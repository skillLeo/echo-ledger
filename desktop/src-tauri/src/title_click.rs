//! A double click on the main window's title row (UX-20): what the Mac's own setting says, in System Settings ›
//! Desktop & Dock › "Double-click a window's title bar to" (the global default `AppleActionOnDoubleClick`, or before it
//! `AppleMiniaturizeOnDoubleClick`). Tauri's drag script always zooms; the page (src/lib/titleClick.ts) stops that and
//! asks here instead. Off a Mac the page never asks (Windows maximizes, as Tauri does).

use tauri::Window;

/// What a double click on the title bar does.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum TitleAction {
    /// Zoom (the window fills the screen and back), as Tauri's own double click does. "Fill" (macOS 15) is taken as it.
    Zoom,
    Minimize,
    Nothing,
}

/// The setting's values as `defaults read -g` prints them (pure). Unset, the old switch decides; neither set: zoom,
/// as macOS does.
pub fn title_action(action: Option<&str>, minimize: Option<&str>) -> TitleAction {
    match action.map(str::trim) {
        Some("Minimize") => TitleAction::Minimize,
        Some("None") | Some("Do Nothing") => TitleAction::Nothing,
        Some(_) => TitleAction::Zoom,
        None => match minimize.map(str::trim) {
            Some("1") | Some("YES") | Some("true") => TitleAction::Minimize,
            _ => TitleAction::Zoom,
        },
    }
}

#[cfg(target_os = "macos")]
fn read_setting() -> TitleAction {
    let read = |key: &str| {
        std::process::Command::new("/usr/bin/defaults")
            .args(["read", "-g", key])
            .output()
            .ok()
            .filter(|out| out.status.success())
            .map(|out| String::from_utf8_lossy(&out.stdout).trim().to_string())
    };
    title_action(read("AppleActionOnDoubleClick").as_deref(), read("AppleMiniaturizeOnDoubleClick").as_deref())
}

#[cfg(not(target_os = "macos"))]
fn read_setting() -> TitleAction {
    TitleAction::Zoom
}

/// The page's double click on its title row: zoom, minimize or nothing, as the Mac's setting says. Nothing in full
/// screen.
#[tauri::command]
pub async fn title_double_click(window: Window) -> Result<(), String> {
    if window.is_fullscreen().unwrap_or(false) {
        return Ok(());
    }
    let action = tauri::async_runtime::spawn_blocking(read_setting).await.unwrap_or(TitleAction::Zoom);
    let done = match action {
        TitleAction::Zoom => {
            if !window.is_resizable().unwrap_or(true) {
                Ok(())
            } else if window.is_maximized().unwrap_or(false) {
                window.unmaximize()
            } else {
                window.maximize()
            }
        }
        TitleAction::Minimize => window.minimize(),
        TitleAction::Nothing => Ok(()),
    };
    done.map_err(|e| e.to_string())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_macs_setting_decides_what_a_double_click_on_the_title_row_does() {
        assert_eq!(title_action(Some("Maximize"), None), TitleAction::Zoom);
        assert_eq!(title_action(Some("Fill"), None), TitleAction::Zoom);
        assert_eq!(title_action(Some("Minimize\n"), None), TitleAction::Minimize);
        assert_eq!(title_action(Some("None"), None), TitleAction::Nothing);
        // The setting wins over the old switch; with neither set, zoom (macOS's own default).
        assert_eq!(title_action(Some("Maximize"), Some("1")), TitleAction::Zoom);
        assert_eq!(title_action(None, Some("1")), TitleAction::Minimize);
        assert_eq!(title_action(None, Some("0")), TitleAction::Zoom);
        assert_eq!(title_action(None, None), TitleAction::Zoom);
    }

    #[test]
    fn the_command_is_registered_and_the_main_window_may_call_it() {
        let lib = include_str!("lib.rs");
        assert!(lib.contains("title_click::title_double_click,"), "in the invoke handler");
        assert!(lib.contains("pub mod title_click;"));
        let ipc = include_str!("../../src/lib/ipc.ts");
        assert!(ipc.contains(r#"titleDoubleClick: () => invoke<void>("title_double_click"),"#));
        assert!(include_str!("../../src/lib/titleClick.ts").contains("api.titleDoubleClick()"));
    }
}
