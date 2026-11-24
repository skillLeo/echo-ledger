//! A menu of the page's own, drawn by the system (DES-02, PLAN_V2_1_POLISH 3b): on a Mac, Record's menu is a native menu,
//! as every Mac app's button menus are. The page gives its items, each with the action id it sends (UX-21,
//! v2/actions/actions.json), and where to open it; the system draws it, with its own keys, ticks and greyed items. A
//! pick reaches the menus' handler (tray.rs, Tauri hands it every menu's events), which tells the main window the action
//! id (`menu:action`); the page's registry runs it. Every item the system draws for the page (here and the menu bar,
//! app_menu.rs) has its id from [`item_id`] and is read back by [`parse_item_id`]: one form everywhere.

use serde::Deserialize;
use tauri::menu::{CheckMenuItem, Menu, MenuItem, PredefinedMenuItem};
use tauri::{AppHandle, LogicalPosition, Manager, Window};

/// What a native item's id starts with: the rest is the action id.
pub const PREFIX: &str = "action:";
/// The event the main window hears with the pick (the item's id without its prefix).
pub const EVENT: &str = "menu:action";
/// Before what an action acts on, when that isn't the selection ("action:project.rename@p1").
pub const TARGET: char = '@';
/// Before a sub-menu choice's value ("action:view.sortBy=newest").
pub const VALUE: char = '=';

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
    /// What it acts on, when that isn't the selection (the project right-clicked in the panel's tree).
    #[serde(default)]
    pub target: Option<String>,
    /// A sub-menu's choice, sent with its action.
    #[serde(default)]
    pub value: Option<String>,
}

fn yes() -> bool {
    true
}

/// What a picked item says: its action, what it acts on, the choice it was picked with.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct Pick<'a> {
    pub action: &'a str,
    pub target: Option<&'a str>,
    pub value: Option<&'a str>,
}

/// An action id ("view.sortBy"): words of letters and digits joined by dots, its group first.
fn is_action(action: &str) -> bool {
    action.contains('.') && action.split('.').all(|w| !w.is_empty() && w.chars().all(|c| c.is_ascii_alphanumeric()))
}

/// A target or a value: an id we build (a project's, a label's, an order's), never free text, so never holding the
/// separators.
fn is_part(part: &str) -> bool {
    !part.is_empty() && !part.contains([TARGET, VALUE])
}

/// The id of one of our items (pure), the one form every menu the system draws uses (the menu bar, the page's menus):
/// `action:<id>`, `action:<id>=<value>` (a choice), `action:<id>@<target>` (acts on a thing), and both,
/// `action:<id>@<target>=<value>`. None when a part isn't well formed (a target or value with "@" or "="): that item
/// isn't drawn.
pub fn item_id(action: &str, target: Option<&str>, value: Option<&str>) -> Option<String> {
    if !is_action(action) || !target.is_none_or(is_part) || !value.is_none_or(is_part) {
        return None;
    }
    let mut id = format!("{PREFIX}{action}");
    if let Some(target) = target {
        id.push(TARGET);
        id.push_str(target);
    }
    if let Some(value) = value {
        id.push(VALUE);
        id.push_str(value);
    }
    Some(id)
}

/// What an item's id says (pure), the other half of [`item_id`]; none when it isn't one of ours (Settings…, Quit, the
/// tray's own) or isn't well formed. The page reads the same form (v2/actions/menuState.ts, parsePick).
pub fn parse_item_id(id: &str) -> Option<Pick<'_>> {
    let rest = id.strip_prefix(PREFIX)?;
    let (head, value) = match rest.split_once(VALUE) {
        Some((head, value)) => (head, Some(value)),
        None => (rest, None),
    };
    let (action, target) = match head.split_once(TARGET) {
        Some((action, target)) => (action, Some(target)),
        None => (head, None),
    };
    (is_action(action) && target.is_none_or(is_part) && value.is_none_or(is_part)).then_some(Pick { action, target, value })
}

/// The pick a picked item sends the page (its id without the prefix: "view.sortBy=newest"), or none when the item is
/// not one of ours (pure).
pub fn picked_action(id: &str) -> Option<&str> {
    parse_item_id(id).map(|_| &id[PREFIX.len()..])
}

/// A pick from a native menu or the menu bar: the main window runs its action ("view.sortBy=newest" with a choice). With
/// no main window (closed to the menu bar), it opens, and the pick is kept for its page until it listens.
pub fn picked(app: &AppHandle, id: &str) {
    if let Some(action) = picked_action(id) {
        if app.get_webview_window(crate::lifecycle::MAIN).is_none() {
            crate::lifecycle::show_main(app);
        }
        crate::lifecycle::emit(app, crate::lifecycle::MAIN, EVENT, action);
    }
}

/// Opens the page's menu at `x`, `y` (CSS pixels from the window's top left), drawn by the system.
#[tauri::command]
pub async fn native_menu_popup(window: Window, items: Vec<NativeItem>, x: f64, y: f64) -> Result<(), String> {
    let app = window.app_handle().clone();
    let menu = Menu::new(&app).map_err(|e| e.to_string())?;
    for item in &items {
        let Some(action) = &item.action else {
            menu.append(&PredefinedMenuItem::separator(&app).map_err(|e| e.to_string())?).map_err(|e| e.to_string())?;
            continue;
        };
        // A target or value that isn't one of our ids: the item isn't drawn (and can't send a pick nobody reads).
        let Some(id) = item_id(action, item.target.as_deref(), item.value.as_deref()) else { continue };
        match item.checked {
            Some(checked) => menu.append(
                &CheckMenuItem::with_id(&app, id, &item.label, item.enabled, checked, item.accelerator.as_deref()).map_err(|e| e.to_string())?,
            ),
            None => menu.append(&MenuItem::with_id(&app, id, &item.label, item.enabled, item.accelerator.as_deref()).map_err(|e| e.to_string())?),
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
        assert_eq!(item_id("file.newRecording", None, None).as_deref(), Some("action:file.newRecording"));
        assert_eq!(picked_action("action:file.newRecording"), Some("file.newRecording"));
        assert_eq!(picked_action("action:"), None);
        assert_eq!(picked_action("quit"), None);
        assert_eq!(picked_action("record"), None);
        assert_eq!(picked_action("settings"), None);
    }

    #[test]
    fn one_form_for_every_id_a_choice_after_equals_a_thing_after_at() {
        let id = |a, t, v| item_id(a, t, v).unwrap();
        assert_eq!(id("view.sortBy", None, Some("newest")), "action:view.sortBy=newest");
        assert_eq!(id("project.rename", Some("p1"), None), "action:project.rename@p1");
        assert_eq!(id("recording.labels", Some("r1"), Some("clients")), "action:recording.labels@r1=clients");
        // Each reads back as it was built.
        for (action, target, value) in [("view.sortBy", None, Some("newest")), ("project.rename", Some("p1"), None), ("recording.labels", Some("r1"), Some("clients")), ("go.library", None, None)] {
            assert_eq!(parse_item_id(&id(action, target, value)), Some(Pick { action, target, value }));
        }
        assert_eq!(picked_action("action:recording.labels@r1=clients"), Some("recording.labels@r1=clients"));
    }

    #[test]
    fn a_target_or_value_holding_a_separator_is_refused_both_ways() {
        for (target, value) in [(Some("p@1"), None), (Some("p=1"), None), (None, Some("a=b")), (None, Some("a@b")), (Some(""), None), (None, Some(""))] {
            assert_eq!(item_id("project.rename", target, value), None, "{target:?} {value:?}");
        }
        assert_eq!(item_id("", None, None), None);
        assert_eq!(item_id("action:go.library", None, None), None);
        assert_eq!(item_id("library", None, None), None);
        for id in ["action:view.sortBy=a=b", "action:project.rename@p@1", "action:project.rename@=x", "action:view.sortBy=", "action:=x", "action:go..library", "action:go.library#x"] {
            assert_eq!(parse_item_id(id), None, "{id}");
            assert_eq!(picked_action(id), None, "{id}");
        }
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
