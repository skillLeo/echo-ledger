//! Deterministic synthetic two-channel source: tones on each channel, a silence gap on the system channel
//! (like endpoint loopback sending no packets), slight clock drift and timestamp jitter.
//! Drives the acceptance test "mocked 2-channel capture → aligned mic.wav + system.wav of equal length".

use super::types::{AudioChunk, Channel, TARGET_RATE};

pub struct MockSource {
    /// Packet length in frames (10 ms).
    pub packet: usize,
    pub duration_ms: u64,
    /// System-channel clock drift in parts per million.
    pub drift_ppm: i64,
    /// [start, end) milliseconds during which the system channel sends no packets.
    pub system_gap_ms: (u64, u64),
    pub t0_ns: u64,
}

impl Default for MockSource {
    fn default() -> Self {
        Self { packet: (TARGET_RATE / 100) as usize, duration_ms: 3_000, drift_ppm: 80, system_gap_ms: (1_000, 1_600), t0_ns: 5_000_000_000 }
    }
}

impl MockSource {
    /// All packets for both channels, interleaved in arrival order.
    pub fn chunks(&self) -> Vec<AudioChunk> {
        let mut out = Vec::new();
        let packets = (self.duration_ms * TARGET_RATE as u64 / 1000) / self.packet as u64;
        for p in 0..packets {
            let start_frame = p * self.packet as u64;
            let start_ns = self.t0_ns + start_frame * 1_000_000_000 / TARGET_RATE as u64;
            out.push(AudioChunk { channel: Channel::Mic, samples: tone(start_frame, self.packet, 330.0), ts_ns: Some(start_ns), discontinuity: false });

            let ms = start_frame * 1000 / TARGET_RATE as u64;
            if ms >= self.system_gap_ms.0 && ms < self.system_gap_ms.1 {
                continue; // no packets while "nothing plays"
            }
            let drift = (start_ns - self.t0_ns) as i128 * self.drift_ppm as i128 / 1_000_000;
            let jitter = ((p % 7) as i128 - 3) * 200_000; // ±0.6 ms
            let sys_ns = (start_ns as i128 + drift + jitter) as u64;
            out.push(AudioChunk { channel: Channel::System, samples: tone(start_frame, self.packet, 440.0), ts_ns: Some(sys_ns), discontinuity: false });
        }
        out
    }
}

fn tone(start_frame: u64, len: usize, freq: f32) -> Vec<f32> {
    (0..len)
        .map(|i| {
            let n = (start_frame + i as u64) as f32;
            0.25 * (2.0 * std::f32::consts::PI * freq * n / TARGET_RATE as f32).sin()
        })
        .collect()
}
