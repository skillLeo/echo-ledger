//! Global shortcut vocabulary, defaults, parsing and duplicate detection.

use std::collections::HashMap;

use desktop_lib::shortcuts::{Action, default_map, duplicates, parse};

fn map(entries: &[(Action, &str)]) -> HashMap<Action, String> {
    entries.iter().map(|(a, s)| (*a, s.to_string())).collect()
}

#[test]
fn the_defaults_are_the_documented_chords() {
    let defaults = default_map();
    assert_eq!(defaults.len(), 4);
    assert_eq!(
        defaults[&Action::ToggleCaptions],
        "CommandOrControl+Shift+Space"
    );
    assert_eq!(defaults[&Action::ToggleSaving], "CommandOrControl+Shift+R");
    assert_eq!(defaults[&Action::CopyLast2Min], "CommandOrControl+Shift+C");
    assert_eq!(defaults[&Action::Dictation], "CommandOrControl+Shift+D");
    assert!(
        !defaults.contains_key(&Action::VoiceNote),
        "voice-note capture ships without a default chord"
    );
}

#[test]
fn every_default_chord_parses() {
    for (action, accelerator) in default_map() {
        assert!(parse(&accelerator).is_ok(), "{action:?}: {accelerator}");
    }
}

#[test]
fn the_action_list_is_complete_and_unique() {
    let mut all = Action::ALL.to_vec();
    all.dedup();
    assert_eq!(all.len(), 5);
    let defaults: Vec<Option<&str>> = Action::ALL
        .iter()
        .map(|a| a.default_accelerator())
        .collect();
    assert_eq!(defaults.iter().filter(|d| d.is_some()).count(), 4);
}

#[test]
fn action_names_in_settings_files_and_ipc_are_snake_case() {
    let names: Vec<String> = Action::ALL
        .iter()
        .map(|a| {
            serde_json::to_value(a)
                .unwrap()
                .as_str()
                .unwrap()
                .to_string()
        })
        .collect();
    assert_eq!(
        names,
        [
            "toggle_captions",
            "toggle_saving",
            "copy_last2_min",
            "dictation",
            "voice_note"
        ]
    );
    for action in Action::ALL {
        let back: Action = serde_json::from_value(serde_json::to_value(action).unwrap()).unwrap();
        assert_eq!(back, action);
    }
    assert!(serde_json::from_value::<Action>(serde_json::json!("launch_rockets")).is_err());
}

#[test]
fn the_names_match_the_typescript_union() {
    let ts = std::fs::read_to_string(
        std::path::Path::new(env!("CARGO_MANIFEST_DIR"))
            .join("..")
            .join("src")
            .join("lib")
            .join("ipc.ts"),
    )
    .expect("ipc.ts");
    let line = ts
        .lines()
        .find(|l| l.contains("type ShortcutAction"))
        .expect("ShortcutAction in ipc.ts");
    for action in Action::ALL {
        let name = serde_json::to_value(action).unwrap();
        let name = name.as_str().unwrap();
        assert!(
            line.contains(&format!("\"{name}\"")),
            "`{name}` missing from: {line}"
        );
    }
}

#[test]
fn accelerator_spellings_that_the_settings_screen_can_produce_parse() {
    for accelerator in [
        "Ctrl+Shift+K",
        "Control+Alt+F5",
        "Alt+Space",
        "CommandOrControl+Shift+Space",
        "Shift+F12",
        "ctrl+shift+k",
        "CmdOrCtrl+Alt+9",
    ] {
        assert!(parse(accelerator).is_ok(), "{accelerator}");
    }
}

#[test]
fn nonsense_accelerators_are_rejected_with_a_readable_message() {
    for accelerator in [
        "",
        "+",
        "Ctrl+",
        "Ctrl+Shift",
        "Banana+K",
        "Ctrl+Shift+K+L",
        "definitely not a shortcut",
    ] {
        let err = parse(accelerator).expect_err(accelerator);
        assert!(err.contains("Invalid shortcut"), "{err}");
    }
}

#[test]
fn identical_accelerators_are_reported_once_for_the_later_action() {
    let found = duplicates(&map(&[
        (Action::ToggleCaptions, "Ctrl+Shift+X"),
        (Action::Dictation, "Ctrl+Shift+X"),
    ]));
    assert_eq!(found, vec![Action::Dictation]);
}

#[test]
fn different_spellings_of_the_same_chord_are_still_duplicates() {
    let found = duplicates(&map(&[
        (Action::ToggleCaptions, "Ctrl+Shift+X"),
        (Action::ToggleSaving, "Control+Shift+X"),
        (Action::Dictation, "shift+ctrl+x"),
    ]));
    assert_eq!(found, vec![Action::ToggleSaving, Action::Dictation]);
}

#[cfg(windows)]
#[test]
fn command_or_control_is_control_on_windows() {
    let found = duplicates(&map(&[
        (Action::ToggleCaptions, "CommandOrControl+Shift+X"),
        (Action::Dictation, "Ctrl+Shift+X"),
    ]));
    assert_eq!(found, vec![Action::Dictation]);
}

#[test]
fn invalid_and_missing_entries_never_count_as_duplicates() {
    assert!(
        duplicates(&map(&[
            (Action::ToggleCaptions, "nonsense"),
            (Action::Dictation, "nonsense")
        ]))
        .is_empty()
    );
    assert!(
        duplicates(&map(&[
            (Action::ToggleCaptions, ""),
            (Action::Dictation, "")
        ]))
        .is_empty()
    );
    assert!(duplicates(&HashMap::new()).is_empty());
    assert!(duplicates(&default_map()).is_empty());
}

#[test]
fn the_same_chord_on_different_keys_is_not_a_duplicate() {
    assert!(
        duplicates(&map(&[
            (Action::ToggleCaptions, "Ctrl+Shift+X"),
            (Action::Dictation, "Ctrl+Alt+X"),
            (Action::VoiceNote, "Ctrl+Shift+Y")
        ]))
        .is_empty()
    );
}
