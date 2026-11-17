//! Pure overlay geometry (unit-tested): click-through hit regions and work-area docking.
//! On Windows all values are physical pixels. On a Mac they are points: its physical positions depend on the scale of
//! the window asking (a laptop at 2x beside a monitor at 1x), so only points add up across displays (see `mac_area`).
//! Desktop coordinates may be negative (monitors left/above the primary).

use serde::{Deserialize, Serialize};

use crate::settings::{CaptionSize, CaptionWidth};

/// Interactive region reported by React in physical px relative to the window's client area.
#[derive(Clone, Copy, Debug, PartialEq, Deserialize, Serialize)]
pub struct RectPx {
    pub x: f64,
    pub y: f64,
    pub w: f64,
    pub h: f64,
}

/// True when the cursor (desktop px) is over any interactive region of a window whose client area starts at `origin`.
pub fn cursor_in_regions(cursor: (f64, f64), origin: (i32, i32), regions: &[RectPx]) -> bool {
    let (lx, ly) = (cursor.0 - origin.0 as f64, cursor.1 - origin.1 as f64);
    regions.iter().any(|r| r.w > 0.0 && r.h > 0.0 && lx >= r.x && lx < r.x + r.w && ly >= r.y && ly < r.y + r.h)
}

/// [`cursor_in_regions`] for positions that were made physical at different scales: on a Mac the cursor is scaled by the
/// primary display, a window and its regions by the display it is on. Everything is brought back to points first.
pub fn cursor_in_regions_scaled(cursor: (f64, f64), cursor_scale: f64, origin: (i32, i32), window_scale: f64, regions: &[RectPx]) -> bool {
    let (cx, cy) = (cursor.0 / cursor_scale, cursor.1 / cursor_scale);
    let (lx, ly) = (cx - origin.0 as f64 / window_scale, cy - origin.1 as f64 / window_scale);
    regions.iter().any(|r| {
        let (x, y, w, h) = (r.x / window_scale, r.y / window_scale, r.w / window_scale, r.h / window_scale);
        w > 0.0 && h > 0.0 && lx >= x && lx < x + w && ly >= y && ly < y + h
    })
}

/// Regions multiplied by `by` (pure): a window's regions are kept in CSS px and made physical at the scale the window
/// has now, so a move to a monitor with another scale (per-monitor DPI on Windows) never leaves them stale. A scale
/// that is not a positive number counts as 1.
pub fn scale_regions(regions: &[RectPx], by: f64) -> Vec<RectPx> {
    let by = if by.is_finite() && by > 0.0 { by } else { 1.0 };
    regions.iter().map(|r| RectPx { x: r.x * by, y: r.y * by, w: r.w * by, h: r.h * by }).collect()
}

#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize, Deserialize, Default)]
#[serde(rename_all = "snake_case")]
pub enum Corner {
    TopLeft,
    TopRight,
    BottomLeft,
    #[default]
    BottomRight,
}

/// Monitor work area (excludes taskbar/dock), physical px.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct WorkArea {
    pub x: i32,
    pub y: i32,
    pub w: u32,
    pub h: u32,
}

/// Top-left position that docks a window of `size` into `corner` with `margin` px from the work-area edges.
pub fn dock(area: WorkArea, size: (u32, u32), corner: Corner, margin: i32) -> (i32, i32) {
    let right = area.x + area.w as i32 - size.0 as i32 - margin;
    let bottom = area.y + area.h as i32 - size.1 as i32 - margin;
    let left = area.x + margin;
    let top = area.y + margin;
    let (x, y) = match corner {
        Corner::TopLeft => (left, top),
        Corner::TopRight => (right, top),
        Corner::BottomLeft => (left, bottom),
        Corner::BottomRight => (right, bottom),
    };
    // Never place outside the work area when the window is larger than the area.
    (x.max(area.x), y.max(area.y))
}

/// Corner nearest to the window's center — used to snap after the user drags the overlay.
pub fn nearest_corner(area: WorkArea, pos: (i32, i32), size: (u32, u32)) -> Corner {
    let cx = pos.0 + size.0 as i32 / 2;
    let cy = pos.1 + size.1 as i32 / 2;
    let mid_x = area.x + area.w as i32 / 2;
    let mid_y = area.y + area.h as i32 / 2;
    match (cx < mid_x, cy < mid_y) {
        (true, true) => Corner::TopLeft,
        (false, true) => Corner::TopRight,
        (true, false) => Corner::BottomLeft,
        (false, false) => Corner::BottomRight,
    }
}

/// Whether a point lies inside a work area (picks the monitor that hosts the overlay).
pub fn contains(area: WorkArea, point: (i32, i32)) -> bool {
    point.0 >= area.x && point.0 < area.x + area.w as i32 && point.1 >= area.y && point.1 < area.y + area.h as i32
}

/// Saved top-left corner of the recording pill, physical desktop px (`Settings.pillPosition`).
#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize, Deserialize)]
pub struct PillPosition {
    pub x: i32,
    pub y: i32,
}

/// Top-left position that centres a window of `size` horizontally at the top of the work area, `margin` px down.
pub fn top_center(area: WorkArea, size: (u32, u32), margin: i32) -> (i32, i32) {
    let x = area.x + (area.w as i32 - size.0 as i32) / 2;
    (x.max(area.x), area.y + margin)
}

/// Moves a window of `size` at `pos` the least distance needed to lie inside the work area (top-left wins when
/// the window is larger than the area).
pub fn clamp_into(area: WorkArea, pos: (i32, i32), size: (u32, u32)) -> (i32, i32) {
    let max_x = area.x + area.w as i32 - size.0 as i32;
    let max_y = area.y + area.h as i32 - size.1 as i32;
    (pos.0.min(max_x).max(area.x), pos.1.min(max_y).max(area.y))
}

/// Where the recording pill goes: the saved position when its top-left lies in a current monitor's work area
/// (kept fully inside that area), else top-centre of the primary work area `margin` px from the top. A saved
/// position on a monitor that is no longer connected falls back, so the pill never opens off screen.
pub fn pill_origin(saved: Option<PillPosition>, areas: &[WorkArea], primary: WorkArea, size: (u32, u32), margin: i32) -> (i32, i32) {
    saved
        .and_then(|p| areas.iter().find(|a| contains(**a, (p.x, p.y))).map(|a| clamp_into(*a, (p.x, p.y), size)))
        .unwrap_or_else(|| top_center(primary, size, margin))
}

/// A window's physical size on a display at scale `to`, from its size at scale `from` (pure). Per-monitor DPI on
/// Windows keeps a window's logical size across monitors, so a pill 352 px wide at 100 % is 528 px at 150 %. Equal
/// scales (a Mac's points, which are 1 on every display here) keep it.
pub fn size_at(size: (u32, u32), from: f64, to: f64) -> (u32, u32) {
    let ok = |s: f64| s.is_finite() && s > 0.0;
    if !ok(from) || !ok(to) || (from - to).abs() < f64::EPSILON {
        return size;
    }
    let f = to / from;
    ((f64::from(size.0) * f).round() as u32, (f64::from(size.1) * f).round() as u32)
}

/// [`pill_origin`] over displays with their scales: the window is `size` at `window_scale` now, and it is kept inside
/// the display it lands on at the size it will have there. `margin_logical` is in logical px of that display.
pub fn pill_origin_scaled(saved: Option<PillPosition>, areas: &[(WorkArea, f64)], primary: usize, size: (u32, u32), window_scale: f64, margin_logical: f64) -> Option<(i32, i32)> {
    if let Some(p) = saved
        && let Some((area, scale)) = areas.iter().find(|(a, _)| contains(*a, (p.x, p.y)))
    {
        return Some(clamp_into(*area, (p.x, p.y), size_at(size, window_scale, *scale)));
    }
    let (area, scale) = *areas.get(primary)?;
    Some(top_center(area, size_at(size, window_scale, scale), (margin_logical * scale).round() as i32))
}

/// [`rescue`] over displays with their scales: whether half of the window shows is judged at its size now; the display
/// it moves into gets it at the size it will have there.
pub fn rescue_scaled(areas: &[(WorkArea, f64)], pos: (i32, i32), size: (u32, u32), window_scale: f64) -> Option<(i32, i32)> {
    let rects: Vec<WorkArea> = areas.iter().map(|(a, _)| *a).collect();
    rescue(&rects, pos, size)?;
    let centre = (pos.0 + size.0 as i32 / 2, pos.1 + size.1 as i32 / 2);
    let (area, scale) = areas[area_for(&rects, centre)?];
    Some(clamp_into(area, pos, size_at(size, window_scale, scale)))
}

/// Where on a display a window goes when it is moved there.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Spot {
    TopCentre,
    Corner(Corner),
}

/// A window moved onto a display at `scale` (pure): placed at `spot`, `margin_logical` px of that display from its
/// edges, at the size it will have there (it is `size` at `window_scale` now), so it never hangs off the display.
pub fn spot_on(area: WorkArea, scale: f64, size: (u32, u32), window_scale: f64, spot: Spot, margin_logical: f64) -> (i32, i32) {
    let size = size_at(size, window_scale, scale);
    let margin = (margin_logical * scale).round() as i32;
    let at = match spot {
        Spot::TopCentre => top_center(area, size, margin),
        Spot::Corner(corner) => dock(area, size, corner, margin),
    };
    clamp_into(area, at, size)
}

/// A scale as read now, else the last one read for the same window, else 1 (pure).
pub fn scale_or_last(read: Option<f64>, last: Option<f64>) -> f64 {
    let ok = |s: &f64| s.is_finite() && *s > 0.0;
    read.filter(ok).or(last.filter(ok)).unwrap_or(1.0)
}

/// The display that holds `point`, else the nearest one (its index); none without displays.
pub fn area_for(areas: &[WorkArea], point: (i32, i32)) -> Option<usize> {
    if let Some(at) = areas.iter().position(|a| contains(*a, point)) {
        return Some(at);
    }
    let distance = |a: &WorkArea| {
        let (cx, cy) = (a.x as i64 + a.w as i64 / 2, a.y as i64 + a.h as i64 / 2);
        (cx - point.0 as i64).pow(2) + (cy - point.1 as i64).pow(2)
    };
    areas.iter().enumerate().min_by_key(|(_, a)| distance(a)).map(|(i, _)| i)
}

/// Where a window goes when a display went away or moved: it stays put while at least half of it shows on some display;
/// otherwise it moves the least distance into the nearest one. None: leave it.
pub fn rescue(areas: &[WorkArea], pos: (i32, i32), size: (u32, u32)) -> Option<(i32, i32)> {
    let total = size.0 as i64 * size.1 as i64;
    let shown = areas.iter().map(|a| {
        let w = (pos.0 as i64 + size.0 as i64).min(a.x as i64 + a.w as i64) - (pos.0 as i64).max(a.x as i64);
        let h = (pos.1 as i64 + size.1 as i64).min(a.y as i64 + a.h as i64) - (pos.1 as i64).max(a.y as i64);
        w.max(0) * h.max(0)
    }).max()?;
    if shown * 2 >= total {
        return None;
    }
    let centre = (pos.0 + size.0 as i32 / 2, pos.1 + size.1 as i32 / 2);
    area_for(areas, centre).map(|at| clamp_into(areas[at], pos, size))
}

/// A rectangle in AppKit's coordinates: points, y going up from the primary display's bottom-left corner.
#[derive(Clone, Copy, Debug, PartialEq)]
pub struct MacRect {
    pub x: f64,
    pub y: f64,
    pub w: f64,
    pub h: f64,
}

/// One display as AppKit reports it: `NSScreen.frame`, `.visibleFrame` (without the menu bar and the Dock) and the top of
/// `.safeAreaInsets` (the notch's height, on a Mac that has one).
#[derive(Clone, Copy, Debug, PartialEq)]
pub struct MacScreen {
    pub frame: MacRect,
    pub visible: MacRect,
    pub top_inset: f64,
}

/// What is usable on a display for overlays, in points with y going down from the primary display's top (what windows
/// are placed in). The visible frame already leaves out the menu bar, and with it the notch; when the menu bar hides by
/// itself the visible frame reaches the top of the screen, so the notch's inset still applies.
pub fn mac_area(screen: &MacScreen, primary_height: f64) -> WorkArea {
    let screen_top = primary_height - (screen.frame.y + screen.frame.h);
    let visible_top = primary_height - (screen.visible.y + screen.visible.h);
    let top = visible_top.max(screen_top + screen.top_inset).ceil();
    let bottom = (primary_height - screen.visible.y).floor();
    let left = screen.visible.x.ceil();
    let right = (screen.visible.x + screen.visible.w).floor();
    WorkArea { x: left as i32, y: top as i32, w: (right - left).max(0.0) as u32, h: (bottom - top).max(0.0) as u32 }
}

/// `NSStatusWindowLevel`: above full-screen windows, floating panels (Zoom's share bar) and the menu bar.
pub const MAC_OVERLAY_LEVEL: isize = 25;
/// `NSFloatingWindowLevel`, the lowest level that stays above ordinary windows.
pub const MAC_FLOATING_LEVEL: isize = 3;
const CAN_JOIN_ALL_SPACES: usize = 1 << 0;
const STATIONARY: usize = 1 << 4;
const IGNORES_CYCLE: usize = 1 << 6;
const FULL_SCREEN_AUXILIARY: usize = 1 << 8;
/// `NSWindowCollectionBehavior` of an overlay: in every Space, beside full-screen windows, out of Mission Control's way
/// and of ⌘` .
pub const MAC_OVERLAY_BEHAVIOUR: usize = CAN_JOIN_ALL_SPACES | STATIONARY | IGNORES_CYCLE | FULL_SCREEN_AUXILIARY;

/// `NSWindowStyleMaskNonactivatingPanel`: a click on the window never activates EchoLedger.
pub const MAC_NONACTIVATING: usize = 1 << 7;

/// An overlay's style on a Mac (pure): its own, plus non-activating. With the panel class (`macos::make_panel`), a click on
/// the recording controls or the captions never brings EchoLedger (and its main window's Space) in front of the meeting,
/// and macOS lets the panel float over another app's full-screen window although EchoLedger is a normal app with a Dock
/// icon: since macOS 10.14 a plain window of a Dock app may not, a non-activating panel may.
pub fn mac_overlay_style(style: usize) -> usize {
    style | MAC_NONACTIVATING
}

/// The window would be seen over full-screen meetings and in every Space.
pub fn mac_follows_spaces(behaviour: usize) -> bool {
    behaviour & (CAN_JOIN_ALL_SPACES | FULL_SCREEN_AUXILIARY) == CAN_JOIN_ALL_SPACES | FULL_SCREEN_AUXILIARY
}

/// A window's frame lies on a display, as AppKit reports both.
pub fn mac_on_screen(window: &MacRect, screens: &[MacScreen]) -> bool {
    screens.iter().any(|s| {
        let w = (window.x + window.w).min(s.frame.x + s.frame.w) - window.x.max(s.frame.x);
        let h = (window.y + window.h).min(s.frame.y + s.frame.h) - window.y.max(s.frame.y);
        w > 0.0 && h > 0.0 && w * h * 2.0 >= window.w * window.h
    })
}

/// Caption overlay size (logical px) for the "visible lines" setting: 2 → 118, 3 → 146, 5 → 202 high, 460 wide.
/// Any other value is treated as 3 (the default).
pub fn caption_overlay_size(lines: u8) -> (f64, f64) {
    caption_overlay_size_for(lines, CaptionSize::Medium, CaptionWidth::Normal)
}

/// Caption overlay size for lines, text size and width (B4). Width: narrow 380, normal 460, wide 640. The text
/// part of the height scales with the text size (small 0.85, large 1.25); the 62 px of chrome stays.
pub fn caption_overlay_size_for(lines: u8, size: CaptionSize, width: CaptionWidth) -> (f64, f64) {
    let text = match lines {
        2 => 56.0,
        5 => 140.0,
        _ => 84.0,
    };
    let scale = match size {
        CaptionSize::Small => 0.85,
        CaptionSize::Medium => 1.0,
        CaptionSize::Large => 1.25,
    };
    let wide = match width {
        CaptionWidth::Narrow => 380.0,
        CaptionWidth::Normal => 460.0,
        CaptionWidth::Wide => 640.0,
    };
    (wide, (62.0 + text * scale_f(scale)).round())
}

fn scale_f(scale: f64) -> f64 {
    scale
}
