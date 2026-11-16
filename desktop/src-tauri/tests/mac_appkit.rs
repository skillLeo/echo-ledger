//! AppKit on the main thread (a test of its own without the test harness, whose tests run on other threads): a window
//! built as tao builds one becomes a non-activating floating panel with everything else kept (its own state, the capture
//! flag), it is released safely, and the application delegate learns to answer `applicationShouldTerminate:`. Nothing
//! is shown: the windows are never ordered in and the process is never in the Dock.

#[cfg(target_os = "macos")]
fn main() {
    use desktop_lib::overlay::macos::{PANEL_CLASS, TAO_WINDOW_CLASS, make_panel};
    use objc2::rc::{Allocated, Retained};
    use objc2::runtime::{AnyClass, AnyObject, ClassBuilder, NSObjectProtocol};
    use objc2::{MainThreadMarker, msg_send, sel};
    use objc2_app_kit::{NSApplication, NSApplicationActivationPolicy, NSBackingStoreType, NSWindow, NSWindowSharingType, NSWindowStyleMask};
    use objc2_core_foundation::{CGPoint, CGRect, CGSize};

    let mtm = MainThreadMarker::new().expect("the test runs on the main thread");
    let app = NSApplication::sharedApplication(mtm);
    app.setActivationPolicy(NSApplicationActivationPolicy::Prohibited);

    // tao's window: an NSWindow subclass named TaoWindow with state of its own after NSWindow's.
    let tao = {
        let mut builder = ClassBuilder::new(c"TaoWindow", AnyClass::get(c"NSWindow").unwrap()).expect("no other TaoWindow here");
        builder.add_ivar::<u64>(c"tao_state");
        builder.register()
    };
    assert_eq!(tao.name().to_str().unwrap(), TAO_WINDOW_CLASS);
    let state = tao.instance_variable(c"tao_state").unwrap();
    let build = |class: &AnyClass| -> Retained<NSWindow> {
        let frame = CGRect::new(CGPoint::new(0.0, 0.0), CGSize::new(352.0, 84.0));
        // SAFETY: on the main thread, a window class allocated and initialised with NSWindow's designated initialiser,
        // deferred (no window server window), never shown.
        unsafe {
            let allocated: Allocated<NSWindow> = msg_send![class, alloc];
            msg_send![allocated, initWithContentRect: frame, styleMask: NSWindowStyleMask::Borderless, backing: NSBackingStoreType::Buffered, defer: true]
        }
    };

    let window = build(tao);
    let object: &AnyObject = window.as_ref();
    // SAFETY: the ivar is a u64 of this object's class.
    unsafe { *state.load_ptr::<u64>(object) = 0x00EC_401E_D6E2 };
    window.setSharingType(NSWindowSharingType::None);
    // SAFETY: the Retained below owns the window; AppKit must not release it again when it closes.
    unsafe { window.setReleasedWhenClosed(false) };

    make_panel(&window).expect("tao's window becomes a panel");
    assert_eq!(object.class().name().to_str().unwrap(), PANEL_CLASS);
    assert!(window.isKindOfClass(AnyClass::get(c"NSPanel").unwrap()), "a real NSPanel");
    assert!(window.styleMask().contains(NSWindowStyleMask::NonactivatingPanel), "non-activating");
    assert!(!window.canBecomeKeyWindow() && !window.canBecomeMainWindow(), "never the keyboard's");
    assert!(!window.hidesOnDeactivate(), "stays on screen while another app is active");
    assert_eq!(window.sharingType(), NSWindowSharingType::None, "the capture flag stays (screen-share privacy)");
    // SAFETY: as above; the object kept its size and its own state is where it was.
    assert_eq!(unsafe { *state.load_ptr::<u64>(object) }, 0x00EC_401E_D6E2, "tao's own state untouched");
    make_panel(&window).expect("again: already a panel");
    assert_eq!(object.class().name().to_str().unwrap(), PANEL_CLASS);

    // Any other window is left as it is.
    let other = build(AnyClass::get(c"NSWindow").unwrap());
    assert_eq!(make_panel(&other).unwrap_err(), "left as NSWindow");
    assert!(!other.isKindOfClass(AnyClass::get(c"NSPanel").unwrap()));

    // Released as panels and windows are: no crash in dealloc.
    window.close();
    drop(window);
    drop(other);

    // The application delegate learns applicationShouldTerminate:, once.
    let delegate_class = ClassBuilder::new(c"FakeAppDelegate", AnyClass::get(c"NSObject").unwrap()).unwrap().register();
    // SAFETY: a plain NSObject subclass, made and set as the delegate on the main thread.
    let delegate: Retained<AnyObject> = unsafe { msg_send![delegate_class, new] };
    unsafe {
        let _: () = msg_send![&*app, setDelegate: &*delegate];
    }
    assert!(!delegate.class().responds_to(sel!(applicationShouldTerminate:)));
    assert!(desktop_lib::app_life::mac::install(&app), "added");
    assert!(delegate.class().responds_to(sel!(applicationShouldTerminate:)));
    assert!(!desktop_lib::app_life::mac::install(&app), "never twice: an answer already there is left as it is");
    // With no app running behind it, nothing is recording: quit now (NSTerminateNow).
    // SAFETY: the method was just added with this signature.
    let reply: usize = unsafe { msg_send![&*delegate, applicationShouldTerminate: &*app] };
    assert_eq!(reply, 1);

    let prevents = AnyClass::get(c"NSPanel").unwrap().responds_to(sel!(_setPreventsActivation:));
    println!("mac_appkit: ok (the floating window panel and the quit answer; the activation setter is {})", if prevents { "there" } else { "not there" });
}

#[cfg(not(target_os = "macos"))]
fn main() {}
