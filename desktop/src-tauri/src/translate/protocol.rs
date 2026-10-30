//! Soniox real-time protocol for Live Translate (v1.3, Phase B): the first text frame is the configuration, then raw
//! 16 kHz mono PCM; replies carry tokens marked original or translation, final or not, and `<end>` at the end of a
//! sentence. Pure, so it is tested without a network (docs/research/soniox-live-translate.md).

use serde::{Deserialize, Serialize};
use serde_json::{Value, json};

pub const SAMPLE_RATE: u32 = 16_000;
pub const KEEPALIVE: &str = r#"{"type":"keepalive"}"#;
/// The empty text frame that ends the audio (an empty binary frame does not).
pub const END_OF_AUDIO: &str = "";

/// How soon a sentence is finished: a shorter wait after speech is faster but splits more.
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize, Default)]
#[serde(rename_all = "lowercase")]
pub enum Speed {
    Fastest,
    #[default]
    Balanced,
    Accurate,
}

impl Speed {
    pub fn endpoint_delay_ms(self) -> u32 {
        match self {
            Speed::Fastest => 500,
            Speed::Balanced => 1_000,
            Speed::Accurate => 1_500,
        }
    }
}

/// `POST /api/live-translate/token`: a temporary key and how to open the stream.
#[derive(Debug, Clone, Deserialize)]
pub struct LiveTranslateToken {
    pub api_key: String,
    pub expires_at: String,
    pub websocket_url: String,
    pub model: String,
    pub translation: TranslationConfig,
    #[serde(default)]
    pub language_hints: Vec<String>,
}

#[derive(Debug, Clone, Deserialize, Serialize)]
pub struct TranslationConfig {
    #[serde(rename = "type")]
    pub kind: String,
    pub target_language: String,
}

pub fn config(token: &LiveTranslateToken, speed: Speed) -> String {
    let mut c = json!({
        "api_key": token.api_key,
        "model": token.model,
        "audio_format": "pcm_s16le",
        "sample_rate": SAMPLE_RATE,
        "num_channels": 1,
        "enable_endpoint_detection": true,
        "max_endpoint_delay_ms": speed.endpoint_delay_ms(),
        "translation": { "type": token.translation.kind, "target_language": token.translation.target_language },
    });
    if !token.language_hints.is_empty() {
        c["language_hints"] = json!(token.language_hints);
    }
    c.to_string()
}

/// One reply: settled and changing text of what was said and of its translation, or an error.
#[derive(Debug, Default, Clone, PartialEq)]
pub struct Reply {
    pub final_original: String,
    pub final_translation: String,
    pub partial_original: String,
    pub partial_translation: String,
    pub language: Option<String>,
    pub endpoint: bool,
    pub finished: bool,
    pub error_code: Option<i64>,
    pub error_message: Option<String>,
}

pub fn parse(frame: &str) -> Reply {
    let Ok(v) = serde_json::from_str::<Value>(frame) else {
        return Reply {
            error_code: Some(-1),
            error_message: Some("Soniox sent something that is not JSON.".into()),
            ..Default::default()
        };
    };
    if let Some(code) = v.get("error_code").and_then(Value::as_i64) {
        return Reply {
            error_code: Some(code),
            error_message: v
                .get("error_message")
                .and_then(Value::as_str)
                .map(str::to_string),
            ..Default::default()
        };
    }
    let mut r = Reply {
        finished: v.get("finished").and_then(Value::as_bool).unwrap_or(false),
        ..Default::default()
    };
    for t in v
        .get("tokens")
        .and_then(Value::as_array)
        .into_iter()
        .flatten()
    {
        let Some(text) = t.get("text").and_then(Value::as_str) else {
            continue;
        };
        if text == "<end>" {
            r.endpoint = true;
            continue;
        }
        let is_final = t.get("is_final").and_then(Value::as_bool).unwrap_or(false);
        let translated = t.get("translation_status").and_then(Value::as_str) == Some("translation");
        if !translated && let Some(lang) = t.get("language").and_then(Value::as_str) {
            r.language = Some(lang.to_string());
        }
        match (is_final, translated) {
            (true, true) => r.final_translation.push_str(text),
            (true, false) => r.final_original.push_str(text),
            (false, true) => r.partial_translation.push_str(text),
            (false, false) => r.partial_original.push_str(text),
        }
    }
    r
}

#[cfg(test)]
mod tests {
    use super::*;

    fn token() -> LiveTranslateToken {
        LiveTranslateToken {
            api_key: "temp:abc".into(),
            expires_at: "2099-01-01T00:00:00Z".into(),
            websocket_url: "wss://example.test".into(),
            model: "stt-rt-v5".into(),
            translation: TranslationConfig {
                kind: "one_way".into(),
                target_language: "en".into(),
            },
            language_hints: vec!["ar".into()],
        }
    }

    #[test]
    fn the_first_frame_holds_the_key_model_audio_format_hints_and_translation() {
        let v: Value = serde_json::from_str(&config(&token(), Speed::Fastest)).unwrap();
        assert_eq!(v["api_key"], "temp:abc");
        assert_eq!(v["model"], "stt-rt-v5");
        assert_eq!(v["audio_format"], "pcm_s16le");
        assert_eq!(v["sample_rate"], 16_000);
        assert_eq!(v["max_endpoint_delay_ms"], 500);
        assert_eq!(v["translation"]["target_language"], "en");
        assert_eq!(v["language_hints"][0], "ar");
    }

    #[test]
    fn a_reply_separates_original_from_translation_final_from_changing_and_the_sentence_end() {
        let r = parse(
            r#"{"tokens":[
              {"text":"مرحبا","is_final":true,"translation_status":"original","language":"ar"},
              {"text":"Hello","is_final":true,"translation_status":"translation","language":"en"},
              {"text":" يا","is_final":false,"translation_status":"original","language":"ar"},
              {"text":"<end>","is_final":true}]}"#,
        );
        assert_eq!(r.final_original, "مرحبا");
        assert_eq!(r.final_translation, "Hello");
        assert_eq!(r.partial_original, " يا");
        assert_eq!(r.language.as_deref(), Some("ar"));
        assert!(r.endpoint);
        assert_eq!(
            parse(r#"{"error_code":402,"error_message":"no credit"}"#).error_code,
            Some(402)
        );
        assert_eq!(parse("not json").error_code, Some(-1));
    }
}
