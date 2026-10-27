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

/// Caption text size (B4).
#[derive(Clone, Copy, Debug, Default, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum CaptionSize {
    Small,
    #[default]
    Medium,
    Large,
}

/// Caption overlay width (B4).
#[derive(Clone, Copy, Debug, Default, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum CaptionWidth {
    Narrow,
    #[default]
    Normal,
    Wide,
}

/// Accepted `autoHideSeconds`: never, or after 5 / 10 / 30 s without new captions.
pub const CAPTION_AUTO_HIDE: [u16; 4] = [0, 5, 10, 30];
pub const CAPTION_OPACITY_MIN: u8 = 40;
pub const CAPTION_OPACITY_MAX: u8 = 100;

/// How the caption overlay looks (B4). Hidden from screen sharing is `contentProtection`; the position is
/// `overlayCorner` (or a drag); the line count is `captionLines`.
#[derive(Clone, Debug, PartialEq, Eq, Serialize, Deserialize)]
#[serde(default, rename_all = "camelCase")]
pub struct CaptionStyle {
    pub size: CaptionSize,
    pub width: CaptionWidth,
    /// Background opacity in percent, 40-100.
    pub opacity: u8,
    /// One running line per speaker without the speaker labels.
    pub compact: bool,
    /// Hide the overlay after this many seconds without new captions; 0 keeps it shown.
    pub auto_hide_seconds: u16,
    /// "#rrggbb", or none for the theme colour.
    pub me_color: Option<String>,
    pub client_color: Option<String>,
}

impl Default for CaptionStyle {
    fn default() -> Self {
        Self {
            size: CaptionSize::Medium,
            width: CaptionWidth::Normal,
            opacity: 85,
            compact: false,
            auto_hide_seconds: 0,
            me_color: None,
            client_color: None,
        }
    }
}

/// `#rrggbb` (any case).
pub fn is_hex_colour(value: &str) -> bool {
    value.len() == 7 && value.starts_with('#') && value[1..].chars().all(|c| c.is_ascii_hexdigit())
}

impl CaptionStyle {
    /// Why this style cannot be saved, if it cannot.
    pub fn problem(&self) -> Option<&'static str> {
        if !(CAPTION_OPACITY_MIN..=CAPTION_OPACITY_MAX).contains(&self.opacity) {
            return Some("Choose an opacity between 40 and 100 %.");
        }
        if !CAPTION_AUTO_HIDE.contains(&self.auto_hide_seconds) {
            return Some("Choose never, 5, 10 or 30 seconds.");
        }
        if [&self.me_color, &self.client_color].iter().any(|c| c.as_deref().is_some_and(|c| !is_hex_colour(c))) {
            return Some("Choose a colour like #3b82f6.");
        }
        None
    }

    /// The style with each out-of-range value replaced by its default (used when loading a file).
    pub fn sanitized(mut self) -> Self {
        let defaults = Self::default();
        if !(CAPTION_OPACITY_MIN..=CAPTION_OPACITY_MAX).contains(&self.opacity) {
            self.opacity = defaults.opacity;
        }
        if !CAPTION_AUTO_HIDE.contains(&self.auto_hide_seconds) {
            self.auto_hide_seconds = 0;
        }
        self.me_color = self.me_color.filter(|c| is_hex_colour(c));
        self.client_color = self.client_color.filter(|c| is_hex_colour(c));
        self
    }
}

/// Decodes `captionStyle` key by key, like the settings themselves: one bad value costs that value only.
fn tolerant_caption_style(value: Value) -> Value {
    let Value::Object(file) = value else { return serde_json::to_value(CaptionStyle::default()).unwrap_or(Value::Null) };
    let Ok(Value::Object(mut merged)) = serde_json::to_value(CaptionStyle::default()) else { return Value::Null };
    for (key, value) in file {
        let previous = merged.insert(key.clone(), value);
        if serde_json::from_value::<CaptionStyle>(Value::Object(merged.clone())).is_err() {
            match previous {
                Some(previous) => merged.insert(key, previous),
                None => merged.remove(&key),
            };
        }
    }
    let style = serde_json::from_value::<CaptionStyle>(Value::Object(merged)).unwrap_or_default().sanitized();
    serde_json::to_value(style).unwrap_or(Value::Null)
}

/// The language choices offered (docs/API.md "Languages"); anything else is automatic.
pub const LANGUAGES: [&str; 5] = ["en", "ur", "en+ur", "hi", "en+hi"];

/// A stored or requested language: one of [`LANGUAGES`], else none (automatic). Mixed languages are written with
/// English first ("ur+en" is "en+ur"), like the server.
pub fn language_or_none(value: Option<&str>) -> Option<String> {
    let value = value?.trim().to_ascii_lowercase();
    let mut codes: Vec<&str> = value.split('+').map(str::trim).filter(|c| !c.is_empty()).collect();
    codes.sort_by_key(|c| *c != "en");
    let canonical = codes.join("+");
    LANGUAGES.contains(&canonical.as_str()).then_some(canonical)
}

/// The short label of a language on chips: "UR+EN", "Auto" for none.
pub fn language_chip(value: Option<&str>) -> &'static str {
    match value {
        Some("en") => "EN",
        Some("ur") => "UR",
        Some("en+ur") => "UR+EN",
        Some("hi") => "HI",
        Some("en+hi") => "HI+EN",
        _ => "Auto",
    }
}

/// Keeps the shortcut entries this version knows (action name and a text accelerator).
fn known_shortcuts(value: Value) -> Value {
    let Value::Object(map) = value else { return value };
    map.into_iter()
        .filter(|(action, accelerator)| accelerator.is_string() && serde_json::from_value::<Action>(Value::String(action.clone())).is_ok())
        .collect::<serde_json::Map<_, _>>()
        .into()
}

/// Keeps the auto-record switches of call apps this version knows, with a boolean value.
fn known_app_switches(value: Value) -> Value {
    let Value::Object(map) = value else { return Value::Object(serde_json::Map::new()) };
    map.into_iter()
        .filter(|(app, on)| on.is_boolean() && crate::detect::classify::SourceApp::from_wire(app).is_some())
        .collect::<serde_json::Map<_, _>>()
        .into()
}

/// Keeps the list entries that parse; drops the others one by one.
fn parsable_list<T: serde::de::DeserializeOwned>(value: Value) -> Value {
    let Value::Array(items) = value else { return Value::Array(Vec::new()) };
    items.into_iter().filter(|item| serde_json::from_value::<T>(item.clone()).is_ok()).collect::<Vec<_>>().into()
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
    /// Per call app (`source_app` wire value): `false` keeps auto mode from recording it. Missing apps are on.
    pub auto_record_apps: HashMap<String, bool>,
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
    /// Size, width, opacity, compact mode, auto-hide and speaker colours of the caption overlay.
    pub caption_style: CaptionStyle,
    /// Microphone to record (Windows endpoint id / macOS device UID); none follows the system default. A device
    /// that is not connected falls back to the default.
    pub input_device: Option<String>,
    /// Warn when a call recording has not heard the other side for 30 seconds.
    pub far_side_warning: bool,
    /// Language of new recordings: none (the client's or the console default, else automatic), `en`, `ur`, `hi` or `en+ur`.
    pub transcription_language: Option<String>,
    /// `toggle` (press to start, press again to type) or `push_to_talk` (hold the shortcut while speaking).
    pub dictation_mode: String,
    /// Tidy spacing, punctuation and capitals and drop filler words (B14).
    pub dictation_cleanup: bool,
    /// A short sound when dictation starts and stops.
    pub dictation_sounds: bool,
    /// Dictionary and replacements: `when I say X, write Y`.
    pub dictation_replacements: Vec<crate::dictation::rules::Replacement>,
    /// Voice snippets: saying the trigger inserts the text.
    pub dictation_snippets: Vec<crate::dictation::rules::Snippet>,
    /// App lock (B16): Windows Hello / Touch ID or the PIN when the app opens and after [Self::lock_after_minutes].
    pub app_lock: bool,
    /// Minutes without use before the app locks; 0 = only when it starts or with Lock now.
    pub lock_after_minutes: u32,
    /// Show new activity (transcript ready, upload failed, ...) as OS notifications (B18).
    pub activity_notifications: bool,
    /// No OS notifications between [Self::quiet_from] and [Self::quiet_to] (local "HH:MM").
    pub quiet_hours: bool,
    pub quiet_from: String,
    pub quiet_to: String,
    /// The first-run wizard (B19) was finished or skipped. Installs paired before v1.1 count as done.
    pub onboarding_done: bool,
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
            auto_record_apps: HashMap::new(),
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
            caption_style: CaptionStyle::default(),
            input_device: None,
            far_side_warning: true,
            transcription_language: None,
            dictation_mode: "toggle".into(),
            dictation_cleanup: true,
            dictation_sounds: true,
            dictation_replacements: Vec::new(),
            dictation_snippets: Vec::new(),
            app_lock: false,
            lock_after_minutes: 5,
            activity_notifications: true,
            quiet_hours: false,
            quiet_from: "22:00".into(),
            quiet_to: "07:00".into(),
            onboarding_done: false,
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
        // A file from before the wizard (B19) that is already paired: the person has set EchoLedger up.
        let set_up_before = !file.contains_key("onboardingDone") && file.get("deviceId").is_some_and(|v| !v.is_null());
        let Ok(Value::Object(mut merged)) = serde_json::to_value(Self::default()) else { return Self::default() };
        for (key, value) in file {
            let value = match key.as_str() {
                "shortcuts" => known_shortcuts(value),
                "siteRules" => parsable_site_rules(value),
                "autoRecordApps" => known_app_switches(value),
                "captionStyle" => tolerant_caption_style(value),
                "transcriptionLanguage" => language_or_none(value.as_str()).map_or(Value::Null, Value::from),
                "dictationMode" => Value::from(if value.as_str() == Some("push_to_talk") { "push_to_talk" } else { "toggle" }),
                "dictationReplacements" => parsable_list::<crate::dictation::rules::Replacement>(value),
                "dictationSnippets" => parsable_list::<crate::dictation::rules::Snippet>(value),
                "captionLines" => Value::from(caption_lines_or_default(value.as_u64())),
                "quietFrom" => Value::from(value.as_str().filter(|t| crate::activity::parse_hhmm(t).is_some()).unwrap_or("22:00")),
                "quietTo" => Value::from(value.as_str().filter(|t| crate::activity::parse_hhmm(t).is_some()).unwrap_or("07:00")),
                "lockAfterMinutes" => Value::from(value.as_u64().and_then(|m| u32::try_from(m).ok()).filter(|m| crate::lock::LOCK_AFTER_CHOICES.contains(m)).unwrap_or(5)),
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
        let mut settings: Settings = serde_json::from_value(Value::Object(merged)).unwrap_or_default();
        settings.onboarding_done |= set_up_before;
        settings
    }

    pub fn save(&self, path: &Path) -> std::io::Result<()> {
        let tmp = path.with_extension("json.tmp");
        std::fs::write(&tmp, serde_json::to_vec_pretty(self)?)?;
        std::fs::rename(tmp, path)
    }
}

#[cfg(test)]
mod tests;
