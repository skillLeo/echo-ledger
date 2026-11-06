//! The Mac's app menu (Phase 1B). The app has no Dock icon and shows no menu bar, but macOS still looks here for key
//! equivalents while its window is in front: ⌘, opens Settings, ⌘W closes the window to the menu bar as Windows closes
//! to the tray, ⌘Q quits the way the menu-bar icon's Quit does (a running recording is saved first), and the Edit keys
//! work in every text field. The two items of our own are answered by the tray's menu handler (Tauri hands it every
//! menu's events).

/// What the keys do; the ids the handler in tray.rs knows.
pub const SETTINGS: &str = "settings";
pub const QUIT: &str = "quit";

/// The items of our own: id, label, accelerator.
pub const OWN_ITEMS: [(&str, &str, &str); 2] = [(SETTINGS, "Settings…", "CmdOrCtrl+,"), (QUIT, "Quit EchoLedger", "CmdOrCtrl+Q")];

#[cfg(target_os = "macos")]
pub fn build<R: tauri::Runtime>(app: &tauri::AppHandle<R>) -> tauri::Result<tauri::menu::Menu<R>> {
    use tauri::menu::{MenuBuilder, MenuItem, PredefinedMenuItem, SubmenuBuilder};

    let [settings, quit] = OWN_ITEMS.map(|(id, label, accelerator)| MenuItem::with_id(app, id, label, true, Some(accelerator)));
    let app_menu = SubmenuBuilder::new(app, "EchoLedger").item(&settings?).separator().item(&quit?).build()?;
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
    fn quit_is_the_id_the_tray_s_quit_answers() {
        let tray = include_str!("tray.rs");
        assert!(tray.contains("\"quit\" => {") && tray.contains("stop_recording_for_quit"), "⌘Q saves a running recording like the menu-bar Quit");
        assert_eq!(QUIT, "quit");
        assert!(tray.contains("crate::app_menu::SETTINGS => {"));
    }
}
