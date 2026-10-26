//! Tray / menu-bar icon: the app keeps running here when the main window is closed. The icon shows the app's state
//! (A3.4): a coloured dot on the app icon while recording, paused, uploading or when something needs attention.

use std::sync::Mutex;

use serde::Serialize;
use tauri::image::Image;
use tauri::menu::{CheckMenuItem, Menu, MenuItem, PredefinedMenuItem};
use tauri::tray::{MouseButton, MouseButtonState, TrayIconBuilder, TrayIconEvent};
use tauri::{AppHandle, Manager};

pub const TRAY_ID: &str = "main";

#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum TrayState {
    Idle,
    Recording,
    Paused,
    Uploading,
    Attention,
}

/// The tray state with precedence Recording/Paused > Attention > Uploading > Idle: a visible recording indicator
/// always wins (A3.4).
pub fn tray_state(
    recording: bool,
    paused: bool,
    pending: usize,
    failed_uploads: usize,
    budget_reached: bool,
    uploading: bool,
) -> TrayState {
    if recording {
        return if paused {
            TrayState::Paused
        } else {
            TrayState::Recording
        };
    }
    if pending > 0 || failed_uploads > 0 || budget_reached {
        return TrayState::Attention;
    }
    if uploading {
        TrayState::Uploading
    } else {
        TrayState::Idle
    }
}

pub fn tooltip(state: TrayState) -> &'static str {
    match state {
        TrayState::Idle => "EchoLedger",
        TrayState::Recording => "EchoLedger — recording",
        TrayState::Paused => "EchoLedger — paused",
        TrayState::Uploading => "EchoLedger — uploading",
        TrayState::Attention => "EchoLedger — needs your attention",
    }
}

/// Status dot colour (RGBA): red recording, amber paused, blue uploading, violet attention.
pub fn dot_colour(state: TrayState) -> Option<[u8; 4]> {
    match state {
        TrayState::Idle => None,
        TrayState::Recording => Some([0xF4, 0x3F, 0x5E, 0xFF]),
        TrayState::Paused => Some([0xF5, 0x9E, 0x0B, 0xFF]),
        TrayState::Uploading => Some([0x3B, 0x82, 0xF6, 0xFF]),
        TrayState::Attention => Some([0x8B, 0x5C, 0xF6, 0xFF]),
    }
}

/// Paints a filled dot with a transparent ring in the bottom-right quarter of an RGBA image.
pub fn paint_dot(rgba: &mut [u8], width: u32, height: u32, colour: [u8; 4]) {
    let size = width.min(height) as f32;
    let radius = size * 0.22;
    let ring = size * 0.06;
    let (cx, cy) = (width as f32 - radius - ring, height as f32 - radius - ring);
    for y in 0..height {
        for x in 0..width {
            let d = ((x as f32 + 0.5 - cx).powi(2) + (y as f32 + 0.5 - cy).powi(2)).sqrt();
            let at = ((y * width + x) * 4) as usize;
            if at + 3 >= rgba.len() {
                continue;
            }
            if d <= radius {
                rgba[at..at + 4].copy_from_slice(&colour);
            } else if d <= radius + ring {
                rgba[at + 3] = 0; // cut a ring so the dot reads on any icon colour
            }
        }
    }
}

/// What the menu currently shows; the menu is rebuilt only when this changes.
#[derive(Clone, Debug, PartialEq, Eq, Default)]
struct MenuShape {
    recording: bool,
    paused: bool,
    show_controls: bool,
    review: usize,
    captions: bool,
    auto: bool,
}

#[derive(Default)]
pub struct TrayCache {
    shape: Mutex<Option<MenuShape>>,
    state: Mutex<Option<TrayState>>,
}

/// One line of the tray menu: an action, a check item (`checked` is `Some`) or a separator (C2: short, grouped).
#[derive(Clone, Debug, PartialEq, Eq)]
enum Entry {
    Item { id: &'static str, label: String, enabled: bool, checked: Option<bool> },
    Separator,
}

fn item(id: &'static str, label: impl Into<String>) -> Entry {
    Entry::Item { id, label: label.into(), enabled: true, checked: None }
}

/// The menu for a state: open, then recording, then the two switches and the review queue, then quit.
fn menu_entries(shape: &MenuShape) -> Vec<Entry> {
    let mut entries = vec![item("open", "Open EchoLedger"), Entry::Separator];
    entries.push(item("record", if shape.recording { "Stop recording" } else { "Start recording" }));
    entries.push(Entry::Item {
        id: "pause",
        label: (if shape.paused { "Resume recording" } else { "Pause recording" }).into(),
        enabled: shape.recording,
        checked: None,
    });
    if shape.show_controls {
        entries.push(item("controls", "Show recording controls"));
    }
    entries.push(item("voice_note", "Capture voice note playing now"));
    entries.push(Entry::Separator);
    entries.push(Entry::Item { id: "captions", label: "Live captions".into(), enabled: true, checked: Some(shape.captions) });
    entries.push(Entry::Item { id: "auto", label: "Record calls automatically".into(), enabled: true, checked: Some(shape.auto) });
    if shape.review > 0 {
        entries.push(item("review", format!("Review recordings ({})", shape.review)));
    }
    entries.push(Entry::Separator);
    entries.push(item("quit", "Quit EchoLedger"));
    entries
}

fn build_menu(app: &AppHandle, shape: &MenuShape) -> tauri::Result<Menu<tauri::Wry>> {
    let menu = Menu::new(app)?;
    for entry in menu_entries(shape) {
        match entry {
            Entry::Separator => menu.append(&PredefinedMenuItem::separator(app)?)?,
            Entry::Item { id, label, enabled, checked: Some(checked) } => {
                menu.append(&CheckMenuItem::with_id(app, id, label, enabled, checked, None::<&str>)?)?
            }
            Entry::Item { id, label, enabled, checked: None } => menu.append(&MenuItem::with_id(app, id, label, enabled, None::<&str>)?)?,
        }
    }
    Ok(menu)
}
pub fn create(app: &AppHandle) -> tauri::Result<()> {
    app.manage(TrayCache::default());
    let shape = MenuShape::default();
    let menu = build_menu(app, &shape)?;
    *app.state::<TrayCache>()
        .shape
        .lock()
        .unwrap_or_else(|p| p.into_inner()) = Some(shape);

    let mut builder = TrayIconBuilder::with_id(TRAY_ID)
        .tooltip(tooltip(TrayState::Idle))
        .menu(&menu)
        .show_menu_on_left_click(false);
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
                    "pause" => crate::actions::toggle_pause(&app).await,
                    "controls" => {
                        crate::actions::set_pill_hidden(&app, false);
                        Ok(())
                    }
                    "captions" => crate::actions::toggle_captions(&app).await,
                    "voice_note" => crate::actions::toggle_voice_note(&app).await,
                    "auto" => {
                        let state = app.state::<crate::AppState>();
                        state
                            .update_settings(|s| s.auto_mode = !s.auto_mode)
                            .map(|_| ())
                    }
                    "review" => {
                        crate::actions::navigate(&app, "library", None, false);
                        Ok(())
                    }
                    "quit" => {
                        let state = app.state::<crate::AppState>();
                        if state.capture.is_recording() {
                            // Never lose audio on quit: the recording stays as a decision for the next start.
                            let _ = crate::actions::stop_recording_for_quit(&app).await;
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
            if let TrayIconEvent::Click {
                button: MouseButton::Left,
                button_state: MouseButtonState::Up,
                ..
            } = event
            {
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

/// The two tray switches: live captions and automatic call recording.
#[derive(Clone, Copy, Debug, Default)]
pub struct Switches {
    pub captions: bool,
    pub auto: bool,
}

/// Applies a new state to the tray: icon, tooltip and menu, each only when it changed.
pub fn update(app: &AppHandle, state: TrayState, paused: bool, pill_hidden: bool, pending: usize, switches: Switches) {
    let Some(cache) = app.try_state::<TrayCache>() else {
        return;
    };
    let Some(tray) = app.tray_by_id(TRAY_ID) else {
        return;
    };
    let recording = matches!(state, TrayState::Recording | TrayState::Paused);

    let mut last = cache.state.lock().unwrap_or_else(|p| p.into_inner());
    if *last != Some(state) {
        *last = Some(state);
        let _ = tray.set_tooltip(Some(tooltip(state)));
        if let Some(base) = app.default_window_icon() {
            let mut rgba = base.rgba().to_vec();
            if let Some(colour) = dot_colour(state) {
                paint_dot(&mut rgba, base.width(), base.height(), colour);
            }
            let _ = tray.set_icon(Some(Image::new_owned(rgba, base.width(), base.height())));
        }
    }
    drop(last);

    let shape = MenuShape {
        recording,
        paused: recording && paused,
        show_controls: recording && pill_hidden,
        review: pending,
        captions: switches.captions,
        auto: switches.auto,
    };
    let mut current = cache.shape.lock().unwrap_or_else(|p| p.into_inner());
    if current.as_ref() != Some(&shape) {
        if let Ok(menu) = build_menu(app, &shape) {
            let _ = tray.set_menu(Some(menu));
        }
        *current = Some(shape);
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn labels(shape: &MenuShape) -> Vec<String> {
        menu_entries(shape)
            .into_iter()
            .map(|e| match e {
                Entry::Separator => "---".into(),
                Entry::Item { label, checked: Some(true), .. } => format!("[x] {label}"),
                Entry::Item { label, checked: Some(false), .. } => format!("[ ] {label}"),
                Entry::Item { label, .. } => label,
            })
            .collect()
    }

    #[test]
    fn idle_menu_is_short_and_grouped() {
        let shape = MenuShape { auto: true, ..MenuShape::default() };
        assert_eq!(
            labels(&shape),
            ["Open EchoLedger", "---", "Start recording", "Pause recording", "Capture voice note playing now", "---", "[ ] Live captions", "[x] Record calls automatically", "---", "Quit EchoLedger"]
        );
        let pause = menu_entries(&shape).into_iter().find(|e| matches!(e, Entry::Item { id: "pause", .. })).unwrap();
        assert!(matches!(pause, Entry::Item { enabled: false, .. }), "pause needs a recording");
    }

    #[test]
    fn recording_menu_shows_its_state_and_the_review_queue() {
        let shape = MenuShape { recording: true, paused: true, show_controls: true, review: 2, captions: true, auto: false };
        let labels = labels(&shape);
        assert!(labels.contains(&"Stop recording".to_string()));
        assert!(labels.contains(&"Resume recording".to_string()));
        assert!(labels.contains(&"Show recording controls".to_string()));
        assert!(labels.contains(&"[x] Live captions".to_string()));
        assert!(labels.contains(&"[ ] Record calls automatically".to_string()));
        assert!(labels.contains(&"Review recordings (2)".to_string()));
        assert_eq!(labels.iter().filter(|l| *l == "---").count(), 3);
        assert!(labels.len() <= 13, "the tray menu stays short");
    }

    #[test]
    fn menu_ids_keep_their_actions() {
        let shape = MenuShape { recording: true, show_controls: true, review: 1, ..MenuShape::default() };
        let ids: Vec<&str> = menu_entries(&shape).into_iter().filter_map(|e| match e { Entry::Item { id, .. } => Some(id), Entry::Separator => None }).collect();
        assert_eq!(ids, ["open", "record", "pause", "controls", "voice_note", "captions", "auto", "review", "quit"]);
    }
}
