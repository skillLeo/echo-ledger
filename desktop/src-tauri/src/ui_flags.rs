//! The interface flags (contract 2.0.0-a, docs/API.md "Interface flags"): which redesigned screens this person sees,
//! decided by the server and never on the device. `GET /api/config` carries `ui_flags` and `ui_classic`; a flag the
//! app does not know is ignored and one the answer lacks (an older server) is off.
//!
//! The window asks with `ui_flags` (answered from the last config read, no request) and sets "Switch back to classic"
//! with `ui_classic_set` (`PUT /api/account/ui`, which updates the cached config). Both answer
//! `{"ui_flags": {"<name>": bool, …}, "ui_classic": bool}` (desktop/src/v2/flags.ts reads that shape), and `ui:flags`
//! carries it whenever the cached flags change. A window that read before the first config answer (everything off)
//! takes the first `ui:flags` at once, or when its recording ends; after that it reads them again on its next screen or
//! when a recording ends, never in the middle of one. The config is read at start, every minute while the app runs
//! (so within a minute of a resume, well inside the contract's 15 minutes) and right after `ui_classic_set`.

use std::collections::BTreeMap;
use std::sync::Mutex;

use serde::Serialize;
use tauri::{AppHandle, Emitter, Manager};

/// The flags this app knows, as the contract names them.
pub const KNOWN: [&str; 17] = [
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
    // UX-02 (PLAN_V2_1_POLISH): the panel docked from 1200 px, a flyout below (contract 2.0.0-r).
    "v21_panel_flyout",
    // UX-20 (PLAN_V2_1_POLISH): the main window's own title row on Windows (contract 2.0.0-s, wintitle.rs).
    "v21_win_titlebar",
    // UX-07 (PLAN_V2_1_POLISH): zoom like Chrome in the main window (contract 2.0.0-r).
    "v21_zoom",
    // Desktop Urdu: the window speaks the account's language (src/i18n). Without it here the window never heard it.
    "v21_urdu",
    // Call rules (contract 2.0.0-w and -x): the account's After each call, its rules and its remembered choices.
    "call_rules",
];

/// The event the window hears when the cached flags change.
pub const EVENT: &str = "ui:flags";

/// The flags as the window reads them: every known flag, on or off, the person's "classic" choice, and the design
/// switch (contract 2.0.0-g; `null` from an older server, which shows only the 2.0.0-a switch).
#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
pub struct UiFlags {
    #[serde(rename = "ui_flags")]
    pub flags: BTreeMap<String, bool>,
    #[serde(rename = "ui_classic")]
    pub classic: bool,
    #[serde(rename = "ui_design")]
    pub design: Option<UiDesign>,
}

/// The design switch (contract 2.0.0-g, docs/API.md "The design switch"): what the person chose (`v2`, `classic`, or
/// never), which design they see now, and which of the two Settings rows to show.
#[derive(Clone, Debug, PartialEq, Eq, Serialize, serde::Deserialize)]
pub struct UiDesign {
    #[serde(default)]
    pub choice: Option<String>,
    #[serde(default)]
    pub current: String,
    #[serde(default)]
    pub can_try: bool,
    #[serde(default)]
    pub can_switch_back: bool,
}

impl UiDesign {
    /// The block as sent, made safe (pure): a choice other than `v2` or `classic` is none, a `current` other than `v2`
    /// is `classic`, and anything that is not such an object is none.
    pub fn read(raw: Option<&serde_json::Value>) -> Option<Self> {
        let object = raw?.as_object()?;
        let word = |key: &str| object.get(key).and_then(serde_json::Value::as_str);
        let yes = |key: &str| object.get(key) == Some(&serde_json::Value::Bool(true));
        Some(UiDesign {
            choice: word("choice").filter(|c| matches!(*c, "v2" | "classic")).map(str::to_string),
            current: if word("current") == Some("v2") { "v2" } else { "classic" }.to_string(),
            can_try: yes("can_try"),
            can_switch_back: yes("can_switch_back"),
        })
    }
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
        UiFlags { flags, classic, design: None }
    }

    /// The same with the design switch's block (contract 2.0.0-g), as sent.
    pub fn with_design(mut self, raw: Option<&serde_json::Value>) -> Self {
        self.design = UiDesign::read(raw);
        self
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
        // The main window's own title row on Windows follows its flag at once (wintitle.rs).
        crate::wintitle::follow(app, &flags);
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
    fn the_urdu_flag_reaches_the_window() {
        // Desktop Urdu: the server's v21_urdu is on for the window only when this list knows it.
        assert!(UiFlags::read(Some(&json!({"v21_urdu": true})), false).is_on("v21_urdu"));
        assert!(!UiFlags::read(Some(&json!({"v21_urdu": false})), false).is_on("v21_urdu"));
    }

    #[test]
    fn the_window_gets_the_shape_its_adapter_reads() {
        // desktop/src/v2/flags.ts: { ui_flags: { "<name>": bool, … }, ui_classic: bool }
        let json = serde_json::to_value(UiFlags::read(Some(&json!({"ui_v2.shell": true})), true)).unwrap();
        assert_eq!(json["ui_flags"]["ui_v2.shell"], true);
        assert_eq!(json["ui_flags"]["live_assist"], false);
        assert_eq!(json["ui_classic"], true);
        assert_eq!(json["ui_design"], serde_json::Value::Null, "an older server: no design switch block");
        assert_eq!(json.as_object().unwrap().len(), 3);
        let with = serde_json::to_value(UiFlags::default().with_design(Some(&json!({"choice": "v2", "current": "v2", "can_try": false, "can_switch_back": true})))).unwrap();
        assert_eq!(with["ui_design"], json!({"choice": "v2", "current": "v2", "can_try": false, "can_switch_back": true}));
    }

    #[test]
    fn the_design_switch_block_is_read_safely() {
        let read = |raw: serde_json::Value| UiDesign::read(Some(&raw));
        assert_eq!(read(json!({"choice": null, "current": "classic", "can_try": true, "can_switch_back": false})),
            Some(UiDesign { choice: None, current: "classic".into(), can_try: true, can_switch_back: false }));
        assert_eq!(read(json!({"choice": "beta", "current": "v3", "can_try": "yes", "can_switch_back": 1})),
            Some(UiDesign { choice: None, current: "classic".into(), can_try: false, can_switch_back: false }), "unknown values are the safe ones");
        assert_eq!(read(json!("v2")), None);
        assert_eq!(UiDesign::read(None), None);
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
