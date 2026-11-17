//! Auto mode records real calls only. A call is an application process tree that holds the microphone AND plays
//! audio for the whole start window; holding only the microphone is dictation or a voice message and is never
//! recorded. Native call apps (WhatsApp, Zoom, Teams) are identified by process identity; browsers only by the site
//! they show (window titles on Windows, active-tab URLs on macOS), with a built-in never-record list. The label is
//! decided once when the recording starts and kept until it stops. Voice notes are never auto-recorded.

pub mod callers;
pub mod classify;
pub mod decide;
pub mod sites;
pub mod state;

use std::collections::HashMap;
use std::sync::{Mutex, MutexGuard};
use std::time::{Duration, Instant};

use serde::Serialize;
use tauri::{AppHandle, Emitter, Manager};

use crate::error::{AppError, AppResult};
use classify::SourceApp;
use decide::{AskTracker, CallTracker, IgnoreReason, TreeAudio, Verdict, apply_app_switches, decide, identify, is_browser_tree, pick_candidate};
use sites::{NEVER, SiteRule, strip_browser_suffix, url_host};
use state::MicUseEvent;

/// How long the chip stays before it dismisses itself.
pub const ASK_SECONDS: u64 = 8;
/// Shown for a process whose identity cannot be read.
pub const PROTECTED: &str = "a protected process";

/// One line of "Why a call wasn't recorded" (Settings › Calls & meetings): an application holding the microphone and
/// what auto mode does with it, which the app says in plain words ("Google Meet: Will be recorded").
#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct MicUser {
    /// "chrome.exe", "WhatsApp.exe", a bundle id, or "a protected process".
    pub name: String,
    /// The `source_app` it is identified as, e.g. "google_meet"; None when not identified.
    pub app: Option<String>,
    /// Always true for listed entries.
    pub holds_mic: bool,
    /// The same process tree has an active render (output) session.
    pub plays_audio: bool,
    /// Browsers: matched host or rule id, e.g. "meet.google.com"; None otherwise.
    pub site: Option<String>,
    /// "auto_record" | "recording" | "ignored" | "ask" | "off".
    pub decision: String,
    /// When ignored: "dictation" | "unknown_site" | "unknown_app" | "never_record" | "auto_off" | "app_off".
    pub reason: Option<String>,
}

/// The explainer line for one tree. `recording` is the tree auto mode is recording and the label it decided at
/// start (kept even when the tree now shows another site).
pub fn explain(t: &TreeAudio, verdict: &Verdict, user_rules: &[SiteRule], recording: Option<(u32, SourceApp)>) -> MicUser {
    let (identified, site, _) = identify(t, user_rules);
    let recorded = recording.filter(|(pid, _)| *pid == t.root_pid).map(|(_, app)| app);
    let (decision, reason) = match (recorded, verdict) {
        (Some(_), _) => ("recording", None),
        (None, Verdict::Record(_)) => ("auto_record", None),
        (None, Verdict::Ask) => ("ask", None),
        (None, Verdict::Ignore(IgnoreReason::AutoOff)) => ("off", Some(IgnoreReason::AutoOff)),
        (None, Verdict::Ignore(reason)) => ("ignored", Some(*reason)),
    };
    MicUser {
        name: t.name.clone(),
        app: recorded.or(identified).map(|a| a.as_str().to_string()),
        holds_mic: t.capture_active,
        plays_audio: t.render_active,
        site,
        decision: decision.into(),
        reason: reason.map(|r| r.as_str().to_string()),
    }
}

/// Whether an app using the microphone is left unrecorded (pure): ignored, or auto-record is off.
pub fn not_recorded(user: &MicUser) -> bool {
    matches!(user.decision.as_str(), "ignored" | "off")
}

/// An app that used the microphone without being recorded, and when it was last seen so. Kept in memory while
/// EchoLedger runs, one entry per app, so Settings › Calls & meetings can explain a call from earlier, not only one
/// happening while that page is open. Names and reasons only: no sound, no words, no window titles.
#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct NotRecorded {
    pub user: MicUser,
    pub at: chrono::DateTime<chrono::Utc>,
}

/// How many apps not recorded are kept, the most recently seen.
pub const KEPT_NOT_RECORDED: usize = 8;

/// The same app as far as "not recorded" goes: the same program, on the same website for a browser.
fn same_app(a: &MicUser, b: &MicUser) -> bool {
    a.name == b.name && a.site == b.site
}

/// What is kept after a look (pure): every app of this look that is not recorded, each in its own entry seen now, then
/// the apps kept before that this look did not see again, as they were; newest first, at most [`KEPT_NOT_RECORDED`].
pub fn keep_not_recorded(users: &[MicUser], kept: Vec<NotRecorded>, now: chrono::DateTime<chrono::Utc>) -> Vec<NotRecorded> {
    let mut next: Vec<NotRecorded> = Vec::new();
    for user in users.iter().filter(|u| not_recorded(u)) {
        if !next.iter().any(|k| same_app(&k.user, user)) {
            next.push(NotRecorded { user: user.clone(), at: now });
        }
    }
    let older: Vec<NotRecorded> = kept.into_iter().filter(|k| !next.iter().any(|n| same_app(&n.user, &k.user))).collect();
    next.extend(older);
    // Stable: this look's apps keep their order, then the older ones by their time.
    next.sort_by_key(|k| std::cmp::Reverse(k.at));
    next.truncate(KEPT_NOT_RECORDED);
    next
}

/// Whether an app looks like a call (pure): a call app EchoLedger knows, or one that plays sound. An app that only
/// holds the microphone (a noise filter, a voice changer, dictation) comes after any of those.
fn call_like(user: &MicUser) -> bool {
    user.app.is_some() || user.plays_audio
}

/// The one "Why a call wasn't recorded" explains (pure): of the apps kept (newest first), only those not using the
/// microphone now (the list above it explains those), the newest that looks like a call, else the newest of the rest.
/// So an app always holding the microphone (a noise filter, a browser tab) never hides the call that was missed.
pub fn last_not_recorded(kept: &[NotRecorded], now_using: &[MicUser]) -> Option<NotRecorded> {
    let gone = || kept.iter().filter(|k| !now_using.iter().any(|u| same_app(u, &k.user)));
    gone().find(|k| call_like(&k.user)).or_else(|| gone().next()).cloned()
}

/// The never-record rule "Always ignore" adds: for a browser the tab's host when known, else the page title;
/// for any other app its image name (or bundle id). None when there is nothing specific to match.
pub fn never_rule_for(id: String, name: &str, browser: bool, host: Option<&str>, title: Option<&str>) -> Option<SiteRule> {
    let mut rule = SiteRule { id, app: NEVER.into(), host: None, title_prefix: None, title_contains: None, title_equals: None, priority: 100, enabled: true };
    let clean = |v: Option<&str>| v.map(str::trim).filter(|v| !v.is_empty()).map(str::to_string);
    if browser {
        if let Some(host) = clean(host) {
            rule.host = Some(host);
        } else {
            rule.title_contains = Some(clean(title.map(strip_browser_suffix))?);
        }
    } else {
        let name = clean(Some(name)).filter(|n| n != PROTECTED)?;
        rule.title_equals = Some(name);
    }
    Some(rule)
}

/// The application trees holding a microphone right now (EchoLedger excluded). Windows reads window titles of
/// browser trees; macOS reads tab URLs only when `browser_automation` is on.
pub fn observe(browser_automation: bool) -> Vec<TreeAudio> {
    #[cfg(windows)]
    {
        let _ = browser_automation; // titles need no permission on Windows
        // Call apps' titles too, for caller names (since v1.2).
        crate::audio::platform::windows::observe_trees(|image| classify::is_browser_image(image) || classify::classify_windows(image, None).is_some())
    }
    #[cfg(target_os = "macos")]
    {
        crate::audio::platform::macos::observe_trees(browser_automation)
    }
    #[cfg(not(any(windows, target_os = "macos")))]
    {
        let _ = browser_automation;
        Vec::new()
    }
}

/// One-shot: the call app auto mode would record now with the built-in rules (read-only, no state).
pub fn detect_call_app() -> Option<SourceApp> {
    let trees = observe(false);
    let verdicts: Vec<Verdict> = trees.iter().map(|t| decide(t, &[], false, true)).collect();
    pick_candidate(&trees, &verdicts).map(|(_, app)| app)
}

/// One-shot: the explainer lines with the built-in rules (read-only, no state). The app itself serves the list
/// the auto-mode loop keeps ([`DetectState::mic_users`]).
pub fn mic_users() -> Vec<MicUser> {
    observe(false).iter().map(|t| explain(t, &decide(t, &[], false, true), &[], None)).collect()
}

/// A tree the chip was offered for, kept until the chip is answered or dismissed.
#[derive(Clone, Debug)]
struct AskTarget {
    root_pid: u32,
    name: String,
    browser: bool,
    host: Option<String>,
    title: Option<String>,
}

/// Auto-mode state shared between the detection loop and the commands.
#[derive(Default)]
pub struct DetectState {
    users: Mutex<Vec<MicUser>>,
    asks: Mutex<HashMap<String, AskTarget>>,
    call: Mutex<CallTracker>,
    ask: Mutex<AskTracker>,
    far_side: Mutex<crate::audio::health::FarSideMonitor>,
    meeting: Mutex<Option<crate::screens::platform::MeetingTree>>,
    /// When the loop last looked (the Ready check's heartbeat, Phase 1C).
    looked: Mutex<Option<Instant>>,
    /// Starts a detected meeting that did not start recording after 5 s (Phase 1C).
    watchdog: Mutex<crate::call_guard::Watchdog>,
    /// The apps not recorded and when, newest first ([`keep_not_recorded`]), for "Why a call wasn't recorded".
    missed: Mutex<Vec<NotRecorded>>,
    /// Who each call app's windows say the call is with now (wire value → name), read every tick: the contact a
    /// call rule is checked against before capture starts.
    callers: Mutex<HashMap<String, String>>,
}

fn lock<T>(m: &Mutex<T>) -> MutexGuard<'_, T> {
    m.lock().unwrap_or_else(|p| p.into_inner())
}

impl DetectState {
    /// For tests: the call in `source_app` is with `name`, as if its window said so.
    #[cfg(test)]
    pub(crate) fn note_caller(&self, source_app: &str, name: &str) {
        lock(&self.callers).insert(source_app.to_string(), name.to_string());
    }

    /// Who the call in `source_app` (its wire value) is with, as its window says now; none when it does not say.
    pub fn caller_now(&self, source_app: &str) -> Option<String> {
        lock(&self.callers).get(source_app).cloned()
    }

    /// The last observation with its decisions (refreshed once per second by the loop).
    pub fn mic_users(&self) -> Vec<MicUser> {
        lock(&self.users).clone()
    }

    /// The last app that used the microphone without being recorded since EchoLedger started, and when.
    pub fn last_not_recorded(&self) -> Option<NotRecorded> {
        let kept = lock(&self.missed).clone();
        let now_using = lock(&self.users).clone();
        last_not_recorded(&kept, &now_using)
    }

    /// Whether the loop looked at the microphone within `limit` (it stops only if its thread died).
    pub fn looked_within(&self, limit: Duration) -> bool {
        crate::readiness::looked_within(*lock(&self.looked), Instant::now(), limit)
    }

    /// The meeting app screen moments look at (refreshed once per second by the loop).
    pub fn meeting(&self) -> Option<crate::screens::platform::MeetingTree> {
        lock(&self.meeting).clone()
    }
}

/// The meeting app of this moment: the tree auto mode records, else the call app or site that holds the microphone
/// and plays audio (also with auto mode off, for a recording started by hand).
pub fn meeting_tree(trees: &[TreeAudio], rules: &[SiteRule], recorded: Option<(u32, SourceApp)>) -> Option<crate::screens::platform::MeetingTree> {
    let pick = recorded.or_else(|| {
        let verdicts: Vec<Verdict> = trees.iter().map(|t| decide(t, rules, false, true)).collect();
        pick_candidate(trees, &verdicts)
    })?;
    let tree = trees.iter().find(|t| t.root_pid == pick.0)?;
    Some(crate::screens::platform::MeetingTree { root_pid: tree.root_pid, app: pick.1, name: tree.name.clone(), browser: is_browser_tree(tree) })
}

/// Payload of `ask:show` (section 4.3 `AskPayload`).
fn ask_payload(id: &str, target: &AskTarget) -> crate::commands::AskPayload {
    let site = if target.browser { target.host.clone().or_else(|| target.title.clone()) } else { None };
    crate::commands::AskPayload { id: id.to_string(), name: target.name.clone(), site, seconds: ASK_SECONDS }
}

pub fn spawn_auto_mode(app: AppHandle) {
    std::thread::Builder::new()
        .name("el-auto-mode".into())
        .spawn(move || {
            let clock = Instant::now();
            loop {
                std::thread::sleep(Duration::from_millis(1_000));
                let settings = app.state::<crate::AppState>().settings();
                let trees = observe(settings.browser_automation);
                tick(&app, &settings, clock.elapsed().as_millis() as u64, &trees);
            }
        })
        .expect("spawn auto-mode thread");
}

/// One detection step: decisions, the start/stop state machine, the unknown-app chip and the explainer list.
fn tick(app: &AppHandle, settings: &crate::settings::Settings, now_ms: u64, trees: &[TreeAudio]) {
    let state = app.state::<crate::AppState>();
    let detect = &state.detect;
    *lock(&detect.looked) = Some(Instant::now());
    let recording = state.capture.is_recording();
    let rules = &settings.site_rules;
    // A viewer records nothing (v1.5): no automatic start and no "Record?" chip, as if auto-record were off.
    let can_record = state.viewer_refusal().is_none();
    let auto_mode = settings.auto_mode && can_record;
    let ask_unknown = settings.ask_unknown_apps && can_record;
    let mut verdicts: Vec<Verdict> = trees
        .iter()
        .map(|t| apply_app_switches(decide(t, rules, ask_unknown, auto_mode), &settings.auto_record_apps))
        .collect();

    let (event, recorded, chip) = {
        let mut call = lock(&detect.call);
        let mut ask = lock(&detect.ask);
        // "Ignore" on the chip: the tree counts as unknown until it releases the microphone.
        for (t, v) in trees.iter().zip(verdicts.iter_mut()) {
            if *v == Verdict::Ask && ask.is_suppressed(t.root_pid) {
                *v = Verdict::Ignore(if is_browser_tree(t) { IgnoreReason::UnknownSite } else { IgnoreReason::UnknownApp });
            }
        }
        let event = if auto_mode {
            call.tick(now_ms, trees, &verdicts)
        } else {
            call.reset();
            None
        };
        let chip = ask.tick(now_ms, trees, &verdicts, recording);
        (event, call.recorded(), chip)
    };

    // The watchdog (Phase 1C, A5): the meeting auto mode would record, recorded by now or started here.
    let detected = auto_mode.then(|| pick_candidate(trees, &verdicts).map(|(_, app)| app)).flatten().or(recorded.map(|(_, app)| app).filter(|_| auto_mode));
    if let Some(source) = lock(&detect.watchdog).tick(now_ms, detected, recording) {
        let app = app.clone();
        tauri::async_runtime::spawn(async move { crate::actions::start_backup(&app, source).await });
    }

    let silent_ms = state.capture.far_side_silent_ms();
    if let Some(payload) = lock(&detect.far_side).tick(silent_ms, settings.far_side_warning) {
        let _ = app.emit("capture:far_side", payload);
    }

    let users: Vec<MicUser> = trees.iter().zip(&verdicts).map(|(t, v)| explain(t, v, rules, recorded.filter(|_| recording))).collect();
    {
        let mut missed = lock(&detect.missed);
        *missed = keep_not_recorded(&users, std::mem::take(&mut *missed), chrono::Utc::now());
    }
    *lock(&detect.users) = users;
    *lock(&detect.meeting) = meeting_tree(trees, rules, recorded.filter(|_| recording));

    // Who each call app's call is with now, for the call rules checked before a recording starts.
    *lock(&detect.callers) = trees
        .iter()
        .filter_map(|t| {
            let source = classify::classify_any(&t.name, t.pfn.as_deref())?;
            let titles: Vec<String> = t.titles.iter().map(|s| strip_browser_suffix(s).to_string()).collect();
            callers::meeting_name(source, &titles).map(|name| (source.as_str().to_string(), name))
        })
        .collect();

    // Caller names (since v1.2): who the recorded call is with, from its app's window titles.
    if recording
        && let Some((pid, source)) = recorded
        && let Some(t) = trees.iter().find(|t| t.root_pid == pid)
    {
        let titles: Vec<String> = t.titles.iter().map(|s| strip_browser_suffix(s).to_string()).collect();
        if let Some(name) = callers::meeting_name(source, &titles) {
            crate::actions::set_recording_caller(app, name);
        }
    }

    match event {
        Some(MicUseEvent::Start(source)) => {
            let app = app.clone();
            tauri::async_runtime::spawn(async move {
                if let Err(e) = crate::actions::start_call_recording(&app, source.as_str(), true).await {
                    eprintln!("[echoledger] auto-mode start failed: {e}");
                    // A call that is not being recorded is never silent about it (Phase 1B).
                    crate::refusal::say(&app, &e.to_string(), crate::refusal::asked_by_meeting(&e));
                }
            });
        }
        Some(MicUseEvent::Stop(_)) => {
            let app = app.clone();
            tauri::async_runtime::spawn(async move {
                if let Err(e) = crate::actions::stop_recording(&app, true).await {
                    eprintln!("[echoledger] auto-mode stop failed: {e}");
                }
            });
        }
        None => {}
    }

    if let Some(pid) = chip
        && let Some(t) = trees.iter().find(|t| t.root_pid == pid)
    {
        let target = AskTarget {
            root_pid: pid,
            name: t.name.clone(),
            browser: is_browser_tree(t),
            host: t.urls.iter().find_map(|u| url_host(u)),
            title: t.titles.iter().map(|s| strip_browser_suffix(s).trim().to_string()).find(|s| !s.is_empty()),
        };
        let id = uuid::Uuid::new_v4().to_string();
        let payload = ask_payload(&id, &target);
        lock(&detect.asks).insert(id.clone(), target);
        crate::overlay::show_ask(app, payload); // the chip shares the after-call window
        let app = app.clone();
        tauri::async_runtime::spawn(async move {
            tokio::time::sleep(Duration::from_secs(ASK_SECONDS)).await;
            if lock(&app.state::<crate::AppState>().detect.asks).remove(&id).is_some() {
                crate::overlay::hide_ask(&app);
            }
        });
    }
}

/// The chip's answer: `record` starts a call recording labelled `other` that stops when the tree releases the
/// microphone; `ignore` silences the tree until it releases the microphone; `always_ignore` also adds a
/// never-record rule to Settings.
pub async fn answer_ask(app: &AppHandle, id: &str, answer: &str) -> AppResult<()> {
    if !matches!(answer, "record" | "ignore" | "always_ignore") {
        return Err(AppError::msg("Unknown answer."));
    }
    let state = app.state::<crate::AppState>();
    let target = lock(&state.detect.asks).remove(id);
    crate::overlay::hide_ask(app);
    match (answer, target) {
        ("record", target) => {
            if let Some(t) = &target {
                lock(&state.detect.call).adopt(t.root_pid);
            }
            // The person asked: no call rule refuses it, and it stops when the app lets go of the microphone.
            let captions = state.settings().captions_on_record;
            crate::actions::start_call_recording_as(app, SourceApp::Other.as_str(), crate::actions::Started::AskedOnChip, captions).await?;
        }
        ("ignore", Some(t)) => lock(&state.detect.ask).suppress(t.root_pid),
        ("always_ignore", Some(t)) => {
            lock(&state.detect.ask).suppress(t.root_pid);
            let rule = never_rule_for(uuid::Uuid::new_v4().to_string(), &t.name, t.browser, t.host.as_deref(), t.title.as_deref());
            if let Some(rule) = rule {
                let settings = state.update_settings(|s| {
                    let known = s.site_rules.iter().any(|r| {
                        r.app == NEVER && r.host == rule.host && r.title_contains == rule.title_contains && r.title_equals == rule.title_equals
                    });
                    if !known {
                        s.site_rules.push(rule);
                    }
                })?;
                let _ = app.emit("settings:changed", &settings);
            }
        }
        _ => {} // the chip already timed out: nothing to ignore
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    fn user(name: &str, decision: &str, reason: Option<&str>) -> MicUser {
        MicUser { name: name.into(), app: None, holds_mic: true, plays_audio: false, site: None, decision: decision.into(), reason: reason.map(str::to_string) }
    }

    fn at(minute: u32) -> chrono::DateTime<chrono::Utc> {
        chrono::DateTime::from_timestamp(1_763_380_800 + i64::from(minute) * 60, 0).unwrap()
    }

    fn call(name: &str, app: &str, reason: &str) -> MicUser {
        MicUser { app: Some(app.into()), plays_audio: true, ..user(name, "ignored", Some(reason)) }
    }

    /// One look after another: what is kept, and the line's app with what uses the microphone now.
    fn looks(steps: &[(u32, &[MicUser])]) -> (Vec<NotRecorded>, Option<NotRecorded>) {
        let mut kept = Vec::new();
        let mut now_using: &[MicUser] = &[];
        for (minute, users) in steps {
            kept = keep_not_recorded(users, kept, at(*minute));
            now_using = users;
        }
        let last = last_not_recorded(&kept, now_using);
        (kept, last)
    }

    #[test]
    fn the_last_app_not_recorded_is_kept_with_its_reason_and_time_after_it_stops() {
        let slack = user("slack.exe", "ignored", Some("unknown_app"));
        let meet = user("chrome.exe", "recording", None);
        // While it is listed, the list explains it: the line is for apps gone from it.
        let (kept, last) = looks(&[(0, &[meet.clone(), slack.clone()])]);
        assert_eq!(kept, vec![NotRecorded { user: slack.clone(), at: at(0) }]);
        assert_eq!(last, None);
        // Still there a minute later: the time moves on; then it stopped, and a recorded call came and went.
        let (_, last) = looks(&[(0, std::slice::from_ref(&slack)), (1, std::slice::from_ref(&slack)), (5, std::slice::from_ref(&meet)), (30, &[])]);
        assert_eq!(last, Some(NotRecorded { user: slack.clone(), at: at(1) }));
        // Auto-record off counts too; a later one comes first.
        let zoom = call("zoom.us", "zoom", "auto_off");
        let (_, last) = looks(&[(0, std::slice::from_ref(&slack)), (1, &[]), (40, &[MicUser { decision: "off".into(), ..zoom.clone() }]), (41, &[])]);
        assert_eq!(last.map(|k| (k.user.name, k.at)), Some(("zoom.us".to_string(), at(40))));
        // Recorded, about to be recorded or asked about is not "not recorded".
        for decision in ["recording", "auto_record", "ask"] {
            assert!(keep_not_recorded(&[user("x", decision, None)], Vec::new(), at(0)).is_empty(), "{decision}");
        }
    }

    #[test]
    fn an_app_always_on_the_microphone_never_hides_the_call_that_was_missed() {
        // A noise filter holds the microphone all day, listed first; Zoom's call is not recorded (its switch is off).
        let krisp = user("Krisp", "ignored", Some("unknown_app"));
        let zoom = call("zoom.us", "zoom", "app_off");
        let (kept, last) = looks(&[(0, &[krisp.clone(), zoom.clone()]), (20, &[krisp.clone(), zoom.clone()]), (21, std::slice::from_ref(&krisp))]);
        assert_eq!(kept.len(), 2, "one entry per app");
        // The call ended, the filter is still listed: the line explains Zoom, and is not hidden by the filter.
        assert_eq!(last, Some(NotRecorded { user: zoom.clone(), at: at(20) }));
        // A voice changer seen after the call, gone since: the call still comes first, as it looks like a call.
        let changer = user("Voice changer", "ignored", Some("dictation"));
        let (_, last) = looks(&[(0, &[krisp.clone(), zoom.clone()]), (20, &[krisp.clone(), changer.clone()]), (25, std::slice::from_ref(&krisp))]);
        assert_eq!(last.map(|k| k.user.name), Some("zoom.us".to_string()));
        // Nothing that looks like a call was missed: the newest of the rest.
        let (_, last) = looks(&[(0, std::slice::from_ref(&changer)), (3, std::slice::from_ref(&krisp)), (4, &[])]);
        assert_eq!(last, Some(NotRecorded { user: krisp.clone(), at: at(3) }));
        // A browser counts per website.
        let tab = |site: &str| MicUser { site: Some(site.into()), ..user("chrome.exe", "ignored", Some("unknown_site")) };
        let (kept, _) = looks(&[(0, &[tab("a.example")]), (1, &[tab("b.example")])]);
        assert_eq!(kept.len(), 2);
    }

    #[test]
    fn only_the_most_recent_apps_are_kept() {
        let mut kept = Vec::new();
        for minute in 0..12u32 {
            kept = keep_not_recorded(&[user(&format!("app{minute}"), "ignored", Some("unknown_app"))], kept, at(minute));
        }
        assert_eq!(kept.len(), KEPT_NOT_RECORDED);
        assert_eq!(kept[0].user.name, "app11");
        assert_eq!(kept[KEPT_NOT_RECORDED - 1].user.name, "app4");
    }

    #[test]
    fn it_says_names_reasons_and_a_time_only() {
        let kept = NotRecorded { user: user("slack.exe", "ignored", Some("unknown_app")), at: at(0) };
        let json = serde_json::to_value(&kept).unwrap();
        assert_eq!(json["at"], "2025-11-17T12:00:00Z");
        assert_eq!(json["user"]["name"], "slack.exe");
        assert_eq!(json["user"]["reason"], "unknown_app");
        let keys: Vec<&str> = json["user"].as_object().unwrap().keys().map(String::as_str).collect();
        assert_eq!(keys.len(), 7, "{keys:?}");
    }
}
