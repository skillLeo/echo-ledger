//! EchoLedger and the Mac's Spaces: its menu bar menu over another app's full-screen window, and the main window opened
//! on the Space in use from that menu.
//!
//! macOS opens a Dock app's menu bar menu on the desktop, below another app's full-screen window: a click on
//! EchoLedger's icon there only highlighted it. An app without a Dock icon (`NSApplicationActivationPolicyAccessory`)
//! has its menu open over it, as a menu bar app's does; switching when the menu opens is too late. A click on the icon
//! never activates EchoLedger or shows a window (tray.rs); only "Open EchoLedger" does.
//!
//! Decision (the lead's; the one exception to "EchoLedger is a normal Mac app", app_life.rs): EchoLedger goes without
//! its Dock icon only while the display in use (the one under the pointer) shows another app in a real full-screen
//! Space, where the Dock is hidden anyway, and gets it back as soon as it does not. A full-screen app on another display,
//! a window merely filling a display (zoomed under an auto-hiding menu bar) and EchoLedger's own full-screen window
//! never count. Whether a Space is full screen is read from the system's list of Spaces (each display's current Space and
//! its type, as the window server keeps it); macOS has no public call for it, so the two functions are looked up when
//! the app runs and, without them, EchoLedger simply keeps its Dock icon. It is read when the Space in use or the
//! displays change, and, while there are several displays, when the pointer moves to another one; it is switched only
//! when two reads [`SETTLED`] apart agree (never mid-slide). The normal-Mac-app test allows no other activation policy
//! change anywhere.
//!
//! "Open EchoLedger" from the menu brings an open main window to the Space in use (`MoveToActiveSpace` while it opens,
//! then the window's own behaviour again), so a Dock click or ⌘Tab later still goes to the window where it is.

use std::time::Duration;

/// How long two reads must agree before the Dock icon changes: the switch's slide has settled by then.
pub const SETTLED: Duration = Duration::from_millis(700);

/// The type the window server gives a full-screen Space (a normal desktop is 0).
pub const FULL_SCREEN_SPACE: i64 = 4;

/// Whether EchoLedger keeps its Dock icon.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Dock {
    /// A normal Mac app: Dock icon, ⌘Tab, its menu bar while in front.
    Shown,
    /// While the display in use shows another app full screen: its menu bar menu opens over it.
    Away,
}

/// What the display in use shows, as read.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct InUse {
    /// The type of its current Space; none when the system did not say.
    pub space_type: Option<i64>,
    /// One of EchoLedger's own windows is full screen on it.
    pub ours_full_screen: bool,
}

/// The decision (pure): without the Dock icon only for another app's real full-screen Space on the display in use.
pub fn dock_for(in_use: InUse) -> Dock {
    if in_use.space_type == Some(FULL_SCREEN_SPACE) && !in_use.ours_full_screen { Dock::Away } else { Dock::Shown }
}

/// The current Space's type of display `uuid` in the system's list of (display, current Space type) (pure). With one
/// set of Spaces for all displays ("Displays have separate Spaces" off) the list's one entry is every display's.
pub fn space_type_of(uuid: &str, displays: &[(String, i64)]) -> Option<i64> {
    let one = match displays {
        [(_, only)] => Some(*only),
        _ => None,
    };
    displays.iter().find(|(id, _)| id.eq_ignore_ascii_case(uuid)).map(|(_, t)| *t).or(one)
}

/// Whether the pointer's display is watched (pure): only while there are several displays to move between.
pub fn watch_pointer_with(displays: usize) -> bool {
    displays > 1
}

/// Whether the pointer moved to another display (pure): a new read is due then (the first known display counts).
pub fn moved_to_another_display(before: Option<(i64, i64)>, now: Option<(i64, i64)>) -> bool {
    now.is_some() && before != now
}

/// One read against the read before it (pure): the Dock to apply now (only when both agree), and whether to read again.
pub fn settle(before: Option<Dock>, now: Dock) -> (Option<Dock>, bool) {
    if before == Some(now) { (Some(now), false) } else { (None, true) }
}

#[cfg(target_os = "macos")]
pub mod mac {
    use super::{Dock, InUse, SETTLED, dock_for, moved_to_another_display, settle, space_type_of, watch_pointer_with};
    use objc2::{MainThreadMarker, Message};
    use objc2::rc::Retained;
    use objc2::runtime::AnyObject;
    use objc2_app_kit::{
        NSApplication, NSApplicationActivationPolicy, NSApplicationDidChangeScreenParametersNotification, NSEvent, NSEventMask, NSScreen, NSWindow,
        NSWindowCollectionBehavior, NSWindowStyleMask, NSWorkspace, NSWorkspaceActiveSpaceDidChangeNotification,
    };
    use objc2_core_foundation::{CFArray, CFDictionary, CFNumber, CFRetained, CFString, CFType, CFUUID};
    use objc2_foundation::{NSNotification, NSNotificationCenter, NSNotificationName, NSNumber, NSOperationQueue, NSString};
    use std::ffi::{c_char, c_void};
    use std::ptr::NonNull;
    use std::sync::{Mutex, OnceLock};

    /// The first check after launch, once the launch's own activation policy is applied.
    const AFTER_LAUNCH: std::time::Duration = std::time::Duration::from_secs(1);
    /// How long the main window comes to the Space in use after "Open EchoLedger" before its own behaviour is back.
    const BRING_HERE_FOR: std::time::Duration = std::time::Duration::from_secs(2);

    /// The last read, waiting for the next one to agree.
    static LAST_READ: Mutex<Option<Dock>> = Mutex::new(None);

    /// Reads on every change of the Space in use or of the displays, for the app's whole run; the first after launch.
    pub fn install() {
        // SAFETY: the names are AppKit's constants.
        let (space, screens) = unsafe { (NSWorkspaceActiveSpaceDidChangeNotification, NSApplicationDidChangeScreenParametersNotification) };
        observe(&NSWorkspace::sharedWorkspace().notificationCenter(), space);
        observe(&NSNotificationCenter::defaultCenter(), screens);
        later(AFTER_LAUNCH);
        if let Some(mtm) = MainThreadMarker::new() {
            watch_pointer(mtm);
        }
    }

    thread_local! {
        /// The mouse-moved monitors (another app's events, then EchoLedger's own), while there are several displays.
        static POINTER_MONITORS: std::cell::RefCell<Option<[Retained<AnyObject>; 2]>> = const { std::cell::RefCell::new(None) };
        /// The display the pointer was last on (its frame's corner).
        static POINTER_DISPLAY: std::cell::Cell<Option<(i64, i64)>> = const { std::cell::Cell::new(None) };
    }

    /// Watches which display the pointer is on while there are several (main thread): moving to another one reads
    /// again, as a Space change does. A mouse-moved monitor needs no permission (only key events would); the handler
    /// only compares the display, and reads only when it changed.
    fn watch_pointer(mtm: MainThreadMarker) {
        let several = watch_pointer_with(NSScreen::screens(mtm).count());
        POINTER_MONITORS.with(|monitors| {
            let mut monitors = monitors.borrow_mut();
            match (several, monitors.is_some()) {
                (true, false) => {
                    let global = block2::RcBlock::new(|_: NonNull<NSEvent>| pointer_moved());
                    let local = block2::RcBlock::new(|event: NonNull<NSEvent>| {
                        pointer_moved();
                        event.as_ptr()
                    });
                    let global = NSEvent::addGlobalMonitorForEventsMatchingMask_handler(NSEventMask::MouseMoved, &global);
                    // SAFETY: the block hands every event back unchanged.
                    let local = unsafe { NSEvent::addLocalMonitorForEventsMatchingMask_handler(NSEventMask::MouseMoved, &local) };
                    if let (Some(global), Some(local)) = (global, local) {
                        *monitors = Some([global, local]);
                    }
                }
                (false, true) => {
                    for monitor in monitors.take().into_iter().flatten() {
                        // SAFETY: a monitor NSEvent returned above.
                        unsafe { NSEvent::removeMonitor(&monitor) };
                    }
                    POINTER_DISPLAY.set(None);
                }
                _ => {}
            }
        });
    }

    fn pointer_moved() {
        let Some(mtm) = MainThreadMarker::new() else { return };
        let pointer = NSEvent::mouseLocation();
        let now = NSScreen::screens(mtm).iter().map(|s| s.frame()).find(|f| {
            pointer.x >= f.origin.x && pointer.x < f.origin.x + f.size.width && pointer.y >= f.origin.y && pointer.y < f.origin.y + f.size.height
        }).map(|f| (f.origin.x as i64, f.origin.y as i64));
        if moved_to_another_display(POINTER_DISPLAY.replace(now), now) {
            *LAST_READ.lock().unwrap_or_else(|p| p.into_inner()) = None;
            check();
        }
    }

    fn observe(centre: &NSNotificationCenter, name: &NSNotificationName) {
        let block = block2::RcBlock::new(|_: NonNull<NSNotification>| {
            *LAST_READ.lock().unwrap_or_else(|p| p.into_inner()) = None;
            check();
            // A display came or went: the pointer is watched only while there are several.
            if let Some(mtm) = MainThreadMarker::new() {
                watch_pointer(mtm);
            }
        });
        // SAFETY: no object filter; the main queue (the check needs it); the block lives as long as the observation, which
        // is the app's run.
        let observing = unsafe { centre.addObserverForName_object_queue_usingBlock(Some(name), None, Some(&NSOperationQueue::mainQueue()), &block) };
        std::mem::forget(observing);
    }

    fn later(after: std::time::Duration) {
        if let Ok(when) = dispatch2::DispatchTime::try_from(after) {
            let _ = dispatch2::DispatchQueue::main().after(when, check);
        }
    }

    /// One read (main thread): applied once the read before agrees, else read again [`SETTLED`] later.
    pub fn check() {
        let Some(mtm) = MainThreadMarker::new() else { return };
        let now = dock_for(in_use(mtm));
        let (apply, again) = {
            let mut last = LAST_READ.lock().unwrap_or_else(|p| p.into_inner());
            let answer = settle(*last, now);
            *last = Some(now);
            answer
        };
        if again {
            later(SETTLED);
        }
        let Some(dock) = apply else { return };
        let wanted = match dock {
            Dock::Shown => NSApplicationActivationPolicy::Regular,
            Dock::Away => NSApplicationActivationPolicy::Accessory,
        };
        let app = NSApplication::sharedApplication(mtm);
        if app.activationPolicy() != wanted {
            app.setActivationPolicy(wanted);
        }
    }

    /// What the display under the pointer shows now.
    fn in_use(mtm: MainThreadMarker) -> InUse {
        let pointer = NSEvent::mouseLocation();
        let screens = NSScreen::screens(mtm);
        let screen = screens.iter().find(|s| {
            let f = s.frame();
            pointer.x >= f.origin.x && pointer.x < f.origin.x + f.size.width && pointer.y >= f.origin.y && pointer.y < f.origin.y + f.size.height
        });
        let Some(screen) = screen.or_else(|| NSScreen::mainScreen(mtm)) else { return InUse { space_type: None, ours_full_screen: false } };
        let frame = screen.frame();
        let ours_full_screen = NSApplication::sharedApplication(mtm).windows().iter().any(|w| {
            let f = w.frame();
            let centre = (f.origin.x + f.size.width / 2.0, f.origin.y + f.size.height / 2.0);
            w.isVisible()
                && w.styleMask().contains(NSWindowStyleMask::FullScreen)
                && centre.0 >= frame.origin.x && centre.0 < frame.origin.x + frame.size.width
                && centre.1 >= frame.origin.y && centre.1 < frame.origin.y + frame.size.height
        });
        let space_type = display_uuid(&screen).and_then(|uuid| current_spaces().and_then(|list| space_type_of(&uuid, &list)));
        InUse { space_type, ours_full_screen }
    }

    // ---- The window server's list of Spaces (no public call; looked up once, at run time) -------------------------------

    unsafe extern "C" {
        fn dlsym(handle: *mut c_void, symbol: *const c_char) -> *mut c_void;
    }
    /// `RTLD_DEFAULT` on macOS: every image loaded in the process.
    const RTLD_DEFAULT: *mut c_void = -2isize as *mut c_void;

    type MainConnection = unsafe extern "C" fn() -> i32;
    type CopySpaces = unsafe extern "C" fn(i32) -> *const CFType;
    type DisplayUuid = unsafe extern "C" fn(u32) -> *const CFType;

    /// The three functions this needs, found once; none when one is missing (EchoLedger then keeps its Dock icon).
    struct WindowServer {
        main_connection: MainConnection,
        copy_spaces: CopySpaces,
        display_uuid: DisplayUuid,
    }

    static WINDOW_SERVER: OnceLock<Option<WindowServer>> = OnceLock::new();

    fn window_server() -> Option<&'static WindowServer> {
        WINDOW_SERVER
            .get_or_init(|| {
                let find = |name: &std::ffi::CStr| {
                    // SAFETY: a plain lookup by name.
                    NonNull::new(unsafe { dlsym(RTLD_DEFAULT, name.as_ptr()) })
                };
                let (main, copy, uuid) = (find(c"CGSMainConnectionID")?, find(c"CGSCopyManagedDisplaySpaces")?, find(c"CGDisplayCreateUUIDFromDisplayID")?);
                // SAFETY: the functions by the signatures every user of them relies on; what they return is only read
                // after a type check (`current_spaces`, `display_uuid`).
                Some(unsafe {
                    WindowServer {
                        main_connection: std::mem::transmute::<*mut c_void, MainConnection>(main.as_ptr()),
                        copy_spaces: std::mem::transmute::<*mut c_void, CopySpaces>(copy.as_ptr()),
                        display_uuid: std::mem::transmute::<*mut c_void, DisplayUuid>(uuid.as_ptr()),
                    }
                })
            })
            .as_ref()
    }

    /// An owned (+1) CF object a function returned, or none for a null one.
    fn owned(object: *const CFType) -> Option<CFRetained<CFType>> {
        // SAFETY: the functions above return a new (+1) object or null; CFRetained releases it.
        NonNull::new(object.cast_mut()).map(|o| unsafe { CFRetained::from_raw(o) })
    }

    /// The display's UUID (as the list of Spaces names it).
    fn display_uuid(screen: &NSScreen) -> Option<String> {
        let key = NSString::from_str("NSScreenNumber");
        let number: Option<Retained<AnyObject>> = screen.deviceDescription().objectForKey(&key);
        let id = number?.downcast::<NSNumber>().ok()?.unsignedIntValue();
        // SAFETY: CoreGraphics' function by its documented signature.
        let uuid = owned(unsafe { (window_server()?.display_uuid)(id) })?;
        let uuid = uuid.downcast_ref::<CFUUID>()?;
        CFUUID::new_string(None, Some(uuid)).map(|s| s.to_string())
    }

    /// Each display's UUID and its current Space's type; none when the system does not have the list.
    fn current_spaces() -> Option<Vec<(String, i64)>> {
        let functions = window_server()?;
        // SAFETY: the window server's functions by their signatures; the answer is type-checked before it is read.
        let answer = owned(unsafe { (functions.copy_spaces)((functions.main_connection)()) })?;
        let list = answer.downcast_ref::<CFArray>()?;
        let get = |dict: &CFDictionary, key: &str| -> Option<CFRetained<CFType>> {
            let key = CFString::from_str(key);
            // SAFETY: the key is a CFString; the value, when present, is a CF object the dictionary keeps alive.
            let value = unsafe { dict.value((&*key as *const CFString).cast()) } as *const CFType;
            // SAFETY: retained here, so it outlives the dictionary's borrow.
            NonNull::new(value.cast_mut()).map(|v| unsafe { CFRetained::retain(v) })
        };
        let mut out = Vec::new();
        for i in 0..list.count() {
            // SAFETY: the index is in range; each value is a CF object the array keeps alive.
            let item = unsafe { list.value_at_index(i) } as *const CFType;
            let Some(display) = (unsafe { item.as_ref() }).and_then(|t| t.downcast_ref::<CFDictionary>()) else { continue };
            let Some(id) = get(display, "Display Identifier").and_then(|v| v.downcast_ref::<CFString>().map(|s| s.to_string())) else { continue };
            let Some(current) = get(display, "Current Space") else { continue };
            let Some(current) = current.downcast_ref::<CFDictionary>() else { continue };
            let Some(kind) = get(current, "type").and_then(|v| v.downcast_ref::<CFNumber>().and_then(|n| n.as_i64())) else { continue };
            out.push((id, kind));
        }
        Some(out)
    }

    // ---- "Open EchoLedger" from the menu -------------------------------------------------------------------------------

    /// Brings an open main window to the Space in use while it opens from the menu ("Open EchoLedger"), then gives it its
    /// own behaviour back, so a Dock click or ⌘Tab later still goes to it where it is (main thread).
    pub fn bring_here_while_opening(window: &NSWindow) {
        let before = window.collectionBehavior();
        if before.contains(NSWindowCollectionBehavior::MoveToActiveSpace) {
            return;
        }
        window.setCollectionBehavior(before | NSWindowCollectionBehavior::MoveToActiveSpace);
        let Ok(when) = dispatch2::DispatchTime::try_from(BRING_HERE_FOR) else { return };
        let window = window.retain();
        let restore = move || {
            let now = window.collectionBehavior();
            window.setCollectionBehavior(now & !NSWindowCollectionBehavior::MoveToActiveSpace | (before & NSWindowCollectionBehavior::MoveToActiveSpace));
        };
        let restore = SendOnMain(restore);
        let _ = dispatch2::DispatchQueue::main().after(when, move || restore.run());
    }

    /// A closure run only on the main queue (the window it holds is used there only).
    struct SendOnMain<F>(F);

    impl<F: FnOnce()> SendOnMain<F> {
        /// Taken whole (a closure naming only `.0` would capture the bare field).
        fn run(self) {
            (self.0)()
        }
    }
    // SAFETY: built on the main thread and run by the main dispatch queue, which is the main thread.
    unsafe impl<F> Send for SendOnMain<F> {}

    #[cfg(test)]
    mod live {
        use super::*;

        #[test]
        fn the_window_server_lists_each_display_s_current_space_and_is_looked_up_once() {
            let first = window_server().map(|w| w as *const WindowServer as usize);
            assert!(first.is_some(), "the three functions are there on this macOS");
            assert_eq!(first, window_server().map(|w| w as *const WindowServer as usize), "found once, kept");
            let list = current_spaces().expect("the list of Spaces");
            assert!(!list.is_empty(), "one entry per display (or one for all)");
            for (id, kind) in &list {
                assert!(!id.is_empty() && *kind >= 0, "{id}: {kind}");
            }
            println!("spaces: {list:?}");
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    const A: &str = "37D8832A-2D66-02CA-B9F7-8F30A301B230";
    const B: &str = "11111111-2222-3333-4444-555555555555";

    #[test]
    fn the_dock_icon_goes_only_for_another_app_full_screen_on_the_display_in_use() {
        let full = |ours| InUse { space_type: Some(FULL_SCREEN_SPACE), ours_full_screen: ours };
        assert_eq!(dock_for(full(false)), Dock::Away, "VS Code full screen where the person works: the menu opens over it");
        assert_eq!(dock_for(full(true)), Dock::Shown, "EchoLedger's own window full screen");
        assert_eq!(dock_for(InUse { space_type: Some(0), ours_full_screen: false }), Dock::Shown, "a desktop, a window zoomed under an auto-hiding menu bar included");
        assert_eq!(dock_for(InUse { space_type: None, ours_full_screen: false }), Dock::Shown, "the system did not say: a normal Mac app");
    }

    #[test]
    fn a_full_screen_app_on_another_display_never_counts() {
        // Zoom full screen on the second display; the person works on the first.
        let displays = [(A.to_string(), 0), (B.to_string(), FULL_SCREEN_SPACE)];
        let in_use = InUse { space_type: space_type_of(A, &displays), ours_full_screen: false };
        assert_eq!(dock_for(in_use), Dock::Shown);
        assert_eq!(dock_for(InUse { space_type: space_type_of(B, &displays), ours_full_screen: false }), Dock::Away, "the pointer moves to it");
        assert_eq!(space_type_of(&A.to_lowercase(), &displays), Some(0));
        assert_eq!(space_type_of("unknown", &displays), None, "a display the list does not know: nothing decided");
        // "Displays have separate Spaces" off: one list for all displays.
        assert_eq!(space_type_of(A, &[("Main".to_string(), FULL_SCREEN_SPACE)]), Some(FULL_SCREEN_SPACE));
    }

    #[test]
    fn the_pointer_moving_to_another_display_reads_again_only_while_there_are_several() {
        assert!(!watch_pointer_with(1), "one display: nothing is watched");
        assert!(watch_pointer_with(2));
        assert!(moved_to_another_display(None, Some((0, 0))), "the first known display");
        assert!(moved_to_another_display(Some((0, 0)), Some((1512, 0))), "to the second display");
        assert!(!moved_to_another_display(Some((1512, 0)), Some((1512, 0))), "moving on the same display reads nothing");
        assert!(!moved_to_another_display(Some((0, 0)), None), "between displays (no display under it)");
        let spaces = code("spaces.rs");
        let watch = body(&spaces, "    fn watch_pointer(");
        assert!(watch.contains("addGlobalMonitorForEventsMatchingMask_handler(NSEventMask::MouseMoved") && watch.contains("NSEvent::removeMonitor(&monitor)"), "on with several displays, off with one");
        assert!(body(&spaces, "    pub fn install() {").contains("watch_pointer(mtm)"));
        assert!(body(&spaces, "    fn observe(").contains("watch_pointer(mtm)"), "a display came or went");
        assert!(body(&spaces, "    fn pointer_moved() {").contains("if moved_to_another_display("), "reads only when the display changed");
    }

    #[test]
    fn the_dock_icon_changes_only_when_two_reads_agree() {
        assert_eq!(settle(None, Dock::Away), (None, true), "the first read only asks for another");
        assert_eq!(settle(Some(Dock::Shown), Dock::Away), (None, true), "mid-slide: read again");
        assert_eq!(settle(Some(Dock::Away), Dock::Away), (Some(Dock::Away), false), "settled: applied");
    }

    fn code(file: &str) -> String {
        let text = std::fs::read_to_string(std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("src").join(file)).unwrap();
        text.split("\n#[cfg(test)]\nmod tests").next().unwrap().to_string()
    }

    fn body(code: &str, start: &str) -> String {
        let from = &code[code.find(start).unwrap_or_else(|| panic!("{start}"))..];
        from[..from.find("\n    }\n").map(|i| i + 6).or_else(|| from.find("\n}\n")).unwrap()].to_string()
    }

    #[test]
    fn the_private_answer_is_type_checked_and_the_functions_are_found_once() {
        let spaces = code("spaces.rs");
        assert_eq!(spaces.matches("dlsym(RTLD_DEFAULT").count(), 1, "one lookup place");
        assert!(spaces.contains("static WINDOW_SERVER: OnceLock<Option<WindowServer>>"));
        let read = body(&spaces, "    fn current_spaces() -> Option<Vec<(String, i64)>> {");
        assert!(read.find("answer.downcast_ref::<CFArray>()?").unwrap() < read.find("list.count()").unwrap(), "a CFArray before it is read");
        assert!(body(&spaces, "    fn display_uuid(").contains("uuid.downcast_ref::<CFUUID>()?"));
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
        assert_eq!(menu.matches("show_main(").count() + menu.matches("open_here(").count(), 1);
        assert!(menu.contains("\"open\" => {\n                        open_here(&app);"));
    }

    #[test]
    fn the_dock_icon_follows_the_display_in_use_and_the_main_window_comes_only_from_the_menu() {
        let spaces = code("spaces.rs");
        let install = body(&spaces, "    pub fn install() {");
        for watched in ["NSWorkspaceActiveSpaceDidChangeNotification", "NSApplicationDidChangeScreenParametersNotification", "later(AFTER_LAUNCH)"] {
            assert!(install.contains(watched), "{watched}");
        }
        let check = body(&spaces, "    pub fn check() {");
        assert!(check.contains("settle(*last, now)") && check.contains("later(SETTLED)") && check.contains("if app.activationPolicy() != wanted {"));
        // The switch never activates EchoLedger or shows a window.
        let mac = &spaces[spaces.find("pub mod mac {").unwrap()..];
        for wakes in ["activate", "makeKeyAndOrderFront", "orderFront", "set_focus"] {
            assert!(!mac.contains(wakes), "{wakes}");
        }
        assert!(code("lib.rs").contains("spaces::mac::install();"), "installed for the run");
        // MoveToActiveSpace only while "Open EchoLedger" opens it from the menu, never on the window for good.
        assert!(!code("lifecycle.rs").contains("MoveToActiveSpace") && !code("lifecycle.rs").contains("spaces::"));
        let tray = code("tray.rs");
        assert!(body(&tray, "fn open_here(").contains("crate::spaces::mac::bring_here_while_opening("));
        let bring = body(&spaces, "    pub fn bring_here_while_opening(");
        assert!(bring.contains("BRING_HERE_FOR") && bring.contains("before & NSWindowCollectionBehavior::MoveToActiveSpace"), "its own behaviour back");
    }
}
