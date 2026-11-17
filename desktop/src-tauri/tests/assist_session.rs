//! Live Assist sessions against the server (docs/API_ASSIST.md 1.9.0-e "Sessions"), with fixtures: start, token
//! refresh and end on an in-process HTTP server, the refusals in plain words, and the `live_assist` gate.

mod common;

use std::sync::Arc;

use serde_json::json;

use common::{MockServer, Reply, Router};
use desktop_lib::assist::client::{AssistEvent, EndReason};
use desktop_lib::assist::session::{self, AssistStarted};
use desktop_lib::net::api::ApiClient;
use desktop_lib::net::models::AssistStartRequest;
use desktop_lib::ui_flags::UiFlags;

const TOKEN: &str = "7|eld_TOKEN_PLACEHOLDER";
const ID: &str = "01JCASSIST0SESSION00000001";

fn fixture(name: &str) -> serde_json::Value {
    let path = std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("tests/fixtures").join(name);
    serde_json::from_str(&std::fs::read_to_string(path).unwrap()).unwrap()
}

fn client(server: &MockServer) -> ApiClient {
    common::init_tls();
    ApiClient::new(&server.base_url(), &[], Some(TOKEN.to_string())).expect("local client")
}

fn request() -> AssistStartRequest {
    AssistStartRequest { prep_id: Some("01JBPREP".into()), mode: Some("balanced".into()), platform: "desktop".into(), recording_session_id: Some("01JBREC".into()), practice: false }
}

#[tokio::test]
async fn a_session_starts_refreshes_its_tokens_and_ends() {
    let server = MockServer::start(
        Router::new()
            .fixed("POST", "/api/assist/sessions", Reply::json(201, fixture("assist_session_start.json")))
            .fixed("POST", &format!("/api/assist/sessions/{ID}/token"), Reply::json(200, fixture("assist_session_token.json")))
            .fixed("POST", &format!("/api/assist/sessions/{ID}/end"), Reply::json(200, json!({"report": "queued"}))),
    );
    let api = client(&server);
    let answer = api.assist_start(&request()).await.unwrap();
    let sent = server.requests_to("POST", "/api/assist/sessions");
    assert_eq!(
        serde_json::from_slice::<serde_json::Value>(&sent[0].body).unwrap(),
        json!({"prep_id": "01JBPREP", "mode": "balanced", "platform": "desktop", "recording_session_id": "01JBREC", "practice": false})
    );
    assert_eq!(
        AssistStarted::from_answer(&answer),
        AssistStarted { session_id: ID.into(), auto_saved: true, mode: "balanced".into(), allowed_modes: vec!["fast".into(), "balanced".into()], seconds_left: Some(31_200), practice: false }
    );
    let grant = session::grant(&answer.relay, &["sha256/AAAA".into()]);
    assert_eq!((grant.url.as_str(), grant.token.as_str(), grant.pins.len()), ("wss://relay.example/v1/assist", "relay-token-1", 1));
    assert!(!format!("{:?}", answer.relay).contains("relay-token-1"), "the token is never printed");
    let refreshed = api.assist_token(ID).await.unwrap();
    assert_eq!(refreshed.relay.token, "relay-token-2");
    // The connection's refresh hands the plan on: the card's time left counts from there.
    let heard: Arc<std::sync::Mutex<Vec<Option<u64>>>> = Arc::default();
    let log = heard.clone();
    let refresh = session::refresh(Arc::new(client(&server)), ID.into(), vec!["sha256/AAAA".into()], Arc::new(move |plan| log.lock().unwrap().push(plan.seconds_left)));
    let next = refresh().await.unwrap();
    assert_eq!((next.token.as_str(), next.pins.len()), ("relay-token-2", 1));
    assert_eq!(*heard.lock().unwrap(), [Some(30_600)]);
    api.assist_end(ID).await.unwrap();
    assert_eq!(server.requests_to("POST", &format!("/api/assist/sessions/{ID}/end")).len(), 1);
}

#[tokio::test]
async fn practice_has_no_recording_and_the_body_leaves_out_what_is_not_set() {
    let server = MockServer::start(Router::new().fixed("POST", "/api/assist/sessions", Reply::json(201, fixture("assist_session_start.json"))));
    let practice = AssistStartRequest { platform: "desktop".into(), practice: true, ..AssistStartRequest::default() };
    client(&server).assist_start(&practice).await.unwrap();
    let body: serde_json::Value = serde_json::from_slice(&server.requests_to("POST", "/api/assist/sessions")[0].body).unwrap();
    assert_eq!(body, json!({"platform": "desktop", "practice": true}));
}

#[tokio::test]
async fn refusals_read_in_plain_words() {
    for (status, body, said) in [
        (402, json!({"code": "budget_exhausted", "message": "Allowance used", "fallback": "captions_only"}), "This month's Live Assist time is used up. Captions go on."),
        (409, json!({"code": "session_active", "message": "Active elsewhere"}), "Live Assist is already running for you on another device."),
        (403, json!({"code": "feature_not_in_plan", "message": "Live suggestions are in Pro.", "feature": "live_assist"}), "This is not in your workspace's plan. The workspace owner can change the plan in the console."),
    ] {
        let server = MockServer::start(Router::new().fixed("POST", "/api/assist/sessions", Reply::json(status, body)));
        let error = client(&server).assist_start(&request()).await.unwrap_err();
        assert_eq!(error.to_string(), said);
    }
    let server = MockServer::start(Router::new().fixed("POST", &format!("/api/assist/sessions/{ID}/end"), Reply::json(409, json!({"code": "session_ended", "message": "Ended"}))));
    let ended = client(&server).assist_end(ID).await.unwrap_err();
    assert_eq!(ended.code(), Some("session_ended"));
}

#[tokio::test]
async fn nothing_reaches_the_server_while_live_assist_is_off() {
    let server = MockServer::start(Router::new().fixed("POST", "/api/assist/sessions", Reply::json(201, fixture("assist_session_start.json"))));
    let off = UiFlags::read(Some(&json!({"live_assist": false})), false);
    let refused = session::start(Arc::new(client(&server)), &off, vec![], request(), Arc::new(|_| {}), |_| {}).await.unwrap_err();
    assert_eq!(refused.to_string(), "Live Assist isn't on for you yet.");
    assert!(server.requests_to("POST", "/api/assist/sessions").is_empty());
}

#[tokio::test]
async fn a_started_session_connects_to_the_relay_it_was_given() {
    // The relay's address is wss:// and no pins are known here: the session refuses it in plain words.
    let server = MockServer::start(Router::new().fixed("POST", "/api/assist/sessions", Reply::json(201, fixture("assist_session_start.json"))));
    let on = UiFlags::read(Some(&json!({"live_assist": true})), false);
    let events: Arc<std::sync::Mutex<Vec<AssistEvent>>> = Arc::default();
    let sink = events.clone();
    let (answer, _handle) = session::start(Arc::new(client(&server)), &on, vec![], request(), Arc::new(|_| {}), move |event| sink.lock().unwrap().push(event)).await.unwrap();
    assert_eq!(answer.session.id, ID);
    for _ in 0..200 {
        if !events.lock().unwrap().is_empty() {
            break;
        }
        tokio::time::sleep(std::time::Duration::from_millis(10)).await;
    }
    let first = events.lock().unwrap()[0].clone();
    assert_eq!(first, AssistEvent::Ended(EndReason::Insecure));
    assert_eq!(session::end_sentence(&EndReason::Insecure), Some("Live Assist could not make a secure connection."));
}
