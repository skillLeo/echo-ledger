//! Recordings cut short (since v1.3): when the app is closed or stops in the middle of a recording, its folder keeps
//! `mic.wav` and `system.wav` (valid up to the last two seconds: the writer flushes them) but no upload file and no
//! queue entry, so nobody would ever see it and the audio would stay on disk. At start-up such a folder is encoded
//! again and waits for Keep or Discard as "Recovered recording"; one with less than a second of sound is removed.

use std::collections::HashSet;
use std::path::{Path, PathBuf};

use chrono::{DateTime, Duration, Utc};

use crate::audio::writer::encode_stereo_flac_from_wavs;
use crate::audio::types::TARGET_RATE;
use crate::net::sync::paths;
use crate::net::uploader::UploadQueue;
use crate::pending::{DecisionFiles, PendingDecision};

/// What one start-up pass did.
#[derive(Debug, Default, PartialEq, Eq)]
pub struct Recovery {
    /// Folders now waiting for Keep or Discard.
    pub recovered: Vec<String>,
    /// Folders removed because they held (almost) nothing.
    pub removed: Vec<String>,
}

/// Looks at every recording folder no queue knows. Call it before any recording starts (the one being recorded has
/// no queue entry yet either).
pub fn recover(data_dir: &Path, now: DateTime<Utc>) -> Recovery {
    let mut result = Recovery::default();
    // The folders as they are now: one that appears while this runs is a new recording, not a leftover.
    let Ok(entries) = std::fs::read_dir(paths::recordings(data_dir)) else { return result };
    let entries: Vec<_> = entries.flatten().collect();
    let files = DecisionFiles::in_dir(data_dir);
    let mut known: HashSet<PathBuf> = files.load().items.iter().map(|d| d.dir().to_path_buf()).collect();
    known.extend(UploadQueue::load(&paths::uploads(data_dir)).items.iter().map(|u| u.dir.clone()));
    for entry in entries {
        let dir = entry.path();
        let name = entry.file_name().to_string_lossy().to_string();
        if !dir.is_dir() || known.contains(&dir) {
            continue;
        }
        let (mic, system) = (dir.join("mic.wav"), dir.join("system.wav"));
        if !mic.is_file() || !system.is_file() {
            continue; // not a cut-short call recording: left alone
        }
        // A recording in progress writes its WAVs every two seconds: anything touched in the last minute is live.
        let touched = std::fs::metadata(&mic).and_then(|m| m.modified()).map(DateTime::<Utc>::from).unwrap_or(now);
        if now - touched < Duration::seconds(60) {
            continue;
        }
        let frames = wav_frames(&mic).max(wav_frames(&system));
        if frames < TARGET_RATE as u64 {
            if std::fs::remove_dir_all(&dir).is_ok() {
                result.removed.push(name);
            }
            continue;
        }
        let primary = dir.join("recording.flac");
        if let Err(e) = encode_stereo_flac_from_wavs(&mic, &system, &primary) {
            eprintln!("[echoledger] could not recover the recording in {name}: {e}");
            continue;
        }
        let duration_ms = frames * 1000 / TARGET_RATE as u64;
        let ended_at = std::fs::metadata(&mic).and_then(|m| m.modified()).map(DateTime::<Utc>::from).unwrap_or(now);
        let started_at = ended_at - Duration::milliseconds(duration_ms as i64);
        let decision = PendingDecision::recovered(dir.clone(), primary, mic, system, duration_ms, started_at, ended_at, now);
        if files.add(decision).is_ok() {
            result.recovered.push(name);
        }
    }
    result
}

/// Frames a WAV header says it holds (0 when it cannot be read).
fn wav_frames(path: &Path) -> u64 {
    hound::WavReader::open(path).map(|r| u64::from(r.duration())).unwrap_or(0)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn wav(path: &Path, seconds: f32) {
        let spec = hound::WavSpec { channels: 1, sample_rate: TARGET_RATE, bits_per_sample: 16, sample_format: hound::SampleFormat::Int };
        let mut w = hound::WavWriter::create(path, spec).unwrap();
        for i in 0..(seconds * TARGET_RATE as f32) as usize {
            w.write_sample(((i % 100) as i16 - 50) * 100).unwrap();
        }
        w.finalize().unwrap();
    }

    fn recording(data_dir: &Path, name: &str, seconds: f32) -> PathBuf {
        let dir = paths::recordings(data_dir).join(name);
        std::fs::create_dir_all(&dir).unwrap();
        wav(&dir.join("mic.wav"), seconds);
        wav(&dir.join("system.wav"), seconds);
        dir
    }

    #[test]
    fn a_call_cut_short_waits_for_keep_or_discard_and_a_near_empty_one_is_removed() {
        let data = tempfile::tempdir().unwrap();
        let cut = recording(data.path(), "cut-short", 3.0);
        let empty = recording(data.path(), "empty", 0.2);
        let other = paths::recordings(data.path()).join("not-a-call");
        std::fs::create_dir_all(&other).unwrap();

        assert_eq!(recover(data.path(), Utc::now()), Recovery::default(), "a folder written a moment ago may be live");

        let later = Utc::now() + Duration::minutes(2);
        let done = recover(data.path(), later);

        assert_eq!(done.recovered, vec!["cut-short".to_string()]);
        assert_eq!(done.removed, vec!["empty".to_string()]);
        assert!(cut.join("recording.flac").is_file());
        assert!(!empty.exists());
        assert!(other.exists(), "a folder that is not a cut-short call is left alone");
        let waiting = DecisionFiles::in_dir(data.path()).load().items;
        assert_eq!(waiting.len(), 1);
        assert_eq!(waiting[0].title.as_deref(), Some(crate::pending::RECOVERED_TITLE));
        assert_eq!(waiting[0].duration_ms, 3000);

        assert_eq!(recover(data.path(), later), Recovery::default(), "a second start finds nothing new");
    }
}
