//! Bookmarks and pause gaps of one recording (B2, B3). Times are recording time (paused spans excluded), so they
//! line up with the file and the transcript; a pause sits at the recorded time where it began and keeps its wall
//! length (docs/API.md "Bookmarks and pauses").

use crate::net::models::{MarkerDto, PauseDto};

/// The server keeps at most this many bookmarks and pauses per recording.
pub const MAX_MARKERS: usize = 500;
pub const MAX_PAUSES: usize = 200;
pub const MAX_TAG_CHARS: usize = 40;
pub const MAX_NOTE_CHARS: usize = 200;

/// Tags offered when the server sends none (the console's defaults).
pub const DEFAULT_TAGS: [&str; 5] = ["Requirement", "Budget", "Deadline", "Decision", "Follow-up"];
/// At most this many presets are shown.
pub const MAX_PRESETS: usize = 12;

/// The tag presets to offer: the server's (cleaned, unique, at most 12), else [`DEFAULT_TAGS`].
pub fn tag_presets(server: Option<&[String]>) -> Vec<String> {
    let mut tags: Vec<String> = Vec::new();
    for tag in server.unwrap_or_default() {
        if let Some(tag) = clean(Some(tag), MAX_TAG_CHARS)
            && !tags.contains(&tag)
        {
            tags.push(tag);
        }
    }
    tags.truncate(MAX_PRESETS);
    if tags.is_empty() { DEFAULT_TAGS.iter().map(|t| t.to_string()).collect() } else { tags }
}

/// Trimmed, inner whitespace collapsed, cut to `max` characters; blank is none.
pub fn clean(text: Option<&str>, max: usize) -> Option<String> {
    let joined = text?.split_whitespace().collect::<Vec<_>>().join(" ");
    let cut: String = joined.chars().take(max).collect();
    let cut = cut.trim_end().to_string();
    (!cut.is_empty()).then_some(cut)
}

#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct RecordingNotes {
    pub markers: Vec<MarkerDto>,
    pub pauses: Vec<PauseDto>,
    paused_at_ms: Option<u64>,
}

impl RecordingNotes {
    /// Adds a bookmark at `at_ms`; `None` once the recording holds [`MAX_MARKERS`].
    pub fn mark(&mut self, at_ms: u64, tag: Option<&str>, note: Option<&str>) -> Option<MarkerDto> {
        if self.markers.len() >= MAX_MARKERS {
            return None;
        }
        let marker = MarkerDto { at_ms, tag: clean(tag, MAX_TAG_CHARS), note: clean(note, MAX_NOTE_CHARS) };
        self.markers.push(marker.clone());
        Some(marker)
    }

    /// Gives the last bookmark a tag or note (the pill's tag menu after a plain "Mark"); false when there is none.
    pub fn tag_last(&mut self, tag: Option<&str>, note: Option<&str>) -> bool {
        let Some(last) = self.markers.last_mut() else { return false };
        if let Some(tag) = tag {
            last.tag = clean(Some(tag), MAX_TAG_CHARS);
        }
        if let Some(note) = note {
            last.note = clean(Some(note), MAX_NOTE_CHARS);
        }
        true
    }

    /// Removes the last bookmark ("Undo" on the pill toast).
    pub fn undo_mark(&mut self) -> Option<MarkerDto> {
        self.markers.pop()
    }

    /// The recording paused at recorded time `at_ms`; pausing twice keeps the first point.
    pub fn paused(&mut self, at_ms: u64) {
        self.paused_at_ms.get_or_insert(at_ms);
    }

    /// The recording resumed after `paused_ms` of wall time: the gap is kept (zero-length pauses are dropped, and
    /// past [`MAX_PAUSES`] the last gap absorbs the rest so the total paused time stays right).
    pub fn resumed(&mut self, paused_ms: u64) {
        let Some(at_ms) = self.paused_at_ms.take() else { return };
        if paused_ms == 0 {
            return;
        }
        if self.pauses.len() >= MAX_PAUSES {
            if let Some(last) = self.pauses.last_mut() {
                last.paused_ms = last.paused_ms.saturating_add(paused_ms);
            }
            return;
        }
        self.pauses.push(PauseDto { at_ms, paused_ms });
    }

    /// Bookmarks in time order and the closed pauses, for the upload. A pause still open at the end leaves no
    /// gap in the file and is not sent.
    pub fn finish(mut self) -> (Vec<MarkerDto>, Vec<PauseDto>) {
        self.markers.sort_by_key(|m| m.at_ms);
        (self.markers, self.pauses)
    }
}
