//! Dictation 2.0 rules (plan B14): spoken commands ("new line"), the user's dictionary and replacements, and voice
//! snippets ("insert signature"). Applied after the clean-up, in that order. Pure; unit-tested.

use serde::{Deserialize, Serialize};

/// "When I say X, write Y": a dictionary entry (same word, fixed spelling) or a replacement.
#[derive(Clone, Debug, Default, PartialEq, Eq, Serialize, Deserialize)]
#[serde(default, rename_all = "camelCase")]
pub struct Replacement {
    pub from: String,
    pub to: String,
}

/// A voice snippet: saying the trigger inserts the text.
#[derive(Clone, Debug, Default, PartialEq, Eq, Serialize, Deserialize)]
#[serde(default, rename_all = "camelCase")]
pub struct Snippet {
    pub trigger: String,
    pub text: String,
}

pub const MAX_ENTRIES: usize = 200;
pub const MAX_CHARS: usize = 100;
pub const MAX_SNIPPET_CHARS: usize = 2_000;

/// Spoken commands, longest first so "new paragraph" wins over "new line".
const COMMANDS: [(&str, &str); 4] = [("new paragraph", "\n\n"), ("next paragraph", "\n\n"), ("new line", "\n"), ("next line", "\n")];

/// Entries that can be saved: trimmed, non-empty, within limits, without duplicates (case-insensitive `from`).
pub fn clean_replacements(list: &[Replacement]) -> Vec<Replacement> {
    let mut out: Vec<Replacement> = Vec::new();
    for r in list {
        let (from, to) = (r.from.trim(), r.to.trim());
        if from.is_empty() || from.chars().count() > MAX_CHARS || to.chars().count() > MAX_CHARS || out.iter().any(|o| o.from.eq_ignore_ascii_case(from)) {
            continue;
        }
        out.push(Replacement { from: from.to_string(), to: to.to_string() });
    }
    out.truncate(MAX_ENTRIES);
    out
}

pub fn clean_snippets(list: &[Snippet]) -> Vec<Snippet> {
    let mut out: Vec<Snippet> = Vec::new();
    for s in list {
        let trigger = s.trigger.trim();
        if trigger.is_empty() || trigger.chars().count() > MAX_CHARS || s.text.trim().is_empty() || s.text.chars().count() > MAX_SNIPPET_CHARS
            || out.iter().any(|o| o.trigger.eq_ignore_ascii_case(trigger))
        {
            continue;
        }
        out.push(Snippet { trigger: trigger.to_string(), text: s.text.trim().to_string() });
    }
    out.truncate(MAX_ENTRIES);
    out
}

/// Applies the commands, then the replacements, then the snippets.
pub fn apply(text: &str, replacements: &[Replacement], snippets: &[Snippet]) -> String {
    let mut out = text.to_string();
    for (spoken, written) in COMMANDS {
        out = replace_phrase(&out, spoken, written, true);
    }
    for r in replacements {
        out = replace_phrase(&out, &r.from, &r.to, false);
    }
    for s in snippets {
        out = replace_phrase(&out, &s.trigger, &s.text, true);
    }
    tidy_breaks(&out)
}

fn is_word_char(c: char) -> bool {
    c.is_alphanumeric() || c == '_'
}

/// Replaces every whole-word, case-insensitive occurrence of `phrase`. With `eat_punctuation`, a full stop or comma
/// the clean-up put right after the phrase goes with it ("new line." → line break).
fn replace_phrase(text: &str, phrase: &str, with: &str, eat_punctuation: bool) -> String {
    let phrase = phrase.trim();
    if phrase.is_empty() {
        return text.to_string();
    }
    let lower: Vec<char> = text.chars().flat_map(char::to_lowercase).collect();
    let chars: Vec<char> = text.chars().collect();
    // Lower-casing can change the length for a few scripts; only replace when it does not, else leave the text.
    if lower.len() != chars.len() {
        return text.to_string();
    }
    let needle: Vec<char> = phrase.chars().flat_map(char::to_lowercase).collect();
    let mut out = String::with_capacity(text.len());
    let mut i = 0;
    while i < chars.len() {
        let end = i + needle.len();
        let fits = end <= chars.len() && lower[i..end] == needle[..];
        if fits && (i == 0 || !is_word_char(chars[i - 1])) && (end == chars.len() || !is_word_char(chars[end])) {
            out.push_str(with);
            i += needle.len();
            if eat_punctuation && i < chars.len() && matches!(chars[i], '.' | ',' | '۔' | '،') {
                i += 1;
            }
            continue;
        }
        out.push(chars[i]);
        i += 1;
    }
    out
}

/// No spaces around line breaks, and a capital letter after one.
fn tidy_breaks(text: &str) -> String {
    let mut out = String::with_capacity(text.len());
    let mut capitalize = false;
    let chars: Vec<char> = text.chars().collect();
    let mut i = 0;
    while i < chars.len() {
        let c = chars[i];
        if c == ' ' && chars.get(i + 1) == Some(&'\n') {
            i += 1;
            continue;
        }
        if c == ' ' && out.ends_with('\n') {
            i += 1;
            continue;
        }
        if c == '\n' {
            capitalize = true;
            out.push(c);
        } else if capitalize && c.is_alphabetic() {
            out.extend(c.to_uppercase());
            capitalize = false;
        } else {
            if !c.is_whitespace() {
                capitalize = false;
            }
            out.push(c);
        }
        i += 1;
    }
    out.trim_matches(' ').to_string()
}

/// One finished dictation in the history.
#[derive(Clone, Debug, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct HistoryEntry {
    pub at: String,
    pub text: String,
}

pub const HISTORY_LIMIT: usize = 50;

/// `dictation_history.json`: the newest 50 dictations, newest first. Kept on this computer only.
#[derive(Clone, Debug, Default, PartialEq, Eq, Serialize, Deserialize)]
pub struct History {
    pub entries: Vec<HistoryEntry>,
}

impl History {
    pub fn load(path: &std::path::Path) -> Self {
        std::fs::read(path).ok().and_then(|b| serde_json::from_slice(&b).ok()).unwrap_or_default()
    }

    pub fn push(&mut self, at: String, text: &str) {
        if text.trim().is_empty() {
            return;
        }
        self.entries.insert(0, HistoryEntry { at, text: text.to_string() });
        self.entries.truncate(HISTORY_LIMIT);
    }

    pub fn save(&self, path: &std::path::Path) -> std::io::Result<()> {
        let tmp = path.with_extension("json.tmp");
        std::fs::write(&tmp, serde_json::to_vec_pretty(self)?)?;
        std::fs::rename(tmp, path)
    }
}
