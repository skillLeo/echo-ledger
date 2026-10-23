//! Wire types for docs/API.md. Library items keep every server field (flattened) for the UI.

use std::collections::HashMap;

use serde::{Deserialize, Serialize};

#[derive(Clone, Debug, Default, Serialize, Deserialize)]
pub struct DeviceDto {
    pub id: String,
    pub name: String,
    pub platform: String,
    pub source_device: String,
}

#[derive(Clone, Debug, Default, Serialize, Deserialize)]
pub struct UploadConfig {
    pub chunk_size_bytes: u64,
    pub max_recording_bytes: u64,
    #[serde(default)]
    pub accepted_mimes: Vec<String>,
}

#[derive(Clone, Debug, Default, Serialize, Deserialize)]
pub struct ProviderConfig {
    pub provider: String,
    pub mode: String,
    #[serde(default)]
    pub required_artifacts: Option<Vec<String>>,
    #[serde(default)]
    pub preferred_primary: Option<String>,
}

#[derive(Clone, Debug, Default, Serialize, Deserialize)]
pub struct CaptionsConfig {
    pub channels: String,
}

#[derive(Clone, Debug, Default, Serialize, Deserialize)]
pub struct ServerConfig {
    pub device: DeviceDto,
    pub server_time: String,
    pub retention_days: u32,
    pub upload: UploadConfig,
    #[serde(default)]
    pub providers: HashMap<String, ProviderConfig>,
    #[serde(default)]
    pub captions: CaptionsConfig,
    #[serde(default)]
    pub pins: Vec<String>,
}

impl ServerConfig {
    pub fn required_artifacts(&self, use_case: &str) -> Vec<String> {
        self.providers
            .get(use_case)
            .and_then(|p| p.required_artifacts.clone())
            .unwrap_or_else(|| vec!["primary".to_string()])
    }
}

#[derive(Clone, Debug, Deserialize)]
pub struct RegisterResponse {
    pub device: DeviceDto,
    pub token: String,
    pub token_expires_at: Option<String>,
    pub config: ServerConfig,
}

#[derive(Clone, Debug, Default, Serialize, Deserialize)]
pub struct AudioSpec {
    pub encoding: String,
    pub sample_rate: u32,
    pub channels: u16,
    pub frame_ms: u32,
}

/// One streaming credential (docs/API.md "POST /api/stt/token"). Contains short-lived tokens only.
#[derive(Clone, Debug, Default, Serialize, Deserialize)]
pub struct Grant {
    pub channel: String,
    pub transport: String,
    #[serde(default)]
    pub protocol: Option<String>,
    #[serde(default)]
    pub ws_url: Option<String>,
    #[serde(default)]
    pub max_session_seconds: Option<u64>,
    #[serde(default)]
    pub audio: Option<AudioSpec>,
    #[serde(default)]
    pub init_messages: Vec<serde_json::Value>,
}

#[derive(Clone, Debug, Default, Serialize, Deserialize)]
pub struct SttTokenResponse {
    pub provider: Option<String>,
    pub mode: String,
    pub expires_at: Option<String>,
    #[serde(default)]
    pub keyterms: Vec<String>,
    #[serde(default)]
    pub grants: Vec<Grant>,
}

#[derive(Clone, Debug, Serialize)]
pub struct ArtifactDto {
    pub role: String,
    pub mime: String,
    pub codec: Option<String>,
    pub channels: u16,
    pub sample_rate: Option<u32>,
    pub size_bytes: u64,
    pub sha256: String,
    pub duration_ms: Option<u64>,
}

#[derive(Clone, Debug, Serialize)]
pub struct CreateSessionRequest {
    pub client_ref: String,
    pub source_app: String,
    pub client_id: Option<String>,
    pub title: Option<String>,
    pub started_at: String,
    pub ended_at: Option<String>,
    pub duration_ms: Option<u64>,
    pub language: Option<String>,
    pub artifacts: Vec<ArtifactDto>,
}

#[derive(Clone, Debug, Default, Serialize, Deserialize)]
pub struct UploadState {
    pub chunk_size_bytes: u64,
    pub total_chunks: u64,
    #[serde(default)]
    pub received_chunks: Vec<u64>,
    pub status: String,
}

#[derive(Clone, Debug, Default, Serialize, Deserialize)]
pub struct RecordingUpload {
    pub id: String,
    pub role: String,
    pub upload: UploadState,
}

#[derive(Clone, Debug, Deserialize)]
pub struct CreateSessionResponse {
    pub session: LibraryItem,
    pub recordings: Vec<RecordingUpload>,
    #[serde(default)]
    pub required_artifacts: Vec<String>,
}

/// Library item; `deleted == true` is a sync tombstone. Other fields pass through to the UI untouched.
#[derive(Clone, Debug, Serialize, Deserialize)]
pub struct LibraryItem {
    pub id: String,
    #[serde(default)]
    pub deleted: bool,
    pub updated_at: String,
    #[serde(flatten)]
    pub rest: serde_json::Map<String, serde_json::Value>,
}

#[derive(Clone, Debug, Deserialize)]
pub struct LibraryResponse {
    pub data: Vec<LibraryItem>,
    pub next_cursor: Option<String>,
    pub server_time: String,
}

#[derive(Clone, Debug, Serialize, Deserialize)]
pub struct SegmentDto {
    pub channel: u8,
    pub speaker: String,
    pub start_ms: u64,
    pub end_ms: u64,
    pub text: String,
    #[serde(default)]
    pub words: Vec<serde_json::Value>,
}

#[derive(Clone, Debug, Serialize)]
pub struct DeviceTranscript {
    pub kind: String,
    pub provider: String,
    pub language: Option<String>,
    pub segments: Vec<SegmentDto>,
}
