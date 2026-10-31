//! `update_settings` patches as the UI sends them (camelCase JSON, only the keys that change).

use serde_json::json;

use super::*;
use crate::settings::{AskBeforeSaving, DecisionTimeout};

fn patch(value: serde_json::Value) -> SettingsPatch {
    serde_json::from_value(value).expect("the UI's patch shape")
}

fn with_client() -> Settings {
    Settings { client_id: Some("01JB8Q4C1D2E3F4G5H6J7K8M9N".into()), ..Settings::default() }
}

#[test]
fn a_patch_without_client_id_keeps_the_selected_client() {
    let mut s = with_client();
    patch(json!({"autoMode": false})).apply(&mut s);
    assert_eq!(s.client_id.as_deref(), Some("01JB8Q4C1D2E3F4G5H6J7K8M9N"));
    assert!(!s.auto_mode);
}

#[test]
fn a_null_client_id_clears_the_client() {
    // A6.DU4: "No client" in Settings sends `clientId: null`.
    let mut s = with_client();
    patch(json!({"clientId": null})).apply(&mut s);
    assert_eq!(s.client_id, None);
}

#[test]
fn a_client_id_string_selects_that_client() {
    let mut s = with_client();
    patch(json!({"clientId": "01JB8Q9Z9Z9Z9Z9Z9Z9Z9Z9Z9Z"})).apply(&mut s);
    assert_eq!(s.client_id.as_deref(), Some("01JB8Q9Z9Z9Z9Z9Z9Z9Z9Z9Z9Z"));
    let mut fresh = Settings::default();
    patch(json!({"clientId": "01JB8Q9Z9Z9Z9Z9Z9Z9Z9Z9Z9Z"})).apply(&mut fresh);
    assert_eq!(fresh.client_id.as_deref(), Some("01JB8Q9Z9Z9Z9Z9Z9Z9Z9Z9Z9Z"));
}

#[test]
fn an_empty_patch_changes_nothing() {
    let mut s = with_client();
    patch(json!({})).apply(&mut s);
    assert_eq!(s, with_client());
}

#[test]
fn the_v1_1_keys_are_applied() {
    let mut s = Settings::default();
    patch(json!({"askBeforeSaving": "never", "decisionTimeout": "discard", "captionLines": 2})).apply(&mut s);
    assert_eq!((s.ask_before_saving, s.decision_timeout, s.caption_lines), (AskBeforeSaving::Never, DecisionTimeout::Discard, 2));
}

#[test]
fn an_unknown_dictation_output_is_ignored() {
    let mut s = Settings::default();
    patch(json!({"dictationOutput": "fax"})).apply(&mut s);
    assert_eq!(s.dictation_output, "type");
    patch(json!({"dictationOutput": "clipboard"})).apply(&mut s);
    assert_eq!(s.dictation_output, "clipboard");
}

#[test]
fn app_switches_merge_and_leave_other_apps_alone() {
    let mut s = Settings { auto_record_apps: HashMap::from([("zoom".to_string(), false)]), ..Settings::default() };
    patch(json!({"autoRecordApps": {"whatsapp": false, "zoom": true}})).apply(&mut s);
    assert_eq!(s.auto_record_apps, HashMap::from([("zoom".to_string(), true), ("whatsapp".to_string(), false)]));
}

#[test]
fn the_caption_style_is_replaced_whole_and_the_microphone_choice_can_be_cleared() {
    let mut s = Settings { input_device: Some("usb-mic".into()), ..Settings::default() };
    patch(json!({"captionStyle": {"size": "small", "opacity": 50}})).apply(&mut s);
    assert_eq!(s.caption_style.size, crate::settings::CaptionSize::Small);
    assert_eq!(s.caption_style.opacity, 50);
    assert_eq!(s.input_device.as_deref(), Some("usb-mic"), "a patch without inputDevice keeps it");
    patch(json!({"inputDevice": null, "farSideWarning": false})).apply(&mut s);
    assert_eq!(s.input_device, None);
    assert!(!s.far_side_warning);
}

#[test]
fn privacy_settings_open_the_right_system_page() {
    assert_eq!(privacy_settings_url("microphone", false), Some("ms-settings:privacy-microphone"));
    assert!(privacy_settings_url("microphone", true).unwrap().contains("Privacy_Microphone"));
    assert!(privacy_settings_url("system_audio", true).unwrap().contains("Privacy_ScreenCapture"));
    assert_eq!(privacy_settings_url("system_audio", false), None, "Windows needs no permission for system audio");
    assert_eq!(privacy_settings_url("camera", true), None);
}

#[test]
fn a_search_sends_only_the_filters_that_are_set() {
    let query = SearchQuery { q: "  invoice due ".into(), tag: Some("Budget".into()), source_app: Some(" ".into()), bookmarked: true, page: Some(2), ..SearchQuery::default() };
    assert_eq!(
        query.params(),
        vec![("q".to_string(), "invoice due".to_string()), ("tag".into(), "Budget".into()), ("bookmarked".into(), "1".into()), ("page".into(), "2".into())]
    );
    assert_eq!(SearchQuery { q: "x".into(), page: Some(1), ..SearchQuery::default() }.params().len(), 1, "page 1 is the default");
}

#[test]
fn an_export_never_overwrites_a_file() {
    let dir = tempfile::tempdir().unwrap();
    assert_eq!(export_target(dir.path(), "Call.txt"), dir.path().join("Call.txt"));
    std::fs::write(dir.path().join("Call.txt"), "x").unwrap();
    std::fs::write(dir.path().join("Call (2).txt"), "x").unwrap();
    assert_eq!(export_target(dir.path(), "Call.txt"), dir.path().join("Call (3).txt"));
    std::fs::write(dir.path().join("Notes"), "x").unwrap();
    assert_eq!(export_target(dir.path(), "Notes"), dir.path().join("Notes (2)"));
}

#[test]
fn the_transcription_language_is_set_cleared_or_kept() {
    let mut s = Settings::default();
    patch(json!({"transcriptionLanguage": "EN+UR"})).apply(&mut s);
    assert_eq!(s.transcription_language.as_deref(), Some("ur"), "a v1.1 mixed choice is Urdu");
    patch(json!({"autoMode": true})).apply(&mut s);
    assert_eq!(s.transcription_language.as_deref(), Some("ur"), "a patch without it keeps it");
    patch(json!({"transcriptionLanguage": null})).apply(&mut s);
    assert_eq!(s.transcription_language, None);
}

#[test]
fn dictation_settings_are_saved_cleaned() {
    let mut s = Settings::default();
    patch(json!({
        "dictationMode": "push_to_talk", "dictationCleanup": false,
        "dictationReplacements": [{"from": " asap ", "to": "as soon as possible"}, {"from": "", "to": "x"}],
        "dictationSnippets": [{"trigger": "sig", "text": "Regards"}]
    }))
    .apply(&mut s);
    assert_eq!(s.dictation_mode, "push_to_talk");
    assert!(!s.dictation_cleanup);
    assert_eq!(s.dictation_replacements.len(), 1);
    assert_eq!(s.dictation_replacements[0].from, "asap");
    assert_eq!(s.dictation_snippets[0].trigger, "sig");
    patch(json!({"dictationMode": "whisper"})).apply(&mut s);
    assert_eq!(s.dictation_mode, "push_to_talk", "an unknown mode is ignored");
}

// ---- library organisation (B15) ----

fn object(value: serde_json::Value) -> serde_json::Map<String, serde_json::Value> {
    value.as_object().cloned().unwrap()
}

#[test]
fn only_known_fields_are_sent() {
    let patch = object(json!({"pinned": true, "labels": ["Invoice"], "user_id": 7, "status": "failed"}));
    assert_eq!(serde_json::Value::Object(allowed_patch(&patch, &SESSION_PATCH_KEYS)), json!({"pinned": true, "labels": ["Invoice"]}));
    let client_patch = object(json!({"avatar": "AC", "user_id": 7}));
    assert_eq!(serde_json::Value::Object(allowed_patch(&client_patch, &CLIENT_PATCH_KEYS)), json!({"avatar": "AC"}));
}

#[test]
fn a_client_folder_change_may_switch_its_call_notes() {
    let patch = object(json!({"ai_notes": false, "workspace_id": 3}));
    assert_eq!(serde_json::Value::Object(allowed_patch(&patch, &CLIENT_PATCH_KEYS)), json!({"ai_notes": false}));
}

#[test]
fn bulk_requests_are_checked_first() {
    let ids = vec!["01JB8Q4C1D2E3F4G5H6J7K8M9N".to_string()];
    for action in BULK_ACTIONS {
        let label = matches!(action, "label" | "unlabel").then_some("Q4");
        assert!(check_bulk(&ids, action, label).is_ok(), "{action}");
    }
    assert!(check_bulk(&ids, "explode", None).is_err());
    assert!(check_bulk(&[], "pin", None).is_err());
    assert!(check_bulk(&vec!["01JB8Q4C1D2E3F4G5H6J7K8M9N".to_string(); 201], "pin", None).is_err());
    assert!(check_bulk(&ids, "label", Some("  ")).is_err());
    assert!(check_bulk(&ids, "unlabel", None).is_err());
}

#[test]
fn app_lock_settings_take_only_the_offered_minutes() {
    let mut s = Settings::default();
    patch(json!({"appLock": true, "lockAfterMinutes": 15})).apply(&mut s);
    assert!(s.app_lock);
    assert_eq!(s.lock_after_minutes, 15);
    patch(json!({"lockAfterMinutes": 7})).apply(&mut s);
    assert_eq!(s.lock_after_minutes, 15, "7 is not offered");
}

// ---- settings export / import (B19) ----

#[test]
fn an_export_leaves_out_the_pairing() {
    let s = Settings {
        server_url: Some("https://ledger.example.com".into()),
        device_id: Some("01JB8Q3R0Z5V1K7M9N2P4S6T8W".into()),
        client_id: Some("01JCLIENT00000000000000000".into()),
        caption_lines: 5,
        ..Settings::default()
    };
    let doc = portable_settings(&s);
    assert_eq!(doc["format"], SETTINGS_FORMAT);
    let settings = doc["settings"].as_object().unwrap();
    for key in NOT_PORTABLE {
        assert!(!settings.contains_key(key), "{key}");
    }
    assert_eq!(settings["captionLines"], 5);
    assert!(settings.contains_key("shortcuts"));
}

#[test]
fn an_import_takes_known_values_and_drops_the_rest() {
    let text = json!({
        "format": SETTINGS_FORMAT, "version": 1,
        "settings": {"captionLines": 2, "dictationMode": "push_to_talk", "serverUrl": "https://evil.example", "futureSetting": {"x": 1},
                     "quietHours": "not a bool", "shortcuts": {"dictation": "CommandOrControl+Alt+K"}}
    }).to_string();
    let (patch, shortcuts) = read_portable(&text).unwrap();
    let mut s = Settings::default();
    patch.apply(&mut s);
    assert_eq!(s.caption_lines, 2);
    assert_eq!(s.dictation_mode, "push_to_talk");
    assert_eq!(s.server_url, None, "the pairing never comes from a file");
    assert!(!s.quiet_hours);
    assert_eq!(shortcuts.unwrap()["dictation"], "CommandOrControl+Alt+K");
}

#[test]
fn an_import_never_acknowledges_the_consent_notice() {
    let text = json!({"format": SETTINGS_FORMAT, "version": 1, "settings": {"consentAcknowledged": true, "captionLines": 3}}).to_string();
    let (patch, _) = read_portable(&text).unwrap();
    let mut s = Settings::default();
    patch.apply(&mut s);
    assert!(!s.consent_acknowledged, "only the person using this install acknowledges the notice");
    assert_eq!(s.caption_lines, 3);
    let own = Settings { consent_acknowledged: true, ..Settings::default() };
    assert!(!portable_settings(&own)["settings"].as_object().unwrap().contains_key("consentAcknowledged"));
}

#[test]
fn only_settings_files_are_imported() {
    for text in ["", "{}", "[1]", r#"{"format":"something-else","settings":{}}"#, r#"{"format":"echoledger-settings"}"#] {
        assert!(read_portable(text).is_err(), "{text}");
    }
}

// ---- client workspaces (v1.4) ----

#[test]
fn memory_lines_need_a_known_kind_and_some_text() {
    for kind in MEMORY_KINDS {
        assert_eq!(check_memory_line(kind, "  Use Stripe \n").unwrap(), "Use Stripe", "{kind}");
    }
    assert!(check_memory_line("idea", "Use Stripe").is_err());
    assert!(check_memory_line("Decision", "Use Stripe").is_err(), "kinds are the server's lower-case names");
    assert!(check_memory_line("decision", " \n\t ").is_err());
    assert!(check_memory_line("decision", &"x".repeat(MAX_MEMORY_CHARS + 1)).is_err());
    assert!(check_memory_line("decision", &"ب".repeat(MAX_MEMORY_CHARS)).is_ok(), "characters are counted, not bytes");
    let patch = object(json!({"text": "Launch on November 15", "pinned": true, "kind": "scope", "source": {"type": "user"}}));
    assert_eq!(serde_json::Value::Object(allowed_patch(&patch, &MEMORY_PATCH_KEYS)), json!({"text": "Launch on November 15", "pinned": true}));
}

#[test]
fn ask_takes_3_to_500_characters_in_english_or_roman_urdu() {
    assert_eq!(check_question("  When do we launch? ", None).unwrap(), ("When do we launch?".to_string(), "en"));
    assert_eq!(check_question("Launch kab hai?", Some("roman_urdu")).unwrap().1, "roman_urdu");
    assert_eq!(check_question("Why?", Some("en")).unwrap().1, "en");
    assert!(check_question("  hi  ", None).is_err());
    assert!(check_question(&"a".repeat(501), None).is_err());
    assert!(check_question(&"ب".repeat(500), None).is_ok(), "characters are counted, not bytes");
    for language in ["ur", "", "EN", "roman urdu", "english"] {
        assert!(check_question("When do we launch?", Some(language)).is_err(), "{language:?}");
    }
    assert_eq!(notes_language(None).unwrap(), None, "notes written again without a choice send no language");
    assert_eq!(notes_language(Some("roman_urdu")).unwrap(), Some("roman_urdu"));
    assert!(notes_language(Some("ur")).is_err());
}

#[test]
fn client_files_are_checked_before_they_are_read() {
    use std::path::Path;
    assert_eq!(check_client_file(Path::new("C:/Acme/scope.pdf"), 182_340).unwrap(), "application/pdf");
    assert_eq!(check_client_file(Path::new("Brief.DOCX"), 1).unwrap(), "application/vnd.openxmlformats-officedocument.wordprocessingml.document");
    assert_eq!(check_client_file(Path::new("rules.txt"), 10).unwrap(), "text/plain");
    assert_eq!(check_client_file(Path::new("notes.md"), 20 * 1024 * 1024).unwrap(), "text/markdown", "20 MB itself is allowed");
    assert_eq!(check_client_file(Path::new("C:/Acme/scan.pdf"), 20 * 1024 * 1024 + 1).unwrap_err().to_string(), "scan.pdf is larger than 20 MB.");
    let wrong = check_client_file(Path::new("budget.xlsx"), 10).unwrap_err().to_string();
    assert!(wrong.starts_with("budget.xlsx cannot be added."), "{wrong}");
    assert!(check_client_file(Path::new("old.doc"), 10).is_err(), "only Word's .docx is read");
    assert!(check_client_file(Path::new("empty.txt"), 0).is_err());
}

#[test]
fn pasted_instructions_are_trimmed_and_limited() {
    assert_eq!(check_instructions("  Always copy Ali on invoices.\n").unwrap(), "Always copy Ali on invoices.");
    assert!(check_instructions(" \n\t").is_err());
    assert!(check_instructions(&"x".repeat(MAX_INSTRUCTIONS_CHARS)).is_ok());
    assert!(check_instructions(&"x".repeat(MAX_INSTRUCTIONS_CHARS + 1)).is_err());
}

#[test]
fn a_recording_without_notes_reads_as_none() {
    let server = |code: &str| AppError::Server { status: 404, message: "Not found.".into(), code: Some(code.into()), retry_after_secs: None };
    assert_eq!(notes_or_none(Err(server("notes_not_found"))).unwrap(), None);
    assert!(notes_or_none(Err(server("session_not_found"))).is_err(), "a recording that is gone is still an error");
    assert!(notes_or_none(Err(AppError::NotPaired)).is_err());
    assert_eq!(notes_or_none(Ok(json!({"data": {"status": "ready"}}))).unwrap(), Some(json!({"data": {"status": "ready"}})));
}
