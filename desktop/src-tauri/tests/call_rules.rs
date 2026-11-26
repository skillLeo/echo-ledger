//! Call rules (contract 2.0.0-w): the routes' requests with the contract's samples, the config's `call_rules`, the
//! flag, and the first sync both ways (the account's choice taken; this computer's sent only when the account has none,
//! right after reading it).

mod common;

use serde_json::{Value, json};

use common::{MockServer, Reply, Router};
use desktop_lib::call_rules::{self, CallAfter, Ended, Policy, Reread, RuleChanged, Synced, device_choice, flag_is_off, read_with_first_sync};
use desktop_lib::net::api::ApiClient;
use desktop_lib::net::models::{CallRule, CallRules, ServerConfig};
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

#[tokio::test]
async fn locked_the_tray_s_switch_changes_nothing_on_the_account() {
    // While EchoLedger is locked, the account's After each call is never changed from the tray: nothing is sent.
    let server = MockServer::start(Router::new().fixed("PUT", "/api/call-rules/settings", Reply::json(200, sample(json!("ask")))));
    let api = client(&server);
    let locked = call_rules::set_records_by_themselves(&api, true, false, None).await;
    assert_eq!(locked.unwrap_err().to_string(), "EchoLedger is locked.");
    assert!(call_rules::set_records_by_themselves(&api, true, true, Some("always_transcribe")).await.is_err());
    assert!(server.requests().is_empty(), "no PUT while locked");
    // Unlocked: off is Don't record; on again is the account's last choice that records, else Ask me.
    call_rules::set_records_by_themselves(&api, false, false, Some("always_transcribe")).await.unwrap();
    call_rules::set_records_by_themselves(&api, false, true, Some("always_transcribe")).await.unwrap();
    call_rules::set_records_by_themselves(&api, false, true, None).await.unwrap();
    call_rules::set_records_by_themselves(&api, false, true, Some("dont_record")).await.unwrap();
    let sent: Vec<Value> = server.requests_to("PUT", "/api/call-rules/settings").iter().map(|r| r.json()).collect();
    assert_eq!(sent, [json!({"after_call": "dont_record"}), json!({"after_call": "always_transcribe"}), json!({"after_call": "ask"}), json!({"after_call": "ask"})]);
    // Every way in checks the lock first: "Auto-record calls & meetings" (the tray, the Record and Live menus, Home, the
    // Mac menu bar: one core function, lead decision 29), the card's "Always do this?", and each command the window and
    // its menus use.
    let source = include_str!("../src/call_rules.rs");
    let body = |source: &'static str, start: &str| {
        let from = &source[source.find(start).unwrap_or_else(|| panic!("{start}"))..];
        &from[..from.find("\n}\n").unwrap()]
    };
    let auto = body(source, "pub async fn set_auto_record(");
    assert!(auto.find("ensure_unlocked(&state)?").unwrap() < auto.find("set_records_by_themselves(").unwrap(), "{auto}");
    assert!(body(source, "pub async fn toggle_records_by_themselves(").contains("set_auto_record(app, !now)"));
    let after = body(source, "pub async fn set_after_call(");
    assert!(after.find("ensure_unlocked(&state)?").unwrap() < after.find("put_call_rule_settings(").unwrap(), "{after}");
    let commands = include_str!("../src/commands.rs");
    assert!(body(commands, "pub async fn auto_record_set(").contains("call_rules::set_auto_record(&app, on)"));
    for (file, source, call) in [
        ("tray.rs", include_str!("../src/tray.rs"), "toggle_records_by_themselves("),
        ("native_menu.rs", include_str!("../src/native_menu.rs"), "set_auto_record("),
        ("readiness.rs", include_str!("../src/readiness.rs"), "set_auto_record("),
    ] {
        assert!(source.contains(call), "{file}");
    }
    for command in ["pub async fn call_rules_get(", "pub async fn call_rules_settings_set(", "pub async fn call_rule_save(", "pub async fn call_rule_update(", "pub async fn call_rule_delete("] {
        let body = &commands[commands.find(command).unwrap_or_else(|| panic!("{command}"))..];
        let body = &body[..body.find("\n}\n").unwrap()];
        assert!(body.contains("ensure_unlocked(&state)?"), "{command}");
    }
}

fn kept_rule(id: &str, kind: &str, name: Option<&str>, source_app: Option<&str>, action: &str) -> CallRule {
    CallRule { id: id.into(), kind: kind.into(), name: name.map(str::to_string), source_app: source_app.map(str::to_string), action: action.into(), ..CallRule::default() }
}

#[test]
fn only_a_contact_s_dont_record_deletes_a_recording_and_only_one_recorded_by_itself() {
    // Lead decisions 25, 28 and 29, as the end of a call meets them.
    let mine = Settings { auto_mode: true, after_call: AfterCall::AlwaysTranscribe, ask_when: AskWhen::After, skip_calls_shorter_than_s: 0, ..Settings::default() };
    let account = |after_call: &str, rules: Vec<CallRule>| {
        Policy::account(CallRules { after_call: Some(after_call.into()), ask_when: Some("at_start".into()), short_calls: Some("dont_keep".into()), rules, ..CallRules::default() })
    };
    let ahmed_off = kept_rule("c1", "contact", Some("Ahmed Khan"), None, "dont_record");
    let ended = |caller: Option<&'static str>, auto: bool, chip: Option<&'static str>, pill: bool| Ended {
        caller_name: caller,
        source_app: "zoom",
        is_call: true,
        auto,
        rules_apply: call_rules::rules_apply(auto, chip, "rec-1"),
        pill_chosen: pill,
    };

    // Recorded by itself, and the window named Ahmed: it goes.
    let rules = account("always_audio", vec![ahmed_off.clone()]);
    let end = rules.end_of_call(&mine, &ended(Some("  ahmed   KHAN "), true, None, false));
    assert!(end.drops);
    // Started by hand, or from the "Record?" chip: the person's own. Nothing deletes it, short calls don't apply, it
    // asks after it (no start banner was shown), and its card counts for nothing.
    for end in [rules.end_of_call(&mine, &ended(Some("Ahmed Khan"), false, None, false)), rules.end_of_call(&mine, &ended(Some("Ahmed Khan"), true, Some("rec-1"), false))] {
        assert!(!end.drops && !end.by_itself);
        assert_eq!((end.setting, end.ask_when), (AfterCall::Ask, AskWhen::After));
    }
    // The chip of another recording changes nothing for this one.
    assert!(rules.end_of_call(&mine, &ended(Some("Ahmed Khan"), true, Some("rec-0"), false)).drops);
    // A choice made on the pill during the call stands.
    assert!(!rules.end_of_call(&mine, &ended(Some("Ahmed Khan"), true, None, true)).drops);
    assert!(!rules.stops_for_caller("Ahmed Khan", true, true));

    // The default, or Zoom's rule, turned to Don't record during the call (the tray, Settings, the phone): decided at
    // the start only, so the recording already made ends as Ask me, never deleted.
    for changed in [account("dont_record", vec![]), account("ask", vec![kept_rule("s1", "source", None, Some("zoom"), "dont_record")])] {
        let end = changed.end_of_call(&mine, &ended(Some("Sara"), true, None, false));
        assert!(!end.drops && end.by_itself);
        assert_eq!((end.setting, end.ask_when), (AfterCall::Ask, AskWhen::Start));
        assert!(!changed.stops_for_caller("Sara", true, false));
        assert_eq!(changed.after(&mine, None, "zoom", true), CallAfter::Choose(AfterCall::Ask));
        assert!(changed.blocks_at_start("zoom"), "a call that starts now isn't recorded");
    }
    // No "Record?" chip for an app nobody knows under Don't record calls.
    assert!(!account("dont_record", vec![]).offers_unknown_apps());

    // The flag off: this computer's own settings, as before (a voice note never drops).
    let own = Policy::default().end_of_call(&mine, &ended(Some("Ahmed Khan"), true, Some("rec-1"), false));
    assert!(!own.drops && own.by_itself);
    assert_eq!((own.setting, own.ask_when, own.short_call_seconds), (AfterCall::AlwaysTranscribe, AskWhen::After, 0));
    let note = Ended { is_call: false, ..ended(Some("Ahmed Khan"), true, None, false) };
    assert!(!rules.end_of_call(&mine, &note).drops);
}

#[tokio::test]
async fn a_sync_reads_again_only_when_the_version_moved_and_the_flag_off_clears_what_is_kept() {
    let mine = Settings::default();
    let data = tempfile::tempdir().unwrap();
    let server = MockServer::start(Router::new().fixed("GET", "/api/call-rules", Reply::json(200, sample(json!("always_audio")))));
    let api = client(&server);

    // Nothing kept: read.
    let Synced::Read(rules) = call_rules::sync_with(&api, data.path(), "sign-in-a", Some(12), &mine).await else { panic!("read") };
    assert_eq!(rules.version, Some(12));
    call_rules::save(data.path(), "sign-in-a", &rules).unwrap();
    // As new as the config says: nothing is read.
    assert_eq!(call_rules::sync_with(&api, data.path(), "sign-in-a", Some(12), &mine).await, Synced::Kept);
    assert_eq!(call_rules::sync_with(&api, data.path(), "sign-in-a", Some(11), &mine).await, Synced::Kept);
    assert_eq!(server.requests_to("GET", "/api/call-rules").len(), 1);
    // Newer: read again. Another sign-in never takes what this one kept.
    assert!(matches!(call_rules::sync_with(&api, data.path(), "sign-in-a", Some(13), &mine).await, Synced::Read(_)));
    assert!(matches!(call_rules::sync_with(&api, data.path(), "sign-in-b", Some(12), &mine).await, Synced::Read(_)));
    assert_eq!(server.requests_to("GET", "/api/call-rules").len(), 3);

    // Offline: what is kept stays.
    let offline = ApiClient::new("http://127.0.0.1:9", &[], Some(TOKEN.to_string())).expect("local client");
    assert_eq!(call_rules::sync_with(&offline, data.path(), "sign-in-a", Some(14), &mine).await, Synced::Wait);
    assert!(call_rules::load(data.path(), "sign-in-a").is_some());

    // The config says the flag is off: what was kept goes, once.
    assert_eq!(call_rules::sync_with(&api, data.path(), "sign-in-a", None, &mine).await, Synced::Off { forgot: true });
    assert!(call_rules::load(data.path(), "sign-in-a").is_none() && !call_rules::path(data.path()).exists());
    assert_eq!(call_rules::sync_with(&api, data.path(), "sign-in-a", None, &mine).await, Synced::Off { forgot: false });
    // The route answers 404 not_found: the same.
    call_rules::save(data.path(), "sign-in-a", &rules).unwrap();
    let off = MockServer::start(Router::new().fixed("GET", "/api/call-rules", Reply::json(404, json!({"code": "not_found", "message": "Not found."}))));
    assert_eq!(call_rules::sync_with(&client(&off), data.path(), "sign-in-a", Some(13), &mine).await, Synced::Off { forgot: true });
    assert!(call_rules::load(data.path(), "sign-in-a").is_none());
}

#[tokio::test]
async fn a_rule_another_device_removed_is_read_again() {
    let gone = Reply::json(404, json!({"message": "No such rule.", "code": "rule_not_found"}));
    let server = MockServer::start(
        Router::new()
            .fixed("PATCH", "/api/call-rules/gone", gone.clone())
            .fixed("DELETE", "/api/call-rules/gone", gone)
            .fixed("PATCH", "/api/call-rules/01JC9", Reply::json(200, rule("01JC9")))
            .fixed("DELETE", "/api/call-rules/01JC9", Reply::empty(204))
            .fixed("GET", "/api/call-rules", Reply::json(200, sample(json!("ask")))),
    );
    let api = client(&server);
    // Changed while gone: the account's answer is read again, and the change says so.
    let RuleChanged::Gone(Reread::Read(read)) = call_rules::update_rule(&api, "gone", &json!({"action": "ask"})).await.unwrap() else { panic!("read again") };
    assert_eq!(read.version, Some(12));
    // Removed while gone: done all the same, and read again.
    assert!(matches!(call_rules::delete_rule(&api, "gone").await.unwrap(), RuleChanged::Removed { reread: Some(Reread::Read(_)) }));
    let order: Vec<String> = server.requests().iter().map(|r| format!("{} {}", r.method, r.path())).collect();
    assert_eq!(order, ["PATCH /api/call-rules/gone", "GET /api/call-rules", "DELETE /api/call-rules/gone", "GET /api/call-rules"]);
    // There: saved or removed, nothing read again.
    assert!(matches!(call_rules::update_rule(&api, "01JC9", &json!({"action": "ask"})).await.unwrap(), RuleChanged::Saved(r) if r.id == "01JC9"));
    assert!(matches!(call_rules::delete_rule(&api, "01JC9").await.unwrap(), RuleChanged::Removed { reread: None }));
    assert_eq!(server.requests_to("GET", "/api/call-rules").len(), 2);
    // The flag off during it: the read again says so.
    let off = MockServer::start(
        Router::new()
            .fixed("PATCH", "/api/call-rules/gone", Reply::json(404, json!({"message": "No such rule.", "code": "rule_not_found"})))
            .fixed("GET", "/api/call-rules", Reply::json(404, json!({"code": "not_found", "message": "Not found."}))),
    );
    assert!(matches!(call_rules::update_rule(&client(&off), "gone", &json!({"action": "ask"})).await.unwrap(), RuleChanged::Gone(Reread::Off)));
}
