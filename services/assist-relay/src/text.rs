//! Text helpers: normalising speech-engine text, spotting questions in English, Urdu (script and Roman) and Arabic,
//! spotting replies that need no answer, comparing a partial with its final, and estimating tokens.
//!
//! The question test only decides when a suggestion may start early. A miss costs speed, not an answer (the end of the
//! turn starts one anyway); a false alarm costs one request the model answers with SKIP.

use std::collections::HashSet;
use std::sync::LazyLock;

/// Lower case, Arabic-script letters folded to one form, diacritics and tatweel dropped, punctuation turned into
/// spaces (question marks kept), whitespace collapsed.
pub fn normalize(text: &str) -> String {
    let mut out = String::with_capacity(text.len());
    let mut space = true;
    for c in text.chars() {
        let folded = match c {
            // Arabic and Urdu forms of the same letters, so one word list covers both.
            'ك' => 'ک',
            'ي' | 'ى' | 'ې' => 'ی',
            'ه' | 'ە' => 'ہ',
            'أ' | 'إ' | 'آ' | 'ٱ' => 'ا',
            'ؤ' => 'و',
            'ة' => 'ہ',
            '?' | '؟' => '?',
            // Harakat, superscript alef and tatweel carry no meaning for matching.
            '\u{064B}'..='\u{065F}' | '\u{0670}' | '\u{0640}' => continue,
            c if c.is_alphanumeric() || c == '\'' || c == '’' => c,
            _ => ' ',
        };
        if folded == ' ' {
            if !space {
                out.push(' ');
                space = true;
            }
            continue;
        }
        if folded == '?' && !space {
            out.push(' ');
        }
        for lower in folded.to_lowercase() {
            out.push(if lower == '’' { '\'' } else { lower });
        }
        space = folded == '?';
        if space {
            out.push(' ');
        }
    }
    out.trim_end().to_owned()
}

/// The words of normalised text, question marks left out.
pub fn words(normalized: &str) -> Vec<&str> {
    normalized.split(' ').filter(|w| !w.is_empty() && *w != "?").collect()
}

/// The normalised words of `entries` ("mm-hmm" gives "mm" and "hmm").
fn set(entries: &[&str]) -> HashSet<String> {
    entries.iter().flat_map(|entry| normalize(entry).split(' ').map(str::to_owned).collect::<Vec<_>>()).collect()
}

/// Words a speaker opens with before the question itself.
static FILLERS: LazyLock<HashSet<String>> = LazyLock::new(|| {
    set(&[
        "so", "and", "but", "okay", "ok", "um", "umm", "uh", "er", "well", "alright", "right", "now", "then", "also",
        "achha", "acha", "accha", "to", "toh", "aur", "phir", "ji", "اچھا", "تو", "اور", "پھر", "جی", "طیب", "یعنی",
        "و", "طب",
    ])
});

/// A question when the utterance (after fillers) starts with one of these.
static START_WORDS: LazyLock<HashSet<String>> = LazyLock::new(|| {
    set(&[
        // English: question words, auxiliaries, and requests that expect an answer.
        "what", "what's", "whats", "why", "how", "how's", "when", "where", "who", "whom", "whose", "which", "can",
        "could", "would", "will", "should", "shall", "do", "does", "did", "is", "are", "was", "were", "have", "has",
        "had", "may", "might", "am", "isn't", "aren't", "don't", "doesn't", "didn't", "won't", "wouldn't", "can't",
        "couldn't", "shouldn't", "haven't", "hasn't", "tell", "describe", "explain", "walk", "share",
        // Roman Urdu.
        "kya", "kia", "kyun", "kyon", "kiyun", "kaise", "kese", "kaisay", "kaisa", "kab", "kahan", "kahaan", "kaun",
        "kon", "kitna", "kitni", "kitne", "konsa", "kaunsa", "konsi", "kis", "batao", "bataen", "bataiye", "bataein",
        "batayen", "batain", "samjhao",
        // Urdu.
        "کیا", "کیوں", "کیسے", "کیسا", "کب", "کہاں", "کون", "کتنا", "کتنی", "کتنے", "کونسا", "کونسی", "کس", "بتائیں",
        "بتایئے", "بتاؤ", "بتاو", "سمجھائیں",
        // Arabic (Gulf and Levantine forms too).
        "هل", "ماذا", "لماذا", "لیش", "کیف", "متی", "این", "وین", "من", "کم", "ما", "شو", "ایش", "ممکن", "اشرح",
        "اخبرنی", "خبرنی", "حدثنی", "عرفنی",
    ])
});

/// Question words that mark a question wherever they stand (Urdu puts them mid-sentence).
static ANYWHERE_WORDS: LazyLock<HashSet<String>> = LazyLock::new(|| {
    set(&[
        "kya", "kyun", "kyon", "kaise", "kaisay", "kab", "kahan", "kahaan", "kaun", "kitna", "kitni", "kitne", "konsa",
        "kaunsa", "batao", "bataen", "bataiye", "bataein", "batayen", "batain", "samjhao", "کیوں", "کیسے", "کب", "کہاں",
        "کون", "کتنا", "کتنی", "کتنے", "کونسا", "کونسی", "بتائیں", "بتایئے", "بتاؤ", "بتاو", "سمجھائیں", "ماذا", "لماذا",
        "کیف", "متی", "وین", "لیش", "ایش", "شو",
    ])
});

/// Word sequences that ask for an answer.
static PHRASES: LazyLock<Vec<Vec<String>>> = LazyLock::new(|| {
    [
        "can you", "could you", "would you", "will you", "do you", "did you", "have you", "are you", "were you",
        "tell me", "walk me through", "give me an example", "what about", "how about", "any questions", "your thoughts",
        "kya aap", "kya tum", "aap ka kya", "kya hai", "کیا آپ", "کیا ہے", "کیا تم", "آپ کا کیا", "ممکن تشرح",
        "ممکن تقول",
    ]
    .iter()
    .map(|phrase| normalize(phrase).split(' ').map(str::to_owned).collect())
    .collect()
});

/// A question when the utterance ends with one of these tags ("..., right").
static END_TAGS: LazyLock<Vec<Vec<String>>> = LazyLock::new(|| {
    ["right", "isn't it", "don't you", "correct", "na", "hai na", "nahi", "نا", "ہے نا", "نہیں", "صح", "صحیح"]
        .iter()
        .map(|tag| normalize(tag).split(' ').map(str::to_owned).collect())
        .collect()
});

/// Short replies that never need an answer.
static BACKCHANNEL: LazyLock<HashSet<String>> = LazyLock::new(|| {
    set(&[
        "ok", "okay", "yeah", "yes", "yep", "yup", "right", "sure", "mm", "mmm", "mhm", "mm-hmm", "uh-huh", "hmm",
        "uh", "huh", "alright", "great", "cool", "nice", "got", "it", "i", "see", "thanks", "thank", "you", "perfect", "good",
        "fine", "achha", "acha", "accha", "theek", "thik", "hai", "ji", "jee", "haan", "han", "bilkul", "sahi",
        "shukriya", "اچھا", "ٹھیک", "ہے", "جی", "ہاں", "بالکل", "صحیح", "شکریہ", "نعم", "ای", "ایوہ", "تمام", "طیب",
        "حسنا", "ماشی", "صح", "شکرا", "اوکی",
    ])
});

fn contains_sequence(words: &[&str], sequence: &[String]) -> bool {
    !sequence.is_empty() && words.windows(sequence.len()).any(|window| window.iter().zip(sequence).all(|(w, s)| *w == s))
}

fn ends_with_sequence(words: &[&str], sequence: &[String]) -> bool {
    words.len() >= sequence.len() && words[words.len() - sequence.len()..].iter().zip(sequence).all(|(w, s)| *w == s)
}

/// Whether text from the speech engine reads like a question or a request that needs an answer.
pub fn looks_like_question(text: &str) -> bool {
    let normalized = normalize(text);
    if normalized.contains('?') {
        return true;
    }
    let all = words(&normalized);
    let start = all.iter().position(|w| !FILLERS.contains(*w)).unwrap_or(all.len());
    let rest = &all[start..];
    let Some(first) = rest.first() else {
        return false;
    };
    START_WORDS.contains(*first)
        || rest.iter().any(|w| ANYWHERE_WORDS.contains(*w))
        || PHRASES.iter().any(|p| contains_sequence(rest, p))
        || (rest.len() > 2 && END_TAGS.iter().any(|t| ends_with_sequence(rest, t)))
}

/// "Okay", "theek hai", "تمام": a short reply that needs no suggestion (and no request to the model).
pub fn is_backchannel(text: &str) -> bool {
    let normalized = normalize(text);
    if normalized.contains('?') {
        return false;
    }
    let all = words(&normalized);
    !all.is_empty() && all.len() <= 3 && all.iter().all(|w| BACKCHANNEL.contains(*w))
}

/// Whether a final turn says the same as the partial a suggestion was started on: equal words, or at most one word
/// in ten changed, added or dropped (speech engines fix a word or two when they finish a turn).
pub fn same_question(started_on: &str, final_text: &str) -> bool {
    let a = expanded_words(started_on);
    let b = expanded_words(final_text);
    let allowed = (a.len().max(b.len()) / 10).max(1);
    a.len().abs_diff(b.len()) <= allowed && word_distance(&a, &b) <= allowed
}

/// Normalised words with English contractions written out, so "what's" and "what is" compare equal.
fn expanded_words(text: &str) -> Vec<String> {
    const SUFFIXES: [(&str, &str); 6] = [("'s", "is"), ("'re", "are"), ("'ll", "will"), ("'ve", "have"), ("'m", "am"), ("'d", "would")];
    let normalized = normalize(text);
    let mut out = Vec::new();
    for word in words(&normalized) {
        if let Some(stem) = word.strip_suffix("n't") {
            out.push(match stem {
                "ca" => "can".to_owned(),
                "wo" => "will".to_owned(),
                _ => stem.to_owned(),
            });
            out.push("not".to_owned());
        } else if let Some((stem, full)) = SUFFIXES.iter().find_map(|(s, full)| word.strip_suffix(s).map(|stem| (stem, *full))) {
            out.push(stem.to_owned());
            out.push(full.to_owned());
        } else {
            out.push(word.to_owned());
        }
    }
    out
}

/// Edit distance in words.
fn word_distance(a: &[String], b: &[String]) -> usize {
    let mut previous: Vec<usize> = (0..=b.len()).collect();
    let mut current = vec![0; b.len() + 1];
    for (i, wa) in a.iter().enumerate() {
        current[0] = i + 1;
        for (j, wb) in b.iter().enumerate() {
            let substitution = previous[j] + usize::from(wa != wb);
            current[j + 1] = substitution.min(previous[j + 1] + 1).min(current[j] + 1);
        }
        std::mem::swap(&mut previous, &mut current);
    }
    previous[b.len()]
}

/// About how many tokens `text` costs: four characters a token for ASCII, two for other scripts. Used for block sizes
/// and budget estimates only; the provider reports the real counts.
pub fn estimate_tokens(text: &str) -> u32 {
    let (ascii, other) = text.chars().fold((0u64, 0u64), |(a, o), c| if c.is_ascii() { (a + 1, o) } else { (a, o + 1) });
    let tokens = ascii.div_ceil(4) + other.div_ceil(2);
    u32::try_from(tokens).unwrap_or(u32::MAX)
}

/// Lower-cased words of `text` for phrase checks ("walk me through").
pub fn has_phrase(text: &str, phrases: &[String]) -> bool {
    let normalized = normalize(text);
    let all = words(&normalized);
    phrases.iter().any(|phrase| {
        let wanted = normalize(phrase);
        let wanted: Vec<String> = words(&wanted).into_iter().map(str::to_owned).collect();
        contains_sequence(&all, &wanted)
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn normalising_folds_scripts_and_punctuation() {
        assert_eq!(normalize("  What's  YOUR notice-period?? "), "what's your notice period ? ?");
        assert_eq!(normalize("كيف حالك؟"), normalize("کیف حالک?"));
        assert_eq!(normalize("مُحَمَّد"), "محمد");
        assert_eq!(normalize("It’s"), "it's");
    }

    #[test]
    fn english_questions_are_spotted() {
        for text in [
            "What is your notice period",
            "so how did you handle that",
            "Could you walk me through the design",
            "Tell me about yourself",
            "You worked with React before, right",
            "Any questions for us",
            "is that okay?",
            "Okay and when can you start",
        ] {
            assert!(looks_like_question(text), "{text}");
        }
    }

    #[test]
    fn urdu_questions_are_spotted_in_both_scripts() {
        for text in [
            "Aap ka experience kitna hai",
            "React mein aap ne kya kaam kiya",
            "kya aap kal available hain",
            "achha to aap kab join kar sakte hain",
            "کیا آپ کل آ سکتے ہیں",
            "آپ کی تنخواہ کی توقع کتنی ہے",
            "یہ پروجیکٹ کب ختم ہوگا",
            "آپ نے یہ کام کیوں چھوڑا",
            "اپنے بارے میں بتائیں",
        ] {
            assert!(looks_like_question(text), "{text}");
        }
    }

    #[test]
    fn arabic_questions_are_spotted() {
        for text in [
            "هل لديك خبرة في الإدارة",
            "كيف تتعامل مع ضغط العمل",
            "متى يمكنك البدء",
            "لماذا تركت وظيفتك",
            "ممكن تشرح لي المشروع",
            "شو رأيك بالعرض",
        ] {
            assert!(looks_like_question(text), "{text}");
        }
    }

    #[test]
    fn statements_are_not_questions() {
        for text in [
            "We will send you the contract tomorrow",
            "Our team is based in Dubai",
            "I think that works for us",
            "Thanks for joining today",
            "ہم آپ کو کل ای میل کریں گے",
            "Hum aap ko kal email karenge",
            "سنرسل لك العقد غدا",
            "",
            "um",
        ] {
            assert!(!looks_like_question(text), "{text}");
        }
    }

    #[test]
    fn backchannels_need_no_reply() {
        for text in ["Okay.", "mm-hmm", "Got it", "theek hai", "جی", "ٹھیک ہے", "تمام", "Thank you"] {
            assert!(is_backchannel(text), "{text}");
        }
        for text in ["Okay?", "Okay, and your salary expectation", "Sure, tell me more about it", ""] {
            assert!(!is_backchannel(text), "{text}");
        }
    }

    #[test]
    fn a_final_matches_its_partial_when_at_most_a_word_changed() {
        assert!(same_question("what is your notice period", "What is your notice period?"));
        assert!(same_question("what is your notice period", "what is your notice period then"));
        assert!(same_question("what's your notice period", "what is your notice period"));
        assert!(same_question("you don't use kotlin", "you do not use kotlin"));
        assert!(!same_question("what is your notice period", "what is your notice period and your expected salary"));
        assert!(!same_question("do you know rust", "do you know kotlin and swift"));
    }

    #[test]
    fn token_estimates_follow_the_script() {
        assert_eq!(estimate_tokens(""), 0);
        assert_eq!(estimate_tokens("abcd"), 1);
        assert_eq!(estimate_tokens("abcde"), 2);
        assert_eq!(estimate_tokens("کیا"), 2);
    }

    #[test]
    fn phrases_match_whole_words() {
        let phrases = vec!["walk me through".to_owned(), "why".to_owned()];
        assert!(has_phrase("Can you walk me through it", &phrases));
        assert!(has_phrase("Why?", &phrases));
        assert!(!has_phrase("whyever not", &phrases));
    }
}
