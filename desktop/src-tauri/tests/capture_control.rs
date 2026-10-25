//! Capture and control (plan B1-B5): per-app auto-record switches, bookmarks and pause gaps, caption overlay
//! sizes, the microphone choice and capture health. Everything here is pure.

use std::collections::HashMap;

use desktop_lib::audio::health::{
    FAR_SIDE_SILENT_MS, FarSideMonitor, FarSidePayload, HEARD_LEVEL, consent_from, peak_level, rms, stereo_wav, summarize,
};
use desktop_lib::audio::notes::{DEFAULT_TAGS, MAX_MARKERS, MAX_PAUSES, RecordingNotes, clean, tag_presets};
use desktop_lib::audio::platform::{InputDevice, choose_mic, sort_devices};
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
    };
    let body = serde_json::to_value(&create).unwrap();
    assert!(body.get("markers").is_none() && body.get("pauses").is_none(), "a v1 server never sees the keys");

    create.markers = vec![MarkerDto { at_ms: 5, tag: Some("Budget".into()), note: None }];
    create.pauses = vec![PauseDto { at_ms: 7, paused_ms: 3 }];
    let body = serde_json::to_value(&create).unwrap();
    assert_eq!(body["markers"], json!([{"at_ms": 5, "tag": "Budget", "note": null}]));
    assert_eq!(body["pauses"], json!([{"at_ms": 7, "paused_ms": 3}]));

    let finish = FinishSessionRequest {
        ended_at: "2026-10-25T09:10:00Z".into(),
        duration_ms: 600_000,
        title: None,
        client_id: None,
        artifacts: vec![],
        markers: create.markers.clone(),
        pauses: vec![],
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
    InputDevice { id: id.into(), name: name.into(), is_default: default }
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
    assert_eq!(serde_json::to_value(&devices[0]).unwrap(), json!({"id": "a", "name": "Headset", "isDefault": true}));
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
