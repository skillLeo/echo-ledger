//! AppKit on the main thread (a test of its own without the test harness, whose tests run on other threads), with tao's
//! own classes and a web view put in as wry puts it: a window observed once its content is in (the CI case) is left as it
//! is; a window tao builds inside `build_as_panel` is a non-activating panel from the start, observed and let go by KVO
//! as usual, with tao's state and the capture flag kept, released safely; a window of another class is left alone; and
//! tao's application delegate learns to answer `applicationShouldTerminate:`, once. Nothing is shown: the window is never
//! ordered in, the event loop never runs, and the process is never in the Dock.

#[cfg(target_os = "macos")]
fn main() {
    use desktop_lib::overlay::macos::{PANEL_CLASS, TAO_WINDOW_CLASS, make_panel, tao_window_shape};
    use objc2::rc::{Allocated, Retained};
    use objc2::runtime::{AnyClass, AnyObject, NSObjectProtocol};
    use objc2::{MainThreadMarker, msg_send, sel};
    use objc2_app_kit::{NSApplication, NSApplicationActivationPolicy, NSBackingStoreType, NSWindow, NSWindowSharingType, NSWindowStyleMask};
    use objc2_core_foundation::{CGPoint, CGRect, CGSize};
    use tao::platform::macos::WindowExtMacOS;

    let mtm = MainThreadMarker::new().expect("the test runs on the main thread");
    // tao's event loop first: it makes the application and sets its own delegate, as in the app.
    let event_loop = tao::event_loop::EventLoop::new();
    let app = NSApplication::sharedApplication(mtm);
    app.setActivationPolicy(NSApplicationActivationPolicy::Prohibited);

    // A counting KVO observer.
    static TOLD: std::sync::atomic::AtomicUsize = std::sync::atomic::AtomicUsize::new(0);
    extern "C-unwind" fn told(_: *mut AnyObject, _: objc2::runtime::Sel, _: *mut AnyObject, _: *mut AnyObject, _: *mut AnyObject, _: *mut std::ffi::c_void) {
        TOLD.fetch_add(1, std::sync::atomic::Ordering::SeqCst);
    }
    let observer_class = {
        let mut builder = objc2::runtime::ClassBuilder::new(c"CountingObserver", AnyClass::get(c"NSObject").unwrap()).unwrap();
        // SAFETY: the signature of observeValueForKeyPath:ofObject:change:context:.
        unsafe { builder.add_method(sel!(observeValueForKeyPath:ofObject:change:context:), told as extern "C-unwind" fn(*mut AnyObject, objc2::runtime::Sel, *mut AnyObject, *mut AnyObject, *mut AnyObject, *mut std::ffi::c_void)) };
        builder.register()
    };
    // SAFETY: a plain NSObject subclass, made on the main thread.
    let observer: Retained<AnyObject> = unsafe { msg_send![observer_class, new] };
    let observe = |window: &NSWindow, key: &str, on: bool| {
        let key = objc2_foundation::NSString::from_str(key);
        // SAFETY: KVO's public registration; every observation added is removed.
        unsafe {
            if on {
                let _: () = msg_send![window, addObserver: &*observer, forKeyPath: &*key, options: 1usize, context: std::ptr::null_mut::<std::ffi::c_void>()];
            } else {
                let _: () = msg_send![window, removeObserver: &*observer, forKeyPath: &*key];
            }
        }
    };
    let isa = |window: &NSWindow| -> String { (window.as_ref() as &AnyObject).class().name().to_str().unwrap().to_string() };

    // The web view as wry puts it in a window: inside a view of its own that becomes the window's content view.
    let put_web_view = |window: &NSWindow| -> Retained<AnyObject> {
        let frame = CGRect::new(CGPoint::new(0.0, 0.0), CGSize::new(352.0, 84.0));
        // SAFETY: WebKit's and AppKit's public initialisers and setters, on the main thread.
        unsafe {
            let config: Retained<AnyObject> = msg_send![AnyClass::get(c"WKWebViewConfiguration").expect("WebKit is linked"), new];
            let allocated: Allocated<AnyObject> = msg_send![AnyClass::get(c"WKWebView").unwrap(), alloc];
            let view: Retained<AnyObject> = msg_send![allocated, initWithFrame: frame, configuration: &*config];
            let parent: Retained<AnyObject> = msg_send![AnyClass::get(c"NSView").unwrap(), new];
            let _: () = msg_send![&*parent, addSubview: &*view];
            let _: () = msg_send![window, setContentView: &*parent];
            view
        }
    };
    let new_tao_window = || tao::window::WindowBuilder::new().with_visible(false).with_focused(false).with_inner_size(tao::dpi::LogicalSize::new(352.0, 84.0)).build(&event_loop).expect("tao builds a window");

    // The CI case: tao's window with wry's web view put in it. AppKit's title bar and WebKit observe the window then,
    // and its class is KVO's NSKVONotifying_TaoWindow: its class must not change any more, so it is left as it is.
    let tao_window = new_tao_window();
    // SAFETY: tao hands out its window's NSWindow, alive while `tao_window` is, on the main thread here.
    let window: &NSWindow = unsafe { &*(tao_window.ns_window() as *const NSWindow) };
    assert_eq!(isa(window), TAO_WINDOW_CLASS, "nothing observes a window tao just built");
    let web_view = put_web_view(window);
    assert_eq!(isa(window), format!("NSKVONotifying_{TAO_WINDOW_CLASS}"), "observed once its content is in, as in the app");
    observe(window, "title", true);
    assert_eq!(make_panel(window).unwrap_err(), format!("left as NSKVONotifying_{TAO_WINDOW_CLASS}: observed, so it must be built as a panel from the start"));
    assert_eq!(isa(window), format!("NSKVONotifying_{TAO_WINDOW_CLASS}"), "untouched");
    let told_before = TOLD.load(std::sync::atomic::Ordering::SeqCst);
    window.setTitle(&objc2_foundation::NSString::from_str("still observed"));
    assert!(TOLD.load(std::sync::atomic::Ordering::SeqCst) > told_before, "its observers keep working");
    observe(window, "title", false);
    // SAFETY: the view leaves the window on the main thread.
    unsafe {
        let _: () = msg_send![&*web_view, removeFromSuperview];
    }
    drop(web_view);
    drop(tao_window);

    // The fix: tao builds the floating window as a panel from the start (build_as_panel), so everything that observes it
    // later observes a panel: KVO makes its class for the panel, and every observer is told and removed as usual.
    let tao_window = desktop_lib::overlay::macos::build_as_panel(new_tao_window);
    // SAFETY: as above.
    let window: &NSWindow = unsafe { &*(tao_window.ns_window() as *const NSWindow) };
    let object: &AnyObject = window.as_ref();
    assert_eq!(isa(window), PANEL_CLASS, "a panel from its allocation on");
    assert!(window.isKindOfClass(AnyClass::get(c"NSPanel").unwrap()), "a real NSPanel");
    // tao's own state lives after NSWindow's part, where tao put it.
    let tao_class = AnyClass::get(c"TaoWindow").expect("tao's class");
    tao_window_shape(tao_class).expect("tao's window class is shaped as the allocator expects");
    let state = tao_class.instance_variable(c"ivars").expect("tao keeps its window state in `ivars`");
    // SAFETY: the object was allocated with TaoWindow's size and tao wrote its state there; its first byte is `focusable`.
    assert_eq!(unsafe { *state.load_ptr::<u8>(object) }, 1, "tao's state as tao set it (its builder's default: focusable)");
    let web_view = put_web_view(window);
    assert_eq!(isa(window), format!("NSKVONotifying_{PANEL_CLASS}"), "observed now: KVO's class of the panel");
    window.setSharingType(NSWindowSharingType::None);
    make_panel(window).expect("a panel from the start takes its style");
    assert_eq!(desktop_lib::overlay::macos::class_of(object).name().to_str().unwrap(), PANEL_CLASS);
    assert!(window.styleMask().contains(NSWindowStyleMask::NonactivatingPanel), "non-activating");
    assert!(!window.canBecomeKeyWindow() && !window.canBecomeMainWindow(), "never the keyboard's");
    assert!(!window.hidesOnDeactivate(), "stays on screen while another app is active");
    assert_eq!(window.sharingType(), NSWindowSharingType::None, "the capture flag stays (screen-share privacy)");
    make_panel(window).expect("again");
    let setter = window.respondsToSelector(sel!(_setPreventsActivation:));
    // An observer of our own, before and after: told, and removed without complaint.
    observe(window, "title", true);
    let told_before = TOLD.load(std::sync::atomic::Ordering::SeqCst);
    window.setTitle(&objc2_foundation::NSString::from_str("EchoLedger recording controls"));
    assert!(TOLD.load(std::sync::atomic::Ordering::SeqCst) > told_before, "the observer heard the change");
    observe(window, "title", false);
    // The web view leaves (WebKit lets go of the window) and the window goes: no complaint, no crash.
    // SAFETY: the view leaves the window on the main thread.
    unsafe {
        let _: () = msg_send![&*web_view, removeFromSuperview];
    }
    drop(web_view);
    drop(tao_window);
    // The allocator is back to plain windows: the next one tao builds is tao's own.
    let plain = new_tao_window();
    // SAFETY: as above.
    assert_eq!(isa(unsafe { &*(plain.ns_window() as *const NSWindow) }), TAO_WINDOW_CLASS, "only the window asked for");
    drop(plain);

    // Any other window is left as it is.
    let other: Retained<NSWindow> = {
        let frame = CGRect::new(CGPoint::new(0.0, 0.0), CGSize::new(352.0, 84.0));
        // SAFETY: on the main thread, an NSWindow initialised with its designated initialiser, deferred, never shown.
        unsafe {
            let allocated: Allocated<NSWindow> = msg_send![AnyClass::get(c"NSWindow").unwrap(), alloc];
            msg_send![allocated, initWithContentRect: frame, styleMask: NSWindowStyleMask::Borderless, backing: NSBackingStoreType::Buffered, defer: true]
        }
    };
    // SAFETY: the Retained owns it; AppKit must not release it again when it closes.
    unsafe { other.setReleasedWhenClosed(false) };
    assert_eq!(make_panel(&other).unwrap_err(), "left as NSWindow");
    assert!(!other.isKindOfClass(AnyClass::get(c"NSPanel").unwrap()));

    // Released as AppKit releases it: no crash in dealloc.
    other.close();
    drop(other);

    // tao's application delegate learns applicationShouldTerminate:, once.
    let delegate = app.delegate().expect("tao's delegate");
    let delegate: &AnyObject = AsRef::<AnyObject>::as_ref(&*delegate);
    assert!(!delegate.class().responds_to(sel!(applicationShouldTerminate:)), "tao 0.37 has none of its own");
    assert!(desktop_lib::app_life::mac::install(&app), "added");
    assert!(delegate.class().responds_to(sel!(applicationShouldTerminate:)));
    assert!(!desktop_lib::app_life::mac::install(&app), "never twice: an answer already there is left as it is");
    // With no app running behind it, nothing is recording: quit now (NSTerminateNow).
    // SAFETY: the method was just added with this signature.
    let reply: usize = unsafe { msg_send![delegate, applicationShouldTerminate: &*app] };
    assert_eq!(reply, 1);

    println!("mac_appkit: ok (tao builds a floating window as a panel, an observed window is left alone, tao's delegate answers quits; the activation setter is {})", if setter { "there" } else { "not there" });
}

#[cfg(not(target_os = "macos"))]
fn main() {}
