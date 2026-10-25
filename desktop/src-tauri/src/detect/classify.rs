//! Maps a process identity (Windows image name / package family name, macOS bundle id) to `source_app`.
//! Identities from docs/research/desktop-audio-native.md §2.7 and §3.6.
//!
//! Browsers are never a call app by themselves: a browser tree is identified by the site it shows
//! (`detect::sites`), so the classifier only answers whether an identity is a browser.

use serde::{Deserialize, Serialize};

#[derive(Clone, Copy, Debug, PartialEq, Eq, Hash, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum SourceApp {
    Whatsapp,
    WhatsappBusiness,
    WhatsappWeb,
    GoogleMeet,
    Zoom,
    MicrosoftTeams,
    VoiceNote,
    Other,
}

impl SourceApp {
    pub const ALL: [SourceApp; 8] = [
        SourceApp::Whatsapp,
        SourceApp::WhatsappBusiness,
        SourceApp::WhatsappWeb,
        SourceApp::GoogleMeet,
        SourceApp::Zoom,
        SourceApp::MicrosoftTeams,
        SourceApp::VoiceNote,
        SourceApp::Other,
    ];

    /// The wire value (`source_app` in docs/API.md).
    pub fn as_str(self) -> &'static str {
        match self {
            SourceApp::Whatsapp => "whatsapp",
            SourceApp::WhatsappBusiness => "whatsapp_business",
            SourceApp::WhatsappWeb => "whatsapp_web",
            SourceApp::GoogleMeet => "google_meet",
            SourceApp::Zoom => "zoom",
            SourceApp::MicrosoftTeams => "microsoft_teams",
            SourceApp::VoiceNote => "voice_note",
            SourceApp::Other => "other",
        }
    }

    /// The name people read (tray tooltips, notifications); the same labels as the server.
    pub fn label(self) -> &'static str {
        match self {
            SourceApp::Whatsapp => "WhatsApp",
            SourceApp::WhatsappBusiness => "WhatsApp Business",
            SourceApp::WhatsappWeb => "WhatsApp Web",
            SourceApp::GoogleMeet => "Google Meet",
            SourceApp::Zoom => "Zoom",
            SourceApp::MicrosoftTeams => "Microsoft Teams",
            SourceApp::VoiceNote => "Voice note",
            SourceApp::Other => "Other",
        }
    }

    /// Parses a wire value; `None` for anything this version does not know.
    pub fn from_wire(value: &str) -> Option<SourceApp> {
        SourceApp::ALL.into_iter().find(|app| app.as_str() == value)
    }
}

/// Windows: image file name (e.g. `Zoom.exe`) and optional package family name.
pub fn classify_windows(image: &str, pfn: Option<&str>) -> Option<SourceApp> {
    let image = image.to_ascii_lowercase();
    if let Some(pfn) = pfn {
        let lower = pfn.to_ascii_lowercase();
        // WhatsApp Inc. publisher prefix covers WhatsApp and WhatsApp Beta Store packages.
        if pfn.starts_with("5319275A.") || lower.contains("whatsapp") {
            return Some(SourceApp::Whatsapp);
        }
        // New Microsoft Teams (MSIX package `MSTeams_8wekyb3d8bbwe`).
        if lower.starts_with("msteams_") {
            return Some(SourceApp::MicrosoftTeams);
        }
    }
    match image.as_str() {
        "whatsapp.exe" | "whatsapp.root.exe" => Some(SourceApp::Whatsapp),
        // Any other WhatsApp build (updated Store app, helper or beta executables).
        name if name.contains("whatsapp") => Some(SourceApp::Whatsapp),
        "zoom.exe" => Some(SourceApp::Zoom),
        // New Teams (`ms-teams.exe`) and classic Teams (`Teams.exe`).
        "ms-teams.exe" | "teams.exe" => Some(SourceApp::MicrosoftTeams),
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
    if id.starts_with("com.microsoft.teams2") {
        return Some(SourceApp::MicrosoftTeams);
    }
    None
}

const BROWSER_IMAGES: [&str; 8] = ["chrome.exe", "msedge.exe", "brave.exe", "firefox.exe", "opera.exe", "vivaldi.exe", "chromium.exe", "arc.exe"];

const BROWSER_BUNDLES: [&str; 8] = [
    "com.google.chrome",
    "com.microsoft.edgemac",
    "com.apple.safari",
    "com.brave.browser",
    "org.mozilla.firefox",
    "company.thebrowser.browser",
    "com.operasoftware.opera",
    "com.vivaldi.vivaldi",
];

/// Windows: is this image file name a web browser (case-insensitive)?
pub fn is_browser_image(image: &str) -> bool {
    BROWSER_IMAGES.iter().any(|b| b.eq_ignore_ascii_case(image.trim()))
}

/// macOS: is this bundle id a web browser or one of its helpers (prefix match, case-insensitive)?
pub fn is_browser_bundle(id: &str) -> bool {
    let id = id.trim().to_ascii_lowercase();
    BROWSER_BUNDLES.iter().any(|b| id.starts_with(b))
}

/// The call app a process identity names on either platform (bundle ids first: they never look like an image name).
pub fn classify_any(name: &str, pfn: Option<&str>) -> Option<SourceApp> {
    classify_macos(name).or_else(|| classify_windows(name, pfn))
}

/// Is this identity (image name or bundle id) a browser?
pub fn is_browser(name: &str) -> bool {
    is_browser_image(name) || is_browser_bundle(name)
}

/// macOS: the application a helper belongs to (`com.google.Chrome.helper.Renderer` → `com.google.Chrome`), used to
/// group a browser's or call app's helper processes with the app.
pub fn app_bundle_id(bundle_id: &str) -> &str {
    let lower = bundle_id.to_ascii_lowercase();
    match lower.find(".helper") {
        Some(at) if at > 0 => &bundle_id[..at],
        _ => bundle_id,
    }
}

/// macOS: the AppleScript application name of a browser that can report its tab URLs, and whether it uses Safari's
/// `current tab` (others: `active tab`). Firefox, Opera and Vivaldi have no such scripting support.
pub fn browser_script(bundle_id: &str) -> Option<(&'static str, bool)> {
    let id = bundle_id.to_ascii_lowercase();
    [
        ("com.google.chrome", "Google Chrome", false),
        ("com.microsoft.edgemac", "Microsoft Edge", false),
        ("com.brave.browser", "Brave Browser", false),
        ("company.thebrowser.browser", "Arc", false),
        ("com.apple.safari", "Safari", true),
    ]
    .into_iter()
    .find(|(prefix, _, _)| id.starts_with(prefix))
    .map(|(_, name, safari)| (name, safari))
}
