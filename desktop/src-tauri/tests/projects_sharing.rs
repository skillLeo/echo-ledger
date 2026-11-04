//! Projects, smart folders, filing rules, sharing, request links and app links (docs/API.md, since v1.5) against an
//! in-process HTTP server: the paths, query strings and bodies the desktop sends, and how an older server that lacks a
//! route is told apart from a real refusal. The checks made before anything is sent are unit tests in `sharing.rs`,
//! `projects.rs` and `deep_link.rs`.

mod common;

use serde_json::json;

use common::{MockServer, Reply, Router};
use desktop_lib::net::api::ApiClient;

const TOKEN: &str = "7|eld_TOKEN_PLACEHOLDER";
const ACME: &str = "01JBACME000000000000000000";
const ZED: &str = "01JBZED0000000000000000000";
const SESSION: &str = "01JB8Q4C1D2E3F4G5H6J7K8M9N";

fn client(server: &MockServer) -> ApiClient {
    common::init_tls();
    ApiClient::new(&server.base_url(), &[], Some(TOKEN.to_string())).expect("local client")
}

#[tokio::test]
async fn a_project_is_deleted_and_says_what_moved() {
    let server = MockServer::start(Router::new().fixed("DELETE", &format!("/api/clients/{ACME}"), Reply::json(200, json!({"moved_sessions": 12, "moved_projects": 2}))));

    let answer = client(&server).delete_client(ACME).await.unwrap();

    assert_eq!(answer, json!({"moved_sessions": 12, "moved_projects": 2}));
    assert_eq!(server.requests()[0].method, "DELETE");
}

#[tokio::test]
async fn a_server_without_project_delete_is_told_apart_from_a_refusal() {
    // A v1.4 server has PATCH clients/{id} only: Laravel answers DELETE with 405.
    let old = MockServer::start(Router::new().fixed("DELETE", &format!("/api/clients/{ACME}"), Reply::json(405, json!({"message": "The DELETE method is not supported.", "code": "method_not_allowed"}))));
    assert!(client(&old).delete_client(ACME).await.unwrap_err().is_missing_route());

    let member = MockServer::start(Router::new().fixed("DELETE", &format!("/api/clients/{ACME}"), Reply::json(403, json!({"message": "Only an admin or the owner can delete a project.", "code": "not_allowed"}))));
    let err = client(&member).delete_client(ACME).await.unwrap_err();
    assert!(!err.is_missing_route());
    assert_eq!(err.to_string(), "Only an admin or the owner can delete a project.");
}

#[tokio::test]
async fn a_subfolder_is_created_and_a_project_moved_with_parent_id() {
    let server = MockServer::start(
        Router::new()
            .fixed("POST", "/api/clients", Reply::json(201, json!({"data": {"id": ZED, "name": "Invoices", "parent_id": ACME, "path": [{"id": ACME, "name": "Acme"}]}})))
            .fixed("PATCH", &format!("/api/clients/{ZED}"), Reply::json(422, json!({"message": "The parent is invalid.", "code": "too_deep"}))),
    );
    let api = client(&server);

    let body = json!({"name": "Invoices", "color": null, "avatar": null, "parent_id": ACME});
    assert_eq!(api.create_client(&body).await.unwrap()["data"]["path"][0]["name"], "Acme");
    let err = api.update_client(ZED, &json!({"parent_id": ACME})).await.unwrap_err();
    assert_eq!(err.to_string(), "Projects can be nested 4 levels deep at most. Pick a folder higher up.");
    assert_eq!(server.requests()[1].json(), json!({"parent_id": ACME}));
}

#[tokio::test]
async fn smart_folders_are_read_page_by_page_and_counted_in_the_views() {
    let server = MockServer::start(
        Router::new()
            .route("GET", "/api/library", |request, _| {
                let cursor = common::query_value(request.query(), "cursor");
                let page = match cursor.as_deref() {
                    None => json!({"data": [{"id": "a", "updated_at": "2026-11-04T09:00:00+00:00", "client": null}], "next_cursor": "c2", "server_time": "2026-11-04T10:00:00+00:00"}),
                    _ => json!({"data": [{"id": "b", "updated_at": "2026-11-04T09:00:00+00:00", "client": null}], "next_cursor": null, "server_time": "2026-11-04T10:00:00+00:00"}),
                };
                Reply::json(200, page).into()
            })
            .fixed("GET", "/api/library/views", Reply::json(200, json!({"views": {"today": 2}, "labels": [], "folders": {"inbox": 4, "zoom": 1, "shared": 0}}))),
    );
    let api = client(&server);

    let first = api.library_folder("inbox", None).await.unwrap();
    let second = api.library_folder("inbox", first.next_cursor.as_deref()).await.unwrap();
    assert_eq!((first.data[0].id.as_str(), second.data[0].id.as_str()), ("a", "b"));
    let query = server.requests()[0].query().unwrap_or_default().to_string();
    assert_eq!(common::query_value(Some(&query), "folder").as_deref(), Some("inbox"));
    assert_eq!(common::query_value(Some(&query), "limit").as_deref(), Some("100"));
    assert_eq!(common::query_value(server.requests()[1].query(), "cursor").as_deref(), Some("c2"));

    let views = api.library_views(Some("Asia/Karachi")).await.unwrap();
    assert_eq!(views["folders"]["inbox"], 4);
    assert_eq!(common::query_value(server.requests()[2].query(), "tz").as_deref(), Some("Asia/Karachi"));
}

#[tokio::test]
async fn filing_rules_are_listed_added_and_removed() {
    let server = MockServer::start(
        Router::new()
            .fixed("GET", &format!("/api/clients/{ACME}/rules"), Reply::json(200, json!({"data": [{"id": 7, "kind": "title", "value": "Acme", "created_at": "2026-11-04T09:00:00+00:00"}]})))
            .fixed("POST", &format!("/api/clients/{ACME}/rules"), Reply::json(201, json!({"data": {"id": 8, "kind": "app", "value": "zoom", "created_at": "2026-11-04T09:00:00+00:00"}})))
            .fixed("DELETE", &format!("/api/clients/{ACME}/rules/7"), Reply::empty(204)),
    );
    let api = client(&server);

    assert_eq!(api.client_rules(ACME).await.unwrap()["data"][0]["value"], "Acme");
    assert_eq!(api.add_client_rule(ACME, "app", "zoom").await.unwrap()["data"]["id"], 8);
    api.delete_client_rule(ACME, "7").await.unwrap();
    assert_eq!(server.requests()[1].json(), json!({"kind": "app", "value": "zoom"}));
}

#[tokio::test]
async fn a_rule_another_project_has_is_refused_naming_that_project() {
    let server = MockServer::start(
        Router::new()
            .fixed("POST", &format!("/api/clients/{ACME}/rules"), Reply::json(409, json!({"message": "Duplicate rule.", "code": "rule_exists"})))
            .fixed("GET", "/api/clients", Reply::json(200, json!({"data": [{"id": ACME, "name": "Acme"}, {"id": ZED, "name": "Zed Logistics"}]})))
            .fixed("GET", &format!("/api/clients/{ZED}/rules"), Reply::json(200, json!({"data": [{"id": 3, "kind": "title", "value": "weekly sync"}]}))),
    );
    let api = client(&server);

    let err = api.add_client_rule(ACME, "title", "Weekly Sync").await.unwrap_err();
    assert_eq!(err.code(), Some("rule_exists"));
    let text = desktop_lib::projects::rule_exists_text(&api, ACME, "title", "Weekly Sync", "Duplicate rule.").await;
    assert_eq!(text, "Zed Logistics already has this rule. Remove it there first, or file these recordings by something else.");
    assert!(server.requests_to("GET", &format!("/api/clients/{ACME}/rules")).is_empty(), "the project itself is not searched");

    let unknown = desktop_lib::projects::rule_exists_text(&api, ACME, "app", "zoom", "Duplicate rule.").await;
    assert!(unknown.starts_with("Another project already has this rule."), "{unknown}");
}

#[tokio::test]
async fn the_project_the_server_names_is_taken_without_reading_every_project() {
    // The server names the project, also when the number is only another project's contact (no rule to find).
    let server = MockServer::start(Router::new().fixed("GET", "/api/clients", Reply::json(200, json!({"data": [{"id": ACME, "name": "Acme"}, {"id": ZED, "name": "Zed Logistics"}]}))));
    let api = client(&server);

    let text = desktop_lib::projects::rule_exists_text(&api, ACME, "contact", "+92 300 1234567", "“Zed Logistics” already has this rule.").await;

    assert_eq!(text, "Zed Logistics already has this rule. Remove it there first, or file these recordings by something else.");
    assert!(server.requests().is_empty(), "no project was read");
}

#[tokio::test]
async fn a_project_share_goes_to_clients_and_the_list_is_narrowed() {
    let server = MockServer::start(
        Router::new()
            .fixed("POST", &format!("/api/clients/{ACME}/shares"), Reply::json(201, json!({"id": "sh1", "kind": "project", "client_id": ACME, "session_id": null, "audience": "people", "people": ["ana@example.com"], "allow_download": false, "url": "https://ledger.example.test/p/tok"})))
            .fixed("GET", "/api/shares", Reply::json(200, json!({"data": [{"id": "sh1", "kind": "project", "view_count": 3}]}))),
    );
    let api = client(&server);
    let body = json!({"audience": "people", "people": ["ana@example.com"], "allow_download": false});

    let link = api.create_link_share("clients", ACME, &body).await.unwrap();
    assert_eq!(link["url"], "https://ledger.example.test/p/tok", "the answer is not wrapped in data");
    assert_eq!(server.requests()[0].json(), body);
    assert_eq!(api.shares(None, Some(ACME)).await.unwrap()["data"][0]["view_count"], 3);
    assert_eq!(common::query_value(server.requests()[1].query(), "client_id").as_deref(), Some(ACME));
    assert_eq!(common::query_value(server.requests()[1].query(), "session_id"), None);
}

#[tokio::test]
async fn an_old_server_has_no_share_list_route() {
    let server = MockServer::start(Router::new());
    assert!(client(&server).shares(Some(SESSION), None).await.unwrap_err().is_missing_route());
}

#[tokio::test]
async fn a_recording_is_sent_to_a_teammate_and_a_missing_account_keeps_its_code() {
    let server = MockServer::start(
        Router::new()
            .fixed("POST", &format!("/api/sessions/{SESSION}/send"), Reply::json(201, json!({"sent": 1})))
            .fixed("POST", &format!("/api/clients/{ACME}/send"), Reply::json(404, json!({"message": "No account for bo@x.org.", "code": "recipient_not_found"}))),
    );
    let api = client(&server);
    let body = json!({"to": ["ana@example.com"], "note": "for tomorrow"});

    assert_eq!(api.send_to("sessions", SESSION, &body).await.unwrap()["sent"], 1);
    assert_eq!(server.requests()[0].json(), body);
    let err = api.send_to("clients", ACME, &json!({"to": ["bo@x.org"], "note": null})).await.unwrap_err();
    assert_eq!(err.code(), Some("recipient_not_found"));
    assert!(!err.is_missing_route());
}

#[tokio::test]
async fn shared_with_me_is_listed_saved_and_removed() {
    let server = MockServer::start(
        Router::new()
            .fixed("GET", "/api/shared-with-me", Reply::json(200, json!({"data": [{"id": "01JDSHARE", "kind": "session", "from": {"name": "Hassam", "email": "h@example.com"}, "title": "Call with Omar"}]})))
            .fixed("POST", "/api/shared-with-me/01JDSHARE/save", Reply::json(201, json!({"session_id": SESSION})))
            .fixed("DELETE", "/api/shared-with-me/01JDSHARE", Reply::empty(204)),
    );
    let api = client(&server);

    assert_eq!(api.shared_with_me().await.unwrap()["data"][0]["from"]["name"], "Hassam");
    assert_eq!(api.save_shared("01JDSHARE").await.unwrap()["session_id"], SESSION);
    api.remove_shared("01JDSHARE").await.unwrap();
}

#[tokio::test]
async fn request_links_are_made_listed_and_revoked() {
    let made = json!({"data": {"id": "rq1", "url": "https://ledger.example.test/r/tok", "title": "Send me your voice notes", "client_id": null, "received": 0, "revoked_at": null}});
    let server = MockServer::start(
        Router::new()
            .fixed("POST", "/api/request-links", Reply::json(201, made.clone()))
            .fixed("GET", "/api/request-links", Reply::json(200, json!({"data": [{"id": "rq1", "received": 4}]})))
            .fixed("DELETE", "/api/request-links/rq1", Reply::empty(204)),
    );
    let api = client(&server);
    let body = json!({"title": "Send me your voice notes", "client_id": null, "expires_at": null, "max_files": 20, "max_mb": 100});

    assert_eq!(api.create_request_link(&body).await.unwrap(), made);
    assert_eq!(server.requests()[0].json(), body);
    assert_eq!(api.request_links().await.unwrap()["data"][0]["received"], 4);
    api.revoke_request_link("rq1").await.unwrap();
}

#[tokio::test]
async fn a_link_is_resolved_with_the_url_in_the_query() {
    let server = MockServer::start(Router::new().fixed("GET", "/api/links/resolve", Reply::json(200, json!({"kind": "session", "session_id": SESSION}))));
    let link = format!("{}/s/AbC123", server.base_url());

    let answer = client(&server).resolve_link(&link).await.unwrap();

    assert_eq!(answer, json!({"kind": "session", "session_id": SESSION}));
    assert_eq!(common::query_value(server.requests()[0].query(), "url").as_deref(), Some(link.as_str()));
}

#[tokio::test]
async fn a_link_that_is_not_the_caller_s_resolves_to_not_found() {
    let server = MockServer::start(Router::new().fixed("GET", "/api/links/resolve", Reply::json(404, json!({"message": "Not found.", "code": "not_found"}))));
    let err = client(&server).resolve_link(&format!("{}/p/tok", server.base_url())).await.unwrap_err();
    assert_eq!(err.status(), Some(404));
}
