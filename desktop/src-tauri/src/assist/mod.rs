//! Live Assist (v1.9 Phase L, docs/API_ASSIST.md 1.9.0-c): text-only help on screen while the other person talks. This
//! is the groundwork: the relay's messages ([`protocol`]) and the WebSocket client ([`client`]). Nothing here shows or
//! starts anything by itself, and a session starts only while the server's `live_assist` flag is on for this person.

pub mod client;
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
