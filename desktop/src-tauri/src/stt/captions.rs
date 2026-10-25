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

/// Recording-timeline times (ms) of a final turn. Provider times count from the stream start, which sits
/// `base_offset` ms into the recording. A turn without provider timing is placed where it arrived (`arrived_ms`
/// after the stream start), so turns from both channels keep their order (A6.DR11).
pub fn turn_times(base_offset: u64, start_ms: Option<u64>, end_ms: Option<u64>, arrived_ms: u64) -> (u64, u64) {
    match start_ms {
        Some(start) => (base_offset + start, base_offset + end_ms.unwrap_or(start)),
        None => (base_offset + arrived_ms, base_offset + arrived_ms),
    }
}

/// Rolling buffer of final turns (pure; unit-tested).
#[derive(Default)]
pub struct CaptionBuffer {
    turns: VecDeque<FinalTurn>,
    /// Start time given to the last turn that arrived without provider timing.
    last_untimed_start: Option<u64>,
}

impl CaptionBuffer {
    const MAX_TURNS: usize = 5_000;

    pub fn push(&mut self, mut turn: FinalTurn) {
        // A turn without provider timing has no length (ElevenLabs commits carry no word times). Such turns get
        // strictly increasing start times in arrival order, so `segments()` keeps the conversation order across
        // both channels instead of listing all of Me before all of Client (A6.DR11).
        if turn.end_ms <= turn.start_ms {
            if let Some(last) = self.last_untimed_start
                && turn.start_ms <= last
            {
                turn.start_ms = last + 1;
                turn.end_ms = turn.start_ms;
            }
            self.last_untimed_start = Some(turn.start_ms);
        }
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
        self.last_untimed_start = None;
    }

    pub fn len(&self) -> usize {
        self.turns.len()
    }

    pub fn is_empty(&self) -> bool {
        self.turns.is_empty()
    }
}
