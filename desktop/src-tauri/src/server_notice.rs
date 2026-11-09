//! What the server says to every app (v1.6, P2.7, docs/API.md "Updates, announcements and maintenance"): an update
//! this version needs, an announcement, maintenance. While the server is in maintenance (`503 maintenance`) or refuses
//! this version (`426 update_required`), the upload queue holds: recordings and uploads wait on this computer, nothing
//! is marked failed, and the next try comes after `Retry-After` (maintenance) or a while later (an update, which also
//! restarts the app). `GET /api/config` is never refused, so each try starts by asking it.

use std::time::{Duration, Instant};

use serde::Serialize;

use crate::error::{AppError, ServerHold, MAINTENANCE_TEXT, UPDATE_REQUIRED_TEXT};
use crate::net::models::{AnnouncementDto, AppUpdateDto, MaintenanceDto, ServerConfig};

/// Maintenance without `Retry-After`: the next look.
pub const MAINTENANCE_WAIT: Duration = Duration::from_secs(60);
/// A `Retry-After` longer than this is shortened: the server may be back sooner than it guessed.
pub const MAX_MAINTENANCE_WAIT: Duration = Duration::from_secs(15 * 60);
/// A refused version looks again this often (a version raised by mistake is put back on the server).
pub const UPDATE_WAIT: Duration = Duration::from_secs(10 * 60);

/// The upload queue holds until `until` for `reason`.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct UploadHold {
    pub reason: ServerHold,
    pub until: Instant,
}

impl UploadHold {
    /// A hold for `reason` from `now`; maintenance waits `Retry-After` seconds (at least 1, at most 15 minutes).
    pub fn after(reason: ServerHold, retry_after_secs: Option<u64>, now: Instant) -> Self {
        let wait = match reason {
            ServerHold::Maintenance => retry_after_secs.map_or(MAINTENANCE_WAIT, |s| Duration::from_secs(s.max(1))).min(MAX_MAINTENANCE_WAIT),
            ServerHold::UpdateRequired => UPDATE_WAIT,
        };
        Self { reason, until: now + wait }
    }

    pub fn active(&self, now: Instant) -> bool {
        now < self.until
    }
}

/// The hold an answer asks for, when it is one.
pub fn hold_from_error(e: &AppError, now: Instant) -> Option<UploadHold> {
    e.hold().map(|reason| UploadHold::after(reason, e.retry_after_secs(), now))
}

/// The hold the config asks for: maintenance, or this version refused.
pub fn hold_from_config(config: &ServerConfig, now: Instant) -> Option<UploadHold> {
    if config.maintenance.is_some() {
        Some(UploadHold::after(ServerHold::Maintenance, None, now))
    } else if config.app_update.as_ref().is_some_and(|u| u.refused) {
        Some(UploadHold::after(ServerHold::UpdateRequired, None, now))
    } else {
        None
    }
}

/// What the main window shows at its top: the update this version needs, the announcement, maintenance, and why
/// uploads are waiting. A hold the config does not know of yet (a `503` before the next config) speaks for itself.
#[derive(Clone, Debug, Default, PartialEq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct ServerNotices {
    pub app_update: Option<AppUpdateDto>,
    pub announcement: Option<AnnouncementDto>,
    pub maintenance: Option<MaintenanceDto>,
    /// Uploads wait for this reason (none: they go as usual).
    pub waiting: Option<ServerHold>,
}

pub fn notices(config: Option<&ServerConfig>, hold: Option<&UploadHold>, now: Instant) -> ServerNotices {
    let held = hold.filter(|h| h.active(now)).map(|h| h.reason);
    let mut out = ServerNotices {
        app_update: config.and_then(|c| c.app_update.clone()),
        announcement: config.and_then(|c| c.announcement.clone()).filter(|a| !a.text.trim().is_empty()),
        maintenance: config.and_then(|c| c.maintenance.clone()),
        waiting: held,
    };
    match held {
        Some(ServerHold::Maintenance) if out.maintenance.is_none() => out.maintenance = Some(MaintenanceDto { message: MAINTENANCE_TEXT.into(), until: None }),
        Some(ServerHold::UpdateRequired) => {
            let update = out.app_update.get_or_insert_with(|| AppUpdateDto { required: true, message: UPDATE_REQUIRED_TEXT.into(), ..AppUpdateDto::default() });
            update.refused = true;
        }
        _ => {}
    }
    if out.waiting.is_none() {
        out.waiting = config.and_then(|c| hold_from_config(c, now)).map(|h| h.reason);
    }
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    fn server(status: u16, code: Option<&str>, retry: Option<u64>) -> AppError {
        AppError::Server { status, message: "x".into(), code: code.map(Into::into), retry_after_secs: retry }
    }

    #[test]
    fn maintenance_and_a_refused_version_hold_and_are_never_permanent() {
        let maintenance = server(503, Some("maintenance"), Some(120));
        let refused = server(426, Some("update_required"), None);
        assert_eq!(maintenance.hold(), Some(ServerHold::Maintenance));
        assert_eq!(refused.hold(), Some(ServerHold::UpdateRequired));
        assert_eq!(server(426, None, None).hold(), Some(ServerHold::UpdateRequired), "any 426 is Upgrade Required");
        assert!(!maintenance.is_permanent() && !refused.is_permanent());
        assert_eq!(server(503, Some("provider_not_configured"), None).hold(), None, "another 503 is not maintenance");
        assert_eq!(server(422, Some("validation_failed"), None).hold(), None);
        assert!(server(422, Some("validation_failed"), None).is_permanent());
    }

    #[test]
    fn both_are_said_in_plain_words() {
        assert_eq!(server(503, Some("maintenance"), Some(5)).to_string(), MAINTENANCE_TEXT);
        assert_eq!(server(426, Some("update_required"), None).to_string(), UPDATE_REQUIRED_TEXT);
    }

    #[test]
    fn maintenance_waits_retry_after_within_limits_and_an_update_ten_minutes() {
        let now = Instant::now();
        assert_eq!(UploadHold::after(ServerHold::Maintenance, Some(120), now).until, now + Duration::from_secs(120));
        assert_eq!(UploadHold::after(ServerHold::Maintenance, None, now).until, now + MAINTENANCE_WAIT);
        assert_eq!(UploadHold::after(ServerHold::Maintenance, Some(86_400), now).until, now + MAX_MAINTENANCE_WAIT);
        assert_eq!(UploadHold::after(ServerHold::Maintenance, Some(0), now).until, now + Duration::from_secs(1));
        let update = UploadHold::after(ServerHold::UpdateRequired, Some(5), now);
        assert_eq!(update.until, now + UPDATE_WAIT);
        assert!(update.active(now) && !update.active(now + UPDATE_WAIT));
        let hold = hold_from_error(&server(503, Some("maintenance"), Some(30)), now).unwrap();
        assert_eq!((hold.reason, hold.until), (ServerHold::Maintenance, now + Duration::from_secs(30)));
        assert!(hold_from_error(&server(500, None, None), now).is_none());
    }

    fn config(extra: serde_json::Value) -> ServerConfig {
        let mut base = serde_json::json!({
            "device": {"id": "d", "name": "PC", "platform": "windows", "source_device": "desktop"},
            "server_time": "2026-11-09T10:00:00+00:00", "retention_days": 30,
            "upload": {"chunk_size_bytes": 5_242_880, "max_recording_bytes": 2_147_483_648u64, "accepted_mimes": ["audio/flac"]},
        });
        base.as_object_mut().unwrap().extend(extra.as_object().unwrap().clone());
        serde_json::from_value(base).unwrap()
    }

    #[test]
    fn the_config_says_what_to_show_and_whether_uploads_wait() {
        let now = Instant::now();
        let quiet = config(serde_json::json!({"app_update": null, "announcement": null, "maintenance": null}));
        assert_eq!(notices(Some(&quiet), None, now), ServerNotices::default());
        assert!(hold_from_config(&quiet, now).is_none());

        let busy = config(serde_json::json!({
            "app_update": {"minimum": "1.6.0", "required": true, "refused": false, "message": "Please update EchoLedger to 1.6.0 or later.", "url": "https://x.test/downloads"},
            "announcement": {"text": "New: Send to WhatsApp", "level": "info", "until": "2026-11-20T00:00:00+00:00"},
            "maintenance": {"message": "Back at 11:00", "until": "2026-11-09T11:00:00+00:00"},
        }));
        let shown = notices(Some(&busy), None, now);
        assert_eq!(shown.app_update.as_ref().unwrap().minimum, "1.6.0");
        assert!(!shown.app_update.as_ref().unwrap().refused);
        assert_eq!(shown.announcement.as_ref().unwrap().text, "New: Send to WhatsApp");
        assert_eq!(shown.maintenance.as_ref().unwrap().message, "Back at 11:00");
        assert_eq!(shown.waiting, Some(ServerHold::Maintenance));

        let refused = config(serde_json::json!({"app_update": {"minimum": "1.6.0", "required": true, "refused": true, "message": "Update", "url": null}}));
        assert_eq!(hold_from_config(&refused, now).unwrap().reason, ServerHold::UpdateRequired);
        assert_eq!(notices(Some(&refused), None, now).waiting, Some(ServerHold::UpdateRequired));

        // A malformed block never breaks the config, and an empty announcement shows nothing.
        let odd = config(serde_json::json!({"app_update": "soon", "announcement": {"text": "  "}, "maintenance": 3}));
        assert_eq!(notices(Some(&odd), None, now), ServerNotices::default());
    }

    #[test]
    fn a_hold_the_config_does_not_know_yet_speaks_for_itself() {
        let now = Instant::now();
        let maintenance = UploadHold::after(ServerHold::Maintenance, Some(60), now);
        let shown = notices(None, Some(&maintenance), now);
        assert_eq!(shown.waiting, Some(ServerHold::Maintenance));
        assert_eq!(shown.maintenance.unwrap().message, MAINTENANCE_TEXT);
        let refused = UploadHold::after(ServerHold::UpdateRequired, None, now);
        let shown = notices(None, Some(&refused), now);
        assert!(shown.app_update.as_ref().unwrap().refused);
        assert_eq!(shown.app_update.unwrap().message, UPDATE_REQUIRED_TEXT);
        // An old hold is over.
        assert_eq!(notices(None, Some(&maintenance), now + Duration::from_secs(61)).waiting, None);
    }
}
