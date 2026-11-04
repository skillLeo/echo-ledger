//! Screen capture backends: Windows.Graphics.Capture on Windows, ScreenCaptureKit on macOS. Each finds the window or
//! screen to capture with the privacy rules of `privacy` and hands the capture thread the newest frame as a CPU
//! bitmap, once a second at most.

use crate::detect::classify::SourceApp;
use crate::detect::sites::SiteRule;

use super::privacy::HoldReason;

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

/// Prepares the calling thread for capture calls (WinRT on Windows).
pub fn thread_init() {
    #[cfg(windows)]
    windows::thread_init();
}

/// This system can capture windows and screens.
pub fn supported() -> bool {
    #[cfg(windows)]
    return windows::supported();
    #[cfg(target_os = "macos")]
    return macos::supported();
    #[cfg(not(any(windows, target_os = "macos")))]
    false
}

/// The capture can be asked to draw no border around what it captures (Windows 11; macOS shows its own indicator).
pub fn borderless() -> bool {
    #[cfg(windows)]
    return windows::borderless_supported();
    #[cfg(not(windows))]
    true
}

/// EchoLedger may capture the screen (macOS Screen Recording; always on Windows).
pub fn permission() -> bool {
    #[cfg(target_os = "macos")]
    return macos::permission();
    #[cfg(not(target_os = "macos"))]
    true
}

/// Asks the system for the screen capture permission (macOS shows its prompt once, then only System Settings can
/// change it). True when it is granted now.
pub fn request_permission() -> bool {
    #[cfg(target_os = "macos")]
    return macos::request_permission();
    #[cfg(not(target_os = "macos"))]
    true
}

/// The window or screen to capture now, or why the capture holds.
pub fn find_target(wanted: &Wanted<'_>) -> Result<Target, HoldReason> {
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

/// Opens a capture of `target`.
pub fn open(target: Target) -> Result<Box<dyn Grab>, String> {
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
