//! Send to WhatsApp → Text and voice (F6): the voice goes to WhatsApp with the text, without the person finding the
//! file in Downloads. The audio is saved in Downloads first (a stable place), then handed to the system's own share:
//! - macOS: WhatsApp's share extension (`NSSharingService`) when its WhatsApp has one (`mac`);
//! - Windows: the Share window, where WhatsApp is a target (`DataTransferManager`, `win`).
//!
//! When neither can (WhatsApp not installed, no share service for it, another system, any error), the window falls back to
//! what it did before (wa.me with the text) and puts the audio file on the clipboard, so ⌘V / Ctrl+V in the chat
//! attaches it. The clipboard then holds the file, not the text: wa.me already puts the text in the message. When the
//! text had to be cut to fit the address, the window says so and offers to copy the whole text after the file.
//!
//! The choices are small pure functions below, tested here; the native calls stay thin.

use std::time::Duration;

use serde::Serialize;

#[cfg(target_os = "macos")]
mod mac;
#[cfg(windows)]
mod win;

/// What the window is told once Text and voice is done.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
#[serde(tag = "kind", rename_all = "camelCase", rename_all_fields = "camelCase")]
pub enum ShareOutcome {
    /// The system's share took the text and the voice (the person chose a chat there).
    Shared,
    /// wa.me opened with the text; the voice is saved at `path` and, when `file_on_clipboard`, on the clipboard to
    /// paste with `paste_keys`. `text_cut`: the text was too long for the address and only its start is in the message.
    Fallback { path: String, file_on_clipboard: bool, text_cut: bool, paste_keys: String },
    /// The person closed the share without sending.
    Cancelled,
}

/// What the native share answered.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Native {
    Shared,
    Cancelled,
    /// It could not share; the reason is logged and the fallback runs.
    Unavailable(String),
}

/// The share's answer as the window's outcome, or `None` when the fallback must run.
pub fn settled(native: &Native) -> Option<ShareOutcome> {
    match native {
        Native::Shared => Some(ShareOutcome::Shared),
        Native::Cancelled => Some(ShareOutcome::Cancelled),
        Native::Unavailable(_) => None,
    }
}

/// How long the share may stay open before the window stops waiting. The share window was shown, so the voice is taken
/// as handed over; the person can still close the sheet at any time.
pub const SHARE_WAIT: Duration = Duration::from_secs(5 * 60);

/// The answer after waiting: `None` when the wait ran out (taken as shared, see `SHARE_WAIT`), `Some(None)` when the
/// share went away without answering.
pub fn after_wait(answer: Option<Option<Native>>) -> Native {
    match answer {
        None => Native::Shared,
        Some(None) => Native::Unavailable("the share ended without an answer".into()),
        Some(Some(native)) => native,
    }
}

/// The share service that is WhatsApp's, among the services' titles: "WhatsApp" itself first, then a title naming it
/// that is not WhatsApp Business, then WhatsApp Business.
pub fn whatsapp_service(titles: &[String]) -> Option<usize> {
    let names: Vec<String> = titles.iter().map(|t| t.trim().to_lowercase()).collect();
    names
        .iter()
        .position(|n| n == "whatsapp")
        .or_else(|| names.iter().position(|n| n.contains("whatsapp") && !n.contains("business")))
        .or_else(|| names.iter().position(|n| n.contains("whatsapp")))
}

/// The Mac's WhatsApp apps (the current one, then the older one).
pub const MAC_WHATSAPP_BUNDLES: [&str; 2] = ["net.whatsapp.WhatsApp", "desktop.WhatsApp"];
/// WhatsApp for Windows (the Microsoft Store app): its package family.
pub const WINDOWS_WHATSAPP_FAMILY: &str = "5319275A.WhatsAppDesktop_cv1g1gvanyjgm";
/// The Share window's title on Windows.
pub const SHARE_TITLE: &str = "Send to WhatsApp";

/// `NSUserCancelledError` in `NSCocoaErrorDomain`: the person closed a share without sending.
pub fn is_cancel(domain: &str, code: isize) -> bool {
    domain == "NSCocoaErrorDomain" && code == 3072
}

/// The keys that paste in a chat, as this system writes them.
pub fn paste_keys(os: &str) -> &'static str {
    if os == "macos" { "⌘V" } else { "Ctrl+V" }
}

/// The clipboard's `CF_HDROP` for one file (Windows): a `DROPFILES` header (files at byte 20, wide names), then the
/// path in UTF-16 and two zeros (the end of the name, the end of the list).
pub fn drop_files(path: &str) -> Vec<u8> {
    const HEADER: u32 = 20;
    let mut out = Vec::with_capacity(HEADER as usize + (path.len() + 2) * 2);
    out.extend_from_slice(&HEADER.to_le_bytes()); // pFiles
    out.extend_from_slice(&0i32.to_le_bytes()); // pt.x
    out.extend_from_slice(&0i32.to_le_bytes()); // pt.y
    out.extend_from_slice(&0i32.to_le_bytes()); // fNC
    out.extend_from_slice(&1i32.to_le_bytes()); // fWide
    for unit in path.encode_utf16().chain([0, 0]) {
        out.extend_from_slice(&unit.to_le_bytes());
    }
    out
}

/// Hands the text and the saved voice to the system's share and waits for its answer.
#[allow(unused_variables)]
pub async fn share(app: &tauri::AppHandle, text: &str, path: &str) -> Native {
    #[cfg(target_os = "macos")]
    let native = mac::share(app, text, path).await;
    #[cfg(windows)]
    let native = win::share(app, text, path).await;
    #[cfg(not(any(windows, target_os = "macos")))]
    let native = Native::Unavailable("no share on this system".into());
    if let Native::Unavailable(why) = &native {
        eprintln!("[whatsapp] share not used: {why}");
    }
    native
}

/// Puts the saved voice on the clipboard as a file (pasted into a chat, it is attached). False when it could not.
#[allow(unused_variables)]
pub async fn file_to_clipboard(app: &tauri::AppHandle, path: &str) -> bool {
    #[cfg(target_os = "macos")]
    return mac::file_to_clipboard(app, path).await;
    #[cfg(windows)]
    return win::file_to_clipboard(path).await;
    #[cfg(not(any(windows, target_os = "macos")))]
    false
}

#[cfg(test)]
mod tests {
    use super::*;

    fn titles(names: &[&str]) -> Vec<String> {
        names.iter().map(|n| n.to_string()).collect()
    }

    #[test]
    fn finds_whatsapp_among_the_services() {
        assert_eq!(whatsapp_service(&titles(&["Mail", "Messages", "AirDrop", "WhatsApp", "Notes"])), Some(3));
        assert_eq!(whatsapp_service(&titles(&["Mail", " whatsapp "])), Some(1));
        assert_eq!(whatsapp_service(&titles(&["WhatsApp Business", "WhatsApp"])), Some(1), "WhatsApp itself first");
        assert_eq!(whatsapp_service(&titles(&["WhatsApp Business", "Send with WhatsApp"])), Some(1));
        assert_eq!(whatsapp_service(&titles(&["Mail", "WhatsApp Business"])), Some(1), "Business when it is the only one");
        assert_eq!(whatsapp_service(&titles(&["Mail", "Messages", "AirDrop"])), None);
        assert_eq!(whatsapp_service(&[]), None);
    }

    #[test]
    fn only_an_unavailable_share_falls_back() {
        assert_eq!(settled(&Native::Shared), Some(ShareOutcome::Shared));
        assert_eq!(settled(&Native::Cancelled), Some(ShareOutcome::Cancelled));
        assert_eq!(settled(&Native::Unavailable("no WhatsApp".into())), None);
    }

    #[test]
    fn a_share_left_open_counts_as_shared_and_a_lost_one_falls_back() {
        assert_eq!(after_wait(None), Native::Shared);
        assert!(matches!(after_wait(Some(None)), Native::Unavailable(_)));
        assert_eq!(after_wait(Some(Some(Native::Cancelled))), Native::Cancelled);
    }

    #[test]
    fn tells_a_cancel_from_a_failure() {
        assert!(is_cancel("NSCocoaErrorDomain", 3072));
        assert!(!is_cancel("NSCocoaErrorDomain", 4));
        assert!(!is_cancel("NSPOSIXErrorDomain", 3072));
    }

    #[test]
    fn paste_keys_per_system() {
        assert_eq!(paste_keys("macos"), "⌘V");
        assert_eq!(paste_keys("windows"), "Ctrl+V");
    }

    #[test]
    fn the_outcome_reaches_the_window_in_camel_case() {
        assert_eq!(serde_json::to_value(ShareOutcome::Shared).unwrap(), serde_json::json!({"kind": "shared"}));
        assert_eq!(serde_json::to_value(ShareOutcome::Cancelled).unwrap(), serde_json::json!({"kind": "cancelled"}));
        let fallback = ShareOutcome::Fallback { path: "/d/Call.m4a".into(), file_on_clipboard: true, text_cut: false, paste_keys: "⌘V".into() };
        assert_eq!(
            serde_json::to_value(fallback).unwrap(),
            serde_json::json!({"kind": "fallback", "path": "/d/Call.m4a", "fileOnClipboard": true, "textCut": false, "pasteKeys": "⌘V"})
        );
    }

    #[test]
    fn drop_files_holds_one_wide_path_and_ends_the_list() {
        let bytes = drop_files("C:\\Users\\me\\Downloads\\Kick-off.ogg");
        assert_eq!(&bytes[0..4], &20u32.to_le_bytes());
        assert_eq!(&bytes[16..20], &1i32.to_le_bytes(), "wide names");
        let units: Vec<u16> = bytes[20..].chunks(2).map(|c| u16::from_le_bytes([c[0], c[1]])).collect();
        assert_eq!(&units[units.len() - 2..], &[0, 0]);
        assert_eq!(String::from_utf16(&units[..units.len() - 2]).unwrap(), "C:\\Users\\me\\Downloads\\Kick-off.ogg");
        let urdu = drop_files("C:\\سلام.ogg");
        let units: Vec<u16> = urdu[20..].chunks(2).map(|c| u16::from_le_bytes([c[0], c[1]])).collect();
        assert_eq!(String::from_utf16(&units[..units.len() - 2]).unwrap(), "C:\\سلام.ogg");
    }
}
