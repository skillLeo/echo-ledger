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
//!
//! It is also emptied at once when the app locks, signs out or quits ([`clear_now`], [`clear_now_on_main_thread`]),
//! with the same rule: only while it still holds what was put there.

use tauri::AppHandle;

/// How long the text may stay on the clipboard.
pub const CLEAR_AFTER: std::time::Duration = std::time::Duration::from_secs(60);

/// What clearing needs of a clipboard: its change counter now, and emptying it.
pub trait Board {
    /// The clipboard's change counter (or a digest of what it holds where there is none); `None` when it can't be read.
    fn stamp(&mut self) -> Option<u64>;
    fn clear(&mut self);
}

/// What trying to empty the clipboard of one copy came to.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Outcome {
    /// It still held the copy, and is empty now.
    Cleared,
    /// Something else was copied since: nothing to do, ever, for that copy.
    Changed,
    /// The clipboard could not be read or opened (another app held it): try again later.
    Failed,
}

/// Empties the clipboard when it still holds what was put there (its counter is the one read right after). A
/// clipboard that can't be read is left alone, and says so.
pub fn clear_if_unchanged(board: &mut impl Board, put: u64) -> Outcome {
    match board.stamp() {
        None => Outcome::Failed,
        Some(now) if now == put => {
            board.clear();
            Outcome::Cleared
        }
        Some(_) => Outcome::Changed,
    }
}

/// The counters read after each copy that may still be on the clipboard: what a lock, a sign-out or a quit empties.
/// Each copy's counter is its own entry, so two copies at once never lose one; the clipboard's counter decides which
/// one it still holds.
static PENDING: std::sync::Mutex<Vec<u64>> = std::sync::Mutex::new(Vec::new());

fn pending() -> std::sync::MutexGuard<'static, Vec<u64>> {
    PENDING.lock().unwrap_or_else(|p| p.into_inner())
}

/// Remembers `put` as a copy to empty.
pub fn remember(put: u64) {
    let mut all = pending();
    if !all.contains(&put) {
        all.push(put);
    }
}

/// Writes to the clipboard and remembers the copy under the one lock [`take_all`] takes: a lock, a sign-out or a quit
/// that comes while the codes are being copied finds them remembered (it waits for the write), never on the clipboard
/// and forgotten.
pub fn put_remembered<E>(write: impl FnOnce() -> Result<u64, E>) -> Result<u64, E> {
    let mut all = pending();
    let put = write()?;
    if !all.contains(&put) {
        all.push(put);
    }
    Ok(put)
}

/// Every copy still to empty, taken all at once (a lock, a sign-out, a quit).
pub fn take_all() -> Vec<u64> {
    std::mem::take(&mut *pending())
}

/// What happened to one copy: it stays pending only when the clipboard could not be emptied (it was busy), and goes
/// otherwise (emptied now, or something else was copied since).
pub fn settle(put: u64, outcome: Outcome) {
    let mut all = pending();
    all.retain(|&p| p != put);
    if outcome == Outcome::Failed {
        all.push(put);
    }
}

/// How many times the wait's clear is tried when another app holds the clipboard, and how far apart.
pub const CLEAR_TRIES: u32 = 4;
pub const CLEAR_RETRY_GAP: std::time::Duration = std::time::Duration::from_secs(2);

/// Puts `text` on the clipboard, concealed, and empties it after [`CLEAR_AFTER`] if nothing was copied since.
pub async fn copy_concealed(app: &AppHandle, text: String) -> Result<(), String> {
    let put = platform::put(app, text).await?;
    let app = app.clone();
    tauri::async_runtime::spawn(async move {
        tokio::time::sleep(CLEAR_AFTER).await;
        // Its own copy only: the counter leaves anything copied since (a newer copy of the codes too) alone.
        clear_with_retries(put, CLEAR_TRIES, CLEAR_RETRY_GAP, |p| platform::clear_if_unchanged(&app, p)).await;
    });
    Ok(())
}

/// The wait's clear of one copy: tried again a few times, a little apart, while the clipboard is busy (another app
/// holds it), so a moment's hold doesn't leave the codes there until the next lock. Still busy after the last try, the
/// copy stays pending for the next lock, sign-out or quit.
pub async fn clear_with_retries<F, Fut>(put: u64, tries: u32, gap: std::time::Duration, mut clear: F) -> Outcome
where
    F: FnMut(u64) -> Fut,
    Fut: std::future::Future<Output = Outcome>,
{
    let mut outcome = Outcome::Failed;
    for attempt in 0..tries.max(1) {
        if attempt > 0 {
            tokio::time::sleep(gap).await;
            // A lock or a sign-out meanwhile already settled it: nothing left for the wait to do.
            if !pending().contains(&put) {
                return outcome;
            }
        }
        outcome = clear(put).await;
        settle(put, outcome);
        if outcome != Outcome::Failed {
            break;
        }
    }
    outcome
}

/// Every pending copy, emptied with `clear` (one try each); a copy it couldn't be emptied of stays pending.
pub async fn clear_pending<F, Fut>(mut clear: F)
where
    F: FnMut(u64) -> Fut,
    Fut: std::future::Future<Output = Outcome>,
{
    for put in take_all() {
        settle(put, clear(put).await);
    }
}

/// The app locked or signed out: copied codes still on the clipboard go now, not after the wait. A copy the clipboard
/// could not be emptied of (busy) stays pending for the next try.
pub async fn clear_now(app: &AppHandle) {
    clear_pending(|put| platform::clear_if_unchanged(app, put)).await;
}

/// The app quits: the same, from the run loop on the main thread (nothing may wait on it there).
pub fn clear_now_on_main_thread(app: &AppHandle) {
    for put in take_all() {
        settle(put, platform::clear_on_main_thread(app, put));
    }
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
        tauri::async_runtime::spawn_blocking(move || super::put_remembered(|| put_now(owner, &text)).map_err(|e| e.to_string()))
            .await
            .map_err(|e| e.to_string())?
    }

    pub async fn clear_if_unchanged(app: &AppHandle, put: u64) -> super::Outcome {
        let owner = owner(app);
        tauri::async_runtime::spawn_blocking(move || clear_blocking(owner, put)).await.unwrap_or(super::Outcome::Failed)
    }

    pub fn clear_on_main_thread(app: &AppHandle, put: u64) -> super::Outcome {
        clear_blocking(owner(app), put)
    }

    fn clear_blocking(owner: Option<isize>, put: u64) -> super::Outcome {
        let owner = owner.map(|h| HWND(h as *mut _));
        if open_clipboard(owner).is_err() {
            return super::Outcome::Failed;
        }
        let outcome = super::clear_if_unchanged(&mut Clipboard, put);
        // SAFETY: opened above.
        let _ = unsafe { CloseClipboard() };
        outcome
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
            let put = super::put_remembered(|| {
                let pasteboard = NSPasteboard::generalPasteboard();
                pasteboard.clearContents();
                // SAFETY: an AppKit constant, read only.
                let string_type = unsafe { NSPasteboardTypeString };
                let written = pasteboard.setString_forType(&NSString::from_str(&text), string_type)
                    && pasteboard.setString_forType(&NSString::from_str(""), &NSString::from_str(CONCEALED));
                if written { Ok(pasteboard.changeCount() as u64) } else { Err(()) }
            });
            let _ = tx.send(put.ok());
        })
        .map_err(|e| e.to_string())?;
        rx.await.ok().flatten().ok_or_else(|| "The clipboard did not take the codes.".to_string())
    }

    pub async fn clear_if_unchanged(app: &AppHandle, put: u64) -> super::Outcome {
        let (tx, rx) = oneshot::channel();
        if app
            .run_on_main_thread(move || {
                let _ = tx.send(super::clear_if_unchanged(&mut Pasteboard(NSPasteboard::generalPasteboard()), put));
            })
            .is_err()
        {
            return super::Outcome::Failed;
        }
        rx.await.unwrap_or(super::Outcome::Failed)
    }

    /// On the main thread already (the run loop's exit): AppKit is called here directly.
    pub fn clear_on_main_thread(_app: &AppHandle, put: u64) -> super::Outcome {
        super::clear_if_unchanged(&mut Pasteboard(NSPasteboard::generalPasteboard()), put)
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
        super::put_remembered(|| app.clipboard().write_secret(text.clone()).map(|_| digest(&text)).map_err(|e| e.to_string()))
    }

    pub async fn clear_if_unchanged(app: &AppHandle, put: u64) -> super::Outcome {
        super::clear_if_unchanged(&mut Clipboard(app), put)
    }

    pub fn clear_on_main_thread(app: &AppHandle, put: u64) -> super::Outcome {
        super::clear_if_unchanged(&mut Clipboard(app), put)
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
        assert_eq!(clear_if_unchanged(&mut board, put), Outcome::Cleared);
        assert_eq!(board.text, None, "still the codes: emptied");
        // Cleared once; the next check finds another counter and leaves the clipboard alone.
        assert_eq!(clear_if_unchanged(&mut board, put), Outcome::Changed);

        let put = board.copy("aaaa-bbbb\ncccc-dddd");
        board.copy("a link the person copied after");
        assert_eq!(clear_if_unchanged(&mut board, put), Outcome::Changed);
        assert_eq!(board.text.as_deref(), Some("a link the person copied after"), "something copied since stays");

        // The codes copied twice: the first wait ends without clearing the second copy; the second wait clears it.
        let first = board.copy("codes");
        let second = board.copy("codes");
        assert_eq!(clear_if_unchanged(&mut board, first), Outcome::Changed);
        assert_eq!(board.text.as_deref(), Some("codes"));
        assert_eq!(clear_if_unchanged(&mut board, second), Outcome::Cleared);
        assert_eq!(board.text, None);
    }

    /// The pending copies are one process-wide list: these tests take it in turn.
    static PENDING_TESTS: std::sync::Mutex<()> = std::sync::Mutex::new(());

    #[test]
    fn a_copy_the_clipboard_could_not_be_emptied_of_stays_pending_until_a_clear_is_confirmed() {
        let _turn = PENDING_TESTS.lock().unwrap_or_else(|p| p.into_inner());
        let _ = take_all();
        remember(5);
        // A lock while another app holds the clipboard: the copy is put back, not dropped.
        for put in take_all() {
            settle(put, Outcome::Failed);
        }
        assert_eq!(take_all(), vec![5]);
        remember(5);
        // The next try empties it: gone for good.
        for put in take_all() {
            settle(put, Outcome::Cleared);
        }
        assert_eq!(take_all(), Vec::<u64>::new());
        // Something else was copied since: nothing left to do for it.
        remember(6);
        settle(6, Outcome::Changed);
        assert_eq!(take_all(), Vec::<u64>::new());
    }

    #[test]
    fn two_copies_at_once_are_both_kept_and_each_wait_tries_its_own_copy() {
        let _turn = PENDING_TESTS.lock().unwrap_or_else(|p| p.into_inner());
        let _ = take_all();
        let mut board = Fake { counter: 0, text: None };
        let first = board.copy("codes");
        let second = board.copy("codes");
        // The two copies remembered in either order: neither is lost.
        remember(second);
        remember(first);
        remember(first);
        // The first copy's wait tries its own copy: the clipboard holds the second, so it is left alone.
        let outcome = clear_if_unchanged(&mut board, first);
        assert_eq!(outcome, Outcome::Changed);
        settle(first, outcome);
        assert_eq!(board.text.as_deref(), Some("codes"));
        // A lock then empties what is still pending: the second copy.
        let all = take_all();
        assert_eq!(all, vec![second]);
        for put in all {
            let outcome = clear_if_unchanged(&mut board, put);
            assert_eq!(outcome, Outcome::Cleared);
            settle(put, outcome);
        }
        assert_eq!(board.text, None);
        assert_eq!(take_all(), Vec::<u64>::new());
    }

    #[test]
    fn locking_signing_out_and_quitting_empty_the_copied_codes() {
        let commands = include_str!("commands.rs");
        let body = |start: &str| {
            let at = commands.find(start).unwrap_or_else(|| panic!("{start}"));
            &commands[at..at + commands[at..].find("\n}\n").unwrap()]
        };
        assert!(body("pub fn lock_now(").contains("clear_copied_codes(&app)"), "Lock now");
        assert!(body("pub fn spawn_idle_lock(").contains("crate::secret_copy::clear_now(&app).await"), "the idle lock");
        assert!(body("pub async fn unpair(").contains("crate::secret_copy::clear_now(&app).await"), "sign-out");
        let lib = include_str!("lib.rs");
        let exit = &lib[lib.find("if let RunEvent::Exit = &event {").expect("the run loop hears the exit")..];
        assert!(exit[..exit.find("\n        }").unwrap()].contains("secret_copy::clear_now_on_main_thread(handle)"), "quitting");
    }

    /// A fake clipboard shared by the clears below, as the system's is; `busy` refuses that many opens first.
    struct Shared {
        board: std::cell::RefCell<Fake>,
        busy: std::cell::Cell<u32>,
        tries: std::cell::Cell<u32>,
    }

    impl Shared {
        fn new() -> Self {
            Shared { board: std::cell::RefCell::new(Fake { counter: 0, text: None }), busy: 0.into(), tries: 0.into() }
        }

        /// A copy of the codes, written and remembered the way copy_concealed does it.
        fn copy_codes(&self, text: &str) -> u64 {
            put_remembered(|| Ok::<u64, ()>(self.board.borrow_mut().copy(text))).unwrap()
        }

        async fn clear(&self, put: u64) -> Outcome {
            self.tries.set(self.tries.get() + 1);
            if self.busy.get() > 0 {
                self.busy.set(self.busy.get() - 1);
                return Outcome::Failed;
            }
            clear_if_unchanged(&mut *self.board.borrow_mut(), put)
        }

        fn text(&self) -> Option<String> {
            self.board.borrow().text.clone()
        }
    }

    #[test]
    fn clear_now_empties_the_codes_leaves_a_later_copy_and_keeps_a_busy_one_for_next_time() {
        let _turn = PENDING_TESTS.lock().unwrap_or_else(|p| p.into_inner());
        let _ = take_all();
        let clip = Shared::new();
        // Codes copied, then the app locks: the clipboard is empty and nothing is left to do.
        clip.copy_codes("aaaa-bbbb");
        tauri::async_runtime::block_on(clear_pending(|p| clip.clear(p)));
        assert_eq!(clip.text(), None);
        assert_eq!(take_all(), Vec::<u64>::new());

        // Codes copied, then a link: the lock leaves the link, and forgets the codes' copy.
        clip.copy_codes("aaaa-bbbb");
        clip.board.borrow_mut().copy("https://example.test/link");
        tauri::async_runtime::block_on(clear_pending(|p| clip.clear(p)));
        assert_eq!(clip.text().as_deref(), Some("https://example.test/link"));
        assert_eq!(take_all(), Vec::<u64>::new());

        // Another app holds the clipboard at the lock: the codes stay there and pending; the next lock empties them.
        let put = clip.copy_codes("cccc-dddd");
        clip.busy.set(1);
        tauri::async_runtime::block_on(clear_pending(|p| clip.clear(p)));
        assert_eq!(clip.text().as_deref(), Some("cccc-dddd"));
        assert_eq!(take_all(), vec![put]);
        remember(put);
        tauri::async_runtime::block_on(clear_pending(|p| clip.clear(p)));
        assert_eq!(clip.text(), None);
        assert_eq!(take_all(), Vec::<u64>::new());
    }

    #[test]
    fn the_waits_clear_is_tried_again_while_the_clipboard_is_busy() {
        let _turn = PENDING_TESTS.lock().unwrap_or_else(|p| p.into_inner());
        let _ = take_all();
        let clip = Shared::new();
        let put = clip.copy_codes("aaaa-bbbb");
        // Busy for the first two tries: the third empties it, and it is no longer pending.
        clip.busy.set(2);
        let outcome = tauri::async_runtime::block_on(clear_with_retries(put, 4, std::time::Duration::ZERO, |p| clip.clear(p)));
        assert_eq!(outcome, Outcome::Cleared);
        assert_eq!(clip.tries.get(), 3);
        assert_eq!(clip.text(), None);
        assert_eq!(take_all(), Vec::<u64>::new());

        // Busy for every try: it stops after the last one and stays pending for the next lock.
        let put = clip.copy_codes("cccc-dddd");
        clip.tries.set(0);
        clip.busy.set(10);
        let outcome = tauri::async_runtime::block_on(clear_with_retries(put, 4, std::time::Duration::ZERO, |p| clip.clear(p)));
        assert_eq!(outcome, Outcome::Failed);
        assert_eq!(clip.tries.get(), 4);
        assert_eq!(clip.text().as_deref(), Some("cccc-dddd"));
        assert_eq!(take_all(), vec![put]);

        // A lock settles it while the wait sleeps between two tries: the wait stops there, and tries no more.
        let put = clip.copy_codes("eeee-ffff");
        clip.tries.set(0);
        clip.busy.set(10);
        let (tried_tx, tried_rx) = std::sync::mpsc::channel::<()>();
        let lock = std::thread::spawn(move || {
            tried_rx.recv().unwrap();
            std::thread::sleep(std::time::Duration::from_millis(60));
            take_all()
        });
        let shared = &clip;
        let outcome = tauri::async_runtime::block_on(clear_with_retries(put, 4, std::time::Duration::from_millis(400), |p| {
            let _ = tried_tx.send(());
            shared.clear(p)
        }));
        assert_eq!(lock.join().unwrap(), vec![put], "the lock took the busy copy");
        assert_eq!(outcome, Outcome::Failed);
        assert_eq!(clip.tries.get(), 1);
        assert_eq!(take_all(), Vec::<u64>::new());
    }

    #[test]
    fn a_lock_while_the_codes_are_being_copied_finds_them_remembered() {
        let _turn = PENDING_TESTS.lock().unwrap_or_else(|p| p.into_inner());
        let _ = take_all();
        let (writing_tx, writing_rx) = std::sync::mpsc::channel();
        let copier = std::thread::spawn(move || {
            put_remembered(|| {
                writing_tx.send(()).unwrap();
                // The clipboard takes a moment (another app held it): the lock comes in now.
                std::thread::sleep(std::time::Duration::from_millis(50));
                Ok::<u64, ()>(42)
            })
        });
        writing_rx.recv().unwrap();
        // The lock takes the pending copies while the write is on its way: it waits, and gets this one.
        assert_eq!(take_all(), vec![42]);
        assert_eq!(copier.join().unwrap(), Ok(42));
        // A write the clipboard refused remembers nothing.
        assert_eq!(put_remembered(|| Err::<u64, &str>("busy")), Err("busy"));
        assert_eq!(take_all(), Vec::<u64>::new());
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
        assert_eq!(clear_if_unchanged(&mut board, 1), Outcome::Failed);
        assert!(!board.0);
        assert_eq!(CLEAR_AFTER.as_secs(), 60);
    }
}
