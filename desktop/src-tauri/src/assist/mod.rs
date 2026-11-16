//! Live Assist (v1.9 Phase L, docs/API_ASSIST.md 1.9.0-e): text-only help on screen while the other person talks. The
//! relay's messages ([`protocol`]), what waits for the relay ([`outbox`]) and the WebSocket client ([`client`]). Nothing
//! here shows or starts anything by itself; a session starts only while the server's `live_assist` flag is on for this
//! person, and one that runs stops when the flag turns off ([`Registry::stop_unless_enabled`]).

use std::sync::Mutex;

pub mod client;
pub mod outbox;
pub mod protocol;

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

/// The session that runs on this computer, if any (one at a time, as the server allows).
#[derive(Default)]
pub struct Registry(Mutex<Option<client::AssistHandle>>);

impl Registry {
    pub fn set(&self, handle: client::AssistHandle) {
        if let Some(old) = self.0.lock().unwrap_or_else(|p| p.into_inner()).replace(handle) {
            old.stop();
        }
    }

    pub fn current(&self) -> Option<client::AssistHandle> {
        self.0.lock().unwrap_or_else(|p| p.into_inner()).clone().filter(client::AssistHandle::is_running)
    }

    /// Stops the session and forgets it; true when one ran.
    pub fn stop(&self) -> bool {
        let handle = self.0.lock().unwrap_or_else(|p| p.into_inner()).take();
        handle.map(|h| h.stop()).is_some()
    }

    /// Stops a running session when the `live_assist` flag is off (the server turned it off for this person); true
    /// when one was stopped.
    pub fn stop_unless_enabled(&self, flags: &crate::ui_flags::UiFlags) -> bool {
        !enabled(flags) && self.stop()
    }
}
