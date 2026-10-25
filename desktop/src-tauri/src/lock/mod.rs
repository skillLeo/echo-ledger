//! App lock (plan B16): Windows Hello or Touch ID, with a PIN as the fallback. Locking only hides the app; a running
//! recording, captions and uploads carry on. The PIN is stored as a salted PBKDF2 hash in `app_lock.json`, never in
//! the settings the UI reads, and repeated wrong PINs make the next try wait.

pub mod os;

use std::num::NonZeroU32;
use std::path::Path;
use std::sync::Mutex;
use std::sync::atomic::{AtomicBool, Ordering};
use std::time::{Duration, Instant};

use base64::Engine;
use base64::engine::general_purpose::STANDARD_NO_PAD;
use ring::rand::{SecureRandom, SystemRandom};
use serde::{Deserialize, Serialize};

/// Lock-after choices in minutes; 0 = only when the app starts or "Lock now" is used.
pub const LOCK_AFTER_CHOICES: [u32; 5] = [0, 1, 5, 15, 60];
const PBKDF2_ITERATIONS: u32 = 210_000;
/// Free tries before the waits start, then 30 s doubling up to 5 minutes.
const FREE_TRIES: u32 = 5;
const FIRST_WAIT: Duration = Duration::from_secs(30);
const MAX_WAIT: Duration = Duration::from_secs(300);

/// A PIN is 4 to 12 digits.
pub fn pin_is_valid(pin: &str) -> bool {
    (4..=12).contains(&pin.len()) && pin.bytes().all(|b| b.is_ascii_digit())
}

/// `pbkdf2-sha256$<iterations>$<salt>$<hash>` (base64 without padding).
pub fn hash_pin(pin: &str) -> String {
    let mut salt = [0u8; 16];
    SystemRandom::new().fill(&mut salt).expect("system random");
    let mut out = [0u8; 32];
    ring::pbkdf2::derive(ring::pbkdf2::PBKDF2_HMAC_SHA256, iterations(PBKDF2_ITERATIONS), &salt, pin.as_bytes(), &mut out);
    format!("pbkdf2-sha256${PBKDF2_ITERATIONS}${}${}", STANDARD_NO_PAD.encode(salt), STANDARD_NO_PAD.encode(out))
}

/// Constant-time check of a PIN against a stored hash; a malformed hash never matches.
pub fn verify_pin(stored: &str, pin: &str) -> bool {
    let parts: Vec<&str> = stored.split('$').collect();
    let [scheme, rounds, salt, hash] = parts.as_slice() else { return false };
    if *scheme != "pbkdf2-sha256" {
        return false;
    }
    let (Ok(rounds), Ok(salt), Ok(hash)) = (rounds.parse::<u32>(), STANDARD_NO_PAD.decode(salt), STANDARD_NO_PAD.decode(hash)) else { return false };
    ring::pbkdf2::verify(ring::pbkdf2::PBKDF2_HMAC_SHA256, iterations(rounds), &salt, pin.as_bytes(), &hash).is_ok()
}

fn iterations(n: u32) -> NonZeroU32 {
    NonZeroU32::new(n.max(1)).unwrap_or(NonZeroU32::MIN)
}

/// Wrong-PIN bookkeeping: free tries, then waits that double.
#[derive(Debug, Default, Clone, PartialEq, Eq)]
pub struct Attempts {
    pub failures: u32,
    pub until: Option<Instant>,
}

impl Attempts {
    /// How long the next try must wait; zero when it may go ahead.
    pub fn wait(&self, now: Instant) -> Duration {
        self.until.map(|u| u.saturating_duration_since(now)).unwrap_or_default()
    }

    pub fn fail(&mut self, now: Instant) {
        self.failures += 1;
        if self.failures >= FREE_TRIES {
            let doublings = (self.failures - FREE_TRIES).min(10);
            let wait = FIRST_WAIT.saturating_mul(1 << doublings).min(MAX_WAIT);
            self.until = Some(now + wait);
        }
    }

    pub fn succeed(&mut self) {
        *self = Self::default();
    }
}

/// Should an unlocked app lock now? Never when the lock is off or set to "only at start".
pub fn idle_expired(enabled: bool, lock_after_minutes: u32, last_activity: Instant, now: Instant) -> bool {
    enabled && lock_after_minutes > 0 && now.saturating_duration_since(last_activity) >= Duration::from_secs(u64::from(lock_after_minutes) * 60)
}

/// `app_lock.json`: the PIN hash.
#[derive(Clone, Debug, Default, PartialEq, Eq, Serialize, Deserialize)]
pub struct LockFile {
    #[serde(default)]
    pub pin_hash: Option<String>,
}

impl LockFile {
    pub fn load(path: &Path) -> Self {
        std::fs::read(path).ok().and_then(|b| serde_json::from_slice(&b).ok()).unwrap_or_default()
    }

    pub fn save(&self, path: &Path) -> std::io::Result<()> {
        let tmp = path.with_extension("json.tmp");
        std::fs::write(&tmp, serde_json::to_vec_pretty(self)?)?;
        std::fs::rename(tmp, path)
    }
}

/// The live lock state of this run.
pub struct Lock {
    locked: AtomicBool,
    pub attempts: Mutex<Attempts>,
    last_activity: Mutex<Instant>,
}

impl Lock {
    pub fn new(locked: bool) -> Self {
        Self { locked: AtomicBool::new(locked), attempts: Mutex::new(Attempts::default()), last_activity: Mutex::new(Instant::now()) }
    }

    pub fn is_locked(&self) -> bool {
        self.locked.load(Ordering::SeqCst)
    }

    pub fn set_locked(&self, locked: bool) {
        self.locked.store(locked, Ordering::SeqCst);
        if !locked {
            self.touch();
        }
    }

    pub fn touch(&self) {
        *self.last_activity.lock().unwrap_or_else(|p| p.into_inner()) = Instant::now();
    }

    pub fn last_activity(&self) -> Instant {
        *self.last_activity.lock().unwrap_or_else(|p| p.into_inner())
    }
}
