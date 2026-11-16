//! The live provider clients against a local server that replays SSE fixtures (written from the providers'
//! documented formats, not recordings): the request each client sends, tokens and usage, stop reasons, busy answers
//! and their retries, and a connection dropped mid-answer falling back to the next provider within the latency
//! budget. Nothing leaves 127.0.0.1, and the keys are made-up strings.

mod common;

use std::collections::BTreeMap;
use std::sync::Arc;
use std::time::Duration;

use assist_relay::config::{RelayConfig, TranscriptScope};
use assist_relay::context::{CallContext, ContextBuilder, Task, Transcript};
use assist_relay::domain::{Mode, ProviderKind};
use assist_relay::internal::ProviderKeys;
use assist_relay::pack::Pack;
use assist_relay::provider::{CompletionRequest, ProviderError, Providers, Purpose, Retry, StreamEvent, Usage};
use common::mock_http::{ANTHROPIC, GEMINI_FLASH, GROQ, MockHttp, OPENAI, Reply};
use common::*;
use futures_util::StreamExt;
use serde_json::json;
use tokio::time::Instant;

const QUESTION: &str = "What is your notice period?";

fn keys() -> Arc<ProviderKeys> {
    let keys = Arc::new(ProviderKeys::default());
    keys.replace(BTreeMap::from([
        (ProviderKind::Anthropic, "test-anthropic-key".to_owned()),
        (ProviderKind::Groq, "test-groq-key".to_owned()),
        (ProviderKind::Openai, "test-openai-key".to_owned()),
        (ProviderKind::Gemini, "test-gemini-key".to_owned()),
    ]));
    keys
}

fn live(server: &MockHttp) -> Providers {
    Providers::live(keys(), &server.endpoints(), Retry::default())
}

fn request(model: &str) -> CompletionRequest {
    let config = RelayConfig::built_in();
    let pack = Pack::built_in();
    let transcript = Transcript::new(&config.context);
    let context = ContextBuilder { pack: &pack, config: &config.context }.build(
        &CallContext::default(),
        &transcript,
        Task::Answer { turn_id: Some("o-1"), text: QUESTION, partial: false },
        TranscriptScope::Full,
    );
    CompletionRequest {
        model: model.into(),
        context: Arc::new(context),
        max_output_tokens: 300,
        options: config.model(model).unwrap().options.clone(),
        purpose: Purpose::Answer,
        question: QUESTION.into(),
    }
}

/// Everything a stream sends: the text, the usage, and the error that ended it.
async fn collect(providers: &Providers, kind: ProviderKind, model: &str) -> (String, Option<Usage>, Option<ProviderError>) {
    let mut stream = providers.get(kind).stream(request(model));
    let (mut text, mut usage, mut error) = (String::new(), None, None);
    while let Some(item) = stream.next().await {
        match item {
            Ok(StreamEvent::Text(piece)) => text.push_str(&piece),
            Ok(StreamEvent::Usage(counts)) => usage = Some(counts),
            Err(e) => error = Some(e),
        }
    }
    (text, usage, error)
}

#[tokio::test]
async fn anthropic_streams_text_and_usage_from_a_cached_request() {
    let server = MockHttp::start().await;
    server.push(ANTHROPIC, Reply::sse("anthropic_answer.sse"));
    let (text, usage, error) = collect(&live(&server), ProviderKind::Anthropic, "claude-haiku-5-5").await;
    assert_eq!(error, None);
    assert_eq!(text, "One month, and I can start in March.\n- **Notice**: one month");
    assert_eq!(usage, Some(Usage { input_tokens: 42, cached_input_tokens: 1830, cache_write_tokens: 0, output_tokens: 17 }));

    let sent = &server.requests(ANTHROPIC)[0];
    assert_eq!(sent.headers["x-api-key"], "test-anthropic-key");
    assert_eq!(sent.headers["anthropic-version"], "2026-10-21");
    assert_eq!(sent.body["model"], "claude-haiku-5-5");
    assert_eq!(sent.body["stream"], true);
    assert_eq!(sent.body["thinking"], json!({"type": "disabled"}));
    assert_eq!(sent.body["output_config"], json!({"effort": "low"}));
    assert_eq!(sent.body["system"][0]["cache_control"], json!({"type": "ephemeral"}));
    let user = sent.body["messages"][0]["content"].as_array().unwrap();
    assert!(user.last().unwrap()["text"].as_str().unwrap().contains(QUESTION));
}

#[tokio::test]
async fn anthropic_stop_reasons_max_tokens_ends_normally_and_refusal_is_declined() {
    let server = MockHttp::start().await;
    server.push(ANTHROPIC, Reply::sse("anthropic_max_tokens.sse"));
    server.push(ANTHROPIC, Reply::sse("anthropic_refusal.sse"));
    server.push(ANTHROPIC, Reply::sse("anthropic_overloaded.sse"));
    let providers = live(&server);
    let (text, usage, error) = collect(&providers, ProviderKind::Anthropic, "claude-haiku-5-5").await;
    assert_eq!((text.as_str(), error), ("One month, and", None));
    assert_eq!(usage.map(|u| (u.cache_write_tokens, u.output_tokens)), Some((1830, 4)));
    let (text, usage, error) = collect(&providers, ProviderKind::Anthropic, "claude-haiku-5-5").await;
    assert_eq!((text.as_str(), usage, error), ("", None, Some(ProviderError::Declined)));
    let (_, _, error) = collect(&providers, ProviderKind::Anthropic, "claude-haiku-5-5").await;
    assert_eq!(error, Some(ProviderError::Unavailable), "an error event mid-stream");
}

#[tokio::test]
async fn busy_answers_are_retried_quickly_or_handed_back_for_the_fallback() {
    let server = MockHttp::start().await;
    let providers = live(&server);

    // 529, then an answer: one retry after 100 ms.
    server.push(ANTHROPIC, Reply::Status { code: 529, retry_after: None });
    server.push(ANTHROPIC, Reply::sse("anthropic_answer.sse"));
    let started = Instant::now();
    let (text, _, error) = collect(&providers, ProviderKind::Anthropic, "claude-haiku-5-5").await;
    assert_eq!(error, None);
    assert!(text.starts_with("One month"));
    let sent = server.requests(ANTHROPIC);
    assert_eq!(sent.len(), 2);
    assert!(sent[1].at - sent[0].at >= Duration::from_millis(100));
    assert!(started.elapsed() < Duration::from_millis(800), "{:?}", started.elapsed());

    // 429 asking for 300 ms: waited for, it fits.
    server.push(GROQ, Reply::Status { code: 429, retry_after: Some("0.3") });
    server.push(GROQ, Reply::sse("groq_answer.sse"));
    let (text, _, error) = collect(&providers, ProviderKind::Groq, "openai/gpt-oss-120b").await;
    assert_eq!((text.as_str(), error), ("One month, from Groq.", None));
    let sent = server.requests(GROQ);
    assert!(sent[1].at - sent[0].at >= Duration::from_millis(300));

    // 429 asking for 30 s: no wait at all, the session falls back instead.
    server.push(GROQ, Reply::Status { code: 429, retry_after: Some("30") });
    let started = Instant::now();
    let (_, _, error) = collect(&providers, ProviderKind::Groq, "openai/gpt-oss-120b").await;
    assert_eq!(error, Some(ProviderError::RateLimited));
    assert!(started.elapsed() < Duration::from_millis(100));
    assert_eq!(server.requests(GROQ).len(), 3);

    // Three busy answers: two retries, then the error.
    for _ in 0..3 {
        server.push(ANTHROPIC, Reply::Status { code: 529, retry_after: None });
    }
    let (_, _, error) = collect(&providers, ProviderKind::Anthropic, "claude-haiku-5-5").await;
    assert_eq!(error, Some(ProviderError::Unavailable));
    assert_eq!(server.requests(ANTHROPIC).len(), 5);
}

#[tokio::test]
async fn groq_streams_text_and_usage_with_its_options() {
    let server = MockHttp::start().await;
    server.push(GROQ, Reply::sse("groq_answer.sse"));
    let (text, usage, error) = collect(&live(&server), ProviderKind::Groq, "openai/gpt-oss-120b").await;
    assert_eq!(error, None);
    assert_eq!(text, "One month, from Groq.");
    assert_eq!(usage, Some(Usage { input_tokens: 364, cached_input_tokens: 1536, cache_write_tokens: 0, output_tokens: 12 }));
    let sent = &server.requests(GROQ)[0];
    assert_eq!(sent.headers["authorization"], "Bearer test-groq-key");
    assert_eq!(sent.body["reasoning_effort"], "low");
    assert_eq!(sent.body["stream_options"], json!({"include_usage": true}));
    assert_eq!(sent.body["max_completion_tokens"], 300);
    assert_eq!(sent.body["messages"][0]["role"], "system");
    assert!(sent.body["messages"][1]["content"].as_str().unwrap().contains(QUESTION));
}

#[tokio::test]
async fn a_provider_without_a_key_is_not_configured_and_sends_nothing() {
    let server = MockHttp::start().await;
    let providers = Providers::live(Arc::new(ProviderKeys::default()), &server.endpoints(), Retry::default());
    let (_, _, error) = collect(&providers, ProviderKind::Anthropic, "claude-haiku-5-5").await;
    assert_eq!(error, Some(ProviderError::NotConfigured(ProviderKind::Anthropic)));
    assert!(server.requests(ANTHROPIC).is_empty());
}

fn fast_claims(session: &str) -> assist_relay::auth::Claims {
    let mut fast = claims(session);
    fast.modes = vec![Mode::Fast];
    fast
}

/// A Fast session on the live clients: Haiku at Anthropic, falling back to gpt-oss at Groq.
async fn fast_session(server: &MockHttp, session: &str) -> Driver {
    let h = Box::leak(Box::new(harness_on(RelayConfig::built_in(), live(server))));
    let mut s = h.connect(fast_claims(session));
    assert_eq!(s.next().await["type"], "ready");
    s.send(json!({"type": "context_update", "settings": {"mode": "fast"}})).await;
    s.drain(Duration::from_millis(50)).await;
    s
}

#[tokio::test]
async fn a_connection_dropped_mid_answer_falls_back_to_groq_within_the_fast_target() {
    let server = MockHttp::start().await;
    // message_start, content_block_start, ping, one text delta: then the connection drops.
    server.push(ANTHROPIC, Reply::cut("anthropic_answer.sse", 4));
    server.push(GROQ, Reply::sse("groq_answer.sse"));
    let mut s = fast_session(&server, "s-drop").await;
    let asked = Instant::now();
    s.send(final_turn("o-1", "other", QUESTION)).await;

    let seen = s.until("suggestion_end").await;
    let broken = of_type(&seen, "suggestion_start")[0]["suggestion_id"].as_str().unwrap().to_owned();
    assert_eq!(text_of(&seen, &broken), "One month");
    assert_eq!(seen.last().unwrap()["status"], "superseded");
    let replacement = s.next().await;
    assert_eq!((replacement["replaces"].as_str(), replacement["model"].as_str()), (Some(broken.as_str()), Some("openai/gpt-oss-120b")));
    let id = replacement["suggestion_id"].as_str().unwrap().to_owned();
    let rest = s.until("suggestion_end").await;
    let replaced_at = asked.elapsed();
    assert_eq!(text_of(&rest, &id), "One month, from Groq.");
    assert_eq!(rest.last().unwrap()["status"], "done");
    assert!(replaced_at < Duration::from_millis(1500), "end of turn to the replacement's end: {replaced_at:?}");
}

#[tokio::test]
async fn a_refusal_or_a_drop_before_the_first_word_falls_back_inside_the_same_suggestion() {
    let server = MockHttp::start().await;
    server.push(ANTHROPIC, Reply::sse("anthropic_refusal.sse"));
    server.push(GROQ, Reply::sse("groq_answer.sse"));
    server.push(ANTHROPIC, Reply::cut("anthropic_answer.sse", 2));
    server.push(GROQ, Reply::sse("groq_answer.sse"));
    let mut s = fast_session(&server, "s-early").await;
    for turn in ["o-1", "o-2"] {
        let asked = Instant::now();
        s.send(final_turn(turn, "other", QUESTION)).await;
        let seen = s.until("suggestion_end").await;
        assert_eq!(of_type(&seen, "suggestion_start").len(), 1, "nothing was shown, so no replacement: {seen:?}");
        let end = seen.last().unwrap();
        assert_eq!((end["status"].as_str(), end["model"].as_str()), (Some("done"), Some("openai/gpt-oss-120b")));
        assert_eq!(text_of(&seen, end["suggestion_id"].as_str().unwrap()), "One month, from Groq.");
        assert!(asked.elapsed() < Duration::from_millis(1500), "{turn}: {:?}", asked.elapsed());
    }
}

#[tokio::test]
async fn without_keys_the_app_is_told_to_ask_its_admin_and_the_vendor_stays_in_the_log() {
    let server = MockHttp::start().await;
    let providers = Providers::live(Arc::new(ProviderKeys::default()), &server.endpoints(), Retry::default());
    let h = harness_on(RelayConfig::built_in(), providers);
    let mut s = h.connect(fast_claims("s-nokeys"));
    assert_eq!(s.next().await["type"], "ready");
    s.send(json!({"type": "context_update", "settings": {"mode": "fast"}})).await;
    s.send(final_turn("o-1", "other", QUESTION)).await;
    let seen = s.until("error").await;
    let error = seen.last().unwrap();
    assert_eq!(error["code"], "provider_not_configured");
    assert_eq!(error["message"], "Live Assist is not set up on the server yet. Ask your admin.");
    for vendor in ["Anthropic", "Groq", "OpenAI", "Gemini", "Claude", "gpt"] {
        assert!(!error.to_string().contains(vendor), "{error}");
    }
    let record = h.telemetry.suggestions().pop().expect("a record");
    assert_eq!((record.provider, record.error), (ProviderKind::Groq, Some("provider_not_configured")), "the fallback's provider, in the log");
    assert!(server.requests(ANTHROPIC).is_empty() && server.requests(GROQ).is_empty());
}

#[tokio::test]
async fn openai_and_gemini_stream_text_and_usage() {
    let server = MockHttp::start().await;
    server.push(OPENAI, Reply::sse("openai_answer.sse"));
    server.push(GEMINI_FLASH, Reply::sse("gemini_answer.sse"));
    let providers = live(&server);

    let (text, usage, error) = collect(&providers, ProviderKind::Openai, "gpt-6-luna").await;
    assert_eq!((text.as_str(), error), ("One month, from OpenAI.", None));
    assert_eq!(usage, Some(Usage { input_tokens: 108, cached_input_tokens: 1792, cache_write_tokens: 0, output_tokens: 6 }));
    assert_eq!(server.requests(OPENAI)[0].headers["authorization"], "Bearer test-openai-key");

    let (text, usage, error) = collect(&providers, ProviderKind::Gemini, "gemini-3.8-flash").await;
    assert_eq!((text.as_str(), error), ("One month, from Gemini.", None));
    assert_eq!(usage, Some(Usage { input_tokens: 876, cached_input_tokens: 1024, cache_write_tokens: 0, output_tokens: 6 }));
    let sent = &server.requests(GEMINI_FLASH)[0];
    assert_eq!(sent.headers["x-goog-api-key"], "test-gemini-key");
    assert_eq!(sent.body["generationConfig"]["thinkingConfig"], json!({"thinkingBudget": 0}));
    assert_eq!(sent.body["generationConfig"]["maxOutputTokens"], 300);
    assert!(sent.body["systemInstruction"]["parts"][0]["text"].as_str().unwrap().contains("Live Assist"));
    assert!(sent.body["contents"][0]["parts"][0]["text"].as_str().unwrap().contains(QUESTION));
}

#[tokio::test]
async fn gemini_dropped_before_its_finish_reason_is_a_broken_connection() {
    let server = MockHttp::start().await;
    server.push(GEMINI_FLASH, Reply::cut("gemini_answer.sse", 1));
    let (text, _, error) = collect(&live(&server), ProviderKind::Gemini, "gemini-3.8-flash").await;
    assert_eq!((text.as_str(), error), ("One month,", Some(ProviderError::Unavailable)));
}

#[tokio::test]
async fn a_refusal_after_streamed_words_is_never_shown_and_the_fallback_answers() {
    let server = MockHttp::start().await;
    // Deep: Sonnet (held until complete), falling back to Haiku.
    server.push(ANTHROPIC, Reply::sse("anthropic_refusal_midstream.sse"));
    server.push(ANTHROPIC, Reply::sse("anthropic_answer.sse"));
    let h = harness_on(RelayConfig::built_in(), live(&server));
    let mut s = h.connect(claims("s-refusal"));
    assert_eq!(s.next().await["type"], "ready");
    s.send(json!({"type": "context_update", "settings": {"mode": "deep"}})).await;
    s.drain(Duration::from_millis(50)).await;
    s.send(final_turn("o-1", "other", QUESTION)).await;
    let seen = s.until("suggestion_end").await;
    let all = seen.iter().map(|m| m.to_string()).collect::<String>();
    assert!(!all.contains("REFUSED-PARTIAL") && !all.contains("never show"), "refused words reached the app: {all}");
    assert_eq!(of_type(&seen, "suggestion_start").len(), 1, "one suggestion: nothing was shown, so no replacement");
    let end = seen.last().unwrap();
    assert_eq!((end["status"].as_str(), end["model"].as_str()), (Some("done"), Some("claude-haiku-5-5")));
    assert_eq!(text_of(&seen, end["suggestion_id"].as_str().unwrap()), "One month, and I can start in March.\n- **Notice**: one month");
    let sent = server.requests(ANTHROPIC);
    assert_eq!((sent[0].body["model"].as_str(), sent[1].body["model"].as_str()), (Some("claude-sonnet-5-5"), Some("claude-haiku-5-5")));
    // The refused answer is not reported as shown text.
    assert!(h.reports.reports().iter().all(|r| !format!("{r:?}").contains("REFUSED")));
}

#[tokio::test]
async fn a_streamed_models_refusal_after_shown_words_ends_that_suggestion_at_once() {
    let server = MockHttp::start().await;
    // Haiku (streamed) shows words, then refuses; events 400 ms apart, so message_stop comes 400 ms after the refusal.
    let gap = Duration::from_millis(400);
    server.push(ANTHROPIC, Reply::Sse { fixture: "anthropic_haiku_refusal_midstream.sse", first: Duration::from_millis(120), gap, cut_after: None });
    server.push(GROQ, Reply::sse("groq_answer.sse"));
    let mut s = fast_session(&server, "s-streamed-refusal").await;
    let asked = Instant::now();
    s.send(final_turn("o-1", "other", QUESTION)).await;

    let mut seen = Vec::new();
    let superseded_at = loop {
        let message = s.next().await;
        let done = message["type"] == "suggestion_end";
        seen.push(message);
        if done {
            break asked.elapsed();
        }
    };
    let first = seen[0]["suggestion_id"].as_str().unwrap().to_owned();
    assert!(text_of(&seen, &first).contains("REFUSED-PARTIAL"), "a streamed model shows its words: {seen:?}");
    let end = seen.last().unwrap();
    assert_eq!((end["suggestion_id"].as_str(), end["status"].as_str()), (Some(first.as_str()), Some("superseded")));
    // The refusal (message_delta) is the 6th event, at about 120 + 5 x 400 ms; message_stop would be 400 ms later.
    assert!(superseded_at < Duration::from_millis(120 + 5 * 400 + 300), "ended at the refusal, not at message_stop: {superseded_at:?}");

    // Then, and only then, the fallback; no refused word after the end.
    let rest = s.until("suggestion_end").await;
    let start = &rest[0];
    assert_eq!((start["type"].as_str(), start["replaces"].as_str()), (Some("suggestion_start"), Some(first.as_str())));
    assert_eq!(start["model"], "openai/gpt-oss-120b");
    assert!(rest.iter().all(|m| !m.to_string().contains("REFUSED")), "{rest:?}");
    assert_eq!(text_of(&rest, start["suggestion_id"].as_str().unwrap()), "One month, from Groq.");
}
