//! IPC commands for the React UI. Tokens and provider credentials never cross this boundary.

use std::collections::HashMap;

use serde::Serialize;
use tauri::ipc::Channel as IpcChannel;
use tauri::{AppHandle, Emitter, Manager, State, WebviewWindow};

use crate::AppState;
use crate::error::{AppError, AppResult};
use crate::net::api::ApiClient;
use crate::net::sync::{LibraryCache, paths};
use crate::net::uploader::UploadQueue;
use crate::overlay::geometry::{RectPx, WorkArea, nearest_corner};
use crate::overlay::{HitRegions, redock};
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
}

pub fn status(app: &AppHandle) -> AppStatus {
    let state = app.state::<AppState>();
    let settings = state.settings();
    let queue = UploadQueue::load(&paths::uploads(&state.data_dir));
    let recording = state.capture.info();
    crate::tray::set_recording_tooltip(app, recording.is_some(), state.live.is_live());
    AppStatus {
        paired: state.is_paired(),
        server_url: settings.server_url.clone(),
        device_name: settings.device_name.clone(),
        pinned: !settings.pins.is_empty(),
        recording,
        captions_live: state.live.is_live(),
        dictation_active: crate::dictation::is_active(app),
        auto_mode: settings.auto_mode,
        pending_uploads: queue.items.len(),
        upload_errors: queue.items.iter().filter_map(|i| i.last_error.clone()).collect(),
        shortcut_errors: state.shortcut_errors.lock().unwrap().clone(),
        levels: state.capture.levels(),
        platform: if cfg!(windows) { "windows" } else if cfg!(target_os = "macos") { "macos" } else { "other" },
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
    client_id: Option<Option<String>>,
    consent_acknowledged: Option<bool>,
    overlay_corner: Option<crate::overlay::geometry::Corner>,
}

#[tauri::command]
pub fn update_settings(app: AppHandle, patch: SettingsPatch) -> AppResult<Settings> {
    use tauri_plugin_autostart::ManagerExt;
    let state = app.state::<AppState>();
    let updated = state.update_settings(|s| {
        if let Some(v) = patch.captions_on_record { s.captions_on_record = v; }
        if let Some(v) = patch.auto_mode { s.auto_mode = v; }
        if let Some(v) = patch.content_protection { s.content_protection = v; }
        if let Some(v) = patch.autostart { s.autostart = v; }
        if let Some(v) = &patch.dictation_output && (v == "type" || v == "clipboard") { s.dictation_output = v.clone(); }
        if let Some(v) = &patch.client_id { s.client_id = v.clone(); }
        if let Some(v) = patch.consent_acknowledged { s.consent_acknowledged = v; }
        if let Some(v) = patch.overlay_corner { s.overlay_corner = v; }
    })?;
    if patch.content_protection.is_some() {
        crate::overlay::set_content_protection(&app, updated.content_protection);
    }
    if let Some(enable) = patch.autostart {
        let manager = app.autolaunch();
        let _ = if enable { manager.enable() } else { manager.disable() };
    }
    if let Some(corner) = patch.overlay_corner
        && let Some(w) = app.get_webview_window(crate::overlay::OVERLAY)
    {
        redock(&w, corner);
    }
    crate::actions::emit_status(&app);
    Ok(updated)
}

#[tauri::command]
pub fn set_shortcut(app: AppHandle, action: Action, accelerator: String) -> AppResult<Settings> {
    shortcuts::rebind(&app, action, &accelerator).map_err(AppError::Message)?;
    let state = app.state::<AppState>();
    state.shortcut_errors.lock().unwrap().retain(|(a, _)| *a != action);
    state.update_settings(|s| {
        s.shortcuts.insert(action, accelerator.trim().to_string());
    })
}

#[tauri::command]
pub fn reset_shortcuts(app: AppHandle) -> AppResult<Settings> {
    let state = app.state::<AppState>();
    let settings = state.update_settings(|s| s.shortcuts = shortcuts::default_map())?;
    *state.shortcut_errors.lock().unwrap() = shortcuts::register_all(&app, &settings.shortcuts);
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
        app.state::<AppState>().update_settings(|s| s.overlay_corner = corner)?;
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
