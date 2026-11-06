//! Signing in with an account and the account page (docs/API.md "Signing in with an account", "Your account", since
//! v1.5), against a scripted server: what is sent, the two-factor step, the errors in plain words and older servers.

mod common;

use serde_json::json;

use common::{MockServer, Reply, Router};
use desktop_lib::net::api::ApiClient;
use desktop_lib::net::models::{PasswordAnswer, PasswordSignIn};

const TOKEN: &str = "7|eld_TOKEN_PLACEHOLDER";

fn public(server: &MockServer) -> ApiClient {
    common::init_tls();
    ApiClient::new(&server.base_url(), &[], None).expect("local client")
}

fn authed(server: &MockServer) -> ApiClient {
    common::init_tls();
    ApiClient::new(&server.base_url(), &[], Some(TOKEN.to_string())).expect("local client")
}

fn sign_in<'a>(otp: Option<&'a str>) -> PasswordSignIn<'a> {
    PasswordSignIn { email: "hassam@example.test", password: "correct horse", otp, name: "Studio Mac" }
}

#[tokio::test]
async fn a_password_sign_in_sends_the_account_and_this_computer_and_gets_a_token() {
    let server = MockServer::start(Router::new().fixed("POST", "/api/auth/password", Reply::json(201, common::fixture("server/register_201_windows.json"))));

    let answer = public(&server).sign_in_password(&sign_in(None), "macos", "14.6").await.unwrap();
    let PasswordAnswer::SignedIn(registered) = answer else { panic!("signed in") };
    assert_eq!(registered.token, "1|eld_TOKEN_PLACEHOLDER");
    assert_eq!(registered.device.name, "Work laptop");

    let sent = &server.requests_to("POST", "/api/auth/password")[0];
    let body = sent.json();
    assert_eq!(body["email"], "hassam@example.test");
    assert_eq!(body["password"], "correct horse");
    assert!(body["otp"].is_null(), "no code until the server asks for one");
    assert_eq!(body["name"], "Studio Mac");
    assert_eq!(body["platform"], "macos");
    assert_eq!(body["os_version"], "14.6");
    assert_eq!(body["app_version"], env!("CARGO_PKG_VERSION"));
    assert_eq!(sent.header("authorization"), None, "a sign-in carries no token");
}

#[tokio::test]
async fn two_factor_accounts_are_asked_for_the_code_and_a_wrong_one_says_so() {
    let server = MockServer::start(Router::new().route("POST", "/api/auth/password", |request, _| {
        match request.json()["otp"].as_str() {
            None => Reply::json(401, json!({"message": "A two-factor code is required.", "code": "otp_required"})).into(),
            Some("123456") => Reply::json(201, common::fixture("server/register_201_windows.json")).into(),
            Some(_) => Reply::json(422, json!({"message": "The code is invalid.", "code": "invalid_otp"})).into(),
        }
    }));
    let api = public(&server);

    assert!(matches!(api.sign_in_password(&sign_in(None), "macos", "").await.unwrap(), PasswordAnswer::OtpRequired), "401 otp_required is not an unpaired token");
    let wrong = api.sign_in_password(&sign_in(Some("000000")), "macos", "").await.unwrap_err();
    assert!(wrong.to_string().starts_with("That code did not work."), "{wrong}");
    assert!(matches!(api.sign_in_password(&sign_in(Some("123456")), "macos", "").await.unwrap(), PasswordAnswer::SignedIn(_)));
    assert_eq!(server.requests_to("POST", "/api/auth/password")[2].json()["otp"], "123456");
}

#[tokio::test]
async fn sign_in_refusals_read_as_plain_words() {
    let server = MockServer::start(Router::new().route("POST", "/api/auth/password", |request, _| {
        match request.json()["email"].as_str().unwrap_or_default() {
            "wrong@example.test" => Reply::json(422, json!({"message": "These credentials do not match our records.", "code": "invalid_credentials"})).into(),
            "new@example.test" => Reply::json(403, json!({"message": "Verify your email address first.", "code": "email_not_verified"})).into(),
            _ => Reply::json(429, json!({"message": "Too many attempts.", "code": "too_many_attempts"})).header("Retry-After", "840").into(),
        }
    }));
    let api = public(&server);
    let attempt = |email: &'static str| PasswordSignIn { email, password: "x", otp: None, name: "Mac" };

    assert_eq!(api.sign_in_password(&attempt("wrong@example.test"), "macos", "").await.unwrap_err().to_string(), "The email or password is not right. Check both and try again.");
    assert!(api.sign_in_password(&attempt("new@example.test"), "macos", "").await.unwrap_err().to_string().starts_with("Your email address is not verified yet."));
    let locked = api.sign_in_password(&attempt("any@example.test"), "macos", "").await.unwrap_err();
    assert_eq!(locked.retry_after_secs(), Some(840));
    assert_eq!(locked.to_string(), "Too many sign-in attempts. Wait 14 minutes, then try again.");
}

#[tokio::test]
async fn the_route_s_own_throttle_reads_as_the_sign_in_lock_too() {
    // 5 a minute from one address: Laravel's throttle answers before the lock per email does.
    let throttled = || Reply::json(429, json!({"message": "Too Many Attempts.", "code": "rate_limited"})).header("Retry-After", "52");
    let server = MockServer::start(
        Router::new()
            .fixed("POST", "/api/auth/password", throttled())
            .fixed("POST", "/api/auth/device/token", throttled())
            .fixed("POST", "/api/devices/register", throttled()),
    );
    let api = public(&server);
    let expected = "Too many sign-in attempts. Wait 52 seconds, then try again.";

    let password = api.sign_in_password(&sign_in(Some("000000")), "macos", "").await.unwrap_err();
    assert_eq!(password.to_string(), expected);
    assert_eq!(password.retry_after_secs(), Some(52));
    assert_eq!(api.device_token("one-time", "the-verifier", "Studio Mac", "macos", "").await.unwrap_err().to_string(), expected);
    assert_eq!(api.register("7KQ2-M9XD", "Studio Mac", "macos", "").await.unwrap_err().to_string(), expected);
}

#[tokio::test]
async fn an_older_server_has_no_account_sign_in() {
    // The mock answers unknown routes like Laravel: 404 without a code.
    let server = MockServer::start(Router::new());
    let api = public(&server);

    assert!(api.sign_in_password(&sign_in(None), "macos", "").await.unwrap_err().is_missing_route());
    assert!(!api.offers_account_sign_in().await.unwrap());

    let current = MockServer::start(Router::new().fixed(
        "POST",
        "/api/auth/device/token",
        Reply::json(422, json!({"message": "The code field is required.", "code": "validation_failed", "errors": {"code": ["The code field is required."]}})),
    ));
    assert!(public(&current).offers_account_sign_in().await.unwrap());
    assert_eq!(current.requests()[0].json(), json!({}), "the probe sends nothing of the person's");
}

#[tokio::test]
async fn the_browser_s_code_and_this_computer_s_verifier_are_exchanged_for_a_token() {
    let server = MockServer::start(Router::new().route("POST", "/api/auth/device/token", |request, _| {
        let body = request.json();
        if body["code"] == "one-time" && body["code_verifier"] == "the-verifier" {
            Reply::json(201, common::fixture("server/register_201_windows.json")).into()
        } else {
            Reply::json(422, json!({"message": "The code is invalid.", "code": "invalid_code"})).into()
        }
    }));
    let api = public(&server);

    let registered = api.device_token("one-time", "the-verifier", "Studio Mac", "macos", "14.6").await.unwrap();
    assert_eq!(registered.token, "1|eld_TOKEN_PLACEHOLDER");
    let body = server.requests()[0].json();
    assert_eq!(body["name"], "Studio Mac");
    assert_eq!(body["platform"], "macos");
    assert_eq!(body["app_version"], env!("CARGO_PKG_VERSION"));

    let refused = api.device_token("one-time", "another-verifier", "Studio Mac", "macos", "").await.unwrap_err();
    assert!(refused.to_string().contains("Choose “Sign in in the browser” to start again."), "{refused}");
}

#[tokio::test]
async fn the_account_page_reads_the_account_and_hides_on_an_older_server() {
    let account = json!({"name": "Hassam", "email": "hassam@example.test", "email_verified": true, "two_factor": true, "google": false,
        "created_at": "2026-11-01T09:00:00+00:00", "workspace": {"id": "01JF", "name": "Acme team", "role": "viewer"}, "deletes_at": null});
    let server = MockServer::start(Router::new().fixed("GET", "/api/account", Reply::json(200, account)));
    let got = authed(&server).account().await.unwrap().expect("an account");
    assert_eq!(got.email, "hassam@example.test");
    assert!(got.two_factor && !got.google);
    assert_eq!(got.workspace.as_ref().map(|w| w.role.as_str()), Some("viewer"));
    assert_eq!(server.requests()[0].header("authorization"), Some(format!("Bearer {TOKEN}").as_str()));

    let old = MockServer::start(Router::new());
    assert_eq!(authed(&old).account().await.unwrap(), None);
}

#[tokio::test]
async fn deleting_the_account_is_confirmed_with_the_password_or_a_code() {
    let server = MockServer::start(
        Router::new().route("DELETE", "/api/account", |request, _| match request.json().get("password").and_then(|p| p.as_str()) {
            Some("hunter2") => Reply::json(202, json!({"deletes_at": "2026-11-13T09:00:00+00:00"})).into(),
            Some(_) => Reply::json(422, json!({"message": "The password is wrong.", "code": "invalid_credentials"})).into(),
            None if request.json()["otp"] == "123456" => Reply::json(409, json!({"message": "Hand the workspace over first.", "code": "owner_of_team"})).into(),
            None => Reply::json(422, json!({"message": "The code is invalid.", "code": "invalid_otp"})).into(),
        }),
    );
    let api = authed(&server);

    assert_eq!(api.delete_account(Some("hunter2"), None).await.unwrap().as_deref(), Some("2026-11-13T09:00:00+00:00"));
    assert_eq!(server.requests()[0].json(), json!({"password": "hunter2"}));
    assert!(api.delete_account(Some("nope"), None).await.unwrap_err().to_string().starts_with("The email or password is not right."));
    assert!(api.delete_account(None, Some("123456")).await.unwrap_err().to_string().contains("Workspace page"));
    assert_eq!(server.requests()[2].json(), json!({"otp": "123456"}));
    assert!(api.delete_account(None, None).await.is_err(), "nothing to confirm with");
    assert_eq!(server.requests().len(), 3, "an empty confirmation is never sent");
}

#[tokio::test]
async fn sign_out_everywhere_and_the_data_export() {
    let server = MockServer::start(
        Router::new()
            .fixed("POST", "/api/account/sign-out-everywhere", Reply::empty(204))
            .fixed("POST", "/api/account/export", Reply::json(202, json!({"id": "01JFEXPORT", "status": "making"})))
            .route("GET", "/api/account/export/01JFEXPORT", |_, nth| {
                if nth == 0 {
                    Reply::json(200, json!({"id": "01JFEXPORT", "status": "making", "url": null, "expires_at": null})).into()
                } else {
                    Reply::json(200, json!({"id": "01JFEXPORT", "status": "ready", "url": "https://ledger.example.test/x.zip", "expires_at": "2026-11-07T09:00:00+00:00"})).into()
                }
            }),
    );
    let api = authed(&server);

    api.sign_out_everywhere().await.unwrap();
    let started = api.start_export().await.unwrap();
    assert_eq!((started.id.as_str(), started.status.as_str()), ("01JFEXPORT", "making"));
    assert_eq!(api.export_status("01JFEXPORT").await.unwrap().url, None);
    let ready = api.export_status("01JFEXPORT").await.unwrap();
    assert_eq!(ready.status, "ready");
    assert_eq!(ready.url.as_deref(), Some("https://ledger.example.test/x.zip"));
}

#[tokio::test]
async fn a_feature_left_out_of_the_plan_is_named_in_plain_words() {
    let server = MockServer::start(Router::new().fixed(
        "POST",
        "/api/live-translate/token",
        Reply::json(403, json!({"message": "Forbidden.", "code": "feature_not_in_plan", "feature": "live_translate"})),
    ));
    let err = authed(&server).live_translate_token("game", "en", &[]).await.unwrap_err();
    assert_eq!(err.code(), Some("feature_not_in_plan"));
    assert_eq!(err.to_string(), "Live Translate is not in your workspace's plan. The workspace owner can change the plan in the console.");
}
