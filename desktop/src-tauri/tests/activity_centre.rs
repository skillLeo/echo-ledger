//! The activity centre on the desktop (plan B18): quiet hours, what counts as new, the notification text and the API.

mod common;

use serde_json::json;

use common::{MockServer, Reply, Router};
use desktop_lib::activity::{ActivityItem, MAX_SINGLE, Seen, in_quiet_hours, notification_text, parse_hhmm};
use desktop_lib::net::api::ApiClient;

const TOKEN: &str = "7|eld_TOKEN_PLACEHOLDER";

fn item(id: &str, read: bool) -> ActivityItem {
    ActivityItem { id: id.into(), kind: Some("transcript_ready".into()), title: "Transcript ready".into(), body: format!("Call {id}"), session_id: Some(format!("s-{id}")), read, at: None }
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
