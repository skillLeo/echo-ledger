//! The typed API client against an in-process HTTP server: request shape (method, path, headers, bodies),
//! authentication, error mapping for every status the server documents, and transport failures.

mod common;

use std::time::Duration;

use serde_json::{Value, json};

use common::{Action, MockServer, Reply, Router, fixture, query_pairs, query_value, sha256_hex};
use desktop_lib::error::AppError;
use desktop_lib::net::api::{ApiClient, ApiClientCache, with_client_fallback};
use desktop_lib::net::models::*;

const TOKEN: &str = "7|eld_TOKEN_PLACEHOLDER";

fn client(server: &MockServer) -> ApiClient {
    ApiClient::new(&server.base_url(), &[], Some(TOKEN.to_string())).expect("local client")
}

fn anonymous(server: &MockServer) -> ApiClient {
    ApiClient::new(&server.base_url(), &[], None).expect("local client")
}

fn server_error(result: Result<impl std::fmt::Debug, AppError>) -> (u16, String) {
    match result {
        Err(AppError::Server {
            status, message, ..
        }) => (status, message),
        other => panic!("expected a server error, got {other:?}"),
    }
}

// ---- Construction ----------------------------------------------------------------------------------------------

#[test]
fn base_urls_are_trimmed_and_normalised() {
    common::init_tls();
    let c = ApiClient::new("  https://ledger.example.com///  ", &[], None).unwrap();
    assert_eq!(c.base_url(), "https://ledger.example.com");
    let c = ApiClient::new("http://localhost:8000/", &[], None).unwrap();
    assert_eq!(c.base_url(), "http://localhost:8000");
}

#[test]
fn plain_http_to_a_remote_host_is_refused() {
    common::init_tls();
    for url in [
        "http://ledger.example.com",
        "http://192.168.1.20:8000",
        "ftp://ledger.example.com",
        "ledger.example.com",
        "",
        "   ",
        "file:///etc/passwd",
    ] {
        assert!(
            ApiClient::new(url, &[], None).is_err(),
            "{url:?} must be rejected"
        );
    }
}

#[test]
fn https_and_loopback_http_are_accepted() {
    common::init_tls();
    for url in [
        "https://ledger.example.com",
        "https://ledger.example.com:8443/base",
        "http://localhost",
        "http://localhost:8000",
        "http://127.0.0.1:8000",
        "http://[::1]:8000",
    ] {
        assert!(
            ApiClient::new(url, &[], None).is_ok(),
            "{url:?} must be accepted"
        );
    }
}

#[test]
fn hosts_that_merely_start_like_loopback_are_not_treated_as_local() {
    common::init_tls();
    let tricky = [
        "http://localhost.attacker.example",
        "http://localhost@attacker.example/",
        "http://127.0.0.1.attacker.example",
        "http://127.0.0.1@attacker.example",
        "http://localhost:80@attacker.example",
    ];
    let accepted: Vec<&str> = tricky
        .into_iter()
        .filter(|url| ApiClient::new(url, &[], None).is_ok())
        .collect();
    assert!(
        accepted.is_empty(),
        "cleartext HTTP accepted for non-loopback hosts: {accepted:?}"
    );
}

#[test]
fn without_a_token_authenticated_calls_fail_before_touching_the_network() {
    let server = MockServer::start(Router::new());
    let c = anonymous(&server);
    let rt = tokio::runtime::Runtime::new().unwrap();
    rt.block_on(async {
        assert!(matches!(c.config().await, Err(AppError::NotPaired)));
        assert!(matches!(c.clients().await, Err(AppError::NotPaired)));
        assert!(matches!(
            c.library(None, None).await,
            Err(AppError::NotPaired)
        ));
        assert!(matches!(c.complete("x").await, Err(AppError::NotPaired)));
        assert!(matches!(
            c.delete_session("x").await,
            Err(AppError::NotPaired)
        ));
    });
    assert!(server.requests().is_empty());
}

// ---- Request shape ---------------------------------------------------------------------------------------------

#[tokio::test]
async fn config_is_a_bearer_authenticated_json_get() {
    let server = MockServer::start(Router::new().fixed(
        "GET",
        "/api/config",
        Reply::json(200, fixture("config.json")),
    ));
    let config = client(&server).config().await.unwrap();
    assert_eq!(config.retention_days, 30);

    let requests = server.requests();
    assert_eq!(requests.len(), 1);
    let r = &requests[0];
    assert_eq!((r.method.as_str(), r.path()), ("GET", "/api/config"));
    assert_eq!(
        r.header("authorization"),
        Some(format!("Bearer {TOKEN}").as_str())
    );
    assert_eq!(r.header("accept"), Some("application/json"));
    assert_eq!(
        r.header("user-agent"),
        Some(concat!("EchoLedger-Desktop/", env!("CARGO_PKG_VERSION")))
    );
}

#[tokio::test]
async fn register_sends_the_pairing_code_without_any_credentials() {
    let server = MockServer::start(Router::new().fixed(
        "POST",
        "/api/devices/register",
        Reply::json(201, fixture("register_201.json")),
    ));
    let registered = anonymous(&server)
        .register("ABCD-EFGH", "Work laptop", "windows", "build 26300")
        .await
        .unwrap();
    assert_eq!(registered.device.id, "01JB8Q3R0Z5V1K7M9N2P4S6T8W");
    assert!(registered.token.starts_with("7|"));

    let r = &server.requests()[0];
    assert_eq!(r.header("authorization"), None);
    assert_eq!(r.header("content-type"), Some("application/json"));
    assert_eq!(r.header("accept"), Some("application/json"));
    assert_eq!(
        r.json(),
        json!({"pairing_code": "ABCD-EFGH", "name": "Work laptop", "platform": "windows", "app_version": env!("CARGO_PKG_VERSION"), "os_version": "build 26300"})
    );
}

#[tokio::test]
async fn an_invalid_pairing_code_surfaces_the_server_message() {
    let body = json!({"message": "The pairing code is invalid, expired or already used.", "errors": {"pairing_code": ["The pairing code is invalid, expired or already used."]}});
    let server = MockServer::start(Router::new().fixed(
        "POST",
        "/api/devices/register",
        Reply::json(422, body),
    ));
    let (status, message) = server_error(
        anonymous(&server)
            .register("BAD", "pc", "windows", "")
            .await,
    );
    assert_eq!(
        (status, message.as_str()),
        (422, "The pairing code is invalid, expired or already used.")
    );
}

#[tokio::test]
async fn stt_token_requests_name_the_channels_and_the_sample_rate() {
    let server = MockServer::start(Router::new().fixed(
        "POST",
        "/api/stt/token",
        Reply::json(200, fixture("stt_token_assemblyai.json")),
    ));
    let token = client(&server)
        .stt_token(
            "live_captions",
            &["me", "client"],
            Some("01JB8Q4C1D2E3F4G5H6J7K8M9N"),
            None,
        )
        .await
        .unwrap();
    assert_eq!(token.grants.len(), 2);
    let r = &server.requests()[0];
    assert_eq!(
        r.json(),
        json!({"use_case": "live_captions", "channels": ["me", "client"], "client_id": "01JB8Q4C1D2E3F4G5H6J7K8M9N", "sample_rate": 16000})
    );

    client(&server)
        .stt_token("dictation", &["me"], None, None)
        .await
        .unwrap();
    assert_eq!(server.requests()[1].json()["client_id"], Value::Null);
}

#[tokio::test]
async fn live_caption_tokens_name_the_open_session_when_there_is_one() {
    let server = MockServer::start(Router::new().fixed(
        "POST",
        "/api/stt/token",
        Reply::json(200, fixture("stt_token_assemblyai.json")),
    ));
    client(&server)
        .stt_token(
            "live_captions",
            &["me", "client"],
            None,
            Some("01JB8QA0M1X2C3V4B5N6M7K8J9"),
        )
        .await
        .unwrap();
    assert_eq!(
        server.requests()[0].json(),
        json!({"use_case": "live_captions", "session_id": "01JB8QA0M1X2C3V4B5N6M7K8J9", "channels": ["me", "client"], "client_id": null, "sample_rate": 16000})
    );
}

#[tokio::test]
async fn an_open_session_is_a_session_request_without_artifacts() {
    let server = MockServer::start(Router::new().fixed(
        "POST",
        "/api/sessions",
        Reply::json(201, fixture("server/sessions_store_201_open.json")),
    ));
    let request = OpenSessionRequest {
        client_ref: "3f2b6c1e-8d4a-4d3e-9a51-2c7f0e9b1a22".into(),
        source_app: "google_meet".into(),
        client_id: None,
        title: None,
        started_at: "2026-10-24T09:00:00Z".into(),
        language: None,
    };
    let created = client(&server).open_session(&request).await.unwrap();
    assert_eq!(created.session.rest["status"], "recording");
    assert!(created.recordings.is_empty());
    let sent = server.requests()[0].json();
    assert_eq!(
        sent,
        json!({"client_ref": "3f2b6c1e-8d4a-4d3e-9a51-2c7f0e9b1a22", "source_app": "google_meet", "client_id": null, "title": null, "started_at": "2026-10-24T09:00:00Z", "language": null})
    );
    assert!(
        sent.get("artifacts").is_none(),
        "the artifacts key is what makes a create one-shot"
    );
}

fn finish_request(client_id: Option<Option<String>>) -> FinishSessionRequest {
    FinishSessionRequest {
        ended_at: "2026-10-24T09:42:10Z".into(),
        duration_ms: 2_530_000,
        title: Some("Budget review".into()),
        client_id,
        artifacts: vec![ArtifactDto {
            role: "primary".into(),
            mime: "audio/flac".into(),
            codec: Some("flac".into()),
            channels: 2,
            sample_rate: Some(16_000),
            size_bytes: 10_485_760,
            sha256: "ab".repeat(32),
            duration_ms: Some(2_530_000),
        }],
    }
}

#[tokio::test]
async fn finish_posts_the_artifacts_to_the_open_session() {
    let server = MockServer::start(Router::new().fixed(
        "POST",
        "/api/sessions/01JB8QA0M1X2C3V4B5N6M7K8J9/finish",
        Reply::json(200, fixture("server/sessions_finish_200.json")),
    ));
    let c = client(&server);
    let finished = c
        .finish_session(
            "01JB8QA0M1X2C3V4B5N6M7K8J9",
            &finish_request(Some(Some("01JB8Q4C1D2E3F4G5H6J7K8M9N".into()))),
        )
        .await
        .unwrap();
    assert_eq!(finished.recordings.len(), 1);
    assert_eq!(finished.session.rest["status"], "uploading");
    let sent = server.requests()[0].json();
    assert_eq!(sent["client_id"], "01JB8Q4C1D2E3F4G5H6J7K8M9N");
    assert_eq!(sent["title"], "Budget review");
    assert_eq!(sent["artifacts"][0]["role"], "primary");

    // A present null clears the stored client; an absent key keeps it.
    c.finish_session("01JB8QA0M1X2C3V4B5N6M7K8J9", &finish_request(Some(None)))
        .await
        .unwrap();
    let cleared = server.requests()[1].json();
    assert!(cleared.get("client_id").is_some_and(Value::is_null));
    c.finish_session("01JB8QA0M1X2C3V4B5N6M7K8J9", &finish_request(None))
        .await
        .unwrap();
    assert!(server.requests()[2].json().get("client_id").is_none());
}

#[tokio::test]
async fn a_finish_for_a_lost_session_carries_its_code() {
    let server = MockServer::start(Router::new().fixed(
        "POST",
        "/api/sessions/GONE/finish",
        Reply::json(404, fixture("server/sessions_finish_404.json")),
    ));
    let error = client(&server)
        .finish_session("GONE", &finish_request(None))
        .await
        .unwrap_err();
    assert_eq!(error.code(), Some("session_not_found"));
    assert_eq!(error.status(), Some(404));
    assert!(error.is_permanent());
}

#[tokio::test]
async fn usage_reports_are_posted_and_duplicates_are_success() {
    let server = MockServer::start(Router::new().route("POST", "/api/usage", |_, nth| {
        Action::Reply(if nth == 0 {
            Reply::json(201, fixture("server/usage_201.json"))
        } else {
            Reply::json(200, fixture("server/usage_200_duplicate.json"))
        })
    }));
    let report = UsageReport {
        report_id: "6c0d0b2e-2a4e-4c8e-9a9f-0c3f1f5b7d11".into(),
        use_case: "live_captions".into(),
        provider: "assemblyai".into(),
        session_id: Some("01JB8QA0M1X2C3V4B5N6M7K8J9".into()),
        streamed_ms: 61_000,
        channels: 2,
        ended_at: "2026-10-24T09:43:00Z".into(),
    };
    let c = client(&server);
    let first = c.report_usage(&report).await.unwrap();
    assert!(!first.report.duplicate);
    assert_eq!(first.report.billed_ms, Some(122_000));
    assert_eq!(first.usage.as_ref().map(|u| u.month.as_str()), Some("2026-10"));
    let again = c.report_usage(&report).await.unwrap();
    assert!(again.report.duplicate);
    assert_eq!(
        server.requests()[0].json(),
        json!({"report_id": "6c0d0b2e-2a4e-4c8e-9a9f-0c3f1f5b7d11", "use_case": "live_captions", "provider": "assemblyai", "session_id": "01JB8QA0M1X2C3V4B5N6M7K8J9", "streamed_ms": 61000, "channels": 2, "ended_at": "2026-10-24T09:43:00Z"})
    );
}

#[tokio::test]
async fn complete_reports_whether_the_file_is_ready_or_still_assembling() {
    let server = MockServer::start(
        Router::new()
            .fixed("POST", "/api/recordings/A/complete", Reply::json(202, json!({"status": "assembling"})))
            .fixed("POST", "/api/recordings/R/complete", Reply::json(202, json!({"status": "ready"})))
            .fixed("POST", "/api/recordings/E/complete", Reply::empty(202))
            .fixed("POST", "/api/recordings/V/complete", Reply::json(422, fixture("server/recordings_complete_422_verification_failed.json"))),
    );
    let c = client(&server);
    assert_eq!(c.complete("A").await.unwrap().status, "assembling");
    assert_eq!(c.complete("R").await.unwrap().status, "ready");
    assert_eq!(
        c.complete("E").await.unwrap().status,
        "assembling",
        "no readable status: assume the file is not safe yet"
    );
    let error = c.complete("V").await.unwrap_err();
    assert_eq!(error.code(), Some("verification_failed"));
    assert!(!error.is_permanent(), "a failed verification means: send it again");
}

#[tokio::test]
async fn a_503_from_the_token_endpoint_carries_the_admin_hint() {
    let server = MockServer::start(Router::new().fixed(
        "POST",
        "/api/stt/token",
        Reply::json(
            503,
            json!({"message": "No AssemblyAI API key is configured."}),
        ),
    ));
    let (status, message) = server_error(
        client(&server)
            .stt_token("live_captions", &["me"], None, None)
            .await,
    );
    assert_eq!(
        (status, message.as_str()),
        (503, "No AssemblyAI API key is configured.")
    );
}

#[tokio::test]
async fn library_requests_page_by_since_and_cursor() {
    let server = MockServer::start(Router::new().fixed(
        "GET",
        "/api/library",
        Reply::json(200, fixture("library_page.json")),
    ));
    let c = client(&server);
    c.library(None, None).await.unwrap();
    c.library(
        Some("2026-10-24T09:05:00+00:00"),
        Some("eyJjdXJzb3IiOiIxIn0"),
    )
    .await
    .unwrap();

    let requests = server.requests();
    assert_eq!(
        query_pairs(requests[0].query()),
        [("limit".to_string(), "100".to_string())]
    );
    // `+00:00` must reach the server as a plus sign, not as a space.
    assert_eq!(
        query_value(requests[1].query(), "since").as_deref(),
        Some("2026-10-24T09:05:00+00:00")
    );
    assert_eq!(
        query_value(requests[1].query(), "cursor").as_deref(),
        Some("eyJjdXJzb3IiOiIxIn0")
    );
    assert_eq!(
        query_value(requests[1].query(), "limit").as_deref(),
        Some("100")
    );
    assert!(requests.iter().all(|r| r.header("authorization").is_some()));
}

#[tokio::test]
async fn simple_reads_hit_the_documented_paths() {
    let server = MockServer::start(
        Router::new()
            .fixed("GET", "/api/clients", Reply::json(200, fixture("clients.json")))
            .fixed("GET", "/api/sessions/S1", Reply::json(200, fixture("session_detail.json")))
            .fixed("GET", "/api/transcripts/T1", Reply::json(200, fixture("transcript.json")))
            .fixed("GET", "/api/recordings/R1", Reply::json(200, json!({"id": "R1", "role": "primary", "upload": {"chunk_size_bytes": 10, "total_chunks": 3, "received_chunks": [0, 2], "status": "uploading"}}))),
    );
    let c = client(&server);
    assert_eq!(
        c.clients().await.unwrap()["data"][0]["name"],
        "Acme Holdings"
    );
    assert_eq!(c.session("S1").await.unwrap()["status"], "completed");
    assert_eq!(c.transcript("T1").await.unwrap()["kind"], "final");
    let status = c.recording_status("R1").await.unwrap();
    assert_eq!(
        (
            status.upload.total_chunks,
            status.upload.received_chunks.as_slice()
        ),
        (3, &[0, 2][..])
    );
}

#[tokio::test]
async fn create_session_posts_the_request_as_json() {
    let server = MockServer::start(Router::new().fixed(
        "POST",
        "/api/sessions",
        Reply::json(201, fixture("session_created.json")),
    ));
    let request = CreateSessionRequest {
        client_ref: "ref-1".into(),
        source_app: "zoom".into(),
        client_id: None,
        title: None,
        started_at: "2026-10-24T09:00:00+00:00".into(),
        ended_at: Some("2026-10-24T09:10:00+00:00".into()),
        duration_ms: Some(600_000),
        language: None,
        artifacts: vec![],
    };
    let created = client(&server).create_session(&request).await.unwrap();
    assert_eq!(created.recordings.len(), 1);
    let sent = server.requests()[0].json();
    assert_eq!(
        (
            sent["client_ref"].as_str(),
            sent["source_app"].as_str(),
            sent["duration_ms"].as_u64()
        ),
        (Some("ref-1"), Some("zoom"), Some(600_000))
    );
}

#[tokio::test]
async fn chunk_uploads_send_raw_bytes_with_index_and_checksum_headers() {
    let server = MockServer::start(Router::new().fixed(
        "POST",
        "/api/recordings/R1/chunks",
        Reply::json(200, fixture("chunk_response.json")),
    ));
    let bytes = common::pseudo_random_bytes(300_000, 5);
    let hash = sha256_hex(&bytes);
    let state = client(&server)
        .upload_chunk("R1", 7, bytes.clone(), &hash)
        .await
        .unwrap();
    assert_eq!(
        (
            state.total_chunks,
            state.received_chunks,
            state.status.as_str()
        ),
        (2, vec![0, 1], "uploading")
    );

    let r = &server.requests()[0];
    assert_eq!(r.header("content-type"), Some("application/octet-stream"));
    assert_eq!(r.header("upload-chunk-index"), Some("7"));
    assert_eq!(r.header("upload-chunk-sha256"), Some(hash.as_str()));
    assert_eq!(
        r.header("authorization"),
        Some(format!("Bearer {TOKEN}").as_str())
    );
    assert_eq!(
        r.body, bytes,
        "the body must be the raw chunk, not multipart or JSON"
    );
}

#[tokio::test]
async fn a_full_five_mebibyte_chunk_is_delivered_intact() {
    let server = MockServer::start(Router::new().fixed(
        "POST",
        "/api/recordings/R1/chunks",
        Reply::json(200, fixture("chunk_response.json")),
    ));
    let bytes = common::pseudo_random_bytes(5 * 1024 * 1024, 77);
    client(&server)
        .upload_chunk("R1", 0, bytes.clone(), &sha256_hex(&bytes))
        .await
        .unwrap();
    assert_eq!(sha256_hex(&server.requests()[0].body), sha256_hex(&bytes));
}

#[tokio::test]
async fn complete_accepts_a_202_and_reports_missing_chunks_as_a_422() {
    let server = MockServer::start(
        Router::new()
            .fixed(
                "POST",
                "/api/recordings/OK/complete",
                Reply::json(202, json!({"status": "assembling"})),
            )
            .fixed(
                "POST",
                "/api/recordings/BAD/complete",
                Reply::json(
                    422,
                    json!({"message": "Upload is incomplete.", "missing_chunks": [3, 4]}),
                ),
            ),
    );
    let c = client(&server);
    c.complete("OK").await.unwrap();
    let (status, message) = server_error(c.complete("BAD").await);
    assert_eq!((status, message.as_str()), (422, "Upload is incomplete."));
}

#[tokio::test]
async fn transcripts_are_posted_and_deletes_accept_an_empty_204() {
    let server = MockServer::start(
        Router::new()
            .fixed("POST", "/api/sessions/S1/transcripts", Reply::json(201, fixture("transcript.json")))
            .fixed("DELETE", "/api/sessions/S1", Reply::empty(204))
            .fixed("DELETE", "/api/sessions/GONE", Reply::json(404, json!({"message": "No query results for model [App\\Models\\CaptureSession] GONE"}))),
    );
    let c = client(&server);
    let transcript = DeviceTranscript {
        kind: "live".into(),
        provider: "assemblyai".into(),
        language: None,
        segments: vec![],
    };
    c.post_transcript("S1", &transcript).await.unwrap();
    assert_eq!(server.requests()[0].json()["kind"], "live");
    c.delete_session("S1").await.unwrap();
    let (status, _) = server_error(c.delete_session("GONE").await);
    assert_eq!(status, 404);
}

// ---- Status code mapping ---------------------------------------------------------------------------------------

#[tokio::test]
async fn every_documented_error_status_maps_to_a_typed_error() {
    let cases: Vec<(u16, Reply, Option<&str>)> = vec![
        (
            401,
            Reply::json(401, json!({"message": "Unauthenticated."})),
            None,
        ),
        (
            403,
            Reply::json(403, json!({"message": "Invalid ability provided."})),
            Some("Invalid ability provided."),
        ),
        (
            404,
            Reply::json(
                404,
                json!({"message": "No query results for model [App\\Models\\Recording] 01JB"}),
            ),
            Some("No query results for model [App\\Models\\Recording] 01JB"),
        ),
        (
            409,
            Reply::json(409, json!({"message": "Upload already complete."})),
            Some("Upload already complete."),
        ),
        (
            413,
            Reply::json(413, json!({"message": "Payload Too Large"})),
            Some("Payload Too Large"),
        ),
        (
            422,
            Reply::json(
                422,
                json!({"message": "The given data was invalid.", "errors": {"sha256": ["The sha256 field format is invalid."]}}),
            ),
            Some("The given data was invalid."),
        ),
        (
            429,
            Reply::json(429, json!({"message": "Too Many Attempts."})).header("Retry-After", "30"),
            Some("Too Many Attempts."),
        ),
        (
            500,
            Reply::html(500, "<!DOCTYPE html><html><body>Server Error</body></html>"),
            Some("request failed"),
        ),
        (
            502,
            Reply::html(502, "<html>Bad Gateway</html>"),
            Some("request failed"),
        ),
        (
            503,
            Reply::json(503, json!({"message": "Service Unavailable"})),
            Some("Service Unavailable"),
        ),
        (500, Reply::empty(500), Some("request failed")),
        (500, Reply::raw_json(500, "null"), Some("request failed")),
        (
            422,
            Reply::raw_json(422, "{\"message\": [\"not a string\"]}"),
            Some("request failed"),
        ),
        (
            422,
            Reply::raw_json(422, "{\"message\": "),
            Some("request failed"),
        ),
    ];
    for (status, reply, expected_message) in cases {
        let server = MockServer::start(Router::new().fixed("GET", "/api/config", reply));
        let result = client(&server).config().await;
        match expected_message {
            None => assert!(
                matches!(result, Err(AppError::NotPaired)),
                "{status}: {result:?}"
            ),
            Some(message) => {
                let (got_status, got_message) = server_error(result);
                assert_eq!(
                    (got_status, got_message.as_str()),
                    (status, message),
                    "status {status}"
                );
            }
        }
    }
}

#[tokio::test]
async fn a_401_means_the_device_was_revoked_for_every_authenticated_call() {
    let reply = Reply::json(401, json!({"message": "Unauthenticated."}));
    let server = MockServer::start(
        Router::new()
            .fixed("GET", "/api/library", reply.clone())
            .fixed("POST", "/api/recordings/R/chunks", reply.clone())
            .fixed("POST", "/api/recordings/R/complete", reply.clone())
            .fixed("DELETE", "/api/sessions/S", reply.clone())
            .fixed("POST", "/api/sessions/S/transcripts", reply),
    );
    let c = client(&server);
    assert!(matches!(
        c.library(None, None).await,
        Err(AppError::NotPaired)
    ));
    assert!(matches!(
        c.upload_chunk("R", 0, vec![1], "00").await,
        Err(AppError::NotPaired)
    ));
    assert!(matches!(c.complete("R").await, Err(AppError::NotPaired)));
    assert!(matches!(
        c.delete_session("S").await,
        Err(AppError::NotPaired)
    ));
    let transcript = DeviceTranscript {
        kind: "live".into(),
        provider: "x".into(),
        language: None,
        segments: vec![],
    };
    assert!(matches!(
        c.post_transcript("S", &transcript).await,
        Err(AppError::NotPaired)
    ));
}

#[tokio::test]
async fn a_rate_limit_error_exposes_the_retry_after_delay() {
    // docs/API.md: 429 must be honoured with Retry-After; the caller can only do that if the error carries it.
    let server = MockServer::start(Router::new().fixed(
        "GET",
        "/api/config",
        Reply::json(429, json!({"message": "Too Many Attempts."})).header("Retry-After", "37"),
    ));
    let error = client(&server).config().await.unwrap_err();
    let text = error.to_string();
    assert!(
        text.contains("37"),
        "the Retry-After value is lost: {text:?}"
    );
    assert_eq!(error.retry_after_secs(), Some(37));
    assert_eq!(
        text,
        "Server returned 429: Too Many Attempts. Try again in 37 s."
    );
}

#[tokio::test]
async fn the_error_code_and_retry_after_come_from_the_documented_places() {
    let server = MockServer::start(
        Router::new()
            .fixed(
                "GET",
                "/api/config",
                Reply::json(429, fixture("server/errors_429_retry_after.json")).header("Retry-After", "7"),
            )
            .fixed(
                "GET",
                "/api/library",
                Reply::json(429, json!({"message": "Too Many Attempts.", "code": "rate_limited"}))
                    .header("Retry-After", "Wed, 21 Oct 2026 07:28:00 GMT"),
            )
            .fixed(
                "GET",
                "/api/clients",
                Reply::json(422, json!({"message": "x", "code": ["not", "a", "string"]})),
            ),
    );
    let c = client(&server);
    let limited = c.config().await.unwrap_err();
    assert_eq!(
        (limited.status(), limited.code(), limited.retry_after_secs()),
        (Some(429), Some("rate_limited"), Some(7))
    );
    let dated = c.library(None, None).await.unwrap_err();
    assert_eq!(dated.retry_after_secs(), None, "an HTTP-date is ignored");
    assert_eq!(dated.to_string(), "Server returned 429: Too Many Attempts.");
    let odd = c.clients().await.unwrap_err();
    assert_eq!(odd.code(), None);
}

#[test]
fn permanent_and_transient_errors_follow_the_documented_table() {
    let server = |status: u16, code: Option<&str>| AppError::Server {
        status,
        message: "m".into(),
        code: code.map(String::from),
        retry_after_secs: None,
    };
    for permanent in [
        server(400, None),
        server(403, Some("missing_ability")),
        server(403, Some("budget_exceeded")),
        server(404, Some("session_not_found")),
        server(404, None),
        server(409, Some("upload_complete")),
        server(413, Some("too_large")),
        server(422, Some("validation_failed")),
        server(422, None),
        AppError::NotPaired,
    ] {
        assert!(permanent.is_permanent(), "{permanent:?}");
    }
    for transient in [
        server(408, None),
        server(429, Some("rate_limited")),
        server(422, Some("upload_incomplete")),
        server(422, Some("verification_failed")),
        server(500, None),
        server(502, Some("provider_error")),
        server(503, Some("provider_not_configured")),
        AppError::Network("connection refused".into()),
        AppError::msg("anything else"),
    ] {
        assert!(!transient.is_permanent(), "{transient:?}");
    }
}

#[test]
fn the_app_keeps_one_http_client_until_its_inputs_change() {
    common::init_tls();
    let cache = ApiClientCache::default();
    let pins = vec![format!("sha256/{}", "A".repeat(43) + "=")];
    let a = cache.get("https://ledger.example.test", &pins, "t1").unwrap();
    let b = cache.get("https://ledger.example.test", &pins, "t1").unwrap();
    assert_eq!(cache.builds(), 1, "the same inputs reuse the client");
    assert_eq!(a.base_url(), b.base_url());
    cache.get("https://ledger.example.test", &pins, "t2").unwrap();
    assert_eq!(cache.builds(), 2, "a new token builds a new client");
    cache.get("https://ledger.example.test", &[], "t2").unwrap();
    assert_eq!(cache.builds(), 3, "new pins build a new client");
    cache.get("https://other.example.test", &[], "t2").unwrap();
    cache.get("https://other.example.test", &[], "t2").unwrap();
    assert_eq!(cache.builds(), 4, "a new server builds a new client");
    cache.clear();
    cache.get("https://other.example.test", &[], "t2").unwrap();
    assert_eq!(cache.builds(), 5);
    assert!(
        cache.get("https://other.example.test", &["garbage".into()], "t2").is_err(),
        "unusable pins are refused, not cached"
    );
    assert_eq!(cache.builds(), 5);
}

#[tokio::test]
async fn a_deleted_client_is_dropped_and_the_call_repeated_once_without_it() {
    let server = MockServer::start(Router::new().route("POST", "/api/stt/token", |r, _| {
        Action::Reply(if r.json()["client_id"].is_null() {
            Reply::json(200, fixture("stt_token_assemblyai.json"))
        } else {
            Reply::json(404, json!({"message": "Client not found.", "code": "client_not_found"}))
        })
    }));
    let c = client(&server);
    let (token, cleared) = with_client_fallback(Some("01DELETEDCLIENT0000000000".to_string()), |client_id| {
        let c = c.clone();
        async move { c.stt_token("dictation", &["me"], client_id.as_deref(), None).await }
    })
    .await
    .unwrap();
    assert_eq!(token.grants.len(), 2);
    assert_eq!(cleared.as_deref(), Some("01DELETEDCLIENT0000000000"));
    assert_eq!(server.requests().len(), 2);

    // Without a client, or for another error, nothing is repeated.
    let (_, cleared) = with_client_fallback(None, |client_id| {
        let c = c.clone();
        async move { c.stt_token("dictation", &["me"], client_id.as_deref(), None).await }
    })
    .await
    .unwrap();
    assert_eq!(cleared, None);
    assert_eq!(server.requests().len(), 3);
}

// ---- Malformed and failing responses ---------------------------------------------------------------------------

#[tokio::test]
async fn a_success_response_that_is_not_the_expected_json_is_an_error_not_a_panic() {
    let server = MockServer::start(
        Router::new()
            .fixed(
                "GET",
                "/api/config",
                Reply::html(200, "<html>captive portal login</html>"),
            )
            .fixed(
                "GET",
                "/api/library",
                Reply::json(200, json!({"data": "not a list"})),
            )
            .fixed(
                "GET",
                "/api/recordings/R",
                Reply::json(200, json!({"id": "R"})),
            ),
    );
    let c = client(&server);
    assert!(matches!(c.config().await, Err(AppError::Network(_))));
    assert!(matches!(
        c.library(None, None).await,
        Err(AppError::Network(_))
    ));
    assert!(matches!(
        c.recording_status("R").await,
        Err(AppError::Network(_))
    ));
}

#[tokio::test]
async fn connection_failures_are_network_errors_and_never_leak_the_token() {
    let port = {
        let listener = std::net::TcpListener::bind("127.0.0.1:0").unwrap();
        listener.local_addr().unwrap().port()
    };
    let c = ApiClient::new(
        &format!("http://127.0.0.1:{port}"),
        &[],
        Some(TOKEN.to_string()),
    )
    .unwrap();
    match c.config().await {
        Err(AppError::Network(message)) => {
            assert!(!message.contains(TOKEN), "token leaked into: {message}")
        }
        other => panic!("expected a network error, got {other:?}"),
    }
}

#[tokio::test]
async fn a_server_that_hangs_up_without_answering_is_a_network_error() {
    let server = MockServer::start(Router::new().route("GET", "/api/config", |_, _| Action::Drop));
    assert!(matches!(
        client(&server).config().await,
        Err(AppError::Network(_))
    ));
}

#[tokio::test]
async fn a_response_cut_off_mid_body_is_a_network_error() {
    let body = fixture("config.json").to_string();
    let server = MockServer::start(Router::new().route("GET", "/api/config", move |_, _| {
        Action::Truncated {
            reply: Reply::raw_json(200, &body[..body.len() / 2]),
            claimed: body.len(),
        }
    }));
    assert!(matches!(
        client(&server).config().await,
        Err(AppError::Network(_))
    ));
}

#[tokio::test]
async fn concurrent_requests_are_all_answered() {
    let server = MockServer::start(Router::new().fixed(
        "GET",
        "/api/config",
        Reply::json(200, fixture("config.json")),
    ));
    let c = client(&server);
    let tasks: Vec<_> = (0..24)
        .map(|_| {
            let c = c.clone();
            tokio::spawn(async move { c.config().await.map(|cfg| cfg.retention_days) })
        })
        .collect();
    for task in tasks {
        assert_eq!(task.await.unwrap().unwrap(), 30);
    }
    assert_eq!(server.requests().len(), 24);
}

// ---- Redirects -------------------------------------------------------------------------------------------------

#[tokio::test]
async fn the_bearer_token_is_not_forwarded_to_a_different_origin_on_redirect() {
    let elsewhere = MockServer::start(Router::new().fixed(
        "GET",
        "/api/config",
        Reply::json(200, fixture("config.json")),
    ));
    let target = format!("{}/api/config", elsewhere.base_url());
    let origin = MockServer::start(Router::new().route("GET", "/api/config", move |_, _| {
        Action::Reply(Reply::empty(302).header("Location", &target))
    }));
    let result = client(&origin).config().await;
    assert!(result.is_ok(), "{result:?}");
    let forwarded = &elsewhere.requests()[0];
    assert_eq!(
        forwarded.header("authorization"),
        None,
        "credentials followed the redirect to another origin"
    );
}

// ---- Timeouts (slow) -------------------------------------------------------------------------------------------

#[tokio::test]
#[ignore = "waits for the 120 s request timeout; run with --ignored"]
async fn a_server_that_never_answers_times_out_after_two_minutes() {
    let server = MockServer::start(Router::new().route("GET", "/api/config", |_, _| {
        Action::Stall(Duration::from_secs(300))
    }));
    let started = std::time::Instant::now();
    let result = client(&server).config().await;
    let elapsed = started.elapsed();
    assert!(matches!(result, Err(AppError::Network(_))), "{result:?}");
    assert!(
        elapsed >= Duration::from_secs(115) && elapsed <= Duration::from_secs(135),
        "timed out after {elapsed:?}"
    );
}
