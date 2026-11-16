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
//! restarting or shutting down, which macOS asks through `applicationShouldTerminate:` (`mac`). One keeping serves every
//! quit ([`QuitSave`]): a second Quit while it runs waits for it, and macOS is told to wait while it runs.

use std::future::Future;
use std::sync::OnceLock;
use std::sync::atomic::{AtomicBool, Ordering};
#[cfg(any(target_os = "macos", test))]
use std::time::Duration;

use tokio::sync::watch;

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

/// The keeping of a running recording before a quit, one for every quit: the first quit runs it, a quit that comes while
/// it runs (⌘Q twice, the Dock's Quit during the menu's) waits for it, and a quit after it goes on at once. Its end is
/// sent even when the keeping panics, so nobody waits for ever.
pub struct QuitSave {
    started: AtomicBool,
    done: watch::Sender<bool>,
}

impl Default for QuitSave {
    fn default() -> Self {
        Self { started: AtomicBool::new(false), done: watch::Sender::new(false) }
    }
}

/// Sends the end of the keeping when it is dropped: after the save, or while a panic unwinds through it.
struct Finished<'a>(&'a watch::Sender<bool>);

impl Drop for Finished<'_> {
    fn drop(&mut self) {
        self.0.send_replace(true);
    }
}

impl QuitSave {
    /// A quit is keeping the recording now.
    pub fn saving(&self) -> bool {
        self.started.load(Ordering::SeqCst) && !*self.done.borrow()
    }

    /// Runs `save` for the first quit; any other waits until it ended.
    pub async fn run<F: Future<Output = ()>>(&self, save: impl FnOnce() -> F) {
        if self.started.swap(true, Ordering::SeqCst) {
            let mut ended = self.done.subscribe();
            let _ = ended.wait_for(|done| *done).await;
            return;
        }
        let _finished = Finished(&self.done);
        save().await;
    }
}

/// The app's one [`QuitSave`].
pub fn quit_save() -> &'static QuitSave {
    static SAVE: OnceLock<QuitSave> = OnceLock::new();
    SAVE.get_or_init(QuitSave::default)
}

/// macOS asked whether EchoLedger may quit (the Dock's Quit, logging out) and was told to wait: that answer ends the app,
/// so a Quit of EchoLedger's own that finishes its keeping meanwhile leaves the exit to it. Never on Windows.
pub fn terminate_pending() -> bool {
    #[cfg(target_os = "macos")]
    {
        mac::pending()
    }
    #[cfg(not(target_os = "macos"))]
    {
        false
    }
}

/// The answer to a quit from outside the app on a Mac (pure): the Dock's Quit, logging out, restarting, shutting down.
#[cfg(any(target_os = "macos", test))]
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum TerminateReply {
    /// Nothing to keep and no keeping running: quit now.
    Now,
    /// A recording runs, or a quit is keeping it: quit once it is kept.
    Later,
}

#[cfg(any(target_os = "macos", test))]
pub fn terminate_reply(recording: bool, saving: bool) -> TerminateReply {
    if recording || saving { TerminateReply::Later } else { TerminateReply::Now }
}

/// How long a quit from outside waits for the recording to be kept before the Mac quits anyway (what was written by then
/// comes back as a recovered recording at the next start).
#[cfg(any(target_os = "macos", test))]
pub const SAVE_LIMIT: Duration = Duration::from_secs(20);

/// The main queue's own timer answers this much later, should the async runtime be stuck.
#[cfg(any(target_os = "macos", test))]
pub const SAVE_LIMIT_BACKSTOP: Duration = Duration::from_secs(22);

/// macOS: the Dock's Quit and the system's quit (logging out, restarting, shutting down) go through the same keeping of a
/// running recording as the app's own Quit. tao's application delegate does not answer `applicationShouldTerminate:`,
/// so EchoLedger adds that answer to it, once, before the event loop runs (public Objective-C runtime and AppKit API).
#[cfg(target_os = "macos")]
pub mod mac {
    use std::sync::OnceLock;
    use std::sync::atomic::{AtomicBool, Ordering};

    use objc2::MainThreadMarker;
    use objc2::runtime::{AnyClass, AnyObject, Imp, NSObjectProtocol, Sel};
    use objc2_app_kit::{NSApplication, NSApplicationTerminateReply};
    use tauri::{AppHandle, Manager};

    use super::{SAVE_LIMIT, SAVE_LIMIT_BACKSTOP, TerminateReply, terminate_reply};

    static APP: OnceLock<AppHandle> = OnceLock::new();
    /// The delegate answers `applicationShouldTerminate:` (the smoke test fails without it).
    static ANSWERS: AtomicBool = AtomicBool::new(false);
    /// macOS waits for EchoLedger's answer.
    static PENDING: AtomicBool = AtomicBool::new(false);
    /// The answer went: it is sent once, whichever comes first (the keeping, its time limit, the main queue's timer).
    static REPLIED: AtomicBool = AtomicBool::new(false);

    /// Teaches the application delegate to answer `applicationShouldTerminate:`. On the main thread, after the app is
    /// built and before it runs (the delegate exists then and its launch has not begun). True when the answer is in.
    pub fn answer_quits_from_outside(app: &AppHandle) -> bool {
        let _ = APP.set(app.clone());
        let Some(mtm) = MainThreadMarker::new() else { return false };
        let answers = install(&NSApplication::sharedApplication(mtm));
        ANSWERS.store(answers, Ordering::SeqCst);
        answers
    }

    /// Whether the delegate answers quits from outside (`answer_quits_from_outside` worked).
    pub fn answers_quits() -> bool {
        ANSWERS.load(Ordering::SeqCst)
    }

    pub fn pending() -> bool {
        PENDING.load(Ordering::SeqCst)
    }

    /// Adds `applicationShouldTerminate:` to the delegate's class; false when there is no delegate or its class already
    /// answers it (an update of tao or Tauri may add its own: then theirs runs and the smoke test says so).
    pub fn install(ns_app: &NSApplication) -> bool {
        let Some(delegate) = ns_app.delegate() else { return false };
        let object: &AnyObject = AsRef::<AnyObject>::as_ref(&*delegate);
        let class: *const AnyClass = object.class();
        let answer: extern "C-unwind" fn(&AnyObject, Sel, *mut AnyObject) -> usize = should_terminate;
        // SAFETY: `answer` has the signature of `applicationShouldTerminate:` ("Q@:@": NSUInteger from self, _cmd and the
        // sender); a method the class already has is left as it is (class_addMethod refuses to replace one).
        let added = unsafe { objc2::ffi::class_addMethod(class.cast_mut(), objc2::sel!(applicationShouldTerminate:), std::mem::transmute::<extern "C-unwind" fn(&AnyObject, Sel, *mut AnyObject) -> usize, Imp>(answer), c"Q@:@".as_ptr()) };
        if !added.as_bool() {
            eprintln!("[echoledger] the application delegate already answers applicationShouldTerminate:");
            return false;
        }
        // AppKit may have noted what the delegate answers when it was set: set it again so the new answer counts.
        ns_app.setDelegate(Some(&delegate));
        delegate.respondsToSelector(objc2::sel!(applicationShouldTerminate:))
    }

    extern "C-unwind" fn should_terminate(_delegate: &AnyObject, _cmd: Sel, _sender: *mut AnyObject) -> usize {
        let Some(app) = APP.get() else { return NSApplicationTerminateReply::TerminateNow.0 };
        let recording = app.try_state::<crate::AppState>().is_some_and(|s| s.capture.is_recording());
        if terminate_reply(recording, super::quit_save().saving()) == TerminateReply::Now {
            return NSApplicationTerminateReply::TerminateNow.0;
        }
        if !PENDING.swap(true, Ordering::SeqCst) {
            // Whatever happens to the keeping, the main queue answers once its time is up.
            if let Ok(when) = dispatch2::DispatchTime::try_from(SAVE_LIMIT_BACKSTOP) {
                let _ = dispatch2::DispatchQueue::main().after(when, reply_once);
            }
            let app = app.clone();
            tauri::async_runtime::spawn(async move {
                // The keeping runs as a task of its own (it blocks while the recorder finishes its files), so its time limit
                // fires while it still works, and a panic in it ends it like a finish.
                let keeping = tauri::async_runtime::spawn(async move { crate::actions::save_before_quit(&app).await });
                let _ = tokio::time::timeout(SAVE_LIMIT, keeping).await;
                reply_once();
            });
        }
        NSApplicationTerminateReply::TerminateLater.0
    }

    /// Tells macOS it may quit now, once.
    fn reply_once() {
        if REPLIED.swap(true, Ordering::SeqCst) {
            return;
        }
        // AppKit waits for the answer in its modal run loop mode, where the main dispatch queue still runs.
        dispatch2::DispatchQueue::main().exec_async(|| {
            if let Some(mtm) = MainThreadMarker::new() {
                NSApplication::sharedApplication(mtm).replyToApplicationShouldTerminate(true);
            }
        });
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
        // From outside on a Mac (the Dock's Quit, logging out): nothing to keep quits now; a recording, or a keeping that
        // runs, makes macOS wait.
        assert_eq!(terminate_reply(false, false), TerminateReply::Now);
        assert_eq!(terminate_reply(true, false), TerminateReply::Later);
        assert_eq!(terminate_reply(true, true), TerminateReply::Later);
        assert_eq!(terminate_reply(false, true), TerminateReply::Later, "the menu's Quit is keeping it: the Dock's waits");
        assert!(SAVE_LIMIT.as_secs() >= 10 && SAVE_LIMIT.as_secs() <= 30, "long enough to finish the files, short enough for a logout");
        assert!(SAVE_LIMIT_BACKSTOP > SAVE_LIMIT);
        // One keeping for every quit: it stops a running recording the way the menu bar icon's Quit always did.
        let actions = code("actions.rs");
        let keep = &actions[actions.find("pub async fn save_before_quit(").unwrap()..];
        let keep = &keep[..keep.find("\n}\n").unwrap()];
        assert!(keep.contains("crate::app_life::quit_save()") && keep.contains("capture.is_recording()") && keep.contains("stop_recording_for_quit(app).await"), "{keep}");
        // The menu bar icon's and the app menu's Quit (⌘Q) keep it, then exit, unless macOS's own quit already waits.
        let tray = code("tray.rs");
        let quit = &tray[tray.find("\"quit\" => {").unwrap()..];
        let quit = &quit[..quit.find("Ok(())").unwrap()];
        assert!(quit.contains("crate::actions::save_before_quit(&app).await;") && quit.contains("if !crate::app_life::terminate_pending() {\n                            app.exit(0);"), "{quit}");
        // The Dock's Quit and the system's: kept in a task of its own under the time limit, a timer on the main queue
        // besides, and one answer.
        let mac = code("app_life.rs");
        let answer = &mac[mac.find("extern \"C-unwind\" fn should_terminate(").unwrap()..];
        let answer = &answer[..answer.find("\n    }\n").unwrap()];
        for step in ["DispatchQueue::main().after(when, reply_once)", "let keeping = tauri::async_runtime::spawn(", "tokio::time::timeout(SAVE_LIMIT, keeping).await", "reply_once();"] {
            assert!(answer.contains(step), "{step}");
        }
        assert!(mac.contains("if REPLIED.swap(true, Ordering::SeqCst) {\n            return;"), "answered once");
        // Installed after the app is built and before it runs, in every run (the smoke test checks it).
        let lib = code("lib.rs");
        let (built, runs) = (lib.find(".build(tauri::generate_context!())").unwrap(), lib.find("app.run(|handle, event|").unwrap());
        let installed = lib.find("app_life::mac::answer_quits_from_outside(app.handle())").expect("installed");
        assert!(built < installed && installed < runs);
        assert!(code("smoke.rs").contains("crate::app_life::mac::answers_quits()"));
    }

    #[tokio::test]
    async fn a_second_quit_waits_for_the_first_keeping() {
        let save = std::sync::Arc::new(QuitSave::default());
        let runs = std::sync::Arc::new(std::sync::atomic::AtomicU32::new(0));
        let (release, released) = tokio::sync::oneshot::channel::<()>();
        let first = {
            let (save, runs) = (save.clone(), runs.clone());
            tokio::spawn(async move {
                save.run(|| async move {
                    runs.fetch_add(1, Ordering::SeqCst);
                    let _ = released.await;
                })
                .await
            })
        };
        while !save.saving() {
            tokio::task::yield_now().await;
        }
        let second = {
            let (save, runs) = (save.clone(), runs.clone());
            tokio::spawn(async move { save.run(|| async move { runs.fetch_add(1, Ordering::SeqCst); }).await })
        };
        tokio::time::sleep(Duration::from_millis(50)).await;
        assert!(!second.is_finished(), "the second Quit waits while the first keeps the recording");
        assert!(save.saving());
        release.send(()).unwrap();
        first.await.unwrap();
        tokio::time::timeout(Duration::from_secs(1), second).await.expect("it goes on once the keeping ended").unwrap();
        assert!(!save.saving());
        assert_eq!(runs.load(Ordering::SeqCst), 1, "kept once");
        // A quit after it goes on at once.
        tokio::time::timeout(Duration::from_millis(100), save.run(|| async { panic!("never kept twice") })).await.unwrap();
    }

    #[tokio::test]
    async fn a_keeping_that_panics_still_lets_the_others_go() {
        let save = std::sync::Arc::new(QuitSave::default());
        let first = {
            let save = save.clone();
            tokio::spawn(async move { save.run(|| async { panic!("the keeping failed") }).await })
        };
        assert!(first.await.is_err(), "it panicked");
        assert!(!save.saving());
        tokio::time::timeout(Duration::from_millis(100), save.run(|| async {})).await.expect("nobody waits for ever");
    }

    #[test]
    fn echoledger_is_a_normal_mac_app() {
        // Never the Dock-less modes, in any run: not in the code, not in Info.plist. The one exception is spaces.rs: while
        // another app's full-screen window fills a display (the Dock hidden there anyway) EchoLedger's menu bar menu
        // opens over it only without the Dock icon, which comes back as soon as no such window is on screen.
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
            if file.ends_with("spaces.rs") {
                assert_eq!(app_code.matches("ActivationPolicy::Accessory").count(), 1, "only the full-screen switch");
                assert!(app_code.contains("Dock::Away => NSApplicationActivationPolicy::Accessory,"));
                assert!(app_code.contains("if another_app_full_screen { Dock::Away } else { Dock::Shown }"));
                for dockless in ["ActivationPolicy::Prohibited", "set_dock_visibility(false)"] {
                    assert!(!app_code.contains(dockless), "{}: {dockless}", file.display());
                }
                continue;
            }
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
