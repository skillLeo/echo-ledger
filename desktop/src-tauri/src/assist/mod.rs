//! Live Assist (v1.9 Phase L, docs/API_ASSIST.md 1.9.0-e): text-only help on screen while the other person talks. The
//! relay's messages ([`protocol`]), what waits for the relay ([`outbox`]) and the WebSocket client ([`client`]). Nothing
//! here shows or starts anything by itself; a session starts only while the server's `live_assist` flag is on for this
//! person, and one that runs stops when the flag turns off ([`Registry::stop_unless_enabled`]).

use std::sync::Mutex;

pub mod client;
pub mod outbox;
pub mod protocol;
pub mod session;

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

/// The session that runs on this computer, if any (one at a time, as the server allows): its server id and its relay
/// connection.
#[derive(Default)]
pub struct Registry(Mutex<Option<(String, client::AssistHandle)>>);

impl Registry {
    pub fn set(&self, session_id: String, handle: client::AssistHandle) {
        if let Some((_, old)) = self.0.lock().unwrap_or_else(|p| p.into_inner()).replace((session_id, handle)) {
            old.stop();
        }
    }

    /// The running session's id and connection.
    pub fn current(&self) -> Option<(String, client::AssistHandle)> {
        self.0.lock().unwrap_or_else(|p| p.into_inner()).clone().filter(|(_, h)| h.is_running())
    }

    /// Stops the session's connection and forgets it; its id when one ran (the server is told by the caller).
    pub fn take(&self) -> Option<String> {
        let running = self.0.lock().unwrap_or_else(|p| p.into_inner()).take();
        running.map(|(id, handle)| {
            handle.stop();
            id
        })
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
