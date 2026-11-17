//! The practice eval: the relay's practice questions must not invent personal data. A case (`eval/practice/*.json`)
//! is a brief (possibly empty), documents, and the answers Me gives; each question the relay asks is checked against
//! what is known at that point (the brief, the documents, Me's answers so far): no number that isn't there, and no
//! name (a capitalised word that does not start a sentence) that isn't there.
//!
//! CI scripts the questions on the mocked models: the reference questions must pass, each bad question must fail. A
//! live run scores the real model's questions.

use std::path::Path;
use std::sync::Arc;
use std::time::Duration;

use serde::Deserialize;
use serde_json::{Value, json};
use tokio::sync::mpsc;
use tokio::time::Instant;

use super::contains_word;
use super::score::{NumberCheck, Sources, quantities};
use crate::auth::{AUDIENCE, BudgetClaim, Claims};
use crate::config::{MockLatency, RelayConfig, Secret, Shared};
use crate::domain::{Mode, Plan, ProviderKind};
use crate::internal::NoReports;
use crate::pack::Pack;
use crate::protocol::{Brief, ClientMessage, Document};
use crate::provider::{MockBehavior, MockStats, Providers};
use crate::server::AppState;
use crate::session::{Inbound, Outbound};
use crate::telemetry::Recorder;
use crate::time::unix_now;

#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct PracticeCase {
    pub id: String,
    #[serde(default)]
    pub brief: Brief,
    #[serde(default)]
    pub documents: Vec<Document>,
    /// Me's answer after each question, in order.
    pub answers: Vec<String>,
    /// Questions that use only what is known (CI scripts them; they must pass).
    pub reference: Vec<String>,
    /// Questions that invent something (CI scripts each as the first question; each must fail).
    pub bad: Vec<String>,
}

pub fn load_dir(dir: &Path) -> Result<Vec<PracticeCase>, String> {
    let mut paths: Vec<_> = std::fs::read_dir(dir)
        .map_err(|e| format!("{}: {e}", dir.display()))?
        .filter_map(Result::ok)
        .map(|entry| entry.path())
        .filter(|path| path.extension().is_some_and(|ext| ext == "json"))
        .collect();
    paths.sort();
    paths
        .iter()
        .map(|path| {
            let text = std::fs::read_to_string(path).map_err(|e| format!("{}: {e}", path.display()))?;
            serde_json::from_str(&text).map_err(|e| format!("{}: {e}", path.display()))
        })
        .collect()
}

/// Capitalised words that are not names: the usual openers of a question, and common words.
const NOT_NAMES: &[&str] = &[
    "i", "i'm", "i've", "i'd", "me", "my", "you", "your", "you're", "you've", "we", "we're", "our", "us", "they", "it",
    "this", "that", "these", "there", "tell", "describe", "walk", "give", "imagine", "suppose", "let's", "let", "say",
    "talk", "share", "explain", "why", "what", "how", "when", "where", "who", "whom", "whose", "which", "do", "does",
    "did", "have", "has", "had", "can", "could", "would", "will", "should", "is", "are", "was", "were", "please",
    "okay", "if", "in", "on", "at", "for", "as", "after", "before", "now", "next", "finally", "so", "and", "but",
    "also", "great", "thanks", "good", "right", "sure", "yes", "no", "any", "last", "first", "looking", "thinking",
    "the", "a", "an", "monday", "tuesday", "wednesday", "thursday", "friday", "saturday", "sunday", "january",
    "february", "march", "april", "may", "june", "july", "august", "september", "october", "november", "december",
    "english", "urdu", "arabic", "ai", "ok",
];

/// Words that state something bad happened to Me or was done by Me (an invented negative, unless the material says
/// so).
const NEGATIVES: &[&str] = &[
    "fired", "sacked", "dismissed", "laid off", "let you go", "let go", "terminated", "demoted", "failed", "failure",
    "complaint", "complaints", "warning", "lied", "cheated", "arrested", "missed", "late", "quit", "resigned",
    "mistake", "mistakes", "blamed", "rejected", "poor performance", "struggled",
];

/// Words that make a question hypothetical or open ("a time you missed a deadline" asks, it does not claim).
const HYPOTHETICAL: &[&str] = &[
    "if", "would", "could", "imagine", "suppose", "ever", "a time", "any", "what happens", "how do you handle",
    "how would", "what would",
];

/// What a question invents, against what is known when it is asked: numbers and names that aren't there, and bad
/// things about Me that the material doesn't say.
pub fn invented(question: &str, known: &Sources) -> Vec<String> {
    let mut found: Vec<String> = quantities(question, false)
        .iter()
        .filter(|q| known.check(q) != NumberCheck::Known)
        .map(|q| format!("the number {}", q.value))
        .collect();
    for sentence in question.split(['.', '?', '!', ':', ';', '\n']) {
        for word in sentence.split(|c: char| c.is_whitespace() || c == ',') {
            let word = word.trim_matches(|c: char| !c.is_alphanumeric() && c != '\'');
            let starts_upper = word.chars().next().is_some_and(char::is_uppercase);
            let lower = word.to_lowercase();
            if starts_upper && word.chars().count() > 1 && !NOT_NAMES.contains(&lower.as_str()) && !known.has_word(&lower) {
                found.push(format!("the name {word}"));
            }
        }
        let lower = sentence.to_lowercase();
        let hypothetical = HYPOTHETICAL.iter().any(|h| contains_word(&lower, h));
        for negative in NEGATIVES {
            if !hypothetical && contains_word(&lower, negative) && !negative.split(' ').all(|w| known.has_word(w)) {
                found.push(format!("the claim \"{negative}\""));
            }
        }
    }
    found.sort();
    found.dedup();
    found
}

fn known_from(case: &PracticeCase, answers: &[String]) -> Sources {
    let mut known = Sources::default();
    let brief = &case.brief;
    for field in [&brief.title, &brief.counterpart, &brief.company, &brief.goals, &brief.done, &brief.not_done, &brief.to_discuss, &brief.instructions] {
        known.add(field);
    }
    for document in &case.documents {
        known.add(&document.title);
        known.add(&document.text);
    }
    answers.iter().for_each(|answer| known.add(answer));
    known
}

/// How a practice run gets its questions.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Questions {
    /// CI: the case's reference questions, scripted.
    References,
    /// CI: the bad question number `n` as the first question, scripted.
    Bad(usize),
    /// The real models.
    Live,
}

fn scripted_state() -> (AppState, std::collections::BTreeMap<ProviderKind, Arc<crate::provider::MockProvider>>) {
    let mut config = RelayConfig::built_in();
    for spec in config.models.values_mut() {
        spec.mock = MockLatency { first_token_ms: 1, token_ms: 0 };
    }
    config.background.facts_every_finals = u32::MAX;
    config.background.summary_every_secs = u64::MAX / 4;
    let (providers, mocks) = Providers::mock(&config, Arc::new(MockStats::default()));
    (state(config, providers), mocks)
}

fn state(config: RelayConfig, providers: Providers) -> AppState {
    crate::app_state(crate::Parts {
        config: Arc::new(Shared::new(config)),
        pack: Pack::built_in(),
        providers,
        telemetry: Arc::new(Recorder::default()),
        reports: Arc::new(NoReports),
        secrets: vec![Secret::new("practice-eval-secret-not-used-for-signing-0123456789")],
        issuer: "echoledger".into(),
    })
}

async fn next_of(out: &mut mpsc::Receiver<Outbound>, kinds: &[&str], wait: Duration) -> Option<Value> {
    let deadline = Instant::now() + wait;
    loop {
        let left = deadline.saturating_duration_since(Instant::now());
        match tokio::time::timeout(left, out.recv()).await {
            Ok(Some(Outbound::Message(message))) => {
                let value = serde_json::to_value(&message).ok()?;
                if kinds.iter().any(|k| value["type"] == *k) {
                    return Some(value);
                }
            }
            _ => return None,
        }
    }
}

/// Runs one case: `count` questions, Me answering after each; the questions asked, and each one's inventions.
pub async fn run_case(
    state: &AppState,
    mocks: Option<&std::collections::BTreeMap<ProviderKind, Arc<crate::provider::MockProvider>>>,
    case: &PracticeCase,
    questions: Questions,
    count: usize,
) -> Result<Vec<(String, Vec<String>)>, String> {
    let now = unix_now();
    let claims = Claims {
        iss: "echoledger".into(),
        aud: AUDIENCE.into(),
        sub: "eval".into(),
        ws: None,
        sid: format!("practice-eval-{}-{now}-{questions:?}", case.id),
        plan: Plan::Free,
        modes: vec![Mode::Fast],
        models: state.config.get().models.keys().cloned().collect(),
        budget: BudgetClaim { seconds_left: 36_000, cents_left: 100_000 },
        practice: true,
        iat: now,
        exp: now + 900,
        jti: None,
    };
    let (out_tx, mut out) = mpsc::channel::<Outbound>(1024);
    let (inbound, in_rx) = mpsc::channel::<Inbound>(64);
    state.registry.connect(claims, out_tx, in_rx).map_err(|_| "the session did not start".to_owned())?;
    let mut seq = 0u64;
    let mut send = |message: Value| {
        seq += 1;
        let inbound = inbound.clone();
        let seq = seq;
        async move {
            if let Ok(message) = serde_json::from_value::<ClientMessage>(message) {
                let _ = inbound.send(Inbound { message, seq: Some(seq), received_at: Instant::now() }).await;
            }
        }
    };
    next_of(&mut out, &["ready"], Duration::from_secs(10)).await.ok_or("no ready")?;
    let documents: Vec<Value> = case.documents.iter().map(|d| json!({"id": d.id, "title": d.title, "text": d.text})).collect();
    let b = &case.brief;
    // Hints off: only the questions are under test.
    send(json!({"type": "context_update", "settings": {"assist": "paused"}, "documents": documents,
        "brief": {"meeting_type": b.meeting_type, "title": b.title, "counterpart": b.counterpart, "company": b.company,
                  "goals": b.goals, "done": b.done, "not_done": b.not_done, "to_discuss": b.to_discuss, "instructions": b.instructions}}))
    .await;
    let mut asked = Vec::new();
    for index in 0..count {
        if let Some(mocks) = mocks {
            let scripted = match questions {
                Questions::Bad(n) if index == 0 => case.bad[n].clone(),
                _ => case.reference.get(index).cloned().unwrap_or_else(|| "What would you like to talk about next?".into()),
            };
            mocks[&ProviderKind::Anthropic].push(MockBehavior::Reply(scripted));
        }
        send(json!({"type": "practice_next"})).await;
        let message = next_of(&mut out, &["practice_question", "practice_done", "error"], Duration::from_secs(60)).await.ok_or("no question in time")?;
        if message["type"] != "practice_question" {
            return Err(format!("question {}: {message}", index + 1));
        }
        let question = message["text"].as_str().unwrap_or_default().to_owned();
        let known = known_from(case, &case.answers[..index.min(case.answers.len())]);
        asked.push((question.clone(), invented(&question, &known)));
        if let Some(answer) = case.answers.get(index) {
            send(json!({"type": "turn_final", "turn_id": format!("m-{index}"), "channel": "me", "text": answer, "language": "en"})).await;
        }
    }
    Ok(asked)
}

/// CI: every reference question passes, every bad question fails. Returns what is wrong.
pub async fn ci_check(cases: &[PracticeCase]) -> Vec<String> {
    let mut problems = Vec::new();
    if !cases.iter().any(|c| c.brief.title.trim().is_empty() && c.documents.is_empty()) {
        problems.push("no case with an empty brief".into());
    }
    for case in cases {
        let (state, mocks) = scripted_state();
        match run_case(&state, Some(&mocks), case, Questions::References, case.reference.len()).await {
            Ok(asked) => {
                for (question, invented) in asked.iter().filter(|(_, i)| !i.is_empty()) {
                    problems.push(format!("{}: the reference \"{question}\" invents {invented:?}", case.id));
                }
            }
            Err(error) => problems.push(format!("{}: {error}", case.id)),
        }
        for n in 0..case.bad.len() {
            let (state, mocks) = scripted_state();
            match run_case(&state, Some(&mocks), case, Questions::Bad(n), 1).await {
                Ok(asked) if asked.first().is_some_and(|(_, invented)| !invented.is_empty()) => {}
                Ok(_) => problems.push(format!("{}: the bad question \"{}\" passed", case.id, case.bad[n])),
                Err(error) => problems.push(format!("{}: {error}", case.id)),
            }
        }
    }
    problems
}

/// The model judge for practice questions in a live run (`--judge`).
pub type PracticeJudge = (Arc<dyn crate::provider::Provider>, String, toml::Table);

/// A live run: `count` questions per case from the real models, what the rules find invented, and with `judge` the
/// model judge's verdict on each question too.
pub async fn run_live(config: RelayConfig, providers: Providers, cases: &[PracticeCase], count: usize, judge: Option<&PracticeJudge>) -> String {
    let state = state(config, providers);
    let mut text = String::from(
        "\n## Practice questions\n\nEach question checked against what was known when it was asked (the brief, the documents, Me's \
         answers): numbers, names and bad things about Me that aren't there (rules), and, with the judge, anything else \
         it presents as a fact.\n\n| Case | Questions | Inventing (rules) | Inventing (judge) | Details |\n|---|---|---|---|---|\n",
    );
    for case in cases {
        match run_case(&state, None, case, Questions::Live, count).await {
            Ok(asked) => {
                let mut details = Vec::new();
                let rules = asked.iter().filter(|(_, i)| !i.is_empty()).count();
                for (question, invented) in asked.iter().filter(|(_, i)| !i.is_empty()) {
                    details.push(format!("rules: \"{question}\": {}", invented.join(", ")));
                }
                let mut judged = "not run".to_owned();
                if let Some((provider, model, options)) = judge {
                    let mut failed = 0;
                    for (index, (question, _)) in asked.iter().enumerate() {
                        let known = known_from(case, &case.answers[..index.min(case.answers.len())]);
                        let material = format!("{}\nThe question:\n{question}", known.text());
                        let (passed, verdict) = super::judge::ask(provider, model, options, super::judge::PRACTICE_RULES, material).await;
                        if !passed {
                            failed += 1;
                            details.push(format!("judge: \"{question}\": {verdict}"));
                        }
                    }
                    judged = failed.to_string();
                }
                let details = if details.is_empty() { "none".into() } else { details.join("<br>") };
                text.push_str(&format!("| {} | {} | {rules} | {judged} | {details} |\n", case.id, asked.len()));
            }
            Err(error) => text.push_str(&format!("| {} | - | - | - | {error} |\n", case.id)),
        }
    }
    text
}

#[cfg(test)]
mod tests {
    use super::*;

    #[tokio::test]
    async fn the_judge_reads_pass_and_fail() {
        let config = RelayConfig::built_in();
        let (providers, mocks) = Providers::mock(&config, Arc::new(MockStats::default()));
        let provider = providers.get(ProviderKind::Anthropic);
        let options = toml::Table::new();
        mocks[&ProviderKind::Anthropic].push(MockBehavior::Reply("PASS".into()));
        mocks[&ProviderKind::Anthropic].push(MockBehavior::Reply("FAIL: Careem is not in the brief".into()));
        let rules = super::super::judge::PRACTICE_RULES;
        let ask = |m: &str| super::super::judge::ask(&provider, "claude-sonnet-5-5", &options, rules, m.to_owned());
        assert_eq!(ask("Tell me about yourself?").await, (true, "PASS".to_owned()));
        let (passed, verdict) = ask("Careem let you go. Why?").await;
        assert!(!passed && verdict.contains("Careem"));
    }

    #[test]
    fn names_and_numbers_not_in_the_material_are_invented() {
        let mut known = Sources::default();
        known.add("Flutter developer at Nuqta Labs. Ghar Dekho app, 100,000 downloads.");
        assert!(invented("What was your part in the Ghar Dekho app at Nuqta Labs?", &known).is_empty());
        assert!(invented("Tell me about your time at Google?", &known).iter().any(|i| i.contains("Google")));
        assert!(invented("How did you lead a team of 12 developers?", &known).iter().any(|i| i.contains("12")));
        assert!(invented("Tell me about yourself. What do you enjoy in Flutter?", &known).is_empty());
        // A name at the start of a sentence, a salary in words, an invented negative.
        assert!(invented("Careem let you go. Why?", &known).iter().any(|i| i.contains("Careem")));
        assert!(invented("Sana, your manager, says you were late.", &known).iter().any(|i| i.contains("Sana")));
        assert!(!invented("Are you expecting fifty thousand a month?", &known).is_empty());
        assert!(!invented("Is a six-figure salary what you want?", &known).is_empty());
        assert!(invented("Why were you fired from Nuqta Labs?", &known).iter().any(|i| i.contains("fired")));
        assert!(invented("Tell me about a time you missed a deadline.", &known).is_empty(), "hypothetical");
    }
}
