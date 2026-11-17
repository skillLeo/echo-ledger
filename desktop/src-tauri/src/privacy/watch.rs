//! The Mac safety net: when a whole-screen share starts in a meeting app that is not confirmed hidden on this Mac,
//! EchoLedger's floating windows (and the main window with "All EchoLedger windows") stop drawing and leave the screen
//! within 300 ms. The share is seen from the system's window list (`CGWindowListCopyWindowInfo`, which shows other
//! apps' titles only with the Screen Recording permission EchoLedger already has for screen moments), matched against
//! the sharing-bar patterns and told apart with the meeting auto mode detected (`detect`).
//!
//! Once a share took the windows off they stay off while the meeting seen with it goes on: a browser's bar can be
//! hidden and Zoom can hide its controls while the share goes on, so a missing bar alone proves nothing. They come back
//! once that meeting has ended (or none was seen with the share) and no bar has shown for a moment over several reads
//! in a row; a read of the window list that failed changes nothing. The person can ask for them sooner ("Show
//! EchoLedger windows"; a bar still on screen takes them off again at the next look).
//!
//! It looks every 140 ms only while a meeting is detected, once a second otherwise, and every few seconds without the
//! Screen Recording permission (the privacy page says it can't work then). The setting Off, "I share one window or a
//! tab", the tray's demo and the guided test turn it off. Public APIs only; EchoLedger stays in every window list.

use std::collections::{BTreeMap, BTreeSet};
use std::time::{Duration, Instant};

use serde::Serialize;

use super::apps::MeetingApp;
use super::bars::{Found, Listed, Mode, Patterns};
use crate::detect::classify::SourceApp;

/// How often the window list is read while a meeting is detected: a bar appearing just after a look is seen one period
/// later, inside the 300 ms budget with the read and the ordering out.
pub const POLL: Duration = Duration::from_millis(140);
/// No meeting detected: shares start in meetings, so once a second is enough.
pub const SLOW_POLL: Duration = Duration::from_secs(1);
/// The windows are off for a share: only its end is looked for.
pub const HIDDEN_POLL: Duration = Duration::from_millis(280);
/// The safety net is off (the setting, one window, the demo, the guided test): only a change of that is looked for.
pub const IDLE_POLL: Duration = Duration::from_secs(1);
/// Without the Screen Recording permission nothing can be seen: only the permission coming back is looked for.
pub const NO_PERMISSION_POLL: Duration = Duration::from_secs(5);
/// From the bar appearing to the windows leaving the screen, at most.
pub const BUDGET: Duration = Duration::from_millis(300);
/// The windows come back once no bar has shown for this long over at least [`RELEASE_READS`] reads in a row...
pub const RELEASE_AFTER: Duration = Duration::from_millis(1500);
pub const RELEASE_READS: u32 = 3;
/// ...and the meeting seen with the share has been gone this long (detection can miss a beat).
pub const MEETING_GONE: Duration = Duration::from_secs(3);
/// How long the apps the guided test confirmed are believed before they are read again (an answer reads them at once).
pub const CONFIRMED_TTL: Duration = Duration::from_secs(30);

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

/// Whether EchoLedger's windows must leave the screen for the share in this look (pure).
pub fn should_hide(found: Option<&Found>, meeting: Option<SourceApp>, one_window: bool, off: bool, confirmed: impl FnOnce(MeetingApp) -> bool) -> Option<Seen> {
    let found = found?;
    let app = app_of(found, meeting);
    let hid = !off && !one_window && found.mode == Mode::Screen && !app.is_some_and(confirmed);
    Some(Seen { app, mode: found.mode, hid })
}

/// Whether the safety net works now (pure): some window is hidden from screen shares (none is while every window is
/// shown on purpose: `hideFromSharing` Off or the demo, the card and the captions shown by the person, the main window
/// not hidden), "I share one window or a tab", and the guided test (it shares the whole screen on purpose, and its
/// answer is given in the main window).
pub fn allowed(choice: &super::Choice, one_window: bool, guided: bool) -> bool {
    (choice.main_hidden || choice.any_floating_hidden()) && !one_window && !guided
}

/// What the loop knows before a look, gathered cheaply each time.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct Situation {
    /// [`allowed`].
    pub allowed: bool,
    /// macOS Screen Recording is allowed: without it other apps' titles read empty and no bar can be seen.
    pub permission: bool,
    /// The meeting detected now: its process tree and app.
    pub meeting: Option<(u32, SourceApp)>,
}

/// How long until the next look (pure).
pub fn period(s: &Situation, hidden: bool) -> Duration {
    if !s.allowed {
        IDLE_POLL
    } else if !s.permission {
        NO_PERMISSION_POLL
    } else if hidden {
        HIDDEN_POLL
    } else if s.meeting.is_some() {
        POLL
    } else {
        SLOW_POLL
    }
}

/// Keeps the windows off once a whole-screen share took them off (pure; see the module's text).
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct Latch {
    on: bool,
    /// The meeting detected with the share (its process tree), when there was one.
    meeting: Option<u32>,
    /// Since when, and over how many reads in a row, no bar that hides the windows was seen.
    clear_since: Option<Instant>,
    clear_reads: u32,
    /// Since when the meeting seen with the share is gone.
    gone_since: Option<Instant>,
}

impl Latch {
    pub fn on(&self) -> bool {
        self.on
    }

    pub fn release(&mut self) {
        *self = Self::default();
    }

    /// One successful read: `hide` = it showed a whole-screen share that takes the windows off; `meeting` = the meeting
    /// detected now. True while the windows must stay off.
    pub fn look(&mut self, hide: bool, meeting: Option<u32>, now: Instant) -> bool {
        if hide {
            self.on = true;
            if meeting.is_some() {
                self.meeting = meeting;
            }
            (self.clear_since, self.clear_reads, self.gone_since) = (None, 0, None);
            return true;
        }
        if !self.on {
            return false;
        }
        self.clear_reads += 1;
        let clear_since = *self.clear_since.get_or_insert(now);
        let meeting_over = match self.meeting {
            Some(m) if meeting == Some(m) => {
                self.gone_since = None;
                false
            }
            Some(_) => now.saturating_duration_since(*self.gone_since.get_or_insert(now)) >= MEETING_GONE,
            None => true,
        };
        if meeting_over && self.clear_reads >= RELEASE_READS && now.saturating_duration_since(clear_since) >= RELEASE_AFTER {
            self.release();
        }
        self.on
    }
}

/// What one look decided.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Step {
    /// The windows must be off the screen now.
    pub hide: bool,
    /// This read saw a sharing bar that takes the windows off ("Show EchoLedger windows" can't bring them back now).
    pub bar: bool,
    /// The share for the status: the one seen now, or the one that keeps the windows off.
    pub seen: Option<Seen>,
    /// Lines about bars no pattern knows, new this run (the loop logs each once).
    pub unknown: Vec<String>,
    pub next: Duration,
}

/// The safety net's memory between looks (pure apart from `confirmed`).
#[derive(Debug, Default)]
pub struct Watcher {
    latch: Latch,
    holding: Option<Seen>,
    logged: BTreeSet<String>,
    /// The last read saw a sharing bar that takes the windows off.
    bar: bool,
}

impl Watcher {
    pub fn hidden(&self) -> bool {
        self.latch.on()
    }

    /// "Show EchoLedger windows" during a share, or the safety net turned off: the windows may come back (a bar still on
    /// screen takes them off again at the next look).
    pub fn release(&mut self) {
        self.latch.release();
        self.holding = None;
        self.bar = false;
    }

    /// One look. `read` is the window list; none when the system did not answer (an empty list counts as that: the Dock
    /// and the menu bar are always in it).
    pub fn look(&mut self, s: &Situation, read: Option<&[Listed]>, patterns: &Patterns, confirmed: impl FnOnce(MeetingApp) -> bool, now: Instant) -> Step {
        if !s.allowed || !s.permission {
            self.release();
            return Step { hide: false, bar: false, seen: None, unknown: Vec::new(), next: period(s, false) };
        }
        let Some(windows) = read.filter(|w| !w.is_empty()) else {
            return Step { hide: self.latch.on(), bar: self.bar, seen: self.holding.clone(), unknown: Vec::new(), next: period(s, self.latch.on()) };
        };
        let found = super::bars::find(patterns, "macos", windows);
        let unknown = super::bars::unknown(patterns, "macos", windows).iter().map(super::bars::unknown_line).filter(|line| self.logged.insert(line.clone())).collect();
        let seen = should_hide(found.as_ref(), s.meeting.map(|m| m.1), false, false, confirmed);
        let hid_now = seen.as_ref().is_some_and(|x| x.hid);
        if hid_now {
            self.holding = seen.clone();
        }
        let hide = self.latch.look(hid_now, s.meeting.map(|m| m.0), now);
        if !hide {
            self.holding = None;
        }
        self.bar = hid_now;
        Step { hide, bar: hid_now, seen: if hide { self.holding.clone() } else { seen }, unknown, next: period(s, hide) }
    }
}

/// The apps the guided test confirmed hidden for their installed version on this macOS version, read at most once a
/// [`CONFIRMED_TTL`] or after a new answer (`generation`), never per look.
#[derive(Debug, Default)]
pub struct Confirmed {
    read: Option<(Instant, u64)>,
    apps: BTreeMap<MeetingApp, bool>,
}

impl Confirmed {
    pub fn get(&mut self, app: MeetingApp, now: Instant, generation: u64, read: impl FnOnce() -> BTreeMap<MeetingApp, bool>) -> bool {
        let fresh = self.read.is_some_and(|(at, g)| g == generation && now.saturating_duration_since(at) < CONFIRMED_TTL);
        if !fresh {
            self.apps = read();
            self.read = Some((now, generation));
        }
        self.apps.get(&app).copied().unwrap_or(false)
    }
}

/// The window list now (macOS): every on-screen window's owner, title and layer; none when the system did not answer.
#[cfg(target_os = "macos")]
pub fn window_list() -> Option<Vec<Listed>> {
    use objc2_core_foundation::{CFDictionary, CFNumber, CFString, CFType};
    use objc2_core_graphics::{CGWindowListCopyWindowInfo, CGWindowListOption, kCGWindowLayer, kCGWindowName, kCGWindowOwnerName, kCGWindowOwnerPID};

    let own = std::process::id() as i64;
    let list = CGWindowListCopyWindowInfo(CGWindowListOption::OptionOnScreenOnly | CGWindowListOption::ExcludeDesktopElements, 0)?;
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
        out.push(Listed { owner: text(owner_key), title: text(name_key), layer: number(layer_key) });
    }
    Some(out)
}

/// The safety net's loop (macOS).
#[cfg(target_os = "macos")]
pub fn spawn(app: tauri::AppHandle) {
    use tauri::{Emitter, Manager};
    /// How often the permission and the patterns are read again.
    const PERMISSION_EVERY: Duration = Duration::from_secs(5);
    const PATTERNS_EVERY: Duration = Duration::from_secs(600);
    std::thread::Builder::new()
        .name("el-share-watch".into())
        .spawn(move || {
            let state = app.state::<crate::AppState>();
            let privacy = app.state::<super::Privacy>();
            let mut patterns = super::bars::current(&state.data_dir);
            let mut patterns_at = Instant::now();
            let mut permission = (crate::screens::platform::macos::permission(), Instant::now());
            let mut watcher = Watcher::default();
            let mut confirmed = Confirmed::default();
            let mut points = super::Points::default();
            loop {
                let now = Instant::now();
                if now.saturating_duration_since(permission.1) >= PERMISSION_EVERY {
                    permission = (crate::screens::platform::macos::permission(), now);
                    // The system makes windows of its own in this process later (TextInputUI's, once a page loaded).
                    super::seal_others(&app);
                    // Woken from sleep, or the displays changed: every flag is set again (EchoLedger's own lifecycle points).
                    if let Some(point) = points.tick(std::time::SystemTime::now(), PERMISSION_EVERY, super::displays(&app)) {
                        super::at_point(&app, point);
                    }
                }
                if now.saturating_duration_since(patterns_at) >= PATTERNS_EVERY {
                    patterns = super::bars::current(&state.data_dir);
                    patterns_at = now;
                }
                let one_window = state.settings_with(|s| s.share_one_window);
                let guided = app.get_webview_window(super::GUIDED_CARD).is_some();
                let situation = Situation {
                    allowed: allowed(&super::choice_now(&app), one_window, guided),
                    permission: permission.0,
                    meeting: state.detect.meeting().map(|m| (m.root_pid, m.app)),
                };
                if privacy.take_share_release() {
                    watcher.release();
                }
                let read = (situation.allowed && situation.permission).then(window_list).flatten();
                let generation = privacy.guided_generation();
                let step = watcher.look(&situation, read.as_deref(), &patterns, |a| {
                    confirmed.get(a, now, generation, || {
                        let store = super::check::Store::load(&state.data_dir);
                        let os = super::check::os_version();
                        MeetingApp::ALL.into_iter().map(|m| (m, super::apps::confirmed_hidden(m, super::apps::version(m).as_deref(), &os, &store.guided))).collect()
                    })
                }, now);
                for line in &step.unknown {
                    if !super::diag::has(&state.data_dir, line) {
                        super::diag::log(&state.data_dir, line);
                    }
                }
                let seen_changed = {
                    let mut seen = privacy.share_seen.lock().unwrap_or_else(|p| p.into_inner());
                    let changed = *seen != step.seen;
                    *seen = step.seen.clone();
                    changed
                };
                let bar_before = privacy.bar_holds();
                privacy.set_bar_on_screen(step.bar);
                if step.hide != privacy.hidden_for(super::HideReason::Share) {
                    super::set_hidden(&app, super::HideReason::Share, step.hide); // the tray and the pages follow
                } else if privacy.bar_holds() != bar_before {
                    // "Show EchoLedger windows" comes back once the bar is hidden, and goes while it is on screen.
                    let _ = app.emit("privacy:changed", ());
                    crate::actions::emit_status(&app);
                } else if seen_changed {
                    let _ = app.emit("privacy:changed", ());
                }
                std::thread::sleep(step.next);
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

    fn patterns() -> Patterns {
        Patterns::fallback()
    }

    fn zoom_bar() -> Listed {
        Listed { owner: "zoom.us".into(), title: "zoom share toolbar window".into(), layer: 25 }
    }

    fn desk(bar: bool) -> Vec<Listed> {
        let mut windows = vec![Listed { owner: "zoom.us".into(), title: "Zoom Meeting".into(), layer: 0 }, Listed { owner: "Dock".into(), title: String::new(), layer: 20 }];
        if bar {
            windows.push(zoom_bar());
        }
        windows
    }

    const MEETING: Option<(u32, SourceApp)> = Some((4242, SourceApp::Zoom));

    fn watching(meeting: Option<(u32, SourceApp)>) -> Situation {
        Situation { allowed: true, permission: true, meeting }
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
    fn the_demo_the_setting_one_window_and_the_guided_test_turn_the_safety_net_off() {
        use crate::settings::ShareHiding::*;
        let choice = |hiding, demo, shown: bool, main_hidden| super::super::Choice { hiding, demo, main_hidden, show_card: shown, show_captions: shown };
        assert!(allowed(&choice(All, false, false, false), false, false));
        assert!(allowed(&choice(Floating, false, false, false), false, false), "the floating windows still leave");
        assert!(allowed(&choice(Off, false, false, false), false, false), "the card and the captions are hidden whatever the setting");
        assert!(allowed(&choice(All, true, false, false), false, false), "the demo never shows the card or the captions");
        assert!(!allowed(&choice(All, true, true, false), false, false), "Show in screen share for 10 minutes with the card and captions shown: shown on purpose");
        assert!(!allowed(&choice(Off, false, true, false), false, false), "every window shown on purpose");
        assert!(allowed(&choice(Off, false, true, true), false, false), "the main window is hidden");
        assert!(!allowed(&choice(All, false, false, false), true, false), "I share one window or a tab");
        assert!(!allowed(&choice(All, false, false, false), false, true), "the guided test shares the whole screen on purpose");
    }

    #[test]
    fn it_looks_fast_only_in_a_meeting_and_slowly_without_the_permission() {
        assert_eq!(period(&watching(MEETING), false), POLL);
        assert_eq!(period(&watching(None), false), SLOW_POLL, "no meeting: once a second, not 7 times");
        assert_eq!(period(&watching(MEETING), true), HIDDEN_POLL, "off the screen: only the end is looked for");
        assert_eq!(period(&Situation { permission: false, ..watching(MEETING) }, false), NO_PERMISSION_POLL);
        assert_eq!(period(&Situation { allowed: false, ..watching(MEETING) }, false), IDLE_POLL);
    }

    #[test]
    fn a_hidden_bar_keeps_the_windows_off_while_the_meeting_goes_on() {
        let mut w = Watcher::default();
        let t0 = Instant::now();
        assert!(w.look(&watching(MEETING), Some(&desk(true)), &patterns(), |_| false, t0).hide);
        // Chrome's Hide, Zoom's hidden controls: no bar for a minute, the same meeting goes on.
        for s in 1..=60 {
            let step = w.look(&watching(MEETING), Some(&desk(false)), &patterns(), |_| false, t0 + Duration::from_secs(s));
            assert!(step.hide, "still off at {s} s");
            assert_eq!(step.seen.as_ref().map(|x| x.app), Some(Some(MeetingApp::Zoom)), "the status keeps the share");
        }
    }

    #[test]
    fn they_come_back_once_the_meeting_ended_and_no_bar_showed_for_a_moment() {
        let mut w = Watcher::default();
        let t0 = Instant::now();
        assert!(w.look(&watching(MEETING), Some(&desk(true)), &patterns(), |_| false, t0).hide);
        let at = |ms: u64| t0 + Duration::from_millis(ms);
        // The meeting ends with the share: off a little longer, in case detection missed a beat.
        assert!(w.look(&watching(None), Some(&desk(false)), &patterns(), |_| false, at(300)).hide);
        assert!(w.look(&watching(None), Some(&desk(false)), &patterns(), |_| false, at(2000)).hide, "the meeting is gone only 1.7 s");
        let back = w.look(&watching(None), Some(&desk(false)), &patterns(), |_| false, at(3400));
        assert!(!back.hide, "gone 3 s, no bar for 3 s over 3 reads");
        assert_eq!(back.seen, None);
        assert_eq!(back.next, SLOW_POLL);
    }

    #[test]
    fn without_a_meeting_the_bar_must_stay_away_for_several_reads() {
        let mut w = Watcher::default();
        let t0 = Instant::now();
        let at = |ms: u64| t0 + Duration::from_millis(ms);
        assert!(w.look(&watching(None), Some(&desk(true)), &patterns(), |_| false, t0).hide);
        assert!(w.look(&watching(None), Some(&desk(false)), &patterns(), |_| false, at(280)).hide, "one read without the bar is not the end");
        assert!(w.look(&watching(None), Some(&desk(true)), &patterns(), |_| false, at(560)).hide, "it was back: start again");
        assert!(w.look(&watching(None), Some(&desk(false)), &patterns(), |_| false, at(840)).hide);
        assert!(w.look(&watching(None), Some(&desk(false)), &patterns(), |_| false, at(2400)).hide, "two reads in 1.6 s");
        assert!(!w.look(&watching(None), Some(&desk(false)), &patterns(), |_| false, at(2680)).hide, "three reads over 1.8 s");
    }

    #[test]
    fn a_failed_or_empty_read_never_brings_the_windows_back() {
        let mut w = Watcher::default();
        let t0 = Instant::now();
        assert!(w.look(&watching(None), Some(&desk(true)), &patterns(), |_| false, t0).hide);
        for s in 1..=30 {
            let now = t0 + Duration::from_secs(s);
            assert!(w.look(&watching(None), None, &patterns(), |_| false, now).hide, "the system did not answer at {s} s");
            assert!(w.look(&watching(None), Some(&[]), &patterns(), |_| false, now).hide, "an empty list is a failed read");
        }
        // And a failed read never takes them off either.
        let mut fresh = Watcher::default();
        assert!(!fresh.look(&watching(MEETING), None, &patterns(), |_| false, t0).hide);
    }

    #[test]
    fn the_person_can_ask_for_the_windows_but_a_bar_on_screen_takes_them_off_again() {
        let mut w = Watcher::default();
        let t0 = Instant::now();
        assert!(w.look(&watching(MEETING), Some(&desk(true)), &patterns(), |_| false, t0).hide);
        // Show EchoLedger windows after the share, the meeting still on.
        w.release();
        assert!(!w.look(&watching(MEETING), Some(&desk(false)), &patterns(), |_| false, t0 + Duration::from_secs(1)).hide);
        // Pressed while the bar is still there: off again at the next look.
        w.release();
        assert!(w.look(&watching(MEETING), Some(&desk(true)), &patterns(), |_| false, t0 + Duration::from_secs(2)).hide);
    }

    #[test]
    fn each_look_says_whether_the_sharing_bar_is_on_screen() {
        let mut w = Watcher::default();
        let t0 = Instant::now();
        let first = w.look(&watching(MEETING), Some(&desk(true)), &patterns(), |_| false, t0);
        assert!(first.hide && first.bar, "the bar is there: Show is not offered");
        // Zoom hides its controls while the share goes on: still off, but Show can bring them back now.
        let hidden_bar = w.look(&watching(MEETING), Some(&desk(false)), &patterns(), |_| false, t0 + Duration::from_secs(1));
        assert!(hidden_bar.hide && !hidden_bar.bar);
        // A read the system did not answer keeps what the last one saw.
        w.look(&watching(MEETING), Some(&desk(true)), &patterns(), |_| false, t0 + Duration::from_secs(2));
        assert!(w.look(&watching(MEETING), None, &patterns(), |_| false, t0 + Duration::from_secs(3)).bar);
        w.release();
        assert!(!w.look(&watching(MEETING), None, &patterns(), |_| false, t0 + Duration::from_secs(4)).bar);
    }

    #[test]
    fn turning_the_safety_net_off_or_losing_the_permission_lets_the_windows_back_and_clears_the_share() {
        let mut w = Watcher::default();
        let t0 = Instant::now();
        assert!(w.look(&watching(MEETING), Some(&desk(true)), &patterns(), |_| false, t0).hide);
        let off = w.look(&Situation { allowed: false, ..watching(MEETING) }, None, &patterns(), |_| false, t0);
        assert_eq!(off, Step { hide: false, bar: false, seen: None, unknown: Vec::new(), next: IDLE_POLL }, "no share stays in the status");
        assert!(!w.hidden());
        assert!(w.look(&watching(MEETING), Some(&desk(true)), &patterns(), |_| false, t0).hide);
        let no_permission = w.look(&Situation { permission: false, ..watching(MEETING) }, None, &patterns(), |_| false, t0);
        assert!(!no_permission.hide && no_permission.seen.is_none());
    }

    #[test]
    fn an_unknown_bar_is_reported_once_a_run() {
        let mut w = Watcher::default();
        let odd = vec![Listed { owner: "zoom.us".into(), title: "Screen sharing meeting controls".into(), layer: 25 }];
        let first = w.look(&watching(MEETING), Some(&odd), &patterns(), |_| false, Instant::now());
        assert_eq!(first.unknown, vec!["Sharing bar no pattern knows: app \"zoom.us\", title \"Screen sharing meeting controls\".".to_string()]);
        assert!(w.look(&watching(MEETING), Some(&odd), &patterns(), |_| false, Instant::now()).unknown.is_empty(), "not read back from the log each look");
    }

    #[test]
    fn the_windows_leave_within_300_ms() {
        // A simulated meeting: the loop looks every period it asks for; the bar appears 1 ms after a look. The look that
        // first sees it asks for the hide; then the window list read and the ordering out (posted to the main thread)
        // must fit in what is left of the budget.
        const LIST_READ: Duration = Duration::from_millis(40);
        const ORDER_OUT: Duration = Duration::from_millis(60);
        let mut w = Watcher::default();
        let t0 = Instant::now();
        let mut now = t0;
        let appears = t0 + Duration::from_millis(1);
        let hidden_at = loop {
            let step = w.look(&watching(MEETING), Some(&desk(now >= appears)), &patterns(), |_| false, now);
            if step.hide {
                break now;
            }
            assert_eq!(step.next, POLL, "a meeting is detected: the fast look");
            now += step.next;
            assert!(now < t0 + Duration::from_secs(1), "never seen");
        };
        let latency = hidden_at.duration_since(appears) + LIST_READ + ORDER_OUT;
        assert!(latency <= BUDGET, "{latency:?}");
        assert_eq!(BUDGET, Duration::from_millis(300));
    }

    #[test]
    fn the_guided_results_are_read_twice_a_minute_or_after_an_answer() {
        let mut c = Confirmed::default();
        let reads = std::cell::Cell::new(0);
        let read = || {
            reads.set(reads.get() + 1);
            BTreeMap::from([(MeetingApp::Zoom, true)])
        };
        let t0 = Instant::now();
        assert!(c.get(MeetingApp::Zoom, t0, 0, read));
        assert!(!c.get(MeetingApp::Teams, t0 + Duration::from_secs(5), 0, read));
        assert_eq!(reads.get(), 1, "no share-check.json or Info.plist per look");
        c.get(MeetingApp::Zoom, t0 + Duration::from_secs(6), 1, read);
        assert_eq!(reads.get(), 2, "a new answer is read at once");
        c.get(MeetingApp::Zoom, t0 + Duration::from_secs(6) + CONFIRMED_TTL, 1, read);
        assert_eq!(reads.get(), 3);
    }
}
