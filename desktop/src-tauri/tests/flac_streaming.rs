//! The upload file of a long recording is encoded straight from the two channel WAVs, block by block, so stopping a
//! recording needs the same small amount of memory whatever its length (A6.DR10). Measured with a counting
//! allocator, which is why this file holds a single test: nothing else may allocate while it measures.

use std::alloc::{GlobalAlloc, Layout, System};
use std::path::Path;
use std::sync::atomic::{AtomicUsize, Ordering};

use desktop_lib::audio::writer::encode_stereo_flac_from_wavs;

struct Counting;

static CURRENT: AtomicUsize = AtomicUsize::new(0);
static PEAK: AtomicUsize = AtomicUsize::new(0);

fn grew(by: usize) {
    let now = CURRENT.fetch_add(by, Ordering::Relaxed) + by;
    PEAK.fetch_max(now, Ordering::Relaxed);
}

unsafe impl GlobalAlloc for Counting {
    unsafe fn alloc(&self, layout: Layout) -> *mut u8 {
        let ptr = unsafe { System.alloc(layout) };
        if !ptr.is_null() {
            grew(layout.size());
        }
        ptr
    }

    unsafe fn dealloc(&self, ptr: *mut u8, layout: Layout) {
        unsafe { System.dealloc(ptr, layout) };
        CURRENT.fetch_sub(layout.size(), Ordering::Relaxed);
    }

    unsafe fn realloc(&self, ptr: *mut u8, layout: Layout, new_size: usize) -> *mut u8 {
        let moved = unsafe { System.realloc(ptr, layout, new_size) };
        if !moved.is_null() {
            if new_size >= layout.size() {
                grew(new_size - layout.size());
            } else {
                CURRENT.fetch_sub(layout.size() - new_size, Ordering::Relaxed);
            }
        }
        moved
    }
}

#[global_allocator]
static ALLOCATOR: Counting = Counting;

const RATE: u32 = 16_000;

/// Speech-like test signal: a slowly gliding tone plus a little deterministic noise, different per channel.
fn sample(channel: u32, n: u64) -> i16 {
    let t = n as f64 / RATE as f64;
    let freq = 180.0 + 60.0 * (t * 0.37 + channel as f64).sin();
    let noise = ((n.wrapping_mul(2_654_435_761).wrapping_add(channel as u64 * 97)) % 401) as f64 - 200.0;
    (6_000.0 * (2.0 * std::f64::consts::PI * freq * t).sin() + noise) as i16
}

fn write_wav(path: &Path, channel: u32, frames: u64) {
    let spec = hound::WavSpec { channels: 1, sample_rate: RATE, bits_per_sample: 16, sample_format: hound::SampleFormat::Int };
    let mut writer = hound::WavWriter::create(path, spec).unwrap();
    for n in 0..frames {
        writer.write_sample(sample(channel, n)).unwrap();
    }
    writer.finalize().unwrap();
}

/// Extra bytes allocated at the peak while encoding.
fn peak_while_encoding(dir: &Path, frames: u64, name: &str) -> usize {
    let (mic, system, out) = (dir.join(format!("{name}-mic.wav")), dir.join(format!("{name}-system.wav")), dir.join(format!("{name}.flac")));
    write_wav(&mic, 0, frames);
    write_wav(&system, 1, frames - 1_234); // the system channel ended a little earlier
    let baseline = CURRENT.load(Ordering::Relaxed);
    PEAK.store(baseline, Ordering::Relaxed);
    encode_stereo_flac_from_wavs(&mic, &system, &out).unwrap();
    PEAK.load(Ordering::Relaxed).saturating_sub(baseline)
}

fn assert_decodes(path: &Path, frames: u64) {
    let mut reader = claxon::FlacReader::open(path).expect("decodable FLAC");
    let info = reader.streaminfo();
    assert_eq!((info.channels, info.sample_rate, info.bits_per_sample), (2, RATE, 16));
    assert_eq!(info.samples, Some(frames));
    assert!(info.min_block_size >= 16 && info.min_block_size <= info.max_block_size);
    assert_ne!(info.md5sum, [0u8; 16], "the MD5 of the audio is recorded");
    let mut n = 0u64;
    let mut samples = reader.samples();
    while let Some(left) = samples.next() {
        let right = samples.next().expect("stereo pairs").unwrap();
        assert_eq!(left.unwrap(), i32::from(sample(0, n)), "left sample {n}");
        let expected_right = if n < frames - 1_234 { i32::from(sample(1, n)) } else { 0 };
        assert_eq!(right, expected_right, "right sample {n}");
        n += 1;
    }
    assert_eq!(n, frames);
}

#[test]
fn a_long_recording_is_encoded_from_its_wavs_with_flat_memory() {
    let dir = tempfile::tempdir().unwrap();
    let short_frames = 10 * RATE as u64; // 10 s
    let long_frames = 90 * RATE as u64 + 4_097; // 90 s and a final block of one sample
    let short_peak = peak_while_encoding(dir.path(), short_frames, "short");
    let long_peak = peak_while_encoding(dir.path(), long_frames, "long");
    eprintln!("peak extra memory: {short_peak} bytes for 10 s, {long_peak} bytes for 90 s");

    // Loading both channels the old way needed at least 12 bytes per stereo frame (i16 reads, an interleaved i32
    // copy, the encoder's copy, the encoded stream): ~17 MB for these 90 s, ~2.3 GB for an hour.
    assert!(long_peak < 2 * 1024 * 1024, "peak {long_peak} bytes while encoding 90 s");
    assert!(
        long_peak <= short_peak + 256 * 1024,
        "memory grows with the length: {short_peak} bytes for 10 s, {long_peak} bytes for 90 s"
    );

    assert_decodes(&dir.path().join("short.flac"), short_frames);
    assert_decodes(&dir.path().join("long.flac"), long_frames);
}
