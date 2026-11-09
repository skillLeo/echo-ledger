//! High-level actions shared by shortcuts, the tray, auto mode and UI commands.

use std::sync::atomic::Ordering;
use std::time::{Duration, Instant};

use chrono::Utc;

use tauri::{AppHandle, Emitter, Manager};
use tauri_plugin_clipboard_manager::ClipboardExt;

use crate::after_call::{AfterCallState, SavedToast};
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
use crate::settings::AfterCall;
use crate::shortcuts::Action;
use crate::skipped::{SkipReason, SkippedCall, SkippedFiles, SkippedItem};
use crate::stt::captions::{CaptionEvent, CaptionHub};

const CAPTION_TARGETS: &[&str] = &[OVERLAY, "main"];

pub async fn on_shortcut(app: AppHandle, action: Action, pressed: bool) {
    let result = match (action, pressed) {
        (Action::ToggleCaptions, true) => toggle_captions(&app).await,
        (Action::ToggleSaving, true) => toggle_recording(&app).await,
        (Action::RecordNow, true) => record_now(&app).await,
        (Action::CopyLast2Min, true) => copy_last_minutes(&app, 2).map(|_| ()),
        (Action::VoiceNote, true) => toggle_voice_note(&app).await,
        (Action::Mark, true) => mark_moment(&app, None, None).map(|_| ()),
        (Action::TranslateLock, true) => {
            crate::translate::toggle_lock(&app);
            Ok(())
        }
        (Action::TranslateHide, true) => {
            crate::translate::toggle_hidden(&app);
            Ok(())
        }
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
        let recording = app.state::<crate::AppState>().capture.is_recording();
        crate::refusal::say(&app, &e.to_string(), crate::refusal::asked_by_shortcut(action, pressed, recording));
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
    let language = state.recording_language(&info.client_ref);
    let senders = match state.live.start(app, "live_captions", &channels, CAPTION_TARGETS, true, base_offset_ms, session_id, language).await {
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
        language: state.recording_language(&info.client_ref),
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
    if let Some(refusal) = state.viewer_refusal() {
        return Err(AppError::Message(refusal));
    }
    if state.updates.is_installing() {
        return Err(AppError::msg(crate::updates::INSTALLING));
    }
    let missing = checked_permissions(&state, RecordingMode::Call)?;
    let client_id = state.settings().client_id.clone();
    clear_captions(app); // a new recording never inherits the previous one's lines
    let info = state.capture.start(&paths::recordings(&state.data_dir), RecordingMode::Call, source_app, client_id)?;
    *state.recording_missing.lock().unwrap_or_else(|p| p.into_inner()) = missing;
    *state.recording_started_at.lock().unwrap() = Some(Instant::now());
    state.auto_started.store(auto, Ordering::SeqCst);
    on_recording_started(app, &info);
    if captions && let Err(e) = start_captions(app).await {
        let _ = app.emit("app:error", e.to_string());
    }
    Ok(())
}

/// What the system lets a recording of `mode` use (Phase 1B): what will be missing from it, or the refusal when it would
/// hold nothing. It never starts silently empty.
fn checked_permissions(state: &crate::AppState, mode: RecordingMode) -> AppResult<Vec<crate::permissions::Missing>> {
    match crate::permissions::check_start(mode, &state.permissions_now()) {
        crate::permissions::StartCheck::Go(missing) => Ok(missing),
        crate::permissions::StartCheck::Refuse(sentence) => Err(AppError::Message(sentence)),
    }
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
    if let Some(refusal) = state.viewer_refusal() {
        return Err(AppError::Message(refusal));
    }
    if state.updates.is_installing() {
        return Err(AppError::msg(crate::updates::INSTALLING));
    }
    let missing = checked_permissions(&state, mode)?;
    clear_captions(app);
    let info = state.capture.start(&paths::recordings(&state.data_dir), mode, "voice_note", state.settings().client_id.clone())?;
    *state.recording_missing.lock().unwrap_or_else(|p| p.into_inner()) = missing;
    *state.recording_started_at.lock().unwrap() = Some(Instant::now());
    state.auto_started.store(false, Ordering::SeqCst);
    on_recording_started(app, &info);
    Ok(())
}

/// Record now (Phase 1C, A4), from the tray, the shortcut and the Record page: stops a running recording; else a meeting
/// or call on screen is recorded with call capture (both sides), anything else is a voice note into the microphone. It
/// counts as started by hand: it is never skipped as a short automatic call, and pressing again stops it.
pub async fn record_now(app: &AppHandle) -> AppResult<()> {
    use crate::call_guard::RecordNow;
    let state = app.state::<crate::AppState>();
    let meeting = if state.capture.is_recording() { None } else { state.detect.meeting().map(|m| m.app) };
    match crate::call_guard::record_now(state.capture.is_recording(), meeting) {
        RecordNow::Stop => stop_recording(app, false).await,
        RecordNow::Call(source) => {
            start_call_recording(app, source.as_str(), false).await?;
            toast_to(app, "Recording the call, both sides", Target::Page("record"));
            Ok(())
        }
        RecordNow::VoiceNote => {
            toggle_mic_note(app).await?;
            toast_to(app, "Recording a voice note", Target::Page("record"));
            Ok(())
        }
    }
}

/// The watchdog's start (Phase 1C, A5): a detected meeting that was not recording after 5 s.
pub async fn start_backup(app: &AppHandle, source: crate::detect::classify::SourceApp) {
    use crate::call_guard::{BACKUP_BODY, BACKUP_TITLE};
    let state = app.state::<crate::AppState>();
    if state.capture.is_recording() {
        return;
    }
    match start_call_recording(app, source.as_str(), true).await {
        Ok(()) => {
            toast_to(app, BACKUP_TITLE, Target::Page("record"));
            crate::notifications::send(app, crate::notifications::Category::CallCapture, BACKUP_TITLE, BACKUP_BODY);
        }
        Err(e) => crate::refusal::say(app, &e.to_string(), crate::refusal::asked_by_meeting(&e)),
    }
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
    *state.device_status.lock().unwrap_or_else(|p| p.into_inner()) = Default::default();
    crate::overlay::set_pill_visible(app, true);
    start_ticker(app);
    emit_status(app);
    emit_recording_state(app);
    check_bluetooth_mic(app, info);
    crate::screens::on_recording_started(app, info);
}

/// Whether the recording's microphone is Bluetooth (v1.5), asked of the system beside the start so the start does not
/// wait for it; the pill shows "Accuracy is lower with Bluetooth mics" when it is.
fn check_bluetooth_mic(app: &AppHandle, info: &crate::audio::RecordingInfo) {
    let state = app.state::<crate::AppState>();
    state.bluetooth_mic.store(false, Ordering::SeqCst);
    if info.mode == RecordingMode::VoiceNote {
        return; // this computer's sound only
    }
    let (app, client_ref) = (app.clone(), info.client_ref.clone());
    std::thread::spawn(move || {
        let devices = crate::audio::platform::input_devices();
        let preferred = crate::audio::platform::preferred_mic();
        if !crate::audio::platform::mic_in_use(&devices, preferred.as_deref()).is_some_and(|d| d.bluetooth) {
            return;
        }
        let state = app.state::<crate::AppState>();
        // Still the same recording (a very short one may have ended meanwhile).
        if state.capture.info().is_some_and(|i| i.client_ref == client_ref) {
            state.bluetooth_mic.store(true, Ordering::SeqCst);
            emit_recording_state(&app);
        }
    });
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
    // Screens first, while the recording's clock still runs (a screen being written keeps its moment).
    crate::screens::on_recording_ended(app).await;
    let sides = state.capture.sides_heard();
    let finished = state.capture.stop()?;
    if let (Some(heard), Some(done)) = (sides, finished.as_ref()) {
        check_sides(app, done.output.duration_ms, heard);
    }
    state.bluetooth_mic.store(false, Ordering::SeqCst);
    state.recording_missing.lock().unwrap_or_else(|p| p.into_inner()).clear();
    *state.device_status.lock().unwrap_or_else(|p| p.into_inner()) = Default::default();
    set_overlay_visible(app, OVERLAY, false);
    crate::overlay::set_pill_visible(app, false);
    *state.recording_started_at.lock().unwrap() = None;
    Ok(finished.map(|f| (f, session, provider)))
}

/// After each call (Phase 1C, A5): a side that was never heard is said, with why and how to fix it, as Call capture &
/// readiness, and on the page (`capture:one_sided`).
fn check_sides(app: &AppHandle, duration_ms: u64, heard: (bool, bool)) {
    let state = app.state::<crate::AppState>();
    let whole_system = crate::audio::platform::system_audio_note().is_some();
    let Some(said) = crate::call_guard::one_sided(duration_ms, heard, &state.permissions_now(), whole_system) else { return };
    let _ = app.emit("capture:one_sided", &said);
    crate::notifications::send(app, crate::notifications::Category::CallCapture, said.title, &format!("{} {}", said.reason, said.fix));
}

/// Stops the recording; "After a call" decides what comes next (the Saved toast, or the chip). Auto mode never stops a
/// manual recording.
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

/// Quit while recording: the recording is stopped and kept, or (Ask me) waits for the next start; never discarded,
/// nothing is shown.
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
        crate::recovery::delete_recording_folder(&finished.output.dir);
        crate::screens::forget(app, &finished.info.client_ref);
        for id in session.into_iter().chain(late) {
            queue_discard(app, id)?;
        }
    }
    clear_captions(app);
    emit_status(app);
    emit_recording_state(app);
    Ok(())
}

/// What a finished recording does next (v1.5 "After a call"): kept at once with the "Saved · Undo" toast, or asked
/// about with the chip, which keeps it by itself after 10 s. `interactive` is false when the app quits in the middle
/// of a call: nothing is shown, and a recording that would be asked about waits in the Library for the next start.
fn decide_finished(app: &AppHandle, finished: FinishedRecording, session: Option<String>, provider: Option<String>, auto: bool, interactive: bool) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    let client_ref = finished.info.client_ref.clone();
    let late = state.session_link.lock().unwrap_or_else(|p| p.into_inner()).end_stop(&client_ref);
    // A container with (almost) no audio frames means nothing was heard: tell the user instead of keeping a
    // recording the server would reject (the server enforces the same floor).
    let silent = std::fs::metadata(&finished.output.primary).map(|m| m.len() < MIN_AUDIO_BYTES).unwrap_or(true);
    if silent {
        crate::recovery::delete_recording_folder(&finished.output.dir);
        crate::screens::forget(app, &client_ref);
        for id in session.into_iter().chain(late) {
            queue_discard(app, id)?;
        }
        crate::refusal::say(app, "No sound was captured, so nothing was saved. Check your microphone and the audio device, then try again.", crate::refusal::Asked::Other);
        return Ok(());
    }

    let settings = state.settings();
    let live_segments = state.captions.lock().unwrap().segments();
    let mode = finished.info.mode;
    let now = Utc::now();
    let mut decision = PendingDecision::from_recording(
        &finished,
        DecisionInput {
            auto,
            client_name: None,
            live_segments,
            live_provider: provider,
            server_session_id: session,
            created_at: now,
            language: state.recording_language(&client_ref),
            language_marks: take_language_marks(&state, &client_ref),
            caller: take_caller(&state, &client_ref),
        },
    );
    // Another account signed in while it ran: it stays the account's that made it.
    decision.owner = take_recording_owner(&state, &client_ref);
    let output = &finished.output;
    if let Some(reason) = crate::skipped::reason(auto, mode, output.duration_ms, settings.skip_calls_shorter_than_s, output.speech_ms) {
        return skip_finished(app, decision, late, reason, now);
    }
    let ask = pending::after_call(settings.after_call, mode) == AfterCall::Ask;
    let decision = if ask && interactive { decision.keeping_at(now + chrono::Duration::seconds(pending::CHIP_SECONDS)) } else { decision };
    let id = decision.id.clone();
    let keep_at = decision.keep_at();
    let toast = (!ask && interactive).then(|| SavedToast::of(&decision, now));
    {
        let files = DecisionFiles::in_dir(&state.data_dir);
        let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
        files.add(decision)?;
        if let Some(late) = late {
            files.set_server_session(&id, &late)?;
        }
    }
    if !ask {
        keep_decision(app, &id)?;
        if let Some(toast) = toast {
            show_saved(app, toast);
        }
        return Ok(());
    }
    if let Some(at) = keep_at {
        surfaces(&state).show_chip(id);
        schedule_keep(app, at);
    }
    emit_pending(app);
    refresh_after_call(app);
    Ok(())
}

fn surfaces(state: &crate::AppState) -> std::sync::MutexGuard<'_, crate::after_call::Surfaces> {
    state.after_call.lock().unwrap_or_else(|p| p.into_inner())
}

/// A short or silent automatic call (v1.5): nothing is shown, kept or uploaded. It waits in `skipped.json` for 24 hours
/// so Activity can restore it; a server session captions opened for it is deleted at once (a restored call opens a
/// new one when it uploads).
fn skip_finished(app: &AppHandle, mut decision: PendingDecision, late: Option<String>, reason: SkipReason, now: chrono::DateTime<Utc>) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    for session in decision.take_server_session().into_iter().chain(late) {
        queue_discard(app, session)?;
    }
    let call = SkippedCall { decision, reason, skipped_at: now };
    let expires = call.expires_at();
    {
        let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
        SkippedFiles::in_dir(&state.data_dir).add(call)?;
    }
    schedule_purge(app, expires);
    emit_skipped(app);
    Ok(())
}

/// The skipped calls Activity lists (still restorable, newest first); the ones whose 24 hours are over go first.
pub fn skipped_items(app: &AppHandle) -> Vec<SkippedItem> {
    purge_skipped(app);
    let state = app.state::<crate::AppState>();
    SkippedFiles::in_dir(&state.data_dir).load().ui_items(Utc::now())
}

/// `skipped:changed` with the whole list.
pub fn emit_skipped(app: &AppHandle) {
    let state = app.state::<crate::AppState>();
    let items = SkippedFiles::in_dir(&state.data_dir).load().ui_items(Utc::now());
    let _ = app.emit("skipped:changed", items);
}

/// Restore on Activity's skipped line: the call is kept like any other (upload, then the final transcript).
pub fn restore_skipped(app: &AppHandle, id: &str) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    {
        let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
        SkippedFiles::in_dir(&state.data_dir).restore(id, Utc::now())?;
    }
    state.upload_notify.notify_one();
    emit_skipped(app);
    emit_uploads(app);
    emit_status(app);
    Ok(())
}

/// Deletes the skipped calls whose 24 hours are over.
pub fn purge_skipped(app: &AppHandle) {
    let state = app.state::<crate::AppState>();
    let done = {
        let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
        SkippedFiles::in_dir(&state.data_dir).purge(Utc::now())
    };
    match done {
        Ok(ids) if !ids.is_empty() => {
            for id in &ids {
                crate::screens::forget(app, id);
            }
            emit_skipped(app);
        }
        Ok(_) => {}
        Err(e) => eprintln!("[echoledger] removing skipped calls: {e}"),
    }
}

/// Wakes up when a skipped call's 24 hours are over (the list is also cleaned whenever it is read and at start).
fn schedule_purge(app: &AppHandle, at: chrono::DateTime<Utc>) {
    let wait = (at - Utc::now()).to_std().unwrap_or_default() + Duration::from_secs(1);
    let app = app.clone();
    tauri::async_runtime::spawn(async move {
        tokio::time::sleep(wait).await;
        purge_skipped(&app);
    });
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
        let decision = DecisionFiles::in_dir(&state.data_dir).discard(id)?;
        if let (Some(owner), Some(session)) = (held_owner(&state, decision.owner.as_ref()), decision.server_session_id()) {
            crate::owner::hold_discard(&state.data_dir, owner, session)?;
        }
    }
    crate::screens::forget(app, id);
    state.upload_notify.notify_one(); // the worker deletes a queued server session
    after_decision(app, id);
    Ok(())
}

/// The account a recording waits for, when it was made while another account was signed in here (v1.5): a deletion of
/// its server session waits for that account too.
fn held_owner<'a>(state: &crate::AppState, owner: Option<&'a crate::owner::Owner>) -> Option<&'a crate::owner::Owner> {
    owner.filter(|o| !crate::owner::may_upload(Some(o), state.owner().as_ref()))
}

fn after_decision(app: &AppHandle, id: &str) {
    surfaces(&app.state::<crate::AppState>()).clear_chip(id);
    emit_pending(app);
    refresh_after_call(app);
    emit_status(app);
}

pub fn pending_items(app: &AppHandle) -> Vec<pending::PendingItem> {
    let state = app.state::<crate::AppState>();
    let mut items = DecisionFiles::in_dir(&state.data_dir).load().ui_items();
    items.sort_by(|a, b| b.ended_at.cmp(&a.ended_at));
    items
}

pub fn emit_pending(app: &AppHandle) {
    let _ = app.emit("pending:changed", pending_items(app));
    emit_status(app);
}

/// What the after-call window shows now: the Saved toast while it lasts, and the chip while its recording counts down.
pub fn after_call_state(app: &AppHandle) -> AfterCallState {
    let state = app.state::<crate::AppState>();
    let (saved, chip) = {
        let surfaces = surfaces(&state);
        (surfaces.saved(Instant::now()).cloned(), surfaces.chip().map(str::to_string))
    };
    let ask = chip.and_then(|id| DecisionFiles::in_dir(&state.data_dir).load().find(&id).filter(|d| !d.waits()).map(PendingDecision::item));
    AfterCallState { saved, ask }
}

/// Puts the after-call window in line with `after_call_state` (built when something is to be shown, let go when not).
pub fn refresh_after_call(app: &AppHandle) {
    crate::overlay::show_after_call(app, &after_call_state(app));
}

/// "Saved · … · Undo" for 6 s; the window is told again once the time is over.
fn show_saved(app: &AppHandle, toast: SavedToast) {
    surfaces(&app.state::<crate::AppState>()).show_saved(toast, Instant::now());
    refresh_after_call(app);
    let app = app.clone();
    tauri::async_runtime::spawn(async move {
        tokio::time::sleep(Duration::from_secs(crate::after_call::SAVED_SECONDS) + Duration::from_millis(100)).await;
        refresh_after_call(&app);
    });
}

/// Undo on the Saved toast: as if the recording had been discarded. Still on this computer (queued or uploading), its
/// files are deleted and nothing more is sent; already uploaded, it is deleted on the server like any delete, so it
/// stays in Recently deleted for 48 hours. An upload that ends after this is deleted by the worker.
pub async fn undo_saved(app: &AppHandle, id: &str) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    let uploaded = {
        let mut surfaces = surfaces(&state);
        let session = surfaces.uploaded_session(id, Instant::now());
        surfaces.undo(id);
        session
    };
    refresh_after_call(app);
    crate::screens::forget(app, id);
    if discard_upload(app, id).is_ok() {
        return Ok(());
    }
    if let Some(session) = uploaded {
        let deleted = match state.api() {
            Ok(api) => api.delete_session(&session).await,
            Err(e) => Err(e),
        };
        match deleted {
            Ok(()) => {}
            Err(e) if e.status() == Some(404) => {}
            // Offline or refused for now: the worker deletes it once it can.
            Err(_) => queue_discard(app, session)?,
        }
        let _ = crate::commands::refresh_library_inner(app).await;
    }
    Ok(())
}

/// Keeps every recording whose chip time is over ("Ask me"), also one whose time ran out while the app was closed,
/// and sets the timer for the next chip still counting. That chip may have no timer of its own: a restart sets one
/// timer for all of them, and a timer that wakes early (the clock was set back) keeps nothing. Without this its
/// recording would count for ever, hidden from the Library and every count until the next start.
pub fn keep_due(app: &AppHandle) {
    let state = app.state::<crate::AppState>();
    let now = Utc::now();
    let (done, next) = {
        let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
        let files = DecisionFiles::in_dir(&state.data_dir);
        let done = files.keep_due(now);
        (done, files.load().next_wake(now))
    };
    if let Some(at) = next {
        schedule_keep(app, at);
    }
    match done {
        Ok(done) if !done.is_empty() => {
            {
                let mut surfaces = surfaces(&state);
                for id in &done {
                    surfaces.clear_chip(id);
                }
            }
            state.upload_notify.notify_one();
            emit_pending(app);
            emit_uploads(app);
            refresh_after_call(app);
        }
        Ok(_) => {}
        Err(e) => eprintln!("[echoledger] keeping after the chip: {e}"),
    }
}

/// Wakes up when a chip's time is over and keeps what is due then.
fn schedule_keep(app: &AppHandle, at: chrono::DateTime<Utc>) {
    let wait = (at - Utc::now()).to_std().unwrap_or_default() + Duration::from_millis(200);
    let app = app.clone();
    tauri::async_runtime::spawn(async move {
        tokio::time::sleep(wait).await;
        keep_due(&app);
    });
}

/// At start: a recording whose chip time ran out while the app was closed is kept, one whose chip still counts (a
/// restart within 10 s) gets its timer (`keep_due`). Recordings that wait for the person stay as they are, whatever
/// the setting. Skipped calls past their 24 hours are deleted; the others get their timers.
pub fn restore_pending(app: &AppHandle) {
    keep_due(app);
    let state = app.state::<crate::AppState>();
    purge_skipped(app);
    for call in SkippedFiles::in_dir(&state.data_dir).load().items {
        schedule_purge(app, call.expires_at());
    }
    emit_pending(app);
}

/// Recordings recovered at start (since v1.3) wait in the Library. One stopped in the middle of the recording follows
/// "After a call" (`cut_short`): under "Keep automatically" it is kept at once. One that was already finished waits
/// whatever the setting, as it may have been discarded, undone or uploaded before (`recovery::Recovery`).
pub fn after_recovery(app: &AppHandle, cut_short: &[String]) {
    if app.state::<crate::AppState>().settings().after_call == AfterCall::Keep {
        for id in cut_short {
            if let Err(e) = keep_decision(app, id) {
                eprintln!("[echoledger] keeping the recovered recording {id}: {e}");
            }
        }
    }
    emit_pending(app);
}

/// Tray "Review recordings (N)": the Library with the waiting list open at the newest waiting recording.
pub fn review_waiting(app: &AppHandle) {
    let first = pending_items(app).into_iter().find(|i| i.deadline_at.is_none()).map(|i| i.id);
    navigate(app, "library", first, false);
}

pub fn pending_rename(app: &AppHandle, id: &str, title: &str) -> AppResult<pending::PendingItem> {
    let state = app.state::<crate::AppState>();
    let updated = {
        let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
        DecisionFiles::in_dir(&state.data_dir).rename(id, title)?
    };
    emit_pending(app);
    refresh_after_call(app);
    Ok(updated.item())
}

/// The account a finished recording was made under, when another account signed in while it ran (cleared with it).
fn take_recording_owner(state: &crate::AppState, client_ref: &str) -> Option<crate::owner::Owner> {
    let mut owner = state.recording_owner.lock().unwrap_or_else(|p| p.into_inner());
    match owner.take() {
        Some((r, o)) if r == client_ref => Some(o),
        other => {
            *owner = other;
            None
        }
    }
}

/// Who a finished recording was with (cleared with it).
fn take_caller(state: &crate::AppState, client_ref: &str) -> Option<crate::net::models::CallerDto> {
    let mut caller = state.recording_caller.lock().unwrap_or_else(|p| p.into_inner());
    match caller.take() {
        Some((r, c)) if r == client_ref => Some(c),
        other => {
            *caller = other;
            None
        }
    }
}

/// Caller names (since v1.2): the running recording is with [name] (from the meeting app's window title). The first
/// name seen during a recording is kept; later window titles (another chat opened mid-call) do not replace it.
pub fn set_recording_caller(app: &AppHandle, name: String) {
    let state = app.state::<crate::AppState>();
    if let Some(info) = state.capture.info() {
        let mut caller = state.recording_caller.lock().unwrap_or_else(|p| p.into_inner());
        if caller.as_ref().is_some_and(|(r, _)| *r == info.client_ref) {
            return;
        }
        *caller = Some((info.client_ref, crate::net::models::CallerDto { name: Some(name), number: None, source: Some("window_title".into()) }));
    }
}

/// The chip changes of a finished recording (cleared, so the next recording starts without marks).
fn take_language_marks(state: &crate::AppState, client_ref: &str) -> Vec<crate::net::models::LanguageMarkDto> {
    let mut marks = state.recording_language_marks.lock().unwrap_or_else(|p| p.into_inner());
    match marks.take() {
        Some((r, list)) if r == client_ref => list,
        other => {
            *marks = other;
            Vec::new()
        }
    }
}

pub fn pending_set_language(app: &AppHandle, id: &str, language: Option<String>) -> AppResult<pending::PendingItem> {
    let state = app.state::<crate::AppState>();
    let language = crate::settings::language_or_none(language.as_deref());
    let updated = {
        let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
        DecisionFiles::in_dir(&state.data_dir).set_language(id, language)?
    };
    emit_pending(app);
    refresh_after_call(app);
    Ok(updated.item())
}

/// The pill's language chip. With live captions on, the caption stream reconnects in the new language; the
/// recording itself never stops (captions are a side stream of the capture).
pub async fn set_recording_language(app: &AppHandle, language: Option<String>) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    let Some(info) = state.capture.info() else {
        return Err(AppError::msg("No recording is running."));
    };
    let language = crate::settings::language_or_none(language.as_deref());
    // A timestamped mark: the server cuts the audio here and transcribes each piece in its language. The first change
    // also records the language the recording started with.
    let before = state.recording_language(&info.client_ref);
    if before != language {
        let at_ms = state.capture.elapsed_ms();
        let mut marks = state.recording_language_marks.lock().unwrap_or_else(|p| p.into_inner());
        let list = match marks.as_mut() {
            Some((client_ref, list)) if *client_ref == info.client_ref => list,
            _ => {
                *marks = Some((info.client_ref.clone(), vec![crate::net::models::LanguageMarkDto { at_ms: 0, language: before.clone() }]));
                &mut marks.as_mut().expect("just set").1
            }
        };
        list.retain(|m| m.at_ms < at_ms || m.at_ms == 0);
        list.push(crate::net::models::LanguageMarkDto { at_ms, language: language.clone() });
    }
    *state.recording_language.lock().unwrap_or_else(|p| p.into_inner()) = Some((info.client_ref.clone(), language));
    if state.live.is_live() {
        start_captions(app).await?;
    }
    emit_recording_state(app);
    Ok(())
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
    refresh_after_call(app);
    Ok(updated.item())
}

/// Shows the main window on a view (tray "Review recordings", the chip's title).
pub fn navigate(app: &AppHandle, view: &str, pending_id: Option<String>, rename: bool) {
    let payload = crate::commands::NavigatePayload { view: view.to_string(), pending_id, rename, recording_id: None, link: None };
    crate::tray::show_main(app);
    crate::lifecycle::emit(app, crate::lifecycle::MAIN, "main:navigate", payload);
}

/// Opens a waiting recording in the main window (the chip's title: with its title field open). Its chip stops
/// counting, so it is never kept while the person is still naming it: it waits for Keep or Discard there.
pub fn pending_open(app: &AppHandle, id: &str, rename: bool) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    {
        let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
        DecisionFiles::in_dir(&state.data_dir).stop_countdown(id)?;
    }
    surfaces(&state).clear_chip(id);
    emit_pending(app);
    refresh_after_call(app);
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
    // Fresh devices: a channel that was lost before the pause is told about again only if it still is.
    *state.device_status.lock().unwrap_or_else(|p| p.into_inner()) = Default::default();
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
    crate::overlay::set_pill_visible(app, visible);
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
        let mut shown_note = None;
        let mut ticks = 0_u32;
        loop {
            tokio::time::sleep(Duration::from_secs(1)).await;
            let state = app.state::<crate::AppState>();
            if !state.capture.is_recording() {
                state.ticker_running.store(false, Ordering::SeqCst);
                emit_recording_state(&app);
                break;
            }
            ticks = ticks.wrapping_add(1);
            if ticks.is_multiple_of(READ_PERMISSIONS_EVERY) {
                read_recording_permissions(&app);
            }
            take_device_notices(&app);
            // The Record page hears of a device note when it comes and when it goes (news lasts a few seconds).
            let note = state.device_status.lock().unwrap_or_else(|p| p.into_inner()).note(Instant::now());
            if note != shown_note {
                shown_note = note;
                emit_status(&app);
            }
            emit_recording_state(&app);
        }
    });
}

/// Seconds between looks at what a running recording may use.
const READ_PERMISSIONS_EVERY: u32 = 3;

/// What the running recording is missing, read again (Phase 1B): the prompt that settles "not asked yet" comes after the
/// start, and a permission can be turned off during a recording. The pill and the captions hear it with the next
/// `recording:state`, and the Record page reads the permissions when that changes. Asked beside the ticker, so it never
/// waits for the system.
fn read_recording_permissions(app: &AppHandle) {
    let app = app.clone();
    tauri::async_runtime::spawn_blocking(move || {
        let state = app.state::<crate::AppState>();
        let Some(info) = state.capture.info() else { return };
        let next = crate::permissions::missing_during(info.mode, &state.permissions_now());
        // The same recording: a very short one may have ended while the system was asked.
        if state.capture.info().is_some_and(|i| i.client_ref == info.client_ref) {
            let mut missing = state.recording_missing.lock().unwrap_or_else(|p| p.into_inner());
            if *missing != next {
                eprintln!("[echoledger] capture: what this recording may use changed; {} kind(s) missing now", next.len());
                *missing = next;
            }
        }
    });
}

/// What the capture says about its devices (a headset came or went, Phase 1B) goes to the pill and the Record page; a
/// new microphone is looked at again for Bluetooth.
fn take_device_notices(app: &AppHandle) {
    use crate::audio::devices::DeviceNotice;
    let state = app.state::<crate::AppState>();
    let notices = state.capture.take_device_notices();
    if notices.is_empty() {
        return;
    }
    let now = Instant::now();
    {
        let mut status = state.device_status.lock().unwrap_or_else(|p| p.into_inner());
        for notice in &notices {
            status.apply(notice, now);
        }
    }
    let new_mic = notices.iter().any(|n| matches!(n, DeviceNotice::Moved { channel: crate::audio::types::Channel::Mic, .. } | DeviceNotice::Back { channel: crate::audio::types::Channel::Mic, .. }));
    if new_mic && let Some(info) = state.capture.info() {
        check_bluetooth_mic(app, &info);
    }
}

/// Appends an item to `uploads.json` and wakes the upload worker. An import of a file that is already queued (same
/// SHA-256, checked with the lock held) is not added: false.
pub fn queue_upload(app: &AppHandle, item: PendingUpload) -> AppResult<bool> {
    let state = app.state::<crate::AppState>();
    let path = paths::uploads(&state.data_dir);
    {
        let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
        let mut queue = UploadQueue::load(&path);
        if item.sha256.as_deref().is_some_and(|sha| queue.holds_file(sha)) {
            return Ok(false);
        }
        queue.items.push(item);
        queue.save(&path)?;
    }
    state.upload_notify.notify_one();
    emit_uploads(app);
    Ok(true)
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
    let items = UploadQueue::load(&paths::uploads(&state.data_dir)).ui_items(state.owner().as_ref());
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
        match held_owner(&state, item.owner.as_ref()) {
            Some(owner) => {
                let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
                crate::owner::hold_discard(&state.data_dir, owner, &session_id)?;
            }
            None => queue_discard(app, session_id)?,
        }
    }
    delete_local_audio(&item);
    crate::screens::forget(app, &item.id());
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
        refresh_after_call(app);
    }
    toast_to(app, "The project chosen for keyterms was removed on the server, so no project is set now.", Target::Account("general"));
}

/// Imports files (the Import button or a drop on the main window), one recording each, with one client and language
/// for all of them. A file whose SHA-256 is already in the upload queue is skipped; the server skips files it holds.
pub fn import_files(app: &AppHandle, sources: &[std::path::PathBuf], client_id: Option<String>, language: Option<String>) -> crate::files::ImportSummary {
    let mut summary = crate::files::ImportSummary::default();
    for source in sources {
        if !crate::files::is_importable(source) || !source.is_file() {
            summary.unsupported += 1;
            continue;
        }
        match import_voice_note(app, source, client_id.clone(), language.clone()) {
            Ok(true) => summary.queued += 1,
            Ok(false) => summary.duplicates += 1,
            Err(e) => {
                eprintln!("[echoledger] import of {} failed: {e}", source.display());
                summary.failed += 1;
            }
        }
    }
    summary
}

/// Copies one file into the queue as a voice note titled after its file name. False when the same file is already
/// queued (nothing is copied then).
pub fn import_voice_note(app: &AppHandle, source: &std::path::Path, client_id: Option<String>, language: Option<String>) -> AppResult<bool> {
    let state = app.state::<crate::AppState>();
    let queue_path = paths::uploads(&state.data_dir);
    let (sha256, _) = crate::net::uploader::file_sha256(source)?;
    let queued = {
        let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
        UploadQueue::load(&queue_path).holds_file(&sha256)
    };
    if queued {
        return Ok(false);
    }
    let client_ref = uuid::Uuid::new_v4().to_string();
    let dir = paths::recordings(&state.data_dir).join(&client_ref);
    std::fs::create_dir_all(&dir)?;
    let name = source.file_name().ok_or_else(|| AppError::msg("not a file"))?;
    let primary = dir.join(name);
    // The copy runs outside the queue lock (a long video must not hold up the uploads).
    if let Err(e) = std::fs::copy(source, &primary) {
        let _ = std::fs::remove_dir_all(&dir);
        return Err(e.into());
    }
    let now = chrono::Utc::now().to_rfc3339();
    // Duration 0 marks an import: the uploader declares only what the file's header says (A6.DR5).
    let item = PendingUpload {
        client_ref,
        source_app: "voice_note".into(),
        client_id,
        started_at: now.clone(),
        ended_at: now,
        duration_ms: 0,
        dir: dir.clone(),
        primary,
        primary_channels: 1,
        title: crate::files::title_from_file_name(source),
        language,
        imported: true,
        sha256: Some(sha256),
        ..PendingUpload::default()
    };
    // Checked again on the way in: the same file may have been queued while this copy ran.
    let stored = queue_upload(app, item);
    if !matches!(stored, Ok(true)) {
        let _ = std::fs::remove_dir_all(&dir);
    }
    stored
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

/// A token the server no longer takes: the device was removed in the console, or the account signed out everywhere or
/// asked to be deleted (since v1.5).
const UNPAIRED: &str = "This computer was signed out on the server. Sign in again to upload.";

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
            // While the server holds the queue (maintenance, a refused version) nothing is asked until the hold is over.
            if state.is_paired() && !state.uploads_held() {
                run_upload_cycle(&app).await;
            }
        }
    });
}

async fn run_upload_cycle(app: &AppHandle) {
    let state = app.state::<crate::AppState>();
    let data_dir = state.data_dir.clone();
    let (usage, discards, uploads, waiting) = {
        let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
        let uploads = UploadQueue::load(&paths::uploads(&data_dir));
        // Every recording still deciding, queued or skipped keeps its screens.
        let waiting: std::collections::HashSet<String> = uploads
            .items
            .iter()
            .map(PendingUpload::id)
            .chain(DecisionFiles::in_dir(&data_dir).load().items.into_iter().map(|d| d.id))
            .chain(SkippedFiles::in_dir(&data_dir).load().items.into_iter().map(|c| c.decision.id))
            .collect();
        (UsageQueue::load(&paths::usage(&data_dir)).items, DiscardQueue::load(&paths::discards(&data_dir)).items, uploads, waiting)
    };
    crate::screens::sweep(app, waiting);
    // Failed items wait for Retry; v1 servers get no usage reports (kept until the server is upgraded). A recording made
    // while another account was signed in here waits for that account (v1.5).
    let mut current = state.owner();
    let mut pending_uploads = uploads.sendable(current.as_ref());
    // The account signed in here is not known yet (an install from before v1.5, or one the sign-in could not read):
    // asked for before anything that may be another account's waits for good.
    let unknown = current.as_ref().is_none_or(|c| c.email.is_none()) && !state.owner_checked.load(Ordering::SeqCst);
    let others_waiting = uploads.held(current.as_ref()) > 0;
    let known_v1 = state.server_config.lock().unwrap_or_else(|p| p.into_inner()).as_ref().is_some_and(|c| !c.is_v1_1());
    let usage_to_send = !usage.is_empty() && !known_v1;
    if pending_uploads.is_empty() && discards.is_empty() && !usage_to_send && !crate::screens::due(app) && !(unknown && others_waiting) {
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
            state.note_hold(&e);
            eprintln!("[echoledger] upload cycle skipped: {e}");
            return;
        }
    };
    // In maintenance or refused (v1.6, P2.7): everything waits here, quietly; the banner says why.
    if state.uploads_held() {
        emit_status(app);
        emit_uploads(app);
        return;
    }
    if unknown {
        current = crate::owner::learn(&state, &api).await;
        pending_uploads = uploads.sendable(current.as_ref());
        emit_uploads(app);
    }

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
        if drain.stopped.as_ref().is_some_and(|e| state.note_hold(e)) {
            emit_status(app);
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
        if drain.stopped.as_ref().is_some_and(|e| state.note_hold(e)) {
            emit_status(app);
            return;
        }
    }

    let mut uploaded = 0usize;
    let mut duplicates = 0usize;
    for id in pending_uploads {
        // Work on the current copy: the item may have been discarded or changed since the snapshot.
        let Some(mut item) = current_item(app, &id) else { continue };
        if item.is_failed() {
            continue;
        }
        let was_verifying = item.state == states::VERIFYING;
        if !was_verifying {
            item.state = states::UPLOADING.into();
            item.progress = None;
            store_item(app, &item);
            emit_uploads(app);
        }
        let client_before = item.client_id.clone();
        // Every stored step also reaches the Library, so an upload's progress shows as it goes.
        let persist = |p: &PendingUpload| {
            store_item(app, p);
            emit_uploads(app);
        };
        let result = crate::net::uploader::upload_with(&api, &config, &mut item, UploadTiming::default(), &persist).await;
        if let (Some(old), None) = (client_before.as_deref(), item.client_id.as_deref()) {
            forget_client(app, old);
        }
        match after_attempt(&mut item, was_verifying, &result) {
            // Every artifact is ready (the server holds the encrypted copy now), or the session was deleted there.
            AfterAttempt::Remove => finish_item(app, &item),
            AfterAttempt::Keep => store_item(app, &item),
        }
        // Screen moments go once the recording is on the server; one deleted there (or a file it already had) has none.
        match &result {
            Ok(UploadOutcome::Ready { session_id } | UploadOutcome::Verifying { session_id }) => crate::screens::link(app, &id, session_id),
            Ok(UploadOutcome::Deleted { .. } | UploadOutcome::Duplicate) => crate::screens::forget(app, &id),
            _ => {}
        }
        emit_uploads(app);
        // Undo on the Saved toast while this attempt ran: what reached the server is deleted again (v1.5). Checked
        // under the same lock Undo takes, so a recording is either found uploaded by Undo or deleted here.
        let undone = {
            let mut surfaces = surfaces(&state);
            let undone = surfaces.take_undone(&id);
            if !undone && let Ok(UploadOutcome::Ready { session_id }) = &result {
                surfaces.uploaded(&id, session_id, Instant::now());
            }
            undone
        };
        if undone {
            let reached = result.as_ref().ok().map(|o| o.session_id().to_string()).filter(|s| !s.is_empty()).or_else(|| item.server_session());
            if let Some(session) = reached {
                let _ = queue_discard(app, session);
            }
            crate::screens::forget(app, &id);
            continue;
        }
        match result {
            Ok(UploadOutcome::Ready { .. }) => uploaded += 1,
            Ok(UploadOutcome::Deleted { .. }) => {
                let _ = app.emit("app:toast", "This recording was deleted on the server, so its upload was stopped.");
            }
            // The Library already holds this file: its copy here is gone (finish_item) and nothing is retried.
            Ok(UploadOutcome::Duplicate) => duplicates += 1,
            Err(AppError::NotPaired) => {
                let _ = app.emit("app:error", UNPAIRED);
                break;
            }
            // Maintenance began, or this version is refused: the rest of the queue waits too (no toast, no failure).
            Err(e) if state.note_hold(&e) => {
                emit_status(app);
                break;
            }
            _ => {}
        }
    }
    // Screen moments of the recordings that reached the server (this cycle or before).
    let screens_sent = crate::screens::send(app, &api).await;
    // One notice for the cycle (a second toast would replace the first).
    let mut notices = Vec::new();
    if uploaded > 0 || screens_sent > 0 {
        let _ = crate::commands::refresh_library_inner(app).await;
    }
    if uploaded > 0 {
        notices.push(format!("Uploaded {uploaded} recording(s) for transcription"));
    }
    if duplicates > 0 {
        notices.push(crate::files::duplicates_notice(duplicates));
    }
    if !notices.is_empty() {
        // Uploaded recordings are in the Library: the toast goes there (v1.6.1).
        toast_to(app, &notices.join(". "), Target::Page("library"));
    }
    emit_status(app);
}

/// How long after an OS notification bringing the app forward opens its recording.
const NOTIFIED_WINDOW: Duration = Duration::from_secs(600);

/// Another start of the app (a second launch, an `echoledger://open` link, or a click on one of our notifications):
/// show the window and open the link it was started with or, when a notification about a recording went out in the
/// last ten minutes, that recording.
pub fn show_main_from_outside(app: &AppHandle, args: &[String]) {
    if handle_links(app, args) {
        return;
    }
    crate::tray::show_main(app);
    let target = app.state::<crate::AppState>().notified.lock().unwrap_or_else(|p| p.into_inner()).take();
    if let Some((id, at)) = target
        && at.elapsed() < NOTIFIED_WINDOW
    {
        let payload = crate::commands::NavigatePayload { view: "library".into(), pending_id: None, rename: false, recording_id: Some(id), link: None };
        crate::lifecycle::emit(app, crate::lifecycle::MAIN, "main:navigate", payload);
    }
}

/// An `echoledger://signed-in` link (the browser sign-in coming back) or an `echoledger://open` link among `args`
/// (v1.5); false when there is neither.
pub fn handle_links(app: &AppHandle, args: &[String]) -> bool {
    if let Some(link) = crate::account::find_signed_in_link(args) {
        crate::tray::show_main(app);
        let app = app.clone();
        tauri::async_runtime::spawn(async move { crate::commands::finish_browser_sign_in(&app, link).await });
        return true;
    }
    if let Some(link) = crate::deep_link::find_open_link(args) {
        open_app_link(app, link);
        return true;
    }
    false
}

/// Shows the main window with a share, project or request link to open in place (v1.5). The page resolves it once it
/// is unlocked, and opens the web page instead when it is not this workspace's.
pub fn open_app_link(app: &AppHandle, link: String) {
    crate::tray::show_main(app);
    let payload = crate::commands::NavigatePayload { view: "library".into(), pending_id: None, rename: false, recording_id: None, link: Some(link) };
    crate::lifecycle::emit(app, crate::lifecycle::MAIN, "main:navigate", payload);
}

/// Where a toast from the core goes when it is clicked (v1.6.1, docs/DESIGN_SYSTEM.md "Notices"); the window's
/// `NoticeTarget` (lib/notice.ts) reads it.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Target {
    /// A page of the main window (`record` is Home, `library`, `activity`…).
    Page(&'static str),
    /// A tab or a section of Account (`general`, `devices`, `plan`…).
    Account(&'static str),
}

impl Target {
    pub fn json(self) -> serde_json::Value {
        match self {
            Self::Page(page) => serde_json::json!({"kind": "page", "page": page}),
            Self::Account(section) => serde_json::json!({"kind": "account", "section": section}),
        }
    }
}

/// The payload of a toast that goes somewhere: `{"text", "target"}` (a plain string is a toast about nothing).
pub fn toast_payload(text: &str, target: Target) -> serde_json::Value {
    serde_json::json!({"text": text, "target": target.json()})
}

/// A toast in the main window that goes to `target` when clicked.
pub fn toast_to(app: &AppHandle, text: &str, target: Target) {
    let _ = app.emit("app:toast", toast_payload(text, target));
}

/// Polls the activity centre every minute and shows new notifications (B18), outside the quiet hours.
pub fn spawn_activity_poll(app: AppHandle) {
    tauri::async_runtime::spawn(async move {
        loop {
            tokio::time::sleep(Duration::from_secs(60)).await;
            refresh_config(&app).await;
            let _ = poll_activity(&app).await;
        }
    });
}

/// Reads `GET /api/config` (never refused, v1.6 P2.7) so the window's update, announcement and maintenance banners
/// and the upload hold follow the server within a minute, whether or not anything waits to upload.
async fn refresh_config(app: &AppHandle) {
    let state = app.state::<crate::AppState>();
    if !state.is_paired() {
        return;
    }
    let Ok(api) = state.api() else { return };
    let was_held = state.uploads_held();
    match api.config().await {
        Ok(config) => {
            state.apply_config(config);
            // Back from maintenance: what waited goes now rather than at the next 30-second turn.
            if was_held && !state.uploads_held() {
                state.upload_notify.notify_one();
            }
            emit_status(app);
        }
        Err(e) => {
            state.note_hold(&e);
        }
    }
}

async fn poll_activity(app: &AppHandle) -> AppResult<()> {
    use crate::notifications::{Category, allowed, local_minutes};
    let state = app.state::<crate::AppState>();
    let answer = state.api()?.activity(20).await?;
    let items: Vec<crate::activity::ActivityItem> = serde_json::from_value(answer.get("data").cloned().unwrap_or_default()).unwrap_or_default();
    let path = paths::activity_seen(&state.data_dir);
    let mut seen = crate::activity::Seen::load(&path);
    let fresh = seen.fresh(&items);
    let _ = seen.save(&path);
    let _ = app.emit("activity:updated", answer.get("unread_count").and_then(serde_json::Value::as_u64).unwrap_or(0));
    let settings = state.settings();
    let minutes = local_minutes();
    // Each item goes through its own category (Phase 1C): its switch, its sound and the quiet hours; a recording the
    // person asked about ("Notify me when ready") is said whatever they say.
    let shown: Vec<_> = {
        let mut asked = state.notify_ready.lock().unwrap_or_else(|p| p.into_inner());
        fresh.into_iter().filter(|i| crate::activity::asked_for(&mut asked, i) || allowed(&settings, Category::of_activity(i.kind.as_deref()), minutes)).collect()
    };
    if shown.is_empty() {
        return Ok(());
    }
    if let Some(id) = shown.iter().rev().find_map(|i| i.session_id.clone()) {
        *state.notified.lock().unwrap_or_else(|p| p.into_inner()) = Some((id, Instant::now()));
    }
    let category = crate::activity::category_of(&shown);
    for (title, body) in crate::activity::notification_text(&shown, state.lock.is_locked()) {
        crate::notifications::show(app, &settings, category, &title, &body);
    }
    Ok(())
}

#[cfg(test)]
mod notice_tests {
    use super::{Target, toast_payload};

    #[test]
    fn a_toast_that_goes_somewhere_carries_its_target_for_the_window() {
        assert_eq!(
            toast_payload("Uploaded 2 recording(s) for transcription", Target::Page("library")),
            serde_json::json!({"text": "Uploaded 2 recording(s) for transcription", "target": {"kind": "page", "page": "library"}})
        );
        assert_eq!(Target::Account("general").json(), serde_json::json!({"kind": "account", "section": "general"}));
    }
}
