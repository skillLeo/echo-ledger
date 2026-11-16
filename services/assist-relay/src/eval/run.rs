//! Replays transcripts through a relay session (the same engine the apps talk to, driven through its channels) and
//! scores them.
//!
//! CI mode runs on the mocked models with every answer scripted: once with the reference answers, which must pass
//! every check, and once with the bad answers, which must fail exactly the categories they name. That checks the
//! transcripts, the harness and the scorer without a model. A live run uses the real providers and scores what they
//! answer.

use std::collections::{BTreeMap, BTreeSet};
use std::sync::Arc;
use std::time::Duration;

use serde_json::{Value, json};
use tokio::sync::mpsc;
use tokio::time::Instant;

use super::score::{CaseScore, Output, Totals, score_case};
use super::{Case, Category, Event, Expect};
use crate::auth::{AUDIENCE, BudgetClaim, Claims};
use crate::config::{MockLatency, RelayConfig, Secret, Shared};
use crate::domain::{Channel, Mode, Plan, ProviderKind};
use crate::internal::NoReports;
use crate::pack::Pack;
use crate::protocol::ClientMessage;
use crate::provider::{MockBehavior, MockProvider, MockStats, Providers};
use crate::server::AppState;
use crate::session::{Inbound, Outbound};
use crate::telemetry::Recorder;
use crate::time::unix_now;

/// The answer CI scripts for an `answer` or `any` turn that has no reference: short, no numbers, nothing claimed.
pub const GENERIC_ANSWER: &str = "Sure, happy to answer that.\n- **Point**: from my notes";

/// Which answers CI scripts.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Script {
    References,
    /// Each turn's bad answer number `n` (turns with fewer get their reference).
    BadAnswers(usize),
}

/// How a run talks to the models.
pub enum Models {
    /// The mocked models, every answer scripted.
    Scripted { script: Script, mocks: BTreeMap<ProviderKind, Arc<MockProvider>> },
    Live,
}

impl std::fmt::Debug for Models {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        match self {
            Models::Scripted { script, .. } => write!(f, "Scripted({script:?})"),
            Models::Live => f.write_str("Live"),
        }
    }
}

#[derive(Debug)]
pub struct Runner {
    state: AppState,
    config: RelayConfig,
    models: Models,
    pub mode: Mode,
    /// How long to wait for a suggestion's end, and for a Balanced upgrade after its draft.
    pub wait: Duration,
    pub upgrade_wait: Duration,
}

fn state(config: &RelayConfig, providers: Providers) -> AppState {
    let secret: String = (0..4).map(|i| format!("{:016x}", std::hash::BuildHasher::hash_one(&std::collections::hash_map::RandomState::new(), i))).collect();
    crate::app_state(crate::Parts {
        config: Arc::new(Shared::new(config.clone())),
        pack: Pack::built_in(),
        providers,
        telemetry: Arc::new(Recorder::default()),
        reports: Arc::new(NoReports),
        secrets: vec![Secret::new(secret)],
        issuer: "echoledger".into(),
    })
}

impl Runner {
    /// CI: the mocked models, nearly instant, in Fast (one request per suggestion, so the script lines up).
    pub fn scripted(script: Script) -> Runner {
        let mut config = RelayConfig::built_in();
        for spec in config.models.values_mut() {
            spec.mock = MockLatency { first_token_ms: 1, token_ms: 0 };
        }
        let (providers, mocks) = Providers::mock(&config, Arc::new(MockStats::default()));
        Runner {
            state: state(&config, providers),
            config,
            models: Models::Scripted { script, mocks },
            mode: Mode::Fast,
            wait: Duration::from_secs(5),
            upgrade_wait: Duration::from_millis(200),
        }
    }

    /// The real providers (keys already in `providers`), in `mode`.
    pub fn live(config: RelayConfig, providers: Providers, mode: Mode) -> Runner {
        Runner {
            state: state(&config, providers),
            config,
            models: Models::Live,
            mode,
            wait: Duration::from_secs(30),
            upgrade_wait: Duration::from_secs(20),
        }
    }

    fn claims(&self, case: &Case) -> Claims {
        let now = unix_now();
        Claims {
            iss: "echoledger".into(),
            aud: AUDIENCE.into(),
            sub: "eval".into(),
            ws: None,
            sid: format!("eval-{}-{now}", case.id),
            plan: Plan::Ultra,
            modes: Mode::LADDER.to_vec(),
            models: self.config.models.keys().cloned().collect(),
            budget: BudgetClaim { seconds_left: 36_000, cents_left: 100_000 },
            practice: false,
            iat: now,
            exp: now + 900,
            jti: Some(format!("eval-{}", case.id)),
        }
    }

    /// The scripted answer for a turn (CI only).
    fn scripted_answer(script: Script, turn: &super::Turn) -> String {
        if let Script::BadAnswers(n) = script
            && let Some(bad) = turn.bad.get(n)
        {
            return bad.answer.clone();
        }
        match (&turn.reference, turn.expect()) {
            (Some(reference), _) => reference.clone(),
            (None, Expect::Skip) => "SKIP".into(),
            _ => GENERIC_ANSWER.into(),
        }
    }

    /// Replays one transcript; the output of every `other` turn by id, and anything the harness itself got wrong.
    pub async fn run_case(&self, case: &Case) -> (BTreeMap<String, Output>, Vec<String>) {
        let (out_tx, mut out) = mpsc::channel::<Outbound>(4096);
        let (inbound, in_rx) = mpsc::channel::<Inbound>(256);
        let mut problems = Vec::new();
        if self.state.registry.connect(self.claims(case), out_tx, in_rx).is_err() {
            return (BTreeMap::new(), vec!["the session did not start".into()]);
        }
        let mut seq = 0u64;
        let mut send = |message: Value| {
            seq += 1;
            let inbound = inbound.clone();
            let seq = seq;
            async move {
                match serde_json::from_value::<ClientMessage>(message) {
                    Ok(message) => inbound.send(Inbound { message, seq: Some(seq), received_at: Instant::now() }).await.is_ok(),
                    Err(_) => false,
                }
            }
        };
        if next_of(&mut out, "ready", self.wait).await.is_none() {
            return (BTreeMap::new(), vec!["no ready".into()]);
        }
        let documents: Vec<Value> = case.documents.iter().map(|d| json!({"id": d.id, "title": d.title, "text": d.text})).collect();
        let brief = &case.brief;
        send(json!({"type": "context_update",
            "brief": {"meeting_type": brief.meeting_type, "title": brief.title, "counterpart": brief.counterpart,
                      "company": brief.company, "goals": brief.goals, "done": brief.done, "not_done": brief.not_done,
                      "to_discuss": brief.to_discuss, "instructions": brief.instructions},
            "documents": documents,
            "settings": {"mode": self.mode, "hint_level": case.settings.hint_level, "language_help": case.settings.language_help}}))
        .await;
        // Let the mode change and the warm-up settle before the call starts.
        drain(&mut out, Duration::from_millis(if matches!(self.models, Models::Live) { 1500 } else { 50 })).await;

        let mut outputs = BTreeMap::new();
        for event in &case.events {
            match event {
                Event::Facts(facts) => {
                    let list: Vec<Value> = facts.facts.iter().map(|text| json!({"text": text})).collect();
                    send(json!({"type": "context_update", "facts": list})).await;
                }
                Event::Turn(turn) => {
                    let start = super::at_ms(&turn.at).unwrap_or(0);
                    let channel = if turn.channel == Channel::Me { "me" } else { "other" };
                    let expect = turn.expect();
                    if let Models::Scripted { script, mocks } = &self.models
                        && turn.channel == Channel::Other
                        && expect != Expect::None
                    {
                        let primary = &self.config.modes.get(self.mode).primary;
                        let kind = self.config.model(primary).map_or(ProviderKind::Anthropic, |spec| spec.provider);
                        mocks[&kind].push(MockBehavior::Reply(Self::scripted_answer(*script, turn)));
                    }
                    send(json!({"type": "turn_final", "turn_id": turn.id, "channel": channel, "text": turn.text,
                                "language": "en", "start_ms": start, "end_ms": start + 3000}))
                    .await;
                    if turn.channel == Channel::Me {
                        continue;
                    }
                    let output = if expect == Expect::None {
                        match next_of(&mut out, "suggestion_start", Duration::from_millis(200)).await {
                            Some(_) => {
                                problems.push(format!("{}: a backchannel started a suggestion", turn.id));
                                Output::Failed("unexpected".into())
                            }
                            None => Output::Nothing,
                        }
                    } else {
                        self.collect(&mut out, &turn.id).await
                    };
                    if let Output::Failed(reason) = &output {
                        problems.push(format!("{}: {reason}", turn.id));
                    }
                    outputs.insert(turn.id.clone(), output);
                }
            }
        }
        (outputs, problems)
    }

    /// Follows the suggestion for `turn` to the text it leaves on the card: through a fallback's replacement, and a
    /// Balanced upgrade when one replaces the draft.
    async fn collect(&self, out: &mut mpsc::Receiver<Outbound>, turn: &str) -> Output {
        let deadline = Instant::now() + self.wait;
        let mut current: Option<String> = None;
        let mut draft = false;
        let mut text = String::new();
        loop {
            let Some(message) = next_message(out, deadline.saturating_duration_since(Instant::now())).await else {
                return Output::Failed(if current.is_some() { "no end in time" } else { "no suggestion" }.into());
            };
            let id = message["suggestion_id"].as_str().map(str::to_owned);
            match message["type"].as_str() {
                Some("suggestion_start") if message["turn_id"] == turn && (current.is_none() || message["replaces"].as_str() == current.as_deref()) => {
                    current = id;
                    draft = message["draft"].as_bool().unwrap_or(false);
                    text.clear();
                }
                Some("token") if id.is_some() && id == current => text.push_str(message["text"].as_str().unwrap_or_default()),
                Some("skip") if id.is_some() && id == current => return Output::Skip,
                Some("error") if id.is_some() && id == current => {
                    return Output::Failed(format!("error {}", message["code"].as_str().unwrap_or_default()));
                }
                Some("suggestion_end") if id.is_some() && id == current => match message["status"].as_str() {
                    Some("done") if draft => return self.upgrade(out, current.unwrap_or_default(), text).await,
                    Some("done") => return Output::Answer(text),
                    // A replacement follows (a fallback after a broken answer).
                    Some("superseded") => {}
                    other => return Output::Failed(format!("ended {other:?}")),
                },
                _ => {}
            }
        }
    }

    /// After a Balanced draft: the upgrade's whole text if it replaces the draft in time, else the draft.
    async fn upgrade(&self, out: &mut mpsc::Receiver<Outbound>, draft_id: String, draft: String) -> Output {
        let deadline = Instant::now() + self.upgrade_wait;
        let mut upgrade: Option<String> = None;
        let mut text = String::new();
        while let Some(message) = next_message(out, deadline.saturating_duration_since(Instant::now())).await {
            let id = message["suggestion_id"].as_str().map(str::to_owned);
            match message["type"].as_str() {
                Some("suggestion_start") if message["replaces"] == draft_id.as_str() => upgrade = id,
                Some("token") if id.is_some() && id == upgrade => text.push_str(message["text"].as_str().unwrap_or_default()),
                Some("suggestion_end") if id.is_some() && id == upgrade => return Output::Answer(text),
                _ => {}
            }
        }
        Output::Answer(draft)
    }
}

async fn next_message(out: &mut mpsc::Receiver<Outbound>, wait: Duration) -> Option<Value> {
    match tokio::time::timeout(wait, out.recv()).await {
        Ok(Some(Outbound::Message(message))) => serde_json::to_value(&message).ok(),
        Ok(Some(Outbound::Close { .. }) | None) | Err(_) => None,
    }
}

async fn next_of(out: &mut mpsc::Receiver<Outbound>, kind: &str, wait: Duration) -> Option<Value> {
    let deadline = Instant::now() + wait;
    while let Some(message) = next_message(out, deadline.saturating_duration_since(Instant::now())).await {
        if message["type"] == kind {
            return Some(message);
        }
    }
    None
}

async fn drain(out: &mut mpsc::Receiver<Outbound>, wait: Duration) {
    while next_message(out, wait).await.is_some() {}
}

/// Runs and scores every transcript.
pub async fn run_all(runner: &Runner, cases: &[Case]) -> (Vec<CaseScore>, Totals, Vec<String>) {
    let mut scores = Vec::new();
    let mut totals = Totals::default();
    let mut problems = Vec::new();
    for case in cases {
        let (outputs, harness) = runner.run_case(case).await;
        problems.extend(harness.into_iter().map(|p| format!("{}: {p}", case.id)));
        let score = score_case(case, &outputs);
        totals.add(&score, case);
        scores.push(score);
    }
    (scores, totals, problems)
}

/// CI: the transcripts are sound, the references pass every check, and each bad answer fails exactly the categories
/// it names (and nothing else fails). Returns what is wrong.
pub async fn ci_check(cases: &[Case]) -> Vec<String> {
    let mut problems = super::validate(cases);
    let (scores, totals, harness) = run_all(&Runner::scripted(Script::References), cases).await;
    problems.extend(harness);
    for score in &scores {
        for (turn, _, results) in &score.turns {
            for r in results.iter().filter(|r| !r.passed || r.unchecked) {
                problems.push(format!("{}/{turn}: the reference fails {}: {}", score.id, r.category.label(), r.detail));
            }
        }
    }
    if (totals.overall() - 1.0).abs() > f64::EPSILON {
        problems.push(format!("references score {:.3}, not 1.0", totals.overall()));
    }
    let rounds = cases
        .iter()
        .flat_map(|case| &case.events)
        .filter_map(|event| match event {
            Event::Turn(turn) => Some(turn.bad.len()),
            Event::Facts(_) => None,
        })
        .max()
        .unwrap_or(0);
    for round in 0..rounds {
        let (scores, _, harness) = run_all(&Runner::scripted(Script::BadAnswers(round)), cases).await;
        problems.extend(harness);
        for (score, case) in scores.iter().zip(cases) {
            for (turn_id, _, results) in &score.turns {
                let failed: BTreeSet<Category> = results.iter().filter(|r| !r.passed).map(|r| r.category).collect();
                let bad = case.events.iter().find_map(|e| match e {
                    Event::Turn(t) if &t.id == turn_id => t.bad.get(round),
                    _ => None,
                });
                if bad.is_some_and(|b| b.judge_only) {
                    // The rules can't fail it; they must say so rather than pass it silently.
                    let reported = results.iter().any(|r| r.category == Category::NothingInvented && r.unchecked);
                    if !failed.is_empty() || !reported {
                        problems.push(format!(
                            "{}/{turn_id}: judge-only bad answer {}: rules failed {failed:?}, reported as not checkable: {reported}",
                            score.id,
                            round + 1
                        ));
                    }
                    continue;
                }
                let declared: BTreeSet<Category> = bad.map(|b| b.fails.iter().copied().collect()).unwrap_or_default();
                if failed != declared {
                    problems.push(format!("{}/{turn_id}: bad answer {} fails {failed:?}, declared {declared:?}", score.id, round + 1));
                }
            }
        }
    }
    problems
}

/// "Nothing invented" with the model judge: an answer passes when the rules don't fail it and the judge passes it
/// (an answer the judge did not see counts as failed). Returns the rule-and-judge rate and the overall score with it.
pub fn judged(scores: &[CaseScore], totals: &Totals, judge: &super::judge::JudgeTotals) -> (u32, u32, f64) {
    let (mut passed, mut total) = (0u32, 0u32);
    for score in scores {
        for (turn, _, results) in &score.turns {
            for r in results.iter().filter(|r| r.category == Category::NothingInvented) {
                total += 1;
                let judge_passed = judge.verdicts.get(&format!("{}/{turn}", score.id)).copied().unwrap_or(false);
                if r.passed && judge_passed {
                    passed += 1;
                }
            }
        }
    }
    let rates: Vec<f64> = Category::ALL
        .iter()
        .filter_map(|c| if *c == Category::NothingInvented { (total > 0).then(|| f64::from(passed) / f64::from(total)) } else { totals.rate(*c) })
        .collect();
    let overall = if rates.is_empty() { 0.0 } else { rates.iter().sum::<f64>() / rates.len() as f64 };
    (passed, total, overall)
}

/// A Markdown report. The rule scores are an UPPER BOUND: rules can't see an invented fact without a number or a
/// listed phrase. The L9 verdict (90 % overall) is given only with the model judge.
pub fn report(scores: &[CaseScore], totals: &Totals, judge: Option<&super::judge::JudgeTotals>) -> String {
    let pct = |rate: Option<f64>| rate.map_or("n/a".to_owned(), |r| format!("{:.1} %", r * 100.0));
    let mut text = String::from(
        "Rule scores are an **upper bound**: the rules check numbers, listed claims, re-asks and SKIP, but an invented \
         fact with no number (\"I've used Kotlin on Android projects\") passes them. Answers with such a claim are \
         counted as passed and listed as not checkable by rules; only the model judge decides them.\n\n\
         | Check | Passed | Rate |\n|---|---|---|\n",
    );
    for category in Category::ALL {
        let (passed, total) = totals.by_category.get(&category).copied().unwrap_or_default();
        let bound = if category == Category::NothingInvented { " (upper bound)" } else { "" };
        text.push_str(&format!("| {}{bound} | {passed}/{total} | {} |\n", category.label(), pct(totals.rate(category))));
    }
    text.push_str(&format!("| SKIP precision (recall) | | {} ({}) |\n", pct(totals.skip_precision()), pct(totals.skip_recall())));
    text.push_str(&format!("| Not checkable by rules (left to the judge) | {} | |\n", totals.unchecked));
    text.push_str(&format!("| Rule score overall (**upper bound**) | | {:.1} % |\n", totals.overall() * 100.0));
    match judge {
        Some(judge) => {
            let (passed, total, overall) = judged(scores, totals, judge);
            text.push_str(&format!(
                "| Nothing invented, rules and model judge | {passed}/{total} | {} |\n",
                pct((total > 0).then(|| f64::from(passed) / f64::from(total)))
            ));
            text.push_str(&format!(
                "| **Overall with the judge: the L9 verdict** | | **{:.1} %: {}** (target 90 %) |\n",
                overall * 100.0,
                if overall >= 0.9 { "pass" } else { "below target" }
            ));
        }
        None => text.push_str(
            "\n**No verdict.** The L9 sign-off at 90 % requires the model judge: run again with `--judge`. The rule \
             score above is an upper bound, not a pass.\n",
        ),
    }
    if totals.failed_turns > 0 {
        text.push_str(&format!("\n{} turns got no suggestion or an error.\n", totals.failed_turns));
    }
    text.push_str("\n| Transcript | Turns | Failed checks | Not checkable by rules |\n|---|---|---|---|\n");
    for score in scores {
        let failed: Vec<String> = score
            .turns
            .iter()
            .flat_map(|(turn, _, results)| results.iter().filter(|r| !r.passed).map(move |r| format!("{turn} {}: {}", r.category.label(), r.detail)))
            .collect();
        let unchecked: Vec<String> = score
            .turns
            .iter()
            .flat_map(|(turn, _, results)| results.iter().filter(|r| r.unchecked).map(move |r| format!("{turn}: {}", r.detail)))
            .collect();
        let list = |items: Vec<String>| if items.is_empty() { "none".to_owned() } else { items.join("<br>") };
        text.push_str(&format!("| {} | {} | {} | {} |\n", score.id, score.turns.len(), list(failed), list(unchecked)));
    }
    text
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::eval::judge::JudgeTotals;
    use crate::eval::score::CheckResult;

    fn score(unchecked: bool) -> (Vec<CaseScore>, Totals) {
        let results = vec![
            CheckResult { category: Category::FirstLine, passed: true, unchecked: false, detail: String::new() },
            CheckResult { category: Category::NothingInvented, passed: true, unchecked, detail: "not checkable by rules: x".into() },
        ];
        let scores = vec![CaseScore { id: "case".into(), turns: vec![("o-1".into(), Output::Answer("I've used it.".into()), results)] }];
        let mut totals = Totals::default();
        for (_, _, results) in &scores[0].turns {
            for r in results {
                let entry = totals.by_category.entry(r.category).or_default();
                entry.1 += 1;
                if r.passed {
                    entry.0 += 1;
                }
                if r.unchecked {
                    totals.unchecked += 1;
                }
            }
        }
        (scores, totals)
    }

    #[test]
    fn without_the_judge_there_is_no_verdict_and_the_rule_score_is_an_upper_bound() {
        let (scores, totals) = score(true);
        let text = report(&scores, &totals, None);
        assert!(text.contains("upper bound") && text.contains("No verdict") && text.contains("--judge"), "{text}");
        assert!(!text.contains("pass**") && !text.contains("below target"), "{text}");
        assert!(text.contains("Not checkable by rules (left to the judge) | 1"), "{text}");
    }

    #[test]
    fn with_the_judge_an_unchecked_claim_it_fails_counts_as_invented() {
        let (scores, totals) = score(true);
        let mut judge = JudgeTotals::default();
        judge.verdicts.insert("case/o-1".into(), false);
        let (passed, total, overall) = judged(&scores, &totals, &judge);
        assert_eq!((passed, total), (0, 1));
        assert!(overall < 0.9);
        assert!(report(&scores, &totals, Some(&judge)).contains("below target"));
        judge.verdicts.insert("case/o-1".into(), true);
        assert!(report(&scores, &totals, Some(&judge)).contains(": pass**"));
    }
}
