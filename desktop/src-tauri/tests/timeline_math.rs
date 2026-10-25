//! Timestamp-anchored placement: nanosecond to frame conversion, gap filling, overlap trimming and tolerance
//! boundaries.

use desktop_lib::audio::timeline::{Placement, Timeline, nanos_to_frames, place_packet};

fn p(pad: usize, skip: usize, take: usize) -> Placement {
    Placement { pad, skip, take }
}

// ---- nanos_to_frames -------------------------------------------------------------------------------------------

#[test]
fn whole_seconds_and_milliseconds_convert_exactly() {
    assert_eq!(nanos_to_frames(0, 16_000), 0);
    assert_eq!(nanos_to_frames(1_000_000_000, 16_000), 16_000);
    assert_eq!(nanos_to_frames(1_000_000, 16_000), 16);
    assert_eq!(nanos_to_frames(10_000_000, 16_000), 160);
    assert_eq!(nanos_to_frames(3_600_000_000_000, 16_000), 57_600_000);
    assert_eq!(nanos_to_frames(1_000_000_000, 48_000), 48_000);
    assert_eq!(nanos_to_frames(1_000_000_000, 8_000), 8_000);
}

#[test]
fn fractional_frames_round_to_nearest_with_halves_going_up() {
    // One frame at 16 kHz is 62 500 ns.
    assert_eq!(nanos_to_frames(31_249, 16_000), 0);
    assert_eq!(nanos_to_frames(31_250, 16_000), 1);
    assert_eq!(nanos_to_frames(62_499, 16_000), 1);
    assert_eq!(nanos_to_frames(93_749, 16_000), 1);
    assert_eq!(nanos_to_frames(93_750, 16_000), 2);
}

#[test]
fn very_long_spans_do_not_overflow() {
    // 213 days of uptime in nanoseconds would overflow a 64-bit multiplication by the rate.
    let ns = 213u64 * 24 * 3600 * 1_000_000_000;
    assert_eq!(nanos_to_frames(ns, 16_000), 213 * 24 * 3600 * 16_000);
    assert_eq!(
        nanos_to_frames(u64::MAX, 16_000),
        ((u64::MAX as u128 * 16_000 + 500_000_000) / 1_000_000_000) as u64
    );
    assert_eq!(nanos_to_frames(u64::MAX, 1), 18_446_744_074);
}

#[test]
fn conversion_is_monotonic() {
    let mut last = 0;
    for ns in (0..5_000_000u64).step_by(997) {
        let frames = nanos_to_frames(ns, 44_100);
        assert!(frames >= last);
        last = frames;
    }
}

// ---- place_packet ----------------------------------------------------------------------------------------------

#[test]
fn a_packet_on_the_cursor_is_appended() {
    assert_eq!(place_packet(1_000, 1_000, 160, 80), p(0, 0, 160));
    assert_eq!(place_packet(0, 0, 160, 0), p(0, 0, 160));
}

#[test]
fn jitter_up_to_the_tolerance_is_absorbed_in_both_directions() {
    assert_eq!(
        place_packet(1_000, 1_080, 160, 80),
        p(0, 0, 160),
        "late by exactly the tolerance"
    );
    assert_eq!(
        place_packet(1_000, 920, 160, 80),
        p(0, 0, 160),
        "early by exactly the tolerance"
    );
}

#[test]
fn one_frame_beyond_the_tolerance_pads_or_skips() {
    assert_eq!(
        place_packet(1_000, 1_081, 160, 80),
        p(81, 0, 160),
        "late by tolerance + 1: the gap becomes silence"
    );
    assert_eq!(
        place_packet(1_000, 919, 160, 80),
        p(0, 81, 79),
        "early by tolerance + 1: the overlap is dropped"
    );
}

#[test]
fn a_packet_entirely_behind_the_cursor_is_dropped() {
    assert_eq!(place_packet(10_000, 0, 160, 80), p(0, 160, 0));
    assert_eq!(place_packet(10_000, 9_000, 160, 80), p(0, 160, 0));
    assert_eq!(
        place_packet(1_000, 800, 0, 80),
        p(0, 0, 0),
        "an empty packet"
    );
}

#[test]
fn a_large_gap_is_padded_in_full() {
    assert_eq!(
        place_packet(0, 16_000 * 600, 160, 80),
        p(16_000 * 600, 0, 160)
    );
}

#[test]
fn padding_plus_content_places_the_packet_at_its_target() {
    for (cursor, target, len) in [
        (0u64, 5_000u64, 160usize),
        (123, 9_999, 1),
        (50_000, 50_081, 4_096),
        (7, 8, 160),
        (1_000, 600, 1_000),
        (1_000, 100, 50),
    ] {
        let placement = place_packet(cursor, target, len, 80);
        let end = cursor + (placement.pad + placement.take) as u64;
        if placement.take == 0 {
            continue; // fully dropped
        }
        if placement.pad > 0 || placement.skip > 0 {
            // Outside the jitter window the first kept frame lands exactly where the timestamp says.
            let first_kept = cursor + placement.pad as u64;
            let wanted = target + placement.skip as u64;
            assert_eq!(first_kept, wanted, "cursor {cursor}, target {target}");
        }
        assert!(end >= cursor);
    }
}

#[test]
fn skip_never_exceeds_the_packet_and_pad_and_take_stay_consistent() {
    for cursor in [0u64, 1, 79, 80, 81, 1_000, 123_456] {
        for target in [
            0u64, 1, 79, 80, 81, 999, 1_000, 1_080, 1_081, 5_000, 123_456, 200_000,
        ] {
            for len in [0usize, 1, 160, 4_096] {
                let placement = place_packet(cursor, target, len, 80);
                assert!(
                    placement.skip <= len,
                    "{cursor}/{target}/{len}: {placement:?}"
                );
                assert_eq!(
                    placement.skip + placement.take,
                    len,
                    "{cursor}/{target}/{len}: {placement:?}"
                );
            }
        }
    }
}

// ---- Timeline --------------------------------------------------------------------------------------------------

#[test]
fn tolerance_is_five_milliseconds() {
    // Rate / 200 = 80 frames at 16 kHz; a packet 5 ms late is appended, 5 ms + 1 frame late is padded.
    let t0 = 1_000u64;
    let mut tl = Timeline::new(16_000);
    assert_eq!(tl.place(t0, Some(t0), 160), p(0, 0, 160));
    // The cursor is at 160 frames = 10 ms. A packet stamped 15 ms (+5 ms late).
    let mut late = tl.clone();
    assert_eq!(late.place(t0, Some(t0 + 15_000_000), 160), p(0, 0, 160));
    let mut later = tl.clone();
    assert_eq!(later.place(t0, Some(t0 + 15_062_500), 160), p(81, 0, 160));
    assert_eq!(tl.cursor(), 160);
}

#[test]
fn packets_without_timestamps_are_appended_back_to_back() {
    let mut tl = Timeline::new(16_000);
    for _ in 0..10 {
        assert_eq!(tl.place(0, None, 160), p(0, 0, 160));
    }
    assert_eq!(tl.cursor(), 1_600);
}

#[test]
fn a_timestamp_before_the_shared_origin_is_treated_as_the_origin() {
    let mut tl = Timeline::new(16_000);
    assert_eq!(
        tl.place(5_000_000_000, Some(4_000_000_000), 160),
        p(0, 0, 160),
        "stamped before T0: placed at the start"
    );
    // A second early packet also maps to frame 0, which is now 160 frames behind the cursor: it is a duplicate.
    assert_eq!(tl.place(5_000_000_000, Some(1), 160), p(0, 160, 0));
    assert_eq!(tl.cursor(), 160);
}

#[test]
fn a_duplicated_packet_is_dropped_and_does_not_move_the_cursor() {
    let t0 = 0u64;
    let mut tl = Timeline::new(16_000);
    assert_eq!(tl.place(t0, Some(0), 160), p(0, 0, 160));
    assert_eq!(tl.place(t0, Some(10_000_000), 160), p(0, 0, 160));
    // The same packet delivered again, 20 ms stale.
    assert_eq!(tl.place(t0, Some(10_000_000), 160), p(0, 160, 0));
    assert_eq!(tl.cursor(), 320);
}

#[test]
fn skipped_frames_do_not_advance_the_cursor() {
    let mut tl = Timeline::new(16_000);
    tl.place(0, Some(0), 1_600); // 100 ms
    let placement = tl.place(0, Some(50_000_000), 1_600); // starts 50 ms back
    assert_eq!(placement, p(0, 800, 800));
    assert_eq!(tl.cursor(), 2_400);
}

#[test]
fn gaps_are_filled_so_two_channels_stay_aligned() {
    // The system channel goes quiet for 600 ms (endpoint loopback sends nothing); the mic keeps going.
    let mut mic = Timeline::new(16_000);
    let mut sys = Timeline::new(16_000);
    let t0 = 7_000_000_000u64;
    for i in 0..300u64 {
        let ts = t0 + i * 10_000_000;
        mic.place(t0, Some(ts), 160);
        let quiet = (100..160).contains(&i);
        if !quiet {
            sys.place(t0, Some(ts), 160);
        }
    }
    assert_eq!(mic.cursor(), 300 * 160);
    assert_eq!(
        sys.cursor(),
        300 * 160,
        "padding must make the system channel as long as the mic channel"
    );
}

#[test]
fn a_clock_running_slightly_fast_or_slow_keeps_the_channels_within_a_few_milliseconds() {
    for ppm in [-200i64, -80, 0, 80, 200] {
        let mut tl = Timeline::new(16_000);
        let t0 = 1_000_000_000u64;
        let packets = 6_000u64; // 60 s of 10 ms packets
        for i in 0..packets {
            let nominal = i * 10_000_000;
            let drift = nominal as i128 * ppm as i128 / 1_000_000;
            let ts = (t0 as i128 + nominal as i128 + drift) as u64;
            tl.place(t0, Some(ts), 160);
        }
        let ideal = packets * 160;
        let off = (tl.cursor() as i64 - ideal as i64).abs();
        // 200 ppm over 60 s is 12 ms = 192 frames; the timeline follows the stamps, so it must stay within that.
        assert!(off <= 200, "{ppm} ppm: cursor off by {off} frames");
    }
}

#[test]
fn advance_moves_the_cursor_by_the_given_frames() {
    let mut tl = Timeline::new(16_000);
    tl.advance(400);
    assert_eq!(tl.cursor(), 400);
    assert_eq!(tl.place(0, None, 160), p(0, 0, 160));
    assert_eq!(tl.cursor(), 560);
}

#[test]
fn other_rates_scale_the_tolerance() {
    // 48 kHz: tolerance = 240 frames (5 ms).
    let mut tl = Timeline::new(48_000);
    tl.place(0, Some(0), 480);
    let mut within = tl.clone();
    assert_eq!(
        within.place(0, Some(10_000_000 + 5_000_000), 480),
        p(0, 0, 480)
    );
    let mut beyond = tl.clone();
    assert_eq!(
        beyond.place(0, Some(10_000_000 + 5_100_000), 480),
        p(245, 0, 480)
    );
}
