//! Caption events for the overlay and a rolling buffer of final turns ("copy last 2 minutes", live transcript upload).

use std::collections::VecDeque;
use std::sync::Mutex;

use serde::Serialize;
use tauri::ipc::Channel as IpcChannel;

use crate::audio::types::Channel;
use crate::net::models::SegmentDto;

#[derive(Clone, Debug, Serialize)]
#[serde(rename_all = "camelCase", rename_all_fields = "camelCase", tag = "kind")]
pub enum CaptionEvent {
    Partial { speaker: String, channel: u8, text: String },
    Final { speaker: String, channel: u8, text: String, start_ms: u64, end_ms: u64 },
    Status { live: bool, recording: bool, provider: Option<String>, message: Option<String> },
    Clear,
}

/// Fan-out to every subscribed webview (overlay, dictation box, main window).
#[derive(Default)]
pub struct CaptionHub {
    subscribers: Mutex<Vec<(String, IpcChannel<CaptionEvent>)>>,
}

impl CaptionHub {
    pub fn subscribe(&self, label: &str, channel: IpcChannel<CaptionEvent>) {
        let mut subs = self.subscribers.lock().unwrap();
        subs.retain(|(l, _)| l != label); // one channel per window (replaced on remount)
        subs.push((label.to_string(), channel));
    }

    pub fn send(&self, targets: &[&str], event: CaptionEvent) {
        let mut subs = self.subscribers.lock().unwrap();
        subs.retain(|(label, ch)| !targets.contains(&label.as_str()) || ch.send(event.clone()).is_ok());
    }
}

#[derive(Clone, Debug, PartialEq)]
pub struct FinalTurn {
    pub wall_ms: u64,
    pub channel: Channel,
    pub text: String,
    /// Milliseconds from the start of the recording (session-relative).
    pub start_ms: u64,
    pub end_ms: u64,
}

/// Rolling buffer of final turns (pure; unit-tested).
#[derive(Default)]
pub struct CaptionBuffer {
    turns: VecDeque<FinalTurn>,
}

impl CaptionBuffer {
    const MAX_TURNS: usize = 5_000;

    pub fn push(&mut self, turn: FinalTurn) {
        if self.turns.len() >= Self::MAX_TURNS {
            self.turns.pop_front();
        }
        self.turns.push_back(turn);
    }

    /// Plain-text transcript of turns finalized in the last `window_ms`, oldest first ("Me: …" / "Client: …").
    pub fn text_since(&self, now_ms: u64, window_ms: u64) -> String {
        let cutoff = now_ms.saturating_sub(window_ms);
        self.turns
            .iter()
            .filter(|t| t.wall_ms >= cutoff)
            .map(|t| format!("{}: {}", t.channel.speaker(), t.text))
            .collect::<Vec<_>>()
            .join("\n")
    }

    /// Segments for the `live` transcript upload, sorted by start time.
    pub fn segments(&self) -> Vec<SegmentDto> {
        let mut out: Vec<SegmentDto> = self
            .turns
            .iter()
            .map(|t| SegmentDto {
                channel: (t.channel.index() + 1) as u8,
                speaker: t.channel.speaker().to_string(),
                start_ms: t.start_ms,
                end_ms: t.end_ms.max(t.start_ms),
                text: t.text.clone(),
                words: vec![],
            })
            .collect();
        out.sort_by_key(|s| (s.start_ms, s.channel));
        out
    }

    pub fn clear(&mut self) {
        self.turns.clear();
    }

    pub fn len(&self) -> usize {
        self.turns.len()
    }

    pub fn is_empty(&self) -> bool {
        self.turns.is_empty()
    }
}
