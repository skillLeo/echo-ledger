//! After a call (v1.5): what the small window near the tray shows once a recording ends. "Keep automatically" keeps
//! the recording at once and shows "Saved · … · Undo" for 6 s; "Ask me" shows a pill-size chip with the title, ✓ and
//! ✕ that keeps the recording by itself after 10 s (`pending::CHIP_SECONDS`). Both live in the `review` window
//! (overlay/mod.rs) beside the unknown-app chip. `Surfaces` is plain data (unit-tested below); `actions` adds the
//! files, timers and events.

use std::collections::{HashMap, HashSet};
use std::time::{Duration, Instant};

use serde::Serialize;

use crate::audio::RecordingMode;
use crate::pending::{PendingDecision, PendingItem};

/// How long "Saved · … · Undo" stays on screen.
pub const SAVED_SECONDS: u64 = 6;
/// How long a recording that finished uploading can still be undone from its toast (the toast and some slack).
pub const UNDO_UPLOADED_FOR: Duration = Duration::from_secs(60);

/// "Saved · Call with Omar · 4:12 · Undo" (`AfterCallState.saved`).
#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct SavedToast {
    /// The recording: its folder name, which is also its id in the upload queue.
    pub id: String,
    pub title: Option<String>,
    /// Who the call was with (a meeting window's title).
    pub caller_name: Option<String>,
    pub source_app: String,
    pub mode: RecordingMode,
    pub duration_ms: u64,
    /// RFC 3339: when the toast goes.
    pub until: String,
}

impl SavedToast {
    /// The toast of a recording kept at `now`.
    pub fn of(decision: &PendingDecision, now: chrono::DateTime<chrono::Utc>) -> Self {
        Self {
            id: decision.id.clone(),
            title: decision.title.clone(),
            caller_name: decision.caller_name().map(str::to_string),
            source_app: decision.source_app.clone(),
            mode: decision.mode,
            duration_ms: decision.duration_ms,
            until: (now + chrono::Duration::seconds(SAVED_SECONDS as i64)).to_rfc3339(),
        }
    }
}

/// What the after-call window shows (`aftercall:state`, `after_call_state`): the toast, the chip, both or neither.
#[derive(Clone, Debug, Default, PartialEq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct AfterCallState {
    pub saved: Option<SavedToast>,
    /// The newest recording whose chip counts down ("Ask me"); its `deadlineAt` is when it keeps itself.
    pub ask: Option<PendingItem>,
}

impl AfterCallState {
    pub fn is_empty(&self) -> bool {
        self.saved.is_none() && self.ask.is_none()
    }
}

/// The core's side of the after-call window (managed state).
#[derive(Debug, Default)]
pub struct Surfaces {
    saved: Option<(SavedToast, Instant)>,
    chip: Option<String>,
    /// Recently kept recordings that finished uploading: their server session, for Undo.
    uploaded: HashMap<String, (String, Instant)>,
    /// Recordings undone while an upload may still be running for them.
    undone: HashSet<String>,
}

impl Surfaces {
    /// Shows the toast until `now` + 6 s (a newer one replaces an older one).
    pub fn show_saved(&mut self, toast: SavedToast, now: Instant) {
        self.saved = Some((toast, now + Duration::from_secs(SAVED_SECONDS)));
    }

    /// The toast while it is on screen.
    pub fn saved(&self, now: Instant) -> Option<&SavedToast> {
        self.saved.as_ref().filter(|(_, until)| *until > now).map(|(toast, _)| toast)
    }

    /// Takes the toast of `id` away; false when another toast (or none) is shown.
    pub fn clear_saved(&mut self, id: &str) -> bool {
        if self.saved.as_ref().is_some_and(|(t, _)| t.id == id) {
            self.saved = None;
            return true;
        }
        false
    }

    /// The chip now counts down for `id` (the newest call wins; an older one still keeps itself on time).
    pub fn show_chip(&mut self, id: String) {
        self.chip = Some(id);
    }

    pub fn chip(&self) -> Option<&str> {
        self.chip.as_deref()
    }

    /// The chip of `id` goes (kept, discarded or opened); false when the chip shows another recording.
    pub fn clear_chip(&mut self, id: &str) -> bool {
        if self.chip.as_deref() == Some(id) {
            self.chip = None;
            return true;
        }
        false
    }

    /// `id` finished uploading as server session `session`; entries older than [`UNDO_UPLOADED_FOR`] are dropped.
    pub fn uploaded(&mut self, id: &str, session: &str, now: Instant) {
        self.uploaded.retain(|_, (_, at)| now.saturating_duration_since(*at) < UNDO_UPLOADED_FOR);
        self.uploaded.insert(id.to_string(), (session.to_string(), now));
    }

    /// The server session of `id` when it finished uploading a moment ago.
    pub fn uploaded_session(&self, id: &str, now: Instant) -> Option<String> {
        self.uploaded.get(id).filter(|(_, at)| now.saturating_duration_since(*at) < UNDO_UPLOADED_FOR).map(|(s, _)| s.clone())
    }

    /// Undo was pressed for `id`: its toast goes, and an upload still running for it is deleted when it ends.
    pub fn undo(&mut self, id: &str) {
        self.clear_saved(id);
        self.uploaded.remove(id);
        self.undone.insert(id.to_string());
    }

    /// Called by the upload worker once an attempt for `id` ended: true (once) when the person undid it meanwhile.
    pub fn take_undone(&mut self, id: &str) -> bool {
        self.undone.remove(id)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn toast(id: &str) -> SavedToast {
        SavedToast {
            id: id.into(),
            title: None,
            caller_name: Some("Omar".into()),
            source_app: "whatsapp".into(),
            mode: RecordingMode::Call,
            duration_ms: 252_000,
            until: "2026-11-04T10:00:06+00:00".into(),
        }
    }

    #[test]
    fn the_saved_toast_stays_six_seconds_and_a_newer_one_replaces_it() {
        let mut s = Surfaces::default();
        let t0 = Instant::now();
        s.show_saved(toast("a"), t0);
        assert_eq!(s.saved(t0 + Duration::from_secs(5)).map(|t| t.id.as_str()), Some("a"));
        assert_eq!(s.saved(t0 + Duration::from_secs(6)), None, "gone after 6 s");
        s.show_saved(toast("b"), t0);
        assert!(!s.clear_saved("a"), "another recording's toast is left alone");
        assert!(s.clear_saved("b"));
        assert_eq!(s.saved(t0), None);
    }

    #[test]
    fn the_chip_follows_the_newest_call() {
        let mut s = Surfaces::default();
        s.show_chip("a".into());
        s.show_chip("b".into());
        assert_eq!(s.chip(), Some("b"));
        assert!(!s.clear_chip("a"), "an older call keeps itself without touching the chip");
        assert!(s.clear_chip("b"));
        assert_eq!(s.chip(), None);
    }

    #[test]
    fn undo_finds_a_recording_that_finished_uploading_a_moment_ago() {
        let mut s = Surfaces::default();
        let t0 = Instant::now();
        s.uploaded("a", "01SESSION", t0);
        assert_eq!(s.uploaded_session("a", t0 + Duration::from_secs(5)).as_deref(), Some("01SESSION"));
        assert_eq!(s.uploaded_session("a", t0 + UNDO_UPLOADED_FOR), None, "too late for its toast");
        s.uploaded("b", "01OTHER", t0 + UNDO_UPLOADED_FOR);
        assert_eq!(s.uploaded.len(), 1, "old entries are dropped");
    }

    #[test]
    fn an_upload_that_ends_after_undo_is_reported_once() {
        let mut s = Surfaces::default();
        let t0 = Instant::now();
        s.show_saved(toast("a"), t0);
        s.undo("a");
        assert_eq!(s.saved(t0), None, "the toast goes with Undo");
        assert!(s.take_undone("a"));
        assert!(!s.take_undone("a"), "only once");
        assert!(!s.take_undone("b"));
    }
}
