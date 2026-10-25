//! Call-app classification: every executable, package and bundle identity the auto-record feature has to
//! recognise, plus the near misses it has to leave alone.

use desktop_lib::detect::classify::{SourceApp, classify_macos, classify_windows};

fn win(image: &str) -> Option<SourceApp> {
    classify_windows(image, None)
}

// ---- WhatsApp desktop ------------------------------------------------------------------------------------------

#[test]
fn whatsapp_executables_are_recognised_in_any_letter_case() {
    for image in [
        "WhatsApp.exe",
        "whatsapp.exe",
        "WHATSAPP.EXE",
        "WhatsApp.Root.exe",
        "whatsapp.root.exe",
        "WhatsApp.Beta.exe",
        "WhatsAppDesktop.exe",
    ] {
        assert_eq!(win(image), Some(SourceApp::Whatsapp), "{image}");
    }
}

#[test]
fn whatsapp_packages_are_recognised_whatever_the_process_image_is() {
    let packages = [
        "5319275A.WhatsAppDesktop_cv1g1gvanyjgm",
        "5319275A.51895FA4EA97F_cv1g1gvanyjgm", // WhatsApp Beta: no "whatsapp" in the family name, matched by publisher prefix
        "WhatsAppInc.WhatsAppBeta_abc123",
    ];
    for pfn in packages {
        assert_eq!(
            classify_windows("msedgewebview2.exe", Some(pfn)),
            Some(SourceApp::Whatsapp),
            "{pfn}"
        );
        assert_eq!(
            classify_windows("", Some(pfn)),
            Some(SourceApp::Whatsapp),
            "{pfn} with an unreadable image name"
        );
    }
}

#[test]
fn a_webview2_helper_on_its_own_is_not_a_call_app() {
    // The microphone owner of a WebView2 app is msedgewebview2.exe; only the ancestor walk can attribute it.
    assert_eq!(win("msedgewebview2.exe"), None);
    assert_eq!(win("MSEDGEWEBVIEW2.EXE"), None);
    assert_eq!(
        classify_windows(
            "msedgewebview2.exe",
            Some("Microsoft.WindowsNotepad_8wekyb3d8bbwe")
        ),
        None
    );
}

// ---- Zoom ------------------------------------------------------------------------------------------------------

#[test]
fn zoom_is_recognised_by_image_name_only() {
    for image in ["Zoom.exe", "zoom.exe", "ZOOM.EXE"] {
        assert_eq!(win(image), Some(SourceApp::Zoom), "{image}");
    }
    // Look-alikes and Zoom helper binaries are not the call process.
    for image in [
        "ZoomIt.exe",
        "ZoomIt64.exe",
        "zoom-updater.exe",
        "CptHost.exe",
        "ZoomWebviewHost.exe",
        "zoomtext.exe",
    ] {
        assert_eq!(win(image), None, "{image}");
    }
}

// ---- Browsers (Google Meet) ------------------------------------------------------------------------------------

#[test]
fn mainstream_browsers_are_labelled_google_meet() {
    for image in [
        "chrome.exe",
        "Chrome.exe",
        "msedge.exe",
        "MSEdge.exe",
        "brave.exe",
        "firefox.exe",
        "opera.exe",
    ] {
        assert_eq!(win(image), Some(SourceApp::GoogleMeet), "{image}");
    }
}

#[test]
fn browser_launchers_and_proxies_are_not_the_audio_process() {
    for image in [
        "chrome_proxy.exe",
        "chrome_pwa_launcher.exe",
        "msedge_proxy.exe",
        "pwahelper.exe",
        "iexplore.exe",
    ] {
        assert_eq!(win(image), None, "{image}");
    }
}

#[test]
#[ignore = "A1.3: Vivaldi and Chromium must be recognised as browsers, like Chrome"]
fn other_chromium_browsers_are_classified_like_chrome() {
    // Whatever a browser process maps to, every supported Chromium browser has to map to the same thing.
    let chrome = win("chrome.exe");
    let different: Vec<&str> = ["vivaldi.exe", "chromium.exe"]
        .into_iter()
        .filter(|image| win(image) != chrome)
        .collect();
    assert!(
        different.is_empty(),
        "browsers classified differently from Chrome: {different:?}"
    );
}

// ---- Teams and the negatives -----------------------------------------------------------------------------------

#[test]
fn teams_calls_are_not_auto_detected() {
    assert_eq!(win("ms-teams.exe"), None);
    assert_eq!(win("Teams.exe"), None);
    assert_eq!(
        classify_windows("ms-teams.exe", Some("MSTeams_8wekyb3d8bbwe")),
        None
    );
}

#[test]
fn unrelated_and_empty_identities_are_ignored() {
    for image in [
        "notepad.exe",
        "discord.exe",
        "slack.exe",
        "spotify.exe",
        "obs64.exe",
        "EchoLedger.exe",
        "echoledger-desktop.exe",
        "svchost.exe",
        "explorer.exe",
        "",
        " ",
        ".exe",
    ] {
        assert_eq!(win(image), None, "{image:?}");
    }
    assert_eq!(classify_windows("", Some("")), None);
    assert_eq!(
        classify_windows(
            "notepad.exe",
            Some("Microsoft.WindowsNotepad_8wekyb3d8bbwe")
        ),
        None
    );
    assert_eq!(
        classify_windows("outlook.exe", Some("5319275")),
        None,
        "a near miss of the WhatsApp publisher prefix"
    );
}

#[test]
fn a_package_identity_outranks_the_image_name() {
    // WhatsApp helpers shipped inside the WhatsApp package are WhatsApp even when launched as a browser-like image.
    assert_eq!(
        classify_windows("chrome.exe", Some("5319275A.WhatsAppDesktop_cv1g1gvanyjgm")),
        Some(SourceApp::Whatsapp)
    );
    // An unrelated package does not hide a recognisable image.
    assert_eq!(
        classify_windows("Zoom.exe", Some("SomeVendor.App_abc")),
        Some(SourceApp::Zoom)
    );
}

#[test]
fn window_titles_are_not_an_input_to_classification() {
    // The classifier only receives an image name and package family; a window title passed as an image must not
    // be mistaken for a browser or call app unless it literally names one.
    assert_eq!(win("Meet - abc-defg-hij - Google Chrome"), None);
    assert_eq!(win("Zoom Meeting"), None);
}

// ---- macOS -----------------------------------------------------------------------------------------------------

#[test]
fn macos_whatsapp_and_business_bundles() {
    assert_eq!(
        classify_macos("net.whatsapp.WhatsApp"),
        Some(SourceApp::Whatsapp)
    );
    assert_eq!(
        classify_macos("NET.WHATSAPP.WHATSAPP"),
        Some(SourceApp::Whatsapp)
    );
    assert_eq!(
        classify_macos("desktop.WhatsApp"),
        Some(SourceApp::Whatsapp)
    );
    assert_eq!(
        classify_macos("net.whatsapp.WhatsApp.helper"),
        Some(SourceApp::Whatsapp)
    );
    assert_eq!(
        classify_macos("net.whatsapp.WhatsAppSMB"),
        Some(SourceApp::WhatsappBusiness)
    );
    assert_eq!(
        classify_macos("net.whatsapp.WhatsAppSMB.helper"),
        Some(SourceApp::WhatsappBusiness)
    );
    assert_eq!(classify_macos("net.whatsapp"), None);
}

#[test]
fn macos_zoom_bundles_including_helpers() {
    assert_eq!(classify_macos("us.zoom.xos"), Some(SourceApp::Zoom));
    assert_eq!(classify_macos("us.zoom.xos.helper"), Some(SourceApp::Zoom));
    assert_eq!(classify_macos("US.ZOOM.XOS"), Some(SourceApp::Zoom));
    assert_eq!(classify_macos("us.zoom.ZoomPhone"), None);
    assert_eq!(classify_macos("us.zoom"), None);
}

#[test]
fn macos_browsers_are_labelled_google_meet() {
    for id in [
        "com.google.Chrome",
        "com.google.Chrome.helper",
        "com.google.Chrome.canary",
        "com.microsoft.edgemac",
        "com.microsoft.edgemac.Beta",
        "com.apple.Safari",
        "com.brave.Browser",
        "org.mozilla.firefox",
        "company.thebrowser.Browser",
    ] {
        assert_eq!(classify_macos(id), Some(SourceApp::GoogleMeet), "{id}");
    }
}

#[test]
fn macos_negatives_and_teams() {
    for id in [
        "com.apple.Terminal",
        "com.apple.FaceTime",
        "com.tinyspeck.slackmacgap",
        "com.microsoft.teams2",
        "com.microsoft.Word",
        "com.spotify.client",
        "",
        "com",
    ] {
        assert_eq!(classify_macos(id), None, "{id:?}");
    }
}

#[test]
#[ignore = "A1.3: the macOS browser list must include Opera, as on Windows"]
fn browsers_recognised_on_windows_are_recognised_on_macos_too() {
    // Opera is treated like Chrome on Windows; its macOS bundle has to be treated like Chrome's there.
    assert_eq!(win("opera.exe"), win("chrome.exe"));
    assert_eq!(
        classify_macos("com.operasoftware.Opera"),
        classify_macos("com.google.Chrome")
    );
}

// ---- Vocabulary shared with the server -------------------------------------------------------------------------

const ALL: [SourceApp; 6] = [
    SourceApp::Whatsapp,
    SourceApp::WhatsappBusiness,
    SourceApp::GoogleMeet,
    SourceApp::Zoom,
    SourceApp::VoiceNote,
    SourceApp::Other,
];

#[test]
fn source_app_wire_names_match_the_api_vocabulary() {
    let names: Vec<&str> = ALL.iter().map(|a| a.as_str()).collect();
    assert_eq!(
        names,
        [
            "whatsapp",
            "whatsapp_business",
            "google_meet",
            "zoom",
            "voice_note",
            "other"
        ]
    );
    for app in ALL {
        assert_eq!(
            serde_json::to_value(app).unwrap(),
            serde_json::json!(app.as_str()),
            "serde name of {app:?}"
        );
        let back: SourceApp = serde_json::from_value(serde_json::json!(app.as_str())).unwrap();
        assert_eq!(back, app);
    }
}

#[test]
fn source_app_values_match_the_server_enum() {
    let path = std::path::Path::new(env!("CARGO_MANIFEST_DIR"))
        .join("..")
        .join("..")
        .join("web")
        .join("app")
        .join("Enums")
        .join("SourceApp.php");
    let php = std::fs::read_to_string(&path)
        .unwrap_or_else(|e| panic!("cannot read {}: {e}", path.display()));
    let mut server: Vec<String> = php
        .lines()
        .filter_map(|l| l.trim().strip_prefix("case "))
        .filter_map(|l| {
            l.split_once('=').map(|(_, v)| {
                v.trim()
                    .trim_end_matches(';')
                    .trim_matches('\'')
                    .to_string()
            })
        })
        .collect();
    let mut client: Vec<String> = ALL.iter().map(|a| a.as_str().to_string()).collect();
    server.sort();
    client.sort();
    assert_eq!(client, server);
}
