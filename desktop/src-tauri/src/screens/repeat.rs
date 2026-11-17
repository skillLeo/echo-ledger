//! Is a screen about to be kept the previous kept screen of this recording again? (pure; unit-tested with synthetic
//! frames). The server takes a picture that matches the screen before or after it as that screen (docs/API.md 2.0.0-e:
//! the same claimed size, within 5 minutes, at most 2 pixels differing by more than 12 shades of grey at 640 px) and
//! answers `200` with it. Skipped here, it costs no encoding, no upload and no decoding on a busy server.
//!
//! The check is stricter than the server's on each of its three counts, so it never drops a screen the server would
//! keep: within [`WINDOW_MS`] (not 5 minutes), the same size, and no pixel at all differing by more than [`SHADES`]
//! (not 2), on a picture shrunk as the server shrinks it ([`grey_width`]: at most 3 × smaller, so one pixel always
//! weighs at least a ninth of its box). The picture is reduced by averaging every pixel into its box, never by
//! sampling, so a small sharp change (a digit, a cursor's click) cannot fall between samples. A frame above
//! [`MAX_PIXELS`] or longer than [`MAX_ASPECT`] to 1 is never compared. "Capture now" and pointing words count too: the
//! server would merge their repeat all the same.

/// How close in time a repeat must be to the screen it repeats.
pub const WINDOW_MS: u64 = 30_000;
/// The narrowest width the pictures are compared at (the server's).
pub const GREY_WIDTH: usize = 640;
/// Frames above this many pixels are never compared.
pub const MAX_PIXELS: u64 = 12_000_000;
/// Nor frames longer than this to 1 either way.
pub const MAX_ASPECT: u64 = 4;

/// The width a frame `width` px wide is compared at, as the server shrinks it: `min(w, max(640, ceil(w / 3)))`.
pub fn grey_width(width: usize) -> usize {
    width.min(GREY_WIDTH.max(width.div_ceil(3)))
}
/// A pixel differs when its grey moved by more than this (the server's).
pub const SHADES: u8 = 12;

/// What a kept screen is compared by: its size, its moment and its grey picture at [`GREY_WIDTH`] px.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Fingerprint {
    width: u32,
    height: u32,
    at_ms: u64,
    grey: Vec<u8>,
}

/// Rec. 601 luminance of one BGRA pixel (as the detector reads it).
fn luminance(b: u8, g: u8, r: u8) -> u32 {
    (77 * r as u32 + 150 * g as u32 + 29 * b as u32) >> 8
}

impl Fingerprint {
    /// The fingerprint of a BGRA frame (rows `stride` bytes apart) kept at `at_ms` of the recording. Every pixel is
    /// averaged into its box of the [`grey_width`]-wide picture. `None` for a frame that is never compared (above
    /// [`MAX_PIXELS`], longer than [`MAX_ASPECT`] to 1), an empty one or a buffer too short for its size.
    pub fn of(bgra: &[u8], width: u32, height: u32, stride: usize, at_ms: u64) -> Option<Self> {
        let (w, h) = (width as usize, height as usize);
        if w == 0 || h == 0 || stride < w * 4 || bgra.len() < stride * (h - 1) + w * 4 {
            return None;
        }
        let (long, short) = (width.max(height) as u64, width.min(height) as u64);
        if u64::from(width) * u64::from(height) > MAX_PIXELS || long > short * MAX_ASPECT {
            return None;
        }
        let gw = grey_width(w);
        let gh = (h * gw / w).max(1);
        let column: Vec<usize> = (0..w).map(|x| x * gw / w).collect();
        let mut per_column = vec![0u32; gw];
        for &c in &column {
            per_column[c] += 1;
        }
        let mut grey = vec![0u8; gw * gh];
        let mut sums = vec![0u32; gw];
        let mut rows_in = 0u32;
        let mut out_row = 0usize;
        for y in 0..h {
            let row_out = y * gh / h;
            if row_out != out_row {
                flush(&mut grey[out_row * gw..(out_row + 1) * gw], &mut sums, &per_column, rows_in);
                out_row = row_out;
                rows_in = 0;
            }
            let row = &bgra[y * stride..y * stride + w * 4];
            for (x, px) in row.as_chunks::<4>().0.iter().enumerate() {
                sums[column[x]] += luminance(px[0], px[1], px[2]);
            }
            rows_in += 1;
        }
        flush(&mut grey[out_row * gw..(out_row + 1) * gw], &mut sums, &per_column, rows_in);
        Some(Self { width, height, at_ms, grey })
    }

    /// This screen is `previous` again: kept after it within [`WINDOW_MS`], the same size, and no pixel of the grey
    /// pictures more than [`SHADES`] apart.
    pub fn repeats(&self, previous: &Self) -> bool {
        self.width == previous.width
            && self.height == previous.height
            && self.at_ms >= previous.at_ms
            && self.at_ms - previous.at_ms <= WINDOW_MS
            && self.grey.len() == previous.grey.len()
            && self.grey.iter().zip(&previous.grey).all(|(a, b)| a.abs_diff(*b) <= SHADES)
    }
}

/// Writes one row of averages and clears the sums.
fn flush(out: &mut [u8], sums: &mut [u32], per_column: &[u32], rows: u32) {
    for ((o, s), n) in out.iter_mut().zip(sums.iter_mut()).zip(per_column) {
        *o = (*s / (n * rows.max(1)).max(1)) as u8;
        *s = 0;
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// A light page with dark lines of "text", `shade(x, y)` drawn over it.
    fn page(width: u32, height: u32, shade: impl Fn(u32, u32) -> Option<u8>) -> Vec<u8> {
        let mut bgra = Vec::with_capacity((width * height * 4) as usize);
        for y in 0..height {
            for x in 0..width {
                let base = if (y / 24) % 2 == 0 && (x / 9) % 3 != 0 { 40 } else { 235 };
                let v = shade(x, y).unwrap_or(base);
                bgra.extend_from_slice(&[v, v, v, 255]);
            }
        }
        bgra
    }

    fn print(bgra: &[u8], width: u32, height: u32, at_ms: u64) -> Fingerprint {
        Fingerprint::of(bgra, width, height, width as usize * 4, at_ms).unwrap()
    }

    #[test]
    fn the_same_picture_again_soon_is_a_repeat_and_only_then() {
        let frame = page(1920, 1080, |_, _| None);
        let first = print(&frame, 1920, 1080, 10_000);
        assert!(print(&frame, 1920, 1080, 12_000).repeats(&first), "byte for byte the same, 2 s later");
        assert!(print(&frame, 1920, 1080, 10_000 + WINDOW_MS).repeats(&first), "at the window's edge");
        assert!(!print(&frame, 1920, 1080, 10_001 + WINDOW_MS).repeats(&first), "later than the window: the server decides");
        assert!(!print(&frame, 1920, 1080, 9_000).repeats(&first), "never against a later screen");
        let wider = page(1921, 1080, |_, _| None);
        assert!(!print(&wider, 1921, 1080, 12_000).repeats(&first), "another size is never compared");
    }

    #[test]
    fn noise_below_the_shade_threshold_is_still_a_repeat() {
        // A video call's encoder never sends the same pixels twice: every pixel off by up to 3.
        let frame = page(1920, 1080, |_, _| None);
        let noisy = page(1920, 1080, |x, y| {
            let base = if (y / 24) % 2 == 0 && (x / 9) % 3 != 0 { 40i32 } else { 235 };
            Some((base + ((x * 7 + y * 13) % 7) as i32 - 3) as u8)
        });
        assert!(print(&noisy, 1920, 1080, 5_000).repeats(&print(&frame, 1920, 1080, 0)));
    }

    #[test]
    fn a_real_change_is_never_a_repeat_however_small() {
        let frame = page(1920, 1080, |_, _| None);
        let first = print(&frame, 1920, 1080, 0);
        // One digit changed: a 7 × 12 px glyph on the light part of the page.
        let digit = page(1920, 1080, |x, y| ((300..307).contains(&x) && (530..542).contains(&y)).then_some(20));
        assert!(!print(&digit, 1920, 1080, 1_000).repeats(&first));
        // A single pixel gone black on white: averaged over its 3 × 3 box it still moves its grey by 26.
        let pixel = page(1920, 1080, |x, y| (x == 1000 && y == 540).then_some(0));
        assert!(!print(&pixel, 1920, 1080, 1_000).repeats(&first));
        // The whole page a little darker (a dimmed slide): every pixel off by 20.
        let dimmed = page(1920, 1080, |x, y| Some(if (y / 24) % 2 == 0 && (x / 9) % 3 != 0 { 20 } else { 215 }));
        assert!(!print(&dimmed, 1920, 1080, 1_000).repeats(&first));
    }

    #[test]
    fn large_frames_shrink_as_the_server_does_so_one_pixel_or_a_faint_line_still_counts() {
        assert_eq!((grey_width(500), grey_width(1280), grey_width(1920), grey_width(2880), grey_width(3840)), (500, 640, 640, 960, 1280));
        for (w, h) in [(3840u32, 2160u32), (2880, 1800)] {
            let frame = page(w, h, |_, _| None);
            let first = print(&frame, w, h, 0);
            assert!(print(&frame, w, h, 1_000).repeats(&first), "{w}x{h}: the same picture again");
            // One pixel gone black on the light part of the page.
            let pixel = page(w, h, |x, y| (x == 1001 && y == 30).then_some(0));
            assert!(!print(&pixel, w, h, 1_000).repeats(&first), "{w}x{h}: one changed pixel is kept");
            // A 1 px line of low contrast (40 shades) across the light part, and down it.
            let across = page(w, h, |_, y| (y == 30).then_some(195));
            assert!(!print(&across, w, h, 1_000).repeats(&first), "{w}x{h}: a faint line across is kept");
            let down = page(w, h, |x, y| (x == 1001 && (24..48).contains(&y)).then_some(195));
            assert!(!print(&down, w, h, 1_000).repeats(&first), "{w}x{h}: a faint line down is kept");
        }
    }

    #[test]
    fn frames_too_large_or_too_long_are_never_compared() {
        let big = page(5120, 2880, |_, _| None);
        assert!(Fingerprint::of(&big, 5120, 2880, 5120 * 4, 0).is_none(), "14.7 MP");
        let long = page(4100, 1000, |_, _| None);
        assert!(Fingerprint::of(&long, 4100, 1000, 4100 * 4, 0).is_none(), "longer than 4 to 1");
        let tall = page(400, 1601, |_, _| None);
        assert!(Fingerprint::of(&tall, 400, 1601, 400 * 4, 0).is_none(), "taller than 4 to 1");
        let edge = page(4000, 1000, |_, _| None);
        assert!(Fingerprint::of(&edge, 4000, 1000, 4000 * 4, 0).is_some(), "4 to 1 exactly is compared");
    }

    /// CPU per capture, beside the WebP encoding every kept screen pays anyway. Ignored by default; in a release build:
    /// `cargo test --release --lib screens::repeat -- --ignored --nocapture`.
    #[test]
    #[ignore]
    fn cpu_per_capture() {
        let median = |mut ms: Vec<f64>| {
            ms.sort_by(f64::total_cmp);
            ms[ms.len() / 2]
        };
        for (w, h) in [(1920u32, 1080u32), (2560, 1440), (3840, 2160)] {
            let frame = page(w, h, |_, _| None);
            let time = |f: &dyn Fn()| {
                let started = std::time::Instant::now();
                f();
                started.elapsed().as_secs_f64() * 1000.0
            };
            let print_ms = median((0..15).map(|_| time(&|| drop(print(&frame, w, h, 0)))).collect());
            let (a, b) = (print(&frame, w, h, 0), print(&frame, w, h, 1));
            let compare_ms = median((0..15).map(|_| time(&|| assert!(b.repeats(&a)))).collect());
            let encode_ms = median((0..5).map(|_| time(&|| drop(super::super::encode::encode(&frame, w, h, w as usize * 4)))).collect());
            eprintln!("{w}x{h}: fingerprint {print_ms:.2} ms, compare {compare_ms:.3} ms; the WebP encoding it can save {encode_ms:.1} ms");
        }
    }

    #[test]
    fn small_and_odd_frames_are_read_whole() {
        // Narrower than 640: compared at its own width.
        let small = page(500, 300, |_, _| None);
        let changed = page(500, 300, |x, y| (x == 499 && y == 299).then_some(0));
        assert!(!print(&changed, 500, 300, 1).repeats(&print(&small, 500, 300, 0)), "the last pixel of the last row counts");
        // Padded rows (stride wider than the picture) and a short buffer.
        let (w, h, stride) = (700u32, 200u32, 704 * 4);
        let padded: Vec<u8> = (0..h).flat_map(|_| (0..stride).map(|i| if i < (w as usize) * 4 { 128 } else { 0 })).collect();
        assert!(Fingerprint::of(&padded, w, h, stride, 0).is_some());
        assert!(Fingerprint::of(&padded[..100], w, h, stride, 0).is_none());
        assert!(Fingerprint::of(&[], 0, 0, 0, 0).is_none());
    }
}
