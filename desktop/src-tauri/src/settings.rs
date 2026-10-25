//! Local, non-secret settings persisted as JSON in the app data dir. The device token lives in the keychain.

use std::collections::HashMap;
use std::path::Path;

use serde::{Deserialize, Serialize};
use serde_json::Value;

use crate::overlay::geometry::{Corner, PillPosition};
use crate::shortcuts::{Action, default_map};

/// "Ask before saving" (A2.6): which finished recordings wait for Keep / Discard.
#[derive(Clone, Copy, Debug, Default, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum AskBeforeSaving {
    #[default]
    Always,
    AutoOnly,
    Never,
}

/// "If I don't choose within 60 s" (A2.5).
#[derive(Clone, Copy, Debug, Default, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum DecisionTimeout {
    #[default]
    Keep,
    Discard,
    Wait,
}

/// Accepted values of `captionLines`.
pub const CAPTION_LINES: [u8; 3] = [2, 3, 5];
pub const DEFAULT_CAPTION_LINES: u8 = 3;

/// `captionLines` from a stored or requested value: 2, 3 or 5, anything else 3.
pub fn caption_lines_or_default(value: Option<u64>) -> u8 {
    value.and_then(|v| u8::try_from(v).ok()).filter(|v| CAPTION_LINES.contains(v)).unwrap_or(DEFAULT_CAPTION_LINES)
}

/// Keeps the shortcut entries this version knows (action name and a text accelerator).
fn known_shortcuts(value: Value) -> Value {
    let Value::Object(map) = value else { return value };
    map.into_iter()
        .filter(|(action, accelerator)| accelerator.is_string() && serde_json::from_value::<Action>(Value::String(action.clone())).is_ok())
        .collect::<serde_json::Map<_, _>>()
        .into()
}

/// Keeps the site rules that parse; drops the others one by one.
fn parsable_site_rules(value: Value) -> Value {
    let Value::Array(rules) = value else { return Value::Array(Vec::new()) };
    rules.into_iter().filter(|rule| serde_json::from_value::<crate::detect::sites::SiteRule>(rule.clone()).is_ok()).collect::<Vec<_>>().into()
}

#[derive(Clone, Debug, Serialize, Deserialize, PartialEq)]
#[serde(default, rename_all = "camelCase")]
pub struct Settings {
    pub server_url: Option<String>,
    /// SPKI pins received at pairing ("sha256/<base64>").
    pub pins: Vec<String>,
    pub device_id: Option<String>,
    pub device_name: Option<String>,
    pub shortcuts: HashMap<Action, String>,
    /// Show live captions automatically when a recording starts.
    pub captions_on_record: bool,
    /// Detect WhatsApp / Zoom / Meet mic use and record automatically.
    pub auto_mode: bool,
    /// Hide overlays from screen sharing / recording (WDA_EXCLUDEFROMCAPTURE / NSWindowSharingNone).
    pub content_protection: bool,
    pub overlay_corner: Corner,
    pub autostart: bool,
    /// "type" (keystrokes into the focused app) | "clipboard"
    pub dictation_output: String,
    /// Default client whose keyterms boost accuracy.
    pub client_id: Option<String>,
    /// One-time consent acknowledgement for recording calls (shown once, never nags).
    pub consent_acknowledged: bool,
    /// When a finished recording waits for Keep / Discard instead of uploading at once.
    pub ask_before_saving: AskBeforeSaving,
    /// What happens to a recording nobody decided on within 60 s.
    pub decision_timeout: DecisionTimeout,
    /// Offer the "Record / Ignore / Always ignore" chip when an unrecognised app or site holds the mic and plays audio.
    pub ask_unknown_apps: bool,
    /// User site rules only (built-ins live in `detect::sites`); a user rule with a built-in's id replaces it.
    pub site_rules: Vec<crate::detect::sites::SiteRule>,
    /// Visible caption lines in the overlay: 2, 3 or 5 (anything else loads as 3).
    pub caption_lines: u8,
    /// Physical desktop px of the recording pill's top-left corner; set only when the user drags the pill.
    pub pill_position: Option<PillPosition>,
    /// macOS only: read browser tab addresses through Apple Events to identify call sites; ignored on Windows.
    pub browser_automation: bool,
}

impl Default for Settings {
    fn default() -> Self {
        Self {
            server_url: None,
            pins: vec![],
            device_id: None,
            device_name: None,
            shortcuts: default_map(),
            captions_on_record: true,
            auto_mode: true, // recording calls is the core feature; the recording indicator and tray state stay visible
            content_protection: true,
            overlay_corner: Corner::BottomRight,
            autostart: false,
            dictation_output: "type".into(),
            client_id: None,
            consent_acknowledged: false,
            ask_before_saving: AskBeforeSaving::Always,
            decision_timeout: DecisionTimeout::Keep,
            ask_unknown_apps: false,
            site_rules: Vec::new(),
            caption_lines: DEFAULT_CAPTION_LINES,
            pill_position: None,
            browser_automation: false,
        }
    }
}

impl Settings {
    /// Loads `settings.json`. A missing, empty or non-object file gives the defaults; inside an object, one bad
    /// value never discards the rest of the file (see [`Settings::from_value`]).
    pub fn load(path: &Path) -> Self {
        std::fs::read(path).ok().and_then(|bytes| serde_json::from_slice::<Value>(&bytes).ok()).map(Self::from_value).unwrap_or_default()
    }

    /// Tolerant decoding (A6.DR13): starts from the defaults and takes each key of the file only if the settings
    /// still decode with it, so an unknown enum value from a newer build or a hand edit costs that one key, never
    /// the pairing. Unknown shortcut actions and unparsable site rules are dropped one by one; `captionLines`
    /// outside 2 / 3 / 5 becomes 3.
    pub fn from_value(value: Value) -> Self {
        let Value::Object(file) = value else { return Self::default() };
        let Ok(Value::Object(mut merged)) = serde_json::to_value(Self::default()) else { return Self::default() };
        for (key, value) in file {
            let value = match key.as_str() {
                "shortcuts" => known_shortcuts(value),
                "siteRules" => parsable_site_rules(value),
                "captionLines" => Value::from(caption_lines_or_default(value.as_u64())),
                _ => value,
            };
            let previous = merged.insert(key.clone(), value);
            if serde_json::from_value::<Settings>(Value::Object(merged.clone())).is_err() {
                match previous {
                    Some(previous) => merged.insert(key, previous),
                    None => merged.remove(&key),
                };
            }
        }
        serde_json::from_value(Value::Object(merged)).unwrap_or_default()
    }

    pub fn save(&self, path: &Path) -> std::io::Result<()> {
        let tmp = path.with_extension("json.tmp");
        std::fs::write(&tmp, serde_json::to_vec_pretty(self)?)?;
        std::fs::rename(tmp, path)
    }
}

#[cfg(test)]
mod tests;
