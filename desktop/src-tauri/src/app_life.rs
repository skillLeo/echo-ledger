//! How EchoLedger runs and quits as an app.
//!
//! On a Mac it is a normal app (since v1.8.1): in the Dock with its dot, in ⌘Tab and in Force Quit Applications, with its
//! menu bar icon as well. Closing the main window keeps it running, as Slack and Zoom do: the Dock icon and the menu bar
//! icon stay, and a click on the Dock icon opens the window again (`RunEvent::Reopen`). Started at login (`--autostart`)
//! it is in the Dock with no window and leaves the focus where it was. On Windows it lives in the notification area as
//! before. EchoLedger hides its windows from screen sharing only, never itself from other software (`privacy`).
//!
//! Every quit really quits, and none loses a recording: the menu bar icon's and the app menu's Quit (⌘Q) stop and keep a
//! running recording first (`actions::save_before_quit`), then exit; on a Mac, so do the Dock's Quit and logging out,
//! restarting or shutting down, which macOS asks through `applicationShouldTerminate:` (`mac`).

/// What the run loop does with an exit request (pure): the last window closing (no code) keeps EchoLedger running, in
/// the notification area or the menu bar and on a Mac the Dock; an explicit quit (`AppHandle::exit`: the menu bar icon's
/// and the app menu's Quit) or the updater's restart (a code) exits.
pub fn keep_running(code: Option<i32>) -> bool {
    code.is_none()
}

/// Whether a start takes the focus from the app in front (pure). Opened by the person, it does (macOS brings a newly
/// opened app forward); started at login, or as the smoke test beside the person's own work, it does not.
#[cfg(any(target_os = "macos", test))]
pub fn takes_focus_at_launch(autostart: bool, smoke: bool) -> bool {
    !autostart && !smoke
}

/// The answer to a quit from outside the app on a Mac (pure): the Dock's Quit, logging out, restarting, shutting down.
#[cfg(any(target_os = "macos", test))]
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum TerminateReply {
    /// Nothing to keep: quit now.
    Now,
    /// A recording runs: keep it first (as the menu bar icon's Quit does), then quit.
    AfterSaving,
    /// Already keeping it: quit once that is done.
    StillSaving,
}

#[cfg(any(target_os = "macos", test))]
pub fn terminate_reply(recording: bool, saving: bool) -> TerminateReply {
    if saving {
        TerminateReply::StillSaving
    } else if recording {
        TerminateReply::AfterSaving
    } else {
        TerminateReply::Now
    }
}

/// How long a quit from outside waits for the recording to be kept before the Mac quits anyway (what was written by then
/// comes back as a recovered recording at the next start).
#[cfg(any(target_os = "macos", test))]
pub const SAVE_LIMIT: std::time::Duration = std::time::Duration::from_secs(20);

/// macOS: the Dock's Quit and the system's quit (logging out, restarting, shutting down) go through the same keeping of a
/// running recording as the app's own Quit. tao's application delegate does not answer `applicationShouldTerminate:`,
/// so EchoLedger adds that answer to it (public Objective-C runtime and AppKit API only).
#[cfg(target_os = "macos")]
pub mod mac {
    use std::sync::OnceLock;
    use std::sync::atomic::{AtomicBool, Ordering};

    use objc2::MainThreadMarker;
    use objc2::runtime::{AnyClass, AnyObject, Imp, Sel};
    use objc2_app_kit::{NSApplication, NSApplicationTerminateReply};
    use tauri::{AppHandle, Manager};

    use super::{SAVE_LIMIT, TerminateReply, terminate_reply};

    static APP: OnceLock<AppHandle> = OnceLock::new();
    /// A quit from outside is keeping the recording now.
    static SAVING: AtomicBool = AtomicBool::new(false);

    /// Teaches the application delegate to answer `applicationShouldTerminate:` (on the main thread, at setup).
    pub fn answer_quits_from_outside(app: &AppHandle) {
        let _ = APP.set(app.clone());
        let Some(mtm) = MainThreadMarker::new() else { return };
        let ns_app = NSApplication::sharedApplication(mtm);
        let Some(delegate) = ns_app.delegate() else { return };
        let object: &AnyObject = AsRef::<AnyObject>::as_ref(&*delegate);
        let class: *const AnyClass = object.class();
        let answer: extern "C-unwind" fn(&AnyObject, Sel, *mut AnyObject) -> usize = should_terminate;
        // SAFETY: `answer` has the signature of `applicationShouldTerminate:` ("Q@:@": NSUInteger from self, _cmd and the
        // sender); a method the class already has is left as it is (class_addMethod refuses to replace one).
        let added = unsafe { objc2::ffi::class_addMethod(class.cast_mut(), objc2::sel!(applicationShouldTerminate:), std::mem::transmute::<extern "C-unwind" fn(&AnyObject, Sel, *mut AnyObject) -> usize, Imp>(answer), c"Q@:@".as_ptr()) };
        if added.as_bool() {
            // AppKit may have noted what the delegate answers when it was set: set it again so the new answer counts.
            ns_app.setDelegate(Some(&delegate));
        } else {
            eprintln!("[echoledger] the application delegate already answers applicationShouldTerminate:");
        }
    }

    extern "C-unwind" fn should_terminate(_delegate: &AnyObject, _cmd: Sel, _sender: *mut AnyObject) -> usize {
        let Some(app) = APP.get() else { return NSApplicationTerminateReply::TerminateNow.0 };
        let recording = app.try_state::<crate::AppState>().is_some_and(|s| s.capture.is_recording());
        match terminate_reply(recording, SAVING.load(Ordering::SeqCst)) {
            TerminateReply::Now => NSApplicationTerminateReply::TerminateNow.0,
            TerminateReply::StillSaving => NSApplicationTerminateReply::TerminateLater.0,
            TerminateReply::AfterSaving => {
                SAVING.store(true, Ordering::SeqCst);
                let app = app.clone();
                tauri::async_runtime::spawn(async move {
                    let _ = tokio::time::timeout(SAVE_LIMIT, crate::actions::save_before_quit(&app)).await;
                    // AppKit waits for the answer in its modal run loop mode, where the main dispatch queue still runs.
                    dispatch2::DispatchQueue::main().exec_async(|| {
                        if let Some(mtm) = MainThreadMarker::new() {
                            NSApplication::sharedApplication(mtm).replyToApplicationShouldTerminate(true);
                        }
                    });
                });
                NSApplicationTerminateReply::TerminateLater.0
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// The app's own code (test modules left out) of a file under src/.
    fn code(file: &str) -> String {
        let text = std::fs::read_to_string(std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("src").join(file)).unwrap();
        text.split("\n#[cfg(test)]\nmod tests").next().unwrap().to_string()
    }

    #[test]
    fn closing_the_last_window_keeps_echoledger_running_and_a_quit_quits() {
        assert!(keep_running(None), "the last window closed: in the Dock, the menu bar or the notification area");
        assert!(!keep_running(Some(0)), "Quit (the menu bar icon, the app menu, ⌘Q): app.exit(0)");
        assert!(!keep_running(Some(tauri::RESTART_EXIT_CODE)), "the updater's restart");
        // The run loop asks exactly this, and nothing else keeps EchoLedger from exiting.
        let lib = code("lib.rs");
        let exit = &lib[lib.find("if let RunEvent::ExitRequested { code, api, .. } = &event {").expect("the run loop hears exit requests")..];
        let exit = &exit[..exit.find("\n        }\n").unwrap()];
        assert!(exit.contains("if app_life::keep_running(*code) {\n                api.prevent_exit();\n            }"), "{exit}");
        assert_eq!(lib.matches("prevent_exit()").count(), 1);
    }

    #[test]
    fn every_quit_keeps_a_running_recording_first() {
        // From outside on a Mac (the Dock's Quit, logging out): nothing to keep quits now; a recording is kept first.
        assert_eq!(terminate_reply(false, false), TerminateReply::Now);
        assert_eq!(terminate_reply(true, false), TerminateReply::AfterSaving);
        assert_eq!(terminate_reply(true, true), TerminateReply::StillSaving, "asked again while keeping it: no second stop");
        assert_eq!(terminate_reply(false, true), TerminateReply::StillSaving);
        assert!(SAVE_LIMIT.as_secs() >= 10 && SAVE_LIMIT.as_secs() <= 30, "long enough to finish the files, short enough for a logout");
        // One way to keep it, for every quit: it stops a running recording the way the menu bar icon's Quit always did.
        let actions = code("actions.rs");
        let keep = &actions[actions.find("pub async fn save_before_quit(").unwrap()..];
        let keep = &keep[..keep.find("\n}\n").unwrap()];
        assert!(keep.contains("capture.is_recording()") && keep.contains("stop_recording_for_quit(app).await"));
        // The menu bar icon's and the app menu's Quit (⌘Q) keep it, then exit.
        let tray = code("tray.rs");
        let quit = &tray[tray.find("\"quit\" => {").unwrap()..];
        let quit = &quit[..quit.find("app.exit(0);").expect("the tray's Quit exits")];
        assert!(quit.contains("crate::actions::save_before_quit(&app).await;"), "{quit}");
        // The Dock's Quit and the system's: kept first, then macOS is told to go on, even when keeping it hangs.
        let mac = code("app_life.rs");
        let answer = &mac[mac.find("extern \"C-unwind\" fn should_terminate(").unwrap()..];
        assert!(answer.contains("tokio::time::timeout(SAVE_LIMIT, crate::actions::save_before_quit(&app)).await"));
        assert!(answer.find("save_before_quit").unwrap() < answer.find("replyToApplicationShouldTerminate(true)").unwrap());
        assert!(answer.contains("TerminateReply::Now => NSApplicationTerminateReply::TerminateNow.0"));
        assert!(code("lib.rs").contains("app_life::mac::answer_quits_from_outside(&handle);"), "installed at setup");
    }

    #[test]
    fn echoledger_is_a_normal_mac_app() {
        // Never the Dock-less modes, in any run: not in the code, not in Info.plist.
        let root = std::path::Path::new(env!("CARGO_MANIFEST_DIR"));
        let mut files = Vec::new();
        fn walk(dir: &std::path::Path, out: &mut Vec<std::path::PathBuf>) {
            for entry in std::fs::read_dir(dir).unwrap().flatten() {
                let path = entry.path();
                if path.is_dir() {
                    walk(&path, out);
                } else if path.extension().is_some_and(|e| e == "rs") && path.file_name().is_some_and(|n| n != "tests.rs") {
                    out.push(path);
                }
            }
        }
        walk(&root.join("src"), &mut files);
        for file in &files {
            let text = std::fs::read_to_string(file).unwrap();
            let app_code = text.split("\n#[cfg(test)]\nmod tests").next().unwrap();
            for dockless in ["ActivationPolicy::Accessory", "ActivationPolicy::Prohibited", "set_dock_visibility(false)"] {
                assert!(!app_code.contains(dockless), "{}: {dockless}", file.display());
            }
        }
        let plist = std::fs::read_to_string(root.join("Info.plist")).unwrap();
        assert!(!plist.contains("LSUIElement") && !plist.contains("LSBackgroundOnly"), "an agent app has no Dock icon, no ⌘Tab and no Force Quit");
        // Regular for the whole run (the smoke test too), and a start at login leaves the focus where it was.
        let lib = code("lib.rs");
        assert_eq!(lib.matches("set_activation_policy(tauri::ActivationPolicy::Regular)").count(), 2, "the run and the smoke test");
        assert!(lib.contains(".activate_ignoring_other_apps(app_life::takes_focus_at_launch(autostart, smoke_dir.is_some()))"));
        assert!(takes_focus_at_launch(false, false), "opened by the person");
        assert!(!takes_focus_at_launch(true, false), "at login: in the Dock with its dot, no window, the focus stays");
        assert!(!takes_focus_at_launch(false, true), "the smoke test beside the person's work");
        assert!(lib.contains("if !autostart {\n                tray::show_main(&handle);"), "no window at login");
        // A click on the Dock icon opens the window (the reopen macOS sends a running app).
        assert!(lib.contains("if let RunEvent::Reopen { .. } = &event {\n            actions::show_main_from_outside(handle, &[]);"));
    }
}
