//! The sharing-bar patterns (web/resources/data/share-bars.json, the one file the server serves and the app is built
//! with) against saved window lists: each meeting app's bar is found, a tab is told from the whole screen, nothing else
//! is taken for a share, and a share-looking bar no pattern knows is reported for the log (titles only).

mod common;

use desktop_lib::privacy::bars::{self, Listed, Mode, Patterns};
use serde_json::Value;

fn server_file() -> String {
    let path = std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../web/resources/data/share-bars.json");
    std::fs::read_to_string(&path).unwrap_or_else(|e| panic!("{}: {e}", path.display()))
}

#[test]
fn the_app_is_built_with_the_server_s_file_unchanged() {
    assert_eq!(bars::FALLBACK, server_file(), "one copy: the app's fallback is the server's file");
}

#[test]
fn every_pattern_finds_its_bar_in_the_saved_window_lists() {
    let patterns = Patterns::parse(&server_file()).expect("the server file reads");
    let fixture = common::fixture("share-bars/window-lists.json");
    let mut seen_apps = std::collections::BTreeSet::new();
    for case in fixture["cases"].as_array().unwrap() {
        let name = case["name"].as_str().unwrap();
        let platform = case["platform"].as_str().unwrap();
        let windows: Vec<Listed> = serde_json::from_value(case["windows"].clone()).unwrap();
        let found = bars::find(&patterns, platform, &windows);
        assert_eq!(found.as_ref().map(|f| f.app.as_str()), case["expect"].as_str(), "{name}");
        if let Some(found) = &found {
            let mode = match case["mode"].as_str().unwrap() {
                "screen" => Mode::Screen,
                "window" => Mode::Window,
                _ => Mode::Tab,
            };
            assert_eq!(found.mode, mode, "{name}");
            seen_apps.insert(found.app.clone());
        }
        let unknown: Vec<String> = bars::unknown(&patterns, platform, &windows).into_iter().map(|w| w.title).collect();
        let expected: Vec<String> = case["unknown"].as_array().unwrap().iter().map(|v| v.as_str().unwrap().to_string()).collect();
        // A page in a browser can be titled anything: only meeting apps' windows and browsers' floating bars count.
        assert_eq!(unknown, expected, "{name}");
    }
    for app in ["zoom", "teams", "meet", "slack", "whatsapp"] {
        assert!(seen_apps.contains(app), "a window list shows {app}'s bar");
    }
}

#[test]
fn every_pattern_in_the_file_is_exercised_and_runs_in_rust() {
    let file: Value = serde_json::from_str(&server_file()).unwrap();
    let patterns = Patterns::parse(&server_file()).unwrap();
    assert_eq!(patterns.list.len(), file["patterns"].as_array().unwrap().len(), "no pattern was dropped as unreadable");
    let fixture = common::fixture("share-bars/window-lists.json");
    let mut matched = std::collections::BTreeSet::new();
    for case in fixture["cases"].as_array().unwrap() {
        let platform = case["platform"].as_str().unwrap();
        for w in case["windows"].as_array().unwrap() {
            if let Some(p) = patterns.matching(platform, w["owner"].as_str().unwrap(), w["title"].as_str().unwrap()) {
                matched.insert(p.id.clone());
            }
        }
    }
    for id in ["zoom-share-toolbar-mac", "zoom-share-toolbar-win", "teams-sharing-bar-mac", "chromium-sharing-bar-mac", "slack-share-mac", "whatsapp-share-mac"] {
        assert!(matched.contains(id), "{id} has a window list");
    }
    let line = bars::unknown_line(&Listed { owner: "zoom.us".into(), title: "Screen sharing meeting controls".into(), layer: 25 });
    assert_eq!(line, "Sharing bar no pattern knows: app \"zoom.us\", title \"Screen sharing meeting controls\".");
}
