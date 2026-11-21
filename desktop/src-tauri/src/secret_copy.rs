//! Text put on the clipboard that should not stay there (the recovery codes of two-step sign-in): marked so the
//! system's clipboard history, cloud clipboard and clipboard managers leave it out, and emptied after a minute when
//! nothing else was copied since.
//!
//! - Windows: `CanIncludeInClipboardHistory` and `CanUploadToCloudClipboard` set to 0, and
//!   `ExcludeClipboardContentFromMonitorProcessing` present, next to the text (CF_UNICODETEXT).
//! - macOS: `org.nspasteboard.ConcealedType` next to the text (nspasteboard.org), which clipboard managers honour.
//! - Elsewhere: the text only, emptied after a minute all the same.
//!
//! Whether the clipboard still holds the text is its own change counter (Windows' sequence number, the pasteboard's
//! change count), read when the text was put there: anything copied since, even the same text, leaves it alone.

use tauri::AppHandle;

/// How long the text may stay on the clipboard.
pub const CLEAR_AFTER: std::time::Duration = std::time::Duration::from_secs(60);

/// What clearing needs of a clipboard: its change counter now, and emptying it.
pub trait Board {
    /// The clipboard's change counter (or a digest of what it holds where there is none); `None` when it can't be read.
    fn stamp(&mut self) -> Option<u64>;
    fn clear(&mut self);
}

/// After the wait: empties the clipboard when it still holds what was put there (its counter is the one read right
/// after), and says whether it did. A clipboard that can't be read is left alone.
pub fn clear_if_unchanged(board: &mut impl Board, put: u64) -> bool {
    if board.stamp() == Some(put) {
        board.clear();
        true
    } else {
        false
    }
}

/// Puts `text` on the clipboard, concealed, and empties it after [`CLEAR_AFTER`] if nothing was copied since.
pub async fn copy_concealed(app: &AppHandle, text: String) -> Result<(), String> {
    let put = platform::put(app, text).await?;
    let app = app.clone();
    tauri::async_runtime::spawn(async move {
        tokio::time::sleep(CLEAR_AFTER).await;
        platform::clear_if_unchanged(&app, put).await;
    });
    Ok(())
}

#[cfg(windows)]
mod platform {
    use ::windows::Win32::Foundation::{GlobalFree, HANDLE, HGLOBAL, HWND};
    use ::windows::Win32::System::DataExchange::{
        CloseClipboard, EmptyClipboard, GetClipboardSequenceNumber, OpenClipboard, RegisterClipboardFormatW, SetClipboardData,
    };
    use ::windows::Win32::System::Memory::{GMEM_MOVEABLE, GlobalAlloc, GlobalLock, GlobalUnlock};
    use ::windows::Win32::System::Ole::CF_UNICODETEXT;
    use ::windows::core::w;
    use tauri::{AppHandle, Manager};

    /// The main window owns the clipboard (a clipboard without an owner may refuse the data).
    fn owner(app: &AppHandle) -> Option<isize> {
        app.get_webview_window(crate::lifecycle::MAIN).and_then(|w| w.hwnd().ok()).map(|h| h.0 as isize)
    }

    pub async fn put(app: &AppHandle, text: String) -> Result<u64, String> {
        let owner = owner(app);
        tauri::async_runtime::spawn_blocking(move || put_now(owner, &text).map_err(|e| e.to_string()))
            .await
            .map_err(|e| e.to_string())?
    }

    pub async fn clear_if_unchanged(app: &AppHandle, put: u64) {
        let owner = owner(app);
        let _ = tauri::async_runtime::spawn_blocking(move || {
            let owner = owner.map(|h| HWND(h as *mut _));
            if open_clipboard(owner).is_err() {
                return;
            }
            super::clear_if_unchanged(&mut Clipboard, put);
            // SAFETY: opened above.
            let _ = unsafe { CloseClipboard() };
        })
        .await;
    }

    /// The open clipboard.
    struct Clipboard;

    impl super::Board for Clipboard {
        fn stamp(&mut self) -> Option<u64> {
            // SAFETY: no arguments; reads a counter.
            Some(u64::from(unsafe { GetClipboardSequenceNumber() }))
        }

        fn clear(&mut self) {
            // SAFETY: the clipboard is open (clear_if_unchanged).
            let _ = unsafe { EmptyClipboard() };
        }
    }

    /// Opens the clipboard, a few tries apart: another app may hold it for a moment.
    fn open_clipboard(owner: Option<HWND>) -> ::windows::core::Result<()> {
        let mut last = Ok(());
        for _ in 0..5 {
            // SAFETY: closed by the caller.
            last = unsafe { OpenClipboard(owner) };
            if last.is_ok() {
                return last;
            }
            std::thread::sleep(std::time::Duration::from_millis(40));
        }
        last
    }

    /// A moveable block holding `bytes`, for SetClipboardData.
    fn global(bytes: &[u8]) -> ::windows::core::Result<HGLOBAL> {
        // SAFETY: a moveable block of the payload's size, filled while locked, freed here when it can't be.
        unsafe {
            let memory = GlobalAlloc(GMEM_MOVEABLE, bytes.len().max(1))?;
            let at = GlobalLock(memory);
            if at.is_null() {
                let e = ::windows::core::Error::from_thread();
                let _ = GlobalFree(Some(memory));
                return Err(e);
            }
            std::ptr::copy_nonoverlapping(bytes.as_ptr(), at.cast::<u8>(), bytes.len());
            let _ = GlobalUnlock(memory);
            Ok(memory)
        }
    }

    /// Hands one block to the open clipboard, which owns it once set; freed here when it is refused.
    fn set(format: u32, bytes: &[u8]) -> ::windows::core::Result<()> {
        let memory = global(bytes)?;
        // SAFETY: the clipboard is open and emptied by this process (put_now).
        unsafe {
            if let Err(e) = SetClipboardData(format, Some(HANDLE(memory.0))) {
                let _ = GlobalFree(Some(memory));
                return Err(e);
            }
        }
        Ok(())
    }

    /// The text and the three marks on the open, emptied clipboard.
    fn fill(wide: &[u8]) -> ::windows::core::Result<()> {
        let zero = 0u32.to_le_bytes();
        set(u32::from(CF_UNICODETEXT.0), wide)?;
        // Left out of Win+V's history and the cloud clipboard, and of clipboard monitors that honour it. The formats are
        // registered by name (the same number in every process).
        // SAFETY: constant names; no other argument.
        let (history, cloud, monitors) = unsafe {
            (
                RegisterClipboardFormatW(w!("CanIncludeInClipboardHistory")),
                RegisterClipboardFormatW(w!("CanUploadToCloudClipboard")),
                RegisterClipboardFormatW(w!("ExcludeClipboardContentFromMonitorProcessing")),
            )
        };
        for format in [history, cloud, monitors] {
            if format != 0 {
                set(format, &zero)?;
            }
        }
        Ok(())
    }

    fn put_now(owner: Option<isize>, text: &str) -> ::windows::core::Result<u64> {
        let owner = owner.map(|h| HWND(h as *mut _));
        let mut wide: Vec<u16> = text.encode_utf16().collect();
        wide.push(0);
        let wide: Vec<u8> = wide.iter().flat_map(|u| u.to_le_bytes()).collect();
        open_clipboard(owner)?;
        // SAFETY: the clipboard is open until CloseClipboard below.
        let done = unsafe { EmptyClipboard() }.and_then(|_| fill(&wide));
        // SAFETY: opened above.
        let _ = unsafe { CloseClipboard() };
        done?;
        // SAFETY: no arguments; reads a counter.
        Ok(u64::from(unsafe { GetClipboardSequenceNumber() }))
    }
}

#[cfg(target_os = "macos")]
mod platform {
    use objc2_app_kit::{NSPasteboard, NSPasteboardTypeString};
    use objc2_foundation::NSString;
    use tauri::AppHandle;
    use tokio::sync::oneshot;

    /// nspasteboard.org: clipboard managers keep no copy of what carries this type.
    const CONCEALED: &str = "org.nspasteboard.ConcealedType";

    pub async fn put(app: &AppHandle, text: String) -> Result<u64, String> {
        let (tx, rx) = oneshot::channel();
        app.run_on_main_thread(move || {
            let pasteboard = NSPasteboard::generalPasteboard();
            pasteboard.clearContents();
            // SAFETY: an AppKit constant, read only.
            let string_type = unsafe { NSPasteboardTypeString };
            let written = pasteboard.setString_forType(&NSString::from_str(&text), string_type)
                && pasteboard.setString_forType(&NSString::from_str(""), &NSString::from_str(CONCEALED));
            let _ = tx.send(written.then(|| pasteboard.changeCount() as u64));
        })
        .map_err(|e| e.to_string())?;
        rx.await.ok().flatten().ok_or_else(|| "The clipboard did not take the codes.".to_string())
    }

    pub async fn clear_if_unchanged(app: &AppHandle, put: u64) {
        let (tx, rx) = oneshot::channel();
        if app
            .run_on_main_thread(move || {
                let _ = tx.send(super::clear_if_unchanged(&mut Pasteboard(NSPasteboard::generalPasteboard()), put));
            })
            .is_ok()
        {
            let _ = rx.await;
        }
    }

    struct Pasteboard(objc2::rc::Retained<NSPasteboard>);

    impl super::Board for Pasteboard {
        fn stamp(&mut self) -> Option<u64> {
            Some(self.0.changeCount() as u64)
        }

        fn clear(&mut self) {
            self.0.clearContents();
        }
    }
}

#[cfg(not(any(windows, target_os = "macos")))]
mod platform {
    use tauri::AppHandle;
    use tauri_plugin_clipboard_manager::ClipboardExt;

    /// No change counter here: a digest of the text the clipboard holds stands in for one.
    fn digest(text: &str) -> u64 {
        let d = ring::digest::digest(&ring::digest::SHA256, text.as_bytes());
        u64::from_le_bytes(d.as_ref()[..8].try_into().unwrap_or_default())
    }

    pub async fn put(app: &AppHandle, text: String) -> Result<u64, String> {
        app.clipboard().write_secret(text.clone()).map_err(|e| e.to_string())?;
        Ok(digest(&text))
    }

    pub async fn clear_if_unchanged(app: &AppHandle, put: u64) {
        super::clear_if_unchanged(&mut Clipboard(app), put);
    }

    struct Clipboard<'a>(&'a AppHandle);

    impl super::Board for Clipboard<'_> {
        fn stamp(&mut self) -> Option<u64> {
            self.0.clipboard().read_text().ok().map(|t| digest(&t))
        }

        fn clear(&mut self) {
            let _ = self.0.clipboard().clear();
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// A clipboard whose counter moves on every copy, as the system's does.
    struct Fake {
        counter: u64,
        text: Option<String>,
    }

    impl Fake {
        fn copy(&mut self, text: &str) -> u64 {
            self.counter += 1;
            self.text = Some(text.into());
            self.counter
        }
    }

    impl Board for Fake {
        fn stamp(&mut self) -> Option<u64> {
            Some(self.counter)
        }

        fn clear(&mut self) {
            self.counter += 1;
            self.text = None;
        }
    }

    #[test]
    fn the_codes_are_cleared_after_the_wait_only_when_nothing_was_copied_since() {
        let mut board = Fake { counter: 7, text: Some("before".into()) };
        let put = board.copy("aaaa-bbbb\ncccc-dddd");
        assert!(clear_if_unchanged(&mut board, put));
        assert_eq!(board.text, None, "still the codes: emptied");
        // Cleared once; the next check finds another counter and leaves the clipboard alone.
        assert!(!clear_if_unchanged(&mut board, put));

        let put = board.copy("aaaa-bbbb\ncccc-dddd");
        board.copy("a link the person copied after");
        assert!(!clear_if_unchanged(&mut board, put));
        assert_eq!(board.text.as_deref(), Some("a link the person copied after"), "something copied since stays");

        // The codes copied twice: the first wait ends without clearing the second copy; the second wait clears it.
        let first = board.copy("codes");
        let second = board.copy("codes");
        assert!(!clear_if_unchanged(&mut board, first));
        assert_eq!(board.text.as_deref(), Some("codes"));
        assert!(clear_if_unchanged(&mut board, second));
        assert_eq!(board.text, None);
    }

    #[test]
    fn a_clipboard_that_cant_be_read_is_left_alone() {
        struct Unreadable(bool);
        impl Board for Unreadable {
            fn stamp(&mut self) -> Option<u64> {
                None
            }
            fn clear(&mut self) {
                self.0 = true;
            }
        }
        let mut board = Unreadable(false);
        assert!(!clear_if_unchanged(&mut board, 1));
        assert!(!board.0);
        assert_eq!(CLEAR_AFTER.as_secs(), 60);
    }
}
