//! FIX-10: the main window is an app, not a web page. On Windows, WebView2 has browser keys of its own (reload, print the
//! page, the find bar, downloads, history, the developer tools), fills forms from its saved entries and offers to save
//! passwords: they go off at the source, as the window is made. Autofill and password saving in every build; the
//! browser keys in a release build only (a development build keeps reload and the tools). The page's own guard,
//! src/lib/nativeFeel.ts, takes reload, print and the tools on every system once the page runs. Tauri 2.12 has a switch only for
//! general autofill, so this sets WebView2's own settings (ICoreWebView2Settings3 and 4, the interfaces wry itself
//! uses); webview2-com 0.39.1 and windows 0.62.2 are the versions Tauri's wry is built with (Cargo.lock).
//!
//! A Mac's WKWebView has no browser keys or saved forms of its own; its menu and keys are the page's to stop.

use tauri::{Runtime, WebviewWindow};

/// The entries WebView2's own menu keeps (on a field or a selection, which the page lets through): the names WebView2
/// gives them. Everything else it would offer (Print, Share, Web capture, Save as, Inspect…) goes.
pub const MENU_KEEPS: [&str; 4] = ["cut", "copy", "paste", "selectAll"];

/// Turns WebView2's browser keys, autofill and password saving off in this window, and trims its own right-click menu
/// to Cut, Copy, Paste and Select All (Windows only; nothing elsewhere).
pub fn quiet_browser<R: Runtime>(window: &WebviewWindow<R>) {
    #[cfg(windows)]
    {
        let _ = window.with_webview(|webview| {
            use webview2_com::Microsoft::Web::WebView2::Win32::{ICoreWebView2_11, ICoreWebView2Settings3, ICoreWebView2Settings4};
            use webview2_com::{ContextMenuRequestedEventHandler, take_pwstr};
            use windows::core::{Interface, PWSTR};
            // SAFETY: WebView2's COM calls on the controller this window owns, made on the thread Tauri runs this on.
            unsafe {
                let Ok(core) = webview.controller().CoreWebView2() else { return };
                let Ok(settings) = core.Settings() else { return };
                // The browser's keys only in a release build: a development build keeps reload and the developer tools.
                if !cfg!(debug_assertions)
                    && let Ok(keys) = settings.cast::<ICoreWebView2Settings3>()
                {
                    let _ = keys.SetAreBrowserAcceleratorKeysEnabled(false);
                }
                if let Ok(forms) = settings.cast::<ICoreWebView2Settings4>() {
                    let _ = forms.SetIsPasswordAutosaveEnabled(false);
                    let _ = forms.SetIsGeneralAutofillEnabled(false);
                }
                // Its menu on a field or a selection: Cut, Copy, Paste and Select All only (MENU_KEEPS).
                if let Ok(core11) = core.cast::<ICoreWebView2_11>() {
                    let mut token = 0i64;
                    let _ = core11.add_ContextMenuRequested(
                        &ContextMenuRequestedEventHandler::create(Box::new(|_, args| {
                            let Some(args) = args else { return Ok(()) };
                            let items = args.MenuItems()?;
                            let mut count = 0u32;
                            items.Count(&mut count)?;
                            for index in (0..count).rev() {
                                let item = items.GetValueAtIndex(index)?;
                                let mut name = PWSTR::null();
                                item.Name(&mut name)?;
                                if !MENU_KEEPS.contains(&take_pwstr(name).as_str()) {
                                    items.RemoveValueAtIndex(index)?;
                                }
                            }
                            Ok(())
                        })),
                        &mut token,
                    );
                }
            }
        });
    }
    #[cfg(not(windows))]
    let _ = window;
}

#[cfg(test)]
mod tests {
    #[test]
    fn its_own_menu_keeps_only_cut_copy_paste_and_select_all() {
        assert_eq!(super::MENU_KEEPS, ["cut", "copy", "paste", "selectAll"]);
        let code = include_str!("native_feel.rs");
        assert!(code.contains("add_ContextMenuRequested") && code.contains("RemoveValueAtIndex"));
    }

    #[test]
    fn the_main_window_turns_the_browser_off_before_it_shows() {
        let lifecycle = include_str!("lifecycle.rs");
        let build = &lifecycle[lifecycle.find("fn build_main").unwrap()..];
        let quiet = build.find("crate::native_feel::quiet_browser(&window);").expect("build_main calls it");
        assert!(quiet < build.find("tauri::async_runtime::spawn").unwrap(), "before the fallback that shows the window");
        let cargo = include_str!("../Cargo.toml");
        assert!(cargo.contains("webview2-com = \"0.39.1\""), "the version wry uses");
    }
}
