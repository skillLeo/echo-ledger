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
    /// Bookmark the current recording (B3).
    Mark,
    /// Record now (Phase 1C): the meeting on screen with both sides, else a voice note; again to stop.
    RecordNow,
    /// "Hide all EchoLedger windows" (v1.8.1, `privacy`): every EchoLedger window off the screen, again to bring them back.
    HideAll,
    /// "Capture screen now" (v1.8 P0.1): the running recording keeps the screen at its next sample; a flash confirms it.
    CaptureScreen,
    /// Live Translate (v1.3): Edit / Lock the overlay and hide it. Registered only while Live Translate runs (never
    /// in [`Action::ALL`]): a global Ctrl+Shift+T would otherwise take "reopen closed tab" from every browser.
    TranslateLock,
    TranslateHide,
}

impl Action {
    pub const ALL: [Action; 9] = [
        Action::RecordNow,
        Action::ToggleCaptions,
        Action::ToggleSaving,
        Action::CopyLast2Min,
        Action::Dictation,
        Action::VoiceNote,
        Action::Mark,
        Action::HideAll,
        Action::CaptureScreen,
    ];

    /// Defaults (Cmd on macOS). Copying uses Alt+Shift: Ctrl+Shift+C opens DevTools in every browser (B19). Record now
    /// is Ctrl / ⌘ + Alt + R (Phase 1C, chosen by Hassam over Ctrl+Shift+R, the browsers' hard reload); on a Mac Safari
    /// uses ⌘⌥R to reload from the server, which Settings says next to it; it can be changed or cleared there. Hide all
    /// is Ctrl / ⌘ + Alt + Shift + P ("private"): three modifiers like Start / stop saving and Copy, so no browser, Office
    /// or system key uses it; not ⌘⌥H (Hide Others on a Mac, one slip from hiding every other app) and not Ctrl+Shift+H
    /// (Live Translate's Hide while it runs). Capture screen now is Ctrl / ⌘ + Alt + S (v1.8 P0.1), clear of every other
    /// default here; it can be changed or cleared in Settings.
    pub fn default_accelerator(self) -> Option<&'static str> {
        match self {
            Action::ToggleCaptions => Some("CommandOrControl+Shift+Space"),
            Action::ToggleSaving => Some("CommandOrControl+Alt+Shift+R"),
            Action::CopyLast2Min => Some("CommandOrControl+Alt+Shift+C"),
            Action::Dictation => Some("CommandOrControl+Shift+D"),
            Action::VoiceNote => None,
            Action::Mark => Some("CommandOrControl+Shift+B"),
            Action::RecordNow => Some("CommandOrControl+Alt+R"),
            Action::HideAll => Some("CommandOrControl+Alt+Shift+P"),
            Action::CaptureScreen => Some("CommandOrControl+Alt+S"),
            Action::TranslateLock => Some("CommandOrControl+Shift+T"),
            Action::TranslateHide => Some("CommandOrControl+Shift+H"),
        }
    }
}

pub fn default_map() -> HashMap<Action, String> {
    Action::ALL.iter().filter_map(|a| a.default_accelerator().map(|s| (*a, s.to_string()))).collect()
}

/// Shortcuts added after the first version: a settings file from before one gets its default (a cleared one is kept
/// as an empty accelerator, so it stays cleared).
pub const ADDED_LATER: [Action; 3] = [Action::RecordNow, Action::HideAll, Action::CaptureScreen];

/// Parses an accelerator ("Ctrl+Shift+Space"); pure wrapper around the plugin's FromStr.
pub fn parse(accelerator: &str) -> Result<Shortcut, String> {
    accelerator.parse::<Shortcut>().map_err(|e| format!("Invalid shortcut \"{accelerator}\": {e:?}"))
}

/// An accelerator as a person reads it (pure): "⌘⌥⇧R" on a Mac, "Ctrl + Alt + Shift + R" elsewhere.
pub fn label(accelerator: &str, macos: bool) -> String {
    let keys: Vec<String> = accelerator
        .split('+')
        .map(str::trim)
        .filter(|key| !key.is_empty())
        .map(|key| match key.to_ascii_lowercase().as_str() {
            "commandorcontrol" | "cmdorctrl" => if macos { "⌘" } else { "Ctrl" }.to_string(),
            "command" | "cmd" | "super" | "meta" => if macos { "⌘" } else { "Win" }.to_string(),
            "control" | "ctrl" => if macos { "⌃" } else { "Ctrl" }.to_string(),
            "alt" | "option" => if macos { "⌥" } else { "Alt" }.to_string(),
            "shift" => if macos { "⇧" } else { "Shift" }.to_string(),
            "comma" => ",".to_string(),
            _ => key.strip_prefix("Key").filter(|k| !k.is_empty()).unwrap_or(key).to_string(),
        })
        .collect();
    keys.join(if macos { "" } else { " + " })
}

/// Actions whose accelerators collide with another action (pure).
pub fn duplicates(map: &HashMap<Action, String>) -> Vec<Action> {
    let mut seen: HashMap<Shortcut, Action> = HashMap::new();
    let mut dupes = Vec::new();
    for action in Action::ALL {
        if let Some(Ok(shortcut)) = map.get(&action).map(|s| parse(s))
            && seen.insert(shortcut, action).is_some()
        {
            dupes.push(action);
        }
    }
    dupes
}

#[derive(Default)]
pub struct Bindings(pub Mutex<HashMap<Action, Shortcut>>);

impl Bindings {
    /// Takes these actions' shortcuts out of the table. The lock is let go when this returns: unregistering each one
    /// waits for the main thread, which looks the table up for every key press (`main_thread`).
    pub fn take(&self, actions: &[Action]) -> Vec<Shortcut> {
        let mut table = self.0.lock().unwrap_or_else(|p| p.into_inner());
        actions.iter().filter_map(|action| table.remove(action)).collect()
    }

    /// Takes every shortcut out of the table, as [`Bindings::take`].
    pub fn take_all(&self) -> Vec<Shortcut> {
        std::mem::take(&mut *self.0.lock().unwrap_or_else(|p| p.into_inner())).into_values().collect()
    }
}

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
    // Every shortcut EchoLedger holds goes first, one at a time and with no lock held: the plugin's `unregister_all`
    // keeps its own table locked while it waits for the main thread, which takes that table for every key press.
    for shortcut in app.state::<Bindings>().take_all() {
        let _ = gs.unregister(shortcut);
    }
    let mut errors = Vec::new();
    let mut bound = HashMap::new();
    for action in Action::ALL {
        let Some(accelerator) = map.get(&action) else { continue };
        if accelerator.trim().is_empty() {
            continue;
        }
        match parse(accelerator).and_then(|s| gs.register(s).map(|_| s).map_err(|e| format!("{}: {e}", label(accelerator, cfg!(target_os = "macos"))))) {
            Ok(shortcut) => {
                bound.insert(action, shortcut);
            }
            Err(e) => errors.push((action, e)),
        }
    }
    let dictation = bound.contains_key(&Action::Dictation);
    *app.state::<Bindings>().0.lock().unwrap() = bound;
    crate::lifecycle::keep_dictation_warm(app, dictation);
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
        if action == Action::Dictation {
            crate::lifecycle::keep_dictation_warm(app, false);
        }
        return Ok(());
    }
    let new = parse(accelerator)?;
    if old == Some(new) {
        return Ok(());
    }
    if app.state::<Bindings>().0.lock().unwrap().iter().any(|(a, s)| *a != action && *s == new) {
        return Err("That shortcut is already used by another EchoLedger action.".into());
    }
    gs.register(new).map_err(|e| format!("Could not register {} (maybe used by another app): {e}", label(accelerator, cfg!(target_os = "macos"))))?;
    if let Some(o) = old {
        let _ = gs.unregister(o);
    }
    app.state::<Bindings>().0.lock().unwrap().insert(action, new);
    if action == Action::Dictation {
        crate::lifecycle::keep_dictation_warm(app, true);
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn record_now_is_ctrl_alt_r_and_clashes_with_no_other_action() {
        let map = default_map();
        assert_eq!(map[&Action::RecordNow], "CommandOrControl+Alt+R");
        assert!(duplicates(&map).is_empty(), "{:?}", duplicates(&map));
        let saving = parse(&map[&Action::ToggleSaving]).unwrap();
        assert_ne!(parse(&map[&Action::RecordNow]).unwrap(), saving, "Start / stop saving keeps its Alt");
        for live_only in [Action::TranslateLock, Action::TranslateHide] {
            assert_ne!(parse(live_only.default_accelerator().unwrap()).unwrap(), parse(&map[&Action::RecordNow]).unwrap());
        }
        assert_eq!(label(&map[&Action::RecordNow], true), "⌘⌥R");
        assert_eq!(label(&map[&Action::RecordNow], false), "Ctrl + Alt + R");
    }

    #[test]
    fn shortcuts_leave_the_table_before_they_are_unregistered() {
        // Unregistering waits for the main thread, and a key press there reads the table: it must be free by then.
        let bindings = Bindings::default();
        let [edit, hide, mark] = ["CommandOrControl+Shift+T", "CommandOrControl+Shift+H", "CommandOrControl+Alt+M"].map(|a| parse(a).unwrap());
        bindings.0.lock().unwrap().extend([(Action::TranslateLock, edit), (Action::TranslateHide, hide), (Action::Mark, mark)]);
        let mut taken = bindings.take(&[Action::TranslateLock, Action::TranslateHide, Action::Dictation]);
        taken.sort_by_key(|s| s.id());
        let mut wanted = vec![edit, hide];
        wanted.sort_by_key(|s| s.id());
        assert_eq!(taken, wanted, "only Live Translate's keys, and nothing for an action that has none");
        assert!(bindings.0.try_lock().is_ok(), "the table is free while they are unregistered");
        assert_eq!(bindings.0.lock().unwrap().get(&Action::Mark), Some(&mark), "the others stay");
        assert_eq!(bindings.take_all(), vec![mark]);
        assert!(bindings.0.try_lock().is_ok_and(|t| t.is_empty()));
        // Every unregister goes through `take`: never the plugin's `unregister_all` (it holds its table while it waits).
        let source = include_str!("shortcuts.rs").split("#[cfg(test)]").next().unwrap().to_string() + include_str!("translate/mod.rs");
        assert!(!source.contains(".unregister_all("));
    }

    #[test]
    fn hide_all_has_a_default_that_clashes_with_nothing() {
        let map = default_map();
        let hide = parse(&map[&Action::HideAll]).unwrap();
        assert!(duplicates(&map).is_empty());
        // Not Hide Others on a Mac, nor Live Translate's keys, nor any other action.
        assert_ne!(hide, parse("CommandOrControl+Alt+H").unwrap());
        for live_only in [Action::TranslateLock, Action::TranslateHide] {
            assert_ne!(hide, parse(live_only.default_accelerator().unwrap()).unwrap());
        }
        assert!(ADDED_LATER.contains(&Action::HideAll), "a settings file from before gets it");
        assert_eq!(label(&map[&Action::HideAll], true), "⌘⌥⇧P");
        // The conflict check: the same keys as another action are a duplicate.
        let mut clash = map.clone();
        clash.insert(Action::HideAll, map[&Action::Mark].clone());
        assert_eq!(duplicates(&clash), vec![Action::HideAll]);
    }

    #[test]
    fn capture_screen_now_is_ctrl_alt_s_and_clashes_with_nothing() {
        let map = default_map();
        assert_eq!(map[&Action::CaptureScreen], "CommandOrControl+Alt+S");
        assert!(duplicates(&map).is_empty(), "{:?}", duplicates(&map));
        assert!(ADDED_LATER.contains(&Action::CaptureScreen), "a settings file from before gets it");
        for live_only in [Action::TranslateLock, Action::TranslateHide] {
            assert_ne!(parse(live_only.default_accelerator().unwrap()).unwrap(), parse(&map[&Action::CaptureScreen]).unwrap());
        }
        assert_eq!(label(&map[&Action::CaptureScreen], true), "⌘⌥S");
        assert_eq!(label(&map[&Action::CaptureScreen], false), "Ctrl + Alt + S");
        assert_eq!(serde_json::to_value(Action::CaptureScreen).unwrap(), "capture_screen");
    }
}
