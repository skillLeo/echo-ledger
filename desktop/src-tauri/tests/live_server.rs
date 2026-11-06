//! The desktop's real client against a real server, both ways: what it sends must pass the server's validation and what
//! comes back must read. Skipped unless a disposable server is named:
//!   ECHOLEDGER_TEST_SERVER=http://127.0.0.1:8791 ECHOLEDGER_TEST_EMAIL=… ECHOLEDGER_TEST_PASSWORD=… cargo test --test live_server
//! It changes data, so never point it at anything but a throwaway database. Answers that depend on the server's setup (no
//! AI key, no speech provider) are fine; a refused body (422), a missing route (404 or 405) or an unreadable answer is not.

use desktop_lib::error::{AppError, AppResult};
use desktop_lib::net::api::ApiClient;
use desktop_lib::net::uploader::sha256_hex;
use desktop_lib::net::models::{
    ArtifactDto, CreateSessionRequest, DeviceTranscript, FinishSessionRequest, OpenSessionRequest, PasswordAnswer, PasswordSignIn, SegmentDto,
    UsageReport,
};
use serde_json::{json, Value};

#[derive(Default)]
struct Log {
    ok: usize,
    soft: Vec<String>,
    bad: Vec<String>,
}

impl Log {
    fn note<T>(&mut self, name: &str, result: AppResult<T>) -> Option<T> {
        match result {
            Ok(value) => {
                self.ok += 1;
                Some(value)
            }
            Err(AppError::Server { status, message, code, .. }) if matches!(status, 401 | 403 | 409 | 410 | 429 | 503) || code.as_deref() == Some("notes_not_found") => {
                self.soft.push(format!("{status} {} {name}: {message}", code.unwrap_or_default()));
                None
            }
            Err(error) => {
                self.bad.push(format!("{name}: {error}"));
                None
            }
        }
    }
}

fn id_of(value: &Value) -> Option<String> {
    value.get("data").unwrap_or(value).get("id").and_then(Value::as_str).map(str::to_string)
}

#[tokio::test(flavor = "multi_thread")]
async fn every_call_the_desktop_makes_passes_the_server_and_reads_back() {
    let (Ok(base), Ok(email), Ok(password)) =
        (std::env::var("ECHOLEDGER_TEST_SERVER"), std::env::var("ECHOLEDGER_TEST_EMAIL"), std::env::var("ECHOLEDGER_TEST_PASSWORD"))
    else {
        return;
    };
    let _ = rustls::crypto::ring::default_provider().install_default();
    let signed_out = ApiClient::new(&base, &[], None).unwrap();
    let sign_in = PasswordSignIn { email: &email, password: &password, otp: None, name: "Live test computer" };
    let PasswordAnswer::SignedIn(signed_in) = signed_out.sign_in_password(&sign_in, "macos", "15.0").await.unwrap() else {
        panic!("two-factor sign-in is on for the test account");
    };
    let api = ApiClient::new(&base, &[], Some(signed_in.token.clone())).unwrap();
    let mut log = Log::default();

    log.note("config", api.config().await);
    log.note("account", api.account().await);
    log.note("clients", api.clients().await);
    log.note("storage", api.storage().await);
    log.note("security", api.security().await);
    log.note("activity", api.activity(20).await);
    log.note("activity read", api.mark_activity_read(None).await);
    log.note("bin", api.bin().await);
    let library = log.note("library", api.library(None, None).await);
    log.note("folder", api.library_folder("whatsapp", None).await);
    log.note("views", api.library_views(Some("UTC")).await);
    log.note("search", api.search(&[("q".to_string(), "retainer".to_string())]).await);

    // A recording from start to finish, as the recorder does it.
    let bytes = vec![7u8; 4096];
    let sha = sha256_hex(&bytes);
    let artifact = ArtifactDto {
        role: "primary".into(),
        mime: "audio/flac".into(),
        codec: Some("flac".into()),
        channels: 2,
        sample_rate: Some(16000),
        size_bytes: bytes.len() as u64,
        sha256: sha.clone(),
        duration_ms: Some(5000),
    };
    let open = log.note(
        "open session",
        api.open_session(&OpenSessionRequest {
            client_ref: uuid::Uuid::new_v4().to_string(),
            source_app: "zoom".into(),
            client_id: None,
            title: None,
            started_at: "2026-11-01T10:00:00Z".into(),
            language: None,
        })
        .await,
    );
    if let Some(open) = &open {
        log.note("stt token", api.stt_token("live_captions", &["me", "client"], None, Some(&open.session.id), None).await);
        log.note("translate token", api.live_translate_token("watch", "ur", &[]).await);
        log.note(
            "usage",
            api.report_usage(&UsageReport {
                report_id: uuid::Uuid::new_v4().to_string(),
                use_case: "live_captions".into(),
                provider: "assemblyai".into(),
                session_id: Some(open.session.id.clone()),
                streamed_ms: 60000,
                channels: 2,
                ended_at: "2026-11-01T10:01:00Z".into(),
            })
            .await,
        );
        let finish = FinishSessionRequest {
            ended_at: "2026-11-01T10:05:00Z".into(),
            duration_ms: 300000,
            title: Some("Live test".into()),
            client_id: None,
            language: None,
            artifacts: vec![artifact.clone()],
            markers: vec![],
            pauses: vec![],
            language_marks: vec![],
            caller: None,
        };
        if let Some(finished) = log.note("finish session", api.finish_session(&open.session.id, &finish).await)
            && let Some(recording) = finished.recordings.first()
        {
            log.note("recording status", api.recording_status(&recording.id).await);
            log.note("chunk", api.upload_chunk(&recording.id, 0, bytes.clone(), &sha).await);
            log.note("complete", api.complete(&recording.id).await);
        }
    }
    let create = CreateSessionRequest {
        client_ref: uuid::Uuid::new_v4().to_string(),
        source_app: "other".into(),
        client_id: None,
        title: Some("An import".into()),
        started_at: "2026-11-01T11:00:00Z".into(),
        ended_at: Some("2026-11-01T11:01:00Z".into()),
        duration_ms: Some(60000),
        language: None,
        artifacts: vec![artifact.clone()],
        markers: vec![],
        pauses: vec![],
        language_marks: vec![],
        caller: None,
    };
    if let Some(created) = log.note("create session", api.create_session(&create).await) {
        let transcript = DeviceTranscript {
            kind: "live".into(),
            provider: "assemblyai".into(),
            language: None,
            segments: vec![serde_json::from_value::<SegmentDto>(json!({"channel": 1, "speaker": "Me", "start_ms": 0, "end_ms": 900, "text": "Hello there."})).unwrap()],
        };
        log.note("post transcript", api.post_transcript(&created.session.id, &transcript).await);
        log.note("delete unfinished", api.delete_session(&created.session.id).await);
    }

    // A recording that has a transcript, through what its page does.
    let id = library
        .as_ref()
        .and_then(|l| l.data.iter().find(|i| i.rest.get("transcript").and_then(|t| t.get("final_transcript_id")).is_some_and(Value::is_string)))
        .map(|i| i.id.clone());
    let detail = match &id {
        Some(id) => log.note("detail for ids", api.session(id).await),
        None => None,
    };
    let transcript_id = detail.as_ref().and_then(|d| d.get("transcripts")).and_then(Value::as_array).and_then(|t| t.first()).and_then(id_of);
    if let Some(id) = &id {
        log.note("session", api.session(id).await);
        log.note("rename", api.update_session(id, &json!({"title": "Renamed"})).await);
        log.note("pin", api.update_session(id, &json!({"pinned": true})).await);
        log.note("speakers", api.rename_speakers(id, json!({"Me": "Ali"}).as_object().unwrap(), false).await);
        log.note("screens", api.session_screens(id).await);
        log.note("notes", api.session_notes(id).await);
        log.note("make notes", api.write_session_notes(id, Some("en")).await);
        log.note("old share", api.create_share(id, 86400, Some("secret-1234"), false).await);
        log.note("public link", api.create_link_share("sessions", id, &json!({"audience": "public", "allow_download": false})).await);
        log.note("password link", api.create_link_share("sessions", id, &json!({"audience": "password", "password": "secret-1234", "allow_download": true})).await);
        log.note("people link", api.create_link_share("sessions", id, &json!({"audience": "people", "people": ["someone@example.test"], "allow_download": false})).await);
        if let Some(list) = log.note("shares", api.list_shares(id).await)
            && let Some(first) = list.get("data").and_then(Value::as_array).and_then(|d| d.first()).and_then(id_of)
        {
            log.note("revoke share", api.revoke_share(&first).await);
        }
        log.note("shares filter", api.shares(Some(id), None).await);
        log.note("send", api.send_to("sessions", id, &json!({"to": ["teammate@example.test"], "note": "Please look."})).await);
        log.note("export sessions", api.export_sessions(std::slice::from_ref(id), "txt").await.map(|_| ()));
        log.note("bulk label", api.bulk_sessions(std::slice::from_ref(id), "label", None, Some("Bulk")).await);
        log.note("retranscribe", api.retranscribe(id, Some("ur")).await);
    }
    if let Some(t) = &transcript_id {
        log.note("transcript", api.transcript(t).await);
        log.note("revisions", api.transcript_revisions(t).await);
        log.note("edit line", api.edit_transcript(t, 0, "Edited line.").await);
        log.note("replace", api.replace_in_transcript(t, "Edited", "Changed", false, true).await);
        log.note("restore text", api.restore_transcript(t).await);
        log.note("views", api.transcript_views(t).await);
        log.note("make views", api.make_transcript_views(t).await);
        log.note("export", api.export_transcript(t, "txt", true, true, None).await.map(|_| ()));
        log.note("suggestion", api.decide_suggestion(t, 0, true).await.or_else(|e| match e {
            AppError::Server { status: 404 | 422, .. } => Ok(Value::Null), // none to decide in this data
            other => Err(other),
        }));
    }

    // A project.
    if let Some(project) = log.note("create project", api.create_client(&json!({"name": "Live project"})).await) {
        let p = id_of(&project).unwrap();
        log.note("subfolder", api.create_client(&json!({"name": "Sub", "parent_id": p})).await);
        log.note("rename project", api.update_client(&p, &json!({"name": "Live project renamed"})).await);
        log.note("project workspace", api.client_workspace(&p).await);
        log.note("brief", api.save_client_brief(&p, &json!({"goal": "A goal", "scope": ["Login"], "people": ["Omar"]})).await);
        log.note("refresh brief", api.refresh_client_brief(&p).await);
        if let Some(memory) = log.note("memory", api.add_client_memory(&p, "decision", "They chose the second plan.", Some("2026-11-01")).await) {
            let item = id_of(&memory).unwrap();
            log.note("memory edit", api.update_client_memory(&p, &item, &json!({"text": "Changed.", "pinned": true})).await);
            log.note("memory delete", api.delete_client_memory(&p, &item).await);
        }
        if let Some(file) = log.note("file", api.upload_client_file(&p, "notes.txt", "text/plain", b"Some notes.".to_vec()).await) {
            log.note("delete file", api.delete_client_file(&p, &id_of(&file).unwrap()).await);
        }
        log.note("instructions", api.add_client_instructions(&p, Some("Rules"), "Keep answers short.").await);
        log.note("ask", api.ask_client(&p, "What did they choose?", "en").await);
        log.note("bundle", api.client_bundle(&p, Some(10)).await);
        if let Some(rule) = log.note("rule", api.add_client_rule(&p, "contact", "+923001234567").await) {
            log.note("rules", api.client_rules(&p).await);
            log.note("delete rule", api.delete_client_rule(&p, &rule.get("data").and_then(|d| d.get("id")).map(Value::to_string).unwrap_or_default()).await);
        }
        log.note("keyterm", api.add_keyterm("EchoLedger", Some(&p)).await);
        log.note("replacement", api.add_replacement("echo ledger", "EchoLedger", Some(&p)).await);
        log.note("project link", api.create_link_share("clients", &p, &json!({"audience": "public", "allow_download": true})).await);
        log.note("project shares", api.shares(None, Some(&p)).await);
        log.note("send project", api.send_to("clients", &p, &json!({"to": ["teammate@example.test"]})).await);
        if let Some(id) = &id {
            log.note("bulk move", api.bulk_sessions(std::slice::from_ref(id), "move", Some(&p), None).await);
        }
        if let Some(request) = log.note("request link", api.create_request_link(&json!({"title": "Send me the file", "client_id": p})).await) {
            log.note("request links", api.request_links().await);
            log.note("revoke request link", api.revoke_request_link(&id_of(&request).unwrap()).await);
        }
        log.note("delete project", api.delete_client(&p).await);
    }
    log.note("shared with me", api.shared_with_me().await);

    // Housekeeping last.
    if let Some(id) = &id {
        log.note("delete", api.delete_session(id).await);
        log.note("restore", api.restore_session(id).await);
        log.note("delete again", api.delete_session(id).await);
        log.note("erase", api.erase_from_bin(id).await);
    }
    if let Some(export) = log.note("start export", api.start_export().await) {
        log.note("export status", api.export_status(&export.id).await);
    }

    eprintln!("ok {}\nsoft:\n{}\nbad:\n{}", log.ok, log.soft.join("\n"), log.bad.join("\n"));
    assert!(log.bad.is_empty(), "{}", log.bad.join("\n"));
}
