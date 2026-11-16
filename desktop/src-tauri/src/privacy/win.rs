//! Windows: the display affinity of every EchoLedger window, set and read back. `WDA_EXCLUDEFROMCAPTURE` (build 19041
//! and later) leaves a window out of every capture; on older builds the same call silently acts like `WDA_MONITOR` (a
//! black box), so there the person chooses a black box (default) or the window as it is. Remote Desktop ignores the
//! flag. The flag can be turned off from inside the process (IOActive, 3 Sep 2026), so while a protected window is
//! visible it is read back every 5 s and set again when it changed. The Win32 calls sit behind [`Affinity`], so the
//! rules are tested on any system; [`System`] is the real one.

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

/// One window the 5 s check reads back.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Watched {
    pub label: String,
    pub hwnd: isize,
    pub wanted: u32,
}

/// A diagnostics line: which window, what was read, what happened; never the window's contents or title.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Changed {
    pub label: String,
    pub wanted: u32,
    pub read: Option<u32>,
    /// Setting it again held.
    pub restored: bool,
}

impl Changed {
    pub fn line(&self) -> String {
        let read = self.read.map_or_else(|| "nothing".to_string(), |r| format!("0x{r:x}"));
        let then = if self.restored { "set again, it held" } else { "set again, it did not hold" };
        format!("The {} window's capture flag read {read} instead of 0x{:x}: {then}.", self.label, self.wanted)
    }
}

/// The read-back state of every window, kept between checks.
#[derive(Debug, Default)]
pub struct Watch {
    pub last: BTreeMap<String, Readback>,
}

impl Watch {
    /// The 5 s check: reads each visible protected window back, sets a changed one again and reports it (pure apart
    /// from the calls on `system`).
    pub fn tick(&mut self, system: &dyn Affinity, windows: &[Watched]) -> Vec<Changed> {
        let mut changed = Vec::new();
        for w in windows {
            let read = system.get(w.hwnd);
            if read == Some(w.wanted) {
                self.last.insert(w.label.clone(), Readback::Held);
                continue;
            }
            let after = apply(system, w.hwnd, w.wanted);
            changed.push(Changed { label: w.label.clone(), wanted: w.wanted, read, restored: after == Readback::Held });
            self.last.insert(w.label.clone(), after);
        }
        changed
    }

    pub fn record(&mut self, label: &str, readback: Readback) {
        self.last.insert(label.to_string(), readback);
    }

    pub fn forget(&mut self, label: &str) {
        self.last.remove(label);
    }

    pub fn any_mismatch(&self) -> bool {
        self.last.values().any(|r| matches!(r, Readback::Mismatch { .. }))
    }

    pub fn any_held(&self) -> bool {
        self.last.values().any(|r| *r == Readback::Held)
    }
}

/// A status line of the Windows check, and whether it is a warning (amber).
#[derive(Clone, Debug, PartialEq, Eq, serde::Serialize)]
#[serde(rename_all = "camelCase")]
pub struct Line {
    pub warning: bool,
    pub text: String,
}

pub const OLD_WINDOWS_LINE: &str = "On this Windows version, people see a black box instead of EchoLedger. Update Windows for a clear result.";
pub const OLD_WINDOWS_SHOWN_LINE: &str = "On this Windows version, EchoLedger can't be left out of a share, so it shows as it is. Choose Black box to keep its text private, or update Windows.";
pub const REMOTE_LINE: &str = "In Remote Desktop, EchoLedger windows are visible to the remote side.";
pub const MISMATCH_LINE: &str = "Windows didn't keep EchoLedger hidden from screen sharing. It was set again; run the check to be sure.";

/// The amber lines of the Windows status (pure).
pub fn lines(build: Option<u32>, remote: bool, old: OldWindowsSharing, mismatch: bool) -> Vec<Line> {
    let mut out = Vec::new();
    if remote {
        out.push(Line { warning: true, text: REMOTE_LINE.into() });
    }
    if build.is_none_or(|b| b < EXCLUDE_BUILD) {
        let text = if old == OldWindowsSharing::BlackBox { OLD_WINDOWS_LINE } else { OLD_WINDOWS_SHOWN_LINE };
        out.push(Line { warning: true, text: text.into() });
    }
    if mismatch {
        out.push(Line { warning: true, text: MISMATCH_LINE.into() });
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
            self.affinity.borrow().get(&hwnd).copied()
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

    #[test]
    fn the_five_second_check_sets_a_changed_flag_again_and_logs_it_without_contents() {
        let windows = FakeWindows { build: Some(22_631), ..Default::default() };
        let watched = vec![Watched { label: "pill".into(), hwnd: 1, wanted: WDA_EXCLUDEFROMCAPTURE }, Watched { label: "overlay".into(), hwnd: 2, wanted: WDA_EXCLUDEFROMCAPTURE }];
        for w in &watched {
            apply(&windows, w.hwnd, w.wanted);
        }
        let mut watch = Watch::default();
        assert!(watch.tick(&windows, &watched).is_empty(), "nothing changed");
        assert!(watch.any_held() && !watch.any_mismatch());
        let sets_before = windows.sets.borrow().len();
        assert_eq!(sets_before, 2, "an unchanged flag is only read, never set again");

        // Something in the process turned the pill's flag off.
        windows.affinity.borrow_mut().insert(1, WDA_NONE);
        let changed = watch.tick(&windows, &watched);
        assert_eq!(changed, vec![Changed { label: "pill".into(), wanted: WDA_EXCLUDEFROMCAPTURE, read: Some(WDA_NONE), restored: true }]);
        assert_eq!(windows.get(1), Some(WDA_EXCLUDEFROMCAPTURE), "set again");
        assert_eq!(changed[0].line(), "The pill window's capture flag read 0x0 instead of 0x11: set again, it held.");
        assert!(!watch.any_mismatch());

        // One that cannot be set again stays a mismatch (the badge turns amber).
        let stuck = FakeWindows { build: Some(22_631), stuck: vec![1], ..Default::default() };
        let changed = watch.tick(&stuck, &watched[..1]);
        assert!(!changed[0].restored);
        assert!(watch.any_mismatch());
        assert_eq!(RECHECK_EVERY, Duration::from_secs(5));
    }

    #[test]
    fn remote_desktop_and_old_builds_give_amber_lines() {
        let remote = FakeWindows { build: Some(26_100), remote: true, ..Default::default() };
        assert_eq!(lines(remote.build(), remote.remote_session(), OldWindowsSharing::BlackBox, false), vec![Line { warning: true, text: REMOTE_LINE.into() }]);
        assert_eq!(lines(Some(18_363), false, OldWindowsSharing::BlackBox, false)[0].text, OLD_WINDOWS_LINE);
        assert_eq!(lines(Some(18_363), false, OldWindowsSharing::Show, false)[0].text, OLD_WINDOWS_SHOWN_LINE);
        assert!(lines(Some(26_100), false, OldWindowsSharing::BlackBox, false).is_empty(), "nothing to say");
        assert_eq!(lines(Some(26_100), false, OldWindowsSharing::BlackBox, true)[0].text, MISMATCH_LINE);
    }
}
