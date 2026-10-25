//! Dictation text clean-up: spacing, filler words, "I", sentence capitalisation and the closing full stop.

use desktop_lib::dictation::cleanup::professionalize;

fn clean(text: &str) -> String {
    professionalize(&[text.to_string()])
}

fn turns(parts: &[&str]) -> String {
    professionalize(&parts.iter().map(|s| s.to_string()).collect::<Vec<_>>())
}

// ---- Joining and emptiness -------------------------------------------------------------------------------------

#[test]
fn nothing_in_nothing_out() {
    assert_eq!(professionalize(&[]), "");
    assert_eq!(turns(&[""]), "");
    assert_eq!(turns(&["   ", "\n\t", ""]), "");
}

#[test]
fn turns_are_joined_with_single_spaces_and_blank_ones_skipped() {
    assert_eq!(
        turns(&["hello there.", "", "  how are you  "]),
        "Hello there. How are you."
    );
    assert_eq!(turns(&["first", "second", "third"]), "First second third.");
}

#[test]
fn text_made_only_of_fillers_disappears() {
    assert_eq!(clean("um uh erm"), "");
    assert_eq!(clean("Um, uh... hmm."), "");
}

// ---- Fillers ---------------------------------------------------------------------------------------------------

#[test]
fn filler_words_are_removed_in_any_case_with_their_punctuation() {
    assert_eq!(clean("Um, I think we should go"), "I think we should go.");
    assert_eq!(clean("we should, uh, go"), "We should, go.");
    assert_eq!(clean("UHM that is ERM fine"), "That is fine.");
    assert_eq!(clean("hmm. okay"), "Okay.");
}

#[test]
fn words_that_merely_contain_a_filler_are_untouched() {
    assert_eq!(clean("the human mummy hummed"), "The human mummy hummed.");
    assert_eq!(
        clean("summer is umbrella weather"),
        "Summer is umbrella weather."
    );
}

#[test]
#[ignore = "NEW: dictation clean-up mangles decimals, web addresses, file extensions, units, the pronoun I and non-Latin sentence ends"]
fn the_unit_mm_is_not_mistaken_for_a_filler() {
    assert_eq!(clean("a gap of 5 mm is fine"), "A gap of 5 mm is fine.");
}

// ---- Punctuation spacing ---------------------------------------------------------------------------------------

#[test]
fn space_before_punctuation_is_removed() {
    assert_eq!(clean("hello , world !"), "Hello, world!");
    assert_eq!(
        clean("what time ? noon ; sharp : yes"),
        "What time? Noon; sharp: yes."
    );
}

#[test]
fn repeated_spaces_collapse() {
    assert_eq!(clean("a    b \t c\n\nd"), "A b c d.");
}

// ---- Capitalisation --------------------------------------------------------------------------------------------

#[test]
fn the_first_letter_of_every_sentence_is_capitalised() {
    assert_eq!(
        clean("hello. how are you? fine! ok then"),
        "Hello. How are you? Fine! Ok then."
    );
    assert_eq!(clean("wait... what"), "Wait... What.");
}

#[test]
fn the_pronoun_i_is_capitalised_including_contractions() {
    assert_eq!(clean("i think i can"), "I think I can.");
    assert_eq!(
        clean("i'm sure i'll go and i've said i'd come"),
        "I'm sure I'll go and I've said I'd come."
    );
}

#[test]
#[ignore = "NEW: dictation clean-up mangles decimals, web addresses, file extensions, units, the pronoun I and non-Latin sentence ends"]
fn the_pronoun_i_is_capitalised_next_to_punctuation() {
    assert_eq!(clean("so do i."), "So do I.");
    assert_eq!(clean("well, i, for one, agree"), "Well, I, for one, agree.");
    assert_eq!(clean("can i? yes"), "Can I? Yes.");
}

#[test]
fn letters_that_start_with_i_are_not_changed() {
    assert_eq!(clean("it is in an igloo"), "It is in an igloo.");
    assert_eq!(clean("ice cream is nice"), "Ice cream is nice.");
}

#[test]
fn already_capitalised_text_is_preserved() {
    assert_eq!(
        clean("Meet Farah Khan at Acme Holdings"),
        "Meet Farah Khan at Acme Holdings."
    );
    assert_eq!(
        clean("The NASA team and the iPhone"),
        "The NASA team and the iPhone."
    );
}

#[test]
#[ignore = "NEW: dictation clean-up mangles decimals, web addresses, file extensions, units, the pronoun I and non-Latin sentence ends"]
fn decimals_and_versions_do_not_start_a_new_sentence() {
    assert_eq!(
        clean("the price is 3.5 million"),
        "The price is 3.5 million."
    );
    assert_eq!(
        clean("we are on version 2.1 beta"),
        "We are on version 2.1 beta."
    );
}

#[test]
#[ignore = "NEW: dictation clean-up mangles decimals, web addresses, file extensions, units, the pronoun I and non-Latin sentence ends"]
fn web_addresses_and_file_names_keep_their_case() {
    assert_eq!(
        clean("open google.com for details"),
        "Open google.com for details."
    );
    assert_eq!(
        clean("email farah@acme.com today"),
        "Email farah@acme.com today."
    );
}

#[test]
#[ignore = "NEW: dictation clean-up mangles decimals, web addresses, file extensions, units, the pronoun I and non-Latin sentence ends"]
fn a_space_before_a_dot_that_starts_a_word_is_kept() {
    assert_eq!(clean("save it as a .pdf file"), "Save it as a .pdf file.");
}

// ---- Closing punctuation ---------------------------------------------------------------------------------------

#[test]
fn a_full_stop_is_added_only_when_the_text_has_no_sentence_ending() {
    assert_eq!(clean("hello"), "Hello.");
    for ending in [".", "!", "?", "…", ":", ";"] {
        let text = format!("hello{ending}");
        assert_eq!(clean(&text), format!("Hello{ending}"), "{ending}");
    }
}

#[test]
#[ignore = "NEW: dictation clean-up mangles decimals, web addresses, file extensions, units, the pronoun I and non-Latin sentence ends"]
fn a_trailing_comma_does_not_become_a_comma_and_a_full_stop() {
    let out = clean("first we plan,");
    assert!(!out.contains(",."), "{out:?}");
}

#[test]
#[ignore = "NEW: dictation clean-up mangles decimals, web addresses, file extensions, units, the pronoun I and non-Latin sentence ends"]
fn closing_punctuation_from_other_scripts_is_recognised() {
    for (text, ending) in [
        ("میں کل آؤں گا۔", '۔'),
        ("کیا آپ آئیں گے؟", '؟'),
        ("你好吗？", '？'),
        ("今天很好。", '。'),
        ("すごい！", '！'),
    ] {
        let out = clean(text);
        assert_eq!(out.chars().last(), Some(ending), "{text:?} -> {out:?}");
        assert!(!out.ends_with('.'), "{out:?}");
    }
}

#[test]
fn text_in_scripts_without_letter_case_is_left_alone_apart_from_the_closing_mark() {
    assert_eq!(clean("سلام دنیا"), "سلام دنیا.");
    assert_eq!(clean("привет мир"), "Привет мир.");
    assert_eq!(clean("élan vital"), "Élan vital.");
}

// ---- Robustness ------------------------------------------------------------------------------------------------

#[test]
fn cleaning_twice_changes_nothing() {
    for text in [
        "hello , world ! um this is a test",
        "i think i'm done. are you ? ok",
        "Version two. Final answer",
        "plain text without ending",
    ] {
        let once = clean(text);
        assert_eq!(clean(&once), once, "{text:?}");
    }
}

#[test]
fn very_long_dictations_are_cleaned_in_reasonable_time() {
    let started = std::time::Instant::now();
    let long = "so um this is a long sentence , with i and uh some words . ".repeat(20_000);
    let out = clean(&long);
    assert!(out.starts_with("So this is a long sentence, with I and some words."));
    assert!(
        started.elapsed() < std::time::Duration::from_secs(10),
        "{:?}",
        started.elapsed()
    );
}

#[test]
fn unusual_characters_do_not_panic() {
    for text in [
        "i",
        "i'",
        "'",
        "\u{0}",
        "😀",
        "i😀",
        "ß ǆ ŉ",
        "ʼn",
        "\u{200f}שלום עולם",
        "a\u{0301}b",
        ". . .",
        ", , ,",
        "!!!",
        "i'i'i",
        "İstanbul i̇",
    ] {
        let _ = clean(text);
    }
}
