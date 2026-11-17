//! Live Assist's HTTP mode (docs/API_ASSIST.md 1.9.0-q "HTTP mode"), next to the relay's WebSocket ([`super::client`])
//! for servers that can't hold a long-lived connection. The server picks the transport per session
//! ([`super::session::uses_http`]); the card and everything above it see the same [`AssistEvent`]s either way:
//!
//! - the start's `http.ready` is the relay's `ready` (one `Connected` and one `ready` message for the card);
//! - each app message is one `POST {live_url}` (the device token, `Accept: text/event-stream`, the message as on the
//!   WebSocket without `seq`); its answer is a stream of server-sent events, one relay message each, until `end`;
//! - the events of every open stream go to the card in arrival order; at most [`HttpPolicy::max_streams`] streams are
//!   open (the server answers a fifth with `429`), the rest wait in order;
//! - a stream that drops before its `end` leaves the suggestion it was writing without an end: the card hears a
//!   resumed `ready`, which clears it, as after a relay reconnect;
//! - `POST …/token` every 13 minutes keeps the session on (one not refreshed for 20 minutes is ended) and may bring a
//!   new `live_url`;
//! - `426 app_update_required` ends the session in plain words; `503` is said on the card and the session goes on.

use std::collections::VecDeque;
use std::sync::Arc;
use std::time::Duration;

use futures_util::StreamExt;
use futures_util::future::BoxFuture;
use tokio::sync::{mpsc, watch};
use tokio::time::Instant;

use super::client::{AssistEvent, AssistHandle, EndReason};
use super::protocol::{ClientMessage, Ready, RelayError, ServerMessage};
use crate::error::AppError;
use crate::net::api::ApiClient;

/// What the person reads when the server needs a newer app (`426 app_update_required`).
pub const APP_UPDATE: &str = "Live Assist needs the latest version of the app.";

/// HTTP mode's block of the start and of each token refresh.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct HttpGrant {
    pub live_url: String,
    pub ready: Ready,
}

/// Fetches a fresh `http` block (the server's token refresh, which also keeps the session on); the error is a plain
/// sentence.
pub type HttpRefresh = Arc<dyn Fn() -> BoxFuture<'static, Result<HttpGrant, String>> + Send + Sync>;

/// The limits of one HTTP-mode session.
#[derive(Clone, Debug)]
pub struct HttpPolicy {
    /// Streams open at once (the server allows 4 per session).
    pub max_streams: usize,
    /// How often the session's token is refreshed (13 minutes; the server ends one not refreshed for 20).
    pub refresh_every: Duration,
    /// After a refresh that failed, the next try.
    pub refresh_retry: Duration,
    /// After a `429` (too many streams, or too many messages), the message waits this long, then goes again.
    pub busy_wait: Duration,
}

impl Default for HttpPolicy {
    fn default() -> Self {
        HttpPolicy {
            max_streams: 4,
            refresh_every: Duration::from_secs(13 * 60),
            refresh_retry: Duration::from_secs(30),
            busy_wait: Duration::from_secs(1),
        }
    }
}

/// One server-sent event: its `event:` name and its `data:` lines joined.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct SseEvent {
    pub event: String,
    pub data: String,
}

/// The longest line a stream may send (a suggestion's token is a few words).
pub const MAX_LINE: usize = 64 * 1024;
/// The most one event's `data:` lines may add up to.
pub const MAX_EVENT: usize = 256 * 1024;

/// A stream that sent more than it may: the stream is treated as dropped.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Overflow {
    Line,
    Event,
}

/// Reads server-sent events from a byte stream in pieces of any size (pure): `event:`/`data:` lines, a blank line ends
/// an event, lines starting with `:` are keep-alives, `\r\n` and `\n` both end a line. A line over [`MAX_LINE`] or an
/// event's data over [`MAX_EVENT`] is an [`Overflow`]: nothing more is kept for it.
#[derive(Default)]
pub struct SseReader {
    buffer: Vec<u8>,
    event: String,
    data: Vec<String>,
    data_len: usize,
}

impl SseReader {
    /// Takes the next bytes; gives the events they complete, in order, or the overflow that ends the stream.
    pub fn push(&mut self, bytes: &[u8]) -> Result<Vec<SseEvent>, Overflow> {
        self.buffer.extend_from_slice(bytes);
        let mut done = Vec::new();
        while let Some(end) = self.buffer.iter().position(|&b| b == b'\n') {
            if end > MAX_LINE {
                self.buffer.clear();
                return Err(Overflow::Line);
            }
            let mut line: Vec<u8> = self.buffer.drain(..=end).collect();
            line.pop();
            if line.last() == Some(&b'\r') {
                line.pop();
            }
            let line = String::from_utf8_lossy(&line).into_owned();
            if line.is_empty() {
                if !self.data.is_empty() || !self.event.is_empty() {
                    let event = if self.event.is_empty() { "message".to_string() } else { std::mem::take(&mut self.event) };
                    done.push(SseEvent { event, data: std::mem::take(&mut self.data).join("\n") });
                    self.data_len = 0;
                }
                continue;
            }
            if line.starts_with(':') {
                continue;
            }
            let (field, value) = line.split_once(':').map_or((line.as_str(), ""), |(f, v)| (f, v.strip_prefix(' ').unwrap_or(v)));
            match field {
                "event" => self.event = value.to_string(),
                "data" => {
                    self.data_len += value.len() + 1;
                    if self.data_len > MAX_EVENT {
                        self.buffer.clear();
                        return Err(Overflow::Event);
                    }
                    self.data.push(value.to_string());
                }
                _ => {}
            }
        }
        // No line end yet and already too long: it will not be a line.
        if self.buffer.len() > MAX_LINE {
            self.buffer.clear();
            return Err(Overflow::Line);
        }
        Ok(done)
    }
}

/// What one stream tells the session.
enum FromStream {
    Message(ServerMessage),
    /// The stream ended: with its `end` (`dropped` false), or before it.
    Done { dropped: bool },
    /// Refused before it started: the message and the error.
    Refused(ClientMessage, AppError),
}

type OnEvent = Arc<dyn Fn(AssistEvent) + Send + Sync>;

/// A session whose connection could not be made at all: its `Ended` comes at once, its handle takes nothing.
pub fn ended_at_once(on_event: impl Fn(AssistEvent) + Send + Sync + 'static, reason: EndReason) -> AssistHandle {
    let (outgoing, _) = mpsc::unbounded_channel();
    let (stop, _) = watch::channel(true);
    on_event(AssistEvent::Ended(reason));
    AssistHandle::from_parts(outgoing, stop)
}

/// Starts an HTTP-mode session. `on_event` hears everything; it ends with exactly one `Ended`.
pub fn spawn(api: Arc<ApiClient>, grant: HttpGrant, refresh: HttpRefresh, policy: HttpPolicy, on_event: impl Fn(AssistEvent) + Send + Sync + 'static) -> AssistHandle {
    let (outgoing, rx) = mpsc::unbounded_channel();
    let (stop, stopped) = watch::channel(false);
    let on_event: OnEvent = Arc::new(on_event);
    tokio::spawn(async move {
        let reason = run(api, grant, refresh, policy, rx, stopped, on_event.clone()).await;
        on_event(AssistEvent::Ended(reason));
    });
    AssistHandle::from_parts(outgoing, stop)
}

async fn run(
    api: Arc<ApiClient>,
    mut grant: HttpGrant,
    refresh: HttpRefresh,
    policy: HttpPolicy,
    mut outgoing: mpsc::UnboundedReceiver<ClientMessage>,
    mut stop: watch::Receiver<bool>,
    on_event: OnEvent,
) -> EndReason {
    // The start's `ready`: as the relay's first message.
    on_event(AssistEvent::Connected { resumed: false, mode: grant.ready.mode.clone() });
    on_event(AssistEvent::Message(ServerMessage::Ready(grant.ready.clone())));
    let (from_streams, mut streams) = mpsc::unbounded_channel::<FromStream>();
    let (refreshed_tx, mut refreshed) = mpsc::unbounded_channel::<Result<HttpGrant, String>>();
    let mut waiting: VecDeque<ClientMessage> = VecDeque::new();
    // Every task this session starts (a stream per message, the token refresh): all aborted when `run` returns, for
    // whatever reason (Stop, a 426, the session gone), so no stream is left holding a connection open.
    let mut tasks: tokio::task::JoinSet<()> = tokio::task::JoinSet::new();
    let mut open = 0usize;
    let mut busy_until: Option<Instant> = None;
    let mut refresh_at = Instant::now() + policy.refresh_every;
    let mut refreshing = false;
    loop {
        // What waits goes out while there is room (and the server did not just say it was busy).
        while open < policy.max_streams && busy_until.is_none_or(|until| Instant::now() >= until) {
            let Some(message) = waiting.pop_front() else { break };
            open += 1;
            busy_until = None;
            tasks.spawn(stream(api.clone(), grant.live_url.clone(), message, from_streams.clone()));
        }
        tokio::select! {
            _ = stop.changed() => return EndReason::Closed,
            // Finished tasks leave the set (their results are nothing).
            Some(_) = tasks.join_next(), if !tasks.is_empty() => {}
            message = outgoing.recv() => match message {
                Some(message) => waiting.push_back(message),
                None => return EndReason::Closed,
            },
            Some(from) = streams.recv() => match from {
                FromStream::Message(message) => on_event(AssistEvent::Message(message)),
                FromStream::Done { dropped } => {
                    open = open.saturating_sub(1);
                    if dropped {
                        // The suggestion it was writing gets no end: the card clears it, as after a resumed `ready`.
                        on_event(AssistEvent::Message(ServerMessage::Ready(Ready { resumed: true, ..grant.ready.clone() })));
                    }
                }
                FromStream::Refused(message, error) => {
                    open = open.saturating_sub(1);
                    match refusal(&error) {
                        Refusal::AppUpdate => {
                            on_event(AssistEvent::Message(ServerMessage::Error(plain_error("app_update_required", false))));
                            return EndReason::AppUpdateRequired;
                        }
                        Refusal::Ended(why) => return EndReason::GaveUp(why),
                        Refusal::Busy => {
                            // Too many streams or messages: this one goes again first, a moment later.
                            waiting.push_front(message);
                            busy_until = Some(Instant::now() + policy.busy_wait);
                        }
                        Refusal::Unavailable => on_event(AssistEvent::Message(ServerMessage::Error(plain_error("relay_unavailable", true)))),
                        Refusal::Dropped(why) => eprintln!("[echoledger] Live Assist: a {} was not taken: {why}", message.kind()),
                    }
                }
            },
            _ = tokio::time::sleep_until(busy_until.unwrap_or_else(Instant::now)), if busy_until.is_some() => busy_until = None,
            _ = tokio::time::sleep_until(refresh_at), if !refreshing => {
                refreshing = true;
                let (refresh, done) = (refresh.clone(), refreshed_tx.clone());
                tasks.spawn(async move {
                    let _ = done.send(refresh().await);
                });
            }
            Some(result) = refreshed.recv() => {
                refreshing = false;
                match result {
                    Ok(next) => {
                        grant.live_url = next.live_url;
                        grant.ready = next.ready;
                        refresh_at = Instant::now() + policy.refresh_every;
                    }
                    Err(why) => {
                        eprintln!("[echoledger] Live Assist: the session's token was not renewed: {why}");
                        refresh_at = Instant::now() + policy.refresh_retry;
                    }
                }
            }
        }
    }
}

/// What a refusal before a stream means for the session (pure).
#[derive(Clone, Debug, PartialEq, Eq)]
pub enum Refusal {
    /// `426`: the session ends, saying an update is needed.
    AppUpdate,
    /// `401`, `404`, `409 session_ended`: the session is gone.
    Ended(String),
    /// `429`: the same message again in a moment.
    Busy,
    /// `503`: said on the card; the session goes on.
    Unavailable,
    /// Anything else (`422`, a network error): that message is not taken, the session goes on.
    Dropped(String),
}

pub fn refusal(error: &AppError) -> Refusal {
    match error {
        AppError::NotPaired => Refusal::Ended("Not signed in.".into()),
        AppError::Server { status: 426, .. } => Refusal::AppUpdate,
        AppError::Server { status: 429, .. } => Refusal::Busy,
        AppError::Server { status: 503, .. } => Refusal::Unavailable,
        AppError::Server { status: 404 | 409, message, .. } => Refusal::Ended(message.clone()),
        other => Refusal::Dropped(other.to_string()),
    }
}

/// An error the card says in its own words ([`super::card::error_sentence`]).
fn plain_error(code: &str, retry: bool) -> RelayError {
    RelayError { code: code.into(), message: String::new(), retry, suggestion_id: None, in_reply_to: None, fatal: !retry }
}

/// A stream's last word to the session, said exactly once: by [`StreamEnd::finish`], or when the task ends any other
/// way (it panicked, or a dropped connection) as a dropped stream, so the session's count of open streams always goes
/// down and the suggestion it was writing leaves the card.
struct StreamEnd {
    to: Option<mpsc::UnboundedSender<FromStream>>,
}

impl StreamEnd {
    fn finish(mut self, last: FromStream) {
        if let Some(to) = self.to.take() {
            let _ = to.send(last);
        }
    }
}

impl Drop for StreamEnd {
    fn drop(&mut self) {
        if let Some(to) = self.to.take() {
            let _ = to.send(FromStream::Done { dropped: true });
        }
    }
}

/// One message's stream: posted, then each event to the session as it comes, until `end`.
async fn stream(api: Arc<ApiClient>, live_url: String, message: ClientMessage, to: mpsc::UnboundedSender<FromStream>) {
    let end = StreamEnd { to: Some(to.clone()) };
    let body = serde_json::to_string(&message).unwrap_or_default();
    let response = match api.assist_live(&live_url, body).await {
        Ok(response) => response,
        Err(error) => return end.finish(FromStream::Refused(message, error)),
    };
    let mut reader = SseReader::default();
    let mut bytes = response.bytes_stream();
    while let Some(Ok(chunk)) = bytes.next().await {
        let Ok(events) = reader.push(&chunk) else {
            // More than a stream may send: it is treated as dropped (the response is dropped with this task).
            eprintln!("[echoledger] Live Assist: a stream sent more than it may; it was closed");
            break;
        };
        for event in events {
            if event.event == "end" {
                return end.finish(FromStream::Done { dropped: false });
            }
            if let Some((_, message)) = super::protocol::parse(&event.data) {
                let _ = to.send(FromStream::Message(message));
            }
        }
    }
    end.finish(FromStream::Done { dropped: true });
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn server_sent_events_are_read_across_any_split() {
        let text = ": keep-alive\r\nevent: suggestion_start\r\ndata: {\"type\":\"suggestion_start\"}\r\n\r\nevent: token\ndata: {\"a\":1,\ndata: \"b\":2}\n\n: ping\n\nevent: end\ndata: {}\n\n";
        let expected = vec![
            SseEvent { event: "suggestion_start".into(), data: "{\"type\":\"suggestion_start\"}".into() },
            SseEvent { event: "token".into(), data: "{\"a\":1,\n\"b\":2}".into() },
            SseEvent { event: "end".into(), data: "{}".into() },
        ];
        for size in [1, 2, 3, 7, 64, text.len()] {
            let mut reader = SseReader::default();
            let got: Vec<SseEvent> = text.as_bytes().chunks(size).flat_map(|piece| reader.push(piece).unwrap()).collect();
            assert_eq!(got, expected, "pieces of {size}");
        }
    }

    #[test]
    fn a_line_over_64_kib_or_an_event_over_256_kib_is_an_overflow() {
        // A line: with its end, split, or never ended.
        let long = format!("data: {}\n\n", "x".repeat(MAX_LINE + 10));
        assert_eq!(SseReader::default().push(long.as_bytes()), Err(Overflow::Line));
        let mut reader = SseReader::default();
        let mut result = Ok(Vec::new());
        for piece in long.as_bytes().chunks(1000) {
            result = reader.push(piece);
            if result.is_err() {
                break;
            }
        }
        assert_eq!(result, Err(Overflow::Line), "caught before the line even ends");
        assert_eq!(SseReader::default().push("x".repeat(MAX_LINE + 1).as_bytes()), Err(Overflow::Line), "a line that never ends");
        // Just under is fine.
        let ok = format!("data: {}\n\n", "x".repeat(MAX_LINE - 10));
        assert_eq!(SseReader::default().push(ok.as_bytes()).unwrap().len(), 1);
        // An event: lines each under the cap add up past it.
        let line = format!("data: {}\n", "y".repeat(60_000));
        let mut reader = SseReader::default();
        let mut got = Ok(Vec::new());
        for _ in 0..5 {
            got = reader.push(line.as_bytes());
            if got.is_err() {
                break;
            }
        }
        assert_eq!(got, Err(Overflow::Event), "five 60 KB lines are over 256 KiB");
        // The count starts over with each event.
        let mut reader = SseReader::default();
        for _ in 0..8 {
            let event = format!("data: {}\n\n", "z".repeat(60_000));
            assert_eq!(reader.push(event.as_bytes()).unwrap().len(), 1);
        }
    }

    #[test]
    fn each_refusal_before_a_stream_has_its_outcome() {
        let server = |status: u16| AppError::Server { status, message: "Session ended.".into(), code: None, retry_after_secs: None };
        assert_eq!(refusal(&server(426)), Refusal::AppUpdate);
        assert_eq!(refusal(&server(429)), Refusal::Busy);
        assert_eq!(refusal(&server(503)), Refusal::Unavailable);
        assert_eq!(refusal(&server(409)), Refusal::Ended("Session ended.".into()));
        assert_eq!(refusal(&AppError::NotPaired), Refusal::Ended("Not signed in.".into()));
        assert!(matches!(refusal(&server(422)), Refusal::Dropped(_)));
    }

    #[test]
    fn the_stream_client_has_no_total_timeout_and_follows_no_redirect() {
        // A total timeout (120 s on the ordinary client) would cut a long stream; a followed redirect would re-send the
        // meeting's words to an address the server did not name. Only silence cuts a stream.
        let pinning = include_str!("../net/pinning.rs");
        let live = &pinning[pinning.find("pub fn build_live_client(").unwrap()..];
        let live = &live[..live.find("\n}\n").unwrap()];
        assert!(!live.contains(".timeout("), "no total timeout: {live}");
        assert!(live.contains(".read_timeout(LIVE_IDLE)") && live.contains("redirect::Policy::none()"), "{live}");
        let api = include_str!("../net/api.rs");
        let call = &api[api.find("pub async fn assist_live(").unwrap()..];
        let call = &call[..call.find("\n    }\n").unwrap()];
        assert!(call.contains(".live\n") && !call.contains("self.http"), "the call uses the stream client: {call}");
    }

    #[tokio::test]
    async fn a_stream_task_that_panics_still_counts_as_ended_and_dropped() {
        let (to, mut from) = mpsc::unbounded_channel::<FromStream>();
        let task = tokio::spawn(async move {
            let _end = StreamEnd { to: Some(to) };
            panic!("a stream task failed");
        });
        assert!(task.await.unwrap_err().is_panic());
        assert!(matches!(from.recv().await, Some(FromStream::Done { dropped: true })), "the session hears it ended");
        assert!(from.recv().await.is_none(), "said once");
        // A stream that finished says only its own last word.
        let (to, mut from) = mpsc::unbounded_channel::<FromStream>();
        StreamEnd { to: Some(to) }.finish(FromStream::Done { dropped: false });
        assert!(matches!(from.recv().await, Some(FromStream::Done { dropped: false })));
        assert!(from.recv().await.is_none());
    }

    #[test]
    fn the_defaults_follow_the_contract() {
        let policy = HttpPolicy::default();
        assert_eq!(policy.max_streams, 4);
        assert_eq!(policy.refresh_every, Duration::from_secs(13 * 60));
    }
}
