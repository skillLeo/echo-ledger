//! Wire-model deserialisation against JSON captured in the shapes the Laravel server really produces
//! (`web/app/Http/Resources`, `DeviceConfigPayload`, the STT drivers). Unknown fields must be tolerated and nulls
//! must not break parsing.

mod common;

use serde_json::{Value, json};

use common::fixture;
use desktop_lib::error::AppError;
use desktop_lib::net::models::*;
use desktop_lib::stt::stream::Protocol;

/// Adds an unrecognised member to every JSON object in the document, at every depth. The `providers` map is keyed
/// by use case, so a new key there would have to be a full provider entry and is left alone.
fn with_unknown_fields(value: &Value) -> Value {
    fn add(value: &Value, key: &str) -> Value {
        match value {
            Value::Object(map) => {
                let mut out: serde_json::Map<String, Value> =
                    map.iter().map(|(k, v)| (k.clone(), add(v, k))).collect();
                if key != "providers" {
                    out.insert(
                        "x_added_by_a_newer_server".into(),
                        json!({"nested": [1, null, "two"]}),
                    );
                }
                Value::Object(out)
            }
            Value::Array(items) => Value::Array(items.iter().map(|v| add(v, key)).collect()),
            other => other.clone(),
        }
    }
    add(value, "")
}

fn parse<T: serde::de::DeserializeOwned>(name: &str) -> T {
    serde_json::from_value(fixture(name)).unwrap_or_else(|e| panic!("{name}: {e}"))
}

// ---- GET /api/config -------------------------------------------------------------------------------------------

#[test]
fn config_parses_every_documented_field() {
    let config: ServerConfig = parse("config.json");
    assert_eq!(config.device.id, "01JB8Q3R0Z5V1K7M9N2P4S6T8W");
    assert_eq!(
        (
            config.device.platform.as_str(),
            config.device.source_device.as_str()
        ),
        ("windows", "desktop")
    );
    assert_eq!(config.server_time, "2026-10-24T09:00:00+00:00");
    assert_eq!(config.retention_days, 30);
    assert_eq!(config.upload.chunk_size_bytes, 5_242_880);
    assert_eq!(config.upload.max_recording_bytes, 2_147_483_648);
    assert_eq!(config.upload.accepted_mimes.len(), 10);
    assert_eq!(config.captions.channels, "both");
    assert_eq!(config.pins.len(), 2);
    assert_eq!(config.providers.len(), 4);
    let live = &config.providers["live_captions"];
    assert_eq!(
        (live.provider.as_str(), live.mode.as_str()),
        ("assemblyai", "cloud")
    );
    assert!(
        live.required_artifacts.is_none() && live.preferred_primary.is_none(),
        "streaming use cases carry no artifact list"
    );
    let fin = &config.providers["final_transcript"];
    assert_eq!(fin.mode, "async_webhook");
    assert_eq!(
        fin.required_artifacts.as_deref(),
        Some(&["primary".to_string()][..])
    );
    assert_eq!(fin.preferred_primary.as_deref(), Some("ogg_opus"));
}

#[test]
fn config_for_a_provider_that_needs_channel_artifacts() {
    let config: ServerConfig = parse("config_channel_artifacts.json");
    assert_eq!(
        config.required_artifacts("final_transcript"),
        ["primary", "channel_1", "channel_2"]
    );
    assert_eq!(config.required_artifacts("voice_note"), ["primary"]);
    assert_eq!(
        config.providers["voice_note"].preferred_primary, None,
        "null preferred_primary"
    );
    assert_eq!(config.captions.channels, "client_only");
    assert!(config.pins.is_empty());
}

#[test]
fn required_artifacts_default_to_the_primary_file() {
    let config: ServerConfig = parse("config.json");
    assert_eq!(config.required_artifacts("unknown_use_case"), ["primary"]);
    assert_eq!(
        config.required_artifacts("live_captions"),
        ["primary"],
        "no list for streaming use cases"
    );
    assert_eq!(
        ServerConfig::default().required_artifacts("final_transcript"),
        ["primary"]
    );
}

#[test]
fn config_tolerates_unknown_fields_at_every_depth() {
    let plain: ServerConfig = parse("config.json");
    let noisy: ServerConfig = serde_json::from_value(with_unknown_fields(&fixture("config.json")))
        .expect("unknown fields must be ignored");
    assert_eq!(
        serde_json::to_value(&noisy).unwrap(),
        serde_json::to_value(&plain).unwrap()
    );
}

#[test]
fn config_tolerates_missing_optional_sections() {
    let mut v = fixture("config.json");
    for key in ["providers", "captions", "pins"] {
        v.as_object_mut().unwrap().remove(key);
    }
    v["upload"]
        .as_object_mut()
        .unwrap()
        .remove("accepted_mimes");
    let config: ServerConfig = serde_json::from_value(v).expect("optional sections may be absent");
    assert!(
        config.providers.is_empty()
            && config.pins.is_empty()
            && config.upload.accepted_mimes.is_empty()
    );
    assert_eq!(config.captions.channels, "");
}

#[test]
fn config_without_its_required_sections_is_rejected() {
    for key in ["device", "server_time", "retention_days", "upload"] {
        let mut v = fixture("config.json");
        v.as_object_mut().unwrap().remove(key);
        assert!(
            serde_json::from_value::<ServerConfig>(v).is_err(),
            "config without {key} should not parse"
        );
    }
}

#[test]
fn caption_policy_defaults_to_both_when_the_server_sends_none() {
    // `AppState::server_caption_channels` treats an empty string as "both"; the model default must be that empty string.
    assert_eq!(CaptionsConfig::default().channels, "");
}

// ---- POST /api/devices/register --------------------------------------------------------------------------------

#[test]
fn register_response_parses_the_token_and_embedded_config() {
    let r: RegisterResponse = parse("register_201.json");
    assert_eq!(r.token, "7|eld_TOKEN_PLACEHOLDER");
    assert_eq!(
        r.token_expires_at.as_deref(),
        Some("2027-10-24T09:00:00+00:00")
    );
    assert_eq!(r.device.name, "Work laptop");
    assert_eq!(
        r.config.pins,
        ["sha256/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA="]
    );
    assert_eq!(r.config.upload.chunk_size_bytes, 5_242_880);
}

#[test]
fn register_response_accepts_a_null_expiry_and_extra_fields() {
    let mut v = with_unknown_fields(&fixture("register_201.json"));
    v["token_expires_at"] = Value::Null;
    let r: RegisterResponse = serde_json::from_value(v).unwrap();
    assert!(r.token_expires_at.is_none());
}

#[test]
fn register_response_without_a_token_is_rejected() {
    let mut v = fixture("register_201.json");
    v.as_object_mut().unwrap().remove("token");
    assert!(serde_json::from_value::<RegisterResponse>(v).is_err());
}

// ---- POST /api/stt/token ---------------------------------------------------------------------------------------

#[test]
fn assemblyai_grants_carry_the_url_audio_format_and_keyterm_message() {
    let t: SttTokenResponse = parse("stt_token_assemblyai.json");
    assert_eq!(
        (t.provider.as_deref(), t.mode.as_str()),
        (Some("assemblyai"), "cloud")
    );
    assert_eq!(t.keyterms, ["Acme Holdings", "Farah Khan"]);
    assert_eq!(t.grants.len(), 2);
    let me = &t.grants[0];
    assert_eq!(
        (
            me.channel.as_str(),
            me.transport.as_str(),
            me.protocol.as_deref()
        ),
        ("me", "websocket", Some("assemblyai_v3"))
    );
    assert!(
        me.ws_url
            .as_deref()
            .unwrap()
            .starts_with("wss://streaming.assemblyai.com/v3/ws?")
    );
    assert_eq!(me.max_session_seconds, Some(10_800));
    let audio = me.audio.as_ref().unwrap();
    assert_eq!(
        (
            audio.encoding.as_str(),
            audio.sample_rate,
            audio.channels,
            audio.frame_ms
        ),
        ("pcm_s16le", 16_000, 1, 50)
    );
    assert_eq!(
        me.init_messages,
        [
            json!({"type": "UpdateConfiguration", "keyterms_prompt": ["Acme Holdings", "Farah Khan"]})
        ]
    );
    assert_eq!(t.grants[1].channel, "client");
    assert_eq!(Protocol::from_grant(me), Some(Protocol::AssemblyAiV3));
}

#[test]
fn elevenlabs_grants_have_no_init_messages_and_an_unlimited_session() {
    let t: SttTokenResponse = parse("stt_token_elevenlabs.json");
    let g = &t.grants[0];
    assert_eq!(g.max_session_seconds, None, "null max_session_seconds");
    assert!(g.init_messages.is_empty());
    assert_eq!(g.audio.as_ref().unwrap().frame_ms, 100);
    assert!(g.ws_url.as_deref().unwrap().contains("commit_strategy=vad"));
    assert_eq!(Protocol::from_grant(g), Some(Protocol::ElevenLabsRealtime));
}

#[test]
fn azure_grants_parse_but_are_not_streamable_on_desktop() {
    let t: SttTokenResponse = parse("stt_token_azure.json");
    let g = &t.grants[0];
    assert_eq!(g.transport, "azure_speech_sdk");
    assert!(g.ws_url.is_none());
    assert_eq!(Protocol::from_grant(g), None);
}

#[test]
fn on_device_and_disabled_caption_responses_have_no_grants() {
    let on_device: SttTokenResponse = parse("stt_token_on_device.json");
    assert_eq!(
        (on_device.mode.as_str(), on_device.provider.as_deref()),
        ("on_device", Some("whispercpp_local"))
    );
    assert!(on_device.grants.is_empty() && on_device.expires_at.is_none());

    let off: SttTokenResponse = parse("stt_token_off.json");
    assert_eq!(off.mode, "off");
    assert!(off.provider.is_none(), "null provider");
    assert!(off.grants.is_empty());
}

#[test]
fn stt_token_tolerates_unknown_fields_and_missing_lists() {
    let noisy: SttTokenResponse =
        serde_json::from_value(with_unknown_fields(&fixture("stt_token_assemblyai.json"))).unwrap();
    assert_eq!(noisy.grants.len(), 2);
    let minimal: SttTokenResponse = serde_json::from_value(
        json!({"mode": "cloud", "grants": [{"channel": "me", "transport": "websocket"}]}),
    )
    .unwrap();
    assert!(minimal.provider.is_none() && minimal.keyterms.is_empty());
    assert!(
        minimal.grants[0].protocol.is_none()
            && minimal.grants[0].ws_url.is_none()
            && minimal.grants[0].init_messages.is_empty()
    );
    assert_eq!(Protocol::from_grant(&minimal.grants[0]), None);
}

#[test]
fn a_grant_with_an_unknown_protocol_name_is_not_streamable() {
    let mut g: Grant = serde_json::from_value(
        json!({"channel": "me", "transport": "websocket", "protocol": "deepgram_v1"}),
    )
    .unwrap();
    assert_eq!(Protocol::from_grant(&g), None);
    g.protocol = Some("ASSEMBLYAI_V3".into());
    assert_eq!(
        Protocol::from_grant(&g),
        None,
        "protocol names are case-sensitive"
    );
}

// ---- Sessions and uploads --------------------------------------------------------------------------------------

#[test]
fn create_session_response_parses_the_upload_slots() {
    let r: CreateSessionResponse = parse("session_created.json");
    assert_eq!(r.session.id, "01JB8QA0M1X2C3V4B5N6M7K8J9");
    assert_eq!(r.session.rest["source_app"], "whatsapp");
    assert!(r.session.rest["client"].is_null());
    assert_eq!(r.required_artifacts, ["primary"]);
    let rec = &r.recordings[0];
    assert_eq!(
        (rec.id.as_str(), rec.role.as_str()),
        ("01JB8QA1P2Q3R4S5T6V7W8X9YZ", "primary")
    );
    assert_eq!(
        (
            rec.upload.chunk_size_bytes,
            rec.upload.total_chunks,
            rec.upload.status.as_str()
        ),
        (5_242_880, 2, "pending")
    );
    assert!(rec.upload.received_chunks.is_empty());
}

#[test]
fn an_existing_session_response_lists_the_chunks_already_received() {
    let mut v = fixture("session_created.json");
    v["recordings"][0]["upload"]["received_chunks"] = json!([0]);
    v["recordings"][0]["upload"]["status"] = json!("uploading");
    let r: CreateSessionResponse = serde_json::from_value(v).unwrap();
    assert_eq!(r.recordings[0].upload.received_chunks, [0]);
}

#[test]
fn create_session_response_tolerates_extras_and_a_missing_artifact_list() {
    let mut v = with_unknown_fields(&fixture("session_created.json"));
    v.as_object_mut().unwrap().remove("required_artifacts");
    let r: CreateSessionResponse = serde_json::from_value(v).unwrap();
    assert!(r.required_artifacts.is_empty());
}

#[test]
fn chunk_acknowledgements_are_plain_objects() {
    let ack = fixture("chunk_response.json");
    assert_eq!(ack["received_chunks"], json!([0, 1]));
    let state: UploadState = serde_json::from_value(json!({"chunk_size_bytes": 5, "total_chunks": 2, "received_chunks": [1, 0], "status": "uploading"})).unwrap();
    assert_eq!(state.received_chunks, [1, 0]);
}

// ---- Library ---------------------------------------------------------------------------------------------------

#[test]
fn library_page_parses_items_nulls_and_tombstones() {
    let page: LibraryResponse = parse("library_page.json");
    assert_eq!(page.server_time, "2026-10-24T09:05:00+00:00");
    assert!(page.next_cursor.is_none());
    assert_eq!(page.data.len(), 3);

    let zoom = &page.data[0];
    assert!(!zoom.deleted);
    assert_eq!(zoom.updated_at, "2026-10-24T08:50:00+00:00");
    assert_eq!(zoom.rest["source_app_label"], "Zoom");
    assert_eq!(zoom.rest["transcript"]["live_available"], true);

    let phone = &page.data[1];
    assert!(
        phone.rest["device"].is_null()
            && phone.rest["client"].is_null()
            && phone.rest["ended_at"].is_null()
            && phone.rest["duration_ms"].is_null()
    );
    assert!(phone.rest["transcript"]["preview"].is_null());

    let tombstone = &page.data[2];
    assert!(tombstone.deleted);
    assert_eq!(
        tombstone.rest.keys().collect::<Vec<_>>(),
        ["client_ref"],
        "a tombstone carries only id, client_ref, deleted, updated_at"
    );
}

#[test]
fn library_pages_with_cursors_and_extras() {
    let mut v = with_unknown_fields(&fixture("library_page.json"));
    v["next_cursor"] = json!(
        "eyJ1cGRhdGVkX2F0IjoiMjAyNi0xMC0yNCAwOTowMDowMCIsIl9wb2ludHNUb05leHRJdGVtcyI6dHJ1ZX0"
    );
    let page: LibraryResponse = serde_json::from_value(v).unwrap();
    assert!(page.next_cursor.unwrap().starts_with("eyJ1"));
    assert!(
        page.data[0].rest.contains_key("x_added_by_a_newer_server"),
        "unknown item fields pass through to the UI"
    );
}

#[test]
fn library_items_reserialise_with_every_server_field_for_the_ui() {
    let page: LibraryResponse = parse("library_page.json");
    let original = fixture("library_page.json");
    for (item, raw) in page.data.iter().zip(original["data"].as_array().unwrap()) {
        assert_eq!(
            &serde_json::to_value(item).unwrap(),
            raw,
            "{} must round-trip untouched",
            item.id
        );
    }
}

#[test]
fn library_items_without_a_deleted_flag_are_live() {
    let item: LibraryItem = serde_json::from_value(
        json!({"id": "x", "updated_at": "2026-10-24T09:00:00+00:00", "title": "t"}),
    )
    .unwrap();
    assert!(!item.deleted);
    assert_eq!(serde_json::to_value(&item).unwrap()["deleted"], false);
}

#[test]
fn library_items_without_an_id_or_timestamp_are_rejected() {
    assert!(
        serde_json::from_value::<LibraryItem>(json!({"updated_at": "2026-10-24T09:00:00+00:00"}))
            .is_err()
    );
    assert!(serde_json::from_value::<LibraryItem>(json!({"id": "x"})).is_err());
    assert!(serde_json::from_value::<LibraryItem>(json!({"id": "x", "updated_at": null})).is_err());
}

#[test]
fn session_and_transcript_details_are_passed_through_as_json() {
    let detail = fixture("session_detail.json");
    assert_eq!(detail["recordings"][0]["status"], "ready");
    assert!(
        detail["playback_url"]
            .as_str()
            .unwrap()
            .contains("signature=")
    );
    let transcript = fixture("transcript.json");
    let segments: Vec<SegmentDto> = serde_json::from_value(transcript["segments"].clone()).unwrap();
    assert_eq!(
        (
            segments[0].channel,
            segments[0].speaker.as_str(),
            segments[0].start_ms,
            segments[0].end_ms
        ),
        (1, "Me", 250, 2_600)
    );
    assert_eq!(segments[0].words.len(), 1);
    assert_eq!(segments[1].channel, 2);
    assert!(segments[1].words.is_empty());
}

#[test]
fn segments_without_words_are_accepted() {
    let s: SegmentDto = serde_json::from_value(
        json!({"channel": 2, "speaker": "Client", "start_ms": 0, "end_ms": 5, "text": "hi"}),
    )
    .unwrap();
    assert!(s.words.is_empty());
    assert!(
        serde_json::from_value::<SegmentDto>(
            json!({"channel": 3000, "speaker": "x", "start_ms": 0, "end_ms": 5, "text": "hi"})
        )
        .is_err(),
        "channel is a u8"
    );
}

// ---- Requests the client sends ---------------------------------------------------------------------------------

fn artifact() -> ArtifactDto {
    ArtifactDto {
        role: "primary".into(),
        mime: "audio/flac".into(),
        codec: Some("flac".into()),
        channels: 2,
        sample_rate: Some(16_000),
        size_bytes: 12_345,
        sha256: "ab".repeat(32),
        duration_ms: Some(2_530_000),
    }
}

#[test]
fn create_session_request_uses_the_api_field_names() {
    let request = CreateSessionRequest {
        client_ref: "3f2b6c1e-8d4a-4d3e-9a51-2c7f0e9b1a22".into(),
        source_app: "whatsapp".into(),
        client_id: None,
        title: None,
        started_at: "2026-10-24T09:00:00+00:00".into(),
        ended_at: Some("2026-10-24T09:42:10+00:00".into()),
        duration_ms: Some(2_530_000),
        language: None,
        artifacts: vec![artifact()],
    };
    let v = serde_json::to_value(&request).unwrap();
    let keys: Vec<&str> = v.as_object().unwrap().keys().map(String::as_str).collect();
    let mut expected = vec![
        "client_ref",
        "source_app",
        "client_id",
        "title",
        "started_at",
        "ended_at",
        "duration_ms",
        "language",
        "artifacts",
    ];
    let mut got = keys.clone();
    expected.sort_unstable();
    got.sort_unstable();
    assert_eq!(got, expected);
    assert!(v["client_id"].is_null() && v["title"].is_null() && v["language"].is_null());
    let a = &v["artifacts"][0];
    let mut artifact_keys: Vec<&str> = a.as_object().unwrap().keys().map(String::as_str).collect();
    artifact_keys.sort_unstable();
    assert_eq!(
        artifact_keys,
        [
            "channels",
            "codec",
            "duration_ms",
            "mime",
            "role",
            "sample_rate",
            "sha256",
            "size_bytes"
        ]
    );
    assert_eq!(a["sha256"].as_str().unwrap().len(), 64);
}

#[test]
fn device_transcript_serialises_in_the_documented_shape() {
    let transcript = DeviceTranscript {
        kind: "live".into(),
        provider: "assemblyai".into(),
        language: None,
        segments: vec![SegmentDto {
            channel: 1,
            speaker: "Me".into(),
            start_ms: 250,
            end_ms: 2_600,
            text: "Hello.".into(),
            words: vec![],
        }],
    };
    assert_eq!(
        serde_json::to_value(&transcript).unwrap(),
        json!({"kind": "live", "provider": "assemblyai", "language": null, "segments": [{"channel": 1, "speaker": "Me", "start_ms": 250, "end_ms": 2600, "text": "Hello.", "words": []}]})
    );
}

// ---- Errors shown to the user ----------------------------------------------------------------------------------

#[test]
fn errors_serialise_to_plain_messages() {
    let cases: [(AppError, &str); 5] = [
        (
            AppError::NotPaired,
            "This computer is not paired with an EchoLedger server yet.",
        ),
        (
            AppError::Server {
                status: 422,
                message: "The pairing code is invalid.".into(),
            },
            "Server returned 422: The pairing code is invalid.",
        ),
        (
            AppError::Network("connection refused".into()),
            "Network error: connection refused",
        ),
        (AppError::msg("custom"), "custom"),
        (
            AppError::Audio("no device".into()),
            "Audio error: no device",
        ),
    ];
    for (error, text) in cases {
        assert_eq!(serde_json::to_value(&error).unwrap(), json!(text));
    }
}
