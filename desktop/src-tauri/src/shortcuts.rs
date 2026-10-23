//! User-configurable global shortcuts. One plugin handler dispatches to actions; (un)registration happens only
//! from async contexts (never inside the handler). docs/research/desktop-tauri.md §4.2.

use std::collections::HashMap;
use std::sync::Mutex;

use serde::{Deserialize, Serialize};
use tauri::{AppHandle, Manager};
use tauri_plugin_global_shortcut::{GlobalShortcutExt, Shortcut, ShortcutState};

#[derive(Clone, Copy, Debug, PartialEq, Eq, Hash, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum Action {
    ToggleCaptions,
    ToggleSaving,
    CopyLast2Min,
    Dictation,
    VoiceNote,
}

impl Action {
    pub const ALL: [Action; 5] = [Action::ToggleCaptions, Action::ToggleSaving, Action::CopyLast2Min, Action::Dictation, Action::VoiceNote];

    /// Spec defaults (Cmd on macOS). Ctrl+Shift+C/R collide with browser DevTools/hard-reload, hence configurable.
    pub fn default_accelerator(self) -> Option<&'static str> {
        match self {
            Action::ToggleCaptions => Some("CommandOrControl+Shift+Space"),
            Action::ToggleSaving => Some("CommandOrControl+Shift+R"),
            Action::CopyLast2Min => Some("CommandOrControl+Shift+C"),
            Action::Dictation => Some("CommandOrControl+Shift+D"),
            Action::VoiceNote => None,
        }
    }
}

pub fn default_map() -> HashMap<Action, String> {
    Action::ALL.iter().filter_map(|a| a.default_accelerator().map(|s| (*a, s.to_string()))).collect()
}

/// Parses an accelerator ("Ctrl+Shift+Space"); pure wrapper around the plugin's FromStr.
pub fn parse(accelerator: &str) -> Result<Shortcut, String> {
    accelerator.parse::<Shortcut>().map_err(|e| format!("Invalid shortcut \"{accelerator}\": {e:?}"))
}

/// Actions whose accelerators collide with another action (pure).
pub fn duplicates(map: &HashMap<Action, String>) -> Vec<Action> {
    let mut seen: HashMap<Shortcut, Action> = HashMap::new();
    let mut dupes = Vec::new();
    for action in Action::ALL {
        if let Some(Ok(shortcut)) = map.get(&action).map(|s| parse(s)) {
            if seen.insert(shortcut, action).is_some() {
                dupes.push(action);
            }
        }
    }
    dupes
}

#[derive(Default)]
pub struct Bindings(pub Mutex<HashMap<Action, Shortcut>>);

pub fn plugin() -> tauri::plugin::TauriPlugin<tauri::Wry> {
    tauri_plugin_global_shortcut::Builder::new()
        .with_handler(|app, shortcut, event| {
            let action = app.state::<Bindings>().0.lock().unwrap().iter().find(|(_, s)| *s == shortcut).map(|(a, _)| *a);
            let Some(action) = action else { return };
            let state = event.state();
            let handle = app.app_handle().clone();
            tauri::async_runtime::spawn(async move {
                crate::actions::on_shortcut(handle, action, state == ShortcutState::Pressed).await;
            });
        })
        .build()
}

/// Registers every configured shortcut. Returns (action, error) for shortcuts taken by other apps.
pub fn register_all(app: &AppHandle, map: &HashMap<Action, String>) -> Vec<(Action, String)> {
    let gs = app.global_shortcut();
    let _ = gs.unregister_all();
    let mut errors = Vec::new();
    let mut bound = HashMap::new();
    for action in Action::ALL {
        let Some(accelerator) = map.get(&action) else { continue };
        if accelerator.trim().is_empty() {
            continue;
        }
        match parse(accelerator).and_then(|s| gs.register(s).map(|_| s).map_err(|e| e.to_string())) {
            Ok(shortcut) => {
                bound.insert(action, shortcut);
            }
            Err(e) => errors.push((action, e)),
        }
    }
    *app.state::<Bindings>().0.lock().unwrap() = bound;
    errors
}

/// Rebinds one action: registers the new accelerator before dropping the old one (old survives failures).
pub fn rebind(app: &AppHandle, action: Action, accelerator: &str) -> Result<(), String> {
    let gs = app.global_shortcut();
    let old = app.state::<Bindings>().0.lock().unwrap().get(&action).copied();
    if accelerator.trim().is_empty() {
        if let Some(o) = old {
            let _ = gs.unregister(o);
        }
        app.state::<Bindings>().0.lock().unwrap().remove(&action);
        return Ok(());
    }
    let new = parse(accelerator)?;
    if old == Some(new) {
        return Ok(());
    }
    if app.state::<Bindings>().0.lock().unwrap().iter().any(|(a, s)| *a != action && *s == new) {
        return Err("That shortcut is already used by another EchoLedger action.".into());
    }
    gs.register(new).map_err(|e| format!("Could not register (maybe used by another app): {e}"))?;
    if let Some(o) = old {
        let _ = gs.unregister(o);
    }
    app.state::<Bindings>().0.lock().unwrap().insert(action, new);
    Ok(())
}
