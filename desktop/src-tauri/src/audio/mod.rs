//! Audio capture orchestration. Platform backends → recorder thread (timeline alignment, WAVs, live PCM) →
//! finished stereo upload file. Logic that can be pure lives in `timeline`, `dsp`, `recorder`, `clock` and `mock`.
//!
//! Pause (A3.3) stops the platform backend, so the microphone and the loopback stream are released and the OS
//! indicator turns off; the recorder thread stays alive through a keep-alive sender. Resume starts a new backend on
//! the same channel and the recorder re-anchors its timeline, so the paused span leaves no gap in the file.

pub mod clock;
pub mod dsp;
pub mod health;
pub mod mock;
pub mod notes;
pub mod platform;
pub mod recorder;
pub mod timeline;
pub mod types;
pub mod writer;

use std::path::{Path, PathBuf};
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::mpsc::{Receiver, Sender, channel};
use std::sync::{Arc, Mutex};
use std::thread::JoinHandle;
use std::time::Instant;

use chrono::{DateTime, Utc};
use serde::Serialize;
use tokio::sync::mpsc::UnboundedSender;

use crate::error::{AppError, AppResult};
use crate::net::models::{MarkerDto, PauseDto};
use clock::RecordingClock;
use notes::RecordingNotes;
use platform::{CaptureBackend, Streams};
use recorder::{Recorder, RecordingOutput};
use types::{AudioChunk, BackendKind, Channel};

#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize, serde::Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum RecordingMode {
    /// Call/meeting: mic ("Me") + system ("Client") as two channels.
    Call,
    /// Voice note playing on this computer: system audio only (never auto-started).
    VoiceNote,
    /// Voice note spoken into the microphone: mic only, mono (never auto-started).
    MicNote,
}

#[derive(Clone, Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct RecordingInfo {
    pub client_ref: String,
    pub mode: RecordingMode,
    pub source_app: String,
    pub client_id: Option<String>,
    pub started_at: DateTime<Utc>,
    pub backend: BackendKind,
}

#[derive(Clone, Debug)]
pub struct FinishedRecording {
    pub info: RecordingInfo,
    pub ended_at: DateTime<Utc>,
    pub output: RecordingOutput,
    /// Bookmarks in time order and the pause gaps (recorded time).
    pub markers: Vec<MarkerDto>,
    pub pauses: Vec<PauseDto>,
}

type LiveSinks = [Option<UnboundedSender<Vec<u8>>>; 2];

/// What the capture controller shares with its recorder thread.
#[derive(Clone, Default)]
pub struct RecorderLink {
    /// Caption sinks per channel (index = `Channel::index`), re-read whenever `live_version` changes.
    pub live: Arc<Mutex<LiveSinks>>,
    pub live_version: Arc<AtomicU64>,
    /// RMS level of the last packet per channel, for the meters.
    pub levels: Arc<Mutex<[f32; 2]>>,
    /// Bumped by every resume: packets that arrive after a pause marker are dropped until it changes.
    pub resume_epoch: Arc<AtomicU64>,
    /// When system audio (the other side) was last above [`health::HEARD_LEVEL`].
    pub system_heard: Arc<Mutex<Option<Instant>>>,
}

/// Sent through the keep-alive sender once a paused backend has stopped: every packet captured before the pause
/// is ahead of it in the channel. No backend produces an empty packet stamped `u64::MAX`.
pub fn pause_marker() -> AudioChunk {
    AudioChunk { channel: Channel::Mic, samples: Vec::new(), ts_ns: Some(u64::MAX), discontinuity: true }
}

pub fn is_pause_marker(chunk: &AudioChunk) -> bool {
    chunk.samples.is_empty() && chunk.ts_ns == Some(u64::MAX) && chunk.discontinuity
}

/// The recorder thread's loop: writes packets until every sender is gone, then finishes the files. At a pause
/// marker the recorder marks the resume point (no silence for the paused span) and drops whatever arrives before
/// the next resume; chunks dropped this way are neither written nor sent to the caption sinks.
pub fn recorder_loop(mut recorder: Recorder, rx: Receiver<AudioChunk>, link: &RecorderLink) -> Result<RecordingOutput, String> {
    let mut seen_version = u64::MAX;
    let mut paused_epoch: Option<u64> = None;
    while let Ok(chunk) = rx.recv() {
        if is_pause_marker(&chunk) {
            recorder.mark_resume()?;
            paused_epoch = Some(link.resume_epoch.load(Ordering::SeqCst));
            *link.levels.lock().unwrap_or_else(|p| p.into_inner()) = [0.0, 0.0];
            continue;
        }
        if let Some(epoch) = paused_epoch {
            if link.resume_epoch.load(Ordering::SeqCst) == epoch {
                continue; // still paused: not captured, not streamed
            }
            paused_epoch = None;
        }
        let version = link.live_version.load(Ordering::SeqCst);
        if version != seen_version {
            let sinks = link.live.lock().unwrap_or_else(|p| p.into_inner()).clone();
            recorder.set_live_sink(Channel::Mic, sinks[0].clone());
            recorder.set_live_sink(Channel::System, sinks[1].clone());
            seen_version = version;
        }
        let system = chunk.channel == Channel::System;
        recorder.push(chunk)?;
        let levels = recorder.levels();
        *link.levels.lock().unwrap_or_else(|p| p.into_inner()) = levels;
        if system && levels[Channel::System.index()] >= health::HEARD_LEVEL {
            *link.system_heard.lock().unwrap_or_else(|p| p.into_inner()) = Some(Instant::now());
        }
    }
    recorder.finish()
}

struct Active {
    info: RecordingInfo,
    streams: Streams,
    /// `None` while paused (the devices are released).
    backend: Option<Box<dyn CaptureBackend>>,
    /// Keeps the recorder thread alive while no backend holds a sender (pause); dropped at stop.
    keepalive: Sender<AudioChunk>,
    thread: JoinHandle<Result<RecordingOutput, String>>,
    link: RecorderLink,
    clock: RecordingClock,
    notes: RecordingNotes,
    /// Start of the current running stretch (start or last resume), for the far-side silence check.
    running_since: Instant,
}

#[derive(Default)]
pub struct CaptureController {
    active: Mutex<Option<Active>>,
}

fn streams_for(mode: RecordingMode) -> Streams {
    match mode {
        RecordingMode::Call => Streams { mic: true, system: true },
        RecordingMode::VoiceNote => Streams { mic: false, system: true },
        RecordingMode::MicNote => Streams { mic: true, system: false },
    }
}

impl CaptureController {
    fn lock(&self) -> std::sync::MutexGuard<'_, Option<Active>> {
        self.active.lock().unwrap_or_else(|p| p.into_inner())
    }

    pub fn is_recording(&self) -> bool {
        self.lock().is_some()
    }

    /// True while a recording exists and is paused.
    pub fn is_paused(&self) -> bool {
        self.lock().as_ref().is_some_and(|a| a.backend.is_none())
    }

    /// Recorded time of the current recording (paused time excluded); 0 when idle.
    pub fn elapsed_ms(&self) -> u64 {
        self.lock().as_ref().map(|a| a.clock.elapsed_ms(Instant::now())).unwrap_or(0)
    }

    pub fn info(&self) -> Option<RecordingInfo> {
        self.lock().as_ref().map(|a| a.info.clone())
    }

    pub fn levels(&self) -> [f32; 2] {
        self.lock()
            .as_ref()
            .filter(|a| a.backend.is_some())
            .map(|a| *a.link.levels.lock().unwrap_or_else(|p| p.into_inner()))
            .unwrap_or([0.0, 0.0])
    }

    /// How long a running call recording has not heard the other side (since the last loud system packet, the
    /// start or the last resume); `None` when no call records or it is paused.
    pub fn far_side_silent_ms(&self) -> Option<u64> {
        let guard = self.lock();
        let active = guard.as_ref().filter(|a| a.info.mode == RecordingMode::Call && a.backend.is_some())?;
        let heard = *active.link.system_heard.lock().unwrap_or_else(|p| p.into_inner());
        let since = heard.map_or(active.running_since, |h| h.max(active.running_since));
        Some(Instant::now().saturating_duration_since(since).as_millis() as u64)
    }

    /// Forgets the client of the current recording when it is `client_id` (deleted on the server, A6.DR7).
    pub fn clear_client(&self, client_id: &str) {
        if let Some(active) = self.lock().as_mut()
            && active.info.client_id.as_deref() == Some(client_id)
        {
            active.info.client_id = None;
        }
    }

    pub fn start(&self, root: &Path, mode: RecordingMode, source_app: &str, client_id: Option<String>) -> AppResult<RecordingInfo> {
        let mut guard = self.lock();
        if let Some(active) = guard.as_ref() {
            return Ok(active.info.clone());
        }
        let client_ref = uuid::Uuid::new_v4().to_string();
        let dir = root.join(&client_ref);
        let (tx, rx) = channel::<AudioChunk>();
        let streams = streams_for(mode);
        let backend = platform::start(streams, tx.clone()).map_err(|e| AppError::Audio(e.to_string()))?;
        let info = RecordingInfo {
            client_ref,
            mode,
            source_app: source_app.to_string(),
            client_id,
            started_at: Utc::now(),
            backend: backend.kind(),
        };

        let link = RecorderLink::default();
        let mono = match mode {
            RecordingMode::Call => None,
            RecordingMode::VoiceNote => Some(Channel::System),
            RecordingMode::MicNote => Some(Channel::Mic),
        };
        let thread = spawn_recorder(dir, mono, rx, link.clone());

        *guard = Some(Active { info: info.clone(), streams, backend: Some(backend), keepalive: tx, thread, link, clock: RecordingClock::new(Instant::now()), notes: RecordingNotes::default(), running_since: Instant::now() });
        Ok(info)
    }

    /// Pauses the recording: the backend stops (devices released, OS microphone indicator off) and the recorder is
    /// told where the pause begins. Pausing a paused recording changes nothing.
    pub fn pause(&self) -> AppResult<()> {
        let mut guard = self.lock();
        let active = guard.as_mut().ok_or_else(|| AppError::msg("Nothing is recording."))?;
        let Some(mut backend) = active.backend.take() else { return Ok(()) };
        backend.stop(); // joins the capture threads: every captured packet is in the channel now
        drop(backend);
        let _ = active.keepalive.send(pause_marker());
        let now = Instant::now();
        active.notes.paused(active.clock.elapsed_ms(now));
        active.clock.pause(now);
        *active.link.levels.lock().unwrap_or_else(|p| p.into_inner()) = [0.0, 0.0];
        Ok(())
    }

    /// Resumes a paused recording on fresh devices. The epoch moves first, so the new backend's first packet is
    /// already accepted; on a device error the recording stays paused.
    pub fn resume(&self) -> AppResult<()> {
        let mut guard = self.lock();
        let active = guard.as_mut().ok_or_else(|| AppError::msg("Nothing is paused."))?;
        if active.backend.is_some() {
            return Err(AppError::msg("Nothing is paused."));
        }
        active.link.resume_epoch.fetch_add(1, Ordering::SeqCst);
        match platform::start(active.streams, active.keepalive.clone()) {
            Ok(backend) => {
                active.backend = Some(backend);
                let now = Instant::now();
                let paused_ms = active.clock.paused_at.map_or(0, |at| now.saturating_duration_since(at).as_millis() as u64);
                active.notes.resumed(paused_ms);
                active.clock.resume(now);
                active.running_since = now;
                Ok(())
            }
            Err(e) => {
                // Back to "paused" for the recorder too: nothing may be written until a resume succeeds.
                let _ = active.keepalive.send(pause_marker());
                Err(AppError::Audio(e.to_string()))
            }
        }
    }

    /// Bookmarks the current recording at the recorded time now (B3). Paused recordings take the pause point.
    pub fn mark(&self, tag: Option<&str>, note: Option<&str>) -> AppResult<MarkerDto> {
        let mut guard = self.lock();
        let active = guard.as_mut().ok_or_else(|| AppError::msg("Nothing is recording."))?;
        let at_ms = active.clock.elapsed_ms(Instant::now());
        active.notes.mark(at_ms, tag, note).ok_or_else(|| AppError::msg("This recording already has 500 bookmarks."))
    }

    /// Sets the tag or note of the latest bookmark; false when the recording has none.
    pub fn tag_last_mark(&self, tag: Option<&str>, note: Option<&str>) -> bool {
        self.lock().as_mut().is_some_and(|a| a.notes.tag_last(tag, note))
    }

    /// Removes the latest bookmark of the current recording.
    pub fn undo_mark(&self) -> Option<MarkerDto> {
        self.lock().as_mut().and_then(|a| a.notes.undo_mark())
    }

    /// Bookmarks of the current recording so far, in the order they were made.
    pub fn markers(&self) -> Vec<MarkerDto> {
        self.lock().as_ref().map(|a| a.notes.markers.clone()).unwrap_or_default()
    }

    /// Routes live PCM (16 kHz s16le mono) of `channel` to a caption stream, or detaches it.
    pub fn set_live_sink(&self, channel: Channel, sink: Option<UnboundedSender<Vec<u8>>>) {
        if let Some(active) = self.lock().as_ref() {
            active.link.live.lock().unwrap_or_else(|p| p.into_inner())[channel.index()] = sink;
            active.link.live_version.fetch_add(1, Ordering::SeqCst);
        }
    }

    pub fn stop(&self) -> AppResult<Option<FinishedRecording>> {
        let active = self.lock().take();
        let Some(active) = active else { return Ok(None) };
        let Active { info, backend, keepalive, thread, notes, .. } = active;
        if let Some(mut backend) = backend {
            backend.stop(); // drops the backend's senders
        }
        drop(keepalive); // the last sender: the recorder thread drains and finishes
        let output = thread.join().map_err(|_| AppError::Audio("recorder thread panicked".into()))?.map_err(AppError::Audio)?;
        let (markers, pauses) = notes.finish();
        Ok(Some(FinishedRecording { info, ended_at: Utc::now(), output, markers, pauses }))
    }
}

fn spawn_recorder(dir: PathBuf, mono: Option<Channel>, rx: Receiver<AudioChunk>, link: RecorderLink) -> JoinHandle<Result<RecordingOutput, String>> {
    std::thread::Builder::new()
        .name("el-recorder".into())
        .spawn(move || {
            let recorder = match mono {
                Some(channel) => Recorder::create_mono(&dir, channel)?,
                None => Recorder::create(&dir, false)?,
            };
            recorder_loop(recorder, rx, &link)
        })
        .expect("spawn recorder thread")
}

/// Mic-only capture for dictation: forwards live PCM to the dictation stream, writes nothing to disk.
pub struct MicStream {
    backend: Box<dyn CaptureBackend>,
    thread: Option<JoinHandle<()>>,
}

impl MicStream {
    pub fn start(sink: UnboundedSender<Vec<u8>>) -> AppResult<Self> {
        let (tx, rx): (Sender<AudioChunk>, _) = channel();
        let backend = platform::start(Streams { mic: true, system: false }, tx).map_err(|e| AppError::Audio(e.to_string()))?;
        let thread = std::thread::Builder::new()
            .name("el-dictation-mic".into())
            .spawn(move || {
                while let Ok(chunk) = rx.recv() {
                    let mut bytes = Vec::with_capacity(chunk.samples.len() * 2);
                    for s in chunk.samples {
                        bytes.extend_from_slice(&dsp::f32_to_i16(s).to_le_bytes());
                    }
                    if sink.send(bytes).is_err() {
                        break;
                    }
                }
            })
            .map_err(AppError::Io)?;
        Ok(Self { backend, thread: Some(thread) })
    }

    pub fn stop(mut self) {
        self.backend.stop();
        if let Some(t) = self.thread.take() {
            let _ = t.join();
        }
    }
}
