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
    assert_eq!(s.shortcuts.len(), 4);
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
            "autoMode",
            "autostart",
            "captionsOnRecord",
            "clientId",
            "consentAcknowledged",
            "contentProtection",
            "deviceId",
            "deviceName",
            "dictationOutput",
            "overlayCorner",
            "pins",
            "serverUrl",
            "shortcuts"
        ]
    );
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
#[ignore = "A6.DR13: an unknown shortcut action in settings.json must not discard the whole file"]
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
#[ignore = "A6.DR13: an unknown overlay corner in settings.json must not discard the whole file"]
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
