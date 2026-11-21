//! Send to WhatsApp → Text and voice (F6): the voice goes to WhatsApp with the text, without the person finding the
//! file in Downloads. The audio is kept in the app's cache, one copy per recording (a retry downloads nothing again, and
//! only the last recording's copy is kept), and handed to the system's own share:
//! - macOS: WhatsApp's share extension (`NSSharingService`) when its WhatsApp has one (`mac`);
//! - Windows: the Share window, where WhatsApp is a target (`DataTransferManager`, `win`).
//!
//! Nothing goes to Downloads unless it is needed: a share that was sent or closed leaves no file behind there. When the
//! share cannot be used (WhatsApp not installed, no share service for it, another system, any error), the window falls
//! back to what it did before: the voice in Downloads (one copy per recording, used again on a retry) and wa.me with the
//! text, plus the file on the clipboard, so ⌘V / Ctrl+V in the chat attaches it. The clipboard then holds the file, not
//! the text: wa.me already puts the text in the message. When the text had to be cut to fit the address, the window says
//! so and offers to copy the whole text after the file.
//!
//! A share whose end is not known is never taken as sent: Windows before build 20348 does not say when its Share window
//! is closed, so there the command answers `Opened` as soon as the window is shown, and so does a share that gives no
//! answer within `SHARE_WAIT`. The window then stays open and offers the fallback.
//!
//! The choices are small pure functions below, tested here; the native calls stay thin.

use std::path::{Path, PathBuf};
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
    /// The system's share took the text and the voice (the person chose a chat there); `to_whatsapp` when the target
    /// was WhatsApp (else another app the person chose in the Share window).
    Shared {
        #[serde(rename = "toWhatsApp")]
        to_whatsapp: bool,
    },
    /// The share was shown and its end is not known (Windows 10, or no answer in time): nothing is claimed.
    Opened,
    /// wa.me opened with the text; the voice is saved at `path` and, when `file_on_clipboard`, on the clipboard to
    /// paste with `paste_keys`. `text_cut`: the text was too long for the address and only its start is in the message.
    Fallback { path: String, file_on_clipboard: bool, text_cut: bool, paste_keys: String },
    /// The person closed the share without sending.
    Cancelled,
}

/// What the native share answered.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Native {
    Shared { to_whatsapp: bool },
    Cancelled,
    Opened,
    /// It could not share; the reason is logged and the fallback runs.
    Unavailable(String),
}

/// The share's answer as the window's outcome, or `None` when the fallback must run.
pub fn settled(native: &Native) -> Option<ShareOutcome> {
    match native {
        Native::Shared { to_whatsapp } => Some(ShareOutcome::Shared { to_whatsapp: *to_whatsapp }),
        Native::Opened => Some(ShareOutcome::Opened),
        Native::Cancelled => Some(ShareOutcome::Cancelled),
        Native::Unavailable(_) => None,
    }
}

/// How long the window waits for a share that says how it ended (WhatsApp's on the Mac, Windows from build 20348). When
/// it runs out the share is still open somewhere: the answer is `Opened`, never `Shared`.
pub const SHARE_WAIT: Duration = Duration::from_secs(2 * 60);

/// The answer after waiting: `None` when the wait ran out (`Opened`, see `SHARE_WAIT`), `Some(None)` when the share
/// went away without answering.
pub fn after_wait(answer: Option<Option<Native>>) -> Native {
    match answer {
        None => Native::Opened,
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
/// The first Windows build whose share says it was closed without sending (`DataPackage.ShareCanceled`).
pub const SHARE_CANCELED_BUILD: u32 = 20348;
/// What the Share window shows when the voice file could not be added.
/// What the Share window shows when the voice file could not be added: before build 20348 the sheet already shows
/// "Open WhatsApp with the text instead" (the share answered `Opened`); from it on, the fallback runs by itself.
pub fn attach_failed_text(says_cancel: bool) -> &'static str {
    if says_cancel {
        "EchoLedger could not add the voice file. Close this window: EchoLedger opens WhatsApp with the text instead."
    } else {
        "EchoLedger could not add the voice file. Close this window, then choose Open WhatsApp with the text instead."
    }
}

/// Windows says a closed Share window from this build on; an unknown build is taken as not saying.
pub fn says_cancel(build: Option<u32>) -> bool {
    build.is_some_and(|b| b >= SHARE_CANCELED_BUILD)
}

/// The Share window's chosen target is WhatsApp (its app id starts with WhatsApp's package family).
pub fn is_whatsapp_target(app_user_model_id: &str) -> bool {
    app_user_model_id.to_lowercase().starts_with(&format!("{}!", WINDOWS_WHATSAPP_FAMILY.to_lowercase()))
}

/// The recording's audio as the server has it now, from its detail: the primary recording's id, size and type (the
/// link's signature changes on every read and is left out), and its size when the server says it.
pub fn audio_version(detail: &serde_json::Value) -> (String, Option<u64>) {
    let primary = detail.get("recordings").and_then(serde_json::Value::as_array).and_then(|all| all.iter().find(|r| r.get("role").and_then(serde_json::Value::as_str) == Some("primary")));
    let field = |name: &str| primary.and_then(|p| p.get(name)).map(|v| v.as_str().map_or_else(|| v.to_string(), str::to_string)).unwrap_or_default();
    let link = detail.get("playback_url").and_then(serde_json::Value::as_str).unwrap_or_default();
    let path = link.split('?').next().unwrap_or_default();
    let size = primary.and_then(|p| p.get("size_bytes")).and_then(serde_json::Value::as_u64);
    (format!("{}|{}|{}|{path}", field("id"), field("size_bytes"), field("mime")), size)
}

/// The cache folder's name for a recording's audio: its id's letters, digits, `-` and `_` only (the id comes from the
/// window, so it never names another folder; at most 64), then a digest of the audio's version, so a replaced audio is
/// never taken for the old one.
pub fn cache_key(id: &str, version: &str) -> String {
    let safe: String = id.chars().filter(|c| c.is_ascii_alphanumeric() || matches!(c, '-' | '_')).take(64).collect();
    let safe = if safe.is_empty() { "recording".to_string() } else { safe };
    // FNV-1a: stable across builds and runs; a name, not a secret (the folder is the app's own).
    let hash = version.bytes().fold(0xcbf2_9ce4_8422_2325u64, |h, b| (h ^ u64::from(b)).wrapping_mul(0x0000_0100_0000_01b3));
    format!("{safe}-{hash:016x}")
}

/// Where the share copies live: the app's cache folder (else its data folder), `whatsapp-share`.
pub fn share_root(cache_dir: Option<PathBuf>, data_dir: &Path) -> PathBuf {
    cache_dir.unwrap_or_else(|| data_dir.to_path_buf()).join("whatsapp-share")
}

/// Removes every share copy (sign-out, another account signed in).
pub fn clear_all(root: &Path) {
    let _ = std::fs::remove_dir_all(root);
}

/// Removes every share copy under `root` but `keep_key`'s. Called once a share has settled, so a copy a still-open
/// share window may read stays until the next share is done.
pub fn clear_other_shares(root: &Path, keep_key: &str) {
    let Ok(entries) = std::fs::read_dir(root) else { return };
    for entry in entries.flatten() {
        if entry.file_name().to_string_lossy() != keep_key {
            let _ = std::fs::remove_dir_all(entry.path());
        }
    }
}

/// A finished copy of the recording is there (a download in progress is written beside it as `.part`), of the size the
/// server says when it says one.
pub fn is_cached(path: &Path, expected: Option<u64>) -> bool {
    std::fs::metadata(path).is_ok_and(|m| m.is_file() && m.len() > 0 && expected.is_none_or(|size| m.len() == size))
}

/// The two files hold the same bytes (compared in full, not by name or size alone).
pub fn same_bytes(a: &Path, b: &Path) -> std::io::Result<bool> {
    use std::io::Read;
    if std::fs::metadata(a)?.len() != std::fs::metadata(b)?.len() {
        return Ok(false);
    }
    let (mut a, mut b) = (std::fs::File::open(a)?, std::fs::File::open(b)?);
    let (mut left, mut right) = (vec![0u8; 64 * 1024], vec![0u8; 64 * 1024]);
    loop {
        let read = a.read(&mut left)?;
        if read == 0 {
            return Ok(true);
        }
        b.read_exact(&mut right[..read])?;
        if left[..read] != right[..read] {
            return Ok(false);
        }
    }
}

/// The voice in Downloads for the fallback: a file already there under `name` (or its "(2)", "(3)"… names) only when it
/// holds exactly these bytes, else a copy under a free name. A same-named file of another recording is never used.
pub fn downloads_copy(dir: &Path, name: &str, source: &Path) -> std::io::Result<PathBuf> {
    let (stem, ext) = name.rsplit_once('.').map_or((name, ""), |(s, e)| (s, e));
    let candidates = std::iter::once(dir.join(name)).chain((2..1000).map(|n| dir.join(if ext.is_empty() { format!("{stem} ({n})") } else { format!("{stem} ({n}).{ext}") })));
    for there in candidates {
        if !there.exists() {
            break;
        }
        if there.is_file() && same_bytes(&there, source).unwrap_or(false) {
            return Ok(there);
        }
    }
    let target = crate::commands::export_target(dir, name);
    std::fs::copy(source, &target)?;
    Ok(target)
}

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
    return win::file_to_clipboard(app, path).await;
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
        assert_eq!(settled(&Native::Shared { to_whatsapp: true }), Some(ShareOutcome::Shared { to_whatsapp: true }));
        assert_eq!(settled(&Native::Shared { to_whatsapp: false }), Some(ShareOutcome::Shared { to_whatsapp: false }));
        assert_eq!(settled(&Native::Opened), Some(ShareOutcome::Opened));
        assert_eq!(settled(&Native::Cancelled), Some(ShareOutcome::Cancelled));
        assert_eq!(settled(&Native::Unavailable("no WhatsApp".into())), None);
    }

    #[test]
    fn a_share_left_open_is_never_taken_as_sent_and_a_lost_one_falls_back() {
        assert_eq!(after_wait(None), Native::Opened, "no answer in time: open, its end unknown");
        assert!(matches!(after_wait(Some(None)), Native::Unavailable(_)));
        assert_eq!(after_wait(Some(Some(Native::Cancelled))), Native::Cancelled);
    }

    #[test]
    fn windows_says_a_closed_share_window_only_from_build_20348() {
        assert!(!says_cancel(Some(19045)), "Windows 10 22H2");
        assert!(!says_cancel(Some(20347)));
        assert!(says_cancel(Some(20348)));
        assert!(says_cancel(Some(22631)), "Windows 11");
        assert!(!says_cancel(None), "unknown: treated as not saying");
    }

    #[test]
    fn tells_whatsapp_from_another_share_target() {
        assert!(is_whatsapp_target("5319275A.WhatsAppDesktop_cv1g1gvanyjgm!App"));
        assert!(is_whatsapp_target("5319275a.whatsappdesktop_cv1g1gvanyjgm!app"));
        assert!(!is_whatsapp_target("microsoft.windowscommunicationsapps_8wekyb3d8bbwe!microsoft.windowslive.mail"));
        assert!(!is_whatsapp_target(""));
    }

    #[test]
    fn a_recordings_share_copy_is_named_by_its_id_and_its_audio_version() {
        let key = cache_key("0b6f6a3e-1c2d-4e5f-9a8b-7c6d5e4f3a2b", "rec-1:1000:audio/ogg");
        assert!(key.starts_with("0b6f6a3e-1c2d-4e5f-9a8b-7c6d5e4f3a2b-"), "{key}");
        assert_eq!(key, cache_key("0b6f6a3e-1c2d-4e5f-9a8b-7c6d5e4f3a2b", "rec-1:1000:audio/ogg"), "stable");
        assert_ne!(key, cache_key("0b6f6a3e-1c2d-4e5f-9a8b-7c6d5e4f3a2b", "rec-2:1000:audio/ogg"), "new audio, new copy");
        assert!(cache_key("../../etc/x", "v").starts_with("etcx-"));
        assert!(cache_key("a\\b:c", "v").starts_with("abc-"));
        assert!(cache_key("..", "v").starts_with("recording-"));
        assert!(cache_key(&"x".repeat(200), "v").len() <= 64 + 1 + 16);
        assert!(cache_key("s1", "v").chars().all(|c| c.is_ascii_alphanumeric() || c == '-' || c == '_'));
    }

    #[test]
    fn the_audio_version_is_the_primary_recording_and_its_size() {
        let detail = serde_json::json!({
            "updated_at": "2026-11-20T10:00:00Z",
            "recordings": [
                {"id": "mic", "role": "mic", "mime": "audio/ogg", "size_bytes": 5},
                {"id": "rec-1", "role": "primary", "mime": "audio/ogg", "size_bytes": 1000}
            ],
            "playback_url": "https://ledger.example.test/api/recordings/rec-1/audio?expires=1&signature=abc"
        });
        let (version, size) = audio_version(&detail);
        assert_eq!(size, Some(1000));
        assert!(version.contains("rec-1") && version.contains("1000"), "{version}");
        // The link's signature changes on every read: it is not part of the version.
        let mut again = detail.clone();
        again["playback_url"] = serde_json::json!("https://ledger.example.test/api/recordings/rec-1/audio?expires=2&signature=def");
        assert_eq!(audio_version(&again).0, version);
        let mut replaced = detail.clone();
        replaced["recordings"][1]["id"] = serde_json::json!("rec-2");
        assert_ne!(audio_version(&replaced).0, version);
        assert_eq!(audio_version(&serde_json::json!({})).1, None);
    }

    #[test]
    fn only_the_last_recordings_share_copy_is_kept() {
        let root = tempfile::tempdir().unwrap();
        for key in ["old", "keep"] {
            std::fs::create_dir_all(root.path().join(key)).unwrap();
            std::fs::write(root.path().join(key).join("Call.ogg"), b"audio").unwrap();
        }
        clear_other_shares(root.path(), "keep");
        assert!(!root.path().join("old").exists());
        assert!(root.path().join("keep").join("Call.ogg").exists());
        clear_other_shares(&root.path().join("missing"), "keep");
    }

    #[test]
    fn signing_out_leaves_no_share_copy() {
        let base = tempfile::tempdir().unwrap();
        let root = share_root(Some(base.path().to_path_buf()), Path::new("/unused"));
        assert_eq!(root, base.path().join("whatsapp-share"));
        assert_eq!(share_root(None, base.path()), base.path().join("whatsapp-share"));
        std::fs::create_dir_all(root.join("s1-abc")).unwrap();
        std::fs::write(root.join("s1-abc").join("Call.ogg"), b"audio").unwrap();
        clear_all(&root);
        assert!(!root.exists());
        clear_all(&root);
    }

    #[test]
    fn sign_out_and_a_new_sign_in_forget_the_share_copies() {
        let source = include_str!("../commands.rs");
        for start in ["pub async fn unpair(", "async fn adopt_registration("] {
            let at = source.find(start).unwrap_or_else(|| panic!("{start} not found"));
            let body = &source[at..at + source[at..].find("\n}\n").unwrap()];
            assert!(body.contains("forget_whatsapp_shares("), "{start} clears the WhatsApp share copies");
        }
    }

    #[test]
    fn a_kept_share_copy_is_used_again_and_a_partial_one_is_not() {
        let dir = tempfile::tempdir().unwrap();
        let file = dir.path().join("Call.ogg");
        assert!(!is_cached(&file, None));
        std::fs::write(&file, b"").unwrap();
        assert!(!is_cached(&file, None), "an empty file is not a recording");
        std::fs::write(&file, b"audio").unwrap();
        assert!(is_cached(&file, None));
        assert!(is_cached(&file, Some(5)));
        assert!(!is_cached(&file, Some(6)), "not the size the server says");
    }

    #[test]
    fn the_windows_attach_failure_says_what_to_do_on_each_build() {
        assert!(attach_failed_text(false).contains("Close this window, then choose Open WhatsApp with the text instead"));
        assert!(attach_failed_text(true).contains("EchoLedger opens WhatsApp with the text instead"));
    }

    #[test]
    fn the_downloads_copy_is_made_once_per_recording() {
        let dir = tempfile::tempdir().unwrap();
        let source = dir.path().join("cache.ogg");
        std::fs::write(&source, b"the voice").unwrap();
        let downloads = dir.path().join("Downloads");
        std::fs::create_dir_all(&downloads).unwrap();
        let first = downloads_copy(&downloads, "Kick-off 2026-11-06.ogg", &source).unwrap();
        assert_eq!(first, downloads.join("Kick-off 2026-11-06.ogg"));
        assert_eq!(std::fs::read(&first).unwrap(), b"the voice");
        let again = downloads_copy(&downloads, "Kick-off 2026-11-06.ogg", &source).unwrap();
        assert_eq!(again, first, "a retry uses the same file, no (2)");
        assert_eq!(std::fs::read_dir(&downloads).unwrap().count(), 1);
        // Another file of that name and the same size (another client's recording) is left alone and never sent.
        std::fs::write(&first, b"other one").unwrap();
        let other = downloads_copy(&downloads, "Kick-off 2026-11-06.ogg", &source).unwrap();
        assert_eq!(other, downloads.join("Kick-off 2026-11-06 (2).ogg"));
        assert_eq!(std::fs::read(&first).unwrap(), b"other one");
        assert_eq!(std::fs::read(&other).unwrap(), b"the voice");
        // Of another size too.
        std::fs::write(&first, b"something else entirely").unwrap();
        assert_eq!(downloads_copy(&downloads, "Kick-off 2026-11-06.ogg", &source).unwrap(), other, "the matching copy is found");
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
        assert_eq!(serde_json::to_value(ShareOutcome::Shared { to_whatsapp: true }).unwrap(), serde_json::json!({"kind": "shared", "toWhatsApp": true}));
        assert_eq!(serde_json::to_value(ShareOutcome::Opened).unwrap(), serde_json::json!({"kind": "opened"}));
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
