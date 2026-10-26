//! On-demand windows (C9): an idle app runs one web view at most. The main window is built when it is opened and
//! destroyed when it is closed; each overlay is built the first time it is needed and destroyed once it has been
//! hidden for `HIDE_GRACE`, except the dictation box, which stays built while its shortcut is bound (`WARM`).
//! Recording, detection, shortcuts and uploads live in the core and need no window.
//!
//! Events for a window that is still being built are kept (the latest of each kind, in order) and sent when its page
//! says it listens (`window_ready`). Events for a window that does not exist are dropped: every page reads the
//! current state when it starts. Captions are replayed by `CaptionHub` instead.
//!
//! Overlays keep the no-focus rule (overlay/mod.rs): built unfocused and unfocusable, never `show()`n. Building and
//! destroying run on the async runtime, never inside a sync command or an event handler (WebView2 would deadlock).

use std::collections::{BTreeSet, HashMap};
use std::sync::{Mutex, MutexGuard};
use std::time::{Duration, Instant};

use serde::Serialize;
use serde_json::Value;
use tauri::utils::config::WindowConfig;
use tauri::{AppHandle, Emitter, Manager, WebviewWindowBuilder};

use crate::overlay::{self, DICTATION, OVERLAY, PILL, REVIEW};

pub const MAIN: &str = "main";

/// Every window built on demand.
pub const LABELS: [&str; 5] = [MAIN, OVERLAY, DICTATION, PILL, REVIEW];

/// How long an overlay stays built after it was hidden (a call often brings the pill or captions back soon).
pub const HIDE_GRACE: Duration = Duration::from_secs(30);

/// A freshly built main window is shown when its page has drawn, or after this long at the latest.
const MAIN_SHOW_FALLBACK: Duration = Duration::from_millis(1500);

/// Main view state is a small JSON object from the page; anything larger is refused.
pub const MAIN_VIEW_MAX_BYTES: usize = 4096;

#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub enum Phase {
    #[default]
    Absent,
    /// Built or being built; its page does not listen yet.
    Creating,
    Ready,
    /// Being destroyed; a show meanwhile builds it again once it is gone.
    Closing,
}

/// What `Slots::event` did with an event.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Delivery {
    /// The page listens: send it now.
    Now,
    /// Kept until the page is ready.
    Kept,
    /// No window: nothing to tell (the page reads the current state when it starts).
    Dropped,
}

/// The page of a window said it listens.
#[derive(Debug, Default, PartialEq)]
pub struct Ready {
    /// Kept events, oldest first.
    pub events: Vec<(String, Value)>,
    /// From the first show to now (the trigger-to-visible time).
    pub latency: Option<Duration>,
    /// Nothing needs the window any more: check this hide generation after the grace.
    pub expire: Option<u64>,
}

#[derive(Debug, Default)]
struct Slot {
    phase: Phase,
    /// Why the window is on screen (the review card and the "ask" chip share one window).
    uses: BTreeSet<&'static str>,
    /// Bumped on every hide: only the timer of the last hide may destroy the window.
    hidden: u64,
    pending: Vec<(String, Value)>,
    asked_at: Option<Instant>,
}

/// The lifecycle of every on-demand window: plain state, no Tauri calls (unit-tested below).
#[derive(Debug, Default)]
pub struct Slots(HashMap<String, Slot>);

impl Slots {
    fn slot(&mut self, label: &str) -> &mut Slot {
        self.0.entry(label.to_string()).or_default()
    }

    pub fn phase(&self, label: &str) -> Phase {
        self.0.get(label).map_or(Phase::Absent, |s| s.phase)
    }

    /// `reason` needs `label` on screen. True when the caller must build the window now (never twice).
    pub fn show(&mut self, label: &str, reason: &'static str, now: Instant) -> bool {
        let slot = self.slot(label);
        slot.uses.insert(reason);
        if slot.phase != Phase::Absent {
            return false; // built, being built, or rebuilt once the closing one is gone
        }
        slot.phase = Phase::Creating;
        slot.pending.clear();
        slot.asked_at = Some(now);
        true
    }

    /// `reason` no longer needs `label`. When nothing does, returns the hide generation to check after the grace.
    pub fn hide(&mut self, label: &str, reason: &'static str) -> Option<u64> {
        let slot = self.slot(label);
        slot.uses.remove(reason);
        if !slot.uses.is_empty() || matches!(slot.phase, Phase::Absent | Phase::Closing) {
            return None;
        }
        slot.hidden += 1;
        Some(slot.hidden)
    }

    pub fn in_use(&self, label: &str) -> bool {
        self.0.get(label).is_some_and(|s| !s.uses.is_empty())
    }

    /// Sends, keeps (only the latest of each event name, in the order last sent) or drops an event for `label`.
    pub fn event(&mut self, label: &str, name: &str, payload: Value) -> Delivery {
        let slot = self.slot(label);
        let keep = match slot.phase {
            Phase::Ready => return Delivery::Now,
            Phase::Absent => false,
            Phase::Creating => true,
            Phase::Closing => !slot.uses.is_empty(), // for the window built after this one
        };
        if !keep {
            return Delivery::Dropped;
        }
        slot.pending.retain(|(n, _)| n != name);
        slot.pending.push((name.to_string(), payload));
        Delivery::Kept
    }

    /// The page of `label` listens now. A page we did not build (or are closing) changes nothing.
    pub fn ready(&mut self, label: &str, now: Instant) -> Ready {
        let slot = self.slot(label);
        if !matches!(slot.phase, Phase::Creating | Phase::Ready) {
            return Ready::default();
        }
        slot.phase = Phase::Ready;
        let expire = slot.uses.is_empty().then(|| {
            slot.hidden += 1;
            slot.hidden
        });
        Ready { events: std::mem::take(&mut slot.pending), latency: slot.asked_at.take().map(|t| now.saturating_duration_since(t)), expire }
    }

    /// The grace after hide `generation` is over: true when the window must be destroyed now (it is then closing).
    /// A window still being built is checked again when its page is ready.
    pub fn expire(&mut self, label: &str, generation: u64) -> bool {
        let slot = self.slot(label);
        if slot.phase != Phase::Ready || !slot.uses.is_empty() || slot.hidden != generation {
            return false;
        }
        slot.phase = Phase::Closing;
        slot.pending.clear();
        true
    }

    /// The user closes the window (the main window): nothing needs it any more.
    pub fn closing(&mut self, label: &str) {
        let slot = self.slot(label);
        slot.phase = Phase::Closing;
        slot.uses.clear();
        slot.pending.clear();
    }

    /// The window is gone (destroyed after the grace, closed by the user, or it failed to build). True when it was
    /// asked for again meanwhile and must be built now; its kept events wait for the new page.
    pub fn destroyed(&mut self, label: &str, now: Instant) -> bool {
        let slot = self.slot(label);
        if slot.phase == Phase::Closing && !slot.uses.is_empty() {
            slot.phase = Phase::Creating;
            slot.asked_at = Some(now);
            return true;
        }
        *slot = Slot { hidden: slot.hidden, ..Slot::default() };
        false
    }
}

/// Managed state: the window slots and where the user was in the main window.
#[derive(Default)]
pub struct WindowLifecycle {
    slots: Mutex<Slots>,
    main_view: Mutex<Option<String>>,
}

impl WindowLifecycle {
    pub fn main_view(&self) -> Option<String> {
        self.main_view.lock().unwrap_or_else(|p| p.into_inner()).clone()
    }

    /// False when the state is too large to keep.
    pub fn set_main_view(&self, view: String) -> bool {
        if view.len() > MAIN_VIEW_MAX_BYTES {
            return false;
        }
        *self.main_view.lock().unwrap_or_else(|p| p.into_inner()) = Some(view);
        true
    }
}

fn slots(app: &AppHandle) -> MutexGuard<'_, Slots> {
    app.state::<WindowLifecycle>().inner().slots.lock().unwrap_or_else(|p| p.into_inner())
}

/// The `'static` label of an on-demand window.
fn known(label: &str) -> Option<&'static str> {
    LABELS.into_iter().find(|l| *l == label)
}

/// Puts `label` on screen for `reason`, building it first when it does not exist.
pub fn show(app: &AppHandle, label: &'static str, reason: &'static str) {
    if slots(app).show(label, reason, Instant::now()) {
        spawn_build(app, label);
    }
}

/// `reason` no longer needs `label`; an overlay nothing needs is destroyed after `HIDE_GRACE`.
pub fn hide(app: &AppHandle, label: &'static str, reason: &'static str) {
    let generation = slots(app).hide(label, reason);
    if let Some(generation) = generation {
        schedule_expiry(app, label, generation);
    }
}

/// `emit_to` for an on-demand window: kept while the window is being built, dropped when there is none.
pub fn emit<S: Serialize>(app: &AppHandle, label: &str, event: &str, payload: S) {
    let Ok(value) = serde_json::to_value(payload) else { return };
    // Sent under the lock, so a flush of kept events in `ready` can never overtake a newer one.
    let mut slots = slots(app);
    if slots.event(label, event, value.clone()) == Delivery::Now {
        let _ = app.emit_to(label, event, value);
    }
}

/// `window_ready`: the page of `label` listens; it gets what was kept for it, and the main window is shown.
pub fn ready(app: &AppHandle, label: &str) {
    let Some(label) = known(label) else { return };
    let (ready, phase) = {
        let mut slots = slots(app);
        let ready = slots.ready(label, Instant::now());
        for (event, payload) in &ready.events {
            let _ = app.emit_to(label, event, payload);
        }
        (ready, slots.phase(label))
    };
    if let Some(latency) = ready.latency {
        eprintln!("[echoledger] {label} window ready {} ms after it was asked for", latency.as_millis());
    }
    if label == MAIN && phase == Phase::Ready {
        reveal_main(app);
    }
    if let Some(generation) = ready.expire {
        schedule_expiry(app, label, generation);
    }
}

/// The use that keeps the dictation box built while the app idles. Built cold it takes about a second (WebView2 has
/// to start first, measured in docs/HANDOFF.md "Phase 6 results"), too slow for a shortcut that must answer at once;
/// kept built (hidden and click-through) it shows with one event. It is the only window kept while idle.
pub const WARM: &str = "warm";

/// Keeps the dictation box built while a dictation shortcut is bound, and lets it go when none is.
pub fn keep_dictation_warm(app: &AppHandle, bound: bool) {
    if bound { show(app, DICTATION, WARM) } else { hide(app, DICTATION, WARM) }
}

/// Opens the main window: shown at once when it exists, else built (and shown when its page is ready).
pub fn show_main(app: &AppHandle) {
    let (build, phase) = {
        let mut slots = slots(app);
        (slots.show(MAIN, "open", Instant::now()), slots.phase(MAIN))
    };
    if build {
        spawn_build(app, MAIN);
    } else if phase == Phase::Ready {
        reveal_main(app);
    }
}

fn reveal_main(app: &AppHandle) {
    if let Some(window) = app.get_webview_window(MAIN) {
        let _ = window.show();
        let _ = window.unminimize();
        let _ = window.set_focus();
        let _ = window.emit("main:visibility", true);
    }
}

/// The user closes the main window: it is destroyed (not hidden), so no web view stays alive in the tray.
pub fn main_closing(app: &AppHandle) {
    slots(app).closing(MAIN);
}

/// `WindowEvent::Destroyed`: forgets the window's hit regions and caption channel; builds it again when it was asked
/// for while closing.
pub fn on_destroyed(app: &AppHandle, label: &str) {
    let Some(label) = known(label) else { return };
    if let Ok(mut regions) = app.state::<overlay::HitRegions>().0.lock() {
        regions.remove(label);
    }
    app.state::<crate::stt::captions::CaptionHub>().unsubscribe(label);
    if slots(app).destroyed(label, Instant::now()) {
        spawn_build(app, label);
    }
}

fn schedule_expiry(app: &AppHandle, label: &'static str, generation: u64) {
    if label == MAIN {
        return; // destroyed when the user closes it
    }
    let app = app.clone();
    tauri::async_runtime::spawn(async move {
        tokio::time::sleep(HIDE_GRACE).await;
        if !slots(&app).expire(label, generation) {
            return;
        }
        match app.get_webview_window(label) {
            Some(window) => {
                let _ = window.destroy(); // `WindowEvent::Destroyed` follows (on_destroyed)
            }
            None => on_destroyed(&app, label),
        }
    });
}

fn spawn_build(app: &AppHandle, label: &'static str) {
    let app = app.clone();
    tauri::async_runtime::spawn(async move {
        let built = if label == MAIN {
            build_main(&app)
        } else {
            let setup = overlay::OverlaySetup::from_settings(&app.state::<crate::AppState>().settings());
            overlay::create_overlay(&app, label, setup)
        };
        if let Err(e) = built {
            eprintln!("[echoledger] could not open the {label} window: {e}");
            slots(&app).destroyed(label, Instant::now()); // absent again: the next show tries again (no retry loop)
        }
    });
}

/// The main window from tauri.conf.json (`"create": false`, hidden until its page is ready).
fn build_main(app: &AppHandle) -> tauri::Result<()> {
    let config = app.config().app.windows.iter().find(|w| w.label == MAIN).cloned().unwrap_or_else(|| WindowConfig { label: MAIN.into(), ..WindowConfig::default() });
    let window = WebviewWindowBuilder::from_config(app, &config)?.build()?;
    crate::material::apply(&window);
    let app = app.clone();
    tauri::async_runtime::spawn(async move {
        tokio::time::sleep(MAIN_SHOW_FALLBACK).await;
        let shown = app.get_webview_window(MAIN).and_then(|w| w.is_visible().ok());
        if shown == Some(false) && slots(&app).in_use(MAIN) {
            reveal_main(&app);
        }
    });
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn t0() -> Instant {
        Instant::now()
    }

    #[test]
    fn a_window_is_built_on_first_use_and_never_twice() {
        let mut s = Slots::default();
        let now = t0();
        assert_eq!(s.phase(PILL), Phase::Absent);
        assert!(s.show(PILL, "pill", now), "first use builds it");
        assert!(!s.show(PILL, "pill", now), "no double create while it is being built");
        s.ready(PILL, now);
        assert!(!s.show(PILL, "pill", now), "no double create once it is ready");
        assert_eq!(s.phase(PILL), Phase::Ready);
    }

    #[test]
    fn a_hidden_overlay_is_destroyed_only_by_the_timer_of_its_last_hide() {
        let mut s = Slots::default();
        let now = t0();
        s.show(OVERLAY, OVERLAY, now);
        s.ready(OVERLAY, now);
        let first = s.hide(OVERLAY, OVERLAY).expect("unused: schedule a check");
        s.show(OVERLAY, OVERLAY, now); // shown again within the grace
        assert!(!s.expire(OVERLAY, first), "in use again: kept");
        let second = s.hide(OVERLAY, OVERLAY).unwrap();
        assert!(!s.expire(OVERLAY, first), "an older timer never destroys it");
        assert!(s.expire(OVERLAY, second), "the grace after the last hide is over");
        assert_eq!(s.phase(OVERLAY), Phase::Closing);
        assert!(!s.destroyed(OVERLAY, now));
        assert_eq!(s.phase(OVERLAY), Phase::Absent);
        assert!(s.show(OVERLAY, OVERLAY, now), "built again on the next use");
    }

    #[test]
    fn a_window_shared_by_two_uses_stays_while_either_needs_it() {
        let mut s = Slots::default();
        let now = t0();
        s.show(REVIEW, "review", now);
        s.show(REVIEW, "ask", now);
        s.ready(REVIEW, now);
        assert_eq!(s.hide(REVIEW, "ask"), None, "the review card still needs the window");
        assert!(s.in_use(REVIEW));
        assert!(s.hide(REVIEW, "review").is_some());
        assert!(!s.in_use(REVIEW));
    }

    #[test]
    fn events_wait_for_the_page_and_keep_only_the_latest_of_each_kind() {
        let mut s = Slots::default();
        let now = t0();
        assert_eq!(s.event(PILL, "pill:visibility", json!(true)), Delivery::Dropped, "no window: the page reads the state when it starts");
        s.show(PILL, "pill", now);
        assert_eq!(s.event(PILL, "pill:visibility", json!(true)), Delivery::Kept);
        assert_eq!(s.event(REVIEW, "review:show", json!(1)), Delivery::Dropped);
        s.show(REVIEW, "review", now);
        assert_eq!(s.event(REVIEW, "review:show", json!(1)), Delivery::Kept);
        assert_eq!(s.event(REVIEW, "review:hide", Value::Null), Delivery::Kept);
        assert_eq!(s.event(REVIEW, "review:show", json!(2)), Delivery::Kept);
        let ready = s.ready(REVIEW, now);
        assert_eq!(ready.events, vec![("review:hide".to_string(), Value::Null), ("review:show".to_string(), json!(2))], "the last state wins");
        assert_eq!(ready.expire, None, "still in use");
        assert_eq!(s.event(REVIEW, "review:hide", Value::Null), Delivery::Now, "a ready page gets events at once");
        assert!(s.ready(REVIEW, now).events.is_empty(), "nothing is sent twice");
        assert_eq!(s.ready(PILL, now).events, vec![("pill:visibility".to_string(), json!(true))]);
    }

    #[test]
    fn the_trigger_to_ready_time_is_measured_once() {
        let mut s = Slots::default();
        let asked = t0();
        s.show(DICTATION, DICTATION, asked);
        let ready = s.ready(DICTATION, asked + Duration::from_millis(180));
        assert_eq!(ready.latency, Some(Duration::from_millis(180)));
        assert_eq!(s.ready(DICTATION, asked + Duration::from_secs(5)).latency, None, "a page reload is not a new open");
    }

    #[test]
    fn a_window_hidden_before_its_page_was_ready_is_checked_again_when_it_is() {
        let mut s = Slots::default();
        let now = t0();
        s.show(DICTATION, DICTATION, now);
        let early = s.hide(DICTATION, DICTATION).unwrap();
        assert!(!s.expire(DICTATION, early), "never destroyed while being built");
        let ready = s.ready(DICTATION, now);
        let again = ready.expire.expect("nothing needs it: a new check");
        assert!(s.expire(DICTATION, again));
    }

    #[test]
    fn a_use_while_closing_rebuilds_the_window_once_it_is_gone_and_keeps_its_events() {
        let mut s = Slots::default();
        let now = t0();
        s.show(PILL, "pill", now);
        s.ready(PILL, now);
        let generation = s.hide(PILL, "pill").unwrap();
        assert!(s.expire(PILL, generation));
        assert_eq!(s.event(PILL, "pill:visibility", json!(false)), Delivery::Dropped, "closing and unused");
        assert!(!s.show(PILL, "pill", now), "never two windows with one label: wait for the old one to go");
        assert_eq!(s.event(PILL, "pill:visibility", json!(true)), Delivery::Kept);
        assert!(s.destroyed(PILL, now), "gone: build the new one now");
        assert_eq!(s.phase(PILL), Phase::Creating);
        assert_eq!(s.ready(PILL, now).events, vec![("pill:visibility".to_string(), json!(true))]);
    }

    #[test]
    fn closing_the_main_window_forgets_it_and_the_next_open_builds_a_new_one() {
        let mut s = Slots::default();
        let now = t0();
        assert!(s.show(MAIN, "open", now));
        s.event(MAIN, "main:navigate", json!({"view": "settings"}));
        let ready = s.ready(MAIN, now);
        assert_eq!(ready.events.len(), 1, "a navigation sent while it was built is not lost");
        assert_eq!(ready.expire, None);
        s.closing(MAIN);
        assert_eq!(s.event(MAIN, "main:navigate", json!({})), Delivery::Dropped);
        assert!(!s.destroyed(MAIN, now));
        assert!(s.show(MAIN, "open", now));
    }

    #[test]
    fn a_window_that_failed_to_build_is_absent_again() {
        let mut s = Slots::default();
        let now = t0();
        s.show(OVERLAY, OVERLAY, now);
        s.event(OVERLAY, "overlay:visibility", json!(true));
        s.destroyed(OVERLAY, now);
        assert_eq!(s.phase(OVERLAY), Phase::Absent);
        assert!(!s.in_use(OVERLAY));
        assert!(s.ready(OVERLAY, now).events.is_empty(), "a stray page gets nothing kept for the failed one");
        assert!(s.show(OVERLAY, OVERLAY, now));
    }

    #[test]
    fn the_warm_dictation_box_stays_built_between_dictations() {
        let mut s = Slots::default();
        let now = t0();
        assert!(s.show(DICTATION, WARM, now), "built while the app idles");
        assert_eq!(s.ready(DICTATION, now).expire, None, "never destroyed while warm");
        assert!(!s.show(DICTATION, DICTATION, now), "a dictation shows the built box: nothing to build");
        assert_eq!(s.event(DICTATION, "overlay:visibility", json!(true)), Delivery::Now, "shown with one event");
        assert_eq!(s.hide(DICTATION, DICTATION), None, "hidden after the dictation and still built");
        let generation = s.hide(DICTATION, WARM).expect("no dictation shortcut any more: let it go");
        assert!(s.expire(DICTATION, generation));
    }

    #[test]
    fn main_view_state_is_kept_in_memory_and_capped() {
        let lifecycle = WindowLifecycle::default();
        assert_eq!(lifecycle.main_view(), None);
        assert!(lifecycle.set_main_view(r#"{"tab":"settings"}"#.into()));
        assert_eq!(lifecycle.main_view().as_deref(), Some(r#"{"tab":"settings"}"#));
        assert!(!lifecycle.set_main_view("x".repeat(MAIN_VIEW_MAX_BYTES + 1)));
        assert_eq!(lifecycle.main_view().as_deref(), Some(r#"{"tab":"settings"}"#), "a refused state keeps the last one");
    }

    #[test]
    fn every_overlay_is_built_on_demand() {
        for label in overlay::WINDOWS {
            assert!(LABELS.contains(&label));
        }
        assert!(LABELS.contains(&MAIN));
        assert!(HIDE_GRACE >= Duration::from_secs(30));
    }
}
