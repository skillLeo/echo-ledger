//! High-level actions shared by shortcuts, the tray, auto mode and UI commands.

use std::sync::atomic::Ordering;
use std::time::Instant;

use tauri::{AppHandle, Emitter, Manager};
use tauri_plugin_clipboard_manager::ClipboardExt;

use crate::audio::types::Channel;
use crate::audio::{FinishedRecording, RecordingMode};
use crate::error::{AppError, AppResult};
use crate::net::models::UsageReport;
use crate::net::sync::paths;
use crate::net::uploader::{
    AfterAttempt, DiscardQueue, PendingUpload, UploadOutcome, UploadQueue, UploadTiming, UsageQueue, after_attempt, delete_local_audio, states,
};
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
        ..PendingUpload::default()
    };
    queue_upload(app, item)
}

/// Appends an item to `uploads.json` and wakes the upload worker.
pub fn queue_upload(app: &AppHandle, item: PendingUpload) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    let path = paths::uploads(&state.data_dir);
    {
        let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
        let mut queue = UploadQueue::load(&path);
        queue.items.push(item);
        queue.save(&path)?;
    }
    state.upload_notify.notify_one();
    emit_uploads(app);
    Ok(())
}

/// Appends a streaming usage report to `usage.json` (sent by the worker once the server speaks v1.1). Called when
/// a captions or dictation run ends.
#[allow(dead_code)] // the caption and dictation runs report their periods through this
pub fn queue_usage_report(app: &AppHandle, report: UsageReport) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    let path = paths::usage(&state.data_dir);
    {
        let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
        let mut queue = UsageQueue::load(&path);
        queue.push(report);
        queue.save(&path)?;
    }
    state.upload_notify.notify_one();
    Ok(())
}

/// Queues a server session for deletion (`discards.json`): a discarded recording whose session was opened.
pub fn queue_discard(app: &AppHandle, session_id: String) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    let path = paths::discards(&state.data_dir);
    {
        let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
        let mut queue = DiscardQueue::load(&path);
        queue.push(session_id);
        queue.save(&path)?;
    }
    state.upload_notify.notify_one();
    Ok(())
}

/// `uploads:changed` with the whole queue, oldest first.
pub fn emit_uploads(app: &AppHandle) {
    let state = app.state::<crate::AppState>();
    let items = UploadQueue::load(&paths::uploads(&state.data_dir)).ui_items();
    let _ = app.emit("uploads:changed", items);
}

const NOT_IN_QUEUE: &str = "This upload is no longer in the queue.";

/// `upload_retry`: a failed item goes back to the queue with a fresh automatic restart.
pub fn retry_upload(app: &AppHandle, id: &str) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    let path = paths::uploads(&state.data_dir);
    {
        let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
        let mut queue = UploadQueue::load(&path);
        let item = queue.items.iter_mut().find(|i| i.id() == id).ok_or_else(|| AppError::msg(NOT_IN_QUEUE))?;
        if item.is_failed() {
            item.state = states::QUEUED.into();
            item.full_restarts = 0;
            item.attempts = 0;
            item.last_error = None;
            queue.save(&path)?;
        }
    }
    state.upload_notify.notify_one();
    emit_uploads(app);
    emit_status(app);
    Ok(())
}

/// `upload_discard`: removes the item, deletes its audio, and queues its server session (if any) for deletion.
pub fn discard_upload(app: &AppHandle, id: &str) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    let path = paths::uploads(&state.data_dir);
    let item = {
        let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
        let mut queue = UploadQueue::load(&path);
        let at = queue.items.iter().position(|i| i.id() == id).ok_or_else(|| AppError::msg(NOT_IN_QUEUE))?;
        let item = queue.items.remove(at);
        queue.save(&path)?;
        item
    };
    if let Some(session_id) = item.server_session() {
        queue_discard(app, session_id)?;
    }
    delete_local_audio(&item);
    emit_uploads(app);
    emit_status(app);
    Ok(())
}

/// Replaces the stored copy of an item (by id); an item discarded meanwhile is not brought back.
fn store_item(app: &AppHandle, item: &PendingUpload) {
    let state = app.state::<crate::AppState>();
    let path = paths::uploads(&state.data_dir);
    let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
    let mut queue = UploadQueue::load(&path);
    let id = item.id();
    if let Some(slot) = queue.items.iter_mut().find(|i| i.id() == id) {
        *slot = item.clone();
        let _ = queue.save(&path);
    }
}

fn current_item(app: &AppHandle, id: &str) -> Option<PendingUpload> {
    let state = app.state::<crate::AppState>();
    let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
    UploadQueue::load(&paths::uploads(&state.data_dir)).items.into_iter().find(|i| i.id() == id)
}

/// Removes an uploaded (or server-deleted) item and its local audio.
fn finish_item(app: &AppHandle, item: &PendingUpload) {
    let state = app.state::<crate::AppState>();
    let path = paths::uploads(&state.data_dir);
    {
        let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
        let mut queue = UploadQueue::load(&path);
        let id = item.id();
        queue.items.retain(|i| i.id() != id);
        let _ = queue.save(&path);
    }
    delete_local_audio(item);
}

/// The server no longer knows a client (`404 client_not_found`, A6.DR7): forget it in the settings and in every
/// queued item, so nothing else is refused for it.
fn forget_client(app: &AppHandle, client_id: &str) {
    let state = app.state::<crate::AppState>();
    if state.settings().client_id.as_deref() == Some(client_id)
        && let Ok(settings) = state.update_settings(|s| {
            if s.client_id.as_deref() == Some(client_id) {
                s.client_id = None;
            }
        })
    {
        let _ = app.emit("settings:changed", &settings);
    }
    {
        let path = paths::uploads(&state.data_dir);
        let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
        let mut queue = UploadQueue::load(&path);
        let mut changed = false;
        for item in queue.items.iter_mut().filter(|i| i.client_id.as_deref() == Some(client_id)) {
            item.client_id = None;
            changed = true;
        }
        if changed {
            let _ = queue.save(&path);
        }
    }
    let _ = app.emit("app:toast", "The selected client was removed on the server, so no client is set now.");
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
    // Duration 0 marks an import: the uploader declares only what the file's header says (A6.DR5).
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
        ..PendingUpload::default()
    };
    queue_upload(app, item)
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

const UNPAIRED: &str = "This computer was unpaired on the server. Pair it again to upload.";

/// Background worker: on notify / every 30 s it sends queued usage reports (v1.1 servers), deletes discarded
/// server sessions, then uploads the queue oldest first; refreshes the library after uploads.
pub fn spawn_upload_worker(app: AppHandle) {
    {
        // A crash in the middle of an upload leaves `uploading` behind: such items are simply queued again.
        let state = app.state::<crate::AppState>();
        let path = paths::uploads(&state.data_dir);
        let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
        let mut queue = UploadQueue::load(&path);
        let mut changed = false;
        for item in queue.items.iter_mut().filter(|i| i.state == states::UPLOADING) {
            item.state = states::QUEUED.into();
            changed = true;
        }
        if changed {
            let _ = queue.save(&path);
        }
    }
    tauri::async_runtime::spawn(async move {
        loop {
            let state = app.state::<crate::AppState>();
            let notified = state.upload_notify.notified();
            let _ = tokio::time::timeout(std::time::Duration::from_secs(30), notified).await;
            if state.is_paired() {
                run_upload_cycle(&app).await;
            }
        }
    });
}

async fn run_upload_cycle(app: &AppHandle) {
    let state = app.state::<crate::AppState>();
    let data_dir = state.data_dir.clone();
    let (usage, discards, uploads) = {
        let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
        (
            UsageQueue::load(&paths::usage(&data_dir)).items,
            DiscardQueue::load(&paths::discards(&data_dir)).items,
            UploadQueue::load(&paths::uploads(&data_dir)).items,
        )
    };
    // Failed items wait for Retry; v1 servers get no usage reports (kept until the server is upgraded).
    let pending_uploads: Vec<String> = uploads.iter().filter(|i| !i.is_failed()).map(PendingUpload::id).collect();
    let known_v1 = state.server_config.lock().unwrap_or_else(|p| p.into_inner()).as_ref().is_some_and(|c| !c.is_v1_1());
    let usage_to_send = !usage.is_empty() && !known_v1;
    if pending_uploads.is_empty() && discards.is_empty() && !usage_to_send {
        return;
    }
    let Ok(api) = state.api() else { return };
    let config = match api.config().await {
        Ok(config) => {
            state.apply_config(config.clone());
            config
        }
        Err(AppError::NotPaired) => {
            let _ = app.emit("app:error", UNPAIRED);
            return;
        }
        Err(e) => {
            eprintln!("[echoledger] upload cycle skipped: {e}");
            return;
        }
    };

    if config.is_v1_1() && !usage.is_empty() {
        let drain = crate::net::uploader::drain_usage(&api, &usage).await;
        if !drain.done.is_empty() {
            let path = paths::usage(&data_dir);
            let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
            let mut queue = UsageQueue::load(&path);
            queue.items.retain(|r| !drain.done.contains(&r.report_id));
            let _ = queue.save(&path);
        }
        if let Some(usage) = drain.usage
            && let Some(config) = state.server_config.lock().unwrap_or_else(|p| p.into_inner()).as_mut()
        {
            config.usage = Some(usage);
        }
        if matches!(drain.stopped, Some(AppError::NotPaired)) {
            let _ = app.emit("app:error", UNPAIRED);
            return;
        }
    }

    if !discards.is_empty() {
        let drain = crate::net::uploader::drain_discards(&api, &discards).await;
        if !drain.done.is_empty() {
            let path = paths::discards(&data_dir);
            let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
            let mut queue = DiscardQueue::load(&path);
            queue.items.retain(|id| !drain.done.contains(id));
            let _ = queue.save(&path);
        }
        if matches!(drain.stopped, Some(AppError::NotPaired)) {
            let _ = app.emit("app:error", UNPAIRED);
            return;
        }
    }

    let mut uploaded = 0usize;
    for id in pending_uploads {
        // Work on the current copy: the item may have been discarded or changed since the snapshot.
        let Some(mut item) = current_item(app, &id) else { continue };
        if item.is_failed() {
            continue;
        }
        let was_verifying = item.state == states::VERIFYING;
        if !was_verifying {
            item.state = states::UPLOADING.into();
            store_item(app, &item);
            emit_uploads(app);
        }
        let client_before = item.client_id.clone();
        let persist = |p: &PendingUpload| store_item(app, p);
        let result = crate::net::uploader::upload_with(&api, &config, &mut item, UploadTiming::default(), &persist).await;
        if let (Some(old), None) = (client_before.as_deref(), item.client_id.as_deref()) {
            forget_client(app, old);
        }
        match after_attempt(&mut item, was_verifying, &result) {
            // Every artifact is ready (the server holds the encrypted copy now), or the session was deleted there.
            AfterAttempt::Remove => finish_item(app, &item),
            AfterAttempt::Keep => store_item(app, &item),
        }
        emit_uploads(app);
        match result {
            Ok(UploadOutcome::Ready { .. }) => uploaded += 1,
            Ok(UploadOutcome::Deleted { .. }) => {
                let _ = app.emit("app:toast", "This recording was deleted on the server, so its upload was stopped.");
            }
            Err(AppError::NotPaired) => {
                let _ = app.emit("app:error", UNPAIRED);
                break;
            }
            _ => {}
        }
    }
    if uploaded > 0 {
        let _ = crate::commands::refresh_library_inner(app).await;
        let _ = app.emit("app:toast", format!("Uploaded {uploaded} recording(s) for transcription"));
    }
    emit_status(app);
}
