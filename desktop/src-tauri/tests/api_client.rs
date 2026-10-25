//! The typed API client against an in-process HTTP server: request shape (method, path, headers, bodies),
//! authentication, error mapping for every status the server documents, and transport failures.

mod common;

use std::time::Duration;

use serde_json::{Value, json};

use common::{Action, MockServer, Reply, Router, fixture, query_pairs, query_value, sha256_hex};
use desktop_lib::error::AppError;
use desktop_lib::net::api::ApiClient;
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
        Err(AppError::Server { status, message }) => (status, message),
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
#[ignore = "A6.DR2: only real loopback hosts may use plain HTTP; look-alike hosts and userinfo must be refused"]
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
        .stt_token("dictation", &["me"], None)
        .await
        .unwrap();
    assert_eq!(server.requests()[1].json()["client_id"], Value::Null);
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
            .stt_token("live_captions", &["me"], None)
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
#[ignore = "A6.DR4: a 429 error must carry the Retry-After delay"]
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
