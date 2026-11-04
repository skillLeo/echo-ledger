//! Calls skipped quietly (v1.5): an automatic call recording shorter than "Skip calls shorter than" (10 s by default),
//! or one in which nobody spoke on either side, is neither kept nor asked about, and nothing is shown. It stays on this
//! computer for 24 hours in `skipped.json` (atomic writes, tolerant loading) so Activity's "Skipped 3 short calls" can
//! restore it; a restored call joins the upload queue like any kept recording. After 24 hours its files are deleted.
//! Recordings started by hand and voice notes are never skipped. The rules are the phone's (`SkippedCalls`); the
//! desktop has no ringing signal, so there is no "Not answered" here.

use std::path::{Path, PathBuf};

use chrono::{DateTime, Utc};
use serde::{Deserialize, Serialize};
use serde_json::Value;

use crate::audio::RecordingMode;
use crate::error::{AppError, AppResult};
use crate::net::sync::paths;
use crate::net::uploader::{PendingUpload, UploadQueue};
use crate::pending::PendingDecision;

/// How long a skipped call can still be restored.
pub const KEEP_HOURS: i64 = 24;
/// The "Skip calls shorter than" choices in seconds (0 = never skip a short call).
pub const SHORTER_THAN_CHOICES: [u32; 5] = [0, 5, 10, 20, 30];
pub const DEFAULT_SHORTER_THAN_S: u32 = 10;
/// Less speech than this on each side counts as nobody speaking.
pub const MIN_SPEECH_MS: u64 = 1_000;
/// `skipped_restore` answers this when the call is not (or no longer) in the list.
pub const NOT_SKIPPED: &str = "This call is no longer in the skipped list.";

#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum SkipReason {
    TooShort,
    NoSpeech,
}

/// Why a finished recording is skipped, or `None` to handle it as usual. `speech_ms` is the speech heard on each side
/// (microphone, this computer's sound).
pub fn reason(auto: bool, mode: RecordingMode, duration_ms: u64, shorter_than_s: u32, speech_ms: [u64; 2]) -> Option<SkipReason> {
    if !auto || mode != RecordingMode::Call {
        return None;
    }
    if shorter_than_s > 0 && duration_ms < u64::from(shorter_than_s) * 1_000 {
        return Some(SkipReason::TooShort);
    }
    speech_ms.iter().all(|ms| *ms < MIN_SPEECH_MS).then_some(SkipReason::NoSpeech)
}

/// One entry of `skipped.json`: the recording as it would have waited for a decision, and why it was skipped.
#[derive(Clone, Debug, Serialize, Deserialize)]
pub struct SkippedCall {
    pub decision: PendingDecision,
    pub reason: SkipReason,
    pub skipped_at: DateTime<Utc>,
}

impl SkippedCall {
    /// When it goes for good.
    pub fn expires_at(&self) -> DateTime<Utc> {
        self.skipped_at + chrono::Duration::hours(KEEP_HOURS)
    }

    pub fn item(&self) -> SkippedItem {
        let d = &self.decision;
        SkippedItem {
            id: d.id.clone(),
            source_app: d.source_app.clone(),
            title: d.title.clone(),
            caller_name: d.caller_name().map(str::to_string),
            started_at: d.started_at.clone(),
            duration_ms: d.duration_ms,
            reason: self.reason,
            expires_at: self.expires_at().to_rfc3339(),
        }
    }
}

/// A skipped call as Activity shows it (`skipped_list`, `skipped:changed`). Times are RFC 3339 UTC.
#[derive(Clone, Debug, PartialEq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct SkippedItem {
    pub id: String,
    pub source_app: String,
    pub title: Option<String>,
    /// Who the call was with (a meeting window's title).
    pub caller_name: Option<String>,
    pub started_at: String,
    pub duration_ms: u64,
    pub reason: SkipReason,
    /// When it is deleted for good (24 hours after it was skipped).
    pub expires_at: String,
}

/// `skipped.json`.
#[derive(Clone, Debug, Default, Serialize)]
pub struct SkippedStore {
    pub items: Vec<SkippedCall>,
}

impl SkippedStore {
    /// Tolerant like the other queues: a missing or broken file is empty, and one unreadable entry costs only that
    /// entry.
    pub fn load(path: &Path) -> Self {
        let Some(Value::Object(mut file)) = std::fs::read(path).ok().and_then(|b| serde_json::from_slice::<Value>(&b).ok()) else {
            return Self::default();
        };
        let Some(Value::Array(items)) = file.remove("items") else { return Self::default() };
        Self { items: items.into_iter().filter_map(|v| serde_json::from_value(v).ok()).collect() }
    }

    /// Atomic replace: a temporary file renamed over the old one.
    pub fn save(&self, path: &Path) -> AppResult<()> {
        let tmp = path.with_extension("json.tmp");
        std::fs::write(&tmp, serde_json::to_vec_pretty(self)?)?;
        std::fs::rename(tmp, path)?;
        Ok(())
    }

    /// The calls that can still be restored at `now`, newest first.
    pub fn ui_items(&self, now: DateTime<Utc>) -> Vec<SkippedItem> {
        let mut calls: Vec<&SkippedCall> = self.items.iter().filter(|c| c.expires_at() > now).collect();
        calls.sort_by_key(|c| std::cmp::Reverse(c.decision.created_at));
        calls.into_iter().map(SkippedCall::item).collect()
    }
}

/// The files a skipped call touches, in the app data dir. The caller holds the queue lock.
#[derive(Clone, Debug)]
pub struct SkippedFiles {
    pub skipped: PathBuf,
    pub uploads: PathBuf,
}

impl SkippedFiles {
    pub fn in_dir(data_dir: &Path) -> Self {
        Self { skipped: paths::skipped(data_dir), uploads: paths::uploads(data_dir) }
    }

    pub fn load(&self) -> SkippedStore {
        SkippedStore::load(&self.skipped)
    }

    pub fn add(&self, call: SkippedCall) -> AppResult<()> {
        let mut store = self.load();
        store.items.retain(|c| c.decision.id != call.decision.id);
        store.items.push(call);
        store.save(&self.skipped)
    }

    /// Restore: the recording joins the upload queue (once), then leaves `skipped.json`, so a crash in between can
    /// never lose it. A call whose 24 hours are over cannot be restored (its files may be gone).
    pub fn restore(&self, id: &str, now: DateTime<Utc>) -> AppResult<PendingUpload> {
        let mut store = self.load();
        let at = store.items.iter().position(|c| c.decision.id == id && c.expires_at() > now).ok_or_else(|| AppError::msg(NOT_SKIPPED))?;
        let upload = store.items[at].decision.to_upload();
        let mut queue = UploadQueue::load(&self.uploads);
        if !queue.items.iter().any(|i| i.id() == upload.id()) {
            queue.items.push(upload.clone());
            queue.save(&self.uploads)?;
        }
        store.items.remove(at);
        store.save(&self.skipped)?;
        Ok(upload)
    }

    /// Deletes every skipped call whose 24 hours are over at `now` (its files, then its entry); returns their ids. A
    /// folder that cannot be deleted yet (a file still open) keeps its entry for the next try, so the start-up recovery
    /// never mistakes it for a recording cut short.
    pub fn purge(&self, now: DateTime<Utc>) -> AppResult<Vec<String>> {
        let mut store = self.load();
        let mut gone = Vec::new();
        store.items.retain(|c| {
            if c.expires_at() > now {
                return true;
            }
            let dir = c.decision.dir();
            let deleted = dir.as_os_str().is_empty() || !dir.exists() || std::fs::remove_dir_all(dir).is_ok();
            if deleted {
                gone.push(c.decision.id.clone());
            }
            !deleted
        });
        if !gone.is_empty() {
            store.save(&self.skipped)?;
        }
        Ok(gone)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn only_automatic_calls_are_ever_skipped() {
        for mode in [RecordingMode::VoiceNote, RecordingMode::MicNote] {
            assert_eq!(reason(true, mode, 2_000, 10, [0, 0]), None, "{mode:?}");
        }
        assert_eq!(reason(false, RecordingMode::Call, 2_000, 10, [0, 0]), None, "a recording started by hand is kept");
    }

    #[test]
    fn a_short_call_is_skipped_by_the_setting_and_never_when_it_is_off() {
        let speech = [20_000, 20_000];
        assert_eq!(reason(true, RecordingMode::Call, 9_999, 10, speech), Some(SkipReason::TooShort));
        assert_eq!(reason(true, RecordingMode::Call, 10_000, 10, speech), None, "10 s exactly is long enough");
        assert_eq!(reason(true, RecordingMode::Call, 29_000, 30, speech), Some(SkipReason::TooShort));
        assert_eq!(reason(true, RecordingMode::Call, 1_000, 0, speech), None, "Never");
    }

    #[test]
    fn a_call_is_silent_only_when_neither_side_spoke() {
        assert_eq!(reason(true, RecordingMode::Call, 60_000, 10, [0, 0]), Some(SkipReason::NoSpeech));
        assert_eq!(reason(true, RecordingMode::Call, 60_000, 10, [999, 400]), Some(SkipReason::NoSpeech), "a cough is not a call");
        assert_eq!(reason(true, RecordingMode::Call, 60_000, 10, [1_000, 0]), None, "I spoke");
        assert_eq!(reason(true, RecordingMode::Call, 60_000, 10, [0, 5_000]), None, "the other side spoke");
        assert_eq!(reason(true, RecordingMode::Call, 60_000, 0, [0, 0]), Some(SkipReason::NoSpeech), "silence is skipped even with short calls kept");
    }

    #[test]
    fn reasons_have_wire_names() {
        assert_eq!(serde_json::to_value(SkipReason::TooShort).unwrap(), "too_short");
        assert_eq!(serde_json::to_value(SkipReason::NoSpeech).unwrap(), "no_speech");
    }

    #[test]
    fn a_broken_file_is_an_empty_list() {
        let dir = tempfile::tempdir().unwrap();
        let path = dir.path().join("skipped.json");
        assert!(SkippedStore::load(&path).items.is_empty(), "missing");
        std::fs::write(&path, b"{not json").unwrap();
        assert!(SkippedStore::load(&path).items.is_empty(), "broken");
        std::fs::write(&path, br#"{"items": [{"decision": 3}]}"#).unwrap();
        assert!(SkippedStore::load(&path).items.is_empty(), "an unreadable entry costs only itself");
    }
}
