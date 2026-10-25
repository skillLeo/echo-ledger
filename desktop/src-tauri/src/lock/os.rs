//! The operating system's own check for the app lock: Windows Hello on Windows, Touch ID (or the Mac password) on
//! macOS. Both block until the person answers, so they run on a blocking thread.

/// Which system unlock this computer offers, for the button label: `windows_hello`, `touch_id` or none.
pub fn method() -> Option<&'static str> {
    if available() {
        Some(if cfg!(target_os = "macos") { "touch_id" } else { "windows_hello" })
    } else {
        None
    }
}

#[cfg(windows)]
pub fn available() -> bool {
    use windows::Security::Credentials::UI::{UserConsentVerifier, UserConsentVerifierAvailability};
    UserConsentVerifier::CheckAvailabilityAsync().and_then(|op| op.join()).map(|a| a == UserConsentVerifierAvailability::Available).unwrap_or(false)
}

/// Asks Windows Hello, with the prompt attached to the main window. `Ok(true)` only when verified.
#[cfg(windows)]
pub fn verify(window: isize, reason: &str) -> Result<bool, String> {
    use windows::Security::Credentials::UI::{UserConsentVerificationResult, UserConsentVerifier};
    use windows::Win32::Foundation::HWND;
    use windows::Win32::System::WinRT::IUserConsentVerifierInterop;
    use windows::core::{HSTRING, factory};
    use windows_future::IAsyncOperation;

    let interop = factory::<UserConsentVerifier, IUserConsentVerifierInterop>().map_err(|e| e.message())?;
    // SAFETY: the window handle comes from the live main window; the call only reads it to parent the prompt.
    let op: IAsyncOperation<UserConsentVerificationResult> =
        unsafe { interop.RequestVerificationForWindowAsync(HWND(window as *mut core::ffi::c_void), &HSTRING::from(reason)) }.map_err(|e| e.message())?;
    Ok(op.join().map_err(|e| e.message())? == UserConsentVerificationResult::Verified)
}

#[cfg(target_os = "macos")]
pub fn available() -> bool {
    use objc2_local_authentication::{LAContext, LAPolicy};
    // SAFETY: a fresh LAContext is only asked whether the policy can be evaluated.
    unsafe { LAContext::new().canEvaluatePolicy_error(LAPolicy::DeviceOwnerAuthentication).is_ok() }
}

/// Asks for Touch ID (or the Mac password). `Ok(true)` only when the owner was verified.
#[cfg(target_os = "macos")]
pub fn verify(_window: isize, reason: &str) -> Result<bool, String> {
    use block2::RcBlock;
    use objc2::runtime::Bool;
    use objc2_foundation::{NSError, NSString};
    use objc2_local_authentication::{LAContext, LAPolicy};
    use std::sync::mpsc;

    let (tx, rx) = mpsc::channel::<bool>();
    let reply = RcBlock::new(move |ok: Bool, _error: *mut NSError| {
        let _ = tx.send(ok.as_bool());
    });
    // SAFETY: the reply block is retained by LAContext until it has been called once.
    unsafe {
        LAContext::new().evaluatePolicy_localizedReason_reply(LAPolicy::DeviceOwnerAuthentication, &NSString::from_str(reason), &reply);
    }
    rx.recv().map_err(|e| e.to_string())
}

#[cfg(not(any(windows, target_os = "macos")))]
pub fn available() -> bool {
    false
}

#[cfg(not(any(windows, target_os = "macos")))]
pub fn verify(_window: isize, _reason: &str) -> Result<bool, String> {
    Ok(false)
}
