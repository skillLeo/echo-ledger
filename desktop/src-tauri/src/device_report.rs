//! The device report this computer last sent ("Send to my admin", `POST /api/devices/report`), remembered across
//! restarts. A message to support attaches the device's last report when the person asks (docs/API.md 2.0.0-d), so
//! the support form says exactly what that will be: this report and when it was sent, or none from this computer yet.
//! A report is only ever made by the person running the Mac check and sending it: nothing here listens or captures.
//!
//! It belongs to the account that sent it: the server keeps a report per device of an account, so another account
//! signed in here has none from this computer.

use std::path::{Path, PathBuf};

use serde::{Deserialize, Serialize};

use crate::error::AppResult;
use crate::owner::{self, Owner};

/// What the support form says goes with a message.
#[derive(Clone, Debug, Default, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct LastDeviceReport {
    /// When the server took the last report this computer sent for the account signed in (ISO 8601, the server's);
    /// none when it has sent none.
    pub sent_at: Option<String>,
}

#[derive(Clone, Debug, PartialEq, Eq, Serialize, Deserialize)]
struct Stored {
    sent_at: String,
    #[serde(default)]
    owner: Option<Owner>,
}

pub fn path(data_dir: &Path) -> PathBuf {
    data_dir.join("device_report.json")
}

/// A report was sent now (the server's `reported_at`) for `owner`.
pub fn remember(data_dir: &Path, sent_at: &str, owner: Option<Owner>) -> AppResult<()> {
    let stored = Stored { sent_at: sent_at.to_string(), owner };
    crate::privacy::diag::write_atomic(&path(data_dir), &serde_json::to_vec_pretty(&stored)?)?;
    Ok(())
}

/// The last report sent from here for `current`; none for another account, or when the file is missing or unreadable.
pub fn last(data_dir: &Path, current: Option<&Owner>) -> LastDeviceReport {
    let stored = std::fs::read(path(data_dir)).ok().and_then(|bytes| serde_json::from_slice::<Stored>(&bytes).ok());
    LastDeviceReport { sent_at: stored.filter(|s| owner::may_upload(s.owner.as_ref(), current)).map(|s| s.sent_at) }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_last_report_is_remembered_for_the_account_that_sent_it() {
        let dir = tempfile::tempdir().unwrap();
        let me = Owner::new("https://echoledger.example", Some("Me@Example.com"));
        assert_eq!(last(dir.path(), Some(&me)), LastDeviceReport { sent_at: None }, "none sent yet");

        remember(dir.path(), "2026-11-17T09:00:00+00:00", Some(me.clone())).unwrap();
        // Read again as after a restart.
        assert_eq!(last(dir.path(), Some(&me)).sent_at.as_deref(), Some("2026-11-17T09:00:00+00:00"));
        assert_eq!(serde_json::to_value(last(dir.path(), Some(&me))).unwrap(), serde_json::json!({"sentAt": "2026-11-17T09:00:00+00:00"}));

        let other = Owner::new("https://echoledger.example", Some("someone@example.com"));
        assert_eq!(last(dir.path(), Some(&other)).sent_at, None, "another account has none from this computer");
        assert_eq!(last(dir.path(), None).sent_at, None, "nobody signed in");

        remember(dir.path(), "2026-11-18T10:00:00+00:00", Some(me.clone())).unwrap();
        assert_eq!(last(dir.path(), Some(&me)).sent_at.as_deref(), Some("2026-11-18T10:00:00+00:00"), "the newest one");

        std::fs::write(path(dir.path()), b"not json").unwrap();
        assert_eq!(last(dir.path(), Some(&me)).sent_at, None, "an unreadable file says none, never something made up");
    }
}
