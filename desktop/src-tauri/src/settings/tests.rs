//! Settings defaults, the JSON on disk, and compatibility with older and newer files.

use serde_json::{Value, json};

use super::*;

fn dir() -> tempfile::TempDir {
    tempfile::tempdir().unwrap()
}

fn customised() -> Settings {
    let mut shortcuts = default_map();
    shortcuts.insert(Action::VoiceNote, "Ctrl+Alt+V".into());
    shortcuts.insert(Action::Dictation, "Ctrl+Alt+D".into());
    Settings {
        server_url: Some("https://ledger.example.com".into()),
        pins: vec![
            "sha256/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=".into(),
            "sha256/BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB=".into(),
        ],
        device_id: Some("01JB8Q3R0Z5V1K7M9N2P4S6T8W".into()),
        device_name: Some("Büro-PC 🖥️".into()),
        sign_in_id: Some("6f1d2c3b-4a5e-4f60-8a7b-9c0d1e2f3a4b".into()),
        install_id: Some("3f1c5e0b7a9d2c4e6f8a0b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6f7a8b9c0d1e".into()),
        shortcuts,
        captions_on_record: false,
        auto_mode: false,
        hide_from_sharing: ShareHiding::Floating,
        main_share_hide: Some(crate::privacy::share::MainHide { until: crate::privacy::share::HideUntil::At { at_ms: 1_795_000_000_000 }, since_ms: 1_794_999_100_000 }),
        share_notice: true,
        old_windows_sharing: OldWindowsSharing::Show,
        share_one_window: true,
        send_diagnostics: true,
        overlay_corner: Corner::TopLeft,
        autostart: true,
        dictation_output: "clipboard".into(),
        client_id: Some("01JB8Q4C1D2E3F4G5H6J7K8M9N".into()),
        consent_acknowledged: true,
        after_call: AfterCall::Ask,
        after_call_notice: true,
        ask_when: crate::settings::AskWhen::Start,
        card_hint_seen: true,
        card_streak: Some(crate::after_call::CardStreak { choice: crate::after_call::CallChoice::Audio, count: 2 }),
        always_offered: true,
        skip_calls_shorter_than_s: 30,
        caption_lines: 5,
        pill_position: Some(PillPosition { x: -1_520, y: 64 }),
        assist_card_position: Some(PillPosition { x: 880, y: 40 }),
        ask_unknown_apps: true,
        site_rules: vec![crate::detect::sites::SiteRule {
            id: "6f1c2d3e-4b5a-4c7d-8e9f-0a1b2c3d4e5f".into(),
            app: "never".into(),
            host: Some("notes.example.test".into()),
            title_prefix: None,
            title_contains: Some("Private notes".into()),
            title_equals: None,
            priority: 100,
            enabled: true,
        }],
        browser_automation: true,
        auto_record_apps: HashMap::from([("zoom".to_string(), false)]),
        caption_style: CaptionStyle {
            size: CaptionSize::Large,
            width: CaptionWidth::Wide,
            opacity: 60,
            compact: true,
            auto_hide_seconds: 10,
            me_color: Some("#22c55e".into()),
            client_color: None,
        },
        input_device: Some("{0.0.1.00000000}.{4b3c5f0e-usb-headset}".into()),
        far_side_warning: false,
        transcription_language: Some("ur".into()),
        dictation_mode: "push_to_talk".into(),
        dictation_cleanup: false,
        dictation_sounds: false,
        dictation_replacements: vec![crate::dictation::rules::Replacement { from: "asap".into(), to: "as soon as possible".into() }],
        dictation_snippets: vec![crate::dictation::rules::Snippet { trigger: "sig".into(), text: "Regards".into() }],
        app_lock: true,
        lock_after_minutes: 15,
        activity_notifications: false,
        quiet_hours: true,
        quiet_from: "23:30".into(),
        quiet_to: "06:15".into(),
        notification_categories: HashMap::from([("sharing".to_string(), false)]),
        notification_sounds: HashMap::from([("recording".to_string(), false)]),
        onboarding_done: true,
        screen_moments: false,
        screen_target: "whole_screen".into(),
        screen_excluded_apps: vec!["Signal.exe".into()],
    }
}
// ---- Defaults --------------------------------------------------------------------------------------------------

#[test]
fn a_fresh_install_has_the_documented_defaults() {
    let s = Settings::default();
    assert_eq!(
        (s.server_url, s.device_id, s.device_name, s.client_id),
        (None, None, None, None)
    );
    assert!(s.pins.is_empty());
    assert!(s.captions_on_record, "captions open with a recording");
    assert!(s.auto_mode, "auto mode is on");
    assert_eq!(s.hide_from_sharing, ShareHiding::All, "the other floating windows are hidden from screen sharing");
    assert_eq!(s.old_windows_sharing, OldWindowsSharing::BlackBox);
    assert!(!s.share_one_window);
    assert!(!s.send_diagnostics, "diagnostics are sent only once the person turns them on");
    assert!(!s.autostart);
    assert!(!s.consent_acknowledged);
    assert_eq!(s.overlay_corner, Corner::BottomRight);
    assert_eq!(s.dictation_output, "type");
    assert_eq!(s.shortcuts, default_map());
    assert_eq!(s.shortcuts.len(), 8, "voice note has no default shortcut");
    assert!(!s.ask_unknown_apps, "the unknown-app chip is off by default");
    assert!(s.site_rules.is_empty(), "built-in site rules are never stored");
    assert!(!s.browser_automation, "reading browser tabs is opt-in");
    assert_eq!(s.after_call, AfterCall::Ask, "permission first: the card asks (contract 1.8.0-e)");
    assert_eq!(s.skip_calls_shorter_than_s, 10, "automatic calls under 10 s are skipped");
    assert_eq!(s.caption_lines, 3);
    assert_eq!(s.pill_position, None, "the pill starts at the top centre");
    assert!(s.auto_record_apps.is_empty(), "every call app records automatically");
    assert_eq!(s.caption_style, CaptionStyle::default());
    assert_eq!((s.caption_style.size, s.caption_style.width, s.caption_style.opacity), (CaptionSize::Medium, CaptionWidth::Normal, 85));
    assert_eq!(s.caption_style.auto_hide_seconds, 0, "captions stay shown");
    assert_eq!(s.input_device, None, "the system default microphone");
    assert!(s.far_side_warning, "the silent-other-side warning is on");
    assert_eq!(s.transcription_language, None, "the client's or the console's language, else automatic");
    assert_eq!((s.dictation_mode.as_str(), s.dictation_cleanup, s.dictation_sounds), ("toggle", true, true));
    assert!(s.dictation_replacements.is_empty() && s.dictation_snippets.is_empty());
    assert!(s.screen_moments, "screen moments are on, shown in the pill with one-tap pause");
    assert_eq!(s.screen_target, "meeting_window", "the meeting window, not the whole screen");
    assert!(s.screen_excluded_apps.iter().any(|a| a == "1Password") && s.screen_excluded_apps.iter().any(|a| a == "Bitwarden"), "password managers are excluded");
}

#[test]
fn screen_moments_settings_load_tolerantly() {
    let s = Settings::from_value(json!({"screenTarget": "everything", "screenExcludedApps": ["  Signal.exe ", "", "signal", 7, "x".repeat(101)], "screenMoments": "yes"}));
    assert_eq!(s.screen_target, "meeting_window", "an unknown target is the meeting window");
    assert_eq!(s.screen_excluded_apps, vec!["Signal.exe".to_string()], "trimmed, no repeats, nothing too long or not a name");
    assert!(s.screen_moments, "a value of the wrong type keeps the default");
    let whole = Settings::from_value(json!({"screenTarget": "whole_screen", "screenExcludedApps": []}));
    assert_eq!(whole.screen_target, "whole_screen");
    assert!(whole.screen_excluded_apps.is_empty(), "the person may take every app off the list");
}

#[test]
fn defaults_are_what_a_missing_or_empty_object_deserialises_to() {
    assert_eq!(
        serde_json::from_value::<Settings>(json!({})).unwrap(),
        Settings::default()
    );
}

// ---- Wire format -----------------------------------------------------------------------------------------------

#[test]
fn keys_are_camel_case_and_shortcut_names_snake_case() {
    let v = serde_json::to_value(customised()).unwrap();
    let mut keys: Vec<&str> = v.as_object().unwrap().keys().map(String::as_str).collect();
    keys.sort_unstable();
    assert_eq!(
        keys,
        [
            "activityNotifications",
            "afterCall",
            "afterCallNotice",
            "alwaysOffered",
            "appLock",
            "askUnknownApps",
            "askWhen",
            "assistCardPosition",
            "autoMode",
            "autoRecordApps",
            "autostart",
            "browserAutomation",
            "captionLines",
            "captionStyle",
            "captionsOnRecord",
            "cardHintSeen",
            "cardStreak",
            "clientId",
            "consentAcknowledged",
            "deviceId",
            "deviceName",
            "dictationCleanup",
            "dictationMode",
            "dictationOutput",
            "dictationReplacements",
            "dictationSnippets",
            "dictationSounds",
            "farSideWarning",
            "hideFromSharing",
            "inputDevice",
            "installId",
            "lockAfterMinutes",
            "mainShareHide",
            "notificationCategories",
            "notificationSounds",
            "oldWindowsSharing",
            "onboardingDone",
            "overlayCorner",
            "pillPosition",
            "pins",
            "quietFrom",
            "quietHours",
            "quietTo",
            "screenExcludedApps",
            "screenMoments",
            "screenTarget",
            "sendDiagnostics",
            "serverUrl",
            "shareNotice",
            "shareOneWindow",
            "shortcuts",
            "signInId",
            "siteRules",
            "skipCallsShorterThanS",
            "transcriptionLanguage"
        ]
    );
    assert_eq!(keys.len(), 55, "section 4.7 of the IPC contract, with the call choice's four keys and the sign-in id");
    assert_eq!(v["mainShareHide"], json!({"until": {"kind": "at", "at_ms": 1_795_000_000_000_i64}, "sinceMs": 1_794_999_100_000_i64}));
    assert_eq!(v["dictationMode"], "push_to_talk");
    assert_eq!(v["dictationReplacements"][0]["to"], "as soon as possible");
    assert_eq!(v["transcriptionLanguage"], "ur");
    assert_eq!(v["autoRecordApps"], json!({"zoom": false}));
    assert_eq!(
        v["captionStyle"],
        json!({"size": "large", "width": "wide", "opacity": 60, "compact": true, "autoHideSeconds": 10, "meColor": "#22c55e", "clientColor": null})
    );
    assert_eq!(v["afterCall"], "ask");
    assert_eq!(v["skipCallsShorterThanS"], 30);
    assert_eq!(v["captionLines"], 5);
    assert_eq!(v["pillPosition"], json!({"x": -1_520, "y": 64}));
    assert_eq!(v["siteRules"][0]["titleContains"], "Private notes");
    assert!(v["siteRules"][0]["titleEquals"].is_null());
    assert_eq!(v["overlayCorner"], "top_left");
    assert_eq!(v["shortcuts"]["voice_note"], "Ctrl+Alt+V");
    assert_eq!(
        v["shortcuts"]["toggle_captions"],
        "CommandOrControl+Shift+Space"
    );
}

#[test]
fn every_field_the_ui_declares_exists_and_vice_versa() {
    let ts = std::fs::read_to_string(
        std::path::Path::new(env!("CARGO_MANIFEST_DIR"))
            .join("..")
            .join("src")
            .join("lib")
            .join("ipc.ts"),
    )
    .expect("ipc.ts");
    let start = ts
        .find("export interface Settings")
        .expect("Settings interface");
    let body: String = ts[start..]
        .lines()
        .skip(1)
        .take_while(|l| !l.starts_with('}'))
        .collect::<Vec<_>>()
        .join("\n");
    let declared: Vec<&str> = body
        .lines()
        .filter_map(|l| l.trim().split(':').next())
        .filter(|k| !k.is_empty())
        .collect();
    let serialised = serde_json::to_value(Settings::default()).unwrap();
    let mut produced: Vec<&str> = serialised
        .as_object()
        .unwrap()
        .keys()
        .map(String::as_str)
        .collect();
    let mut declared_sorted = declared.clone();
    produced.sort_unstable();
    declared_sorted.sort_unstable();
    assert_eq!(
        produced, declared_sorted,
        "Rust Settings vs the TypeScript interface"
    );
}

// ---- Persistence -----------------------------------------------------------------------------------------------

#[test]
fn every_setting_survives_a_save_and_load() {
    let d = dir();
    let path = d.path().join("settings.json");
    let original = customised();
    original.save(&path).unwrap();
    assert_eq!(Settings::load(&path), original);
}

#[test]
fn saving_writes_readable_json_and_leaves_no_temporary_file() {
    let d = dir();
    let path = d.path().join("settings.json");
    Settings::default().save(&path).unwrap();
    let text = std::fs::read_to_string(&path).unwrap();
    assert!(
        text.contains('\n'),
        "pretty-printed so it can be inspected by hand"
    );
    assert!(serde_json::from_str::<Value>(&text).is_ok());
    assert!(!d.path().join("settings.json.tmp").exists());
    let names: Vec<_> = std::fs::read_dir(d.path())
        .unwrap()
        .map(|e| e.unwrap().file_name())
        .collect();
    assert_eq!(names.len(), 1, "{names:?}");
}

#[test]
fn saving_replaces_the_previous_file() {
    let d = dir();
    let path = d.path().join("settings.json");
    customised().save(&path).unwrap();
    Settings::default().save(&path).unwrap();
    assert_eq!(Settings::load(&path), Settings::default());
}

#[test]
fn saving_into_a_missing_directory_fails_without_panicking() {
    let d = dir();
    assert!(
        Settings::default()
            .save(&d.path().join("nope").join("settings.json"))
            .is_err()
    );
}

#[test]
fn a_failed_save_leaves_the_existing_file_untouched() {
    let d = dir();
    let path = d.path().join("settings.json");
    customised().save(&path).unwrap();
    // Occupy the temporary file's name with a directory so the new copy cannot be written.
    std::fs::create_dir(d.path().join("settings.json.tmp")).unwrap();
    assert!(Settings::default().save(&path).is_err());
    assert_eq!(
        Settings::load(&path),
        customised(),
        "the previous settings must still be intact"
    );
}

#[test]
fn a_missing_empty_or_corrupt_file_loads_the_defaults() {
    let d = dir();
    let path = d.path().join("settings.json");
    assert_eq!(Settings::load(&path), Settings::default());
    for content in ["", "   ", "not json", "{", "[]", "null", "42", "\"text\""] {
        std::fs::write(&path, content).unwrap();
        assert_eq!(Settings::load(&path), Settings::default(), "{content:?}");
    }
    std::fs::write(&path, [0xff, 0xfe, 0x00, 0x01]).unwrap();
    assert_eq!(Settings::load(&path), Settings::default(), "binary garbage");
}

// ---- Compatibility ---------------------------------------------------------------------------------------------

#[test]
fn a_file_with_only_some_fields_keeps_the_defaults_for_the_rest() {
    let d = dir();
    let path = d.path().join("settings.json");
    std::fs::write(
        &path,
        r#"{"autoMode": false, "serverUrl": "https://ledger.example.com"}"#,
    )
    .unwrap();
    let s = Settings::load(&path);
    assert!(!s.auto_mode);
    assert_eq!(s.server_url.as_deref(), Some("https://ledger.example.com"));
    assert!(s.captions_on_record && s.hide_from_sharing == ShareHiding::All);
    assert_eq!(s.overlay_corner, Corner::BottomRight);
    assert_eq!(s.shortcuts, default_map());
}

#[test]
fn fields_from_a_newer_version_are_ignored() {
    let d = dir();
    let path = d.path().join("settings.json");
    std::fs::write(&path, r#"{"autoMode": false, "theme": "solarized", "experimental": {"a": [1, 2, 3]}, "serverUrl": "https://ledger.example.com"}"#).unwrap();
    let s = Settings::load(&path);
    assert!(!s.auto_mode);
    assert_eq!(s.server_url.as_deref(), Some("https://ledger.example.com"));
}

#[test]
fn explicit_nulls_for_optional_fields_are_accepted() {
    let s: Settings = serde_json::from_value(
        json!({"serverUrl": null, "deviceId": null, "deviceName": null, "clientId": null}),
    )
    .unwrap();
    assert_eq!(s, Settings::default());
}

#[test]
fn a_shortcut_for_an_action_this_version_does_not_know_does_not_discard_the_pairing() {
    // Opening a settings file written by a newer build (or one edited by hand) must not reset the server URL and pins.
    let d = dir();
    let path = d.path().join("settings.json");
    std::fs::write(
        &path,
        r#"{"serverUrl": "https://ledger.example.com", "pins": ["sha256/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA="],
            "shortcuts": {"toggle_captions": "Ctrl+Shift+K", "pause_recording": "Ctrl+Shift+P"}}"#,
    )
    .unwrap();
    let s = Settings::load(&path);
    assert_eq!(
        s.server_url.as_deref(),
        Some("https://ledger.example.com"),
        "the whole file was thrown away"
    );
    assert_eq!(s.pins.len(), 1);
}

#[test]
fn an_overlay_corner_this_version_does_not_know_does_not_discard_the_pairing() {
    let d = dir();
    let path = d.path().join("settings.json");
    std::fs::write(
        &path,
        r#"{"serverUrl": "https://ledger.example.com", "overlayCorner": "center"}"#,
    )
    .unwrap();
    let s = Settings::load(&path);
    assert_eq!(
        s.server_url.as_deref(),
        Some("https://ledger.example.com"),
        "the whole file was thrown away"
    );
}

#[test]
fn an_unknown_shortcut_action_is_dropped_and_the_known_ones_are_kept() {
    let s = Settings::from_value(json!({
        "shortcuts": {"toggle_captions": "Ctrl+Shift+K", "pause_recording": "Ctrl+Shift+P", "voice_note": 42}
    }));
    assert_eq!(s.shortcuts.len(), 4, "{:?}", s.shortcuts);
    assert_eq!(s.shortcuts[&Action::ToggleCaptions], "Ctrl+Shift+K");
    assert_eq!(s.shortcuts[&Action::HideAll], "CommandOrControl+Alt+Shift+P");
    assert_eq!(s.shortcuts[&Action::CaptureScreen], "CommandOrControl+Alt+S", "Capture screen now, added later, too");
    assert_eq!(s.shortcuts[&Action::RecordNow], "CommandOrControl+Alt+R", "a shortcut added later gets its default");
}

#[test]
fn a_cleared_record_now_stays_cleared() {
    let s = Settings::from_value(json!({"shortcuts": {"record_now": ""}}));
    assert_eq!(s.shortcuts[&Action::RecordNow], "");
}

#[test]
fn one_bad_value_costs_only_its_own_key() {
    let s = Settings::from_value(json!({
        "serverUrl": "https://ledger.example.com",
        "autoMode": "sometimes",
        "afterCall": "on_tuesdays",
        "pillPosition": {"x": "left", "y": 3},
        "hideFromSharing": "sometimes"
    }));
    assert_eq!(s.server_url.as_deref(), Some("https://ledger.example.com"));
    assert!(s.auto_mode, "an invalid value falls back to the default");
    assert_eq!(s.after_call, AfterCall::Ask);
    assert_eq!(s.pill_position, None);
    assert_eq!(s.hide_from_sharing, ShareHiding::All, "an unknown choice is the default");
}

#[test]
fn show_during_screen_share_from_v1_8_keeps_echoledger_visible_in_shares() {
    // v1.8 "Show during screen share" on: the floating windows were in shares, and the main window always was.
    let s = Settings::from_value(json!({"serverUrl": "https://ledger.example.com", "contentProtection": false}));
    assert_eq!(s.hide_from_sharing, ShareHiding::Off);
    // The default (hidden captions) moves to the new default: the other floating windows hidden (the card and the
    // captions are hidden whatever this says, and the main window has its own choice).
    assert_eq!(Settings::from_value(json!({"contentProtection": true})).hide_from_sharing, ShareHiding::All);
    assert_eq!(Settings::from_value(json!({"autoMode": true})).hide_from_sharing, ShareHiding::All);
    // Once the new setting is in the file, the old key no longer counts.
    assert_eq!(Settings::from_value(json!({"contentProtection": false, "hideFromSharing": "floating"})).hide_from_sharing, ShareHiding::Floating);
    let saved = serde_json::to_value(&s).unwrap();
    assert!(saved.get("contentProtection").is_none(), "the old key is not written again");
    assert_eq!(saved["hideFromSharing"], "off");
}

#[test]
fn defaults_show_the_main_window_and_hide_the_card_and_the_captions() {
    let s = Settings::default();
    assert_eq!(s.main_share_hide, None, "the main window is shown in screen shares");
    assert!(!s.share_notice, "a new install has nothing to be told");
    let fresh = Settings::from_value(json!({}));
    assert!(fresh.main_share_hide.is_none() && !fresh.share_notice);
    // The card's and the captions' choice is never kept in the file: it lasts until that window closes, and every start
    // begins hidden (privacy::Privacy::shown_in_shares).
    let saved = serde_json::to_value(&s).unwrap();
    assert!(saved.get("showCardInShares").is_none() && saved.get("showCaptionsInShares").is_none());
}

#[test]
fn the_old_share_setting_migrates_to_main_shown_and_card_and_captions_hidden() {
    // The table: "all", "floating" and "off" (and v1.8's key) all become the main window shown; the card and the captions
    // are hidden after every migration, and the person is told once.
    let table = [
        json!({"serverUrl": "https://ledger.example.com", "hideFromSharing": "all"}),
        json!({"serverUrl": "https://ledger.example.com", "hideFromSharing": "floating"}),
        json!({"serverUrl": "https://ledger.example.com", "hideFromSharing": "off"}),
        json!({"serverUrl": "https://ledger.example.com", "contentProtection": false}),
        json!({"serverUrl": "https://ledger.example.com", "contentProtection": true}),
        // Even a file that somehow carries a hide or a shown card without the new switches: a migration never shows them.
        json!({"hideFromSharing": "off", "showCaptionsInShares": true, "mainShareHide": {"until": {"kind": "until_off"}, "sinceMs": 1}}),
    ];
    for file in table {
        let s = Settings::from_value(file.clone());
        assert_eq!(s.main_share_hide, None, "{file}: the main window is shown");
        assert!(s.share_notice, "{file}: told once");
    }
    // The other floating windows keep what the person had.
    assert_eq!(Settings::from_value(json!({"hideFromSharing": "off"})).hide_from_sharing, ShareHiding::Off);
    assert_eq!(Settings::from_value(json!({"hideFromSharing": "floating"})).hide_from_sharing, ShareHiding::Floating);
    // Once saved by this version, nothing migrates again: the person's own choices are kept, the notice stays answered.
    let dir = dir();
    let path = dir.path().join("settings.json");
    let mut s = Settings::from_value(json!({"hideFromSharing": "all"}));
    s.share_notice = false;
    s.main_share_hide = Some(crate::privacy::share::MainHide { until: crate::privacy::share::HideUntil::UntilOff, since_ms: 5 });
    s.save(&path).unwrap();
    let again = Settings::load(&path);
    assert!(!again.share_notice, "shown once, remembered");
    assert_eq!(again.main_share_hide, s.main_share_hide);
}

#[test]
fn the_hide_all_shortcut_is_added_to_an_older_file() {
    let s = Settings::from_value(json!({"shortcuts": {"toggle_captions": "CommandOrControl+Shift+Space"}}));
    assert_eq!(s.shortcuts.get(&Action::HideAll).map(String::as_str), Some("CommandOrControl+Alt+Shift+P"));
    let cleared = Settings::from_value(json!({"shortcuts": {"hide_all": ""}}));
    assert_eq!(cleared.shortcuts.get(&Action::HideAll).map(String::as_str), Some(""), "a cleared one stays cleared");
}

#[test]
fn caption_lines_other_than_two_three_or_five_load_as_three() {
    for (stored, expected) in [
        (json!(2), 2),
        (json!(3), 3),
        (json!(5), 5),
        (json!(4), 3),
        (json!(0), 3),
        (json!(300), 3),
        (json!(-2), 3),
        (json!("5"), 3),
        (json!(null), 3),
    ] {
        let s = Settings::from_value(json!({"captionLines": stored.clone(), "autoMode": false}));
        assert_eq!(s.caption_lines, expected, "{stored}");
        assert!(!s.auto_mode, "{stored}: the rest of the file is kept");
    }
    assert_eq!(caption_lines_or_default(Some(5)), 5);
    assert_eq!(caption_lines_or_default(Some(6)), 3);
    assert_eq!(caption_lines_or_default(None), 3);
}

#[test]
fn site_rules_that_do_not_parse_are_dropped_one_by_one() {
    let good = json!({"id": "6f1c2d3e-4b5a-4c7d-8e9f-0a1b2c3d4e5f", "app": "never", "host": "notes.example.test",
        "titlePrefix": null, "titleContains": null, "titleEquals": null, "priority": 100, "enabled": true});
    let s = Settings::from_value(json!({
        "serverUrl": "https://ledger.example.com",
        "siteRules": [good.clone(), {"id": "x", "app": "zoom"}, "garbage", {"id": "y", "app": "zoom", "host": null,
            "titlePrefix": null, "titleContains": null, "titleEquals": null, "priority": 900, "enabled": true}]
    }));
    assert_eq!(s.site_rules.len(), 1);
    assert_eq!(serde_json::to_value(&s.site_rules[0]).unwrap(), good);
    assert_eq!(s.server_url.as_deref(), Some("https://ledger.example.com"));
    assert!(Settings::from_value(json!({"siteRules": {"not": "a list"}})).site_rules.is_empty());
}

#[test]
fn a_settings_file_from_version_one_loads_with_the_new_defaults() {
    let d = dir();
    let path = d.path().join("settings.json");
    std::fs::write(
        &path,
        r#"{"serverUrl": "https://ledger.example.com", "pins": [], "deviceId": "01JB8Q3R0Z5V1K7M9N2P4S6T8W",
            "deviceName": "Office", "shortcuts": {"toggle_captions": "CommandOrControl+Shift+Space"},
            "captionsOnRecord": true, "autoMode": true, "contentProtection": true, "overlayCorner": "top_right",
            "autostart": false, "dictationOutput": "type", "clientId": null, "consentAcknowledged": true}"#,
    )
    .unwrap();
    let s = Settings::load(&path);
    assert_eq!(s.device_name.as_deref(), Some("Office"));
    assert_eq!(s.overlay_corner, Corner::TopRight);
    assert_eq!(
        (s.after_call, s.caption_lines, s.pill_position),
        (AfterCall::Ask, 3, None)
    );
}

#[test]
fn a_file_from_before_after_call_loads_as_ask_whatever_it_asked_before() {
    // Contract 1.8.0-e: permission first, so every older file becomes Ask. The old keys still read cleanly.
    let d = dir();
    let path = d.path().join("settings.json");
    for (ask, timeout) in [("always", "wait"), ("auto_only", "discard"), ("never", "keep"), ("sometimes", "later")] {
        std::fs::write(
            &path,
            format!(r#"{{"serverUrl": "https://ledger.example.com", "deviceId": "01JB8Q3R0Z5V1K7M9N2P4S6T8W", "askBeforeSaving": "{ask}", "decisionTimeout": "{timeout}", "autoMode": false}}"#),
        )
        .unwrap();
        let s = Settings::load(&path);
        assert_eq!(s.after_call, AfterCall::Ask, "{ask} + {timeout}");
        assert_eq!(s.server_url.as_deref(), Some("https://ledger.example.com"), "the pairing is kept");
        assert!(!s.auto_mode, "the other settings are kept");
    }
    let s = Settings::load(&path);
    s.save(&path).unwrap();
    let written: Value = serde_json::from_slice(&std::fs::read(&path).unwrap()).unwrap();
    assert_eq!(written["afterCall"], "ask");
    assert!(written.get("askBeforeSaving").is_none() && written.get("decisionTimeout").is_none(), "the next save drops the old keys");
}

#[test]
fn skip_calls_shorter_than_takes_only_the_offered_choices() {
    for (stored, loaded) in [(json!(0), 0), (json!(5), 5), (json!(20), 20), (json!(30), 30), (json!(7), 10), (json!(-5), 10), (json!("10"), 10), (json!(4_294_967_296_u64), 10)] {
        assert_eq!(Settings::from_value(json!({"skipCallsShorterThanS": stored})).skip_calls_shorter_than_s, loaded, "{stored}");
    }
    let s = Settings::from_value(json!({"serverUrl": "https://ledger.example.com", "skipCallsShorterThanS": 99}));
    assert_eq!(s.server_url.as_deref(), Some("https://ledger.example.com"), "a bad value costs only its own key");
    let missing = Settings::from_value(json!({"serverUrl": "https://ledger.example.com"}));
    assert_eq!(missing.skip_calls_shorter_than_s, 10, "a file from before v1.5 skips calls under 10 s");
}

#[test]
fn every_after_a_call_choice_survives_a_save_and_load_and_v1_5_s_choices_become_ask() {
    let d = dir();
    let path = d.path().join("settings.json");
    for (choice, written) in [(AfterCall::Ask, "ask"), (AfterCall::AlwaysTranscribe, "always_transcribe"), (AfterCall::AlwaysAudio, "always_audio")] {
        Settings { after_call: choice, ..Settings::default() }.save(&path).unwrap();
        assert_eq!(Settings::load(&path).after_call, choice);
        let file: Value = serde_json::from_slice(&std::fs::read(&path).unwrap()).unwrap();
        assert_eq!(file["afterCall"], written);
    }
    // v1.5's "Keep automatically" and "Ask me" both migrate to ask (contract 1.8.0-e), and are written back as ask.
    std::fs::write(&path, r#"{"serverUrl": "https://ledger.example.com", "afterCall": "keep"}"#).unwrap();
    let s = Settings::load(&path);
    assert_eq!(s.after_call, AfterCall::Ask);
    assert_eq!(s.server_url.as_deref(), Some("https://ledger.example.com"));
    s.save(&path).unwrap();
    let file: Value = serde_json::from_slice(&std::fs::read(&path).unwrap()).unwrap();
    assert_eq!(file["afterCall"], "ask");
}

#[test]
fn keep_automatically_becoming_ask_me_is_told_once() {
    let d = dir();
    let path = d.path().join("settings.json");
    std::fs::write(&path, r#"{"serverUrl": "https://ledger.example.com", "afterCall": "keep"}"#).unwrap();
    let s = Settings::load(&path);
    assert!(s.after_call_notice, "a person who kept every call is told");
    // Saved as ask: the next start finds the notice, not "keep" again.
    s.save(&path).unwrap();
    let again = Settings::load(&path);
    assert!(again.after_call_notice && again.after_call == AfterCall::Ask);
    // Answered (either way): never again.
    Settings { after_call_notice: false, ..again }.save(&path).unwrap();
    assert!(!Settings::load(&path).after_call_notice);
    for choice in ["ask", "always_transcribe", "always_audio"] {
        assert!(!Settings::from_value(json!({"afterCall": choice})).after_call_notice, "{choice}: nothing changed for them");
    }
    assert!(!Settings::from_value(json!({})).after_call_notice, "a new install has nothing to be told");
    assert!(crate::settings::AFTER_CALL_NOTICE.ends_with("choose Transcribe every call"), "it names the button");
}

#[test]
fn the_pill_position_survives_a_save_and_load_including_negative_coordinates() {
    let d = dir();
    let path = d.path().join("settings.json");
    let s = Settings { pill_position: Some(PillPosition { x: -2_400, y: -300 }), ..Settings::default() };
    s.save(&path).unwrap();
    assert_eq!(Settings::load(&path).pill_position, Some(PillPosition { x: -2_400, y: -300 }));
}

#[test]
fn settings_survive_many_save_load_cycles() {
    let d = dir();
    let path = d.path().join("settings.json");
    let mut s = customised();
    for round in 0..50 {
        s.device_name = Some(format!("device {round}"));
        s.save(&path).unwrap();
        s = Settings::load(&path);
    }
    assert_eq!(s.device_name.as_deref(), Some("device 49"));
    assert_eq!(s.pins.len(), 2);
}

#[test]
fn auto_record_switches_keep_known_apps_with_a_boolean() {
    let s = Settings::from_value(json!({
        "autoRecordApps": {"zoom": false, "whatsapp": true, "skype": false, "google_meet": "no"},
        "autoMode": false
    }));
    assert_eq!(s.auto_record_apps, HashMap::from([("zoom".to_string(), false), ("whatsapp".to_string(), true)]));
    assert!(!s.auto_mode, "the rest of the file is kept");
    assert!(Settings::from_value(json!({"autoRecordApps": ["zoom"]})).auto_record_apps.is_empty());
}

#[test]
fn a_bad_caption_style_value_costs_that_value_only() {
    let s = Settings::from_value(json!({
        "captionStyle": {"size": "huge", "width": "wide", "opacity": 7, "compact": true, "autoHideSeconds": 12,
            "meColor": "green", "clientColor": "#F59E0B"},
        "farSideWarning": false
    }));
    let style = s.caption_style;
    assert_eq!(style.size, CaptionSize::Medium, "unknown size → default");
    assert_eq!(style.width, CaptionWidth::Wide);
    assert_eq!(style.opacity, 85, "out of range → default");
    assert!(style.compact);
    assert_eq!(style.auto_hide_seconds, 0, "not one of 0 / 5 / 10 / 30 → never");
    assert_eq!(style.me_color, None, "not #rrggbb → theme colour");
    assert_eq!(style.client_color.as_deref(), Some("#F59E0B"));
    assert!(!s.far_side_warning);
    assert_eq!(Settings::from_value(json!({"captionStyle": "big"})).caption_style, CaptionStyle::default());
}

#[test]
fn caption_style_problems_name_the_value_to_fix() {
    let ok = CaptionStyle::default();
    assert_eq!(ok.problem(), None);
    assert!(CaptionStyle { opacity: 39, ..ok.clone() }.problem().unwrap().contains("opacity"));
    assert!(CaptionStyle { opacity: 101, ..ok.clone() }.problem().is_some());
    assert!(CaptionStyle { auto_hide_seconds: 7, ..ok.clone() }.problem().unwrap().contains("seconds"));
    assert!(CaptionStyle { client_color: Some("#12345".into()), ..ok.clone() }.problem().unwrap().contains("colour"));
    assert_eq!(CaptionStyle { opacity: 40, auto_hide_seconds: 30, me_color: Some("#ABCDEF".into()), ..ok }.problem(), None);
    assert!(is_hex_colour("#00ff7A") && !is_hex_colour("00ff7A") && !is_hex_colour("#00ff7G"));
}

#[test]
fn the_microphone_choice_is_kept_or_cleared() {
    let s = Settings::from_value(json!({"inputDevice": "usb-mic"}));
    assert_eq!(s.input_device.as_deref(), Some("usb-mic"));
    assert_eq!(Settings::from_value(json!({"inputDevice": null})).input_device, None);
    assert_eq!(Settings::from_value(json!({"inputDevice": 3})).input_device, None);
}

#[test]
fn old_languages_read_as_today_and_have_short_chips() {
    use crate::settings::{language_chip, language_or_none};
    // v1.1 values: the mixed choices and Hindi are Urdu (Urdu always allows English words).
    assert_eq!(language_or_none(Some("ur+en")).as_deref(), Some("ur"));
    assert_eq!(language_or_none(Some("en+ur")).as_deref(), Some("ur"));
    assert_eq!(language_or_none(Some("HI + EN")).as_deref(), Some("ur"));
    assert_eq!(language_or_none(Some("hi")).as_deref(), Some("ur"));
    assert_eq!(language_or_none(Some("en+ar")).as_deref(), Some("ar"));
    assert_eq!(language_or_none(Some("ar-EG")).as_deref(), Some("ar"));
    assert_eq!(language_or_none(Some("fr")).as_deref(), Some("fr"));
    assert_eq!(language_or_none(Some("ja")), None);
    assert_eq!(language_or_none(Some("")), None);
    assert_eq!(language_chip(Some("ur")), "UR");
    assert_eq!(language_chip(Some("en+ur")), "UR");
    assert_eq!(language_chip(Some("de")), "DE");
    assert_eq!(language_chip(None), "Auto");
}

#[test]
fn only_the_offered_languages_are_kept() {
    for (stored, expected) in [
        (json!("ur"), Some("ur")),
        (json!(" HI "), Some("ur")),
        (json!("en+ur"), Some("ur")),
        (json!("ar"), Some("ar")),
        (json!("fr"), Some("fr")),
        (json!("xx"), None),
        (json!(5), None),
        (json!(null), None),
    ] {
        assert_eq!(Settings::from_value(json!({"transcriptionLanguage": stored.clone()})).transcription_language.as_deref(), expected, "{stored}");
    }
}
#[test]
fn dictation_settings_load_tolerantly() {
    let s = Settings::from_value(json!({
        "dictationMode": "shout", "dictationSounds": false,
        "dictationReplacements": [{"from": "asap", "to": "as soon as possible"}, "junk", 5],
        "dictationSnippets": {"not": "a list"}
    }));
    assert_eq!(s.dictation_mode, "toggle");
    assert!(!s.dictation_sounds);
    assert_eq!(s.dictation_replacements.len(), 1);
    assert!(s.dictation_snippets.is_empty());
}

#[test]
fn app_lock_settings_load_tolerantly() {
    let s = Settings::from_value(json!({"appLock": true, "lockAfterMinutes": 15}));
    assert!(s.app_lock);
    assert_eq!(s.lock_after_minutes, 15);
    assert_eq!(Settings::from_value(json!({"lockAfterMinutes": 7})).lock_after_minutes, 5, "not a choice: the default");
    assert_eq!(Settings::from_value(json!({"lockAfterMinutes": "soon"})).lock_after_minutes, 5);
    assert!(!Settings::default().app_lock);
}

#[test]
fn quiet_hours_load_tolerantly() {
    let s = Settings::from_value(json!({"quietHours": true, "quietFrom": "21:45", "quietTo": "25:00"}));
    assert!(s.quiet_hours && s.activity_notifications);
    assert_eq!((s.quiet_from.as_str(), s.quiet_to.as_str()), ("21:45", "07:00"), "an impossible time falls back");
}

#[test]
fn installs_paired_before_the_wizard_count_as_set_up() {
    assert!(Settings::from_value(json!({"deviceId": "01JB8Q3R0Z5V1K7M9N2P4S6T8W"})).onboarding_done);
    assert!(!Settings::from_value(json!({"deviceId": null})).onboarding_done);
    assert!(!Settings::from_value(json!({"deviceId": "01JB8Q3R0Z5V1K7M9N2P4S6T8W", "onboardingDone": false})).onboarding_done, "an explicit value wins");
    assert!(!Settings::default().onboarding_done);
}

#[test]
fn after_each_call_migrates_every_earlier_choice_without_losing_one() {
    // The call choice reads the files people have today: their After a call becomes the same After each call, "Record
    // calls automatically" off reads as Don't record calls, and the choice under it is kept for when calls are recorded
    // again. Nothing is rewritten: the keys stay as they were.
    let cases = [
        (json!({"afterCall": "ask"}), AfterEachCall::Ask),
        (json!({"afterCall": "always_transcribe"}), AfterEachCall::AlwaysTranscribe),
        (json!({"afterCall": "always_audio"}), AfterEachCall::AlwaysAudio),
        (json!({"afterCall": "keep"}), AfterEachCall::Ask),
        (json!({"askBeforeSaving": "never", "decisionTimeout": "keep"}), AfterEachCall::Ask),
        (json!({}), AfterEachCall::Ask),
        (json!({"autoMode": false, "afterCall": "always_audio"}), AfterEachCall::Off),
    ];
    for (file, want) in cases {
        let s = Settings::from_value(file.clone());
        assert_eq!(s.after_each_call(), want, "{file}");
        assert_eq!((s.ask_when, s.card_hint_seen, s.card_streak, s.always_offered), (AskWhen::After, false, None, false), "{file}: new keys start at their defaults");
    }
    let mut off = Settings::from_value(json!({"autoMode": false, "afterCall": "always_audio"}));
    assert_eq!(off.after_call, AfterCall::AlwaysAudio, "kept under Don't record calls");
    off.set_after_each_call(AfterEachCall::Off);
    assert_eq!((off.auto_mode, off.after_call), (false, AfterCall::AlwaysAudio));
    off.auto_mode = true; // the tray's "Record calls automatically" turned on again
    assert_eq!(off.after_each_call(), AfterEachCall::AlwaysAudio, "the choice comes back");
    for value in [AfterEachCall::Ask, AfterEachCall::AlwaysTranscribe, AfterEachCall::AlwaysAudio, AfterEachCall::Off] {
        let mut s = Settings { after_call_notice: true, ..Settings::default() };
        s.set_after_each_call(value);
        assert_eq!(s.after_each_call(), value);
        assert!(!s.after_call_notice, "choosing answers the one-time notice");
    }
    // The new keys survive a save; an unknown "When to ask" costs only that key.
    let d = dir();
    let path = d.path().join("settings.json");
    Settings { ask_when: AskWhen::Start, ..Settings::default() }.save(&path).unwrap();
    let file: Value = serde_json::from_slice(&std::fs::read(&path).unwrap()).unwrap();
    assert_eq!(file["askWhen"], "start");
    assert_eq!(Settings::load(&path).ask_when, AskWhen::Start);
    let odd = Settings::from_value(json!({"askWhen": "sometimes", "afterCall": "always_transcribe"}));
    assert_eq!((odd.ask_when, odd.after_call), (AskWhen::After, AfterCall::AlwaysTranscribe));
}
