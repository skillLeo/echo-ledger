//! Typed client for docs/API.md. All requests go through the pinned reqwest client.

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
        let http = build_http_client(&base, pins).map_err(AppError::Network)?;
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
        let status = resp.status();
        if status.is_success() {
            return Ok(resp.json::<T>().await?);
        }
        if status.as_u16() == 401 {
            return Err(AppError::NotPaired);
        }
        let body: serde_json::Value = resp.json().await.unwrap_or_default();
        let message = body.get("message").and_then(|m| m.as_str()).unwrap_or("request failed").to_string();
        Err(AppError::Server { status: status.as_u16(), message })
    }

    async fn ok_empty(resp: reqwest::Response) -> AppResult<()> {
        let status = resp.status();
        if status.is_success() {
            return Ok(());
        }
        if status.as_u16() == 401 {
            return Err(AppError::NotPaired);
        }
        let body: serde_json::Value = resp.json().await.unwrap_or_default();
        Err(AppError::Server { status: status.as_u16(), message: body["message"].as_str().unwrap_or("request failed").to_string() })
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

    pub async fn stt_token(&self, use_case: &str, channels: &[&str], client_id: Option<&str>) -> AppResult<SttTokenResponse> {
        let resp = self
            .authed(self.http.post(self.url("stt/token")))?
            .json(&json!({ "use_case": use_case, "channels": channels, "client_id": client_id, "sample_rate": 16000 }))
            .send()
            .await?;
        Self::parse(resp).await
    }

    pub async fn create_session(&self, req: &CreateSessionRequest) -> AppResult<CreateSessionResponse> {
        let resp = self.authed(self.http.post(self.url("sessions")))?.json(req).send().await?;
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
        Ok(UploadState { chunk_size_bytes: 0, total_chunks: r.total_chunks, received_chunks: r.received_chunks, status: r.status })
    }

    pub async fn complete(&self, recording_id: &str) -> AppResult<()> {
        let resp = self.authed(self.http.post(self.url(&format!("recordings/{recording_id}/complete"))))?.send().await?;
        Self::ok_empty(resp).await
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

    pub async fn delete_session(&self, id: &str) -> AppResult<()> {
        let resp = self.authed(self.http.delete(self.url(&format!("sessions/{id}"))))?.send().await?;
        Self::ok_empty(resp).await
    }
}
