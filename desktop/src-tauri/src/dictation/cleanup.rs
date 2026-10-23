//! "Professional" dictation text: tidy spacing and punctuation, drop filler words, capitalize sentences.
//! Deterministic and rule-based (no AI rewriting — v1 is transcription only). Pure; unit-tested in Phase 4.

const FILLERS: [&str; 6] = ["um", "uh", "erm", "uhm", "hmm", "mm"];

pub fn professionalize(turns: &[String]) -> String {
    let joined = turns.iter().map(|t| t.trim()).filter(|t| !t.is_empty()).collect::<Vec<_>>().join(" ");
    if joined.is_empty() {
        return String::new();
    }

    // Remove standalone filler words (keep punctuation attached to neighbours).
    let words: Vec<&str> = joined
        .split_whitespace()
        .filter(|w| {
            let bare: String = w.chars().filter(|c| c.is_alphanumeric()).collect::<String>().to_lowercase();
            !FILLERS.contains(&bare.as_str())
        })
        .collect();
    let mut text = words.join(" ");

    // No space before punctuation; single space after sentence punctuation.
    for p in [",", ".", "!", "?", ";", ":"] {
        text = text.replace(&format!(" {p}"), p);
    }
    while text.contains("  ") {
        text = text.replace("  ", " ");
    }
    // Standalone lowercase "i" → "I" (incl. contractions).
    text = text
        .split(' ')
        .map(|w| if w == "i" || w.starts_with("i'") { format!("I{}", &w[1..]) } else { w.to_string() })
        .collect::<Vec<_>>()
        .join(" ");

    // Capitalize the first letter of each sentence.
    let mut out = String::with_capacity(text.len() + 1);
    let mut capitalize = true;
    for ch in text.chars() {
        if capitalize && ch.is_alphabetic() {
            out.extend(ch.to_uppercase());
            capitalize = false;
        } else {
            out.push(ch);
        }
        if matches!(ch, '.' | '!' | '?') {
            capitalize = true;
        }
    }

    let trimmed = out.trim().to_string();
    match trimmed.chars().last() {
        Some('.' | '!' | '?' | '…' | ':' | ';') | None => trimmed,
        Some(_) => format!("{trimmed}."),
    }
}
