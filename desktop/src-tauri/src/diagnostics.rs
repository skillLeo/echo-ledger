//! Setup & health › Copy the report (v2.0, both platforms): what this computer's setup looks like, as plain text to
//! paste into a message to support or an admin. The Ready check, whether EchoLedger's windows stay out of a screen
//! share and the last Share Check, which shortcuts are held, and the app and system versions.
//!
//! Never in it: secrets (no token, key, server address, email or name), audio, transcripts, window titles or file paths.
//! The account check says only whether it is fine: its detail can name the workspace.

use chrono::{DateTime, Utc};

use crate::readiness::ReadyReport;

/// The last Share Check on this system and app version, as the report says it.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct ShareCheckLine {
    pub result: &'static str,
    pub at: DateTime<Utc>,
    /// Each method and its result (`window pass`).
    pub methods: Vec<String>,
}

/// One global shortcut: its Settings name, its keys as shown, and whether EchoLedger holds them.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct ShortcutLine {
    pub title: &'static str,
    /// None: no keys set.
    pub keys: Option<String>,
    /// Another app holds the keys.
    pub taken: bool,
}

/// What the report is made of (`gather`; tests build it by hand).
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct DiagnosticInputs {
    pub app_version: String,
    pub system: String,
    pub os_build: String,
    pub made_at: DateTime<Utc>,
    pub ready: Option<ReadyReport>,
    /// The privacy badge's words ("Private", "Not checked" …).
    pub protection: String,
    pub share_check: Option<ShareCheckLine>,
    pub shortcuts: Vec<ShortcutLine>,
}

/// The report (pure).
pub fn report_text(d: &DiagnosticInputs) -> String {
    let mut out = vec![
        "EchoLedger diagnostic report".to_string(),
        format!("App: {}", d.app_version),
        format!("System: {} ({})", d.system, d.os_build),
        format!("Made: {}", d.made_at.format("%Y-%m-%d %H:%M UTC")),
        String::new(),
    ];
    match &d.ready {
        Some(report) => {
            out.push(format!("Ready check ({}): {}", report.checked_at.format("%H:%M UTC"), if report.ready { "ready" } else { "not ready" }));
            for check in &report.checks {
                let state = if check.ok { "ok" } else if check.advisory { "worth a look" } else { "needs action" };
                // The account's detail can name the workspace: only its state.
                let detail = check.detail.as_deref().filter(|_| check.id != "account").map(|d| format!(": {d}")).unwrap_or_default();
                out.push(format!("  {} - {state}{detail}", check.label));
            }
        }
        None => out.push("Ready check: not run yet".into()),
    }
    out.push(String::new());
    out.push(format!("Hidden from screen sharing: {}", d.protection));
    out.push(match &d.share_check {
        Some(c) if c.methods.is_empty() => format!("Last Share Check: {} on {}", c.result, c.at.format("%Y-%m-%d %H:%M UTC")),
        Some(c) => format!("Last Share Check: {} on {} ({})", c.result, c.at.format("%Y-%m-%d %H:%M UTC"), c.methods.join(", ")),
        None => "Last Share Check: not run on this app and system version yet".into(),
    });
    out.push(String::new());
    out.push("Shortcuts:".into());
    for s in &d.shortcuts {
        let state = match (&s.keys, s.taken) {
            (None, _) => "not set".to_string(),
            (Some(keys), true) => format!("{keys}, held by another app"),
            (Some(keys), false) => format!("{keys}, registered"),
        };
        out.push(format!("  {}: {state}", s.title));
    }
    out.join("\n") + "\n"
}

/// The inputs on this computer now.
pub fn gather(app: &tauri::AppHandle) -> DiagnosticInputs {
    use tauri::Manager;
    let state = app.state::<crate::AppState>();
    let settings = state.settings();
    let store = crate::privacy::check::Store::load(&state.data_dir);
    let (os, version) = (crate::privacy::check::os_build(), crate::privacy::check::app_version());
    let share_check = store.last_for(&os, version).map(|r| ShareCheckLine {
        result: crate::privacy::check::wire(r.result),
        at: r.at,
        methods: r.methods.iter().map(|m| format!("{} {}", m.method, crate::privacy::check::wire(m.result))).collect(),
    });
    let taken: Vec<crate::shortcuts::Action> = state.shortcut_errors.lock().unwrap_or_else(|p| p.into_inner()).iter().map(|(a, _)| *a).collect();
    let macos = cfg!(target_os = "macos");
    let shortcuts = crate::shortcuts::Action::ALL
        .iter()
        .map(|action| {
            let keys = settings.shortcuts.get(action).map(|k| k.trim()).filter(|k| !k.is_empty()).map(|k| crate::shortcuts::label(k, macos));
            ShortcutLine { title: action.title(), keys, taken: taken.contains(action) }
        })
        .collect();
    let protection = if settings.hide_from_sharing == crate::settings::ShareHiding::Off {
        "off (Hide from screen sharing is off)".to_string()
    } else {
        crate::privacy::check::badge_now(app).label().to_lowercase()
    };
    DiagnosticInputs {
        app_version: version.to_string(),
        system: crate::privacy::check::system_name(),
        os_build: os,
        made_at: Utc::now(),
        ready: state.ready.lock().unwrap_or_else(|p| p.into_inner()).clone(),
        protection,
        share_check,
        shortcuts,
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::readiness::ReadyCheck;

    fn at(h: u32) -> DateTime<Utc> {
        DateTime::parse_from_rfc3339(&format!("2026-11-17T{h:02}:00:00Z")).unwrap().with_timezone(&Utc)
    }

    fn check(id: &'static str, label: &'static str, ok: bool, detail: Option<&str>, advisory: bool) -> ReadyCheck {
        ReadyCheck { id, label, ok, detail: detail.map(str::to_string), fix: None, critical: false, advisory }
    }

    fn inputs() -> DiagnosticInputs {
        DiagnosticInputs {
            app_version: "1.6.0".into(),
            system: "Windows 11".into(),
            os_build: "26100.2314".into(),
            made_at: at(10),
            ready: Some(ReadyReport {
                ready: false,
                checks: vec![
                    check("account", "Signed in", false, Some("You are a viewer in Acme Traders: ask an admin to record."), false),
                    check("microphone", "Microphone", false, Some("No microphone is connected."), false),
                    check("shortcuts", "Shortcuts", false, Some("Another app holds the keys of: Record now."), true),
                    check("overlay_protection", "Hidden from screen sharing", true, None, true),
                ],
                checked_at: at(9),
            }),
            protection: "private".into(),
            share_check: Some(ShareCheckLine { result: "pass", at: at(8), methods: vec!["window pass".into(), "display pass".into()] }),
            shortcuts: vec![
                ShortcutLine { title: "Record now", keys: Some("Ctrl+Alt+R".into()), taken: true },
                ShortcutLine { title: "Bookmark this moment", keys: Some("Ctrl+Shift+B".into()), taken: false },
                ShortcutLine { title: "Capture a voice note", keys: None, taken: false },
            ],
        }
    }

    #[test]
    fn the_report_says_the_setup_in_plain_lines() {
        let text = report_text(&inputs());
        assert_eq!(
            text,
            "EchoLedger diagnostic report\nApp: 1.6.0\nSystem: Windows 11 (26100.2314)\nMade: 2026-11-17 10:00 UTC\n\n\
             Ready check (09:00 UTC): not ready\n  Signed in - needs action\n  Microphone - needs action: No microphone is connected.\n  \
             Shortcuts - worth a look: Another app holds the keys of: Record now.\n  Hidden from screen sharing - ok\n\n\
             Hidden from screen sharing: private\nLast Share Check: pass on 2026-11-17 08:00 UTC (window pass, display pass)\n\n\
             Shortcuts:\n  Record now: Ctrl+Alt+R, held by another app\n  Bookmark this moment: Ctrl+Shift+B, registered\n  Capture a voice note: not set\n"
        );
    }

    #[test]
    fn the_report_never_names_the_workspace_and_says_what_was_not_run() {
        let text = report_text(&inputs());
        assert!(!text.contains("Acme"), "the account's detail can name the workspace");
        let bare = report_text(&DiagnosticInputs { ready: None, share_check: None, ..inputs() });
        assert!(bare.contains("Ready check: not run yet"));
        assert!(bare.contains("Last Share Check: not run on this app and system version yet"));
    }
}
