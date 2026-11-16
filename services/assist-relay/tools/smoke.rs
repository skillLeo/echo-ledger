//! Live smoke test for one real model: one suggestion through the relay's own provider client, with the time to the
//! first token, the text, the token counts and the cost. Opt-in: it calls a paid API, so it sends nothing without
//! `--yes`. It is never run by the tests or by CI.
//!
//! The key is read from the environment at run time only, and only for the model's provider:
//! `ANTHROPIC_API_KEY`, `GROQ_API_KEY`, `OPENAI_API_KEY` or `GEMINI_API_KEY`. It is never printed or written. Set it
//! for one command and don't put it in a file in the repo:
//!
//!   cargo build --release
//!   read -rs ANTHROPIC_API_KEY && export ANTHROPIC_API_KEY
//!   target/release/assist-relay-smoke --model claude-haiku-5-5 --yes
//!   target/release/assist-relay-smoke --model openai/gpt-oss-120b --question "When can you start?" --yes
//!   target/release/assist-relay-smoke --model claude-haiku-5-5 --yes --record tests/fixtures/sse/anthropic_answer.sse
//!
//! `--record PATH` saves the provider's raw streamed answer (no request, no headers, so no key) as an SSE file, to
//! replace a hand-written test fixture with a recording. Read it before committing: it holds the model's text.
//! `--warm` sends the cache warm-up first and asks twice, to show the cached second request.

use std::collections::BTreeMap;
use std::process::ExitCode;
use std::sync::{Arc, Mutex};
use std::time::Duration;

use assist_relay::budget::cost_micros;
use assist_relay::config::{RelayConfig, TranscriptScope};
use assist_relay::context::{CallContext, ContextBuilder, Task, Transcript};
use assist_relay::domain::{MeetingType, ProviderKind};
use assist_relay::internal::ProviderKeys;
use assist_relay::pack::Pack;
use assist_relay::protocol::Brief;
use assist_relay::provider::{
    Anthropic, CompletionRequest, Endpoints, Gemini, OpenAiCompatible, Provider, Purpose, Retry, StreamEvent, Usage, http,
};
use futures_util::StreamExt;
use tokio::time::Instant;

struct Options {
    model: String,
    question: String,
    record: Option<String>,
    warm: bool,
    yes: bool,
}

fn options() -> Result<Options, String> {
    let mut o = Options {
        model: "claude-haiku-5-5".into(),
        question: "What is your notice period?".into(),
        record: None,
        warm: false,
        yes: false,
    };
    let mut args = std::env::args().skip(1);
    while let Some(flag) = args.next() {
        let mut value = || args.next().ok_or(format!("{flag} needs a value"));
        match flag.as_str() {
            "--model" => o.model = value()?,
            "--question" => o.question = value()?,
            "--record" => o.record = Some(value()?),
            "--warm" => o.warm = true,
            "--yes" => o.yes = true,
            _ => return Err(format!("unknown option {flag}")),
        }
    }
    Ok(o)
}

fn key_variable(kind: ProviderKind) -> &'static str {
    match kind {
        ProviderKind::Anthropic => "ANTHROPIC_API_KEY",
        ProviderKind::Groq => "GROQ_API_KEY",
        ProviderKind::Openai => "OPENAI_API_KEY",
        ProviderKind::Gemini => "GEMINI_API_KEY",
    }
}

fn main() -> ExitCode {
    let o = match options() {
        Ok(o) => o,
        Err(error) => {
            eprintln!("assist-relay-smoke: {error}");
            return ExitCode::from(2);
        }
    };
    let config = RelayConfig::built_in();
    let Some(spec) = config.model(&o.model).cloned() else {
        eprintln!("assist-relay-smoke: {} is not in config/relay.toml", o.model);
        return ExitCode::from(2);
    };
    let variable = key_variable(spec.provider);
    println!("Model {} at {} ({} from the environment).", o.model, spec.provider.label(), variable);
    if !o.yes {
        println!("Nothing sent: this calls a paid API (a fraction of a cent per request). Add --yes to send it.");
        return ExitCode::from(2);
    }
    let Some(key) = std::env::var(variable).ok().filter(|k| !k.trim().is_empty()) else {
        eprintln!("assist-relay-smoke: {variable} is not set");
        return ExitCode::from(2);
    };
    let runtime = match tokio::runtime::Builder::new_multi_thread().enable_all().build() {
        Ok(runtime) => runtime,
        Err(error) => {
            eprintln!("assist-relay-smoke: {error}");
            return ExitCode::FAILURE;
        }
    };
    runtime.block_on(run(o, config, spec, key))
}

async fn run(o: Options, config: RelayConfig, spec: assist_relay::config::ModelSpec, key: String) -> ExitCode {
    let keys = Arc::new(ProviderKeys::default());
    keys.replace(BTreeMap::from([(spec.provider, key)]));
    let tap: http::Tap = Arc::new(Mutex::new(Vec::new()));
    let endpoints = Endpoints::default();
    let client = http::client();
    let provider: Arc<dyn Provider> = match spec.provider {
        ProviderKind::Anthropic => {
            Arc::new(Anthropic::new(&endpoints.anthropic, keys, client, Retry::default()).with_tap(tap.clone()))
        }
        ProviderKind::Groq => Arc::new(OpenAiCompatible::new(ProviderKind::Groq, &endpoints.groq, keys, client, Retry::default()).with_tap(tap.clone())),
        ProviderKind::Openai => {
            Arc::new(OpenAiCompatible::new(ProviderKind::Openai, &endpoints.openai, keys, client, Retry::default()).with_tap(tap.clone()))
        }
        ProviderKind::Gemini => Arc::new(Gemini::new(&endpoints.gemini, keys, client, Retry::default()).with_tap(tap.clone())),
    };

    // A small interview context, the same shape the relay sends.
    let pack = Pack::built_in();
    let call = CallContext {
        brief: Brief {
            meeting_type: MeetingType::Interview,
            title: "Backend developer".into(),
            goals: "Show the payments work".into(),
            to_discuss: "Notice period, start date".into(),
            ..Brief::default()
        },
        ..CallContext::default()
    };
    let transcript = Transcript::new(&config.context);
    let builder = ContextBuilder { pack: &pack, config: &config.context };
    let context = Arc::new(builder.build(
        &call,
        &transcript,
        Task::Answer { turn_id: Some("o-1"), text: &o.question, partial: false },
        TranscriptScope::Full,
    ));
    let max_output_tokens = config.modes.fast.max_output_tokens;
    let request = |purpose: Purpose| CompletionRequest {
        model: o.model.clone(),
        context: context.clone(),
        max_output_tokens,
        options: spec.options.clone(),
        purpose,
        question: o.question.clone(),
    };

    if o.warm {
        let started = Instant::now();
        match provider.warm(request(Purpose::Warm)).await {
            Ok(usage) => println!("Warm-up: {} ms, {}", started.elapsed().as_millis(), counts(&usage)),
            Err(error) => println!("Warm-up failed: {error}"),
        }
    }
    let mut failed = false;
    for round in 1..=(if o.warm { 2 } else { 1 }) {
        let started = Instant::now();
        let mut first: Option<Duration> = None;
        let (mut text, mut usage) = (String::new(), None);
        let mut stream = provider.stream(request(Purpose::Answer));
        while let Some(item) = stream.next().await {
            match item {
                Ok(StreamEvent::Text(piece)) => {
                    first.get_or_insert(started.elapsed());
                    text.push_str(&piece);
                }
                Ok(StreamEvent::Usage(counts)) => usage = Some(counts),
                Err(error) => {
                    println!("Request {round} failed: {error} ({})", error.code());
                    failed = true;
                    break;
                }
            }
        }
        println!(
            "\nRequest {round}: first token {} ms, done {} ms",
            first.map_or("-".into(), |d| d.as_millis().to_string()),
            started.elapsed().as_millis()
        );
        println!("{text}");
        if let Some(usage) = usage {
            println!("{}; cost about ${:.6}", counts(&usage), cost_micros(&spec, &usage) as f64 / 1_000_000.0);
        }
    }
    if let Some(path) = &o.record {
        let raw = tap.lock().map(|bytes| bytes.clone()).unwrap_or_default();
        let note = format!(": Recorded with assist-relay-smoke from {} on {}.\n\n", o.model, assist_relay::time::iso8601(assist_relay::time::unix_now()));
        match std::fs::write(path, [note.as_bytes(), &raw].concat()) {
            Ok(()) => println!("\nRaw stream saved to {path} ({} bytes). Read it before committing.", raw.len()),
            Err(error) => println!("\nCould not save {path}: {error}"),
        }
    }
    if failed { ExitCode::FAILURE } else { ExitCode::SUCCESS }
}

fn counts(usage: &Usage) -> String {
    format!(
        "input {} (cached {}, written {}), output {}",
        usage.input_tokens, usage.cached_input_tokens, usage.cache_write_tokens, usage.output_tokens
    )
}
