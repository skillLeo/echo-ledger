//! "Professional" dictation text: tidy spacing and punctuation, drop filler words, capitalize sentences.
//! Deterministic and rule-based (no rewriting — v1 is transcription only). Pure; unit-tested.

/// Hesitation words removed from dictation. "mm" is not one of them: it is also the unit (millimetres).
const FILLERS: [&str; 5] = ["um", "uh", "erm", "uhm", "hmm"];

/// Marks that end a sentence, in Latin and other scripts (Urdu/Arabic, CJK full-width, Devanagari).
const SENTENCE_ENDS: [char; 13] = ['.', '!', '?', '…', '۔', '؟', '。', '？', '！', '｡', '．', '।', '॥'];

/// Marks after which no full stop is added at the end of the text.
fn ends_text(ch: char) -> bool {
    SENTENCE_ENDS.contains(&ch) || matches!(ch, ':' | ';' | '：' | '；')
}

/// Punctuation that belongs to the word before it ("hello , world" → "hello, world").
fn is_attaching_punctuation(ch: char) -> bool {
    ends_text(ch) || matches!(ch, ',' | '،' | '，' | '、')
}

pub fn professionalize(turns: &[String]) -> String {
    // Words without fillers (punctuation attached to a filler goes with it).
    let words = turns.iter().flat_map(|t| t.split_whitespace()).filter(|w| {
        let bare: String = w.chars().filter(|c| c.is_alphanumeric()).collect::<String>().to_lowercase();
        !FILLERS.contains(&bare.as_str())
    });

    // A word made only of punctuation joins the previous word; a word that merely starts with a dot (".pdf") keeps
    // its space.
    let mut tokens: Vec<String> = Vec::new();
    for word in words {
        match tokens.last_mut() {
            Some(last) if word.chars().all(is_attaching_punctuation) => last.push_str(word),
            _ => tokens.push(word.to_string()),
        }
    }

    // The pronoun "i" and its contractions, also next to punctuation ("i,", "i?", "i'm").
    let text = tokens.iter().map(|t| capitalize_pronoun(t)).collect::<Vec<_>>().join(" ");

    // Capitalize the first letter of the text and of every sentence. A sentence ends at a mark followed by a space,
    // so decimals (3.5), versions (2.1), addresses (google.com) and file names keep their case.
    let mut out = String::with_capacity(text.len() + 1);
    let mut capitalize = true;
    let mut after_end = false;
    for ch in text.chars() {
        if capitalize && ch.is_alphabetic() {
            out.extend(ch.to_uppercase());
            capitalize = false;
        } else {
            out.push(ch);
        }
        if SENTENCE_ENDS.contains(&ch) {
            after_end = true;
        } else if after_end {
            capitalize |= ch.is_whitespace();
            after_end = false;
        }
    }

    // Close with a full stop unless the text already ends a sentence; a trailing comma becomes that full stop.
    let trimmed = out.trim().trim_end_matches([',', '،', '，', '、']).trim_end();
    match trimmed.chars().last() {
        None => String::new(),
        Some(last) if ends_text(last) => trimmed.to_string(),
        Some(_) => format!("{trimmed}."),
    }
}

/// "i" → "I" and "i'm" → "I'm", keeping punctuation that follows ("i," → "I,").
fn capitalize_pronoun(token: &str) -> String {
    let core = token.trim_end_matches(|c: char| !c.is_alphanumeric() && c != '\'');
    if core == "i" || core.starts_with("i'") {
        format!("I{}", &token[1..])
    } else {
        token.to_string()
    }
}
