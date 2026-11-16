//! The interface flags (contract 2.0.0-a) read from `GET /api/config` and set with `PUT /api/account/ui`, against an
//! in-process HTTP server and the fixtures of tests/fixtures.

mod common;

use serde_json::json;

use common::{MockServer, Reply, Router};
use desktop_lib::net::api::ApiClient;
use desktop_lib::net::models::ServerConfig;

const TOKEN: &str = "7|eld_TOKEN_PLACEHOLDER";

fn fixture(name: &str) -> serde_json::Value {
    let path = std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("tests/fixtures").join(name);
    serde_json::from_str(&std::fs::read_to_string(path).unwrap()).unwrap()
}

fn client(server: &MockServer) -> ApiClient {
    common::init_tls();
    ApiClient::new(&server.base_url(), &[], Some(TOKEN.to_string())).expect("local client")
}

#[tokio::test]
async fn the_config_brings_the_flags_known_ones_only() {
    let server = MockServer::start(Router::new().fixed("GET", "/api/config", Reply::json(200, fixture("config_ui_flags.json"))));
    let flags = client(&server).config().await.unwrap().ui();
    assert!(flags.is_on("ui_v2.shell") && flags.is_on("ui_v2.home") && flags.is_on("live_assist"));
    assert!(!flags.is_on("ui_v2.library"));
    assert!(!flags.flags.contains_key("ui_v3.experiment"), "a flag the app does not know is ignored");
    assert!(!flags.classic);
    assert!(flags.offers_classic_switch());
}

#[tokio::test]
async fn an_older_server_without_flags_has_every_flag_off() {
    let server = MockServer::start(Router::new().fixed("GET", "/api/config", Reply::json(200, fixture("config.json"))));
    let config = client(&server).config().await.unwrap();
    assert!(config.ui().flags.values().all(|on| !on));
    assert!(!config.ui().classic);
    assert!(!config.ui().offers_classic_switch());
    // A broken block never breaks the config.
    let mut odd = fixture("config.json");
    odd["ui_flags"] = json!("everything");
    odd["ui_classic"] = json!("yes");
    let config: ServerConfig = serde_json::from_value(odd).unwrap();
    assert!(config.ui().flags.values().all(|on| !on));
    assert!(!config.ui().classic);
}

#[tokio::test]
async fn switching_back_to_classic_sends_the_choice_and_reads_the_answer() {
    let server = MockServer::start(Router::new().fixed(
        "PUT",
        "/api/account/ui",
        Reply::json(200, json!({"ui_flags": {"ui_v2.shell": false, "ui_v2.home": false, "live_assist": true}, "ui_classic": true})),
    ));
    let answer = client(&server).set_ui_classic(true).await.unwrap();
    assert!(answer.ui_classic);
    let sent = server.requests_to("PUT", "/api/account/ui");
    assert_eq!(sent.len(), 1);
    assert_eq!(serde_json::from_slice::<serde_json::Value>(&sent[0].body).unwrap(), json!({"classic": true}));
    let flags = desktop_lib::ui_flags::UiFlags::read(answer.ui_flags.as_ref(), answer.ui_classic);
    assert!(flags.classic && !flags.is_on("ui_v2.home") && flags.is_on("live_assist"));
}
