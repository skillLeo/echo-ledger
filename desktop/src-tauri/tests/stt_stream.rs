//! The provider WebSocket session (`stt::stream::run_session`) against an in-process WebSocket server that plays
//! the part of AssemblyAI v3 or ElevenLabs realtime: session start, init messages, audio framing, interim and final
//! turns, termination, and failures.

use std::future::Future;
use std::sync::{Arc, Mutex};
use std::time::Duration;

use base64::Engine;
use futures_util::{SinkExt, StreamExt};
use serde_json::{Value, json};
use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::TcpListener;
use tokio::sync::mpsc::{UnboundedSender, unbounded_channel};
use tokio::sync::watch;
use tokio::task::JoinHandle;
use tokio_tungstenite::WebSocketStream;
use tokio_tungstenite::tungstenite::Message;

use desktop_lib::net::models::{AudioSpec, Grant};
use desktop_lib::stt::protocol::SttEvent;
use desktop_lib::stt::stream::run_session;

type Ws = WebSocketStream<tokio::net::TcpStream>;

#[derive(Clone, Debug, PartialEq)]
enum Frame {
    Text(String),
    Binary(Vec<u8>),
}

type Log = Arc<Mutex<Vec<Frame>>>;

fn texts(log: &Log) -> Vec<Value> {
    log.lock()
        .unwrap()
        .iter()
        .filter_map(|f| {
            if let Frame::Text(t) = f {
                serde_json::from_str(t).ok()
            } else {
                None
            }
        })
        .collect()
}

fn binaries(log: &Log) -> Vec<Vec<u8>> {
    log.lock()
        .unwrap()
        .iter()
        .filter_map(|f| {
            if let Frame::Binary(b) = f {
                Some(b.clone())
            } else {
                None
            }
        })
        .collect()
}

async fn serve<F, Fut>(scenario: F) -> (u16, JoinHandle<()>)
where
    F: FnOnce(Ws) -> Fut + Send + 'static,
    Fut: Future<Output = ()> + Send + 'static,
{
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let port = listener.local_addr().unwrap().port();
    let handle = tokio::spawn(async move {
        let (stream, _) = listener.accept().await.unwrap();
        let ws = tokio_tungstenite::accept_async(stream).await.unwrap();
        scenario(ws).await;
    });
    (port, handle)
}

const BEGIN: &str = r#"{"type":"Begin","id":"session-1","expires_at":1790000000}"#;
const TERMINATION: &str =
    r#"{"type":"Termination","audio_duration_seconds":3,"session_duration_seconds":4}"#;

/// AssemblyAI-like server: sends `Begin` after `begin_delay`, logs everything it receives, answers `Terminate`
/// with `Termination` (unless told to stay silent) and then closes.
async fn assemblyai_server(
    mut ws: Ws,
    log: Log,
    begin_delay: Duration,
    answer_terminate: bool,
    extra_after_begin: Vec<String>,
) {
    tokio::time::sleep(begin_delay).await;
    ws.send(Message::text(BEGIN)).await.unwrap();
    for extra in extra_after_begin {
        ws.send(Message::text(extra)).await.unwrap();
    }
    while let Some(Ok(message)) = ws.next().await {
        match message {
            Message::Text(text) => {
                log.lock()
                    .unwrap()
                    .push(Frame::Text(text.as_str().to_string()));
                if text.as_str().contains("Terminate") && answer_terminate {
                    let _ = ws.send(Message::text(TERMINATION)).await;
                    let _ = ws.close(None).await;
                    return;
                }
            }
            Message::Binary(bytes) => log.lock().unwrap().push(Frame::Binary(bytes.to_vec())),
            Message::Close(_) => return,
            _ => {}
        }
    }
}

fn grant(protocol: &str, port: u16, frame_ms: u32, init: Vec<Value>) -> Grant {
    Grant {
        channel: "me".into(),
        transport: "websocket".into(),
        protocol: Some(protocol.into()),
        ws_url: Some(format!("ws://127.0.0.1:{port}/stream?token=short-lived")),
        max_session_seconds: Some(10_800),
        audio: Some(AudioSpec {
            encoding: "pcm_s16le".into(),
            sample_rate: 16_000,
            channels: 1,
            frame_ms,
        }),
        init_messages: init,
    }
}

struct Client {
    pcm: UnboundedSender<Vec<u8>>,
    stop: watch::Sender<bool>,
    events: Arc<Mutex<Vec<SttEvent>>>,
    task: JoinHandle<Result<(), String>>,
}

fn start_client(grant: Grant) -> Client {
    let (pcm, pcm_rx) = unbounded_channel();
    let (stop, stop_rx) = watch::channel(false);
    let events = Arc::new(Mutex::new(Vec::new()));
    let sink = events.clone();
    let task = tokio::spawn(run_session(grant, pcm_rx, stop_rx, move |event| {
        sink.lock().unwrap().push(event)
    }));
    Client {
        pcm,
        stop,
        events,
        task,
    }
}

impl Client {
    async fn finish(self, within: Duration) -> Result<(), String> {
        let _ = self.stop.send(true);
        tokio::time::timeout(within, self.task)
            .await
            .expect("the session did not end after the stop request")
            .expect("task panicked")
    }

    fn events(&self) -> Vec<SttEvent> {
        self.events.lock().unwrap().clone()
    }
}

async fn wait_for(what: &str, timeout: Duration, condition: impl Fn() -> bool) {
    let deadline = std::time::Instant::now() + timeout;
    while !condition() {
        assert!(
            std::time::Instant::now() < deadline,
            "timed out waiting for {what}"
        );
        tokio::time::sleep(Duration::from_millis(10)).await;
    }
}

fn pcm_bytes(len: usize, seed: u8) -> Vec<u8> {
    (0..len)
        .map(|n| (n as u8).wrapping_mul(31).wrapping_add(seed))
        .collect()
}

// ---- AssemblyAI ------------------------------------------------------------------------------------------------

#[tokio::test]
async fn init_messages_follow_the_session_start_and_precede_all_audio() {
    let log: Log = Arc::default();
    let server_log = log.clone();
    let (port, _server) = serve(move |ws| {
        assemblyai_server(ws, server_log, Duration::from_millis(300), true, vec![])
    })
    .await;
    let keyterms =
        json!({"type": "UpdateConfiguration", "keyterms_prompt": ["Acme Holdings", "Farah Khan"]});
    let client = start_client(grant("assemblyai_v3", port, 50, vec![keyterms.clone()]));
    client.pcm.send(pcm_bytes(3_200, 1)).unwrap(); // arrives before the session has started

    wait_for("audio", Duration::from_secs(5), || {
        !binaries(&log).is_empty()
    })
    .await;
    let frames = log.lock().unwrap().clone();
    assert_eq!(
        frames[0],
        Frame::Text(keyterms.to_string()),
        "the keyterm update is the first thing the provider hears"
    );
    assert!(matches!(frames[1], Frame::Binary(_)));
    client.finish(Duration::from_secs(5)).await.unwrap();
}

#[tokio::test]
async fn audio_sent_before_and_after_the_session_start_arrives_complete_and_in_order() {
    let log: Log = Arc::default();
    let server_log = log.clone();
    let (port, _server) = serve(move |ws| {
        assemblyai_server(ws, server_log, Duration::from_millis(300), true, vec![])
    })
    .await;
    let client = start_client(grant("assemblyai_v3", port, 50, vec![]));

    let before = pcm_bytes(4_800, 3); // exactly three frames buffered before Begin
    let after = pcm_bytes(6_400, 9); // four frames after
    client.pcm.send(before.clone()).unwrap();
    wait_for("Begin", Duration::from_secs(5), || {
        client.events().contains(&SttEvent::Started)
    })
    .await;
    client.pcm.send(after.clone()).unwrap();
    wait_for("seven frames", Duration::from_secs(5), || {
        binaries(&log).len() >= 7
    })
    .await;
    client.finish(Duration::from_secs(5)).await.unwrap();

    let received: Vec<u8> = binaries(&log).concat();
    assert_eq!(received, [before, after].concat());
    assert!(
        binaries(&log).iter().all(|f| f.len() == 1_600),
        "50 ms frames of 16 kHz s16le mono"
    );
}

#[tokio::test]
async fn audio_buffered_before_the_start_is_sent_in_provider_sized_frames() {
    // AssemblyAI closes the session (3007) on any chunk shorter than 50 ms, so the flush that follows `Begin` has to
    // hold back or pad a remainder instead of sending it as a short frame.
    let log: Log = Arc::default();
    let server_log = log.clone();
    let (port, _server) = serve(move |ws| {
        assemblyai_server(ws, server_log, Duration::from_millis(300), true, vec![])
    })
    .await;
    let client = start_client(grant("assemblyai_v3", port, 50, vec![]));
    client.pcm.send(pcm_bytes(2_500, 5)).unwrap(); // 1.56 frames
    wait_for("Begin", Duration::from_secs(5), || {
        client.events().contains(&SttEvent::Started)
    })
    .await;
    wait_for("the flush", Duration::from_secs(5), || {
        !binaries(&log).is_empty()
    })
    .await;
    tokio::time::sleep(Duration::from_millis(300)).await;
    client.finish(Duration::from_secs(5)).await.unwrap();

    let sizes: Vec<usize> = binaries(&log).iter().map(Vec::len).collect();
    assert!(
        sizes.iter().all(|&len| (1_600..=32_000).contains(&len)),
        "frame sizes sent to the provider: {sizes:?}"
    );
}

#[tokio::test]
async fn a_remainder_held_back_after_the_start_joins_the_next_audio_and_the_last_one_is_padded() {
    let log: Log = Arc::default();
    let server_log = log.clone();
    let (port, _server) = serve(move |ws| {
        assemblyai_server(ws, server_log, Duration::from_millis(300), true, vec![])
    })
    .await;
    let client = start_client(grant("assemblyai_v3", port, 50, vec![]));
    let before = pcm_bytes(2_500, 5); // one frame and 900 bytes
    let after = pcm_bytes(1_700, 8); // completes the second frame, leaves 1 000 bytes
    client.pcm.send(before.clone()).unwrap();
    wait_for("Begin", Duration::from_secs(5), || {
        client.events().contains(&SttEvent::Started)
    })
    .await;
    client.pcm.send(after.clone()).unwrap();
    wait_for("two frames", Duration::from_secs(5), || {
        binaries(&log).len() >= 2
    })
    .await;
    client.finish(Duration::from_secs(5)).await.unwrap();

    let frames = binaries(&log);
    assert!(frames.iter().all(|f| f.len() == 1_600), "{:?}", frames.iter().map(Vec::len).collect::<Vec<_>>());
    let received = frames.concat();
    let sent = [before, after].concat();
    assert_eq!(&received[..sent.len()], &sent[..], "contiguous and in order");
    assert_eq!(received.len(), 4_800, "the last 1 000 bytes are padded to a whole frame");
    assert!(received[sent.len()..].iter().all(|&b| b == 0), "padding is silence");
    let terminate_at = log
        .lock()
        .unwrap()
        .iter()
        .position(|f| matches!(f, Frame::Text(t) if t.contains("Terminate")))
        .expect("Terminate sent");
    assert_eq!(terminate_at, 3, "the padded frame precedes Terminate");
}

#[tokio::test]
async fn the_frame_size_follows_the_grant_within_the_provider_limits() {
    for (frame_ms, expected) in [
        (10u32, 1_600usize),
        (50, 1_600),
        (100, 3_200),
        (250, 8_000),
        (1_000, 8_000),
    ] {
        let log: Log = Arc::default();
        let server_log = log.clone();
        let (port, _server) = serve(move |ws| {
            assemblyai_server(ws, server_log, Duration::from_millis(0), true, vec![])
        })
        .await;
        let client = start_client(grant("assemblyai_v3", port, frame_ms, vec![]));
        wait_for("Begin", Duration::from_secs(5), || {
            client.events().contains(&SttEvent::Started)
        })
        .await;
        client.pcm.send(pcm_bytes(expected * 3, 1)).unwrap();
        wait_for("three frames", Duration::from_secs(5), || {
            binaries(&log).len() >= 3
        })
        .await;
        client.finish(Duration::from_secs(5)).await.unwrap();
        assert!(
            binaries(&log).iter().all(|f| f.len() == expected),
            "frame_ms {frame_ms}: {:?}",
            binaries(&log).iter().map(Vec::len).collect::<Vec<_>>()
        );
    }
}

#[tokio::test]
async fn a_missing_audio_spec_defaults_to_fifty_millisecond_frames() {
    let log: Log = Arc::default();
    let server_log = log.clone();
    let (port, _server) =
        serve(move |ws| assemblyai_server(ws, server_log, Duration::from_millis(0), true, vec![]))
            .await;
    let mut g = grant("assemblyai_v3", port, 50, vec![]);
    g.audio = None;
    let client = start_client(g);
    wait_for("Begin", Duration::from_secs(5), || {
        client.events().contains(&SttEvent::Started)
    })
    .await;
    client.pcm.send(pcm_bytes(3_200, 1)).unwrap();
    wait_for("two frames", Duration::from_secs(5), || {
        binaries(&log).len() >= 2
    })
    .await;
    client.finish(Duration::from_secs(5)).await.unwrap();
    assert_eq!(
        binaries(&log).iter().map(Vec::len).collect::<Vec<_>>(),
        [1_600, 1_600]
    );
}

#[tokio::test]
async fn at_most_one_second_of_audio_is_buffered_while_waiting_for_the_session() {
    // More would go out at once on Begin, and the provider (throttled to about 1.25x real time) would take four times
    // as long to catch up, every caption late meanwhile.
    let log: Log = Arc::default();
    let server_log = log.clone();
    let (port, _server) = serve(move |ws| {
        assemblyai_server(ws, server_log, Duration::from_millis(600), true, vec![])
    })
    .await;
    let client = start_client(grant("assemblyai_v3", port, 50, vec![]));
    let pushed = pcm_bytes(500_000, 7); // 15.6 s
    for piece in pushed.chunks(25_000) {
        client.pcm.send(piece.to_vec()).unwrap();
    }
    wait_for("the flush", Duration::from_secs(10), || {
        binaries(&log).concat().len() >= 32_000
    })
    .await;
    tokio::time::sleep(Duration::from_millis(200)).await;
    client.finish(Duration::from_secs(5)).await.unwrap();
    let received = binaries(&log).concat();
    assert_eq!(received.len(), 32_000, "one second");
    assert_eq!(
        received,
        pushed[pushed.len() - 32_000..],
        "the newest audio is kept, the oldest dropped"
    );
}

#[tokio::test]
async fn events_reach_the_caller_in_order() {
    let log: Log = Arc::default();
    let server_log = log.clone();
    let extras = vec![
        json!({"type": "Turn", "end_of_turn": false, "turn_is_formatted": false, "transcript": "good mor", "words": []}).to_string(),
        "{ this is not json".to_string(),
        json!({"type": "SpeakerRevision"}).to_string(),
        json!({"type": "Turn", "end_of_turn": true, "turn_is_formatted": false, "transcript": "good morning", "words": []}).to_string(),
        json!({"type": "Turn", "end_of_turn": true, "turn_is_formatted": true, "transcript": "Good morning.", "words": [{"start": 200, "end": 900}]}).to_string(),
    ];
    let (port, _server) =
        serve(move |ws| assemblyai_server(ws, server_log, Duration::from_millis(0), true, extras))
            .await;
    let client = start_client(grant("assemblyai_v3", port, 50, vec![]));
    wait_for("the final turn", Duration::from_secs(5), || {
        client
            .events()
            .iter()
            .any(|e| matches!(e, SttEvent::Final { .. }))
    })
    .await;
    let recorded = client.events.clone();
    client.finish(Duration::from_secs(5)).await.unwrap();
    let events = recorded.lock().unwrap().clone();
    assert_eq!(
        events,
        vec![
            SttEvent::Started,
            SttEvent::Partial {
                text: "good mor".into()
            },
            SttEvent::Final {
                text: "Good morning.".into(),
                start_ms: Some(200),
                end_ms: Some(900)
            },
            SttEvent::Terminated,
        ]
    );
}

#[tokio::test]
async fn a_provider_error_is_reported_and_does_not_end_the_session() {
    let log: Log = Arc::default();
    let server_log = log.clone();
    let error =
        json!({"type": "Error", "error_code": 3005, "error": "Session expired"}).to_string();
    let (port, _server) = serve(move |ws| {
        assemblyai_server(ws, server_log, Duration::from_millis(0), true, vec![error])
    })
    .await;
    let client = start_client(grant("assemblyai_v3", port, 50, vec![]));
    wait_for("the error", Duration::from_secs(5), || {
        client
            .events()
            .contains(&SttEvent::Error("Session expired".into()))
    })
    .await;
    client.finish(Duration::from_secs(5)).await.unwrap();
}

#[tokio::test]
async fn stopping_sends_terminate_and_waits_for_the_provider_to_confirm() {
    let log: Log = Arc::default();
    let server_log = log.clone();
    let (port, _server) =
        serve(move |ws| assemblyai_server(ws, server_log, Duration::from_millis(0), true, vec![]))
            .await;
    let client = start_client(grant("assemblyai_v3", port, 50, vec![]));
    wait_for("Begin", Duration::from_secs(5), || {
        client.events().contains(&SttEvent::Started)
    })
    .await;
    let events = client.events.clone();
    client.finish(Duration::from_secs(5)).await.unwrap();
    assert!(texts(&log).contains(&json!({"type": "Terminate"})));
    assert!(
        events.lock().unwrap().contains(&SttEvent::Terminated),
        "the caller hears about the confirmed end"
    );
}

#[tokio::test]
async fn closing_the_audio_channel_also_terminates_the_session() {
    let log: Log = Arc::default();
    let server_log = log.clone();
    let (port, _server) =
        serve(move |ws| assemblyai_server(ws, server_log, Duration::from_millis(0), true, vec![]))
            .await;
    let client = start_client(grant("assemblyai_v3", port, 50, vec![]));
    wait_for("Begin", Duration::from_secs(5), || {
        client.events().contains(&SttEvent::Started)
    })
    .await;
    let Client {
        pcm,
        stop: _stop,
        task,
        ..
    } = client;
    drop(pcm);
    let result = tokio::time::timeout(Duration::from_secs(5), task)
        .await
        .expect("session should end when the recorder goes away")
        .unwrap();
    assert!(result.is_ok());
    assert!(texts(&log).contains(&json!({"type": "Terminate"})));
}

#[tokio::test]
async fn a_provider_that_never_confirms_the_end_does_not_hold_the_session_open_forever() {
    let log: Log = Arc::default();
    let server_log = log.clone();
    let (port, _server) =
        serve(move |ws| assemblyai_server(ws, server_log, Duration::from_millis(0), false, vec![]))
            .await;
    let client = start_client(grant("assemblyai_v3", port, 50, vec![]));
    wait_for("Begin", Duration::from_secs(5), || {
        client.events().contains(&SttEvent::Started)
    })
    .await;
    // The stop request must be honoured within a few seconds even if no Termination ever arrives.
    let _ = client.stop.send(true);
    let outcome = tokio::time::timeout(Duration::from_secs(8), client.task).await;
    assert!(
        outcome.is_ok(),
        "run_session is still waiting for a Termination message 8 s after the stop request"
    );
}

#[tokio::test]
async fn the_provider_hanging_up_ends_the_session_cleanly() {
    let (port, _server) = serve(|mut ws| async move {
        ws.send(Message::text(BEGIN)).await.unwrap();
        let _ = ws.close(None).await;
    })
    .await;
    let client = start_client(grant("assemblyai_v3", port, 50, vec![]));
    let result = tokio::time::timeout(Duration::from_secs(5), client.task)
        .await
        .expect("session should end")
        .unwrap();
    assert!(result.is_ok(), "{result:?}");
}

#[tokio::test]
async fn a_connection_that_drops_without_a_close_handshake_ends_the_session() {
    let (port, _server) = serve(|mut ws| async move {
        ws.send(Message::text(BEGIN)).await.unwrap();
        drop(ws); // TCP closed with no WebSocket close frame
    })
    .await;
    let client = start_client(grant("assemblyai_v3", port, 50, vec![]));
    let result = tokio::time::timeout(Duration::from_secs(5), client.task)
        .await
        .expect("session should end")
        .unwrap();
    // Either a clean end or a reported error is acceptable; hanging is not.
    let _ = result;
}

// ---- ElevenLabs ------------------------------------------------------------------------------------------------

/// ElevenLabs-like server: `session_started`, logs frames, answers a commit with a committed transcript.
async fn elevenlabs_server(mut ws: Ws, log: Log, answer_commit: bool) {
    ws.send(Message::text(
        json!({"message_type": "session_started", "session_id": "s1", "config": {}}).to_string(),
    ))
    .await
    .unwrap();
    while let Some(Ok(message)) = ws.next().await {
        match message {
            Message::Text(text) => {
                log.lock()
                    .unwrap()
                    .push(Frame::Text(text.as_str().to_string()));
                let v: Value = serde_json::from_str(text.as_str()).unwrap();
                if v["commit"] == true && answer_commit {
                    let reply =
                        json!({"message_type": "committed_transcript", "text": "That is all."});
                    ws.send(Message::text(reply.to_string())).await.unwrap();
                }
            }
            Message::Close(_) => return,
            _ => {}
        }
    }
}

fn audio_frames(log: &Log) -> Vec<Vec<u8>> {
    texts(log)
        .into_iter()
        .filter(|v| v["message_type"] == "input_audio_chunk" && v["commit"] == false)
        .map(|v| {
            base64::engine::general_purpose::STANDARD
                .decode(v["audio_base_64"].as_str().unwrap())
                .unwrap()
        })
        .collect()
}

#[tokio::test]
async fn elevenlabs_audio_is_sent_as_base64_json_frames() {
    let log: Log = Arc::default();
    let server_log = log.clone();
    let (port, _server) = serve(move |ws| elevenlabs_server(ws, server_log, true)).await;
    let client = start_client(grant("elevenlabs_realtime", port, 100, vec![]));
    wait_for("session start", Duration::from_secs(5), || {
        client.events().contains(&SttEvent::Started)
    })
    .await;
    let pcm = pcm_bytes(9_600, 2); // three 100 ms frames
    client.pcm.send(pcm.clone()).unwrap();
    wait_for("the frames", Duration::from_secs(5), || {
        audio_frames(&log).iter().map(Vec::len).sum::<usize>() >= 9_600
    })
    .await;
    client.finish(Duration::from_secs(8)).await.unwrap();

    // Silence keep-alives may be interleaved; the speech frames in order are the audio that was sent.
    let speech: Vec<u8> = audio_frames(&log)
        .into_iter()
        .filter(|f| f.iter().any(|&b| b != 0))
        .flatten()
        .collect();
    assert_eq!(speech, pcm);
    assert!(
        audio_frames(&log).iter().all(|f| f.len() == 3_200),
        "100 ms frames"
    );
    let first = &texts(&log)[0];
    assert_eq!(first["sample_rate"], 16_000);
}

#[tokio::test]
async fn elevenlabs_stop_commits_the_last_turn_and_delivers_it_before_closing() {
    let log: Log = Arc::default();
    let server_log = log.clone();
    let (port, _server) = serve(move |ws| elevenlabs_server(ws, server_log, true)).await;
    let client = start_client(grant("elevenlabs_realtime", port, 100, vec![]));
    wait_for("session start", Duration::from_secs(5), || {
        client.events().contains(&SttEvent::Started)
    })
    .await;
    let events = client.events.clone();
    client.finish(Duration::from_secs(8)).await.unwrap();

    assert!(
        texts(&log)
            .iter()
            .any(|v| v["commit"] == true && v["audio_base_64"] == ""),
        "an explicit commit is sent"
    );
    assert!(events.lock().unwrap().contains(&SttEvent::Final {
        text: "That is all.".into(),
        start_ms: None,
        end_ms: None
    }));
}

#[tokio::test]
async fn elevenlabs_stop_does_not_wait_forever_for_a_commit_that_never_comes() {
    let log: Log = Arc::default();
    let server_log = log.clone();
    let (port, _server) = serve(move |ws| elevenlabs_server(ws, server_log, false)).await;
    let client = start_client(grant("elevenlabs_realtime", port, 100, vec![]));
    wait_for("session start", Duration::from_secs(5), || {
        client.events().contains(&SttEvent::Started)
    })
    .await;
    let started = std::time::Instant::now();
    client.finish(Duration::from_secs(8)).await.unwrap();
    assert!(
        started.elapsed() < Duration::from_secs(6),
        "gave up after {:?}",
        started.elapsed()
    );
}

#[tokio::test]
async fn elevenlabs_is_kept_alive_with_silence_while_nobody_speaks() {
    let log: Log = Arc::default();
    let server_log = log.clone();
    let (port, _server) = serve(move |ws| elevenlabs_server(ws, server_log, true)).await;
    let client = start_client(grant("elevenlabs_realtime", port, 100, vec![]));
    // One right at the start and another about five seconds later: the provider drops idle sessions after 15 s.
    wait_for("two silence frames", Duration::from_secs(12), || {
        audio_frames(&log)
            .iter()
            .filter(|f| f.len() == 3_200 && f.iter().all(|&b| b == 0))
            .count()
            >= 2
    })
    .await;
    client.finish(Duration::from_secs(8)).await.unwrap();
}

// ---- Bad grants and unreachable servers ------------------------------------------------------------------------

#[tokio::test]
async fn a_grant_for_an_unsupported_engine_is_refused_before_connecting() {
    let (_pcm, rx) = unbounded_channel();
    let (_stop, stop_rx) = watch::channel(false);
    let mut g = grant("azure_speech_sdk", 1, 50, vec![]);
    let err = run_session(g.clone(), rx, stop_rx.clone(), |_| {})
        .await
        .unwrap_err();
    assert!(err.contains("unsupported streaming protocol"), "{err}");
    g.protocol = None;
    let (_pcm2, rx2) = unbounded_channel();
    let err = run_session(g, rx2, stop_rx, |_| {}).await.unwrap_err();
    assert!(err.contains("unsupported streaming protocol"), "{err}");
}

#[tokio::test]
async fn a_grant_without_a_url_or_with_a_broken_one_is_an_error() {
    let (_stop, stop_rx) = watch::channel(false);
    let mut g = grant("assemblyai_v3", 1, 50, vec![]);
    g.ws_url = None;
    let (_p, rx) = unbounded_channel();
    assert!(
        run_session(g.clone(), rx, stop_rx.clone(), |_| {})
            .await
            .unwrap_err()
            .contains("ws_url")
    );
    for bad in ["not a url", "", "ws://", "http://127.0.0.1:1/x"] {
        g.ws_url = Some(bad.into());
        let (_p, rx) = unbounded_channel();
        let outcome = tokio::time::timeout(
            Duration::from_secs(5),
            run_session(g.clone(), rx, stop_rx.clone(), |_| {}),
        )
        .await
        .expect("must fail fast");
        assert!(outcome.is_err(), "{bad:?} should not connect");
    }
}

#[tokio::test]
async fn an_unreachable_provider_is_reported_as_a_connect_error() {
    let port = {
        let l = std::net::TcpListener::bind("127.0.0.1:0").unwrap();
        l.local_addr().unwrap().port()
    };
    let (_stop, stop_rx) = watch::channel(false);
    let (_p, rx) = unbounded_channel();
    let err = tokio::time::timeout(
        Duration::from_secs(10),
        run_session(
            grant("assemblyai_v3", port, 50, vec![]),
            rx,
            stop_rx,
            |_| {},
        ),
    )
    .await
    .expect("must fail fast")
    .unwrap_err();
    assert!(err.starts_with("connect:"), "{err}");
}

#[tokio::test]
async fn a_refused_connection_never_echoes_the_session_token() {
    let port = {
        let l = std::net::TcpListener::bind("127.0.0.1:0").unwrap();
        l.local_addr().unwrap().port()
    };
    let (_stop, stop_rx) = watch::channel(false);
    let (_p, rx) = unbounded_channel();
    let err = run_session(
        grant("assemblyai_v3", port, 50, vec![]),
        rx,
        stop_rx,
        |_| {},
    )
    .await
    .unwrap_err();
    assert!(
        !err.contains("short-lived"),
        "the one-time token appears in the error shown to the user: {err}"
    );
}

#[tokio::test]
async fn a_rejected_upgrade_is_reported_with_its_status_and_without_the_token() {
    let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
    let port = listener.local_addr().unwrap().port();
    tokio::spawn(async move {
        let (mut stream, _) = listener.accept().await.unwrap();
        let mut buffer = [0u8; 4096];
        let _ = stream.read(&mut buffer).await;
        let _ = stream
            .write_all(
                b"HTTP/1.1 401 Unauthorized\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
            )
            .await;
    });
    let (_stop, stop_rx) = watch::channel(false);
    let (_p, rx) = unbounded_channel();
    let err = tokio::time::timeout(
        Duration::from_secs(5),
        run_session(
            grant("assemblyai_v3", port, 50, vec![]),
            rx,
            stop_rx,
            |_| {},
        ),
    )
    .await
    .expect("must fail fast")
    .unwrap_err();
    assert!(err.contains("401"), "{err}");
    assert!(!err.contains("short-lived"), "{err}");
}

// ---- The caption delay: frames in, caption out ------------------------------------------------------------------

/// Someone speaking: a loud 220 Hz tone, `ms` of 16 kHz 16-bit PCM.
fn speech(ms: usize) -> Vec<u8> {
    (0..16 * ms)
        .flat_map(|n| (((n as f32 * 220.0 * std::f32::consts::TAU / 16_000.0).sin() * 8_000.0) as i16).to_le_bytes())
        .collect()
}

/// A provider that never ends a turn on its own (music or noise under the voice: it never hears a pause) and sends no
/// partials: only `ForceEndpoint` brings a turn's text back. With it, captions lagged 30-60 s.
async fn turn_holding_assemblyai(mut ws: Ws, log: Log, partials_every: Option<Duration>) {
    ws.send(Message::text(BEGIN)).await.unwrap();
    let mut partial = tokio::time::interval(partials_every.unwrap_or(Duration::from_secs(3_600)));
    partial.tick().await;
    loop {
        tokio::select! {
            message = ws.next() => {
                let Some(Ok(message)) = message else { return };
                match message {
                    Message::Text(text) => {
                        log.lock().unwrap().push(Frame::Text(text.as_str().to_string()));
                        if text.as_str().contains("ForceEndpoint") {
                            let turn = json!({"type": "Turn", "end_of_turn": true, "turn_is_formatted": true, "transcript": "Hello there.", "words": []});
                            ws.send(Message::text(turn.to_string())).await.unwrap();
                        }
                        if text.as_str().contains("Terminate") {
                            let _ = ws.send(Message::text(TERMINATION)).await;
                            let _ = ws.close(None).await;
                            return;
                        }
                    }
                    Message::Binary(bytes) => log.lock().unwrap().push(Frame::Binary(bytes.to_vec())),
                    Message::Close(_) => return,
                    _ => {}
                }
            }
            _ = partial.tick(), if partials_every.is_some() => {
                let turn = json!({"type": "Turn", "end_of_turn": false, "turn_is_formatted": false, "transcript": "hello", "words": []});
                ws.send(Message::text(turn.to_string())).await.unwrap();
            }
        }
    }
}

/// Feeds `audio` in 50 ms pieces at real time; gives when the first piece went in.
fn feed_real_time(pcm: UnboundedSender<Vec<u8>>, audio: Vec<u8>) -> std::time::Instant {
    let started = std::time::Instant::now();
    tokio::spawn(async move {
        let mut tick = tokio::time::interval(Duration::from_millis(50));
        for piece in audio.chunks(1_600) {
            tick.tick().await;
            if pcm.send(piece.to_vec()).is_err() {
                return;
            }
        }
    });
    started
}

fn forced(log: &Log) -> usize {
    texts(log).iter().filter(|v| v["type"] == "ForceEndpoint" || v["commit"] == true).count()
}

#[tokio::test]
async fn a_caption_comes_out_within_the_caption_wait_even_when_the_provider_holds_its_turn() {
    let log: Log = Arc::default();
    let server_log = log.clone();
    let (port, _server) = serve(move |ws| turn_holding_assemblyai(ws, server_log, None)).await;
    let client = start_client(grant("assemblyai_v3", port, 50, vec![]));
    let fed = feed_real_time(client.pcm.clone(), speech(6_000));
    let events = client.events.clone();
    wait_for("a caption", Duration::from_secs(5), || {
        events.lock().unwrap().iter().any(|e| matches!(e, SttEvent::Final { .. }))
    })
    .await;
    let delay = fed.elapsed();
    let cap = desktop_lib::stt::stream::CAPTION_WAIT;
    assert!(delay <= cap + Duration::from_millis(800), "frames in to caption out: {delay:?} (the cap is {cap:?})");
    assert!(delay >= cap - Duration::from_millis(100), "only after the wait: {delay:?}");
    assert_eq!(forced(&log), 1, "the turn was ended once");
    // Still speaking: the next caption comes within the wait again, not at the end of the speech.
    wait_for("the second caption", Duration::from_secs(4), || {
        events.lock().unwrap().iter().filter(|e| matches!(e, SttEvent::Final { .. })).count() >= 2
    })
    .await;
    client.finish(Duration::from_secs(5)).await.unwrap();
}

#[tokio::test]
async fn a_provider_that_sends_partials_is_never_made_to_end_its_turn() {
    let log: Log = Arc::default();
    let server_log = log.clone();
    let (port, _server) = serve(move |ws| turn_holding_assemblyai(ws, server_log, Some(Duration::from_millis(500)))).await;
    let client = start_client(grant("assemblyai_v3", port, 50, vec![]));
    feed_real_time(client.pcm.clone(), speech(3_500));
    tokio::time::sleep(Duration::from_millis(3_500)).await;
    assert!(client.events().iter().any(|e| matches!(e, SttEvent::Partial { .. })));
    assert_eq!(forced(&log), 0, "captions came: nothing to force");
    client.finish(Duration::from_secs(5)).await.unwrap();
}

#[tokio::test]
async fn silence_never_ends_a_turn() {
    let log: Log = Arc::default();
    let server_log = log.clone();
    let (port, _server) = serve(move |ws| turn_holding_assemblyai(ws, server_log, None)).await;
    let client = start_client(grant("assemblyai_v3", port, 50, vec![]));
    feed_real_time(client.pcm.clone(), vec![0u8; 32_000 * 3]);
    tokio::time::sleep(Duration::from_millis(3_000)).await;
    assert_eq!(forced(&log), 0, "nobody spoke");
    client.finish(Duration::from_secs(5)).await.unwrap();
}

#[tokio::test]
async fn elevenlabs_holding_its_turn_is_committed_within_the_caption_wait() {
    let log: Log = Arc::default();
    let server_log = log.clone();
    let (port, _server) = serve(move |ws| elevenlabs_server(ws, server_log, true)).await;
    let client = start_client(grant("elevenlabs_realtime", port, 100, vec![]));
    let fed = feed_real_time(client.pcm.clone(), speech(4_000));
    let events = client.events.clone();
    wait_for("a caption", Duration::from_secs(5), || {
        events.lock().unwrap().iter().any(|e| matches!(e, SttEvent::Final { .. }))
    })
    .await;
    let delay = fed.elapsed();
    assert!(delay <= desktop_lib::stt::stream::CAPTION_WAIT + Duration::from_millis(800), "frames in to caption out: {delay:?}");
    client.finish(Duration::from_secs(5)).await.unwrap();
}

#[test]
fn speech_is_told_from_silence_and_one_second_waits_for_the_start() {
    use desktop_lib::stt::stream::{pre_begin_bytes, voiced};
    assert!(voiced(&speech(50)));
    assert!(!voiced(&[0u8; 1_600]));
    let hum: Vec<u8> = (0..800).flat_map(|n| ((n % 7) as i16 * 20).to_le_bytes()).collect();
    assert!(!voiced(&hum), "room tone is not speech");
    let second = Duration::from_secs(1);
    assert_eq!(pre_begin_bytes(1_600, second), 32_000);
    assert_eq!(pre_begin_bytes(3_200, second), 32_000);
    assert_eq!(pre_begin_bytes(8_000, second), 32_000);
    assert_eq!(pre_begin_bytes(1_600, Duration::from_secs(10)), 320_000);
}

#[test]
fn dictation_keeps_its_first_words_and_live_sessions_one_second() {
    use desktop_lib::stt::stream::SessionOptions;
    assert_eq!(SessionOptions::for_use_case("dictation").pre_begin, Duration::from_secs(10));
    assert_eq!(SessionOptions::for_use_case("live_captions").pre_begin, Duration::from_secs(1));
    assert_eq!(SessionOptions::for_use_case("assist").pre_begin, Duration::from_secs(1));
}

#[test]
fn a_forced_end_that_brings_nothing_back_doubles_the_wait_and_a_caption_resets_it() {
    use desktop_lib::stt::stream::{Answer, CAPTION_WAIT, CAPTION_WAIT_MAX, CaptionWait};
    let now = tokio::time::Instant::now();
    let mut waits = CaptionWait::default();
    assert_eq!(waits.due(), None, "nothing said yet");
    waits.sent_at(&[0u8; 1_600], now);
    assert_eq!(waits.due(), None, "silence starts no wait");
    waits.sent_at(&speech(50), now);
    assert_eq!(waits.due(), Some(now + CAPTION_WAIT));
    for expected in [4, 8, 8] {
        waits.forced_end();
        assert_eq!(waits.due(), None);
        waits.answered(Answer::Empty);
        assert_eq!(waits.wait, Duration::from_secs(expected), "doubled, up to {CAPTION_WAIT_MAX:?}");
        waits.sent_at(&speech(50), now);
    }
    waits.forced_end();
    waits.answered(Answer::Refused);
    assert_eq!(waits.wait, CAPTION_WAIT_MAX);
    waits.caption();
    assert_eq!(waits.wait, CAPTION_WAIT, "a real caption starts over");
    // Stop's own commit and one in flight: both counted.
    waits.forced_end();
    waits.forced += 1;
    waits.answered(Answer::Text);
    assert_eq!(waits.forced, 1);
    waits.answered(Answer::Empty);
    assert_eq!(waits.forced, 0, "every commit answered");
}

#[test]
fn the_answers_to_a_forced_end_are_read_from_each_provider() {
    use desktop_lib::stt::stream::{Answer, Protocol, commit_answer};
    let eleven = Protocol::ElevenLabsRealtime;
    assert_eq!(commit_answer(eleven, r#"{"message_type":"committed_transcript","text":"Hi."}"#), Some(Answer::Text));
    assert_eq!(commit_answer(eleven, r#"{"message_type":"committed_transcript","text":" "}"#), Some(Answer::Empty));
    assert_eq!(commit_answer(eleven, r#"{"message_type":"commit_throttled","error":"Commit throttled"}"#), Some(Answer::Refused));
    assert_eq!(commit_answer(eleven, r#"{"message_type":"insufficient_audio_activity","error":"Not enough audio"}"#), Some(Answer::Refused));
    assert_eq!(commit_answer(eleven, r#"{"message_type":"partial_transcript","text":"hi"}"#), None);
    assert_eq!(commit_answer(eleven, r#"{"message_type":"quota_exceeded","error":"Quota"}"#), None, "a real failure stays one");
    let assembly = Protocol::AssemblyAiV3;
    assert_eq!(commit_answer(assembly, r#"{"type":"Turn","end_of_turn":true,"turn_is_formatted":true,"transcript":"Hi."}"#), Some(Answer::Text));
    assert_eq!(commit_answer(assembly, r#"{"type":"Turn","end_of_turn":true,"turn_is_formatted":true,"transcript":""}"#), Some(Answer::Empty));
    assert_eq!(commit_answer(assembly, r#"{"type":"Turn","end_of_turn":false,"turn_is_formatted":false,"transcript":"hi"}"#), None);
}

/// An ElevenLabs stand-in under continuous noise: empty partials every 300 ms (when `empty_partials`), and each commit
/// answered with `answer` (the committed text, or a refusal) after `delay`. Logs when each commit came.
async fn noisy_elevenlabs(mut ws: Ws, commits: Arc<Mutex<Vec<std::time::Instant>>>, answers: Vec<Value>, delay: Duration, empty_partials: bool) {
    ws.send(Message::text(json!({"message_type": "session_started", "session_id": "s1", "config": {}}).to_string())).await.unwrap();
    let mut answers = answers.into_iter().cycle();
    let mut partial = tokio::time::interval(Duration::from_millis(300));
    let (reply_tx, mut reply_rx) = unbounded_channel::<Value>();
    loop {
        tokio::select! {
            message = ws.next() => {
                let Some(Ok(message)) = message else { return };
                match message {
                    Message::Text(text) => {
                        let v: Value = serde_json::from_str(text.as_str()).unwrap();
                        if v["commit"] == true {
                            commits.lock().unwrap().push(std::time::Instant::now());
                            let (answer, replies) = (answers.next().unwrap(), reply_tx.clone());
                            tokio::spawn(async move {
                                tokio::time::sleep(delay).await;
                                let _ = replies.send(answer);
                            });
                        }
                    }
                    Message::Close(_) => return,
                    _ => {}
                }
            }
            Some(reply) = reply_rx.recv() => {
                if ws.send(Message::text(reply.to_string())).await.is_err() { return; }
            }
            _ = partial.tick(), if empty_partials => {
                let _ = ws.send(Message::text(json!({"message_type": "partial_transcript", "text": ""}).to_string())).await;
            }
        }
    }
}

#[tokio::test]
async fn a_throttled_commit_is_no_failure_and_the_next_one_waits_longer() {
    let commits: Arc<Mutex<Vec<std::time::Instant>>> = Arc::default();
    let seen = commits.clone();
    let throttled = json!({"message_type": "commit_throttled", "error": "Commit throttled"});
    let (port, _server) = serve(move |ws| noisy_elevenlabs(ws, seen, vec![throttled], Duration::from_millis(50), false)).await;
    let client = start_client(grant("elevenlabs_realtime", port, 100, vec![]));
    let fed = feed_real_time(client.pcm.clone(), speech(7_500));
    tokio::time::sleep(Duration::from_millis(7_000)).await;
    let at: Vec<Duration> = commits.lock().unwrap().iter().map(|t| t.duration_since(fed)).collect();
    assert_eq!(at.len(), 2, "after 2 s, then 4 s later: {at:?}");
    assert!(at[1] - at[0] >= Duration::from_millis(3_800), "the wait doubled: {at:?}");
    assert!(!client.events().iter().any(|e| matches!(e, SttEvent::Error(_))), "never shown as stopped: {:?}", client.events());
    client.finish(Duration::from_secs(5)).await.unwrap();
}

#[tokio::test]
async fn continuous_noise_with_only_empty_partials_backs_off_and_shows_nothing() {
    let commits: Arc<Mutex<Vec<std::time::Instant>>> = Arc::default();
    let seen = commits.clone();
    let empty = json!({"message_type": "committed_transcript", "text": ""});
    let (port, _server) = serve(move |ws| noisy_elevenlabs(ws, seen, vec![empty], Duration::from_millis(50), true)).await;
    let client = start_client(grant("elevenlabs_realtime", port, 100, vec![]));
    let fed = feed_real_time(client.pcm.clone(), speech(7_500));
    tokio::time::sleep(Duration::from_millis(7_000)).await;
    let at: Vec<Duration> = commits.lock().unwrap().iter().map(|t| t.duration_since(fed)).collect();
    assert_eq!(at.len(), 2, "empty partials are no caption, empty commits double the wait: {at:?}");
    assert!(client.events().iter().all(|e| !matches!(e, SttEvent::Error(_) | SttEvent::Final { .. })), "{:?}", client.events());
    client.finish(Duration::from_secs(5)).await.unwrap();
}

#[tokio::test]
async fn stop_waits_for_every_commit_still_open_so_the_last_words_arrive() {
    let commits: Arc<Mutex<Vec<std::time::Instant>>> = Arc::default();
    let seen = commits.clone();
    let answers = vec![json!({"message_type": "committed_transcript", "text": "First part."}), json!({"message_type": "committed_transcript", "text": "Last words."})];
    let (port, _server) = serve(move |ws| noisy_elevenlabs(ws, seen, answers, Duration::from_millis(600), false)).await;
    let client = start_client(grant("elevenlabs_realtime", port, 100, vec![]));
    feed_real_time(client.pcm.clone(), speech(2_200));
    let waiting = commits.clone();
    wait_for("the forced commit", Duration::from_secs(4), || !waiting.lock().unwrap().is_empty()).await;
    // Stop while the forced commit's answer is still on its way.
    let events = client.events.clone();
    client.finish(Duration::from_secs(5)).await.unwrap();
    let finals: Vec<String> = events.lock().unwrap().iter().filter_map(|e| if let SttEvent::Final { text, .. } = e { Some(text.clone()) } else { None }).collect();
    assert_eq!(finals, ["First part.", "Last words."], "both commits answered before the close");
}

#[tokio::test]
async fn a_dictation_session_whose_start_takes_two_seconds_keeps_its_first_words() {
    let log: Log = Arc::default();
    let server_log = log.clone();
    let (port, _server) = serve(move |ws| assemblyai_server(ws, server_log, Duration::from_millis(2_000), true, vec![])).await;
    let (pcm, pcm_rx) = unbounded_channel();
    let (stop, stop_rx) = watch::channel(false);
    let task = tokio::spawn(desktop_lib::stt::stream::run_session_with(
        grant("assemblyai_v3", port, 50, vec![]),
        pcm_rx,
        stop_rx,
        |_| {},
        desktop_lib::stt::stream::SessionOptions::DICTATION,
    ));
    let spoken = pcm_bytes(64_000, 9); // the first two seconds, said before the provider started
    for piece in spoken.chunks(3_200) {
        pcm.send(piece.to_vec()).unwrap();
        tokio::time::sleep(Duration::from_millis(100)).await;
    }
    wait_for("the first words", Duration::from_secs(5), || binaries(&log).concat().len() >= 64_000).await;
    let _ = stop.send(true);
    tokio::time::timeout(Duration::from_secs(5), task).await.unwrap().unwrap().unwrap();
    assert_eq!(binaries(&log).concat()[..64_000], spoken[..], "nothing said before the start is lost");
}
