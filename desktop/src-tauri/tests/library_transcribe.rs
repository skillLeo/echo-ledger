//! v2 parity, the core's side (docs/PLAN_V2_PARITY.md 3-4): transcribing an audio-only recording, Transcribe selected
//! with its estimate, a selection exported in a Show as view, and a recording's language changed without
//! re-transcribing it, against an in-process HTTP server (docs/API.md "Audio only", "POST /api/sessions/export",
//! "PATCH /api/sessions/{id}").

mod common;

use serde_json::json;

use common::{MockServer, Reply, Router};
use desktop_lib::net::api::{ApiClient, budget_sentence};

const TOKEN: &str = "7|eld_TOKEN_PLACEHOLDER";
const A: &str = "01JB8Q4C1D2E3F4G5H6J7K8M9N";
const B: &str = "01JB8Q4C1D2E3F4G5H6J7K8M9P";

fn client(server: &MockServer) -> ApiClient {
    common::init_tls();
    ApiClient::new(&server.base_url(), &[], Some(TOKEN.to_string())).expect("local client")
}

fn ids() -> Vec<String> {
    vec![A.to_string(), B.to_string()]
}

#[tokio::test]
async fn one_audio_only_recording_is_transcribed() {
    let answer = json!({"session": {"id": A, "status": "processing"}, "transcript": {"id": "01JT", "kind": "final", "provider": "assemblyai", "status": "queued", "language": null}});
    let server = MockServer::start(Router::new().fixed("POST", &format!("/api/sessions/{A}/transcribe"), Reply::json(202, answer.clone())));
    assert_eq!(client(&server).transcribe_session(A).await.unwrap(), answer);
    let sent = server.requests_to("POST", &format!("/api/sessions/{A}/transcribe"));
    assert_eq!(sent.len(), 1);
    assert_eq!(sent[0].header("authorization"), Some(format!("Bearer {TOKEN}").as_str()));
}

#[tokio::test]
async fn a_recording_already_transcribed_or_over_the_budget_says_why() {
    let server = MockServer::start(
        Router::new()
            .fixed("POST", &format!("/api/sessions/{A}/transcribe"), Reply::json(409, json!({"code": "not_audio_only", "message": "This recording is already transcribed."})))
            .fixed(
                "POST",
                &format!("/api/sessions/{B}/transcribe"),
                Reply::json(403, json!({"code": "budget_exceeded", "message": "Monthly limit reached", "minutes_needed": 18, "minutes_left": 5})),
            ),
    );
    let api = client(&server);
    let done = api.transcribe_session(A).await.unwrap_err();
    assert_eq!((done.code(), done.to_string().contains("already transcribed")), (Some("not_audio_only"), true), "{done}");
    let over = api.transcribe_session(B).await.unwrap_err();
    assert_eq!(over.code(), Some("budget_exceeded"));
    assert!(over.to_string().contains("This needs 18 minutes, and 5 minutes are left this month."), "{over}");
}

#[tokio::test]
async fn a_selection_is_transcribed_and_its_estimate_comes_first() {
    let estimate = json!({"minutes": 42, "minutes_left": 120, "items": [{"id": A, "minutes": 30}, {"id": B, "minutes": 12}]});
    let started = json!({"started": [A], "skipped": [{"id": B, "code": "not_audio_only"}], "minutes": 30});
    let server = MockServer::start(
        Router::new()
            .fixed("POST", "/api/sessions/transcribe-estimate", Reply::json(200, estimate.clone()))
            .fixed("POST", "/api/sessions/transcribe", Reply::json(202, started.clone())),
    );
    let api = client(&server);
    assert_eq!(api.transcribe_estimate(&ids()).await.unwrap(), estimate);
    assert_eq!(api.transcribe_sessions(&ids()).await.unwrap(), started);
    assert_eq!(server.requests_to("POST", "/api/sessions/transcribe-estimate")[0].json(), json!({"ids": [A, B]}));
    assert_eq!(server.requests_to("POST", "/api/sessions/transcribe")[0].json(), json!({"ids": [A, B]}));
}

#[tokio::test]
async fn a_selection_over_the_budget_starts_nothing_and_says_the_minutes() {
    let server = MockServer::start(Router::new().fixed(
        "POST",
        "/api/sessions/transcribe",
        Reply::json(403, json!({"code": "budget_exceeded", "message": "Monthly limit reached", "minutes_needed": 42, "minutes_left": 0})),
    ));
    let err = client(&server).transcribe_sessions(&ids()).await.unwrap_err();
    assert!(err.to_string().contains("This needs 42 minutes and no minutes are left this month."), "{err}");
    assert_eq!(budget_sentence(1, 1), "This needs 1 minute, and 1 minute is left this month.");
}

#[tokio::test]
async fn a_selection_is_exported_in_the_view_on_screen_and_without_one_as_before() {
    let server = MockServer::start(Router::new().fixed(
        "POST",
        "/api/sessions/export",
        Reply::html(200, "PK-zip").header("Content-Disposition", "attachment; filename=\"EchoLedger transcripts.zip\""),
    ));
    let api = client(&server);
    let (bytes, name) = api.export_sessions_as(&ids(), "docx", Some("roman")).await.unwrap();
    assert_eq!((bytes.as_slice(), name.as_str()), (b"PK-zip".as_slice(), "EchoLedger transcripts.zip"));
    api.export_sessions(&ids(), "srt").await.unwrap();
    let sent = server.requests_to("POST", "/api/sessions/export");
    assert_eq!(sent[0].json(), json!({"ids": [A, B], "format": "docx", "view": "roman"}));
    assert_eq!(sent[1].json(), json!({"ids": [A, B], "format": "srt"}), "no view: the body an older server knows");
}

#[tokio::test]
async fn a_recording_s_language_changes_without_re_transcribing_it() {
    let server = MockServer::start(Router::new().fixed("PATCH", &format!("/api/sessions/{A}"), Reply::json(200, json!({"data": {"id": A, "language": "ur"}}))));
    let answer = client(&server).update_session(A, &json!({"language": "ur"})).await.unwrap();
    assert_eq!(answer["data"]["language"], "ur");
    assert_eq!(server.requests()[0].json(), json!({"language": "ur"}));
    assert!(server.requests_to("POST", &format!("/api/sessions/{A}/retranscribe")).is_empty(), "nothing re-transcribed");
}

#[tokio::test]
async fn the_design_switch_is_set_and_its_refusal_says_why() {
    let answer = json!({"ui_flags": {"ui_v2.shell": true}, "ui_classic": false, "ui_design": {"choice": "v2", "current": "v2", "can_try": false, "can_switch_back": true}});
    let server = MockServer::start(Router::new().fixed("PUT", "/api/account/ui", Reply::json(200, answer)));
    let api = client(&server);
    let got = api.set_ui_design(Some("v2")).await.unwrap();
    let flags = desktop_lib::ui_flags::UiFlags::read(got.ui_flags.as_ref(), got.ui_classic).with_design(got.ui_design.as_ref());
    assert!(flags.is_on("ui_v2.shell"));
    assert_eq!(flags.design.as_ref().map(|d| (d.current.as_str(), d.can_switch_back)), Some(("v2", true)));
    api.set_ui_design(None).await.unwrap();
    let sent = server.requests_to("PUT", "/api/account/ui");
    assert_eq!(sent[0].json(), json!({"design": "v2"}));
    assert_eq!(sent[1].json(), json!({"design": null}), "back to the default");

    let refused = MockServer::start(Router::new().fixed("PUT", "/api/account/ui", Reply::json(422, json!({"code": "design_not_available", "message": "Nothing to try yet."}))));
    let err = client(&refused).set_ui_design(Some("v2")).await.unwrap_err();
    assert_eq!(err.code(), Some("design_not_available"));
    assert_eq!(err.to_string(), "The new design isn't open to try yet.");
}

#[tokio::test]
async fn the_config_carries_the_design_switch_and_an_older_one_does_not() {
    let base: serde_json::Value = serde_json::from_str(&std::fs::read_to_string(std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("tests/fixtures/config.json")).unwrap()).unwrap();
    let mut with = base.clone();
    with["ui_flags"] = json!({"ui_v2.home": true});
    with["ui_design"] = json!({"choice": null, "current": "classic", "can_try": true, "can_switch_back": false});
    let config: desktop_lib::net::models::ServerConfig = serde_json::from_value(with).unwrap();
    assert_eq!(config.ui().design.map(|d| d.can_try), Some(true));
    let older: desktop_lib::net::models::ServerConfig = serde_json::from_value(base).unwrap();
    assert_eq!(older.ui().design, None, "an older server shows only the 2.0.0-a switch");
}
