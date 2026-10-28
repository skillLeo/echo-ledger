//! Transcript tools on the desktop (plan B7-B12): search, edits, speaker names, exports, share links and
//! "Add to vocabulary", against an in-process HTTP server.

mod common;

use serde_json::{Value, json};

use common::{MockServer, Reply, Router, query_value};
use desktop_lib::error::AppError;
use desktop_lib::net::api::{ApiClient, filename_from_disposition};

const TOKEN: &str = "7|eld_TOKEN_PLACEHOLDER";
const ID: &str = "01JB8Q4C1D2E3F4G5H6J7K8M9N";

fn client(server: &MockServer) -> ApiClient {
    common::init_tls();
    ApiClient::new(&server.base_url(), &[], Some(TOKEN.to_string())).expect("local client")
}

#[tokio::test]
async fn search_sends_the_query_and_filters() {
    let server = MockServer::start(Router::new().fixed("GET", "/api/search", Reply::json(200, json!({"data": [], "next_page": null}))));
    let params = vec![("q".to_string(), "invoice due".to_string()), ("tag".to_string(), "Budget".to_string()), ("bookmarked".to_string(), "1".to_string())];

    client(&server).search(&params).await.unwrap();

    let r = &server.requests()[0];
    assert_eq!(query_value(r.query(), "q").as_deref(), Some("invoice due"));
    assert_eq!(query_value(r.query(), "tag").as_deref(), Some("Budget"));
    assert_eq!(query_value(r.query(), "bookmarked").as_deref(), Some("1"));
    assert_eq!(r.header("authorization"), Some(format!("Bearer {TOKEN}").as_str()));
}

#[tokio::test]
async fn an_edit_patches_one_line() {
    let path = format!("/api/transcripts/{ID}");
    let server = MockServer::start(Router::new().fixed("PATCH", &path, Reply::json(200, json!({"id": ID, "segments": []}))));

    client(&server).edit_transcript(ID, 3, "Corrected text").await.unwrap();

    assert_eq!(server.requests()[0].json(), json!({"edits": [{"index": 3, "text": "Corrected text"}]}));
}

#[tokio::test]
async fn find_and_replace_restore_and_revisions_use_their_routes() {
    let server = MockServer::start(
        Router::new()
            .fixed("POST", &format!("/api/transcripts/{ID}/replace"), Reply::json(200, json!({"replaced": 2, "transcript": {}})))
            .fixed("POST", &format!("/api/transcripts/{ID}/restore"), Reply::json(200, json!({"id": ID})))
            .fixed("GET", &format!("/api/transcripts/{ID}/revisions"), Reply::json(200, json!({"data": [{"source": "original"}]}))),
    );
    let api = client(&server);

    assert_eq!(api.replace_in_transcript(ID, "acme", "Acme Ltd", false, true).await.unwrap()["replaced"], 2);
    api.restore_transcript(ID).await.unwrap();
    assert_eq!(api.transcript_revisions(ID).await.unwrap()["data"][0]["source"], "original");

    assert_eq!(server.requests()[0].json(), json!({"find": "acme", "replace": "Acme Ltd", "match_case": false, "whole_word": true}));
}

#[tokio::test]
async fn speakers_are_renamed_by_label() {
    let server = MockServer::start(Router::new().fixed("PUT", &format!("/api/sessions/{ID}/speakers"), Reply::json(200, json!({"speaker_names": {"Client": "Jane"}}))));
    let mut names = serde_json::Map::new();
    names.insert("Client".into(), Value::from("Jane"));
    names.insert("Me".into(), Value::Null);

    let answer = client(&server).rename_speakers(ID, &names, true).await.unwrap();

    assert_eq!(answer["speaker_names"]["Client"], "Jane");
    assert_eq!(server.requests()[0].json(), json!({"names": {"Client": "Jane", "Me": null}, "remember_for_client": true}));
}

#[tokio::test]
async fn an_export_returns_the_file_and_the_server_s_name() {
    let path = format!("/api/transcripts/{ID}/export");
    let server = MockServer::start(Router::new().fixed(
        "GET",
        &path,
        Reply::html(200, "WEBVTT\n").header("Content-Disposition", "attachment; filename=\"Acme 2026-10-24.vtt\"; filename*=utf-8''%D9%82%DB%8C%D9%85%D8%AA%202026-10-24.vtt"),
    ));

    let (bytes, name) = client(&server).export_transcript(ID, "vtt", true, false, None).await.unwrap();

    assert_eq!(bytes, b"WEBVTT\n");
    assert_eq!(name, "قیمت 2026-10-24.vtt", "the UTF-8 name wins");
    let r = &server.requests()[0];
    assert_eq!(query_value(r.query(), "format").as_deref(), Some("vtt"));
    assert_eq!(query_value(r.query(), "speakers").as_deref(), Some("0"));
}

#[tokio::test]
async fn an_unfinished_transcript_cannot_be_exported() {
    let server = MockServer::start(Router::new().fixed(
        "GET",
        &format!("/api/transcripts/{ID}/export"),
        Reply::json(409, json!({"message": "The transcript is not finished yet.", "code": "transcript_not_ready"})),
    ));

    match client(&server).export_transcript(ID, "txt", true, true, None).await {
        Err(AppError::Server { status, code, .. }) => assert_eq!((status, code.as_deref()), (409, Some("transcript_not_ready"))),
        other => panic!("expected a refusal, got {other:?}"),
    }
}

#[tokio::test]
async fn share_links_are_made_listed_and_revoked() {
    let server = MockServer::start(
        Router::new()
            .fixed("POST", &format!("/api/sessions/{ID}/shares"), Reply::json(201, json!({"id": "s1", "url": "https://ledger.example.com/s/abc"})))
            .fixed("GET", &format!("/api/sessions/{ID}/shares"), Reply::json(200, json!({"data": [{"id": "s1", "active": true}]})))
            .fixed("DELETE", "/api/shares/s1", Reply::empty(204)),
    );
    let api = client(&server);

    assert_eq!(api.create_share(ID, 86_400, Some("secret12"), true).await.unwrap()["url"], "https://ledger.example.com/s/abc");
    assert_eq!(api.list_shares(ID).await.unwrap()["data"][0]["active"], true);
    api.revoke_share("s1").await.unwrap();

    assert_eq!(server.requests()[0].json(), json!({"expires_in": 86400, "password": "secret12", "include_audio": true}));
}

#[tokio::test]
async fn a_keyterm_is_added_for_the_client() {
    let server = MockServer::start(Router::new().fixed("POST", "/api/vocabulary/keyterms", Reply::json(201, json!({"keyterms": ["Farah Khan"]}))));

    client(&server).add_keyterm("Farah Khan", Some(ID)).await.unwrap();

    assert_eq!(server.requests()[0].json(), json!({"term": "Farah Khan", "client_id": ID}));
}

#[test]
fn export_names_are_safe_for_the_file_system() {
    assert_eq!(filename_from_disposition("attachment; filename=\"a.txt\"").as_deref(), Some("a.txt"));
    assert_eq!(filename_from_disposition("attachment; filename=\"../../evil.txt\"").as_deref(), Some("evil.txt"));
    assert_eq!(filename_from_disposition("attachment; filename*=utf-8''a%2Fb%3A.md").as_deref(), Some("ab.md"));
    assert_eq!(filename_from_disposition("attachment").as_deref(), None);
    assert_eq!(filename_from_disposition("attachment; filename=\"...\"").as_deref(), None);
}
