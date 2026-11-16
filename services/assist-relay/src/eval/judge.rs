//! An optional model judge for "nothing invented": a claim can be invented without a number or a listed phrase, which
//! the rules can't see. Opt-in (`assist-relay-eval --live --judge --yes`), never in CI. It asks one model, per answer,
//! whether every claim about Me is supported by what was known at that point, and expects `PASS` or `FAIL: <claim>`.

use std::collections::BTreeMap;
use std::sync::Arc;

use futures_util::StreamExt;

use super::score::{CaseScore, Output};
use super::{Case, Event};
use crate::context::{Context, Role, Segment, SegmentKind};
use crate::provider::{CompletionRequest, Provider, Purpose, StreamEvent};
use crate::text::estimate_tokens;

const RULES: &str = "You check suggestions written for a person (Me) during a live call. You get what Me knew at that \
moment (the brief Me prepared, Me's documents, the facts ledger, and the call so far) and one suggestion. Decide \
whether the suggestion invents anything about Me: experience, skills, employers, clients, numbers, dates, results or \
promises that the known material does not support. Honest hedges (\"I can check and come back to you\"), polite \
phrases and questions to the other person are not inventions. Answer with exactly PASS, or FAIL: followed by the \
invented claim in a few words. Nothing else.";

#[derive(Debug, Clone, Default)]
pub struct JudgeTotals {
    pub passed: u32,
    pub total: u32,
    /// Turn and the judge's reason, for each FAIL.
    pub failures: Vec<String>,
    /// `transcript/turn` -> the judge passed it.
    pub verdicts: BTreeMap<String, bool>,
}

impl JudgeTotals {
    pub fn rate(&self) -> Option<f64> {
        (self.total > 0).then(|| f64::from(self.passed) / f64::from(self.total))
    }
}

/// What Me knew before `turn_id` was answered.
fn known_before(case: &Case, turn_id: &str) -> String {
    let brief = &case.brief;
    let mut known = format!(
        "Brief: {} | with {} ({}) | goals: {} | done: {} | not done: {} | to discuss: {} | Me's instructions: {}\n",
        brief.title, brief.counterpart, brief.company, brief.goals, brief.done, brief.not_done, brief.to_discuss, brief.instructions
    );
    for document in &case.documents {
        known.push_str(&format!("Document [{}]: {}\n", document.title, document.text));
    }
    known.push_str("The call so far:\n");
    for event in &case.events {
        match event {
            Event::Facts(facts) => known.push_str(&format!("(facts ledger: {})\n", facts.facts.join("; "))),
            Event::Turn(turn) => {
                let who = if turn.channel == crate::domain::Channel::Me { "Me" } else { "Them" };
                known.push_str(&format!("[{}] {who}: {}\n", turn.at, turn.text));
                if turn.id == turn_id {
                    break;
                }
            }
        }
    }
    known
}

/// Judges every answer of a scored transcript with `model` at `provider`.
pub async fn judge_case(provider: &Arc<dyn Provider>, model: &str, options: &toml::Table, case: &Case, score: &CaseScore) -> JudgeTotals {
    let mut totals = JudgeTotals::default();
    for (turn_id, output, _) in &score.turns {
        let Output::Answer(answer) = output else { continue };
        let question = format!("{}\nThe suggestion:\n{answer}", known_before(case, turn_id));
        let context = Context {
            segments: vec![
                Segment { kind: SegmentKind::Rules, role: Role::System, tokens: estimate_tokens(RULES), text: RULES.to_owned(), cache_after: true },
                Segment { kind: SegmentKind::Question, role: Role::User, tokens: estimate_tokens(&question), text: question, cache_after: false },
            ],
        };
        let request = CompletionRequest {
            model: model.to_owned(),
            context: Arc::new(context),
            max_output_tokens: 120,
            options: options.clone(),
            purpose: Purpose::Answer,
            question: String::new(),
        };
        let mut verdict = String::new();
        let mut stream = provider.stream(request);
        while let Some(item) = stream.next().await {
            match item {
                Ok(StreamEvent::Text(text)) => verdict.push_str(&text),
                Ok(StreamEvent::Usage(_)) => {}
                Err(error) => {
                    verdict = format!("FAIL: the judge could not answer ({error})");
                    break;
                }
            }
        }
        totals.total += 1;
        let passed = verdict.trim().eq_ignore_ascii_case("PASS");
        totals.verdicts.insert(format!("{}/{turn_id}", case.id), passed);
        if passed {
            totals.passed += 1;
        } else {
            totals.failures.push(format!("{}/{turn_id}: {}", case.id, verdict.trim()));
        }
    }
    totals
}
