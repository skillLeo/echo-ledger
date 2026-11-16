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
use std::time::{Duration, Instant, SystemTime};

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
/// How often the demo's timer looks at the clocks: after a sleep it ends within this.
pub const DEMO_TICK: Duration = Duration::from_secs(5);

/// The tray's demo, until when on both clocks: `Instant` (and tokio's timers with it) stops while a Mac sleeps, so the
/// wall clock ends a demo the lid was closed on.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct Demo {
    pub until: Instant,
    pub wall: SystemTime,
}

impl Demo {
    pub fn starting(now: Instant, wall: SystemTime, how_long: Duration) -> Self {
        Self { until: now + how_long, wall: wall + how_long }
    }

    pub fn active(&self, now: Instant, wall: SystemTime) -> bool {
        now < self.until && wall < self.wall
    }

    /// What is left, by the clock that says less.
    pub fn left(&self, now: Instant, wall: SystemTime) -> Option<Duration> {
        let by_uptime = self.until.checked_duration_since(now)?;
        let by_wall = self.wall.duration_since(wall).ok()?;
        Some(by_uptime.min(by_wall)).filter(|d| !d.is_zero())
    }
}

/// What the demo's timer does at a tick (pure).
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum DemoTick {
    /// Still on: look again later.
    Running,
    /// Over by either clock: the setting holds again.
    Over,
    /// A later demo or an early end owns the demo now: this timer stops.
    Other,
}

pub fn demo_tick(current: Option<Demo>, mine: Demo, now: Instant, wall: SystemTime) -> DemoTick {
    match current {
        Some(d) if d == mine && d.active(now, wall) => DemoTick::Running,
        Some(d) if d == mine => DemoTick::Over,
        _ => DemoTick::Other,
    }
}

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
/// ("All EchoLedger windows") and the person did not open it during the share (`main_asked`, see
/// [`Privacy::person_opened`]). The Share Check's windows are never put away.
pub fn away(kind: Kind, reasons: &BTreeSet<HideReason>, hiding: ShareHiding, main_asked: bool) -> bool {
    match kind {
        Kind::Floating => !reasons.is_empty(),
        Kind::Main => reasons.contains(&HideReason::Person) || (reasons.contains(&HideReason::Share) && hiding == ShareHiding::All && !main_asked),
        Kind::Test | Kind::Control => false,
    }
}

/// The privacy commands that change what is hidden, or hand out a picture of the screen or the log, answer the main
/// window only: a floating window's page has no business with them.
pub fn from_main_window(label: &str) -> crate::error::AppResult<()> {
    if label == crate::lifecycle::MAIN { Ok(()) } else { Err(crate::error::AppError::msg("Only EchoLedger's main window can do that.")) }
}

/// What an open by the person changed.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct Opened {
    /// "Hide EchoLedger windows" was on: it ends.
    pub end_person_hide: bool,
    /// A whole-screen share keeps the windows off: the main window is shown anyway, the floating windows stay off.
    pub during_share: bool,
}

/// Whether the windows the system makes in EchoLedger's process are left out of screen share (pure): TextInputUI's
/// `TUINSWindow` (the Caps Lock and input-language indicator next to the text cursor), a tooltip, a menu. They sit over
/// EchoLedger's own windows, so they follow the floating windows.
pub fn others_protected(hiding: ShareHiding, demo: bool) -> bool {
    protected(Kind::Floating, hiding, demo)
}

/// The command that puts a window away or back on Windows (pure; `ShowWindow`'s values): `SW_HIDE`, then `SW_SHOWNA`,
/// which shows it in its own state without activating it, so a minimised window stays minimised.
pub fn show_command(away: bool) -> i32 {
    if away { 0 } else { 8 }
}

/// The privacy service's state (managed by Tauri before any window is built).
#[derive(Default)]
pub struct Privacy {
    registry: Mutex<BTreeMap<String, Kind>>,
    demo: Mutex<Option<Demo>>,
    hidden: Mutex<BTreeSet<HideReason>>,
    /// The person opened the main window during the current whole-screen share: it stays on screen until the share is
    /// over.
    main_asked: AtomicBool,
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

    /// The demo is on at `now` (and by the wall clock now).
    pub fn demo_active(&self, now: Instant) -> bool {
        self.demo_active_at(now, SystemTime::now())
    }

    pub fn demo_active_at(&self, now: Instant, wall: SystemTime) -> bool {
        lock(&self.demo).is_some_and(|d| d.active(now, wall))
    }

    pub fn demo_left(&self, now: Instant) -> Option<Duration> {
        lock(&self.demo).and_then(|d| d.left(now, SystemTime::now()))
    }

    pub fn demo(&self) -> Option<Demo> {
        *lock(&self.demo)
    }

    /// Starts a demo (none ends it).
    pub fn set_demo(&self, demo: Option<Demo>) {
        *lock(&self.demo) = demo;
    }

    /// Whether `label` should be left out of shares now (none for a window that never registered).
    pub fn wants(&self, label: &str, settings: &Settings, now: Instant) -> Option<bool> {
        Some(protected(self.kind_of(label)?, settings.hide_from_sharing, self.demo_active(now)))
    }

    /// Adds or removes a reason to keep the windows off the screen; true when that changed whether the floating windows
    /// are off (they are while any reason remains). A share that is over takes the person's open with it.
    pub fn set_hidden(&self, reason: HideReason, on: bool) -> bool {
        let mut hidden = lock(&self.hidden);
        let before = !hidden.is_empty();
        if on {
            hidden.insert(reason);
        } else {
            hidden.remove(&reason);
            if reason == HideReason::Share {
                self.main_asked.store(false, Ordering::SeqCst);
            }
        }
        before != !hidden.is_empty()
    }

    /// The person opened the main window (pure state, see [`opened_by_person`]).
    pub fn person_opened(&self) -> Opened {
        let reasons = self.reasons();
        let during_share = reasons.contains(&HideReason::Share);
        if during_share {
            self.main_asked.store(true, Ordering::SeqCst);
        }
        Opened { end_person_hide: reasons.contains(&HideReason::Person), during_share }
    }

    pub fn main_asked(&self) -> bool {
        self.main_asked.load(Ordering::SeqCst)
    }

    /// The main window may come on screen now: nothing keeps it off, or the person opened it during the share.
    pub fn may_show_main(&self, hiding: ShareHiding) -> bool {
        !away(Kind::Main, &self.reasons(), hiding, self.main_asked())
    }

    /// The main window is on screen during a whole-screen share because the person opened it: it says so.
    pub fn main_in_share(&self, hiding: ShareHiding) -> bool {
        self.hidden_for(HideReason::Share) && self.main_asked() && hiding == ShareHiding::All
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

/// Builds one EchoLedger window: its capture flag is set on the builder (so it holds before the first show), then it is
/// built, its flag is read back, and it goes off the screen at once when its kind is kept off. Every window EchoLedger
/// builds goes through here (a test reads the sources for it); `adjust` has the last word on the builder (the main
/// window's shield while screen moments capture a whole screen).
pub fn window<'a, R: Runtime, M: Manager<R>>(
    app: &AppHandle<R>,
    builder: WebviewWindowBuilder<'a, R, M>,
    label: &str,
    kind: Kind,
    adjust: impl FnOnce(WebviewWindowBuilder<'a, R, M>) -> WebviewWindowBuilder<'a, R, M>,
) -> tauri::Result<WebviewWindow<R>> {
    let window = adjust(prepare(app, builder, label, kind)).build()?;
    built(app, &window);
    Ok(window)
}

/// Sets the capture flag on a window's builder. Every label is in [`KNOWN`] with its kind (debug builds refuse
/// another); one missing from it in a release build is registered here, so it still follows the setting.
fn prepare<'a, R: Runtime, M: Manager<R>>(app: &AppHandle<R>, builder: WebviewWindowBuilder<'a, R, M>, label: &str, kind: Kind) -> WebviewWindowBuilder<'a, R, M> {
    let Some(privacy) = app.try_state::<Privacy>() else {
        // Only a test app without the service gets here: the window is protected, never the reverse.
        return builder.content_protected(kind != Kind::Control);
    };
    debug_assert!(privacy.kind_of(label) == Some(kind), "the {label} window is not in privacy::KNOWN as {kind:?}");
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
fn built<R: Runtime>(app: &AppHandle<R>, window: &WebviewWindow<R>) {
    let Some(privacy) = app.try_state::<Privacy>() else { return };
    let label = window.label().to_string();
    if let (Some(state), Some(kind), Some(protect)) = (app.try_state::<crate::AppState>(), privacy.kind_of(&label), wanted_for(app, &label)) {
        let settings = state.settings();
        enforce(app, window, protect, &settings);
        if away(kind, &privacy.reasons(), settings.hide_from_sharing, privacy.main_asked()) {
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
        let shielded = window.label() == crate::lifecycle::MAIN && app.try_state::<crate::AppState>().is_some_and(|s| s.screens.own_hidden());
        let wanted = win::affinity(protect, shielded, win::System.build(), settings.old_windows_sharing);
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
        let read = read.map_or_else(|| "nothing".to_string(), |r| format!("0x{r:x}"));
        if let Some(state) = app.try_state::<crate::AppState>() {
            diag::log(&state.data_dir, &format!("The {label} window's capture flag read {read} right after EchoLedger set 0x{wanted:x}."));
        }
        check::report_mismatch(app);
    }
    if let Some(privacy) = app.try_state::<Privacy>() {
        lock(&privacy.watch).applied(label, wanted, readback);
    }
    readback == win::Readback::Held
}

/// Windows: the affinity `label` should have now.
#[cfg(windows)]
pub fn affinity_for<R: Runtime>(app: &AppHandle<R>, label: &str) -> Option<u32> {
    use win::Affinity;
    let protect = wanted_for(app, label)?;
    let state = app.try_state::<crate::AppState>()?;
    let shielded = label == crate::lifecycle::MAIN && state.screens.own_hidden();
    Some(win::affinity(protect, shielded, win::System.build(), state.settings().old_windows_sharing))
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
    #[cfg(target_os = "macos")]
    seal_others(app);
    let _ = app.emit("privacy:changed", ());
}

/// "Hide again" (Windows): after something else on the computer changed an EchoLedger window's capture flag, the person
/// sets every flag again. EchoLedger never does it by itself (see [`win`]).
pub fn hide_again<R: Runtime>(app: &AppHandle<R>) {
    if let Some(privacy) = app.try_state::<Privacy>() {
        lock(&privacy.watch).hide_again();
    }
    apply_all(app);
}

/// A window is gone: what was read or set on it, and that it was put away, are forgotten.
pub fn destroyed<R: Runtime>(app: &AppHandle<R>, label: &str) {
    if let Some(privacy) = app.try_state::<Privacy>() {
        lock(&privacy.watch).forget(label);
        lock(&privacy.put_away).remove(label);
    }
}

/// The tray's demo: EchoLedger shows in screen share for 10 minutes, then the setting applies again by itself (by the
/// wall clock too, so a Mac that slept through the end ends it within a few seconds of waking).
pub fn show_in_shares_for(app: &AppHandle, how_long: Duration) {
    let privacy = app.state::<Privacy>();
    let demo = Demo::starting(Instant::now(), SystemTime::now(), how_long);
    privacy.set_demo(Some(demo));
    apply_all(app);
    crate::actions::emit_status(app);
    let app = app.clone();
    tauri::async_runtime::spawn(async move {
        loop {
            tokio::time::sleep(DEMO_TICK.min(how_long)).await;
            let privacy = app.state::<Privacy>();
            match demo_tick(privacy.demo(), demo, Instant::now(), SystemTime::now()) {
                DemoTick::Running => {}
                DemoTick::Other => return,
                DemoTick::Over => {
                    privacy.set_demo(None);
                    apply_all(&app);
                    crate::actions::emit_status(&app);
                    return;
                }
            }
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

/// The person opened the main window: the tray's Open, the app opened again (Dock, Finder, Launchpad, Spotlight), a
/// second launch, a clicked notification, all through `lifecycle::show_main`. "Hide EchoLedger windows" ends; during a
/// whole-screen share the main window is shown anyway with a line saying it may be in the screen share, and the floating
/// windows stay off.
pub fn opened_by_person<R: Runtime>(app: &AppHandle<R>) {
    let Some(privacy) = app.try_state::<Privacy>() else { return };
    let opened = privacy.person_opened();
    if opened.end_person_hide {
        set_hidden(app, HideReason::Person, false);
    } else if opened.during_share {
        reconcile(app);
        let _ = app.emit("privacy:changed", ());
    }
}

/// Whether the main window may come on screen now (`lifecycle::reveal_main`): an automatic reveal (a rebuilt window's
/// page drawing) never brings it back while windows are kept off.
pub fn may_show_main<R: Runtime>(app: &AppHandle<R>) -> bool {
    let (Some(privacy), Some(state)) = (app.try_state::<Privacy>(), app.try_state::<crate::AppState>()) else { return true };
    privacy.may_show_main(state.settings_with(|s| s.hide_from_sharing))
}

/// Puts every window where the reasons want it, on the main thread: there a window's state is read without a round trip
/// (no lock is ever held across one) and two changes never cross. A window that must be off and is on screen is ordered
/// out; one the service took off and that may come back is ordered in.
pub fn reconcile<R: Runtime>(app: &AppHandle<R>) {
    let handle = app.clone();
    let _ = app.run_on_main_thread(move || {
        let (Some(privacy), Some(state)) = (handle.try_state::<Privacy>(), handle.try_state::<crate::AppState>()) else { return };
        let (reasons, hiding, main_asked) = (privacy.reasons(), state.settings_with(|s| s.hide_from_sharing), privacy.main_asked());
        for (label, kind) in privacy.registered() {
            let Some(window) = handle.get_webview_window(&label) else { continue };
            let (off, taken) = (away(kind, &reasons, hiding, main_asked), lock(&privacy.put_away).contains(&label));
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

/// macOS: every NSWindow of this process that is not one of EchoLedger's registered windows (made by the system, see
/// [`others_protected`]) gets the sharing type the floating windows have. Public NSApplication and NSWindow API only,
/// and only this process's windows. Run whenever the setting or the demo changes, and every few seconds by the safety
/// net's loop (the system makes these windows later, `TUINSWindow` once a page has loaded).
#[cfg(target_os = "macos")]
pub fn seal_others<R: Runtime>(app: &AppHandle<R>) {
    let handle = app.clone();
    let _ = app.run_on_main_thread(move || {
        use objc2::MainThreadMarker;
        use objc2_app_kit::{NSApplication, NSWindowSharingType};
        let (Some(privacy), Some(state), Some(mtm)) = (handle.try_state::<Privacy>(), handle.try_state::<crate::AppState>(), MainThreadMarker::new()) else { return };
        let ours: BTreeSet<usize> = privacy.registered().iter().filter_map(|(label, _)| handle.get_webview_window(label)?.ns_window().ok()).map(|p| p as usize).collect();
        let protect = others_protected(state.settings_with(|s| s.hide_from_sharing), privacy.demo_active(Instant::now()));
        let sharing = if protect { NSWindowSharingType::None } else { NSWindowSharingType::ReadOnly };
        let windows = NSApplication::sharedApplication(mtm).windows();
        for i in 0..windows.count() {
            let window = windows.objectAtIndex(i);
            if !ours.contains(&(objc2::rc::Retained::as_ptr(&window) as usize)) && window.sharingType() != sharing {
                window.setSharingType(sharing);
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
        use windows::Win32::UI::WindowsAndMessaging::{SHOW_WINDOW_CMD, ShowWindow};
        if let Ok(hwnd) = window.hwnd() {
            let hwnd = hwnd.0 as isize;
            let _ = window.run_on_main_thread(move || {
                let _ = unsafe { ShowWindow(HWND(hwnd as *mut _), SHOW_WINDOW_CMD(show_command(away))) };
            });
        }
    }
    #[cfg(not(any(windows, target_os = "macos")))]
    {
        let _ = if away { window.hide() } else { window.show() };
    }
}

/// Windows: every 5 s each visible window's flag is read back against what EchoLedger set. The windows are looked up on
/// the main thread when the check runs (where they are built and destroyed), so a window that went meanwhile is not
/// read. A flag that changed is logged (which window and what was read, never what it shows) and left as it is.
#[cfg(windows)]
pub fn spawn_recheck(app: AppHandle) {
    tauri::async_runtime::spawn(async move {
        loop {
            tokio::time::sleep(win::RECHECK_EVERY).await;
            let (tx, rx) = std::sync::mpsc::channel();
            let checker = app.clone();
            let _ = app.run_on_main_thread(move || {
                let privacy = checker.state::<Privacy>();
                let watched: Vec<win::Watched> = privacy
                    .registered()
                    .into_iter()
                    .filter_map(|(label, _)| {
                        let window = checker.get_webview_window(&label)?;
                        if !window.is_visible().unwrap_or(false) {
                            return None;
                        }
                        Some(win::Watched { label, hwnd: window.hwnd().ok()?.0 as isize })
                    })
                    .collect();
                let changed = lock(&privacy.watch).tick(&win::System, &watched);
                let _ = tx.send(changed);
            });
            let changed = tokio::task::spawn_blocking(move || rx.recv_timeout(Duration::from_secs(2)).unwrap_or_default()).await.unwrap_or_default();
            if !changed.is_empty() {
                let state = app.state::<crate::AppState>();
                for c in &changed {
                    diag::log(&state.data_dir, &c.line());
                }
                check::report_mismatch(&app);
                let _ = app.emit("privacy:changed", ());
                crate::actions::emit_status(&app);
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
        privacy.set_demo(Some(Demo::starting(start, SystemTime::now(), DEMO)));
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
            assert!(away(Kind::Floating, &reasons(&[Share]), hiding, false), "{hiding:?}: the floating windows leave a share");
            assert!(away(Kind::Main, &reasons(&[Person]), hiding, false), "{hiding:?}: Hide EchoLedger windows takes every window");
            assert!(away(Kind::Floating, &reasons(&[Person]), hiding, true));
        }
        assert!(away(Kind::Main, &reasons(&[Share]), ShareHiding::All, false));
        assert!(!away(Kind::Main, &reasons(&[Share]), ShareHiding::Floating, false), "Only floating windows: the main window stays");
        for kind in [Kind::Main, Kind::Floating, Kind::Test, Kind::Control] {
            assert!(!away(kind, &reasons(&[]), ShareHiding::All, false), "{kind:?}: nothing keeps it off");
        }
        assert!(!away(Kind::Test, &reasons(&[Person, Share]), ShareHiding::All, false), "the Share Check's windows are never put away");
        assert!(!away(Kind::Control, &reasons(&[Person, Share]), ShareHiding::All, false));
    }

    #[test]
    fn opening_echoledger_during_a_hide_shows_the_main_window_and_automatic_reveals_do_not() {
        use HideReason::*;
        let floating_away = |p: &Privacy| away(Kind::Floating, &p.reasons(), ShareHiding::All, p.main_asked());
        // A whole-screen share took the windows off ("All EchoLedger windows").
        let p = Privacy::new();
        p.set_hidden(Share, true);
        assert!(!p.may_show_main(ShareHiding::All), "a rebuilt main window's page drawing does not bring it back");
        assert!(!p.main_in_share(ShareHiding::All));
        // The person opens EchoLedger again (the Dock, Finder, the tray's Open, a clicked notification).
        assert_eq!(p.person_opened(), Opened { end_person_hide: false, during_share: true });
        assert!(p.may_show_main(ShareHiding::All), "shown anyway: the person asked");
        assert!(p.main_in_share(ShareHiding::All), "with the line that it may be in the screen share");
        assert!(floating_away(&p), "the floating windows stay off");
        // The share is over: the next share starts without that open.
        p.set_hidden(Share, false);
        assert!(!p.main_asked());
        p.set_hidden(Share, true);
        assert!(!p.may_show_main(ShareHiding::All));

        // Hide EchoLedger windows: an open by the person ends it (`opened_by_person` turns the reason off).
        let p = Privacy::new();
        p.set_hidden(Person, true);
        assert!(!p.may_show_main(ShareHiding::Floating));
        assert_eq!(p.person_opened(), Opened { end_person_hide: true, during_share: false });
        p.set_hidden(Person, false);
        assert!(p.may_show_main(ShareHiding::Floating) && !floating_away(&p), "everything comes back");
        assert!(!p.main_in_share(ShareHiding::All));
    }

    #[test]
    fn a_demo_the_lid_closed_on_ends_by_the_wall_clock() {
        let (start, wall) = (Instant::now(), SystemTime::now());
        let demo = Demo::starting(start, wall, DEMO);
        assert!(demo.active(start + Duration::from_secs(60), wall + Duration::from_secs(60)));
        // Closed 2 minutes in, opened 10 hours later: uptime moved 2 minutes, the wall clock 10 hours.
        let (uptime, later) = (start + Duration::from_secs(120), wall + Duration::from_secs(10 * 3600));
        assert!(!demo.active(uptime, later), "over, though uptime says 8 minutes are left");
        assert_eq!(demo.left(uptime, later), None);
        assert_eq!(demo_tick(Some(demo), demo, uptime, later), DemoTick::Over, "the timer ends it at its next tick");
        assert_eq!(demo_tick(Some(demo), demo, uptime, wall + Duration::from_secs(120)), DemoTick::Running);
        assert_eq!(demo_tick(None, demo, uptime, later), DemoTick::Other, "ended early by the person");
        let later_demo = Demo::starting(uptime, wall, DEMO);
        assert_eq!(demo_tick(Some(later_demo), demo, uptime, later), DemoTick::Other, "a new demo owns the timer");
        assert!(DEMO_TICK <= Duration::from_secs(5), "ends within seconds of waking");
        let privacy = Privacy::new();
        privacy.set_demo(Some(demo));
        assert!(!privacy.demo_active_at(uptime, later));
    }

    #[test]
    fn only_the_main_window_may_change_what_is_hidden() {
        assert!(from_main_window(crate::lifecycle::MAIN).is_ok());
        for label in [crate::overlay::PILL, crate::overlay::OVERLAY, crate::translate::WINDOW, CHECK_TEST, CHECK_CONTROL, GUIDED_CARD] {
            assert!(from_main_window(label).is_err(), "{label}");
        }
    }

    #[test]
    fn a_window_put_back_on_windows_keeps_its_own_state() {
        assert_eq!(show_command(true), 0, "SW_HIDE");
        assert_eq!(show_command(false), 8, "SW_SHOWNA: a minimised window stays minimised, nothing is activated");
    }

    #[test]
    fn the_system_s_own_windows_follow_the_floating_windows() {
        assert!(others_protected(ShareHiding::All, false), "TUINSWindow is left out of screen share");
        assert!(others_protected(ShareHiding::Floating, false), "it sits over EchoLedger's text fields");
        assert!(!others_protected(ShareHiding::Off, false));
        assert!(!others_protected(ShareHiding::All, true), "the demo shows everything");
    }

    /// Every `.rs` file under `dir`.
    fn sources(dir: &std::path::Path, out: &mut Vec<std::path::PathBuf>) {
        for entry in std::fs::read_dir(dir).unwrap().flatten() {
            let path = entry.path();
            if path.is_dir() {
                sources(&path, out);
            } else if path.extension().is_some_and(|e| e == "rs") {
                out.push(path);
            }
        }
    }

    #[test]
    fn every_window_is_built_through_the_privacy_service() {
        let mut files = Vec::new();
        sources(&std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("src"), &mut files);
        let mut builders = 0;
        for file in files.iter().filter(|f| !f.ends_with("privacy/mod.rs")) {
            let text = std::fs::read_to_string(file).unwrap();
            let lines: Vec<&str> = text.lines().collect();
            for (i, line) in lines.iter().enumerate() {
                if line.contains("WebviewWindowBuilder::new(") || line.contains("WebviewWindowBuilder::from_config(") {
                    builders += 1;
                    let after = lines[i..(i + 25).min(lines.len())].join("\n");
                    assert!(after.contains("privacy::window(") || after.contains("super::window("), "{}:{} builds a window outside privacy::window", file.display(), i + 1);
                }
            }
            for private in ["privacy::prepare(", "privacy::built(", "super::prepare(", "super::built(", ".content_protected("] {
                let allowed = private == ".content_protected(" && file.ends_with("lifecycle.rs") && text.contains("|b| if shielded { b.content_protected(true) }");
                assert!(allowed || !text.contains(private), "{}: {private} outside privacy::window", file.display());
            }
        }
        assert!(builders >= 4, "the main window, the overlays, the check's patches and its card: {builders}");
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
