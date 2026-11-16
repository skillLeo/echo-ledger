//! Screen-share privacy (v1.8.1): one service owns the capture flag of every EchoLedger window. Each window is
//! registered with its kind before it is built ([`prepare`] sets the flag on the builder, so it holds before the first
//! show) and gets the flag again whenever the setting changes ([`apply_all`]). The setting "Hide EchoLedger from screen
//! sharing" chooses every window (default), only the floating ones, or none; the tray's "Show EchoLedger in screen
//! share for 10 minutes" lifts it for a demo and returns to it by itself.
//!
//! Scope (permanent): EchoLedger leaves its own windows out of screen shares and nothing else. It never renames its
//! process, never leaves window lists, the Dock or task switchers, and does nothing about proctoring apps, lockdown
//! browsers or monitoring software, which can always see it. Only public system APIs.
//!
//! - Windows ([`win`]): `WDA_EXCLUDEFROMCAPTURE` from build 19041, read back for every window and every 5 s while a
//!   protected window is visible; older builds get a black box (or the window as it is); Remote Desktop is told.
//! - Share Check ([`check`]): a protected magenta test window and an unprotected green control, the screen captured
//!   the way meeting apps capture it, the colours counted ([`pixels`]).
//! - Mac safety net ([`bars`], [`watch`]): a whole-screen share in a meeting app not confirmed hidden on this Mac
//!   takes the floating windows (and the main window with "All EchoLedger windows") off the screen until it is over.

pub mod apps;
pub mod bars;
pub mod capture;
pub mod check;
pub mod diag;
pub mod pixels;
pub mod status;
pub mod watch;
pub mod win;

use std::collections::{BTreeMap, BTreeSet};
use std::sync::atomic::{AtomicBool, AtomicU64, Ordering};
use std::sync::{Mutex, MutexGuard};
use std::time::{Duration, Instant};

use serde::Serialize;
use tauri::{AppHandle, Emitter, Manager, Runtime, WebviewWindow, WebviewWindowBuilder};

use crate::settings::{Settings, ShareHiding};

/// What a window is to the privacy service.
#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum Kind {
    /// The main window: hidden from shares when the setting is "All EchoLedger windows".
    Main,
    /// Captions, dictation, the pill, the after-call card, Live Translate, the Assist card, any later overlay.
    Floating,
    /// The Share Check's test window and the guided test card: always protected, like a floating window would be.
    Test,
    /// The Share Check's control window: never protected, so the capture must show it.
    Control,
}

/// The Assist card (Live Assist, Phase L): it is built through [`prepare`] like every other window.
pub const ASSIST: &str = "assist";
pub const CHECK_TEST: &str = "share-check-test";
pub const CHECK_CONTROL: &str = "share-check-control";
pub const GUIDED_CARD: &str = "share-check-card";

/// Every window EchoLedger builds today, with its kind. A window added later registers itself in [`prepare`] (an
/// unknown label is refused there in debug builds, so it cannot be forgotten).
pub const KNOWN: [(&str, Kind); 10] = [
    (crate::lifecycle::MAIN, Kind::Main),
    (crate::overlay::OVERLAY, Kind::Floating),
    (crate::overlay::DICTATION, Kind::Floating),
    (crate::overlay::PILL, Kind::Floating),
    (crate::overlay::REVIEW, Kind::Floating),
    (crate::translate::WINDOW, Kind::Floating),
    (ASSIST, Kind::Floating),
    (CHECK_TEST, Kind::Test),
    (CHECK_CONTROL, Kind::Control),
    (GUIDED_CARD, Kind::Test),
];

/// How long the tray's demo lasts.
pub const DEMO: Duration = Duration::from_secs(10 * 60);

/// Whether a window of `kind` is left out of screen shares (pure). Live Translate follows the floating windows: its
/// history (`git log -S create_translate_window`) shows the window was built unprotected with the same builder and no
/// reason given, not as a choice, so it follows the setting like the others.
pub fn protected(kind: Kind, hiding: ShareHiding, demo: bool) -> bool {
    match kind {
        Kind::Test => true,
        Kind::Control => false,
        _ if demo => false,
        Kind::Floating => hiding != ShareHiding::Off,
        Kind::Main => hiding == ShareHiding::All,
    }
}

/// Why EchoLedger's windows are off the screen.
#[derive(Clone, Copy, Debug, PartialEq, Eq, PartialOrd, Ord, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum HideReason {
    /// "Hide EchoLedger windows" (the shortcut, the tray, Settings).
    Person,
    /// A whole-screen share in a meeting app not confirmed hidden on this Mac (the safety net).
    Share,
}

/// Whether a window of `kind` must be off the screen for these reasons (pure): "Hide EchoLedger windows" takes every
/// one off; a whole-screen share the floating windows, and the main window only when it is hidden from screen share too
/// ("All EchoLedger windows"). The Share Check's windows are never put away.
pub fn away(kind: Kind, reasons: &BTreeSet<HideReason>, hiding: ShareHiding) -> bool {
    match kind {
        Kind::Floating => !reasons.is_empty(),
        Kind::Main => reasons.contains(&HideReason::Person) || (reasons.contains(&HideReason::Share) && hiding == ShareHiding::All),
        Kind::Test | Kind::Control => false,
    }
}

/// The privacy service's state (managed by Tauri before any window is built).
#[derive(Default)]
pub struct Privacy {
    registry: Mutex<BTreeMap<String, Kind>>,
    demo_until: Mutex<Option<Instant>>,
    hidden: Mutex<BTreeSet<HideReason>>,
    /// The windows the service took off the screen (they were on it), brought back when every reason is gone.
    put_away: Mutex<BTreeSet<String>>,
    /// Windows: what the last read-back of each window found.
    pub watch: Mutex<win::Watch>,
    /// The Share Check's windows said they have drawn.
    pub drawn: tokio::sync::Notify,
    pub drawn_labels: Mutex<BTreeSet<String>>,
    /// The share the Mac safety net saw last (its meeting app), for the status.
    pub share_seen: Mutex<Option<watch::Seen>>,
    /// "Show EchoLedger windows" was chosen during a share: the safety net lets go at its next look.
    share_release: AtomicBool,
    /// Bumped by every guided-test answer, so the safety net reads the answers again at once.
    guided_answers: AtomicU64,
}

fn lock<T>(m: &Mutex<T>) -> MutexGuard<'_, T> {
    m.lock().unwrap_or_else(|p| p.into_inner())
}

impl Privacy {
    pub fn new() -> Self {
        let privacy = Self::default();
        for (label, kind) in KNOWN {
            privacy.register(label, kind);
        }
        privacy
    }

    /// Registers a window: from now on its capture flag follows the setting.
    pub fn register(&self, label: &str, kind: Kind) {
        lock(&self.registry).insert(label.to_string(), kind);
    }

    pub fn kind_of(&self, label: &str) -> Option<Kind> {
        lock(&self.registry).get(label).copied()
    }

    pub fn registered(&self) -> Vec<(String, Kind)> {
        lock(&self.registry).iter().map(|(l, k)| (l.clone(), *k)).collect()
    }

    /// The demo is on at `now`.
    pub fn demo_active(&self, now: Instant) -> bool {
        lock(&self.demo_until).is_some_and(|until| now < until)
    }

    pub fn demo_left(&self, now: Instant) -> Option<Duration> {
        lock(&self.demo_until).and_then(|until| until.checked_duration_since(now)).filter(|d| !d.is_zero())
    }

    /// Starts the demo until `until` (none ends it).
    pub fn set_demo(&self, until: Option<Instant>) {
        *lock(&self.demo_until) = until;
    }

    /// Whether `label` should be left out of shares now (none for a window that never registered).
    pub fn wants(&self, label: &str, settings: &Settings, now: Instant) -> Option<bool> {
        Some(protected(self.kind_of(label)?, settings.hide_from_sharing, self.demo_active(now)))
    }

    /// Adds or removes a reason to keep the windows off the screen; true when that changed whether they are off.
    pub fn set_hidden(&self, reason: HideReason, on: bool) -> bool {
        let mut hidden = lock(&self.hidden);
        let before = !hidden.is_empty();
        if on {
            hidden.insert(reason);
        } else {
            hidden.remove(&reason);
        }
        before != !hidden.is_empty()
    }

    pub fn hidden_for(&self, reason: HideReason) -> bool {
        lock(&self.hidden).contains(&reason)
    }

    pub fn concealed(&self) -> bool {
        !lock(&self.hidden).is_empty()
    }

    pub fn reasons(&self) -> BTreeSet<HideReason> {
        lock(&self.hidden).clone()
    }

    /// The person chose "Show EchoLedger windows" during a share (see [`watch::Watcher::release`]).
    pub fn release_share(&self) {
        self.share_release.store(true, Ordering::SeqCst);
    }

    pub fn take_share_release(&self) -> bool {
        self.share_release.swap(false, Ordering::SeqCst)
    }

    pub fn guided_generation(&self) -> u64 {
        self.guided_answers.load(Ordering::SeqCst)
    }

    pub fn note_guided_answer(&self) {
        self.guided_answers.fetch_add(1, Ordering::SeqCst);
    }
}

/// Sets the capture flag on a window's builder (before the first show) and registers the window. Every window
/// EchoLedger builds goes through here.
pub fn prepare<'a, R: Runtime, M: Manager<R>>(app: &AppHandle<R>, builder: WebviewWindowBuilder<'a, R, M>, label: &str, kind: Kind) -> WebviewWindowBuilder<'a, R, M> {
    let Some(privacy) = app.try_state::<Privacy>() else {
        // Only a test app without the service gets here: the window is protected, never the reverse.
        return builder.content_protected(kind != Kind::Control);
    };
    debug_assert!(privacy.kind_of(label).is_none_or(|k| k == kind), "the {label} window registered with another kind");
    privacy.register(label, kind);
    let demo = privacy.demo_active(Instant::now());
    let hiding = app.try_state::<crate::AppState>().map_or(ShareHiding::All, |s| s.settings().hide_from_sharing);
    builder.content_protected(protected(kind, hiding, demo))
}

/// Whether `label` is left out of shares now: the setting and the demo, and the main window whatever they say while
/// screen moments capture a whole screen (Windows, `screens::hide_own_windows`).
pub fn wanted_for<R: Runtime>(app: &AppHandle<R>, label: &str) -> Option<bool> {
    let (privacy, state) = (app.try_state::<Privacy>()?, app.try_state::<crate::AppState>()?);
    let kind = privacy.kind_of(label)?;
    let shielded = kind == Kind::Main && state.screens.own_hidden();
    Some(shielded || protected(kind, state.settings().hide_from_sharing, privacy.demo_active(Instant::now())))
}

/// After a window was built: Windows sets and reads back its display affinity; a window built while its kind is off
/// the screen goes off at once.
pub fn built<R: Runtime>(app: &AppHandle<R>, window: &WebviewWindow<R>) {
    let Some(privacy) = app.try_state::<Privacy>() else { return };
    let label = window.label().to_string();
    if let (Some(state), Some(kind), Some(protect)) = (app.try_state::<crate::AppState>(), privacy.kind_of(&label), wanted_for(app, &label)) {
        let settings = state.settings();
        enforce(app, window, protect, &settings);
        if away(kind, &privacy.reasons(), settings.hide_from_sharing) {
            lock(&privacy.put_away).insert(label);
            put_away(window, true);
        }
    }
}

/// The flag on one window. Windows: our own `SetWindowDisplayAffinity` with the build gate and a read-back (tao's
/// call ignores the result and has no fallback); elsewhere Tauri's `set_content_protected` (`sharingType = .none`).
fn enforce<R: Runtime>(app: &AppHandle<R>, window: &WebviewWindow<R>, protect: bool, settings: &Settings) {
    #[cfg(windows)]
    {
        use win::Affinity;
        let Ok(hwnd) = window.hwnd() else { return };
        let hwnd = hwnd.0 as isize;
        let wanted = win::wanted(protect, win::System.build(), settings.old_windows_sharing);
        let (app, label) = (app.clone(), window.label().to_string());
        let _ = window.run_on_main_thread(move || {
            set_and_read_back(&app, &label, hwnd, wanted);
        });
    }
    #[cfg(not(windows))]
    {
        let _ = (app, settings);
        let _ = window.set_content_protected(protect);
    }
}

/// Windows, on the main thread: sets one window's affinity and reads it back; records the result and logs a mismatch.
/// True when it reads as wanted.
#[cfg(windows)]
pub fn set_and_read_back<R: Runtime>(app: &AppHandle<R>, label: &str, hwnd: isize, wanted: u32) -> bool {
    let readback = win::apply(&win::System, hwnd, wanted);
    if let win::Readback::Mismatch { read, .. } = readback {
        let line = win::Changed { label: label.to_string(), wanted, read, restored: false }.line();
        if let Some(state) = app.try_state::<crate::AppState>() {
            diag::log(&state.data_dir, &line);
        }
        check::report_mismatch(app);
    }
    if let Some(privacy) = app.try_state::<Privacy>() {
        if wanted == win::WDA_NONE {
            lock(&privacy.watch).forget(label);
        } else {
            lock(&privacy.watch).record(label, readback);
        }
    }
    readback == win::Readback::Held
}

/// Windows: the affinity `label` should have now.
#[cfg(windows)]
pub fn affinity_for<R: Runtime>(app: &AppHandle<R>, label: &str) -> Option<u32> {
    use win::Affinity;
    let protect = wanted_for(app, label)?;
    let settings = app.try_state::<crate::AppState>()?.settings();
    Some(win::wanted(protect, win::System.build(), settings.old_windows_sharing))
}

/// Gives every built window the flag the setting wants now (after a settings change, the demo starting or ending).
pub fn apply_all<R: Runtime>(app: &AppHandle<R>) {
    let (Some(privacy), Some(state)) = (app.try_state::<Privacy>(), app.try_state::<crate::AppState>()) else { return };
    let settings = state.settings();
    for (label, _) in privacy.registered() {
        if let (Some(window), Some(protect)) = (app.get_webview_window(&label), wanted_for(app, &label)) {
            enforce(app, &window, protect, &settings);
        }
    }
    // The setting decides whether a share takes the main window off too.
    reconcile(app);
    let _ = app.emit("privacy:changed", ());
}

/// The tray's demo: EchoLedger shows in screen shares for 10 minutes, then the setting applies again by itself.
pub fn show_in_shares_for(app: &AppHandle, how_long: Duration) {
    let privacy = app.state::<Privacy>();
    let until = Instant::now() + how_long;
    privacy.set_demo(Some(until));
    apply_all(app);
    crate::actions::emit_status(app);
    let app = app.clone();
    tauri::async_runtime::spawn(async move {
        tokio::time::sleep(how_long).await;
        let privacy = app.state::<Privacy>();
        // A later demo (or an early end) owns the timer now.
        if lock(&privacy.demo_until).is_some_and(|u| u == until) {
            privacy.set_demo(None);
            apply_all(&app);
            crate::actions::emit_status(&app);
        }
    });
}

pub fn end_demo(app: &AppHandle) {
    app.state::<Privacy>().set_demo(None);
    apply_all(app);
    crate::actions::emit_status(app);
}

/// "Hide EchoLedger windows" (the shortcut): off the screen, and back on the next press.
pub fn toggle_hide_all(app: &AppHandle) {
    let on = !app.state::<Privacy>().hidden_for(HideReason::Person);
    set_hidden(app, HideReason::Person, on);
}

/// Takes EchoLedger's windows off the screen for `reason` ([`away`] says which), or brings them back once nothing
/// keeps them off. The floating windows' pages stop drawing first (`privacy:conceal`), then each window is ordered out
/// without closing (nothing is lost, and the app stays in the Dock, the menu bar, the tray and every window list).
pub fn set_hidden<R: Runtime>(app: &AppHandle<R>, reason: HideReason, on: bool) {
    let privacy = app.state::<Privacy>();
    if privacy.set_hidden(reason, on) {
        let _ = app.emit("privacy:conceal", privacy.concealed());
    }
    reconcile(app);
    let _ = app.emit("privacy:changed", ());
}

/// Puts every window where the reasons want it, on the main thread: there a window's state is read without a round trip
/// (no lock is ever held across one) and two changes never cross. A window that must be off and is on screen is ordered
/// out; one the service took off and that may come back is ordered in.
pub fn reconcile<R: Runtime>(app: &AppHandle<R>) {
    let handle = app.clone();
    let _ = app.run_on_main_thread(move || {
        let (Some(privacy), Some(state)) = (handle.try_state::<Privacy>(), handle.try_state::<crate::AppState>()) else { return };
        let (reasons, hiding) = (privacy.reasons(), state.settings_with(|s| s.hide_from_sharing));
        for (label, kind) in privacy.registered() {
            let Some(window) = handle.get_webview_window(&label) else { continue };
            let (off, taken) = (away(kind, &reasons, hiding), lock(&privacy.put_away).contains(&label));
            if off && !taken && window.is_visible().unwrap_or(false) {
                put_away(&window, true);
                lock(&privacy.put_away).insert(label);
            } else if !off && taken {
                put_away(&window, false);
                lock(&privacy.put_away).remove(&label);
            }
        }
    });
}

/// Orders a window out, or back in front without taking focus (overlays are never `show()`n: tao would focus them).
fn put_away<R: Runtime>(window: &WebviewWindow<R>, away: bool) {
    #[cfg(target_os = "macos")]
    crate::overlay::macos::order(window, !away);
    #[cfg(windows)]
    {
        use windows::Win32::Foundation::HWND;
        use windows::Win32::UI::WindowsAndMessaging::{SW_HIDE, SW_SHOWNOACTIVATE, ShowWindow};
        if let Ok(hwnd) = window.hwnd() {
            let hwnd = hwnd.0 as isize;
            let _ = window.run_on_main_thread(move || {
                let _ = unsafe { ShowWindow(HWND(hwnd as *mut _), if away { SW_HIDE } else { SW_SHOWNOACTIVATE }) };
            });
        }
    }
    #[cfg(not(any(windows, target_os = "macos")))]
    {
        let _ = if away { window.hide() } else { window.show() };
    }
}

/// Windows: every 5 s while a protected window is visible, each one's flag is read back and set again if it changed
/// (a diagnostics line says which window and what was read, never what it shows).
#[cfg(windows)]
pub fn spawn_recheck(app: AppHandle) {
    tauri::async_runtime::spawn(async move {
        loop {
            tokio::time::sleep(win::RECHECK_EVERY).await;
            let (privacy, state) = (app.state::<Privacy>(), app.state::<crate::AppState>());
            let watched: Vec<win::Watched> = privacy
                .registered()
                .into_iter()
                .filter_map(|(label, _)| {
                    let window = app.get_webview_window(&label)?;
                    if !window.is_visible().unwrap_or(false) {
                        return None;
                    }
                    let wanted = affinity_for(&app, &label)?;
                    let hwnd = window.hwnd().ok()?.0 as isize;
                    (wanted != win::WDA_NONE).then_some(win::Watched { label, hwnd, wanted })
                })
                .collect();
            if watched.is_empty() {
                continue;
            }
            let (tx, rx) = std::sync::mpsc::channel();
            let checker = app.clone();
            let _ = app.run_on_main_thread(move || {
                let changed = lock(&checker.state::<Privacy>().watch).tick(&win::System, &watched);
                let _ = tx.send(changed);
            });
            let changed = tokio::task::spawn_blocking(move || rx.recv_timeout(Duration::from_secs(2)).unwrap_or_default()).await.unwrap_or_default();
            if !changed.is_empty() {
                for c in &changed {
                    diag::log(&state.data_dir, &c.line());
                }
                check::report_mismatch(&app);
                let _ = app.emit("privacy:changed", ());
            }
        }
    });
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn every_window_echoledger_builds_is_registered_with_its_kind() {
        let privacy = Privacy::new();
        for label in crate::lifecycle::LABELS {
            assert!(privacy.kind_of(label).is_some(), "{label} is not registered");
        }
        assert_eq!(privacy.kind_of(crate::translate::WINDOW), Some(Kind::Floating), "Live Translate");
        assert_eq!(privacy.kind_of(ASSIST), Some(Kind::Floating), "the Assist card, before it exists");
        assert_eq!(privacy.kind_of(crate::lifecycle::MAIN), Some(Kind::Main));
        for label in crate::overlay::WINDOWS {
            assert_eq!(privacy.kind_of(label), Some(Kind::Floating), "{label}");
        }
        // A window added later registers itself.
        privacy.register("notes", Kind::Floating);
        assert_eq!(privacy.kind_of("notes"), Some(Kind::Floating));
    }

    #[test]
    fn the_setting_decides_which_windows_are_protected() {
        use ShareHiding::*;
        for (hiding, floating, main) in [(All, true, true), (Floating, true, false), (Off, false, false)] {
            assert_eq!(protected(Kind::Floating, hiding, false), floating, "{hiding:?}");
            assert_eq!(protected(Kind::Main, hiding, false), main, "{hiding:?}");
            assert!(protected(Kind::Test, hiding, false), "the test window is always protected");
            assert!(!protected(Kind::Control, hiding, false), "the control never is");
        }
        assert!(!protected(Kind::Floating, All, true), "the demo shows every window");
        assert!(!protected(Kind::Main, All, true));
        assert!(protected(Kind::Test, All, true), "even during a demo the Share Check measures the flag");
    }

    #[test]
    fn the_ten_minute_demo_returns_to_the_setting_by_itself() {
        let privacy = Privacy::new();
        let settings = Settings::default();
        let start = Instant::now();
        assert_eq!(privacy.wants(crate::overlay::PILL, &settings, start), Some(true));
        privacy.set_demo(Some(start + DEMO));
        assert_eq!(privacy.wants(crate::overlay::PILL, &settings, start), Some(false), "shown during the demo");
        assert_eq!(privacy.wants(crate::lifecycle::MAIN, &settings, start + Duration::from_secs(9 * 60)), Some(false));
        assert!(privacy.demo_left(start + Duration::from_secs(60)).is_some_and(|d| d == Duration::from_secs(9 * 60)));
        // Ten minutes later the setting holds again, without anyone touching it.
        assert_eq!(privacy.wants(crate::overlay::PILL, &settings, start + DEMO), Some(true));
        assert_eq!(privacy.wants(crate::lifecycle::MAIN, &settings, start + DEMO), Some(true));
        assert_eq!(privacy.demo_left(start + DEMO), None);
        assert_eq!(DEMO, Duration::from_secs(600));
    }

    #[test]
    fn a_share_takes_the_main_window_off_only_with_all_windows_and_the_person_takes_all() {
        use HideReason::*;
        let reasons = |list: &[HideReason]| list.iter().copied().collect::<BTreeSet<_>>();
        for hiding in [ShareHiding::All, ShareHiding::Floating] {
            assert!(away(Kind::Floating, &reasons(&[Share]), hiding), "{hiding:?}: the floating windows leave a share");
            assert!(away(Kind::Main, &reasons(&[Person]), hiding), "{hiding:?}: Hide EchoLedger windows takes every window");
            assert!(away(Kind::Floating, &reasons(&[Person]), hiding));
        }
        assert!(away(Kind::Main, &reasons(&[Share]), ShareHiding::All));
        assert!(!away(Kind::Main, &reasons(&[Share]), ShareHiding::Floating), "Only floating windows: the main window stays");
        for kind in [Kind::Main, Kind::Floating, Kind::Test, Kind::Control] {
            assert!(!away(kind, &reasons(&[]), ShareHiding::All), "{kind:?}: nothing keeps it off");
        }
        assert!(!away(Kind::Test, &reasons(&[Person, Share]), ShareHiding::All), "the Share Check's windows are never put away");
        assert!(!away(Kind::Control, &reasons(&[Person, Share]), ShareHiding::All));
    }

    #[test]
    fn hiding_is_kept_while_any_reason_remains() {
        let privacy = Privacy::new();
        assert!(privacy.set_hidden(HideReason::Share, true), "off the screen");
        assert!(!privacy.set_hidden(HideReason::Person, true), "already off");
        assert!(!privacy.set_hidden(HideReason::Share, false), "the person still wants them off");
        assert!(privacy.concealed());
        assert!(privacy.set_hidden(HideReason::Person, false), "back");
        assert!(!privacy.concealed());
    }
}
