//! `update_settings` patches as the UI sends them (camelCase JSON, only the keys that change).

use serde_json::json;

use super::*;
use crate::settings::AfterCall;

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
fn after_a_call_and_the_caption_lines_are_applied() {
    let mut s = Settings::default();
    patch(json!({"afterCall": "ask", "captionLines": 2})).apply(&mut s);
    assert_eq!((s.after_call, s.caption_lines), (AfterCall::Ask, 2));
    patch(json!({"afterCall": "always_audio"})).apply(&mut s);
    assert_eq!(s.after_call, AfterCall::AlwaysAudio);
    patch(json!({"afterCall": "always_transcribe"})).apply(&mut s);
    assert_eq!(s.after_call, AfterCall::AlwaysTranscribe);
    patch(json!({"afterCall": "keep"})).apply(&mut s);
    assert_eq!(s.after_call, AfterCall::Ask, "v1.5's Keep automatically, from an older window, is permission first");
}

#[test]
fn choosing_after_a_call_answers_its_notice() {
    let mut s = Settings { after_call_notice: true, ..Settings::default() };
    patch(json!({"captionLines": 5})).apply(&mut s);
    assert!(s.after_call_notice, "another setting leaves it");
    patch(json!({"afterCall": "always_audio"})).apply(&mut s);
    assert!(!s.after_call_notice, "the person chose: nothing more to tell");
    let mut same = Settings { after_call_notice: true, ..Settings::default() };
    patch(json!({"afterCall": "ask"})).apply(&mut same);
    assert!(!same.after_call_notice, "choosing Ask me again answers it too");
}

#[test]
fn skip_calls_shorter_than_is_applied_only_for_an_offered_choice() {
    let mut s = Settings::default();
    patch(json!({"skipCallsShorterThanS": 0})).apply(&mut s);
    assert_eq!(s.skip_calls_shorter_than_s, 0, "never");
    patch(json!({"skipCallsShorterThanS": 30})).apply(&mut s);
    assert_eq!(s.skip_calls_shorter_than_s, 30);
    patch(json!({"skipCallsShorterThanS": 12})).apply(&mut s);
    assert_eq!(s.skip_calls_shorter_than_s, 30, "update_settings refuses it; apply never stores it");
}

#[test]
fn a_patch_with_the_keys_from_before_v1_5_changes_nothing_and_is_not_refused() {
    // An exported settings file from v1.4 still carries askBeforeSaving / decisionTimeout.
    let mut s = Settings { after_call: AfterCall::Ask, ..Settings::default() };
    patch(json!({"askBeforeSaving": "never", "decisionTimeout": "discard"})).apply(&mut s);
    assert_eq!(s, Settings { after_call: AfterCall::Ask, ..Settings::default() });
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
    assert_eq!(privacy_settings_url("system_audio", true), Some("x-apple.systempreferences:com.apple.preference.security?Privacy_AudioCapture"));
    assert_eq!(privacy_settings_url("system_audio", false), None, "Windows needs no permission for system audio");
    assert_eq!(privacy_settings_url("camera", true), None);
    // The Mac check's other two panes, which Windows does not have.
    assert_eq!(privacy_settings_url("sound", true), Some("x-apple.systempreferences:com.apple.Sound-Settings.extension"));
    assert_eq!(privacy_settings_url("software_update", true), Some("x-apple.systempreferences:com.apple.Software-Update-Settings.extension"));
    assert_eq!(privacy_settings_url("sound", false), None);
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

#[test]
fn a_subfolder_names_its_parent_and_a_top_level_project_sends_the_v1_1_body() {
    assert_eq!(new_client_body(" Acme ", None, Some("AC".into()), None).unwrap(), json!({"name": "Acme", "color": null, "avatar": "AC"}));
    assert_eq!(new_client_body("Invoices", None, None, Some("01JBACME".into())).unwrap(), json!({"name": "Invoices", "color": null, "avatar": null, "parent_id": "01JBACME"}));
    assert_eq!(new_client_body("Invoices", None, None, Some("  ".into())).unwrap().get("parent_id"), None);
    assert_eq!(new_client_body("   ", None, None, None).unwrap_err().to_string(), "Give the project a name.");
}

#[test]
fn a_project_can_be_moved_but_nothing_else_slips_into_the_patch() {
    let patch = json!({"parent_id": null, "name": "Acme", "session_count": 4}).as_object().unwrap().clone();
    let kept = allowed_patch(&patch, &CLIENT_PATCH_KEYS);
    assert_eq!(serde_json::Value::Object(kept), json!({"parent_id": null, "name": "Acme"}));
}

#[test]
fn a_route_an_older_server_lacks_reads_as_none_and_real_refusals_stay_errors() {
    let server = |status: u16, code: Option<&str>| AppError::Server { status, message: "x".into(), code: code.map(String::from), retry_after_secs: None };
    assert_eq!(when_available(Err(server(404, Some("not_found")))).unwrap(), None);
    assert_eq!(when_available(Err(server(404, None))).unwrap(), None);
    assert_eq!(when_available(Err(server(405, Some("method_not_allowed")))).unwrap(), None);
    assert!(when_available(Err(server(404, Some("client_not_found")))).is_err());
    assert!(when_available(Err(server(503, None))).is_err());
    assert_eq!(when_available(Ok(json!({"data": []}))).unwrap(), Some(json!({"data": []})));
}

#[test]
fn only_time_zone_names_go_into_the_views_query() {
    for zone in ["Asia/Karachi", "UTC", "America/Argentina/Buenos_Aires", "Etc/GMT+5"] {
        assert!(is_time_zone(zone), "{zone}");
    }
    for zone in ["", "Asia/Karachi&limit=1", "Europe/Paris?x", &"A".repeat(65)] {
        assert!(!is_time_zone(zone), "{zone}");
    }
}

#[test]
fn a_projects_screens_switch_goes_through_the_patch() {
    let patch = json!({"screens": false, "screen_count": 3}).as_object().unwrap().clone();
    assert_eq!(serde_json::Value::Object(allowed_patch(&patch, &CLIENT_PATCH_KEYS)), json!({"screens": false}));
}

#[test]
fn the_screen_settings_page_opens_where_each_system_keeps_it() {
    assert_eq!(privacy_settings_url("screen", true), Some("x-apple.systempreferences:com.apple.preference.security?Privacy_ScreenCapture"));
    assert_eq!(privacy_settings_url("screen", false), Some("ms-settings:privacy-graphicscapturewithoutborder"));
}

#[test]
fn the_console_pages_are_the_customer_app_and_the_staff_panel() {
    // v1.6: the console moved its customer pages from /admin to /app; /admin is the staff's panel.
    for page in ["app/workspace", "app/profile", "admin", "downloads", "terms", "privacy", "account/delete"] {
        assert!(SERVER_PAGES.contains(&page), "{page}");
    }
    assert!(!SERVER_PAGES.contains(&"admin/workspace") && !SERVER_PAGES.contains(&"admin/profile"));
    assert!(!SERVER_PAGES.contains(&"app/../admin"), "only the listed pages open");
}

#[test]
fn permissions_status_answers_on_one_thread_and_never_waits_on_itself() {
    // The real path, as the command runs it: one thread reads the system, the memory, and builds the answer. A guard on
    // the permission memory left alive while it was locked again froze the app (every permission read after it waited).
    let dir = tempfile::tempdir().unwrap();
    let state = std::sync::Arc::new(crate::AppState::load(dir.path().to_path_buf(), true));
    // Something remembered, so every field reads the memory.
    state.permission_memory.lock().unwrap().notice = vec![crate::permissions::Kind::Screen];
    for round in 0..2 {
        let (tx, rx) = std::sync::mpsc::channel();
        let reader = state.clone();
        std::thread::spawn(move || {
            let _ = tx.send(permissions_status_of(&reader));
        });
        let status = rx.recv_timeout(std::time::Duration::from_secs(10)).unwrap_or_else(|_| panic!("permissions_status never answered (round {round}): a lock waits on itself"));
        assert_eq!(status.platform, if cfg!(windows) { "windows" } else if cfg!(target_os = "macos") { "macos" } else { "other" });
    }
    // And the memory is free afterwards.
    assert!(state.permission_memory.try_lock().is_ok());
}

#[test]
fn transcribe_selected_and_its_estimate_take_1_to_50_recordings() {
    let ids = |n: usize| (0..n).map(|i| format!("01JB{i:022}")).collect::<Vec<_>>();
    assert!(check_transcribe(&ids(1)).is_ok() && check_transcribe(&ids(50)).is_ok());
    for n in [0, 51] {
        assert_eq!(check_transcribe(&ids(n)).unwrap_err().to_string(), "Choose between 1 and 50 recordings.");
    }
}

#[test]
fn a_recording_s_language_is_one_of_the_keys_its_patch_sends() {
    let mut patch = serde_json::Map::new();
    patch.insert("language".into(), json!("ur"));
    patch.insert("status".into(), json!("completed"));
    let sent = allowed_patch(&patch, &SESSION_PATCH_KEYS);
    assert_eq!(serde_json::Value::Object(sent), json!({"language": "ur"}), "language goes, anything unknown does not");
    assert!(EXPORT_VIEWS.contains(&"roman") && !EXPORT_VIEWS.contains(&"original"), "the views an export takes");
}

/// The body of `fn name` in `text` (from its line to the next item at the start of a line).
fn body_of<'a>(text: &'a str, name: &str) -> &'a str {
    let start = text.find(name).unwrap_or_else(|| panic!("{name} not found"));
    let rest = &text[start..];
    let end = rest.find("\n}\n").map_or(rest.len(), |e| e + 3);
    &rest[..end]
}

#[test]
fn each_v2_parity_command_calls_its_own_api_method() {
    // A command wired to the wrong route would pass every client test: each one is read here for its own method.
    let source = include_str!("../commands.rs");
    for (command, method) in [
        ("pub async fn sessions_transcribe_estimate(", ".transcribe_estimate(&ids)"),
        ("pub async fn session_transcribe(", ".transcribe_session(&id)"),
        ("pub async fn sessions_transcribe(", ".transcribe_sessions(&ids)"),
        ("pub async fn sessions_export(", ".export_sessions_as(&ids, &format, view.as_deref())"),
        ("pub async fn session_organise(", ".update_session(&id, "),
        ("pub async fn ui_design_set(", ".set_ui_design(design.as_deref())"),
    ] {
        let body = body_of(source, command);
        assert!(body.contains(method), "{command} does not call {method}");
        let others = [".transcribe_estimate(", ".transcribe_session(", ".transcribe_sessions(", ".export_sessions_as(", ".update_session(", ".set_ui_design(", ".set_ui_classic("];
        for other in others.iter().filter(|o| !method.starts_with(**o)) {
            assert!(!body.contains(other), "{command} also calls {other}");
        }
    }
}

#[test]
fn the_design_switch_refuses_an_unknown_design_before_anything_is_sent() {
    assert_eq!(check_design(Some("x")).unwrap_err().to_string(), "That design is not one of the choices.");
    for ok in [Some("v2"), Some("classic"), None] {
        assert!(check_design(ok).is_ok(), "{ok:?}");
    }
    // The check comes first: nothing reaches the server (or even the client) for "x".
    let body = body_of(include_str!("../commands.rs"), "pub async fn ui_design_set(");
    let check = body.find("check_design(design.as_deref())?").expect("ui_design_set checks the design");
    assert!(check < body.find(".api()?").expect("then sends it"), "checked before anything is sent");
}

#[test]
fn a_per_window_share_switch_is_changed_only_by_its_own_window_or_the_main_window() {
    use crate::privacy::share::Pinned;
    let (card, captions) = (crate::privacy::ASSIST, crate::overlay::OVERLAY);
    assert_eq!(pinned_for(card, None).unwrap(), Pinned::Card, "the card about itself");
    assert_eq!(pinned_for(captions, None).unwrap(), Pinned::Captions);
    assert_eq!(pinned_for(card, Some(Pinned::Card)).unwrap(), Pinned::Card);
    assert!(pinned_for(card, Some(Pinned::Captions)).is_err(), "the card never changes the captions");
    assert!(pinned_for(captions, Some(Pinned::Card)).is_err());
    assert_eq!(pinned_for(crate::lifecycle::MAIN, Some(Pinned::Captions)).unwrap(), Pinned::Captions, "Settings names the window");
    assert!(pinned_for(crate::lifecycle::MAIN, None).is_err(), "the main window must say which");
    for other in [crate::overlay::PILL, crate::overlay::DICTATION, crate::translate::WINDOW, crate::privacy::CHECK_TEST] {
        assert!(pinned_for(other, Some(Pinned::Card)).is_err() && pinned_for(other, None).is_err(), "{other}");
    }
}

#[test]
fn only_the_card_or_the_captions_themselves_show_them_and_the_main_window_may_only_hide_them() {
    use crate::privacy::share::Pinned;
    let main = crate::lifecycle::MAIN;
    // The main window naming the card (or the captions) with shown: true is refused.
    for which in [Pinned::Card, Pinned::Captions] {
        assert!(pinned_for_set(main, Some(which), true).is_err(), "{which:?}: never shown from the main window");
        assert_eq!(pinned_for_set(main, Some(which), false).unwrap(), which, "{which:?}: hidden again from Settings");
    }
    assert_eq!(pinned_for_set(crate::privacy::ASSIST, None, true).unwrap(), Pinned::Card, "the card's own badge");
    assert_eq!(pinned_for_set(crate::overlay::OVERLAY, None, true).unwrap(), Pinned::Captions, "the captions' own badge");
    assert!(pinned_for_set(crate::privacy::ASSIST, Some(Pinned::Captions), true).is_err());
    assert!(include_str!("../commands.rs").contains("let which = pinned_for_set(window.label(), which, shown)?;"), "the command uses the rule");
}

#[test]
fn only_the_main_window_closes_the_share_notice() {
    let body = body_of(include_str!("../commands.rs"), "pub fn share_notice_dismiss(");
    assert!(body.find("crate::privacy::from_main_window(window.label())?").unwrap() < body.find("dismiss_share_notice").unwrap());
}

#[test]
fn the_share_notice_is_said_once_and_closing_it_remembers() {
    let state = Settings { share_notice: true, ..Settings::default() };
    assert_eq!(state.share_notice.then_some(crate::privacy::share::SHARE_NOTICE), Some("EchoLedger now shows in screen shares unless you hide it."));
    // Closing it, or hiding the main window from its Hide button, clears it in the saved settings.
    let privacy = include_str!("../privacy/mod.rs");
    assert!(body_of(privacy, "pub fn dismiss_share_notice(").contains("update_settings(|s| s.share_notice = false)"));
    assert!(body_of(privacy, "pub fn hide_main(").contains("s.share_notice = false;"));
    assert!(include_str!("../commands.rs").contains("share_notice: settings.share_notice.then_some(crate::privacy::share::SHARE_NOTICE),"));
    let dir = tempfile::tempdir().unwrap();
    let path = dir.path().join("settings.json");
    let mut s = Settings::from_value(json!({"hideFromSharing": "all"}));
    assert!(s.share_notice, "after the update");
    s.share_notice = false;
    s.save(&path).unwrap();
    assert!(!Settings::load(&path).share_notice, "never again");
}

#[test]
fn a_list_name_is_cleaned_as_the_server_cleans_it() {
    assert_eq!(list_name("  Clients   and  Co ").unwrap(), "Clients and Co");
    assert_eq!(list_name("کلائنٹس").unwrap(), "کلائنٹس");
    assert!(list_name("   ").is_err());
    assert_eq!(list_name(&"ب".repeat(40)).unwrap().chars().count(), 40, "40 letters of any script");
    assert!(list_name(&"x".repeat(41)).is_err());
}

#[test]
fn a_list_colour_is_hash_and_six_hex_digits() {
    assert_eq!(list_colour("#E15F56").unwrap(), "#e15f56");
    for bad in ["red", "#12345", "#1234567", "#GGGGGG", "e15f56"] {
        assert!(list_colour(bad).is_err(), "{bad}");
    }
}

fn server(status: u16, code: Option<&str>, message: &str) -> AppError {
    AppError::Server { status, message: message.into(), code: code.map(String::from), retry_after_secs: None }
}

#[test]
fn a_view_the_server_refuses_for_good_comes_back_as_data() {
    let refused = view_answer(Err(server(422, Some("validation_failed"), "The name is too long."))).unwrap();
    assert_eq!(refused, json!({"refused": {"status": 422, "code": "validation_failed", "message": "The name is too long."}}));
    let full = view_answer(Err(server(422, Some("views_limit"), "You can keep up to 30 views."))).unwrap();
    assert_eq!(full["refused"]["code"], "views_limit");
    let gone = view_answer(Err(server(404, Some("view_not_found"), "No such view."))).unwrap();
    assert_eq!(gone["refused"]["code"], "view_not_found");
    assert_eq!(view_answer(Ok(json!({"data": {"id": "v1"}}))).unwrap(), json!({"data": {"id": "v1"}}));
}

#[test]
fn a_view_change_that_can_wait_stays_an_error() {
    // Busy, held for an update, the server's own trouble, or offline: sent again later.
    for e in [server(429, Some("rate_limited"), ""), server(426, Some("update_required"), ""), server(503, Some("maintenance"), ""),
        server(500, None, "boom"), AppError::Network("offline".into())] {
        assert!(view_answer(Err(e)).is_err());
    }
    // Not about the view: a suspended account, or a captive portal's or a proxy's 4xx without a code.
    for e in [server(403, Some("account_suspended"), "This account is suspended."), server(403, None, "Forbidden"),
        server(407, None, "Proxy Authentication Required"), server(400, None, "<html>Sign in to the Wi-Fi</html>"),
        server(409, Some("conflict"), "")] {
        let status = e.status();
        assert!(view_answer(Err(e)).is_err(), "{status:?}");
    }
    // A server without saved views yet.
    assert!(view_answer(Err(server(404, None, ""))).unwrap_err().to_string().contains("Saved views"));
}

#[test]
fn each_sign_in_has_its_own_id_and_signing_out_forgets_it() {
    let dir = tempfile::tempdir().unwrap();
    let state = crate::AppState::load(dir.path().to_path_buf(), true);
    // Signed out: no id.
    assert_eq!(state.sign_in_id(), None);
    // Signed in: an id, the same for as long as the sign-in lasts.
    *state.token.lock().unwrap() = Some("token-1".into());
    state.note_sign_in("https://ledger.example.test", &[], "dev-1", "Work laptop").unwrap();
    let first = state.sign_in_id().expect("an id while signed in");
    assert_eq!(state.sign_in_id().as_deref(), Some(first.as_str()));
    // Kept with the settings on disk.
    assert_eq!(Settings::load(&dir.path().join("settings.json")).sign_in_id.as_deref(), Some(first.as_str()));
    // Signed out: gone, and the status says none.
    *state.token.lock().unwrap() = None;
    state.note_sign_out().unwrap();
    assert_eq!(state.sign_in_id(), None);
    assert_eq!(state.settings().sign_in_id, None);
    // The next sign-in (the same account or another) has another id.
    *state.token.lock().unwrap() = Some("token-2".into());
    state.note_sign_in("https://ledger.example.test", &[], "dev-2", "Work laptop").unwrap();
    let second = state.sign_in_id().expect("an id again");
    assert_ne!(first, second);
    // Never exported with the settings.
    assert!(portable_settings(&state.settings())["settings"].get("signInId").is_none());
}
