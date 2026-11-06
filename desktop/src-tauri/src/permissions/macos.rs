//! What macOS allows, read without showing anything. The microphone through AVFoundation; Screen Recording through
//! CoreGraphics (`screens::platform::macos`). System audio has no public way to ask (`NSAudioCaptureUsageDescription`
//! only names the prompt), and CoreGraphics cannot tell "not asked yet" from "told no", so both read the private TCC call
//! that AudioCap uses, looked up at run time: if it ever goes, the answer is "unknown" and the recording's far-side
//! warning and the screens chip still say when something is missing.

use std::ffi::{CStr, c_char, c_int, c_void};
use std::sync::OnceLock;

use block2::RcBlock;
use objc2::msg_send;
use objc2::runtime::{AnyClass, Bool};
use objc2_core_foundation::CFString;
use objc2_foundation::NSString;

use super::{Access, Kind, Permissions};

#[link(name = "AVFoundation", kind = "framework")]
unsafe extern "C" {
    static AVMediaTypeAudio: &'static NSString;
}

unsafe extern "C" {
    fn dlopen(path: *const c_char, mode: c_int) -> *mut c_void;
    fn dlsym(handle: *mut c_void, symbol: *const c_char) -> *mut c_void;
}

const RTLD_LAZY: c_int = 1;
const TCC: &CStr = c"/System/Library/PrivateFrameworks/TCC.framework/Versions/A/TCC";

/// `AVAuthorizationStatus` as the app reads it: 3 authorized, 1 restricted, 2 denied, 0 not asked yet.
pub fn access_of_av_status(status: isize) -> Access {
    match status {
        3 => Access::Allowed,
        1 | 2 => Access::Denied,
        _ => Access::Unknown,
    }
}

/// `TCCAccessPreflight`'s answer: 0 granted, 1 denied, anything else not asked yet.
pub fn access_of_tcc_answer(answer: c_int) -> Access {
    match answer {
        0 => Access::Allowed,
        1 => Access::Denied,
        _ => Access::Unknown,
    }
}

fn microphone() -> Access {
    let Some(class) = AnyClass::get(c"AVCaptureDevice") else { return Access::Unknown };
    // SAFETY: a class method taking the media type and returning `AVAuthorizationStatus` (NSInteger); it shows nothing.
    let status: isize = unsafe { msg_send![class, authorizationStatusForMediaType: AVMediaTypeAudio] };
    access_of_av_status(status)
}

type Preflight = unsafe extern "C" fn(*const CFString, *const c_void) -> c_int;

/// `TCCAccessPreflight`, looked up once; none when this macOS has no such call.
fn preflight() -> Option<Preflight> {
    static CALL: OnceLock<Option<usize>> = OnceLock::new();
    let address = *CALL.get_or_init(|| {
        // SAFETY: looks up a symbol by name in a system framework; a missing one is a null pointer.
        unsafe {
            let handle = dlopen(TCC.as_ptr(), RTLD_LAZY);
            if handle.is_null() {
                return None;
            }
            let symbol = dlsym(handle, c"TCCAccessPreflight".as_ptr());
            (!symbol.is_null()).then_some(symbol as usize)
        }
    });
    // SAFETY: the symbol has this signature (service, options) → status.
    address.map(|a| unsafe { std::mem::transmute::<usize, Preflight>(a) })
}

fn tcc(service: &str) -> Access {
    let Some(call) = preflight() else { return Access::Unknown };
    let service = CFString::from_str(service);
    let service: &CFString = &service;
    // SAFETY: the service name lives for the call; no options; this only reads the answer, it never prompts.
    access_of_tcc_answer(unsafe { call(service, std::ptr::null()) })
}

/// Screen Recording: CoreGraphics' yes is final; its no is told apart by TCC.
pub fn access_of_screen(preflight: bool, tcc: Access) -> Access {
    if preflight {
        Access::Allowed
    } else if tcc == Access::Denied {
        Access::Denied
    } else {
        Access::Unknown
    }
}

pub fn read() -> Permissions {
    Permissions {
        microphone: microphone(),
        system_audio: tcc("kTCCServiceAudioCapture"),
        screen: access_of_screen(crate::screens::platform::macos::permission(), tcc("kTCCServiceScreenCapture")),
    }
}

/// Shows the system's prompt for a permission it has not asked about yet.
pub fn request(kind: Kind) {
    match kind {
        Kind::Microphone => {
            let Some(class) = AnyClass::get(c"AVCaptureDevice") else { return };
            let done = RcBlock::new(|_granted: Bool| {});
            // SAFETY: a class method that keeps the handler until it has answered.
            let () = unsafe { msg_send![class, requestAccessForMediaType: AVMediaTypeAudio, completionHandler: &*done] };
        }
        Kind::SystemAudio => {
            // There is no asking without doing: the first tap to start shows the prompt.
            let (tx, rx) = std::sync::mpsc::channel();
            if let Ok(mut backend) = crate::audio::platform::start(crate::audio::platform::Streams { mic: false, system: true }, tx) {
                std::thread::sleep(std::time::Duration::from_millis(500));
                backend.stop();
            }
            drop(rx);
        }
        Kind::Screen => {
            crate::screens::platform::macos::request_permission();
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn what_the_system_answers_becomes_the_three_states() {
        assert_eq!(access_of_av_status(3), Access::Allowed);
        assert_eq!(access_of_av_status(2), Access::Denied);
        assert_eq!(access_of_av_status(1), Access::Denied, "restricted by a profile: still off");
        assert_eq!(access_of_av_status(0), Access::Unknown);
        assert_eq!(access_of_tcc_answer(0), Access::Allowed);
        assert_eq!(access_of_tcc_answer(1), Access::Denied);
        assert_eq!(access_of_tcc_answer(2), Access::Unknown);
    }

    #[test]
    fn screen_recording_not_asked_yet_is_not_told_no() {
        assert_eq!(access_of_screen(true, Access::Unknown), Access::Allowed);
        assert_eq!(access_of_screen(true, Access::Denied), Access::Allowed, "CoreGraphics' yes is what the capture uses");
        assert_eq!(access_of_screen(false, Access::Denied), Access::Denied);
        assert_eq!(access_of_screen(false, Access::Unknown), Access::Unknown, "so Allow can put EchoLedger on System Settings' list");
        assert_eq!(access_of_screen(false, Access::Allowed), Access::Unknown, "TCC says yes, the capture says no: ask again");
    }
}
