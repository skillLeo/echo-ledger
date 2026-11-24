//! The main window's own title row on Windows (UX-20, PLAN_V2_1_POLISH; behind `v21_win_titlebar`, off for everyone
//! until it is turned on). With the flag on the window has no system title bar: the page draws the row, 48 px, with the
//! app's mark in the rail's top cell and Minimize, Maximize and Close at its end (TitleRow.tsx, WindowButtons).
//! What Windows does for its own title bar stays:
//! - moving: the row is a drag region, which starts the system's move loop (snapping to the screen's edges and corners
//!   included), and a double click maximizes or restores;
//! - resizing from every edge, the top included: Tauri hit-tests an undecorated window's border (tauri-runtime-wry's
//!   undecorated_resizing);
//! - Snap Layouts on Windows 11 when the pointer rests on Maximize: tauri-plugin-snap-layout puts a small native window
//!   over the page's Maximize button that answers the system as a maximize button (HTMAXBUTTON); Windows 10 has none;
//! - the shadow: kept on an undecorated window (`shadow(true)`);
//! - Alt+Space, the window's system menu: an undecorated window keeps WS_SYSMENU and WS_CAPTION (tao).
//!
//! With the flag off nothing here touches the window: it is built from tauri.conf.json as before, with Windows' title bar.
use std::sync::atomic::{AtomicBool, Ordering};

use tauri::{AppHandle, Emitter, Manager, Runtime, WebviewWindowBuilder};

use crate::ui_flags::UiFlags;

/// The flag (contract 2.0.0-s).
pub const FLAG: &str = "v21_win_titlebar";

/// What the main window hears when its title bar changes while it is open: `true`, the page draws its own row.
pub const EVENT: &str = "window:titlebar";

/// Run before the page when the main window is built without the system's title bar: the first frame draws the row.
pub const START: &str = include_str!("../../src/lib/winTitlebarStart.js");

/// The id of the page's Maximize button, the one the snap layout plugin covers (WindowButtons in TitleRow.tsx).
pub const MAXIMIZE_ID: &str = "el-window-maximize";

/// Whether the main window has its own row now (as it was built, or as the flag last changed it).
static OWN_ROW: AtomicBool = AtomicBool::new(false);

/// Whether the main window draws its own title row (pure): on Windows with the flag on.
pub fn own_row(windows: bool, flags: &UiFlags) -> bool {
    windows && flags.is_on(FLAG)
}

/// The main window's builder as the flag says: with it off, unchanged (Windows' own title bar); with it on, no system
/// title bar, the shadow kept, and the row drawn from the first frame.
pub fn on_builder<'a, R: Runtime, M: Manager<R>>(app: &AppHandle, builder: WebviewWindowBuilder<'a, R, M>) -> WebviewWindowBuilder<'a, R, M> {
    let on = own_row(cfg!(windows), &crate::ui_flags::cached(&app.state::<crate::AppState>()));
    OWN_ROW.store(on, Ordering::SeqCst);
    if on { builder.decorations(false).shadow(true).initialization_script(START) } else { builder }
}

/// Follows the flag while EchoLedger runs (`ui_flags::announce`, on a change): the open main window loses or gets back
/// Windows' title bar and is told, so its page draws or drops its row in the same moment. Nothing when it doesn't change.
pub fn follow(app: &AppHandle, flags: &UiFlags) {
    let on = own_row(cfg!(windows), flags);
    if OWN_ROW.swap(on, Ordering::SeqCst) == on {
        return;
    }
    let Some(window) = app.get_webview_window(crate::lifecycle::MAIN) else { return };
    let _ = window.set_decorations(!on);
    if on {
        let _ = window.set_shadow(true);
    }
    let _ = app.emit_to(crate::lifecycle::MAIN, EVENT, on);
}

/// Whether the main window has its own row (`window_titlebar`, the page's first question after a reload).
#[tauri::command]
pub fn window_titlebar() -> bool {
    OWN_ROW.load(Ordering::SeqCst)
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn only_windows_with_the_flag_on_has_its_own_row() {
        let on = UiFlags::read(Some(&json!({ "v21_win_titlebar": true })), false);
        let off = UiFlags::read(Some(&json!({ "v21_win_titlebar": false })), false);
        assert!(own_row(true, &on));
        assert!(!own_row(true, &off), "flag off: Windows' own title bar");
        assert!(!own_row(false, &on), "never on a Mac");
        assert!(!own_row(true, &UiFlags::default()), "before the first config read: Windows' own title bar");
        assert!(crate::ui_flags::KNOWN.contains(&FLAG));
    }

    #[test]
    fn with_the_flag_off_the_main_window_is_built_as_before() {
        let code = include_str!("wintitle.rs").split("#[cfg(test)]").next().unwrap();
        let build = &code[code.find("pub fn on_builder").unwrap()..];
        let build = &build[..build.find("\n}\n").unwrap()];
        assert!(build.contains("if on { builder.decorations(false).shadow(true).initialization_script(START) } else { builder }"), "{build}");
        let lifecycle = include_str!("lifecycle.rs");
        let main = &lifecycle[lifecycle.find("fn build_main").unwrap()..];
        let main = &main[..main.find("\n}\n").unwrap()];
        let at = main.find("crate::wintitle::on_builder(app, builder)").expect("the builder goes through it");
        assert!(at < main.find("privacy::window(").unwrap(), "before the window is made");
        // Told on a change only, from the one place the flags are announced.
        let flags = include_str!("ui_flags.rs");
        let announce = &flags[flags.find("pub fn announce").unwrap()..];
        assert!(announce[..announce.find("\n}\n").unwrap()].contains("crate::wintitle::follow(app, &flags);"));
        // The first frame draws the row (the page keys off <html data-titlebar="win">).
        assert!(START.contains(r#"root.dataset.titlebar = "win""#));
    }

    #[test]
    fn snap_layouts_come_from_one_pinned_plugin_linked_on_windows_only() {
        let cargo = include_str!("../Cargo.toml");
        let windows = &cargo[cargo.find("[target.'cfg(windows)'.dependencies]").unwrap()..];
        let windows = &windows[..windows[1..].find("\n[").map_or(windows.len(), |i| i + 1)];
        assert!(windows.contains("tauri-plugin-snap-layout = \"=1.0.9\""), "pinned, in the Windows table");
        assert_eq!(cargo.matches("tauri-plugin-snap-layout").count(), 1, "nowhere else: the Mac build never links it");
        let lock = include_str!("../Cargo.lock");
        assert_eq!(lock.matches("\nname = \"tauri-plugin-snap-layout\"\n").count(), 1);
        assert_eq!(lock.matches("\nname = \"raw-window-handle\"\n").count(), 1, "one raw-window-handle");
        let lib = include_str!("lib.rs");
        assert_eq!(lib.matches("tauri_plugin_snap_layout").count(), 1);
        assert!(
            lib.contains("    #[cfg(windows)]\n    {\n        builder = builder.plugin(tauri_plugin_snap_layout::init().button_id(wintitle::MAXIMIZE_ID).build());\n    }"),
            "registered on Windows only, for the page's Maximize"
        );
        // Its permission is the main window's, on Windows only (tauri-build checks a capability only for its platforms).
        let capability: serde_json::Value = serde_json::from_str(include_str!("../capabilities/windows-titlebar.json")).unwrap();
        assert_eq!(capability["platforms"], json!(["windows"]));
        assert_eq!(capability["windows"], json!(["main"]));
        assert!(capability["permissions"].as_array().unwrap().contains(&json!("snap-layout:default")));
    }
}
