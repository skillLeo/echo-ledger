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

/// Honorifics and titles that say nothing about who someone is.
const HONORIFICS: [&str; 22] = [
    "mr", "mrs", "ms", "miss", "mx", "dr", "sir", "madam", "madame", "prof", "professor", "engr", "eng", "sahib", "sahab", "saab", "sb", "mian", "janab", "begum", "hafiz", "haji",
];

/// The name words of a name (pure): no honorifics or titles, only words of 3 letters or more.
pub fn name_words(name: &str) -> Vec<String> {
    words(name).into_iter().map(|w| w.trim_end_matches('.').to_string()).filter(|w| w.chars().count() >= 3 && !HONORIFICS.contains(&w.as_str())).collect()
}

/// The caller is the prep's person (pure): the prep's name word is the caller's; with two name words or more, all of
/// them (the full name), so a shared family or company word is never enough.
pub fn same_person(person: &str, caller: &str) -> bool {
    let (wanted, heard) = (name_words(person), name_words(caller));
    !wanted.is_empty() && wanted.iter().all(|w| heard.contains(w))
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

/// What the armed preps do with a recording that started (pure). Live Assist never starts by itself: a call that
/// may be a prep's meeting gets the card's question; matching only chooses which prep it offers.
#[derive(Clone, Debug, PartialEq, Eq)]
pub enum Arming {
    /// No prep's meeting: a voice note, or a call that began before any prep was armed or after its time.
    Nothing,
    /// The card asks about this prep.
    Offer(String),
    /// A prep names a person and the caller is not known yet: asked again in a moment, to offer the right prep.
    Wait,
}

/// A recording a prep's window holds: a call that started while it was armed.
fn in_window(armed: &Armed, call: &CallStart<'_>) -> bool {
    call.mode == crate::audio::RecordingMode::Call && call.started_at >= armed.armed_at && call.started_at <= armed.until
}

/// How well a prep matches the call (pure): its app and its person count; one that does not match counts against it.
fn score(armed: &Armed, call: &CallStart<'_>) -> i32 {
    let app = armed.app.map_or(0, |a| if same_app(a, call.source_app) { 2 } else { -2 });
    let person = match (armed.person.as_deref(), call.caller) {
        (Some(p), Some(c)) => {
            if same_person(p, c) { 3 } else { -3 }
        }
        _ => 0,
    };
    app + person
}

/// Which prep the card offers for a recording that started (pure): the best match among the preps whose window holds
/// it, the one scheduled nearest the call on a tie. While a prep names a person and the caller is not known, it waits
/// (`late` ends the wait: the offer goes with what is known).
pub fn decide(armed: &[Armed], call: &CallStart<'_>, late: bool) -> Arming {
    let candidates: Vec<&Armed> = armed.iter().filter(|a| in_window(a, call)).collect();
    if candidates.is_empty() {
        return Arming::Nothing;
    }
    if !late && call.caller.is_none() && candidates.iter().any(|a| a.person.is_some()) {
        return Arming::Wait;
    }
    let best = candidates.iter().max_by_key(|a| (score(a, call), -(a.at - call.started_at).num_seconds().abs()));
    best.map_or(Arming::Nothing, |a| Arming::Offer(a.prep_id.clone()))
}

/// The card's question for a prep (it starts nothing by itself): "Start Live Assist for <prep title>?", naming the
/// caller when known, with the recording it is about.
pub fn offer_message(armed: &Armed, caller: Option<&str>, client_ref: &str) -> serde_json::Value {
    let title = armed.title.trim();
    let question = if title.is_empty() { "Start Live Assist for this call?".to_string() } else { format!("Start Live Assist for {title}?") };
    serde_json::json!({"type": "offer", "prep_id": armed.prep_id, "title": armed.title, "caller": caller, "question": question, "client_ref": client_ref})
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

/// The language a `translate` asks for (pure): the prep's language help; when it is off, Urdu if the app's language
/// (the recording's, else Settings' transcription language) is Urdu, otherwise Roman Urdu.
pub fn translate_language(language_help: &str, app_language: Option<&str>) -> &'static str {
    match language_help {
        "urdu" => "urdu",
        "arabic" => "arabic",
        "roman_urdu" => "roman_urdu",
        _ if app_language == Some("ur") => "urdu",
        _ => "roman_urdu",
    }
}

/// The relay message for a button on the card (pure): `help_now`, `regenerate`, `shorter`, `formal`, `translate`
/// (latest suggestion, into `translate_to`) or `cancel` (every shown one).
pub fn send_message(kind: &str, translate_to: &str) -> Option<ClientMessage> {
    Some(match kind {
        "help_now" => ClientMessage::HelpNow { turn_id: None },
        "regenerate" => ClientMessage::Regenerate { suggestion_id: None },
        "shorter" => ClientMessage::Shorter { suggestion_id: None },
        "formal" => ClientMessage::Formal { suggestion_id: None },
        "translate" => ClientMessage::Translate { suggestion_id: None, language: translate_to.into() },
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
    // `context_update` replaces (1.9.0-n): the settings go whole, every field, every time.
    let settings = Settings {
        mode: Some(mode.to_string()),
        hint_level: Some(prep.map_or("outline", |p| p.hint_level.as_str()).to_string()),
        language_help: Some(prep.map_or("off", |p| p.language_help.as_str()).to_string()),
        assist: Some("on".into()),
    };
    // The documents' extracted text (contract 1.9.0-m), within the relay's limits.
    let documents = prep.map(|p| documents_within_limits(&p.documents)).unwrap_or_default();
    ContextUpdate { brief, settings: Some(settings), documents: (!documents.is_empty()).then_some(documents), ..ContextUpdate::default() }
}

/// The relay's limits on documents (contract 1.9.0-n): how many, the characters of each, the tokens of all.
pub const MAX_DOCUMENTS: usize = 20;
pub const MAX_DOCUMENT_CHARS: usize = 400_000;
pub const MAX_DOCUMENT_TOKENS: u64 = 60_000;

/// The prep's documents that have text, as the relay takes them (pure): at most [`MAX_DOCUMENTS`], each cut to
/// [`MAX_DOCUMENT_CHARS`], and only while all together stay within about [`MAX_DOCUMENT_TOKENS`] (the server's count,
/// else 4 characters a token), so `documents_too_large` is not met in the ordinary case.
pub fn documents_within_limits(documents: &[crate::net::models::PrepDocument]) -> Vec<Document> {
    let mut tokens = 0_u64;
    let mut kept = Vec::new();
    for document in documents {
        let Some(text) = document.text.as_deref().filter(|t| !t.trim().is_empty()) else { continue };
        if kept.len() == MAX_DOCUMENTS {
            break;
        }
        let cut = text.char_indices().nth(MAX_DOCUMENT_CHARS).map_or(text, |(at, _)| &text[..at]);
        // The server's count (for a cut text, its share of it), else about 4 characters a token.
        let cost = match document.tokens {
            Some(all) if cut.len() < text.len() => (all as u128 * cut.len() as u128 / text.len().max(1) as u128) as u64,
            Some(all) => all,
            None => cut.chars().count().div_ceil(4) as u64,
        };
        if tokens + cost > MAX_DOCUMENT_TOKENS {
            continue;
        }
        tokens += cost;
        kept.push(Document { id: document.file_id.clone(), title: document.name.clone(), text: cut.to_string() });
    }
    kept
}

/// `assist_set`'s settings from the card.
#[derive(Clone, Debug, Default, Deserialize)]
pub struct CardSettings {
    pub mode: Option<String>,
    pub hint_level: Option<String>,
    pub paused: Option<bool>,
    pub muted_seconds: Option<u64>,
}

/// The whole `context_update.settings` after a change on the card (pure): `context_update` replaces, so every field
/// goes each time; `assist` is `paused` while paused or muted.
pub fn settings_update(current: &Settings, change: &CardSettings, quiet: bool) -> Settings {
    Settings {
        mode: change.mode.clone().or_else(|| current.mode.clone()),
        hint_level: change.hint_level.clone().or_else(|| current.hint_level.clone()),
        language_help: current.language_help.clone(),
        assist: Some(if quiet { "paused" } else { "on" }.into()),
    }
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
/// `client_ref`: the recording an offer was about ("Start Live Assist for <prep>?"); the start is refused when that
/// recording is no longer the one running.
pub async fn start(app: &AppHandle, prep_id: Option<String>, mode: Option<String>, practice: bool, client_ref: Option<String>) -> AppResult<AssistStarted> {
    let state = app.state::<crate::AppState>();
    let running = state.capture.info().map(|i| i.client_ref);
    if !offer_still_current(client_ref.as_deref(), running.as_deref()) {
        return Err(AppError::msg(OFFER_GONE));
    }
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
    // A start answered `session_active` left what it turned on to this one ("End it and start this one").
    let earlier = state.assist.pending().take();
    let mut fresh = Attempt::default();
    let recording_session_id = if practice {
        None
    } else {
        match recording_session(app, &mut fresh).await {
            Ok(id) => Some(id),
            Err(e) => {
                let running = state.capture.info().map(|i| i.client_ref);
                undo(app, &carried(earlier.as_ref(), &fresh, running.as_deref())).await;
                return Err(e);
            }
        }
    };
    let running = state.capture.info().map(|i| i.client_ref);
    let attempt = carried(earlier.as_ref(), &fresh, running.as_deref());
    let captions_were_on = !attempt.captions_turned_on;
    let started_recording = attempt.started_recording.clone();
    let app_language = state.capture.info().map_or_else(|| state.settings().transcription_language.clone(), |i| state.recording_language(&i.client_ref));
    let request = AssistStartRequest { prep_id: prep.as_ref().map(|p| p.id.clone()), mode: Some(mode.clone()), platform: "desktop".into(), recording_session_id, practice, transports: AssistStartRequest::transports() };
    let pins = super::relay_pins(state.server_config.lock().unwrap_or_else(|p| p.into_inner()).as_ref());
    let meeting_type = prep.as_ref().map(|p| p.meeting_type.clone());
    // What the relay says before the session is in the registry (its `ready` can be that quick) waits here.
    let early: Arc<std::sync::Mutex<Early>> = Arc::default();
    let (events, events_app, meeting) = (early.clone(), app.clone(), meeting_type.clone());
    // A token refresh's plan: the time left counts from there.
    let plan_app = app.clone();
    let on_plan: session::OnPlan = Arc::new(move |plan| {
        let state = plan_app.state::<crate::AppState>();
        let message = state.assist.with_current(|r| {
            r.budget = r.budget.renewed(&plan, Instant::now());
            let message = r.budget.message(Instant::now());
            r.card.record(&message);
            message
        });
        if let Some(message) = message {
            emit(&plan_app, &message);
        }
    });
    let answer = match api.assist_start_or_active(&request).await {
        Ok(Ok(answer)) => answer,
        Ok(Err(active)) => {
            // Another session of this person is active: the card offers to end it and start this one. What this start
            // turned on waits for the answer: the next start owns it, "Keep running" undoes it.
            *state.assist.pending() = Some(attempt);
            let this_device = state.settings().device_id.clone();
            let message = active_message(&active, this_device.as_deref());
            crate::lifecycle::show(app, WINDOW, "assist");
            emit(app, &message);
            return Err(AppError::msg(message["text"].as_str().unwrap_or_default()));
        }
        Err(e) => {
            undo(app, &attempt).await;
            return Err(e);
        }
    };
    let handle = session::connect(api, &answer, pins, on_plan, move |event| {
        let mut early = events.lock().unwrap_or_else(|p| p.into_inner());
        match early.id.clone() {
            Some(id) => on_event(&events_app, &id, meeting.as_deref(), event),
            None => early.waiting.push(event),
        }
    });
    let started = AssistStarted::from_answer(&answer);
    let plan = answer.plan.clone().unwrap_or_default();
    let budget = Budget { left_at_start: plan.seconds_left, total: plan.seconds_total, started: Instant::now() };
    let context = first_context(prep.as_ref(), &mode);
    let settings = context.settings.clone().unwrap_or_default();
    handle.send(ClientMessage::ContextUpdate(Box::new(context)));
    state.assist.begin(Running {
        id: started.session_id.clone(),
        handle,
        turns: TurnFeeder::default(),
        card: Card::new(meeting_type.clone(), prep.as_ref().map(|p| p.hint_level.clone())),
        batch: TurnBatch::new(Instant::now()),
        budget,
        translate_to: translate_language(prep.as_ref().map_or("off", |p| p.language_help.as_str()), app_language.as_deref()).into(),
        started_recording,
        captions_were_on,
        settings,
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

/// The card's offer when another session of this person is active (pure): what it says, and the action that ends it
/// and starts this one (`assist_start_replacing` with `session_id`).
pub fn active_message(active: &crate::net::models::ActiveAssist, this_device: Option<&str>) -> serde_json::Value {
    let here = this_device.is_some() && active.device_id.as_deref() == this_device;
    let what = if active.practice { "A Live Assist practice" } else { "Live Assist" };
    let text = format!("{what} is already running for you {}.", if here { "on this computer" } else { "on another device" });
    serde_json::json!({"type": "session_active", "session_id": active.session_id, "practice": active.practice, "text": text, "action": "End it and start this one"})
}

/// "End it and start this one": the active session ends, then this start is tried once more.
pub async fn start_replacing(app: &AppHandle, active_id: &str, prep_id: Option<String>, mode: Option<String>, practice: bool, client_ref: Option<String>) -> AppResult<AssistStarted> {
    let api = app.state::<crate::AppState>().api()?;
    let active = checked(active_id)?.to_string();
    session::end_then_start(&active, |id| async move { api.assist_end(&id).await }, || start(app, prep_id, mode, practice, client_ref)).await
}

/// An offer's call has ended (or another one runs).
pub const OFFER_GONE: &str = "That call has ended, so Live Assist didn't start.";

/// An offer is still about the recording that runs (pure); a start that names no recording always is.
pub fn offer_still_current(offered: Option<&str>, running: Option<&str>) -> bool {
    offered.is_none_or(|offered| running == Some(offered))
}

/// The relay's events of a session that is not in the registry yet.
#[derive(Default)]
struct Early {
    id: Option<String>,
    waiting: Vec<AssistEvent>,
}

/// The open server session of the recording the assist runs beside, and the recording this started (its
/// `client_ref`): a call recording is started (with captions, whose turns feed the relay) when none runs.
/// `attempt` notes what this start turned on as it goes, so a start that fails can undo it.
async fn recording_session(app: &AppHandle, attempt: &mut Attempt) -> AppResult<String> {
    let state = app.state::<crate::AppState>();
    if !state.capture.is_recording() {
        let source = state.detect.meeting().map_or("other", |m| m.app.as_str());
        let captions_before = state.live.is_live();
        crate::actions::start_call_recording_with(app, source, false, true).await?;
        attempt.started_recording = state.capture.info().map(|i| i.client_ref);
        attempt.captions_turned_on |= !captions_before && state.live.is_live();
    }
    if !state.live.is_live() {
        crate::actions::start_captions(app).await?;
        attempt.captions_turned_on = true;
    }
    let info = state.capture.info().ok_or_else(|| AppError::msg("The recording ended before Live Assist could start."))?;
    crate::actions::ensure_server_session(app, &info).await
}

/// What a start turned on before the server answered: the recording it started (its `client_ref`) and whether it
/// turned captions on. A start that fails undoes them; one answered `session_active` keeps them while the card asks,
/// and the start that follows owns them.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct Attempt {
    pub started_recording: Option<String>,
    pub captions_turned_on: bool,
}

/// What undoing an attempt does (pure): the person declined, so the recording it started is discarded (the pill's
/// Discard: its files, its screens and its open server session go; nothing is saved or uploaded) and its captions with
/// it; captions it turned on for the person's own recording go off, that recording untouched; nothing when that
/// recording has ended or another runs.
#[derive(Debug, PartialEq, Eq)]
pub enum Undo {
    Discard,
    CaptionsOff,
    Nothing,
}

pub fn undo_for(attempt: &Attempt, running: Option<&str>) -> Undo {
    match running {
        Some(running) if attempt.started_recording.as_deref() == Some(running) => Undo::Discard,
        Some(_) if attempt.captions_turned_on && attempt.started_recording.is_none() => Undo::CaptionsOff,
        _ => Undo::Nothing,
    }
}

/// The attempt a start carries on from (pure): what an earlier attempt answered `session_active` turned on, while its
/// recording is still the one running, joined with what this start turned on.
pub fn carried(earlier: Option<&Attempt>, now: &Attempt, running: Option<&str>) -> Attempt {
    let earlier = earlier.filter(|e| e.started_recording.is_none() || e.started_recording.as_deref() == running);
    Attempt {
        started_recording: now.started_recording.clone().or_else(|| earlier.and_then(|e| e.started_recording.clone())),
        captions_turned_on: now.captions_turned_on || earlier.is_some_and(|e| e.captions_turned_on),
    }
}

/// Undoes what an attempt turned on.
async fn undo(app: &AppHandle, attempt: &Attempt) {
    let state = app.state::<crate::AppState>();
    let running = state.capture.info().map(|i| i.client_ref);
    match undo_for(attempt, running.as_deref()) {
        // The recording it started, by name: one that took its place since (the person stopped it and started their
        // own) is left alone.
        Undo::Discard => {
            if let Err(e) = crate::actions::cancel_recording(app, attempt.started_recording.as_deref()).await {
                eprintln!("[echoledger] discarding the recording Live Assist started: {}", e.code().unwrap_or("error"));
            }
        }
        Undo::CaptionsOff => crate::actions::stop_captions(app),
        Undo::Nothing => {}
    }
}

/// "Keep running" on the `session_active` card (or the card closed): what the waiting start turned on is undone.
pub async fn keep_running(app: &AppHandle) {
    let pending = app.state::<crate::AppState>().assist.pending().take();
    if let Some(attempt) = pending {
        undo(app, &attempt).await;
    }
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

/// Ends Live Assist without sending the turns it hasn't sent yet: a call a contact's Don't record stopped (contract
/// 2.0.0-w, decision 25), so nothing more of it goes anywhere. The card closes; the recording is left to what stopped it.
pub async fn end_without_last_turns(app: &AppHandle) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    let Some(taken) = state.assist.take() else { return Ok(()) };
    let ended = match state.api()?.assist_end(&taken.id).await {
        Err(e) if e.code() == Some("session_ended") => Ok(()),
        other => other,
    };
    emit(app, &card::ended_message("closed", None));
    crate::lifecycle::hide(app, WINDOW, "assist");
    ended
}

/// A button on the card.
pub fn send(app: &AppHandle, kind: &str) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    let message = state
        .assist
        .with_current(|r| send_message(kind, &r.translate_to).map(|m| r.handle.send(m)))
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
            let settings = settings_update(&r.settings, &change, r.card.quiet(now));
            r.settings = settings.clone();
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
                    let settings = Settings { assist: Some("on".into()), ..r.settings.clone() };
                    r.settings = settings.clone();
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

/// The card drew a suggestion's first text `render_ms` after its `suggestion_start` (the "shown" report).
pub async fn shown(app: &AppHandle, suggestion_id: &str, render_ms: u64) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    let id = state.assist.session_id().ok_or_else(|| AppError::msg("Live Assist isn't running."))?;
    state.api()?.assist_suggestion_shown(&id, checked(suggestion_id)?, render_ms.min(600_000)).await
}

/// The card's state for a window opened mid-session; none when no session runs.
pub fn snapshot(app: &AppHandle) -> Option<Snapshot> {
    app.state::<crate::AppState>().assist.with_current(|r| r.card.snapshot(Utc::now()))
}

/// The card hides; the session goes on.
pub fn hide_card(app: &AppHandle) {
    crate::lifecycle::hide(app, WINDOW, "assist");
    // Closing the card while it asked about another session is "Keep running".
    if app.state::<crate::AppState>().assist.pending().is_some() {
        let app = app.clone();
        tauri::async_runtime::spawn(async move { keep_running(&app).await });
    }
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

/// How long the offer waits for the caller's name when a prep names a person.
const CALLER_WAIT: Duration = Duration::from_secs(10);

/// A recording started: when it may be an armed prep's meeting, the card asks "Start Live Assist for <prep>?". Live
/// Assist never starts by itself; never for a voice note or a call that was running before the prep was armed.
pub fn on_recording_started(app: &AppHandle, info: &crate::audio::RecordingInfo) {
    if app.state::<crate::AppState>().assist.armed().is_empty() {
        return;
    }
    let (app, info) = (app.clone(), info.clone());
    tauri::async_runtime::spawn(async move {
        let late_at = Instant::now() + CALLER_WAIT;
        loop {
            let state = app.state::<crate::AppState>();
            if state.assist.session_id().is_some() || state.capture.info().map(|i| i.client_ref) != Some(info.client_ref.clone()) {
                return;
            }
            let caller = state.recording_caller.lock().unwrap_or_else(|p| p.into_inner()).as_ref().filter(|(r, _)| *r == info.client_ref).and_then(|(_, c)| c.name.clone());
            let call = CallStart { mode: info.mode, started_at: info.started_at, source_app: &info.source_app, caller: caller.as_deref() };
            let (decision, offer) = {
                let armed = state.assist.armed();
                let decision = decide(&armed, &call, Instant::now() >= late_at);
                let offer = match &decision {
                    Arming::Offer(id) => armed.iter().find(|a| &a.prep_id == id).map(|a| offer_message(a, caller.as_deref(), &info.client_ref)),
                    _ => None,
                };
                (decision, offer)
            };
            match decision {
                Arming::Nothing => return,
                Arming::Wait => tokio::time::sleep(Duration::from_secs(2)).await,
                Arming::Offer(_) => {
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
        assert_eq!(frame(send_message("help_now", "roman_urdu").unwrap()), json!({"type": "help_now", "seq": 1}));
        for kind in ["regenerate", "shorter", "formal", "cancel"] {
            assert_eq!(frame(send_message(kind, "roman_urdu").unwrap()), json!({"type": kind, "seq": 1}), "the latest suggestion");
        }
        assert_eq!(frame(send_message("translate", "arabic").unwrap())["language"], "arabic");
        assert!(send_message("token_refresh", "urdu").is_none(), "only the card's buttons");
        assert_eq!(translate_language("arabic", Some("ur")), "arabic", "the prep's language help first");
        assert_eq!(translate_language("roman_urdu", Some("ur")), "roman_urdu");
        assert_eq!(translate_language("off", Some("ur")), "urdu", "off, and the app speaks Urdu");
        assert_eq!(translate_language("off", Some("en")), "roman_urdu");
        assert_eq!(translate_language("off", None), "roman_urdu");
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
        assert_eq!(bare, json!({"settings": {"mode": "fast", "hint_level": "outline", "language_help": "off", "assist": "on"}}), "Start now without a prep: every field");
        // `context_update` replaces (1.9.0-n): a change sends the whole settings, never a delta.
        let current = first_context(Some(&prep), "balanced").settings.unwrap();
        let muted = settings_update(&current, &CardSettings { muted_seconds: Some(60), ..CardSettings::default() }, true);
        assert_eq!(serde_json::to_value(muted).unwrap(), json!({"mode": "balanced", "hint_level": "full", "language_help": "urdu", "assist": "paused"}));
        let level = settings_update(&current, &CardSettings { hint_level: Some("keywords".into()), mode: Some("fast".into()), ..CardSettings::default() }, false);
        assert_eq!(serde_json::to_value(level).unwrap(), json!({"mode": "fast", "hint_level": "keywords", "language_help": "urdu", "assist": "on"}));
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
    fn a_prep_is_only_ever_offered_for_a_call_that_starts_inside_its_window() {
        let zoom_with_imran = [armed("p1", "Interview on Zoom", "Imran Khan", "2026-12-01T10:00:00Z")];
        let call_at = |at| call(at, "zoom", Some("Imran Khan"));
        assert_eq!(decide(&zoom_with_imran, &call_at("2026-12-01T10:01:00Z"), false), Arming::Offer("p1".into()), "a match is offered, never started");
        // A call already running when the prep was armed, or after its time, is never offered.
        assert_eq!(decide(&zoom_with_imran, &call_at("2026-12-01T09:50:00Z"), true), Arming::Nothing);
        assert_eq!(decide(&zoom_with_imran, &call_at("2026-12-01T10:11:00Z"), true), Arming::Nothing);
        // A voice note is never a meeting.
        let note = CallStart { mode: crate::audio::RecordingMode::VoiceNote, ..call("2026-12-01T10:01:00Z", "voice_note", None) };
        assert_eq!(decide(&zoom_with_imran, &note, true), Arming::Nothing);
        // Another app or another person: still only a question.
        assert_eq!(decide(&zoom_with_imran, &call("2026-12-01T10:01:00Z", "google_meet", Some("Sana")), true), Arming::Offer("p1".into()));
        // The prep names a person and the caller is not known yet: it waits, then asks with what it knows.
        assert_eq!(decide(&zoom_with_imran, &call("2026-12-01T10:01:00Z", "zoom", None), false), Arming::Wait);
        assert_eq!(decide(&zoom_with_imran, &call("2026-12-01T10:01:00Z", "zoom", None), true), Arming::Offer("p1".into()));
        // A prep naming only the app: offered at once, there is no person to wait for.
        let app_only = [armed("p3", "Call on WhatsApp", "", "2026-12-01T10:00:00Z")];
        assert_eq!(decide(&app_only, &call("2026-12-01T10:00:30Z", "whatsapp_business", None), false), Arming::Offer("p3".into()));
    }

    #[test]
    fn matching_chooses_which_prep_is_offered() {
        // Two preps close together: the one whose person called is offered, whichever is nearer.
        let two = [armed("p1", "With Imran", "Dr Imran", "2026-12-01T10:00:00Z"), armed("p2", "With Sana", "Dr Sana", "2026-12-01T10:05:00Z")];
        assert_eq!(decide(&two, &call("2026-12-01T10:04:00Z", "zoom", Some("Dr Imran")), true), Arming::Offer("p1".into()));
        assert_eq!(decide(&two, &call("2026-12-01T10:04:00Z", "zoom", Some("Dr Sana")), true), Arming::Offer("p2".into()));
        // Nothing tells them apart: the nearer one.
        assert_eq!(decide(&two, &call("2026-12-01T10:04:00Z", "zoom", Some("Omar")), true), Arming::Offer("p2".into()));
        // The app ranks too.
        let apps = [armed("p1", "On Zoom", "", "2026-12-01T10:00:00Z"), armed("p2", "On Google Meet", "", "2026-12-01T10:05:00Z")];
        assert_eq!(decide(&apps, &call("2026-12-01T10:04:00Z", "zoom", None), false), Arming::Offer("p1".into()));
    }

    #[test]
    fn an_offer_starts_only_while_its_call_is_the_one_running() {
        assert!(offer_still_current(Some("rec-1"), Some("rec-1")));
        assert!(!offer_still_current(Some("rec-1"), Some("rec-2")), "another call runs now");
        assert!(!offer_still_current(Some("rec-1"), None), "the call ended");
        assert!(offer_still_current(None, None) && offer_still_current(None, Some("rec-2")), "Start now names no recording");
    }

    #[test]
    fn people_match_on_their_name_words_never_on_titles_or_a_shared_word() {
        assert!(!same_person("Dr Imran", "Dr Sana"), "a shared title is not a match");
        assert!(same_person("Dr Imran", "Imran"));
        assert!(!same_person("Imran Khan", "Khan Traders"), "two name words: the full name");
        assert!(same_person("Imran Khan", "Imran Khan (Acme)"));
        assert!(!same_person("Imran Khan", "Imran"), "half of a full name is not enough");
        assert!(same_person("Mian Sahib Tariq", "Tariq"), "honorifics are not name words");
        assert!(!same_person("Al", "Al"), "only words of 3 letters or more");
        assert!(!same_person("", "Imran"));
    }

    #[test]
    fn apps_and_people_are_read_from_the_prep_s_words() {
        let prep = |title: &str| Prep { id: "p".into(), title: title.into(), ..Prep::default() };
        assert_eq!(named_app(&prep("Interview on Google Meet")), Some("google_meet"));
        assert_eq!(named_app(&prep("Teams sync")), Some("microsoft_teams"));
        assert_eq!(named_app(&prep("Zoom or Teams")), None, "two apps: none for sure");
        assert_eq!(named_app(&prep("Zoomed-out review")), None, "a word, not a part of one");
        let offer = offer_message(&armed("p1", "Weekly sync", "", "2026-12-01T10:00:00Z"), Some("Sara"), "rec-7");
        assert_eq!(offer, json!({"type": "offer", "prep_id": "p1", "title": "Weekly sync", "caller": "Sara", "question": "Start Live Assist for Weekly sync?", "client_ref": "rec-7"}));
        assert_eq!(offer_message(&armed("p1", " ", "", "2026-12-01T10:00:00Z"), None, "rec-7")["question"], "Start Live Assist for this call?");
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
    fn a_start_answered_session_active_then_keep_running_leaves_no_recording() {
        // This start began rec-1 with its captions; the server said another session is active.
        let attempt = carried(None, &Attempt { started_recording: Some("rec-1".into()), captions_turned_on: true }, Some("rec-1"));
        assert_eq!(undo_for(&attempt, Some("rec-1")), Undo::Discard, "Keep running: the recording it started is discarded");
        // A start beside the person's own recording only turned captions on: they go off, the recording goes on.
        let beside = Attempt { started_recording: None, captions_turned_on: true };
        assert_eq!(undo_for(&beside, Some("rec-0")), Undo::CaptionsOff);
        assert_eq!(undo_for(&Attempt::default(), Some("rec-0")), Undo::Nothing, "it turned nothing on");
        assert_eq!(undo_for(&attempt, Some("rec-9")), Undo::Nothing, "another recording runs now");
        assert_eq!(undo_for(&attempt, None), Undo::Nothing, "it already stopped");
        // The discard itself names the recording it means, so one that took its place before it ran is left alone.
        assert!(crate::actions::discards(Some("rec-1"), Some("rec-1")));
        assert!(!crate::actions::discards(Some("rec-1"), Some("rec-2")), "the person's new recording stays");
        assert!(!crate::actions::discards(Some("rec-1"), None));
        assert!(crate::actions::discards(None, Some("rec-2")), "the pill's Discard: whatever runs");
    }

    #[test]
    fn end_it_and_start_this_one_owns_the_recording_so_the_end_stops_it() {
        let first = Attempt { started_recording: Some("rec-1".into()), captions_turned_on: true };
        // The retry starts nothing new: the recording runs already.
        let owned = carried(Some(&first), &Attempt::default(), Some("rec-1"));
        assert_eq!(owned, first, "the new session owns what the first attempt started");
        // Then assist_end: the recording stops; nothing is left running.
        assert_eq!(at_end(owned.started_recording.as_deref(), Some("rec-1"), !owned.captions_turned_on), AtEnd { stop_recording: true, captions_off: false });
        // A retry whose recording is gone carries nothing from it.
        assert_eq!(carried(Some(&first), &Attempt::default(), Some("rec-2")).started_recording, None);
        // The retry failing too undoes all of it.
        assert_eq!(undo_for(&owned, Some("rec-1")), Undo::Discard);
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
    fn documents_stay_within_the_relay_s_limits() {
        use crate::net::models::PrepDocument;
        let doc = |id: &str, text: &str, tokens: Option<u64>| PrepDocument { file_id: id.into(), name: format!("{id}.pdf"), tokens, text: Some(text.into()) };
        let many: Vec<PrepDocument> = (0..25).map(|i| doc(&format!("f{i}"), "short text", Some(3))).collect();
        assert_eq!(documents_within_limits(&many).len(), MAX_DOCUMENTS);
        let long = "a".repeat(MAX_DOCUMENT_CHARS + 10);
        let kept = documents_within_limits(&[doc("big", &long, Some(50_000))]);
        assert_eq!(kept[0].text.chars().count(), MAX_DOCUMENT_CHARS, "cut to the relay's size, counted by its share of the tokens");
        assert!(documents_within_limits(&[doc("big", &long, None)]).is_empty(), "about 100,000 tokens without a count: too many");
        let heavy = [doc("a", "x", Some(40_000)), doc("b", "y", Some(30_000)), doc("c", "z", Some(15_000))];
        let ids: Vec<String> = documents_within_limits(&heavy).into_iter().map(|d| d.id).collect();
        assert_eq!(ids, ["a", "c"], "about 60,000 tokens in all: one that would pass it is left out");
        let words = [doc("u", "ب".repeat(8).as_str(), None)];
        assert_eq!(documents_within_limits(&words)[0].text.chars().count(), 8, "never cut inside a character");
        assert!(documents_within_limits(&[PrepDocument { text: Some("  ".into()), ..doc("e", "", None) }]).is_empty(), "no text: nothing to send");
    }

    #[test]
    fn an_active_session_is_offered_to_be_ended_and_this_one_started() {
        let active = crate::net::models::ActiveAssist { session_id: "01JCOLD".into(), practice: false, device_id: Some("dev-1".into()) };
        let here = active_message(&active, Some("dev-1"));
        assert_eq!(here, json!({"type": "session_active", "session_id": "01JCOLD", "practice": false, "text": "Live Assist is already running for you on this computer.", "action": "End it and start this one"}));
        assert_eq!(active_message(&active, Some("dev-2"))["text"], "Live Assist is already running for you on another device.");
        let practice = crate::net::models::ActiveAssist { practice: true, ..active };
        assert_eq!(active_message(&practice, None)["text"], "A Live Assist practice is already running for you on another device.");
    }

    #[tokio::test]
    async fn ending_the_active_session_then_starting_tries_the_start_once() {
        use std::sync::atomic::AtomicU32;
        let (ends, starts) = (Arc::new(AtomicU32::new(0)), Arc::new(AtomicU32::new(0)));
        let (e, s) = (ends.clone(), starts.clone());
        let started: AppResult<&str> = session::end_then_start("old", |id| async move {
            assert_eq!(id, "old");
            e.fetch_add(1, Ordering::SeqCst);
            Ok(())
        }, || async move {
            s.fetch_add(1, Ordering::SeqCst);
            Ok("new")
        })
        .await;
        assert_eq!(started.unwrap(), "new");
        assert_eq!((ends.load(Ordering::SeqCst), starts.load(Ordering::SeqCst)), (1, 1));
        // Already ended elsewhere: still started.
        let gone = AppError::Server { status: 409, message: "Ended".into(), code: Some("session_ended".into()), retry_after_secs: None };
        assert_eq!(session::end_then_start("old", |_| async move { Err(gone) }, || async { Ok(1) }).await.unwrap(), 1);
        // The end failed for another reason: nothing is started.
        let tried = Arc::new(AtomicU32::new(0));
        let t = tried.clone();
        let refused = AppError::Server { status: 403, message: "No".into(), code: Some("forbidden".into()), retry_after_secs: None };
        let result = session::end_then_start("old", |_| async move { Err(refused) }, || async move {
            t.fetch_add(1, Ordering::SeqCst);
            Ok(())
        })
        .await;
        assert!(result.is_err() && tried.load(Ordering::SeqCst) == 0);
        // A second 409 is the start's own error: tried once, never again.
        let again = AppError::Server { status: 409, message: "Active".into(), code: Some("session_active".into()), retry_after_secs: None };
        let tries = Arc::new(AtomicU32::new(0));
        let n = tries.clone();
        let second: AppResult<()> = session::end_then_start("old", |_| async { Ok(()) }, || async move {
            n.fetch_add(1, Ordering::SeqCst);
            Err(again)
        })
        .await;
        assert!(second.is_err());
        assert_eq!(tries.load(Ordering::SeqCst), 1);
    }

    #[test]
    fn only_the_server_s_ids_go_into_paths() {
        assert!(valid_id("01JB8Q3R0Z5V1K7M9N2P4S6T8W") && valid_id("s-1_a"));
        for bad in ["", "../config", "a/b", "a?b", &"x".repeat(65)] {
            assert!(!valid_id(bad), "{bad}");
        }
    }
}
