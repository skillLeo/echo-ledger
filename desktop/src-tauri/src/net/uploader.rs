//! Resumable chunked uploads (docs/API.md). Finished recordings are queued on disk (`uploads.json`) so they
//! survive restarts and network loss; a worker drains the queue. Local audio is deleted after a verified upload.

use std::io::{Read, Seek, SeekFrom};
use std::path::{Path, PathBuf};

use serde::{Deserialize, Serialize};

use super::api::ApiClient;
use super::models::{ArtifactDto, CreateSessionRequest, DeviceTranscript, SegmentDto};
use crate::error::{AppError, AppResult};

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

fn mime_for(path: &Path) -> (&'static str, &'static str) {
    match path.extension().and_then(|e| e.to_str()).map(|e| e.to_ascii_lowercase()).as_deref() {
        Some("flac") => ("audio/flac", "flac"),
        Some("wav") => ("audio/wav", "pcm_s16le"),
        Some("ogg") | Some("opus") => ("audio/ogg", "opus"),
        Some("mp3") => ("audio/mpeg", "mp3"),
        Some("m4a") | Some("mp4") => ("audio/mp4", "aac"),
        Some("webm") => ("audio/webm", "opus"),
        _ => ("application/octet-stream", "unknown"),
    }
}

fn artifact(role: &str, path: &Path, channels: u16, duration_ms: u64) -> AppResult<ArtifactDto> {
    let (sha256, size) = file_sha256(path)?;
    let (mime, codec) = mime_for(path);
    Ok(ArtifactDto {
        role: role.into(),
        mime: mime.into(),
        codec: Some(codec.into()),
        channels,
        sample_rate: Some(16_000),
        size_bytes: size,
        sha256,
        duration_ms: Some(duration_ms),
    })
}

/// Uploads one pending recording end to end. Returns the server session id.
pub async fn upload(api: &ApiClient, pending: &PendingUpload) -> AppResult<String> {
    let config = api.config().await?;
    let use_case = if pending.source_app == "voice_note" { "voice_note" } else { "final_transcript" };
    let required = config.required_artifacts(use_case);

    let mut files: Vec<(String, PathBuf)> = vec![("primary".into(), pending.primary.clone())];
    let mut artifacts = vec![artifact("primary", &pending.primary, pending.primary_channels, pending.duration_ms)?];
    if required.iter().any(|r| r == "channel_1")
        && let (Some(mic), Some(sys)) = (&pending.mic_wav, &pending.system_wav)
    {
        artifacts.push(artifact("channel_1", mic, 1, pending.duration_ms)?);
        artifacts.push(artifact("channel_2", sys, 1, pending.duration_ms)?);
        files.push(("channel_1".into(), mic.clone()));
        files.push(("channel_2".into(), sys.clone()));
    }

    let created = api
        .create_session(&CreateSessionRequest {
            client_ref: pending.client_ref.clone(),
            source_app: pending.source_app.clone(),
            client_id: pending.client_id.clone(),
            title: None,
            started_at: pending.started_at.clone(),
            ended_at: Some(pending.ended_at.clone()),
            duration_ms: Some(pending.duration_ms),
            language: None,
            artifacts,
        })
        .await?;

    for recording in &created.recordings {
        let Some((_, path)) = files.iter().find(|(role, _)| *role == recording.role) else { continue };
        if recording.upload.status == "ready" || recording.upload.status == "assembling" {
            continue; // already done (idempotent retry)
        }
        let size = std::fs::metadata(path)?.len();
        let plan = plan_chunks(size, recording.upload.chunk_size_bytes.max(1));
        let mut received = recording.upload.received_chunks.clone();
        for index in missing_chunks(plan.len() as u64, &received.clone()) {
            let (offset, len) = plan[index as usize];
            let mut file = std::fs::File::open(path)?;
            file.seek(SeekFrom::Start(offset))?;
            let mut bytes = vec![0u8; len as usize];
            file.read_exact(&mut bytes)?;
            let hash = sha256_hex(&bytes);
            let mut attempt = 0;
            loop {
                match api.upload_chunk(&recording.id, index, bytes.clone(), &hash).await {
                    Ok(state) => {
                        received = state.received_chunks;
                        break;
                    }
                    Err(AppError::NotPaired) => return Err(AppError::NotPaired),
                    Err(e) if attempt < 3 => {
                        attempt += 1;
                        eprintln!("[echoledger] chunk {index} retry {attempt}: {e}");
                        tokio::time::sleep(std::time::Duration::from_secs(2u64.pow(attempt))).await;
                    }
                    Err(e) => return Err(e),
                }
            }
        }
        api.complete(&recording.id).await?;
    }

    let session_id = created.session.id.clone();
    if !pending.live_segments.is_empty() {
        let transcript = DeviceTranscript {
            kind: "live".into(),
            provider: pending.live_provider.clone().unwrap_or_else(|| "assemblyai".into()),
            language: None,
            segments: pending.live_segments.clone(),
        };
        if let Err(e) = api.post_transcript(&session_id, &transcript).await {
            eprintln!("[echoledger] live transcript upload failed (final transcript unaffected): {e}");
        }
    }
    Ok(session_id)
}

#[derive(Default, Serialize, Deserialize)]
pub struct UploadQueue {
    pub items: Vec<PendingUpload>,
}

impl UploadQueue {
    pub fn load(path: &Path) -> Self {
        std::fs::read(path).ok().and_then(|b| serde_json::from_slice(&b).ok()).unwrap_or_default()
    }

    pub fn save(&self, path: &Path) -> AppResult<()> {
        let tmp = path.with_extension("json.tmp");
        std::fs::write(&tmp, serde_json::to_vec_pretty(self)?)?;
        std::fs::rename(tmp, path)?;
        Ok(())
    }
}
