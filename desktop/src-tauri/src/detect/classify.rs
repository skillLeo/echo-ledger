//! Maps a process identity (Windows image name / package family name, macOS bundle id) to `source_app`.
//! Identities from docs/research/desktop-audio-native.md §2.7 and §3.6.

use serde::{Deserialize, Serialize};

#[derive(Clone, Copy, Debug, PartialEq, Eq, Hash, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum SourceApp {
    Whatsapp,
    WhatsappBusiness,
    GoogleMeet,
    Zoom,
    VoiceNote,
    Other,
}

impl SourceApp {
    pub fn as_str(self) -> &'static str {
        match self {
            SourceApp::Whatsapp => "whatsapp",
            SourceApp::WhatsappBusiness => "whatsapp_business",
            SourceApp::GoogleMeet => "google_meet",
            SourceApp::Zoom => "zoom",
            SourceApp::VoiceNote => "voice_note",
            SourceApp::Other => "other",
        }
    }
}

/// Windows: image file name (e.g. `Zoom.exe`) and optional package family name.
pub fn classify_windows(image: &str, pfn: Option<&str>) -> Option<SourceApp> {
    let image = image.to_ascii_lowercase();
    if let Some(pfn) = pfn {
        // WhatsApp Inc. publisher prefix covers WhatsApp and WhatsApp Beta Store packages.
        if pfn.starts_with("5319275A.") {
            return Some(SourceApp::Whatsapp);
        }
    }
    match image.as_str() {
        "whatsapp.exe" | "whatsapp.root.exe" => Some(SourceApp::Whatsapp),
        "zoom.exe" => Some(SourceApp::Zoom),
        // Google Meet runs in a browser; browser mic use is labeled Meet (the user can relabel later).
        "chrome.exe" | "msedge.exe" | "brave.exe" | "firefox.exe" | "opera.exe" => Some(SourceApp::GoogleMeet),
        _ => None,
    }
}

/// macOS: bundle identifier (helpers like `us.zoom.xos.helper` match by prefix).
pub fn classify_macos(bundle_id: &str) -> Option<SourceApp> {
    let id = bundle_id.to_ascii_lowercase();
    if id.starts_with("net.whatsapp.whatsappsmb") {
        return Some(SourceApp::WhatsappBusiness);
    }
    if id.starts_with("net.whatsapp.whatsapp") || id.starts_with("desktop.whatsapp") {
        return Some(SourceApp::Whatsapp);
    }
    if id.starts_with("us.zoom.xos") {
        return Some(SourceApp::Zoom);
    }
    const BROWSERS: [&str; 6] = ["com.google.chrome", "com.microsoft.edgemac", "com.apple.safari", "com.brave.browser", "org.mozilla.firefox", "company.thebrowser.browser"];
    if BROWSERS.iter().any(|b| id.starts_with(b)) {
        return Some(SourceApp::GoogleMeet);
    }
    None
}
