//! Attribution of a microphone-holding helper process to the call app that launched it (Windows). WhatsApp's
//! desktop app hosts its calls in WebView2, so the process that owns the microphone is `msedgewebview2.exe`.

#![cfg(windows)]

use std::collections::HashMap;

use desktop_lib::audio::platform::windows::ancestor_app_with;
use desktop_lib::detect::classify::SourceApp;

type Row = (u32, &'static str, Option<&'static str>);

/// Process table: pid -> (image, package family). Unknown pids answer like a protected process (empty image).
fn identities(rows: &[Row]) -> impl Fn(u32) -> (String, Option<String>) + use<> {
    let table: HashMap<u32, (String, Option<String>)> = rows
        .iter()
        .map(|(pid, image, pfn)| (*pid, (image.to_string(), pfn.map(str::to_string))))
        .collect();
    move |pid| table.get(&pid).cloned().unwrap_or_default()
}

/// `[child, parent, grandparent, ...]` -> child -> parent links; the last entry's parent is 0.
fn parents(chain: &[u32]) -> HashMap<u32, u32> {
    let mut map: HashMap<u32, u32> = chain.windows(2).map(|w| (w[0], w[1])).collect();
    map.insert(*chain.last().unwrap(), 0);
    map
}

const WHATSAPP_BETA_PFN: &str = "5319275A.51895FA4EA97F_cv1g1gvanyjgm";
const WHATSAPP_PFN: &str = "5319275A.WhatsAppDesktop_cv1g1gvanyjgm";

#[test]
fn a_webview2_audio_helper_belongs_to_the_whatsapp_that_launched_it() {
    // msedgewebview2 (audio) <- msedgewebview2 (gpu) <- msedgewebview2 (browser) <- WhatsApp.Root.exe <- explorer
    let chain = [600, 500, 400, 300, 200];
    let who = identities(&[
        (500, "msedgewebview2.exe", None),
        (400, "msedgewebview2.exe", None),
        (300, "WhatsApp.Root.exe", Some(WHATSAPP_BETA_PFN)),
        (200, "explorer.exe", None),
    ]);
    assert_eq!(
        ancestor_app_with(600, &parents(&chain), who),
        Some(SourceApp::Whatsapp)
    );
}

#[test]
fn the_store_edition_and_the_beta_are_both_recognised_by_package() {
    for pfn in [WHATSAPP_PFN, WHATSAPP_BETA_PFN] {
        let who = identities(&[
            (20, "msedgewebview2.exe", None),
            (10, "launcher.exe", Some(pfn)),
        ]);
        assert_eq!(
            ancestor_app_with(30, &parents(&[30, 20, 10]), who),
            Some(SourceApp::Whatsapp),
            "{pfn}"
        );
    }
}

#[test]
fn the_whatsapp_executable_name_alone_is_enough() {
    let who = identities(&[(20, "msedgewebview2.exe", None), (10, "WhatsApp.exe", None)]);
    assert_eq!(
        ancestor_app_with(30, &parents(&[30, 20, 10]), who),
        Some(SourceApp::Whatsapp)
    );
}

#[test]
fn zoom_helpers_belong_to_zoom() {
    let who = identities(&[(20, "CptHost.exe", None), (10, "Zoom.exe", None)]);
    assert_eq!(
        ancestor_app_with(30, &parents(&[30, 20, 10]), who),
        Some(SourceApp::Zoom)
    );
}

#[test]
fn a_teams_webview_is_not_a_recognised_call_app() {
    let who = identities(&[
        (20, "msedgewebview2.exe", None),
        (10, "ms-teams.exe", Some("MSTeams_8wekyb3d8bbwe")),
        (5, "explorer.exe", None),
    ]);
    assert_eq!(ancestor_app_with(30, &parents(&[30, 20, 10, 5]), who), None);
}

#[test]
fn an_unrelated_webview_app_is_not_attributed_to_anything() {
    let who = identities(&[
        (20, "msedgewebview2.exe", None),
        (
            10,
            "Widgets.exe",
            Some("MicrosoftWindows.Client.WebExperience_cw5n1h2txyewy"),
        ),
        (5, "explorer.exe", None),
    ]);
    assert_eq!(ancestor_app_with(30, &parents(&[30, 20, 10, 5]), who), None);
}

#[test]
fn the_nearest_recognised_ancestor_decides() {
    // WhatsApp opened a link in Chrome, which hosts the call: the browser is the nearer, recognised owner.
    let who = identities(&[(20, "chrome.exe", None), (10, "WhatsApp.exe", None)]);
    assert_eq!(
        ancestor_app_with(30, &parents(&[30, 20, 10]), who),
        Some(SourceApp::GoogleMeet)
    );
}

#[test]
fn a_protected_ancestor_with_an_unreadable_identity_does_not_stop_the_walk() {
    // pid 20 cannot be opened (empty image, no package); the walk continues to WhatsApp above it.
    let who = identities(&[(10, "WhatsApp.exe", None)]);
    assert_eq!(
        ancestor_app_with(30, &parents(&[30, 20, 10]), who),
        Some(SourceApp::Whatsapp)
    );
}

#[test]
fn only_ancestors_count_never_the_process_itself() {
    let who = identities(&[(30, "WhatsApp.exe", None), (10, "explorer.exe", None)]);
    assert_eq!(
        ancestor_app_with(30, &parents(&[30, 10]), who),
        None,
        "recognising the process itself is the classifier's job"
    );
}

#[test]
fn the_walk_looks_at_eight_ancestors_and_no_further() {
    // pid 100 is the helper; its ancestors are 99 (1st), 98, ... 92 (8th), 91 (9th).
    let ladder: Vec<u32> = (91..=100).rev().collect();
    let found_at = |pid: u32| {
        ancestor_app_with(
            100,
            &parents(&ladder),
            identities(&[(pid, "WhatsApp.exe", None)]),
        )
    };
    assert_eq!(found_at(99), Some(SourceApp::Whatsapp), "first ancestor");
    assert_eq!(found_at(93), Some(SourceApp::Whatsapp), "seventh ancestor");
    assert_eq!(found_at(92), Some(SourceApp::Whatsapp), "eighth ancestor");
    assert_eq!(found_at(91), None, "ninth ancestor is out of reach");
}
#[test]
fn a_missing_link_or_the_root_ends_the_walk_without_a_result() {
    let who = identities(&[(10, "WhatsApp.exe", None)]);
    let mut broken = parents(&[30, 20, 10]);
    broken.remove(&20); // the process table no longer knows pid 20's parent
    assert_eq!(ancestor_app_with(30, &broken, &who), None);
    assert_eq!(ancestor_app_with(30, &HashMap::new(), &who), None);
    assert_eq!(
        ancestor_app_with(30, &HashMap::from([(30, 0)]), &who),
        None,
        "parent 0 is the idle process, not an app"
    );
}

#[test]
fn cycles_from_reused_process_ids_terminate() {
    let who = identities(&[(1, "explorer.exe", None), (2, "svchost.exe", None)]);
    assert_eq!(
        ancestor_app_with(1, &HashMap::from([(1, 2), (2, 1)]), &who),
        None
    );
    assert_eq!(ancestor_app_with(5, &HashMap::from([(5, 5)]), &who), None);
    assert_eq!(
        ancestor_app_with(5, &HashMap::from([(5, 6), (6, 7), (7, 5)]), &who),
        None
    );
}

#[test]
fn a_very_deep_process_tree_is_cheap() {
    let chain: Vec<u32> = (1..=20_000).rev().collect();
    let who = identities(&[(1, "WhatsApp.exe", None)]);
    let started = std::time::Instant::now();
    assert_eq!(ancestor_app_with(20_000, &parents(&chain), who), None);
    assert!(started.elapsed() < std::time::Duration::from_secs(5));
}
