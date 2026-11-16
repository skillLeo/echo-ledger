//! The AppKit side of the overlays: what keeps them over full-screen meetings and in every Space, and the displays' usable
//! areas in points. AppKit answers only on the main thread, so each call goes there and waits for it (from the main
//! thread itself the task runs at once).
//!
//! Every floating window is a real non-activating panel (since v1.8.1). EchoLedger is a normal app with a Dock icon, and
//! since macOS 10.14 a plain window of such an app may not float over another app's full-screen window, while a
//! non-activating `NSPanel` may, and a click on it never makes EchoLedger the active app. tao builds every window as its
//! own `TaoWindow` (an `NSWindow` subclass), so a floating window's class is changed to [`PANEL_CLASS`], a subclass of
//! `NSPanel` made once at runtime, right after it is built ([`make_panel`]), as tauri-nspanel does. `NSPanel` adds no
//! instance variables to `NSWindow` and neither does the panel class, so the object's `NSWindow` part is all either
//! reads; `TaoWindow`'s own Rust state sits after it, untouched (checked at runtime: a layout that ever differs leaves
//! the window as it is). tao's window delegate, the capture flag (`sharingType`) and every other window state stay.

use std::sync::OnceLock;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::mpsc;
use std::time::Duration;

use objc2::runtime::{AnyClass, AnyObject, Bool, ClassBuilder, NSObjectProtocol, Sel};
use objc2::{MainThreadMarker, msg_send, sel};
use objc2_app_kit::{NSScreen, NSWindow, NSWindowCollectionBehavior, NSWindowStyleMask};
use tauri::{AppHandle, Runtime, WebviewWindow};

use super::geometry::{MAC_FLOATING_LEVEL, MAC_NONACTIVATING, MAC_OVERLAY_BEHAVIOUR, MAC_OVERLAY_LEVEL, MacRect, MacScreen, mac_follows_spaces, mac_on_screen, mac_overlay_style};
use crate::mac_check::WindowFacts;

/// The floating windows' class: a subclass of `NSPanel`, made once.
pub const PANEL_CLASS: &str = "EchoLedgerFloatingPanel";
/// The class tao builds every window with.
pub const TAO_WINDOW_CLASS: &str = "TaoWindow";

fn mac_rect(r: objc2_core_foundation::CGRect) -> MacRect {
    MacRect { x: r.origin.x, y: r.origin.y, w: r.size.width, h: r.size.height }
}

fn screens_now(mtm: MainThreadMarker) -> Vec<MacScreen> {
    NSScreen::screens(mtm).iter().map(|s| MacScreen { frame: mac_rect(s.frame()), visible: mac_rect(s.visibleFrame()), top_inset: s.safeAreaInsets().top }).collect()
}

/// The displays as AppKit reports them, the primary first (empty if the main thread did not answer in time).
pub fn screens<R: Runtime>(app: &AppHandle<R>) -> Vec<MacScreen> {
    let (tx, rx) = mpsc::channel();
    let _ = app.run_on_main_thread(move || {
        if let Some(mtm) = MainThreadMarker::new() {
            let _ = tx.send(screens_now(mtm));
        }
    });
    rx.recv_timeout(Duration::from_secs(2)).unwrap_or_default()
}

/// Whether `NSPanel` lays an object out as `NSWindow` does (pure over the runtime's answers): the same size and no
/// instance variables of its own, so an `NSWindow` (or `TaoWindow`) object can become a panel.
pub fn panel_layout_matches() -> bool {
    let (Some(panel), Some(window)) = (AnyClass::get(c"NSPanel"), AnyClass::get(c"NSWindow")) else { return false };
    panel.instance_size() == window.instance_size() && panel.instance_variables().is_empty()
}

/// Overlays never take the keyboard (`TaoWindow` answers what tao was told: never, for them).
extern "C-unwind" fn never(_panel: *mut AnyObject, _cmd: Sel) -> Bool {
    Bool::NO
}

/// `TaoWindow`'s own `sendEvent:`: a window moved by its background starts the drag on a left mouse down (a web view
/// would take it otherwise), then the event goes on as usual.
extern "C-unwind" fn send_event(panel: *mut AnyObject, _cmd: Sel, event: *mut AnyObject) {
    const LEFT_MOUSE_DOWN: usize = 1;
    // SAFETY: AppKit sends `sendEvent:` to one of our panels with an NSEvent, on the main thread.
    let (Some(superclass), Some(panel), Some(event)) = (AnyClass::get(c"NSPanel"), unsafe { panel.as_ref() }, unsafe { event.as_ref() }) else { return };
    // SAFETY: as above.
    unsafe {
        let kind: usize = msg_send![event, type];
        let movable: bool = msg_send![panel, isMovableByWindowBackground];
        if kind == LEFT_MOUSE_DOWN && movable {
            let _: () = msg_send![panel, performWindowDragWithEvent: event];
        }
        let _: () = msg_send![super(panel, superclass), sendEvent: event];
    }
}

/// The panel class, made the first time it is asked for; none when this macOS lays `NSPanel` out unlike `NSWindow`.
fn panel_class() -> Option<&'static AnyClass> {
    static CLASS: OnceLock<Option<&'static AnyClass>> = OnceLock::new();
    *CLASS.get_or_init(|| {
        if !panel_layout_matches() {
            return None;
        }
        let name = std::ffi::CString::new(PANEL_CLASS).ok()?;
        if let Some(made) = AnyClass::get(&name) {
            return Some(made);
        }
        let mut builder = ClassBuilder::new(&name, AnyClass::get(c"NSPanel")?)?;
        // SAFETY: each function has the signature of the method it answers (BOOL from self and _cmd; void from self,
        // _cmd and an NSEvent).
        unsafe {
            builder.add_method(sel!(canBecomeKeyWindow), never as extern "C-unwind" fn(*mut AnyObject, Sel) -> Bool);
            builder.add_method(sel!(canBecomeMainWindow), never as extern "C-unwind" fn(*mut AnyObject, Sel) -> Bool);
            builder.add_method(sel!(sendEvent:), send_event as extern "C-unwind" fn(*mut AnyObject, Sel, *mut AnyObject));
        }
        Some(builder.register())
    })
}

/// The methods `TaoWindow` (tao 0.37) has of its own, and the panel class answers alike.
pub const TAO_WINDOW_METHODS: [&str; 3] = ["canBecomeKeyWindow", "canBecomeMainWindow", "sendEvent:"];

/// Whether a window of `class` may become a panel (pure over the runtime): its own methods are exactly
/// [`TAO_WINDOW_METHODS`] (so no `dealloc` and nothing else the panel class would skip), and its own state needs no
/// cleaning up. objc2 keeps such a class's Rust state in one instance variable, `ivars` (tao's: whether the window may
/// take the keyboard), and adds a `drop_flag` and a `dealloc` only for state that needs dropping. A tao update that
/// changes any of this leaves the window as it is, and the Mac check says so.
pub fn tao_window_shape(class: &AnyClass) -> Result<(), String> {
    let mut methods: Vec<String> = class.instance_methods().iter().map(|m| m.name().name().to_string_lossy().into_owned()).collect();
    methods.sort();
    if methods != TAO_WINDOW_METHODS {
        return Err(format!("its class has the methods {}", methods.join(", ")));
    }
    let state: Vec<String> = class.instance_variables().iter().map(|v| v.name().to_string_lossy().into_owned()).collect();
    if state.iter().any(|name| name != "ivars") {
        return Err(format!("its class keeps state that needs cleaning up ({})", state.join(", ")));
    }
    Ok(())
}

/// The window server is told a click on a panel must not activate EchoLedger through this setter (`make_panel`).
const PREVENTS_ACTIVATION: &str = "_setPreventsActivation:";

/// Makes an overlay a non-activating panel (main thread): its class becomes [`PANEL_CLASS`], its style gets
/// `NonactivatingPanel`, and it stays on screen when EchoLedger is not the active app. A window of any other class than
/// tao's, or of a tao class shaped otherwise than [`tao_window_shape`] expects, is left as it is and the reason returned.
pub fn make_panel(window: &NSWindow) -> Result<(), String> {
    let class = panel_class().ok_or("NSPanel is laid out unlike NSWindow on this macOS")?;
    let object: &AnyObject = window.as_ref();
    let current = object.class();
    if !std::ptr::eq(current, class) {
        let name = current.name().to_string_lossy().into_owned();
        if name != TAO_WINDOW_CLASS || current.instance_size() < class.instance_size() {
            return Err(format!("left as {name}"));
        }
        tao_window_shape(current).map_err(|why| format!("left as {name}: {why}"))?;
        // SAFETY: on the main thread, a TaoWindow (an NSWindow whose own state comes after NSWindow's and needs no
        // cleaning up) becomes a panel class whose objects are exactly NSWindow's layout (`panel_layout_matches`), so
        // every byte it reads is there.
        unsafe { objc2::ffi::object_setClass((object as *const AnyObject).cast_mut(), class) };
    }
    window.setStyleMask(NSWindowStyleMask(mac_overlay_style(window.styleMask().0)));
    // The class changed after AppKit made the window, so NSPanel's own start never told the window server that a click
    // must not activate EchoLedger: the setter it uses, when this macOS has it (tauri-nspanel does the same).
    if window.respondsToSelector(sel!(_setPreventsActivation:)) {
        // SAFETY: the selector exists on this window (asked just above) and takes a BOOL.
        unsafe {
            let _: () = msg_send![window, _setPreventsActivation: Bool::YES];
        }
    } else {
        static SAID: AtomicBool = AtomicBool::new(false);
        if !SAID.swap(true, Ordering::SeqCst) {
            eprintln!("[echoledger] this macOS has no {PREVENTS_ACTIVATION}: a click on the recording controls or the captions may bring EchoLedger to the front");
        }
    }
    window.setHidesOnDeactivate(false);
    Ok(())
}

/// Whether `make_panel` told the window server about this window (pure over the runtime): it is a panel of
/// [`PANEL_CLASS`] and the setter is there (`make_panel` always sends it then).
fn activation_setter_applied(window: &NSWindow) -> bool {
    let object: &AnyObject = window.as_ref();
    panel_class().is_some_and(|class| std::ptr::eq(object.class(), class)) && window.respondsToSelector(sel!(_setPreventsActivation:))
}

/// Above full-screen windows and in every Space. `always_on_top` and `visible_on_all_workspaces` alone give the floating
/// level and no full-screen behaviour, which a full-screen Zoom window covers. Hide Others leaves overlays alone too. A
/// non-activating panel ([`make_panel`]): a click never brings EchoLedger in front of the meeting, and a normal app's
/// window may float over another app's full-screen window. Out of the Window menu and the Dock icon's list of windows:
/// the main window is the one window people pick there (⌘Tab lists apps, and EchoLedger once). On screen already, it
/// is ordered in again as a panel, so the window server places it as one; one the privacy service took off stays off.
pub fn keep_above_everything<R: Runtime>(window: &WebviewWindow<R>) {
    let target = window.clone();
    let _ = window.run_on_main_thread(move || {
        let Ok(pointer) = target.ns_window() else { return };
        // SAFETY: Tauri hands out the window's own NSWindow, alive while `target` is, on the main thread here.
        let ns_window: &NSWindow = unsafe { &*(pointer as *const NSWindow) };
        if let Err(why) = make_panel(ns_window) {
            eprintln!("[echoledger] the {} is not a floating panel: {why}", crate::privacy::window_name(target.label()));
        }
        ns_window.setLevel(MAC_OVERLAY_LEVEL);
        ns_window.setCollectionBehavior(NSWindowCollectionBehavior(MAC_OVERLAY_BEHAVIOUR));
        ns_window.setCanHide(false);
        ns_window.setExcludedFromWindowsMenu(true);
        if ns_window.isVisible() {
            ns_window.orderOut(None);
            ns_window.orderFrontRegardless();
        }
    });
}

/// How an overlay stands, for the Mac check, the smoke test and "Copy diagnostics".
pub fn window_facts<R: Runtime>(window: &WebviewWindow<R>, label: &'static str) -> Option<WindowFacts> {
    let (tx, rx) = mpsc::channel();
    let target = window.clone();
    let _ = window.run_on_main_thread(move || {
        let (Some(mtm), Ok(pointer)) = (MainThreadMarker::new(), target.ns_window()) else { return };
        // SAFETY: as in `keep_above_everything`.
        let ns_window: &NSWindow = unsafe { &*(pointer as *const NSWindow) };
        let object: &AnyObject = ns_window.as_ref();
        let style = ns_window.styleMask().0 as u64;
        let is_panel = AnyClass::get(c"NSPanel").is_some_and(|panel| ns_window.isKindOfClass(panel));
        let _ = tx.send(WindowFacts {
            label,
            exists: true,
            visible: ns_window.isVisible(),
            on_screen: mac_on_screen(&mac_rect(ns_window.frame()), &screens_now(mtm)),
            level_ok: ns_window.level() >= MAC_FLOATING_LEVEL,
            spaces_ok: mac_follows_spaces(ns_window.collectionBehavior().0),
            class: object.class().name().to_string_lossy().into_owned(),
            style,
            panel: is_panel && style & MAC_NONACTIVATING as u64 != 0 && !ns_window.canBecomeKeyWindow(),
            activation_setter: activation_setter_applied(ns_window),
            sharing: ns_window.sharingType().0 as u64,
        });
    });
    rx.recv_timeout(Duration::from_secs(2)).ok()
}

/// Orders a window out (it stays open and keeps its page), or back in front without making it key or bringing
/// EchoLedger forward (`orderFrontRegardless`): the privacy service's hide and show.
pub fn order<R: Runtime>(window: &WebviewWindow<R>, front: bool) {
    let target = window.clone();
    let _ = window.run_on_main_thread(move || {
        let Ok(pointer) = target.ns_window() else { return };
        // SAFETY: as in `keep_above_everything`.
        let ns_window: &NSWindow = unsafe { &*(pointer as *const NSWindow) };
        if front {
            ns_window.orderFrontRegardless();
        } else {
            ns_window.orderOut(None);
        }
    });
}

#[cfg(test)]
mod tests {
    use super::*;

    extern "C-unwind" fn answer_no(_: *mut AnyObject, _: Sel) -> Bool {
        Bool::NO
    }

    extern "C-unwind" fn take_event(_: *mut AnyObject, _: Sel, _: *mut AnyObject) {}

    extern "C-unwind" fn release(_: *mut AnyObject, _: Sel) {}

    /// A window class shaped like tao's, with `extra` methods (by selector) and instance variables.
    fn shaped(name: &std::ffi::CStr, extra_methods: &[&str], ivars: &[&std::ffi::CStr]) -> &'static AnyClass {
        let mut builder = ClassBuilder::new(name, AnyClass::get(c"NSWindow").unwrap()).expect("a fresh class");
        // SAFETY: each function has the signature its selector expects.
        unsafe {
            builder.add_method(sel!(canBecomeKeyWindow), answer_no as extern "C-unwind" fn(*mut AnyObject, Sel) -> Bool);
            builder.add_method(sel!(canBecomeMainWindow), answer_no as extern "C-unwind" fn(*mut AnyObject, Sel) -> Bool);
            builder.add_method(sel!(sendEvent:), take_event as extern "C-unwind" fn(*mut AnyObject, Sel, *mut AnyObject));
            for method in extra_methods {
                match *method {
                    "dealloc" => builder.add_method(sel!(dealloc), release as extern "C-unwind" fn(*mut AnyObject, Sel)),
                    _ => builder.add_method(sel!(windowDidMove:), take_event as extern "C-unwind" fn(*mut AnyObject, Sel, *mut AnyObject)),
                }
            }
        }
        for ivar in ivars {
            builder.add_ivar::<u8>(ivar);
        }
        builder.register()
    }

    #[test]
    fn only_a_window_class_shaped_like_tao_s_becomes_a_panel() {
        assert_eq!(tao_window_shape(shaped(c"ShapedLikeTao", &[], &[c"ivars"])), Ok(()), "tao's own: three methods, its flag in `ivars`");
        assert_eq!(tao_window_shape(shaped(c"ShapedNoState", &[], &[])), Ok(()));
        assert_eq!(tao_window_shape(shaped(c"ShapedExtraMethod", &["windowDidMove:"], &[c"ivars"])).unwrap_err(), "its class has the methods canBecomeKeyWindow, canBecomeMainWindow, sendEvent:, windowDidMove:");
        assert!(tao_window_shape(shaped(c"ShapedDealloc", &["dealloc"], &[c"ivars"])).unwrap_err().contains("dealloc"), "a dealloc the panel would skip");
        assert_eq!(tao_window_shape(shaped(c"ShapedDropFlag", &[], &[c"ivars", c"drop_flag"])).unwrap_err(), "its class keeps state that needs cleaning up (ivars, drop_flag)");
        assert_eq!(TAO_WINDOW_METHODS, ["canBecomeKeyWindow", "canBecomeMainWindow", "sendEvent:"], "sorted, as compared");
    }

    #[test]
    fn nspanel_is_laid_out_as_nswindow_on_this_mac() {
        // Class introspection needs no window and no main thread: the conversion is safe only while this holds.
        assert!(panel_layout_matches(), "NSPanel adds instance variables or size to NSWindow on this macOS");
        let class = panel_class().expect("the panel class is made");
        assert_eq!(class.name().to_str().unwrap(), PANEL_CLASS);
        assert_eq!(class.superclass().map(|c| c.name().to_str().unwrap().to_string()).as_deref(), Some("NSPanel"));
        assert_eq!(class.instance_size(), AnyClass::get(c"NSWindow").unwrap().instance_size(), "no instance variables of its own");
        assert!(class.instance_variables().is_empty());
        for answered in [sel!(canBecomeKeyWindow), sel!(canBecomeMainWindow), sel!(sendEvent:)] {
            assert!(class.instance_method(answered).is_some(), "{answered:?}");
        }
        assert!(std::ptr::eq(panel_class().unwrap(), class), "made once");
    }
}
