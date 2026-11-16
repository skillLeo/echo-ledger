//! Shared test helpers: tokens, an in-process relay on mocked models, a session driven through its channels, and a
//! WebSocket client.

#![allow(dead_code)]

use std::collections::BTreeMap;
use std::net::SocketAddr;
use std::sync::Arc;
use std::time::Duration;

use assist_relay::auth::{AUDIENCE, BudgetClaim, Claims, TokenSigner};
use assist_relay::config::{RelayConfig, Secret, Shared};
use assist_relay::domain::{Mode, Plan, ProviderKind};
use assist_relay::internal::{ReportRecorder, ReportSink};
use assist_relay::pack::Pack;
use assist_relay::protocol::{ClientMessage, ServerMessage};
use assist_relay::provider::{MockProvider, MockStats, Providers};
use assist_relay::server::AppState;
use assist_relay::session::{Inbound, Outbound};
use assist_relay::telemetry::{Recorder, TelemetrySink};
use assist_relay::time::unix_now;
use futures_util::{SinkExt, StreamExt};
use serde_json::{Value, json};
use tokio::net::TcpStream;
use tokio::sync::mpsc;
use tokio::time::Instant;
use tokio_tungstenite::tungstenite::client::IntoClientRequest;
use tokio_tungstenite::tungstenite::{self, Message};
use tokio_tungstenite::{MaybeTlsStream, WebSocketStream};

pub const SECRET: &str = "relay-secret-for-tests-only-0123456789-abcdef";

pub const ALL_MODELS: [&str; 4] = ["claude-haiku-5-5", "claude-sonnet-5-5", "claude-opus-5-5", "openai/gpt-oss-120b"];

/// An Ultra token for `session`, valid for ten minutes, with plenty of budget.
pub fn claims(session: &str) -> Claims {
    let now = unix_now();
    Claims {
        iss: "echoledger".into(),
        aud: AUDIENCE.into(),
        sub: "user-1".into(),
        ws: Some("ws-1".into()),
        sid: session.into(),
        plan: Plan::Ultra,
        modes: Mode::LADDER.to_vec(),
        models: ALL_MODELS.iter().map(|m| (*m).to_owned()).collect(),
        budget: BudgetClaim { seconds_left: 36_000, cents_left: 10_000 },
        practice: false,
        iat: now,
        exp: now + 600,
        jti: Some(format!("jti-{session}-{now}")),
    }
}

pub fn sign(claims: &Claims) -> String {
    TokenSigner::new(Secret::new(SECRET)).sign(claims)
}

/// A relay on mocked models, not listening (sessions are driven through the registry).
pub struct Harness {
    pub state: AppState,
    pub mocks: BTreeMap<ProviderKind, Arc<MockProvider>>,
    pub stats: Arc<MockStats>,
    pub telemetry: Recorder,
    pub reports: ReportRecorder,
}

pub fn harness(config: RelayConfig) -> Harness {
    harness_with(config, Arc::new(Recorder::default()) as Arc<dyn TelemetrySink>)
}

pub fn harness_with(config: RelayConfig, telemetry: Arc<dyn TelemetrySink>) -> Harness {
    let stats = Arc::new(MockStats::default());
    let (providers, mocks) = Providers::mock(&config, stats.clone());
    let recorder = Recorder::default();
    let reports = ReportRecorder::default();
    let sinks: Arc<dyn TelemetrySink> =
        Arc::new(assist_relay::telemetry::Fanout(vec![telemetry, Arc::new(recorder.clone()) as Arc<dyn TelemetrySink>]));
    let state = assist_relay::app_state(assist_relay::Parts {
        config: Arc::new(Shared::new(config)),
        pack: Pack::built_in(),
        providers,
        telemetry: sinks,
        reports: Arc::new(reports.clone()) as Arc<dyn ReportSink>,
        secrets: vec![Secret::new(SECRET)],
        issuer: "echoledger".into(),
    });
    Harness { state, mocks, stats, telemetry: recorder, reports }
}

/// One session's connection, driven through channels.
pub struct Driver {
    pub out: mpsc::Receiver<Outbound>,
    pub inbound: mpsc::Sender<Inbound>,
    seq: u64,
}

impl Harness {
    pub fn connect(&self, claims: Claims) -> Driver {
        let (out_tx, out) = mpsc::channel(4096);
        let (inbound, in_rx) = mpsc::channel(256);
        self.state.registry.connect(claims, out_tx, in_rx).expect("connect");
        Driver { out, inbound, seq: 0 }
    }

    pub fn mock(&self, kind: ProviderKind) -> &Arc<MockProvider> {
        &self.mocks[&kind]
    }
}

impl Driver {
    pub async fn send(&mut self, message: Value) {
        self.seq += 1;
        let message: ClientMessage = serde_json::from_value(message).expect("a valid message");
        self.inbound.send(Inbound { message, seq: Some(self.seq), received_at: Instant::now() }).await.expect("session alive");
    }

    /// The next message, as JSON (closes come back as `{"type": "close", "code": …}`).
    pub async fn next(&mut self) -> Value {
        self.next_within(Duration::from_secs(30)).await.expect("a message")
    }

    pub async fn next_within(&mut self, wait: Duration) -> Option<Value> {
        match tokio::time::timeout(wait, self.out.recv()).await {
            Ok(Some(Outbound::Message(message))) => Some(to_json(&message)),
            Ok(Some(Outbound::Close { code, reason })) => Some(json!({"type": "close", "code": code, "reason": reason})),
            Ok(None) | Err(_) => None,
        }
    }

    /// Messages until one of `kind` arrives (that one included).
    pub async fn until(&mut self, kind: &str) -> Vec<Value> {
        let mut seen = Vec::new();
        loop {
            let message = self.next().await;
            let done = message["type"] == kind;
            seen.push(message);
            if done {
                return seen;
            }
        }
    }

    /// Everything that arrives within `wait`.
    pub async fn drain(&mut self, wait: Duration) -> Vec<Value> {
        let mut seen = Vec::new();
        while let Some(message) = self.next_within(wait).await {
            seen.push(message);
        }
        seen
    }
}

pub fn to_json(message: &ServerMessage) -> Value {
    serde_json::to_value(message).expect("serializable")
}

pub fn of_type<'a>(messages: &'a [Value], kind: &str) -> Vec<&'a Value> {
    messages.iter().filter(|m| m["type"] == kind).collect()
}

/// The text of a suggestion, from its `token` messages.
pub fn text_of(messages: &[Value], suggestion_id: &str) -> String {
    messages
        .iter()
        .filter(|m| m["type"] == "token" && m["suggestion_id"] == suggestion_id)
        .map(|m| m["text"].as_str().unwrap_or_default())
        .collect()
}

pub fn partial(turn: &str, text: &str, silence_ms: u64) -> Value {
    json!({"type": "turn_partial", "turn_id": turn, "channel": "other", "text": text, "language": "en", "silence_ms": silence_ms})
}

pub fn final_turn(turn: &str, channel: &str, text: &str) -> Value {
    json!({"type": "turn_final", "turn_id": turn, "channel": channel, "text": text, "language": "en", "start_ms": 1000, "end_ms": 4000})
}

/// A relay listening on 127.0.0.1 (for the WebSocket and HTTP tests).
pub struct Listening {
    pub harness: Harness,
    pub addr: SocketAddr,
}

pub async fn listen(config: RelayConfig) -> Listening {
    listen_with(harness(config)).await
}

pub async fn listen_with(harness: Harness) -> Listening {
    let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.expect("bind");
    let addr = listener.local_addr().expect("address");
    let state = harness.state.clone();
    tokio::spawn(async move {
        let _ = assist_relay::server::serve(listener, state, std::future::pending()).await;
    });
    Listening { harness, addr }
}

pub type Socket = WebSocketStream<MaybeTlsStream<TcpStream>>;

/// Opens the WebSocket with `Authorization: Bearer <token>` (or without the header when `token` is `None`).
pub async fn open(addr: SocketAddr, token: Option<&str>) -> Result<Socket, tungstenite::Error> {
    let mut request = format!("ws://{addr}/v1/assist").into_client_request()?;
    if let Some(token) = token {
        request.headers_mut().insert("authorization", format!("Bearer {token}").parse().expect("header"));
    }
    tokio_tungstenite::connect_async(request).await.map(|(socket, _)| socket)
}

pub async fn send(socket: &mut Socket, message: Value) {
    socket.send(Message::Text(message.to_string().into())).await.expect("send");
}

/// The next text message as JSON; a close frame as `{"type": "close", "code": …}`.
pub async fn receive(socket: &mut Socket) -> Value {
    loop {
        let frame = tokio::time::timeout(Duration::from_secs(30), socket.next()).await.expect("in time");
        match frame {
            Some(Ok(Message::Text(text))) => return serde_json::from_str(text.as_str()).expect("json"),
            Some(Ok(Message::Close(frame))) => {
                return json!({"type": "close", "code": frame.map(|f| u16::from(f.code)).unwrap_or(1005)});
            }
            Some(Ok(_)) => {}
            Some(Err(_)) | None => return json!({"type": "close", "code": 1006}),
        }
    }
}

pub async fn receive_until(socket: &mut Socket, kind: &str) -> Vec<Value> {
    let mut seen = Vec::new();
    loop {
        let message = receive(socket).await;
        let done = message["type"] == kind || message["type"] == "close";
        seen.push(message);
        if done {
            return seen;
        }
    }
}
