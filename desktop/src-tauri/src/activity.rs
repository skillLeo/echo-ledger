//! The activity centre on the desktop (plan B18): new server notifications become OS notifications, except in the
//! quiet hours; clicking one brings EchoLedger forward on that recording. Pure parts are unit-tested.

use std::collections::VecDeque;
use std::path::Path;

use serde::{Deserialize, Serialize};

/// How many notification ids are remembered as already shown.
const SEEN_LIMIT: usize = 200;
/// More new items than this in one poll become one summary notification.
pub const MAX_SINGLE: usize = 3;

/// "22:30" → minutes after midnight.
pub fn parse_hhmm(text: &str) -> Option<u32> {
    let (h, m) = text.split_once(':')?;
    if h.len() != 2 || m.len() != 2 {
        return None;
    }
    let (h, m) = (h.parse::<u32>().ok()?, m.parse::<u32>().ok()?);
    (h < 24 && m < 60).then_some(h * 60 + m)
}

/// Is [now] (minutes after midnight, local) inside the quiet hours? Overnight ranges (22:00 to 07:00) wrap; equal
/// ends mean all day.
pub fn in_quiet_hours(enabled: bool, from: &str, to: &str, now: u32) -> bool {
    let (Some(from), Some(to)) = (parse_hhmm(from), parse_hhmm(to)) else { return false };
    if !enabled {
        return false;
    }
    match from.cmp(&to) {
        std::cmp::Ordering::Equal => true,
        std::cmp::Ordering::Less => now >= from && now < to,
        std::cmp::Ordering::Greater => now >= from || now < to,
    }
}

/// One item of `GET /api/activity`.
#[derive(Clone, Debug, PartialEq, Eq, Serialize, Deserialize)]
pub struct ActivityItem {
    pub id: String,
    #[serde(default)]
    pub kind: Option<String>,
    #[serde(default)]
    pub title: String,
    #[serde(default)]
    pub body: String,
    #[serde(default)]
    pub session_id: Option<String>,
    #[serde(default)]
    pub read: bool,
    #[serde(default)]
    pub at: Option<String>,
    /// The problem is over (2.0.0-r; a server_problem once the server's check passes). Kept off the window's badge.
    #[serde(default)]
    pub resolved: bool,
}

/// The only Activity group (2.0.0-r): uploads that didn't finish, shown as one line with Retry all.
pub const UPLOAD_FAILED_GROUP: &str = "upload_failed";

/// `retry_all_ran.json` (BE-03): for each account on this computer (its [`account_key`]), the last Retry all
/// (`retry_requested_at`, a server time) this computer has run or learned, so each one runs here once.
#[derive(Clone, Debug, Default, PartialEq, Eq, Serialize, Deserialize)]
pub struct RetryAllRan {
    #[serde(default)]
    pub by: std::collections::BTreeMap<String, String>,
}

/// What the person's latest Retry all means here.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum RetryAllDue {
    /// Nothing asked, an unreadable time, or one this computer has already run (or an older one).
    No,
    /// The first one this account sees here (a fresh sign-in, or the first poll since this version): learned, not run.
    First,
    /// Newer than the last one run here: run it now.
    Run,
}

/// What the poll did with a Retry all.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum RetryAllStep {
    Nothing,
    /// Learned without running (the first one this account sees here).
    Learned,
    /// Ran: how many failed uploads went back to the queue.
    Ran(usize),
}

/// The account a Retry all belongs to: its server and email.
pub fn account_key(owner: &crate::owner::Owner) -> String {
    format!("{}|{}", owner.server.to_lowercase(), owner.email.as_deref().unwrap_or_default())
}

fn server_time(v: &str) -> Option<chrono::DateTime<chrono::FixedOffset>> {
    chrono::DateTime::parse_from_rfc3339(v).ok()
}

impl RetryAllRan {
    pub fn load(path: &Path) -> Self {
        std::fs::read(path).ok().and_then(|b| serde_json::from_slice(&b).ok()).unwrap_or_default()
    }

    /// Written whole through a temporary file of its own (never another writer's), then renamed into place.
    pub fn save(&self, path: &Path) -> std::io::Result<()> {
        static N: std::sync::atomic::AtomicU64 = std::sync::atomic::AtomicU64::new(0);
        let n = N.fetch_add(1, std::sync::atomic::Ordering::Relaxed);
        let tmp = path.with_extension(format!("json.{}-{n}.tmp", std::process::id()));
        std::fs::write(&tmp, serde_json::to_vec(self)?)?;
        std::fs::rename(&tmp, path).inspect_err(|_| {
            let _ = std::fs::remove_file(&tmp);
        })
    }

    /// Whether `account`'s latest Retry all (`requested`, from any app) runs here now: once per Retry all and device.
    pub fn due(&self, account: &str, requested: Option<&str>) -> RetryAllDue {
        let Some(asked) = requested.and_then(server_time) else { return RetryAllDue::No };
        match self.by.get(account) {
            None => RetryAllDue::First,
            Some(ran) => match server_time(ran) {
                Some(ran) if asked <= ran => RetryAllDue::No,
                _ => RetryAllDue::Run,
            },
        }
    }

    /// Records `at` for `account`, keeping the later of it and what is there (an older answer never undoes a newer one).
    pub fn note(&mut self, account: &str, at: &str) {
        let Some(new) = server_time(at) else { return };
        let keep = self.by.get(account).and_then(|old| server_time(old)).is_none_or(|old| new > old);
        if keep {
            self.by.insert(account.to_string(), at.to_string());
        }
    }
}

/// Retry all on this computer (BE-03): every failed upload `owner` may send goes back to the queue at once, under the
/// queue's lock. Returns how many.
pub fn retry_failed_here(data_dir: &Path, lock: &std::sync::Mutex<()>, owner: Option<&crate::owner::Owner>) -> crate::error::AppResult<usize> {
    use crate::net::uploader::UploadQueue;
    let _guard = lock.lock().unwrap_or_else(|p| p.into_inner());
    let path = crate::net::sync::paths::uploads(data_dir);
    let mut queue = UploadQueue::load(&path);
    let n = queue.retry_failed(owner);
    if n > 0 {
        queue.save(&path)?;
    }
    Ok(n)
}

/// Records, under the queue's lock, that this computer ran `owner`'s Retry all asked at `at` (keeping the later time).
pub fn note_retry_all(data_dir: &Path, lock: &std::sync::Mutex<()>, owner: &crate::owner::Owner, at: &str) -> std::io::Result<()> {
    let _guard = lock.lock().unwrap_or_else(|p| p.into_inner());
    let path = crate::net::sync::paths::retry_all_ran(data_dir);
    let mut ran = RetryAllRan::load(&path);
    ran.note(&account_key(owner), at);
    ran.save(&path)
}

/// The poll's half of Retry all (BE-03): `asked` is `retry_requested_at` from `GET /api/activity`, read for `owner`. A
/// newer one than this computer ran retries `owner`'s failed uploads and is recorded only once that worked; the first
/// one an account sees here is only learned (a fresh sign-in never runs an old request). All under the queue's lock.
pub fn follow_retry_all(data_dir: &Path, lock: &std::sync::Mutex<()>, owner: &crate::owner::Owner, asked: Option<&str>) -> crate::error::AppResult<RetryAllStep> {
    use crate::net::uploader::UploadQueue;
    let _guard = lock.lock().unwrap_or_else(|p| p.into_inner());
    let path = crate::net::sync::paths::retry_all_ran(data_dir);
    let mut ran = RetryAllRan::load(&path);
    let key = account_key(owner);
    let step = match ran.due(&key, asked) {
        RetryAllDue::No => return Ok(RetryAllStep::Nothing),
        RetryAllDue::First => RetryAllStep::Learned,
        RetryAllDue::Run => {
            let uploads = crate::net::sync::paths::uploads(data_dir);
            let mut queue = UploadQueue::load(&uploads);
            let n = queue.retry_failed(Some(owner));
            if n > 0 {
                queue.save(&uploads)?;
            }
            RetryAllStep::Ran(n)
        }
    };
    ran.note(&key, asked.unwrap_or_default());
    ran.save(&path)?;
    Ok(step)
}

/// Retry all from this computer (BE-03): its own failed uploads go back to the queue at once, then the server asks the
/// person's other devices (`POST /api/activity/retry-all`), and the time it answers is recorded so the poll doesn't run
/// it again here. The answer gains `here` (how many were retried here). When the server can't be asked but something
/// was retried here, `retry_requested_at` is null and `others` says why: `offline` (a network error) or `failed`.
/// With nothing here either, the server's error.
pub async fn retry_all(api: crate::error::AppResult<crate::net::api::ApiClient>, data_dir: &Path, lock: &std::sync::Mutex<()>,
    owner: Option<&crate::owner::Owner>, group: &str) -> crate::error::AppResult<serde_json::Value> {
    let here = retry_failed_here(data_dir, lock, owner)?;
    let asked = match api {
        Ok(api) => api.activity_retry_all(group).await,
        Err(e) => Err(e),
    };
    match asked {
        Ok(mut answer) => {
            if let (Some(owner), Some(at)) = (owner, answer.get("retry_requested_at").and_then(serde_json::Value::as_str)) {
                note_retry_all(data_dir, lock, owner, at)?;
            }
            answer["here"] = serde_json::json!(here);
            Ok(answer)
        }
        Err(e) if here > 0 => {
            let others = if matches!(e, crate::error::AppError::Network(_)) { "offline" } else { "failed" };
            Ok(serde_json::json!({ "retry_requested_at": null, "count": here, "here": here, "others": others }))
        }
        Err(e) => Err(e),
    }
}

/// `activity_seen.json`: the ids already shown, so a restart never repeats them.
#[derive(Clone, Debug, Default, PartialEq, Eq, Serialize, Deserialize)]
pub struct Seen {
    /// False until the first poll, which only learns what exists (no flood of old notifications).
    #[serde(default)]
    pub initialised: bool,
    #[serde(default)]
    pub ids: VecDeque<String>,
}

impl Seen {
    pub fn load(path: &Path) -> Self {
        std::fs::read(path).ok().and_then(|b| serde_json::from_slice(&b).ok()).unwrap_or_default()
    }

    pub fn save(&self, path: &Path) -> std::io::Result<()> {
        let tmp = path.with_extension("json.tmp");
        std::fs::write(&tmp, serde_json::to_vec(self)?)?;
        std::fs::rename(tmp, path)
    }

    /// The unread items not shown before, oldest first; remembers them all.
    pub fn fresh(&mut self, items: &[ActivityItem]) -> Vec<ActivityItem> {
        let first = !self.initialised;
        self.initialised = true;
        let mut out = Vec::new();
        for item in items.iter().rev() {
            if self.ids.contains(&item.id) {
                continue;
            }
            self.ids.push_back(item.id.clone());
            if !first && !item.read {
                out.push(item.clone());
            }
        }
        while self.ids.len() > SEEN_LIMIT {
            self.ids.pop_front();
        }
        out
    }
}

/// Whether `item` answers a "Notify me when ready" (its recording's transcript is ready or failed); the request is used
/// up by it.
pub fn asked_for(asked: &mut std::collections::HashSet<String>, item: &ActivityItem) -> bool {
    let done = matches!(item.kind.as_deref(), Some("transcript_ready" | "transcript_failed"));
    done && item.session_id.as_ref().is_some_and(|id| asked.remove(id))
}

/// The category the notifications of `items` sound as: theirs when they share one, else Other (a summary of several).
pub fn category_of(items: &[ActivityItem]) -> crate::notifications::Category {
    use crate::notifications::Category;
    let mut kinds = items.iter().map(|i| Category::of_activity(i.kind.as_deref()));
    let first = kinds.next().unwrap_or(Category::Other);
    if kinds.all(|k| k == first) { first } else { Category::Other }
}

/// What the OS notification says. While the app is locked it says nothing about the recording.
pub fn notification_text(items: &[ActivityItem], locked: bool) -> Vec<(String, String)> {
    if locked {
        return vec![("EchoLedger".into(), "New activity. Unlock EchoLedger to see it.".into())];
    }
    if items.len() > MAX_SINGLE {
        return vec![("EchoLedger".into(), format!("{} new notifications", items.len()))];
    }
    items.iter().map(|i| (i.title.clone(), i.body.clone())).collect()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_server_problem_and_its_end_reach_the_window() {
        // 2.0.0-v: super admins get `server_problem`; once the server's check passes it is `resolved` (2.0.0-r).
        let fixed: ActivityItem = serde_json::from_value(serde_json::json!({
            "id": "a9", "kind": "server_problem", "title": "Fixed: uploads work again", "body": "", "session_id": null,
            "read": false, "at": "2026-11-25T09:00:00Z", "resolved": true,
        }))
        .unwrap();
        assert!(fixed.resolved);
        assert_eq!(serde_json::to_value(&fixed).unwrap()["resolved"], true, "passed on to the window");
        let older: ActivityItem = serde_json::from_value(serde_json::json!({ "id": "a1", "kind": "upload_failed" })).unwrap();
        assert!(!older.resolved, "an older server's item: not resolved");
    }

    #[test]
    fn a_retry_all_runs_once_per_account_on_this_computer_and_only_when_newer() {
        let mut ran = RetryAllRan::default();
        assert_eq!(ran.due("a", None), RetryAllDue::No, "nobody asked");
        assert_eq!(ran.due("a", Some("not a time")), RetryAllDue::No, "an unreadable time runs nothing");
        assert_eq!(ran.due("a", Some("2026-11-25T09:00:00Z")), RetryAllDue::First, "the first one seen here: learned, not run");
        ran.note("a", "2026-11-25T09:00:00Z");
        assert_eq!(ran.due("a", Some("2026-11-25T09:00:00Z")), RetryAllDue::No, "the same one: not again");
        assert_eq!(ran.due("a", Some("2026-11-25T14:00:00+05:00")), RetryAllDue::No, "the same moment in another zone: not again");
        assert_eq!(ran.due("a", Some("2026-11-25T08:59:00Z")), RetryAllDue::No, "an older one: no");
        assert_eq!(ran.due("a", Some("2026-11-25T09:00:01Z")), RetryAllDue::Run, "a newer one: yes");
        assert_eq!(ran.due("b", Some("2026-11-25T08:00:00Z")), RetryAllDue::First, "another account keeps its own");
        // An older answer finishing late never undoes a newer one.
        ran.note("a", "2026-11-25T10:00:00Z");
        ran.note("a", "2026-11-25T09:30:00Z");
        assert_eq!(ran.by["a"], "2026-11-25T10:00:00Z");
        let dir = tempfile::tempdir().unwrap();
        let path = dir.path().join("retry_all_ran.json");
        assert_eq!(RetryAllRan::load(&path), RetryAllRan::default(), "no file: never ran");
        ran.save(&path).unwrap();
        assert_eq!(RetryAllRan::load(&path), ran);
        assert_eq!(std::fs::read_dir(dir.path()).unwrap().count(), 1, "no temporary file left behind");
        std::fs::write(&path, b"{oops").unwrap();
        assert_eq!(RetryAllRan::load(&path), RetryAllRan::default(), "a damaged file: as if never ran");
    }

    #[test]
    fn notify_me_when_ready_is_answered_once_by_its_transcript() {
        let mut asked = std::collections::HashSet::from(["s1".to_string()]);
        let item = |kind: &str, session: &str| ActivityItem {
            id: format!("{kind}-{session}"),
            kind: Some(kind.into()),
            title: String::new(),
            body: String::new(),
            session_id: Some(session.into()),
            read: false,
            at: None,
            resolved: false,
        };
        assert!(!asked_for(&mut asked, &item("upload_failed", "s1")), "only the transcript answers it");
        assert!(!asked_for(&mut asked, &item("transcript_ready", "s2")));
        assert!(asked_for(&mut asked, &item("transcript_ready", "s1")));
        assert!(!asked_for(&mut asked, &item("transcript_ready", "s1")), "used up");
        assert_eq!(category_of(&[item("transcript_ready", "a"), item("transcript_failed", "b")]), crate::notifications::Category::Transcription);
        assert_eq!(category_of(&[item("transcript_ready", "a"), item("shared_with_you", "b")]), crate::notifications::Category::Other);
    }
}
