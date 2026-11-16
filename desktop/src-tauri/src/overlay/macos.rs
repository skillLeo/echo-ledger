//! The AppKit side of the overlays: what keeps them over full-screen meetings and in every Space, and the displays' usable
//! areas in points. AppKit answers only on the main thread, so each call goes there and waits for it (from the main
//! thread itself the task runs at once).
//!
//! Every floating window is a real non-activating panel (since v1.8.1). EchoLedger is a normal app with a Dock icon, and
//! since macOS 10.14 a plain window of such an app may not float over another app's full-screen window, while a
//! non-activating `NSPanel` may, and a click on it never makes EchoLedger the active app. tao builds every window as its
//! own `TaoWindow` (an `NSWindow` subclass), and once a window's content is in, AppKit's title bar and WebKit observe it
//! (KVO gives it a class of its own, `NSKVONotifying_TaoWindow`), after which its class must never change: KVO's records
//! are tied to it. So a floating window is a panel from its allocation on ([`build_as_panel`]): while it is built, on the
//! main thread, NSWindow's allocator hands tao an object of [`PANEL_CLASS`] (a subclass of `NSPanel` made once at
//! runtime) with TaoWindow's size. tao initialises it with NSWindow's own initialiser (a `super` call, so NSPanel's
//! initialiser overrides are skipped), and whatever observes it later observes a panel; what a floating panel needs is set
//! by [`make_panel`] (the non-activating style, the window server's activation setter, staying on screen when EchoLedger
//! is not active) and checked by tests/mac_appkit.rs and the smoke test. `NSPanel` adds no instance variables
//! to `NSWindow` and neither does the panel class, so tao's own state sits after NSWindow's part where tao puts it
//! (checked at runtime: a layout or a TaoWindow that ever differs leaves the window as it is, and the Mac check and the
//! smoke test say so). tao's window delegate and the capture flag (`sharingType`) are tao's and the privacy service's as
//! for any window.

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
    let isa = object.class();
    // What the object is to everyone else: KVO's own subclass answers `class` with the class it was made for.
    let base = class_of(object);
    if !std::ptr::eq(base, class) {
        let name = base.name().to_string_lossy().into_owned();
        if name != TAO_WINDOW_CLASS || base.instance_size() < class.instance_size() {
            return Err(format!("left as {name}"));
        }
        tao_window_shape(base).map_err(|why| format!("left as {name}: {why}"))?;
        if !std::ptr::eq(isa, base) {
            // Observed (AppKit's title bar and WebKit observe a window once its content is in): KVO's records are tied
            // to the class they were made under, so the class never changes now. A floating window is built as a panel
            // from the start instead (`build_as_panel`); one that was not is left as it is.
            return Err(format!("left as {}: observed, so it must be built as a panel from the start", isa.name().to_string_lossy()));
        }
        // SAFETY: on the main thread, a TaoWindow (an NSWindow whose own state comes after NSWindow's and needs no
        // cleaning up), observed by nothing, becomes a panel class whose objects are exactly NSWindow's layout
        // (`panel_layout_matches`), so every byte it reads is there.
        unsafe { objc2::ffi::object_setClass((object as *const AnyObject).cast_mut(), class) };
    }
    window.setStyleMask(NSWindowStyleMask(mac_overlay_style(window.styleMask().0)));
    // NSPanel's own initialiser never ran for this panel (its class changed after AppKit made it, or tao's `super` call to
    // NSWindow's initialiser skipped it), so the window server was never told that a click must not activate EchoLedger:
    // the setter NSPanel uses, when this macOS has it (tauri-nspanel does the same).
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

/// The class an object says it is (`[object class]`): its own, or for one KVO observes, the class KVO made its class for.
pub fn class_of(object: &AnyObject) -> &'static AnyClass {
    // SAFETY: `class` is NSObject's, answered by every object here.
    let class: *const AnyClass = unsafe { msg_send![object, class] };
    // SAFETY: `class` never answers nil.
    unsafe { &*class }
}

/// The name KVO gives the class it makes for observed objects of `class`.
pub fn kvo_name(class: &AnyClass) -> String {
    format!("NSKVONotifying_{}", class.name().to_string_lossy())
}

thread_local! {
    /// [`build_as_panel`] runs on this (the main) thread, and the TaoWindow it asks for is still to come.
    static BUILDING: std::cell::Cell<bool> = const { std::cell::Cell::new(false) };
    static NEXT_IS_PANEL: std::cell::Cell<bool> = const { std::cell::Cell::new(false) };
}

/// NSWindow's own `allocWithZone:` implementation, kept for every thread (set before the first swap, never cleared): any
/// thread that allocates a window while [`allocate`] stands in reaches the real allocator.
static ORIGINAL: std::sync::atomic::AtomicUsize = std::sync::atomic::AtomicUsize::new(0);

type AllocWithZone = extern "C-unwind" fn(*const AnyClass, Sel, *mut std::ffi::c_void) -> *mut AnyObject;

/// `+[NSWindow allocWithZone:]` while [`build_as_panel`] runs: every window is allocated by NSWindow's own allocator as
/// before, except the one TaoWindow asked for on the main thread, which is allocated as the panel class with TaoWindow's
/// size (tao's own state goes after NSWindow's part), a panel before anything can observe it.
extern "C-unwind" fn allocate(class: *const AnyClass, cmd: Sel, zone: *mut std::ffi::c_void) -> *mut AnyObject {
    // SAFETY: the runtime passes the class being allocated.
    let asked = unsafe { class.as_ref() };
    let wanted = NEXT_IS_PANEL.with(|next| next.get()) && asked.is_some_and(|c| c.name().to_bytes() == TAO_WINDOW_CLASS.as_bytes() && tao_window_shape(c).is_ok());
    if wanted && let (Some(tao), Some(panel)) = (asked, panel_class()) && tao.instance_size() >= panel.instance_size() {
        NEXT_IS_PANEL.with(|next| next.set(false));
        // SAFETY: a zeroed object of the panel class with room for TaoWindow's own state after NSWindow's part, as
        // `alloc` returns it (+1, uninitialised).
        return unsafe { objc2::ffi::class_createInstance(panel, tao.instance_size() - panel.instance_size()) };
    }
    let original = ORIGINAL.load(Ordering::SeqCst);
    // SAFETY: NSWindow's own `allocWithZone:` (stored before `allocate` was ever swapped in, never cleared), called as the
    // runtime would.
    unsafe { std::mem::transmute::<usize, AllocWithZone>(original)(class, cmd, zone) }
}

/// NSWindow's own `allocWithZone:` (a method of NSWindow's metaclass itself, never the one it inherits from NSObject,
/// which every class uses); none when NSWindow has none of its own on this macOS.
pub fn nswindow_own_allocator() -> Option<&'static objc2::runtime::Method> {
    let window = AnyClass::get(c"NSWindow")?.metaclass();
    let own = window.instance_methods().iter().any(|m| m.name() == sel!(allocWithZone:));
    // The method found is NSWindow's own (asked just above), so its lookup lands on it.
    own.then(|| window.instance_method(sel!(allocWithZone:))).flatten()
}

/// Puts NSWindow's own allocator back when the build ends, also if it panics.
struct Restore(*const objc2::runtime::Method);

impl Drop for Restore {
    fn drop(&mut self) {
        // SAFETY: the method swapped in `build_as_panel`, given back its own implementation on the main thread.
        unsafe { objc2::ffi::method_setImplementation(self.0, std::mem::transmute::<usize, objc2::runtime::Imp>(ORIGINAL.load(Ordering::SeqCst))) };
        BUILDING.with(|b| b.set(false));
        NEXT_IS_PANEL.with(|next| next.set(false));
    }
}

/// Runs `build` on the main thread so the TaoWindow it builds is a floating panel from its allocation on: for the
/// length of the call NSWindow's own `allocWithZone:` is [`allocate`], then its own again. Only a method NSWindow owns is
/// swapped (never NSObject's, which every class allocates with); off the main thread, nested, or without that method it
/// runs as it is, and `make_panel`, the Mac check and the smoke test say what it got.
pub fn build_as_panel<T>(build: impl FnOnce() -> T) -> T {
    let (Some(method), false, Some(_)) = (nswindow_own_allocator(), BUILDING.with(|b| b.get()) || MainThreadMarker::new().is_none(), panel_class()) else {
        return build();
    };
    let ours: AllocWithZone = allocate;
    let original = method.implementation() as usize;
    // Kept for every thread before `allocate` can run anywhere.
    if original != ours as usize {
        ORIGINAL.store(original, Ordering::SeqCst);
    }
    let method = method as *const objc2::runtime::Method;
    // SAFETY: `ours` has `allocWithZone:`'s signature and calls the implementation it replaces for every other window.
    unsafe { objc2::ffi::method_setImplementation(method, std::mem::transmute::<AllocWithZone, objc2::runtime::Imp>(ours)) };
    let _restore = Restore(method);
    BUILDING.with(|b| b.set(true));
    NEXT_IS_PANEL.with(|next| next.set(true));
    build()
}

/// [`build_as_panel`] on the main thread, waited for (from the main thread itself it runs at once). None when the main
/// thread is gone.
pub fn build_on_main_as_panel<R: Runtime, T: Send + 'static>(app: &AppHandle<R>, build: impl FnOnce() -> T + Send + 'static) -> Option<T> {
    if MainThreadMarker::new().is_some() {
        return Some(build_as_panel(build));
    }
    let (tx, rx) = mpsc::channel();
    app.run_on_main_thread(move || {
        let _ = tx.send(build_as_panel(build));
    })
    .ok()?;
    rx.recv().ok()
}

/// Whether `make_panel` told the window server about this window (pure over the runtime): it is a panel of
/// [`PANEL_CLASS`] and the setter is there (`make_panel` always sends it then).
fn activation_setter_applied(window: &NSWindow) -> bool {
    panel_class().is_some_and(|class| std::ptr::eq(class_of(window.as_ref()), class)) && window.respondsToSelector(sel!(_setPreventsActivation:))
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
