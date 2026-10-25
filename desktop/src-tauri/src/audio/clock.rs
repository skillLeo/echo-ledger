//! Recording clock: wall time since the start minus the time spent paused. Paused audio is neither captured nor
//! streamed, so the file, the pill timer and caption times all follow this clock (A3.3, A5.3).

use std::time::{Duration, Instant};

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct RecordingClock {
    pub started: Instant,
    pub paused_total: Duration,
    pub paused_at: Option<Instant>,
}

impl RecordingClock {
    pub fn new(started: Instant) -> Self {
        Self { started, paused_total: Duration::ZERO, paused_at: None }
    }

    /// Stops the clock at `now`; pausing while paused changes nothing.
    pub fn pause(&mut self, now: Instant) {
        if self.paused_at.is_none() {
            self.paused_at = Some(now);
        }
    }

    /// Runs the clock again from `now`; resuming while running changes nothing.
    pub fn resume(&mut self, now: Instant) {
        if let Some(at) = self.paused_at.take() {
            self.paused_total += now.saturating_duration_since(at);
        }
    }

    pub fn is_paused(&self) -> bool {
        self.paused_at.is_some()
    }

    /// Recorded time at `now`, paused spans excluded (frozen while paused).
    pub fn elapsed_ms(&self, now: Instant) -> u64 {
        let end = self.paused_at.unwrap_or(now);
        let paused = self.paused_total;
        end.saturating_duration_since(self.started).saturating_sub(paused).as_millis() as u64
    }
}
