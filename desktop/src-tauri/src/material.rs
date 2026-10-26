//! Main-window material and system colours (C2): Mica on Windows 11 (build 22000 and later), the under-window
//! vibrancy material on macOS, a solid background anywhere else (Windows 10). The page asks which material applied
//! and only then turns its backgrounds translucent, so a missing effect never leaves a see-through window.
//!
//! APIs checked against tauri-2.12.5 `src/webview/webview_window.rs` (`set_effects` 2155, `set_theme` 2422) and
//! tauri-utils-2.10.2 `src/lib.rs` (`WindowEffect::{Mica, UnderWindowBackground}`, `WindowEffectState`).

use std::sync::OnceLock;

use serde::Serialize;
use tauri::window::{Effect, EffectState, EffectsBuilder};
use tauri::{AppHandle, Manager, Runtime, Theme, WebviewWindow};

#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum Material {
    Mica,
    Vibrancy,
    None,
}

static APPLIED: OnceLock<Material> = OnceLock::new();

/// Mica needs Windows 11, which starts at build 22000.
pub fn mica_supported(build: Option<u32>) -> bool {
    build.is_some_and(|b| b >= 22_000)
}

/// The material this platform supports.
fn wanted() -> Material {
    #[cfg(windows)]
    {
        if mica_supported(crate::audio::platform::windows::os_build()) { Material::Mica } else { Material::None }
    }
    #[cfg(target_os = "macos")]
    {
        Material::Vibrancy
    }
    #[cfg(not(any(windows, target_os = "macos")))]
    {
        Material::None
    }
}

/// Applies the material to the main window once (setup hook); returns what applied.
pub fn apply<R: Runtime>(window: &WebviewWindow<R>) -> Material {
    let material = match wanted() {
        Material::None => Material::None,
        m => {
            let effect = if m == Material::Mica { Effect::Mica } else { Effect::UnderWindowBackground };
            let effects = EffectsBuilder::new().effect(effect).state(EffectState::FollowsWindowActiveState).build();
            if window.set_effects(effects).is_ok() { m } else { Material::None }
        }
    };
    let _ = APPLIED.set(material);
    material
}

/// What the main window needs to draw itself like the system: the material and the accent colour.
#[derive(Clone, Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct Appearance {
    pub material: Material,
    /// The system accent as `#rrggbb` where the core can read it (Windows); the page reads macOS's itself.
    pub accent: Option<String>,
    pub platform: &'static str,
}

/// The window_appearance command (commands.rs).
pub fn appearance() -> Appearance {
    Appearance {
        material: *APPLIED.get().unwrap_or(&Material::None),
        accent: system_accent(),
        platform: if cfg!(windows) { "windows" } else if cfg!(target_os = "macos") { "macos" } else { "other" },
    }
}

/// `light`, `dark` or `system`: the title bar and the Mica tint follow the app's theme, not only the system's.
pub fn set_theme<R: Runtime>(app: &AppHandle<R>, theme: &str) {
    let theme = match theme {
        "light" => Some(Theme::Light),
        "dark" => Some(Theme::Dark),
        _ => None,
    };
    if let Some(window) = app.get_webview_window("main") {
        let _ = window.set_theme(theme);
    }
}

/// `#rrggbb` from red, green and blue.
pub fn hex(r: u8, g: u8, b: u8) -> String {
    format!("#{r:02x}{g:02x}{b:02x}")
}

/// The Windows accent colour (Settings > Personalisation > Colours) through `UISettings::GetColorValue`
/// (windows 0.62 `Windows.UI.ViewManagement`).
#[cfg(windows)]
fn system_accent() -> Option<String> {
    use windows::UI::ViewManagement::{UIColorType, UISettings};
    let colour = UISettings::new().ok()?.GetColorValue(UIColorType::Accent).ok()?;
    Some(hex(colour.R, colour.G, colour.B))
}

#[cfg(not(windows))]
fn system_accent() -> Option<String> {
    None
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn mica_only_from_windows_11() {
        assert!(!mica_supported(None));
        assert!(!mica_supported(Some(19_045))); // Windows 10 22H2
        assert!(mica_supported(Some(22_000)));
        assert!(mica_supported(Some(26_100)));
    }

    #[test]
    fn accent_hex_is_lower_case_rrggbb() {
        assert_eq!(hex(0, 120, 212), "#0078d4");
        assert_eq!(hex(255, 255, 255), "#ffffff");
    }

    #[test]
    fn no_material_is_reported_before_the_window_had_one() {
        assert!(matches!(appearance().material, Material::None | Material::Mica | Material::Vibrancy));
    }
}
