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
