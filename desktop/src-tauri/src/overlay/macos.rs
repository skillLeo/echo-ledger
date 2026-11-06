//! The AppKit side of the overlays: what keeps them over full-screen meetings and in every Space, and the displays' usable
//! areas in points. AppKit answers only on the main thread, so each call goes there and waits for it (from the main
//! thread itself the task runs at once).

use std::sync::mpsc;
use std::time::Duration;

use objc2::MainThreadMarker;
use objc2_app_kit::{NSScreen, NSWindow, NSWindowCollectionBehavior};
use tauri::{AppHandle, Runtime, WebviewWindow};

use super::geometry::{MAC_FLOATING_LEVEL, MAC_OVERLAY_BEHAVIOUR, MAC_OVERLAY_LEVEL, MacRect, MacScreen, mac_follows_spaces, mac_on_screen};
use crate::mac_check::WindowFacts;

fn mac_rect(r: objc2_core_foundation::CGRect) -> MacRect {
    MacRect { x: r.origin.x, y: r.origin.y, w: r.size.width, h: r.size.height }
}

fn screens_now(mtm: MainThreadMarker) -> Vec<MacScreen> {
    NSScreen::screens(mtm).iter().map(|s| MacScreen { frame: mac_rect(s.frame()), visible: mac_rect(s.visibleFrame()), top_inset: s.safeAreaInsets().top }).collect()
}

/// The displays as AppKit reports them, the primary first (empty if the main thread did not answer in time).
pub fn screens<R: Runtime>(app: &AppHandle<R>) -> Vec<MacScreen> {
    let (tx, rx) = mpsc::channel();
    let _ = app.run_on_main_thread(move || {
        if let Some(mtm) = MainThreadMarker::new() {
            let _ = tx.send(screens_now(mtm));
        }
    });
    rx.recv_timeout(Duration::from_secs(2)).unwrap_or_default()
}

/// Above full-screen windows and in every Space. `always_on_top` and `visible_on_all_workspaces` alone give the floating
/// level and no full-screen behaviour, which a full-screen Zoom window covers. Hide Others leaves overlays alone too.
pub fn keep_above_everything<R: Runtime>(window: &WebviewWindow<R>) {
    let target = window.clone();
    let _ = window.run_on_main_thread(move || {
        let Ok(pointer) = target.ns_window() else { return };
        // SAFETY: Tauri hands out the window's own NSWindow, alive while `target` is, on the main thread here.
        let ns_window: &NSWindow = unsafe { &*(pointer as *const NSWindow) };
        ns_window.setLevel(MAC_OVERLAY_LEVEL);
        ns_window.setCollectionBehavior(NSWindowCollectionBehavior(MAC_OVERLAY_BEHAVIOUR));
        ns_window.setCanHide(false);
    });
}

/// How an overlay stands, for the Mac check.
pub fn window_facts<R: Runtime>(window: &WebviewWindow<R>, label: &'static str) -> Option<WindowFacts> {
    let (tx, rx) = mpsc::channel();
    let target = window.clone();
    let _ = window.run_on_main_thread(move || {
        let (Some(mtm), Ok(pointer)) = (MainThreadMarker::new(), target.ns_window()) else { return };
        // SAFETY: as in `keep_above_everything`.
        let ns_window: &NSWindow = unsafe { &*(pointer as *const NSWindow) };
        let _ = tx.send(WindowFacts {
            label,
            exists: true,
            visible: ns_window.isVisible(),
            on_screen: mac_on_screen(&mac_rect(ns_window.frame()), &screens_now(mtm)),
            level_ok: ns_window.level() >= MAC_FLOATING_LEVEL,
            spaces_ok: mac_follows_spaces(ns_window.collectionBehavior().0),
        });
    });
    rx.recv_timeout(Duration::from_secs(2)).ok()
}
