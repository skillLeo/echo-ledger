//! The WebSocket client to the Live Assist relay (docs/API_ASSIST.md 1.9.0-c; close codes and first messages as
//! services/assist-relay sends them). One task per assist session:
//!
//! - connects to `relay.url` with the relay token as `Authorization: Bearer`, with TCP_NODELAY on the socket (the
//!   relay's load test found Nagle's delay cost 20–30 ms a suggestion);
//! - sends `token_refresh` with a new token 2 minutes before the current one expires (`refresh` fetches it from the
//!   server's `POST /api/assist/sessions/{id}/token`), and again when the relay rejects one;
//! - on a dropped connection reconnects with growing pauses, inside the relay's 120 s resume grace, so the session
//!   resumes with its context; 4401 (token expired) reconnects at once with a new token; 4409 (a newer connection took
//!   over), 4403 (another person's session) and a normal close end it.
//!
//! Messages the app sends while the socket is down wait in order and go out once it is back.

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

use super::protocol::{ClientMessage, ServerMessage, parse};

pub type Socket = WebSocketStream<MaybeTlsStream<tokio::net::TcpStream>>;

/// The relay block of `POST /api/assist/sessions` and of each token refresh.
#[derive(Clone, PartialEq, Eq)]
pub struct RelayGrant {
    pub url: String,
    pub token: String,
    pub expires_at: DateTime<Utc>,
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
    /// Stopped by the app, or closed normally by the relay (1000).
    Closed,
    /// A newer connection for this session took over (4409).
    Replaced,
    /// The token names another person's session (4403, or 403 before the upgrade).
    WrongSession,
    /// No connection inside the resume grace, or no new token.
    GaveUp(String),
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
    /// Reconnect at once, with a new token first.
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
        // 1012 restarting, 4408 idle, 1009 too big, any other code, or no close frame at all (the network).
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
    /// The URL is not one this app connects to (only `wss://`, or `ws://` on this computer).
    BadUrl(String),
    Other(String),
}

/// Opens the socket: the token as `Authorization: Bearer`, TCP_NODELAY on, TLS for `wss://` (WebPKI roots, as the
/// speech engines'); plain `ws://` only to this computer (tests, a local relay).
pub async fn connect(grant: &RelayGrant) -> Result<Socket, ConnectError> {
    let uri: Uri = grant.url.parse().map_err(|_| ConnectError::BadUrl("The relay address is not valid.".into()))?;
    let connector = match (uri.scheme_str(), uri.host()) {
        (Some("wss"), _) => Connector::Rustls(crate::net::pinning::public_tls_config()),
        (Some("ws"), Some("localhost" | "127.0.0.1" | "[::1]" | "::1")) => Connector::Plain,
        _ => return Err(ConnectError::BadUrl("The relay must be reached over wss://.".into())),
    };
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
        Err(e) => Err(ConnectError::Other(e.to_string())),
    }
}

/// Whether the socket has TCP_NODELAY set (the relay's latency depends on it).
pub fn nodelay(socket: &Socket) -> bool {
    match socket.get_ref() {
        MaybeTlsStream::Plain(tcp) => tcp.nodelay().unwrap_or(false),
        MaybeTlsStream::Rustls(tls) => tls.get_ref().0.nodelay().unwrap_or(false),
        _ => false,
    }
}

/// The app's side of a running session: send messages, stop it.
#[derive(Clone)]
pub struct AssistHandle {
    outgoing: mpsc::UnboundedSender<ClientMessage>,
    stop: Arc<watch::Sender<bool>>,
}

impl AssistHandle {
    /// Queues a message; it goes out in order, once the socket is up.
    pub fn send(&self, message: ClientMessage) -> bool {
        self.outgoing.send(message).is_ok()
    }

    /// Closes the connection (1000) and ends the session.
    pub fn stop(&self) {
        let _ = self.stop.send(true);
    }
}

/// Starts a session's connection task. `on_event` hears everything; it ends with exactly one `Ended`.
pub fn spawn(grant: RelayGrant, refresh: Refresh, policy: Policy, on_event: impl Fn(AssistEvent) + Send + Sync + 'static) -> AssistHandle {
    let (outgoing, rx) = mpsc::unbounded_channel();
    let (stop, stopped) = watch::channel(false);
    tokio::spawn(run(grant, refresh, policy, rx, stopped, Arc::new(on_event)));
    AssistHandle { outgoing, stop: Arc::new(stop) }
}

/// How one connection ended.
enum Outcome {
    Stopped,
    Closed(Option<u16>),
}

type OnEvent = Arc<dyn Fn(AssistEvent) + Send + Sync>;

async fn run(mut grant: RelayGrant, refresh: Refresh, policy: Policy, mut outgoing: mpsc::UnboundedReceiver<ClientMessage>, mut stop: watch::Receiver<bool>, on_event: OnEvent) {
    let mut seq: u64 = 0;
    let mut attempt: u32 = 0;
    let mut down_since: Option<Instant> = None;
    let end = |reason: EndReason| on_event(AssistEvent::Ended(reason));
    loop {
        if *stop.borrow() {
            return end(EndReason::Closed);
        }
        let mut refresh_first = false;
        match connect(&grant).await {
            Ok(socket) => {
                attempt = 0;
                down_since = None;
                match serve(socket, &mut grant, &refresh, &policy, &mut outgoing, &mut stop, &mut seq, &on_event).await {
                    Outcome::Stopped => return end(EndReason::Closed),
                    Outcome::Closed(code) => match after_close(code) {
                        AfterClose::End(Ended::Closed) => return end(EndReason::Closed),
                        AfterClose::End(Ended::Replaced) => return end(EndReason::Replaced),
                        AfterClose::End(Ended::WrongSession) => return end(EndReason::WrongSession),
                        AfterClose::RefreshThenReconnect => refresh_first = true,
                        AfterClose::Reconnect => {}
                    },
                }
            }
            Err(ConnectError::WrongSession) => return end(EndReason::WrongSession),
            Err(ConnectError::BadUrl(why)) => return end(EndReason::GaveUp(why)),
            Err(ConnectError::TokenRefused) => refresh_first = true,
            Err(ConnectError::Other(_)) => {}
        }
        let since = *down_since.get_or_insert_with(Instant::now);
        if since.elapsed() >= policy.give_up_after {
            return end(EndReason::GaveUp("The assistant could not reconnect.".into()));
        }
        if refresh_first {
            match (refresh)().await {
                Ok(next) => grant = next,
                Err(why) => return end(EndReason::GaveUp(why)),
            }
            // A new token is the fix: reconnect at once.
            on_event(AssistEvent::Reconnecting { attempt: 0, after: Duration::ZERO, why: "token".into() });
            continue;
        }
        attempt += 1;
        let after = policy.pause(attempt);
        on_event(AssistEvent::Reconnecting { attempt, after, why: "connection".into() });
        tokio::select! {
            _ = tokio::time::sleep(after) => {}
            _ = stop.changed() => return end(EndReason::Closed),
        }
    }
}

#[allow(clippy::too_many_arguments)]
async fn serve(
    socket: Socket,
    grant: &mut RelayGrant,
    refresh: &Refresh,
    policy: &Policy,
    outgoing: &mut mpsc::UnboundedReceiver<ClientMessage>,
    stop: &mut watch::Receiver<bool>,
    seq: &mut u64,
    on_event: &OnEvent,
) -> Outcome {
    let (mut sink, mut stream) = socket.split();
    let mut refresh_at = Instant::now() + policy.refresh_in(grant.expires_at, Utc::now());
    // A refresh runs on its own, so the socket keeps reading and sending meanwhile.
    let (refreshed_tx, mut refreshed) = mpsc::unbounded_channel::<Result<RelayGrant, String>>();
    let mut refreshing = false;
    loop {
        tokio::select! {
            _ = stop.changed() => {
                let _ = sink.send(Message::Close(Some(CloseFrame { code: CloseCode::Normal, reason: "".into() }))).await;
                return Outcome::Stopped;
            }
            frame = stream.next() => match frame {
                Some(Ok(Message::Text(text))) => {
                    let Some((_, message)) = parse(text.as_str()) else { continue };
                    match &message {
                        ServerMessage::Unknown => continue,
                        ServerMessage::Ready(ready) => on_event(AssistEvent::Connected { resumed: ready.resumed, mode: ready.mode.clone() }),
                        // A rejected new token: fetch another one at once (the old one is still valid until it expires).
                        ServerMessage::Error(error) if error.code == "token_refresh_rejected" => refresh_at = Instant::now(),
                        _ => {}
                    }
                    on_event(AssistEvent::Message(message));
                }
                Some(Ok(Message::Close(frame))) => return Outcome::Closed(frame.map(|f| u16::from(f.code))),
                // Pings are answered by the socket itself; binary frames are not part of the protocol.
                Some(Ok(_)) => {}
                Some(Err(_)) | None => return Outcome::Closed(None),
            },
            message = outgoing.recv() => {
                let Some(message) = message else { return Outcome::Stopped };
                *seq += 1;
                if sink.send(Message::Text(message.frame(*seq).into())).await.is_err() {
                    return Outcome::Closed(None);
                }
            }
            _ = tokio::time::sleep_until(refresh_at), if !refreshing => {
                refreshing = true;
                let (fetch, tx) = ((refresh)(), refreshed_tx.clone());
                tokio::spawn(async move {
                    let _ = tx.send(fetch.await);
                });
            }
            Some(next) = refreshed.recv() => {
                refreshing = false;
                match next {
                    Ok(next) => {
                        *seq += 1;
                        let frame = ClientMessage::TokenRefresh { token: next.token.clone() }.frame(*seq);
                        refresh_at = Instant::now() + policy.refresh_in(next.expires_at, Utc::now());
                        *grant = next;
                        if sink.send(Message::Text(frame.into())).await.is_err() {
                            return Outcome::Closed(None);
                        }
                    }
                    Err(_) => refresh_at = Instant::now() + policy.refresh_retry,
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
    fn the_grant_s_token_is_never_printed() {
        let grant = RelayGrant { url: "wss://relay.example/v1/assist".into(), token: "SECRET".into(), expires_at: Utc::now() };
        assert!(!format!("{grant:?}").contains("SECRET"));
    }
}
