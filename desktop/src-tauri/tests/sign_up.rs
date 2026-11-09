//! v1.6, P1.1 and P3 (docs/API.md "Sign-up and sign-in with an email code"), against a scripted server: what is sent
//! (the install id on every sign-in), the answers, the refusals in plain words, renaming a device, and an older server
//! (404 on the new routes) keeping today's behaviour.

mod common;

use serde_json::json;

use common::{MockServer, Reply, Router};
use desktop_lib::net::api::ApiClient;
use desktop_lib::net::models::{CodeAnswer, PasswordSignIn, ThisDevice};

const TOKEN: &str = "7|eld_TOKEN_PLACEHOLDER";
const INSTALL: &str = "3f1c5e0b7a9d2c4e6f8a0b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6f7a8b9c0d1e";

fn public(server: &MockServer) -> ApiClient {
    common::init_tls();
    ApiClient::new(&server.base_url(), &[], None).expect("local client").with_install_id(Some(INSTALL.into()))
}

fn authed(server: &MockServer) -> ApiClient {
    common::init_tls();
    ApiClient::new(&server.base_url(), &[], Some(TOKEN.to_string())).expect("local client")
}

fn mac() -> ThisDevice {
    ThisDevice { name: "MacBook Pro".into(), platform: "macos", os_version: "15.1".into() }
}

#[tokio::test]
async fn the_email_first_says_whether_it_has_an_account_and_how_it_signs_in() {
    let server = MockServer::start(Router::new().route("POST", "/api/auth/start", |request, _| match request.json()["email"].as_str() {
        Some("sam@example.test") => Reply::json(200, json!({"account": "existing", "methods": ["password", "code", "google"], "sign_up": true})).into(),
        Some("new@example.test") => Reply::json(200, json!({"account": "new", "methods": ["code", "google"], "sign_up": true})).into(),
        Some("closed@example.test") => Reply::json(403, json!({"message": "Ask the owner for an invitation", "code": "sign_up_closed"})).into(),
        _ => Reply::json(422, json!({"message": "Not allowed", "code": "email_not_allowed"})).into(),
    }));
    let api = public(&server);
    let existing = api.auth_start("sam@example.test").await.unwrap().unwrap();
    assert_eq!(existing.account, "existing");
    assert_eq!(existing.methods, ["password", "code", "google"]);
    assert!(existing.sign_up);
    let new = api.auth_start("new@example.test").await.unwrap().unwrap();
    assert_eq!((new.account.as_str(), new.methods.len()), ("new", 2));
    assert_eq!(api.auth_start("closed@example.test").await.unwrap_err().to_string(), "New accounts are not open on this server. Ask its owner for an invitation.");
    assert!(api.auth_start("x@mailinator.test").await.unwrap_err().to_string().starts_with("This email address can't be used"));
    assert_eq!(server.requests_to("POST", "/api/auth/start")[0].json(), json!({"email": "sam@example.test"}), "only the email");

    // An older server has no route: none, and the screen keeps the v1.5 sign-in.
    let older = MockServer::start(Router::new());
    assert_eq!(public(&older).auth_start("sam@example.test").await.unwrap(), None);
}

#[tokio::test]
async fn a_code_is_sent_and_asking_again_too_soon_says_when() {
    let server = MockServer::start(Router::new().route("POST", "/api/auth/code", |request, _| match request.json()["purpose"].as_str() {
        Some("sign_up") => Reply::json(202, json!({"resend_after": 60, "expires_in": 600})).into(),
        _ => Reply::json(429, json!({"message": "Wait", "code": "resend_too_soon"})).header("Retry-After", "41").into(),
    }));
    let api = public(&server);
    let sent = api.send_code("new@example.test", "sign_up").await.unwrap();
    assert!(sent.sent);
    assert_eq!((sent.resend_after, sent.expires_in), (60, Some(600)));
    let again = api.send_code("new@example.test", "sign_in").await.unwrap();
    assert!(!again.sent);
    assert_eq!(again.resend_after, 41);
    let body = server.requests_to("POST", "/api/auth/code")[0].json();
    assert_eq!(body, json!({"email": "new@example.test", "purpose": "sign_up", "turnstile": null}), "the apps send no Turnstile answer");
}

#[tokio::test]
async fn a_verified_code_signs_in_asks_for_two_factor_or_opens_the_profile_step() {
    let server = MockServer::start(Router::new().route("POST", "/api/auth/code/verify", |request, _| {
        let body = request.json();
        match (body["email"].as_str(), body["code"].as_str(), body["otp"].as_str()) {
            (Some("sam@example.test"), Some("123456"), _) => Reply::json(201, common::fixture("server/register_201_windows.json")).into(),
            (Some("two@example.test"), Some("123456"), None) => Reply::json(401, json!({"message": "Code", "code": "otp_required"})).into(),
            (Some("two@example.test"), Some("123456"), Some("654321")) => Reply::json(201, common::fixture("server/register_201_windows.json")).into(),
            (Some("new@example.test"), Some("123456"), _) => Reply::json(200, json!({"next": "profile", "sign_up_token": "sut_once"})).into(),
            (_, _, _) if body["email"] == "late@example.test" => Reply::json(422, json!({"message": "Expired", "code": "code_expired"})).into(),
            _ => Reply::json(422, json!({"message": "Wrong", "code": "invalid_code", "attempts_left": 3})).into(),
        }
    }));
    let api = public(&server);
    let CodeAnswer::SignedIn(registered) = api.verify_code("sam@example.test", "123456", None, &mac()).await.unwrap() else { panic!("signed in") };
    assert_eq!(registered.token, "1|eld_TOKEN_PLACEHOLDER");
    assert!(matches!(api.verify_code("two@example.test", "123456", None, &mac()).await.unwrap(), CodeAnswer::OtpRequired), "401 otp_required is not a signed-out token");
    assert!(matches!(api.verify_code("two@example.test", "123456", Some("654321"), &mac()).await.unwrap(), CodeAnswer::SignedIn(_)));
    let CodeAnswer::Profile { sign_up_token } = api.verify_code("new@example.test", "123456", None, &mac()).await.unwrap() else { panic!("profile") };
    assert_eq!(sign_up_token, "sut_once");
    assert_eq!(api.verify_code("sam@example.test", "000000", None, &mac()).await.unwrap_err().to_string(), "That code is not right. You can try 3 more times, or choose “Send a new code”.");
    assert_eq!(api.verify_code("late@example.test", "000000", None, &mac()).await.unwrap_err().to_string(), "This code has expired or was tried too often. Choose “Send a new code”.");

    let body = server.requests_to("POST", "/api/auth/code/verify")[0].json();
    assert_eq!(body["install_id"], INSTALL);
    assert_eq!(body["name"], "MacBook Pro");
    assert_eq!(body["platform"], "macos");
    assert_eq!(body["os_version"], "15.1");
    assert_eq!(body["app_version"], env!("CARGO_PKG_VERSION"));
    assert!(body["otp"].is_null());
}

#[tokio::test]
async fn a_sign_up_sends_the_name_the_terms_and_the_install_id() {
    let server = MockServer::start(Router::new().route("POST", "/api/auth/sign-up", |request, _| match request.json()["sign_up_token"].as_str() {
        Some("sut_once") => Reply::json(201, common::fixture("server/register_201_windows.json")).into(),
        _ => Reply::json(422, json!({"message": "Used", "code": "invalid_code"})).into(),
    }));
    let api = public(&server);
    let registered = api.sign_up("sut_once", "Sam Khan", &mac()).await.unwrap();
    assert_eq!(registered.device.name, "Work laptop");
    let body = server.requests_to("POST", "/api/auth/sign-up")[0].json();
    assert_eq!(body["sign_up_token"], "sut_once");
    assert_eq!(body["name"], "Sam Khan");
    assert_eq!(body["accept_terms"], true);
    assert_eq!(body["install_id"], INSTALL);
    assert_eq!(api.sign_up("sut_used", "Sam", &mac()).await.unwrap_err().to_string(), desktop_lib::account::SIGN_UP_EXPIRED);
}

#[tokio::test]
async fn every_sign_in_route_sends_this_install_s_id_and_an_older_app_s_body_has_none() {
    let ok = || Reply::json(201, common::fixture("server/register_201_windows.json"));
    let server = MockServer::start(
        Router::new().fixed("POST", "/api/auth/password", ok()).fixed("POST", "/api/auth/device/token", ok()).fixed("POST", "/api/devices/register", ok()),
    );
    let api = public(&server);
    let sign_in = PasswordSignIn { email: "sam@example.test", password: "pw", otp: None, name: "MacBook Pro" };
    api.sign_in_password(&sign_in, "macos", "15.1").await.unwrap();
    api.device_token("one-time", "verifier", "MacBook Pro", "macos", "15.1").await.unwrap();
    api.register("7KQ2-M9XD", "MacBook Pro", "macos", "15.1").await.unwrap();
    for path in ["/api/auth/password", "/api/auth/device/token", "/api/devices/register"] {
        assert_eq!(server.requests_to("POST", path)[0].json()["install_id"], INSTALL, "{path}");
    }
    common::init_tls();
    let without = ApiClient::new(&server.base_url(), &[], None).unwrap();
    without.register("7KQ2-M9XD", "MacBook Pro", "macos", "15.1").await.unwrap();
    assert!(server.requests_to("POST", "/api/devices/register")[1].json().get("install_id").is_none());
}

#[tokio::test]
async fn a_device_is_renamed_on_the_server_and_an_older_server_says_where_instead() {
    let server = MockServer::start(Router::new().fixed("PATCH", "/api/devices/01JBDEVICE", Reply::json(200, json!({"data": {"id": "01JBDEVICE", "name": "Studio iMac"}}))));
    authed(&server).rename_device("01JBDEVICE", "Studio iMac").await.unwrap();
    assert_eq!(server.requests_to("PATCH", "/api/devices/01JBDEVICE")[0].json(), json!({"name": "Studio iMac"}));
    let older = authed(&MockServer::start(Router::new())).rename_device("01JBDEVICE", "Studio iMac").await.unwrap_err();
    assert!(older.to_string().starts_with("This server cannot rename devices yet."), "{older}");
}
