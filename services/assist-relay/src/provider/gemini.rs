//! Gemini's `streamGenerateContent` with server-sent events
//! (`POST {base}/v1beta/models/{model}:streamGenerateContent?alt=sse`, key in `x-goog-api-key`).
//!
//! - The context's system segments become `systemInstruction`, the rest one user turn, in the same order every time:
//!   Gemini caches a repeated prefix by itself (implicit caching), so there are no cache markers to place.
//! - The model's `options`: `thinking_budget` goes to `generationConfig.thinkingConfig.thinkingBudget` (0 turns
//!   thinking off on Flash); anything else goes into `generationConfig` as it is.
//! - Text is every non-thought part of the first candidate. `usageMetadata` (the last one wins) gives the prompt,
//!   cached and output counts; thinking tokens count as output, as they are billed.
//! - A finish reason other than `STOP` or `MAX_TOKENS` (`SAFETY`, `RECITATION`, `BLOCKLIST`, `PROHIBITED_CONTENT`,
//!   `SPII`…), or a blocked prompt, is a declined request. There is no end marker: the body ending after a finish reason
//!   is the end, before one a dropped connection.
//! - There is no warm-up request.
//!
//! The SSE fixture in the tests follows the documented format; it is not a recording.

use std::sync::Arc;

use futures_util::future::BoxFuture;
use futures_util::{FutureExt, StreamExt};
use serde::Deserialize;
use serde_json::{Map, Value, json};

use super::http::{self, Decoded, Decoder, Retry, SseEvent, Tap};
use super::{CompletionRequest, Provider, ProviderError, StreamEvent, TokenStream, Usage};
use crate::config::Secret;
use crate::context::Role;
use crate::domain::ProviderKind;
use crate::internal::ProviderKeys;

pub const BASE_URL: &str = "https://generativelanguage.googleapis.com";

#[derive(Debug, Clone)]
pub struct Gemini {
    base: String,
    keys: Arc<ProviderKeys>,
    http: reqwest::Client,
    retry: Retry,
    tap: Option<Tap>,
}

impl Gemini {
    pub fn new(base: &str, keys: Arc<ProviderKeys>, http: reqwest::Client, retry: Retry) -> Self {
        Gemini { base: base.trim_end_matches('/').to_owned(), keys, http, retry, tap: None }
    }

    /// Copies every response body into `tap` (the smoke binary's `--record`).
    pub fn with_tap(mut self, tap: Tap) -> Self {
        self.tap = Some(tap);
        self
    }

    fn post(&self, key: &Secret, model: &str, body: &Value) -> reqwest::RequestBuilder {
        self.http
            .post(format!("{}/v1beta/models/{model}:streamGenerateContent?alt=sse", self.base))
            .header("x-goog-api-key", String::from_utf8_lossy(key.expose()).into_owned())
            .header(reqwest::header::CONTENT_TYPE, "application/json")
            .body(body.to_string())
    }
}

/// The request body.
pub fn body(request: &CompletionRequest) -> Value {
    let joined = |role: Role| request.context.segments.iter().filter(|s| s.role == role).map(|s| s.text.as_str()).collect::<String>();
    let (system, user) = (joined(Role::System), joined(Role::User));
    let mut generation = Map::new();
    generation.insert("maxOutputTokens".into(), json!(request.max_output_tokens));
    for (name, value) in &request.options {
        if name == "thinking_budget" {
            generation.insert("thinkingConfig".into(), json!({"thinkingBudget": http::json_of(value)}));
        } else {
            generation.insert(name.clone(), http::json_of(value));
        }
    }
    let mut body = Map::new();
    if !system.is_empty() {
        body.insert("systemInstruction".into(), json!({"parts": [{"text": system}]}));
    }
    body.insert("contents".into(), json!([{"role": "user", "parts": [{"text": user}]}]));
    body.insert("generationConfig".into(), Value::Object(generation));
    Value::Object(body)
}

#[derive(Debug, Deserialize, Default, Clone, Copy)]
#[serde(rename_all = "camelCase")]
struct UsageMetadata {
    #[serde(default)]
    prompt_token_count: u32,
    #[serde(default)]
    cached_content_token_count: u32,
    #[serde(default)]
    candidates_token_count: u32,
    #[serde(default)]
    thoughts_token_count: u32,
}

impl UsageMetadata {
    fn usage(self) -> Usage {
        let cached = self.cached_content_token_count.min(self.prompt_token_count);
        Usage {
            input_tokens: self.prompt_token_count - cached,
            cached_input_tokens: cached,
            cache_write_tokens: 0,
            output_tokens: self.candidates_token_count + self.thoughts_token_count,
        }
    }
}

#[derive(Deserialize)]
#[serde(rename_all = "camelCase")]
struct Chunk {
    #[serde(default)]
    candidates: Vec<Candidate>,
    #[serde(default)]
    usage_metadata: Option<UsageMetadata>,
    #[serde(default)]
    prompt_feedback: Option<PromptFeedback>,
    #[serde(default)]
    error: Option<Value>,
}

#[derive(Deserialize)]
#[serde(rename_all = "camelCase")]
struct Candidate {
    #[serde(default)]
    content: Option<Content>,
    #[serde(default)]
    finish_reason: Option<String>,
}

#[derive(Deserialize)]
struct Content {
    #[serde(default)]
    parts: Vec<Part>,
}

#[derive(Deserialize)]
struct Part {
    #[serde(default)]
    text: Option<String>,
    #[serde(default)]
    thought: bool,
}

#[derive(Deserialize)]
#[serde(rename_all = "camelCase")]
struct PromptFeedback {
    #[serde(default)]
    block_reason: Option<String>,
}

#[derive(Debug, Default)]
pub struct GeminiDecoder {
    usage: Option<UsageMetadata>,
}

impl Decoder for GeminiDecoder {
    fn decode(&mut self, event: SseEvent) -> Decoded {
        let mut out = Decoded::default();
        let Ok(chunk) = serde_json::from_str::<Chunk>(&event.data) else {
            return out;
        };
        if chunk.error.is_some() {
            out.fail(ProviderError::Unavailable);
            return out;
        }
        if chunk.prompt_feedback.and_then(|f| f.block_reason).is_some() {
            out.fail(ProviderError::Declined);
            out.finished = true;
            return out;
        }
        if let Some(usage) = chunk.usage_metadata {
            self.usage = Some(usage);
        }
        if let Some(candidate) = chunk.candidates.into_iter().next() {
            for part in candidate.content.map(|c| c.parts).unwrap_or_default() {
                if !part.thought
                    && let Some(text) = part.text
                {
                    out.text(&text);
                }
            }
            match candidate.finish_reason.as_deref() {
                None | Some("FINISH_REASON_UNSPECIFIED") => {}
                Some("STOP" | "MAX_TOKENS") => out.finished = true,
                Some(_) => {
                    out.fail(ProviderError::Declined);
                    out.finished = true;
                }
            }
        }
        out
    }

    fn finish(&mut self) -> Option<StreamEvent> {
        self.usage.map(|usage| StreamEvent::Usage(usage.usage()))
    }
}

impl Provider for Gemini {
    fn kind(&self) -> ProviderKind {
        ProviderKind::Gemini
    }

    fn stream(&self, request: CompletionRequest) -> TokenStream {
        let Some(key) = self.keys.get(ProviderKind::Gemini) else {
            return futures_util::stream::once(async { Err(ProviderError::NotConfigured(ProviderKind::Gemini)) }).boxed();
        };
        let body = body(&request);
        let model = request.model.clone();
        let this = self.clone();
        let tap = self.tap.clone();
        http::sse_stream(ProviderKind::Gemini, self.retry, move || this.post(&key, &model, &body), GeminiDecoder::default(), tap)
    }

    fn warm(&self, _request: CompletionRequest) -> BoxFuture<'static, Result<Usage, ProviderError>> {
        let ready = self.keys.get(ProviderKind::Gemini).is_some();
        async move { if ready { Ok(Usage::default()) } else { Err(ProviderError::NotConfigured(ProviderKind::Gemini)) } }.boxed()
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn event(data: &str) -> SseEvent {
        SseEvent { event: None, data: data.into() }
    }

    #[test]
    fn chunks_become_text_usage_and_refusals() {
        let mut decoder = GeminiDecoder::default();
        let first = decoder.decode(event(
            r#"{"candidates":[{"content":{"parts":[{"text":"thinking…","thought":true},{"text":"One month,"}],"role":"model"},"index":0}]}"#,
        ));
        assert_eq!(first.items, vec![Ok(StreamEvent::Text("One month,".into()))]);
        assert!(!first.finished);
        let last = decoder.decode(event(
            r#"{"candidates":[{"content":{"parts":[{"text":" yes."}],"role":"model"},"finishReason":"STOP","index":0}],"usageMetadata":{"promptTokenCount":1900,"cachedContentTokenCount":1024,"candidatesTokenCount":6,"thoughtsTokenCount":2}}"#,
        ));
        assert!(last.finished);
        assert_eq!(
            decoder.finish(),
            Some(StreamEvent::Usage(Usage { input_tokens: 876, cached_input_tokens: 1024, cache_write_tokens: 0, output_tokens: 8 }))
        );
        let safety = GeminiDecoder::default().decode(event(r#"{"candidates":[{"finishReason":"SAFETY","index":0}]}"#));
        assert_eq!(safety.items, vec![Err(ProviderError::Declined)]);
        let blocked = GeminiDecoder::default().decode(event(r#"{"promptFeedback":{"blockReason":"SAFETY"}}"#));
        assert_eq!(blocked.items, vec![Err(ProviderError::Declined)]);
    }
}
