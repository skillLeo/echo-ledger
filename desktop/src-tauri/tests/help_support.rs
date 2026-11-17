//! Help (docs/API.md 2.0.0-d "Help") against an in-process server answering with fixtures in the server's shapes
//! (web/tests/Feature/Help/HelpTest.php): the guide, what's new for this computer's platform and a message to support,
//! with the refusals the support route sends read as plain sentences.

mod common;

use serde_json::json;

use common::{MockServer, Reply, Router, fixture, query_value};
use desktop_lib::help;
use desktop_lib::net::api::ApiClient;

fn client(server: &MockServer) -> ApiClient {
    ApiClient::new(&server.base_url(), &[], Some("7|eld_TOKEN_PLACEHOLDER".to_string())).expect("local client")
}

#[tokio::test]
async fn the_guide_lists_its_sections_and_searches_them() {
    let server = MockServer::start(Router::new().fixed("GET", "/api/help/guides", Reply::json(200, fixture("help_guides.json"))));
    let all = help::guides(&client(&server), None).await.unwrap().data;
    assert_eq!(all.len(), 2);
    assert_eq!((all[0].id.as_str(), all[0].title.as_str()), ("after-a-call", "After a call"));
    assert!(!all[0].excerpt.is_empty());

    help::guides(&client(&server), Some("  captions  ")).await.unwrap();
    help::guides(&client(&server), Some("   ")).await.unwrap();
    let requests = server.requests();
    assert_eq!(requests[0].query(), None, "no search, no q");
    assert_eq!(query_value(requests[1].query(), "q").as_deref(), Some("captions"));
    assert_eq!(requests[2].query(), None, "a blank search is no search");
    assert_eq!(requests[0].header("authorization"), Some("Bearer 7|eld_TOKEN_PLACEHOLDER"));
}

#[tokio::test]
async fn a_guide_comes_in_full_and_an_unknown_one_says_so() {
    let server = MockServer::start(
        Router::new()
            .fixed("GET", "/api/help/guides/after-a-call", Reply::json(200, fixture("help_guide.json")))
            .fixed("GET", "/api/help/guides/gone", Reply::json(404, json!({"message": "Not found.", "code": "not_found"}))),
    );
    let guide = help::guide(&client(&server), "after-a-call").await.unwrap();
    assert_eq!(guide.title, "After a call");
    assert!(guide.markdown.starts_with("## After a call"));
    assert_eq!(help::guide(&client(&server), "gone").await.unwrap_err().to_string(), help::GUIDE_GONE);
    // An id the server never makes is not asked for at all.
    assert_eq!(help::guide(&client(&server), "../config").await.unwrap_err().to_string(), help::GUIDE_GONE);
    assert_eq!(server.requests().len(), 2);
}

#[tokio::test]
async fn whats_new_is_for_this_computer_s_platform() {
    let server = MockServer::start(Router::new().fixed("GET", "/api/help/whats-new", Reply::json(200, fixture("help_whats_new.json"))));
    let releases = help::whats_new(&client(&server)).await.unwrap().data;
    assert_eq!(releases.len(), 2);
    assert_eq!(releases[0].version, "1.8.2");
    assert_eq!(releases[0].released_at.as_deref(), Some("2026-11-14T10:00:00+00:00"));
    assert_eq!(releases[1].released_at, None, "a release whose date the server does not know");
    assert!(releases[1].notes.contains('\n'));
    let platform = query_value(server.requests()[0].query(), "platform");
    assert_eq!(platform.as_deref(), Some(if cfg!(target_os = "macos") { "mac" } else { "windows" }));
}

#[tokio::test]
async fn a_message_to_support_is_sent_as_the_contract_says_and_nothing_comes_back() {
    let server = MockServer::start(Router::new().fixed("POST", "/api/support/messages", Reply::empty(202)));
    help::send(&client(&server), " Captions stop ", " After a minute. ", true).await.unwrap();
    help::send(&client(&server), "Plain", "No report.", false).await.unwrap();
    let requests = server.requests();
    assert_eq!(requests[0].json(), json!({"subject": "Captions stop", "message": "After a minute.", "include_device_report": true}));
    assert_eq!(requests[1].json()["include_device_report"], false);
    // A message that could not be sent is not asked for.
    assert_eq!(help::send(&client(&server), "", "x", false).await.unwrap_err().to_string(), "Write a subject and a message.");
    assert_eq!(server.requests().len(), 2);
}

#[tokio::test]
async fn the_support_route_s_refusals_read_as_plain_sentences() {
    let refused = |reply: Reply| async move {
        let server = MockServer::start(Router::new().fixed("POST", "/api/support/messages", reply));
        help::send(&client(&server), "Subject", "Message", false).await.unwrap_err().to_string()
    };
    // The sixth in an hour: the server answers with the sign-in lock's code (HelpTest.php).
    let limited = refused(Reply::json(429, json!({"message": "Too many attempts.", "code": "too_many_attempts"})).header("Retry-After", "1800")).await;
    assert_eq!(limited, "You have written to support several times in the last hour. You can send another message in 30 minutes.");
    let unverified = refused(Reply::json(403, json!({"message": "Verify your email.", "code": "email_not_verified"}))).await;
    assert!(unverified.starts_with("Verify your email address before writing to support"), "{unverified}");
    let busy = refused(Reply::json(503, json!({"message": "Busy.", "code": "server_busy"})).header("Retry-After", "5")).await;
    assert_eq!(busy, "The server is busy right now, and your message was not sent. Try again in 5 seconds.");
    let invalid = refused(Reply::json(422, json!({"message": "The subject may not be greater than 120 characters.", "code": "validation_failed"}))).await;
    assert_eq!(invalid, "The subject may not be greater than 120 characters.");
}
