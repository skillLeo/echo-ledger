//! Recording lifecycle (A2, A3.3, A3.4, A5.4, v1.5 1.2): pause without gaps, Keep / Discard decisions that survive a
//! restart, "After a call" and its chip, the tray state and the streaming usage read from AssemblyAI.

use std::path::Path;
use std::time::{Duration, Instant};

use chrono::{TimeZone, Utc};
use desktop_lib::audio::clock::RecordingClock;
use desktop_lib::audio::recorder::Recorder;
use desktop_lib::audio::recorder::RecordingOutput;
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
    assert_eq!(pending::after_call(AfterCall::Ask, RecordingMode::Call), AfterCall::Ask);
    assert_eq!(pending::after_call(AfterCall::Keep, RecordingMode::Call), AfterCall::Keep);
    for note in [RecordingMode::VoiceNote, RecordingMode::MicNote] {
        assert_eq!(pending::after_call(AfterCall::Ask, note), AfterCall::Keep, "a voice note was stopped on purpose");
    }
    assert_eq!(pending::CHIP_SECONDS, 10, "the chip keeps the recording after 10 s");
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
    assert!(root.path().join("recordings/due/recording.flac").exists(), "kept audio stays until the server has it");

    let later = now + chrono::Duration::minutes(5);
    assert_eq!(files.keep_due(later).unwrap(), vec!["counting".to_string()]);
    assert_eq!(files.load().items.iter().map(|d| d.id.as_str()).collect::<Vec<_>>(), vec!["waiting"], "a waiting recording is never kept by itself");
    assert!(files.keep_due(later).unwrap().is_empty());
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
