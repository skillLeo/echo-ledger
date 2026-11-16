//! The golden eval set (plan L9): ten call transcripts with traps, replayed through a real relay session, and a
//! rule-based scorer.
//!
//! A transcript (`eval/transcripts/*.json`) is a brief, documents, and the call's events in order: final turns of
//! both channels, and facts-ledger updates as the server would send them. Each `other` turn says what is expected
//! (`answer`, `skip`, `any`, or `none` for a backchannel that starts nothing), may carry checks, a `reference` answer
//! that passes every check, and a `bad` answer with the categories it must fail. Every transcript has the three traps:
//! a figure given around minute 2 and asked again around minute 12, a repeated question, and a statement that needs no
//! reply.
//!
//! The scorer is rule-based (score.rs): anti-repeat, consistency with what was said, SKIP precision, the first line at
//! most 25 words, and nothing invented (numbers and listed claims). An optional model judge (judge.rs) adds a semantic
//! check for invented claims; it is opt-in and never runs in CI. `run.rs` replays transcripts through the relay: in CI
//! on the mocked models with the reference and the bad answers scripted (so the harness and the scorer are checked),
//! and for real with the live providers.

pub mod judge;
pub mod run;
pub mod score;

use std::collections::BTreeSet;
use std::path::Path;

use serde::Deserialize;

use crate::domain::{Channel, HintLevel, LanguageHelp, MeetingType};
use crate::protocol::{Brief, Document};
use crate::text::is_backchannel;

#[derive(Debug, Clone, Copy, PartialEq, Eq, PartialOrd, Ord, Deserialize)]
pub enum CaseLanguage {
    #[serde(rename = "en")]
    English,
    #[serde(rename = "ur-mixed")]
    UrduMixed,
    #[serde(rename = "ar-accent")]
    ArabicAccent,
}

#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct CaseSettings {
    pub hint_level: HintLevel,
    pub language_help: LanguageHelp,
}

#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Case {
    pub id: String,
    pub title: String,
    pub language: CaseLanguage,
    pub settings: CaseSettings,
    pub brief: Brief,
    #[serde(default)]
    pub documents: Vec<Document>,
    /// Claims that are not in the brief or the documents: an answer containing one invented it.
    #[serde(default)]
    pub invented: Vec<String>,
    pub events: Vec<Event>,
}

#[derive(Debug, Clone, Deserialize)]
#[serde(untagged)]
pub enum Event {
    Turn(Turn),
    Facts(FactsUpdate),
}

/// The facts ledger as the server would send it at this point (the whole list).
#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct FactsUpdate {
    pub at: String,
    pub facts: Vec<String>,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum Expect {
    Answer,
    Skip,
    /// Either is fine (not counted for SKIP precision).
    Any,
    /// A backchannel: no suggestion starts.
    None,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum Trap {
    /// A figure given around minute 2, asked again around minute 12.
    BudgetAgain,
    RepeatedQuestion,
    /// A statement that needs no reply (SKIP).
    SkipStatement,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, PartialOrd, Ord, Hash, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum Category {
    AntiRepeat,
    Consistency,
    Skip,
    FirstLine,
    NothingInvented,
}

impl Category {
    pub const ALL: [Category; 5] =
        [Category::AntiRepeat, Category::Consistency, Category::Skip, Category::FirstLine, Category::NothingInvented];

    pub fn label(self) -> &'static str {
        match self {
            Category::AntiRepeat => "anti-repeat",
            Category::Consistency => "consistency",
            Category::Skip => "SKIP",
            Category::FirstLine => "first line <= 25 words",
            Category::NothingInvented => "nothing invented",
        }
    }
}

#[derive(Debug, Clone, Default, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Check {
    /// At least one of these must be in the answer (the figure or fact said before).
    #[serde(default)]
    pub include_any: Vec<String>,
    /// None of these may be in the answer (a different figure).
    #[serde(default)]
    pub exclude: Vec<String>,
    /// The answer must not suggest asking about these (Them said them already).
    #[serde(default)]
    pub no_ask: Vec<String>,
}

#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Bad {
    pub answer: String,
    pub fails: Vec<Category>,
}

#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Turn {
    pub id: String,
    pub channel: Channel,
    pub at: String,
    pub text: String,
    #[serde(default)]
    pub expect: Option<Expect>,
    #[serde(default)]
    pub trap: Option<Trap>,
    #[serde(default)]
    pub check: Check,
    #[serde(default)]
    pub reference: Option<String>,
    /// One bad answer, or several: CI scripts each in turn and checks it fails exactly what it names.
    #[serde(default, deserialize_with = "one_or_many")]
    pub bad: Vec<Bad>,
}

fn one_or_many<'de, D: serde::Deserializer<'de>>(deserializer: D) -> Result<Vec<Bad>, D::Error> {
    #[derive(Deserialize)]
    #[serde(untagged)]
    enum OneOrMany {
        One(Bad),
        Many(Vec<Bad>),
    }
    Ok(match OneOrMany::deserialize(deserializer)? {
        OneOrMany::One(bad) => vec![bad],
        OneOrMany::Many(bads) => bads,
    })
}

impl Turn {
    /// What an `other` turn expects (`answer` unless it says otherwise); `me` turns expect nothing.
    pub fn expect(&self) -> Expect {
        match self.channel {
            Channel::Me => Expect::None,
            Channel::Other => self.expect.unwrap_or(Expect::Answer),
        }
    }
}

/// `mm:ss` in milliseconds.
pub fn at_ms(at: &str) -> Option<u64> {
    let (minutes, seconds) = at.split_once(':')?;
    Some((minutes.parse::<u64>().ok()? * 60 + seconds.parse::<u64>().ok()?) * 1000)
}

/// Every `*.json` in `dir`, by file name.
pub fn load_dir(dir: &Path) -> Result<Vec<Case>, String> {
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

/// Names that mark a transcript as a placeholder rather than a realistic call.
const PLACEHOLDERS: [&str; 12] =
    ["john doe", "jane doe", "acme", "foo", "lorem", "example corp", "xyz", "abc company", "test user", "company x", "client a", "placeholder"];

/// Checks the set against the plan: ten transcripts over the four meeting types and three languages, each with the
/// three traps, sound times and expectations, and a reference answer wherever there is something to check.
pub fn validate(cases: &[Case]) -> Vec<String> {
    let mut problems = Vec::new();
    if cases.len() != 10 {
        problems.push(format!("{} transcripts, the plan asks for 10", cases.len()));
    }
    let ids: BTreeSet<&str> = cases.iter().map(|c| c.id.as_str()).collect();
    if ids.len() != cases.len() {
        problems.push("transcript ids are not unique".into());
    }
    for meeting in [MeetingType::Interview, MeetingType::Boss, MeetingType::ExistingClient, MeetingType::NewClient] {
        if !cases.iter().any(|c| c.brief.meeting_type == meeting) {
            problems.push(format!("no {meeting:?} transcript"));
        }
    }
    let languages: BTreeSet<CaseLanguage> = cases.iter().map(|c| c.language).collect();
    if languages.len() != 3 {
        problems.push(format!("languages covered: {languages:?}"));
    }
    for case in cases {
        problems.extend(validate_case(case).into_iter().map(|p| format!("{}: {p}", case.id)));
    }
    problems
}

fn validate_case(case: &Case) -> Vec<String> {
    let mut problems = Vec::new();
    let mut last = 0;
    let mut turn_ids = BTreeSet::new();
    let mut traps = Vec::new();
    let all_text = serde_json::to_string(&case.events.iter().map(event_text).collect::<Vec<_>>()).unwrap_or_default().to_lowercase()
        + &case.brief.title.to_lowercase()
        + &case.brief.counterpart.to_lowercase()
        + &case.brief.company.to_lowercase();
    for name in PLACEHOLDERS {
        if contains_word(&all_text, name) {
            problems.push(format!("placeholder name \"{name}\""));
        }
    }
    for event in &case.events {
        let at = match event {
            Event::Turn(turn) => &turn.at,
            Event::Facts(facts) => &facts.at,
        };
        match at_ms(at) {
            Some(ms) if ms >= last => last = ms,
            _ => problems.push(format!("time {at} is not mm:ss in order")),
        }
        let Event::Turn(turn) = event else { continue };
        if !turn_ids.insert(turn.id.as_str()) {
            problems.push(format!("turn id {} repeats", turn.id));
        }
        let expect = turn.expect();
        if turn.channel == Channel::Me && turn.expect.is_some() {
            problems.push(format!("{}: only other turns expect something", turn.id));
        }
        if turn.channel == Channel::Other && (expect == Expect::None) != is_backchannel(&turn.text) {
            problems.push(format!("{}: `none` exactly for backchannels", turn.id));
        }
        let checked = !turn.check.include_any.is_empty() || !turn.check.exclude.is_empty() || !turn.check.no_ask.is_empty();
        if (checked || turn.trap.is_some() || !turn.bad.is_empty()) && turn.reference.is_none() {
            problems.push(format!("{}: a checked turn needs a reference answer", turn.id));
        }
        if let Some(trap) = turn.trap {
            traps.push((trap, at_ms(&turn.at).unwrap_or(0), turn));
        }
        if turn.bad.iter().any(|bad| bad.fails.is_empty()) {
            problems.push(format!("{}: a bad answer must name what it fails", turn.id));
        }
    }
    for trap in [Trap::BudgetAgain, Trap::RepeatedQuestion, Trap::SkipStatement] {
        if !traps.iter().any(|(t, _, _)| *t == trap) {
            problems.push(format!("no {trap:?} trap"));
        }
    }
    for (trap, at, turn) in &traps {
        match trap {
            Trap::BudgetAgain => {
                if !(600_000..=900_000).contains(at) {
                    problems.push(format!("{}: the figure is asked again at {}, not around minute 12", turn.id, turn.at));
                }
                let early = case.events.iter().any(|event| {
                    let (at, text) = match event {
                        Event::Turn(t) => (at_ms(&t.at).unwrap_or(u64::MAX), t.text.to_lowercase()),
                        Event::Facts(f) => (at_ms(&f.at).unwrap_or(u64::MAX), f.facts.join(" ").to_lowercase()),
                    };
                    at <= 210_000 && turn.check.include_any.iter().any(|term| text.contains(&term.to_lowercase()))
                });
                if !early {
                    problems.push(format!("{}: the figure it asks for is not given by minute 3", turn.id));
                }
            }
            Trap::SkipStatement if turn.expect() != Expect::Skip => problems.push(format!("{}: a skip trap must expect skip", turn.id)),
            _ => {}
        }
    }
    problems
}

fn event_text(event: &Event) -> String {
    match event {
        Event::Turn(turn) => format!("{} {}", turn.text, turn.reference.as_deref().unwrap_or_default()),
        Event::Facts(facts) => facts.facts.join(" "),
    }
}

/// `needle` in `haystack` with no letter or digit right before or after it.
pub fn contains_word(haystack: &str, needle: &str) -> bool {
    let mut start = 0;
    while let Some(found) = haystack[start..].find(needle) {
        let begin = start + found;
        let end = begin + needle.len();
        let before = haystack[..begin].chars().next_back().is_none_or(|c| !c.is_alphanumeric());
        let after = haystack[end..].chars().next().is_none_or(|c| !c.is_alphanumeric());
        if before && after {
            return true;
        }
        start = begin + needle.len().max(1);
        while !haystack.is_char_boundary(start) {
            start += 1;
        }
    }
    false
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn times_and_words() {
        assert_eq!(at_ms("12:10"), Some(730_000));
        assert_eq!(at_ms("12"), None);
        assert!(contains_word("ask about on-call here", "on-call"));
        assert!(!contains_word("acmeal", "acme"));
        assert!(contains_word("the acme deal", "acme"));
    }
}
