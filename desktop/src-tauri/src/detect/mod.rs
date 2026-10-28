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

/// One line of the Record screen's explainer: an application holding the microphone and what auto mode does
/// with it ("Holds mic ✓ · Playing audio ✓ · Identified as Google Meet · Decision: auto-record").
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
}

fn lock<T>(m: &Mutex<T>) -> MutexGuard<'_, T> {
    m.lock().unwrap_or_else(|p| p.into_inner())
}

impl DetectState {
    /// The last observation with its decisions (refreshed once per second by the loop).
    pub fn mic_users(&self) -> Vec<MicUser> {
        lock(&self.users).clone()
    }
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
    let recording = state.capture.is_recording();
    let rules = &settings.site_rules;
    let mut verdicts: Vec<Verdict> = trees
        .iter()
        .map(|t| apply_app_switches(decide(t, rules, settings.ask_unknown_apps, settings.auto_mode), &settings.auto_record_apps))
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
        let event = if settings.auto_mode {
            call.tick(now_ms, trees, &verdicts)
        } else {
            call.reset();
            None
        };
        let chip = ask.tick(now_ms, trees, &verdicts, recording);
        (event, call.recorded(), chip)
    };

    let silent_ms = state.capture.far_side_silent_ms();
    if let Some(payload) = lock(&detect.far_side).tick(silent_ms, settings.far_side_warning) {
        let _ = app.emit("capture:far_side", payload);
    }

    *lock(&detect.users) = trees.iter().zip(&verdicts).map(|(t, v)| explain(t, v, rules, recorded.filter(|_| recording))).collect();

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
        crate::overlay::show_ask(app, payload); // the chip shares the review card's window
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
            crate::actions::start_call_recording(app, SourceApp::Other.as_str(), true).await?;
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
