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
use crate::settings::{AfterCall, AfterEachCall, AskWhen, Settings};

/// The interface flag (docs/API.md "Call rules (since 2.0.0-w)").
pub const FLAG: &str = "call_rules";
/// The event the window hears when the kept answer changes (`null`: none, the flag is off).
pub const EVENT: &str = "call_rules:changed";

/// The four choices (docs/API.md "The choices").
pub const ASK: &str = "ask";
pub const ALWAYS_TRANSCRIBE: &str = "always_transcribe";
pub const ALWAYS_AUDIO: &str = "always_audio";
pub const DONT_RECORD: &str = "dont_record";

/// Short calls (`short_calls: dont_keep`): shorter than this many seconds, a call isn't kept. The server's, fixed.
pub const SHORT_CALL_SECONDS: u32 = 30;

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

/// The one notice when a call rule stopped a recording (decision 25: never "not recorded", as it was, for a moment).
pub fn stopped_words(name: Option<&str>) -> String {
    match name.map(str::trim).filter(|n| !n.is_empty()) {
        Some(name) => format!("Stopped and deleted the recording: your call rule for {name} says Don't record."),
        None => "Stopped and deleted the recording: your call rules say Don't record for this call.".to_string(),
    }
}

/// The file's answer in memory, by its path: the detection reads it every second, the disk only when it changed.
static MEMORY: std::sync::Mutex<Option<(PathBuf, Option<Kept>)>> = std::sync::Mutex::new(None);

fn remember(file: &Path, kept: Option<Kept>) {
    *MEMORY.lock().unwrap_or_else(|p| p.into_inner()) = Some((file.to_path_buf(), kept));
}

/// The answer kept for `sign_in`, or none (none kept, another sign-in's, or a file that can't be read).
pub fn load(data_dir: &Path, sign_in: &str) -> Option<CallRules> {
    let file = path(data_dir);
    let known = MEMORY.lock().unwrap_or_else(|p| p.into_inner()).as_ref().filter(|(at, _)| *at == file).map(|(_, kept)| kept.clone());
    let kept = match known {
        Some(kept) => kept,
        None => {
            let read: Option<Kept> = std::fs::read(&file).ok().and_then(|b| serde_json::from_slice(&b).ok());
            remember(&file, read.clone());
            read
        }
    };
    kept.filter(|k| k.sign_in == sign_in).map(|k| k.rules)
}

pub fn save(data_dir: &Path, sign_in: &str, rules: &CallRules) -> AppResult<()> {
    let file = path(data_dir);
    let tmp = file.with_extension("json.tmp");
    let kept = Kept { sign_in: sign_in.to_string(), rules: rules.clone() };
    std::fs::write(&tmp, serde_json::to_vec_pretty(&kept)?)?;
    std::fs::rename(tmp, &file)?;
    remember(&file, Some(kept));
    Ok(())
}

/// Forgets what was kept (the flag is off, signed out).
pub fn clear(data_dir: &Path) {
    let file = path(data_dir);
    let _ = std::fs::remove_file(&file);
    remember(&file, None);
}

/// A contact's name as rules match it (the server's `cleanName` and key): trimmed, each run of whitespace one space, the
/// first 120 characters, in lower case; none for no name.
pub fn name_key(name: &str) -> Option<String> {
    let clean: String = name.split_whitespace().collect::<Vec<_>>().join(" ").chars().take(120).collect();
    (!clean.is_empty()).then(|| clean.to_lowercase())
}

/// The action for a call (pure; the server's `resolve`, docs/API.md "Which rule wins"): the first step with a rule, the
/// contact (by name: only a rule with no number, as the desktop knows no numbers), then the Source (contact groups are
/// the phone's: skipped), else the default (`after_call`, `ask` when never chosen). A rule's `ask` follows the default
/// and stops the search, except that a `dont_record` at a later step or the default still applies.
pub fn resolve(rules: &CallRules, caller_name: Option<&str>, source_app: Option<&str>) -> String {
    let default = rules.after_call.clone().unwrap_or_else(|| ASK.to_string());
    let caller = caller_name.and_then(name_key);
    let contact = caller.as_ref().and_then(|key| {
        rules.rules.iter().find(|r| r.kind == "contact" && r.number.as_deref().is_none_or(str::is_empty) && r.name.as_deref().and_then(name_key).as_ref() == Some(key))
    });
    let source = source_app.and_then(|app| rules.rules.iter().find(|r| r.kind == "source" && r.source_app.as_deref() == Some(app)));
    let steps: Vec<&CallRule> = [contact, source].into_iter().flatten().collect();
    let Some(first) = steps.first() else { return default };
    if first.action != ASK {
        return first.action.clone();
    }
    let later_dont = steps[1..].iter().any(|r| r.action == DONT_RECORD) || default == DONT_RECORD;
    if later_dont { DONT_RECORD.to_string() } else { default }
}

/// What a call follows (pure): while the account's rules are kept (the flag on), the account's settings and rules; else
/// this computer's own settings, as before. Rules apply only to calls recorded by themselves (lead decision 28): a
/// recording started by hand is the person's own act, and asks.
#[derive(Clone, Debug, Default)]
pub struct Policy {
    account: Option<CallRules>,
}

/// What happens to a call once it ends, by the policy.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum CallAfter {
    Choose(AfterCall),
    /// Not recorded: what was written goes and nothing is uploaded.
    DontRecord,
}

impl Policy {
    /// The policy now: the account's while the flag is on and its answer is kept.
    pub fn of(state: &crate::AppState) -> Self {
        Self { account: kept(state) }
    }

    /// The account's rules (tests, and an answer just read).
    pub fn account(rules: CallRules) -> Self {
        Self { account: Some(rules) }
    }

    pub fn follows_account(&self) -> bool {
        self.account.is_some()
    }

    /// Whether calls are recorded by themselves: this computer's switch, or always while the account decides (its
    /// `dont_record` then decides each call).
    pub fn auto_mode(&self, settings: &Settings) -> bool {
        self.account.is_some() || settings.auto_mode
    }

    /// Whether a call in `source_app` isn't recorded, decided before its caller is known (a Source's or the default's
    /// `dont_record`; a contact's comes once the name is known).
    pub fn blocks_at_start(&self, source_app: &str) -> bool {
        self.account.as_ref().is_some_and(|r| resolve(r, None, Some(source_app)) == DONT_RECORD)
    }

    /// When to ask under Ask me.
    pub fn ask_when(&self, settings: &Settings) -> AskWhen {
        match &self.account {
            Some(r) if r.ask_when.as_deref() == Some("at_start") => AskWhen::Start,
            Some(_) => AskWhen::After,
            None => settings.ask_when,
        }
    }

    /// Calls shorter than this many seconds aren't kept (0: all are): the account's Short calls, at the server's fixed
    /// 30 seconds, or this computer's own choice.
    pub fn short_call_seconds(&self, settings: &Settings) -> u32 {
        match &self.account {
            Some(r) if r.short_calls.as_deref() == Some("keep") => 0,
            Some(_) => SHORT_CALL_SECONDS,
            None => settings.skip_calls_shorter_than_s,
        }
    }

    /// What a call does once it ends: by the rules for one recorded by itself (with its caller's name, when known, and
    /// its Source); a call started by hand asks unless this computer's own choice says otherwise.
    pub fn after(&self, settings: &Settings, caller_name: Option<&str>, source_app: &str, auto: bool) -> CallAfter {
        let Some(rules) = &self.account else { return CallAfter::Choose(settings.after_call) };
        if !auto {
            return CallAfter::Choose(AfterCall::Ask);
        }
        match resolve(rules, caller_name, Some(source_app)).as_str() {
            DONT_RECORD => CallAfter::DontRecord,
            ALWAYS_TRANSCRIBE => CallAfter::Choose(AfterCall::AlwaysTranscribe),
            ALWAYS_AUDIO => CallAfter::Choose(AfterCall::AlwaysAudio),
            _ => CallAfter::Choose(AfterCall::Ask),
        }
    }

    /// "After each call" for the start banner and the card's offers: the account's for this Source, or this computer's.
    pub fn after_each_call(&self, settings: &Settings, source_app: Option<&str>) -> AfterEachCall {
        let Some(rules) = &self.account else { return settings.after_each_call() };
        match resolve(rules, None, source_app).as_str() {
            DONT_RECORD => AfterEachCall::Off,
            ALWAYS_TRANSCRIBE => AfterEachCall::AlwaysTranscribe,
            ALWAYS_AUDIO => AfterEachCall::AlwaysAudio,
            _ => AfterEachCall::Ask,
        }
    }

    /// Whether the contact a recording turned out to be with isn't to be recorded: a call recorded by itself whose
    /// caller's name meets a `dont_record` (decision 25: it stops, and what was written goes).
    pub fn stops_for_caller(&self, caller_name: &str, source_app: &str, auto: bool) -> bool {
        auto && self.account.as_ref().is_some_and(|r| resolve(r, Some(caller_name), Some(source_app)) == DONT_RECORD)
    }
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

    fn rules_of(after_call: Option<&str>, rules: Vec<CallRule>) -> CallRules {
        CallRules { after_call: after_call.map(str::to_string), rules, ..CallRules::default() }
    }

    #[test]
    fn a_name_is_matched_as_the_server_keys_it() {
        assert_eq!(name_key("  Ahmed \t  KHAN \u{00A0} "), Some("ahmed khan".into()));
        assert_eq!(name_key("   "), None);
        assert_eq!(name_key(&"x".repeat(130)).map(|k| k.chars().count()), Some(120));
        assert_eq!(name_key("ÉMILE Ünal"), Some("émile ünal".into()));
        assert_eq!(name_key("عائشہ  خان"), Some("عائشہ خان".into()));
    }

    #[test]
    fn which_rule_wins_contact_then_source_then_the_default() {
        let ahmed = |action: &str| rule("c1", "contact", Some("Ahmed Khan"), None, action);
        let zoom = |action: &str| rule("s1", "source", None, Some("zoom"), action);
        // The contact first (by name only for a rule without a number), then the Source, then the default.
        assert_eq!(resolve(&rules_of(Some(ALWAYS_AUDIO), vec![ahmed(ALWAYS_TRANSCRIBE), zoom(DONT_RECORD)]), Some(" ahmed  khan"), Some("zoom")), ALWAYS_TRANSCRIBE);
        assert_eq!(resolve(&rules_of(Some(ALWAYS_AUDIO), vec![ahmed(ALWAYS_TRANSCRIBE), zoom(DONT_RECORD)]), Some("Sara"), Some("zoom")), DONT_RECORD);
        assert_eq!(resolve(&rules_of(Some(ALWAYS_AUDIO), vec![zoom(DONT_RECORD)]), None, Some("google_meet")), ALWAYS_AUDIO);
        assert_eq!(resolve(&rules_of(None, vec![]), None, Some("zoom")), ASK, "never chosen: ask");
        // A contact rule with a number never matches by name (the desktop knows no numbers).
        let numbered = CallRule { number: Some("+923001234567".into()), ..ahmed(DONT_RECORD) };
        assert_eq!(resolve(&rules_of(Some(ASK), vec![numbered]), Some("Ahmed Khan"), Some("zoom")), ASK);
        // Groups are the phone's: skipped.
        assert_eq!(resolve(&rules_of(Some(ALWAYS_AUDIO), vec![rule("g1", "group", None, None, DONT_RECORD)]), Some("Ahmed Khan"), Some("zoom")), ALWAYS_AUDIO);
    }

    #[test]
    fn ask_follows_the_default_and_stops_the_search_but_a_later_dont_record_wins() {
        let ahmed_ask = rule("c1", "contact", Some("Ahmed Khan"), None, ASK);
        // "Ask me" for Ahmed: the default (Save audio only), not Zoom's Transcribe.
        let rules = rules_of(Some(ALWAYS_AUDIO), vec![ahmed_ask.clone(), rule("s1", "source", None, Some("zoom"), ALWAYS_TRANSCRIBE)]);
        assert_eq!(resolve(&rules, Some("Ahmed Khan"), Some("zoom")), ALWAYS_AUDIO);
        // "Ask me" for Ahmed and "Don't record" for Zoom: a Zoom call with Ahmed is not recorded (privacy first).
        let rules = rules_of(Some(ALWAYS_TRANSCRIBE), vec![ahmed_ask.clone(), rule("s1", "source", None, Some("zoom"), DONT_RECORD)]);
        assert_eq!(resolve(&rules, Some("Ahmed Khan"), Some("zoom")), DONT_RECORD);
        // Nor with the default's "Don't record".
        assert_eq!(resolve(&rules_of(Some(DONT_RECORD), vec![ahmed_ask]), Some("Ahmed Khan"), Some("zoom")), DONT_RECORD);
        // Any other action at an earlier step wins: "Transcribe every call" for Ahmed records him even on Zoom.
        let rules = rules_of(Some(DONT_RECORD), vec![rule("c1", "contact", Some("Ahmed Khan"), None, ALWAYS_TRANSCRIBE), rule("s1", "source", None, Some("zoom"), DONT_RECORD)]);
        assert_eq!(resolve(&rules, Some("Ahmed Khan"), Some("zoom")), ALWAYS_TRANSCRIBE);
    }

    #[test]
    fn the_policy_is_this_computer_s_own_while_the_flag_is_off_and_the_account_s_while_on() {
        let mine = Settings { auto_mode: false, after_call: AfterCall::AlwaysAudio, ask_when: AskWhen::Start, skip_calls_shorter_than_s: 60, ..Settings::default() };
        let own = Policy::default();
        assert!(!own.follows_account());
        assert!(!own.auto_mode(&mine));
        assert_eq!((own.ask_when(&mine), own.short_call_seconds(&mine)), (AskWhen::Start, 60));
        assert_eq!(own.after(&mine, Some("Ahmed Khan"), "zoom", true), CallAfter::Choose(AfterCall::AlwaysAudio));
        assert!(!own.blocks_at_start("zoom"));

        let account = Policy::account(CallRules {
            after_call: Some(ALWAYS_TRANSCRIBE.into()),
            ask_when: Some("after_call".into()),
            short_calls: Some("dont_keep".into()),
            short_call_seconds: Some(45),
            rules: vec![rule("s1", "source", None, Some("zoom"), DONT_RECORD), rule("c1", "contact", Some("Ahmed Khan"), None, DONT_RECORD)],
            ..CallRules::default()
        });
        assert!(account.auto_mode(&mine), "the account decides each call");
        assert_eq!((account.ask_when(&mine), account.short_call_seconds(&mine)), (AskWhen::After, SHORT_CALL_SECONDS), "the server's 30, fixed");
        assert!(account.blocks_at_start("zoom") && !account.blocks_at_start("google_meet"));
        assert_eq!(account.after(&mine, None, "google_meet", true), CallAfter::Choose(AfterCall::AlwaysTranscribe));
        assert_eq!(account.after(&mine, Some("Ahmed Khan"), "google_meet", true), CallAfter::DontRecord);
        assert!(account.stops_for_caller("ahmed khan", "google_meet", true));
        // Started by hand (lead decision 28): the person's own act asks, and no rule stops it.
        assert_eq!(account.after(&mine, Some("Ahmed Khan"), "zoom", false), CallAfter::Choose(AfterCall::Ask));
        assert!(!account.stops_for_caller("Ahmed Khan", "google_meet", false));
        let keep = Policy::account(CallRules { short_calls: Some("keep".into()), ..CallRules::default() });
        assert_eq!(keep.short_call_seconds(&mine), 0);
        assert_eq!(account.after_each_call(&mine, Some("zoom")), AfterEachCall::Off);
        assert_eq!(account.after_each_call(&mine, Some("google_meet")), AfterEachCall::AlwaysTranscribe);
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
