//! Notification categories (Phase 1C, B): every OS notification the app shows belongs to one category, the same list the
//! phone has as Android channels. Settings → Notifications turns each on or off and its sound, and sets the quiet hours.
//! Call capture & readiness is Critical: the quiet hours never hold it back (a call that will not be recorded must be
//! said). The decisions are pure and tested; only [`send`] shows anything.

use std::collections::HashMap;

use serde::{Deserialize, Serialize};
use tauri::AppHandle;

#[derive(Clone, Copy, Debug, PartialEq, Eq, Hash, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum Category {
    Recording,
    Transcription,
    Enhancement,
    Uploads,
    /// Call capture & readiness: Critical.
    CallCapture,
    Sharing,
    Account,
    Usage,
    LiveTranslate,
    AppUpdates,
    Other,
}

impl Category {
    /// In the order Settings lists them (the phone's channel order).
    pub const ALL: [Category; 11] = [
        Category::Recording,
        Category::Transcription,
        Category::Enhancement,
        Category::Uploads,
        Category::CallCapture,
        Category::Sharing,
        Category::Account,
        Category::Usage,
        Category::LiveTranslate,
        Category::AppUpdates,
        Category::Other,
    ];

    pub fn as_str(self) -> &'static str {
        match self {
            Category::Recording => "recording",
            Category::Transcription => "transcription",
            Category::Enhancement => "enhancement",
            Category::Uploads => "uploads",
            Category::CallCapture => "call_capture",
            Category::Sharing => "sharing",
            Category::Account => "account",
            Category::Usage => "usage",
            Category::LiveTranslate => "live_translate",
            Category::AppUpdates => "app_updates",
            Category::Other => "other",
        }
    }

    pub fn from_wire(name: &str) -> Option<Category> {
        Category::ALL.into_iter().find(|c| c.as_str() == name)
    }

    pub fn label(self) -> &'static str {
        match self {
            Category::Recording => "Recording",
            Category::Transcription => "Transcription",
            Category::Enhancement => "Enhancement",
            Category::Uploads => "Uploads",
            Category::CallCapture => "Call capture & readiness",
            Category::Sharing => "Sharing",
            Category::Account => "Account & security",
            Category::Usage => "Usage & limits",
            Category::LiveTranslate => "Live Translate",
            Category::AppUpdates => "App updates",
            Category::Other => "Other",
        }
    }

    /// Never held back by the quiet hours.
    pub fn critical(self) -> bool {
        self == Category::CallCapture
    }

    /// What the Test button shows.
    pub fn test_text(self) -> (String, String) {
        (format!("Test: {}", self.label()), "Notifications of this kind look like this.".to_string())
    }

    /// The category of a server activity item (`GET /api/activity` `kind`); a kind this version does not know is Other.
    pub fn of_activity(kind: Option<&str>) -> Category {
        match kind.unwrap_or("") {
            "transcript_ready" | "transcript_failed" => Category::Transcription,
            "views_ready" | "views_failed" | "enhancement_ready" | "notes_ready" => Category::Enhancement,
            "upload_failed" => Category::Uploads,
            "device_paired" | "sign_in" | "security" => Category::Account,
            "budget_warn" | "budget_reached" | "storage_warn" => Category::Usage,
            "shared_with_you" | "share_viewed" => Category::Sharing,
            _ => Category::Other,
        }
    }
}

/// Keeps the switches of categories this version knows, with a boolean value (tolerant settings loading).
pub fn known_switches(value: serde_json::Value) -> serde_json::Value {
    let serde_json::Value::Object(map) = value else { return serde_json::Value::Object(serde_json::Map::new()) };
    map.into_iter().filter(|(name, on)| on.is_boolean() && Category::from_wire(name).is_some()).collect::<serde_json::Map<_, _>>().into()
}

/// What the notification settings say about one category (pure): missing switches are on.
pub fn is_on(switches: &HashMap<String, bool>, category: Category) -> bool {
    switches.get(category.as_str()).copied().unwrap_or(true)
}

/// Whether a notification of `category` is shown now (pure). `minutes` is the local time, minutes after midnight.
/// "Show notifications" (`activity_notifications`) is the switch for them all.
pub fn allowed(settings: &crate::settings::Settings, category: Category, minutes: u32) -> bool {
    if !settings.activity_notifications || !is_on(&settings.notification_categories, category) {
        return false;
    }
    category.critical() || !crate::activity::in_quiet_hours(settings.quiet_hours, &settings.quiet_from, &settings.quiet_to, minutes)
}

/// The system sound's name for the notification plugin, or none for a silent one (Windows toasts are silent without it).
pub fn sound_name(settings: &crate::settings::Settings, category: Category, macos: bool) -> Option<&'static str> {
    is_on(&settings.notification_sounds, category).then_some(if macos { "NSUserNotificationDefaultSoundName" } else { "Default" })
}

/// The local time now, minutes after midnight.
pub fn local_minutes() -> u32 {
    use chrono::Timelike;
    let now = chrono::Local::now();
    now.hour() * 60 + now.minute()
}

/// Shows `title` and `body` when the category's settings allow it now; true when it was shown.
pub fn send(app: &AppHandle, category: Category, title: &str, body: &str) -> bool {
    use tauri::Manager;
    let Some(state) = app.try_state::<crate::AppState>() else { return false };
    let settings = state.settings();
    if !allowed(&settings, category, local_minutes()) {
        return false;
    }
    show(app, &settings, category, title, body)
}

/// Shows it whatever the switches and the quiet hours say (the Test button), with the category's sound.
pub fn show(app: &AppHandle, settings: &crate::settings::Settings, category: Category, title: &str, body: &str) -> bool {
    use tauri_plugin_notification::NotificationExt;
    let mut builder = app.notification().builder().title(title).body(body);
    if let Some(sound) = sound_name(settings, category, cfg!(target_os = "macos")) {
        builder = builder.sound(sound);
    }
    builder.show().is_ok()
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::settings::Settings;

    #[test]
    fn the_categories_mirror_the_phone_s_channels() {
        let labels: Vec<&str> = Category::ALL.iter().map(|c| c.label()).collect();
        assert_eq!(
            labels,
            ["Recording", "Transcription", "Enhancement", "Uploads", "Call capture & readiness", "Sharing", "Account & security", "Usage & limits", "Live Translate", "App updates", "Other"]
        );
        for c in Category::ALL {
            assert_eq!(Category::from_wire(c.as_str()), Some(c));
            assert_eq!(serde_json::to_value(c).unwrap(), serde_json::Value::from(c.as_str()), "the wire name is the serde name");
        }
        assert_eq!(Category::ALL.iter().filter(|c| c.critical()).collect::<Vec<_>>(), [&Category::CallCapture]);
    }

    #[test]
    fn server_activity_goes_to_its_category() {
        assert_eq!(Category::of_activity(Some("transcript_ready")), Category::Transcription);
        assert_eq!(Category::of_activity(Some("upload_failed")), Category::Uploads);
        assert_eq!(Category::of_activity(Some("budget_reached")), Category::Usage);
        assert_eq!(Category::of_activity(Some("shared_with_you")), Category::Sharing);
        assert_eq!(Category::of_activity(Some("device_paired")), Category::Account);
        assert_eq!(Category::of_activity(Some("something_new")), Category::Other);
        assert_eq!(Category::of_activity(None), Category::Other);
    }

    fn quiet_all_day() -> Settings {
        Settings { quiet_hours: true, quiet_from: "00:00".into(), quiet_to: "00:00".into(), ..Settings::default() }
    }

    #[test]
    fn quiet_hours_hold_back_everything_but_critical() {
        let settings = quiet_all_day();
        assert!(!allowed(&settings, Category::Transcription, 600));
        assert!(allowed(&settings, Category::CallCapture, 600), "readiness breaking is said even at night");
        let awake = Settings::default();
        assert!(Category::ALL.iter().all(|c| allowed(&awake, *c, 600)), "every category is on by default");
        let none = Settings { activity_notifications: false, ..Settings::default() };
        assert!(Category::ALL.iter().all(|c| !allowed(&none, *c, 600)), "Show notifications off turns them all off");
    }

    #[test]
    fn a_category_turned_off_stays_quiet() {
        let mut settings = Settings::default();
        settings.notification_categories.insert("sharing".into(), false);
        assert!(!allowed(&settings, Category::Sharing, 600));
        assert!(allowed(&settings, Category::Uploads, 600));
        settings.notification_categories.insert("call_capture".into(), false);
        assert!(!allowed(&quiet_all_day_with(settings.notification_categories.clone()), Category::CallCapture, 600), "off is off, even for Critical");
    }

    fn quiet_all_day_with(categories: HashMap<String, bool>) -> Settings {
        Settings { notification_categories: categories, ..quiet_all_day() }
    }

    #[test]
    fn sound_follows_its_switch_and_the_platform_s_name() {
        let mut settings = Settings::default();
        assert_eq!(sound_name(&settings, Category::Recording, true), Some("NSUserNotificationDefaultSoundName"));
        assert_eq!(sound_name(&settings, Category::Recording, false), Some("Default"));
        settings.notification_sounds.insert("recording".into(), false);
        assert_eq!(sound_name(&settings, Category::Recording, false), None);
    }

    #[test]
    fn unknown_switches_are_dropped_when_loading() {
        let kept = known_switches(serde_json::json!({"sharing": false, "fax": true, "uploads": "no"}));
        assert_eq!(kept, serde_json::json!({"sharing": false}));
        assert_eq!(known_switches(serde_json::json!([1])), serde_json::json!({}));
    }
}
