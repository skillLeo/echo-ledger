//! Overlay geometry across several monitors, DPI scales and negative desktop coordinates.

use desktop_lib::overlay::geometry::{
    Corner, RectPx, WorkArea, contains, cursor_in_regions, dock, nearest_corner,
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
