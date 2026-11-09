//! v1.6, P1.2 and P1.4 (docs/API.md "Entitlements", "Your own transcription key", "Upgrade requests"), against a
//! scripted server: what the app reads, what it sends, the refusals in plain words, and an older server (404 on the
//! new routes) keeping today's behaviour (everything on).

mod common;

use serde_json::json;

use common::{MockServer, Reply, Router};
use desktop_lib::net::api::ApiClient;

const TOKEN: &str = "7|eld_TOKEN_PLACEHOLDER";

fn authed(server: &MockServer) -> ApiClient {
    common::init_tls();
    ApiClient::new(&server.base_url(), &[], Some(TOKEN.to_string())).expect("local client")
}

#[tokio::test]
async fn entitlements_are_read_tolerantly_and_an_older_server_has_none() {
    let server = MockServer::start(Router::new().fixed("GET", "/api/me/entitlements", Reply::json(200, json!({
        "plan": {"key": "free", "name": "Free", "badge": "  FREE  ", "color": "#64748B", "expires_at": null, "trial_minutes_left": 0},
        "features": {
            "views": {"enabled": false, "name": "Transcript views", "description": "Roman Urdu, English and Urdu", "cost": "paid", "lowest_plan": "pro"},
            "record": {"enabled": true, "cost": "free"},
            "broken": "yes"
        },
        "limits": {"monthly_minutes": {"limit": 0, "used": 0}, "devices": {"limit": 2, "used": 1}, "storage_bytes": {"limit": null, "used": 5}, "odd": 3},
        "engines": ["own_groq", "on_device", 7],
        "transcription_key": {"provider": "groq", "set": true, "last4": "3f9a"},
        "upgrade_request": {"id": "01JF", "plan": "pro", "status": "pending", "created_at": "2026-11-09T09:00:00+00:00"},
        "plans": [
            {"key": "pro", "name": "Pro", "badge": "PRO", "color": "red; background: url(x)", "price": "Rs 2,000 a month", "features": ["views", "platform_transcription"], "limits": {"monthly_minutes": 600, "devices": 5, "seats": null, "note": "x"}},
            {"name": "No key"}
        ]
    }))));
    let entitlements = authed(&server).entitlements().await.unwrap().expect("a v1.6 server");
    let plan = entitlements.plan.as_ref().unwrap();
    assert_eq!((plan.key.as_str(), plan.badge.as_deref(), plan.color.as_deref()), ("free", Some("FREE"), Some("#64748b")));
    assert!(!entitlements.features["views"].enabled);
    assert_eq!(entitlements.features["views"].lowest_plan.as_deref(), Some("pro"));
    assert!(entitlements.features["record"].enabled);
    assert!(!entitlements.features.contains_key("broken"), "a malformed feature is left out on its own");
    assert_eq!(entitlements.limits["devices"].limit, Some(2.0));
    assert_eq!(entitlements.limits["storage_bytes"].limit, None, "null is no limit");
    assert!(!entitlements.limits.contains_key("odd"));
    assert_eq!(entitlements.engines, ["own_groq", "on_device"]);
    assert_eq!(entitlements.transcription_key.as_ref().unwrap().last4.as_deref(), Some("3f9a"));
    assert_eq!(entitlements.upgrade_request.as_ref().unwrap().status, "pending");
    assert_eq!(entitlements.plans.len(), 1, "a plan without a key is not offered");
    assert_eq!(entitlements.plans[0].color, None, "only #rrggbb colours reach the page");
    assert_eq!(entitlements.plans[0].limits.get("seats"), Some(&None));
    assert!(!entitlements.plans[0].limits.contains_key("note"));
    let wire = serde_json::to_value(&entitlements).unwrap();
    assert_eq!(wire["features"]["views"]["lowest_plan"], "pro", "the page reads the server's names");

    let older = MockServer::start(Router::new());
    assert_eq!(authed(&older).entitlements().await.unwrap(), None);
}

#[tokio::test]
async fn the_own_key_is_checked_by_the_server_and_never_comes_back() {
    let server = MockServer::start(
        Router::new()
            .route("PUT", "/api/me/transcription-key", |request, _| match request.json()["key"].as_str() {
                Some(k) if k.ends_with("3f9a") => Reply::json(200, json!({"provider": "groq", "set": true, "last4": "3f9a"})).into(),
                _ => Reply::json(422, json!({"message": "Groq refused it", "code": "invalid_key"})).into(),
            })
            .fixed("DELETE", "/api/me/transcription-key", Reply::empty(204)),
    );
    let api = authed(&server);
    let state = api.set_transcription_key("groq", "gsk_abcdefghijklmnopqrstuvwxyz3f9a").await.unwrap();
    assert!(state.set);
    assert_eq!(state.last4.as_deref(), Some("3f9a"));
    let refused = api.set_transcription_key("groq", "gsk_abcdefghijklmnopqrstuvwxyz0000").await.unwrap_err();
    assert!(refused.to_string().starts_with("Groq did not accept this key."), "{refused}");
    assert!(!refused.to_string().contains("gsk_"), "the key is never in a message");
    api.delete_transcription_key().await.unwrap();
    let sent = &server.requests_to("PUT", "/api/me/transcription-key")[0];
    assert_eq!(sent.json(), json!({"provider": "groq", "key": "gsk_abcdefghijklmnopqrstuvwxyz3f9a"}));
    assert_eq!(sent.header("authorization"), Some(format!("Bearer {TOKEN}").as_str()));
}

#[tokio::test]
async fn an_upgrade_request_is_made_once_and_the_pending_one_comes_back() {
    let server = MockServer::start(
        Router::new()
            .route("POST", "/api/upgrade-requests", |request, _| match request.json()["plan"].as_str() {
                Some("pro") => Reply::json(201, json!({"id": "01JF", "plan": "pro", "status": "pending", "message": "For my team", "payment_instructions": "Bank: Example Bank, account 0000.", "created_at": "2026-11-09T09:00:00+00:00"})).into(),
                _ => Reply::json(409, json!({"message": "Pending", "code": "request_pending", "request": {"id": "01JF", "plan": "pro", "status": "pending"}})).into(),
            })
            .fixed("GET", "/api/upgrade-requests/latest", Reply::json(200, json!({"id": "01JF", "plan": "pro", "status": "declined", "decided_at": "2026-11-10T09:00:00+00:00", "reply": "Not yet"}))),
    );
    let api = authed(&server);
    let made = api.request_upgrade("pro", Some("For my team"), None).await.unwrap();
    assert_eq!((made.status.as_str(), made.payment_instructions.as_deref()), ("pending", Some("Bank: Example Bank, account 0000.")));
    let pending = api.request_upgrade("business", None, None).await.unwrap();
    assert_eq!((pending.id.as_str(), pending.plan.as_str()), ("01JF", "pro"), "409 answers with the pending one");
    let latest = api.latest_upgrade_request().await.unwrap().unwrap();
    assert_eq!((latest.status.as_str(), latest.reply.as_deref()), ("declined", Some("Not yet")));
    assert_eq!(server.requests_to("POST", "/api/upgrade-requests")[0].json(), json!({"plan": "pro", "message": "For my team"}));

    // None yet (404 not_found), or an older server: nothing to show.
    let none = MockServer::start(Router::new().fixed("GET", "/api/upgrade-requests/latest", Reply::json(404, json!({"message": "None", "code": "not_found"}))));
    assert_eq!(authed(&none).latest_upgrade_request().await.unwrap(), None);
    assert!(authed(&MockServer::start(Router::new())).request_upgrade("pro", None, None).await.unwrap_err().to_string().contains("does not take upgrade requests yet"));
}

#[tokio::test]
async fn a_feature_outside_the_plan_names_the_lowest_plan_that_has_it() {
    let server = MockServer::start(Router::new().fixed(
        "POST",
        "/api/transcripts/01JT/views",
        Reply::json(403, json!({"message": "Not in plan", "code": "feature_not_in_plan", "feature": "views", "lowest_plan": "pro"})),
    ));
    let error = authed(&server).make_transcript_views("01JT").await.unwrap_err();
    assert_eq!(error.to_string(), "Roman Urdu and the other transcript views are in the Pro plan. Open Settings → Account → Plan & usage to ask for it.");
}

#[tokio::test]
async fn the_features_keep_the_order_the_server_sends_them_in() {
    let server = MockServer::start(Router::new().fixed("GET", "/api/me/entitlements", Reply::raw_json(200, r#"{
        "features": {
            "record": {"enabled": true, "cost": "free"},
            "views": {"enabled": false, "cost": "paid", "lowest_plan": "pro"},
            "broken": 3,
            "ai_notes": {"enabled": false, "cost": "paid", "lowest_plan": "business"},
            "connector": {"enabled": false, "cost": "paid", "lowest_plan": "pro"}
        }
    }"#)));
    let entitlements = authed(&server).entitlements().await.unwrap().expect("a v1.6 server");
    assert_eq!(entitlements.features.keys().collect::<Vec<_>>(), ["record", "views", "ai_notes", "connector"], "the registry's order, not sorted");
    let wire = serde_json::to_string(&entitlements).unwrap();
    let at = |k: &str| wire.find(&format!("\"{k}\":{{")).unwrap();
    assert!(at("record") < at("views") && at("views") < at("ai_notes") && at("ai_notes") < at("connector"), "and the page gets them so: {wire}");

    let none = MockServer::start(Router::new().fixed("GET", "/api/me/entitlements", Reply::raw_json(200, r#"{"features": null}"#)));
    assert!(authed(&none).entitlements().await.unwrap().unwrap().features.is_empty());
    let list = MockServer::start(Router::new().fixed("GET", "/api/me/entitlements", Reply::raw_json(200, r#"{"features": ["views"], "engines": ["own_groq"]}"#)));
    let read = authed(&list).entitlements().await.unwrap().unwrap();
    assert!(read.features.is_empty(), "anything but an object is no features, never a failed answer");
    assert_eq!(read.engines, ["own_groq"]);
}

// ---- Promo codes (v1.6.2, docs/API.md "Promo codes") ----------------------------------------------------------------

fn promo_server() -> MockServer {
    MockServer::start(
        Router::new()
            .route("POST", "/api/promo-codes/check", |request, _| {
                let body = request.json();
                match (body["code"].as_str(), body["plan"].as_str()) {
                    (Some("SAVE50"), Some("pro")) => Reply::json(200, json!({"code": "SAVE50", "plan": "pro", "plan_name": "Pro", "percent": 50, "price": "Rs 2,999 / month", "final_price": "Rs 1,500 / month", "free": false, "months": 1})).into(),
                    (Some("SAVE50"), _) => Reply::json(422, json!({"message": "This code is for Pro.", "code": "promo_other_plan", "plan": "pro"})).into(),
                    (Some("FREEPRO"), _) => Reply::json(200, json!({"code": "FREEPRO", "plan": "pro", "plan_name": "Pro", "percent": 100, "price": "Rs 2,999 / month", "final_price": "Rs 0 / month", "free": true, "months": 3})).into(),
                    (Some("SLOWDOWN"), _) => Reply::json(429, json!({"message": "Too Many Attempts."})).into(),
                    _ => Reply::json(422, json!({"message": "This promo code is not valid.", "code": "invalid_promo_code"})).into(),
                }
            })
            .route("POST", "/api/upgrade-requests", |request, _| match request.json()["promo_code"].as_str() {
                Some("FREEPRO") => Reply::json(201, json!({"id": "01JG", "plan": "pro", "status": "approved", "promo_code": "FREEPRO", "percent": 100, "amount_due": "Rs 0 / month",
                    "approved_plan": "pro", "approved_until": "2027-02-09T12:00:00+00:00", "payment_instructions": null, "created_at": "2026-11-09T12:00:00+00:00"})).into(),
                Some("SAVE50") => Reply::json(201, json!({"id": "01JH", "plan": "pro", "status": "pending", "promo_code": "SAVE50", "percent": 50, "amount_due": "Rs 1,500 / month",
                    "payment_instructions": "Pay Rs 1,500 to Example Bank 0000.", "created_at": "2026-11-09T12:00:00+00:00"})).into(),
                _ => Reply::json(422, json!({"message": "This code is for Business.", "code": "promo_other_plan", "plan": "business"})).into(),
            }),
    )
}

#[tokio::test]
async fn a_promo_code_is_checked_for_the_plan_shown_and_its_refusals_are_answers() {
    let server = promo_server();
    let api = authed(&server);
    let valid = serde_json::to_value(api.check_promo("SAVE50", Some("pro")).await.unwrap()).unwrap();
    assert_eq!(valid["result"], "valid");
    assert_eq!(valid["check"]["final_price"], "Rs 1,500 / month");
    assert_eq!(valid["check"]["percent"], 50.0);
    assert_eq!(valid["check"]["free"], false);
    assert_eq!(server.requests_to("POST", "/api/promo-codes/check")[0].json(), json!({"code": "SAVE50", "plan": "pro"}));

    let other = serde_json::to_value(api.check_promo("SAVE50", Some("business")).await.unwrap()).unwrap();
    assert_eq!(other, json!({"result": "other_plan", "plan": "pro", "message": "This code is for Pro."}));

    let invalid = serde_json::to_value(api.check_promo("NOPE-1234", Some("pro")).await.unwrap()).unwrap();
    assert_eq!(invalid["result"], "invalid");
    assert_eq!(invalid["message"], desktop_lib::error::INVALID_PROMO_TEXT, "said plainly, in the app's words");

    let slow = api.check_promo("SLOWDOWN", Some("pro")).await.unwrap_err();
    assert!(slow.to_string().starts_with("Too many promo codes tried."), "{slow}");

    // An older server has no route: the sheet hides the field.
    let older = authed(&MockServer::start(Router::new())).check_promo("SAVE50", Some("pro")).await.unwrap();
    assert_eq!(serde_json::to_value(older).unwrap(), json!({"result": "unsupported"}));
}

#[tokio::test]
async fn a_request_carries_the_code_and_a_100_percent_code_switches_the_plan_on_at_once() {
    let server = promo_server();
    let api = authed(&server);
    let free = api.request_upgrade("pro", None, Some("FREEPRO")).await.unwrap();
    assert_eq!(free.status, "approved");
    assert_eq!((free.approved_plan.as_deref(), free.approved_until.as_deref()), (Some("pro"), Some("2027-02-09T12:00:00+00:00")));
    assert_eq!(free.payment_instructions, None, "nothing to pay");
    assert_eq!(free.percent, Some(100.0));
    assert_eq!(server.requests_to("POST", "/api/upgrade-requests")[0].json(), json!({"plan": "pro", "message": null, "promo_code": "FREEPRO"}));

    let half = api.request_upgrade("pro", Some("For my calls"), Some("SAVE50")).await.unwrap();
    assert_eq!((half.status.as_str(), half.amount_due.as_deref(), half.promo_code.as_deref()), ("pending", Some("Rs 1,500 / month"), Some("SAVE50")));

    let other = api.request_upgrade("pro", None, Some("BIZ-ONLY")).await.unwrap_err();
    assert_eq!(other.to_string(), "This code is for Business.");
}

#[test]
fn a_typed_promo_code_is_read_the_way_the_server_matches_it() {
    use desktop_lib::plan::promo_code_of;
    assert_eq!(promo_code_of("  save50 ").unwrap(), "SAVE50");
    assert_eq!(promo_code_of("eid-2026").unwrap(), "EID-2026");
    for bad in ["SAVE", "SAVE 50", "SAVE_50", "ÜBER-SAVE", "A-VERY-LONG-PROMO-CODE-2026"] {
        assert!(promo_code_of(bad).unwrap_err().to_string().starts_with("A promo code is 6 to 24"), "{bad}");
    }
}
