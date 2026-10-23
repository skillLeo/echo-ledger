//! Timestamp-anchored placement of packets on a per-channel timeline (docs/research/desktop-audio-native.md §6).
//! Fills gaps with silence (endpoint loopback sends nothing while silent, device reopen leaves holes) and drops
//! overlaps, so both channels stay sample-aligned to a shared T0.

/// What to do with an incoming packet relative to the channel's write cursor.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct Placement {
    /// Zero frames to write before the packet.
    pub pad: usize,
    /// Frames to drop from the head of the packet (already covered).
    pub skip: usize,
    /// Frames of the packet to append after skipping.
    pub take: usize,
}

/// Converts a nanosecond delta to frames at `rate`.
pub fn nanos_to_frames(delta_ns: u64, rate: u32) -> u64 {
    ((delta_ns as u128 * rate as u128 + 500_000_000) / 1_000_000_000) as u64
}

/// Places a packet of `len` frames whose first frame belongs at `target`, given the current write `cursor`.
/// Differences within `tolerance` frames are absorbed (jitter) and the packet is appended as-is.
pub fn place_packet(cursor: u64, target: u64, len: usize, tolerance: u64) -> Placement {
    if target > cursor + tolerance {
        Placement { pad: (target - cursor) as usize, skip: 0, take: len }
    } else if target + tolerance < cursor {
        let skip = ((cursor - target) as usize).min(len);
        Placement { pad: 0, skip, take: len - skip }
    } else {
        Placement { pad: 0, skip: 0, take: len }
    }
}

/// One channel's timeline: shared T0 (set by whichever channel speaks first) and a write cursor in frames.
#[derive(Clone, Debug)]
pub struct Timeline {
    rate: u32,
    tolerance: u64,
    cursor: u64,
}

impl Timeline {
    pub fn new(rate: u32) -> Self {
        // ~5 ms tolerance absorbs scheduling jitter without audible artifacts.
        Self { rate, tolerance: (rate / 200) as u64, cursor: 0 }
    }

    pub fn cursor(&self) -> u64 {
        self.cursor
    }

    /// Returns where to put `len` frames stamped `ts_ns` (relative to the shared `t0_ns`).
    /// Packets without timestamps are appended at the cursor.
    pub fn place(&mut self, t0_ns: u64, ts_ns: Option<u64>, len: usize) -> Placement {
        let placement = match ts_ns {
            Some(ts) if ts >= t0_ns => place_packet(self.cursor, nanos_to_frames(ts - t0_ns, self.rate), len, self.tolerance),
            Some(_) => place_packet(self.cursor, 0, len, self.tolerance),
            None => Placement { pad: 0, skip: 0, take: len },
        };
        self.cursor += (placement.pad + placement.take) as u64;
        placement
    }

    /// Advances the cursor by explicitly written frames (e.g. trailing padding at stop).
    pub fn advance(&mut self, frames: u64) {
        self.cursor += frames;
    }
}
