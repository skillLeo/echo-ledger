//! The relay's side of the server's internal API (contract 1.9.0-b, "Internal (relay → server)").
//!
//! Every request is signed with HMAC-SHA256 (the `assist_relay` secret) over
//! `timestamp + "\n" + method + "\n" + path + "\n" + body`, sent as lowercase hex in `X-Assist-Signature` with the unix
//! time in `X-Assist-Timestamp`; the server accepts ±60 s.
//!
//! - `GET /api/internal/assist/providers`: the provider keys (kept in memory only, never written or logged) and the
//!   models and mode map, which replace the settings file's. Fetched at start, then every 10 minutes.
//! - `POST /api/internal/assist/usage`: one record per suggestion (tokens, cost, latency stages), idempotent per
//!   `suggestion_id`.
//! - `POST /api/internal/assist/suggestions`: the final text of each suggestion shown, for the report.
//!
//! Reports go out from a queue in the background, so a slow server never holds up a suggestion.

use std::collections::BTreeMap;
use std::fmt;
use std::sync::atomic::{AtomicUsize, Ordering};
use std::sync::{Arc, Mutex, RwLock};
use std::time::Duration;

use hmac::{Hmac, KeyInit, Mac};
use serde::{Deserialize, Serialize};
use sha2::Sha256;
use thiserror::Error;
use tokio::sync::{Semaphore, mpsc};

use crate::config::{Catalog, RelayConfig, Secret, Shared};
use crate::domain::{Mode, ProviderKind};
use crate::protocol::{Latency, SuggestionKind, UsageReport};
use crate::time::unix_now;

pub const SIGNATURE_HEADER: &str = "X-Assist-Signature";
pub const TIMESTAMP_HEADER: &str = "X-Assist-Timestamp";
/// How far the server lets the timestamp drift.
pub const TIMESTAMP_WINDOW_SECS: i64 = 60;

pub const PROVIDERS_PATH: &str = "/api/internal/assist/providers";
pub const USAGE_PATH: &str = "/api/internal/assist/usage";
pub const SUGGESTIONS_PATH: &str = "/api/internal/assist/suggestions";

const REFRESH_EVERY: Duration = Duration::from_secs(600);
const RETRY_FIRST_FETCH: Duration = Duration::from_secs(30);
const QUEUE: usize = 10_000;
const IN_FLIGHT: usize = 8;
const ATTEMPTS: u32 = 5;

/// The signature of one request: lowercase hex HMAC-SHA256 of `timestamp\nMETHOD\npath\nbody`.
pub fn sign(secret: &Secret, timestamp: i64, method: &str, path: &str, body: &[u8]) -> String {
    let mut mac = Hmac::<Sha256>::new_from_slice(secret.expose()).unwrap_or_else(|_| unreachable!("HMAC takes any key length"));
    mac.update(format!("{timestamp}\n{method}\n{path}\n").as_bytes());
    mac.update(body);
    mac.finalize().into_bytes().iter().map(|byte| format!("{byte:02x}")).collect()
}

/// The server's check, for tests and for anyone mirroring it: the timestamp within ±60 s and the signature equal in
/// constant time.
pub fn verify(secret: &Secret, timestamp: i64, now: i64, method: &str, path: &str, body: &[u8], signature: &str) -> bool {
    if (now - timestamp).abs() > TIMESTAMP_WINDOW_SECS {
        return false;
    }
    let Some(tag) = decode_hex(signature) else {
        return false;
    };
    let mut mac = Hmac::<Sha256>::new_from_slice(secret.expose()).unwrap_or_else(|_| unreachable!("HMAC takes any key length"));
    mac.update(format!("{timestamp}\n{method}\n{path}\n").as_bytes());
    mac.update(body);
    mac.verify_slice(&tag).is_ok()
}

fn decode_hex(text: &str) -> Option<Vec<u8>> {
    if !text.len().is_multiple_of(2) {
        return None;
    }
    (0..text.len()).step_by(2).map(|i| u8::from_str_radix(text.get(i..i + 2)?, 16).ok()).collect()
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Error)]
pub enum InternalError {
    #[error("the server could not be reached")]
    Network,
    #[error("the server answered {0}")]
    Status(u16),
    #[error("the server's answer was not readable")]
    Body,
}

/// The answer of `GET /api/internal/assist/providers`.
#[derive(Clone, Deserialize)]
pub struct ProvidersResponse {
    /// Provider -> API key.
    #[serde(default)]
    pub keys: BTreeMap<ProviderKind, String>,
    #[serde(flatten)]
    pub catalog: Catalog,
}

impl fmt::Debug for ProvidersResponse {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("ProvidersResponse").field("keys", &self.keys.len()).field("catalog", &self.catalog).finish()
    }
}

/// The provider keys, in memory only.
#[derive(Default)]
pub struct ProviderKeys(RwLock<BTreeMap<ProviderKind, Secret>>);

impl fmt::Debug for ProviderKeys {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        write!(f, "ProviderKeys({} keys)", self.count())
    }
}

impl ProviderKeys {
    pub fn replace(&self, keys: BTreeMap<ProviderKind, String>) {
        let keys = keys.into_iter().filter(|(_, key)| !key.is_empty()).map(|(kind, key)| (kind, Secret::new(key))).collect();
        if let Ok(mut guard) = self.0.write() {
            *guard = keys;
        }
    }

    pub fn get(&self, kind: ProviderKind) -> Option<Secret> {
        self.0.read().ok()?.get(&kind).cloned()
    }

    pub fn count(&self) -> usize {
        self.0.read().map_or(0, |keys| keys.len())
    }
}

/// Signed requests to the server.
///
/// During a secret rotation the relay holds two secrets and the server checks one of them, whichever the console has
/// switched to. Requests are signed with the secret the server last accepted (the current one at start); on a `401` the
/// same request is signed once with the other secret, and the one that works is kept for the next requests.
pub struct InternalApi {
    base: String,
    secrets: Vec<Secret>,
    /// Index in `secrets` of the one the server last accepted.
    signing: AtomicUsize,
    http: reqwest::Client,
}

impl fmt::Debug for InternalApi {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("InternalApi").field("base", &self.base).field("secrets", &self.secrets.len()).finish()
    }
}

impl InternalApi {
    /// `secrets`: the current one first, then the previous one during a rotation.
    pub fn new(base: &str, secrets: Vec<Secret>) -> Result<Self, InternalError> {
        if secrets.is_empty() {
            return Err(InternalError::Network);
        }
        let _ = rustls::crypto::ring::default_provider().install_default();
        let http = reqwest::Client::builder()
            .connect_timeout(Duration::from_secs(5))
            .timeout(Duration::from_secs(15))
            .build()
            .map_err(|_| InternalError::Network)?;
        Ok(InternalApi { base: base.trim_end_matches('/').to_owned(), secrets, signing: AtomicUsize::new(0), http })
    }

    async fn send(&self, method: reqwest::Method, path: &str, body: Vec<u8>) -> Result<Vec<u8>, InternalError> {
        let first = self.signing.load(Ordering::Relaxed) % self.secrets.len();
        match self.send_signed(&self.secrets[first], method.clone(), path, &body).await {
            Err(InternalError::Status(401)) if self.secrets.len() > 1 => {
                let other = (first + 1) % self.secrets.len();
                let answer = self.send_signed(&self.secrets[other], method, path, &body).await;
                if answer.is_ok() {
                    self.signing.store(other, Ordering::Relaxed);
                    tracing::info!(event = "internal_secret_switched", secret = if other == 0 { "current" } else { "previous" });
                }
                answer
            }
            answer => answer,
        }
    }

    async fn send_signed(&self, secret: &Secret, method: reqwest::Method, path: &str, body: &[u8]) -> Result<Vec<u8>, InternalError> {
        let url = reqwest::Url::parse(&format!("{}{path}", self.base)).map_err(|_| InternalError::Network)?;
        let timestamp = unix_now();
        let signature = sign(secret, timestamp, method.as_str(), url.path(), body);
        let response = self
            .http
            .request(method, url)
            .header(TIMESTAMP_HEADER, timestamp.to_string())
            .header(SIGNATURE_HEADER, signature)
            .header(reqwest::header::CONTENT_TYPE, "application/json")
            .header(reqwest::header::ACCEPT, "application/json")
            .body(body.to_vec())
            .send()
            .await
            .map_err(|_| InternalError::Network)?;
        let status = response.status();
        if !status.is_success() {
            return Err(InternalError::Status(status.as_u16()));
        }
        response.bytes().await.map(|bytes| bytes.to_vec()).map_err(|_| InternalError::Body)
    }

    pub async fn providers(&self) -> Result<ProvidersResponse, InternalError> {
        let body = self.send(reqwest::Method::GET, PROVIDERS_PATH, Vec::new()).await?;
        serde_json::from_slice(&body).map_err(|_| InternalError::Body)
    }

    pub async fn post(&self, path: &str, body: Vec<u8>) -> Result<(), InternalError> {
        self.send(reqwest::Method::POST, path, body).await.map(|_| ())
    }
}

/// Fetches the providers at start (every 30 s until it works), then every 10 minutes: the keys go to `keys`, the
/// models and mode map replace `base`'s in `config`. A refused catalog keeps the last good one.
pub fn spawn_provider_refresh(
    api: Arc<InternalApi>,
    base: Arc<RelayConfig>,
    config: Arc<Shared<RelayConfig>>,
    keys: Arc<ProviderKeys>,
) -> tokio::task::JoinHandle<()> {
    tokio::spawn(async move {
        loop {
            let wait = match api.providers().await {
                Ok(answer) => {
                    keys.replace(answer.keys);
                    match base.with_catalog(answer.catalog) {
                        Ok(next) => {
                            let models = next.models.len();
                            config.set(next);
                            tracing::info!(event = "providers_fetched", keys = keys.count(), models);
                        }
                        Err(error) => tracing::warn!(event = "providers_refused", reason = %error),
                    }
                    REFRESH_EVERY
                }
                Err(error) => {
                    tracing::warn!(event = "providers_fetch_failed", reason = %error);
                    if keys.count() == 0 { RETRY_FIRST_FETCH } else { REFRESH_EVERY }
                }
            };
            tokio::time::sleep(wait).await;
        }
    })
}

#[derive(Debug, Clone, Copy, Serialize, PartialEq, Eq)]
pub struct TokenCounts {
    pub input: u32,
    pub cached: u32,
    pub written: u32,
    pub output: u32,
}

/// `POST /api/internal/assist/usage`: numbers and ids only.
#[derive(Debug, Clone, Serialize)]
pub struct UsageRecord {
    pub suggestion_id: String,
    pub session_id: String,
    pub model: String,
    pub provider: ProviderKind,
    pub mode: Mode,
    pub kind: SuggestionKind,
    pub trigger: &'static str,
    pub outcome: &'static str,
    pub fallback: bool,
    pub tokens: TokenCounts,
    pub cost_usd: f64,
    pub estimated: bool,
    pub latency: Latency,
    pub at: String,
}

impl UsageRecord {
    pub fn tokens_of(usage: &UsageReport) -> TokenCounts {
        TokenCounts {
            input: usage.input_tokens,
            cached: usage.cached_input_tokens,
            written: usage.cache_write_tokens,
            output: usage.output_tokens,
        }
    }
}

/// `POST /api/internal/assist/suggestions`: the words of a suggestion that was shown. Never logged.
#[derive(Clone, Serialize)]
pub struct SuggestionText {
    pub suggestion_id: String,
    pub session_id: String,
    pub turn_id: Option<String>,
    pub kind: SuggestionKind,
    pub mode: Mode,
    pub model: String,
    pub replaces: Option<String>,
    pub text: String,
    pub at: String,
}

impl fmt::Debug for SuggestionText {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("SuggestionText").field("suggestion_id", &self.suggestion_id).field("chars", &self.text.chars().count()).finish()
    }
}

#[derive(Debug, Clone)]
pub enum Report {
    Usage(UsageRecord),
    Suggestion(SuggestionText),
}

impl Report {
    fn path(&self) -> &'static str {
        match self {
            Report::Usage(_) => USAGE_PATH,
            Report::Suggestion(_) => SUGGESTIONS_PATH,
        }
    }

    fn name(&self) -> &'static str {
        match self {
            Report::Usage(_) => "usage",
            Report::Suggestion(_) => "suggestion",
        }
    }

    fn body(&self) -> Vec<u8> {
        match self {
            Report::Usage(usage) => serde_json::to_vec(usage),
            Report::Suggestion(text) => serde_json::to_vec(text),
        }
        .unwrap_or_default()
    }
}

/// Where the sessions hand their reports.
pub trait ReportSink: Send + Sync + fmt::Debug {
    fn report(&self, report: Report);
}

/// No server (development, the load test): reports are dropped.
#[derive(Debug, Default)]
pub struct NoReports;

impl ReportSink for NoReports {
    fn report(&self, _report: Report) {}
}

/// Keeps every report in memory (tests).
#[derive(Debug, Default, Clone)]
pub struct ReportRecorder(Arc<Mutex<Vec<Report>>>);

impl ReportRecorder {
    pub fn reports(&self) -> Vec<Report> {
        self.0.lock().map(|r| r.clone()).unwrap_or_default()
    }
}

impl ReportSink for ReportRecorder {
    fn report(&self, report: Report) {
        if let Ok(mut reports) = self.0.lock() {
            reports.push(report);
        }
    }
}

/// Posts reports to the server from a queue, a few at a time, retrying failures with backoff (5 tries; a 4xx other
/// than 408 and 429 is not retried). A full queue drops the newest report and says so in the log.
#[derive(Debug, Clone)]
pub struct Reporter {
    queue: mpsc::Sender<Report>,
}

impl Reporter {
    pub fn spawn(api: Arc<InternalApi>) -> Self {
        let (queue, mut reports) = mpsc::channel::<Report>(QUEUE);
        let slots = Arc::new(Semaphore::new(IN_FLIGHT));
        tokio::spawn(async move {
            while let Some(report) = reports.recv().await {
                let Ok(slot) = slots.clone().acquire_owned().await else {
                    return;
                };
                let api = api.clone();
                tokio::spawn(async move {
                    let _slot = slot;
                    deliver(&api, &report).await;
                });
            }
        });
        Reporter { queue }
    }
}

async fn deliver(api: &InternalApi, report: &Report) {
    let body = report.body();
    let mut wait = Duration::from_secs(1);
    for attempt in 1..=ATTEMPTS {
        match api.post(report.path(), body.clone()).await {
            Ok(()) => return,
            Err(InternalError::Status(status)) if (400..500).contains(&status) && status != 408 && status != 429 => {
                tracing::warn!(event = "report_refused", report = report.name(), status);
                return;
            }
            Err(error) if attempt == ATTEMPTS => {
                tracing::warn!(event = "report_dropped", report = report.name(), reason = %error, attempts = ATTEMPTS);
            }
            Err(_) => {
                tokio::time::sleep(wait).await;
                wait *= 2;
            }
        }
    }
}

impl ReportSink for Reporter {
    fn report(&self, report: Report) {
        if let Err(mpsc::error::TrySendError::Full(report)) = self.queue.try_send(report) {
            tracing::warn!(event = "report_queue_full", report = report.name());
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn signatures_follow_the_contract_and_expire_after_a_minute() {
        let secret = Secret::new("relay-secret-for-tests-only-0123456789");
        let body = br#"{"suggestion_id":"s1"}"#;
        let signature = sign(&secret, 1_800_000_000, "POST", USAGE_PATH, body);
        assert_eq!(signature.len(), 64);
        assert!(signature.bytes().all(|b| b.is_ascii_digit() || (b'a'..=b'f').contains(&b)));
        // The same bytes the server signs: timestamp, method, path and body, joined by newlines.
        let mut mac = Hmac::<Sha256>::new_from_slice(secret.expose()).unwrap();
        mac.update(b"1800000000\nPOST\n/api/internal/assist/usage\n{\"suggestion_id\":\"s1\"}");
        let expected: String = mac.finalize().into_bytes().iter().map(|b| format!("{b:02x}")).collect();
        assert_eq!(signature, expected);
        assert!(verify(&secret, 1_800_000_000, 1_800_000_060, "POST", USAGE_PATH, body, &signature));
        assert!(!verify(&secret, 1_800_000_000, 1_800_000_061, "POST", USAGE_PATH, body, &signature));
        assert!(!verify(&secret, 1_800_000_000, 1_800_000_000, "POST", USAGE_PATH, b"{}", &signature));
        assert!(!verify(&secret, 1_800_000_000, 1_800_000_000, "GET", USAGE_PATH, body, &signature));
        assert!(!verify(&secret, 1_800_000_000, 1_800_000_000, "POST", USAGE_PATH, body, "zz"));
    }

    /// A server that checks one secret, as the server does during a rotation.
    async fn server_checking(secret: &'static str) -> String {
        use axum::http::{HeaderMap, StatusCode};
        let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
        let addr = listener.local_addr().unwrap();
        let app = axum::Router::new().route(
            PROVIDERS_PATH,
            axum::routing::get(move |headers: HeaderMap| async move {
                let header = |name: &str| headers.get(name).and_then(|v| v.to_str().ok()).unwrap_or_default().to_owned();
                let timestamp = header(TIMESTAMP_HEADER).parse().unwrap_or(0);
                let ok = verify(&Secret::new(secret), timestamp, unix_now(), "GET", PROVIDERS_PATH, b"", &header(SIGNATURE_HEADER));
                if ok { (StatusCode::OK, r#"{"keys":{}}"#) } else { (StatusCode::UNAUTHORIZED, "{}") }
            }),
        );
        tokio::spawn(async move { axum::serve(listener, app).await.unwrap() });
        format!("http://{addr}")
    }

    #[tokio::test]
    async fn during_a_rotation_requests_follow_the_secret_the_server_checks() {
        const NEW: &str = "new-relay-secret-for-tests-0123456789-abc";
        const OLD: &str = "old-relay-secret-for-tests-0123456789-abc";
        // The relay already has both (the new one current); the server still checks the old one.
        let api = InternalApi::new(&server_checking(OLD).await, vec![Secret::new(NEW), Secret::new(OLD)]).unwrap();
        assert!(api.providers().await.is_ok(), "the old secret is tried after a 401");
        assert_eq!(api.signing.load(Ordering::Relaxed), 1, "and kept");
        // The server switches: the relay follows on the next request.
        let api = InternalApi { base: server_checking(NEW).await, ..api };
        assert!(api.providers().await.is_ok());
        assert_eq!(api.signing.load(Ordering::Relaxed), 0);
        // One secret only, and the wrong one: refused.
        let api = InternalApi::new(&server_checking(OLD).await, vec![Secret::new(NEW)]).unwrap();
        assert!(matches!(api.providers().await, Err(InternalError::Status(401))));
    }

    #[test]
    fn provider_keys_never_show_in_debug() {
        let answer: ProvidersResponse = serde_json::from_str(r#"{"keys":{"anthropic":"KEY-VALUE-1","groq":"KEY-VALUE-2"}}"#).unwrap();
        let keys = ProviderKeys::default();
        keys.replace(answer.keys.clone());
        let shown = format!("{answer:?} {keys:?}");
        assert!(!shown.contains("KEY-VALUE"), "{shown}");
        assert_eq!(keys.count(), 2);
        assert_eq!(keys.get(ProviderKind::Groq).map(|k| k.expose().to_vec()), Some(b"KEY-VALUE-2".to_vec()));
    }
}
