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
    /// How unsure words appear in copied text (since v1.3, plan A4).
    #[serde(default, deserialize_with = "lenient")]
    pub unsure: Option<UnsureConfig>,
    /// The workspace's plan and its features (since v1.5).
    #[serde(default, deserialize_with = "lenient")]
    pub plan: Option<PlanDto>,
    /// The device's workspace and its person's role there (since v1.5).
    #[serde(default, deserialize_with = "lenient")]
    pub workspace: Option<WorkspaceDto>,
    /// This version is below the minimum the admin set (since v1.6, P2.7); `null` when it is not.
    #[serde(default, deserialize_with = "lenient")]
    pub app_update: Option<AppUpdateDto>,
    /// A banner the admin put up for everyone (since v1.6, P2.7).
    #[serde(default, deserialize_with = "lenient")]
    pub announcement: Option<AnnouncementDto>,
    /// The server is in maintenance (since v1.6, P2.7): uploads wait.
    #[serde(default, deserialize_with = "lenient")]
    pub maintenance: Option<MaintenanceDto>,
}

/// `config.app_update` (docs/API.md "Updates, announcements and maintenance"). `refused`: the server answers this
/// version's other requests with `426 update_required` until it is updated.
#[derive(Clone, Debug, Default, PartialEq, Serialize, Deserialize)]
pub struct AppUpdateDto {
    #[serde(default)]
    pub minimum: String,
    #[serde(default)]
    pub required: bool,
    #[serde(default)]
    pub refused: bool,
    #[serde(default)]
    pub message: String,
    #[serde(default)]
    pub url: Option<String>,
}

/// `config.announcement`: `level` is `info` or `warning`; the banner stays until `until` (none: until it is taken down).
#[derive(Clone, Debug, Default, PartialEq, Serialize, Deserialize)]
pub struct AnnouncementDto {
    pub text: String,
    #[serde(default = "AnnouncementDto::default_level")]
    pub level: String,
    #[serde(default)]
    pub until: Option<String>,
}

impl AnnouncementDto {
    fn default_level() -> String {
        "info".into()
    }
}

/// `config.maintenance`: the server's message and when it should be back (none: not said).
#[derive(Clone, Debug, Default, PartialEq, Serialize, Deserialize)]
pub struct MaintenanceDto {
    #[serde(default)]
    pub message: String,
    #[serde(default)]
    pub until: Option<String>,
}

/// `config.unsure` (docs/API.md "GET /api/config"): `[?word]` (brackets), `word(?)` (suffix) or plain (off) in copies,
/// the confidence below which a word is unsure, and whether copies start with the note line.
#[derive(Clone, Debug, PartialEq, Serialize, Deserialize)]
pub struct UnsureConfig {
    #[serde(default = "UnsureConfig::default_style")]
    pub style: String,
    #[serde(default = "UnsureConfig::default_threshold")]
    pub threshold: f64,
    #[serde(default = "UnsureConfig::default_note")]
    pub note: bool,
    #[serde(default)]
    pub note_text: String,
}

impl UnsureConfig {
    fn default_style() -> String {
        "brackets".into()
    }
    fn default_threshold() -> f64 {
        0.5
    }
    fn default_note() -> bool {
        true
    }
}

impl Default for UnsureConfig {
    fn default() -> Self {
        Self {
            style: Self::default_style(),
            threshold: Self::default_threshold(),
            note: Self::default_note(),
            note_text: "Note: [?word] = the transcription is unsure about this word; [unclear] = inaudible audio.".into(),
        }
    }
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

    /// Whether the workspace's plan includes screen moments (since v1.5; a feature missing from the plan is on). `None`
    /// from a server without plans.
    pub fn screens_in_plan(&self) -> Option<bool> {
        self.plan.as_ref().map(|p| p.features.get("screen_moments") != Some(&false))
    }

    /// Whether the plan has `feature` (`live_translate`, `screen_moments`, `ai_notes`, `connector`): a server without
    /// plans, or a plan that does not name the feature, leaves it on.
    pub fn has_feature(&self, feature: &str) -> bool {
        self.plan.as_ref().is_none_or(|p| p.features.get(feature) != Some(&false))
    }
}

/// `config.plan` (since v1.5): the features (one missing from the map is on), the limits (`null` = no limit) and what
/// the workspace used. A malformed limits or used block is dropped on its own, never the features.
#[derive(Clone, Debug, Default, PartialEq, Serialize, Deserialize)]
pub struct PlanDto {
    #[serde(default)]
    pub key: Option<String>,
    #[serde(default)]
    pub name: Option<String>,
    #[serde(default)]
    pub features: HashMap<String, bool>,
    #[serde(default, deserialize_with = "lenient")]
    pub limits: Option<PlanLimits>,
    #[serde(default, deserialize_with = "lenient")]
    pub used: Option<PlanUsed>,
}

/// `config.plan.limits` (since v1.5); `None` is no limit.
#[derive(Clone, Debug, Default, PartialEq, Serialize, Deserialize)]
pub struct PlanLimits {
    #[serde(default)]
    pub monthly_minutes: Option<f64>,
    #[serde(default)]
    pub storage_bytes: Option<u64>,
    #[serde(default)]
    pub retention_days: Option<u32>,
    #[serde(default)]
    pub seats: Option<u32>,
}

/// `config.plan.used` (since v1.5): this month's minutes and the storage the workspace holds.
#[derive(Clone, Debug, Default, PartialEq, Serialize, Deserialize)]
pub struct PlanUsed {
    #[serde(default)]
    pub minutes: Option<f64>,
    #[serde(default)]
    pub storage_bytes: Option<u64>,
}

/// `config.workspace` (since v1.5): the device's workspace and its person's role there (`owner`, `admin`, `member`
/// or `viewer`).
#[derive(Clone, Debug, Default, PartialEq, Serialize, Deserialize)]
pub struct WorkspaceDto {
    #[serde(default)]
    pub name: Option<String>,
    #[serde(default)]
    pub role: Option<String>,
}

/// The fields of `POST /api/sessions/{id}/screens` beside the image (since v1.5).
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct ScreenUpload {
    pub at_ms: u64,
    pub width: u32,
    pub height: u32,
    pub trigger: String,
    pub sha256: String,
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

/// What `POST /api/auth/password` sends besides the platform and versions (since v1.5).
#[derive(Clone, Copy, Debug)]
pub struct PasswordSignIn<'a> {
    pub email: &'a str,
    pub password: &'a str,
    pub otp: Option<&'a str>,
    pub name: &'a str,
}

/// The answer to a password sign-in: this computer's token (as from pairing), or a request for the two-factor code.
#[derive(Clone, Debug)]
pub enum PasswordAnswer {
    SignedIn(Box<RegisterResponse>),
    OtpRequired,
}

/// `GET /api/account` (since v1.5), handed to the account page as the server wrote it.
#[derive(Clone, Debug, Default, PartialEq, Serialize, Deserialize)]
pub struct AccountDto {
    #[serde(default)]
    pub name: String,
    #[serde(default)]
    pub email: String,
    #[serde(default)]
    pub email_verified: bool,
    #[serde(default)]
    pub two_factor: bool,
    #[serde(default)]
    pub google: bool,
    #[serde(default)]
    pub created_at: Option<String>,
    #[serde(default, deserialize_with = "lenient")]
    pub workspace: Option<AccountWorkspace>,
    #[serde(default)]
    pub deletes_at: Option<String>,
    /// The server's operator (v1.6 shell): the avatar menu then offers the console's admin panel. Missing (a server
    /// that does not say) is false, and the menu leaves it out.
    #[serde(default, alias = "is_super_admin", deserialize_with = "lenient_bool")]
    pub super_admin: bool,
    /// Every workspace the person belongs to (v1.6 shell, for the switcher); empty when the server does not list them.
    #[serde(default, deserialize_with = "lenient_list")]
    pub workspaces: Vec<AccountWorkspaceEntry>,
}

/// One of the person's workspaces in `account.workspaces` (v1.6 shell).
#[derive(Clone, Debug, Default, PartialEq, Serialize, Deserialize)]
pub struct AccountWorkspaceEntry {
    #[serde(default)]
    pub id: serde_json::Value,
    #[serde(default)]
    pub name: String,
    #[serde(default)]
    pub role: Option<String>,
    /// The one this device works in.
    #[serde(default)]
    pub current: Option<bool>,
}

/// A flag that is false unless the server sends `true` (anything else, `null` included, is false).
fn lenient_bool<'de, D>(deserializer: D) -> Result<bool, D::Error>
where
    D: serde::Deserializer<'de>,
{
    Ok(matches!(Option::<serde_json::Value>::deserialize(deserializer)?, Some(serde_json::Value::Bool(true))))
}

/// `account.workspace`: the device's workspace and the person's role in it.
#[derive(Clone, Debug, Default, PartialEq, Serialize, Deserialize)]
pub struct AccountWorkspace {
    #[serde(default)]
    pub id: serde_json::Value,
    #[serde(default)]
    pub name: String,
    #[serde(default)]
    pub role: String,
}

/// `POST /api/account/export` and `GET /api/account/export/{id}` (since v1.5).
#[derive(Clone, Debug, Default, PartialEq, Serialize, Deserialize)]
pub struct AccountExport {
    pub id: String,
    pub status: String,
    #[serde(default)]
    pub url: Option<String>,
    #[serde(default)]
    pub expires_at: Option<String>,
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
/// The language chip changed during the recording (since v1.2): the recording time and the new language (none =
/// Auto). The server cuts the audio at these times and transcribes each piece in its language.
#[derive(Clone, Debug, Default, PartialEq, Eq, Serialize, Deserialize)]
pub struct LanguageMarkDto {
    pub at_ms: u64,
    pub language: Option<String>,
}

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
    #[serde(skip_serializing_if = "Vec::is_empty")]
    pub language_marks: Vec<LanguageMarkDto>,
    /// Who the recording was with, when this computer saw it (since v1.2: a meeting window's title).
    #[serde(skip_serializing_if = "Option::is_none")]
    pub caller: Option<CallerDto>,
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
    /// The Keep card's language (since v1.1.2), always sent: null is automatic (the client's or the console default).
    pub language: Option<String>,
    pub artifacts: Vec<ArtifactDto>,
    #[serde(skip_serializing_if = "Vec::is_empty")]
    pub markers: Vec<MarkerDto>,
    #[serde(skip_serializing_if = "Vec::is_empty")]
    pub pauses: Vec<PauseDto>,
    #[serde(skip_serializing_if = "Vec::is_empty")]
    pub language_marks: Vec<LanguageMarkDto>,
    /// Who the recording was with, when this computer saw it (since v1.2: a meeting window's title).
    #[serde(skip_serializing_if = "Option::is_none")]
    pub caller: Option<CallerDto>,
}

/// Caller names (since v1.2): who a recording was with and where the name came from (`window_title` here).
#[derive(Clone, Debug, Default, PartialEq, Serialize, Deserialize)]
pub struct CallerDto {
    #[serde(default)]
    pub name: Option<String>,
    #[serde(default)]
    pub number: Option<String>,
    #[serde(default)]
    pub source: Option<String>,
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
    /// Word timings as the server sent them (with `confidence` 0-1 when the engine scored the word).
    #[serde(default)]
    pub words: Vec<serde_json::Value>,
    /// The engine was not sure of this line (since v1.2); this app never sends it.
    #[serde(default, skip_serializing_if = "std::ops::Not::not")]
    pub uncertain: bool,
    /// Double-check: what a second engine heard on this line when it disagreed (since v1.2); never sent.
    #[serde(default, skip_serializing_if = "Option::is_none")]
    pub check: Option<serde_json::Value>,
}

#[derive(Clone, Debug, Serialize)]
pub struct DeviceTranscript {
    pub kind: String,
    pub provider: String,
    pub language: Option<String>,
    pub segments: Vec<SegmentDto>,
}

/// `POST /api/devices/report` (since v1.5): what a device check found, sent only when the person chose to.
#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
pub struct DeviceReport {
    pub platform: &'static str,
    pub app_version: String,
    pub brand: &'static str,
    pub model: String,
    pub os_version: String,
    pub checks: Vec<DeviceReportCheck>,
}

#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
pub struct DeviceReportCheck {
    pub key: &'static str,
    pub state: crate::mac_check::CheckState,
    pub detail: Option<String>,
}

impl DeviceReport {
    /// The Mac check's report in the server's form, cut to its limits (`brand`, `model`, `os_version` 64 characters,
    /// `app_version` 32; the details already fit).
    pub fn of_mac_check(report: &crate::mac_check::CheckReport) -> Self {
        let cut = |text: &str, most: usize| text.chars().take(most).collect::<String>();
        Self {
            platform: "macos",
            app_version: cut(&report.app_version, 32),
            brand: "Apple",
            model: cut(&report.model, 64),
            os_version: cut(&report.os_version, 64),
            checks: report.items.iter().map(|i| DeviceReportCheck { key: i.key, state: i.state, detail: Some(i.detail.clone()) }).collect(),
        }
    }
}

#[derive(Clone, Debug, Deserialize)]
pub struct DeviceReportAnswer {
    pub data: DeviceReportReceipt,
}

#[derive(Clone, Debug, Deserialize)]
pub struct DeviceReportReceipt {
    pub reported_at: String,
}

/// `GET /api/auth/options` (Phase 1C): what a sign-in screen offers on this server. Every field is optional, so a server
/// that leaves one out reads as before (email and password, no sign-up page, no reset link).
#[derive(Clone, Debug, Default, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct AuthOptions {
    #[serde(default = "yes", alias = "password")]
    pub password: bool,
    #[serde(default)]
    pub google: bool,
    #[serde(default, alias = "sign_up")]
    pub sign_up: bool,
    #[serde(default, alias = "sign_up_url")]
    pub sign_up_url: Option<String>,
    #[serde(default, alias = "reset_url")]
    pub reset_url: Option<String>,
    #[serde(default, alias = "browser_sign_in")]
    pub browser_sign_in: bool,
    /// Email codes are offered (since v1.6).
    #[serde(default)]
    pub code: bool,
}

fn yes() -> bool {
    true
}

// ---- Sign-up and sign-in with an email code (since v1.6, P1) -------------------------------------------------------

/// `POST /api/auth/start`: whether the email has an account and what it can sign in with. `account` is `existing`,
/// `new`, or (made by the app, never sent) `older` for a server without the route.
#[derive(Clone, Debug, Default, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct AuthStart {
    #[serde(default)]
    pub account: String,
    #[serde(default)]
    pub methods: Vec<String>,
    #[serde(default, alias = "sign_up")]
    pub sign_up: bool,
}

/// `POST /api/auth/code`: a code is on its way (`sent`), or one went out a moment ago and a new one may be asked for in
/// `resend_after` seconds (`429 resend_too_soon`).
#[derive(Clone, Debug, Default, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct CodeSent {
    #[serde(default = "sixty", alias = "resend_after")]
    pub resend_after: u64,
    #[serde(default, alias = "expires_in")]
    pub expires_in: Option<u64>,
    #[serde(default = "yes")]
    pub sent: bool,
}

fn sixty() -> u64 {
    60
}

/// The answer to `POST /api/auth/code/verify`: this computer's token (an existing account), the two-factor code first,
/// or a new email's sign-up token (kept in the core, never shown to the page).
#[derive(Clone, Debug)]
pub enum CodeAnswer {
    SignedIn(Box<RegisterResponse>),
    OtpRequired,
    Profile { sign_up_token: String },
}

/// What a sign-in route sends about this computer besides its own fields (since v1.6 with `install_id`).
#[derive(Clone, Debug, Default)]
pub struct ThisDevice {
    pub name: String,
    pub platform: &'static str,
    pub os_version: String,
}

// ---- Entitlements, the transcription key and upgrade requests (since v1.6, P1) -------------------------------------

/// `GET /api/me/entitlements`, as the windows read it (snake_case, like the server). Each part is read on its own: a
/// malformed one is left out, never the rest. Colours are `#rrggbb` only; a feature or limit the app does not know is
/// kept (the server alone decides). The features keep the order the server sent: its registry's, which Plan & usage
/// lists them in.
#[derive(Clone, Debug, Default, PartialEq, Serialize, Deserialize)]
pub struct Entitlements {
    #[serde(default, deserialize_with = "lenient")]
    pub plan: Option<EntitlementPlan>,
    #[serde(default)]
    pub features: Features,
    #[serde(default, deserialize_with = "lenient_map")]
    pub limits: std::collections::BTreeMap<String, LimitEntitlement>,
    #[serde(default, deserialize_with = "lenient_list")]
    pub engines: Vec<String>,
    #[serde(default, deserialize_with = "lenient")]
    pub transcription_key: Option<TranscriptionKeyState>,
    #[serde(default, deserialize_with = "lenient")]
    pub upgrade_request: Option<UpgradeRequest>,
    #[serde(default, deserialize_with = "lenient_list")]
    pub plans: Vec<OfferedPlan>,
}

#[derive(Clone, Debug, Default, PartialEq, Serialize, Deserialize)]
pub struct EntitlementPlan {
    #[serde(default)]
    pub key: String,
    #[serde(default)]
    pub name: String,
    #[serde(default, deserialize_with = "lenient")]
    pub badge: Option<String>,
    #[serde(default, deserialize_with = "lenient")]
    pub color: Option<String>,
    #[serde(default, deserialize_with = "lenient")]
    pub expires_at: Option<String>,
    #[serde(default, deserialize_with = "lenient")]
    pub trial_minutes_left: Option<f64>,
}

#[derive(Clone, Debug, Default, PartialEq, Serialize, Deserialize)]
pub struct FeatureEntitlement {
    #[serde(default)]
    pub enabled: bool,
    #[serde(default, deserialize_with = "lenient")]
    pub name: Option<String>,
    #[serde(default, deserialize_with = "lenient")]
    pub description: Option<String>,
    #[serde(default, deserialize_with = "lenient")]
    pub cost: Option<String>,
    #[serde(default, deserialize_with = "lenient")]
    pub lowest_plan: Option<String>,
}

/// The features by key, in the order the server sent them (its registry's). Sent on as a JSON object in that same
/// order, which the page keeps. An entry that does not parse is left out on its own; anything but an object is none.
#[derive(Clone, Debug, Default, PartialEq)]
pub struct Features(Vec<(String, FeatureEntitlement)>);

impl Features {
    pub fn get(&self, key: &str) -> Option<&FeatureEntitlement> {
        self.0.iter().find(|(k, _)| k == key).map(|(_, f)| f)
    }

    pub fn contains_key(&self, key: &str) -> bool {
        self.get(key).is_some()
    }

    /// The keys in the server's order.
    pub fn keys(&self) -> impl Iterator<Item = &str> {
        self.0.iter().map(|(k, _)| k.as_str())
    }

    pub fn len(&self) -> usize {
        self.0.len()
    }

    pub fn is_empty(&self) -> bool {
        self.0.is_empty()
    }

    fn insert(&mut self, key: String, feature: FeatureEntitlement) {
        match self.0.iter_mut().find(|(k, _)| *k == key) {
            Some(entry) => entry.1 = feature,
            None => self.0.push((key, feature)),
        }
    }
}

impl std::ops::Index<&str> for Features {
    type Output = FeatureEntitlement;

    fn index(&self, key: &str) -> &FeatureEntitlement {
        self.get(key).unwrap_or_else(|| panic!("no feature {key}"))
    }
}

impl Serialize for Features {
    fn serialize<S: serde::Serializer>(&self, serializer: S) -> Result<S::Ok, S::Error> {
        use serde::ser::SerializeMap;
        let mut map = serializer.serialize_map(Some(self.0.len()))?;
        for (key, feature) in &self.0 {
            map.serialize_entry(key, feature)?;
        }
        map.end()
    }
}

impl<'de> Deserialize<'de> for Features {
    fn deserialize<D: serde::Deserializer<'de>>(deserializer: D) -> Result<Self, D::Error> {
        struct InOrder;

        impl<'de> serde::de::Visitor<'de> for InOrder {
            type Value = Features;

            fn expecting(&self, f: &mut std::fmt::Formatter) -> std::fmt::Result {
                f.write_str("the features by key")
            }

            fn visit_map<A: serde::de::MapAccess<'de>>(self, mut map: A) -> Result<Features, A::Error> {
                let mut features = Features::default();
                while let Some((key, value)) = map.next_entry::<String, serde_json::Value>()? {
                    if let Ok(feature) = serde_json::from_value(value) {
                        features.insert(key, feature);
                    }
                }
                Ok(features)
            }

            fn visit_seq<A: serde::de::SeqAccess<'de>>(self, mut seq: A) -> Result<Features, A::Error> {
                while seq.next_element::<serde::de::IgnoredAny>()?.is_some() {}
                Ok(Features::default())
            }

            fn visit_unit<E>(self) -> Result<Features, E> {
                Ok(Features::default())
            }

            fn visit_none<E>(self) -> Result<Features, E> {
                Ok(Features::default())
            }

            fn visit_bool<E>(self, _: bool) -> Result<Features, E> {
                Ok(Features::default())
            }

            fn visit_i64<E>(self, _: i64) -> Result<Features, E> {
                Ok(Features::default())
            }

            fn visit_u64<E>(self, _: u64) -> Result<Features, E> {
                Ok(Features::default())
            }

            fn visit_f64<E>(self, _: f64) -> Result<Features, E> {
                Ok(Features::default())
            }

            fn visit_str<E>(self, _: &str) -> Result<Features, E> {
                Ok(Features::default())
            }
        }

        deserializer.deserialize_any(InOrder)
    }
}

/// One limit: `limit: null` is no limit; `used` only where the server counts it.
#[derive(Clone, Debug, Default, PartialEq, Serialize, Deserialize)]
pub struct LimitEntitlement {
    #[serde(default, deserialize_with = "lenient")]
    pub limit: Option<f64>,
    #[serde(default, deserialize_with = "lenient")]
    pub used: Option<f64>,
    /// When the monthly minutes start again (since v1.7, `monthly_minutes` only): the 1st of next month.
    #[serde(default, deserialize_with = "lenient", skip_serializing_if = "Option::is_none")]
    pub resets_at: Option<String>,
}

/// The person's own transcription key: whether one is set and its last four characters, never the key.
#[derive(Clone, Debug, Default, PartialEq, Eq, Serialize, Deserialize)]
pub struct TranscriptionKeyState {
    #[serde(default, deserialize_with = "lenient")]
    pub provider: Option<String>,
    #[serde(default)]
    pub set: bool,
    #[serde(default, deserialize_with = "lenient")]
    pub last4: Option<String>,
}

/// An upgrade request (`POST /api/upgrade-requests`, `GET /api/upgrade-requests/latest`).
#[derive(Clone, Debug, Default, PartialEq, Serialize, Deserialize)]
pub struct UpgradeRequest {
    #[serde(default)]
    pub id: String,
    #[serde(default)]
    pub plan: String,
    /// `pending`, `approved` or `declined`.
    #[serde(default)]
    pub status: String,
    #[serde(default, deserialize_with = "lenient")]
    pub message: Option<String>,
    #[serde(default, deserialize_with = "lenient")]
    pub payment_instructions: Option<String>,
    #[serde(default, deserialize_with = "lenient")]
    pub created_at: Option<String>,
    #[serde(default, deserialize_with = "lenient")]
    pub decided_at: Option<String>,
    #[serde(default, deserialize_with = "lenient")]
    pub reply: Option<String>,
    /// A promo code on the request (since v1.6.2), its discount and the price after it, as the server shows it.
    #[serde(default, deserialize_with = "lenient")]
    pub promo_code: Option<String>,
    #[serde(default, deserialize_with = "lenient")]
    pub percent: Option<f64>,
    #[serde(default, deserialize_with = "lenient")]
    pub amount_due: Option<String>,
    /// A 100 % code approves the request at once (since v1.6.2): the plan switched on, and until when.
    #[serde(default, deserialize_with = "lenient")]
    pub approved_plan: Option<String>,
    #[serde(default, deserialize_with = "lenient")]
    pub approved_until: Option<String>,
    /// Payment receipts (since v1.7): the reference to write on the transfer, the amount in whole rupees, how to pay
    /// and what was sent, and the request's steps so far.
    #[serde(default, deserialize_with = "lenient")]
    pub reference_code: Option<String>,
    #[serde(default, deserialize_with = "lenient")]
    pub amount_due_pkr: Option<f64>,
    #[serde(default, deserialize_with = "lenient")]
    pub currency: Option<String>,
    #[serde(default, deserialize_with = "lenient")]
    pub payment: Option<PaymentState>,
    #[serde(default, deserialize_with = "lenient_list")]
    pub timeline: Vec<TimelineStep>,
}

/// How a request is paid for and where it stands (since v1.7, `payment` of the request).
#[derive(Clone, Debug, Default, PartialEq, Serialize, Deserialize)]
pub struct PaymentState {
    /// `awaiting_payment`, `in_review`, `needs_receipt`, `approved`, `declined` or `cancelled`.
    #[serde(default)]
    pub stage: String,
    #[serde(default, deserialize_with = "lenient_list")]
    pub accounts: Vec<PaymentAccount>,
    #[serde(default, deserialize_with = "lenient")]
    pub note: Option<String>,
    #[serde(default, deserialize_with = "lenient")]
    pub review_hours: Option<String>,
    #[serde(default, deserialize_with = "lenient")]
    pub paid_at: Option<String>,
    #[serde(default, deserialize_with = "lenient")]
    pub reference: Option<String>,
    #[serde(default, deserialize_with = "lenient")]
    pub payer_name: Option<String>,
    #[serde(default, deserialize_with = "lenient")]
    pub proof_request: Option<ProofRequest>,
    #[serde(default, deserialize_with = "lenient_list")]
    pub proofs: Vec<Proof>,
    #[serde(default, deserialize_with = "lenient")]
    pub max_proofs: Option<u32>,
    #[serde(default, deserialize_with = "lenient")]
    pub max_bytes: Option<u64>,
    #[serde(default, deserialize_with = "lenient_list")]
    pub accepts: Vec<String>,
}

/// One bank account the money goes to.
#[derive(Clone, Debug, Default, PartialEq, Eq, Serialize, Deserialize)]
pub struct PaymentAccount {
    #[serde(default, deserialize_with = "lenient")]
    pub bank: Option<String>,
    #[serde(default, deserialize_with = "lenient")]
    pub branch: Option<String>,
    #[serde(default, deserialize_with = "lenient")]
    pub title: Option<String>,
    #[serde(default, deserialize_with = "lenient")]
    pub account_number: Option<String>,
    #[serde(default, deserialize_with = "lenient")]
    pub iban: Option<String>,
}

/// Staff asked for a new receipt: why, and when.
#[derive(Clone, Debug, Default, PartialEq, Eq, Serialize, Deserialize)]
pub struct ProofRequest {
    #[serde(default, deserialize_with = "lenient")]
    pub note: Option<String>,
    #[serde(default, deserialize_with = "lenient")]
    pub at: Option<String>,
}

/// One receipt on a request. Its file is read through the core (`upgrade_request_proof_file`), never by its URL: the
/// URLs need the device token, which the page never has, so they are not sent on to it.
#[derive(Clone, Debug, Default, PartialEq, Serialize, Deserialize)]
pub struct Proof {
    #[serde(default)]
    pub id: String,
    /// `image` or `pdf`.
    #[serde(default)]
    pub kind: String,
    #[serde(default, deserialize_with = "lenient")]
    pub mime: Option<String>,
    #[serde(default, deserialize_with = "lenient")]
    pub name: Option<String>,
    #[serde(default, deserialize_with = "lenient")]
    pub bytes: Option<u64>,
    #[serde(default, deserialize_with = "lenient")]
    pub width: Option<u32>,
    #[serde(default, deserialize_with = "lenient")]
    pub height: Option<u32>,
    #[serde(default, deserialize_with = "lenient")]
    pub created_at: Option<String>,
    #[serde(default)]
    pub locked: bool,
    #[serde(default, deserialize_with = "lenient", skip_serializing)]
    pub thumb_url: Option<String>,
    #[serde(default, deserialize_with = "lenient", skip_serializing)]
    pub file_url: Option<String>,
}

/// One step of a request (`requested`, `receipt_sent`, `receipt_requested`, `decided`, `cancelled`) and when.
#[derive(Clone, Debug, Default, PartialEq, Eq, Serialize, Deserialize)]
pub struct TimelineStep {
    #[serde(default)]
    pub step: String,
    #[serde(default, deserialize_with = "lenient")]
    pub at: Option<String>,
}

/// A receipt's file as the page shows it: its type and its bytes in base64, made into a `blob:` URL and let go.
#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
pub struct ProofFile {
    pub mime: String,
    pub data: String,
}

/// `POST /api/promo-codes/check` (since v1.6.2): a valid code for the plan asked, its discount and the prices before
/// and after it as the server writes them. `free` is a 100 % code: nothing to pay, the plan switches on at once.
#[derive(Clone, Debug, Default, PartialEq, Serialize, Deserialize)]
pub struct PromoCheck {
    #[serde(default)]
    pub code: String,
    #[serde(default)]
    pub plan: String,
    #[serde(default, deserialize_with = "lenient")]
    pub plan_name: Option<String>,
    #[serde(default, deserialize_with = "lenient")]
    pub percent: Option<f64>,
    #[serde(default, deserialize_with = "lenient")]
    pub price: Option<String>,
    #[serde(default, deserialize_with = "lenient")]
    pub final_price: Option<String>,
    #[serde(default)]
    pub free: bool,
    #[serde(default, deserialize_with = "lenient")]
    pub months: Option<u32>,
}

/// What the upgrade sheet hears about a promo code: it applies, it does not, it is for another plan (which the sheet
/// offers), or the server has no promo codes yet (an older one: the field goes).
#[derive(Clone, Debug, PartialEq, Serialize)]
#[serde(tag = "result", rename_all = "snake_case")]
pub enum PromoAnswer {
    Valid { check: PromoCheck },
    Invalid { message: String },
    OtherPlan { plan: String, message: String },
    Unsupported,
}

/// A plan on offer, for the comparison sheet (cheapest first).
#[derive(Clone, Debug, Default, PartialEq, Serialize, Deserialize)]
pub struct OfferedPlan {
    #[serde(default)]
    pub key: String,
    #[serde(default)]
    pub name: String,
    #[serde(default, deserialize_with = "lenient")]
    pub badge: Option<String>,
    #[serde(default, deserialize_with = "lenient")]
    pub color: Option<String>,
    #[serde(default, deserialize_with = "lenient")]
    pub price: Option<String>,
    /// Since v1.7: the price in whole rupees (none when free or not set) and as the apps write it ("Rs 2,999 a month").
    #[serde(default, deserialize_with = "lenient")]
    pub price_pkr: Option<f64>,
    #[serde(default, deserialize_with = "lenient")]
    pub price_text: Option<String>,
    /// Up to five lines about the plan, when the server sends them.
    #[serde(default, deserialize_with = "lenient_list")]
    pub highlights: Vec<String>,
    #[serde(default, deserialize_with = "lenient")]
    pub description: Option<String>,
    #[serde(default, deserialize_with = "lenient_list")]
    pub features: Vec<String>,
    /// Each limit's number, `null` for no limit; anything else is left out.
    #[serde(default, deserialize_with = "limit_numbers")]
    pub limits: std::collections::BTreeMap<String, Option<f64>>,
}

/// A badge's text: short, one line ("PRO"), none when there is nothing to show.
fn badge_text(text: Option<String>) -> Option<String> {
    text.map(|t| t.split_whitespace().collect::<Vec<_>>().join(" ").chars().take(16).collect::<String>()).filter(|t| !t.is_empty())
}

fn colour(value: Option<String>) -> Option<String> {
    value.map(|c| c.trim().to_ascii_lowercase()).filter(|c| crate::settings::is_hex_colour(c))
}

impl Entitlements {
    /// Badges cut to one short line, colours that are not `#rrggbb` dropped (the page puts them in styles), plans
    /// without a key dropped.
    pub fn checked(mut self) -> Self {
        if let Some(plan) = self.plan.as_mut() {
            plan.badge = badge_text(plan.badge.take());
            plan.color = colour(plan.color.take());
        }
        self.plans.retain(|p| !p.key.trim().is_empty());
        for plan in &mut self.plans {
            plan.badge = badge_text(plan.badge.take());
            plan.color = colour(plan.color.take());
            plan.highlights = std::mem::take(&mut plan.highlights).into_iter().map(|h| h.trim().to_string()).filter(|h| !h.is_empty()).take(5).collect();
        }
        self
    }
}

/// A list whose entries that do not parse are dropped one by one.
fn lenient_list<'de, D, T>(deserializer: D) -> Result<Vec<T>, D::Error>
where
    D: serde::Deserializer<'de>,
    T: serde::de::DeserializeOwned,
{
    let value = Option::<serde_json::Value>::deserialize(deserializer)?;
    Ok(match value {
        Some(serde_json::Value::Array(items)) => items.into_iter().filter_map(|i| serde_json::from_value(i).ok()).collect(),
        _ => Vec::new(),
    })
}

/// A map whose entries that do not parse are dropped one by one.
fn lenient_map<'de, D, T>(deserializer: D) -> Result<std::collections::BTreeMap<String, T>, D::Error>
where
    D: serde::Deserializer<'de>,
    T: serde::de::DeserializeOwned,
{
    let value = Option::<serde_json::Value>::deserialize(deserializer)?;
    Ok(match value {
        Some(serde_json::Value::Object(map)) => map.into_iter().filter_map(|(k, v)| serde_json::from_value(v).ok().map(|v| (k, v))).collect(),
        _ => std::collections::BTreeMap::new(),
    })
}

/// A plan's limits: numbers, and `null` for no limit.
fn limit_numbers<'de, D>(deserializer: D) -> Result<std::collections::BTreeMap<String, Option<f64>>, D::Error>
where
    D: serde::Deserializer<'de>,
{
    let value = Option::<serde_json::Value>::deserialize(deserializer)?;
    Ok(match value {
        Some(serde_json::Value::Object(map)) => map
            .into_iter()
            .filter_map(|(k, v)| match v {
                serde_json::Value::Null => Some((k, None)),
                serde_json::Value::Number(n) => n.as_f64().map(|n| (k, Some(n))),
                _ => None,
            })
            .collect(),
        _ => std::collections::BTreeMap::new(),
    })
}
