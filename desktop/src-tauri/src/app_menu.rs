//! The Mac's app menu (Phase 1B), in the menu bar while EchoLedger is in front (a normal app since v1.8.1, `app_life`).
//! The EchoLedger menu has what every Mac app's has, in its order: About EchoLedger, Settings… (⌘,), Services, Hide
//! EchoLedger (⌘H), Hide Others (⌥⌘H), Show All and Quit EchoLedger (⌘Q). ⌘H hides the main window only: the floating
//! windows cannot hide (`overlay::macos`), so the recording controls and captions stay on screen. ⌘W closes the window
//! while EchoLedger keeps running in the Dock and the menu bar (as Windows closes to the notification area), ⌘Q quits
//! the way the menu bar icon's Quit does (a running recording is kept first), and the Edit keys work in every text
//! field. The two items of our own are answered by the tray's menu handler (Tauri hands it every menu's events).

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

#[cfg(test)]
mod tests {
    use super::*;
    use crate::shortcuts::label;

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
