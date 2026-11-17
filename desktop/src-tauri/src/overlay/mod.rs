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
use geometry::{Corner, PillPosition, RectPx, Spot, WorkArea, area_for, caption_overlay_size_for, cursor_in_regions_scaled, dock, nearest_corner, pill_origin_scaled, rescue_scaled, scale_or_last, settled, size_at, spot_on};

pub const OVERLAY: &str = "overlay";
pub const DICTATION: &str = "dictation";
pub const PILL: &str = "pill";
pub const REVIEW: &str = "review";

/// Every overlay-type window: same builder flags, click-through unless the cursor is over a reported region.
pub const WINDOWS: [&str; 5] = [OVERLAY, DICTATION, PILL, REVIEW, ASSIST];
/// The windows kept on screen when a display goes away or moves (`keep_on_screen`): the overlays and Live Translate,
/// whose clicks it locks and unlocks itself, so it stays out of the click-through poll's [`WINDOWS`].
pub const RESCUED: [&str; 6] = [OVERLAY, DICTATION, PILL, REVIEW, ASSIST, crate::translate::WINDOW];
/// The Live Assist card (the privacy service's name for it).
pub const ASSIST: &str = crate::privacy::ASSIST;

const DICTATION_SIZE: (f64, f64) = (420.0, 110.0);
pub const PILL_SIZE: (f64, f64) = (352.0, 84.0);
pub const REVIEW_SIZE: (f64, f64) = (360.0, 168.0);
/// The pill and the after-call card grow with the system's text size (D-141), up to twice their size.
pub const TEXT_SCALE_MAX: f64 = 2.0;

/// A window's size for the system's text size (pure): `base` at 100 %, grown in proportion, never past
/// [`TEXT_SCALE_MAX`] times `base`; an unreadable scale is 100 %.
pub fn scaled_size(base: (f64, f64), text_scale: f64) -> (f64, f64) {
    let scale = if text_scale.is_finite() { text_scale.clamp(1.0, TEXT_SCALE_MAX) } else { 1.0 };
    ((base.0 * scale).round(), (base.1 * scale).round())
}

/// Whether a window `current` (logical) is to change to `wanted` (pure): more than a pixel apart either way.
pub fn needs_resize(current: (f64, f64), wanted: (f64, f64)) -> bool {
    (current.0 - wanted.0).abs() > 1.0 || (current.1 - wanted.1).abs() > 1.0
}

/// The pill or the after-call card at the size for the system's text size now (D-141). Read again each time one is
/// shown and at every screen check while one is built (`keep_on_screen`), so a change in Settings reaches it without a
/// restart. The pill keeps its centre; the card stays docked bottom-right.
pub fn fit_to_text_size<R: Runtime>(app: &AppHandle<R>, label: &str) {
    let base = match label {
        PILL => PILL_SIZE,
        REVIEW => REVIEW_SIZE,
        _ => return,
    };
    let Some(window) = app.get_webview_window(label) else { return };
    let (Ok(scale), Ok(inner)) = (window.scale_factor(), window.inner_size()) else { return };
    let current = (f64::from(inner.width) / scale, f64::from(inner.height) / scale);
    let wanted = scaled_size(base, text_scale());
    if !needs_resize(current, wanted) {
        return;
    }
    let before = frame_of(&window);
    // `set_size` and the frame read back for docking go through the same event-loop queue, in order.
    if window.set_size(LogicalSize::new(wanted.0, wanted.1)).is_err() {
        return;
    }
    if label == REVIEW {
        dock_on_primary(&window, Corner::BottomRight);
    } else if let Some(((x, y), _)) = before {
        // Frames are in physical pixels on Windows, in points on a Mac.
        let unit = if cfg!(target_os = "macos") { 1.0 } else { scale };
        put(&window, (x - ((wanted.0 - current.0) / 2.0 * unit).round() as i32, y));
    }
}

/// [`fit_to_text_size`] on a task of its own: it waits for the main thread, and a window is shown from places that
/// may hold a lock.
fn fit_to_text_size_later(app: &AppHandle, label: &'static str) {
    let app = app.clone();
    tauri::async_runtime::spawn(async move { fit_to_text_size(&app, label) });
}

/// The system's text size as a factor: Windows' "Make text bigger" (Accessibility › Text size, 100–225 %); 1.0 on a
/// Mac, whose windows follow the display's scale instead.
pub fn text_scale() -> f64 {
    #[cfg(windows)]
    {
        use windows::Win32::System::Registry::{HKEY_CURRENT_USER, RRF_RT_REG_DWORD, RegGetValueW};
        use windows::core::{HSTRING, PCWSTR};
        let mut value: u32 = 100;
        let mut size = std::mem::size_of::<u32>() as u32;
        let status = unsafe {
            RegGetValueW(
                HKEY_CURRENT_USER,
                &HSTRING::from(r"Software\Microsoft\Accessibility"),
                PCWSTR(HSTRING::from("TextScaleFactor").as_ptr()),
                RRF_RT_REG_DWORD,
                None,
                Some((&mut value as *mut u32).cast()),
                Some(&mut size),
            )
        };
        if status.is_ok() { f64::from(value) / 100.0 } else { 1.0 }
    }
    #[cfg(not(windows))]
    {
        1.0
    }
}
/// The Live Assist card's window: the card (at most 512 px wide) and room for a long suggestion; outside the card it
/// lets clicks through.
const ASSIST_SIZE: (f64, f64) = (560.0, 440.0);
const MARGIN_LOGICAL: f64 = 18.0;
const PILL_MARGIN_LOGICAL: f64 = 12.0;

/// Interactive rects per window label in CSS px, pushed from React on layout change (made physical at the window's
/// scale at each poll); the notify wakes the idle poll at once.
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
    pub caption_lines: u8,
    pub caption_size: CaptionSize,
    pub caption_width: CaptionWidth,
    pub pill_position: Option<PillPosition>,
    pub assist_card_position: Option<PillPosition>,
}

impl OverlaySetup {
    /// Read when a window is built, so a rebuilt one has the current corner, size and pill position (its capture flag
    /// comes from the privacy service).
    pub fn from_settings(settings: &Settings) -> Self {
        Self {
            corner: settings.overlay_corner,
            caption_lines: settings.caption_lines,
            caption_size: settings.caption_style.size,
            caption_width: settings.caption_style.width,
            pill_position: settings.pill_position,
            assist_card_position: settings.assist_card_position,
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
        ASSIST => "EchoLedger Live Assist",
        _ => "EchoLedger",
    }
}

/// One builder for every overlay-type window. Flags verified against tauri-2.12.5
/// `src/webview/webview_window.rs` (`focusable` 927, `content_protected` 952, `skip_taskbar` 641,
/// `always_on_top` 615, `accept_first_mouse` 962). The capture flag is set by the privacy service before the window is
/// first shown and checked once it is built (`privacy::window`). On a Mac it is built on the main thread as a
/// non-activating panel from the start (`macos::build_on_main_as_panel`): once its content is in, AppKit and WebKit
/// observe the window, and its class can no longer change.
fn build<R: Runtime>(app: &AppHandle<R>, label: &str, size: (f64, f64)) -> tauri::Result<WebviewWindow<R>> {
    #[cfg(target_os = "macos")]
    {
        let (app, label) = (app.clone(), label.to_string());
        let on_main = app.clone();
        macos::build_on_main_as_panel(&on_main, move || build_window(&app, &label, size)).unwrap_or(Err(tauri::Error::FailedToReceiveMessage))
    }
    #[cfg(not(target_os = "macos"))]
    build_window(app, label, size)
}

fn build_window<R: Runtime>(app: &AppHandle<R>, label: &str, size: (f64, f64)) -> tauri::Result<WebviewWindow<R>> {
    let builder = crate::smoke::isolate(WebviewWindowBuilder::new(app, label, WebviewUrl::App("index.html".into())))
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
        .focused(false)
        .focusable(false)
        .accept_first_mouse(true)
        .visible(true);
    crate::privacy::window(app, builder, label, crate::privacy::Kind::Floating, |b| b)
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
        PILL => scaled_size(PILL_SIZE, text_scale()),
        ASSIST => ASSIST_SIZE,
        _ => scaled_size(REVIEW_SIZE, text_scale()),
    };
    let window = build(app, label, size)?;
    #[cfg(target_os = "macos")]
    macos::keep_above_everything(&window);
    let _ = window.set_ignore_cursor_events(true);
    match label {
        OVERLAY => redock(&window, setup.corner),
        DICTATION => redock(&window, top_center_corner(setup.corner)),
        PILL => place_pill(&window, setup.pill_position),
        // Top-centre, under the webcam, until the person drags it elsewhere (L4).
        ASSIST => place_pill(&window, setup.assist_card_position),
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

/// The scale of a window's frame in [`Desk`]'s units: the scale of the monitor it is on (Windows, per-monitor DPI v2:
/// physical px), 1 on a Mac (points everywhere).
fn frame_scale<R: Runtime>(window: &WebviewWindow<R>) -> f64 {
    if cfg!(target_os = "macos") { 1.0 } else { scale_of(window) }
}

/// Where each window was at the last screen check: one that moved since is not rescued that round (`settled`).
static LAST_FRAME: Mutex<Option<HashMap<String, (i32, i32)>>> = Mutex::new(None);

/// The last scale read for each window, for when a read fails (the window is being moved or rebuilt).
static LAST_SCALE: Mutex<Option<HashMap<String, f64>>> = Mutex::new(None);

/// A window's scale now (its monitor's), else the last one read for it, else 1.
fn scale_of<R: Runtime>(window: &WebviewWindow<R>) -> f64 {
    let read = window.scale_factor().ok();
    let mut last = LAST_SCALE.lock().unwrap_or_else(|p| p.into_inner());
    let map = last.get_or_insert_with(HashMap::new);
    let scale = scale_or_last(read, map.get(window.label()).copied());
    map.insert(window.label().to_string(), scale);
    scale
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
    // Built on another display than the primary, the window takes the primary's scale once there.
    put(window, dock(area, size_at(size, frame_scale(window), scale), corner, margin_units(scale, MARGIN_LOGICAL)));
}

/// The Live Translate window (v1.3; hidden from screen sharing like the other floating windows since v1.8.1): top-centre for a game's voice chat, bottom-centre for a video's subtitles, on
/// the primary monitor's work area; click-through until the person unlocks it (Ctrl+Shift+T). Never focused.
pub fn create_translate_window<R: Runtime>(app: &AppHandle<R>, subtitles: bool) -> tauri::Result<()> {
    if app.get_webview_window(crate::translate::WINDOW).is_some() {
        return Ok(());
    }
    let size = if subtitles { (900.0, 140.0) } else { (560.0, 132.0) };
    let window = build(app, crate::translate::WINDOW, size)?;
    #[cfg(target_os = "macos")]
    macos::keep_above_everything(&window);
    let _ = window.set_ignore_cursor_events(true);
    if let (Some(desk), Some((_, outer))) = (Desk::read(&window), frame_of(&window)) {
        let (area, scale) = desk.primary();
        // At its size on the primary display, which may have another scale than the one it was built on.
        let (spot, margin) = if subtitles { (Spot::BottomCentre, 96.0) } else { (Spot::TopCentre, 16.0) };
        put(&window, spot_on(area, scale, outer, frame_scale(&window), spot, margin));
    }
    Ok(())
}

/// The floating windows "Move to the other display" moves (the dictation box, kept built while idle, goes along when the
/// others do, but alone it is nothing to move).
const MOVABLE: [&str; 4] = [OVERLAY, PILL, REVIEW, crate::translate::WINDOW];

/// Whether one of the floating windows is open (Settings offers the move only then).
pub fn floating_open<R: Runtime>(app: &AppHandle<R>) -> bool {
    MOVABLE.iter().any(|l| app.get_webview_window(l).is_some())
}

/// The display to move to (pure): the first that is not the main one, whatever order the system lists them in.
pub fn other_display(displays: usize, primary: usize) -> Option<usize> {
    (0..displays).find(|i| *i != primary)
}

/// Moves every floating window to the first display that is not the main one (Screen-share privacy: share the main
/// display, keep EchoLedger on the other): the captions to their corner, the recording controls to the top centre, the
/// others to the bottom right. The error says why nothing moved.
pub fn move_to_other_display<R: Runtime>(app: &AppHandle<R>) -> Result<(), String> {
    let labels = [OVERLAY, DICTATION, PILL, REVIEW, crate::translate::WINDOW];
    if !floating_open(app) {
        return Err("Nothing to move yet: the recording controls, captions and Live Translate move once they are open.".into());
    }
    let Some(first) = labels.iter().find_map(|l| app.get_webview_window(l)) else { return Err("Nothing to move yet.".into()) };
    let desk = Desk::read(&first).ok_or("The displays could not be read.")?;
    let index = other_display(desk.areas.len(), desk.primary).ok_or("Only one display is connected now.")?;
    let (area, scale) = desk.areas[index];
    let corner = app.try_state::<crate::AppState>().map_or(Corner::BottomRight, |s| s.settings().overlay_corner);
    for label in labels {
        let Some(window) = app.get_webview_window(label) else { continue };
        let Some((_, size)) = frame_of(&window) else { continue };
        let (spot, margin) = match label {
            PILL => (Spot::TopCentre, PILL_MARGIN_LOGICAL),
            OVERLAY => (Spot::Corner(corner), MARGIN_LOGICAL),
            DICTATION => (Spot::Corner(top_center_corner(corner)), MARGIN_LOGICAL),
            _ => (Spot::Corner(Corner::BottomRight), MARGIN_LOGICAL),
        };
        // At the size it will have on that display, which may have another scale than the one it leaves.
        put(&window, spot_on(area, scale, size, frame_scale(&window), spot, margin));
    }
    Ok(())
}

/// Puts the pill at its saved position when that is on a connected monitor, else top-centre of the primary work
/// area with a 12 px margin.
pub fn place_pill<R: Runtime>(window: &WebviewWindow<R>, saved: Option<PillPosition>) {
    let (Some(desk), Some((_, size))) = (Desk::read(window), frame_of(window)) else { return };
    let window_scale = frame_scale(window);
    if let Some(at) = pill_origin_scaled(saved, &desk.areas, desk.primary, size, window_scale, PILL_MARGIN_LOGICAL) {
        eprintln!("[echoledger] placing {} at {at:?} ({} display(s); built at scale {window_scale})", window.label(), desk.areas.len());
        put(window, at);
    }
}

/// The pill's current top-left corner, for saving after the user dragged it.
pub fn pill_position<R: Runtime>(window: &WebviewWindow<R>) -> Option<PillPosition> {
    frame_of(window).map(|((x, y), _)| PillPosition { x, y })
}

/// Moves overlays back on screen after a display went away or moved. Asked about every few seconds while one is built.
fn keep_on_screen<R: Runtime>(app: &AppHandle<R>) {
    let Some(first) = RESCUED.iter().find_map(|label| app.get_webview_window(label)) else { return };
    let Some(desk) = Desk::read(&first) else { return };
    for label in RESCUED {
        fit_to_text_size(app, label);
        let Some(window) = app.get_webview_window(label) else { continue };
        let Some((position, size)) = frame_of(&window) else { continue };
        // Read before the lock is taken: nothing waits for the main thread while it is held.
        let still = {
            let mut seen = LAST_FRAME.lock().unwrap_or_else(|p| p.into_inner());
            settled(seen.get_or_insert_with(HashMap::new).insert(label.to_string(), position), position)
        };
        if still
            && let Some(at) = rescue_scaled(&desk.areas, position, size, frame_scale(&window))
        {
            eprintln!("[echoledger] the {label} window was off screen at {position:?}: moved back to {at:?}");
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
            // Live Translate open alone still needs the screen check, so it counts here (never in the click-through below).
            if (!resync && regions.is_empty() && all_ignoring) || RESCUED.iter().all(|l| app.get_webview_window(l).is_none()) {
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
                            // The window's scale now: dragged onto a monitor with another one (per-monitor DPI on
                            // Windows), its regions follow at once, before the page measures them again.
                            let scale = scale_of(&window);
                            let physical = geometry::scale_regions(rects, scale);
                            // A Mac's cursor and window are brought back to points; Windows' are physical already.
                            let window_scale = if cfg!(target_os = "macos") { scale } else { 1.0 };
                            !cursor_in_regions_scaled((cursor.x, cursor.y), cursor_scale, (origin.x, origin.y), window_scale, &physical)
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
        fit_to_text_size_later(app, PILL);
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
        fit_to_text_size_later(app, REVIEW);
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
    fn live_translate_is_kept_on_screen_but_left_out_of_the_click_through_poll() {
        assert!(RESCUED.contains(&crate::translate::WINDOW), "moved back after a display went away");
        assert!(!WINDOWS.contains(&crate::translate::WINDOW), "its clicks stay locked and unlocked by Live Translate alone");
        assert!(WINDOWS.iter().all(|w| RESCUED.contains(w)), "every overlay is still rescued");
    }

    #[test]
    fn the_pill_and_the_after_call_card_grow_with_the_text_size_up_to_twice() {
        assert_eq!(scaled_size(PILL_SIZE, 1.0), PILL_SIZE, "100 %");
        assert_eq!(scaled_size(REVIEW_SIZE, 1.0), REVIEW_SIZE);
        assert_eq!(scaled_size(PILL_SIZE, 2.0), (704.0, 168.0), "200 %");
        assert_eq!(scaled_size(REVIEW_SIZE, 2.0), (720.0, 336.0));
        assert_eq!(scaled_size(REVIEW_SIZE, 1.5), (540.0, 252.0));
        assert_eq!(scaled_size(REVIEW_SIZE, 2.25), (720.0, 336.0), "Windows goes to 225 %: the card stops at twice");
        assert_eq!(scaled_size(PILL_SIZE, 0.5), PILL_SIZE, "never smaller than at 100 %");
        assert_eq!(scaled_size(PILL_SIZE, f64::NAN), PILL_SIZE);
    }

    #[test]
    fn a_text_size_changed_in_settings_resizes_a_shown_window_once() {
        let at_100 = scaled_size(PILL_SIZE, 1.0);
        assert!(needs_resize(at_100, scaled_size(PILL_SIZE, 1.5)), "the text grew while it was shown");
        assert!(!needs_resize(scaled_size(PILL_SIZE, 1.5), scaled_size(PILL_SIZE, 1.5)), "then left alone at each check");
        assert!(!needs_resize((352.4, 83.6), at_100), "a scale's rounding is no change");
        assert!(needs_resize(scaled_size(REVIEW_SIZE, 2.0), scaled_size(REVIEW_SIZE, 1.0)), "and back when it shrank");
    }

    #[test]
    fn the_other_display_is_the_first_that_is_not_the_main_one() {
        assert_eq!(other_display(2, 0), Some(1));
        assert_eq!(other_display(2, 1), Some(0), "Windows may list the main display second: never move onto it");
        assert_eq!(other_display(3, 1), Some(0));
        assert_eq!(other_display(1, 0), None, "one display: nowhere to move");
    }

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
