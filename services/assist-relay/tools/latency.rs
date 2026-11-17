//! Latency benchmark (plan L9): every model in the settings, through the relay's own provider clients, cold once and
//! then warm and cached; time to first token p50 / p95 and the share of input read from the cache, written to
//! docs/LATENCY_V1_9.md.
//!
//! Where it runs: on the relay's VPS, where the relay's model calls start. What a person in Pakistan sees adds the trip
//! from their network to the relay, measured separately from the owner's computer with
//! `curl -o /dev/null -s -w 'connect %{time_connect}s\n' https://<relay host>/healthz` (RELEASE.md step 5). The
//! "Against the targets" table compares the VPS numbers only: add that trip before reading a pass.
//!
//! How to run (on the VPS, from services/assist-relay):
//!   1. cargo build --release
//!   2. read -rs ANTHROPIC_API_KEY && export ANTHROPIC_API_KEY   (and GROQ_API_KEY, OPENAI_API_KEY, GEMINI_API_KEY the same way)
//!   3. target/release/assist-relay-latency --from "VPS, Frankfurt"          (lists the models; sends nothing)
//!   4. target/release/assist-relay-latency --from "VPS, Frankfurt" --yes    (about 12 requests per model, a few cents)
//!   5. unset the keys, read docs/LATENCY_V1_9.md, and commit it with the trip from Pakistan noted in it.
//!
//! Options: --rounds N (10 warm requests per model), --gap-ms N (1500 between requests), --models a,b (only these),
//! --out FILE (default docs/LATENCY_V1_9.md). A model whose provider key is not set is listed as skipped. Keys are read
//! from the environment at run time only and never printed or written.

use std::collections::BTreeMap;
use std::path::PathBuf;
use std::process::ExitCode;
use std::sync::Arc;
use std::time::Duration;

use assist_relay::config::{RelayConfig, TranscriptScope};
use assist_relay::context::{CallContext, ContextBuilder, Task, Transcript};
use assist_relay::domain::{MeetingType, Mode, ProviderKind};
use assist_relay::internal::ProviderKeys;
use assist_relay::pack::Pack;
use assist_relay::protocol::{Brief, Document};
use assist_relay::provider::{CompletionRequest, Endpoints, Providers, Purpose, Retry, StreamEvent, Usage};
use futures_util::StreamExt;
use tokio::time::Instant;

/// Questions an interviewer might ask; each warm request takes the next one, after the same cached prefix.
const QUESTIONS: [&str; 12] = [
    "What is your notice period?",
    "Tell me about the payouts system you built.",
    "Have you worked with Kotlin?",
    "How do you handle a payout that fails halfway?",
    "What salary are you looking for?",
    "Why do you want to leave your current job?",
    "How do you test a payment flow before release?",
    "What was the hardest bug you fixed last year?",
    "How do you work with product managers?",
    "When could you start?",
    "What would you improve in our checkout?",
    "Do you have any questions for me?",
];

/// A CV long enough that every provider caches the prefix (Anthropic's minimum is 512 tokens, the others' about 1,024).
fn cv() -> String {
    let mut text = String::from(
        "Hamza Qureshi. Backend engineer, 6 years. Laravel, PHP 8, Go, PostgreSQL, Redis.\n\
         2021-2025 Savari Rides, Karachi: built the driver payouts service, 40,000 payouts a day; cut reconciliation from 3 days to 4 hours.\n\
         2019-2021 Bazaarline, Karachi: order and returns API in Laravel.\nBS Computer Science, NED University, 2019. Notice period: one month.\n",
    );
    for (year, project) in [
        (2025, "Moved the payouts ledger to PostgreSQL partitions; month-end queries went from minutes to seconds."),
        (2024, "Added idempotency keys to every payout step and a reconciliation job that repairs half-done payouts."),
        (2023, "Built the driver wallet: top-ups, withdrawals, holds and a daily statement in Urdu and English."),
        (2022, "Wrote the bank file exports for three banks and the checks that catch a file before it is sent."),
        (2021, "Set up on-call, runbooks and alerts for the payments team; cut night pages by half."),
    ] {
        for detail in [
            "Worked with finance every week to agree the numbers.",
            "Wrote the design note, reviewed it with the team, and kept it up to date.",
            "Tested with production-like data before every release.",
        ] {
            text.push_str(&format!("{year}: {project} {detail}\n"));
        }
    }
    text.repeat(6)
}

fn key_variable(kind: ProviderKind) -> &'static str {
    match kind {
        ProviderKind::Anthropic => "ANTHROPIC_API_KEY",
        ProviderKind::Groq => "GROQ_API_KEY",
        ProviderKind::Openai => "OPENAI_API_KEY",
        ProviderKind::Gemini => "GEMINI_API_KEY",
    }
}

struct Options {
    from: String,
    rounds: usize,
    gap: Duration,
    models: Option<Vec<String>>,
    out: PathBuf,
    yes: bool,
}

fn options() -> Result<Options, String> {
    let mut o = Options {
        from: "not given".into(),
        rounds: 10,
        gap: Duration::from_millis(1500),
        models: None,
        out: PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("../../docs/LATENCY_V1_9.md"),
        yes: false,
    };
    let mut args = std::env::args().skip(1);
    while let Some(flag) = args.next() {
        let mut value = || args.next().ok_or(format!("{flag} needs a value"));
        let number = |v: String| v.parse::<u64>().map_err(|_| format!("{flag}: not a number"));
        match flag.as_str() {
            "--from" => o.from = value()?,
            "--rounds" => o.rounds = usize::try_from(number(value()?)?).map_err(|e| e.to_string())?.clamp(2, 50),
            "--gap-ms" => o.gap = Duration::from_millis(number(value()?)?),
            "--models" => o.models = Some(value()?.split(',').map(str::to_owned).collect()),
            "--out" => o.out = PathBuf::from(value()?),
            "--yes" => o.yes = true,
            _ => return Err(format!("unknown option {flag}")),
        }
    }
    Ok(o)
}

/// One request: time to the first word, the whole answer, and the counts.
struct Sample {
    ttft: Option<Duration>,
    total: Duration,
    usage: Option<Usage>,
    error: Option<String>,
}

#[derive(Default)]
struct ModelResult {
    model: String,
    provider: &'static str,
    modes: Vec<&'static str>,
    cold: Option<Sample>,
    warm: Vec<Sample>,
    skipped: Option<String>,
}

fn percentile(values: &mut [f64], p: f64) -> Option<f64> {
    if values.is_empty() {
        return None;
    }
    values.sort_by(f64::total_cmp);
    let rank = ((p / 100.0) * values.len() as f64).ceil() as usize;
    Some(values[rank.clamp(1, values.len()) - 1])
}

fn ms(value: Option<f64>) -> String {
    value.map_or("-".into(), |v| format!("{v:.0}"))
}

impl ModelResult {
    fn ttfts(&self) -> Vec<f64> {
        self.warm.iter().filter_map(|s| s.ttft).map(|d| d.as_secs_f64() * 1000.0).collect()
    }

    fn cache_share(&self) -> Option<f64> {
        let shares: Vec<f64> = self
            .warm
            .iter()
            .filter_map(|s| s.usage)
            .filter(|u| u.prompt_tokens() > 0)
            .map(|u| f64::from(u.cached_input_tokens) / f64::from(u.prompt_tokens()))
            .collect();
        (!shares.is_empty()).then(|| shares.iter().sum::<f64>() / shares.len() as f64)
    }
}

fn main() -> ExitCode {
    let o = match options() {
        Ok(o) => o,
        Err(error) => {
            eprintln!("assist-relay-latency: {error}");
            return ExitCode::from(2);
        }
    };
    let config = RelayConfig::built_in();
    let mut results = Vec::new();
    for (model, spec) in &config.models {
        if o.models.as_ref().is_some_and(|only| !only.contains(model)) {
            continue;
        }
        results.push(ModelResult {
            model: model.clone(),
            provider: spec.provider.label(),
            modes: Mode::LADDER
                .iter()
                .filter(|mode| {
                    let plan = config.modes.get(**mode);
                    plan.primary == *model || plan.fallback.as_ref() == Some(model) || plan.upgrade.as_ref() == Some(model)
                })
                .map(|mode| mode.as_str())
                .collect(),
            ..ModelResult::default()
        });
    }
    if !o.yes {
        // Nothing is read from the environment before --yes.
        for r in &results {
            let kind = config.model(&r.model).map_or(ProviderKind::Anthropic, |spec| spec.provider);
            println!("{:<22} {:<10} needs {}", r.model, r.provider, key_variable(kind));
        }
        println!("\nNothing sent and no key read: this calls paid APIs ({} requests per model). Add --yes to run it.", o.rounds + 2);
        return ExitCode::from(2);
    }
    let mut keys = BTreeMap::new();
    for r in &mut results {
        let Some(kind) = config.model(&r.model).map(|spec| spec.provider) else { continue };
        let variable = key_variable(kind);
        match std::env::var(variable).ok().filter(|k| !k.trim().is_empty()) {
            Some(key) => {
                keys.insert(kind, key);
            }
            None => r.skipped = Some(format!("{variable} not set")),
        }
        println!("{:<22} {:<10} {}", r.model, r.provider, r.skipped.as_deref().unwrap_or("will be measured"));
    }
    if results.iter().all(|r| r.skipped.is_some()) {
        println!("\nNo provider key is set, so there is nothing to measure (and {} is left as it is).", o.out.display());
        return ExitCode::from(2);
    }
    let runtime = match tokio::runtime::Builder::new_multi_thread().enable_all().build() {
        Ok(runtime) => runtime,
        Err(error) => {
            eprintln!("assist-relay-latency: {error}");
            return ExitCode::FAILURE;
        }
    };
    let store = Arc::new(ProviderKeys::default());
    store.replace(keys);
    let providers = Providers::live(store, &Endpoints::default(), Retry::default());
    let started = assist_relay::time::iso8601(assist_relay::time::unix_now());
    runtime.block_on(async {
        for result in results.iter_mut().filter(|r| r.skipped.is_none()) {
            measure(&config, &providers, result, &o).await;
        }
    });
    let text = render(&results, &config, &o, &started);
    println!("\n{text}");
    match std::fs::write(&o.out, &text) {
        Ok(()) => println!("Written to {}.", o.out.display()),
        Err(error) => {
            eprintln!("Could not write {}: {error}", o.out.display());
            return ExitCode::FAILURE;
        }
    }
    ExitCode::SUCCESS
}

async fn ask(providers: &Providers, kind: ProviderKind, request: CompletionRequest) -> Sample {
    let started = Instant::now();
    let mut stream = providers.get(kind).stream(request);
    let (mut ttft, mut usage, mut error) = (None, None, None);
    while let Some(item) = stream.next().await {
        match item {
            Ok(StreamEvent::Text(_)) => {
                ttft.get_or_insert(started.elapsed());
            }
            Ok(StreamEvent::Usage(counts)) => usage = Some(counts),
            Err(e) => {
                error = Some(e.code().to_owned());
                break;
            }
        }
    }
    Sample { ttft, total: started.elapsed(), usage, error }
}

async fn measure(config: &RelayConfig, providers: &Providers, result: &mut ModelResult, o: &Options) {
    let Some(spec) = config.model(&result.model) else { return };
    let pack = Pack::built_in();
    let call = CallContext {
        brief: Brief {
            meeting_type: MeetingType::Interview,
            title: "Senior Backend Engineer at Zarya Pay".into(),
            counterpart: "Sana Mirza".into(),
            company: "Zarya Pay".into(),
            goals: "Show the payouts and reconciliation work".into(),
            to_discuss: "Salary, notice period, start date".into(),
            ..Brief::default()
        },
        documents: vec![Document { id: "cv".into(), title: "CV".into(), text: cv() }],
        ..CallContext::default()
    };
    let transcript = Transcript::new(&config.context);
    let builder = ContextBuilder { pack: &pack, config: &config.context };
    let request = |question: &str, purpose: Purpose| CompletionRequest {
        model: result.model.clone(),
        context: Arc::new(builder.build(&call, &transcript, Task::Answer { turn_id: None, text: question, partial: false }, TranscriptScope::Full)),
        max_output_tokens: config.modes.fast.max_output_tokens,
        options: spec.options.clone(),
        purpose,
        question: question.to_owned(),
    };
    println!("{}: cold request", result.model);
    result.cold = Some(ask(providers, spec.provider, request(QUESTIONS[0], Purpose::Answer)).await);
    tokio::time::sleep(o.gap).await;
    let _ = providers.get(spec.provider).warm(request(QUESTIONS[0], Purpose::Warm)).await;
    for round in 0..o.rounds {
        tokio::time::sleep(o.gap).await;
        let sample = ask(providers, spec.provider, request(QUESTIONS[(round + 1) % QUESTIONS.len()], Purpose::Answer)).await;
        println!(
            "{}: warm {} ttft {} ms{}",
            result.model,
            round + 1,
            sample.ttft.map_or("-".into(), |d| d.as_millis().to_string()),
            sample.error.as_deref().map_or(String::new(), |e| format!(" ({e})"))
        );
        result.warm.push(sample);
    }
}

fn render(results: &[ModelResult], config: &RelayConfig, o: &Options, started: &str) -> String {
    let mut text = format!(
        "# Live Assist latency (v1.9, L9)\n\nMeasured {started} from **{}**, with `assist-relay-latency` (services/assist-relay/tools/latency.rs): \
         the relay's own provider clients called directly from this machine, one cold request per model, the cache warm-up, then \
         {} warm requests with the same cached prefix (an interview brief and a CV of about 4,000 tokens) and a different question \
         each, {} ms apart. Time to first token (TTFT) is from sending the request to the first word of the answer.\n\n",
        o.from,
        o.rounds,
        o.gap.as_millis()
    );
    text.push_str("| Model | Provider | Modes | Cold TTFT ms | Warm TTFT p50 ms | Warm TTFT p95 ms | Answer p50 ms | Cache-hit share | Errors |\n");
    text.push_str("|---|---|---|---|---|---|---|---|---|\n");
    for r in results {
        if let Some(reason) = &r.skipped {
            let modes = if r.modes.is_empty() { "not in a mode".to_owned() } else { r.modes.join(", ") };
            text.push_str(&format!("| {} | {} | {modes} | skipped: {reason} | | | | | |\n", r.model, r.provider));
            continue;
        }
        let mut ttfts = r.ttfts();
        let mut totals: Vec<f64> = r.warm.iter().filter(|s| s.error.is_none()).map(|s| s.total.as_secs_f64() * 1000.0).collect();
        let errors: Vec<String> = r.warm.iter().chain(r.cold.as_ref()).filter_map(|s| s.error.clone()).collect();
        text.push_str(&format!(
            "| {} | {} | {} | {} | {} | {} | {} | {} | {} |\n",
            r.model,
            r.provider,
            if r.modes.is_empty() { "not in a mode".into() } else { r.modes.join(", ") },
            ms(r.cold.as_ref().and_then(|s| s.ttft).map(|d| d.as_secs_f64() * 1000.0)),
            ms(percentile(&mut ttfts, 50.0)),
            ms(percentile(&mut ttfts, 95.0)),
            ms(percentile(&mut totals, 50.0)),
            r.cache_share().map_or("-".into(), |s| format!("{:.0} %", s * 100.0)),
            if errors.is_empty() { "0".into() } else { format!("{} ({})", errors.len(), errors.join(", ")) },
        ));
    }
    text.push_str("\n## Against the targets\n\nThe plan's targets (L9): end of turn to first word on screen p50 at most 1.5 s in Fast, 2.0 s for the Balanced draft, \
        3.0 s in Deep; at least 80 % of input read from the cache after the third suggestion. On screen adds the relay's overhead \
        (under 10 ms p95, LOADTEST.md) and the network between the app and the relay. Models held until complete (Sonnet 5.5, \
        Opus 5.5) show their first word with the last, so their answer time is the one that counts.\n\n");
    text.push_str("| Mode | Primary model | Target p50 | Measured p50 | Cache-hit share | Result |\n|---|---|---|---|---|---|\n");
    for (mode, target) in [(Mode::Fast, Some(1500.0)), (Mode::Balanced, Some(2000.0)), (Mode::Deep, Some(3000.0)), (Mode::Max, None)] {
        let primary = &config.modes.get(mode).primary;
        let Some(r) = results.iter().find(|r| &r.model == primary && r.skipped.is_none()) else {
            text.push_str(&format!("| {} | {primary} | {} | not measured | | |\n", mode.as_str(), target.map_or("none".into(), |t| format!("{t:.0} ms"))));
            continue;
        };
        let held = config.model(primary).is_some_and(|spec| spec.hold_until_complete);
        let mut values = if held {
            r.warm.iter().filter(|s| s.error.is_none()).map(|s| s.total.as_secs_f64() * 1000.0).collect()
        } else {
            r.ttfts()
        };
        let p50 = percentile(&mut values, 50.0);
        let share = r.cache_share();
        let verdict = match (target, p50) {
            (Some(t), Some(v)) if v <= t && share.is_none_or(|s| s >= 0.8) => "pass",
            (Some(_), Some(_)) => "miss",
            (None, _) => "no target",
            _ => "no data",
        };
        text.push_str(&format!(
            "| {} | {primary}{} | {} | {} ms | {} | {verdict} |\n",
            mode.as_str(),
            if held { " (held)" } else { "" },
            target.map_or("none".into(), |t| format!("{t:.0} ms")),
            ms(p50),
            share.map_or("-".into(), |s| format!("{:.0} %", s * 100.0)),
        ));
    }
    text.push_str("\nUpdate the mode -> model map in config/relay.toml (and the server's catalog) from these numbers.\n");
    text
}
