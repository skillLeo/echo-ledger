//! High-level actions shared by shortcuts, the tray, auto mode and UI commands.

use std::sync::atomic::Ordering;
use std::time::{Duration, Instant};

use chrono::Utc;

use tauri::{AppHandle, Emitter, Manager};
use tauri_plugin_clipboard_manager::ClipboardExt;

use crate::audio::types::Channel;
use crate::audio::{FinishedRecording, RecordingMode};
use crate::error::{AppError, AppResult};
use crate::net::models::{OpenSessionRequest, UsageReport};
use crate::pending::{self, Attach, DecisionFiles, DecisionInput, PendingDecision};
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
        (Action::Mark, true) => mark_moment(&app, None, None).map(|_| ()),
        (Action::Dictation, true) => {
            let push_to_talk = app.state::<crate::AppState>().settings().dictation_mode == "push_to_talk";
            if crate::dictation::is_active(&app) && !push_to_talk {
                app.state::<crate::AppState>().dictation_stop_requested.store(true, Ordering::SeqCst);
                Ok(())
            } else if crate::dictation::is_active(&app) {
                Ok(())
            } else {
                crate::dictation::start(&app).await
            }
        }
        // Type only after the keys are released, so held modifiers never combine with typed text. Hold to talk
        // ends on every release; a release while the stream is still opening is picked up by `start`.
        (Action::Dictation, false) => {
            let state = app.state::<crate::AppState>();
            let push_to_talk = state.settings().dictation_mode == "push_to_talk";
            if push_to_talk && !crate::dictation::is_active(&app) {
                state.dictation_stop_requested.store(true, Ordering::SeqCst);
                Ok(())
            } else if push_to_talk || state.dictation_stop_requested.swap(false, Ordering::SeqCst) {
                state.dictation_stop_requested.store(false, Ordering::SeqCst);
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

/// `recording:marked`: a bookmark was added to the current recording (the pill shows the tag presets and Undo).
#[derive(Clone, Debug, PartialEq, Eq, serde::Serialize)]
#[serde(rename_all = "camelCase")]
pub struct MarkPayload {
    pub at_ms: u64,
    pub tag: Option<String>,
    pub note: Option<String>,
    /// Bookmarks in this recording so far.
    pub count: usize,
}

/// Bookmarks the current recording at the recorded time now (B3), from the shortcut or the pill.
pub fn mark_moment(app: &AppHandle, tag: Option<&str>, note: Option<&str>) -> AppResult<MarkPayload> {
    let state = app.state::<crate::AppState>();
    let marker = state.capture.mark(tag, note)?;
    let payload = MarkPayload { at_ms: marker.at_ms, tag: marker.tag, note: marker.note, count: state.capture.markers().len() };
    let _ = app.emit("recording:marked", &payload);
    Ok(payload)
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
    let Some(info) = state.capture.info() else {
        return Err(AppError::msg("Start a recording (or let auto mode detect a call) to see live captions."));
    };
    if state.capture.is_paused() {
        state.captions_on_resume.store(true, Ordering::SeqCst);
        return Err(AppError::msg("Resume the recording to turn on live captions."));
    }
    set_overlay_visible(app, OVERLAY, true);
    let channels = match state.server_caption_channels().as_str() {
        "client_only" => vec![Channel::System],
        _ => vec![Channel::Mic, Channel::System],
    };
    // A v1.1 server hands out caption tokens only for an open session of this recording (A5.1); with captions off
    // nothing reaches the server until Keep.
    let session_id = if state.server_is_v1_1() {
        match ensure_server_session(app, &info).await {
            Ok(id) => Some(id),
            Err(e) => {
                set_overlay_visible(app, OVERLAY, false);
                return Err(e);
            }
        }
    } else {
        None
    };
    let base_offset_ms = state.capture.elapsed_ms();
    let senders = match state.live.start(app, "live_captions", &channels, CAPTION_TARGETS, true, base_offset_ms, session_id).await {
        Ok(senders) => senders,
        Err(e) => {
            set_overlay_visible(app, OVERLAY, false);
            emit_status(app);
            return Err(e);
        }
    };
    for (channel, sink) in senders {
        state.capture.set_live_sink(channel, Some(sink));
    }
    emit_status(app);
    emit_recording_state(app);
    Ok(())
}

/// The open server session of the running recording, opened on first use (live captions on a v1.1 server).
async fn ensure_server_session(app: &AppHandle, info: &crate::audio::RecordingInfo) -> AppResult<String> {
    let state = app.state::<crate::AppState>();
    if let Some(id) = state.session_link.lock().unwrap_or_else(|p| p.into_inner()).session_for(&info.client_ref) {
        return Ok(id);
    }
    let api = state.api()?;
    let request = OpenSessionRequest {
        client_ref: info.client_ref.clone(),
        source_app: info.source_app.clone(),
        client_id: info.client_id.clone(),
        title: None,
        started_at: info.started_at.to_rfc3339(),
        language: state.settings().transcription_language,
    };
    let (response, cleared) = crate::net::api::with_client_fallback(info.client_id.clone(), |client| {
        let (api, mut request) = (api.clone(), request.clone());
        request.client_id = client;
        async move { api.open_session(&request).await }
    })
    .await
    .map_err(|e| crate::stt::token_error("live_captions", e))?;
    if let Some(client) = cleared {
        forget_client(app, &client);
        state.capture.clear_client(&client);
    }
    let id = response.session.id;
    let placed = state.session_link.lock().unwrap_or_else(|p| p.into_inner()).attach(&info.client_ref, &id);
    match placed {
        Attach::Current => Ok(id),
        // The recording stopped meanwhile: the stop flow (or its outcome) owns the session now.
        Attach::Deferred => Err(AppError::msg("The recording ended before live captions could start.")),
        Attach::Orphan => {
            queue_discard(app, id)?;
            Err(AppError::msg("The recording ended before live captions could start."))
        }
    }
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
    let info = state.capture.start(&paths::recordings(&state.data_dir), RecordingMode::Call, source_app, client_id)?;
    *state.recording_started_at.lock().unwrap() = Some(Instant::now());
    state.auto_started.store(auto, Ordering::SeqCst);
    on_recording_started(app, &info);
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
    let info = state.capture.start(&paths::recordings(&state.data_dir), mode, "voice_note", state.settings().client_id.clone())?;
    *state.recording_started_at.lock().unwrap() = Some(Instant::now());
    state.auto_started.store(false, Ordering::SeqCst);
    on_recording_started(app, &info);
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

/// Smaller than any real recording: the container headers alone are a few hundred bytes.
const MIN_AUDIO_BYTES: u64 = 1024;

/// A recording started: fresh session link, pill shown (unless the user hides it later), state ticker running.
fn on_recording_started(app: &AppHandle, info: &crate::audio::RecordingInfo) {
    let state = app.state::<crate::AppState>();
    state.session_link.lock().unwrap_or_else(|p| p.into_inner()).start(&info.client_ref);
    state.pill_hidden.store(false, Ordering::SeqCst);
    state.captions_on_resume.store(false, Ordering::SeqCst);
    let _ = app.emit_to(crate::overlay::PILL, "pill:visibility", true);
    start_ticker(app);
    emit_status(app);
    emit_recording_state(app);
}

/// Stops captions (waiting for their final turns) and the capture. Returns the finished files, the server session
/// that captions opened (if any) and the caption provider.
async fn end_capture(app: &AppHandle) -> AppResult<Option<(FinishedRecording, Option<String>, Option<String>)>> {
    let state = app.state::<crate::AppState>();
    let provider = state.live.provider();
    if state.live.is_live() {
        state.live.stop();
        state.live.wait_closed(Duration::from_secs(3)).await; // final turns flush
    }
    state.captions_on_resume.store(false, Ordering::SeqCst);
    let session = state.session_link.lock().unwrap_or_else(|p| p.into_inner()).begin_stop();
    let finished = state.capture.stop()?;
    set_overlay_visible(app, OVERLAY, false);
    let _ = app.emit_to(crate::overlay::PILL, "pill:visibility", false);
    *state.recording_started_at.lock().unwrap() = None;
    Ok(finished.map(|f| (f, session, provider)))
}

/// Stops the recording and asks Keep / Discard (A2), or keeps it at once when the "Ask before saving" policy says
/// so. Auto mode never stops a manual recording.
pub async fn stop_recording(app: &AppHandle, from_auto: bool) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    if from_auto && !state.auto_started.load(Ordering::SeqCst) {
        return Ok(());
    }
    let auto = state.auto_started.load(Ordering::SeqCst);
    if let Some((finished, session, provider)) = end_capture(app).await? {
        decide_finished(app, finished, session, provider, auto, true)?;
    }
    clear_captions(app); // after the decision (it reads the buffered turns), so the next session starts empty
    emit_status(app);
    emit_recording_state(app);
    Ok(())
}

/// Quit while recording: the recording is stopped and stays as a decision (never discarded); no card.
pub async fn stop_recording_for_quit(app: &AppHandle) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    let auto = state.auto_started.load(Ordering::SeqCst);
    if let Some((finished, session, provider)) = end_capture(app).await? {
        decide_finished(app, finished, session, provider, auto, false)?;
    }
    Ok(())
}

/// Stop and discard at once (the pill's Cancel): nothing is kept, uploaded or transcribed; no card.
pub async fn cancel_recording(app: &AppHandle) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    if !state.capture.is_recording() {
        return Err(AppError::msg("Nothing is recording."));
    }
    if let Some((finished, session, _)) = end_capture(app).await? {
        let late = state.session_link.lock().unwrap_or_else(|p| p.into_inner()).end_stop(&finished.info.client_ref);
        let _ = std::fs::remove_dir_all(&finished.output.dir);
        for id in session.into_iter().chain(late) {
            queue_discard(app, id)?;
        }
    }
    clear_captions(app);
    emit_status(app);
    emit_recording_state(app);
    Ok(())
}

fn decide_finished(app: &AppHandle, finished: FinishedRecording, session: Option<String>, provider: Option<String>, auto: bool, show_card: bool) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    let client_ref = finished.info.client_ref.clone();
    let late = state.session_link.lock().unwrap_or_else(|p| p.into_inner()).end_stop(&client_ref);
    // A container with (almost) no audio frames means nothing was heard: tell the user instead of keeping a
    // recording the server would reject (the server enforces the same floor).
    let silent = std::fs::metadata(&finished.output.primary).map(|m| m.len() < MIN_AUDIO_BYTES).unwrap_or(true);
    if silent {
        let _ = std::fs::remove_dir_all(&finished.output.dir);
        for id in session.into_iter().chain(late) {
            queue_discard(app, id)?;
        }
        let _ = app.emit("app:error", "No sound was captured, so nothing was saved. Check your microphone and the audio device, then try again.");
        return Ok(());
    }

    let settings = state.settings();
    let live_segments = state.captions.lock().unwrap().segments();
    let mode = finished.info.mode;
    let decision = PendingDecision::from_recording(
        &finished,
        DecisionInput {
            auto,
            client_name: None,
            live_segments,
            live_provider: provider,
            server_session_id: session,
            created_at: Utc::now(),
            language: settings.transcription_language.clone(),
        },
    );
    let id = decision.id.clone();
    let created_at = decision.created_at;
    {
        let files = DecisionFiles::in_dir(&state.data_dir);
        let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
        files.add(decision)?;
        if let Some(late) = late {
            files.set_server_session(&id, &late)?;
        }
    }
    if !pending::should_ask(settings.ask_before_saving, auto, mode) {
        return keep_decision(app, &id);
    }
    if show_card {
        state.review_run.lock().unwrap_or_else(|p| p.into_inner()).push(id);
    }
    emit_pending(app);
    refresh_review(app);
    schedule_timeout(app, created_at);
    Ok(())
}

/// Keep: the recording moves to the upload queue and the final transcript follows.
pub fn keep_decision(app: &AppHandle, id: &str) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    {
        let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
        DecisionFiles::in_dir(&state.data_dir).keep(id)?;
    }
    state.upload_notify.notify_one();
    after_decision(app, id);
    emit_uploads(app);
    Ok(())
}

/// Discard: files deleted, nothing uploaded, no transcript requested; an open server session is deleted.
pub fn discard_decision(app: &AppHandle, id: &str) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    {
        let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
        DecisionFiles::in_dir(&state.data_dir).discard(id)?;
    }
    state.upload_notify.notify_one(); // the worker deletes a queued server session
    after_decision(app, id);
    Ok(())
}

fn after_decision(app: &AppHandle, id: &str) {
    app.state::<crate::AppState>().review_run.lock().unwrap_or_else(|p| p.into_inner()).retain(|r| r != id);
    emit_pending(app);
    refresh_review(app);
    emit_status(app);
}

pub fn pending_items(app: &AppHandle) -> Vec<pending::PendingItem> {
    let state = app.state::<crate::AppState>();
    let policy = state.settings().decision_timeout;
    let mut items = DecisionFiles::in_dir(&state.data_dir).load().ui_items(policy);
    items.sort_by(|a, b| b.ended_at.cmp(&a.ended_at));
    items
}

pub fn emit_pending(app: &AppHandle) {
    let _ = app.emit("pending:changed", pending_items(app));
    emit_status(app);
}

/// What the review card shows: the newest undecided recording of this run.
pub fn review_payload(app: &AppHandle) -> Option<crate::commands::ReviewPayload> {
    let state = app.state::<crate::AppState>();
    let policy = state.settings().decision_timeout;
    let store = DecisionFiles::in_dir(&state.data_dir).load();
    let run = state.review_run.lock().unwrap_or_else(|p| p.into_inner()).clone();
    let waiting: Vec<&String> = run.iter().filter(|id| store.find(id).is_some()).collect();
    let newest = waiting.last()?;
    Some(crate::commands::ReviewPayload { item: store.find(newest)?.item(policy), timeout: policy, queue_length: waiting.len() })
}

/// Shows the card for the newest undecided recording of this run, or hides it when there is none.
pub fn refresh_review(app: &AppHandle) {
    match review_payload(app) {
        Some(payload) => {
            let _ = app.emit_to(crate::overlay::REVIEW, "review:show", payload);
        }
        None => {
            let _ = app.emit_to(crate::overlay::REVIEW, "review:hide", ());
        }
    }
}

/// Applies the current "If I don't choose within 60 s" policy to every recording whose time is up.
pub fn apply_due_timeouts(app: &AppHandle) {
    let state = app.state::<crate::AppState>();
    let policy = state.settings().decision_timeout;
    let done = {
        let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
        DecisionFiles::in_dir(&state.data_dir).apply_timeouts(Utc::now(), policy)
    };
    match done {
        Ok(done) if !done.is_empty() => {
            {
                let mut run = state.review_run.lock().unwrap_or_else(|p| p.into_inner());
                run.retain(|r| !done.iter().any(|(id, _)| id == r));
            }
            state.upload_notify.notify_one();
            emit_pending(app);
            emit_uploads(app);
            refresh_review(app);
        }
        Ok(_) => {}
        Err(e) => eprintln!("[echoledger] decision timeout: {e}"),
    }
}

/// Wakes up when a recording's time is up and applies the policy in force then.
fn schedule_timeout(app: &AppHandle, created_at: chrono::DateTime<Utc>) {
    let due = created_at + chrono::Duration::seconds(pending::DECISION_WINDOW_SECS);
    let wait = (due - Utc::now()).to_std().unwrap_or_default() + Duration::from_millis(200);
    let app = app.clone();
    tauri::async_runtime::spawn(async move {
        tokio::time::sleep(wait).await;
        apply_due_timeouts(&app);
    });
}

/// At start: overdue decisions get the current policy; the others get timers. The card is not shown for them.
pub fn restore_pending(app: &AppHandle) {
    apply_due_timeouts(app);
    let state = app.state::<crate::AppState>();
    for decision in DecisionFiles::in_dir(&state.data_dir).load().items {
        schedule_timeout(app, decision.created_at);
    }
    emit_pending(app);
}

pub fn pending_rename(app: &AppHandle, id: &str, title: &str) -> AppResult<pending::PendingItem> {
    let state = app.state::<crate::AppState>();
    let updated = {
        let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
        DecisionFiles::in_dir(&state.data_dir).rename(id, title)?
    };
    emit_pending(app);
    refresh_review(app);
    Ok(updated.item(state.settings().decision_timeout))
}

pub async fn pending_assign_client(app: &AppHandle, id: &str, client_id: Option<String>) -> AppResult<pending::PendingItem> {
    let state = app.state::<crate::AppState>();
    if DecisionFiles::in_dir(&state.data_dir).load().find(id).is_none() {
        return Err(AppError::msg(pending::NOT_WAITING));
    }
    let client_name = match (&client_id, state.api()) {
        (Some(wanted), Ok(api)) => api.clients().await.ok().and_then(|list| {
            list["data"].as_array()?.iter().find(|c| c["id"].as_str() == Some(wanted.as_str()))?["name"].as_str().map(str::to_string)
        }),
        _ => None,
    };
    let updated = {
        let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
        DecisionFiles::in_dir(&state.data_dir).assign_client(id, client_id, client_name)?
    };
    emit_pending(app);
    refresh_review(app);
    Ok(updated.item(state.settings().decision_timeout))
}

/// Shows the main window on a view (tray "Review recordings", the card's Open / Rename).
pub fn navigate(app: &AppHandle, view: &str, pending_id: Option<String>, rename: bool) {
    let payload = crate::commands::NavigatePayload { view: view.to_string(), pending_id, rename };
    crate::tray::show_main(app);
    let _ = app.emit_to("main", "main:navigate", payload);
}

pub fn pending_open(app: &AppHandle, id: &str, rename: bool) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    if DecisionFiles::in_dir(&state.data_dir).load().find(id).is_none() {
        return Err(AppError::msg(pending::NOT_WAITING));
    }
    state.review_run.lock().unwrap_or_else(|p| p.into_inner()).retain(|r| r != id);
    let _ = app.emit_to(crate::overlay::REVIEW, "review:hide", ());
    navigate(app, "library", Some(id.to_string()), rename);
    Ok(())
}

/// Pause: the capture stops (the OS microphone indicator turns off) and caption sockets close, so nothing is
/// captured, streamed or billed while paused (A3.3, A5.3).
pub async fn pause_recording(app: &AppHandle) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    if !state.capture.is_recording() {
        return Err(AppError::msg("Nothing is recording."));
    }
    if state.capture.is_paused() {
        return Ok(());
    }
    let captions = state.live.is_live();
    if captions {
        stop_captions(app);
    }
    state.capture.pause()?;
    state.captions_on_resume.store(captions, Ordering::SeqCst);
    emit_status(app);
    emit_recording_state(app);
    Ok(())
}

pub async fn resume_recording(app: &AppHandle) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    if !state.capture.is_paused() {
        return Err(AppError::msg("Nothing is paused."));
    }
    state.capture.resume()?;
    if state.captions_on_resume.swap(false, Ordering::SeqCst)
        && let Err(e) = start_captions(app).await
    {
        let _ = app.emit("app:error", e.to_string());
    }
    emit_status(app);
    emit_recording_state(app);
    Ok(())
}

pub async fn toggle_pause(app: &AppHandle) -> AppResult<()> {
    if app.state::<crate::AppState>().capture.is_paused() { resume_recording(app).await } else { pause_recording(app).await }
}

/// Hide / show the recording pill; the recording continues and the tray shows its state.
pub fn set_pill_hidden(app: &AppHandle, hidden: bool) {
    let state = app.state::<crate::AppState>();
    state.pill_hidden.store(hidden, Ordering::SeqCst);
    let visible = !hidden && state.capture.is_recording();
    let _ = app.emit_to(crate::overlay::PILL, "pill:visibility", visible);
    emit_status(app);
    emit_recording_state(app);
}

pub fn emit_recording_state(app: &AppHandle) {
    let _ = app.emit("recording:state", crate::commands::recording_state_now(app));
}

/// One task sends `recording:state` every second while a recording runs and stops when it ends.
fn start_ticker(app: &AppHandle) {
    let state = app.state::<crate::AppState>();
    if state.ticker_running.swap(true, Ordering::SeqCst) {
        return;
    }
    let app = app.clone();
    tauri::async_runtime::spawn(async move {
        loop {
            tokio::time::sleep(Duration::from_secs(1)).await;
            let state = app.state::<crate::AppState>();
            if !state.capture.is_recording() {
                state.ticker_running.store(false, Ordering::SeqCst);
                emit_recording_state(&app);
                break;
            }
            emit_recording_state(&app);
        }
    });
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
pub(crate) fn forget_client(app: &AppHandle, client_id: &str) {
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
    let pending_changed = {
        let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
        DecisionFiles::in_dir(&state.data_dir).forget_client(client_id).unwrap_or(false)
    };
    if pending_changed {
        emit_pending(app);
        refresh_review(app);
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
