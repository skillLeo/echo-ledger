//! Capture and control (plan B1-B5): per-app auto-record switches, bookmarks and pause gaps, caption overlay
//! sizes, the microphone choice and capture health. Everything here is pure.

use std::collections::HashMap;

use desktop_lib::audio::health::{
    FAR_SIDE_SILENT_MS, FarSideMonitor, FarSidePayload, HEARD_LEVEL, consent_from, peak_level, rms, stereo_wav, summarize,
};
use desktop_lib::audio::notes::{DEFAULT_TAGS, MAX_MARKERS, MAX_PAUSES, RecordingNotes, clean, tag_presets};
use desktop_lib::audio::platform::{InputDevice, MicBus, bus_of_instance_id, choose_mic, is_bluetooth_mic, mic_in_use, sort_devices};
use desktop_lib::detect::classify::SourceApp;
use desktop_lib::detect::decide::{IgnoreReason, TreeAudio, Verdict, apply_app_switches, decide};
use desktop_lib::detect::explain;
use desktop_lib::net::models::{CreateSessionRequest, FinishSessionRequest, MarkerDto, PauseDto, ServerConfig};
use desktop_lib::overlay::geometry::{caption_overlay_size, caption_overlay_size_for};
use desktop_lib::settings::{CaptionSize, CaptionWidth};
use serde_json::json;

// ---- B1 auto-record switches ---------------------------------------------------------------------------------

fn zoom_call() -> TreeAudio {
    TreeAudio {
        root_pid: 77,
        name: "Zoom.exe".into(),
        pfn: None,
        capture_active: true,
        render_active: true,
        titles: vec![],
        urls: vec![],
    }
}

#[test]
fn a_call_app_switched_off_is_ignored_and_explained() {
    let off = HashMap::from([("zoom".to_string(), false)]);
    let verdict = apply_app_switches(decide(&zoom_call(), &[], false, true), &off);

    assert_eq!(verdict, Verdict::Ignore(IgnoreReason::AppOff));
    let line = explain(&zoom_call(), &verdict, &[], None);
    assert_eq!((line.decision.as_str(), line.reason.as_deref()), ("ignored", Some("app_off")));
    assert_eq!(line.app.as_deref(), Some("zoom"), "still identified, so the explainer can say which switch");
}

#[test]
fn apps_switched_on_or_missing_still_record() {
    let verdict = decide(&zoom_call(), &[], false, true);
    assert_eq!(apply_app_switches(verdict.clone(), &HashMap::new()), Verdict::Record(SourceApp::Zoom));
    assert_eq!(apply_app_switches(verdict.clone(), &HashMap::from([("zoom".to_string(), true)])), Verdict::Record(SourceApp::Zoom));
    assert_eq!(apply_app_switches(verdict, &HashMap::from([("whatsapp".to_string(), false)])), Verdict::Record(SourceApp::Zoom));
}

#[test]
fn switches_never_turn_an_ignored_tree_into_a_recording() {
    let mut dictation = zoom_call();
    dictation.render_active = false;
    let verdict = decide(&dictation, &[], false, true);
    assert_eq!(apply_app_switches(verdict.clone(), &HashMap::from([("zoom".to_string(), true)])), verdict);
    assert_eq!(IgnoreReason::AppOff.as_str(), "app_off");
}

// ---- B3 bookmarks ----------------------------------------------------------------------------------------------

#[test]
fn bookmarks_are_cleaned_and_sent_in_time_order() {
    let mut notes = RecordingNotes::default();
    notes.mark(9_000, Some("  Budget "), Some("max\n\n 5k   per month"));
    notes.mark(2_000, Some("   "), None);

    let (markers, pauses) = notes.finish();
    assert_eq!(
        markers,
        vec![
            MarkerDto { at_ms: 2_000, tag: None, note: None },
            MarkerDto { at_ms: 9_000, tag: Some("Budget".into()), note: Some("max 5k per month".into()) },
        ]
    );
    assert!(pauses.is_empty());
}

#[test]
fn tags_and_notes_are_cut_to_the_server_limits() {
    assert_eq!(clean(Some(&"t".repeat(45)), 40).unwrap().chars().count(), 40);
    assert_eq!(clean(Some(&"é".repeat(250)), 200).unwrap().chars().count(), 200, "characters, not bytes");
    assert_eq!(clean(Some("word "), 4).as_deref(), Some("word"));
    assert_eq!(clean(None, 40), None);
}

#[test]
fn the_last_bookmark_can_be_tagged_or_undone() {
    let mut notes = RecordingNotes::default();
    assert!(!notes.tag_last(Some("Deadline"), None), "nothing to tag yet");
    notes.mark(1_000, None, None);
    assert!(notes.tag_last(Some("Deadline"), Some("Friday")));
    assert_eq!(notes.markers[0].tag.as_deref(), Some("Deadline"));
    assert!(notes.tag_last(Some(""), None));
    assert_eq!(notes.markers[0].tag, None, "an empty tag clears it");
    assert_eq!(notes.markers[0].note.as_deref(), Some("Friday"), "the note stays");
    assert_eq!(notes.undo_mark().map(|m| m.at_ms), Some(1_000));
    assert_eq!(notes.undo_mark(), None);
}

#[test]
fn a_recording_holds_at_most_five_hundred_bookmarks() {
    let mut notes = RecordingNotes::default();
    for i in 0..MAX_MARKERS {
        assert!(notes.mark(i as u64, None, None).is_some());
    }
    assert_eq!(notes.mark(9_999, Some("Budget"), None), None);
    assert_eq!(notes.markers.len(), MAX_MARKERS);
}

#[test]
fn tag_presets_come_from_the_server_or_the_defaults() {
    let server = vec![" Scope ".to_string(), "Scope".into(), "".into(), "Risk".into()];
    assert_eq!(tag_presets(Some(&server)), vec!["Scope", "Risk"]);
    assert_eq!(tag_presets(Some(&[])), DEFAULT_TAGS.to_vec());
    assert_eq!(tag_presets(None), DEFAULT_TAGS.to_vec());
    let many: Vec<String> = (1..=20).map(|i| format!("Tag {i}")).collect();
    assert_eq!(tag_presets(Some(&many)).len(), 12);
}

#[test]
fn the_config_carries_the_presets_and_a_broken_block_is_ignored() {
    let mut config = json!({"device": {"id": "d", "name": "n", "platform": "windows", "source_device": "computer"},
        "server_time": "", "retention_days": 30, "upload": {"chunk_size_bytes": 1, "max_recording_bytes": 1}});
    config["markers"] = json!({"tags": ["Budget", "Scope"]});
    let parsed: ServerConfig = serde_json::from_value(config.clone()).unwrap();
    assert_eq!(parsed.markers.unwrap().tags, vec!["Budget", "Scope"]);
    config["markers"] = json!("nonsense");
    let parsed: ServerConfig = serde_json::from_value(config).unwrap();
    assert_eq!(parsed.markers, None);
}

// ---- B2 pause gaps ---------------------------------------------------------------------------------------------

#[test]
fn each_resume_leaves_a_gap_at_the_recorded_time_of_the_pause() {
    let mut notes = RecordingNotes::default();
    notes.paused(60_000);
    notes.paused(61_000); // pausing twice keeps the first point
    notes.resumed(125_000);
    notes.resumed(5_000); // resuming without a pause changes nothing
    notes.paused(90_000);
    notes.resumed(0); // a zero-length pause is not a gap

    assert_eq!(notes.pauses, vec![PauseDto { at_ms: 60_000, paused_ms: 125_000 }]);
}

#[test]
fn a_pause_still_open_at_the_end_is_not_sent() {
    let mut notes = RecordingNotes::default();
    notes.paused(10_000);
    let (_, pauses) = notes.finish();
    assert!(pauses.is_empty());
}

#[test]
fn past_two_hundred_pauses_the_last_gap_keeps_the_total() {
    let mut notes = RecordingNotes::default();
    for i in 0..(MAX_PAUSES as u64 + 3) {
        notes.paused(i * 1_000);
        notes.resumed(10);
    }
    assert_eq!(notes.pauses.len(), MAX_PAUSES);
    assert_eq!(notes.pauses.iter().map(|p| p.paused_ms).sum::<u64>(), (MAX_PAUSES as u64 + 3) * 10);
}

#[test]
fn markers_and_pauses_are_sent_only_when_there_are_some() {
    let mut create = CreateSessionRequest {
        transcribe: true,
        client_ref: "r".into(),
        source_app: "zoom".into(),
        client_id: None,
        title: None,
        started_at: "2026-10-25T09:00:00Z".into(),
        ended_at: None,
        duration_ms: Some(1_000),
        language: None,
        artifacts: vec![],
        markers: vec![],
        pauses: vec![],
        language_marks: vec![],
        caller: None,
    };
    let body = serde_json::to_value(&create).unwrap();
    assert!(body.get("markers").is_none() && body.get("pauses").is_none(), "a v1 server never sees the keys");

    create.markers = vec![MarkerDto { at_ms: 5, tag: Some("Budget".into()), note: None }];
    create.pauses = vec![PauseDto { at_ms: 7, paused_ms: 3 }];
    let body = serde_json::to_value(&create).unwrap();
    assert_eq!(body["markers"], json!([{"at_ms": 5, "tag": "Budget", "note": null}]));
    assert_eq!(body["pauses"], json!([{"at_ms": 7, "paused_ms": 3}]));

    let finish = FinishSessionRequest {
        transcribe: true,
        ended_at: "2026-10-25T09:10:00Z".into(),
        duration_ms: 600_000,
        title: None,
        client_id: None,
        language: None,
        artifacts: vec![],
        markers: create.markers.clone(),
        pauses: vec![],
        language_marks: vec![],
        caller: None,
    };
    let body = serde_json::to_value(&finish).unwrap();
    assert_eq!(body["markers"][0]["at_ms"], 5);
    assert!(body.get("pauses").is_none());
}

// ---- B4 caption sizes ------------------------------------------------------------------------------------------

#[test]
fn the_default_style_keeps_the_v1_overlay_sizes() {
    for lines in [2, 3, 5, 4] {
        assert_eq!(caption_overlay_size_for(lines, CaptionSize::Medium, CaptionWidth::Normal), caption_overlay_size(lines));
    }
    assert_eq!(caption_overlay_size(2), (460.0, 118.0));
    assert_eq!(caption_overlay_size(3), (460.0, 146.0));
    assert_eq!(caption_overlay_size(5), (460.0, 202.0));
}

#[test]
fn width_and_text_size_change_the_overlay() {
    assert_eq!(caption_overlay_size_for(3, CaptionSize::Medium, CaptionWidth::Narrow).0, 380.0);
    assert_eq!(caption_overlay_size_for(3, CaptionSize::Medium, CaptionWidth::Wide).0, 640.0);
    assert_eq!(caption_overlay_size_for(3, CaptionSize::Large, CaptionWidth::Normal).1, 167.0);
    assert_eq!(caption_overlay_size_for(3, CaptionSize::Small, CaptionWidth::Normal).1, 133.0);
    assert!(caption_overlay_size_for(5, CaptionSize::Large, CaptionWidth::Wide).1 > caption_overlay_size(5).1);
}

// ---- B5 capture health -----------------------------------------------------------------------------------------

fn device(id: &str, name: &str, default: bool) -> InputDevice {
    InputDevice { id: id.into(), name: name.into(), is_default: default, bluetooth: false }
}

#[test]
fn the_chosen_microphone_is_used_only_while_connected() {
    let devices = vec![device("a", "Laptop mic", true), device("b", "USB headset", false)];
    assert_eq!(choose_mic(&devices, Some("b")).as_deref(), Some("b"));
    assert_eq!(choose_mic(&devices, Some("gone")), None, "unplugged → the system default");
    assert_eq!(choose_mic(&devices, None), None);
}

#[test]
fn the_default_microphone_is_listed_first() {
    let mut devices = vec![device("c", "zoom H1", false), device("b", "Array", false), device("a", "Headset", true)];
    sort_devices(&mut devices);
    assert_eq!(devices.iter().map(|d| d.id.as_str()).collect::<Vec<_>>(), ["a", "b", "c"]);
    assert_eq!(serde_json::to_value(&devices[0]).unwrap(), json!({"id": "a", "name": "Headset", "isDefault": true, "bluetooth": false}));
}

// ---- v1.5 Bluetooth microphones -----------------------------------------------------------------------------------

#[test]
fn a_bluetooth_microphone_is_told_by_its_device_instance_id() {
    for id in [
        r"{1}.BTHHFENUM\BthHFPAudio\8&2A7E4A5B&0&97",
        r"{1}.BTHENUM\{0000111e-0000-1000-8000-00805f9b34fb}_VID&0001004c_PID&2014\8&3B1&0&D4B5",
        r"{1}.BTHLEDevice\{0000184e-0000-1000-8000-00805f9b34fb}_Dev_VID&02005c\9&1",
        r"{1}.bthhfenum\bthhfpaudio\8&1",
    ] {
        assert_eq!(bus_of_instance_id(Some(id)), MicBus::Bluetooth, "{id}");
        assert!(is_bluetooth_mic(bus_of_instance_id(Some(id)), "Microphone"), "{id}");
    }
    for id in [r"{1}.USB\VID_046D&PID_0A44&MI_00\7&1A2B&0&0000", r"{1}.HDAUDIO\FUNC_01&VEN_10EC&DEV_0257&SUBSYS_17AA\4&1", r"{1}.INTELAUDIO\FUNC_01&VEN_8086\5&2"] {
        assert_eq!(bus_of_instance_id(Some(id)), MicBus::Other, "{id}");
    }
    assert_eq!(bus_of_instance_id(None), MicBus::Unknown);
    assert_eq!(bus_of_instance_id(Some("  ")), MicBus::Unknown);
}

#[test]
fn a_bluetooth_name_counts_whatever_the_bus_and_headset_only_when_the_system_does_not_say() {
    for name in ["Headset (WH-1000XM4 Hands-Free AG Audio)", "AirPods Pro", "Galaxy Buds2 Pro", "Bluetooth Headset", "Jabra Handsfree Link", "Headset Microphone (Jabra Evolve2 65)"] {
        assert!(is_bluetooth_mic(MicBus::Unknown, name), "{name}");
    }
    for name in ["Microphone Array (Realtek(R) Audio)", "MacBook Pro Microphone", "USB Audio Device", "Yeti Stereo Microphone"] {
        assert!(!is_bluetooth_mic(MicBus::Unknown, name), "{name}");
        assert!(!is_bluetooth_mic(MicBus::Other, name), "{name}");
    }
    // A Bluetooth headset behind the audio chip's Bluetooth offload reports the chip's bus ({1}.INTELAUDIO\…).
    for name in ["Headset (WH-1000XM4 Hands-Free AG Audio)", "AirPods Pro", "Galaxy Buds2 Pro", "Microphone (Intel Smart Sound Technology for Bluetooth Audio)"] {
        assert!(is_bluetooth_mic(MicBus::Other, name), "{name}");
    }
    assert!(!is_bluetooth_mic(MicBus::Other, "Headset Microphone (Realtek(R) Audio)"), "a wired headset on the audio jack");
    assert!(is_bluetooth_mic(MicBus::Bluetooth, "Microphone"));
}

#[test]
fn the_microphone_in_use_is_the_chosen_one_while_connected_else_the_default() {
    let devices = vec![device("a", "Laptop mic", true), InputDevice { bluetooth: true, ..device("b", "AirPods Pro", false) }];
    assert_eq!(mic_in_use(&devices, Some("b")).map(|d| (d.id.as_str(), d.bluetooth)), Some(("b", true)));
    assert_eq!(mic_in_use(&devices, Some("gone")).map(|d| d.id.as_str()), Some("a"), "unplugged: the system default");
    assert_eq!(mic_in_use(&devices, None).map(|d| d.id.as_str()), Some("a"));
    assert_eq!(mic_in_use(&[], None), None);
    assert_eq!(mic_in_use(&[device("x", "Array", false)], None), None, "no default is known");
}

#[test]
fn the_far_side_warning_fires_once_per_silent_stretch() {
    let mut monitor = FarSideMonitor::default();
    assert_eq!(monitor.tick(Some(29_999), true), None);
    assert_eq!(monitor.tick(Some(FAR_SIDE_SILENT_MS), true), Some(FarSidePayload { silent: true, seconds: 30 }));
    assert_eq!(monitor.tick(Some(45_000), true), None, "warned once");
    assert_eq!(monitor.tick(Some(200), true), Some(FarSidePayload { silent: false, seconds: 0 }), "heard again");
    assert_eq!(monitor.tick(Some(200), true), None);
}

#[test]
fn the_far_side_warning_clears_on_pause_or_end_and_respects_the_setting() {
    let mut monitor = FarSideMonitor::default();
    assert_eq!(monitor.tick(Some(40_000), false), None, "switched off");
    monitor.tick(Some(40_000), true);
    assert_eq!(monitor.tick(None, true), Some(FarSidePayload { silent: false, seconds: 0 }));
    monitor.tick(Some(40_000), true);
    assert_eq!(monitor.tick(Some(41_000), false), Some(FarSidePayload { silent: false, seconds: 0 }), "turned off while warned");
}

#[test]
fn levels_and_peaks() {
    assert_eq!(rms(&[]), 0.0);
    assert!((rms(&[0.5, -0.5]) - 0.5).abs() < 1e-6);
    let mut samples = vec![0.0_f32; 16_000];
    samples[8_000..9_600].fill(0.25); // one loud 100 ms window
    assert!((peak_level(&samples) - 0.25).abs() < 1e-6);
    assert!(rms(&[0.001; 160]) < HEARD_LEVEL, "room noise is not the other side");
    assert!(rms(&[0.01; 160]) >= HEARD_LEVEL, "quiet speech is");
}

#[test]
fn the_test_recording_says_which_side_was_heard_and_plays_back() {
    let mic = vec![0.2_f32; 16_000];
    let system = vec![0.0_f32; 8_000];
    let result = summarize(10, &mic, &system).unwrap();
    assert!(result.mic_heard && !result.system_heard);
    assert_eq!(result.seconds, 10);

    let wav = stereo_wav(&mic, &system).unwrap();
    let reader = hound::WavReader::new(std::io::Cursor::new(wav)).unwrap();
    assert_eq!((reader.spec().channels, reader.spec().sample_rate), (2, 16_000));
    assert_eq!(reader.len(), 32_000, "the shorter side is padded");
    let json = serde_json::to_value(&result).unwrap();
    assert!(json["wavBase64"].as_str().unwrap().starts_with("UklGR"), "base64 of RIFF");
}

#[test]
fn microphone_consent_any_deny_blocks() {
    let allow = Some("Allow".to_string());
    let deny = Some("Deny".to_string());
    assert_eq!(consent_from(&[allow.clone(), allow.clone(), allow.clone()]), "allowed");
    assert_eq!(consent_from(&[allow.clone(), None, deny]), "denied");
    assert_eq!(consent_from(&[None, None, None]), "unknown");
    assert_eq!(consent_from(&[None, allow, None]), "allowed");
}

// ---- The other side of a call, checked within 3 s (2.0.6) --------------------------------------------------------

mod system_side {
    use desktop_lib::audio::RecordingMode;
    use desktop_lib::audio::health::{
        DIGITAL_SILENCE, FAR_SIDE_SILENT_MS, FarSideMonitor, PLAYING_EVERY, PlayingCache, SYSTEM_CHECK_AFTER_MS, SystemProblem, SystemSide, SystemVerdict, SystemWatch,
        far_side_enabled, has_signal, system_side_problem,
    };
    use std::time::{Duration, Instant};
    use desktop_lib::audio::platform::{Streams, open_keeping_mic};
    use desktop_lib::permissions::{Access, Kind, OtherSide, Permissions, SYSTEM_NOT_RECORDED, SYSTEM_NOT_RECORDED_REOPEN, missing_with};

    const CALL: Streams = Streams { mic: true, system: true };
    const ON: Permissions = Permissions { microphone: Access::Allowed, system_audio: Access::Allowed, screen: Access::Allowed };
    /// 16 kHz: frames per second of the other side.
    const PER_S: u64 = 16_000;

    /// An allowed tap with nothing to hear: zero-valued frames, for `ms`.
    fn zeros(ms: u64, mic_heard: bool) -> SystemSide {
        SystemSide { running_ms: ms, frames: ms * PER_S / 1_000, mic_heard, ..SystemSide::default() }
    }
    /// A tap macOS does not let through: buffers with no data, no frames.
    fn empty(ms: u64) -> SystemSide {
        SystemSide { running_ms: ms, empty_buffers: ms / 10, mic_heard: true, ..SystemSide::default() }
    }
    fn heard(ms: u64) -> SystemSide {
        SystemSide { signal: true, ..zeros(ms, true) }
    }

    #[test]
    fn you_speak_first_and_nothing_plays_no_warning() {
        // You speak, the other person is muted or not there yet, nothing plays: zeros, never this warning, however long.
        for ms in [3_000, 10_000, 30_000, 120_000] {
            assert_eq!(system_side_problem(&zeros(ms, true)), None, "{ms} ms");
        }
        let mut watch = SystemWatch::default();
        for ms in (0..=60_000).step_by(1_000) {
            assert_eq!(watch.look(&zeros(ms, true)), SystemVerdict::Same, "{ms} ms");
        }
        assert_eq!(watch.warning(), None);
    }

    /// A long silence is the one 30-second "not hearing the other side" warning: under the person's setting, and on a
    /// Mac only while another app plays sound (a tap with nothing to hear gives zeros).
    #[test]
    fn a_long_silence_is_the_far_side_warning_under_its_setting() {
        let asked = std::cell::Cell::new(0);
        let playing = |answer: bool| {
            asked.set(asked.get() + 1);
            answer
        };
        // Under 30 s the process list is not even read.
        assert!(far_side_enabled(true, Some(FAR_SIDE_SILENT_MS - 1), true, || playing(false)));
        assert!(far_side_enabled(true, None, true, || playing(false)));
        assert_eq!(asked.get(), 0);
        // 30 s of silence on a Mac: only while another app plays sound.
        assert!(far_side_enabled(true, Some(FAR_SIDE_SILENT_MS), true, || playing(true)));
        assert!(!far_side_enabled(true, Some(FAR_SIDE_SILENT_MS), true, || playing(false)));
        assert_eq!(asked.get(), 2);
        // The person's setting off: never, and nothing is read.
        assert!(!far_side_enabled(false, Some(120_000), true, || playing(true)));
        assert_eq!(asked.get(), 2);
        // Windows' loopback delivers nothing while nothing plays: as before.
        assert!(far_side_enabled(true, Some(120_000), false, || playing(false)));
        assert_eq!(asked.get(), 2);
        // Through the monitor: said once at 30 s while another app plays, cleared when it stops.
        let mut monitor = FarSideMonitor::default();
        let mut tick = |ms: u64, plays: bool| monitor.tick(Some(ms), far_side_enabled(true, Some(ms), true, || plays));
        assert_eq!(tick(29_000, true), None);
        assert!(tick(30_000, true).is_some_and(|p| p.silent));
        assert_eq!(tick(31_000, true), None, "once");
        assert!(tick(32_000, false).is_some_and(|p| !p.silent), "nothing plays any more: taken back");
        let mut quiet = FarSideMonitor::default();
        assert_eq!(quiet.tick(Some(60_000), far_side_enabled(true, Some(60_000), true, || false)), None, "you speak, nothing plays: never");
    }

    #[test]
    fn whether_another_app_plays_is_read_at_most_every_5_s() {
        assert_eq!(PLAYING_EVERY, Duration::from_secs(5));
        let start = Instant::now();
        let mut cache = PlayingCache::default();
        let mut reads = 0;
        let mut at = |s: u64, answer: bool| {
            cache.get(start + Duration::from_secs(s), || {
                reads += 1;
                answer
            })
        };
        assert!(at(0, true));
        for s in 1..5 {
            assert!(at(s, false), "kept for 5 s");
        }
        assert!(!at(5, false), "read again");
        assert!(!at(9, true));
        assert_eq!(reads, 2);
    }

    #[test]
    fn a_look_from_before_the_other_side_was_heard_never_shows_the_warning_again() {
        let mut watch = SystemWatch::default();
        let missing = watch.look(&empty(3_000));
        assert_eq!(missing, SystemVerdict::Missing(SystemProblem::NoData));
        assert!(watch.still(&missing));
        // The other side speaks before the job of that look runs: its verdict is old, and dropped.
        let heard_now = watch.look(&heard(3_500));
        assert_eq!(heard_now, SystemVerdict::Recording);
        assert!(!watch.still(&missing), "no flash of the warning after the other side spoke");
        assert!(watch.still(&heard_now));
        // And the other way: a "recording" from before it went missing again never clears the warning.
        let again = watch.look(&SystemSide { failure: Some("tap gone".into()), ..empty(0) });
        assert!(matches!(again, SystemVerdict::Missing(SystemProblem::Failed(_))));
        assert!(!watch.still(&heard_now));
        assert!(watch.still(&SystemVerdict::Same));
    }

    #[test]
    fn empty_buffers_are_the_permission_warning() {
        assert_eq!(system_side_problem(&empty(2_999)), None, "not before 3 s");
        let problem = system_side_problem(&empty(3_000)).expect("said at 3 s");
        assert_eq!(problem, SystemProblem::NoData);
        assert_eq!(problem.reason(), "the system audio tap delivered buffers with no audio data for 3 s");
        let mut watch = SystemWatch::default();
        assert_eq!(watch.look(&empty(3_000)), SystemVerdict::Missing(SystemProblem::NoData));
        assert_eq!(watch.warning(), Some((OtherSide::Permission, SYSTEM_NOT_RECORDED)));
        assert_eq!(SYSTEM_NOT_RECORDED, "The other side isn't being recorded. Allow System Audio Recording for EchoLedger.");
        // No IOProc call at all is the device's matter (the supervisor says it), not the permission.
        assert_eq!(system_side_problem(&SystemSide { running_ms: 10_000, ..SystemSide::default() }), None);
        assert_eq!(SYSTEM_CHECK_AFTER_MS, 3_000);
    }

    #[test]
    fn a_failed_tap_keeps_the_microphone_and_says_why_at_once() {
        // The tap does not open (OSStatus from AudioHardwareCreateProcessTap): the call records the microphone alone.
        let mut asked = Vec::new();
        let (stream, failure) = open_keeping_mic(CALL, |with_tap| {
            asked.push(with_tap);
            if with_tap { Err("system audio tap (OSStatus 560947818); allow System Audio Recording".to_string()) } else { Ok("microphone only") }
        })
        .expect("the recording starts");
        assert_eq!(asked, [true, false], "with the tap first, then without it");
        assert_eq!(stream, "microphone only", "the microphone is kept");
        let failure = failure.expect("the reason is kept");
        let problem = system_side_problem(&SystemSide { failure: Some(failure.clone()), ..SystemSide::default() }).expect("said at once");
        assert_eq!(problem, SystemProblem::Failed(failure));
        assert!(problem.reason().starts_with("the system audio tap did not open: system audio tap (OSStatus 560947818)"));
        let missing = missing_with(RecordingMode::Call, &ON, Some((OtherSide::Permission, SYSTEM_NOT_RECORDED)));
        assert_eq!(missing.len(), 1);
        assert_eq!((missing[0].kind, missing[0].sentence.as_str(), missing[0].other_side), (Kind::SystemAudio, SYSTEM_NOT_RECORDED, Some(OtherSide::Permission)));
    }

    #[test]
    fn what_opens_with_its_tap_or_is_not_a_call_is_left_as_it_was() {
        assert_eq!(open_keeping_mic(CALL, |_| Ok::<_, String>(7)).unwrap(), (7, None));
        let mut asked = 0;
        let note = open_keeping_mic(Streams { mic: false, system: true }, |_| {
            asked += 1;
            Err::<u8, _>("no tap".to_string())
        });
        assert_eq!(note.unwrap_err(), "no tap", "a voice note of this computer's sound has nothing without its tap");
        assert_eq!(asked, 1);
        let both = open_keeping_mic(CALL, |_| Err::<u8, _>("no device".to_string()));
        assert_eq!(both.unwrap_err(), "no device", "the microphone alone could not open either: the recording's error");
    }

    #[test]
    fn the_warning_stays_through_a_resume_and_a_try_until_the_other_side_is_heard() {
        let mut watch = SystemWatch::default();
        assert_eq!(watch.look(&empty(3_000)), SystemVerdict::Missing(SystemProblem::NoData));
        assert_eq!(watch.look(&empty(4_000)), SystemVerdict::Same, "said once");
        // A resume starts the counts afresh: the first seconds know nothing yet, and the warning stays.
        for ms in [0, 1_000, 2_000] {
            assert_eq!(watch.look(&empty(ms)), SystemVerdict::Same, "{ms} ms after the resume");
            assert_eq!(watch.warning(), Some((OtherSide::Permission, SYSTEM_NOT_RECORDED)));
        }
        assert_eq!(watch.look(&empty(3_000)), SystemVerdict::Same, "the same problem: no flicker");
        // Try again: the warning stays; the look after it still finds no data and says to quit and reopen.
        watch.retry();
        assert_eq!(watch.look(&empty(500)), SystemVerdict::Same);
        assert_eq!(watch.warning(), Some((OtherSide::Permission, SYSTEM_NOT_RECORDED)));
        assert_eq!(watch.look(&empty(3_000)), SystemVerdict::Missing(SystemProblem::NoData));
        assert_eq!(watch.warning(), Some((OtherSide::Permission, SYSTEM_NOT_RECORDED_REOPEN)));
        assert!(SYSTEM_NOT_RECORDED_REOPEN.contains("quit and reopen EchoLedger"));
        assert_eq!(watch.look(&empty(4_000)), SystemVerdict::Same);
        // Heard: the warning goes, and system audio counts as working.
        assert_eq!(watch.look(&heard(5_000)), SystemVerdict::Recording);
        assert_eq!(watch.warning(), None);
        assert_eq!(watch.look(&heard(6_000)), SystemVerdict::Same);
    }

    #[test]
    fn the_warning_never_hides_that_nothing_is_recorded_and_a_note_has_no_other_side() {
        let none = Permissions { microphone: Access::Denied, system_audio: Access::Denied, ..ON };
        let missing = missing_with(RecordingMode::Call, &none, Some((OtherSide::Permission, SYSTEM_NOT_RECORDED)));
        assert_eq!(missing.iter().map(|m| m.kind).collect::<Vec<_>>(), [Kind::Microphone, Kind::SystemAudio]);
        assert!(missing.iter().all(|m| m.sentence.contains("nothing to record") && m.other_side.is_none()));
        assert!(missing_with(RecordingMode::MicNote, &ON, Some((OtherSide::Permission, SYSTEM_NOT_RECORDED))).is_empty());
        assert!(missing_with(RecordingMode::Call, &ON, None).is_empty());
        assert!(!has_signal(&[0.0, DIGITAL_SILENCE, -DIGITAL_SILENCE]));
        assert!(has_signal(&[0.0, 0.001]));
    }
}
