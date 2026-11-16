//! Chat Completions streaming, the format Groq and OpenAI share (`POST {base}/v1/chat/completions`, `stream: true`).
//!
//! - The context's system segments become one `system` message and the rest one `user` message, in the same order
//!   every time: both providers cache a repeated prefix by themselves, so there are no cache markers to place.
//! - `stream_options.include_usage` asks for the token counts in the last chunk; Groq may send them in `x_groq.usage`
//!   instead, and either is read. `prompt_tokens_details.cached_tokens` is the cached share.
//! - The model's `options` from the settings go to the request as they are (`reasoning_effort` for gpt-oss on Groq).
//! - `finish_reason: "content_filter"` is a declined request. `data: [DONE]` ends the answer.
//! - There is no warm-up request: the first suggestion writes the cache. The warm-up only opens the connection.
//!
//! The SSE fixtures in the tests follow the providers' documented formats; they are not recordings.

use std::sync::Arc;

use futures_util::future::BoxFuture;
use futures_util::{FutureExt, StreamExt};
use serde::Deserialize;
use serde_json::{Map, Value, json};

use super::http::{self, Decoded, Decoder, Retry, SseEvent};
use super::{CompletionRequest, Provider, ProviderError, StreamEvent, TokenStream, Usage};
use crate::config::Secret;
use crate::context::Role;
use crate::domain::ProviderKind;
use crate::internal::ProviderKeys;

pub const GROQ_BASE_URL: &str = "https://api.groq.com/openai";
pub const OPENAI_BASE_URL: &str = "https://api.openai.com";

#[derive(Debug, Clone)]
pub struct OpenAiCompatible {
    kind: ProviderKind,
    base: String,
    keys: Arc<ProviderKeys>,
    http: reqwest::Client,
    retry: Retry,
    tap: Option<http::Tap>,
}

impl OpenAiCompatible {
    /// `kind` is Groq or OpenAI; `base` is the address before `/v1/chat/completions`.
    pub fn new(kind: ProviderKind, base: &str, keys: Arc<ProviderKeys>, http: reqwest::Client, retry: Retry) -> Self {
        OpenAiCompatible { kind, base: base.trim_end_matches('/').to_owned(), keys, http, retry, tap: None }
    }

    /// Copies every response body into `tap` (the smoke binary's `--record`).
    pub fn with_tap(mut self, tap: http::Tap) -> Self {
        self.tap = Some(tap);
        self
    }

    fn post(&self, key: &Secret, body: &Value) -> reqwest::RequestBuilder {
        self.http
            .post(format!("{}/v1/chat/completions", self.base))
            .bearer_auth(String::from_utf8_lossy(key.expose()))
            .header(reqwest::header::CONTENT_TYPE, "application/json")
            .body(body.to_string())
    }
}

/// The request body.
pub fn body(request: &CompletionRequest) -> Value {
    let joined = |role: Role| request.context.segments.iter().filter(|s| s.role == role).map(|s| s.text.as_str()).collect::<String>();
    let (system, user) = (joined(Role::System), joined(Role::User));
    let mut messages = Vec::new();
    if !system.is_empty() {
        messages.push(json!({"role": "system", "content": system}));
    }
    messages.push(json!({"role": "user", "content": user}));
    let mut body = Map::new();
    body.insert("model".into(), json!(request.model));
    body.insert("messages".into(), Value::Array(messages));
    body.insert("stream".into(), json!(true));
    body.insert("stream_options".into(), json!({"include_usage": true}));
    body.insert("max_completion_tokens".into(), json!(request.max_output_tokens));
    for (name, value) in &request.options {
        body.insert(name.clone(), http::json_of(value));
    }
    Value::Object(body)
}

#[derive(Debug, Deserialize, Default, Clone, Copy)]
struct ApiUsage {
    #[serde(default)]
    prompt_tokens: u32,
    #[serde(default)]
    completion_tokens: u32,
    #[serde(default)]
    prompt_tokens_details: Option<PromptDetails>,
}

#[derive(Debug, Deserialize, Default, Clone, Copy)]
struct PromptDetails {
    #[serde(default)]
    cached_tokens: u32,
}

impl ApiUsage {
    fn usage(self) -> Usage {
        let cached = self.prompt_tokens_details.map_or(0, |d| d.cached_tokens).min(self.prompt_tokens);
        Usage {
            input_tokens: self.prompt_tokens - cached,
            cached_input_tokens: cached,
            cache_write_tokens: 0,
            output_tokens: self.completion_tokens,
        }
    }
}

#[derive(Deserialize)]
struct Chunk {
    #[serde(default)]
    choices: Vec<Choice>,
    #[serde(default)]
    usage: Option<ApiUsage>,
    #[serde(default)]
    x_groq: Option<XGroq>,
    #[serde(default)]
    error: Option<Value>,
}

#[derive(Deserialize)]
struct XGroq {
    #[serde(default)]
    usage: Option<ApiUsage>,
}

#[derive(Deserialize)]
struct Choice {
    #[serde(default)]
    delta: Option<ChoiceDelta>,
    #[serde(default)]
    finish_reason: Option<String>,
}

#[derive(Deserialize)]
struct ChoiceDelta {
    #[serde(default)]
    content: Option<String>,
}

#[derive(Debug, Default)]
pub struct ChatDecoder {
    usage: Option<ApiUsage>,
    refused: bool,
}

impl Decoder for ChatDecoder {
    fn decode(&mut self, event: SseEvent) -> Decoded {
        let mut out = Decoded::default();
        if event.data.trim() == "[DONE]" {
            out.finished = true;
            if self.refused {
                out.fail(ProviderError::Declined);
            }
            return out;
        }
        let Ok(chunk) = serde_json::from_str::<Chunk>(&event.data) else {
            return out;
        };
        if chunk.error.is_some() {
            out.fail(ProviderError::Unavailable);
            return out;
        }
        for choice in &chunk.choices {
            if let Some(text) = choice.delta.as_ref().and_then(|d| d.content.as_deref()) {
                out.text(text);
            }
            self.refused |= choice.finish_reason.as_deref() == Some("content_filter");
        }
        if let Some(usage) = chunk.usage.or_else(|| chunk.x_groq.and_then(|x| x.usage)) {
            self.usage = Some(usage);
        }
        out
    }

    fn finish(&mut self) -> Option<StreamEvent> {
        if self.refused {
            return None;
        }
        self.usage.map(|usage| StreamEvent::Usage(usage.usage()))
    }
}

impl Provider for OpenAiCompatible {
    fn kind(&self) -> ProviderKind {
        self.kind
    }

    fn stream(&self, request: CompletionRequest) -> TokenStream {
        let kind = self.kind;
        let Some(key) = self.keys.get(kind) else {
            return futures_util::stream::once(async move { Err(ProviderError::NotConfigured(kind)) }).boxed();
        };
        let body = body(&request);
        let this = self.clone();
        let tap = self.tap.clone();
        http::sse_stream(kind, self.retry, move || this.post(&key, &body), ChatDecoder::default(), tap)
    }

    fn warm(&self, _request: CompletionRequest) -> BoxFuture<'static, Result<Usage, ProviderError>> {
        let kind = self.kind;
        let ready = self.keys.get(kind).is_some();
        async move { if ready { Ok(Usage::default()) } else { Err(ProviderError::NotConfigured(kind)) } }.boxed()
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn event(data: &str) -> SseEvent {
        SseEvent { event: None, data: data.into() }
    }

    #[test]
    fn chunks_become_text_and_the_last_usage() {
        let mut decoder = ChatDecoder::default();
        let first = decoder.decode(event(r#"{"choices":[{"index":0,"delta":{"role":"assistant","content":""},"finish_reason":null}]}"#));
        assert!(first.items.is_empty());
        let text = decoder.decode(event(r#"{"choices":[{"index":0,"delta":{"content":"Yes, "},"finish_reason":null}]}"#));
        assert_eq!(text.items, vec![Ok(StreamEvent::Text("Yes, ".into()))]);
        decoder.decode(event(r#"{"choices":[{"index":0,"delta":{},"finish_reason":"stop"}],"x_groq":{"usage":{"prompt_tokens":900,"completion_tokens":20,"prompt_tokens_details":{"cached_tokens":800}}}}"#));
        let done = decoder.decode(event("[DONE]"));
        assert!(done.finished && done.items.is_empty());
        assert_eq!(
            decoder.finish(),
            Some(StreamEvent::Usage(Usage { input_tokens: 100, cached_input_tokens: 800, cache_write_tokens: 0, output_tokens: 20 }))
        );

        let mut openai = ChatDecoder::default();
        openai.decode(event(r#"{"choices":[],"usage":{"prompt_tokens":50,"completion_tokens":5}}"#));
        assert_eq!(openai.finish(), Some(StreamEvent::Usage(Usage { input_tokens: 50, output_tokens: 5, ..Usage::default() })));

        let mut filtered = ChatDecoder::default();
        filtered.decode(event(r#"{"choices":[{"index":0,"delta":{},"finish_reason":"content_filter"}]}"#));
        assert_eq!(filtered.decode(event("[DONE]")).items, vec![Err(ProviderError::Declined)]);
    }
}
