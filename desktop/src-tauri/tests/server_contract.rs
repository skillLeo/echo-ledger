//! Contract between the Laravel server and the desktop client: responses captured from a running server (device
//! tokens, signatures and streaming tokens replaced by placeholders) must deserialise into the wire types in
//! `net::models`, and the typed `ApiClient` must turn them into the values the UI reads.
//!
//! Fixtures live in `tests/fixtures/server`; `index.json` records the request and HTTP status of each one.

mod common;

use std::collections::BTreeSet;

use serde::de::DeserializeOwned;
use serde_json::Value;

use common::{MockServer, Reply, Router};
use desktop_lib::error::AppError;
use desktop_lib::net::api::ApiClient;
use desktop_lib::net::models::*;
use desktop_lib::net::sync::merge;
use desktop_lib::net::uploader::{missing_chunks, plan_chunks};
use desktop_lib::stt::stream::Protocol;

const TOKEN: &str = "1|eld_TOKEN_PLACEHOLDER";

// ---- Helpers ---------------------------------------------------------------------------------------------------

fn raw(name: &str) -> String {
    common::fixture_text(&format!("server/{name}.json"))
}

fn fx(name: &str) -> Value {
    common::fixture(&format!("server/{name}.json"))
}

fn parse<T: DeserializeOwned>(name: &str) -> T {
    serde_json::from_str(&raw(name)).unwrap_or_else(|e| panic!("{name} does not deserialise: {e}"))
}

fn is_id(value: &str) -> bool {
    value.len() == 26
        && value
            .bytes()
            .all(|b| b.is_ascii_digit() || b.is_ascii_lowercase())
}

fn is_rfc3339(value: &str) -> bool {
    chrono::DateTime::parse_from_rfc3339(value).is_ok()
}

fn str_of<'a>(value: &'a Value, key: &str) -> &'a str {
    value[key]
        .as_str()
        .unwrap_or_else(|| panic!("{key} is not a string in {value}"))
}

fn field<'a>(map: &'a serde_json::Map<String, Value>, key: &str) -> &'a str {
    map.get(key)
        .and_then(Value::as_str)
        .unwrap_or_else(|| panic!("{key} is not a string"))
}

fn index() -> serde_json::Map<String, Value> {
    fx("index")
        .as_object()
        .cloned()
        .expect("index.json is an object")
}

fn fixture_names() -> Vec<String> {
    let dir = common::fixture_path("server");
    let mut names: Vec<String> = std::fs::read_dir(&dir)
        .unwrap_or_else(|e| panic!("{}: {e}", dir.display()))
        .filter_map(|entry| entry.ok())
        .filter_map(|entry| entry.file_name().into_string().ok())
        .filter_map(|file| file.strip_suffix(".json").map(str::to_string))
        .filter(|name| name != "index")
        .collect();
    names.sort();
    names
}

fn client(server: &MockServer) -> ApiClient {
    ApiClient::new(&server.base_url(), &[], Some(TOKEN.to_string())).expect("loopback client")
}

fn anonymous(server: &MockServer) -> ApiClient {
    ApiClient::new(&server.base_url(), &[], None).expect("loopback client")
}

fn server_error<T: std::fmt::Debug>(result: Result<T, AppError>) -> (u16, String) {
    match result {
        Err(AppError::Server {
            status, message, ..
        }) => (status, message),
        other => panic!("expected a server error, got {other:?}"),
    }
}

// ---- The fixture set itself ------------------------------------------------------------------------------------

#[test]
fn every_fixture_is_indexed_and_is_valid_json() {
    let index = index();
    let names = fixture_names();
    assert!(names.len() >= 80, "only {} fixtures found", names.len());
    for name in &names {
        assert!(
            index.contains_key(name),
            "{name} is missing from index.json"
        );
        let text = raw(name);
        serde_json::from_str::<Value>(&text).unwrap_or_else(|e| panic!("{name}: {e}"));
    }
    for name in index.keys() {
        assert!(
            names.contains(name),
            "index.json lists {name} but the file is missing"
        );
    }
}

#[test]
fn fixtures_hold_placeholders_instead_of_secrets() {
    for name in fixture_names() {
        let text = raw(&name);
        for (marker, allowed) in [
            ("eld_", "TOKEN_PLACEHOLDER"),
            ("signature=", "SIGNATURE_PLACEHOLDER"),
            ("token=", "STREAMING_TOKEN_PLACEHOLDER"),
        ] {
            for (at, _) in text.match_indices(marker) {
                assert!(
                    text[at + marker.len()..].starts_with(allowed),
                    "{name}: {marker} is not followed by {allowed}"
                );
            }
        }
        for forbidden in [
            "127.0.0.1",
            "xampp",
            "\"trace\"",
            "\"exception\"",
            "Authorization",
        ] {
            assert!(!text.contains(forbidden), "{name} contains {forbidden}");
        }
    }
}

#[test]
fn error_fixtures_use_the_documented_error_shape() {
    let index = index();
    let mut seen = BTreeSet::new();
    for (name, entry) in &index {
        let status = entry["status"].as_u64().unwrap();
        if status < 400 {
            continue;
        }
        seen.insert(status);
        let body = fx(name);
        assert!(
            body["message"].as_str().is_some_and(|m| !m.is_empty()),
            "{name} ({status}) has no message"
        );
        if status == 422 && !body["errors"].is_null() {
            for (field, messages) in body["errors"].as_object().expect("errors is an object") {
                let list = messages
                    .as_array()
                    .unwrap_or_else(|| panic!("{name}: errors.{field} is not a list"));
                assert!(
                    list.iter().all(|m| m.is_string()),
                    "{name}: errors.{field} holds non-strings"
                );
            }
        }
    }
    for status in [401, 403, 404, 409, 413, 422, 429] {
        assert!(seen.contains(&status), "no fixture for HTTP {status}");
    }
}

#[test]
fn rate_limit_responses_record_a_retry_after() {
    let index = index();
    for name in ["errors_429_pairing", "errors_429_stt_token"] {
        let wait = index[name]["retry_after"]
            .as_u64()
            .unwrap_or_else(|| panic!("{name}: no Retry-After"));
        assert!((1..=60).contains(&wait), "{name}: Retry-After {wait}");
    }
}

// ---- Pairing and configuration ---------------------------------------------------------------------------------

#[test]
fn register_response_for_a_windows_device() {
    let r: RegisterResponse = parse("register_201_windows");
    assert!(r.token.starts_with("1|eld_"), "{}", r.token);
    assert!(is_rfc3339(
        r.token_expires_at.as_deref().expect("token_expires_at")
    ));
    assert!(is_id(&r.device.id));
    assert_eq!(r.device.name, "Work laptop");
    assert_eq!(
        (r.device.platform.as_str(), r.device.source_device.as_str()),
        ("windows", "desktop")
    );
    assert_eq!(
        r.config.device.id, r.device.id,
        "the embedded config describes the same device"
    );
}

#[test]
fn register_response_for_an_android_device_is_a_phone() {
    let r: RegisterResponse = parse("register_201_android");
    assert_eq!(
        (r.device.platform.as_str(), r.device.source_device.as_str()),
        ("android", "phone")
    );
    assert_eq!(r.config.device.source_device, "phone");
}

#[test]
fn config_carries_every_section_the_client_reads() {
    let config: ServerConfig = parse("config_200");
    assert!(is_id(&config.device.id));
    assert!(is_rfc3339(&config.server_time));
    assert_eq!(config.retention_days, 30);
    assert_eq!(config.upload.chunk_size_bytes, 5_242_880);
    assert_eq!(config.upload.max_recording_bytes, 2_147_483_648);
    for mime in ["audio/flac", "audio/wav", "audio/ogg", "audio/opus"] {
        assert!(
            config.upload.accepted_mimes.iter().any(|m| m == mime),
            "{mime} not accepted"
        );
    }
    assert_eq!(config.captions.channels, "both");
    assert!(
        config.pins.is_empty(),
        "no SPKI pins are configured on the server these fixtures came from"
    );

    let use_cases: BTreeSet<&str> = config.providers.keys().map(String::as_str).collect();
    assert_eq!(
        use_cases,
        BTreeSet::from([
            "dictation",
            "final_transcript",
            "live_captions",
            "voice_note"
        ])
    );
    for (use_case, provider) in &config.providers {
        assert!(
            !provider.provider.is_empty() && !provider.mode.is_empty(),
            "{use_case}"
        );
    }
    assert_eq!(config.providers["live_captions"].mode, "cloud");
    assert!(
        config.providers["live_captions"]
            .required_artifacts
            .is_none()
    );
    let fin = &config.providers["final_transcript"];
    assert_eq!(fin.mode, "async_webhook");
    assert_eq!(fin.preferred_primary.as_deref(), Some("ogg_opus"));
    assert_eq!(config.required_artifacts("final_transcript"), ["primary"]);
    assert_eq!(config.required_artifacts("voice_note"), ["primary"]);
}

#[test]
fn the_config_inside_register_matches_get_config() {
    let registered: RegisterResponse = parse("register_201_windows");
    let fetched: ServerConfig = parse("config_200");
    let mut a = serde_json::to_value(&registered.config).unwrap();
    let mut b = serde_json::to_value(&fetched).unwrap();
    a["server_time"] = Value::Null;
    b["server_time"] = Value::Null;
    assert_eq!(a, b);
    assert_eq!(
        parse::<ServerConfig>("config_200_android")
            .device
            .source_device,
        "phone"
    );
}

#[test]
fn clients_list_has_what_the_settings_screen_shows() {
    let body = fx("clients_200");
    let rows = body["data"].as_array().expect("data");
    assert_eq!(rows.len(), 2);
    for row in rows {
        assert!(is_id(str_of(row, "id")));
        assert!(!str_of(row, "name").is_empty());
        assert!(
            row["keyterm_count"].is_u64(),
            "keyterm_count must be a number: {row}"
        );
        assert!(is_rfc3339(str_of(row, "updated_at")));
    }
    assert_eq!(rows[0]["name"], "Acme Holdings");
    assert_eq!(rows[0]["keyterm_count"], 3);
    assert!(rows[1]["color"].is_null(), "a client may have no colour");
}

// ---- Live captions and dictation -------------------------------------------------------------------------------

#[test]
fn live_caption_grants_are_streamable_and_carry_the_keyterms() {
    let token: SttTokenResponse = parse("stt_token_200_live_captions");
    assert_eq!(
        (token.provider.as_deref(), token.mode.as_str()),
        (Some("assemblyai"), "cloud")
    );
    assert!(is_rfc3339(token.expires_at.as_deref().expect("expires_at")));
    assert_eq!(
        token.keyterms,
        ["Acme Holdings", "Farah Khan", "Q3 forecast"]
    );
    let channels: Vec<&str> = token.grants.iter().map(|g| g.channel.as_str()).collect();
    assert_eq!(channels, ["me", "client"]);
    for grant in &token.grants {
        assert_eq!(grant.transport, "websocket");
        assert_eq!(Protocol::from_grant(grant), Some(Protocol::AssemblyAiV3));
        let url = grant.ws_url.as_deref().expect("ws_url");
        assert!(
            url.starts_with("wss://streaming.assemblyai.com/v3/ws?"),
            "{url}"
        );
        assert!(
            url.contains("encoding=pcm_s16le") && url.contains("sample_rate=16000"),
            "{url}"
        );
        assert_eq!(grant.max_session_seconds, Some(10_800));
        let audio = grant.audio.as_ref().expect("audio");
        assert_eq!(
            (
                audio.encoding.as_str(),
                audio.sample_rate,
                audio.channels,
                audio.frame_ms
            ),
            ("pcm_s16le", 16_000, 1, 50)
        );
        assert_eq!(grant.init_messages.len(), 1);
        assert_eq!(grant.init_messages[0]["type"], "UpdateConfiguration");
        assert_eq!(
            grant.init_messages[0]["keyterms_prompt"],
            serde_json::to_value(&token.keyterms).unwrap()
        );
    }
}

#[test]
fn dictation_grants_cover_only_the_microphone() {
    let token: SttTokenResponse = parse("stt_token_200_dictation");
    assert!(token.keyterms.is_empty());
    assert_eq!(token.grants.len(), 1);
    assert_eq!(token.grants[0].channel, "me");
    assert!(
        token.grants[0].init_messages.is_empty(),
        "no keyterms, so nothing to configure"
    );
    assert_eq!(
        Protocol::from_grant(&token.grants[0]),
        Some(Protocol::AssemblyAiV3)
    );
}

// ---- Sessions and uploads --------------------------------------------------------------------------------------

#[test]
fn a_new_session_comes_back_with_its_upload_slot() {
    let created: CreateSessionResponse = parse("sessions_store_201");
    assert!(is_id(&created.session.id));
    assert!(!created.session.deleted);
    assert!(is_rfc3339(&created.session.updated_at));
    let s = &created.session.rest;
    assert_eq!(s["source_device"], "desktop");
    assert_eq!(s["source_app"], "zoom");
    assert_eq!(s["title"], "Quarterly review");
    assert_eq!(s["status"], "uploading");
    assert_eq!(s["client"]["name"], "Acme Holdings");
    assert_eq!(s["transcript"]["final_status"], Value::Null);
    assert_eq!(s["transcript"]["live_available"], false);
    assert_eq!(created.required_artifacts, ["primary"]);

    assert_eq!(created.recordings.len(), 1);
    let rec = &created.recordings[0];
    assert!(is_id(&rec.id));
    assert_eq!(rec.role, "primary");
    assert_eq!(rec.upload.chunk_size_bytes, 5_242_880);
    assert_eq!(rec.upload.total_chunks, 1);
    assert!(rec.upload.received_chunks.is_empty());
    assert_eq!(rec.upload.status, "pending");
}

#[test]
fn repeating_a_session_returns_the_same_session() {
    let first: CreateSessionResponse = parse("sessions_store_201");
    let again: CreateSessionResponse = parse("sessions_store_200_existing");
    assert_eq!(first.session.id, again.session.id);
    assert_eq!(
        first.session.rest["client_ref"],
        again.session.rest["client_ref"]
    );
    assert_eq!(first.recordings[0].id, again.recordings[0].id);
    assert_eq!(index()["sessions_store_200_existing"]["status"], 200);
    assert_eq!(index()["sessions_store_201"]["status"], 201);
}

#[test]
fn a_session_without_title_or_client_gets_a_display_title() {
    let created: CreateSessionResponse = parse("sessions_store_201_minimal");
    assert_eq!(
        created.session.rest["title"], "WhatsApp call",
        "the server fills in a title"
    );
    assert!(created.session.rest["client"].is_null());
    assert_eq!(created.session.rest["source_device"], "phone");
    let voice: CreateSessionResponse = parse("sessions_store_201_voice_note");
    assert_eq!(voice.session.rest["source_app_label"], "Voice note");
    assert_eq!(voice.session.rest["title"], "Shopping list");
}

#[test]
fn the_server_chunk_count_matches_the_clients_chunk_plan() {
    let created: CreateSessionResponse = parse("sessions_store_201_two_chunks");
    let upload = &created.recordings[0].upload;
    assert_eq!(upload.total_chunks, 2);
    // The artifact in this capture was 5.5 MiB.
    let plan = plan_chunks(5_767_168, upload.chunk_size_bytes);
    assert_eq!(plan.len() as u64, upload.total_chunks);
    assert_eq!(plan[0], (0, 5_242_880));
    assert_eq!(plan[1], (5_242_880, 524_288));

    let single: CreateSessionResponse = parse("sessions_store_201");
    assert_eq!(
        plan_chunks(512_044, single.recordings[0].upload.chunk_size_bytes).len() as u64,
        1
    );
}

#[test]
fn upload_state_tracks_the_received_chunks() {
    let pending: RecordingUpload = parse("recordings_show_200_pending");
    assert!(pending.upload.received_chunks.is_empty());
    assert_eq!(pending.upload.status, "pending");

    let uploading: RecordingUpload = parse("recordings_show_200_uploading");
    assert_eq!(uploading.upload.received_chunks, [0]);
    assert_eq!(uploading.upload.total_chunks, 2);
    assert_eq!(
        missing_chunks(
            uploading.upload.total_chunks,
            &uploading.upload.received_chunks
        ),
        [1]
    );

    let all: RecordingUpload = parse("recordings_show_200_all_chunks");
    assert!(missing_chunks(all.upload.total_chunks, &all.upload.received_chunks).is_empty());

    let ready: RecordingUpload = parse("recordings_show_200_ready");
    assert_eq!(ready.upload.status, "ready");
    assert_eq!(ready.role, "primary");
}

#[test]
fn chunk_acknowledgements_omit_the_chunk_size() {
    // The chunk reply has no chunk_size_bytes, so it cannot be read as the full UploadState; ApiClient::upload_chunk
    // therefore uses its own response type. Guard that both facts stay true.
    for name in [
        "chunks_200_partial",
        "chunks_200_resend",
        "chunks_200_single",
        "chunks_200_last",
    ] {
        let body = fx(name);
        assert!(body.get("chunk_size_bytes").is_none(), "{name}");
        assert!(
            body["received_chunks"].is_array()
                && body["total_chunks"].is_u64()
                && body["status"].is_string(),
            "{name}"
        );
    }
    assert!(serde_json::from_value::<UploadState>(fx("chunks_200_last")).is_err());
    assert_eq!(
        fx("chunks_200_last")["received_chunks"],
        serde_json::json!([0, 1])
    );
}

#[test]
fn the_missing_chunk_report_lists_indexes() {
    let body = fx("recordings_complete_422_missing_chunks");
    assert_eq!(body["message"], "Upload is incomplete.");
    let missing: Vec<u64> = serde_json::from_value(body["missing_chunks"].clone()).unwrap();
    assert_eq!(missing, [1]);
    assert!(
        fx("recordings_complete_202")["status"].is_string(),
        "complete answers with a status string"
    );
}

// ---- Session detail --------------------------------------------------------------------------------------------

#[test]
fn session_detail_has_the_fields_the_library_reads() {
    for name in [
        "sessions_show_200_uploading",
        "sessions_show_200_completed",
        "sessions_show_200_completed_android",
        "sessions_show_200_failed",
        "sessions_show_200_processing",
        "sessions_show_200_with_pending_artifact",
    ] {
        let detail = fx(name);
        let item: LibraryItem = parse(name);
        assert!(!item.deleted, "{name}");
        assert!(is_id(&item.id), "{name}");
        let recordings = detail["recordings"]
            .as_array()
            .unwrap_or_else(|| panic!("{name}: recordings"));
        assert!(!recordings.is_empty(), "{name}");
        for r in recordings {
            for key in ["id", "role", "mime", "status"] {
                assert!(r[key].is_string(), "{name}: recordings[].{key}");
            }
            assert!(r["size_bytes"].is_u64(), "{name}: size_bytes");
        }
        for t in detail["transcripts"]
            .as_array()
            .unwrap_or_else(|| panic!("{name}: transcripts"))
        {
            for key in ["id", "kind", "provider", "status"] {
                assert!(t[key].is_string(), "{name}: transcripts[].{key}");
            }
        }
        let playback = &detail["playback_url"];
        assert!(
            playback.is_null() || playback.is_string(),
            "{name}: playback_url"
        );
    }
}

#[test]
fn playback_is_offered_only_once_the_recording_is_ready() {
    assert!(fx("sessions_show_200_uploading")["playback_url"].is_null());
    for name in [
        "sessions_show_200_completed",
        "sessions_show_200_failed",
        "sessions_show_200_processing",
    ] {
        let url = fx(name)["playback_url"]
            .as_str()
            .unwrap_or_else(|| panic!("{name}: no playback_url"))
            .to_string();
        assert!(url.starts_with("https://"), "{url}");
        assert!(
            url.contains("/audio?expires=") && url.contains("&signature="),
            "{url}"
        );
    }
    let two = fx("sessions_show_200_with_pending_artifact");
    // API.md promises no order for the recordings of a session, so look them up by role.
    let recordings = two["recordings"].as_array().unwrap();
    let status_of = |role: &str| {
        recordings
            .iter()
            .find(|r| r["role"] == role)
            .map(|r| r["status"].clone())
    };
    assert_eq!(recordings.len(), 2);
    assert_eq!(status_of("primary"), Some(Value::from("ready")));
    assert_eq!(status_of("channel_1"), Some(Value::from("pending")));
}

// ---- Library ---------------------------------------------------------------------------------------------------

#[test]
fn library_page_items_carry_what_the_list_renders() {
    let page: LibraryResponse = parse("library_200_page");
    assert!(is_rfc3339(&page.server_time));
    assert!(page.next_cursor.is_none());
    assert_eq!(page.data.len(), 5);
    let mut devices = BTreeSet::new();
    for item in &page.data {
        assert!(
            is_id(&item.id) && !item.deleted && is_rfc3339(&item.updated_at),
            "{}",
            item.id
        );
        let r = &item.rest;
        for key in [
            "client_ref",
            "source_device",
            "source_device_label",
            "source_device_icon",
            "source_app",
            "source_app_label",
            "title",
            "started_at",
            "status",
        ] {
            assert!(
                r[key].is_string(),
                "{}: {key} should be a string: {}",
                item.id,
                r[key]
            );
        }
        assert!(is_rfc3339(field(r, "started_at")));
        assert!(r["duration_ms"].is_u64(), "{}: duration_ms", item.id);
        assert!(r["transcript"]["live_available"].is_boolean());
        devices.insert(field(r, "source_device").to_string());
        let icon = field(r, "source_device_icon");
        assert!(
            ["device-phone-mobile", "computer-desktop", "globe-alt"].contains(&icon),
            "{icon}"
        );
    }
    assert_eq!(
        devices,
        BTreeSet::from(["desktop".to_string(), "phone".to_string()])
    );
}

#[test]
fn library_item_states_cover_completed_processing_and_failed() {
    let page: LibraryResponse = parse("library_200_page");
    let by_title = |title: &str| {
        page.data
            .iter()
            .find(|i| i.rest["title"] == title)
            .unwrap_or_else(|| panic!("no item {title}"))
    };
    let done = by_title("Quarterly review");
    assert_eq!(done.rest["status"], "completed");
    assert_eq!(done.rest["transcript"]["final_status"], "completed");
    assert!(is_id(str_of(
        &done.rest["transcript"],
        "final_transcript_id"
    )));
    assert!(is_id(str_of(
        &done.rest["transcript"],
        "live_transcript_id"
    )));
    assert!(str_of(&done.rest["transcript"], "preview").starts_with("Me: "));

    let processing = by_title("Vendor sync");
    assert_eq!(processing.rest["status"], "processing");
    assert_eq!(processing.rest["transcript"]["final_status"], "submitted");
    assert!(processing.rest["transcript"]["preview"].is_null());
    assert_eq!(processing.rest["client"]["name"], "Birch & Co");

    let failed = by_title("WhatsApp call");
    assert_eq!(failed.rest["status"], "failed");
    assert_eq!(failed.rest["transcript"]["final_status"], "failed");
    assert!(failed.rest["client"].is_null());

    let note = by_title("Shopping list");
    assert_eq!(note.rest["source_app"], "voice_note");
    assert!(
        !str_of(&note.rest["transcript"], "preview").contains("Me: "),
        "a mono transcript has no speaker prefix"
    );
}

#[test]
fn library_items_reserialise_unchanged_for_the_ui() {
    for name in [
        "library_200_page",
        "library_200_with_tombstones",
        "library_200_cursor_page1",
    ] {
        let page: LibraryResponse = parse(name);
        let original = fx(name);
        let rows = original["data"].as_array().unwrap();
        assert_eq!(page.data.len(), rows.len(), "{name}");
        for (item, row) in page.data.iter().zip(rows) {
            assert_eq!(
                &serde_json::to_value(item).unwrap(),
                row,
                "{name}: {} must round-trip",
                item.id
            );
        }
    }
}

#[test]
fn cursor_pages_chain_without_gaps_or_repeats() {
    let first: LibraryResponse = parse("library_200_cursor_page1");
    let second: LibraryResponse = parse("library_200_cursor_page2");
    assert_eq!(first.data.len(), 2);
    assert_eq!(second.data.len(), 2);
    let cursor = first
        .next_cursor
        .as_deref()
        .expect("a full page has a next cursor");
    assert!(!cursor.is_empty());
    assert!(
        second.next_cursor.is_some(),
        "four of five items seen, one left"
    );
    let ids: BTreeSet<&str> = first
        .data
        .iter()
        .chain(&second.data)
        .map(|i| i.id.as_str())
        .collect();
    assert_eq!(ids.len(), 4, "no item repeats across pages");
    assert!(
        first.data[0].updated_at >= first.data[1].updated_at,
        "newest first"
    );
    assert!(first.data[1].updated_at >= second.data[0].updated_at);
}

#[test]
fn filters_return_subsets_of_the_full_library() {
    let all: LibraryResponse = parse("library_200_page");
    let all_ids: BTreeSet<&str> = all.data.iter().map(|i| i.id.as_str()).collect();
    for (name, key, value) in [
        ("library_200_filter_phone", "source_device", "phone"),
        ("library_200_filter_zoom", "source_app", "zoom"),
        ("library_200_filter_failed", "status", "failed"),
    ] {
        let page: LibraryResponse = parse(name);
        assert!(!page.data.is_empty(), "{name}");
        for item in &page.data {
            assert_eq!(item.rest[key], value, "{name}");
            assert!(
                all_ids.contains(item.id.as_str()),
                "{name}: unknown item {}",
                item.id
            );
        }
    }
    let by_client: LibraryResponse = parse("library_200_filter_client");
    assert_eq!(by_client.data.len(), 1);
    assert_eq!(by_client.data[0].rest["client"]["name"], "Acme Holdings");
}

#[test]
fn tombstones_are_minimal_and_remove_the_cached_item() {
    let tombstone: LibraryResponse = parse("library_200_since_tombstone");
    assert_eq!(tombstone.data.len(), 1);
    let gone = &tombstone.data[0];
    assert!(gone.deleted);
    assert!(is_rfc3339(&gone.updated_at));
    let mut keys: Vec<&str> = gone.rest.keys().map(String::as_str).collect();
    keys.sort_unstable();
    assert_eq!(
        keys,
        ["client_ref"],
        "a tombstone carries only id, client_ref, deleted and updated_at"
    );
    let gone_id = gone.id.clone();

    // The cache the desktop keeps, then the incremental sync after the failed call was deleted on another device.
    let before: LibraryResponse = parse("library_200_page");
    let mut cache = before.data;
    assert!(
        cache.iter().any(|i| i.id == gone_id),
        "the deleted session was in the library before"
    );
    let count = cache.len();
    merge(&mut cache, tombstone.data);
    assert_eq!(cache.len(), count - 1);
    assert!(cache.iter().all(|i| i.id != gone_id));

    let after: LibraryResponse = parse("library_200_page_after_delete");
    assert_eq!(after.data.len(), cache.len());
    let mut ids: Vec<&str> = cache.iter().map(|i| i.id.as_str()).collect();
    let mut expected: Vec<&str> = after.data.iter().map(|i| i.id.as_str()).collect();
    ids.sort_unstable();
    expected.sort_unstable();
    assert_eq!(
        ids, expected,
        "merging the tombstone yields the same library as a fresh full sync"
    );
}

#[test]
fn an_incremental_sync_with_no_changes_is_an_empty_page() {
    let page: LibraryResponse = parse("library_200_since_empty");
    assert!(page.data.is_empty());
    assert!(page.next_cursor.is_none());
    assert!(is_rfc3339(&page.server_time));
}

#[test]
fn a_full_sync_after_deletions_includes_tombstones_for_the_cache_to_apply() {
    let page: LibraryResponse = parse("library_200_with_tombstones");
    let deleted = page.data.iter().filter(|i| i.deleted).count();
    let live = page.data.len() - deleted;
    assert!(
        deleted >= 1 && live >= 1,
        "{deleted} tombstones, {live} live items"
    );
    let mut cache: Vec<LibraryItem> = Vec::new();
    merge(&mut cache, page.data);
    assert!(
        cache.iter().all(|i| !i.deleted),
        "tombstones never enter the cache"
    );
    assert_eq!(cache.len(), live);
}

// ---- Transcripts -----------------------------------------------------------------------------------------------

#[test]
fn a_completed_stereo_transcript_parses_into_segments() {
    let t = fx("transcripts_show_200_final_completed");
    for key in ["id", "session_id", "kind", "provider", "status", "text"] {
        assert!(t[key].is_string(), "{key}");
    }
    assert_eq!(
        (
            str_of(&t, "kind"),
            str_of(&t, "status"),
            str_of(&t, "provider")
        ),
        ("final", "completed", "assemblyai")
    );
    assert!(is_rfc3339(str_of(&t, "created_at")) && is_rfc3339(str_of(&t, "completed_at")));
    assert!(t["error"].is_null());
    let segments: Vec<SegmentDto> = serde_json::from_value(t["segments"].clone()).unwrap();
    assert_eq!(segments.len(), 4);
    assert_eq!(
        (segments[0].channel, segments[0].speaker.as_str()),
        (1, "Me")
    );
    assert_eq!(
        (segments[1].channel, segments[1].speaker.as_str()),
        (2, "Client")
    );
    assert!(
        segments
            .iter()
            .all(|s| s.start_ms < s.end_ms && !s.text.is_empty() && !s.words.is_empty())
    );
    assert!(
        segments.windows(2).all(|w| w[0].start_ms <= w[1].start_ms),
        "segments arrive in time order"
    );
    let word = &segments[0].words[0];
    assert!(word["text"].is_string() && word["start_ms"].is_u64() && word["confidence"].is_f64());
}

#[test]
fn a_mono_transcript_is_one_speaker_on_channel_one() {
    let t = fx("transcripts_show_200_final_mono");
    let segments: Vec<SegmentDto> = serde_json::from_value(t["segments"].clone()).unwrap();
    assert_eq!(segments.len(), 1);
    assert_eq!(
        (segments[0].channel, segments[0].speaker.as_str()),
        (1, "Speaker")
    );
}

#[test]
fn a_failed_transcript_explains_why_and_has_no_text() {
    let t = fx("transcripts_show_200_final_failed");
    assert_eq!(t["status"], "failed");
    assert!(t["text"].is_null() && t["language"].is_null() && t["completed_at"].is_null());
    assert_eq!(t["segments"], serde_json::json!([]));
    assert!(
        str_of(&t, "error").len() > 10,
        "the library shows this text to the user"
    );
}

#[test]
fn a_transcript_still_in_flight_has_no_segments() {
    let t = fx("transcripts_show_200_final_processing");
    assert_eq!(t["status"], "submitted");
    assert!(t["text"].is_null() && t["error"].is_null() && t["completed_at"].is_null());
    assert!(t["segments"].as_array().unwrap().is_empty());
}

#[test]
fn live_transcripts_posted_by_the_client_come_back_intact() {
    let stored = fx("transcripts_store_201_live");
    let fetched = fx("transcripts_show_200_live");
    for key in ["id", "session_id", "kind", "status", "text", "segments"] {
        assert_eq!(stored[key], fetched[key], "{key}");
    }
    assert_eq!(
        (str_of(&stored, "kind"), str_of(&stored, "status")),
        ("live", "completed")
    );

    // What the desktop posts (DeviceTranscript) is what the server returns, field for field.
    let segments: Vec<SegmentDto> = serde_json::from_value(stored["segments"].clone()).unwrap();
    let posted = DeviceTranscript {
        kind: "live".into(),
        provider: "assemblyai".into(),
        language: Some("en".into()),
        segments,
    };
    assert_eq!(
        serde_json::to_value(&posted).unwrap()["segments"],
        stored["segments"]
    );
}

// ---- Settings that change the shape of the answers -------------------------------------------------------------

fn grant_channels(token: &SttTokenResponse) -> Vec<&str> {
    token.grants.iter().map(|g| g.channel.as_str()).collect()
}

#[test]
fn the_caption_policy_decides_which_channels_get_a_grant() {
    let client_only: ServerConfig = parse("config_200_captions_client_only");
    assert_eq!(client_only.captions.channels, "client_only");
    let token: SttTokenResponse = parse("stt_token_200_client_only");
    assert_eq!(
        grant_channels(&token),
        ["client"],
        "the server drops the microphone grant"
    );
    assert_eq!(token.mode, "cloud");

    let off: ServerConfig = parse("config_200_captions_off");
    assert_eq!(off.captions.channels, "off");
    let token: SttTokenResponse = parse("stt_token_200_captions_off");
    assert_eq!(token.mode, "off");
    assert!(token.provider.is_none() && token.expires_at.is_none());
    assert!(token.grants.is_empty() && token.keyterms.is_empty());

    let dictation: SttTokenResponse = parse("stt_token_200_dictation_captions_off");
    assert_eq!(
        grant_channels(&dictation),
        ["me"],
        "dictation ignores the caption policy"
    );
}

#[test]
fn on_device_captions_hand_out_no_grants() {
    let config: ServerConfig = parse("config_200_on_device_captions");
    let live = &config.providers["live_captions"];
    assert_eq!(
        (live.provider.as_str(), live.mode.as_str()),
        ("whispercpp_local", "on_device")
    );
    let token: SttTokenResponse = parse("stt_token_200_on_device");
    assert_eq!(
        (token.provider.as_deref(), token.mode.as_str()),
        (Some("whispercpp_local"), "on_device")
    );
    assert!(token.grants.is_empty() && token.expires_at.is_none());
}

#[test]
fn providers_that_need_channel_files_say_so_in_config_and_in_the_session_response() {
    let config: ServerConfig = parse("config_200_channel_artifacts");
    let fin = &config.providers["final_transcript"];
    assert_eq!(fin.mode, "sync");
    assert_eq!(fin.preferred_primary.as_deref(), Some("flac"));
    assert_eq!(
        config.required_artifacts("final_transcript"),
        ["primary", "channel_1", "channel_2"]
    );

    let created: CreateSessionResponse = parse("sessions_store_201_three_artifacts");
    assert_eq!(
        created.required_artifacts,
        ["primary", "channel_1", "channel_2"]
    );
    // API.md promises no order for the upload slots; the uploader finds each one by role.
    let mut roles: Vec<&str> = created.recordings.iter().map(|r| r.role.as_str()).collect();
    roles.sort_unstable();
    assert_eq!(roles, ["channel_1", "channel_2", "primary"]);
    assert!(
        created
            .recordings
            .iter()
            .all(|r| r.upload.status == "pending")
    );

    let detail = fx("sessions_show_200_three_artifacts");
    assert_eq!(detail["recordings"].as_array().unwrap().len(), 3);
    assert!(
        detail["playback_url"].is_null(),
        "no playback until the primary file is ready"
    );
}

#[test]
fn an_on_device_final_provider_is_announced_as_device_mode() {
    let config: ServerConfig = parse("config_200_device_mode");
    assert_eq!(config.providers["final_transcript"].mode, "device");
    assert_eq!(config.required_artifacts("final_transcript"), ["primary"]);
}

#[test]
fn pins_arrive_with_registration_and_the_client_can_use_them() {
    let registered: RegisterResponse = parse("register_201_with_pins");
    let fetched: ServerConfig = parse("config_200_with_pins");
    assert_eq!(registered.config.pins.len(), 2);
    assert_eq!(registered.config.pins, fetched.pins);
    for pin in &fetched.pins {
        assert!(pin.starts_with("sha256/") && pin.len() == 51, "{pin}");
    }
    assert_eq!(
        desktop_lib::net::pinning::parse_pins(&fetched.pins).len(),
        2,
        "the desktop's own pin parser accepts what the server sends"
    );
    common::init_tls();
    ApiClient::new("https://ledger.example.com", &fetched.pins, None).expect("a pinned client");
}

#[test]
fn repeating_the_creation_of_a_deleted_session_returns_its_tombstone() {
    let again: CreateSessionResponse = parse("sessions_store_200_after_delete");
    assert!(again.session.deleted);
    assert!(is_id(&again.session.id) && is_rfc3339(&again.session.updated_at));
    assert!(
        again.recordings.is_empty(),
        "there is nothing left to upload to"
    );
    assert_eq!(again.required_artifacts, ["primary"]);
    assert_eq!(index()["sessions_store_200_after_delete"]["status"], 200);
}

// ---- The typed client over the wire ----------------------------------------------------------------------------

fn lifecycle_router() -> Router {
    let created: CreateSessionResponse = parse("sessions_store_201");
    let session_id = created.session.id.clone();
    let recording_id = created.recordings[0].id.clone();
    let transcript_id = str_of(&fx("transcripts_show_200_final_completed"), "id").to_string();
    let json = |status: u16, name: &str| Reply::raw_json(status, &raw(name));
    Router::new()
        .fixed(
            "POST",
            "/api/devices/register",
            json(201, "register_201_windows"),
        )
        .fixed("GET", "/api/config", json(200, "config_200"))
        .fixed("GET", "/api/clients", json(200, "clients_200"))
        .fixed(
            "POST",
            "/api/stt/token",
            json(200, "stt_token_200_live_captions"),
        )
        .fixed("POST", "/api/sessions", json(201, "sessions_store_201"))
        .fixed(
            "GET",
            &format!("/api/recordings/{recording_id}"),
            json(200, "recordings_show_200_uploading"),
        )
        .fixed(
            "POST",
            &format!("/api/recordings/{recording_id}/chunks"),
            json(200, "chunks_200_last"),
        )
        .fixed(
            "POST",
            &format!("/api/recordings/{recording_id}/complete"),
            json(202, "recordings_complete_202"),
        )
        .fixed(
            "POST",
            &format!("/api/sessions/{session_id}/transcripts"),
            json(201, "transcripts_store_201_live"),
        )
        .fixed("GET", "/api/library", json(200, "library_200_page"))
        .fixed(
            "GET",
            &format!("/api/sessions/{session_id}"),
            json(200, "sessions_show_200_completed"),
        )
        .fixed(
            "GET",
            &format!("/api/transcripts/{transcript_id}"),
            json(200, "transcripts_show_200_final_completed"),
        )
        .fixed(
            "DELETE",
            &format!("/api/sessions/{session_id}"),
            Reply::empty(204),
        )
}

fn request_for(created: &CreateSessionResponse) -> CreateSessionRequest {
    CreateSessionRequest {
        markers: vec![],
        pauses: vec![],
        client_ref: created.session.rest["client_ref"]
            .as_str()
            .expect("client_ref")
            .to_string(),
        source_app: "zoom".into(),
        client_id: None,
        title: None,
        started_at: "2026-10-24T15:56:41+00:00".into(),
        ended_at: Some("2026-10-24T15:56:49+00:00".into()),
        duration_ms: Some(8_000),
        language: None,
        artifacts: vec![ArtifactDto {
            role: "primary".into(),
            mime: "audio/wav".into(),
            codec: Some("pcm_s16le".into()),
            channels: 2,
            sample_rate: Some(16_000),
            size_bytes: 512_044,
            sha256: "0".repeat(64),
            duration_ms: Some(8_000),
        }],
    }
}

#[tokio::test]
async fn the_client_runs_a_whole_session_lifecycle_against_server_responses() {
    let server = MockServer::start(lifecycle_router());
    let created_fixture: CreateSessionResponse = parse("sessions_store_201");

    let registered = anonymous(&server)
        .register("ABCD-EFGH", "Work laptop", "windows", "11")
        .await
        .unwrap();
    assert_eq!(registered.device.source_device, "desktop");
    assert!(registered.token.starts_with("1|eld_"));

    let api = client(&server);
    let config = api.config().await.unwrap();
    assert_eq!(config.required_artifacts("final_transcript"), ["primary"]);

    let clients = api.clients().await.unwrap();
    assert_eq!(clients["data"][0]["name"], "Acme Holdings");

    let token = api
        .stt_token(
            "live_captions",
            &["me", "client"],
            Some("01m5qa084edsznvjc59xnyt37x"),
            None,
        )
        .await
        .unwrap();
    assert_eq!(token.grants.len(), 2);

    let created = api
        .create_session(&request_for(&created_fixture))
        .await
        .unwrap();
    let rec = &created.recordings[0];
    assert_eq!(rec.upload.status, "pending");

    let status = api.recording_status(&rec.id).await.unwrap();
    assert_eq!(status.upload.received_chunks, [0]);

    let state = api
        .upload_chunk(&rec.id, 1, vec![1, 2, 3], "ab".repeat(32).as_str())
        .await
        .unwrap();
    assert_eq!(
        (
            state.received_chunks.as_slice(),
            state.total_chunks,
            state.status.as_str()
        ),
        (&[0u64, 1][..], 2, "uploading")
    );
    assert_eq!(
        state.chunk_size_bytes, 0,
        "the chunk reply does not repeat the chunk size"
    );

    api.complete(&rec.id).await.unwrap();

    let posted = DeviceTranscript {
        kind: "live".into(),
        provider: "assemblyai".into(),
        language: Some("en".into()),
        segments: vec![],
    };
    api.post_transcript(&created.session.id, &posted)
        .await
        .unwrap();

    let library = api.library(None, None).await.unwrap();
    assert_eq!(library.data.len(), 5);

    let detail = api.session(&created.session.id).await.unwrap();
    assert!(
        detail["playback_url"]
            .as_str()
            .unwrap()
            .contains("/audio?expires=")
    );

    let transcript_id = str_of(&fx("transcripts_show_200_final_completed"), "id").to_string();
    let transcript = api.transcript(&transcript_id).await.unwrap();
    assert_eq!(transcript["segments"].as_array().unwrap().len(), 4);

    api.delete_session(&created.session.id).await.unwrap();

    // Every authenticated call carried the device token.
    for request in server
        .requests()
        .iter()
        .filter(|r| r.path() != "/api/devices/register")
    {
        assert_eq!(
            request.header("authorization"),
            Some(format!("Bearer {TOKEN}").as_str()),
            "{} {}",
            request.method,
            request.path()
        );
    }
    let register = server.requests_to("POST", "/api/devices/register");
    assert_eq!(register[0].header("authorization"), None);
}

#[tokio::test]
async fn the_library_query_sends_since_and_cursor_encoded() {
    let server = MockServer::start(Router::new().fixed(
        "GET",
        "/api/library",
        Reply::raw_json(200, &raw("library_200_cursor_page1")),
    ));
    let api = client(&server);
    let page = api
        .library(
            Some("2026-10-24T15:58:56+00:00"),
            Some("eyJ1cGRhdGVkX2F0IjoiMjAyNiJ9"),
        )
        .await
        .unwrap();
    assert!(page.next_cursor.is_some());
    let sent = &server.requests_to("GET", "/api/library")[0];
    let query = common::query_pairs(sent.query());
    assert!(
        query.contains(&("since".to_string(), "2026-10-24T15:58:56+00:00".to_string())),
        "{query:?}"
    );
    assert!(query.contains(&("limit".to_string(), "100".to_string())));
    assert!(
        sent.query().unwrap().contains("%2B00%3A00") || sent.query().unwrap().contains("%2B00:00"),
        "the + of the UTC offset must be percent-encoded: {:?}",
        sent.query()
    );
}

#[tokio::test]
async fn server_errors_become_the_message_the_user_sees() {
    let cases: Vec<(&str, &str, &str, u16, &str)> = vec![
        // (fixture, method, path, status, expected message)
        (
            "errors_403_missing_ability",
            "GET",
            "/api/library",
            403,
            "Invalid ability provided.",
        ),
        (
            "errors_404_session",
            "GET",
            "/api/sessions/01zzzzzzzzzzzzzzzzzzzzzzzz",
            404,
            "",
        ),
        (
            "sessions_store_422_validation",
            "POST",
            "/api/sessions",
            422,
            "",
        ),
        (
            "recordings_complete_422_missing_chunks",
            "POST",
            "/api/recordings/r1/complete",
            422,
            "Upload is incomplete.",
        ),
        (
            "chunks_409_already_complete",
            "POST",
            "/api/recordings/r1/chunks",
            409,
            "Recording upload is already complete.",
        ),
        (
            "chunks_413_too_large",
            "POST",
            "/api/recordings/r1/chunks",
            413,
            "Chunk is larger than expected.",
        ),
        (
            "chunks_422_checksum_mismatch",
            "POST",
            "/api/recordings/r1/chunks",
            422,
            "Chunk checksum mismatch.",
        ),
        (
            "errors_429_stt_token",
            "POST",
            "/api/stt/token",
            429,
            "Too Many Attempts.",
        ),
        (
            "transcripts_store_422_final_from_device",
            "POST",
            "/api/sessions/s1/transcripts",
            422,
            "This session is transcribed by the server provider.",
        ),
    ];
    for (name, method, path, status, message) in cases {
        let expected = if message.is_empty() {
            str_of(&fx(name), "message").to_string()
        } else {
            message.to_string()
        };
        let server = MockServer::start(Router::new().fixed(
            method,
            path,
            Reply::raw_json(status, &raw(name)),
        ));
        let api = client(&server);
        let result = match (method, path) {
            ("GET", "/api/library") => api.library(None, None).await.map(|_| ()),
            ("GET", p) if p.starts_with("/api/sessions/") => {
                api.session("01zzzzzzzzzzzzzzzzzzzzzzzz").await.map(|_| ())
            }
            ("POST", "/api/sessions") => api
                .create_session(&request_for(&parse("sessions_store_201")))
                .await
                .map(|_| ()),
            ("POST", p) if p.ends_with("/complete") => api.complete("r1").await.map(|_| ()),
            ("POST", p) if p.ends_with("/chunks") => {
                api.upload_chunk("r1", 1, vec![0], "ab").await.map(|_| ())
            }
            ("POST", "/api/stt/token") => {
                api.stt_token("dictation", &["me"], None, None).await.map(|_| ())
            }
            ("POST", p) if p.ends_with("/transcripts") => {
                let t = DeviceTranscript {
                    kind: "final".into(),
                    provider: "parakeet_local".into(),
                    language: None,
                    segments: vec![],
                };
                api.post_transcript("s1", &t).await
            }
            other => panic!("unhandled case {other:?}"),
        };
        let (got_status, got_message) = server_error(result);
        assert_eq!(got_status, status, "{name}");
        assert_eq!(got_message, expected, "{name}");
    }
}

#[tokio::test]
async fn an_unauthorised_response_means_the_device_must_pair_again() {
    for name in [
        "errors_401_missing_token",
        "errors_401_invalid_token",
        "errors_401_expired_token",
        "errors_401_revoked_token",
    ] {
        let server = MockServer::start(Router::new().fixed(
            "GET",
            "/api/config",
            Reply::raw_json(401, &raw(name)),
        ));
        let result = client(&server).config().await;
        assert!(
            matches!(result, Err(AppError::NotPaired)),
            "{name}: {result:?}"
        );
    }
}

#[tokio::test]
async fn a_bad_pairing_code_reports_the_servers_reason() {
    for (name, expected) in [
        (
            "register_422_invalid_code",
            "This pairing code is invalid, expired, or already used.",
        ),
        (
            "register_422_used_code",
            "This pairing code is invalid, expired, or already used.",
        ),
        (
            "register_422_validation",
            "The pairing code field is required. (and 2 more errors)",
        ),
    ] {
        let server = MockServer::start(Router::new().fixed(
            "POST",
            "/api/devices/register",
            Reply::raw_json(422, &raw(name)),
        ));
        let (status, message) = server_error(
            anonymous(&server)
                .register("ABCD-EFGH", "Work laptop", "windows", "11")
                .await,
        );
        assert_eq!((status, message.as_str()), (422, expected), "{name}");
    }
}
