//! Live Assist (v1.9 Phase L, docs/API_ASSIST.md 1.9.0-e): text-only help on screen while the other person talks. The
//! relay's messages ([`protocol`]), what waits for the relay ([`outbox`]) and the WebSocket client ([`client`]). Nothing
//! here shows or starts anything by itself; a session starts only while the server's `live_assist` flag is on for this
//! person, and one that runs stops when the flag turns off ([`Registry::stop_unless_enabled`]).

use std::sync::Mutex;

pub mod client;
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
/// connection and its turns.
#[derive(Default)]
pub struct Registry(Mutex<Option<Running>>);

struct Running {
    id: String,
    handle: client::AssistHandle,
    turns: turns::TurnFeeder,
}

impl Registry {
    fn lock(&self) -> std::sync::MutexGuard<'_, Option<Running>> {
        self.0.lock().unwrap_or_else(|p| p.into_inner())
    }

    pub fn set(&self, session_id: String, handle: client::AssistHandle) {
        if let Some(old) = self.lock().replace(Running { id: session_id, handle, turns: turns::TurnFeeder::default() }) {
            old.handle.stop();
        }
    }

    /// The running session's id and connection.
    pub fn current(&self) -> Option<(String, client::AssistHandle)> {
        self.lock().as_ref().filter(|r| r.handle.is_running()).map(|r| (r.id.clone(), r.handle.clone()))
    }

    /// Stops the session's connection and forgets it; its id when one ran (the server is told by the caller).
    pub fn take(&self) -> Option<String> {
        self.lock().take().map(|running| {
            running.handle.stop();
            running.id
        })
    }

    /// A live captions partial, to the running session: the other person's only ([`turns`]). True when sent.
    pub fn heard_partial(&self, channel: crate::audio::types::Channel, text: &str) -> bool {
        let mut running = self.lock();
        let Some(running) = running.as_mut().filter(|r| r.handle.is_running()) else { return false };
        running.turns.partial(channel, text, std::time::Instant::now()).is_some_and(|m| running.handle.send(m))
    }

    /// A live captions final turn, on the recording's clock, to the running session (the microphone's as context).
    pub fn heard_final(&self, channel: crate::audio::types::Channel, text: &str, start_ms: u64, end_ms: u64) -> bool {
        let mut running = self.lock();
        let Some(running) = running.as_mut().filter(|r| r.handle.is_running()) else { return false };
        running.turns.finished(channel, text, start_ms, end_ms).is_some_and(|m| running.handle.send(m))
    }

    /// Stops the session and forgets it; true when one ran.
    pub fn stop(&self) -> bool {
        self.take().is_some()
    }

    /// Stops a running session when the `live_assist` flag is off (the server turned it off for this person); true
    /// when one was stopped.
    pub fn stop_unless_enabled(&self, flags: &crate::ui_flags::UiFlags) -> bool {
        !enabled(flags) && self.stop()
    }
}
