//! Recording lifecycle (A2, A3.3, A3.4, A5.4, v1.5 1.2): pause without gaps, Keep / Discard decisions that survive a
//! restart, "After a call" and its chip, the tray state and the streaming usage read from AssemblyAI.

use std::path::Path;
use std::time::{Duration, Instant};

use chrono::{TimeZone, Utc};
use desktop_lib::audio::clock::RecordingClock;
use desktop_lib::audio::recorder::Recorder;
use desktop_lib::audio::recorder::RecordingOutput;
use desktop_lib::audio::sleep::{Reading, SleepClock};
use desktop_lib::audio::types::{AudioChunk, Channel, TARGET_RATE};
use desktop_lib::audio::writer::read_mono_i16;
use desktop_lib::audio::{FinishedRecording, RecordingInfo, RecordingMode};
use desktop_lib::net::models::{MarkerDto, PauseDto, SegmentDto};
use desktop_lib::net::uploader::{DiscardQueue, UploadQueue};
use desktop_lib::pending::{self, DecisionFiles, DecisionInput, PendingDecision};
use desktop_lib::settings::AfterCall;
use desktop_lib::skipped::{self, SkipReason, SkippedCall, SkippedFiles};
use desktop_lib::stt::protocol::assemblyai_session_seconds;
use desktop_lib::tray::{TrayState, tray_state};

// ---- clock -------------------------------------------------------------------------------------------------------

#[test]
fn the_clock_leaves_out_paused_time() {
    let t0 = Instant::now();
    let mut clock = RecordingClock::new(t0);
    clock.pause(t0 + Duration::from_secs(5));
    clock.resume(t0 + Duration::from_secs(15));
    assert_eq!(clock.elapsed_ms(t0 + Duration::from_secs(20)), 10_000);
}

#[test]
fn pausing_twice_or_resuming_twice_changes_nothing() {
    let t0 = Instant::now();
    let mut clock = RecordingClock::new(t0);
    clock.pause(t0 + Duration::from_secs(2));
    clock.pause(t0 + Duration::from_secs(4));
    assert!(clock.is_paused());
    assert_eq!(
        clock.elapsed_ms(t0 + Duration::from_secs(9)),
        2_000,
        "time stands still while paused"
    );
    clock.resume(t0 + Duration::from_secs(10));
    clock.resume(t0 + Duration::from_secs(11));
    assert_eq!(clock.elapsed_ms(t0 + Duration::from_secs(12)), 4_000);
}

// ---- recorder pause ----------------------------------------------------------------------------------------------

fn tone(channel: Channel, start_s: f64, seconds: f64) -> Vec<AudioChunk> {
    let per_chunk = TARGET_RATE as usize / 10; // 100 ms
    let chunks = (seconds * 10.0).round() as usize;
    (0..chunks)
        .map(|i| {
            let ts = start_s + i as f64 * 0.1;
            let samples = (0..per_chunk)
                .map(|n| ((n as f32) * 0.05).sin() * 0.3)
                .collect();
            AudioChunk {
                channel,
                samples,
                ts_ns: Some((ts * 1e9) as u64),
                discontinuity: false,
            }
        })
        .collect()
}

#[test]
fn a_ten_second_pause_leaves_no_gap_and_keeps_both_channels_equal() {
    let dir = tempfile::tempdir().unwrap();
    let mut recorder = Recorder::create(dir.path(), false).unwrap();
    for (mic, system) in
        tone(Channel::Mic, 1.0, 2.0)
            .into_iter()
            .zip(tone(Channel::System, 1.0, 2.0))
    {
        recorder.push(mic).unwrap();
        recorder.push(system).unwrap();
    }
    recorder.mark_resume().unwrap();
    // Resumed 10 s later on the capture clock.
    for (mic, system) in
        tone(Channel::Mic, 13.0, 2.0)
            .into_iter()
            .zip(tone(Channel::System, 13.0, 2.0))
    {
        recorder.push(mic).unwrap();
        recorder.push(system).unwrap();
    }
    let output = recorder.finish().unwrap();

    let mic = read_mono_i16(&output.mic_wav).unwrap();
    let system = read_mono_i16(&output.system_wav).unwrap();
    assert_eq!(
        mic.len(),
        system.len(),
        "channels stay sample-aligned across the pause"
    );
    assert!(
        (output.duration_ms as i64 - 4_000).abs() < 250,
        "only recorded time counts, got {} ms",
        output.duration_ms
    );
}

// ---- sleep and silence ---------------------------------------------------------------------------------------------

/// The two system clocks, moved by hand: time awake and time asleep.
#[derive(Clone, Default)]
struct HandClock(std::sync::Arc<std::sync::Mutex<Reading>>);

impl SleepClock for HandClock {
    fn read(&self) -> Reading {
        *self.0.lock().unwrap()
    }
}

impl HandClock {
    fn sleep(&self, seconds: f64) {
        self.0.lock().unwrap().asleep_ns += (seconds * 1e9) as u64;
    }
    fn asleep_s(&self) -> f64 {
        self.0.lock().unwrap().asleep_ns as f64 / 1e9
    }
    fn set_awake(&self, seconds: f64) {
        self.0.lock().unwrap().awake_ns = (seconds * 1e9) as u64;
    }
}

/// Which device clock the packets are stamped with.
#[derive(Clone, Copy, PartialEq)]
enum DeviceClock {
    /// Runs on through sleep (what made DC-01).
    RunsInSleep,
    /// Stops in sleep, like the awake clock (a Mac's host time).
    StopsInSleep,
}

/// A recorder on a hand-moved clock, fed 100 ms packets in time order: `sides(t)` says which channels speak at device
/// time `t`. Each packet reaches the recorder as it ends.
struct Feed {
    recorder: Recorder,
    clock: HandClock,
    device: DeviceClock,
    /// How late packets reach the recorder (a busy recorder thread works through a backlog).
    lag: f64,
    _dir: tempfile::TempDir,
}

impl Feed {
    fn new(device: DeviceClock) -> Self {
        let dir = tempfile::tempdir().unwrap();
        let clock = HandClock::default();
        let recorder = Recorder::create(dir.path(), false).unwrap().with_sleep_clock(Box::new(clock.clone()));
        Self { recorder, clock, device, lag: 0.0, _dir: dir }
    }

    fn run(&mut self, from: f64, to: f64, sides: impl Fn(f64) -> Vec<Channel>) {
        let ticks = ((to - from) * 10.0).round() as usize;
        for i in 0..ticks {
            let t = from + i as f64 * 0.1;
            let asleep = if self.device == DeviceClock::RunsInSleep { self.clock.asleep_s() } else { 0.0 };
            self.clock.set_awake(t + 0.1 + self.lag - asleep);
            for channel in sides(t) {
                self.recorder.push(tone(channel, t, 0.1).remove(0)).unwrap();
            }
        }
    }

    fn finish(self) -> (RecordingOutput, Vec<i16>, Vec<i16>) {
        let output = self.recorder.finish().unwrap();
        let mic = read_mono_i16(&output.mic_wav).unwrap();
        let system = read_mono_i16(&output.system_wav).unwrap();
        assert_eq!(mic.len(), system.len(), "both sides stay aligned");
        (output, mic, system)
    }
}

fn near(got_ms: u64, want_ms: i64, why: &str) {
    assert!((got_ms as i64 - want_ms).abs() < 300, "{why}: want {want_ms} ms, got {got_ms} ms");
}

/// Whether a channel has sound in the 100 ms starting `at_s` seconds into the file.
fn sounds_at(samples: &[i16], at_s: f64) -> bool {
    let from = (at_s * TARGET_RATE as f64) as usize;
    samples[from..from + TARGET_RATE as usize / 10].iter().any(|&s| s.unsigned_abs() > 100)
}

#[test]
fn the_other_side_quiet_for_90_s_while_the_microphone_flows_keeps_the_90_s() {
    let mut feed = Feed::new(DeviceClock::RunsInSleep);
    feed.run(1.0, 101.0, |t| if !(4.0..95.0).contains(&t) { vec![Channel::Mic, Channel::System] } else { vec![Channel::Mic] });
    let (output, _, system) = feed.finish();
    near(output.duration_ms, 100_000, "the whole call");
    assert!(!sounds_at(&system, 50.0), "the other person's silence is silence");
    assert!(sounds_at(&system, 96.0), "and they speak again where they did");
}

#[test]
fn a_system_only_recording_quiet_for_90_s_with_no_sleep_keeps_it() {
    // The microphone denied: only this computer's side, which sends nothing on Windows' loopback while it is quiet.
    let mut feed = Feed::new(DeviceClock::RunsInSleep);
    feed.run(1.0, 97.0, |t| if !(4.0..94.0).contains(&t) { vec![Channel::System] } else { vec![] });
    let (output, _, system) = feed.finish();
    near(output.duration_ms, 96_000, "a long silence is not a sleep");
    assert!(sounds_at(&system, 93.5));
}

#[test]
fn a_late_recorder_before_a_quiet_spell_drops_nothing_without_a_sleep() {
    // The packets before the quiet reached the recorder 5 s late: measured by arrival, the quiet looks 5 s shorter
    // than its gap. Without a sleep on the clocks, nothing goes.
    let mut feed = Feed::new(DeviceClock::RunsInSleep);
    feed.lag = 5.0;
    feed.run(1.0, 4.0, |_| vec![Channel::System]);
    feed.lag = 0.0;
    feed.run(4.0, 97.0, |t| if t >= 94.0 { vec![Channel::System] } else { vec![] });
    let (output, _, _) = feed.finish();
    near(output.duration_ms, 96_000, "missing packets alone are never a sleep");
}

#[test]
fn a_computer_that_slept_mid_recording_adds_no_hours_of_silence() {
    let mut feed = Feed::new(DeviceClock::RunsInSleep);
    let both = |_: f64| vec![Channel::Mic, Channel::System];
    feed.run(1.0, 4.0, both);
    feed.clock.sleep(7_200.0); // the device clock ran on through two hours of sleep
    feed.run(4.0 + 7_200.0, 7.0 + 7_200.0, both);
    // A short gap (a device that went quiet for 2 s) is still kept as silence.
    feed.run(9.0 + 7_200.0, 10.0 + 7_200.0, both);
    let (output, mic, _) = feed.finish();
    near(output.duration_ms, 9_000, "recorded time and the short gap only");
    assert!(sounds_at(&mic, 3.5) && !sounds_at(&mic, 6.5) && sounds_at(&mic, 8.5));
}

#[test]
fn a_quiet_side_then_a_sleep_keeps_the_quiet_and_drops_the_sleep() {
    let mut feed = Feed::new(DeviceClock::RunsInSleep);
    feed.run(1.0, 94.0, |t| if t < 4.0 { vec![Channel::System] } else { vec![] }); // 90 s of quiet, awake
    feed.clock.sleep(3_600.0);
    feed.run(94.0 + 3_600.0, 97.0 + 3_600.0, |_| vec![Channel::System]);
    let (output, _, system) = feed.finish();
    near(output.duration_ms, 96_000, "the quiet stays, the hour asleep goes");
    assert!(!sounds_at(&system, 50.0) && sounds_at(&system, 93.5));
}

#[test]
fn a_quiet_side_then_a_sleep_on_a_clock_that_stops_in_sleep_keeps_the_quiet() {
    // The packets' gap is the quiet only: the time asleep must not be taken out of it.
    let mut feed = Feed::new(DeviceClock::StopsInSleep);
    feed.run(1.0, 94.0, |t| if t < 4.0 { vec![Channel::System] } else { vec![] });
    feed.clock.sleep(3_600.0);
    feed.run(94.0, 97.0, |_| vec![Channel::System]);
    let (output, _, system) = feed.finish();
    near(output.duration_ms, 96_000, "the quiet stays");
    assert!(sounds_at(&system, 93.5));
}

#[test]
fn a_device_clock_that_stops_in_sleep_loses_nothing() {
    let mut feed = Feed::new(DeviceClock::StopsInSleep);
    let both = |_: f64| vec![Channel::Mic, Channel::System];
    feed.run(1.0, 4.0, both);
    feed.clock.sleep(3_600.0); // the packets show no gap: nothing to drop, and no audio skipped
    feed.run(4.0, 7.0, both);
    let (output, mic, system) = feed.finish();
    near(output.duration_ms, 6_000, "all of it");
    assert!((0..59).all(|i| sounds_at(&mic, i as f64 * 0.1) && sounds_at(&system, i as f64 * 0.1)), "no hole anywhere");
}

/// The 60-minute soak (Day 5 bug bash): an hour of both channels with a slowly drifting system clock, jitter and a
/// pause, on the real writer and encoder. Ignored by default (a few hundred MB on disk while it runs):
/// `cargo test --test recording_lifecycle soak -- --ignored`.
#[test]
#[ignore]
fn soak_an_hour_long_recording_stays_aligned_and_whole() {
    let dir = tempfile::tempdir().unwrap();
    let mut recorder = Recorder::create(dir.path(), false).unwrap();
    let per_chunk = TARGET_RATE as usize / 100; // 10 ms packets, as the capture sends them
    let packet = |channel: Channel, ts: f64, i: usize| AudioChunk {
        channel,
        samples: (0..per_chunk).map(|n| (((i * per_chunk + n) as f32) * 0.05).sin() * 0.2).collect(),
        ts_ns: Some((ts * 1e9) as u64),
        discontinuity: false,
    };
    let started = std::time::Instant::now();
    let packets = 360_000; // one hour
    for i in 0..packets {
        let t = 1.0 + i as f64 * 0.01;
        // The system device's clock runs 50 ppm fast, and packets arrive with up to 3 ms of jitter.
        let jitter = ((i * 7919) % 7) as f64 * 0.0005;
        recorder.push(packet(Channel::Mic, t + jitter, i)).unwrap();
        recorder.push(packet(Channel::System, t * 1.000_05, i)).unwrap();
        if i == 180_000 {
            recorder.mark_resume().unwrap(); // a pause halfway
        }
    }
    let output = recorder.finish().unwrap();
    let mic = read_mono_i16(&output.mic_wav).unwrap();
    let system = read_mono_i16(&output.system_wav).unwrap();
    assert_eq!(mic.len(), system.len(), "aligned after an hour");
    assert!((output.duration_ms as i64 - 3_600_000).abs() < 2_000, "an hour, got {} ms", output.duration_ms);
    assert!(std::fs::metadata(&output.primary).unwrap().len() > 0, "the upload file is written");
    eprintln!("soak: {} ms of audio written and encoded in {:?}", output.duration_ms, started.elapsed());
}

#[test]
fn the_recorder_measures_how_long_each_side_was_heard() {
    let dir = tempfile::tempdir().unwrap();
    let mut recorder = Recorder::create(dir.path(), false).unwrap();
    // 3 s of speech into the microphone; the other side sends only comfort noise (about -70 dBFS).
    let comfort_noise = tone(Channel::System, 1.0, 3.0).into_iter().map(|mut chunk| {
        chunk.samples.iter_mut().for_each(|s| *s *= 0.001);
        chunk
    });
    for (mic, system) in tone(Channel::Mic, 1.0, 3.0).into_iter().zip(comfort_noise) {
        recorder.push(mic).unwrap();
        recorder.push(system).unwrap();
    }
    let output = recorder.finish().unwrap();
    assert!((output.speech_ms[0] as i64 - 3_000).abs() <= 100, "the microphone was heard: {:?}", output.speech_ms);
    assert_eq!(output.speech_ms[1], 0, "comfort noise is not speech");
}

// ---- decision rules ----------------------------------------------------------------------------------------------

#[test]
fn after_a_call_applies_to_calls_and_voice_notes_are_always_kept() {
    for setting in [AfterCall::Ask, AfterCall::AlwaysTranscribe, AfterCall::AlwaysAudio] {
        assert_eq!(pending::after_call(setting, RecordingMode::Call), setting);
    }
    for note in [RecordingMode::VoiceNote, RecordingMode::MicNote] {
        for setting in [AfterCall::Ask, AfterCall::AlwaysAudio] {
            assert_eq!(pending::after_call(setting, note), AfterCall::AlwaysTranscribe, "a voice note was stopped on purpose");
        }
    }
    assert_eq!(AfterCall::Ask.keeps_at_once(), None, "the card asks");
    assert_eq!(AfterCall::AlwaysTranscribe.keeps_at_once(), Some(true));
    assert_eq!(AfterCall::AlwaysAudio.keeps_at_once(), Some(false), "kept at once, not transcribed");
    assert_eq!(pending::CHIP_SECONDS, 12, "v1.8 P1: no answer within 12 s saves the audio");
}

// ---- pending store -----------------------------------------------------------------------------------------------

fn finished_recording(root: &Path, name: &str) -> FinishedRecording {
    let dir = root.join("recordings").join(name);
    std::fs::create_dir_all(&dir).unwrap();
    for file in ["recording.flac", "mic.wav", "system.wav"] {
        std::fs::write(dir.join(file), vec![1u8; 4096]).unwrap();
    }
    let started_at = Utc.with_ymd_and_hms(2026, 10, 25, 8, 0, 0).unwrap();
    FinishedRecording {
        markers: vec![MarkerDto { at_ms: 5_000, tag: Some("Budget".into()), note: None }],
        pauses: vec![PauseDto { at_ms: 7_000, paused_ms: 3_000 }],
        info: RecordingInfo {
            client_ref: format!("ref-{name}"),
            mode: RecordingMode::Call,
            source_app: "whatsapp".into(),
            client_id: Some("01ARZ3NDEKTSV4RRFFQ69G5FAV".into()),
            started_at,
            backend: desktop_lib::audio::types::BackendKind::Mock,
        },
        ended_at: started_at + chrono::Duration::seconds(90),
        output: RecordingOutput {
            dir: dir.clone(),
            mic_wav: dir.join("mic.wav"),
            system_wav: dir.join("system.wav"),
            primary: dir.join("recording.flac"),
            channels: 2,
            duration_ms: 90_000,
            speech_ms: [30_000, 42_000],
        },
    }
}

fn decision(
    root: &Path,
    name: &str,
    session: Option<&str>,
    created_at: chrono::DateTime<Utc>,
) -> PendingDecision {
    let finished = finished_recording(root, name);
    let segments = vec![SegmentDto {
        channel: 2,
        speaker: "Client".into(),
        start_ms: 400,
        end_ms: 1200,
        text: "Hello there".into(),
        words: vec![],
        uncertain: false,
        check: None,
    }];
    PendingDecision::from_recording(
        &finished,
        DecisionInput {
            auto: true,
            client_name: Some("Acme".into()),
            live_segments: segments,
            live_provider: Some("assemblyai".into()),
            server_session_id: session.map(str::to_string),
            created_at,
            language: Some("ur".into()),
            language_marks: vec![],
            caller: None,
        },
    )
}

#[test]
fn bookmarks_and_pauses_survive_a_restart_and_go_with_the_upload() {
    let root = tempfile::tempdir().unwrap();
    let files = DecisionFiles::in_dir(root.path());
    files.add(decision(root.path(), "m", None, Utc::now())).unwrap();

    let upload = DecisionFiles::in_dir(root.path()).load().find("m").unwrap().to_upload();

    assert_eq!(upload.markers, vec![MarkerDto { at_ms: 5_000, tag: Some("Budget".into()), note: None }]);
    assert_eq!(upload.pauses, vec![PauseDto { at_ms: 7_000, paused_ms: 3_000 }]);
    assert_eq!(upload.language.as_deref(), Some("ur"), "the language chosen when the recording ended");
}

#[test]
fn changing_the_language_chip_before_keep_changes_the_final_transcript_language() {
    let root = tempfile::tempdir().unwrap();
    let files = DecisionFiles::in_dir(root.path());
    files.add(decision(root.path(), "m", None, Utc::now())).unwrap();
    assert_eq!(files.load().find("m").unwrap().item().language.as_deref(), Some("ur"));

    let item = files.set_language("m", Some("ar".into())).unwrap().item();
    assert_eq!(item.language.as_deref(), Some("ar"), "the card shows the new chip");

    let upload = DecisionFiles::in_dir(root.path()).load().find("m").unwrap().to_upload();
    assert_eq!(upload.language.as_deref(), Some("ar"), "Keep uploads with the chip's value");
    files.set_language("m", None).unwrap();
    assert_eq!(files.load().find("m").unwrap().to_upload().language, None, "Auto: the server decides");
}

#[test]
fn waiting_recordings_survive_a_restart() {
    let root = tempfile::tempdir().unwrap();
    let files = DecisionFiles::in_dir(root.path());
    files
        .add(decision(root.path(), "a", None, Utc::now()))
        .unwrap();
    let keep_at = Utc::now() + chrono::Duration::seconds(pending::CHIP_SECONDS);
    files
        .add(decision(
            root.path(),
            "b",
            Some("01SESSIONAAAAAAAAAAAAAAAAA"),
            Utc::now(),
        ).keeping_at(keep_at))
        .unwrap();

    let reloaded = DecisionFiles::in_dir(root.path()).load();
    assert_eq!(reloaded.items.len(), 2);
    let item = reloaded.find("a").unwrap().item();
    assert_eq!(item.first_line.as_deref(), Some("Client: Hello there"));
    assert_eq!(item.client_name.as_deref(), Some("Acme"));
    assert!(item.deadline_at.is_none(), "no chip counts down: it waits for the person");
    let chip = reloaded.find("b").unwrap().item();
    assert_eq!(chip.deadline_at, Some(keep_at.to_rfc3339()), "the chip's time survives a restart");
    assert_eq!(reloaded.waiting(), 1, "only the one without a chip waits for the person");
    assert_eq!(reloaded.next_keep_at(), Some(keep_at));
}

#[test]
fn a_pending_file_from_before_v1_5_loads_with_every_recording_waiting() {
    let root = tempfile::tempdir().unwrap();
    let files = DecisionFiles::in_dir(root.path());
    files.add(decision(root.path(), "old", None, Utc::now() - chrono::Duration::days(3))).unwrap();
    // A v1.4 entry has no `keep_at`: written without it, it must load and wait (never be kept by itself).
    let mut file: serde_json::Value = serde_json::from_slice(&std::fs::read(&files.pending).unwrap()).unwrap();
    file["items"][0].as_object_mut().unwrap().remove("keep_at");
    std::fs::write(&files.pending, serde_json::to_vec(&file).unwrap()).unwrap();

    let store = files.load();
    assert_eq!(store.items.len(), 1);
    assert!(store.items[0].waits());
    assert!(files.keep_due(Utc::now() + chrono::Duration::days(30)).unwrap().is_empty(), "nothing keeps a waiting recording");
    assert!(UploadQueue::load(&files.uploads).items.is_empty());
}

#[test]
fn a_damaged_pending_file_loads_as_empty_instead_of_failing() {
    let root = tempfile::tempdir().unwrap();
    std::fs::write(root.path().join("pending.json"), b"{ not json").unwrap();
    assert!(DecisionFiles::in_dir(root.path()).load().items.is_empty());
}

#[test]
fn keep_moves_the_recording_to_the_upload_queue_with_its_server_session() {
    let root = tempfile::tempdir().unwrap();
    let files = DecisionFiles::in_dir(root.path());
    files
        .add(decision(
            root.path(),
            "a",
            Some("01SESSIONAAAAAAAAAAAAAAAAA"),
            Utc::now(),
        ))
        .unwrap();

    files.keep("a").unwrap();

    let queue = UploadQueue::load(&files.uploads);
    assert_eq!(queue.items.len(), 1);
    assert_eq!(
        queue.items[0].server_session_id.as_deref(),
        Some("01SESSIONAAAAAAAAAAAAAAAAA")
    );
    assert_eq!(
        queue.items[0].live_segments.len(),
        1,
        "the live transcript goes with a kept recording"
    );
    assert!(files.load().items.is_empty());
    assert!(
        root.path().join("recordings/a/recording.flac").exists(),
        "kept audio stays until the server has it"
    );
    assert_eq!(
        files.keep("a").unwrap_err().to_string(),
        pending::NOT_WAITING
    );
}

#[test]
fn discard_deletes_the_files_uploads_nothing_and_deletes_the_server_session() {
    let root = tempfile::tempdir().unwrap();
    let files = DecisionFiles::in_dir(root.path());
    files
        .add(decision(
            root.path(),
            "a",
            Some("01SESSIONAAAAAAAAAAAAAAAAA"),
            Utc::now(),
        ))
        .unwrap();

    files.discard("a").unwrap();

    assert!(!root.path().join("recordings/a").exists());
    assert!(
        UploadQueue::load(&files.uploads).items.is_empty(),
        "nothing is uploaded, so no transcript is requested"
    );
    assert!(
        DiscardQueue::load(&files.discards)
            .items
            .contains(&"01SESSIONAAAAAAAAAAAAAAAAA".to_string())
    );
    assert!(files.load().items.is_empty());
}

#[test]
fn the_chips_discard_deletes_nothing_until_its_undo_time_is_over() {
    let root = tempfile::tempdir().unwrap();
    let files = DecisionFiles::in_dir(root.path());
    let now = Utc::now();
    files.add(decision(root.path(), "a", Some("01SESSIONAAAAAAAAAAAAAAAAA"), now).keeping_at(now + chrono::Duration::seconds(8))).unwrap();
    let folder = root.path().join("recordings/a");
    let at = now + chrono::Duration::seconds(8);

    files.discard_later("a", at).unwrap();

    // Gone from every list and count, its chip no longer counting, but every file and the server session still there.
    let store = files.load();
    assert!(store.ui_items().is_empty() && store.waiting() == 0, "no list or count shows it");
    assert!(!store.find("a").unwrap().counting(), "the chip no longer keeps it");
    assert!(folder.join("recording.flac").exists() && folder.join("mic.wav").exists() && folder.join("system.wav").exists());
    assert!(DiscardQueue::load(&files.discards).items.is_empty(), "the server session is not deleted yet");
    assert_eq!(store.next_discard(now), Some(at));
    // Before its time: nothing happens, also when the app starts again meanwhile.
    assert!(files.discard_due(now + chrono::Duration::seconds(7)).0.is_empty());
    assert!(DecisionFiles::in_dir(root.path()).discard_due(now).0.is_empty(), "a restart within the time");
    assert!(folder.exists());
    // Once over: the files and the server session go, as with any discard.
    let (done, failed) = files.discard_due(at);
    assert!(failed.is_empty());
    assert_eq!(done.iter().map(|d| d.id.as_str()).collect::<Vec<_>>(), ["a"]);
    assert!(!folder.exists());
    assert!(DiscardQueue::load(&files.discards).items.contains(&"01SESSIONAAAAAAAAAAAAAAAAA".to_string()));
    assert!(files.load().items.is_empty());
    assert!(UploadQueue::load(&files.uploads).items.is_empty(), "nothing is uploaded");
}

#[test]
fn due_discards_go_one_by_one_and_one_that_fails_never_stops_the_others() {
    let root = tempfile::tempdir().unwrap();
    let files = DecisionFiles::in_dir(root.path());
    let now = Utc::now();
    // "b" (first in the file) has a server session to queue, and the queue cannot be written; "a" has none.
    files.add(decision(root.path(), "b", Some("01SESSIONBBBBBBBBBBBBBBBBB"), now)).unwrap();
    files.add(decision(root.path(), "a", None, now)).unwrap();
    for id in ["b", "a"] {
        files.discard_later(id, now).unwrap();
    }
    std::fs::create_dir_all(&files.discards).unwrap();

    let (done, failed) = files.discard_due(now);

    assert_eq!(done.iter().map(|d| d.id.as_str()).collect::<Vec<_>>(), ["a"], "the one that could go went");
    assert_eq!(failed.iter().map(|(id, _)| id.as_str()).collect::<Vec<_>>(), ["b"]);
    assert!(!root.path().join("recordings/a").exists());
    assert!(root.path().join("recordings/b/recording.flac").exists(), "nothing of the failed one is deleted");
    assert!(files.load().find("b").is_some_and(|d| d.discarding()), "it stays for the next try");
    assert!(!root.path().join("recordings/b.leaving").exists(), "and is never marked on its way out");
}

#[test]
fn a_discard_that_cannot_finish_takes_its_server_delete_back() {
    let root = tempfile::tempdir().unwrap();
    let files = DecisionFiles::in_dir(root.path());
    files.add(decision(root.path(), "a", Some("01SESSIONAAAAAAAAAAAAAAAAA"), Utc::now())).unwrap();
    // Another session already waiting to be deleted stays queued whatever happens.
    let mut queue = DiscardQueue::load(&files.discards);
    queue.push("01SESSIONOTHERXXXXXXXXXXXXX".into());
    queue.save(&files.discards).unwrap();
    // The folder's mark can't be written (a folder sits where it goes): the discard stops.
    std::fs::create_dir_all(root.path().join("recordings/a.leaving")).unwrap();

    assert!(files.discard("a").is_err());

    assert_eq!(DiscardQueue::load(&files.discards).items, vec!["01SESSIONOTHERXXXXXXXXXXXXX".to_string()], "its own delete taken back, the other kept");
    assert!(files.load().find("a").is_some(), "the recording still waits");
    assert!(root.path().join("recordings/a/recording.flac").exists());
}

#[test]
fn a_discarded_folder_is_marked_before_its_entry_leaves_pending_json() {
    // A crash between the two would otherwise leave a folder no queue knows, offered back as "Recovered recording".
    let source = std::fs::read_to_string(std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("src/pending.rs")).unwrap();
    let discard = &source[source.find("    pub fn discard(&self, id: &str)").unwrap()..];
    let discard = &discard[..discard.find("\n    }\n").unwrap()];
    let mark = discard.find("crate::recovery::mark_leaving(&decision.dir)").expect("marked");
    let save = discard.find("store.save(&self.pending)").expect("saved");
    assert!(mark < save, "the mark comes first");
    // And a marked folder no queue knows is deleted at start, not recovered.
    let root = tempfile::tempdir().unwrap();
    let files = DecisionFiles::in_dir(root.path());
    files.add(decision(root.path(), "gone", None, Utc::now())).unwrap();
    let folder = root.path().join("recordings/gone");
    desktop_lib::recovery::mark_leaving(&folder).unwrap();
    // The crash: its entry left pending.json, its folder is still there.
    let mut store = files.load();
    store.items.clear();
    store.save(&files.pending).unwrap();
    let recovery = desktop_lib::recovery::recover(root.path(), Utc::now());
    assert!(recovery.recovered.is_empty(), "never offered back");
    assert!(!folder.exists());
}

#[test]
fn undo_after_the_chips_discard_brings_the_recording_back_whole() {
    let root = tempfile::tempdir().unwrap();
    let files = DecisionFiles::in_dir(root.path());
    let now = Utc::now();
    files.add(decision(root.path(), "a", Some("01SESSIONAAAAAAAAAAAAAAAAA"), now).keeping_at(now + chrono::Duration::seconds(4))).unwrap();
    files.discard_later("a", now + chrono::Duration::seconds(8)).unwrap();

    let again = now + chrono::Duration::seconds(10);
    let back = files.undo_discard("a", again).unwrap();

    assert!(!back.discarding() && back.counting(), "on its chip again, counting");
    assert_eq!(back.keep_at(), Some(again));
    let store = files.load();
    assert_eq!(store.ui_items().len(), 1, "in the lists again");
    assert!(store.next_discard(now).is_none(), "nothing left to delete");
    // Its time passing deletes nothing now; every file and the live transcript are there for Keep.
    assert!(files.discard_due(now + chrono::Duration::hours(1)).0.is_empty());
    assert!(root.path().join("recordings/a/recording.flac").exists());
    let upload = files.keep("a").unwrap();
    assert_eq!(upload.server_session_id.as_deref(), Some("01SESSIONAAAAAAAAAAAAAAAAA"));
    assert!(DiscardQueue::load(&files.discards).items.is_empty());
    // Undo again, or after the time: too late, said as an error.
    assert_eq!(files.undo_discard("a", again).unwrap_err().to_string(), pending::NOT_WAITING);
}

#[test]
fn discarding_a_recording_without_a_server_session_queues_no_server_delete() {
    let root = tempfile::tempdir().unwrap();
    let files = DecisionFiles::in_dir(root.path());
    files
        .add(decision(root.path(), "a", None, Utc::now()))
        .unwrap();

    files.discard("a").unwrap();

    assert!(DiscardQueue::load(&files.discards).items.is_empty());
}

#[test]
fn the_chip_keeps_only_recordings_whose_time_is_up_and_never_one_that_waits() {
    let root = tempfile::tempdir().unwrap();
    let files = DecisionFiles::in_dir(root.path());
    let now = Utc::now();
    files.add(decision(root.path(), "due", None, now - chrono::Duration::seconds(11)).keeping_at(now - chrono::Duration::seconds(1))).unwrap();
    files.add(decision(root.path(), "counting", None, now).keeping_at(now + chrono::Duration::seconds(10))).unwrap();
    files.add(decision(root.path(), "waiting", None, now - chrono::Duration::hours(2))).unwrap();

    assert_eq!(files.keep_due(now).unwrap(), vec!["due".to_string()], "only the chip whose time is over");
    assert_eq!(UploadQueue::load(&files.uploads).items.len(), 1);
    assert!(!UploadQueue::load(&files.uploads).items[0].transcribe, "no answer is \"Save audio\": uploaded, not transcribed");
    assert!(root.path().join("recordings/due/recording.flac").exists(), "kept audio stays until the server has it");

    let later = now + chrono::Duration::minutes(5);
    assert_eq!(files.keep_due(later).unwrap(), vec!["counting".to_string()]);
    assert_eq!(files.load().items.iter().map(|d| d.id.as_str()).collect::<Vec<_>>(), vec!["waiting"], "a waiting recording is never kept by itself");
    assert!(files.keep_due(later).unwrap().is_empty());
}

#[test]
fn a_declined_live_assist_start_s_recording_is_discarded_never_saved_or_uploaded() {
    use desktop_lib::screens::store::{ScreenKey, ScreenStore};
    let root = tempfile::tempdir().unwrap();
    let finished = finished_recording(root.path(), "declined");
    let store = ScreenStore::in_dir(root.path());
    store.add("ref-declined", &ScreenKey::from_bytes(&[7; 32]), b"webp", 1_000, 1920, 1080, "change", Utc::now()).unwrap();
    pending::discard_recording_files(root.path(), &finished.output.dir, "ref-declined");
    assert!(!finished.output.dir.exists(), "its audio is gone");
    assert_eq!(store.count("ref-declined"), 0, "its screens are gone");
    let files = DecisionFiles::in_dir(root.path());
    assert!(files.load().items.is_empty(), "nothing waits to be kept");
    assert!(UploadQueue::load(&files.uploads).items.is_empty(), "nothing is queued for upload");
}

#[test]
fn keep_transcribes_and_save_audio_uploads_without_a_transcript() {
    let root = tempfile::tempdir().unwrap();
    let files = DecisionFiles::in_dir(root.path());
    let now = Utc::now();
    files.add(decision(root.path(), "kept", None, now)).unwrap();
    files.add(decision(root.path(), "audio", None, now).keeping_at(now + chrono::Duration::seconds(12))).unwrap();

    assert!(files.keep("kept").unwrap().transcribe);
    let audio = files.keep_audio("audio").unwrap();
    assert!(!audio.transcribe);
    assert!(files.load().items.is_empty(), "both left the waiting list");
    let queued: Vec<(String, bool)> = UploadQueue::load(&files.uploads).items.iter().map(|i| (i.client_ref.clone(), i.transcribe)).collect();
    assert_eq!(queued, vec![("ref-kept".to_string(), true), ("ref-audio".to_string(), false)], "the choice survives a restart");
    assert!(files.keep_audio("audio").is_err(), "a recording that no longer waits");
}

#[test]
fn a_chip_shown_before_the_upgrade_keeps_its_promise_and_is_transcribed() {
    let root = tempfile::tempdir().unwrap();
    let files = DecisionFiles::in_dir(root.path());
    let now = Utc::now();
    let due = now - chrono::Duration::seconds(1);
    files.add(decision(root.path(), "old", None, now).keeping_at(due)).unwrap();
    files.add(decision(root.path(), "new", None, now).keeping_at(due)).unwrap();
    // pending.json as an older version wrote it: its chip had no Save audio mark and promised Keep.
    let path = root.path().join("pending.json");
    let mut file: serde_json::Value = serde_json::from_str(&std::fs::read_to_string(&path).unwrap()).unwrap();
    for item in file["items"].as_array_mut().unwrap() {
        if item["id"] == "old" {
            item.as_object_mut().unwrap().remove("saves_audio_when_due").expect("the mark is written");
        }
    }
    std::fs::write(&path, file.to_string()).unwrap();

    let mut kept = files.keep_due(now).unwrap();
    kept.sort();
    assert_eq!(kept, ["new", "old"]);
    let queued: Vec<(String, bool)> = UploadQueue::load(&files.uploads).items.iter().map(|i| (i.client_ref.clone(), i.transcribe)).collect();
    assert!(queued.contains(&("ref-old".to_string(), true)), "kept and transcribed, as its chip said: {queued:?}");
    assert!(queued.contains(&("ref-new".to_string(), false)), "this version's chip saves the audio only: {queued:?}");
}

#[test]
fn a_chip_back_from_undo_saves_the_audio_when_due() {
    let root = tempfile::tempdir().unwrap();
    let files = DecisionFiles::in_dir(root.path());
    let now = Utc::now();
    files.add(decision(root.path(), "a", None, now)).unwrap();
    files.discard_later("a", now + chrono::Duration::seconds(6)).unwrap();
    files.undo_discard("a", now - chrono::Duration::seconds(1)).unwrap();
    assert_eq!(files.keep_due(now).unwrap(), ["a"]);
    assert!(!UploadQueue::load(&files.uploads).items[0].transcribe);
}

#[test]
fn an_upload_queued_before_save_audio_existed_is_still_transcribed() {
    let root = tempfile::tempdir().unwrap();
    let files = DecisionFiles::in_dir(root.path());
    files.add(decision(root.path(), "old", None, Utc::now())).unwrap();
    files.keep("old").unwrap();
    let mut file: serde_json::Value = serde_json::from_str(&std::fs::read_to_string(&files.uploads).unwrap()).unwrap();
    file["items"][0].as_object_mut().unwrap().remove("transcribe").expect("the field is written");
    std::fs::write(&files.uploads, file.to_string()).unwrap();
    assert!(UploadQueue::load(&files.uploads).items[0].transcribe);
}

#[test]
fn every_chip_still_counting_after_a_restart_gets_its_wake_up_in_turn() {
    let root = tempfile::tempdir().unwrap();
    let files = DecisionFiles::in_dir(root.path());
    let now = Utc::now();
    let secs = chrono::Duration::seconds;
    files.add(decision(root.path(), "first", None, now).keeping_at(now + secs(3))).unwrap();
    files.add(decision(root.path(), "second", None, now).keeping_at(now + secs(8))).unwrap();
    files.add(decision(root.path(), "waiting", None, now)).unwrap();
    assert_eq!(files.load().next_wake(now), Some(now + secs(3)), "the start sets one timer, for the earliest chip");

    assert_eq!(files.keep_due(now + secs(3)).unwrap(), vec!["first".to_string()]);
    assert_eq!(files.load().next_wake(now + secs(3)), Some(now + secs(8)), "the next chip gets its wake-up from the one that kept");
    assert!(files.keep_due(now + secs(5)).unwrap().is_empty(), "a timer that woke early (the clock set back) keeps nothing");
    assert_eq!(files.load().next_wake(now + secs(5)), Some(now + secs(8)), "and it sets the same time again");

    assert_eq!(files.keep_due(now + secs(8)).unwrap(), vec!["second".to_string()]);
    assert_eq!(files.load().next_wake(now + secs(8)), None, "a recording that waits for the person needs no timer");
}

#[test]
fn a_chip_time_that_has_passed_is_tried_again_in_a_minute_not_straight_away() {
    let root = tempfile::tempdir().unwrap();
    let files = DecisionFiles::in_dir(root.path());
    let now = Utc::now();
    // Kept would have removed it: still here at its time means keeping it failed just now (a full disk, say).
    files.add(decision(root.path(), "stuck", None, now).keeping_at(now - chrono::Duration::seconds(1))).unwrap();
    assert_eq!(files.load().next_wake(now), Some(now + chrono::Duration::minutes(1)));
}

#[test]
fn opening_a_recording_from_its_chip_stops_the_countdown() {
    let root = tempfile::tempdir().unwrap();
    let files = DecisionFiles::in_dir(root.path());
    let now = Utc::now();
    files.add(decision(root.path(), "a", None, now).keeping_at(now + chrono::Duration::seconds(10))).unwrap();

    let opened = files.stop_countdown("a").unwrap();
    assert!(opened.waits());
    assert_eq!(opened.item().deadline_at, None);
    assert!(files.keep_due(now + chrono::Duration::minutes(1)).unwrap().is_empty(), "renaming it in the main window takes as long as it takes");
    assert_eq!(files.load().waiting(), 1);
    assert_eq!(files.stop_countdown("gone").unwrap_err().to_string(), pending::NOT_WAITING);
}

#[test]
fn a_waiting_recording_carries_the_callers_name_for_its_title() {
    let root = tempfile::tempdir().unwrap();
    let finished = finished_recording(root.path(), "c");
    let input = |name: Option<&str>| DecisionInput {
        auto: true,
        client_name: None,
        live_segments: vec![],
        live_provider: None,
        server_session_id: None,
        created_at: Utc::now(),
        language: None,
        language_marks: vec![],
        caller: Some(desktop_lib::net::models::CallerDto { name: name.map(str::to_string), number: None, source: Some("window_title".into()) }),
    };
    assert_eq!(PendingDecision::from_recording(&finished, input(Some(" Omar Khan "))).item().caller_name.as_deref(), Some("Omar Khan"));
    assert_eq!(PendingDecision::from_recording(&finished, input(Some("  "))).item().caller_name, None);
    assert_eq!(PendingDecision::from_recording(&finished, input(None)).item().caller_name, None);
}

#[test]
fn the_pill_names_the_caller_of_the_running_recording_only() {
    use desktop_lib::net::models::CallerDto;
    let noted = |name: &str| ("ref-now".to_string(), CallerDto { name: Some(name.into()), number: None, source: Some("window_title".into()) });
    assert_eq!(pending::running_caller_name(Some(&noted(" Omar ")), "ref-now").as_deref(), Some("Omar"));
    assert_eq!(pending::running_caller_name(Some(&noted("Omar")), "ref-next"), None, "noted for another recording");
    assert_eq!(pending::running_caller_name(Some(&noted(" ")), "ref-now"), None);
    assert_eq!(pending::running_caller_name(None, "ref-now"), None, "not known yet");
}

#[test]
fn a_waiting_call_counts_its_own_screens() {
    use desktop_lib::screens::store::{ScreenKey, ScreenStore};
    let root = tempfile::tempdir().unwrap();
    let files = DecisionFiles::in_dir(root.path());
    files.add(decision(root.path(), "a", None, Utc::now())).unwrap();
    files.add(decision(root.path(), "b", None, Utc::now() - chrono::Duration::minutes(1))).unwrap();
    let store = ScreenStore::in_dir(root.path());
    let key = ScreenKey::from_bytes(&[7; 32]);
    for at in [1_000, 2_000, 3_000] {
        store.add("ref-a", &key, format!("webp {at}").as_bytes(), at, 1920, 1080, "change", Utc::now()).unwrap();
    }
    let loaded = files.load();
    assert_eq!(loaded.find("a").unwrap().item_with_screens(&store).screens, 3, "\"3 screens\" on the after-call card");
    let listed: Vec<(String, usize)> = loaded.ui_items_with_screens(&store).into_iter().map(|i| (i.id, i.screens)).collect();
    assert_eq!(listed, vec![("a".to_string(), 3), ("b".to_string(), 0)]);
    let json = serde_json::to_value(loaded.find("a").unwrap().item_with_screens(&store)).unwrap();
    assert_eq!(json["screens"], 3);
}

#[test]
fn rename_trims_and_limits_the_title() {
    let root = tempfile::tempdir().unwrap();
    let files = DecisionFiles::in_dir(root.path());
    files
        .add(decision(root.path(), "a", None, Utc::now()))
        .unwrap();

    assert_eq!(
        files
            .rename("a", "  Budget call  ")
            .unwrap()
            .title
            .as_deref(),
        Some("Budget call")
    );
    assert_eq!(files.rename("a", "   ").unwrap().title, None);
    assert_eq!(
        files.rename("a", &"x".repeat(201)).unwrap_err().to_string(),
        pending::TITLE_TOO_LONG
    );
}

// ---- tray --------------------------------------------------------------------------------------------------------

#[test]
fn a_recording_always_wins_the_tray_then_attention_then_uploading() {
    assert_eq!(
        tray_state(true, false, 3, 2, true, true),
        TrayState::Recording
    );
    assert_eq!(tray_state(true, true, 3, 2, true, true), TrayState::Paused);
    assert_eq!(
        tray_state(false, false, 1, 0, false, true),
        TrayState::Attention
    );
    assert_eq!(
        tray_state(false, false, 0, 1, false, true),
        TrayState::Attention
    );
    assert_eq!(
        tray_state(false, false, 0, 0, true, true),
        TrayState::Attention
    );
    assert_eq!(
        tray_state(false, false, 0, 0, false, true),
        TrayState::Uploading
    );
    assert_eq!(
        tray_state(false, false, 0, 0, false, false),
        TrayState::Idle
    );
}

// ---- streaming usage ---------------------------------------------------------------------------------------------

#[test]
fn the_billed_streaming_seconds_come_from_assemblyai_termination() {
    assert_eq!(
        assemblyai_session_seconds(
            r#"{"type":"Termination","audio_duration_seconds":40,"session_duration_seconds":42}"#
        ),
        Some(42)
    );
    assert_eq!(
        assemblyai_session_seconds(r#"{"type":"Termination","session_duration_seconds":41.2}"#),
        Some(42)
    );
    assert_eq!(
        assemblyai_session_seconds(r#"{"type":"Turn","session_duration_seconds":42}"#),
        None
    );
    assert_eq!(assemblyai_session_seconds("not json"), None);
}

// ---- skipped calls (v1.5) ----------------------------------------------------------------------------------------

#[test]
fn a_skipped_call_waits_a_day_and_is_restored_once_into_the_upload_queue() {
    let root = tempfile::tempdir().unwrap();
    let files = SkippedFiles::in_dir(root.path());
    let now = Utc::now();
    let mut short = decision(root.path(), "short", Some("01SESSIONAAAAAAAAAAAAAAAAA"), now);
    assert_eq!(short.take_server_session().as_deref(), Some("01SESSIONAAAAAAAAAAAAAAAAA"), "its captions session is deleted at once");
    files.add(SkippedCall { decision: short, reason: SkipReason::TooShort, skipped_at: now }).unwrap();
    let an_hour_ago = now - chrono::Duration::hours(1);
    files.add(SkippedCall { decision: decision(root.path(), "silent", None, an_hour_ago), reason: SkipReason::NoSpeech, skipped_at: an_hour_ago }).unwrap();

    let items = files.load().ui_items(now);
    assert_eq!(items.iter().map(|i| i.id.as_str()).collect::<Vec<_>>(), ["short", "silent"], "newest first");
    assert_eq!(items[0].reason, SkipReason::TooShort);
    assert_eq!(items[0].expires_at, (now + chrono::Duration::hours(24)).to_rfc3339());
    assert_eq!((items[0].source_app.as_str(), items[0].duration_ms), ("whatsapp", 90_000));
    assert!(UploadQueue::load(&files.uploads).items.is_empty(), "nothing is uploaded");
    assert!(DecisionFiles::in_dir(root.path()).load().items.is_empty(), "nothing waits for a decision");

    let upload = files.restore("short", now).unwrap();
    assert_eq!(upload.server_session(), None, "a restored call opens a new session");
    assert_eq!(UploadQueue::load(&files.uploads).items.len(), 1);
    assert!(root.path().join("recordings/short/recording.flac").exists(), "its audio stays for the upload");
    assert_eq!(files.restore("short", now).unwrap_err().to_string(), skipped::NOT_SKIPPED);
    assert_eq!(UploadQueue::load(&files.uploads).items.len(), 1, "restored once");
    assert_eq!(files.load().items.iter().map(|c| c.decision.id.as_str()).collect::<Vec<_>>(), ["silent"]);
}

#[test]
fn skipped_calls_are_deleted_with_their_files_after_24_hours() {
    let root = tempfile::tempdir().unwrap();
    let files = SkippedFiles::in_dir(root.path());
    let now = Utc::now();
    let yesterday = now - chrono::Duration::hours(25);
    files.add(SkippedCall { decision: decision(root.path(), "old", None, yesterday), reason: SkipReason::TooShort, skipped_at: yesterday }).unwrap();
    files.add(SkippedCall { decision: decision(root.path(), "new", None, now), reason: SkipReason::NoSpeech, skipped_at: now }).unwrap();

    assert_eq!(files.load().ui_items(now).iter().map(|i| i.id.as_str()).collect::<Vec<_>>(), ["new"], "an expired call is no longer offered");
    assert_eq!(files.restore("old", now).unwrap_err().to_string(), skipped::NOT_SKIPPED, "nor restored");
    assert_eq!(files.purge(now).unwrap(), vec!["old".to_string()]);
    assert!(!root.path().join("recordings/old").exists(), "its files are gone");
    assert!(root.path().join("recordings/new/recording.flac").exists());
    assert!(files.purge(now).unwrap().is_empty());
    assert_eq!(files.purge(now + chrono::Duration::hours(24)).unwrap(), vec!["new".to_string()]);
    assert!(files.load().items.is_empty());
    assert!(UploadQueue::load(&files.uploads).items.is_empty(), "nothing was ever uploaded");
}
