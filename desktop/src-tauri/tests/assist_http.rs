//! Live Assist's HTTP mode (docs/API_ASSIST.md 1.9.0-q) against an in-process server that answers each POST with
//! server-sent events, and the same card sequence through the relay's WebSocket and through HTTP mode.

mod common;

use std::collections::HashMap;
use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant};

use futures_util::{SinkExt, StreamExt};
use serde_json::{Value, json};
use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::TcpListener;
use tokio_tungstenite::tungstenite::Message;

use desktop_lib::assist::card::{self, Card};
use desktop_lib::assist::client::{self, AssistEvent, EndReason, Policy, Refresh, RelayGrant};
use desktop_lib::assist::http::{self, HttpGrant, HttpPolicy, HttpRefresh};
use desktop_lib::assist::protocol::{ClientMessage, Ready, Turn};
use desktop_lib::assist::session;
use desktop_lib::net::api::ApiClient;
use desktop_lib::net::models::AssistStartAnswer;

const TOKEN: &str = "7|eld_TOKEN_PLACEHOLDER";

fn fixture(name: &str) -> Value {
    let path = std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("tests/fixtures").join(name);
    serde_json::from_str(&std::fs::read_to_string(path).unwrap()).unwrap()
}

fn sequence() -> Vec<Value> {
    fixture("assist_card_sequence.json").as_array().unwrap().clone()
}

fn ready_json() -> Value {
    fixture("assist_session_start_http.json")["http"]["ready"].clone()
}

fn ready() -> Ready {
    serde_json::from_value(ready_json()).unwrap()
}

/// What the stand-in answers one POST with.
#[derive(Clone)]
enum Plan {
    /// A refusal before the stream.
    Status(u16, Value),
    /// A stream: each message after its pause, then `end` (or nothing: the stream drops).
    Events { events: Vec<(Duration, Value)>, end: bool },
}

struct Posted {
    path: String,
    authorization: String,
    accept: String,
    body: Value,
    at: Instant,
}

/// A server that answers each POST (numbered from 0) as `plan` says, keeping what was posted and how many streams were
/// open at once.
struct SseServer {
    base: String,
    posted: Arc<Mutex<Vec<Posted>>>,
    most_open: Arc<AtomicUsize>,
}

impl SseServer {
    async fn start(plan: impl Fn(usize, &Value) -> Plan + Send + Sync + 'static) -> SseServer {
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let base = format!("http://127.0.0.1:{}", listener.local_addr().unwrap().port());
        let posted: Arc<Mutex<Vec<Posted>>> = Arc::default();
        let (open, most_open) = (Arc::new(AtomicUsize::new(0)), Arc::new(AtomicUsize::new(0)));
        let (log, plan) = (posted.clone(), Arc::new(plan));
        let (open_count, most) = (open.clone(), most_open.clone());
        tokio::spawn(async move {
            let index = Arc::new(AtomicUsize::new(0));
            while let Ok((mut tcp, _)) = listener.accept().await {
                let (log, plan, index, open_count, most) = (log.clone(), plan.clone(), index.clone(), open_count.clone(), most.clone());
                tokio::spawn(async move {
                    let mut raw = Vec::new();
                    let mut buf = [0u8; 4096];
                    let head_end = loop {
                        let n = tcp.read(&mut buf).await.unwrap_or(0);
                        if n == 0 {
                            return;
                        }
                        raw.extend_from_slice(&buf[..n]);
                        if let Some(at) = raw.windows(4).position(|w| w == b"\r\n\r\n") {
                            break at + 4;
                        }
                    };
                    let head = String::from_utf8_lossy(&raw[..head_end]).to_string();
                    let path = head.split_whitespace().nth(1).unwrap_or("").to_string();
                    let headers: HashMap<String, String> = head
                        .lines()
                        .skip(1)
                        .filter_map(|l| l.split_once(':'))
                        .map(|(k, v)| (k.trim().to_ascii_lowercase(), v.trim().to_string()))
                        .collect();
                    let length: usize = headers.get("content-length").and_then(|l| l.parse().ok()).unwrap_or(0);
                    while raw.len() < head_end + length {
                        let n = tcp.read(&mut buf).await.unwrap_or(0);
                        if n == 0 {
                            break;
                        }
                        raw.extend_from_slice(&buf[..n]);
                    }
                    let body: Value = serde_json::from_slice(&raw[head_end..head_end + length]).unwrap_or(Value::Null);
                    let this = index.fetch_add(1, Ordering::SeqCst);
                    let now_open = open_count.fetch_add(1, Ordering::SeqCst) + 1;
                    most.fetch_max(now_open, Ordering::SeqCst);
                    let answer = plan(this, &body);
                    log.lock().unwrap().push(Posted {
                        path,
                        authorization: headers.get("authorization").cloned().unwrap_or_default(),
                        accept: headers.get("accept").cloned().unwrap_or_default(),
                        body,
                        at: Instant::now(),
                    });
                    match answer {
                        Plan::Status(status, body) => {
                            let body = body.to_string();
                            let reply = format!("HTTP/1.1 {status} Refused\r\nContent-Type: application/json\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{body}", body.len());
                            let _ = tcp.write_all(reply.as_bytes()).await;
                        }
                        Plan::Events { events, end } => {
                            let head = "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nCache-Control: no-cache\r\nConnection: close\r\n\r\n: open\n\n";
                            let _ = tcp.write_all(head.as_bytes()).await;
                            for (pause, message) in events {
                                tokio::time::sleep(pause).await;
                                let kind = message["type"].as_str().unwrap_or("message").to_string();
                                let _ = tcp.write_all(format!("event: {kind}\ndata: {message}\n\n: ping\n\n").as_bytes()).await;
                            }
                            if end {
                                let _ = tcp.write_all(b"event: end\ndata: {}\n\n").await;
                            }
                        }
                    }
                    let _ = tcp.shutdown().await;
                    open_count.fetch_sub(1, Ordering::SeqCst);
                });
            }
        });
        SseServer { base, posted, most_open }
    }

    fn live_url(&self) -> String {
        format!("{}/api/assist/sessions/01JCASSIST0SESSION00000001/live", self.base)
    }

    fn api(&self) -> Arc<ApiClient> {
        common::init_tls();
        Arc::new(ApiClient::new(&self.base, &[], Some(TOKEN.to_string())).unwrap())
    }

    fn posted(&self) -> Vec<(String, Value, Instant)> {
        self.posted.lock().unwrap().iter().map(|p| (p.path.clone(), p.body.clone(), p.at)).collect()
    }
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

    fn messages(&self) -> usize {
        self.all().iter().filter(|e| matches!(e, AssistEvent::Message(_))).count()
    }

    /// What the card window got, and what the card keeps: the card reducer fed in arrival order.
    fn card(&self) -> (Vec<Value>, Vec<Value>) {
        let mut card = Card::new(None, None);
        let mut sent = Vec::new();
        for event in self.all() {
            if let AssistEvent::Message(message) = event
                && let Some(value) = card::relay_message(&message, None)
            {
                card.record(&value);
                sent.push(value);
            }
        }
        (sent, card.snapshot(chrono::Utc::now()).messages)
    }
}

async fn wait_for(what: &str, condition: impl Fn() -> bool) {
    let deadline = Instant::now() + Duration::from_secs(10);
    while !condition() {
        assert!(Instant::now() < deadline, "timed out waiting for {what}");
        tokio::time::sleep(Duration::from_millis(10)).await;
    }
}

fn never_refresh() -> HttpRefresh {
    Arc::new(|| Box::pin(async { Err("not in this test".to_string()) }))
}

fn grant(server: &SseServer) -> HttpGrant {
    HttpGrant { live_url: server.live_url(), ready: ready() }
}

fn turn(text: &str) -> ClientMessage {
    ClientMessage::TurnFinal(Turn { turn_id: format!("o-{text}"), channel: desktop_lib::assist::protocol::Channel::Other, text: text.into(), language: Some("en".into()), start_ms: Some(0), end_ms: Some(1), silence_ms: None })
}

fn the_sequence(pause: Duration) -> Plan {
    Plan::Events { events: sequence().into_iter().map(|m| (pause, m)).collect(), end: true }
}

#[tokio::test]
async fn the_same_card_sequence_reads_the_same_through_the_relay_and_http_mode() {
    // The relay: `ready`, then the sequence, each with its `seq`.
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let url = format!("ws://127.0.0.1:{}/v1/assist", listener.local_addr().unwrap().port());
    tokio::spawn(async move {
        let (tcp, _) = listener.accept().await.unwrap();
        let mut ws = tokio_tungstenite::accept_async(tcp).await.unwrap();
        let mut first = ready_json();
        first["type"] = json!("ready");
        let frames = std::iter::once(first).chain(sequence());
        for (seq, mut frame) in frames.enumerate() {
            frame["seq"] = json!(seq + 1);
            ws.send(Message::Text(frame.to_string().into())).await.unwrap();
        }
        while let Some(Ok(_)) = ws.next().await {}
    });
    let relay_events = Events::default();
    let refresh: Refresh = Arc::new(|| Box::pin(async { Err("not in this test".to_string()) }));
    let relay = client::spawn(RelayGrant { url, token: "t1".into(), expires_at: chrono::Utc::now() + chrono::Duration::minutes(15), pins: vec![] }, refresh, Policy::default(), relay_events.sink());
    let expected = 1 + sequence().len();
    wait_for("the relay's sequence", || relay_events.messages() == expected).await;
    relay.stop();

    // HTTP mode: the start's `ready`, then one message's stream carrying the same sequence.
    let server = SseServer::start(|_, _| the_sequence(Duration::from_millis(5))).await;
    let http_events = Events::default();
    let handle = http::spawn(server.api(), grant(&server), never_refresh(), HttpPolicy::default(), http_events.sink());
    handle.send(ClientMessage::HelpNow { turn_id: None });
    wait_for("the stream's sequence", || http_events.messages() == expected).await;
    handle.stop();

    let (relay_sent, relay_kept) = relay_events.card();
    let (http_sent, http_kept) = http_events.card();
    assert_eq!(http_sent, relay_sent, "the card window gets the same messages");
    assert_eq!(http_kept, relay_kept, "the card keeps the same");
    assert!(relay_kept.iter().any(|m| m["type"] == "token" && m["text"] == "Thirty days, negotiable."), "{relay_kept:?}");
    assert!(matches!(http_events.all()[0], AssistEvent::Connected { resumed: false, .. }));
}

#[tokio::test]
async fn each_message_is_posted_with_the_device_token_as_an_event_stream_without_a_seq() {
    let server = SseServer::start(|_, _| Plan::Events { events: vec![], end: true }).await;
    let events = Events::default();
    let handle = http::spawn(server.api(), grant(&server), never_refresh(), HttpPolicy::default(), events.sink());
    handle.send(ClientMessage::HelpNow { turn_id: None });
    handle.send(turn("What is your notice period?"));
    wait_for("both posts", || server.posted().len() == 2).await;
    let posted = server.posted.lock().unwrap();
    assert_eq!(posted[0].authorization, format!("Bearer {TOKEN}"));
    assert_eq!(posted[0].accept, "text/event-stream");
    assert!(posted[0].path.ends_with("/live"));
    let bodies: Vec<&Value> = posted.iter().map(|p| &p.body).collect();
    assert!(bodies.contains(&&json!({"type": "help_now"})), "{bodies:?}");
    assert!(bodies.iter().all(|b| b.get("seq").is_none()), "no seq in HTTP mode");
    drop(posted);
    handle.stop();
}

#[tokio::test]
async fn at_most_four_streams_are_open_and_the_rest_wait_their_turn_in_order() {
    let server = SseServer::start(|_, body| {
        let id = format!("s-{}", body["text"].as_str().unwrap_or(""));
        Plan::Events { events: vec![(Duration::from_millis(400), json!({"type": "skip", "suggestion_id": id}))], end: true }
    })
    .await;
    let events = Events::default();
    let handle = http::spawn(server.api(), grant(&server), never_refresh(), HttpPolicy::default(), events.sink());
    let started = Instant::now();
    for n in 1..=6 {
        handle.send(turn(&n.to_string()));
    }
    wait_for("six posts", || server.posted().len() == 6).await;
    wait_for("six answers", || events.messages() == 1 + 6).await;
    assert!(server.most_open.load(Ordering::SeqCst) <= 4, "never more than four streams");
    let posted = server.posted();
    let late: Vec<Duration> = posted.iter().map(|(_, _, at)| at.duration_since(started)).collect();
    let waited = late.iter().filter(|d| **d >= Duration::from_millis(350)).count();
    assert_eq!(waited, 2, "the fifth and sixth waited for a stream to end: {late:?}");
    let texts: Vec<String> = posted.iter().map(|(_, b, _)| b["text"].as_str().unwrap_or("").to_string()).collect();
    assert_eq!(&texts[4..], ["5", "6"], "in the order they were sent");
    handle.stop();
}

#[tokio::test]
async fn a_stream_that_drops_before_its_end_clears_the_suggestion_it_was_writing() {
    let server = SseServer::start(|_, _| Plan::Events {
        events: vec![
            (Duration::ZERO, json!({"type": "suggestion_start", "suggestion_id": "s9", "kind": "answer", "mode": "balanced", "model": "m"})),
            (Duration::from_millis(20), json!({"type": "token", "suggestion_id": "s9", "text": "Half a"})),
        ],
        end: false,
    })
    .await;
    let events = Events::default();
    let handle = http::spawn(server.api(), grant(&server), never_refresh(), HttpPolicy::default(), events.sink());
    handle.send(ClientMessage::HelpNow { turn_id: None });
    wait_for("the drop", || events.all().iter().any(|e| matches!(e, AssistEvent::Message(desktop_lib::assist::protocol::ServerMessage::Ready(r)) if r.resumed))).await;
    let (_, kept) = events.card();
    assert!(kept.iter().all(|m| m["suggestion_id"] != "s9"), "the unfinished suggestion left the card: {kept:?}");
    handle.stop();
}

#[tokio::test]
async fn a_503_is_said_on_the_card_and_a_426_ends_the_session_in_plain_words() {
    let server = SseServer::start(|n, _| match n {
        0 => Plan::Status(503, json!({"code": "relay_unavailable", "message": "Live Assist can't connect right now. Try again in a few minutes."})),
        1 => Plan::Events { events: vec![(Duration::ZERO, json!({"type": "skip", "suggestion_id": "s1"}))], end: true },
        _ => Plan::Status(426, json!({"code": "app_update_required", "message": "Live Assist needs the latest version of the app."})),
    })
    .await;
    let events = Events::default();
    let handle = http::spawn(server.api(), grant(&server), never_refresh(), HttpPolicy::default(), events.sink());
    handle.send(ClientMessage::HelpNow { turn_id: None });
    wait_for("the 503", || events.messages() == 2).await;
    let (sent, _) = events.card();
    assert_eq!(sent[1]["type"], "error");
    assert_eq!(sent[1]["message"], "Live Assist can't connect right now. Try again in a few minutes.");
    handle.send(ClientMessage::HelpNow { turn_id: None });
    wait_for("the next stream", || events.messages() == 3).await;
    assert!(!events.all().iter().any(|e| matches!(e, AssistEvent::Ended(_))), "a 503 does not end the session");
    handle.send(ClientMessage::HelpNow { turn_id: None });
    wait_for("the end", || events.all().iter().any(|e| matches!(e, AssistEvent::Ended(_)))).await;
    let ended = events.all().into_iter().find_map(|e| if let AssistEvent::Ended(reason) = e { Some(reason) } else { None }).unwrap();
    assert_eq!(ended, EndReason::AppUpdateRequired);
    assert_eq!(session::end_sentence(&ended), Some("Live Assist needs the latest version of the app."));
    let (sent, _) = events.card();
    assert_eq!(sent.last().unwrap()["message"], "Live Assist needs the latest version of the app.");
}

#[tokio::test]
async fn a_busy_server_gets_the_same_message_again_a_moment_later() {
    let server = SseServer::start(|n, _| if n == 0 { Plan::Status(429, json!({"code": "rate_limited", "message": "Slow down."})) } else { Plan::Events { events: vec![], end: true } }).await;
    let events = Events::default();
    let policy = HttpPolicy { busy_wait: Duration::from_millis(200), ..HttpPolicy::default() };
    let handle = http::spawn(server.api(), grant(&server), never_refresh(), policy, events.sink());
    handle.send(turn("Again"));
    wait_for("the second post", || server.posted().len() == 2).await;
    let posted = server.posted();
    assert_eq!(posted[0].1, posted[1].1, "the same message");
    assert!(posted[1].2.duration_since(posted[0].2) >= Duration::from_millis(180), "after the wait");
    handle.stop();
}

#[tokio::test]
async fn the_session_is_refreshed_on_time_and_its_new_address_is_used() {
    let server = SseServer::start(|_, _| Plan::Events { events: vec![], end: true }).await;
    let calls = Arc::new(AtomicUsize::new(0));
    let (counter, moved) = (calls.clone(), format!("{}/api/assist/sessions/01JCASSIST0SESSION00000001/live2", server.base));
    let refresh: HttpRefresh = Arc::new(move || {
        counter.fetch_add(1, Ordering::SeqCst);
        let next = HttpGrant { live_url: moved.clone(), ready: ready() };
        Box::pin(async move { Ok(next) })
    });
    let events = Events::default();
    let policy = HttpPolicy { refresh_every: Duration::from_millis(300), ..HttpPolicy::default() };
    let handle = http::spawn(server.api(), grant(&server), refresh, policy, events.sink());
    handle.send(ClientMessage::HelpNow { turn_id: None });
    wait_for("the first post", || server.posted().len() == 1).await;
    wait_for("the refresh", || calls.load(Ordering::SeqCst) >= 1).await;
    tokio::time::sleep(Duration::from_millis(50)).await;
    handle.send(ClientMessage::HelpNow { turn_id: None });
    wait_for("the second post", || server.posted().len() == 2).await;
    let posted = server.posted();
    assert!(posted[0].0.ends_with("/live") && posted[1].0.ends_with("/live2"), "{:?}", posted.iter().map(|p| &p.0).collect::<Vec<_>>());
    tokio::time::sleep(Duration::from_millis(400)).await;
    assert!(calls.load(Ordering::SeqCst) >= 2, "every refresh_every");
    handle.stop();
}

#[tokio::test]
async fn a_session_the_server_put_on_http_mode_connects_through_it() {
    let server = SseServer::start(|_, _| the_sequence(Duration::ZERO)).await;
    let mut start = fixture("assist_session_start_http.json");
    start["http"]["live_url"] = json!(server.live_url());
    let answer: AssistStartAnswer = serde_json::from_value(start).unwrap();
    assert!(session::uses_http(&answer));
    let events = Events::default();
    let on_plan: session::OnPlan = Arc::new(|_| {});
    let handle = session::connect(server.api(), &answer, vec![], on_plan, events.sink());
    handle.send(ClientMessage::HelpNow { turn_id: None });
    wait_for("the stream", || events.messages() == 1 + sequence().len()).await;
    assert_eq!(server.posted()[0].0, "/api/assist/sessions/01JCASSIST0SESSION00000001/live");
    handle.stop();
    // A relay answer (or one from a server before 1.9.0-q) stays on the relay.
    let relay: AssistStartAnswer = serde_json::from_value(fixture("assist_session_start.json")).unwrap();
    assert!(!session::uses_http(&relay));
}

#[tokio::test]
async fn only_an_address_on_this_server_gets_the_device_token() {
    let server = SseServer::start(|_, _| Plan::Events { events: vec![], end: true }).await;
    let error = server.api().assist_live("https://elsewhere.example/api/assist/sessions/x/live", "{}".into()).await.unwrap_err();
    assert!(error.to_string().contains("not on this server"), "{error}");
    assert!(server.posted().is_empty());
}
