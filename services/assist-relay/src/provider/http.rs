//! What the real providers share: one HTTP client (TLS through rustls, keep-alive, no Nagle), a reader for
//! server-sent events, the retry rule for a busy provider, and the stream that turns a provider's events into the
//! relay's.
//!
//! Retries are short on purpose: a suggestion has about two seconds to its first word. A busy answer (429, 5xx, 529)
//! is tried again only while the wait fits in [`Retry::max_wait`]; otherwise the error goes back at once, and the
//! session moves on to the mode's fallback model, usually at another provider.

use std::collections::VecDeque;
use std::time::Duration;

use futures_util::{StreamExt, stream};
use reqwest::{RequestBuilder, Response, StatusCode};

use super::{ProviderError, StreamEvent, TokenStream};
use crate::domain::ProviderKind;

/// The HTTP client for the providers.
pub fn client() -> reqwest::Client {
    let _ = rustls::crypto::ring::default_provider().install_default();
    reqwest::Client::builder()
        .connect_timeout(Duration::from_secs(3))
        .pool_idle_timeout(Duration::from_secs(90))
        .tcp_nodelay(true)
        .build()
        .unwrap_or_else(|_| reqwest::Client::new())
}

/// How often, and how long, a busy provider is tried again before the session falls back.
#[derive(Debug, Clone, Copy)]
pub struct Retry {
    /// Tries after the first.
    pub retries: u32,
    /// The first wait; it doubles each time.
    pub base: Duration,
    /// A provider asking for a longer wait (`retry-after`) is not waited for.
    pub max_wait: Duration,
}

impl Default for Retry {
    fn default() -> Self {
        Retry { retries: 2, base: Duration::from_millis(100), max_wait: Duration::from_secs(1) }
    }
}

impl Retry {
    /// The wait before try `attempt + 1` after `status`, or `None` to give up now.
    pub fn wait(&self, attempt: u32, status: StatusCode, retry_after: Option<Duration>) -> Option<Duration> {
        if attempt >= self.retries || !retryable(status) {
            return None;
        }
        let backoff = self.base.saturating_mul(1 << attempt.min(8));
        let wait = retry_after.map_or(backoff, |after| after.max(backoff));
        (wait <= self.max_wait).then_some(wait)
    }
}

fn retryable(status: StatusCode) -> bool {
    matches!(status.as_u16(), 429 | 500 | 502 | 503 | 504 | 529)
}

/// The error for a refused HTTP answer.
pub fn status_error(kind: ProviderKind, status: StatusCode) -> ProviderError {
    match status.as_u16() {
        // A missing or revoked key: the key in Admin -> API Credentials needs fixing.
        401 | 403 => ProviderError::NotConfigured(kind),
        429 => ProviderError::RateLimited,
        500..=599 => ProviderError::Unavailable,
        _ => ProviderError::Protocol,
    }
}

fn retry_after(response: &Response) -> Option<Duration> {
    let value = response.headers().get(reqwest::header::RETRY_AFTER)?.to_str().ok()?;
    value.trim().parse::<f64>().ok().filter(|s| s.is_finite() && *s >= 0.0).map(Duration::from_secs_f64)
}

/// Sends a request built by `build`, trying again on a busy answer while [`Retry`] allows. A network failure before
/// any answer is tried again the same way.
pub async fn send(kind: ProviderKind, retry: Retry, build: impl Fn() -> RequestBuilder) -> Result<Response, ProviderError> {
    let mut attempt = 0;
    loop {
        let (status, after) = match build().send().await {
            Ok(response) if response.status().is_success() => return Ok(response),
            Ok(response) => (response.status(), retry_after(&response)),
            Err(_) => (StatusCode::SERVICE_UNAVAILABLE, None),
        };
        match retry.wait(attempt, status, after) {
            Some(wait) => {
                tokio::time::sleep(wait).await;
                attempt += 1;
            }
            None => return Err(status_error(kind, status)),
        }
    }
}

/// One server-sent event.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct SseEvent {
    pub event: Option<String>,
    pub data: String,
}

/// Cuts a response body into events: `event:` and `data:` lines, an empty line ends an event, `:` lines are comments.
#[derive(Debug, Default)]
pub struct SseParser {
    line: Vec<u8>,
    event: Option<String>,
    data: Vec<String>,
}

impl SseParser {
    pub fn push(&mut self, bytes: &[u8]) -> Vec<SseEvent> {
        let mut events = Vec::new();
        for &byte in bytes {
            if byte != b'\n' {
                self.line.push(byte);
                continue;
            }
            if self.line.last() == Some(&b'\r') {
                self.line.pop();
            }
            let line = String::from_utf8_lossy(&std::mem::take(&mut self.line)).into_owned();
            if line.is_empty() {
                if !self.data.is_empty() || self.event.is_some() {
                    events.push(SseEvent { event: self.event.take(), data: std::mem::take(&mut self.data).join("\n") });
                }
                continue;
            }
            if line.starts_with(':') {
                continue;
            }
            let (field, value) = line.split_once(':').map_or((line.as_str(), ""), |(f, v)| (f, v.strip_prefix(' ').unwrap_or(v)));
            match field {
                "event" => self.event = Some(value.to_owned()),
                "data" => self.data.push(value.to_owned()),
                _ => {}
            }
        }
        events
    }
}

/// What a provider made of one event.
#[derive(Debug, Default)]
pub struct Decoded {
    pub items: Vec<Result<StreamEvent, ProviderError>>,
    /// The provider said the answer is complete (`message_stop`, `[DONE]`, a finish reason).
    pub finished: bool,
}

impl Decoded {
    pub fn text(&mut self, text: &str) {
        if !text.is_empty() {
            self.items.push(Ok(StreamEvent::Text(text.to_owned())));
        }
    }

    pub fn fail(&mut self, error: ProviderError) {
        self.items.push(Err(error));
    }
}

/// A provider's streaming format.
pub trait Decoder: Send + 'static {
    fn decode(&mut self, event: SseEvent) -> Decoded;
    /// Called once when the body ends after `finished`: the token counts, if they came apart from the last event.
    fn finish(&mut self) -> Option<StreamEvent> {
        None
    }
}

enum State<D> {
    Connect { kind: ProviderKind, retry: Retry, build: Box<dyn Fn() -> RequestBuilder + Send + Sync>, decoder: D },
    Reading { response: Response, parser: SseParser, decoder: D, pending: VecDeque<Result<StreamEvent, ProviderError>>, finished: bool },
    Done,
}

/// The relay's stream for a provider's SSE answer. The body ending before the provider said it was finished is a
/// broken connection (`Unavailable`). Dropping the stream drops the response, which closes the connection, so the
/// provider stops generating.
pub fn sse_stream<D: Decoder>(
    kind: ProviderKind,
    retry: Retry,
    build: impl Fn() -> RequestBuilder + Send + Sync + 'static,
    decoder: D,
) -> TokenStream {
    let start = State::Connect { kind, retry, build: Box::new(build), decoder };
    stream::unfold(start, |state| async move {
        let mut state = state;
        loop {
            state = match state {
                State::Done => return None,
                State::Connect { kind, retry, build, decoder } => match send(kind, retry, build).await {
                    Ok(response) => State::Reading { response, parser: SseParser::default(), decoder, pending: VecDeque::new(), finished: false },
                    Err(error) => return Some((Err(error), State::Done)),
                },
                State::Reading { mut response, mut parser, mut decoder, mut pending, mut finished } => {
                    if let Some(item) = pending.pop_front() {
                        let next = if item.is_err() {
                            State::Done
                        } else {
                            State::Reading { response, parser, decoder, pending, finished }
                        };
                        return Some((item, next));
                    }
                    match response.chunk().await {
                        Ok(Some(bytes)) => {
                            for event in parser.push(&bytes) {
                                let decoded = decoder.decode(event);
                                pending.extend(decoded.items);
                                finished |= decoded.finished;
                            }
                            State::Reading { response, parser, decoder, pending, finished }
                        }
                        // After the provider's last word, a connection that ends badly lost nothing.
                        Ok(None) | Err(_) if finished => match decoder.finish() {
                            Some(last) => return Some((Ok(last), State::Done)),
                            None => return None,
                        },
                        // The connection ended mid-answer.
                        Ok(None) | Err(_) => return Some((Err(ProviderError::Unavailable), State::Done)),
                    }
                }
            };
        }
    })
    .boxed()
}

/// A settings value as JSON (options are passed to the provider as they are).
pub fn json_of(value: &toml::Value) -> serde_json::Value {
    serde_json::to_value(value).unwrap_or(serde_json::Value::Null)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn events_survive_any_split_and_crlf() {
        let body = "event: message_start\r\ndata: {\"a\":1}\r\n\r\n: keep-alive\n\nevent: ping\ndata: {}\n\ndata: one\ndata: two\n\n";
        for cut in 0..body.len() {
            let mut parser = SseParser::default();
            let mut events = parser.push(&body.as_bytes()[..cut]);
            events.extend(parser.push(&body.as_bytes()[cut..]));
            assert_eq!(
                events,
                vec![
                    SseEvent { event: Some("message_start".into()), data: "{\"a\":1}".into() },
                    SseEvent { event: Some("ping".into()), data: "{}".into() },
                    SseEvent { event: None, data: "one\ntwo".into() },
                ],
                "cut at {cut}"
            );
        }
    }

    #[test]
    fn busy_providers_are_retried_only_while_the_wait_fits() {
        let retry = Retry::default();
        let busy = StatusCode::from_u16(529).unwrap();
        assert_eq!(retry.wait(0, busy, None), Some(Duration::from_millis(100)));
        assert_eq!(retry.wait(1, StatusCode::TOO_MANY_REQUESTS, None), Some(Duration::from_millis(200)));
        assert_eq!(retry.wait(2, busy, None), None, "two retries at most");
        assert_eq!(retry.wait(0, StatusCode::TOO_MANY_REQUESTS, Some(Duration::from_millis(400))), Some(Duration::from_millis(400)));
        assert_eq!(retry.wait(0, StatusCode::TOO_MANY_REQUESTS, Some(Duration::from_secs(30))), None, "too long: fall back instead");
        assert_eq!(retry.wait(0, StatusCode::BAD_REQUEST, None), None);
        assert_eq!(status_error(ProviderKind::Groq, StatusCode::UNAUTHORIZED), ProviderError::NotConfigured(ProviderKind::Groq));
        assert_eq!(status_error(ProviderKind::Groq, busy), ProviderError::Unavailable);
    }
}
