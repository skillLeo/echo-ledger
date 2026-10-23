//! Tray / menu-bar icon: the app keeps running here when the main window is closed.

use tauri::menu::{Menu, MenuItem, PredefinedMenuItem};
use tauri::tray::{MouseButton, MouseButtonState, TrayIconBuilder, TrayIconEvent};
use tauri::{AppHandle, Manager};

pub const TRAY_ID: &str = "main";

pub fn create(app: &AppHandle) -> tauri::Result<()> {
    let open = MenuItem::with_id(app, "open", "Open EchoLedger", true, None::<&str>)?;
    let record = MenuItem::with_id(app, "record", "Start / stop recording", true, None::<&str>)?;
    let captions = MenuItem::with_id(app, "captions", "Live captions on / off", true, None::<&str>)?;
    let voice = MenuItem::with_id(app, "voice_note", "Capture voice note playing now", true, None::<&str>)?;
    let auto = MenuItem::with_id(app, "auto", "Auto-record calls on / off", true, None::<&str>)?;
    let separator = PredefinedMenuItem::separator(app)?;
    let quit = MenuItem::with_id(app, "quit", "Quit EchoLedger", true, None::<&str>)?;
    let menu = Menu::with_items(app, &[&open, &record, &captions, &voice, &auto, &separator, &quit])?;

    let mut builder = TrayIconBuilder::with_id(TRAY_ID).tooltip("EchoLedger").menu(&menu).show_menu_on_left_click(false);
    if let Some(icon) = app.default_window_icon() {
        builder = builder.icon(icon.clone());
    }
    builder
        .on_menu_event(|app, event| {
            let app = app.clone();
            let id = event.id.as_ref().to_string();
            tauri::async_runtime::spawn(async move {
                let result = match id.as_str() {
                    "open" => {
                        show_main(&app);
                        Ok(())
                    }
                    "record" => crate::actions::toggle_recording(&app).await,
                    "captions" => crate::actions::toggle_captions(&app).await,
                    "voice_note" => crate::actions::toggle_voice_note(&app).await,
                    "auto" => {
                        let state = app.state::<crate::AppState>();
                        state.update_settings(|s| s.auto_mode = !s.auto_mode).map(|_| ())
                    }
                    "quit" => {
                        let state = app.state::<crate::AppState>();
                        if state.capture.is_recording() {
                            let _ = crate::actions::stop_recording(&app, false).await; // never lose audio on quit
                        }
                        app.exit(0);
                        Ok(())
                    }
                    _ => Ok(()),
                };
                if let Err(e) = result {
                    use tauri::Emitter;
                    let _ = app.emit("app:error", e.to_string());
                }
                crate::actions::emit_status(&app);
            });
        })
        .on_tray_icon_event(|tray, event| {
            tauri_plugin_positioner::on_tray_event(tray.app_handle(), &event);
            if let TrayIconEvent::Click { button: MouseButton::Left, button_state: MouseButtonState::Up, .. } = event {
                show_main(tray.app_handle());
            }
        })
        .build(app)?;
    Ok(())
}

pub fn show_main(app: &AppHandle) {
    if let Some(window) = app.get_webview_window("main") {
        let _ = window.show();
        let _ = window.unminimize();
        let _ = window.set_focus();
    }
}

pub fn set_recording_tooltip(app: &AppHandle, recording: bool, captions: bool) {
    if let Some(tray) = app.tray_by_id(TRAY_ID) {
        let text = match (recording, captions) {
            (true, true) => "EchoLedger — recording · captions on",
            (true, false) => "EchoLedger — recording",
            (false, true) => "EchoLedger — captions on",
            (false, false) => "EchoLedger",
        };
        let _ = tray.set_tooltip(Some(text));
    }
}
