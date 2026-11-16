//! The interface flags (contract 2.0.0-a, docs/API.md "Interface flags"): which redesigned screens this person sees,
//! decided by the server and never on the device. `GET /api/config` carries `ui_flags` and `ui_classic`; a flag the
//! app does not know is ignored and one the answer lacks (an older server) is off. `PUT /api/account/ui` sets "Switch
//! back to classic", and the config is read again right after it.
//!
//! The config is read at start, every minute while the app runs (`actions::spawn_activity_poll`, so within a minute of
//! a resume, well inside the contract's 15 minutes) and right after `set_classic`. A change waits while a recording
//! runs: the window keeps the flags it has until the recording ends, then gets the new ones as `ui:flags`.

use std::collections::BTreeMap;
use std::sync::{Mutex, MutexGuard};

use serde::Serialize;
use tauri::{AppHandle, Emitter, Manager};

/// The flags this app knows, as the contract names them.
pub const KNOWN: [&str; 12] = [
    "ui_v2.shell",
    "ui_v2.home",
    "ui_v2.library",
    "ui_v2.recording_controls",
    "ui_v2.after_call",
    "ui_v2.live",
    "ui_v2.ask",
    "ui_v2.settings",
    "ui_v2.setup_health",
    "ui_v2.admin",
    "ui_v2.website",
    "live_assist",
];

/// The event the window hears when the flags it shows change.
pub const EVENT: &str = "ui:flags";

/// The flags as the window uses them: every known flag, on or off, and the person's "classic" choice.
#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct UiFlags {
    pub flags: BTreeMap<String, bool>,
    pub classic: bool,
}

impl Default for UiFlags {
    fn default() -> Self {
        Self::read(None, false)
    }
}

impl UiFlags {
    /// From the server's `ui_flags` object (pure): a known flag is on only when it is exactly `true`; unknown names,
    /// other values and a missing or malformed object count as off.
    pub fn read(raw: Option<&serde_json::Value>, classic: bool) -> Self {
        let object = raw.and_then(serde_json::Value::as_object);
        let flags = KNOWN
            .iter()
            .map(|name| ((*name).to_string(), object.and_then(|o| o.get(*name)).is_some_and(|v| *v == serde_json::Value::Bool(true))))
            .collect();
        UiFlags { flags, classic }
    }

    pub fn is_on(&self, name: &str) -> bool {
        self.flags.get(name).copied().unwrap_or(false)
    }

    /// Settings shows "Switch back to classic" while any redesigned screen is on for this person (or classic is chosen,
    /// so it can be switched back).
    pub fn offers_classic_switch(&self) -> bool {
        self.classic || self.flags.iter().any(|(name, on)| *on && name.starts_with("ui_v2."))
    }
}

/// What the window has and what waits for it.
#[derive(Default)]
pub struct UiFlagsState {
    applied: Mutex<UiFlags>,
    waiting: Mutex<Option<UiFlags>>,
}

/// What the window gets (`get_ui_flags`, `set_classic`): the flags it shows, and whether newer ones wait for the end of
/// a recording.
#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct UiFlagsView {
    #[serde(flatten)]
    pub flags: UiFlags,
    pub offers_classic_switch: bool,
    pub waiting: bool,
}

fn lock<T>(m: &Mutex<T>) -> MutexGuard<'_, T> {
    m.lock().unwrap_or_else(|p| p.into_inner())
}

impl UiFlagsState {
    /// Takes in the flags of a config just read: they wait to be applied unless they are what the window has.
    pub fn offer(&self, flags: UiFlags) {
        let same = *lock(&self.applied) == flags;
        *lock(&self.waiting) = (!same).then_some(flags);
    }

    /// Applies what waits, unless a recording runs (pure decision, [`settle_decision`]); the new flags when they changed.
    pub fn settle(&self, recording: bool) -> Option<UiFlags> {
        let mut waiting = lock(&self.waiting);
        let next = settle_decision(waiting.as_ref(), recording)?;
        *waiting = None;
        *lock(&self.applied) = next.clone();
        Some(next)
    }

    pub fn view(&self) -> UiFlagsView {
        let flags = lock(&self.applied).clone();
        UiFlagsView { offers_classic_switch: flags.offers_classic_switch(), flags, waiting: lock(&self.waiting).is_some() }
    }

    pub fn applied(&self) -> UiFlags {
        lock(&self.applied).clone()
    }
}

/// Whether waiting flags apply now (pure): never in the middle of a recording.
pub fn settle_decision(waiting: Option<&UiFlags>, recording: bool) -> Option<UiFlags> {
    if recording { None } else { waiting.cloned() }
}

/// Applies flags that wait, when no recording runs, and tells the window (`ui:flags`). Called with every status
/// update, so the end of a recording applies what waited during it.
pub fn settle(app: &AppHandle) {
    let state = app.state::<crate::AppState>();
    if let Some(flags) = state.ui_flags.settle(state.capture.is_recording()) {
        let view = UiFlagsView { offers_classic_switch: flags.offers_classic_switch(), flags, waiting: false };
        let _ = app.emit(EVENT, view);
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn known_flags_are_read_unknown_ignored_and_missing_ones_off() {
        let flags = UiFlags::read(Some(&json!({"ui_v2.shell": true, "ui_v2.home": false, "ui_v2.library": "yes", "ui_v2.future": true, "live_assist": true})), false);
        assert!(flags.is_on("ui_v2.shell"));
        assert!(!flags.is_on("ui_v2.home"));
        assert!(!flags.is_on("ui_v2.library"), "only true is on");
        assert!(flags.is_on("live_assist"));
        assert!(!flags.flags.contains_key("ui_v2.future"), "a flag the app does not know is ignored");
        assert_eq!(flags.flags.len(), KNOWN.len(), "every known flag is listed");
        assert!(!flags.is_on("ui_v2.settings"), "missing from the answer: off");
        // An older server, or a broken value: everything off.
        for raw in [None, Some(json!(null)), Some(json!([true])), Some(json!("on"))] {
            assert!(UiFlags::read(raw.as_ref(), false).flags.values().all(|on| !on), "{raw:?}");
        }
        assert_eq!(UiFlags::default(), UiFlags::read(None, false));
    }

    #[test]
    fn the_classic_switch_shows_while_any_redesigned_screen_is_on() {
        assert!(!UiFlags::read(Some(&json!({"live_assist": true})), false).offers_classic_switch(), "live_assist is not a screen");
        assert!(UiFlags::read(Some(&json!({"ui_v2.home": true})), false).offers_classic_switch());
        assert!(UiFlags::read(None, true).offers_classic_switch(), "chosen: it can be switched back");
    }

    #[test]
    fn a_change_waits_for_the_end_of_a_recording_and_applies_once() {
        let state = UiFlagsState::default();
        let on = UiFlags::read(Some(&json!({"ui_v2.home": true})), false);
        state.offer(on.clone());
        assert!(state.view().waiting);
        assert_eq!(state.settle(true), None, "never in the middle of a recording");
        assert!(!state.view().flags.is_on("ui_v2.home"));
        assert_eq!(state.settle(false), Some(on.clone()), "applied once the recording has ended");
        assert!(state.view().flags.is_on("ui_v2.home"));
        assert!(!state.view().waiting);
        assert_eq!(state.settle(false), None, "said once");
        // The same flags again: nothing to apply.
        state.offer(on.clone());
        assert_eq!(state.settle(false), None);
        // A change that is taken back before it applied: nothing waits.
        state.offer(UiFlags::default());
        state.offer(on);
        assert!(!state.view().waiting);
    }

    #[test]
    fn the_window_gets_every_flag_with_its_name() {
        let view = UiFlagsView { flags: UiFlags::read(Some(&json!({"ui_v2.shell": true})), true), offers_classic_switch: true, waiting: false };
        let json = serde_json::to_value(view).unwrap();
        assert_eq!(json["flags"]["ui_v2.shell"], true);
        assert_eq!(json["flags"]["live_assist"], false);
        assert_eq!(json["classic"], true);
        assert_eq!(json["offersClassicSwitch"], true);
        assert_eq!(json["waiting"], false);
    }
}
