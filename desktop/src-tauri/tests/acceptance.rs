//! Acceptance test (Phase 4): a mocked two-channel capture must write `mic.wav` and `system.wav` of equal
//! length, with the system channel's silence gap padded so both channels stay sample-aligned to a shared T0.

use desktop_lib::audio::mock::MockSource;
use desktop_lib::audio::recorder::Recorder;
use desktop_lib::audio::types::TARGET_RATE;
use desktop_lib::audio::writer::read_mono_i16;

#[test]
fn mocked_two_channel_capture_produces_aligned_equal_length_channels() {
    let dir = tempfile::tempdir().expect("tempdir");
    let mut recorder = Recorder::create(dir.path(), false).expect("create recorder");

    let source = MockSource::default(); // 3 s, system silent 1.0–1.6 s, +80 ppm drift, timestamp jitter
    for chunk in source.chunks() {
        recorder.push(chunk).expect("push");
    }
    let output = recorder.finish().expect("finish");

    let mic = read_mono_i16(&output.mic_wav).expect("read mic.wav");
    let system = read_mono_i16(&output.system_wav).expect("read system.wav");

    assert_eq!(mic.len(), system.len(), "mic and system channels must be equal length after padding");
    assert_eq!(output.channels, 2, "a call recording is stereo");
    assert!(output.primary.exists(), "the stereo upload file must exist");

    // ~3 seconds at 16 kHz, within tolerance for drift/jitter rounding.
    let expected = 3 * TARGET_RATE as usize;
    assert!((mic.len() as i64 - expected as i64).abs() < (TARGET_RATE as i64 / 10), "got {} frames", mic.len());
    assert!((output.duration_ms as i64 - 3_000).abs() < 200, "duration {} ms", output.duration_ms);

    // The mic channel (steady 330 Hz tone) must carry real signal.
    let energy: f64 = mic.iter().map(|&s| (s as f64).abs()).sum();
    assert!(energy > 0.0, "mic channel should not be silent");
}

/// A voice note spoken into the microphone must upload the MIC channel as mono audio (not the silent system one).
#[test]
fn mic_voice_note_uploads_a_mono_file_that_contains_the_microphone() {
    use desktop_lib::audio::types::Channel;

    let dir = tempfile::tempdir().expect("tempdir");
    let mut recorder = Recorder::create_mono(dir.path(), Channel::Mic).expect("create recorder");
    for chunk in MockSource::default().chunks().into_iter().filter(|c| c.channel == Channel::Mic) {
        recorder.push(chunk).expect("push");
    }
    let output = recorder.finish().expect("finish");

    assert_eq!(output.channels, 1, "a voice note is mono");
    assert_eq!(output.primary.file_name().and_then(|n| n.to_str()), Some("voice-note.flac"));
    assert!(std::fs::metadata(&output.primary).expect("primary file").len() > 1024, "a spoken note is far larger than the empty-audio floor");
}
