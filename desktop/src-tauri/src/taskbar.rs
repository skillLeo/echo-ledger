//! FIX-12 (PLAN_V2_1_POLISH): EchoLedger on Windows' taskbar and in its notification area.
//!
//! - The taskbar's right click (Jump List): a fixed Tasks list, New recording · New voice note · Import audio files… ·
//!   Start Live Assist, each starting EchoLedger.exe with `--action=…`; the single-instance plugin hands a second start's
//!   arguments to the running app, a first start reads its own. Each runs as the menu bar's command does
//!   (`native_menu::picked`), so the core records with the window closed.
//! - The taskbar icon: a small red dot while recording (never animated), and the progress of a long import.
//! - Open with: the audio types EchoLedger takes are added to Explorer's Open with (HKCU …\OpenWithProgids), never made
//!   EchoLedger's by default; a file opened that way is imported as the Import button's (`dock::opened`, its guard).
//! - Closing: ✕ keeps EchoLedger in the notification area (said once, with how to quit), or quits as "When I close the
//!   window" says; once after install, a tip that Windows 11 hides new tray icons.

use std::path::PathBuf;

use tauri::{AppHandle, Manager};

/// The Jump List's Tasks, in order: the `--action=` value, Windows' words, and the command it runs.
pub const JUMP_TASKS: [(&str, &str, &str); 4] = [
    ("new-recording", "New recording", "file.newRecording"),
    ("new-voice-note", "New voice note", "file.newVoiceNote"),
    ("import", "Import audio files…", "file.importAudio"),
    ("live-assist", "Start Live Assist", "live.startAssist"),
];

/// The command a start's arguments ask for (pure): `--action=new-recording` and so on; none without one.
pub fn jump_action(args: &[String]) -> Option<&'static str> {
    let wanted = args.iter().find_map(|a| a.strip_prefix("--action="))?;
    JUMP_TASKS.iter().find(|(arg, _, _)| *arg == wanted).map(|(_, _, action)| *action)
}

/// Runs a Jump List task as the menu bar's command (its routing: the core's commands with the window closed, the
/// window's own opened quietly). True when the arguments held one.
pub fn run_jump_task(app: &AppHandle, args: &[String]) -> bool {
    let Some(action) = jump_action(args) else { return false };
    let Some(id) = crate::native_menu::item_id(&crate::native_menu::Pick::of(action)) else { return false };
    let app = app.clone();
    tauri::async_runtime::spawn(async move {
        if let Err(e) = crate::native_menu::picked(&app, &id).await {
            crate::refusal::say(&app, &e.to_string(), crate::refusal::Asked::Other);
        }
    });
    true
}

/// The audio types Open with offers EchoLedger for (the plan's list, the Mac's too: tauri.macos.conf.json).
pub const AUDIO_TYPES: [&str; 9] = ["m4a", "mp3", "wav", "aac", "ogg", "opus", "amr", "flac", "webm"];

/// Files a start was handed (Explorer's Open with): existing files of a type EchoLedger takes (pure but for the check
/// that they exist).
pub fn opened_files(args: &[String]) -> Vec<PathBuf> {
    args.iter()
        .skip(1)
        .filter(|a| !a.starts_with('-'))
        .map(PathBuf::from)
        .filter(|p| p.extension().and_then(|e| e.to_str()).is_some_and(|e| AUDIO_TYPES.contains(&e.to_ascii_lowercase().as_str())))
        .filter(|p| p.is_file())
        .collect()
}

/// The registry values that add EchoLedger to Open with for the audio types, for this user only (pure): a ProgID that
/// opens a file with EchoLedger, and that ProgID under each type's OpenWithProgids. Never a type's default (its own
/// default value is not touched), so the person's player stays theirs.
pub fn open_with_values(exe: &str) -> Vec<(String, String, String)> {
    let prog_id = "EchoLedger.Audio";
    let mut values = vec![
        (format!(r"Software\Classes\{prog_id}"), String::new(), "Audio to transcribe with EchoLedger".into()),
        (format!(r"Software\Classes\{prog_id}\shell\open\command"), String::new(), format!("\"{exe}\" \"%1\"")),
    ];
    for ext in AUDIO_TYPES {
        values.push((format!(r"Software\Classes\.{ext}\OpenWithProgids"), prog_id.into(), String::new()));
    }
    values
}

/// What ✕ on the main window does (pure): kept in the notification area (said the first time), or quit.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum OnClose {
    Keep { first_time: bool },
    Quit,
}

pub fn on_close(quit: bool, told_before: bool) -> OnClose {
    if quit { OnClose::Quit } else { OnClose::Keep { first_time: !told_before } }
}

/// The notification the first ✕ gives (Windows).
pub const STILL_RUNNING: (&str, &str) = (
    "EchoLedger is still running",
    "It stays in the notification area so calls are still recorded. To quit, right-click its icon and choose Quit.",
);

/// The one-time tip after install (Windows 11 hides new tray icons).
pub const TRAY_TIP: (&str, &str) = (
    "Keep EchoLedger's icon in view",
    "Windows hides new icons: drag EchoLedger's icon from ^ onto the taskbar to always see when it records.",
);

/// ✕ on the main window (Windows): the setting decides. Quit: the close is held and EchoLedger quits as the tray's
/// Quit does (asking first while recording). Keep: the window closes, EchoLedger stays, and the first time says so.
/// True when the close goes on.
pub fn main_close_requested(app: &AppHandle) -> bool {
    let state = app.state::<crate::AppState>();
    let settings = state.settings();
    match on_close(settings.close_quits, settings.close_tip_shown) {
        OnClose::Quit => {
            let app = app.clone();
            tauri::async_runtime::spawn(async move { crate::app_life::quit(&app).await });
            false
        }
        OnClose::Keep { first_time } => {
            if first_time {
                let (title, body) = STILL_RUNNING;
                crate::notifications::send(app, crate::notifications::Category::Other, title, body);
                let _ = state.update_settings(|s| s.close_tip_shown = true);
            }
            true
        }
    }
}

/// Once after install (Windows): the tray tip.
pub fn tray_tip_once(app: &AppHandle) {
    let state = app.state::<crate::AppState>();
    if state.settings().tray_tip_shown {
        return;
    }
    let (title, body) = TRAY_TIP;
    crate::notifications::send(app, crate::notifications::Category::Other, title, body);
    let _ = state.update_settings(|s| s.tray_tip_shown = true);
}

/// The taskbar's progress for an import of several files (n of m), or none when done (Windows; never on a Mac, whose
/// Dock shows no progress of EchoLedger's).
pub fn import_progress(app: &AppHandle, done: usize, total: usize) {
    #[cfg(windows)]
    {
        use tauri::window::{ProgressBarState, ProgressBarStatus};
        let Some(window) = app.get_webview_window(crate::lifecycle::MAIN) else { return };
        let state = match progress_percent(done, total) {
            Some(percent) => ProgressBarState { status: Some(ProgressBarStatus::Normal), progress: Some(percent) },
            None => ProgressBarState { status: Some(ProgressBarStatus::None), progress: None },
        };
        let _ = window.set_progress_bar(state);
    }
    #[cfg(not(windows))]
    let _ = (app, done, total);
}

/// An import's progress in percent (pure): none for one file, or once all are done.
pub fn progress_percent(done: usize, total: usize) -> Option<u64> {
    (total > 1 && done < total).then(|| (done * 100 / total) as u64)
}

/// The taskbar icon's small red dot while recording, none otherwise (Windows). Drawn when the recording starts or ends
/// and when the main window is built; never animated.
pub fn overlay(app: &AppHandle, recording: bool) {
    #[cfg(windows)]
    {
        let Some(window) = app.get_webview_window(crate::lifecycle::MAIN) else { return };
        let _ = window.set_overlay_icon(recording.then(red_dot));
    }
    #[cfg(not(windows))]
    let _ = (app, recording);
}

/// The overlay's picture (pure): a 16 × 16 red dot, the recording red the tray uses, on clear.
pub fn red_dot_rgba() -> Vec<u8> {
    let [r, g, b, _] = crate::tray::dot_colour(crate::tray::TrayState::Recording).unwrap_or([229, 72, 77, 255]);
    let mut rgba = vec![0u8; 16 * 16 * 4];
    for y in 0..16 {
        for x in 0..16 {
            let (dx, dy) = (x as f32 - 7.5, y as f32 - 7.5);
            let inside = (dx * dx + dy * dy).sqrt() <= 6.5;
            if inside {
                let at = (y * 16 + x) * 4;
                rgba[at..at + 4].copy_from_slice(&[r, g, b, 255]);
            }
        }
    }
    rgba
}

#[cfg(windows)]
fn red_dot() -> tauri::image::Image<'static> {
    tauri::image::Image::new_owned(red_dot_rgba(), 16, 16)
}

/// Windows: the Jump List's Tasks and Open with, set at each start (both idempotent), on a thread of their own (COM).
pub fn register(app: &AppHandle) {
    #[cfg(windows)]
    {
        let _ = app;
        std::thread::spawn(|| {
            if let Err(e) = win::set_jump_list() {
                eprintln!("[echoledger] the taskbar's Jump List was not set: {e}");
            }
            if let Err(e) = win::add_open_with() {
                eprintln!("[echoledger] Open with was not registered: {e}");
            }
        });
    }
    #[cfg(not(windows))]
    let _ = app;
}

#[cfg(windows)]
mod win {
    use windows::Win32::Foundation::PROPERTYKEY;
    use windows::Win32::System::Com::StructuredStorage::PROPVARIANT;
    use windows::Win32::System::Com::{CLSCTX_INPROC_SERVER, COINIT_APARTMENTTHREADED, CoCreateInstance, CoInitializeEx, CoUninitialize};
    use windows::Win32::System::Registry::{HKEY_CURRENT_USER, REG_SZ, RegSetKeyValueW};
    use windows::Win32::UI::Shell::Common::{IObjectArray, IObjectCollection};
    use windows::Win32::UI::Shell::PropertiesSystem::IPropertyStore;
    use windows::Win32::UI::Shell::{DestinationList, EnumerableObjectCollection, ICustomDestinationList, IShellLinkW, SHCNE_ASSOCCHANGED, SHCNF_IDLIST, SHChangeNotify, ShellLink};
    use windows::core::{GUID, HSTRING, Interface};

    use super::{JUMP_TASKS, open_with_values};

    /// PKEY_Title, the words a Jump List task shows.
    const PKEY_TITLE: PROPERTYKEY = PROPERTYKEY { fmtid: GUID::from_u128(0xf29f85e0_4ff9_1068_ab91_08002b27b3d9), pid: 2 };

    fn exe() -> windows::core::Result<String> {
        std::env::current_exe().map(|p| p.to_string_lossy().into_owned()).map_err(|_| windows::core::Error::from_thread())
    }

    pub fn set_jump_list() -> windows::core::Result<()> {
        // SAFETY: COM on this thread of our own, initialised here and uninitialised at its end; every interface is
        // created and released through the windows crate's owned wrappers.
        unsafe {
            let _ = CoInitializeEx(None, COINIT_APARTMENTTHREADED);
            let result = (|| {
                let exe = exe()?;
                let list: ICustomDestinationList = CoCreateInstance(&DestinationList, None, CLSCTX_INPROC_SERVER)?;
                let mut slots = 0u32;
                let _removed: IObjectArray = list.BeginList(&mut slots)?;
                let tasks: IObjectCollection = CoCreateInstance(&EnumerableObjectCollection, None, CLSCTX_INPROC_SERVER)?;
                for (arg, title, _) in JUMP_TASKS {
                    let link: IShellLinkW = CoCreateInstance(&ShellLink, None, CLSCTX_INPROC_SERVER)?;
                    link.SetPath(&HSTRING::from(exe.as_str()))?;
                    link.SetArguments(&HSTRING::from(format!("--action={arg}").as_str()))?;
                    link.SetIconLocation(&HSTRING::from(exe.as_str()), 0)?;
                    link.SetDescription(&HSTRING::from(title))?;
                    let store: IPropertyStore = link.cast()?;
                    store.SetValue(&PKEY_TITLE, &PROPVARIANT::from(title))?;
                    store.Commit()?;
                    tasks.AddObject(&link)?;
                }
                let array: IObjectArray = tasks.cast()?;
                list.AddUserTasks(&array)?;
                list.CommitList()
            })();
            CoUninitialize();
            result
        }
    }

    pub fn add_open_with() -> windows::core::Result<()> {
        let exe = exe()?;
        for (path, name, value) in open_with_values(&exe) {
            let wide: Vec<u16> = value.encode_utf16().chain(std::iter::once(0)).collect();
            // SAFETY: one registry value under this user's hive (the key is made when missing); `wide` outlives the call
            // and its byte length includes the closing zero, as REG_SZ wants.
            unsafe {
                RegSetKeyValueW(HKEY_CURRENT_USER, &HSTRING::from(path.as_str()), &HSTRING::from(name.as_str()), REG_SZ.0, Some(wide.as_ptr().cast()), (wide.len() * 2) as u32).ok()?;
            }
        }
        // SAFETY: tells Explorer the associations changed, so Open with lists EchoLedger at once.
        unsafe { SHChangeNotify(SHCNE_ASSOCCHANGED, SHCNF_IDLIST, None, None) };
        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_jump_list_s_tasks_run_the_menu_bar_s_commands() {
        let args = |a: &str| vec!["EchoLedger.exe".to_string(), a.to_string()];
        assert_eq!(jump_action(&args("--action=new-recording")), Some("file.newRecording"));
        assert_eq!(jump_action(&args("--action=new-voice-note")), Some("file.newVoiceNote"));
        assert_eq!(jump_action(&args("--action=import")), Some("file.importAudio"));
        assert_eq!(jump_action(&args("--action=live-assist")), Some("live.startAssist"));
        assert_eq!(jump_action(&args("--action=format-disk")), None);
        assert_eq!(jump_action(&args("--autostart")), None);
        assert_eq!(JUMP_TASKS.map(|(_, title, _)| title), ["New recording", "New voice note", "Import audio files…", "Start Live Assist"]);
        // Each is a command of the action list, with the menu bar's id; recording ones run in the core.
        for (_, _, action) in JUMP_TASKS {
            assert!(crate::native_menu::item_id(&crate::native_menu::Pick::of(action)).is_some(), "{action}");
        }
        assert_eq!(crate::native_menu::route("file.newRecording", crate::lifecycle::Phase::Absent), crate::native_menu::Route::Core);
        // A second start hands its arguments over; a first start reads its own.
        let actions = include_str!("actions.rs");
        let outside = &actions[actions.find("pub fn show_main_from_outside(").unwrap()..];
        assert!(outside[..outside.find("\n}\n").unwrap()].contains("crate::taskbar::run_jump_task(app, args)"));
        assert!(include_str!("lib.rs").contains("taskbar::run_jump_task(&handle, &args)"));
    }

    #[test]
    fn open_with_adds_echoledger_for_the_audio_types_and_never_takes_a_default() {
        let values = open_with_values(r"C:\Users\me\AppData\Local\EchoLedger\EchoLedger.exe");
        assert_eq!(values[1], (r"Software\Classes\EchoLedger.Audio\shell\open\command".to_string(), String::new(), "\"C:\\Users\\me\\AppData\\Local\\EchoLedger\\EchoLedger.exe\" \"%1\"".to_string()));
        let open_with: Vec<&str> = values.iter().filter(|(path, _, _)| path.ends_with("OpenWithProgids")).map(|(path, _, _)| path.as_str()).collect();
        assert_eq!(open_with.len(), AUDIO_TYPES.len());
        assert!(open_with.contains(&r"Software\Classes\.m4a\OpenWithProgids"));
        // A type's own default value (its handler) is never written: only the ProgID's name under OpenWithProgids.
        assert!(values.iter().all(|(path, name, _)| !path.starts_with(r"Software\Classes\.") || (path.ends_with("OpenWithProgids") && name == "EchoLedger.Audio")));
        // The same types as the Mac's association.
        let mac: serde_json::Value = serde_json::from_str(include_str!("../tauri.macos.conf.json")).unwrap();
        let ext: Vec<&str> = mac["bundle"]["fileAssociations"][0]["ext"].as_array().unwrap().iter().map(|e| e.as_str().unwrap()).collect();
        assert_eq!(ext, AUDIO_TYPES);
        // A start with a file imports it through the Import button's guard.
        assert!(include_str!("actions.rs").contains("crate::dock::opened(app, crate::taskbar::opened_files(args))"));
    }

    #[test]
    fn a_start_from_open_with_hands_over_its_existing_audio_files_only() {
        // This platform's own absolute paths (Explorer hands "C:\…\Call with Bilal.M4A" as one argument).
        let dir = std::env::temp_dir().join(format!("echoledger-open-with-{}", std::process::id()));
        std::fs::create_dir_all(&dir).unwrap();
        let audio = dir.join("Call with Bilal.M4A");
        let notes = dir.join("notes.txt");
        std::fs::write(&audio, b"audio").unwrap();
        std::fs::write(&notes, b"text").unwrap();
        let gone = dir.join("gone.mp3");
        let arg = |p: &std::path::Path| p.to_string_lossy().into_owned();
        let args = vec!["EchoLedger.exe".to_string(), "--autostart".into(), arg(&audio), arg(&notes), arg(&gone)];
        let opened = opened_files(&args);
        let _ = std::fs::remove_dir_all(&dir);
        assert_eq!(opened, [audio], "an audio type whatever its case; never a flag, another type or a file not there");
        assert!(opened_files(&["EchoLedger.exe".to_string(), "--action=import".into()]).is_empty());
    }

    #[test]
    fn closing_keeps_echoledger_in_the_notification_area_or_quits_as_the_setting_says() {
        assert_eq!(on_close(false, false), OnClose::Keep { first_time: true }, "the first time it says so");
        assert_eq!(on_close(false, true), OnClose::Keep { first_time: false });
        assert_eq!(on_close(true, false), OnClose::Quit);
        assert!(STILL_RUNNING.1.contains("To quit, right-click its icon and choose Quit."));
        assert!(TRAY_TIP.1.contains("drag EchoLedger's icon from ^ onto the taskbar"));
        // Quit asks first while recording, as the tray's Quit does.
        let app_life = include_str!("app_life.rs");
        let quit = &app_life[app_life.find("pub async fn quit(app: &tauri::AppHandle)").unwrap()..];
        assert!(quit[..quit.find("\n}\n").unwrap()].contains("if !may_quit(app).await"));
        // Wired on Windows only: a Mac's red button always keeps EchoLedger running.
        let lib = include_str!("lib.rs");
        assert!(lib.contains("#[cfg(windows)]\n        WindowEvent::CloseRequested { api, .. } if window.label() == lifecycle::MAIN =>"));
    }

    #[test]
    fn the_taskbar_shows_a_still_red_dot_while_recording_and_an_import_s_progress() {
        let rgba = red_dot_rgba();
        assert_eq!(rgba.len(), 16 * 16 * 4);
        let pixel = |x: usize, y: usize| &rgba[(y * 16 + x) * 4..(y * 16 + x) * 4 + 4];
        assert_eq!(pixel(0, 0)[3], 0, "clear corners");
        assert_eq!(pixel(8, 8)[3], 255, "a solid dot");
        assert_eq!(&pixel(8, 8)[..3], &crate::tray::dot_colour(crate::tray::TrayState::Recording).unwrap()[..3], "the recording red");
        assert_eq!(progress_percent(0, 1), None, "one file: no bar");
        assert_eq!(progress_percent(1, 4), Some(25));
        assert_eq!(progress_percent(4, 4), None, "done: the bar goes");
        let actions = include_str!("actions.rs");
        assert!(actions.contains("crate::taskbar::import_progress(app, done, sources.len());"));
        assert!(include_str!("tray.rs").contains("crate::taskbar::overlay(app, recording);"));
    }
}
