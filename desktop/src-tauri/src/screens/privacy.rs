//! What may be captured right now (pure; unit-tested). Screens are kept only during a call recording (a detected
//! meeting or one the person started), while Screen moments are on, the plan and the server have them and the
//! recording's project allows them. The capture holds while the person paused it, the recording is paused, the
//! meeting window is minimised or hidden, an excluded app (password managers by default) is on the captured screen,
//! or the hour's screens are used up.

use serde::Serialize;

use crate::detect::classify::SourceApp;
use crate::detect::sites::{SiteRule, SiteVerdict, match_site};

/// `screenTarget`: the meeting app's window (the default) or the whole screen it is on.
pub const MEETING_WINDOW: &str = "meeting_window";
pub const WHOLE_SCREEN: &str = "whole_screen";

/// Apps never captured unless the person takes them off the list: password managers and the system keychains.
pub const DEFAULT_EXCLUDED: [&str; 13] = [
    "1Password",
    "Bitwarden",
    "KeePass",
    "KeePassXC",
    "LastPass",
    "Dashlane",
    "NordPass",
    "Keeper Password Manager",
    "RoboForm",
    "Enpass",
    "Proton Pass",
    "Keychain Access",
    "Passwords",
];

/// Excluded apps kept at most, and the longest name.
pub const MAX_EXCLUDED: usize = 50;
pub const MAX_EXCLUDED_NAME: usize = 100;

/// A process or app name as the list compares it: no capitals, spaces, dashes or `.exe` ("Proton Pass" =
/// "ProtonPass.exe").
fn comparable(name: &str) -> String {
    let lower = name.trim().to_lowercase();
    let lower = lower.strip_suffix(".exe").unwrap_or(&lower);
    lower.chars().filter(|c| !c.is_whitespace() && *c != '-' && *c != '_').collect()
}

/// Is `name` (an image name, an app name or a bundle id) on the excluded list?
pub fn is_excluded(name: &str, excluded: &[String]) -> bool {
    let name = comparable(name);
    // A bundle id names the app last ("com.1password.1password", "com.bitwarden.desktop").
    let last = name.rsplit('.').next().unwrap_or(&name).to_string();
    !name.is_empty() && excluded.iter().map(|e| comparable(e)).filter(|e| !e.is_empty()).any(|e| e == name || e == last || name.split('.').any(|part| part == e))
}

/// The list as Settings stores it: trimmed, no empty or repeated names (compared like [`is_excluded`]), at most
/// [`MAX_EXCLUDED`] names of at most [`MAX_EXCLUDED_NAME`] characters.
pub fn clean_excluded(list: &[String]) -> Result<Vec<String>, &'static str> {
    let mut out: Vec<String> = Vec::new();
    for name in list.iter().map(|n| n.trim()).filter(|n| !n.is_empty()) {
        if name.chars().count() > MAX_EXCLUDED_NAME {
            return Err("App names are 100 characters at most.");
        }
        if !out.iter().any(|o| comparable(o) == comparable(name)) {
            out.push(name.to_string());
        }
    }
    if out.len() > MAX_EXCLUDED {
        return Err("Up to 50 apps can be excluded.");
    }
    Ok(out)
}

/// The list as a settings file gives it: like [`clean_excluded`], leaving out what that would refuse.
pub fn tolerant_excluded(list: &[String]) -> Vec<String> {
    let mut out: Vec<String> = Vec::new();
    for name in list.iter().map(|n| n.trim()).filter(|n| !n.is_empty() && n.chars().count() <= MAX_EXCLUDED_NAME) {
        if out.len() < MAX_EXCLUDED && !out.iter().any(|o| comparable(o) == comparable(name)) {
            out.push(name.to_string());
        }
    }
    out
}

/// Why nothing is captured for this recording at all.
#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum OffReason {
    /// Screen moments are off in Settings.
    Setting,
    /// A voice note, a dictation or a capture test: no meeting.
    NotACall,
    /// The plan leaves screen moments out (`403 feature_not_in_plan`, or the config's plan).
    Plan,
    /// The recording's project has screens switched off.
    Project,
    /// The server is older than screen moments (`404 not_found`).
    Server,
    /// This system cannot capture the screen.
    Unsupported,
    /// The key that seals screens on this computer could not be read from the keychain.
    Keychain,
}

/// Why the capture holds for now (it goes on by itself when the reason is gone, except the person's pause).
#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum HoldReason {
    /// The person paused screens in the pill.
    You,
    /// The recording itself is paused.
    RecordingPaused,
    /// The meeting window is minimised or hidden behind another tab or desktop.
    Minimised,
    /// No meeting window is open (or the meeting app is not known yet).
    NoWindow,
    /// An excluded app is on the captured screen, or the meeting app itself is excluded.
    ExcludedApp,
    /// macOS: Screen Recording is not allowed for EchoLedger yet.
    Permission,
    /// 120 screens were kept in the last hour.
    HourCap,
    /// The recording's project is still being checked.
    Starting,
}

/// What the capture knows about the moment, gathered each second.
#[derive(Clone, Copy, Debug)]
pub struct Facts {
    pub enabled: bool,
    pub call: bool,
    pub plan: bool,
    pub server: bool,
    /// None while the project's switch is being read.
    pub project: Option<bool>,
    pub supported: bool,
    pub keychain: bool,
    pub permission: bool,
    pub user_paused: bool,
    pub recording_paused: bool,
    pub capped: bool,
}

/// The gate before the capture looks for its window.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Gate {
    Off(OffReason),
    Hold(HoldReason),
    /// Find the meeting window (or the screen) and sample it.
    Look,
}

/// Off reasons first (they last the whole recording), then the holds in the order the person can act on them.
pub fn gate(f: &Facts) -> Gate {
    let off = [
        (!f.call, OffReason::NotACall),
        (!f.enabled, OffReason::Setting),
        (!f.supported, OffReason::Unsupported),
        (!f.server, OffReason::Server),
        (!f.plan, OffReason::Plan),
        (f.project == Some(false), OffReason::Project),
        (!f.keychain, OffReason::Keychain),
    ];
    if let Some((_, reason)) = off.iter().find(|(yes, _)| *yes) {
        return Gate::Off(*reason);
    }
    let hold = [
        (f.user_paused, HoldReason::You),
        (f.recording_paused, HoldReason::RecordingPaused),
        (!f.permission, HoldReason::Permission),
        (f.project.is_none(), HoldReason::Starting),
        (f.capped, HoldReason::HourCap),
    ];
    match hold.iter().find(|(yes, _)| *yes) {
        Some((_, reason)) => Gate::Hold(*reason),
        None => Gate::Look,
    }
}

/// One top-level window as the capture sees it.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct WindowFacts {
    pub title: String,
    /// Its process image name (Windows) or app name / bundle id (macOS).
    pub process: String,
    pub visible: bool,
    pub minimised: bool,
    /// On another virtual desktop, or hidden by the system (Windows "cloaked").
    pub cloaked: bool,
    /// Pixels on screen.
    pub area: i64,
    /// Floating toolbars and palettes (the Zoom share bar), never the meeting.
    pub tool: bool,
}

impl WindowFacts {
    /// Shown on screen now.
    pub fn showing(&self) -> bool {
        self.visible && !self.minimised && !self.cloaked && self.area > 0
    }
}

/// Which window of the meeting app's process tree to capture.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Pick {
    Window(usize),
    Hold(HoldReason),
}

/// Title words of a native app's meeting window, preferred over its other windows (the Zoom home window, the
/// Teams chat list).
fn meeting_words(app: SourceApp) -> &'static [&'static str] {
    match app {
        SourceApp::Zoom => &["zoom meeting", "zoom webinar", "meeting"],
        SourceApp::MicrosoftTeams => &["meeting", "call", "besprechung", "réunion", "reunión"],
        _ => &[],
    }
}

/// The meeting window among `windows` (the tree's top-level windows). A browser counts only windows whose title shows
/// the meeting's site (a Meet tab that is not the open tab of its window is hidden: the window shows something else);
/// a native app prefers its meeting window, then its largest. No such window: [`HoldReason::NoWindow`]; only
/// minimised or hidden ones: [`HoldReason::Minimised`].
pub fn pick_meeting_window(app: SourceApp, browser: bool, windows: &[WindowFacts], site_rules: &[SiteRule]) -> Pick {
    let shows_meeting = |w: &WindowFacts| {
        !browser || matches!(match_site(std::slice::from_ref(&w.title), &[], site_rules), SiteVerdict::App { app: ref a, .. } if a == app.as_str())
    };
    let candidates: Vec<usize> = (0..windows.len()).filter(|&i| !windows[i].tool && !windows[i].title.trim().is_empty() && shows_meeting(&windows[i])).collect();
    if candidates.is_empty() {
        return Pick::Hold(HoldReason::NoWindow);
    }
    let showing: Vec<usize> = candidates.into_iter().filter(|&i| windows[i].showing()).collect();
    if showing.is_empty() {
        return Pick::Hold(HoldReason::Minimised);
    }
    let words = meeting_words(app);
    let preferred: Vec<usize> = showing.iter().copied().filter(|&i| words.iter().any(|w| windows[i].title.to_lowercase().contains(w))).collect();
    let from = if preferred.is_empty() { &showing } else { &preferred };
    Pick::Window(*from.iter().max_by_key(|&&i| windows[i].area).expect("not empty"))
}

/// Whole screen: an excluded app showing anywhere on the captured screen holds the capture.
pub fn screen_shows_excluded(on_screen: &[WindowFacts], excluded: &[String]) -> bool {
    on_screen.iter().any(|w| w.showing() && is_excluded(&w.process, excluded))
}

#[cfg(test)]
mod tests {
    use super::*;

    fn excluded() -> Vec<String> {
        DEFAULT_EXCLUDED.iter().map(|s| s.to_string()).collect()
    }

    fn facts() -> Facts {
        Facts { enabled: true, call: true, plan: true, server: true, project: Some(true), supported: true, keychain: true, permission: true, user_paused: false, recording_paused: false, capped: false }
    }

    fn window(title: &str, area: i64) -> WindowFacts {
        WindowFacts { title: title.into(), process: "Zoom.exe".into(), visible: true, area, ..Default::default() }
    }

    #[test]
    fn password_managers_are_excluded_by_default_by_any_of_their_names() {
        for name in ["1Password.exe", "1password", "Bitwarden.exe", "KeePassXC.exe", "keepass.exe", "ProtonPass.exe", "Proton Pass", "KeeperPasswordManager.exe", "com.1password.1password", "com.bitwarden.desktop", "Keychain Access", "com.apple.Passwords"] {
            assert!(is_excluded(name, &excluded()), "{name}");
        }
        for name in ["Zoom.exe", "chrome.exe", "Teams.exe", "com.microsoft.teams2", "WhatsApp.exe", "", "notepad.exe"] {
            assert!(!is_excluded(name, &excluded()), "{name}");
        }
    }

    #[test]
    fn the_person_adds_any_process_name() {
        let list = vec!["Signal.exe".to_string(), "  ".to_string()];
        assert!(is_excluded("signal.EXE", &list));
        assert!(is_excluded("Signal", &list));
        assert!(!is_excluded("Slack.exe", &list));
    }

    #[test]
    fn the_stored_list_is_cleaned() {
        let list: Vec<String> = ["  Signal.exe ", "", "signal", "Slack"].iter().map(|s| s.to_string()).collect();
        assert_eq!(clean_excluded(&list).unwrap(), vec!["Signal.exe".to_string(), "Slack".to_string()]);
        assert!(clean_excluded(&["x".repeat(101)]).is_err());
        let many: Vec<String> = (0..51).map(|i| format!("app{i}")).collect();
        assert!(clean_excluded(&many).is_err());
    }

    #[test]
    fn nothing_is_captured_outside_a_call_or_when_switched_off() {
        assert_eq!(gate(&Facts { call: false, ..facts() }), Gate::Off(OffReason::NotACall));
        assert_eq!(gate(&Facts { enabled: false, ..facts() }), Gate::Off(OffReason::Setting));
        assert_eq!(gate(&facts()), Gate::Look);
    }

    #[test]
    fn a_project_with_screens_off_captures_nothing() {
        assert_eq!(gate(&Facts { project: Some(false), ..facts() }), Gate::Off(OffReason::Project));
        // Until the project is read the capture waits instead of keeping anything.
        assert_eq!(gate(&Facts { project: None, ..facts() }), Gate::Hold(HoldReason::Starting));
    }

    #[test]
    fn the_plan_the_server_and_the_keychain_switch_screens_off() {
        assert_eq!(gate(&Facts { plan: false, ..facts() }), Gate::Off(OffReason::Plan));
        assert_eq!(gate(&Facts { server: false, ..facts() }), Gate::Off(OffReason::Server));
        assert_eq!(gate(&Facts { keychain: false, ..facts() }), Gate::Off(OffReason::Keychain));
        assert_eq!(gate(&Facts { supported: false, ..facts() }), Gate::Off(OffReason::Unsupported));
        // An off reason outranks every hold.
        assert_eq!(gate(&Facts { plan: false, user_paused: true, ..facts() }), Gate::Off(OffReason::Plan));
    }

    #[test]
    fn pauses_hold_the_capture() {
        assert_eq!(gate(&Facts { user_paused: true, ..facts() }), Gate::Hold(HoldReason::You));
        assert_eq!(gate(&Facts { recording_paused: true, ..facts() }), Gate::Hold(HoldReason::RecordingPaused));
        assert_eq!(gate(&Facts { permission: false, ..facts() }), Gate::Hold(HoldReason::Permission));
        assert_eq!(gate(&Facts { capped: true, ..facts() }), Gate::Hold(HoldReason::HourCap));
    }

    #[test]
    fn a_minimised_meeting_window_pauses_the_capture() {
        let mut w = window("Zoom Meeting", 2_000_000);
        w.minimised = true;
        assert_eq!(pick_meeting_window(SourceApp::Zoom, false, &[w.clone()], &[]), Pick::Hold(HoldReason::Minimised));
        w.minimised = false;
        w.cloaked = true; // on another virtual desktop
        assert_eq!(pick_meeting_window(SourceApp::Zoom, false, &[w.clone()], &[]), Pick::Hold(HoldReason::Minimised));
        w.cloaked = false;
        assert_eq!(pick_meeting_window(SourceApp::Zoom, false, &[w], &[]), Pick::Window(0));
        assert_eq!(pick_meeting_window(SourceApp::Zoom, false, &[], &[]), Pick::Hold(HoldReason::NoWindow));
    }

    #[test]
    fn the_meeting_window_wins_over_the_apps_other_windows() {
        let mut bar = window("", 40_000);
        bar.tool = true;
        let windows = [window("Zoom Workplace", 3_000_000), window("Zoom Meeting", 1_500_000), bar];
        assert_eq!(pick_meeting_window(SourceApp::Zoom, false, &windows, &[]), Pick::Window(1));
        // WhatsApp: the largest window.
        let whatsapp = [window("WhatsApp", 900_000), window("WhatsApp", 2_000_000)];
        assert_eq!(pick_meeting_window(SourceApp::Whatsapp, false, &whatsapp, &[]), Pick::Window(1));
    }

    #[test]
    fn a_browser_counts_only_the_window_showing_the_meeting() {
        let windows = [window("Inbox (3) - Gmail - Google Chrome", 3_000_000), window("Meet - abc-defg-hij - Google Chrome", 2_000_000)];
        assert_eq!(pick_meeting_window(SourceApp::GoogleMeet, true, &windows, &[]), Pick::Window(1));
        // The Meet tab is not the open tab of any window: hidden, nothing is captured.
        let elsewhere = [window("Inbox (3) - Gmail - Google Chrome", 3_000_000), window("Meeting notes - Google Docs - Google Chrome", 2_000_000)];
        assert_eq!(pick_meeting_window(SourceApp::GoogleMeet, true, &elsewhere, &[]), Pick::Hold(HoldReason::NoWindow));
    }

    #[test]
    fn whole_screen_holds_while_an_excluded_app_shows() {
        let mut vault = window("Bitwarden", 600_000);
        vault.process = "Bitwarden.exe".into();
        let meeting = window("Zoom Meeting", 2_000_000);
        assert!(screen_shows_excluded(&[meeting.clone(), vault.clone()], &excluded()));
        vault.minimised = true;
        assert!(!screen_shows_excluded(&[meeting, vault], &excluded()));
    }
}
