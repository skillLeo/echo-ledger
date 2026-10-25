//! The JSON the Rust core hands to the React views must match the declarations in `src/lib/ipc.ts`.

use serde_json::{Value, json};

use desktop_lib::audio::types::BackendKind;
use desktop_lib::audio::{RecordingInfo, RecordingMode};
use desktop_lib::detect::MicUser;

fn ipc_ts() -> String {
    std::fs::read_to_string(
        std::path::Path::new(env!("CARGO_MANIFEST_DIR"))
            .join("..")
            .join("src")
            .join("lib")
            .join("ipc.ts"),
    )
    .expect("ipc.ts")
}

/// Member names of `export interface <name> { ... }`.
fn interface_members(ts: &str, name: &str) -> Vec<String> {
    let start = ts
        .find(&format!("export interface {name}"))
        .unwrap_or_else(|| panic!("interface {name}"));
    let mut members: Vec<String> = ts[start..]
        .lines()
        .skip(1)
        .take_while(|l| !l.starts_with('}'))
        .filter_map(|l| l.trim().split(':').next())
        .map(|k| k.trim_end_matches('?').to_string())
        .filter(|k| !k.is_empty() && !k.starts_with("//") && !k.starts_with("/*"))
        .collect();
    members.sort();
    members
}

fn info(mode: RecordingMode, backend: BackendKind) -> RecordingInfo {
    RecordingInfo {
        client_ref: "3f2b6c1e-8d4a-4d3e-9a51-2c7f0e9b1a22".into(),
        mode,
        source_app: "zoom".into(),
        client_id: None,
        started_at: chrono::Utc::now(),
        backend,
    }
}

#[test]
fn recording_info_has_exactly_the_members_the_ui_reads() {
    let v = serde_json::to_value(info(RecordingMode::Call, BackendKind::Mock)).unwrap();
    let mut keys: Vec<String> = v.as_object().unwrap().keys().cloned().collect();
    keys.sort();
    assert_eq!(keys, interface_members(&ipc_ts(), "RecordingInfo"));
    assert!(v["clientId"].is_null());
    assert!(
        v["startedAt"]
            .as_str()
            .unwrap()
            .parse::<chrono::DateTime<chrono::Utc>>()
            .is_ok(),
        "an RFC 3339 instant"
    );
}

#[test]
fn recording_modes_and_backends_use_the_names_the_ui_switches_on() {
    let ts = ipc_ts();
    for (mode, name) in [
        (RecordingMode::Call, "call"),
        (RecordingMode::VoiceNote, "voice_note"),
        (RecordingMode::MicNote, "mic_note"),
    ] {
        assert_eq!(serde_json::to_value(mode).unwrap(), json!(name));
        assert!(
            ts.contains(&format!("\"{name}\"")),
            "mode {name} not declared in ipc.ts"
        );
    }
    for (backend, name) in [
        (BackendKind::ProcessLoopback, "process_loopback"),
        (BackendKind::EndpointLoopback, "endpoint_loopback"),
        (BackendKind::CoreAudioTap, "core_audio_tap"),
        (BackendKind::Mock, "mock"),
    ] {
        assert_eq!(serde_json::to_value(&backend).unwrap(), json!(name));
        assert!(
            ts.contains(&format!("\"{name}\"")),
            "backend {name} not declared in ipc.ts"
        );
    }
}

#[test]
fn mic_users_serialise_with_a_name_and_an_optional_call_app() {
    let recognised = serde_json::to_value(MicUser {
        name: "chrome.exe".into(),
        app: Some("google_meet".into()),
        holds_mic: true,
        plays_audio: true,
        site: Some("meet.google.com".into()),
        decision: "auto_record".into(),
        reason: None,
    })
    .unwrap();
    let ignored = serde_json::to_value(MicUser {
        name: "chrome.exe".into(),
        app: None,
        holds_mic: true,
        plays_audio: false,
        site: None,
        decision: "ignored".into(),
        reason: Some("unknown_site".into()),
    })
    .unwrap();
    assert_eq!(
        recognised,
        json!({"name": "chrome.exe", "app": "google_meet", "holdsMic": true, "playsAudio": true,
               "site": "meet.google.com", "decision": "auto_record", "reason": Value::Null})
    );
    assert_eq!(
        ignored,
        json!({"name": "chrome.exe", "app": Value::Null, "holdsMic": true, "playsAudio": false,
               "site": Value::Null, "decision": "ignored", "reason": "unknown_site"})
    );
    let mut keys: Vec<String> = recognised.as_object().unwrap().keys().cloned().collect();
    keys.sort();
    assert_eq!(keys, interface_members(&ipc_ts(), "MicUser"));
}

#[test]
fn the_explainer_built_by_the_core_uses_the_decision_and_reason_names_the_ui_declares() {
    use desktop_lib::detect::decide::{TreeAudio, decide};
    use desktop_lib::detect::explain;

    let chrome = |render: bool, title: &str| TreeAudio {
        root_pid: 42,
        name: "chrome.exe".into(),
        pfn: None,
        capture_active: true,
        render_active: render,
        titles: vec![title.into()],
        urls: vec![],
    };
    let meet = chrome(true, "Meet - abc-defg-hij - Google Chrome");
    let unknown = chrome(false, "Some page - Google Chrome");
    let built = [meet, unknown].map(|t| serde_json::to_value(explain(&t, &decide(&t, &[], false, true), &[], None)).unwrap());
    assert_eq!(
        built[0],
        json!({"name": "chrome.exe", "app": "google_meet", "holdsMic": true, "playsAudio": true,
               "site": "meet.google.com", "decision": "auto_record", "reason": Value::Null})
    );
    assert_eq!(
        built[1],
        json!({"name": "chrome.exe", "app": Value::Null, "holdsMic": true, "playsAudio": false,
               "site": Value::Null, "decision": "ignored", "reason": "unknown_site"})
    );
    let ts = ipc_ts();
    for name in ["auto_record", "recording", "ignored", "ask", "off", "dictation", "unknown_site", "unknown_app", "never_record", "auto_off"] {
        assert!(ts.contains(&format!("\"{name}\"")), "{name} not declared in ipc.ts");
    }
    for (reason, name) in [
        (desktop_lib::detect::decide::IgnoreReason::Dictation, "dictation"),
        (desktop_lib::detect::decide::IgnoreReason::UnknownSite, "unknown_site"),
        (desktop_lib::detect::decide::IgnoreReason::UnknownApp, "unknown_app"),
        (desktop_lib::detect::decide::IgnoreReason::NeverRecord, "never_record"),
        (desktop_lib::detect::decide::IgnoreReason::AutoOff, "auto_off"),
    ] {
        assert_eq!(serde_json::to_value(reason).unwrap(), json!(name));
        assert_eq!(reason.as_str(), name);
    }
}

#[test]
fn site_rules_serialise_with_the_members_the_ui_declares() {
    let rule = desktop_lib::detect::sites::builtin_rules().remove(0);
    let v = serde_json::to_value(&rule).unwrap();
    let mut keys: Vec<String> = v.as_object().unwrap().keys().cloned().collect();
    keys.sort();
    assert_eq!(keys, interface_members(&ipc_ts(), "SiteRule"));
    assert_eq!(
        v,
        json!({"id": "builtin.meet", "app": "google_meet", "host": "meet.google.com", "titlePrefix": "Meet - ",
               "titleContains": "Google Meet", "titleEquals": Value::Null, "priority": 30, "enabled": true})
    );
    let back: desktop_lib::detect::sites::SiteRule = serde_json::from_value(v).unwrap();
    assert_eq!(back, rule);
}

#[test]
fn recording_mode_round_trips() {
    for mode in [
        RecordingMode::Call,
        RecordingMode::VoiceNote,
        RecordingMode::MicNote,
    ] {
        let back: RecordingMode =
            serde_json::from_value(serde_json::to_value(mode).unwrap()).unwrap();
        assert_eq!(back, mode);
    }
}

#[test]
fn the_server_channel_names_are_me_and_client() {
    use desktop_lib::audio::types::Channel;
    assert_eq!(
        (Channel::Mic.grant_name(), Channel::System.grant_name()),
        ("me", "client")
    );
    assert_eq!(
        (Channel::Mic.speaker(), Channel::System.speaker()),
        ("Me", "Client")
    );
    assert_eq!((Channel::Mic.index(), Channel::System.index()), (0, 1));
}
