//! Meeting apps on a Mac: which are installed and at which version (from each app bundle's Info.plist), what the
//! guided test found for that version on this macOS version, and the plain line each gets ("Zoom: hidden", "Teams: not
//! checked", "Meet: share a tab"). A result counts only for the app version and the macOS version it was found on, so
//! the test is asked again after any of them updates. Zoom's capture mode lives in its encrypted settings database
//! (`~/Library/Application Support/zoom.us/data/zoomus.enc.db`; its plain preferences and feature-flag files hold no
//! capture mode), so EchoLedger cannot read it and never writes another app's settings: Zoom counts as set up only
//! once the guided test says so.

use std::path::{Path, PathBuf};

use chrono::{DateTime, Utc};
use serde::{Deserialize, Serialize};

#[derive(Clone, Copy, Debug, PartialEq, Eq, PartialOrd, Ord, Hash, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum MeetingApp {
    Zoom,
    Teams,
    Meet,
    Slack,
    Whatsapp,
}

impl MeetingApp {
    pub const ALL: [MeetingApp; 5] = [MeetingApp::Zoom, MeetingApp::Teams, MeetingApp::Meet, MeetingApp::Slack, MeetingApp::Whatsapp];

    pub fn name(self) -> &'static str {
        match self {
            MeetingApp::Zoom => "Zoom",
            MeetingApp::Teams => "Teams",
            MeetingApp::Meet => "Meet",
            MeetingApp::Slack => "Slack",
            MeetingApp::Whatsapp => "WhatsApp",
        }
    }

    /// The `app` of a sharing-bar pattern and the server's `meeting_app`.
    pub fn wire(self) -> &'static str {
        match self {
            MeetingApp::Zoom => "zoom",
            MeetingApp::Teams => "teams",
            MeetingApp::Meet => "meet",
            MeetingApp::Slack => "slack",
            MeetingApp::Whatsapp => "whatsapp",
        }
    }

    pub fn from_wire(wire: &str) -> Option<Self> {
        Self::ALL.into_iter().find(|a| a.wire() == wire)
    }

    /// The app bundle whose version counts (Meet runs in Chrome, so Chrome's).
    pub fn bundles(self) -> &'static [&'static str] {
        match self {
            MeetingApp::Zoom => &["zoom.us.app"],
            MeetingApp::Teams => &["Microsoft Teams.app", "Microsoft Teams (work or school).app"],
            MeetingApp::Meet => &["Google Chrome.app"],
            MeetingApp::Slack => &["Slack.app"],
            MeetingApp::Whatsapp => &["WhatsApp.app"],
        }
    }
}

/// What the guided test found: the person shared the whole screen, looked on the phone and said.
#[derive(Clone, Debug, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct Guided {
    pub app: MeetingApp,
    pub app_version: String,
    pub os_version: String,
    /// "I can see it".
    pub visible: bool,
    pub at: DateTime<Utc>,
}

#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum AppState {
    Hidden,
    Visible,
    NotChecked,
    /// Meet without a result: whole-screen sharing does not hide EchoLedger reliably, so share a tab.
    ShareTab,
}

/// The guided result for this app version on this macOS version, if any (the newest wins).
pub fn guided_for<'a>(app: MeetingApp, app_version: &str, os_version: &str, results: &'a [Guided]) -> Option<&'a Guided> {
    results.iter().rev().find(|g| g.app == app && g.app_version == app_version && g.os_version == os_version)
}

/// What the badge's list says of one app (pure).
pub fn state_of(app: MeetingApp, app_version: Option<&str>, os_version: &str, results: &[Guided]) -> AppState {
    match app_version.and_then(|v| guided_for(app, v, os_version, results)) {
        Some(g) if g.visible => AppState::Visible,
        Some(_) => AppState::Hidden,
        None if app == MeetingApp::Meet => AppState::ShareTab,
        None => AppState::NotChecked,
    }
}

pub fn line(app: MeetingApp, state: AppState) -> String {
    let what = match state {
        AppState::Hidden => "hidden",
        AppState::Visible => "visible in shares",
        AppState::NotChecked => "not checked",
        AppState::ShareTab => "share a tab",
    };
    format!("{}: {what}", app.name())
}

/// Confirmed hidden on this Mac: the guided test said "I can't see it" for this app version and macOS version.
pub fn confirmed_hidden(app: MeetingApp, app_version: Option<&str>, os_version: &str, results: &[Guided]) -> bool {
    state_of(app, app_version, os_version, results) == AppState::Hidden
}

/// The guided test should be (re)run for this app: installed, and nothing for its version on this macOS version.
pub fn needs_test(app_version: Option<&str>, state: AppState) -> bool {
    app_version.is_some() && matches!(state, AppState::NotChecked | AppState::ShareTab)
}

/// What to say once before a scheduled call in an app that is not confirmed on this Mac (Live Assist uses it).
pub fn warning_before_call(app: MeetingApp, state: AppState) -> Option<String> {
    (state != AppState::Hidden).then(|| {
        format!(
            "EchoLedger isn't confirmed hidden in {} on this Mac. Run the 1-minute test, or share one window. During a whole-screen share, your answers move to your phone.",
            app.name()
        )
    })
}

/// The folders apps are installed in.
fn app_folders() -> Vec<PathBuf> {
    let mut folders = vec![PathBuf::from("/Applications")];
    if let Some(home) = std::env::var_os("HOME") {
        folders.push(Path::new(&home).join("Applications"));
    }
    folders
}

/// An app's bundle, found by its name in the Applications folders. A name is compared without invisible formatting
/// characters: WhatsApp's bundle is named "\u{200e}WhatsApp.app".
pub fn bundle_path(app: MeetingApp) -> Option<PathBuf> {
    let clean = |name: &str| name.chars().filter(|c| !matches!(c, '\u{200e}' | '\u{200f}' | '\u{202a}'..='\u{202e}' | '\u{2066}'..='\u{2069}')).collect::<String>();
    for folder in app_folders() {
        for bundle in app.bundles() {
            let direct = folder.join(bundle);
            if direct.is_dir() {
                return Some(direct);
            }
        }
        let Ok(entries) = std::fs::read_dir(&folder) else { continue };
        for entry in entries.flatten() {
            let name = entry.file_name().to_string_lossy().to_string();
            if app.bundles().contains(&clean(&name).as_str()) {
                return Some(entry.path());
            }
        }
    }
    None
}

/// `CFBundleShortVersionString` of a bundle's Info.plist (read only).
pub fn bundle_version(bundle: &Path) -> Option<String> {
    let value = plist::Value::from_file(bundle.join("Contents").join("Info.plist")).ok()?;
    let version = value.as_dictionary()?.get("CFBundleShortVersionString")?.as_string()?.trim().to_string();
    (!version.is_empty()).then_some(version)
}

/// The installed version of a meeting app (Mac), none when it is not installed.
pub fn installed_version(app: MeetingApp) -> Option<String> {
    if !cfg!(target_os = "macos") {
        return None;
    }
    bundle_path(app).and_then(|b| bundle_version(&b))
}

#[cfg(test)]
mod tests {
    use super::*;

    fn result(app: MeetingApp, version: &str, os: &str, visible: bool) -> Guided {
        Guided { app, app_version: version.into(), os_version: os.into(), visible, at: Utc::now() }
    }

    #[test]
    fn each_app_gets_its_line_from_the_guided_test_of_this_version() {
        let results = vec![result(MeetingApp::Zoom, "7.1.9", "27.3", false), result(MeetingApp::Slack, "4.47", "27.3", true)];
        assert_eq!(line(MeetingApp::Zoom, state_of(MeetingApp::Zoom, Some("7.1.9"), "27.3", &results)), "Zoom: hidden");
        assert_eq!(line(MeetingApp::Teams, state_of(MeetingApp::Teams, Some("25.1"), "27.3", &results)), "Teams: not checked");
        assert_eq!(line(MeetingApp::Meet, state_of(MeetingApp::Meet, Some("142.0"), "27.3", &results)), "Meet: share a tab");
        assert_eq!(line(MeetingApp::Slack, state_of(MeetingApp::Slack, Some("4.47"), "27.3", &results)), "Slack: visible in shares");
    }

    #[test]
    fn an_app_or_macos_update_asks_again() {
        let results = vec![result(MeetingApp::Zoom, "7.1.9", "27.3", false)];
        assert!(confirmed_hidden(MeetingApp::Zoom, Some("7.1.9"), "27.3", &results));
        assert!(!confirmed_hidden(MeetingApp::Zoom, Some("7.2.0"), "27.3", &results), "Zoom updated");
        assert!(!confirmed_hidden(MeetingApp::Zoom, Some("7.1.9"), "27.4", &results), "macOS updated");
        assert!(needs_test(Some("7.2.0"), state_of(MeetingApp::Zoom, Some("7.2.0"), "27.3", &results)));
        assert!(!needs_test(None, AppState::NotChecked), "not installed");
        // The newest answer wins.
        let mut later = results.clone();
        later.push(result(MeetingApp::Zoom, "7.1.9", "27.3", true));
        assert_eq!(state_of(MeetingApp::Zoom, Some("7.1.9"), "27.3", &later), AppState::Visible);
    }

    #[test]
    fn the_warning_before_a_call_names_the_app_and_is_quiet_when_confirmed() {
        assert_eq!(
            warning_before_call(MeetingApp::Teams, AppState::NotChecked).unwrap(),
            "EchoLedger isn't confirmed hidden in Teams on this Mac. Run the 1-minute test, or share one window. During a whole-screen share, your answers move to your phone."
        );
        assert_eq!(warning_before_call(MeetingApp::Zoom, AppState::Hidden), None);
    }

    #[test]
    fn a_bundle_version_is_read_from_info_plist_and_invisible_marks_in_names_are_ignored() {
        let dir = tempfile::tempdir().unwrap();
        let bundle = dir.path().join("\u{200e}WhatsApp.app");
        std::fs::create_dir_all(bundle.join("Contents")).unwrap();
        let mut info = plist::Dictionary::new();
        info.insert("CFBundleShortVersionString".into(), plist::Value::String("26.1.0".into()));
        plist::Value::Dictionary(info).to_file_xml(bundle.join("Contents").join("Info.plist")).unwrap();
        assert_eq!(bundle_version(&bundle).as_deref(), Some("26.1.0"));
        assert_eq!(MeetingApp::from_wire("whatsapp"), Some(MeetingApp::Whatsapp));
        assert_eq!(MeetingApp::from_wire("webex"), None);
    }
}
