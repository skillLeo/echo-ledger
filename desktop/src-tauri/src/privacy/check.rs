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
    /// The last automatic run measured nothing (an Error): tried again at the next tick, a few times.
    Retry,
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
    /// The capture came back one colour all over (no screen session): the smoke test skips rather than fails then.
    #[serde(default)]
    pub blank: bool,
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
/// How many automatic runs in a row may measure nothing on one system and app version before the check waits a week.
pub const RETRIES: usize = 3;

pub fn store_path(data_dir: &Path) -> PathBuf {
    data_dir.join("share-check.json")
}

impl Store {
    pub fn load(data_dir: &Path) -> Self {
        std::fs::read(store_path(data_dir)).ok().and_then(|b| serde_json::from_slice(&b).ok()).unwrap_or_default()
    }

    pub fn save(&self, data_dir: &Path) {
        if let Ok(bytes) = serde_json::to_vec_pretty(self) {
            let _ = diag::write_atomic(&store_path(data_dir), &bytes);
        }
    }

    /// Changes the kept store under one lock: the checks, the guided answers and the commands all write it, and each
    /// change starts from the last one (a written file is replaced whole, never read half-written).
    pub fn update(data_dir: &Path, change: impl FnOnce(&mut Store)) -> Store {
        static WRITING: std::sync::Mutex<()> = std::sync::Mutex::new(());
        let _one_at_a_time = WRITING.lock().unwrap_or_else(|p| p.into_inner());
        let mut store = Store::load(data_dir);
        change(&mut store);
        store.save(data_dir);
        store
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

/// Whether the automatic check is due, and why (pure): never run, the app or the system changed since the last run, a
/// week went by, or the last run measured nothing (tried again at the next tick, [`RETRIES`] times in a row at most).
pub fn due(store: &Store, os_build: &str, app_version: &str, now: DateTime<Utc>) -> Option<Why> {
    let Some(last) = store.checks.last() else { return Some(Why::First) };
    let errors_in_a_row = store.checks.iter().rev().take_while(|r| r.os_build == os_build && r.app_version == app_version && r.result == Outcome::Error).count();
    if last.app_version != app_version {
        Some(Why::AppUpdate)
    } else if last.os_build != os_build {
        Some(Why::SystemUpdate)
    } else if now - last.at >= WEEKLY {
        Some(Why::Weekly)
    } else if last.result == Outcome::Error && errors_in_a_row < RETRIES {
        Some(Why::Retry)
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
        .title(if kind == Kind::Test { "EchoLedger screen-share privacy test" } else { "EchoLedger screen-share privacy control" })
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
    let window = super::window(app, crate::smoke::isolate(builder), label, kind, |b| b)?;
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
fn frame_units(window: &tauri::WebviewWindow) -> Option<Frame> {
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

/// A window's frame: its top-left corner and its size.
pub type Frame = ((f64, f64), (f64, f64));

/// Whether something lies over the test window (pure): a window in front of it whose frame overlaps its frame. A covered
/// test window could read as hidden while it is not, so the check measures nothing then.
pub fn covered(test: Frame, in_front: &[Frame]) -> bool {
    let ((x, y), (w, h)) = test;
    in_front.iter().any(|((fx, fy), (fw, fh))| *fx < x + w && x < fx + fw && *fy < y + h && y < fy + fh)
}

/// The frames (points, the main display's top-left corner at 0, 0) of every visible window in front of window
/// `number`, from the system's window list (front to back); none when it is not in the list.
#[cfg(target_os = "macos")]
fn windows_in_front_of(number: i64) -> Option<Vec<Frame>> {
    use objc2_core_foundation::{CFDictionary, CFNumber, CFString, CFType};
    use objc2_core_graphics::{CGWindowListCopyWindowInfo, CGWindowListOption, kCGWindowAlpha, kCGWindowBounds, kCGWindowNumber};

    fn value<'a>(dict: &'a CFDictionary, key: &CFString) -> Option<&'a CFType> {
        // SAFETY: the key is a CFString; the value, when present, is a CF object the dictionary keeps alive.
        let value = unsafe { dict.value((key as *const CFString).cast()) } as *const CFType;
        unsafe { value.as_ref() }
    }
    fn number_of<'a>(dict: &'a CFDictionary, key: &CFString) -> Option<&'a CFNumber> {
        value(dict, key).and_then(|v| v.downcast_ref::<CFNumber>())
    }
    let list = CGWindowListCopyWindowInfo(CGWindowListOption::OptionOnScreenOnly | CGWindowListOption::ExcludeDesktopElements, 0)?;
    // SAFETY: the window-list keys are constants of CoreGraphics.
    let (number_key, bounds_key, alpha_key) = unsafe { (kCGWindowNumber, kCGWindowBounds, kCGWindowAlpha) };
    let mut front = Vec::new();
    for i in 0..list.count() {
        // SAFETY: the index is in range; each value of this array is a CFDictionary the array keeps alive.
        let item = unsafe { list.value_at_index(i) } as *const CFType;
        let Some(dict) = (unsafe { item.as_ref() }).and_then(|t| t.downcast_ref::<CFDictionary>()) else { continue };
        if number_of(dict, number_key).and_then(|n| n.as_i64()) == Some(number) {
            return Some(front);
        }
        if number_of(dict, alpha_key).and_then(|a| a.as_f64()).is_some_and(|a| a <= 0.01) {
            continue;
        }
        let Some(bounds) = value(dict, bounds_key).and_then(|v| v.downcast_ref::<CFDictionary>()) else { continue };
        let field = |name: &'static str| number_of(bounds, &CFString::from_static_str(name)).and_then(|n| n.as_f64());
        if let (Some(x), Some(y), Some(w), Some(h)) = (field("X"), field("Y"), field("Width"), field("Height")) {
            front.push(((x, y), (w, h)));
        }
    }
    None
}

/// Whether the test window is covered now (none when the system could not say): on a Mac the windows in front of it in
/// the window list; on Windows the window hit at its four corners and its middle.
fn test_window_covered(window: &tauri::WebviewWindow, frame: Frame) -> Option<bool> {
    #[cfg(target_os = "macos")]
    {
        let pointer = window.ns_window().ok()?;
        // SAFETY: Tauri hands out the window's own NSWindow, alive while `window` is; the number is a plain read.
        let number = unsafe { &*(pointer as *const objc2_app_kit::NSWindow) }.windowNumber() as i64;
        windows_in_front_of(number).map(|front| covered(frame, &front))
    }
    #[cfg(windows)]
    {
        use windows::Win32::Foundation::POINT;
        use windows::Win32::UI::WindowsAndMessaging::{GA_ROOT, GetAncestor, WindowFromPoint};
        let own = window.hwnd().ok()?.0 as isize;
        let ((x, y), (w, h)) = frame;
        let points = [(x + 8.0, y + 8.0), (x + w - 8.0, y + 8.0), (x + 8.0, y + h - 8.0), (x + w - 8.0, y + h - 8.0), (x + w / 2.0, y + h / 2.0)];
        Some(points.iter().any(|(px, py)| {
            // SAFETY: plain queries of the window under a screen point and its top-level window.
            let hit = unsafe { GetAncestor(WindowFromPoint(POINT { x: *px as i32, y: *py as i32 }), GA_ROOT) };
            hit.0 as isize != own
        }))
    }
    #[cfg(not(any(windows, target_os = "macos")))]
    {
        let _ = (window, frame);
        None
    }
}

/// Runs the Share Check now. One at a time, never while the guided test's card is open; the windows close whatever
/// happens.
pub async fn run(app: &AppHandle, why: Why) -> AppResult<View> {
    if app.get_webview_window(GUIDED_CARD).is_some() {
        return Err(AppError::msg("The test with your phone is still open. Finish it first."));
    }
    if RUNNING.swap(true, Ordering::SeqCst) {
        return Err(AppError::msg("EchoLedger is already checking screen-share privacy."));
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
        methods.push(MethodResult { method: "sck".into(), result: Outcome::Error, reason: Some("permission_missing".into()), counts: None, detail: None, blank: false });
    } else {
        privacy.drawn_labels.lock().unwrap_or_else(|p| p.into_inner()).clear();
        let (test_at, control_at) = places(app);
        let test = build_patch(app, CHECK_TEST, Kind::Test, test_at).map_err(|e| AppError::msg(format!("The test window could not open: {e}")))?;
        let control = build_patch(app, CHECK_CONTROL, Kind::Control, control_at).map_err(|e| AppError::msg(format!("The control window could not open: {e}")))?;
        // Both of the check's own windows by name: the guided card's page says it drew too, and must not count.
        let both_drawn = || {
            let drawn = privacy.drawn_labels.lock().unwrap_or_else(|p| p.into_inner());
            drawn.contains(CHECK_TEST) && drawn.contains(CHECK_CONTROL)
        };
        let deadline = tokio::time::Instant::now() + DRAW_WAIT;
        while !both_drawn() && tokio::time::Instant::now() < deadline {
            let _ = tokio::time::timeout(Duration::from_millis(200), privacy.drawn.notified()).await;
        }
        tokio::time::sleep(SETTLE).await;
        let (Some(test_frame), Some(control_frame)) = (frame_units(&test), frame_units(&control)) else {
            return Err(AppError::msg("The check's windows did not open."));
        };
        // A window that never drew shows no colour at all, and one something covers shows what covers it: measuring
        // either would pass for nothing.
        let drawn = both_drawn();
        let is_covered = drawn && test_window_covered(&test, test_frame) == Some(true);
        if !drawn {
            diag::log(&state.data_dir, "Privacy check: the check's windows did not draw.");
            methods.push(MethodResult { method: "none".into(), result: Outcome::Error, reason: Some("capture_failed".into()), counts: None, detail: Some("the check's windows did not draw".into()), blank: false });
        } else if is_covered {
            diag::log(&state.data_dir, "Privacy check: another window covered the test window.");
            methods.push(MethodResult { method: "none".into(), result: Outcome::Error, reason: Some("covered".into()), counts: None, detail: Some("another window covered the test window".into()), blank: false });
        }
        let shots = if !drawn || is_covered { Vec::new() } else { tokio::task::spawn_blocking(capture::screen_shots).await.map_err(|e| AppError::msg(e.to_string()))? };
        for (method, shot) in shots {
            match shot {
                Ok(shot) => {
                    let test_rect = capture::rect_in(shot.origin, shot.scale, test_frame, 6);
                    let control_rect = capture::rect_in(shot.origin, shot.scale, control_frame, 6);
                    let picture = pixels::Picture { width: shot.frame.width, height: shot.frame.height, stride: shot.frame.stride, bgra: &shot.frame.bgra };
                    let counts = pixels::measure(&picture, test_rect, control_rect);
                    let result = pixels::verdict(counts);
                    let reason = (result == Outcome::Error).then(|| "control_missing".to_string());
                    diag::log(&state.data_dir, &format!("Privacy check {method}: {result:?} (test {} of {} px, control {} of {} px).", counts.test, counts.test_area, counts.control, counts.control_area));
                    let detail = (result != Outcome::Pass).then(|| {
                        let rgb = |r: Option<[u8; 3]>| r.map_or_else(|| "nothing".to_string(), |c| format!("rgb({}, {}, {})", c[0], c[1], c[2]));
                        format!("the control area averages {}, the test area {}, picture {}x{}", rgb(pixels::average(&picture, control_rect)), rgb(pixels::average(&picture, test_rect)), shot.frame.width, shot.frame.height)
                    });
                    let blank = result == Outcome::Error && pixels::uniform(&picture);
                    methods.push(MethodResult { method: method.into(), result, reason, counts: Some(counts), detail, blank });
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
                            diag::log(&state.data_dir, &format!("Privacy check {method}: the capture failed ({message})."));
                            Some(message.clone())
                        }
                        Failure::Permission => None,
                    };
                    methods.push(MethodResult { method: method.into(), result: Outcome::Error, reason: Some(reason_of(&failure).into()), counts: None, detail, blank: false });
                }
            }
        }
    }
    let outcomes: Vec<Outcome> = methods.iter().map(|m| m.result).collect();
    let result = pixels::combined(&outcomes);
    // Windows before 19041 with "Black box": hidden as a black box, which the result says (and the server hears).
    let black_box = result == Outcome::Pass && black_box_here(state.settings().old_windows_sharing);
    if black_box {
        methods.iter_mut().filter(|m| m.result == Outcome::Pass).for_each(|m| m.reason = Some("old_windows".into()));
    }
    let reason = if result == Outcome::Error { methods.iter().find_map(|m| m.reason.clone()) } else if black_box { Some("old_windows".into()) } else { None };
    let at = Utc::now();
    let record = Record { at, os_build: os_build(), app_version: app_version().into(), result, reason: reason.clone(), methods: methods.clone(), why };
    let before = Store::update(&state.data_dir, |store| store.add(record)).checks.iter().rev().skip(1).find(|r| r.os_build == os_build() && r.app_version == app_version() && r.result != Outcome::Error).map(|r| r.result);
    if let Some((title, body)) = change_notice(before, result, why) {
        use crate::notifications::{Category, allowed, local_minutes, show};
        let settings = state.settings();
        if allowed(&settings, Category::Account, local_minutes()) {
            show(app, &settings, Category::Account, title, body);
        }
    }
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

/// What to tell the person when a run they did not start changed the result on this system and app version (pure),
/// above all that EchoLedger became visible in screen share. A run that measured nothing says nothing.
pub fn change_notice(before: Option<Outcome>, after: Outcome, why: Why) -> Option<(&'static str, &'static str)> {
    if matches!(why, Why::Asked | Why::Onboarding) || after == Outcome::Error || before == Some(after) {
        return None;
    }
    match after {
        Outcome::Fail => Some((
            "EchoLedger may be visible in screen share",
            "The automatic check found EchoLedger in a screen capture on this computer. Open Check screen-share privacy to see what others see.",
        )),
        Outcome::Pass if before == Some(Outcome::Fail) => Some(("EchoLedger is hidden from screen share again", "The automatic check no longer finds it in a screen capture.")),
        _ => None,
    }
}

/// Windows before build 19041 gives a black box instead of leaving the window out (with the setting "Black box").
fn black_box_here(old: crate::settings::OldWindowsSharing) -> bool {
    Platform::here() == Platform::Windows && super::win::wanted(true, windows_build(), old) == super::win::WDA_MONITOR
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
        let state_now = apps::state_of(m, apps::version(m).as_deref(), &os_version(), &Store::load(&state.data_dir).guided);
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

/// A window's bounds in logical points: x, y, width, height.
pub type Bounds = (f64, f64, f64, f64);

/// The test card's size: big enough to read on a phone in a screen share, and the smallest it is made to fit beside
/// the main window (16:9 both).
pub const CARD_SIZE: (f64, f64) = (640.0, 360.0);
pub const CARD_SMALLEST: (f64, f64) = (480.0, 270.0);
const CARD_MARGIN: f64 = 16.0;

fn overlap(a: Bounds, b: Bounds) -> f64 {
    let w = (a.0 + a.2).min(b.0 + b.2) - a.0.max(b.0);
    let h = (a.1 + a.3).min(b.1 + b.3) - a.1.max(b.1);
    if w > 0.0 && h > 0.0 { w * h } else { 0.0 }
}

/// Where the phone-test card goes on `screen` (pure): beside the main window where it fits, right, left, below or above
/// it (made smaller down to [`CARD_SMALLEST`] to fit), so it never covers the question and its answer buttons; with no
/// room beside it, the smallest card in a top corner of the screen (the one that covers less of the window, the left
/// one on a tie): there it covers the window's title and the top of its page, while the question is the page's last
/// section. Centred only when there is no main window.
pub fn card_frame(screen: Bounds, main: Option<Bounds>) -> Bounds {
    let (sx, sy, sw, sh) = screen;
    let Some(m) = main else {
        let (w, h) = CARD_SIZE;
        return (sx + (sw - w) / 2.0, sy + (sh - h) / 2.0, w, h);
    };
    let fit = |room_w: f64, room_h: f64| -> Option<(f64, f64)> {
        let w = CARD_SIZE.0.min(room_w).min(room_h * 16.0 / 9.0);
        (w >= CARD_SMALLEST.0).then_some((w, w * 9.0 / 16.0))
    };
    let clamp_y = |h: f64| (m.1 + (m.3 - h) / 2.0).clamp(sy + CARD_MARGIN, sy + sh - h - CARD_MARGIN);
    let clamp_x = |w: f64| (m.0 + (m.2 - w) / 2.0).clamp(sx + CARD_MARGIN, sx + sw - w - CARD_MARGIN);
    let full_h = sh - 2.0 * CARD_MARGIN;
    let full_w = sw - 2.0 * CARD_MARGIN;
    let right = sx + sw - (m.0 + m.2) - 2.0 * CARD_MARGIN;
    if let Some((w, h)) = fit(right, full_h) {
        return (m.0 + m.2 + CARD_MARGIN, clamp_y(h), w, h);
    }
    let left = m.0 - sx - 2.0 * CARD_MARGIN;
    if let Some((w, h)) = fit(left, full_h) {
        return (m.0 - CARD_MARGIN - w, clamp_y(h), w, h);
    }
    let below = sy + sh - (m.1 + m.3) - 2.0 * CARD_MARGIN;
    if let Some((w, h)) = fit(full_w, below) {
        return (clamp_x(w), m.1 + m.3 + CARD_MARGIN, w, h);
    }
    let above = m.1 - sy - 2.0 * CARD_MARGIN;
    if let Some((w, h)) = fit(full_w, above) {
        return (clamp_x(w), m.1 - CARD_MARGIN - h, w, h);
    }
    let (w, h) = CARD_SMALLEST;
    let top_left = (sx + CARD_MARGIN, sy + CARD_MARGIN, w, h);
    let top_right = (sx + sw - w - CARD_MARGIN, sy + CARD_MARGIN, w, h);
    if overlap(top_right, m) < overlap(top_left, m) { top_right } else { top_left }
}

/// The main window's frame in logical points and the display it is on, when it is on screen.
fn main_frame(app: &AppHandle) -> Option<(Bounds, Option<tauri::Monitor>)> {
    let window = app.get_webview_window(crate::lifecycle::MAIN)?;
    if !window.is_visible().unwrap_or(false) || window.is_minimized().unwrap_or(false) {
        return None;
    }
    let scale = window.scale_factor().ok()?;
    let (at, size) = (window.outer_position().ok()?, window.outer_size().ok()?);
    let frame = (f64::from(at.x) / scale, f64::from(at.y) / scale, f64::from(size.width) / scale, f64::from(size.height) / scale);
    Some((frame, window.current_monitor().ok().flatten()))
}

/// Opens the big test card (protected like every EchoLedger window) for the guided test of one meeting app. It never
/// takes the focus (the keyboard and VoiceOver stay on the question in the main window) and sits clear of the main
/// window, so it never covers the answer buttons.
pub fn guided_open(app: &AppHandle) -> AppResult<()> {
    if app.get_webview_window(GUIDED_CARD).is_some() {
        return Ok(());
    }
    if RUNNING.load(Ordering::SeqCst) {
        return Err(AppError::msg("Wait for the check to finish, then test with your phone."));
    }
    let (main, shown_on) = main_frame(app).map_or((None, None), |(frame, monitor)| (Some(frame), monitor));
    // The display the main window is on (the primary one without it).
    let monitor = shown_on.or_else(|| app.primary_monitor().ok().flatten());
    let screen = monitor.map_or((0.0, 0.0, 1280.0, 800.0), |m| {
        let s = m.scale_factor();
        (f64::from(m.position().x) / s, f64::from(m.position().y) / s, f64::from(m.size().width) / s, f64::from(m.size().height) / s)
    });
    let (x, y, w, h) = card_frame(screen, main);
    let builder = WebviewWindowBuilder::new(app, GUIDED_CARD, WebviewUrl::App("index.html".into()))
        .title("EchoLedger test card")
        .inner_size(w, h)
        .position(x, y)
        .decorations(false)
        .resizable(false)
        .always_on_top(true)
        .skip_taskbar(true)
        .focused(false)
        .focusable(false)
        .visible(true);
    super::window(app, crate::smoke::isolate(builder), GUIDED_CARD, Kind::Test, |b| b).map_err(|e| AppError::msg(e.to_string()))?;
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
    Store::update(&state.data_dir, |store| store.guided.push(Guided { app: meeting, app_version: version.clone(), os_version: os_version(), visible, at: Utc::now() }));
    app.state::<Privacy>().note_guided_answer();
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
    /// The person opened the main window during that share: it says it may be in the screen share.
    pub main_in_share: bool,
    /// Which share that is, counted in this run: the notice closed in one share shows again in the next.
    pub share_number: u64,
    /// The share's sharing bar is on screen: "Show EchoLedger windows" is not offered (they come back when it ends).
    pub bar_on_screen: bool,
    pub share: Option<super::watch::Seen>,
    pub last_check: Option<LastCheck>,
    pub check_running: bool,
    /// The automatic check would run now, and why.
    pub check_due: Option<Why>,
    pub apps: Vec<AppRow>,
    pub zoom_installed: bool,
    /// macOS Screen Recording is allowed (always true elsewhere).
    pub permission: bool,
    /// One of the floating windows is open: "Move to the other display" has something to move.
    pub floating_open: bool,
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
    let (mismatch, held, changed_outside) = (watch.any_mismatch(), watch.any_held(), watch.changed_outside());
    drop(watch);
    let now = std::time::Instant::now();
    let black_box = platform == Platform::Windows && build.is_none_or(|b| b < super::win::EXCLUDE_BUILD) && settings.old_windows_sharing == crate::settings::OldWindowsSharing::BlackBox;
    let facts = Facts { platform, hiding: settings.hide_from_sharing, demo: privacy.demo_active(now), build, remote, mismatch, held, check: last.map(|r| r.result), black_box };
    let badge = status::badge(&facts);
    #[cfg(target_os = "macos")]
    let permission = crate::screens::platform::macos::permission();
    #[cfg(not(target_os = "macos"))]
    let permission = true;
    let lines = match platform {
        Platform::Windows => super::win::lines(build, remote, settings.old_windows_sharing, mismatch, changed_outside),
        Platform::Mac => mac_lines(permission, super::watch::allowed(settings.hide_from_sharing, settings.share_one_window, false, false)),
        Platform::Other => Vec::new(),
    };
    let mac_version = os_version();
    // Read before the answer is put together: no lock is held while the displays are asked of the main thread below.
    let share = privacy.share_seen.lock().unwrap_or_else(|p| p.into_inner()).clone();
    let apps: Vec<AppRow> = if platform == Platform::Mac {
        MeetingApp::ALL
            .into_iter()
            .map(|app| {
                let version = apps::version(app);
                let state = apps::state_of(app, version.as_deref(), &mac_version, &store.guided);
                AppRow { app, name: app.name(), needs_test: apps::needs_test(version.as_deref(), state), line: apps::line(app, state), version, state }
            })
            .collect()
    } else {
        Vec::new()
    };
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
        main_in_share: privacy.main_in_share(settings.hide_from_sharing),
        share_number: privacy.share_number(),
        bar_on_screen: privacy.bar_holds(),
        share,
        last_check: last.map(|r| LastCheck { at: r.at, result: r.result, reason: r.reason.clone(), methods: r.methods.clone() }),
        check_running: RUNNING.load(Ordering::SeqCst),
        check_due: due(&store, &os, version, Utc::now()),
        zoom_installed: apps.iter().any(|a| a.app == MeetingApp::Zoom && a.version.is_some()),
        apps,
        permission,
        floating_open: crate::overlay::floating_open(app),
        displays: app.available_monitors().map(|m| m.len()).unwrap_or(1),
    }
}

/// Without the Screen Recording permission other apps' window titles read empty, so the safety net can't see a share.
pub const NO_PERMISSION_LINE: &str = "EchoLedger needs the Screen Recording permission to notice when you share your whole screen and hide its windows. Allow it in System Settings.";

/// The Mac's amber lines (pure): the safety net can't work without the permission (said only while it is meant to).
pub fn mac_lines(permission: bool, safety_net_on: bool) -> Vec<super::win::Line> {
    if permission || !safety_net_on {
        return Vec::new();
    }
    vec![super::win::Line { fix: Some(super::win::Fix::OpenSettings), ..super::win::Line::warning(NO_PERMISSION_LINE) }]
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
        header.push(format!("Last privacy check: {} on {} ({})", wire(last.result), last.at.format("%Y-%m-%d %H:%M"), methods.join(", ")));
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
    fn an_automatic_run_that_measured_nothing_is_tried_again_a_few_times() {
        let mut store = Store::default();
        let now = at("2026-11-16T18:00:00Z");
        store.add(record("2026-11-16T09:00:00Z", "27.3", "1.8.1", Outcome::Pass));
        store.add(record("2026-11-16T10:00:00Z", "27.3", "1.8.1", Outcome::Error));
        assert_eq!(due(&store, "27.3", "1.8.1", now), Some(Why::Retry), "not counted as done for a week");
        store.add(record("2026-11-16T16:00:00Z", "27.3", "1.8.1", Outcome::Error));
        assert_eq!(due(&store, "27.3", "1.8.1", now), Some(Why::Retry));
        store.add(record("2026-11-16T22:00:00Z", "27.3", "1.8.1", Outcome::Error));
        assert_eq!(due(&store, "27.3", "1.8.1", now), None, "three in a row: it waits a week, not every 6 h for ever");
        store.add(record("2026-11-17T04:00:00Z", "27.3", "1.8.1", Outcome::Pass));
        assert_eq!(due(&store, "27.3", "1.8.1", at("2026-11-17T05:00:00Z")), None, "measured again");
    }

    #[test]
    fn the_store_written_from_several_threads_keeps_every_answer() {
        let dir = tempfile::tempdir().unwrap();
        let threads: Vec<_> = (0..8)
            .map(|t| {
                let dir = dir.path().to_path_buf();
                std::thread::spawn(move || {
                    for i in 0..10 {
                        Store::update(&dir, |s| s.guided.push(Guided { app: MeetingApp::Zoom, app_version: format!("{t}.{i}"), os_version: "27.3".into(), visible: false, at: Utc::now() }));
                    }
                })
            })
            .collect();
        threads.into_iter().for_each(|t| t.join().unwrap());
        assert_eq!(Store::load(dir.path()).guided.len(), 80, "no change was lost and no torn read emptied the file");
    }

    #[test]
    fn an_automatic_run_says_when_the_result_changed_above_all_to_visible() {
        let visible = change_notice(Some(Outcome::Pass), Outcome::Fail, Why::Weekly).unwrap();
        assert_eq!(visible.0, "EchoLedger may be visible in screen share");
        assert!(change_notice(None, Outcome::Fail, Why::AppUpdate).is_some(), "the first measure after an update");
        assert_eq!(change_notice(Some(Outcome::Fail), Outcome::Pass, Why::SystemUpdate).map(|n| n.0), Some("EchoLedger is hidden from screen share again"));
        assert_eq!(change_notice(Some(Outcome::Pass), Outcome::Pass, Why::Weekly), None, "nothing changed");
        assert_eq!(change_notice(None, Outcome::Pass, Why::First), None, "hidden from the start needs no news");
        assert_eq!(change_notice(Some(Outcome::Pass), Outcome::Error, Why::Retry), None, "nothing was measured");
        assert_eq!(change_notice(Some(Outcome::Pass), Outcome::Fail, Why::Asked), None, "the person sees the result on the page");
    }

    #[test]
    fn a_covered_test_window_is_told_apart() {
        let test = ((540.0, 400.0), (220.0, 150.0));
        assert!(!covered(test, &[]));
        assert!(!covered(test, &[((0.0, 0.0), (1312.0, 25.0)), ((784.0, 400.0), (220.0, 150.0))]), "the menu bar and the control beside it");
        assert!(covered(test, &[((600.0, 440.0), (40.0, 30.0))]), "a small window over the patch");
        assert!(covered(test, &[((0.0, 0.0), (1312.0, 848.0))]), "a full-screen window in front");
        assert!(!covered(test, &[((760.0, 400.0), (24.0, 150.0))]), "touching its edge is not over it");
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
    fn the_phone_test_card_sits_clear_of_the_main_window() {
        let laptop = (0.0, 0.0, 1512.0, 982.0);
        // The main window on the left half: the card beside it, full size, level with it.
        let left_half = (0.0, 38.0, 756.0, 900.0);
        let card = card_frame(laptop, Some(left_half));
        assert_eq!((card.2, card.3), CARD_SIZE);
        assert_eq!(card.0, 756.0 + 16.0);
        assert_eq!(overlap(card, left_half), 0.0);
        // Settings' usual size in the middle of a laptop: no room beside it, so the smallest card in the top left
        // corner, over the window's title and the top of the page, well above the question at the page's end.
        let middle = (256.0, 120.0, 1000.0, 740.0);
        let card = card_frame(laptop, Some(middle));
        assert_eq!(card, (16.0, 16.0, CARD_SMALLEST.0, CARD_SMALLEST.1));
        assert!(card.1 + card.3 <= middle.1 + middle.3 / 2.0, "it covers at most the window's top part");
        // On a wider screen the same window has room to its right, at a smaller size.
        let wide = card_frame((0.0, 0.0, 1920.0, 1080.0), Some((300.0, 120.0, 1000.0, 740.0)));
        assert_eq!(overlap(wide, (300.0, 120.0, 1000.0, 740.0)), 0.0, "{wide:?}");
        assert!(wide.2 >= CARD_SMALLEST.0 && (wide.2 / wide.3 - 16.0 / 9.0).abs() < 1e-9);
        // A wide window high up: below it.
        let top = (100.0, 40.0, 1300.0, 520.0);
        let card = card_frame(laptop, Some(top));
        assert!(card.1 >= 40.0 + 520.0 && overlap(card, top) == 0.0, "{card:?}");
        // Full screen: the smallest card in the top left corner, never centred over the question.
        let full = (0.0, 0.0, 1512.0, 982.0);
        let card = card_frame(laptop, Some(full));
        assert_eq!(card, (16.0, 16.0, CARD_SMALLEST.0, CARD_SMALLEST.1));
        assert!(card.1 + card.3 < 982.0 / 2.0, "the top half only");
        // The main window kept to the left: the top right corner covers less of it.
        let narrow_left = (0.0, 0.0, 1300.0, 982.0);
        assert_eq!(card_frame(laptop, Some(narrow_left)).0, 1512.0 - 480.0 - 16.0);
        // A main window pushed to the top right: the corner that covers the least of it.
        let top_right = (900.0, 0.0, 612.0, 600.0);
        let card = card_frame((0.0, 0.0, 1100.0, 650.0), Some(top_right));
        assert_eq!(overlap(card, top_right), 0.0, "{card:?}");
        // No main window on screen: centred.
        assert_eq!(card_frame(laptop, None), ((1512.0 - 640.0) / 2.0, (982.0 - 360.0) / 2.0, 640.0, 360.0));
    }

    #[test]
    fn the_status_reads_its_locks_before_it_asks_the_main_thread() {
        // The answer asks the main thread for the displays: a lock read in the same statement would be held across that
        // wait (the kind of lock that froze the Mac build), so every lock is read before the answer is put together.
        let source = include_str!("check.rs");
        let status = &source[source.find("pub fn status(app: &AppHandle) -> PrivacyStatus {").unwrap()..];
        let status = &status[..status.find("\n}\n").unwrap()];
        let literal = &status[status.find("\n    PrivacyStatus {").expect("the answer is a PrivacyStatus literal")..];
        assert!(literal.contains("app.available_monitors()"), "the displays are asked inside the answer");
        for lock in [".lock()", "lock(&", ".read()", ".write()", ".try_lock()"] {
            assert!(!literal.contains(lock), "`{lock}` inside the PrivacyStatus literal is held while the main thread is asked");
        }
        assert!(status.find("let share = privacy.share_seen.lock()").unwrap() < status.find("\n    PrivacyStatus {").unwrap(), "the share is read first");
    }

    #[test]
    fn the_phone_test_card_never_takes_the_focus() {
        let source = include_str!("check.rs");
        let open = &source[source.find("pub fn guided_open(").unwrap()..];
        let open = &open[..open.find("\n}\n").unwrap()];
        assert!(open.contains(".focused(false)") && open.contains(".focusable(false)"), "the keyboard and VoiceOver stay on the question");
        assert!(open.contains("card_frame(screen, main)"), "placed clear of the main window");
    }

    #[test]
    fn a_mac_without_screen_recording_is_told_the_safety_net_cannot_work() {
        let lines = mac_lines(false, true);
        assert_eq!(lines.len(), 1);
        assert_eq!(lines[0].text, NO_PERMISSION_LINE);
        assert_eq!(lines[0].fix, Some(super::super::win::Fix::OpenSettings), "with the button to System Settings");
        assert!(lines[0].warning);
        assert!(mac_lines(true, true).is_empty());
        assert!(mac_lines(false, false).is_empty(), "the safety net is off anyway (Off, one window)");
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
