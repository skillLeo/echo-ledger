//! The Mac's app menu (Phase 1B), in the menu bar while EchoLedger is in front (a normal app since v1.8.1, `app_life`).
//! The EchoLedger menu has what every Mac app's has, in its order: About EchoLedger, Settings… (⌘,), Services, Hide
//! EchoLedger (⌘H), Hide Others (⌥⌘H), Show All and Quit EchoLedger (⌘Q). ⌘H hides the main window only: the floating
//! windows cannot hide (`overlay::macos`), so the recording controls and captions stay on screen. ⌘W closes the window
//! while EchoLedger keeps running in the Dock and the menu bar (as Windows closes to the notification area), ⌘Q quits
//! the way the menu bar icon's Quit does (a running recording is kept first), and the Edit keys work in every text
//! field. The two items of our own are answered by the tray's menu handler (Tauri hands it every menu's events).

use tauri::AppHandle;

/// What the keys do; the ids the handler in tray.rs knows.
pub const SETTINGS: &str = "settings";
pub const QUIT: &str = "quit";

/// The items of our own: id, label, accelerator.
pub const OWN_ITEMS: [(&str, &str, &str); 2] = [(SETTINGS, "Settings…", "CmdOrCtrl+,"), (QUIT, "Quit EchoLedger", "CmdOrCtrl+Q")];

/// One line of the EchoLedger menu.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum AppMenuLine {
    /// macOS's own About panel, with the app's name and version.
    About,
    /// One of [`OWN_ITEMS`], by id.
    Own(&'static str),
    /// The system's Services, Hide EchoLedger (⌘H), Hide Others (⌥⌘H) and Show All.
    Services,
    Hide,
    HideOthers,
    ShowAll,
    Separator,
}

/// The EchoLedger menu, in a Mac app's order. Quit is our own item, not the system's: it keeps a running recording first.
pub const APP_MENU: [AppMenuLine; 11] = [
    AppMenuLine::About,
    AppMenuLine::Separator,
    AppMenuLine::Own(SETTINGS),
    AppMenuLine::Separator,
    AppMenuLine::Services,
    AppMenuLine::Separator,
    AppMenuLine::Hide,
    AppMenuLine::HideOthers,
    AppMenuLine::ShowAll,
    AppMenuLine::Separator,
    AppMenuLine::Own(QUIT),
];

#[cfg(target_os = "macos")]
pub fn build<R: tauri::Runtime>(app: &tauri::AppHandle<R>) -> tauri::Result<tauri::menu::Menu<R>> {
    use tauri::menu::{AboutMetadata, MenuBuilder, MenuItem, PredefinedMenuItem, SubmenuBuilder};

    let info = app.package_info();
    let about = AboutMetadata { name: Some(info.name.clone()), version: Some(info.version.to_string()), ..Default::default() };
    let mut app_menu = SubmenuBuilder::new(app, "EchoLedger");
    for line in APP_MENU {
        app_menu = match line {
            AppMenuLine::About => app_menu.item(&PredefinedMenuItem::about(app, None, Some(about.clone()))?),
            AppMenuLine::Own(id) => {
                let (id, label, accelerator) = OWN_ITEMS.into_iter().find(|(own, _, _)| *own == id).expect("one of our own items");
                app_menu.item(&MenuItem::with_id(app, id, label, true, Some(accelerator))?)
            }
            AppMenuLine::Services => app_menu.item(&PredefinedMenuItem::services(app, None)?),
            AppMenuLine::Hide => app_menu.item(&PredefinedMenuItem::hide(app, None)?),
            AppMenuLine::HideOthers => app_menu.item(&PredefinedMenuItem::hide_others(app, None)?),
            AppMenuLine::ShowAll => app_menu.item(&PredefinedMenuItem::show_all(app, None)?),
            AppMenuLine::Separator => app_menu.separator(),
        };
    }
    let app_menu = app_menu.build()?;
    let edit = SubmenuBuilder::new(app, "Edit").undo().redo().separator().cut().copy().paste().select_all().build()?;
    // The predefined Close sends the window its close, which `lifecycle::main_closing` turns into closing to the menu bar.
    let window = SubmenuBuilder::new(app, "Window").item(&PredefinedMenuItem::minimize(app, None)?).item(&PredefinedMenuItem::close_window(app, None)?).build()?;
    MenuBuilder::new(app).item(&app_menu).item(&edit).item(&window).build()
}

/// One line of the menu bar the page sends (FIX-05, src/v2/actions/menuBar.ts): our item (an action, with a sub-menu
/// choice's value), one of macOS's own, a line, or a sub-menu.
#[derive(Debug, Clone, serde::Deserialize, PartialEq)]
#[serde(tag = "kind", rename_all = "lowercase")]
pub enum BarEntry {
    Item {
        action: String,
        value: Option<String>,
        label: String,
        accelerator: Option<String>,
        enabled: bool,
        checked: Option<bool>,
        /// One of the core's own commands (native_menu::CORE_RUNS): what its look follows while there is no page.
        #[serde(default)]
        core: Option<CoreItem>,
    },
    Native {
        action: String,
        native: String,
    },
    Separator,
    Submenu {
        action: Option<String>,
        label: String,
        enabled: bool,
        items: Vec<BarEntry>,
    },
}

/// One menu of the bar: its title, its items, and whether it is the app's Window or Help menu.
#[derive(Debug, Clone, serde::Deserialize, PartialEq)]
pub struct BarMenu {
    pub title: String,
    pub role: Option<String>,
    pub items: Vec<BarEntry>,
}

/// The whole menu bar.
#[derive(Debug, Clone, serde::Deserialize, PartialEq)]
pub struct MenuBar {
    pub menus: Vec<BarMenu>,
}

/// The id a picked item comes back with (pure): Settings… and Quit are answered by the core itself (with or without
/// the page; Quit keeps a running recording first); every other item is an action for the page, with its choice, in
/// the one form every menu uses (`native_menu::item_id`). None: not well formed, and not drawn.
pub fn bar_item_id(action: &str, value: Option<&str>) -> Option<String> {
    match action {
        "app.settings" => Some(SETTINGS.to_string()),
        "app.quit" => Some(QUIT.to_string()),
        _ => crate::native_menu::item_id(&crate::native_menu::Pick { value, ..crate::native_menu::Pick::of(action) }),
    }
}

/// After a pick from the menu bar: a ticked item keeps the tick the app drew (macOS flips it on the click).
#[cfg(target_os = "macos")]
pub fn keep_tick(id: &str) {
    mac::keep_tick(id);
}

/// A core command's look as the page knows it (its actions.json entry), so the core keeps it in step while there is no
/// page: the conditions it needs, the state its words follow with both words, the state its tick shows.
#[derive(Debug, Clone, serde::Deserialize, PartialEq)]
pub struct CoreItem {
    #[serde(default)]
    pub when: Vec<String>,
    pub state: Option<String>,
    pub off: Option<String>,
    pub on: Option<String>,
    pub check: Option<String>,
}

/// What the core knows of the moment, for its own commands' looks while there is no page.
#[derive(Debug, Clone, Copy, Default, PartialEq, Eq)]
pub struct CoreState {
    pub recording: bool,
    pub paused: bool,
    pub captions: bool,
    pub pill_shown: bool,
    pub auto_record: bool,
    pub hiding: bool,
    /// The person's Hide EchoLedger windows is on.
    pub windows_hidden: bool,
    /// This computer records (not a viewer's).
    pub can_record: bool,
}

/// The state and condition names the core follows (the same as the page's, v2/actions/registry.ts CONDITIONS and the
/// menus' state).
pub const CORE_KEYS: [&str; 9] = ["recording", "notRecording", "paused", "captionsShown", "pillShown", "autoRecord", "hiding", "windowsHidden", "canRecord"];

impl CoreState {
    /// Whether a condition or a state holds now (pure); one it doesn't know never does.
    pub fn holds(&self, key: &str) -> bool {
        match key {
            "recording" => self.recording,
            "notRecording" => !self.recording,
            "paused" => self.paused,
            "captionsShown" => self.captions,
            "pillShown" => self.pill_shown,
            "autoRecord" => self.auto_record,
            "hiding" => self.hiding,
            "windowsHidden" => self.windows_hidden,
            "canRecord" => self.can_record,
            _ => false,
        }
    }
}

/// Whether the core answers an action itself (pure): Settings…, Quit and its own commands.
pub fn core_answers(action: &str) -> bool {
    action == "app.settings" || action == "app.quit" || crate::native_menu::CORE_RUNS.contains(&action)
}

/// An item's or a sub-menu's look with no page (pure): the core's own commands follow the core's state; what only the
/// page could do is greyed (its words and tick stay); a sub-menu with no action of its own (Find, Theme) stays as it is.
pub fn pageless_look(action: Option<&str>, core: Option<&CoreItem>, drawn: &Look, state: &CoreState) -> Look {
    match (action, core) {
        (_, Some(core)) => {
            let on = core.state.as_deref().map(|key| state.holds(key));
            Look {
                label: match (on, &core.off, &core.on) {
                    (Some(true), _, Some(words)) | (Some(false), Some(words), _) => words.clone(),
                    _ => drawn.label.clone(),
                },
                enabled: core.when.iter().all(|key| state.holds(key)),
                checked: core.check.as_deref().map(|key| state.holds(key)).or(drawn.checked),
            }
        }
        (Some(action), None) if !core_answers(action) => Look { enabled: false, ..drawn.clone() },
        _ => drawn.clone(),
    }
}

/// The main window's page went away (closed to the menu bar): the bar greys what only it could do, and keeps the core's
/// own commands in step from now on (`core_follow`).
#[cfg(target_os = "macos")]
pub fn page_gone(app: &AppHandle) {
    let handle = app.clone();
    let _ = app.run_on_main_thread(move || mac::follow(&handle));
}

/// The status changed: with no page, the core's own commands follow it (Pause or Resume Recording, Stop Hiding…).
/// Posted to the main thread, never waited for, and takes no lock here: whether there is a page is read there, without
/// waiting for the window lifecycle's lock (`lifecycle::main_phase_now`), so no caller of the status can be holding it.
#[cfg(target_os = "macos")]
pub fn core_follow(app: &AppHandle) {
    post_follow(app, 0);
}

/// How many times the core's look is tried again while the lifecycle's lock is busy (a recording's caption and pill
/// events take it for each event), and how long apart.
pub const FOLLOW_TRIES: u8 = 5;
pub const FOLLOW_RETRY_MS: u64 = 40;

/// What one look at the main window decides for the core's own commands.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum FollowStep {
    /// No page: the core keeps its commands in step.
    Follow,
    /// The page draws the bar, or the lock stayed busy every try: nothing to do now (the next status looks again).
    Skip,
    /// The lifecycle's lock was busy: look again a moment later.
    Retry,
}

/// The decision (pure): `phase` as read without waiting (none: the lock was busy), `tries` the looks before this one.
pub fn follow_step(phase: Option<crate::lifecycle::Phase>, tries: u8) -> FollowStep {
    use crate::lifecycle::Phase;
    match phase {
        Some(Phase::Absent | Phase::Closing) => FollowStep::Follow,
        Some(Phase::Creating | Phase::Ready) => FollowStep::Skip,
        None if tries + 1 < FOLLOW_TRIES => FollowStep::Retry,
        None => FollowStep::Skip,
    }
}

/// Posts a look to the main thread (never waited for); a busy lock is looked at again a moment later, from off the main
/// thread, so the main thread never sleeps and never takes the lock it might itself be holding.
#[cfg(target_os = "macos")]
fn post_follow(app: &AppHandle, tries: u8) {
    let handle = app.clone();
    let _ = app.run_on_main_thread(move || match follow_step(crate::lifecycle::main_phase_now(&handle), tries) {
        FollowStep::Follow => mac::follow(&handle),
        FollowStep::Skip => {}
        FollowStep::Retry => {
            let again = handle.clone();
            std::thread::spawn(move || {
                std::thread::sleep(std::time::Duration::from_millis(FOLLOW_RETRY_MS));
                post_follow(&again, tries + 1);
            });
        }
    });
}

/// What an item or a sub-menu shows that can change in place: its words, whether it can be picked, its tick.
#[derive(Debug, Clone, PartialEq)]
pub struct Look {
    pub label: String,
    pub enabled: bool,
    pub checked: Option<bool>,
}

/// The bar's structure (pure): every menu, item, choice, key and line in order, without what changes in place (an item's
/// or a sub-menu's words, greying and tick). A bar of the same structure is updated in place; any other is drawn again.
pub fn structure(bar: &MenuBar) -> String {
    fn strip(items: &mut [BarEntry]) {
        for item in items {
            match item {
                BarEntry::Item { label, enabled, checked, core, .. } => {
                    label.clear();
                    *enabled = false;
                    *checked = checked.map(|_| false);
                    // A core command's words follow the language too: changed in place, as its label is.
                    if let Some(core) = core {
                        core.off = None;
                        core.on = None;
                    }
                }
                BarEntry::Submenu { label, enabled, items, .. } => {
                    label.clear();
                    *enabled = false;
                    strip(items);
                }
                BarEntry::Native { .. } | BarEntry::Separator => {}
            }
        }
    }
    let mut bare = bar.clone();
    for menu in &mut bare.menus {
        strip(&mut menu.items);
    }
    format!("{bare:?}")
}

/// What changes in place, in drawing order (pure): one per item and sub-menu (a sub-menu before its items), the order
/// `draw` keeps their handles in.
pub fn looks(bar: &MenuBar) -> Vec<Look> {
    fn walk(items: &[BarEntry], out: &mut Vec<Look>) {
        for item in items {
            match item {
                BarEntry::Item { label, enabled, checked, .. } => out.push(Look { label: label.clone(), enabled: *enabled, checked: *checked }),
                BarEntry::Submenu { label, enabled, items, .. } => {
                    out.push(Look { label: label.clone(), enabled: *enabled, checked: None });
                    walk(items, out);
                }
                BarEntry::Native { .. } | BarEntry::Separator => {}
            }
        }
    }
    let mut out = Vec::new();
    for menu in &bar.menus {
        walk(&menu.items, &mut out);
    }
    out
}

/// Each item's core rule in drawing order, `None` for a sub-menu or a page item (pure): the same places as [`looks`].
pub fn cores(bar: &MenuBar) -> Vec<Option<CoreItem>> {
    fn walk(items: &[BarEntry], out: &mut Vec<Option<CoreItem>>) {
        for item in items {
            match item {
                BarEntry::Item { core, .. } => out.push(core.clone()),
                BarEntry::Submenu { items, .. } => {
                    out.push(None);
                    walk(items, out);
                }
                BarEntry::Native { .. } | BarEntry::Separator => {}
            }
        }
    }
    let mut out = Vec::new();
    for menu in &bar.menus {
        walk(&menu.items, &mut out);
    }
    out
}

/// The places whose look changed (pure).
pub fn changed(before: &[Look], now: &[Look]) -> Vec<usize> {
    now.iter().zip(before).enumerate().filter(|(_, (n, b))| n != b).map(|(at, _)| at).collect()
}

/// Whether a bar sent by the page is drawn (pure): only while its window is there or being built.
pub fn page_draws(main: crate::lifecycle::Phase) -> bool {
    matches!(main, crate::lifecycle::Phase::Creating | crate::lifecycle::Phase::Ready)
}

/// The page's menu bar (FIX-05), with the new design's shell: drawn by the system, macOS's own items for the standard
/// commands; the Window and Help menus set as the app's, so macOS adds the window list, Fill and Center, and the Help
/// search that finds any item. A bar of the same structure is updated in place, item by item (its words, greying,
/// ticks), so nothing is rebuilt while a menu is open; only new choices or another layout draw it again. None puts
/// classic's own bar back (`build`). Elsewhere (Windows) there is no menu bar: the same commands are in the avatar's
/// menu and the search (3b).
#[tauri::command]
pub fn app_menu_set(app: AppHandle, bar: Option<MenuBar>) -> Result<(), String> {
    #[cfg(target_os = "macos")]
    {
        // A bar sent late by a page that has gone (its debounce ran out while the window closed) would enable what
        // page_gone greyed: only a page that is there, or being built, draws the bar.
        let main = crate::lifecycle::phases(&app, &[crate::lifecycle::MAIN])[0].1;
        if bar.is_some() && !page_draws(main) {
            return Ok(());
        }
        mac::set(&app, bar.as_ref()).map_err(|e| e.to_string())
    }
    #[cfg(not(target_os = "macos"))]
    {
        let _ = (app, bar);
        Ok(())
    }
}

#[cfg(target_os = "macos")]
mod mac {
    use super::{BarEntry, CoreItem, CoreState, Look, MenuBar, bar_item_id, changed, cores, looks, pageless_look, structure};
    use std::sync::Mutex;
    use tauri::menu::{AboutMetadata, CheckMenuItem, IsMenuItem, Menu, MenuItem, PredefinedMenuItem, Submenu};
    use tauri::{AppHandle, Wry};

    /// One of our items or sub-menus as drawn, to change in place.
    enum Handle {
        Item(MenuItem<Wry>),
        Check(CheckMenuItem<Wry>),
        Sub(Submenu<Wry>),
    }

    impl Handle {
        /// Says what changed, and only that.
        fn relabel(&self, before: &Look, now: &Look) -> tauri::Result<()> {
            let text = before.label != now.label;
            let enabled = before.enabled != now.enabled;
            match self {
                Handle::Item(item) => {
                    if text {
                        item.set_text(&now.label)?;
                    }
                    if enabled {
                        item.set_enabled(now.enabled)?;
                    }
                }
                Handle::Check(item) => {
                    if text {
                        item.set_text(&now.label)?;
                    }
                    if enabled {
                        item.set_enabled(now.enabled)?;
                    }
                    if let Some(checked) = now.checked.filter(|c| Some(*c) != before.checked) {
                        item.set_checked(checked)?;
                    }
                }
                Handle::Sub(sub) => {
                    if text {
                        sub.set_text(&now.label)?;
                    }
                    if enabled {
                        sub.set_enabled(now.enabled)?;
                    }
                }
            }
            Ok(())
        }
    }

    /// The bar drawn last: its structure, its looks, and its items' handles in the same order.
    /// One drawn item or sub-menu: its handle, the action it is for, and, for the core's own commands, their look's rule.
    struct Slot {
        handle: Handle,
        action: Option<String>,
        core: Option<CoreItem>,
    }

    struct Drawn {
        structure: String,
        looks: Vec<Look>,
        handles: Vec<Slot>,
    }

    static DRAWN: Mutex<Option<Drawn>> = Mutex::new(None);

    /// A picked item with a tick: the system flipped it on the click, but the tick is the app's state, so it goes back
    /// to what was drawn (ticking View › Theme › System again leaves it ticked); a pick that changes the state redraws it.
    pub fn keep_tick(id: &str) {
        let ticked = {
            let drawn = DRAWN.lock().unwrap_or_else(|e| e.into_inner());
            drawn.as_ref().and_then(|d| {
                d.handles.iter().zip(&d.looks).find_map(|(slot, look)| match &slot.handle {
                    Handle::Check(item) if item.id().0 == id => Some((item.clone(), look.checked.unwrap_or(false))),
                    _ => None,
                })
            })
        };
        if let Some((item, checked)) = ticked {
            let _ = item.set_checked(checked);
        }
    }

    /// What the core reads of the moment for its own commands.
    fn core_state(app: &AppHandle) -> CoreState {
        use tauri::Manager;
        let state = app.state::<crate::AppState>();
        let settings = state.settings();
        let recording = state.capture.is_recording();
        CoreState {
            recording,
            paused: recording && state.capture.is_paused(),
            captions: state.live.is_live(),
            pill_shown: recording && !state.pill_hidden.load(std::sync::atomic::Ordering::SeqCst),
            auto_record: crate::call_rules::records_by_themselves(&state, &settings),
            hiding: crate::privacy::share::main_hidden(settings.main_share_hide.as_ref(), crate::privacy::now_ms()),
            windows_hidden: app.try_state::<crate::privacy::Privacy>().is_some_and(|p| p.hidden_for(crate::privacy::HideReason::Person)),
            can_record: state.viewer_refusal().is_none(),
        }
    }

    /// With no page: each item's look by `pageless_look`, changed in place (on the main thread).
    pub fn follow(app: &AppHandle) {
        let state = core_state(app);
        let Some(mut d) = DRAWN.lock().unwrap_or_else(|e| e.into_inner()).take() else { return };
        let now: Vec<Look> = d.handles.iter().zip(&d.looks).map(|(slot, look)| pageless_look(slot.action.as_deref(), slot.core.as_ref(), look, &state)).collect();
        for at in changed(&d.looks, &now) {
            let _ = d.handles[at].handle.relabel(&d.looks[at], &now[at]);
        }
        d.looks = now;
        // A bar the page drew meanwhile wins.
        let mut drawn = DRAWN.lock().unwrap_or_else(|e| e.into_inner());
        if drawn.is_none() {
            *drawn = Some(d);
        }
    }

    pub fn set(app: &AppHandle, bar: Option<&MenuBar>) -> tauri::Result<()> {
        // What was drawn is taken out first: no lock is held while the menu changes on the main thread.
        let last = DRAWN.lock().unwrap_or_else(|e| e.into_inner()).take();
        let Some(bar) = bar else {
            app.set_menu(super::build(app)?)?;
            return Ok(());
        };
        let shape = structure(bar);
        let now = looks(bar);
        let drawn = match last {
            // The same structure: each item says only what changed, in place.
            Some(mut d)
                if d.structure == shape
                    && d.handles.len() == now.len()
                    && changed(&d.looks, &now).into_iter().try_for_each(|at| d.handles[at].handle.relabel(&d.looks[at], &now[at])).is_ok() =>
            {
                d.looks = now;
                // The core rules' words follow the new bar (a language change), as the labels did.
                for (slot, core) in d.handles.iter_mut().zip(cores(bar)) {
                    slot.core = core;
                }
                d
            }
            _ => draw(app, bar, shape, now)?,
        };
        *DRAWN.lock().unwrap_or_else(|e| e.into_inner()) = Some(drawn);
        Ok(())
    }

    fn draw(app: &AppHandle, bar: &MenuBar, structure: String, looks: Vec<Look>) -> tauri::Result<Drawn> {
        fn entries(app: &AppHandle, items: &[BarEntry], handles: &mut Vec<Option<Slot>>) -> tauri::Result<Vec<Box<dyn IsMenuItem<Wry>>>> {
            let mut out: Vec<Box<dyn IsMenuItem<Wry>>> = Vec::new();
            for item in items {
                match item {
                    BarEntry::Separator => out.push(Box::new(PredefinedMenuItem::separator(app)?)),
                    BarEntry::Native { native, .. } => {
                        let info = app.package_info();
                        let made = match native.as_str() {
                            "about" => PredefinedMenuItem::about(app, None, Some(AboutMetadata { name: Some(info.name.clone()), version: Some(info.version.to_string()), ..Default::default() })),
                            "services" => PredefinedMenuItem::services(app, None),
                            "hide" => PredefinedMenuItem::hide(app, None),
                            "hideOthers" => PredefinedMenuItem::hide_others(app, None),
                            "showAll" => PredefinedMenuItem::show_all(app, None),
                            "undo" => PredefinedMenuItem::undo(app, None),
                            "redo" => PredefinedMenuItem::redo(app, None),
                            "cut" => PredefinedMenuItem::cut(app, None),
                            "copy" => PredefinedMenuItem::copy(app, None),
                            "paste" => PredefinedMenuItem::paste(app, None),
                            "selectAll" => PredefinedMenuItem::select_all(app, None),
                            // The predefined Close sends the window its close, which `lifecycle::main_closing` turns
                            // into closing to the menu bar.
                            "closeWindow" => PredefinedMenuItem::close_window(app, None),
                            "minimize" => PredefinedMenuItem::minimize(app, None),
                            "maximize" => PredefinedMenuItem::maximize(app, None),
                            "fullscreen" => PredefinedMenuItem::fullscreen(app, None),
                            "bringAllToFront" => PredefinedMenuItem::bring_all_to_front(app, None),
                            _ => continue,
                        };
                        out.push(Box::new(made?));
                    }
                    BarEntry::Item { action, value, label, accelerator, enabled, checked, core } => {
                        // A choice with an empty value isn't drawn (its pick would say nothing); its place stays,
                        // empty, so the handles keep the looks' order.
                        let Some(id) = bar_item_id(action, value.as_deref()) else {
                            handles.push(None);
                            continue;
                        };
                        // A key the menu can't take is left off rather than losing the item.
                        match checked {
                            Some(checked) => {
                                let item = CheckMenuItem::with_id(app, &id, label, *enabled, *checked, accelerator.as_deref())
                                    .or_else(|_| CheckMenuItem::with_id(app, &id, label, *enabled, *checked, None::<&str>))?;
                                handles.push(Some(Slot { handle: Handle::Check(item.clone()), action: Some(action.clone()), core: core.clone() }));
                                out.push(Box::new(item));
                            }
                            None => {
                                let item = MenuItem::with_id(app, &id, label, *enabled, accelerator.as_deref())
                                    .or_else(|_| MenuItem::with_id(app, &id, label, *enabled, None::<&str>))?;
                                handles.push(Some(Slot { handle: Handle::Item(item.clone()), action: Some(action.clone()), core: core.clone() }));
                                out.push(Box::new(item));
                            }
                        }
                    }
                    BarEntry::Submenu { action, label, enabled, items } => {
                        // Its place before its items', as `looks` has it.
                        let place = handles.len();
                        handles.push(None);
                        let inner = entries(app, items, handles)?;
                        let refs: Vec<&dyn IsMenuItem<Wry>> = inner.iter().map(|b| b.as_ref()).collect();
                        let sub = Submenu::with_items(app, label, *enabled, &refs)?;
                        handles[place] = Some(Slot { handle: Handle::Sub(sub.clone()), action: action.clone(), core: None });
                        out.push(Box::new(sub));
                    }
                }
            }
            Ok(out)
        }

        let menu = Menu::new(app)?;
        let mut roles = Vec::new();
        let mut handles = Vec::new();
        for bar_menu in &bar.menus {
            let inner = entries(app, &bar_menu.items, &mut handles)?;
            let refs: Vec<&dyn IsMenuItem<Wry>> = inner.iter().map(|b| b.as_ref()).collect();
            let submenu = Submenu::with_items(app, &bar_menu.title, true, &refs)?;
            menu.append(&submenu)?;
            if let Some(role) = &bar_menu.role {
                roles.push((role.clone(), submenu));
            }
        }
        app.set_menu(menu)?;
        for (role, submenu) in roles {
            match role.as_str() {
                "window" => submenu.set_as_windows_menu_for_nsapp()?,
                "help" => submenu.set_as_help_menu_for_nsapp()?,
                _ => {}
            }
        }
        // A place left empty (an item not drawn) can't be updated: the next bar with it draws again.
        let handles = handles.into_iter().collect::<Option<Vec<_>>>().unwrap_or_default();
        Ok(Drawn { structure, looks, handles })
    }
}

/// No window tabs: macOS would otherwise add "Show Tab Bar" and "Show All Tabs" to View (3b).
#[cfg(target_os = "macos")]
pub fn no_window_tabs() {
    if let Some(mtm) = objc2::MainThreadMarker::new() {
        objc2_app_kit::NSWindow::setAllowsAutomaticWindowTabbing(false, mtm);
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::shortcuts::label;

    #[test]
    fn the_page_s_menu_bar_reads_in_and_its_picks_carry_their_action() {
        let bar: MenuBar = serde_json::from_str(
            r#"{"menus":[{"title":"File","items":[
                {"kind":"item","action":"file.newRecording","label":"New Recording","accelerator":"CmdOrCtrl+N","enabled":true,"checked":null},
                {"kind":"separator"},
                {"kind":"native","action":"file.closeWindow","native":"closeWindow"},
                {"kind":"submenu","action":"file.openRecent","label":"Open Recent","enabled":true,"items":[
                  {"kind":"item","action":"file.openRecent","value":"r1","label":"Bilal Qureshi","accelerator":null,"enabled":true,"checked":false}]}]},
              {"title":"Window","role":"window","items":[]}]}"#,
        )
        .unwrap();
        assert_eq!(bar.menus.len(), 2);
        assert_eq!(bar.menus[1].role.as_deref(), Some("window"));
        assert!(matches!(&bar.menus[0].items[2], BarEntry::Native { native, .. } if native == "closeWindow"));
        // Settings… and Quit are the core's own; the rest go to the page with their choice.
        assert_eq!(bar_item_id("app.quit", None).as_deref(), Some(QUIT));
        assert_eq!(bar_item_id("app.settings", None).as_deref(), Some(SETTINGS));
        assert_eq!(bar_item_id("file.newRecording", None).as_deref(), Some("action:file.newRecording"));
        // The one form every menu uses: a choice after "=".
        assert_eq!(bar_item_id("file.openRecent", Some("r1")).as_deref(), Some("action:file.openRecent=r1"));
        // A value is an id we build, its "=" "@" "~" "%" percent-encoded all the same; an empty one isn't drawn.
        assert_eq!(bar_item_id("file.openRecent", Some("r=1")).as_deref(), Some("action:file.openRecent=r%3D1"));
        assert_eq!(bar_item_id("file.openRecent", Some("")), None);
        // The page reads back what the core built.
        let page = include_str!("../../src/v2/actions/menuState.ts");
        assert!(page.contains("export function parsePick(payload: string): MenuPick | null {"));
    }

    /// A small bar: Sidebar (words follow the state), a tick, Sort By with its choices.
    fn small_bar(sidebar: &str, enabled: bool, ticked: bool, choices: &[&str]) -> MenuBar {
        let choices: Vec<String> = choices
            .iter()
            .map(|c| format!(r#"{{"kind":"item","action":"view.sortBy","value":"{c}","label":"{c}","accelerator":null,"enabled":true,"checked":false}}"#))
            .collect();
        serde_json::from_str(&format!(
            r#"{{"menus":[{{"title":"View","items":[
                {{"kind":"item","action":"view.sidebar","label":"{sidebar}","accelerator":"Ctrl+CmdOrCtrl+S","enabled":{enabled},"checked":null}},
                {{"kind":"item","action":"live.autoRecord","label":"Auto-record","accelerator":null,"enabled":true,"checked":{ticked}}},
                {{"kind":"separator"}},
                {{"kind":"submenu","action":"view.sortBy","label":"Sort By","enabled":true,"items":[{}]}}]}}]}}"#,
            choices.join(",")
        ))
        .unwrap()
    }

    #[test]
    fn the_same_structure_is_updated_in_place_and_only_new_choices_or_keys_draw_it_again() {
        let before = small_bar("Hide Sidebar", true, false, &["newest", "oldest"]);
        // The words, the greying and the tick change: the same structure, so updated in place, item by item.
        let after = small_bar("Show Sidebar", false, true, &["newest", "oldest"]);
        assert_eq!(structure(&before), structure(&after));
        // Sidebar, Auto-record, Sort By, its two choices: in drawing order, a sub-menu before its items.
        assert_eq!(looks(&before).iter().map(|l| l.label.as_str()).collect::<Vec<_>>(), ["Hide Sidebar", "Auto-record", "Sort By", "newest", "oldest"]);
        assert_eq!(changed(&looks(&before), &looks(&after)), [0, 1]);
        assert_eq!(looks(&after)[0], Look { label: "Show Sidebar".into(), enabled: false, checked: None });
        assert_eq!(looks(&after)[1].checked, Some(true));
        assert_eq!(changed(&looks(&before), &looks(&before)), Vec::<usize>::new());
        // Other choices are another structure: drawn again.
        assert_ne!(structure(&before), structure(&small_bar("Hide Sidebar", true, false, &["newest"])));
        assert_ne!(structure(&before), structure(&small_bar("Hide Sidebar", true, false, &["newest", "title"])));
        // So is another key, and a plain item that becomes a ticked one.
        let mut keyed = before.clone();
        if let BarEntry::Item { accelerator, .. } = &mut keyed.menus[0].items[0] {
            *accelerator = None;
        }
        assert_ne!(structure(&before), structure(&keyed));
        let mut ticked = before.clone();
        if let BarEntry::Item { checked, .. } = &mut ticked.menus[0].items[0] {
            *checked = Some(false);
        }
        assert_ne!(structure(&before), structure(&ticked));
    }

    #[test]
    fn a_busy_lifecycle_lock_is_looked_at_again_a_few_times_then_left_to_the_next_status() {
        use crate::lifecycle::Phase;
        // No page: follow; a page: nothing to do, whatever the try.
        for tries in [0, 3, FOLLOW_TRIES] {
            assert_eq!(follow_step(Some(Phase::Absent), tries), FollowStep::Follow);
            assert_eq!(follow_step(Some(Phase::Closing), tries), FollowStep::Follow);
            assert_eq!(follow_step(Some(Phase::Ready), tries), FollowStep::Skip);
            assert_eq!(follow_step(Some(Phase::Creating), tries), FollowStep::Skip);
        }
        // The lock busy (a caption or pill event holds it): tried again, FOLLOW_TRIES looks in all, then left.
        let looks = (0..=FOLLOW_TRIES).take_while(|&tries| follow_step(None, tries) == FollowStep::Retry).count() + 1;
        assert_eq!(looks, usize::from(FOLLOW_TRIES));
        assert_eq!(follow_step(None, FOLLOW_TRIES - 1), FollowStep::Skip);
        // A busy lock found on the next look: followed then.
        assert_eq!(follow_step(Some(Phase::Absent), 2), FollowStep::Follow);
        // The retry waits off the main thread, then posts again.
        let code = include_str!("app_menu.rs").split("#[cfg(test)]").next().unwrap();
        let post = &code[code.find("fn post_follow(app: &AppHandle, tries: u8)").unwrap()..];
        let post = &post[..post.find("\n}\n").unwrap()];
        assert!(post.contains("std::thread::spawn") && post.contains("post_follow(&again, tries + 1)"));
    }

    #[test]
    fn a_core_command_s_words_change_in_place_with_the_language() {
        let bar = |off: &str, on: &str| -> MenuBar {
            serde_json::from_str(&format!(
                r#"{{"menus":[{{"title":"Live","items":[
                    {{"kind":"item","action":"live.pauseRecording","label":"{off}","accelerator":null,"enabled":true,"checked":null,
                      "core":{{"when":["recording"],"state":"paused","off":"{off}","on":"{on}","check":null}}}}]}}]}}"#
            ))
            .unwrap()
        };
        let english = bar("Pause Recording", "Resume Recording");
        let urdu = bar("ریکارڈنگ روکیں", "ریکارڈنگ جاری رکھیں");
        assert_eq!(structure(&english), structure(&urdu), "updated in place, not drawn again");
        assert_eq!(cores(&urdu)[0].as_ref().and_then(|c| c.on.clone()).as_deref(), Some("ریکارڈنگ جاری رکھیں"));
        let code = include_str!("app_menu.rs").split("#[cfg(test)]").next().unwrap();
        assert!(code.contains("for (slot, core) in d.handles.iter_mut().zip(cores(bar))"), "the rules follow the new bar");
    }

    #[test]
    fn classic_gets_its_own_bar_back_and_the_mac_keeps_what_it_drew_to_update_it() {
        // The page sends null with the new design's shell off: classic's bar, as at start (`build`).
        let none: Option<MenuBar> = serde_json::from_str("null").unwrap();
        assert!(none.is_none());
        let code = include_str!("app_menu.rs").split("#[cfg(test)]").next().unwrap();
        let set = &code[code.find("pub fn set(app: &AppHandle, bar: Option<&MenuBar>)").expect("the Mac's set")..];
        let set = &set[..set.find("\n    }\n").unwrap()];
        assert!(set.contains("app.set_menu(super::build(app)?)?;"), "classic's bar comes back");
        assert!(set.contains("if d.structure == shape"), "the same structure is updated in place");
        assert!(include_str!("lib.rs").contains("builder.menu(app_menu::build)"), "classic's bar at start");
        // The page: classic keeps the core's bar (no leaf), the leaf's last one going puts it back.
        let page = include_str!("../../src/v2/actions/useMenuBar.ts");
        assert!(page.contains("void api.appMenuSet(null)"));
        let main = include_str!("../../src/views/main/MainApp.tsx");
        assert!(main.contains(r#"status?.platform === "macos" && ui.flags["ui_v2.shell"] ? <MenuBar platform="macos" /> : null"#));
    }

    #[test]
    fn with_no_page_its_items_are_greyed_and_the_core_s_own_follow_the_core() {
        let drawn = |label: &str, enabled: bool, checked: Option<bool>| Look { label: label.into(), enabled, checked };
        let idle = CoreState::default();
        let recording = CoreState { recording: true, ..CoreState::default() };
        // What only the page could do: greyed, its words and tick kept.
        assert_eq!(pageless_look(Some("go.library"), None, &drawn("Library", true, None), &idle), drawn("Library", false, None));
        assert_eq!(pageless_look(Some("view.themeDark"), None, &drawn("Dark", true, Some(true)), &idle), drawn("Dark", false, Some(true)));
        assert!(!pageless_look(Some("view.sortBy"), None, &drawn("Sort By", true, None), &idle).enabled);
        // Settings…, Quit, a sub-menu of its own (Find, Theme) and Hide from Screen Shares stay.
        for action in [Some("app.settings"), Some("app.quit"), None, Some("live.hideFromShares")] {
            assert!(pageless_look(action, None, &drawn("x", true, None), &idle).enabled, "{action:?}");
        }
        // The core's own: their conditions, words and ticks from the core's state.
        let pause = CoreItem { when: vec!["recording".into()], state: Some("paused".into()), off: Some("Pause Recording".into()), on: Some("Resume Recording".into()), check: None };
        assert_eq!(pageless_look(Some("live.pauseRecording"), Some(&pause), &drawn("Pause Recording", true, None), &idle), drawn("Pause Recording", false, None));
        assert_eq!(pageless_look(Some("live.pauseRecording"), Some(&pause), &drawn("Pause Recording", false, None), &recording), drawn("Pause Recording", true, None));
        let paused = CoreState { paused: true, ..recording };
        assert_eq!(pageless_look(Some("live.pauseRecording"), Some(&pause), &drawn("Pause Recording", true, None), &paused).label, "Resume Recording");
        let auto = CoreItem { when: vec![], state: None, off: None, on: None, check: Some("autoRecord".into()) };
        assert_eq!(pageless_look(Some("live.autoRecord"), Some(&auto), &drawn("Auto-record", true, Some(false)), &CoreState { auto_record: true, ..idle }).checked, Some(true));
        let stop_hiding = CoreItem { when: vec!["hiding".into()], state: None, off: None, on: None, check: None };
        assert!(!pageless_look(Some("live.stopHiding"), Some(&stop_hiding), &drawn("Stop Hiding", true, None), &idle).enabled);
        assert!(pageless_look(Some("live.stopHiding"), Some(&stop_hiding), &drawn("Stop Hiding", false, None), &CoreState { hiding: true, ..idle }).enabled);
        // Every key the core follows holds when its state does; one it doesn't know never does.
        let all = CoreState { recording: true, paused: true, captions: true, pill_shown: true, auto_record: true, hiding: true, windows_hidden: true, can_record: true };
        for key in CORE_KEYS {
            assert_eq!(all.holds(key), key != "notRecording", "{key}");
        }
        assert!(!all.holds("somethingElse"));
        // Wired: the page going away greys, and the status keeps the core's own in step while there is no page.
        assert!(include_str!("lifecycle.rs").contains("crate::app_menu::page_gone(app);"));
        assert!(include_str!("commands.rs").contains("crate::app_menu::core_follow(app);"));
        // The status path takes no lock of the lifecycle's: the phase is read on the main thread, without waiting.
        let source = include_str!("app_menu.rs").split("#[cfg(test)]").next().unwrap();
        let follow_now = &source[source.find("fn post_follow(app: &AppHandle, tries: u8)").unwrap()..];
        let follow_now = &follow_now[..follow_now.find("\n}\n").unwrap()];
        let posted = follow_now.find("run_on_main_thread").unwrap();
        assert!(!follow_now[..posted].contains("phases(") && !follow_now[..posted].contains("slots("));
        assert!(follow_now[posted..].contains("main_phase_now(&handle)"));
        let start = &source[source.find("pub fn core_follow(app: &AppHandle)").unwrap()..];
        assert!(start[..start.find("\n}\n").unwrap()].contains("post_follow(app, 0)"));
        let lifecycle = include_str!("lifecycle.rs");
        let now = &lifecycle[lifecycle.find("pub fn main_phase_now(").unwrap()..];
        assert!(now[..now.find("\n}\n").unwrap()].contains("try_lock()"), "never waits for the lock");
        // A late bar from a page that has gone is not drawn.
        use crate::lifecycle::Phase;
        assert!(page_draws(Phase::Ready) && page_draws(Phase::Creating));
        assert!(!page_draws(Phase::Closing) && !page_draws(Phase::Absent));
        // Show EchoLedger Windows only while the person's hide is on.
        let show = CoreItem { when: vec!["windowsHidden".into()], state: None, off: None, on: None, check: None };
        assert!(!pageless_look(Some("live.showWindows"), Some(&show), &drawn("Show EchoLedger Windows", true, None), &idle).enabled);
        assert!(pageless_look(Some("live.showWindows"), Some(&show), &drawn("Show EchoLedger Windows", false, None), &CoreState { windows_hidden: true, ..idle }).enabled);
        let code = include_str!("app_menu.rs").split("#[cfg(test)]").next().unwrap();
        let follow = &code[code.find("pub fn follow(app: &AppHandle)").unwrap()..];
        let follow = &follow[..follow.find("\n    }\n").unwrap()];
        assert!(follow.contains(".take()") && follow.contains("pageless_look("), "taken out of its lock before the menu changes");
    }

    #[test]
    fn the_command_is_registered_and_window_tabs_are_off() {
        let lib = include_str!("lib.rs");
        assert!(lib.contains("app_menu::app_menu_set,"));
        assert!(lib.contains("app_menu::no_window_tabs();"));
        assert!(include_str!("../../src/lib/ipc.ts").contains(r#"invoke<void>("app_menu_set", { bar })"#));
    }

    #[test]
    fn the_mac_s_keys_read_as_a_mac_person_expects() {
        let keys: Vec<String> = OWN_ITEMS.iter().map(|(_, _, accelerator)| label(accelerator, true)).collect();
        assert_eq!(keys, ["⌘,", "⌘Q"]);
    }

    #[test]
    fn the_echoledger_menu_reads_like_every_mac_app_s() {
        use AppMenuLine::*;
        assert_eq!(APP_MENU, [About, Separator, Own(SETTINGS), Separator, Services, Separator, Hide, HideOthers, ShowAll, Separator, Own(QUIT)]);
        // Quit is our own (it keeps a running recording first), never the system's terminate.
        let code = include_str!("app_menu.rs").split("#[cfg(test)]").next().unwrap();
        assert!(!code.contains("PredefinedMenuItem::quit") && !code.contains(".quit()"));
        // ⌘H leaves the floating windows on screen: they cannot hide.
        assert!(include_str!("overlay/macos.rs").contains("ns_window.setCanHide(false);"));
    }

    #[test]
    fn quit_is_the_id_the_tray_s_quit_answers() {
        let tray = include_str!("tray.rs");
        let quit = &tray[tray.find("\"quit\" => {").expect("the tray answers quit")..];
        let quit = &quit[..quit.find("app.exit(0);").unwrap()];
        assert!(quit.contains("crate::actions::save_before_quit(&app).await;"), "⌘Q saves a running recording like the menu-bar Quit");
        let actions = include_str!("actions.rs");
        let keep = &actions[actions.find("pub async fn save_before_quit(app: &AppHandle) {").unwrap()..];
        let keep = &keep[..keep.find("\n}\n").unwrap()];
        assert!(keep.contains("capture.is_recording()") && keep.contains("stop_recording_for_quit(app).await"), "it keeps a running recording");
        assert_eq!(QUIT, "quit");
        assert!(tray.contains("crate::app_menu::SETTINGS => {"));
    }
}
