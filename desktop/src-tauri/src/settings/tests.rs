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
        shortcuts,
        captions_on_record: false,
        auto_mode: false,
        content_protection: false,
        overlay_corner: Corner::TopLeft,
        autostart: true,
        dictation_output: "clipboard".into(),
        client_id: Some("01JB8Q4C1D2E3F4G5H6J7K8M9N".into()),
        consent_acknowledged: true,
        ask_before_saving: AskBeforeSaving::AutoOnly,
        decision_timeout: DecisionTimeout::Wait,
        caption_lines: 5,
        pill_position: Some(PillPosition { x: -1_520, y: 64 }),
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
        transcription_language: Some("en+ur".into()),
        dictation_mode: "push_to_talk".into(),
        dictation_cleanup: false,
        dictation_sounds: false,
        dictation_replacements: vec![crate::dictation::rules::Replacement { from: "asap".into(), to: "as soon as possible".into() }],
        dictation_snippets: vec![crate::dictation::rules::Snippet { trigger: "sig".into(), text: "Regards".into() }],
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
    assert!(
        s.content_protection,
        "captions are hidden from screen sharing"
    );
    assert!(!s.autostart);
    assert!(!s.consent_acknowledged);
    assert_eq!(s.overlay_corner, Corner::BottomRight);
    assert_eq!(s.dictation_output, "type");
    assert_eq!(s.shortcuts, default_map());
    assert_eq!(s.shortcuts.len(), 5, "voice note has no default shortcut");
    assert!(!s.ask_unknown_apps, "the unknown-app chip is off by default");
    assert!(s.site_rules.is_empty(), "built-in site rules are never stored");
    assert!(!s.browser_automation, "reading browser tabs is opt-in");
    assert_eq!(s.ask_before_saving, AskBeforeSaving::Always);
    assert_eq!(s.decision_timeout, DecisionTimeout::Keep);
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
            "askBeforeSaving",
            "askUnknownApps",
            "autoMode",
            "autoRecordApps",
            "autostart",
            "browserAutomation",
            "captionLines",
            "captionStyle",
            "captionsOnRecord",
            "clientId",
            "consentAcknowledged",
            "contentProtection",
            "decisionTimeout",
            "deviceId",
            "deviceName",
            "dictationCleanup",
            "dictationMode",
            "dictationOutput",
            "dictationReplacements",
            "dictationSnippets",
            "dictationSounds",
            "farSideWarning",
            "inputDevice",
            "overlayCorner",
            "pillPosition",
            "pins",
            "serverUrl",
            "shortcuts",
            "siteRules",
            "transcriptionLanguage"
        ]
    );
    assert_eq!(keys.len(), 30, "section 4.7 of the IPC contract");
    assert_eq!(v["dictationMode"], "push_to_talk");
    assert_eq!(v["dictationReplacements"][0]["to"], "as soon as possible");
    assert_eq!(v["transcriptionLanguage"], "en+ur");
    assert_eq!(v["autoRecordApps"], json!({"zoom": false}));
    assert_eq!(
        v["captionStyle"],
        json!({"size": "large", "width": "wide", "opacity": 60, "compact": true, "autoHideSeconds": 10, "meColor": "#22c55e", "clientColor": null})
    );
    assert_eq!(v["askBeforeSaving"], "auto_only");
    assert_eq!(v["decisionTimeout"], "wait");
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
    assert!(s.captions_on_record && s.content_protection);
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
    assert_eq!(s.shortcuts.len(), 1, "{:?}", s.shortcuts);
    assert_eq!(s.shortcuts[&Action::ToggleCaptions], "Ctrl+Shift+K");
}

#[test]
fn one_bad_value_costs_only_its_own_key() {
    let s = Settings::from_value(json!({
        "serverUrl": "https://ledger.example.com",
        "autoMode": "sometimes",
        "askBeforeSaving": "on_tuesdays",
        "decisionTimeout": "discard",
        "pillPosition": {"x": "left", "y": 3},
        "contentProtection": false
    }));
    assert_eq!(s.server_url.as_deref(), Some("https://ledger.example.com"));
    assert!(s.auto_mode, "an invalid value falls back to the default");
    assert_eq!(s.ask_before_saving, AskBeforeSaving::Always);
    assert_eq!(s.decision_timeout, DecisionTimeout::Discard);
    assert_eq!(s.pill_position, None);
    assert!(!s.content_protection);
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
        (s.ask_before_saving, s.decision_timeout, s.caption_lines, s.pill_position),
        (AskBeforeSaving::Always, DecisionTimeout::Keep, 3, None)
    );
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
fn only_the_offered_languages_are_kept() {
    for (stored, expected) in [(json!("ur"), Some("ur")), (json!(" HI "), Some("hi")), (json!("en+ur"), Some("en+ur")), (json!("fr"), None), (json!(5), None), (json!(null), None)] {
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
