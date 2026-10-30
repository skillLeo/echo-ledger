//! IPC commands for the React UI. Tokens and provider credentials never cross this boundary.

use std::collections::HashMap;

use serde::Serialize;
use tauri::ipc::Channel as IpcChannel;
use tauri::{AppHandle, Emitter, Manager, State, WebviewWindow};

use crate::AppState;
use crate::error::{AppError, AppResult};
use crate::net::api::ApiClient;
use crate::net::sync::{LibraryCache, paths};
pub use crate::net::models::BudgetState;
use crate::net::uploader::{UploadItem, UploadQueue};
use crate::overlay::geometry::{RectPx, WorkArea, nearest_corner};
use crate::overlay::{HitRegions, redock};
use crate::pending::{DecisionFiles, PendingItem};
use crate::settings::DecisionTimeout;
use crate::tray::TrayState;
use crate::settings::Settings;
use crate::shortcuts::{self, Action};
use crate::stt::captions::{CaptionEvent, CaptionHub};

#[derive(Clone, Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct AppStatus {
    pub paired: bool,
    pub server_url: Option<String>,
    pub device_name: Option<String>,
    pub pinned: bool,
    pub recording: Option<crate::audio::RecordingInfo>,
    pub captions_live: bool,
    pub dictation_active: bool,
    pub auto_mode: bool,
    pub pending_uploads: usize,
    pub upload_errors: Vec<String>,
    pub shortcut_errors: Vec<(Action, String)>,
    pub levels: [f32; 2],
    pub platform: &'static str,
    pub failed_uploads: usize,
    pub budget: BudgetState,
    pub paused: bool,
    pub elapsed_ms: u64,
    pub pill_hidden: bool,
    pub pending_decisions: usize,
    pub tray_state: TrayState,
}

pub fn status(app: &AppHandle) -> AppStatus {
    let state = app.state::<AppState>();
    let settings = state.settings();
    let queue = UploadQueue::load(&paths::uploads(&state.data_dir));
    let budget = {
        let config = state.server_config.lock().unwrap_or_else(|p| p.into_inner());
        BudgetState::from_usage(config.as_ref().and_then(|c| c.usage.as_ref()), state.budget_blocked.load(std::sync::atomic::Ordering::SeqCst))
    };
    let recording = state.capture.info();
    let paused = state.capture.is_paused();
    let pill_hidden = state.pill_hidden.load(std::sync::atomic::Ordering::SeqCst);
    let pending_decisions = DecisionFiles::in_dir(&state.data_dir).load().items.len();
    let tray_state = crate::tray::tray_state(recording.is_some(), paused, pending_decisions, queue.failed(), budget == BudgetState::Reached, queue.active() > 0);
    let switches = crate::tray::Switches { captions: state.live.is_live(), auto: settings.auto_mode };
    crate::tray::update(app, tray_state, paused, pill_hidden, pending_decisions, switches);
    AppStatus {
        paired: state.is_paired(),
        server_url: settings.server_url.clone(),
        device_name: settings.device_name.clone(),
        pinned: !settings.pins.is_empty(),
        recording,
        captions_live: state.live.is_live(),
        dictation_active: crate::dictation::is_active(app),
        auto_mode: settings.auto_mode,
        pending_uploads: queue.active(),
        upload_errors: queue.items.iter().filter(|i| !i.is_failed()).filter_map(|i| i.last_error.clone()).collect(),
        shortcut_errors: state.shortcut_errors.lock().unwrap().clone(),
        levels: state.capture.levels(),
        platform: if cfg!(windows) { "windows" } else if cfg!(target_os = "macos") { "macos" } else { "other" },
        failed_uploads: queue.failed(),
        budget,
        paused,
        elapsed_ms: state.capture.elapsed_ms(),
        pill_hidden,
        pending_decisions,
        tray_state,
    }
}

/// Main-window material and system accent (C2).
#[tauri::command]
pub fn window_appearance() -> crate::material::Appearance {
    crate::material::appearance()
}

/// The native title bar and window material follow the app's theme (`light`, `dark` or `system`).
#[tauri::command]
pub fn set_window_theme(app: AppHandle, theme: String) {
    crate::material::set_theme(&app, &theme);
}

#[tauri::command]
pub fn app_status(app: AppHandle) -> AppStatus {
    status(&app)
}

/// Pairs with a server using the one-time code (typed) or the QR payload JSON.
#[tauri::command]
pub async fn pair_device(app: AppHandle, server_url: String, code: String, device_name: String) -> AppResult<AppStatus> {
    let (server_url, code, pins) = if code.trim_start().starts_with('{') {
        let payload: serde_json::Value = serde_json::from_str(code.trim())?;
        let url = payload["url"].as_str().unwrap_or(&server_url).to_string();
        let pins = payload["pins"].as_array().map(|a| a.iter().filter_map(|p| p.as_str().map(String::from)).collect()).unwrap_or_default();
        (url, payload["code"].as_str().unwrap_or_default().to_string(), pins)
    } else {
        (server_url, code, Vec::<String>::new())
    };
    let name = if device_name.trim().is_empty() { default_device_name() } else { device_name.trim().to_string() };
    let platform = if cfg!(target_os = "macos") { "macos" } else if cfg!(windows) { "windows" } else { "linux" };

    // Pairing uses the pins from the QR (when scanned); typed codes rely on WebPKI until the server sends pins.
    let api = ApiClient::new(&server_url, &pins, None)?;
    let registered = api.register(code.trim(), &name, platform, &os_version()).await?;
    let state = app.state::<AppState>();
    state.token_store.save(&registered.token).map_err(AppError::Keychain)?;
    *state.token.lock().unwrap() = Some(registered.token);
    let final_pins = if registered.config.pins.is_empty() { pins } else { registered.config.pins.clone() };
    state.update_settings(|s| {
        s.server_url = Some(api.base_url().to_string());
        s.pins = final_pins.clone();
        s.device_id = Some(registered.device.id.clone());
        s.device_name = Some(registered.device.name.clone());
    })?;
    *state.server_config.lock().unwrap() = Some(registered.config);
    state.upload_notify.notify_one();
    let _ = refresh_library_inner(&app).await;
    Ok(status(&app))
}

#[tauri::command]
pub async fn unpair(app: AppHandle) -> AppResult<AppStatus> {
    let state = app.state::<AppState>();
    state.token_store.forget().map_err(AppError::Keychain)?;
    *state.token.lock().unwrap() = None;
    state.api_cache.clear();
    state.update_settings(|s| {
        s.device_id = None;
        s.device_name = None;
    })?;
    let _ = std::fs::remove_file(paths::library(&state.data_dir));
    *state.library.lock().await = LibraryCache::default();
    Ok(status(&app))
}

#[tauri::command]
pub fn get_settings(state: State<'_, AppState>) -> Settings {
    state.settings()
}

#[derive(serde::Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct SettingsPatch {
    captions_on_record: Option<bool>,
    auto_mode: Option<bool>,
    /// Switches to merge: app wire value → on/off; unknown apps are refused.
    auto_record_apps: Option<HashMap<String, bool>>,
    content_protection: Option<bool>,
    autostart: Option<bool>,
    dictation_output: Option<String>,
    /// Missing = keep, `null` = clear ("No client"), a string = set (A6.DU4).
    #[serde(default, deserialize_with = "double_option")]
    client_id: Option<Option<String>>,
    consent_acknowledged: Option<bool>,
    overlay_corner: Option<crate::overlay::geometry::Corner>,
    ask_unknown_apps: Option<bool>,
    /// Full replacement of the user rules (built-ins are never stored).
    site_rules: Option<Vec<crate::detect::sites::SiteRule>>,
    browser_automation: Option<bool>,
    ask_before_saving: Option<crate::settings::AskBeforeSaving>,
    decision_timeout: Option<crate::settings::DecisionTimeout>,
    /// 2, 3 or 5; anything else is refused.
    caption_lines: Option<u8>,
    /// Full replacement; refused when a value is out of range.
    caption_style: Option<crate::settings::CaptionStyle>,
    /// Missing = keep, `null` = follow the system default, a string = that device.
    #[serde(default, deserialize_with = "double_option")]
    input_device: Option<Option<String>>,
    far_side_warning: Option<bool>,
    /// Missing = keep, `null` = automatic, else one of `settings::LANGUAGES`.
    #[serde(default, deserialize_with = "double_option")]
    transcription_language: Option<Option<String>>,
    /// `toggle` or `push_to_talk`.
    dictation_mode: Option<String>,
    dictation_cleanup: Option<bool>,
    dictation_sounds: Option<bool>,
    /// Full replacement of the dictionary and replacements.
    dictation_replacements: Option<Vec<crate::dictation::rules::Replacement>>,
    /// Full replacement of the voice snippets.
    dictation_snippets: Option<Vec<crate::dictation::rules::Snippet>>,
    app_lock: Option<bool>,
    lock_after_minutes: Option<u32>,
    activity_notifications: Option<bool>,
    quiet_hours: Option<bool>,
    quiet_from: Option<String>,
    quiet_to: Option<String>,
    onboarding_done: Option<bool>,
}

/// A present key becomes `Some(value)`, so an explicit `null` reads as `Some(None)` and a missing key (via
/// `#[serde(default)]`) as `None`. Plain `Option<Option<T>>` would read `null` as `None` too.
fn double_option<'de, D: serde::Deserializer<'de>>(d: D) -> Result<Option<Option<String>>, D::Error> {
    <Option<String> as serde::Deserialize>::deserialize(d).map(Some)
}

impl SettingsPatch {
    /// Applies the keys present in the patch (validation of `captionLines` happens before).
    fn apply(&self, s: &mut Settings) {
        if let Some(v) = self.captions_on_record { s.captions_on_record = v; }
        if let Some(v) = self.auto_mode { s.auto_mode = v; }
        if let Some(v) = &self.auto_record_apps { s.auto_record_apps.extend(v.iter().map(|(k, on)| (k.clone(), *on))); }
        if let Some(v) = self.content_protection { s.content_protection = v; }
        if let Some(v) = self.autostart { s.autostart = v; }
        if let Some(v) = &self.dictation_output && (v == "type" || v == "clipboard") { s.dictation_output = v.clone(); }
        if let Some(v) = &self.client_id { s.client_id = v.clone(); }
        if let Some(v) = self.consent_acknowledged { s.consent_acknowledged = v; }
        if let Some(v) = self.overlay_corner { s.overlay_corner = v; }
        if let Some(v) = self.ask_unknown_apps { s.ask_unknown_apps = v; }
        if let Some(v) = &self.site_rules { s.site_rules = v.clone(); }
        if let Some(v) = self.browser_automation { s.browser_automation = v; }
        if let Some(v) = self.ask_before_saving { s.ask_before_saving = v; }
        if let Some(v) = self.decision_timeout { s.decision_timeout = v; }
        if let Some(v) = self.caption_lines { s.caption_lines = v; }
        if let Some(v) = &self.caption_style { s.caption_style = v.clone(); }
        if let Some(v) = &self.input_device { s.input_device = v.clone(); }
        if let Some(v) = self.far_side_warning { s.far_side_warning = v; }
        if let Some(v) = &self.transcription_language { s.transcription_language = crate::settings::language_or_none(v.as_deref()); }
        if let Some(v) = &self.dictation_mode && (v == "toggle" || v == "push_to_talk") { s.dictation_mode = v.clone(); }
        if let Some(v) = self.dictation_cleanup { s.dictation_cleanup = v; }
        if let Some(v) = self.dictation_sounds { s.dictation_sounds = v; }
        if let Some(v) = &self.dictation_replacements { s.dictation_replacements = crate::dictation::rules::clean_replacements(v); }
        if let Some(v) = &self.dictation_snippets { s.dictation_snippets = crate::dictation::rules::clean_snippets(v); }
        if let Some(v) = self.app_lock { s.app_lock = v; }
        if let Some(v) = self.lock_after_minutes && crate::lock::LOCK_AFTER_CHOICES.contains(&v) { s.lock_after_minutes = v; }
        if let Some(v) = self.activity_notifications { s.activity_notifications = v; }
        if let Some(v) = self.quiet_hours { s.quiet_hours = v; }
        if let Some(v) = &self.quiet_from && crate::activity::parse_hhmm(v).is_some() { s.quiet_from = v.clone(); }
        if let Some(v) = &self.quiet_to && crate::activity::parse_hhmm(v).is_some() { s.quiet_to = v.clone(); }
        if let Some(v) = self.onboarding_done { s.onboarding_done = v; }
    }
}

#[tauri::command]
pub fn update_settings(app: AppHandle, patch: SettingsPatch) -> AppResult<Settings> {
    use tauri_plugin_autostart::ManagerExt;
    if let Some(lines) = patch.caption_lines
        && !crate::settings::CAPTION_LINES.contains(&lines)
    {
        return Err(AppError::msg("Choose 2, 3 or 5 lines."));
    }
    if let Some(mode) = &patch.dictation_mode
        && mode != "toggle"
        && mode != "push_to_talk"
    {
        return Err(AppError::msg("Choose press to start or hold to talk."));
    }
    if [&patch.quiet_from, &patch.quiet_to].into_iter().flatten().any(|t| crate::activity::parse_hhmm(t).is_none()) {
        return Err(AppError::msg("Use a time like 22:00."));
    }
    if let Some(minutes) = patch.lock_after_minutes
        && !crate::lock::LOCK_AFTER_CHOICES.contains(&minutes)
    {
        return Err(AppError::msg("Choose when the app locks."));
    }
    if patch.app_lock == Some(true) && !crate::lock::LockFile::load(&paths::app_lock(&app.state::<AppState>().data_dir)).pin_hash.is_some_and(|h| !h.is_empty()) {
        return Err(AppError::msg("Set a PIN first. It unlocks the app when Windows Hello or Touch ID is not available."));
    }
    if let Some(Some(language)) = &patch.transcription_language
        && crate::settings::language_or_none(Some(language)).is_none()
    {
        return Err(AppError::msg("That language is not in the list. Choose Auto, English only, Urdu, Arabic or one of the other languages."));
    }
    if let Some(problem) = patch.caption_style.as_ref().and_then(|s| s.problem()) {
        return Err(AppError::msg(problem));
    }
    if let Some(apps) = &patch.auto_record_apps
        && apps.keys().any(|app| !crate::detect::classify::SourceApp::from_wire(app).is_some_and(|a| a.desktop_detects()))
    {
        return Err(AppError::msg("That app cannot be recorded automatically."));
    }
    let state = app.state::<AppState>();
    let updated = state.update_settings(|s| patch.apply(s))?;
    let _ = app.emit("settings:changed", &updated);
    if patch.content_protection.is_some() {
        crate::overlay::set_content_protection(&app, updated.content_protection);
    }
    if let Some(enable) = patch.autostart {
        let manager = app.autolaunch();
        let _ = if enable { manager.enable() } else { manager.disable() };
    }
    if patch.input_device.is_some() {
        // Used from the next recording or resume on; a recording in progress keeps its device.
        crate::audio::platform::set_preferred_mic(updated.input_device.clone());
    }
    if patch.caption_lines.is_some() || patch.caption_style.is_some() {
        // Resizes and re-docks in the setting's corner (never `show()`).
        crate::overlay::resize_captions(&app, updated.caption_lines, &updated.caption_style, updated.overlay_corner);
    } else if let Some(corner) = patch.overlay_corner
        && let Some(w) = app.get_webview_window(crate::overlay::OVERLAY)
    {
        redock(&w, corner);
    }
    if patch.decision_timeout.is_some() {
        // The policy in force now applies to recordings whose time is already up (A2.5).
        crate::actions::apply_due_timeouts(&app);
        crate::actions::emit_pending(&app);
        crate::actions::refresh_review(&app);
    }
    crate::actions::emit_status(&app);
    Ok(updated)
}

#[tauri::command]
pub fn set_shortcut(app: AppHandle, action: Action, accelerator: String) -> AppResult<Settings> {
    shortcuts::rebind(&app, action, &accelerator).map_err(AppError::Message)?;
    let state = app.state::<AppState>();
    state.shortcut_errors.lock().unwrap().retain(|(a, _)| *a != action);
    let settings = state.update_settings(|s| {
        s.shortcuts.insert(action, accelerator.trim().to_string());
    })?;
    let _ = app.emit("settings:changed", &settings);
    Ok(settings)
}

#[tauri::command]
pub fn reset_shortcuts(app: AppHandle) -> AppResult<Settings> {
    let state = app.state::<AppState>();
    let settings = state.update_settings(|s| s.shortcuts = shortcuts::default_map())?;
    *state.shortcut_errors.lock().unwrap() = shortcuts::register_all(&app, &settings.shortcuts);
    let _ = app.emit("settings:changed", &settings);
    Ok(settings)
}

#[tauri::command]
pub fn subscribe_captions(window: WebviewWindow, on_event: IpcChannel<CaptionEvent>, hub: State<'_, CaptionHub>) {
    hub.subscribe(window.label(), on_event);
}

/// The page has attached its listeners: it gets the events kept while its window was built (C9, lifecycle.rs).
#[tauri::command]
pub fn window_ready(app: AppHandle, window: WebviewWindow) {
    crate::lifecycle::ready(&app, window.label());
}

/// Where the user was in the main window (page, recording, scroll), kept in memory while the window is closed.
#[tauri::command]
pub fn main_view_get(state: State<'_, crate::lifecycle::WindowLifecycle>) -> Option<String> {
    state.main_view()
}

#[tauri::command]
pub fn main_view_set(view: String, state: State<'_, crate::lifecycle::WindowLifecycle>) {
    state.set_main_view(view);
}

#[tauri::command]
pub fn overlay_set_hit_regions(window: WebviewWindow, regions: Vec<RectPx>, state: State<'_, HitRegions>) {
    state.0.lock().unwrap().insert(window.label().to_string(), regions);
    state.1.notify_one(); // wake the idle click-through poll at once (C9)
}

/// After the user drags the overlay: snap to the nearest corner of its monitor's work area and remember it.
#[tauri::command]
pub fn overlay_snap(app: AppHandle, window: WebviewWindow) -> AppResult<()> {
    let (Ok(pos), Ok(size)) = (window.outer_position(), window.outer_size()) else { return Ok(()) };
    let Some(monitor) = window.current_monitor()? else { return Ok(()) };
    let wa = monitor.work_area();
    let area = WorkArea { x: wa.position.x, y: wa.position.y, w: wa.size.width, h: wa.size.height };
    let corner = nearest_corner(area, (pos.x, pos.y), (size.width, size.height));
    redock(&window, corner);
    if window.label() == crate::overlay::OVERLAY {
        let settings = app.state::<AppState>().update_settings(|s| s.overlay_corner = corner)?;
        let _ = app.emit("settings:changed", &settings);
    }
    Ok(())
}

#[tauri::command]
pub async fn toggle_captions(app: AppHandle) -> AppResult<AppStatus> {
    crate::actions::toggle_captions(&app).await?;
    Ok(status(&app))
}

#[tauri::command]
pub async fn start_recording(app: AppHandle, source_app: Option<String>) -> AppResult<AppStatus> {
    crate::actions::start_call_recording(&app, source_app.as_deref().unwrap_or("other"), false).await?;
    Ok(status(&app))
}

#[tauri::command]
pub async fn stop_recording(app: AppHandle) -> AppResult<AppStatus> {
    crate::actions::stop_recording(&app, false).await?;
    Ok(status(&app))
}

#[tauri::command]
pub async fn toggle_voice_note(app: AppHandle) -> AppResult<AppStatus> {
    crate::actions::toggle_voice_note(&app).await?;
    Ok(status(&app))
}

/// Record a voice note with the microphone (mono). Unlike `toggle_voice_note`, which captures audio playing here.
#[tauri::command]
pub async fn toggle_mic_note(app: AppHandle) -> AppResult<AppStatus> {
    crate::actions::toggle_mic_note(&app).await?;
    Ok(status(&app))
}

/// Which applications hold the microphone and what auto mode decided for each (the Record screen explainer).
/// Served from the auto-mode loop's last observation (refreshed every second); never polls the system itself.
#[tauri::command]
pub async fn mic_users(app: AppHandle) -> Vec<crate::detect::MicUser> {
    app.state::<AppState>().detect.mic_users()
}

/// Payload of `ask:show`: the unknown-app chip (A1.5).
#[derive(Clone, Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct AskPayload {
    pub id: String,
    pub name: String,
    pub site: Option<String>,
    pub seconds: u64,
}

/// The unknown-app chip's answer: `record`, `ignore` or `always_ignore`.
#[tauri::command]
pub async fn ask_answer(app: AppHandle, id: String, answer: String) -> AppResult<()> {
    crate::detect::answer_ask(&app, &id, &answer).await?;
    crate::actions::emit_status(&app);
    Ok(())
}

/// The overlay's close button: always hides captions, whatever state they are in (a recording keeps running).
#[tauri::command]
pub fn hide_captions(app: AppHandle) -> AppStatus {
    crate::actions::stop_captions(&app);
    status(&app)
}

#[tauri::command]
pub fn import_voice_notes(app: AppHandle, paths_list: Vec<String>) -> AppResult<usize> {
    let mut count = 0;
    for p in paths_list {
        let path = std::path::PathBuf::from(&p);
        if is_audio(&path) {
            crate::actions::import_voice_note(&app, &path)?;
            count += 1;
        }
    }
    crate::actions::emit_status(&app);
    Ok(count)
}

pub fn is_audio(path: &std::path::Path) -> bool {
    matches!(
        path.extension().and_then(|e| e.to_str()).map(|e| e.to_ascii_lowercase()).as_deref(),
        Some("ogg" | "opus" | "oga" | "mp3" | "m4a" | "mp4" | "aac" | "wav" | "flac" | "webm" | "amr")
    )
}

#[tauri::command]
pub fn copy_last_minutes(app: AppHandle, minutes: Option<u64>) -> AppResult<String> {
    crate::actions::copy_last_minutes(&app, minutes.unwrap_or(2))
}

#[tauri::command]
pub async fn library_list(state: State<'_, AppState>) -> AppResult<Vec<crate::net::models::LibraryItem>> {
    ensure_unlocked(&state)?;
    Ok(state.library.lock().await.items.clone())
}

pub async fn refresh_library_inner(app: &AppHandle) -> AppResult<usize> {
    let state = app.state::<AppState>();
    let api = state.api()?;
    let mut cache = state.library.lock().await;
    let changed = cache.refresh(&api).await?;
    cache.save(&paths::library(&state.data_dir))?;
    let _ = app.emit("library:updated", changed);
    Ok(changed)
}

#[tauri::command]
pub async fn library_refresh(app: AppHandle) -> AppResult<Vec<crate::net::models::LibraryItem>> {
    ensure_unlocked(&app.state::<AppState>())?;
    refresh_library_inner(&app).await?;
    Ok(app.state::<AppState>().library.lock().await.items.clone())
}

#[tauri::command]
pub async fn session_detail(state: State<'_, AppState>, id: String) -> AppResult<serde_json::Value> {
    ensure_unlocked(&state)?;
    state.api()?.session(&id).await
}

/// "Re-transcribe as <language>": the current transcript stays until the new one is ready (since v1.2).
#[tauri::command]
pub async fn session_retranscribe(app: AppHandle, id: String, language: Option<String>) -> AppResult<serde_json::Value> {
    let state = app.state::<AppState>();
    ensure_unlocked(&state)?;
    let language = crate::settings::language_or_none(language.as_deref());
    let run = state.api()?.retranscribe(&id, language.as_deref()).await?;
    let _ = refresh_library_inner(&app).await;
    Ok(run)
}

#[tauri::command]
pub async fn transcript_detail(state: State<'_, AppState>, id: String) -> AppResult<serde_json::Value> {
    ensure_unlocked(&state)?;
    state.api()?.transcript(&id).await
}

#[tauri::command]
pub async fn delete_session(app: AppHandle, id: String) -> AppResult<()> {
    app.state::<AppState>().api()?.delete_session(&id).await?;
    let _ = refresh_library_inner(&app).await;
    Ok(())
}

#[tauri::command]
pub async fn list_clients(state: State<'_, AppState>) -> AppResult<serde_json::Value> {
    state.api()?.clients().await
}

/// The upload queue, oldest first.
#[tauri::command]
pub fn upload_list(app: AppHandle) -> Vec<UploadItem> {
    let state = app.state::<AppState>();
    UploadQueue::load(&paths::uploads(&state.data_dir)).ui_items()
}

/// Puts a failed upload back in the queue (one more automatic full restart is allowed again).
#[tauri::command]
pub fn upload_retry(app: AppHandle, id: String) -> AppResult<()> {
    crate::actions::retry_upload(&app, &id)
}

/// Deletes a queued upload and its local audio; a session already created on the server is deleted too.
#[tauri::command]
pub async fn upload_discard(app: AppHandle, id: String) -> AppResult<()> {
    crate::actions::discard_upload(&app, &id)
}

#[tauri::command]
pub fn shortcut_defaults() -> HashMap<Action, String> {
    shortcuts::default_map()
}

fn default_device_name() -> String {
    std::env::var("COMPUTERNAME").or_else(|_| std::env::var("HOSTNAME")).unwrap_or_else(|_| "Desktop".into())
}

fn os_version() -> String {
    #[cfg(windows)]
    {
        crate::audio::platform::windows::os_build().map(|b| format!("build {b}")).unwrap_or_default()
    }
    #[cfg(not(windows))]
    {
        String::new()
    }
}

#[cfg(test)]
mod tests;

/// What the recording pill shows (section 4.3).
#[derive(Clone, Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct RecordingState {
    pub active: bool,
    pub paused: bool,
    pub elapsed_ms: u64,
    pub source_app: Option<String>,
    pub mode: Option<crate::audio::RecordingMode>,
    pub captions: bool,
    pub levels: [f32; 2],
    pub auto: bool,
    pub hidden: bool,
    /// The recording's language chip (none: automatic, the client's or the console default).
    pub language: Option<String>,
}

/// What the review card shows: the newest undecided recording of this run.
#[derive(Clone, Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct ReviewPayload {
    pub item: PendingItem,
    pub timeout: DecisionTimeout,
    pub queue_length: usize,
}

/// `main:navigate`: which view the main window opens on.
#[derive(Clone, Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct NavigatePayload {
    pub view: String,
    pub pending_id: Option<String>,
    pub rename: bool,
    /// A recording to open in the library (an OS notification was clicked, B18).
    pub recording_id: Option<String>,
}

pub fn recording_state_now(app: &AppHandle) -> RecordingState {
    let state = app.state::<AppState>();
    let info = state.capture.info();
    RecordingState {
        active: info.is_some(),
        paused: state.capture.is_paused(),
        elapsed_ms: state.capture.elapsed_ms(),
        source_app: info.as_ref().map(|i| i.source_app.clone()),
        mode: info.as_ref().map(|i| i.mode),
        captions: state.live.is_live(),
        levels: state.capture.levels(),
        auto: info.is_some() && state.auto_started.load(std::sync::atomic::Ordering::SeqCst),
        hidden: state.pill_hidden.load(std::sync::atomic::Ordering::SeqCst),
        language: info.as_ref().and_then(|i| state.recording_language(&i.client_ref)),
    }
}

#[tauri::command]
pub async fn pause_recording(app: AppHandle) -> AppResult<AppStatus> {
    crate::actions::pause_recording(&app).await?;
    Ok(status(&app))
}

/// A transcript search from the library or the command palette (`GET /api/search`).
#[derive(Clone, Debug, Default, serde::Deserialize)]
#[serde(default, rename_all = "camelCase")]
pub struct SearchQuery {
    pub q: String,
    pub client_id: Option<String>,
    pub source_app: Option<String>,
    pub source_device: Option<String>,
    pub from: Option<String>,
    pub to: Option<String>,
    pub tag: Option<String>,
    pub bookmarked: bool,
    pub page: Option<u32>,
}

impl SearchQuery {
    /// The query parameters the server documents; empty filters are left out.
    pub fn params(&self) -> Vec<(String, String)> {
        let mut params = vec![("q".to_string(), self.q.trim().to_string())];
        for (key, value) in [
            ("client_id", &self.client_id),
            ("source_app", &self.source_app),
            ("source_device", &self.source_device),
            ("from", &self.from),
            ("to", &self.to),
            ("tag", &self.tag),
        ] {
            if let Some(v) = value.as_deref().map(str::trim).filter(|v| !v.is_empty()) {
                params.push((key.to_string(), v.to_string()));
            }
        }
        if self.bookmarked {
            params.push(("bookmarked".into(), "1".into()));
        }
        if let Some(page) = self.page.filter(|p| *p > 1) {
            params.push(("page".into(), page.to_string()));
        }
        params
    }
}

/// Searches every recording's title and transcript on the server.
#[tauri::command]
pub async fn search_library(state: State<'_, AppState>, query: SearchQuery) -> AppResult<serde_json::Value> {
    ensure_unlocked(&state)?;
    if query.q.trim().chars().count() < 2 {
        return Ok(serde_json::json!({ "data": [], "next_page": null }));
    }
    state.api()?.search(&query.params()).await
}

#[tauri::command]
pub async fn transcript_edit(state: State<'_, AppState>, id: String, index: usize, text: String) -> AppResult<serde_json::Value> {
    state.api()?.edit_transcript(&id, index, &text).await
}

#[tauri::command]
pub async fn transcript_replace(state: State<'_, AppState>, id: String, find: String, replace: String, match_case: bool, whole_word: bool) -> AppResult<serde_json::Value> {
    state.api()?.replace_in_transcript(&id, &find, &replace, match_case, whole_word).await
}

#[tauri::command]
pub async fn transcript_restore(state: State<'_, AppState>, id: String) -> AppResult<serde_json::Value> {
    state.api()?.restore_transcript(&id).await
}

#[tauri::command]
pub async fn transcript_revisions(state: State<'_, AppState>, id: String) -> AppResult<serde_json::Value> {
    ensure_unlocked(&state)?;
    state.api()?.transcript_revisions(&id).await
}

#[tauri::command]
pub async fn speakers_rename(state: State<'_, AppState>, session_id: String, names: serde_json::Map<String, serde_json::Value>, remember_for_client: bool) -> AppResult<serde_json::Value> {
    state.api()?.rename_speakers(&session_id, &names, remember_for_client).await
}

/// Where an export is saved: the Downloads folder (else the app data folder), with a free name.
pub fn export_target(dir: &std::path::Path, filename: &str) -> std::path::PathBuf {
    let path = dir.join(filename);
    if !path.exists() {
        return path;
    }
    let (stem, ext) = filename.rsplit_once('.').map_or((filename, ""), |(s, e)| (s, e));
    (2..1000)
        .map(|n| dir.join(if ext.is_empty() { format!("{stem} ({n})") } else { format!("{stem} ({n}).{ext}") }))
        .find(|p| !p.exists())
        .unwrap_or(path)
}

/// Saves an export to Downloads and returns its path; the print page (`html`) opens in the browser for PDF.
/// "Show as" (since v1.2): the Roman Urdu and English views of a transcript and their state.
#[tauri::command]
pub async fn transcript_views(app: AppHandle, id: String) -> AppResult<serde_json::Value> {
    let state = app.state::<AppState>();
    ensure_unlocked(&state)?;
    state.api()?.transcript_views(&id).await
}

/// "Show as" (since v1.2): starts making the Roman Urdu and English views (the server writes them on its queue).
#[tauri::command]
pub async fn transcript_views_make(app: AppHandle, id: String) -> AppResult<serde_json::Value> {
    let state = app.state::<AppState>();
    ensure_unlocked(&state)?;
    state.api()?.make_transcript_views(&id).await
}

#[tauri::command]
pub async fn export_transcript(app: AppHandle, id: String, format: String, timestamps: bool, speakers: bool, view: Option<String>) -> AppResult<String> {
    ensure_unlocked(&app.state::<AppState>())?;
    use tauri_plugin_opener::OpenerExt;
    const FORMATS: [&str; 7] = ["txt", "md", "srt", "vtt", "json", "docx", "html"];
    if !FORMATS.contains(&format.as_str()) {
        return Err(AppError::msg("That export format is not available."));
    }
    let state = app.state::<AppState>();
    // Show as: a Roman Urdu or English view instead of the original words (anything else exports the original).
    let view = view.filter(|v| v == "roman" || v == "english");
    let (bytes, filename) = state.api()?.export_transcript(&id, &format, timestamps, speakers, view.as_deref()).await?;
    let dir = if format == "html" { std::env::temp_dir() } else { app.path().download_dir().unwrap_or_else(|_| state.data_dir.clone()) };
    let path = export_target(&dir, &filename);
    std::fs::write(&path, bytes)?;
    if format == "html" {
        app.opener().open_path(path.to_string_lossy(), None::<&str>).map_err(|e| AppError::msg(e.to_string()))?;
    }
    Ok(path.to_string_lossy().into_owned())
}

// ---- Settings export / import (B19) -----------------------------------------------------------------------------

/// Keys never exported or imported: the pairing (server, pins, device) and the client of this server.
/// `consentAcknowledged` stays out too: the recording-laws notice is acknowledged by the person using this install, never by a file.
pub const NOT_PORTABLE: [&str; 7] = ["serverUrl", "pins", "deviceId", "deviceName", "clientId", "onboardingDone", "consentAcknowledged"];
/// The `format` marker of an exported settings file.
pub const SETTINGS_FORMAT: &str = "echoledger-settings";

/// The settings as a portable JSON document: everything but the pairing. There are no secrets in settings; the
/// device token lives in the system keychain and the PIN hash in its own file.
pub fn portable_settings(settings: &Settings) -> serde_json::Value {
    let mut value = serde_json::to_value(settings).unwrap_or_default();
    if let Some(map) = value.as_object_mut() {
        for key in NOT_PORTABLE {
            map.remove(key);
        }
    }
    serde_json::json!({ "format": SETTINGS_FORMAT, "version": 1, "settings": value })
}

/// The patch and shortcuts of an exported file; an error for anything that is not one.
pub fn read_portable(text: &str) -> AppResult<(SettingsPatch, Option<serde_json::Map<String, serde_json::Value>>)> {
    let doc: serde_json::Value = serde_json::from_str(text).map_err(|_| AppError::msg("That is not an EchoLedger settings file."))?;
    if doc.get("format").and_then(serde_json::Value::as_str) != Some(SETTINGS_FORMAT) {
        return Err(AppError::msg("That is not an EchoLedger settings file."));
    }
    let mut map = doc.get("settings").and_then(serde_json::Value::as_object).cloned().ok_or_else(|| AppError::msg("The file has no settings."))?;
    for key in NOT_PORTABLE {
        map.remove(key);
    }
    let shortcuts = map.remove("shortcuts").and_then(|v| v.as_object().cloned());
    // Values this build does not know (a newer export) are dropped one by one, like Settings::from_value does.
    let mut clean = serde_json::Map::new();
    for (key, value) in map {
        let mut probe = clean.clone();
        probe.insert(key.clone(), value.clone());
        if serde_json::from_value::<SettingsPatch>(serde_json::Value::Object(probe)).is_ok() {
            clean.insert(key, value);
        }
    }
    let patch = serde_json::from_value::<SettingsPatch>(serde_json::Value::Object(clean)).map_err(|e| AppError::msg(e.to_string()))?;
    Ok((patch, shortcuts))
}

/// Saves the settings (without the pairing) as a file in Downloads; returns its path.
#[tauri::command]
pub fn settings_export(app: AppHandle) -> AppResult<String> {
    let state = app.state::<AppState>();
    let doc = portable_settings(&state.settings());
    let dir = app.path().download_dir().unwrap_or_else(|_| state.data_dir.clone());
    let path = export_target(&dir, &format!("EchoLedger settings {}.json", chrono::Local::now().format("%Y-%m-%d")));
    std::fs::write(&path, serde_json::to_vec_pretty(&doc)?)?;
    Ok(path.to_string_lossy().into_owned())
}

/// Applies an exported settings file; the pairing stays as it is.
#[tauri::command]
pub fn settings_import(app: AppHandle, text: String) -> AppResult<Settings> {
    let (mut patch, shortcuts) = read_portable(&text)?;
    if patch.app_lock == Some(true) && !crate::lock::LockFile::load(&paths::app_lock(&app.state::<AppState>().data_dir)).pin_hash.is_some_and(|h| !h.is_empty()) {
        patch.app_lock = None; // the PIN is not part of an export: set one here first
    }
    let mut settings = update_settings(app.clone(), patch)?;
    if let Some(shortcuts) = shortcuts {
        let state = app.state::<AppState>();
        let mut map = settings.shortcuts.clone();
        for (key, value) in shortcuts {
            if let (Ok(action), Some(accelerator)) = (serde_json::from_value::<crate::shortcuts::Action>(serde_json::Value::String(key)), value.as_str())
                && (accelerator.is_empty() || crate::shortcuts::parse(accelerator).is_ok())
            {
                map.insert(action, accelerator.to_string());
            }
        }
        if crate::shortcuts::duplicates(&map).is_empty() {
            settings = state.update_settings(|s| s.shortcuts = map.clone())?;
            let errors = crate::shortcuts::register_all(&app, &map);
            *state.shortcut_errors.lock().unwrap_or_else(|p| p.into_inner()) = errors;
        }
    }
    Ok(settings)
}
// ---- App lock (B16) ---------------------------------------------------------------------------------------------

/// What the lock screen and Settings need. The PIN hash itself never leaves the Rust side.
#[derive(Clone, Debug, Serialize, PartialEq, Eq)]
#[serde(rename_all = "camelCase")]
pub struct LockStatus {
    pub enabled: bool,
    pub locked: bool,
    pub has_pin: bool,
    /// `windows_hello`, `touch_id` or null when the system offers neither.
    pub os_method: Option<String>,
    /// Seconds before another PIN try is accepted.
    pub retry_after_seconds: u64,
}

pub fn lock_status_of(state: &AppState) -> LockStatus {
    let wait = state.lock.attempts.lock().unwrap_or_else(|p| p.into_inner()).wait(std::time::Instant::now());
    LockStatus {
        enabled: state.settings().app_lock,
        locked: state.lock.is_locked(),
        has_pin: crate::lock::LockFile::load(&paths::app_lock(&state.data_dir)).pin_hash.is_some(),
        os_method: crate::lock::os::method().map(str::to_string),
        retry_after_seconds: wait.as_secs() + u64::from(wait.subsec_nanos() > 0),
    }
}

/// Library, transcript and export commands refuse to answer while the app is locked.
pub fn ensure_unlocked(state: &AppState) -> AppResult<()> {
    if state.lock.is_locked() { Err(AppError::msg("EchoLedger is locked.")) } else { Ok(()) }
}

fn emit_lock(app: &AppHandle) {
    let status = lock_status_of(&app.state::<AppState>());
    let _ = app.emit("lock:changed", status);
}

#[tauri::command]
pub fn lock_status(state: State<'_, AppState>) -> LockStatus {
    lock_status_of(&state)
}

/// Locks now (when the lock is on). Recording, captions and uploads carry on.
#[tauri::command]
pub fn lock_now(app: AppHandle) -> LockStatus {
    let state = app.state::<AppState>();
    if state.settings().app_lock {
        state.lock.set_locked(true);
        emit_lock(&app);
    }
    lock_status_of(&state)
}

/// The UI saw the person use the app; resets the lock-after timer.
#[tauri::command]
pub fn lock_touch(state: State<'_, AppState>) {
    if !state.lock.is_locked() {
        state.lock.touch();
    }
}

/// Unlocks with Windows Hello or Touch ID.
#[tauri::command]
pub async fn lock_unlock_os(app: AppHandle) -> AppResult<LockStatus> {
    let window = main_window_handle(&app);
    let verified = tauri::async_runtime::spawn_blocking(move || crate::lock::os::verify(window, "Unlock EchoLedger"))
        .await
        .map_err(|e| AppError::msg(e.to_string()))?
        .map_err(AppError::msg)?;
    let state = app.state::<AppState>();
    if verified {
        state.lock.attempts.lock().unwrap_or_else(|p| p.into_inner()).succeed();
        state.lock.set_locked(false);
        emit_lock(&app);
    }
    Ok(lock_status_of(&state))
}

/// Unlocks with the PIN; wrong PINs make the next try wait.
#[tauri::command]
pub fn lock_unlock_pin(app: AppHandle, pin: String) -> AppResult<LockStatus> {
    let state = app.state::<AppState>();
    let now = std::time::Instant::now();
    let wait = state.lock.attempts.lock().unwrap_or_else(|p| p.into_inner()).wait(now);
    if !wait.is_zero() {
        return Err(AppError::msg(format!("Too many tries. Try again in {} seconds.", wait.as_secs().max(1))));
    }
    let stored = crate::lock::LockFile::load(&paths::app_lock(&state.data_dir)).pin_hash.unwrap_or_default();
    if crate::lock::verify_pin(&stored, &pin) {
        state.lock.attempts.lock().unwrap_or_else(|p| p.into_inner()).succeed();
        state.lock.set_locked(false);
        emit_lock(&app);
        return Ok(lock_status_of(&state));
    }
    state.lock.attempts.lock().unwrap_or_else(|p| p.into_inner()).fail(now);
    Err(AppError::msg("That PIN is not right."))
}

/// Sets or changes the PIN (only while unlocked).
#[tauri::command]
pub fn lock_set_pin(app: AppHandle, pin: String) -> AppResult<LockStatus> {
    let state = app.state::<AppState>();
    ensure_unlocked(&state)?;
    if !crate::lock::pin_is_valid(&pin) {
        return Err(AppError::msg("Use 4 to 12 digits."));
    }
    crate::lock::LockFile { pin_hash: Some(crate::lock::hash_pin(&pin)) }.save(&paths::app_lock(&state.data_dir))?;
    emit_lock(&app);
    Ok(lock_status_of(&state))
}

/// Locks an idle app after the chosen minutes. Checks twice a minute; costs nothing while the lock is off.
pub fn spawn_idle_lock(app: AppHandle) {
    tauri::async_runtime::spawn(async move {
        loop {
            tokio::time::sleep(std::time::Duration::from_secs(30)).await;
            let state = app.state::<AppState>();
            let settings = state.settings();
            if !state.lock.is_locked() && crate::lock::idle_expired(settings.app_lock, settings.lock_after_minutes, state.lock.last_activity(), std::time::Instant::now()) {
                state.lock.set_locked(true);
                emit_lock(&app);
            }
        }
    });
}

/// Devices paired with the server and its recent audit log (B16).
#[tauri::command]
pub async fn security_overview(state: State<'_, AppState>) -> AppResult<serde_json::Value> {
    ensure_unlocked(&state)?;
    state.api()?.security().await
}

/// Revokes another paired device.
#[tauri::command]
pub async fn device_revoke(state: State<'_, AppState>, id: String) -> AppResult<()> {
    ensure_unlocked(&state)?;
    state.api()?.revoke_device(&id).await
}
/// Recordings deleted in the last 48 hours (B17).
#[tauri::command]
pub async fn bin_list(state: State<'_, AppState>) -> AppResult<serde_json::Value> {
    ensure_unlocked(&state)?;
    state.api()?.bin().await
}

/// Restores a recording from the undo bin and syncs the library so it is back everywhere.
#[tauri::command]
pub async fn session_restore(app: AppHandle, id: String) -> AppResult<serde_json::Value> {
    ensure_unlocked(&app.state::<AppState>())?;
    let item = app.state::<AppState>().api()?.restore_session(&id).await?;
    let _ = refresh_library_inner(&app).await;
    Ok(item)
}

/// Erases a binned recording now.
#[tauri::command]
pub async fn bin_erase(state: State<'_, AppState>, id: String) -> AppResult<()> {
    ensure_unlocked(&state)?;
    state.api()?.erase_from_bin(&id).await
}

/// The storage meter and the retention in days.
#[tauri::command]
pub async fn storage_info(state: State<'_, AppState>) -> AppResult<serde_json::Value> {
    state.api()?.storage().await
}
/// The activity centre (B18): newest notifications with the unread count.
#[tauri::command]
pub async fn activity_list(state: State<'_, AppState>, limit: Option<u32>) -> AppResult<serde_json::Value> {
    ensure_unlocked(&state)?;
    state.api()?.activity(limit.unwrap_or(30).clamp(1, 100)).await
}

/// Marks the given notifications read, or all of them.
#[tauri::command]
pub async fn activity_read(app: AppHandle, ids: Option<Vec<String>>) -> AppResult<serde_json::Value> {
    let answer = app.state::<AppState>().api()?.mark_activity_read(ids.as_deref()).await?;
    let _ = app.emit("activity:updated", answer.get("unread_count").and_then(serde_json::Value::as_u64).unwrap_or(0));
    Ok(answer)
}
fn main_window_handle(app: &AppHandle) -> isize {
    #[cfg(windows)]
    {
        app.get_webview_window("main").and_then(|w| w.hwnd().ok()).map(|h| h.0 as isize).unwrap_or(0)
    }
    #[cfg(not(windows))]
    {
        let _ = app;
        0
    }
}
/// Fields a recording or client-folder change may carry; anything else from the UI is dropped.
pub const SESSION_PATCH_KEYS: [&str; 5] = ["title", "client_id", "pinned", "keep", "labels"];
pub const CLIENT_PATCH_KEYS: [&str; 5] = ["name", "color", "avatar", "pinned", "language"];
pub const BULK_ACTIONS: [&str; 8] = ["move", "label", "unlabel", "pin", "unpin", "keep", "unkeep", "delete"];
pub const BULK_EXPORT_FORMATS: [&str; 6] = ["txt", "md", "srt", "vtt", "json", "docx"];
/// The server takes at most this many recordings per bulk action.
pub const MAX_BULK: usize = 200;

/// Keeps only the allowed keys of a patch.
pub fn allowed_patch(patch: &serde_json::Map<String, serde_json::Value>, keys: &[&str]) -> serde_json::Map<String, serde_json::Value> {
    patch.iter().filter(|(k, _)| keys.contains(&k.as_str())).map(|(k, v)| (k.clone(), v.clone())).collect()
}

/// Checks a bulk request before it goes to the server: a known action, 1-200 recordings, a label when needed.
pub fn check_bulk(ids: &[String], action: &str, label: Option<&str>) -> AppResult<()> {
    if !BULK_ACTIONS.contains(&action) {
        return Err(AppError::msg("That action is not available."));
    }
    if ids.is_empty() || ids.len() > MAX_BULK {
        return Err(AppError::msg(format!("Choose between 1 and {MAX_BULK} recordings.")));
    }
    if matches!(action, "label" | "unlabel") && label.is_none_or(|l| l.trim().is_empty()) {
        return Err(AppError::msg("Type a label first."));
    }
    Ok(())
}

/// Pins, labels, renames or moves one recording, then refreshes the library so every view shows the change.
#[tauri::command]
pub async fn session_organise(app: AppHandle, id: String, patch: serde_json::Map<String, serde_json::Value>) -> AppResult<serde_json::Value> {
    let patch = allowed_patch(&patch, &SESSION_PATCH_KEYS);
    if patch.is_empty() {
        return Err(AppError::msg("Nothing to change."));
    }
    let item = app.state::<AppState>().api()?.update_session(&id, &serde_json::Value::Object(patch)).await?;
    let _ = refresh_library_inner(&app).await;
    Ok(item)
}

/// One action for the selected recordings (move, label, unlabel, pin, unpin, delete); returns how many changed.
#[tauri::command]
pub async fn sessions_bulk(app: AppHandle, ids: Vec<String>, action: String, client_id: Option<String>, label: Option<String>) -> AppResult<u64> {
    check_bulk(&ids, &action, label.as_deref())?;
    let answer = app.state::<AppState>().api()?.bulk_sessions(&ids, &action, client_id.as_deref(), label.as_deref()).await?;
    let _ = refresh_library_inner(&app).await;
    Ok(answer.get("changed").and_then(serde_json::Value::as_u64).unwrap_or(0))
}

/// Saves the finished transcripts of the selected recordings as one zip file in Downloads; returns its path.
#[tauri::command]
pub async fn sessions_export(app: AppHandle, ids: Vec<String>, format: String) -> AppResult<String> {
    ensure_unlocked(&app.state::<AppState>())?;
    if !BULK_EXPORT_FORMATS.contains(&format.as_str()) {
        return Err(AppError::msg("That export format is not available."));
    }
    if ids.is_empty() || ids.len() > MAX_BULK {
        return Err(AppError::msg(format!("Choose between 1 and {MAX_BULK} recordings.")));
    }
    let state = app.state::<AppState>();
    let (bytes, filename) = state.api()?.export_sessions(&ids, &format).await?;
    let dir = app.path().download_dir().unwrap_or_else(|_| state.data_dir.clone());
    let path = export_target(&dir, &filename);
    std::fs::write(&path, bytes)?;
    Ok(path.to_string_lossy().into_owned())
}

/// A new client folder.
#[tauri::command]
pub async fn client_create(state: State<'_, AppState>, name: String, color: Option<String>, avatar: Option<String>) -> AppResult<serde_json::Value> {
    let name = name.trim().to_string();
    if name.is_empty() {
        return Err(AppError::msg("Give the client a name."));
    }
    state.api()?.create_client(&serde_json::json!({ "name": name, "color": color, "avatar": avatar })).await
}

/// Renames, recolours, sets the avatar of or pins a client folder.
#[tauri::command]
pub async fn client_update(app: AppHandle, id: String, patch: serde_json::Map<String, serde_json::Value>) -> AppResult<serde_json::Value> {
    let patch = allowed_patch(&patch, &CLIENT_PATCH_KEYS);
    if patch.is_empty() {
        return Err(AppError::msg("Nothing to change."));
    }
    let client = app.state::<AppState>().api()?.update_client(&id, &serde_json::Value::Object(patch)).await?;
    let _ = refresh_library_inner(&app).await;
    Ok(client)
}
#[tauri::command]
pub async fn share_create(state: State<'_, AppState>, session_id: String, expires_in: u64, password: Option<String>, include_audio: bool) -> AppResult<serde_json::Value> {
    let password = password.filter(|p| !p.is_empty());
    state.api()?.create_share(&session_id, expires_in, password.as_deref(), include_audio).await
}

#[tauri::command]
pub async fn share_list(state: State<'_, AppState>, session_id: String) -> AppResult<serde_json::Value> {
    state.api()?.list_shares(&session_id).await
}

#[tauri::command]
pub async fn share_revoke(state: State<'_, AppState>, id: String) -> AppResult<()> {
    state.api()?.revoke_share(&id).await
}

/// "Add to vocabulary" after an edit: for the recording's client, else for every recording.
#[tauri::command]
pub async fn vocabulary_add(state: State<'_, AppState>, term: String, client_id: Option<String>) -> AppResult<serde_json::Value> {
    state.api()?.add_keyterm(&term, client_id.as_deref()).await
}

/// The dictation history on this computer, newest first (B14).
#[tauri::command]
pub fn dictation_history(app: AppHandle) -> AppResult<Vec<crate::dictation::rules::HistoryEntry>> {
    ensure_unlocked(&app.state::<AppState>())?;
    Ok(crate::dictation::history(&app))
}

#[tauri::command]
pub fn dictation_history_clear(app: AppHandle) -> AppResult<()> {
    crate::dictation::clear_history(&app)
}

/// The connected microphones for the device picker (B5), the default first.
#[tauri::command]
pub async fn input_devices() -> AppResult<Vec<crate::audio::platform::InputDevice>> {
    tauri::async_runtime::spawn_blocking(crate::audio::platform::input_devices).await.map_err(|e| AppError::msg(e.to_string()))
}

/// The 10-second test recording (B5): both sides are recorded, `capture:test_level` reports the meters every
/// 100 ms, and the result carries the recording to play back. Refused while recording or dictating.
#[tauri::command]
pub async fn test_capture(app: AppHandle, seconds: Option<u32>) -> AppResult<crate::audio::health::TestResult> {
    let state = app.state::<AppState>();
    if state.capture.is_recording() || crate::dictation::is_active(&app) {
        return Err(AppError::msg("Finish the current recording before testing the microphone."));
    }
    if state.capture_test.swap(true, std::sync::atomic::Ordering::SeqCst) {
        return Err(AppError::msg("A test recording is already running."));
    }
    let seconds = seconds.unwrap_or(crate::audio::health::TEST_SECONDS).clamp(3, 30);
    let emitter = app.clone();
    let result = tauri::async_runtime::spawn_blocking(move || {
        crate::audio::health::run_test(seconds, |levels| {
            let _ = emitter.emit("capture:test_level", levels);
        })
    })
    .await;
    state.capture_test.store(false, std::sync::atomic::Ordering::SeqCst);
    result.map_err(|e| AppError::msg(e.to_string()))?.map_err(AppError::Audio)
}

/// What the permissions centre shows (B5).
#[derive(Clone, Debug, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct PermissionsStatus {
    /// `allowed` | `denied` | `unknown`.
    pub microphone: &'static str,
    /// macOS: reading browser tabs through Automation is switched on in EchoLedger.
    pub browser_automation: bool,
    pub platform: &'static str,
}

#[tauri::command]
pub async fn permissions_status(state: State<'_, AppState>) -> AppResult<PermissionsStatus> {
    let microphone = tauri::async_runtime::spawn_blocking(crate::audio::health::microphone_permission).await.unwrap_or("unknown");
    Ok(PermissionsStatus {
        microphone,
        browser_automation: state.settings().browser_automation,
        platform: if cfg!(windows) { "windows" } else if cfg!(target_os = "macos") { "macos" } else { "other" },
    })
}

/// The system settings page for a permission: `microphone`, `system_audio` (macOS screen and system audio
/// recording) or `automation` (macOS).
pub fn privacy_settings_url(kind: &str, macos: bool) -> Option<&'static str> {
    match (kind, macos) {
        ("microphone", false) => Some("ms-settings:privacy-microphone"),
        ("microphone", true) => Some("x-apple.systempreferences:com.apple.preference.security?Privacy_Microphone"),
        ("system_audio", true) => Some("x-apple.systempreferences:com.apple.preference.security?Privacy_ScreenCapture"),
        ("automation", true) => Some("x-apple.systempreferences:com.apple.preference.security?Privacy_Automation"),
        _ => None,
    }
}

#[tauri::command]
pub fn open_privacy_settings(app: AppHandle, kind: String) -> AppResult<()> {
    use tauri_plugin_opener::OpenerExt;
    let url = privacy_settings_url(&kind, cfg!(target_os = "macos")).ok_or_else(|| AppError::msg("There is no setting for that here."))?;
    app.opener().open_url(url, None::<&str>).map_err(|e| AppError::msg(e.to_string()))
}

/// Bookmarks the current recording now (B3); `tag` is one of the presets or none.
#[tauri::command]
pub fn mark_moment(app: AppHandle, tag: Option<String>, note: Option<String>) -> AppResult<crate::actions::MarkPayload> {
    crate::actions::mark_moment(&app, tag.as_deref(), note.as_deref())
}

/// Sets the tag (and optionally the note) of the latest bookmark: the pill's tag chips after a plain Mark.
#[tauri::command]
pub fn tag_last_mark(app: AppHandle, tag: Option<String>, note: Option<String>) -> AppResult<()> {
    if app.state::<AppState>().capture.tag_last_mark(Some(tag.as_deref().unwrap_or("")), note.as_deref()) {
        Ok(())
    } else {
        Err(AppError::msg("There is no bookmark to tag yet."))
    }
}

/// Removes the latest bookmark of the current recording (Undo on the pill).
#[tauri::command]
pub fn undo_mark(app: AppHandle) -> AppResult<()> {
    app.state::<AppState>().capture.undo_mark().map(|_| ()).ok_or_else(|| AppError::msg("There is no bookmark to undo."))
}

/// The bookmark tags offered during a call: the console's presets, else the defaults.
#[tauri::command]
pub fn marker_tags(state: State<'_, AppState>) -> Vec<String> {
    let config = state.server_config.lock().unwrap_or_else(|p| p.into_inner());
    crate::audio::notes::tag_presets(config.as_ref().and_then(|c| c.markers.as_ref()).map(|m| m.tags.as_slice()))
}

/// How unsure words appear in copied text (the server's `config.unsure`, defaults before the first config).
#[tauri::command]
pub fn unsure_markers(state: State<'_, AppState>) -> crate::net::models::UnsureConfig {
    let config = state.server_config.lock().unwrap_or_else(|p| p.into_inner());
    let mut unsure = config.as_ref().and_then(|c| c.unsure.clone()).unwrap_or_default();
    if unsure.note_text.is_empty() {
        unsure.note_text = crate::net::models::UnsureConfig::default().note_text;
    }
    unsure
}

#[tauri::command]
pub async fn resume_recording(app: AppHandle) -> AppResult<AppStatus> {
    crate::actions::resume_recording(&app).await?;
    Ok(status(&app))
}

#[tauri::command]
pub async fn cancel_recording(app: AppHandle) -> AppResult<AppStatus> {
    crate::actions::cancel_recording(&app).await?;
    Ok(status(&app))
}

#[tauri::command]
pub fn set_pill_hidden(app: AppHandle, hidden: bool) -> AppStatus {
    crate::actions::set_pill_hidden(&app, hidden);
    status(&app)
}

#[tauri::command]
pub fn pill_moved(app: AppHandle, window: WebviewWindow) -> AppResult<()> {
    let Some(position) = crate::overlay::pill_position(&window) else { return Ok(()) };
    let settings = app.state::<AppState>().update_settings(|s| s.pill_position = Some(position))?;
    let _ = app.emit("settings:changed", &settings);
    Ok(())
}

#[tauri::command]
pub fn recording_state(app: AppHandle) -> RecordingState {
    recording_state_now(&app)
}

#[tauri::command]
pub fn pending_list(app: AppHandle) -> Vec<PendingItem> {
    crate::actions::pending_items(&app)
}

#[tauri::command]
pub async fn pending_keep(app: AppHandle, id: String) -> AppResult<()> {
    crate::actions::keep_decision(&app, &id)
}

#[tauri::command]
pub async fn pending_discard(app: AppHandle, id: String) -> AppResult<()> {
    crate::actions::discard_decision(&app, &id)
}

#[tauri::command]
pub fn pending_rename(app: AppHandle, id: String, title: String) -> AppResult<PendingItem> {
    crate::actions::pending_rename(&app, &id, &title)
}

/// The language chip of a waiting recording (review card, waiting list): Keep transcribes in it.
#[tauri::command]
pub fn pending_set_language(app: AppHandle, id: String, language: Option<String>) -> AppResult<PendingItem> {
    crate::actions::pending_set_language(&app, &id, language)
}

/// The language chip on the pill: live captions reconnect in it, and Keep transcribes in it.
#[tauri::command]
pub async fn set_recording_language(app: AppHandle, language: Option<String>) -> AppResult<()> {
    crate::actions::set_recording_language(&app, language).await
}

#[tauri::command]
pub async fn pending_assign_client(app: AppHandle, id: String, client_id: Option<String>) -> AppResult<PendingItem> {
    crate::actions::pending_assign_client(&app, &id, client_id).await
}

#[tauri::command]
pub async fn pending_open(app: AppHandle, id: String, rename: bool) -> AppResult<()> {
    crate::actions::pending_open(&app, &id, rename)
}

#[tauri::command]
pub fn review_state(app: AppHandle) -> Option<ReviewPayload> {
    crate::actions::review_payload(&app)
}

// ---- Live Translate (v1.3, plan B6) ---------------------------------------------------------------------------------

/// The apps playing sound now, to pick one for Live Translate.
#[tauri::command]
pub fn translate_apps() -> Vec<crate::translate::TranslateApp> {
    crate::translate::apps()
}

/// What Live Translate shows now (null when it is off).
#[tauri::command]
pub fn translate_status() -> Option<crate::translate::Feed> {
    crate::translate::current_feed()
}

/// Starts Game or Watch translate for one app: a 10-minute Soniox key from the server, the app's own sound, and the
/// click-through overlay. Returns the hotkeys another app already holds (empty when both are ours).
#[tauri::command]
pub async fn translate_start(app: AppHandle, state: State<'_, AppState>, request: crate::translate::StartRequest) -> AppResult<Vec<String>> {
    let crate::translate::StartRequest { pid, name, mode, target, hints, speed } = request;
    let api = state.api()?;
    let fetch = move || {
        let (api, target, hints) = (api.clone(), target.clone(), hints.clone());
        async move { api.live_translate_token(mode.key(), &target, &hints).await.map_err(|e| e.to_string()) }
    };
    crate::overlay::create_translate_window(&app, mode == crate::translate::Mode::Watch).map_err(|e| AppError::Message(e.to_string()))?;
    crate::translate::start(app.clone(), pid, name, mode, speed, fetch).map_err(AppError::Message)?;
    Ok(crate::translate::register_hotkeys(&app))
}

#[tauri::command]
pub fn translate_stop(app: AppHandle) {
    crate::translate::stop(&app);
}

/// Edit (move the overlay with the mouse) or lock it again (click-through), like Ctrl+Shift+T.
#[tauri::command]
pub fn translate_toggle_lock(app: AppHandle) {
    crate::translate::toggle_lock(&app);
}
