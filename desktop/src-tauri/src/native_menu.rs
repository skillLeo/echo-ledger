//! A menu of the page's own, drawn by the system (DES-02, PLAN_V2_1_POLISH 3b): on a Mac, Record's menu is a native menu,
//! as every Mac app's button menus are. The page gives its items, each with the action id it sends (UX-21,
//! v2/actions/actions.json), and where to open it; the system draws it, with its own keys, ticks and greyed items. A
//! pick reaches the menus' handler (tray.rs, Tauri hands it every menu's events), which tells the main window the action
//! id (`menu:action`); the page's registry runs it.

use serde::Deserialize;
use tauri::menu::{CheckMenuItem, Menu, MenuItem, PredefinedMenuItem};
use tauri::{AppHandle, Emitter, LogicalPosition, Window};

/// What a native item's id starts with: the rest is the action id.
pub const PREFIX: &str = "action:";
/// The event the main window hears with the action id picked.
pub const EVENT: &str = "menu:action";

/// One line of the menu, as the page sends it.
#[derive(Debug, Clone, Deserialize, PartialEq)]
#[serde(rename_all = "camelCase")]
pub struct NativeItem {
    /// The action it sends (none: a line between groups).
    pub action: Option<String>,
    #[serde(default)]
    pub label: String,
    /// Tauri's accelerator ("CmdOrCtrl+Shift+N"), shown at the end of the line.
    pub accelerator: Option<String>,
    #[serde(default = "yes")]
    pub enabled: bool,
    /// A tick (a check item); none: a plain item.
    pub checked: Option<bool>,
}

fn yes() -> bool {
    true
}

/// The item's id (pure): the prefix and the action id.
pub fn item_id(action: &str) -> String {
    format!("{PREFIX}{action}")
}

/// The action id of a picked item, or none when the item is not one of ours (pure).
pub fn picked_action(id: &str) -> Option<&str> {
    id.strip_prefix(PREFIX).filter(|a| !a.is_empty())
}

/// A pick from a native menu: the main window runs its action.
pub fn picked(app: &AppHandle, id: &str) {
    if let Some(action) = picked_action(id) {
        let _ = app.emit_to(crate::lifecycle::MAIN, EVENT, action);
    }
}

/// Opens the page's menu at `x`, `y` (CSS pixels from the window's top left), drawn by the system.
#[tauri::command]
pub async fn native_menu_popup(window: Window, items: Vec<NativeItem>, x: f64, y: f64) -> Result<(), String> {
    let app = window.app_handle().clone();
    let menu = Menu::new(&app).map_err(|e| e.to_string())?;
    for item in &items {
        match (&item.action, item.checked) {
            (None, _) => menu.append(&PredefinedMenuItem::separator(&app).map_err(|e| e.to_string())?),
            (Some(action), Some(checked)) => menu.append(
                &CheckMenuItem::with_id(&app, item_id(action), &item.label, item.enabled, checked, item.accelerator.as_deref()).map_err(|e| e.to_string())?,
            ),
            (Some(action), None) => {
                menu.append(&MenuItem::with_id(&app, item_id(action), &item.label, item.enabled, item.accelerator.as_deref()).map_err(|e| e.to_string())?)
            }
        }
        .map_err(|e| e.to_string())?;
    }
    window.popup_menu_at(&menu, LogicalPosition::new(x, y)).map_err(|e| e.to_string())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_native_items_id_carries_its_action_and_only_ours_are_answered() {
        assert_eq!(item_id("file.newRecording"), "action:file.newRecording");
        assert_eq!(picked_action("action:file.newRecording"), Some("file.newRecording"));
        assert_eq!(picked_action("action:"), None);
        assert_eq!(picked_action("quit"), None);
        assert_eq!(picked_action("record"), None);
    }

    #[test]
    fn the_page_sends_its_items_in_its_own_words() {
        let items: Vec<NativeItem> = serde_json::from_str(
            r#"[{"action":"file.newRecording","label":"New Recording","accelerator":"CmdOrCtrl+N","enabled":true},
                {"action":null},
                {"action":"live.autoRecord","label":"Auto-record Calls & Meetings","checked":true}]"#,
        )
        .unwrap();
        assert_eq!(items.len(), 3);
        assert_eq!(items[0].accelerator.as_deref(), Some("CmdOrCtrl+N"));
        assert_eq!(items[1].action, None);
        assert!(items[2].enabled);
        assert_eq!(items[2].checked, Some(true));
    }

    #[test]
    fn its_picks_reach_the_main_window_through_the_menus_handler() {
        let tray = include_str!("tray.rs");
        assert!(tray.contains("id if crate::native_menu::picked_action(id).is_some() => {"));
        assert!(include_str!("lib.rs").contains("native_menu::native_menu_popup,"));
        assert!(include_str!("../../src/lib/ipc.ts").contains(r#"invoke<void>("native_menu_popup", { items, x, y })"#));
    }
}
