//! Call-app classification: every executable, package and bundle identity the auto-record feature has to
//! recognise, plus the near misses it has to leave alone.

use desktop_lib::detect::classify::{
    SourceApp, app_bundle_id, browser_script, classify_macos, classify_windows, is_browser_bundle, is_browser_image,
};

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

// ---- Browsers: identified by site, never by process name (A1.3) ------------------------------------------------

#[test]
fn browsers_are_not_a_call_app_by_themselves() {
    // A browser holding the microphone may be dictation into any website; only the site decides (detect::sites).
    for image in [
        "chrome.exe",
        "Chrome.exe",
        "msedge.exe",
        "MSEdge.exe",
        "brave.exe",
        "firefox.exe",
        "opera.exe",
        "vivaldi.exe",
        "chromium.exe",
        "arc.exe",
    ] {
        assert_eq!(win(image), None, "{image}");
        assert!(is_browser_image(image), "{image} is a browser");
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
        assert!(!is_browser_image(image), "{image} is not a browser process");
    }
    for image in ["msedgewebview2.exe", "WhatsApp.exe", "Zoom.exe", "", "chrome", "chrome.exe.bak"] {
        assert!(!is_browser_image(image), "{image:?}");
    }
}

#[test]
fn other_chromium_browsers_are_classified_like_chrome() {
    // Whatever a browser process maps to, every supported Chromium browser has to map to the same thing.
    let chrome = win("chrome.exe");
    let different: Vec<&str> = ["vivaldi.exe", "chromium.exe"]
        .into_iter()
        .filter(|image| win(image) != chrome || !is_browser_image(image))
        .collect();
    assert!(
        different.is_empty(),
        "browsers classified differently from Chrome: {different:?}"
    );
}

// ---- Teams and the negatives -----------------------------------------------------------------------------------

#[test]
fn teams_is_recognised_by_image_or_package() {
    assert_eq!(win("ms-teams.exe"), Some(SourceApp::MicrosoftTeams));
    assert_eq!(win("MS-TEAMS.EXE"), Some(SourceApp::MicrosoftTeams));
    assert_eq!(win("Teams.exe"), Some(SourceApp::MicrosoftTeams));
    assert_eq!(
        classify_windows("ms-teams.exe", Some("MSTeams_8wekyb3d8bbwe")),
        Some(SourceApp::MicrosoftTeams)
    );
    assert_eq!(
        classify_windows("msedgewebview2.exe", Some("MSTeams_8wekyb3d8bbwe")),
        Some(SourceApp::MicrosoftTeams),
        "a WebView2 helper inside the Teams package"
    );
    assert_eq!(win("teams-helper.exe"), None);
    assert_eq!(win("msteams.exe"), None);
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
fn macos_browsers_are_not_a_call_app_by_themselves() {
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
        "com.operasoftware.Opera",
        "com.vivaldi.Vivaldi",
    ] {
        assert_eq!(classify_macos(id), None, "{id}");
        assert!(is_browser_bundle(id), "{id} is a browser");
    }
    for id in ["com.apple.Terminal", "com.google.drivefs", "org.mozilla.thunderbird", "", "com"] {
        assert!(!is_browser_bundle(id), "{id:?}");
    }
}

#[test]
fn macos_negatives_and_teams() {
    for id in [
        "com.apple.Terminal",
        "com.apple.FaceTime",
        "com.tinyspeck.slackmacgap",
        "com.microsoft.Word",
        "com.spotify.client",
        "",
        "com",
    ] {
        assert_eq!(classify_macos(id), None, "{id:?}");
    }
    assert_eq!(classify_macos("com.microsoft.teams2"), Some(SourceApp::MicrosoftTeams));
    assert_eq!(classify_macos("com.microsoft.teams2.helper"), Some(SourceApp::MicrosoftTeams));
}

#[test]
fn browsers_recognised_on_windows_are_recognised_on_macos_too() {
    // Opera is treated like Chrome on Windows; its macOS bundle has to be treated like Chrome's there.
    assert_eq!(win("opera.exe"), win("chrome.exe"));
    assert_eq!(
        classify_macos("com.operasoftware.Opera"),
        classify_macos("com.google.Chrome")
    );
    assert_eq!(is_browser_image("opera.exe"), is_browser_bundle("com.operasoftware.Opera"));
}

#[test]
fn macos_helpers_group_with_their_app_and_scriptable_browsers_are_known() {
    assert_eq!(app_bundle_id("com.google.Chrome.helper.Renderer"), "com.google.Chrome");
    assert_eq!(app_bundle_id("com.google.Chrome.helper"), "com.google.Chrome");
    assert_eq!(app_bundle_id("us.zoom.xos"), "us.zoom.xos");
    assert_eq!(app_bundle_id(".helper"), ".helper");
    assert_eq!(browser_script("com.google.Chrome"), Some(("Google Chrome", false)));
    assert_eq!(browser_script("com.apple.Safari"), Some(("Safari", true)));
    assert_eq!(browser_script("company.thebrowser.Browser"), Some(("Arc", false)));
    assert_eq!(browser_script("org.mozilla.firefox"), None, "Firefox has no tab scripting");
}

// ---- Vocabulary shared with the server -------------------------------------------------------------------------

const ALL: [SourceApp; 8] = [
    SourceApp::Whatsapp,
    SourceApp::WhatsappBusiness,
    SourceApp::WhatsappWeb,
    SourceApp::GoogleMeet,
    SourceApp::Zoom,
    SourceApp::MicrosoftTeams,
    SourceApp::VoiceNote,
    SourceApp::Other,
];

#[test]
fn source_app_wire_names_match_the_api_vocabulary() {
    assert_eq!(ALL, SourceApp::ALL);
    let names: Vec<&str> = ALL.iter().map(|a| a.as_str()).collect();
    assert_eq!(
        names,
        [
            "whatsapp",
            "whatsapp_business",
            "whatsapp_web",
            "google_meet",
            "zoom",
            "microsoft_teams",
            "voice_note",
            "other"
        ]
    );
    let labels: Vec<&str> = ALL.iter().map(|a| a.label()).collect();
    assert_eq!(
        labels,
        ["WhatsApp", "WhatsApp Business", "WhatsApp Web", "Google Meet", "Zoom", "Microsoft Teams", "Voice note", "Other"]
    );
    for app in ALL {
        assert_eq!(SourceApp::from_wire(app.as_str()), Some(app));
    }
    assert_eq!(SourceApp::from_wire("skype"), None);
    assert_eq!(SourceApp::from_wire("Zoom"), None, "wire values are lower case");
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
