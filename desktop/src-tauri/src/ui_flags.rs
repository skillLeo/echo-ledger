//! The interface flags (contract 2.0.0-a, docs/API.md "Interface flags"): which redesigned screens this person sees,
//! decided by the server and never on the device. `GET /api/config` carries `ui_flags` and `ui_classic`; a flag the
//! app does not know is ignored and one the answer lacks (an older server) is off.
//!
//! The window asks with `ui_flags` (answered from the last config read, no request) and sets "Switch back to classic"
//! with `ui_classic_set` (`PUT /api/account/ui`, which updates the cached config). Both answer
//! `{"ui_flags": {"<name>": bool, …}, "ui_classic": bool}` (desktop/src/v2/flags.ts reads that shape), and `ui:flags`
//! carries it whenever the cached flags change. The window reads them again on its next screen, never in the middle
//! of a recording. The config is read at start, every minute while the app runs (so within a minute of a resume, well
//! inside the contract's 15 minutes) and right after `ui_classic_set`.

use std::collections::BTreeMap;
use std::sync::Mutex;

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

/// The event the window hears when the cached flags change.
pub const EVENT: &str = "ui:flags";

/// The flags as the window reads them: every known flag, on or off, and the person's "classic" choice.
#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
pub struct UiFlags {
    #[serde(rename = "ui_flags")]
    pub flags: BTreeMap<String, bool>,
    #[serde(rename = "ui_classic")]
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

/// The flags of the last config read: all off before the first one.
pub fn cached(state: &crate::AppState) -> UiFlags {
    state.server_config.lock().unwrap_or_else(|p| p.into_inner()).as_ref().map(crate::net::models::ServerConfig::ui).unwrap_or_default()
}

/// The flags last announced as `ui:flags`, to say only a change.
#[derive(Default)]
pub struct Announced(Mutex<Option<UiFlags>>);

impl Announced {
    /// The flags when they differ from the ones announced last (pure apart from remembering them); none the first
    /// time when they are all off (nothing changed for the window, which starts with everything off).
    pub fn changed(&self, now: UiFlags) -> Option<UiFlags> {
        let mut last = self.0.lock().unwrap_or_else(|p| p.into_inner());
        let before = last.clone().unwrap_or_default();
        *last = Some(now.clone());
        (before != now).then_some(now)
    }
}

/// Tells the window (`ui:flags`) when the cached flags changed. Called with every status update, which follows every
/// config read.
pub fn announce(app: &AppHandle) {
    let state = app.state::<crate::AppState>();
    let flags = cached(&state);
    // `live_assist` turned off for this person: a session that runs ends.
    crate::assist::live::stop_unless_enabled(app, &flags);
    if let Some(flags) = state.ui_announced.changed(flags) {
        // Turned on: the scheduled preps get their arming timers.
        if crate::assist::enabled(&flags) {
            crate::assist::live::rearm(app);
        }
        let _ = app.emit(EVENT, flags);
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
        for raw in [None, Some(json!(null)), Some(json!([true])), Some(json!("on"))] {
            assert!(UiFlags::read(raw.as_ref(), false).flags.values().all(|on| !on), "{raw:?}");
        }
        assert_eq!(UiFlags::default(), UiFlags::read(None, false));
    }

    #[test]
    fn the_window_gets_the_shape_its_adapter_reads() {
        // desktop/src/v2/flags.ts: { ui_flags: { "<name>": bool, … }, ui_classic: bool }
        let json = serde_json::to_value(UiFlags::read(Some(&json!({"ui_v2.shell": true})), true)).unwrap();
        assert_eq!(json["ui_flags"]["ui_v2.shell"], true);
        assert_eq!(json["ui_flags"]["live_assist"], false);
        assert_eq!(json["ui_classic"], true);
        assert_eq!(json.as_object().unwrap().len(), 2);
    }

    #[test]
    fn the_classic_switch_shows_while_any_redesigned_screen_is_on() {
        assert!(!UiFlags::read(Some(&json!({"live_assist": true})), false).offers_classic_switch(), "live_assist is not a screen");
        assert!(UiFlags::read(Some(&json!({"ui_v2.home": true})), false).offers_classic_switch());
        assert!(UiFlags::read(None, true).offers_classic_switch(), "chosen: it can be switched back");
    }

    #[test]
    fn only_a_change_is_announced() {
        let announced = Announced::default();
        assert_eq!(announced.changed(UiFlags::default()), None, "all off at first: the window already has that");
        let on = UiFlags::read(Some(&json!({"ui_v2.home": true})), false);
        assert_eq!(announced.changed(on.clone()), Some(on.clone()));
        assert_eq!(announced.changed(on.clone()), None, "said once");
        let classic = UiFlags::read(None, true);
        assert_eq!(announced.changed(classic.clone()), Some(classic));
    }
}
