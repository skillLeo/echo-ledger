//! Sending stored screens (`POST /api/sessions/{id}/screens`) once their recording reached the server. Each screen is
//! deleted here as soon as the server holds it (`201`, or `200` for an image it already has). The answers decide the
//! rest: `409 too_many_screens` and `404 session_not_found` end the recording's screens, `404 not_found` (an older
//! server) and `403 feature_not_in_plan` end all of them and stop the capture, a refusal of one screen drops that
//! screen, and a busy or unreachable server is tried again later, waiting longer each time. Before the first screen
//! goes, the session's project is read: one with screens switched off keeps none of them.

use chrono::{DateTime, Duration, Utc};

use super::store::{Manifest, ScreenKey, ScreenStore};
use crate::error::{AppError, AppResult};
use crate::net::api::ApiClient;
use crate::net::models::ScreenUpload;

/// What follows one answer of the server.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Next {
    /// The server holds the screen: delete it here.
    Sent,
    /// The server will never take this screen (too large, not an image): delete it.
    DropScreen,
    /// No screen of this recording can go any more (400 kept, the recording deleted, not allowed).
    DropRecording,
    /// The server has no screen moments: nothing can go.
    ServerMissing,
    /// The plan leaves screen moments out: nothing can go.
    NotInPlan,
    /// The device was unpaired.
    Unpaired,
    /// Busy or unreachable: try again later (after `Retry-After` seconds when the server said).
    Later(Option<u64>),
}

/// The step after an upload's answer (pure).
pub fn next_after<T>(result: &AppResult<T>) -> Next {
    let e = match result {
        Ok(_) => return Next::Sent,
        Err(AppError::NotPaired) => return Next::Unpaired,
        Err(e) => e,
    };
    match e.code() {
        Some("too_many_screens" | "session_not_found" | "not_allowed" | "storage_limit_reached") => return Next::DropRecording,
        Some("feature_not_in_plan") => return Next::NotInPlan,
        _ => {}
    }
    if e.is_missing_route() {
        Next::ServerMissing
    } else if e.status() == Some(429) {
        Next::Later(e.retry_after_secs())
    } else if e.is_permanent() {
        Next::DropScreen
    } else {
        Next::Later(None)
    }
}

/// How long a recording's screens wait after `failures` failed tries in a row: 30 s, doubling, at most 30 minutes.
pub fn backoff(failures: u32) -> Duration {
    Duration::seconds((30i64 << failures.min(6)).min(1_800))
}

/// What one round of sending did.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct Drain {
    pub sent: usize,
    pub dropped: usize,
    /// The round stopped early for everything (an older server, the plan, unpaired).
    pub stopped: Option<Next>,
}

/// Does the session's project keep screens? The Inbox does; a project says with its `screens` switch (missing = on).
pub async fn project_keeps_screens(api: &ApiClient, session_id: &str, clients: &mut Option<serde_json::Value>) -> AppResult<bool> {
    let session = api.session(session_id).await?;
    let Some(client_id) = session.get("client").and_then(|c| c.get("id")).and_then(serde_json::Value::as_str).map(str::to_string) else { return Ok(true) };
    if let Some(flag) = session["client"].get("screens").and_then(serde_json::Value::as_bool) {
        return Ok(flag);
    }
    if clients.is_none() {
        *clients = Some(api.clients().await?);
    }
    let listed = clients.as_ref().and_then(|c| c["data"].as_array()).and_then(|list| list.iter().find(|c| c["id"].as_str() == Some(client_id.as_str())).cloned());
    Ok(listed.and_then(|c| c.get("screens").and_then(serde_json::Value::as_bool)).unwrap_or(true))
}

/// Sends every screen that is due, oldest recording first. A failure that is not about one screen ends this round for
/// its recording (with a growing wait) or, when the server is away, for all of them.
pub async fn drain(api: &ApiClient, store: &ScreenStore, key: &ScreenKey, now: DateTime<Utc>) -> Drain {
    let mut drain = Drain::default();
    let mut clients: Option<serde_json::Value> = None;
    for manifest in store.due(now) {
        let Some(session) = manifest.session_id.clone() else { continue };
        if !manifest.project_checked {
            match project_keeps_screens(api, &session, &mut clients).await {
                Ok(true) => {
                    let _ = store.update(&manifest.recording, |m| m.project_checked = true);
                }
                Ok(false) => {
                    drain.dropped += manifest.items.len();
                    store.forget(&manifest.recording);
                    continue;
                }
                Err(e) => match next_after::<()>(&Err(e)) {
                    Next::DropRecording | Next::DropScreen | Next::ServerMissing => {
                        // The recording is gone (or unreadable): its screens have nowhere to go.
                        drain.dropped += manifest.items.len();
                        store.forget(&manifest.recording);
                        continue;
                    }
                    next @ (Next::Unpaired | Next::NotInPlan) => {
                        drain.stopped = Some(next);
                        return drain;
                    }
                    Next::Later(_) | Next::Sent => {
                        wait(store, &manifest, now);
                        return drain;
                    }
                },
            }
        }
        match send_recording(api, store, key, &manifest, &session, &mut drain).await {
            Some(next @ (Next::ServerMissing | Next::NotInPlan | Next::Unpaired)) => {
                drain.stopped = Some(next);
                return drain;
            }
            // Busy or away: the other recordings would meet the same server.
            Some(Next::Later(_)) => return drain,
            _ => {}
        }
    }
    drain
}

/// Sends one recording's screens in order; returns the answer that ended it early, if one did.
async fn send_recording(api: &ApiClient, store: &ScreenStore, key: &ScreenKey, manifest: &Manifest, session: &str, drain: &mut Drain) -> Option<Next> {
    for screen in &manifest.items {
        let image = match store.read(&manifest.recording, screen, key) {
            Ok(image) => image,
            Err(_) => {
                // Unreadable here (another key, a damaged file): it can never be sent.
                let _ = store.remove(&manifest.recording, &screen.file);
                drain.dropped += 1;
                continue;
            }
        };
        let upload = ScreenUpload { at_ms: screen.at_ms, width: screen.width, height: screen.height, trigger: screen.trigger.clone(), sha256: screen.sha256.clone() };
        let result = api.upload_screen(session, &upload, &image).await;
        match next_after(&result) {
            Next::Sent => {
                let _ = store.remove(&manifest.recording, &screen.file);
                drain.sent += 1;
            }
            Next::DropScreen => {
                let _ = store.remove(&manifest.recording, &screen.file);
                drain.dropped += 1;
            }
            Next::DropRecording => {
                drain.dropped += store.count(&manifest.recording);
                store.forget(&manifest.recording);
                return None;
            }
            Next::Later(after) => {
                wait_at_least(store, manifest, Utc::now(), after);
                return Some(Next::Later(after));
            }
            other => return Some(other),
        }
    }
    let _ = store.update(&manifest.recording, |m| {
        m.failures = 0;
        m.next_try_at = None;
    });
    None
}

fn wait(store: &ScreenStore, manifest: &Manifest, now: DateTime<Utc>) {
    wait_at_least(store, manifest, now, None);
}

/// One more failure in a row: the recording waits [`backoff`], or longer when the server asked for that.
fn wait_at_least(store: &ScreenStore, manifest: &Manifest, now: DateTime<Utc>, retry_after_secs: Option<u64>) {
    let _ = store.update(&manifest.recording, |m| {
        let asked = Duration::seconds(retry_after_secs.unwrap_or(0).min(3_600) as i64);
        m.next_try_at = Some(now + backoff(m.failures).max(asked));
        m.failures = m.failures.saturating_add(1);
    });
}

#[cfg(test)]
mod tests {
    use super::*;

    fn server(status: u16, code: Option<&str>) -> AppResult<()> {
        Err(AppError::Server { status, message: "x".into(), code: code.map(str::to_string), retry_after_secs: None })
    }

    #[test]
    fn every_answer_has_its_next_step() {
        assert_eq!(next_after(&AppResult::Ok(())), Next::Sent);
        assert_eq!(next_after(&server(409, Some("too_many_screens"))), Next::DropRecording);
        assert_eq!(next_after(&server(404, Some("session_not_found"))), Next::DropRecording);
        assert_eq!(next_after(&server(403, Some("not_allowed"))), Next::DropRecording);
        assert_eq!(next_after(&server(404, Some("not_found"))), Next::ServerMissing);
        assert_eq!(next_after(&server(404, None)), Next::ServerMissing);
        assert_eq!(next_after(&server(405, Some("method_not_allowed"))), Next::ServerMissing);
        assert_eq!(next_after(&server(403, Some("feature_not_in_plan"))), Next::NotInPlan);
        assert_eq!(next_after(&server(413, Some("too_large"))), Next::DropScreen);
        assert_eq!(next_after(&server(422, Some("validation_failed"))), Next::DropScreen);
        assert_eq!(next_after(&server(500, None)), Next::Later(None));
        assert_eq!(next_after(&server(503, None)), Next::Later(None));
        assert_eq!(next_after::<()>(&Err(AppError::Network("offline".into()))), Next::Later(None));
        assert_eq!(next_after::<()>(&Err(AppError::NotPaired)), Next::Unpaired);
        let limited: AppResult<()> = Err(AppError::Server { status: 429, message: "slow".into(), code: Some("rate_limited".into()), retry_after_secs: Some(42) });
        assert_eq!(next_after(&limited), Next::Later(Some(42)));
    }

    #[test]
    fn failures_wait_longer_each_time_up_to_half_an_hour() {
        assert_eq!(backoff(0), Duration::seconds(30));
        assert_eq!(backoff(1), Duration::seconds(60));
        assert_eq!(backoff(3), Duration::seconds(240));
        assert_eq!(backoff(6), Duration::seconds(1_800));
        assert_eq!(backoff(40), Duration::seconds(1_800));
    }
}
