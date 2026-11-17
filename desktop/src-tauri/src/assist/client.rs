//! The WebSocket client to the Live Assist relay (docs/API_ASSIST.md 1.9.0-e, "Connecting" and "Suggestions"; close
//! codes and first messages as services/assist-relay sends them). One task per assist session:
//!
//! - connects to `relay.url` with the relay token as `Authorization: Bearer`, TCP_NODELAY on the socket (the relay's
//!   load test found Nagle's delay cost 20–30 ms a suggestion), over `wss://` with the relay's public key pinned like
//!   the server's (its key and a backup, `relay_pins` in `GET /api/config`, 1.9.0-g); no pins or another key refuse the
//!   connection in plain words, and recording goes on unaffected. Plain `ws://` only to this computer, in debug builds
//!   and tests;
//! - waits for `ready`, then sends what waits, paced to its `limits.messages_per_second`, with a `seq` per connection
//!   from 1 (the outbox, [`super::outbox`]); a `ready` with `resumed: false` after a context was given (the relay
//!   restarted and lost the session) gets the whole context again first: every `context_update` so far, merged;
//! - sends `token_refresh` with a new token 2 minutes before the current one expires (`refresh` fetches it from the
//!   server's `POST /api/assist/sessions/{id}/token`); when the relay rejects one, another is fetched at once the
//!   first time, then after the usual growing pauses, and the session ends once rejections have gone on for the
//!   resume grace (an accepted token or a new `ready` starts the count again);
//! - on a dropped connection reconnects with growing pauses inside the relay's 120 s resume grace; 4401 or a refused
//!   token gets a new token and reconnects at once the first time, then with the usual pauses; 4409 (a newer
//!   connection took over), 4403 (another person's session) and 1000 end it.

use std::sync::Arc;
use std::time::Duration;

use chrono::{DateTime, Utc};
use futures_util::future::BoxFuture;
use futures_util::{SinkExt, StreamExt};
use tokio::sync::{mpsc, watch};
use tokio::time::Instant;
use tokio_tungstenite::tungstenite::protocol::CloseFrame;
use tokio_tungstenite::tungstenite::protocol::frame::coding::CloseCode;
use tokio_tungstenite::tungstenite::{ClientRequestBuilder, Message, http::Uri};
use tokio_tungstenite::{Connector, MaybeTlsStream, WebSocketStream, connect_async_tls_with_config};

use super::outbox::{Outbox, gap};
use super::protocol::{ClientMessage, ServerMessage, parse};

pub type Socket = WebSocketStream<MaybeTlsStream<tokio::net::TcpStream>>;

/// The relay block of `POST /api/assist/sessions` and of each token refresh, with the relay's pins (`relay_pins` in
/// `GET /api/config`, contract 1.9.0-g: its key and a backup; never the server's `pins`).
#[derive(Clone, PartialEq, Eq)]
pub struct RelayGrant {
    pub url: String,
    pub token: String,
    pub expires_at: DateTime<Utc>,
    pub pins: Vec<String>,
}

impl std::fmt::Debug for RelayGrant {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("RelayGrant").field("url", &self.url).field("expires_at", &self.expires_at).finish_non_exhaustive()
    }
}

/// Fetches a new relay grant (the server's token refresh); the error is a plain sentence.
pub type Refresh = Arc<dyn Fn() -> BoxFuture<'static, Result<RelayGrant, String>> + Send + Sync>;

/// What the session tells the app.
#[derive(Clone, Debug, PartialEq)]
pub enum AssistEvent {
    /// The relay's `ready`: connected (again); `resumed` false means the context must be sent again.
    Connected { resumed: bool, mode: String },
    Message(ServerMessage),
    /// The connection dropped; the next try is after `after`.
    Reconnecting { attempt: u32, after: Duration, why: String },
    Ended(EndReason),
}

#[derive(Clone, Debug, PartialEq, Eq)]
pub enum EndReason {
    /// Stopped by the app, or closed with 1000.
    Closed,
    /// A newer connection for this session took over (4409).
    Replaced,
    /// The token names another person's session (4403, or 403 before the upgrade).
    WrongSession,
    /// The relay's key is not the pinned one, or no pins are known: [`crate::net::pinning::RELAY_INSECURE`].
    Insecure,
    /// No connection inside the resume grace, no new token, or an address this build does not connect to.
    GaveUp(String),
    /// The relay kept rejecting new tokens (`token_refresh_rejected`) for the whole resume grace.
    TokenRejected,
}

/// The relay's close codes (services/assist-relay `session::close`).
pub mod close {
    pub const NORMAL: u16 = 1000;
    pub const TOO_BIG: u16 = 1009;
    pub const RESTARTING: u16 = 1012;
    pub const TOKEN_EXPIRED: u16 = 4401;
    pub const WRONG_SESSION: u16 = 4403;
    pub const IDLE: u16 = 4408;
    pub const REPLACED: u16 = 4409;
}

/// What a close code means for this session (pure).
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum AfterClose {
    End(Ended),
    /// Reconnect after the usual pause.
    Reconnect,
    /// Reconnect with a new token first.
    RefreshThenReconnect,
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Ended {
    Closed,
    Replaced,
    WrongSession,
}

pub fn after_close(code: Option<u16>) -> AfterClose {
    match code {
        Some(close::NORMAL) => AfterClose::End(Ended::Closed),
        Some(close::REPLACED) => AfterClose::End(Ended::Replaced),
        Some(close::WRONG_SESSION) => AfterClose::End(Ended::WrongSession),
        Some(close::TOKEN_EXPIRED) => AfterClose::RefreshThenReconnect,
        // 1012 restarting, 4408 idle, 1009 too big, any other code, or no close frame at all (the network). Before the
        // upgrade, 503 relay_busy and 429 are retried the same way (ConnectError::Other).
        _ => AfterClose::Reconnect,
    }
}

/// The pauses and limits of one session.
#[derive(Clone, Debug)]
pub struct Policy {
    /// The pause before reconnect `n` (1-based); the last one repeats.
    pub backoff: Vec<Duration>,
    /// How long the session may stay without a connection before it gives up (the relay keeps it 120 s).
    pub give_up_after: Duration,
    /// How long before the token expires a new one is fetched and sent.
    pub refresh_early: Duration,
    /// The pause before trying a failed refresh again.
    pub refresh_retry: Duration,
}

impl Default for Policy {
    fn default() -> Self {
        Policy {
            backoff: [250, 500, 1_000, 2_000, 4_000, 8_000].into_iter().map(Duration::from_millis).collect(),
            give_up_after: Duration::from_secs(115),
            refresh_early: Duration::from_secs(120),
            refresh_retry: Duration::from_secs(10),
        }
    }
}

impl Policy {
    /// The pause before reconnect `attempt` (pure).
    pub fn pause(&self, attempt: u32) -> Duration {
        let index = (attempt.max(1) as usize - 1).min(self.backoff.len().saturating_sub(1));
        self.backoff.get(index).copied().unwrap_or(Duration::from_secs(1))
    }

    /// How long from now until the token should be refreshed (pure); zero when that time has passed.
    pub fn refresh_in(&self, expires_at: DateTime<Utc>, now: DateTime<Utc>) -> Duration {
        let early = chrono::Duration::from_std(self.refresh_early).unwrap_or_default();
        (expires_at - early - now).to_std().unwrap_or(Duration::ZERO)
    }
}

/// Why a connection could not be opened.
#[derive(Debug, PartialEq, Eq)]
pub enum ConnectError {
    /// `401` before the upgrade: the token is missing, invalid or expired.
    TokenRefused,
    /// `403` before the upgrade: another person's session.
    WrongSession,
    /// No pins, unusable pins, or a key that is not pinned: [`crate::net::pinning::RELAY_INSECURE`].
    Insecure,
    /// The URL is not one this build connects to.
    BadUrl(String),
    Other(String),
}

/// Whether a plain `ws://` address is allowed (pure): only to this computer, and only in debug builds and tests.
pub fn plain_allowed(host: Option<&str>, debug_build: bool) -> bool {
    debug_build && matches!(host, Some("localhost" | "127.0.0.1" | "[::1]" | "::1"))
}

/// Opens the socket: the token as `Authorization: Bearer`, TCP_NODELAY on, and for `wss://` the relay's pinned key.
pub async fn connect(grant: &RelayGrant) -> Result<Socket, ConnectError> {
    let uri: Uri = grant.url.parse().map_err(|_| ConnectError::BadUrl("The relay address is not valid.".into()))?;
    let connector = match uri.scheme_str() {
        Some("wss") => {
            let config = crate::net::pinning::relay_tls_config(&grant.pins).map_err(|_| ConnectError::Insecure)?;
            Connector::Rustls(Arc::new(config))
        }
        Some("ws") if plain_allowed(uri.host(), cfg!(debug_assertions)) => Connector::Plain,
        _ => return Err(ConnectError::BadUrl("The relay must be reached over wss://.".into())),
    };
    let tls = uri.scheme_str() == Some("wss");
    let request = ClientRequestBuilder::new(uri)
        .with_header("Authorization", format!("Bearer {}", grant.token))
        .with_header("User-Agent", concat!("EchoLedger-Desktop/", env!("CARGO_PKG_VERSION")));
    match connect_async_tls_with_config(request, None, true, Some(connector)).await {
        Ok((socket, _)) => Ok(socket),
        Err(tokio_tungstenite::tungstenite::Error::Http(response)) => match response.status().as_u16() {
            401 => Err(ConnectError::TokenRefused),
            403 => Err(ConnectError::WrongSession),
            status => Err(ConnectError::Other(format!("The relay answered {status}."))),
        },
        Err(e) if tls && is_certificate_error(&e) => Err(ConnectError::Insecure),
        Err(e) => Err(ConnectError::Other(e.to_string())),
    }
}

/// A TLS handshake refused for the relay's certificate or key (its chain, its name, or the pin).
fn is_certificate_error(error: &tokio_tungstenite::tungstenite::Error) -> bool {
    use tokio_tungstenite::tungstenite::Error;
    let rustls_error = match error {
        Error::Io(io) => io.get_ref().and_then(|inner| inner.downcast_ref::<rustls::Error>()).cloned(),
        Error::Tls(tls) => {
            let text = tls.to_string();
            return text.contains("certificate") || text.contains("Certificate");
        }
        _ => None,
    };
    matches!(rustls_error, Some(rustls::Error::InvalidCertificate(_)))
}

/// Whether the socket has TCP_NODELAY set (the relay's latency depends on it).
pub fn nodelay(socket: &Socket) -> bool {
    match socket.get_ref() {
        MaybeTlsStream::Plain(tcp) => tcp.nodelay().unwrap_or(false),
        MaybeTlsStream::Rustls(tls) => tls.get_ref().0.nodelay().unwrap_or(false),
        _ => false,
    }
}

/// The app's side of a running session: send messages, stop it. Dropping every copy of it ends the session too.
#[derive(Clone)]
pub struct AssistHandle {
    outgoing: mpsc::UnboundedSender<ClientMessage>,
    stop: Arc<watch::Sender<bool>>,
}

impl std::fmt::Debug for AssistHandle {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("AssistHandle").field("running", &self.is_running()).finish()
    }
}

impl AssistHandle {
    /// Queues a message; it goes out in order, once the socket is up and the relay is ready.
    pub fn send(&self, message: ClientMessage) -> bool {
        self.outgoing.send(message).is_ok()
    }

    /// Closes the connection (1000) and ends the session.
    pub fn stop(&self) {
        let _ = self.stop.send(true);
    }

    /// Whether the session's task still runs.
    pub fn is_running(&self) -> bool {
        !self.outgoing.is_closed()
    }
}

/// Starts a session's connection task. `on_event` hears everything; it ends with exactly one `Ended`.
pub fn spawn(grant: RelayGrant, refresh: Refresh, policy: Policy, on_event: impl Fn(AssistEvent) + Send + Sync + 'static) -> AssistHandle {
    let (outgoing, rx) = mpsc::unbounded_channel();
    let (stop, stopped) = watch::channel(false);
    tokio::spawn(run(grant, refresh, policy, rx, stopped, Arc::new(on_event)));
    AssistHandle { outgoing, stop: Arc::new(stop) }
}

/// How one connection ended, and whether the relay said `ready` on it.
enum Outcome {
    Stopped,
    Closed { code: Option<u16>, was_ready: bool },
    /// New tokens were rejected for too long.
    TokenRejected,
}

/// `token_refresh_rejected` in a row on an open connection (pure): the first gets a new token at once, the next ones
/// after the reconnect pauses; past the resume grace the session ends.
#[derive(Debug, Default)]
pub struct Rejections {
    count: u32,
    since: Option<Instant>,
}

impl Rejections {
    /// One more rejection at `now`: how long to wait before the next token, or `None` to give up.
    pub fn rejected(&mut self, policy: &Policy, now: Instant) -> Option<Duration> {
        self.count += 1;
        let since = *self.since.get_or_insert(now);
        if now.duration_since(since) >= policy.give_up_after {
            return None;
        }
        Some(if self.count == 1 { Duration::ZERO } else { policy.pause(self.count - 1) })
    }

    /// A token was accepted, or the relay said `ready`: the count starts again.
    pub fn reset(&mut self) {
        *self = Rejections::default();
    }
}

type OnEvent = Arc<dyn Fn(AssistEvent) + Send + Sync>;

/// What one session carries from connection to connection.
struct Session {
    grant: RelayGrant,
    refresh: Refresh,
    policy: Policy,
    outgoing: mpsc::UnboundedReceiver<ClientMessage>,
    stop: watch::Receiver<bool>,
    on_event: OnEvent,
    outbox: Outbox,
    /// Every `context_update` the app gave, merged: what the relay should hold.
    context: Option<super::protocol::ContextUpdate>,
}

async fn run(grant: RelayGrant, refresh: Refresh, policy: Policy, outgoing: mpsc::UnboundedReceiver<ClientMessage>, stop: watch::Receiver<bool>, on_event: OnEvent) {
    let mut session = Session { grant, refresh, policy, outgoing, stop, on_event, outbox: Outbox::default(), context: None };
    let reason = session.run().await;
    (session.on_event)(AssistEvent::Ended(reason));
}

impl Session {
    /// A message from the app into the outbox; a `context_update` is also merged into the context kept for resends.
    fn take_in(&mut self, message: ClientMessage, down: bool) {
        if let ClientMessage::ContextUpdate(update) = &message {
            self.context.get_or_insert_with(Default::default).merge(update);
        }
        self.outbox.push(message, down);
    }

    async fn run(&mut self) -> EndReason {
        let mut attempt: u32 = 0;
        let mut down_since: Option<Instant> = None;
        // Token problems in a row: the first gets a new token at once, the next ones wait like any reconnect.
        let mut token_trouble: u32 = 0;
        loop {
            if *self.stop.borrow() {
                return EndReason::Closed;
            }
            let mut refresh_first = false;
            match connect(&self.grant).await {
                Ok(socket) => match self.serve(socket).await {
                    Outcome::Stopped => return EndReason::Closed,
                    Outcome::TokenRejected => return EndReason::TokenRejected,
                    Outcome::Closed { code, was_ready } => {
                        if was_ready {
                            attempt = 0;
                            down_since = None;
                            token_trouble = 0;
                        }
                        self.outbox.went_down();
                        match after_close(code) {
                            AfterClose::End(Ended::Closed) => return EndReason::Closed,
                            AfterClose::End(Ended::Replaced) => return EndReason::Replaced,
                            AfterClose::End(Ended::WrongSession) => return EndReason::WrongSession,
                            AfterClose::RefreshThenReconnect => refresh_first = true,
                            AfterClose::Reconnect => {}
                        }
                    }
                },
                Err(ConnectError::WrongSession) => return EndReason::WrongSession,
                Err(ConnectError::Insecure) => return EndReason::Insecure,
                Err(ConnectError::BadUrl(why)) => return EndReason::GaveUp(why),
                Err(ConnectError::TokenRefused) => refresh_first = true,
                Err(ConnectError::Other(_)) => {}
            }
            let since = *down_since.get_or_insert_with(Instant::now);
            if since.elapsed() >= self.policy.give_up_after {
                return EndReason::GaveUp("The assistant could not reconnect.".into());
            }
            if refresh_first {
                token_trouble += 1;
                match (self.refresh)().await {
                    Ok(next) => self.grant = next,
                    Err(why) => return EndReason::GaveUp(why),
                }
                if token_trouble == 1 {
                    // A new token is the fix: reconnect at once, the first time only.
                    (self.on_event)(AssistEvent::Reconnecting { attempt: 0, after: Duration::ZERO, why: "token".into() });
                    continue;
                }
            }
            attempt += 1;
            let after = self.policy.pause(attempt);
            let why = if refresh_first { "token" } else { "connection" };
            (self.on_event)(AssistEvent::Reconnecting { attempt, after, why: why.into() });
            if !self.wait_down(after).await {
                return EndReason::Closed;
            }
        }
    }

    /// Waits `after` with the socket down, keeping what the app sends (thinned while down). False when stopped.
    async fn wait_down(&mut self, after: Duration) -> bool {
        let until = Instant::now() + after;
        loop {
            tokio::select! {
                _ = tokio::time::sleep_until(until) => return true,
                _ = self.stop.changed() => return false,
                message = self.outgoing.recv() => match message {
                    Some(message) => self.take_in(message, true),
                    None => return false,
                },
            }
        }
    }

    async fn serve(&mut self, socket: Socket) -> Outcome {
        let (mut sink, mut stream) = socket.split();
        // `seq` counts per direction and per connection, from 1 (1.9.0-d).
        let mut seq: u64 = 0;
        // Nothing goes out before the relay's `ready`; then one message per `gap`, the relay's rate.
        let mut ready = false;
        let mut pace = gap(None);
        let mut next_send = Instant::now();
        let mut refresh_at = Instant::now() + self.policy.refresh_in(self.grant.expires_at, Utc::now());
        // A refresh runs on its own, so the socket keeps reading and sending meanwhile.
        let (refreshed_tx, mut refreshed) = mpsc::unbounded_channel::<Result<RelayGrant, String>>();
        let mut refreshing = false;
        let mut rejections = Rejections::default();
        let closed = |code: Option<u16>, ready: bool| Outcome::Closed { code, was_ready: ready };
        loop {
            let can_send = ready && !self.outbox.is_empty();
            tokio::select! {
                _ = self.stop.changed() => {
                    let _ = sink.send(Message::Close(Some(CloseFrame { code: CloseCode::Normal, reason: "".into() }))).await;
                    return Outcome::Stopped;
                }
                frame = stream.next() => match frame {
                    Some(Ok(Message::Text(text))) => {
                        let Some((_, message)) = parse(text.as_str()) else { continue };
                        match &message {
                            ServerMessage::Unknown => continue,
                            ServerMessage::Ready(r) => {
                                ready = true;
                                rejections.reset();
                                // A session the relay lost (its restart): it gets the whole context again, first.
                                if !r.resumed && let Some(context) = &self.context {
                                    self.outbox.context_first(ClientMessage::ContextUpdate(Box::new(context.clone())));
                                }
                                pace = gap(r.limits.and_then(|l| l.messages_per_second));
                                next_send = Instant::now();
                                (self.on_event)(AssistEvent::Connected { resumed: r.resumed, mode: r.mode.clone() });
                            }
                            // A rejected new token (the old one is valid until it expires): another one, at once the
                            // first time, then after growing pauses; given up past the resume grace.
                            ServerMessage::Error(error) if error.code == "token_refresh_rejected" => match rejections.rejected(&self.policy, Instant::now()) {
                                Some(wait) => refresh_at = Instant::now() + wait,
                                None => {
                                    (self.on_event)(AssistEvent::Message(message));
                                    let _ = sink.send(Message::Close(Some(CloseFrame { code: CloseCode::Normal, reason: "".into() }))).await;
                                    return Outcome::TokenRejected;
                                }
                            },
                            ServerMessage::TokenRefreshed { .. } => rejections.reset(),
                            _ => {}
                        }
                        (self.on_event)(AssistEvent::Message(message));
                    }
                    Some(Ok(Message::Close(frame))) => return closed(frame.map(|f| u16::from(f.code)), ready),
                    // Pings are answered by the socket itself; binary frames are not part of the protocol.
                    Some(Ok(_)) => {}
                    Some(Err(_)) | None => return closed(None, ready),
                },
                message = self.outgoing.recv() => match message {
                    Some(message) => self.take_in(message, false),
                    None => return Outcome::Stopped,
                },
                _ = tokio::time::sleep_until(next_send), if can_send => {
                    let Some(message) = self.outbox.pop() else { continue };
                    seq += 1;
                    if sink.send(Message::Text(message.frame(seq).into())).await.is_err() {
                        // Not sent: first in line on the next connection.
                        self.outbox.unpop(message);
                        return closed(None, ready);
                    }
                    next_send = Instant::now() + pace;
                }
                _ = tokio::time::sleep_until(refresh_at), if !refreshing => {
                    refreshing = true;
                    let (fetch, tx) = ((self.refresh)(), refreshed_tx.clone());
                    tokio::spawn(async move {
                        let _ = tx.send(fetch.await);
                    });
                }
                Some(next) = refreshed.recv() => {
                    refreshing = false;
                    match next {
                        Ok(next) => {
                            seq += 1;
                            let frame = ClientMessage::TokenRefresh { token: next.token.clone() }.frame(seq);
                            refresh_at = Instant::now() + self.policy.refresh_in(next.expires_at, Utc::now());
                            self.grant = next;
                            if sink.send(Message::Text(frame.into())).await.is_err() {
                                return closed(None, ready);
                            }
                        }
                        Err(_) => refresh_at = Instant::now() + self.policy.refresh_retry,
                    }
                }
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn close_codes_decide_what_happens_next() {
        assert_eq!(after_close(Some(close::NORMAL)), AfterClose::End(Ended::Closed));
        assert_eq!(after_close(Some(close::REPLACED)), AfterClose::End(Ended::Replaced));
        assert_eq!(after_close(Some(close::WRONG_SESSION)), AfterClose::End(Ended::WrongSession));
        assert_eq!(after_close(Some(close::TOKEN_EXPIRED)), AfterClose::RefreshThenReconnect);
        for code in [Some(close::RESTARTING), Some(close::IDLE), Some(close::TOO_BIG), Some(1006), None] {
            assert_eq!(after_close(code), AfterClose::Reconnect, "{code:?}");
        }
    }

    #[test]
    fn the_pauses_grow_and_the_refresh_comes_two_minutes_early() {
        let policy = Policy::default();
        assert_eq!(policy.pause(1), Duration::from_millis(250));
        assert_eq!(policy.pause(3), Duration::from_secs(1));
        assert_eq!(policy.pause(40), Duration::from_secs(8), "the last pause repeats");
        assert!(policy.give_up_after < Duration::from_secs(120), "inside the relay's resume grace");
        let now = DateTime::from_timestamp(1_763_380_800, 0).unwrap();
        assert_eq!(policy.refresh_in(now + chrono::Duration::minutes(15), now), Duration::from_secs(13 * 60));
        assert_eq!(policy.refresh_in(now + chrono::Duration::seconds(30), now), Duration::ZERO, "already due");
    }

    #[test]
    fn rejected_tokens_in_a_row_wait_longer_each_time_then_give_up() {
        let policy = Policy::default();
        let start = Instant::now();
        let mut rejections = Rejections::default();
        let waits: Vec<Option<Duration>> = (0..4).map(|i| rejections.rejected(&policy, start + Duration::from_secs(i))).collect();
        assert_eq!(waits, [Some(Duration::ZERO), Some(Duration::from_millis(250)), Some(Duration::from_millis(500)), Some(Duration::from_secs(1))], "at once, then 250 ms → 8 s");
        assert_eq!(rejections.rejected(&policy, start + policy.give_up_after), None, "past the resume grace");
        rejections.reset();
        assert_eq!(rejections.rejected(&policy, start + Duration::from_secs(500)), Some(Duration::ZERO), "an accepted token starts again");
    }

    #[test]
    fn the_grant_s_token_is_never_printed() {
        let grant = RelayGrant { url: "wss://relay.example/v1/assist".into(), token: "SECRET".into(), expires_at: Utc::now(), pins: vec![] };
        assert!(!format!("{grant:?}").contains("SECRET"));
    }

    #[test]
    fn plain_ws_only_to_this_computer_in_debug_builds() {
        assert!(plain_allowed(Some("127.0.0.1"), true));
        assert!(plain_allowed(Some("localhost"), true));
        assert!(!plain_allowed(Some("127.0.0.1"), false), "a release build takes only wss://");
        assert!(!plain_allowed(Some("relay.example"), true));
        assert!(!plain_allowed(None, true));
    }

    #[tokio::test]
    async fn no_pins_or_unusable_ones_never_reach_the_relay() {
        for pins in [vec![], vec!["sha256/@@@".to_string()]] {
            let grant = RelayGrant { url: "wss://relay.example/v1/assist".into(), token: "t".into(), expires_at: Utc::now(), pins };
            assert_eq!(connect(&grant).await.err(), Some(ConnectError::Insecure));
        }
    }
}
