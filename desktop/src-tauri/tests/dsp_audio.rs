//! DSP helpers: sample conversion, clipping, down-mixing, interleaving, level metering and the streaming
//! resampler (ratios, block-size independence, drift, odd block sizes, non-finite input).

use desktop_lib::audio::dsp::{self, Resampler};

fn sine(rate: u32, freq: f32, amplitude: f32, frames: usize) -> Vec<f32> {
    (0..frames)
        .map(|n| amplitude * (2.0 * std::f32::consts::PI * freq * n as f32 / rate as f32).sin())
        .collect()
}

/// Runs `input` through a fresh resampler in blocks whose sizes cycle through `sizes`.
fn resample_in_blocks(
    input_rate: u32,
    output_rate: u32,
    input: &[f32],
    sizes: &[usize],
) -> Vec<f32> {
    let mut resampler = Resampler::new(input_rate, output_rate);
    let mut out = Vec::new();
    let (mut pos, mut i) = (0, 0);
    while pos < input.len() {
        let end = (pos + sizes[i % sizes.len()]).min(input.len());
        out.extend(resampler.process(&input[pos..end]));
        pos = end;
        i += 1;
    }
    out
}

/// Frequency estimate from the number of rising zero crossings after the start-up transient.
fn estimated_frequency(samples: &[f32], rate: u32) -> f32 {
    let body = &samples[samples.len() / 10..];
    let crossings = body
        .windows(2)
        .filter(|w| w[0] < 0.0 && w[1] >= 0.0)
        .count();
    crossings as f32 * rate as f32 / body.len() as f32
}

// ---- Sample conversion and clipping ----------------------------------------------------------------------------

#[test]
fn full_scale_and_mid_scale_samples_convert_exactly() {
    assert_eq!(dsp::f32_to_i16(0.0), 0);
    assert_eq!(dsp::f32_to_i16(-0.0), 0);
    assert_eq!(dsp::f32_to_i16(1.0), 32_767);
    assert_eq!(dsp::f32_to_i16(-1.0), -32_768);
    assert_eq!(dsp::f32_to_i16(0.5), 16_384);
    assert_eq!(dsp::f32_to_i16(-0.5), -16_384);
    assert_eq!(dsp::f32_to_i16(0.25), 8_192);
}

#[test]
fn out_of_range_samples_are_clipped_not_wrapped() {
    for loud in [1.0001f32, 1.5, 2.0, 100.0, 1.0e9, f32::MAX, f32::INFINITY] {
        assert_eq!(dsp::f32_to_i16(loud), i16::MAX, "{loud}");
        assert_eq!(dsp::f32_to_i16(-loud), i16::MIN, "-{loud}");
    }
}

#[test]
fn nan_and_denormals_become_silence_without_panicking() {
    assert_eq!(dsp::f32_to_i16(f32::NAN), 0);
    assert_eq!(dsp::f32_to_i16(-f32::NAN), 0);
    assert_eq!(dsp::f32_to_i16(f32::MIN_POSITIVE / 2.0), 0);
    assert_eq!(dsp::f32_to_i16(1.0e-9), 0);
}

#[test]
fn conversion_is_monotonic_and_nearly_symmetric() {
    let mut previous = i16::MIN;
    for step in -15_000i32..=15_000 {
        let x = step as f32 / 10_000.0;
        let y = dsp::f32_to_i16(x);
        assert!(y >= previous, "not monotonic at {x}: {previous} then {y}");
        previous = y;
        let mirrored = dsp::f32_to_i16(-x);
        assert!(
            (i32::from(y) + i32::from(mirrored)).abs() <= 1,
            "asymmetry at {x}: {y} vs {mirrored}"
        );
    }
}

#[test]
fn quantisation_error_stays_within_one_and_a_half_steps() {
    for n in 0..2_000 {
        let x = (n as f32 * 0.7137).sin() * 0.999;
        let back = f32::from(dsp::f32_to_i16(x)) / 32_768.0;
        assert!((back - x).abs() <= 1.5 / 32_768.0 + 1e-6, "{x} -> {back}");
    }
}

// ---- Mixing helpers --------------------------------------------------------------------------------------------

#[test]
fn downmix_handles_stereo_surround_and_odd_frame_counts() {
    assert_eq!(
        dsp::downmix_to_mono(&[1.0, 3.0, 2.0, 4.0], 2),
        vec![2.0, 3.0]
    );
    assert_eq!(
        dsp::downmix_to_mono(&[1.0, 2.0, 3.0, 4.0, 5.0, 6.0], 3),
        vec![2.0, 5.0]
    );
    assert_eq!(
        dsp::downmix_to_mono(&[1.0, 2.0, 3.0, 4.0, 5.0, 6.0], 6),
        vec![3.5]
    );
    // A trailing partial frame (5 samples at 2 channels) is dropped rather than read out of bounds.
    assert_eq!(
        dsp::downmix_to_mono(&[1.0, 3.0, 2.0, 4.0, 9.0], 2),
        vec![2.0, 3.0]
    );
    assert_eq!(dsp::downmix_to_mono(&[7.0], 2), Vec::<f32>::new());
    assert_eq!(dsp::downmix_to_mono(&[], 2), Vec::<f32>::new());
}

#[test]
fn downmix_with_one_or_zero_channels_returns_the_input_unchanged() {
    assert_eq!(
        dsp::downmix_to_mono(&[0.1, 0.2, 0.3], 1),
        vec![0.1, 0.2, 0.3]
    );
    assert_eq!(
        dsp::downmix_to_mono(&[0.1, 0.2, 0.3], 0),
        vec![0.1, 0.2, 0.3]
    );
}

#[test]
fn downmix_keeps_opposite_phase_channels_silent() {
    let left = sine(16_000, 440.0, 0.5, 1_000);
    let right: Vec<f32> = left.iter().map(|s| -s).collect();
    let mono = dsp::downmix_to_mono(&dsp::interleave(&left, &right), 2);
    assert_eq!(mono.len(), 1_000);
    assert!(mono.iter().all(|s| s.abs() < 1e-6));
}

#[test]
fn interleave_alternates_left_and_right_and_pads_the_shorter_side() {
    assert_eq!(
        dsp::interleave(&[1.0, 2.0, 3.0], &[10.0, 20.0, 30.0]),
        vec![1.0, 10.0, 2.0, 20.0, 3.0, 30.0]
    );
    assert_eq!(
        dsp::interleave(&[1.0], &[10.0, 20.0, 30.0]),
        vec![1.0, 10.0, 0.0, 20.0, 0.0, 30.0]
    );
    assert_eq!(
        dsp::interleave(&[1.0, 2.0, 3.0], &[10.0]),
        vec![1.0, 10.0, 2.0, 0.0, 3.0, 0.0]
    );
    assert_eq!(dsp::interleave(&[], &[5.0]), vec![0.0, 5.0]);
    assert_eq!(dsp::interleave(&[], &[]), Vec::<f32>::new());
}

#[test]
fn pad_to_equal_extends_whichever_side_is_shorter() {
    let (mut a, mut b) = (vec![1.0, 2.0, 3.0], vec![9.0]);
    dsp::pad_to_equal(&mut a, &mut b);
    assert_eq!(
        (a.clone(), b.clone()),
        (vec![1.0, 2.0, 3.0], vec![9.0, 0.0, 0.0])
    );
    dsp::pad_to_equal(&mut a, &mut b);
    assert_eq!(a.len(), b.len());
    let (mut e1, mut e2): (Vec<f32>, Vec<f32>) = (vec![], vec![]);
    dsp::pad_to_equal(&mut e1, &mut e2);
    assert!(e1.is_empty() && e2.is_empty());
}

#[test]
fn interleaving_then_downmixing_averages_the_two_channels() {
    let (a, b) = (sine(16_000, 300.0, 0.4, 800), sine(16_000, 700.0, 0.2, 800));
    let mono = dsp::downmix_to_mono(&dsp::interleave(&a, &b), 2);
    for n in 0..800 {
        assert!((mono[n] - (a[n] + b[n]) / 2.0).abs() < 1e-6);
    }
}

// ---- Level metering and silence --------------------------------------------------------------------------------

#[test]
fn rms_of_known_signals() {
    assert_eq!(dsp::rms(&[]), 0.0);
    assert_eq!(dsp::rms(&[0.0; 4_096]), 0.0);
    assert!((dsp::rms(&[0.25; 100]) - 0.25).abs() < 1e-6);
    assert!((dsp::rms(&[-0.25; 100]) - 0.25).abs() < 1e-6);
    let tone = sine(16_000, 440.0, 0.5, 16_000);
    assert!((dsp::rms(&tone) - 0.5 / std::f32::consts::SQRT_2).abs() < 1e-3);
    assert!(dsp::rms(&[1.0e-20; 10]).is_finite());
}

#[test]
fn rms_stays_accurate_over_a_long_buffer() {
    let one_minute = vec![0.5f32; 16_000 * 60];
    assert!((dsp::rms(&one_minute) - 0.5).abs() < 1e-3);
}

#[test]
fn a_non_finite_sample_does_not_panic_the_meter() {
    assert!(dsp::rms(&[0.1, f32::NAN, 0.1]).is_nan());
    assert!(dsp::rms(&[0.1, f32::INFINITY]).is_infinite());
}

// ---- Resampler -------------------------------------------------------------------------------------------------

#[test]
fn equal_rates_pass_samples_through_untouched() {
    let mut r = Resampler::new(16_000, 16_000);
    assert!(r.is_passthrough());
    let input = vec![0.1, -0.2, f32::NAN, 0.4];
    let out = r.process(&input);
    assert_eq!(out.len(), 4);
    assert_eq!(out[0], 0.1);
    assert!(out[2].is_nan());
    assert!(!Resampler::new(48_000, 16_000).is_passthrough());
    assert!(!Resampler::new(16_001, 16_000).is_passthrough());
}

#[test]
fn output_length_follows_the_rate_ratio_for_common_device_rates() {
    let block_sizes = [480, 441, 1, 7, 1_023, 4_096, 3, 160];
    for (input_rate, output_rate) in [
        (48_000, 16_000),
        (44_100, 16_000),
        (32_000, 16_000),
        (24_000, 16_000),
        (22_050, 16_000),
        (11_025, 16_000),
        (8_000, 16_000),
        (96_000, 16_000),
        (192_000, 16_000),
        (16_000, 48_000),
    ] {
        let frames = input_rate as usize * 2;
        let input = sine(input_rate, 440.0, 0.5, frames);
        let out = resample_in_blocks(input_rate, output_rate, &input, &block_sizes);
        let expected = frames as f64 * output_rate as f64 / input_rate as f64;
        // The first output sits one input sample into the stream, so an upsampler is up to one input period short.
        let allowed = (output_rate as f64 / input_rate as f64).ceil() + 2.0;
        assert!(
            (out.len() as f64 - expected).abs() <= allowed,
            "{input_rate} -> {output_rate}: {} samples, expected about {expected}",
            out.len()
        );
        assert!(
            out.iter().all(|s| s.is_finite() && s.abs() <= 0.5 + 1e-3),
            "{input_rate} -> {output_rate}: sample out of range"
        );
    }
}

#[test]
fn a_tone_keeps_its_pitch_through_every_ratio() {
    for (input_rate, output_rate) in [
        (48_000, 16_000),
        (44_100, 16_000),
        (8_000, 16_000),
        (16_000, 48_000),
    ] {
        let input = sine(input_rate, 440.0, 0.5, input_rate as usize * 2);
        let out = resample_in_blocks(input_rate, output_rate, &input, &[512]);
        let hz = estimated_frequency(&out, output_rate);
        assert!(
            (hz - 440.0).abs() < 8.0,
            "{input_rate} -> {output_rate}: measured {hz} Hz"
        );
    }
}

#[test]
fn a_tone_below_the_cutoff_keeps_almost_all_of_its_level() {
    let input = sine(48_000, 1_000.0, 0.5, 48_000);
    let out = resample_in_blocks(48_000, 16_000, &input, &[480]);
    let level = dsp::rms(&out[1_000..]);
    let expected = 0.5 / std::f32::consts::SQRT_2;
    assert!(
        level > expected * 0.9 && level < expected * 1.02,
        "level {level}, expected about {expected}"
    );
}

#[test]
fn block_boundaries_do_not_change_the_result() {
    let input = sine(44_100, 523.0, 0.4, 44_100);
    let whole = resample_in_blocks(44_100, 16_000, &input, &[input.len()]);
    for sizes in [
        vec![1usize],
        vec![7],
        vec![441],
        vec![1_000, 3, 77],
        vec![4_096],
    ] {
        let chunked = resample_in_blocks(44_100, 16_000, &input, &sizes);
        assert!(
            (chunked.len() as i64 - whole.len() as i64).abs() <= 1,
            "block sizes {sizes:?}: {} vs {}",
            chunked.len(),
            whole.len()
        );
        let worst = whole
            .iter()
            .zip(&chunked)
            .map(|(a, b)| (a - b).abs())
            .fold(0.0f32, f32::max);
        assert!(worst < 1e-3, "block sizes {sizes:?} differ by {worst}");
    }
}

#[test]
fn long_streams_do_not_drift_against_the_ideal_ratio() {
    // 30 s of 44.1 kHz audio in 10 ms blocks: the fractional read position must not accumulate an error.
    let input = vec![0.1f32; 44_100 * 30];
    let out = resample_in_blocks(44_100, 16_000, &input, &[441]);
    assert!(
        (out.len() as i64 - 480_000).abs() <= 2,
        "{} samples",
        out.len()
    );
}

#[test]
fn constant_input_settles_to_the_same_constant() {
    for (input_rate, output_rate) in [(48_000, 16_000), (8_000, 16_000), (44_100, 16_000)] {
        let out = resample_in_blocks(
            input_rate,
            output_rate,
            &vec![0.5; input_rate as usize],
            &[256],
        );
        assert!(
            out[out.len() / 2..].iter().all(|s| (s - 0.5).abs() < 1e-3),
            "{input_rate} -> {output_rate}"
        );
    }
}

#[test]
fn a_step_does_not_overshoot() {
    let out = resample_in_blocks(48_000, 16_000, &vec![1.0; 4_800], &[480]);
    assert!(
        out.windows(2).all(|w| w[1] >= w[0] - 1e-6),
        "step response must be monotonic"
    );
    assert!(out.iter().all(|s| *s <= 1.0 + 1e-6));
}

#[test]
fn silence_stays_silent() {
    let out = resample_in_blocks(48_000, 16_000, &[0.0; 9_600], &[333]);
    assert!(
        (out.len() as i64 - 3_200).abs() <= 2,
        "{} samples",
        out.len()
    );
    assert!(out.iter().all(|s| *s == 0.0));
}

#[test]
fn empty_and_single_sample_blocks_are_accepted() {
    let mut r = Resampler::new(48_000, 16_000);
    assert!(r.process(&[]).is_empty());
    let mut total = 0;
    for _ in 0..4_800 {
        total += r.process(&[0.25]).len();
        total += r.process(&[]).len();
    }
    assert!((total as i64 - 1_600).abs() <= 1, "{total}");
}

#[test]
#[ignore = "A6.DR14: a single NaN or infinite sample must not poison the resampler"]
fn the_resampler_recovers_after_a_non_finite_sample() {
    // One bad sample from a driver must not turn the rest of the stream into NaN (which would be written as silence).
    let mut r = Resampler::new(48_000, 16_000);
    r.process(&[0.1; 480]);
    r.process(&[f32::NAN]);
    let mut tail = Vec::new();
    for _ in 0..20 {
        tail.extend(r.process(&[0.2; 480]));
    }
    let last = &tail[tail.len() - 100..];
    assert!(
        last.iter().all(|s| s.is_finite()),
        "output is still non-finite 9600 samples after the glitch"
    );
}
