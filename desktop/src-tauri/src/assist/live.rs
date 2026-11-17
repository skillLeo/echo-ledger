//! A Live Assist session as the app runs it (docs/API_ASSIST.md 1.9.0-g, L4): `assist_start` starts the recording
//! first (with captions, whose turns feed the relay), then the server session linked to it, then the relay connection
//! pinned with `relay_pins`; it sends the prep as the first `context_update` and opens the `assist` card window. What
//! the relay sends reaches the card as `assist:message` ([`super::card`]); final turns go to the server every 5 s or
//! 10 at a time. `assist_end` ends the server session and stops the recording. A scheduled prep is armed 2 minutes
//! before its time and waits up to 10 minutes: a call recording that starts meanwhile starts its session.

use std::sync::Arc;
use std::sync::atomic::{AtomicU64, Ordering};
use std::time::{Duration, Instant};

use chrono::{DateTime, Utc};
use serde::Deserialize;
use tauri::{AppHandle, Emitter, Manager};

use super::card::{self, Budget, Card, Snapshot, TurnBatch};
use super::client::{AssistEvent, EndReason};
use super::protocol::{Brief, ClientMessage, ContextUpdate, Document, Settings};
use super::session::{self, AssistStarted};
use super::turns::TurnFeeder;
use super::{Running, Taken};
use crate::error::{AppError, AppResult};
use crate::net::models::{AssistStartRequest, Prep};

/// The card window (built like the other floating panels, hidden from screen sharing by the privacy service).
pub const WINDOW: &str = crate::privacy::ASSIST;
/// A scheduled prep is armed this long before its time.
pub const ARM_EARLY: Duration = Duration::from_secs(120);
/// How often the card hears the assist time left.
const BUDGET_EVERY: Duration = Duration::from_secs(60);

/// A prep armed for its meeting: from `armed_at` until `until` (the server's `armed_until`, else 10 minutes after its
/// time), with the app and the person it names, if any.
#[derive(Clone, PartialEq, Eq)]
pub struct Armed {
    pub prep_id: String,
    pub title: String,
    pub at: DateTime<Utc>,
    pub armed_at: DateTime<Utc>,
    pub until: DateTime<Utc>,
    /// A meeting app the prep names (`zoom`, `google_meet`, `whatsapp`…).
    pub app: Option<&'static str>,
    /// The person the prep is with (`counterpart`).
    pub person: Option<String>,
}

/// What people wrote stays out of logs.
impl std::fmt::Debug for Armed {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Armed").field("prep_id", &self.prep_id).field("until", &self.until).field("app", &self.app).finish_non_exhaustive()
    }
}

/// The meeting apps a prep can name, as the recording's source app reads (WhatsApp's three apps are one).
const APPS: [(&str, &[&str]); 9] = [
    ("google_meet", &["google meet", "meet.google"]),
    ("zoom", &["zoom"]),
    ("microsoft_teams", &["microsoft teams", "teams"]),
    ("whatsapp", &["whatsapp"]),
    ("skype", &["skype"]),
    ("slack", &["slack"]),
    ("telegram", &["telegram"]),
    ("signal", &["signal"]),
    ("discord", &["discord"]),
];

fn words(text: &str) -> Vec<String> {
    text.to_lowercase().split(|c: char| !c.is_alphanumeric() && c != '.').filter(|w| !w.is_empty()).map(str::to_string).collect()
}

/// The meeting app a prep names in its title, company, counterpart or instructions, if exactly one (pure).
pub fn named_app(prep: &Prep) -> Option<&'static str> {
    let text = format!(" {} ", words(&[prep.title.as_str(), &prep.company, &prep.counterpart, &prep.instructions].join(" ")).join(" "));
    let named: Vec<&'static str> = APPS.iter().filter(|(_, names)| names.iter().any(|n| text.contains(&format!(" {n} ")))).map(|(key, _)| *key).collect();
    (named.len() == 1).then(|| named[0])
}

fn same_app(named: &str, source_app: &str) -> bool {
    if named == "whatsapp" { source_app.starts_with("whatsapp") } else { named == source_app }
}

/// The caller's name and the prep's person are the same person: a word of one is a word of the other (pure).
pub fn same_person(person: &str, caller: &str) -> bool {
    let caller = words(caller);
    words(person).iter().filter(|w| w.chars().count() >= 2).any(|w| caller.contains(w))
}

impl Armed {
    pub fn from_prep(prep: &Prep, at: DateTime<Utc>, armed_at: DateTime<Utc>, until: DateTime<Utc>) -> Self {
        let person = Some(prep.counterpart.trim().to_string()).filter(|p| !p.is_empty());
        Armed { prep_id: prep.id.clone(), title: prep.title.clone(), at, armed_at, until, app: named_app(prep), person }
    }
}

/// A recording that just started, as arming sees it.
#[derive(Clone, Copy, Debug)]
pub struct CallStart<'a> {
    pub mode: crate::audio::RecordingMode,
    pub started_at: DateTime<Utc>,
    pub source_app: &'a str,
    /// Who it is with, once its window's title says.
    pub caller: Option<&'a str>,
}

/// What an armed prep does with a recording that started (pure).
#[derive(Clone, Debug, PartialEq, Eq)]
pub enum Arming {
    /// Not its meeting: a voice note, a call that began before it was armed or after its time.
    Nothing,
    /// The app and the person it names match: Live Assist starts.
    Start(String),
    /// It names nobody, something does not match, or two preps could be this call: the card asks.
    Ask(String),
    /// It names a person and the caller is not known yet: asked again in a moment.
    Wait,
}

fn verdict(armed: &Armed, call: &CallStart<'_>) -> Arming {
    if call.mode != crate::audio::RecordingMode::Call || call.started_at < armed.armed_at || call.started_at > armed.until {
        return Arming::Nothing;
    }
    let app = armed.app.map(|a| same_app(a, call.source_app));
    let person = armed.person.as_deref().map(|p| call.caller.map(|c| same_person(p, c)));
    match (app, person) {
        (Some(false), _) | (_, Some(Some(false))) | (None, None) => Arming::Ask(armed.prep_id.clone()),
        (_, Some(None)) => Arming::Wait,
        _ => Arming::Start(armed.prep_id.clone()),
    }
}

/// What the armed preps do with a recording that started (pure): one that matches starts; two that could be this call,
/// or one that names nothing or does not match, make the card ask about the one scheduled nearest the call.
pub fn decide(armed: &[Armed], call: &CallStart<'_>) -> Arming {
    let candidates: Vec<(&Armed, Arming)> = armed.iter().map(|a| (a, verdict(a, call))).filter(|(_, v)| *v != Arming::Nothing).collect();
    let nearest = || candidates.iter().min_by_key(|(a, _)| (a.at - call.started_at).num_seconds().abs()).map(|(a, _)| a.prep_id.clone());
    let starts: Vec<&String> = candidates.iter().filter_map(|(_, v)| if let Arming::Start(id) = v { Some(id) } else { None }).collect();
    match starts.as_slice() {
        [one] => Arming::Start((*one).clone()),
        [] if candidates.iter().any(|(_, v)| *v == Arming::Wait) => Arming::Wait,
        _ => nearest().map_or(Arming::Nothing, Arming::Ask),
    }
}

/// The card's question when an armed prep may be this call (it starts nothing by itself).
pub fn offer_message(armed: &Armed) -> serde_json::Value {
    serde_json::json!({"type": "offer", "prep_id": armed.prep_id, "title": armed.title, "question": "Start Live Assist for this call?"})
}

/// When a prep scheduled for `at` is armed (pure).
pub fn arm_at(at: DateTime<Utc>) -> DateTime<Utc> {
    at - chrono::Duration::from_std(ARM_EARLY).unwrap_or_default()
}

/// Ids in paths are the server's ids: letters, digits, `-` and `_`, at most 64 (pure).
pub fn valid_id(id: &str) -> bool {
    !id.is_empty() && id.len() <= 64 && id.bytes().all(|b| b.is_ascii_alphanumeric() || b == b'-' || b == b'_')
}

fn checked(id: &str) -> AppResult<&str> {
    if valid_id(id) { Ok(id) } else { Err(AppError::msg("That Live Assist item can't be found.")) }
}

/// The language a `translate` asks for: the prep's language help, or Roman Urdu when it is off (pure).
pub fn translate_language(language_help: &str) -> &'static str {
    match language_help {
        "urdu" => "urdu",
        "arabic" => "arabic",
        _ => "roman_urdu",
    }
}

/// The relay message for a button on the card (pure): `help_now`, `regenerate`, `shorter`, `formal`, `translate`
/// (latest suggestion) or `cancel` (every shown one).
pub fn send_message(kind: &str, language_help: &str) -> Option<ClientMessage> {
    Some(match kind {
        "help_now" => ClientMessage::HelpNow { turn_id: None },
        "regenerate" => ClientMessage::Regenerate { suggestion_id: None },
        "shorter" => ClientMessage::Shorter { suggestion_id: None },
        "formal" => ClientMessage::Formal { suggestion_id: None },
        "translate" => ClientMessage::Translate { suggestion_id: None, language: translate_language(language_help).into() },
        "cancel" => ClientMessage::Cancel { suggestion_id: None },
        _ => return None,
    })
}

/// What the person did with a suggestion, as the server takes it (pure).
pub fn feedback_action(action: &str) -> Option<&'static str> {
    ["used", "dismissed", "regenerated", "shorter", "formal", "translated"].into_iter().find(|a| *a == action)
}

/// The prep as the relay's `context_update.brief`, and its settings (pure).
pub fn first_context(prep: Option<&Prep>, mode: &str) -> ContextUpdate {
    let brief = prep.map(|p| Brief {
        meeting_type: p.meeting_type.clone(),
        title: p.title.clone(),
        counterpart: p.counterpart.clone(),
        company: p.company.clone(),
        goals: p.goals.clone(),
        done: p.done.clone(),
        not_done: p.not_done.clone(),
        to_discuss: p.to_discuss.clone(),
        instructions: p.instructions.clone(),
    });
    let settings = Settings {
        mode: Some(mode.to_string()),
        hint_level: prep.map(|p| p.hint_level.clone()),
        language_help: prep.map(|p| p.language_help.clone()),
        assist: Some("on".into()),
    };
    // The documents' extracted text (contract 1.9.0-m), the ones that have some.
    let documents: Vec<Document> = prep
        .map(|p| p.documents.iter().filter_map(|d| d.text.as_ref().filter(|t| !t.trim().is_empty()).map(|text| Document { id: d.file_id.clone(), title: d.name.clone(), text: text.clone() })).collect())
        .unwrap_or_default();
    ContextUpdate { brief, settings: Some(settings), documents: (!documents.is_empty()).then_some(documents), ..ContextUpdate::default() }
}

/// `assist_set`'s settings from the card.
#[derive(Clone, Debug, Default, Deserialize)]
pub struct CardSettings {
    pub mode: Option<String>,
    pub hint_level: Option<String>,
    pub paused: Option<bool>,
    pub muted_seconds: Option<u64>,
}

/// The `context_update.settings` for a change on the card (pure): `assist` is `paused` while paused or muted.
pub fn settings_update(change: &CardSettings, quiet: bool) -> Settings {
    Settings { mode: change.mode.clone(), hint_level: change.hint_level.clone(), language_help: None, assist: Some(if quiet { "paused" } else { "on" }.into()) }
}

fn emit(app: &AppHandle, message: &serde_json::Value) {
    let _ = app.emit(card::EVENT, message);
}

/// A caption line for the card (from the captions' events).
pub fn emit_line(app: &AppHandle, line: super::Line) {
    if let Some(message) = &line.card {
        emit(app, message);
    }
    if line.flush {
        let app = app.clone();
        tauri::async_runtime::spawn(async move { flush_turns(&app, false).await });
    }
}

/// Sends the turns that are due (all that wait with `force`); a batch that fails waits for the next try.
async fn flush_turns(app: &AppHandle, force: bool) {
    let state = app.state::<crate::AppState>();
    let now = Instant::now();
    let Some((id, rows)) = state.assist.with_current(|r| (force || r.batch.due(now)).then(|| (r.id.clone(), r.batch.take(now)))).flatten() else { return };
    if rows.is_empty() {
        return;
    }
    let sent = match state.api() {
        Ok(api) => api.assist_turns(&id, &rows).await,
        Err(e) => Err(e),
    };
    state.assist.with(&id, |r| match sent {
        Ok(_) => r.batch.accepted(&rows),
        Err(_) => r.batch.failed(rows),
    });
}

/// The turns of a session that was taken out, sent once more (best effort).
async fn send_last_turns(app: &AppHandle, taken: &Taken) {
    if taken.rows.is_empty() {
        return;
    }
    if let Ok(api) = app.state::<crate::AppState>().api() {
        for rows in taken.rows.chunks(card::BATCH_SIZE) {
            let _ = api.assist_turns(&taken.id, rows).await;
        }
    }
}

/// Starts a session: the recording first (unless it is Practice), then the server and the relay; the card opens.
pub async fn start(app: &AppHandle, prep_id: Option<String>, mode: Option<String>, practice: bool) -> AppResult<AssistStarted> {
    let state = app.state::<crate::AppState>();
    let flags = crate::ui_flags::cached(&state);
    if !super::enabled(&flags) {
        return Err(AppError::msg("Live Assist isn't on for you yet."));
    }
    if state.assist.session_id().is_some() {
        return Err(AppError::msg("Live Assist is already on for this call."));
    }
    let api = Arc::new(state.api()?);
    let prep = match &prep_id {
        Some(id) => Some(api.assist_prep(checked(id)?).await?),
        None => None,
    };
    let mode = mode.filter(|m| !m.is_empty()).or_else(|| prep.as_ref().map(|p| p.mode.clone())).unwrap_or_else(|| "balanced".into());
    let captions_were_on = state.live.is_live();
    let (recording_session_id, started_recording) = if practice { (None, None) } else {
        let (id, started) = recording_session(app).await?;
        (Some(id), started)
    };
    let request = AssistStartRequest { prep_id: prep.as_ref().map(|p| p.id.clone()), mode: Some(mode.clone()), platform: "desktop".into(), recording_session_id, practice };
    let pins = super::relay_pins(state.server_config.lock().unwrap_or_else(|p| p.into_inner()).as_ref());
    let meeting_type = prep.as_ref().map(|p| p.meeting_type.clone());
    // What the relay says before the session is in the registry (its `ready` can be that quick) waits here.
    let early: Arc<std::sync::Mutex<Early>> = Arc::default();
    let (events, events_app, meeting) = (early.clone(), app.clone(), meeting_type.clone());
    let (answer, handle) = session::start(api, &flags, pins, request, move |event| {
        let mut early = events.lock().unwrap_or_else(|p| p.into_inner());
        match early.id.clone() {
            Some(id) => on_event(&events_app, &id, meeting.as_deref(), event),
            None => early.waiting.push(event),
        }
    })
    .await?;
    let started = AssistStarted::from_answer(&answer);
    let plan = answer.plan.clone().unwrap_or_default();
    let budget = Budget { left_at_start: plan.seconds_left, total: plan.seconds_total, started: Instant::now() };
    handle.send(ClientMessage::ContextUpdate(Box::new(first_context(prep.as_ref(), &mode))));
    state.assist.begin(Running {
        id: started.session_id.clone(),
        handle,
        turns: TurnFeeder::default(),
        card: Card::new(meeting_type.clone(), prep.as_ref().map(|p| p.hint_level.clone())),
        batch: TurnBatch::new(Instant::now()),
        budget,
        language_help: prep.as_ref().map(|p| p.language_help.clone()).unwrap_or_else(|| "off".into()),
        started_recording,
        captions_were_on,
    });
    // From a prep: the call is saved without the after-call card.
    if started.auto_saved && let Some(info) = state.capture.info() {
        *state.assist_auto_saved.lock().unwrap_or_else(|p| p.into_inner()) = Some(info.client_ref);
    }
    let first_budget = budget.message(Instant::now());
    state.assist.with(&started.session_id, |r| r.card.record(&first_budget));
    emit(app, &first_budget);
    {
        let mut early = early.lock().unwrap_or_else(|p| p.into_inner());
        early.id = Some(started.session_id.clone());
        for event in std::mem::take(&mut early.waiting) {
            on_event(app, &started.session_id, meeting_type.as_deref(), event);
        }
    }
    crate::lifecycle::show(app, WINDOW, "assist");
    spawn_upkeep(app, started.session_id.clone());
    Ok(started)
}

/// The relay's events of a session that is not in the registry yet.
#[derive(Default)]
struct Early {
    id: Option<String>,
    waiting: Vec<AssistEvent>,
}

/// The open server session of the recording the assist runs beside, and the recording this started (its
/// `client_ref`): a call recording is started (with captions, whose turns feed the relay) when none runs.
async fn recording_session(app: &AppHandle) -> AppResult<(String, Option<String>)> {
    let state = app.state::<crate::AppState>();
    let started = if state.capture.is_recording() {
        None
    } else {
        let source = state.detect.meeting().map_or("other", |m| m.app.as_str());
        crate::actions::start_call_recording_with(app, source, false, true).await?;
        state.capture.info().map(|i| i.client_ref)
    };
    if !state.live.is_live() {
        crate::actions::start_captions(app).await?;
    }
    let info = state.capture.info().ok_or_else(|| AppError::msg("The recording ended before Live Assist could start."))?;
    Ok((crate::actions::ensure_server_session(app, &info).await?, started))
}

/// What the end of a session does to the recording beside it (pure).
#[derive(Debug, PartialEq, Eq)]
pub struct AtEnd {
    /// The session started this recording: it stops (its captions with it).
    pub stop_recording: bool,
    /// The person's own recording goes on, and its captions were off before the session: they go off again.
    pub captions_off: bool,
}

/// `started`: the recording the session started; `running`: the recording now; `captions_were_on`: before the session.
pub fn at_end(started: Option<&str>, running: Option<&str>, captions_were_on: bool) -> AtEnd {
    let Some(running) = running else { return AtEnd { stop_recording: false, captions_off: false } };
    if started == Some(running) {
        return AtEnd { stop_recording: true, captions_off: false };
    }
    AtEnd { stop_recording: false, captions_off: !captions_were_on }
}

/// What the relay connection of session `id` heard.
fn on_event(app: &AppHandle, id: &str, meeting_type: Option<&str>, event: AssistEvent) {
    let state = app.state::<crate::AppState>();
    match event {
        AssistEvent::Message(message) => {
            let Some(value) = card::relay_message(&message, meeting_type) else { return };
            if state.assist.with(id, |r| r.card.record(&value)).is_some() {
                emit(app, &value);
            }
        }
        AssistEvent::Connected { .. } | AssistEvent::Reconnecting { .. } => {}
        // Stopped by the app: `end` tells the card.
        AssistEvent::Ended(EndReason::Closed) => {}
        AssistEvent::Ended(reason) => {
            if state.assist.session_id().as_deref() != Some(id) {
                return;
            }
            let Some(taken) = state.assist.take() else { return };
            emit(app, &card::ended_message(session::end_word(&reason), session::end_sentence(&reason)));
            let app = app.clone();
            tauri::async_runtime::spawn(async move {
                send_last_turns(&app, &taken).await;
                // A newer connection took the session over elsewhere: it is not ended here.
                if reason != EndReason::Replaced && let Ok(api) = app.state::<crate::AppState>().api() {
                    let _ = api.assist_end(&taken.id).await;
                }
            });
        }
    }
}

/// While session `id` runs: due turn batches every second, the assist time left every minute.
fn spawn_upkeep(app: &AppHandle, id: String) {
    let app = app.clone();
    tauri::async_runtime::spawn(async move {
        let mut last_budget = Instant::now();
        loop {
            tokio::time::sleep(Duration::from_secs(1)).await;
            let state = app.state::<crate::AppState>();
            if state.assist.session_id().as_deref() != Some(id.as_str()) {
                return;
            }
            flush_turns(&app, false).await;
            if last_budget.elapsed() >= BUDGET_EVERY {
                last_budget = Instant::now();
                if let Some(message) = state.assist.with(&id, |r| {
                    let message = r.budget.message(Instant::now());
                    r.card.record(&message);
                    message
                }) {
                    emit(&app, &message);
                }
            }
        }
    });
}

/// Ends the session that runs: the last turns go, the server is told, the card hears `ended` and hides. A recording the
/// session started stops; one the person started before goes on, with captions as they were before the session.
/// Nothing runs: nothing to do.
pub async fn end(app: &AppHandle) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    let Some(taken) = state.assist.take() else { return Ok(()) };
    send_last_turns(app, &taken).await;
    let ended = match state.api()?.assist_end(&taken.id).await {
        // Already ended on the server (from another device, or by its time): what was wanted.
        Err(e) if e.code() == Some("session_ended") => Ok(()),
        other => other,
    };
    emit(app, &card::ended_message("closed", None));
    crate::lifecycle::hide(app, WINDOW, "assist");
    let running = state.capture.info().map(|i| i.client_ref);
    let plan = at_end(taken.started_recording.as_deref(), running.as_deref(), taken.captions_were_on);
    if plan.stop_recording {
        crate::actions::stop_recording(app, false).await?;
    } else if plan.captions_off && state.live.is_live() {
        crate::actions::stop_captions(app);
    }
    ended
}

/// A button on the card.
pub fn send(app: &AppHandle, kind: &str) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    let message = state
        .assist
        .with_current(|r| send_message(kind, &r.language_help).map(|m| r.handle.send(m)))
        .ok_or_else(|| AppError::msg("Live Assist isn't running."))?;
    message.map(|_| ()).ok_or_else(|| AppError::msg("That isn't something Live Assist can do."))
}

/// The card's mode, hint level, pause or mute: the relay hears the settings; the card's state comes back.
pub fn set(app: &AppHandle, change: CardSettings) -> AppResult<Snapshot> {
    let state = app.state::<crate::AppState>();
    let now = Utc::now();
    let (id, snapshot) = state
        .assist
        .with_current(|r| {
            if let Some(level) = &change.hint_level {
                r.card.hint_level.clone_from(level);
            }
            if let Some(paused) = change.paused {
                r.card.paused = paused;
            }
            if let Some(seconds) = change.muted_seconds {
                r.card.mute(seconds, now);
            }
            let settings = settings_update(&change, r.card.quiet(now));
            r.handle.send(ClientMessage::ContextUpdate(Box::new(ContextUpdate { settings: Some(settings), ..ContextUpdate::default() })));
            (r.id.clone(), r.card.snapshot(now))
        })
        .ok_or_else(|| AppError::msg("Live Assist isn't running."))?;
    // A mute ends by itself: suggestions come again unless the card was paused meanwhile.
    if let Some(seconds) = change.muted_seconds {
        let app = app.clone();
        tauri::async_runtime::spawn(async move {
            tokio::time::sleep(Duration::from_secs(seconds.min(24 * 3600)) + Duration::from_millis(50)).await;
            app.state::<crate::AppState>().assist.with(&id, |r| {
                if !r.card.quiet(Utc::now()) {
                    let settings = Settings { assist: Some("on".into()), ..Settings::default() };
                    r.handle.send(ClientMessage::ContextUpdate(Box::new(ContextUpdate { settings: Some(settings), ..ContextUpdate::default() })));
                }
            });
        });
    }
    Ok(snapshot)
}

/// What the person did with a suggestion of the running session.
pub async fn feedback(app: &AppHandle, suggestion_id: &str, action: &str) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    let action = feedback_action(action).ok_or_else(|| AppError::msg("That isn't something Live Assist can do."))?;
    let id = state.assist.session_id().ok_or_else(|| AppError::msg("Live Assist isn't running."))?;
    state.api()?.assist_suggestion_action(&id, checked(suggestion_id)?, action).await
}

/// The card's state for a window opened mid-session; none when no session runs.
pub fn snapshot(app: &AppHandle) -> Option<Snapshot> {
    app.state::<crate::AppState>().assist.with_current(|r| r.card.snapshot(Utc::now()))
}

/// The card hides; the session goes on.
pub fn hide_card(app: &AppHandle) {
    crate::lifecycle::hide(app, WINDOW, "assist");
}

/// Whether a session ends because of the flags (pure): `live_assist` is off and one runs.
pub fn ends_with_flags(flags: &crate::ui_flags::UiFlags, running: bool) -> bool {
    running && !super::enabled(flags)
}

/// `live_assist` turned off for this person: a session that runs ends.
pub fn stop_unless_enabled(app: &AppHandle, flags: &crate::ui_flags::UiFlags) {
    let state = app.state::<crate::AppState>();
    if !ends_with_flags(flags, state.assist.session_id().is_some()) {
        return;
    }
    let app = app.clone();
    tauri::async_runtime::spawn(async move {
        let _ = end(&app).await;
    });
}

// ---- preps and scheduling ----------------------------------------------------------------------------------------

pub async fn preps(app: &AppHandle, status: Option<String>) -> AppResult<Vec<Prep>> {
    app.state::<crate::AppState>().api()?.assist_preps(status.as_deref().filter(|s| !s.is_empty())).await
}

pub async fn prep_save(app: &AppHandle, draft: crate::net::models::PrepDraft) -> AppResult<Prep> {
    if let Some(id) = &draft.id {
        checked(id)?;
    }
    app.state::<crate::AppState>().api()?.assist_prep_save(&draft).await
}

pub async fn prep_delete(app: &AppHandle, id: &str) -> AppResult<()> {
    app.state::<crate::AppState>().api()?.assist_prep_delete(checked(id)?).await
}

pub async fn prep_documents_add(app: &AppHandle, id: &str, file_ids: &[String]) -> AppResult<Prep> {
    if file_ids.is_empty() || !file_ids.iter().all(|f| valid_id(f)) {
        return Err(AppError::msg("Choose project files to add."));
    }
    app.state::<crate::AppState>().api()?.assist_prep_documents_add(checked(id)?, file_ids).await
}

pub async fn prep_document_remove(app: &AppHandle, id: &str, file_id: &str) -> AppResult<Prep> {
    app.state::<crate::AppState>().api()?.assist_prep_document_remove(checked(id)?, checked(file_id)?).await
}

/// One generation per prep's arming timer: a newer schedule cancels the older timer.
static ARMING: AtomicU64 = AtomicU64::new(0);

fn arming() -> &'static std::sync::Mutex<std::collections::HashMap<String, u64>> {
    static TIMERS: std::sync::OnceLock<std::sync::Mutex<std::collections::HashMap<String, u64>>> = std::sync::OnceLock::new();
    TIMERS.get_or_init(Default::default)
}

/// Schedules a prep for `at` (RFC 3339): the server stores it, and the app arms it 2 minutes before.
pub async fn schedule(app: &AppHandle, prep_id: &str, at: &str) -> AppResult<Prep> {
    let when = DateTime::parse_from_rfc3339(at).map_err(|_| AppError::msg("Choose a date and time for the meeting."))?.with_timezone(&Utc);
    let fields = serde_json::json!({"scheduled_at": when.to_rfc3339(), "status": "scheduled"});
    let prep = app.state::<crate::AppState>().api()?.assist_prep_patch(checked(prep_id)?, &fields).await?;
    arm_later(app, prep.clone(), when);
    Ok(prep)
}

/// Arms `prep` at [`arm_at`] (at once when that has passed); one armed entry per prep.
fn arm_later(app: &AppHandle, prep: Prep, at: DateTime<Utc>) {
    let prep_id = prep.id.clone();
    let generation = ARMING.fetch_add(1, Ordering::SeqCst) + 1;
    arming().lock().unwrap_or_else(|p| p.into_inner()).insert(prep_id.clone(), generation);
    let app = app.clone();
    tauri::async_runtime::spawn(async move {
        let wait = (arm_at(at) - Utc::now()).to_std().unwrap_or(Duration::ZERO);
        tokio::time::sleep(wait).await;
        if arming().lock().unwrap_or_else(|p| p.into_inner()).get(&prep_id) != Some(&generation) {
            return;
        }
        let state = app.state::<crate::AppState>();
        let Ok(api) = state.api() else { return };
        let until = match api.assist_prep_arm(&prep_id).await {
            Ok(until) => until.and_then(|u| DateTime::parse_from_rfc3339(&u).ok()).map(|u| u.with_timezone(&Utc)),
            Err(e) => {
                eprintln!("[echoledger] arming a Live Assist prep: {}", e.code().unwrap_or("error"));
                return;
            }
        };
        let until = until.unwrap_or(at + chrono::Duration::minutes(10));
        // Only a call that starts from now on can be the meeting, never one already running.
        let entry = Armed::from_prep(&prep, at, Utc::now(), until);
        let mut armed = state.assist.armed();
        armed.retain(|a| a.prep_id != prep_id && a.waits(Utc::now()));
        armed.push(entry);
    });
}

impl Armed {
    pub fn waits(&self, now: DateTime<Utc>) -> bool {
        now <= self.until
    }
}

/// At start or when Live Assist turns on: the scheduled preps get their arming timers again.
pub fn rearm(app: &AppHandle) {
    let app = app.clone();
    tauri::async_runtime::spawn(async move {
        let Ok(scheduled) = preps(&app, Some("scheduled".into())).await else { return };
        for prep in scheduled {
            let at = prep.scheduled_at.as_deref().and_then(|a| DateTime::parse_from_rfc3339(a).ok()).map(|a| a.with_timezone(&Utc));
            if let Some(at) = at.filter(|at| *at + chrono::Duration::minutes(10) > Utc::now()) {
                arm_later(&app, prep, at);
            }
        }
    });
}

/// How long an armed prep that names a person waits for the caller's name before the card asks.
const CALLER_WAIT: Duration = Duration::from_secs(30);

/// A recording started: an armed prep that matches it starts its session; one that may be this call makes the card
/// ask. Never a voice note, never a call that was running before the prep was armed.
pub fn on_recording_started(app: &AppHandle, info: &crate::audio::RecordingInfo) {
    if app.state::<crate::AppState>().assist.armed().is_empty() {
        return;
    }
    let (app, info) = (app.clone(), info.clone());
    tauri::async_runtime::spawn(async move {
        let asked_until = Instant::now() + CALLER_WAIT;
        loop {
            let state = app.state::<crate::AppState>();
            if state.assist.session_id().is_some() || state.capture.info().map(|i| i.client_ref) != Some(info.client_ref.clone()) {
                return;
            }
            let caller = state.recording_caller.lock().unwrap_or_else(|p| p.into_inner()).as_ref().filter(|(r, _)| *r == info.client_ref).and_then(|(_, c)| c.name.clone());
            let call = CallStart { mode: info.mode, started_at: info.started_at, source_app: &info.source_app, caller: caller.as_deref() };
            let decision = {
                let armed = state.assist.armed();
                match decide(&armed, &call) {
                    Arming::Wait if Instant::now() >= asked_until => armed.iter().find(|a| verdict(a, &call) == Arming::Wait).map_or(Arming::Nothing, |a| Arming::Ask(a.prep_id.clone())),
                    other => other,
                }
            };
            match decision {
                Arming::Nothing => return,
                Arming::Wait => tokio::time::sleep(Duration::from_secs(2)).await,
                Arming::Start(prep_id) => {
                    state.assist.armed().retain(|a| a.prep_id != prep_id);
                    if let Err(e) = start(&app, Some(prep_id), None, false).await {
                        let _ = app.emit("app:error", e.to_string());
                    }
                    return;
                }
                Arming::Ask(prep_id) => {
                    let offer = state.assist.armed().iter().find(|a| a.prep_id == prep_id).map(offer_message);
                    if let Some(offer) = offer {
                        crate::lifecycle::show(&app, WINDOW, "assist");
                        emit(&app, &offer);
                    }
                    return;
                }
            }
        }
    });
}

// ---- the card window ---------------------------------------------------------------------------------------------

static MOVES: AtomicU64 = AtomicU64::new(0);

/// The card was dragged: its place is saved once it rests (half a second), for the next session.
pub fn card_moved(app: &AppHandle) {
    let generation = MOVES.fetch_add(1, Ordering::SeqCst) + 1;
    let app = app.clone();
    tauri::async_runtime::spawn(async move {
        tokio::time::sleep(Duration::from_millis(500)).await;
        if MOVES.load(Ordering::SeqCst) != generation {
            return;
        }
        let Some(window) = app.get_webview_window(WINDOW) else { return };
        let Some(position) = crate::overlay::pill_position(&window) else { return };
        if let Ok(settings) = app.state::<crate::AppState>().update_settings(|s| s.assist_card_position = Some(position)) {
            let _ = app.emit("settings:changed", &settings);
        }
    });
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn frame(message: ClientMessage) -> serde_json::Value {
        serde_json::from_str(&message.frame(1)).unwrap()
    }

    #[test]
    fn the_card_s_buttons_become_the_relay_s_messages() {
        assert_eq!(frame(send_message("help_now", "off").unwrap()), json!({"type": "help_now", "seq": 1}));
        for kind in ["regenerate", "shorter", "formal", "cancel"] {
            assert_eq!(frame(send_message(kind, "off").unwrap()), json!({"type": kind, "seq": 1}), "the latest suggestion");
        }
        assert_eq!(frame(send_message("translate", "arabic").unwrap())["language"], "arabic", "the prep's language help");
        assert_eq!(frame(send_message("translate", "off").unwrap())["language"], "roman_urdu");
        assert!(send_message("token_refresh", "off").is_none(), "only the card's buttons");
        assert_eq!(feedback_action("used"), Some("used"));
        assert_eq!(feedback_action("dismissed"), Some("dismissed"));
        assert_eq!(feedback_action("deleted"), None);
    }

    #[test]
    fn the_prep_is_the_first_context_and_the_card_s_changes_are_settings() {
        let prep = Prep { id: "p1".into(), meeting_type: "interview".into(), title: "Backend role".into(), counterpart: "Sara".into(), hint_level: "full".into(), language_help: "urdu".into(), ..Prep::default() }.completed();
        let first = serde_json::to_value(first_context(Some(&prep), "balanced")).unwrap();
        assert_eq!(first["brief"], json!({"meeting_type": "interview", "title": "Backend role", "counterpart": "Sara"}));
        assert_eq!(first["settings"], json!({"mode": "balanced", "hint_level": "full", "language_help": "urdu", "assist": "on"}));
        assert!(first.get("documents").is_none(), "no documents: none sent");
        let mut with_docs = prep.clone();
        with_docs.documents = vec![
            crate::net::models::PrepDocument { file_id: "f1".into(), name: "CV.pdf".into(), tokens: Some(2140), text: Some("Payments engineer, 6 years.".into()) },
            crate::net::models::PrepDocument { file_id: "f2".into(), name: "Scan.png".into(), tokens: None, text: None },
        ];
        let sent = serde_json::to_value(first_context(Some(&with_docs), "balanced")).unwrap();
        assert_eq!(sent["documents"], json!([{"id": "f1", "title": "CV.pdf", "text": "Payments engineer, 6 years."}]), "the extracted text goes to the relay (1.9.0-m)");
        assert!(serde_json::to_value(&with_docs).unwrap()["documents"][0].get("text").is_none(), "and never to a window");
        assert!(!format!("{:?}", with_docs.documents[0]).contains("Payments"), "nor to a log");
        let bare = serde_json::to_value(first_context(None, "fast")).unwrap();
        assert_eq!(bare, json!({"settings": {"mode": "fast", "assist": "on"}}), "Start now without a prep");
        let muted = settings_update(&CardSettings { muted_seconds: Some(60), ..CardSettings::default() }, true);
        assert_eq!(serde_json::to_value(muted).unwrap(), json!({"assist": "paused"}));
        let level = settings_update(&CardSettings { hint_level: Some("keywords".into()), ..CardSettings::default() }, false);
        assert_eq!(serde_json::to_value(level).unwrap(), json!({"hint_level": "keywords", "assist": "on"}));
    }

    fn utc(text: &str) -> DateTime<Utc> {
        DateTime::parse_from_rfc3339(text).unwrap().with_timezone(&Utc)
    }

    fn armed(id: &str, title: &str, counterpart: &str, at: &str) -> Armed {
        let at = utc(at);
        let prep = Prep { id: id.into(), title: title.into(), counterpart: counterpart.into(), ..Prep::default() }.completed();
        Armed::from_prep(&prep, at, arm_at(at), at + chrono::Duration::minutes(10))
    }

    fn call<'a>(at: &str, source_app: &'a str, caller: Option<&'a str>) -> CallStart<'a> {
        CallStart { mode: crate::audio::RecordingMode::Call, started_at: utc(at), source_app, caller }
    }

    #[test]
    fn a_scheduled_prep_is_armed_two_minutes_early_and_waits_until_its_time() {
        let at = utc("2026-12-01T10:00:00Z");
        assert_eq!(arm_at(at).to_rfc3339(), "2026-12-01T09:58:00+00:00");
        let entry = armed("p1", "Interview", "", "2026-12-01T10:00:00Z");
        assert!(entry.waits(at + chrono::Duration::minutes(9)));
        assert!(!entry.waits(at + chrono::Duration::minutes(11)), "then the server cancels it");
    }

    #[test]
    fn a_prep_starts_only_with_a_matching_call_that_starts_inside_its_window() {
        let zoom_with_sara = [armed("p1", "Interview on Zoom", "Sara Khan", "2026-12-01T10:00:00Z")];
        assert_eq!(decide(&zoom_with_sara, &call("2026-12-01T10:01:00Z", "zoom", Some("Sara"))), Arming::Start("p1".into()), "app and person match");
        // A call already running when the prep was armed is never its meeting.
        assert_eq!(decide(&zoom_with_sara, &call("2026-12-01T09:50:00Z", "zoom", Some("Sara"))), Arming::Nothing);
        assert_eq!(decide(&zoom_with_sara, &call("2026-12-01T10:11:00Z", "zoom", Some("Sara"))), Arming::Nothing, "after its time");
        // A voice note is never a meeting.
        let note = CallStart { mode: crate::audio::RecordingMode::VoiceNote, ..call("2026-12-01T10:01:00Z", "voice_note", None) };
        assert_eq!(decide(&zoom_with_sara, &note), Arming::Nothing);
        // Another app: it does not start by itself; the card asks.
        assert_eq!(decide(&zoom_with_sara, &call("2026-12-01T10:01:00Z", "google_meet", Some("Sara"))), Arming::Ask("p1".into()));
        assert_eq!(decide(&zoom_with_sara, &call("2026-12-01T10:01:00Z", "zoom", Some("Omar Ali"))), Arming::Ask("p1".into()), "another person");
        assert_eq!(decide(&zoom_with_sara, &call("2026-12-01T10:01:00Z", "zoom", None)), Arming::Wait, "the caller's name is not known yet");
        // A prep that names neither: the card asks.
        let bare = [armed("p2", "Weekly", "", "2026-12-01T10:00:00Z")];
        assert_eq!(decide(&bare, &call("2026-12-01T10:00:30Z", "zoom", None)), Arming::Ask("p2".into()));
        let whatsapp = [armed("p3", "Call on WhatsApp", "", "2026-12-01T10:00:00Z")];
        assert_eq!(decide(&whatsapp, &call("2026-12-01T10:00:30Z", "whatsapp_business", None)), Arming::Start("p3".into()), "WhatsApp's apps are one");
    }

    #[test]
    fn two_preps_close_together_make_the_card_ask_about_the_nearest() {
        let two = [armed("p1", "Weekly", "", "2026-12-01T10:00:00Z"), armed("p2", "Standup", "", "2026-12-01T10:05:00Z")];
        assert_eq!(decide(&two, &call("2026-12-01T10:04:00Z", "zoom", None)), Arming::Ask("p2".into()));
        let both_zoom = [armed("p1", "Zoom one", "", "2026-12-01T10:00:00Z"), armed("p2", "Zoom two", "", "2026-12-01T10:05:00Z")];
        assert_eq!(decide(&both_zoom, &call("2026-12-01T10:04:00Z", "zoom", None)), Arming::Ask("p2".into()), "two that match: asked, never guessed");
        assert_eq!(decide(&both_zoom, &call("2026-12-01T10:01:00Z", "zoom", None)), Arming::Start("p1".into()), "before the second is armed, only the first can be it");
        let one_matches = [armed("p1", "On Zoom", "", "2026-12-01T10:00:00Z"), armed("p2", "Weekly", "", "2026-12-01T10:05:00Z")];
        assert_eq!(decide(&one_matches, &call("2026-12-01T10:04:00Z", "zoom", None)), Arming::Start("p1".into()), "the one that names this app");
    }

    #[test]
    fn apps_and_people_are_read_from_the_prep_s_words() {
        let prep = |title: &str| Prep { id: "p".into(), title: title.into(), ..Prep::default() };
        assert_eq!(named_app(&prep("Interview on Google Meet")), Some("google_meet"));
        assert_eq!(named_app(&prep("Teams sync")), Some("microsoft_teams"));
        assert_eq!(named_app(&prep("Zoom or Teams")), None, "two apps: none for sure");
        assert_eq!(named_app(&prep("Zoomed-out review")), None, "a word, not a part of one");
        assert!(same_person("Sara Khan", "Sara") && same_person("Sara", "Sara Khan (Acme)"));
        assert!(!same_person("Sara Khan", "Omar Ali"));
        assert_eq!(offer_message(&armed("p1", "Weekly", "", "2026-12-01T10:00:00Z"))["question"], "Start Live Assist for this call?");
        assert!(!format!("{:?}", armed("p1", "SECRET title", "SECRET person", "2026-12-01T10:00:00Z")).contains("SECRET"));
    }

    #[test]
    fn the_end_stops_only_the_recording_the_session_started() {
        assert_eq!(at_end(Some("rec-1"), Some("rec-1"), false), AtEnd { stop_recording: true, captions_off: false }, "started by Live Assist");
        assert_eq!(at_end(None, Some("rec-0"), true), AtEnd { stop_recording: false, captions_off: false }, "the person's recording, captions on before: all goes on");
        assert_eq!(at_end(None, Some("rec-0"), false), AtEnd { stop_recording: false, captions_off: true }, "the person's recording goes on; captions go back off");
        assert!(!at_end(Some("rec-1"), Some("rec-2"), true).stop_recording, "a later recording is not the session's");
        assert_eq!(at_end(Some("rec-1"), None, false), AtEnd { stop_recording: false, captions_off: false }, "nothing runs");
    }

    #[test]
    fn a_running_session_ends_when_live_assist_turns_off() {
        let on = crate::ui_flags::UiFlags::read(Some(&json!({"live_assist": true})), false);
        let off = crate::ui_flags::UiFlags::read(Some(&json!({"live_assist": false})), false);
        assert!(ends_with_flags(&off, true));
        assert!(!ends_with_flags(&on, true), "still on: it runs");
        assert!(!ends_with_flags(&off, false), "nothing runs");
    }

    #[test]
    fn only_the_server_s_ids_go_into_paths() {
        assert!(valid_id("01JB8Q3R0Z5V1K7M9N2P4S6T8W") && valid_id("s-1_a"));
        for bad in ["", "../config", "a/b", "a?b", &"x".repeat(65)] {
            assert!(!valid_id(bad), "{bad}");
        }
    }
}
