//! End-to-end behaviour of the resumable chunk uploader against a scripted server: chunk boundaries, checksums,
//! resume after a partial upload, retry and back-off, permanent versus transient failures, and the artifacts it
//! declares.

mod common;

use std::collections::BTreeMap;
use std::path::{Path, PathBuf};
use std::sync::{Arc, Mutex};
use std::time::Duration;

use serde_json::{Value, json};

use common::{MockServer, Reply, Router, fixture, pseudo_random_bytes, sha256_hex};
use desktop_lib::error::AppError;
use desktop_lib::net::api::ApiClient;
use desktop_lib::net::models::SegmentDto;
use desktop_lib::net::uploader::{
    PendingUpload, UploadQueue, file_sha256, missing_chunks, plan_chunks, upload,
};

const SESSION_ID: &str = "01JB8QA0M1X2C3V4B5N6M7K8J9";

// ---- Scripted server -------------------------------------------------------------------------------------------

struct Slot {
    role: &'static str,
    size: u64,
    already_received: Vec<u64>,
    status: &'static str,
}

impl Slot {
    fn fresh(role: &'static str, size: u64) -> Self {
        Self {
            role,
            size,
            already_received: vec![],
            status: "pending",
        }
    }
}

/// `(index, attempt starting at 1)` -> a reply that replaces normal handling, e.g. an injected failure.
type Inject = Arc<dyn Fn(u64, usize) -> Option<Reply> + Send + Sync>;
type Stored = Arc<Mutex<BTreeMap<(String, u64), Vec<u8>>>>;

struct Flow {
    config: Value,
    chunk_size: u64,
    slots: Vec<Slot>,
    inject: Inject,
    session_reply: Option<Reply>,
    config_reply: Option<Reply>,
    transcript_reply: Reply,
}

impl Flow {
    fn new(chunk_size: u64, slots: Vec<Slot>) -> Self {
        Self {
            config: fixture("config.json"),
            chunk_size,
            slots,
            inject: Arc::new(|_, _| None),
            session_reply: None,
            config_reply: None,
            transcript_reply: Reply::json(201, fixture("transcript.json")),
        }
    }

    fn inject(mut self, f: impl Fn(u64, usize) -> Option<Reply> + Send + Sync + 'static) -> Self {
        self.inject = Arc::new(f);
        self
    }

    fn start(self) -> (MockServer, Stored) {
        let stored: Stored = Arc::new(Mutex::new(BTreeMap::new()));
        let total = |size: u64| size.div_ceil(self.chunk_size);
        let recordings: Vec<Value> = self
            .slots
            .iter()
            .map(|s| {
                json!({"id": format!("REC-{}", s.role), "role": s.role,
                       "upload": {"chunk_size_bytes": self.chunk_size, "total_chunks": total(s.size), "received_chunks": s.already_received, "status": s.status}})
            })
            .collect();
        let mut session = fixture("session_created.json");
        session["recordings"] = Value::Array(recordings);

        let config_reply = self
            .config_reply
            .clone()
            .unwrap_or_else(|| Reply::json(200, self.config.clone()));
        let session_reply = self
            .session_reply
            .clone()
            .unwrap_or_else(|| Reply::json(201, session));
        let mut router = Router::new()
            .fixed("GET", "/api/config", config_reply)
            .fixed("POST", "/api/sessions", session_reply)
            .fixed(
                "POST",
                &format!("/api/sessions/{SESSION_ID}/transcripts"),
                self.transcript_reply.clone(),
            );

        for slot in &self.slots {
            let id = format!("REC-{}", slot.role);
            let (baseline, total_chunks, status) =
                (slot.already_received.clone(), total(slot.size), "uploading");
            let (stored, inject) = (stored.clone(), self.inject.clone());
            let attempts: Arc<Mutex<BTreeMap<u64, usize>>> = Arc::new(Mutex::new(BTreeMap::new()));
            let chunk_id = id.clone();
            router = router.route("POST", &format!("/api/recordings/{id}/chunks"), move |request, _| {
                let index: u64 = request.header("upload-chunk-index").and_then(|v| v.parse().ok()).expect("Upload-Chunk-Index header");
                let attempt = {
                    let mut map = attempts.lock().unwrap();
                    let n = map.entry(index).or_insert(0);
                    *n += 1;
                    *n
                };
                if let Some(reply) = inject(index, attempt) {
                    return reply.into();
                }
                if request.header("upload-chunk-sha256") != Some(sha256_hex(&request.body).as_str()) {
                    return Reply::json(422, json!({"message": "The chunk checksum does not match."})).into();
                }
                let mut store = stored.lock().unwrap();
                store.insert((chunk_id.clone(), index), request.body.clone());
                let mut received: Vec<u64> = baseline.clone();
                received.extend(store.keys().filter(|(rec, _)| *rec == chunk_id).map(|(_, i)| *i));
                received.sort_unstable();
                received.dedup();
                Reply::json(200, json!({"received_chunks": received, "total_chunks": total_chunks, "status": status})).into()
            });
            router = router.fixed(
                "POST",
                &format!("/api/recordings/{id}/complete"),
                Reply::json(202, json!({"status": "assembling"})),
            );
        }
        (MockServer::start(router), stored)
    }
}

// ---- Fixtures --------------------------------------------------------------------------------------------------

fn write_file(dir: &Path, name: &str, len: usize, seed: u64) -> PathBuf {
    let path = dir.join(name);
    std::fs::write(&path, pseudo_random_bytes(len, seed)).unwrap();
    path
}

fn pending(dir: &Path, primary: PathBuf) -> PendingUpload {
    PendingUpload {
        client_ref: "3f2b6c1e-8d4a-4d3e-9a51-2c7f0e9b1a22".into(),
        source_app: "whatsapp".into(),
        client_id: None,
        started_at: "2026-10-24T09:00:00+00:00".into(),
        ended_at: "2026-10-24T09:42:10+00:00".into(),
        duration_ms: 2_530_000,
        dir: dir.to_path_buf(),
        primary,
        primary_channels: 2,
        mic_wav: None,
        system_wav: None,
        live_segments: vec![],
        live_provider: None,
        attempts: 0,
        last_error: None,
    }
}

fn api(server: &MockServer) -> ApiClient {
    ApiClient::new(&server.base_url(), &[], Some("tok".into())).unwrap()
}

fn chunk_requests(server: &MockServer, role: &str) -> Vec<common::Recorded> {
    server.requests_to("POST", &format!("/api/recordings/REC-{role}/chunks"))
}

fn index_of(r: &common::Recorded) -> u64 {
    r.header("upload-chunk-index").unwrap().parse().unwrap()
}

/// The validation `SessionController@store` applies to a session request (docs/API.md and the Laravel rules).
fn assert_acceptable_to_the_server(request: &Value, accepted_mimes: &[&str]) {
    assert!(
        request["client_ref"]
            .as_str()
            .is_some_and(|s| !s.is_empty() && s.len() <= 64)
    );
    assert!(
        [
            "whatsapp",
            "whatsapp_business",
            "google_meet",
            "zoom",
            "voice_note",
            "other"
        ]
        .contains(&request["source_app"].as_str().unwrap())
    );
    assert!(request["duration_ms"].as_u64().unwrap() <= 86_400_000);
    assert!(request["ended_at"].as_str().unwrap() >= request["started_at"].as_str().unwrap());
    let artifacts = request["artifacts"].as_array().unwrap();
    assert!((1..=3).contains(&artifacts.len()));
    let roles: Vec<&str> = artifacts
        .iter()
        .map(|a| a["role"].as_str().unwrap())
        .collect();
    assert!(roles.contains(&"primary"));
    let mut unique = roles.clone();
    unique.sort_unstable();
    unique.dedup();
    assert_eq!(unique.len(), roles.len(), "roles must be unique");
    for a in artifacts {
        assert!(["primary", "channel_1", "channel_2"].contains(&a["role"].as_str().unwrap()));
        assert!(
            accepted_mimes.contains(&a["mime"].as_str().unwrap()),
            "mime {} not accepted",
            a["mime"]
        );
        assert!((1..=2).contains(&a["channels"].as_u64().unwrap()));
        assert!(
            a["sample_rate"].is_null()
                || (8_000..=192_000).contains(&a["sample_rate"].as_u64().unwrap())
        );
        assert!(a["size_bytes"].as_u64().unwrap() >= 1);
        let sha = a["sha256"].as_str().unwrap();
        assert!(
            sha.len() == 64 && sha.chars().all(|c| c.is_ascii_hexdigit()),
            "sha256 {sha}"
        );
    }
}

const ACCEPTED: [&str; 10] = [
    "audio/ogg",
    "audio/opus",
    "audio/flac",
    "audio/wav",
    "audio/x-wav",
    "audio/mpeg",
    "audio/mp4",
    "audio/aac",
    "audio/webm",
    "audio/amr",
];

// ---- Pure planning helpers -------------------------------------------------------------------------------------

#[test]
fn chunk_plans_cover_every_byte_exactly_once() {
    for chunk in [1u64, 2, 3, 7, 100, 1_000, 5_242_880] {
        for size in [
            1u64, 2, 3, 99, 100, 101, 999, 1_000, 1_001, 4_999, 5_242_879, 5_242_880, 5_242_881,
            15_728_641,
        ] {
            if size / chunk > 200_000 {
                continue;
            }
            let plan = plan_chunks(size, chunk);
            assert_eq!(
                plan.len() as u64,
                size.div_ceil(chunk),
                "size {size} chunk {chunk}"
            );
            assert_eq!(plan.iter().map(|(_, len)| len).sum::<u64>(), size);
            let mut expected_offset = 0;
            for (i, (offset, len)) in plan.iter().enumerate() {
                assert_eq!(*offset, expected_offset, "contiguous at chunk {i}");
                assert!(*len >= 1 && *len <= chunk);
                if i + 1 < plan.len() {
                    assert_eq!(*len, chunk, "only the last chunk may be short");
                }
                expected_offset += len;
            }
        }
    }
}

#[test]
fn chunk_plan_edge_cases() {
    assert_eq!(plan_chunks(1, 5_242_880), vec![(0, 1)]);
    assert_eq!(plan_chunks(5_242_880, 5_242_880), vec![(0, 5_242_880)]);
    assert_eq!(
        plan_chunks(5_242_881, 5_242_880),
        vec![(0, 5_242_880), (5_242_880, 1)]
    );
    assert!(plan_chunks(0, 0).is_empty());
    assert_eq!(plan_chunks(u64::MAX, u64::MAX), vec![(0, u64::MAX)]);
    assert_eq!(plan_chunks(u64::MAX - 1, u64::MAX), vec![(0, u64::MAX - 1)]);
}

#[test]
fn missing_chunk_calculation_ignores_duplicates_order_and_out_of_range_entries() {
    assert_eq!(missing_chunks(5, &[]), vec![0, 1, 2, 3, 4]);
    assert_eq!(missing_chunks(5, &[4, 0, 0, 2, 2]), vec![1, 3]);
    assert_eq!(missing_chunks(3, &[0, 1, 2, 9, 10]), Vec::<u64>::new());
    assert_eq!(missing_chunks(0, &[0]), Vec::<u64>::new());
    assert_eq!(missing_chunks(3, &[7]), vec![0, 1, 2]);
}

#[test]
fn file_hashes_are_correct_across_the_read_buffer_boundaries() {
    let dir = tempfile::tempdir().unwrap();
    let mib = 1usize << 20;
    for len in [0usize, 1, 63, 64, 65, mib - 1, mib, mib + 1, 3 * mib + 5] {
        let path = write_file(dir.path(), &format!("f{len}.bin"), len, len as u64 + 1);
        let bytes = std::fs::read(&path).unwrap();
        let (hash, size) = file_sha256(&path).unwrap();
        assert_eq!(size, len as u64);
        assert_eq!(hash, sha256_hex(&bytes), "{len} bytes");
        assert_eq!(hash.len(), 64);
        assert!(
            hash.chars().all(|c| matches!(c, '0'..='9' | 'a'..='f')),
            "lower-case hex"
        );
    }
    assert_eq!(
        file_sha256(&dir.path().join("f0.bin")).unwrap().0,
        "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
    );
    assert!(file_sha256(&dir.path().join("absent.bin")).is_err());
}

// ---- Happy path ------------------------------------------------------------------------------------------------

#[tokio::test]
async fn a_recording_is_uploaded_in_order_in_correct_chunks_with_correct_checksums() {
    let dir = tempfile::tempdir().unwrap();
    let primary = write_file(dir.path(), "recording.flac", 2_500, 1);
    let bytes = std::fs::read(&primary).unwrap();
    let (server, stored) = Flow::new(1_000, vec![Slot::fresh("primary", 2_500)]).start();

    let session_id = upload(&api(&server), &pending(dir.path(), primary))
        .await
        .unwrap();
    assert_eq!(session_id, SESSION_ID);

    let chunks = chunk_requests(&server, "primary");
    assert_eq!(chunks.iter().map(index_of).collect::<Vec<_>>(), [0, 1, 2]);
    assert_eq!(
        chunks.iter().map(|c| c.body.len()).collect::<Vec<_>>(),
        [1_000, 1_000, 500]
    );
    for (i, chunk) in chunks.iter().enumerate() {
        assert_eq!(
            chunk.header("upload-chunk-sha256"),
            Some(sha256_hex(&bytes[i * 1_000..(i * 1_000 + chunk.body.len())]).as_str())
        );
        assert_eq!(
            chunk.header("content-type"),
            Some("application/octet-stream")
        );
        assert_eq!(chunk.header("authorization"), Some("Bearer tok"));
    }
    let rebuilt: Vec<u8> = stored.lock().unwrap().values().flatten().copied().collect();
    assert_eq!(
        rebuilt, bytes,
        "the server reassembles exactly the file on disk"
    );

    // Order of calls: config, session, chunks, complete.
    let all = server.requests();
    let order: Vec<String> = all
        .iter()
        .map(|r| format!("{} {}", r.method, r.path()))
        .collect();
    assert_eq!(order.first().unwrap(), "GET /api/config");
    assert_eq!(order[1], "POST /api/sessions");
    assert_eq!(
        order.last().unwrap(),
        "POST /api/recordings/REC-primary/complete"
    );
    assert_eq!(
        server
            .requests_to("POST", "/api/recordings/REC-primary/complete")
            .len(),
        1
    );
}

#[tokio::test]
async fn the_session_request_declares_the_file_it_is_about_to_send() {
    let dir = tempfile::tempdir().unwrap();
    let primary = write_file(dir.path(), "recording.flac", 4_321, 2);
    let (hash, size) = file_sha256(&primary).unwrap();
    let (server, _) = Flow::new(5_000, vec![Slot::fresh("primary", 4_321)]).start();
    upload(&api(&server), &pending(dir.path(), primary))
        .await
        .unwrap();

    let request = server.requests_to("POST", "/api/sessions")[0].json();
    assert_acceptable_to_the_server(&request, &ACCEPTED);
    assert_eq!(
        request["client_ref"],
        "3f2b6c1e-8d4a-4d3e-9a51-2c7f0e9b1a22"
    );
    assert_eq!(request["source_app"], "whatsapp");
    assert_eq!(request["started_at"], "2026-10-24T09:00:00+00:00");
    assert_eq!(request["ended_at"], "2026-10-24T09:42:10+00:00");
    assert_eq!(request["duration_ms"], 2_530_000);
    let a = &request["artifacts"][0];
    assert_eq!(
        (a["role"].as_str(), a["mime"].as_str(), a["codec"].as_str()),
        (Some("primary"), Some("audio/flac"), Some("flac"))
    );
    assert_eq!(
        (
            a["channels"].as_u64(),
            a["sample_rate"].as_u64(),
            a["size_bytes"].as_u64(),
            a["duration_ms"].as_u64()
        ),
        (Some(2), Some(16_000), Some(size), Some(2_530_000))
    );
    assert_eq!(a["sha256"], hash.as_str());
}

#[tokio::test]
async fn a_file_that_is_an_exact_multiple_of_the_chunk_size_has_no_empty_trailing_chunk() {
    let dir = tempfile::tempdir().unwrap();
    let primary = write_file(dir.path(), "recording.flac", 3_000, 3);
    let (server, _) = Flow::new(1_000, vec![Slot::fresh("primary", 3_000)]).start();
    upload(&api(&server), &pending(dir.path(), primary))
        .await
        .unwrap();
    let sizes: Vec<usize> = chunk_requests(&server, "primary")
        .iter()
        .map(|c| c.body.len())
        .collect();
    assert_eq!(sizes, [1_000, 1_000, 1_000]);
}

#[tokio::test]
async fn files_smaller_than_one_chunk_are_sent_whole_in_a_single_request() {
    for len in [1usize, 17, 999] {
        let dir = tempfile::tempdir().unwrap();
        let primary = write_file(dir.path(), "recording.flac", len, 4);
        let bytes = std::fs::read(&primary).unwrap();
        let (server, _) = Flow::new(5_242_880, vec![Slot::fresh("primary", len as u64)]).start();
        upload(&api(&server), &pending(dir.path(), primary))
            .await
            .unwrap();
        let chunks = chunk_requests(&server, "primary");
        assert_eq!(chunks.len(), 1, "{len} bytes");
        assert_eq!(chunks[0].body, bytes);
    }
}

// ---- Resume ----------------------------------------------------------------------------------------------------

#[tokio::test]
async fn a_partially_uploaded_recording_resumes_with_only_the_missing_chunks() {
    let dir = tempfile::tempdir().unwrap();
    let primary = write_file(dir.path(), "recording.flac", 4_200, 5);
    let bytes = std::fs::read(&primary).unwrap();
    let mut slot = Slot::fresh("primary", 4_200);
    slot.already_received = vec![0, 2, 4];
    slot.status = "uploading";
    let (server, stored) = Flow::new(1_000, vec![slot]).start();

    upload(&api(&server), &pending(dir.path(), primary))
        .await
        .unwrap();

    let chunks = chunk_requests(&server, "primary");
    assert_eq!(
        chunks.iter().map(index_of).collect::<Vec<_>>(),
        [1, 3],
        "chunks 0, 2 and 4 are already on the server"
    );
    assert_eq!(chunks[0].body, bytes[1_000..2_000]);
    assert_eq!(chunks[1].body, bytes[3_000..4_000]);
    assert_eq!(stored.lock().unwrap().len(), 2);
    assert_eq!(
        server
            .requests_to("POST", "/api/recordings/REC-primary/complete")
            .len(),
        1
    );
}

#[tokio::test]
async fn when_every_chunk_is_already_there_only_the_completion_call_is_made() {
    let dir = tempfile::tempdir().unwrap();
    let primary = write_file(dir.path(), "recording.flac", 2_000, 6);
    let mut slot = Slot::fresh("primary", 2_000);
    slot.already_received = vec![0, 1];
    slot.status = "uploading";
    let (server, _) = Flow::new(1_000, vec![slot]).start();
    upload(&api(&server), &pending(dir.path(), primary))
        .await
        .unwrap();
    assert!(chunk_requests(&server, "primary").is_empty());
    assert_eq!(
        server
            .requests_to("POST", "/api/recordings/REC-primary/complete")
            .len(),
        1
    );
}

#[tokio::test]
async fn recordings_the_server_already_assembled_are_left_alone() {
    for status in ["ready", "assembling"] {
        let dir = tempfile::tempdir().unwrap();
        let primary = write_file(dir.path(), "recording.flac", 2_000, 7);
        let mut slot = Slot::fresh("primary", 2_000);
        slot.already_received = vec![0, 1];
        slot.status = status;
        let (server, _) = Flow::new(1_000, vec![slot]).start();
        let id = upload(&api(&server), &pending(dir.path(), primary))
            .await
            .unwrap();
        assert_eq!(id, SESSION_ID);
        assert!(chunk_requests(&server, "primary").is_empty(), "{status}");
        assert!(
            server
                .requests_to("POST", "/api/recordings/REC-primary/complete")
                .is_empty(),
            "{status}"
        );
    }
}

// ---- Retry, back-off and permanent failures --------------------------------------------------------------------

#[tokio::test]
async fn a_transient_server_error_is_retried_and_the_upload_still_succeeds() {
    let dir = tempfile::tempdir().unwrap();
    let primary = write_file(dir.path(), "recording.flac", 2_000, 8);
    let flow = Flow::new(1_000, vec![Slot::fresh("primary", 2_000)]).inject(|index, attempt| {
        (index == 1 && attempt == 1)
            .then(|| Reply::json(503, json!({"message": "Service Unavailable"})))
    });
    let (server, stored) = flow.start();
    upload(&api(&server), &pending(dir.path(), primary))
        .await
        .unwrap();

    let chunks = chunk_requests(&server, "primary");
    let attempts_for_1: Vec<_> = chunks.iter().filter(|c| index_of(c) == 1).collect();
    assert_eq!(attempts_for_1.len(), 2);
    assert!(
        attempts_for_1[1].received_at - attempts_for_1[0].received_at
            >= Duration::from_millis(1_800),
        "the retry must wait before resending"
    );
    assert_eq!(stored.lock().unwrap().len(), 2);
}

#[tokio::test]
async fn retries_back_off_exponentially_and_succeed_on_the_fourth_attempt() {
    let dir = tempfile::tempdir().unwrap();
    let primary = write_file(dir.path(), "recording.flac", 500, 9);
    let flow = Flow::new(1_000, vec![Slot::fresh("primary", 500)]).inject(|_, attempt| {
        (attempt <= 3).then(|| Reply::json(502, json!({"message": "Bad Gateway"})))
    });
    let (server, _) = flow.start();
    upload(&api(&server), &pending(dir.path(), primary))
        .await
        .unwrap();

    let chunks = chunk_requests(&server, "primary");
    assert_eq!(chunks.len(), 4);
    let gaps: Vec<f64> = chunks
        .windows(2)
        .map(|w| (w[1].received_at - w[0].received_at).as_secs_f64())
        .collect();
    assert!(
        gaps[0] >= 1.9 && gaps[0] < 4.0,
        "first back-off {:.2}s",
        gaps[0]
    );
    assert!(
        gaps[1] >= 3.9 && gaps[1] < 7.0,
        "second back-off {:.2}s",
        gaps[1]
    );
    assert!(
        gaps[2] >= 7.9 && gaps[2] < 13.0,
        "third back-off {:.2}s",
        gaps[2]
    );
}

#[tokio::test]
async fn a_chunk_that_keeps_failing_is_given_up_on_after_three_retries() {
    let dir = tempfile::tempdir().unwrap();
    let primary = write_file(dir.path(), "recording.flac", 500, 10);
    let flow = Flow::new(1_000, vec![Slot::fresh("primary", 500)])
        .inject(|_, _| Some(Reply::json(503, json!({"message": "Service Unavailable"}))));
    let (server, _) = flow.start();
    let result = upload(&api(&server), &pending(dir.path(), primary)).await;
    match result {
        Err(AppError::Server {
            status: 503,
            message,
        }) => assert_eq!(message, "Service Unavailable"),
        other => panic!("expected the 503 to surface, got {other:?}"),
    }
    assert_eq!(
        chunk_requests(&server, "primary").len(),
        4,
        "one attempt plus three retries"
    );
    assert!(
        server
            .requests_to("POST", "/api/recordings/REC-primary/complete")
            .is_empty(),
        "an incomplete upload must not be completed"
    );
}

async fn permanent_failure_is_sent_once(status: u16, message: &str) {
    let dir = tempfile::tempdir().unwrap();
    let primary = write_file(dir.path(), "recording.flac", 500, 11);
    let body = json!({"message": message});
    let flow = Flow::new(1_000, vec![Slot::fresh("primary", 500)])
        .inject(move |_, _| Some(Reply::json(status, body.clone())));
    let (server, _) = flow.start();
    let started = std::time::Instant::now();
    let result = upload(&api(&server), &pending(dir.path(), primary)).await;
    assert!(
        matches!(result, Err(AppError::Server { status: s, .. }) if s == status),
        "{result:?}"
    );
    let attempts = chunk_requests(&server, "primary").len();
    assert_eq!(
        attempts,
        1,
        "a {status} will never succeed on retry, yet the chunk was sent {attempts} times over {:.0}s",
        started.elapsed().as_secs_f64()
    );
}

#[tokio::test]
#[ignore = "A6.DR4: a 422 chunk rejection is permanent and must not be retried"]
async fn a_checksum_rejection_is_not_retried() {
    permanent_failure_is_sent_once(422, "The chunk checksum does not match.").await;
}

#[tokio::test]
#[ignore = "A6.DR4: a 413 chunk rejection is permanent and must not be retried"]
async fn a_too_large_rejection_is_not_retried() {
    permanent_failure_is_sent_once(413, "Payload Too Large").await;
}

#[tokio::test]
#[ignore = "A6.DR4: a 403 chunk rejection is permanent and must not be retried"]
async fn a_missing_ability_is_not_retried() {
    permanent_failure_is_sent_once(403, "Invalid ability provided.").await;
}

#[tokio::test]
async fn a_revoked_token_stops_the_upload_immediately_without_retrying() {
    let dir = tempfile::tempdir().unwrap();
    let primary = write_file(dir.path(), "recording.flac", 2_000, 12);
    let flow = Flow::new(1_000, vec![Slot::fresh("primary", 2_000)])
        .inject(|_, _| Some(Reply::json(401, json!({"message": "Unauthenticated."}))));
    let (server, _) = flow.start();
    let started = std::time::Instant::now();
    let result = upload(&api(&server), &pending(dir.path(), primary)).await;
    assert!(matches!(result, Err(AppError::NotPaired)), "{result:?}");
    assert_eq!(chunk_requests(&server, "primary").len(), 1);
    assert!(
        started.elapsed() < Duration::from_secs(3),
        "no back-off before reporting a revoked device"
    );
}

#[tokio::test]
#[ignore = "A6.DR4: a rate-limited chunk must wait for the Retry-After delay"]
async fn a_rate_limited_chunk_waits_for_the_retry_after_the_server_asked_for() {
    let dir = tempfile::tempdir().unwrap();
    let primary = write_file(dir.path(), "recording.flac", 500, 13);
    let flow = Flow::new(1_000, vec![Slot::fresh("primary", 500)]).inject(|_, attempt| {
        (attempt == 1).then(|| {
            Reply::json(429, json!({"message": "Too Many Attempts."})).header("Retry-After", "5")
        })
    });
    let (server, _) = flow.start();
    upload(&api(&server), &pending(dir.path(), primary))
        .await
        .unwrap();
    let chunks = chunk_requests(&server, "primary");
    assert_eq!(chunks.len(), 2);
    let waited = (chunks[1].received_at - chunks[0].received_at).as_secs_f64();
    assert!(
        waited >= 4.9,
        "retried after {waited:.1}s although the server said Retry-After: 5"
    );
}

// ---- Setup failures --------------------------------------------------------------------------------------------

#[tokio::test]
async fn a_rejected_session_stops_before_any_audio_is_sent() {
    let dir = tempfile::tempdir().unwrap();
    let primary = write_file(dir.path(), "recording.flac", 2_000, 14);
    let mut flow = Flow::new(1_000, vec![Slot::fresh("primary", 2_000)]);
    flow.session_reply = Some(Reply::json(
        422,
        json!({"message": "The artifacts.0.mime field must be one of the accepted types."}),
    ));
    let (server, _) = flow.start();
    let result = upload(&api(&server), &pending(dir.path(), primary)).await;
    assert!(
        matches!(result, Err(AppError::Server { status: 422, .. })),
        "{result:?}"
    );
    assert!(chunk_requests(&server, "primary").is_empty());
}

#[tokio::test]
async fn an_unauthorised_config_call_stops_everything() {
    let dir = tempfile::tempdir().unwrap();
    let primary = write_file(dir.path(), "recording.flac", 2_000, 15);
    let mut flow = Flow::new(1_000, vec![Slot::fresh("primary", 2_000)]);
    flow.config_reply = Some(Reply::json(401, json!({"message": "Unauthenticated."})));
    let (server, _) = flow.start();
    assert!(matches!(
        upload(&api(&server), &pending(dir.path(), primary)).await,
        Err(AppError::NotPaired)
    ));
    assert!(server.requests_to("POST", "/api/sessions").is_empty());
}

#[tokio::test]
async fn a_file_that_vanished_from_disk_is_an_io_error_before_any_request_for_it() {
    let dir = tempfile::tempdir().unwrap();
    let (server, _) = Flow::new(1_000, vec![Slot::fresh("primary", 2_000)]).start();
    let result = upload(
        &api(&server),
        &pending(dir.path(), dir.path().join("gone.flac")),
    )
    .await;
    assert!(matches!(result, Err(AppError::Io(_))), "{result:?}");
    assert!(
        server.requests_to("POST", "/api/sessions").is_empty(),
        "the file is hashed before the session is created"
    );
}

// ---- Artifacts -------------------------------------------------------------------------------------------------

#[tokio::test]
async fn per_channel_wavs_are_uploaded_when_the_server_asks_for_them() {
    let dir = tempfile::tempdir().unwrap();
    let primary = write_file(dir.path(), "recording.flac", 1_500, 16);
    let mic = write_file(dir.path(), "mic.wav", 2_500, 17);
    let system = write_file(dir.path(), "system.wav", 2_500, 18);
    let mut flow = Flow::new(
        1_000,
        vec![
            Slot::fresh("primary", 1_500),
            Slot::fresh("channel_1", 2_500),
            Slot::fresh("channel_2", 2_500),
        ],
    );
    flow.config = fixture("config_channel_artifacts.json");
    let (server, stored) = flow.start();
    let mut item = pending(dir.path(), primary);
    item.mic_wav = Some(mic.clone());
    item.system_wav = Some(system.clone());
    upload(&api(&server), &item).await.unwrap();

    let request = server.requests_to("POST", "/api/sessions")[0].json();
    assert_acceptable_to_the_server(&request, &["audio/flac", "audio/wav"]);
    let artifacts = request["artifacts"].as_array().unwrap();
    assert_eq!(
        artifacts
            .iter()
            .map(|a| a["role"].as_str().unwrap())
            .collect::<Vec<_>>(),
        ["primary", "channel_1", "channel_2"]
    );
    for (a, file) in artifacts[1..].iter().zip([&mic, &system]) {
        assert_eq!(
            (
                a["mime"].as_str(),
                a["codec"].as_str(),
                a["channels"].as_u64()
            ),
            (Some("audio/wav"), Some("pcm_s16le"), Some(1))
        );
        assert_eq!(a["sha256"], file_sha256(file).unwrap().0.as_str());
    }
    let store = stored.lock().unwrap();
    let rebuilt = |role: &str| -> Vec<u8> {
        store
            .iter()
            .filter(|((rec, _), _)| rec == &format!("REC-{role}"))
            .flat_map(|(_, v)| v.clone())
            .collect()
    };
    assert_eq!(rebuilt("channel_1"), std::fs::read(&mic).unwrap());
    assert_eq!(rebuilt("channel_2"), std::fs::read(&system).unwrap());
    assert_eq!(
        rebuilt("primary"),
        std::fs::read(dir.path().join("recording.flac")).unwrap()
    );
    for role in ["primary", "channel_1", "channel_2"] {
        assert_eq!(
            server
                .requests_to("POST", &format!("/api/recordings/REC-{role}/complete"))
                .len(),
            1,
            "{role}"
        );
    }
}

#[tokio::test]
async fn channel_files_are_not_declared_when_only_the_primary_is_required() {
    let dir = tempfile::tempdir().unwrap();
    let primary = write_file(dir.path(), "recording.flac", 1_500, 19);
    let (server, _) = Flow::new(1_000, vec![Slot::fresh("primary", 1_500)]).start();
    let mut item = pending(dir.path(), primary);
    item.mic_wav = Some(write_file(dir.path(), "mic.wav", 100, 1));
    item.system_wav = Some(write_file(dir.path(), "system.wav", 100, 2));
    upload(&api(&server), &item).await.unwrap();
    assert_eq!(
        server.requests_to("POST", "/api/sessions")[0].json()["artifacts"]
            .as_array()
            .unwrap()
            .len(),
        1
    );
}

#[tokio::test]
async fn voice_notes_use_the_voice_note_artifact_policy() {
    let dir = tempfile::tempdir().unwrap();
    let primary = write_file(dir.path(), "note.m4a", 800, 20);
    let mut flow = Flow::new(1_000, vec![Slot::fresh("primary", 800)]);
    flow.config = fixture("config_channel_artifacts.json"); // final_transcript wants channel files, voice_note only the primary
    let (server, _) = flow.start();
    let mut item = pending(dir.path(), primary);
    item.source_app = "voice_note".into();
    item.primary_channels = 1;
    item.mic_wav = Some(write_file(dir.path(), "mic.wav", 100, 1));
    item.system_wav = Some(write_file(dir.path(), "system.wav", 100, 2));
    upload(&api(&server), &item).await.unwrap();
    let request = server.requests_to("POST", "/api/sessions")[0].json();
    let artifacts = request["artifacts"].as_array().unwrap();
    assert_eq!(artifacts.len(), 1);
    assert_eq!(
        (
            artifacts[0]["mime"].as_str(),
            artifacts[0]["channels"].as_u64()
        ),
        (Some("audio/mp4"), Some(1))
    );
    assert_eq!(request["source_app"], "voice_note");
}

#[tokio::test]
async fn container_types_map_to_the_mime_types_the_server_accepts() {
    let cases = [
        ("a.flac", "audio/flac"),
        ("a.FLAC", "audio/flac"),
        ("a.wav", "audio/wav"),
        ("a.ogg", "audio/ogg"),
        ("a.opus", "audio/ogg"),
        ("a.mp3", "audio/mpeg"),
        ("a.m4a", "audio/mp4"),
        ("a.mp4", "audio/mp4"),
        ("a.webm", "audio/webm"),
    ];
    for (name, mime) in cases {
        let dir = tempfile::tempdir().unwrap();
        let primary = write_file(dir.path(), name, 100, 21);
        let (server, _) = Flow::new(1_000, vec![Slot::fresh("primary", 100)]).start();
        upload(&api(&server), &pending(dir.path(), primary))
            .await
            .unwrap();
        let request = server.requests_to("POST", "/api/sessions")[0].json();
        assert_eq!(request["artifacts"][0]["mime"], mime, "{name}");
        assert_acceptable_to_the_server(&request, &ACCEPTED);
    }
}

#[tokio::test]
#[ignore = "A6.DR5: imported notes must not declare a sample rate or duration that was never measured"]
async fn imported_voice_notes_do_not_claim_a_sample_rate_or_duration_they_were_never_measured_at() {
    // Dropping an m4a/mp3/ogg file into the window queues it with no decoded metadata (duration 0). The server treats a
    // missing sample rate as "unknown"; declaring 16 kHz and 0 ms would store wrong facts about the file.
    for extension in ["mp3", "m4a", "ogg", "webm"] {
        let dir = tempfile::tempdir().unwrap();
        let primary = write_file(dir.path(), &format!("note.{extension}"), 500, 31);
        let (server, _) = Flow::new(1_000, vec![Slot::fresh("primary", 500)]).start();
        let mut item = pending(dir.path(), primary);
        item.source_app = "voice_note".into();
        item.primary_channels = 1;
        item.duration_ms = 0;
        upload(&api(&server), &item).await.unwrap();
        let request = server.requests_to("POST", "/api/sessions")[0].json();
        let artifact = &request["artifacts"][0];
        assert!(
            artifact["sample_rate"].is_null(),
            ".{extension}: declared sample_rate {}",
            artifact["sample_rate"]
        );
        assert!(
            artifact["duration_ms"].is_null(),
            ".{extension}: declared duration_ms {}",
            artifact["duration_ms"]
        );
    }
}

#[tokio::test]
#[ignore = "A6.DR5: .oga, .aac and .amr imports must be declared with a MIME type the server accepts"]
async fn every_audio_extension_the_app_imports_is_declared_with_an_accepted_mime() {
    // The drag-and-drop filter (`commands::is_audio`) lets these extensions in; the server allow-list must then
    // accept whatever type the uploader declares for them.
    let importable = [
        "ogg", "opus", "oga", "mp3", "m4a", "mp4", "aac", "wav", "flac", "webm", "amr",
    ];
    let mut rejected = Vec::new();
    for extension in importable {
        let dir = tempfile::tempdir().unwrap();
        let primary = write_file(dir.path(), &format!("note.{extension}"), 100, 22);
        let (server, _) = Flow::new(1_000, vec![Slot::fresh("primary", 100)]).start();
        upload(&api(&server), &pending(dir.path(), primary))
            .await
            .unwrap();
        let mime = server.requests_to("POST", "/api/sessions")[0].json()["artifacts"][0]["mime"]
            .as_str()
            .unwrap()
            .to_string();
        if !ACCEPTED.contains(&mime.as_str()) {
            rejected.push(format!(".{extension} -> {mime}"));
        }
    }
    assert!(
        rejected.is_empty(),
        "declared types the server would answer with 422: {rejected:?}"
    );
}

// ---- Live transcript -------------------------------------------------------------------------------------------

fn segments() -> Vec<SegmentDto> {
    vec![
        SegmentDto {
            channel: 1,
            speaker: "Me".into(),
            start_ms: 250,
            end_ms: 2_600,
            text: "Hello.".into(),
            words: vec![],
        },
        SegmentDto {
            channel: 2,
            speaker: "Client".into(),
            start_ms: 2_700,
            end_ms: 4_100,
            text: "Hi there.".into(),
            words: vec![],
        },
    ]
}

#[tokio::test]
async fn caption_turns_are_posted_as_the_live_transcript_after_the_audio() {
    let dir = tempfile::tempdir().unwrap();
    let primary = write_file(dir.path(), "recording.flac", 500, 23);
    let (server, _) = Flow::new(1_000, vec![Slot::fresh("primary", 500)]).start();
    let mut item = pending(dir.path(), primary);
    item.live_segments = segments();
    item.live_provider = Some("elevenlabs".into());
    upload(&api(&server), &item).await.unwrap();

    let posts = server.requests_to("POST", &format!("/api/sessions/{SESSION_ID}/transcripts"));
    assert_eq!(posts.len(), 1);
    let body = posts[0].json();
    assert_eq!(
        (body["kind"].as_str(), body["provider"].as_str()),
        (Some("live"), Some("elevenlabs"))
    );
    assert_eq!(body["segments"].as_array().unwrap().len(), 2);
    assert_eq!(body["segments"][1]["speaker"], "Client");
    let complete =
        server.requests_to("POST", "/api/recordings/REC-primary/complete")[0].received_at;
    assert!(
        posts[0].received_at >= complete,
        "the transcript follows the completion call"
    );
}

#[tokio::test]
async fn the_live_provider_defaults_to_assemblyai() {
    let dir = tempfile::tempdir().unwrap();
    let primary = write_file(dir.path(), "recording.flac", 500, 24);
    let (server, _) = Flow::new(1_000, vec![Slot::fresh("primary", 500)]).start();
    let mut item = pending(dir.path(), primary);
    item.live_segments = segments();
    upload(&api(&server), &item).await.unwrap();
    let body =
        server.requests_to("POST", &format!("/api/sessions/{SESSION_ID}/transcripts"))[0].json();
    assert_eq!(body["provider"], "assemblyai");
}

#[tokio::test]
async fn no_live_transcript_is_posted_when_nothing_was_captioned() {
    let dir = tempfile::tempdir().unwrap();
    let primary = write_file(dir.path(), "recording.flac", 500, 25);
    let (server, _) = Flow::new(1_000, vec![Slot::fresh("primary", 500)]).start();
    upload(&api(&server), &pending(dir.path(), primary))
        .await
        .unwrap();
    assert!(
        server
            .requests_to("POST", &format!("/api/sessions/{SESSION_ID}/transcripts"))
            .is_empty()
    );
}

#[tokio::test]
async fn a_rejected_live_transcript_does_not_fail_the_upload() {
    let dir = tempfile::tempdir().unwrap();
    let primary = write_file(dir.path(), "recording.flac", 500, 26);
    let mut flow = Flow::new(1_000, vec![Slot::fresh("primary", 500)]);
    flow.transcript_reply = Reply::json(
        422,
        json!({"message": "Segment timing exceeds the session duration."}),
    );
    let (server, _) = flow.start();
    let mut item = pending(dir.path(), primary);
    item.live_segments = segments();
    assert_eq!(upload(&api(&server), &item).await.unwrap(), SESSION_ID);
}

// ---- Upload queue on disk --------------------------------------------------------------------------------------

#[test]
fn the_queue_survives_a_restart_with_every_field() {
    let dir = tempfile::tempdir().unwrap();
    let path = dir.path().join("uploads.json");
    let mut item = pending(dir.path(), dir.path().join("recording.flac"));
    item.mic_wav = Some(dir.path().join("mic.wav"));
    item.live_segments = segments();
    item.live_provider = Some("assemblyai".into());
    item.attempts = 3;
    item.last_error = Some("Server returned 503: Service Unavailable".into());
    let queue = UploadQueue {
        items: vec![
            item.clone(),
            pending(dir.path(), dir.path().join("two.flac")),
        ],
    };
    queue.save(&path).unwrap();

    let loaded = UploadQueue::load(&path);
    assert_eq!(loaded.items.len(), 2);
    let back = &loaded.items[0];
    assert_eq!(
        (
            back.client_ref.as_str(),
            back.attempts,
            back.primary_channels
        ),
        (item.client_ref.as_str(), 3, 2)
    );
    assert_eq!(back.last_error, item.last_error);
    assert_eq!(back.mic_wav, item.mic_wav);
    assert_eq!(back.live_segments.len(), 2);
    assert_eq!(back.live_segments[1].text, "Hi there.");
    assert!(
        !path.with_extension("json.tmp").exists(),
        "the temporary file is renamed away"
    );
}

#[test]
fn a_missing_empty_or_corrupt_queue_file_loads_as_empty() {
    let dir = tempfile::tempdir().unwrap();
    let path = dir.path().join("uploads.json");
    assert!(UploadQueue::load(&path).items.is_empty());
    for content in ["", "not json", "{", "[]", "{\"items\": 5}"] {
        std::fs::write(&path, content).unwrap();
        assert!(UploadQueue::load(&path).items.is_empty(), "{content:?}");
    }
}

#[test]
fn queue_entries_written_by_an_older_version_still_load() {
    // Older builds had no attempts / last_error / live_* fields.
    let dir = tempfile::tempdir().unwrap();
    let path = dir.path().join("uploads.json");
    let legacy = json!({"items": [{
        "client_ref": "abc", "source_app": "zoom", "client_id": null,
        "started_at": "2026-10-24T09:00:00+00:00", "ended_at": "2026-10-24T09:10:00+00:00", "duration_ms": 600000,
        "dir": "recordings/abc", "primary": "recordings/abc/recording.flac", "primary_channels": 2,
        "mic_wav": null, "system_wav": null
    }]});
    std::fs::write(&path, legacy.to_string()).unwrap();
    let queue = UploadQueue::load(&path);
    assert_eq!(queue.items.len(), 1);
    assert_eq!(
        (
            queue.items[0].attempts,
            queue.items[0].live_segments.len(),
            queue.items[0].last_error.clone()
        ),
        (0, 0, None)
    );
}

#[test]
fn queue_entries_with_fields_from_a_newer_version_still_load() {
    let dir = tempfile::tempdir().unwrap();
    let path = dir.path().join("uploads.json");
    let newer = json!({"items": [{
        "client_ref": "abc", "source_app": "zoom", "client_id": null,
        "started_at": "x", "ended_at": "y", "duration_ms": 1,
        "dir": "d", "primary": "p", "primary_channels": 1, "mic_wav": null, "system_wav": null,
        "priority": "high", "tags": ["a", "b"]
    }], "format": 2});
    std::fs::write(&path, newer.to_string()).unwrap();
    assert_eq!(UploadQueue::load(&path).items.len(), 1);
}

#[test]
fn saving_into_a_missing_directory_is_an_error() {
    let dir = tempfile::tempdir().unwrap();
    assert!(
        UploadQueue::default()
            .save(&dir.path().join("nope").join("uploads.json"))
            .is_err()
    );
}
