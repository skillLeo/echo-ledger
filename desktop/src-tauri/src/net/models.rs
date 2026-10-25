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
    /// `"1.1"` on v1.1 servers, absent on v1 servers (docs/API.md "Conventions").
    #[serde(default, deserialize_with = "lenient")]
    pub api_version: Option<String>,
    /// This month's usage and limit (since v1.1). A malformed block never breaks the config.
    #[serde(default, deserialize_with = "lenient")]
    pub usage: Option<UsageDto>,
    /// Bookmark tag presets (since v1.1).
    #[serde(default, deserialize_with = "lenient")]
    pub markers: Option<MarkersConfig>,
}

/// `config.markers` (docs/API.md "GET /api/config").
#[derive(Clone, Debug, Default, PartialEq, Eq, Serialize, Deserialize)]
pub struct MarkersConfig {
    #[serde(default)]
    pub tags: Vec<String>,
}

impl ServerConfig {
    pub fn required_artifacts(&self, use_case: &str) -> Vec<String> {
        self.providers
            .get(use_case)
            .and_then(|p| p.required_artifacts.clone())
            .unwrap_or_else(|| vec!["primary".to_string()])
    }

    /// True when the server speaks the v1.1 contract (open sessions, `session_id` on `stt/token`, `POST /api/usage`).
    /// Absent `api_version` means a v1 server: the app then uses the v1 flows.
    pub fn is_v1_1(&self) -> bool {
        let Some(version) = self.api_version.as_deref() else { return false };
        let mut parts = version.trim().split('.').map(|p| p.parse::<u32>());
        match (parts.next(), parts.next()) {
            (Some(Ok(major)), Some(Ok(minor))) => (major, minor) >= (1, 1),
            (Some(Ok(major)), None) => major >= 2,
            _ => false,
        }
    }
}

/// Deserialises an optional value, turning a value of the wrong shape into `None` instead of failing the parent.
fn lenient<'de, D, T>(deserializer: D) -> Result<Option<T>, D::Error>
where
    D: serde::Deserializer<'de>,
    T: serde::de::DeserializeOwned,
{
    let value = Option::<serde_json::Value>::deserialize(deserializer)?;
    Ok(value.and_then(|v| serde_json::from_value(v).ok()))
}

/// Monthly limit state shown on every screen (`AppStatus.budget`).
#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum BudgetState {
    Ok,
    Warn,
    Reached,
}

impl BudgetState {
    /// From the last `config.usage`; `blocked` (a `403 budget_exceeded` since that config) forces `Reached`.
    pub fn from_usage(usage: Option<&UsageDto>, blocked: bool) -> Self {
        match usage {
            _ if blocked => Self::Reached,
            Some(u) if u.limit_reached => Self::Reached,
            Some(u) if u.warn => Self::Warn,
            _ => Self::Ok,
        }
    }
}

/// `config.usage` (docs/API.md "GET /api/config", since v1.1).
#[derive(Clone, Debug, Default, PartialEq, Serialize, Deserialize)]
#[serde(default)]
pub struct UsageDto {
    pub month: String,
    pub time_zone: String,
    pub period_start: String,
    pub period_end: String,
    pub minutes: f64,
    pub cost_usd: f64,
    pub limit_minutes: Option<u64>,
    pub limit_usd: Option<f64>,
    pub warn_at_percent: u8,
    pub warn: bool,
    pub limit_reached: bool,
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

/// A bookmark on the recording's timeline (since v1.1): recorded time, optional preset tag and short note.
#[derive(Clone, Debug, Default, PartialEq, Eq, Serialize, Deserialize)]
pub struct MarkerDto {
    pub at_ms: u64,
    #[serde(default)]
    pub tag: Option<String>,
    #[serde(default)]
    pub note: Option<String>,
}

/// A paused stretch (since v1.1): at recorded time `at_ms` the recording paused for `paused_ms` of wall time.
#[derive(Clone, Copy, Debug, Default, PartialEq, Eq, Serialize, Deserialize)]
pub struct PauseDto {
    pub at_ms: u64,
    pub paused_ms: u64,
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
    /// Sent only when there are some, so a v1 server never sees the keys.
    #[serde(skip_serializing_if = "Vec::is_empty")]
    pub markers: Vec<MarkerDto>,
    #[serde(skip_serializing_if = "Vec::is_empty")]
    pub pauses: Vec<PauseDto>,
}

/// Open session (since v1.1): `POST /api/sessions` without the `artifacts` key, status `recording`. Created only
/// when live captions need a token.
#[derive(Clone, Debug, Serialize)]
pub struct OpenSessionRequest {
    pub client_ref: String,
    pub source_app: String,
    pub client_id: Option<String>,
    pub title: Option<String>,
    pub started_at: String,
    pub language: Option<String>,
}

/// `POST /api/sessions/{id}/finish` (since v1.1). `client_id` is sent only when `Some`: a present key (even
/// `null`) replaces the stored client, an absent key keeps it.
#[derive(Clone, Debug, Serialize)]
pub struct FinishSessionRequest {
    pub ended_at: String,
    pub duration_ms: u64,
    pub title: Option<String>,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub client_id: Option<Option<String>>,
    pub artifacts: Vec<ArtifactDto>,
    #[serde(skip_serializing_if = "Vec::is_empty")]
    pub markers: Vec<MarkerDto>,
    #[serde(skip_serializing_if = "Vec::is_empty")]
    pub pauses: Vec<PauseDto>,
}

/// One streaming period (socket open to close) for `POST /api/usage` (since v1.1); queued in `usage.json`.
#[derive(Clone, Debug, Default, PartialEq, Serialize, Deserialize)]
pub struct UsageReport {
    /// UUID v4 generated on the device; the server stores each id once.
    pub report_id: String,
    /// `live_captions` | `dictation`
    pub use_case: String,
    pub provider: String,
    #[serde(default)]
    pub session_id: Option<String>,
    pub streamed_ms: u64,
    /// Provider sockets in the period, 1-2.
    pub channels: u8,
    pub ended_at: String,
}

/// The stored report as the server returns it (`201` new, `200` with `duplicate: true` for a repeat).
#[derive(Clone, Debug, Default, Serialize, Deserialize)]
#[serde(default)]
pub struct UsageReceipt {
    pub id: Option<String>,
    pub report_id: String,
    pub use_case: String,
    pub provider: String,
    pub session_id: Option<String>,
    pub streamed_ms: u64,
    pub channels: u8,
    pub billed_ms: Option<u64>,
    pub cost_usd: Option<f64>,
    pub occurred_at: Option<String>,
    pub duplicate: bool,
}

#[derive(Clone, Debug, Default, Serialize, Deserialize)]
pub struct UsageAnswer {
    #[serde(default)]
    pub report: UsageReceipt,
    #[serde(default, deserialize_with = "lenient")]
    pub usage: Option<UsageDto>,
}

/// `202` answer of `POST /api/recordings/{id}/complete`: `assembling` or `ready`.
#[derive(Clone, Debug, Default, Serialize, Deserialize)]
pub struct CompleteResponse {
    #[serde(default)]
    pub status: String,
}

#[derive(Clone, Debug, Default, Serialize, Deserialize)]
pub struct UploadState {
    pub chunk_size_bytes: u64,
    pub total_chunks: u64,
    #[serde(default)]
    pub received_chunks: Vec<u64>,
    pub status: String,
    /// A readable reason when `status` is `failed` (since v1.1), otherwise `null`.
    #[serde(default)]
    pub error: Option<String>,
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
