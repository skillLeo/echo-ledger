//! EchoLedger desktop. Tray app: dual-channel capture, live captions overlay, dictation, auto mode, uploads.

mod actions;
pub mod audio;
mod commands;
pub mod detect;
pub mod dictation;
pub mod error;
pub mod net;
pub mod overlay;
pub mod pending;
pub mod settings;
pub mod shortcuts;
pub mod stt;
pub mod tray;

use std::path::PathBuf;
use std::sync::atomic::AtomicBool;
use std::sync::{Mutex, MutexGuard};
use std::time::Instant;

use tauri::{DragDropEvent, Manager, RunEvent, WindowEvent};
use tauri_plugin_autostart::MacosLauncher;

use error::{AppError, AppResult};
use net::api::ApiClient;
use net::keychain::{KeyringStore, TokenStore};
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
    /// Pending ids created by this run, oldest first: the review card shows the newest undecided one.
    pub review_run: Mutex<Vec<String>>,
    /// The once-a-second `recording:state` task is running.
    pub ticker_running: AtomicBool,
}

impl AppState {
    fn load(data_dir: PathBuf) -> Self {
        let settings = Settings::load(&data_dir.join("settings.json"));
        let token_store: Box<dyn TokenStore> = Box::new(KeyringStore);
        let token = token_store.load().unwrap_or_else(|e| {
            eprintln!("[echoledger] keychain unavailable: {e}");
            None
        });
        let library = LibraryCache::load(&paths::library(&data_dir));
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
            review_run: Mutex::new(Vec::new()),
            ticker_running: AtomicBool::new(false),
        }
    }

    pub fn settings(&self) -> Settings {
        self.lock_settings().clone()
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
        *self.server_config.lock().unwrap_or_else(|p| p.into_inner()) = Some(config);
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

    let app = tauri::Builder::default()
        .plugin(tauri_plugin_single_instance::init(|app, _argv, _cwd| tray::show_main(app))) // must be first
        .plugin(shortcuts::plugin())
        .plugin(tauri_plugin_autostart::init(MacosLauncher::LaunchAgent, Some(vec!["--autostart"])))
        .plugin(tauri_plugin_notification::init())
        .plugin(tauri_plugin_positioner::init())
        .plugin(tauri_plugin_store::Builder::new().build())
        .plugin(tauri_plugin_opener::init())
        .plugin(tauri_plugin_clipboard_manager::init())
        .manage(overlay::HitRegions::default())
        .manage(shortcuts::Bindings::default())
        .manage(stt::captions::CaptionHub::default())
        .setup(|app| {
            let data_dir = app.path().app_data_dir()?;
            std::fs::create_dir_all(paths::recordings(&data_dir))?;
            app.manage(AppState::load(data_dir));
            let handle = app.handle().clone();
            let settings = app.state::<AppState>().settings();

            tray::create(&handle)?;
            overlay::create_overlays(
                &handle,
                overlay::OverlaySetup {
                    corner: settings.overlay_corner,
                    content_protected: settings.content_protection,
                    caption_lines: settings.caption_lines,
                    pill_position: settings.pill_position,
                },
            )?;
            overlay::spawn_clickthrough_poll(handle.clone());
            let errors = shortcuts::register_all(&handle, &settings.shortcuts);
            *app.state::<AppState>().shortcut_errors.lock().unwrap() = errors;
            detect::spawn_auto_mode(handle.clone());
            actions::spawn_upload_worker(handle.clone());
            actions::restore_pending(&handle);

            // Fetch server config (caption policy, pins rotation) and the library in the background.
            let startup = handle.clone();
            tauri::async_runtime::spawn(async move {
                let state = startup.state::<AppState>();
                if let Ok(api) = state.api() {
                    if let Ok(config) = api.config().await {
                        state.apply_config(config);
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
            Ok(())
        })
        .on_window_event(|window, event| match event {
            WindowEvent::CloseRequested { api, .. } if window.label() == "main" => {
                api.prevent_close(); // keep running in the tray
                let _ = window.hide();
            }
            WindowEvent::DragDrop(DragDropEvent::Drop { paths, .. }) if window.label() == "main" => {
                let app = window.app_handle().clone();
                let files: Vec<String> = paths.iter().map(|p| p.to_string_lossy().to_string()).collect();
                let _ = commands::import_voice_notes(app, files);
            }
            _ => {}
        })
        .invoke_handler(tauri::generate_handler![
            commands::app_status,
            commands::pair_device,
            commands::unpair,
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
            commands::hide_captions,
            commands::mic_users,
            commands::ask_answer,
            commands::import_voice_notes,
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
            commands::cancel_recording,
            commands::set_pill_hidden,
            commands::pill_moved,
            commands::recording_state,
            commands::pending_list,
            commands::pending_keep,
            commands::pending_discard,
            commands::pending_rename,
            commands::pending_assign_client,
            commands::pending_open,
            commands::review_state,
        ])
        .build(tauri::generate_context!())
        .expect("error while building EchoLedger");

    app.run(|handle, event| {
        if let RunEvent::ExitRequested { code, api, .. } = &event {
            // User-initiated exit (last window closed): stay in the tray. Programmatic exit (Quit) proceeds.
            if code.is_none() {
                api.prevent_exit();
            }
        }
        let _ = handle;
    });
}
