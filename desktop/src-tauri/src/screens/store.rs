//! Screens waiting for their upload, kept encrypted on this computer: one folder per recording under
//! `screens/<recording folder name>/` with `screens.json` (times, sizes and hashes, nothing readable of the picture)
//! and one sealed file per screen (ChaCha20-Poly1305, a random 32-byte key in the OS keychain, the recording and file
//! name as associated data). A screen is deleted once the server holds it; a recording's folder goes with it when the
//! recording is discarded, and a folder whose recording never reached the server is swept after a while.

use std::collections::HashSet;
use std::path::{Path, PathBuf};
use std::sync::{Mutex, MutexGuard};

use chrono::{DateTime, Duration, Utc};
use ring::aead::{Aad, CHACHA20_POLY1305, LessSafeKey, NONCE_LEN, Nonce, UnboundKey};
use ring::rand::{SecureRandom, SystemRandom};
use serde::{Deserialize, Serialize};

use crate::error::{AppError, AppResult};

/// First bytes of a sealed screen.
const MAGIC: &[u8; 4] = b"ELS1";
const MANIFEST: &str = "screens.json";
const SEALED_EXT: &str = "elsc";
/// Screens of a recording with no server session yet (still deciding, or discarded without a word) are kept this
/// long after their last change before a sweep may take them.
pub const ORPHAN_AFTER: Duration = Duration::minutes(10);
/// Screens the server never took (it was unreachable for days) are given up after this long.
pub const GIVE_UP_AFTER: Duration = Duration::days(7);

/// One store for the whole app: the capture thread adds, the upload worker sends and removes, discards forget.
static LOCK: Mutex<()> = Mutex::new(());

fn locked() -> MutexGuard<'static, ()> {
    LOCK.lock().unwrap_or_else(|p| p.into_inner())
}

/// The key screens are sealed with.
pub struct ScreenKey(LessSafeKey);

impl ScreenKey {
    pub fn from_bytes(bytes: &[u8; 32]) -> Self {
        Self(LessSafeKey::new(UnboundKey::new(&CHACHA20_POLY1305, bytes).expect("a 32-byte key")))
    }

    /// `ELS1`, a random nonce, then the sealed bytes and their tag. `aad` binds the file to its place.
    pub fn seal(&self, aad: &str, plain: &[u8]) -> AppResult<Vec<u8>> {
        let mut nonce = [0u8; NONCE_LEN];
        SystemRandom::new().fill(&mut nonce).map_err(|_| AppError::msg("No random numbers for the screen key."))?;
        let mut sealed = plain.to_vec();
        self.0
            .seal_in_place_append_tag(Nonce::assume_unique_for_key(nonce), Aad::from(aad.as_bytes()), &mut sealed)
            .map_err(|_| AppError::msg("A screen could not be sealed."))?;
        let mut out = Vec::with_capacity(MAGIC.len() + NONCE_LEN + sealed.len());
        out.extend_from_slice(MAGIC);
        out.extend_from_slice(&nonce);
        out.extend_from_slice(&sealed);
        Ok(out)
    }

    /// The plain bytes of a sealed file; an error when it was changed, moved or sealed with another key.
    pub fn open(&self, aad: &str, sealed: &[u8]) -> AppResult<Vec<u8>> {
        let unreadable = || AppError::msg("A stored screen could not be read.");
        if sealed.len() < MAGIC.len() + NONCE_LEN || &sealed[..MAGIC.len()] != MAGIC {
            return Err(unreadable());
        }
        let nonce: [u8; NONCE_LEN] = sealed[MAGIC.len()..MAGIC.len() + NONCE_LEN].try_into().map_err(|_| unreadable())?;
        let mut body = sealed[MAGIC.len() + NONCE_LEN..].to_vec();
        let plain = self.0.open_in_place(Nonce::assume_unique_for_key(nonce), Aad::from(aad.as_bytes()), &mut body).map_err(|_| unreadable())?;
        Ok(plain.to_vec())
    }
}

/// Where the screen key lives.
pub trait KeySource: Send + Sync {
    /// The key, made on first use.
    fn load_or_create(&self) -> Result<[u8; 32], String>;
}

/// The OS keychain (Windows Credential Manager / macOS Keychain), in an entry of its own beside the device token's.
pub struct KeychainKeys;

const KEY_SERVICE: &str = "com.echoledger.desktop.screens";
const KEY_USER: &str = "screens-key";

fn new_key() -> Result<[u8; 32], String> {
    let mut key = [0u8; 32];
    SystemRandom::new().fill(&mut key).map_err(|_| "no random numbers".to_string())?;
    Ok(key)
}

impl KeySource for KeychainKeys {
    fn load_or_create(&self) -> Result<[u8; 32], String> {
        use base64::Engine as _;
        let entry = keyring::Entry::new(KEY_SERVICE, KEY_USER).map_err(|e| e.to_string())?;
        match entry.get_password() {
            Ok(stored) => {
                let bytes = base64::engine::general_purpose::STANDARD.decode(stored.trim()).map_err(|e| e.to_string())?;
                bytes.try_into().map_err(|_| "the stored screen key has the wrong length".to_string())
            }
            Err(keyring::Error::NoEntry) => {
                let key = new_key()?;
                entry.set_password(&base64::engine::general_purpose::STANDARD.encode(key)).map_err(|e| e.to_string())?;
                Ok(key)
            }
            Err(e) => Err(e.to_string()),
        }
    }
}

/// A key in memory (tests).
#[derive(Default)]
pub struct MemoryKeys(pub Mutex<Option<[u8; 32]>>);

impl KeySource for MemoryKeys {
    fn load_or_create(&self) -> Result<[u8; 32], String> {
        let mut key = self.0.lock().unwrap_or_else(|p| p.into_inner());
        if key.is_none() {
            *key = Some(new_key()?);
        }
        Ok(key.expect("set above"))
    }
}

/// One stored screen (`screens.json`).
#[derive(Clone, Debug, PartialEq, Eq, Serialize, Deserialize)]
pub struct StoredScreen {
    /// The sealed file in the recording's folder (`0007.elsc`).
    pub file: String,
    /// The moment on the recording's clock (recorded time, pauses left out).
    pub at_ms: u64,
    pub width: u32,
    pub height: u32,
    /// `change` | `words` | `manual`.
    pub trigger: String,
    /// SHA-256 of the WebP image, so the server keeps no second copy of a screen sent twice.
    pub sha256: String,
    pub bytes: u64,
    pub taken_at: DateTime<Utc>,
}

/// `screens.json` of one recording.
#[derive(Clone, Debug, Serialize, Deserialize)]
pub struct Manifest {
    /// The recording's folder name (the id of its decision and upload).
    pub recording: String,
    /// The server session once the recording was kept and reached the server: the screens may go then.
    #[serde(default)]
    pub session_id: Option<String>,
    /// The session's project was checked for its screens switch (`screens: false` keeps nothing).
    #[serde(default)]
    pub project_checked: bool,
    pub created_at: DateTime<Utc>,
    pub updated_at: DateTime<Utc>,
    #[serde(default)]
    pub items: Vec<StoredScreen>,
    #[serde(default)]
    pub next_number: u32,
    /// Uploads that failed in a row (server busy or away) and when to try again.
    #[serde(default)]
    pub failures: u32,
    #[serde(default)]
    pub next_try_at: Option<DateTime<Utc>>,
}

impl Manifest {
    fn new(recording: &str, now: DateTime<Utc>) -> Self {
        Self { recording: recording.to_string(), session_id: None, project_checked: false, created_at: now, updated_at: now, items: Vec::new(), next_number: 1, failures: 0, next_try_at: None }
    }

    /// Waiting to be sent now: linked to a session, with screens, and not waiting out a failure.
    pub fn due(&self, now: DateTime<Utc>) -> bool {
        self.session_id.is_some() && !self.items.is_empty() && self.next_try_at.is_none_or(|at| at <= now)
    }
}

/// What a screen is sealed against: its recording and file name.
fn aad(recording: &str, file: &str) -> String {
    format!("{recording}/{file}")
}

/// A recording folder name that is safe as a path segment.
fn safe_name(name: &str) -> AppResult<&str> {
    let ok = !name.is_empty() && name.len() <= 128 && name.chars().all(|c| c.is_ascii_alphanumeric() || c == '-' || c == '_');
    ok.then_some(name).ok_or_else(|| AppError::msg("Not a recording."))
}

/// The screens folder of the app.
pub struct ScreenStore {
    root: PathBuf,
}

impl ScreenStore {
    pub fn in_dir(data_dir: &Path) -> Self {
        Self { root: data_dir.join("screens") }
    }

    fn dir(&self, recording: &str) -> AppResult<PathBuf> {
        Ok(self.root.join(safe_name(recording)?))
    }

    fn read_manifest(&self, recording: &str) -> Option<Manifest> {
        let path = self.dir(recording).ok()?.join(MANIFEST);
        std::fs::read(path).ok().and_then(|b| serde_json::from_slice(&b).ok())
    }

    fn write_manifest(&self, manifest: &Manifest) -> AppResult<()> {
        let dir = self.dir(&manifest.recording)?;
        std::fs::create_dir_all(&dir)?;
        let tmp = dir.join(format!("{MANIFEST}.tmp"));
        std::fs::write(&tmp, serde_json::to_vec_pretty(manifest)?)?;
        std::fs::rename(tmp, dir.join(MANIFEST))?;
        Ok(())
    }

    /// The manifest of one recording, if it has screens stored.
    pub fn load(&self, recording: &str) -> Option<Manifest> {
        let _guard = locked();
        self.read_manifest(recording)
    }

    /// Seals and stores one WebP screen of `recording`.
    #[allow(clippy::too_many_arguments)]
    pub fn add(&self, recording: &str, key: &ScreenKey, webp: &[u8], at_ms: u64, width: u32, height: u32, trigger: &str, now: DateTime<Utc>) -> AppResult<StoredScreen> {
        let _guard = locked();
        let mut manifest = self.read_manifest(recording).unwrap_or_else(|| Manifest::new(recording, now));
        let number = manifest.next_number.max(1);
        let file = format!("{number:04}.{SEALED_EXT}");
        let dir = self.dir(recording)?;
        std::fs::create_dir_all(&dir)?;
        std::fs::write(dir.join(&file), key.seal(&aad(recording, &file), webp)?)?;
        let screen = StoredScreen {
            file,
            at_ms,
            width,
            height,
            trigger: trigger.to_string(),
            sha256: crate::net::uploader::sha256_hex(webp),
            bytes: webp.len() as u64,
            taken_at: now,
        };
        manifest.items.push(screen.clone());
        manifest.next_number = number + 1;
        manifest.updated_at = now;
        self.write_manifest(&manifest)?;
        Ok(screen)
    }

    /// The WebP bytes of a stored screen.
    pub fn read(&self, recording: &str, screen: &StoredScreen, key: &ScreenKey) -> AppResult<Vec<u8>> {
        let sealed = std::fs::read(self.dir(recording)?.join(safe_file(&screen.file)?))?;
        key.open(&aad(recording, &screen.file), &sealed)
    }

    /// Removes one screen (sent, or refused for good). A linked recording whose last screen went loses its folder.
    pub fn remove(&self, recording: &str, file: &str) -> AppResult<()> {
        let _guard = locked();
        let Some(mut manifest) = self.read_manifest(recording) else { return Ok(()) };
        manifest.items.retain(|s| s.file != file);
        let _ = std::fs::remove_file(self.dir(recording)?.join(safe_file(file)?));
        if manifest.items.is_empty() && manifest.session_id.is_some() {
            drop(_guard);
            self.forget(recording);
            return Ok(());
        }
        manifest.updated_at = Utc::now();
        self.write_manifest(&manifest)
    }

    /// The recording reached the server as `session_id`: its screens may be sent. False when it has none stored.
    pub fn link(&self, recording: &str, session_id: &str) -> AppResult<bool> {
        let _guard = locked();
        let Some(mut manifest) = self.read_manifest(recording) else { return Ok(false) };
        if manifest.session_id.as_deref() == Some(session_id) {
            return Ok(true);
        }
        manifest.session_id = Some(session_id.to_string());
        manifest.project_checked = false;
        manifest.updated_at = Utc::now();
        self.write_manifest(&manifest)?;
        Ok(true)
    }

    /// Changes a manifest under the lock (the upload worker's checks and failures).
    pub fn update(&self, recording: &str, change: impl FnOnce(&mut Manifest)) -> AppResult<()> {
        let _guard = locked();
        let Some(mut manifest) = self.read_manifest(recording) else { return Ok(()) };
        change(&mut manifest);
        self.write_manifest(&manifest)
    }

    /// Deletes every screen of `recording` (discarded, deleted, or no longer wanted).
    pub fn forget(&self, recording: &str) {
        let _guard = locked();
        if let Ok(dir) = self.dir(recording)
            && dir.exists()
        {
            let _ = std::fs::remove_dir_all(dir);
        }
    }

    /// Every recording with a folder here.
    pub fn recordings(&self) -> Vec<String> {
        let Ok(entries) = std::fs::read_dir(&self.root) else { return Vec::new() };
        let mut names: Vec<String> = entries.flatten().filter(|e| e.path().is_dir()).filter_map(|e| e.file_name().to_str().map(str::to_string)).collect();
        names.sort();
        names
    }

    /// Screens stored for `recording`.
    pub fn count(&self, recording: &str) -> usize {
        self.load(recording).map_or(0, |m| m.items.len())
    }

    /// The recordings whose screens are due to be sent now, oldest first.
    pub fn due(&self, now: DateTime<Utc>) -> Vec<Manifest> {
        let _guard = locked();
        let mut due: Vec<Manifest> = self.recordings().iter().filter_map(|r| self.read_manifest(r)).filter(|m| m.due(now)).collect();
        due.sort_by_key(|m| m.created_at);
        due
    }

    /// Whether any screens wait for a server session or for their upload.
    pub fn holds_any(&self) -> bool {
        !self.recordings().is_empty()
    }

    /// Deletes what can never be sent: folders of recordings that are not waiting anywhere (`waiting`: the running
    /// recording and the ids of every decision, upload and skipped call) and have no server session, once untouched for
    /// [`ORPHAN_AFTER`]; folders without a readable manifest; and screens the server has not taken for
    /// [`GIVE_UP_AFTER`]. Returns the recordings swept.
    pub fn sweep(&self, waiting: &HashSet<String>, now: DateTime<Utc>) -> Vec<String> {
        let mut swept = Vec::new();
        for recording in self.recordings() {
            let gone = {
                let _guard = locked();
                match self.read_manifest(&recording) {
                    None => !waiting.contains(&recording) && self.untouched_since(&recording, now - ORPHAN_AFTER),
                    Some(m) if m.session_id.is_none() => !waiting.contains(&recording) && m.updated_at <= now - ORPHAN_AFTER,
                    Some(m) => m.created_at <= now - GIVE_UP_AFTER || m.items.is_empty(),
                }
            };
            if gone {
                self.forget(&recording);
                swept.push(recording);
            }
        }
        swept
    }

    /// The folder has not been written since `before` (a folder whose manifest was lost).
    fn untouched_since(&self, recording: &str, before: DateTime<Utc>) -> bool {
        let Ok(dir) = self.dir(recording) else { return true };
        let modified = std::fs::metadata(dir).and_then(|m| m.modified()).ok().map(DateTime::<Utc>::from);
        modified.is_none_or(|m| m <= before)
    }
}

/// A sealed file name as the store writes it.
fn safe_file(file: &str) -> AppResult<&str> {
    let ok = file.len() <= 32 && file.ends_with(SEALED_EXT) && file.chars().all(|c| c.is_ascii_alphanumeric() || c == '.');
    ok.then_some(file).ok_or_else(|| AppError::msg("Not a stored screen."))
}

#[cfg(test)]
mod tests {
    use super::*;

    fn key() -> ScreenKey {
        ScreenKey::from_bytes(&MemoryKeys::default().load_or_create().unwrap())
    }

    /// Minutes from now (folders on disk carry the real time).
    fn at(minutes: i64) -> DateTime<Utc> {
        Utc::now() + Duration::minutes(minutes)
    }

    const WEBP: &[u8] = b"RIFF\x10\0\0\0WEBPVP8 fake picture bytes";

    #[test]
    fn screens_are_sealed_on_disk_and_read_back_with_the_key() {
        let dir = tempfile::tempdir().unwrap();
        let store = ScreenStore::in_dir(dir.path());
        let key = key();
        let screen = store.add("rec-1", &key, WEBP, 872_000, 1920, 1080, "change", at(0)).unwrap();
        assert_eq!(screen.file, "0001.elsc");
        assert_eq!(screen.sha256, crate::net::uploader::sha256_hex(WEBP));
        let on_disk = std::fs::read(dir.path().join("screens").join("rec-1").join("0001.elsc")).unwrap();
        assert!(on_disk.starts_with(b"ELS1"));
        assert!(!on_disk.windows(4).any(|w| w == b"WEBP"), "nothing of the picture is readable on disk");
        assert_eq!(store.read("rec-1", &screen, &key).unwrap(), WEBP);
        // Another key, or the file moved to another recording, cannot be read.
        assert!(store.read("rec-1", &screen, &self::key()).is_err());
        assert!(key.open(&aad("rec-2", "0001.elsc"), &on_disk).is_err());
        let manifest = store.load("rec-1").unwrap();
        assert_eq!((manifest.items.len(), manifest.next_number, manifest.session_id), (1, 2, None));
    }

    #[test]
    fn a_tampered_file_is_refused() {
        let key = key();
        let mut sealed = key.seal("r/f", b"hello").unwrap();
        assert_eq!(key.open("r/f", &sealed).unwrap(), b"hello");
        let last = sealed.len() - 1;
        sealed[last] ^= 1;
        assert!(key.open("r/f", &sealed).is_err());
        assert!(key.open("r/f", b"ELS1").is_err());
        assert!(key.open("r/f", b"nonsense bytes that are long enough").is_err());
    }

    #[test]
    fn screens_wait_until_the_recording_reaches_the_server_and_go_one_by_one() {
        let dir = tempfile::tempdir().unwrap();
        let store = ScreenStore::in_dir(dir.path());
        let key = key();
        store.add("rec-1", &key, WEBP, 1_000, 10, 10, "change", at(0)).unwrap();
        store.add("rec-1", &key, b"second", 2_000, 10, 10, "words", at(1)).unwrap();
        assert!(store.due(at(2)).is_empty(), "no session yet");
        assert!(store.link("rec-1", "01JSESSION").unwrap());
        assert!(!store.link("rec-unknown", "01JX").unwrap());
        let due = store.due(at(2));
        assert_eq!(due.len(), 1);
        assert_eq!(due[0].session_id.as_deref(), Some("01JSESSION"));
        store.remove("rec-1", "0001.elsc").unwrap();
        assert_eq!(store.count("rec-1"), 1);
        assert!(!dir.path().join("screens/rec-1/0001.elsc").exists());
        store.remove("rec-1", "0002.elsc").unwrap();
        assert!(!dir.path().join("screens/rec-1").exists(), "the last screen sent takes the folder");
    }

    #[test]
    fn a_failure_holds_the_recording_until_its_time() {
        let dir = tempfile::tempdir().unwrap();
        let store = ScreenStore::in_dir(dir.path());
        store.add("rec-1", &key(), WEBP, 1_000, 10, 10, "change", at(0)).unwrap();
        store.link("rec-1", "s1").unwrap();
        store.update("rec-1", |m| m.next_try_at = Some(at(5))).unwrap();
        assert!(store.due(at(4)).is_empty());
        assert_eq!(store.due(at(5)).len(), 1);
    }

    #[test]
    fn forget_deletes_every_screen_of_a_recording() {
        let dir = tempfile::tempdir().unwrap();
        let store = ScreenStore::in_dir(dir.path());
        store.add("rec-1", &key(), WEBP, 1_000, 10, 10, "change", at(0)).unwrap();
        store.add("rec-2", &key(), WEBP, 1_000, 10, 10, "change", at(0)).unwrap();
        store.forget("rec-1");
        assert_eq!(store.recordings(), vec!["rec-2".to_string()]);
        store.forget("../rec-2"); // never outside the folder
        assert_eq!(store.recordings(), vec!["rec-2".to_string()]);
    }

    #[test]
    fn the_sweep_takes_only_what_can_never_be_sent() {
        let dir = tempfile::tempdir().unwrap();
        let store = ScreenStore::in_dir(dir.path());
        let key = key();
        for r in ["waiting", "orphan", "fresh-orphan", "linked", "stale-linked"] {
            store.add(r, &key, WEBP, 1_000, 10, 10, "change", at(-60)).unwrap();
        }
        store.update("fresh-orphan", |m| m.updated_at = at(-5)).unwrap();
        store.link("linked", "s1").unwrap();
        store.link("stale-linked", "s2").unwrap();
        store.update("stale-linked", |m| m.created_at = at(-8 * 24 * 60)).unwrap();
        std::fs::create_dir_all(dir.path().join("screens/no-manifest")).unwrap();
        let waiting: HashSet<String> = ["waiting".to_string()].into();
        let mut swept = store.sweep(&waiting, at(0));
        swept.sort();
        // A folder without a manifest was made just now, so it waits out the orphan time like any other.
        assert_eq!(swept, vec!["orphan".to_string(), "stale-linked".to_string()]);
        assert_eq!(store.recordings(), vec!["fresh-orphan".to_string(), "linked".to_string(), "no-manifest".to_string(), "waiting".to_string()]);
    }

    #[test]
    fn names_that_are_not_recordings_or_screens_are_refused() {
        let dir = tempfile::tempdir().unwrap();
        let store = ScreenStore::in_dir(dir.path());
        assert!(store.add("../evil", &key(), WEBP, 0, 1, 1, "change", at(0)).is_err());
        assert!(store.add("", &key(), WEBP, 0, 1, 1, "change", at(0)).is_err());
        assert!(safe_file("../../x.elsc").is_err());
        assert!(safe_file("0001.elsc").is_ok());
    }
}
