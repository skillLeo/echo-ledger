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
    if let Some(Some(language)) = &patch.transcription_language
        && crate::settings::language_or_none(Some(language)).is_none()
    {
        return Err(AppError::msg("Choose Automatic, English, Urdu, Hindi or English + Urdu."));
    }
    if let Some(problem) = patch.caption_style.as_ref().and_then(|s| s.problem()) {
        return Err(AppError::msg(problem));
    }
    if let Some(apps) = &patch.auto_record_apps
        && apps.keys().any(|app| crate::detect::classify::SourceApp::from_wire(app).is_none())
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
#[tauri::command]
pub async fn export_transcript(app: AppHandle, id: String, format: String, timestamps: bool, speakers: bool) -> AppResult<String> {
    use tauri_plugin_opener::OpenerExt;
    const FORMATS: [&str; 7] = ["txt", "md", "srt", "vtt", "json", "docx", "html"];
    if !FORMATS.contains(&format.as_str()) {
        return Err(AppError::msg("That export format is not available."));
    }
    let state = app.state::<AppState>();
    let (bytes, filename) = state.api()?.export_transcript(&id, &format, timestamps, speakers).await?;
    let dir = if format == "html" { std::env::temp_dir() } else { app.path().download_dir().unwrap_or_else(|_| state.data_dir.clone()) };
    let path = export_target(&dir, &filename);
    std::fs::write(&path, bytes)?;
    if format == "html" {
        app.opener().open_path(path.to_string_lossy(), None::<&str>).map_err(|e| AppError::msg(e.to_string()))?;
    }
    Ok(path.to_string_lossy().into_owned())
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
pub fn dictation_history(app: AppHandle) -> Vec<crate::dictation::rules::HistoryEntry> {
    crate::dictation::history(&app)
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
