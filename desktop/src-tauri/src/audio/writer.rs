//! Local artifacts: `mic.wav` + `system.wav` (mono 16 kHz PCM16) and the stereo upload file (FLAC, lossless).
//! FLAC is the accuracy-first default (providers convert to 16 kHz internally; lossy compression can hurt WER).

use std::fs::File;
use std::io::BufWriter;
use std::path::Path;

use flacenc::component::BitRepr;
use flacenc::error::Verify;

use super::dsp::f32_to_i16;
use super::types::TARGET_RATE;

pub struct MonoWav {
    inner: Option<hound::WavWriter<BufWriter<File>>>,
    frames: u64,
}

impl MonoWav {
    pub fn create(path: &Path) -> Result<Self, String> {
        let spec = hound::WavSpec { channels: 1, sample_rate: TARGET_RATE, bits_per_sample: 16, sample_format: hound::SampleFormat::Int };
        let writer = hound::WavWriter::create(path, spec).map_err(|e| e.to_string())?;
        Ok(Self { inner: Some(writer), frames: 0 })
    }

    pub fn write(&mut self, samples: &[f32]) -> Result<(), String> {
        if let Some(w) = self.inner.as_mut() {
            for &s in samples {
                w.write_sample(f32_to_i16(s)).map_err(|e| e.to_string())?;
            }
            self.frames += samples.len() as u64;
            // Keep the file valid up to here every ~2 s so a crash still leaves playable audio.
            if self.frames % (TARGET_RATE as u64 * 2) < samples.len() as u64 {
                w.flush().map_err(|e| e.to_string())?;
            }
        }
        Ok(())
    }

    pub fn write_silence(&mut self, frames: usize) -> Result<(), String> {
        const BLOCK: usize = 4096;
        let zeros = [0.0f32; BLOCK];
        let mut left = frames;
        while left > 0 {
            let n = left.min(BLOCK);
            self.write(&zeros[..n])?;
            left -= n;
        }
        Ok(())
    }

    pub fn frames(&self) -> u64 {
        self.frames
    }

    pub fn finalize(&mut self) -> Result<(), String> {
        if let Some(w) = self.inner.take() {
            w.finalize().map_err(|e| e.to_string())?;
        }
        Ok(())
    }
}

/// Reads a mono 16-bit WAV written by [`MonoWav`].
pub fn read_mono_i16(path: &Path) -> Result<Vec<i16>, String> {
    let mut reader = hound::WavReader::open(path).map_err(|e| e.to_string())?;
    reader.samples::<i16>().map(|s| s.map_err(|e| e.to_string())).collect()
}

/// Encodes two mono channels into one stereo FLAC (L = mic/"Me", R = system/"Client").
pub fn encode_stereo_flac(left: &[i16], right: &[i16], out: &Path) -> Result<(), String> {
    let len = left.len().max(right.len());
    let mut interleaved: Vec<i32> = Vec::with_capacity(len * 2);
    for i in 0..len {
        interleaved.push(left.get(i).copied().unwrap_or(0) as i32);
        interleaved.push(right.get(i).copied().unwrap_or(0) as i32);
    }
    let config = flacenc::config::Encoder::default().into_verified().map_err(|(_, e)| format!("{e:?}"))?;
    let source = flacenc::source::MemSource::from_samples(&interleaved, 2, 16, TARGET_RATE as usize);
    let stream = flacenc::encode_with_fixed_block_size(&config, source, config.block_size).map_err(|e| format!("{e:?}"))?;
    let mut sink = flacenc::bitsink::ByteSink::new();
    stream.write(&mut sink).map_err(|e| format!("{e:?}"))?;
    std::fs::write(out, sink.as_slice()).map_err(|e| e.to_string())
}

/// Encodes a mono FLAC (voice notes captured while playing).
pub fn encode_mono_flac(samples: &[i16], out: &Path) -> Result<(), String> {
    let data: Vec<i32> = samples.iter().map(|&s| s as i32).collect();
    let config = flacenc::config::Encoder::default().into_verified().map_err(|(_, e)| format!("{e:?}"))?;
    let source = flacenc::source::MemSource::from_samples(&data, 1, 16, TARGET_RATE as usize);
    let stream = flacenc::encode_with_fixed_block_size(&config, source, config.block_size).map_err(|e| format!("{e:?}"))?;
    let mut sink = flacenc::bitsink::ByteSink::new();
    stream.write(&mut sink).map_err(|e| format!("{e:?}"))?;
    std::fs::write(out, sink.as_slice()).map_err(|e| e.to_string())
}
