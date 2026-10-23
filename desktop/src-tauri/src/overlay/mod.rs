//! Caption overlay + dictation box windows. Created once, visible, unfocused and unfocusable (never `show()` or
//! `set_focus()` afterwards — tao ≥ 0.37.1 would steal focus); "hidden" = empty content + click-through.
//! docs/research/desktop-tauri.md §2.

pub mod geometry;

use std::sync::Mutex;
use std::time::Duration;

use tauri::{AppHandle, Emitter, Manager, PhysicalPosition, Runtime, WebviewUrl, WebviewWindow, WebviewWindowBuilder};

use geometry::{Corner, RectPx, WorkArea, cursor_in_regions, dock};

pub const OVERLAY: &str = "overlay";
pub const DICTATION: &str = "dictation";

const OVERLAY_SIZE: (f64, f64) = (460.0, 150.0);
const DICTATION_SIZE: (f64, f64) = (420.0, 110.0);
const MARGIN_LOGICAL: f64 = 18.0;

/// Interactive rects per window label, pushed from React on layout change.
#[derive(Default)]
pub struct HitRegions(pub Mutex<std::collections::HashMap<String, Vec<RectPx>>>);

fn build<R: Runtime>(app: &AppHandle<R>, label: &str, size: (f64, f64), protected: bool) -> tauri::Result<WebviewWindow<R>> {
    WebviewWindowBuilder::new(app, label, WebviewUrl::App("index.html".into()))
        .title(if label == OVERLAY { "EchoLedger captions" } else { "EchoLedger dictation" })
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

/// Creates both overlays (call from an async context or the setup hook — never from a sync command).
pub fn create_overlays<R: Runtime>(app: &AppHandle<R>, corner: Corner, content_protected: bool) -> tauri::Result<()> {
    if app.get_webview_window(OVERLAY).is_none() {
        let window = build(app, OVERLAY, OVERLAY_SIZE, content_protected)?;
        let _ = window.set_ignore_cursor_events(true);
        redock(&window, corner);
    }
    if app.get_webview_window(DICTATION).is_none() {
        let window = build(app, DICTATION, DICTATION_SIZE, content_protected)?;
        let _ = window.set_ignore_cursor_events(true);
        redock(&window, top_center_corner(corner));
    }
    Ok(())
}

fn top_center_corner(corner: Corner) -> Corner {
    match corner {
        Corner::BottomRight | Corner::TopRight => Corner::TopRight,
        Corner::BottomLeft | Corner::TopLeft => Corner::TopLeft,
    }
}

/// Docks a window into `corner` of its current monitor's work area (not the full monitor: taskbar-safe).
pub fn redock<R: Runtime>(window: &WebviewWindow<R>, corner: Corner) {
    let monitor = window.current_monitor().ok().flatten().or_else(|| window.primary_monitor().ok().flatten());
    let (Some(monitor), Ok(size)) = (monitor, window.outer_size()) else { return };
    let wa = monitor.work_area();
    let area = WorkArea { x: wa.position.x, y: wa.position.y, w: wa.size.width, h: wa.size.height };
    let margin = (MARGIN_LOGICAL * monitor.scale_factor()).round() as i32;
    let (x, y) = dock(area, (size.width, size.height), corner, margin);
    let _ = window.set_position(PhysicalPosition::new(x, y));
}

pub fn set_content_protection<R: Runtime>(app: &AppHandle<R>, protected: bool) {
    for label in [OVERLAY, DICTATION] {
        if let Some(w) = app.get_webview_window(label) {
            let _ = w.set_content_protected(protected);
        }
    }
}

/// ~30 Hz cursor poll: clickable only while the cursor is over an interactive region; toggles on transitions.
pub fn spawn_clickthrough_poll<R: Runtime>(app: AppHandle<R>) {
    tauri::async_runtime::spawn(async move {
        let mut ignoring: std::collections::HashMap<&'static str, bool> = std::collections::HashMap::new();
        let mut tick = tokio::time::interval(Duration::from_millis(33));
        loop {
            tick.tick().await;
            let Ok(cursor) = app.cursor_position() else { continue };
            for label in [OVERLAY, DICTATION] {
                let Some(window) = app.get_webview_window(label) else { continue };
                let regions = app.state::<HitRegions>().0.lock().map(|m| m.get(label).cloned().unwrap_or_default()).unwrap_or_default();
                let want_ignore = if regions.is_empty() {
                    true
                } else {
                    match window.inner_position() {
                        Ok(origin) => !cursor_in_regions((cursor.x, cursor.y), (origin.x, origin.y), &regions),
                        Err(_) => true,
                    }
                };
                if ignoring.get(label) != Some(&want_ignore) && window.set_ignore_cursor_events(want_ignore).is_ok() {
                    ignoring.insert(label, want_ignore);
                }
            }
        }
    });
}

/// Visibility of overlay content (the window itself stays created; see module docs).
pub fn set_overlay_visible<R: Runtime>(app: &AppHandle<R>, label: &str, visible: bool) {
    let _ = app.emit_to(label, "overlay:visibility", visible);
    if !visible {
        if let Ok(mut map) = app.state::<HitRegions>().0.lock() {
            map.remove(label);
        }
        if let Some(w) = app.get_webview_window(label) {
            let _ = w.set_ignore_cursor_events(true);
        }
    }
}
