//! The rule-based scorer. For each `other` turn and the suggestion the relay ended with on the card:
//!
//! - **SKIP:** a `skip` turn must get SKIP; an `answer` turn must not. (`any` turns are not counted.)
//! - **First line:** an answer's first line has at most 25 words.
//! - **Nothing invented:** every number in an answer appears in what is known at that point (the brief, the documents,
//!   the facts ledger, the call so far; number words count, and `4.5 lakh` equals `450,000`), and no claim from the
//!   transcript's `invented` list appears.
//! - **Consistency:** a checked turn's answer contains one of `include_any` and none of `exclude`.
//! - **Anti-repeat:** no question in the answer (a sentence ending in `?`, the `Say next:` line included) asks about a
//!   `no_ask` term: something Them already said.

use std::collections::BTreeMap;

use super::{Case, Category, Event, Expect, Turn, contains_word};

/// What the relay ended with for a turn.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Output {
    /// The text on the card at the end (the upgrade's, when one replaced the draft).
    Answer(String),
    Skip,
    /// No suggestion started.
    Nothing,
    /// The suggestion ended in an error (or the harness gave up waiting).
    Failed(String),
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct CheckResult {
    pub category: Category,
    pub passed: bool,
    pub detail: String,
}

/// What was known when a turn was answered.
#[derive(Debug, Clone, Default)]
pub struct Sources {
    text: String,
    numbers: Vec<f64>,
}

impl Sources {
    pub fn add(&mut self, text: &str) {
        self.text.push_str(&text.to_lowercase());
        self.text.push('\n');
        self.numbers.extend(numbers(text));
        self.numbers.extend(word_numbers(text));
    }

    fn knows(&self, value: f64) -> bool {
        self.numbers.iter().any(|known| (known - value).abs() <= 1e-9 * known.abs().max(1.0))
    }
}

/// The brief and the documents: known from the start.
pub fn base_sources(case: &Case) -> Sources {
    let mut sources = Sources::default();
    let brief = &case.brief;
    for field in [&brief.title, &brief.counterpart, &brief.company, &brief.goals, &brief.done, &brief.not_done, &brief.to_discuss, &brief.instructions] {
        sources.add(field);
    }
    for document in &case.documents {
        sources.add(&document.title);
        sources.add(&document.text);
    }
    sources
}

/// Numbers written with digits: `450,000`, `4.6`, `18%`, `£1,200`, `$16,500`, `4.5 lakh`, `35 thousand`, `1.5 million`,
/// `450k`; Indian grouping (`15,00,000`) too.
pub fn numbers(text: &str) -> Vec<f64> {
    let chars: Vec<char> = text.chars().collect();
    let mut found = Vec::new();
    let mut i = 0;
    while i < chars.len() {
        if !chars[i].is_ascii_digit() || (i > 0 && (chars[i - 1].is_ascii_digit() || chars[i - 1] == '.')) {
            i += 1;
            continue;
        }
        let start = i;
        while i < chars.len() && (chars[i].is_ascii_digit() || ((chars[i] == ',' || chars[i] == '.') && chars.get(i + 1).is_some_and(char::is_ascii_digit))) {
            i += 1;
        }
        let digits: String = chars[start..i].iter().filter(|c| **c != ',').collect();
        let Ok(mut value) = digits.parse::<f64>() else { continue };
        // A unit straight after (450k) or after one space (4.5 lakh).
        let mut j = i;
        if chars.get(j) == Some(&' ') {
            j += 1;
        }
        let word: String = chars[j..].iter().take_while(|c| c.is_alphabetic()).collect::<String>().to_lowercase();
        let glued = j == i;
        value *= match word.as_str() {
            "k" if glued => 1_000.0,
            "thousand" => 1_000.0,
            "lakh" | "lac" | "lakhs" => 100_000.0,
            "million" | "mn" => 1_000_000.0,
            "m" if glued => 1_000_000.0,
            _ => 1.0,
        };
        found.push(value);
    }
    found
}

/// English number words, and Roman Urdu ones that are not also common English words ("do", "teen" and "char" would
/// make 2, 3 and 4 known in almost any sentence).
const WORDS: [(&str, f64); 23] = [
    ("one", 1.0), ("two", 2.0), ("three", 3.0), ("four", 4.0), ("five", 5.0), ("six", 6.0), ("seven", 7.0),
    ("eight", 8.0), ("nine", 9.0), ("ten", 10.0), ("eleven", 11.0), ("twelve", 12.0), ("fifteen", 15.0),
    ("sixteen", 16.0), ("eighteen", 18.0), ("twenty", 20.0), ("thirty", 30.0), ("forty", 40.0), ("fifty", 50.0),
    ("sixty", 60.0), ("seventy", 70.0), ("hundred", 100.0), ("pandrah", 15.0),
];

/// Numbers written as words, alone or before thousand / lakh / million.
fn word_numbers(text: &str) -> Vec<f64> {
    let words: Vec<String> = text.split(|c: char| !c.is_alphanumeric()).filter(|w| !w.is_empty()).map(str::to_lowercase).collect();
    let mut found = Vec::new();
    for (index, word) in words.iter().enumerate() {
        if let Some((_, value)) = WORDS.iter().find(|(name, _)| name == word) {
            found.push(*value);
            match words.get(index + 1).map(String::as_str) {
                Some("thousand" | "hazar") => found.push(value * 1_000.0),
                Some("lakh") => found.push(value * 100_000.0),
                Some("million") => found.push(value * 1_000_000.0),
                _ => {}
            }
        }
    }
    found
}

/// The questions an answer suggests asking: sentences that end in `?`.
pub fn questions(text: &str) -> Vec<String> {
    let mut found = Vec::new();
    let mut current = String::new();
    for c in text.chars() {
        match c {
            '?' | '؟' => {
                found.push(std::mem::take(&mut current).trim().to_lowercase());
            }
            '.' | '!' | '\n' => current.clear(),
            _ => current.push(c),
        }
    }
    found
}

fn first_line(text: &str) -> &str {
    text.lines().map(str::trim).find(|line| !line.is_empty()).unwrap_or_default()
}

fn result(category: Category, passed: bool, detail: impl Into<String>) -> CheckResult {
    CheckResult { category, passed, detail: if passed { String::new() } else { detail.into() } }
}

/// The checks for one `other` turn's output.
pub fn score_turn(case: &Case, turn: &Turn, output: &Output, sources: &Sources) -> Vec<CheckResult> {
    let expect = turn.expect();
    let mut results = Vec::new();
    // SKIP precision and recall.
    match (expect, output) {
        (Expect::Skip, Output::Skip) | (Expect::Answer, Output::Answer(_)) => results.push(result(Category::Skip, true, "")),
        (Expect::Skip, _) => results.push(result(Category::Skip, false, "needed no reply, got an answer")),
        (Expect::Answer, Output::Skip) => results.push(result(Category::Skip, false, "needed an answer, got SKIP")),
        _ => {}
    }
    let checked = !turn.check.include_any.is_empty() || !turn.check.exclude.is_empty();
    let Output::Answer(answer) = output else {
        if checked {
            results.push(result(Category::Consistency, false, "no answer"));
        }
        if !turn.check.no_ask.is_empty() {
            results.push(result(Category::AntiRepeat, false, "no answer"));
        }
        return results;
    };
    let lower = answer.to_lowercase();
    let words = first_line(answer).split_whitespace().count();
    results.push(result(Category::FirstLine, words <= 25, format!("first line has {words} words")));

    let unknown: Vec<String> = numbers(answer).into_iter().filter(|n| !sources.knows(*n)).map(|n| n.to_string()).collect();
    let claims: Vec<&String> = case.invented.iter().filter(|claim| lower.contains(&claim.to_lowercase())).collect();
    let mut invented = Vec::new();
    if !unknown.is_empty() {
        invented.push(format!("numbers not in what is known: {}", unknown.join(", ")));
    }
    if !claims.is_empty() {
        invented.push(format!("invented: {}", claims.iter().map(|c| c.as_str()).collect::<Vec<_>>().join(", ")));
    }
    results.push(result(Category::NothingInvented, invented.is_empty(), invented.join("; ")));

    if checked {
        let has = |term: &String| lower.contains(&term.to_lowercase());
        let included = turn.check.include_any.is_empty() || turn.check.include_any.iter().any(has);
        let wrong: Vec<&String> = turn.check.exclude.iter().filter(|term| has(term)).collect();
        let mut detail = Vec::new();
        if !included {
            detail.push(format!("none of {:?}", turn.check.include_any));
        }
        if !wrong.is_empty() {
            detail.push(format!("contains {wrong:?}"));
        }
        results.push(result(Category::Consistency, detail.is_empty(), detail.join("; ")));
    }
    if !turn.check.no_ask.is_empty() {
        let repeated: Vec<String> = questions(answer)
            .into_iter()
            .filter(|question| turn.check.no_ask.iter().any(|term| contains_word(question, &term.to_lowercase())))
            .collect();
        results.push(result(Category::AntiRepeat, repeated.is_empty(), format!("asks again: {repeated:?}")));
    }
    results
}

/// One transcript's results, turn by turn.
#[derive(Debug, Clone, Default)]
pub struct CaseScore {
    pub id: String,
    pub turns: Vec<(String, Output, Vec<CheckResult>)>,
}

/// Scores a run: `outputs` holds each `other` turn's output by turn id.
pub fn score_case(case: &Case, outputs: &BTreeMap<String, Output>) -> CaseScore {
    let mut sources = base_sources(case);
    let mut score = CaseScore { id: case.id.clone(), turns: Vec::new() };
    for event in &case.events {
        match event {
            Event::Facts(facts) => facts.facts.iter().for_each(|fact| sources.add(fact)),
            Event::Turn(turn) => {
                sources.add(&turn.text);
                if turn.expect() == Expect::None {
                    continue;
                }
                let output = outputs.get(&turn.id).cloned().unwrap_or(Output::Failed("not run".into()));
                let results = score_turn(case, turn, &output, &sources);
                score.turns.push((turn.id.clone(), output, results));
            }
        }
    }
    score
}

/// Passes and totals per category, over any number of transcripts.
#[derive(Debug, Clone, Default)]
pub struct Totals {
    pub by_category: BTreeMap<Category, (u32, u32)>,
    /// SKIPs given where none was needed, SKIPs given, `skip` turns, `skip` turns that got SKIP.
    pub skip_counts: (u32, u32, u32, u32),
    pub failed_turns: u32,
}

impl Totals {
    pub fn add(&mut self, score: &CaseScore, case: &Case) {
        for (turn_id, output, results) in &score.turns {
            for r in results {
                let entry = self.by_category.entry(r.category).or_default();
                entry.1 += 1;
                if r.passed {
                    entry.0 += 1;
                }
            }
            let expect = case.events.iter().find_map(|e| match e {
                Event::Turn(t) if &t.id == turn_id => Some(t.expect()),
                _ => None,
            });
            match (expect, output) {
                (Some(Expect::Skip), Output::Skip) => {
                    self.skip_counts.1 += 1;
                    self.skip_counts.2 += 1;
                    self.skip_counts.3 += 1;
                }
                (Some(Expect::Skip), _) => self.skip_counts.2 += 1,
                (Some(Expect::Answer), Output::Skip) => {
                    self.skip_counts.0 += 1;
                    self.skip_counts.1 += 1;
                }
                (Some(Expect::Any), Output::Skip) => self.skip_counts.1 += 1,
                _ => {}
            }
            if matches!(output, Output::Failed(_) | Output::Nothing) {
                self.failed_turns += 1;
            }
        }
    }

    pub fn rate(&self, category: Category) -> Option<f64> {
        self.by_category.get(&category).filter(|(_, total)| *total > 0).map(|(passed, total)| f64::from(*passed) / f64::from(*total))
    }

    /// The mean of the category rates: the number held against the plan's 90 %.
    pub fn overall(&self) -> f64 {
        let rates: Vec<f64> = Category::ALL.iter().filter_map(|c| self.rate(*c)).collect();
        if rates.is_empty() { 0.0 } else { rates.iter().sum::<f64>() / rates.len() as f64 }
    }

    /// SKIP precision: SKIPs where one was needed, over all SKIPs on turns with an expectation.
    pub fn skip_precision(&self) -> Option<f64> {
        let (wrong, given, _, _) = self.skip_counts;
        (given > 0).then(|| f64::from(given - wrong) / f64::from(given))
    }

    pub fn skip_recall(&self) -> Option<f64> {
        let (_, _, needed, got) = self.skip_counts;
        (needed > 0).then(|| f64::from(got) / f64::from(needed))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn numbers_are_read_in_every_usual_form() {
        assert_eq!(numbers("450,000 rupees, 4.5 lakh, 450k"), vec![450_000.0, 450_000.0, 450_000.0]);
        assert_eq!(numbers("£1,200 and $16,500, 18%, rating 4.6."), vec![1_200.0, 16_500.0, 18.0, 4.6]);
        assert_eq!(numbers("15,00,000 or 1.5 million or 35 thousand"), vec![1_500_000.0, 1_500_000.0, 35_000.0]);
        assert_eq!(numbers("2021-2025, the 28th, PHP 8"), vec![2021.0, 2025.0, 28.0, 8.0]);
        assert!(numbers("no digits here").is_empty());
        let mut known = Sources::default();
        known.add("Notice period: one month. Budget 15 lakh tak. Sixteen thousand dirhams.");
        assert!(known.knows(1.0) && known.knows(1_500_000.0) && known.knows(16_000.0));
        assert!(!known.knows(2.0));
    }

    #[test]
    fn questions_are_the_sentences_that_ask() {
        let text = "Yes, two quick ones.\n- **Team**: who would I work with?\nSay next: How does on-call work here?";
        assert_eq!(questions(text), vec!["- **team**: who would i work with", "say next: how does on-call work here"]);
    }
}
