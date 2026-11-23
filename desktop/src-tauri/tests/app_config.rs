//! Static application configuration: Content-Security-Policy, window capabilities, packaging metadata and
//! version consistency.

use serde_json::Value;

use desktop_lib::overlay::{ASSIST, DICTATION, OVERLAY, PILL, REVIEW, WINDOWS};

fn manifest_dir() -> std::path::PathBuf {
    std::path::PathBuf::from(env!("CARGO_MANIFEST_DIR"))
}

fn json(relative: &str) -> Value {
    let path = manifest_dir().join(relative);
    let text = std::fs::read_to_string(&path).unwrap_or_else(|e| panic!("{}: {e}", path.display()));
    serde_json::from_str(&text).unwrap_or_else(|e| panic!("{} is not JSON: {e}", path.display()))
}

fn tauri_conf() -> Value {
    json("tauri.conf.json")
}

/// Every capability file, so a new one is held to the same rules.
fn capability_files() -> Vec<(String, Value)> {
    let mut files: Vec<String> = std::fs::read_dir(manifest_dir().join("capabilities"))
        .unwrap()
        .map(|e| e.unwrap().file_name().to_string_lossy().to_string())
        .filter(|name| name.ends_with(".json"))
        .map(|name| format!("capabilities/{name}"))
        .collect();
    files.sort();
    assert!(files.len() >= 3, "{files:?}");
    files.into_iter().map(|p| { let v = json(&p); (p, v) }).collect()
}

fn directive<'a>(csp: &'a Value, name: &str) -> &'a str {
    csp[name]
        .as_str()
        .unwrap_or_else(|| panic!("CSP has no {name}"))
}

// ---- Content-Security-Policy -----------------------------------------------------------------------------------

#[test]
fn the_production_policy_only_allows_ipc_for_network_access() {
    let conf = tauri_conf();
    let csp = &conf["app"]["security"]["csp"];
    let connect = directive(csp, "connect-src");
    assert_eq!(
        connect.split_whitespace().collect::<Vec<_>>(),
        ["ipc:", "http://ipc.localhost"],
        "the web view must not be able to open its own connections"
    );
    assert_eq!(directive(csp, "default-src"), "'self'");
    assert_eq!(directive(csp, "object-src"), "'none'");
}

#[test]
fn scripts_run_only_from_the_app_bundle() {
    let conf = tauri_conf();
    for key in ["csp", "devCsp"] {
        let script = directive(&conf["app"]["security"][key], "script-src");
        assert_eq!(script, "'self'", "{key}");
        assert!(!script.contains("unsafe"), "{key}");
    }
    assert_eq!(conf["app"]["security"]["freezePrototype"], true);
}

#[test]
fn the_development_policy_adds_only_local_hot_reload_sockets() {
    let conf = tauri_conf();
    let connect = directive(&conf["app"]["security"]["devCsp"], "connect-src");
    for source in connect.split_whitespace() {
        assert!(
            source == "ipc:"
                || source.starts_with("http://ipc.localhost")
                || source.starts_with("ws://localhost:"),
            "unexpected dev connect source {source}"
        );
    }
}

#[test]
fn media_may_come_from_https_origins_only_in_production() {
    let conf = tauri_conf();
    let production = directive(&conf["app"]["security"]["csp"], "media-src");
    assert!(
        !production.contains("http:") && !production.contains("http://"),
        "{production}"
    );
}

// ---- Capabilities ----------------------------------------------------------------------------------------------

#[test]
fn capabilities_name_only_windows_that_exist() {
    let conf = tauri_conf();
    let configured: Vec<&str> = conf["app"]["windows"]
        .as_array()
        .unwrap()
        .iter()
        .map(|w| w["label"].as_str().unwrap())
        .collect();
    for (file, capability) in capability_files() {
        for window in capability["windows"].as_array().unwrap() {
            let label = window.as_str().unwrap();
            assert!(
                configured.contains(&label) || [OVERLAY, DICTATION, PILL, REVIEW, ASSIST].contains(&label),
                "{file} grants permissions to unknown window `{label}`"
            );
        }
    }
    assert_eq!(WINDOWS, [OVERLAY, DICTATION, PILL, REVIEW, ASSIST]);
    assert_eq!((PILL, REVIEW, ASSIST), ("pill", "review", "assist"), "the labels the views and the IPC contract use");
}

#[test]
fn the_control_windows_get_no_more_than_events_and_dragging() {
    let controls = json("capabilities/controls.json");
    assert_eq!(controls["identifier"], "controls");
    assert_eq!(controls["windows"], serde_json::json!([PILL, REVIEW, ASSIST]), "the Live Assist card drags like the pill");
    assert_eq!(controls["platforms"], serde_json::json!(["windows", "macOS"]));
    assert_eq!(
        controls["permissions"],
        serde_json::json!(["core:event:default", "core:window:default", "core:window:allow-start-dragging"])
    );
}

#[test]
fn every_overlay_type_window_is_covered_by_exactly_one_capability() {
    for label in WINDOWS {
        let holders: Vec<String> = capability_files()
            .into_iter()
            .filter(|(_, c)| c["windows"].as_array().unwrap().iter().any(|w| w == label))
            .map(|(file, _)| file)
            .collect();
        assert_eq!(holders.len(), 1, "{label}: {holders:?}");
    }
}

#[test]
fn the_overlay_windows_get_no_more_than_events_and_dragging() {
    let overlay = json("capabilities/overlay.json");
    let windows: Vec<&str> = overlay["windows"]
        .as_array()
        .unwrap()
        .iter()
        .map(|w| w.as_str().unwrap())
        .collect();
    assert_eq!(windows, [OVERLAY, DICTATION]);
    for permission in overlay["permissions"].as_array().unwrap() {
        let p = permission.as_str().unwrap();
        assert!(
            p.starts_with("core:event") || p.starts_with("core:window:"),
            "overlay holds `{p}`"
        );
    }
}

#[test]
fn no_window_can_reach_the_file_system_shell_or_raw_http() {
    for (file, capability) in capability_files() {
        for permission in capability["permissions"].as_array().unwrap() {
            let p = permission.as_str().unwrap();
            for forbidden in [
                "fs:",
                "shell:",
                "http:",
                "process:",
                "os:",
                "sql:",
                "websocket:",
            ] {
                assert!(!p.starts_with(forbidden), "{file} grants `{p}`");
            }
        }
    }
}

#[test]
fn the_main_window_capability_covers_only_the_main_window_on_the_supported_platforms() {
    let main = json("capabilities/default.json");
    assert_eq!(main["windows"], serde_json::json!(["main"]));
    assert_eq!(main["platforms"], serde_json::json!(["windows", "macOS"]));
}

// ---- Packaging -------------------------------------------------------------------------------------------------

#[test]
fn every_declared_bundle_icon_exists() {
    let conf = tauri_conf();
    for icon in conf["bundle"]["icon"].as_array().unwrap() {
        let path = manifest_dir().join(icon.as_str().unwrap());
        assert!(path.exists(), "missing {}", path.display());
    }
}

#[test]
fn the_app_identifier_is_the_one_the_documentation_and_keychain_use() {
    assert_eq!(tauri_conf()["identifier"], "com.echoledger.desktop");
}

#[test]
fn the_main_window_starts_hidden_so_the_tray_app_does_not_flash_on_login() {
    let conf = tauri_conf();
    let main = &conf["app"]["windows"][0];
    assert_eq!(main["label"], "main");
    assert_eq!(main["visible"], false);
}

#[test]
fn macos_builds_require_the_audio_tap_capable_release() {
    let mac = json("tauri.macos.conf.json");
    assert_eq!(mac["bundle"]["macOS"]["minimumSystemVersion"], "14.4");
}

#[test]
fn macos_declares_the_microphone_and_audio_capture_purposes() {
    let info = std::fs::read_to_string(manifest_dir().join("Info.plist")).unwrap();
    for key in [
        "NSMicrophoneUsageDescription",
        "NSAudioCaptureUsageDescription",
        "NSAppleEventsUsageDescription",
    ] {
        assert!(info.contains(key), "Info.plist lacks {key}");
    }
    // A signed build has the hardened runtime, which refuses what these do not allow: the microphone, and the Apple events
    // osascript sends for "Read browser tab addresses" (without it the switch silently reads nothing).
    let entitlements = std::fs::read_to_string(manifest_dir().join("Entitlements.plist")).unwrap();
    let flat: String = entitlements.split_whitespace().collect();
    for key in ["com.apple.security.device.audio-input", "com.apple.security.automation.apple-events", "com.apple.security.network.client"] {
        assert!(flat.contains(&format!("<key>{key}</key><true/>")), "Entitlements.plist does not allow {key}");
    }
}

#[test]
fn the_echoledger_link_scheme_is_registered_by_the_installer_and_on_macos() {
    // v1.5 "Links in the apps": the bundler writes the Windows installer's protocol keys from the deep-link schemes,
    // and macOS reads the scheme from Info.plist. The scheme is the one the share pages link to.
    let schemes = &tauri_conf()["plugins"]["deep-link"]["desktop"]["schemes"];
    assert_eq!(schemes, &serde_json::json!([desktop_lib::deep_link::SCHEME]));
    let info = std::fs::read_to_string(manifest_dir().join("Info.plist")).unwrap();
    assert!(info.contains("<key>CFBundleURLSchemes</key>"), "Info.plist lacks the URL scheme");
    assert!(info.contains("<string>echoledger</string>"), "Info.plist does not name the echoledger scheme");
}

#[test]
fn the_windows_installer_installs_per_user_without_elevation() {
    let windows = json("tauri.windows.conf.json");
    assert_eq!(
        windows["bundle"]["windows"]["nsis"]["installMode"],
        "currentUser"
    );
}

// ---- Versions --------------------------------------------------------------------------------------------------

#[test]
fn the_cargo_tauri_and_npm_versions_agree() {
    let cargo = env!("CARGO_PKG_VERSION");
    let tauri = tauri_conf()["version"].as_str().unwrap().to_string();
    let npm = json("../package.json")["version"]
        .as_str()
        .unwrap()
        .to_string();
    assert_eq!(
        (cargo, tauri.as_str(), npm.as_str()),
        (cargo, cargo, cargo),
        "Cargo.toml = {cargo}, tauri.conf.json = {tauri}, package.json = {npm}"
    );
}

#[test]
fn this_release_is_version_2_0_11() {
    assert_eq!(env!("CARGO_PKG_VERSION"), "2.0.11");
    assert_eq!(tauri_conf()["version"], "2.0.11");
}

#[test]
fn the_updater_carries_the_release_key_and_refuses_unsigned_releases() {
    // v1.5: the release signing key (minisign; its private half stays outside the repo); the endpoint is the signed-in server's
    // /downloads/desktop.json, set at run time, so none is configured.
    let conf = tauri_conf();
    let updater = &conf["plugins"]["updater"];
    assert_eq!(updater["requireSignedVersion"], true, "a signature must name the version it was made for");
    let key = updater["pubkey"].as_str().unwrap_or_default();
    let decoded = String::from_utf8(base64::Engine::decode(&base64::engine::general_purpose::STANDARD, key).expect("base64 key")).unwrap();
    assert!(decoded.starts_with("untrusted comment: minisign public key: FBF4156A0A9B5B35\n"), "{decoded}");
    assert_eq!(updater["endpoints"], serde_json::json!([]), "the endpoint comes from the signed-in server");
    assert!(updater.get("dangerousInsecureTransportProtocol").is_none() && updater.get("dangerousAcceptInvalidCerts").is_none());
    assert_eq!(updater["windows"]["installMode"], "passive");
    // The plugin reads the same block at start-up: a block it refuses would stop the app.
    let read: tauri_plugin_updater::Config = serde_json::from_value(updater.clone()).expect("the updater plugin reads its block");
    assert!(read.require_signed_version && !read.dangerous_insecure_transport_protocol && read.endpoints.is_empty());
    assert_eq!(read.pubkey, key);
}

#[test]
fn only_the_release_build_makes_signed_updater_packages() {
    // Signing needs the private key, which only the release shell (or a CI secret) has: everyday builds make none.
    assert_eq!(tauri_conf()["bundle"]["createUpdaterArtifacts"], false);
    assert_eq!(json("tauri.release.conf.json"), serde_json::json!({"bundle": {"createUpdaterArtifacts": true}}));
}

#[test]
fn the_updater_runs_from_rust_only_and_without_zip() {
    // v1.1 dropped the plugin partly because it pulled in `zip`: it now comes without its default features (zip,
    // system proxy), and no window may call it (the core checks, downloads and installs).
    let manifest = std::fs::read_to_string(manifest_dir().join("Cargo.toml")).unwrap();
    let line = manifest.lines().find(|l| l.starts_with("tauri-plugin-updater")).expect("the updater plugin");
    assert!(line.contains("default-features = false") && line.contains("\"rustls-tls\"") && !line.contains("zip"), "{line}");
    let lock = std::fs::read_to_string(manifest_dir().join("Cargo.lock")).unwrap();
    assert!(!lock.contains("name = \"zip\""), "zip is in Cargo.lock");
    for (file, _) in capability_files() {
        let text = std::fs::read_to_string(manifest_dir().join(&file)).unwrap();
        assert!(!text.contains("updater:"), "{file} lets a window use the updater");
    }
    let npm = std::fs::read_to_string(manifest_dir().join("../package.json")).unwrap();
    assert!(!npm.contains("plugin-updater"), "no JavaScript half");
}

#[test]
fn the_installer_names_its_publisher() {
    let conf = tauri_conf();
    assert_eq!(conf["bundle"]["publisher"], "SkillLeo");
    assert_eq!(conf["bundle"]["copyright"], "© 2026 SkillLeo");
}

// ---- The smoke test (Phase 1B) ---------------------------------------------------------------------------------

/// lib.rs from its setup on: what runs in a smoke test is what stands before its early return.
fn setup_source() -> String {
    let source = std::fs::read_to_string(manifest_dir().join("src/lib.rs")).unwrap();
    let start = source.find(".setup(move |app|").expect("the setup");
    source[start..source.find(".on_window_event").unwrap()].to_string()
}

#[test]
fn the_smoke_test_returns_before_anything_that_records_uploads_or_polls_starts() {
    let setup = setup_source();
    let leaves = setup.find("return Ok(());").expect("the smoke branch returns");
    assert!(setup[..leaves].contains("smoke::drive(handle)"), "the branch runs the test");
    for later in [
        "updates::install_kept",
        "shortcuts::register_all",
        "detect::spawn_auto_mode",
        "actions::spawn_upload_worker",
        "actions::restore_pending",
        "recovery::recover",
        "commands::spawn_idle_lock",
        "actions::spawn_activity_poll",
        "updates::spawn_quiet_checks",
        "privacy::bars::spawn_refresh",
        "state.api()",
        "actions::handle_links",
    ] {
        let at = setup.find(later).unwrap_or_else(|| panic!("{later} is gone from the setup"));
        assert!(at > leaves, "{later} would run in a smoke test");
    }
}

#[test]
fn the_smoke_test_does_not_hand_itself_over_to_a_running_app_or_touch_the_keychain() {
    let source = std::fs::read_to_string(manifest_dir().join("src/lib.rs")).unwrap();
    let guarded = source.find("if smoke_dir.is_none()").expect("the single-instance guard");
    let plugin = source.find("tauri_plugin_single_instance::init").unwrap();
    assert!(plugin > guarded && plugin < guarded + 200, "the single-instance plugin stays out of a smoke test");
    assert!(source.contains("if smoke { Box::new(MemoryStore::default()) } else { Box::new(KeyringStore) }"));
    assert!(source.contains("if smoke { Box::new(smoke::NoKeys) } else { Box::new(screens::store::KeychainKeys) }"));
}

// ---- Opening the app again (v1.8, 0.4) -------------------------------------------------------------------------

#[test]
fn opening_the_app_again_goes_where_a_second_launch_goes() {
    // A Mac starts no second copy when EchoLedger is opened from Finder, Launchpad, Spotlight or the Dock: the running
    // copy gets a reopen, which the installed v1.6.0 did not answer. Windows starts a second copy, which the
    // single-instance plugin hands to the running one. Both open the main window through `show_main_from_outside`.
    let lib = std::fs::read_to_string(manifest_dir().join("src/lib.rs")).unwrap();
    assert!(lib.contains("tauri_plugin_single_instance::init(|app, argv, _cwd| actions::show_main_from_outside(app, &argv))"), "a second launch opens the main window");
    let run = &lib[lib.find("app.run(|handle, event|").expect("the run loop")..];
    let reopen = run.find("if let RunEvent::Reopen { .. } = &event {").expect("the run loop answers a reopen");
    assert!(run[..reopen].trim_end().ends_with("#[cfg(target_os = \"macos\")]"), "the reopen arm is the Mac's");
    assert!(run[reopen..].trim_start_matches("if let RunEvent::Reopen { .. } = &event {").trim_start().starts_with("actions::show_main_from_outside(handle, &[]);"), "a reopen goes where a second launch goes");
    let actions = std::fs::read_to_string(manifest_dir().join("src/actions.rs")).unwrap();
    let body = &actions[actions.find("pub fn show_main_from_outside(app: &AppHandle, args: &[String]) {").expect("show_main_from_outside")..];
    let body = &body[..body.find("\n}\n").expect("the end of show_main_from_outside")];
    // The notified recording is taken (so forgotten) every time, and whether a main window existed is read before the
    // open builds one: only then does the decision open the recording.
    let (taken, exists, shown) = (body.find(".notified.lock()").expect("the notified recording"), body.find("get_webview_window(crate::lifecycle::MAIN).is_some()").expect("a main window exists"), body.find("crate::tray::show_main(app);").expect("shown"));
    assert!(body[taken..].starts_with(".notified.lock().unwrap_or_else(|p| p.into_inner()).take()"), "never kept for a later open");
    assert!(taken < shown && exists < shown, "read before the open");
    assert!(body.contains("crate::lifecycle::reopen_action(main_exists, notified.is_some())"), "the pure decision says whether the page changes");
}

// ---- Screen-share privacy commands (v1.8.1 audit, S4) ------------------------------------------------------------

#[test]
fn privacy_commands_that_change_or_reveal_answer_the_main_window_only() {
    // A floating window's page has no business with a picture of the screen, the log, or what is hidden.
    let commands = std::fs::read_to_string(manifest_dir().join("src/commands.rs")).unwrap();
    for name in ["share_check_run", "share_check_card", "share_check_answer", "privacy_show_in_shares", "privacy_hide_all", "privacy_hide_again", "privacy_set_hidden", "privacy_diagnostics", "privacy_move_to_other_display"] {
        let start = commands.find(&format!("fn {name}(")).unwrap_or_else(|| panic!("{name} is gone"));
        let body = &commands[start..];
        let body = &body[..body.find("\n}\n").unwrap()];
        assert!(body.lines().next().unwrap().contains("window: WebviewWindow"), "{name} knows which window asked");
        assert_eq!(body.lines().nth(1).unwrap().trim(), "crate::privacy::from_main_window(window.label())?;", "{name} refuses other windows first");
    }
    let drawn = &commands[commands.find("fn share_check_drawn(").unwrap()..];
    assert!(drawn[..drawn.find("\n}\n").unwrap()].contains("[crate::privacy::CHECK_TEST, crate::privacy::CHECK_CONTROL].contains(&window.label())"), "only the check's own windows count as drawn");
    assert!(commands.contains("pub async fn privacy_status(app: AppHandle)"), "the status every badge reads stays off the main thread");
}

#[test]
fn the_sharing_bar_patterns_never_wait_ahead_of_the_config_and_the_uploads() {
    // R21: the ask for newer patterns was awaited before the config, the library and the queue (up to 120 s offline).
    let setup = setup_source();
    let startup = &setup[setup.find("let startup = handle.clone();").expect("the startup task")..];
    let startup = &startup[..startup.find("});").unwrap()];
    assert!(!startup.contains("bars::refresh"), "not in the startup task");
    assert!(setup.contains("privacy::bars::spawn_refresh(handle.clone());"), "a task of its own, once a day");
}
