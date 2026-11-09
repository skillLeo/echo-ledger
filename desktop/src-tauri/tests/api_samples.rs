//! The answers the real server gives (docs/api-samples, kept true by the server's ApiSamplesTest) read with this app's own
//! models. Fakes in the other tests repeat what their author believes; these files are what the server sends.

use std::path::PathBuf;

use desktop_lib::net::models::{
    AccountDto, AccountExport, CompleteResponse, CreateSessionResponse, Entitlements, LibraryResponse, RecordingUpload, RegisterResponse,
    ServerConfig, SttTokenResponse,
};
use desktop_lib::translate::protocol::LiveTranslateToken;
use serde::de::DeserializeOwned;

fn sample(name: &str) -> String {
    let path = PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("../../docs/api-samples").join(format!("{name}.json"));
    std::fs::read_to_string(&path).unwrap_or_else(|e| panic!("{}: {e}", path.display()))
}

fn reads<T: DeserializeOwned>(name: &str, failures: &mut Vec<String>) {
    if let Err(e) = serde_json::from_str::<T>(&sample(name)) {
        failures.push(format!("{name}: {e}"));
    }
}

#[test]
fn the_desktop_reads_every_real_answer_it_has_a_model_for() {
    let mut failures = Vec::new();
    reads::<RegisterResponse>("auth_password", &mut failures);
    reads::<ServerConfig>("config", &mut failures);
    reads::<AccountDto>("account", &mut failures);
    reads::<AccountExport>("account_export", &mut failures);
    reads::<SttTokenResponse>("stt_token", &mut failures);
    reads::<LiveTranslateToken>("live_translate_token", &mut failures);
    reads::<CreateSessionResponse>("session_open", &mut failures);
    reads::<CreateSessionResponse>("session_finish", &mut failures);
    reads::<CreateSessionResponse>("session_create", &mut failures);
    reads::<RecordingUpload>("recording_status", &mut failures);
    reads::<CompleteResponse>("recording_complete", &mut failures);
    reads::<LibraryResponse>("library", &mut failures);
    reads::<LibraryResponse>("library_folder", &mut failures);
    reads::<Entitlements>("entitlements", &mut failures);
    assert!(failures.is_empty(), "{}", failures.join("\n"));
}

#[test]
fn the_account_names_staff_and_lists_the_workspaces_as_the_server_sends_them() {
    // v1.6 (P4): the switcher's list and the avatar menu's Admin panel come from this answer.
    let account: desktop_lib::net::models::AccountDto = serde_json::from_str(&sample("account")).expect("account.json");
    assert!(account.super_admin);
    assert_eq!(account.workspaces.len(), 1);
    assert_eq!(account.workspaces[0].name, "Personal");
    assert_eq!(account.workspaces[0].role.as_deref(), Some("owner"));
    assert_eq!(account.workspaces[0].current, Some(true));
}

#[test]
fn the_config_says_no_update_announcement_or_maintenance_when_there_is_none() {
    // v1.6 (P2.7): the real answer carries the three fields as null; they read as nothing to show.
    let config: desktop_lib::net::models::ServerConfig = serde_json::from_str(&sample("config")).expect("config.json");
    assert_eq!((config.app_update, config.announcement, config.maintenance), (None, None, None));
}

#[test]
fn every_feature_reaches_the_page_in_the_registry_order_the_server_sends() {
    let raw = sample("entitlements");
    let sent: serde_json::Map<String, serde_json::Value> = serde_json::from_str::<serde_json::Value>(&raw).unwrap()["features"]
        .as_object()
        .cloned()
        .unwrap();
    // serde_json's own map sorts its keys, so the order is read from the text itself.
    let mut in_text: Vec<&str> = sent.keys().map(String::as_str).collect();
    in_text.sort_by_key(|k| raw.find(&format!("\"{k}\": {{")).unwrap_or(usize::MAX));
    let entitlements: Entitlements = serde_json::from_str(&raw).unwrap();
    assert_eq!(entitlements.features.len(), 15);
    assert_eq!(entitlements.features.keys().collect::<Vec<_>>(), in_text);
    assert_eq!(entitlements.features.keys().next(), Some("record"));
    let wire = serde_json::to_string(&entitlements).unwrap();
    let mut on_wire = in_text.clone();
    on_wire.sort_by_key(|k| wire.find(&format!("\"{k}\":{{")).unwrap_or(usize::MAX));
    assert_eq!(on_wire, in_text, "the page gets them in the same order");
}
