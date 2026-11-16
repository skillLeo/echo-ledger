//! Recordings recovered after a restart (since v1.3) wait as "Recovered recording"; once kept, their transcript's title
//! replaces that name. So the Library and Activity can still mark them "Recovered", this computer remembers the server
//! sessions they became (`recovered.json`, the newest [`KEEP`]). The server is not told: the mark is this computer's.

use std::path::{Path, PathBuf};
use std::sync::Mutex;

/// How many recovered sessions are remembered (the oldest go first).
pub const KEEP: usize = 500;

/// One writer at a time (the upload loop; tests).
static WRITING: Mutex<()> = Mutex::new(());

pub fn path(data_dir: &Path) -> PathBuf {
    data_dir.join("recovered.json")
}

/// The remembered sessions, oldest first; none when the file is missing or unreadable.
pub fn sessions(data_dir: &Path) -> Vec<String> {
    std::fs::read(path(data_dir)).ok().and_then(|b| serde_json::from_slice::<Vec<String>>(&b).ok()).unwrap_or_default()
}

/// The list with `session` added once, at most [`KEEP`] long (pure).
pub fn with(mut list: Vec<String>, session: &str) -> Vec<String> {
    if session.is_empty() || list.iter().any(|s| s == session) {
        return list;
    }
    list.push(session.to_string());
    let extra = list.len().saturating_sub(KEEP);
    list.drain(..extra);
    list
}

/// Remembers `session` as recovered; whether the list changed (written whole or not at all).
pub fn remember(data_dir: &Path, session: &str) -> bool {
    let _writing = WRITING.lock().unwrap_or_else(|p| p.into_inner());
    let before = sessions(data_dir);
    let after = with(before.clone(), session);
    if after == before {
        return false;
    }
    let Ok(bytes) = serde_json::to_vec(&after) else { return false };
    crate::privacy::diag::write_atomic(&path(data_dir), &bytes).is_ok()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_recovered_session_is_remembered_once_and_the_oldest_go_first() {
        assert_eq!(with(vec![], "s1"), ["s1"]);
        assert_eq!(with(vec!["s1".into()], "s1"), ["s1"], "once");
        assert_eq!(with(vec!["s1".into()], ""), ["s1"], "never an empty id");
        let full: Vec<String> = (0..KEEP).map(|i| format!("s{i}")).collect();
        let next = with(full, "new");
        assert_eq!(next.len(), KEEP);
        assert_eq!(next.first().map(String::as_str), Some("s1"));
        assert_eq!(next.last().map(String::as_str), Some("new"));
    }

    #[test]
    fn the_mark_is_kept_on_this_computer() {
        let dir = tempfile::tempdir().unwrap();
        assert!(sessions(dir.path()).is_empty());
        assert!(remember(dir.path(), "abc"));
        assert!(!remember(dir.path(), "abc"), "already there");
        assert_eq!(sessions(dir.path()), ["abc"]);
        std::fs::write(path(dir.path()), b"not json").unwrap();
        assert!(sessions(dir.path()).is_empty(), "a broken file reads as none");
    }

    #[test]
    fn a_kept_recovered_recording_carries_its_mark_to_the_upload_and_the_upload_remembers_it() {
        let dir = std::path::PathBuf::from("/tmp/el-recovered");
        let now = chrono::Utc::now();
        let decision = crate::pending::PendingDecision::recovered(dir.clone(), dir.join("recording.flac"), dir.join("mic.wav"), dir.join("system.wav"), 3_000, now, now, now);
        assert!(decision.is_recovered() && decision.to_upload().recovered);
        let pending = include_str!("pending.rs");
        assert!(pending.contains("recovered: self.is_recovered(),"), "Keep passes the mark on");
        assert!(pending.contains("self.recovered || self.title.as_deref() == Some(RECOVERED_TITLE)"), "entries saved before the mark");
        let actions = include_str!("actions.rs");
        assert!(actions.contains("if item.recovered\n            && let Ok(UploadOutcome::Ready { session_id } | UploadOutcome::Verifying { session_id }) = &result\n            && crate::recovered::remember(&state.data_dir, session_id)"));
    }
}
