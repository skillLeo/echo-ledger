//! Pure overlay geometry (unit-tested): click-through hit regions and work-area docking.
//! All values are physical pixels; desktop coordinates may be negative (monitors left/above the primary).

use serde::{Deserialize, Serialize};

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
