//! The Mac safety net: when a whole-screen share starts in a meeting app that is not confirmed hidden on this Mac, every
//! EchoLedger window stops drawing and leaves the screen within 300 ms, and comes back when the share ends. The share is
//! seen from the system's window list (`CGWindowListCopyWindowInfo`, which shows other apps' titles with the Screen
//! Recording permission EchoLedger already has for screen moments), matched against the sharing-bar patterns and told
//! apart with the meeting auto mode detected (`detect`). "I share one window or a tab" turns it off: only a whole-screen
//! share exposes the windows. Public APIs only; EchoLedger stays in every window list itself.

use std::time::Duration;

use serde::Serialize;

use super::apps::MeetingApp;
use super::bars::{Found, Mode};
use crate::detect::classify::SourceApp;

/// How often the window list is read while an EchoLedger window is on screen: two looks fit in the 300 ms budget.
pub const POLL: Duration = Duration::from_millis(140);
/// How often it is read while every EchoLedger window is off the screen anyway (only to bring them back).
pub const IDLE_POLL: Duration = Duration::from_millis(500);
/// From the bar appearing to the windows leaving the screen, at most.
pub const BUDGET: Duration = Duration::from_millis(300);

/// The share the safety net saw.
#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct Seen {
    pub app: Option<MeetingApp>,
    pub mode: Mode,
    /// EchoLedger's windows left the screen for it.
    pub hid: bool,
}

/// The meeting app of a share: the bar's own app, or for a browser's bar the meeting auto mode detected in it.
pub fn app_of(found: &Found, meeting: Option<SourceApp>) -> Option<MeetingApp> {
    let from_meeting = meeting.and_then(|m| match m {
        SourceApp::Zoom => Some(MeetingApp::Zoom),
        SourceApp::MicrosoftTeams => Some(MeetingApp::Teams),
        SourceApp::GoogleMeet => Some(MeetingApp::Meet),
        SourceApp::Whatsapp | SourceApp::WhatsappBusiness | SourceApp::WhatsappWeb => Some(MeetingApp::Whatsapp),
        _ => None,
    });
    match MeetingApp::from_wire(&found.app) {
        // A browser's bar: the meeting in that browser says which app it is (Teams or WhatsApp on the web).
        Some(MeetingApp::Meet) => from_meeting.or(Some(MeetingApp::Meet)),
        other => other.or(from_meeting),
    }
}

/// Whether EchoLedger's windows must leave the screen now (pure).
pub fn should_hide(found: Option<&Found>, meeting: Option<SourceApp>, one_window: bool, off: bool, confirmed: impl Fn(MeetingApp) -> bool) -> Option<Seen> {
    let found = found?;
    let app = app_of(found, meeting);
    let hid = !off && !one_window && found.mode == Mode::Screen && !app.is_some_and(confirmed);
    Some(Seen { app, mode: found.mode, hid })
}

/// The window list now (macOS): every on-screen window's owner, title and layer.
#[cfg(target_os = "macos")]
pub fn window_list() -> Vec<super::bars::Listed> {
    use objc2_core_foundation::{CFDictionary, CFNumber, CFString, CFType};
    use objc2_core_graphics::{CGWindowListCopyWindowInfo, CGWindowListOption, kCGWindowLayer, kCGWindowName, kCGWindowOwnerName, kCGWindowOwnerPID};

    let own = std::process::id() as i64;
    let Some(list) = CGWindowListCopyWindowInfo(CGWindowListOption::OptionOnScreenOnly | CGWindowListOption::ExcludeDesktopElements, 0) else { return Vec::new() };
    let mut out = Vec::new();
    for i in 0..list.count() {
        // SAFETY: the index is in range; each value of this array is a CFDictionary the array keeps alive.
        let item = unsafe { list.value_at_index(i) } as *const CFType;
        let Some(dict) = (unsafe { item.as_ref() }).and_then(|t| t.downcast_ref::<CFDictionary>()) else { continue };
        let get = |key: &CFString| -> Option<&CFType> {
            // SAFETY: the key is a CFString; the value, when present, is a CF object owned by the dictionary.
            let value = unsafe { dict.value((key as *const CFString).cast()) } as *const CFType;
            unsafe { value.as_ref() }
        };
        // SAFETY: the window-list keys are constants of CoreGraphics.
        let (owner_key, name_key, layer_key, pid_key) = unsafe { (kCGWindowOwnerName, kCGWindowName, kCGWindowLayer, kCGWindowOwnerPID) };
        let text = |key: &CFString| get(key).and_then(|v| v.downcast_ref::<CFString>()).map(|s| s.to_string()).unwrap_or_default();
        let number = |key: &CFString| get(key).and_then(|v| v.downcast_ref::<CFNumber>()).and_then(|n| n.as_i64()).unwrap_or(0);
        if number(pid_key) == own {
            continue;
        }
        out.push(super::bars::Listed { owner: text(owner_key), title: text(name_key), layer: number(layer_key) });
    }
    out
}

/// The safety net's loop (macOS).
#[cfg(target_os = "macos")]
pub fn spawn(app: tauri::AppHandle) {
    use tauri::{Emitter, Manager};
    std::thread::Builder::new()
        .name("el-share-watch".into())
        .spawn(move || {
            let mut patterns = super::bars::current(&app.state::<crate::AppState>().data_dir);
            let mut loaded = std::time::Instant::now();
            loop {
                let state = app.state::<crate::AppState>();
                let privacy = app.state::<super::Privacy>();
                let settings = state.settings();
                let concealed = privacy.hidden_for(super::HideReason::Share);
                let any_shown = super::KNOWN.iter().any(|(label, kind)| matches!(kind, super::Kind::Main | super::Kind::Floating) && app.get_webview_window(label).is_some_and(|w| w.is_visible().unwrap_or(false)));
                // The guided test shares the whole screen on purpose, and its answer is given in the main window.
                let guided = app.get_webview_window(super::GUIDED_CARD).is_some();
                let watching = (any_shown || concealed) && !guided && !settings.share_one_window && settings.hide_from_sharing != crate::settings::ShareHiding::Off;
                if !watching {
                    if concealed {
                        super::set_hidden(&app, super::HideReason::Share, false);
                    }
                    std::thread::sleep(IDLE_POLL);
                    continue;
                }
                if loaded.elapsed() > Duration::from_secs(600) {
                    patterns = super::bars::current(&state.data_dir);
                    loaded = std::time::Instant::now();
                }
                let windows = window_list();
                let found = super::bars::find(&patterns, "macos", &windows);
                for w in super::bars::unknown(&patterns, "macos", &windows) {
                    let line = super::bars::unknown_line(&w);
                    if !super::diag::has(&state.data_dir, &line) {
                        super::diag::log(&state.data_dir, &line);
                    }
                }
                let meeting = state.detect.meeting().map(|m| m.app);
                // Read only while a share shows: the guided results and the app's version.
                let seen = should_hide(found.as_ref(), meeting, settings.share_one_window, false, |a| {
                    let store = super::check::Store::load(&state.data_dir);
                    super::apps::confirmed_hidden(a, super::apps::installed_version(a).as_deref(), &super::check::os_version(), &store.guided)
                });
                let hide = seen.as_ref().is_some_and(|s| s.hid);
                let changed = *privacy.share_seen.lock().unwrap_or_else(|p| p.into_inner()) != seen;
                *privacy.share_seen.lock().unwrap_or_else(|p| p.into_inner()) = seen;
                if hide != concealed {
                    super::set_hidden(&app, super::HideReason::Share, hide);
                    crate::actions::emit_status(&app);
                } else if changed {
                    let _ = app.emit("privacy:changed", ());
                }
                std::thread::sleep(if concealed && !any_shown { IDLE_POLL.min(POLL * 2) } else { POLL });
            }
        })
        .ok();
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::privacy::bars::Mode;

    fn found(app: &str, mode: Mode) -> Found {
        Found { pattern: "p".into(), app: app.into(), mode }
    }

    #[test]
    fn a_whole_screen_share_in_an_unconfirmed_app_hides_the_windows() {
        let unconfirmed = |_| false;
        let seen = should_hide(Some(&found("teams", Mode::Screen)), None, false, false, unconfirmed).unwrap();
        assert!(seen.hid);
        assert_eq!(seen.app, Some(MeetingApp::Teams));
        assert_eq!(should_hide(None, None, false, false, unconfirmed), None, "no share: nothing to do");
    }

    #[test]
    fn a_confirmed_app_a_tab_or_one_window_keeps_them() {
        let zoom_ok = |a| a == MeetingApp::Zoom;
        assert!(!should_hide(Some(&found("zoom", Mode::Screen)), None, false, false, zoom_ok).unwrap().hid, "the guided test said hidden");
        assert!(!should_hide(Some(&found("meet", Mode::Tab)), None, false, false, |_| false).unwrap().hid, "a tab never shows EchoLedger");
        assert!(!should_hide(Some(&found("meet", Mode::Window)), None, false, false, |_| false).unwrap().hid);
        assert!(!should_hide(Some(&found("teams", Mode::Screen)), None, true, false, |_| false).unwrap().hid, "I share one window or a tab");
        assert!(!should_hide(Some(&found("teams", Mode::Screen)), None, false, true, |_| false).unwrap().hid, "the setting is off");
    }

    #[test]
    fn a_browser_bar_takes_its_app_from_the_meeting_detected() {
        let bar = found("meet", Mode::Screen);
        assert_eq!(app_of(&bar, Some(SourceApp::MicrosoftTeams)), Some(MeetingApp::Teams), "Teams on the web");
        assert_eq!(app_of(&bar, None), Some(MeetingApp::Meet));
        assert_eq!(app_of(&found("zoom", Mode::Screen), Some(SourceApp::GoogleMeet)), Some(MeetingApp::Zoom), "Zoom's own bar is Zoom");
        // Teams on the web confirmed hidden does not hide; Meet in the same browser would.
        let teams_ok = |a| a == MeetingApp::Teams;
        assert!(!should_hide(Some(&bar), Some(SourceApp::MicrosoftTeams), false, false, teams_ok).unwrap().hid);
        assert!(should_hide(Some(&bar), Some(SourceApp::GoogleMeet), false, false, teams_ok).unwrap().hid);
    }

    #[test]
    fn the_windows_leave_within_300_ms() {
        // The bar can appear just after a look: the next look comes one period later, and ordering out is immediate.
        assert!(POLL * 2 <= BUDGET, "two looks fit in the budget");
        assert_eq!(BUDGET, Duration::from_millis(300));
    }
}
