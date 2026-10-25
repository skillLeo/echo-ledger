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
