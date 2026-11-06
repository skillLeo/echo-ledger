//! Screen capture backends: Windows.Graphics.Capture on Windows, ScreenCaptureKit on macOS, behind [`ScreenSystem`].
//! Each lists the windows and screens of the moment, lets [`choose`] pick what to capture with the privacy rules of
//! `privacy`, and hands the capture thread the newest frame as a CPU bitmap, once a second at most.

use crate::detect::classify::SourceApp;
use crate::detect::sites::SiteRule;

use super::privacy::{HoldReason, Pick, WHOLE_SCREEN, WindowFacts, is_excluded, pick_meeting_window};

#[cfg(target_os = "macos")]
pub mod macos;
#[cfg(windows)]
pub mod windows;

/// One frame in system memory: BGRA, rows `stride` bytes apart.
pub struct Frame {
    pub width: u32,
    pub height: u32,
    pub stride: usize,
    pub bgra: Vec<u8>,
}

/// How a 32-bit pixel's bytes lie in memory (a CoreGraphics image says so in its bitmap info).
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum PixelOrder {
    Bgra,
    Argb,
    Rgba,
}

/// The byte order of a 32-bit CoreGraphics bitmap: 32-bit little-endian with alpha first is BGRA in memory (what
/// ScreenCaptureKit hands over), big-endian or the default order is ARGB with alpha first, RGBA with alpha last.
/// `None` for layouts the capture does not read.
pub fn pixel_order(bitmap_info: u32) -> Option<PixelOrder> {
    const ALPHA_MASK: u32 = 0x1F;
    const ORDER_MASK: u32 = 0x7000;
    const ORDER_32_LITTLE: u32 = 2 << 12;
    const ORDER_32_BIG: u32 = 4 << 12;
    // kCGImageAlpha… PremultipliedLast 1, PremultipliedFirst 2, Last 3, First 4, NoneSkipLast 5, NoneSkipFirst 6.
    let first = matches!(bitmap_info & ALPHA_MASK, 2 | 4 | 6);
    let last = matches!(bitmap_info & ALPHA_MASK, 1 | 3 | 5);
    match (bitmap_info & ORDER_MASK, first, last) {
        (ORDER_32_LITTLE, true, _) => Some(PixelOrder::Bgra),
        (0 | ORDER_32_BIG, true, _) => Some(PixelOrder::Argb),
        (0 | ORDER_32_BIG, _, true) => Some(PixelOrder::Rgba),
        _ => None,
    }
}

/// Rewrites 32-bit pixels of `order` in place as BGRA.
pub fn to_bgra(bytes: &mut [u8], order: PixelOrder) {
    for px in bytes.as_chunks_mut::<4>().0 {
        *px = match order {
            PixelOrder::Bgra => *px,
            PixelOrder::Argb => [px[3], px[2], px[1], px[0]],
            PixelOrder::Rgba => [px[2], px[1], px[0], px[3]],
        };
    }
}

/// What is being captured: a window or a whole screen (Windows `HWND` / `HMONITOR`, macOS window / display id).
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Target {
    Window(isize),
    Display(isize),
}

/// The meeting app's process tree, as auto mode sees it.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct MeetingTree {
    pub root_pid: u32,
    pub app: SourceApp,
    /// The tree's process image name (Windows) or bundle id (macOS).
    pub name: String,
    /// A browser tree: only windows whose title shows the meeting's site count.
    pub browser: bool,
}

/// What the capture is told to look at each second.
pub struct Wanted<'a> {
    /// `meeting_window` or `whole_screen`.
    pub mode: &'a str,
    pub meeting: Option<&'a MeetingTree>,
    pub excluded: &'a [String],
    pub site_rules: &'a [SiteRule],
}

/// An open capture: the newest frame of its target on demand.
pub trait Grab {
    fn target(&self) -> Target;
    /// The newest frame since the capture opened; `None` until the system delivered one.
    fn grab(&mut self) -> Result<Option<Frame>, String>;
}

/// The system's screen capture. The capture thread reaches Windows.Graphics.Capture and ScreenCaptureKit only through
/// this, so tests drive it with a stand-in and never touch the real capture (nor ask for Screen Recording).
pub trait ScreenSystem: Send + Sync {
    /// Prepares the calling thread for capture calls (WinRT on Windows).
    fn thread_init(&self) {}
    /// This system can capture windows and screens.
    fn supported(&self) -> bool;
    /// The capture can be asked to draw no border around what it captures (Windows 11; macOS shows its own indicator).
    fn borderless(&self) -> bool;
    /// EchoLedger may capture the screen (macOS Screen Recording; always on Windows).
    fn permission(&self) -> bool;
    /// Asks the system for the screen capture permission (macOS shows its prompt once, then only System Settings can
    /// change it). True when it is granted now.
    fn request_permission(&self) -> bool;
    /// The window or screen to capture now, or why the capture holds.
    fn find_target(&self, wanted: &Wanted<'_>) -> Result<Target, HoldReason>;
    /// Opens a capture of `target`.
    fn open(&self, target: Target) -> Result<Box<dyn Grab>, String>;
}

/// The capture of this computer: Windows.Graphics.Capture on Windows, ScreenCaptureKit on macOS, none elsewhere.
pub struct Native;

impl ScreenSystem for Native {
    fn thread_init(&self) {
        #[cfg(windows)]
        windows::thread_init();
    }

    fn supported(&self) -> bool {
        #[cfg(windows)]
        return windows::supported();
        #[cfg(target_os = "macos")]
        return macos::supported();
        #[cfg(not(any(windows, target_os = "macos")))]
        false
    }

    fn borderless(&self) -> bool {
        #[cfg(windows)]
        return windows::borderless_supported();
        #[cfg(not(windows))]
        true
    }

    fn permission(&self) -> bool {
        #[cfg(target_os = "macos")]
        return macos::permission();
        #[cfg(not(target_os = "macos"))]
        true
    }

    fn request_permission(&self) -> bool {
        #[cfg(target_os = "macos")]
        return macos::request_permission();
        #[cfg(not(target_os = "macos"))]
        true
    }

    fn find_target(&self, wanted: &Wanted<'_>) -> Result<Target, HoldReason> {
        #[cfg(windows)]
        return windows::find_target(wanted);
        #[cfg(target_os = "macos")]
        return macos::find_target(wanted);
        #[cfg(not(any(windows, target_os = "macos")))]
        {
            let _ = wanted;
            Err(HoldReason::NoWindow)
        }
    }

    fn open(&self, target: Target) -> Result<Box<dyn Grab>, String> {
        #[cfg(windows)]
        return windows::open(target);
        #[cfg(target_os = "macos")]
        return macos::open(target);
        #[cfg(not(any(windows, target_os = "macos")))]
        {
            let _ = target;
            Err("Screen capture is not available on this system.".into())
        }
    }
}

/// One window of another app, as the system listed it this second.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct SysWindow {
    /// `HWND` (Windows) or window id (macOS).
    pub id: isize,
    /// It belongs to the meeting app: a process of its tree (Windows), a window of its bundle (macOS).
    pub meeting: bool,
    /// The screens it is on: every monitor (Windows) or display (macOS) its frame meets; on Windows the one it mostly
    /// sits on comes first.
    pub displays: Vec<isize>,
    /// The app's bundle id (macOS), compared with the excluded apps beside its name.
    pub bundle: Option<String>,
    pub facts: WindowFacts,
}

impl SysWindow {
    fn excluded(&self, excluded: &[String]) -> bool {
        is_excluded(&self.facts.process, excluded) || self.bundle.as_deref().is_some_and(|b| is_excluded(b, excluded))
    }
}

/// What the system shows this second: the windows of other apps and the main screen.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct Snapshot {
    pub windows: Vec<SysWindow>,
    /// The primary monitor (Windows) or the display with the menu bar (macOS).
    pub main: Option<isize>,
}

/// A rectangle in screen pixels, as Windows gives a window's frame and a monitor (right and bottom exclusive).
#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub struct Bounds {
    pub left: i32,
    pub top: i32,
    pub right: i32,
    pub bottom: i32,
}

impl Bounds {
    /// The two share at least one pixel (touching edges do not).
    fn meets(&self, other: &Bounds) -> bool {
        self.left < other.right && other.left < self.right && self.top < other.bottom && other.top < self.bottom
    }
}

/// The monitors a window shows on (pure): `nearest`, the one it mostly sits on (whose screen a whole-screen capture of
/// the meeting takes), first, then every other monitor its frame meets, so a vault dragged across two monitors counts
/// on both.
pub fn displays_of(frame: Bounds, nearest: isize, monitors: &[(isize, Bounds)]) -> Vec<isize> {
    let mut out = vec![nearest];
    out.extend(monitors.iter().filter(|(id, m)| *id != nearest && m.meets(&frame)).map(|(id, _)| *id));
    out
}

/// The window or screen to capture, from what the system shows (pure; both backends decide with it). An excluded
/// meeting app is never captured, whether the list names its process tree (an image name or a bundle id) or one of its
/// windows' app names (macOS: "zoom.us", "Google Chrome"). The meeting window: the one [`pick_meeting_window`] picks,
/// and nothing while it is minimised or hidden. The whole screen: the screen the meeting window is on, held as well
/// while a known meeting's window is minimised or hidden (the screen would show whatever the person turned to
/// instead), the main screen when no meeting is known (a recording started by hand); any excluded app showing on that
/// screen holds the capture.
pub fn choose(wanted: &Wanted<'_>, snapshot: &Snapshot) -> Result<Target, HoldReason> {
    let meeting_excluded = |m: &MeetingTree| is_excluded(&m.name, wanted.excluded) || snapshot.windows.iter().any(|w| w.meeting && w.excluded(wanted.excluded));
    if wanted.meeting.is_some_and(meeting_excluded) {
        return Err(HoldReason::ExcludedApp);
    }
    let meeting_window = match wanted.meeting {
        None => None,
        Some(m) => {
            let tree: Vec<&SysWindow> = snapshot.windows.iter().filter(|w| w.meeting).collect();
            let facts: Vec<WindowFacts> = tree.iter().map(|w| w.facts.clone()).collect();
            match pick_meeting_window(m.app, m.browser, &facts, wanted.site_rules) {
                Pick::Window(i) => Some(tree[i]),
                Pick::Hold(reason) => return Err(reason),
            }
        }
    };
    if wanted.mode != WHOLE_SCREEN {
        return meeting_window.map(|w| Target::Window(w.id)).ok_or(HoldReason::NoWindow);
    }
    let display = meeting_window.and_then(|w| w.displays.first().copied()).or(snapshot.main).ok_or(HoldReason::NoWindow)?;
    if snapshot.windows.iter().any(|w| w.facts.showing() && w.displays.contains(&display) && w.excluded(wanted.excluded)) {
        return Err(HoldReason::ExcludedApp);
    }
    Ok(Target::Display(display))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn coregraphics_layouts_are_read_as_bgra() {
        // ScreenCaptureKit: 32-bit little-endian, premultiplied alpha first.
        assert_eq!(pixel_order((2 << 12) | 2), Some(PixelOrder::Bgra));
        assert_eq!(pixel_order((2 << 12) | 6), Some(PixelOrder::Bgra));
        assert_eq!(pixel_order((4 << 12) | 2), Some(PixelOrder::Argb));
        assert_eq!(pixel_order(1), Some(PixelOrder::Rgba));
        assert_eq!(pixel_order((4 << 12) | 5), Some(PixelOrder::Rgba));
        assert_eq!(pixel_order((2 << 12) | 1), None, "little-endian alpha last is ABGR: not read");
        assert_eq!(pixel_order((1 << 12) | 2), None, "16-bit orders are not read");
        let mut argb = vec![255, 10, 20, 30];
        to_bgra(&mut argb, PixelOrder::Argb);
        assert_eq!(argb, [30, 20, 10, 255]);
        let mut rgba = vec![10, 20, 30, 255, 1, 2, 3, 4];
        to_bgra(&mut rgba, PixelOrder::Rgba);
        assert_eq!(rgba, [30, 20, 10, 255, 3, 2, 1, 4]);
    }

    // ---- choose: snapshots shaped like each system's ------------------------------------------------------------

    use crate::screens::privacy::{DEFAULT_EXCLUDED, MEETING_WINDOW};

    fn excluded() -> Vec<String> {
        DEFAULT_EXCLUDED.iter().map(|s| s.to_string()).collect()
    }

    fn win(id: isize, process: &str, title: &str, meeting: bool, display: isize) -> SysWindow {
        SysWindow { id, meeting, displays: vec![display], bundle: None, facts: WindowFacts { title: title.into(), process: process.into(), visible: true, area: 1_000_000, ..Default::default() } }
    }

    fn tree(app: SourceApp, name: &str, browser: bool) -> MeetingTree {
        MeetingTree { root_pid: 10, app, name: name.into(), browser }
    }

    fn wanted<'a>(mode: &'a str, meeting: Option<&'a MeetingTree>, excluded: &'a [String]) -> Wanted<'a> {
        Wanted { mode, meeting, excluded, site_rules: &[] }
    }

    /// Windows: Zoom's meeting window and its home window on monitor 1, Bitwarden on monitor 2.
    fn windows_desktop() -> Snapshot {
        Snapshot {
            windows: vec![win(100, "Zoom.exe", "Zoom Workplace", true, 1), win(101, "Zoom.exe", "Zoom Meeting", true, 1), win(200, "Bitwarden.exe", "Bitwarden", false, 2), win(300, "chrome.exe", "Inbox - Gmail - Google Chrome", false, 1)],
            main: Some(1),
        }
    }

    #[test]
    fn the_meeting_window_is_captured_by_default() {
        let zoom = tree(SourceApp::Zoom, "Zoom.exe", false);
        assert_eq!(choose(&wanted(MEETING_WINDOW, Some(&zoom), &excluded()), &windows_desktop()), Ok(Target::Window(101)));
        // No meeting known (a recording started by hand): no window to capture.
        assert_eq!(choose(&wanted(MEETING_WINDOW, None, &excluded()), &windows_desktop()), Err(HoldReason::NoWindow));
    }

    #[test]
    fn a_minimised_or_hidden_meeting_window_pauses_both_modes() {
        let zoom = tree(SourceApp::Zoom, "Zoom.exe", false);
        let mut desktop = windows_desktop();
        for w in desktop.windows.iter_mut().filter(|w| w.meeting) {
            w.facts.minimised = true;
        }
        assert_eq!(choose(&wanted(MEETING_WINDOW, Some(&zoom), &excluded()), &desktop), Err(HoldReason::Minimised));
        assert_eq!(choose(&wanted(WHOLE_SCREEN, Some(&zoom), &excluded()), &desktop), Err(HoldReason::Minimised), "the screen would show what the person turned to");
        for w in desktop.windows.iter_mut().filter(|w| w.meeting) {
            w.facts.minimised = false;
            w.facts.cloaked = true;
        }
        assert_eq!(choose(&wanted(MEETING_WINDOW, Some(&zoom), &excluded()), &desktop), Err(HoldReason::Minimised), "another virtual desktop");
    }

    #[test]
    fn the_whole_screen_is_the_one_the_meeting_is_on_unless_an_excluded_app_shows_there() {
        let zoom = tree(SourceApp::Zoom, "Zoom.exe", false);
        assert_eq!(choose(&wanted(WHOLE_SCREEN, Some(&zoom), &excluded()), &windows_desktop()), Ok(Target::Display(1)), "Bitwarden is on the other screen");
        let mut desktop = windows_desktop();
        desktop.windows[2].displays = vec![1];
        assert_eq!(choose(&wanted(WHOLE_SCREEN, Some(&zoom), &excluded()), &desktop), Err(HoldReason::ExcludedApp));
        desktop.windows[2].facts.minimised = true;
        assert_eq!(choose(&wanted(WHOLE_SCREEN, Some(&zoom), &excluded()), &desktop), Ok(Target::Display(1)), "a minimised vault shows nothing");
        // The meeting window mode never shows other windows, so a vault beside it does not matter.
        desktop.windows[2].facts.minimised = false;
        assert_eq!(choose(&wanted(MEETING_WINDOW, Some(&zoom), &excluded()), &desktop), Ok(Target::Window(101)));
        // No meeting known: the main screen.
        assert_eq!(choose(&wanted(WHOLE_SCREEN, None, &excluded()), &windows_desktop()), Ok(Target::Display(1)));
        assert_eq!(choose(&wanted(WHOLE_SCREEN, None, &excluded()), &Snapshot::default()), Err(HoldReason::NoWindow));
    }

    #[test]
    fn an_excluded_meeting_app_is_never_captured() {
        let zoom = tree(SourceApp::Zoom, "Zoom.exe", false);
        let list = vec!["zoom".to_string()];
        assert_eq!(choose(&wanted(MEETING_WINDOW, Some(&zoom), &list), &windows_desktop()), Err(HoldReason::ExcludedApp));
        assert_eq!(choose(&wanted(WHOLE_SCREEN, Some(&zoom), &list), &windows_desktop()), Err(HoldReason::ExcludedApp));
    }

    #[test]
    fn a_vault_across_two_monitors_counts_on_both() {
        let left = Bounds { left: 0, top: 0, right: 1920, bottom: 1080 };
        let right = Bounds { left: 1920, top: 0, right: 4480, bottom: 1440 };
        let monitors = [(1, left), (2, right)];
        // Mostly on monitor 2 (its nearest), a strip on monitor 1.
        let across = Bounds { left: 1800, top: 100, right: 2600, bottom: 700 };
        assert_eq!(displays_of(across, 2, &monitors), vec![2, 1]);
        // Snapped to the right edge of monitor 1: touching monitor 2 is not being on it.
        let snapped = Bounds { left: 960, top: 0, right: 1920, bottom: 1080 };
        assert_eq!(displays_of(snapped, 1, &monitors), vec![1]);
        // Off every monitor (a monitor just unplugged): the one Windows calls nearest.
        assert_eq!(displays_of(Bounds { left: -5000, top: 0, right: -4000, bottom: 500 }, 1, &monitors), vec![1]);

        let zoom = tree(SourceApp::Zoom, "Zoom.exe", false);
        let mut desktop = windows_desktop();
        desktop.windows[2].displays = displays_of(across, 2, &monitors);
        assert_eq!(choose(&wanted(WHOLE_SCREEN, Some(&zoom), &excluded()), &desktop), Err(HoldReason::ExcludedApp), "its strip shows on the meeting's monitor");
        desktop.windows[2].displays = displays_of(Bounds { left: 2000, ..across }, 2, &monitors);
        assert_eq!(choose(&wanted(WHOLE_SCREEN, Some(&zoom), &excluded()), &desktop), Ok(Target::Display(1)), "moved fully onto monitor 2");
    }

    #[test]
    fn an_excluded_meeting_app_is_known_by_its_windows_app_name_too() {
        // macOS names the meeting app's tree by its bundle id; the person types the app's name.
        let mut zoom_window = win(11, "zoom.us", "Zoom Meeting", true, 1);
        zoom_window.bundle = Some("us.zoom.xos".into());
        let desktop = Snapshot { windows: vec![zoom_window], main: Some(1) };
        let zoom = tree(SourceApp::Zoom, "us.zoom.xos", false);
        for (mode, target) in [(MEETING_WINDOW, Target::Window(11)), (WHOLE_SCREEN, Target::Display(1))] {
            assert_eq!(choose(&wanted(mode, Some(&zoom), &excluded()), &desktop), Ok(target));
            assert_eq!(choose(&wanted(mode, Some(&zoom), &["zoom.us".to_string()]), &desktop), Err(HoldReason::ExcludedApp), "{mode}");
        }
        let mut chrome = win(12, "Google Chrome", "Meet - abc-defg-hij", true, 1);
        chrome.bundle = Some("com.google.Chrome".into());
        let meet = tree(SourceApp::GoogleMeet, "com.google.Chrome", true);
        let desktop = Snapshot { windows: vec![chrome], main: Some(1) };
        assert_eq!(choose(&wanted(MEETING_WINDOW, Some(&meet), &excluded()), &desktop), Ok(Target::Window(12)));
        assert_eq!(choose(&wanted(MEETING_WINDOW, Some(&meet), &["Google Chrome".to_string()]), &desktop), Err(HoldReason::ExcludedApp));
        // An excluded app that is not the meeting's leaves the meeting window alone.
        assert_eq!(choose(&wanted(MEETING_WINDOW, Some(&meet), &["Safari".to_string()]), &desktop), Ok(Target::Window(12)));
    }

    /// macOS: a Meet tab in Safari, 1Password (by its bundle id) and Teams, over two displays.
    fn mac_desktop() -> Snapshot {
        let mut safari = win(11, "Safari", "Meet - abc-defg-hij", true, 1);
        safari.bundle = Some("com.apple.Safari".into());
        let mut other_tab = win(12, "Safari", "Inbox (3) - Gmail", true, 1);
        other_tab.bundle = Some("com.apple.Safari".into());
        let mut vault = win(21, "1Password", "1Password", false, 2);
        vault.bundle = Some("com.1password.1password".into());
        let mut teams = win(31, "Microsoft Teams", "Chat | Microsoft Teams", false, 2);
        teams.bundle = Some("com.microsoft.teams2".into());
        Snapshot { windows: vec![safari, other_tab, vault, teams], main: Some(1) }
    }

    #[test]
    fn a_meet_tab_is_captured_only_while_it_is_the_open_tab() {
        let meet = tree(SourceApp::GoogleMeet, "com.apple.Safari", true);
        assert_eq!(choose(&wanted(MEETING_WINDOW, Some(&meet), &excluded()), &mac_desktop()), Ok(Target::Window(11)));
        let mut desktop = mac_desktop();
        desktop.windows[0].facts.title = "Meeting notes - Google Docs".into();
        assert_eq!(choose(&wanted(MEETING_WINDOW, Some(&meet), &excluded()), &desktop), Err(HoldReason::NoWindow), "another tab is open: the Meet tab is hidden");
        assert_eq!(choose(&wanted(WHOLE_SCREEN, Some(&meet), &excluded()), &desktop), Err(HoldReason::NoWindow));
    }

    #[test]
    fn an_excluded_app_is_known_by_its_bundle_id_too() {
        let meet = tree(SourceApp::GoogleMeet, "com.apple.Safari", true);
        let mut desktop = mac_desktop();
        desktop.windows[2].facts.process = String::new();
        // The vault spans both displays now (it was dragged across).
        desktop.windows[2].displays = vec![2, 1];
        assert_eq!(choose(&wanted(WHOLE_SCREEN, Some(&meet), &excluded()), &desktop), Err(HoldReason::ExcludedApp));
        desktop.windows[2].displays = vec![2];
        assert_eq!(choose(&wanted(WHOLE_SCREEN, Some(&meet), &excluded()), &desktop), Ok(Target::Display(1)));
    }
}
