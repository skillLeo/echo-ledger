//! Audio capture orchestration. Platform backends → recorder thread (timeline alignment, WAVs, live PCM) →
//! finished stereo upload file. Logic that can be pure lives in `timeline`, `dsp`, `recorder` and `mock`.

pub mod dsp;
pub mod mock;
pub mod platform;
pub mod recorder;
pub mod timeline;
pub mod types;
pub mod writer;

use std::path::{Path, PathBuf};
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::mpsc::{Sender, channel};
use std::sync::{Arc, Mutex};
use std::thread::JoinHandle;

use chrono::{DateTime, Utc};
use serde::Serialize;
use tokio::sync::mpsc::UnboundedSender;

use crate::error::{AppError, AppResult};
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
}

type LiveSinks = [Option<UnboundedSender<Vec<u8>>>; 2];

struct Active {
    info: RecordingInfo,
    backend: Box<dyn CaptureBackend>,
    thread: JoinHandle<Result<RecordingOutput, String>>,
    live: Arc<Mutex<LiveSinks>>,
    live_version: Arc<AtomicU64>,
    levels: Arc<Mutex<[f32; 2]>>,
}

#[derive(Default)]
pub struct CaptureController {
    active: Mutex<Option<Active>>,
}

impl CaptureController {
    pub fn is_recording(&self) -> bool {
        self.active.lock().map(|a| a.is_some()).unwrap_or(false)
    }

    pub fn info(&self) -> Option<RecordingInfo> {
        self.active.lock().ok()?.as_ref().map(|a| a.info.clone())
    }

    pub fn levels(&self) -> [f32; 2] {
        self.active.lock().ok().and_then(|a| a.as_ref().map(|a| *a.levels.lock().unwrap())).unwrap_or([0.0, 0.0])
    }

    pub fn start(&self, root: &Path, mode: RecordingMode, source_app: &str, client_id: Option<String>) -> AppResult<RecordingInfo> {
        let mut guard = self.active.lock().map_err(|_| AppError::msg("capture state poisoned"))?;
        if let Some(active) = guard.as_ref() {
            return Ok(active.info.clone());
        }
        let client_ref = uuid::Uuid::new_v4().to_string();
        let dir = root.join(&client_ref);
        let (tx, rx) = channel::<AudioChunk>();
        let streams = match mode {
            RecordingMode::Call => Streams { mic: true, system: true },
            RecordingMode::VoiceNote => Streams { mic: false, system: true },
            RecordingMode::MicNote => Streams { mic: true, system: false },
        };
        let backend = platform::start(streams, tx).map_err(|e| AppError::Audio(e.to_string()))?;
        let info = RecordingInfo {
            client_ref,
            mode,
            source_app: source_app.to_string(),
            client_id,
            started_at: Utc::now(),
            backend: backend.kind(),
        };

        let live: Arc<Mutex<LiveSinks>> = Arc::new(Mutex::new([None, None]));
        let live_version = Arc::new(AtomicU64::new(0));
        let levels = Arc::new(Mutex::new([0.0f32, 0.0]));
        let mono = match mode {
            RecordingMode::Call => None,
            RecordingMode::VoiceNote => Some(Channel::System),
            RecordingMode::MicNote => Some(Channel::Mic),
        };
        let thread = spawn_recorder(dir, mono, rx, live.clone(), live_version.clone(), levels.clone());

        *guard = Some(Active { info: info.clone(), backend, thread, live, live_version, levels });
        Ok(info)
    }

    /// Routes live PCM (16 kHz s16le mono) of `channel` to a caption stream, or detaches it.
    pub fn set_live_sink(&self, channel: Channel, sink: Option<UnboundedSender<Vec<u8>>>) {
        if let Ok(guard) = self.active.lock()
            && let Some(active) = guard.as_ref()
        {
            active.live.lock().unwrap()[channel.index()] = sink;
            active.live_version.fetch_add(1, Ordering::SeqCst);
        }
    }

    pub fn stop(&self) -> AppResult<Option<FinishedRecording>> {
        let active = self.active.lock().map_err(|_| AppError::msg("capture state poisoned"))?.take();
        let Some(mut active) = active else { return Ok(None) };
        active.backend.stop(); // drops the senders → recorder thread drains and finishes
        let output = active.thread.join().map_err(|_| AppError::Audio("recorder thread panicked".into()))?.map_err(AppError::Audio)?;
        Ok(Some(FinishedRecording { info: active.info, ended_at: Utc::now(), output }))
    }
}

fn spawn_recorder(
    dir: PathBuf,
    mono: Option<Channel>,
    rx: std::sync::mpsc::Receiver<AudioChunk>,
    live: Arc<Mutex<LiveSinks>>,
    live_version: Arc<AtomicU64>,
    levels: Arc<Mutex<[f32; 2]>>,
) -> JoinHandle<Result<RecordingOutput, String>> {
    std::thread::Builder::new()
        .name("el-recorder".into())
        .spawn(move || {
            let mut recorder = match mono {
                Some(channel) => Recorder::create_mono(&dir, channel)?,
                None => Recorder::create(&dir, false)?,
            };
            let mut seen_version = u64::MAX;
            while let Ok(chunk) = rx.recv() {
                let version = live_version.load(Ordering::SeqCst);
                if version != seen_version {
                    let sinks = live.lock().unwrap().clone();
                    recorder.set_live_sink(Channel::Mic, sinks[0].clone());
                    recorder.set_live_sink(Channel::System, sinks[1].clone());
                    seen_version = version;
                }
                recorder.push(chunk)?;
                *levels.lock().unwrap() = recorder.levels();
            }
            recorder.finish()
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
