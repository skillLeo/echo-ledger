//! The Share Check's verdict from a captured picture (pure): the protected test window is magenta, the unprotected
//! control window green. A pixel is one of them when each channel is within [`TOLERANCE`] of its colour, so a
//! compressed or colour-managed capture still counts. Pass = control found and no test pixel; Fail = test pixels
//! found (the window is in the share); Error = the control is missing (the capture did not show the screen at all).

/// Per channel, the most a captured pixel may differ from the colour it is counted as.
pub const TOLERANCE: u8 = 25;
/// The test window's colour (RGB): magenta, which almost nothing on a desktop uses.
pub const TEST: [u8; 3] = [255, 0, 255];
/// The check pattern's other squares: black, never counted.
pub const TEST_DARK: [u8; 3] = [0, 0, 0];
/// The control window's colour (RGB).
pub const CONTROL: [u8; 3] = [0, 200, 0];
/// The control counts as found from this share of its area on (a frame caught mid-change still shows most of it).
pub const CONTROL_FOUND_SHARE: f64 = 0.5;

/// A rectangle in the picture's pixels (right and bottom exclusive).
#[derive(Clone, Copy, Debug, Default, PartialEq, Eq, serde::Serialize)]
pub struct Rect {
    pub x: u32,
    pub y: u32,
    pub w: u32,
    pub h: u32,
}

impl Rect {
    pub fn area(&self) -> u64 {
        u64::from(self.w) * u64::from(self.h)
    }

    /// The part inside a `width` × `height` picture.
    pub fn clipped(&self, width: u32, height: u32) -> Rect {
        let x = self.x.min(width);
        let y = self.y.min(height);
        Rect { x, y, w: self.w.min(width - x), h: self.h.min(height - y) }
    }
}

/// A BGRA picture, rows `stride` bytes apart (what both systems' captures hand over).
#[derive(Clone, Copy, Debug)]
pub struct Picture<'a> {
    pub width: u32,
    pub height: u32,
    pub stride: usize,
    pub bgra: &'a [u8],
}

fn near(px: &[u8], rgb: [u8; 3]) -> bool {
    // BGRA in memory.
    px[2].abs_diff(rgb[0]) <= TOLERANCE && px[1].abs_diff(rgb[1]) <= TOLERANCE && px[0].abs_diff(rgb[2]) <= TOLERANCE
}

/// How many pixels inside `area` (the whole picture when none) are near `rgb`.
pub fn count(picture: &Picture<'_>, rgb: [u8; 3], area: Option<Rect>) -> u64 {
    let area = area.unwrap_or(Rect { x: 0, y: 0, w: picture.width, h: picture.height }).clipped(picture.width, picture.height);
    let mut n = 0;
    for y in area.y..area.y + area.h {
        let start = y as usize * picture.stride + area.x as usize * 4;
        let end = start + area.w as usize * 4;
        let Some(row) = picture.bgra.get(start..end) else { break };
        n += row.as_chunks::<4>().0.iter().filter(|px| near(&px[..], rgb)).count() as u64;
    }
    n
}

/// The average colour (RGB) inside `area`: what a missing control looked like instead, for the diagnostics.
pub fn average(picture: &Picture<'_>, area: Rect) -> Option<[u8; 3]> {
    let area = area.clipped(picture.width, picture.height);
    let (mut sum, mut n) = ([0u64; 3], 0u64);
    for y in area.y..area.y + area.h {
        let start = y as usize * picture.stride + area.x as usize * 4;
        let Some(row) = picture.bgra.get(start..start + area.w as usize * 4) else { break };
        for px in row.as_chunks::<4>().0 {
            sum[0] += u64::from(px[2]);
            sum[1] += u64::from(px[1]);
            sum[2] += u64::from(px[0]);
            n += 1;
        }
    }
    (n > 0).then(|| [(sum[0] / n) as u8, (sum[1] / n) as u8, (sum[2] / n) as u8])
}

/// What one capture showed.
#[derive(Clone, Copy, Debug, Default, PartialEq, Eq, serde::Serialize, serde::Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct Counts {
    pub test: u64,
    pub control: u64,
    /// The control's area in the picture, which "found" is measured against.
    pub control_area: u64,
}

/// Counts the test colour where the test window is and the control colour where the control is.
pub fn measure(picture: &Picture<'_>, test: Rect, control: Rect) -> Counts {
    let control_clipped = control.clipped(picture.width, picture.height);
    Counts { test: count(picture, TEST, Some(test)), control: count(picture, CONTROL, Some(control_clipped)), control_area: control_clipped.area() }
}

#[derive(Clone, Copy, Debug, PartialEq, Eq, serde::Serialize, serde::Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum Outcome {
    Pass,
    Fail,
    Error,
}

/// The verdict of one capture (pure): any test pixel is a Fail, whatever else the picture shows; otherwise the control
/// must show (most of its area), or the capture is an Error.
pub fn verdict(counts: Counts) -> Outcome {
    if counts.test > 0 {
        return Outcome::Fail;
    }
    let found = counts.control_area > 0 && counts.control as f64 >= counts.control_area as f64 * CONTROL_FOUND_SHARE;
    if found { Outcome::Pass } else { Outcome::Error }
}

/// The result over several methods (Windows captures twice): one Fail fails it, one Pass with no Fail passes it.
pub fn combined(outcomes: &[Outcome]) -> Outcome {
    if outcomes.contains(&Outcome::Fail) {
        Outcome::Fail
    } else if outcomes.contains(&Outcome::Pass) {
        Outcome::Pass
    } else {
        Outcome::Error
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// Decodes a fixture PNG (tests/fixtures/share-check) into BGRA.
    fn fixture(name: &str) -> (u32, u32, Vec<u8>) {
        let path = std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("tests/fixtures/share-check").join(name);
        let bytes = std::fs::read(&path).unwrap_or_else(|e| panic!("{}: {e}", path.display()));
        let image = tauri::image::Image::from_bytes(&bytes).expect("a PNG");
        let mut bgra = image.rgba().to_vec();
        for px in bgra.as_chunks_mut::<4>().0 {
            px.swap(0, 2);
        }
        (image.width(), image.height(), bgra)
    }

    /// The fixtures are 160 × 100 captures: the test window at x 20-79, y 30-69, the control at x 100-139, y 30-69.
    /// pass.png: the test window left out, the page behind it shows; fail.png: its magenta and black pattern shows;
    /// black-box.png: old Windows' `WDA_MONITOR`, a black box there; error.png: a black capture (no permission, or the
    /// capture failed), no control.
    const TEST_AT: Rect = Rect { x: 20, y: 30, w: 60, h: 40 };
    const CONTROL_AT: Rect = Rect { x: 100, y: 30, w: 40, h: 40 };

    fn judge(name: &str) -> (Outcome, Counts) {
        let (width, height, bgra) = fixture(name);
        let picture = Picture { width, height, stride: width as usize * 4, bgra: &bgra };
        let counts = measure(&picture, TEST_AT, CONTROL_AT);
        (verdict(counts), counts)
    }

    #[test]
    fn a_hidden_test_window_passes() {
        let (outcome, counts) = judge("pass.png");
        assert_eq!(outcome, Outcome::Pass, "{counts:?}");
        assert_eq!(counts.test, 0);
        assert_eq!(counts.control, 1600, "the whole control");
    }

    #[test]
    fn a_visible_test_window_fails() {
        let (outcome, counts) = judge("fail.png");
        assert_eq!(outcome, Outcome::Fail);
        assert!(counts.test >= 1000, "half of its check pattern is magenta: {counts:?}");
    }

    #[test]
    fn a_black_box_is_not_a_fail_and_a_missing_control_is_an_error() {
        // Old Windows: a black box where the test window is, the control shows.
        assert_eq!(judge("black-box.png").0, Outcome::Pass);
        // A capture that came back black (no permission, or the capture failed): no control.
        let (outcome, counts) = judge("error.png");
        assert_eq!(outcome, Outcome::Error);
        assert_eq!(counts.control, 0);
    }

    #[test]
    fn colours_within_the_tolerance_count_and_others_do_not() {
        // 2 × 1 BGRA: a slightly shifted magenta, then a pink that is too far off.
        let bgra = [230, 20, 240, 255, 180, 40, 255, 255];
        let picture = Picture { width: 2, height: 1, stride: 8, bgra: &bgra };
        assert_eq!(count(&picture, TEST, None), 1);
        assert_eq!(TOLERANCE, 25);
    }

    #[test]
    fn a_mostly_drawn_control_is_found_a_sliver_is_not() {
        assert_eq!(verdict(Counts { test: 0, control: 800, control_area: 1600 }), Outcome::Pass);
        assert_eq!(verdict(Counts { test: 0, control: 100, control_area: 1600 }), Outcome::Error);
        assert_eq!(verdict(Counts { test: 1, control: 1600, control_area: 1600 }), Outcome::Fail, "one test pixel is enough");
        assert_eq!(verdict(Counts::default()), Outcome::Error);
    }

    #[test]
    fn two_methods_combine_with_fail_first() {
        assert_eq!(combined(&[Outcome::Pass, Outcome::Fail]), Outcome::Fail);
        assert_eq!(combined(&[Outcome::Pass, Outcome::Error]), Outcome::Pass);
        assert_eq!(combined(&[Outcome::Error, Outcome::Error]), Outcome::Error);
        assert_eq!(combined(&[]), Outcome::Error);
    }

    #[test]
    fn rects_are_clipped_to_the_picture() {
        assert_eq!(Rect { x: 150, y: 90, w: 40, h: 40 }.clipped(160, 100), Rect { x: 150, y: 90, w: 10, h: 10 });
        assert_eq!(Rect { x: 500, y: 0, w: 4, h: 4 }.clipped(160, 100).area(), 0);
    }
}
