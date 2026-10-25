//! Call detection: a call is two-way audio in one application tree; browsers are judged by the site they show; the
//! label is decided once at start (plan A1.1-A1.9). Everything here is pure: trees are built by hand.

use desktop_lib::detect::classify::SourceApp;
use desktop_lib::detect::decide::{
    ASK_AGAIN_AFTER_MS, AskTracker, CallTracker, IgnoreReason, TreeAudio, Verdict, decide, identify, pick_candidate,
};
use desktop_lib::detect::sites::{
    SiteRule, SiteVerdict, builtin_rules, match_site, parse_osascript_list, strip_browser_suffix, url_host,
};
use desktop_lib::detect::state::{MicUseEvent, MicUseState};
use desktop_lib::detect::{MicUser, explain, never_rule_for};

// ---- Builders ----------------------------------------------------------------------------------------------------

fn tree(pid: u32, name: &str, render: bool, titles: &[&str]) -> TreeAudio {
    TreeAudio {
        root_pid: pid,
        name: name.into(),
        pfn: None,
        capture_active: true,
        render_active: render,
        titles: titles.iter().map(|t| t.to_string()).collect(),
        urls: Vec::new(),
    }
}

fn chrome(render: bool, titles: &[&str]) -> TreeAudio {
    tree(4_200, "chrome.exe", render, titles)
}

fn with_urls(mut t: TreeAudio, urls: &[&str]) -> TreeAudio {
    t.urls = urls.iter().map(|u| u.to_string()).collect();
    t
}

fn verdict(t: &TreeAudio) -> Verdict {
    decide(t, &[], false, true)
}

fn rule(id: &str, app: &str, contains: Option<&str>, priority: u8, enabled: bool) -> SiteRule {
    SiteRule {
        id: id.into(),
        app: app.into(),
        host: None,
        title_prefix: None,
        title_contains: contains.map(Into::into),
        title_equals: None,
        priority,
        enabled,
    }
}

fn strings(v: &[&str]) -> Vec<String> {
    v.iter().map(|s| s.to_string()).collect()
}

// ---- Browsers are identified by site (A1.3, A1.4, A1.9) ----------------------------------------------------------

#[test]
fn chatgpt_in_chrome_is_never_recorded() {
    // Dictation into ChatGPT: the built-in never-record rule matches the title, with or without audio playing.
    for render in [false, true] {
        let v = verdict(&chrome(render, &["ChatGPT - Google Chrome"]));
        assert_eq!(v, Verdict::Ignore(IgnoreReason::NeverRecord), "render {render}");
        assert!(!matches!(v, Verdict::Record(_)));
    }
    assert_eq!(verdict(&chrome(false, &["ChatGPT"])), Verdict::Ignore(IgnoreReason::NeverRecord));
}

#[test]
fn a_site_nobody_knows_is_ignored_as_an_unknown_site() {
    assert_eq!(
        verdict(&chrome(true, &["Some article about pottery - Google Chrome"])),
        Verdict::Ignore(IgnoreReason::UnknownSite)
    );
    assert_eq!(verdict(&chrome(true, &[])), Verdict::Ignore(IgnoreReason::UnknownSite), "no titles at all");
}

#[test]
fn a_meet_call_in_chrome_is_recorded_as_google_meet() {
    let t = chrome(true, &["Meet - abc-defg-hij - Google Chrome"]);
    assert_eq!(verdict(&t), Verdict::Record(SourceApp::GoogleMeet));
    assert_eq!(identify(&t, &[]), (Some(SourceApp::GoogleMeet), Some("meet.google.com".into()), false));
}

#[test]
fn whatsapp_web_is_recorded_as_whatsapp_web() {
    assert_eq!(verdict(&chrome(true, &["(3) WhatsApp - Google Chrome"])), Verdict::Record(SourceApp::WhatsappWeb));
    assert_eq!(verdict(&chrome(true, &["WhatsApp"])), Verdict::Record(SourceApp::WhatsappWeb));
}

#[test]
fn a_site_without_audio_playing_is_dictation() {
    assert_eq!(
        verdict(&chrome(false, &["Meet - abc-defg-hij - Google Chrome"])),
        Verdict::Ignore(IgnoreReason::Dictation)
    );
}

#[test]
fn never_record_wins_over_a_call_site_in_another_window() {
    let t = chrome(true, &["chatgpt.com - Google Chrome", "Meet - x - Google Chrome"]);
    assert_eq!(verdict(&t), Verdict::Ignore(IgnoreReason::NeverRecord));
    let (app, site, never) = identify(&t, &[]);
    assert_eq!((app, site.as_deref(), never), (None, Some("chatgpt.com"), true));
}

#[test]
fn claude_and_gemini_are_never_recorded_by_host_in_the_title_or_the_url() {
    assert_eq!(verdict(&chrome(true, &["claude.ai - Google Chrome"])), Verdict::Ignore(IgnoreReason::NeverRecord));
    let mac = with_urls(tree(77, "com.google.Chrome", true, &[]), &["https://gemini.google.com/app/123"]);
    assert_eq!(verdict(&mac), Verdict::Ignore(IgnoreReason::NeverRecord));
}

#[test]
fn edge_titles_with_a_zero_width_space_in_the_suffix_are_understood() {
    assert_eq!(strip_browser_suffix("Zoom - Microsoft\u{200b} Edge"), "Zoom");
    assert_eq!(strip_browser_suffix("Zoom - Microsoft Edge"), "Zoom");
    assert_eq!(strip_browser_suffix("Zoom \u{2014} Mozilla Firefox"), "Zoom");
    assert_eq!(strip_browser_suffix("Zoom - Brave"), "Zoom");
    assert_eq!(strip_browser_suffix("A page title"), "A page title");
    let edge = tree(10, "msedge.exe", true, &["Meet - abc-defg-hij - Microsoft\u{200b} Edge"]);
    assert_eq!(verdict(&edge), Verdict::Record(SourceApp::GoogleMeet));
}

#[test]
fn zoom_web_matches_the_exact_title_zoom_only() {
    assert_eq!(verdict(&chrome(true, &["Zoom - Google Chrome"])), Verdict::Record(SourceApp::Zoom));
    assert_eq!(verdict(&chrome(true, &["zoom - Microsoft\u{200b} Edge"])), Verdict::Record(SourceApp::Zoom));
    assert_eq!(verdict(&chrome(true, &["Zoom Meeting - Google Chrome"])), Verdict::Record(SourceApp::Zoom));
    assert_eq!(
        verdict(&chrome(true, &["Zoomed-in photos - Google Chrome"])),
        Verdict::Ignore(IgnoreReason::UnknownSite),
        "title_equals is not a substring match"
    );
}

#[test]
fn teams_on_the_web_is_microsoft_teams() {
    assert_eq!(
        verdict(&chrome(true, &["Calendar | Microsoft Teams - Google Chrome"])),
        Verdict::Record(SourceApp::MicrosoftTeams)
    );
    let mac = with_urls(tree(9, "com.apple.Safari", true, &[]), &["https://teams.live.com/v2/"]);
    assert_eq!(verdict(&mac), Verdict::Record(SourceApp::MicrosoftTeams));
}

#[test]
fn a_user_rule_with_a_built_in_id_replaces_it() {
    let disable_whatsapp_web = SiteRule { enabled: false, ..builtin_rules().into_iter().find(|r| r.id == "builtin.whatsapp_web").unwrap() };
    let t = chrome(true, &["WhatsApp - Google Chrome"]);
    assert_eq!(decide(&t, std::slice::from_ref(&disable_whatsapp_web), false, true), Verdict::Ignore(IgnoreReason::UnknownSite));
    // Replacing a never-record built-in lets the user record that site.
    let allow_chatgpt = SiteRule { app: "other".into(), priority: 50, ..builtin_rules().into_iter().find(|r| r.id == "builtin.never_chatgpt").unwrap() };
    assert_eq!(
        decide(&chrome(true, &["ChatGPT - Google Chrome"]), &[allow_chatgpt], false, true),
        Verdict::Record(SourceApp::Other)
    );
}

#[test]
fn a_disabled_rule_is_ignored() {
    let t = chrome(true, &["Daily standup - Google Chrome"]);
    let off = rule("0d7a1d9e-1c2b-4f5e-8a9b-0c1d2e3f4a5b", "zoom", Some("standup"), 40, false);
    assert_eq!(decide(&t, std::slice::from_ref(&off), false, true), Verdict::Ignore(IgnoreReason::UnknownSite));
    let on = SiteRule { enabled: true, ..off };
    assert_eq!(decide(&t, &[on], false, true), Verdict::Record(SourceApp::Zoom));
}

#[test]
fn a_user_never_rule_blocks_a_site() {
    let never = rule("5b0d9a47-2f3c-4f1e-9d6a-7c8b9a0e1f2d", "never", Some("Private notes"), 100, true);
    let t = chrome(true, &["Private notes - Google Chrome", "Meet - abc - Google Chrome"]);
    assert_eq!(decide(&t, &[never], false, true), Verdict::Ignore(IgnoreReason::NeverRecord));
}

#[test]
fn rules_with_an_unknown_app_or_empty_patterns_never_match() {
    let typo = rule("7e3f1c2a-0b9d-4e8f-a1b2-c3d4e5f6a7b8", "skype", Some("Daily"), 90, true);
    let empty = rule("8f4a2d3b-1c0e-4f9a-b2c3-d4e5f6a7b8c9", "zoom", Some("   "), 90, true);
    let t = chrome(true, &["Daily - Google Chrome"]);
    assert_eq!(decide(&t, &[typo, empty], false, true), Verdict::Ignore(IgnoreReason::UnknownSite));
}

#[test]
fn the_higher_priority_rule_wins_across_windows() {
    // Meet (30) beats WhatsApp Web (10) whatever the window order.
    for titles in [["WhatsApp - Google Chrome", "Meet - abc - Google Chrome"], ["Meet - abc - Google Chrome", "WhatsApp - Google Chrome"]] {
        assert_eq!(verdict(&chrome(true, &titles)), Verdict::Record(SourceApp::GoogleMeet), "{titles:?}");
    }
    // Ties: user rules first, then table order.
    let tie = rule("9a5b3e4c-2d1f-4a0b-c3d4-e5f6a7b8c9d0", "zoom", Some("Meet"), 30, true);
    assert_eq!(
        match_site(&strings(&["Meet - abc - Google Chrome"]), &[], &[tie]),
        SiteVerdict::App { app: "zoom".into(), rule_id: "9a5b3e4c-2d1f-4a0b-c3d4-e5f6a7b8c9d0".into() }
    );
    assert_eq!(
        match_site(&strings(&["Zoom Meeting", "Microsoft Teams"]), &[], &[]),
        SiteVerdict::App { app: "zoom".into(), rule_id: "builtin.zoom".into() },
        "zoom comes before teams in the table"
    );
}

#[test]
fn tab_urls_match_by_host_or_subdomain_only() {
    let meet = |url: &str| match_site(&[], &strings(&[url]), &[]);
    assert_eq!(meet("https://meet.google.com/abc-defg-hij"), SiteVerdict::App { app: "google_meet".into(), rule_id: "builtin.meet".into() });
    assert_eq!(meet("https://us02web.zoom.us/wc/123/join"), SiteVerdict::App { app: "zoom".into(), rule_id: "builtin.zoom".into() });
    assert_eq!(meet("https://notzoom.us/meeting"), SiteVerdict::Unknown);
    assert_eq!(meet("https://meet.google.com.example.test/abc"), SiteVerdict::Unknown);
    assert_eq!(meet("https://example.test/?next=meet.google.com"), SiteVerdict::Unknown);
}

#[test]
fn url_hosts_are_parsed_without_scheme_user_port_or_path() {
    assert_eq!(url_host("https://user:pw@Meet.Google.com:443/abc?x#y").as_deref(), Some("meet.google.com"));
    assert_eq!(url_host("web.whatsapp.com/").as_deref(), Some("web.whatsapp.com"));
    assert_eq!(url_host("https://chatgpt.com./c/1").as_deref(), Some("chatgpt.com"));
    assert_eq!(url_host("http://[::1]:8080/").as_deref(), Some("::1"));
    assert_eq!(url_host("about:blank").as_deref(), Some("about"));
    assert_eq!(url_host(""), None);
    assert_eq!(url_host("https:///path"), None);
}

#[test]
fn osascript_lists_are_split_into_urls() {
    assert_eq!(
        parse_osascript_list("https://meet.google.com/abc, missing value, https://example.test/a\n"),
        strings(&["https://meet.google.com/abc", "https://example.test/a"])
    );
    assert!(parse_osascript_list("\n").is_empty());
}

// ---- Native apps need two-way audio (A1.1, A1.9) -----------------------------------------------------------------

#[test]
fn a_whatsapp_voice_message_is_not_a_call() {
    assert_eq!(verdict(&tree(300, "WhatsApp.Root.exe", false, &[])), Verdict::Ignore(IgnoreReason::Dictation));
}

#[test]
fn a_whatsapp_call_with_audio_both_ways_is_recorded() {
    assert_eq!(verdict(&tree(300, "WhatsApp.Root.exe", true, &[])), Verdict::Record(SourceApp::Whatsapp));
    let packaged = TreeAudio { pfn: Some("5319275A.WhatsAppDesktop_cv1g1gvanyjgm".into()), ..tree(300, "WhatsApp.exe", true, &[]) };
    assert_eq!(verdict(&packaged), Verdict::Record(SourceApp::Whatsapp));
}

#[test]
fn native_teams_zoom_and_mac_apps_are_recorded_with_two_way_audio() {
    assert_eq!(verdict(&tree(5, "ms-teams.exe", true, &[])), Verdict::Record(SourceApp::MicrosoftTeams));
    assert_eq!(verdict(&tree(5, "Zoom.exe", true, &[])), Verdict::Record(SourceApp::Zoom));
    assert_eq!(verdict(&tree(5, "com.microsoft.teams2", true, &[])), Verdict::Record(SourceApp::MicrosoftTeams));
    assert_eq!(verdict(&tree(5, "net.whatsapp.WhatsAppSMB", true, &[])), Verdict::Record(SourceApp::WhatsappBusiness));
    assert_eq!(verdict(&tree(5, "us.zoom.xos", false, &[])), Verdict::Ignore(IgnoreReason::Dictation));
}

#[test]
fn window_titles_do_not_relabel_a_native_app() {
    // Zoom's own window says "Zoom Meeting"; WhatsApp's says "WhatsApp": identity decides, not titles.
    assert_eq!(verdict(&tree(5, "WhatsApp.exe", true, &["Zoom Meeting"])), Verdict::Record(SourceApp::Whatsapp));
}

#[test]
fn unknown_apps_are_ignored_unless_the_user_asked_to_be_asked() {
    let discord = tree(8, "Discord.exe", true, &[]);
    assert_eq!(decide(&discord, &[], false, true), Verdict::Ignore(IgnoreReason::UnknownApp));
    assert_eq!(decide(&discord, &[], true, true), Verdict::Ask);
    let quiet = tree(8, "Discord.exe", false, &[]);
    assert_eq!(decide(&quiet, &[], true, true), Verdict::Ignore(IgnoreReason::UnknownApp), "no audio: never asked");
    assert_eq!(decide(&chrome(true, &["Some page"]), &[], true, true), Verdict::Ask);
    assert_eq!(decide(&chrome(false, &["Some page"]), &[], true, true), Verdict::Ignore(IgnoreReason::UnknownSite));
}

#[test]
fn an_app_on_the_users_never_list_is_ignored() {
    let never = SiteRule { title_equals: Some("discord.EXE".into()), ..rule("1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d", "never", None, 100, true) };
    assert_eq!(decide(&tree(8, "Discord.exe", true, &[]), std::slice::from_ref(&never), true, true), Verdict::Ignore(IgnoreReason::NeverRecord));
    // `title_equals` of a never rule names apps only; a browser is still judged by its site.
    let chrome_rule = SiteRule { title_equals: Some("chrome.exe".into()), ..never };
    assert_eq!(decide(&chrome(true, &["Meet - x"]), &[chrome_rule], true, true), Verdict::Record(SourceApp::GoogleMeet));
}

#[test]
fn auto_mode_off_ignores_everything() {
    assert_eq!(decide(&tree(5, "Zoom.exe", true, &[]), &[], true, false), Verdict::Ignore(IgnoreReason::AutoOff));
}

#[test]
fn a_whatsapp_package_running_a_browser_image_is_whatsapp_not_a_browser() {
    let t = TreeAudio { pfn: Some("5319275A.WhatsAppDesktop_cv1g1gvanyjgm".into()), ..chrome(true, &["ChatGPT"]) };
    assert_eq!(verdict(&t), Verdict::Record(SourceApp::Whatsapp));
}

// ---- Candidate choice ----------------------------------------------------------------------------------------------

#[test]
fn the_candidate_is_deterministic_native_apps_first_then_lowest_pid() {
    let trees = vec![
        chrome(true, &["Meet - abc"]),                   // pid 4200, browser
        tree(900, "Zoom.exe", true, &[]),               // native
        tree(700, "WhatsApp.exe", true, &[]),           // native, lower pid
        tree(100, "Discord.exe", true, &[]),            // unknown
        tree(50, "chrome.exe", false, &["Meet - abc"]), // dictation
    ];
    let verdicts: Vec<Verdict> = trees.iter().map(verdict).collect();
    assert_eq!(pick_candidate(&trees, &verdicts), Some((700, SourceApp::Whatsapp)));
    let mut reversed = trees.clone();
    reversed.reverse();
    let rv: Vec<Verdict> = reversed.iter().map(verdict).collect();
    assert_eq!(pick_candidate(&reversed, &rv), Some((700, SourceApp::Whatsapp)), "input order does not matter");
    let browsers_only = vec![tree(30, "msedge.exe", true, &["WhatsApp"]), tree(20, "chrome.exe", true, &["Zoom"])];
    let bv: Vec<Verdict> = browsers_only.iter().map(verdict).collect();
    assert_eq!(pick_candidate(&browsers_only, &bv), Some((20, SourceApp::Zoom)));
    assert_eq!(pick_candidate(&[], &[]), None);
}

// ---- The start window and the label decided once (A1.6, A1.9) ------------------------------------------------------

/// Runs the tracker at 1 Hz over `seconds` and returns the events with their times.
fn run(tracker: &mut CallTracker, from_s: u64, seconds: u64, trees: impl Fn(u64) -> Vec<TreeAudio>) -> Vec<(u64, MicUseEvent)> {
    let mut events = Vec::new();
    for s in from_s..from_s + seconds {
        let now = s * 1_000;
        let observed = trees(now);
        let verdicts: Vec<Verdict> = observed.iter().map(verdict).collect();
        if let Some(e) = tracker.tick(now, &observed, &verdicts) {
            events.push((now, e));
        }
    }
    events
}

fn meet(render: bool) -> TreeAudio {
    chrome(render, &["Meet - abc-defg-hij - Google Chrome"])
}

#[test]
fn a_call_starts_after_three_seconds_of_both_signals() {
    let mut tracker = CallTracker::new();
    let events = run(&mut tracker, 0, 6, |_| vec![meet(true)]);
    assert_eq!(events, vec![(3_000, MicUseEvent::Start(SourceApp::GoogleMeet))]);
    assert_eq!(tracker.recorded(), Some((4_200, SourceApp::GoogleMeet)));
}

#[test]
fn audio_dropping_inside_the_start_window_restarts_it() {
    let mut tracker = CallTracker::new();
    // Both signals at 0-1 s, render gone at 2 s, back from 3 s: the window restarts at 3 s and ends at 6 s.
    let events = run(&mut tracker, 0, 10, |now| vec![meet(now != 2_000)]);
    assert_eq!(events, vec![(6_000, MicUseEvent::Start(SourceApp::GoogleMeet))]);
}

#[test]
fn dictation_never_starts_a_recording_however_long_it_lasts() {
    let mut tracker = CallTracker::new();
    assert!(run(&mut tracker, 0, 120, |_| vec![meet(false)]).is_empty());
    assert!(run(&mut tracker, 0, 120, |_| vec![tree(300, "WhatsApp.exe", false, &[])]).is_empty());
    assert_eq!(tracker.state(), MicUseState::Idle);
}

#[test]
fn a_tab_switch_after_the_start_keeps_the_label_and_the_recording() {
    let mut tracker = CallTracker::new();
    let mut events = run(&mut tracker, 0, 5, |_| vec![meet(true)]);
    // The user switches to ChatGPT (never-record) and the call's audio goes quiet for a while.
    events.extend(run(&mut tracker, 5, 30, |now| vec![chrome(now < 20_000, &["ChatGPT - Google Chrome"])]));
    assert_eq!(events, vec![(3_000, MicUseEvent::Start(SourceApp::GoogleMeet))]);
    assert_eq!(tracker.state(), MicUseState::Recording { app: SourceApp::GoogleMeet });
    // The call ends: the microphone is released and the recording stops after the stop window.
    let end = run(&mut tracker, 35, 10, |_| Vec::new());
    assert_eq!(end, vec![(40_000, MicUseEvent::Stop(SourceApp::GoogleMeet))]);
    assert_eq!(tracker.recorded(), None);
}

#[test]
fn another_tree_with_the_same_app_restarts_the_start_window() {
    let mut tracker = CallTracker::new();
    let events = run(&mut tracker, 0, 8, |now| {
        let pid = if now < 2_000 { 300 } else { 301 };
        vec![tree(pid, "WhatsApp.exe", true, &[])]
    });
    assert_eq!(events, vec![(5_000, MicUseEvent::Start(SourceApp::Whatsapp))]);
    assert_eq!(tracker.recorded(), Some((301, SourceApp::Whatsapp)));
}

#[test]
fn only_the_recorded_tree_keeps_a_recording_alive() {
    let mut tracker = CallTracker::new();
    run(&mut tracker, 0, 4, |_| vec![tree(300, "WhatsApp.exe", true, &[])]);
    // WhatsApp hangs up while Zoom opens: the WhatsApp recording stops; Zoom must earn its own start window.
    let events = run(&mut tracker, 4, 12, |_| vec![tree(900, "Zoom.exe", true, &[])]);
    assert_eq!(
        events,
        vec![(9_000, MicUseEvent::Stop(SourceApp::Whatsapp)), (13_000, MicUseEvent::Start(SourceApp::Zoom))]
    );
}

#[test]
fn an_adopted_unknown_tree_is_recorded_as_other_until_it_releases_the_microphone() {
    let mut tracker = CallTracker::new();
    tracker.adopt(100);
    let mut events = run(&mut tracker, 0, 5, |_| vec![tree(100, "Discord.exe", true, &[])]);
    events.extend(run(&mut tracker, 5, 6, |_| Vec::new()));
    assert_eq!(events, vec![(3_000, MicUseEvent::Start(SourceApp::Other)), (10_000, MicUseEvent::Stop(SourceApp::Other))]);
    // Adoption ends with the tree: the next Discord session is unknown again.
    assert!(run(&mut tracker, 20, 10, |_| vec![tree(100, "Discord.exe", true, &[])]).is_empty());
}

// ---- The unknown-app chip (A1.5) -----------------------------------------------------------------------------------

#[test]
fn the_chip_is_offered_after_the_start_window_once_per_ten_minutes() {
    let mut ask = AskTracker::default();
    let trees = vec![tree(100, "Discord.exe", true, &[])];
    let verdicts = vec![Verdict::Ask];
    let mut shown = Vec::new();
    for s in 0..20 {
        if let Some(pid) = ask.tick(s * 1_000, &trees, &verdicts, false) {
            shown.push((s, pid));
        }
    }
    assert_eq!(shown, vec![(3, 100)]);
    assert_eq!(ask.tick(3_000 + ASK_AGAIN_AFTER_MS - 1_000, &trees, &verdicts, false), None);
    assert_eq!(ask.tick(3_000 + ASK_AGAIN_AFTER_MS, &trees, &verdicts, false), Some(100));
}

#[test]
fn the_chip_waits_while_something_records_and_restarts_when_the_ask_stops() {
    let mut ask = AskTracker::default();
    let trees = vec![tree(100, "Discord.exe", true, &[])];
    for s in 0..10 {
        assert_eq!(ask.tick(s * 1_000, &trees, &[Verdict::Ask], true), None, "recording");
    }
    assert_eq!(ask.tick(10_000, &trees, &[Verdict::Ask], false), Some(100));
    let mut fresh = AskTracker::default();
    fresh.tick(0, &trees, &[Verdict::Ask], false);
    fresh.tick(1_000, &trees, &[Verdict::Ignore(IgnoreReason::UnknownApp)], false);
    assert_eq!(fresh.tick(3_000, &trees, &[Verdict::Ask], false), None, "the window restarted at 3 s");
    assert_eq!(fresh.tick(6_000, &trees, &[Verdict::Ask], false), Some(100));
}

#[test]
fn ignore_silences_a_tree_until_it_releases_the_microphone() {
    let mut ask = AskTracker::default();
    let trees = vec![tree(100, "Discord.exe", true, &[])];
    ask.suppress(100);
    for s in 0..30 {
        assert_eq!(ask.tick(s * 1_000, &trees, &[Verdict::Ask], false), None);
    }
    assert!(ask.is_suppressed(100));
    ask.tick(30_000, &[], &[], false); // released
    assert!(!ask.is_suppressed(100));
    for s in 31..34 {
        ask.tick(s * 1_000, &trees, &[Verdict::Ask], false);
    }
    assert_eq!(ask.tick(34_000, &trees, &[Verdict::Ask], false), Some(100));
}

#[test]
fn always_ignore_builds_a_never_rule_for_the_host_the_title_or_the_app() {
    let id = || "2b3c4d5e-6f7a-4b8c-9d0e-1f2a3b4c5d6e".to_string();
    let host = never_rule_for(id(), "chrome.exe", true, Some("notes.example.test"), Some("Notes")).unwrap();
    assert_eq!((host.app.as_str(), host.host.as_deref(), host.title_contains.as_deref(), host.priority, host.enabled), ("never", Some("notes.example.test"), None, 100, true));
    let title = never_rule_for(id(), "chrome.exe", true, None, Some("Voice memo studio - Google Chrome")).unwrap();
    assert_eq!((title.host.as_deref(), title.title_contains.as_deref()), (None, Some("Voice memo studio")));
    let app = never_rule_for(id(), "Discord.exe", false, None, Some("ignored for apps")).unwrap();
    assert_eq!((app.title_equals.as_deref(), app.title_contains.as_deref()), (Some("Discord.exe"), None));
    assert_eq!(never_rule_for(id(), "chrome.exe", true, None, Some("  ")), None, "nothing specific to match");
    assert_eq!(never_rule_for(id(), "a protected process", false, None, None), None, "would match every protected process");
    // The rule it builds really silences that tree.
    assert_eq!(decide(&tree(8, "Discord.exe", true, &[]), &[app], true, true), Verdict::Ignore(IgnoreReason::NeverRecord));
    assert_eq!(
        decide(&chrome(true, &["Voice memo studio - Google Chrome"]), &[title], true, true),
        Verdict::Ignore(IgnoreReason::NeverRecord)
    );
}

// ---- The Record screen explainer (A1.8) ----------------------------------------------------------------------------

fn explained(t: &TreeAudio, recording: Option<(u32, SourceApp)>) -> MicUser {
    explain(t, &verdict(t), &[], recording)
}

#[test]
fn the_explainer_says_why_for_each_tree() {
    let meet_user = explained(&meet(true), None);
    assert_eq!((meet_user.app.as_deref(), meet_user.site.as_deref(), meet_user.decision.as_str(), meet_user.reason.as_deref()), (Some("google_meet"), Some("meet.google.com"), "auto_record", None));
    assert!(meet_user.holds_mic && meet_user.plays_audio);

    let dictation = explained(&tree(300, "WhatsApp.exe", false, &[]), None);
    assert_eq!((dictation.app.as_deref(), dictation.decision.as_str(), dictation.reason.as_deref()), (Some("whatsapp"), "ignored", Some("dictation")));
    assert!(!dictation.plays_audio);

    let unknown = explained(&chrome(false, &["Some page"]), None);
    assert_eq!((unknown.app, unknown.site, unknown.decision.as_str(), unknown.reason.as_deref()), (None, None, "ignored", Some("unknown_site")));

    let never = explained(&chrome(true, &["ChatGPT"]), None);
    assert_eq!((never.site.as_deref(), never.reason.as_deref()), (Some("chatgpt.com"), Some("never_record")));

    let off = explain(&meet(true), &decide(&meet(true), &[], false, false), &[], None);
    assert_eq!((off.decision.as_str(), off.reason.as_deref()), ("off", Some("auto_off")));

    let asked = explain(&tree(8, "Discord.exe", true, &[]), &Verdict::Ask, &[], None);
    assert_eq!((asked.decision.as_str(), asked.reason), ("ask", None));
}

#[test]
fn the_recorded_tree_keeps_its_label_in_the_explainer_after_a_tab_switch() {
    let switched = chrome(true, &["ChatGPT - Google Chrome"]);
    let user = explained(&switched, Some((4_200, SourceApp::GoogleMeet)));
    assert_eq!((user.app.as_deref(), user.decision.as_str(), user.reason), (Some("google_meet"), "recording", None));
    let other_tree = explained(&tree(5, "Zoom.exe", true, &[]), Some((4_200, SourceApp::GoogleMeet)));
    assert_eq!(other_tree.decision, "auto_record");
}

// ---- Process tree roots (Windows) --------------------------------------------------------------------------------

#[cfg(windows)]
mod roots {
    use std::collections::HashMap;

    use desktop_lib::audio::platform::windows::resolve_root_with;

    type Row = (u32, &'static str, Option<&'static str>);

    fn identities(rows: &[Row]) -> impl Fn(u32) -> (String, Option<String>) + use<> {
        let table: HashMap<u32, (String, Option<String>)> =
            rows.iter().map(|(pid, image, pfn)| (*pid, (image.to_string(), pfn.map(str::to_string)))).collect();
        move |pid| table.get(&pid).cloned().unwrap_or_default()
    }

    fn parents(chain: &[u32]) -> HashMap<u32, u32> {
        let mut map: HashMap<u32, u32> = chain.windows(2).map(|w| (w[0], w[1])).collect();
        map.insert(*chain.last().unwrap(), 0);
        map
    }

    #[test]
    fn a_chrome_utility_process_belongs_to_the_chrome_browser_process() {
        // chrome (audio service) <- chrome (browser) <- explorer
        let who = identities(&[(30, "chrome.exe", None), (20, "chrome.exe", None), (10, "explorer.exe", None)]);
        assert_eq!(resolve_root_with(30, &parents(&[30, 20, 10]), &who), 20);
        assert_eq!(resolve_root_with(20, &parents(&[30, 20, 10]), &who), 20, "the browser process is its own root");
    }

    #[test]
    fn a_webview2_helper_belongs_to_whatsapp() {
        let who = identities(&[
            (600, "msedgewebview2.exe", None),
            (500, "msedgewebview2.exe", None),
            (300, "WhatsApp.Root.exe", Some("5319275A.51895FA4EA97F_cv1g1gvanyjgm")),
            (200, "explorer.exe", None),
        ]);
        assert_eq!(resolve_root_with(600, &parents(&[600, 500, 300, 200]), &who), 300);
    }

    #[test]
    fn a_teams_webview_belongs_to_teams_and_zoom_helpers_to_zoom() {
        let teams = identities(&[(30, "msedgewebview2.exe", None), (20, "ms-teams.exe", Some("MSTeams_8wekyb3d8bbwe")), (10, "explorer.exe", None)]);
        assert_eq!(resolve_root_with(30, &parents(&[30, 20, 10]), &teams), 20);
        let zoom = identities(&[(30, "CptHost.exe", None), (20, "Zoom.exe", None), (10, "explorer.exe", None)]);
        assert_eq!(resolve_root_with(30, &parents(&[30, 20, 10]), &zoom), 20);
    }

    #[test]
    fn an_unknown_multi_process_app_is_rooted_at_its_top_same_name_ancestor() {
        let who = identities(&[(40, "Discord.exe", None), (30, "Discord.exe", None), (20, "Discord.exe", None), (10, "explorer.exe", None)]);
        assert_eq!(resolve_root_with(40, &parents(&[40, 30, 20, 10]), &who), 20);
        let single = identities(&[(40, "obs64.exe", None), (10, "explorer.exe", None)]);
        assert_eq!(resolve_root_with(40, &parents(&[40, 10]), &single), 40);
        assert_eq!(resolve_root_with(40, &HashMap::new(), &single), 40, "no parent table");
    }

    #[test]
    fn a_zoom_launched_from_chrome_is_its_own_tree() {
        let who = identities(&[(30, "Zoom.exe", None), (20, "chrome.exe", None), (10, "explorer.exe", None)]);
        assert_eq!(resolve_root_with(30, &parents(&[30, 20, 10]), &who), 30);
    }

    #[test]
    fn an_unreadable_process_stays_its_own_root() {
        let who = identities(&[(10, "explorer.exe", None)]);
        assert_eq!(resolve_root_with(40, &parents(&[40, 30, 10]), &who), 40);
    }

    #[test]
    fn cycles_and_self_parents_terminate() {
        let who = identities(&[(1, "app.exe", None), (2, "app.exe", None), (3, "app.exe", None)]);
        let r = resolve_root_with(1, &HashMap::from([(1, 2), (2, 3), (3, 1)]), &who);
        assert!([1, 2, 3].contains(&r));
        assert_eq!(resolve_root_with(5, &HashMap::from([(5, 5)]), identities(&[(5, "app.exe", None)])), 5);
        let browsers = identities(&[(1, "chrome.exe", None), (2, "chrome.exe", None)]);
        let r = resolve_root_with(1, &HashMap::from([(1, 2), (2, 1)]), &browsers);
        assert!([1, 2].contains(&r));
    }

    #[test]
    fn a_very_deep_same_name_chain_is_cheap() {
        let chain: Vec<u32> = (1..=20_000).rev().collect();
        let rows: Vec<(u32, String, Option<String>)> = chain.iter().map(|p| (*p, "app.exe".to_string(), None)).collect();
        let who = move |pid: u32| rows.iter().find(|r| r.0 == pid).map(|r| (r.1.clone(), r.2.clone())).unwrap_or_default();
        let started = std::time::Instant::now();
        let root = resolve_root_with(20_000, &parents(&chain), who);
        assert!(root >= 20_000 - 8, "the walk is bounded: {root}");
        assert!(started.elapsed() < std::time::Duration::from_secs(5));
    }
}
