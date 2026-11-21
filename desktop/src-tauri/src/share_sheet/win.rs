//! Windows: the text and the voice go to the Share window (`DataTransferManager` for the main window), where WhatsApp
//! is a target. The share is shown on the main thread (the window's own); the file is looked up on a thread of its own
//! under the request's deferral; the package's ShareCompleted / ShareCanceled answer through a channel.

use std::sync::atomic::{AtomicI64, Ordering};
use std::sync::{Arc, Mutex};

use ::windows::ApplicationModel::DataTransfer::{DataPackage, DataRequestedEventArgs, DataTransferManager, ShareCompletedEventArgs};
use ::windows::Foundation::TypedEventHandler;
use ::windows::Storage::{IStorageItem, StorageFile};
use ::windows::Win32::Foundation::{ERROR_INSUFFICIENT_BUFFER, ERROR_SUCCESS, GlobalFree, HANDLE, HWND};
use ::windows::Win32::Storage::Packaging::Appx::GetPackagesByPackageFamily;
use ::windows::Win32::System::DataExchange::{CloseClipboard, EmptyClipboard, OpenClipboard, SetClipboardData};
use ::windows::Win32::System::Memory::{GMEM_MOVEABLE, GlobalAlloc, GlobalLock, GlobalUnlock};
use ::windows::Win32::System::Ole::CF_HDROP;
use ::windows::Win32::System::WinRT::{RO_INIT_MULTITHREADED, RoInitialize};
use ::windows::Win32::UI::Shell::IDataTransferManagerInterop;
use ::windows::core::{HSTRING, IInspectable, Interface, Ref};
use tauri::{AppHandle, Manager};
use tokio::sync::oneshot;
use windows_collections::IIterable;

use super::{Native, SHARE_TITLE, SHARE_WAIT, WINDOWS_WHATSAPP_FAMILY, after_wait, drop_files};

type Answer = Arc<Mutex<Option<oneshot::Sender<Native>>>>;

/// The DataRequested handler of the last share (the manager is the window's for good: one handler at a time).
static HANDLER: AtomicI64 = AtomicI64::new(0);

fn answer(to: &Answer, native: Native) {
    let tx = to.lock().unwrap_or_else(|p| p.into_inner()).take();
    if let Some(tx) = tx {
        let _ = tx.send(native);
    }
}

/// WhatsApp for Windows is installed for this person (its Store package family has a package).
fn whatsapp_installed() -> bool {
    let (mut count, mut length) = (0u32, 0u32);
    // SAFETY: counts only (no buffers).
    let status = unsafe { GetPackagesByPackageFamily(&HSTRING::from(WINDOWS_WHATSAPP_FAMILY), &mut count, None, &mut length, None) };
    (status == ERROR_SUCCESS || status == ERROR_INSUFFICIENT_BUFFER) && count > 0
}

pub async fn share(app: &AppHandle, text: &str, path: &str) -> Native {
    if !whatsapp_installed() {
        return Native::Unavailable("WhatsApp is not installed".into());
    }
    let Some(hwnd) = app.get_webview_window(crate::lifecycle::MAIN).and_then(|w| w.hwnd().ok()).map(|h| h.0 as isize) else {
        return Native::Unavailable("no main window to share from".into());
    };
    let (tx, rx) = oneshot::channel();
    let to: Answer = Arc::new(Mutex::new(Some(tx)));
    let (text, path) = (text.to_string(), path.to_string());
    let shown = to.clone();
    let started = app.run_on_main_thread(move || {
        if let Err(e) = show(hwnd, text, path, shown.clone()) {
            answer(&shown, Native::Unavailable(format!("the Share window did not open: {e}")));
        }
    });
    if let Err(e) = started {
        return Native::Unavailable(format!("the main thread did not run the share: {e}"));
    }
    after_wait(tokio::time::timeout(SHARE_WAIT, rx).await.ok().map(Result::ok))
}

fn show(hwnd: isize, text: String, path: String, to: Answer) -> ::windows::core::Result<()> {
    let hwnd = HWND(hwnd as *mut _);
    let interop = ::windows::core::factory::<DataTransferManager, IDataTransferManagerInterop>()?;
    // SAFETY: the main window's handle, on its own thread.
    let manager: DataTransferManager = unsafe { interop.GetForWindow(hwnd) }?;
    let old = HANDLER.swap(0, Ordering::SeqCst);
    if old != 0 {
        let _ = manager.RemoveDataRequested(old);
    }
    let text = HSTRING::from(text);
    let token = manager.DataRequested(&TypedEventHandler::new(move |_, args: Ref<DataRequestedEventArgs>| {
        let Some(args) = args.as_ref() else { return Ok(()) };
        let request = args.Request()?;
        let data = request.Data()?;
        data.Properties()?.SetTitle(&HSTRING::from(SHARE_TITLE))?;
        data.SetText(&text)?;
        let done = to.clone();
        data.ShareCompleted(&TypedEventHandler::new(move |_, completed: Ref<ShareCompletedEventArgs>| {
            if let Some(target) = completed.as_ref().and_then(|c| c.ShareTarget().ok()) {
                eprintln!("[whatsapp] shared to {:?}", target.AppUserModelId().map(|id| id.to_string()).unwrap_or_default());
            }
            answer(&done, Native::Shared);
            Ok(())
        }))?;
        let cancelled = to.clone();
        // Windows 10 2004 and later; without it a closed Share window is answered by the wait running out.
        let _ = data.ShareCanceled(&TypedEventHandler::new(move |_, _: Ref<IInspectable>| {
            answer(&cancelled, Native::Cancelled);
            Ok(())
        }));
        // The file is looked up asynchronously: the request waits on its deferral.
        let deferral = request.GetDeferral()?;
        let (path, failed) = (path.clone(), to.clone());
        std::thread::spawn(move || {
            // SAFETY: this thread's own apartment.
            let _ = unsafe { RoInitialize(RO_INIT_MULTITHREADED) };
            if let Err(e) = attach(&data, &path) {
                eprintln!("[whatsapp] the voice was not attached: {e}");
                answer(&failed, Native::Unavailable(format!("the voice file could not be shared: {e}")));
            }
            let _ = deferral.Complete();
        });
        Ok(())
    }))?;
    HANDLER.store(token, Ordering::SeqCst);
    // SAFETY: as above.
    unsafe { interop.ShowShareUIForWindow(hwnd) }
}

fn attach(data: &DataPackage, path: &str) -> ::windows::core::Result<()> {
    let file: StorageFile = StorageFile::GetFileFromPathAsync(&HSTRING::from(path))?.join()?;
    let items: IIterable<IStorageItem> = IIterable::from(vec![Some(file.cast::<IStorageItem>()?)]);
    data.SetStorageItems(&items, true)
}

/// The saved voice on the clipboard as Explorer's copy of a file (`CF_HDROP`), so Ctrl+V in a chat attaches it.
pub async fn file_to_clipboard(path: &str) -> bool {
    let bytes = drop_files(path);
    tauri::async_runtime::spawn_blocking(move || put_drop_files(&bytes).inspect_err(|e| eprintln!("[whatsapp] clipboard: {e}")).is_ok()).await.unwrap_or(false)
}

fn put_drop_files(bytes: &[u8]) -> ::windows::core::Result<()> {
    // SAFETY: a moveable block of the payload's size, filled while locked; the clipboard owns it once set.
    unsafe {
        let memory = GlobalAlloc(GMEM_MOVEABLE, bytes.len())?;
        let at = GlobalLock(memory);
        if at.is_null() {
            let e = ::windows::core::Error::from_thread();
            let _ = GlobalFree(Some(memory));
            return Err(e);
        }
        std::ptr::copy_nonoverlapping(bytes.as_ptr(), at.cast::<u8>(), bytes.len());
        let _ = GlobalUnlock(memory);
        OpenClipboard(None)?;
        let set = EmptyClipboard().and_then(|_| SetClipboardData(u32::from(CF_HDROP.0), Some(HANDLE(memory.0))));
        let _ = CloseClipboard();
        if set.is_err() {
            let _ = GlobalFree(Some(memory));
        }
        set.map(|_| ())
    }
}
