//! A menu of the page's own, drawn by the system (DES-02, PLAN_V2_1_POLISH 3b): on a Mac, Record's menu is a native menu,
//! as every Mac app's button menus are. The page gives its items, each with the action id it sends (UX-21,
//! v2/actions/actions.json), and where to open it; the system draws it, with its own keys, ticks and greyed items. A
//! pick reaches the menus' handler (tray.rs, Tauri hands it every menu's events), which tells the main window the action
//! id (`menu:action`); the page's registry runs it.
//!
//! A pick's id, after the prefix: `<id>[=<value>][~<n>]`, the shared format of the window's menus (the menu bar's,
//! FIX-05, adds `@<target>` before the value). `=<value>` is a sub-menu's choice; `~<n>` is the number of the
//! right-click menu it came from (UX-22, v2/actions/popup.ts), read off the end first: such a pick belongs to that menu
//! alone, which runs it while it is the open one and drops it once it is not. A pick with no number (Record's menu, the
//! menu bar) goes to the registry.

use serde::Deserialize;
use tauri::menu::{CheckMenuItem, IsMenuItem, Menu, MenuItem, PredefinedMenuItem, Submenu};
use tauri::{AppHandle, Emitter, LogicalPosition, Manager, Window, Wry};

/// What a native item's id starts with: the rest is the action id.
pub const PREFIX: &str = "action:";
/// The event the main window hears with the action id picked.
pub const EVENT: &str = "menu:action";

/// One line of the menu, as the page sends it.
#[derive(Debug, Clone, Deserialize, PartialEq)]
#[serde(rename_all = "camelCase")]
pub struct NativeItem {
    /// The action it sends (none: a line between groups, or with a label a greyed title line, "3 recordings").
    pub action: Option<String>,
    #[serde(default)]
    pub label: String,
    /// Tauri's accelerator ("CmdOrCtrl+Shift+N"), shown at the end of the line.
    pub accelerator: Option<String>,
    #[serde(default = "yes")]
    pub enabled: bool,
    /// A tick (a check item); none: a plain item.
    pub checked: Option<bool>,
    /// A sub-menu's choice (a label, a project, a format, UX-22): sent with its action as `<action>=<value>`.
    #[serde(default)]
    pub value: Option<String>,
    /// A sub-menu's items (one level, 3b).
    #[serde(default)]
    pub items: Option<Vec<NativeItem>>,
}

fn yes() -> bool {
    true
}

/// The item's id (pure): the prefix and the action id, a choice's value after "=" (ids have none), and last the
/// number of the right-click menu it is in after "~".
pub fn item_id(action: &str, value: Option<&str>, menu: Option<u64>) -> String {
    let mut id = format!("{PREFIX}{action}");
    if let Some(value) = value {
        id.push('=');
        id.push_str(value);
    }
    if let Some(menu) = menu {
        id.push_str(&format!("~{menu}"));
    }
    id
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

/// One line as the system draws it: a hairline, a greyed title, a sub-menu (one level), a tick or a plain item.
fn native_line(app: &AppHandle, item: &NativeItem, depth: usize, menu: Option<u64>) -> Result<Box<dyn IsMenuItem<Wry>>, String> {
    let e = |e: tauri::Error| e.to_string();
    Ok(match (&item.action, &item.items, item.checked) {
        (None, _, _) if item.label.is_empty() => Box::new(PredefinedMenuItem::separator(app).map_err(e)?),
        (None, _, _) => Box::new(MenuItem::new(app, &item.label, false, None::<&str>).map_err(e)?),
        (Some(_), Some(items), _) if depth == 0 => {
            let sub = Submenu::new(app, &item.label, item.enabled).map_err(e)?;
            for child in items {
                sub.append(native_line(app, child, depth + 1, menu)?.as_ref()).map_err(e)?;
            }
            Box::new(sub)
        }
        (Some(action), _, Some(checked)) => Box::new(
            CheckMenuItem::with_id(app, item_id(action, item.value.as_deref(), menu), &item.label, item.enabled, checked, item.accelerator.as_deref())
                .map_err(e)?,
        ),
        (Some(action), _, None) => {
            Box::new(MenuItem::with_id(app, item_id(action, item.value.as_deref(), menu), &item.label, item.enabled, item.accelerator.as_deref()).map_err(e)?)
        }
    })
}

/// Opens the page's menu at `x`, `y` (CSS pixels from the window's top left), drawn by the system. Nothing of it stays
/// on the page's side: a pick comes back as `menu:action` with its action id (and a choice's value), and with `menu`
/// (a right-click menu's number) its every pick carries `~<menu>`.
#[tauri::command]
pub async fn native_menu_popup(window: Window, items: Vec<NativeItem>, x: f64, y: f64, menu: Option<u64>) -> Result<(), String> {
    let app = window.app_handle().clone();
    let lines = Menu::new(&app).map_err(|e| e.to_string())?;
    for item in &items {
        lines.append(native_line(&app, item, 0, menu)?.as_ref()).map_err(|e| e.to_string())?;
    }
    window.popup_menu_at(&lines, LogicalPosition::new(x, y)).map_err(|e| e.to_string())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_native_items_id_carries_its_action_and_only_ours_are_answered() {
        assert_eq!(item_id("file.newRecording", None, None), "action:file.newRecording");
        // A sub-menu's choice carries its value: the page splits it at the first "=".
        assert_eq!(item_id("recording.labels", Some("clients"), None), "action:recording.labels=clients");
        // A right-click menu's pick carries its number last, read off first ("~5"), the value's own "~" kept.
        assert_eq!(item_id("recording.pin", None, Some(5)), "action:recording.pin~5");
        assert_eq!(item_id("recording.labels", Some("a~b"), Some(12)), "action:recording.labels=a~b~12");
        assert_eq!(picked_action("action:recording.labels=a~b~12"), Some("recording.labels=a~b~12"));
        assert_eq!(picked_action("action:recording.labels=clients"), Some("recording.labels=clients"));
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
        assert_eq!(items[0].value, None);
        assert_eq!(items[0].items, None);
    }

    #[test]
    fn a_title_line_a_choice_and_a_sub_menu_of_choices() {
        let items: Vec<NativeItem> = serde_json::from_str(
            r#"[{"action":null,"label":"3 Recordings","enabled":false},
                {"action":null},
                {"action":"recording.moveToProject","label":"Move to Project","items":[
                    {"action":"recording.moveToProject","label":"No project","value":"none","checked":true},
                    {"action":"recording.moveToProject","label":"Acme","value":"c1","checked":false}]}]"#,
        )
        .unwrap();
        assert_eq!(items[0].label, "3 Recordings");
        assert!(!items[0].enabled);
        assert_eq!(items[1].label, "");
        let sub = items[2].items.as_ref().unwrap();
        assert_eq!(sub.len(), 2);
        assert_eq!(sub[1].value.as_deref(), Some("c1"));
        assert_eq!(item_id(sub[1].action.as_deref().unwrap(), sub[1].value.as_deref(), Some(3)), "action:recording.moveToProject=c1~3");
    }

    #[test]
    fn its_picks_reach_the_main_window_through_the_menus_handler() {
        let tray = include_str!("tray.rs");
        assert!(tray.contains("id if crate::native_menu::picked_action(id).is_some() => {"));
        assert!(include_str!("lib.rs").contains("native_menu::native_menu_popup,"));
        assert!(include_str!("../../src/lib/ipc.ts").contains(r#"invoke<void>("native_menu_popup", { items, x, y, menu: menu ?? null })"#));
    }
}
