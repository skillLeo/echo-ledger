//! Share Check, "See what others see": a protected test window (a magenta check pattern, built like the overlays) and
//! an unprotected green control window open side by side on the main screen for about a second, the screen is captured
//! the way meeting apps capture it ([`super::capture`]), and the colours are counted ([`super::pixels`]). The captured
//! picture goes to the page that asked, scaled down, and is never kept or uploaded. What is kept on this computer
//! (`share-check.json`) is each run's result per system version and app version and each guided test's answer; only
//! {platform, OS build, app version, method, result, reason} go to the server, and only with "Send diagnostics" on.
//!
//! The check runs from Settings, the badge and onboarding, and by itself after an app update, a system update and once
//! a week ([`due`]); Live Assist will call [`before_call`] two minutes before a scheduled call.

use std::path::{Path, PathBuf};
use std::sync::atomic::{AtomicBool, Ordering};
use std::time::Duration;

use base64::Engine;
use chrono::{DateTime, Utc};
use serde::{Deserialize, Serialize};
use tauri::{AppHandle, Emitter, Manager, WebviewUrl, WebviewWindowBuilder};

use super::apps::{self, Guided, MeetingApp};
use super::capture::{self, Failure};
use super::pixels::{self, Outcome, Rect};
use super::status::{self, Badge, Facts, Platform};
use super::{CHECK_CONTROL, CHECK_TEST, GUIDED_CARD, Kind, Privacy, diag};
use crate::error::{AppError, AppResult};
use crate::net::models::ShareCheckReport;

/// Why a check ran.
#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum Why {
    Asked,
    Onboarding,
    First,
    AppUpdate,
    SystemUpdate,
    Weekly,
    BeforeCall,
}

/// One capture method's result in a run.
#[derive(Clone, Debug, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct MethodResult {
    pub method: String,
    pub result: Outcome,
    /// `permission_missing` | `capture_failed` | `control_missing`.
    pub reason: Option<String>,
    /// What the capture showed: test pixels, control pixels and the control's area (none when nothing was captured).
    #[serde(default)]
    pub counts: Option<pixels::Counts>,
    /// Why the capture failed, in the system's words (for the diagnostics; never a picture or a title).
    #[serde(default)]
    pub detail: Option<String>,
}

/// One run, as kept.
#[derive(Clone, Debug, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct Record {
    pub at: DateTime<Utc>,
    pub os_build: String,
    pub app_version: String,
    pub result: Outcome,
    pub reason: Option<String>,
    pub methods: Vec<MethodResult>,
    pub why: Why,
}

#[derive(Clone, Debug, Default, PartialEq, Eq, Serialize, Deserialize)]
#[serde(default, rename_all = "camelCase")]
pub struct Store {
    /// The last runs, oldest first.
    pub checks: Vec<Record>,
    pub guided: Vec<Guided>,
}

const KEEP_CHECKS: usize = 30;
/// A week: the automatic check runs again after this long.
pub const WEEKLY: chrono::TimeDelta = chrono::TimeDelta::days(7);

pub fn store_path(data_dir: &Path) -> PathBuf {
    data_dir.join("share-check.json")
}

impl Store {
    pub fn load(data_dir: &Path) -> Self {
        std::fs::read(store_path(data_dir)).ok().and_then(|b| serde_json::from_slice(&b).ok()).unwrap_or_default()
    }

    pub fn save(&self, data_dir: &Path) {
        if let Ok(bytes) = serde_json::to_vec_pretty(self) {
            let _ = std::fs::write(store_path(data_dir), bytes);
        }
    }

    pub fn add(&mut self, record: Record) {
        self.checks.push(record);
        if self.checks.len() > KEEP_CHECKS {
            let extra = self.checks.len() - KEEP_CHECKS;
            self.checks.drain(..extra);
        }
    }

    /// The last run on this system version and app version that measured something (an Error measured nothing, but
    /// it is still the last word when nothing else is there).
    pub fn last_for(&self, os_build: &str, app_version: &str) -> Option<&Record> {
        let here = |r: &&Record| r.os_build == os_build && r.app_version == app_version;
        self.checks.iter().rev().filter(here).find(|r| r.result != Outcome::Error).or_else(|| self.checks.iter().rev().find(here))
    }
}

/// Whether the automatic check is due, and why (pure): never run, the app or the system changed since the last run,
/// or a week went by.
pub fn due(store: &Store, os_build: &str, app_version: &str, now: DateTime<Utc>) -> Option<Why> {
    let Some(last) = store.checks.last() else { return Some(Why::First) };
    if last.app_version != app_version {
        Some(Why::AppUpdate)
    } else if last.os_build != os_build {
        Some(Why::SystemUpdate)
    } else if now - last.at >= WEEKLY {
        Some(Why::Weekly)
    } else {
        None
    }
}

/// This system's version as the server gets it: "27.3 (26C5035r)" on a Mac, "10.0.26100" on Windows.
pub fn os_build() -> String {
    #[cfg(target_os = "macos")]
    {
        let build = crate::mac_check::native::sysctl_text(c"kern.osversion");
        let version = crate::mac_check::native::version_string();
        if build.is_empty() { version } else { format!("{version} ({build})") }
    }
    #[cfg(windows)]
    {
        crate::audio::platform::windows::os_build().map_or_else(|| "10.0".to_string(), |b| format!("10.0.{b}"))
    }
    #[cfg(not(any(windows, target_os = "macos")))]
    {
        std::env::consts::OS.to_string()
    }
}

/// macOS's version ("27.3"), the key of guided results; the OS build elsewhere.
pub fn os_version() -> String {
    #[cfg(target_os = "macos")]
    {
        crate::mac_check::native::version_string()
    }
    #[cfg(not(target_os = "macos"))]
    {
        os_build()
    }
}

pub fn app_version() -> &'static str {
    env!("CARGO_PKG_VERSION")
}

fn platform_wire() -> &'static str {
    if cfg!(windows) { "windows" } else if cfg!(target_os = "macos") { "macos" } else { std::env::consts::OS }
}

fn windows_build() -> Option<u32> {
    #[cfg(windows)]
    {
        crate::audio::platform::windows::os_build()
    }
    #[cfg(not(windows))]
    {
        None
    }
}

fn remote_session() -> bool {
    #[cfg(windows)]
    {
        <super::win::System as super::win::Affinity>::remote_session(&super::win::System)
    }
    #[cfg(not(windows))]
    {
        false
    }
}

/// The system's name for people ("Windows 11 24H2", "macOS 27.3").
pub fn system_name() -> String {
    status::system_name(Platform::here(), windows_build(), &os_version())
}

// ---- The run -------------------------------------------------------------------------------------------------------

static RUNNING: AtomicBool = AtomicBool::new(false);

/// What the page shows after a run.
#[derive(Clone, Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct View {
    pub result: Outcome,
    /// `permission_missing` | `capture_failed` | `control_missing`.
    pub reason: Option<String>,
    pub methods: Vec<MethodResult>,
    /// "macOS 27.3", "Windows 11 24H2".
    pub system: String,
    pub at: DateTime<Utc>,
    /// The captured screen, scaled down (a `data:` URL), for "What others see"; none when nothing was captured.
    pub picture: Option<String>,
    pub width: u32,
    pub height: u32,
    /// Where the test and control windows are in the picture, as fractions of its size (x, y, w, h).
    pub test_area: Option<[f64; 4]>,
    pub control_area: Option<[f64; 4]>,
}

/// The check windows' size, in window units (points on a Mac, logical pixels elsewhere).
const PATCH: (f64, f64) = (220.0, 150.0);
const GAP: f64 = 24.0;
/// How long the windows may take to draw, and how long the compositor is given after that.
const DRAW_WAIT: Duration = Duration::from_secs(4);
const SETTLE: Duration = Duration::from_millis(450);
/// The preview's long side.
const PREVIEW_LONG: u32 = 960;

fn build_patch(app: &AppHandle, label: &str, kind: Kind, at: (f64, f64)) -> tauri::Result<tauri::WebviewWindow> {
    let builder = WebviewWindowBuilder::new(app, label, WebviewUrl::App("index.html".into()))
        .title(if kind == Kind::Test { "EchoLedger Share Check test" } else { "EchoLedger Share Check control" })
        .inner_size(PATCH.0, PATCH.1)
        .position(at.0, at.1)
        .decorations(false)
        .shadow(false)
        .resizable(false)
        .always_on_top(true)
        .skip_taskbar(true)
        .focused(false)
        .focusable(false)
        .visible(true);
    let window = super::prepare(app, crate::smoke::isolate(builder), label, kind).build()?;
    super::built(app, &window);
    #[cfg(target_os = "macos")]
    crate::overlay::macos::keep_above_everything(&window);
    Ok(window)
}

/// Where the two windows go: side by side in the middle of the main screen (logical units from its corner).
fn places(app: &AppHandle) -> ((f64, f64), (f64, f64)) {
    let (origin, size) = app
        .primary_monitor()
        .ok()
        .flatten()
        .map(|m| {
            let scale = m.scale_factor();
            ((f64::from(m.position().x) / scale, f64::from(m.position().y) / scale), (f64::from(m.size().width) / scale, f64::from(m.size().height) / scale))
        })
        .unwrap_or(((0.0, 0.0), (1280.0, 800.0)));
    let y = origin.1 + (size.1 - PATCH.1) / 2.0;
    let left = origin.0 + size.0 / 2.0 - PATCH.0 - GAP / 2.0;
    ((left, y), (left + PATCH.0 + GAP, y))
}

/// A window's frame in the units [`capture::Shot`] uses (points on a Mac, physical pixels on Windows).
fn frame_units(window: &tauri::WebviewWindow) -> Option<((f64, f64), (f64, f64))> {
    let (position, size) = (window.inner_position().ok()?, window.inner_size().ok()?);
    let scale = if cfg!(target_os = "macos") { window.scale_factor().ok()? } else { 1.0 };
    Some(((f64::from(position.x) / scale, f64::from(position.y) / scale), (f64::from(size.width) / scale, f64::from(size.height) / scale)))
}

fn reason_of(failure: &Failure) -> &'static str {
    match failure {
        Failure::Permission => "permission_missing",
        Failure::Capture(_) => "capture_failed",
    }
}

fn fraction(r: Rect, width: u32, height: u32) -> [f64; 4] {
    let (w, h) = (f64::from(width.max(1)), f64::from(height.max(1)));
    [f64::from(r.x) / w, f64::from(r.y) / h, f64::from(r.w) / w, f64::from(r.h) / h]
}

/// The preview of a shot as a WebP `data:` URL.
fn preview(frame: &crate::screens::platform::Frame) -> Option<(String, u32, u32)> {
    let encoded = crate::screens::encode::preview(&frame.bgra, frame.width, frame.height, frame.stride, PREVIEW_LONG).ok()?;
    Some((format!("data:image/webp;base64,{}", base64::engine::general_purpose::STANDARD.encode(&encoded.webp)), encoded.width, encoded.height))
}

/// Runs the Share Check now. One at a time; the windows close whatever happens.
pub async fn run(app: &AppHandle, why: Why) -> AppResult<View> {
    if RUNNING.swap(true, Ordering::SeqCst) {
        return Err(AppError::msg("A Share Check is already running."));
    }
    let result = run_inner(app, why).await;
    for label in [CHECK_TEST, CHECK_CONTROL] {
        if let Some(w) = app.get_webview_window(label) {
            let _ = w.destroy();
        }
        // The check's windows are gone: their read-back no longer speaks for EchoLedger's own windows.
        app.state::<Privacy>().watch.lock().unwrap_or_else(|p| p.into_inner()).forget(label);
    }
    RUNNING.store(false, Ordering::SeqCst);
    let view = result?;
    let _ = app.emit("privacy:changed", ());
    crate::actions::emit_status(app);
    Ok(view)
}

async fn run_inner(app: &AppHandle, why: Why) -> AppResult<View> {
    let state = app.state::<crate::AppState>();
    let privacy = app.state::<Privacy>();
    let system = system_name();
    #[cfg(target_os = "macos")]
    let allowed = crate::screens::platform::macos::permission();
    #[cfg(not(target_os = "macos"))]
    let allowed = true;
    let mut methods: Vec<MethodResult> = Vec::new();
    let mut best: Option<(capture::Shot, Rect, Rect, Outcome)> = None;
    if !allowed {
        methods.push(MethodResult { method: "sck".into(), result: Outcome::Error, reason: Some("permission_missing".into()), counts: None, detail: None });
    } else {
        privacy.drawn_labels.lock().unwrap_or_else(|p| p.into_inner()).clear();
        let (test_at, control_at) = places(app);
        let test = build_patch(app, CHECK_TEST, Kind::Test, test_at).map_err(|e| AppError::msg(format!("The test window could not open: {e}")))?;
        let control = build_patch(app, CHECK_CONTROL, Kind::Control, control_at).map_err(|e| AppError::msg(format!("The control window could not open: {e}")))?;
        let deadline = tokio::time::Instant::now() + DRAW_WAIT;
        while privacy.drawn_labels.lock().unwrap_or_else(|p| p.into_inner()).len() < 2 && tokio::time::Instant::now() < deadline {
            let _ = tokio::time::timeout(Duration::from_millis(200), privacy.drawn.notified()).await;
        }
        tokio::time::sleep(SETTLE).await;
        let (Some(test_frame), Some(control_frame)) = (frame_units(&test), frame_units(&control)) else {
            return Err(AppError::msg("The check's windows did not open."));
        };
        // A window that never drew shows no colour at all: measuring it would pass for nothing.
        let drawn = privacy.drawn_labels.lock().unwrap_or_else(|p| p.into_inner()).len();
        if drawn < 2 {
            diag::log(&state.data_dir, "Share Check: the check's windows did not draw.");
            methods.push(MethodResult { method: "none".into(), result: Outcome::Error, reason: Some("capture_failed".into()), counts: None, detail: Some("the check's windows did not draw".into()) });
        }
        let shots = if drawn < 2 { Vec::new() } else { tokio::task::spawn_blocking(capture::screen_shots).await.map_err(|e| AppError::msg(e.to_string()))? };
        for (method, shot) in shots {
            match shot {
                Ok(shot) => {
                    let test_rect = capture::rect_in(shot.origin, shot.scale, test_frame, 6);
                    let control_rect = capture::rect_in(shot.origin, shot.scale, control_frame, 6);
                    let picture = pixels::Picture { width: shot.frame.width, height: shot.frame.height, stride: shot.frame.stride, bgra: &shot.frame.bgra };
                    let counts = pixels::measure(&picture, test_rect, control_rect);
                    let result = pixels::verdict(counts);
                    let reason = (result == Outcome::Error).then(|| "control_missing".to_string());
                    diag::log(&state.data_dir, &format!("Share Check {method}: {result:?} (test {} px, control {} of {} px).", counts.test, counts.control, counts.control_area));
                    let detail = (result != Outcome::Pass).then(|| {
                        let rgb = |r: Option<[u8; 3]>| r.map_or_else(|| "nothing".to_string(), |c| format!("rgb({}, {}, {})", c[0], c[1], c[2]));
                        format!("the control area averages {}, the test area {}, picture {}x{}", rgb(pixels::average(&picture, control_rect)), rgb(pixels::average(&picture, test_rect)), shot.frame.width, shot.frame.height)
                    });
                    methods.push(MethodResult { method: method.into(), result, reason, counts: Some(counts), detail });
                    // "What others see": a picture that shows the test window first, else the first that showed the screen.
                    let rank = |o: Outcome| match o {
                        Outcome::Fail => 2,
                        Outcome::Pass => 1,
                        Outcome::Error => 0,
                    };
                    if best.as_ref().is_none_or(|(_, _, _, kept)| rank(result) > rank(*kept)) {
                        best = Some((shot, test_rect, control_rect, result));
                    }
                }
                Err(failure) => {
                    let detail = match &failure {
                        Failure::Capture(message) => {
                            diag::log(&state.data_dir, &format!("Share Check {method}: the capture failed ({message})."));
                            Some(message.clone())
                        }
                        Failure::Permission => None,
                    };
                    methods.push(MethodResult { method: method.into(), result: Outcome::Error, reason: Some(reason_of(&failure).into()), counts: None, detail });
                }
            }
        }
    }
    let outcomes: Vec<Outcome> = methods.iter().map(|m| m.result).collect();
    let result = pixels::combined(&outcomes);
    let reason = if result == Outcome::Error { methods.iter().find_map(|m| m.reason.clone()) } else { None };
    let at = Utc::now();
    let record = Record { at, os_build: os_build(), app_version: app_version().into(), result, reason: reason.clone(), methods: methods.clone(), why };
    let mut store = Store::load(&state.data_dir);
    store.add(record);
    store.save(&state.data_dir);
    for m in methods.iter().filter(|m| m.method != "none") {
        report(app, ShareCheckReport {
            platform: platform_wire().into(),
            os_build: os_build().chars().take(64).collect(),
            app_version: app_version().into(),
            method: m.method.clone(),
            result: wire(m.result).into(),
            reason: m.reason.clone(),
            meeting_app: None,
            meeting_app_version: None,
            share_mode: None,
        });
    }
    let (picture, width, height, test_area, control_area) = match best.as_ref().and_then(|(shot, t, c, _)| preview(&shot.frame).map(|p| (p, shot, *t, *c))) {
        Some(((url, w, h), shot, t, c)) => (Some(url), w, h, Some(fraction(t, shot.frame.width, shot.frame.height)), Some(fraction(c, shot.frame.width, shot.frame.height))),
        None => (None, 0, 0, None, None),
    };
    Ok(View { result, reason, methods, system, at, picture, width, height, test_area, control_area })
}

pub fn wire(outcome: Outcome) -> &'static str {
    match outcome {
        Outcome::Pass => "pass",
        Outcome::Fail => "fail",
        Outcome::Error => "error",
    }
}

/// Sends one result to the server, only with "Send diagnostics" on and a signed-in computer; failures are dropped.
pub fn report(app: &AppHandle, body: ShareCheckReport) {
    let state = app.state::<crate::AppState>();
    if !state.settings().send_diagnostics {
        return;
    }
    let Ok(api) = state.api() else { return };
    tauri::async_runtime::spawn(async move {
        let _ = api.share_check_report(&body).await;
    });
}

/// Windows: a window's flag did not read back as set; with diagnostics on the server hears it once per run.
pub fn report_mismatch<R: tauri::Runtime>(app: &tauri::AppHandle<R>) {
    static SENT: AtomicBool = AtomicBool::new(false);
    let Some(state) = app.try_state::<crate::AppState>() else { return };
    if !state.settings().send_diagnostics || SENT.swap(true, Ordering::SeqCst) {
        return;
    }
    let Ok(api) = state.api() else { return };
    let body = ShareCheckReport {
        platform: platform_wire().into(),
        os_build: os_build(),
        app_version: app_version().into(),
        method: "readback".into(),
        result: "fail".into(),
        reason: Some("flag_mismatch".into()),
        meeting_app: None,
        meeting_app_version: None,
        share_mode: None,
    };
    tauri::async_runtime::spawn(async move {
        let _ = api.share_check_report(&body).await;
    });
}

/// For Live Assist (Phase L): two minutes before a scheduled call, the check runs if it is due, and the warning to say
/// once is given for the meeting app when it is not confirmed hidden on this Mac.
pub async fn before_call(app: &AppHandle, meeting: Option<MeetingApp>) -> (Option<View>, Option<String>) {
    let state = app.state::<crate::AppState>();
    let store = Store::load(&state.data_dir);
    let view = if due(&store, &os_build(), app_version(), Utc::now()).is_some() { run(app, Why::BeforeCall).await.ok() } else { None };
    let warning = meeting.filter(|_| cfg!(target_os = "macos")).and_then(|m| {
        let state_now = apps::state_of(m, apps::installed_version(m).as_deref(), &os_version(), &Store::load(&state.data_dir).guided);
        apps::warning_before_call(m, state_now)
    });
    (view, warning)
}

/// The automatic runs: a while after the start, then every few hours, the check runs when it is due and nothing is
/// going on (no recording, no meeting, no share running, onboarding done; on a Mac only with the permission, so it
/// never asks by itself).
pub fn spawn_auto(app: AppHandle) {
    tauri::async_runtime::spawn(async move {
        tokio::time::sleep(Duration::from_secs(90)).await;
        loop {
            let state = app.state::<crate::AppState>();
            let settings = state.settings();
            #[cfg(target_os = "macos")]
            let allowed = crate::screens::platform::macos::permission();
            #[cfg(not(target_os = "macos"))]
            let allowed = true;
            let busy = state.capture.is_recording() || state.detect.meeting().is_some() || app.state::<Privacy>().share_seen.lock().unwrap_or_else(|p| p.into_inner()).is_some();
            let store = Store::load(&state.data_dir);
            if allowed && !busy && settings.onboarding_done && let Some(why) = due(&store, &os_build(), app_version(), Utc::now()) {
                let _ = run(&app, why).await;
            }
            tokio::time::sleep(Duration::from_secs(6 * 60 * 60)).await;
        }
    });
}

// ---- The guided test (Mac) ---------------------------------------------------------------------------------------------

/// Opens the big test card (protected like every EchoLedger window) for the guided test of one meeting app.
pub fn guided_open(app: &AppHandle) -> AppResult<()> {
    if app.get_webview_window(GUIDED_CARD).is_some() {
        return Ok(());
    }
    let (origin, size) = app.primary_monitor().ok().flatten().map_or(((0.0, 0.0), (1280.0, 800.0)), |m| {
        let s = m.scale_factor();
        ((f64::from(m.position().x) / s, f64::from(m.position().y) / s), (f64::from(m.size().width) / s, f64::from(m.size().height) / s))
    });
    let (w, h) = (640.0, 360.0);
    let builder = WebviewWindowBuilder::new(app, GUIDED_CARD, WebviewUrl::App("index.html".into()))
        .title("EchoLedger test card")
        .inner_size(w, h)
        .position(origin.0 + (size.0 - w) / 2.0, origin.1 + (size.1 - h) / 2.0)
        .decorations(false)
        .resizable(false)
        .always_on_top(true)
        .skip_taskbar(true)
        .visible(true);
    let window = super::prepare(app, crate::smoke::isolate(builder), GUIDED_CARD, Kind::Test).build().map_err(|e| AppError::msg(e.to_string()))?;
    super::built(app, &window);
    Ok(())
}

pub fn guided_close(app: &AppHandle) {
    if let Some(w) = app.get_webview_window(GUIDED_CARD) {
        let _ = w.destroy();
    }
    app.state::<Privacy>().watch.lock().unwrap_or_else(|p| p.into_inner()).forget(GUIDED_CARD);
}

/// Keeps the answer of a guided test for this app version on this macOS version.
pub fn guided_answer(app: &AppHandle, meeting: MeetingApp, visible: bool) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    let version = apps::installed_version(meeting).unwrap_or_else(|| "unknown".into());
    let mut store = Store::load(&state.data_dir);
    store.guided.push(Guided { app: meeting, app_version: version.clone(), os_version: os_version(), visible, at: Utc::now() });
    store.save(&state.data_dir);
    diag::log(&state.data_dir, &format!("Guided test, {} {version}: {}.", meeting.name(), if visible { "visible" } else { "hidden" }));
    report(app, ShareCheckReport {
        platform: platform_wire().into(),
        os_build: os_build(),
        app_version: app_version().into(),
        method: "guided".into(),
        result: if visible { "fail" } else { "pass" }.into(),
        reason: None,
        meeting_app: Some(meeting.wire().into()),
        meeting_app_version: Some(version.chars().take(32).collect()),
        share_mode: Some("screen".into()),
    });
    guided_close(app);
    let _ = app.emit("privacy:changed", ());
    crate::actions::emit_status(app);
    Ok(())
}

// ---- The status ----------------------------------------------------------------------------------------------------

/// One meeting app on a Mac, for the badge's list and Settings.
#[derive(Clone, Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct AppRow {
    pub app: MeetingApp,
    pub name: &'static str,
    pub version: Option<String>,
    pub state: apps::AppState,
    pub line: String,
    pub needs_test: bool,
}

/// The last run, as the page shows it.
#[derive(Clone, Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct LastCheck {
    pub at: DateTime<Utc>,
    pub result: Outcome,
    pub reason: Option<String>,
    pub methods: Vec<MethodResult>,
}

/// Everything the badge and Settings → Screen-share privacy show.
#[derive(Clone, Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct PrivacyStatus {
    pub platform: &'static str,
    pub badge: Badge,
    pub badge_label: &'static str,
    pub system: String,
    pub hiding: crate::settings::ShareHiding,
    /// Windows before build 19041.
    pub old_windows: bool,
    pub lines: Vec<super::win::Line>,
    pub demo_seconds_left: Option<u64>,
    pub hidden_by_shortcut: bool,
    /// The safety net took EchoLedger off the screen for a share (macOS).
    pub hidden_for_share: bool,
    pub share: Option<super::watch::Seen>,
    pub last_check: Option<LastCheck>,
    pub check_running: bool,
    /// The automatic check would run now, and why.
    pub check_due: Option<Why>,
    pub apps: Vec<AppRow>,
    pub zoom_installed: bool,
    /// macOS Screen Recording is allowed (always true elsewhere).
    pub permission: bool,
    pub displays: usize,
}

pub fn status(app: &AppHandle) -> PrivacyStatus {
    let state = app.state::<crate::AppState>();
    let privacy = app.state::<Privacy>();
    let settings = state.settings();
    let store = Store::load(&state.data_dir);
    let (os, version) = (os_build(), app_version());
    let last = store.last_for(&os, version);
    let platform = Platform::here();
    let build = windows_build();
    let remote = remote_session();
    let watch = privacy.watch.lock().unwrap_or_else(|p| p.into_inner());
    let (mismatch, held) = (watch.any_mismatch(), watch.any_held());
    drop(watch);
    let now = std::time::Instant::now();
    let facts = Facts { platform, hiding: settings.hide_from_sharing, demo: privacy.demo_active(now), build, remote, mismatch, held, check: last.map(|r| r.result) };
    let badge = status::badge(&facts);
    let lines = if platform == Platform::Windows { super::win::lines(build, remote, settings.old_windows_sharing, mismatch) } else { Vec::new() };
    let mac_version = os_version();
    let apps: Vec<AppRow> = if platform == Platform::Mac {
        MeetingApp::ALL
            .into_iter()
            .map(|app| {
                let version = apps::installed_version(app);
                let state = apps::state_of(app, version.as_deref(), &mac_version, &store.guided);
                AppRow { app, name: app.name(), needs_test: apps::needs_test(version.as_deref(), state), line: apps::line(app, state), version, state }
            })
            .collect()
    } else {
        Vec::new()
    };
    #[cfg(target_os = "macos")]
    let permission = crate::screens::platform::macos::permission();
    #[cfg(not(target_os = "macos"))]
    let permission = true;
    PrivacyStatus {
        platform: platform_wire(),
        badge,
        badge_label: badge.label(),
        system: system_name(),
        hiding: settings.hide_from_sharing,
        old_windows: platform == Platform::Windows && build.is_none_or(|b| b < super::win::EXCLUDE_BUILD),
        lines,
        demo_seconds_left: privacy.demo_left(now).map(|d| d.as_secs().max(1)),
        hidden_by_shortcut: privacy.hidden_for(super::HideReason::Person),
        hidden_for_share: privacy.hidden_for(super::HideReason::Share),
        share: privacy.share_seen.lock().unwrap_or_else(|p| p.into_inner()).clone(),
        last_check: last.map(|r| LastCheck { at: r.at, result: r.result, reason: r.reason.clone(), methods: r.methods.clone() }),
        check_running: RUNNING.load(Ordering::SeqCst),
        check_due: due(&store, &os, version, Utc::now()),
        zoom_installed: apps.iter().any(|a| a.app == MeetingApp::Zoom && a.version.is_some()),
        apps,
        permission,
        displays: app.available_monitors().map(|m| m.len()).unwrap_or(1),
    }
}

/// "Copy diagnostics": the system, the setting, the last checks and the log lines.
pub fn diagnostics_text(app: &AppHandle) -> String {
    let state = app.state::<crate::AppState>();
    let s = status(app);
    let mut header = vec![
        format!("EchoLedger {} on {} ({})", app_version(), s.system, os_build()),
        format!("Hide from screen sharing: {:?}; badge: {}", s.hiding, s.badge_label),
    ];
    if let Some(last) = &s.last_check {
        let methods: Vec<String> = last.methods.iter().map(|m| format!("{} {}", m.method, wire(m.result))).collect();
        header.push(format!("Last Share Check: {} on {} ({})", wire(last.result), last.at.format("%Y-%m-%d %H:%M"), methods.join(", ")));
    }
    for row in &s.apps {
        header.push(format!("{} ({})", row.line, row.version.as_deref().unwrap_or("not installed")));
    }
    for line in &s.lines {
        header.push(line.text.clone());
    }
    diag::text(&header, &diag::read(&state.data_dir))
}

#[cfg(test)]
mod tests {
    use super::*;

    fn at(s: &str) -> DateTime<Utc> {
        DateTime::parse_from_rfc3339(s).unwrap().with_timezone(&Utc)
    }

    fn record(when: &str, os: &str, app: &str, result: Outcome) -> Record {
        Record { at: at(when), os_build: os.into(), app_version: app.into(), result, reason: None, methods: Vec::new(), why: Why::Asked }
    }

    #[test]
    fn the_automatic_check_runs_first_after_updates_and_weekly() {
        let mut store = Store::default();
        let now = at("2026-11-16T10:00:00Z");
        assert_eq!(due(&store, "27.3 (26C5035r)", "1.8.1", now), Some(Why::First));
        store.add(record("2026-11-16T09:00:00Z", "27.3 (26C5035r)", "1.8.1", Outcome::Pass));
        assert_eq!(due(&store, "27.3 (26C5035r)", "1.8.1", now), None, "just checked");
        assert_eq!(due(&store, "27.3 (26C5035r)", "1.8.2", now), Some(Why::AppUpdate));
        assert_eq!(due(&store, "27.4 (26D10)", "1.8.1", now), Some(Why::SystemUpdate));
        assert_eq!(due(&store, "27.3 (26C5035r)", "1.8.1", at("2026-11-23T09:00:00Z")), Some(Why::Weekly));
        assert_eq!(due(&store, "27.3 (26C5035r)", "1.8.1", at("2026-11-23T08:59:00Z")), None);
    }

    #[test]
    fn the_badge_reads_the_last_measured_check_of_this_version() {
        let mut store = Store::default();
        store.add(record("2026-11-10T09:00:00Z", "27.2", "1.8.1", Outcome::Fail));
        store.add(record("2026-11-16T09:00:00Z", "27.3", "1.8.1", Outcome::Pass));
        store.add(record("2026-11-16T10:00:00Z", "27.3", "1.8.1", Outcome::Error));
        assert_eq!(store.last_for("27.3", "1.8.1").map(|r| r.result), Some(Outcome::Pass), "an error measured nothing");
        assert_eq!(store.last_for("27.2", "1.8.1").map(|r| r.result), Some(Outcome::Fail));
        assert_eq!(store.last_for("27.4", "1.8.1"), None, "a new macOS is not checked yet");
        let mut only_error = Store::default();
        only_error.add(record("2026-11-16T10:00:00Z", "27.3", "1.8.1", Outcome::Error));
        assert_eq!(only_error.last_for("27.3", "1.8.1").map(|r| r.result), Some(Outcome::Error));
    }

    #[test]
    fn the_store_keeps_its_last_runs_and_survives_a_restart() {
        let dir = tempfile::tempdir().unwrap();
        let mut store = Store::default();
        for i in 0..(KEEP_CHECKS + 3) {
            store.add(record("2026-11-16T09:00:00Z", "27.3", &format!("1.8.{i}"), Outcome::Pass));
        }
        store.save(dir.path());
        let back = Store::load(dir.path());
        assert_eq!(back.checks.len(), KEEP_CHECKS);
        assert_eq!(back, store);
        // What is kept: results only, no picture.
        let text = std::fs::read_to_string(store_path(dir.path())).unwrap();
        assert!(!text.contains("data:image") && !text.contains("picture"));
    }

    #[test]
    fn the_report_carries_only_the_documented_fields() {
        let body = ShareCheckReport {
            platform: "macos".into(),
            os_build: "27.3 (26C5035r)".into(),
            app_version: "1.8.1".into(),
            method: "sck".into(),
            result: "pass".into(),
            reason: None,
            meeting_app: None,
            meeting_app_version: None,
            share_mode: None,
        };
        let json = serde_json::to_value(&body).unwrap();
        let mut keys: Vec<&str> = json.as_object().unwrap().keys().map(String::as_str).collect();
        keys.sort();
        assert_eq!(keys, ["app_version", "meeting_app", "meeting_app_version", "method", "os_build", "platform", "reason", "result", "share_mode"]);
    }
}
