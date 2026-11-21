//! macOS: the text and the voice go to WhatsApp's share extension (`NSSharingService`) when this Mac's WhatsApp has
//! one. AppKit runs on the main thread; the answer comes back through a channel from the delegate below.
//!
//! No share picker when WhatsApp offers no service: the picker lists the same services, so WhatsApp would not be in it
//! either (WhatsApp for Mac 26.40 ships no share extension at all); wa.me and ⌘V reach it instead (`super`).

use std::cell::RefCell;
use std::ptr::NonNull;

use objc2::rc::Retained;
use objc2::runtime::{AnyObject, NSObject, NSObjectProtocol, ProtocolObject};
use objc2::{DefinedClass, MainThreadMarker, MainThreadOnly, define_class, msg_send};
use objc2_app_kit::{NSPasteboard, NSPasteboardWriting, NSSharingContentScope, NSSharingService, NSSharingServiceDelegate, NSWindow, NSWorkspace};
use objc2_foundation::{NSArray, NSError, NSString, NSURL};
use tauri::{AppHandle, Manager};
use tokio::sync::oneshot;

use super::{MAC_WHATSAPP_BUNDLES, Native, SHARE_WAIT, after_wait, is_cancel, whatsapp_service};

struct Ivars {
    answer: RefCell<Option<oneshot::Sender<Native>>>,
    /// The main window: the extension's sheet is shown on it.
    window: Option<Retained<NSWindow>>,
}

define_class!(
    // SAFETY: NSObject has no subclassing requirements and the class has no Drop.
    #[unsafe(super(NSObject))]
    #[thread_kind = MainThreadOnly]
    #[name = "EchoLedgerWhatsAppShare"]
    #[ivars = Ivars]
    struct ShareDelegate;

    unsafe impl NSObjectProtocol for ShareDelegate {}

    unsafe impl NSSharingServiceDelegate for ShareDelegate {
        #[unsafe(method(sharingService:didShareItems:))]
        fn did_share(&self, _service: &NSSharingService, _items: &NSArray) {
            self.answer(Native::Shared);
        }

        #[unsafe(method(sharingService:didFailToShareItems:error:))]
        fn did_fail(&self, _service: &NSSharingService, _items: &NSArray, error: &NSError) {
            let native = if is_cancel(&error.domain().to_string(), error.code()) {
                Native::Cancelled
            } else {
                Native::Unavailable(format!("the share failed: {}", error.localizedDescription()))
            };
            self.answer(native);
        }

        #[unsafe(method_id(sharingService:sourceWindowForShareItems:sharingContentScope:))]
        fn source_window(&self, _service: &NSSharingService, _items: &NSArray, _scope: NonNull<NSSharingContentScope>) -> Option<Retained<NSWindow>> {
            self.ivars().window.clone()
        }
    }
);

impl ShareDelegate {
    fn new(mtm: MainThreadMarker, answer: oneshot::Sender<Native>, window: Option<Retained<NSWindow>>) -> Retained<Self> {
        let this = Self::alloc(mtm).set_ivars(Ivars { answer: RefCell::new(Some(answer)), window });
        // SAFETY: NSObject's init.
        unsafe { msg_send![super(this), init] }
    }

    /// The first answer goes to the waiting command; any later one is dropped.
    fn answer(&self, native: Native) {
        if let Some(tx) = self.ivars().answer.borrow_mut().take() {
            let _ = tx.send(native);
        }
    }
}

thread_local! {
    /// The running share's delegate and service (AppKit keeps a delegate weakly). Replaced by the next share, never
    /// released from inside one of its own callbacks.
    static RUNNING: RefCell<Option<(Retained<ShareDelegate>, Retained<NSSharingService>)>> = const { RefCell::new(None) };
}

pub async fn share(app: &AppHandle, text: &str, path: &str) -> Native {
    // The native window, asked for here (not on the main thread) and carried as a number.
    let window = app.get_webview_window(crate::lifecycle::MAIN).and_then(|w| w.ns_window().ok()).map(|p| p as usize);
    let (tx, rx) = oneshot::channel();
    let (text, path) = (text.to_string(), path.to_string());
    let started = app.run_on_main_thread(move || {
        let Some(mtm) = MainThreadMarker::new() else { return };
        start(mtm, window, &text, &path, tx);
    });
    if let Err(e) = started {
        return Native::Unavailable(format!("the main thread did not run the share: {e}"));
    }
    after_wait(tokio::time::timeout(SHARE_WAIT, rx).await.ok().map(Result::ok))
}

fn whatsapp_installed() -> bool {
    let workspace = NSWorkspace::sharedWorkspace();
    MAC_WHATSAPP_BUNDLES.iter().any(|id| workspace.URLForApplicationWithBundleIdentifier(&NSString::from_str(id)).is_some())
}

/// The sharing services this Mac offers for the text and the file, with their titles.
fn services_for(items: &NSArray) -> (Retained<NSArray<NSSharingService>>, Vec<String>) {
    // SAFETY: NSString and NSURL are items the sharing services take. Deprecated since macOS 13 for menus (the picker's
    // standard item), it still lists the share extensions, and it is the only way to reach WhatsApp's without a picker.
    #[allow(deprecated)]
    let services = unsafe { NSSharingService::sharingServicesForItems(items) };
    let titles = services.iter().map(|s| s.title().to_string()).collect();
    (services, titles)
}

fn items(text: &str, path: &str) -> Retained<NSArray> {
    let text = NSString::from_str(text);
    let url = NSURL::fileURLWithPath(&NSString::from_str(path));
    let parts: [&AnyObject; 2] = [&text, &url];
    NSArray::from_slice(&parts)
}

fn start(mtm: MainThreadMarker, window: Option<usize>, text: &str, path: &str, tx: oneshot::Sender<Native>) {
    // SAFETY: the pointer is Tauri's own NSWindow for the main window, alive while the app runs.
    let window: Option<Retained<NSWindow>> = window.and_then(|p| unsafe { Retained::retain(p as *mut NSWindow) });
    let items = items(text, path);
    let (services, titles) = services_for(&items);
    eprintln!("[whatsapp] share services for text and voice: {titles:?}");
    let delegate = ShareDelegate::new(mtm, tx, window);
    let Some(at) = whatsapp_service(&titles) else {
        let why = if whatsapp_installed() { "this Mac's WhatsApp has no share service" } else { "WhatsApp is not installed" };
        delegate.answer(Native::Unavailable(why.into()));
        return;
    };
    let service = services.objectAtIndex(at);
    service.setDelegate(Some(ProtocolObject::from_ref(&*delegate)));
    // SAFETY: the same items the service was found for.
    if !unsafe { service.canPerformWithItems(Some(&items)) } {
        delegate.answer(Native::Unavailable(format!("{:?} cannot take these items", titles[at])));
        return;
    }
    RUNNING.with(|r| r.replace(Some((delegate, service.clone()))));
    // SAFETY: as above; the answer comes to the delegate.
    unsafe { service.performWithItems(&items) };
}

/// The saved voice on the general pasteboard as a file URL (Finder's own copy), so ⌘V in a chat attaches it.
pub async fn file_to_clipboard(app: &AppHandle, path: &str) -> bool {
    let (tx, rx) = oneshot::channel();
    let path = path.to_string();
    let started = app.run_on_main_thread(move || {
        let pasteboard = NSPasteboard::generalPasteboard();
        pasteboard.clearContents();
        let url = NSURL::fileURLWithPath(&NSString::from_str(&path));
        let objects: Retained<NSArray<ProtocolObject<dyn NSPasteboardWriting>>> = NSArray::from_retained_slice(&[ProtocolObject::from_retained(url)]);
        let _ = tx.send(pasteboard.writeObjects(&objects));
    });
    started.is_ok() && rx.await.unwrap_or(false)
}

#[cfg(test)]
mod tests {
    use super::*;

    /// On a Mac with WhatsApp: `cargo test share_services_here -- --ignored --nocapture` prints the services offered
    /// for text and a voice file, and whether one is WhatsApp's (nothing is shown or sent).
    #[test]
    #[ignore = "reads this Mac's share services"]
    fn share_services_here() {
        let file = tempfile::Builder::new().suffix(".m4a").tempfile().unwrap();
        let (_, titles) = services_for(&items("Me: hello", &file.path().to_string_lossy()));
        println!("services: {titles:?}");
        println!("WhatsApp installed: {}, its service: {:?}", whatsapp_installed(), whatsapp_service(&titles).map(|at| &titles[at]));
    }
}
