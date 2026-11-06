//! A kept frame as a WebP image of about 150 KB: the long side is brought down to 1920 px at most (area averaged,
//! so text stays readable), then encoded lossy with libwebp (the `webp` crate builds it in), lowering the quality
//! step by step while the file is well above the target.

/// The long side of a stored screen at most.
pub const MAX_LONG_SIDE: u32 = 1920;
/// What a screen should weigh; a little above is fine, the qualities step down until a file fits.
pub const TARGET_BYTES: usize = 150_000;
const ACCEPT_BYTES: usize = TARGET_BYTES + TARGET_BYTES / 5;
const QUALITIES: [f32; 4] = [80.0, 68.0, 55.0, 40.0];
/// The server takes images of at most 3 MB.
pub const MAX_BYTES: usize = 3 * 1024 * 1024;

/// One encoded screen.
#[derive(Clone, Debug)]
pub struct Encoded {
    pub webp: Vec<u8>,
    pub width: u32,
    pub height: u32,
}

/// The size a `width` × `height` frame is stored at: the same, or scaled so its long side is `max_long` (never 0).
pub fn fit(width: u32, height: u32, max_long: u32) -> (u32, u32) {
    let long = width.max(height);
    if long <= max_long || long == 0 {
        return (width.max(1), height.max(1));
    }
    let scale = |side: u32| ((side as u64 * max_long as u64 + long as u64 / 2) / long as u64).max(1) as u32;
    (scale(width), scale(height))
}

/// A BGRA frame (rows `stride` bytes apart) as RGB at `out_w` × `out_h`, each output pixel the average of the source
/// pixels it covers.
pub fn bgra_to_rgb(pixels: &[u8], width: u32, height: u32, stride: usize, out_w: u32, out_h: u32) -> Vec<u8> {
    let (w, h, ow, oh) = (width as usize, height as usize, out_w.max(1) as usize, out_h.max(1) as usize);
    let mut out = vec![0u8; ow * oh * 3];
    if w == 0 || h == 0 {
        return out;
    }
    if ow == w && oh == h {
        for y in 0..h {
            let row = &pixels[y * stride..y * stride + w * 4];
            for (x, px) in row.as_chunks::<4>().0.iter().enumerate() {
                let o = (y * w + x) * 3;
                out[o] = px[2];
                out[o + 1] = px[1];
                out[o + 2] = px[0];
            }
        }
        return out;
    }
    // Source columns of every output column, worked out once.
    let spans_x: Vec<(usize, usize)> = (0..ow).map(|x| span(x, ow, w)).collect();
    for oy in 0..oh {
        let (y0, y1) = span(oy, oh, h);
        for (ox, &(x0, x1)) in spans_x.iter().enumerate() {
            let (mut r, mut g, mut b, mut n) = (0u32, 0u32, 0u32, 0u32);
            for y in y0..y1 {
                let row = y * stride;
                for x in x0..x1 {
                    let p = row + x * 4;
                    b += pixels[p] as u32;
                    g += pixels[p + 1] as u32;
                    r += pixels[p + 2] as u32;
                    n += 1;
                }
            }
            let o = (oy * ow + ox) * 3;
            let n = n.max(1);
            out[o] = ((r + n / 2) / n) as u8;
            out[o + 1] = ((g + n / 2) / n) as u8;
            out[o + 2] = ((b + n / 2) / n) as u8;
        }
    }
    out
}

/// The source pixels `[start, end)` output pixel `i` of `out` covers in a line of `src` pixels (at least one).
fn span(i: usize, out: usize, src: usize) -> (usize, usize) {
    let start = i * src / out;
    let end = ((i + 1) * src).div_ceil(out).min(src);
    (start, end.max(start + 1))
}

/// Encodes RGB pixels as lossy WebP at `quality` (0-100).
fn webp_at(rgb: &[u8], width: u32, height: u32, quality: f32) -> Result<Vec<u8>, String> {
    let mut config = webp::WebPConfig::new().map_err(|_| "The WebP encoder could not start.".to_string())?;
    config.lossless = 0;
    config.quality = quality;
    config.method = 4;
    let encoded = webp::Encoder::from_rgb(rgb, width, height).encode_advanced(&config).map_err(|e| format!("WebP encoding failed: {e:?}"))?;
    Ok(encoded.to_vec())
}

/// A BGRA frame as a stored screen: at most [`MAX_LONG_SIDE`] on its long side, about [`TARGET_BYTES`] (the first
/// quality whose file fits, else the smallest). Fails for an empty frame or a buffer too short for its size.
pub fn encode(pixels: &[u8], width: u32, height: u32, stride: usize) -> Result<Encoded, String> {
    if width == 0 || height == 0 || stride < width as usize * 4 || pixels.len() < stride * (height as usize - 1) + width as usize * 4 {
        return Err("The frame is empty.".into());
    }
    let (out_w, out_h) = fit(width, height, MAX_LONG_SIDE);
    let rgb = bgra_to_rgb(pixels, width, height, stride, out_w, out_h);
    let mut smallest: Option<Vec<u8>> = None;
    for quality in QUALITIES {
        let webp = webp_at(&rgb, out_w, out_h, quality)?;
        if webp.len() <= ACCEPT_BYTES {
            return Ok(Encoded { webp, width: out_w, height: out_h });
        }
        if smallest.as_ref().is_none_or(|s| webp.len() < s.len()) {
            smallest = Some(webp);
        }
    }
    let webp = smallest.unwrap_or_default();
    Ok(Encoded { webp, width: out_w, height: out_h })
}

#[cfg(test)]
mod tests {
    use super::*;

    /// A BGRA "document": white page, dark text bars, a coloured header, `width` × `height`.
    fn document(width: u32, height: u32) -> Vec<u8> {
        let (w, h) = (width as usize, height as usize);
        let mut px = vec![255u8; w * h * 4];
        for y in 0..h {
            for x in 0..w {
                let p = (y * w + x) * 4;
                let header = y < h / 10;
                let text = !header && (y / 14) % 2 == 0 && (y % 14) < 9 && x > w / 12 && x < w - w / 12 && (x / 9 + y / 14) % 7 != 0;
                let colour: [u8; 4] = if header { [221, 99, 62, 255] } else if text { [40, 40, 40, 255] } else { [255, 255, 255, 255] };
                px[p..p + 4].copy_from_slice(&colour);
            }
        }
        px
    }

    /// Pseudo-random noise, the hardest picture to compress.
    fn noise(width: u32, height: u32) -> Vec<u8> {
        let mut seed = 0x2545_F491_4F6C_DD1Du64;
        (0..width as usize * height as usize * 4)
            .map(|_| {
                seed ^= seed << 13;
                seed ^= seed >> 7;
                seed ^= seed << 17;
                (seed >> 32) as u8
            })
            .collect()
    }

    #[test]
    fn the_long_side_is_brought_down_to_1920() {
        assert_eq!(fit(3840, 2160, 1920), (1920, 1080));
        assert_eq!(fit(2560, 1440, 1920), (1920, 1080));
        assert_eq!(fit(1080, 2400, 1920), (864, 1920));
        assert_eq!(fit(1920, 1200, 1920), (1920, 1200));
        assert_eq!(fit(800, 600, 1920), (800, 600));
        assert_eq!(fit(5000, 2, 1920), (1920, 1));
    }

    #[test]
    fn colours_come_out_as_rgb_and_scaling_averages_areas() {
        // 4 × 2 BGRA: a red and a blue 2 × 2 block.
        let red = [0u8, 0, 255, 255];
        let blue = [255u8, 0, 0, 255];
        let row: Vec<u8> = [red, red, blue, blue].concat();
        let px: Vec<u8> = [row.clone(), row].concat();
        assert_eq!(bgra_to_rgb(&px, 4, 2, 16, 4, 2)[..6], [255, 0, 0, 255, 0, 0]);
        assert_eq!(bgra_to_rgb(&px, 4, 2, 16, 2, 1), vec![255, 0, 0, 0, 0, 255]);
        // Halfway between both: the average.
        assert_eq!(bgra_to_rgb(&px, 4, 2, 16, 1, 1), vec![128, 0, 128]);
    }

    #[test]
    fn a_big_document_frame_is_a_webp_of_the_target_size() {
        let (w, h) = (2560, 1440);
        let encoded = encode(&document(w, h), w, h, w as usize * 4).expect("encodes");
        assert_eq!((encoded.width, encoded.height), (1920, 1080));
        assert_eq!(&encoded.webp[..4], b"RIFF");
        assert_eq!(&encoded.webp[8..12], b"WEBP");
        assert!(encoded.webp.len() <= ACCEPT_BYTES, "{} bytes", encoded.webp.len());
        let decoded = webp::Decoder::new(&encoded.webp).decode().expect("decodes");
        assert_eq!((decoded.width(), decoded.height()), (1920, 1080));
    }

    #[test]
    fn a_picture_that_will_not_compress_ends_at_the_lowest_quality_and_stays_under_the_server_limit() {
        let (w, h) = (1920, 1080);
        let px = noise(w, h);
        let encoded = encode(&px, w, h, w as usize * 4).expect("encodes");
        let rgb = bgra_to_rgb(&px, w, h, w as usize * 4, w, h);
        let lowest = webp_at(&rgb, w, h, QUALITIES[QUALITIES.len() - 1]).unwrap();
        assert!(encoded.webp.len() > ACCEPT_BYTES);
        assert_eq!(encoded.webp.len(), lowest.len());
        assert!(encoded.webp.len() < MAX_BYTES);
    }

    #[test]
    fn empty_frames_and_short_buffers_are_refused() {
        assert!(encode(&[], 0, 0, 0).is_err());
        assert!(encode(&[0; 16], 8, 8, 32).is_err());
    }
}
