//! High-level actions shared by shortcuts, the tray, auto mode and UI commands.

use std::sync::atomic::Ordering;
use std::time::{Duration, Instant};

use chrono::Utc;

use tauri::{AppHandle, Emitter, Manager};
use tauri_plugin_clipboard_manager::ClipboardExt;

use crate::after_call::{AfterCallState, Before, CallChoice, CallDone, CallGroup, Outcome, OutcomeInput};
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
use crate::skipped::{SkipReason, SkippedCall, SkippedFiles, SkippedItem};
use crate::stt::captions::{CaptionEvent, CaptionHub};

const CAPTION_TARGETS: &[&str] = &[OVERLAY, "main"];

/// "Capture screen now" (the shortcut and the pill): the running recording keeps the screen at its next sample;
/// `screens:captured` follows once it is kept (the flash). Refused in plain words when nothing can be captured.
pub fn capture_screen_now(app: &AppHandle) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    if !state.capture.is_recording() {
        return Err(AppError::msg(CAPTURE_NEEDS_RECORDING));
    }
    if !state.screens.capture_now() {
        return Err(AppError::msg(CAPTURE_SCREENS_OFF));
    }
    Ok(())
}

/// Capture screen now with no recording running.
pub const CAPTURE_NEEDS_RECORDING: &str = "Start a recording to capture its screen.";
/// Capture screen now while screen moments are off for this recording (Settings, the plan or a voice note).
pub const CAPTURE_SCREENS_OFF: &str = "Screen moments are off for this recording.";

pub async fn on_shortcut(app: AppHandle, action: Action, pressed: bool) {
    let result = match (action, pressed) {
        (Action::ToggleCaptions, true) => toggle_captions(&app).await,
        (Action::ToggleSaving, true) => toggle_recording(&app).await,
        (Action::RecordNow, true) => record_now(&app).await,
        (Action::CopyLast2Min, true) => copy_last_minutes(&app, 2).map(|_| ()),
        (Action::VoiceNote, true) => toggle_voice_note(&app).await,
        (Action::Mark, true) => mark_moment(&app, None, None).map(|_| ()),
        (Action::HideAll, true) => {
            crate::privacy::toggle_hide_all(&app);
            Ok(())
        }
        (Action::CaptureScreen, true) => capture_screen_now(&app),
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
    // A config read that changed the interface flags is announced with the status (contract 2.0.0-a).
    crate::ui_flags::announce(app);
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
pub(crate) async fn ensure_server_session(app: &AppHandle, info: &crate::audio::RecordingInfo) -> AppResult<String> {
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
    *state.system_watch.lock().unwrap_or_else(|p| p.into_inner()) = Default::default();
    *state.recording_started_at.lock().unwrap() = Some(Instant::now());
    state.auto_started.store(auto, Ordering::SeqCst);
    // "When to ask: At the start of the call": "Recording this call · Don't record" beside the pill for 5 s.
    let settings = state.settings();
    let banner = crate::after_call::start_banner(settings.after_each_call(), settings.ask_when, auto, RecordingMode::Call);
    *state.start_banner.lock().unwrap_or_else(|p| p.into_inner()) = banner.then(|| (info.client_ref.clone(), Instant::now()));
    // The pill opens with the banner under it: its window is built with the room for it.
    crate::overlay::preset_pill_open(banner);
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

/// A recording with nothing in it: nothing reached the app's input.
pub(crate) const NO_SOUND: &str = "No sound was captured, so nothing was saved. Check your microphone and the audio device, then try again.";
/// A recording of exact zeros on both sides (D-168): the input runs but the system hands over silence, as macOS does
/// for an app it doesn't let use the microphone and the computer's sound (a build not signed as the one allowed).
pub(crate) const WITHHELD: &str = "Your computer gave EchoLedger only silence, so nothing was saved. Allow EchoLedger in System Settings › Privacy & Security › Microphone and System Audio Recording, then try again.";

/// What the "nothing was saved" notice says (pure).
pub(crate) fn silent_recording_message(withheld: bool) -> &'static str {
    if withheld { WITHHELD } else { NO_SOUND }
}

/// Whether both sides ran for over a second and every sample was exactly zero (D-168). A microphone that is merely
/// quiet or muted still has its noise floor a few steps above zero; a moment too short to tell, or a side that wrote
/// nothing, is not counted.
pub(crate) fn input_withheld(mic_wav: &std::path::Path, system_wav: &std::path::Path) -> bool {
    let silent_for_a_second = |path: &std::path::Path| -> bool {
        let Ok(mut reader) = hound::WavReader::open(path) else { return false };
        if reader.duration() < 16_000 {
            return false;
        }
        reader.samples::<i16>().all(|s| matches!(s, Ok(0)))
    };
    silent_for_a_second(mic_wav) && silent_for_a_second(system_wav)
}

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
    // A prep armed for this meeting starts its Live Assist session, or the card asks (`assist::live::decide`).
    crate::assist::live::on_recording_started(app, info);
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
    *state.system_watch.lock().unwrap_or_else(|p| p.into_inner()) = Default::default();
    if let Some(done) = finished.as_ref() {
        keep_capture_notes(&state, done);
    }
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

/// What went wrong with a finished recording's capture: in its folder (`capture-notes.txt`, beside its audio) and for
/// Copy diagnostics. Nothing is written when nothing went wrong.
fn keep_capture_notes(state: &crate::AppState, done: &crate::audio::FinishedRecording) {
    *state.last_capture_notes.lock().unwrap_or_else(|p| p.into_inner()) = done.capture_notes.clone();
    if !done.capture_notes.is_empty() {
        let _ = std::fs::write(done.output.dir.join("capture-notes.txt"), done.capture_notes.join("\n") + "\n");
    }
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
        decide_finished(app, finished, session, provider, auto, true, None)?;
    }
    clear_captions(app); // after the decision (it reads the buffered turns), so the next session starts empty
    emit_status(app);
    emit_recording_state(app);
    Ok(())
}

/// Before every quit (the menu bar icon's and the app menu's Quit, ⌘Q; on a Mac also the Dock's Quit, logging out,
/// restarting and shutting down, `app_life`): a running recording is stopped and kept first, never lost. One keeping for
/// every quit: a quit that comes while it runs waits for it (`app_life::QuitSave`).
pub async fn save_before_quit(app: &AppHandle) {
    crate::app_life::quit_save()
        .run(|| async {
            if app.state::<crate::AppState>().capture.is_recording() {
                let _ = stop_recording_for_quit(app).await;
            }
        })
        .await;
}

/// Quit while recording: the recording is stopped and kept, or (Ask me) waits for the next start; never discarded,
/// nothing is shown.
pub async fn stop_recording_for_quit(app: &AppHandle) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    let auto = state.auto_started.load(Ordering::SeqCst);
    if let Some((finished, session, provider)) = end_capture(app).await? {
        decide_finished(app, finished, session, provider, auto, false, None)?;
    }
    Ok(())
}

/// "Stop and delete" on the pill's menu, and "Don't record" on the start banner: the recording ends and goes as the
/// card's Delete does, "Deleted · Undo" for 5 s and its files only once that time is over; Undo brings its card back.
pub async fn stop_and_delete(app: &AppHandle) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    if !state.capture.is_recording() {
        return Err(AppError::msg("Nothing is recording."));
    }
    let auto = state.auto_started.load(Ordering::SeqCst);
    if let Some((finished, session, provider)) = end_capture(app).await? {
        decide_finished(app, finished, session, provider, auto, true, Some(CallChoice::Delete))?;
    }
    clear_captions(app);
    emit_status(app);
    emit_recording_state(app);
    Ok(())
}

/// The pill's menu during a call or meeting: "Transcribe this call" or "Save audio only" is what happens when it ends,
/// with no card then (chosen again, the last one counts); "Stop and delete" ends it now.
pub async fn call_choice_now(app: &AppHandle, choice: CallChoice) -> AppResult<()> {
    if choice == CallChoice::Delete {
        return stop_and_delete(app).await;
    }
    let state = app.state::<crate::AppState>();
    let Some(info) = state.capture.info() else { return Err(AppError::msg("Nothing is recording.")) };
    *state.call_choice.lock().unwrap_or_else(|p| p.into_inner()) = Some((info.client_ref.clone(), choice));
    *state.start_banner.lock().unwrap_or_else(|p| p.into_inner()) = None;
    emit_recording_state(app);
    Ok(())
}

/// The choice made on the pill for the running recording `client_ref`.
pub fn call_choice_of(state: &crate::AppState, client_ref: &str) -> Option<CallChoice> {
    state.call_choice.lock().unwrap_or_else(|p| p.into_inner()).as_ref().filter(|(r, _)| r == client_ref).map(|(_, c)| *c)
}

/// Whether the start banner shows for the running recording `client_ref` now: its first 5 s, no choice made yet.
pub fn start_banner_shows(state: &crate::AppState, client_ref: &str) -> bool {
    let banner = state.start_banner.lock().unwrap_or_else(|p| p.into_inner());
    banner.as_ref().is_some_and(|(r, at)| r == client_ref && at.elapsed() < Duration::from_secs(crate::after_call::START_BANNER_SECONDS))
        && call_choice_of(state, client_ref).is_none()
}

/// Takes the pill's choice for `client_ref` (and its start banner) when the recording ends.
fn take_call_choice(state: &crate::AppState, client_ref: &str) -> Option<CallChoice> {
    let mut banner = state.start_banner.lock().unwrap_or_else(|p| p.into_inner());
    if banner.as_ref().is_some_and(|(r, _)| r == client_ref) {
        *banner = None;
    }
    drop(banner);
    let mut choice = state.call_choice.lock().unwrap_or_else(|p| p.into_inner());
    match choice.take() {
        Some((r, c)) if r == client_ref => Some(c),
        other => {
            *choice = other;
            None
        }
    }
}

/// Stop and discard at once (the pill's Cancel): nothing is kept, uploaded or transcribed; no card.
/// The pill's Discard (also a Live Assist start the person declined): the recording ends and nothing of it is kept or
/// uploaded; its open server session is queued for deletion.
/// `expected`: the recording meant (a declined Live Assist start names the one it started); when another runs by now,
/// nothing is discarded.
pub async fn cancel_recording(app: &AppHandle, expected: Option<&str>) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    if !state.capture.is_recording() {
        return Err(AppError::msg("Nothing is recording."));
    }
    let running = state.capture.info().map(|i| i.client_ref);
    if !discards(expected, running.as_deref()) {
        return Ok(());
    }
    if let Some((finished, session, _)) = end_capture(app).await? {
        let late = state.session_link.lock().unwrap_or_else(|p| p.into_inner()).end_stop(&finished.info.client_ref);
        pending::discard_recording_files(&state.data_dir, &finished.output.dir, &finished.info.client_ref);
        for id in session.into_iter().chain(late) {
            queue_discard(app, id)?;
        }
    }
    clear_captions(app);
    emit_status(app);
    emit_recording_state(app);
    Ok(())
}

/// Whether a discard meant for `expected` (any recording when `None`) applies to the recording `running` now (pure).
pub fn discards(expected: Option<&str>, running: Option<&str>) -> bool {
    match (expected, running) {
        (_, None) => false,
        (None, Some(_)) => true,
        (Some(expected), Some(running)) => expected == running,
    }
}

/// What a finished recording does next (the call choice, `after_call::outcome`): the card, a confirmation with Undo, or
/// kept at once. `interactive` is false when the app quits in the middle of a call: nothing is shown, and a recording
/// that would be asked about waits in the Library for the next start. `forced` is the pill's Stop and delete.
fn decide_finished(app: &AppHandle, finished: FinishedRecording, session: Option<String>, provider: Option<String>, auto: bool, interactive: bool, forced: Option<CallChoice>) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    let client_ref = finished.info.client_ref.clone();
    let late = state.session_link.lock().unwrap_or_else(|p| p.into_inner()).end_stop(&client_ref);
    // Chosen on the pill during the call (taken whatever comes next, so it never reaches another recording).
    let pill = forced.or_else(|| take_call_choice(&state, &client_ref));
    // A container with (almost) no audio frames means nothing was heard: tell the user instead of keeping a
    // recording the server would reject (the server enforces the same floor).
    let silent = std::fs::metadata(&finished.output.primary).map(|m| m.len() < MIN_AUDIO_BYTES).unwrap_or(true);
    if silent {
        // Read before the folder goes: exact zeros mean the system withheld the input (D-168), not a quiet microphone.
        let withheld = input_withheld(&finished.output.mic_wav, &finished.output.system_wav);
        crate::recovery::delete_recording_folder(&finished.output.dir);
        crate::screens::forget(app, &client_ref);
        for id in session.into_iter().chain(late) {
            queue_discard(app, id)?;
        }
        // Deleted on purpose: nothing to say about what it held.
        if pill != Some(CallChoice::Delete) {
            crate::refusal::say(app, silent_recording_message(withheld), crate::refusal::Asked::Other);
        }
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
    // Short or silent automatic calls go quietly, unless the person chose for this one.
    if pill.is_none()
        && let Some(reason) = crate::skipped::reason(auto, mode, output.duration_ms, settings.skip_calls_shorter_than_s, output.speech_ms)
    {
        return skip_finished(app, decision, late, reason, now);
    }
    // A Live Assist session from a prep saves its call without the after-call card (docs/API_ASSIST.md `auto_saved`).
    let assist_saved = take_assist_saved(&state, &client_ref);
    let quiet = crate::activity::in_quiet_hours(settings.quiet_hours, &settings.quiet_from, &settings.quiet_to, crate::notifications::local_minutes());
    let outcome = crate::after_call::outcome(OutcomeInput { setting: settings.after_call, ask_when: settings.ask_when, mode, pill, assist_saved, interactive, quiet });
    let id = decision.id.clone();
    // The card, or (back to back: something else holds the window) one grouped card with this call saved as audio.
    let before = (outcome == Outcome::Ask && interactive).then(|| surfaces(&state).arrive(&id, !settings.card_hint_seen, Instant::now()));
    let card = before == Some(Before::Nothing);
    let decision = match outcome {
        Outcome::Ask if card => decision.keeping_at(now + chrono::Duration::seconds(pending::CHIP_SECONDS)),
        Outcome::Ask if before.is_some() => decision.chosen_at(CallChoice::Audio, applied_at(now, CallChoice::Audio)),
        Outcome::Confirm(choice) => decision.chosen_at(choice, applied_at(now, choice)),
        _ => decision,
    };
    let keep_at = decision.keep_at();
    let done = match outcome {
        Outcome::Confirm(choice) => Some(CallDone::of(&decision, choice, now)),
        Outcome::Ask if !card => Some(CallDone::of(&decision, CallChoice::Audio, now)),
        _ => None,
    };
    {
        let files = DecisionFiles::in_dir(&state.data_dir);
        let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
        files.add(decision)?;
        if let Some(late) = late {
            files.set_server_session(&id, &late)?;
        }
    }
    match outcome {
        Outcome::KeepNow { transcribe } => return keep_decision_as(app, &id, transcribe),
        Outcome::Confirm(choice) => {
            let at = applied_at(now, choice);
            if choice == CallChoice::Delete { schedule_discard(app, at) } else { schedule_keep(app, at) }
            if interactive && let Some(done) = done {
                // Never hidden: with something else in the window (another call's card above all), both are grouped.
                show_or_group(app, done);
            }
        }
        Outcome::Ask if card => {
            // The first card says where to change it, once.
            if !settings.card_hint_seen {
                let _ = state.update_settings(|s| s.card_hint_seen = true);
            }
            if let Some(at) = keep_at {
                schedule_keep(app, at);
            }
        }
        Outcome::Ask => {
            if let (Some(before), Some(done)) = (before, done) {
                fold_into_group(app, before, done);
            }
        }
        Outcome::Wait => {}
    }
    emit_pending(app);
    refresh_after_call(app);
    Ok(())
}

/// Back to back: `newest` needs the window while `before` holds it. Both go into the one grouped card, each call with its
/// Undo (a counting card's call saved as audio), and every choice in it is applied when the grouped card goes, so no
/// Undo shown there is ever too late.
fn fold_into_group(app: &AppHandle, before: Before, newest: CallDone) {
    let state = app.state::<crate::AppState>();
    let now = Utc::now();
    let store = DecisionFiles::in_dir(&state.data_dir).load();
    let calls = crate::after_call::grouped(before, |id| store.find(id).map(|d| CallDone::of(d, CallChoice::Audio, now)), newest);
    show_group(app, calls);
}

/// The grouped card with `calls` for 8 s: each call's choice is applied when it goes, a Delete's files a little later.
fn show_group(app: &AppHandle, calls: Vec<CallDone>) {
    let state = app.state::<crate::AppState>();
    let at = Utc::now() + chrono::Duration::seconds(crate::after_call::GROUP_SECONDS as i64);
    let calls = set_apply_time(&state, calls, at);
    if calls.is_empty() {
        return;
    }
    surfaces(&state).group_with(calls, Instant::now());
    schedule_keep(app, at);
    schedule_discard(app, at + discard_slack());
    refresh_after(app, Duration::from_secs(crate::after_call::GROUP_SECONDS));
}

fn discard_slack() -> chrono::Duration {
    chrono::Duration::from_std(crate::after_call::DISCARD_SLACK).unwrap_or_default()
}

/// Sets when each listed call's choice is applied (`at`; a Delete's files a little later) and returns the calls still
/// waiting, each saying so in its `until`.
fn set_apply_time(state: &crate::AppState, mut calls: Vec<CallDone>, at: chrono::DateTime<Utc>) -> Vec<CallDone> {
    let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
    let files = DecisionFiles::in_dir(&state.data_dir);
    for call in &mut calls {
        call.until = at.to_rfc3339();
        call.offer_always = None;
        let applied = if call.kind == CallChoice::Delete { files.discard_later(&call.id, at + discard_slack()) } else { files.choose(&call.id, call.kind, at) };
        if let Err(e) = applied {
            eprintln!("[echoledger] the grouped card's call {}: {e}", call.id);
        }
    }
    let store = files.load();
    calls.retain(|c| store.find(&c.id).is_some());
    calls
}

/// A card went (answered, timed out, opened or kept elsewhere): the rows held under it come back as the grouped card,
/// with `newest` (the card's own answer) when there is one; else `newest` alone shows as usual.
fn after_card(app: &AppHandle, held: Vec<CallDone>, newest: Option<CallDone>) {
    match (held.is_empty(), newest) {
        (true, Some(done)) => show_or_group(app, done),
        (true, None) => {}
        (false, newest) => show_group(app, held.into_iter().chain(newest).collect()),
    }
}

/// Applies a listed call's choice now (the grouped card's ×, Review's non-deletes).
fn apply_now(app: &AppHandle, call: &CallDone) {
    let done = if call.kind == CallChoice::Delete { discard_decision(app, &call.id) } else { keep_decision_as(app, &call.id, call.kind == CallChoice::Transcribe) };
    if let Err(e) = done {
        eprintln!("[echoledger] applying {}: {e}", call.id);
    }
}

/// When a choice made at `now` is applied: once its confirmation's Undo time is over (a deletion's files a little
/// later still, so an Undo pressed at the last moment finds them).
fn applied_at(now: chrono::DateTime<Utc>, choice: CallChoice) -> chrono::DateTime<Utc> {
    let mut wait = Duration::from_secs(crate::after_call::CONFIRM_SECONDS);
    if choice == CallChoice::Delete {
        wait += crate::after_call::DISCARD_SLACK;
    }
    now + chrono::Duration::from_std(wait).unwrap_or_else(|_| chrono::Duration::seconds(7))
}

/// Tells the after-call window again once `after` is over (a confirmation or the grouped card has gone).
fn refresh_after(app: &AppHandle, after: Duration) {
    let app = app.clone();
    tauri::async_runtime::spawn(async move {
        tokio::time::sleep(after + Duration::from_millis(100)).await;
        refresh_after_call(&app);
    });
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
    keep_decision_as(app, id, true)
}

/// "Save audio" (contract 1.8.0-e): kept and uploaded as audio only, not transcribed.
pub fn keep_decision_audio(app: &AppHandle, id: &str) -> AppResult<()> {
    keep_decision_as(app, id, false)
}

fn keep_decision_as(app: &AppHandle, id: &str, transcribe: bool) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    {
        let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
        let files = DecisionFiles::in_dir(&state.data_dir);
        if transcribe { files.keep(id)? } else { files.keep_audio(id)? };
    }
    state.upload_notify.notify_one();
    after_decision(app, id);
    emit_uploads(app);
    Ok(())
}

/// The card's answer: Transcribe, its × / Esc / swipe (Save audio only) or Delete. Chosen now and applied once the
/// confirmation's 5 s are over, so Undo brings the card back with nothing uploaded or deleted. The same choice three
/// times in a row offers "Always do this?" once.
pub fn card_answer(app: &AppHandle, id: &str, choice: CallChoice) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    let now = Utc::now();
    let at = applied_at(now, choice);
    let decision = {
        let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
        let files = DecisionFiles::in_dir(&state.data_dir);
        if choice == CallChoice::Delete { files.discard_later(id, at)? } else { files.choose(id, choice, at)? }
    };
    let mut offer = None;
    if let Err(e) = state.update_settings(|s| {
        let streak = crate::after_call::next_streak(s.card_streak, choice);
        s.card_streak = Some(streak);
        offer = crate::after_call::offers_always(streak, s.always_offered, s.after_each_call());
        s.always_offered |= offer.is_some();
    }) {
        eprintln!("[echoledger] counting the card's answers: {e}");
    }
    let held = surfaces(&state).clear_card(id).unwrap_or_default();
    if choice == CallChoice::Delete { schedule_discard(app, at) } else { schedule_keep(app, at) }
    after_card(app, held, Some(CallDone { offer_always: offer, ..CallDone::of(&decision, choice, now) }));
    emit_pending(app);
    refresh_after_call(app);
    Ok(())
}

/// A confirmation in the window; when something else holds it (another call's card, confirmation or the grouped card),
/// the two are grouped instead, so no confirmation or Undo is ever hidden.
fn show_or_group(app: &AppHandle, done: CallDone) {
    let before = surfaces(&app.state::<crate::AppState>()).show_done(done.clone(), Instant::now());
    if before == Before::Nothing {
        refresh_after(app, Duration::from_secs(crate::after_call::CONFIRM_SECONDS));
    } else {
        fold_into_group(app, before, done);
    }
}

/// Transcribe on "Saved as audio": it is transcribed instead, with "Transcribing… · Undo" for 5 s.
pub fn done_transcribe(app: &AppHandle, id: &str) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    let now = Utc::now();
    let at = applied_at(now, CallChoice::Transcribe);
    let decision = {
        let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
        DecisionFiles::in_dir(&state.data_dir).choose(id, CallChoice::Transcribe, at).map_err(|_| AppError::msg(TRANSCRIBE_TOO_LATE))?
    };
    let done = CallDone::of(&decision, CallChoice::Transcribe, now);
    // On a row (the grouped card, or under a card) it changes in place, applied when that row's time comes.
    let listed = surfaces(&state).listed(id, Instant::now()).map(|c| c.until.clone());
    match listed {
        Some(until) => {
            if let Ok(row_at) = chrono::DateTime::parse_from_rfc3339(&until) {
                let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
                let _ = DecisionFiles::in_dir(&state.data_dir).choose(id, CallChoice::Transcribe, row_at.with_timezone(&Utc));
            }
            surfaces(&state).update_listed(CallDone { until, ..done });
        }
        None => show_or_group(app, done),
    }
    schedule_keep(app, at);
    emit_pending(app);
    refresh_after_call(app);
    refresh_after(app, Duration::from_secs(crate::after_call::CONFIRM_SECONDS));
    Ok(())
}

/// Undo on a confirmation ("Transcribing…", "Saved as audio", "Deleted") or on a row: nothing was applied yet, so the call
/// is back on its card, which counts its 8 s again (then saves the audio only). Whatever else was on screen (the rest of
/// the grouped card) stays under the card with its own Undo, and none of it is applied until the card is answered. An
/// error when its time was already over.
pub fn call_undo(app: &AppHandle, id: &str) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    let now = Utc::now();
    let at = now + chrono::Duration::seconds(pending::CHIP_SECONDS);
    let store = {
        let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
        let files = DecisionFiles::in_dir(&state.data_dir);
        files.ask_again(id, at).map_err(|_| AppError::msg(UNDO_TOO_LATE))?;
        files.load()
    };
    let held = surfaces(&state).ask_again(id, |other| store.find(other).map(|d| CallDone::of(d, CallChoice::Audio, now)), Instant::now());
    // Paused: the rows under the card wait past its 8 s and the grouped card it comes back as.
    let paused = at + chrono::Duration::seconds(crate::after_call::GROUP_SECONDS as i64 + 1);
    let held = set_apply_time(&state, held, paused);
    {
        let mut surfaces = surfaces(&state);
        for row in held {
            surfaces.update_listed(row);
        }
    }
    schedule_keep(app, at);
    emit_pending(app);
    refresh_after_call(app);
    Ok(())
}

/// "Always do this?" › Yes on a confirmation: After each call becomes that choice (Don't record calls for Delete).
pub fn call_always(app: &AppHandle, id: &str, value: crate::settings::AfterEachCall) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    let settings = state.update_settings(|s| s.set_after_each_call(value))?;
    surfaces(&state).drop_offer(id);
    let _ = app.emit("settings:changed", &settings);
    emit_status(app);
    refresh_after_call(app);
    Ok(())
}

/// The grouped card's Review: the Library, where each call can be transcribed or deleted. What is kept is applied now
/// and shows there; a Delete is never applied out of sight, so its row stays in the grouped card with its Undo.
pub fn group_review(app: &AppHandle) {
    let rows = surfaces(&app.state::<crate::AppState>()).take_group(Instant::now());
    let (deletes, kept): (Vec<CallDone>, Vec<CallDone>) = rows.into_iter().partition(|c| c.kind == CallChoice::Delete);
    for call in &kept {
        apply_now(app, call);
    }
    show_group(app, deletes);
    emit_pending(app);
    refresh_after_call(app);
    navigate(app, "library", None, false);
}

/// The grouped card's ×: a deliberate dismiss, so every choice in it is applied now.
pub fn group_close(app: &AppHandle) {
    let rows = surfaces(&app.state::<crate::AppState>()).take_group(Instant::now());
    for call in &rows {
        apply_now(app, call);
    }
    emit_pending(app);
    refresh_after_call(app);
}

/// The card's Delete (kept for older windows): the same as `card_answer` with Delete.
pub fn discard_from_chip(app: &AppHandle, id: &str) -> AppResult<()> {
    card_answer(app, id, CallChoice::Delete)
}

/// The waiting list's Delete and Delete all: the recordings leave the list now and their files go once "Deleted · Undo"
/// has had its time (5 s, and a little more), never at once. Returns how many are on their way out.
pub fn pending_delete(app: &AppHandle, ids: &[String]) -> AppResult<usize> {
    let state = app.state::<crate::AppState>();
    let at = applied_at(Utc::now(), CallChoice::Delete);
    let mut done = 0;
    let mut last = None;
    {
        let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
        let files = DecisionFiles::in_dir(&state.data_dir);
        for id in ids {
            match files.discard_later(id, at) {
                Ok(_) => done += 1,
                Err(e) => last = Some(e),
            }
        }
    }
    if done == 0 && let Some(e) = last {
        return Err(e);
    }
    schedule_discard(app, at);
    emit_pending(app);
    Ok(done)
}

/// Undo on the waiting list's "Deleted · Undo": every recording still on its way out is back in the list.
pub fn pending_delete_undo(app: &AppHandle, ids: &[String]) -> AppResult<usize> {
    let state = app.state::<crate::AppState>();
    let back = {
        let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
        let files = DecisionFiles::in_dir(&state.data_dir);
        ids.iter().filter(|id| files.restore_waiting(id).is_ok()).count()
    };
    emit_pending(app);
    if back == 0 && !ids.is_empty() {
        return Err(AppError::msg(UNDO_TOO_LATE));
    }
    Ok(back)
}

/// Undo pressed after the choice was already applied.
pub const UNDO_TOO_LATE: &str = "Too late to undo: it was already done.";
/// Transcribe pressed on "Saved as audio" after it was saved.
pub const TRANSCRIBE_TOO_LATE: &str = "This call is already saved as audio. Transcribe it from the Library.";

/// Deletes every recording discarded from the chip whose Undo time is over, and sets the timer for the next one.
pub fn discard_due(app: &AppHandle) {
    let state = app.state::<crate::AppState>();
    let now = Utc::now();
    let (done, next) = {
        let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
        let files = DecisionFiles::in_dir(&state.data_dir);
        // One by one: a recording that could not go stays for the next try, and never stops the others.
        let (done, failed) = files.discard_due(now);
        for (id, e) in &failed {
            eprintln!("[echoledger] discarding {id} after the Undo time: {e} (tried again later)");
        }
        for decision in &done {
            if let (Some(owner), Some(session)) = (held_owner(&state, decision.owner.as_ref()), decision.server_session_id())
                && let Err(e) = crate::owner::hold_discard(&state.data_dir, owner, session)
            {
                eprintln!("[echoledger] holding a discarded recording's server session: {e}");
            }
        }
        (done, files.load().next_discard(now))
    };
    if let Some(at) = next {
        schedule_discard(app, at);
    }
    if !done.is_empty() {
        for decision in &done {
            crate::screens::forget(app, &decision.id);
        }
        state.upload_notify.notify_one(); // the worker deletes a queued server session
        emit_pending(app);
        refresh_after_call(app);
    }
}

/// Wakes up when a discarded recording's Undo time is over and deletes what is due then.
fn schedule_discard(app: &AppHandle, at: chrono::DateTime<Utc>) {
    let wait = (at - Utc::now()).to_std().unwrap_or_default() + Duration::from_millis(200);
    let app = app.clone();
    tauri::async_runtime::spawn(async move {
        tokio::time::sleep(wait).await;
        discard_due(&app);
    });
}

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
    let held = surfaces(&app.state::<crate::AppState>()).clear_card(id).unwrap_or_default();
    after_card(app, held, None);
    emit_pending(app);
    refresh_after_call(app);
    emit_status(app);
}

pub fn pending_items(app: &AppHandle) -> Vec<pending::PendingItem> {
    let state = app.state::<crate::AppState>();
    let mut items = DecisionFiles::in_dir(&state.data_dir).load().ui_items_with_screens(&crate::screens::store::ScreenStore::in_dir(&state.data_dir));
    items.sort_by(|a, b| b.ended_at.cmp(&a.ended_at));
    items
}

pub fn emit_pending(app: &AppHandle) {
    let _ = app.emit("pending:changed", pending_items(app));
    emit_status(app);
}

/// What the after-call window shows now: the card while its call counts, else the one confirmation or the grouped card.
pub fn after_call_state(app: &AppHandle) -> AfterCallState {
    let state = app.state::<crate::AppState>();
    let (card, hint, held, done, group) = {
        let surfaces = surfaces(&state);
        let now = Instant::now();
        (surfaces.card().map(str::to_string), surfaces.hint(), surfaces.held().to_vec(), surfaces.done(now).cloned(), surfaces.group_calls(now).map(|(calls, left)| (calls.to_vec(), left)))
    };
    let screens = crate::screens::store::ScreenStore::in_dir(&state.data_dir);
    let ask = card.and_then(|id| DecisionFiles::in_dir(&state.data_dir).load().find(&id).filter(|d| d.counting() && d.chosen().is_none()).map(|d| d.item_with_screens(&screens)));
    let group = group.map(|(calls, left)| CallGroup { count: calls.len(), until: (Utc::now() + chrono::Duration::from_std(left).unwrap_or_default()).to_rfc3339(), calls });
    let held = if ask.is_some() { held } else { Vec::new() };
    AfterCallState { hint: hint && ask.is_some(), ask, held, done, group }
}

/// Puts the after-call window in line with `after_call_state` (built when something is to be shown, let go when not).
pub fn refresh_after_call(app: &AppHandle) {
    crate::overlay::show_after_call(app, &after_call_state(app));
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
    time_out_card(app, now);
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
            let held: Vec<CallDone> = {
                let mut surfaces = surfaces(&state);
                done.iter().filter_map(|id| surfaces.clear_card(id)).flatten().collect()
            };
            after_card(app, held, None);
            state.upload_notify.notify_one();
            emit_pending(app);
            emit_uploads(app);
            refresh_after_call(app);
        }
        Ok(_) => {}
        Err(e) => eprintln!("[echoledger] keeping after the chip: {e}"),
    }
}

/// The card on screen whose 8 s are over with no choice: "Saved as audio · Transcribe · Undo" for 5 s, saved as audio
/// only once that is over. (A card whose time ran out while the app was closed is saved at the next start, quietly.)
fn time_out_card(app: &AppHandle, now: chrono::DateTime<Utc>) {
    let state = app.state::<crate::AppState>();
    let Some(id) = surfaces(&state).card().map(str::to_string) else { return };
    let at = applied_at(now, CallChoice::Audio);
    let decision = {
        let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
        let files = DecisionFiles::in_dir(&state.data_dir);
        match files.time_out_card(&id, now, at) {
            Ok(true) => files.load().find(&id).cloned(),
            Ok(false) => None,
            Err(e) => {
                eprintln!("[echoledger] saving the card's call as audio: {e}");
                None
            }
        }
    };
    let Some(decision) = decision else { return };
    let held = surfaces(&state).clear_card(&id).unwrap_or_default();
    schedule_keep(app, at);
    after_card(app, held, Some(CallDone::of(&decision, CallChoice::Audio, now)));
    emit_pending(app);
    refresh_after_call(app);
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

/// At start: a recording whose chip time ran out while the app was closed is kept as its chip promised (audio only, or
/// kept and transcribed for a chip shown before v1.8), one whose chip still counts (a restart within 8 s) gets its
/// timer, and a pending Transcribe or Save audio only is applied when due (`keep_due`). A Delete chosen before the quit
/// and not applied is never applied out of sight: it goes back to the Library's waiting list
/// (`restore_unapplied_deletes`). Recordings that wait for the person stay as they are, whatever the setting. Skipped
/// calls past their 24 hours are deleted; the others get their timers.
pub fn restore_pending(app: &AppHandle) {
    keep_due(app);
    let state = app.state::<crate::AppState>();
    let back = {
        let _guard = state.queue_lock.lock().unwrap_or_else(|p| p.into_inner());
        DecisionFiles::in_dir(&state.data_dir).restore_unapplied_deletes()
    };
    match back {
        Ok(ids) if !ids.is_empty() => eprintln!("[echoledger] {} deletion(s) not applied before the quit wait in the Library again", ids.len()),
        Ok(_) => {}
        Err(e) => eprintln!("[echoledger] putting unapplied deletions back in the waiting list: {e}"),
    }
    purge_skipped(app);
    for call in SkippedFiles::in_dir(&state.data_dir).load().items {
        schedule_purge(app, call.expires_at());
    }
    emit_pending(app);
}

/// Recordings recovered at start (since v1.3) wait in the Library. One stopped in the middle of the recording follows
/// "After a call" (`cut_short`): under "Always transcribe" or "Always save audio" it is kept at once, that way. One that
/// was already finished waits whatever the setting, as it may have been discarded, undone or uploaded before
/// (`recovery::Recovery`).
pub fn after_recovery(app: &AppHandle, cut_short: &[String]) {
    if let Some(transcribe) = app.state::<crate::AppState>().settings().after_call.keeps_at_once() {
        for id in cut_short {
            if let Err(e) = keep_decision_as(app, id, transcribe) {
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
    Ok(updated.item_with_screens(&crate::screens::store::ScreenStore::in_dir(&state.data_dir)))
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

/// Whether a Live Assist session from a prep saved this recording (cleared with it).
fn take_assist_saved(state: &crate::AppState, client_ref: &str) -> bool {
    let mut saved = state.assist_auto_saved.lock().unwrap_or_else(|p| p.into_inner());
    if saved.as_deref() == Some(client_ref) {
        *saved = None;
        return true;
    }
    false
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
    Ok(updated.item_with_screens(&crate::screens::store::ScreenStore::in_dir(&state.data_dir)))
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
    Ok(updated.item_with_screens(&crate::screens::store::ScreenStore::in_dir(&state.data_dir)))
}

/// Shows the main window on a view (tray "Review recordings", the chip's title).
pub fn navigate(app: &AppHandle, view: &str, pending_id: Option<String>, rename: bool) {
    let payload = crate::commands::NavigatePayload { view: view.to_string(), pending_id, rename, recording_id: None, link: None, section: None };
    crate::tray::show_main(app);
    crate::lifecycle::emit(app, crate::lifecycle::MAIN, "main:navigate", payload);
}

/// Opens a Settings page (or a setting on it) in the main window.
pub fn open_settings(app: &AppHandle, section: &str) {
    let payload = crate::commands::NavigatePayload { view: "settings".into(), pending_id: None, rename: false, recording_id: None, link: None, section: Some(section.to_string()) };
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
    let held = surfaces(&state).clear_card(id).unwrap_or_default();
    after_card(app, held, None);
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
            check_system_side(&app);
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
        let permissions = state.permissions_now();
        let warning = state.system_watch.lock().unwrap_or_else(|p| p.into_inner()).warning();
        let next = crate::permissions::missing_with(info.mode, &permissions, warning);
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

/// Whether the running call recording records the other side (a Mac: macOS lets a copy of EchoLedger it no longer
/// allows open a tap that delivers nothing, while its privacy call still says yes). Looked at every second: within
/// about 3 s of the start a system side that failed, delivered nothing, or only digital silence while the microphone
/// hears sound is said on the pill and in the main window, in the recording's notes, the log and Copy diagnostics. The
/// microphone records on. Windows' loopback delivers nothing while nothing plays, so it is not looked at there.
fn check_system_side(app: &AppHandle) {
    if !cfg!(target_os = "macos") {
        return;
    }
    let state = app.state::<crate::AppState>();
    let Some(side) = state.capture.system_side() else { return };
    let verdict = state.system_watch.lock().unwrap_or_else(|p| p.into_inner()).look(&side);
    if verdict == crate::audio::health::SystemVerdict::Same {
        return;
    }
    // What a change does reads the system and writes a file: beside the ticker, so it never waits for them.
    let app = app.clone();
    tauri::async_runtime::spawn_blocking(move || system_side_changed(&app, verdict));
}

/// What a change of the system-side check does: says it in the log and the recording's notes, keeps what was found for
/// Setup and the Mac check, and puts the warning on the pill and in the main window, or takes it away once the other
/// side is heard.
fn system_side_changed(app: &AppHandle, verdict: crate::audio::health::SystemVerdict) {
    use crate::audio::health::SystemVerdict;
    let state = app.state::<crate::AppState>();
    // A later look may have changed it meanwhile (the other side heard a moment after it went missing): an old verdict
    // never shows the warning again, or clears it. The watch stays held while what was found is kept, so two such
    // jobs keep it in the order of their looks.
    let watch = state.system_watch.lock().unwrap_or_else(|p| p.into_inner());
    if !watch.still(&verdict) {
        return;
    }
    match verdict {
        SystemVerdict::Same => return,
        SystemVerdict::Missing(problem) => {
            let reason = problem.reason();
            eprintln!("[echoledger] capture: the other side isn't being recorded: {reason}; the microphone records on");
            state.capture.note(&format!("Other side not recorded: {reason}"));
            // Setup, the Mac check and the next recording say System Audio Recording is missing.
            state.system_audio_checked(Some(Err(reason)));
        }
        SystemVerdict::Recording => {
            if state.permission_memory_now().system_audio_failing.is_some() {
                eprintln!("[echoledger] capture: the other side is being recorded again");
            }
            state.system_audio_checked(Some(Ok(())));
        }
    }
    drop(watch);
    let Some(info) = state.capture.info() else { return };
    let permissions = state.permissions_now();
    let warning = state.system_watch.lock().unwrap_or_else(|p| p.into_inner()).warning();
    *state.recording_missing.lock().unwrap_or_else(|p| p.into_inner()) = crate::permissions::missing_with(info.mode, &permissions, warning);
    emit_status(app);
}

/// "Try again" on the warning: opens the system side once more (System Audio Recording allowed meanwhile). False when
/// only quitting and reopening EchoLedger would. The warning stays until the other side is heard; when the check after
/// the try still finds it missing, it says to quit and reopen.
pub fn retry_system_audio(app: &AppHandle) -> bool {
    let state = app.state::<crate::AppState>();
    if !state.capture.retry_system() {
        return false;
    }
    state.system_watch.lock().unwrap_or_else(|p| p.into_inner()).retry();
    eprintln!("[echoledger] capture: trying the system side again");
    true
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
        // A recovered recording keeps its mark in the Library and Activity after its transcript renames it.
        if item.recovered
            && let Ok(UploadOutcome::Ready { session_id } | UploadOutcome::Verifying { session_id }) = &result
            && crate::recovered::remember(&state.data_dir, session_id)
        {
            let _ = app.emit("recovered:changed", ());
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

/// Opens from outside (a second launch, a reopen) and whether the start-up is done: one that comes before is kept and
/// answered by [`started`].
pub struct Outside {
    started: bool,
    queued: Vec<Vec<String>>,
}

static OUTSIDE: std::sync::Mutex<Outside> = std::sync::Mutex::new(Outside { started: false, queued: Vec::new() });

/// Whether EchoLedger has started; if not, `args` is kept for when it has (pure on `gate`).
pub fn ready_or_queue(gate: &std::sync::Mutex<Outside>, args: &[String]) -> bool {
    let mut gate = gate.lock().unwrap_or_else(|p| p.into_inner());
    if !gate.started {
        gate.queued.push(args.to_vec());
    }
    gate.started
}

/// Marks the start-up done and gives what came before it, in order (pure on `gate`).
pub fn mark_started(gate: &std::sync::Mutex<Outside>) -> Vec<Vec<String>> {
    let mut gate = gate.lock().unwrap_or_else(|p| p.into_inner());
    gate.started = true;
    std::mem::take(&mut gate.queued)
}

/// The end of the setup: the opens from outside that came during the start-up are answered, once each.
pub fn started(app: &AppHandle) {
    for args in mark_started(&OUTSIDE) {
        show_main_from_outside(app, &args);
    }
}

/// Another start of the app (a second launch, the app opened again on a Mac from Finder, Launchpad, Spotlight or the
/// Dock, an `echoledger://open` link, or a click on one of our notifications): show the window and open the link it was
/// started with. With no main window yet it opens on the recording of a notification sent in the last ten minutes; an
/// existing window only comes to the front and keeps its page (`lifecycle::reopen_action`). The notified recording is
/// forgotten either way.
pub fn show_main_from_outside(app: &AppHandle, args: &[String]) {
    // Opened again while EchoLedger still starts (the setup may wait on the keychain's prompt): answered once started.
    if !ready_or_queue(&OUTSIDE, args) || app.try_state::<crate::AppState>().is_none() {
        return;
    }
    if handle_links(app, args) {
        return;
    }
    let notified = app.state::<crate::AppState>().notified.lock().unwrap_or_else(|p| p.into_inner()).take().filter(|(_, at)| at.elapsed() < NOTIFIED_WINDOW);
    let main_exists = app.get_webview_window(crate::lifecycle::MAIN).is_some();
    crate::tray::show_main(app);
    if let (crate::lifecycle::Reopen::OpenNotified, Some((id, _))) = (crate::lifecycle::reopen_action(main_exists, notified.is_some()), notified) {
        let payload = crate::commands::NavigatePayload { view: "library".into(), pending_id: None, rename: false, recording_id: Some(id), link: None, section: None };
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
    let payload = crate::commands::NavigatePayload { view: "library".into(), pending_id: None, rename: false, recording_id: None, link: Some(link), section: None };
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

#[cfg(test)]
mod start_up_tests {
    use super::{Outside, mark_started, ready_or_queue};

    #[test]
    fn an_open_from_outside_during_the_start_up_waits_for_it_and_is_answered_once() {
        let gate = std::sync::Mutex::new(Outside { started: false, queued: Vec::new() });
        let link = vec!["EchoLedger".to_string(), "echoledger://open/library".to_string()];
        assert!(!ready_or_queue(&gate, &[]), "still starting: kept");
        assert!(!ready_or_queue(&gate, &link));
        assert_eq!(mark_started(&gate), vec![Vec::new(), link], "answered in order once started");
        assert!(ready_or_queue(&gate, &[]), "started: answered at once");
        assert!(mark_started(&gate).is_empty(), "never twice");
    }

    #[test]
    fn the_state_is_managed_before_the_keychain_is_read_and_opens_from_outside_wait_for_the_start_up() {
        // A second launch while the setup waited on the keychain's prompt found no state and crashed the running app.
        let lib = include_str!("lib.rs");
        let load = &lib[lib.find("    fn load(data_dir: PathBuf, smoke: bool) -> Self {").unwrap()..];
        let load = &load[..load.find("\n    }\n").unwrap()];
        assert!(!load.contains("token_store.load()"), "AppState::load reads no keychain: {load}");
        let setup = &lib[lib.find(".setup(move |app| {").unwrap()..];
        let manage = setup.find("app.manage(AppState::load(data_dir, smoke_test));").unwrap();
        let token = setup.find("app.state::<AppState>().load_token();").expect("the token is read in the setup");
        let started = setup.find("actions::started(&handle);").expect("the setup ends by answering what came during it");
        assert!(manage < token && token < started);
        assert!(started > setup.find("actions::handle_links(&handle, &std::env::args()").unwrap() && setup[started..].find("Ok(())") < setup[started..].find(".on_window_event"));
        let actions = include_str!("actions.rs");
        let outside = &actions[actions.find("pub fn show_main_from_outside(").unwrap()..];
        let gate = outside.find("if !ready_or_queue(&OUTSIDE, args) || app.try_state::<crate::AppState>().is_none() {").expect("gated");
        assert!(gate < outside.find("handle_links(app, args)").unwrap() && gate < outside.find(".state::<crate::AppState>()").unwrap());
        assert!(lib.contains("tauri_plugin_single_instance::init(|app, argv, _cwd| actions::show_main_from_outside(app, &argv))"));
    }
}

#[cfg(test)]
mod silence_tests {
    use super::{NO_SOUND, WITHHELD, input_withheld, silent_recording_message};
    use std::path::Path;

    fn wav(path: &Path, samples: &[i16]) {
        let spec = hound::WavSpec { channels: 1, sample_rate: 16_000, bits_per_sample: 16, sample_format: hound::SampleFormat::Int };
        let mut w = hound::WavWriter::create(path, spec).unwrap();
        for &s in samples {
            w.write_sample(s).unwrap();
        }
        w.finalize().unwrap();
    }

    // D-168: an unsigned build got only zeros from macOS for 3 s of a voice note, and said "check your microphone".
    #[test]
    fn exact_zeros_on_both_sides_for_over_a_second_mean_macos_withheld_the_input() {
        let dir = tempfile::tempdir().unwrap();
        let (mic, sys) = (dir.path().join("mic.wav"), dir.path().join("system.wav"));
        wav(&mic, &vec![0; 48_000]);
        wav(&sys, &vec![0; 48_000]);
        assert!(input_withheld(&mic, &sys));
        assert_eq!(silent_recording_message(true), WITHHELD);
        assert!(WITHHELD.contains("System Settings") && WITHHELD.contains("Microphone"));
    }

    #[test]
    fn a_real_quiet_microphone_or_a_moment_too_short_is_not_withheld() {
        let dir = tempfile::tempdir().unwrap();
        let (mic, sys) = (dir.path().join("mic.wav"), dir.path().join("system.wav"));
        // A muted microphone still has its noise floor: a few steps above zero.
        let noise: Vec<i16> = (0..48_000).map(|i| [0, 2, -1, 3, -2][i % 5]).collect();
        wav(&mic, &noise);
        wav(&sys, &vec![0; 48_000]);
        assert!(!input_withheld(&mic, &sys));
        // Started and stopped at once: too short to tell.
        wav(&mic, &vec![0; 4_000]);
        wav(&sys, &vec![0; 4_000]);
        assert!(!input_withheld(&mic, &sys));
        // No files: not withheld either (the plain message).
        assert!(!input_withheld(&dir.path().join("none.wav"), &sys));
        assert_eq!(silent_recording_message(false), NO_SOUND);
    }
}
