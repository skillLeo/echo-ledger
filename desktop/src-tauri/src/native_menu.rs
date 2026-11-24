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
/// Before the number of the context menu an item is in ("action:recording.pin~4"): its picks are that menu's only.
pub const MENU: char = '~';

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
    /// The context menu it is in (the page numbers each it shows, popup.ts): answered by that menu only.
    #[serde(default)]
    pub menu: Option<u32>,
}

fn yes() -> bool {
    true
}

/// What an item sends when picked: its action, what it acts on, the choice it was picked with, the context menu it is in.
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq)]
pub struct Pick<'a> {
    pub action: &'a str,
    pub target: Option<&'a str>,
    pub value: Option<&'a str>,
    pub menu: Option<u32>,
}

impl<'a> Pick<'a> {
    /// A plain action's pick.
    pub fn of(action: &'a str) -> Self {
        Pick { action, ..Pick::default() }
    }
}

/// A pick read back from an item's id, its target and value decoded.
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Picked {
    pub action: String,
    pub target: Option<String>,
    pub value: Option<String>,
    pub menu: Option<u32>,
}

/// An action id ("view.sortBy"): words of letters and digits joined by dots, its group first.
fn is_action(action: &str) -> bool {
    action.contains('.') && action.split('.').all(|w| !w.is_empty() && w.chars().all(|c| c.is_ascii_alphanumeric()))
}

/// The form's own characters inside a target or a value, percent-encoded (pure): "%", "=", "@" and "~". Targets and
/// values are ids we build (a project's, a label's, a sort key), but one could still hold them, or end in "~12".
const ESCAPES: [(char, &str); 4] = [('%', "%25"), ('=', "%3D"), ('@', "%40"), ('~', "%7E")];

fn encode(part: &str) -> String {
    let mut out = String::with_capacity(part.len());
    for c in part.chars() {
        match ESCAPES.iter().find(|(raw, _)| *raw == c) {
            Some((_, code)) => out.push_str(code),
            None => out.push(c),
        }
    }
    out
}

/// A target or value as written in an id, decoded (pure); none when it holds a separator, an empty part or a "%" that
/// isn't one of [`ESCAPES`].
fn decode(raw: &str) -> Option<String> {
    if raw.is_empty() || raw.contains([TARGET, VALUE, MENU]) {
        return None;
    }
    let mut out = String::with_capacity(raw.len());
    let mut rest = raw;
    while let Some(at) = rest.find('%') {
        out.push_str(&rest[..at]);
        let code = rest.get(at..at + 3)?;
        out.push(ESCAPES.iter().find(|(_, c)| c.eq_ignore_ascii_case(code))?.0);
        rest = &rest[at + 3..];
    }
    out.push_str(rest);
    Some(out)
}

/// The id of one of our items (pure), the one form every menu the system draws uses (the menu bar, the page's menus):
/// `action:<id>[@<target>][=<value>][~<n>]`: a plain action, on a thing, a choice, in context menu n; the form's own
/// characters inside a target or value percent-encoded. None when the action isn't an action id or a target or value
/// is empty: that item isn't drawn.
pub fn item_id(pick: &Pick<'_>) -> Option<String> {
    let Pick { action, target, value, menu } = *pick;
    if !is_action(action) || target.is_some_and(str::is_empty) || value.is_some_and(str::is_empty) {
        return None;
    }
    let mut id = format!("{PREFIX}{action}");
    if let Some(target) = target {
        id.push(TARGET);
        id.push_str(&encode(target));
    }
    if let Some(value) = value {
        id.push(VALUE);
        id.push_str(&encode(value));
    }
    if let Some(menu) = menu {
        id.push(MENU);
        id.push_str(&menu.to_string());
    }
    Some(id)
}

/// What an item's id says (pure), the other half of [`item_id`]; none when it isn't one of ours (Settings…, Quit, the
/// tray's own) or isn't well formed. The page reads the same form (v2/actions/menuState.ts, parsePick).
pub fn parse_item_id(id: &str) -> Option<Picked> {
    let rest = id.strip_prefix(PREFIX)?;
    // The menu's number comes off first, then the value, then the target.
    let (rest, menu) = match rest.rsplit_once(MENU) {
        Some((rest, n)) if !n.is_empty() && n.bytes().all(|b| b.is_ascii_digit()) => (rest, Some(n.parse().ok()?)),
        Some(_) => return None,
        None => (rest, None),
    };
    let (head, value) = match rest.split_once(VALUE) {
        Some((head, value)) => (head, Some(value)),
        None => (rest, None),
    };
    let (action, target) = match head.split_once(TARGET) {
        Some((action, target)) => (action, Some(target)),
        None => (head, None),
    };
    if !is_action(action) {
        return None;
    }
    let target = match target {
        Some(raw) => Some(decode(raw)?),
        None => None,
    };
    let value = match value {
        Some(raw) => Some(decode(raw)?),
        None => None,
    };
    Some(Picked { action: action.to_string(), target, value, menu })
}

/// The pick a picked item sends the page (its id without the prefix: "view.sortBy=newest"), or none when the item is
/// not one of ours (pure).
pub fn picked_action(id: &str) -> Option<&str> {
    parse_item_id(id).map(|_| &id[PREFIX.len()..])
}

/// A pick from a native menu or the menu bar: the main window runs its action ("view.sortBy=newest" with a choice). The
/// window comes to the front first, whatever its state (shown, unminimised and focused; built when there is none; built
/// again when it is being closed), so the pick is sent to its page, or kept for it until it listens, never dropped.
pub fn picked(app: &AppHandle, id: &str) {
    if let Some(action) = picked_action(id) {
        crate::lifecycle::show_main(app);
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
        let pick = Pick { action, target: item.target.as_deref(), value: item.value.as_deref(), menu: item.menu };
        let Some(id) = item_id(&pick) else { continue };
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
        assert_eq!(item_id(&Pick::of("file.newRecording")).as_deref(), Some("action:file.newRecording"));
        assert_eq!(picked_action("action:file.newRecording"), Some("file.newRecording"));
        assert_eq!(picked_action("action:"), None);
        assert_eq!(picked_action("quit"), None);
        assert_eq!(picked_action("record"), None);
        assert_eq!(picked_action("settings"), None);
    }

    /// What a pick reads back as.
    fn picked(action: &str, target: Option<&str>, value: Option<&str>, menu: Option<u32>) -> Picked {
        Picked { action: action.into(), target: target.map(Into::into), value: value.map(Into::into), menu }
    }

    #[test]
    fn one_form_for_every_id_a_thing_after_at_a_choice_after_equals_a_menu_after_tilde() {
        let picks = [
            (Pick { value: Some("newest"), ..Pick::of("view.sortBy") }, "action:view.sortBy=newest"),
            (Pick { target: Some("p1"), ..Pick::of("project.rename") }, "action:project.rename@p1"),
            (Pick { target: Some("r1"), value: Some("clients"), ..Pick::of("recording.labels") }, "action:recording.labels@r1=clients"),
            (Pick { menu: Some(4), ..Pick::of("recording.pin") }, "action:recording.pin~4"),
            (Pick { target: Some("r1"), value: Some("clients"), menu: Some(12), ..Pick::of("recording.labels") }, "action:recording.labels@r1=clients~12"),
            (Pick::of("go.library"), "action:go.library"),
        ];
        for (pick, form) in picks {
            assert_eq!(item_id(&pick).as_deref(), Some(form));
            // Each reads back as it was built.
            assert_eq!(parse_item_id(form), Some(picked(pick.action, pick.target, pick.value, pick.menu)));
        }
        assert_eq!(picked_action("action:recording.labels@r1=clients~12"), Some("recording.labels@r1=clients~12"));
    }

    #[test]
    fn the_forms_own_characters_inside_a_target_or_value_are_percent_encoded() {
        for (raw, coded) in [("50%", "50%25"), ("a=b", "a%3Db"), ("me@home", "me%40home"), ("x~y", "x%7Ey"), ("Clients~12", "Clients%7E12")] {
            let id = item_id(&Pick { value: Some(raw), ..Pick::of("recording.labels") }).unwrap();
            assert_eq!(id, format!("action:recording.labels={coded}"));
            assert_eq!(parse_item_id(&id), Some(picked("recording.labels", None, Some(raw), None)), "{raw}");
            let on = item_id(&Pick { target: Some(raw), value: Some(raw), menu: Some(3), ..Pick::of("recording.labels") }).unwrap();
            assert_eq!(parse_item_id(&on), Some(picked("recording.labels", Some(raw), Some(raw), Some(3))), "{raw}");
        }
        // A value ending in "~12" is a value, never menu 12.
        let id = item_id(&Pick { value: Some("Clients~12"), ..Pick::of("recording.labels") }).unwrap();
        assert_eq!(parse_item_id(&id).unwrap().menu, None);
        // Lower-case escapes read too.
        assert_eq!(parse_item_id("action:recording.labels=a%3db").unwrap().value.as_deref(), Some("a=b"));
    }

    #[test]
    fn an_empty_part_or_a_malformed_id_is_refused() {
        for (target, value) in [(Some(""), None), (None, Some(""))] {
            assert_eq!(item_id(&Pick { target, value, ..Pick::of("project.rename") }), None, "{target:?} {value:?}");
        }
        assert_eq!(item_id(&Pick::of("")), None);
        assert_eq!(item_id(&Pick::of("action:go.library")), None);
        assert_eq!(item_id(&Pick::of("library")), None);
        for id in [
            "action:view.sortBy=a=b", "action:project.rename@p@1", "action:project.rename@=x", "action:view.sortBy=", "action:=x",
            "action:go..library", "action:go.library#x", "action:recording.pin~", "action:recording.pin~x", "action:recording.pin~4~5",
            "action:recording.pin~-1", "action:recording.labels=50%", "action:recording.labels=%zz", "action:recording.labels=%4",
            "action:recording.labels=a%20b",
        ] {
            assert_eq!(parse_item_id(id), None, "{id}");
            assert_eq!(picked_action(id), None, "{id}");
        }
    }

    #[test]
    fn a_pick_brings_the_window_to_the_front_and_is_kept_while_it_is_being_closed() {
        use crate::lifecycle::{Delivery, MAIN, Slots};
        use std::time::Instant;
        // picked(): the window is shown first, whatever its state, then the pick is sent.
        let code = include_str!("native_menu.rs");
        let body = &code[code.find("pub fn picked(app: &AppHandle, id: &str) {").unwrap()..];
        let body = &body[..body.find("\n}\n").unwrap()];
        let show = body.find("crate::lifecycle::show_main(app);").expect("shows the window");
        assert!(show < body.find("crate::lifecycle::emit(").unwrap());
        assert!(!body.contains("is_none()"), "shown whether or not it exists");
        // While the window is being closed, the show asks for a new one, so the pick waits for its page.
        let now = Instant::now();
        let mut slots = Slots::default();
        assert!(slots.show(MAIN, "open", now));
        slots.ready(MAIN, now);
        slots.closing(MAIN);
        assert!(!slots.show(MAIN, "open", now));
        assert_eq!(slots.event(MAIN, EVENT, serde_json::json!("go.library")), Delivery::Kept);
        assert!(slots.destroyed(MAIN, now), "built again");
        assert_eq!(slots.ready(MAIN, now).events, vec![(EVENT.to_string(), serde_json::json!("go.library"))]);
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
        assert_eq!(items[0].menu, None);
        let numbered: NativeItem = serde_json::from_str(r#"{"action":"recording.pin","label":"Pin","menu":4}"#).unwrap();
        assert_eq!(numbered.menu, Some(4));
    }

    #[test]
    fn its_picks_reach_the_main_window_through_the_menus_handler() {
        let tray = include_str!("tray.rs");
        assert!(tray.contains("id if crate::native_menu::picked_action(id).is_some() => {"));
        assert!(include_str!("lib.rs").contains("native_menu::native_menu_popup,"));
        assert!(include_str!("../../src/lib/ipc.ts").contains(r#"invoke<void>("native_menu_popup", { items, x, y })"#));
    }
}
