//! The activity centre on the desktop (plan B18): quiet hours, what counts as new, the notification text and the API.

mod common;

use serde_json::json;

use common::{Action, MockServer, Reply, Router};
use desktop_lib::activity::{ActivityItem, MAX_SINGLE, Seen, in_quiet_hours, notification_text, parse_hhmm};
use desktop_lib::net::api::ApiClient;

const TOKEN: &str = "7|eld_TOKEN_PLACEHOLDER";

fn item(id: &str, read: bool) -> ActivityItem {
    ActivityItem { id: id.into(), kind: Some("transcript_ready".into()), title: "Transcript ready".into(), body: format!("Call {id}"), session_id: Some(format!("s-{id}")), read, at: None, resolved: false }
}

#[test]
fn times_are_read_strictly() {
    assert_eq!(parse_hhmm("00:00"), Some(0));
    assert_eq!(parse_hhmm("22:30"), Some(1350));
    for bad in ["24:00", "7:00", "07:60", "0700", "", "aa:bb"] {
        assert_eq!(parse_hhmm(bad), None, "{bad}");
    }
}

#[test]
fn quiet_hours_wrap_past_midnight() {
    let at = |h: u32, m: u32| h * 60 + m;
    assert!(in_quiet_hours(true, "22:00", "07:00", at(23, 0)));
    assert!(in_quiet_hours(true, "22:00", "07:00", at(6, 59)));
    assert!(!in_quiet_hours(true, "22:00", "07:00", at(7, 0)));
    assert!(!in_quiet_hours(true, "22:00", "07:00", at(12, 0)));
    assert!(in_quiet_hours(true, "12:00", "13:00", at(12, 30)));
    assert!(!in_quiet_hours(true, "12:00", "13:00", at(13, 0)));
    assert!(in_quiet_hours(true, "09:00", "09:00", at(3, 0)), "equal ends mean all day");
    assert!(!in_quiet_hours(false, "22:00", "07:00", at(23, 0)), "off");
    assert!(!in_quiet_hours(true, "nonsense", "07:00", at(23, 0)));
}

#[test]
fn the_first_poll_only_learns_and_later_polls_show_new_unread_items_once() {
    let dir = tempfile::tempdir().unwrap();
    let path = dir.path().join("activity_seen.json");
    let mut seen = Seen::load(&path);
    assert!(seen.fresh(&[item("old", false)]).is_empty(), "no flood of old notifications at first start");
    seen.save(&path).unwrap();

    let mut seen = Seen::load(&path);
    let fresh = seen.fresh(&[item("c", false), item("b", true), item("a", false), item("old", false)]);
    assert_eq!(fresh.iter().map(|i| i.id.as_str()).collect::<Vec<_>>(), ["a", "c"], "oldest first, read ones skipped");
    assert!(seen.fresh(&[item("c", false)]).is_empty(), "never twice");
}

#[test]
fn notifications_say_little_when_locked_and_summarise_bursts() {
    let one = [item("a", false)];
    assert_eq!(notification_text(&one, false), vec![("Transcript ready".to_string(), "Call a".to_string())]);
    assert_eq!(notification_text(&one, true), vec![("EchoLedger".to_string(), "New activity. Unlock EchoLedger to see it.".to_string())]);
    let many: Vec<ActivityItem> = (0..=MAX_SINGLE).map(|i| item(&i.to_string(), false)).collect();
    assert_eq!(notification_text(&many, false), vec![("EchoLedger".to_string(), format!("{} new notifications", MAX_SINGLE + 1))]);
}

#[tokio::test]
async fn the_api_lists_and_marks_read() {
    let server = MockServer::start(
        Router::new()
            .fixed("GET", "/api/activity", Reply::json(200, json!({"data": [{"id": "n1", "title": "Device paired", "read": false}], "unread_count": 1})))
            .fixed("POST", "/api/activity/read", Reply::json(200, json!({"unread_count": 0}))),
    );
    common::init_tls();
    let api = ApiClient::new(&server.base_url(), &[], Some(TOKEN.to_string())).unwrap();

    assert_eq!(api.activity(20).await.unwrap()["unread_count"], 1);
    api.mark_activity_read(Some(&["n1".to_string()])).await.unwrap();
    api.mark_activity_read(None).await.unwrap();
    let requests = server.requests();
    assert_eq!(common::query_value(requests[0].query(), "limit").as_deref(), Some("20"));
    assert_eq!(requests[1].json(), json!({"ids": ["n1"]}));
    assert_eq!(requests[2].json(), json!({}));
}

#[tokio::test]
async fn the_badge_the_groups_and_retry_all() {
    // 2.0.0-r: the one Activity number, the groups, one group's items and Retry all.
    let server = MockServer::start(
        Router::new()
            .fixed("GET", "/api/badge", Reply::json(200, json!({"count": 4, "label": "4", "tone": "danger"})))
            .fixed("GET", "/api/activity/groups", Reply::json(200, json!({"data": [{"group": "upload_failed", "count": 3, "unread": 2, "session_ids": ["s1", "s2", "s3"], "at": "2026-11-25T09:00:00Z"}]})))
            .fixed("GET", "/api/activity", Reply::json(200, json!({"data": [], "unread_count": 0, "retry_requested_at": null})))
            .fixed("POST", "/api/activity/retry-all", Reply::json(202, json!({"retry_requested_at": "2026-11-25T09:05:00Z", "count": 3}))),
    );
    common::init_tls();
    let api = ApiClient::new(&server.base_url(), &[], Some(TOKEN.to_string())).unwrap();

    assert_eq!(api.badge().await.unwrap(), json!({"count": 4, "label": "4", "tone": "danger"}));
    assert_eq!(api.activity_groups().await.unwrap()["data"][0]["count"], 3);
    api.activity_in_group("upload_failed", 50).await.unwrap();
    let asked = api.activity_retry_all("upload_failed").await.unwrap();
    assert_eq!(asked["retry_requested_at"], "2026-11-25T09:05:00Z", "a 202 is an answer");
    let requests = server.requests();
    let query = requests[2].query();
    assert_eq!(common::query_value(query, "group").as_deref(), Some("upload_failed"));
    assert_eq!(common::query_value(query, "limit").as_deref(), Some("50"));
    assert_eq!(requests[3].json(), json!({"group": "upload_failed"}));
}

// ---- Retry all (BE-03): the poll's half and this computer's own ------------------------------------------------------

mod retry_all {
    use super::*;
    use desktop_lib::activity::{RetryAllStep, follow_retry_all, retry_all};
    use desktop_lib::net::sync::paths;
    use desktop_lib::net::uploader::{PendingUpload, UploadQueue};
    use desktop_lib::owner::Owner;
    use std::path::{Path, PathBuf};
    use std::sync::Mutex;

    const T1: &str = "2026-11-25T09:00:00Z";
    const T2: &str = "2026-11-25T09:05:00Z";

    fn me() -> Owner {
        Owner::new("https://ledger.example.test", Some("ayesha@example.test"))
    }
    fn other() -> Owner {
        Owner::new("https://ledger.example.test", Some("sara@example.test"))
    }
    /// Two failed uploads of mine and one of another account, in `data`'s queue.
    fn failed_queue(data: &Path) {
        let item = |name: &str, owner: &Owner| PendingUpload {
            dir: PathBuf::from(format!("/tmp/recordings/{name}")),
            state: "failed".into(),
            last_error: Some("The server could not verify this upload.".into()),
            owner: Some(owner.clone()),
            ..PendingUpload::default()
        };
        UploadQueue { items: vec![item("a", &me()), item("b", &me()), item("c", &other())] }.save(&paths::uploads(data)).unwrap();
    }
    fn states(data: &Path) -> Vec<String> {
        UploadQueue::load(&paths::uploads(data)).items.iter().map(|i| i.state.clone()).collect()
    }

    #[tokio::test]
    async fn the_poll_runs_a_newer_retry_all_once_and_never_the_first_one_it_sees() {
        let answers = [T1, T1, T2, T2];
        let server = MockServer::start(Router::new().route("GET", "/api/activity", move |_, n| {
            Action::Reply(Reply::json(200, json!({"data": [], "unread_count": 0, "retry_requested_at": answers[n.min(3)]})))
        }));
        common::init_tls();
        let api = ApiClient::new(&server.base_url(), &[], Some(TOKEN.to_string())).unwrap();
        let data = tempfile::tempdir().unwrap();
        failed_queue(data.path());
        let lock = Mutex::new(());
        let mut steps = Vec::new();
        for _ in 0..4 {
            let answer = api.activity(20).await.unwrap();
            steps.push(follow_retry_all(data.path(), &lock, &me(), answer["retry_requested_at"].as_str()).unwrap());
            if steps.len() == 2 {
                assert_eq!(states(data.path()), ["failed", "failed", "failed"], "a request from before this sign-in runs nothing");
            }
        }
        assert_eq!(steps, [RetryAllStep::Learned, RetryAllStep::Nothing, RetryAllStep::Ran(2), RetryAllStep::Nothing]);
        assert_eq!(states(data.path()), ["queued", "queued", "failed"], "mine went back to the queue; Sara's stays as it is");
        // Another account signed in here learns its own first request; it never borrows this one's.
        assert_eq!(follow_retry_all(data.path(), &lock, &other(), Some(T2)).unwrap(), RetryAllStep::Learned);
    }

    #[tokio::test]
    async fn retry_all_from_here_retries_here_asks_the_others_and_is_not_run_again_by_the_poll() {
        let server = MockServer::start(
            Router::new().fixed("POST", "/api/activity/retry-all", Reply::json(202, json!({"retry_requested_at": T2, "count": 3}))),
        );
        common::init_tls();
        let api = ApiClient::new(&server.base_url(), &[], Some(TOKEN.to_string()));
        let data = tempfile::tempdir().unwrap();
        failed_queue(data.path());
        let lock = Mutex::new(());
        let answer = retry_all(api, data.path(), &lock, Some(&me()), "upload_failed").await.unwrap();
        assert_eq!(answer, json!({"retry_requested_at": T2, "count": 3, "here": 2}));
        assert_eq!(states(data.path()), ["queued", "queued", "failed"]);
        assert_eq!(server.requests()[0].json(), json!({"group": "upload_failed"}));
        assert_eq!(follow_retry_all(data.path(), &lock, &me(), Some(T2)).unwrap(), RetryAllStep::Nothing, "the poll doesn't run it again");
    }

    #[tokio::test]
    async fn offline_retry_all_still_retries_here_and_says_why_the_others_werent_asked() {
        common::init_tls();
        let lock = Mutex::new(());
        // Nothing listens there: a network error.
        let unreachable = || ApiClient::new("https://127.0.0.1:9", &[], Some(TOKEN.to_string()));
        let data = tempfile::tempdir().unwrap();
        failed_queue(data.path());
        let answer = retry_all(unreachable(), data.path(), &lock, Some(&me()), "upload_failed").await.unwrap();
        assert_eq!(answer, json!({"retry_requested_at": null, "count": 2, "here": 2, "others": "offline"}));
        assert_eq!(states(data.path()), ["queued", "queued", "failed"]);
        // Nothing failed here any more and still offline: the network error itself.
        let err = retry_all(unreachable(), data.path(), &lock, Some(&me()), "upload_failed").await.unwrap_err();
        assert!(matches!(err, desktop_lib::error::AppError::Network(_)), "{err:?}");

        // The server answers, but with an error: retried here, the others not asked, and not called offline.
        let server = MockServer::start(Router::new().fixed("POST", "/api/activity/retry-all", Reply::json(500, json!({"message": "Server Error"}))));
        let data = tempfile::tempdir().unwrap();
        failed_queue(data.path());
        let answer = retry_all(ApiClient::new(&server.base_url(), &[], Some(TOKEN.to_string())), data.path(), &lock, Some(&me()), "upload_failed").await.unwrap();
        assert_eq!(answer["others"], "failed");
        assert_eq!(answer["here"], 2);
        assert!(!data.path().join("retry_all_ran.json").exists(), "nothing recorded: no request was made");
    }
}
