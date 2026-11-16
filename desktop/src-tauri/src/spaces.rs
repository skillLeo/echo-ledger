//! EchoLedger and the Mac's Spaces: its menu bar menu over another app's full-screen window, and the main window opened
//! on the Space in use.
//!
//! macOS opens a Dock app's menu bar menu on the desktop, below another app's full-screen window: a click on
//! EchoLedger's icon there only highlighted it. An app without a Dock icon (`NSApplicationActivationPolicyAccessory`)
//! has its menu open over it, as a menu bar app's does; switching when the menu opens is too late. So while another
//! app's full-screen window fills a display (where the Dock is hidden anyway) EchoLedger runs without its Dock icon, and
//! has it back as soon as none does. It is checked each time the Space in use changes, and again once the switch has
//! settled. A click on the icon never activates EchoLedger or shows a window (tray.rs); only "Open EchoLedger" does, and
//! the main window then comes to the Space in use instead of taking the person to the one it was on.

/// A window's or a display's frame: top-left corner and size, in points, the main display's top-left corner at 0, 0.
pub type Frame = ((f64, f64), (f64, f64));

/// Whether EchoLedger keeps its Dock icon.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Dock {
    /// A normal Mac app: Dock icon, ⌘Tab, its menu bar while in front.
    Shown,
    /// While another app's full-screen window fills a display: its menu bar menu opens over that window.
    Away,
}

/// What EchoLedger's Dock icon is while another app's full-screen window does or does not fill a display (pure).
pub fn dock_for(another_app_full_screen: bool) -> Dock {
    if another_app_full_screen { Dock::Away } else { Dock::Shown }
}

/// Whether a window of the system's window list is another app's full-screen window (pure): at the normal window level
/// (layer 0), not EchoLedger's, and filling one display to within a point (a zoomed window leaves the menu bar).
pub fn fills_a_display(ours: bool, layer: i64, window: Frame, displays: &[Frame]) -> bool {
    let close = |a: f64, b: f64| (a - b).abs() <= 1.0;
    !ours
        && layer == 0
        && displays.iter().any(|((x, y), (w, h))| {
            let ((wx, wy), (ww, wh)) = window;
            close(wx, *x) && close(wy, *y) && close(ww, *w) && close(wh, *h)
        })
}

#[cfg(target_os = "macos")]
pub mod mac {
    use super::{Dock, Frame, dock_for, fills_a_display};
    use objc2::MainThreadMarker;
    use objc2_app_kit::{NSApplication, NSApplicationActivationPolicy, NSScreen, NSWindow, NSWindowCollectionBehavior, NSWorkspace, NSWorkspaceActiveSpaceDidChangeNotification};
    use objc2_foundation::{NSNotification, NSOperationQueue};
    use std::ptr::NonNull;
    use std::time::Duration;

    /// How long after a Space change it is checked again: the window list is read once the switch's slide has settled.
    const SETTLED: Duration = Duration::from_millis(700);
    /// The first check after launch, once the launch's own activation policy is applied.
    const AFTER_LAUNCH: Duration = Duration::from_secs(1);

    /// Checks on every change of the Space in use, for the app's whole run; the first check runs after launch.
    pub fn install() {
        // SAFETY: the name is AppKit's constant; the block runs on the main queue, as the check needs.
        let name = unsafe { NSWorkspaceActiveSpaceDidChangeNotification };
        let block = block2::RcBlock::new(|_: NonNull<NSNotification>| {
            check();
            later(SETTLED);
        });
        let centre = NSWorkspace::sharedWorkspace().notificationCenter();
        // SAFETY: no object filter; the main queue; the block lives as long as the observation, which is the app's run.
        let observing = unsafe { centre.addObserverForName_object_queue_usingBlock(Some(name), None, Some(&NSOperationQueue::mainQueue()), &block) };
        std::mem::forget(observing);
        later(AFTER_LAUNCH);
    }

    fn later(after: Duration) {
        if let Ok(when) = dispatch2::DispatchTime::try_from(after) {
            let _ = dispatch2::DispatchQueue::main().after(when, check);
        }
    }

    /// Gives EchoLedger its Dock icon or takes it away for what is on screen now (main thread).
    pub fn check() {
        let Some(mtm) = MainThreadMarker::new() else { return };
        let Some(full) = another_app_full_screen(mtm) else { return };
        let wanted = match dock_for(full) {
            Dock::Shown => NSApplicationActivationPolicy::Regular,
            Dock::Away => NSApplicationActivationPolicy::Accessory,
        };
        let app = NSApplication::sharedApplication(mtm);
        if app.activationPolicy() != wanted {
            app.setActivationPolicy(wanted);
        }
    }

    /// Whether another app's full-screen window fills a display now; none when the system did not answer.
    fn another_app_full_screen(mtm: MainThreadMarker) -> Option<bool> {
        use objc2_core_foundation::{CFDictionary, CFNumber, CFString, CFType};
        use objc2_core_graphics::{CGWindowListCopyWindowInfo, CGWindowListOption, kCGWindowBounds, kCGWindowLayer, kCGWindowOwnerPID};

        // The displays in the window list's terms: AppKit's frames are from the main display's bottom-left corner.
        let screens = NSScreen::screens(mtm);
        let main_height = screens.iter().next().map(|s| s.frame().size.height)?;
        let displays: Vec<Frame> = screens
            .iter()
            .map(|s| {
                let f = s.frame();
                ((f.origin.x, main_height - f.origin.y - f.size.height), (f.size.width, f.size.height))
            })
            .collect();
        let own = std::process::id() as i64;
        let list = CGWindowListCopyWindowInfo(CGWindowListOption::OptionOnScreenOnly | CGWindowListOption::ExcludeDesktopElements, 0)?;
        // SAFETY: the window-list keys are constants of CoreGraphics.
        let (bounds_key, layer_key, pid_key) = unsafe { (kCGWindowBounds, kCGWindowLayer, kCGWindowOwnerPID) };
        for i in 0..list.count() {
            // SAFETY: the index is in range; each value of this array is a CFDictionary the array keeps alive.
            let item = unsafe { list.value_at_index(i) } as *const CFType;
            let Some(dict) = (unsafe { item.as_ref() }).and_then(|t| t.downcast_ref::<CFDictionary>()) else { continue };
            let get = |dict: &CFDictionary, key: &CFString| -> Option<&CFType> {
                // SAFETY: the key is a CFString; the value, when present, is a CF object owned by the dictionary.
                let value = unsafe { dict.value((key as *const CFString).cast()) } as *const CFType;
                unsafe { value.as_ref() }
            };
            let number = |dict: &CFDictionary, key: &CFString| get(dict, key).and_then(|v| v.downcast_ref::<CFNumber>()).and_then(|n| n.as_f64());
            let Some(bounds) = get(dict, bounds_key).and_then(|v| v.downcast_ref::<CFDictionary>()) else { continue };
            let field = |name: &'static str| number(bounds, &CFString::from_static_str(name));
            let (Some(x), Some(y), Some(w), Some(h)) = (field("X"), field("Y"), field("Width"), field("Height")) else { continue };
            let ours = number(dict, pid_key).map(|p| p as i64) == Some(own);
            let layer = number(dict, layer_key).map(|l| l as i64).unwrap_or(-1);
            if fills_a_display(ours, layer, ((x, y), (w, h)), &displays) {
                return Some(true);
            }
        }
        Some(false)
    }

    /// The main window comes to the Space in use when it is opened, instead of taking the person to the Space it was on
    /// (main thread).
    pub fn follow_active_space(window: &NSWindow) {
        window.setCollectionBehavior(window.collectionBehavior() | NSWindowCollectionBehavior::MoveToActiveSpace);
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    const MAIN: Frame = ((0.0, 0.0), (1512.0, 982.0));
    const SIDE: Frame = ((1512.0, -200.0), (1920.0, 1080.0));

    #[test]
    fn the_dock_icon_goes_only_while_another_app_is_full_screen() {
        assert_eq!(dock_for(false), Dock::Shown, "a normal Mac app everywhere else");
        assert_eq!(dock_for(true), Dock::Away, "its menu then opens over the full-screen window");
    }

    #[test]
    fn a_full_screen_window_is_another_app_s_window_filling_a_display() {
        let displays = [MAIN, SIDE];
        assert!(fills_a_display(false, 0, MAIN, &displays), "VS Code full screen on the built-in display");
        assert!(fills_a_display(false, 0, SIDE, &displays), "on the second display");
        assert!(fills_a_display(false, 0, ((0.5, 0.0), (1511.5, 982.0)), &displays), "within a point");
        assert!(!fills_a_display(true, 0, MAIN, &displays), "EchoLedger's own window full screen: it keeps its Dock icon");
        assert!(!fills_a_display(false, 0, ((0.0, 33.0), (1512.0, 949.0)), &displays), "a zoomed window leaves the menu bar");
        assert!(!fills_a_display(false, 25, MAIN, &displays), "the menu bar, the Dock and other system layers");
        assert!(!fills_a_display(false, 0, ((100.0, 100.0), (800.0, 600.0)), &displays));
        assert!(!fills_a_display(false, 0, MAIN, &[]), "no display known: nothing fills one");
    }

    fn code(file: &str) -> String {
        let text = std::fs::read_to_string(std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("src").join(file)).unwrap();
        text.split("\n#[cfg(test)]\nmod tests").next().unwrap().to_string()
    }

    fn body(code: &str, start: &str) -> String {
        let from = &code[code.find(start).unwrap_or_else(|| panic!("{start}"))..];
        from[..from.find("\n}\n").unwrap()].to_string()
    }

    #[test]
    fn a_click_on_the_menu_bar_icon_never_activates_echoledger_or_opens_a_window() {
        // A Mac's left click opens the menu natively (tray-icon sets the menu on the status item and clicks it); the
        // click handler does nothing else there.
        let tray = code("tray.rs");
        let click = &tray[tray.find(".on_tray_icon_event(").unwrap()..];
        let click = &click[..click.find(".build(app)").unwrap()];
        for wakes in ["activate", "set_focus", "makeKeyAndOrderFront", "show()", "unminimize", "show_main_from_outside"] {
            assert!(!click.contains(wakes), "the click handler: {wakes}");
        }
        assert!(click.contains("if !left_click_opens_menu(cfg!(target_os = \"macos\"))"), "the window opens on a click only where the menu does not (Windows)");
        assert!(crate::tray::left_click_opens_menu(true));
        // Opening the menu wakes nothing: only its entries act, and only "Open EchoLedger" shows the main window.
        let menu = &tray[tray.find(".on_menu_event(").unwrap()..tray.find(".on_tray_icon_event(").unwrap()];
        assert_eq!(menu.matches("show_main(").count(), 1);
        assert!(menu.contains("\"open\" => {\n                        show_main(&app);"));
    }

    #[test]
    fn the_dock_icon_follows_the_space_in_use_and_the_main_window_comes_to_it() {
        let spaces = code("spaces.rs");
        // Checked on every Space change, again once it settled, and once after launch; the switch only when it changes.
        let install = body(&spaces, "    pub fn install() {");
        assert!(install.contains("NSWorkspaceActiveSpaceDidChangeNotification") && install.contains("later(SETTLED)") && install.contains("later(AFTER_LAUNCH)"));
        let check = body(&spaces, "    pub fn check() {");
        assert!(check.contains("if app.activationPolicy() != wanted {"));
        // The switch never activates EchoLedger or shows a window.
        let mac = &spaces[spaces.find("pub mod mac {").unwrap()..];
        for wakes in ["activate", "makeKeyAndOrderFront", "orderFront", "set_focus"] {
            assert!(!mac.contains(wakes), "{wakes}");
        }
        let lib = code("lib.rs");
        assert!(lib.contains("spaces::mac::install();"), "installed for the run");
        let lifecycle = code("lifecycle.rs");
        assert!(body(&lifecycle, "fn build_main(").contains("crate::spaces::mac::follow_active_space("), "the main window opens on the Space in use");
    }
}
