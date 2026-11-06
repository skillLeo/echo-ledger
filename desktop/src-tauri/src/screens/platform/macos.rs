//! ScreenCaptureKit (macOS 14 and later) through `objc2-screen-capture-kit`. Once a second the shareable content is
//! read (every window with its app and title, the displays), [`choose`] picks the meeting window or the display it is
//! on, and one screenshot is taken of the window (`SCContentFilter::initWithDesktopIndependentWindow`) or of the display
//! without EchoLedger's own windows (`initWithDisplay_excludingWindows`), with `SCScreenshotManager` at 1920 px on the
//! long side at most (the size a screen is stored at), no cursor. It needs Screen Recording
//! (`CGPreflightScreenCaptureAccess`, asked with `CGRequestScreenCaptureAccess`); macOS 15 and later ask the person
//! again about once a month, and Settings says so. What ScreenCaptureKit says of a window becomes the window facts in
//! pure functions tested here; the capture itself is a manual check (docs/MANUAL_QA.md).

use std::ptr::NonNull;
use std::sync::mpsc;
use std::sync::{Mutex, MutexGuard};
use std::time::Duration;

use block2::RcBlock;
use objc2::AnyThread;
use objc2::rc::{Retained, autoreleasepool};
use objc2_core_foundation::{CFRetained, CGRect};
use objc2_core_graphics::{CGDataProvider, CGImage, CGMainDisplayID, CGPreflightScreenCaptureAccess, CGRequestScreenCaptureAccess};
use objc2_foundation::{NSArray, NSError};
use objc2_screen_capture_kit::{SCContentFilter, SCScreenshotManager, SCShareableContent, SCStreamConfiguration, SCWindow};

use super::{Frame, Grab, Snapshot, SysWindow, Target, Wanted, choose, pixel_order, to_bgra};
use crate::detect::classify::app_bundle_id;
use crate::screens::privacy::{HoldReason, WindowFacts, is_excluded};

/// How long ScreenCaptureKit may take to answer.
const WAIT: Duration = Duration::from_secs(3);
/// Screenshots are taken at most this large on the long side (the stored screen is 1920 px at most).
const MAX_LONG_SIDE: f64 = crate::screens::encode::MAX_LONG_SIDE as f64;
/// `kCVPixelFormatType_32BGRA`: the layout the detector and the encoder read.
const BGRA: u32 = u32::from_be_bytes(*b"BGRA");

/// ScreenCaptureKit answers on its own queue; its content objects are immutable and safe to hand to the capture thread.
struct Sendable<T>(T);
// SAFETY: only immutable ScreenCaptureKit and CoreGraphics objects are wrapped.
unsafe impl<T> Send for Sendable<T> {}

/// The content `find_target` read last, which `grab` takes the window or display from.
static LAST: Mutex<Option<Sendable<Retained<SCShareableContent>>>> = Mutex::new(None);

fn last() -> MutexGuard<'static, Option<Sendable<Retained<SCShareableContent>>>> {
    LAST.lock().unwrap_or_else(|p| p.into_inner())
}

/// macOS 14.4 is the app's minimum, which has everything used here.
pub fn supported() -> bool {
    true
}

pub fn permission() -> bool {
    CGPreflightScreenCaptureAccess()
}

pub fn request_permission() -> bool {
    CGRequestScreenCaptureAccess()
}

/// The windows and displays that can be captured now; `None` when ScreenCaptureKit refused or did not answer.
fn shareable() -> Option<Retained<SCShareableContent>> {
    let (tx, rx) = mpsc::channel::<Sendable<Option<Retained<SCShareableContent>>>>();
    let handler = RcBlock::new(move |content: *mut SCShareableContent, _error: *mut NSError| {
        // SAFETY: the content is valid for the call; retaining it keeps it past the call.
        let content = unsafe { Retained::retain(content) };
        let _ = tx.send(Sendable(content));
    });
    // Off-screen windows too: a minimised meeting window pauses the capture instead of looking closed.
    // SAFETY: ScreenCaptureKit keeps the handler until it was called once.
    unsafe { SCShareableContent::getShareableContentExcludingDesktopWindows_onScreenWindowsOnly_completionHandler(true, false, &handler) };
    rx.recv_timeout(WAIT).ok().and_then(|s| s.0)
}

/// What ScreenCaptureKit says of one window.
#[derive(Clone, Debug, Default)]
struct MacWindow {
    id: u32,
    pid: i32,
    title: String,
    app_name: String,
    bundle: String,
    on_screen: bool,
    layer: isize,
    frame: CGRect,
}

fn mac_window(window: &SCWindow) -> Option<MacWindow> {
    let app = unsafe { window.owningApplication() }?;
    Some(MacWindow {
        id: unsafe { window.windowID() },
        pid: unsafe { app.processID() },
        title: unsafe { window.title() }.map(|t| t.to_string()).unwrap_or_default(),
        app_name: unsafe { app.applicationName() }.to_string(),
        bundle: unsafe { app.bundleIdentifier() }.to_string(),
        on_screen: unsafe { window.isOnScreen() },
        layer: unsafe { window.windowLayer() },
        frame: unsafe { window.frame() },
    })
}

fn intersects(a: CGRect, b: CGRect) -> bool {
    a.origin.x < b.origin.x + b.size.width && b.origin.x < a.origin.x + a.size.width && a.origin.y < b.origin.y + b.size.height && b.origin.y < a.origin.y + a.size.height
}

/// The window as [`choose`] sees it. A window that is minimised, hidden (Cmd-H), on another Space or in Stage Manager's
/// strip is not on screen; floating panels sit above the normal layer and are never the meeting (Zoom's share bar).
/// The meeting app's windows are those of its bundle (a browser's helpers are grouped with it as auto mode does).
fn sys_window(w: MacWindow, displays: &[(isize, CGRect)], meeting_bundle: Option<&str>) -> SysWindow {
    let area = (w.frame.size.width.max(0.0) * w.frame.size.height.max(0.0)) as i64;
    SysWindow {
        id: w.id as isize,
        meeting: meeting_bundle.is_some_and(|m| !w.bundle.is_empty() && app_bundle_id(&w.bundle).eq_ignore_ascii_case(m)),
        displays: displays.iter().filter(|(_, d)| intersects(*d, w.frame)).map(|(id, _)| *id).collect(),
        bundle: Some(w.bundle.clone()).filter(|b| !b.is_empty()),
        facts: WindowFacts {
            title: w.title,
            process: if w.app_name.is_empty() { w.bundle } else { w.app_name },
            visible: true,
            minimised: !w.on_screen,
            cloaked: false,
            area,
            tool: w.layer != 0,
        },
    }
}

/// The windows of other apps and the displays (the main one, else the first) as [`choose`] reads them.
fn snapshot_of(windows: Vec<MacWindow>, displays: &[(isize, CGRect)], main: isize, own_pid: i32, meeting_bundle: Option<&str>) -> Snapshot {
    let windows = windows.into_iter().filter(|w| w.pid != own_pid).map(|w| sys_window(w, displays, meeting_bundle)).collect();
    let main = displays.iter().map(|(id, _)| *id).find(|id| *id == main).or_else(|| displays.first().map(|(id, _)| *id));
    Snapshot { windows, main }
}

pub fn find_target(wanted: &Wanted<'_>) -> Result<Target, HoldReason> {
    if !permission() {
        return Err(HoldReason::Permission);
    }
    if wanted.meeting.is_some_and(|m| is_excluded(&m.name, wanted.excluded)) {
        return Err(HoldReason::ExcludedApp);
    }
    autoreleasepool(|_| {
        let content = shareable().ok_or(HoldReason::NoWindow)?;
        let displays: Vec<(isize, CGRect)> = unsafe { content.displays() }.iter().map(|d| (unsafe { d.displayID() } as isize, unsafe { d.frame() })).collect();
        let windows: Vec<MacWindow> = unsafe { content.windows() }.iter().filter_map(|w| mac_window(&w)).collect();
        let snapshot = snapshot_of(windows, &displays, CGMainDisplayID() as isize, std::process::id() as i32, wanted.meeting.map(|m| m.name.as_str()));
        let target = choose(wanted, &snapshot)?;
        *last() = Some(Sendable(content));
        Ok(target)
    })
}

pub fn open(target: Target) -> Result<Box<dyn Grab>, String> {
    Ok(Box::new(Screenshots { target }))
}

/// The screenshot's size in pixels: the content's size in points at its scale, brought down to [`MAX_LONG_SIDE`] on
/// the long side, 1 px at least.
fn capture_size(width_pt: f64, height_pt: f64, scale: f64) -> (usize, usize) {
    let scale = if scale.is_finite() { scale.max(1.0) } else { 1.0 };
    let (mut w, mut h) = (width_pt.max(0.0) * scale, height_pt.max(0.0) * scale);
    let long = w.max(h);
    if long > MAX_LONG_SIDE {
        w *= MAX_LONG_SIDE / long;
        h *= MAX_LONG_SIDE / long;
    }
    (w.round().max(1.0) as usize, h.round().max(1.0) as usize)
}

/// Screenshots of one window or display, one per `grab`.
struct Screenshots {
    target: Target,
}

impl Screenshots {
    fn filter(&self, content: &SCShareableContent) -> Result<Retained<SCContentFilter>, String> {
        match self.target {
            Target::Window(id) => {
                let window = unsafe { content.windows() }.iter().find(|w| unsafe { w.windowID() } as isize == id).ok_or("The meeting window closed.")?;
                Ok(unsafe { SCContentFilter::initWithDesktopIndependentWindow(SCContentFilter::alloc(), &window) })
            }
            Target::Display(id) => {
                let display = unsafe { content.displays() }.iter().find(|d| unsafe { d.displayID() } as isize == id).ok_or("The screen is gone.")?;
                let own = std::process::id() as i32;
                let ours: Vec<Retained<SCWindow>> =
                    unsafe { content.windows() }.iter().filter(|w| unsafe { w.owningApplication() }.is_some_and(|a| unsafe { a.processID() } == own)).collect();
                let excluded = NSArray::from_retained_slice(&ours);
                Ok(unsafe { SCContentFilter::initWithDisplay_excludingWindows(SCContentFilter::alloc(), &display, &excluded) })
            }
        }
    }
}

/// One screenshot through `SCScreenshotManager`, waited for here.
fn screenshot(filter: &SCContentFilter, config: &SCStreamConfiguration) -> Result<CFRetained<CGImage>, String> {
    let (tx, rx) = mpsc::channel::<Sendable<Option<CFRetained<CGImage>>>>();
    let handler = RcBlock::new(move |image: *mut CGImage, _error: *mut NSError| {
        // SAFETY: the image is valid for the call; retaining it keeps it past the call.
        let image = NonNull::new(image).map(|p| unsafe { CFRetained::retain(p) });
        let _ = tx.send(Sendable(image));
    });
    // SAFETY: the manager keeps the handler until it was called once.
    unsafe { SCScreenshotManager::captureImageWithFilter_configuration_completionHandler(filter, config, Some(&*handler)) };
    rx.recv_timeout(WAIT).ok().and_then(|s| s.0).ok_or_else(|| "The screenshot did not arrive.".to_string())
}

/// The CoreGraphics image as BGRA rows.
fn frame_of(image: &CGImage) -> Result<Frame, String> {
    let (width, height, stride) = (CGImage::width(Some(image)), CGImage::height(Some(image)), CGImage::bytes_per_row(Some(image)));
    let eight_bit = CGImage::bits_per_pixel(Some(image)) == 32 && CGImage::bits_per_component(Some(image)) == 8;
    let order = pixel_order(CGImage::bitmap_info(Some(image)).0).filter(|_| eight_bit).ok_or("The screenshot has an unexpected pixel format.")?;
    let provider = CGImage::data_provider(Some(image)).ok_or("The screenshot has no pixels.")?;
    let data = CGDataProvider::data(Some(&*provider)).ok_or("The screenshot has no pixels.")?;
    let mut bgra = data.to_vec();
    if width == 0 || height == 0 || stride < width * 4 || bgra.len() < stride * height {
        return Err("The screenshot is empty.".into());
    }
    to_bgra(&mut bgra, order);
    Ok(Frame { width: width as u32, height: height as u32, stride, bgra })
}

impl Grab for Screenshots {
    fn target(&self) -> Target {
        self.target
    }

    fn grab(&mut self) -> Result<Option<Frame>, String> {
        autoreleasepool(|_| {
            let content = last().as_ref().map(|c| c.0.clone()).or_else(shareable).ok_or("ScreenCaptureKit did not answer.")?;
            let filter = self.filter(&content)?;
            // The window's (or display's) size in pixels, brought down to the stored size.
            let info = unsafe { SCShareableContent::infoForFilter(&filter) };
            let rect = unsafe { info.contentRect() };
            let (width, height) = capture_size(rect.size.width, rect.size.height, f64::from(unsafe { info.pointPixelScale() }));
            let config = unsafe { SCStreamConfiguration::new() };
            unsafe {
                config.setWidth(width);
                config.setHeight(height);
                config.setPixelFormat(BGRA);
                config.setShowsCursor(false);
                config.setIgnoreShadowsSingleWindow(true);
            }
            let image = screenshot(&filter, &config)?;
            frame_of(&image).map(Some)
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::detect::classify::SourceApp;
    use crate::screens::platform::MeetingTree;
    use crate::screens::privacy::{DEFAULT_EXCLUDED, MEETING_WINDOW, WHOLE_SCREEN};
    use objc2_core_foundation::{CGPoint, CGSize};

    fn rect(x: f64, y: f64, w: f64, h: f64) -> CGRect {
        CGRect { origin: CGPoint { x, y }, size: CGSize { width: w, height: h } }
    }

    /// The built-in display at the origin, an external one to its right.
    fn displays() -> Vec<(isize, CGRect)> {
        vec![(1, rect(0.0, 0.0, 1512.0, 982.0)), (2, rect(1512.0, 0.0, 2560.0, 1440.0))]
    }

    fn window(id: u32, app_name: &str, bundle: &str, title: &str, frame: CGRect) -> MacWindow {
        MacWindow { id, pid: 500 + id as i32, title: title.into(), app_name: app_name.into(), bundle: bundle.into(), on_screen: true, layer: 0, frame }
    }

    fn zoom() -> MeetingTree {
        MeetingTree { root_pid: 0, app: SourceApp::Zoom, name: "us.zoom.xos".into(), browser: false }
    }

    fn excluded() -> Vec<String> {
        DEFAULT_EXCLUDED.iter().map(|s| s.to_string()).collect()
    }

    fn desktop() -> Vec<MacWindow> {
        vec![
            window(10, "zoom.us", "us.zoom.xos", "Zoom Workplace", rect(100.0, 100.0, 900.0, 600.0)),
            window(11, "zoom.us", "us.zoom.xos", "Zoom Meeting", rect(1600.0, 50.0, 1800.0, 1100.0)),
            window(20, "1Password", "com.1password.1password", "1Password", rect(200.0, 200.0, 700.0, 500.0)),
            window(30, "EchoLedger", "com.echoledger.desktop", "EchoLedger", rect(0.0, 0.0, 400.0, 300.0)),
        ]
    }

    #[test]
    fn what_screencapturekit_says_becomes_the_window_facts() {
        let mut w = window(11, "zoom.us", "us.zoom.xos", "Zoom Meeting", rect(1400.0, 0.0, 400.0, 300.0));
        let sys = sys_window(w.clone(), &displays(), Some("us.zoom.xos"));
        assert!(sys.meeting && sys.facts.showing());
        assert_eq!(sys.displays, vec![1, 2], "a window across the edge is on both displays");
        assert_eq!((sys.facts.process.as_str(), sys.bundle.as_deref(), sys.facts.area), ("zoom.us", Some("us.zoom.xos"), 120_000));
        w.on_screen = false;
        assert!(sys_window(w.clone(), &displays(), Some("us.zoom.xos")).facts.minimised, "minimised, hidden or on another Space");
        w.layer = 3;
        assert!(sys_window(w, &displays(), None).facts.tool, "a floating panel is never the meeting");
        // A browser helper's window belongs to the browser; another bundle does not.
        let helper = window(40, "Google Chrome Helper", "com.google.Chrome.helper", "Meet - abc", rect(0.0, 0.0, 10.0, 10.0));
        assert!(sys_window(helper, &displays(), Some("com.google.Chrome")).meeting);
        let other = window(41, "Safari", "com.apple.Safari", "Meet - abc", rect(0.0, 0.0, 10.0, 10.0));
        assert!(!sys_window(other, &displays(), Some("com.google.Chrome")).meeting);
        let nameless = window(42, "", "", "", rect(0.0, 0.0, 10.0, 10.0));
        assert!(!sys_window(nameless, &displays(), Some("")).meeting, "a window without a bundle is never the meeting's");
    }

    #[test]
    fn the_meeting_window_is_found_and_our_own_windows_are_left_out() {
        let snapshot = snapshot_of(desktop(), &displays(), 1, 530, Some("us.zoom.xos"));
        assert!(snapshot.windows.iter().all(|w| w.id != 30), "EchoLedger's own window is not listed");
        assert_eq!(snapshot.main, Some(1));
        let wanted = Wanted { mode: MEETING_WINDOW, meeting: Some(&zoom()), excluded: &excluded(), site_rules: &[] };
        assert_eq!(choose(&wanted, &snapshot), Ok(Target::Window(11)));
    }

    #[test]
    fn a_minimised_zoom_meeting_pauses_the_capture() {
        let mut windows = desktop();
        windows.iter_mut().filter(|w| w.bundle == "us.zoom.xos").for_each(|w| w.on_screen = false);
        let snapshot = snapshot_of(windows, &displays(), 1, 530, Some("us.zoom.xos"));
        for mode in [MEETING_WINDOW, WHOLE_SCREEN] {
            let wanted = Wanted { mode, meeting: Some(&zoom()), excluded: &excluded(), site_rules: &[] };
            assert_eq!(choose(&wanted, &snapshot), Err(HoldReason::Minimised), "{mode}");
        }
    }

    #[test]
    fn the_whole_display_holds_while_1password_shows_on_it() {
        // The meeting is on the external display, 1Password on the built-in one: the external display is captured.
        let snapshot = snapshot_of(desktop(), &displays(), 1, 530, Some("us.zoom.xos"));
        let wanted = Wanted { mode: WHOLE_SCREEN, meeting: Some(&zoom()), excluded: &excluded(), site_rules: &[] };
        assert_eq!(choose(&wanted, &snapshot), Ok(Target::Display(2)));
        // Moved beside the meeting: held, whether it is known by its name or only by its bundle id.
        let mut windows = desktop();
        windows[2].frame = rect(2000.0, 300.0, 700.0, 500.0);
        assert_eq!(choose(&wanted, &snapshot_of(windows.clone(), &displays(), 1, 530, Some("us.zoom.xos"))), Err(HoldReason::ExcludedApp));
        windows[2].app_name = String::new();
        assert_eq!(choose(&wanted, &snapshot_of(windows.clone(), &displays(), 1, 530, Some("us.zoom.xos"))), Err(HoldReason::ExcludedApp));
        windows[2].on_screen = false;
        assert_eq!(choose(&wanted, &snapshot_of(windows, &displays(), 1, 530, Some("us.zoom.xos"))), Ok(Target::Display(2)));
    }

    #[test]
    fn without_a_known_meeting_the_main_display_or_the_first_is_captured() {
        let wanted = Wanted { mode: WHOLE_SCREEN, meeting: None, excluded: &[], site_rules: &[] };
        assert_eq!(choose(&wanted, &snapshot_of(desktop(), &displays(), 2, 530, None)), Ok(Target::Display(2)));
        assert_eq!(choose(&wanted, &snapshot_of(desktop(), &displays(), 99, 530, None)), Ok(Target::Display(1)), "an unknown main display: the first");
        assert_eq!(choose(&wanted, &snapshot_of(Vec::new(), &[], 1, 530, None)), Err(HoldReason::NoWindow));
    }

    #[test]
    fn screenshots_are_taken_at_the_stored_size_at_most() {
        assert_eq!(capture_size(1512.0, 982.0, 2.0), (1920, 1247), "a Retina display: 3024 px wide, brought down");
        assert_eq!(capture_size(800.0, 600.0, 2.0), (1600, 1200));
        assert_eq!(capture_size(600.0, 1400.0, 2.0), (823, 1920), "portrait: the long side is the height");
        assert_eq!(capture_size(0.0, 0.0, 2.0), (1, 1));
        assert_eq!(capture_size(100.0, 50.0, f64::NAN), (100, 50));
        assert_eq!(BGRA, 0x4247_5241);
    }
}
