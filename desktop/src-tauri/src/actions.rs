//! High-level actions shared by shortcuts, the tray, auto mode and UI commands.

use std::sync::atomic::Ordering;
use std::time::Instant;

use tauri::{AppHandle, Emitter, Manager};
use tauri_plugin_clipboard_manager::ClipboardExt;

use crate::audio::types::Channel;
use crate::audio::{FinishedRecording, RecordingMode};
use crate::error::{AppError, AppResult};
use crate::net::sync::paths;
use crate::net::uploader::{PendingUpload, UploadQueue};
use crate::overlay::{OVERLAY, set_overlay_visible};
use crate::shortcuts::Action;
use crate::stt::captions::{CaptionEvent, CaptionHub};

const CAPTION_TARGETS: &[&str] = &[OVERLAY, "main"];

pub async fn on_shortcut(app: AppHandle, action: Action, pressed: bool) {
    let result = match (action, pressed) {
        (Action::ToggleCaptions, true) => toggle_captions(&app).await,
        (Action::ToggleSaving, true) => toggle_recording(&app).await,
        (Action::CopyLast2Min, true) => copy_last_minutes(&app, 2).map(|_| ()),
        (Action::VoiceNote, true) => toggle_voice_note(&app).await,
        (Action::Dictation, true) => {
            if crate::dictation::is_active(&app) {
                app.state::<crate::AppState>().dictation_stop_requested.store(true, Ordering::SeqCst);
                Ok(())
            } else {
                crate::dictation::start(&app).await
            }
        }
        // Type only after the keys are released, so held modifiers never combine with typed text.
        (Action::Dictation, false) => {
            if app.state::<crate::AppState>().dictation_stop_requested.swap(false, Ordering::SeqCst) {
                crate::dictation::finish(&app).await.map(|_| ())
            } else {
                Ok(())
            }
        }
        _ => Ok(()),
    };
    if let Err(e) = result {
        eprintln!("[echoledger] {action:?}: {e}");
        let _ = app.emit("app:error", e.to_string());
    }
}

pub fn emit_status(app: &AppHandle) {
    let _ = app.emit("app:status", crate::commands::status(app));
}

/// Live captions on/off. Captions need audio, so turning them on while idle starts a recording (captions on);
/// turning them off only hides captions — an ongoing recording keeps running and is saved as usual.
pub async fn toggle_captions(app: &AppHandle) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    if state.live.is_live() {
        stop_captions(app);
        return Ok(());
    }
    if !state.capture.is_recording() {
        return start_call_recording_with(app, "other", false, true).await;
    }
    start_captions(app).await
}

/// Empties the caption buffer and every caption window, so a new session never shows the previous one's lines.
pub fn clear_captions(app: &AppHandle) {
    app.state::<crate::AppState>().captions.lock().unwrap().clear();
    app.state::<CaptionHub>().send(CAPTION_TARGETS, CaptionEvent::Clear);
}

pub fn stop_captions(app: &AppHandle) {
    let state = app.state::<crate::AppState>();
    state.live.stop();
    state.capture.set_live_sink(Channel::Mic, None);
    state.capture.set_live_sink(Channel::System, None);
    set_overlay_visible(app, OVERLAY, false);
    app.state::<CaptionHub>().send(CAPTION_TARGETS, CaptionEvent::Status { live: false, recording: state.capture.is_recording(), provider: None, message: None });
    emit_status(app);
}

pub async fn start_captions(app: &AppHandle) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    if !state.capture.is_recording() {
        return Err(AppError::msg("Start a recording (or let auto mode detect a call) to see live captions."));
    }
    set_overlay_visible(app, OVERLAY, true);
    let channels = match state.server_caption_channels().as_str() {
        "client_only" => vec![Channel::System],
        _ => vec![Channel::Mic, Channel::System],
    };
    let started_at = *state.recording_started_at.lock().unwrap();
    let senders = state.live.start(app, "live_captions", &channels, CAPTION_TARGETS, true, started_at).await?;
    for (channel, sink) in senders {
        state.capture.set_live_sink(channel, Some(sink));
    }
    emit_status(app);
    Ok(())
}

pub async fn start_call_recording(app: &AppHandle, source_app: &str, auto: bool) -> AppResult<()> {
    let captions = app.state::<crate::AppState>().settings().captions_on_record;
    start_call_recording_with(app, source_app, auto, captions).await
}

/// Starts a two-channel recording from a clean slate; `captions` decides whether live captions open with it.
pub async fn start_call_recording_with(app: &AppHandle, source_app: &str, auto: bool, captions: bool) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    if state.capture.is_recording() {
        return Ok(());
    }
    if !state.is_paired() {
        return Err(AppError::NotPaired);
    }
    let client_id = state.settings().client_id.clone();
    clear_captions(app); // a new recording never inherits the previous one's lines
    state.capture.start(&paths::recordings(&state.data_dir), RecordingMode::Call, source_app, client_id)?;
    *state.recording_started_at.lock().unwrap() = Some(Instant::now());
    state.auto_started.store(auto, Ordering::SeqCst);
    emit_status(app);
    if captions && let Err(e) = start_captions(app).await {
        let _ = app.emit("app:error", e.to_string());
    }
    Ok(())
}

/// Voice note while audio plays on this computer (system audio only).
pub async fn toggle_voice_note(app: &AppHandle) -> AppResult<()> {
    toggle_note(app, RecordingMode::VoiceNote).await
}

/// Voice note spoken into the microphone (mic only, mono).
pub async fn toggle_mic_note(app: &AppHandle) -> AppResult<()> {
    toggle_note(app, RecordingMode::MicNote).await
}

async fn toggle_note(app: &AppHandle, mode: RecordingMode) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    if state.capture.is_recording() {
        return stop_recording(app, false).await;
    }
    if !state.is_paired() {
        return Err(AppError::NotPaired);
    }
    clear_captions(app);
    state.capture.start(&paths::recordings(&state.data_dir), mode, "voice_note", state.settings().client_id.clone())?;
    *state.recording_started_at.lock().unwrap() = Some(Instant::now());
    state.auto_started.store(false, Ordering::SeqCst);
    emit_status(app);
    Ok(())
}

pub async fn toggle_recording(app: &AppHandle) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    if state.capture.is_recording() {
        stop_recording(app, false).await
    } else {
        start_call_recording(app, "other", false).await
    }
}

/// Stops the recording, queues the upload, and kicks the upload worker. Auto mode never stops manual recordings.
pub async fn stop_recording(app: &AppHandle, from_auto: bool) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    if from_auto && !state.auto_started.load(Ordering::SeqCst) {
        return Ok(());
    }
    if state.live.is_live() {
        state.live.stop();
        tokio::time::sleep(std::time::Duration::from_millis(800)).await; // final turns flush
    }
    let finished = state.capture.stop()?;
    set_overlay_visible(app, OVERLAY, false);
    *state.recording_started_at.lock().unwrap() = None;
    if let Some(finished) = finished {
        // A container with (almost) no audio frames means nothing was heard: tell the user instead of uploading a
        // recording the server would reject (the server enforces the same floor).
        let silent = std::fs::metadata(&finished.output.primary).map(|m| m.len() < MIN_AUDIO_BYTES).unwrap_or(true);
        if silent {
            let _ = std::fs::remove_dir_all(&finished.output.dir);
            let _ = app.emit("app:error", "No sound was captured, so nothing was saved. Check your microphone and the audio device, then try again.");
        } else {
            enqueue(app, finished)?;
        }
    }
    clear_captions(app); // after enqueue (it reads the buffered turns), so the next session starts empty
    emit_status(app);
    Ok(())
}

/// Smaller than any real recording: the container headers alone are a few hundred bytes.
const MIN_AUDIO_BYTES: u64 = 1024;

fn enqueue(app: &AppHandle, finished: FinishedRecording) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    let live_segments = state.captions.lock().unwrap().segments();
    let item = PendingUpload {
        client_ref: finished.info.client_ref.clone(),
        source_app: finished.info.source_app.clone(),
        client_id: finished.info.client_id.clone(),
        started_at: finished.info.started_at.to_rfc3339(),
        ended_at: finished.ended_at.to_rfc3339(),
        duration_ms: finished.output.duration_ms,
        dir: finished.output.dir.clone(),
        primary: finished.output.primary.clone(),
        primary_channels: finished.output.channels,
        mic_wav: (finished.info.mode == RecordingMode::Call).then(|| finished.output.mic_wav.clone()),
        system_wav: (finished.info.mode == RecordingMode::Call).then(|| finished.output.system_wav.clone()),
        live_segments,
        live_provider: state.live.provider(),
        attempts: 0,
        last_error: None,
    };
    let path = paths::uploads(&state.data_dir);
    {
        let _guard = state.queue_lock.lock().unwrap();
        let mut queue = UploadQueue::load(&path);
        queue.items.push(item);
        queue.save(&path)?;
    }
    state.upload_notify.notify_one();
    Ok(())
}

/// Imports an audio file (drag-drop) as a voice note and queues it.
pub fn import_voice_note(app: &AppHandle, source: &std::path::Path) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    let client_ref = uuid::Uuid::new_v4().to_string();
    let dir = paths::recordings(&state.data_dir).join(&client_ref);
    std::fs::create_dir_all(&dir)?;
    let name = source.file_name().ok_or_else(|| AppError::msg("not a file"))?;
    let primary = dir.join(name);
    std::fs::copy(source, &primary)?;
    let now = chrono::Utc::now().to_rfc3339();
    let item = PendingUpload {
        client_ref,
        source_app: "voice_note".into(),
        client_id: state.settings().client_id.clone(),
        started_at: now.clone(),
        ended_at: now,
        duration_ms: 0,
        dir,
        primary,
        primary_channels: 1,
        mic_wav: None,
        system_wav: None,
        live_segments: vec![],
        live_provider: None,
        attempts: 0,
        last_error: None,
    };
    let path = paths::uploads(&state.data_dir);
    {
        let _guard = state.queue_lock.lock().unwrap();
        let mut queue = UploadQueue::load(&path);
        queue.items.push(item);
        queue.save(&path)?;
    }
    state.upload_notify.notify_one();
    Ok(())
}

pub fn copy_last_minutes(app: &AppHandle, minutes: u64) -> AppResult<String> {
    let state = app.state::<crate::AppState>();
    let text = state.captions.lock().unwrap().text_since(crate::stt::now_ms(), minutes * 60_000);
    if text.is_empty() {
        return Err(AppError::msg("No captions in the last minutes yet."));
    }
    app.clipboard().write_text(text.clone()).map_err(|e| AppError::msg(e.to_string()))?;
    let _ = app.emit("app:toast", format!("Copied the last {minutes} minutes of captions"));
    Ok(text)
}

/// Background worker: drains the upload queue on notify / every 30 s; refreshes the library after uploads.
pub fn spawn_upload_worker(app: AppHandle) {
    tauri::async_runtime::spawn(async move {
        loop {
            let state = app.state::<crate::AppState>();
            let notified = state.upload_notify.notified();
            let _ = tokio::time::timeout(std::time::Duration::from_secs(30), notified).await;
            if !state.is_paired() {
                continue;
            }
            let Ok(api) = state.api() else { continue };
            let path = paths::uploads(&state.data_dir);
            let snapshot = {
                let _guard = state.queue_lock.lock().unwrap();
                UploadQueue::load(&path).items
            };
            if snapshot.is_empty() {
                continue;
            }
            let mut done: Vec<String> = Vec::new();
            let mut failed: Vec<PendingUpload> = Vec::new();
            for mut item in snapshot {
                match crate::net::uploader::upload(&api, &item).await {
                    Ok(_) => {
                        let _ = std::fs::remove_dir_all(&item.dir); // server holds the encrypted copy now
                        done.push(item.client_ref);
                    }
                    Err(AppError::NotPaired) => {
                        let _ = app.emit("app:error", "This computer was unpaired on the server. Pair it again to upload.");
                        break;
                    }
                    Err(e) => {
                        item.attempts += 1;
                        item.last_error = Some(e.to_string());
                        failed.push(item);
                    }
                }
            }
            {
                // Items enqueued while we uploaded are preserved; uploaded ones removed; failures updated.
                let _guard = state.queue_lock.lock().unwrap();
                let mut current = UploadQueue::load(&path);
                current.items.retain(|i| !done.contains(&i.client_ref));
                for item in current.items.iter_mut() {
                    if let Some(f) = failed.iter().find(|f| f.client_ref == item.client_ref) {
                        *item = f.clone();
                    }
                }
                let _ = current.save(&path);
            }
            let uploaded = done.len();
            if uploaded > 0 {
                let _ = crate::commands::refresh_library_inner(&app).await;
                let _ = app.emit("app:toast", format!("Uploaded {uploaded} recording(s) for transcription"));
            }
            emit_status(&app);
        }
    });
}
