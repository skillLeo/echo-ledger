//! Live Assist (v1.9 Phase L, docs/API_ASSIST.md 1.9.0-g): text-only help on screen while the other person talks. The
//! relay's messages ([`protocol`]), what waits for the relay ([`outbox`]), the WebSocket client ([`client`]), the
//! server side of a session ([`session`]), the card's messages and the turns for the server ([`card`]), and the session
//! as the app runs it ([`live`]: start, end, the card window, scheduling). A session starts only while the server's
//! `live_assist` flag is on for this person, and one that runs stops when the flag turns off.

use std::sync::Mutex;

pub mod card;
pub mod client;
pub mod http;
pub mod live;
pub mod outbox;
pub mod protocol;
pub mod session;
pub mod turns;

/// The relay host's pins from the last config (`relay_pins`, contract 1.9.0-g), never the server's own `pins`; empty
/// before the first config or from a server without them, which refuses the connection.
pub fn relay_pins(config: Option<&crate::net::models::ServerConfig>) -> Vec<String> {
    config.map(|c| c.relay_pins.clone()).unwrap_or_default()
}

/// Whether Live Assist may run for this person: the server's `live_assist` flag (contract 2.0.0-a), never decided here.
pub fn enabled(flags: &crate::ui_flags::UiFlags) -> bool {
    flags.is_on("live_assist")
}

/// Starts a session's relay connection, only while `live_assist` is on; `None` otherwise.
pub fn start(
    flags: &crate::ui_flags::UiFlags,
    grant: client::RelayGrant,
    refresh: client::Refresh,
    on_event: impl Fn(client::AssistEvent) + Send + Sync + 'static,
) -> Option<client::AssistHandle> {
    enabled(flags).then(|| client::spawn(grant, refresh, client::Policy::default(), on_event))
}

/// The session that runs on this computer, if any (one at a time, as the server allows): its server id, its relay
/// connection, its turns, the card's side and the turns waiting for the server; and the prep armed for a meeting.
#[derive(Default)]
pub struct Registry {
    running: Mutex<Option<Running>>,
    armed: Mutex<Vec<live::Armed>>,
    /// A start answered `session_active`: what it turned on, waiting for "End it and start this one" or "Keep running".
    pending: Mutex<Option<live::Attempt>>,
}

/// One running session.
pub struct Running {
    pub id: String,
    pub handle: client::AssistHandle,
    pub turns: turns::TurnFeeder,
    pub card: card::Card,
    pub batch: card::TurnBatch,
    pub budget: card::Budget,
    /// What `translate` asks for (`urdu` | `roman_urdu` | `arabic`): the prep's language help, else from the app's
    /// language ([`live::translate_language`]).
    pub translate_to: String,
    /// The recording this session started (its `client_ref`); none when the person's own recording was running.
    pub started_recording: Option<String>,
    /// Live captions were on before the session turned them on.
    pub captions_were_on: bool,
    /// The settings the relay holds, whole: every change sends all of them (`context_update` replaces).
    pub settings: protocol::Settings,
}

/// What is left of a session that was taken out: its id and the turns that still wait for the server.
#[derive(Debug)]
pub struct Taken {
    pub id: String,
    pub rows: Vec<card::TurnRow>,
    pub started_recording: Option<String>,
    pub captions_were_on: bool,
}

/// What one caption line gives the app: the card's message, and whether a batch of turns should go now.
#[derive(Debug, Default)]
pub struct Line {
    pub card: Option<serde_json::Value>,
    pub flush: bool,
}

impl Registry {
    fn lock(&self) -> std::sync::MutexGuard<'_, Option<Running>> {
        self.running.lock().unwrap_or_else(|p| p.into_inner())
    }

    /// What a start answered `session_active` turned on, while the card asks.
    pub fn pending(&self) -> std::sync::MutexGuard<'_, Option<live::Attempt>> {
        self.pending.lock().unwrap_or_else(|p| p.into_inner())
    }

    /// The preps armed for their meetings, one entry per prep.
    pub fn armed(&self) -> std::sync::MutexGuard<'_, Vec<live::Armed>> {
        self.armed.lock().unwrap_or_else(|p| p.into_inner())
    }

    /// A new running session; one that ran before is stopped.
    pub fn begin(&self, running: Running) {
        if let Some(old) = self.lock().replace(running) {
            old.handle.stop();
        }
    }

    /// The running session's id and connection.
    pub fn current(&self) -> Option<(String, client::AssistHandle)> {
        self.lock().as_ref().filter(|r| r.handle.is_running()).map(|r| (r.id.clone(), r.handle.clone()))
    }

    /// The running session's id, also while its connection is down.
    pub fn session_id(&self) -> Option<String> {
        self.lock().as_ref().map(|r| r.id.clone())
    }

    /// Works on the running session `id` (none when another one runs, or none at all).
    pub fn with<T>(&self, id: &str, f: impl FnOnce(&mut Running) -> T) -> Option<T> {
        self.lock().as_mut().filter(|r| r.id == id).map(f)
    }

    /// Works on whichever session runs.
    pub fn with_current<T>(&self, f: impl FnOnce(&mut Running) -> T) -> Option<T> {
        self.lock().as_mut().map(f)
    }

    /// Stops the session's connection and forgets it: its id and the turns that still wait.
    pub fn take(&self) -> Option<Taken> {
        self.lock().take().map(|mut running| {
            running.handle.stop();
            let mut rows = Vec::new();
            while !running.batch.is_empty() {
                rows.extend(running.batch.take(std::time::Instant::now()));
            }
            Taken { id: running.id, rows, started_recording: running.started_recording, captions_were_on: running.captions_were_on }
        })
    }

    /// A live captions partial: the relay hears the other person's ([`turns`]); the card hears both.
    pub fn heard_partial(&self, channel: crate::audio::types::Channel, text: &str) -> Line {
        let mut running = self.lock();
        let Some(running) = running.as_mut() else { return Line::default() };
        let heard = running.turns.hear_partial(channel, text, std::time::Instant::now());
        if let Some(message) = heard.relay {
            running.handle.send(message);
        }
        let card = heard.card.map(|turn| card::turn_message(&turn));
        if let Some(message) = &card {
            running.card.record(message);
        }
        Line { card, flush: false }
    }

    /// A live captions final turn, on the recording's clock: to the relay (the microphone's as context), the card and
    /// the batch for the server.
    pub fn heard_final(&self, channel: crate::audio::types::Channel, text: &str, start_ms: u64, end_ms: u64) -> Line {
        let mut running = self.lock();
        let Some(running) = running.as_mut() else { return Line::default() };
        let heard = running.turns.hear_final(channel, text, start_ms, end_ms);
        if let Some(message) = heard.relay {
            running.handle.send(message);
        }
        let Some(turn) = heard.card else { return Line::default() };
        let flush = card::TurnRow::of(&running.id, &turn).is_some_and(|row| running.batch.push(row));
        let message = card::turn_message(&turn);
        running.card.record(&message);
        Line { card: Some(message), flush }
    }

    /// Stops the session and forgets it; true when one ran.
    pub fn stop(&self) -> bool {
        self.take().is_some()
    }
}
