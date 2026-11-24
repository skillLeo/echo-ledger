//! A menu of the page's own, drawn by the system (DES-02, PLAN_V2_1_POLISH 3b): on a Mac, Record's menu is a native menu,
//! as every Mac app's button menus are. The page gives its items, each with the action id it sends (UX-21,
//! v2/actions/actions.json), and where to open it; the system draws it, with its own keys, ticks and greyed items. A
//! pick reaches the menus' handler (tray.rs, Tauri hands it every menu's events), which tells the main window the action
//! id (`menu:action`); the page's registry runs it. Every item the system draws for the page (here and the menu bar,
//! app_menu.rs) has its id from [`item_id`] and is read back by [`parse_item_id`]: one form everywhere,
//! `action:<id>[@<target>][=<value>][~<n>]`. `@<target>` is what it acts on when that isn't the selection; `=<value>` a
//! sub-menu's choice; `~<n>` the number of the right-click menu it came from (UX-22, v2/actions/popup.ts), read off the
//! end first: such a pick belongs to that menu alone. A pick with no number (Record's menu, the menu bar, the tree's
//! project menu) goes to the registry.

use serde::Deserialize;
use tauri::menu::{CheckMenuItem, IsMenuItem, Menu, MenuItem, PredefinedMenuItem, Submenu};
use tauri::{AppHandle, LogicalPosition, Manager, Window, Wry};

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
    /// What it acts on, when that isn't the selection (the project right-clicked in the panel's tree).
    #[serde(default)]
    pub target: Option<String>,
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

/// What an item sends when picked: its action, what it acts on, the choice it was picked with, the context menu it is in.
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq)]
pub struct Pick<'a> {
    pub action: &'a str,
    pub target: Option<&'a str>,
    pub value: Option<&'a str>,
    pub menu: Option<u64>,
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
    pub menu: Option<u64>,
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

/// The live items that open something in the main window; every other `live.*` acts outside it (the recording, the
/// floating windows, hiding from shares), as every `window.*` does.
const LIVE_IN_MAIN: [&str; 3] = ["live.startAssist", "live.practice", "live.pastReports"];

/// A pick that acts outside the main window (pure): Hide from Screen Shares and its times, Stop Hiding, Hide Live Words
/// Now, the recording's and the captions' toggles, the floating windows (Window › Show Recording Pill…).
pub fn acts_outside_main(action: &str) -> bool {
    action.starts_with("window.") || (action.starts_with("live.") && !LIVE_IN_MAIN.contains(&action))
}

/// The menu bar's commands the core answers itself, with or without the main window's page, as the tray does: the
/// recording's, the captions', the pill's, Auto-record, and hiding from screen shares (Hide from Screen Shares is its
/// times' sub-menu). The page enables them by their conditions alone (v2/actions/menuBar.ts CORE_RUNS, the same list).
pub const CORE_RUNS: [&str; 11] = [
    "live.stopRecording",
    "live.pauseRecording",
    "live.captions",
    "live.autoRecord",
    "live.hideFromShares",
    "live.hide15",
    "live.hide60",
    "live.hideUntilQuit",
    "live.stopHiding",
    "live.hideWordsNow",
    "window.pill",
];

/// What the core does for one of [`CORE_RUNS`].
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum CoreStep {
    StopRecording,
    TogglePause,
    ToggleCaptions,
    ToggleAutoRecord,
    /// The main window hidden from screen shares for a time (`privacy::hide_main`, as the tray's times).
    HideFromShares(crate::privacy::share::HideFor),
    /// In screen shares again (`privacy::show_main_in_shares`, the tray's Show Now).
    StopHiding,
    /// Every EchoLedger window off the screen at once (the person's hide, as the shortcut and the tray's).
    HideWindows,
    TogglePill,
}

/// The core's step for a pick (pure); none for what only the page can do.
pub fn core_step(action: &str) -> Option<CoreStep> {
    use crate::privacy::share::HideFor;
    Some(match action {
        "live.stopRecording" => CoreStep::StopRecording,
        "live.pauseRecording" => CoreStep::TogglePause,
        "live.captions" => CoreStep::ToggleCaptions,
        "live.autoRecord" => CoreStep::ToggleAutoRecord,
        "live.hide15" => CoreStep::HideFromShares(HideFor::Minutes15),
        "live.hide60" => CoreStep::HideFromShares(HideFor::Hour1),
        "live.hideUntilQuit" => CoreStep::HideFromShares(HideFor::UntilQuit),
        "live.stopHiding" => CoreStep::StopHiding,
        "live.hideWordsNow" => CoreStep::HideWindows,
        "window.pill" => CoreStep::TogglePill,
        _ => return None,
    })
}

/// Runs a core step, as the tray's menu does.
async fn run_core(app: &AppHandle, step: CoreStep) -> crate::error::AppResult<()> {
    let state = app.state::<crate::AppState>();
    match step {
        CoreStep::StopRecording if state.capture.is_recording() => crate::actions::stop_recording(app, false).await,
        CoreStep::StopRecording => Ok(()),
        CoreStep::TogglePause => crate::actions::toggle_pause(app).await,
        CoreStep::ToggleCaptions => crate::actions::toggle_captions(app).await,
        CoreStep::ToggleAutoRecord => state.update_settings(|s| s.auto_mode = !s.auto_mode).map(|_| ()),
        CoreStep::HideFromShares(choice) => crate::privacy::hide_main(app, choice).map(|_| ()),
        CoreStep::StopHiding => crate::privacy::show_main_in_shares(app, None),
        CoreStep::HideWindows => {
            crate::privacy::set_hidden(app, crate::privacy::HideReason::Person, true);
            Ok(())
        }
        CoreStep::TogglePill => {
            let hidden = state.pill_hidden.load(std::sync::atomic::Ordering::SeqCst);
            crate::actions::set_pill_hidden(app, !hidden);
            Ok(())
        }
    }
}

/// What a pick does.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Route {
    /// The core answers it, page or not (a [`CORE_RUNS`] command).
    Core,
    /// Its page takes it (built or being built).
    Send,
    /// No page can take it (no window, or one being closed): opened quietly, the pick kept for its page.
    OpenQuietly,
    /// It acts outside the main window, the core can't do it and no page can take it: let go (the bar greys it then,
    /// `app_menu::page_gone`).
    Drop,
}

/// Where a pick goes (pure). The core never reveals the main window for a pick: a person's Hide EchoLedger Windows,
/// Hide from Screen Shares and a whole-screen share stay as they are (`show_main` would end them, as an open by the
/// person does). The core's own commands run in the core; when no page can take another pick the window is opened
/// quietly (`show_main_quietly`: what keeps it off still does), never for a pick that acts outside it.
pub fn route(action: &str, main: crate::lifecycle::Phase) -> Route {
    use crate::lifecycle::Phase;
    if core_step(action).is_some() {
        return Route::Core;
    }
    match main {
        Phase::Creating | Phase::Ready => Route::Send,
        Phase::Absent | Phase::Closing if acts_outside_main(action) => Route::Drop,
        Phase::Absent | Phase::Closing => Route::OpenQuietly,
    }
}

/// A pick from a native menu or the menu bar, by [`route`]: the core's own command run here, else the main window's page
/// runs its action ("view.sortBy=newest" with a choice). An in-window command brings a minimised or put-away window
/// forward, quietly (every hide reason kept).
pub async fn picked(app: &AppHandle, id: &str) -> crate::error::AppResult<()> {
    let (Some(pick), Some(payload)) = (parse_item_id(id), picked_action(id)) else { return Ok(()) };
    #[cfg(target_os = "macos")]
    crate::app_menu::keep_tick(id);
    let main = crate::lifecycle::phases(app, &[crate::lifecycle::MAIN])[0].1;
    match route(&pick.action, main) {
        Route::Core => {
            return match core_step(&pick.action) {
                Some(step) => run_core(app, step).await,
                None => Ok(()),
            };
        }
        Route::Drop => return Ok(()),
        Route::OpenQuietly => crate::lifecycle::show_main_quietly(app),
        Route::Send if !acts_outside_main(&pick.action) => crate::lifecycle::bring_forward_quietly(app),
        Route::Send => {}
    }
    crate::lifecycle::emit(app, crate::lifecycle::MAIN, EVENT, payload);
    Ok(())
}

/// One line as the system draws it: a hairline, a greyed title, a sub-menu (one level), a tick or a plain item. An item
/// whose target or value has no id (empty) isn't drawn.
fn native_line(app: &AppHandle, item: &NativeItem, depth: usize, menu: Option<u64>) -> Result<Option<Box<dyn IsMenuItem<Wry>>>, String> {
    let e = |e: tauri::Error| e.to_string();
    let id = |action| item_id(&Pick { action, target: item.target.as_deref(), value: item.value.as_deref(), menu });
    Ok(Some(match (&item.action, &item.items, item.checked) {
        (None, _, _) if item.label.is_empty() => Box::new(PredefinedMenuItem::separator(app).map_err(e)?),
        (None, _, _) => Box::new(MenuItem::new(app, &item.label, false, None::<&str>).map_err(e)?),
        (Some(_), Some(items), _) if depth == 0 => {
            let sub = Submenu::new(app, &item.label, item.enabled).map_err(e)?;
            for child in items {
                if let Some(line) = native_line(app, child, depth + 1, menu)? {
                    sub.append(line.as_ref()).map_err(e)?;
                }
            }
            Box::new(sub)
        }
        (Some(action), _, checked) => {
            let Some(id) = id(action.as_str()) else { return Ok(None) };
            match checked {
                Some(checked) => Box::new(CheckMenuItem::with_id(app, id, &item.label, item.enabled, checked, item.accelerator.as_deref()).map_err(e)?),
                None => Box::new(MenuItem::with_id(app, id, &item.label, item.enabled, item.accelerator.as_deref()).map_err(e)?),
            }
        }
    }))
}

/// Opens the page's menu at `x`, `y` (CSS pixels from the window's top left), drawn by the system. Nothing of it stays
/// on the page's side: a pick comes back as `menu:action` with its id after the prefix, and with `menu` (a right-click
/// menu's number) its every pick carries `~<menu>`.
#[tauri::command]
pub async fn native_menu_popup(window: Window, items: Vec<NativeItem>, x: f64, y: f64, menu: Option<u64>) -> Result<(), String> {
    let app = window.app_handle().clone();
    let lines = Menu::new(&app).map_err(|e| e.to_string())?;
    for item in &items {
        if let Some(line) = native_line(&app, item, 0, menu)? {
            lines.append(line.as_ref()).map_err(|e| e.to_string())?;
        }
    }
    window.popup_menu_at(&lines, LogicalPosition::new(x, y)).map_err(|e| e.to_string())
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
    fn picked(action: &str, target: Option<&str>, value: Option<&str>, menu: Option<u64>) -> Picked {
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
        // UX-22's case: a value's own "~" before the menu's number.
        let id = item_id(&Pick { value: Some("a~b"), menu: Some(12), ..Pick::of("recording.labels") }).unwrap();
        assert_eq!(id, "action:recording.labels=a%7Eb~12");
        assert_eq!(parse_item_id(&id), Some(picked("recording.labels", None, Some("a~b"), Some(12))));
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
    fn a_pick_never_reveals_the_main_window_and_is_kept_while_it_is_being_closed() {
        use crate::lifecycle::{Delivery, MAIN, Phase, Slots};
        use std::time::Instant;
        // The core's own commands run in the core, page or not (the tray has them too).
        for phase in [Phase::Absent, Phase::Closing, Phase::Creating, Phase::Ready] {
            for action in ["live.hide15", "live.hide60", "live.hideUntilQuit", "live.stopHiding", "live.hideWordsNow", "live.captions", "live.autoRecord", "live.pauseRecording", "live.stopRecording", "window.pill"] {
                assert_eq!(route(action, phase), Route::Core, "{action} {phase:?}");
            }
        }
        // Its page takes any other: nothing shown (hiding stays hidden, a share stays clean).
        for action in ["go.library", "window.captions", "live.translate"] {
            assert_eq!(route(action, Phase::Ready), Route::Send, "{action}");
            assert_eq!(route(action, Phase::Creating), Route::Send, "{action}");
        }
        // No page: an in-window command opens it quietly; one that acts outside it, which the core can't do, is let go
        // (and greyed: app_menu::pageless_look).
        for phase in [Phase::Absent, Phase::Closing] {
            for action in ["go.library", "file.newRecording", "view.sidebar", "live.startAssist", "live.pastReports"] {
                assert_eq!(route(action, phase), Route::OpenQuietly, "{action} {phase:?}");
            }
            for action in ["window.captions", "window.assistCard", "live.translate", "live.dictation"] {
                assert_eq!(route(action, phase), Route::Drop, "{action} {phase:?}");
            }
        }
        // picked(): never show_main (it ends a person's hide and shows the window in a share), only the quiet open, and
        // an in-window command brings a minimised or put-away window forward quietly.
        let code = include_str!("native_menu.rs");
        let body = &code[code.find("pub async fn picked(app: &AppHandle, id: &str)").unwrap()..];
        let body = &body[..body.find("\n}\n").unwrap()];
        assert!(body.contains("crate::lifecycle::show_main_quietly(app)"));
        assert!(body.contains("Route::Send if !acts_outside_main(&pick.action) => crate::lifecycle::bring_forward_quietly(app)"));
        assert!(!body.contains("show_main(app)") && !body.contains("opened_by_person"));
        let lifecycle = include_str!("lifecycle.rs");
        let forward = &lifecycle[lifecycle.find("pub fn bring_forward_quietly(").unwrap()..];
        let forward = &forward[..forward.find("\n}\n").unwrap()];
        assert!(forward.contains("reveal_main(app, true)") && forward.contains("is_minimized()") && forward.contains("is_visible()"));
        // A quiet open keeps the window off while the person hid the windows, and during a whole-screen share.
        use crate::privacy::{Choice, HideReason, Privacy};
        let choice = Choice { hiding: crate::settings::ShareHiding::All, demo: false, main_hidden: true, show_card: false, show_captions: false };
        for reason in [HideReason::Person, HideReason::Share] {
            let privacy = Privacy::new();
            privacy.set_hidden(reason, true);
            assert!(!privacy.may_show_main(&choice, true), "{reason:?}: stays hidden");
        }
        // While the window is being closed, the quiet open asks for a new one, so the pick waits for its page.
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
    fn with_the_window_closed_hide_from_screen_shares_really_hides_and_the_others_run_in_the_core() {
        use crate::lifecycle::Phase;
        use crate::privacy::share::{HideFor, hide_until, main_hidden};
        // The window closed to the menu bar: Hide from Screen Shares › For 15 Minutes is the core's.
        assert_eq!(route("live.hide15", Phase::Absent), Route::Core);
        assert_eq!(core_step("live.hide15"), Some(CoreStep::HideFromShares(HideFor::Minutes15)));
        // What the core does for it is the tray's own hide: the main window is hidden from shares until then.
        let now = chrono::Local::now();
        let hide = hide_until(HideFor::Minutes15, now, false).unwrap();
        assert!(main_hidden(Some(&hide), now.timestamp_millis()));
        assert!(!main_hidden(Some(&hide), now.timestamp_millis() + 16 * 60 * 1000));
        let code = include_str!("native_menu.rs");
        let run = &code[code.find("async fn run_core(").unwrap()..];
        let run = &run[..run.find("\n}\n").unwrap()];
        for step in [
            "CoreStep::HideFromShares(choice) => crate::privacy::hide_main(app, choice)",
            "CoreStep::StopHiding => crate::privacy::show_main_in_shares(app, None)",
            "crate::privacy::set_hidden(app, crate::privacy::HideReason::Person, true)",
            "crate::actions::stop_recording(app, false)",
            "CoreStep::TogglePause => crate::actions::toggle_pause(app)",
            "CoreStep::ToggleCaptions => crate::actions::toggle_captions(app)",
            "crate::actions::set_pill_hidden(app, !hidden)",
        ] {
            assert!(run.contains(step), "{step}");
        }
        // Every core command has its step but Hide from Screen Shares, its times' sub-menu.
        for action in CORE_RUNS {
            assert_eq!(core_step(action).is_some(), action != "live.hideFromShares", "{action}");
        }
        // The tray hands every pick to picked(), and a failure is said as the tray's own are.
        assert!(include_str!("tray.rs").contains("id if crate::native_menu::picked_action(id).is_some() => crate::native_menu::picked(&app, id).await,"));
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
        let pick = Pick { value: sub[1].value.as_deref(), menu: Some(3), ..Pick::of(sub[1].action.as_deref().unwrap()) };
        assert_eq!(item_id(&pick).as_deref(), Some("action:recording.moveToProject=c1~3"));
    }

    #[test]
    fn its_picks_reach_the_main_window_through_the_menus_handler() {
        let tray = include_str!("tray.rs");
        assert!(tray.contains("id if crate::native_menu::picked_action(id).is_some() => crate::native_menu::picked(&app, id).await,"));
        assert!(include_str!("lib.rs").contains("native_menu::native_menu_popup,"));
        assert!(include_str!("../../src/lib/ipc.ts").contains(r#"invoke<void>("native_menu_popup", { items, x, y, menu: menu ?? null })"#));
    }
}
