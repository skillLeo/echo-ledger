//! Organising the library on the desktop (plan B15): pins, labels, client folders, bulk actions and the zip export,
//! against an in-process HTTP server. The checks made before anything is sent are unit tests in commands/tests.rs.

mod common;

use serde_json::json;

use common::{MockServer, Reply, Router};
use desktop_lib::net::api::ApiClient;

const TOKEN: &str = "7|eld_TOKEN_PLACEHOLDER";
const ID: &str = "01JB8Q4C1D2E3F4G5H6J7K8M9N";

fn client(server: &MockServer) -> ApiClient {
    common::init_tls();
    ApiClient::new(&server.base_url(), &[], Some(TOKEN.to_string())).expect("local client")
}

#[tokio::test]
async fn a_recording_is_pinned_and_labelled_with_one_patch() {
    let server = MockServer::start(Router::new().fixed("PATCH", &format!("/api/sessions/{ID}"), Reply::json(200, json!({"data": {"id": ID, "pinned": true}}))));

    let answer = client(&server).update_session(ID, &json!({"pinned": true, "labels": ["Invoice"]})).await.unwrap();

    assert_eq!(answer["data"]["pinned"], true);
    assert_eq!(server.requests()[0].json(), json!({"pinned": true, "labels": ["Invoice"]}));
}

#[tokio::test]
async fn bulk_actions_send_the_ids_action_and_target() {
    let server = MockServer::start(Router::new().fixed("POST", "/api/sessions/bulk", Reply::json(200, json!({"changed": 2}))));
    let ids = vec![ID.to_string(), "01JB8Q4C1D2E3F4G5H6J7K8M9P".to_string()];

    let answer = client(&server).bulk_sessions(&ids, "move", Some("01JCLIENT00000000000000000"), None).await.unwrap();

    assert_eq!(answer["changed"], 2);
    assert_eq!(server.requests()[0].json(), json!({"ids": ids, "action": "move", "client_id": "01JCLIENT00000000000000000", "label": null}));
}

#[tokio::test]
async fn the_zip_export_keeps_the_server_s_name() {
    let server = MockServer::start(Router::new().fixed(
        "POST",
        "/api/sessions/export",
        Reply::html(200, "PK-zip-bytes").header("Content-Disposition", "attachment; filename=\"EchoLedger transcripts 2026-10-28.zip\""),
    ));

    let (bytes, name) = client(&server).export_sessions(&[ID.to_string()], "docx").await.unwrap();

    assert_eq!(bytes, b"PK-zip-bytes");
    assert_eq!(name, "EchoLedger transcripts 2026-10-28.zip");
    assert_eq!(server.requests()[0].json(), json!({"ids": [ID], "format": "docx"}));
}

#[tokio::test]
async fn nothing_to_export_is_a_readable_error() {
    let server = MockServer::start(Router::new().fixed(
        "POST",
        "/api/sessions/export",
        Reply::json(409, json!({"code": "nothing_to_export", "message": "None of these recordings has a finished transcript."})),
    ));

    let err = client(&server).export_sessions(&[ID.to_string()], "txt").await.unwrap_err();

    assert!(err.to_string().contains("None of these recordings has a finished transcript."), "{err}");
}

#[tokio::test]
async fn client_folders_are_created_and_updated() {
    let server = MockServer::start(
        Router::new()
            .fixed("POST", "/api/clients", Reply::json(201, json!({"data": {"id": ID, "name": "Acme", "avatar": "AC"}})))
            .fixed("PATCH", &format!("/api/clients/{ID}"), Reply::json(200, json!({"data": {"id": ID, "pinned": true}}))),
    );
    let api = client(&server);

    assert_eq!(api.create_client(&json!({"name": "Acme", "color": "#22d3ee", "avatar": "AC"})).await.unwrap()["data"]["avatar"], "AC");
    assert_eq!(api.update_client(ID, &json!({"pinned": true})).await.unwrap()["data"]["pinned"], true);
    assert_eq!(server.requests()[1].json(), json!({"pinned": true}));
}
