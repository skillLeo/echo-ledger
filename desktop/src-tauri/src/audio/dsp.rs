//! Small, allocation-light DSP helpers (pure; unit-tested in Phase 4).

/// Converts f32 [-1, 1] to i16 with clipping.
pub fn f32_to_i16(sample: f32) -> i16 {
    let clamped = sample.clamp(-1.0, 1.0);
    if clamped >= 0.0 { (clamped * i16::MAX as f32).round() as i16 } else { (clamped * 32768.0).round() as i16 }
}

/// Averages interleaved frames down to mono.
pub fn downmix_to_mono(interleaved: &[f32], channels: usize) -> Vec<f32> {
    if channels <= 1 {
        return interleaved.to_vec();
    }
    interleaved.chunks_exact(channels).map(|frame| frame.iter().sum::<f32>() / channels as f32).collect()
}

/// Pads the shorter channel with silence so both have equal length (acceptance: equal-length mic/system files).
pub fn pad_to_equal(a: &mut Vec<f32>, b: &mut Vec<f32>) {
    let len = a.len().max(b.len());
    a.resize(len, 0.0);
    b.resize(len, 0.0);
}

/// Interleaves two mono channels as L = mic ("Me"), R = system ("Client").
pub fn interleave(left: &[f32], right: &[f32]) -> Vec<f32> {
    let len = left.len().max(right.len());
    let mut out = Vec::with_capacity(len * 2);
    for i in 0..len {
        out.push(left.get(i).copied().unwrap_or(0.0));
        out.push(right.get(i).copied().unwrap_or(0.0));
    }
    out
}

/// Root-mean-square level, used for the UI level meter and silence detection.
pub fn rms(samples: &[f32]) -> f32 {
    if samples.is_empty() {
        return 0.0;
    }
    (samples.iter().map(|s| s * s).sum::<f32>() / samples.len() as f32).sqrt()
}

/// Replaces NaN and ±infinity with silence.
pub fn finite_or_zero(sample: f32) -> f32 {
    if sample.is_finite() { sample } else { 0.0 }
}

/// Streaming resampler: one-pole low-pass (anti-aliasing for downsampling) + linear interpolation.
/// Good enough for speech recognition; WASAPI autoconvert usually delivers 16 kHz already.
#[derive(Clone, Debug)]
pub struct Resampler {
    ratio: f64, // input frames per output frame
    pos: f64,   // fractional read position into `history`
    last: f32,
    lp_state: f32,
    lp_alpha: f32,
}

impl Resampler {
    pub fn new(input_rate: u32, output_rate: u32) -> Self {
        let ratio = input_rate as f64 / output_rate as f64;
        // Cutoff ≈ 0.45 × output Nyquist when downsampling; pass-through otherwise.
        let lp_alpha = if ratio > 1.0 {
            let cutoff = 0.45 * output_rate as f64;
            let rc = 1.0 / (2.0 * std::f64::consts::PI * cutoff);
            let dt = 1.0 / input_rate as f64;
            (dt / (rc + dt)) as f32
        } else {
            1.0
        };
        Self { ratio, pos: 1.0, last: 0.0, lp_state: 0.0, lp_alpha }
    }

    pub fn is_passthrough(&self) -> bool {
        (self.ratio - 1.0).abs() < 1e-9
    }

    pub fn process(&mut self, input: &[f32]) -> Vec<f32> {
        if self.is_passthrough() {
            return input.to_vec(); // no filter state to poison; the writer maps NaN to 0 (`f32_to_i16`)
        }
        // A NaN or infinite sample from a driver would stay in the filter state for ever (A6.DR14): treat it as 0.0.
        let filtered: Vec<f32> = input
            .iter()
            .map(|&x| {
                self.lp_state += self.lp_alpha * (finite_or_zero(x) - self.lp_state);
                self.lp_state
            })
            .collect();
        let n = filtered.len();
        if n == 0 {
            return Vec::new();
        }
        // Extended input: index 0 = last sample of the previous block, index k (1..=n) = filtered[k - 1].
        let last = self.last;
        let at = |k: usize| -> f32 { if k == 0 { last } else { filtered[k - 1] } };
        let mut out = Vec::with_capacity((n as f64 / self.ratio) as usize + 2);
        while self.pos < n as f64 {
            let k = self.pos.floor() as usize;
            let frac = (self.pos - k as f64) as f32;
            let (a, b) = (at(k), at(k + 1));
            out.push(a + (b - a) * frac);
            self.pos += self.ratio;
        }
        self.pos -= n as f64;
        self.last = filtered[n - 1];
        out
    }
}
