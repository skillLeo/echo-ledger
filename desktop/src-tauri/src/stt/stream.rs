//! One provider WebSocket session for one mono channel: connect with the server-minted token URL (WebPKI TLS,
//! no pin — third-party host), send init messages after the session-start message, stream PCM frames, and on stop
//! send the provider's terminate message and wait for the last transcript.

use std::time::Duration;

use futures_util::{SinkExt, StreamExt};
use tokio::sync::mpsc::UnboundedReceiver;
use tokio::sync::watch;
use tokio_tungstenite::tungstenite::{ClientRequestBuilder, Message, http::Uri};
use tokio_tungstenite::{Connector, connect_async_tls_with_config};

use super::protocol::{ASSEMBLYAI_FORCE_ENDPOINT, ASSEMBLYAI_TERMINATE, SttEvent, assemblyai_session_seconds, elevenlabs_audio_message, parse_assemblyai, parse_elevenlabs};
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

/// How long one provider socket was open (A5.4): `open_ms` from the successful connect to the close, and the
/// provider's own count of billed seconds when it reported one (AssemblyAI `Termination.session_duration_seconds`).
#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub struct SessionUsage {
    pub open_ms: u64,
    pub provider_session_s: Option<u64>,
}

impl SessionUsage {
    /// True when the socket was opened at all (only such sockets are reported).
    pub fn opened(&self) -> bool {
        self.open_ms > 0 || self.provider_session_s.is_some()
    }

    /// Billed length of this socket: the provider's seconds when known, else the measured open time.
    pub fn streamed_ms(&self) -> u64 {
        self.provider_session_s.map(|s| s.saturating_mul(1000)).unwrap_or(self.open_ms)
    }
}

/// What a session is for, as far as the socket cares.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct SessionOptions {
    /// Audio kept while the provider has not started (the newest, in whole frames).
    pub pre_begin: Duration,
}

impl SessionOptions {
    /// Captions and Live Assist: one second, so a provider throttled to about 1.25x real time never starts behind.
    pub const LIVE: Self = Self { pre_begin: Duration::from_secs(1) };
    /// Dictation: ten seconds, because its first words are the result.
    pub const DICTATION: Self = Self { pre_begin: Duration::from_secs(10) };

    /// The options for a token's use case (pure): `dictation` keeps its first words, everything else is live.
    pub fn for_use_case(use_case: &str) -> Self {
        if use_case == "dictation" { Self::DICTATION } else { Self::LIVE }
    }
}

/// Runs until `stop` flips to true or the provider closes. `on_event` receives parsed events.
pub async fn run_session(
    grant: Grant,
    pcm: UnboundedReceiver<Vec<u8>>,
    stop: watch::Receiver<bool>,
    on_event: impl Fn(SttEvent) + Send + 'static,
) -> Result<(), String> {
    run_session_with(grant, pcm, stop, on_event, SessionOptions::LIVE).await
}

/// [`run_session`] with the session's options.
pub async fn run_session_with(
    grant: Grant,
    pcm: UnboundedReceiver<Vec<u8>>,
    stop: watch::Receiver<bool>,
    on_event: impl Fn(SttEvent) + Send + 'static,
    options: SessionOptions,
) -> Result<(), String> {
    let mut usage = SessionUsage::default();
    run_session_into(grant, pcm, stop, on_event, options, &mut usage).await
}

/// [`run_session`] that also returns how long the socket was open.
pub async fn run_session_with_usage(
    grant: Grant,
    pcm: UnboundedReceiver<Vec<u8>>,
    stop: watch::Receiver<bool>,
    on_event: impl Fn(SttEvent) + Send + 'static,
) -> Result<SessionUsage, String> {
    let mut usage = SessionUsage::default();
    run_session_into(grant, pcm, stop, on_event, SessionOptions::LIVE, &mut usage).await.map(|_| usage)
}

/// [`run_session`] writing the socket's usage into `usage` on every way out, so a session that fails after it
/// connected is still reported (the provider bills it).
pub async fn run_session_into(
    grant: Grant,
    pcm: UnboundedReceiver<Vec<u8>>,
    stop: watch::Receiver<bool>,
    on_event: impl Fn(SttEvent) + Send + 'static,
    options: SessionOptions,
    usage: &mut SessionUsage,
) -> Result<(), String> {
    let protocol = Protocol::from_grant(&grant).ok_or_else(|| format!("unsupported streaming protocol {:?}", grant.protocol))?;
    let url = grant.ws_url.clone().ok_or("grant has no ws_url")?;
    let uri: Uri = url.parse().map_err(|e| format!("invalid ws url: {e}"))?;
    let request = ClientRequestBuilder::new(uri).with_header("User-Agent", concat!("EchoLedger-Desktop/", env!("CARGO_PKG_VERSION")));
    let (ws, _) = connect_async_tls_with_config(request, None, true, Some(Connector::Rustls(public_tls_config())))
        .await
        .map_err(|e| format!("connect: {e}"))?;
    let connected = std::time::Instant::now();
    let mut provider_seconds: Option<u64> = None;
    let result = stream_socket(ws, &grant, protocol, pcm, stop, &on_event, options, &mut provider_seconds).await;
    usage.open_ms = (connected.elapsed().as_millis() as u64).max(1);
    usage.provider_session_s = provider_seconds;
    result
}

type Socket = tokio_tungstenite::WebSocketStream<tokio_tungstenite::MaybeTlsStream<tokio::net::TcpStream>>;

#[allow(clippy::too_many_arguments)]
async fn stream_socket(
    ws: Socket,
    grant: &Grant,
    protocol: Protocol,
    mut pcm: UnboundedReceiver<Vec<u8>>,
    mut stop: watch::Receiver<bool>,
    on_event: &(impl Fn(SttEvent) + Send + 'static),
    options: SessionOptions,
    provider_seconds: &mut Option<u64>,
) -> Result<(), String> {
    let frame_ms = grant.audio.as_ref().map(|a| a.frame_ms).unwrap_or(50).clamp(50, 250);
    let frame_bytes = (16_000 * 2 * frame_ms / 1000) as usize;
    let (mut tx, mut rx) = ws.split();

    let mut started = false;
    let mut pending: Vec<u8> = Vec::new();
    // Before Begin: live sessions keep one second (more, sent at once, leaves a provider throttled to about 1.25x real
    // time catching up for four times as long); dictation keeps ten, its first words being the result.
    let max_buffer = pre_begin_bytes(frame_bytes, options.pre_begin);
    // A provider that holds its turn open is made to end it (CAPTION_WAIT, backing off when that brings nothing back).
    let mut waits = CaptionWait::default();
    let mut terminating = false;
    // Once Terminate is sent, the provider gets this long to confirm before the socket is closed (A6.DR11).
    let mut terminate_deadline: Option<tokio::time::Instant> = None;
    let mut idle = tokio::time::interval(Duration::from_secs(5));

    loop {
        tokio::select! {
            changed = stop.changed(), if !terminating => {
                if changed.is_err() || *stop.borrow() {
                    terminating = true;
                    if started {
                        let _ = flush_remainder(&mut tx, protocol, &mut pending, frame_bytes).await;
                    }
                    let _ = stop_now(&mut tx, protocol).await;
                    terminate_deadline = Some(tokio::time::Instant::now() + TERMINATE_WAIT);
                    if protocol == Protocol::ElevenLabsRealtime {
                        // ElevenLabs commits and keeps the socket open. Its answers carry no id, so the first one that
                        // comes after Stop's commit is taken as its own; a short grace lets one more in flight (a
                        // forced commit's, a VAD commit's) arrive too, never past TERMINATE_WAIT.
                        waits.forced_end();
                        let cap = tokio::time::Instant::now() + TERMINATE_WAIT;
                        let mut close_at = cap;
                        while let Ok(Some(Ok(message))) = tokio::time::timeout_at(close_at, rx.next()).await {
                            let Message::Text(t) = message else { continue };
                            let raw = t.as_str();
                            if let Some(answer) = commit_answer(protocol, raw) {
                                close_at = close_at.min(tokio::time::Instant::now() + STOP_GRACE);
                                if waits.answered(answer) || answer != Answer::Text {
                                    continue;
                                }
                            }
                            if let Some(ev) = protocol.parse(raw) {
                                on_event(ev);
                            }
                        }
                        let _ = tx.send(Message::Close(None)).await;
                        return Ok(());
                    }
                }
            }
            msg = rx.next() => {
                match msg {
                    Some(Ok(Message::Text(text))) => {
                        if protocol == Protocol::AssemblyAiV3
                            && let Some(seconds) = assemblyai_session_seconds(text.as_str())
                        {
                            *provider_seconds = Some(seconds);
                        }
                        // The answer to a forced end: with text, a caption; without (or throttled, or "not enough
                        // audio"), the wait grows. A refusal after a forced commit is no failure: logged, never shown.
                        if let Some(answer) = commit_answer(protocol, text.as_str())
                            && waits.answered(answer)
                        {
                            eprintln!("[echoledger] captions: the provider declined a forced commit; the wait is now {} ms", waits.wait.as_millis());
                            continue;
                        }
                        if let Some(event) = protocol.parse(text.as_str()) {
                            if let SttEvent::Partial { text } | SttEvent::Final { text, .. } = &event
                                && !text.is_empty()
                            {
                                waits.caption();
                            }
                            match &event {
                                SttEvent::Started if !started => {
                                    started = true;
                                    for init in &grant.init_messages {
                                        tx.send(Message::text(init.to_string())).await.map_err(|e| e.to_string())?;
                                    }
                                    // Whole frames only: AssemblyAI ends the session (3007) on a chunk shorter than
                                    // 50 ms, so a remainder waits in `pending` for the next audio (A6.DR1).
                                    let whole = pending.len() - pending.len() % frame_bytes;
                                    for frame in pending[..whole].chunks(frame_bytes) {
                                        send_audio(&mut tx, protocol, frame).await?;
                                        waits.sent(frame);
                                    }
                                    pending.drain(..whole);
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
                                waits.sent(&frame);
                            }
                        } else if pending.len() > max_buffer {
                            let excess = pending.len() - max_buffer;
                            pending.drain(..excess);
                        }
                    }
                    None => {
                        if started {
                            let _ = flush_remainder(&mut tx, protocol, &mut pending, frame_bytes).await;
                        }
                        let _ = stop_now(&mut tx, protocol).await;
                        terminating = true;
                        terminate_deadline = Some(tokio::time::Instant::now() + TERMINATE_WAIT);
                    }
                }
            }
            _ = tokio::time::sleep_until(terminate_deadline.unwrap_or_else(tokio::time::Instant::now)), if terminate_deadline.is_some() => {
                // The provider never confirmed the end: close and return instead of holding the session open.
                let _ = tx.send(Message::Close(None)).await;
                return Ok(());
            }
            _ = tokio::time::sleep_until(waits.due().unwrap_or_else(tokio::time::Instant::now)), if started && !terminating && waits.due().is_some() => {
                // Speech went out and nothing came back for the wait: the provider holds the turn open (music or noise
                // under the voice keeps it from hearing a pause). Ending the turn brings its text back now.
                let waited = waits.since.map(|at| at.elapsed().as_millis()).unwrap_or_default();
                eprintln!("[echoledger] captions: no caption {waited} ms after speech, the turn is ended now ({protocol:?})");
                waits.forced_end();
                end_turn(&mut tx, protocol).await?;
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

/// How long a stopped session waits for the provider's end-of-session message.
const TERMINATE_WAIT: Duration = Duration::from_secs(3);

/// After the first answer that follows Stop's commit, how long one more in flight may take to arrive.
const STOP_GRACE: Duration = Duration::from_millis(500);

/// How long after a forced end a provider's refusal (`commit_throttled`, `insufficient_audio_activity`) is taken as its
/// answer: logged, never shown. Its answers carry no id, so time is what ties them to the forced end.
pub const REFUSAL_WINDOW: Duration = Duration::from_secs(4);

/// The longest a caption waits once speech has gone out: then the turn is ended (AssemblyAI `ForceEndpoint`, an
/// ElevenLabs commit) and its text comes back. Never reached while the provider sends partials.
pub const CAPTION_WAIT: Duration = Duration::from_secs(2);

/// The longest the wait grows to after forced ends that brought nothing back.
pub const CAPTION_WAIT_MAX: Duration = Duration::from_secs(8);

/// What is kept before the provider's Begin: `keep` of 16 kHz audio, in whole frames (pure).
pub fn pre_begin_bytes(frame_bytes: usize, keep: Duration) -> usize {
    let bytes = (16_000 * 2 * keep.as_millis() / 1000) as usize;
    bytes.div_ceil(frame_bytes).max(1) * frame_bytes
}

/// How a provider answered a forced end of its turn.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Answer {
    /// The turn's text came back.
    Text,
    /// The turn ended with no text (noise, no words).
    Empty,
    /// ElevenLabs declined the commit: `commit_throttled`, `insufficient_audio_activity`.
    Refused,
}

/// A provider message that ends a turn, and how (pure): AssemblyAI's final (formatted) Turn; ElevenLabs' committed
/// transcript, or its refusal of a commit. Anything else: none.
pub fn commit_answer(protocol: Protocol, raw: &str) -> Option<Answer> {
    let v: serde_json::Value = serde_json::from_str(raw).ok()?;
    let text = |key: &str| v.get(key).and_then(serde_json::Value::as_str).is_some_and(|t| !t.trim().is_empty());
    match protocol {
        Protocol::AssemblyAiV3 => {
            let ends = v.get("type")?.as_str()? == "Turn"
                && v.get("end_of_turn").and_then(serde_json::Value::as_bool) == Some(true)
                && v.get("turn_is_formatted").and_then(serde_json::Value::as_bool) == Some(true);
            ends.then(|| if text("transcript") { Answer::Text } else { Answer::Empty })
        }
        Protocol::ElevenLabsRealtime => match v.get("message_type")?.as_str()? {
            "committed_transcript" | "committed_transcript_with_timestamps" => Some(if text("text") { Answer::Text } else { Answer::Empty }),
            "commit_throttled" | "insufficient_audio_activity" => Some(Answer::Refused),
            _ => None,
        },
    }
}

/// When a caption is overdue, and the turns ended to bring one (pure, the clock passed in where it matters).
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct CaptionWait {
    /// The first speech sent since the last caption.
    pub since: Option<tokio::time::Instant>,
    /// How long a caption may take: CAPTION_WAIT, doubled after each forced end that brought no text (to
    /// CAPTION_WAIT_MAX), back to CAPTION_WAIT with the next caption.
    pub wait: Duration,
    /// When the turn was last ended by force (or by Stop): refusals within [`REFUSAL_WINDOW`] of it are its answer.
    pub forced_at: Option<tokio::time::Instant>,
    /// That forced end has not been answered yet (by text, or by nothing).
    pub open: bool,
}

impl Default for CaptionWait {
    fn default() -> Self {
        Self { since: None, wait: CAPTION_WAIT, forced_at: None, open: false }
    }
}

impl CaptionWait {
    /// A frame went out: the first speech since the last caption starts the wait.
    pub fn sent(&mut self, frame: &[u8]) {
        self.sent_at(frame, tokio::time::Instant::now());
    }

    pub fn sent_at(&mut self, frame: &[u8], now: tokio::time::Instant) {
        if self.since.is_none() && voiced(frame) {
            self.since = Some(now);
        }
    }

    /// When the turn is ended, if speech is waiting for a caption.
    pub fn due(&self) -> Option<tokio::time::Instant> {
        self.since.map(|at| at + self.wait)
    }

    /// A caption with text came: the wait starts over.
    pub fn caption(&mut self) {
        self.since = None;
        self.wait = CAPTION_WAIT;
        self.open = false;
    }

    /// The turn was ended now.
    pub fn forced_end(&mut self) {
        self.forced_end_at(tokio::time::Instant::now());
    }

    pub fn forced_end_at(&mut self, now: tokio::time::Instant) {
        self.since = None;
        self.forced_at = Some(now);
        self.open = true;
    }

    /// A turn ended (by force, or the provider's own VAD: the answer does not say). Text resets the wait; the first
    /// nothing (or refusal) within the window of an open forced end doubles it. True for a refusal within the window:
    /// the forced end's answer, logged and never shown.
    pub fn answered(&mut self, answer: Answer) -> bool {
        self.answered_at(answer, tokio::time::Instant::now())
    }

    pub fn answered_at(&mut self, answer: Answer, now: tokio::time::Instant) -> bool {
        let in_window = self.forced_at.is_some_and(|at| now.saturating_duration_since(at) <= REFUSAL_WINDOW);
        match answer {
            Answer::Text => self.caption(),
            Answer::Empty | Answer::Refused if self.open && in_window => {
                self.wait = (self.wait * 2).min(CAPTION_WAIT_MAX);
                self.open = false;
            }
            _ => {}
        }
        answer == Answer::Refused && in_window
    }
}

/// A 16-bit PCM frame loud enough to be someone speaking (pure): room tone and the silence of a quiet app are not.
pub fn voiced(frame: &[u8]) -> bool {
    let samples = frame.len() / 2;
    if samples == 0 {
        return false;
    }
    let energy: f64 = frame.as_chunks::<2>().0.iter().map(|b| f64::from(i16::from_le_bytes(*b)).powi(2)).sum();
    (energy / samples as f64).sqrt() >= VOICE_RMS
}

/// The level (RMS of 16-bit samples, about -36 dBFS) above which a frame counts as speech for [`CAPTION_WAIT`].
const VOICE_RMS: f64 = 500.0;

/// Ends the provider's current turn so its text comes back now.
async fn end_turn(tx: &mut WsSink, protocol: Protocol) -> Result<(), String> {
    let msg = match protocol {
        Protocol::AssemblyAiV3 => Message::text(ASSEMBLYAI_FORCE_ENDPOINT),
        Protocol::ElevenLabsRealtime => Message::text(elevenlabs_audio_message(&[], true)),
    };
    tx.send(msg).await.map_err(|e| e.to_string())
}

/// At the end of a session: sends what is left in `pending` padded with silence to a whole frame, so the last
/// words reach the provider without a short frame (A6.DR1).
async fn flush_remainder(tx: &mut WsSink, protocol: Protocol, pending: &mut Vec<u8>, frame_bytes: usize) -> Result<(), String> {
    if pending.is_empty() {
        return Ok(());
    }
    let padded = pending.len().div_ceil(frame_bytes) * frame_bytes;
    pending.resize(padded, 0);
    for frame in pending.chunks(frame_bytes) {
        send_audio(tx, protocol, frame).await?;
    }
    pending.clear();
    Ok(())
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
