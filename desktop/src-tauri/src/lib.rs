//! EchoLedger desktop. Tray app: dual-channel capture, live captions overlay, dictation, auto mode, uploads.

pub mod account;
mod actions;
pub mod activity;
pub mod after_call;
pub mod app_menu;
pub mod audio;
pub mod call_guard;
mod commands;
pub mod deep_link;
pub mod detect;
pub mod dictation;
pub mod error;
pub mod files;
pub mod lifecycle;
pub mod lock;
pub mod mac_check;
pub mod material;
pub mod net;
pub mod notifications;
pub mod overlay;
pub mod owner;
pub mod pending;
pub mod permissions;
pub mod plan;
pub mod projects;
pub mod readiness;
pub mod recovery;
pub mod refusal;
pub mod screens;
pub mod settings;
pub mod sharing;
pub mod shortcuts;
pub mod skipped;
pub mod smoke;
pub mod stt;
pub mod translate;
pub mod tray;
pub mod updates;

use std::path::PathBuf;
use std::sync::atomic::AtomicBool;
use std::sync::{Mutex, MutexGuard};
use std::time::Instant;

use tauri::{Manager, RunEvent, WindowEvent};
use tauri_plugin_autostart::MacosLauncher;

use error::{AppError, AppResult};
use net::api::ApiClient;
use net::keychain::{KeyringStore, MemoryStore, TokenStore};
use net::models::ServerConfig;
use net::sync::{LibraryCache, paths};
use settings::Settings;

pub struct AppState {
    pub data_dir: PathBuf,
    settings: Mutex<Settings>,
    pub token: Mutex<Option<String>>,
    pub token_store: Box<dyn TokenStore>,
    pub server_config: Mutex<Option<ServerConfig>>,
    pub capture: audio::CaptureController,
    pub live: stt::LiveCaptions,
    pub dictation_live: stt::LiveCaptions,
    pub captions: Mutex<stt::captions::CaptionBuffer>,
    pub dictation_text: Mutex<Vec<String>>,
    pub dictation: Mutex<Option<audio::MicStream>>,
    pub dictation_stop_requested: AtomicBool,
    pub auto_started: AtomicBool,
    pub recording_started_at: Mutex<Option<Instant>>,
    pub library: tokio::sync::Mutex<LibraryCache>,
    pub upload_notify: tokio::sync::Notify,
    pub queue_lock: Mutex<()>,
    pub shortcut_errors: Mutex<Vec<(shortcuts::Action, String)>>,
    /// Auto-mode detection: last observation for the explainer, call/ask trackers, open unknown-app chips.
    pub detect: detect::DetectState,
    /// The one HTTP client, rebuilt only when server URL, pins or token change (A6.DR15).
    api_cache: net::api::ApiClientCache,
    /// Set by a `403 budget_exceeded`; cleared by the next config fetch (`AppStatus.budget`).
    pub budget_blocked: AtomicBool,
    /// Where a captions server session belongs while a recording runs and stops (A5.1).
    pub session_link: Mutex<pending::SessionLink>,
    /// The recording pill is hidden by the user; the recording continues (A3.3).
    pub pill_hidden: AtomicBool,
    /// Captions were on when the recording was paused, so resume reopens them (sockets close on pause, A5.3).
    pub captions_on_resume: AtomicBool,
    /// The after-call window (v1.5): the Saved toast, the chip, and what Undo needs.
    pub after_call: Mutex<after_call::Surfaces>,
    /// The current recording's microphone is Bluetooth (v1.5): the pill says "Accuracy is lower with Bluetooth mics".
    pub bluetooth_mic: AtomicBool,
    /// The once-a-second `recording:state` task is running.
    pub ticker_running: AtomicBool,
    /// A test recording (B5) is running.
    pub capture_test: AtomicBool,
    /// App lock (B16): locked at start when the lock is on.
    pub lock: lock::Lock,
    /// The recording of the last OS notification, opened when that notification brings the app forward (B18).
    pub notified: Mutex<Option<(String, Instant)>>,
    /// The language chip of the running recording (its client_ref and the choice, none = automatic), set on the pill.
    pub recording_language: Mutex<Option<(String, Option<String>)>>,
    /// The chip changes of the running recording (its client_ref and the marks), sent with the upload (v1.2).
    pub recording_language_marks: Mutex<Option<(String, Vec<net::models::LanguageMarkDto>)>>,
    /// Caller names (since v1.2): who the running recording is with (its client reference and the caller).
    pub recording_caller: Mutex<Option<(String, net::models::CallerDto)>>,
    /// Screen moments (v1.5): the capture beside a call recording, the key that seals stored screens, what the plan
    /// and the server said.
    pub screens: screens::Screens,
    /// A browser sign-in waiting for its `echoledger://signed-in` link (v1.5), in memory only.
    pub browser_sign_in: Mutex<Option<account::BrowserSignIn>>,
    /// The account the running recording was made under, when another account signed in while it ran (its client
    /// reference and the account).
    pub recording_owner: Mutex<Option<(String, owner::Owner)>>,
    /// The server said who this computer is signed in as (or that it has no accounts) this run.
    pub owner_checked: AtomicBool,
    /// Signed updates from the server (v1.5): the status, the one running job, the update ready to install.
    pub updates: updates::Updates,
    /// What the system does not let the running recording use, and what it holds without it (Phase 1B).
    pub recording_missing: Mutex<Vec<permissions::Missing>>,
    /// Which permissions were granted last time, and what the person has not been told is gone yet (Phase 1B).
    pub permission_memory: Mutex<permissions::Memory>,
    /// The devices of the running recording: a channel without one, or news of a change (Phase 1B).
    pub device_status: Mutex<audio::devices::DeviceStatus>,
    /// The last Mac check of this run (Phase 1B).
    pub mac_check: Mutex<Option<mac_check::CheckReport>>,
    /// The last Ready check (Phase 1C), what had broken at it, and the wake-up for a check now.
    pub ready: Mutex<Option<readiness::ReadyReport>>,
    pub ready_watch: Mutex<readiness::ReadyWatch>,
    pub ready_wake: tokio::sync::Notify,
    /// Recordings whose transcript the person asked to be told about ("Notify me when ready", Phase 1C).
    pub notify_ready: Mutex<std::collections::HashSet<String>>,
}

impl AppState {
    /// `smoke`: the smoke test's state, with nothing that reads the keychain.
    fn load(data_dir: PathBuf, smoke: bool) -> Self {
        let settings = Settings::load(&data_dir.join("settings.json"));
        let token_store: Box<dyn TokenStore> = if smoke { Box::new(MemoryStore::default()) } else { Box::new(KeyringStore) };
        let token = token_store.load().unwrap_or_else(|e| {
            eprintln!("[echoledger] keychain unavailable: {e}");
            None
        });
        let library = LibraryCache::load(&paths::library(&data_dir));
        let settings_locked = settings.app_lock && lock::LockFile::load(&paths::app_lock(&data_dir)).pin_hash.is_some();
        let permission_memory = permissions::Memory::load(&permissions::Memory::path(&data_dir));
        Self {
            data_dir,
            settings: Mutex::new(settings),
            token: Mutex::new(token),
            token_store,
            server_config: Mutex::new(None),
            capture: audio::CaptureController::default(),
            live: stt::LiveCaptions::default(),
            dictation_live: stt::LiveCaptions::default(),
            captions: Mutex::new(stt::captions::CaptionBuffer::default()),
            dictation_text: Mutex::new(Vec::new()),
            dictation: Mutex::new(None),
            dictation_stop_requested: AtomicBool::new(false),
            auto_started: AtomicBool::new(false),
            recording_started_at: Mutex::new(None),
            library: tokio::sync::Mutex::new(library),
            upload_notify: tokio::sync::Notify::new(),
            queue_lock: Mutex::new(()),
            shortcut_errors: Mutex::new(Vec::new()),
            detect: detect::DetectState::default(),
            api_cache: net::api::ApiClientCache::default(),
            budget_blocked: AtomicBool::new(false),
            session_link: Mutex::new(pending::SessionLink::default()),
            pill_hidden: AtomicBool::new(false),
            captions_on_resume: AtomicBool::new(false),
            after_call: Mutex::new(after_call::Surfaces::default()),
            bluetooth_mic: AtomicBool::new(false),
            ticker_running: AtomicBool::new(false),
            capture_test: AtomicBool::new(false),
            lock: lock::Lock::new(settings_locked),
            notified: Mutex::new(None),
            recording_language: Mutex::new(None),
            recording_language_marks: Mutex::new(None),
            recording_caller: Mutex::new(None),
            screens: screens::Screens::new(Box::new(screens::platform::Native), if smoke { Box::new(smoke::NoKeys) } else { Box::new(screens::store::KeychainKeys) }),
            browser_sign_in: Mutex::new(None),
            recording_owner: Mutex::new(None),
            owner_checked: AtomicBool::new(false),
            updates: updates::Updates::default(),
            recording_missing: Mutex::new(Vec::new()),
            permission_memory: Mutex::new(permission_memory),
            device_status: Mutex::new(audio::devices::DeviceStatus::default()),
            mac_check: Mutex::new(None),
            ready: Mutex::new(None),
            ready_watch: Mutex::new(readiness::ReadyWatch::default()),
            ready_wake: tokio::sync::Notify::new(),
            notify_ready: Mutex::new(std::collections::HashSet::new()),
        }
    }

    pub fn settings(&self) -> Settings {
        self.lock_settings().clone()
    }

    /// The language of a recording: its chip when one was chosen on the pill, else the app's transcription language
    /// (none: the server uses the client's or the console default).
    pub fn recording_language(&self, client_ref: &str) -> Option<String> {
        match &*self.recording_language.lock().unwrap_or_else(|p| p.into_inner()) {
            Some((r, language)) if r == client_ref => language.clone(),
            _ => self.lock_settings().transcription_language.clone(),
        }
    }

    fn lock_settings(&self) -> MutexGuard<'_, Settings> {
        self.settings.lock().unwrap_or_else(|p| p.into_inner())
    }

    pub fn update_settings(&self, f: impl FnOnce(&mut Settings)) -> AppResult<Settings> {
        let mut guard = self.lock_settings();
        f(&mut guard);
        guard.save(&self.data_dir.join("settings.json"))?;
        Ok(guard.clone())
    }

    pub fn is_paired(&self) -> bool {
        self.token.lock().map(|t| t.is_some()).unwrap_or(false) && self.lock_settings().server_url.is_some()
    }

    /// What the system allows now, taken into the memory of what was granted (a permission that went is said once).
    pub fn permissions_now(&self) -> permissions::Permissions {
        let now = permissions::read();
        if cfg!(target_os = "macos") {
            let screens_wanted = self.lock_settings().screen_moments;
            let mut memory = self.permission_memory.lock().unwrap_or_else(|p| p.into_inner());
            let next = memory.observe(&now, screens_wanted);
            if next != *memory {
                next.save(&permissions::Memory::path(&self.data_dir));
                *memory = next;
            }
        }
        now
    }

    /// The account queued work without an owner belongs to (v1.5, see `owner`).
    pub fn owner(&self) -> Option<owner::Owner> {
        owner::current(&self.data_dir, self.lock_settings().server_url.as_deref())
    }

    pub fn api(&self) -> AppResult<ApiClient> {
        let settings = self.settings();
        let url = settings.server_url.ok_or(AppError::NotPaired)?;
        let token = self.token.lock().unwrap().clone().ok_or(AppError::NotPaired)?;
        self.api_cache.get(&url, &settings.pins, &token)
    }

    /// Stores a freshly fetched server config: rotates the pins when the server sent a usable set, and lifts a
    /// budget block (the config's `usage` is authoritative again).
    pub fn apply_config(&self, config: ServerConfig) {
        if !net::pinning::parse_pins(&config.pins).is_empty() && self.settings().pins != config.pins {
            let pins = config.pins.clone();
            let _ = self.update_settings(|s| s.pins = pins);
        }
        self.budget_blocked.store(false, std::sync::atomic::Ordering::SeqCst);
        self.screens.note_config(&config);
        *self.server_config.lock().unwrap_or_else(|p| p.into_inner()) = Some(config);
    }

    /// What the workspace's plan and the person's role allow, from the last server config (v1.5).
    pub fn plan_info(&self) -> plan::PlanInfo {
        let config = self.server_config.lock().unwrap_or_else(|p| p.into_inner());
        plan::PlanInfo::from_config(config.as_ref(), self.screens.in_plan())
    }

    /// A viewer's computer records nothing (v1.5): the sentence the app shows instead, or none when it may record.
    pub fn viewer_refusal(&self) -> Option<String> {
        let info = self.plan_info();
        (!info.can_record()).then(|| account::viewer_sentence(info.workspace.as_deref()))
    }

    /// True when the last server config says the server speaks the v1.1 contract.
    pub fn server_is_v1_1(&self) -> bool {
        self.server_config.lock().unwrap_or_else(|p| p.into_inner()).as_ref().is_some_and(ServerConfig::is_v1_1)
    }

    /// Caption channel policy from the last server config ("both" | "client_only" | "off").
    pub fn server_caption_channels(&self) -> String {
        self.server_config.lock().unwrap().as_ref().map(|c| c.captions.channels.clone()).filter(|c| !c.is_empty()).unwrap_or_else(|| "both".into())
    }
}

#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    // Explicit crypto provider before any TLS use (reqwest/websocket would otherwise disagree).
    let _ = rustls::crypto::ring::default_provider().install_default();

    // The smoke test runs beside a running EchoLedger instead of handing itself over to it.
    let smoke_dir = smoke::requested(std::env::args(), std::env::var(smoke::ENV).ok()).then(smoke::begin);
    let mut builder = tauri::Builder::default();
    if smoke_dir.is_none() {
        builder = builder.plugin(tauri_plugin_single_instance::init(|app, argv, _cwd| actions::show_main_from_outside(app, &argv))); // must be first
    }
    // ⌘, ⌘W ⌘Q and the Edit keys of a Mac. Windows has no app menu: its windows would grow a menu bar.
    #[cfg(target_os = "macos")]
    {
        builder = builder.menu(app_menu::build);
    }
    let app = builder
        .plugin(shortcuts::plugin())
        .plugin(tauri_plugin_autostart::init(MacosLauncher::LaunchAgent, Some(vec!["--autostart"])))
        .plugin(tauri_plugin_notification::init())
        .plugin(tauri_plugin_positioner::init())
        .plugin(tauri_plugin_store::Builder::new().build())
        .plugin(tauri_plugin_opener::init())
        .plugin(tauri_plugin_clipboard_manager::init())
        .plugin(tauri_plugin_updater::Builder::new().build())
        .manage(overlay::HitRegions::default())
        .manage(lifecycle::WindowLifecycle::default())
        .manage(shortcuts::Bindings::default())
        .manage(stt::captions::CaptionHub::default())
        .setup(move |app| {
            let smoke_test = smoke_dir.is_some();
            let data_dir = match smoke_dir {
                Some(dir) => dir,
                None => app.path().app_data_dir()?,
            };
            std::fs::create_dir_all(paths::recordings(&data_dir))?;
            app.manage(AppState::load(data_dir, smoke_test));
            let handle = app.handle().clone();
            // The smoke test opens the windows and quits (smoke.rs): nothing below this runs in it.
            if smoke_test {
                tray::create(&handle)?;
                overlay::spawn_clickthrough_poll(handle.clone());
                #[cfg(target_os = "macos")]
                app.set_activation_policy(tauri::ActivationPolicy::Accessory);
                smoke::drive(handle);
                return Ok(());
            }
            let settings = app.state::<AppState>().settings();
            // Without a Developer ID macOS may forget permissions at an update: looked at every start (Phase 1B).
            app.state::<AppState>().permissions_now();
            // An update downloaded by the last run installs now, before anything records (v1.5).
            updates::install_kept(&handle);

            tray::create(&handle)?;
            // C9: no window is built here. The main window and the overlays are built when first needed
            // (lifecycle.rs), so an idle app in the tray runs no web view.
            audio::platform::set_preferred_mic(settings.input_device.clone());
            overlay::spawn_clickthrough_poll(handle.clone());
            let errors = shortcuts::register_all(&handle, &settings.shortcuts);
            *app.state::<AppState>().shortcut_errors.lock().unwrap() = errors;
            detect::spawn_auto_mode(handle.clone());
            readiness::spawn(handle.clone());
            actions::spawn_upload_worker(handle.clone());
            actions::restore_pending(&handle);
            // Recordings cut short by a crash or a forced quit come back as "Recovered recording" (since v1.3) and
            // follow "After a call"; encoding takes a moment, so it runs beside the start-up.
            let recovering = handle.clone();
            std::thread::spawn(move || {
                let data_dir = recovering.state::<AppState>().data_dir.clone();
                let recovery = recovery::recover(&data_dir, chrono::Utc::now());
                if !recovery.recovered.is_empty() {
                    actions::after_recovery(&recovering, &recovery.cut_short);
                }
            });
            commands::spawn_idle_lock(handle.clone());
            actions::spawn_activity_poll(handle.clone());
            updates::spawn_quiet_checks(handle.clone());

            // Fetch server config (caption policy, pins rotation) and the library in the background.
            let startup = handle.clone();
            tauri::async_runtime::spawn(async move {
                let state = startup.state::<AppState>();
                if let Ok(api) = state.api() {
                    if let Ok(config) = api.config().await {
                        state.apply_config(config);
                        // The tray follows the role too: a viewer's menu has no recording lines.
                        actions::emit_status(&startup);
                    }
                    let _ = commands::refresh_library_inner(&startup).await;
                    state.upload_notify.notify_one(); // send what is still queued from the last run
                }
            });

            #[cfg(target_os = "macos")]
            app.set_activation_policy(tauri::ActivationPolicy::Accessory);
            if !std::env::args().any(|a| a == "--autostart") {
                tray::show_main(&handle);
            }
            // Started by an echoledger://open or echoledger://signed-in link (Windows passes it on the command line, since
            // v1.5). A sign-in link that starts the app finds no sign-in waiting (its verifier was in the memory of the
            // run that opened the browser) and says so.
            actions::handle_links(&handle, &std::env::args().collect::<Vec<_>>());
            Ok(())
        })
        .on_window_event(|window, event| match event {
            // C9: closing the main window destroys it (the app keeps running in the tray, see `ExitRequested`); the
            // next open builds a new one on the same page (main_view_get).
            WindowEvent::CloseRequested { .. } if window.label() == lifecycle::MAIN => lifecycle::main_closing(window.app_handle()),
            WindowEvent::Destroyed => lifecycle::on_destroyed(window.app_handle(), window.label()),
            // Files dropped on the main window are heard by its page, which opens the import sheet (one client and
            // language for the batch), so nothing is imported here.
            _ => {}
        })
        .invoke_handler(tauri::generate_handler![
            commands::app_status,
            commands::translate_apps,
            commands::translate_status,
            commands::translate_start,
            commands::translate_stop,
            commands::translate_toggle_lock,
            commands::window_appearance,
            commands::set_window_theme,
            commands::pair_device,
            commands::sign_in_password,
            commands::sign_in_browser,
            commands::auth_options,
            commands::auth_page_open,
            commands::sign_in_browser_cancel,
            commands::unpair,
            commands::account_get,
            commands::account_sign_out_everywhere,
            commands::account_delete,
            commands::account_export_start,
            commands::account_export_status,
            commands::account_export_open,
            commands::server_page_open,
            commands::update_status,
            commands::update_check,
            commands::update_install,
            commands::get_settings,
            commands::update_settings,
            commands::set_shortcut,
            commands::reset_shortcuts,
            commands::shortcut_defaults,
            commands::subscribe_captions,
            commands::overlay_set_hit_regions,
            commands::overlay_snap,
            commands::toggle_captions,
            commands::start_recording,
            commands::stop_recording,
            commands::toggle_voice_note,
            commands::toggle_mic_note,
            commands::record_now,
            commands::notification_test,
            commands::notification_send,
            commands::notify_when_ready,
            commands::hide_captions,
            commands::mic_users,
            commands::ask_answer,
            commands::import_voice_notes,
            commands::import_pick,
            commands::save_audio,
            commands::copy_last_minutes,
            commands::library_list,
            commands::library_refresh,
            commands::session_detail,
            commands::transcript_detail,
            commands::delete_session,
            commands::list_clients,
            commands::upload_list,
            commands::upload_retry,
            commands::upload_discard,
            commands::pause_recording,
            commands::resume_recording,
            commands::mark_moment,
            commands::tag_last_mark,
            commands::undo_mark,
            commands::marker_tags,
            commands::unsure_markers,
            commands::input_devices,
            commands::test_capture,
            commands::permissions_status,
            commands::permission_request,
            commands::permission_notice_dismiss,
            commands::open_privacy_settings,
            commands::ready_check,
            commands::ready_last,
            commands::ready_fix,
            commands::mac_check_run,
            commands::mac_check_last,
            commands::mac_check_copy,
            commands::mac_check_send,
            commands::search_library,
            commands::transcript_edit,
            commands::transcript_replace,
            commands::transcript_restore,
            commands::transcript_revisions,
            commands::speakers_rename,
            commands::export_transcript,
            commands::lock_status,
            commands::lock_now,
            commands::lock_touch,
            commands::lock_unlock_os,
            commands::lock_unlock_pin,
            commands::lock_set_pin,
            commands::security_overview,
            commands::device_revoke,
            commands::bin_list,
            commands::session_restore,
            commands::bin_erase,
            commands::storage_info,
            commands::activity_list,
            commands::activity_read,
            commands::settings_export,
            commands::settings_import,
            commands::session_organise,
            commands::sessions_bulk,
            commands::sessions_export,
            commands::client_create,
            commands::client_update,
            commands::client_delete,
            commands::library_views,
            commands::library_folder,
            commands::client_rules,
            commands::client_rule_add,
            commands::client_rule_delete,
            commands::share_link_create,
            commands::shares_of,
            commands::send_to_teammate,
            commands::sent_of,
            commands::stop_sharing,
            commands::shared_with_me,
            commands::shared_save,
            commands::shared_remove,
            commands::request_link_create,
            commands::request_links,
            commands::request_link_revoke,
            commands::links_resolve,
            commands::link_open,
            commands::screens_status,
            commands::screens_pause,
            commands::screens_permission,
            commands::session_screens,
            commands::client_screens,
            commands::screen_delete,
            commands::screen_image,
            commands::meeting_pack,
            commands::share_create,
            commands::share_list,
            commands::share_revoke,
            commands::vocabulary_add,
            commands::vocabulary_replace_add,
            commands::transcript_suggestion,
            commands::client_workspace,
            commands::client_brief_save,
            commands::client_brief_refresh,
            commands::client_memory_add,
            commands::client_memory_update,
            commands::client_memory_delete,
            commands::client_files_pick,
            commands::client_file_upload,
            commands::client_instructions_add,
            commands::client_file_delete,
            commands::client_ask,
            commands::client_bundle,
            commands::session_notes,
            commands::session_notes_write,
            commands::dictation_history,
            commands::dictation_history_clear,
            commands::cancel_recording,
            commands::set_pill_hidden,
            commands::pill_moved,
            commands::recording_state,
            commands::pending_list,
            commands::pending_keep,
            commands::pending_discard,
            commands::pending_rename,
            commands::pending_assign_client,
            commands::pending_set_language,
            commands::session_retranscribe,
            commands::transcript_views,
            commands::transcript_views_make,
            commands::set_recording_language,
            commands::pending_open,
            commands::after_call_state,
            commands::saved_undo,
            commands::skipped_list,
            commands::skipped_restore,
            commands::window_ready,
            commands::main_view_get,
            commands::main_view_set,
        ])
        .build(tauri::generate_context!())
        .expect("error while building EchoLedger");

    app.run(|handle, event| {
        // macOS hands an echoledger://open or echoledger://signed-in link to the running app as an opened URL (Info.plist
        // registers the scheme).
        #[cfg(target_os = "macos")]
        if let RunEvent::Opened { urls, .. } = &event {
            let urls: Vec<String> = urls.iter().map(|u| u.to_string()).collect();
            actions::handle_links(handle, &urls);
        }
        if let RunEvent::ExitRequested { code, api, .. } = &event {
            // User-initiated exit (last window closed): stay in the tray. Programmatic exit (Quit) proceeds.
            if code.is_none() {
                api.prevent_exit();
            }
        }
        let _ = handle;
    });
}
