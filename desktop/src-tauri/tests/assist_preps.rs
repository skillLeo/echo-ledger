//! Live Assist preps, turns and suggestion actions against the server (docs/API_ASSIST.md 1.9.0-g "Preps" and
//! "Sessions"), with fixtures written from the contract while the server side is built: the card's shapes, the
//! requests' paths and bodies, and the batches of final turns.

mod common;

use serde_json::json;

use common::{MockServer, Reply, Router};
use desktop_lib::assist::card::TurnRow;
use desktop_lib::net::api::ApiClient;
use desktop_lib::net::models::{Prep, PrepDraft};

const TOKEN: &str = "7|eld_TOKEN_PLACEHOLDER";
const PREP: &str = "01JBPREP0000000000000000P1";
const SESSION: &str = "01JCASSIST0SESSION00000001";

fn fixture(name: &str) -> serde_json::Value {
    let path = std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("tests/fixtures").join(name);
    serde_json::from_str(&std::fs::read_to_string(path).unwrap()).unwrap()
}

fn client(server: &MockServer) -> ApiClient {
    common::init_tls();
    ApiClient::new(&server.base_url(), &[], Some(TOKEN.to_string())).expect("local client")
}

fn body(server: &MockServer, method: &str, path: &str) -> serde_json::Value {
    serde_json::from_slice(&server.requests_to(method, path)[0].body).unwrap()
}

#[tokio::test]
async fn preps_are_listed_in_the_card_s_shape() {
    let server = MockServer::start(Router::new().fixed("GET", "/api/assist/preps", Reply::json(200, fixture("assist_preps.json"))));
    let api = client(&server);
    let all = api.assist_preps(None).await.unwrap();
    assert_eq!(all.iter().map(|p| p.id.as_str()).collect::<Vec<_>>(), [PREP, "01JBPREP0000000000000000P2"], "an entry without an id is left out");
    // What the server left out gets the contract's defaults, so the card always has every field.
    let second = serde_json::to_value(&all[1]).unwrap();
    assert_eq!((second["meeting_type"].as_str(), second["hint_level"].as_str(), second["mode"].as_str(), second["language_help"].as_str()), (Some("general"), Some("outline"), Some("balanced"), Some("off")));
    assert_eq!((second["counterpart"].as_str(), second["documents"].clone()), (Some(""), json!([])));
    api.assist_preps(Some("scheduled")).await.unwrap();
    let asked = server.requests_to("GET", "/api/assist/preps");
    assert_eq!((asked[0].query(), asked[1].query()), (None, Some("status=scheduled")));
    assert!(!format!("{:?}", all[0]).contains("Acme"), "what people wrote stays out of logs");
}

#[tokio::test]
async fn a_prep_is_created_or_updated_without_its_id_in_the_body() {
    let server = MockServer::start(
        Router::new()
            .fixed("POST", "/api/assist/preps", Reply::json(201, fixture("assist_prep.json")))
            .fixed("PATCH", &format!("/api/assist/preps/{PREP}"), Reply::json(200, fixture("assist_prep.json"))),
    );
    let api = client(&server);
    let draft: PrepDraft = serde_json::from_value(json!({
        "meeting_type": "interview", "title": "Interview at Acme", "counterpart": "Sara Khan", "company": "Acme", "project_id": null,
        "goals": "", "done": "", "not_done": "", "to_discuss": "", "instructions": "", "hint_level": "outline", "mode": "balanced",
        "language_help": "roman_urdu", "scheduled_at": null
    }))
    .unwrap();
    let saved = api.assist_prep_save(&draft).await.unwrap();
    assert_eq!(saved.id, PREP);
    assert_eq!(saved.not_done, "", "null text reads as empty");
    assert_eq!(saved.documents[0].tokens, Some(2140));
    assert_eq!(saved.documents[0].text.as_deref(), Some("Payments engineer, six years at a bank."), "the single read carries the text (1.9.0-m)");
    assert_eq!(saved.documents[1].text, None);
    let sent = body(&server, "POST", "/api/assist/preps");
    assert!(sent.get("id").is_none());
    assert_eq!(sent["meeting_type"], "interview");
    let update = PrepDraft { id: Some(PREP.into()), ..draft };
    api.assist_prep_save(&update).await.unwrap();
    assert!(body(&server, "PATCH", &format!("/api/assist/preps/{PREP}")).get("id").is_none(), "the id is in the path only");
}

#[tokio::test]
async fn documents_join_and_leave_a_prep_and_the_prep_comes_back() {
    let docs = format!("/api/assist/preps/{PREP}/documents");
    let server = MockServer::start(
        Router::new()
            .fixed("POST", &docs, Reply::json(200, json!({"ok": true})))
            .fixed("DELETE", &format!("{docs}/01JBFILE000000000000000002"), Reply::empty(204))
            .fixed("GET", &format!("/api/assist/preps/{PREP}"), Reply::json(200, fixture("assist_prep.json")))
            .fixed("DELETE", &format!("/api/assist/preps/{PREP}"), Reply::empty(204)),
    );
    let api = client(&server);
    let ids = vec!["01JBFILE000000000000000001".to_string()];
    let prep = api.assist_prep_documents_add(PREP, &ids).await.unwrap();
    assert_eq!(body(&server, "POST", &docs), json!({"file_ids": ["01JBFILE000000000000000001"]}));
    assert_eq!(prep.documents.len(), 2);
    api.assist_prep_document_remove(PREP, "01JBFILE000000000000000002").await.unwrap();
    assert_eq!(server.requests_to("GET", &format!("/api/assist/preps/{PREP}")).len(), 2, "the prep as it is now, each time");
    api.assist_prep_delete(PREP).await.unwrap();
    assert_eq!(server.requests_to("DELETE", &format!("/api/assist/preps/{PREP}")).len(), 1);
}

#[tokio::test]
async fn a_prep_is_scheduled_and_armed() {
    let server = MockServer::start(
        Router::new()
            .fixed("PATCH", &format!("/api/assist/preps/{PREP}"), Reply::json(200, fixture("assist_prep.json")))
            .fixed("POST", &format!("/api/assist/preps/{PREP}/arm"), Reply::json(200, json!({"armed_until": "2026-12-01T10:10:00Z"}))),
    );
    let api = client(&server);
    api.assist_prep_patch(PREP, &json!({"scheduled_at": "2026-12-01T10:00:00+00:00", "status": "scheduled"})).await.unwrap();
    assert_eq!(body(&server, "PATCH", &format!("/api/assist/preps/{PREP}")), json!({"scheduled_at": "2026-12-01T10:00:00+00:00", "status": "scheduled"}));
    assert_eq!(api.assist_prep_arm(PREP).await.unwrap().as_deref(), Some("2026-12-01T10:10:00Z"));
}

#[tokio::test]
async fn final_turns_go_in_a_batch_and_suggestion_actions_are_told() {
    let server = MockServer::start(
        Router::new()
            .fixed("POST", &format!("/api/assist/sessions/{SESSION}/turns"), Reply::json(200, json!({"accepted": 2})))
            .fixed("POST", &format!("/api/assist/sessions/{SESSION}/suggestions/s-7/action"), Reply::empty(204)),
    );
    let api = client(&server);
    let rows = vec![
        TurnRow { client_ref: "o-1".into(), channel: "other", start_ms: 81_200, end_ms: 86_400, text: "So what is your notice period?".into(), language: None },
        TurnRow { client_ref: format!("{SESSION}:m-1"), channel: "me", start_ms: 87_000, end_ms: 89_000, text: "One month.".into(), language: Some("en".into()) },
    ];
    assert_eq!(api.assist_turns(SESSION, &rows).await.unwrap(), 2);
    assert_eq!(
        body(&server, "POST", &format!("/api/assist/sessions/{SESSION}/turns")),
        json!({"turns": [
            {"client_ref": "o-1", "channel": "other", "start_ms": 81200, "end_ms": 86400, "text": "So what is your notice period?"},
            {"client_ref": format!("{SESSION}:m-1"), "channel": "me", "start_ms": 87000, "end_ms": 89000, "text": "One month.", "language": "en"}
        ]})
    );
    api.assist_suggestion_action(SESSION, "s-7", "used").await.unwrap();
    assert_eq!(body(&server, "POST", &format!("/api/assist/sessions/{SESSION}/suggestions/s-7/action")), json!({"action": "used"}));
    api.assist_suggestion_shown(SESSION, "s-7", 412).await.unwrap();
    let shown = &server.requests_to("POST", &format!("/api/assist/sessions/{SESSION}/suggestions/s-7/action"))[1];
    assert_eq!(serde_json::from_slice::<serde_json::Value>(&shown.body).unwrap(), json!({"action": "shown", "render_ms": 412}), "contract 1.9.0-n");
}

#[test]
fn a_prep_answer_that_is_not_a_prep_is_refused_in_plain_words() {
    assert_eq!(Prep::from_answer(json!("nope")).unwrap_err().to_string(), "The server sent a prep this app can't read.");
    assert_eq!(Prep::from_answer(json!({"id": "p1"})).unwrap().meeting_type, "general", "a bare object works too");
    assert!(Prep::list_from_answer(json!([{"id": "p1"}])).len() == 1, "a bare list works too");
}
