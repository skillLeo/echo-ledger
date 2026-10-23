//! Two-channel recorder: places packets on per-channel timelines anchored at a shared T0, writes
//! `mic.wav` + `system.wav`, forwards live PCM to caption streams, and on finish pads both channels to equal
//! length and encodes the stereo upload file.

use std::path::{Path, PathBuf};

use tokio::sync::mpsc::UnboundedSender;

use super::dsp::{f32_to_i16, rms};
use super::timeline::Timeline;
use super::types::{AudioChunk, Channel, TARGET_RATE};
use super::writer::{MonoWav, encode_mono_flac, encode_stereo_flac, read_mono_i16};

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
    mono_system_only: bool,
}

impl Recorder {
    /// `mono_system_only` = voice-note capture while playing (only the system channel matters).
    pub fn create(dir: &Path, mono_system_only: bool) -> Result<Self, String> {
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
            mono_system_only,
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
        let t0 = *self.t0_ns.get_or_insert(chunk.ts_ns.unwrap_or(0));
        let (timeline, wav) = match chunk.channel {
            Channel::Mic => (&mut self.mic_tl, &mut self.mic),
            Channel::System => (&mut self.sys_tl, &mut self.sys),
        };
        let placement = timeline.place(t0, chunk.ts_ns, chunk.samples.len());
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

    /// Pads the shorter channel, finalizes both WAVs and encodes the upload file.
    pub fn finish(mut self) -> Result<RecordingOutput, String> {
        let (mic_frames, sys_frames) = self.frames();
        let total = mic_frames.max(sys_frames);
        self.mic.write_silence((total - mic_frames) as usize)?;
        self.sys.write_silence((total - sys_frames) as usize)?;
        self.mic_tl.advance(total - mic_frames);
        self.sys_tl.advance(total - sys_frames);
        self.mic.finalize()?;
        self.sys.finalize()?;

        let mic_wav = self.dir.join("mic.wav");
        let system_wav = self.dir.join("system.wav");
        let left = read_mono_i16(&mic_wav)?;
        let right = read_mono_i16(&system_wav)?;
        let (primary, channels) = if self.mono_system_only {
            let path = self.dir.join("voice-note.flac");
            encode_mono_flac(&right, &path)?;
            (path, 1)
        } else {
            let path = self.dir.join("recording.flac");
            encode_stereo_flac(&left, &right, &path)?;
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
