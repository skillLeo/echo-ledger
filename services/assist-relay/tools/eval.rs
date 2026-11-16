//! The golden eval set (plan L9): ten call transcripts with traps, replayed through a relay session and scored.
//!
//!   cargo build --release
//!   target/release/assist-relay-eval --ci                    # mocked models, no keys: the set, the harness, the scorer
//!   target/release/assist-relay-eval --live --yes            # real models, Balanced, keys from the environment
//!   target/release/assist-relay-eval --live --yes --mode fast --out eval/RESULTS.md
//!   target/release/assist-relay-eval --live --yes --judge    # plus the model judge for invented claims
//!
//! `--live` calls paid APIs and sends nothing without `--yes`. Keys are read from the environment at run time only
//! (`ANTHROPIC_API_KEY`, `GROQ_API_KEY`, `OPENAI_API_KEY`, `GEMINI_API_KEY`: the ones the mode's models need) and never
//! printed or written. The plan's target is 90 % overall in Balanced. `--judge` uses Claude Sonnet 5.5 and needs
//! `ANTHROPIC_API_KEY`; it never runs in CI.

use std::collections::BTreeMap;
use std::path::PathBuf;
use std::process::ExitCode;
use std::sync::Arc;

use assist_relay::config::RelayConfig;
use assist_relay::domain::{Mode, ProviderKind};
use assist_relay::eval::judge::{JudgeTotals, judge_case};
use assist_relay::eval::run::{Runner, ci_check, report, run_all};
use assist_relay::eval::{load_dir, validate};
use assist_relay::internal::ProviderKeys;
use assist_relay::provider::{Endpoints, Providers, Retry};

const JUDGE_MODEL: &str = "claude-sonnet-5-5";

struct Options {
    ci: bool,
    live: bool,
    yes: bool,
    judge: bool,
    mode: Mode,
    dir: PathBuf,
    out: Option<PathBuf>,
}

fn options() -> Result<Options, String> {
    let mut o = Options {
        ci: false,
        live: false,
        yes: false,
        judge: false,
        mode: Mode::Balanced,
        dir: PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("eval/transcripts"),
        out: None,
    };
    let mut args = std::env::args().skip(1);
    while let Some(flag) = args.next() {
        let mut value = || args.next().ok_or(format!("{flag} needs a value"));
        match flag.as_str() {
            "--ci" => o.ci = true,
            "--live" => o.live = true,
            "--yes" => o.yes = true,
            "--judge" => o.judge = true,
            "--mode" => {
                o.mode = match value()?.as_str() {
                    "fast" => Mode::Fast,
                    "balanced" => Mode::Balanced,
                    "deep" => Mode::Deep,
                    "max" => Mode::Max,
                    other => return Err(format!("unknown mode {other}")),
                }
            }
            "--dir" => o.dir = PathBuf::from(value()?),
            "--out" => o.out = Some(PathBuf::from(value()?)),
            _ => return Err(format!("unknown option {flag}")),
        }
    }
    if o.ci == o.live {
        return Err("choose --ci or --live".into());
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
            eprintln!("assist-relay-eval: {error}\nUsage: assist-relay-eval --ci | --live --yes [--mode balanced] [--judge] [--out FILE]");
            return ExitCode::from(2);
        }
    };
    let cases = match load_dir(&o.dir) {
        Ok(cases) => cases,
        Err(error) => {
            eprintln!("assist-relay-eval: {error}");
            return ExitCode::from(2);
        }
    };
    let runtime = match tokio::runtime::Builder::new_multi_thread().enable_all().build() {
        Ok(runtime) => runtime,
        Err(error) => {
            eprintln!("assist-relay-eval: {error}");
            return ExitCode::FAILURE;
        }
    };
    if o.ci {
        let problems = runtime.block_on(ci_check(&cases));
        if problems.is_empty() {
            println!("{} transcripts: the set follows the plan, every reference passes, every bad answer fails as named.", cases.len());
            return ExitCode::SUCCESS;
        }
        for problem in &problems {
            println!("{problem}");
        }
        return ExitCode::FAILURE;
    }

    let problems = validate(&cases);
    if !problems.is_empty() {
        problems.iter().for_each(|p| eprintln!("{p}"));
        return ExitCode::from(2);
    }
    let config = RelayConfig::built_in();
    let plan = config.modes.get(o.mode);
    let mut models: Vec<String> = [Some(&plan.primary), plan.fallback.as_ref(), plan.upgrade.as_ref()].into_iter().flatten().cloned().collect();
    if o.judge {
        models.push(JUDGE_MODEL.to_owned());
    }
    let mut keys = BTreeMap::new();
    for model in &models {
        let Some(kind) = config.model(model).map(|spec| spec.provider) else { continue };
        let variable = key_variable(kind);
        match std::env::var(variable).ok().filter(|k| !k.trim().is_empty()) {
            Some(key) => {
                keys.insert(kind, key);
            }
            None => println!("{variable} is not set: {model} will answer \"not configured\"."),
        }
    }
    println!(
        "Live eval: {} transcripts in {} ({}){}.",
        cases.len(),
        o.mode.as_str(),
        models.iter().map(|m| m.as_str()).collect::<Vec<_>>().join(", "),
        if o.judge { ", with the model judge" } else { "" }
    );
    if !o.yes {
        println!("Nothing sent: this calls paid APIs (about 100 requests). Add --yes to run it.");
        return ExitCode::from(2);
    }
    let store = Arc::new(ProviderKeys::default());
    store.replace(keys);
    let providers = Providers::live(store, &Endpoints::default(), Retry::default());
    let judge_provider = providers.get(ProviderKind::Anthropic);
    let runner = Runner::live(config.clone(), providers, o.mode);
    let (scores, totals, harness) = runtime.block_on(run_all(&runner, &cases));
    let judge = o.judge.then(|| {
        let options = config.model(JUDGE_MODEL).map(|spec| spec.options.clone()).unwrap_or_default();
        let mut totals = JudgeTotals::default();
        for (score, case) in scores.iter().zip(&cases) {
            let one = runtime.block_on(judge_case(&judge_provider, JUDGE_MODEL, &options, case, score));
            totals.passed += one.passed;
            totals.total += one.total;
            totals.failures.extend(one.failures);
        }
        totals
    });
    let mut text = format!(
        "# Live Assist golden eval\n\nRun {} in {} mode ({}){}.\n\n",
        assist_relay::time::iso8601(assist_relay::time::unix_now()),
        o.mode.as_str(),
        models.iter().map(|m| m.as_str()).collect::<Vec<_>>().join(", "),
        if o.judge { ", with the model judge" } else { "" }
    );
    text.push_str(&report(&scores, &totals, judge.as_ref()));
    if let Some(judge) = &judge
        && !judge.failures.is_empty()
    {
        text.push_str("\nThe judge's findings:\n\n");
        judge.failures.iter().for_each(|f| text.push_str(&format!("- {f}\n")));
    }
    if !harness.is_empty() {
        text.push_str("\nTurns that got no suggestion or an error:\n\n");
        harness.iter().for_each(|p| text.push_str(&format!("- {p}\n")));
    }
    println!("\n{text}");
    if let Some(path) = &o.out {
        match std::fs::write(path, &text) {
            Ok(()) => println!("Written to {}.", path.display()),
            Err(error) => eprintln!("Could not write {}: {error}", path.display()),
        }
    }
    if totals.overall() >= 0.9 { ExitCode::SUCCESS } else { ExitCode::FAILURE }
}
