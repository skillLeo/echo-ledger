//! Typed client for docs/API.md. All requests go through the pinned reqwest client.

use std::future::Future;
use std::sync::Mutex;
use std::sync::atomic::{AtomicUsize, Ordering};

use serde::de::DeserializeOwned;
use serde_json::json;

use super::models::*;
use super::pinning::build_http_client;
use crate::error::{AppError, AppResult};

#[derive(Clone)]
pub struct ApiClient {
    base: String,
    http: reqwest::Client,
    token: Option<String>,
    /// This install's id (since v1.6, P3), sent by every sign-in route so the same computer keeps its row.
    install_id: Option<String>,
}

impl ApiClient {
    pub fn new(base_url: &str, pins: &[String], token: Option<String>) -> AppResult<Self> {
        let base = base_url.trim().trim_end_matches('/').to_string();
        if !(base.starts_with("https://") || super::pinning::is_local_dev(&base)) {
            return Err(AppError::msg("The server URL must start with https:// (http is only allowed for localhost)."));
        }
        let http = build_http_client(&base, pins).map_err(AppError::Message)?;
        Ok(Self { base, http, token, install_id: None })
    }

    /// The same client sending `install_id` with every sign-in (v1.6, P3); none leaves it out (an older app's body).
    pub fn with_install_id(mut self, install_id: Option<String>) -> Self {
        self.install_id = install_id.filter(|id| !id.trim().is_empty());
        self
    }

    /// A sign-in body with this install's id, when there is one.
    fn signing_in(&self, mut body: serde_json::Value) -> serde_json::Value {
        if let (Some(id), Some(map)) = (&self.install_id, body.as_object_mut()) {
            map.insert("install_id".into(), json!(id));
        }
        body
    }

    pub fn base_url(&self) -> &str {
        &self.base
    }

    fn url(&self, path: &str) -> String {
        format!("{}/api/{}", self.base, path.trim_start_matches('/'))
    }

    fn authed(&self, req: reqwest::RequestBuilder) -> AppResult<reqwest::RequestBuilder> {
        let token = self.token.as_deref().ok_or(AppError::NotPaired)?;
        Ok(req.bearer_auth(token).header("Accept", "application/json"))
    }

    async fn parse<T: DeserializeOwned>(resp: reqwest::Response) -> AppResult<T> {
        if resp.status().is_success() {
            return Ok(resp.json::<T>().await?);
        }
        Err(Self::error(resp).await)
    }

    async fn ok_empty(resp: reqwest::Response) -> AppResult<()> {
        if resp.status().is_success() {
            return Ok(());
        }
        Err(Self::error(resp).await)
    }

    /// Maps a non-2xx answer: `401` → `NotPaired`; otherwise the body's `message` and `code` plus the
    /// `Retry-After` header (integer seconds; an HTTP-date is ignored).
    async fn error(resp: reqwest::Response) -> AppError {
        if resp.status().as_u16() == 401 {
            return AppError::NotPaired;
        }
        Self::answer_error(resp).await
    }

    /// The error of an answer as the server sent it, a `401` included (the sign-in routes answer `401 otp_required`,
    /// which says nothing about this computer's token). `403 feature_not_in_plan` gets the app's sentence for its
    /// `feature`.
    async fn answer_error(resp: reqwest::Response) -> AppError {
        let status = resp.status().as_u16();
        let retry_after_secs = resp
            .headers()
            .get(reqwest::header::RETRY_AFTER)
            .and_then(|v| v.to_str().ok())
            .and_then(|v| v.trim().parse::<u64>().ok());
        let body: serde_json::Value = resp.json().await.unwrap_or_default();
        let code = body.get("code").and_then(|c| c.as_str()).map(str::to_string);
        let message = if code.as_deref() == Some("feature_not_in_plan") {
            crate::plan::feature_text_with(body.get("feature").and_then(|f| f.as_str()), body.get("lowest_plan").and_then(|f| f.as_str()))
        } else {
            body.get("message").and_then(|m| m.as_str()).unwrap_or("request failed").to_string()
        };
        AppError::Server { status, message, code, retry_after_secs }
    }

    /// Any `429` from a sign-in route reads as the sign-in lock: the route's own throttle (5 a minute from one address,
    /// `rate_limited`) comes before the lock per email (`too_many_attempts`), and both send `Retry-After`.
    fn sign_in_throttled(e: AppError) -> AppError {
        match e {
            AppError::Server { status: 429, message, retry_after_secs, .. } => {
                AppError::Server { status: 429, message, code: Some("too_many_attempts".into()), retry_after_secs }
            }
            e => e,
        }
    }

    pub async fn register(&self, code: &str, name: &str, platform: &str, os_version: &str) -> AppResult<RegisterResponse> {
        let resp = self
            .http
            .post(self.url("devices/register"))
            .header("Accept", "application/json")
            .json(&self.signing_in(json!({ "pairing_code": code, "name": name, "platform": platform, "app_version": env!("CARGO_PKG_VERSION"), "os_version": os_version })))
            .send()
            .await?;
        Self::parse(resp).await.map_err(Self::sign_in_throttled)
    }

    /// `POST /api/auth/password` (since v1.5): signs this computer in to the person's account, like pairing. An account
    /// with two-factor sign-in answers `401 otp_required` until `otp` (a TOTP or recovery code) is sent.
    pub async fn sign_in_password(&self, sign_in: &PasswordSignIn<'_>, platform: &str, os_version: &str) -> AppResult<PasswordAnswer> {
        let resp = self
            .http
            .post(self.url("auth/password"))
            .header("Accept", "application/json")
            .json(&self.signing_in(json!({
                "email": sign_in.email, "password": sign_in.password, "otp": sign_in.otp, "name": sign_in.name,
                "platform": platform, "app_version": env!("CARGO_PKG_VERSION"), "os_version": os_version,
            })))
            .send()
            .await?;
        if !resp.status().is_success() {
            let e = Self::answer_error(resp).await;
            return if e.code() == Some("otp_required") { Ok(PasswordAnswer::OtpRequired) } else { Err(Self::sign_in_throttled(e)) };
        }
        Ok(PasswordAnswer::SignedIn(Box::new(resp.json::<RegisterResponse>().await?)))
    }

    /// `POST /api/auth/device/token` (since v1.5): the browser sign-in's one-time code and its PKCE verifier for this
    /// computer's token. A wrong, used or expired code, or another verifier, is `422 invalid_code`.
    pub async fn device_token(&self, code: &str, verifier: &str, name: &str, platform: &str, os_version: &str) -> AppResult<RegisterResponse> {
        let resp = self
            .http
            .post(self.url("auth/device/token"))
            .header("Accept", "application/json")
            .json(&self.signing_in(json!({ "code": code, "code_verifier": verifier, "name": name, "platform": platform, "app_version": env!("CARGO_PKG_VERSION"), "os_version": os_version })))
            .send()
            .await?;
        if !resp.status().is_success() {
            return Err(Self::sign_in_throttled(Self::answer_error(resp).await));
        }
        Ok(resp.json::<RegisterResponse>().await?)
    }

    /// Whether the server signs devices in with accounts (since v1.5): an empty `POST /api/auth/device/token` is refused
    /// for its missing fields by a server that has the route, and answers `404 not_found` on an older one.
    pub async fn offers_account_sign_in(&self) -> AppResult<bool> {
        let resp = self.http.post(self.url("auth/device/token")).header("Accept", "application/json").json(&json!({})).send().await?;
        if resp.status().is_success() {
            return Ok(true);
        }
        Ok(!Self::answer_error(resp).await.is_missing_route())
    }

    /// `GET /api/auth/options` (Phase 1C, public); none from an older server, which answers 404.
    pub async fn auth_options(&self) -> AppResult<Option<crate::net::models::AuthOptions>> {
        let resp = self.http.get(self.url("auth/options")).header("Accept", "application/json").send().await?;
        match Self::parse(resp).await {
            Ok(options) => Ok(Some(options)),
            Err(e) if e.is_missing_route() => Ok(None),
            Err(e) => Err(e),
        }
    }

    /// `POST /api/auth/start` (since v1.6): whether the email has an account and how it can sign in; none from an
    /// older server (404), which keeps the v1.5 sign-in.
    pub async fn auth_start(&self, email: &str) -> AppResult<Option<AuthStart>> {
        let resp = self.http.post(self.url("auth/start")).header("Accept", "application/json").json(&json!({ "email": email })).send().await?;
        if !resp.status().is_success() {
            let e = Self::sign_in_throttled(Self::answer_error(resp).await);
            return if e.is_missing_route() { Ok(None) } else { Err(e) };
        }
        Ok(Some(resp.json::<AuthStart>().await?))
    }

    /// `POST /api/auth/code` (since v1.6): a 6-digit code to the email. Asked again within a minute, the server answers
    /// `429 resend_too_soon`, read as "not sent, ask again in `Retry-After` seconds".
    pub async fn send_code(&self, email: &str, purpose: &str) -> AppResult<CodeSent> {
        let resp = self
            .http
            .post(self.url("auth/code"))
            .header("Accept", "application/json")
            .json(&json!({ "email": email, "purpose": purpose, "turnstile": null }))
            .send()
            .await?;
        if !resp.status().is_success() {
            let e = Self::answer_error(resp).await;
            if e.code() == Some("resend_too_soon") {
                return Ok(CodeSent { resend_after: e.retry_after_secs().unwrap_or(60).max(1), expires_in: None, sent: false });
            }
            return Err(e);
        }
        Ok(CodeSent { sent: true, ..resp.json::<CodeSent>().await? })
    }

    /// `POST /api/auth/code/verify` (since v1.6): the emailed code (and the two-factor code once asked for). A wrong
    /// code says how many tries are left (`422 invalid_code` with `attempts_left`).
    pub async fn verify_code(&self, email: &str, code: &str, otp: Option<&str>, device: &ThisDevice) -> AppResult<CodeAnswer> {
        let resp = self
            .http
            .post(self.url("auth/code/verify"))
            .header("Accept", "application/json")
            .json(&self.signing_in(json!({
                "email": email, "code": code, "otp": otp, "name": device.name, "platform": device.platform,
                "app_version": env!("CARGO_PKG_VERSION"), "os_version": device.os_version,
            })))
            .send()
            .await?;
        let status = resp.status().as_u16();
        if status == 201 {
            return Ok(CodeAnswer::SignedIn(Box::new(resp.json::<RegisterResponse>().await?)));
        }
        if resp.status().is_success() {
            let body: serde_json::Value = resp.json().await?;
            return match body.get("sign_up_token").and_then(|t| t.as_str()).filter(|t| !t.is_empty()) {
                Some(token) => Ok(CodeAnswer::Profile { sign_up_token: token.to_string() }),
                // A 200 with a token instead (a server that answers sign-ins with 200).
                None => Ok(CodeAnswer::SignedIn(Box::new(serde_json::from_value::<RegisterResponse>(body)?))),
            };
        }
        let retry_after_secs = resp.headers().get(reqwest::header::RETRY_AFTER).and_then(|v| v.to_str().ok()).and_then(|v| v.trim().parse::<u64>().ok());
        let body: serde_json::Value = resp.json().await.unwrap_or_default();
        let code = body.get("code").and_then(|c| c.as_str()).map(str::to_string);
        if code.as_deref() == Some("otp_required") {
            return Ok(CodeAnswer::OtpRequired);
        }
        if code.as_deref() == Some("invalid_code") {
            let left = body.get("attempts_left").and_then(|n| n.as_u64());
            return Err(AppError::Message(crate::account::wrong_code_text(left)));
        }
        let message = body.get("message").and_then(|m| m.as_str()).unwrap_or("request failed").to_string();
        Err(Self::sign_in_throttled(AppError::Server { status, message, code, retry_after_secs }))
    }

    /// `POST /api/auth/sign-up` (since v1.6): the new account, its personal workspace on the default plan and this
    /// computer's token. A used or old sign-up token is `422 invalid_code`.
    pub async fn sign_up(&self, sign_up_token: &str, name: &str, device: &ThisDevice) -> AppResult<RegisterResponse> {
        let resp = self
            .http
            .post(self.url("auth/sign-up"))
            .header("Accept", "application/json")
            .json(&self.signing_in(json!({
                "sign_up_token": sign_up_token, "name": name, "accept_terms": true, "platform": device.platform,
                "app_version": env!("CARGO_PKG_VERSION"), "os_version": device.os_version,
            })))
            .send()
            .await?;
        if !resp.status().is_success() {
            let e = Self::sign_in_throttled(Self::answer_error(resp).await);
            return Err(if e.code() == Some("invalid_code") { AppError::msg(crate::account::SIGN_UP_EXPIRED) } else { e });
        }
        Ok(resp.json::<RegisterResponse>().await?)
    }

    /// `GET /api/me/entitlements` (since v1.6): what this person may use; none from an older server (everything on).
    pub async fn entitlements(&self) -> AppResult<Option<Entitlements>> {
        let resp = self.authed(self.http.get(self.url("me/entitlements")))?.send().await?;
        match Self::parse::<Entitlements>(resp).await {
            Ok(e) => Ok(Some(e.checked())),
            Err(e) if e.is_missing_route() => Ok(None),
            Err(e) => Err(e),
        }
    }

    /// `PUT /api/me/transcription-key` (since v1.6): the person's own Groq key, checked by the server once. The key is
    /// never logged and never comes back (only its last four characters).
    pub async fn set_transcription_key(&self, provider: &str, key: &str) -> AppResult<TranscriptionKeyState> {
        let resp = self.authed(self.http.put(self.url("me/transcription-key")))?.json(&json!({ "provider": provider, "key": key })).send().await?;
        match Self::parse::<TranscriptionKeyState>(resp).await {
            Err(e) if e.is_missing_route() => Err(AppError::msg("This server does not take your own transcription key yet.")),
            other => other,
        }
    }

    /// `DELETE /api/me/transcription-key` (since v1.6).
    pub async fn delete_transcription_key(&self) -> AppResult<()> {
        let resp = self.authed(self.http.delete(self.url("me/transcription-key")))?.send().await?;
        Self::ok_empty(resp).await
    }

    /// `POST /api/upgrade-requests` (since v1.6). One pending request at a time: `409 request_pending` answers with the
    /// pending one, which is returned as if it had just been made.
    /// Since v1.6.2 it carries an applied promo code; a 100 % code answers `approved` at once.
    pub async fn request_upgrade(&self, plan: &str, message: Option<&str>, promo_code: Option<&str>) -> AppResult<UpgradeRequest> {
        let mut body = json!({ "plan": plan, "message": message });
        if let Some(code) = promo_code {
            body["promo_code"] = json!(code);
        }
        let resp = self.authed(self.http.post(self.url("upgrade-requests")))?.json(&body).send().await?;
        if resp.status().as_u16() == 409 {
            let body: serde_json::Value = resp.json().await.unwrap_or_default();
            let pending = ["request", "upgrade_request", "data"].iter().find_map(|k| body.get(*k)).cloned().unwrap_or_else(|| body.clone());
            return match serde_json::from_value::<UpgradeRequest>(pending.clone()).ok().filter(|r| !r.id.is_empty()) {
                Some(request) => Ok(request),
                None => Err(AppError::msg(teammate_request_text(&pending))),
            };
        }
        let status = resp.status().as_u16();
        if matches!(status, 403 | 422 | 429) {
            let retry = Self::retry_after(&resp);
            let body: serde_json::Value = resp.json().await.unwrap_or_default();
            if body.get("code").and_then(|c| c.as_str()) == Some("promo_other_plan") {
                let message = body.get("message").and_then(|m| m.as_str()).unwrap_or("This code is for another plan.").to_string();
                return Err(AppError::Server { status, message, code: Some("promo_other_plan".into()), retry_after_secs: retry });
            }
            if body.get("code").and_then(|c| c.as_str()) == Some("invalid_promo_code") {
                return Err(AppError::msg(crate::error::INVALID_PROMO_TEXT));
            }
            return Err(AppError::msg(crate::receipts::refusal_text(status, &body, retry)));
        }
        match Self::parse::<UpgradeRequest>(resp).await {
            Err(e) if e.is_missing_route() => Err(AppError::msg("This server does not take upgrade requests yet.")),
            other => other,
        }
    }

    /// The `Retry-After` header in whole seconds.
    fn retry_after(resp: &reqwest::Response) -> Option<u64> {
        resp.headers().get(reqwest::header::RETRY_AFTER).and_then(|v| v.to_str().ok()).and_then(|v| v.trim().parse::<u64>().ok())
    }

    /// A refusal of a payment route in the app's calm words (docs/API.md "Payment receipts"); `401` still signs out.
    async fn payment_refusal(resp: reqwest::Response) -> AppError {
        let status = resp.status().as_u16();
        if status == 401 {
            return AppError::NotPaired;
        }
        let retry = Self::retry_after(&resp);
        let body: serde_json::Value = resp.json().await.unwrap_or_default();
        if status == 404 {
            return AppError::Server { status, message: "This request is no longer there.".into(), code: body.get("code").and_then(|c| c.as_str()).map(str::to_string), retry_after_secs: retry };
        }
        AppError::msg(crate::receipts::refusal_text(status, &body, retry))
    }

    /// `POST /api/upgrade-requests/{id}/proofs` (since v1.7): one receipt, already checked and stripped of metadata
    /// here. The same file sent again answers `200` with the receipt the server already has.
    pub async fn upload_proof(&self, id: &str, receipt: crate::receipts::Receipt) -> AppResult<Proof> {
        let boundary = format!("echoledger-{}", uuid::Uuid::new_v4().simple());
        let body = multipart_file(&boundary, "file", &receipt.name, receipt.mime, &receipt.bytes);
        drop(receipt);
        let resp = self
            .authed(self.http.post(self.url(&format!("upgrade-requests/{id}/proofs"))))?
            .header("Content-Type", format!("multipart/form-data; boundary={boundary}"))
            .timeout(std::time::Duration::from_secs(180))
            .body(body)
            .send()
            .await?;
        if !resp.status().is_success() {
            return Err(Self::payment_refusal(resp).await);
        }
        let value: serde_json::Value = resp.json().await?;
        let proof = value.get("data").cloned().unwrap_or(value);
        serde_json::from_value::<Proof>(proof).ok().filter(|p| !p.id.is_empty()).ok_or_else(|| AppError::msg(crate::receipts::SERVER_TROUBLE))
    }

    /// `DELETE /api/upgrade-requests/{id}/proofs/{proof}` (since v1.7): a receipt not sent yet. One that is gone
    /// already counts as removed.
    pub async fn delete_proof(&self, id: &str, proof: &str) -> AppResult<()> {
        let resp = self.authed(self.http.delete(self.url(&format!("upgrade-requests/{id}/proofs/{proof}"))))?.send().await?;
        if resp.status().is_success() {
            return Ok(());
        }
        match Self::payment_refusal(resp).await {
            AppError::Server { status: 404, code, .. } if code.as_deref() == Some("proof_not_found") => Ok(()),
            e => Err(e),
        }
    }

    /// `GET /api/upgrade-requests/{id}/proofs/{proof}?variant=thumb|full` (since v1.7): the receipt's bytes and type,
    /// read into memory only (the server answers `no-store`, and nothing is written here).
    pub async fn proof_file(&self, id: &str, proof: &str, variant: &str) -> AppResult<(String, Vec<u8>)> {
        let resp = self.authed(self.http.get(self.url(&format!("upgrade-requests/{id}/proofs/{proof}"))))?.query(&[("variant", variant)]).send().await?;
        if !resp.status().is_success() {
            return Err(Self::payment_refusal(resp).await);
        }
        let mime = resp.headers().get(reqwest::header::CONTENT_TYPE).and_then(|v| v.to_str().ok()).unwrap_or("").split(';').next().unwrap_or("").trim().to_ascii_lowercase();
        let bytes = resp.bytes().await?.to_vec();
        let mime = match crate::receipts::sniff(&bytes) {
            Some(kind) => kind.mime().to_string(),
            None if mime.starts_with("image/") || mime == "application/pdf" => mime,
            None => return Err(AppError::msg(crate::receipts::UNREADABLE)),
        };
        Ok((mime, bytes))
    }

    /// `POST /api/upgrade-requests/{id}/paid` (since v1.7): "Send receipt", with the bank's transaction ID and the
    /// payer's name when given. The files sent so far are locked.
    pub async fn mark_paid(&self, id: &str, reference: Option<&str>, payer_name: Option<&str>) -> AppResult<UpgradeRequest> {
        let resp = self
            .authed(self.http.post(self.url(&format!("upgrade-requests/{id}/paid"))))?
            .json(&json!({ "payment_reference": reference, "payer_name": payer_name }))
            .send()
            .await?;
        if !resp.status().is_success() {
            return Err(Self::payment_refusal(resp).await);
        }
        Ok(resp.json::<UpgradeRequest>().await?)
    }

    /// `POST /api/upgrade-requests/{id}/cancel` (since v1.7).
    pub async fn cancel_request(&self, id: &str) -> AppResult<UpgradeRequest> {
        let resp = self.authed(self.http.post(self.url(&format!("upgrade-requests/{id}/cancel"))))?.send().await?;
        if !resp.status().is_success() {
            return Err(Self::payment_refusal(resp).await);
        }
        Ok(resp.json::<UpgradeRequest>().await?)
    }

    /// `POST /api/promo-codes/check` (since v1.6.2) for the plan the sheet shows. The server's two refusals are answers
    /// the sheet shows (not errors); an older server without the route is `Unsupported`.
    pub async fn check_promo(&self, code: &str, plan: Option<&str>) -> AppResult<PromoAnswer> {
        let resp = self.authed(self.http.post(self.url("promo-codes/check")))?.json(&json!({ "code": code, "plan": plan })).send().await?;
        match resp.status().as_u16() {
            422 => {
                let body: serde_json::Value = resp.json().await.unwrap_or_default();
                let message = body.get("message").and_then(|m| m.as_str()).map(str::trim).filter(|m| !m.is_empty()).map(str::to_string);
                let other = body.get("plan").and_then(|p| p.as_str()).map(str::trim).filter(|p| crate::plan::is_plan_key(p));
                match (body.get("code").and_then(|c| c.as_str()), other) {
                    (Some("promo_other_plan"), Some(plan)) => Ok(PromoAnswer::OtherPlan {
                        message: message.unwrap_or_else(|| format!("This code is for {plan}.")),
                        plan: plan.to_string(),
                    }),
                    _ => Ok(PromoAnswer::Invalid { message: crate::error::INVALID_PROMO_TEXT.to_string() }),
                }
            }
            429 => Err(AppError::msg("Too many promo codes tried. Wait a minute, then try again.")),
            _ => match Self::parse::<PromoCheck>(resp).await {
                Ok(check) if crate::plan::is_plan_key(&check.plan) => Ok(PromoAnswer::Valid { check }),
                Ok(_) => Ok(PromoAnswer::Invalid { message: crate::error::INVALID_PROMO_TEXT.to_string() }),
                Err(e) if e.is_missing_route() => Ok(PromoAnswer::Unsupported),
                Err(e) => Err(e),
            },
        }
    }

    /// `GET /api/upgrade-requests/latest` (since v1.6); none when there is none (or on an older server).
    pub async fn latest_upgrade_request(&self) -> AppResult<Option<UpgradeRequest>> {
        let resp = self.authed(self.http.get(self.url("upgrade-requests/latest")))?.send().await?;
        match Self::parse::<UpgradeRequest>(resp).await {
            Ok(request) => Ok(Some(request)),
            Err(e) if e.status() == Some(404) => Ok(None),
            Err(e) => Err(e),
        }
    }

    /// `PATCH /api/devices/{id}` (v1.6, P3, proposed): a new name for one of the account's devices.
    pub async fn rename_device(&self, id: &str, name: &str) -> AppResult<serde_json::Value> {
        let resp = self.authed(self.http.patch(self.url(&format!("devices/{id}"))))?.json(&json!({ "name": name })).send().await?;
        match Self::parse::<serde_json::Value>(resp).await {
            Err(e) if e.is_missing_route() => Err(AppError::msg("This server cannot rename devices yet. Rename it on the console's Devices page.")),
            other => other,
        }
    }

    /// `GET /api/account` (since v1.5); none from a server without accounts.
    pub async fn account(&self) -> AppResult<Option<AccountDto>> {
        let resp = self.authed(self.http.get(self.url("account")))?.send().await?;
        match Self::parse(resp).await {
            Ok(account) => Ok(Some(account)),
            Err(e) if e.is_missing_route() => Ok(None),
            Err(e) => Err(e),
        }
    }

    /// `POST /api/devices/report` (since v1.5): a device check the person chose to send; returns when the server took it.
    pub async fn device_report(&self, report: &DeviceReport) -> AppResult<String> {
        let resp = self.authed(self.http.post(self.url("devices/report")))?.json(report).send().await?;
        match Self::parse::<DeviceReportAnswer>(resp).await {
            Ok(answer) => Ok(answer.data.reported_at),
            Err(e) if e.is_missing_route() => Err(AppError::msg("This server does not take device reports yet. Copy the report instead.")),
            Err(e) => Err(e),
        }
    }

    /// `POST /api/account/sign-out-everywhere` (since v1.5): every device and console session of the account, this
    /// computer's token included.
    pub async fn sign_out_everywhere(&self) -> AppResult<()> {
        let resp = self.authed(self.http.post(self.url("account/sign-out-everywhere")))?.send().await?;
        Self::ok_empty(resp).await
    }

    /// `DELETE /api/account` (since v1.5) with the password or a two-factor code: the account goes after 7 days. Returns
    /// when (`deletes_at`).
    pub async fn delete_account(&self, password: Option<&str>, otp: Option<&str>) -> AppResult<Option<String>> {
        let body = match (password, otp) {
            (Some(password), _) => json!({ "password": password }),
            (None, Some(otp)) => json!({ "otp": otp }),
            (None, None) => return Err(AppError::msg("Type your password or a two-factor code to confirm it is you.")),
        };
        let resp = self.authed(self.http.delete(self.url("account")))?.json(&body).send().await?;
        let answer: serde_json::Value = Self::parse(resp).await?;
        Ok(answer.get("deletes_at").and_then(|d| d.as_str()).map(str::to_string))
    }

    /// `POST /api/account/export` (since v1.5): starts the ZIP of everything the account owns.
    pub async fn start_export(&self) -> AppResult<AccountExport> {
        let resp = self.authed(self.http.post(self.url("account/export")))?.send().await?;
        Self::parse(resp).await
    }

    /// `GET /api/account/export/{id}` (since v1.5): `making`, `ready` (with its link) or `failed`.
    pub async fn export_status(&self, id: &str) -> AppResult<AccountExport> {
        let resp = self.authed(self.http.get(self.url(&format!("account/export/{id}"))))?.send().await?;
        Self::parse(resp).await
    }

    pub async fn config(&self) -> AppResult<ServerConfig> {
        let resp = self.authed(self.http.get(self.url("config")))?.send().await?;
        Self::parse(resp).await
    }

    pub async fn clients(&self) -> AppResult<serde_json::Value> {
        let resp = self.authed(self.http.get(self.url("clients")))?.send().await?;
        Self::parse(resp).await
    }

    /// `POST /api/stt/token`. `session_id` (the open session, since v1.1) is required by v1.1 servers for
    /// `live_captions` and is only sent when `Some`, so v1 servers see the v1 body. `language` (since v1.1.2) is the
    /// recording's language chip; none lets the server use the session's, the client's or the console default.
    pub async fn stt_token(
        &self,
        use_case: &str,
        channels: &[&str],
        client_id: Option<&str>,
        session_id: Option<&str>,
        language: Option<&str>,
    ) -> AppResult<SttTokenResponse> {
        let mut body = json!({ "use_case": use_case, "channels": channels, "client_id": client_id, "sample_rate": 16000 });
        if let Some(id) = session_id {
            body["session_id"] = json!(id);
        }
        if let Some(language) = language {
            body["language"] = json!(language);
        }
        let resp = self.authed(self.http.post(self.url("stt/token")))?.json(&body).send().await?;
        Self::parse(resp).await
    }

    /// `POST /api/live-translate/token` (since v1.3): a 10-minute Soniox key for Live Translate.
    pub async fn live_translate_token(&self, mode: &str, target_language: &str, language_hints: &[String]) -> AppResult<crate::translate::protocol::LiveTranslateToken> {
        let body = json!({ "mode": mode, "target_language": target_language, "language_hints": language_hints });
        let resp = self.authed(self.http.post(self.url("live-translate/token")))?.json(&body).send().await?;
        Self::parse(resp).await
    }

    /// One-shot create (with artifacts) of docs/API.md "POST /api/sessions".
    pub async fn create_session(&self, req: &CreateSessionRequest) -> AppResult<CreateSessionResponse> {
        let resp = self.authed(self.http.post(self.url("sessions")))?.json(req).send().await?;
        Self::parse(resp).await
    }

    /// The one-shot create of an imported file (since v1.4): `"skip_duplicate": true` makes the server refuse a file it
    /// already holds with `409 duplicate_recording` instead of storing it again.
    pub async fn create_session_skipping_duplicate(&self, req: &CreateSessionRequest) -> AppResult<CreateSessionResponse> {
        let mut body = serde_json::to_value(req)?;
        body["skip_duplicate"] = json!(true);
        let resp = self.authed(self.http.post(self.url("sessions")))?.json(&body).send().await?;
        Self::parse(resp).await
    }

    /// Open session (since v1.1): `POST /api/sessions` without `artifacts`; status `recording`, no upload slots.
    pub async fn open_session(&self, req: &OpenSessionRequest) -> AppResult<CreateSessionResponse> {
        let resp = self.authed(self.http.post(self.url("sessions")))?.json(req).send().await?;
        Self::parse(resp).await
    }

    /// `POST /api/sessions/{id}/finish` (since v1.1): attaches the artifacts to an open session and returns the
    /// upload slots. Idempotent; `404 session_not_found` when the session is gone.
    pub async fn finish_session(&self, id: &str, req: &FinishSessionRequest) -> AppResult<CreateSessionResponse> {
        let resp = self.authed(self.http.post(self.url(&format!("sessions/{id}/finish"))))?.json(req).send().await?;
        Self::parse(resp).await
    }

    /// `POST /api/usage` (since v1.1): one streaming period. `201` new, `200` duplicate; both are success.
    pub async fn report_usage(&self, report: &UsageReport) -> AppResult<UsageAnswer> {
        let resp = self.authed(self.http.post(self.url("usage")))?.json(report).send().await?;
        Self::parse(resp).await
    }

    pub async fn recording_status(&self, id: &str) -> AppResult<RecordingUpload> {
        let resp = self.authed(self.http.get(self.url(&format!("recordings/{id}"))))?.send().await?;
        Self::parse(resp).await
    }

    pub async fn upload_chunk(&self, recording_id: &str, index: u64, bytes: Vec<u8>, sha256_hex: &str) -> AppResult<UploadState> {
        let resp = self
            .authed(self.http.post(self.url(&format!("recordings/{recording_id}/chunks"))))?
            .header("Content-Type", "application/octet-stream")
            .header("Upload-Chunk-Index", index.to_string())
            .header("Upload-Chunk-Sha256", sha256_hex)
            .body(bytes)
            .send()
            .await?;
        #[derive(serde::Deserialize)]
        struct ChunkResp {
            received_chunks: Vec<u64>,
            total_chunks: u64,
            status: String,
        }
        let r: ChunkResp = Self::parse(resp).await?;
        Ok(UploadState { chunk_size_bytes: 0, total_chunks: r.total_chunks, received_chunks: r.received_chunks, status: r.status, error: None })
    }

    /// `202 {"status": "assembling" | "ready"}`. An answer without a readable status counts as `assembling`, so
    /// the caller polls `GET /api/recordings/{id}` instead of assuming the file is safe on the server.
    pub async fn complete(&self, recording_id: &str) -> AppResult<CompleteResponse> {
        let resp = self.authed(self.http.post(self.url(&format!("recordings/{recording_id}/complete"))))?.send().await?;
        if !resp.status().is_success() {
            return Err(Self::error(resp).await);
        }
        let bytes = resp.bytes().await?;
        let mut answer: CompleteResponse = serde_json::from_slice(&bytes).unwrap_or_default();
        if answer.status.is_empty() {
            answer.status = "assembling".into();
        }
        Ok(answer)
    }

    pub async fn post_transcript(&self, session_id: &str, transcript: &DeviceTranscript) -> AppResult<()> {
        let resp = self.authed(self.http.post(self.url(&format!("sessions/{session_id}/transcripts"))))?.json(transcript).send().await?;
        Self::ok_empty(resp).await
    }

    pub async fn library(&self, since: Option<&str>, cursor: Option<&str>) -> AppResult<LibraryResponse> {
        let mut query: Vec<(&str, &str)> = vec![("limit", "100")];
        if let Some(s) = since {
            query.push(("since", s));
        }
        if let Some(c) = cursor {
            query.push(("cursor", c));
        }
        let resp = self.authed(self.http.get(self.url("library")).query(&query))?.send().await?;
        Self::parse(resp).await
    }

    pub async fn session(&self, id: &str) -> AppResult<serde_json::Value> {
        let resp = self.authed(self.http.get(self.url(&format!("sessions/{id}"))))?.send().await?;
        Self::parse(resp).await
    }

    pub async fn transcript(&self, id: &str) -> AppResult<serde_json::Value> {
        let resp = self.authed(self.http.get(self.url(&format!("transcripts/{id}"))))?.send().await?;
        Self::parse(resp).await
    }

    /// `GET /api/search` (since v1.1): `params` are the documented query parameters (`q`, filters, `page`).
    pub async fn search(&self, params: &[(String, String)]) -> AppResult<serde_json::Value> {
        let resp = self.authed(self.http.get(self.url("search")).query(params))?.send().await?;
        Self::parse(resp).await
    }

    /// `PATCH /api/transcripts/{id}`: new text for one line (timings stay).
    pub async fn edit_transcript(&self, id: &str, index: usize, text: &str) -> AppResult<serde_json::Value> {
        let body = json!({ "edits": [{ "index": index, "text": text }] });
        let resp = self.authed(self.http.patch(self.url(&format!("transcripts/{id}"))))?.json(&body).send().await?;
        Self::parse(resp).await
    }

    /// `POST /api/transcripts/{id}/replace`: `{replaced, transcript}`.
    pub async fn replace_in_transcript(&self, id: &str, find: &str, replace: &str, match_case: bool, whole_word: bool) -> AppResult<serde_json::Value> {
        let body = json!({ "find": find, "replace": replace, "match_case": match_case, "whole_word": whole_word });
        let resp = self.authed(self.http.post(self.url(&format!("transcripts/{id}/replace"))))?.json(&body).send().await?;
        Self::parse(resp).await
    }

    /// `POST /api/transcripts/{id}/restore`: back to the engine's original text.
    pub async fn restore_transcript(&self, id: &str) -> AppResult<serde_json::Value> {
        let resp = self.authed(self.http.post(self.url(&format!("transcripts/{id}/restore"))))?.send().await?;
        Self::parse(resp).await
    }

    /// `GET /api/transcripts/{id}/revisions`.
    pub async fn transcript_revisions(&self, id: &str) -> AppResult<serde_json::Value> {
        let resp = self.authed(self.http.get(self.url(&format!("transcripts/{id}/revisions"))))?.send().await?;
        Self::parse(resp).await
    }

    /// `PUT /api/sessions/{id}/speakers`: `{speaker_names}`.
    pub async fn rename_speakers(&self, session_id: &str, names: &serde_json::Map<String, serde_json::Value>, remember_for_client: bool) -> AppResult<serde_json::Value> {
        let body = json!({ "names": names, "remember_for_client": remember_for_client });
        let resp = self.authed(self.http.put(self.url(&format!("sessions/{session_id}/speakers"))))?.json(&body).send().await?;
        Self::parse(resp).await
    }

    /// `GET /api/transcripts/{id}/export`: the file and the name the server gives it; `view` (since v1.2) exports a
    /// Show as view (`roman` or `english`) instead of the original words.
    pub async fn export_transcript(&self, id: &str, format: &str, timestamps: bool, speakers: bool, view: Option<&str>) -> AppResult<(Vec<u8>, String)> {
        let mut query = vec![("format", format.to_string()), ("timestamps", u8::from(timestamps).to_string()), ("speakers", u8::from(speakers).to_string())];
        if let Some(view) = view {
            query.push(("view", view.to_string()));
        }
        let resp = self.authed(self.http.get(self.url(&format!("transcripts/{id}/export"))).query(&query))?.send().await?;
        if !resp.status().is_success() {
            return Err(Self::error(resp).await);
        }
        let disposition = resp.headers().get(reqwest::header::CONTENT_DISPOSITION).and_then(|v| v.to_str().ok()).unwrap_or_default().to_string();
        let bytes = resp.bytes().await?.to_vec();
        Ok((bytes, filename_from_disposition(&disposition).unwrap_or_else(|| format!("transcript.{format}"))))
    }

    /// `GET /api/transcripts/{id}/views` (since v1.2): the Show as views (Roman Urdu, English) and their state.
    pub async fn transcript_views(&self, id: &str) -> AppResult<serde_json::Value> {
        let resp = self.authed(self.http.get(self.url(&format!("transcripts/{id}/views"))))?.send().await?;
        Self::parse(resp).await
    }

    /// `POST /api/transcripts/{id}/views` (since v1.2): starts making the Show as views.
    pub async fn make_transcript_views(&self, id: &str) -> AppResult<serde_json::Value> {
        let resp = self.authed(self.http.post(self.url(&format!("transcripts/{id}/views"))))?.json(&json!({})).send().await?;
        Self::parse(resp).await
    }

    /// `POST /api/sessions/{id}/shares`: the link (with its URL, shown once).
    pub async fn create_share(&self, session_id: &str, expires_in: u64, password: Option<&str>, include_audio: bool) -> AppResult<serde_json::Value> {
        let body = json!({ "expires_in": expires_in, "password": password, "include_audio": include_audio });
        let resp = self.authed(self.http.post(self.url(&format!("sessions/{session_id}/shares"))))?.json(&body).send().await?;
        Self::parse(resp).await
    }

    /// `PATCH /api/sessions/{id}` (since v1.1): title, client folder, pin or labels; returns the library item.
    /// `POST /api/sessions/{id}/retranscribe` (since v1.2): "Re-transcribe as <language>" (none = Auto); 202 with the run.
    pub async fn retranscribe(&self, id: &str, language: Option<&str>) -> AppResult<serde_json::Value> {
        let resp = self.authed(self.http.post(self.url(&format!("sessions/{id}/retranscribe"))))?.json(&json!({ "language": language })).send().await?;
        Self::parse(resp).await
    }

    pub async fn update_session(&self, id: &str, patch: &serde_json::Value) -> AppResult<serde_json::Value> {
        let resp = self.authed(self.http.patch(self.url(&format!("sessions/{id}"))))?.json(patch).send().await?;
        Self::parse(resp).await
    }

    /// `POST /api/sessions/bulk` (since v1.1): one action for many recordings; returns `{"changed": n}`.
    pub async fn bulk_sessions(&self, ids: &[String], action: &str, client_id: Option<&str>, label: Option<&str>) -> AppResult<serde_json::Value> {
        let body = json!({ "ids": ids, "action": action, "client_id": client_id, "label": label });
        let resp = self.authed(self.http.post(self.url("sessions/bulk")))?.json(&body).send().await?;
        Self::parse(resp).await
    }

    /// `POST /api/sessions/export` (since v1.1): the finished transcripts of many recordings in one zip file.
    pub async fn export_sessions(&self, ids: &[String], format: &str) -> AppResult<(Vec<u8>, String)> {
        let resp = self.authed(self.http.post(self.url("sessions/export")))?.json(&json!({ "ids": ids, "format": format })).send().await?;
        if !resp.status().is_success() {
            return Err(Self::error(resp).await);
        }
        let disposition = resp.headers().get(reqwest::header::CONTENT_DISPOSITION).and_then(|v| v.to_str().ok()).unwrap_or_default().to_string();
        let bytes = resp.bytes().await?.to_vec();
        Ok((bytes, filename_from_disposition(&disposition).unwrap_or_else(|| "EchoLedger transcripts.zip".to_string())))
    }

    /// `POST /api/clients` (since v1.1): a new client folder.
    pub async fn create_client(&self, body: &serde_json::Value) -> AppResult<serde_json::Value> {
        let resp = self.authed(self.http.post(self.url("clients")))?.json(body).send().await?;
        Self::parse(resp).await
    }

    /// `PATCH /api/clients/{id}` (since v1.1): rename, colour, avatar or pin a client folder; since v1.5 also
    /// `parent_id` (move a project into another one, `null` = the top level).
    pub async fn update_client(&self, id: &str, body: &serde_json::Value) -> AppResult<serde_json::Value> {
        let resp = self.authed(self.http.patch(self.url(&format!("clients/{id}"))))?.json(body).send().await?;
        Self::parse(resp).await
    }

    /// `DELETE /api/clients/{id}` (since v1.5): its recordings go to the Inbox and its subfolders up a level;
    /// `{"moved_sessions", "moved_projects"}`.
    pub async fn delete_client(&self, id: &str) -> AppResult<serde_json::Value> {
        let resp = self.authed(self.http.delete(self.url(&format!("clients/{id}"))))?.send().await?;
        Self::parse(resp).await
    }

    /// `GET /api/library/views?tz=` (since v1.1): the smart views' counts and the labels; since v1.5 `folders`, the
    /// smart folders' counts.
    pub async fn library_views(&self, tz: Option<&str>) -> AppResult<serde_json::Value> {
        let mut req = self.http.get(self.url("library/views"));
        if let Some(tz) = tz {
            req = req.query(&[("tz", tz)]);
        }
        let resp = self.authed(req)?.send().await?;
        Self::parse(resp).await
    }

    /// One page of a smart folder (`GET /api/library?folder=`, since v1.5).
    pub async fn library_folder(&self, folder: &str, cursor: Option<&str>) -> AppResult<LibraryResponse> {
        let mut query: Vec<(&str, &str)> = vec![("folder", folder), ("limit", "100")];
        if let Some(c) = cursor {
            query.push(("cursor", c));
        }
        let resp = self.authed(self.http.get(self.url("library")).query(&query))?.send().await?;
        Self::parse(resp).await
    }

    /// `GET /api/clients/{id}/rules` (since v1.5): the project's filing rules.
    pub async fn client_rules(&self, id: &str) -> AppResult<serde_json::Value> {
        let resp = self.authed(self.http.get(self.url(&format!("clients/{id}/rules"))))?.send().await?;
        Self::parse(resp).await
    }

    /// `POST /api/clients/{id}/rules` (since v1.5): `title`, `app` or `contact`; `409 rule_exists` when another
    /// project has the same one.
    pub async fn add_client_rule(&self, id: &str, kind: &str, value: &str) -> AppResult<serde_json::Value> {
        let resp = self.authed(self.http.post(self.url(&format!("clients/{id}/rules"))))?.json(&json!({ "kind": kind, "value": value })).send().await?;
        Self::parse(resp).await
    }

    /// `DELETE /api/clients/{id}/rules/{rule}` (since v1.5).
    pub async fn delete_client_rule(&self, id: &str, rule: &str) -> AppResult<()> {
        let resp = self.authed(self.http.delete(self.url(&format!("clients/{id}/rules/{rule}"))))?.send().await?;
        Self::ok_empty(resp).await
    }

    /// `POST /api/{sessions|clients}/{id}/shares` with the v1.5 fields (audience, people, downloads, expiry); the
    /// answer is the link with its URL, shown once.
    pub async fn create_link_share(&self, target: &str, id: &str, body: &serde_json::Value) -> AppResult<serde_json::Value> {
        let resp = self.authed(self.http.post(self.url(&format!("{target}/{id}/shares"))))?.json(body).send().await?;
        Self::parse(resp).await
    }

    /// `GET /api/shares` (since v1.5): the workspace's share links, newest first, narrowed to one recording or project.
    pub async fn shares(&self, session_id: Option<&str>, client_id: Option<&str>) -> AppResult<serde_json::Value> {
        let mut query: Vec<(&str, &str)> = Vec::new();
        if let Some(id) = session_id {
            query.push(("session_id", id));
        }
        if let Some(id) = client_id {
            query.push(("client_id", id));
        }
        let resp = self.authed(self.http.get(self.url("shares")).query(&query))?.send().await?;
        Self::parse(resp).await
    }

    /// `POST /api/{sessions|clients}/{id}/send` (since v1.5): to teammates with an account; `{"sent": n}`.
    pub async fn send_to(&self, target: &str, id: &str, body: &serde_json::Value) -> AppResult<serde_json::Value> {
        let resp = self.authed(self.http.post(self.url(&format!("{target}/{id}/send"))))?.json(body).send().await?;
        Self::parse(resp).await
    }

    /// `GET /api/sent` (since v1.5): what this person sent of a recording or a project.
    pub async fn sent(&self, session_id: Option<&str>, client_id: Option<&str>) -> AppResult<serde_json::Value> {
        let mut query = Vec::new();
        if let Some(id) = session_id {
            query.push(("session_id", id));
        }
        if let Some(id) = client_id {
            query.push(("client_id", id));
        }
        let resp = self.authed(self.http.get(self.url("sent")).query(&query))?.send().await?;
        Self::parse(resp).await
    }

    /// `DELETE /api/sent/{id}` (since v1.5): stop sharing what was sent; a copy they saved stays theirs.
    pub async fn stop_sharing(&self, id: &str) -> AppResult<()> {
        let resp = self.authed(self.http.delete(self.url(&format!("sent/{id}"))))?.send().await?;
        Self::ok_empty(resp).await
    }

    /// `GET /api/shared-with-me` (since v1.5): recordings and projects teammates sent.
    pub async fn shared_with_me(&self) -> AppResult<serde_json::Value> {
        let resp = self.authed(self.http.get(self.url("shared-with-me")))?.send().await?;
        Self::parse(resp).await
    }

    /// `POST /api/shared-with-me/{id}/save` (since v1.5): a copy in this workspace; `{"session_id"}` or `{"client_id"}`.
    pub async fn save_shared(&self, id: &str) -> AppResult<serde_json::Value> {
        let resp = self.authed(self.http.post(self.url(&format!("shared-with-me/{id}/save"))))?.send().await?;
        Self::parse(resp).await
    }

    /// `DELETE /api/shared-with-me/{id}` (since v1.5): off the list; the sender's recording stays.
    pub async fn remove_shared(&self, id: &str) -> AppResult<()> {
        let resp = self.authed(self.http.delete(self.url(&format!("shared-with-me/{id}"))))?.send().await?;
        Self::ok_empty(resp).await
    }

    /// `POST /api/request-links` (since v1.5): a public upload page into a project or the Inbox (`url` shown once).
    pub async fn create_request_link(&self, body: &serde_json::Value) -> AppResult<serde_json::Value> {
        let resp = self.authed(self.http.post(self.url("request-links")))?.json(body).send().await?;
        Self::parse(resp).await
    }

    /// `GET /api/request-links` (since v1.5).
    pub async fn request_links(&self) -> AppResult<serde_json::Value> {
        let resp = self.authed(self.http.get(self.url("request-links")))?.send().await?;
        Self::parse(resp).await
    }

    /// `DELETE /api/request-links/{id}` (since v1.5): the upload page stops working.
    pub async fn revoke_request_link(&self, id: &str) -> AppResult<()> {
        let resp = self.authed(self.http.delete(self.url(&format!("request-links/{id}"))))?.send().await?;
        Self::ok_empty(resp).await
    }

    /// `GET /api/links/resolve?url=` (since v1.5): what a share, project or request link of this server is for the
    /// caller; `404 not_found` for anything it may not open in place.
    pub async fn resolve_link(&self, link: &str) -> AppResult<serde_json::Value> {
        let resp = self.authed(self.http.get(self.url("links/resolve")).query(&[("url", link)]))?.send().await?;
        Self::parse(resp).await
    }
    /// `GET /api/security` (since v1.1): paired devices and the recent audit log.
    pub async fn security(&self) -> AppResult<serde_json::Value> {
        let resp = self.authed(self.http.get(self.url("security")))?.send().await?;
        Self::parse(resp).await
    }

    /// `DELETE /api/devices/{id}` (since v1.1): revokes another paired device.
    pub async fn revoke_device(&self, id: &str) -> AppResult<()> {
        let resp = self.authed(self.http.delete(self.url(&format!("devices/{id}"))))?.send().await?;
        Self::ok_empty(resp).await
    }
    /// `GET /api/bin` (since v1.1): recordings deleted in the last 48 hours.
    pub async fn bin(&self) -> AppResult<serde_json::Value> {
        let resp = self.authed(self.http.get(self.url("bin")))?.send().await?;
        Self::parse(resp).await
    }

    /// `POST /api/sessions/{id}/restore` (since v1.1): out of the undo bin.
    pub async fn restore_session(&self, id: &str) -> AppResult<serde_json::Value> {
        let resp = self.authed(self.http.post(self.url(&format!("sessions/{id}/restore"))))?.send().await?;
        Self::parse(resp).await
    }

    /// `DELETE /api/bin/{id}` (since v1.1): erase now instead of after 48 hours.
    pub async fn erase_from_bin(&self, id: &str) -> AppResult<()> {
        let resp = self.authed(self.http.delete(self.url(&format!("bin/{id}"))))?.send().await?;
        Self::ok_empty(resp).await
    }

    /// `GET /api/storage` (since v1.1): the storage meter and the retention in days.
    pub async fn storage(&self) -> AppResult<serde_json::Value> {
        let resp = self.authed(self.http.get(self.url("storage")))?.send().await?;
        Self::parse(resp).await
    }
    /// `GET /api/activity?limit=` (since v1.1): the activity centre, newest first.
    pub async fn activity(&self, limit: u32) -> AppResult<serde_json::Value> {
        let resp = self.authed(self.http.get(self.url("activity")).query(&[("limit", limit.to_string())]))?.send().await?;
        Self::parse(resp).await
    }

    /// `POST /api/activity/read` (since v1.1): those ids, or everything when `None`.
    pub async fn mark_activity_read(&self, ids: Option<&[String]>) -> AppResult<serde_json::Value> {
        let body = ids.map_or_else(|| json!({}), |ids| json!({ "ids": ids }));
        let resp = self.authed(self.http.post(self.url("activity/read")))?.json(&body).send().await?;
        Self::parse(resp).await
    }
    /// `GET /api/sessions/{id}/shares`.
    pub async fn list_shares(&self, session_id: &str) -> AppResult<serde_json::Value> {
        let resp = self.authed(self.http.get(self.url(&format!("sessions/{session_id}/shares"))))?.send().await?;
        Self::parse(resp).await
    }

    /// `DELETE /api/shares/{id}`.
    pub async fn revoke_share(&self, id: &str) -> AppResult<()> {
        let resp = self.authed(self.http.delete(self.url(&format!("shares/{id}"))))?.send().await?;
        Self::ok_empty(resp).await
    }

    /// `POST /api/vocabulary/keyterms`: "Add to vocabulary" (for the client, or for every recording).
    pub async fn add_keyterm(&self, term: &str, client_id: Option<&str>) -> AppResult<serde_json::Value> {
        let body = json!({ "term": term, "client_id": client_id });
        let resp = self.authed(self.http.post(self.url("vocabulary/keyterms")))?.json(&body).send().await?;
        Self::parse(resp).await
    }

    /// `POST /api/vocabulary/replacements`: "Always write X as Y" (for the client, or for every recording), as whole
    /// words in any case.
    pub async fn add_replacement(&self, from: &str, to: &str, client_id: Option<&str>) -> AppResult<serde_json::Value> {
        let body = json!({ "from": from, "to": to, "match_case": false, "whole_word": true, "client_id": client_id });
        let resp = self.authed(self.http.post(self.url("vocabulary/replacements")))?.json(&body).send().await?;
        Self::parse(resp).await
    }

    /// `POST /api/transcripts/{id}/suggestions/{suggestion}` (since v1.4): accepts or dismisses a suggested fix;
    /// returns the transcript.
    pub async fn decide_suggestion(&self, transcript_id: &str, suggestion: u64, accept: bool) -> AppResult<serde_json::Value> {
        let resp = self.authed(self.http.post(self.url(&format!("transcripts/{transcript_id}/suggestions/{suggestion}"))))?.json(&json!({ "accept": accept })).send().await?;
        Self::parse(resp).await
    }

    /// `GET /api/clients/{id}/workspace` (since v1.4): the brief, memory, call notes and files of a client.
    pub async fn client_workspace(&self, id: &str) -> AppResult<serde_json::Value> {
        let resp = self.authed(self.http.get(self.url(&format!("clients/{id}/workspace"))))?.send().await?;
        Self::parse(resp).await
    }

    /// `PUT /api/clients/{id}/brief` (since v1.4): a person's edit of the brief, never rewritten by itself after.
    pub async fn save_client_brief(&self, id: &str, sections: &serde_json::Value) -> AppResult<serde_json::Value> {
        let resp = self.authed(self.http.put(self.url(&format!("clients/{id}/brief"))))?.json(&json!({ "sections": sections })).send().await?;
        Self::parse(resp).await
    }

    /// `POST /api/clients/{id}/brief/refresh` (since v1.4): writes the brief again on the server's queue.
    pub async fn refresh_client_brief(&self, id: &str) -> AppResult<serde_json::Value> {
        let resp = self.authed(self.http.post(self.url(&format!("clients/{id}/brief/refresh"))))?.send().await?;
        Self::parse(resp).await
    }

    /// `POST /api/clients/{id}/memory` (since v1.4): a memory line; `happened_on` (YYYY-MM-DD) defaults to today.
    pub async fn add_client_memory(&self, id: &str, kind: &str, text: &str, happened_on: Option<&str>) -> AppResult<serde_json::Value> {
        let mut body = json!({ "kind": kind, "text": text });
        if let Some(day) = happened_on {
            body["happened_on"] = json!(day);
        }
        let resp = self.authed(self.http.post(self.url(&format!("clients/{id}/memory"))))?.json(&body).send().await?;
        Self::parse(resp).await
    }

    /// `PATCH /api/clients/{id}/memory/{item}` (since v1.4): new text or pin of a memory line.
    pub async fn update_client_memory(&self, id: &str, item: &str, patch: &serde_json::Value) -> AppResult<serde_json::Value> {
        let resp = self.authed(self.http.patch(self.url(&format!("clients/{id}/memory/{item}"))))?.json(patch).send().await?;
        Self::parse(resp).await
    }

    /// `DELETE /api/clients/{id}/memory/{item}` (since v1.4).
    pub async fn delete_client_memory(&self, id: &str, item: &str) -> AppResult<()> {
        let resp = self.authed(self.http.delete(self.url(&format!("clients/{id}/memory/{item}"))))?.send().await?;
        Self::ok_empty(resp).await
    }

    /// `POST /api/clients/{id}/files` (since v1.4): a PDF, Word, text or Markdown file of at most 20 MB, sent as
    /// `multipart/form-data`. The server reads its text on its queue.
    pub async fn upload_client_file(&self, id: &str, name: &str, mime: &str, bytes: Vec<u8>) -> AppResult<serde_json::Value> {
        let boundary = format!("echoledger-{}", uuid::Uuid::new_v4().simple());
        let body = multipart_file(&boundary, "file", name, mime, &bytes);
        drop(bytes);
        // 20 MB on a slow line takes longer than the client's usual 120 s.
        let resp = self
            .authed(self.http.post(self.url(&format!("clients/{id}/files"))))?
            .header("Content-Type", format!("multipart/form-data; boundary={boundary}"))
            .timeout(std::time::Duration::from_secs(300))
            .body(body)
            .send()
            .await?;
        Self::parse(resp).await
    }

    /// `POST /api/clients/{id}/files` (since v1.4) with JSON: pasted instructions, kept like a file.
    pub async fn add_client_instructions(&self, id: &str, name: Option<&str>, text: &str) -> AppResult<serde_json::Value> {
        let resp = self.authed(self.http.post(self.url(&format!("clients/{id}/files"))))?.json(&json!({ "text": text, "name": name })).send().await?;
        Self::parse(resp).await
    }

    /// `DELETE /api/clients/{id}/files/{file}` (since v1.4): the file and its encrypted original.
    pub async fn delete_client_file(&self, id: &str, file: &str) -> AppResult<()> {
        let resp = self.authed(self.http.delete(self.url(&format!("clients/{id}/files/{file}"))))?.send().await?;
        Self::ok_empty(resp).await
    }

    /// `POST /api/clients/{id}/ask` (since v1.4): an answer from the client's calls and files, with citations.
    pub async fn ask_client(&self, id: &str, question: &str, language: &str) -> AppResult<serde_json::Value> {
        let resp = self.authed(self.http.post(self.url(&format!("clients/{id}/ask"))))?.json(&json!({ "question": question, "language": language })).send().await?;
        Self::parse(resp).await
    }

    /// `GET /api/clients/{id}/bundle?notes=` (since v1.4): "Copy for AI"; without `notes` the server takes the last 5.
    pub async fn client_bundle(&self, id: &str, notes: Option<u32>) -> AppResult<serde_json::Value> {
        let mut req = self.http.get(self.url(&format!("clients/{id}/bundle")));
        if let Some(notes) = notes {
            req = req.query(&[("notes", notes.to_string())]);
        }
        let resp = self.authed(req)?.send().await?;
        Self::parse(resp).await
    }

    /// `GET /api/sessions/{id}/notes` (since v1.4): the call notes; `404 notes_not_found` when none were written.
    pub async fn session_notes(&self, id: &str) -> AppResult<serde_json::Value> {
        let resp = self.authed(self.http.get(self.url(&format!("sessions/{id}/notes"))))?.send().await?;
        Self::parse(resp).await
    }

    /// `POST /api/sessions/{id}/notes` (since v1.4): writes the call notes again, in `language` when given.
    pub async fn write_session_notes(&self, id: &str, language: Option<&str>) -> AppResult<serde_json::Value> {
        let body = language.map_or_else(|| json!({}), |language| json!({ "language": language }));
        let resp = self.authed(self.http.post(self.url(&format!("sessions/{id}/notes"))))?.json(&body).send().await?;
        Self::parse(resp).await
    }

    pub async fn delete_session(&self, id: &str) -> AppResult<()> {
        let resp = self.authed(self.http.delete(self.url(&format!("sessions/{id}"))))?.send().await?;
        Self::ok_empty(resp).await
    }

    /// `POST /api/sessions/{id}/screens` (since v1.5): one screen moment as `multipart/form-data`, the WebP `image`
    /// with its moment, size, trigger and SHA-256. The same image again is `200` with the screen the server has.
    pub async fn upload_screen(&self, session_id: &str, screen: &ScreenUpload, image: &[u8]) -> AppResult<serde_json::Value> {
        let boundary = format!("echoledger-{}", uuid::Uuid::new_v4().simple());
        let fields = [
            ("at_ms", screen.at_ms.to_string()),
            ("width", screen.width.to_string()),
            ("height", screen.height.to_string()),
            ("trigger", screen.trigger.clone()),
            ("sha256", screen.sha256.clone()),
        ];
        let body = multipart_form(&boundary, &fields, "image", "screen.webp", "image/webp", image);
        let resp = self
            .authed(self.http.post(self.url(&format!("sessions/{session_id}/screens"))))?
            .header("Content-Type", format!("multipart/form-data; boundary={boundary}"))
            .body(body)
            .send()
            .await?;
        Self::parse(resp).await
    }

    /// `GET /api/sessions/{id}/screens` (since v1.5): the recording's screens in time order, with signed image links.
    pub async fn session_screens(&self, id: &str) -> AppResult<serde_json::Value> {
        let resp = self.authed(self.http.get(self.url(&format!("sessions/{id}/screens"))))?.send().await?;
        Self::parse(resp).await
    }

    /// `DELETE /api/sessions/{id}/screens/{screen}` (since v1.5).
    pub async fn delete_screen(&self, session_id: &str, screen: &str) -> AppResult<()> {
        let resp = self.authed(self.http.delete(self.url(&format!("sessions/{session_id}/screens/{screen}"))))?.send().await?;
        Self::ok_empty(resp).await
    }

    /// `GET /api/clients/{id}/screens` (since v1.5): a page of the project's screens, newest recording first.
    pub async fn client_screens(&self, id: &str, include_subfolders: bool, cursor: Option<&str>) -> AppResult<serde_json::Value> {
        let mut query = vec![("include_subfolders", u8::from(include_subfolders).to_string())];
        if let Some(cursor) = cursor.filter(|c| !c.is_empty()) {
            query.push(("cursor", cursor.to_string()));
        }
        let resp = self.authed(self.http.get(self.url(&format!("clients/{id}/screens"))).query(&query))?.send().await?;
        Self::parse(resp).await
    }

    /// `GET /api/sessions/{id}/pack` (since v1.5): the meeting pack (`html` to print, or `zip`) and the name the server
    /// gives it.
    pub async fn meeting_pack(&self, id: &str, format: &str, view: Option<&str>, include_audio: bool) -> AppResult<(Vec<u8>, String)> {
        let mut query = vec![("format", format.to_string()), ("include_audio", u8::from(include_audio).to_string())];
        if let Some(view) = view {
            query.push(("view", view.to_string()));
        }
        // A pack with the audio takes longer than the client's usual 120 s.
        let resp = self.authed(self.http.get(self.url(&format!("sessions/{id}/pack"))).query(&query))?.timeout(std::time::Duration::from_secs(600)).send().await?;
        if !resp.status().is_success() {
            return Err(Self::error(resp).await);
        }
        let disposition = resp.headers().get(reqwest::header::CONTENT_DISPOSITION).and_then(|v| v.to_str().ok()).unwrap_or_default().to_string();
        let bytes = resp.bytes().await?.to_vec();
        Ok((bytes, filename_from_disposition(&disposition).unwrap_or_else(|| format!("meeting-pack.{format}"))))
    }

    /// A screen's signed `image_url` or `thumb_url` (no bearer), fetched through the pinned client so the picture never
    /// comes from anywhere but this server. Images only, at most 4 MB.
    pub async fn screen_image(&self, url: &str) -> AppResult<(Vec<u8>, String)> {
        if !same_server(&self.base, url) {
            return Err(AppError::msg("The server sent a screen link of another site."));
        }
        let resp = self.http.get(url).send().await?;
        if !resp.status().is_success() {
            return Err(Self::error(resp).await);
        }
        let mime = resp.headers().get(reqwest::header::CONTENT_TYPE).and_then(|v| v.to_str().ok()).unwrap_or_default().split(';').next().unwrap_or_default().trim().to_ascii_lowercase();
        if !matches!(mime.as_str(), "image/webp" | "image/png" | "image/jpeg") {
            return Err(AppError::msg("The server did not send an image."));
        }
        let bytes = resp.bytes().await?;
        if bytes.len() > 4 * 1024 * 1024 {
            return Err(AppError::msg("The screen is too large to show."));
        }
        Ok((bytes.to_vec(), mime))
    }

    /// Streams a recording's signed `playback_url` (no bearer) into `path`. The file appears only once it is complete,
    /// so a cut-off download leaves nothing half-written behind.
    pub async fn download_audio(&self, url: &str, path: &std::path::Path) -> AppResult<u64> {
        use futures_util::StreamExt;
        use std::io::Write;
        if !(url.starts_with("https://") || super::pinning::is_local_dev(url)) {
            return Err(AppError::msg("The server sent an audio link that is not secure."));
        }
        // A long recording takes longer than the client's usual 120 s.
        let resp = self.http.get(url).timeout(std::time::Duration::from_secs(1800)).send().await?;
        if !resp.status().is_success() {
            return Err(Self::error(resp).await);
        }
        let name = path.file_name().map(|n| n.to_string_lossy().into_owned()).unwrap_or_default();
        let part = path.with_file_name(format!("{name}.part"));
        let written = async {
            let mut file = std::fs::File::create(&part)?;
            let mut size = 0u64;
            let mut body = resp.bytes_stream();
            while let Some(chunk) = body.next().await {
                let chunk = chunk?;
                file.write_all(&chunk)?;
                size += chunk.len() as u64;
            }
            file.sync_all()?;
            AppResult::Ok(size)
        }
        .await;
        match written {
            Ok(size) => {
                std::fs::rename(&part, path)?;
                Ok(size)
            }
            Err(e) => {
                let _ = std::fs::remove_file(&part);
                Err(e)
            }
        }
    }
}

/// Runs `call` with the selected client and, when the server answers `404 client_not_found` (the client was
/// deleted in the console), once more without a client (A6.DR7, docs/API.md "Error codes"). Returns the answer
/// and, when the fallback was used, the client id the caller must forget.
pub async fn with_client_fallback<T, F, Fut>(client_id: Option<String>, mut call: F) -> AppResult<(T, Option<String>)>
where
    F: FnMut(Option<String>) -> Fut,
    Fut: Future<Output = AppResult<T>>,
{
    match call(client_id.clone()).await {
        Err(e) if client_id.is_some() && e.code() == Some("client_not_found") => call(None).await.map(|v| (v, client_id)),
        other => other.map(|v| (v, None)),
    }
}

type ClientKey = (String, Vec<String>, String);

/// One HTTP client for the whole app (A6.DR15): rebuilt only when the server URL, the pin set or the token
/// changes, so connections and TLS sessions are reused instead of re-handshaking on every command.
#[derive(Default)]
pub struct ApiClientCache {
    current: Mutex<Option<(ClientKey, ApiClient)>>,
    builds: AtomicUsize,
}

impl ApiClientCache {
    pub fn get(&self, server_url: &str, pins: &[String], token: &str) -> AppResult<ApiClient> {
        let key: ClientKey = (server_url.to_string(), pins.to_vec(), token.to_string());
        let mut current = self.current.lock().unwrap_or_else(|p| p.into_inner());
        if let Some((cached, client)) = current.as_ref()
            && *cached == key
        {
            return Ok(client.clone());
        }
        let client = ApiClient::new(server_url, pins, Some(token.to_string()))?;
        self.builds.fetch_add(1, Ordering::Relaxed);
        *current = Some((key, client.clone()));
        Ok(client)
    }

    /// Drops the cached client (unpairing).
    pub fn clear(&self) {
        *self.current.lock().unwrap_or_else(|p| p.into_inner()) = None;
    }

    /// How many clients were built so far.
    pub fn builds(&self) -> usize {
        self.builds.load(Ordering::Relaxed)
    }
}

/// The file name of a `Content-Disposition` header: the UTF-8 `filename*` when present, else `filename`. Path
/// separators and other characters file systems refuse are removed.
pub fn filename_from_disposition(header: &str) -> Option<String> {
    let mut plain = None;
    let mut extended = None;
    for part in header.split(';').map(str::trim) {
        if let Some(value) = part.strip_prefix("filename*=") {
            let value = value.trim_matches('"');
            let encoded = value.split_once("''").map_or(value, |(_, v)| v);
            extended = Some(percent_decode(encoded));
        } else if let Some(value) = part.strip_prefix("filename=") {
            plain = Some(value.trim_matches('"').to_string());
        }
    }
    let name: String = extended.or(plain)?.chars().filter(|c| !matches!(c, '/' | '\\' | ':' | '*' | '?' | '"' | '<' | '>' | '|') && !c.is_control()).collect();
    let name = name.trim().trim_start_matches('.').to_string();
    (!name.is_empty()).then_some(name)
}

/// A `multipart/form-data` body with one file part (reqwest is built here without its multipart support). Quotes and
/// line breaks are taken out of the file name, so it cannot end the part's header early.
pub fn multipart_file(boundary: &str, field: &str, filename: &str, mime: &str, bytes: &[u8]) -> Vec<u8> {
    let filename: String = filename.chars().filter(|c| !matches!(c, '"' | '\r' | '\n')).collect();
    let head = format!("--{boundary}\r\nContent-Disposition: form-data; name=\"{field}\"; filename=\"{filename}\"\r\nContent-Type: {mime}\r\n\r\n");
    let tail = format!("\r\n--{boundary}--\r\n");
    let mut body = Vec::with_capacity(head.len() + bytes.len() + tail.len());
    body.extend_from_slice(head.as_bytes());
    body.extend_from_slice(bytes);
    body.extend_from_slice(tail.as_bytes());
    body
}

/// A `multipart/form-data` body with text `fields` before one file part. Values are numbers, hex and plain words;
/// line breaks are taken out all the same.
pub fn multipart_form(boundary: &str, fields: &[(&str, String)], field: &str, filename: &str, mime: &str, bytes: &[u8]) -> Vec<u8> {
    let mut body = Vec::with_capacity(bytes.len() + 512);
    for (name, value) in fields {
        let value: String = value.chars().filter(|c| !matches!(c, '\r' | '\n')).collect();
        body.extend_from_slice(format!("--{boundary}\r\nContent-Disposition: form-data; name=\"{name}\"\r\n\r\n{value}\r\n").as_bytes());
    }
    body.extend_from_slice(&multipart_file(boundary, field, filename, mime, bytes));
    body
}

/// Does `url` point at the server `base` (same scheme, host and port)?
pub fn same_server(base: &str, url: &str) -> bool {
    let origin = |u: &str| -> Option<String> {
        let (scheme, rest) = u.trim().split_once("://")?;
        let authority = rest.split(['/', '?', '#']).next()?;
        (!authority.is_empty() && !authority.contains('@')).then(|| format!("{}://{}", scheme.to_ascii_lowercase(), authority.to_ascii_lowercase()))
    };
    matches!((origin(base), origin(url)), (Some(a), Some(b)) if a == b)
}

fn percent_decode(text: &str) -> String {
    let bytes = text.as_bytes();
    let mut out = Vec::with_capacity(bytes.len());
    let mut i = 0;
    while i < bytes.len() {
        if bytes[i] == b'%'
            && i + 2 < bytes.len()
            && let Some(byte) = text.get(i + 1..i + 3).and_then(|hex| u8::from_str_radix(hex, 16).ok())
        {
            out.push(byte);
            i += 3;
            continue;
        }
        out.push(bytes[i]);
        i += 1;
    }
    String::from_utf8_lossy(&out).into_owned()
}

/// A teammate's open request in the same workspace (`409 request_pending` with only its plan, stage and owner).
pub fn teammate_request_text(body: &serde_json::Value) -> String {
    let owner = body.get("owner_name").and_then(|v| v.as_str()).map(str::trim).filter(|v| !v.is_empty());
    let plan = body.get("plan_name").and_then(|v| v.as_str()).map(str::trim).filter(|v| !v.is_empty());
    match (owner, plan) {
        (Some(owner), Some(plan)) => format!("{owner} has already asked to upgrade this workspace to {plan}. We'll let everyone know when it's decided."),
        _ => "You already asked for an upgrade. It is waiting for the EchoLedger team.".to_string(),
    }
}
