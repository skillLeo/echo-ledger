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
    encode_flac_streaming(vec![slice_samples(left), slice_samples(right)], out)
}

/// Encodes a mono FLAC (voice notes captured while playing).
pub fn encode_mono_flac(samples: &[i16], out: &Path) -> Result<(), String> {
    encode_flac_streaming(vec![slice_samples(samples)], out)
}

/// Stereo FLAC straight from the two channel WAVs, block by block: memory stays flat however long the
/// recording is (A6.DR10; loading both files used to need about 2.3 GB per hour of stereo audio).
pub fn encode_stereo_flac_from_wavs(left: &Path, right: &Path, out: &Path) -> Result<(), String> {
    encode_flac_streaming(vec![wav_samples(left)?, wav_samples(right)?], out)
}

/// Mono FLAC straight from one channel WAV, block by block.
pub fn encode_mono_flac_from_wav(wav: &Path, out: &Path) -> Result<(), String> {
    encode_flac_streaming(vec![wav_samples(wav)?], out)
}

type Samples<'a> = Box<dyn Iterator<Item = Result<i16, String>> + 'a>;

fn slice_samples(samples: &[i16]) -> Samples<'_> {
    Box::new(samples.iter().map(|&s| Ok(s)))
}

fn wav_samples(path: &Path) -> Result<Samples<'static>, String> {
    let reader = hound::WavReader::open(path).map_err(|e| e.to_string())?;
    Ok(Box::new(reader.into_samples::<i16>().map(|s| s.map_err(|e| e.to_string()))))
}

/// `fLaC`, then a STREAMINFO block header flagged "last metadata block" (type 0, 34 bytes long).
const FLAC_HEAD: [u8; 8] = [0x66, 0x4c, 0x61, 0x43, 0x80, 0x00, 0x00, 0x22];
const STREAMINFO_LEN: usize = 34;

/// Streaming FLAC encoder: reads one block of every channel (a channel that ends early is padded with silence),
/// encodes it with `flacenc::encode_fixed_size_frame` (flacenc-0.5.1 `src/coding.rs:584`), writes the frame and
/// moves on. The STREAMINFO block is written as a placeholder first and patched at the end with the block and
/// frame sizes, the total sample count and the MD5 of the input (flacenc `source::Context`, `source.rs:306`).
fn encode_flac_streaming(mut channels: Vec<Samples<'_>>, out: &Path) -> Result<(), String> {
    use flacenc::bitsink::ByteSink;
    use flacenc::component::StreamInfo;
    use flacenc::source::{Context, Fill, FrameBuf};
    use std::io::{Seek, SeekFrom, Write};

    let count = channels.len();
    let config = flacenc::config::Encoder::default().into_verified().map_err(|(_, e)| format!("{e:?}"))?;
    let block = config.block_size;
    let mut info = StreamInfo::new(TARGET_RATE as usize, count, 16).map_err(|e| format!("{e:?}"))?;
    let mut framebuf = FrameBuf::with_size(count, block).map_err(|e| format!("{e:?}"))?;
    let mut context = Context::new(16, count);

    let mut file = BufWriter::new(File::create(out).map_err(|e| e.to_string())?);
    file.write_all(&FLAC_HEAD).map_err(|e| e.to_string())?;
    file.write_all(&[0u8; STREAMINFO_LEN]).map_err(|e| e.to_string())?;

    let mut interleaved: Vec<i32> = Vec::with_capacity(block * count);
    let mut ended = vec![false; count];
    let mut sink = ByteSink::new();
    let (mut frame_number, mut total) = (0usize, 0u64);
    let (mut min_frame, mut max_frame) = (usize::MAX, 0usize);
    loop {
        interleaved.clear();
        let mut frames = 0;
        while frames < block {
            let start = interleaved.len();
            let mut any = false;
            for (n, channel) in channels.iter_mut().enumerate() {
                let sample = if ended[n] {
                    0
                } else {
                    match channel.next() {
                        Some(sample) => {
                            any = true;
                            i32::from(sample?)
                        }
                        None => {
                            ended[n] = true;
                            0
                        }
                    }
                };
                interleaved.push(sample);
            }
            if !any {
                interleaved.truncate(start);
                break;
            }
            frames += 1;
        }
        if frames == 0 {
            break;
        }
        framebuf.fill_interleaved(&interleaved).map_err(|e| e.to_string())?;
        context.fill_interleaved(&interleaved).map_err(|e| e.to_string())?;
        let frame = flacenc::encode_fixed_size_frame(&config, &framebuf, frame_number, &info).map_err(|e| e.to_string())?;
        sink.clear();
        frame.write(&mut sink).map_err(|e| format!("{e:?}"))?;
        file.write_all(sink.as_slice()).map_err(|e| e.to_string())?;
        min_frame = min_frame.min(sink.as_slice().len());
        max_frame = max_frame.max(sink.as_slice().len());
        frame_number += 1;
        total += frames as u64;
    }

    // A fixed-blocksize stream records the configured block size as both minimum and maximum, like the reference
    // encoder: the format's minimum excludes the last block, which may hold as few as one sample (A6.DR9).
    info.set_block_sizes(block, block).map_err(|e| format!("{e:?}"))?;
    let (min_frame, max_frame) = if frame_number == 0 { (0, 0) } else { (min_frame, max_frame) }; // 0 = unknown
    info.set_frame_sizes(min_frame, max_frame).map_err(|e| format!("{e:?}"))?;
    info.set_total_samples(total as usize);
    info.set_md5_digest(&context.md5_digest());
    sink.clear();
    info.write(&mut sink).map_err(|e| format!("{e:?}"))?;

    let mut file = file.into_inner().map_err(|e| e.to_string())?;
    file.seek(SeekFrom::Start(FLAC_HEAD.len() as u64)).map_err(|e| e.to_string())?;
    file.write_all(sink.as_slice()).map_err(|e| e.to_string())?;
    file.flush().map_err(|e| e.to_string())
}
