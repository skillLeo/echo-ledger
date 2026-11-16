//! Anthropic's Messages API, streamed (`POST /v1/messages`, `stream: true`).
//!
//! API facts this client relies on (Anthropic's API reference, checked 2026-11; section names in brackets):
//! - [Versioning] `anthropic-version: 2026-10-21` is current. From that version `temperature`, `top_p` and `top_k`
//!   are a 400 on every model, so no request carries them, whatever the settings say.
//! - [Effort] `output_config: {"effort": …}` is generally available, with no beta header. The default differs by
//!   model (Haiku 5.5 and Opus 5.5: medium; Sonnet 5.5: high), so every model in the settings sets it explicitly.
//! - [Extended thinking] Haiku 5.5 accepts `thinking: {"type": "disabled"}` at effort high or below. Sonnet 5.5 refuses
//!   `disabled` (400); `{"type": "between_tools"}` turns its thinking off, only at effort high or below and with no
//!   other field in the object. Opus 5.5 can't turn thinking off at any effort (400): it gets no `thinking` and is
//!   controlled by effort alone.
//! - [Prompt caching] `cache_control: {"type": "ephemeral"}` on a block caches the prefix up to it; at most 4
//!   breakpoints; the prefix must be byte-identical to hit. The relay places explicit breakpoints only, never the
//!   top-level automatic one.
//! - [Prompt caching, pre-warming] `max_tokens: 0` writes the cache and returns at once with no output; it is an
//!   invalid request together with `stream: true`, so the warm-up does not stream. It sends the same `thinking` and
//!   effort as real requests, or it warms an entry they never read.
//! - [Streaming messages] text arrives in `content_block_delta` / `text_delta`; thinking blocks are never shown; an
//!   `error` event can arrive mid-stream.
//! - [Handling stop reasons] `stop_reason: "refusal"` (Sonnet 5.5 and Opus 5.5 run safety classifiers) arrives in
//!   `message_delta` at the end of the stream. It is a failed attempt: the session falls back, and a refused answer is
//!   never kept, reported or rewritten.
//!
//! The context's system segments go in `system`, the rest in one user message, block by block, with `cache_control`
//! after each segment the context marks (rules, memory, the last full transcript block: at most 3 breakpoints).

use std::sync::Arc;

use futures_util::future::BoxFuture;
use futures_util::{FutureExt, StreamExt};
use serde::Deserialize;
use serde_json::{Map, Value, json};

use super::http::{self, Decoded, Decoder, Retry, SseEvent};
use super::{CompletionRequest, Provider, ProviderError, StreamEvent, TokenStream, Usage};
use crate::context::{Context, Role};
use crate::domain::ProviderKind;
use crate::internal::ProviderKeys;

pub const BASE_URL: &str = "https://api.anthropic.com";
pub const API_VERSION: &str = "2026-10-21";
/// The user turn of a warm-up after the cached prefix (nothing is generated).
const WARM_PLACEHOLDER: &str = "(warm-up: no answer needed)";

#[derive(Debug, Clone)]
pub struct Anthropic {
    base: String,
    keys: Arc<ProviderKeys>,
    http: reqwest::Client,
    retry: Retry,
    tap: Option<http::Tap>,
}

impl Anthropic {
    pub fn new(base: &str, keys: Arc<ProviderKeys>, http: reqwest::Client, retry: Retry) -> Self {
        Anthropic { base: base.trim_end_matches('/').to_owned(), keys, http, retry, tap: None }
    }

    /// Copies every response body into `tap` (the smoke binary's `--record`).
    pub fn with_tap(mut self, tap: http::Tap) -> Self {
        self.tap = Some(tap);
        self
    }

    fn post(&self, key: &crate::config::Secret, body: &Value) -> reqwest::RequestBuilder {
        self.http
            .post(format!("{}/v1/messages", self.base))
            .header("x-api-key", String::from_utf8_lossy(key.expose()).into_owned())
            .header("anthropic-version", API_VERSION)
            .header(reqwest::header::CONTENT_TYPE, "application/json")
            .body(body.to_string())
    }
}

fn text_block(text: &str, cache: bool) -> Value {
    let mut block = json!({"type": "text", "text": text});
    if cache {
        block["cache_control"] = json!({"type": "ephemeral"});
    }
    block
}

/// `system` and the user message's blocks, from `segments`. Empty segments are left out (the API refuses an empty
/// text block); a breakpoint on one moves to the block before it.
fn blocks(context: &Context, upto: usize) -> (Vec<Value>, Vec<Value>) {
    let (mut system, mut user): (Vec<Value>, Vec<Value>) = (Vec::new(), Vec::new());
    for segment in context.segments.iter().take(upto) {
        let list = if segment.role == Role::System { &mut system } else { &mut user };
        if segment.text.is_empty() {
            if segment.cache_after
                && let Some(last) = list.last_mut()
            {
                last["cache_control"] = json!({"type": "ephemeral"});
            }
            continue;
        }
        list.push(text_block(&segment.text, segment.cache_after));
    }
    (system, user)
}

/// The request body. `stream: false` and `max_tokens: 0` make the warm-up.
pub fn body(request: &CompletionRequest, warm: bool) -> Value {
    let upto = if warm {
        request.context.segments.iter().rposition(|s| s.cache_after).map_or(0, |i| i + 1)
    } else {
        request.context.segments.len()
    };
    let (system, mut user) = blocks(&request.context, upto);
    if user.is_empty() || warm {
        user.push(text_block(WARM_PLACEHOLDER, false));
    }
    let mut body = Map::new();
    body.insert("model".into(), json!(request.model));
    // [Prompt caching, pre-warming] max_tokens 0 only without stream.
    body.insert("max_tokens".into(), json!(if warm { 0 } else { request.max_output_tokens }));
    if !warm {
        body.insert("stream".into(), json!(true));
    }
    if !system.is_empty() {
        body.insert("system".into(), Value::Array(system));
    }
    body.insert("messages".into(), json!([{"role": "user", "content": user}]));
    // The same options for the warm-up and the real request, so both hit one cache entry.
    for (name, value) in &request.options {
        match (name.as_str(), value) {
            // [Versioning] 2026-10-21: sampling parameters are a 400 on every model.
            ("temperature" | "top_p" | "top_k", _) => {}
            // [Effort] GA, no beta header; inside output_config, not top-level.
            ("effort", value) => {
                body.insert("output_config".into(), json!({"effort": http::json_of(value)}));
            }
            // [Extended thinking] "disabled" (Haiku 5.5, effort <= high) or "between_tools" (Sonnet 5.5, effort <= high,
            // no other field); Opus 5.5 has no thinking option in the settings.
            ("thinking", toml::Value::String(kind)) => {
                body.insert("thinking".into(), json!({"type": kind}));
            }
            (name, value) => {
                body.insert(name.to_owned(), http::json_of(value));
            }
        }
    }
    Value::Object(body)
}

#[derive(Debug, Deserialize, Default, Clone, Copy)]
struct ApiUsage {
    #[serde(default)]
    input_tokens: Option<u32>,
    #[serde(default)]
    cache_creation_input_tokens: Option<u32>,
    #[serde(default)]
    cache_read_input_tokens: Option<u32>,
    #[serde(default)]
    output_tokens: Option<u32>,
}

impl ApiUsage {
    /// Fields a later event sends replace the earlier ones (`message_delta` carries the final output count).
    fn merge(&mut self, later: ApiUsage) {
        self.input_tokens = later.input_tokens.or(self.input_tokens);
        self.cache_creation_input_tokens = later.cache_creation_input_tokens.or(self.cache_creation_input_tokens);
        self.cache_read_input_tokens = later.cache_read_input_tokens.or(self.cache_read_input_tokens);
        self.output_tokens = later.output_tokens.or(self.output_tokens);
    }

    fn usage(self) -> Usage {
        Usage {
            input_tokens: self.input_tokens.unwrap_or(0),
            cached_input_tokens: self.cache_read_input_tokens.unwrap_or(0),
            cache_write_tokens: self.cache_creation_input_tokens.unwrap_or(0),
            output_tokens: self.output_tokens.unwrap_or(0),
        }
    }
}

#[derive(Deserialize)]
#[serde(tag = "type", rename_all = "snake_case")]
enum ApiEvent {
    MessageStart { message: MessageStart },
    ContentBlockDelta { delta: Delta },
    MessageDelta {
        #[serde(default)]
        delta: MessageDeltaBody,
        #[serde(default)]
        usage: Option<ApiUsage>,
    },
    MessageStop,
    Error { error: ApiError },
    #[serde(other)]
    Other,
}

#[derive(Deserialize)]
struct MessageStart {
    #[serde(default)]
    usage: Option<ApiUsage>,
}

#[derive(Deserialize)]
#[serde(tag = "type", rename_all = "snake_case")]
enum Delta {
    TextDelta { text: String },
    #[serde(other)]
    Other,
}

#[derive(Deserialize, Default)]
struct MessageDeltaBody {
    #[serde(default)]
    stop_reason: Option<String>,
}

#[derive(Deserialize)]
struct ApiError {
    #[serde(rename = "type", default)]
    kind: String,
}

fn error_of(kind: &str) -> ProviderError {
    match kind {
        "rate_limit_error" => ProviderError::RateLimited,
        "overloaded_error" | "api_error" => ProviderError::Unavailable,
        "authentication_error" | "permission_error" => ProviderError::NotConfigured(ProviderKind::Anthropic),
        _ => ProviderError::Protocol,
    }
}

#[derive(Debug, Default)]
pub struct AnthropicDecoder {
    usage: ApiUsage,
    refused: bool,
}

impl Decoder for AnthropicDecoder {
    fn decode(&mut self, event: SseEvent) -> Decoded {
        let mut out = Decoded::default();
        let Ok(parsed) = serde_json::from_str::<ApiEvent>(&event.data) else {
            // `ping` and anything new without a body we know: skip it.
            return out;
        };
        match parsed {
            ApiEvent::MessageStart { message } => self.usage.merge(message.usage.unwrap_or_default()),
            ApiEvent::ContentBlockDelta { delta: Delta::TextDelta { text } } => out.text(&text),
            ApiEvent::ContentBlockDelta { delta: Delta::Other } | ApiEvent::Other => {}
            ApiEvent::MessageDelta { delta, usage } => {
                self.usage.merge(usage.unwrap_or_default());
                self.refused |= delta.stop_reason.as_deref() == Some("refusal");
            }
            ApiEvent::MessageStop => {
                if self.refused {
                    out.fail(ProviderError::Declined);
                } else {
                    out.items.push(Ok(StreamEvent::Usage(self.usage.usage())));
                }
                out.finished = true;
            }
            ApiEvent::Error { error } => out.fail(error_of(&error.kind)),
        }
        out
    }
}

#[derive(Deserialize)]
struct WarmAnswer {
    #[serde(default)]
    usage: ApiUsage,
}

impl Provider for Anthropic {
    fn kind(&self) -> ProviderKind {
        ProviderKind::Anthropic
    }

    fn stream(&self, request: CompletionRequest) -> TokenStream {
        let Some(key) = self.keys.get(ProviderKind::Anthropic) else {
            return futures_util::stream::once(async { Err(ProviderError::NotConfigured(ProviderKind::Anthropic)) }).boxed();
        };
        let body = body(&request, false);
        let this = self.clone();
        let tap = self.tap.clone();
        http::sse_stream(ProviderKind::Anthropic, self.retry, move || this.post(&key, &body), AnthropicDecoder::default(), tap)
    }

    fn warm(&self, request: CompletionRequest) -> BoxFuture<'static, Result<Usage, ProviderError>> {
        let Some(key) = self.keys.get(ProviderKind::Anthropic) else {
            return async { Err(ProviderError::NotConfigured(ProviderKind::Anthropic)) }.boxed();
        };
        let body = body(&request, true);
        let this = self.clone();
        async move {
            let response = http::send(ProviderKind::Anthropic, this.retry, || this.post(&key, &body)).await?;
            let bytes = response.bytes().await.map_err(|_| ProviderError::Unavailable)?;
            let answer: WarmAnswer = serde_json::from_slice(&bytes).map_err(|_| ProviderError::Protocol)?;
            Ok(answer.usage.usage())
        }
        .boxed()
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::config::RelayConfig;
    use crate::context::{CallContext, ContextBuilder, Task, Transcript};
    use crate::pack::Pack;
    use crate::provider::Purpose;

    fn request(model: &str) -> CompletionRequest {
        let config = RelayConfig::built_in();
        let pack = Pack::built_in();
        let transcript = Transcript::new(&config.context);
        let context = ContextBuilder { pack: &pack, config: &config.context }.build(
            &CallContext::default(),
            &transcript,
            Task::Answer { turn_id: Some("o-1"), text: "What is your notice period?", partial: false },
            crate::config::TranscriptScope::Full,
        );
        CompletionRequest {
            model: model.into(),
            context: Arc::new(context),
            max_output_tokens: 300,
            options: config.model(model).unwrap().options.clone(),
            purpose: Purpose::Answer,
            question: "What is your notice period?".into(),
        }
    }

    #[test]
    fn the_request_caches_the_fixed_prefix_and_turns_thinking_off_where_the_model_allows() {
        let haiku = body(&request("claude-haiku-5-5"), false);
        assert_eq!(haiku["stream"], true);
        assert_eq!(haiku["max_tokens"], 300);
        assert_eq!(haiku["thinking"], json!({"type": "disabled"}));
        assert_eq!(haiku["output_config"], json!({"effort": "low"}));
        assert!(haiku.get("temperature").is_none());
        let system = haiku["system"].as_array().unwrap();
        assert_eq!(system[0]["cache_control"], json!({"type": "ephemeral"}), "the rules are cached");
        let user = haiku["messages"][0]["content"].as_array().unwrap();
        assert!(user.last().unwrap()["text"].as_str().unwrap().contains("notice period"));
        let breakpoints = system.iter().chain(user).filter(|b| b.get("cache_control").is_some()).count();
        assert!((1..=4).contains(&breakpoints), "{breakpoints} breakpoints");

        assert_eq!(body(&request("claude-sonnet-5-5"), false)["thinking"], json!({"type": "between_tools"}));
        let opus = body(&request("claude-opus-5-5"), false);
        assert!(opus.get("thinking").is_none(), "Opus 5.5 can't turn thinking off");
        assert_eq!(opus["output_config"], json!({"effort": "low"}));
    }

    #[test]
    fn the_warm_up_sends_only_the_cached_prefix_and_asks_for_nothing() {
        let request = request("claude-haiku-5-5");
        let warm = body(&request, true);
        let full = body(&request, false);
        assert_eq!(warm["max_tokens"], 0);
        assert!(warm.get("stream").is_none());
        assert_eq!(warm["system"], full["system"], "the same bytes up to the breakpoint");
        let user = warm["messages"][0]["content"].as_array().unwrap();
        assert_eq!(user.last().unwrap()["text"], WARM_PLACEHOLDER);
    }

    #[test]
    fn no_request_carries_sampling_parameters_and_the_warm_up_matches_real_traffic() {
        for model in ["claude-haiku-5-5", "claude-sonnet-5-5", "claude-opus-5-5"] {
            let mut request = request(model);
            // Even settings that ask for them.
            request.options.insert("temperature".into(), toml::Value::Float(0.2));
            request.options.insert("top_p".into(), toml::Value::Float(0.9));
            request.options.insert("top_k".into(), toml::Value::Integer(40));
            let full = body(&request, false);
            let warm = body(&request, true);
            for (name, sent) in [("request", &full), ("warm-up", &warm)] {
                for key in ["temperature", "top_p", "top_k"] {
                    assert!(sent.get(key).is_none(), "{model} {name} sends {key}");
                }
                assert!(sent.get("cache_control").is_none(), "{model} {name}: explicit breakpoints only");
                assert_eq!(sent["output_config"], json!({"effort": "low"}), "{model} {name}: effort set explicitly");
            }
            assert!(warm.get("stream").is_none(), "{model}: max_tokens 0 is invalid with stream");
            assert_eq!(warm["max_tokens"], 0);
            assert_eq!(warm.get("thinking"), full.get("thinking"), "{model}: the warm-up sends the same thinking");
            // The cached prefix is byte-identical: the system blocks, and the last block of the warm-up's shared part
            // carries the breakpoint.
            assert_eq!(warm["system"], full["system"]);
            let marked: Vec<&Value> = warm["system"].as_array().unwrap().iter().filter(|b| b.get("cache_control").is_some()).collect();
            assert!(!marked.is_empty());
            assert_eq!(warm["system"].as_array().unwrap().last().unwrap()["cache_control"], json!({"type": "ephemeral"}));
        }
        let sonnet = body(&request("claude-sonnet-5-5"), false);
        assert_eq!(sonnet["thinking"], json!({"type": "between_tools"}), "no other field with between_tools");
    }

    #[test]
    fn events_become_text_usage_and_errors() {
        let mut decoder = AnthropicDecoder::default();
        let event = |data: &str| SseEvent { event: None, data: data.into() };
        let start = decoder.decode(event(
            r#"{"type":"message_start","message":{"usage":{"input_tokens":40,"cache_read_input_tokens":2000,"cache_creation_input_tokens":0,"output_tokens":1}}}"#,
        ));
        assert!(start.items.is_empty());
        let text = decoder.decode(event(r#"{"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Yes"}}"#));
        assert_eq!(text.items, vec![Ok(StreamEvent::Text("Yes".into()))]);
        let thinking = decoder.decode(event(r#"{"type":"content_block_delta","index":0,"delta":{"type":"thinking_delta","thinking":""}}"#));
        assert!(thinking.items.is_empty());
        decoder.decode(event(r#"{"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":12}}"#));
        let stop = decoder.decode(event(r#"{"type":"message_stop"}"#));
        assert!(stop.finished);
        assert_eq!(
            stop.items,
            vec![Ok(StreamEvent::Usage(Usage { input_tokens: 40, cached_input_tokens: 2000, cache_write_tokens: 0, output_tokens: 12 }))]
        );

        let mut refused = AnthropicDecoder::default();
        refused.decode(event(r#"{"type":"message_delta","delta":{"stop_reason":"refusal"},"usage":{"output_tokens":0}}"#));
        assert_eq!(refused.decode(event(r#"{"type":"message_stop"}"#)).items, vec![Err(ProviderError::Declined)]);
        let overloaded = AnthropicDecoder::default().decode(event(r#"{"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}"#));
        assert_eq!(overloaded.items, vec![Err(ProviderError::Unavailable)]);
    }
}
