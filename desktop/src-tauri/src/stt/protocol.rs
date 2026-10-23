//! Pure parsers for provider streaming messages (unit-tested with fixtures in Phase 4).
//! AssemblyAI v3: docs/research/stt-assemblyai.md R3. ElevenLabs realtime: docs/research/stt-elevenlabs.md R8.

use serde_json::Value;

#[derive(Clone, Debug, PartialEq)]
pub enum SttEvent {
    /// Provider accepted the session; send init messages now.
    Started,
    /// Interim text for the current turn (replaces the previous partial).
    Partial { text: String },
    /// Final text for a turn; times are ms from stream start when the provider reports them.
    Final { text: String, start_ms: Option<u64>, end_ms: Option<u64> },
    /// Session ended (after Terminate).
    Terminated,
    Error(String),
}

pub fn parse_assemblyai(raw: &str) -> Option<SttEvent> {
    let v: Value = serde_json::from_str(raw).ok()?;
    match v.get("type")?.as_str()? {
        "Begin" => Some(SttEvent::Started),
        "Turn" => {
            let text = v.get("transcript").and_then(Value::as_str).unwrap_or("").trim().to_string();
            let end_of_turn = v.get("end_of_turn").and_then(Value::as_bool).unwrap_or(false);
            let formatted = v.get("turn_is_formatted").and_then(Value::as_bool).unwrap_or(false);
            if end_of_turn && formatted {
                let words = v.get("words").and_then(Value::as_array);
                let start_ms = words.and_then(|w| w.first()).and_then(|w| w.get("start")).and_then(Value::as_u64);
                let end_ms = words.and_then(|w| w.last()).and_then(|w| w.get("end")).and_then(Value::as_u64);
                if text.is_empty() { None } else { Some(SttEvent::Final { text, start_ms, end_ms }) }
            } else if end_of_turn {
                None // unformatted final; the formatted one follows
            } else {
                Some(SttEvent::Partial { text })
            }
        }
        "Termination" => Some(SttEvent::Terminated),
        "Error" => Some(SttEvent::Error(v.get("error").and_then(Value::as_str).unwrap_or("streaming error").to_string())),
        _ => None,
    }
}

pub fn parse_elevenlabs(raw: &str) -> Option<SttEvent> {
    let v: Value = serde_json::from_str(raw).ok()?;
    let kind = v.get("message_type")?.as_str()?;
    let text = || v.get("text").and_then(Value::as_str).unwrap_or("").trim().to_string();
    match kind {
        "session_started" => Some(SttEvent::Started),
        "partial_transcript" => {
            let t = text();
            if t.is_empty() { None } else { Some(SttEvent::Partial { text: t }) }
        }
        "committed_transcript" | "committed_transcript_with_timestamps" => {
            let t = text();
            if t.is_empty() {
                return None;
            }
            let words = v.get("words").and_then(Value::as_array);
            let secs = |w: Option<&Value>, k: &str| w.and_then(|w| w.get(k)).and_then(Value::as_f64).map(|s| (s * 1000.0).round() as u64);
            Some(SttEvent::Final { text: t, start_ms: secs(words.and_then(|w| w.first()), "start"), end_ms: secs(words.and_then(|w| w.last()), "end") })
        }
        k if v.get("error").is_some() || k.contains("error") => {
            Some(SttEvent::Error(v.get("error").and_then(Value::as_str).unwrap_or(k).to_string()))
        }
        _ => None,
    }
}

/// ElevenLabs audio frame: base64 PCM in a JSON text message.
pub fn elevenlabs_audio_message(pcm: &[u8], commit: bool) -> String {
    use base64::Engine;
    serde_json::json!({
        "message_type": "input_audio_chunk",
        "audio_base_64": base64::engine::general_purpose::STANDARD.encode(pcm),
        "commit": commit,
        "sample_rate": 16000
    })
    .to_string()
}

pub const ASSEMBLYAI_TERMINATE: &str = r#"{"type":"Terminate"}"#;
