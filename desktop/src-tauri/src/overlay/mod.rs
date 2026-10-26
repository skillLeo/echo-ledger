//! Overlay-type windows: caption overlay, dictation box, recording pill and review card. Built on first use and
//! destroyed after they have been hidden for a while (lifecycle.rs, C9), always visible, unfocused and unfocusable
//! (never `show()` or `set_focus()` — tao ≥ 0.37.1 would steal focus; tao builds them with `WS_EX_NOACTIVATE` and
//! `SW_SHOWNOACTIVATE`, so one built mid-call never takes focus either); "hidden" = the view renders nothing and
//! reports no hit regions, so the window is fully click-through. docs/research/desktop-tauri.md §2.

pub mod geometry;

use std::collections::HashMap;
use std::sync::Mutex;
use std::time::Duration;

use tauri::{AppHandle, LogicalSize, Manager, Monitor, PhysicalPosition, Runtime, WebviewUrl, WebviewWindow, WebviewWindowBuilder};

use crate::lifecycle;
use crate::settings::{CaptionSize, CaptionStyle, CaptionWidth, Settings};
use geometry::{Corner, PillPosition, RectPx, WorkArea, caption_overlay_size_for, cursor_in_regions, dock, pill_origin};

pub const OVERLAY: &str = "overlay";
pub const DICTATION: &str = "dictation";
pub const PILL: &str = "pill";
pub const REVIEW: &str = "review";

/// Every overlay-type window: same builder flags, click-through unless the cursor is over a reported region.
pub const WINDOWS: [&str; 4] = [OVERLAY, DICTATION, PILL, REVIEW];

const DICTATION_SIZE: (f64, f64) = (420.0, 110.0);
const PILL_SIZE: (f64, f64) = (308.0, 84.0);
const REVIEW_SIZE: (f64, f64) = (360.0, 168.0);
const MARGIN_LOGICAL: f64 = 18.0;
const PILL_MARGIN_LOGICAL: f64 = 12.0;

/// Interactive rects per window label, pushed from React on layout change; the notify wakes the idle poll at once.
#[derive(Default)]
pub struct HitRegions(pub Mutex<HashMap<String, Vec<RectPx>>>, pub tokio::sync::Notify);

/// Poll period while some overlay is interactive (about 30 Hz, so the pointer is followed smoothly).
pub const ACTIVE_POLL: Duration = Duration::from_millis(33);
/// Poll period while every overlay is click-through and empty (C9: idle costs four wake-ups a second).
pub const IDLE_POLL: Duration = Duration::from_millis(250);

/// How long the click-through loop sleeps: short while a window has hit regions or still accepts clicks.
pub fn poll_period(has_regions: bool, all_ignoring: bool) -> Duration {
    if has_regions || !all_ignoring { ACTIVE_POLL } else { IDLE_POLL }
}

/// What `create_overlay` needs from the settings.
#[derive(Clone, Copy, Debug)]
pub struct OverlaySetup {
    pub corner: Corner,
    pub content_protected: bool,
    pub caption_lines: u8,
    pub caption_size: CaptionSize,
    pub caption_width: CaptionWidth,
    pub pill_position: Option<PillPosition>,
}

impl OverlaySetup {
    /// Read when a window is built, so a rebuilt one has the current corner, size, protection and pill position.
    pub fn from_settings(settings: &Settings) -> Self {
        Self {
            corner: settings.overlay_corner,
            content_protected: settings.content_protection,
            caption_lines: settings.caption_lines,
            caption_size: settings.caption_style.size,
            caption_width: settings.caption_style.width,
            pill_position: settings.pill_position,
        }
    }
}

fn title(label: &str) -> &'static str {
    match label {
        OVERLAY => "EchoLedger captions",
        DICTATION => "EchoLedger dictation",
        PILL => "EchoLedger recording controls",
        REVIEW => "EchoLedger review",
        _ => "EchoLedger",
    }
}

/// One builder for every overlay-type window. Flags verified against tauri-2.12.5
/// `src/webview/webview_window.rs` (`focusable` 927, `content_protected` 952, `skip_taskbar` 641,
/// `always_on_top` 615, `accept_first_mouse` 962).
fn build<R: Runtime>(app: &AppHandle<R>, label: &str, size: (f64, f64), protected: bool) -> tauri::Result<WebviewWindow<R>> {
    WebviewWindowBuilder::new(app, label, WebviewUrl::App("index.html".into()))
        .title(title(label))
        .inner_size(size.0, size.1)
        .transparent(true)
        .no_redirection_bitmap(true)
        .decorations(false)
        .shadow(false)
        .resizable(false)
        .always_on_top(true)
        .skip_taskbar(true)
        .visible_on_all_workspaces(true)
        .content_protected(protected)
        .focused(false)
        .focusable(false)
        .accept_first_mouse(true)
        .visible(true)
        .build()
}

/// Builds one overlay-type window when it does not exist yet, click-through and docked or placed from the settings
/// (call from an async task — never from a sync command or an event handler).
pub fn create_overlay<R: Runtime>(app: &AppHandle<R>, label: &str, setup: OverlaySetup) -> tauri::Result<()> {
    if app.get_webview_window(label).is_some() {
        return Ok(());
    }
    let size = match label {
        OVERLAY => caption_overlay_size_for(setup.caption_lines, setup.caption_size, setup.caption_width),
        DICTATION => DICTATION_SIZE,
        PILL => PILL_SIZE,
        _ => REVIEW_SIZE,
    };
    let window = build(app, label, size, setup.content_protected)?;
    let _ = window.set_ignore_cursor_events(true);
    match label {
        OVERLAY => redock(&window, setup.corner),
        DICTATION => redock(&window, top_center_corner(setup.corner)),
        PILL => place_pill(&window, setup.pill_position),
        _ => dock_on_primary(&window, Corner::BottomRight),
    }
    Ok(())
}

fn top_center_corner(corner: Corner) -> Corner {
    match corner {
        Corner::BottomRight | Corner::TopRight => Corner::TopRight,
        Corner::BottomLeft | Corner::TopLeft => Corner::TopLeft,
    }
}

fn work_area(monitor: &Monitor) -> WorkArea {
    let wa = monitor.work_area();
    WorkArea { x: wa.position.x, y: wa.position.y, w: wa.size.width, h: wa.size.height }
}

fn margin_px(monitor: &Monitor, logical: f64) -> i32 {
    (logical * monitor.scale_factor()).round() as i32
}

/// Docks a window into `corner` of its current monitor's work area (not the full monitor: taskbar-safe).
pub fn redock<R: Runtime>(window: &WebviewWindow<R>, corner: Corner) {
    let monitor = window.current_monitor().ok().flatten().or_else(|| window.primary_monitor().ok().flatten());
    let (Some(monitor), Ok(size)) = (monitor, window.outer_size()) else { return };
    let (x, y) = dock(work_area(&monitor), (size.width, size.height), corner, margin_px(&monitor, MARGIN_LOGICAL));
    let _ = window.set_position(PhysicalPosition::new(x, y));
}

/// Docks a window into `corner` of the primary monitor's work area (the review card: bottom-right, 18 px).
fn dock_on_primary<R: Runtime>(window: &WebviewWindow<R>, corner: Corner) {
    let monitor = window.primary_monitor().ok().flatten().or_else(|| window.current_monitor().ok().flatten());
    let (Some(monitor), Ok(size)) = (monitor, window.outer_size()) else { return };
    let (x, y) = dock(work_area(&monitor), (size.width, size.height), corner, margin_px(&monitor, MARGIN_LOGICAL));
    let _ = window.set_position(PhysicalPosition::new(x, y));
}

/// Puts the pill at its saved position when that is on a connected monitor, else top-centre of the primary work
/// area with a 12 px margin.
pub fn place_pill<R: Runtime>(window: &WebviewWindow<R>, saved: Option<PillPosition>) {
    let monitors = window.available_monitors().unwrap_or_default();
    let primary = window.primary_monitor().ok().flatten().or_else(|| monitors.first().cloned());
    let (Some(primary), Ok(size)) = (primary, window.outer_size()) else { return };
    let areas: Vec<WorkArea> = monitors.iter().map(work_area).collect();
    let margin = margin_px(&primary, PILL_MARGIN_LOGICAL);
    let (x, y) = pill_origin(saved, &areas, work_area(&primary), (size.width, size.height), margin);
    let _ = window.set_position(PhysicalPosition::new(x, y));
}

/// The pill's current top-left corner, for saving after the user dragged it.
pub fn pill_position<R: Runtime>(window: &WebviewWindow<R>) -> Option<PillPosition> {
    window.outer_position().ok().map(|p| PillPosition { x: p.x, y: p.y })
}

/// Resizes the caption overlay for the line count, text size and width settings and re-docks it (never `show()`).
pub fn resize_captions<R: Runtime>(app: &AppHandle<R>, caption_lines: u8, style: &CaptionStyle, corner: Corner) {
    let Some(window) = app.get_webview_window(OVERLAY) else { return };
    let (w, h) = caption_overlay_size_for(caption_lines, style.size, style.width);
    // `set_size` and the size read back by `redock` go through the same event-loop queue, in order.
    if window.set_size(LogicalSize::new(w, h)).is_ok() {
        redock(&window, corner);
    }
}

pub fn set_content_protection<R: Runtime>(app: &AppHandle<R>, protected: bool) {
    for label in WINDOWS {
        if let Some(w) = app.get_webview_window(label) {
            let _ = w.set_content_protected(protected);
        }
    }
}

/// ~30 Hz cursor poll: a window is clickable only while the cursor is over one of its interactive regions; the
/// OS flag is changed on transitions only, plus a resync about once a second.
pub fn spawn_clickthrough_poll<R: Runtime>(app: AppHandle<R>) {
    tauri::async_runtime::spawn(async move {
        let mut ignoring: HashMap<&'static str, bool> = HashMap::new();
        let mut period = ACTIVE_POLL;
        let mut last_resync = std::time::Instant::now();
        loop {
            let regions_changed = app.state::<HitRegions>();
            tokio::select! {
                _ = tokio::time::sleep(period) => {}
                _ = regions_changed.1.notified() => {}
            }
            // Re-apply the desired state about once a second even when unchanged: the window can be put back to
            // click-through elsewhere (e.g. when hiding), and a stale cache would otherwise leave it stuck.
            let resync = last_resync.elapsed() >= Duration::from_secs(1);
            if resync {
                last_resync = std::time::Instant::now();
            }
            let regions: HashMap<&'static str, Vec<RectPx>> = match app.state::<HitRegions>().0.lock() {
                Ok(map) => WINDOWS.iter().filter_map(|l| map.get(*l).filter(|r| !r.is_empty()).map(|r| (*l, r.clone()))).collect(),
                Err(_) => HashMap::new(),
            };
            let all_ignoring = WINDOWS.iter().all(|l| ignoring.get(l) == Some(&true));
            period = poll_period(!regions.is_empty(), all_ignoring);
            // Nothing interactive and every window already click-through (or no overlay built): no cursor query, no
            // window calls.
            if (!resync && regions.is_empty() && all_ignoring) || WINDOWS.iter().all(|l| app.get_webview_window(l).is_none()) {
                continue;
            }
            let Ok(cursor) = app.cursor_position() else { continue };
            for label in WINDOWS {
                let Some(window) = app.get_webview_window(label) else {
                    ignoring.insert(label, true); // a rebuilt window starts click-through
                    continue;
                };
                let want_ignore = match regions.get(label) {
                    None => true,
                    Some(rects) => match window.inner_position() {
                        Ok(origin) => !cursor_in_regions((cursor.x, cursor.y), (origin.x, origin.y), rects),
                        Err(_) => true,
                    },
                };
                if (resync || ignoring.get(label) != Some(&want_ignore)) && window.set_ignore_cursor_events(want_ignore).is_ok() {
                    ignoring.insert(label, want_ignore);
                }
            }
        }
    });
}

/// Shows the caption overlay or the dictation box (built first when needed), or hides its content; a hidden one is
/// destroyed after the grace (lifecycle.rs).
pub fn set_overlay_visible(app: &AppHandle, label: &'static str, visible: bool) {
    if visible {
        lifecycle::show(app, label, label);
    }
    lifecycle::emit(app, label, "overlay:visibility", visible);
    if !visible {
        make_click_through(app, label);
        lifecycle::hide(app, label, label);
    }
}

fn make_click_through(app: &AppHandle, label: &str) {
    if let Ok(mut map) = app.state::<HitRegions>().0.lock() {
        map.remove(label);
    }
    if let Some(w) = app.get_webview_window(label) {
        let _ = w.set_ignore_cursor_events(true);
    }
}

/// The recording pill on or off.
pub fn set_pill_visible(app: &AppHandle, visible: bool) {
    if visible {
        lifecycle::show(app, PILL, "pill");
    }
    lifecycle::emit(app, PILL, "pill:visibility", visible);
    if !visible {
        lifecycle::hide(app, PILL, "pill");
    }
}

/// The review card (A2.2): shown with the newest undecided recording, or hidden.
pub fn show_review<S: serde::Serialize>(app: &AppHandle, payload: S) {
    lifecycle::show(app, REVIEW, "review");
    lifecycle::emit(app, REVIEW, "review:show", payload);
}

pub fn hide_review(app: &AppHandle) {
    lifecycle::emit(app, REVIEW, "review:hide", ());
    lifecycle::hide(app, REVIEW, "review");
}

/// The "ask" chip for an unrecognised call app shares the review card's window.
pub fn show_ask<S: serde::Serialize>(app: &AppHandle, payload: S) {
    lifecycle::show(app, REVIEW, "ask");
    lifecycle::emit(app, REVIEW, "ask:show", payload);
}

pub fn hide_ask(app: &AppHandle) {
    lifecycle::emit(app, REVIEW, "ask:hide", ());
    lifecycle::hide(app, REVIEW, "ask");
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_click_through_poll_slows_down_when_every_overlay_is_idle() {
        assert_eq!(poll_period(false, true), IDLE_POLL);
        assert_eq!(poll_period(true, true), ACTIVE_POLL);
        assert_eq!(poll_period(false, false), ACTIVE_POLL, "a window still accepting clicks is followed closely");
        assert!(IDLE_POLL.as_millis() >= 250 && ACTIVE_POLL.as_millis() <= 40);
    }

    #[tokio::test]
    async fn a_new_hit_region_wakes_the_poll_at_once() {
        let regions = HitRegions::default();
        regions.1.notify_one();
        tokio::time::timeout(Duration::from_millis(50), regions.1.notified()).await.expect("woken without waiting for the idle period");
    }
}
