//! Call rules (contract 2.0.0-w, docs/PLAN_CALL_RULES.md; lead decision 25): the person's After each call, When to ask,
//! Short calls and their rules for a contact or a Source, kept for the whole account on the server, behind the
//! interface flag `call_rules`. While the flag is off nothing here runs and every call follows this computer's own
//! settings, as before.
//!
//! - The account's answer (`GET /api/call-rules`) is kept here for this sign-in (`call_rules.json`) and read again when
//!   the `version` in the 60-second config is newer than the one kept ([`sync`]).
//! - The first sync, when the flag turns on: the account's choice is read, and only when it is still none (the person
//!   never chose on any device) is this computer's own sent, at once after that read, never later from memory.
//! - Any call-rules route answering `404 not_found` (a route that is not there) means the flag is off again: what was
//!   kept goes, and this computer's own settings apply.

use std::path::{Path, PathBuf};

use serde::{Deserialize, Serialize};
use serde_json::json;
use tauri::{AppHandle, Emitter, Manager};

use crate::error::{AppError, AppResult};
use crate::net::models::{CallRule, CallRules};
use crate::settings::{AfterCall, AskWhen, Settings};

/// The interface flag (docs/API.md "Call rules (since 2.0.0-w)").
pub const FLAG: &str = "call_rules";
/// The event the window hears when the kept answer changes (`null`: none, the flag is off).
pub const EVENT: &str = "call_rules:changed";

/// The four choices (docs/API.md "The choices").
pub const ASK: &str = "ask";
pub const ALWAYS_TRANSCRIBE: &str = "always_transcribe";
pub const ALWAYS_AUDIO: &str = "always_audio";
pub const DONT_RECORD: &str = "dont_record";

/// What the app says when a change can't reach the server: a rule is a deliberate act, so it is never queued.
pub const OFFLINE_TEXT: &str = "This can't be saved now: EchoLedger can't reach the server. Try again when you're online.";
/// `429`: 60 changes a minute per person, across their devices.
pub const TOO_MANY_CHANGES_TEXT: &str = "That's a lot of changes at once. Try again in a minute.";
/// `404 not_found` on a call-rules route: the flag was turned off for the person.
pub const FLAG_OFF_TEXT: &str = "Call rules are no longer on for your account. This computer's own settings apply again.";
/// `404 rule_not_found` on a change: another device removed it.
pub const RULE_GONE_TEXT: &str = "That call rule is no longer there.";

pub fn path(data_dir: &Path) -> PathBuf {
    data_dir.join("call_rules.json")
}

/// What is kept: the account's answer, for the sign-in it was read in (another sign-in never reads it).
#[derive(Clone, Debug, Default, PartialEq, Serialize, Deserialize)]
pub struct Kept {
    pub sign_in: String,
    pub rules: CallRules,
}

/// The answer kept for `sign_in`, or none (none kept, another sign-in's, or a file that can't be read).
pub fn load(data_dir: &Path, sign_in: &str) -> Option<CallRules> {
    let kept: Kept = serde_json::from_slice(&std::fs::read(path(data_dir)).ok()?).ok()?;
    (kept.sign_in == sign_in).then_some(kept.rules)
}

pub fn save(data_dir: &Path, sign_in: &str, rules: &CallRules) -> AppResult<()> {
    let file = path(data_dir);
    let tmp = file.with_extension("json.tmp");
    std::fs::write(&tmp, serde_json::to_vec_pretty(&Kept { sign_in: sign_in.to_string(), rules: rules.clone() })?)?;
    std::fs::rename(tmp, file)?;
    Ok(())
}

/// Forgets what was kept (the flag is off, signed out).
pub fn clear(data_dir: &Path) {
    let _ = std::fs::remove_file(path(data_dir));
}

/// This computer's own choice, as the first sync sends it when the account has none (pure): Don't record while calls
/// aren't recorded by themselves, the card's After each call otherwise; When to ask; Short calls kept or not.
pub fn device_choice(settings: &Settings) -> serde_json::Value {
    let after_call = if !settings.auto_mode {
        DONT_RECORD
    } else {
        match settings.after_call {
            AfterCall::Ask => ASK,
            AfterCall::AlwaysTranscribe => ALWAYS_TRANSCRIBE,
            AfterCall::AlwaysAudio => ALWAYS_AUDIO,
        }
    };
    let ask_when = match settings.ask_when {
        AskWhen::After => "after_call",
        AskWhen::Start => "at_start",
    };
    let short_calls = if settings.skip_calls_shorter_than_s > 0 { "dont_keep" } else { "keep" };
    json!({ "after_call": after_call, "ask_when": ask_when, "short_calls": short_calls })
}

/// Where a kind of rule comes in the contract's order: contact, group, Source.
fn kind_order(kind: &str) -> u8 {
    match kind {
        "contact" => 0,
        "group" => 1,
        "source" => 2,
        _ => 3,
    }
}

/// What a rule is for, to put it in order and to find the one it replaces: a contact's number or name, a group, a Source.
fn subject(rule: &CallRule) -> String {
    let of = |v: &Option<String>| v.as_deref().unwrap_or_default().to_lowercase();
    match rule.kind.as_str() {
        "contact" => format!("{}\u{0}{}", of(&rule.name), of(&rule.number)),
        "group" => of(&rule.group),
        _ => of(&rule.source_app),
    }
}

/// The kept answer with `rule` as the server answered it (pure): the rule with its id replaced, else the one for the same
/// contact or Source (a second rule for it replaces the first, `200`), else added; in the contract's order. Its
/// `version` stays the one kept, so the next config reads the whole answer again.
pub fn with_rule(mut rules: CallRules, rule: CallRule) -> CallRules {
    let same_subject = |r: &CallRule| r.kind == rule.kind && r.kind != "group" && subject(r) == subject(&rule) || r.kind == "group" && rule.kind == "group" && r.group == rule.group;
    rules.rules.retain(|r| r.id != rule.id && !same_subject(r));
    rules.rules.push(rule);
    rules.rules.sort_by(|a, b| kind_order(&a.kind).cmp(&kind_order(&b.kind)).then_with(|| subject(a).cmp(&subject(b))));
    rules
}

/// The kept answer without the rule `id` (pure).
pub fn without_rule(mut rules: CallRules, id: &str) -> CallRules {
    rules.rules.retain(|r| r.id != id);
    rules
}

/// Whether an answer says the flag is off: `404 not_found`, a route that is not there (not `rule_not_found`).
pub fn flag_is_off(e: &AppError) -> bool {
    matches!(e, AppError::Server { status: 404, code, .. } if code.as_deref().is_none_or(|c| c == "not_found"))
}

/// A change's failure in the app's words: offline, too many changes, the flag turned off; the rest as the server said
/// it (`409 too_many_rules`, `422` with its field errors).
pub fn change_error(e: AppError) -> AppError {
    match &e {
        AppError::Network(_) => AppError::msg(OFFLINE_TEXT),
        AppError::Server { status: 429, .. } => AppError::msg(TOO_MANY_CHANGES_TEXT),
        _ if flag_is_off(&e) => AppError::msg(FLAG_OFF_TEXT),
        _ => e,
    }
}

/// Whether the person's flag is on, from the last config.
pub fn flag_on(state: &crate::AppState) -> bool {
    crate::ui_flags::cached(state).is_on(FLAG)
}

/// The `version` the last config says, or none (the flag off, or an older server).
fn config_version(state: &crate::AppState) -> Option<u64> {
    let config = state.server_config.lock().unwrap_or_else(|p| p.into_inner());
    config.as_ref().and_then(|c| c.call_rules.as_ref()).map(|c| c.version.unwrap_or(0))
}

/// The answer kept for this sign-in, while the flag is on.
pub fn kept(state: &crate::AppState) -> Option<CallRules> {
    if !flag_on(state) {
        return None;
    }
    load(&state.data_dir, &state.sign_in_id()?)
}

/// Keeps `rules` for this sign-in and tells the window.
pub fn keep(app: &AppHandle, rules: &CallRules) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    let Some(sign_in) = state.sign_in_id() else { return Ok(()) };
    save(&state.data_dir, &sign_in, rules)?;
    let _ = app.emit(EVENT, rules);
    Ok(())
}

/// Forgets the kept answer (the flag is off) and tells the window, when there was one.
pub fn forget(app: &AppHandle) {
    let state = app.state::<crate::AppState>();
    if path(&state.data_dir).exists() {
        clear(&state.data_dir);
        let _ = app.emit(EVENT, serde_json::Value::Null);
    }
}

/// After each config (every 60 s): with the flag off, nothing is kept; with it on, the account's answer is read again
/// when its `version` is newer than the one kept, or none is kept yet. The first sync sends this computer's choice only
/// when the answer just read still has none.
pub async fn sync(app: &AppHandle) {
    let state = app.state::<crate::AppState>();
    let Some(version) = config_version(&state).filter(|_| flag_on(&state)) else {
        forget(app);
        return;
    };
    let Some(sign_in) = state.sign_in_id() else { return };
    if load(&state.data_dir, &sign_in).is_some_and(|k| k.version.unwrap_or(0) >= version) {
        return;
    }
    let Ok(api) = state.api() else { return };
    let rules = match read_with_first_sync(&api, &state.settings()).await {
        Ok(rules) => rules,
        Err(e) if flag_is_off(&e) => return forget(app),
        // Offline or the server busy: read again at the next config; nothing is sent from memory meanwhile.
        Err(_) => return,
    };
    if let Err(e) = keep(app, &rules) {
        eprintln!("[echoledger] keeping the call rules: {e}");
    }
}

/// The account's answer now. The first sync: when it has no After each call yet (the person never chose on any
/// device), this computer's own choice is sent right after that read, and the server's answer to it is the one kept.
pub async fn read_with_first_sync(api: &crate::net::api::ApiClient, settings: &Settings) -> AppResult<CallRules> {
    let rules = api.call_rules().await?;
    if rules.after_call.is_some() {
        return Ok(rules);
    }
    api.put_call_rule_settings(&device_choice(settings)).await
}

#[cfg(test)]
mod tests {
    use super::*;

    fn rule(id: &str, kind: &str, name: Option<&str>, source: Option<&str>, action: &str) -> CallRule {
        CallRule {
            id: id.into(),
            kind: kind.into(),
            name: name.map(str::to_string),
            source_app: source.map(str::to_string),
            group: (kind == "group").then(|| "favourites".into()),
            action: action.into(),
            ..CallRule::default()
        }
    }

    #[test]
    fn this_computer_s_choice_is_what_the_first_sync_sends() {
        let mut s = Settings { auto_mode: true, after_call: AfterCall::AlwaysAudio, ask_when: AskWhen::Start, skip_calls_shorter_than_s: 30, ..Settings::default() };
        assert_eq!(device_choice(&s), json!({"after_call": "always_audio", "ask_when": "at_start", "short_calls": "dont_keep"}));
        s.auto_mode = false;
        s.skip_calls_shorter_than_s = 0;
        assert_eq!(device_choice(&s), json!({"after_call": "dont_record", "ask_when": "at_start", "short_calls": "keep"}));
    }

    #[test]
    fn a_rule_from_the_server_replaces_its_own_or_the_same_contact_s_and_keeps_the_contract_s_order() {
        let kept = CallRules {
            version: Some(4),
            rules: vec![rule("c1", "contact", Some("Ahmed Khan"), None, ASK), rule("s1", "source", None, Some("zoom"), DONT_RECORD)],
            ..CallRules::default()
        };
        // A Source rule, new; then the same contact again (the server answered 200: it replaced Ahmed's).
        let more = with_rule(kept, rule("s2", "source", None, Some("google_meet"), ALWAYS_AUDIO));
        let more = with_rule(more, rule("c9", "contact", Some("ahmed khan"), None, ALWAYS_TRANSCRIBE));
        let ids: Vec<&str> = more.rules.iter().map(|r| r.id.as_str()).collect();
        assert_eq!(ids, ["c9", "s2", "s1"], "contact first, then the Sources by name");
        assert_eq!(more.version, Some(4), "read again at the next config");
        let changed = with_rule(more, rule("s1", "source", None, Some("zoom"), ALWAYS_TRANSCRIBE));
        assert_eq!(changed.rules.iter().find(|r| r.id == "s1").unwrap().action, ALWAYS_TRANSCRIBE);
        assert_eq!(without_rule(changed, "s1").rules.len(), 2);
    }

    #[test]
    fn the_flag_is_off_only_for_a_route_that_is_not_there() {
        let gone = |code: Option<&str>| AppError::Server { status: 404, message: String::new(), code: code.map(str::to_string), retry_after_secs: None };
        assert!(flag_is_off(&gone(Some("not_found"))));
        assert!(flag_is_off(&gone(None)));
        assert!(!flag_is_off(&gone(Some("rule_not_found"))));
        assert_eq!(change_error(AppError::Network("down".into())).to_string(), OFFLINE_TEXT);
        let busy = AppError::Server { status: 429, message: String::new(), code: None, retry_after_secs: Some(30) };
        assert_eq!(change_error(busy).to_string(), TOO_MANY_CHANGES_TEXT);
        assert_eq!(change_error(gone(Some("not_found"))).to_string(), FLAG_OFF_TEXT);
    }

    #[test]
    fn what_is_kept_is_this_sign_in_s_only() {
        let data = tempfile::tempdir().unwrap();
        let rules = CallRules { after_call: Some(ALWAYS_AUDIO.into()), version: Some(2), ..CallRules::default() };
        save(data.path(), "sign-in-a", &rules).unwrap();
        assert_eq!(load(data.path(), "sign-in-a"), Some(rules));
        assert_eq!(load(data.path(), "sign-in-b"), None, "another sign-in never reads it");
        clear(data.path());
        assert_eq!(load(data.path(), "sign-in-a"), None);
    }
}
