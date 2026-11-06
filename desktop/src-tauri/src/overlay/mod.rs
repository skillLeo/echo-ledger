//! Overlay-type windows: caption overlay, dictation box, recording pill and the after-call window (the "Saved · Undo"
//! toast, the ask-me chip and the unknown-app chip, label `review`). Built on first use and
//! destroyed after they have been hidden for a while (lifecycle.rs, C9), always visible, unfocused and unfocusable
//! (never `show()` or `set_focus()` — tao ≥ 0.37.1 would steal focus; tao builds them with `WS_EX_NOACTIVATE` and
//! `SW_SHOWNOACTIVATE`, so one built mid-call never takes focus either); "hidden" = the view renders nothing and
//! reports no hit regions, so the window is fully click-through. docs/research/desktop-tauri.md §2.

pub mod geometry;
#[cfg(target_os = "macos")]
pub mod macos;

use std::collections::HashMap;
use std::sync::Mutex;
use std::time::Duration;

use tauri::{AppHandle, LogicalSize, Manager, Runtime, WebviewUrl, WebviewWindow, WebviewWindowBuilder};

use crate::lifecycle;
use crate::settings::{CaptionSize, CaptionStyle, CaptionWidth, Settings};
use geometry::{Corner, PillPosition, RectPx, WorkArea, area_for, caption_overlay_size_for, cursor_in_regions_scaled, dock, nearest_corner, pill_origin, rescue};

pub const OVERLAY: &str = "overlay";
pub const DICTATION: &str = "dictation";
pub const PILL: &str = "pill";
pub const REVIEW: &str = "review";

/// Every overlay-type window: same builder flags, click-through unless the cursor is over a reported region.
pub const WINDOWS: [&str; 4] = [OVERLAY, DICTATION, PILL, REVIEW];

const DICTATION_SIZE: (f64, f64) = (420.0, 110.0);
const PILL_SIZE: (f64, f64) = (352.0, 84.0);
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

/// How often the built overlays are checked for being off screen (a display unplugged).
pub const SCREEN_CHECK: Duration = Duration::from_secs(5);

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
        crate::translate::WINDOW => "EchoLedger Live Translate",
        DICTATION => "EchoLedger dictation",
        PILL => "EchoLedger recording controls",
        REVIEW => "EchoLedger after a call",
        _ => "EchoLedger",
    }
}

/// One builder for every overlay-type window. Flags verified against tauri-2.12.5
/// `src/webview/webview_window.rs` (`focusable` 927, `content_protected` 952, `skip_taskbar` 641,
/// `always_on_top` 615, `accept_first_mouse` 962).
fn build<R: Runtime>(app: &AppHandle<R>, label: &str, size: (f64, f64), protected: bool) -> tauri::Result<WebviewWindow<R>> {
    crate::smoke::isolate(WebviewWindowBuilder::new(app, label, WebviewUrl::App("index.html".into())))
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
    #[cfg(target_os = "macos")]
    macos::keep_above_everything(&window);
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

/// The displays' usable areas, in the units windows are placed in: physical pixels on Windows, points on a Mac (see
/// `geometry`). Each with its scale, for the margins.
struct Desk {
    areas: Vec<(WorkArea, f64)>,
    primary: usize,
}

impl Desk {
    #[cfg(target_os = "macos")]
    fn read<R: Runtime>(window: &WebviewWindow<R>) -> Option<Self> {
        let screens = macos::screens(window.app_handle());
        let primary_height = screens.first()?.frame.h;
        Some(Self { areas: screens.iter().map(|s| (geometry::mac_area(s, primary_height), 1.0)).collect(), primary: 0 })
    }

    #[cfg(not(target_os = "macos"))]
    fn read<R: Runtime>(window: &WebviewWindow<R>) -> Option<Self> {
        let area = |monitor: &tauri::Monitor| {
            let wa = monitor.work_area();
            (WorkArea { x: wa.position.x, y: wa.position.y, w: wa.size.width, h: wa.size.height }, monitor.scale_factor())
        };
        let primary = window.primary_monitor().ok().flatten();
        let mut areas: Vec<(WorkArea, f64)> = window.available_monitors().unwrap_or_default().iter().map(area).collect();
        if areas.is_empty() {
            areas.push(area(primary.as_ref()?));
        }
        let at = primary.as_ref().and_then(|m| {
            let wanted = area(m).0;
            areas.iter().position(|(a, _)| *a == wanted)
        });
        Some(Self { areas, primary: at.unwrap_or(0) })
    }

    fn rects(&self) -> Vec<WorkArea> {
        self.areas.iter().map(|(a, _)| *a).collect()
    }

    fn primary(&self) -> (WorkArea, f64) {
        self.areas[self.primary]
    }

    /// The display a window at this position is on.
    fn holding(&self, point: (i32, i32)) -> (WorkArea, f64) {
        area_for(&self.rects(), point).map_or_else(|| self.primary(), |at| self.areas[at])
    }
}

fn margin_units(scale: f64, logical: f64) -> i32 {
    (logical * scale).round() as i32
}

/// A window's outer position and size in the units of [`Desk`].
#[cfg(target_os = "macos")]
fn frame_of<R: Runtime>(window: &WebviewWindow<R>) -> Option<((i32, i32), (u32, u32))> {
    let scale = window.scale_factor().ok()?;
    let (position, size) = (window.outer_position().ok()?, window.outer_size().ok()?);
    let points = |v: f64| (v / scale).round();
    Some(((points(f64::from(position.x)) as i32, points(f64::from(position.y)) as i32), (points(f64::from(size.width)) as u32, points(f64::from(size.height)) as u32)))
}

#[cfg(not(target_os = "macos"))]
fn frame_of<R: Runtime>(window: &WebviewWindow<R>) -> Option<((i32, i32), (u32, u32))> {
    let (position, size) = (window.outer_position().ok()?, window.outer_size().ok()?);
    Some(((position.x, position.y), (size.width, size.height)))
}

fn put<R: Runtime>(window: &WebviewWindow<R>, at: (i32, i32)) {
    #[cfg(target_os = "macos")]
    let _ = window.set_position(tauri::LogicalPosition::new(f64::from(at.0), f64::from(at.1)));
    #[cfg(not(target_os = "macos"))]
    let _ = window.set_position(tauri::PhysicalPosition::new(at.0, at.1));
}

fn centre(position: (i32, i32), size: (u32, u32)) -> (i32, i32) {
    (position.0 + size.0 as i32 / 2, position.1 + size.1 as i32 / 2)
}

/// Docks a window into `corner` of its current monitor's work area (not the full monitor: taskbar-safe; on a Mac clear of
/// the menu bar, the notch and the Dock).
pub fn redock<R: Runtime>(window: &WebviewWindow<R>, corner: Corner) {
    let (Some(desk), Some((position, size))) = (Desk::read(window), frame_of(window)) else { return };
    let (area, scale) = desk.holding(centre(position, size));
    put(window, dock(area, size, corner, margin_units(scale, MARGIN_LOGICAL)));
}

/// The corner nearest to where the person dragged a window.
pub fn nearest_corner_of<R: Runtime>(window: &WebviewWindow<R>) -> Option<Corner> {
    let desk = Desk::read(window)?;
    let (position, size) = frame_of(window)?;
    let (area, _) = desk.holding(centre(position, size));
    Some(nearest_corner(area, position, size))
}

/// Docks a window into `corner` of the primary monitor's work area (the after-call window: bottom-right, near the
/// tray, 18 px).
fn dock_on_primary<R: Runtime>(window: &WebviewWindow<R>, corner: Corner) {
    let (Some(desk), Some((_, size))) = (Desk::read(window), frame_of(window)) else { return };
    let (area, scale) = desk.primary();
    put(window, dock(area, size, corner, margin_units(scale, MARGIN_LOGICAL)));
}

/// The Live Translate window (v1.3): top-centre for a game's voice chat, bottom-centre for a video's subtitles, on
/// the primary monitor's work area; click-through until the person unlocks it (Ctrl+Shift+T). Never focused.
pub fn create_translate_window<R: Runtime>(app: &AppHandle<R>, subtitles: bool) -> tauri::Result<()> {
    if app.get_webview_window(crate::translate::WINDOW).is_some() {
        return Ok(());
    }
    let size = if subtitles { (900.0, 140.0) } else { (560.0, 132.0) };
    let window = build(app, crate::translate::WINDOW, size, false)?;
    #[cfg(target_os = "macos")]
    macos::keep_above_everything(&window);
    let _ = window.set_ignore_cursor_events(true);
    if let (Some(desk), Some((_, outer))) = (Desk::read(&window), frame_of(&window)) {
        let (area, scale) = desk.primary();
        let margin = margin_units(scale, if subtitles { 96.0 } else { 16.0 });
        let x = area.x + (area.w as i32 - outer.0 as i32) / 2;
        let y = if subtitles { area.y + area.h as i32 - outer.1 as i32 - margin } else { area.y + margin };
        put(&window, (x, y));
    }
    Ok(())
}

/// Puts the pill at its saved position when that is on a connected monitor, else top-centre of the primary work
/// area with a 12 px margin.
pub fn place_pill<R: Runtime>(window: &WebviewWindow<R>, saved: Option<PillPosition>) {
    let (Some(desk), Some((_, size))) = (Desk::read(window), frame_of(window)) else { return };
    let (primary, scale) = desk.primary();
    put(window, pill_origin(saved, &desk.rects(), primary, size, margin_units(scale, PILL_MARGIN_LOGICAL)));
}

/// The pill's current top-left corner, for saving after the user dragged it.
pub fn pill_position<R: Runtime>(window: &WebviewWindow<R>) -> Option<PillPosition> {
    frame_of(window).map(|((x, y), _)| PillPosition { x, y })
}

/// Moves overlays back on screen after a display went away or moved. Asked about every few seconds while one is built.
fn keep_on_screen<R: Runtime>(app: &AppHandle<R>) {
    let Some(first) = WINDOWS.iter().find_map(|label| app.get_webview_window(label)) else { return };
    let Some(desk) = Desk::read(&first) else { return };
    let rects = desk.rects();
    for label in WINDOWS {
        let Some(window) = app.get_webview_window(label) else { continue };
        if let Some((position, size)) = frame_of(&window)
            && let Some(at) = rescue(&rects, position, size)
        {
            eprintln!("[echoledger] the {label} window was off screen: moved back");
            put(&window, at);
        }
    }
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
        let mut last_screen_check = std::time::Instant::now();
        // On a Mac the cursor comes scaled by the primary display, a window by its own (`cursor_in_regions_scaled`).
        let mut cursor_scale = 1.0;
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
            if resync && cfg!(target_os = "macos") {
                cursor_scale = app.primary_monitor().ok().flatten().map_or(cursor_scale, |m| m.scale_factor());
            }
            if last_screen_check.elapsed() >= SCREEN_CHECK {
                last_screen_check = std::time::Instant::now();
                keep_on_screen(&app);
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
                        Ok(origin) => {
                            let window_scale = if cfg!(target_os = "macos") { window.scale_factor().unwrap_or(1.0) } else { 1.0 };
                            !cursor_in_regions_scaled((cursor.x, cursor.y), cursor_scale, (origin.x, origin.y), window_scale, rects)
                        }
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

/// How long the after-call window stays once it has nothing left to show (v1.5): long enough for the toast or chip
/// to leave (150 ms) and for a kept chip's check (0.9 s) before the window goes.
const AFTER_CALL_GRACE: Duration = Duration::from_millis(1200);
/// Counts what the after-call window was asked to show, so only the last empty state hides it.
static AFTER_CALL_SHOWN: std::sync::atomic::AtomicU64 = std::sync::atomic::AtomicU64::new(0);

/// Whether the empty state numbered `asked` is still the last one shown when its grace is over.
fn still_empty(asked: u64) -> bool {
    AFTER_CALL_SHOWN.load(std::sync::atomic::Ordering::SeqCst) == asked
}

/// The after-call toast and chip (v1.5): the window shows `state`; with nothing in it the window goes after
/// [AFTER_CALL_GRACE], unless something new is shown first.
pub fn show_after_call(app: &AppHandle, state: &crate::after_call::AfterCallState) {
    let asked = AFTER_CALL_SHOWN.fetch_add(1, std::sync::atomic::Ordering::SeqCst) + 1;
    if state.is_empty() {
        lifecycle::emit(app, REVIEW, "aftercall:state", state);
        let app = app.clone();
        tauri::async_runtime::spawn(async move {
            tokio::time::sleep(AFTER_CALL_GRACE).await;
            if still_empty(asked) {
                lifecycle::hide(&app, REVIEW, "after_call");
            }
        });
    } else {
        lifecycle::show(app, REVIEW, "after_call");
        lifecycle::emit(app, REVIEW, "aftercall:state", state);
    }
}

/// The "ask" chip for an unrecognised call app shares the after-call window.
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
    fn only_the_last_empty_after_call_state_hides_the_window() {
        let first = AFTER_CALL_SHOWN.fetch_add(1, std::sync::atomic::Ordering::SeqCst) + 1;
        assert!(still_empty(first));
        // Something new was shown during the grace: the old empty state no longer hides the window.
        let second = AFTER_CALL_SHOWN.fetch_add(1, std::sync::atomic::Ordering::SeqCst) + 1;
        assert!(!still_empty(first));
        assert!(still_empty(second));
        assert!(AFTER_CALL_GRACE >= Duration::from_millis(900 + 150), "room for the kept check and the exit");
    }

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
