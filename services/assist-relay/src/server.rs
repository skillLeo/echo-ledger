//! HTTP: `GET /healthz`, and `GET /v1/assist/sessions/{session_id}/ws`, the WebSocket. The assist token is checked
//! before the upgrade (`Authorization: Bearer <token>`), so an unauthenticated socket never opens.

use std::sync::Arc;
use std::time::Duration;

use axum::Router;
use axum::extract::ws::rejection::WebSocketUpgradeRejection;
use axum::extract::ws::{CloseFrame, Message, WebSocket, WebSocketUpgrade};
use axum::extract::{Path, State};
use axum::http::{HeaderMap, StatusCode, header};
use axum::response::{IntoResponse, Response};
use axum::routing::get;
use futures_util::stream::{SplitSink, SplitStream};
use futures_util::{SinkExt, StreamExt};
use serde::Serialize;
use tokio::sync::mpsc;
use tokio::time::Instant;

use crate::auth::{AuthError, TokenVerifier};
use crate::config::{Limits, RelayConfig};
use crate::protocol::{ClientMessage, ErrorMessage, ServerMessage};
use crate::registry::Registry;
use crate::session::{Inbound, Outbound, close};
use crate::telemetry::{SessionEvent, TelemetrySink};
use crate::time::unix_now;

/// Messages waiting for the socket writer; a client that falls this far behind is dropped.
const OUT_CAPACITY: usize = 512;
const IN_CAPACITY: usize = 256;

#[derive(Debug, Clone)]
pub struct AppState {
    pub registry: Arc<Registry>,
    pub verifier: Arc<TokenVerifier>,
    pub config: Arc<RelayConfig>,
    pub telemetry: Arc<dyn TelemetrySink>,
}

pub fn router(state: AppState) -> Router {
    Router::new()
        .route("/healthz", get(health))
        .route("/v1/assist/sessions/{session_id}/ws", get(connect))
        .fallback(not_found)
        .with_state(state)
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

fn bearer(headers: &HeaderMap) -> &str {
    headers
        .get(header::AUTHORIZATION)
        .and_then(|value| value.to_str().ok())
        .and_then(|value| value.strip_prefix("Bearer "))
        .map_or("", str::trim)
}

async fn connect(
    State(state): State<AppState>,
    Path(session_id): Path<String>,
    headers: HeaderMap,
    upgrade: Result<WebSocketUpgrade, WebSocketUpgradeRejection>,
) -> Response {
    let claims = match state.verifier.verify_for_session(bearer(&headers), &session_id, unix_now()) {
        Ok(claims) => claims,
        Err(error) => return reject(&state, error),
    };
    if state.registry.owner(&session_id).is_some_and(|owner| owner != claims.sub) {
        return reject(&state, AuthError::WrongSession);
    }
    let Ok(upgrade) = upgrade else {
        return http_error(StatusCode::BAD_REQUEST, "websocket_required", "Connect with a WebSocket.");
    };
    let limits = state.config.limits.clone();
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

async fn run_socket(socket: WebSocket, state: AppState, claims: crate::auth::Claims) {
    let (sink, stream) = socket.split();
    let (out_tx, out_rx) = mpsc::channel::<Outbound>(OUT_CAPACITY);
    let (in_tx, in_rx) = mpsc::channel::<Inbound>(IN_CAPACITY);
    let session = &state.config.session;
    let ping_every = Duration::from_secs(session.ping_interval_secs);
    let idle = Duration::from_secs(session.idle_timeout_secs);
    if state.registry.connect(claims, out_tx.clone(), in_rx).is_err() {
        let _ = out_tx.try_send(Outbound::Close { code: close::WRONG_SESSION, reason: "this session belongs to someone else" });
    }
    let writer = tokio::spawn(write_loop(sink, out_rx, ping_every));
    read_loop(stream, in_tx, out_tx, &state.config.limits, idle).await;
    let _ = writer.await;
}

async fn write_loop(mut sink: SplitSink<WebSocket, Message>, mut out: mpsc::Receiver<Outbound>, ping_every: Duration) {
    let mut ping = tokio::time::interval_at(Instant::now() + ping_every, ping_every);
    loop {
        tokio::select! {
            next = out.recv() => match next {
                Some(Outbound::Message(message)) => {
                    let Ok(text) = serde_json::to_string(&message) else { continue };
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
                reply(ErrorMessage::new("rate_limited", "Too many messages; some were dropped."));
            }
            continue;
        }
        // Parse errors are answered with a fixed sentence: the parser's own message can quote the text.
        let Ok(message) = serde_json::from_str::<ClientMessage>(text.as_str()) else {
            reply(ErrorMessage::new("bad_message", "The message is not JSON of a known type."));
            continue;
        };
        if let Err(reason) = message.validate(limits) {
            reply(ErrorMessage::new("bad_message", reason));
            continue;
        }
        if inbound.send(Inbound { message, received_at }).await.is_err() {
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
