//! Which apps play sound now, for Live Translate on the Mac (pure; tested on every system). Core Audio lists processes,
//! not apps: a browser plays from a helper process (`com.google.Chrome.helper` is Chrome's), so processes are grouped by
//! the app they belong to ([`crate::detect::classify::app_bundle_id`]) and the person picks the app.
//!
//! Only what can be told to belong to one app is offered. A process without a bundle id cannot be named, and WebKit's
//! shared processes (`com.apple.WebKit.*`, where Safari and every app showing web pages play their sound) cannot be
//! told apart with the public API, so neither is listed: Live Translate never hears another app than the one picked.

use crate::detect::classify::app_bundle_id;

/// One process as Core Audio reports it.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Player {
    pub pid: u32,
    pub bundle: String,
    /// It plays sound now (`kAudioProcessPropertyIsRunningOutput`).
    pub output: bool,
}

/// One app that plays sound now, with every process of it that does.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct PlayingApp {
    /// Its bundle id (the app's, not a helper's), as Core Audio spells it.
    pub bundle: String,
    /// The lowest of its pids: what the app list hands back to start it.
    pub root: u32,
    pub pids: Vec<u32>,
}

/// Processes whose sound cannot be told to belong to one app (pure).
pub fn shared(bundle: &str) -> bool {
    bundle.is_empty() || bundle.to_ascii_lowercase().starts_with("com.apple.webkit.")
}

/// The apps playing sound now (pure): EchoLedger (`own`) and what cannot be named left out, helpers with their app,
/// sorted by bundle id.
pub fn group(processes: &[Player], own: u32) -> Vec<PlayingApp> {
    let mut apps: Vec<PlayingApp> = Vec::new();
    for p in processes.iter().filter(|p| p.output && p.pid != own && !shared(&p.bundle)) {
        let bundle = app_bundle_id(&p.bundle);
        match apps.iter_mut().find(|a| a.bundle.eq_ignore_ascii_case(bundle)) {
            Some(app) => {
                app.root = app.root.min(p.pid);
                if !app.pids.contains(&p.pid) {
                    app.pids.push(p.pid);
                }
            }
            None => apps.push(PlayingApp { bundle: bundle.to_string(), root: p.pid, pids: vec![p.pid] }),
        }
    }
    for app in &mut apps {
        app.pids.sort_unstable();
    }
    apps.sort_by_key(|a| a.bundle.to_ascii_lowercase());
    apps
}

/// The app among `apps` that `pid` (the list's root) stands for: the processes to hear.
pub fn pids_of(apps: &[PlayingApp], pid: u32) -> Option<Vec<u32>> {
    apps.iter().find(|a| a.root == pid || a.pids.contains(&pid)).map(|a| a.pids.clone())
}

/// A name for an app whose running name cannot be read (pure): the last part of its bundle id, `us.zoom.xos` →
/// "Xos" is no help, so the known ones are named and the rest keep their bundle id.
pub fn fallback_name(bundle: &str) -> String {
    let known = [
        ("com.google.chrome", "Google Chrome"),
        ("com.microsoft.edgemac", "Microsoft Edge"),
        ("com.brave.browser", "Brave Browser"),
        ("company.thebrowser.browser", "Arc"),
        ("org.mozilla.firefox", "Firefox"),
        ("us.zoom.xos", "zoom.us"),
        ("com.microsoft.teams2", "Microsoft Teams"),
        ("com.tinyspeck.slackmacgap", "Slack"),
        ("com.spotify.client", "Spotify"),
        ("com.hnc.discord", "Discord"),
        ("com.apple.music", "Music"),
        ("com.apple.quicktimeplayerx", "QuickTime Player"),
    ];
    let lower = bundle.to_ascii_lowercase();
    known.iter().find(|(id, _)| lower == *id).map_or_else(|| bundle.to_string(), |(_, name)| (*name).to_string())
}

#[cfg(test)]
mod tests {
    use super::*;

    fn p(pid: u32, bundle: &str, output: bool) -> Player {
        Player { pid, bundle: bundle.into(), output }
    }

    #[test]
    fn a_browser_s_helpers_are_the_browser_and_only_what_plays_is_listed() {
        let processes = [
            p(500, "com.google.Chrome", false),
            p(512, "com.google.Chrome.helper", true),
            p(530, "com.google.Chrome.helper.Renderer", true),
            p(700, "us.zoom.xos", true),
            p(800, "com.spotify.client", false),
            p(900, "com.example.echoledger", true),
        ];
        assert_eq!(
            group(&processes, 900),
            [
                PlayingApp { bundle: "com.google.Chrome".into(), root: 512, pids: vec![512, 530] },
                PlayingApp { bundle: "us.zoom.xos".into(), root: 700, pids: vec![700] },
            ],
            "Spotify plays nothing now; EchoLedger never lists itself"
        );
    }

    #[test]
    fn what_cannot_be_told_to_belong_to_one_app_is_never_listed() {
        // Safari's pages play from WebKit's shared processes, like every app that shows web pages.
        let processes = [p(300, "com.apple.WebKit.GPU", true), p(301, "com.apple.WebKit.WebContent", true), p(302, "", true), p(400, "com.apple.Safari", false)];
        assert!(group(&processes, 1).is_empty());
        assert!(shared("com.apple.WebKit.GPU") && shared("") && !shared("com.apple.Music"));
    }

    #[test]
    fn the_app_picked_brings_all_its_playing_processes() {
        let apps = group(&[p(512, "com.google.Chrome.helper", true), p(530, "com.google.Chrome.helper", true), p(700, "us.zoom.xos", true)], 1);
        assert_eq!(pids_of(&apps, 512), Some(vec![512, 530]));
        assert_eq!(pids_of(&apps, 530), Some(vec![512, 530]), "any of its processes stands for it");
        assert_eq!(pids_of(&apps, 700), Some(vec![700]));
        assert_eq!(pids_of(&apps, 999), None, "an app that stopped playing");
    }

    #[test]
    fn apps_without_a_running_name_get_a_known_one_or_their_bundle_id() {
        assert_eq!(fallback_name("com.google.Chrome"), "Google Chrome");
        assert_eq!(fallback_name("us.zoom.xos"), "zoom.us");
        assert_eq!(fallback_name("com.example.game"), "com.example.game");
    }
}
