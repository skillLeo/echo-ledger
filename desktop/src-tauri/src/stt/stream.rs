//! One provider WebSocket session for one mono channel: connect with the server-minted token URL (WebPKI TLS,
//! no pin — third-party host), send init messages after the session-start message, stream PCM frames, and on stop
//! send the provider's terminate message and wait for the last transcript.

use std::time::Duration;

use futures_util::{SinkExt, StreamExt};
use tokio::sync::mpsc::UnboundedReceiver;
use tokio::sync::watch;
use tokio_tungstenite::tungstenite::{ClientRequestBuilder, Message, http::Uri};
use tokio_tungstenite::{Connector, connect_async_tls_with_config};

use super::protocol::{ASSEMBLYAI_TERMINATE, SttEvent, elevenlabs_audio_message, parse_assemblyai, parse_elevenlabs};
use crate::net::models::Grant;
use crate::net::pinning::public_tls_config;

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Protocol {
    AssemblyAiV3,
    ElevenLabsRealtime,
}

impl Protocol {
    pub fn from_grant(grant: &Grant) -> Option<Self> {
        match grant.protocol.as_deref() {
            Some("assemblyai_v3") => Some(Self::AssemblyAiV3),
            Some("elevenlabs_realtime") => Some(Self::ElevenLabsRealtime),
            _ => None,
        }
    }

    fn parse(self, raw: &str) -> Option<SttEvent> {
        match self {
            Self::AssemblyAiV3 => parse_assemblyai(raw),
            Self::ElevenLabsRealtime => parse_elevenlabs(raw),
        }
    }
}

/// Runs until `stop` flips to true or the provider closes. `on_event` receives parsed events.
pub async fn run_session(
    grant: Grant,
    mut pcm: UnboundedReceiver<Vec<u8>>,
    mut stop: watch::Receiver<bool>,
    on_event: impl Fn(SttEvent) + Send + 'static,
) -> Result<(), String> {
    let protocol = Protocol::from_grant(&grant).ok_or_else(|| format!("unsupported streaming protocol {:?}", grant.protocol))?;
    let url = grant.ws_url.clone().ok_or("grant has no ws_url")?;
    let frame_ms = grant.audio.as_ref().map(|a| a.frame_ms).unwrap_or(50).clamp(50, 250);
    let frame_bytes = (16_000 * 2 * frame_ms / 1000) as usize;

    let uri: Uri = url.parse().map_err(|e| format!("invalid ws url: {e}"))?;
    let request = ClientRequestBuilder::new(uri).with_header("User-Agent", concat!("EchoLedger-Desktop/", env!("CARGO_PKG_VERSION")));
    let (ws, _) = connect_async_tls_with_config(request, None, true, Some(Connector::Rustls(public_tls_config())))
        .await
        .map_err(|e| format!("connect: {e}"))?;
    let (mut tx, mut rx) = ws.split();

    let mut started = false;
    let mut pending: Vec<u8> = Vec::new();
    let max_buffer = frame_bytes * 200; // ≤ 10 s buffered before Begin
    let mut terminating = false;
    let mut idle = tokio::time::interval(Duration::from_secs(5));

    loop {
        tokio::select! {
            changed = stop.changed(), if !terminating => {
                if changed.is_err() || *stop.borrow() {
                    terminating = true;
                    let bye = match protocol {
                        Protocol::AssemblyAiV3 => Message::text(ASSEMBLYAI_TERMINATE),
                        Protocol::ElevenLabsRealtime => Message::text(elevenlabs_audio_message(&[], true)),
                    };
                    let _ = tx.send(bye).await;
                    if protocol == Protocol::ElevenLabsRealtime {
                        // ElevenLabs commits and keeps the socket open; allow the final commit to arrive.
                        let _ = tokio::time::timeout(Duration::from_secs(3), async {
                            while let Some(Ok(msg)) = rx.next().await {
                                if let Message::Text(t) = msg && let Some(ev) = protocol.parse(t.as_str()) { let done = matches!(ev, SttEvent::Final { .. }); on_event(ev); if done { break; } }
                            }
                        }).await;
                        let _ = tx.send(Message::Close(None)).await;
                        return Ok(());
                    }
                }
            }
            msg = rx.next() => {
                match msg {
                    Some(Ok(Message::Text(text))) => {
                        if let Some(event) = protocol.parse(text.as_str()) {
                            match &event {
                                SttEvent::Started if !started => {
                                    started = true;
                                    for init in &grant.init_messages {
                                        tx.send(Message::text(init.to_string())).await.map_err(|e| e.to_string())?;
                                    }
                                    if !pending.is_empty() {
                                        let buffered = std::mem::take(&mut pending);
                                        for frame in buffered.chunks(frame_bytes) {
                                            send_audio(&mut tx, protocol, frame).await?;
                                        }
                                    }
                                }
                                SttEvent::Terminated => { on_event(event); let _ = tx.send(Message::Close(None)).await; return Ok(()); }
                                _ => {}
                            }
                            on_event(event);
                        }
                    }
                    Some(Ok(Message::Close(_))) | None => return Ok(()),
                    Some(Ok(_)) => {}
                    Some(Err(e)) => return Err(format!("websocket: {e}")),
                }
            }
            audio = pcm.recv(), if !terminating => {
                match audio {
                    Some(bytes) => {
                        pending.extend_from_slice(&bytes);
                        if started {
                            while pending.len() >= frame_bytes {
                                let frame: Vec<u8> = pending.drain(..frame_bytes).collect();
                                send_audio(&mut tx, protocol, &frame).await?;
                            }
                        } else if pending.len() > max_buffer {
                            let excess = pending.len() - max_buffer;
                            pending.drain(..excess);
                        }
                    }
                    None => { let _ = stop_now(&mut tx, protocol).await; terminating = true; }
                }
            }
            _ = idle.tick(), if started && !terminating && protocol == Protocol::ElevenLabsRealtime => {
                // ElevenLabs closes after 15 s without frames; keep the session alive with silence.
                if pending.is_empty() {
                    send_audio(&mut tx, protocol, &vec![0u8; frame_bytes]).await?;
                }
            }
        }
    }
}

type WsSink = futures_util::stream::SplitSink<tokio_tungstenite::WebSocketStream<tokio_tungstenite::MaybeTlsStream<tokio::net::TcpStream>>, Message>;

async fn send_audio(tx: &mut WsSink, protocol: Protocol, frame: &[u8]) -> Result<(), String> {
    let msg = match protocol {
        Protocol::AssemblyAiV3 => Message::binary(frame.to_vec()),
        Protocol::ElevenLabsRealtime => Message::text(elevenlabs_audio_message(frame, false)),
    };
    tx.send(msg).await.map_err(|e| e.to_string())
}

async fn stop_now(tx: &mut WsSink, protocol: Protocol) -> Result<(), String> {
    let msg = match protocol {
        Protocol::AssemblyAiV3 => Message::text(ASSEMBLYAI_TERMINATE),
        Protocol::ElevenLabsRealtime => Message::text(elevenlabs_audio_message(&[], true)),
    };
    tx.send(msg).await.map_err(|e| e.to_string())
}
