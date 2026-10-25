//! Resumable chunked uploads (docs/API.md "Sessions, recordings, uploads"). Finished recordings are queued on disk
//! (`uploads.json`) so they survive restarts and network loss; a worker drains the queue. Local audio is deleted
//! only once the server reports every artifact `ready` (A6.DR8). Streaming usage reports (`usage.json`) and
//! server sessions to delete (`discards.json`) are queued next to it.

use std::io::{Read, Seek, SeekFrom};
use std::path::{Path, PathBuf};
use std::time::{Duration, Instant};

use serde::de::DeserializeOwned;
use serde::{Deserialize, Serialize};

use super::api::ApiClient;
use super::models::{
    ArtifactDto, CreateSessionRequest, CreateSessionResponse, DeviceTranscript, FinishSessionRequest, RecordingUpload, SegmentDto,
    ServerConfig, UsageDto, UsageReport,
};
use crate::error::{AppError, AppResult};

/// `PendingUpload::state` values (the `UploadState` union of the UI).
pub mod states {
    pub const QUEUED: &str = "queued";
    pub const UPLOADING: &str = "uploading";
    pub const VERIFYING: &str = "verifying";
    pub const FAILED: &str = "failed";
}

/// Shown when the server rejected the assembled file twice (one automatic full restart, A5/DR8).
pub const UNVERIFIABLE: &str = "The server could not verify this upload.";

/// Sample rate of every file the recorder writes.
const RECORDED_RATE: u32 = 16_000;
/// `Retry-After` when a 429 arrives without one, and the longest wait honoured (docs/API.md "Conventions").
const DEFAULT_RETRY_AFTER_SECS: u64 = 5;
const MAX_RETRY_AFTER_SECS: u64 = 300;
/// Rate-limit waits do not consume a retry, but are bounded so a misbehaving server cannot hold the worker for ever.
const MAX_RATE_LIMIT_WAITS: u32 = 20;
/// Transient failures of one chunk are retried this often, after 2, 4 and 8 s.
const MAX_CHUNK_RETRIES: u32 = 3;
/// `422 upload_incomplete` answers handled per recording before giving up for this cycle.
const MAX_INCOMPLETE_ROUNDS: u32 = 2;
/// The longest recording the server accepts (docs/API.md: `duration_ms` 0-86 400 000).
const MAX_DURATION_MS: u64 = 86_400_000;

fn queued_state() -> String {
    states::QUEUED.to_string()
}

/// Last known server state of one upload slot, kept so a `verifying` item is checked without creating the
/// session again or hashing its files.
#[derive(Clone, Debug, Default, PartialEq, Eq, Serialize, Deserialize)]
pub struct UploadSlot {
    pub id: String,
    pub role: String,
    pub status: String,
    #[serde(default)]
    pub chunk_size_bytes: u64,
}

#[derive(Clone, Debug, Serialize, Deserialize)]
pub struct PendingUpload {
    pub client_ref: String,
    pub source_app: String,
    pub client_id: Option<String>,
    pub started_at: String,
    pub ended_at: String,
    pub duration_ms: u64,
    pub dir: PathBuf,
    pub primary: PathBuf,
    pub primary_channels: u16,
    pub mic_wav: Option<PathBuf>,
    pub system_wav: Option<PathBuf>,
    /// Live caption segments captured during the session (uploaded as the `live` transcript).
    #[serde(default)]
    pub live_segments: Vec<SegmentDto>,
    #[serde(default)]
    pub live_provider: Option<String>,
    #[serde(default)]
    pub attempts: u32,
    #[serde(default)]
    pub last_error: Option<String>,
    /// Open session created for live captions (v1.1): the upload finishes it instead of creating a new one.
    #[serde(default)]
    pub server_session_id: Option<String>,
    #[serde(default)]
    pub title: Option<String>,
    /// `queued` | `uploading` | `verifying` | `failed` (see [`states`]).
    #[serde(default = "queued_state")]
    pub state: String,
    /// Full re-uploads after the server failed to verify the file (at most one automatically).
    #[serde(default)]
    pub full_restarts: u8,
    /// The session this item created or finished on the server, once known.
    #[serde(default)]
    pub created_session_id: Option<String>,
    #[serde(default)]
    pub slots: Vec<UploadSlot>,
}

impl Default for PendingUpload {
    fn default() -> Self {
        Self {
            client_ref: String::new(),
            source_app: String::new(),
            client_id: None,
            started_at: String::new(),
            ended_at: String::new(),
            duration_ms: 0,
            dir: PathBuf::new(),
            primary: PathBuf::new(),
            primary_channels: 1,
            mic_wav: None,
            system_wav: None,
            live_segments: vec![],
            live_provider: None,
            attempts: 0,
            last_error: None,
            server_session_id: None,
            title: None,
            state: queued_state(),
            full_restarts: 0,
            created_session_id: None,
            slots: vec![],
        }
    }
}

impl PendingUpload {
    /// Stable id of the queue entry: the name of its recording folder (the `client_ref` may change when a lost
    /// open session forces a new one).
    pub fn id(&self) -> String {
        self.dir
            .file_name()
            .and_then(|n| n.to_str())
            .filter(|n| !n.is_empty())
            .map(str::to_string)
            .unwrap_or_else(|| self.client_ref.clone())
    }

    pub fn is_failed(&self) -> bool {
        self.state == states::FAILED
    }

    /// The server session to delete when the item is discarded.
    pub fn server_session(&self) -> Option<String> {
        self.server_session_id.clone().or_else(|| self.created_session_id.clone())
    }

    fn file_for(&self, role: &str) -> Option<PathBuf> {
        match role {
            "primary" => Some(self.primary.clone()),
            "channel_1" => self.mic_wav.clone(),
            "channel_2" => self.system_wav.clone(),
            _ => None,
        }
    }
}

/// One row of the upload queue for the UI (`upload_list`, `uploads:changed`).
#[derive(Clone, Debug, PartialEq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct UploadItem {
    pub id: String,
    pub source_app: String,
    pub title: Option<String>,
    pub started_at: String,
    pub duration_ms: u64,
    pub state: String,
    pub last_error: Option<String>,
    pub attempts: u32,
}

impl From<&PendingUpload> for UploadItem {
    fn from(p: &PendingUpload) -> Self {
        Self {
            id: p.id(),
            source_app: p.source_app.clone(),
            title: p.title.clone(),
            started_at: p.started_at.clone(),
            duration_ms: p.duration_ms,
            state: p.state.clone(),
            last_error: p.last_error.clone(),
            attempts: p.attempts,
        }
    }
}

/// How an upload attempt ended.
#[derive(Clone, Debug, PartialEq, Eq)]
pub enum UploadOutcome {
    /// Every artifact is `ready` on the server: the local audio may go.
    Ready { session_id: String },
    /// Sent, but the server is still assembling: keep the audio and check again next cycle.
    Verifying { session_id: String },
    /// The session was deleted on the server (a tombstone, or the recording is gone): nothing left to send.
    Deleted { session_id: String },
}

impl UploadOutcome {
    pub fn session_id(&self) -> &str {
        match self {
            Self::Ready { session_id } | Self::Verifying { session_id } | Self::Deleted { session_id } => session_id,
        }
    }
}

/// Polling after `complete` (docs/API.md: every 5 s for up to 2 minutes, then on the next upload cycle).
#[derive(Clone, Copy, Debug)]
pub struct UploadTiming {
    pub poll_every: Duration,
    pub poll_for: Duration,
}

impl Default for UploadTiming {
    fn default() -> Self {
        Self { poll_every: Duration::from_secs(5), poll_for: Duration::from_secs(120) }
    }
}

/// What the queue does with an item after an upload attempt.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum AfterAttempt {
    /// Remove the item and delete its local audio: every artifact is `ready`, or the session is gone.
    Remove,
    /// Keep the item (and its audio) in the state set on it.
    Keep,
}

/// Applies an attempt's result to the queue entry (pure; the worker stores the item or removes it). The local audio
/// only goes once the server holds a verified copy (A6.DR8).
pub fn after_attempt(item: &mut PendingUpload, was_verifying: bool, result: &AppResult<UploadOutcome>) -> AfterAttempt {
    let resume = if was_verifying { states::VERIFYING } else { states::QUEUED };
    match result {
        Ok(UploadOutcome::Ready { .. } | UploadOutcome::Deleted { .. }) => return AfterAttempt::Remove,
        Ok(UploadOutcome::Verifying { .. }) => {
            item.state = states::VERIFYING.into();
            item.last_error = None;
        }
        Err(AppError::NotPaired) => item.state = resume.into(),
        Err(e) => {
            item.attempts += 1;
            item.last_error = Some(e.to_string());
            item.state = if is_final_failure(e) { states::FAILED } else { resume }.into();
        }
    }
    AfterAttempt::Keep
}

/// Deletes an item's recording folder.
pub fn delete_local_audio(item: &PendingUpload) {
    if !item.dir.as_os_str().is_empty() {
        let _ = std::fs::remove_dir_all(&item.dir);
    }
}

/// Errors after which the worker stops retrying the item on its own and shows it as failed with Retry.
pub fn is_final_failure(e: &AppError) -> bool {
    match e {
        AppError::NotPaired => false, // the whole queue waits for pairing, the item itself is fine
        AppError::Message(m) => m == UNVERIFIABLE,
        AppError::Io(io) => io.kind() == std::io::ErrorKind::NotFound, // the audio file is gone
        other => other.is_permanent(),
    }
}

/// (offset, length) of every chunk for a file of `size` bytes (pure).
pub fn plan_chunks(size: u64, chunk: u64) -> Vec<(u64, u64)> {
    if size == 0 || chunk == 0 {
        return vec![];
    }
    (0..size.div_ceil(chunk)).map(|i| (i * chunk, chunk.min(size - i * chunk))).collect()
}

/// Indexes still to send (pure).
pub fn missing_chunks(total: u64, received: &[u64]) -> Vec<u64> {
    (0..total).filter(|i| !received.contains(i)).collect()
}

pub fn sha256_hex(bytes: &[u8]) -> String {
    ring::digest::digest(&ring::digest::SHA256, bytes).as_ref().iter().map(|b| format!("{b:02x}")).collect()
}

pub fn file_sha256(path: &Path) -> AppResult<(String, u64)> {
    let mut file = std::fs::File::open(path)?;
    let mut ctx = ring::digest::Context::new(&ring::digest::SHA256);
    let mut buf = vec![0u8; 1 << 20];
    let mut size = 0u64;
    loop {
        let n = file.read(&mut buf)?;
        if n == 0 {
            break;
        }
        size += n as u64;
        ctx.update(&buf[..n]);
    }
    Ok((ctx.finish().as_ref().iter().map(|b| format!("{b:02x}")).collect(), size))
}

/// MIME type and codec declared for a file, by extension. Every type the drop zone imports
/// (`commands::is_audio`) maps to a type in the server's `accepted_mimes` (A6.DR5).
pub fn mime_for(path: &Path) -> (&'static str, &'static str) {
    match path.extension().and_then(|e| e.to_str()).map(|e| e.to_ascii_lowercase()).as_deref() {
        Some("flac") => ("audio/flac", "flac"),
        Some("wav") => ("audio/wav", "pcm_s16le"),
        Some("ogg" | "oga" | "opus") => ("audio/ogg", "opus"),
        Some("mp3") => ("audio/mpeg", "mp3"),
        Some("m4a" | "mp4") => ("audio/mp4", "aac"),
        Some("aac") => ("audio/aac", "aac"),
        Some("amr") => ("audio/amr", "amr"),
        Some("webm") => ("audio/webm", "opus"),
        _ => ("application/octet-stream", "unknown"),
    }
}

/// `(sample_rate, channels, total_samples_per_channel)` from a FLAC file's STREAMINFO block, which must directly
/// follow the `fLaC` marker (RFC 9639 §8.2: 16+16 bit block sizes, 24+24 bit frame sizes, 20 bit sample rate,
/// 3 bit channels-1, 5 bit bits-per-sample-1, 36 bit sample count).
pub fn flac_stream_info(header: &[u8]) -> Option<(u32, u16, u64)> {
    if header.len() < 42 || &header[..4] != b"fLaC" || header[4] & 0x7f != 0 {
        return None;
    }
    let s = &header[8..42];
    let sample_rate = (u32::from(s[10]) << 12) | (u32::from(s[11]) << 4) | (u32::from(s[12]) >> 4);
    let channels = u16::from((s[12] >> 1) & 0x07) + 1;
    let total = (u64::from(s[13] & 0x0f) << 32) | u64::from(u32::from_be_bytes([s[14], s[15], s[16], s[17]]));
    (sample_rate > 0).then_some((sample_rate, channels, total))
}

/// What can be read from an imported file's header without decoding it: WAV via `hound`, FLAC via STREAMINFO.
/// Compressed formats stay unknown (`None`) rather than claiming 16 kHz and 0 ms (A6.DR5).
fn measure(path: &Path) -> (Option<u32>, Option<u16>, Option<u64>) {
    let ms = |frames: u64, rate: u32| (rate > 0).then(|| frames * 1000 / u64::from(rate));
    match path.extension().and_then(|e| e.to_str()).map(|e| e.to_ascii_lowercase()).as_deref() {
        Some("wav") => match hound::WavReader::open(path) {
            Ok(reader) => {
                let spec = reader.spec();
                (Some(spec.sample_rate), Some(spec.channels), ms(u64::from(reader.duration()), spec.sample_rate))
            }
            Err(_) => (None, None, None),
        },
        Some("flac") => {
            let mut header = [0u8; 42];
            let read = std::fs::File::open(path).and_then(|mut f| f.read_exact(&mut header));
            match read.ok().and_then(|_| flac_stream_info(&header)) {
                Some((rate, channels, total)) => (Some(rate), Some(channels), (total > 0).then(|| ms(total, rate)).flatten()),
                None => (None, None, None),
            }
        }
        _ => (None, None, None),
    }
}

fn artifact(role: &str, path: &Path, channels: u16, sample_rate: Option<u32>, duration_ms: Option<u64>) -> AppResult<ArtifactDto> {
    let (sha256, size) = file_sha256(path)?;
    let (mime, codec) = mime_for(path);
    Ok(ArtifactDto {
        role: role.into(),
        mime: mime.into(),
        codec: Some(codec.into()),
        channels: channels.clamp(1, 2),
        sample_rate: sample_rate.filter(|r| (8_000..=192_000).contains(r)),
        size_bytes: size,
        sha256,
        duration_ms,
    })
}

/// The primary artifact. Recordings made here are 16 kHz with a measured duration; imports (duration 0) declare
/// only what their header says.
fn primary_artifact(item: &PendingUpload) -> AppResult<ArtifactDto> {
    if item.duration_ms > 0 {
        return artifact("primary", &item.primary, item.primary_channels, Some(RECORDED_RATE), Some(item.duration_ms));
    }
    let (rate, channels, duration) = measure(&item.primary);
    artifact("primary", &item.primary, channels.unwrap_or(item.primary_channels), rate, duration)
}

/// `ended_at` to declare. When the wall clock was set back during the recording, the end would lie before the
/// start and the server would refuse the session for good; the measured duration then decides (A6.DR15).
pub fn effective_ended_at(started_at: &str, ended_at: &str, duration_ms: u64) -> String {
    match (chrono::DateTime::parse_from_rfc3339(started_at), chrono::DateTime::parse_from_rfc3339(ended_at)) {
        (Ok(start), Ok(end)) if end < start => {
            let duration = chrono::Duration::milliseconds(duration_ms.min(MAX_DURATION_MS) as i64);
            start.checked_add_signed(duration).map(|t| t.to_rfc3339()).unwrap_or_else(|| ended_at.to_string())
        }
        _ => ended_at.to_string(),
    }
}

/// Uploads one pending recording end to end with the default timing and no queue bookkeeping. Returns the server
/// session id (also when the server is still assembling or the session was deleted there).
pub async fn upload(api: &ApiClient, pending: &PendingUpload) -> AppResult<String> {
    let config = api.config().await?;
    let mut item = pending.clone();
    upload_with(api, &config, &mut item, UploadTiming::default(), &|_: &PendingUpload| {}).await.map(|o| o.session_id().to_string())
}

/// One upload attempt for `item`, updating it in place (new `client_ref`, cleared client, slots, restarts) and
/// calling `persist` before each request that depends on such a change, so a crash cannot lose it.
pub async fn upload_with(
    api: &ApiClient,
    config: &ServerConfig,
    item: &mut PendingUpload,
    timing: UploadTiming,
    persist: &(dyn Fn(&PendingUpload) + Sync),
) -> AppResult<UploadOutcome> {
    if item.state == states::VERIFYING && !item.slots.is_empty() {
        return check_slots(api, item, timing, persist).await;
    }

    let use_case = if item.source_app == "voice_note" { "voice_note" } else { "final_transcript" };
    let required = config.required_artifacts(use_case);
    let mut artifacts = vec![primary_artifact(item)?];
    if required.iter().any(|r| r == "channel_1")
        && let (Some(mic), Some(sys)) = (&item.mic_wav, &item.system_wav)
    {
        artifacts.push(artifact("channel_1", mic, 1, Some(RECORDED_RATE), Some(item.duration_ms))?);
        artifacts.push(artifact("channel_2", sys, 1, Some(RECORDED_RATE), Some(item.duration_ms))?);
    }

    let declared: Vec<String> = artifacts.iter().map(|a| a.role.clone()).collect();
    let created = match open_slots(api, config, item, artifacts, persist).await? {
        Slots::Deleted(session_id) => return Ok(UploadOutcome::Deleted { session_id }),
        Slots::Created(created) => created,
    };
    let session_id = created.session.id.clone();
    item.created_session_id = Some(session_id.clone());
    item.slots = created
        .recordings
        .iter()
        .filter(|r| declared.contains(&r.role))
        .map(|r| UploadSlot { id: r.id.clone(), role: r.role.clone(), status: r.upload.status.clone(), chunk_size_bytes: r.upload.chunk_size_bytes })
        .collect();
    persist(item);

    for recording in &created.recordings {
        let Some(path) = item.file_for(&recording.role).filter(|_| declared.contains(&recording.role)) else { continue };
        let status = match recording.upload.status.as_str() {
            "ready" | "assembling" => continue, // already sent (idempotent retry); assembling is checked below
            "failed" => {
                take_restart(item, persist)?;
                send_and_complete(api, &recording.id, &path, recording.upload.chunk_size_bytes, &[], item, persist).await?
            }
            _ => send_and_complete(api, &recording.id, &path, recording.upload.chunk_size_bytes, &recording.upload.received_chunks, item, persist).await?,
        };
        set_slot(item, &recording.id, &status);
        persist(item);
    }

    if !item.live_segments.is_empty() {
        let transcript = DeviceTranscript {
            kind: "live".into(),
            provider: item.live_provider.clone().unwrap_or_else(|| "assemblyai".into()),
            language: None,
            segments: std::mem::take(&mut item.live_segments), // posted once, never again on a later cycle
        };
        if let Err(e) = api.post_transcript(&session_id, &transcript).await {
            eprintln!("[echoledger] live transcript upload failed (final transcript unaffected): {e}");
        }
        persist(item);
    }

    settle_slots(api, item, session_id, timing, timing.poll_for, persist).await
}

enum Slots {
    Created(CreateSessionResponse),
    Deleted(String),
}

/// Finish the open session (v1.1) or create the session in one go, with the documented fallbacks:
/// `404 session_not_found` on finish → one-shot create with a new `client_ref`; `404 client_not_found` → forget
/// the client and try once more without it (A6.DR7); a tombstone answer → the session was deleted.
async fn open_slots(
    api: &ApiClient,
    config: &ServerConfig,
    item: &mut PendingUpload,
    artifacts: Vec<ArtifactDto>,
    persist: &(dyn Fn(&PendingUpload) + Sync),
) -> AppResult<Slots> {
    let ended_at = effective_ended_at(&item.started_at, &item.ended_at, item.duration_ms);
    let mut client_cleared = false;
    loop {
        let result = match item.server_session_id.clone().filter(|_| config.is_v1_1()) {
            Some(open) => {
                let request = FinishSessionRequest {
                    ended_at: ended_at.clone(),
                    duration_ms: item.duration_ms,
                    title: item.title.clone(),
                    client_id: Some(item.client_id.clone()),
                    artifacts: artifacts.clone(),
                };
                api.finish_session(&open, &request).await
            }
            None => {
                let request = CreateSessionRequest {
                    client_ref: item.client_ref.clone(),
                    source_app: item.source_app.clone(),
                    client_id: item.client_id.clone(),
                    title: item.title.clone(),
                    started_at: item.started_at.clone(),
                    ended_at: Some(ended_at.clone()),
                    duration_ms: Some(item.duration_ms),
                    language: None,
                    artifacts: artifacts.clone(),
                };
                api.create_session(&request).await
            }
        };
        match result {
            Ok(created) if created.session.deleted => return Ok(Slots::Deleted(created.session.id)),
            Ok(created) => return Ok(Slots::Created(created)),
            Err(e) if e.code() == Some("client_not_found") && item.client_id.is_some() && !client_cleared => {
                client_cleared = true;
                item.client_id = None;
                persist(item);
            }
            Err(e) if e.code() == Some("session_not_found") && item.server_session_id.is_some() => {
                let open = item.server_session_id.take().unwrap_or_default();
                if item.created_session_id.as_deref() == Some(open.as_str()) {
                    // This item already finished that session; it has been deleted on the server since.
                    return Ok(Slots::Deleted(open));
                }
                // Swept or discarded before it was finished: a new session with a new client_ref, stored first so
                // a retry reuses it.
                item.client_ref = uuid::Uuid::new_v4().to_string();
                persist(item);
            }
            Err(e) => return Err(e),
        }
    }
}

fn set_slot(item: &mut PendingUpload, recording_id: &str, status: &str) {
    if let Some(slot) = item.slots.iter_mut().find(|s| s.id == recording_id) {
        slot.status = status.to_string();
    }
}

fn take_restart(item: &mut PendingUpload, persist: &(dyn Fn(&PendingUpload) + Sync)) -> AppResult<()> {
    if item.full_restarts >= 1 {
        return Err(AppError::msg(UNVERIFIABLE));
    }
    item.full_restarts += 1;
    persist(item);
    Ok(())
}

fn rate_limit_wait(e: &AppError) -> Duration {
    Duration::from_secs(e.retry_after_secs().unwrap_or(DEFAULT_RETRY_AFTER_SECS).min(MAX_RETRY_AFTER_SECS))
}

fn is_upload_incomplete(e: &AppError) -> bool {
    match e {
        AppError::Server { code: Some(code), .. } => code == "upload_incomplete",
        // v1 servers send no code; their message is the documented one.
        AppError::Server { status: 422, code: None, message, .. } => message == "Upload is incomplete.",
        _ => false,
    }
}

enum Sent {
    All,
    /// `409`: the server already assembles this recording.
    AlreadyComplete,
}

/// Sends the chunks the server does not have yet.
async fn send_chunks(api: &ApiClient, recording_id: &str, path: &Path, plan: &[(u64, u64)], received: &[u64]) -> AppResult<Sent> {
    for index in missing_chunks(plan.len() as u64, received) {
        let (offset, len) = plan[index as usize];
        let mut file = std::fs::File::open(path)?;
        file.seek(SeekFrom::Start(offset))?;
        let mut bytes = vec![0u8; len as usize];
        file.read_exact(&mut bytes)?;
        match send_chunk(api, recording_id, index, bytes).await {
            Ok(()) => {}
            Err(e) if e.status() == Some(409) => return Ok(Sent::AlreadyComplete),
            Err(e) => return Err(e),
        }
    }
    Ok(Sent::All)
}

/// One chunk: transient failures (network, 408, 5xx) are retried after 2, 4 and 8 s; a 429 waits `Retry-After`
/// without using up a retry; permanent answers return at once (A6.DR4).
async fn send_chunk(api: &ApiClient, recording_id: &str, index: u64, bytes: Vec<u8>) -> AppResult<()> {
    let hash = sha256_hex(&bytes);
    let (mut retries, mut waits) = (0u32, 0u32);
    loop {
        match api.upload_chunk(recording_id, index, bytes.clone(), &hash).await {
            Ok(_) => return Ok(()),
            Err(e) if e.status() == Some(429) && waits < MAX_RATE_LIMIT_WAITS => {
                waits += 1;
                tokio::time::sleep(rate_limit_wait(&e)).await;
            }
            Err(e) if e.is_permanent() => return Err(e),
            Err(e) if retries < MAX_CHUNK_RETRIES => {
                retries += 1;
                eprintln!("[echoledger] chunk {index} retry {retries}: {e}");
                tokio::time::sleep(Duration::from_secs(2u64.pow(retries))).await;
            }
            Err(e) => return Err(e),
        }
    }
}

/// Sends the missing chunks and calls `complete`, resuming `upload_incomplete` answers and restarting from chunk 0
/// once on `verification_failed`. Returns the status `complete` reported (`ready` or `assembling`).
async fn send_and_complete(
    api: &ApiClient,
    recording_id: &str,
    path: &Path,
    chunk_size: u64,
    received: &[u64],
    item: &mut PendingUpload,
    persist: &(dyn Fn(&PendingUpload) + Sync),
) -> AppResult<String> {
    let size = std::fs::metadata(path)?.len();
    let plan = plan_chunks(size, chunk_size.max(1));
    let mut received = received.to_vec();
    loop {
        if let Sent::AlreadyComplete = send_chunks(api, recording_id, path, &plan, &received).await? {
            return Ok("assembling".into());
        }
        let (mut rounds, mut waits) = (0u32, 0u32);
        let restart = loop {
            match api.complete(recording_id).await {
                Ok(answer) => return Ok(answer.status),
                Err(e) if is_upload_incomplete(&e) && rounds < MAX_INCOMPLETE_ROUNDS => {
                    rounds += 1;
                    let state = api.recording_status(recording_id).await?;
                    if let Sent::AlreadyComplete = send_chunks(api, recording_id, path, &plan, &state.upload.received_chunks).await? {
                        return Ok("assembling".into());
                    }
                }
                Err(e) if e.code() == Some("verification_failed") => break true,
                Err(e) if e.status() == Some(429) && waits < MAX_RATE_LIMIT_WAITS => {
                    waits += 1;
                    tokio::time::sleep(rate_limit_wait(&e)).await;
                }
                Err(e) => return Err(e),
            }
        };
        if restart {
            // The server discarded every part: the whole file goes again, once.
            take_restart(item, persist)?;
            received.clear();
        }
    }
}

enum Settled {
    Ready,
    Pending,
    Deleted,
}

/// Polls `GET /api/recordings/{id}` until it reports `ready`, the deadline passes, or the recording is gone. A
/// `failed` report (parts discarded) triggers the single full re-upload.
async fn settle(
    api: &ApiClient,
    slot: &UploadSlot,
    path: Option<&Path>,
    item: &mut PendingUpload,
    timing: UploadTiming,
    first_window: Duration,
    persist: &(dyn Fn(&PendingUpload) + Sync),
) -> AppResult<Settled> {
    let mut deadline = Instant::now() + first_window;
    let mut resumed = false;
    loop {
        match api.recording_status(&slot.id).await {
            Ok(RecordingUpload { upload, .. }) => match upload.status.as_str() {
                "ready" => return Ok(Settled::Ready),
                "failed" => {
                    let Some(path) = path else { return Err(AppError::msg(UNVERIFIABLE)) };
                    take_restart(item, persist)?;
                    let chunk_size = if upload.chunk_size_bytes > 0 { upload.chunk_size_bytes } else { slot.chunk_size_bytes };
                    let status = send_and_complete(api, &slot.id, path, chunk_size, &[], item, persist).await?;
                    if status == "ready" {
                        return Ok(Settled::Ready);
                    }
                    deadline = Instant::now() + timing.poll_for;
                }
                // Parts are missing (a re-upload was cut short): send them and complete again.
                "pending" | "uploading" if !resumed => {
                    resumed = true;
                    let Some(path) = path else { return Ok(Settled::Pending) };
                    let chunk_size = if upload.chunk_size_bytes > 0 { upload.chunk_size_bytes } else { slot.chunk_size_bytes };
                    let status = send_and_complete(api, &slot.id, path, chunk_size, &upload.received_chunks, item, persist).await?;
                    if status == "ready" {
                        return Ok(Settled::Ready);
                    }
                    deadline = Instant::now() + timing.poll_for;
                }
                _ => {}
            },
            Err(e) if e.status() == Some(404) && e.code() != Some("not_found") => return Ok(Settled::Deleted),
            Err(e) if e.is_permanent() => return Err(e),
            Err(e) => eprintln!("[echoledger] checking recording {}: {e}", slot.id),
        }
        if Instant::now() + timing.poll_every > deadline {
            return Ok(Settled::Pending);
        }
        tokio::time::sleep(timing.poll_every).await;
    }
}

/// Waits for every slot that is not `ready` yet.
async fn settle_slots(
    api: &ApiClient,
    item: &mut PendingUpload,
    session_id: String,
    timing: UploadTiming,
    first_window: Duration,
    persist: &(dyn Fn(&PendingUpload) + Sync),
) -> AppResult<UploadOutcome> {
    let mut all_ready = true;
    for slot in item.slots.clone() {
        if slot.status == "ready" {
            continue;
        }
        let path = item.file_for(&slot.role);
        match settle(api, &slot, path.as_deref(), item, timing, first_window, persist).await? {
            Settled::Ready => set_slot(item, &slot.id, "ready"),
            Settled::Deleted => return Ok(UploadOutcome::Deleted { session_id }),
            Settled::Pending => {
                set_slot(item, &slot.id, "assembling");
                all_ready = false;
            }
        }
        persist(item);
    }
    Ok(if all_ready { UploadOutcome::Ready { session_id } } else { UploadOutcome::Verifying { session_id } })
}

/// A `verifying` item: one status check per slot, no session request and no chunks.
async fn check_slots(api: &ApiClient, item: &mut PendingUpload, timing: UploadTiming, persist: &(dyn Fn(&PendingUpload) + Sync)) -> AppResult<UploadOutcome> {
    let session_id = item.created_session_id.clone().or_else(|| item.server_session_id.clone()).unwrap_or_default();
    settle_slots(api, item, session_id, timing, Duration::ZERO, persist).await
}

// ---- Queues on disk ----------------------------------------------------------------------------------------------

fn load_json<T: Default + DeserializeOwned>(path: &Path) -> T {
    std::fs::read(path).ok().and_then(|b| serde_json::from_slice(&b).ok()).unwrap_or_default()
}

/// Atomic replace: write a temporary file, then rename it over the old one.
fn save_json<T: Serialize>(path: &Path, value: &T) -> AppResult<()> {
    let tmp = path.with_extension("json.tmp");
    std::fs::write(&tmp, serde_json::to_vec_pretty(value)?)?;
    std::fs::rename(tmp, path)?;
    Ok(())
}

#[derive(Default, Serialize, Deserialize)]
pub struct UploadQueue {
    pub items: Vec<PendingUpload>,
}

impl UploadQueue {
    pub fn load(path: &Path) -> Self {
        load_json(path)
    }

    pub fn save(&self, path: &Path) -> AppResult<()> {
        save_json(path, self)
    }

    pub fn ui_items(&self) -> Vec<UploadItem> {
        self.items.iter().map(UploadItem::from).collect()
    }

    /// Items still on their way (queued, uploading or verifying).
    pub fn active(&self) -> usize {
        self.items.iter().filter(|i| !i.is_failed()).count()
    }

    pub fn failed(&self) -> usize {
        self.items.iter().filter(|i| i.is_failed()).count()
    }
}

/// Streaming usage reports waiting for `POST /api/usage` (`usage.json`).
#[derive(Default, Serialize, Deserialize)]
pub struct UsageQueue {
    #[serde(default)]
    pub items: Vec<UsageReport>,
}

impl UsageQueue {
    /// At most this many reports are kept; the oldest go first.
    pub const CAP: usize = 500;

    pub fn load(path: &Path) -> Self {
        load_json(path)
    }

    pub fn save(&self, path: &Path) -> AppResult<()> {
        save_json(path, self)
    }

    pub fn push(&mut self, report: UsageReport) {
        if self.items.iter().any(|r| r.report_id == report.report_id) {
            return;
        }
        self.items.push(report);
        if self.items.len() > Self::CAP {
            let excess = self.items.len() - Self::CAP;
            self.items.drain(..excess);
        }
    }
}

/// Server sessions to delete (`discards.json`): discarded recordings whose session was already opened.
#[derive(Default, Serialize, Deserialize)]
pub struct DiscardQueue {
    #[serde(default)]
    pub items: Vec<String>,
}

impl DiscardQueue {
    pub fn load(path: &Path) -> Self {
        load_json(path)
    }

    pub fn save(&self, path: &Path) -> AppResult<()> {
        save_json(path, self)
    }

    pub fn push(&mut self, session_id: String) {
        if !session_id.is_empty() && !self.items.contains(&session_id) {
            self.items.push(session_id);
        }
    }
}

/// Result of sending queued usage reports.
#[derive(Debug, Default)]
pub struct UsageDrain {
    /// Report ids to remove from the queue (accepted, duplicate, or refused for good).
    pub done: Vec<String>,
    pub sent: usize,
    pub dropped: usize,
    /// The `usage` block of the last answer.
    pub usage: Option<UsageDto>,
    /// The transient error (or `NotPaired`) that stopped the drain; the remaining reports stay queued.
    pub stopped: Option<AppError>,
}

/// Sends reports oldest first: `200`/`201` remove, a permanent refusal (e.g. `422`) drops the report, anything
/// transient stops the drain so the rest waits for the next cycle.
pub async fn drain_usage(api: &ApiClient, reports: &[UsageReport]) -> UsageDrain {
    let mut out = UsageDrain::default();
    for report in reports {
        match api.report_usage(report).await {
            Ok(answer) => {
                out.sent += 1;
                out.done.push(report.report_id.clone());
                if answer.usage.is_some() {
                    out.usage = answer.usage;
                }
            }
            Err(e) if e.is_permanent() && !matches!(e, AppError::NotPaired) => {
                eprintln!("[echoledger] usage report {} dropped: {e}", report.report_id);
                out.dropped += 1;
                out.done.push(report.report_id.clone());
            }
            Err(e) => {
                out.stopped = Some(e);
                break;
            }
        }
    }
    out
}

/// Result of deleting queued server sessions.
#[derive(Debug, Default)]
pub struct DiscardDrain {
    pub done: Vec<String>,
    pub stopped: Option<AppError>,
}

/// `DELETE /api/sessions/{id}` for each queued id: `204` and `404` (already gone) are done; a transient failure
/// stops the drain.
pub async fn drain_discards(api: &ApiClient, ids: &[String]) -> DiscardDrain {
    let mut out = DiscardDrain::default();
    for id in ids {
        match api.delete_session(id).await {
            Ok(()) => out.done.push(id.clone()),
            Err(e) if e.status() == Some(404) => out.done.push(id.clone()),
            Err(e) if e.is_permanent() && !matches!(e, AppError::NotPaired) => {
                eprintln!("[echoledger] deleting session {id} refused: {e}");
                out.done.push(id.clone());
            }
            Err(e) => {
                out.stopped = Some(e);
                break;
            }
        }
    }
    out
}
