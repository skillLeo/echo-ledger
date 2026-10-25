//! App lock (plan B16): the PIN hash, wrong-PIN waits, the idle timer and the lock file. Pure.

use std::time::{Duration, Instant};

use desktop_lib::lock::{Attempts, LOCK_AFTER_CHOICES, Lock, LockFile, hash_pin, idle_expired, pin_is_valid, verify_pin};

#[test]
fn a_pin_is_four_to_twelve_digits() {
    assert!(pin_is_valid("1234") && pin_is_valid("123456789012"));
    assert!(!pin_is_valid("123") && !pin_is_valid("1234567890123") && !pin_is_valid("12a4") && !pin_is_valid("١٢٣٤"));
}

#[test]
fn the_pin_is_stored_salted_and_checked() {
    let a = hash_pin("2468");
    let b = hash_pin("2468");
    assert_ne!(a, b, "a fresh salt each time");
    assert!(a.starts_with("pbkdf2-sha256$210000$"));
    assert!(!a.contains("2468"));
    assert!(verify_pin(&a, "2468") && verify_pin(&b, "2468"));
    assert!(!verify_pin(&a, "2469"));
    for broken in ["", "2468", "md5$1$x$y", "pbkdf2-sha256$abc$AAAA$AAAA", "pbkdf2-sha256$1000$!!$AAAA"] {
        assert!(!verify_pin(broken, "2468"), "{broken}");
    }
}

#[test]
fn wrong_pins_make_the_next_try_wait_longer() {
    let now = Instant::now();
    let mut attempts = Attempts::default();
    for _ in 0..4 {
        attempts.fail(now);
        assert_eq!(attempts.wait(now), Duration::ZERO, "four free tries");
    }
    attempts.fail(now);
    assert_eq!(attempts.wait(now), Duration::from_secs(30));
    attempts.fail(now);
    assert_eq!(attempts.wait(now), Duration::from_secs(60));
    for _ in 0..10 {
        attempts.fail(now);
    }
    assert_eq!(attempts.wait(now), Duration::from_secs(300), "never more than five minutes");
    assert_eq!(attempts.wait(now + Duration::from_secs(301)), Duration::ZERO);
    attempts.succeed();
    assert_eq!(attempts, Attempts::default());
}

#[test]
fn an_idle_app_locks_after_the_chosen_minutes_only() {
    let start = Instant::now();
    let later = |m: u64| start + Duration::from_secs(m * 60);
    assert!(idle_expired(true, 5, start, later(5)));
    assert!(!idle_expired(true, 5, start, later(4)));
    assert!(!idle_expired(true, 0, start, later(600)), "0 = only at start");
    assert!(!idle_expired(false, 1, start, later(60)), "lock off");
    assert_eq!(LOCK_AFTER_CHOICES, [0, 1, 5, 15, 60]);
}

#[test]
fn locking_and_unlocking_resets_the_timer() {
    let lock = Lock::new(true);
    assert!(lock.is_locked());
    let before = lock.last_activity();
    std::thread::sleep(Duration::from_millis(5));
    lock.set_locked(false);
    assert!(!lock.is_locked() && lock.last_activity() > before);
}

#[test]
fn the_lock_file_keeps_only_the_hash() {
    let dir = tempfile::tempdir().unwrap();
    let path = dir.path().join("app_lock.json");
    assert_eq!(LockFile::load(&path), LockFile::default());
    let file = LockFile { pin_hash: Some(hash_pin("1357")) };
    file.save(&path).unwrap();
    assert_eq!(LockFile::load(&path), file);
    assert!(!std::fs::read_to_string(&path).unwrap().contains("1357"));
    std::fs::write(&path, "not json").unwrap();
    assert_eq!(LockFile::load(&path), LockFile::default());
}
