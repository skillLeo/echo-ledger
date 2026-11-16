//! The Live Assist relay client (docs/API_ASSIST.md 1.9.0-c) against a local mock relay: the Bearer token and
//! TCP_NODELAY, the first `ready`, messages with their `seq`, the token refresh before expiry, the close codes,
//! reconnecting with pauses and giving up, and the `live_assist` gate.

use std::sync::{Arc, Mutex};
use std::time::Duration;

use chrono::Utc;
use futures_util::{SinkExt, StreamExt};
use serde_json::{Value, json};
use tokio::net::TcpListener;
use tokio_tungstenite::tungstenite::Message;
use tokio_tungstenite::tungstenite::handshake::server::{ErrorResponse, Request, Response};
use tokio_tungstenite::tungstenite::protocol::CloseFrame;
use tokio_tungstenite::tungstenite::protocol::frame::coding::CloseCode;

use desktop_lib::assist::client::{self, AssistEvent, EndReason, Policy, Refresh, RelayGrant};
use desktop_lib::assist::protocol::{Channel, ClientMessage, ServerMessage, Turn};

type Ws = tokio_tungstenite::WebSocketStream<tokio::net::TcpStream>;

/// What the mock relay saw on one connection.
#[derive(Clone, Debug, Default)]
struct Seen {
    authorization: String,
}

/// A mock relay: each accepted connection gets its index and its Authorization header; `refuse` answers some with an
/// HTTP status before the upgrade, `serve` runs the rest.
struct Relay {
    url: String,
    seen: Arc<Mutex<Vec<Seen>>>,
}

impl Relay {
    async fn start<F, Fut>(refuse: fn(usize) -> Option<u16>, serve: F) -> Relay
    where
        F: Fn(usize, Ws) -> Fut + Send + Sync + 'static,
        Fut: std::future::Future<Output = ()> + Send + 'static,
    {
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let url = format!("ws://127.0.0.1:{}/v1/assist", listener.local_addr().unwrap().port());
        let seen: Arc<Mutex<Vec<Seen>>> = Arc::default();
        let log = seen.clone();
        let serve = Arc::new(serve);
        tokio::spawn(async move {
            let mut index = 0;
            while let Ok((tcp, _)) = listener.accept().await {
                let this = index;
                index += 1;
                let log = log.clone();
                let serve = serve.clone();
                tokio::spawn(async move {
                    // The handshake callback's type is tungstenite's own.
                    #[allow(clippy::result_large_err)]
                    let callback = |request: &Request, response: Response| -> Result<Response, ErrorResponse> {
                        let authorization = request.headers().get("authorization").and_then(|v| v.to_str().ok()).unwrap_or("").to_string();
                        log.lock().unwrap().push(Seen { authorization });
                        match refuse(this) {
                            Some(status) => {
                                let mut refused = ErrorResponse::new(Some(r#"{"code":"token_expired"}"#.into()));
                                *refused.status_mut() = status.try_into().unwrap();
                                Err(refused)
                            }
                            None => Ok(response),
                        }
                    };
                    if let Ok(ws) = tokio_tungstenite::accept_hdr_async(tcp, callback).await {
                        serve(this, ws).await;
                    }
                });
            }
        });
        Relay { url, seen }
    }

    fn connections(&self) -> Vec<Seen> {
        self.seen.lock().unwrap().clone()
    }
}

fn never(_: usize) -> Option<u16> {
    None
}

fn ready(resumed: bool) -> Message {
    Message::Text(json!({"type": "ready", "seq": 1, "protocol": 1, "session_id": "01JS", "mode": "balanced", "requested_mode": "balanced", "allowed_modes": ["balanced", "fast"], "expires_at": "x", "resumed": resumed, "practice": false}).to_string().into())
}

async fn close_with(ws: &mut Ws, code: u16) {
    let _ = ws.send(Message::Close(Some(CloseFrame { code: CloseCode::from(code), reason: "".into() }))).await;
    // Wait for the app's answer to the close, as a relay does.
    while let Some(Ok(_)) = ws.next().await {}
}

/// The next text frame from the app, as JSON.
async fn next_json(ws: &mut Ws) -> Value {
    loop {
        match ws.next().await {
            Some(Ok(Message::Text(text))) => return serde_json::from_str(text.as_str()).unwrap(),
            Some(Ok(_)) => continue,
            other => panic!("the app closed: {other:?}"),
        }
    }
}

fn grant(url: &str, token: &str, expires_in: chrono::Duration) -> RelayGrant {
    RelayGrant { url: url.to_string(), token: token.to_string(), expires_at: Utc::now() + expires_in }
}

/// A refresh that hands out `t2`, `t3`… for the same relay and counts its calls.
fn refresher(url: &str) -> (Refresh, Arc<Mutex<u32>>) {
    let calls: Arc<Mutex<u32>> = Arc::default();
    let (url, counter) = (url.to_string(), calls.clone());
    let refresh: Refresh = Arc::new(move || {
        let mut n = counter.lock().unwrap();
        *n += 1;
        let next = grant(&url, &format!("t{}", *n + 1), chrono::Duration::minutes(15));
        Box::pin(async move { Ok(next) })
    });
    (refresh, calls)
}

fn quick() -> Policy {
    Policy { backoff: vec![Duration::from_millis(20), Duration::from_millis(40)], give_up_after: Duration::from_secs(5), refresh_early: Duration::from_secs(120), refresh_retry: Duration::from_millis(50) }
}

#[derive(Clone, Default)]
struct Events(Arc<Mutex<Vec<AssistEvent>>>);

impl Events {
    fn sink(&self) -> impl Fn(AssistEvent) + Send + Sync + 'static {
        let events = self.0.clone();
        move |event| events.lock().unwrap().push(event)
    }

    fn all(&self) -> Vec<AssistEvent> {
        self.0.lock().unwrap().clone()
    }

    async fn wait_for(&self, what: &str, test: impl Fn(&AssistEvent) -> bool) -> AssistEvent {
        for _ in 0..300 {
            if let Some(event) = self.all().into_iter().find(|e| test(e)) {
                return event;
            }
            tokio::time::sleep(Duration::from_millis(10)).await;
        }
        panic!("no {what}: {:?}", self.all());
    }
}

fn turn(text: &str) -> ClientMessage {
    ClientMessage::TurnFinal(Turn { turn_id: "o-1".into(), channel: Channel::Other, text: text.into(), language: Some("en".into()), start_ms: Some(0), end_ms: Some(900), silence_ms: None })
}

#[tokio::test]
async fn it_connects_with_the_bearer_token_and_tcp_nodelay() {
    let relay = Relay::start(never, |_, mut ws: Ws| async move {
        let _ = ws.send(ready(false)).await;
        while let Some(Ok(_)) = ws.next().await {}
    })
    .await;
    let socket = client::connect(&grant(&relay.url, "t1", chrono::Duration::minutes(15))).await.unwrap();
    assert!(client::nodelay(&socket), "TCP_NODELAY on the relay's socket");
    assert_eq!(relay.connections()[0].authorization, "Bearer t1");
    // Only wss, or ws to this computer.
    let remote = client::connect(&grant("ws://relay.example/v1/assist", "t1", chrono::Duration::minutes(15))).await;
    assert!(matches!(remote, Err(client::ConnectError::BadUrl(_))));
}

#[tokio::test]
async fn messages_go_out_with_their_seq_and_a_stop_closes_normally() {
    let got: Arc<Mutex<Vec<Value>>> = Arc::default();
    let log = got.clone();
    let relay = Relay::start(never, move |_, mut ws: Ws| {
        let log = log.clone();
        async move {
            let _ = ws.send(ready(false)).await;
            let _ = ws.send(Message::Text(json!({"type": "suggestion_start", "seq": 2, "suggestion_id": "s1", "kind": "answer", "mode": "balanced", "model": "m"}).to_string().into())).await;
            let _ = ws.send(Message::Text(json!({"type": "token", "seq": 3, "suggestion_id": "s1", "text": "Yes, "}).to_string().into())).await;
            let _ = ws.send(Message::Text(json!({"type": "a_new_kind", "seq": 4}).to_string().into())).await;
            loop {
                match ws.next().await {
                    Some(Ok(Message::Text(text))) => log.lock().unwrap().push(serde_json::from_str(text.as_str()).unwrap()),
                    Some(Ok(Message::Close(frame))) => {
                        log.lock().unwrap().push(json!({"closed": frame.map(|f| u16::from(f.code))}));
                        break;
                    }
                    Some(Ok(_)) => {}
                    _ => break,
                }
            }
        }
    })
    .await;
    let (refresh, _) = refresher(&relay.url);
    let events = Events::default();
    let session = client::spawn(grant(&relay.url, "t1", chrono::Duration::minutes(15)), refresh, quick(), events.sink());
    events.wait_for("ready", |e| matches!(e, AssistEvent::Connected { resumed: false, mode } if mode == "balanced")).await;
    events.wait_for("a token", |e| matches!(e, AssistEvent::Message(ServerMessage::Token(t)) if t.text == "Yes, ")).await;
    session.send(turn("What is your notice period?"));
    session.send(ClientMessage::HelpNow { turn_id: None });
    tokio::time::sleep(Duration::from_millis(100)).await;
    session.stop();
    events.wait_for("the end", |e| *e == AssistEvent::Ended(EndReason::Closed)).await;
    tokio::time::sleep(Duration::from_millis(50)).await;
    let got = got.lock().unwrap().clone();
    assert_eq!(got[0]["type"], "turn_final");
    assert_eq!(got[0]["seq"], 1);
    assert_eq!(got[0]["text"], "What is your notice period?");
    assert_eq!(got[1], json!({"type": "help_now", "seq": 2}));
    assert_eq!(got[2], json!({"closed": 1000}));
    assert!(!events.all().iter().any(|e| matches!(e, AssistEvent::Message(ServerMessage::Unknown))), "an unknown type is ignored");
}

#[tokio::test]
async fn the_token_is_refreshed_before_it_expires() {
    let got: Arc<Mutex<Vec<Value>>> = Arc::default();
    let log = got.clone();
    let relay = Relay::start(never, move |_, mut ws: Ws| {
        let log = log.clone();
        async move {
            let _ = ws.send(ready(false)).await;
            let refresh = next_json(&mut ws).await;
            log.lock().unwrap().push(refresh);
            let _ = ws.send(Message::Text(json!({"type": "token_refreshed", "seq": 2, "expires_at": "later"}).to_string().into())).await;
            while let Some(Ok(_)) = ws.next().await {}
        }
    })
    .await;
    let (refresh, calls) = refresher(&relay.url);
    let events = Events::default();
    // Refreshed 2 s before expiry here (2 minutes in the app): this token expires in 2.3 s.
    let policy = Policy { refresh_early: Duration::from_secs(2), ..quick() };
    let session = client::spawn(grant(&relay.url, "t1", chrono::Duration::milliseconds(2_300)), refresh, policy, events.sink());
    events.wait_for("token_refreshed", |e| matches!(e, AssistEvent::Message(ServerMessage::TokenRefreshed { .. }))).await;
    assert_eq!(*calls.lock().unwrap(), 1);
    assert_eq!(got.lock().unwrap()[0], json!({"type": "token_refresh", "seq": 1, "token": "t2"}));
    assert_eq!(relay.connections().len(), 1, "refreshed on the open socket, no reconnect");
    session.stop();
}

#[tokio::test]
async fn a_restart_or_a_dropped_connection_reconnects_and_resumes() {
    let relay = Relay::start(never, |index, mut ws: Ws| async move {
        let _ = ws.send(ready(index > 0)).await;
        if index == 0 {
            close_with(&mut ws, 1012).await;
        } else {
            while let Some(Ok(_)) = ws.next().await {}
        }
    })
    .await;
    let (refresh, calls) = refresher(&relay.url);
    let events = Events::default();
    let session = client::spawn(grant(&relay.url, "t1", chrono::Duration::minutes(15)), refresh, quick(), events.sink());
    events.wait_for("the resumed session", |e| matches!(e, AssistEvent::Connected { resumed: true, .. })).await;
    let reconnecting = events.wait_for("the pause", |e| matches!(e, AssistEvent::Reconnecting { .. })).await;
    assert_eq!(reconnecting, AssistEvent::Reconnecting { attempt: 1, after: Duration::from_millis(20), why: "connection".into() });
    assert_eq!(relay.connections().iter().map(|c| c.authorization.clone()).collect::<Vec<_>>(), ["Bearer t1", "Bearer t1"]);
    assert_eq!(*calls.lock().unwrap(), 0, "the same token while it is valid");
    session.stop();
}

#[tokio::test]
async fn an_expired_token_reconnects_at_once_with_a_new_one() {
    // 4401 after the upgrade, and 401 before it: both get a new token first.
    for refuse_first in [false, true] {
        let refuse: fn(usize) -> Option<u16> = if refuse_first { |i| (i == 0).then_some(401) } else { never };
        let relay = Relay::start(refuse, move |index, mut ws: Ws| async move {
            let _ = ws.send(ready(index > 0)).await;
            if index == 0 {
                let _ = ws.send(Message::Text(json!({"type": "error", "seq": 2, "code": "token_expired", "message": "The token expired.", "retry": false, "fatal": true}).to_string().into())).await;
                close_with(&mut ws, 4401).await;
            } else {
                while let Some(Ok(_)) = ws.next().await {}
            }
        })
        .await;
        let (refresh, calls) = refresher(&relay.url);
        let events = Events::default();
        let session = client::spawn(grant(&relay.url, "t1", chrono::Duration::minutes(15)), refresh, quick(), events.sink());
        events.wait_for("the new connection", |e| matches!(e, AssistEvent::Connected { resumed: true, .. })).await;
        assert_eq!(*calls.lock().unwrap(), 1, "refuse_first {refuse_first}");
        assert_eq!(relay.connections().last().unwrap().authorization, "Bearer t2");
        assert!(events.all().contains(&AssistEvent::Reconnecting { attempt: 0, after: Duration::ZERO, why: "token".into() }));
        session.stop();
    }
}

#[tokio::test]
async fn a_newer_connection_another_person_s_session_or_a_normal_close_end_it() {
    for (code, reason) in [(4409, EndReason::Replaced), (4403, EndReason::WrongSession), (1000, EndReason::Closed)] {
        let relay = Relay::start(never, move |_, mut ws: Ws| async move {
            let _ = ws.send(ready(false)).await;
            close_with(&mut ws, code).await;
        })
        .await;
        let (refresh, _) = refresher(&relay.url);
        let events = Events::default();
        let _session = client::spawn(grant(&relay.url, "t1", chrono::Duration::minutes(15)), refresh, quick(), events.sink());
        events.wait_for("the end", |e| matches!(e, AssistEvent::Ended(_))).await;
        tokio::time::sleep(Duration::from_millis(150)).await;
        assert_eq!(events.all().last(), Some(&AssistEvent::Ended(reason.clone())), "close {code}");
        assert_eq!(relay.connections().len(), 1, "no reconnect after {code}");
    }
    // 403 before the upgrade: another person's session.
    let relay = Relay::start(|_| Some(403), |_, _ws: Ws| async move {}).await;
    let (refresh, _) = refresher(&relay.url);
    let events = Events::default();
    let _session = client::spawn(grant(&relay.url, "t1", chrono::Duration::minutes(15)), refresh, quick(), events.sink());
    events.wait_for("the end", |e| *e == AssistEvent::Ended(EndReason::WrongSession)).await;
}

#[tokio::test]
async fn without_a_relay_it_waits_longer_each_time_then_gives_up() {
    // A port where nothing listens.
    let port = TcpListener::bind("127.0.0.1:0").await.unwrap().local_addr().unwrap().port();
    let url = format!("ws://127.0.0.1:{port}/v1/assist");
    let (refresh, _) = refresher(&url);
    let events = Events::default();
    let policy = Policy { give_up_after: Duration::from_millis(400), ..quick() };
    // Dropping the handle would end the session: it is kept.
    let _session = client::spawn(grant(&url, "t1", chrono::Duration::minutes(15)), refresh, policy, events.sink());
    events.wait_for("giving up", |e| matches!(e, AssistEvent::Ended(EndReason::GaveUp(_)))).await;
    let pauses: Vec<(u32, Duration)> = events.all().iter().filter_map(|e| match e {
        AssistEvent::Reconnecting { attempt, after, .. } => Some((*attempt, *after)),
        _ => None,
    }).collect();
    assert!(pauses.len() >= 3, "{pauses:?}");
    assert_eq!(pauses[0], (1, Duration::from_millis(20)));
    assert_eq!(pauses[1], (2, Duration::from_millis(40)));
    assert_eq!(pauses[2], (3, Duration::from_millis(40)), "the last pause repeats");
}

#[tokio::test]
async fn what_the_app_sends_while_the_socket_is_down_goes_out_once_it_is_back() {
    let got: Arc<Mutex<Vec<Value>>> = Arc::default();
    let log = got.clone();
    let relay = Relay::start(never, move |index, mut ws: Ws| {
        let log = log.clone();
        async move {
            let _ = ws.send(ready(index > 0)).await;
            if index == 0 {
                close_with(&mut ws, 4408).await;
                return;
            }
            let frame = next_json(&mut ws).await;
            log.lock().unwrap().push(frame);
            while let Some(Ok(_)) = ws.next().await {}
        }
    })
    .await;
    let (refresh, _) = refresher(&relay.url);
    let events = Events::default();
    let policy = Policy { backoff: vec![Duration::from_millis(300)], ..quick() };
    let session = client::spawn(grant(&relay.url, "t1", chrono::Duration::minutes(15)), refresh, policy, events.sink());
    events.wait_for("the pause", |e| matches!(e, AssistEvent::Reconnecting { .. })).await;
    session.send(turn("Said while the socket was down"));
    events.wait_for("the resumed session", |e| matches!(e, AssistEvent::Connected { resumed: true, .. })).await;
    for _ in 0..100 {
        if !got.lock().unwrap().is_empty() {
            break;
        }
        tokio::time::sleep(Duration::from_millis(10)).await;
    }
    assert_eq!(got.lock().unwrap()[0]["text"], "Said while the socket was down");
    session.stop();
}

#[tokio::test]
async fn nothing_starts_while_the_live_assist_flag_is_off() {
    let off = desktop_lib::ui_flags::UiFlags::read(Some(&json!({"live_assist": false, "ui_v2.home": true})), false);
    let on = desktop_lib::ui_flags::UiFlags::read(Some(&json!({"live_assist": true})), false);
    assert!(!desktop_lib::assist::enabled(&off));
    assert!(desktop_lib::assist::enabled(&on));
    let (refresh, _) = refresher("ws://127.0.0.1:9/v1/assist");
    let started = desktop_lib::assist::start(&off, grant("ws://127.0.0.1:9/v1/assist", "t1", chrono::Duration::minutes(15)), refresh, |_| {});
    assert!(started.is_none());
}
