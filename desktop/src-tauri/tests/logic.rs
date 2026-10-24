//! Pure-logic unit tests for the desktop core (Phase 4).
//! Audio DSP, timeline alignment, overlay geometry, SPKI pin parsing, upload planning, provider parsers,
//! caption buffer, mic-use classifier + state machine, shortcut parsing, and library sync merge.

use std::collections::HashMap;

use desktop_lib::audio::dsp::{self, Resampler};
use desktop_lib::audio::platform::supports_process_loopback;
use desktop_lib::audio::timeline::{self, Placement, Timeline};
use desktop_lib::audio::types::Channel;
use desktop_lib::detect::classify::{classify_macos, classify_windows, SourceApp};
use desktop_lib::detect::state::{MicUseEvent, MicUseState, MicUseStateMachine};
use desktop_lib::net::models::LibraryItem;
use desktop_lib::net::pinning::{is_local_dev, parse_pins};
use desktop_lib::net::sync::merge;
use desktop_lib::net::uploader::{missing_chunks, plan_chunks, sha256_hex};
use desktop_lib::overlay::geometry::{contains, cursor_in_regions, dock, nearest_corner, Corner, RectPx, WorkArea};
use desktop_lib::shortcuts::{default_map, duplicates, parse, Action};
use desktop_lib::stt::captions::{CaptionBuffer, FinalTurn};
use desktop_lib::stt::protocol::{elevenlabs_audio_message, parse_assemblyai, parse_elevenlabs, SttEvent, ASSEMBLYAI_TERMINATE};

// ---- DSP -------------------------------------------------------------------------------------------------------

#[test]
fn f32_to_i16_clips_at_the_rails() {
    assert_eq!(dsp::f32_to_i16(0.0), 0);
    assert_eq!(dsp::f32_to_i16(1.0), i16::MAX);
    assert_eq!(dsp::f32_to_i16(-1.0), i16::MIN);
    assert_eq!(dsp::f32_to_i16(2.0), i16::MAX); // clamped
    assert_eq!(dsp::f32_to_i16(-2.0), i16::MIN);
}

#[test]
fn interleave_pads_shorter_channel_with_silence() {
    assert_eq!(dsp::interleave(&[1.0, 2.0], &[3.0]), vec![1.0, 3.0, 2.0, 0.0]);
}

#[test]
fn downmix_averages_frames() {
    assert_eq!(dsp::downmix_to_mono(&[1.0, 3.0, 2.0, 4.0], 2), vec![2.0, 3.0]);
    assert_eq!(dsp::downmix_to_mono(&[1.0, 2.0], 1), vec![1.0, 2.0]);
}

#[test]
fn pad_to_equal_matches_lengths() {
    let (mut a, mut b) = (vec![1.0], vec![2.0, 3.0]);
    dsp::pad_to_equal(&mut a, &mut b);
    assert_eq!(a, vec![1.0, 0.0]);
    assert_eq!(b, vec![2.0, 3.0]);
}

#[test]
fn rms_is_zero_for_silence_and_one_for_full_scale() {
    assert_eq!(dsp::rms(&[0.0; 100]), 0.0);
    assert!((dsp::rms(&[1.0; 100]) - 1.0).abs() < 1e-6);
}

#[test]
fn resampler_is_passthrough_at_equal_rates_and_thirds_the_count_at_48_to_16() {
    let mut same = Resampler::new(16_000, 16_000);
    assert!(same.is_passthrough());
    assert_eq!(same.process(&[0.1, 0.2, 0.3]).len(), 3);

    let mut down = Resampler::new(48_000, 16_000);
    assert!(!down.is_passthrough());
    let out = down.process(&vec![0.5f32; 300]);
    assert!((out.len() as i64 - 100).abs() <= 1, "expected ~100, got {}", out.len());
}

// ---- Timeline --------------------------------------------------------------------------------------------------

#[test]
fn nanos_convert_to_frames_with_rounding() {
    assert_eq!(timeline::nanos_to_frames(1_000_000_000, 16_000), 16_000);
    assert_eq!(timeline::nanos_to_frames(500_000_000, 16_000), 8_000);
}

#[test]
fn place_packet_pads_skips_or_appends() {
    // Far ahead of the cursor → pad the gap with silence.
    assert_eq!(place_eq(timeline::place_packet(0, 1000, 160, 80)), Placement { pad: 1000, skip: 0, take: 160 });
    // Behind the cursor → drop the already-covered head.
    assert_eq!(place_eq(timeline::place_packet(1000, 500, 160, 80)), Placement { pad: 0, skip: 160, take: 0 });
    // Within tolerance → append as-is.
    assert_eq!(place_eq(timeline::place_packet(1000, 1040, 160, 80)), Placement { pad: 0, skip: 0, take: 160 });
}

fn place_eq(p: Placement) -> Placement {
    p
}

#[test]
fn timeline_fills_a_gap_so_channels_stay_aligned() {
    let mut tl = Timeline::new(16_000);
    let t0 = 1_000_000_000u64;
    // First packet at T0, 160 frames.
    let first = tl.place(t0, Some(t0), 160);
    assert_eq!(first.pad, 0);
    assert_eq!(tl.cursor(), 160);
    // Next packet 100 ms later (1600 frames) → 1440 frames of silence inserted.
    let gap = tl.place(t0, Some(t0 + 100_000_000), 160);
    assert_eq!(gap.pad, 1440);
    assert_eq!(tl.cursor(), 160 + 1440 + 160);
}

// ---- Build gating ----------------------------------------------------------------------------------------------

#[test]
fn process_loopback_requires_windows_11_build() {
    assert!(!supports_process_loopback(19_045)); // Windows 10 22H2
    assert!(supports_process_loopback(20_348)); // Server 2022 / Win11 baseline
    assert!(supports_process_loopback(22_631));
}

// ---- Overlay geometry ------------------------------------------------------------------------------------------

#[test]
fn cursor_hit_testing_respects_window_origin_including_negative_monitors() {
    let regions = [RectPx { x: 10.0, y: 10.0, w: 50.0, h: 20.0 }];
    assert!(cursor_in_regions((120.0, 120.0), (100, 100), &regions)); // local (20,20)
    assert!(!cursor_in_regions((100.0, 100.0), (100, 100), &regions)); // local (0,0) → outside
    // Monitor to the left of the primary (negative x).
    assert!(cursor_in_regions((-1900.0, 25.0), (-1920, 0), &regions)); // local (20,25)
}

#[test]
fn docking_places_into_each_corner_within_the_work_area() {
    let area = WorkArea { x: 0, y: 0, w: 1920, h: 1040 };
    assert_eq!(dock(area, (360, 200), Corner::BottomRight, 16), (1544, 824));
    assert_eq!(dock(area, (360, 200), Corner::TopLeft, 16), (16, 16));
    // Oversized window is clamped to the work-area origin.
    assert_eq!(dock(area, (4000, 4000), Corner::BottomRight, 16), (0, 0));
}

#[test]
fn nearest_corner_snaps_toward_the_window_center() {
    let area = WorkArea { x: 0, y: 0, w: 1000, h: 1000 };
    assert_eq!(nearest_corner(area, (0, 0), (100, 100)), Corner::TopLeft);
    assert_eq!(nearest_corner(area, (880, 880), (100, 100)), Corner::BottomRight);
    assert!(contains(area, (500, 500)));
    assert!(!contains(area, (1001, 500)));
}

// ---- Pin parsing -----------------------------------------------------------------------------------------------

#[test]
fn pins_parse_valid_entries_and_skip_junk() {
    let valid = "sha256/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=";
    let parsed = parse_pins(&[valid.to_string(), "nonsense".to_string(), "sha256/short".to_string()]);
    assert_eq!(parsed.len(), 1);
    assert_eq!(parsed[0], [0u8; 32]);
}

#[test]
fn local_dev_hosts_are_detected() {
    assert!(is_local_dev("http://localhost:8000"));
    assert!(is_local_dev("http://127.0.0.1:8000"));
    assert!(!is_local_dev("https://echo.example.com"));
    assert!(!is_local_dev("http://echo.example.com"));
}

// ---- Upload planning -------------------------------------------------------------------------------------------

#[test]
fn chunk_planning_covers_the_file_with_a_short_final_chunk() {
    assert_eq!(plan_chunks(250, 100), vec![(0, 100), (100, 100), (200, 50)]);
    assert_eq!(plan_chunks(300, 100), vec![(0, 100), (100, 100), (200, 100)]);
    assert!(plan_chunks(0, 100).is_empty());
    assert!(plan_chunks(100, 0).is_empty());
}

#[test]
fn missing_chunks_lists_unreceived_indexes() {
    assert_eq!(missing_chunks(4, &[0, 2]), vec![1, 3]);
    assert!(missing_chunks(3, &[0, 1, 2]).is_empty());
}

#[test]
fn sha256_hex_matches_the_known_vector() {
    assert_eq!(sha256_hex(b"abc"), "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad");
}

// ---- Provider parsers ------------------------------------------------------------------------------------------

#[test]
fn assemblyai_lifecycle_and_turns_parse() {
    assert_eq!(parse_assemblyai(r#"{"type":"Begin"}"#), Some(SttEvent::Started));
    assert_eq!(parse_assemblyai(r#"{"type":"Termination"}"#), Some(SttEvent::Terminated));
    assert_eq!(
        parse_assemblyai(r#"{"type":"Turn","transcript":"hello wor","end_of_turn":false}"#),
        Some(SttEvent::Partial { text: "hello wor".into() }),
    );
    // End of turn but not yet formatted → suppressed; the formatted one follows.
    assert_eq!(parse_assemblyai(r#"{"type":"Turn","transcript":"hello","end_of_turn":true,"turn_is_formatted":false}"#), None);
    let final_raw = r#"{"type":"Turn","transcript":"Hello world.","end_of_turn":true,"turn_is_formatted":true,
        "words":[{"start":120,"end":480},{"start":500,"end":900}]}"#;
    assert_eq!(parse_assemblyai(final_raw), Some(SttEvent::Final { text: "Hello world.".into(), start_ms: Some(120), end_ms: Some(900) }));
    assert!(parse_assemblyai("not json").is_none());
}

#[test]
fn elevenlabs_lifecycle_and_turns_parse() {
    assert_eq!(parse_elevenlabs(r#"{"message_type":"session_started"}"#), Some(SttEvent::Started));
    assert_eq!(
        parse_elevenlabs(r#"{"message_type":"partial_transcript","text":"hi"}"#),
        Some(SttEvent::Partial { text: "hi".into() }),
    );
    let raw = r#"{"message_type":"committed_transcript_with_timestamps","text":"Done.","words":[{"start":1.0,"end":1.5},{"start":1.6,"end":2.0}]}"#;
    assert_eq!(parse_elevenlabs(raw), Some(SttEvent::Final { text: "Done.".into(), start_ms: Some(1000), end_ms: Some(2000) }));
}

#[test]
fn elevenlabs_audio_message_is_base64_pcm() {
    let msg = elevenlabs_audio_message(&[0, 0], false);
    assert!(msg.contains("input_audio_chunk"));
    assert!(msg.contains("audio_base_64"));
    assert!(ASSEMBLYAI_TERMINATE.contains("Terminate"));
}

// ---- Caption buffer --------------------------------------------------------------------------------------------

#[test]
fn caption_buffer_windows_by_wall_clock_and_sorts_segments() {
    let mut buf = CaptionBuffer::default();
    buf.push(FinalTurn { wall_ms: 1_000, channel: Channel::Mic, text: "early".into(), start_ms: 0, end_ms: 500 });
    buf.push(FinalTurn { wall_ms: 60_000, channel: Channel::System, text: "late".into(), start_ms: 59_000, end_ms: 59_500 });

    // "Copy last 2 minutes" at t=120s keeps both; at t=130s the first falls out of a 120s window.
    assert_eq!(buf.text_since(120_000, 120_000), "Me: early\nClient: late");
    assert_eq!(buf.text_since(130_000, 120_000), "Client: late");

    let segments = buf.segments();
    assert_eq!(segments.len(), 2);
    assert_eq!(segments[0].channel, 1); // Mic sorts first by start_ms
    assert_eq!(segments[1].channel, 2);
    buf.clear();
    assert!(buf.is_empty());
}

// ---- Classifier ------------------------------------------------------------------------------------------------

#[test]
fn windows_images_and_packages_classify() {
    assert_eq!(classify_windows("Zoom.exe", None), Some(SourceApp::Zoom));
    assert_eq!(classify_windows("WhatsApp.exe", None), Some(SourceApp::Whatsapp));
    assert_eq!(classify_windows("chrome.exe", None), Some(SourceApp::GoogleMeet));
    assert_eq!(classify_windows("notepad.exe", None), None);
    assert_eq!(classify_windows("anything.exe", Some("5319275A.WhatsAppDesktop_cv1g1gvanyjgm")), Some(SourceApp::Whatsapp));
    // Other WhatsApp builds are recognised by package family or executable name, not only the two known names.
    assert_eq!(classify_windows("helper.exe", Some("WhatsAppInc.WhatsAppBeta_abc123")), Some(SourceApp::Whatsapp));
    assert_eq!(classify_windows("WhatsApp.Beta.exe", None), Some(SourceApp::Whatsapp));
    assert_eq!(classify_windows("notepad.exe", Some("Microsoft.WindowsNotepad_8wekyb3d8bbwe")), None);
    assert_eq!(SourceApp::Zoom.as_str(), "zoom");
}

#[test]
fn macos_bundle_ids_classify_with_business_before_consumer() {
    assert_eq!(classify_macos("net.whatsapp.WhatsAppSMB.desktop"), Some(SourceApp::WhatsappBusiness));
    assert_eq!(classify_macos("net.whatsapp.WhatsApp"), Some(SourceApp::Whatsapp));
    assert_eq!(classify_macos("us.zoom.xos.helper"), Some(SourceApp::Zoom));
    assert_eq!(classify_macos("com.google.Chrome"), Some(SourceApp::GoogleMeet));
    assert_eq!(classify_macos("com.apple.Terminal"), None);
}

// ---- Helper-process attribution (Windows) ----------------------------------------------------------------------

#[cfg(windows)]
mod helper_process {
    use std::collections::HashMap;

    use desktop_lib::audio::platform::windows::ancestor_app_with;
    use desktop_lib::detect::classify::SourceApp;

    fn identity(table: &[(u32, &str, Option<&str>)]) -> impl Fn(u32) -> (String, Option<String>) {
        let rows: Vec<(u32, String, Option<String>)> = table.iter().map(|(p, i, f)| (*p, i.to_string(), f.map(str::to_string))).collect();
        move |pid| rows.iter().find(|(p, _, _)| *p == pid).map(|(_, i, f)| (i.clone(), f.clone())).unwrap_or_default()
    }

    #[test]
    fn a_webview2_helper_is_attributed_to_the_whatsapp_app_that_launched_it() {
        // msedgewebview2 (audio) ← msedgewebview2 (browser) ← WhatsApp.Root.exe ← explorer.exe
        let parents = HashMap::from([(300, 200), (200, 100), (100, 50), (50, 0)]);
        let who = identity(&[(200, "msedgewebview2.exe", None), (100, "WhatsApp.Root.exe", Some("5319275A.51895FA4EA97F_cv1g1gvanyjgm")), (50, "explorer.exe", None)]);
        assert_eq!(ancestor_app_with(300, &parents, who), Some(SourceApp::Whatsapp));
    }

    #[test]
    fn a_helper_of_an_unrelated_app_stays_unrecognised() {
        let parents = HashMap::from([(300, 200), (200, 50), (50, 0)]);
        let who = identity(&[(200, "ms-teams.exe", None), (50, "explorer.exe", None)]);
        assert_eq!(ancestor_app_with(300, &parents, who), None);
    }

    #[test]
    fn the_walk_stops_on_cycles_and_unknown_pids() {
        let looped = HashMap::from([(1, 2), (2, 1)]);
        assert_eq!(ancestor_app_with(1, &looped, identity(&[])), None);
        assert_eq!(ancestor_app_with(99, &HashMap::new(), identity(&[])), None);
        let selfish = HashMap::from([(7, 7)]);
        assert_eq!(ancestor_app_with(7, &selfish, identity(&[])), None);
    }
}

// ---- Mic-use state machine -------------------------------------------------------------------------------------

#[test]
fn mic_use_debounces_start_and_stop() {
    let mut sm = MicUseStateMachine::new(300, 800);
    assert_eq!(sm.update(0, Some(SourceApp::Whatsapp)), None); // → Candidate
    assert_eq!(sm.update(100, Some(SourceApp::Whatsapp)), None); // still within start debounce
    assert_eq!(sm.update(400, Some(SourceApp::Whatsapp)), Some(MicUseEvent::Start(SourceApp::Whatsapp)));
    assert!(matches!(sm.state(), MicUseState::Recording { .. }));
    assert_eq!(sm.update(500, None), None); // → Releasing
    assert_eq!(sm.update(600, None), None); // still within stop debounce
    assert_eq!(sm.update(1_400, None), Some(MicUseEvent::Stop(SourceApp::Whatsapp)));
    assert_eq!(sm.state(), MicUseState::Idle);
}

#[test]
fn mic_use_recovers_if_the_app_briefly_drops_during_release() {
    let mut sm = MicUseStateMachine::new(100, 500);
    sm.update(0, Some(SourceApp::Zoom));
    sm.update(200, Some(SourceApp::Zoom)); // Recording
    sm.update(300, None); // Releasing
    assert_eq!(sm.update(350, Some(SourceApp::Zoom)), None); // back to Recording, no stop fired
    assert!(matches!(sm.state(), MicUseState::Recording { .. }));
}

// ---- Shortcuts -------------------------------------------------------------------------------------------------

#[test]
fn default_shortcuts_have_no_collisions() {
    let map = default_map();
    assert_eq!(map.len(), 4); // VoiceNote has no default
    assert!(duplicates(&map).is_empty());
    assert!(parse("CommandOrControl+Shift+Space").is_ok());
    assert!(parse("definitely not a shortcut").is_err());
}

#[test]
fn duplicate_accelerators_are_reported() {
    let mut map: HashMap<Action, String> = HashMap::new();
    map.insert(Action::ToggleCaptions, "CommandOrControl+Shift+X".into());
    map.insert(Action::Dictation, "CommandOrControl+Shift+X".into());
    assert_eq!(duplicates(&map).len(), 1);
}

// ---- Library sync ----------------------------------------------------------------------------------------------

fn item(id: &str, updated_at: &str, started_at: &str, deleted: bool) -> LibraryItem {
    serde_json::from_value(serde_json::json!({
        "id": id,
        "deleted": deleted,
        "updated_at": updated_at,
        "started_at": started_at,
        "source_device": "phone"
    }))
    .unwrap()
}

#[test]
fn merge_inserts_replaces_newer_and_sorts_by_start_desc() {
    let mut items = vec![];
    merge(&mut items, vec![item("a", "2026-01-01T00:00:00Z", "2026-01-01T09:00:00Z", false)]);
    merge(&mut items, vec![item("b", "2026-01-01T00:00:00Z", "2026-01-02T09:00:00Z", false)]);
    assert_eq!(items.iter().map(|i| i.id.as_str()).collect::<Vec<_>>(), vec!["b", "a"]); // b started later

    // Older update for "a" is ignored; newer one replaces.
    merge(&mut items, vec![item("a", "2025-12-01T00:00:00Z", "2020-01-01T00:00:00Z", false)]);
    assert!(items.iter().any(|i| i.id == "a" && i.rest.get("started_at").unwrap() == "2026-01-01T09:00:00Z"));
}

#[test]
fn merge_removes_tombstoned_items() {
    let mut items = vec![item("a", "2026-01-01T00:00:00Z", "2026-01-01T09:00:00Z", false)];
    merge(&mut items, vec![item("a", "2026-02-01T00:00:00Z", "2026-01-01T09:00:00Z", true)]);
    assert!(items.is_empty());
}
