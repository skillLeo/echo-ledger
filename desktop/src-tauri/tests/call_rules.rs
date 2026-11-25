//! Call rules (contract 2.0.0-w): the routes' requests with the contract's samples, the config's `call_rules`, the
//! flag, and the first sync both ways (the account's choice taken; this computer's sent only when the account has none,
//! right after reading it).

mod common;

use serde_json::{Value, json};

use common::{MockServer, Reply, Router};
use desktop_lib::call_rules::{self, device_choice, flag_is_off, read_with_first_sync};
use desktop_lib::net::api::ApiClient;
use desktop_lib::net::models::{CallRules, ServerConfig};
use desktop_lib::settings::{AfterCall, AskWhen, Settings};

const TOKEN: &str = "7|eld_TOKEN_PLACEHOLDER";

fn client(server: &MockServer) -> ApiClient {
    common::init_tls();
    ApiClient::new(&server.base_url(), &[], Some(TOKEN.to_string())).expect("local client")
}

/// docs/API.md "GET /api/call-rules", as the contract shows it.
fn sample(after_call: Value) -> Value {
    json!({"after_call": after_call, "ask_when": "after_call", "short_calls": "dont_keep", "short_call_seconds": 30,
        "version": 12, "updated_at": "2026-11-25T09:00:00Z",
        "rules": [{"id": "01JC1", "kind": "contact", "number": "+923001234567", "name": "Ahmed Khan", "action": "always_transcribe",
                   "created_at": "2026-11-25T09:00:00Z", "updated_at": "2026-11-25T09:00:00Z"},
                  {"id": "01JC2", "kind": "group", "group": "favourites", "action": "dont_record"},
                  {"id": "01JC3", "kind": "source", "source_app": "zoom", "action": "always_transcribe"},
                  {"kind": "source", "source_app": "teams"}]})
}

fn rule(id: &str) -> Value {
    json!({"id": id, "kind": "source", "source_app": "zoom", "action": "always_transcribe", "created_at": "…", "updated_at": "…"})
}

#[tokio::test]
async fn each_route_sends_what_the_contract_shows() {
    let server = MockServer::start(
        Router::new()
            .fixed("GET", "/api/call-rules", Reply::json(200, sample(json!("always_audio"))))
            .fixed("PUT", "/api/call-rules/settings", Reply::json(200, sample(json!("ask"))))
            .route("POST", "/api/call-rules", |_, nth| Reply::json(if nth == 0 { 201 } else { 200 }, rule("01JC9")).into())
            .fixed("PATCH", "/api/call-rules/01JC9", Reply::json(200, rule("01JC9")))
            .fixed("DELETE", "/api/call-rules/01JC9", Reply::empty(204)),
    );
    let api = client(&server);
    let read = api.call_rules().await.unwrap();
    assert_eq!(read.after_call.as_deref(), Some("always_audio"));
    assert_eq!(read.version, Some(12));
    assert_eq!(read.rules.len(), 3, "a rule that can't be read is left out, the rest kept");
    assert_eq!((read.rules[1].kind.as_str(), read.rules[1].group.as_deref()), ("group", Some("favourites")));

    api.put_call_rule_settings(&json!({"after_call": "always_audio", "ask_when": "at_start", "short_calls": "keep"})).await.unwrap();
    assert_eq!(server.requests_to("PUT", "/api/call-rules/settings")[0].json(), json!({"after_call": "always_audio", "ask_when": "at_start", "short_calls": "keep"}));

    // 201 for a new rule, 200 when it replaced the same Source's: both are the rule.
    let new = json!({"kind": "source", "source_app": "zoom", "action": "always_transcribe"});
    assert_eq!(api.create_call_rule(&new).await.unwrap().id, "01JC9");
    assert_eq!(api.create_call_rule(&new).await.unwrap().id, "01JC9");
    assert_eq!(server.requests_to("POST", "/api/call-rules")[0].json(), new);
    let contact = json!({"kind": "contact", "name": "Ahmed Khan", "action": "dont_record"});
    let _ = api.create_call_rule(&contact).await;
    assert_eq!(server.requests_to("POST", "/api/call-rules")[2].json(), contact);

    api.update_call_rule("01JC9", &json!({"action": "dont_record"})).await.unwrap();
    assert_eq!(server.requests_to("PATCH", "/api/call-rules/01JC9")[0].json(), json!({"action": "dont_record"}));
    api.delete_call_rule("01JC9").await.unwrap();
    assert_eq!(server.requests_to("DELETE", "/api/call-rules/01JC9").len(), 1);
}

#[tokio::test]
async fn the_refusals_read_as_the_contract_says() {
    let server = MockServer::start(
        Router::new()
            .fixed("POST", "/api/call-rules", Reply::json(409, json!({"message": "Too many rules.", "code": "too_many_rules"})))
            .fixed("PATCH", "/api/call-rules/gone", Reply::json(404, json!({"message": "No such rule.", "code": "rule_not_found"})))
            .fixed("GET", "/api/call-rules", Reply::json(404, json!({"message": "Not found.", "code": "not_found"}))),
    );
    let api = client(&server);
    let full = api.create_call_rule(&json!({"kind": "source", "source_app": "zoom", "action": "ask"})).await.unwrap_err();
    assert_eq!(call_rules::change_error(full).to_string(), "You have 500 call rules, the most there can be. Remove one first.");
    let gone = api.update_call_rule("gone", &json!({"action": "ask"})).await.unwrap_err();
    assert!(!flag_is_off(&gone), "another device removed it: not the flag");
    let off = api.call_rules().await.unwrap_err();
    assert!(flag_is_off(&off), "a route that is not there: the flag is off");
}

#[tokio::test]
async fn the_first_sync_takes_the_account_s_choice_and_sends_this_computer_s_only_when_it_has_none() {
    let mine = Settings { auto_mode: true, after_call: AfterCall::AlwaysTranscribe, ask_when: AskWhen::After, skip_calls_shorter_than_s: 0, ..Settings::default() };

    // The account chose on another device: taken, nothing sent.
    let chosen = MockServer::start(Router::new().fixed("GET", "/api/call-rules", Reply::json(200, sample(json!("always_audio")))));
    let taken = read_with_first_sync(&client(&chosen), &mine).await.unwrap();
    assert_eq!(taken.after_call.as_deref(), Some("always_audio"));
    assert!(chosen.requests_to("PUT", "/api/call-rules/settings").is_empty(), "the device's own choice is dropped");

    // Never chosen: this computer's choice, sent right after that read; the server's answer is the one kept.
    let fresh = MockServer::start(
        Router::new()
            .fixed("GET", "/api/call-rules", Reply::json(200, sample(Value::Null)))
            .fixed("PUT", "/api/call-rules/settings", Reply::json(200, sample(json!("always_transcribe")))),
    );
    let sent = read_with_first_sync(&client(&fresh), &mine).await.unwrap();
    assert_eq!(sent.after_call.as_deref(), Some("always_transcribe"));
    assert_eq!(fresh.requests_to("PUT", "/api/call-rules/settings")[0].json(), device_choice(&mine));
    assert_eq!(device_choice(&mine), json!({"after_call": "always_transcribe", "ask_when": "after_call", "short_calls": "keep"}));
    let order: Vec<String> = fresh.requests().iter().map(|r| format!("{} {}", r.method, r.path())).collect();
    assert_eq!(order, ["GET /api/call-rules", "PUT /api/call-rules/settings"]);

    // Offline: nothing is sent, now or later from memory.
    let offline = ApiClient::new("http://127.0.0.1:9", &[], Some(TOKEN.to_string())).expect("local client");
    assert!(read_with_first_sync(&offline, &mine).await.is_err());
    // The flag off: the route is not there, and nothing is sent.
    let off = MockServer::start(Router::new().fixed("GET", "/api/call-rules", Reply::json(404, json!({"code": "not_found", "message": "Not found."}))));
    assert!(flag_is_off(&read_with_first_sync(&client(&off), &mine).await.unwrap_err()));
    assert!(off.requests_to("PUT", "/api/call-rules/settings").is_empty());
}

#[test]
fn the_config_brings_call_rules_only_while_the_flag_is_on() {
    let base = common::fixture("config.json");
    let older: ServerConfig = serde_json::from_value(base.clone()).unwrap();
    assert_eq!(older.call_rules, None, "an older server, or the flag off");
    let mut with = base.clone();
    with["call_rules"] = json!({"after_call": null, "ask_when": "after_call", "short_calls": "dont_keep", "short_call_seconds": 30, "version": 12});
    let on: ServerConfig = serde_json::from_value(with.clone()).unwrap();
    let rules = on.call_rules.unwrap();
    assert_eq!((rules.after_call, rules.version, rules.short_call_seconds), (None, Some(12), Some(30)));
    with["call_rules"] = json!("on");
    assert_eq!(serde_json::from_value::<ServerConfig>(with).unwrap().call_rules, None, "a malformed block never breaks the config");
    let mut flags = base;
    flags["ui_flags"] = json!({"call_rules": true});
    assert!(serde_json::from_value::<ServerConfig>(flags).unwrap().ui().is_on("call_rules"), "the flag is one the app knows");
    let _: CallRules = serde_json::from_value(json!({})).unwrap();
}
