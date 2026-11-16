//! The privacy diagnostics on this computer (`privacy-log.json`, the last 200 lines): read-back mismatches, Share Check
//! results, sharing bars nobody knows yet. Lines name windows, apps and window titles of sharing bars only, never what
//! any window shows. "Copy diagnostics" on the privacy page copies them; nothing here is uploaded.

use std::path::{Path, PathBuf};

use chrono::{DateTime, Utc};
use serde::{Deserialize, Serialize};

pub const KEEP: usize = 200;

#[derive(Clone, Debug, PartialEq, Eq, Serialize, Deserialize)]
pub struct Entry {
    pub at: DateTime<Utc>,
    pub line: String,
}

pub fn path(data_dir: &Path) -> PathBuf {
    data_dir.join("privacy-log.json")
}

pub fn read(data_dir: &Path) -> Vec<Entry> {
    std::fs::read(path(data_dir)).ok().and_then(|b| serde_json::from_slice(&b).ok()).unwrap_or_default()
}

/// Adds a line (the oldest go past [`KEEP`]).
pub fn log(data_dir: &Path, line: &str) {
    log_at(data_dir, line, Utc::now());
}

pub fn log_at(data_dir: &Path, line: &str, at: DateTime<Utc>) {
    let mut entries = read(data_dir);
    entries.push(Entry { at, line: line.chars().take(300).collect() });
    if entries.len() > KEEP {
        let extra = entries.len() - KEEP;
        entries.drain(..extra);
    }
    if let Ok(bytes) = serde_json::to_vec(&entries) {
        let _ = std::fs::write(path(data_dir), bytes);
    }
}

/// Whether this exact line is already in the log (unknown sharing bars are logged once).
pub fn has(data_dir: &Path, line: &str) -> bool {
    read(data_dir).iter().any(|e| e.line == line)
}

/// The text "Copy diagnostics" puts on the clipboard: a header, then one line per entry.
pub fn text(header: &[String], entries: &[Entry]) -> String {
    let mut out: Vec<String> = header.to_vec();
    if entries.is_empty() {
        out.push("No privacy events yet.".into());
    }
    out.extend(entries.iter().map(|e| format!("{} {}", e.at.format("%Y-%m-%d %H:%M:%SZ"), e.line)));
    out.join("\n")
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_log_keeps_its_last_lines_and_copies_as_text() {
        let dir = tempfile::tempdir().unwrap();
        for i in 0..(KEEP + 5) {
            log(dir.path(), &format!("line {i}"));
        }
        let entries = read(dir.path());
        assert_eq!(entries.len(), KEEP);
        assert_eq!(entries[0].line, "line 5");
        assert!(has(dir.path(), "line 204") && !has(dir.path(), "line 0"));
        let at = DateTime::parse_from_rfc3339("2026-11-16T09:00:00Z").unwrap().with_timezone(&Utc);
        let copied = text(&["EchoLedger 1.8.1".into()], &[Entry { at, line: "Share Check: pass (sck)".into() }]);
        assert_eq!(copied, "EchoLedger 1.8.1\n2026-11-16 09:00:00Z Share Check: pass (sck)");
        assert_eq!(text(&[], &[]), "No privacy events yet.");
    }
}
