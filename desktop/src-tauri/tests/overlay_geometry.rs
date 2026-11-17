//! Overlay geometry across several monitors, DPI scales and negative desktop coordinates.

use desktop_lib::overlay::geometry::{
    Corner, PillPosition, RectPx, WorkArea, caption_overlay_size, clamp_into, contains,
    cursor_in_regions, dock, nearest_corner, pill_origin, top_center,
};

/// Work areas of a plausible desk: a primary screen with a bottom taskbar, a monitor to its left, one above it,
/// a 4K monitor to its right at 150 %, and a portrait monitor further left.
fn desk() -> Vec<(&'static str, WorkArea)> {
    vec![
        (
            "primary",
            WorkArea {
                x: 0,
                y: 0,
                w: 1920,
                h: 1040,
            },
        ),
        (
            "left",
            WorkArea {
                x: -1920,
                y: 0,
                w: 1920,
                h: 1080,
            },
        ),
        (
            "above",
            WorkArea {
                x: 0,
                y: -1080,
                w: 1920,
                h: 1080,
            },
        ),
        (
            "right-4k",
            WorkArea {
                x: 1920,
                y: 0,
                w: 3840,
                h: 2120,
            },
        ),
        (
            "portrait",
            WorkArea {
                x: -3000,
                y: -420,
                w: 1080,
                h: 1880,
            },
        ),
        (
            "taskbar-on-the-left",
            WorkArea {
                x: 48,
                y: 0,
                w: 1872,
                h: 1080,
            },
        ),
    ]
}

fn margin_for(scale: f64) -> i32 {
    (18.0 * scale).round() as i32
}

// ---- dock ------------------------------------------------------------------------------------------------------

#[test]
fn every_corner_of_every_monitor_keeps_the_requested_margin() {
    for (name, area) in desk() {
        let (w, h) = (460u32, 150u32);
        let m = 18;
        assert_eq!(
            dock(area, (w, h), Corner::TopLeft, m),
            (area.x + m, area.y + m),
            "{name} top-left"
        );
        assert_eq!(
            dock(area, (w, h), Corner::TopRight, m),
            (area.x + area.w as i32 - w as i32 - m, area.y + m),
            "{name} top-right"
        );
        assert_eq!(
            dock(area, (w, h), Corner::BottomLeft, m),
            (area.x + m, area.y + area.h as i32 - h as i32 - m),
            "{name} bottom-left"
        );
        assert_eq!(
            dock(area, (w, h), Corner::BottomRight, m),
            (
                area.x + area.w as i32 - w as i32 - m,
                area.y + area.h as i32 - h as i32 - m
            ),
            "{name} bottom-right"
        );
    }
}

#[test]
fn a_docked_window_lies_fully_inside_its_work_area_for_every_scale() {
    for scale in [1.0f64, 1.25, 1.5, 1.75, 2.0, 2.5, 3.0] {
        let size = (
            (460.0 * scale).round() as u32,
            (150.0 * scale).round() as u32,
        );
        for (name, area) in desk() {
            if size.0 > area.w || size.1 > area.h {
                continue; // the caption window does not fit on a narrow portrait monitor at a large scale
            }
            for corner in [
                Corner::TopLeft,
                Corner::TopRight,
                Corner::BottomLeft,
                Corner::BottomRight,
            ] {
                let (x, y) = dock(area, size, corner, margin_for(scale));
                assert!(
                    x >= area.x && y >= area.y,
                    "{name} {corner:?} at {scale}x starts outside: ({x}, {y})"
                );
                assert!(
                    x + size.0 as i32 <= area.x + area.w as i32,
                    "{name} {corner:?} at {scale}x overflows to the right"
                );
                assert!(
                    y + size.1 as i32 <= area.y + area.h as i32,
                    "{name} {corner:?} at {scale}x overflows downwards"
                );
            }
        }
    }
}

#[test]
fn margins_scale_with_the_display_scale_factor() {
    let area = WorkArea {
        x: 0,
        y: 0,
        w: 3840,
        h: 2120,
    };
    for (scale, margin) in [(1.0, 18), (1.25, 23), (1.5, 27), (2.0, 36)] {
        assert_eq!(margin_for(scale), margin);
        let size = ((460.0 * scale) as u32, (150.0 * scale) as u32);
        let (x, y) = dock(area, size, Corner::BottomRight, margin);
        assert_eq!(
            area.w as i32 - (x + size.0 as i32),
            margin,
            "right gap at {scale}x"
        );
        assert_eq!(
            area.h as i32 - (y + size.1 as i32),
            margin,
            "bottom gap at {scale}x"
        );
    }
}

#[test]
fn a_window_larger_than_the_work_area_is_pinned_to_its_origin() {
    for (name, area) in desk() {
        for corner in [
            Corner::TopLeft,
            Corner::TopRight,
            Corner::BottomLeft,
            Corner::BottomRight,
        ] {
            let (x, y) = dock(area, (area.w + 500, area.h + 500), corner, 18);
            assert!(x >= area.x && y >= area.y, "{name} {corner:?}: ({x}, {y})");
        }
        assert_eq!(
            dock(area, (area.w + 1, area.h + 1), Corner::BottomRight, 0),
            (area.x, area.y),
            "{name}"
        );
    }
}

#[test]
fn a_window_exactly_as_large_as_the_area_sits_at_the_origin_even_with_a_margin() {
    let area = WorkArea {
        x: -1920,
        y: 0,
        w: 1920,
        h: 1080,
    };
    assert_eq!(
        dock(area, (1920, 1080), Corner::BottomRight, 18),
        (-1920, 0)
    );
    assert_eq!(dock(area, (1920, 1080), Corner::TopLeft, 0), (-1920, 0));
}

#[test]
fn a_zero_margin_touches_the_edges() {
    let area = WorkArea {
        x: -1920,
        y: -1080,
        w: 1920,
        h: 1080,
    };
    assert_eq!(dock(area, (400, 100), Corner::BottomRight, 0), (-400, -100));
    assert_eq!(dock(area, (400, 100), Corner::TopLeft, 0), (-1920, -1080));
}

#[test]
fn a_zero_sized_window_docks_into_the_exact_corner() {
    let area = WorkArea {
        x: 100,
        y: 200,
        w: 800,
        h: 600,
    };
    assert_eq!(dock(area, (0, 0), Corner::BottomRight, 10), (890, 790));
}

// ---- nearest_corner --------------------------------------------------------------------------------------------

#[test]
fn dragging_snaps_to_the_corner_nearest_the_window_centre_on_every_monitor() {
    for (name, area) in desk() {
        let size = (460u32, 150u32);
        let (right, bottom) = (
            area.x + area.w as i32 - size.0 as i32,
            area.y + area.h as i32 - size.1 as i32,
        );
        assert_eq!(
            nearest_corner(area, (area.x, area.y), size),
            Corner::TopLeft,
            "{name}"
        );
        assert_eq!(
            nearest_corner(area, (right, area.y), size),
            Corner::TopRight,
            "{name}"
        );
        assert_eq!(
            nearest_corner(area, (area.x, bottom), size),
            Corner::BottomLeft,
            "{name}"
        );
        assert_eq!(
            nearest_corner(area, (right, bottom), size),
            Corner::BottomRight,
            "{name}"
        );
    }
}

#[test]
fn the_centre_of_the_window_decides_not_its_top_left_point() {
    let area = WorkArea {
        x: 0,
        y: 0,
        w: 1000,
        h: 1000,
    };
    // Top-left point is in the left half, but the window straddles the middle with its centre on the right.
    assert_eq!(
        nearest_corner(area, (450, 100), (300, 100)),
        Corner::TopRight
    );
    assert_eq!(nearest_corner(area, (450, 100), (80, 100)), Corner::TopLeft);
}

#[test]
fn a_centre_exactly_on_the_midline_goes_right_and_down() {
    let area = WorkArea {
        x: -500,
        y: -500,
        w: 1000,
        h: 1000,
    };
    assert_eq!(
        nearest_corner(area, (-50, -50), (100, 100)),
        Corner::BottomRight
    );
}

#[test]
fn a_window_dragged_partly_off_the_monitor_still_snaps_sensibly() {
    let area = WorkArea {
        x: 0,
        y: 0,
        w: 1920,
        h: 1040,
    };
    assert_eq!(
        nearest_corner(area, (-300, -100), (460, 150)),
        Corner::TopLeft
    );
    assert_eq!(
        nearest_corner(area, (1800, 1000), (460, 150)),
        Corner::BottomRight
    );
    assert_eq!(
        nearest_corner(area, (-5_000, 5_000), (460, 150)),
        Corner::BottomLeft
    );
}

#[test]
fn snapping_then_docking_is_stable() {
    // Snap to a corner, dock there, snap again: the corner must not change (no jitter after a drag).
    for (name, area) in desk() {
        for corner in [
            Corner::TopLeft,
            Corner::TopRight,
            Corner::BottomLeft,
            Corner::BottomRight,
        ] {
            let size = (460, 150);
            let pos = dock(area, size, corner, 18);
            assert_eq!(nearest_corner(area, pos, size), corner, "{name} {corner:?}");
        }
    }
}

// ---- contains --------------------------------------------------------------------------------------------------

#[test]
fn the_work_area_includes_its_origin_and_excludes_its_far_edges() {
    let area = WorkArea {
        x: -1920,
        y: 0,
        w: 1920,
        h: 1080,
    };
    assert!(contains(area, (-1920, 0)));
    assert!(contains(area, (-1, 1079)));
    assert!(!contains(area, (0, 0)), "x = 0 belongs to the next monitor");
    assert!(!contains(area, (-1, 1080)));
    assert!(!contains(area, (-1921, 0)));
    assert!(!contains(area, (-1000, -1)));
}

#[test]
fn every_point_of_the_desk_belongs_to_at_most_one_monitor() {
    let monitors = desk()
        .into_iter()
        .filter(|(name, _)| *name != "taskbar-on-the-left") // an alternative layout for the primary screen
        .collect::<Vec<_>>();
    for x in (-3_200..=5_800).step_by(97) {
        for y in (-1_200..=2_300).step_by(89) {
            let owners: Vec<&str> = monitors
                .iter()
                .filter(|(_, a)| contains(*a, (x, y)))
                .map(|(n, _)| *n)
                .collect();
            assert!(owners.len() <= 1, "({x}, {y}) is claimed by {owners:?}");
        }
    }
    assert_eq!(
        monitors
            .iter()
            .filter(|(_, a)| contains(*a, (960, 500)))
            .count(),
        1
    );
    assert_eq!(
        monitors
            .iter()
            .filter(|(_, a)| contains(*a, (-960, 500)))
            .count(),
        1
    );
    assert_eq!(
        monitors
            .iter()
            .filter(|(_, a)| contains(*a, (3_000, 1_000)))
            .count(),
        1
    );
}

// ---- cursor_in_regions -----------------------------------------------------------------------------------------

fn rect(x: f64, y: f64, w: f64, h: f64) -> RectPx {
    RectPx { x, y, w, h }
}

#[test]
fn the_cursor_is_tested_in_window_coordinates_whatever_the_window_origin() {
    let regions = [rect(10.0, 10.0, 50.0, 20.0)];
    for origin in [(0, 0), (100, 100), (-1920, 0), (-3000, -420), (4000, 2000)] {
        let inside = (origin.0 as f64 + 20.0, origin.1 as f64 + 20.0);
        let outside = (origin.0 as f64 + 5.0, origin.1 as f64 + 20.0);
        assert!(
            cursor_in_regions(inside, origin, &regions),
            "inside at {origin:?}"
        );
        assert!(
            !cursor_in_regions(outside, origin, &regions),
            "outside at {origin:?}"
        );
    }
}

#[test]
fn region_edges_include_the_top_left_and_exclude_the_bottom_right() {
    let regions = [rect(10.0, 20.0, 30.0, 40.0)];
    let at = |x: f64, y: f64| cursor_in_regions((x, y), (0, 0), &regions);
    assert!(at(10.0, 20.0));
    assert!(at(39.999, 59.999));
    assert!(!at(40.0, 30.0));
    assert!(!at(20.0, 60.0));
    assert!(!at(9.999, 30.0));
    assert!(!at(20.0, 19.999));
}

#[test]
fn any_region_may_match_and_degenerate_regions_never_do() {
    let regions = [
        rect(0.0, 0.0, 0.0, 10.0),
        rect(0.0, 0.0, 10.0, 0.0),
        rect(5.0, 5.0, -10.0, -10.0),
        rect(100.0, 100.0, 20.0, 20.0),
    ];
    assert!(!cursor_in_regions((5.0, 5.0), (0, 0), &regions[..3]));
    assert!(cursor_in_regions((110.0, 110.0), (0, 0), &regions));
    assert!(!cursor_in_regions((110.0, 110.0), (0, 0), &[]));
}

#[test]
fn fractional_pixels_from_high_dpi_layouts_are_handled() {
    let regions = [rect(10.5, 10.5, 0.5, 0.5)];
    assert!(cursor_in_regions((10.75, 10.75), (0, 0), &regions));
    assert!(!cursor_in_regions((11.0, 10.75), (0, 0), &regions));
}

#[test]
fn a_cursor_with_non_finite_coordinates_is_never_over_a_region() {
    let regions = [rect(0.0, 0.0, 1_000.0, 1_000.0)];
    assert!(!cursor_in_regions((f64::NAN, 5.0), (0, 0), &regions));
    assert!(!cursor_in_regions((5.0, f64::INFINITY), (0, 0), &regions));
    assert!(!cursor_in_regions(
        (f64::NEG_INFINITY, 5.0),
        (0, 0),
        &regions
    ));
}

// ---- Corner vocabulary -----------------------------------------------------------------------------------------

#[test]
fn corner_names_match_the_settings_file_and_the_ui() {
    for (corner, name) in [
        (Corner::TopLeft, "top_left"),
        (Corner::TopRight, "top_right"),
        (Corner::BottomLeft, "bottom_left"),
        (Corner::BottomRight, "bottom_right"),
    ] {
        assert_eq!(
            serde_json::to_value(corner).unwrap(),
            serde_json::json!(name)
        );
        assert_eq!(
            serde_json::from_value::<Corner>(serde_json::json!(name)).unwrap(),
            corner
        );
    }
    assert_eq!(Corner::default(), Corner::BottomRight);
    assert!(serde_json::from_value::<Corner>(serde_json::json!("center")).is_err());
}

#[test]
fn hit_regions_deserialise_from_the_shape_the_ui_reports() {
    let regions: Vec<RectPx> =
        serde_json::from_str(r#"[{"x": 12.5, "y": 8, "w": 120, "h": 32.25}]"#).unwrap();
    assert_eq!(regions, vec![rect(12.5, 8.0, 120.0, 32.25)]);
}

// ---- Recording pill and caption overlay size -------------------------------------------------------------------

const PRIMARY: WorkArea = WorkArea { x: 0, y: 0, w: 1920, h: 1040 };
const LEFT: WorkArea = WorkArea { x: -1920, y: 0, w: 1920, h: 1080 };
const ABOVE_4K: WorkArea = WorkArea { x: 0, y: -2160, w: 3840, h: 2160 };
/// The pill at 100 % and 150 % scale (280 × 44 logical px).
const PILL_100: (u32, u32) = (280, 44);
const PILL_150: (u32, u32) = (420, 66);

#[test]
fn top_center_centres_horizontally_at_the_top_of_the_work_area() {
    assert_eq!(top_center(PRIMARY, PILL_100, 12), (820, 12));
    assert_eq!(top_center(LEFT, PILL_100, 12), (-1920 + 820, 12));
    assert_eq!(top_center(ABOVE_4K, PILL_150, 18), (1710, -2160 + 18));
    let shifted = WorkArea { x: 48, y: 30, w: 1872, h: 1050 }; // taskbar on the left, toolbar on top
    assert_eq!(top_center(shifted, PILL_100, 12), (48 + 796, 42));
}

#[test]
fn top_center_never_starts_left_of_a_work_area_narrower_than_the_window() {
    let narrow = WorkArea { x: -500, y: 0, w: 200, h: 600 };
    assert_eq!(top_center(narrow, PILL_100, 12), (-500, 12));
}

#[test]
fn clamp_into_moves_a_window_back_inside_the_area() {
    assert_eq!(clamp_into(PRIMARY, (1800, 1020), PILL_100), (1640, 996));
    assert_eq!(clamp_into(PRIMARY, (-50, -10), PILL_100), (0, 0));
    assert_eq!(clamp_into(PRIMARY, (600, 300), PILL_100), (600, 300), "already inside");
    let tiny = WorkArea { x: 10, y: 10, w: 100, h: 20 };
    assert_eq!(clamp_into(tiny, (50, 15), PILL_100), (10, 10), "top-left wins when the window is larger");
}

#[test]
fn a_pill_without_a_saved_position_opens_at_the_top_centre_of_the_primary_screen() {
    let areas = [LEFT, PRIMARY, ABOVE_4K];
    assert_eq!(pill_origin(None, &areas, PRIMARY, PILL_100, 12), (820, 12));
    assert_eq!(pill_origin(None, &areas, PRIMARY, PILL_150, 18), (750, 18));
}

#[test]
fn a_saved_pill_position_on_a_connected_monitor_is_kept() {
    let areas = [LEFT, PRIMARY, ABOVE_4K];
    for saved in [
        PillPosition { x: 100, y: 500 },
        PillPosition { x: -1500, y: 700 },
        PillPosition { x: 2000, y: -1000 },
    ] {
        assert_eq!(pill_origin(Some(saved), &areas, PRIMARY, PILL_100, 12), (saved.x, saved.y), "{saved:?}");
    }
}

#[test]
fn a_saved_pill_position_on_a_monitor_that_is_gone_falls_back_to_the_top_centre() {
    // Saved on the left monitor, which is unplugged now.
    let saved = PillPosition { x: -1500, y: 700 };
    assert_eq!(pill_origin(Some(saved), &[PRIMARY], PRIMARY, PILL_100, 12), (820, 12));
    // Saved in the gap between monitors (never inside a work area).
    let gap = PillPosition { x: 500, y: 1060 };
    assert_eq!(pill_origin(Some(gap), &[PRIMARY, LEFT], PRIMARY, PILL_100, 12), (820, 12));
}

#[test]
fn a_saved_pill_position_near_an_edge_keeps_the_whole_pill_on_that_monitor() {
    // The resolution went down since the pill was saved near the right edge.
    let saved = PillPosition { x: 1900, y: 1030 };
    assert_eq!(pill_origin(Some(saved), &[PRIMARY], PRIMARY, PILL_100, 12), (1640, 996));
    // On the left monitor it is clamped inside the left monitor, not moved to the primary.
    let saved = PillPosition { x: -10, y: 20 };
    assert_eq!(pill_origin(Some(saved), &[LEFT, PRIMARY], PRIMARY, PILL_100, 12), (-280, 20));
}

#[test]
fn the_pill_position_serialises_as_the_settings_file_stores_it() {
    let p = PillPosition { x: -1520, y: 64 };
    assert_eq!(serde_json::to_value(p).unwrap(), serde_json::json!({"x": -1520, "y": 64}));
    assert_eq!(serde_json::from_str::<PillPosition>(r#"{"x": 5, "y": -7}"#).unwrap(), PillPosition { x: 5, y: -7 });
}

#[test]
fn the_caption_overlay_height_follows_the_visible_lines_setting() {
    assert_eq!(caption_overlay_size(2), (460.0, 118.0));
    assert_eq!(caption_overlay_size(3), (460.0, 146.0));
    assert_eq!(caption_overlay_size(5), (460.0, 202.0));
    for other in [0, 1, 4, 6, 255] {
        assert_eq!(caption_overlay_size(other), (460.0, 146.0), "{other} lines");
    }
}

#[test]
fn the_review_card_docks_bottom_right_of_the_primary_work_area() {
    // 360 × 168 logical px with an 18 px margin, at 100 % and 150 %.
    assert_eq!(dock(PRIMARY, (360, 168), Corner::BottomRight, 18), (1920 - 360 - 18, 1040 - 168 - 18));
    assert_eq!(dock(PRIMARY, (540, 252), Corner::BottomRight, 27), (1920 - 540 - 27, 1040 - 252 - 27));
}

// ---- Per-monitor DPI (Windows): a window dragged between 100 % and 150 % monitors ------------------------------------

#[test]
fn hit_regions_follow_the_window_onto_a_monitor_with_another_scale() {
    use desktop_lib::overlay::geometry::{RectPx, cursor_in_regions_scaled, scale_regions};
    // The pill's Stop button, measured on the 100 % monitor: CSS px 300..340 × 20..60 (the core keeps CSS px).
    let css = [RectPx { x: 300.0, y: 20.0, w: 40.0, h: 40.0 }];
    // Dragged onto the 150 % monitor to its right: its client area starts at physical (2000, 100).
    let origin = (2000, 100);
    // The cursor on the button's right part, as Windows reports it there (physical).
    let cursor = (2000.0 + 330.0 * 1.5, 100.0 + 40.0 * 1.5);
    assert!(cursor_in_regions_scaled(cursor, 1.0, origin, 1.0, &scale_regions(&css, 1.5)), "at the scale the window has now");
    assert!(!cursor_in_regions_scaled(cursor, 1.0, origin, 1.0, &scale_regions(&css, 1.0)), "at the old scale the click would go through");
    // Back on the 100 % monitor.
    assert!(cursor_in_regions_scaled((330.0, 40.0), 1.0, (0, 0), 1.0, &scale_regions(&css, 1.0)));
    // A Mac: the same regions made physical at its 2× display and brought back to points come out the same.
    assert!(cursor_in_regions_scaled((330.0 * 2.0, 40.0 * 2.0), 2.0, (0, 0), 2.0, &scale_regions(&css, 2.0)));
    // A scale that is not a number keeps the regions as they are.
    assert_eq!(scale_regions(&css, f64::NAN), css.to_vec());
    assert_eq!(scale_regions(&css, 0.0), css.to_vec());
}

/// A 100 % primary display (1920 × 1080, taskbar 40 px) with a 150 % one to its right (2880 × 1620 physical,
/// taskbar 60 px), as Windows reports them in physical px.
fn mixed_desk() -> Vec<(desktop_lib::overlay::geometry::WorkArea, f64)> {
    use desktop_lib::overlay::geometry::WorkArea;
    vec![(WorkArea { x: 0, y: 0, w: 1920, h: 1040 }, 1.0), (WorkArea { x: 1920, y: 0, w: 2880, h: 1560 }, 1.5)]
}

#[test]
fn a_window_keeps_its_logical_size_on_a_display_with_another_scale() {
    use desktop_lib::overlay::geometry::size_at;
    assert_eq!(size_at((352, 84), 1.0, 1.5), (528, 126), "the pill at 150 %");
    assert_eq!(size_at((528, 126), 1.5, 1.0), (352, 84), "and back");
    assert_eq!(size_at((360, 168), 1.25, 1.75), (504, 235));
    assert_eq!(size_at((352, 84), 1.0, 1.0), (352, 84));
    assert_eq!(size_at((352, 84), f64::NAN, 1.5), (352, 84), "a scale that is not known changes nothing");
}

#[test]
fn the_pill_saved_on_a_150_percent_display_stays_whole_there_at_its_size_there() {
    use desktop_lib::overlay::geometry::{PillPosition, pill_origin_scaled};
    let desk = mixed_desk();
    // Saved near the right edge of the 150 % display; built on the 100 % one (352 × 84 there, 528 × 126 here).
    let saved = Some(PillPosition { x: 4400, y: 1500 });
    assert_eq!(pill_origin_scaled(saved, &desk, 0, (352, 84), 1.0, 12.0), Some((1920 + 2880 - 528, 1560 - 126)), "the whole pill at 150 %");
    // Saved on the 100 % display while the window is on the 150 % one: its size there is the smaller one.
    let saved = Some(PillPosition { x: 1700, y: 900 });
    assert_eq!(pill_origin_scaled(saved, &desk, 0, (528, 126), 1.5, 12.0), Some((1920 - 352, 900)), "352 × 84 there: only x is pulled in");
    // No saved place: top-centre of the primary display, its margin in its own px.
    assert_eq!(pill_origin_scaled(None, &desk, 1, (352, 84), 1.0, 12.0), Some((1920 + (2880 - 528) / 2, 18)));
    assert_eq!(pill_origin_scaled(None, &[], 0, (352, 84), 1.0, 12.0), None);
}

#[test]
fn a_window_rescued_onto_a_display_with_another_scale_fits_it_at_its_size_there() {
    use desktop_lib::overlay::geometry::rescue_scaled;
    let desk = mixed_desk();
    // The 150 % display was unplugged with the Live Assist card on it (360 × 240 logical, 540 × 360 there).
    let only_primary = vec![desk[0]];
    let moved = rescue_scaled(&only_primary, (3000, 400), (540, 360), 1.5).unwrap();
    assert_eq!(moved, (1920 - 360, 400), "kept whole on the 100 % display at 360 × 240");
    // On screen: left alone.
    assert_eq!(rescue_scaled(&desk, (2000, 400), (540, 360), 1.5), None);
    // Moved by a display layout change onto the 150 % display's edge from the 100 % one.
    let moved = rescue_scaled(&desk, (4790, 1500), (360, 240), 1.0).unwrap();
    assert_eq!(moved, (1920 + 2880 - 540, 1560 - 360));
}

#[test]
fn moving_the_windows_to_the_other_display_fits_them_at_their_size_there() {
    use desktop_lib::overlay::geometry::{Corner, Spot, WorkArea, spot_on};
    let inside = |area: WorkArea, at: (i32, i32), size: (u32, u32)| {
        at.0 >= area.x && at.1 >= area.y && at.0 + size.0 as i32 <= area.x + area.w as i32 && at.1 + size.1 as i32 <= area.y + area.h as i32
    };
    // From a 100 % display onto a 200 % one (3840 × 2100 physical): the card is 360 × 168 now, 720 × 336 there.
    let at_200 = WorkArea { x: 1920, y: 0, w: 3840, h: 2100 };
    let card = spot_on(at_200, 2.0, (360, 168), 1.0, Spot::Corner(Corner::BottomRight), 18.0);
    assert_eq!(card, (1920 + 3840 - 720 - 36, 2100 - 336 - 36));
    assert!(inside(at_200, card, (720, 336)), "the whole card, at its size there");
    let pill = spot_on(at_200, 2.0, (352, 84), 1.0, Spot::TopCentre, 12.0);
    assert_eq!(pill, (1920 + (3840 - 704) / 2, 24));
    assert!(inside(at_200, pill, (704, 168)));
    // From a 150 % display onto a 100 % one: the captions (900 × 210 there now) are 600 × 140 there.
    let at_100 = WorkArea { x: -1920, y: 0, w: 1920, h: 1040 };
    let captions = spot_on(at_100, 1.0, (900, 210), 1.5, Spot::Corner(Corner::BottomLeft), 18.0);
    assert_eq!(captions, (-1920 + 18, 1040 - 140 - 18));
    assert!(inside(at_100, captions, (600, 140)));
    // A window larger than the display stays at its top-left corner.
    let tiny = WorkArea { x: 0, y: 0, w: 300, h: 100 };
    assert_eq!(spot_on(tiny, 1.0, (360, 168), 1.0, Spot::Corner(Corner::BottomRight), 18.0), (0, 0));
}

#[test]
fn a_scale_that_cannot_be_read_is_the_last_one_read() {
    use desktop_lib::overlay::geometry::scale_or_last;
    assert_eq!(scale_or_last(Some(1.5), Some(1.0)), 1.5);
    assert_eq!(scale_or_last(None, Some(1.5)), 1.5, "a failed read keeps the window's last scale");
    assert_eq!(scale_or_last(Some(f64::NAN), Some(2.0)), 2.0);
    assert_eq!(scale_or_last(None, None), 1.0, "never read: 1");
    assert_eq!(scale_or_last(Some(0.0), None), 1.0);
}

#[test]
fn live_translate_s_subtitles_sit_bottom_centre_at_their_size_on_the_primary_display() {
    use desktop_lib::overlay::geometry::{Spot, WorkArea, spot_on};
    // Built at 100 % (900 × 140), the primary display at 150 % (2880 × 1560 physical): 1350 × 210 there, 144 px up.
    let primary = WorkArea { x: 0, y: 0, w: 2880, h: 1560 };
    assert_eq!(spot_on(primary, 1.5, (900, 140), 1.0, Spot::BottomCentre, 96.0), ((2880 - 1350) / 2, 1560 - 210 - 144));
    // Game mode: top centre, 16 px down (24 at 150 %).
    assert_eq!(spot_on(primary, 1.5, (560, 132), 1.0, Spot::TopCentre, 16.0), ((2880 - 840) / 2, 24));
    // Wider than a small display: kept at its left edge.
    let small = WorkArea { x: 100, y: 0, w: 800, h: 600 };
    assert_eq!(spot_on(small, 1.0, (900, 140), 1.0, Spot::BottomCentre, 96.0).0, 100);
}
