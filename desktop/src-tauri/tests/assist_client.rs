//! The Live Assist relay client (docs/API_ASSIST.md 1.9.0-e) against a local mock relay: the Bearer token and
//! TCP_NODELAY, the relay's pinned key, the first `ready`, messages with their `seq` per connection and paced to the
//! relay's rate, the token refresh before expiry, the close codes, reconnecting with pauses and giving up, a refused
//! token, what waits while the socket is down, and the `live_assist` gate.

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
use desktop_lib::assist::protocol::{Brief, Channel, ClientMessage, ContextUpdate, ServerMessage, Settings, Turn};

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
    // Plain ws:// to this computer needs no pins (debug builds and tests only).
    RelayGrant { url: url.to_string(), token: token.to_string(), expires_at: Utc::now() + expires_in, pins: vec![] }
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
async fn a_relay_that_lost_the_session_gets_the_whole_context_again_first() {
    // 0: a fresh session, closed by a restart; 1: the restarted relay lost it (resumed false), restarts again;
    // 2: a resume (resumed true), which needs nothing again.
    let got: Arc<Mutex<Vec<(usize, Value)>>> = Arc::default();
    let log = got.clone();
    let relay = Relay::start(never, move |index, mut ws: Ws| {
        let log = log.clone();
        async move {
            let _ = ws.send(ready(index == 2)).await;
            let wanted = if index == 0 { 2 } else { 1 };
            for _ in 0..wanted {
                let message = next_json(&mut ws).await;
                log.lock().unwrap().push((index, message));
            }
            if index < 2 {
                close_with(&mut ws, 1012).await;
            } else {
                while let Some(Ok(_)) = ws.next().await {}
            }
        }
    })
    .await;
    let (refresh, _) = refresher(&relay.url);
    let events = Events::default();
    let session = client::spawn(grant(&relay.url, "t1", chrono::Duration::minutes(15)), refresh, quick(), events.sink());
    let brief = ContextUpdate { brief: Some(Brief { meeting_type: "interview".into(), title: "Backend role".into(), ..Brief::default() }), ..ContextUpdate::default() };
    assert!(session.send(ClientMessage::ContextUpdate(Box::new(brief))));
    events.wait_for("the first ready", |e| matches!(e, AssistEvent::Connected { resumed: false, .. })).await;
    let settings = ContextUpdate { settings: Some(Settings { mode: Some("fast".into()), ..Settings::default() }), ..ContextUpdate::default() };
    assert!(session.send(ClientMessage::ContextUpdate(Box::new(settings))));
    events.wait_for("the second connection", |e| matches!(e, AssistEvent::Reconnecting { .. })).await;
    events.wait_for("the resume", |e| matches!(e, AssistEvent::Connected { resumed: true, .. })).await;
    assert!(session.send(turn("Hello?")));
    for _ in 0..300 {
        if got.lock().unwrap().len() == 4 {
            break;
        }
        tokio::time::sleep(Duration::from_millis(10)).await;
    }
    let got = got.lock().unwrap().clone();
    assert_eq!(got[0], (0, json!({"type": "context_update", "seq": 1, "brief": {"meeting_type": "interview", "title": "Backend role"}})));
    assert_eq!(got[1], (0, json!({"type": "context_update", "seq": 2, "settings": {"mode": "fast"}})));
    assert_eq!(
        got[2],
        (1, json!({"type": "context_update", "seq": 1, "brief": {"meeting_type": "interview", "title": "Backend role"}, "settings": {"mode": "fast"}})),
        "a lost session gets everything given so far, merged, before anything else"
    );
    assert_eq!((got[3].0, got[3].1["type"].as_str()), (2, Some("turn_final")), "a resumed session needs nothing again");
    session.stop();
}

#[tokio::test]
async fn a_connection_dropped_without_a_close_frame_reconnects_and_resumes() {
    // A reader too slow for the relay is dropped with no close frame (the app sees 1006): reconnect and resume.
    let relay = Relay::start(never, |index, mut ws: Ws| async move {
        let _ = ws.send(ready(index > 0)).await;
        if index == 0 {
            drop(ws);
        } else {
            while let Some(Ok(_)) = ws.next().await {}
        }
    })
    .await;
    let (refresh, calls) = refresher(&relay.url);
    let events = Events::default();
    let session = client::spawn(grant(&relay.url, "t1", chrono::Duration::minutes(15)), refresh, quick(), events.sink());
    events.wait_for("the resumed session", |e| matches!(e, AssistEvent::Connected { resumed: true, .. })).await;
    assert!(events.all().iter().any(|e| matches!(e, AssistEvent::Reconnecting { why, .. } if why == "connection")));
    assert!(!events.all().iter().any(|e| matches!(e, AssistEvent::Ended(_))), "the session goes on");
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
                // Something sent on the first connection, so a seq carried over would not start at 1.
                let _ = next_json(&mut ws).await;
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
    events.wait_for("ready", |e| matches!(e, AssistEvent::Connected { resumed: false, .. })).await;
    session.send(ClientMessage::HelpNow { turn_id: None });
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
    assert_eq!(got.lock().unwrap()[0]["seq"], 1, "seq counts per connection, from 1 (1.9.0-d)");
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

fn ready_at(rate: u32, resumed: bool) -> Message {
    Message::Text(json!({"type": "ready", "seq": 1, "protocol": 1, "session_id": "01JS", "mode": "balanced", "allowed_modes": ["balanced"], "resumed": resumed, "practice": false, "limits": {"max_message_bytes": 2097152, "max_turn_chars": 4000, "messages_per_second": rate}}).to_string().into())
}

#[tokio::test]
async fn messages_wait_for_ready_and_go_out_at_the_relay_s_rate() {
    let got: Arc<Mutex<Vec<(Value, std::time::Instant)>>> = Arc::default();
    let log = got.clone();
    let relay = Relay::start(never, move |_, mut ws: Ws| {
        let log = log.clone();
        async move {
            // Ready only after a moment: nothing may arrive before it.
            tokio::time::sleep(Duration::from_millis(150)).await;
            log.lock().unwrap().push((json!({"ready_sent": true}), std::time::Instant::now()));
            let _ = ws.send(ready_at(10, false)).await;
            for _ in 0..4 {
                let frame = next_json(&mut ws).await;
                log.lock().unwrap().push((frame, std::time::Instant::now()));
            }
            while let Some(Ok(_)) = ws.next().await {}
        }
    })
    .await;
    let (refresh, _) = refresher(&relay.url);
    let events = Events::default();
    let session = client::spawn(grant(&relay.url, "t1", chrono::Duration::minutes(15)), refresh, quick(), events.sink());
    for i in 0..4 {
        session.send(turn(&format!("turn {i}")));
    }
    for _ in 0..200 {
        if got.lock().unwrap().len() == 5 {
            break;
        }
        tokio::time::sleep(Duration::from_millis(10)).await;
    }
    let got = got.lock().unwrap().clone();
    assert_eq!(got[0].0, json!({"ready_sent": true}), "nothing before ready");
    assert_eq!(got.iter().skip(1).map(|(v, _)| v["seq"].as_u64().unwrap()).collect::<Vec<_>>(), [1, 2, 3, 4]);
    // 10 a second: 100 ms apart (a little slack below for timer rounding).
    for pair in got[1..].windows(2) {
        assert!(pair[1].1.duration_since(pair[0].1) >= Duration::from_millis(90), "{:?}", pair[1].1.duration_since(pair[0].1));
    }
    session.stop();
}

fn rejected(in_reply_to: u64) -> Message {
    Message::Text(json!({"type": "error", "seq": 2, "code": "token_refresh_rejected", "message": "Refused.", "retry": false, "suggestion_id": null, "in_reply_to": in_reply_to, "fatal": false}).to_string().into())
}

#[tokio::test]
async fn a_new_token_rejected_three_times_is_fetched_again_after_growing_waits() {
    // The relay rejects every token_refresh on the open connection and notes when each arrived.
    let arrived: Arc<Mutex<Vec<std::time::Instant>>> = Arc::default();
    let log = arrived.clone();
    let relay = Relay::start(never, move |_, mut ws: Ws| {
        let log = log.clone();
        async move {
            let _ = ws.send(ready(false)).await;
            loop {
                let message = next_json(&mut ws).await;
                if message["type"] == "token_refresh" {
                    log.lock().unwrap().push(std::time::Instant::now());
                    let _ = ws.send(rejected(message["seq"].as_u64().unwrap())).await;
                }
            }
        }
    })
    .await;
    let (refresh, calls) = refresher(&relay.url);
    let events = Events::default();
    let policy = Policy { backoff: vec![Duration::from_millis(100), Duration::from_millis(300)], give_up_after: Duration::from_millis(900), refresh_early: Duration::from_secs(120), refresh_retry: Duration::from_millis(50) };
    // Due for a refresh at once: the token expires inside the 2 minutes.
    let _session = client::spawn(grant(&relay.url, "t1", chrono::Duration::seconds(60)), refresh, policy, events.sink());
    events.wait_for("the end", |e| matches!(e, AssistEvent::Ended(_))).await;
    assert_eq!(events.all().last(), Some(&AssistEvent::Ended(EndReason::TokenRejected)), "{:?}", events.all());
    let at = arrived.lock().unwrap().clone();
    assert!(at.len() >= 4, "{} refreshes", at.len());
    let gaps: Vec<Duration> = at.windows(2).map(|w| w[1] - w[0]).collect();
    assert!(gaps[0] < Duration::from_millis(90), "the first rejection: another token at once ({:?})", gaps[0]);
    assert!(gaps[1] >= Duration::from_millis(100) && gaps[1] < Duration::from_millis(290), "then the first pause ({:?})", gaps[1]);
    assert!(gaps[2] >= Duration::from_millis(300), "then a longer one ({:?})", gaps[2]);
    assert!(at.len() <= 6, "no tight loop: {} refreshes", at.len());
    assert_eq!(*calls.lock().unwrap() as usize, at.len());
    assert_eq!(relay.connections().len(), 1, "all on one connection");
}

#[tokio::test]
async fn a_token_refused_again_and_again_waits_like_any_reconnect() {
    // Every connection is refused with 401, whatever the token.
    let relay = Relay::start(|_| Some(401), |_, _ws: Ws| async move {}).await;
    let (refresh, calls) = refresher(&relay.url);
    let events = Events::default();
    let policy = Policy { backoff: vec![Duration::from_millis(60)], give_up_after: Duration::from_millis(500), ..quick() };
    let _session = client::spawn(grant(&relay.url, "t1", chrono::Duration::minutes(15)), refresh, policy, events.sink());
    events.wait_for("giving up", |e| matches!(e, AssistEvent::Ended(EndReason::GaveUp(_)))).await;
    let reconnects: Vec<AssistEvent> = events.all().into_iter().filter(|e| matches!(e, AssistEvent::Reconnecting { .. })).collect();
    // The first refusal: a new token and at once; the next ones: a new token and the usual pause.
    assert_eq!(reconnects[0], AssistEvent::Reconnecting { attempt: 0, after: Duration::ZERO, why: "token".into() });
    assert!(reconnects[1..].iter().all(|e| matches!(e, AssistEvent::Reconnecting { attempt, after, .. } if *attempt >= 1 && *after == Duration::from_millis(60))), "{reconnects:?}");
    // About 500 ms at 60 ms a try: a handful of connections, not a tight loop.
    let tries = relay.connections().len();
    assert!((3..=12).contains(&tries), "{tries} connections");
    assert!(*calls.lock().unwrap() as usize <= tries);
}

#[tokio::test]
async fn while_down_only_the_latest_partial_waits_with_every_final() {
    let got: Arc<Mutex<Vec<Value>>> = Arc::default();
    let log = got.clone();
    let relay = Relay::start(never, move |index, mut ws: Ws| {
        let log = log.clone();
        async move {
            let _ = ws.send(ready_at(1000, index > 0)).await;
            if index == 0 {
                close_with(&mut ws, 1012).await;
                return;
            }
            loop {
                match ws.next().await {
                    Some(Ok(Message::Text(text))) => log.lock().unwrap().push(serde_json::from_str(text.as_str()).unwrap()),
                    Some(Ok(_)) => {}
                    _ => break,
                }
            }
        }
    })
    .await;
    let (refresh, _) = refresher(&relay.url);
    let events = Events::default();
    let policy = Policy { backoff: vec![Duration::from_millis(400)], ..quick() };
    let session = client::spawn(grant(&relay.url, "t1", chrono::Duration::minutes(15)), refresh, policy, events.sink());
    events.wait_for("the pause", |e| matches!(e, AssistEvent::Reconnecting { .. })).await;
    let partial = |text: &str| ClientMessage::TurnPartial(Turn { turn_id: "o-2".into(), channel: Channel::Other, text: text.into(), language: None, start_ms: None, end_ms: None, silence_ms: Some(90) });
    session.send(partial("so what"));
    session.send(turn("Final one"));
    session.send(partial("so what is"));
    session.send(ClientMessage::HelpNow { turn_id: None });
    session.send(partial("so what is your"));
    events.wait_for("the resumed session", |e| matches!(e, AssistEvent::Connected { resumed: true, .. })).await;
    for _ in 0..100 {
        if got.lock().unwrap().len() >= 3 {
            break;
        }
        tokio::time::sleep(Duration::from_millis(10)).await;
    }
    tokio::time::sleep(Duration::from_millis(50)).await;
    let got: Vec<String> = got.lock().unwrap().iter().map(|v| format!("{} {}", v["type"].as_str().unwrap(), v["text"].as_str().unwrap_or(""))).collect();
    assert_eq!(got, ["turn_final Final one", "help_now ", "turn_partial so what is your"]);
    session.stop();
}

#[tokio::test]
async fn a_relay_without_the_pinned_key_is_refused_in_plain_words() {
    // A TLS relay on this computer whose key is not the pinned one.
    let _ = rustls::crypto::ring::default_provider().install_default();
    let key = rcgen::KeyPair::generate().unwrap();
    let cert = rcgen::CertificateParams::new(vec!["localhost".to_string()]).unwrap().self_signed(&key).unwrap();
    let config = Arc::new(
        rustls::ServerConfig::builder()
            .with_no_client_auth()
            .with_single_cert(vec![cert.der().clone()], rustls::pki_types::PrivateKeyDer::Pkcs8(rustls::pki_types::PrivatePkcs8KeyDer::from(key.serialize_der())))
            .unwrap(),
    );
    let listener = std::net::TcpListener::bind("127.0.0.1:0").unwrap();
    let port = listener.local_addr().unwrap().port();
    std::thread::spawn(move || {
        for stream in listener.incoming() {
            let Ok(mut tcp) = stream else { break };
            let _ = tcp.set_read_timeout(Some(Duration::from_secs(5)));
            let Ok(mut conn) = rustls::ServerConnection::new(config.clone()) else { continue };
            let _ = conn.complete_io(&mut tcp);
        }
    });
    let other = base64::Engine::encode(&base64::engine::general_purpose::STANDARD, [7u8; 32]);
    let mut wrong = grant(&format!("wss://localhost:{port}/v1/assist"), "t1", chrono::Duration::minutes(15));
    wrong.pins = vec![format!("sha256/{other}")];
    assert_eq!(client::connect(&wrong).await.err(), Some(client::ConnectError::Insecure));
    // No pins at all: refused before any connection.
    let none = RelayGrant { pins: vec![], ..wrong.clone() };
    assert_eq!(client::connect(&none).await.err(), Some(client::ConnectError::Insecure));
    // As a session: it ends in plain words and never retries.
    let (refresh, calls) = refresher(&wrong.url);
    let events = Events::default();
    let _session = client::spawn(wrong, refresh, quick(), events.sink());
    events.wait_for("the refusal", |e| *e == AssistEvent::Ended(EndReason::Insecure)).await;
    assert_eq!(*calls.lock().unwrap(), 0);
    assert_eq!(desktop_lib::net::pinning::RELAY_INSECURE, "Live Assist could not make a secure connection.");
}

fn running(handle: client::AssistHandle) -> desktop_lib::assist::Running {
    use desktop_lib::assist::card::{Budget, Card, TurnBatch};
    desktop_lib::assist::Running {
        id: "01JS".into(),
        handle,
        turns: Default::default(),
        card: Card::new(Some("interview".into()), None),
        batch: TurnBatch::new(std::time::Instant::now()),
        budget: Budget { left_at_start: None, total: None, started: std::time::Instant::now() },
        translate_to: "roman_urdu".into(),
        started_recording: None,
        captions_were_on: true,
        settings: Default::default(),
    }
}

#[tokio::test]
async fn a_running_session_stops_when_it_is_taken_out_with_its_waiting_turns() {
    use desktop_lib::audio::types::Channel as Audio;
    let relay = Relay::start(never, |_, mut ws: Ws| async move {
        let _ = ws.send(ready(false)).await;
        while let Some(Ok(_)) = ws.next().await {}
    })
    .await;
    let (refresh, _) = refresher(&relay.url);
    let events = Events::default();
    let on = desktop_lib::ui_flags::UiFlags::read(Some(&json!({"live_assist": true})), false);
    let registry = desktop_lib::assist::Registry::default();
    registry.begin(running(desktop_lib::assist::start(&on, grant(&relay.url, "t1", chrono::Duration::minutes(15)), refresh, events.sink()).unwrap()));
    events.wait_for("ready", |e| matches!(e, AssistEvent::Connected { .. })).await;
    assert!(registry.current().is_some());
    registry.heard_final(Audio::System, "When can you start?", 1_000, 2_000);
    let taken = registry.take().expect("it ran");
    assert_eq!(taken.id, "01JS");
    assert_eq!(taken.rows.iter().map(|r| r.client_ref.as_str()).collect::<Vec<_>>(), ["o-1"], "the turns still waiting go with it");
    events.wait_for("the end", |e| *e == AssistEvent::Ended(EndReason::Closed)).await;
    assert!(registry.current().is_none());
}

#[tokio::test]
async fn turn_ids_never_restart_when_the_relay_connection_does() {
    use desktop_lib::audio::types::Channel as Audio;
    let got: Arc<Mutex<Vec<(usize, Value)>>> = Arc::default();
    let log = got.clone();
    let relay = Relay::start(never, move |index, mut ws: Ws| {
        let log = log.clone();
        async move {
            let _ = ws.send(ready(index > 0)).await;
            let message = next_json(&mut ws).await;
            log.lock().unwrap().push((index, message));
            if index == 0 {
                close_with(&mut ws, 1012).await;
            } else {
                while let Some(Ok(_)) = ws.next().await {}
            }
        }
    })
    .await;
    let (refresh, _) = refresher(&relay.url);
    let events = Events::default();
    let registry = desktop_lib::assist::Registry::default();
    registry.begin(running(client::spawn(grant(&relay.url, "t1", chrono::Duration::minutes(15)), refresh, quick(), events.sink())));
    events.wait_for("ready", |e| matches!(e, AssistEvent::Connected { resumed: false, .. })).await;
    registry.heard_final(Audio::System, "First question?", 1_000, 2_000);
    events.wait_for("the reconnect", |e| matches!(e, AssistEvent::Connected { resumed: true, .. })).await;
    registry.heard_final(Audio::System, "Second question?", 3_000, 4_000);
    for _ in 0..300 {
        if got.lock().unwrap().len() == 2 {
            break;
        }
        tokio::time::sleep(Duration::from_millis(10)).await;
    }
    let ids: Vec<(usize, String)> = got.lock().unwrap().iter().map(|(i, v)| (*i, v["turn_id"].as_str().unwrap().to_string())).collect();
    assert_eq!(ids, [(0, "o-1".to_string()), (1, "o-2".to_string())], "one numbering for the whole session");
    let rows = registry.take().unwrap().rows;
    assert_eq!(rows.iter().map(|r| r.client_ref.as_str()).collect::<Vec<_>>(), ["o-1", "o-2"], "so client_refs never collide");
}

#[tokio::test]
async fn the_captions_turns_reach_the_relay_the_microphone_s_as_context_only() {
    use desktop_lib::audio::types::Channel as Audio;
    let got: Arc<Mutex<Vec<Value>>> = Arc::default();
    let log = got.clone();
    let relay = Relay::start(never, move |_, mut ws: Ws| {
        let log = log.clone();
        async move {
            let _ = ws.send(ready_at(1000, false)).await;
            loop {
                match ws.next().await {
                    Some(Ok(Message::Text(text))) => log.lock().unwrap().push(serde_json::from_str(text.as_str()).unwrap()),
                    Some(Ok(_)) => {}
                    _ => break,
                }
            }
        }
    })
    .await;
    let (refresh, _) = refresher(&relay.url);
    let events = Events::default();
    let registry = desktop_lib::assist::Registry::default();
    assert!(registry.heard_partial(Audio::System, "nothing runs").card.is_none(), "no session: nothing to send or show");
    registry.begin(running(client::spawn(grant(&relay.url, "t1", chrono::Duration::minutes(15)), refresh, quick(), events.sink())));
    events.wait_for("ready", |e| matches!(e, AssistEvent::Connected { .. })).await;
    let mine = registry.heard_partial(Audio::Mic, "my notice is").card.expect("the card shows my words");
    assert_eq!((mine["type"].as_str(), mine["channel"].as_str(), mine["final"].as_bool()), (Some("turn"), Some("me"), Some(false)));
    assert!(registry.heard_partial(Audio::System, "so what is your notice").card.is_some());
    let theirs = registry.heard_final(Audio::System, "So what is your notice period?", 81_200, 86_400);
    assert_eq!(theirs.card.as_ref().map(|c| c["final"].clone()), Some(json!(true)));
    assert!(!theirs.flush, "one turn waits for the 5 s batch");
    assert!(registry.heard_final(Audio::Mic, "One month.", 87_000, 88_000).card.is_some());
    for _ in 0..100 {
        if got.lock().unwrap().len() >= 3 {
            break;
        }
        tokio::time::sleep(Duration::from_millis(10)).await;
    }
    let got: Vec<(String, String, String)> = got
        .lock()
        .unwrap()
        .iter()
        .map(|v| (v["type"].as_str().unwrap().into(), v["channel"].as_str().unwrap().into(), v["turn_id"].as_str().unwrap().into()))
        .collect();
    assert_eq!(got, [
        ("turn_partial".to_string(), "other".to_string(), "o-1".to_string()),
        ("turn_final".into(), "other".into(), "o-1".into()),
        ("turn_final".into(), "me".into(), "m-1".into()),
    ]);
    let snapshot = registry.with_current(|r| r.card.snapshot(chrono::Utc::now())).unwrap();
    assert_eq!(snapshot.messages.len(), 4, "a window opened now reads every line back");
    assert_eq!(snapshot.meeting_type.as_deref(), Some("interview"));
    registry.stop();
}
