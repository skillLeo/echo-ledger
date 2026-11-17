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
    /// Not failed by the rules. With `unchecked`, only as far as the rules can tell: an upper bound.
    pub passed: bool,
    /// The answer makes a claim the rules can't check (a first-person experience claim with words not in what is
    /// known): only the model judge can decide it.
    pub unchecked: bool,
    pub detail: String,
}

/// What was known when a turn was answered.
#[derive(Debug, Clone, Default)]
pub struct Sources {
    text: String,
    quantities: Vec<Quantity>,
}

impl Sources {
    pub fn add(&mut self, text: &str) {
        self.text.push_str(&text.to_lowercase().replace('\u{2019}', "'"));
        self.text.push('\n');
        self.quantities.extend(quantities(text, true));
    }

    /// Whether a quantity in an answer is known: the same value; with a unit, the same unit (and the same rate where
    /// both say one: "a day" is not "a month"); without one, any mention of the value.
    pub fn knows(&self, q: &Quantity) -> bool {
        self.quantities.iter().any(|known| {
            (known.value - q.value).abs() <= 1e-9 * known.value.abs().max(1.0)
                && match q.unit {
                    None => true,
                    Some(unit) => known.unit == Some(unit) && (q.rate.is_none() || known.rate.is_none() || known.rate == q.rate),
                }
        })
    }

    /// Whether a word (or its singular) is anywhere in what is known.
    fn has_word(&self, word: &str) -> bool {
        contains_word(&self.text, word) || word.strip_suffix('s').is_some_and(|stem| stem.len() > 2 && contains_word(&self.text, stem))
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

/// Arabic-Indic (`٠`-`٩`, Arabic) and Extended Arabic-Indic (`۰`-`۹`, Urdu and Persian) digits, and the Arabic decimal
/// and thousands marks, as ASCII.
pub fn ascii_digits(text: &str) -> String {
    text.chars()
        .map(|c| match c {
            '\u{0660}'..='\u{0669}' => char::from(b'0' + (c as u32 - 0x0660) as u8),
            '\u{06F0}'..='\u{06F9}' => char::from(b'0' + (c as u32 - 0x06F0) as u8),
            '\u{066B}' => '.',
            '\u{066C}' => ',',
            other => other,
        })
        .collect()
}

/// A number as it is used: its value, what it counts (a unit, folded to one name per meaning), and how often ("a
/// day", "per month", "monthly").
#[derive(Debug, Clone, Copy, PartialEq)]
pub struct Quantity {
    pub value: f64,
    pub unit: Option<&'static str>,
    pub rate: Option<&'static str>,
}

#[derive(Debug, Clone, PartialEq)]
enum Token {
    Word(String),
    Number { value: f64, currency: Option<&'static str>, percent: bool },
    /// A sentence or clause boundary: a unit or a rate is never read across it.
    Stop,
}

fn tokenize(text: &str) -> Vec<Token> {
    let chars: Vec<char> = ascii_digits(text).replace('\u{2019}', "'").chars().collect();
    let mut tokens = Vec::new();
    let mut i = 0;
    while i < chars.len() {
        let c = chars[i];
        if c.is_ascii_digit() {
            let start = i;
            while i < chars.len() && (chars[i].is_ascii_digit() || ((chars[i] == ',' || chars[i] == '.') && chars.get(i + 1).is_some_and(char::is_ascii_digit))) {
                i += 1;
            }
            let digits: String = chars[start..i].iter().filter(|c| **c != ',').collect();
            let Ok(mut value) = digits.parse::<f64>() else { continue };
            let currency = match start.checked_sub(1).map(|j| chars[j]) {
                Some('$') => Some("usd"),
                Some('£') => Some("gbp"),
                Some('€') => Some("eur"),
                _ => None,
            };
            // 450k, 2m: a scale glued to the digits.
            if let Some(next) = chars.get(i).map(|c| c.to_ascii_lowercase())
                && (next == 'k' || next == 'm')
                && !chars.get(i + 1).is_some_and(|c| c.is_alphanumeric())
            {
                value *= if next == 'k' { 1_000.0 } else { 1_000_000.0 };
                i += 1;
            }
            let percent = chars.get(i) == Some(&'%') || (chars.get(i) == Some(&' ') && chars.get(i + 1) == Some(&'%'));
            tokens.push(Token::Number { value, currency, percent });
        } else if c.is_alphanumeric() || c == '\'' {
            let start = i;
            while i < chars.len() && (chars[i].is_alphanumeric() || chars[i] == '\'') {
                i += 1;
            }
            let word: String = chars[start..i].iter().collect::<String>().to_lowercase();
            tokens.push(Token::Word(word.trim_matches('\'').to_owned()));
        } else {
            if matches!(c, '.' | '!' | '?' | '\u{061F}' | ':' | ';' | ',' | '\n' | '\u{06D4}' | '\u{060C}' | '(' | ')') {
                tokens.push(Token::Stop);
            }
            i += 1;
        }
    }
    tokens
}

/// A unit word folded to one name per meaning (plural and singular, English and Roman Urdu, currency names and codes).
fn unit_of(word: &str) -> Option<&'static str> {
    Some(match word {
        "year" | "years" | "yr" | "yrs" | "saal" => "year",
        "month" | "months" | "mahine" | "mahina" | "mahinay" => "month",
        "week" | "weeks" | "hafte" | "hafta" => "week",
        "day" | "days" | "din" => "day",
        "hour" | "hours" | "hrs" | "ghante" | "ghanta" => "hour",
        "minute" | "minutes" | "mins" => "minute",
        "time" | "times" => "time",
        "percent" | "per cent" | "fisad" => "percent",
        "rupee" | "rupees" | "rupay" | "rs" | "pkr" => "pkr",
        "dollar" | "dollars" | "usd" => "usd",
        "pound" | "pounds" | "gbp" => "gbp",
        "riyal" | "riyals" | "sar" | "qar" => "riyal",
        "dirham" | "dirhams" | "aed" => "aed",
        "people" | "person" | "persons" => "person",
        "developer" | "developers" => "developer",
        "engineer" | "engineers" => "engineer",
        "designer" | "designers" => "designer",
        "tester" | "testers" => "tester",
        "member" | "members" | "staff" | "employee" | "employees" => "member",
        "project" | "projects" => "project",
        "app" | "apps" => "app",
        "client" | "clients" | "customer" | "customers" => "client",
        "user" | "users" => "user",
        "download" | "downloads" => "download",
        "manager" | "managers" => "manager",
        "branch" | "branches" => "branch",
        "mill" | "mills" => "mill",
        "warehouse" | "warehouses" | "godam" => "warehouse",
        "dealer" | "dealers" => "dealer",
        "order" | "orders" => "order",
        "payout" | "payouts" => "payout",
        "visit" | "visits" => "visit",
        "case" | "cases" => "case",
        "store" | "stores" | "restaurant" | "restaurants" => "store",
        "city" | "cities" => "city",
        "country" | "countries" => "country",
        _ => return None,
    })
}

/// How often: "daily", "a month", "per week", "har mahine", "mahana".
fn rate_after(tokens: &[Token]) -> Option<&'static str> {
    let words: Vec<&str> = tokens
        .iter()
        .take_while(|t| **t != Token::Stop)
        .take(6)
        .filter_map(|t| match t {
            Token::Word(w) => Some(w.as_str()),
            _ => None,
        })
        .collect();
    for (index, word) in words.iter().enumerate() {
        match *word {
            "daily" | "rozana" => return Some("day"),
            "weekly" => return Some("week"),
            "monthly" | "mahana" => return Some("month"),
            "yearly" | "annually" | "annual" | "salana" => return Some("year"),
            "hourly" => return Some("hour"),
            "a" | "an" | "per" | "every" | "each" | "har" | "fi" => {
                if let Some(unit) = words.get(index + 1).and_then(|w| unit_of(w)).filter(|u| ["day", "week", "month", "year", "hour"].contains(u)) {
                    return Some(unit);
                }
            }
            _ => {}
        }
    }
    None
}

/// Words that stand between a number and something it does not count.
const LINKS: [&str; 22] = [
    "a", "an", "per", "every", "each", "har", "fi", "for", "of", "in", "on", "at", "to", "from", "with", "by", "the",
    "and", "or", "se", "ke", "ki",
];

const SCALES: [(&str, f64); 7] =
    [("thousand", 1_000.0), ("hazar", 1_000.0), ("lakh", 100_000.0), ("lac", 100_000.0), ("lakhs", 100_000.0), ("million", 1_000_000.0), ("crore", 10_000_000.0)];

/// English number words.
const WORDS: [(&str, f64); 25] = [
    ("one", 1.0), ("two", 2.0), ("three", 3.0), ("four", 4.0), ("five", 5.0), ("six", 6.0), ("seven", 7.0),
    ("eight", 8.0), ("nine", 9.0), ("ten", 10.0), ("eleven", 11.0), ("twelve", 12.0), ("fifteen", 15.0),
    ("sixteen", 16.0), ("eighteen", 18.0), ("twenty", 20.0), ("thirty", 30.0), ("forty", 40.0), ("fifty", 50.0),
    ("sixty", 60.0), ("seventy", 70.0), ("eighty", 80.0), ("ninety", 90.0), ("hundred", 100.0), ("dozen", 12.0),
];

/// Roman Urdu number words. Several are also English words ("do", "teen", "char"), so they count only before a unit.
const URDU_WORDS: [(&str, f64); 14] = [
    ("ek", 1.0), ("do", 2.0), ("teen", 3.0), ("char", 4.0), ("chaar", 4.0), ("paanch", 5.0), ("chhe", 6.0),
    ("saat", 7.0), ("aath", 8.0), ("nau", 9.0), ("das", 10.0), ("pandrah", 15.0), ("bees", 20.0), ("pachas", 50.0),
];

/// The quantities in a text. A number's unit is the first unit word among the three words after it (after any scale
/// word: 4.5 lakh, 10.2 million), or its currency sign or code. Number words count when a unit follows them (in
/// `known` text, English number words count on their own too).
pub fn quantities(text: &str, known: bool) -> Vec<Quantity> {
    let tokens = tokenize(text);
    let mut found = Vec::new();
    for (index, token) in tokens.iter().enumerate() {
        let (mut value, currency, percent, is_word, urdu) = match token {
            Token::Number { value, currency, percent } => (*value, *currency, *percent, false, false),
            Token::Word(word) => match (WORDS.iter().find(|(w, _)| w == word), URDU_WORDS.iter().find(|(w, _)| w == word)) {
                (Some((_, v)), _) => (*v, None, false, true, false),
                (None, Some((_, v))) => (*v, None, false, true, true),
                _ => continue,
            },
            Token::Stop => continue,
        };
        let mut rest = &tokens[index + 1..];
        if let Some(Token::Word(word)) = rest.first()
            && let Some((_, scale)) = SCALES.iter().find(|(w, _)| w == word)
        {
            value *= scale;
            rest = &rest[1..];
        }
        let before = index.checked_sub(1).and_then(|j| match &tokens[j] {
            Token::Word(w) => unit_of(w).filter(|u| ["pkr", "usd", "gbp", "riyal", "aed"].contains(u)),
            _ => None,
        });
        // The unit follows the number directly, or after one describing word ("12 city managers", "2 Flutter
        // developers"); a preposition or a rate word ends the search ("one key per payout", "PHP 8 every day", "two
        // for restaurants").
        // The last unit word of the two is the thing counted ("12 city managers").
        let after = rest
            .iter()
            .take_while(|t| matches!(t, Token::Word(w) if !LINKS.contains(&w.as_str())))
            .take(2)
            .filter_map(|t| match t {
                Token::Word(w) => unit_of(w),
                _ => None,
            })
            .last();
        let unit = if percent { Some("percent") } else { after.or(currency).or(before) };
        if is_word && unit.is_none() && (urdu || !known) {
            continue;
        }
        found.push(Quantity { value, unit, rate: rate_after(rest) });
    }
    found
}

/// The values of the numbers in a text (for tests and reports).
pub fn numbers(text: &str) -> Vec<f64> {
    quantities(text, false).into_iter().map(|q| q.value).collect()
}

/// Words that make a sentence a request to Them: asking, confirming, checking, inviting them to share (English, Roman
/// Urdu and Arabic, where a question often comes without its mark).
const REQUEST_WORDS: [&str; 42] = [
    "ask", "asking", "confirm", "reconfirm", "check", "double-check", "clarify", "verify", "find out", "request",
    "remind me", "tell me", "let me know", "share", "know", "hear more", "can you", "could you", "would you", "please",
    "it would help to know", "pooch", "poochain", "poochein", "poochiye", "poochna", "puchain", "puchiye", "bata dein",
    "batayein", "bataiye", "batain", "bataen", "kitna", "kitne", "kitni", "\u{0647}\u{0644}", "\u{0645}\u{0627}",
    "\u{0643}\u{0645}", "\u{0623}\u{062E}\u{0628}\u{0631}\u{0646}\u{064A}", "\u{0645}\u{0627}\u{0630}\u{0627}", "\u{0645}\u{062A}\u{0649}",
];

/// What an answer asks of Them: every sentence that ends in `?`, and every sentence with a request word ("Ask them
/// their budget again.", "Confirm the salary figure with them."). Sentences end at `.`, `!`, `?`, a line break or a
/// colon, so "Say next: …" is its own sentence.
pub fn requests(text: &str) -> Vec<String> {
    let mut found = Vec::new();
    let mut current = String::new();
    let mut push = |sentence: &mut String, asked: bool| {
        let lower = sentence.trim().to_lowercase();
        if !lower.is_empty() && (asked || REQUEST_WORDS.iter().any(|word| contains_word(&lower, word))) {
            found.push(lower);
        }
        sentence.clear();
    };
    for c in text.chars() {
        match c {
            '?' | '\u{061F}' => push(&mut current, true),
            '.' | '!' | '\n' | ':' | '\u{06D4}' => push(&mut current, false),
            _ => current.push(c),
        }
    }
    push(&mut current, false);
    found
}

fn first_line(text: &str) -> &str {
    text.lines().map(str::trim).find(|line| !line.is_empty()).unwrap_or_default()
}

fn result(category: Category, passed: bool, detail: impl Into<String>) -> CheckResult {
    CheckResult { category, passed, unchecked: false, detail: if passed { String::new() } else { detail.into() } }
}

/// First-person claims about experience: the sentences the rules can't verify unless every content word is known.
const CLAIM_MARKERS: [&str; 34] = [
    "i've used", "i have used", "i used", "i've worked", "i have worked", "i worked", "i've built", "i have built",
    "i built", "i've led", "i led", "i've managed", "i managed", "i've done", "i have done", "i know", "i have experience",
    "my experience", "i've shipped", "i shipped", "we've built", "we have built", "we built", "we've done",
    "we have done", "we've worked", "we have worked", "we worked", "we've delivered", "we have delivered",
    "we delivered", "we integrated", "main ne", "hum ne",
];

const STOPWORDS: [&str; 40] = [
    "with", "that", "this", "these", "those", "from", "have", "been", "were", "they", "them", "their", "there", "what",
    "when", "where", "which", "while", "would", "could", "should", "about", "into", "over", "more", "most", "some",
    "like", "just", "also", "only", "very", "much", "many", "such", "than", "then", "your", "will", "here",
];

/// Claim sentences with a content word (four letters or more, not a common word or a number word) that is nowhere in
/// what is known: the rules can't tell whether they are true.
fn unverifiable_claims(answer: &str, sources: &Sources) -> Vec<String> {
    let mut found = Vec::new();
    for sentence in answer.replace('\u{2019}', "'").split(['.', '!', '?', '\n', ';']) {
        let lower = sentence.trim().to_lowercase();
        let Some(marker) = CLAIM_MARKERS.iter().find(|m| contains_word(&lower, m)) else { continue };
        let unknown: Vec<&str> = lower
            .split(|c: char| !c.is_alphanumeric())
            .filter(|w| w.chars().count() >= 4 && !STOPWORDS.contains(w) && !marker.contains(*w))
            .filter(|w| !WORDS.iter().any(|(n, _)| n == w) && !sources.has_word(w))
            .collect();
        if !unknown.is_empty() {
            found.push(format!("\"{}\" ({})", sentence.trim(), unknown.join(", ")));
        }
    }
    found
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

    let unknown: Vec<String> = quantities(answer, false)
        .into_iter()
        .filter(|q| !sources.knows(q))
        .map(|q| match (q.unit, q.rate) {
            (Some(unit), Some(rate)) => format!("{} {unit} a {rate}", q.value),
            (Some(unit), None) => format!("{} {unit}", q.value),
            _ => q.value.to_string(),
        })
        .collect();
    let claims: Vec<&String> = case.invented.iter().filter(|claim| lower.contains(&claim.to_lowercase())).collect();
    let mut invented = Vec::new();
    if !unknown.is_empty() {
        invented.push(format!("numbers not in what is known: {}", unknown.join(", ")));
    }
    if !claims.is_empty() {
        invented.push(format!("invented: {}", claims.iter().map(|c| c.as_str()).collect::<Vec<_>>().join(", ")));
    }
    let mut invented_check = result(Category::NothingInvented, invented.is_empty(), invented.join("; "));
    if invented.is_empty() {
        let claims = unverifiable_claims(answer, sources);
        if !claims.is_empty() {
            invented_check.unchecked = true;
            invented_check.detail = format!("not checkable by rules: {}", claims.join("; "));
        }
    }
    results.push(invented_check);

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
        let repeated: Vec<String> = requests(answer)
            .into_iter()
            .filter(|request| turn.check.no_ask.iter().any(|term| contains_word(request, &term.to_lowercase())))
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
    /// Answers whose "nothing invented" the rules could not check (counted as passed: an upper bound).
    pub unchecked: u32,
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
                if r.unchecked {
                    self.unchecked += 1;
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
    }

    #[test]
    fn a_number_is_known_only_with_its_unit_and_rate() {
        let mut known = Sources::default();
        known.add("Built the driver payouts service, 40,000 payouts a day; cut reconciliation from 3 days to 4 hours.");
        known.add("Notice period: one month. I expect 450,000 PKR a month. About 40 of those hours.");
        let q = |text: &str| quantities(text, false);
        assert!(q("40,000 payouts a day").iter().all(|x| known.knows(x)));
        assert!(!known.knows(&q("40,000 payouts a month")[0]), "a day is not a month");
        assert!(!known.knows(&q("up to 3 times")[0]), "3 is known as days, not as times");
        assert!(known.knows(&q("in 3 days")[0]));
        assert!(known.knows(&q("450,000 rupees monthly")[0]), "rupees and PKR, monthly and a month");
        assert!(known.knows(&q("1 month's notice")[0]) && known.knows(&q("40 of the hours")[0]));
        assert!(known.knows(&q("8 of the 40")[1]), "a bare number matches any mention");
        assert_eq!(q("PHP 8 every day")[0].unit, None, "a rate is not a unit");
        assert!(q("one key per payout").is_empty(), "a number word without its unit is no claim");
        assert!(q("two for restaurants").is_empty());
        assert_eq!(q("2 for restaurants")[0].unit, None);
        assert_eq!(q("12 city managers")[0].unit, Some("manager"));
    }

    #[test]
    fn digits_in_arabic_and_urdu_scripts_and_quantities_in_words_are_read() {
        assert_eq!(numbers("قبل ٢٤ ساعة"), vec![24.0]);
        assert_eq!(numbers("۵ لاکھ کا آرڈر"), vec![5.0]);
        assert_eq!(numbers("١٢٬٥٠٠ ريال و ٤٫٥"), vec![12_500.0, 4.5]);
        let words = |text: &str| quantities(text, false).into_iter().map(|q| (q.value, q.unit)).collect::<Vec<_>>();
        assert_eq!(words("I have three years of Kotlin and do hafte notice."), vec![(3.0, Some("year")), (2.0, Some("week"))]);
        assert_eq!(words("Fifty thousand riyals, with twelve developers."), vec![(50_000.0, Some("riyal")), (12.0, Some("developer"))]);
        assert!(words("Yes, two quick ones. One question: do you test?").is_empty());
    }

    #[test]
    fn a_first_person_claim_with_unknown_words_is_not_checkable_by_rules() {
        let mut known = Sources::default();
        known.add("I have not used Kotlin in production. Built the driver payouts service at Savari Rides.");
        assert_eq!(unverifiable_claims("I built the driver payouts service at Savari Rides.", &known), Vec::<String>::new());
        let claims = unverifiable_claims("I've used Kotlin on Android side projects, so I can pick it up fast.", &known);
        assert_eq!(claims.len(), 1);
        assert!(claims[0].contains("android"), "{claims:?}");
        assert!(unverifiable_claims("I mentioned 450,000 rupees a month.", &known).is_empty(), "not a claim about experience");
    }

    #[test]
    fn requests_are_questions_and_sentences_that_ask_for_something() {
        let text = "Yes, two quick ones.\n- **Team**: who would I work with?\nSay next: How does on-call work here?";
        assert_eq!(requests(text), vec!["who would i work with", "how does on-call work here"]);
        assert_eq!(requests("Sure.\nSay next: Ask them their budget again."), vec!["ask them their budget again"]);
        assert_eq!(requests("Confirm the salary figure with them."), vec!["confirm the salary figure with them"]);
        assert_eq!(requests("Un se budget dobara poochain"), vec!["un se budget dobara poochain"]);
        assert_eq!(requests("Could you share a little more about how on-call works here."), vec!["could you share a little more about how on-call works here"]);
        assert_eq!(requests("Office mein kitna aana hoga, yeh bataiye"), vec!["office mein kitna aana hoga, yeh bataiye"]);
        assert_eq!(requests("أخبرني كم عدد الفروع عندكم").len(), 1);
        assert!(requests("I mentioned 450,000 rupees a month.\n- **Open**: happy to discuss").is_empty());
    }
}
