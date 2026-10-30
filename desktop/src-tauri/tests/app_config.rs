//! Static application configuration: Content-Security-Policy, window capabilities, packaging metadata and
//! version consistency.

use serde_json::Value;

use desktop_lib::overlay::{DICTATION, OVERLAY, PILL, REVIEW, WINDOWS};

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
                configured.contains(&label) || [OVERLAY, DICTATION, PILL, REVIEW].contains(&label),
                "{file} grants permissions to unknown window `{label}`"
            );
        }
    }
    assert_eq!(WINDOWS, [OVERLAY, DICTATION, PILL, REVIEW]);
    assert_eq!((PILL, REVIEW), ("pill", "review"), "the labels the views and the IPC contract use");
}

#[test]
fn the_control_windows_get_no_more_than_events_and_dragging() {
    let controls = json("capabilities/controls.json");
    assert_eq!(controls["identifier"], "controls");
    assert_eq!(controls["windows"], serde_json::json!([PILL, REVIEW]));
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
    ] {
        assert!(info.contains(key), "Info.plist lacks {key}");
    }
    let entitlements = std::fs::read_to_string(manifest_dir().join("Entitlements.plist")).unwrap();
    assert!(entitlements.contains("com.apple.security.device.audio-input"));
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
fn this_release_is_version_1_3_0() {
    assert_eq!(env!("CARGO_PKG_VERSION"), "1.3.0");
    assert_eq!(tauri_conf()["version"], "1.3.0");
}

#[test]
fn an_updater_when_configured_refuses_unsigned_releases() {
    // v1.1 ships without the updater plugin (no endpoint, placeholder key, and it pulled in `zip` 4.6.1). When it
    // comes back it must carry a real key and refuse unsigned releases.
    let conf = tauri_conf();
    let updater = &conf["plugins"]["updater"];
    if updater.is_null() {
        return;
    }
    assert_eq!(updater["requireSignedVersion"], true);
    let key = updater["pubkey"].as_str().unwrap_or_default();
    assert!(!key.is_empty() && !key.contains("REPLACE"), "placeholder updater key");
}

#[test]
fn the_updater_plugin_is_not_built_in() {
    let manifest = std::fs::read_to_string(manifest_dir().join("Cargo.toml")).unwrap();
    assert!(!manifest.contains("tauri-plugin-updater"), "Cargo.toml still depends on the updater plugin");
    for (file, needle) in [("src/lib.rs", "tauri_plugin_updater"), ("capabilities/default.json", "updater:")] {
        let text = std::fs::read_to_string(manifest_dir().join(file)).unwrap();
        assert!(!text.contains(needle), "{file} still refers to the updater");
    }
}

#[test]
fn the_installer_names_its_publisher() {
    let conf = tauri_conf();
    assert_eq!(conf["bundle"]["publisher"], "SkillLeo");
    assert_eq!(conf["bundle"]["copyright"], "© 2026 SkillLeo");
}
