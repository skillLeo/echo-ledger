//! Two-channel recorder: places packets on per-channel timelines anchored at a shared T0, writes
//! `mic.wav` + `system.wav`, forwards live PCM to caption streams, and on finish pads both channels to equal
//! length and encodes the stereo upload file.

use std::path::{Path, PathBuf};

use tokio::sync::mpsc::UnboundedSender;

use super::dsp::{f32_to_i16, rms};
use super::timeline::{Timeline, frames_to_nanos};
use super::types::{AudioChunk, Channel, TARGET_RATE};
use super::writer::{MonoWav, encode_mono_flac_from_wav, encode_stereo_flac_from_wavs};

#[derive(Clone, Debug, serde::Serialize)]
pub struct RecordingOutput {
    pub dir: PathBuf,
    pub mic_wav: PathBuf,
    pub system_wav: PathBuf,
    /// Upload file: stereo FLAC (L = Me, R = Client), or mono FLAC for voice-note capture.
    pub primary: PathBuf,
    pub channels: u16,
    pub duration_ms: u64,
}

pub struct Recorder {
    dir: PathBuf,
    mic: MonoWav,
    sys: MonoWav,
    mic_tl: Timeline,
    sys_tl: Timeline,
    t0_ns: Option<u64>,
    live: [Option<UnboundedSender<Vec<u8>>>; 2],
    levels: [f32; 2],
    /// `Some(channel)` = a single-channel recording whose upload file is that channel only (voice notes).
    mono: Option<Channel>,
}

impl Recorder {
    /// `mono_system_only` = voice-note capture while playing (only the system channel matters).
    pub fn create(dir: &Path, mono_system_only: bool) -> Result<Self, String> {
        Self::build(dir, mono_system_only.then_some(Channel::System))
    }

    /// Single-channel recording: `Channel::Mic` for a voice note spoken into the microphone,
    /// `Channel::System` for audio playing on this computer.
    pub fn create_mono(dir: &Path, channel: Channel) -> Result<Self, String> {
        Self::build(dir, Some(channel))
    }

    fn build(dir: &Path, mono: Option<Channel>) -> Result<Self, String> {
        std::fs::create_dir_all(dir).map_err(|e| e.to_string())?;
        Ok(Self {
            dir: dir.to_path_buf(),
            mic: MonoWav::create(&dir.join("mic.wav"))?,
            sys: MonoWav::create(&dir.join("system.wav"))?,
            mic_tl: Timeline::new(TARGET_RATE),
            sys_tl: Timeline::new(TARGET_RATE),
            t0_ns: None,
            live: [None, None],
            levels: [0.0, 0.0],
            mono,
        })
    }

    pub fn set_live_sink(&mut self, channel: Channel, sink: Option<UnboundedSender<Vec<u8>>>) {
        self.live[channel.index()] = sink;
    }

    pub fn levels(&self) -> [f32; 2] {
        self.levels
    }

    pub fn frames(&self) -> (u64, u64) {
        (self.mic.frames(), self.sys.frames())
    }

    pub fn push(&mut self, chunk: AudioChunk) -> Result<(), String> {
        if chunk.samples.is_empty() {
            return Ok(());
        }
        let (timeline, wav) = match chunk.channel {
            Channel::Mic => (&mut self.mic_tl, &mut self.mic),
            Channel::System => (&mut self.sys_tl, &mut self.sys),
        };
        // T0 comes from the first *timestamped* packet, chosen so that packet lands at its channel's cursor. Packets
        // without a timestamp before it were appended at the cursor; anchoring them at clock zero would place the
        // stamped ones (device time since boot) hours into the file (A6.DR6).
        if self.t0_ns.is_none()
            && let Some(ts) = chunk.ts_ns
        {
            self.t0_ns = Some(ts.saturating_sub(frames_to_nanos(timeline.cursor(), TARGET_RATE)));
        }
        let placement = timeline.place(self.t0_ns.unwrap_or(0), chunk.ts_ns, chunk.samples.len());
        if placement.pad > 0 {
            wav.write_silence(placement.pad)?;
        }
        let body = &chunk.samples[placement.skip..placement.skip + placement.take];
        wav.write(body)?;
        self.levels[chunk.channel.index()] = rms(body);

        if let Some(sink) = &self.live[chunk.channel.index()] {
            let mut bytes = Vec::with_capacity((placement.pad + body.len()) * 2);
            bytes.resize(placement.pad.min(TARGET_RATE as usize) * 2, 0); // keep the stream's clock honest across gaps
            for &s in body {
                bytes.extend_from_slice(&f32_to_i16(s).to_le_bytes());
            }
            if sink.send(bytes).is_err() {
                self.live[chunk.channel.index()] = None; // caption stream ended
            }
        }
        Ok(())
    }

    /// Pads the shorter channel so both end at the same frame (the common cursor).
    fn even_out(&mut self) -> Result<u64, String> {
        let (mic_frames, sys_frames) = self.frames();
        let total = mic_frames.max(sys_frames);
        self.mic.write_silence((total - mic_frames) as usize)?;
        self.sys.write_silence((total - sys_frames) as usize)?;
        self.mic_tl.advance(total - mic_frames);
        self.sys_tl.advance(total - sys_frames);
        Ok(total)
    }

    /// Marks the point where a pause ended (A3.3): both channels are evened out and the next timestamped packet
    /// re-anchors the timeline so that it lands at the common cursor. The paused span is not written as silence,
    /// so the file holds the recorded time only.
    pub fn mark_resume(&mut self) -> Result<(), String> {
        self.even_out()?;
        self.t0_ns = None;
        self.levels = [0.0, 0.0];
        Ok(())
    }

    /// Pads the shorter channel, finalizes both WAVs and encodes the upload file.
    pub fn finish(mut self) -> Result<RecordingOutput, String> {
        let total = self.even_out()?;
        self.mic.finalize()?;
        self.sys.finalize()?;

        let mic_wav = self.dir.join("mic.wav");
        let system_wav = self.dir.join("system.wav");
        // Encoded straight from the WAVs, one block at a time (A6.DR10): flat memory for any length.
        let (primary, channels) = if let Some(channel) = self.mono {
            let path = self.dir.join("voice-note.flac");
            encode_mono_flac_from_wav(if channel == Channel::Mic { &mic_wav } else { &system_wav }, &path)?;
            (path, 1)
        } else {
            let path = self.dir.join("recording.flac");
            encode_stereo_flac_from_wavs(&mic_wav, &system_wav, &path)?;
            (path, 2)
        };

        Ok(RecordingOutput {
            dir: self.dir.clone(),
            mic_wav,
            system_wav,
            primary,
            channels,
            duration_ms: total * 1000 / TARGET_RATE as u64,
        })
    }
}
