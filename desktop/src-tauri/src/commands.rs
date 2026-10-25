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
    crate::tray::update(app, tray_state, paused, pill_hidden, pending_decisions);
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
    if patch.caption_lines.is_some() {
        // Resizes and re-docks in the setting's corner (never `show()`).
        crate::overlay::resize_captions(&app, updated.caption_lines, updated.overlay_corner);
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

#[tauri::command]
pub fn overlay_set_hit_regions(window: WebviewWindow, regions: Vec<RectPx>, state: State<'_, HitRegions>) {
    state.0.lock().unwrap().insert(window.label().to_string(), regions);
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
    refresh_library_inner(&app).await?;
    Ok(app.state::<AppState>().library.lock().await.items.clone())
}

#[tauri::command]
pub async fn session_detail(state: State<'_, AppState>, id: String) -> AppResult<serde_json::Value> {
    state.api()?.session(&id).await
}

#[tauri::command]
pub async fn transcript_detail(state: State<'_, AppState>, id: String) -> AppResult<serde_json::Value> {
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
    }
}

#[tauri::command]
pub async fn pause_recording(app: AppHandle) -> AppResult<AppStatus> {
    crate::actions::pause_recording(&app).await?;
    Ok(status(&app))
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