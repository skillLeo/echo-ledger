//! FIX-11 (PLAN_V2_1_POLISH): EchoLedger's icon in the Dock.
//!
//! - Its menu (a right click on the Dock icon): New Recording or Stop Recording, New Voice Note, Start Live Assist…,
//!   Hide from Screen Shares › (15 Minutes, 1 Hour, Until I Quit, or Stop Hiding while hidden). Tauri has no Dock menu,
//!   so the application delegate is taught `applicationDockMenu:` (as `app_life` teaches it `applicationShouldTerminate:`),
//!   which builds the menu afresh each time it opens. Its items carry the menu bar's ids (`native_menu::item_id`), so a
//!   pick goes through the same routing as the menu bar's (`native_menu::picked`: the core runs these, page or not).
//! - Its number: the Activity number the rail shows (unread problems and upgrade answers, from the page, plus the calls
//!   waiting, from the core), unless "Show count on the app icon" is off.
//! - Audio files opened with EchoLedger (Finder's Open With, or dropped on the Dock icon) are imported (`opened`).

use std::sync::atomic::{AtomicU32, AtomicU64, Ordering};

use tauri::{AppHandle, Manager};

/// The page's part of the number: what needs the person in Activity (lib/badge.ts actionCount without the calls).
static ACTIVITY: AtomicU32 = AtomicU32::new(0);
/// What the Dock shows now (u64::MAX: not drawn yet), so the same number is never drawn twice.
static SHOWN: AtomicU64 = AtomicU64::new(u64::MAX);

/// The Dock's words for a number (pure), as the rail's badge (lib/badge.ts badgeLabel): nothing at 0, then the number,
/// "9+" above nine.
pub fn badge_label(count: u32) -> Option<String> {
    match count {
        0 => None,
        1..=9 => Some(count.to_string()),
        _ => Some("9+".into()),
    }
}

/// The number on the Dock icon (pure): the page's Activity part and the calls waiting, or none with the setting off.
pub fn badge_count(activity: u32, waiting_calls: usize, shown: bool) -> u32 {
    if shown { activity.saturating_add(u32::try_from(waiting_calls).unwrap_or(u32::MAX)) } else { 0 }
}

/// The page tells its part (MainApp, whenever its Activity changes).
#[tauri::command]
pub fn dock_activity_set(app: AppHandle, count: u32) {
    ACTIVITY.store(count, Ordering::SeqCst);
    follow(&app);
}

/// The number now, drawn when it changed.
pub fn follow(app: &AppHandle) {
    let state = app.state::<crate::AppState>();
    let waiting = crate::pending::DecisionFiles::in_dir(&state.data_dir).load().waiting();
    follow_with(app, waiting);
}

/// The number with the calls waiting the status has just counted (posted to the main thread, never waited for).
pub fn follow_with(app: &AppHandle, waiting: usize) {
    let count = badge_count(ACTIVITY.load(Ordering::SeqCst), waiting, app.state::<crate::AppState>().settings().dock_badge);
    if SHOWN.swap(u64::from(count), Ordering::SeqCst) == u64::from(count) {
        return;
    }
    #[cfg(target_os = "macos")]
    {
        let _ = app.run_on_main_thread(move || mac::draw_badge(badge_label(count)));
    }
}

/// Paths of audio files opened with EchoLedger (pure): file URLs only, whatever their case.
pub fn opened_files(urls: &[tauri::Url]) -> Vec<std::path::PathBuf> {
    urls.iter().filter(|u| u.scheme() == "file").filter_map(|u| u.to_file_path().ok()).collect()
}

/// Audio files opened with EchoLedger (Open With, a drop on the Dock icon): imported as the Import button's files are,
/// and said in the main window.
pub fn opened(app: &AppHandle, files: Vec<std::path::PathBuf>) {
    if files.is_empty() {
        return;
    }
    // The Import button's guard: locked, or a viewer's computer, nothing is imported, and the person is told why.
    if let Err(refusal) = crate::commands::may_import(&app.state::<crate::AppState>()) {
        crate::refusal::say(app, &refusal.to_string(), crate::refusal::Asked::Other);
        return;
    }
    let app = app.clone();
    tauri::async_runtime::spawn_blocking(move || {
        let summary = crate::actions::import_files(&app, &files, None, None);
        crate::actions::toast_to(&app, &import_words(summary.queued, summary.duplicates, summary.unsupported + summary.failed), crate::actions::Target::Page("library"));
        crate::actions::emit_status(&app);
    });
}

/// What an Open With says (pure).
pub fn import_words(queued: usize, duplicates: usize, not_imported: usize) -> String {
    let mut parts = Vec::new();
    match queued {
        0 => {}
        1 => parts.push("1 recording imported".to_string()),
        n => parts.push(format!("{n} recordings imported")),
    }
    if duplicates > 0 {
        parts.push(format!("{duplicates} already in the Library"));
    }
    if not_imported > 0 {
        parts.push(format!("{not_imported} couldn't be imported"));
    }
    if parts.is_empty() { "Nothing was imported".into() } else { parts.join(" · ") }
}

/// One line of the Dock's menu (pure): an item (its menu bar id, its words), a line, or a sub-menu.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum DockLine {
    Item { id: &'static str, label: &'static str },
    Separator,
    Submenu { label: &'static str, items: Vec<DockLine> },
}

/// The Dock's menu for the moment (pure): New Recording or Stop Recording, New Voice Note (Stop Voice Note while one
/// runs), Start Live Assist…, Hide from Screen Shares › (the set every menu has, native_menu::hide_from_shares). A
/// viewer's computer has only the last two.
pub fn dock_lines(recording: bool, mic_note: bool, can_record: bool, hiding: bool) -> Vec<DockLine> {
    let mut lines = Vec::new();
    if can_record || recording {
        lines.push(if recording {
            DockLine::Item { id: "action:live.stopRecording", label: "Stop Recording" }
        } else {
            DockLine::Item { id: "action:file.newRecording", label: "New Recording" }
        });
        if !recording || mic_note {
            lines.push(DockLine::Item { id: "action:file.newVoiceNote", label: if mic_note { "Stop Voice Note" } else { "New Voice Note" } });
        }
    }
    lines.push(DockLine::Item { id: "action:live.startAssist", label: "Start Live Assist…" });
    lines.push(DockLine::Submenu {
        label: crate::native_menu::HIDE_FROM_SHARES_TITLE.1,
        items: crate::native_menu::hide_from_shares(hiding, true)
            .into_iter()
            .map(|line| match line {
                crate::native_menu::HideLine::Item { id, label } => DockLine::Item { id, label },
                crate::native_menu::HideLine::Separator => DockLine::Separator,
            })
            .collect(),
    });
    lines
}

#[cfg(target_os = "macos")]
pub mod mac {
    use std::sync::OnceLock;

    use muda::ContextMenu;
    use objc2::MainThreadMarker;
    use objc2::runtime::{AnyClass, AnyObject, Imp, NSObjectProtocol, Sel};
    use objc2_app_kit::NSApplication;
    use objc2_foundation::NSString;
    use tauri::{AppHandle, Manager};

    use super::{DockLine, dock_lines};

    static APP: OnceLock<AppHandle> = OnceLock::new();
    thread_local! {
        /// The menu last given to the Dock, kept alive while the Dock shows it (on the main thread, where AppKit asks).
        static SHOWN: std::cell::RefCell<Option<muda::Menu>> = const { std::cell::RefCell::new(None) };
    }

    /// Teaches the application delegate to answer `applicationDockMenu:`. On the main thread, after the app is built and
    /// before it runs. True when the answer is in (false when the delegate already has one: an update of tao or Tauri).
    pub fn install(app: &AppHandle) -> bool {
        let _ = APP.set(app.clone());
        let Some(mtm) = MainThreadMarker::new() else { return false };
        let ns_app = NSApplication::sharedApplication(mtm);
        let Some(delegate) = ns_app.delegate() else { return false };
        let object: &AnyObject = AsRef::<AnyObject>::as_ref(&*delegate);
        let class: *const AnyClass = object.class();
        let answer: extern "C-unwind" fn(&AnyObject, Sel, *mut AnyObject) -> *mut AnyObject = dock_menu;
        // SAFETY: `answer` has the signature of `applicationDockMenu:` ("@@:@": an NSMenu from self, _cmd and the sender);
        // a method the class already has is left as it is (class_addMethod refuses to replace one).
        let added = unsafe {
            objc2::ffi::class_addMethod(
                class.cast_mut(),
                objc2::sel!(applicationDockMenu:),
                std::mem::transmute::<extern "C-unwind" fn(&AnyObject, Sel, *mut AnyObject) -> *mut AnyObject, Imp>(answer),
                c"@@:@".as_ptr(),
            )
        };
        if !added.as_bool() {
            eprintln!("[echoledger] the application delegate already answers applicationDockMenu:");
            return false;
        }
        ns_app.setDelegate(Some(&delegate));
        delegate.respondsToSelector(objc2::sel!(applicationDockMenu:))
    }

    /// The Dock's menu, built afresh as it opens.
    extern "C-unwind" fn dock_menu(_delegate: &AnyObject, _cmd: Sel, _sender: *mut AnyObject) -> *mut AnyObject {
        let Some(app) = APP.get() else { return std::ptr::null_mut() };
        let state = app.state::<crate::AppState>();
        let recording = state.capture.info();
        let mic_note = recording.as_ref().is_some_and(|r| r.mode == crate::audio::RecordingMode::MicNote);
        let settings = state.settings();
        let hiding = crate::privacy::share::main_hidden(settings.main_share_hide.as_ref(), crate::privacy::now_ms());
        let lines = dock_lines(recording.is_some(), mic_note, state.viewer_refusal().is_none(), hiding);
        let Ok(menu) = build(&lines) else { return std::ptr::null_mut() };
        let ns_menu = menu.ns_menu().cast::<AnyObject>();
        SHOWN.with(|shown| *shown.borrow_mut() = Some(menu));
        ns_menu
    }

    /// The lines as a menu: each item's id is the menu bar's for its action, so its pick goes through `picked`.
    fn build(lines: &[DockLine]) -> Result<muda::Menu, muda::Error> {
        let item = |id: &str, label: &str| muda::MenuItem::with_id(id, label, true, None);
        let menu = muda::Menu::new();
        for line in lines {
            match line {
                DockLine::Item { id, label } => menu.append(&item(id, label))?,
                DockLine::Separator => menu.append(&muda::PredefinedMenuItem::separator())?,
                DockLine::Submenu { label, items } => {
                    let sub = muda::Submenu::new(*label, true);
                    for line in items {
                        match line {
                            DockLine::Item { id, label } => sub.append(&item(id, label))?,
                            DockLine::Separator => sub.append(&muda::PredefinedMenuItem::separator())?,
                            DockLine::Submenu { .. } => {}
                        }
                    }
                    menu.append(&sub)?;
                }
            }
        }
        Ok(menu)
    }

    /// The Dock icon's number, or none.
    pub fn draw_badge(label: Option<String>) {
        let Some(mtm) = MainThreadMarker::new() else { return };
        let tile = NSApplication::sharedApplication(mtm).dockTile();
        tile.setBadgeLabel(label.map(|l| NSString::from_str(&l)).as_deref());
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_dock_s_number_is_the_rail_s_capped_at_9_plus_and_off_with_its_setting() {
        assert_eq!(badge_label(0), None);
        assert_eq!(badge_label(3).as_deref(), Some("3"));
        assert_eq!(badge_label(9).as_deref(), Some("9"));
        assert_eq!(badge_label(10).as_deref(), Some("9+"));
        assert_eq!(badge_count(2, 1, true), 3, "Activity's problems and the calls waiting");
        assert_eq!(badge_count(2, 1, false), 0, "Show count on the app icon off");
        // The same rule as the page's badge.
        let page = include_str!("../../src/lib/badge.ts");
        assert!(page.contains(r#"return count > 9 ? "9+" : String(Math.floor(count));"#));
    }

    fn words(lines: &[DockLine]) -> Vec<&'static str> {
        lines
            .iter()
            .map(|l| match l {
                DockLine::Item { label, .. } | DockLine::Submenu { label, .. } => *label,
                DockLine::Separator => "---",
            })
            .collect()
    }

    #[test]
    fn the_dock_menu_is_the_plan_s_and_its_picks_go_through_the_menu_bar_s_routing() {
        assert_eq!(words(&dock_lines(false, false, true, false)), ["New Recording", "New Voice Note", "Start Live Assist…", "Hide from Screen Shares"]);
        assert_eq!(words(&dock_lines(true, false, true, false)), ["Stop Recording", "Start Live Assist…", "Hide from Screen Shares"]);
        assert_eq!(words(&dock_lines(true, true, true, false)), ["Stop Recording", "Stop Voice Note", "Start Live Assist…", "Hide from Screen Shares"]);
        assert_eq!(words(&dock_lines(false, false, false, false)), ["Start Live Assist…", "Hide from Screen Shares"], "a viewer records nothing");
        // The one set every menu has.
        let DockLine::Submenu { items, .. } = &dock_lines(false, false, true, false)[3] else { panic!() };
        assert_eq!(words(items), ["For 15 Minutes", "For 1 Hour", "Until I Quit", "Until I Turn It Off"]);
        let DockLine::Submenu { items, .. } = &dock_lines(false, false, true, true)[3] else { panic!() };
        assert_eq!(words(items), ["For 15 Minutes", "For 1 Hour", "Until I Quit", "Until I Turn It Off", "---", "Stop Hiding"]);
        // Every item is the menu bar's id for an action the core runs (picked → Route::Core): the Dock's menu works
        // with the window closed.
        let mut ids = Vec::new();
        fn collect(lines: &[DockLine], into: &mut Vec<&'static str>) {
            for line in lines {
                match line {
                    DockLine::Item { id, .. } => into.push(id),
                    DockLine::Submenu { items, .. } => collect(items, into),
                    DockLine::Separator => {}
                }
            }
        }
        collect(&dock_lines(false, false, true, true), &mut ids);
        collect(&dock_lines(true, true, true, false), &mut ids);
        for id in ids {
            let pick = crate::native_menu::parse_item_id(id).unwrap_or_else(|| panic!("{id}"));
            assert_eq!(crate::native_menu::item_id(&crate::native_menu::Pick::of(&pick.action)).as_deref(), Some(id));
            assert_eq!(crate::native_menu::route(&pick.action, crate::lifecycle::Phase::Absent), crate::native_menu::Route::Core, "{id}");
        }
        // Installed before the app runs, as the quit answer is.
        assert!(include_str!("lib.rs").contains("dock::mac::install(app.handle())"));
    }

    #[test]
    fn open_with_and_the_dock_import_nothing_while_locked_or_on_a_viewer_s_computer() {
        use crate::commands::import_refusal;
        assert_eq!(import_refusal(true, None).as_deref(), Some("EchoLedger is locked."));
        assert_eq!(import_refusal(true, Some("You are a viewer.".into())).as_deref(), Some("EchoLedger is locked."), "locked first");
        assert_eq!(import_refusal(false, Some("You are a viewer.".into())).as_deref(), Some("You are a viewer."));
        assert_eq!(import_refusal(false, None), None);
        // One guard for every import: the Import button's command and Open With / a drop on the Dock icon.
        let commands = include_str!("commands.rs");
        let import = &commands[commands.find("pub async fn import_voice_notes(").unwrap()..];
        assert!(import[..import.find("\n}\n").unwrap()].contains("may_import(&app.state::<AppState>())?;"));
        let code = include_str!("dock.rs").split("#[cfg(test)]").next().unwrap();
        let opened = &code[code.find("pub fn opened(app: &AppHandle").unwrap()..];
        let opened = &opened[..opened.find("\n}\n").unwrap()];
        let guard = opened.find("crate::commands::may_import(").expect("guarded");
        assert!(guard < opened.find("import_files(").unwrap(), "before anything is imported");
        assert!(opened.contains("crate::refusal::say(app, &refusal.to_string()"), "and said");
    }

    #[test]
    fn muda_is_the_one_tauri_uses() {
        let lock = include_str!("../Cargo.lock");
        assert_eq!(lock.matches("\nname = \"muda\"\n").count(), 1, "Cargo.lock lists muda once: the Dock's menu and Tauri share it");
    }

    #[test]
    fn audio_files_opened_with_echoledger_are_imported_and_said() {
        let urls: Vec<tauri::Url> = ["file:///Users/me/Call%20with%20Bilal.m4a", "echoledger://open", "https://example.com/a.mp3"].iter().map(|u| tauri::Url::parse(u).unwrap()).collect();
        assert_eq!(opened_files(&urls), [std::path::PathBuf::from("/Users/me/Call with Bilal.m4a")]);
        assert_eq!(import_words(2, 0, 0), "2 recordings imported");
        assert_eq!(import_words(1, 1, 1), "1 recording imported · 1 already in the Library · 1 couldn't be imported");
        assert_eq!(import_words(0, 0, 0), "Nothing was imported");
        // Registered as an alternate handler only, never the default player, for the plan's types: on the Mac only
        // (tauri.macos.conf.json). Windows' installer would make a type's default its own; Windows adds EchoLedger to
        // Open with by itself instead (FIX-12).
        let base: serde_json::Value = serde_json::from_str(include_str!("../tauri.conf.json")).unwrap();
        assert!(base["bundle"].get("fileAssociations").is_none(), "never for every platform's installer");
        let conf: serde_json::Value = serde_json::from_str(include_str!("../tauri.macos.conf.json")).unwrap();
        let associations = conf["bundle"]["fileAssociations"].as_array().expect("file associations");
        let audio = &associations[0];
        assert_eq!(audio["rank"], "Alternate");
        assert_eq!(audio["role"], "Viewer");
        let ext: Vec<&str> = audio["ext"].as_array().unwrap().iter().map(|e| e.as_str().unwrap()).collect();
        assert_eq!(ext, ["m4a", "mp3", "wav", "aac", "ogg", "opus", "amr", "flac", "webm"]);
        let lib = include_str!("lib.rs");
        assert!(lib.contains("dock::opened(handle, dock::opened_files(urls));"));
    }
}
