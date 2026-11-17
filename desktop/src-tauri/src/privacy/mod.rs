//! Screen-share privacy (v1.8.1, per window since v2.0): one service owns the capture flag of every EchoLedger window.
//! Each window is registered with its kind before it is built ([`prepare`] sets the flag on the builder, so it holds
//! before the first show) and gets the flag again whenever a choice changes ([`apply_all`]). Since v2.0 ([`share`]):
//! the main window is shown in screen shares unless the person hides it for a time they choose; the Live Assist card and
//! the captions are hidden unless the person shows that one window, on that window; the other floating windows follow
//! "Hide from screen shares" (`hideFromSharing`), which Settings' 10-minute demo lifts for them alone.
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
//!   takes the floating windows, the card and the captions (and the main window while the person hides it from
//!   screen shares) off the screen until it is over.

pub mod apps;
pub mod bars;
pub mod capture;
pub mod check;
pub mod diag;
pub mod pixels;
pub mod share;
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
    /// The main window: shown in shares unless the person hid it ([`share::MainHide`]).
    Main,
    /// Dictation, the pill, the after-call card, Live Translate, any later overlay: they follow `hideFromSharing`.
    Floating,
    /// The Live Assist answer card: hidden from shares unless the person showed it, on the card.
    Card,
    /// The live captions: hidden from shares unless the person showed them, on the captions.
    Captions,
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
    (crate::overlay::OVERLAY, Kind::Captions),
    (crate::overlay::DICTATION, Kind::Floating),
    (crate::overlay::PILL, Kind::Floating),
    (crate::overlay::REVIEW, Kind::Floating),
    (crate::translate::WINDOW, Kind::Floating),
    (ASSIST, Kind::Card),
    (CHECK_TEST, Kind::Test),
    (CHECK_CONTROL, Kind::Control),
    (GUIDED_CARD, Kind::Test),
];

/// The kind a window is built with: its entry in [`KNOWN`], else a floating window.
pub fn kind_for(label: &str) -> Kind {
    KNOWN.iter().find(|(l, _)| *l == label).map_or(Kind::Floating, |(_, k)| *k)
}

/// Every choice that decides whether a window is left out of screen shares, at one moment.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct Choice {
    /// `hideFromSharing`: the other floating windows.
    pub hiding: ShareHiding,
    /// Settings' 10-minute demo: the other floating windows only.
    pub demo: bool,
    /// The person hid the main window, and its time has not come.
    pub main_hidden: bool,
    /// The person showed the Live Assist card in screen shares, on the card (until it closes; never kept).
    pub show_card: bool,
    /// The person showed the captions in screen shares, on the captions (until they close; never kept).
    pub show_captions: bool,
}

impl Choice {
    /// `shown`: the card's and the captions' own choice now ([`Privacy::shown_in_shares`]).
    pub fn of(settings: &Settings, demo: bool, now_ms: i64, shown: (bool, bool)) -> Self {
        Self {
            hiding: settings.hide_from_sharing,
            demo,
            main_hidden: share::main_hidden(settings.main_share_hide.as_ref(), now_ms),
            show_card: shown.0,
            show_captions: shown.1,
        }
    }

    /// The safety net has something to keep out of a whole-screen share: a window other than the main one is hidden.
    pub fn any_floating_hidden(&self) -> bool {
        [Kind::Floating, Kind::Card, Kind::Captions].into_iter().any(|k| protected(k, self))
    }
}

/// Now, by the wall clock (Unix milliseconds).
pub fn now_ms() -> i64 {
    chrono::Utc::now().timestamp_millis()
}

/// The choices now.
pub fn choice_now<R: Runtime>(app: &AppHandle<R>) -> Choice {
    let privacy = app.try_state::<Privacy>();
    let demo = privacy.as_ref().is_some_and(|p| p.demo_active(Instant::now()));
    let shown = privacy.as_ref().map_or((false, false), |p| (p.shown_in_shares(share::Pinned::Card), p.shown_in_shares(share::Pinned::Captions)));
    let now = now_ms();
    app.try_state::<crate::AppState>().map_or_else(|| Choice::of(&Settings::default(), demo, now, shown), |s| s.settings_with(|s| Choice::of(s, demo, now, shown)))
}

/// The Windows-before-2004 choice a window of `kind` gets (pure): the card and the captions are a black box at least,
/// whatever "On this Windows version" says; the others follow it.
pub fn old_windows_for(kind: Option<Kind>, old: crate::settings::OldWindowsSharing) -> crate::settings::OldWindowsSharing {
    match kind {
        Some(Kind::Card | Kind::Captions) => crate::settings::OldWindowsSharing::BlackBox,
        _ => old,
    }
}

/// Windows built off the screen and brought on by the privacy service only once their flag held (pure).
pub fn reveals_itself(kind: Kind) -> bool {
    matches!(kind, Kind::Card | Kind::Captions)
}

/// What becomes of the card or the captions once their flag was set (on the main thread, just before showing).
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Reveal {
    /// On the screen.
    Show,
    /// A reason to keep it off came meanwhile (Hide EchoLedger windows, a whole-screen share): it stays put away, and
    /// the next reconcile brings it back through the same gate.
    StayAway,
    /// Its flag did not hold: it stays off, the person is told, and the next lifecycle point tries again.
    HeldBack,
}

/// The line the tray and Settings › Screen-share privacy show while a window stays off because its flag did not hold.
pub fn held_back_line(label: &str) -> String {
    if label == crate::overlay::OVERLAY {
        "The captions couldn't be hidden from screen shares, so they stay off.".into()
    } else {
        format!("The {} couldn't be hidden from screen shares, so it stays off.", window_name(label))
    }
}

/// What the gate does to the window (pure): `Some(true)` takes it off the screen, `Some(false)` brings it on, none leaves
/// it. `may_show`: this pass may bring a window on (built, reconcile, a retry); a check of the windows on screen
/// (`apply_all`) only ever takes one off.
pub fn gate_step(reveal: Reveal, visible: bool, may_show: bool) -> Option<bool> {
    match reveal {
        Reveal::Show => (!visible && may_show).then_some(false),
        Reveal::StayAway | Reveal::HeldBack => visible.then_some(true),
    }
}

/// Whether the card or the captions may come on the screen once their flag was set (pure): a window shown in screen
/// shares on purpose at once; a hidden one only when its flag read back as set.
pub fn may_reveal(protect: bool, held: bool) -> bool {
    !protect || held
}

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

/// A window as people know it (pure; docs/DESIGN_SYSTEM.md "Screen-share privacy names"), for the lines the privacy log
/// keeps and "Copy diagnostics" hands out: never a window's internal label.
pub fn window_name(label: &str) -> &'static str {
    match label {
        crate::lifecycle::MAIN => "main window",
        crate::overlay::OVERLAY => "captions",
        crate::overlay::DICTATION => "dictation box",
        crate::overlay::PILL => "recording controls",
        crate::overlay::REVIEW => "after-call card",
        crate::translate::WINDOW => "Live Translate",
        ASSIST => "Live Assist card",
        CHECK_TEST => "test window of the check",
        CHECK_CONTROL => "green square of the check",
        GUIDED_CARD => "test card",
        _ => "floating window",
    }
}

/// Whether a window of `kind` is left out of screen shares (pure). The main window only while the person hides it; the
/// Live Assist card and the captions always, unless the person showed that one window (no other choice, the demo
/// included, ever shows them); the other floating windows by `hideFromSharing` and the demo. Live Translate follows the
/// floating windows: its history (`git log -S create_translate_window`) shows the window was built unprotected with the
/// same builder and no reason given, not as a choice, so it follows the setting like the others.
pub fn protected(kind: Kind, choice: &Choice) -> bool {
    match kind {
        Kind::Test => true,
        Kind::Control => false,
        Kind::Main => choice.main_hidden,
        Kind::Card => !choice.show_card,
        Kind::Captions => !choice.show_captions,
        Kind::Floating => !choice.demo && choice.hiding != ShareHiding::Off,
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
/// one off; a whole-screen share the floating windows, the Live Assist card and the captions unless the person showed
/// them in screen shares, and the main window only while the person hides it from screen shares and did not open it
/// during the share (`main_asked`, see [`Privacy::person_opened`]). The Share Check's windows are never put away.
pub fn away(kind: Kind, reasons: &BTreeSet<HideReason>, choice: &Choice, main_asked: bool) -> bool {
    let person = reasons.contains(&HideReason::Person);
    let share = reasons.contains(&HideReason::Share);
    match kind {
        Kind::Floating => !reasons.is_empty(),
        Kind::Card | Kind::Captions => person || (share && protected(kind, choice)),
        Kind::Main => person || (share && choice.main_hidden && !main_asked),
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
pub fn others_protected(choice: &Choice) -> bool {
    protected(Kind::Floating, choice)
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
    /// The windows the service took off the screen (they were on it, or for the card and the captions were about to come
    /// on), brought back when every reason is gone: the card and the captions through [`gate`].
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
    /// How many whole-screen shares took the windows off in this run: the main window's notice belongs to one share.
    shares: AtomicU64,
    /// Ends the main window's hide by itself ([`spawn_main_hide_timer`]).
    pub hide_timer: Mutex<share::MainHideTimer>,
    /// macOS: windows whose flag the system refused to set when EchoLedger last set it (the badge says it couldn't hide).
    pub set_failed: Mutex<BTreeSet<String>>,
    /// The card and the captions the person showed in screen shares, from that window's own badge. Kept in memory only:
    /// it ends when that window closes, and every start begins hidden.
    shown: Mutex<BTreeSet<share::Pinned>>,
    /// The card and the captions kept off the screen because their flag did not hold (tried again at every
    /// [`apply_all`]).
    held_back: Mutex<BTreeSet<String>>,
    /// The safety net's last look saw a sharing bar on screen: "Show EchoLedger windows" can't bring them back now.
    bar_on_screen: AtomicBool,
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
    pub fn wants(&self, label: &str, settings: &Settings, now: Instant, now_ms: i64) -> Option<bool> {
        let shown = (self.shown_in_shares(share::Pinned::Card), self.shown_in_shares(share::Pinned::Captions));
        Some(protected(self.kind_of(label)?, &Choice::of(settings, self.demo_active(now), now_ms, shown)))
    }

    /// What to do with the card or the captions on the main thread, right before showing: the reasons are read now, so
    /// one that came after the reveal was scheduled keeps the window off.
    pub fn reveal_decision(&self, kind: Kind, choice: &Choice, protect: bool, held: bool) -> Reveal {
        if away(kind, &self.reasons(), choice, self.main_asked()) {
            Reveal::StayAway
        } else if may_reveal(protect, held) {
            Reveal::Show
        } else {
            Reveal::HeldBack
        }
    }

    /// Records what [`Privacy::reveal_decision`] decided for `label`: kept off (put away, or held back) or on.
    pub fn revealed(&self, label: &str, reveal: Reveal) {
        match reveal {
            Reveal::Show => {
                lock(&self.held_back).remove(label);
                lock(&self.put_away).remove(label);
            }
            Reveal::StayAway => {
                // Kept off by a reason now, not by its flag: the reason is what the person is told.
                lock(&self.held_back).remove(label);
                lock(&self.put_away).insert(label.to_string());
            }
            Reveal::HeldBack => {
                lock(&self.held_back).insert(label.to_string());
            }
        }
    }

    /// This window is kept off because its flag did not hold.
    pub fn is_held_back(&self, label: &str) -> bool {
        lock(&self.held_back).contains(label)
    }

    /// The windows kept off because their flag did not hold, by label.
    pub fn held_back(&self) -> Vec<String> {
        lock(&self.held_back).iter().cloned().collect()
    }

    /// The person showed this window in screen shares, and it has not closed since.
    pub fn shown_in_shares(&self, which: share::Pinned) -> bool {
        lock(&self.shown).contains(&which)
    }

    /// The window's own choice; false again when it closes ([`destroyed`]).
    pub fn set_shown_in_shares(&self, which: share::Pinned, shown: bool) {
        let mut set = lock(&self.shown);
        if shown { set.insert(which) } else { set.remove(&which) };
    }

    /// Adds or removes a reason to keep the windows off the screen; true when that changed whether the floating windows
    /// are off (they are while any reason remains). A share that is over takes the person's open with it.
    pub fn set_hidden(&self, reason: HideReason, on: bool) -> bool {
        let mut hidden = lock(&self.hidden);
        let before = !hidden.is_empty();
        if on {
            if hidden.insert(reason) && reason == HideReason::Share {
                self.shares.fetch_add(1, Ordering::SeqCst);
            }
        } else {
            hidden.remove(&reason);
            if reason == HideReason::Share {
                self.main_asked.store(false, Ordering::SeqCst);
                self.bar_on_screen.store(false, Ordering::SeqCst);
            }
        }
        before != !hidden.is_empty()
    }

    /// The share that keeps the windows off now, counted from 1 in this run (0: none yet). A notice closed during one
    /// share shows again in the next.
    pub fn share_number(&self) -> u64 {
        self.shares.load(Ordering::SeqCst)
    }

    /// What the safety net's last look saw: a sharing bar on screen or not.
    pub fn set_bar_on_screen(&self, on: bool) {
        self.bar_on_screen.store(on, Ordering::SeqCst);
    }

    /// A whole-screen share keeps the windows off and its sharing bar is on screen: "Show EchoLedger windows" would
    /// bring nothing back (the next look takes them off again), so it is not offered.
    pub fn bar_holds(&self) -> bool {
        self.hidden_for(HideReason::Share) && self.bar_on_screen.load(Ordering::SeqCst)
    }

    /// The main window was closed: an open during the share is over with it.
    pub fn main_closed(&self) {
        self.main_asked.store(false, Ordering::SeqCst);
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

    /// The main window may come on screen now: nothing keeps it off, or the person opened it during the share. A quiet
    /// open (something the person did not ask to see it for) never counts their open: it stays off while a reason keeps
    /// the main window off.
    pub fn may_show_main(&self, choice: &Choice, quiet: bool) -> bool {
        !away(Kind::Main, &self.reasons(), choice, self.main_asked() && !quiet)
    }

    /// The main window is on screen during a whole-screen share because the person opened it while it is hidden from
    /// screen shares: it says so.
    pub fn main_in_share(&self, main_hidden: bool) -> bool {
        self.hidden_for(HideReason::Share) && self.main_asked() && main_hidden
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
    builder.content_protected(protected(kind, &choice_now(app)))
}

/// Whether `label` is left out of shares now: the setting and the demo, and the main window whatever they say while
/// screen moments capture a whole screen (Windows, `screens::hide_own_windows`).
pub fn wanted_for<R: Runtime>(app: &AppHandle<R>, label: &str) -> Option<bool> {
    let (privacy, state) = (app.try_state::<Privacy>()?, app.try_state::<crate::AppState>()?);
    let kind = privacy.kind_of(label)?;
    let shielded = kind == Kind::Main && state.screens.own_hidden();
    Some(shielded || protected(kind, &choice_now(app)))
}

/// After a window was built: Windows sets and reads back its display affinity; a window built while its kind is off
/// the screen goes off at once.
fn built<R: Runtime>(app: &AppHandle<R>, window: &WebviewWindow<R>) {
    let Some(privacy) = app.try_state::<Privacy>() else { return };
    let label = window.label().to_string();
    if let (Some(state), Some(kind), Some(protect)) = (app.try_state::<crate::AppState>(), privacy.kind_of(&label), wanted_for(app, &label)) {
        let settings = state.settings();
        enforce(app, window, protect, &settings);
        if away(kind, &privacy.reasons(), &choice_now(app), privacy.main_asked()) {
            lock(&privacy.put_away).insert(label);
            put_away(window, true);
        } else if reveals_itself(kind) {
            // Built off the screen: they come on only once their flag reads back as set.
            gate(app, window, true);
        }
    }
}

/// The card and the captions, every time their flag is set: on the main thread the flag is set and read back (what it
/// should be is read there, so a change since this was scheduled counts), the reasons are read again, and only then is
/// the window brought on, left, or taken off ([`gate_step`]). One whose flag did not hold is taken off the screen, even
/// one already on it, the person is told, and the next lifecycle point tries again. `may_show`: built, reconcile and a
/// retry may bring it on; `apply_all`'s check of the windows on screen only takes one off.
fn gate<R: Runtime>(app: &AppHandle<R>, window: &WebviewWindow<R>, may_show: bool) {
    let (handle, target) = (app.clone(), window.clone());
    let _ = window.run_on_main_thread(move || {
        let Some(privacy) = handle.try_state::<Privacy>() else { return };
        let Some(kind) = privacy.kind_of(target.label()) else { return };
        let protect = wanted_for(&handle, target.label()).unwrap_or(true);
        let mut live = LiveWindow { app: &handle, window: &target };
        let was_held_back = privacy.is_held_back(target.label());
        let Some(reveal) = run_gate(&privacy, kind, &choice_now(&handle), protect, &mut live, may_show) else { return };
        if reveal == Reveal::HeldBack
            && let Some(state) = handle.try_state::<crate::AppState>()
        {
            diag::log(&state.data_dir, &format!("The {} is off the screen: its capture flag did not read back as set.", window_name(target.label())));
        }
        if was_held_back != (reveal == Reveal::HeldBack) {
            let _ = handle.emit("privacy:changed", ());
            // The tray says it too (the app's own handle; a test runtime has no tray).
            if let Some(app) = (&handle as &dyn std::any::Any).downcast_ref::<AppHandle>() {
                crate::actions::emit_status(app);
            }
        }
    });
}

/// What the gate needs of a window: its label, whether it is on screen, its flag set and read back, and to go off or on.
/// The app's windows ([`LiveWindow`]); a fake one in the tests.
pub trait GateWindow {
    fn label(&self) -> &str;
    fn visible(&self) -> bool;
    /// Sets the flag (`protect`: hidden from screen shares) and reads it back; true when it reads as wanted.
    fn flag_held(&mut self, protect: bool) -> bool;
    /// Takes the window off the screen (`true`) or brings it on.
    fn put_away(&mut self, off: bool);
}

struct LiveWindow<'a, R: Runtime> {
    app: &'a AppHandle<R>,
    window: &'a WebviewWindow<R>,
}

impl<R: Runtime> GateWindow for LiveWindow<'_, R> {
    fn label(&self) -> &str {
        self.window.label()
    }
    fn visible(&self) -> bool {
        self.window.is_visible().unwrap_or(false)
    }
    fn flag_held(&mut self, protect: bool) -> bool {
        flag_held_here(self.app, self.window, protect)
    }
    fn put_away(&mut self, off: bool) {
        put_away(self.window, off);
    }
}

/// The gate's work on one window, on the main thread (everything it reads is read here, when it runs): the flag set and
/// read back, the held-back state and the reasons read now, then on, off or left. A window held back may always come
/// back once its flag holds, whatever pass found it (`may_show` or held back). None when it left the window alone (off
/// the screen and not this pass's to bring on: only its flag was set).
pub fn run_gate(privacy: &Privacy, kind: Kind, choice: &Choice, protect: bool, window: &mut impl GateWindow, may_show: bool) -> Option<Reveal> {
    let held = window.flag_held(protect);
    let visible = window.visible();
    let may_show = may_show || privacy.is_held_back(window.label());
    if !may_show && !visible {
        return None;
    }
    // The reasons are read again here: one that came since this was scheduled keeps the window off.
    let reveal = privacy.reveal_decision(kind, choice, protect, held);
    privacy.revealed(window.label(), reveal);
    if let Some(off) = gate_step(reveal, visible, may_show) {
        window.put_away(off);
    }
    Some(reveal)
}

/// On the main thread: sets the window's flag and reads it back; true when it reads as wanted.
fn flag_held_here<R: Runtime>(app: &AppHandle<R>, window: &WebviewWindow<R>, protect: bool) -> bool {
    #[cfg(windows)]
    {
        let (Ok(hwnd), Some(wanted)) = (window.hwnd(), affinity_for(app, window.label())) else { return false };
        let _ = protect;
        set_and_read_back(app, window.label(), hwnd.0 as isize, wanted)
    }
    #[cfg(target_os = "macos")]
    {
        let _ = app;
        let set = window.set_content_protected(protect).is_ok();
        let wanted = if protect { share::Read::Hidden } else { share::Read::Shown };
        set && read_flag_here(window) == Some(wanted)
    }
    #[cfg(not(any(windows, target_os = "macos")))]
    {
        let _ = (app, window, protect);
        true
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
        let kind = app.try_state::<Privacy>().and_then(|p| p.kind_of(window.label()));
        let wanted = win::affinity(protect, shielded, win::System.build(), old_windows_for(kind, settings.old_windows_sharing));
        let (app, label) = (app.clone(), window.label().to_string());
        let _ = window.run_on_main_thread(move || {
            set_and_read_back(&app, &label, hwnd, wanted);
        });
    }
    #[cfg(not(windows))]
    {
        let _ = settings;
        let refused = window.set_content_protected(protect).is_err();
        if let Some(privacy) = app.try_state::<Privacy>() {
            let mut failed = lock(&privacy.set_failed);
            if refused { failed.insert(window.label().to_string()) } else { failed.remove(window.label()) };
        }
    }
}

/// The privacy log's line when a window's capture flag reads back other than EchoLedger set it (pure; `read` as shown).
pub fn mismatch_line(label: &str, read: String, wanted: u32) -> String {
    format!("The capture flag of the {} read {read} right after EchoLedger set 0x{wanted:x}.", window_name(label))
}

/// Windows, on the main thread: sets one window's affinity and reads it back; records the result and logs a mismatch.
/// True when it reads as wanted.
#[cfg(windows)]
pub fn set_and_read_back<R: Runtime>(app: &AppHandle<R>, label: &str, hwnd: isize, wanted: u32) -> bool {
    let readback = win::apply(&win::System, hwnd, wanted);
    if let win::Readback::Mismatch { read, .. } = readback {
        let read = read.map_or_else(|| "nothing".to_string(), |r| format!("0x{r:x}"));
        if let Some(state) = app.try_state::<crate::AppState>() {
            diag::log(&state.data_dir, &mismatch_line(label, read, wanted));
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
    let kind = app.try_state::<Privacy>().and_then(|p| p.kind_of(label));
    Some(win::affinity(protect, shielded, win::System.build(), old_windows_for(kind, state.settings().old_windows_sharing)))
}

/// Gives every built window the flag the setting wants now (after a settings change, the demo starting or ending).
pub fn apply_all<R: Runtime>(app: &AppHandle<R>) {
    let (Some(privacy), Some(state)) = (app.try_state::<Privacy>(), app.try_state::<crate::AppState>()) else { return };
    let settings = state.settings();
    for (label, kind) in privacy.registered() {
        let Some(window) = app.get_webview_window(&label) else { continue };
        if reveals_itself(kind) {
            // The card and the captions through the gate: one on screen whose flag stops holding goes off at once; one
            // kept off because its flag did not hold is tried again (the gate reads that when it runs).
            gate(app, &window, false);
        } else if let Some(protect) = wanted_for(app, &label) {
            enforce(app, &window, protect, &settings);
        }
    }
    for label in privacy.held_back() {
        if app.get_webview_window(&label).is_none() {
            lock(&privacy.held_back).remove(&label);
        }
    }
    // The setting decides whether a share takes the main window off too.
    reconcile(app);
    #[cfg(target_os = "macos")]
    seal_others(app);
    let _ = app.emit("privacy:changed", ());
}

/// A display as [`Points`] compares them: where it is, its size and its scale in thousandths (never its name).
pub type Display = (i32, i32, u32, u32, u64);

/// One of EchoLedger's own lifecycle points.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Point {
    /// The computer woke from sleep.
    Woke,
    /// A display came, went or changed its size or scale.
    Displays,
}

impl Point {
    /// The line the privacy log keeps.
    pub fn line(self) -> &'static str {
        match self {
            Point::Woke => "The computer woke from sleep: EchoLedger set every window's capture flag again.",
            Point::Displays => "The displays changed: EchoLedger set every window's capture flag again.",
        }
    }
}

/// A wall-clock gap this much longer than the loop's period means the computer slept (or its clock was set).
pub const WOKE_AFTER: Duration = Duration::from_secs(30);

/// EchoLedger's own lifecycle points, where every capture flag is set again (the S5 decision record: a window built or
/// shown, a display change, waking from sleep), so a flag the system dropped there is put back instead of being taken
/// for a change by something else. Pure: the wall clock and the displays come in.
#[derive(Debug, Default)]
pub struct Points {
    wall: Option<SystemTime>,
    displays: Option<Vec<Display>>,
}

impl Points {
    /// One look, `period` after the last one. `displays`: none when they could not be read (nothing is compared then).
    pub fn tick(&mut self, wall: SystemTime, period: Duration, displays: Option<Vec<Display>>) -> Option<Point> {
        let woke = self.wall.is_some_and(|last| wall.duration_since(last).is_ok_and(|gap| gap > period + WOKE_AFTER));
        self.wall = Some(wall);
        let changed = match displays {
            Some(mut now) => {
                now.sort_unstable();
                let changed = self.displays.as_ref().is_some_and(|before| *before != now);
                self.displays = Some(now);
                changed
            }
            None => false,
        };
        if woke {
            Some(Point::Woke)
        } else if changed {
            Some(Point::Displays)
        } else {
            None
        }
    }
}

/// The displays now, for [`Points`] (never on the main thread: the answer comes from it).
pub fn displays<R: Runtime>(app: &AppHandle<R>) -> Option<Vec<Display>> {
    let monitors = app.available_monitors().ok()?;
    let out: Vec<Display> = monitors
        .iter()
        .map(|m| (m.position().x, m.position().y, m.size().width, m.size().height, (m.scale_factor() * 1000.0).round() as u64))
        .collect();
    (!out.is_empty()).then_some(out)
}

/// At one of EchoLedger's own lifecycle points: every flag is set again, and the log says why.
pub fn at_point<R: Runtime>(app: &AppHandle<R>, point: Point) {
    if let Some(state) = app.try_state::<crate::AppState>() {
        diag::log(&state.data_dir, point.line());
    }
    apply_all(app);
}

/// "Hide again" (Windows): after something else on the computer changed an EchoLedger window's capture flag, the person
/// sets every flag again. EchoLedger never does it by itself (see [`win`]).
pub fn hide_again<R: Runtime>(app: &AppHandle<R>) {
    if let Some(privacy) = app.try_state::<Privacy>() {
        lock(&privacy.watch).hide_again();
    }
    apply_all(app);
}

/// A window is gone: what was read or set on it, and that it was put away, are forgotten. The main window closed during a
/// share takes the person's open with it: opened again by something else, it stays off until the share is over.
pub fn destroyed<R: Runtime>(app: &AppHandle<R>, label: &str) {
    if let Some(privacy) = app.try_state::<Privacy>() {
        lock(&privacy.watch).forget(label);
        lock(&privacy.put_away).remove(label);
        lock(&privacy.held_back).remove(label);
        if label == crate::lifecycle::MAIN {
            privacy.main_closed();
        }
        // The card's or the captions' choice to be shown ends with the window (the end of that call).
        if let Some(which) = share::Pinned::of_label(label)
            && privacy.shown_in_shares(which)
        {
            privacy.set_shown_in_shares(which, false);
            let _ = app.emit("privacy:changed", ());
        }
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

// ---- Per-window choices (v2.0) ------------------------------------------------------------------------------------

/// How often the main window's hide is looked at: it shows again within this of its time.
pub const MAIN_HIDE_TICK: Duration = Duration::from_secs(2);

/// A call is detected now (the meeting app the detector sees, refreshed every second).
pub fn call_detected<R: Runtime>(app: &AppHandle<R>) -> bool {
    app.try_state::<crate::AppState>().is_some_and(|s| s.detect.meeting().is_some())
}

/// A choice changed: every flag follows it, every page and the tray hear of it.
fn choices_changed(app: &AppHandle, settings: &Settings) {
    let _ = app.emit("settings:changed", settings);
    apply_all(app);
    crate::actions::emit_status(app);
}

/// "Hide from screen shares" for the main window (Settings, the notice, the tray), until the time chosen. Answers the
/// one-time notice too.
pub fn hide_main(app: &AppHandle, choice: share::HideFor) -> crate::error::AppResult<share::MainHide> {
    let hide = share::hide_until(choice, chrono::Local::now(), call_detected(app)).map_err(|e| crate::error::AppError::msg(e.message()))?;
    *lock(&app.state::<Privacy>().hide_timer) = share::MainHideTimer::default();
    let updated = app.state::<crate::AppState>().update_settings(|s| {
        s.main_share_hide = Some(hide);
        s.share_notice = false;
    })?;
    choices_changed(app, &updated);
    Ok(hide)
}

/// "Show now": the main window is in screen shares again. `only`: clears that hide alone (the timer, so a hide chosen
/// meanwhile is never cleared by an older one's end).
pub fn show_main_in_shares(app: &AppHandle, only: Option<share::MainHide>) -> crate::error::AppResult<()> {
    let state = app.state::<crate::AppState>();
    let mut cleared = false;
    let updated = state.update_settings(|s| {
        if s.main_share_hide.is_some() && only.is_none_or(|h| s.main_share_hide == Some(h)) {
            s.main_share_hide = None;
            cleared = true;
        }
    })?;
    if cleared {
        choices_changed(app, &updated);
    }
    Ok(())
}

/// The Live Assist card's or the captions' own switch: shown in screen shares only by this explicit choice, until that
/// window closes. Never saved.
pub fn set_shown_in_shares(app: &AppHandle, which: share::Pinned, shown: bool) -> crate::error::AppResult<()> {
    app.state::<Privacy>().set_shown_in_shares(which, shown);
    apply_all(app);
    crate::actions::emit_status(app);
    Ok(())
}

/// The one-time notice was closed.
pub fn dismiss_share_notice(app: &AppHandle) -> crate::error::AppResult<()> {
    app.state::<crate::AppState>().update_settings(|s| s.share_notice = false)?;
    crate::actions::emit_status(app);
    Ok(())
}

/// Ends the main window's hide by itself: at its time (by the wall clock, so a sleep never stretches it), or a few
/// seconds after the call is over.
pub fn spawn_main_hide_timer(app: AppHandle) {
    tauri::async_runtime::spawn(async move {
        loop {
            tokio::time::sleep(MAIN_HIDE_TICK).await;
            let hide = app.state::<crate::AppState>().settings_with(|s| s.main_share_hide);
            let over = lock(&app.state::<Privacy>().hide_timer).tick(hide.as_ref(), now_ms(), call_detected(&app));
            if over && let Some(hide) = hide {
                let _ = show_main_in_shares(&app, Some(hide));
            }
        }
    });
}

/// What a card or captions badge shows, with the window's own switch.
#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct WindowShareView {
    pub which: share::Pinned,
    /// The person showed this window in screen shares (until it closes).
    pub shown: bool,
    /// What the system reads back now.
    pub state: share::WindowShare,
    pub badge: &'static str,
    pub switch_label: &'static str,
}

/// The real state of one window's flag, read back from the system on the main thread (never call it there).
pub struct SystemFlag<R: Runtime> {
    pub app: AppHandle<R>,
}

impl<R: Runtime> share::ShareFlag for SystemFlag<R> {
    fn is_open(&self, label: &str) -> bool {
        self.app.get_webview_window(label).is_some()
    }

    /// Setting goes through [`apply_all`]; what the system answered then is kept (macOS) or in the watch (Windows).
    fn set_share_flag(&self, label: &str, _hidden: bool) -> bool {
        let Some(privacy) = self.app.try_state::<Privacy>() else { return false };
        if lock(&privacy.set_failed).contains(label) {
            return false;
        }
        !matches!(lock(&privacy.watch).last.get(label), Some(win::Readback::Mismatch { .. }))
    }

    fn read_share_flag(&self, label: &str) -> Option<share::Read> {
        let window = self.app.get_webview_window(label)?;
        let (tx, rx) = std::sync::mpsc::channel();
        let target = window.clone();
        window
            .run_on_main_thread(move || {
                let _ = tx.send(read_flag_here(&target));
            })
            .ok()?;
        rx.recv_timeout(Duration::from_secs(2)).ok().flatten()
    }
}

/// On the main thread: what the system says of this window in screen shares (macOS `NSWindow.sharingType` none is
/// hidden; Windows `GetWindowDisplayAffinity`: WDA_EXCLUDEFROMCAPTURE hidden, WDA_MONITOR a black box); none when the
/// system did not say.
fn read_flag_here<R: Runtime>(window: &WebviewWindow<R>) -> Option<share::Read> {
    #[cfg(target_os = "macos")]
    {
        use objc2_app_kit::{NSWindow, NSWindowSharingType};
        let pointer = window.ns_window().ok()?;
        // SAFETY: Tauri hands out the window's own NSWindow, alive while `window` is, on the main thread here.
        let ns_window: &NSWindow = unsafe { &*(pointer as *const NSWindow) };
        Some(if ns_window.sharingType() == NSWindowSharingType::None { share::Read::Hidden } else { share::Read::Shown })
    }
    #[cfg(windows)]
    {
        use win::Affinity;
        let hwnd = window.hwnd().ok()?.0 as isize;
        win::System.get(hwnd).map(|a| match a {
            win::WDA_NONE => share::Read::Shown,
            win::WDA_MONITOR => share::Read::BlackBox,
            _ => share::Read::Hidden,
        })
    }
    #[cfg(not(any(windows, target_os = "macos")))]
    {
        let _ = window;
        None
    }
}

/// The badge of the card or the captions now (asynchronous: the system is asked on the main thread).
pub async fn window_share_now<R: Runtime>(app: &AppHandle<R>, which: share::Pinned) -> WindowShareView {
    let shown = app.try_state::<Privacy>().is_some_and(|p| p.shown_in_shares(which));
    let wanted_hidden = wanted_for(app, which.label()).unwrap_or(true);
    let flag = SystemFlag { app: app.clone() };
    let label = which.label();
    let state = tauri::async_runtime::spawn_blocking(move || share::apply(&flag, label, wanted_hidden))
        .await
        .unwrap_or(if wanted_hidden { share::WindowShare::CouldNotHide } else { share::WindowShare::Shown });
    WindowShareView { which, shown, state, badge: state.badge(), switch_label: which.switch_label(shown) }
}

/// "Show EchoLedger windows" (the tray, Settings): "Hide EchoLedger windows" ends; during a whole-screen share the
/// safety net lets go at its next look. While the sharing bar is on screen it is not offered (the tray and Settings say
/// the windows come back when the screen share ends), since the next look would take them off again.
pub fn show_windows(app: &AppHandle) {
    let Some(privacy) = app.try_state::<Privacy>() else { return };
    if privacy.hidden_for(HideReason::Person) {
        set_hidden(app, HideReason::Person, false);
    }
    if privacy.hidden_for(HideReason::Share) {
        privacy.release_share();
    }
}

/// "Hide EchoLedger windows" (the shortcut): off the screen, and back on the next press.
pub fn toggle_hide_all(app: &AppHandle) {
    let on = !app.state::<Privacy>().hidden_for(HideReason::Person);
    set_hidden(app, HideReason::Person, on);
}

/// Takes EchoLedger's windows off the screen for `reason` ([`away`] says which), or brings them back once nothing
/// keeps them off. The floating windows' pages stop drawing first (`privacy:conceal`), then each window is ordered out
/// without closing (nothing is lost, and the app stays in the Dock, the menu bar, the tray and every window list).
/// Every change of a hide reason comes here (the shortcut, the tray, Settings, an open by the person, the safety net), so
/// the tray's line and the pages follow it at once.
pub fn set_hidden(app: &AppHandle, reason: HideReason, on: bool) {
    let privacy = app.state::<Privacy>();
    if privacy.set_hidden(reason, on) {
        let _ = app.emit("privacy:conceal", privacy.concealed());
    }
    reconcile(app);
    let _ = app.emit("privacy:changed", ());
    crate::actions::emit_status(app);
}

/// The person opened the main window: the tray's Open, the app opened again (Dock, Finder, Launchpad, Spotlight), a
/// second launch, a clicked notification, all through `lifecycle::show_main`. "Hide EchoLedger windows" ends; during a
/// whole-screen share the main window is shown anyway with a line saying it may be in the screen share, and the floating
/// windows stay off.
pub fn opened_by_person(app: &AppHandle) {
    let Some(privacy) = app.try_state::<Privacy>() else { return };
    let opened = privacy.person_opened();
    if opened.end_person_hide {
        set_hidden(app, HideReason::Person, false);
    } else if opened.during_share {
        reconcile(app);
        let _ = app.emit("privacy:changed", ());
        crate::actions::emit_status(app);
    }
}

/// Whether the main window may come on screen now (`lifecycle::reveal_main`): an automatic reveal (a rebuilt window's
/// page drawing) never brings it back while windows are kept off, and a quiet open stays off while a reason keeps the
/// main window off, even when the person opened it earlier in the share.
pub fn may_show_main<R: Runtime>(app: &AppHandle<R>, quiet: bool) -> bool {
    let Some(privacy) = app.try_state::<Privacy>() else { return true };
    privacy.may_show_main(&choice_now(app), quiet)
}

/// Puts every window where the reasons want it, on the main thread: there a window's state is read without a round trip
/// (no lock is ever held across one) and two changes never cross. A window that must be off and is on screen is ordered
/// out; one the service took off and that may come back is ordered in.
pub fn reconcile<R: Runtime>(app: &AppHandle<R>) {
    let handle = app.clone();
    let _ = app.run_on_main_thread(move || {
        let Some(privacy) = handle.try_state::<Privacy>() else { return };
        let (reasons, choice, main_asked) = (privacy.reasons(), choice_now(&handle), privacy.main_asked());
        for (label, kind) in privacy.registered() {
            let Some(window) = handle.get_webview_window(&label) else { continue };
            let (off, taken) = (away(kind, &reasons, &choice, main_asked), lock(&privacy.put_away).contains(&label));
            if off && !taken && window.is_visible().unwrap_or(false) {
                put_away(&window, true);
                lock(&privacy.put_away).insert(label);
            } else if !off && taken && reveals_itself(kind) {
                // The card and the captions come back only through the gate: flag set, read back, reasons read again.
                gate(&handle, &window, true);
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
        let (Some(privacy), Some(mtm)) = (handle.try_state::<Privacy>(), MainThreadMarker::new()) else { return };
        let ours: BTreeSet<usize> = privacy.registered().iter().filter_map(|(label, _)| handle.get_webview_window(label)?.ns_window().ok()).map(|p| p as usize).collect();
        let protect = others_protected(&choice_now(&handle));
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
/// read. A flag that changed is logged (which window and what was read, never what it shows) and left as it is. Waking
/// from sleep and a display change are EchoLedger's own lifecycle points: there every flag is set again first.
#[cfg(windows)]
pub fn spawn_recheck(app: AppHandle) {
    tauri::async_runtime::spawn(async move {
        let mut points = Points::default();
        loop {
            tokio::time::sleep(win::RECHECK_EVERY).await;
            // Woken from sleep, or the displays changed: EchoLedger sets every flag again before anything is read back,
            // so the system dropping a flag there never reads as a change by something else.
            let reader = app.clone();
            let shown = tokio::task::spawn_blocking(move || displays(&reader)).await.ok().flatten();
            if let Some(point) = points.tick(SystemTime::now(), win::RECHECK_EVERY, shown) {
                at_point(&app, point);
                continue;
            }
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

    /// The choices with the main window hidden or not, the card and the captions at their default (hidden).
    fn choice(hiding: ShareHiding, main_hidden: bool) -> Choice {
        Choice { hiding, demo: false, main_hidden, show_card: false, show_captions: false }
    }

    #[test]
    fn every_window_echoledger_builds_is_registered_with_its_kind() {
        let privacy = Privacy::new();
        for label in crate::lifecycle::LABELS {
            assert!(privacy.kind_of(label).is_some(), "{label} is not registered");
        }
        assert_eq!(privacy.kind_of(crate::translate::WINDOW), Some(Kind::Floating), "Live Translate");
        assert_eq!(privacy.kind_of(ASSIST), Some(Kind::Card), "the Assist card, before it exists");
        assert_eq!(privacy.kind_of(crate::overlay::OVERLAY), Some(Kind::Captions));
        assert_eq!(privacy.kind_of(crate::lifecycle::MAIN), Some(Kind::Main));
        for label in crate::overlay::WINDOWS {
            let kind = if label == ASSIST { Kind::Card } else if label == crate::overlay::OVERLAY { Kind::Captions } else { Kind::Floating };
            assert_eq!(privacy.kind_of(label), Some(kind), "{label}");
            assert_eq!(kind_for(label), kind, "{label} is built with its kind");
        }
        assert_eq!(kind_for("notes"), Kind::Floating, "a window added later");
        // A window added later registers itself.
        privacy.register("notes", Kind::Floating);
        assert_eq!(privacy.kind_of("notes"), Some(Kind::Floating));
    }

    #[test]
    fn the_setting_decides_which_windows_are_protected() {
        use ShareHiding::*;
        for (hiding, floating) in [(All, true), (Floating, true), (Off, false)] {
            let c = choice(hiding, false);
            assert_eq!(protected(Kind::Floating, &c), floating, "{hiding:?}");
            assert!(!protected(Kind::Main, &c), "{hiding:?}: the main window is shown unless the person hides it");
            assert!(protected(Kind::Main, &choice(hiding, true)), "{hiding:?}: hidden by the person");
            assert!(protected(Kind::Card, &c) && protected(Kind::Captions, &c), "{hiding:?}: the card and the captions stay hidden");
            assert!(protected(Kind::Test, &c), "the test window is always protected");
            assert!(!protected(Kind::Control, &c), "the control never is");
        }
        let demo = Choice { demo: true, ..choice(All, true) };
        assert!(!protected(Kind::Floating, &demo), "the demo shows the other floating windows");
        assert!(protected(Kind::Main, &demo), "the demo never shows a main window the person hid");
        assert!(protected(Kind::Card, &demo) && protected(Kind::Captions, &demo), "the demo never shows the card or the captions");
        assert!(protected(Kind::Test, &demo), "even during a demo the Share Check measures the flag");
    }

    #[test]
    fn defaults_show_the_main_window_and_hide_the_card_and_the_captions() {
        let privacy = Privacy::new();
        let (settings, now, ms) = (Settings::default(), Instant::now(), 0);
        assert_eq!(privacy.wants(crate::lifecycle::MAIN, &settings, now, ms), Some(false), "the main window is in screen shares");
        assert_eq!(privacy.wants(ASSIST, &settings, now, ms), Some(true), "the Live Assist card is hidden");
        assert_eq!(privacy.wants(crate::overlay::OVERLAY, &settings, now, ms), Some(true), "the captions are hidden");
    }

    #[test]
    fn the_card_and_the_captions_are_never_shown_by_the_main_window_s_choice() {
        let privacy = Privacy::new();
        let now = Instant::now();
        let t = chrono::Local::now();
        // Every main-window state, every hideFromSharing value, the demo on or off: the card and the captions stay hidden.
        let hides = [None, Some(share::hide_until(share::HideFor::UntilOff, t, false).unwrap()), Some(share::hide_until(share::HideFor::Minutes15, t, false).unwrap())];
        for hiding in [ShareHiding::All, ShareHiding::Floating, ShareHiding::Off] {
            for hide in hides {
                for demo in [false, true] {
                    let settings = Settings { hide_from_sharing: hiding, main_share_hide: hide, ..Settings::default() };
                    privacy.set_demo(demo.then(|| Demo::starting(now, SystemTime::now(), DEMO)));
                    for label in [ASSIST, crate::overlay::OVERLAY] {
                        assert_eq!(privacy.wants(label, &settings, now, t.timestamp_millis()), Some(true), "{label} {hiding:?} {hide:?} demo {demo}");
                    }
                }
            }
        }
        // Only their own switch shows them, each on its own.
        privacy.set_demo(None);
        let settings = Settings::default();
        privacy.set_shown_in_shares(share::Pinned::Card, true);
        assert_eq!(privacy.wants(ASSIST, &settings, now, 0), Some(false));
        assert_eq!(privacy.wants(crate::overlay::OVERLAY, &settings, now, 0), Some(true), "the card's switch is not the captions'");
        privacy.set_shown_in_shares(share::Pinned::Card, false);
        privacy.set_shown_in_shares(share::Pinned::Captions, true);
        assert_eq!(privacy.wants(crate::overlay::OVERLAY, &settings, now, 0), Some(false));
        assert_eq!(privacy.wants(ASSIST, &settings, now, 0), Some(true));
        // The main window's commands never touch them: its hide and show change `mainShareHide` alone.
        for name in ["pub fn hide_main(", "pub fn show_main_in_shares("] {
            let body = body_of(include_str!("mod.rs"), name);
            assert!(!body.contains("set_shown_in_shares"), "{name}");
        }
    }

    #[test]
    fn showing_the_card_or_the_captions_lasts_until_that_window_closes_and_never_past_a_start() {
        // Every start begins hidden: nothing is read from the settings file.
        let privacy = Privacy::new();
        assert!(!privacy.shown_in_shares(share::Pinned::Card) && !privacy.shown_in_shares(share::Pinned::Captions));
        privacy.set_shown_in_shares(share::Pinned::Captions, true);
        assert!(privacy.shown_in_shares(share::Pinned::Captions));
        // When the window goes, the choice goes with it (`destroyed`).
        let gone = body_of(include_str!("mod.rs"), "pub fn destroyed<");
        assert!(gone.contains("share::Pinned::of_label(label)") && gone.contains("privacy.set_shown_in_shares(which, false);"));
        assert!(!include_str!("../settings.rs").contains("show_card_in_shares"), "never saved");
    }

    #[test]
    fn the_card_and_the_captions_come_on_screen_only_after_their_flag_held() {
        assert!(reveals_itself(Kind::Card) && reveals_itself(Kind::Captions));
        assert!(!reveals_itself(Kind::Floating) && !reveals_itself(Kind::Main));
        assert!(may_reveal(true, true), "hidden and held");
        assert!(!may_reveal(true, false), "hidden but the flag did not hold: stays off");
        assert!(may_reveal(false, false), "shown on purpose");
        // Built with visible(false); in the order: the flag set and read back, then the window on.
        let overlay = include_str!("../overlay/mod.rs");
        assert!(overlay.contains(".visible(!crate::privacy::reveals_itself(crate::privacy::kind_for(label)));"));
        let reveal = body_of(include_str!("mod.rs"), "fn gate<");
        let (wanted, run) = (reveal.find("let protect = wanted_for(&handle, target.label()).unwrap_or(true);").unwrap(), reveal.find("run_gate(").unwrap());
        assert!(wanted < run, "what it should be is read on the main thread, then the gate runs");
        let steps = body_of(include_str!("mod.rs"), "pub fn run_gate(");
        let (held, back, decide, on) = (
            steps.find("window.flag_held(protect)").unwrap(),
            steps.find("privacy.is_held_back(window.label())").unwrap(),
            steps.find("privacy.reveal_decision(").unwrap(),
            steps.find("window.put_away(off)").unwrap(),
        );
        assert!(held < back && back < decide && decide < on, "flag set and read back, held-back read now, the reasons read again, then on or off");
        // Every way back on screen for the card and the captions goes through the gate: reconcile too.
        let back = body_of(include_str!("mod.rs"), "pub fn reconcile<");
        let gate = back.find("gate(&handle, &window, true)").expect("reconcile uses the gate");
        assert!(gate < back.rfind("put_away(&window, false)").unwrap(), "checked before the plain way back");
        assert!(back.contains("!off && taken && reveals_itself(kind)"));
        // apply_all sends every open card and captions through it (on screen: taken off if the flag stops holding; held
        // back: tried again), never the plain set.
        let all = body_of(include_str!("mod.rs"), "pub fn apply_all<");
        assert!(all.find("gate(app, &window, false)").unwrap() < all.find("enforce(app, &window, protect, &settings)").unwrap());
        assert!(all.contains("if reveals_itself(kind)"));
        let flag = body_of(include_str!("mod.rs"), "fn flag_held_here<");
        assert!(flag.contains("set_and_read_back(app, window.label()"), "Windows: set and read back");
        assert!(flag.contains("read_flag_here(window) == Some(wanted)"), "macOS: read back");
    }

    #[test]
    fn a_reason_that_comes_between_scheduling_and_showing_keeps_the_card_off() {
        let privacy = Privacy::new();
        let choice = choice(ShareHiding::All, false);
        // Scheduled with nothing keeping it off; before the main thread runs it, the person hides EchoLedger's windows.
        privacy.set_hidden(HideReason::Person, true);
        let reveal = privacy.reveal_decision(Kind::Card, &choice, true, true);
        assert_eq!(reveal, Reveal::StayAway);
        privacy.revealed(ASSIST, reveal);
        assert!(lock(&privacy.put_away).contains(ASSIST), "tracked, so reconcile brings it back through the gate");
        // A whole-screen share instead: the same.
        let privacy = Privacy::new();
        privacy.set_hidden(HideReason::Share, true);
        assert_eq!(privacy.reveal_decision(Kind::Captions, &choice, true, true), Reveal::StayAway);
        // Nothing in the way: on screen when the flag held, held back when it did not.
        let privacy = Privacy::new();
        assert_eq!(privacy.reveal_decision(Kind::Card, &choice, true, true), Reveal::Show);
        assert_eq!(privacy.reveal_decision(Kind::Card, &choice, true, false), Reveal::HeldBack);
        privacy.revealed(ASSIST, Reveal::HeldBack);
        assert_eq!(privacy.held_back(), [ASSIST.to_string()]);
        privacy.revealed(ASSIST, Reveal::Show);
        assert!(privacy.held_back().is_empty(), "the next try that holds brings it on");
        assert_eq!(held_back_line(ASSIST), "The Live Assist card couldn't be hidden from screen shares, so it stays off.");
        assert_eq!(held_back_line(crate::overlay::OVERLAY), "The captions couldn't be hidden from screen shares, so they stay off.");
    }

    #[test]
    fn a_card_on_screen_whose_flag_stops_holding_goes_off_and_the_person_is_told() {
        let privacy = Privacy::new();
        let choice = choice(ShareHiding::All, false);
        // The card is on screen; the person hid it again (or the computer woke) and the flag reads back wrong.
        let reveal = privacy.reveal_decision(Kind::Card, &choice, true, false);
        assert_eq!(reveal, Reveal::HeldBack);
        assert_eq!(gate_step(reveal, true, false), Some(true), "apply_all's check takes it off the screen");
        assert_eq!(gate_step(reveal, true, true), Some(true));
        privacy.revealed(ASSIST, reveal);
        assert_eq!(privacy.held_back(), [ASSIST.to_string()], "the tray and Settings say so, and the next point tries again");
        // The next try holds: it comes back on.
        let next = privacy.reveal_decision(Kind::Card, &choice, true, true);
        assert_eq!(gate_step(next, false, true), Some(false));
        privacy.revealed(ASSIST, next);
        assert!(privacy.held_back().is_empty());
        // A flag that holds leaves a window on screen as it is; the check of the windows on screen never brings one on.
        assert_eq!(gate_step(Reveal::Show, true, false), None);
        assert_eq!(gate_step(Reveal::Show, false, false), None);
        // A reason that comes instead: off, and the reason (not the flag) is what is said.
        privacy.revealed(ASSIST, Reveal::HeldBack);
        privacy.set_hidden(HideReason::Person, true);
        let away_now = privacy.reveal_decision(Kind::Card, &choice, true, false);
        assert_eq!(away_now, Reveal::StayAway);
        assert_eq!(gate_step(away_now, true, false), Some(true));
        privacy.revealed(ASSIST, away_now);
        assert!(privacy.held_back().is_empty(), "StayAway clears the held-back line");
    }

    /// A window as the gate sees it, with a flag that holds or not.
    struct FakeWindow {
        label: &'static str,
        on_screen: bool,
        holds: bool,
        sets: usize,
    }

    impl GateWindow for FakeWindow {
        fn label(&self) -> &str {
            self.label
        }
        fn visible(&self) -> bool {
            self.on_screen
        }
        fn flag_held(&mut self, _protect: bool) -> bool {
            self.sets += 1;
            self.holds
        }
        fn put_away(&mut self, off: bool) {
            self.on_screen = !off;
        }
    }

    #[test]
    fn the_gate_takes_a_card_off_when_its_flag_fails_and_brings_it_back_when_it_holds() {
        let privacy = Privacy::new();
        let c = choice(ShareHiding::All, false);
        let mut card = FakeWindow { label: ASSIST, on_screen: true, holds: true, sets: 0 };
        // On screen, flag holds: left as it is.
        assert_eq!(run_gate(&privacy, Kind::Card, &c, true, &mut card, false), Some(Reveal::Show));
        assert!(card.on_screen);
        // A wake re-sets the flag and it fails: off the screen at once, held back.
        card.holds = false;
        assert_eq!(run_gate(&privacy, Kind::Card, &c, true, &mut card, false), Some(Reveal::HeldBack));
        assert!(!card.on_screen && privacy.is_held_back(ASSIST));
        // The next lifecycle point (apply_all, may_show false): the flag holds again, and it comes back.
        card.holds = true;
        assert_eq!(run_gate(&privacy, Kind::Card, &c, true, &mut card, false), Some(Reveal::Show));
        assert!(card.on_screen && !privacy.is_held_back(ASSIST));
        assert_eq!(card.sets, 3, "the flag is set on every pass");
        // Off the screen and not held back: apply_all only sets its flag.
        let mut captions = FakeWindow { label: crate::overlay::OVERLAY, on_screen: false, holds: true, sets: 0 };
        assert_eq!(run_gate(&privacy, Kind::Captions, &c, true, &mut captions, false), None);
        assert!(!captions.on_screen && captions.sets == 1);
    }

    #[test]
    fn a_window_held_back_comes_back_even_from_a_pass_that_was_queued_as_a_check() {
        // Two passes queued: the first holds the window back, the second was queued by apply_all (may_show false) before
        // that happened. It reads the held-back state when it runs, so a flag that now holds brings the window on.
        let privacy = Privacy::new();
        let c = choice(ShareHiding::All, false);
        let mut card = FakeWindow { label: ASSIST, on_screen: true, holds: false, sets: 0 };
        assert_eq!(run_gate(&privacy, Kind::Card, &c, true, &mut card, true), Some(Reveal::HeldBack));
        assert!(!card.on_screen);
        card.holds = true;
        assert_eq!(run_gate(&privacy, Kind::Card, &c, true, &mut card, false), Some(Reveal::Show));
        assert!(card.on_screen && !privacy.is_held_back(ASSIST), "back on screen, the line gone");
    }

    #[test]
    fn a_held_back_window_stays_off_while_a_reason_holds_it_or_its_flag_fails_again() {
        let c = choice(ShareHiding::All, false);
        // Held back, then the person hides EchoLedger's windows: the flag now holds, but the reason keeps it off; the
        // reason (not the flag) is what is said, so it leaves held-back and is tracked as put away.
        let privacy = Privacy::new();
        let mut card = FakeWindow { label: ASSIST, on_screen: true, holds: false, sets: 0 };
        assert_eq!(run_gate(&privacy, Kind::Card, &c, true, &mut card, false), Some(Reveal::HeldBack));
        privacy.set_hidden(HideReason::Person, true);
        card.holds = true;
        assert_eq!(run_gate(&privacy, Kind::Card, &c, true, &mut card, false), Some(Reveal::StayAway));
        assert!(!card.on_screen, "stays off");
        assert!(!privacy.is_held_back(ASSIST), "leaves held-back");
        assert!(lock(&privacy.put_away).contains(ASSIST), "reconcile brings it back through the gate when the reason ends");
        // Held back, and on the next check (may_show false) the flag fails again: still off, still held back.
        let privacy = Privacy::new();
        let mut captions = FakeWindow { label: crate::overlay::OVERLAY, on_screen: true, holds: false, sets: 0 };
        assert_eq!(run_gate(&privacy, Kind::Captions, &c, true, &mut captions, true), Some(Reveal::HeldBack));
        assert!(!captions.on_screen);
        assert_eq!(run_gate(&privacy, Kind::Captions, &c, true, &mut captions, false), Some(Reveal::HeldBack));
        assert!(!captions.on_screen, "stays off");
        assert!(privacy.is_held_back(crate::overlay::OVERLAY), "stays held back, and the line stays");
        assert_eq!(captions.sets, 2, "the flag was tried again");
    }

    #[test]
    fn the_card_and_the_captions_are_a_black_box_at_least_on_older_windows() {
        use crate::settings::OldWindowsSharing::{BlackBox, Show};
        assert_eq!(old_windows_for(Some(Kind::Card), Show), BlackBox);
        assert_eq!(old_windows_for(Some(Kind::Captions), Show), BlackBox);
        assert_eq!(old_windows_for(Some(Kind::Floating), Show), Show, "the others follow the setting");
        assert_eq!(old_windows_for(None, Show), Show);
        assert_eq!(win::affinity(true, false, Some(18_363), old_windows_for(Some(Kind::Card), Show)), win::WDA_MONITOR);
    }

    #[test]
    fn the_main_window_s_hide_ends_by_the_clock() {
        let privacy = Privacy::new();
        let now = Instant::now();
        let t = chrono::Local::now();
        let hide = share::hide_until(share::HideFor::Minutes15, t, false).unwrap();
        let settings = Settings { main_share_hide: Some(hide), ..Settings::default() };
        let ms = t.timestamp_millis();
        assert_eq!(privacy.wants(crate::lifecycle::MAIN, &settings, now, ms), Some(true));
        assert_eq!(privacy.wants(crate::lifecycle::MAIN, &settings, now, ms + 15 * 60_000 - 1), Some(true));
        assert_eq!(privacy.wants(crate::lifecycle::MAIN, &settings, now, ms + 15 * 60_000), Some(false), "shown again by itself");
    }

    #[test]
    fn the_ten_minute_demo_returns_to_the_setting_by_itself() {
        let privacy = Privacy::new();
        let settings = Settings::default();
        let start = Instant::now();
        assert_eq!(privacy.wants(crate::overlay::PILL, &settings, start, 0), Some(true));
        privacy.set_demo(Some(Demo::starting(start, SystemTime::now(), DEMO)));
        assert_eq!(privacy.wants(crate::overlay::PILL, &settings, start, 0), Some(false), "shown during the demo");
        assert_eq!(privacy.wants(crate::lifecycle::MAIN, &settings, start + Duration::from_secs(9 * 60), 0), Some(false));
        assert_eq!(privacy.wants(ASSIST, &settings, start + Duration::from_secs(9 * 60), 0), Some(true), "never the card");
        assert!(privacy.demo_left(start + Duration::from_secs(60)).is_some_and(|d| d == Duration::from_secs(9 * 60)));
        // Ten minutes later the setting holds again, without anyone touching it.
        assert_eq!(privacy.wants(crate::overlay::PILL, &settings, start + DEMO, 0), Some(true));
        assert_eq!(privacy.wants(crate::lifecycle::MAIN, &settings, start + DEMO, 0), Some(false), "the main window is shown unless the person hides it");
        assert_eq!(privacy.demo_left(start + DEMO), None);
        assert_eq!(DEMO, Duration::from_secs(600));
    }

    #[test]
    fn a_share_takes_the_main_window_off_only_with_all_windows_and_the_person_takes_all() {
        use HideReason::*;
        let reasons = |list: &[HideReason]| list.iter().copied().collect::<BTreeSet<_>>();
        for main_hidden in [true, false] {
            let c = choice(ShareHiding::All, main_hidden);
            assert!(away(Kind::Floating, &reasons(&[Share]), &c, false), "the floating windows leave a share");
            assert!(away(Kind::Card, &reasons(&[Share]), &c, false) && away(Kind::Captions, &reasons(&[Share]), &c, false), "so do the card and the captions");
            assert!(away(Kind::Main, &reasons(&[Person]), &c, false), "Hide EchoLedger windows takes every window");
            assert!(away(Kind::Floating, &reasons(&[Person]), &c, true));
        }
        assert!(away(Kind::Main, &reasons(&[Share]), &choice(ShareHiding::All, true), false), "a main window hidden from shares leaves the share");
        assert!(!away(Kind::Main, &reasons(&[Share]), &choice(ShareHiding::All, false), false), "a main window shown in shares stays");
        let shown = Choice { show_card: true, show_captions: true, ..choice(ShareHiding::All, false) };
        assert!(!away(Kind::Card, &reasons(&[Share]), &shown, false) && !away(Kind::Captions, &reasons(&[Share]), &shown, false), "shown on purpose: they stay");
        assert!(away(Kind::Card, &reasons(&[Person]), &shown, false), "Hide EchoLedger windows still takes them");
        for kind in [Kind::Main, Kind::Floating, Kind::Card, Kind::Captions, Kind::Test, Kind::Control] {
            assert!(!away(kind, &reasons(&[]), &choice(ShareHiding::All, true), false), "{kind:?}: nothing keeps it off");
        }
        assert!(!away(Kind::Test, &reasons(&[Person, Share]), &choice(ShareHiding::All, true), false), "the Share Check's windows are never put away");
        assert!(!away(Kind::Control, &reasons(&[Person, Share]), &choice(ShareHiding::All, true), false));
    }

    #[test]
    fn opening_echoledger_during_a_hide_shows_the_main_window_and_automatic_reveals_do_not() {
        use HideReason::*;
        let hidden = choice(ShareHiding::All, true);
        let floating_away = |p: &Privacy| away(Kind::Floating, &p.reasons(), &hidden, p.main_asked());
        // A whole-screen share took the windows off (the main window hidden from screen shares).
        let p = Privacy::new();
        p.set_hidden(Share, true);
        assert!(!p.may_show_main(&hidden, false), "a rebuilt main window's page drawing does not bring it back");
        assert!(!p.main_in_share(true));
        // The person opens EchoLedger again (the Dock, Finder, the tray's Open, a clicked notification).
        assert_eq!(p.person_opened(), Opened { end_person_hide: false, during_share: true });
        assert!(p.may_show_main(&hidden, false), "shown anyway: the person asked");
        assert!(p.main_in_share(true), "with the line that it may be in the screen share");
        assert!(floating_away(&p), "the floating windows stay off");
        // The share is over: the next share starts without that open.
        p.set_hidden(Share, false);
        assert!(!p.main_asked());
        p.set_hidden(Share, true);
        assert!(!p.may_show_main(&hidden, false));

        // Hide EchoLedger windows: an open by the person ends it (`opened_by_person` turns the reason off).
        let p = Privacy::new();
        p.set_hidden(Person, true);
        assert!(!p.may_show_main(&choice(ShareHiding::Floating, false), false));
        assert_eq!(p.person_opened(), Opened { end_person_hide: true, during_share: false });
        p.set_hidden(Person, false);
        assert!(p.may_show_main(&choice(ShareHiding::Floating, false), false) && !floating_away(&p), "everything comes back");
        assert!(!p.main_in_share(true));
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
        assert!(others_protected(&choice(ShareHiding::All, false)), "TUINSWindow is left out of screen share");
        assert!(others_protected(&choice(ShareHiding::Floating, false)), "it sits over EchoLedger's text fields");
        assert!(!others_protected(&choice(ShareHiding::Off, false)));
        assert!(!others_protected(&Choice { demo: true, ..choice(ShareHiding::All, false) }), "the demo shows the floating windows");
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
    fn closing_the_main_window_during_a_share_ends_the_person_s_open_and_quiet_opens_stay_off() {
        use HideReason::*;
        let p = Privacy::new();
        let hidden = choice(ShareHiding::All, true);
        p.set_hidden(Share, true);
        p.person_opened();
        assert!(p.may_show_main(&hidden, false));
        assert!(!p.may_show_main(&hidden, true), "a quiet open never counts the person's open");
        // The person closes the main window while the share goes on; something else opens it again later.
        p.main_closed();
        assert!(!p.main_asked() && !p.main_in_share(true));
        assert!(!p.may_show_main(&hidden, false), "a rebuilt window's page drawing stays off");
        assert!(!p.may_show_main(&hidden, true));
        // Hide EchoLedger windows: quiet opens stay off too; with nothing hidden they show.
        let p = Privacy::new();
        p.set_hidden(Person, true);
        assert!(!p.may_show_main(&choice(ShareHiding::Off, false), true));
        p.set_hidden(Person, false);
        assert!(p.may_show_main(&hidden, true));
        // The main window shown in screen shares: a share never keeps it off.
        p.set_hidden(Share, true);
        assert!(p.may_show_main(&choice(ShareHiding::Floating, false), true));
    }

    #[test]
    fn each_share_has_its_own_number_and_a_bar_on_screen_holds_the_windows() {
        use HideReason::*;
        let p = Privacy::new();
        assert_eq!(p.share_number(), 0);
        p.set_hidden(Share, true);
        p.set_hidden(Share, true);
        assert_eq!(p.share_number(), 1, "the same share");
        p.set_bar_on_screen(true);
        assert!(p.bar_holds(), "Show EchoLedger windows can't bring them back now");
        p.set_bar_on_screen(false);
        assert!(!p.bar_holds(), "the bar is hidden while the meeting goes on: Show works");
        p.set_bar_on_screen(true);
        p.set_hidden(Share, false);
        assert!(!p.bar_holds(), "over with the share");
        p.set_hidden(Share, true);
        assert_eq!(p.share_number(), 2, "the next share");
        assert!(!p.bar_holds(), "until the safety net sees its bar");
        // The person's hide is not a share.
        p.set_hidden(Person, true);
        assert_eq!(p.share_number(), 2);
    }

    #[test]
    fn waking_and_a_display_change_are_lifecycle_points() {
        let start = SystemTime::now();
        let one = vec![(0, 0, 2560, 1440, 2000)];
        let two = vec![(2560, 0, 1920, 1080, 1000), (0, 0, 2560, 1440, 2000)];
        let mut points = Points::default();
        assert_eq!(points.tick(start, win::RECHECK_EVERY, Some(one.clone())), None, "the first look has nothing to compare");
        let mut at = start;
        for _ in 0..3 {
            at += Duration::from_secs(5);
            assert_eq!(points.tick(at, win::RECHECK_EVERY, Some(one.clone())), None, "every 5 s, nothing changed");
        }
        at += Duration::from_secs(9);
        assert_eq!(points.tick(at, win::RECHECK_EVERY, Some(one.clone())), None, "a slow tick is not a sleep");
        at += Duration::from_secs(8 * 3600);
        assert_eq!(points.tick(at, win::RECHECK_EVERY, Some(one.clone())), Some(Point::Woke), "the lid was closed overnight");
        at += Duration::from_secs(5);
        assert_eq!(points.tick(at, win::RECHECK_EVERY, Some(two.clone())), Some(Point::Displays), "a display was plugged in");
        let mut reversed = two.clone();
        reversed.reverse();
        at += Duration::from_secs(5);
        assert_eq!(points.tick(at, win::RECHECK_EVERY, Some(reversed)), None, "the same displays in another order");
        at += Duration::from_secs(5);
        assert_eq!(points.tick(at, win::RECHECK_EVERY, None), None, "unread displays compare nothing");
        at += Duration::from_secs(5);
        assert_eq!(points.tick(at, win::RECHECK_EVERY, Some(vec![(0, 0, 2560, 1440, 1000)])), Some(Point::Displays), "a scale change");
        assert!(Point::Woke.line().contains("set every window's capture flag again"));
        // Windows sets every flag again before anything is read back; the Mac's safety net looks every 5 s.
        let recheck = body_of(include_str!("mod.rs"), "pub fn spawn_recheck(");
        assert!(recheck.find("points.tick(").unwrap() < recheck.find("privacy.watch).tick(").unwrap(), "set again before the read-back");
        assert!(include_str!("watch.rs").contains("points.tick(std::time::SystemTime::now(), PERMISSION_EVERY, super::displays(&app))"));
    }

    /// The body of `fn name` in `text` (from its line to the next item at the same indentation).
    fn body_of<'a>(text: &'a str, name: &str) -> &'a str {
        let start = text.find(name).unwrap_or_else(|| panic!("{name} not found"));
        let rest = &text[start..];
        let end = rest.find("\n}\n").map_or(rest.len(), |e| e + 3);
        &rest[..end]
    }

    #[test]
    fn the_tray_follows_every_change_of_a_hide_reason() {
        let source = include_str!("mod.rs");
        // One door: the person's hide (the shortcut, the tray, Settings), an open by the person and the safety net all
        // change a reason through `set_hidden`, which refreshes the status the tray's line is drawn from.
        assert!(body_of(source, "pub fn set_hidden(app").contains("crate::actions::emit_status(app);"));
        assert!(body_of(source, "pub fn toggle_hide_all(").contains("set_hidden(app, HideReason::Person, on)"));
        let opened = body_of(source, "pub fn opened_by_person(");
        assert!(opened.contains("set_hidden(app, HideReason::Person, false)") && opened.contains("crate::actions::emit_status(app);"));
        assert!(include_str!("../actions.rs").contains("(Action::HideAll, true) => {\n            crate::privacy::toggle_hide_all(&app);"), "the shortcut");
        assert!(include_str!("../commands.rs").contains("crate::privacy::toggle_hide_all(&app);"), "Settings' Hide now");
        assert!(include_str!("watch.rs").contains("super::set_hidden(&app, super::HideReason::Share, step.hide);"), "the safety net");
        // Nothing else changes a reason behind the door's back.
        let mut files = Vec::new();
        sources(&std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("src"), &mut files);
        for file in &files {
            let text = std::fs::read_to_string(file).unwrap();
            let code = text.split("#[cfg(test)]").next().unwrap_or("");
            let direct = code.matches("privacy.set_hidden(").count() + code.matches("p.set_hidden(").count();
            let allowed = if file.ends_with("privacy/mod.rs") { 1 } else { 0 };
            assert_eq!(direct, allowed, "{}: a hide reason changed outside privacy::set_hidden", file.display());
        }
        // And the tray draws the line from the reasons.
        let p = Privacy::new();
        p.set_hidden(HideReason::Person, true);
        assert_eq!(crate::tray::hidden_line(&p), Some(crate::tray::Hidden::Person));
        p.set_hidden(HideReason::Person, false);
        assert_eq!(crate::tray::hidden_line(&p), None);
        p.set_hidden(HideReason::Share, true);
        assert_eq!(crate::tray::hidden_line(&p), Some(crate::tray::Hidden::Share));
        p.set_bar_on_screen(true);
        assert_eq!(crate::tray::hidden_line(&p), Some(crate::tray::Hidden::ShareBar));
    }

    #[test]
    fn the_privacy_log_names_windows_the_way_people_know_them() {
        // "Copy diagnostics" hands these lines out: docs/DESIGN_SYSTEM.md's names, never a window's internal label.
        let internal = ["pill", "overlay", "chip", "toast", "popup", "bubble", "review", "share-check"];
        let mut labels: Vec<&str> = KNOWN.iter().map(|(label, _)| *label).collect();
        labels.push("notes");
        for label in labels {
            let name = window_name(label);
            let lines = [
                mismatch_line(label, "0x0".into(), 0x11),
                win::Changed { label: label.into(), set: 0x11, read: Some(0) }.line(),
                win::Changed { label: label.into(), set: 0x11, read: None }.line(),
            ];
            for line in lines {
                assert!(line.contains(name), "{line}");
                let words: Vec<String> = line.split(|c: char| !c.is_alphanumeric() && c != '-').map(str::to_lowercase).collect();
                for word in internal {
                    assert!(!words.iter().any(|w| w.starts_with(word)), "{label}: `{word}` in {line}");
                }
                if !name.to_lowercase().contains(&label.to_lowercase()) {
                    assert!(!words.contains(&label.to_lowercase()), "{label}: the internal label in {line}");
                }
            }
        }
        assert_eq!(window_name(crate::overlay::PILL), "recording controls");
        assert_eq!(window_name(crate::overlay::OVERLAY), "captions");
        assert_eq!(window_name(crate::overlay::REVIEW), "after-call card");
        assert_eq!(window_name(crate::translate::WINDOW), "Live Translate");
        assert_eq!(window_name("notes"), "floating window", "a window added later");
        // The log lines are made only there.
        for (file, source) in [("mod.rs", include_str!("mod.rs")), ("win.rs", include_str!("win.rs"))] {
            let code = source.split("\n#[cfg(test)]\nmod tests").next().unwrap();
            for raw in ["The {label} window", "The {} window's", "\"The {} window"] {
                assert!(!code.contains(raw), "{file}: a line made with the label itself");
            }
        }
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
