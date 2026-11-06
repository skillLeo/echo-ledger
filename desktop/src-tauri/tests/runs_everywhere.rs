//! Phase 1B "runs everywhere": the frame arithmetic of the overlays on a Mac's displays, Windows versions without per-app
//! capture, a recording that goes on across a device change, and the device report's contract. All of it pure or on files:
//! no display, no device.

use std::collections::HashMap;

use chrono::Utc;
use desktop_lib::audio::platform::{WHOLE_SYSTEM_SENTENCE, records_whole_system, supports_process_loopback, system_audio_note_for};
use desktop_lib::audio::recorder::Recorder;
use desktop_lib::audio::types::{AudioChunk, Channel, TARGET_RATE};
use desktop_lib::audio::writer::read_mono_i16;
use desktop_lib::mac_check::{CheckItem, CheckReport, CheckState};
use desktop_lib::net::models::DeviceReport;
use desktop_lib::overlay::geometry::{
    Corner, MAC_FLOATING_LEVEL, MAC_OVERLAY_BEHAVIOUR, MAC_OVERLAY_LEVEL, MacRect, MacScreen, PillPosition, RectPx, WorkArea, area_for, cursor_in_regions, cursor_in_regions_scaled, dock,
    mac_area, mac_follows_spaces, mac_on_screen, nearest_corner, pill_origin, rescue, top_center,
};
use desktop_lib::settings::Settings;
use desktop_lib::shortcuts::{self, Action};
use desktop_lib::tray::{TrayState, look};

fn rect(x: f64, y: f64, w: f64, h: f64) -> MacRect {
    MacRect { x, y, w, h }
}

/// A 14-inch MacBook Pro: 1512 × 982 points, 37 pt of notch and menu bar, the Dock 70 pt high at the bottom.
fn notched() -> MacScreen {
    MacScreen { frame: rect(0.0, 0.0, 1512.0, 982.0), visible: rect(0.0, 70.0, 1512.0, 875.0), top_inset: 37.0 }
}

/// A 27-inch monitor to its right, 2560 × 1440, no notch, a 25 pt menu bar, its bottom lined up with the laptop's.
fn monitor() -> MacScreen {
    MacScreen { frame: rect(1512.0, 0.0, 2560.0, 1440.0), visible: rect(1512.0, 0.0, 2560.0, 1415.0), top_inset: 0.0 }
}

// ---- the menu bar, the notch and the Dock ------------------------------------------------------------------------

#[test]
fn overlays_stay_clear_of_the_menu_bar_and_the_dock() {
    let area = mac_area(&notched(), 982.0);
    assert_eq!(area, WorkArea { x: 0, y: 37, w: 1512, h: 875 });
    // The pill's top-centre spot: under the menu bar, in the middle.
    assert_eq!(top_center(area, (352, 84), 12), (580, 49));
    // The caption overlay's corner: above the Dock.
    let (_, y) = dock(area, (460, 146), Corner::BottomRight, 18);
    assert_eq!(y + 146 + 18, 37 + 875);
}

#[test]
fn with_a_menu_bar_that_hides_the_notch_still_counts() {
    // Automatically hide the menu bar: the visible frame reaches the top of the screen.
    let hiding = MacScreen { visible: rect(0.0, 70.0, 1512.0, 912.0), ..notched() };
    assert_eq!(mac_area(&hiding, 982.0).y, 37, "the camera housing is still there");
    // Without a notch the whole height is usable then.
    let plain = MacScreen { frame: rect(0.0, 0.0, 1440.0, 900.0), visible: rect(0.0, 0.0, 1440.0, 900.0), top_inset: 0.0 };
    assert_eq!(mac_area(&plain, 900.0), WorkArea { x: 0, y: 0, w: 1440, h: 900 });
}

#[test]
fn every_display_is_placed_from_the_primary_s_top() {
    // AppKit's y goes up from the primary's bottom; windows are placed with y going down from its top. The monitor is
    // taller, so its top is above the laptop's: negative.
    let area = mac_area(&monitor(), 982.0);
    assert_eq!(area, WorkArea { x: 1512, y: -458 + 25, w: 2560, h: 1415 });
    // A display to the left and below: negative x, and past the primary's bottom.
    let left = MacScreen { frame: rect(-1920.0, -400.0, 1920.0, 1080.0), visible: rect(-1920.0, -400.0, 1920.0, 1055.0), top_inset: 0.0 };
    assert_eq!(mac_area(&left, 982.0), WorkArea { x: -1920, y: 982 + 400 - 1055, w: 1920, h: 1055 });
}

#[test]
fn fractional_points_round_inward() {
    let screen = MacScreen { frame: rect(0.0, 0.0, 1000.0, 800.0), visible: rect(0.5, 10.5, 999.0, 769.0), top_inset: 0.0 };
    let area = mac_area(&screen, 800.0);
    assert_eq!((area.x, area.y), (1, 21));
    assert!(area.x as f64 >= 0.5 && (area.y + area.h as i32) as f64 <= 800.0 - 10.5);
}

#[test]
fn a_window_never_opens_on_a_display_that_is_gone() {
    let areas = [mac_area(&notched(), 982.0), mac_area(&monitor(), 982.0)];
    let on_monitor = PillPosition { x: 3000, y: 100 };
    assert_eq!(pill_origin(Some(on_monitor), &areas, areas[0], (352, 84), 12), (3000, 100));
    // The monitor was unplugged: the pill's old place is nowhere.
    assert_eq!(pill_origin(Some(on_monitor), &areas[..1], areas[0], (352, 84), 12), (580, 49));
}

// ---- a display unplugged moves the windows back on screen ---------------------------------------------------------

#[test]
fn a_window_on_a_display_that_went_comes_back() {
    let laptop = mac_area(&notched(), 982.0);
    // The captions were on the monitor, which is gone now.
    let back = rescue(&[laptop], (3000, 100), (460, 146)).expect("moved");
    assert!(back.0 + 460 <= laptop.x + laptop.w as i32 && back.1 >= laptop.y, "{back:?}");
    assert_eq!(back, (1052, 100), "the least distance into the display that is left");
    // From far below and to the left as well.
    let back = rescue(&[laptop], (-2000, 5000), (352, 84)).unwrap();
    assert!(back.0 >= laptop.x && back.1 + 84 <= laptop.y + laptop.h as i32, "{back:?}");
}

#[test]
fn a_window_that_mostly_shows_is_left_where_the_person_put_it() {
    let laptop = mac_area(&notched(), 982.0);
    assert_eq!(rescue(&[laptop], (100, 100), (460, 146)), None);
    assert_eq!(rescue(&[laptop], (1300, 100), (352, 84)), None, "dragged partly over the edge: their choice");
    assert!(rescue(&[laptop], (1450, 100), (352, 84)).is_some(), "less than half shows");
    assert_eq!(rescue(&[], (0, 0), (10, 10)), None, "no display answered: nothing to do");
}

#[test]
fn a_window_across_two_displays_counts_each_part_alone() {
    let (laptop, big) = (mac_area(&notched(), 982.0), mac_area(&monitor(), 982.0));
    assert_eq!(rescue(&[laptop, big], (1400, 100), (352, 84)), None, "most of it shows on the monitor");
}

#[test]
fn the_nearest_display_is_chosen_when_none_holds_the_point() {
    let (laptop, big) = (mac_area(&notched(), 982.0), mac_area(&monitor(), 982.0));
    assert_eq!(area_for(&[laptop, big], (100, 100)), Some(0));
    assert_eq!(area_for(&[laptop, big], (2000, 100)), Some(1));
    assert_eq!(area_for(&[laptop, big], (9000, 100)), Some(1));
    assert_eq!(area_for(&[], (0, 0)), None);
}

#[test]
fn a_dragged_overlay_snaps_on_the_display_it_was_dropped_on() {
    let big = mac_area(&monitor(), 982.0);
    assert_eq!(nearest_corner(big, (3800, 900), (460, 146)), Corner::BottomRight);
    assert_eq!(nearest_corner(big, (1600, -300), (460, 146)), Corner::TopLeft);
}

// ---- several displays at different scales --------------------------------------------------------------------------

#[test]
fn the_pill_is_clickable_on_a_1x_monitor_beside_a_retina_laptop() {
    // tao scales the cursor by the primary display (2x) and a window by its own (1x): the same point, two numbers.
    let regions = [RectPx { x: 10.0, y: 10.0, w: 100.0, h: 30.0 }];
    let origin = (2000, 100);
    let cursor_point = (2050.0, 120.0);
    let cursor = (cursor_point.0 * 2.0, cursor_point.1 * 2.0);
    assert!(cursor_in_regions_scaled(cursor, 2.0, origin, 1.0, &regions));
    assert!(!cursor_in_regions(cursor, origin, &regions), "the plain sum misses it: what this is for");
    // Beside the region: click-through.
    assert!(!cursor_in_regions_scaled((2200.0 * 2.0, 120.0 * 2.0), 2.0, origin, 1.0, &regions));
}

#[test]
fn the_pill_is_clickable_on_the_retina_laptop_itself_and_scales_its_regions() {
    // The web page reports its regions in physical pixels of its own display.
    let regions = [RectPx { x: 20.0, y: 20.0, w: 200.0, h: 60.0 }];
    let origin = (1000, 200); // (500, 100) in points
    assert!(cursor_in_regions_scaled((1040.0, 240.0), 2.0, origin, 2.0, &regions));
    assert!(!cursor_in_regions_scaled((1000.0, 200.0), 2.0, origin, 2.0, &regions));
}

#[test]
fn on_windows_the_scaled_test_is_the_plain_one() {
    let regions = [RectPx { x: 10.0, y: 10.0, w: 100.0, h: 30.0 }];
    for cursor in [(115.0, 115.0), (105.0, 105.0), (500.0, 500.0)] {
        assert_eq!(cursor_in_regions_scaled(cursor, 1.0, (100, 100), 1.0, &regions), cursor_in_regions(cursor, (100, 100), &regions), "{cursor:?}");
    }
}

// ---- over full-screen meetings and across Spaces -------------------------------------------------------------------

#[test]
fn overlays_join_every_space_and_show_over_full_screen_windows() {
    assert!(mac_follows_spaces(MAC_OVERLAY_BEHAVIOUR));
    assert_eq!(MAC_OVERLAY_LEVEL, 25, "NSStatusWindowLevel: above the menu bar, as full-screen windows need");
    assert_eq!(MAC_FLOATING_LEVEL, 3, "NSFloatingWindowLevel: what a level below which Zoom's share bar would cover it");
    // What `visible_on_all_workspaces` gives alone is not enough: a full-screen Zoom window covers it.
    assert!(!mac_follows_spaces(1), "canJoinAllSpaces without fullScreenAuxiliary");
    assert!(!mac_follows_spaces(1 << 8), "fullScreenAuxiliary without canJoinAllSpaces");
}

#[test]
fn the_check_knows_a_window_that_went_off_screen() {
    let screens = [notched(), monitor()];
    assert!(mac_on_screen(&rect(100.0, 100.0, 352.0, 84.0), &screens));
    assert!(mac_on_screen(&rect(2000.0, 100.0, 352.0, 84.0), &screens));
    assert!(!mac_on_screen(&rect(9000.0, 100.0, 352.0, 84.0), &screens));
    assert!(!mac_on_screen(&rect(100.0, -500.0, 352.0, 84.0), &screens));
}

// ---- the menu bar icon and the keys --------------------------------------------------------------------------------

#[test]
fn the_shortcuts_settings_lists_read_as_a_mac_writes_them() {
    assert_eq!(shortcuts::label("CommandOrControl+Alt+Shift+R", true), "⌘⌥⇧R");
    assert_eq!(shortcuts::label("CommandOrControl+Shift+Space", true), "⌘⇧Space");
    assert_eq!(shortcuts::label("Control+KeyK", true), "⌃K");
    assert_eq!(shortcuts::label("CommandOrControl+Alt+Shift+R", false), "Ctrl + Alt + Shift + R");
    assert_eq!(shortcuts::label("Alt+Shift+D", false), "Alt + Shift + D");
    for action in Action::ALL {
        if let Some(accelerator) = action.default_accelerator() {
            let label = shortcuts::label(accelerator, true);
            assert!(!label.contains("Ctrl") && !label.contains("Alt") && !label.contains("Command"), "{label}");
        }
    }
}

#[test]
fn the_menu_bar_icon_follows_light_and_dark_except_while_recording() {
    assert!(look(TrayState::Idle, true).template);
    assert!(look(TrayState::Attention, true).template);
    assert!(!look(TrayState::Recording, true).template, "the second picture is its own colour");
    assert!(!look(TrayState::Idle, false).template, "Windows has no template images");
}

#[test]
fn start_at_login_is_off_until_the_person_turns_it_on() {
    assert!(!Settings::default().autostart);
    let source = std::fs::read_to_string(concat!(env!("CARGO_MANIFEST_DIR"), "/src/lib.rs")).unwrap();
    assert!(source.contains("tauri_plugin_autostart::init(MacosLauncher::LaunchAgent, Some(vec![\"--autostart\"]))"));
    assert!(source.contains("any(|a| a == \"--autostart\")"), "started at login it stays in the menu bar");
}

// ---- a Windows without per-app capture -----------------------------------------------------------------------------

#[test]
fn windows_below_build_20348_says_it_records_all_sound() {
    for build in [10_240, 17_763, 19_041, 19_044, 19_045, 20_347] {
        assert!(!supports_process_loopback(build));
        assert!(records_whole_system(Some(build)), "build {build}");
        assert_eq!(system_audio_note_for(Some(build)), Some(WHOLE_SYSTEM_SENTENCE), "build {build}");
    }
    assert_eq!(WHOLE_SYSTEM_SENTENCE, "This Windows version records all sound playing on this computer, not just the call");
}

#[test]
fn newer_windows_records_the_call_without_the_sentence() {
    for build in [20_348, 22_000, 22_631, 26_100] {
        assert!(supports_process_loopback(build));
        assert!(!records_whole_system(Some(build)), "build {build}");
        assert_eq!(system_audio_note_for(Some(build)), None, "build {build}");
    }
}

#[test]
fn a_build_that_cannot_be_read_is_treated_like_the_backend_treats_it() {
    // `WindowsBackend::start`: `os_build().is_some_and(supports_process_loopback)`, so no build means endpoint loopback.
    assert!(records_whole_system(None));
    assert_eq!(system_audio_note_for(None), Some(WHOLE_SYSTEM_SENTENCE));
}

#[test]
fn the_backend_and_the_sentence_use_the_same_gate() {
    let source = std::fs::read_to_string(concat!(env!("CARGO_MANIFEST_DIR"), "/src/audio/platform/windows.rs")).unwrap();
    assert!(source.contains("let process_loopback = os_build().is_some_and(supports_process_loopback);"));
    assert!(source.contains("if process_loopback { BackendKind::ProcessLoopback } else { BackendKind::EndpointLoopback }"));
    assert!(source.contains("enumerator.get_default_device(&Direction::Render)"), "the fallback is the default output's loopback");
}

// ---- a recording across a device change ----------------------------------------------------------------------------

fn chunk(channel: Channel, at_seconds: f64, seconds: f64, level: f32) -> AudioChunk {
    let samples = vec![level; (seconds * TARGET_RATE as f64) as usize];
    AudioChunk { channel, samples, ts_ns: Some(1_000_000_000_000 + (at_seconds * 1e9) as u64), discontinuity: false }
}

#[test]
fn a_microphone_that_changes_mid_call_leaves_a_silent_gap_and_the_recording_goes_on() {
    let dir = tempfile::tempdir().unwrap();
    let mut recorder = Recorder::create(dir.path(), false).unwrap();
    // Five seconds on the first microphone, 1.5 s of nothing while the stream reopens, five more on the new one. The
    // other side never stopped.
    recorder.push(chunk(Channel::Mic, 0.0, 5.0, 0.3)).unwrap();
    recorder.push(chunk(Channel::System, 0.0, 11.5, 0.1)).unwrap();
    recorder.push(chunk(Channel::Mic, 6.5, 5.0, 0.3)).unwrap();
    let output = recorder.finish().unwrap();

    assert_eq!(output.duration_ms, 11_500, "the file holds the whole call");
    let mic = read_mono_i16(&output.mic_wav).unwrap();
    assert_eq!(mic.len(), 11 * TARGET_RATE as usize + TARGET_RATE as usize / 2);
    let at = |second: f64| mic[(second * TARGET_RATE as f64) as usize];
    assert!(at(2.0) > 0, "the first microphone");
    assert_eq!(at(5.5), 0, "silence, not a jump");
    assert!(at(7.0) > 0, "the new microphone, at the right time");
    assert_eq!(output.speech_ms[0], 10_000, "ten seconds were heard");
}

#[test]
fn the_other_side_changing_device_does_not_touch_the_microphone() {
    let dir = tempfile::tempdir().unwrap();
    let mut recorder = Recorder::create(dir.path(), false).unwrap();
    recorder.push(chunk(Channel::Mic, 0.0, 10.0, 0.3)).unwrap();
    recorder.push(chunk(Channel::System, 0.0, 3.0, 0.1)).unwrap();
    recorder.push(chunk(Channel::System, 5.0, 5.0, 0.1)).unwrap();
    let output = recorder.finish().unwrap();
    assert_eq!(output.duration_ms, 10_000);
    let system = read_mono_i16(&output.system_wav).unwrap();
    assert_eq!(system[4 * TARGET_RATE as usize], 0);
    assert!(system[6 * TARGET_RATE as usize] > 0);
    assert!(read_mono_i16(&output.mic_wav).unwrap()[4 * TARGET_RATE as usize] > 0);
}

#[test]
fn a_device_that_never_comes_back_still_leaves_a_whole_file() {
    let dir = tempfile::tempdir().unwrap();
    let mut recorder = Recorder::create(dir.path(), false).unwrap();
    recorder.push(chunk(Channel::Mic, 0.0, 2.0, 0.3)).unwrap();
    recorder.push(chunk(Channel::System, 0.0, 8.0, 0.1)).unwrap();
    let output = recorder.finish().unwrap();
    assert_eq!(output.duration_ms, 8_000, "the shorter side is filled to the end");
    assert!(output.primary.exists());
}

#[test]
fn a_resume_starts_on_fresh_devices_so_an_old_warning_does_not_stay() {
    let source = std::fs::read_to_string(concat!(env!("CARGO_MANIFEST_DIR"), "/src/actions.rs")).unwrap();
    let resume = &source[source.find("pub async fn resume_recording").unwrap()..];
    let resumed = resume.find("state.capture.resume()?").unwrap();
    let reset = resume.find("device_status.lock()").expect("the resume forgets what it knew of the devices");
    assert!(reset > resumed && reset < resumed + 200, "only once the new devices are open");
}

// ---- the device report ---------------------------------------------------------------------------------------------

fn report() -> CheckReport {
    let item = |key, state, detail: &str| CheckItem { key, title: "t", state, detail: detail.into(), fix: None };
    CheckReport {
        items: vec![item("macos_version", CheckState::Ok, "macOS 15.1."), item("microphone_test", CheckState::Limited, "Heard nothing."), item("screen_permission", CheckState::Off, "Off."), item("system_audio_test", CheckState::Failed, "No tone.")],
        stopped: None,
        finished_at: Utc::now(),
        app_version: "1.5.0-beta.1".into(),
        os_version: "15.1".into(),
        model: "Mac14,2".into(),
        sent_at: None,
    }
}

#[test]
fn the_mac_check_is_sent_in_the_form_the_server_takes() {
    let body = serde_json::to_value(DeviceReport::of_mac_check(&report())).unwrap();
    assert_eq!(body["platform"], "macos");
    assert_eq!(body["brand"], "Apple");
    assert_eq!(body["model"], "Mac14,2");
    assert_eq!(body["os_version"], "15.1");
    assert_eq!(body["app_version"], "1.5.0-beta.1");
    let checks = body["checks"].as_array().unwrap();
    assert_eq!(checks.len(), 4);
    assert_eq!(checks[0], serde_json::json!({ "key": "macos_version", "state": "ok", "detail": "macOS 15.1." }));
    let states: Vec<&str> = checks.iter().map(|c| c["state"].as_str().unwrap()).collect();
    assert_eq!(states, ["ok", "limited", "off", "failed"], "docs/API.md's four states");
    let keys: HashMap<&str, ()> = body.as_object().unwrap().keys().map(|k| (k.as_str(), ())).collect();
    assert_eq!(keys.len(), 6, "exactly the contract's fields: {keys:?}");
}

#[test]
fn long_fields_are_cut_to_the_server_s_limits() {
    let mut long = report();
    long.model = "M".repeat(100);
    long.os_version = "1".repeat(100);
    long.app_version = "v".repeat(100);
    let body = DeviceReport::of_mac_check(&long);
    assert_eq!((body.model.chars().count(), body.os_version.chars().count(), body.app_version.chars().count()), (64, 64, 32));
}
