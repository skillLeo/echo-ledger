//! When a screen is worth keeping (pure; unit-tested with synthetic frames). Every sampled frame is reduced to 64×36
//! luminance cells and a 64-bit perceptual hash. A change beyond [`CHANGE_SHARE`] of the cells since the last saved
//! screen that then stays settled for [`SETTLE_MS`] keeps the frame; a frame whose hash is within [`NEAR_BITS`] of the
//! last saved screen is the same screen and is dropped (unless a fifth of its cells moved, which the coarse hash of
//! one slide template can miss). Pointing words in the captions ("look at this page") keep the current frame at once
//! when it differs from the last saved one. At most [`CAP_PER_HOUR`] screens an hour.

use std::collections::VecDeque;

/// Luminance grid width and height (16:9, like most shared screens).
pub const LUMA_W: usize = 64;
pub const LUMA_H: usize = 36;
const CELLS: usize = LUMA_W * LUMA_H;

/// A cell moved when its brightness changed by more than this (of 255).
pub const CELL_DELTA: u8 = 24;
/// Two samples in a row within this share of moved cells count as settled (video tiles beside a shared screen move a
/// little all the time).
pub const STABLE_SHARE: f32 = 0.05;
/// A new screen needs this share of cells moved since the last saved one.
pub const CHANGE_SHARE: f32 = 0.06;
/// Pointing words lower the bar: the person says the screen matters now.
pub const WORDS_SHARE: f32 = 0.02;
/// Hashes this close to the last saved screen's are the same screen.
pub const NEAR_BITS: u32 = 5;
/// Unless this share of the cells moved: slides of one template keep a close hash when only their text changed.
pub const STRONG_SHARE: f32 = 0.20;
/// How long a changed picture must stay settled before it is kept.
pub const SETTLE_MS: u64 = 1_500;
/// Screens kept per rolling hour at most.
pub const CAP_PER_HOUR: usize = 120;
const HOUR_MS: u64 = 3_600_000;
/// A frame whose cells hardly differ from their mean (a black window while a meeting loads) shows nothing.
const BLANK_SPREAD: f32 = 4.0;

/// A frame reduced to 64×36 luminance cells (0-255), all the detector looks at.
#[derive(Clone, PartialEq, Eq)]
pub struct Luma(Box<[u8; CELLS]>);

impl std::fmt::Debug for Luma {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        write!(f, "Luma(mean {:.1})", self.mean())
    }
}

/// Rec. 601 luminance of one BGRA pixel, in integer steps.
fn luminance(b: u8, g: u8, r: u8) -> u32 {
    (77 * r as u32 + 150 * g as u32 + 29 * b as u32) >> 8
}

impl Luma {
    /// Builds the grid cell by cell (tests draw synthetic frames this way).
    pub fn from_fn(f: impl Fn(usize, usize) -> u8) -> Self {
        let mut cells = Box::new([0u8; CELLS]);
        for y in 0..LUMA_H {
            for x in 0..LUMA_W {
                cells[y * LUMA_W + x] = f(x, y);
            }
        }
        Self(cells)
    }

    /// Area-averages a BGRA frame (rows `stride` bytes apart) into the grid. Large cells are sampled on a grid of at
    /// most 16 × 16 pixels, so a 4K frame costs about as much as a small one. `None` for a frame smaller than the grid
    /// or a buffer too short for its size.
    pub fn from_bgra(pixels: &[u8], width: usize, height: usize, stride: usize) -> Option<Self> {
        if width < LUMA_W || height < LUMA_H || stride < width * 4 || pixels.len() < stride * (height - 1) + width * 4 {
            return None;
        }
        let mut cells = Box::new([0u8; CELLS]);
        for cy in 0..LUMA_H {
            let (y0, y1) = (cy * height / LUMA_H, ((cy + 1) * height / LUMA_H).max(cy * height / LUMA_H + 1));
            let step_y = ((y1 - y0) / 16).max(1);
            for cx in 0..LUMA_W {
                let (x0, x1) = (cx * width / LUMA_W, ((cx + 1) * width / LUMA_W).max(cx * width / LUMA_W + 1));
                let step_x = ((x1 - x0) / 16).max(1);
                let (mut sum, mut n) = (0u32, 0u32);
                for y in (y0..y1).step_by(step_y) {
                    let row = y * stride;
                    for x in (x0..x1).step_by(step_x) {
                        let p = row + x * 4;
                        sum += luminance(pixels[p], pixels[p + 1], pixels[p + 2]);
                        n += 1;
                    }
                }
                cells[cy * LUMA_W + cx] = (sum / n.max(1)) as u8;
            }
        }
        Some(Self(cells))
    }

    pub fn cell(&self, x: usize, y: usize) -> u8 {
        self.0[y * LUMA_W + x]
    }

    fn mean(&self) -> f32 {
        self.0.iter().map(|&v| v as f32).sum::<f32>() / CELLS as f32
    }

    /// Share (0-1) of cells whose brightness moved by more than [`CELL_DELTA`] between the two frames.
    pub fn changed_share(&self, other: &Self) -> f32 {
        let moved = self.0.iter().zip(other.0.iter()).filter(|(a, b)| a.abs_diff(**b) > CELL_DELTA).count();
        moved as f32 / CELLS as f32
    }

    /// Nothing on it: every cell close to the frame's mean brightness.
    pub fn is_blank(&self) -> bool {
        let mean = self.mean();
        let spread = (self.0.iter().map(|&v| (v as f32 - mean).powi(2)).sum::<f32>() / CELLS as f32).sqrt();
        spread < BLANK_SPREAD
    }

    /// 64-bit perceptual hash: the 8 × 8 lowest frequencies of the grid's cosine transform, one bit per coefficient
    /// above their median. Small shifts, scaling and brightness changes keep most bits; another picture flips about
    /// half of them.
    pub fn hash(&self) -> u64 {
        let cos_x = cos_table::<LUMA_W>();
        let cos_y = cos_table::<LUMA_H>();
        // Rows first: for each grid row, the 8 lowest horizontal frequencies.
        let mut rows = [[0f32; 8]; LUMA_H];
        for (y, row) in rows.iter_mut().enumerate() {
            for (u, out) in row.iter_mut().enumerate() {
                *out = (0..LUMA_W).map(|x| self.0[y * LUMA_W + x] as f32 * cos_x[u][x]).sum();
            }
        }
        let mut coefficients = [0f32; 64];
        for v in 0..8 {
            for u in 0..8 {
                coefficients[v * 8 + u] = (0..LUMA_H).map(|y| rows[y][u] * cos_y[v][y]).sum();
            }
        }
        // The median of the 63 alternating coefficients (the average brightness says nothing about the picture).
        let mut ac: Vec<f32> = coefficients[1..].to_vec();
        ac.sort_by(f32::total_cmp);
        let median = ac[ac.len() / 2];
        coefficients.iter().enumerate().fold(0u64, |hash, (i, &c)| if c > median { hash | (1 << i) } else { hash })
    }
}

/// `cos(π (2x + 1) u / 2N)` for the 8 lowest frequencies `u` of an `N`-point transform.
fn cos_table<const N: usize>() -> [[f32; N]; 8] {
    let mut table = [[0f32; N]; 8];
    for (u, row) in table.iter_mut().enumerate() {
        for (x, value) in row.iter_mut().enumerate() {
            *value = (std::f32::consts::PI * (2 * x + 1) as f32 * u as f32 / (2 * N) as f32).cos();
        }
    }
    table
}

/// Bits that differ between two hashes.
pub fn hamming(a: u64, b: u64) -> u32 {
    (a ^ b).count_ones()
}

/// Why a screen was kept (`trigger` of `POST /api/sessions/{id}/screens`).
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Trigger {
    /// The picture changed and settled.
    Change,
    /// Someone said "this page", "click here", "yeh dekhein" …
    Words,
}

impl Trigger {
    pub fn as_str(self) -> &'static str {
        match self {
            Trigger::Change => "change",
            Trigger::Words => "words",
        }
    }
}

/// The detector of one recording. Times are milliseconds of any monotonic clock.
#[derive(Debug, Default)]
pub struct Detector {
    /// The previous sample, for settling.
    previous: Option<Luma>,
    /// Since when the samples have stayed settled.
    settled_since: Option<u64>,
    /// The last saved screen and its hash.
    saved: Option<(Luma, u64)>,
    /// When the screens of the last hour were saved.
    saved_at: VecDeque<u64>,
}

impl Detector {
    pub fn new() -> Self {
        Self::default()
    }

    /// The picture may jump (another window, a pause): settling starts again, the last saved screen stays known so
    /// coming back to the same picture saves nothing.
    pub fn restart(&mut self) {
        self.previous = None;
        self.settled_since = None;
    }

    /// The hour's cap is reached: nothing more is saved until the oldest of the hour is an hour old.
    pub fn capped(&mut self, now_ms: u64) -> bool {
        while self.saved_at.front().is_some_and(|&at| now_ms.saturating_sub(at) >= HOUR_MS) {
            self.saved_at.pop_front();
        }
        self.saved_at.len() >= CAP_PER_HOUR
    }

    /// Screens saved in the last hour.
    pub fn saved_this_hour(&mut self, now_ms: u64) -> usize {
        self.capped(now_ms);
        self.saved_at.len()
    }

    /// Looks at one sample. `words`: pointing words were heard since the last sample. `Some` means keep this frame
    /// now (it is remembered as the last saved screen).
    pub fn observe(&mut self, now_ms: u64, frame: &Luma, words: bool) -> Option<Trigger> {
        let hash = frame.hash();
        let settled = self.previous.as_ref().is_some_and(|p| p.changed_share(frame) <= STABLE_SHARE);
        if !settled {
            self.settled_since = Some(now_ms);
        }
        let since = *self.settled_since.get_or_insert(now_ms);
        self.previous = Some(frame.clone());
        if frame.is_blank() || self.capped(now_ms) {
            return None;
        }
        let trigger = if words && self.differs(frame, hash, WORDS_SHARE) {
            Some(Trigger::Words)
        } else if now_ms.saturating_sub(since) >= SETTLE_MS && self.differs(frame, hash, CHANGE_SHARE) {
            Some(Trigger::Change)
        } else {
            None
        };
        if trigger.is_some() {
            self.saved = Some((frame.clone(), hash));
            self.saved_at.push_back(now_ms);
        }
        trigger
    }

    /// Different enough from the last saved screen: `share` of the cells moved, and the hash is not a near duplicate
    /// (or so much moved that the hash cannot tell). Anything differs when nothing was saved yet.
    fn differs(&self, frame: &Luma, hash: u64, share: f32) -> bool {
        match &self.saved {
            None => true,
            Some((saved, saved_hash)) => {
                let moved = saved.changed_share(frame);
                moved >= share && (hamming(hash, *saved_hash) >= NEAR_BITS || moved >= STRONG_SHARE)
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// A "slide": a light background with dark text bars where `layout` puts them.
    fn slide(layout: u32) -> Luma {
        Luma::from_fn(|x, y| {
            let line = y / 4;
            let indent = ((layout.wrapping_mul(7 + line as u32)) % 9) as usize;
            let length = 20 + ((layout.wrapping_mul(13 + 3 * line as u32)) % 30) as usize;
            if y % 4 < 2 && line % 2 == ((layout as usize) % 2) && (x >= 4 + indent && x < 4 + indent + length) { 30 } else { 235 }
        })
    }

    fn uniform(level: u8) -> Luma {
        Luma::from_fn(|_, _| level)
    }

    /// Runs one sample a second and collects the times that were kept.
    fn run(detector: &mut Detector, frames: &[(u64, &Luma)]) -> Vec<(u64, Trigger)> {
        frames.iter().filter_map(|(t, f)| detector.observe(*t, f, false).map(|tr| (*t, tr))).collect()
    }

    #[test]
    fn luminance_cells_average_the_frame_area() {
        // 128 × 72 BGRA: left half white, right half black.
        let (w, h) = (128usize, 72usize);
        let mut px = vec![0u8; w * h * 4];
        for y in 0..h {
            for x in 0..w / 2 {
                let p = (y * w + x) * 4;
                px[p..p + 4].copy_from_slice(&[255, 255, 255, 255]);
            }
        }
        let luma = Luma::from_bgra(&px, w, h, w * 4).expect("big enough");
        assert_eq!(luma.cell(0, 0), 255);
        assert_eq!(luma.cell(31, 35), 255);
        assert_eq!(luma.cell(32, 0), 0);
        assert_eq!(luma.cell(63, 35), 0);
    }

    #[test]
    fn rows_are_read_with_their_stride_and_colours_weighted_like_the_eye() {
        let (w, h, stride) = (64usize, 36usize, 64 * 4 + 32);
        let mut px = vec![0u8; stride * h];
        for y in 0..h {
            for x in 0..w {
                let p = y * stride + x * 4;
                px[p..p + 4].copy_from_slice(&[0, 255, 0, 255]); // pure green (BGRA)
            }
            // Padding past the row must not count.
            for b in px[y * stride + w * 4..(y + 1) * stride].iter_mut() {
                *b = 255;
            }
        }
        let luma = Luma::from_bgra(&px, w, h, stride).unwrap();
        assert_eq!(luma.cell(10, 10), ((150 * 255) >> 8) as u8);
    }

    #[test]
    fn frames_smaller_than_the_grid_or_short_buffers_are_refused() {
        assert!(Luma::from_bgra(&[0; 32 * 18 * 4], 32, 18, 32 * 4).is_none());
        assert!(Luma::from_bgra(&[0; 100], 64, 36, 64 * 4).is_none());
        assert!(Luma::from_bgra(&[0; 64 * 36 * 4], 64, 36, 64 * 4).is_some());
    }

    #[test]
    fn the_hash_keeps_a_picture_and_tells_another_apart() {
        let a = slide(3);
        let brighter = Luma::from_fn(|x, y| a.cell(x, y).saturating_add(12));
        let shifted = Luma::from_fn(|x, y| a.cell(x.saturating_sub(1), y));
        assert!(hamming(a.hash(), brighter.hash()) <= 2, "brightness alone keeps the hash");
        assert!(hamming(a.hash(), shifted.hash()) < NEAR_BITS, "a one-cell shift is the same screen");
        for other in [slide(4), slide(11), slide(26)] {
            assert!(hamming(a.hash(), other.hash()) >= NEAR_BITS * 2, "another slide is another screen");
        }
        assert_eq!(hamming(0b1011, 0b0001), 2);
    }

    #[test]
    fn a_change_is_kept_once_it_has_settled_for_a_second_and_a_half() {
        let mut d = Detector::new();
        let (a, b) = (slide(1), slide(2));
        let kept = run(&mut d, &[(0, &a), (1_000, &a), (2_000, &a), (3_000, &b), (4_000, &b), (5_000, &b), (6_000, &b)]);
        // The first picture settles at 1.5 s (kept at the 2 s sample), the second after its own 1.5 s (4.5 s → 5 s).
        assert_eq!(kept, vec![(2_000, Trigger::Change), (5_000, Trigger::Change)]);
    }

    #[test]
    fn a_picture_that_keeps_moving_is_never_kept() {
        let mut d = Detector::new();
        let frames: Vec<Luma> = (0..10).map(slide).collect();
        let samples: Vec<(u64, &Luma)> = frames.iter().enumerate().map(|(i, f)| (i as u64 * 1_000, f)).collect();
        assert!(run(&mut d, &samples).is_empty());
    }

    #[test]
    fn small_movement_beside_a_settled_screen_still_settles() {
        let mut d = Detector::new();
        let base = slide(5);
        // A video tile (4 × 4 cells, 0.7 % of the grid) flickers every second.
        let frames: Vec<Luma> = (0..4).map(|i| Luma::from_fn(|x, y| if x >= 60 && y < 4 { (i * 60) as u8 } else { base.cell(x, y) })).collect();
        let samples: Vec<(u64, &Luma)> = frames.iter().enumerate().map(|(i, f)| (i as u64 * 1_000, f)).collect();
        assert_eq!(run(&mut d, &samples), vec![(2_000, Trigger::Change)]);
    }

    #[test]
    fn near_duplicates_of_the_last_saved_screen_are_dropped() {
        let mut d = Detector::new();
        let a = slide(7);
        assert_eq!(run(&mut d, &[(0, &a), (2_000, &a)]), vec![(2_000, Trigger::Change)]);
        // The same slide again after something else flashed by, slightly brighter: the same screen.
        let flash = slide(8);
        let again = Luma::from_fn(|x, y| a.cell(x, y).saturating_add(10));
        assert!(run(&mut d, &[(3_000, &flash), (4_000, &again), (5_000, &again), (6_000, &again), (9_000, &again)]).is_empty());
    }

    #[test]
    fn blank_frames_are_never_kept() {
        let mut d = Detector::new();
        let black = uniform(0);
        let grey = uniform(128);
        assert!(run(&mut d, &[(0, &black), (2_000, &black), (4_000, &grey), (6_000, &grey)]).is_empty());
        assert!(black.is_blank() && !slide(1).is_blank());
    }

    #[test]
    fn pointing_words_keep_the_frame_at_once_when_it_differs() {
        let mut d = Detector::new();
        let a = slide(9);
        // At once: no settling needed.
        assert_eq!(d.observe(0, &a, true), Some(Trigger::Words));
        // The same screen again: words keep nothing.
        assert_eq!(d.observe(1_000, &a, true), None);
        // A screen that differs by less than the change bar but more than the words bar (a grey box over 80 cells).
        let edited = Luma::from_fn(|x, y| if (20..22).contains(&y) && (8..48).contains(&x) { 120 } else { a.cell(x, y) });
        let share = a.changed_share(&edited);
        assert!((WORDS_SHARE..CHANGE_SHARE).contains(&share), "share {share}");
        if hamming(a.hash(), edited.hash()) >= NEAR_BITS {
            assert_eq!(d.observe(2_000, &edited, true), Some(Trigger::Words));
        }
        // Another slide while the words are heard: kept at once.
        let b = slide(30);
        assert_eq!(d.observe(3_000, &b, true), Some(Trigger::Words));
    }

    #[test]
    fn words_without_a_new_picture_and_changes_below_the_bar_keep_nothing() {
        let mut d = Detector::new();
        let a = slide(12);
        assert_eq!(run(&mut d, &[(0, &a), (2_000, &a)]).len(), 1);
        let typed = Luma::from_fn(|x, y| if y == 30 && (10..30).contains(&x) { 30 } else { a.cell(x, y) });
        assert!(a.changed_share(&typed) < CHANGE_SHARE);
        assert!(run(&mut d, &[(3_000, &typed), (5_000, &typed), (8_000, &typed)]).is_empty());
        assert_eq!(d.observe(9_000, &a, true), None);
    }

    #[test]
    fn at_most_120_screens_an_hour() {
        let mut d = Detector::new();
        let frames: Vec<Luma> = (0..400).map(|i| slide(1000 + i)).collect();
        let mut kept = 0;
        let mut t = 0u64;
        // A new slide every 3 s for an hour and a quarter (1 500 slides would be possible).
        for f in frames.iter().cycle().take(1_500) {
            for _ in 0..3 {
                if d.observe(t, f, false).is_some() {
                    kept += 1;
                }
                t += 1_000;
            }
            if t >= HOUR_MS {
                break;
            }
        }
        assert_eq!(kept, CAP_PER_HOUR);
        assert!(d.capped(t));
        // An hour after the first ones, room opens again.
        let later = slide(5_000);
        let t2 = t + HOUR_MS;
        assert!(!d.capped(t2));
        assert!(d.observe(t2, &later, true).is_some());
        assert_eq!(d.saved_this_hour(t2), 1);
    }

    #[test]
    fn restarting_keeps_the_last_saved_screen() {
        let mut d = Detector::new();
        let a = slide(14);
        assert_eq!(run(&mut d, &[(0, &a), (2_000, &a)]).len(), 1);
        d.restart();
        // Back on the same window after a pause: nothing new.
        assert!(run(&mut d, &[(10_000, &a), (12_000, &a)]).is_empty());
        // Settling starts again from the restart: one sample is not enough.
        let b = slide(15);
        d.restart();
        assert_eq!(d.observe(20_000, &b, false), None);
        assert_eq!(d.observe(21_600, &b, false), Some(Trigger::Change));
    }
}
