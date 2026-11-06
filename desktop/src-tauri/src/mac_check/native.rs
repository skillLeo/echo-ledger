//! The real [`MacProbe`]: macOS's version and model, the microphone and the system-audio tap through the capture backend,
//! the installed apps through NSWorkspace, the overlay windows as AppKit sees them, one screen frame through
//! ScreenCaptureKit. Only "Run Mac check" uses it; nothing it hears or captures is kept. The tests use fakes
//! (`mac_check.rs`), so this part is proved by the manual check on a real Mac (docs/MANUAL_QA.md).

use std::ffi::{c_char, c_int, c_void};
use std::sync::mpsc::{RecvTimeoutError, channel};
use std::time::{Duration, Instant};

use objc2_app_kit::NSWorkspace;
use objc2_foundation::{NSProcessInfo, NSString};
use tauri::{AppHandle, Manager};

use super::{FrameProblem, MacProbe, WindowFacts, tone_wav};
use crate::audio::health::peak_level;
use crate::audio::platform::{self, Streams};
use crate::audio::types::Channel;
use crate::lifecycle::{self, Phase};
use crate::overlay;
use crate::permissions::Permissions;
use crate::screens::platform::{Native, ScreenSystem, Wanted};
use crate::screens::privacy::{HoldReason, WHOLE_SCREEN};

/// What `lifecycle` calls the check's use of a window.
const USE: &str = "mac_check";
/// How long the captions and the pill may take to open.
const WINDOW_WAIT: Duration = Duration::from_secs(5);

unsafe extern "C" {
    fn sysctlbyname(name: *const c_char, old: *mut c_void, old_len: *mut usize, new: *mut c_void, new_len: usize) -> c_int;
}

/// `hw.model`, such as "Mac14,2".
pub fn model() -> String {
    let mut buffer = [0_u8; 64];
    let mut length = buffer.len();
    // SAFETY: the buffer and its length are valid for the call, which writes at most that many bytes.
    let status = unsafe { sysctlbyname(c"hw.model".as_ptr(), buffer.as_mut_ptr().cast(), &mut length, std::ptr::null_mut(), 0) };
    if status != 0 {
        return String::new();
    }
    String::from_utf8_lossy(&buffer[..length]).trim_end_matches('\0').to_string()
}

/// (major, minor, patch) of this macOS.
pub fn version() -> (u32, u32, u32) {
    let v = NSProcessInfo::processInfo().operatingSystemVersion();
    (v.majorVersion as u32, v.minorVersion as u32, v.patchVersion as u32)
}

/// "15.1" or "15.1.2".
pub fn version_string() -> String {
    let (major, minor, patch) = version();
    if patch == 0 { format!("{major}.{minor}") } else { format!("{major}.{minor}.{patch}") }
}

/// Runs a capture for `seconds` and returns the samples of `wanted`.
fn listen(streams: Streams, wanted: Channel, seconds: u32, meanwhile: impl FnOnce()) -> Result<Vec<f32>, String> {
    let (tx, rx) = channel();
    let mut backend = platform::start(streams, tx).map_err(|e| e.to_string())?;
    meanwhile();
    let until = Instant::now() + Duration::from_secs(u64::from(seconds));
    let mut samples = Vec::new();
    while Instant::now() < until {
        match rx.recv_timeout(Duration::from_millis(50)) {
            Ok(chunk) if chunk.channel == wanted => samples.extend_from_slice(&chunk.samples),
            Ok(_) | Err(RecvTimeoutError::Timeout) => {}
            Err(RecvTimeoutError::Disconnected) => break,
        }
    }
    backend.stop();
    Ok(samples)
}

pub struct NativeProbe {
    pub app: AppHandle,
}

impl MacProbe for NativeProbe {
    fn os_version(&self) -> Option<(u32, u32, u32)> {
        Some(version())
    }

    fn permissions(&self) -> Permissions {
        crate::permissions::read()
    }

    fn listen_to_microphone(&self, seconds: u32) -> Result<f32, String> {
        listen(Streams { mic: true, system: false }, Channel::Mic, seconds, || {}).map(|samples| peak_level(&samples))
    }

    /// The tone is played by the system's own player: the tap leaves EchoLedger out, so the app could not hear itself.
    fn tap_while_tone_plays(&self, seconds: u32) -> Result<Vec<f32>, String> {
        let path = std::env::temp_dir().join(format!("echoledger-tone-{}.wav", std::process::id()));
        std::fs::write(&path, tone_wav()?).map_err(|e| e.to_string())?;
        let mut player = None;
        let samples = listen(Streams { mic: false, system: true }, Channel::System, seconds, || {
            std::thread::sleep(Duration::from_millis(300)); // the tap settles first
            player = std::process::Command::new("/usr/bin/afplay").arg(&path).spawn().ok();
        });
        if let Some(mut player) = player {
            let _ = player.kill();
            let _ = player.wait();
        }
        let _ = std::fs::remove_file(&path);
        samples
    }

    fn is_installed(&self, bundle_id: &str) -> bool {
        let id = NSString::from_str(bundle_id);
        NSWorkspace::sharedWorkspace().URLForApplicationWithBundleIdentifier(&id).is_some()
    }

    fn overlay_windows(&self) -> Vec<WindowFacts> {
        const LABELS: [&str; 2] = [overlay::OVERLAY, overlay::PILL];
        for label in LABELS {
            lifecycle::show(&self.app, label, USE);
        }
        let until = Instant::now() + WINDOW_WAIT;
        while Instant::now() < until && lifecycle::phases(&self.app, &LABELS).iter().any(|(_, phase)| *phase != Phase::Ready) {
            std::thread::sleep(Duration::from_millis(100));
        }
        let facts = LABELS.map(|label| {
            self.app
                .get_webview_window(label)
                .and_then(|window| overlay::macos::window_facts(&window, label))
                .unwrap_or(WindowFacts { label, exists: false, visible: false, on_screen: false, level_ok: false, spaces_ok: false })
        });
        for label in LABELS {
            lifecycle::hide(&self.app, label, USE);
        }
        facts.to_vec()
    }

    fn capture_one_frame(&self) -> Result<(), FrameProblem> {
        let wanted = Wanted { mode: WHOLE_SCREEN, meeting: None, excluded: &[], site_rules: &[] };
        let target = Native.find_target(&wanted).map_err(|reason| match reason {
            HoldReason::Permission => FrameProblem::Permission,
            other => FrameProblem::Other(format!("{other:?}")),
        })?;
        let mut capture = Native.open(target).map_err(FrameProblem::Other)?;
        // The frame is dropped here: nothing is written anywhere.
        match capture.grab().map_err(FrameProblem::Other)? {
            Some(_frame) => Ok(()),
            None => Err(FrameProblem::Other("The screen did not answer.".into())),
        }
    }
}
