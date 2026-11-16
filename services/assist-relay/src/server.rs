//! HTTP: `GET /healthz`, and `GET /v1/assist`, the WebSocket (contract 1.9.0-b). The session is the token's `sid`.
//! With `Authorization: Bearer <token>` the token is checked before the upgrade, so a refused app gets an HTTP answer
//! and no socket. Apps that cannot set headers send `{"type": "auth", "token": …}` as the first message instead,
//! within 5 seconds; until then nothing else is read.

use std::sync::Arc;
use std::time::Duration;

use axum::Router;
use axum::extract::ws::rejection::WebSocketUpgradeRejection;
use axum::extract::ws::{CloseFrame, Message, WebSocket, WebSocketUpgrade};
use axum::extract::State;
use axum::http::{HeaderMap, StatusCode, header};
use axum::response::{IntoResponse, Response};
use axum::routing::get;
use axum::serve::ListenerExt;
use futures_util::stream::{SplitSink, SplitStream};
use futures_util::{SinkExt, StreamExt};
use serde::Serialize;
use tokio::sync::mpsc;
use tokio::time::Instant;

use crate::auth::{AuthError, Claims, MAX_TOKEN_BYTES, TokenVerifier};
use crate::config::{Limits, RelayConfig, Shared};
use crate::protocol::{AuthMessage, ClientEnvelope, ClientMessage, ErrorMessage, ServerEnvelope, ServerMessage};
use crate::registry::Registry;
use crate::session::{Inbound, Outbound, close};
use crate::telemetry::{SessionEvent, TelemetrySink};
use crate::time::unix_now;

/// Messages waiting for the socket writer; a client that falls this far behind is dropped.
const OUT_CAPACITY: usize = 512;
const IN_CAPACITY: usize = 256;
/// How long an app without the header has to send its `auth` message.
const AUTH_WAIT: Duration = Duration::from_secs(5);

#[derive(Debug, Clone)]
pub struct AppState {
    pub registry: Arc<Registry>,
    pub verifier: Arc<TokenVerifier>,
    pub config: Arc<Shared<RelayConfig>>,
    pub telemetry: Arc<dyn TelemetrySink>,
}

pub fn router(state: AppState) -> Router {
    Router::new().route("/healthz", get(health)).route("/v1/assist", get(connect)).fallback(not_found).with_state(state)
}

#[derive(Serialize)]
struct Health {
    status: &'static str,
    version: &'static str,
}

async fn health() -> Response {
    json(StatusCode::OK, &Health { status: "ok", version: env!("CARGO_PKG_VERSION") })
}

#[derive(Serialize)]
struct HttpError<'a> {
    message: &'a str,
    code: &'a str,
}

fn json<T: Serialize>(status: StatusCode, body: &T) -> Response {
    let body = serde_json::to_string(body).unwrap_or_default();
    (status, [(header::CONTENT_TYPE, "application/json"), (header::CACHE_CONTROL, "no-store")], body).into_response()
}

fn http_error(status: StatusCode, code: &str, message: &str) -> Response {
    json(status, &HttpError { message, code })
}

async fn not_found() -> Response {
    http_error(StatusCode::NOT_FOUND, "not_found", "Not found.")
}

/// The token in `Authorization: Bearer …`: `None` without the header (the app sends it as its first message).
fn bearer(headers: &HeaderMap) -> Option<&str> {
    let value = headers.get(header::AUTHORIZATION)?;
    Some(value.to_str().ok().and_then(|value| value.strip_prefix("Bearer ")).map_or("", str::trim))
}

/// Checks a token, and that its session is not someone else's.
fn admit(state: &AppState, token: &str) -> Result<Claims, AuthError> {
    let claims = state.verifier.verify(token, unix_now())?;
    if state.registry.owner(&claims.sid).is_some_and(|owner| owner != claims.sub) {
        return Err(AuthError::WrongSession);
    }
    Ok(claims)
}

async fn connect(
    State(state): State<AppState>,
    headers: HeaderMap,
    upgrade: Result<WebSocketUpgrade, WebSocketUpgradeRejection>,
) -> Response {
    let claims = match bearer(&headers) {
        Some(token) => match admit(&state, token) {
            Ok(claims) => Some(claims),
            Err(error) => return reject(&state, error),
        },
        None => None,
    };
    let Ok(upgrade) = upgrade else {
        return http_error(StatusCode::BAD_REQUEST, "websocket_required", "Connect with a WebSocket.");
    };
    let limits = state.config.get().limits.clone();
    upgrade
        .max_message_size(limits.max_message_bytes)
        .max_frame_size(limits.max_message_bytes)
        .on_upgrade(move |socket| run_socket(socket, state, claims))
}

fn reject(state: &AppState, error: AuthError) -> Response {
    state.telemetry.event(&SessionEvent::AuthRejected { reason: error.reason() });
    let status = if error == AuthError::WrongSession { StatusCode::FORBIDDEN } else { StatusCode::UNAUTHORIZED };
    http_error(status, error.code(), &error.to_string())
}

/// The first message of an app that could not send the header: `auth`, within 5 seconds.
async fn first_message_auth(stream: &mut SplitStream<WebSocket>, state: &AppState) -> Result<Claims, AuthError> {
    let frame = match tokio::time::timeout(AUTH_WAIT, stream.next()).await {
        Ok(Some(Ok(Message::Text(text)))) if text.len() <= MAX_TOKEN_BYTES + 256 => text,
        Ok(Some(Ok(_))) => return Err(AuthError::Malformed),
        _ => return Err(AuthError::Missing),
    };
    match serde_json::from_str::<ClientEnvelope>(frame.as_str()) {
        Ok(ClientEnvelope { message: ClientMessage::Auth(AuthMessage { token }), .. }) => admit(state, &token),
        _ => Err(AuthError::Missing),
    }
}

async fn run_socket(socket: WebSocket, state: AppState, claims: Option<Claims>) {
    let (mut sink, mut stream) = socket.split();
    let claims = match claims {
        Some(claims) => claims,
        None => match first_message_auth(&mut stream, &state).await {
            Ok(claims) => claims,
            Err(error) => {
                state.telemetry.event(&SessionEvent::AuthRejected { reason: error.reason() });
                let code = if error == AuthError::WrongSession { close::WRONG_SESSION } else { close::TOKEN_EXPIRED };
                let refused = ServerMessage::Error(ErrorMessage::new(error.code(), error.to_string()).fatal());
                if let Ok(text) = serde_json::to_string(&ServerEnvelope { seq: 1, message: &refused }) {
                    let _ = sink.send(Message::Text(text.into())).await;
                }
                let _ = sink.send(Message::Close(Some(CloseFrame { code, reason: error.code().into() }))).await;
                return;
            }
        },
    };
    let (out_tx, out_rx) = mpsc::channel::<Outbound>(OUT_CAPACITY);
    let (in_tx, in_rx) = mpsc::channel::<Inbound>(IN_CAPACITY);
    let config = state.config.get();
    let ping_every = Duration::from_secs(config.session.ping_interval_secs);
    let idle = Duration::from_secs(config.session.idle_timeout_secs);
    if state.registry.connect(claims, out_tx.clone(), in_rx).is_err() {
        let _ = out_tx.try_send(Outbound::Close { code: close::WRONG_SESSION, reason: "this session belongs to someone else" });
    }
    let writer = tokio::spawn(write_loop(sink, out_rx, ping_every));
    read_loop(stream, in_tx, out_tx, &config.limits, idle).await;
    let _ = writer.await;
}

async fn write_loop(mut sink: SplitSink<WebSocket, Message>, mut out: mpsc::Receiver<Outbound>, ping_every: Duration) {
    let mut ping = tokio::time::interval_at(Instant::now() + ping_every, ping_every);
    let mut seq = 0u64;
    loop {
        tokio::select! {
            next = out.recv() => match next {
                Some(Outbound::Message(message)) => {
                    seq += 1;
                    let Ok(text) = serde_json::to_string(&ServerEnvelope { seq, message: &message }) else { continue };
                    if sink.send(Message::Text(text.into())).await.is_err() {
                        break;
                    }
                }
                Some(Outbound::Close { code, reason }) => {
                    let _ = sink.send(Message::Close(Some(CloseFrame { code, reason: reason.into() }))).await;
                    break;
                }
                None => break,
            },
            _ = ping.tick() => {
                if sink.send(Message::Ping(Default::default())).await.is_err() {
                    break;
                }
            }
        }
    }
    let _ = sink.close().await;
}

/// Allows `rate` messages a second with bursts up to `burst`.
struct Bucket {
    tokens: f64,
    rate: f64,
    burst: f64,
    at: Instant,
}

impl Bucket {
    fn new(limits: &Limits) -> Self {
        let burst = f64::from(limits.burst);
        Bucket { tokens: burst, rate: f64::from(limits.messages_per_second), burst, at: Instant::now() }
    }

    fn take(&mut self, now: Instant) -> bool {
        self.tokens = (self.tokens + now.saturating_duration_since(self.at).as_secs_f64() * self.rate).min(self.burst);
        self.at = now;
        if self.tokens >= 1.0 {
            self.tokens -= 1.0;
            true
        } else {
            false
        }
    }
}

async fn read_loop(
    mut stream: SplitStream<WebSocket>,
    inbound: mpsc::Sender<Inbound>,
    out: mpsc::Sender<Outbound>,
    limits: &Limits,
    idle: Duration,
) {
    let mut bucket = Bucket::new(limits);
    let mut last_limit_notice: Option<Instant> = None;
    let reply = |error: ErrorMessage| {
        let _ = out.try_send(Outbound::Message(ServerMessage::Error(error)));
    };
    /// Only the `seq` of a message that did not parse, to quote it in the error.
    #[derive(serde::Deserialize)]
    struct SeqOnly {
        #[serde(default)]
        seq: Option<u64>,
    }
    let seq_of = |text: &str| serde_json::from_str::<SeqOnly>(text).ok().and_then(|s| s.seq);
    loop {
        let frame = match tokio::time::timeout(idle, stream.next()).await {
            Err(_) => {
                let _ = out.try_send(Outbound::Close { code: close::IDLE, reason: "no messages" });
                break;
            }
            Ok(None | Some(Err(_))) => break,
            Ok(Some(Ok(frame))) => frame,
        };
        let received_at = Instant::now();
        let text = match frame {
            Message::Text(text) => text,
            Message::Binary(_) => {
                reply(ErrorMessage::new("bad_message", "Send JSON text frames; binary frames are not used."));
                continue;
            }
            Message::Ping(_) | Message::Pong(_) => continue,
            Message::Close(_) => break,
        };
        if !bucket.take(received_at) {
            if last_limit_notice.is_none_or(|at| received_at.saturating_duration_since(at) >= Duration::from_secs(1)) {
                last_limit_notice = Some(received_at);
                reply(ErrorMessage::new("rate_limited", "Too many messages; some were dropped.").retry().in_reply_to(seq_of(text.as_str())));
            }
            continue;
        }
        // Parse errors are answered with a fixed sentence: the parser's own message can quote the text.
        let Ok(ClientEnvelope { seq, message }) = serde_json::from_str::<ClientEnvelope>(text.as_str()) else {
            reply(ErrorMessage::new("bad_message", "The message is not JSON of a known type.").in_reply_to(seq_of(text.as_str())));
            continue;
        };
        if let Err(reason) = message.validate(limits) {
            reply(ErrorMessage::new("bad_message", reason).in_reply_to(seq));
            continue;
        }
        if inbound.send(Inbound { message, seq, received_at }).await.is_err() {
            break;
        }
    }
}

/// Serves until `shutdown` completes, then closes every session's connection (1012) and waits briefly for them.
pub async fn serve(
    listener: tokio::net::TcpListener,
    state: AppState,
    shutdown: impl std::future::Future<Output = ()> + Send + 'static,
) -> std::io::Result<()> {
    let registry = state.registry.clone();
    let sweeper = {
        let registry = registry.clone();
        tokio::spawn(async move {
            let mut every = tokio::time::interval(Duration::from_secs(600));
            loop {
                every.tick().await;
                registry.sweep(Duration::from_secs(24 * 3600));
            }
        })
    };
    let closing = registry.clone();
    // No Nagle: a suggestion is many small frames (start, then a token a word), and a frame held back until the last
    // one is acknowledged costs tens of milliseconds.
    let listener = listener.tap_io(|tcp| {
        let _ = tcp.set_nodelay(true);
    });
    let result = axum::serve(listener, router(state))
        .with_graceful_shutdown(async move {
            shutdown.await;
            closing.shutdown();
        })
        .await;
    let deadline = Instant::now() + Duration::from_secs(5);
    while !registry.is_empty() && Instant::now() < deadline {
        tokio::time::sleep(Duration::from_millis(50)).await;
    }
    sweeper.abort();
    result
}
