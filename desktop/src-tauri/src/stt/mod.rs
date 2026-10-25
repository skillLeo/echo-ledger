//! Live captions controller: mints a token from the server (never holds provider keys), opens one provider
//! session per channel, and fans caption events out to the overlay and main window.

pub mod captions;
pub mod protocol;
pub mod stream;

use std::sync::Mutex;
use std::time::{Instant, SystemTime, UNIX_EPOCH};

use tauri::{AppHandle, Manager};
use tokio::sync::mpsc::{UnboundedSender, unbounded_channel};
use tokio::sync::watch;

use crate::audio::types::Channel;
use crate::error::{AppError, AppResult};
use captions::{CaptionEvent, CaptionHub, FinalTurn};
use protocol::SttEvent;

pub fn now_ms() -> u64 {
    SystemTime::now().duration_since(UNIX_EPOCH).map(|d| d.as_millis() as u64).unwrap_or(0)
}

struct Live {
    stops: Vec<watch::Sender<bool>>,
    provider: Option<String>,
}

#[derive(Default)]
pub struct LiveCaptions {
    live: Mutex<Option<Live>>,
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
    /// live transcript + copy-last-2-minutes; `offset_from` is the recording start (session-relative times).
    pub async fn start(
        &self,
        app: &AppHandle,
        use_case: &str,
        channels: &[Channel],
        targets: &'static [&'static str],
        record_turns: bool,
        offset_from: Option<Instant>,
    ) -> AppResult<Vec<(Channel, UnboundedSender<Vec<u8>>)>> {
        self.stop();
        let state = app.state::<crate::AppState>();
        let api = state.api()?;
        let client_id = state.settings().client_id.clone();
        let names: Vec<&str> = channels.iter().map(|c| c.grant_name()).collect();
        let token = api.stt_token(use_case, &names, client_id.as_deref(), None).await?;
        if token.mode != "cloud" || token.grants.is_empty() {
            let message = match token.mode.as_str() {
                "off" => "Live captions are turned off in admin Settings.",
                "on_device" => "The selected engine runs on-device; live captions need a cloud engine.",
                _ => "No live caption credentials were returned.",
            };
            return Err(AppError::msg(message));
        }

        let mut senders = Vec::new();
        let mut stops = Vec::new();
        for grant in token.grants {
            let channel = match grant.channel.as_str() {
                "client" => Channel::System,
                _ => Channel::Mic,
            };
            if !channels.contains(&channel) {
                continue;
            }
            if stream::Protocol::from_grant(&grant).is_none() {
                return Err(AppError::msg("This live-caption engine is not supported on desktop yet. Choose AssemblyAI or ElevenLabs for live captions."));
            }
            let (pcm_tx, pcm_rx) = unbounded_channel::<Vec<u8>>();
            let (stop_tx, stop_rx) = watch::channel(false);
            let handle = app.clone();
            let stream_start = Instant::now();
            let base_offset = offset_from.map(|t| stream_start.saturating_duration_since(t).as_millis() as u64).unwrap_or(0);
            tauri::async_runtime::spawn(async move {
                let hub_app = handle.clone();
                let result = stream::run_session(grant, pcm_rx, stop_rx, move |event| {
                    on_stt_event(&hub_app, channel, targets, record_turns, (base_offset, stream_start), event);
                })
                .await;
                if let Err(e) = result {
                    handle.state::<CaptionHub>().send(targets, CaptionEvent::Status { live: false, recording: false, provider: None, message: Some(e) });
                }
            });
            senders.push((channel, pcm_tx));
            stops.push(stop_tx);
        }
        *self.live.lock().unwrap() = Some(Live { stops, provider: token.provider.clone() });
        app.state::<CaptionHub>().send(targets, CaptionEvent::Status { live: true, recording: state.capture.is_recording(), provider: token.provider, message: None });
        Ok(senders)
    }

    /// Asks every session to terminate (they flush their final turn first).
    pub fn stop(&self) {
        if let Some(live) = self.live.lock().unwrap().take() {
            for stop in live.stops {
                let _ = stop.send(true);
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
