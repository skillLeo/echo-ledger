//! Screen moments and the meeting pack (docs/API.md, since v1.5) against an in-process HTTP server: the upload body,
//! what each answer does to the stored screens (sent and deleted, dropped, kept for later, everything stopped), the
//! project's screens switch, the list routes, the pack and the signed image links.

mod common;

use chrono::Utc;
use serde_json::json;

use common::{Action, MockServer, Recorded, Reply, Router};
use desktop_lib::net::api::{ApiClient, same_server};
use desktop_lib::net::models::ScreenUpload;
use desktop_lib::screens::store::{ScreenKey, ScreenStore};
use desktop_lib::screens::upload::{Next, drain};

const TOKEN: &str = "7|eld_TOKEN_PLACEHOLDER";
const SESSION: &str = "01JB8Q4C1D2E3F4G5H6J7K8M9N";
const ACME: &str = "01JBACME000000000000000000";
const WEBP: &[u8] = b"RIFF\x24\0\0\0WEBPVP8 a small test picture";

fn client(server: &MockServer) -> ApiClient {
    common::init_tls();
    ApiClient::new(&server.base_url(), &[], Some(TOKEN.to_string())).expect("local client")
}

fn key() -> ScreenKey {
    ScreenKey::from_bytes(&[7u8; 32])
}

/// A store with `n` screens of the recording `rec`, linked to the session.
fn stored(n: u64) -> (tempfile::TempDir, ScreenStore) {
    let dir = tempfile::tempdir().unwrap();
    let store = ScreenStore::in_dir(dir.path());
    for i in 0..n {
        store.add("rec", &key(), WEBP, 10_000 * (i + 1), 1920, 1080, if i == 0 { "change" } else { "words" }, Utc::now()).unwrap();
    }
    store.link("rec", SESSION).unwrap();
    (dir, store)
}

/// `GET /api/sessions/{id}` of a recording in the Inbox.
fn inbox_session() -> Router {
    Router::new().fixed("GET", &format!("/api/sessions/{SESSION}"), Reply::json(200, json!({"id": SESSION, "client": null})))
}

fn screens_path() -> String {
    format!("/api/sessions/{SESSION}/screens")
}

fn part<'a>(request: &'a Recorded, name: &str) -> Option<&'a str> {
    let body = std::str::from_utf8(&request.body).ok()?;
    let marker = format!("name=\"{name}\"\r\n\r\n");
    let start = body.find(&marker)? + marker.len();
    body[start..].split("\r\n").next()
}

#[tokio::test]
async fn a_screen_goes_as_multipart_with_its_moment_size_trigger_and_hash() {
    let server = MockServer::start(Router::new().fixed("POST", &screens_path(), Reply::json(201, json!({"data": {"id": "01JE", "index": 1}}))));
    let upload = ScreenUpload { at_ms: 872_000, width: 1920, height: 1080, trigger: "words".into(), sha256: "ab".repeat(32) };

    client(&server).upload_screen(SESSION, &upload, WEBP).await.unwrap();

    let request = &server.requests()[0];
    assert!(request.header("content-type").unwrap().starts_with("multipart/form-data; boundary="));
    assert_eq!(request.header("authorization"), Some(format!("Bearer {TOKEN}").as_str()));
    assert_eq!(part(request, "at_ms"), Some("872000"));
    assert_eq!(part(request, "width"), Some("1920"));
    assert_eq!(part(request, "height"), Some("1080"));
    assert_eq!(part(request, "trigger"), Some("words"));
    assert_eq!(part(request, "sha256"), Some("ab".repeat(32).as_str()));
    let body = String::from_utf8_lossy(&request.body);
    assert!(body.contains("name=\"image\"; filename=\"screen.webp\"\r\nContent-Type: image/webp"));
    assert!(request.body.windows(WEBP.len()).any(|w| w == WEBP), "the plain picture is sent, not the sealed file");
}

#[tokio::test]
async fn sent_screens_are_deleted_here_and_the_folder_goes_with_the_last() {
    let server = MockServer::start(inbox_session().route("POST", &screens_path(), |_, nth| {
        // The second is an image the server already holds: 200 with its screen, which counts as sent.
        Action::Reply(Reply::json(if nth == 1 { 200 } else { 201 }, json!({"data": {"id": format!("01JE{nth}")}})))
    }));
    let (dir, store) = stored(3);

    let result = drain(&client(&server), &store, &key(), Utc::now()).await;

    assert_eq!((result.sent, result.dropped, result.stopped), (3, 0, None));
    assert_eq!(server.requests_to("POST", &screens_path()).len(), 3);
    assert!(!dir.path().join("screens").join("rec").exists());
    // In time order.
    let moments: Vec<String> = server.requests_to("POST", &screens_path()).iter().map(|r| part(r, "at_ms").unwrap().to_string()).collect();
    assert_eq!(moments, ["10000", "20000", "30000"]);
}

#[tokio::test]
async fn screens_wait_for_their_session() {
    let server = MockServer::start(inbox_session());
    let dir = tempfile::tempdir().unwrap();
    let store = ScreenStore::in_dir(dir.path());
    store.add("deciding", &key(), WEBP, 1_000, 10, 10, "change", Utc::now()).unwrap();

    let result = drain(&client(&server), &store, &key(), Utc::now()).await;

    assert_eq!((result.sent, result.dropped), (0, 0));
    assert!(server.requests().is_empty(), "nothing is sent before the recording is kept and on the server");
    assert_eq!(store.count("deciding"), 1);
}

#[tokio::test]
async fn four_hundred_screens_end_the_recordings_screens() {
    let server = MockServer::start(inbox_session().route("POST", &screens_path(), |_, nth| {
        if nth == 0 {
            Action::Reply(Reply::json(201, json!({"data": {}})))
        } else {
            Action::Reply(Reply::json(409, json!({"message": "A recording keeps at most 400 screens.", "code": "too_many_screens"})))
        }
    }));
    let (dir, store) = stored(4);

    let result = drain(&client(&server), &store, &key(), Utc::now()).await;

    assert_eq!((result.sent, result.dropped, result.stopped), (1, 3, None));
    assert_eq!(server.requests_to("POST", &screens_path()).len(), 2, "no screen is sent after 409");
    assert!(!dir.path().join("screens").join("rec").exists());
}

#[tokio::test]
async fn a_deleted_recording_drops_its_screens() {
    let server = MockServer::start(
        Router::new().fixed("GET", &format!("/api/sessions/{SESSION}"), Reply::json(404, json!({"message": "Not found.", "code": "session_not_found"}))),
    );
    let (dir, store) = stored(2);

    let result = drain(&client(&server), &store, &key(), Utc::now()).await;

    assert_eq!(result.dropped, 2);
    assert!(server.requests_to("POST", &screens_path()).is_empty());
    assert!(!dir.path().join("screens").join("rec").exists());
}

#[tokio::test]
async fn an_older_server_stops_everything() {
    // The session exists; the screens route does not (the router's 404 has no code, like Laravel's).
    let server = MockServer::start(inbox_session());
    let (_dir, store) = stored(2);

    let result = drain(&client(&server), &store, &key(), Utc::now()).await;

    assert_eq!(result.stopped, Some(Next::ServerMissing));
    assert_eq!(result.sent, 0);
}

#[tokio::test]
async fn a_plan_without_screen_moments_stops_everything() {
    let server = MockServer::start(inbox_session().fixed(
        "POST",
        &screens_path(),
        Reply::json(403, json!({"message": "Screen moments are not in your plan", "code": "feature_not_in_plan", "feature": "screen_moments"})),
    ));
    let (_dir, store) = stored(2);

    let result = drain(&client(&server), &store, &key(), Utc::now()).await;

    assert_eq!(result.stopped, Some(Next::NotInPlan));
    assert_eq!(server.requests_to("POST", &screens_path()).len(), 1);
}

#[tokio::test]
async fn a_busy_server_keeps_the_screens_and_waits_longer() {
    let server = MockServer::start(inbox_session().fixed("POST", &screens_path(), Reply::json(503, json!({"message": "Busy"}))));
    let (_dir, store) = stored(2);
    let now = Utc::now();

    let result = drain(&client(&server), &store, &key(), now).await;

    assert_eq!((result.sent, result.dropped, result.stopped), (0, 0, None));
    let manifest = store.load("rec").unwrap();
    assert_eq!(manifest.items.len(), 2, "nothing is lost");
    assert_eq!(manifest.failures, 1);
    assert!(manifest.next_try_at.unwrap() > now + chrono::Duration::seconds(25));
    // Not due again before its time: the next round sends nothing.
    drain(&client(&server), &store, &key(), now).await;
    assert_eq!(server.requests_to("POST", &screens_path()).len(), 1);
}

#[tokio::test]
async fn rate_limits_wait_at_least_retry_after() {
    let server = MockServer::start(inbox_session().fixed("POST", &screens_path(), Reply::json(429, json!({"message": "Slow down", "code": "rate_limited"})).header("Retry-After", "120")));
    let (_dir, store) = stored(1);
    let now = Utc::now();

    drain(&client(&server), &store, &key(), now).await;

    let manifest = store.load("rec").unwrap();
    assert!(manifest.next_try_at.unwrap() >= now + chrono::Duration::seconds(119));
}

#[tokio::test]
async fn a_refused_screen_is_dropped_and_the_rest_go() {
    let server = MockServer::start(inbox_session().route("POST", &screens_path(), |_, nth| {
        if nth == 0 {
            Action::Reply(Reply::json(413, json!({"message": "Too large.", "code": "too_large"})))
        } else {
            Action::Reply(Reply::json(201, json!({"data": {}})))
        }
    }));
    let (_dir, store) = stored(2);

    let result = drain(&client(&server), &store, &key(), Utc::now()).await;

    assert_eq!((result.sent, result.dropped), (1, 1));
}

#[tokio::test]
async fn a_project_with_screens_off_keeps_none() {
    let server = MockServer::start(
        Router::new()
            .fixed("GET", &format!("/api/sessions/{SESSION}"), Reply::json(200, json!({"id": SESSION, "client": {"id": ACME, "name": "Acme"}})))
            .fixed("GET", "/api/clients", Reply::json(200, json!({"data": [{"id": ACME, "name": "Acme", "screens": false}]}))),
    );
    let (dir, store) = stored(3);

    let result = drain(&client(&server), &store, &key(), Utc::now()).await;

    assert_eq!(result.dropped, 3);
    assert!(server.requests_to("POST", &screens_path()).is_empty(), "nothing of it reaches the server");
    assert!(!dir.path().join("screens").join("rec").exists());
}

#[tokio::test]
async fn a_project_that_keeps_screens_is_read_once() {
    let server = MockServer::start(
        Router::new()
            .fixed("GET", &format!("/api/sessions/{SESSION}"), Reply::json(200, json!({"id": SESSION, "client": {"id": ACME, "name": "Acme"}})))
            .fixed("GET", "/api/clients", Reply::json(200, json!({"data": [{"id": ACME, "name": "Acme", "screens": true}]})))
            .route("POST", &screens_path(), |_, nth| {
                if nth == 0 { Action::Reply(Reply::json(201, json!({"data": {}}))) } else { Action::Reply(Reply::json(503, json!({}))) }
            }),
    );
    let (_dir, store) = stored(2);

    drain(&client(&server), &store, &key(), Utc::now()).await;
    let manifest = store.load("rec").unwrap();
    assert!(manifest.project_checked);
    store.update("rec", |m| m.next_try_at = None).unwrap();
    drain(&client(&server), &store, &key(), Utc::now()).await;

    assert_eq!(server.requests_to("GET", "/api/clients").len(), 1, "the project is not read again");
}

#[tokio::test]
async fn the_lists_hide_on_a_server_without_screen_moments_and_page_with_a_cursor() {
    let server = MockServer::start(
        Router::new()
            .fixed("GET", &screens_path(), Reply::json(200, json!({"data": [{"id": "01JE", "index": 1, "at_ms": 872000}]})))
            .fixed("GET", &format!("/api/clients/{ACME}/screens"), Reply::json(200, json!({"data": [], "next_cursor": null}))),
    );
    let api = client(&server);

    assert_eq!(api.session_screens(SESSION).await.unwrap()["data"][0]["index"], 1);
    api.client_screens(ACME, true, Some("abc")).await.unwrap();
    api.client_screens(ACME, false, None).await.unwrap();

    let pages = server.requests_to("GET", &format!("/api/clients/{ACME}/screens"));
    assert_eq!(common::query_value(pages[0].query(), "include_subfolders").as_deref(), Some("1"));
    assert_eq!(common::query_value(pages[0].query(), "cursor").as_deref(), Some("abc"));
    assert_eq!(common::query_value(pages[1].query(), "include_subfolders").as_deref(), Some("0"));
    assert_eq!(common::query_value(pages[1].query(), "cursor"), None);

    let old = MockServer::start(Router::new());
    assert!(client(&old).session_screens(SESSION).await.unwrap_err().is_missing_route());
    assert!(client(&old).client_screens(ACME, false, None).await.unwrap_err().is_missing_route());
}

#[tokio::test]
async fn a_screen_is_deleted() {
    let server = MockServer::start(Router::new().fixed("DELETE", &format!("{}/01JE7", screens_path()), Reply::empty(204)));
    client(&server).delete_screen(SESSION, "01JE7").await.unwrap();
    assert_eq!(server.requests()[0].method, "DELETE");
}

#[tokio::test]
async fn the_meeting_pack_asks_for_its_format_view_and_audio() {
    let server = MockServer::start(Router::new().fixed(
        "GET",
        &format!("/api/sessions/{SESSION}/pack"),
        Reply::html(200, "<html>pack</html>").header("Content-Disposition", "attachment; filename=\"Call with Acme - meeting pack.html\""),
    ));

    let (bytes, name) = client(&server).meeting_pack(SESSION, "html", Some("roman"), false).await.unwrap();

    assert_eq!(bytes, b"<html>pack</html>");
    assert_eq!(name, "Call with Acme - meeting pack.html");
    let query = server.requests()[0].query().map(str::to_string);
    assert_eq!(common::query_value(query.as_deref(), "format").as_deref(), Some("html"));
    assert_eq!(common::query_value(query.as_deref(), "view").as_deref(), Some("roman"));
    assert_eq!(common::query_value(query.as_deref(), "include_audio").as_deref(), Some("0"));

    let nothing = MockServer::start(Router::new().fixed(
        "GET",
        &format!("/api/sessions/{SESSION}/pack"),
        Reply::json(409, json!({"message": "Nothing to export.", "code": "nothing_to_export"})),
    ));
    assert_eq!(client(&nothing).meeting_pack(SESSION, "zip", None, true).await.unwrap_err().code(), Some("nothing_to_export"));
}

#[tokio::test]
async fn screen_pictures_come_only_from_this_server() {
    let server = MockServer::start(Router::new().fixed("GET", "/api/screens/01JE/thumb", Reply::empty(200).header("Content-Type", "image/webp")));
    let api = client(&server);
    let url = format!("{}/api/screens/01JE/thumb?expires=1&signature=x", server.base_url());

    let (_, mime) = api.screen_image(&url).await.unwrap();
    assert_eq!(mime, "image/webp");
    assert!(server.requests()[0].header("authorization").is_none(), "signed links are fetched without the token");
    assert!(api.screen_image("https://elsewhere.example/a.webp").await.is_err());

    assert!(same_server("https://ledger.example.com", "https://Ledger.example.com/api/screens/1"));
    assert!(!same_server("https://ledger.example.com", "https://ledger.example.com.evil.test/x"));
    assert!(!same_server("https://ledger.example.com", "https://user@ledger.example.com/x"));
    assert!(!same_server("https://ledger.example.com", "http://ledger.example.com/x"));
}
