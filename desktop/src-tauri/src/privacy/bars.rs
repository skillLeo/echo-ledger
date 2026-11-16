//! The windows that mean "a screen share is running": Zoom's share toolbar, Teams' sharing bar, the browsers' "is
//! sharing your screen" bars, Slack and WhatsApp. The patterns come from the server (`GET /api/config/share-bars`, kept
//! with its ETag, asked again once a day) and fall back to the same file the server serves,
//! web/resources/data/share-bars.json, built into the app from the repository (one copy, never a second hand-made
//! list); the newer of the two is used. A floating window of a meeting app that looks like a bar no pattern knows is
//! logged on this computer (its app and the first words of its title only) so the patterns can be completed.

use std::path::{Path, PathBuf};
use std::time::Duration;

use regex::{Regex, RegexBuilder};
use serde::{Deserialize, Serialize};

/// The patterns the server serves, as of this build.
pub const FALLBACK: &str = include_str!("../../../../web/resources/data/share-bars.json");

#[derive(Clone, Debug, Deserialize)]
struct FileDto {
    version: u32,
    #[serde(default)]
    updated_at: Option<String>,
    patterns: Vec<PatternDto>,
}

#[derive(Clone, Debug, Deserialize)]
struct PatternDto {
    id: String,
    app: String,
    platform: String,
    owner: String,
    title: String,
    #[serde(default)]
    flags: Option<String>,
}

#[derive(Clone, Debug)]
pub struct Pattern {
    pub id: String,
    pub app: String,
    pub platform: String,
    owner: Regex,
    title: Regex,
}

#[derive(Clone, Debug)]
pub struct Patterns {
    pub version: u32,
    pub updated_at: Option<String>,
    pub list: Vec<Pattern>,
}

fn compile(source: &str, insensitive: bool) -> Result<Regex, String> {
    RegexBuilder::new(source).case_insensitive(insensitive).size_limit(1 << 20).build().map_err(|e| e.to_string())
}

impl Patterns {
    /// Reads the server's answer (or the file). A pattern that does not compile is left out, the others stay.
    pub fn parse(json: &str) -> Result<Self, String> {
        let file: FileDto = serde_json::from_str(json).map_err(|e| format!("The sharing-bar patterns are not readable: {e}"))?;
        let list = file
            .patterns
            .into_iter()
            .filter_map(|p| {
                let insensitive = p.flags.as_deref().is_some_and(|f| f.contains('i'));
                Some(Pattern { owner: compile(&p.owner, insensitive).ok()?, title: compile(&p.title, insensitive).ok()?, id: p.id, app: p.app, platform: p.platform })
            })
            .collect();
        Ok(Self { version: file.version, updated_at: file.updated_at, list })
    }

    pub fn fallback() -> Self {
        Self::parse(FALLBACK).unwrap_or(Self { version: 0, updated_at: None, list: Vec::new() })
    }

    /// The first pattern of `platform` whose owner and title both match.
    pub fn matching(&self, platform: &str, owner: &str, title: &str) -> Option<&Pattern> {
        self.list.iter().find(|p| p.platform == platform && p.owner.is_match(owner) && p.title.is_match(title))
    }
}

/// One window of the system's window list (`CGWindowListCopyWindowInfo`: owner name, title, layer).
#[derive(Clone, Debug, Default, PartialEq, Eq, Serialize, Deserialize)]
pub struct Listed {
    pub owner: String,
    #[serde(default)]
    pub title: String,
    #[serde(default)]
    pub layer: i64,
}

/// How much is being shared, from the bar's words (a browser says "a window" or "a tab"; other bars count as the
/// whole screen, the case that exposes EchoLedger).
#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum Mode {
    Screen,
    Window,
    Tab,
}

pub fn mode_of(title: &str) -> Mode {
    let t = title.to_lowercase();
    if t.contains("a tab") || t.contains("this tab") {
        Mode::Tab
    } else if t.contains("a window") {
        Mode::Window
    } else {
        Mode::Screen
    }
}

/// A share seen in the window list.
#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct Found {
    pub pattern: String,
    pub app: String,
    pub mode: Mode,
}

/// Whether a window can be a sharing bar (pure). On a Mac every bar floats above the apps (layer 3 or 25 in the window
/// lists): a window at the normal layer is a meeting window, a chat or a page, whatever its title says ("Presenting Q3 |
/// Microsoft Teams", a tab titled "…is sharing your screen"). Windows' window list has no layers.
pub fn can_be_bar(platform: &str, layer: i64) -> bool {
    platform != "macos" || layer != 0
}

/// The share the window list shows, if any (pure).
pub fn find(patterns: &Patterns, platform: &str, windows: &[Listed]) -> Option<Found> {
    let mut found: Option<Found> = None;
    for w in windows.iter().filter(|w| can_be_bar(platform, w.layer)) {
        if let Some(p) = patterns.matching(platform, &w.owner, &w.title) {
            let this = Found { pattern: p.id.clone(), app: p.app.clone(), mode: mode_of(&w.title) };
            // A whole-screen bar wins over a tab's.
            if found.as_ref().is_none_or(|f| f.mode != Mode::Screen && this.mode == Mode::Screen) {
                found = Some(this);
            }
        }
    }
    found
}

/// Meeting apps and browsers, whose floating share-looking windows no pattern knows are worth logging.
const MEETING_OWNERS: [&str; 6] = ["zoom", "microsoft teams", "ms-teams", "teams", "slack", "whatsapp"];
const BROWSER_OWNERS: [&str; 6] = ["google chrome", "microsoft edge", "brave browser", "safari", "arc", "chrome"];
/// The words a sharing bar's title uses, as whole words ("shared", "SharePoint" and "#share-ideas" do not count).
const BAR_WORDS: [&str; 3] = ["share", "sharing", "presenting"];
/// How much of a title is kept in the log: enough to write a pattern, never a whole chat or channel name.
pub const LOGGED_TITLE: usize = 80;

/// What a word of a title is made of: "-", "_" and "#" stay inside it, so a channel such as "#share-ideas" or a file
/// "share_notes" is one word, never the bar word "share" (and so never logged).
fn is_word_char(c: char) -> bool {
    c.is_alphanumeric() || matches!(c, '-' | '_' | '#')
}

/// Floating windows (layer above 0) of meeting apps and browsers whose title uses a sharing bar's words and that no
/// pattern matches: to log (pure). A window at the normal layer is a meeting, a chat, a channel or a page, whose title
/// is the person's own content, and is never logged.
pub fn unknown(patterns: &Patterns, platform: &str, windows: &[Listed]) -> Vec<Listed> {
    windows
        .iter()
        .filter(|w| {
            let owner = w.owner.to_lowercase();
            let owner = owner.trim_start_matches('\u{200e}');
            let app = MEETING_OWNERS.iter().chain(BROWSER_OWNERS.iter()).any(|o| owner.starts_with(o));
            let words: Vec<String> = w.title.to_lowercase().split(|c: char| !is_word_char(c)).map(str::to_string).collect();
            w.layer != 0 && app && BAR_WORDS.iter().any(|k| words.iter().any(|word| word == k)) && patterns.matching(platform, &w.owner, &w.title).is_none()
        })
        .cloned()
        .collect()
}

/// The first `LOGGED_TITLE` characters (a mark shows that more was cut).
fn first_words(text: &str) -> String {
    let mut out: String = text.chars().take(LOGGED_TITLE).collect();
    if text.chars().count() > LOGGED_TITLE {
        out.push('…');
    }
    out
}

pub fn unknown_line(w: &Listed) -> String {
    format!("Sharing bar no pattern knows: app \"{}\", title \"{}\".", first_words(&w.owner), first_words(&w.title))
}

/// The patterns kept from the server: its answer and its ETag.
#[derive(Clone, Debug, Default, Serialize, Deserialize)]
pub struct Kept {
    pub etag: Option<String>,
    pub body: String,
}

pub fn kept_path(data_dir: &Path) -> PathBuf {
    data_dir.join("share-bars.json")
}

pub fn load_kept(data_dir: &Path) -> Option<Kept> {
    std::fs::read(kept_path(data_dir)).ok().and_then(|b| serde_json::from_slice(&b).ok())
}

/// The patterns to use: the server's last answer or the built-in file, whichever is newer (an update that ships newer
/// patterns uses them even before the server is reached again); the built-in file when the kept answer does not read.
pub fn current(data_dir: &Path) -> Patterns {
    let built_in = Patterns::fallback();
    match load_kept(data_dir).and_then(|k| Patterns::parse(&k.body).ok()).filter(|p| !p.list.is_empty()) {
        Some(kept) if kept.version >= built_in.version => kept,
        _ => built_in,
    }
}

/// Asks the server for newer patterns (with the kept ETag) and keeps a new answer. Errors leave the kept ones.
pub async fn refresh(api: &crate::net::api::ApiClient, data_dir: &Path) -> Result<bool, crate::error::AppError> {
    let kept = load_kept(data_dir);
    let Some((body, etag)) = api.share_bars(kept.as_ref().and_then(|k| k.etag.as_deref())).await? else { return Ok(false) };
    if Patterns::parse(&body).map(|p| p.list.is_empty()).unwrap_or(true) {
        return Ok(false);
    }
    let _ = super::diag::write_atomic(&kept_path(data_dir), &serde_json::to_vec(&Kept { etag, body }).unwrap_or_default());
    Ok(true)
}

/// How often the patterns are asked for again, and how soon after an ask that failed (or with nobody signed in).
pub const REFRESH_EVERY: Duration = Duration::from_secs(24 * 60 * 60);
pub const RETRY_AFTER: Duration = Duration::from_secs(60 * 60);

/// When to ask next after an ask (pure).
pub fn next_refresh(asked: Result<bool, ()>) -> Duration {
    if asked.is_ok() { REFRESH_EVERY } else { RETRY_AFTER }
}

/// The patterns are asked for in a task of their own (never ahead of the config, the library or the upload queue), at
/// the start and once a day after.
pub fn spawn_refresh(app: tauri::AppHandle) {
    use tauri::Manager;
    tauri::async_runtime::spawn(async move {
        loop {
            let state = app.state::<crate::AppState>();
            let asked = match state.api() {
                Ok(api) => refresh(&api, &state.data_dir).await.map_err(|_| ()),
                Err(_) => Err(()),
            };
            tokio::time::sleep(next_refresh(asked)).await;
        }
    });
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_built_in_patterns_are_the_server_file_and_all_compile() {
        let file: serde_json::Value = serde_json::from_str(FALLBACK).unwrap();
        let patterns = Patterns::fallback();
        assert_eq!(patterns.list.len(), file["patterns"].as_array().unwrap().len(), "every pattern compiles in Rust");
        assert!(patterns.version >= 1);
    }

    #[test]
    fn a_bad_pattern_costs_only_itself() {
        let json = r#"{"version": 2, "patterns": [
            {"id": "bad", "app": "zoom", "platform": "macos", "owner": "(", "title": "x"},
            {"id": "good", "app": "zoom", "platform": "macos", "owner": "^zoom\\.us$", "title": "share toolbar", "flags": "i"}]}"#;
        let p = Patterns::parse(json).unwrap();
        assert_eq!(p.list.len(), 1);
        assert_eq!(p.matching("macos", "zoom.us", "Share Toolbar").map(|p| p.id.as_str()), Some("good"));
        assert!(p.matching("windows", "zoom.us", "Share Toolbar").is_none(), "another platform");
    }

    #[test]
    fn on_a_mac_only_a_floating_window_can_be_a_sharing_bar() {
        let p = Patterns::fallback();
        // Since share-bars.json version 2 (release audit R24) a meeting called "Presenting Q3" is never a bar.
        assert!(p.matching("macos", "Microsoft Teams", "Presenting Q3 | Microsoft Teams").is_none(), "a meeting's own name is no bar");
        let meeting = Listed { owner: "Microsoft Teams".into(), title: "Sharing control bar | Microsoft Teams".into(), layer: 0 };
        assert!(p.matching("macos", &meeting.owner, &meeting.title).is_some(), "the title alone reads like Teams' bar");
        assert_eq!(find(&p, "macos", std::slice::from_ref(&meeting)), None, "a meeting window at the normal layer is no bar");
        assert_eq!(find(&p, "macos", &[Listed { layer: 3, ..meeting }]).map(|f| f.app), Some("teams".to_string()));
        let tab = Listed { owner: "Google Chrome".into(), title: "meet.google.com is sharing your screen - how to stop it".into(), layer: 0 };
        assert_eq!(find(&p, "macos", &[tab]), None, "a page's title is not Chrome's bar");
        assert!(can_be_bar("windows", 0), "Windows' window list has no layers");
    }

    #[test]
    fn the_browser_bar_tells_a_tab_from_the_whole_screen() {
        assert_eq!(mode_of("meet.google.com is sharing your screen."), Mode::Screen);
        assert_eq!(mode_of("meet.google.com is sharing a window."), Mode::Window);
        assert_eq!(mode_of("meet.google.com is sharing this tab"), Mode::Tab);
    }

    #[test]
    fn kept_patterns_win_over_the_built_in_ones_until_they_do_not_read() {
        let dir = tempfile::tempdir().unwrap();
        assert_eq!(current(dir.path()).version, Patterns::fallback().version);
        let body = r#"{"version": 9, "patterns": [{"id": "x", "app": "zoom", "platform": "macos", "owner": "^zoom", "title": "share"}]}"#;
        std::fs::write(kept_path(dir.path()), serde_json::to_vec(&Kept { etag: Some("\"9\"".into()), body: body.into() }).unwrap()).unwrap();
        assert_eq!(current(dir.path()).version, 9);
        std::fs::write(kept_path(dir.path()), b"not json").unwrap();
        assert_eq!(current(dir.path()).version, Patterns::fallback().version);
    }

    #[test]
    fn an_update_with_newer_built_in_patterns_uses_them_offline() {
        let dir = tempfile::tempdir().unwrap();
        // The server's answer kept from before the update is older than the file this build ships.
        let older = r#"{"version": 0, "patterns": [{"id": "old", "app": "zoom", "platform": "macos", "owner": "^zoom", "title": "share"}]}"#;
        std::fs::write(kept_path(dir.path()), serde_json::to_vec(&Kept { etag: Some("\"0\"".into()), body: older.into() }).unwrap()).unwrap();
        let used = current(dir.path());
        assert_eq!(used.version, Patterns::fallback().version);
        assert!(used.list.iter().all(|p| p.id != "old"), "never the older list for ever");
    }

    #[test]
    fn the_patterns_are_asked_for_daily_and_again_soon_after_a_failure() {
        assert_eq!(next_refresh(Ok(true)), REFRESH_EVERY);
        assert_eq!(next_refresh(Ok(false)), REFRESH_EVERY, "nothing new (304)");
        assert_eq!(next_refresh(Err(())), RETRY_AFTER);
        assert_eq!(REFRESH_EVERY, Duration::from_secs(86_400));
    }

    #[test]
    fn a_logged_title_keeps_its_first_words_only() {
        let long = Listed { owner: "zoom.us".into(), title: format!("Sharing {}", "x".repeat(200)), layer: 25 };
        let line = unknown_line(&long);
        assert!(line.chars().count() < 160, "{line}");
        assert!(line.contains('…'), "a cut shows");
        let short = Listed { owner: "zoom.us".into(), title: "Screen sharing meeting controls".into(), layer: 25 };
        assert_eq!(unknown_line(&short), "Sharing bar no pattern knows: app \"zoom.us\", title \"Screen sharing meeting controls\".");
    }

    #[test]
    fn only_floating_windows_with_a_bar_s_words_are_logged() {
        let p = Patterns::fallback();
        let titles = |windows: &[Listed]| unknown(&p, "macos", windows).into_iter().map(|w| w.title).collect::<Vec<_>>();
        let chat = Listed { owner: "Microsoft Teams".into(), title: "Shared files - Acme | Microsoft Teams".into(), layer: 0 };
        let channel = Listed { owner: "Slack".into(), title: "share-ideas (Channel) - Acme - Slack".into(), layer: 0 };
        assert!(titles(&[chat, channel]).is_empty(), "the person's chats and channels stay out of the log");
        let point = Listed { owner: "Microsoft Teams".into(), title: "SharePoint".into(), layer: 3 };
        assert!(titles(&[point]).is_empty(), "whole words only");
        let bar = Listed { owner: "Slack".into(), title: "Huddle: sharing controls".into(), layer: 3 };
        assert_eq!(titles(std::slice::from_ref(&bar)), vec![bar.title.clone()]);
    }
}
