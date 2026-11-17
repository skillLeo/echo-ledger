//! The Ready check (Phase 1C, A1–A3): whether this computer will record the next call, both sides, by itself. One check
//! per thing that can stop it (signed in, a microphone, the microphone permission, the other side's sound, meeting
//! detection), each ok or a problem with a one-click fix. It runs at start, every few seconds and at once after a change
//! of settings; the sidebar and the tray show "Calls: Ready" or "Not ready — Fix", and the moment it breaks by itself (a
//! permission taken away, the microphone unplugged, detection stopped) a Critical notification says so, which the quiet
//! hours never hold back. The decisions are pure and tested; only `gather` and `apply_fix` touch the system.

use std::time::{Duration, Instant};

use serde::Serialize;
use tauri::{AppHandle, Emitter, Manager};

use crate::error::{AppError, AppResult};
use crate::permissions::{Access, Kind, Permissions};

/// How often the check runs by itself.
pub const EVERY: Duration = Duration::from_secs(10);
/// Meeting detection looks once a second; this long without a look means it stopped.
pub const DETECTION_STALE: Duration = Duration::from_secs(10);

/// A permission allowed before on this Mac that macOS now calls "not asked" (D-120), said as a possibility: an entry
/// System Settings still lists (another copy of EchoLedger) can keep macOS from asking.
pub const ALLOWED_BEFORE: &str = "Allowed before on this Mac. If System Settings already lists EchoLedger, remove it there; macOS asks again when a recording starts.";

/// A one-click fix: what the button says and what it does (`ready_fix`).
#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct Fix {
    pub label: &'static str,
    pub action: FixAction,
}

#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize, serde::Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum FixAction {
    SignIn,
    AllowMicrophone,
    AllowSystemAudio,
    OpenSoundSettings,
    SoftwareUpdate,
    TurnOnAutoMode,
    TurnOnApps,
    RestartDetection,
    /// Settings › Keyboard shortcuts.
    OpenShortcuts,
    /// Settings › Share privacy.
    OpenSharePrivacy,
}

#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct ReadyCheck {
    /// `account`, `microphone`, `microphone_permission`, `other_side`, `detection`.
    pub id: &'static str,
    pub label: &'static str,
    pub ok: bool,
    /// What is wrong, or a note on an ok check ("records all sound playing").
    pub detail: Option<String>,
    pub fix: Option<Fix>,
    /// Broke by itself (not a choice the person made): its breaking is a Critical notification.
    #[serde(skip)]
    pub critical: bool,
    /// Shown with its state and fix, but calls are recorded whatever it says: it never makes the computer "Not ready".
    pub advisory: bool,
}

#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct ReadyReport {
    pub ready: bool,
    pub checks: Vec<ReadyCheck>,
    pub checked_at: chrono::DateTime<chrono::Utc>,
}

impl ReadyReport {
    /// What keeps the computer from being ready (an advisory check never does).
    pub fn problems(&self) -> impl Iterator<Item = &ReadyCheck> {
        self.checks.iter().filter(|c| !c.ok && !c.advisory)
    }
}

/// What the check looks at, read from the system by `gather` (fakes in tests).
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct ReadyInputs {
    pub macos: bool,
    pub paired: bool,
    /// The viewer sentence when this computer may not record.
    pub viewer: Option<String>,
    pub microphones: usize,
    /// The microphone chosen in Settings is not connected (the default is used).
    pub chosen_mic_missing: bool,
    pub permissions: Permissions,
    /// macOS: what was allowed before on this Mac and is "not asked" now ([`crate::permissions::Memory::allowed_before`]).
    pub allowed_before: Vec<Kind>,
    /// macOS (major, minor) when known.
    pub mac_version: Option<(u32, u32)>,
    /// Windows without per-app capture: the sentence it records with.
    pub whole_system_note: Option<String>,
    pub auto_mode: bool,
    /// Call apps switched off in Settings → Recording, by label.
    pub apps_off: Vec<String>,
    /// Meeting detection looked at the microphone recently.
    pub detection_alive: bool,
    /// The shortcuts another app holds, by their Settings names.
    pub shortcuts_taken: Vec<&'static str>,
    /// Whether EchoLedger's windows stay out of a screen share here; none on a system without them.
    pub protection: Option<Protection>,
}

/// Whether EchoLedger's windows stay out of a screen share on this computer (the privacy badge, and the setting).
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Protection {
    /// Checked: left out of a share.
    Private,
    /// Windows before 2004: a black box where EchoLedger is; its text stays private.
    BlackBox,
    /// Not checked on this system and app version yet (the Share Check runs by itself).
    NotChecked,
    /// The last check saw them in a share.
    Visible,
    /// Hide from screen sharing is off.
    Off,
}

fn ok(id: &'static str, label: &'static str, detail: Option<String>) -> ReadyCheck {
    ReadyCheck { id, label, ok: true, detail, fix: None, critical: false, advisory: false }
}

fn problem(id: &'static str, label: &'static str, detail: impl Into<String>, fix: Option<Fix>, critical: bool) -> ReadyCheck {
    ReadyCheck { id, label, ok: false, detail: Some(detail.into()), fix, critical, advisory: false }
}

/// A check that never makes the computer "Not ready" (calls are recorded whatever it says).
fn advisory(check: ReadyCheck) -> ReadyCheck {
    ReadyCheck { advisory: true, critical: false, ..check }
}

/// The checks for these inputs (pure).
pub fn evaluate(inputs: &ReadyInputs, now: chrono::DateTime<chrono::Utc>) -> ReadyReport {
    let mut checks = Vec::new();
    if !inputs.paired {
        checks.push(problem("account", "Signed in", "Sign in to record calls.", Some(Fix { label: "Sign in", action: FixAction::SignIn }), false));
    } else if let Some(viewer) = &inputs.viewer {
        checks.push(problem("account", "Signed in", viewer.clone(), None, false));
    } else {
        checks.push(ok("account", "Signed in", None));
    }

    let sound = Fix { label: "Open sound settings", action: FixAction::OpenSoundSettings };
    checks.push(if inputs.microphones == 0 {
        problem("microphone", "Microphone", "No microphone is connected.", Some(sound), true)
    } else if inputs.chosen_mic_missing {
        ok("microphone", "Microphone", Some("The microphone chosen in Settings is not connected, so the default one is used.".into()))
    } else {
        ok("microphone", "Microphone", None)
    });

    checks.push(match inputs.permissions.microphone {
        Access::Denied => problem(
            "microphone_permission",
            "Microphone allowed",
            "EchoLedger may not use the microphone: your voice would be missing.",
            Some(Fix { label: if inputs.macos { "Open System Settings" } else { "Open privacy settings" }, action: FixAction::AllowMicrophone }),
            true,
        ),
        Access::Allowed => ok("microphone_permission", "Microphone allowed", None),
        // Allowed before, "not asked" now: never "Microphone allowed" (D-120).
        Access::Unknown if inputs.allowed_before.contains(&Kind::Microphone) => problem(
            "microphone_permission",
            "Microphone allowed",
            ALLOWED_BEFORE,
            Some(Fix { label: "Open System Settings", action: FixAction::AllowMicrophone }),
            false,
        ),
        // Only Screen Recording waits for a reopen; the microphone is read afresh every time.
        Access::Unknown | Access::Reopen => ok("microphone_permission", "Microphone allowed", inputs.macos.then(|| "macOS asks the first time a recording starts.".to_string())),
    });

    let tap_too_old = inputs.macos && inputs.mac_version.is_some_and(|v| v < crate::mac_check::CALL_AUDIO_FROM);
    checks.push(if tap_too_old {
        problem(
            "other_side",
            "The other side of calls",
            "This macOS cannot record the other side of calls. Update to macOS 14.2 or later.",
            Some(Fix { label: "Open Software Update", action: FixAction::SoftwareUpdate }),
            false,
        )
    } else if inputs.permissions.system_audio == Access::Denied {
        problem(
            "other_side",
            "The other side of calls",
            "EchoLedger may not record this computer's sound: only your voice would be recorded.",
            Some(Fix { label: "Open System Settings", action: FixAction::AllowSystemAudio }),
            true,
        )
    } else if inputs.permissions.system_audio == Access::Unknown && inputs.allowed_before.contains(&Kind::SystemAudio) {
        problem("other_side", "The other side of calls", ALLOWED_BEFORE, Some(Fix { label: "Open System Settings", action: FixAction::AllowSystemAudio }), false)
    } else {
        ok("other_side", "The other side of calls", inputs.whole_system_note.as_ref().map(|n| format!("{n}.")))
    });

    checks.push(if !inputs.auto_mode {
        problem(
            "detection",
            "Meetings recorded by themselves",
            "Record calls automatically is off: calls are recorded only when you start them.",
            Some(Fix { label: "Turn on", action: FixAction::TurnOnAutoMode }),
            false,
        )
    } else if !inputs.detection_alive {
        problem("detection", "Meetings recorded by themselves", "Meeting detection stopped.", Some(Fix { label: "Start it again", action: FixAction::RestartDetection }), true)
    } else if !inputs.apps_off.is_empty() {
        let fix = Fix { label: "Turn them on", action: FixAction::TurnOnApps };
        ReadyCheck { fix: Some(fix), ..ok("detection", "Meetings recorded by themselves", Some(format!("Not recorded by themselves: {}.", inputs.apps_off.join(", ")))) }
    } else {
        ok("detection", "Meetings recorded by themselves", None)
    });

    let shortcuts = Fix { label: "Change shortcuts", action: FixAction::OpenShortcuts };
    checks.push(advisory(if inputs.shortcuts_taken.is_empty() {
        ok("shortcuts", "Shortcuts", None)
    } else {
        problem("shortcuts", "Shortcuts", format!("Another app holds the keys of: {}. Choose other keys for them.", inputs.shortcuts_taken.join(", ")), Some(shortcuts), false)
    }));

    if let Some(protection) = inputs.protection {
        const LABEL: &str = "Hidden from screen sharing";
        let privacy = Fix { label: "Open Share privacy", action: FixAction::OpenSharePrivacy };
        checks.push(advisory(match protection {
            Protection::Private => ok("overlay_protection", LABEL, None),
            Protection::BlackBox => ok("overlay_protection", LABEL, Some("This Windows version shows a black box where EchoLedger is: its text stays private.".into())),
            Protection::NotChecked => ok("overlay_protection", LABEL, Some("Not checked on this computer yet: EchoLedger checks it by itself.".into())),
            Protection::Visible => problem("overlay_protection", LABEL, "The last check saw EchoLedger's windows in a screen share on this computer.", Some(privacy), false),
            Protection::Off => problem("overlay_protection", LABEL, "Hide from screen sharing is off: EchoLedger's windows show in a screen share.", Some(Fix { label: "Turn it on", ..privacy }), false),
        }));
    }

    let ready = checks.iter().all(|c| c.ok || c.advisory);
    ReadyReport { ready, checks, checked_at: now }
}

/// Remembers what was broken, to say only what broke since the last look (pure).
#[derive(Debug, Default)]
pub struct ReadyWatch {
    last: Option<Vec<&'static str>>,
}

impl ReadyWatch {
    /// The Critical notification's text when a check that breaks by itself has broken since the last report; nothing
    /// for the first report (the window shows what is wrong at start) or while it stays broken.
    pub fn observe(&mut self, report: &ReadyReport) -> Option<(String, String)> {
        let broken: Vec<&'static str> = report.problems().filter(|c| c.critical).map(|c| c.id).collect();
        let before = self.last.replace(broken.clone())?;
        let newly = report.problems().find(|c| c.critical && !before.contains(&c.id))?;
        Some(("Calls will not be recorded fully".into(), format!("{} Open EchoLedger to fix it.", newly.detail.clone().unwrap_or_default())))
    }
}

/// The inputs on this computer now.
pub fn gather(app: &AppHandle) -> ReadyInputs {
    let state = app.state::<crate::AppState>();
    let settings = state.settings();
    let devices = crate::audio::platform::input_devices();
    let chosen_mic_missing = settings.input_device.as_deref().is_some_and(|id| !devices.iter().any(|d| d.id == id));
    let apps_off = settings
        .auto_record_apps
        .iter()
        .filter(|(_, on)| !**on)
        .filter_map(|(wire, _)| crate::detect::classify::SourceApp::from_wire(wire))
        .map(|app| app.label().to_string())
        .collect::<std::collections::BTreeSet<_>>()
        .into_iter()
        .collect();
    #[cfg(target_os = "macos")]
    let mac_version = Some({
        let (major, minor, _) = crate::mac_check::native::version();
        (major, minor)
    });
    #[cfg(not(target_os = "macos"))]
    let mac_version = None;
    let permissions = state.permissions_now();
    ReadyInputs {
        macos: cfg!(target_os = "macos"),
        paired: state.is_paired(),
        viewer: state.viewer_refusal(),
        microphones: devices.len(),
        chosen_mic_missing,
        allowed_before: state.permissions_allowed_before(&permissions),
        permissions,
        mac_version,
        whole_system_note: crate::audio::platform::system_audio_note().map(str::to_string),
        auto_mode: settings.auto_mode,
        apps_off,
        detection_alive: state.detect.looked_within(DETECTION_STALE),
        shortcuts_taken: state.shortcut_errors.lock().unwrap_or_else(|p| p.into_inner()).iter().map(|(action, _)| action.title()).collect(),
        protection: protection_now(app, &settings),
    }
}

/// The privacy badge as the Ready check says it; none on a system without hiding.
fn protection_now(app: &AppHandle, settings: &crate::settings::Settings) -> Option<Protection> {
    use crate::privacy::status::Badge;
    if !(cfg!(windows) || cfg!(target_os = "macos")) {
        return None;
    }
    if settings.hide_from_sharing == crate::settings::ShareHiding::Off {
        return Some(Protection::Off);
    }
    Some(match crate::privacy::check::badge_now(app) {
        Badge::Private => Protection::Private,
        Badge::BlackBox => Protection::BlackBox,
        Badge::NotChecked => Protection::NotChecked,
        Badge::Visible => Protection::Visible,
    })
}

/// Runs the check now: keeps the report, tells the windows (`ready:changed`) and the tray, and sends the Critical
/// notification when something broke by itself.
pub fn run(app: &AppHandle) -> ReadyReport {
    let state = app.state::<crate::AppState>();
    let report = evaluate(&gather(app), chrono::Utc::now());
    let alert = state.ready_watch.lock().unwrap_or_else(|p| p.into_inner()).observe(&report);
    let changed = state.ready.lock().unwrap_or_else(|p| p.into_inner()).replace(report.clone()).is_none_or(|old| old.checks != report.checks);
    if let Some((title, body)) = alert
        && state.is_paired()
    {
        crate::notifications::send(app, crate::notifications::Category::CallCapture, &title, &body);
    }
    if changed {
        let _ = app.emit("ready:changed", &report);
        crate::actions::emit_status(app);
    }
    report
}

/// The check by itself: at start, every [`EVERY`] and at once when `ready_wake` is notified (a setting changed).
pub fn spawn(app: AppHandle) {
    tauri::async_runtime::spawn(async move {
        // Meeting detection has looked once before the first check.
        tokio::time::sleep(Duration::from_secs(2)).await;
        loop {
            let runner = app.clone();
            let _ = tauri::async_runtime::spawn_blocking(move || run(&runner)).await;
            let state = app.state::<crate::AppState>();
            tokio::select! {
                _ = tokio::time::sleep(EVERY) => {}
                _ = state.ready_wake.notified() => {}
            }
        }
    });
}

/// Asks for a check soon (after a change of settings).
pub fn wake(app: &AppHandle) {
    if let Some(state) = app.try_state::<crate::AppState>() {
        state.ready_wake.notify_one();
    }
}

/// What a fix button does; the check runs again after it.
pub fn apply_fix(app: &AppHandle, action: FixAction) -> AppResult<()> {
    use tauri_plugin_opener::OpenerExt;
    let state = app.state::<crate::AppState>();
    let macos = cfg!(target_os = "macos");
    let open = |kind: &str| -> AppResult<()> {
        let url = fix_url(kind, macos).ok_or_else(|| AppError::msg("There is no setting for that here."))?;
        app.opener().open_url(url, None::<&str>).map_err(|e| AppError::msg(e.to_string()))
    };
    match action {
        FixAction::SignIn => {
            crate::tray::show_main(app);
            Ok(())
        }
        FixAction::AllowMicrophone => {
            let now = state.permissions_now();
            // Allowed before on this Mac (D-120): the pane, where an entry System Settings still lists can be removed.
            if now.microphone == Access::Unknown && !state.permissions_allowed_before(&now).contains(&Kind::Microphone) {
                crate::permissions::request(Kind::Microphone);
                Ok(())
            } else {
                open("microphone")
            }
        }
        FixAction::AllowSystemAudio => open("system_audio"),
        FixAction::OpenSoundSettings => open("sound"),
        FixAction::SoftwareUpdate => open("software_update"),
        FixAction::TurnOnAutoMode | FixAction::TurnOnApps => {
            let settings = state.update_settings(|s| {
                s.auto_mode = true;
                if action == FixAction::TurnOnApps {
                    s.auto_record_apps.clear();
                }
            })?;
            let _ = app.emit("settings:changed", &settings);
            Ok(())
        }
        FixAction::OpenShortcuts => {
            crate::actions::open_settings(app, "shortcuts");
            Ok(())
        }
        FixAction::OpenSharePrivacy => {
            crate::actions::open_settings(app, "share_privacy");
            Ok(())
        }
        FixAction::RestartDetection => {
            if !state.detect.looked_within(DETECTION_STALE) {
                crate::detect::spawn_auto_mode(app.clone());
            }
            Ok(())
        }
    }
}

/// The settings page a fix opens (pure).
pub fn fix_url(kind: &str, macos: bool) -> Option<&'static str> {
    match (kind, macos) {
        ("sound", false) => Some("ms-settings:sound"),
        ("software_update", false) => Some("ms-settings:windowsupdate"),
        _ => crate::commands::privacy_settings_url(kind, macos),
    }
}

/// Detection's heartbeat, for [`ReadyInputs::detection_alive`].
pub fn looked_within(last: Option<Instant>, now: Instant, limit: Duration) -> bool {
    last.is_some_and(|at| now.saturating_duration_since(at) <= limit)
}

#[cfg(test)]
mod tests {
    use super::*;

    const ALLOWED: Permissions = Permissions { microphone: Access::Allowed, system_audio: Access::Allowed, screen: Access::Allowed };

    fn good(macos: bool) -> ReadyInputs {
        ReadyInputs {
            macos,
            paired: true,
            viewer: None,
            microphones: 1,
            chosen_mic_missing: false,
            permissions: ALLOWED,
            allowed_before: Vec::new(),
            mac_version: macos.then_some((15, 1)),
            whole_system_note: None,
            auto_mode: true,
            apps_off: Vec::new(),
            detection_alive: true,
            shortcuts_taken: Vec::new(),
            protection: Some(Protection::Private),
        }
    }

    fn at() -> chrono::DateTime<chrono::Utc> {
        chrono::DateTime::from_timestamp(1_700_000_000, 0).unwrap()
    }

    fn problems(inputs: &ReadyInputs) -> Vec<(&'static str, Option<FixAction>)> {
        evaluate(inputs, at()).problems().map(|c| (c.id, c.fix.map(|f| f.action))).collect()
    }

    #[test]
    fn a_computer_with_everything_is_ready() {
        for macos in [true, false] {
            let report = evaluate(&good(macos), at());
            assert!(report.ready);
            assert_eq!(report.checks.iter().map(|c| c.id).collect::<Vec<_>>(), ["account", "microphone", "microphone_permission", "other_side", "detection", "shortcuts", "overlay_protection"]);
        }
    }

    #[test]
    fn taken_shortcuts_and_screen_sharing_are_said_but_never_make_the_computer_not_ready() {
        let taken = ReadyInputs { shortcuts_taken: vec!["Record now", "Bookmark this moment"], ..good(false) };
        let report = evaluate(&taken, at());
        assert!(report.ready, "calls are recorded whatever the shortcuts");
        let check = report.checks.iter().find(|c| c.id == "shortcuts").unwrap();
        assert!(!check.ok && check.advisory && !check.critical);
        assert_eq!(check.detail.as_deref(), Some("Another app holds the keys of: Record now, Bookmark this moment. Choose other keys for them."));
        assert_eq!(check.fix.map(|f| f.action), Some(FixAction::OpenShortcuts));

        let protection = |p| {
            let report = evaluate(&ReadyInputs { protection: Some(p), ..good(true) }, at());
            assert!(report.ready, "{p:?}");
            let c = report.checks.into_iter().find(|c| c.id == "overlay_protection").unwrap();
            (c.ok, c.fix.map(|f| f.action), c.advisory)
        };
        assert_eq!(protection(Protection::Private), (true, None, true));
        assert_eq!(protection(Protection::BlackBox), (true, None, true));
        assert_eq!(protection(Protection::NotChecked), (true, None, true), "it checks by itself: nothing to do yet");
        assert_eq!(protection(Protection::Visible), (false, Some(FixAction::OpenSharePrivacy), true));
        assert_eq!(protection(Protection::Off), (false, Some(FixAction::OpenSharePrivacy), true));
        assert!(!evaluate(&ReadyInputs { protection: None, ..good(false) }, at()).checks.iter().any(|c| c.id == "overlay_protection"), "none on a system without hiding");

        // An advisory check never sends the Critical notification.
        let mut watch = ReadyWatch::default();
        assert_eq!(watch.observe(&evaluate(&good(false), at())), None);
        assert_eq!(watch.observe(&evaluate(&taken, at())), None);
        // A real problem still makes it not ready.
        assert!(!evaluate(&ReadyInputs { microphones: 0, ..taken }, at()).ready);
    }

    #[test]
    fn each_problem_comes_with_its_fix() {
        assert_eq!(problems(&ReadyInputs { paired: false, ..good(false) }), [("account", Some(FixAction::SignIn))]);
        assert_eq!(problems(&ReadyInputs { microphones: 0, ..good(false) }), [("microphone", Some(FixAction::OpenSoundSettings))]);
        let no_mic = ReadyInputs { permissions: Permissions { microphone: Access::Denied, ..ALLOWED }, ..good(true) };
        assert_eq!(problems(&no_mic), [("microphone_permission", Some(FixAction::AllowMicrophone))]);
        let no_system = ReadyInputs { permissions: Permissions { system_audio: Access::Denied, ..ALLOWED }, ..good(true) };
        assert_eq!(problems(&no_system), [("other_side", Some(FixAction::AllowSystemAudio))]);
        assert_eq!(problems(&ReadyInputs { mac_version: Some((14, 1)), ..good(true) }), [("other_side", Some(FixAction::SoftwareUpdate))]);
        assert_eq!(problems(&ReadyInputs { auto_mode: false, ..good(false) }), [("detection", Some(FixAction::TurnOnAutoMode))]);
        assert_eq!(problems(&ReadyInputs { detection_alive: false, ..good(false) }), [("detection", Some(FixAction::RestartDetection))]);
    }

    #[test]
    fn notes_do_not_make_it_unready() {
        let windows10 = ReadyInputs { whole_system_note: Some("This Windows version records all sound playing on this computer, not just the call".into()), ..good(false) };
        let report = evaluate(&windows10, at());
        assert!(report.ready);
        assert!(report.checks[3].detail.as_deref().unwrap().ends_with("not just the call."));
        let fallback = evaluate(&ReadyInputs { chosen_mic_missing: true, ..good(false) }, at());
        assert!(fallback.ready, "the default microphone records");
        let some_off = evaluate(&ReadyInputs { apps_off: vec!["Zoom".into()], ..good(false) }, at());
        assert!(some_off.ready);
        assert_eq!(some_off.checks[4].fix.map(|f| f.action), Some(FixAction::TurnOnApps));
        assert_eq!(some_off.checks[4].detail.as_deref(), Some("Not recorded by themselves: Zoom."));
        let unknown = evaluate(&ReadyInputs { permissions: Permissions { microphone: Access::Unknown, ..ALLOWED }, ..good(true) }, at());
        assert!(unknown.ready, "macOS asks when the first recording starts");
    }

    #[test]
    fn a_permission_allowed_before_but_not_asked_now_is_not_ready() {
        // D-120: never "Microphone allowed" / "Calls: Ready" while macOS calls it "not asked" after allowing it before.
        let mic = ReadyInputs { permissions: Permissions { microphone: Access::Unknown, ..ALLOWED }, allowed_before: vec![Kind::Microphone], ..good(true) };
        let report = evaluate(&mic, at());
        assert!(!report.ready);
        assert_eq!(problems(&mic), [("microphone_permission", Some(FixAction::AllowMicrophone))]);
        assert_eq!(report.checks[2].detail.as_deref(), Some(ALLOWED_BEFORE));
        assert_eq!(report.checks[2].fix.map(|f| f.label), Some("Open System Settings"));
        let system = ReadyInputs { permissions: Permissions { system_audio: Access::Unknown, ..ALLOWED }, allowed_before: vec![Kind::SystemAudio], ..good(true) };
        assert_eq!(problems(&system), [("other_side", Some(FixAction::AllowSystemAudio))]);
        // A possibility, not a breakage seen: no Critical notification.
        let mut watch = ReadyWatch::default();
        watch.observe(&evaluate(&good(true), at()));
        assert_eq!(watch.observe(&report), None);
        // Never allowed before: still ready, macOS asks at the first recording.
        assert!(evaluate(&ReadyInputs { allowed_before: Vec::new(), ..mic }, at()).ready);
        // Screen Recording is not part of the check.
        assert!(evaluate(&ReadyInputs { permissions: Permissions { screen: Access::Reopen, ..ALLOWED }, allowed_before: vec![Kind::Screen], ..good(true) }, at()).ready);
    }

    #[test]
    fn a_viewer_is_told_why_without_a_fix() {
        let viewer = ReadyInputs { viewer: Some("You are a viewer in Acme.".into()), ..good(false) };
        assert_eq!(problems(&viewer), [("account", None)]);
    }

    #[test]
    fn the_critical_alert_comes_the_moment_it_breaks_by_itself_and_once() {
        let mut watch = ReadyWatch::default();
        assert_eq!(watch.observe(&evaluate(&ReadyInputs { microphones: 0, ..good(false) }, at())), None, "the first look only learns");
        let mut watch = ReadyWatch::default();
        assert_eq!(watch.observe(&evaluate(&good(true), at())), None);
        let revoked = evaluate(&ReadyInputs { permissions: Permissions { system_audio: Access::Denied, ..ALLOWED }, ..good(true) }, at());
        let (title, body) = watch.observe(&revoked).expect("a permission taken away is said");
        assert_eq!(title, "Calls will not be recorded fully");
        assert!(body.starts_with("EchoLedger may not record this computer's sound"));
        assert_eq!(watch.observe(&revoked), None, "said once while it stays broken");
        assert_eq!(watch.observe(&evaluate(&good(true), at())), None);
        assert!(watch.observe(&revoked).is_some(), "said again if it breaks again");
    }

    #[test]
    fn a_choice_the_person_made_is_not_an_alert() {
        let mut watch = ReadyWatch::default();
        watch.observe(&evaluate(&good(false), at()));
        assert_eq!(watch.observe(&evaluate(&ReadyInputs { auto_mode: false, ..good(false) }, at())), None);
        assert_eq!(watch.observe(&evaluate(&ReadyInputs { paired: false, ..good(false) }, at())), None);
    }

    #[test]
    fn detection_counts_as_alive_while_it_looks() {
        let now = Instant::now();
        assert!(looked_within(Some(now), now + Duration::from_secs(3), DETECTION_STALE));
        assert!(!looked_within(Some(now), now + Duration::from_secs(11), DETECTION_STALE));
        assert!(!looked_within(None, now, DETECTION_STALE));
    }

    #[test]
    fn fixes_open_the_right_settings() {
        assert_eq!(fix_url("sound", false), Some("ms-settings:sound"));
        assert_eq!(fix_url("software_update", false), Some("ms-settings:windowsupdate"));
        assert!(fix_url("sound", true).unwrap().contains("Sound-Settings"));
        assert!(fix_url("system_audio", true).unwrap().contains("Privacy_ScreenCapture"));
        assert_eq!(fix_url("system_audio", false), None, "Windows has no such permission");
    }
}
