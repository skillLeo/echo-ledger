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
        _ => crate::native_menu::item_id(action, None, value),
    }
}

/// The page's menu bar (FIX-05): drawn by the system, macOS's own items for the standard commands; the Window and Help
/// menus set as the app's, so macOS adds the window list, Fill and Center, and the Help search that finds any item.
/// Elsewhere (Windows) there is no menu bar: the same commands are in the avatar's menu and the search (3b).
#[tauri::command]
pub fn app_menu_set(app: AppHandle, bar: MenuBar) -> Result<(), String> {
    #[cfg(target_os = "macos")]
    {
        draw(&app, &bar).map_err(|e| e.to_string())
    }
    #[cfg(not(target_os = "macos"))]
    {
        let _ = (app, bar);
        Ok(())
    }
}

#[cfg(target_os = "macos")]
fn draw<R: tauri::Runtime>(app: &tauri::AppHandle<R>, bar: &MenuBar) -> tauri::Result<()> {
    use tauri::menu::{AboutMetadata, CheckMenuItem, IsMenuItem, Menu, MenuItem, PredefinedMenuItem, Submenu};

    fn entries<R: tauri::Runtime>(app: &tauri::AppHandle<R>, items: &[BarEntry]) -> tauri::Result<Vec<Box<dyn IsMenuItem<R>>>> {
        let mut out: Vec<Box<dyn IsMenuItem<R>>> = Vec::new();
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
                        // The predefined Close sends the window its close, which `lifecycle::main_closing` turns into
                        // closing to the menu bar.
                        "closeWindow" => PredefinedMenuItem::close_window(app, None),
                        "minimize" => PredefinedMenuItem::minimize(app, None),
                        "maximize" => PredefinedMenuItem::maximize(app, None),
                        "fullscreen" => PredefinedMenuItem::fullscreen(app, None),
                        "bringAllToFront" => PredefinedMenuItem::bring_all_to_front(app, None),
                        _ => continue,
                    };
                    out.push(Box::new(made?));
                }
                BarEntry::Item { action, value, label, accelerator, enabled, checked } => {
                    // A choice whose value isn't one of our ids isn't drawn (its pick would be read as something else).
                    let Some(id) = bar_item_id(action, value.as_deref()) else { continue };
                    // A key the menu can't take is left off rather than losing the item.
                    out.push(match checked {
                        Some(checked) => Box::new(
                            CheckMenuItem::with_id(app, &id, label, *enabled, *checked, accelerator.as_deref())
                                .or_else(|_| CheckMenuItem::with_id(app, &id, label, *enabled, *checked, None::<&str>))?,
                        ),
                        None => Box::new(
                            MenuItem::with_id(app, &id, label, *enabled, accelerator.as_deref()).or_else(|_| MenuItem::with_id(app, &id, label, *enabled, None::<&str>))?,
                        ),
                    });
                }
                BarEntry::Submenu { label, enabled, items, .. } => {
                    let inner = entries(app, items)?;
                    let refs: Vec<&dyn IsMenuItem<R>> = inner.iter().map(|b| b.as_ref()).collect();
                    out.push(Box::new(Submenu::with_items(app, label, *enabled, &refs)?));
                }
            }
        }
        Ok(out)
    }

    let menu = Menu::new(app)?;
    let mut roles = Vec::new();
    for bar_menu in &bar.menus {
        let inner = entries(app, &bar_menu.items)?;
        let refs: Vec<&dyn IsMenuItem<R>> = inner.iter().map(|b| b.as_ref()).collect();
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
    Ok(())
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
        assert_eq!(bar_item_id("file.openRecent", Some("r=1")), None);
        // The page reads back what the core built.
        let page = include_str!("../../src/v2/actions/menuState.ts");
        assert!(page.contains("export function parsePick(payload: string): MenuPick | null {"));
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
