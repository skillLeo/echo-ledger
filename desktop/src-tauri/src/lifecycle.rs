//! On-demand windows (C9): an idle app runs one web view at most. The main window is built when it is opened and
//! destroyed when it is closed; each overlay is built the first time it is needed and destroyed once it has been
//! hidden for `HIDE_GRACE`, except the dictation box, which stays built while its shortcut is bound (`WARM`).
//! Recording, detection, shortcuts and uploads live in the core and need no window.
//!
//! Idle budget (what still runs with no window): on a Mac with several displays, spaces.rs watches which display the
//! pointer is on (the Dock icon steps aside only for another app's full-screen Space on the display in use). That is a
//! mouse-moved monitor whose handler compares cached display frames at most once per 200 ms and reads the window
//! server's Space list only when the display changed; with one display nothing is watched.
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
pub const LABELS: [&str; 6] = [MAIN, OVERLAY, DICTATION, PILL, REVIEW, overlay::ASSIST];

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
    /// Why the window is on screen (the after-call toast or chip and the "ask" chip share one window).
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
    /// The main window being built was asked for quietly (`show_main_quietly`) and by nobody else since: when its page
    /// is ready it is revealed only if nothing keeps it off, whatever the person opened before.
    main_quiet: std::sync::atomic::AtomicBool,
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

/// Where these windows stand now (the smoke test waits on them).
pub fn phases<'a>(app: &AppHandle, labels: &[&'a str]) -> Vec<(&'a str, Phase)> {
    let slots = slots(app);
    labels.iter().map(|label| (*label, slots.phase(label))).collect()
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
        reveal_main(app, quiet_open(app));
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

/// Opens the main window for the person: shown at once when it exists, else built (and shown when its page is ready).
/// Every open they ask for comes here (the tray's Open, the app opened again from the Dock, Finder, Launchpad or
/// Spotlight, a second launch, a clicked notification, a tray or toast action), so here "Hide EchoLedger windows" ends,
/// and during a whole-screen share the main window is shown anyway while the floating windows stay off
/// (`privacy::opened_by_person`).
pub fn show_main(app: &AppHandle) {
    crate::privacy::opened_by_person(app);
    open_main(app, false);
}

/// Opens the main window for something the person did not ask to see it for (a refused start that could not be said in
/// a notification): while a reason keeps the main window off it stays off (even when the person opened it earlier in
/// the share), and what it was opened for waits in its page.
pub fn show_main_quietly(app: &AppHandle) {
    open_main(app, true);
}

fn quiet_open(app: &AppHandle) -> bool {
    app.state::<WindowLifecycle>().main_quiet.load(std::sync::atomic::Ordering::SeqCst)
}

/// Whether the main window built now is revealed as a quiet open (pure): a quiet open that builds it is quiet; any open
/// by the person since makes it theirs; a quiet open of a window already being built leaves it as it was.
pub fn quiet_after(was_quiet: bool, builds: bool, quiet: bool) -> bool {
    if !quiet { false } else if builds { true } else { was_quiet }
}

fn open_main(app: &AppHandle, quiet: bool) {
    let (build, phase) = {
        let mut slots = slots(app);
        (slots.show(MAIN, "open", Instant::now()), slots.phase(MAIN))
    };
    let flag = &app.state::<WindowLifecycle>().main_quiet;
    flag.store(quiet_after(flag.load(std::sync::atomic::Ordering::SeqCst), build, quiet), std::sync::atomic::Ordering::SeqCst);
    if build {
        spawn_build(app, MAIN);
    } else if phase == Phase::Ready {
        reveal_main(app, quiet);
    }
}

/// Puts the main window on screen and in front: after an open by the person, when a freshly built window's page has
/// drawn, or by the fallback. While windows are kept off (a whole-screen share the person did not open it during, "Hide
/// EchoLedger windows") a reveal brings nothing back, and a quiet one never does while a reason keeps it off.
fn reveal_main(app: &AppHandle, quiet: bool) {
    if !crate::privacy::may_show_main(app, quiet) {
        return;
    }
    if let Some(window) = app.get_webview_window(MAIN) {
        let _ = window.show();
        let _ = window.unminimize();
        let _ = window.set_focus();
        let _ = window.emit("main:visibility", true);
    }
}

/// What opening EchoLedger from outside does while it runs (`actions::show_main_from_outside`): a second launch on
/// Windows (handed over by the single-instance plugin), the app opened again from Finder, Launchpad, Spotlight or the
/// Dock on a Mac (no second copy starts: macOS sends the running copy a reopen), or a clicked notification. The main
/// window always comes to the front (built when there is none, else shown, unminimised and focused).
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Reopen {
    /// No main window yet and a notification about a recording went out in the last ten minutes: it opens on that
    /// recording.
    OpenNotified,
    /// Shown where the person was: an existing window (on screen, minimised or put away) keeps its page.
    Show,
}

/// The answer to an open from outside (pure, the same on Windows and the Mac). AppKit's `has_visible_windows` is not
/// asked: it counts the overlays too (the dictation box is on screen, drawing nothing, while its shortcut is bound, as
/// it is by default), so it says nothing about the main window.
pub fn reopen_action(main_exists: bool, notified_lately: bool) -> Reopen {
    if !main_exists && notified_lately { Reopen::OpenNotified } else { Reopen::Show }
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
    // On a blocking thread, never an async worker: a build waits for the main thread to make its webview (many seconds on
    // a cold WebView2), and builds on the workers (6 at start in the smoke test, on 4 workers) left the async runtime
    // nothing to run anything else with until the main thread was done (the Server 2022 stall, run 44059717761).
    tauri::async_runtime::spawn_blocking(move || {
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

/// Run in the main window on a Mac before its page (D-214): `<html data-platform="macos" data-titlebar="strip">` from the
/// first frame, unless the same window's page said it was full screen before a reload (desktop/src/lib/titlebar.ts).
pub const TITLEBAR_START: &str = include_str!("../../src/lib/titlebarStart.js");

/// The main window from tauri.conf.json (`"create": false`, hidden until its page is ready).
fn build_main(app: &AppHandle) -> tauri::Result<()> {
    let config = app.config().app.windows.iter().find(|w| w.label == MAIN).cloned().unwrap_or_else(|| WindowConfig { label: MAIN.into(), ..WindowConfig::default() });
    // Its capture flag comes from "Hide EchoLedger from screen sharing" (`privacy`), set before it first shows (once
    // its page is ready); while screen moments capture a whole screen (Windows) it is hidden from captures whatever the
    // setting says (`screens::hide_own_windows`).
    let shielded = app.state::<crate::AppState>().screens.own_hidden();
    let builder = crate::smoke::isolate(WebviewWindowBuilder::from_config(app, &config)?);
    // On a Mac the page's first frame already leaves the window's buttons their strip (D-214, TITLEBAR_START).
    let builder = if cfg!(target_os = "macos") { builder.initialization_script(TITLEBAR_START) } else { builder };
    let window = crate::privacy::window(app, builder, MAIN, crate::privacy::Kind::Main, |b| if shielded { b.content_protected(true) } else { b })?;
    crate::material::apply(&window);
    let app = app.clone();
    tauri::async_runtime::spawn(async move {
        tokio::time::sleep(MAIN_SHOW_FALLBACK).await;
        let shown = app.get_webview_window(MAIN).and_then(|w| w.is_visible().ok());
        if shown == Some(false) && slots(&app).in_use(MAIN) {
            reveal_main(&app, quiet_open(&app));
        }
    });
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_mac_main_window_draws_its_title_bar_strip_from_the_first_frame() {
        // D-214: the strip is there before the page's own scripts ask whether the window is full screen.
        assert!(TITLEBAR_START.contains(r#"root.dataset.platform = "macos""#));
        assert!(TITLEBAR_START.contains(r#"root.dataset.titlebar = "strip""#));
        assert!(TITLEBAR_START.contains(r#"sessionStorage.getItem("echoledger-titlebar")"#), "a reload in full screen keeps its answer");
        let source = include_str!("lifecycle.rs");
        let build = &source[source.find("fn build_main").unwrap()..source.find("fn build_main").unwrap() + 1200];
        assert!(build.contains("if cfg!(target_os = \"macos\") { builder.initialization_script(TITLEBAR_START) }"), "main window only, Mac only");
        let built = build.find("initialization_script").unwrap();
        assert!(built < build.find("privacy::window(").unwrap(), "on the builder, before the window is made");
    }

    /// The app's calls made outside a `spawn_blocking(…)`'s arguments, by name (the last part of the path), per function
    /// (`file::name`), and those its async code makes (`async fn` bodies and `async` blocks).
    #[derive(Default)]
    struct Calls {
        /// `file::function` → the names it calls.
        by_fn: std::collections::BTreeMap<String, std::collections::BTreeSet<String>>,
        /// (`file::function`, name) of every call made by async code.
        from_async: Vec<(String, String)>,
        file: String,
        current: Vec<String>,
        in_async: bool,
        in_blocking: usize,
    }

    impl Calls {
        fn function(&mut self, name: String, is_async: bool, visit: impl FnOnce(&mut Self)) {
            let key = format!("{}::{name}", self.file);
            self.by_fn.entry(key.clone()).or_default();
            self.current.push(key);
            // A sync function's body runs where it is called; an async one's is async code.
            let saved = (self.in_async, self.in_blocking);
            (self.in_async, self.in_blocking) = (is_async, 0);
            visit(self);
            (self.in_async, self.in_blocking) = saved;
            self.current.pop();
        }

        /// The function a call by `name` from `file` reaches: the one of that name in the same file, or else the only
        /// one of the app (`run`, `create` and `build` name several and are not followed).
        fn resolve(&self, file: &str, name: &str) -> Option<String> {
            let here = format!("{file}::{name}");
            if self.by_fn.contains_key(&here) {
                return Some(here);
            }
            let suffix = format!("::{name}");
            let mut all = self.by_fn.keys().filter(|k| k.ends_with(&suffix));
            match (all.next(), all.next()) {
                (Some(only), None) => Some(only.clone()),
                _ => None,
            }
        }

        fn file_of(key: &str) -> &str {
            key.rsplit_once("::").map_or(key, |(file, _)| file)
        }

        /// Whether a call by `name` from `key`'s file builds a window on the calling thread.
        fn builds(&self, builders: &std::collections::BTreeSet<String>, key: &str, name: &str) -> bool {
            name == "window!" || self.resolve(Self::file_of(key), name).is_some_and(|f| builders.contains(&f))
        }

        /// The app's functions that build a window on the thread that calls them: those that call `privacy::window` /
        /// `super::window`, or one of these, outside spawn_blocking.
        fn builders(&self) -> std::collections::BTreeSet<String> {
            let mut found = std::collections::BTreeSet::new();
            loop {
                let more: Vec<String> = self.by_fn.iter().filter(|(f, called)| !found.contains(*f) && called.iter().any(|c| self.builds(&found, f, c))).map(|(f, _)| f.clone()).collect();
                if more.is_empty() {
                    return found;
                }
                found.extend(more);
            }
        }
    }

    impl<'ast> syn::visit::Visit<'ast> for Calls {
        fn visit_item_mod(&mut self, m: &'ast syn::ItemMod) {
            // The app's own code: test modules are left out.
            let test = m.attrs.iter().any(|a| a.path().is_ident("cfg") && a.parse_args::<syn::Ident>().is_ok_and(|i| i == "test"));
            if !test {
                syn::visit::visit_item_mod(self, m);
            }
        }
        fn visit_item_fn(&mut self, f: &'ast syn::ItemFn) {
            self.function(f.sig.ident.to_string(), f.sig.asyncness.is_some(), |v| syn::visit::visit_item_fn(v, f));
        }
        fn visit_impl_item_fn(&mut self, f: &'ast syn::ImplItemFn) {
            self.function(f.sig.ident.to_string(), f.sig.asyncness.is_some(), |v| syn::visit::visit_impl_item_fn(v, f));
        }
        fn visit_expr_closure(&mut self, c: &'ast syn::ExprClosure) {
            // A closure is read as part of its function, but as sync code: the callbacks async code hands on (menu,
            // shortcut and main-thread closures) run elsewhere.
            let saved = std::mem::take(&mut self.in_async);
            syn::visit::visit_expr_closure(self, c);
            self.in_async = saved;
        }
        fn visit_expr_async(&mut self, e: &'ast syn::ExprAsync) {
            let saved = std::mem::replace(&mut self.in_async, true);
            syn::visit::visit_expr_async(self, e);
            self.in_async = saved;
        }
        fn visit_expr_call(&mut self, call: &'ast syn::ExprCall) {
            if let syn::Expr::Path(path) = &*call.func {
                let segments: Vec<String> = path.path.segments.iter().map(|s| s.ident.to_string()).collect();
                let last = segments.last().cloned().unwrap_or_default();
                if last == "spawn_blocking" {
                    self.in_blocking += 1;
                    syn::visit::visit_expr_call(self, call);
                    self.in_blocking -= 1;
                    return;
                }
                if let (0, Some(f)) = (self.in_blocking, self.current.last().cloned()) {
                    // privacy::window / super::window build the window; no other `window` call is one.
                    let qualifier = segments.len().checked_sub(2).map(|i| segments[i].as_str());
                    let name = if last == "window" && matches!(qualifier, Some("privacy" | "super")) { "window!".to_string() } else { last };
                    if self.in_async {
                        self.from_async.push((f.clone(), name.clone()));
                    }
                    self.by_fn.entry(f).or_default().insert(name);
                }
            }
            syn::visit::visit_expr_call(self, call);
        }
    }

    fn app_calls() -> Calls {
        fn walk(root: &std::path::Path, dir: &std::path::Path, calls: &mut Calls) {
            for entry in std::fs::read_dir(dir).unwrap().flatten() {
                let path = entry.path();
                if path.is_dir() {
                    walk(root, &path, calls);
                } else if path.extension().is_some_and(|e| e == "rs") && path.file_name().is_some_and(|n| n != "tests.rs") {
                    let file = syn::parse_file(&std::fs::read_to_string(&path).unwrap()).unwrap();
                    calls.file = path.strip_prefix(root).unwrap().to_string_lossy().into_owned();
                    syn::visit::Visit::visit_file(calls, &file);
                }
            }
        }
        let root = std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("src");
        let mut calls = Calls::default();
        walk(&root, &root, &mut calls);
        calls
    }

    #[test]
    fn no_async_code_reaches_a_window_build_but_through_spawn_blocking() {
        // A build waits for the main thread to make its webview: on an async worker it holds the worker for as long, and
        // a few at once starve the runtime (the Server 2022 stall). Async code reaches a build only through spawn_blocking.
        //
        // What the check cannot see:
        // - method calls (`x.build()`, `Self::f` through a value): only path calls (`f(…)`, `a::b::f(…)`) are followed,
        //   so a window build reached through a method is not;
        // - calls through a function value, a trait object or a macro;
        // - a closure that async code runs itself: every closure is read as sync code (the callbacks it hands on run
        //   elsewhere);
        // - a name the app gives several functions (`run`, `create`, `build`), followed only within its own file.
        let calls = app_calls();
        let builders = calls.builders();
        let found: Vec<String> = calls.from_async.iter().filter(|(f, name)| calls.builds(&builders, f, name)).map(|(f, name)| format!("{f}: {name}(…) outside spawn_blocking")).collect();
        assert!(found.is_empty(), "{}", found.join("\n"));
        for known in ["overlay/mod.rs::build_window", "overlay/mod.rs::create_overlay", "overlay/mod.rs::create_translate_window", "lifecycle.rs::build_main", "privacy/check.rs::build_patch"] {
            assert!(builders.contains(known), "{known} builds a window ({builders:?})");
        }
        for hands_on in ["lifecycle.rs::spawn_build", "privacy/check.rs::build_patch_blocking", "commands.rs::translate_start"] {
            assert!(calls.by_fn.contains_key(hands_on) && !builders.contains(hands_on), "{hands_on} hands its build to spawn_blocking");
        }
    }

    #[test]
    fn windows_are_built_on_blocking_threads_never_on_the_async_workers() {
        // A build waits for the main thread to make its webview; on the async workers six of them starved the runtime
        // (the smoke test's stall on Server 2022). The window builds go to the blocking pool.
        let lifecycle = include_str!("lifecycle.rs");
        let build = &lifecycle[lifecycle.find("fn spawn_build(").unwrap()..];
        let build = &build[..build.find("\n}\n").unwrap()];
        assert!(build.contains("tauri::async_runtime::spawn_blocking(move ||"), "{build}");
        assert!(!build.contains("async_runtime::spawn(async"), "{build}");
        let commands = include_str!("commands.rs");
        let start = &commands[commands.find("pub async fn translate_start").unwrap()..];
        let start = &start[..start.find("\n}\n").unwrap()];
        let blocking = start.find("spawn_blocking(move || crate::overlay::create_translate_window(").expect("Live Translate's window too");
        assert!(!start[..blocking].contains("create_translate_window(&app"), "never built on the async worker itself");
    }
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
        s.show(REVIEW, "after_call", now);
        s.show(REVIEW, "ask", now);
        s.ready(REVIEW, now);
        assert_eq!(s.hide(REVIEW, "ask"), None, "the after-call toast still needs the window");
        assert!(s.in_use(REVIEW));
        assert!(s.hide(REVIEW, "after_call").is_some());
        assert!(!s.in_use(REVIEW));
    }

    #[test]
    fn events_wait_for_the_page_and_keep_only_the_latest_of_each_kind() {
        let mut s = Slots::default();
        let now = t0();
        assert_eq!(s.event(PILL, "pill:visibility", json!(true)), Delivery::Dropped, "no window: the page reads the state when it starts");
        s.show(PILL, "pill", now);
        assert_eq!(s.event(PILL, "pill:visibility", json!(true)), Delivery::Kept);
        assert_eq!(s.event(REVIEW, "aftercall:state", json!(1)), Delivery::Dropped);
        s.show(REVIEW, "after_call", now);
        assert_eq!(s.event(REVIEW, "aftercall:state", json!(1)), Delivery::Kept);
        assert_eq!(s.event(REVIEW, "ask:hide", Value::Null), Delivery::Kept);
        assert_eq!(s.event(REVIEW, "aftercall:state", json!(2)), Delivery::Kept);
        let ready = s.ready(REVIEW, now);
        assert_eq!(ready.events, vec![("ask:hide".to_string(), Value::Null), ("aftercall:state".to_string(), json!(2))], "the last state wins");
        assert_eq!(ready.expire, None, "still in use");
        assert_eq!(s.event(REVIEW, "ask:hide", Value::Null), Delivery::Now, "a ready page gets events at once");
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
    fn only_opens_the_person_asked_for_end_a_hide() {
        // A refused start that could not be said in a notification opens the main window, but nobody asked to see it:
        // it never brings EchoLedger back while windows are kept off (a whole-screen share, Hide EchoLedger windows).
        let refusal = include_str!("refusal.rs");
        assert!(refusal.contains("crate::lifecycle::show_main_quietly(app);") && !refusal.contains("tray::show_main("));
        let lifecycle = include_str!("lifecycle.rs");
        let quiet = &lifecycle[lifecycle.find("pub fn show_main_quietly(").unwrap()..];
        assert!(!quiet[..quiet.find("\n}\n").unwrap()].contains("opened_by_person"));
        assert!(quiet[..quiet.find("\n}\n").unwrap()].contains("open_main(app, true)"), "it reveals as a quiet open");
    }

    #[test]
    fn a_main_window_built_for_a_quiet_open_stays_quiet_until_the_person_opens_it() {
        assert!(quiet_after(false, true, true), "a quiet open that builds the window: revealed only if nothing keeps it off");
        assert!(!quiet_after(true, false, false), "the person opens it meanwhile: it is theirs");
        assert!(!quiet_after(false, false, true), "a quiet open while the person's build runs leaves it theirs");
        assert!(quiet_after(true, false, true));
        assert!(!quiet_after(true, true, false), "the person's open builds it");
        // Every reveal says which it is: the page drawing and the fallback use what the open was.
        let code = include_str!("lifecycle.rs").split("#[cfg(test)]").next().unwrap();
        assert_eq!(code.matches("reveal_main(app, quiet_open(app))").count() + code.matches("reveal_main(&app, quiet_open(&app))").count(), 2);
    }

    #[test]
    fn opening_the_app_again_jumps_to_a_notified_recording_only_without_a_main_window() {
        assert_eq!(reopen_action(false, true), Reopen::OpenNotified, "closed to the tray or menu bar: built on the recording");
        assert_eq!(reopen_action(false, false), Reopen::Show, "nothing notified lately: built where the person was");
        assert_eq!(reopen_action(true, true), Reopen::Show, "an existing window, on screen, minimised or put away, keeps its page");
        assert_eq!(reopen_action(true, false), Reopen::Show);
    }

    #[test]
    fn opening_the_app_again_never_builds_a_second_main_window() {
        let mut s = Slots::default();
        let now = t0();
        assert!(s.show(MAIN, "open", now), "closed to the menu bar: built");
        assert!(!s.show(MAIN, "open", now), "opened again while it is being built: the same window, shown when its page is ready");
        s.ready(MAIN, now);
        assert!(!s.show(MAIN, "open", now), "opened again once it is up: shown and focused, not built");
        s.closing(MAIN);
        assert!(!s.show(MAIN, "open", now), "opened again while it closes: never a second one beside it");
        assert!(s.destroyed(MAIN, now), "built again once the old one is gone");
        assert_eq!(s.phase(MAIN), Phase::Creating);
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
