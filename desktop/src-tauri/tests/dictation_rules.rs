//! Dictation 2.0 (plan B14): spoken commands, dictionary and replacements, voice snippets, clean-up on or off, and
//! the history. Pure.

use desktop_lib::dictation::compose_text;
use desktop_lib::dictation::rules::{History, Replacement, Snippet, apply, clean_replacements, clean_snippets, HISTORY_LIMIT};
use desktop_lib::settings::Settings;

fn r(from: &str, to: &str) -> Replacement {
    Replacement { from: from.into(), to: to.into() }
}

fn s(trigger: &str, text: &str) -> Snippet {
    Snippet { trigger: trigger.into(), text: text.into() }
}

#[test]
fn spoken_commands_break_lines_and_capitalise_after_them() {
    assert_eq!(apply("Hello team. New paragraph. thanks for the call.", &[], &[]), "Hello team.\n\nThanks for the call.");
    assert_eq!(apply("First item new line second item", &[], &[]), "First item\nSecond item");
    assert_eq!(apply("The newline character", &[], &[]), "The newline character", "only whole words");
}

#[test]
fn the_dictionary_fixes_spelling_and_replacements_rewrite_words() {
    let dictionary = [r("echo ledger", "EchoLedger"), r("farah", "Farah"), r("asap", "as soon as possible")];
    assert_eq!(apply("Send it to Echo Ledger and farah asap.", &dictionary, &[]), "Send it to EchoLedger and Farah as soon as possible.");
    assert_eq!(apply("Farahnaz called.", &dictionary, &[]), "Farahnaz called.", "never inside a longer word");
}

#[test]
fn snippets_insert_their_text() {
    let snippets = [s("insert signature", "Best regards,\nSam Khan")];
    assert_eq!(apply("Thanks again. Insert signature.", &[], &snippets), "Thanks again. Best regards,\nSam Khan");
}

#[test]
fn urdu_text_passes_through_and_can_be_replaced() {
    assert_eq!(apply("قیمت ٹھیک ہے", &[r("ٹھیک", "درست")], &[]), "قیمت درست ہے");
}

#[test]
fn only_usable_entries_are_saved() {
    let cleaned = clean_replacements(&[r(" asap ", "as soon as possible"), r("ASAP", "x"), r("", "y"), r(&"a".repeat(101), "z")]);
    assert_eq!(cleaned, vec![r("asap", "as soon as possible")]);
    let snippets = clean_snippets(&[s("sig", " Regards "), s("SIG", "x"), s("empty", "  ")]);
    assert_eq!(snippets, vec![s("sig", "Regards")]);
}

#[test]
fn clean_up_can_be_switched_off() {
    let mut settings = Settings::default();
    let turns = vec!["um so the meeting is at three".to_string(), "new line see you".to_string()];
    assert_eq!(compose_text(&turns, &settings), "So the meeting is at three\nSee you.");
    settings.dictation_cleanup = false;
    assert_eq!(compose_text(&turns, &settings), "um so the meeting is at three\nSee you");
    settings.dictation_replacements = vec![r("three", "3 pm")];
    assert!(compose_text(&turns, &settings).contains("at 3 pm"));
}

#[test]
fn the_history_keeps_the_newest_fifty_and_survives_a_restart() {
    let dir = tempfile::tempdir().unwrap();
    let path = dir.path().join("dictation_history.json");
    let mut history = History::default();
    for i in 0..(HISTORY_LIMIT + 5) {
        history.push(format!("2026-10-25T10:{:02}:00Z", i % 60), &format!("note {i}"));
    }
    history.push("2026-10-25T11:00:00Z".into(), "   ");
    history.save(&path).unwrap();

    let reloaded = History::load(&path);
    assert_eq!(reloaded.entries.len(), HISTORY_LIMIT);
    assert_eq!(reloaded.entries[0].text, format!("note {}", HISTORY_LIMIT + 4), "newest first; blanks are not kept");
    assert!(History::load(&dir.path().join("missing.json")).entries.is_empty());
}
