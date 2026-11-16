//! AppKit on the main thread (a test of its own without the test harness, whose tests run on other threads), with tao's
//! own classes, as the app has them: a window tao builds becomes a non-activating floating panel with everything else
//! kept (its own state, the capture flag), it is released safely, a window of another class is left alone, and tao's
//! application delegate learns to answer `applicationShouldTerminate:`, once. Nothing is shown: the window is never
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

    // tao's own window class, and one of its windows (never shown).
    let tao_window = tao::window::WindowBuilder::new().with_visible(false).with_focused(false).with_inner_size(tao::dpi::LogicalSize::new(352.0, 84.0)).build(&event_loop).expect("tao builds a window");
    // SAFETY: tao hands out its window's NSWindow, alive while `tao_window` is, on the main thread here.
    let window: &NSWindow = unsafe { &*(tao_window.ns_window() as *const NSWindow) };
    let object: &AnyObject = window.as_ref();
    let tao_class = object.class();
    assert_eq!(tao_class.name().to_str().unwrap(), TAO_WINDOW_CLASS);
    tao_window_shape(tao_class).expect("tao's window class is shaped as make_panel expects");
    // tao's own state (whether the window may take the keyboard) sits in `ivars` after NSWindow's part.
    let state = tao_class.instance_variable(c"ivars").expect("tao keeps its window state in `ivars`");
    // SAFETY: `ivars` is tao's state of this object; its first byte is the `focusable` flag.
    let before = unsafe { *state.load_ptr::<u8>(object) };
    window.setSharingType(NSWindowSharingType::None);

    make_panel(window).expect("tao's window becomes a panel");
    assert_eq!(object.class().name().to_str().unwrap(), PANEL_CLASS);
    assert!(window.isKindOfClass(AnyClass::get(c"NSPanel").unwrap()), "a real NSPanel");
    assert!(window.styleMask().contains(NSWindowStyleMask::NonactivatingPanel), "non-activating");
    assert!(!window.canBecomeKeyWindow() && !window.canBecomeMainWindow(), "never the keyboard's");
    assert!(!window.hidesOnDeactivate(), "stays on screen while another app is active");
    assert_eq!(window.sharingType(), NSWindowSharingType::None, "the capture flag stays (screen-share privacy)");
    // SAFETY: as above; the object kept its size and tao's state is where it was.
    assert_eq!(unsafe { *state.load_ptr::<u8>(object) }, before, "tao's own state untouched");
    make_panel(window).expect("again: already a panel");
    let setter = window.respondsToSelector(sel!(_setPreventsActivation:));

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

    // Released as tao and AppKit release them: no crash in dealloc.
    drop(tao_window);
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

    println!("mac_appkit: ok (tao's window became a panel, tao's delegate answers quits; the activation setter is {})", if setter { "there" } else { "not there" });
}

#[cfg(not(target_os = "macos"))]
fn main() {}
