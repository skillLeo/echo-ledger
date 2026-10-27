//! Live captions controller: mints a token from the server (never holds provider keys), opens one provider
//! session per channel, and fans caption events out to the overlay and main window. Every run (sockets opened
//! together, closed by stop, pause or the end of the recording) is reported once to `usage.json` (A5.4).

pub mod captions;
pub mod protocol;
pub mod stream;

use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

use tauri::{AppHandle, Manager};
use tokio::sync::Notify;
use tokio::sync::mpsc::{UnboundedSender, unbounded_channel};
use tokio::sync::watch;

use crate::audio::types::Channel;
use crate::error::{AppError, AppResult};
use crate::net::models::UsageReport;
use captions::{CaptionEvent, CaptionHub, FinalTurn};
use protocol::SttEvent;
use stream::SessionUsage;

pub fn now_ms() -> u64 {
    SystemTime::now().duration_since(UNIX_EPOCH).map(|d| d.as_millis() as u64).unwrap_or(0)
}

/// Shown (caption status and `app:error`) when the server refuses tokens for the month (docs/API.md).
pub const BUDGET_REACHED: &str = "Monthly limit reached — raise it in Settings";
/// `409 session_not_recording`: the open session is no longer recording or older than 4 hours.
pub const CAPTIONS_TOO_LONG: &str = "Live captions stopped: this recording is too long for captions. Recording continues.";
/// Network trouble or a provider outage while starting captions.
pub const CAPTIONS_UNAVAILABLE: &str = "Live captions are unavailable right now. Recording continues.";

fn is_transient(e: &AppError) -> bool {
    match e {
        AppError::Network(_) => true,
        AppError::Server { status, .. } => *status == 408 || *status == 429 || *status >= 500,
        _ => false,
    }
}

/// The message a failed token (or open session) request shows (pure). `budget_exceeded` reads the same for every
/// use case; for live captions a too-long session, a lost session and transient failures get their own copy,
/// because the recording itself always continues.
pub fn token_error(use_case: &str, e: AppError) -> AppError {
    let captions = use_case == "live_captions";
    match e.code() {
        Some("budget_exceeded") => AppError::msg(BUDGET_REACHED),
        Some("session_not_recording") if captions => AppError::msg(CAPTIONS_TOO_LONG),
        Some("session_not_found") if captions => AppError::msg(CAPTIONS_UNAVAILABLE),
        _ if captions && is_transient(&e) => AppError::msg(CAPTIONS_UNAVAILABLE),
        _ => e,
    }
}

/// The usage report of one run (pure): `streamed_ms` is the longest socket (provider seconds × 1000 when known,
/// else the measured open time), `channels` the sockets that were opened. `None` when no socket opened.
pub fn usage_report(use_case: &str, provider: &str, session_id: Option<String>, sockets: &[SessionUsage], ended_at: &str) -> Option<UsageReport> {
    let opened: Vec<&SessionUsage> = sockets.iter().filter(|s| s.opened()).collect();
    if opened.is_empty() || provider.is_empty() {
        return None;
    }
    const MAX_STREAMED_MS: u64 = 86_400_000; // docs/API.md: 0-86 400 000 per channel
    let streamed_ms = opened.iter().map(|s| s.streamed_ms()).max().unwrap_or(0).min(MAX_STREAMED_MS);
    Some(UsageReport {
        report_id: uuid::Uuid::new_v4().to_string(),
        use_case: use_case.to_string(),
        provider: provider.to_string(),
        session_id,
        streamed_ms,
        channels: opened.len().clamp(1, 2) as u8,
        ended_at: ended_at.to_string(),
    })
}

/// Sockets of one run and what they reported so far.
struct RunUsage {
    use_case: String,
    provider: String,
    session_id: Option<String>,
    expected: usize,
    done: Vec<SessionUsage>,
}

impl RunUsage {
    /// Records one socket; returns the run's report once every socket has ended.
    fn finish_one(&mut self, usage: SessionUsage) -> Option<UsageReport> {
        self.done.push(usage);
        if self.done.len() < self.expected {
            return None;
        }
        usage_report(&self.use_case, &self.provider, self.session_id.clone(), &self.done, &chrono::Utc::now().to_rfc3339())
    }
}

struct Live {
    stops: Vec<watch::Sender<bool>>,
    provider: Option<String>,
}

#[derive(Default)]
pub struct LiveCaptions {
    live: Mutex<Option<Live>>,
    /// Provider sockets still running (also after `stop`, until they have closed).
    running: Arc<AtomicUsize>,
    idle: Arc<Notify>,
}

impl LiveCaptions {
    pub fn is_live(&self) -> bool {
        self.live.lock().map(|l| l.is_some()).unwrap_or(false)
    }

    pub fn provider(&self) -> Option<String> {
        self.live.lock().ok()?.as_ref()?.provider.clone()
    }

    /// Starts provider sessions for `channels` and returns PCM senders to attach to the capture.
    /// `targets` are the window labels that receive caption events; `record_turns` stores finals for the
    /// live transcript + copy-last-2-minutes; `base_offset_ms` is the recording time at stream start (paused time
    /// excluded), so caption times sit on the recording timeline; `session_id` is the open server session of the
    /// recording (live captions on a v1.1 server), sent with the token request and the usage report; `language` is
    /// the recording's language chip (none: the server decides).
    #[allow(clippy::too_many_arguments)]
    pub async fn start(
        &self,
        app: &AppHandle,
        use_case: &str,
        channels: &[Channel],
        targets: &'static [&'static str],
        record_turns: bool,
        base_offset_ms: u64,
        session_id: Option<String>,
        language: Option<String>,
    ) -> AppResult<Vec<(Channel, UnboundedSender<Vec<u8>>)>> {
        self.stop();
        let state = app.state::<crate::AppState>();
        let api = state.api()?;
        let names: Vec<&'static str> = channels.iter().map(|c| c.grant_name()).collect();
        let client_id = state.settings().client_id.clone();
        let fetched = crate::net::api::with_client_fallback(client_id, |client| {
            let (api, names, session, language) = (api.clone(), names.clone(), session_id.clone(), language.clone());
            let use_case = use_case.to_string();
            async move { api.stt_token(&use_case, &names, client.as_deref(), session.as_deref(), language.as_deref()).await }
        })
        .await;
        let token = match fetched {
            Ok((token, cleared)) => {
                if let Some(client) = cleared {
                    crate::actions::forget_client(app, &client);
                }
                token
            }
            Err(e) => {
                if e.code() == Some("budget_exceeded") {
                    state.budget_blocked.store(true, Ordering::SeqCst);
                    crate::actions::emit_status(app);
                }
                return Err(token_error(use_case, e));
            }
        };
        if token.mode != "cloud" || token.grants.is_empty() {
            let message = match token.mode.as_str() {
                "off" => "Live captions are turned off in admin Settings.",
                "on_device" => "The selected engine runs on-device; live captions need a cloud engine.",
                _ => "No live caption credentials were returned.",
            };
            return Err(AppError::msg(message));
        }

        let grants: Vec<_> = token
            .grants
            .into_iter()
            .filter_map(|grant| {
                let channel = match grant.channel.as_str() {
                    "client" => Channel::System,
                    _ => Channel::Mic,
                };
                channels.contains(&channel).then_some((channel, grant))
            })
            .collect();
        if grants.iter().any(|(_, grant)| stream::Protocol::from_grant(grant).is_none()) {
            return Err(AppError::msg("This live-caption engine is not supported on desktop yet. Choose AssemblyAI or ElevenLabs for live captions."));
        }

        let run = Arc::new(Mutex::new(RunUsage {
            use_case: use_case.to_string(),
            provider: token.provider.clone().unwrap_or_default(),
            session_id,
            expected: grants.len(),
            done: Vec::new(),
        }));
        let mut senders = Vec::new();
        let mut stops = Vec::new();
        for (channel, grant) in grants {
            let (pcm_tx, pcm_rx) = unbounded_channel::<Vec<u8>>();
            let (stop_tx, stop_rx) = watch::channel(false);
            let handle = app.clone();
            let stream_start = Instant::now();
            let (run, running, idle) = (run.clone(), self.running.clone(), self.idle.clone());
            running.fetch_add(1, Ordering::SeqCst);
            tauri::async_runtime::spawn(async move {
                let hub_app = handle.clone();
                let mut usage = SessionUsage::default();
                let result = stream::run_session_into(
                    grant,
                    pcm_rx,
                    stop_rx,
                    move |event| on_stt_event(&hub_app, channel, targets, record_turns, (base_offset_ms, stream_start), event),
                    &mut usage,
                )
                .await;
                if let Err(e) = result {
                    handle.state::<CaptionHub>().send(targets, CaptionEvent::Status { live: false, recording: false, provider: None, message: Some(e) });
                }
                let report = run.lock().unwrap_or_else(|p| p.into_inner()).finish_one(usage);
                if let Some(report) = report
                    && let Err(e) = crate::actions::queue_usage_report(&handle, report)
                {
                    eprintln!("[echoledger] usage report not queued: {e}");
                }
                if running.fetch_sub(1, Ordering::SeqCst) == 1 {
                    idle.notify_waiters();
                }
            });
            senders.push((channel, pcm_tx));
            stops.push(stop_tx);
        }
        *self.live.lock().unwrap_or_else(|p| p.into_inner()) = Some(Live { stops, provider: token.provider.clone() });
        app.state::<CaptionHub>().send(targets, CaptionEvent::Status { live: true, recording: state.capture.is_recording(), provider: token.provider, message: None });
        Ok(senders)
    }

    /// Asks every session to terminate (they flush their final turn first).
    pub fn stop(&self) {
        if let Some(live) = self.live.lock().unwrap_or_else(|p| p.into_inner()).take() {
            for stop in live.stops {
                let _ = stop.send(true);
            }
        }
    }

    /// Waits until every socket has closed (and its run is reported), at most `limit`.
    pub async fn wait_closed(&self, limit: Duration) {
        let deadline = tokio::time::Instant::now() + limit;
        loop {
            let notified = self.idle.notified();
            if self.running.load(Ordering::SeqCst) == 0 {
                return;
            }
            if tokio::time::timeout_at(deadline, notified).await.is_err() {
                return;
            }
        }
    }
}

/// `stream`: the stream's offset into the recording (ms) and the instant the stream started.
fn on_stt_event(app: &AppHandle, channel: Channel, targets: &[&str], record_turns: bool, stream: (u64, Instant), event: SttEvent) {
    let hub = app.state::<CaptionHub>();
    let speaker = channel.speaker().to_string();
    let index = (channel.index() + 1) as u8;
    match event {
        SttEvent::Partial { text } => hub.send(targets, CaptionEvent::Partial { speaker, channel: index, text }),
        SttEvent::Final { text, start_ms, end_ms } => {
            let arrived_ms = stream.1.elapsed().as_millis() as u64;
            let (start, end) = captions::turn_times(stream.0, start_ms, end_ms, arrived_ms);
            if record_turns {
                let state = app.state::<crate::AppState>();
                state.captions.lock().unwrap().push(FinalTurn { wall_ms: now_ms(), channel, text: text.clone(), start_ms: start, end_ms: end });
            } else {
                let state = app.state::<crate::AppState>();
                state.dictation_text.lock().unwrap().push(text.clone());
            }
            hub.send(targets, CaptionEvent::Final { speaker, channel: index, text, start_ms: start, end_ms: end });
        }
        SttEvent::Error(message) => hub.send(targets, CaptionEvent::Status { live: false, recording: false, provider: None, message: Some(message) }),
        SttEvent::Started | SttEvent::Terminated => {}
    }
}
