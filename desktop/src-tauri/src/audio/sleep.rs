//! Whether the computer slept: two of the system's clocks read together, one that stops while the computer sleeps and
//! one that runs on. Their difference only grows during sleep, so a recording tells a sleep from a long silence (no
//! packets from a side that is quiet) by this, never by missing packets alone.

/// One reading: time awake and time asleep, both since boot, in nanoseconds.
#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub struct Reading {
    pub awake_ns: u64,
    pub asleep_ns: u64,
}

/// Where the recorder reads the clocks; tests give it one they move by hand.
pub trait SleepClock: Send {
    fn read(&self) -> Reading;
}

/// The system's clocks. Windows: unbiased interrupt time (stops in sleep) and interrupt time (runs on). A Mac:
/// `CLOCK_UPTIME_RAW` (`mach_absolute_time`, stops in sleep) and `CLOCK_MONOTONIC_RAW` (`mach_continuous_time`, runs
/// on). Elsewhere no sleep is ever seen.
#[derive(Clone, Copy, Debug, Default)]
pub struct SystemClock;

impl SleepClock for SystemClock {
    fn read(&self) -> Reading {
        read_system()
    }
}

#[cfg(windows)]
fn read_system() -> Reading {
    use windows::Win32::System::WindowsProgramming::{QueryInterruptTime, QueryUnbiasedInterruptTime};
    let mut unbiased = 0u64;
    // Both in 100 ns units since boot; interrupt time also counts sleep and hibernation.
    let (ok, total) = unsafe { (QueryUnbiasedInterruptTime(&mut unbiased).as_bool(), QueryInterruptTime()) };
    if !ok {
        return Reading::default();
    }
    Reading { awake_ns: unbiased.saturating_mul(100), asleep_ns: total.saturating_sub(unbiased).saturating_mul(100) }
}

#[cfg(target_os = "macos")]
fn read_system() -> Reading {
    const CLOCK_MONOTONIC_RAW: u32 = 4;
    const CLOCK_UPTIME_RAW: u32 = 8;
    unsafe extern "C" {
        fn clock_gettime_nsec_np(clock_id: u32) -> u64;
    }
    let (awake, total) = unsafe { (clock_gettime_nsec_np(CLOCK_UPTIME_RAW), clock_gettime_nsec_np(CLOCK_MONOTONIC_RAW)) };
    Reading { awake_ns: awake, asleep_ns: total.saturating_sub(awake) }
}

#[cfg(not(any(windows, target_os = "macos")))]
fn read_system() -> Reading {
    use std::sync::OnceLock;
    static START: OnceLock<std::time::Instant> = OnceLock::new();
    Reading { awake_ns: START.get_or_init(std::time::Instant::now).elapsed().as_nanos() as u64, asleep_ns: 0 }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_system_clocks_read_awake_time_that_moves_and_no_sleep_in_a_moment() {
        let a = SystemClock.read();
        std::thread::sleep(std::time::Duration::from_millis(20));
        let b = SystemClock.read();
        assert!(b.awake_ns >= a.awake_ns + 15_000_000, "{a:?} {b:?}");
        assert!(b.asleep_ns.saturating_sub(a.asleep_ns) < 5_000_000, "the computer did not sleep: {a:?} {b:?}");
    }
}
