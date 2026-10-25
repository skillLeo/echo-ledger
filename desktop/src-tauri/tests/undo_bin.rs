//! The undo bin and storage meter on the desktop (plan B17), against an in-process HTTP server.

mod common;

use serde_json::json;

use common::{MockServer, Reply, Router};
use desktop_lib::net::api::ApiClient;

const TOKEN: &str = "7|eld_TOKEN_PLACEHOLDER";
const ID: &str = "01JB8Q4C1D2E3F4G5H6J7K8M9N";

fn client(server: &MockServer) -> ApiClient {
    common::init_tls();
    ApiClient::new(&server.base_url(), &[], Some(TOKEN.to_string())).expect("local client")
}

#[tokio::test]
async fn the_bin_lists_restores_and_erases() {
    let server = MockServer::start(
        Router::new()
            .fixed("GET", "/api/bin", Reply::json(200, json!({"data": [{"id": ID, "title": "Zoom call"}]})))
            .fixed("POST", &format!("/api/sessions/{ID}/restore"), Reply::json(200, json!({"data": {"id": ID, "deleted": false}})))
            .fixed("DELETE", &format!("/api/bin/{ID}"), Reply::empty(204)),
    );
    let api = client(&server);

    assert_eq!(api.bin().await.unwrap()["data"][0]["title"], "Zoom call");
    assert_eq!(api.restore_session(ID).await.unwrap()["data"]["deleted"], false);
    api.erase_from_bin(ID).await.unwrap();
}

#[tokio::test]
async fn a_late_restore_says_why() {
    let server = MockServer::start(Router::new().fixed(
        "POST",
        &format!("/api/sessions/{ID}/restore"),
        Reply::json(410, json!({"code": "restore_expired", "message": "This recording can no longer be restored."})),
    ));

    let err = client(&server).restore_session(ID).await.unwrap_err();
    assert!(err.to_string().contains("can no longer be restored"), "{err}");
}

#[tokio::test]
async fn the_storage_meter_is_read() {
    let body = json!({"recordings_bytes": 1024, "recordings_count": 2, "disk_free_bytes": null, "disk_total_bytes": null, "retention_days": 30});
    let server = MockServer::start(Router::new().fixed("GET", "/api/storage", Reply::json(200, body.clone())));

    assert_eq!(client(&server).storage().await.unwrap(), body);
}
