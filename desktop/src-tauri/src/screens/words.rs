//! Pointing words: phrases in the live captions that point at the shared screen ("look at this page", "click here",
//! "yeh dekhein"), in English, Roman Urdu and Urdu script. Hearing one keeps the screen at once when it differs from
//! the last saved one (`detect::Trigger::Words`).

/// Whole-word phrases (lower case), matched word by word.
const PHRASES: [&str; 31] = [
    "this page",
    "this screen",
    "this slide",
    "here",
    "click",
    "clicks",
    "clicked",
    "clicking",
    "look at",
    "see this",
    "yeh dekhein",
    "yeh dekhen",
    "ye dekhein",
    "ye dekhen",
    "yeh dekho",
    "is screen par",
    "is screen pe",
    "is page par",
    "is page pe",
    "yahan",
    "yahaan",
    "dekho",
    "dekhiye",
    "یہ دیکھیں",
    "یہ دیکھو",
    "یہاں",
    "دیکھو",
    "دیکھیں",
    "اس سکرین پر",
    "اس اسکرین پر",
    "اس پیج پر",
];

/// The words of a caption line in lower case: letters and digits of any script, everything else separates (an
/// apostrophe too, so "here's" holds "here"). Arabic letter forms some engines write for Urdu become the Urdu ones.
fn words(text: &str) -> Vec<String> {
    let text: String = text.chars().map(urdu_form).collect();
    text.split(|c: char| !(c.is_alphanumeric() || is_joining_mark(c))).filter(|w| !w.is_empty()).map(str::to_lowercase).collect()
}

/// Arabic yeh, alef maksura, heh and kaf as Urdu writes them (ی, ہ, ک).
fn urdu_form(c: char) -> char {
    match c {
        '\u{064A}' | '\u{0649}' => '\u{06CC}',
        '\u{0647}' => '\u{06C1}',
        '\u{0643}' => '\u{06A9}',
        other => other,
    }
}

/// Marks that belong inside a word: Arabic-script vowel signs and the zero-width non-joiner Urdu typing uses.
fn is_joining_mark(c: char) -> bool {
    matches!(c, '\u{064B}'..='\u{065F}' | '\u{0670}' | '\u{200C}')
}

/// Does the caption line point at the screen?
pub fn points_at_screen(text: &str) -> bool {
    let heard = words(text);
    if heard.is_empty() {
        return false;
    }
    PHRASES.iter().any(|phrase| {
        let wanted: Vec<&str> = phrase.split(' ').collect();
        heard.windows(wanted.len()).any(|w| w.iter().zip(&wanted).all(|(a, b)| a == b))
    })
}

#[cfg(test)]
mod tests {
    use super::points_at_screen;

    #[test]
    fn english_pointing_phrases_are_heard() {
        for line in ["Can you look at this?", "Click on the blue button", "So here's the invoice", "If you see this page", "This page has the totals", "Right, here.", "I clicked Save"] {
            assert!(points_at_screen(line), "{line}");
        }
    }

    #[test]
    fn roman_urdu_and_urdu_script_are_heard() {
        for line in ["Yeh dekhein, total yahan hai", "is screen par neeche", "dekho na", "Ye dekhen", "یہ دیکھیں، یہاں ٹوٹل ہے", "اس سکرین پر دیکھیں", "یہاں کلک کریں"] {
            assert!(points_at_screen(line), "{line}");
        }
    }

    #[test]
    fn words_only_count_whole() {
        for line in ["There is no problem", "Where were we?", "Everywhere", "the clicker broke", "lookat", "a page of this book", "", "  ", "Thanks, see you"] {
            assert!(!points_at_screen(line), "{line}");
        }
    }

    #[test]
    fn arabic_letter_forms_count_as_urdu() {
        // "یہاں" typed with Arabic yeh and heh.
        assert!(points_at_screen("\u{064A}\u{0647}\u{0627}\u{06BA} total"));
    }

    #[test]
    fn capitals_and_punctuation_do_not_matter() {
        assert!(points_at_screen("LOOK   AT the chart!"));
        assert!(points_at_screen("(click)"));
        assert!(points_at_screen("Look-at"));
    }
}
