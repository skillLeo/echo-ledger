//! End-to-end behaviour of the resumable chunk uploader against a scripted server: chunk boundaries, checksums,
//! resume after a partial upload, retry and back-off, permanent versus transient failures, and the artifacts it
//! declares.

mod common;

use std::collections::BTreeMap;
use std::path::{Path, PathBuf};
use std::sync::{Arc, Mutex};
use std::time::Duration;

use serde_json::{Value, json};

use common::{Action, MockServer, Reply, Router, fixture, pseudo_random_bytes, sha256_hex};
use desktop_lib::error::AppError;
use desktop_lib::net::api::ApiClient;
use desktop_lib::net::models::{SegmentDto, ServerConfig, UsageReport};
use desktop_lib::net::uploader::{
    AfterAttempt, DiscardQueue, PendingUpload, UNVERIFIABLE, UploadItem, UploadOutcome, UploadQueue,
    UploadTiming, UsageQueue, after_attempt, delete_local_audio, drain_discards, drain_usage,
    effective_ended_at, file_sha256, flac_stream_info, is_final_failure, missing_chunks, mime_for,
    plan_chunks, states, upload, upload_with,
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
/// `(request, nth call)` -> reply, for the session routes.
type Handler = Arc<dyn Fn(&common::Recorded, usize) -> Reply + Send + Sync>;
/// `(role, nth call)` -> the upload status `GET /api/recordings/{id}` reports.
type StatusFn = Arc<dyn Fn(&str, usize) -> &'static str + Send + Sync>;
/// `(role, nth call)` -> the answer to `complete`.
type CompleteFn = Arc<dyn Fn(&str, usize) -> Reply + Send + Sync>;

/// The open session the finish tests attach their artifacts to.
const OPEN_ID: &str = "01JB8QA0M1X2C3V4B5N6M7K8J9";

struct Flow {
    config: Value,
    chunk_size: u64,
    slots: Vec<Slot>,
    inject: Inject,
    session_reply: Option<Reply>,
    session_route: Option<Handler>,
    finish_route: Option<Handler>,
    config_reply: Option<Reply>,
    transcript_reply: Reply,
    status: StatusFn,
    complete: CompleteFn,
}

impl Flow {
    fn new(chunk_size: u64, slots: Vec<Slot>) -> Self {
        Self {
            config: fixture("config.json"),
            chunk_size,
            slots,
            inject: Arc::new(|_, _| None),
            session_reply: None,
            session_route: None,
            finish_route: None,
            config_reply: None,
            transcript_reply: Reply::json(201, fixture("transcript.json")),
            status: Arc::new(|_, _| "ready"),
            complete: Arc::new(|_, _| Reply::json(202, json!({"status": "assembling"}))),
        }
    }

    fn inject(mut self, f: impl Fn(u64, usize) -> Option<Reply> + Send + Sync + 'static) -> Self {
        self.inject = Arc::new(f);
        self
    }

    fn status(mut self, f: impl Fn(&str, usize) -> &'static str + Send + Sync + 'static) -> Self {
        self.status = Arc::new(f);
        self
    }

    fn complete(mut self, f: impl Fn(&str, usize) -> Reply + Send + Sync + 'static) -> Self {
        self.complete = Arc::new(f);
        self
    }

    fn sessions(mut self, f: impl Fn(&common::Recorded, usize) -> Reply + Send + Sync + 'static) -> Self {
        self.session_route = Some(Arc::new(f));
        self
    }

    fn finish(mut self, f: impl Fn(&common::Recorded, usize) -> Reply + Send + Sync + 'static) -> Self {
        self.finish_route = Some(Arc::new(f));
        self
    }

    fn v1_1(mut self) -> Self {
        self.config["api_version"] = json!("1.1");
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
            .unwrap_or_else(|| Reply::json(201, session.clone()));
        let sessions: Handler = self
            .session_route
            .clone()
            .unwrap_or_else(|| Arc::new(move |_, _| session_reply.clone()));
        let finished = Reply::json(200, session);
        let finish: Handler = self
            .finish_route
            .clone()
            .unwrap_or_else(|| Arc::new(move |_, _| finished.clone()));
        let mut router = Router::new()
            .fixed("GET", "/api/config", config_reply)
            .route("POST", "/api/sessions", move |r, n| sessions(r, n).into())
            .route(
                "POST",
                &format!("/api/sessions/{OPEN_ID}/finish"),
                move |r, n| finish(r, n).into(),
            )
            .fixed(
                "POST",
                &format!("/api/sessions/{SESSION_ID}/transcripts"),
                self.transcript_reply.clone(),
            );

        for slot in &self.slots {
            let id = format!("REC-{}", slot.role);
            let (baseline, total_chunks, status) =
                (slot.already_received.clone(), total(slot.size), "uploading");
            let show_store = stored.clone();
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
            let (complete, role) = (self.complete.clone(), slot.role);
            router = router.route(
                "POST",
                &format!("/api/recordings/{id}/complete"),
                move |_, n| complete(role, n).into(),
            );
            let (status, chunk_size, total_chunks) = (self.status.clone(), self.chunk_size, total(slot.size));
            let (show_id, show_baseline) = (id.clone(), slot.already_received.clone());
            router = router.route("GET", &format!("/api/recordings/{id}"), move |_, n| {
                let state = status(role, n);
                let received: Vec<u64> = match state {
                    "failed" => vec![],
                    "ready" | "assembling" => (0..total_chunks).collect(),
                    _ => {
                        let store = show_store.lock().unwrap();
                        let mut got = show_baseline.clone();
                        got.extend(store.keys().filter(|(rec, _)| *rec == show_id).map(|(_, i)| *i));
                        got.sort_unstable();
                        got.dedup();
                        got
                    }
                };
                let error = (state == "failed").then_some("The uploaded file did not match its checksum. Send it again.");
                Reply::json(200, json!({"id": show_id, "role": role, "upload": {"chunk_size_bytes": chunk_size, "total_chunks": total_chunks, "received_chunks": received, "status": state, "error": error}})).into()
            });
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
        ..PendingUpload::default()
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

    // Order of calls: config, session, chunks, complete, then the status check that confirms the server
    // assembled the file (complete answered `assembling`; the audio is kept until the recording is `ready`).
    let all = server.requests();
    let order: Vec<String> = all
        .iter()
        .map(|r| format!("{} {}", r.method, r.path()))
        .collect();
    assert_eq!(order.first().unwrap(), "GET /api/config");
    assert_eq!(order[1], "POST /api/sessions");
    assert_eq!(
        order[order.len() - 2..],
        [
            "POST /api/recordings/REC-primary/complete",
            "GET /api/recordings/REC-primary"
        ]
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
            ..
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
async fn a_checksum_rejection_is_not_retried() {
    permanent_failure_is_sent_once(422, "The chunk checksum does not match.").await;
}

#[tokio::test]
async fn a_too_large_rejection_is_not_retried() {
    permanent_failure_is_sent_once(413, "Payload Too Large").await;
}

#[tokio::test]
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
            uncertain: false,
            check: None,
        },
        SegmentDto {
            channel: 2,
            speaker: "Client".into(),
            start_ms: 2_700,
            end_ms: 4_100,
            text: "Hi there.".into(),
            words: vec![],
            uncertain: false,
            check: None,
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

// ---- v1.1 session lifecycle, verification and queues (A5.1, A6.DR4 DR5 DR7 DR8 DR15) --------------------------

/// Short polling so the verification cases run in well under a second.
const FAST: UploadTiming = UploadTiming {
    poll_every: Duration::from_millis(40),
    poll_for: Duration::from_millis(300),
};

fn config_of(flow_config: &Value) -> ServerConfig {
    serde_json::from_value(flow_config.clone()).unwrap()
}

/// Every state the item was persisted in, in order.
type Persisted = Arc<Mutex<Vec<PendingUpload>>>;

async fn attempt(
    server: &MockServer,
    config: &ServerConfig,
    item: &mut PendingUpload,
) -> (Result<UploadOutcome, AppError>, Vec<PendingUpload>) {
    let persisted: Persisted = Arc::new(Mutex::new(Vec::new()));
    let log = persisted.clone();
    let persist = move |p: &PendingUpload| log.lock().unwrap().push(p.clone());
    let result = upload_with(&api(server), config, item, FAST, &persist).await;
    let states = persisted.lock().unwrap().clone();
    (result, states)
}

fn recorded_call(dir: &Path, len: usize) -> PendingUpload {
    let primary = write_file(dir, "recording.flac", len, 41);
    pending(dir, primary)
}

#[tokio::test]
async fn an_open_session_is_finished_instead_of_creating_a_new_one() {
    let dir = tempfile::tempdir().unwrap();
    let flow = Flow::new(1_000, vec![Slot::fresh("primary", 1_500)]).v1_1();
    let config = config_of(&flow.config);
    let (server, stored) = flow.start();
    let mut item = recorded_call(dir.path(), 1_500);
    item.server_session_id = Some(OPEN_ID.into());
    item.title = Some("Budget review".into());
    item.client_id = Some("01JB8Q4C1D2E3F4G5H6J7K8M9N".into());

    let (result, _) = attempt(&server, &config, &mut item).await;
    assert_eq!(result.unwrap(), UploadOutcome::Ready { session_id: SESSION_ID.into() });
    assert!(server.requests_to("POST", "/api/sessions").is_empty(), "no second session");
    let finish = server.requests_to("POST", &format!("/api/sessions/{OPEN_ID}/finish"));
    assert_eq!(finish.len(), 1);
    let body = finish[0].json();
    assert_eq!(body["title"], "Budget review");
    assert_eq!(body["client_id"], "01JB8Q4C1D2E3F4G5H6J7K8M9N");
    assert_eq!(body["duration_ms"], 2_530_000);
    assert_eq!(body["ended_at"], "2026-10-24T09:42:10+00:00");
    assert_eq!(body["artifacts"][0]["role"], "primary");
    assert!(body.get("client_ref").is_none() && body.get("started_at").is_none());
    assert_eq!(stored.lock().unwrap().len(), 2, "both chunks went to the finished session's slot");
    assert_eq!(item.created_session_id.as_deref(), Some(SESSION_ID));
}

#[tokio::test]
async fn a_lost_open_session_falls_back_to_a_new_one_shot_session() {
    let dir = tempfile::tempdir().unwrap();
    let flow = Flow::new(1_000, vec![Slot::fresh("primary", 800)])
        .v1_1()
        .finish(|_, _| Reply::json(404, fixture("server/sessions_finish_404.json")));
    let config = config_of(&flow.config);
    let (server, _) = flow.start();
    let mut item = recorded_call(dir.path(), 800);
    let original_ref = item.client_ref.clone();
    item.server_session_id = Some(OPEN_ID.into());

    let (result, persisted) = attempt(&server, &config, &mut item).await;
    assert!(result.is_ok(), "{result:?}");
    let created = server.requests_to("POST", "/api/sessions");
    assert_eq!(created.len(), 1);
    let sent_ref = created[0].json()["client_ref"].as_str().unwrap().to_string();
    assert_ne!(sent_ref, original_ref, "a swept session's client_ref must not be reused");
    let parsed = uuid::Uuid::parse_str(&sent_ref).expect("a UUID");
    assert_eq!(parsed.get_version_num(), 4);
    assert_eq!(item.client_ref, sent_ref);
    assert_eq!(item.server_session_id, None);
    assert_eq!(
        persisted.first().map(|p| p.client_ref.clone()),
        Some(sent_ref),
        "the new client_ref is stored before the create, so a retry reuses it"
    );
    assert!(created[0].json()["artifacts"].is_array());
}

#[tokio::test]
async fn a_session_this_item_already_finished_and_that_was_deleted_is_not_recreated() {
    let dir = tempfile::tempdir().unwrap();
    let flow = Flow::new(1_000, vec![Slot::fresh("primary", 800)])
        .v1_1()
        .finish(|_, _| Reply::json(404, fixture("server/sessions_finish_404.json")));
    let config = config_of(&flow.config);
    let (server, _) = flow.start();
    let mut item = recorded_call(dir.path(), 800);
    item.server_session_id = Some(OPEN_ID.into());
    item.created_session_id = Some(OPEN_ID.into());
    let (result, _) = attempt(&server, &config, &mut item).await;
    assert_eq!(result.unwrap(), UploadOutcome::Deleted { session_id: OPEN_ID.into() });
    assert!(server.requests_to("POST", "/api/sessions").is_empty());
    assert!(chunk_requests(&server, "primary").is_empty());
}

#[tokio::test]
async fn a_v1_server_gets_the_one_shot_create_even_with_an_open_session_id() {
    let dir = tempfile::tempdir().unwrap();
    let flow = Flow::new(1_000, vec![Slot::fresh("primary", 800)]);
    let config = config_of(&flow.config);
    assert!(!config.is_v1_1(), "config.json has no api_version");
    let (server, _) = flow.start();
    let mut item = recorded_call(dir.path(), 800);
    item.server_session_id = Some(OPEN_ID.into());
    item.title = Some("Standup".into());
    let (result, _) = attempt(&server, &config, &mut item).await;
    assert!(result.is_ok(), "{result:?}");
    assert!(server.requests_to("POST", &format!("/api/sessions/{OPEN_ID}/finish")).is_empty());
    let created = server.requests_to("POST", "/api/sessions")[0].json();
    assert_eq!(created["client_ref"], "3f2b6c1e-8d4a-4d3e-9a51-2c7f0e9b1a22");
    assert_eq!(created["title"], "Standup");
}

#[tokio::test]
async fn an_assembling_file_is_polled_until_ready_and_only_then_is_the_audio_deleted() {
    let dir = tempfile::tempdir().unwrap();
    let recording = dir.path().join("rec-1");
    std::fs::create_dir_all(&recording).unwrap();
    let flow = Flow::new(1_000, vec![Slot::fresh("primary", 1_200)])
        .status(|_, nth| if nth < 2 { "assembling" } else { "ready" });
    let config = config_of(&flow.config);
    let (server, _) = flow.start();
    let mut item = recorded_call(&recording, 1_200);

    let (result, _) = attempt(&server, &config, &mut item).await;
    assert_eq!(server.requests_to("GET", "/api/recordings/REC-primary").len(), 3);
    assert_eq!(after_attempt(&mut item, false, &result), AfterAttempt::Remove);
    assert!(recording.exists(), "nothing is deleted before the queue applies the outcome");
    delete_local_audio(&item);
    assert!(!recording.exists());
}

#[tokio::test]
async fn files_are_kept_while_the_server_is_still_verifying_and_checked_again_next_cycle() {
    let dir = tempfile::tempdir().unwrap();
    let recording = dir.path().join("rec-2");
    std::fs::create_dir_all(&recording).unwrap();
    let ready = Arc::new(std::sync::atomic::AtomicBool::new(false));
    let switch = ready.clone();
    let flow = Flow::new(1_000, vec![Slot::fresh("primary", 2_500)]).status(move |_, _| {
        if switch.load(std::sync::atomic::Ordering::SeqCst) { "ready" } else { "assembling" }
    });
    let config = config_of(&flow.config);
    let (server, _) = flow.start();
    let mut item = recorded_call(&recording, 2_500);

    let (result, _) = attempt(&server, &config, &mut item).await;
    assert_eq!(result.as_ref().unwrap(), &UploadOutcome::Verifying { session_id: SESSION_ID.into() });
    assert_eq!(after_attempt(&mut item, false, &result), AfterAttempt::Keep);
    assert_eq!(item.state, states::VERIFYING);
    assert!(recording.join("recording.flac").exists(), "the audio stays until the server says ready");
    assert_eq!(item.slots.len(), 1);

    // Next cycle: one status check, no session request and no chunk is sent again.
    let before = server.requests().len();
    let (result, _) = attempt(&server, &config, &mut item).await;
    assert!(matches!(result, Ok(UploadOutcome::Verifying { .. })));
    let since: Vec<String> = server.requests()[before..].iter().map(|r| format!("{} {}", r.method, r.path())).collect();
    assert_eq!(since, ["GET /api/recordings/REC-primary"]);

    ready.store(true, std::sync::atomic::Ordering::SeqCst);
    let (result, _) = attempt(&server, &config, &mut item).await;
    assert_eq!(result.as_ref().unwrap(), &UploadOutcome::Ready { session_id: SESSION_ID.into() });
    assert_eq!(after_attempt(&mut item, true, &result), AfterAttempt::Remove);
    assert_eq!(chunk_requests(&server, "primary").len(), 3, "each chunk was sent once");
    assert_eq!(server.requests_to("POST", "/api/sessions").len(), 1);
}

#[tokio::test]
async fn a_failed_verification_is_uploaded_again_once_and_then_reported() {
    let dir = tempfile::tempdir().unwrap();
    let flow = Flow::new(1_000, vec![Slot::fresh("primary", 1_500)]).status(|_, _| "failed");
    let config = config_of(&flow.config);
    let (server, _) = flow.start();
    let mut item = recorded_call(dir.path(), 1_500);

    let (result, _) = attempt(&server, &config, &mut item).await;
    let error = result.as_ref().unwrap_err();
    assert_eq!(error.to_string(), UNVERIFIABLE);
    assert!(is_final_failure(error));
    assert_eq!(item.full_restarts, 1);
    let indexes: Vec<u64> = chunk_requests(&server, "primary").iter().map(index_of).collect();
    assert_eq!(indexes, [0, 1, 0, 1], "the whole file went twice, from chunk 0");
    assert_eq!(server.requests_to("POST", "/api/recordings/REC-primary/complete").len(), 2);
    assert_eq!(after_attempt(&mut item, false, &result), AfterAttempt::Keep);
    assert_eq!(item.state, states::FAILED);
    assert_eq!(item.last_error.as_deref(), Some(UNVERIFIABLE));
}

#[tokio::test]
async fn a_verification_failure_at_completion_restarts_from_chunk_zero() {
    let dir = tempfile::tempdir().unwrap();
    let flow = Flow::new(1_000, vec![Slot::fresh("primary", 2_100)]).complete(|_, nth| {
        if nth == 0 {
            Reply::json(422, fixture("server/recordings_complete_422_verification_failed.json"))
        } else {
            Reply::json(202, json!({"status": "ready"}))
        }
    });
    let config = config_of(&flow.config);
    let (server, _) = flow.start();
    let mut item = recorded_call(dir.path(), 2_100);
    let (result, _) = attempt(&server, &config, &mut item).await;
    assert!(matches!(result, Ok(UploadOutcome::Ready { .. })), "{result:?}");
    let indexes: Vec<u64> = chunk_requests(&server, "primary").iter().map(index_of).collect();
    assert_eq!(indexes, [0, 1, 2, 0, 1, 2]);
    assert_eq!(item.full_restarts, 1);
    assert!(
        server.requests_to("GET", "/api/recordings/REC-primary").is_empty(),
        "complete said ready: nothing to poll"
    );
}

#[tokio::test]
async fn missing_chunks_reported_at_completion_are_sent_and_completion_repeated() {
    let dir = tempfile::tempdir().unwrap();
    // The first upload of chunk 1 is acknowledged but lost on the server.
    let lose = Arc::new(std::sync::atomic::AtomicBool::new(true));
    let flag = lose.clone();
    let flow = Flow::new(1_000, vec![Slot::fresh("primary", 2_500)])
        .inject(move |index, attempt| {
            (index == 1 && attempt == 1 && flag.load(std::sync::atomic::Ordering::SeqCst))
                .then(|| Reply::json(200, json!({"received_chunks": [0, 1], "total_chunks": 3, "status": "uploading"})))
        })
        .status(|_, _| "uploading")
        .complete(|_, nth| {
            if nth == 0 {
                Reply::json(422, json!({"message": "Upload is incomplete.", "code": "upload_incomplete", "missing_chunks": [1]}))
            } else {
                Reply::json(202, json!({"status": "ready"}))
            }
        });
    let config = config_of(&flow.config);
    let (server, stored) = flow.start();
    let mut item = recorded_call(dir.path(), 2_500);
    let (result, _) = attempt(&server, &config, &mut item).await;
    assert!(matches!(result, Ok(UploadOutcome::Ready { .. })), "{result:?}");
    assert_eq!(server.requests_to("POST", "/api/recordings/REC-primary/complete").len(), 2);
    assert!(stored.lock().unwrap().contains_key(&("REC-primary".to_string(), 1)));
    let _ = lose;
}

#[tokio::test]
async fn a_chunk_refused_because_the_upload_is_already_complete_stops_the_sending() {
    let dir = tempfile::tempdir().unwrap();
    let flow = Flow::new(1_000, vec![Slot::fresh("primary", 3_000)]).inject(|index, _| {
        (index == 1).then(|| Reply::json(409, json!({"message": "Recording upload is already complete.", "code": "upload_complete"})))
    });
    let config = config_of(&flow.config);
    let (server, _) = flow.start();
    let mut item = recorded_call(dir.path(), 3_000);
    let (result, _) = attempt(&server, &config, &mut item).await;
    assert!(matches!(result, Ok(UploadOutcome::Ready { .. })), "{result:?}");
    let indexes: Vec<u64> = chunk_requests(&server, "primary").iter().map(index_of).collect();
    assert_eq!(indexes, [0, 1], "no chunk after the 409, and no retry of it");
    assert!(server.requests_to("POST", "/api/recordings/REC-primary/complete").is_empty());
    assert_eq!(server.requests_to("GET", "/api/recordings/REC-primary").len(), 1);
}

#[tokio::test]
async fn a_tombstone_answer_means_the_session_was_deleted_and_nothing_is_sent() {
    let dir = tempfile::tempdir().unwrap();
    let mut flow = Flow::new(1_000, vec![Slot::fresh("primary", 2_000)]);
    flow.session_reply = Some(Reply::json(200, fixture("server/sessions_store_200_after_delete.json")));
    let config = config_of(&flow.config);
    let (server, _) = flow.start();
    let mut item = recorded_call(dir.path(), 2_000);
    let (result, _) = attempt(&server, &config, &mut item).await;
    assert!(matches!(result, Ok(UploadOutcome::Deleted { .. })), "{result:?}");
    assert!(chunk_requests(&server, "primary").is_empty());
    assert_eq!(after_attempt(&mut item, false, &result), AfterAttempt::Remove);
}

#[tokio::test]
async fn a_recording_deleted_while_it_is_verified_ends_the_upload() {
    let dir = tempfile::tempdir().unwrap();
    let flow = Flow::new(1_000, vec![Slot::fresh("primary", 500)]);
    let config = config_of(&flow.config);
    let (server, _) = flow.start();
    let mut item = recorded_call(dir.path(), 500);
    item.state = states::VERIFYING.into();
    item.created_session_id = Some(SESSION_ID.into());
    item.slots = vec![desktop_lib::net::uploader::UploadSlot {
        id: "GONE".into(),
        role: "primary".into(),
        status: "assembling".into(),
        chunk_size_bytes: 1_000,
    }];
    let (result, _) = attempt(&server, &config, &mut item).await;
    assert_eq!(result.unwrap(), UploadOutcome::Deleted { session_id: SESSION_ID.into() });
}

#[tokio::test]
async fn a_deleted_client_is_dropped_and_the_session_created_without_it() {
    let dir = tempfile::tempdir().unwrap();
    let session = {
        let mut s = fixture("session_created.json");
        s["recordings"] = json!([{"id": "REC-primary", "role": "primary", "upload": {"chunk_size_bytes": 1000, "total_chunks": 1, "received_chunks": [], "status": "pending"}}]);
        s
    };
    let flow = Flow::new(1_000, vec![Slot::fresh("primary", 600)]).sessions(move |r, _| {
        if r.json()["client_id"].is_null() {
            Reply::json(201, session.clone())
        } else {
            Reply::json(404, json!({"message": "Client not found.", "code": "client_not_found"}))
        }
    });
    let config = config_of(&flow.config);
    let (server, _) = flow.start();
    let mut item = recorded_call(dir.path(), 600);
    item.client_id = Some("01DELETEDCLIENT0000000000".into());
    let (result, persisted) = attempt(&server, &config, &mut item).await;
    assert!(matches!(result, Ok(UploadOutcome::Ready { .. })), "{result:?}");
    assert_eq!(server.requests_to("POST", "/api/sessions").len(), 2, "one retry without the client");
    assert_eq!(item.client_id, None);
    assert_eq!(persisted.first().map(|p| p.client_id.clone()), Some(None), "the cleared client is stored first");
}

#[tokio::test]
async fn a_deleted_client_is_also_dropped_when_finishing_an_open_session() {
    let dir = tempfile::tempdir().unwrap();
    let flow = Flow::new(1_000, vec![Slot::fresh("primary", 600)]).v1_1().finish(|r, nth| {
        if r.json()["client_id"].is_null() {
            let mut s = fixture("session_created.json");
            s["recordings"] = json!([{"id": "REC-primary", "role": "primary", "upload": {"chunk_size_bytes": 1000, "total_chunks": 1, "received_chunks": [], "status": "pending"}}]);
            Reply::json(200, s)
        } else {
            assert_eq!(nth, 0);
            Reply::json(404, json!({"message": "Client not found.", "code": "client_not_found"}))
        }
    });
    let config = config_of(&flow.config);
    let (server, _) = flow.start();
    let mut item = recorded_call(dir.path(), 600);
    item.server_session_id = Some(OPEN_ID.into());
    item.client_id = Some("01DELETEDCLIENT0000000000".into());
    let (result, _) = attempt(&server, &config, &mut item).await;
    assert!(result.is_ok(), "{result:?}");
    let finishes = server.requests_to("POST", &format!("/api/sessions/{OPEN_ID}/finish"));
    assert_eq!(finishes.len(), 2);
    assert!(
        finishes[1].json().get("client_id").is_some_and(Value::is_null),
        "the retry clears the stored client explicitly"
    );
}

#[tokio::test]
async fn a_clock_set_back_during_the_call_still_gives_an_end_after_the_start() {
    let dir = tempfile::tempdir().unwrap();
    let (server, _) = Flow::new(1_000, vec![Slot::fresh("primary", 500)]).start();
    let mut item = recorded_call(dir.path(), 500);
    item.started_at = "2026-10-24T09:00:00+00:00".into();
    item.ended_at = "2026-10-24T08:58:30+00:00".into();
    item.duration_ms = 8_000;
    upload(&api(&server), &item).await.unwrap();
    let request = server.requests_to("POST", "/api/sessions")[0].json();
    assert_eq!(request["ended_at"], "2026-10-24T09:00:08+00:00");
    assert_acceptable_to_the_server(&request, &ACCEPTED);
}

#[test]
fn the_declared_end_only_changes_when_it_lies_before_the_start() {
    assert_eq!(
        effective_ended_at("2026-10-24T09:00:00+00:00", "2026-10-24T09:10:00+00:00", 1),
        "2026-10-24T09:10:00+00:00"
    );
    assert_eq!(
        effective_ended_at("2026-10-24T09:00:00Z", "2026-10-24T08:00:00Z", 90_000),
        "2026-10-24T09:01:30+00:00"
    );
    assert_eq!(effective_ended_at("garbage", "also garbage", 5), "also garbage");
}

#[test]
fn every_imported_extension_maps_to_an_accepted_type() {
    for (name, mime) in [
        ("a.oga", "audio/ogg"),
        ("a.ogg", "audio/ogg"),
        ("a.opus", "audio/ogg"),
        ("a.aac", "audio/aac"),
        ("a.AMR", "audio/amr"),
        ("a.m4a", "audio/mp4"),
        ("a.mp4", "audio/mp4"),
        ("a.webm", "audio/webm"),
        ("a.wav", "audio/wav"),
        ("a.flac", "audio/flac"),
        ("a.mp3", "audio/mpeg"),
    ] {
        assert_eq!(mime_for(Path::new(name)).0, mime, "{name}");
        assert!(ACCEPTED.contains(&mime));
    }
}

/// A FLAC file header: the marker plus a STREAMINFO block (RFC 9639 §8.2), followed by junk frames.
fn flac_header(sample_rate: u32, channels: u8, total_samples: u64) -> Vec<u8> {
    let mut b = b"fLaC".to_vec();
    b.extend_from_slice(&[0x80, 0, 0, 34]); // last block, type 0, length 34
    b.extend_from_slice(&[0x10, 0x00, 0x10, 0x00]); // min/max block size 4096
    b.extend_from_slice(&[0, 0, 0, 0, 0, 0]); // min/max frame size unknown
    let bits = 15u64; // 16 bits per sample
    let packed: u64 = (u64::from(sample_rate) << 44) | (u64::from(channels - 1) << 41) | (bits << 36) | total_samples;
    b.extend_from_slice(&packed.to_be_bytes());
    b.extend_from_slice(&[0u8; 16]); // MD5
    b.extend_from_slice(&pseudo_random_bytes(300, 3));
    b
}

#[test]
fn the_flac_stream_info_is_read_from_the_header() {
    assert_eq!(flac_stream_info(&flac_header(44_100, 2, 441_000)), Some((44_100, 2, 441_000)));
    assert_eq!(flac_stream_info(&flac_header(16_000, 1, 0)), Some((16_000, 1, 0)));
    assert_eq!(flac_stream_info(b"ID3\x04 not a flac stream at all, padding padding"), None);
    assert_eq!(flac_stream_info(b"fLaC"), None);
}

#[tokio::test]
async fn imported_wav_and_flac_files_declare_what_their_headers_say() {
    let dir = tempfile::tempdir().unwrap();
    let wav = dir.path().join("note.wav");
    {
        let spec = hound::WavSpec { channels: 1, sample_rate: 8_000, bits_per_sample: 16, sample_format: hound::SampleFormat::Int };
        let mut writer = hound::WavWriter::create(&wav, spec).unwrap();
        for i in 0..12_000 {
            writer.write_sample((i % 100) as i16).unwrap();
        }
        writer.finalize().unwrap();
    }
    let flac = dir.path().join("note.flac");
    std::fs::write(&flac, flac_header(44_100, 2, 441_000)).unwrap();
    for (path, expected) in [(wav, (8_000, 1, 1_500)), (flac, (44_100, 2, 10_000))] {
        let size = std::fs::metadata(&path).unwrap().len();
        let (server, _) = Flow::new(5_000, vec![Slot::fresh("primary", size)]).start();
        let mut item = pending(dir.path(), path.clone());
        item.source_app = "voice_note".into();
        item.primary_channels = 1;
        item.duration_ms = 0;
        upload(&api(&server), &item).await.unwrap();
        let artifact = server.requests_to("POST", "/api/sessions")[0].json()["artifacts"][0].clone();
        assert_eq!(
            (artifact["sample_rate"].as_u64(), artifact["channels"].as_u64(), artifact["duration_ms"].as_u64()),
            (Some(expected.0), Some(expected.1), Some(expected.2)),
            "{}",
            path.display()
        );
    }
}

#[test]
fn the_queue_outcome_of_each_attempt() {
    let mut item = PendingUpload::default();
    let ok = |o: UploadOutcome| -> Result<UploadOutcome, AppError> { Ok(o) };
    let sid = || SESSION_ID.to_string();
    assert_eq!(after_attempt(&mut item, false, &ok(UploadOutcome::Ready { session_id: sid() })), AfterAttempt::Remove);
    assert_eq!(after_attempt(&mut item, false, &ok(UploadOutcome::Deleted { session_id: sid() })), AfterAttempt::Remove);

    let transient: Result<UploadOutcome, AppError> = Err(AppError::Network("timed out".into()));
    assert_eq!(after_attempt(&mut item, false, &transient), AfterAttempt::Keep);
    assert_eq!((item.state.as_str(), item.attempts), (states::QUEUED, 1));
    assert_eq!(after_attempt(&mut item, true, &transient), AfterAttempt::Keep);
    assert_eq!(item.state, states::VERIFYING, "a verifying item keeps checking its slots");

    let permanent: Result<UploadOutcome, AppError> = Err(AppError::Server {
        status: 413,
        message: "Payload Too Large".into(),
        code: Some("too_large".into()),
        retry_after_secs: None,
    });
    after_attempt(&mut item, false, &permanent);
    assert_eq!(item.state, states::FAILED);
    assert_eq!(item.last_error.as_deref(), Some("Server returned 413: Payload Too Large"));

    let mut paired_out = PendingUpload::default();
    after_attempt(&mut paired_out, false, &Err(AppError::NotPaired));
    assert_eq!((paired_out.state.as_str(), paired_out.attempts), (states::QUEUED, 0), "unpairing is not the item's fault");

    let vanished: Result<UploadOutcome, AppError> = Err(AppError::Io(std::io::Error::from(std::io::ErrorKind::NotFound)));
    assert!(is_final_failure(vanished.as_ref().unwrap_err()));
}

#[test]
fn queue_entries_from_before_v1_1_load_as_queued() {
    let dir = tempfile::tempdir().unwrap();
    let path = dir.path().join("uploads.json");
    let legacy = json!({"items": [{
        "client_ref": "abc", "source_app": "zoom", "client_id": null,
        "started_at": "2026-10-24T09:00:00+00:00", "ended_at": "2026-10-24T09:10:00+00:00", "duration_ms": 600000,
        "dir": "recordings/abc", "primary": "recordings/abc/recording.flac", "primary_channels": 2,
        "mic_wav": null, "system_wav": null, "attempts": 2, "last_error": "Network error: x"
    }]});
    std::fs::write(&path, legacy.to_string()).unwrap();
    let item = &UploadQueue::load(&path).items[0];
    assert_eq!(item.state, states::QUEUED);
    assert_eq!((item.server_session_id.clone(), item.title.clone(), item.full_restarts), (None, None, 0));
    assert!(item.slots.is_empty());
    assert_eq!(item.id(), "abc");
}

#[test]
fn upload_items_for_the_ui_use_camel_case_and_a_stable_id() {
    let mut item = pending(Path::new("data/recordings/9a1d"), PathBuf::from("data/recordings/9a1d/recording.flac"));
    item.title = Some("Standup".into());
    item.state = states::VERIFYING.into();
    item.client_ref = "changed-after-a-lost-session".into();
    let ui = serde_json::to_value(UploadItem::from(&item)).unwrap();
    assert_eq!(
        ui,
        json!({"id": "9a1d", "sourceApp": "whatsapp", "title": "Standup", "startedAt": "2026-10-24T09:00:00+00:00",
               "durationMs": 2530000, "state": "verifying", "lastError": null, "attempts": 0})
    );
    let mut queue = UploadQueue { items: vec![item.clone(), item.clone()] };
    queue.items[1].state = states::FAILED.into();
    assert_eq!((queue.active(), queue.failed()), (1, 1));
}

fn report(id: &str) -> UsageReport {
    UsageReport {
        report_id: id.into(),
        use_case: "live_captions".into(),
        provider: "assemblyai".into(),
        session_id: Some(SESSION_ID.into()),
        streamed_ms: 61_000,
        channels: 2,
        ended_at: "2026-10-24T09:43:00Z".into(),
    }
}

#[tokio::test]
async fn the_usage_queue_drains_accepted_duplicate_and_refused_reports_and_keeps_the_rest() {
    let server = MockServer::start(Router::new().route("POST", "/api/usage", |r, _| {
        match r.json()["report_id"].as_str().unwrap() {
            "r1" => Reply::json(201, fixture("server/usage_201.json")).into(),
            "r2" => Reply::json(200, fixture("server/usage_200_duplicate.json")).into(),
            "r3" => Reply::json(422, json!({"message": "The streamed ms field must not be greater than 86400000.", "code": "validation_failed"})).into(),
            _ => Action::Drop,
        }
    }));
    let reports: Vec<UsageReport> = ["r1", "r2", "r3", "r4", "r5"].iter().map(|id| report(id)).collect();
    let drain = drain_usage(&api(&server), &reports).await;
    assert_eq!(drain.done, ["r1", "r2", "r3"]);
    assert_eq!((drain.sent, drain.dropped), (2, 1));
    assert!(matches!(drain.stopped, Some(AppError::Network(_))), "{:?}", drain.stopped);
    assert_eq!(drain.usage.map(|u| u.month), Some("2026-10".to_string()));
    assert_eq!(server.requests().len(), 4, "r5 waits for the next cycle after r4 failed");

    let dir = tempfile::tempdir().unwrap();
    let path = dir.path().join("usage.json");
    let mut queue = UsageQueue { items: reports };
    queue.items.retain(|r| !drain.done.contains(&r.report_id));
    queue.save(&path).unwrap();
    let ids: Vec<String> = UsageQueue::load(&path).items.into_iter().map(|r| r.report_id).collect();
    assert_eq!(ids, ["r4", "r5"]);
}

#[tokio::test]
async fn an_unpaired_device_keeps_its_usage_reports() {
    let server = MockServer::start(Router::new().fixed("POST", "/api/usage", Reply::json(401, json!({"message": "Unauthenticated."}))));
    let drain = drain_usage(&api(&server), &[report("r1")]).await;
    assert!(drain.done.is_empty());
    assert!(matches!(drain.stopped, Some(AppError::NotPaired)));
}

#[test]
fn the_usage_queue_is_capped_and_ignores_repeats() {
    let mut queue = UsageQueue::default();
    for n in 0..(UsageQueue::CAP + 20) {
        queue.push(report(&format!("r{n}")));
    }
    queue.push(report("r100"));
    assert_eq!(queue.items.len(), UsageQueue::CAP);
    assert_eq!(queue.items[0].report_id, "r20", "the oldest reports go first");
    assert_eq!(queue.items.last().unwrap().report_id, format!("r{}", UsageQueue::CAP + 19));
}

#[tokio::test]
async fn the_discard_queue_deletes_sessions_and_treats_404_as_done() {
    let server = MockServer::start(
        Router::new()
            .fixed("DELETE", "/api/sessions/S1", Reply::empty(204))
            .fixed("DELETE", "/api/sessions/S2", Reply::json(404, fixture("server/sessions_finish_404.json")))
            .fixed("DELETE", "/api/sessions/S3", Reply::json(503, json!({"message": "Service Unavailable"}))),
    );
    let ids: Vec<String> = ["S1", "S2", "S3", "S4"].iter().map(|s| s.to_string()).collect();
    let drain = drain_discards(&api(&server), &ids).await;
    assert_eq!(drain.done, ["S1", "S2"]);
    assert!(matches!(drain.stopped, Some(AppError::Server { status: 503, .. })));
    assert_eq!(server.requests().len(), 3);

    let mut queue = DiscardQueue::default();
    for id in ["S3", "S3", "", "S4"] {
        queue.push(id.to_string());
    }
    assert_eq!(queue.items, ["S3", "S4"]);
}
