//! Call rules (contract 2.0.0-w, docs/PLAN_CALL_RULES.md; decision 25): the person's After each call, When to ask,
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

/// What is kept: the account's answer, for the sign-in it was read in (another sign-in never reads it), and its last
/// After each call that records (the tray's switch turned on again goes back to it, not to Ask me).
#[derive(Clone, Debug, Default, PartialEq, Serialize, Deserialize)]
pub struct Kept {
    pub sign_in: String,
    pub rules: CallRules,
    #[serde(default)]
    pub last_recording_choice: Option<String>,
}

/// The one notice when a contact's rule stopped a recording (decision 25: "stopped and deleted", never "not recorded",
/// as it was, for a moment). It names nobody: it may show in a screen share, and as a system notification.
pub const STOPPED_TEXT: &str = "Stopped and deleted the recording: a call rule says Don't record for this contact.";

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

/// The account's last After each call that records (not Don't record) kept for `sign_in`.
pub fn last_recording_choice(data_dir: &Path, sign_in: &str) -> Option<String> {
    let kept: Kept = serde_json::from_slice(&std::fs::read(path(data_dir)).ok()?).ok()?;
    if kept.sign_in != sign_in {
        return None;
    }
    kept.last_recording_choice.or_else(|| kept.rules.after_call.filter(|a| a != DONT_RECORD))
}

pub fn save(data_dir: &Path, sign_in: &str, rules: &CallRules) -> AppResult<()> {
    let file = path(data_dir);
    let tmp = file.with_extension("json.tmp");
    let now = rules.after_call.clone().filter(|a| a != DONT_RECORD);
    let last_recording_choice = now.or_else(|| last_recording_choice(data_dir, sign_in));
    let kept = Kept { sign_in: sign_in.to_string(), rules: rules.clone(), last_recording_choice };
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

/// A recording a contact's rule says not to keep, its first step (crash-safe): its folder is marked as leaving (a crash
/// from here on deletes it at the next start, never offers it back) and its server sessions are queued to be deleted
/// and erased from the Trash (discards.json, then purges.json; the drain also takes a purge without its discard).
pub fn mark_dropped(data_dir: &Path, dir: &Path, sessions: &[String], lock: &std::sync::Mutex<()>) -> AppResult<()> {
    use crate::net::sync::paths;
    use crate::net::uploader::{DiscardQueue, PurgeSet};
    crate::recovery::mark_leaving(dir)?;
    if sessions.is_empty() {
        return Ok(());
    }
    let _guard = lock.lock().unwrap_or_else(|p| p.into_inner());
    let mut discards = DiscardQueue::load(&paths::discards(data_dir));
    let mut purges = PurgeSet::load(&paths::purges(data_dir));
    for id in sessions {
        discards.push(id.clone());
        purges.add(id);
    }
    discards.save(&paths::discards(data_dir))?;
    purges.save(&paths::purges(data_dir))?;
    Ok(())
}

/// A recording a contact's rule says not to keep, on disk: marked and queued ([`mark_dropped`]), then its folder goes.
pub fn drop_recording(data_dir: &Path, dir: &Path, sessions: &[String], lock: &std::sync::Mutex<()>) -> AppResult<()> {
    let marked = mark_dropped(data_dir, dir, sessions, lock);
    crate::recovery::delete_recording_folder(dir);
    marked
}

/// The rule for a contact by name (pure): only one without a number, as the desktop knows no numbers.
pub fn contact_rule<'a>(rules: &'a CallRules, caller_name: &str) -> Option<&'a CallRule> {
    let key = name_key(caller_name)?;
    rules.rules.iter().find(|r| r.kind == "contact" && r.number.as_deref().is_none_or(str::is_empty) && r.name.as_deref().and_then(name_key).as_deref() == Some(key.as_str()))
}

/// What a call follows (pure): while the account's rules are kept (the flag on), the account's settings and rules; else
/// this computer's own settings, as before. Rules apply only to calls recorded by themselves (decision 28): a
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

    /// Calls shorter than this many seconds aren't kept (0: all are): the account's Short calls, at the server's
    /// `short_call_seconds` (30), or this computer's own choice.
    pub fn short_call_seconds(&self, settings: &Settings) -> u32 {
        match &self.account {
            Some(r) if r.short_calls.as_deref() == Some("keep") => 0,
            Some(r) => r.short_call_seconds.unwrap_or(SHORT_CALL_SECONDS),
            None => settings.skip_calls_shorter_than_s,
        }
    }

    /// What a call does once it ends: by the rules for one recorded by itself (with its caller's name, when known, and
    /// its Source); a call started by hand, or from the "Record?" chip, asks (decision 28). Once recording, only a
    /// matched contact's Don't record deletes it (decision 29): a Source's or the default's is decided when the call
    /// starts, so one that came during the call (the tray, Settings, the phone) leaves it to end as Ask me.
    pub fn after(&self, settings: &Settings, caller_name: Option<&str>, source_app: &str, rules_apply: bool) -> CallAfter {
        let Some(rules) = &self.account else { return CallAfter::Choose(settings.after_call) };
        if !rules_apply {
            return CallAfter::Choose(AfterCall::Ask);
        }
        if caller_name.and_then(|n| contact_rule(rules, n)).is_some_and(|r| r.action == DONT_RECORD) {
            return CallAfter::DontRecord;
        }
        match resolve(rules, caller_name, Some(source_app)).as_str() {
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

    /// Whether the contact a recording turned out to be with isn't to be recorded: a call the rules apply to
    /// ([`rules_apply`]) whose caller's name has a contact rule Don't record (decisions 25 and 29: it stops, and what was
    /// written goes), unless a choice was made on the pill during the call (the person's own, which stands). The one
    /// rule for both, during the call and at its end.
    pub fn stops_for_caller(&self, caller_name: &str, rules_apply: bool, pill_chosen: bool) -> bool {
        !pill_chosen && rules_apply && self.account.as_ref().and_then(|r| contact_rule(r, caller_name)).is_some_and(|r| r.action == DONT_RECORD)
    }

    /// How a finished recording ends by the policy (pure; decisions 25, 28 and 29).
    pub fn end_of_call(&self, settings: &Settings, ended: &Ended) -> EndOfCall {
        let after = self.after(settings, ended.caller_name, ended.source_app, ended.rules_apply);
        EndOfCall {
            drops: ended.is_call && ended.caller_name.is_some_and(|n| self.stops_for_caller(n, ended.rules_apply, ended.pill_chosen)),
            by_itself: if self.follows_account() { ended.rules_apply } else { ended.auto },
            short_call_seconds: self.short_call_seconds(settings),
            setting: match after {
                CallAfter::Choose(setting) => setting,
                CallAfter::DontRecord => AfterCall::Ask,
            },
            // A call the person started asks after it, the card: no start banner was shown for it.
            ask_when: if self.follows_account() && !ended.rules_apply { AskWhen::After } else { self.ask_when(settings) },
        }
    }

    /// Whether the "Record?" chip may offer an app nobody knows: not while the account's default is Don't record
    /// (calls aren't recorded by themselves then).
    pub fn offers_unknown_apps(&self) -> bool {
        self.account.as_ref().is_none_or(|r| r.after_call.as_deref() != Some(DONT_RECORD))
    }
}

/// Whether the call rules apply to a recording (pure; decisions 28 and 29): one recorded by itself (`auto`), and not
/// the one the "Record?" chip started (`chip`), which the person asked for.
pub fn rules_apply(auto: bool, chip: Option<&str>, client_ref: &str) -> bool {
    auto && chip != Some(client_ref)
}

/// A finished recording, for [`Policy::end_of_call`].
#[derive(Clone, Copy, Debug)]
pub struct Ended<'a> {
    pub caller_name: Option<&'a str>,
    pub source_app: &'a str,
    /// A call (not a voice note).
    pub is_call: bool,
    /// Started by itself (not by hand).
    pub auto: bool,
    /// The rules apply to it ([`rules_apply`]).
    pub rules_apply: bool,
    /// A choice was made on the pill during the call.
    pub pill_chosen: bool,
}

/// How a finished recording ends by the policy.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct EndOfCall {
    /// A contact's Don't record: what was written goes, nothing is uploaded and nothing asks.
    pub drops: bool,
    /// Recorded by itself as the rules see it: short and silent calls are skipped, and its card's answer counts toward
    /// "Always do this?". Under the account, one started by hand or from the chip is the person's own (decision 28).
    pub by_itself: bool,
    /// Calls shorter than this aren't kept (0: all are).
    pub short_call_seconds: u32,
    /// After each call for it.
    pub setting: AfterCall,
    /// When to ask under Ask me.
    pub ask_when: AskWhen,
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

/// Whether an answer says the rule is no longer there (`404 rule_not_found`: another device removed it).
pub fn rule_gone(e: &AppError) -> bool {
    matches!(e, AppError::Server { status: 404, code: Some(code), .. } if code == "rule_not_found")
}

/// The account's answer read again after a rule turned out to be gone.
#[derive(Debug)]
pub enum Reread {
    Read(CallRules),
    /// `404 not_found`: the flag is off now.
    Off,
    /// Offline or the server busy: what is kept stays until the next config.
    Failed,
}

/// A change to one rule as the server answered it.
#[derive(Debug)]
pub enum RuleChanged {
    /// Saved (`PATCH`): the rule as the server has it.
    Saved(CallRule),
    /// Removed (`DELETE`); one already gone counts as removed, and the account's answer was read again (`reread`).
    Removed { reread: Option<Reread> },
    /// Another device removed it while it was being changed: the account's answer, read again.
    Gone(Reread),
}

async fn reread(api: &crate::net::api::ApiClient) -> Reread {
    match api.call_rules().await {
        Ok(rules) => Reread::Read(rules),
        Err(e) if flag_is_off(&e) => Reread::Off,
        Err(_) => Reread::Failed,
    }
}

/// `PATCH /api/call-rules/{id}`; one another device removed is read again (`rule_not_found`).
pub async fn update_rule(api: &crate::net::api::ApiClient, id: &str, patch: &serde_json::Value) -> AppResult<RuleChanged> {
    match api.update_call_rule(id, patch).await {
        Ok(rule) => Ok(RuleChanged::Saved(rule)),
        Err(e) if rule_gone(&e) => Ok(RuleChanged::Gone(reread(api).await)),
        Err(e) => Err(e),
    }
}

/// `DELETE /api/call-rules/{id}`; one already gone counts as removed, and the rules are read again.
pub async fn delete_rule(api: &crate::net::api::ApiClient, id: &str) -> AppResult<RuleChanged> {
    match api.delete_call_rule(id).await {
        Ok(()) => Ok(RuleChanged::Removed { reread: None }),
        Err(e) if rule_gone(&e) => Ok(RuleChanged::Removed { reread: Some(reread(api).await) }),
        Err(e) => Err(e),
    }
}

/// Keeps, or forgets, what a read again found.
pub fn apply_reread(app: &AppHandle, reread: Reread) {
    match reread {
        Reread::Read(rules) => {
            if let Err(e) = keep(app, &rules) {
                eprintln!("[echoledger] keeping the call rules: {e}");
            }
        }
        Reread::Off => forget(app),
        Reread::Failed => {}
    }
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

/// Whether the person's flag is on: from the last config, or, before the first one is read (at start, or all along
/// while the server can't be reached), from what was kept, which goes only when a config says off or a route answers
/// `404 not_found`.
pub fn flag_on(state: &crate::AppState) -> bool {
    let known = state.server_config.lock().unwrap_or_else(|p| p.into_inner()).as_ref().map(|c| c.ui().is_on(FLAG));
    match known {
        Some(on) => on,
        None => state.sign_in_id().is_some_and(|s| load(&state.data_dir, &s).is_some()),
    }
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

/// Keeps `rules` for this sign-in and tells the window; the status follows (the tray's and the menus' ticks).
pub fn keep(app: &AppHandle, rules: &CallRules) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    let Some(sign_in) = state.sign_in_id() else { return Ok(()) };
    save(&state.data_dir, &sign_in, rules)?;
    let _ = app.emit(EVENT, rules);
    crate::actions::emit_status(app);
    // Setup's and the tray's "not ready" follow the account's After each call.
    crate::readiness::wake(app);
    Ok(())
}

/// Forgets the kept answer (the flag is off) and tells the window, when there was one.
pub fn forget(app: &AppHandle) {
    let state = app.state::<crate::AppState>();
    if path(&state.data_dir).exists() {
        clear(&state.data_dir);
        forgotten(app);
    }
}

/// Tells the window and the status that nothing is kept any more.
fn forgotten(app: &AppHandle) {
    let _ = app.emit(EVENT, serde_json::Value::Null);
    crate::actions::emit_status(app);
    crate::readiness::wake(app);
}

/// Whether calls record by themselves now: the account's After each call isn't Don't record (flag on), else this
/// computer's own switch.
pub fn records_by_themselves(state: &crate::AppState, settings: &Settings) -> bool {
    match kept(state) {
        Some(rules) => rules.after_call.as_deref() != Some(DONT_RECORD),
        None => settings.auto_mode,
    }
}

/// The tray's "Record calls automatically" while the account decides: its After each call becomes Don't record, or
/// Ask me from Don't record; the answer is kept and the status follows. Locked, nothing changes (the account's settings
/// are changed only by someone who unlocked EchoLedger, as every command does), and the tray shows the item greyed.
pub async fn toggle_records_by_themselves(app: &AppHandle) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    let now = records_by_themselves(&state, &state.settings());
    set_auto_record(app, !now).await
}

/// The account's value for a choice of "After each call".
pub fn after_call_of(value: AfterEachCall) -> &'static str {
    match value {
        AfterEachCall::Ask => ASK,
        AfterEachCall::AlwaysTranscribe => ALWAYS_TRANSCRIBE,
        AfterEachCall::AlwaysAudio => ALWAYS_AUDIO,
        AfterEachCall::Off => DONT_RECORD,
    }
}

/// Sets the account's After each call (the card's "Always do this?", the one-time notice): checked for the lock as
/// every change is, the answer kept and the status following.
pub async fn set_after_call(app: &AppHandle, after_call: &str) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    crate::commands::ensure_unlocked(&state)?;
    match state.api()?.put_call_rule_settings(&json!({ "after_call": after_call })).await {
        Ok(rules) => keep(app, &rules),
        Err(e) => {
            if flag_is_off(&e) {
                forget(app);
                crate::actions::refresh_config_now(app).await;
            }
            Err(change_error(e))
        }
    }
}

/// "Auto-record calls & meetings", every control of it (the tray, the Record and Live menus, Home, the Mac menu bar):
/// while the account's rules are kept, its After each call (Don't record, or back to its last choice that records,
/// else Ask me), refused while locked; otherwise this computer's own switch, as before.
pub async fn set_auto_record(app: &AppHandle, on: bool) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    if kept(&state).is_none() {
        return auto_mode_here(&state, on, |settings| {
            let _ = app.emit("settings:changed", settings);
            crate::actions::emit_status(app);
        });
    }
    crate::commands::ensure_unlocked(&state)?;
    // On again: the account's last choice that records (Transcribe every call stays so), else Ask me.
    let back = state.sign_in_id().and_then(|s| last_recording_choice(&state.data_dir, &s));
    let answer = set_records_by_themselves(&state.api()?, state.lock.is_locked(), on, back.as_deref()).await;
    match answer {
        Ok(rules) => keep(app, &rules),
        Err(e) => {
            if flag_is_off(&e) {
                forget(app);
                crate::actions::refresh_config_now(app).await;
            }
            Err(change_error(e))
        }
    }
}

/// This computer's own switch, exactly as before the call rules (what the window's `update_settings` does for it): saved,
/// the readiness check woken (`readiness::wake`), then `told` the new settings (`settings:changed` and the status).
fn auto_mode_here(state: &crate::AppState, on: bool, told: impl FnOnce(&Settings)) -> AppResult<()> {
    let updated = state.update_settings(|s| s.auto_mode = on)?;
    state.ready_wake.notify_one();
    told(&updated);
    Ok(())
}

/// The account's After each call for the tray's switch: `back` (its last choice that records, Ask me without one) when
/// calls are to record by themselves again, Don't record otherwise. Refused while EchoLedger is locked, nothing sent.
pub async fn set_records_by_themselves(api: &crate::net::api::ApiClient, locked: bool, on: bool, back: Option<&str>) -> AppResult<CallRules> {
    if locked {
        return Err(AppError::msg("EchoLedger is locked."));
    }
    let back = back.filter(|b| [ASK, ALWAYS_TRANSCRIBE, ALWAYS_AUDIO].contains(b)).unwrap_or(ASK);
    api.put_call_rule_settings(&json!({ "after_call": if on { back } else { DONT_RECORD } })).await
}

/// After each config (every 60 s): with the flag off, nothing is kept; with it on, the account's answer is read again
/// when its `version` is newer than the one kept, or none is kept yet. The first sync sends this computer's choice only
/// when the answer just read still has none.
pub async fn sync(app: &AppHandle) {
    let state = app.state::<crate::AppState>();
    let Some(sign_in) = state.sign_in_id() else { return };
    let Ok(api) = state.api() else { return };
    let version = config_version(&state).filter(|_| crate::ui_flags::cached(&state).is_on(FLAG));
    match sync_with(&api, &state.data_dir, &sign_in, version, &state.settings()).await {
        Synced::Off { forgot: true } => forgotten(app),
        Synced::Read(rules) => {
            if let Err(e) = keep(app, &rules) {
                eprintln!("[echoledger] keeping the call rules: {e}");
            }
        }
        Synced::Off { forgot: false } | Synced::Kept | Synced::Wait => {}
    }
}

/// What a sync came to.
#[derive(Debug, PartialEq)]
pub enum Synced {
    /// The flag is off (the config says so, or the route is not there): what was kept is gone (`forgot`: there was
    /// some, and the window and status are to follow).
    Off { forgot: bool },
    /// The kept answer is as new as the config says.
    Kept,
    /// A newer answer, to keep.
    Read(CallRules),
    /// Offline or the server busy: read again at the next config; nothing sent from memory meanwhile.
    Wait,
}

/// One sync for `sign_in` (`version`: the config's, none while the flag is off). With the flag off, what was kept goes
/// here, so this computer's own settings apply from now on.
pub async fn sync_with(api: &crate::net::api::ApiClient, data_dir: &Path, sign_in: &str, version: Option<u64>, settings: &Settings) -> Synced {
    let off = || {
        let forgot = path(data_dir).exists();
        clear(data_dir);
        Synced::Off { forgot }
    };
    let Some(version) = version else { return off() };
    if load(data_dir, sign_in).is_some_and(|k| k.version.unwrap_or(0) >= version) {
        return Synced::Kept;
    }
    match read_with_first_sync(api, settings).await {
        Ok(rules) => Synced::Read(rules),
        Err(e) if flag_is_off(&e) => off(),
        Err(_) => Synced::Wait,
    }
}

/// The account's answer now. The first sync: when it has no After each call yet (the person never chose on any
/// device), this computer's own choice is sent right after that read, and the server's answer to it is the one kept.
pub async fn read_with_first_sync(api: &crate::net::api::ApiClient, settings: &Settings) -> AppResult<CallRules> {
    let rules = api.call_rules().await?;
    if rules.after_call.is_some() {
        return Ok(rules);
    }
    // This runs while EchoLedger is locked too, on purpose: it is the automatic sync of this computer's own choice, made
    // before, never a change by someone at the keyboard (those check the lock: ensure_unlocked).
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
        assert_eq!((account.ask_when(&mine), account.short_call_seconds(&mine)), (AskWhen::After, 45), "the server's short_call_seconds");
        let unsaid = Policy::account(CallRules { short_calls: Some("dont_keep".into()), ..CallRules::default() });
        assert_eq!(unsaid.short_call_seconds(&mine), SHORT_CALL_SECONDS, "30 when the server doesn't say");
        assert!(account.blocks_at_start("zoom") && !account.blocks_at_start("google_meet"));
        assert_eq!(account.after(&mine, None, "google_meet", true), CallAfter::Choose(AfterCall::AlwaysTranscribe));
        assert_eq!(account.after(&mine, Some("Ahmed Khan"), "google_meet", true), CallAfter::DontRecord);
        assert!(account.stops_for_caller("ahmed khan", true, false));
        assert!(!account.stops_for_caller("ahmed khan", true, true), "a choice made on the pill stands");
        // Once recording, only a contact's Don't record deletes it (decision 29): Zoom's, decided when the call
        // starts, leaves a Zoom call that is already recording (the rule came during it) to end as Ask me.
        assert_eq!(account.after(&mine, Some("Sara"), "zoom", true), CallAfter::Choose(AfterCall::Ask));
        assert_eq!(account.after(&mine, None, "zoom", true), CallAfter::Choose(AfterCall::Ask));
        assert!(!account.stops_for_caller("Sara", true, false));
        // Nor the default's.
        let off = Policy::account(CallRules { after_call: Some(DONT_RECORD.into()), ..CallRules::default() });
        assert_eq!(off.after(&mine, Some("Sara"), "zoom", true), CallAfter::Choose(AfterCall::Ask));
        assert!(off.blocks_at_start("zoom"), "decided when the call starts");
        // Started by hand (decision 28): the person's own act asks, and no rule stops it.
        assert_eq!(account.after(&mine, Some("Ahmed Khan"), "zoom", false), CallAfter::Choose(AfterCall::Ask));
        assert!(!account.stops_for_caller("Ahmed Khan", false, false));
        // The "Record?" chip offers no app nobody knows while the default is Don't record (decision 29).
        assert!(!off.offers_unknown_apps() && account.offers_unknown_apps() && own.offers_unknown_apps());
        let keep = Policy::account(CallRules { short_calls: Some("keep".into()), ..CallRules::default() });
        assert_eq!(keep.short_call_seconds(&mine), 0);
        assert_eq!(account.after_each_call(&mine, Some("zoom")), AfterEachCall::Off);
        assert_eq!(account.after_each_call(&mine, Some("google_meet")), AfterEachCall::AlwaysTranscribe);
    }

    #[tokio::test]
    async fn with_nothing_kept_the_switch_is_this_computer_s_saved_told_and_readiness_woken() {
        // The flag off stays exactly as before: the window's update_settings saved it, said settings:changed and woke
        // the readiness check.
        let data = tempfile::tempdir().unwrap();
        let state = crate::AppState::load(data.path().to_path_buf(), true);
        let mut told = Vec::new();
        auto_mode_here(&state, false, |s| told.push(s.auto_mode)).unwrap();
        assert_eq!(told, [false], "told once, with the new settings");
        assert!(!Settings::load(&data.path().join("settings.json")).auto_mode, "saved");
        assert!(!state.settings().auto_mode);
        tokio::time::timeout(std::time::Duration::from_millis(200), state.ready_wake.notified()).await.expect("readiness woken");
        auto_mode_here(&state, true, |s| told.push(s.auto_mode)).unwrap();
        assert_eq!(told, [false, true]);
        assert!(Settings::load(&data.path().join("settings.json")).auto_mode);
        // And the path set_auto_record takes with nothing kept is this one, saying settings:changed.
        let source = include_str!("call_rules.rs");
        let body = &source[source.find("pub async fn set_auto_record(").unwrap()..];
        let local = &body[..body.find("crate::commands::ensure_unlocked(&state)?;").unwrap()];
        assert!(local.contains("auto_mode_here(&state, on,") && local.contains("app.emit(\"settings:changed\", settings)"), "{local}");
    }

    #[test]
    fn what_is_kept_is_this_sign_in_s_only() {
        let data = tempfile::tempdir().unwrap();
        let rules = CallRules { after_call: Some(ALWAYS_AUDIO.into()), version: Some(2), ..CallRules::default() };
        save(data.path(), "sign-in-a", &rules).unwrap();
        assert_eq!(load(data.path(), "sign-in-a"), Some(rules.clone()));
        assert_eq!(load(data.path(), "sign-in-b"), None, "another sign-in never reads it");
        // The last choice that records stays through a Don't record, for the tray's switch turned on again.
        assert_eq!(last_recording_choice(data.path(), "sign-in-a").as_deref(), Some(ALWAYS_AUDIO));
        let off = CallRules { after_call: Some(DONT_RECORD.into()), ..rules.clone() };
        save(data.path(), "sign-in-a", &off).unwrap();
        assert_eq!(last_recording_choice(data.path(), "sign-in-a").as_deref(), Some(ALWAYS_AUDIO));
        assert_eq!(last_recording_choice(data.path(), "sign-in-b"), None);
        clear(data.path());
        assert_eq!(load(data.path(), "sign-in-a"), None);
    }
}
