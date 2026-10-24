//! Local, non-secret settings persisted as JSON in the app data dir. The device token lives in the keychain.

use std::collections::HashMap;
use std::path::Path;

use serde::{Deserialize, Serialize};

use crate::overlay::geometry::Corner;
use crate::shortcuts::{Action, default_map};

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
        }
    }
}

impl Settings {
    pub fn load(path: &Path) -> Self {
        std::fs::read(path).ok().and_then(|bytes| serde_json::from_slice(&bytes).ok()).unwrap_or_default()
    }

    pub fn save(&self, path: &Path) -> std::io::Result<()> {
        let tmp = path.with_extension("json.tmp");
        std::fs::write(&tmp, serde_json::to_vec_pretty(self)?)?;
        std::fs::rename(tmp, path)
    }
}
