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
async fn at_most_ten_seconds_of_audio_are_buffered_while_waiting_for_the_session() {
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
        binaries(&log).concat().len() >= 320_000
    })
    .await;
    tokio::time::sleep(Duration::from_millis(200)).await;
    client.finish(Duration::from_secs(5)).await.unwrap();
    let received = binaries(&log).concat();
    assert_eq!(received.len(), 320_000, "ten seconds");
    assert_eq!(
        received,
        pushed[pushed.len() - 320_000..],
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
