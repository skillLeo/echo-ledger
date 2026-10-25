//! Streaming message parsers for AssemblyAI v3 and ElevenLabs realtime: lifecycle messages, interim and final
//! turns, timing, errors, and frames that are malformed or from the future.

use base64::Engine;
use serde_json::{Value, json};

use desktop_lib::stt::protocol::{
    ASSEMBLYAI_TERMINATE, SttEvent, elevenlabs_audio_message, parse_assemblyai, parse_elevenlabs,
};

fn turn(extra: Value) -> String {
    let mut base = json!({"type": "Turn", "turn_order": 0, "turn_is_formatted": false, "end_of_turn": false, "transcript": "", "end_of_turn_confidence": 0.1, "words": []});
    for (k, v) in extra.as_object().unwrap() {
        base[k] = v.clone();
    }
    base.to_string()
}

fn final_turn(text: &str, words: Value) -> String {
    turn(
        json!({"transcript": text, "end_of_turn": true, "turn_is_formatted": true, "words": words}),
    )
}

// ---- AssemblyAI ------------------------------------------------------------------------------------------------

#[test]
fn assemblyai_begin_and_termination_drive_the_session() {
    let begin = json!({"type": "Begin", "id": "abc-123", "expires_at": 1_790_000_000, "configuration": {"speech_model": "universal-3-6-pro"}});
    assert_eq!(
        parse_assemblyai(&begin.to_string()),
        Some(SttEvent::Started)
    );
    let end = json!({"type": "Termination", "audio_duration_seconds": 61, "session_duration_seconds": 64});
    assert_eq!(
        parse_assemblyai(&end.to_string()),
        Some(SttEvent::Terminated)
    );
}

#[test]
fn assemblyai_interim_turns_are_partials_with_trimmed_text() {
    let raw = turn(
        json!({"transcript": "  thanks for join ", "words": [{"text": "thanks", "start": 100, "end": 300, "confidence": 0.9, "word_is_final": true}]}),
    );
    assert_eq!(
        parse_assemblyai(&raw),
        Some(SttEvent::Partial {
            text: "thanks for join".into()
        })
    );
}

#[test]
fn assemblyai_an_unformatted_end_of_turn_is_held_back_until_the_formatted_one() {
    assert_eq!(
        parse_assemblyai(&turn(
            json!({"transcript": "thanks for joining", "end_of_turn": true, "turn_is_formatted": false})
        )),
        None
    );
    let formatted = final_turn(
        "Thanks for joining.",
        json!([{"text": "Thanks", "start": 120, "end": 480}, {"text": "joining.", "start": 900, "end": 1_450}]),
    );
    assert_eq!(
        parse_assemblyai(&formatted),
        Some(SttEvent::Final {
            text: "Thanks for joining.".into(),
            start_ms: Some(120),
            end_ms: Some(1_450)
        })
    );
}

#[test]
fn assemblyai_finals_without_usable_words_have_no_timing() {
    assert_eq!(
        parse_assemblyai(&final_turn("Hello.", json!([]))),
        Some(SttEvent::Final {
            text: "Hello.".into(),
            start_ms: None,
            end_ms: None
        })
    );
    let no_words = json!({"type": "Turn", "end_of_turn": true, "turn_is_formatted": true, "transcript": "Hello."}).to_string();
    assert_eq!(
        parse_assemblyai(&no_words),
        Some(SttEvent::Final {
            text: "Hello.".into(),
            start_ms: None,
            end_ms: None
        })
    );
    let odd_words = final_turn(
        "Hello.",
        json!([{"text": "Hello."}, {"start": "soon", "end": null}]),
    );
    assert_eq!(
        parse_assemblyai(&odd_words),
        Some(SttEvent::Final {
            text: "Hello.".into(),
            start_ms: None,
            end_ms: None
        })
    );
    let null_words = json!({"type": "Turn", "end_of_turn": true, "turn_is_formatted": true, "transcript": "Hello.", "words": null}).to_string();
    assert_eq!(
        parse_assemblyai(&null_words),
        Some(SttEvent::Final {
            text: "Hello.".into(),
            start_ms: None,
            end_ms: None
        })
    );
}

#[test]
fn assemblyai_empty_finals_are_dropped() {
    assert_eq!(parse_assemblyai(&final_turn("", json!([]))), None);
    assert_eq!(parse_assemblyai(&final_turn("   \n ", json!([]))), None);
}

#[test]
fn assemblyai_errors_carry_the_provider_text() {
    let err = json!({"type": "Error", "error_code": 3007, "error": "Input duration violation: chunk must be 50-1000 ms"});
    assert_eq!(
        parse_assemblyai(&err.to_string()),
        Some(SttEvent::Error(
            "Input duration violation: chunk must be 50-1000 ms".into()
        ))
    );
    assert_eq!(
        parse_assemblyai(r#"{"type":"Error"}"#),
        Some(SttEvent::Error("streaming error".into()))
    );
    assert_eq!(
        parse_assemblyai(r#"{"type":"Error","error":42}"#),
        Some(SttEvent::Error("streaming error".into()))
    );
}

#[test]
fn assemblyai_unknown_message_types_are_ignored() {
    for kind in ["SpeakerRevision", "Warning", "Ping", "turn", "BEGIN", ""] {
        assert_eq!(
            parse_assemblyai(&json!({"type": kind, "transcript": "x"}).to_string()),
            None,
            "{kind:?}"
        );
    }
}

#[test]
fn assemblyai_malformed_frames_never_panic() {
    let long = "x".repeat(1_000_000);
    let nested = format!("{}1{}", "[".repeat(5_000), "]".repeat(5_000));
    let frames: Vec<&str> = vec![
        "",
        " ",
        "null",
        "true",
        "42",
        "\"Turn\"",
        "[]",
        "[{\"type\":\"Turn\"}]",
        "{}",
        "{\"type\":null}",
        "{\"type\":42}",
        "{\"type\":[\"Turn\"]}",
        "{\"type\":\"Turn\"",
        "{\"type\":\"Turn\",}",
        "not json at all",
        "\u{feff}{\"type\":\"Begin\"}",
        "{\"type\":\"Turn\",\"transcript\":42,\"end_of_turn\":true,\"turn_is_formatted\":true}",
        "{\"type\":\"Turn\",\"transcript\":null}",
        "{\"type\":\"Turn\",\"end_of_turn\":\"true\",\"turn_is_formatted\":\"true\",\"transcript\":\"x\"}",
        &long,
        &nested,
    ];
    for frame in frames {
        let _ = parse_assemblyai(frame);
    }
}

#[test]
fn assemblyai_a_turn_with_non_boolean_flags_is_treated_as_interim() {
    let raw = json!({"type": "Turn", "end_of_turn": "true", "turn_is_formatted": "true", "transcript": "x"}).to_string();
    assert_eq!(
        parse_assemblyai(&raw),
        Some(SttEvent::Partial { text: "x".into() })
    );
}

#[test]
fn assemblyai_text_in_other_scripts_passes_through_unchanged() {
    for text in [
        "السلام علیکم، آپ کیسے ہیں؟",
        "你好，世界。",
        "Привет, мир.",
        "Café ☕ — naïve résumé.",
        "😀 done.",
    ] {
        assert_eq!(
            parse_assemblyai(&final_turn(text, json!([]))),
            Some(SttEvent::Final {
                text: text.into(),
                start_ms: None,
                end_ms: None
            })
        );
    }
}

#[test]
fn assemblyai_a_huge_turn_is_handled() {
    let text = "word ".repeat(200_000);
    match parse_assemblyai(&final_turn(&text, json!([]))) {
        Some(SttEvent::Final { text: got, .. }) => assert_eq!(got.len(), text.trim().len()),
        other => panic!("{other:?}"),
    }
}

#[test]
fn assemblyai_large_millisecond_offsets_survive() {
    let three_hours_ms = 10_800_000u64;
    let raw = final_turn(
        "Late.",
        json!([{"start": three_hours_ms - 500, "end": three_hours_ms}]),
    );
    assert_eq!(
        parse_assemblyai(&raw),
        Some(SttEvent::Final {
            text: "Late.".into(),
            start_ms: Some(three_hours_ms - 500),
            end_ms: Some(three_hours_ms)
        })
    );
}

#[test]
fn assemblyai_terminate_message_is_the_documented_json() {
    assert_eq!(
        serde_json::from_str::<Value>(ASSEMBLYAI_TERMINATE).unwrap(),
        json!({"type": "Terminate"})
    );
}

// ---- ElevenLabs ------------------------------------------------------------------------------------------------

#[test]
fn elevenlabs_session_start_and_interim_text() {
    let started = json!({"message_type": "session_started", "session_id": "s1", "config": {"sample_rate": 16000, "include_timestamps": false}});
    assert_eq!(
        parse_elevenlabs(&started.to_string()),
        Some(SttEvent::Started)
    );
    assert_eq!(
        parse_elevenlabs(r#"{"message_type":"partial_transcript","text":" hello wor "}"#),
        Some(SttEvent::Partial {
            text: "hello wor".into()
        })
    );
}

#[test]
fn elevenlabs_empty_keepalive_partials_are_dropped() {
    assert_eq!(
        parse_elevenlabs(r#"{"message_type":"partial_transcript","text":""}"#),
        None
    );
    assert_eq!(
        parse_elevenlabs(r#"{"message_type":"partial_transcript","text":"  "}"#),
        None
    );
    assert_eq!(
        parse_elevenlabs(r#"{"message_type":"partial_transcript"}"#),
        None
    );
}

#[test]
fn elevenlabs_committed_text_without_timestamps_has_no_timing() {
    assert_eq!(
        parse_elevenlabs(
            r#"{"message_type":"committed_transcript","text":"We should ship on Friday."}"#
        ),
        Some(SttEvent::Final {
            text: "We should ship on Friday.".into(),
            start_ms: None,
            end_ms: None
        })
    );
}

#[test]
fn elevenlabs_timestamps_are_seconds_converted_to_rounded_milliseconds() {
    let raw = json!({
        "message_type": "committed_transcript_with_timestamps", "text": "Done.", "language_code": "en",
        "words": [{"text": "Done", "start": 1.0006, "end": 1.4, "type": "word"}, {"text": ".", "start": 1.4, "end": 1.4, "type": "spacing"}, {"text": "Okay", "start": 2.25, "end": 2.9996, "type": "word"}]
    });
    assert_eq!(
        parse_elevenlabs(&raw.to_string()),
        Some(SttEvent::Final {
            text: "Done.".into(),
            start_ms: Some(1_001),
            end_ms: Some(3_000)
        })
    );
}

#[test]
fn elevenlabs_negative_or_missing_times_do_not_wrap() {
    let negative = json!({"message_type": "committed_transcript_with_timestamps", "text": "x", "words": [{"start": -3.0, "end": -1.0}]});
    assert_eq!(
        parse_elevenlabs(&negative.to_string()),
        Some(SttEvent::Final {
            text: "x".into(),
            start_ms: Some(0),
            end_ms: Some(0)
        })
    );
    let missing = json!({"message_type": "committed_transcript_with_timestamps", "text": "x", "words": [{"text": "x"}]});
    assert_eq!(
        parse_elevenlabs(&missing.to_string()),
        Some(SttEvent::Final {
            text: "x".into(),
            start_ms: None,
            end_ms: None
        })
    );
}

#[test]
fn elevenlabs_empty_commits_are_dropped() {
    assert_eq!(
        parse_elevenlabs(r#"{"message_type":"committed_transcript","text":""}"#),
        None
    );
    assert_eq!(
        parse_elevenlabs(
            r#"{"message_type":"committed_transcript_with_timestamps","text":" ","words":[]}"#
        ),
        None
    );
}

#[test]
fn elevenlabs_every_documented_error_event_becomes_an_error() {
    let kinds = [
        "error",
        "auth_error",
        "quota_exceeded",
        "commit_throttled",
        "unaccepted_terms",
        "rate_limited",
        "queue_overflow",
        "resource_exhausted",
        "session_time_limit_exceeded",
        "input_error",
        "invalid_request",
        "chunk_size_exceeded",
        "insufficient_audio_activity",
        "transcriber_error",
    ];
    for kind in kinds {
        let raw = json!({"message_type": kind, "error": format!("{kind} happened")}).to_string();
        assert_eq!(
            parse_elevenlabs(&raw),
            Some(SttEvent::Error(format!("{kind} happened"))),
            "{kind}"
        );
    }
}

#[test]
fn elevenlabs_error_events_without_text_fall_back_to_their_type() {
    assert_eq!(
        parse_elevenlabs(r#"{"message_type":"auth_error"}"#),
        Some(SttEvent::Error("auth_error".into()))
    );
}

#[test]
fn elevenlabs_other_events_are_ignored() {
    for kind in [
        "warning",
        "committed_transcript_entities",
        "edited_transcript",
        "future_event",
        "",
    ] {
        assert_eq!(
            parse_elevenlabs(
                &json!({"message_type": kind, "text": "x", "warning": "w"}).to_string()
            ),
            None,
            "{kind:?}"
        );
    }
}

#[test]
fn elevenlabs_malformed_frames_never_panic() {
    let long = "y".repeat(1_000_000);
    let frames = [
        "",
        "null",
        "[]",
        "{}",
        "{\"message_type\":null}",
        "{\"message_type\":7}",
        "{\"message_type\":\"committed_transcript\"",
        "{\"message_type\":\"committed_transcript\",\"text\":42}",
        "{\"message_type\":\"committed_transcript_with_timestamps\",\"text\":\"x\",\"words\":\"nope\"}",
        "{\"message_type\":\"committed_transcript_with_timestamps\",\"text\":\"x\",\"words\":[null,1,\"a\"]}",
        "{\"message_type\":\"committed_transcript_with_timestamps\",\"text\":\"x\",\"words\":[{\"start\":1e999,\"end\":-1e999}]}",
        "garbage",
        long.as_str(),
    ];
    for frame in frames {
        let _ = parse_elevenlabs(frame);
    }
}

#[test]
fn elevenlabs_text_in_other_scripts_passes_through() {
    let raw = json!({"message_type": "committed_transcript", "text": "میں کل آؤں گا۔"}).to_string();
    assert_eq!(
        parse_elevenlabs(&raw),
        Some(SttEvent::Final {
            text: "میں کل آؤں گا۔".into(),
            start_ms: None,
            end_ms: None
        })
    );
}

// ---- ElevenLabs audio frames -----------------------------------------------------------------------------------

#[test]
fn audio_frames_are_json_with_base64_pcm_and_the_sample_rate() {
    let pcm: Vec<u8> = (0..3_200u32).map(|n| (n % 251) as u8).collect();
    let message: Value = serde_json::from_str(&elevenlabs_audio_message(&pcm, false)).unwrap();
    assert_eq!(message["message_type"], "input_audio_chunk");
    assert_eq!(message["commit"], false);
    assert_eq!(message["sample_rate"], 16_000);
    let decoded = base64::engine::general_purpose::STANDARD
        .decode(message["audio_base_64"].as_str().unwrap())
        .unwrap();
    assert_eq!(decoded, pcm, "the base64 payload is the exact PCM");
}

#[test]
fn the_commit_frame_is_an_empty_chunk_with_commit_set() {
    let message: Value = serde_json::from_str(&elevenlabs_audio_message(&[], true)).unwrap();
    assert_eq!(
        message,
        json!({"message_type": "input_audio_chunk", "audio_base_64": "", "commit": true, "sample_rate": 16000})
    );
}

#[test]
fn audio_frames_carry_exactly_the_four_required_fields() {
    let message: Value =
        serde_json::from_str(&elevenlabs_audio_message(&[1, 2, 3], false)).unwrap();
    let mut keys: Vec<&str> = message
        .as_object()
        .unwrap()
        .keys()
        .map(String::as_str)
        .collect();
    keys.sort_unstable();
    assert_eq!(
        keys,
        ["audio_base_64", "commit", "message_type", "sample_rate"]
    );
}
