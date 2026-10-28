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
}

impl ApiClient {
    pub fn new(base_url: &str, pins: &[String], token: Option<String>) -> AppResult<Self> {
        let base = base_url.trim().trim_end_matches('/').to_string();
        if !(base.starts_with("https://") || super::pinning::is_local_dev(&base)) {
            return Err(AppError::msg("The server URL must start with https:// (http is only allowed for localhost)."));
        }
        let http = build_http_client(&base, pins).map_err(AppError::Message)?;
        Ok(Self { base, http, token })
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
        let status = resp.status().as_u16();
        if status == 401 {
            return AppError::NotPaired;
        }
        let retry_after_secs = resp
            .headers()
            .get(reqwest::header::RETRY_AFTER)
            .and_then(|v| v.to_str().ok())
            .and_then(|v| v.trim().parse::<u64>().ok());
        let body: serde_json::Value = resp.json().await.unwrap_or_default();
        let message = body.get("message").and_then(|m| m.as_str()).unwrap_or("request failed").to_string();
        let code = body.get("code").and_then(|c| c.as_str()).map(str::to_string);
        AppError::Server { status, message, code, retry_after_secs }
    }

    pub async fn register(&self, code: &str, name: &str, platform: &str, os_version: &str) -> AppResult<RegisterResponse> {
        let resp = self
            .http
            .post(self.url("devices/register"))
            .header("Accept", "application/json")
            .json(&json!({ "pairing_code": code, "name": name, "platform": platform, "app_version": env!("CARGO_PKG_VERSION"), "os_version": os_version }))
            .send()
            .await?;
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

    /// One-shot create (with artifacts) of docs/API.md "POST /api/sessions".
    pub async fn create_session(&self, req: &CreateSessionRequest) -> AppResult<CreateSessionResponse> {
        let resp = self.authed(self.http.post(self.url("sessions")))?.json(req).send().await?;
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

    /// `GET /api/transcripts/{id}/export`: the file and the name the server gives it.
    pub async fn export_transcript(&self, id: &str, format: &str, timestamps: bool, speakers: bool) -> AppResult<(Vec<u8>, String)> {
        let query = [("format", format.to_string()), ("timestamps", u8::from(timestamps).to_string()), ("speakers", u8::from(speakers).to_string())];
        let resp = self.authed(self.http.get(self.url(&format!("transcripts/{id}/export"))).query(&query))?.send().await?;
        if !resp.status().is_success() {
            return Err(Self::error(resp).await);
        }
        let disposition = resp.headers().get(reqwest::header::CONTENT_DISPOSITION).and_then(|v| v.to_str().ok()).unwrap_or_default().to_string();
        let bytes = resp.bytes().await?.to_vec();
        Ok((bytes, filename_from_disposition(&disposition).unwrap_or_else(|| format!("transcript.{format}"))))
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

    /// `PATCH /api/clients/{id}` (since v1.1): rename, colour, avatar or pin a client folder.
    pub async fn update_client(&self, id: &str, body: &serde_json::Value) -> AppResult<serde_json::Value> {
        let resp = self.authed(self.http.patch(self.url(&format!("clients/{id}"))))?.json(body).send().await?;
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

    pub async fn delete_session(&self, id: &str) -> AppResult<()> {
        let resp = self.authed(self.http.delete(self.url(&format!("sessions/{id}"))))?.send().await?;
        Self::ok_empty(resp).await
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
