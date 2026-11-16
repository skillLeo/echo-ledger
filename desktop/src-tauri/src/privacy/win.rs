//! Windows: the display affinity of every EchoLedger window, set and read back. `WDA_EXCLUDEFROMCAPTURE` (build 19041
//! and later) leaves a window out of every capture; on older builds the same call silently acts like `WDA_MONITOR` (a
//! black box), so there the person chooses a black box (default) or the window as it is. Remote Desktop ignores the
//! flag. EchoLedger sets the flag when a window is built and when the setting or the demo changes. The flag can be
//! turned off from inside the process (IOActive, 3 Sep 2026; a capture or monitoring tool, for example), so while a
//! protected window is visible it is read back every 5 s. A flag that changed without EchoLedger is never set again by
//! itself (the scope rule: nothing against monitoring tools): the change is logged (which window and what was read,
//! never its contents), the windows count as visible in screen share for the rest of the session, and the privacy page
//! offers "Hide again", which sets every flag again only when the person presses it. The Win32 calls sit behind
//! [`Affinity`], so the rules are tested on any system; [`System`] is the real one.

use std::collections::BTreeMap;
use std::time::Duration;

use crate::settings::OldWindowsSharing;

pub const WDA_NONE: u32 = 0x0;
pub const WDA_MONITOR: u32 = 0x1;
pub const WDA_EXCLUDEFROMCAPTURE: u32 = 0x11;
/// Windows 10 version 2004: the first build with `WDA_EXCLUDEFROMCAPTURE`.
pub const EXCLUDE_BUILD: u32 = 19_041;
/// How often a visible protected window's flag is read back.
pub const RECHECK_EVERY: Duration = Duration::from_secs(5);

/// The Win32 calls the privacy service makes.
pub trait Affinity {
    /// The build number (`RtlGetVersion`, which does not lie for compatibility).
    fn build(&self) -> Option<u32>;
    /// `GetSystemMetrics(SM_REMOTESESSION)`.
    fn remote_session(&self) -> bool;
    /// `SetWindowDisplayAffinity`; true when Windows said yes.
    fn set(&self, hwnd: isize, affinity: u32) -> bool;
    /// `GetWindowDisplayAffinity`; none when Windows did not say.
    fn get(&self, hwnd: isize) -> Option<u32>;
    /// `IsWindow`: the handle still names a window (one destroyed meanwhile is never read).
    fn exists(&self, hwnd: isize) -> bool;
}

/// The affinity a window should have (pure).
pub fn wanted(protect: bool, build: Option<u32>, old: OldWindowsSharing) -> u32 {
    if !protect {
        return WDA_NONE;
    }
    match build {
        Some(b) if b >= EXCLUDE_BUILD => WDA_EXCLUDEFROMCAPTURE,
        // An unknown build is treated as old: a black box is private, and the status says to check.
        _ if old == OldWindowsSharing::BlackBox => WDA_MONITOR,
        _ => WDA_NONE,
    }
}

/// The affinity of a window, with the shield (pure): while screen moments capture a whole screen the main window is
/// kept out of the capture whatever the setting says, as a black box at least on Windows before 19041 (where "Show
/// normally" would leave it in the picture).
pub fn affinity(protect: bool, shielded: bool, build: Option<u32>, old: OldWindowsSharing) -> u32 {
    if shielded {
        return if build.is_some_and(|b| b >= EXCLUDE_BUILD) { WDA_EXCLUDEFROMCAPTURE } else { WDA_MONITOR };
    }
    wanted(protect, build, old)
}

/// What reading a window's flag back found.
#[derive(Clone, Copy, Debug, PartialEq, Eq, serde::Serialize)]
#[serde(rename_all = "snake_case", tag = "state")]
pub enum Readback {
    /// The flag reads as set.
    Held,
    /// It reads as something else (or Windows did not say) even after setting it.
    Mismatch { wanted: u32, read: Option<u32> },
}

/// Sets `wanted` on one window and reads it back.
pub fn apply(system: &dyn Affinity, hwnd: isize, wanted: u32) -> Readback {
    let _ = system.set(hwnd, wanted);
    match system.get(hwnd) {
        Some(read) if read == wanted => Readback::Held,
        read => Readback::Mismatch { wanted, read },
    }
}

/// One window the 5 s check reads back, looked up on the main thread when the check runs.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Watched {
    pub label: String,
    pub hwnd: isize,
}

/// A diagnostics line: which window, what was read, and that it was left as it is; never the window's contents or title.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Changed {
    pub label: String,
    /// What EchoLedger had set.
    pub set: u32,
    pub read: Option<u32>,
}

impl Changed {
    pub fn line(&self) -> String {
        let read = self.read.map_or_else(|| "nothing".to_string(), |r| format!("0x{r:x}"));
        format!("The capture flag of the {} read {read} instead of 0x{:x}, changed by something else on this computer: left as it is until Hide again.", super::window_name(&self.label), self.set)
    }
}

/// The read-back state of every window, kept between checks.
#[derive(Debug, Default)]
pub struct Watch {
    pub last: BTreeMap<String, Readback>,
    /// What EchoLedger itself set on each window last: the 5 s check compares with this, so a setting or demo change
    /// that is still on its way never reads as a change from outside.
    applied: BTreeMap<String, u32>,
    /// A flag changed without EchoLedger this session: visible in screen share until "Hide again".
    changed_outside: bool,
}

impl Watch {
    /// The 5 s check (pure apart from the calls on `system`): reads each visible window back against what EchoLedger
    /// set. A flag that held and now reads otherwise was changed by something else: reported once, at that change, and
    /// left as it is. A flag that never held (Windows did not keep it when EchoLedger set it) stays a mismatch, said as
    /// such, never blamed on something else. A window that is gone is forgotten, never read.
    pub fn tick(&mut self, system: &dyn Affinity, windows: &[Watched]) -> Vec<Changed> {
        let mut changed = Vec::new();
        for w in windows {
            let Some(&set) = self.applied.get(&w.label) else { continue };
            if !system.exists(w.hwnd) {
                self.forget(&w.label);
                continue;
            }
            if set == WDA_NONE {
                continue; // shown on purpose: nothing to keep
            }
            let read = system.get(w.hwnd);
            if read == Some(set) {
                self.last.insert(w.label.clone(), Readback::Held);
                continue;
            }
            let held = self.last.get(&w.label) == Some(&Readback::Held);
            self.last.insert(w.label.clone(), Readback::Mismatch { wanted: set, read });
            if held {
                self.changed_outside = true;
                changed.push(Changed { label: w.label.clone(), set, read });
            }
        }
        changed
    }

    /// EchoLedger set `affinity` on `label` (a window built, the setting or the demo changed, Hide again) and read back
    /// `readback`.
    pub fn applied(&mut self, label: &str, affinity: u32, readback: Readback) {
        self.applied.insert(label.to_string(), affinity);
        if affinity == WDA_NONE {
            self.last.remove(label);
        } else {
            self.last.insert(label.to_string(), readback);
        }
    }

    /// The window is gone (destroyed, or closed by the Share Check).
    pub fn forget(&mut self, label: &str) {
        self.last.remove(label);
        self.applied.remove(label);
    }

    /// "Hide again": the flags are about to be set again by the person's choice.
    pub fn hide_again(&mut self) {
        self.changed_outside = false;
    }

    pub fn changed_outside(&self) -> bool {
        self.changed_outside
    }

    pub fn any_mismatch(&self) -> bool {
        self.changed_outside || self.last.values().any(|r| matches!(r, Readback::Mismatch { .. }))
    }

    pub fn any_held(&self) -> bool {
        self.last.values().any(|r| *r == Readback::Held)
    }
}

/// A status line of the privacy page, whether it is a warning (amber), and the one button that fixes it.
#[derive(Clone, Debug, PartialEq, Eq, serde::Serialize)]
#[serde(rename_all = "camelCase")]
pub struct Line {
    pub warning: bool,
    pub text: String,
    pub fix: Option<Fix>,
}

/// What a line's button does.
#[derive(Clone, Copy, Debug, PartialEq, Eq, serde::Serialize)]
#[serde(rename_all = "snake_case")]
pub enum Fix {
    /// The system's Screen Recording settings (Mac).
    OpenSettings,
    /// Sets the capture flag of every window again (Windows, after something else changed it).
    HideAgain,
}

impl Line {
    pub fn warning(text: &str) -> Self {
        Self { warning: true, text: text.into(), fix: None }
    }
}

pub const OLD_WINDOWS_LINE: &str = "On this Windows version, people see a black box instead of EchoLedger. Update Windows for a clear result.";
pub const OLD_WINDOWS_SHOWN_LINE: &str = "On this Windows version, EchoLedger can't be left out of a screen share, so it shows as it is. Choose Black box to keep its text private, or update Windows.";
pub const REMOTE_LINE: &str = "In Remote Desktop, EchoLedger windows are visible to the remote side.";
pub const MISMATCH_LINE: &str = "Windows didn't keep an EchoLedger window hidden from screen share. Run the check to be sure.";
pub const CHANGED_OUTSIDE_LINE: &str = "Something on this computer turned off screen-share privacy for an EchoLedger window, so it is visible in screen share. EchoLedger left it as it is.";

/// The amber lines of the Windows status (pure): a flag changed without EchoLedger comes first, with "Hide again".
pub fn lines(build: Option<u32>, remote: bool, old: OldWindowsSharing, mismatch: bool, changed_outside: bool) -> Vec<Line> {
    let mut out = Vec::new();
    if changed_outside {
        out.push(Line { fix: Some(Fix::HideAgain), ..Line::warning(CHANGED_OUTSIDE_LINE) });
    }
    if remote {
        out.push(Line::warning(REMOTE_LINE));
    }
    if build.is_none_or(|b| b < EXCLUDE_BUILD) {
        let text = if old == OldWindowsSharing::BlackBox { OLD_WINDOWS_LINE } else { OLD_WINDOWS_SHOWN_LINE };
        out.push(Line::warning(text));
    }
    if mismatch && !changed_outside {
        out.push(Line::warning(MISMATCH_LINE));
    }
    out
}

/// The real calls.
#[cfg(windows)]
pub struct System;

#[cfg(windows)]
impl Affinity for System {
    fn build(&self) -> Option<u32> {
        crate::audio::platform::windows::os_build()
    }

    fn remote_session(&self) -> bool {
        use windows::Win32::UI::WindowsAndMessaging::{GetSystemMetrics, SM_REMOTESESSION};
        unsafe { GetSystemMetrics(SM_REMOTESESSION) != 0 }
    }

    fn set(&self, hwnd: isize, affinity: u32) -> bool {
        use windows::Win32::Foundation::HWND;
        use windows::Win32::UI::WindowsAndMessaging::{SetWindowDisplayAffinity, WINDOW_DISPLAY_AFFINITY};
        unsafe { SetWindowDisplayAffinity(HWND(hwnd as *mut _), WINDOW_DISPLAY_AFFINITY(affinity)) }.is_ok()
    }

    fn get(&self, hwnd: isize) -> Option<u32> {
        use windows::Win32::Foundation::HWND;
        use windows::Win32::UI::WindowsAndMessaging::GetWindowDisplayAffinity;
        let mut affinity = 0u32;
        unsafe { GetWindowDisplayAffinity(HWND(hwnd as *mut _), &mut affinity) }.ok()?;
        Some(affinity)
    }

    fn exists(&self, hwnd: isize) -> bool {
        use windows::Win32::Foundation::HWND;
        use windows::Win32::UI::WindowsAndMessaging::IsWindow;
        unsafe { IsWindow(Some(HWND(hwnd as *mut _))) }.as_bool()
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::cell::RefCell;
    use std::collections::HashMap;

    /// Windows as a test sees it: a build, a session, and each window's affinity.
    #[derive(Default)]
    struct FakeWindows {
        build: Option<u32>,
        remote: bool,
        affinity: RefCell<HashMap<isize, u32>>,
        /// Windows that ignore a set (an old build silently turns EXCLUDE into MONITOR: modelled separately).
        stuck: Vec<isize>,
        sets: RefCell<Vec<(isize, u32)>>,
        /// Windows destroyed since they were looked up.
        gone: Vec<isize>,
    }

    impl Affinity for FakeWindows {
        fn build(&self) -> Option<u32> {
            self.build
        }
        fn remote_session(&self) -> bool {
            self.remote
        }
        fn set(&self, hwnd: isize, affinity: u32) -> bool {
            self.sets.borrow_mut().push((hwnd, affinity));
            if self.stuck.contains(&hwnd) {
                return true; // says yes, keeps the old value: what injected code or an old build looks like
            }
            let stored = if self.build.is_some_and(|b| b < EXCLUDE_BUILD) && affinity == WDA_EXCLUDEFROMCAPTURE { WDA_MONITOR } else { affinity };
            self.affinity.borrow_mut().insert(hwnd, stored);
            true
        }
        fn get(&self, hwnd: isize) -> Option<u32> {
            assert!(!self.gone.contains(&hwnd), "a destroyed window is never read");
            self.affinity.borrow().get(&hwnd).copied()
        }
        fn exists(&self, hwnd: isize) -> bool {
            !self.gone.contains(&hwnd)
        }
    }

    #[test]
    fn the_build_gate_picks_exclude_from_19041_and_a_black_box_before() {
        assert_eq!(wanted(true, Some(26_100), OldWindowsSharing::BlackBox), WDA_EXCLUDEFROMCAPTURE, "Windows 11 24H2");
        assert_eq!(wanted(true, Some(19_041), OldWindowsSharing::BlackBox), WDA_EXCLUDEFROMCAPTURE, "Windows 10 2004");
        assert_eq!(wanted(true, Some(18_363), OldWindowsSharing::BlackBox), WDA_MONITOR, "Windows 10 1909: a black box");
        assert_eq!(wanted(true, Some(18_363), OldWindowsSharing::Show), WDA_NONE, "Show normally");
        assert_eq!(wanted(true, None, OldWindowsSharing::BlackBox), WDA_MONITOR, "an unknown build is treated as old");
        assert_eq!(wanted(false, Some(26_100), OldWindowsSharing::BlackBox), WDA_NONE, "not protected");
    }

    #[test]
    fn the_screen_moments_shield_gives_at_least_a_black_box() {
        assert_eq!(affinity(false, true, Some(26_100), OldWindowsSharing::BlackBox), WDA_EXCLUDEFROMCAPTURE, "shielded whatever the setting");
        assert_eq!(affinity(true, true, Some(18_363), OldWindowsSharing::Show), WDA_MONITOR, "older Windows: a black box even with Show normally");
        assert_eq!(affinity(false, true, None, OldWindowsSharing::Show), WDA_MONITOR, "an unknown build too");
        assert_eq!(affinity(true, false, Some(18_363), OldWindowsSharing::Show), WDA_NONE, "without the shield the setting decides");
        assert_eq!(affinity(true, false, Some(26_100), OldWindowsSharing::BlackBox), WDA_EXCLUDEFROMCAPTURE);
    }

    #[test]
    fn a_set_flag_is_read_back_and_a_mismatch_is_reported() {
        let windows = FakeWindows { build: Some(26_100), ..Default::default() };
        assert_eq!(apply(&windows, 1, WDA_EXCLUDEFROMCAPTURE), Readback::Held);
        // An old build turns EXCLUDE into MONITOR: the read-back says so.
        let old = FakeWindows { build: Some(18_363), ..Default::default() };
        assert_eq!(apply(&old, 1, WDA_EXCLUDEFROMCAPTURE), Readback::Mismatch { wanted: WDA_EXCLUDEFROMCAPTURE, read: Some(WDA_MONITOR) });
        // A window that keeps its old value.
        let stuck = FakeWindows { build: Some(26_100), stuck: vec![7], ..Default::default() };
        assert_eq!(apply(&stuck, 7, WDA_EXCLUDEFROMCAPTURE), Readback::Mismatch { wanted: WDA_EXCLUDEFROMCAPTURE, read: None });
    }

    fn watched(labels: &[(&str, isize)]) -> Vec<Watched> {
        labels.iter().map(|(label, hwnd)| Watched { label: label.to_string(), hwnd: *hwnd }).collect()
    }

    #[test]
    fn a_flag_changed_from_outside_is_reported_and_left_until_hide_again() {
        let windows = FakeWindows { build: Some(22_631), ..Default::default() };
        let mut watch = Watch::default();
        for (label, hwnd) in [("pill", 1), ("overlay", 2)] {
            let readback = apply(&windows, hwnd, WDA_EXCLUDEFROMCAPTURE);
            watch.applied(label, WDA_EXCLUDEFROMCAPTURE, readback);
        }
        let both = watched(&[("pill", 1), ("overlay", 2)]);
        assert!(watch.tick(&windows, &both).is_empty(), "nothing changed");
        assert!(watch.any_held() && !watch.any_mismatch());

        // Something in the process turned the pill's flag off.
        windows.affinity.borrow_mut().insert(1, WDA_NONE);
        let sets = windows.sets.borrow().len();
        let changed = watch.tick(&windows, &both);
        assert_eq!(changed, vec![Changed { label: "pill".into(), set: WDA_EXCLUDEFROMCAPTURE, read: Some(WDA_NONE) }]);
        assert_eq!(windows.sets.borrow().len(), sets, "never set again by itself");
        assert_eq!(windows.get(1), Some(WDA_NONE), "left as it is");
        assert_eq!(changed[0].line(), "The capture flag of the recording controls read 0x0 instead of 0x11, changed by something else on this computer: left as it is until Hide again.");
        assert!(watch.any_mismatch() && watch.changed_outside(), "visible in screen share");
        assert_eq!(lines(Some(22_631), false, OldWindowsSharing::BlackBox, true, true), vec![Line { fix: Some(Fix::HideAgain), ..Line::warning(CHANGED_OUTSIDE_LINE) }]);

        // It stays so for the session, even once a window is built again and its flag holds...
        watch.applied("pill", WDA_EXCLUDEFROMCAPTURE, apply(&windows, 1, WDA_EXCLUDEFROMCAPTURE));
        assert!(watch.tick(&windows, &both).is_empty());
        assert!(watch.any_mismatch(), "until the person chooses Hide again");
        watch.hide_again();
        assert!(!watch.any_mismatch());
        assert_eq!(RECHECK_EVERY, Duration::from_secs(5));
    }

    #[test]
    fn a_flag_still_changed_at_the_next_tick_is_not_reported_again() {
        let windows = FakeWindows { build: Some(26_100), ..Default::default() };
        let mut watch = Watch::default();
        watch.applied("pill", WDA_EXCLUDEFROMCAPTURE, apply(&windows, 1, WDA_EXCLUDEFROMCAPTURE));
        let pill = watched(&[("pill", 1)]);
        assert!(watch.tick(&windows, &pill).is_empty());
        windows.affinity.borrow_mut().insert(1, WDA_NONE);
        assert_eq!(watch.tick(&windows, &pill).len(), 1, "reported at the change");
        // Two more ticks with the flag still off: nothing new to log, and it is still visible in screen share.
        assert!(watch.tick(&windows, &pill).is_empty());
        assert!(watch.tick(&windows, &pill).is_empty());
        assert!(watch.changed_outside() && watch.any_mismatch());
        assert_eq!(windows.sets.borrow().len(), 1, "never set again by itself");
    }

    #[test]
    fn a_flag_that_never_held_is_a_mismatch_not_a_change_by_something_else() {
        // Windows did not keep the flag when EchoLedger set it (a window that keeps its old value).
        let stuck = FakeWindows { build: Some(26_100), stuck: vec![7], ..Default::default() };
        let mut watch = Watch::default();
        watch.applied("overlay", WDA_EXCLUDEFROMCAPTURE, apply(&stuck, 7, WDA_EXCLUDEFROMCAPTURE));
        let overlay = watched(&[("overlay", 7)]);
        for _ in 0..3 {
            assert!(watch.tick(&stuck, &overlay).is_empty(), "nothing to blame on something else, tick after tick");
        }
        assert!(watch.any_mismatch() && !watch.changed_outside());
        let said = lines(Some(26_100), false, OldWindowsSharing::BlackBox, watch.any_mismatch(), watch.changed_outside());
        assert_eq!(said, vec![Line::warning(MISMATCH_LINE)], "\"Windows didn't keep it\", with no Hide again");
        // Once it holds (set again at a lifecycle point), a later change is one from outside.
        stuck.affinity.borrow_mut().insert(7, WDA_EXCLUDEFROMCAPTURE);
        assert!(watch.tick(&stuck, &overlay).is_empty());
        stuck.affinity.borrow_mut().insert(7, WDA_NONE);
        assert_eq!(watch.tick(&stuck, &overlay).len(), 1);
        assert!(watch.changed_outside());
    }

    #[test]
    fn a_change_echoledger_makes_itself_is_never_taken_for_one_from_outside() {
        let windows = FakeWindows { build: Some(26_100), ..Default::default() };
        let mut watch = Watch::default();
        watch.applied("main", WDA_EXCLUDEFROMCAPTURE, apply(&windows, 5, WDA_EXCLUDEFROMCAPTURE));
        // The setting went to Off: EchoLedger sets the main window to none and records it before the check reads it.
        watch.applied("main", WDA_NONE, apply(&windows, 5, WDA_NONE));
        assert!(watch.tick(&windows, &watched(&[("main", 5)])).is_empty());
        assert!(!watch.any_mismatch(), "shown on purpose is no change from outside");
        // A window EchoLedger never set (being built) is not read yet.
        assert!(watch.tick(&windows, &watched(&[("review", 9)])).is_empty());
    }

    #[test]
    fn a_window_destroyed_between_the_lookup_and_the_read_is_forgotten_not_reported() {
        let mut windows = FakeWindows { build: Some(26_100), ..Default::default() };
        let mut watch = Watch::default();
        watch.applied("review", WDA_EXCLUDEFROMCAPTURE, apply(&windows, 3, WDA_EXCLUDEFROMCAPTURE));
        windows.gone.push(3);
        assert!(watch.tick(&windows, &watched(&[("review", 3)])).is_empty(), "the after-call card closed after its grace");
        assert!(!watch.any_mismatch() && !watch.any_held(), "nothing is kept for it");
        // And forgetting a label (WindowEvent::Destroyed) drops what was set on it.
        watch.applied("pill", WDA_EXCLUDEFROMCAPTURE, Readback::Mismatch { wanted: WDA_EXCLUDEFROMCAPTURE, read: None });
        assert!(watch.any_mismatch());
        watch.forget("pill");
        assert!(!watch.any_mismatch());
    }

    #[test]
    fn remote_desktop_and_old_builds_give_amber_lines() {
        let remote = FakeWindows { build: Some(26_100), remote: true, ..Default::default() };
        assert_eq!(lines(remote.build(), remote.remote_session(), OldWindowsSharing::BlackBox, false, false), vec![Line::warning(REMOTE_LINE)]);
        assert_eq!(lines(Some(18_363), false, OldWindowsSharing::BlackBox, false, false)[0].text, OLD_WINDOWS_LINE);
        assert_eq!(lines(Some(18_363), false, OldWindowsSharing::Show, false, false)[0].text, OLD_WINDOWS_SHOWN_LINE);
        assert!(lines(Some(26_100), false, OldWindowsSharing::BlackBox, false, false).is_empty(), "nothing to say");
        assert_eq!(lines(Some(26_100), false, OldWindowsSharing::BlackBox, true, false)[0].text, MISMATCH_LINE);
    }
}
