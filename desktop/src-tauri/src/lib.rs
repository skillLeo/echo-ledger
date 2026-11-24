//! EchoLedger desktop. Tray app: dual-channel capture, live captions overlay, dictation, auto mode, uploads.

pub mod account;
mod actions;
pub mod activity;
pub mod after_call;
pub mod app_life;
pub mod app_menu;
pub mod assist;
pub mod audio;
pub mod call_guard;
mod commands;
pub mod deep_link;
pub mod detect;
pub mod device_report;
pub mod diagnostics;
pub mod dictation;
pub mod error;
pub mod files;
pub mod help;
pub mod identity;
pub mod lifecycle;
pub mod lock;
pub mod mac_check;
mod main_thread;
pub mod material;
pub mod native_feel;
pub mod native_menu;
pub mod dock;
pub mod taskbar;
pub mod my_account;
pub mod net;
pub mod notifications;
pub mod overlay;
pub mod owner;
pub mod pending;
pub mod permissions;
pub mod plan;
pub mod privacy;
pub mod projects;
pub mod readiness;
pub mod recovered;
pub mod receipts;
pub mod recovery;
pub mod refusal;
pub mod screens;
pub mod secret_copy;
pub mod settings;
pub mod share_sheet;
pub mod sharing;
pub mod shortcuts;
pub mod skipped;
pub mod smoke;
pub mod stall;
pub mod spaces;
pub mod stt;
pub mod title_click;
pub mod translate;
pub mod tray;
pub mod ui_flags;
pub mod updates;
pub mod whatsapp;
pub mod wintitle;
pub mod server_notice;

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
    /// The interface flags last announced as `ui:flags` (contract 2.0.0-a).
    pub ui_announced: ui_flags::Announced,
    /// The Live Assist session that runs, if any.
    pub assist: assist::Registry,
    /// The recording a Live Assist session from a prep saves without the after-call card (its `client_ref`).
    pub assist_auto_saved: Mutex<Option<String>>,
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
    /// The server holds the upload queue for now (v1.6, P2.7): maintenance, or this version refused.
    pub upload_hold: Mutex<Option<server_notice::UploadHold>>,
    /// Where a captions server session belongs while a recording runs and stops (A5.1).
    pub session_link: Mutex<pending::SessionLink>,
    /// The recording pill is hidden by the user; the recording continues (A3.3).
    pub pill_hidden: AtomicBool,
    /// Captions were on when the recording was paused, so resume reopens them (sockets close on pause, A5.3).
    pub captions_on_resume: AtomicBool,
    /// The after-call window (the call choice): the card, the one confirmation or the grouped card, and what Undo needs.
    pub after_call: Mutex<after_call::Surfaces>,
    /// The choice made on the pill's menu for the running recording (its client_ref): no card when it ends.
    pub call_choice: Mutex<Option<(String, after_call::CallChoice)>>,
    /// "Recording this call · Don't record" beside the pill: the recording it is for and when it started.
    pub start_banner: Mutex<Option<(String, Instant)>>,
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
    /// A new email's sign-up between its code and its name (v1.6), in memory only.
    pub pending_sign_up: Mutex<Option<account::PendingSignUp>>,
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
    /// Whether the running call recording records the other side (`actions::check_system_side`).
    pub system_watch: Mutex<audio::health::SystemWatch>,
    /// What went wrong with the last recording's capture, for Copy diagnostics.
    pub last_capture_notes: Mutex<Vec<String>>,
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
        // The token is read by `load_token`, once this state is managed: the keychain may wait on its prompt (a build
        // without a Developer ID), and nothing that asks for the state may find it missing meanwhile.
        let token = None;
        let library = LibraryCache::load(&paths::library(&data_dir));
        let settings_locked = settings.app_lock && lock::LockFile::load(&paths::app_lock(&data_dir)).pin_hash.is_some();
        // Another copy of EchoLedger (an update, another signature) asks for its permissions afresh: macOS may not have
        // carried them over although its privacy call still says yes.
        let stored = permissions::Memory::load(&permissions::Memory::path(&data_dir));
        let permission_memory = stored.for_identity(&permissions::identity());
        if permission_memory != stored {
            eprintln!("[echoledger] permissions: another copy of EchoLedger than last time; what was granted is asked again");
            permission_memory.save(&permissions::Memory::path(&data_dir));
        }
        Self {
            data_dir,
            settings: Mutex::new(settings),
            token: Mutex::new(token),
            token_store,
            server_config: Mutex::new(None),
            ui_announced: ui_flags::Announced::default(),
            assist: assist::Registry::default(),
            assist_auto_saved: Mutex::new(None),
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
            upload_hold: Mutex::new(None),
            session_link: Mutex::new(pending::SessionLink::default()),
            pill_hidden: AtomicBool::new(false),
            captions_on_resume: AtomicBool::new(false),
            after_call: Mutex::new(after_call::Surfaces::default()),
            call_choice: Mutex::new(None),
            start_banner: Mutex::new(None),
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
            pending_sign_up: Mutex::new(None),
            recording_owner: Mutex::new(None),
            owner_checked: AtomicBool::new(false),
            updates: updates::Updates::default(),
            recording_missing: Mutex::new(Vec::new()),
            permission_memory: Mutex::new(permission_memory),
            system_watch: Mutex::new(audio::health::SystemWatch::default()),
            last_capture_notes: Mutex::new(Vec::new()),
            device_status: Mutex::new(audio::devices::DeviceStatus::default()),
            mac_check: Mutex::new(None),
            ready: Mutex::new(None),
            ready_watch: Mutex::new(readiness::ReadyWatch::default()),
            ready_wake: tokio::sync::Notify::new(),
            notify_ready: Mutex::new(std::collections::HashSet::new()),
        }
    }

    /// Reads the account's token from the keychain (it may wait on the keychain's prompt): right after the state is
    /// managed, in the setup.
    pub fn load_token(&self) {
        let token = self.token_store.load().unwrap_or_else(|e| {
            eprintln!("[echoledger] keychain unavailable: {e}");
            None
        });
        *self.token.lock().unwrap_or_else(|p| p.into_inner()) = token;
    }

    pub fn settings(&self) -> Settings {
        self.lock_settings().clone()
    }

    /// A look at a few settings without copying them all (the screen-share safety net reads two of them several times
    /// a second). `f` must not reach the settings again.
    pub fn settings_with<T>(&self, f: impl FnOnce(&Settings) -> T) -> T {
        f(&self.lock_settings())
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

    /// This install's id (v1.6, P3): the kept one, else made now from the machine's id and the data folder (a random
    /// one without a machine id) and kept.
    pub fn install_id(&self) -> String {
        if let Some(id) = self.settings().install_id.filter(|id| identity::is_install_id(id)) {
            return id;
        }
        let id = identity::machine_id().map(|m| identity::install_id_of(&m, &self.data_dir)).unwrap_or_else(identity::random_install_id);
        if let Err(e) = self.update_settings(|s| s.install_id = Some(id.clone())) {
            eprintln!("[echoledger] the install id could not be kept: {e}");
        }
        id
    }

    /// A sign-in saved (adopt_registration): the server, its pins and this device, and a fresh id for this sign-in, so
    /// nothing the window kept for an earlier one is read as this one's.
    pub fn note_sign_in(&self, server_url: &str, pins: &[String], device_id: &str, device_name: &str) -> AppResult<Settings> {
        self.update_settings(|s| {
            s.server_url = Some(server_url.to_string());
            s.pins = pins.to_vec();
            s.device_id = Some(device_id.to_string());
            s.device_name = Some(device_name.to_string());
            s.sign_in_id = Some(uuid::Uuid::new_v4().to_string());
        })
    }

    /// Signed out (unpair): this device and the sign-in's id are forgotten (the server stays, for the next sign-in).
    pub fn note_sign_out(&self) -> AppResult<Settings> {
        self.update_settings(|s| {
            s.device_id = None;
            s.device_name = None;
            s.sign_in_id = None;
        })
    }

    /// This sign-in's id (AppStatus.signIn), none while signed out. A sign-in kept from before 2.0.0-p gets one now.
    pub fn sign_in_id(&self) -> Option<String> {
        if !self.is_paired() {
            return None;
        }
        if let Some(id) = self.settings().sign_in_id {
            return Some(id);
        }
        let id = uuid::Uuid::new_v4().to_string();
        match self.update_settings(|s| s.sign_in_id = Some(id.clone())) {
            Ok(_) => Some(id),
            // Not kept: none (the window keeps nothing for this sign-in rather than something for any).
            Err(e) => {
                eprintln!("[echoledger] the sign-in id could not be kept: {e}");
                None
            }
        }
    }

    pub fn is_paired(&self) -> bool {
        self.token.lock().map(|t| t.is_some()).unwrap_or(false) && self.lock_settings().server_url.is_some()
    }

    /// What this computer allowed before that macOS now calls "not asked" (D-120); nothing off a Mac.
    pub fn permissions_allowed_before(&self, now: &permissions::Permissions) -> Vec<permissions::Kind> {
        if !cfg!(target_os = "macos") {
            return Vec::new();
        }
        let can_tell = permissions::can_tell_not_asked();
        self.permission_memory_now().allowed_before(now, can_tell)
    }

    /// Whether Screen Recording still waits for a reopen although EchoLedger was reopened since it was allowed.
    pub fn screen_reopen_did_not_apply(&self, now: &permissions::Permissions) -> bool {
        self.permission_memory_now().reopen_did_not_apply(now, permissions::this_run())
    }

    /// A copy of what is remembered about the permissions, the lock let go at once: nothing is ever called while it is
    /// held, and a caller that locks it again (a struct literal's later field, say) can never wait on itself.
    pub fn permission_memory_now(&self) -> permissions::Memory {
        self.permission_memory.lock().unwrap_or_else(|p| p.into_inner()).clone()
    }

    /// What a real look at system audio found (a recording's other side, a short tap): kept, and said by
    /// [`AppState::permissions_now`] from now on.
    pub fn system_audio_checked(&self, result: Option<Result<(), String>>) {
        let mut memory = self.permission_memory.lock().unwrap_or_else(|p| p.into_inner());
        let next = memory.with_system_audio(result);
        if next != *memory {
            next.save(&permissions::Memory::path(&self.data_dir));
            *memory = next;
        }
    }

    /// What the system allows now, taken into the memory of what was granted (a permission that went is said once).
    /// System audio that did not record although the privacy call allows it counts as off (`Memory::overlay`).
    pub fn permissions_now(&self) -> permissions::Permissions {
        let now = self.permission_memory_now().overlay(permissions::read());
        if cfg!(target_os = "macos") {
            let screens_wanted = self.lock_settings().screen_moments;
            let (can_tell, run) = (permissions::can_tell_not_asked(), permissions::this_run());
            // Held only to take in what the system says (pure) and keep it: no other call made under it.
            let mut memory = self.permission_memory.lock().unwrap_or_else(|p| p.into_inner());
            let next = memory.observe(&now, screens_wanted, can_tell).note_reopen(&now, run);
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
        // The config is never refused, so it says whether the queue still waits: in maintenance or refused it holds
        // again, otherwise a hold from an earlier answer is over.
        *self.upload_hold.lock().unwrap_or_else(|p| p.into_inner()) = server_notice::hold_from_config(&config, std::time::Instant::now());
        *self.server_config.lock().unwrap_or_else(|p| p.into_inner()) = Some(config);
    }

    /// Notes an answer that holds the queue (maintenance, a refused version); true when it was one.
    pub fn note_hold(&self, e: &error::AppError) -> bool {
        let Some(hold) = server_notice::hold_from_error(e, std::time::Instant::now()) else { return false };
        *self.upload_hold.lock().unwrap_or_else(|p| p.into_inner()) = Some(hold);
        true
    }

    /// Whether the upload queue waits now (the server is in maintenance or refuses this version).
    pub fn uploads_held(&self) -> bool {
        self.upload_hold.lock().unwrap_or_else(|p| p.into_inner()).is_some_and(|h| h.active(std::time::Instant::now()))
    }

    /// What the main window shows at its top (v1.6, P2.7).
    pub fn server_notices(&self) -> server_notice::ServerNotices {
        let config = self.server_config.lock().unwrap_or_else(|p| p.into_inner());
        let hold = *self.upload_hold.lock().unwrap_or_else(|p| p.into_inner());
        server_notice::notices(config.as_ref(), hold.as_ref(), std::time::Instant::now())
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
    // Started at login (the autostart launch agent): no window, and on a Mac the focus stays with the app in front.
    let autostart = std::env::args().any(|a| a == "--autostart");
    let mut builder = tauri::Builder::default();
    if smoke_dir.is_none() {
        builder = builder.plugin(tauri_plugin_single_instance::init(|app, argv, _cwd| actions::show_main_from_outside(app, &argv))); // must be first
    }
    // The menu bar of a Mac while EchoLedger is in front: ⌘, ⌘W ⌘Q and the Edit keys. Windows has no app menu: its windows
    // would grow a menu bar.
    #[cfg(target_os = "macos")]
    {
        builder = builder.menu(app_menu::build).activate_ignoring_other_apps(app_life::takes_focus_at_launch(autostart, smoke_dir.is_some()));
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
        // Before any window is built: every window registers with it and gets its capture flag from it.
        .manage(privacy::Privacy::new())
        .manage(lifecycle::WindowLifecycle::default())
        .manage(shortcuts::Bindings::default())
        .manage(stt::captions::CaptionHub::default())
        .setup(move |app| {
            // No window tabs on a Mac (FIX-05): macOS would add Show Tab Bar and Show All Tabs to the View menu.
            #[cfg(target_os = "macos")]
            app_menu::no_window_tabs();
            let smoke_test = smoke_dir.is_some();
            let data_dir = match smoke_dir {
                Some(dir) => dir,
                None => app.path().app_data_dir()?,
            };
            std::fs::create_dir_all(paths::recordings(&data_dir))?;
            app.manage(AppState::load(data_dir, smoke_test));
            // Managed first, then the keychain (it may wait on its prompt): a second launch meanwhile finds the state.
            app.state::<AppState>().load_token();
            let handle = app.handle().clone();
            // The smoke test opens the windows and quits (smoke.rs): nothing below this runs in it.
            if smoke_test {
                tray::create(&handle)?;
                overlay::spawn_clickthrough_poll(handle.clone());
                // As the app itself runs (`app_life`).
                #[cfg(target_os = "macos")]
                app.set_activation_policy(tauri::ActivationPolicy::Regular);
                if smoke::share_check_requested(std::env::args()) {
                    smoke::drive_share_check(handle);
                } else {
                    smoke::drive(handle);
                }
                return Ok(());
            }
            let settings = app.state::<AppState>().settings();
            // Without a Developer ID macOS may forget permissions at an update: looked at every start (Phase 1B).
            app.state::<AppState>().permissions_now();
            // Another copy of EchoLedger (the first start after an update): system audio looked at for real once, when it
            // was allowed before (`Memory::wants_probe`).
            if cfg!(target_os = "macos") && app.state::<AppState>().permission_memory_now().wants_probe() {
                let verifying = handle.clone();
                tauri::async_runtime::spawn(async move { commands::verify_system_audio(&verifying).await });
            }
            // An update downloaded by the last run installs now, before anything records (v1.5).
            updates::install_kept(&handle);

            tray::create(&handle)?;
            // FIX-11: the menu bar icon as the settings say (shown or not), and the Dock's number.
            tray::follow_settings(&handle);
            // FIX-12 (Windows): the Jump List's Tasks and Open with, the tray tip once after install; a start from the
            // Jump List runs its task, a start with audio files (Open with) imports them.
            taskbar::register(&handle);
            #[cfg(windows)]
            taskbar::tray_tip_once(&handle);
            let args: Vec<String> = std::env::args().collect();
            taskbar::run_jump_task(&handle, &args);
            dock::opened(&handle, taskbar::opened_files(&args));
            // C9: no window is built here. The main window and the overlays are built when first needed
            // (lifecycle.rs), so an idle app (in the Dock and the menu bar, or the notification area) runs no web view.
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
            // Screen-share privacy (v1.8.1): the 5 s read-back (Windows), the safety net (Mac), the automatic Share Check.
            #[cfg(windows)]
            privacy::spawn_recheck(handle.clone());
            #[cfg(target_os = "macos")]
            privacy::watch::spawn(handle.clone());
            privacy::check::spawn_auto(handle.clone());
            // The main window shows in screen shares again by itself when its hide is over (v2.0).
            privacy::spawn_main_hide_timer(handle.clone());
            // Newer sharing-bar patterns, when the server has them: in a task of their own, once a day.
            privacy::bars::spawn_refresh(handle.clone());

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

            // A normal Mac app for the whole run (`app_life`): in the Dock with its dot, ⌘Tab and Force Quit, quit from the
            // Dock with a running recording kept first.
            #[cfg(target_os = "macos")]
            app.set_activation_policy(tauri::ActivationPolicy::Regular);
            if !autostart {
                tray::show_main(&handle);
            }
            // Started by an echoledger://open or echoledger://signed-in link (Windows passes it on the command line, since
            // v1.5). A sign-in link that starts the app finds no sign-in waiting (its verifier was in the memory of the
            // run that opened the browser) and says so.
            actions::handle_links(&handle, &std::env::args().collect::<Vec<_>>());
            // Started: a second launch or a reopen that came during the start-up is answered now.
            actions::started(&handle);
            Ok(())
        })
        .on_window_event(|window, event| {
            // The smoke test's trail (`stall`): which window event the main thread is in.
            let noted = stall::begin_with(|| format!("window {} {}", window.label(), stall::event_name(event)));
            on_window_event(window, event);
            stall::end(noted);
        })
        // The smoke test's trail (`stall`): each command, the thread that received it and when it returned there.
        .invoke_handler(stall::traced(tauri::generate_handler![
            commands::app_status,
            commands::privacy_status,
            commands::share_check_run,
            commands::share_check_drawn,
            commands::share_check_card,
            commands::share_check_answer,
            commands::privacy_show_in_shares,
            commands::privacy_concealed,
            commands::privacy_hide_all,
            commands::privacy_hide_again,
            commands::privacy_set_hidden,
            commands::privacy_open_check,
            commands::privacy_diagnostics,
            commands::privacy_move_to_other_display,
            commands::privacy_main_hide,
            commands::caption_probe_painted,
            commands::privacy_main_show,
            commands::privacy_window_share,
            commands::privacy_window_share_set,
            commands::share_notice_dismiss,
            commands::translate_apps,
            commands::translate_status,
            commands::live_translate_status,
            commands::diagnostic_report,
            commands::diagnostic_report_copy,
            commands::translate_start,
            commands::translate_stop,
            commands::translate_toggle_lock,
            commands::window_appearance,
            title_click::title_double_click,
            native_menu::native_menu_popup,
            dock::dock_activity_set,
            wintitle::window_titlebar,
            app_menu::app_menu_set,
            commands::set_window_theme,
            commands::pair_device,
            commands::sign_in_password,
            commands::sign_in_browser,
            commands::auth_options,
            commands::auth_start,
            commands::auth_code_send,
            commands::auth_code_verify,
            commands::auth_sign_up,
            commands::entitlements_get,
            commands::transcription_key_set,
            commands::transcription_key_delete,
            commands::groq_console_open,
            commands::upgrade_request_create,
            commands::promo_code_check,
            commands::upgrade_request_latest,
            commands::help_guides,
            commands::help_guide,
            commands::help_whats_new,
            commands::support_send,
            commands::last_device_report,
            commands::upgrade_receipt_pick,
            commands::upgrade_request_proof,
            commands::upgrade_request_proof_data,
            commands::upgrade_request_proof_delete,
            commands::upgrade_request_proof_file,
            commands::upgrade_request_paid,
            commands::upgrade_request_cancel,
            commands::device_rename,
            commands::auth_page_open,
            commands::sign_in_browser_cancel,
            commands::unpair,
            commands::account_get,
            commands::account_call,
            commands::account_avatar_choose,
            commands::account_avatar_image,
            commands::account_recovery_codes_save,
            commands::account_recovery_codes_copy,
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
            commands::mic_last_not_recorded,
            commands::quit_and_reopen,
            commands::ui_flags,
            commands::trash_keep_hours,
            commands::after_call_notice_answer,
            commands::ui_classic_set,
            commands::ui_design_set,
            commands::assist_preps_list,
            commands::assist_prep_save,
            commands::assist_prep_delete,
            commands::assist_prep_documents_add,
            commands::assist_prep_document_remove,
            commands::assist_schedule,
            commands::assist_start,
            commands::assist_start_replacing,
            commands::assist_keep_running,
            commands::assist_end,
            commands::assist_send,
            commands::assist_set,
            commands::assist_feedback,
            commands::assist_shown,
            commands::assist_card_hide,
            commands::assist_state,
            commands::ask_answer,
            commands::import_voice_notes,
            commands::import_pick,
            commands::save_audio,
            commands::save_audio_downloads,
            commands::whatsapp_open,
            commands::whatsapp_share_voice,
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
            commands::capture_retry_system,
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
            commands::session_transcribe,
            commands::sessions_transcribe,
            commands::sessions_transcribe_estimate,
            commands::client_create,
            commands::session_opened,
            commands::session_unopen,
            commands::lists_get,
            commands::list_create,
            commands::list_update,
            commands::list_delete,
            commands::lists_order,
            commands::views_get,
            commands::view_create,
            commands::view_update,
            commands::view_delete,
            commands::views_order,
            commands::library_views_version,
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
            commands::screens_capture_now,
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
            commands::pending_keep_audio,
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
            commands::after_call_discard,
            commands::after_call_discard_undo,
            commands::call_card_answer,
            commands::call_undo,
            commands::call_done_transcribe,
            commands::call_always,
            commands::call_group_close,
            commands::call_choice,
            commands::pill_expanded,
            commands::pending_delete,
            commands::pending_delete_undo,
            commands::skipped_list,
            commands::recovered_sessions,
            commands::skipped_restore,
            commands::window_ready,
            commands::main_view_get,
            commands::main_view_set,
        ]))
        .build(tauri::generate_context!())
        .expect("error while building EchoLedger");
    // The Dock's Quit and the system's keep a running recording first (`app_life`): taught to the application delegate
    // once it exists and before the app runs, also in the smoke test, which fails without it.
    #[cfg(target_os = "macos")]
    if !app_life::mac::answer_quits_from_outside(app.handle()) {
        eprintln!("[echoledger] a quit from the Dock would not keep a running recording first");
    }
    // FIX-11: the Dock icon's menu, taught to the delegate the same way.
    #[cfg(target_os = "macos")]
    if !dock::mac::install(app.handle()) {
        eprintln!("[echoledger] the Dock icon has no menu of EchoLedger's");
    }
    // Its menu bar menu opens over another app's full-screen window (`spaces`).
    #[cfg(target_os = "macos")]
    spaces::mac::install();

    app.run(|handle, event| {
        // macOS hands an echoledger://open or echoledger://signed-in link to the running app as an opened URL (Info.plist
        // registers the scheme).
        #[cfg(target_os = "macos")]
        if let RunEvent::Opened { urls, .. } = &event {
            // Audio files opened with EchoLedger (Finder's Open With, a drop on the Dock icon) are imported (FIX-11).
            dock::opened(handle, dock::opened_files(urls));
            let links: Vec<String> = urls.iter().filter(|u| u.scheme() != "file").map(|u| u.to_string()).collect();
            actions::handle_links(handle, &links);
        }
        // A click on the Dock icon, or opening the app again from Finder, Launchpad or Spotlight, starts no second copy on
        // a Mac (the single-instance plugin hears nothing): macOS sends this copy a reopen, which opens or focuses the
        // main window as a second launch does on Windows. Its `has_visible_windows` counts the overlays (the dictation box
        // is on screen while its shortcut is bound), so it says nothing about the main window and is not asked.
        #[cfg(target_os = "macos")]
        if let RunEvent::Reopen { .. } = &event {
            actions::show_main_from_outside(handle, &[]);
        }
        // Quitting: recovery codes copied a moment ago leave the clipboard with the app.
        if let RunEvent::Exit = &event {
            secret_copy::clear_now_on_main_thread(handle);
        }
        if let RunEvent::ExitRequested { code, api, .. } = &event {
            // The last window closed (no code): EchoLedger keeps running. A Quit (app.exit) or the updater's restart exits.
            if app_life::keep_running(*code) {
                api.prevent_exit();
            }
        }
        let _ = handle;
    });
}

/// What the windows' events do (`on_window_event`).
fn on_window_event(window: &tauri::Window, event: &WindowEvent) {
    match event {
        // C9: closing the main window destroys it (the app keeps running: in the Dock and the menu bar of a Mac, the
        // notification area of Windows, see `ExitRequested`); the next open builds a new one on the same page
        // (main_view_get).
        // FIX-12: on Windows "When I close the window" decides (keep, said the first time, or quit); a Mac's red button
        // always keeps EchoLedger running.
        #[cfg(windows)]
        WindowEvent::CloseRequested { api, .. } if window.label() == lifecycle::MAIN => {
            if taskbar::main_close_requested(window.app_handle()) {
                lifecycle::main_closing(window.app_handle());
            } else {
                api.prevent_close();
            }
        }
        #[cfg(not(windows))]
        WindowEvent::CloseRequested { .. } if window.label() == lifecycle::MAIN => lifecycle::main_closing(window.app_handle()),
        // The Live Assist card was dragged: its place is kept for the next session.
        WindowEvent::Moved(_) if window.label() == assist::live::WINDOW => assist::live::card_moved(window.app_handle()),
        WindowEvent::Destroyed => {
            privacy::destroyed(window.app_handle(), window.label());
            lifecycle::on_destroyed(window.app_handle(), window.label());
            // Live Translate's window went, however: its capture and its keys go with it (off the main thread).
            if window.label() == translate::WINDOW {
                // Its number now, while no newer window can exist yet: a quick Start after Stop keeps its session.
                let gone = translate::window_generation();
                let app = window.app_handle().clone();
                tauri::async_runtime::spawn_blocking(move || translate::window_gone(&app, gone));
            }
        }
        // Files dropped on the main window are heard by its page, which opens the import sheet (one client and
        // language for the batch), so nothing is imported here.
        _ => {}
    }
}
