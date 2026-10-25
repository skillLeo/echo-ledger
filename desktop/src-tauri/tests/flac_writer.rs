//! The FLAC and WAV writers, verified by decoding what they produce with an independent decoder (claxon) and by
//! running mocked captures through the recorder.

mod common;

use std::path::Path;

use desktop_lib::audio::mock::MockSource;
use desktop_lib::audio::recorder::Recorder;
use desktop_lib::audio::types::{AudioChunk, Channel, TARGET_RATE};
use desktop_lib::audio::writer::{MonoWav, encode_mono_flac, encode_stereo_flac, read_mono_i16};

struct Decoded {
    sample_rate: u32,
    channels: u32,
    bits: u32,
    frames: Option<u64>,
    /// Interleaved, as the decoder yields them.
    samples: Vec<i32>,
}

fn decode(path: &Path) -> Decoded {
    let mut reader = claxon::FlacReader::open(path)
        .unwrap_or_else(|e| panic!("{} is not decodable FLAC: {e}", path.display()));
    let info = reader.streaminfo();
    let samples = reader
        .samples()
        .map(|s| s.expect("decodable sample"))
        .collect();
    Decoded {
        sample_rate: info.sample_rate,
        channels: info.channels,
        bits: info.bits_per_sample,
        frames: info.samples,
        samples,
    }
}

fn channel_of(decoded: &Decoded, index: usize) -> Vec<i16> {
    decoded
        .samples
        .iter()
        .skip(index)
        .step_by(decoded.channels as usize)
        .map(|&s| i16::try_from(s).expect("16-bit sample"))
        .collect()
}

fn noise(len: usize, seed: u64) -> Vec<i16> {
    common::pseudo_random_bytes(len * 2, seed)
        .as_chunks::<2>()
        .0
        .iter()
        .map(|b| i16::from_le_bytes(*b))
        .collect()
}

fn tone(len: usize, freq: f32, amplitude: f32) -> Vec<i16> {
    (0..len)
        .map(|n| {
            (amplitude
                * 32_767.0
                * (2.0 * std::f32::consts::PI * freq * n as f32 / TARGET_RATE as f32).sin())
                as i16
        })
        .collect()
}

fn rising_crossings(samples: &[i16]) -> usize {
    samples.windows(2).filter(|w| w[0] < 0 && w[1] >= 0).count()
}

// ---- Stereo FLAC -----------------------------------------------------------------------------------------------

#[test]
fn stereo_flac_has_the_right_format_and_decodes_to_the_exact_input() {
    let dir = tempfile::tempdir().unwrap();
    let path = dir.path().join("stereo.flac");
    let (left, right) = (tone(48_000, 330.0, 0.5), noise(48_000, 7));
    encode_stereo_flac(&left, &right, &path).unwrap();

    let decoded = decode(&path);
    assert_eq!(decoded.sample_rate, 16_000);
    assert_eq!(decoded.channels, 2);
    assert_eq!(decoded.bits, 16);
    assert_eq!(decoded.frames, Some(48_000), "three seconds of audio");
    assert_eq!(
        channel_of(&decoded, 0),
        left,
        "left channel (Me) is lossless"
    );
    assert_eq!(
        channel_of(&decoded, 1),
        right,
        "right channel (Client) is lossless"
    );
}

#[test]
fn frame_counts_around_the_encoder_block_size_all_roundtrip() {
    let dir = tempfile::tempdir().unwrap();
    for frames in [
        16usize, 17, 100, 4_095, 4_096, 4_112, 8_192, 12_304, 16_000, 16_001, 48_001,
    ] {
        let path = dir.path().join(format!("n{frames}.flac"));
        let (left, right) = (noise(frames, 11), tone(frames, 440.0, 0.9));
        encode_stereo_flac(&left, &right, &path).unwrap_or_else(|e| panic!("{frames} frames: {e}"));
        let decoded = decode(&path);
        assert_eq!(decoded.frames, Some(frames as u64), "{frames} frames");
        assert_eq!(channel_of(&decoded, 0), left, "{frames} frames, left");
        assert_eq!(channel_of(&decoded, 1), right, "{frames} frames, right");
    }
}

#[test]
fn a_final_block_of_only_a_few_samples_still_gives_a_stream_strict_decoders_accept() {
    // 4 096 frames per block: lengths just past a block boundary leave a final block of 1..15 samples, which the
    // encoder records as the stream's minimum block size; the format requires at least 16.
    let dir = tempfile::tempdir().unwrap();
    let mut rejected = Vec::new();
    for frames in [4_097usize, 4_105, 4_111, 8_193, 12_289] {
        let path = dir.path().join(format!("tail{frames}.flac"));
        encode_stereo_flac(&noise(frames, 5), &noise(frames, 6), &path).unwrap();
        if let Err(e) = claxon::FlacReader::open(&path) {
            rejected.push(format!("{frames} frames: {e}"));
        }
    }
    assert!(rejected.is_empty(), "undecodable by claxon: {rejected:?}");
}
#[test]
fn a_shorter_channel_is_padded_with_silence() {
    let dir = tempfile::tempdir().unwrap();
    let path = dir.path().join("uneven.flac");
    let (left, right) = (noise(5_000, 3), noise(3_000, 4));
    encode_stereo_flac(&left, &right, &path).unwrap();
    let decoded = decode(&path);
    assert_eq!(decoded.frames, Some(5_000));
    let decoded_right = channel_of(&decoded, 1);
    assert_eq!(&decoded_right[..3_000], &right[..]);
    assert!(decoded_right[3_000..].iter().all(|&s| s == 0));

    let path = dir.path().join("uneven2.flac");
    encode_stereo_flac(&right, &left, &path).unwrap();
    let decoded = decode(&path);
    assert_eq!(decoded.frames, Some(5_000));
    assert!(channel_of(&decoded, 0)[3_000..].iter().all(|&s| s == 0));
}

#[test]
fn full_scale_silence_and_square_waves_survive() {
    let dir = tempfile::tempdir().unwrap();
    let path = dir.path().join("extremes.flac");
    let square: Vec<i16> = (0..9_000)
        .map(|n| {
            if (n / 20) % 2 == 0 {
                i16::MAX
            } else {
                i16::MIN
            }
        })
        .collect();
    let silence = vec![0i16; 9_000];
    encode_stereo_flac(&square, &silence, &path).unwrap();
    let decoded = decode(&path);
    assert_eq!(channel_of(&decoded, 0), square);
    assert_eq!(channel_of(&decoded, 1), silence);
}

#[test]
fn digital_silence_compresses_to_a_tiny_file() {
    let dir = tempfile::tempdir().unwrap();
    let path = dir.path().join("silence.flac");
    encode_stereo_flac(&vec![0i16; 160_000], &vec![0i16; 160_000], &path).unwrap();
    let size = std::fs::metadata(&path).unwrap().len();
    assert!(size < 2_048, "ten seconds of silence took {size} bytes");
    assert_eq!(decode(&path).frames, Some(160_000));
}

#[test]
fn encoding_nothing_reports_an_error_or_an_empty_file_but_never_panics() {
    let dir = tempfile::tempdir().unwrap();
    let path = dir.path().join("empty.flac");
    let outcome = std::panic::catch_unwind(|| encode_stereo_flac(&[], &[], &path));
    let result = outcome.expect("encoding an empty recording panicked");
    if result.is_ok() {
        assert_eq!(decode(&path).frames.unwrap_or(0), 0);
    }
}

#[test]
fn an_unwritable_destination_is_an_error() {
    let dir = tempfile::tempdir().unwrap();
    let missing = dir.path().join("no-such-dir").join("x.flac");
    assert!(encode_stereo_flac(&[1, 2, 3], &[1, 2, 3], &missing).is_err());
    assert!(encode_mono_flac(&[1, 2, 3], &missing).is_err());
}

// ---- Mono FLAC -------------------------------------------------------------------------------------------------

#[test]
fn mono_flac_is_mono_sixteen_bit_sixteen_kilohertz() {
    let dir = tempfile::tempdir().unwrap();
    let path = dir.path().join("mono.flac");
    let samples = tone(32_000, 523.0, 0.7);
    encode_mono_flac(&samples, &path).unwrap();
    let decoded = decode(&path);
    assert_eq!(
        (decoded.sample_rate, decoded.channels, decoded.bits),
        (16_000, 1, 16)
    );
    assert_eq!(decoded.frames, Some(32_000), "two seconds");
    assert_eq!(channel_of(&decoded, 0), samples);
}

#[test]
fn mono_flac_with_odd_lengths_roundtrips() {
    let dir = tempfile::tempdir().unwrap();
    for frames in [16usize, 17, 4_112, 20_001] {
        let path = dir.path().join(format!("m{frames}.flac"));
        let samples = noise(frames, 99);
        encode_mono_flac(&samples, &path).unwrap();
        assert_eq!(channel_of(&decode(&path), 0), samples, "{frames} frames");
    }
}

// ---- WAV writer ------------------------------------------------------------------------------------------------

#[test]
fn mono_wav_has_the_expected_header_and_frame_count() {
    let dir = tempfile::tempdir().unwrap();
    let path = dir.path().join("mic.wav");
    let mut wav = MonoWav::create(&path).unwrap();
    wav.write(&[0.0, 0.5, -0.5, 1.0, -1.0]).unwrap();
    wav.write_silence(3).unwrap();
    assert_eq!(wav.frames(), 8);
    wav.finalize().unwrap();
    wav.finalize().unwrap(); // idempotent

    let spec = hound::WavReader::open(&path).unwrap().spec();
    assert_eq!(
        (spec.channels, spec.sample_rate, spec.bits_per_sample),
        (1, 16_000, 16)
    );
    assert_eq!(spec.sample_format, hound::SampleFormat::Int);
    assert_eq!(
        read_mono_i16(&path).unwrap(),
        vec![0, 16_384, -16_384, 32_767, -32_768, 0, 0, 0]
    );
}

#[test]
fn silence_of_any_length_is_written_in_full() {
    let dir = tempfile::tempdir().unwrap();
    for frames in [0usize, 1, 4_095, 4_096, 4_097, 10_000, 40_000] {
        let path = dir.path().join(format!("silence{frames}.wav"));
        let mut wav = MonoWav::create(&path).unwrap();
        wav.write_silence(frames).unwrap();
        wav.finalize().unwrap();
        let samples = read_mono_i16(&path).unwrap();
        assert_eq!(samples.len(), frames);
        assert!(samples.iter().all(|&s| s == 0));
    }
}

#[test]
fn writes_after_finalize_are_ignored_and_do_not_corrupt_the_file() {
    let dir = tempfile::tempdir().unwrap();
    let path = dir.path().join("late.wav");
    let mut wav = MonoWav::create(&path).unwrap();
    wav.write(&[0.25; 100]).unwrap();
    wav.finalize().unwrap();
    wav.write(&[0.25; 100]).unwrap();
    assert_eq!(read_mono_i16(&path).unwrap().len(), 100);
}

#[test]
fn a_wav_that_was_never_finalised_is_still_playable_up_to_the_last_flush() {
    let dir = tempfile::tempdir().unwrap();
    let path = dir.path().join("crash.wav");
    let mut wav = MonoWav::create(&path).unwrap();
    let packet = tone(160, 440.0, 0.3)
        .iter()
        .map(|&s| f32::from(s) / 32_768.0)
        .collect::<Vec<_>>();
    for _ in 0..(70_000 / 160) {
        wav.write(&packet).unwrap();
    }
    // The writer is still open (simulating a crash): the file must already be readable.
    let recovered = read_mono_i16(&path)
        .expect("a crashed recording must still open")
        .len();
    assert!(
        recovered >= 64_000 - 160,
        "only {recovered} of 70 000 frames are recoverable; the header is refreshed every two seconds"
    );
    wav.finalize().unwrap();
    assert_eq!(read_mono_i16(&path).unwrap().len(), 437 * 160);
}

#[test]
fn creating_a_wav_in_a_missing_directory_fails() {
    let dir = tempfile::tempdir().unwrap();
    assert!(MonoWav::create(&dir.path().join("nope").join("a.wav")).is_err());
    assert!(read_mono_i16(&dir.path().join("absent.wav")).is_err());
}

// ---- Recorder end to end ---------------------------------------------------------------------------------------

fn record(
    dir: &Path,
    mono_system_only: bool,
    keep: impl Fn(&AudioChunk) -> bool,
) -> desktop_lib::audio::recorder::RecordingOutput {
    let mut recorder = Recorder::create(dir, mono_system_only).unwrap();
    for chunk in MockSource::default()
        .chunks()
        .into_iter()
        .filter(|c| keep(c))
    {
        recorder.push(chunk).unwrap();
    }
    recorder.finish().unwrap()
}

#[test]
fn a_mocked_call_produces_a_decodable_stereo_flac_with_both_channels_in_place() {
    let dir = tempfile::tempdir().unwrap();
    let output = record(dir.path(), false, |_| true);

    let mic = read_mono_i16(&output.mic_wav).unwrap();
    let system = read_mono_i16(&output.system_wav).unwrap();
    assert_eq!(
        mic.len(),
        system.len(),
        "both channels padded to the same length"
    );

    let decoded = decode(&output.primary);
    assert_eq!(
        (decoded.sample_rate, decoded.channels, decoded.bits),
        (16_000, 2, 16)
    );
    assert_eq!(decoded.frames, Some(mic.len() as u64));
    assert_eq!(channel_of(&decoded, 0), mic, "left = Me = mic.wav");
    assert_eq!(
        channel_of(&decoded, 1),
        system,
        "right = Client = system.wav"
    );
    assert_eq!(output.channels, 2);
    assert_eq!(output.duration_ms, mic.len() as u64 * 1_000 / 16_000);
    assert!(
        (output.duration_ms as i64 - 3_000).abs() <= 20,
        "{} ms",
        output.duration_ms
    );
    assert_eq!(output.primary.file_name().unwrap(), "recording.flac");
}

#[test]
fn the_silent_gap_in_the_system_channel_is_filled_and_the_tones_keep_their_pitch() {
    let dir = tempfile::tempdir().unwrap();
    let output = record(dir.path(), false, |_| true);
    let decoded = decode(&output.primary);
    let (me, client) = (channel_of(&decoded, 0), channel_of(&decoded, 1));

    // The mock stops the system channel between 1.0 s and 1.6 s.
    assert!(
        client[16_200..25_400].iter().all(|&s| s == 0),
        "the gap must be silence"
    );
    assert!(
        client[2_000..15_800].iter().any(|&s| s.abs() > 1_000),
        "system audio before the gap"
    );
    assert!(
        client[26_000..40_000].iter().any(|&s| s.abs() > 1_000),
        "system audio after the gap"
    );
    assert!(me.iter().step_by(97).any(|&s| s.abs() > 1_000));

    let seconds = me.len() as f32 / 16_000.0;
    let me_hz = rising_crossings(&me) as f32 / seconds;
    assert!(
        (me_hz - 330.0).abs() < 4.0,
        "Me carries the 330 Hz tone, measured {me_hz}"
    );
    let client_after_gap = &client[26_000..];
    let client_hz =
        rising_crossings(client_after_gap) as f32 / (client_after_gap.len() as f32 / 16_000.0);
    assert!(
        (client_hz - 440.0).abs() < 6.0,
        "Client carries the 440 Hz tone, measured {client_hz}"
    );
}

#[test]
fn a_microphone_voice_note_is_a_mono_flac_of_the_microphone() {
    let dir = tempfile::tempdir().unwrap();
    let mut recorder = Recorder::create_mono(dir.path(), Channel::Mic).unwrap();
    for chunk in MockSource::default()
        .chunks()
        .into_iter()
        .filter(|c| c.channel == Channel::Mic)
    {
        recorder.push(chunk).unwrap();
    }
    let output = recorder.finish().unwrap();
    assert_eq!(output.channels, 1);
    assert_eq!(output.primary.file_name().unwrap(), "voice-note.flac");
    let decoded = decode(&output.primary);
    assert_eq!((decoded.channels, decoded.sample_rate), (1, 16_000));
    assert_eq!(
        channel_of(&decoded, 0),
        read_mono_i16(&output.mic_wav).unwrap()
    );
}

#[test]
fn a_system_audio_voice_note_is_a_mono_flac_of_the_system_channel() {
    let dir = tempfile::tempdir().unwrap();
    let output = record(dir.path(), true, |c| c.channel == Channel::System);
    assert_eq!(output.channels, 1);
    let decoded = decode(&output.primary);
    assert_eq!(decoded.channels, 1);
    assert_eq!(
        channel_of(&decoded, 0),
        read_mono_i16(&output.system_wav).unwrap()
    );
    assert!(channel_of(&decoded, 0).iter().any(|&s| s.abs() > 1_000));
}

#[test]
fn a_one_sided_recording_still_yields_two_equal_length_channels() {
    let dir = tempfile::tempdir().unwrap();
    let output = record(dir.path(), false, |c| c.channel == Channel::Mic);
    let mic = read_mono_i16(&output.mic_wav).unwrap();
    let system = read_mono_i16(&output.system_wav).unwrap();
    assert_eq!(mic.len(), system.len());
    assert!(
        system.iter().all(|&s| s == 0),
        "the idle channel is pure silence"
    );
    assert_eq!(decode(&output.primary).frames, Some(mic.len() as u64));
}

#[test]
fn ending_a_recording_that_heard_nothing_does_not_panic() {
    let dir = tempfile::tempdir().unwrap();
    let recorder = Recorder::create(dir.path(), false).unwrap();
    let outcome = std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| recorder.finish()));
    let result = outcome.expect("finishing an empty recording panicked");
    if let Ok(output) = result {
        assert_eq!(output.duration_ms, 0);
    }
}

#[test]
fn empty_packets_are_ignored() {
    let dir = tempfile::tempdir().unwrap();
    let mut recorder = Recorder::create(dir.path(), false).unwrap();
    recorder
        .push(AudioChunk {
            channel: Channel::Mic,
            samples: vec![],
            ts_ns: Some(1),
            discontinuity: false,
        })
        .unwrap();
    assert_eq!(recorder.frames(), (0, 0));
}

#[test]
fn live_sinks_receive_the_pcm_that_was_recorded() {
    let dir = tempfile::tempdir().unwrap();
    let mut recorder = Recorder::create(dir.path(), false).unwrap();
    let (tx, mut rx) = tokio::sync::mpsc::unbounded_channel::<Vec<u8>>();
    recorder.set_live_sink(Channel::Mic, Some(tx));
    let samples: Vec<f32> = (0..160).map(|n| (n as f32 / 160.0) - 0.5).collect();
    recorder
        .push(AudioChunk {
            channel: Channel::Mic,
            samples: samples.clone(),
            ts_ns: Some(0),
            discontinuity: false,
        })
        .unwrap();
    recorder
        .push(AudioChunk {
            channel: Channel::System,
            samples: samples.clone(),
            ts_ns: Some(0),
            discontinuity: false,
        })
        .unwrap();
    let bytes = rx.try_recv().expect("the mic packet reaches its live sink");
    assert_eq!(bytes.len(), 320, "160 samples of s16le");
    assert!(
        rx.try_recv().is_err(),
        "the system packet must not reach the mic sink"
    );
    let first = i16::from_le_bytes([bytes[0], bytes[1]]);
    assert_eq!(first, desktop_lib::audio::dsp::f32_to_i16(samples[0]));
}

#[test]
fn a_dropped_live_sink_is_detached_without_failing_the_recording() {
    let dir = tempfile::tempdir().unwrap();
    let mut recorder = Recorder::create(dir.path(), false).unwrap();
    let (tx, rx) = tokio::sync::mpsc::unbounded_channel::<Vec<u8>>();
    recorder.set_live_sink(Channel::Mic, Some(tx));
    drop(rx);
    for i in 0..5u64 {
        recorder
            .push(AudioChunk {
                channel: Channel::Mic,
                samples: vec![0.1; 160],
                ts_ns: Some(i * 10_000_000),
                discontinuity: false,
            })
            .unwrap();
    }
    assert_eq!(recorder.frames().0, 800);
}

#[test]
fn a_first_packet_without_a_timestamp_does_not_inflate_the_recording() {
    // Device clocks report time since boot. If the very first packet carries no timestamp (WASAPI sets a
    // timestamp-error flag now and then), the later, stamped packets must not be placed "ten seconds in".
    let dir = tempfile::tempdir().unwrap();
    let mut recorder = Recorder::create(dir.path(), false).unwrap();
    recorder
        .push(AudioChunk {
            channel: Channel::Mic,
            samples: vec![0.1; 160],
            ts_ns: None,
            discontinuity: false,
        })
        .unwrap();
    for i in 0..10u64 {
        recorder
            .push(AudioChunk {
                channel: Channel::Mic,
                samples: vec![0.1; 160],
                ts_ns: Some(10_000_000_000 + i * 10_000_000),
                discontinuity: false,
            })
            .unwrap();
    }
    let (mic_frames, _) = recorder.frames();
    assert!(
        mic_frames < 16_000,
        "{mic_frames} frames recorded for 110 ms of audio"
    );
}
