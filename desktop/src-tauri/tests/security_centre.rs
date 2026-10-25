//! The security centre on the desktop (plan B16): devices and the audit log from the server, revoking a device.

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
async fn the_overview_lists_devices_and_events() {
    let body = json!({"devices": [{"id": ID, "name": "Work laptop", "current": true}], "events": [{"id": 1, "event": "device_paired", "label": "Device paired"}]});
    let server = MockServer::start(Router::new().fixed("GET", "/api/security", Reply::json(200, body.clone())));

    assert_eq!(client(&server).security().await.unwrap(), body);
    assert_eq!(server.requests()[0].header("authorization"), Some(format!("Bearer {TOKEN}").as_str()));
}

#[tokio::test]
async fn a_device_is_revoked_and_refusals_are_readable() {
    let server = MockServer::start(
        Router::new()
            .fixed("DELETE", &format!("/api/devices/{ID}"), Reply::empty(204))
            .fixed("DELETE", "/api/devices/01JB8Q4C1D2E3F4G5H6J7K8M9P", Reply::json(409, json!({"code": "current_device", "message": "This is the device you are using. Unpair it in its settings instead."}))),
    );
    let api = client(&server);

    api.revoke_device(ID).await.unwrap();
    let err = api.revoke_device("01JB8Q4C1D2E3F4G5H6J7K8M9P").await.unwrap_err();
    assert!(err.to_string().contains("This is the device you are using"), "{err}");
}
