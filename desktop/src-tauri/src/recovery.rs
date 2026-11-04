//! Recordings cut short (since v1.3): when the app is closed or stops in the middle of a recording, its folder keeps
//! `mic.wav` and `system.wav` (valid up to the last two seconds: the writer flushes them) but no upload file and no
//! queue entry, so nobody would ever see it and the audio would stay on disk. At start-up such a folder is encoded
//! again and waits for Keep or Discard as "Recovered recording"; one with less than a second of sound is removed. A
//! call skipped as short or silent (v1.5) has no queue entry either: its folder waits in `skipped.json` and is left
//! alone. A folder that was on its way out (Discard, Undo, Cancel, a finished upload) but could not be deleted then
//! is deleted now, never recovered (v1.5: "Keep automatically" would upload it).

use std::collections::HashSet;
use std::path::{Path, PathBuf};

use chrono::{DateTime, Duration, Utc};

use crate::audio::writer::encode_stereo_flac_from_wavs;
use crate::audio::types::TARGET_RATE;
use crate::net::sync::paths;
use crate::net::uploader::UploadQueue;
use crate::pending::{DecisionFiles, PendingDecision};

/// The files a finished recording is uploaded from (`Recorder::finish`): a call, a voice note.
const UPLOAD_FILES: [&str; 2] = ["recording.flac", "voice-note.flac"];

/// The marker a recording folder gets beside it (`<folder>.leaving`) just before it is deleted. Beside it rather than
/// in it: a delete that stops half-way may already have taken the files inside.
const LEAVING: &str = ".leaving";

/// What one start-up pass did.
#[derive(Debug, Default, PartialEq, Eq)]
pub struct Recovery {
    /// Folders now waiting for Keep or Discard.
    pub recovered: Vec<String>,
    /// Of those, the ones stopped in the middle of the recording (no upload file yet): they follow "After a call".
    /// One that already had its upload file was finished and then lost its queue entry, the way a recording that was
    /// discarded, undone or uploaded loses it, so it waits for the person whatever the setting.
    pub cut_short: Vec<String>,
    /// Folders removed because they held (almost) nothing, or were on their way out.
    pub removed: Vec<String>,
}

/// Deletes a recording folder that is done with (Discard, Undo, Cancel, a finished upload). It is marked first, so
/// one that cannot be deleted now (a file held open by a virus scanner) is deleted at the next start instead of being
/// recovered.
pub fn delete_recording_folder(dir: &Path) {
    if dir.as_os_str().is_empty() || !dir.exists() {
        return;
    }
    let marker = leaving_marker(dir);
    if let Some(marker) = &marker {
        let _ = std::fs::write(marker, b"");
    }
    if (std::fs::remove_dir_all(dir).is_ok() || !dir.exists())
        && let Some(marker) = &marker
    {
        let _ = std::fs::remove_file(marker);
    }
}

fn leaving_marker(dir: &Path) -> Option<PathBuf> {
    let mut name = dir.file_name()?.to_os_string();
    name.push(LEAVING);
    Some(dir.with_file_name(name))
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
    known.extend(crate::skipped::SkippedStore::load(&paths::skipped(data_dir)).items.iter().map(|c| c.decision.dir().to_path_buf()));
    for entry in entries {
        let dir = entry.path();
        let name = entry.file_name().to_string_lossy().to_string();
        if !dir.is_dir() {
            // A marker whose folder went while the marker could not: it goes too.
            if let Some(folder) = name.strip_suffix(LEAVING)
                && !dir.with_file_name(folder).exists()
            {
                let _ = std::fs::remove_file(&dir);
            }
            continue;
        }
        if known.contains(&dir) {
            continue;
        }
        if leaving_marker(&dir).is_some_and(|marker| marker.is_file()) {
            delete_recording_folder(&dir);
            if !dir.exists() {
                result.removed.push(name);
            }
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
        let finished = UPLOAD_FILES.iter().any(|file| dir.join(file).is_file());
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
            if !finished {
                result.cut_short.push(name.clone());
            }
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
        assert_eq!(done.cut_short, vec!["cut-short".to_string()]);
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

    #[test]
    fn a_skipped_call_is_not_taken_for_one_cut_short() {
        let data = tempfile::tempdir().unwrap();
        let dir = recording(data.path(), "skipped", 3.0);
        let now = Utc::now();
        let decision = PendingDecision::recovered(dir.clone(), dir.join("recording.flac"), dir.join("mic.wav"), dir.join("system.wav"), 3_000, now, now, now);
        let call = crate::skipped::SkippedCall { decision, reason: crate::skipped::SkipReason::NoSpeech, skipped_at: now };
        crate::skipped::SkippedFiles::in_dir(data.path()).add(call).unwrap();

        assert_eq!(recover(data.path(), now + Duration::minutes(2)), Recovery::default());
        assert!(dir.join("mic.wav").is_file(), "its files wait for Restore");
        assert!(DecisionFiles::in_dir(data.path()).load().items.is_empty(), "it is not offered for Keep or Discard");
    }

    #[test]
    fn only_a_recording_stopped_in_the_middle_follows_after_a_call() {
        let data = tempfile::tempdir().unwrap();
        recording(data.path(), "cut-short", 3.0);
        let call = recording(data.path(), "finished-call", 3.0);
        std::fs::write(call.join("recording.flac"), b"fLaC").unwrap();
        let note = recording(data.path(), "finished-note", 3.0);
        std::fs::write(note.join("voice-note.flac"), b"fLaC").unwrap();

        let mut done = recover(data.path(), Utc::now() + Duration::minutes(2));
        done.recovered.sort();
        assert_eq!(done.recovered, ["cut-short", "finished-call", "finished-note"], "all of them wait in the Library");
        assert_eq!(done.cut_short, ["cut-short"], "one that already held its upload file is never kept by itself");
    }

    #[test]
    fn a_folder_marked_on_its_way_out_is_deleted_not_recovered() {
        let data = tempfile::tempdir().unwrap();
        let dir = recording(data.path(), "undone", 3.0);
        std::fs::write(dir.join("recording.flac"), b"fLaC").unwrap();
        // Undo marked it, then could not delete it; the marker of a folder that did go stayed behind.
        std::fs::write(leaving_marker(&dir).unwrap(), b"").unwrap();
        let stray = paths::recordings(data.path()).join(format!("gone{LEAVING}"));
        std::fs::write(&stray, b"").unwrap();

        let done = recover(data.path(), Utc::now());
        assert_eq!(done.removed, vec!["undone".to_string()]);
        assert!(done.recovered.is_empty());
        assert!(!dir.exists());
        assert!(std::fs::read_dir(paths::recordings(data.path())).unwrap().next().is_none(), "no marker is left either");
        assert!(DecisionFiles::in_dir(data.path()).load().items.is_empty());
    }

    #[test]
    fn a_folder_deleted_at_once_leaves_no_marker() {
        let data = tempfile::tempdir().unwrap();
        let dir = recording(data.path(), "done", 1.0);
        delete_recording_folder(&dir);
        delete_recording_folder(&dir); // twice: nothing left to delete
        delete_recording_folder(Path::new(""));
        assert!(std::fs::read_dir(paths::recordings(data.path())).unwrap().next().is_none());
    }

    /// The case behind the marker: a virus scanner holds a file of a recording while Discard deletes it.
    #[cfg(windows)]
    #[test]
    fn a_discarded_recording_that_could_not_be_deleted_goes_at_the_next_start() {
        use std::os::windows::fs::OpenOptionsExt;
        let data = tempfile::tempdir().unwrap();
        let dir = recording(data.path(), "discarded", 3.0);
        std::fs::write(dir.join("recording.flac"), b"fLaC").unwrap();
        let now = Utc::now();
        let files = DecisionFiles::in_dir(data.path());
        files.add(PendingDecision::recovered(dir.clone(), dir.join("recording.flac"), dir.join("mic.wav"), dir.join("system.wav"), 3_000, now, now, now)).unwrap();

        let held = std::fs::OpenOptions::new().read(true).share_mode(0).open(dir.join("mic.wav")).unwrap();
        files.discard("discarded").unwrap();
        assert!(dir.join("mic.wav").is_file(), "it could not be deleted now");
        drop(held);

        let done = recover(data.path(), now + Duration::minutes(2));
        assert_eq!(done.removed, vec!["discarded".to_string()]);
        assert!(done.recovered.is_empty(), "never offered again, nor uploaded under Keep automatically");
        assert!(!dir.exists());
        assert!(std::fs::read_dir(paths::recordings(data.path())).unwrap().next().is_none());
    }
}
