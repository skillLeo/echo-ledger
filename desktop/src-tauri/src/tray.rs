//! Tray / menu-bar icon: the app keeps running here when the main window is closed (on a Mac in the Dock as well,
//! `app_life`). The icon shows the app's state
//! (A3.4): on Windows a coloured dot on the app icon while recording, paused, uploading or when something needs
//! attention. On a Mac the icon is a template image, so it follows light and dark menu bars; while recording it is a
//! coloured badge of its own with a clear margin, and a dot in the template's ink means uploading or attention (`look`).
//!
//! A click follows each system: on a Mac the menu opens on a left click, as every menu bar item's does (its first line
//! opens the window); on Windows a left click opens the window and a right click the menu.

use std::sync::Mutex;
use std::sync::atomic::{AtomicU64, Ordering};

use serde::Serialize;
use tauri::image::Image;
use tauri::menu::{CheckMenuItem, Menu, MenuItem, PredefinedMenuItem};
use tauri::tray::{MouseButton, MouseButtonState, TrayIconBuilder, TrayIconEvent};
use tauri::{AppHandle, Manager};

pub const TRAY_ID: &str = "main";

#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum TrayState {
    Idle,
    Recording,
    Paused,
    Uploading,
    Attention,
}

/// The tray state with precedence Recording/Paused > Attention > Uploading > Idle: a visible recording indicator
/// always wins (A3.4).
pub fn tray_state(
    recording: bool,
    paused: bool,
    pending: usize,
    failed_uploads: usize,
    budget_reached: bool,
    uploading: bool,
) -> TrayState {
    if recording {
        return if paused {
            TrayState::Paused
        } else {
            TrayState::Recording
        };
    }
    if pending > 0 || failed_uploads > 0 || budget_reached {
        return TrayState::Attention;
    }
    if uploading {
        TrayState::Uploading
    } else {
        TrayState::Idle
    }
}

/// The tray state when the Ready check says calls will not be recorded (pure): the attention dot, under a recording.
pub fn with_readiness(state: TrayState, calls_not_ready: bool) -> TrayState {
    match state {
        TrayState::Idle | TrayState::Uploading if calls_not_ready => TrayState::Attention,
        other => other,
    }
}

pub fn tooltip(state: TrayState) -> &'static str {
    match state {
        TrayState::Idle => "EchoLedger",
        TrayState::Recording => "EchoLedger — recording",
        TrayState::Paused => "EchoLedger — paused",
        TrayState::Uploading => "EchoLedger — uploading",
        TrayState::Attention => "EchoLedger — needs your attention",
    }
}

/// Status dot colour (RGBA), step 9 of the design system's Radix scales: red recording, amber paused, blue
/// uploading, indigo attention.
pub fn dot_colour(state: TrayState) -> Option<[u8; 4]> {
    match state {
        TrayState::Idle => None,
        TrayState::Recording => Some([0xE5, 0x48, 0x4D, 0xFF]),
        TrayState::Paused => Some([0xFF, 0xC5, 0x3D, 0xFF]),
        TrayState::Uploading => Some([0x00, 0x90, 0xFF, 0xFF]),
        TrayState::Attention => Some([0x3E, 0x63, 0xDD, 0xFF]),
    }
}

/// What the icon looks like in a state.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct TrayLook {
    /// macOS tints it for the menu bar (black and alpha only).
    pub template: bool,
    /// The recording badge's colour and its mark's: a picture that reads on any menu bar without being tinted.
    pub badge: Option<([u8; 4], [u8; 4])>,
    pub dot: Option<[u8; 4]>,
}

/// The Mac's ink in a template image: only the alpha counts.
const TEMPLATE_INK: [u8; 4] = [0, 0, 0, 0xFF];

/// The look of a state: Windows keeps its coloured dots; a Mac stays a template image, except while recording, when a
/// red badge (amber when paused) must show whatever the menu bar's colour.
pub fn look(state: TrayState, macos: bool) -> TrayLook {
    if !macos {
        return TrayLook { template: false, badge: None, dot: dot_colour(state) };
    }
    match state {
        TrayState::Idle => TrayLook { template: true, badge: None, dot: None },
        TrayState::Recording => TrayLook { template: false, badge: Some(([0xE5, 0x48, 0x4D, 0xFF], [0xFF, 0xFF, 0xFF, 0xFF])), dot: None },
        TrayState::Paused => TrayLook { template: false, badge: Some(([0xFF, 0xC5, 0x3D, 0xFF], [0x1C, 0x20, 0x24, 0xFF])), dot: None },
        TrayState::Uploading | TrayState::Attention => TrayLook { template: true, badge: None, dot: Some(TEMPLATE_INK) },
    }
}

/// The recording badge's share of the menu bar's 18 pt icon: 14 pt, centred, so a clear margin keeps it apart from its
/// neighbours like the other items in the menu bar.
pub const BADGE_OF_ICON: f32 = 14.0 / 18.0;

/// The brand's small mark (docs/design/brand/mark-small.svg, in its 64-unit box): the tile's corner radius, the strokes'
/// width, the wave (moved down by `WAVE_SHIFT`) and the ledger line.
const MARK_BOX: f32 = 64.0;
const MARK_RADIUS: f32 = 15.0;
const MARK_STROKE: f32 = 6.5;
const MARK_WAVE_SHIFT: f32 = 5.0;
const MARK_WAVE: &str = "M13.00 24.00C13.25 23.07 14.00 19.82 14.50 18.43C15.00 17.04 15.50 16.09 16.00 15.66C16.50 15.22 17.00 15.39 17.50 15.82C18.00 16.25 18.50 17.24 19.00 18.22C19.50 19.20 20.00 20.56 20.50 21.70C21.00 22.83 21.50 24.09 22.00 25.03C22.50 25.97 23.00 26.81 23.50 27.34C24.00 27.87 24.50 28.14 25.00 28.20C25.50 28.26 26.00 28.02 26.50 27.70C27.00 27.38 27.50 26.81 28.00 26.28C28.50 25.76 29.00 25.09 29.50 24.55C30.00 24.01 30.50 23.45 31.00 23.05C31.50 22.65 32.00 22.33 32.50 22.15C33.00 21.97 33.50 21.92 34.00 21.96C34.50 22.00 35.00 22.19 35.50 22.39C36.00 22.59 36.50 22.89 37.00 23.16C37.50 23.43 38.25 23.86 38.50 24.00H51";
const MARK_LINE: [(f32, f32); 2] = [(13.0, 45.0), (39.0, 45.0)];

/// The mark's strokes as polylines in the 64-unit box: the wave's curves (`M`, `C`, `H` of `MARK_WAVE`) cut into short
/// straight pieces, and the ledger line.
fn mark_strokes() -> Vec<Vec<(f32, f32)>> {
    let numbers = |s: &str| -> Vec<f32> { s.split_whitespace().filter_map(|n| n.parse().ok()).collect() };
    let mut wave = Vec::new();
    let mut at = (0.0, 0.0);
    let mut rest = MARK_WAVE;
    while let Some(command) = rest.chars().next() {
        let end = rest[1..].find(|c: char| c.is_ascii_alphabetic()).map_or(rest.len(), |i| i + 1);
        let args = numbers(&rest[1..end]);
        match (command, args.as_slice()) {
            ('M', [x, y]) => {
                at = (*x, *y);
                wave.push(at);
            }
            ('C', [x1, y1, x2, y2, x, y]) => {
                for step in 1..=8 {
                    let t = step as f32 / 8.0;
                    let u = 1.0 - t;
                    let point = |a: f32, b: f32, c: f32, d: f32| u * u * u * a + 3.0 * u * u * t * b + 3.0 * u * t * t * c + t * t * t * d;
                    wave.push((point(at.0, *x1, *x2, *x), point(at.1, *y1, *y2, *y)));
                }
                at = (*x, *y);
            }
            ('H', [x]) => {
                at = (*x, at.1);
                wave.push(at);
            }
            _ => {}
        }
        rest = &rest[end..];
    }
    let wave = wave.into_iter().map(|(x, y)| (x, y + MARK_WAVE_SHIFT)).collect();
    vec![wave, MARK_LINE.to_vec()]
}

/// Distance from `p` to the segment `a`–`b`.
fn to_segment(p: (f32, f32), a: (f32, f32), b: (f32, f32)) -> f32 {
    let (dx, dy) = (b.0 - a.0, b.1 - a.1);
    let length = dx * dx + dy * dy;
    let t = if length == 0.0 { 0.0 } else { (((p.0 - a.0) * dx + (p.1 - a.1) * dy) / length).clamp(0.0, 1.0) };
    ((p.0 - a.0 - t * dx).powi(2) + (p.1 - a.1 - t * dy).powi(2)).sqrt()
}

/// Paints the recording badge over the whole picture (pure): the brand's small mark, a rounded `badge` of 14 of the 18
/// points, centred with a clear margin, edged with a 1 px rim of its own colour 20 % darker, its mark in `ink` inset as the
/// brand draws it. Drawn from the mark's own curves at this size, so its edges stay sharp on Retina menu bars.
pub fn paint_badge(rgba: &mut [u8], width: u32, height: u32, badge: [u8; 4], ink: [u8; 4]) {
    let size = width.min(height) as f32 * BADGE_OF_ICON;
    let (left, top) = ((width as f32 - size) / 2.0, (height as f32 - size) / 2.0);
    let unit = size / MARK_BOX;
    let radius = MARK_RADIUS * unit;
    let half_stroke = MARK_STROKE * unit / 2.0;
    let strokes = mark_strokes();
    let rim = rim_of(badge);
    let (centre, inner) = ((left + size / 2.0, top + size / 2.0), size / 2.0 - radius);
    for y in 0..height {
        for x in 0..width {
            let at = ((y * width + x) * 4) as usize;
            if at + 3 >= rgba.len() {
                continue;
            }
            let (fx, fy) = (x as f32 + 0.5, y as f32 + 0.5);
            // How far outside the rounded badge this pixel is (negative inside): its edge, smoothed over one pixel, and
            // the rim along it.
            let (qx, qy) = ((fx - centre.0).abs() - inner, (fy - centre.1).abs() - inner);
            let outside = qx.max(0.0).hypot(qy.max(0.0)) + qx.max(qy).min(0.0) - radius;
            let coverage = (0.5 - outside).clamp(0.0, 1.0);
            let on_rim = (outside + BADGE_RIM + 0.5).clamp(0.0, 1.0);
            // The mark, in the badge's own units.
            let p = ((fx - left) / unit, (fy - top) / unit);
            let distance = strokes.iter().flat_map(|s| s.windows(2)).map(|pair| to_segment(p, pair[0], pair[1])).fold(f32::MAX, f32::min) * unit;
            let mark = (half_stroke + 0.5 - distance).clamp(0.0, 1.0);
            for channel in 0..3 {
                let fill = badge[channel] as f32 * (1.0 - on_rim) + rim[channel] as f32 * on_rim;
                rgba[at + channel] = (fill * (1.0 - mark) + ink[channel] as f32 * mark).round() as u8;
            }
            rgba[at + 3] = (coverage * 255.0).round() as u8;
        }
    }
}

/// The badge's rim, in pixels of the 2x picture: one, so the edge reads on a light menu bar too (amber alone is 1.5:1 on
/// it).
pub const BADGE_RIM: f32 = 1.0;

/// The rim's colour (pure): the badge's own, 20 % darker.
pub fn rim_of(badge: [u8; 4]) -> [u8; 4] {
    let darker = |c: u8| (f32::from(c) * 0.8).round() as u8;
    [darker(badge[0]), darker(badge[1]), darker(badge[2]), badge[3]]
}

/// The tray's own picture (v1.5): the small mark of docs/design/brand (the brand's choice below 24 px), drawn for the
/// menu bar's 18 pt at 2x on a Mac (black on transparent, `scripts/tray-icon.mjs`) and for the notification area's 16 px
/// at 2x on Windows. The window icon is the fallback if it ever fails to decode.
#[cfg(target_os = "macos")]
const TRAY_PNG: &[u8] = include_bytes!("../icons/tray-macos.png");
#[cfg(not(target_os = "macos"))]
const TRAY_PNG: &[u8] = include_bytes!("../icons/tray-windows.png");

fn tray_base(app: &AppHandle) -> Option<Image<'static>> {
    Image::from_bytes(TRAY_PNG).ok().or_else(|| app.default_window_icon().map(|i| i.clone().to_owned()))
}

/// Paints a filled dot with a transparent ring in the bottom-right quarter of an RGBA image.
pub fn paint_dot(rgba: &mut [u8], width: u32, height: u32, colour: [u8; 4]) {
    let size = width.min(height) as f32;
    let radius = size * 0.22;
    let ring = size * 0.06;
    let (cx, cy) = (width as f32 - radius - ring, height as f32 - radius - ring);
    for y in 0..height {
        for x in 0..width {
            let d = ((x as f32 + 0.5 - cx).powi(2) + (y as f32 + 0.5 - cy).powi(2)).sqrt();
            let at = ((y * width + x) * 4) as usize;
            if at + 3 >= rgba.len() {
                continue;
            }
            if d <= radius {
                rgba[at..at + 4].copy_from_slice(&colour);
            } else if d <= radius + ring {
                rgba[at + 3] = 0; // cut a ring so the dot reads on any icon colour
            }
        }
    }
}

/// What the menu currently shows; the menu is rebuilt only when this changes.
#[derive(Clone, Debug, PartialEq, Eq, Default)]
struct MenuShape {
    recording: bool,
    paused: bool,
    show_controls: bool,
    /// The recording is a voice note spoken into the microphone.
    mic_note: bool,
    review: usize,
    captions: bool,
    auto: bool,
    /// "Calls: Ready" or "Calls: Not ready — Fix" (Phase 1C); none before the first Ready check.
    calls_ready: Option<bool>,
    /// A viewer's computer records nothing (v1.5): the sentence the menu shows instead of the recording lines.
    viewer: Option<String>,
    /// The main window in screen shares (v2.0): shown, with "Hide from screen shares" and its durations, or hidden with
    /// "Hidden until 14:30 · Show now". None: no line (before the first status).
    main_share: Option<MainShare>,
    /// The card or the captions stay off because their flag did not hold ([`crate::privacy::held_back_line`]).
    held_back: Option<String>,
    /// EchoLedger's windows are off the screen, and why.
    hidden: Option<Hidden>,
    /// Live Translate is listening ([`TRANSLATE_LISTENING`]).
    translate: bool,
}

/// The main window in screen shares, as the tray says it (v2.0).
#[derive(Clone, Debug, PartialEq, Eq)]
pub enum MainShare {
    /// Shown: "Hide from screen shares" opens the durations; `call`: "Until this call ends" is offered.
    Shown { call: bool },
    /// Hidden: the line ("Hidden until 14:30 · Show now", [`crate::privacy::share::tray_line`]) shows it now.
    Hidden(String),
}

/// The tray's entry that hides the main window from screen shares for a while (PLAN_V2_1_POLISH FIX-01: the words say
/// which window): the durations open under it.
pub const TRAY_HIDE_MAIN: &str = "Hide the EchoLedger window from screen shares for…";

/// The durations under [`TRAY_HIDE_MAIN`], by menu id, in order.
pub const HIDE_CHOICES: [(&str, &str); 6] = [
    ("share_hide_call", "Until this call ends"),
    ("share_hide_15", "15 min"),
    ("share_hide_60", "1 hour"),
    ("share_hide_tomorrow", "Until tomorrow"),
    ("share_hide_off", "Until I turn it off"),
    ("share_hide_custom", "Custom…"),
];

/// The choice a duration's menu id stands for; none for "Custom…" (it opens Settings) and other ids.
pub fn hide_choice(id: &str) -> Option<crate::privacy::share::HideFor> {
    use crate::privacy::share::HideFor;
    match id {
        "share_hide_call" => Some(HideFor::CallEnds),
        "share_hide_15" => Some(HideFor::Minutes15),
        "share_hide_60" => Some(HideFor::Hour1),
        "share_hide_tomorrow" => Some(HideFor::Tomorrow),
        "share_hide_off" => Some(HideFor::UntilOff),
        _ => None,
    }
}

/// Why EchoLedger's windows are off the screen, as the tray says it.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Hidden {
    /// "Hide EchoLedger windows" (the shortcut, the tray, Settings).
    Person,
    /// The Mac safety net, during a whole-screen share whose sharing bar is not on screen now (Zoom hid its controls,
    /// the browser's bar was hidden): "Show EchoLedger windows" brings them back.
    Share,
    /// The same with the sharing bar on screen: showing them would last until the next look, so the tray says when
    /// they come back instead.
    ShareBar,
}

/// The tray's line for the privacy service's reasons now (pure): the person's hide first, then a share.
pub fn hidden_line(privacy: &crate::privacy::Privacy) -> Option<Hidden> {
    use crate::privacy::HideReason;
    if privacy.hidden_for(HideReason::Person) {
        Some(Hidden::Person)
    } else if privacy.bar_holds() {
        Some(Hidden::ShareBar)
    } else {
        privacy.hidden_for(HideReason::Share).then_some(Hidden::Share)
    }
}

/// While the sharing bar keeps the windows off (R1): the tray and Settings › Screen-share privacy say exactly this.
pub const HIDDEN_WHILE_SHARING: &str = "Hidden while you share your screen. They come back when the meeting ends.";

/// Numbers the status reads the tray is drawn from (`commands::status` takes one before it reads anything).
static GENERATION: AtomicU64 = AtomicU64::new(0);

/// The number of a status read about to start: a higher one began later, so it saw every change made before it began.
pub fn next_generation() -> u64 {
    GENERATION.fetch_add(1, Ordering::SeqCst) + 1
}

/// What the tray shows, and the status read it came from.
#[derive(Default)]
struct Drawn {
    generation: u64,
    state: Option<TrayState>,
    shape: Option<MenuShape>,
}

#[derive(Default)]
pub struct TrayCache(Mutex<Drawn>);

impl TrayCache {
    /// What the tray must change to show `state` and `shape` from status read `generation`, kept as what it shows. A read
    /// older than the one shown changes nothing: several threads queue drawings on the main thread, and one read earlier
    /// can arrive after a newer one. The lock is let go when this returns: the tray calls that follow wait for the main
    /// thread, and none may wait while holding it.
    fn changes(&self, generation: u64, state: TrayState, shape: MenuShape) -> (Option<TrayState>, Option<MenuShape>) {
        let mut drawn = self.0.lock().unwrap_or_else(|p| p.into_inner());
        if generation < drawn.generation {
            return (None, None);
        }
        drawn.generation = generation;
        let state = (drawn.state != Some(state)).then(|| {
            drawn.state = Some(state);
            state
        });
        let shape = (drawn.shape.as_ref() != Some(&shape)).then(|| {
            drawn.shape = Some(shape.clone());
            shape
        });
        (state, shape)
    }
}

/// One line of the tray menu: an action, a check item (`checked` is `Some`) or a separator (C2: short, grouped).
#[derive(Clone, Debug, PartialEq, Eq)]
enum Entry {
    Item { id: &'static str, label: String, enabled: bool, checked: Option<bool> },
    Separator,
    Submenu { label: String, items: Vec<Entry> },
}

fn item(id: &'static str, label: impl Into<String>) -> Entry {
    Entry::Item { id, label: label.into(), enabled: true, checked: None }
}

/// The menu for a state: open, then recording, then the two switches and the review queue, then quit. A viewer's menu
/// has the viewer sentence (greyed out) where recording, captions and automatic recording were; a recording that was
/// already running when the role changed can still be stopped.
fn menu_entries(shape: &MenuShape) -> Vec<Entry> {
    let mut entries = vec![item("open", "Open EchoLedger")];
    if shape.translate {
        entries.push(item("translate_stop", TRANSLATE_LISTENING));
    }
    // "Hide EchoLedger windows", and while they are off what took them off and how to bring them back.
    match shape.hidden {
        None => entries.push(item("hide_windows", "Hide EchoLedger windows")),
        Some(Hidden::Person) => entries.push(item("show_windows", "Show EchoLedger windows")),
        Some(Hidden::Share) => {
            entries.push(Entry::Item { id: "hidden_share", label: "Hidden while you share your screen".into(), enabled: false, checked: None });
            entries.push(item("show_windows", "Show EchoLedger windows"));
        }
        Some(Hidden::ShareBar) => {
            entries.push(Entry::Item { id: "hidden_share", label: HIDDEN_WHILE_SHARING.into(), enabled: false, checked: None });
        }
    }
    entries.push(Entry::Separator);
    if let Some(sentence) = shape.viewer.as_ref().filter(|_| !shape.recording) {
        entries.push(Entry::Item { id: "viewer", label: sentence.clone(), enabled: false, checked: None });
        if shape.review > 0 {
            entries.push(item("review", format!("Review recordings ({})", shape.review)));
        }
        entries.push(Entry::Separator);
        entries.push(item("quit", "Quit EchoLedger"));
        return entries;
    }
    match shape.calls_ready {
        Some(true) => entries.push(item("ready", "Calls: Ready")),
        Some(false) => entries.push(item("ready", "Calls: Not ready — Fix")),
        None => {}
    }
    // Record now (Phase 1C): a meeting or call on screen is recorded with both sides, anything else as a voice note.
    entries.push(item("record", if shape.recording { "Stop recording" } else { "Record now" }));
    entries.push(Entry::Item {
        id: "pause",
        label: (if shape.paused { "Resume recording" } else { "Pause recording" }).into(),
        enabled: shape.recording,
        checked: None,
    });
    if shape.show_controls {
        entries.push(item("controls", "Show recording controls"));
    }
    entries.push(item("voice_note", "Capture voice note playing now"));
    // Stops only its own note: during a call recording the line is greyed out instead of stopping the call.
    entries.push(Entry::Item {
        id: "mic_note",
        label: (if shape.mic_note { "Stop voice note" } else { "Record a voice note" }).into(),
        enabled: shape.mic_note || !shape.recording,
        checked: None,
    });
    entries.push(Entry::Separator);
    entries.push(Entry::Item { id: "captions", label: "Live captions".into(), enabled: true, checked: Some(shape.captions) });
    entries.push(Entry::Item { id: "auto", label: "Record calls automatically".into(), enabled: true, checked: Some(shape.auto) });
    match &shape.main_share {
        Some(MainShare::Shown { call }) => entries.push(Entry::Submenu {
            label: TRAY_HIDE_MAIN.into(),
            items: HIDE_CHOICES.iter().filter(|(id, _)| *call || *id != "share_hide_call").map(|(id, label)| item(id, *label)).collect(),
        }),
        Some(MainShare::Hidden(line)) => entries.push(item("share_show_now", line.clone())),
        None => {}
    }
    if let Some(line) = &shape.held_back {
        entries.push(Entry::Item { id: "held_back", label: line.clone(), enabled: false, checked: None });
    }
    if shape.review > 0 {
        entries.push(item("review", format!("Review recordings ({})", shape.review)));
    }
    entries.push(Entry::Separator);
    entries.push(item("quit", "Quit EchoLedger"));
    entries
}

fn build_menu(app: &AppHandle, shape: &MenuShape) -> tauri::Result<Menu<tauri::Wry>> {
    let menu = Menu::new(app)?;
    for entry in menu_entries(shape) {
        match entry {
            Entry::Separator => menu.append(&PredefinedMenuItem::separator(app)?)?,
            Entry::Item { id, label, enabled, checked: Some(checked) } => {
                menu.append(&CheckMenuItem::with_id(app, id, label, enabled, checked, None::<&str>)?)?
            }
            Entry::Item { id, label, enabled, checked: None } => menu.append(&MenuItem::with_id(app, id, label, enabled, None::<&str>)?)?,
            Entry::Submenu { label, items } => {
                let submenu = tauri::menu::Submenu::new(app, label, true)?;
                for entry in items {
                    if let Entry::Item { id, label, enabled, .. } = entry {
                        submenu.append(&MenuItem::with_id(app, id, label, enabled, None::<&str>)?)?;
                    }
                }
                menu.append(&submenu)?
            }
        }
    }
    Ok(menu)
}
pub fn create(app: &AppHandle) -> tauri::Result<()> {
    app.manage(TrayCache::default());
    let shape = MenuShape::default();
    let menu = build_menu(app, &shape)?;
    app.state::<TrayCache>().0.lock().unwrap_or_else(|p| p.into_inner()).shape = Some(shape);

    let mut builder = TrayIconBuilder::with_id(TRAY_ID)
        .tooltip(tooltip(TrayState::Idle))
        .menu(&menu)
        .icon_as_template(look(TrayState::Idle, cfg!(target_os = "macos")).template)
        .show_menu_on_left_click(left_click_opens_menu(cfg!(target_os = "macos")));
    if let Some(icon) = tray_base(app) {
        builder = builder.icon(icon);
    }
    builder
        .on_menu_event(|app, event| {
            let app = app.clone();
            let id = event.id.as_ref().to_string();
            tauri::async_runtime::spawn(async move {
                let result = match id.as_str() {
                    "open" => {
                        open_here(&app);
                        Ok(())
                    }
                    crate::app_menu::SETTINGS => {
                        crate::actions::navigate(&app, "settings", None, false);
                        Ok(())
                    }
                    "record" => crate::actions::record_now(&app).await,
                    "ready" => {
                        crate::actions::navigate(&app, "record", None, false);
                        Ok(())
                    }
                    "pause" => crate::actions::toggle_pause(&app).await,
                    "controls" => {
                        crate::actions::set_pill_hidden(&app, false);
                        Ok(())
                    }
                    "captions" => crate::actions::toggle_captions(&app).await,
                    "voice_note" => crate::actions::toggle_voice_note(&app).await,
                    "mic_note" => crate::actions::toggle_mic_note(&app).await,
                    "auto" => {
                        let state = app.state::<crate::AppState>();
                        state
                            .update_settings(|s| s.auto_mode = !s.auto_mode)
                            .map(|_| ())
                    }
                    "review" => {
                        crate::actions::review_waiting(&app);
                        Ok(())
                    }
                    "translate_stop" => {
                        crate::translate::stop(&app);
                        crate::actions::emit_status(&app);
                        Ok(())
                    }
                    "hide_windows" => {
                        crate::privacy::set_hidden(&app, crate::privacy::HideReason::Person, true);
                        Ok(())
                    }
                    "show_windows" => {
                        crate::privacy::show_windows(&app);
                        Ok(())
                    }
                    "share_show_now" => crate::privacy::show_main_in_shares(&app, None),
                    "share_hide_custom" => {
                        crate::actions::open_settings(&app, "share_privacy.main");
                        Ok(())
                    }
                    id if hide_choice(id).is_some() => hide_choice(id).map_or(Ok(()), |choice| crate::privacy::hide_main(&app, choice).map(|_| ())),
                    "quit" => {
                        // Never lose audio on quit: a running recording is kept first (the Dock's Quit does the same). When
                        // macOS's own quit waits for that keeping too, its answer ends the app.
                        crate::actions::save_before_quit(&app).await;
                        if !crate::app_life::terminate_pending() {
                            app.exit(0);
                        }
                        Ok(())
                    }
                    _ => Ok(()),
                };
                if let Err(e) = result {
                    let recording = app.state::<crate::AppState>().capture.is_recording();
                    crate::refusal::say(&app, &e.to_string(), crate::refusal::asked_by_menu(&id, recording));
                }
                crate::actions::emit_status(&app);
            });
        })
        .on_tray_icon_event(|tray, event| {
            tauri_plugin_positioner::on_tray_event(tray.app_handle(), &event);
            // On a Mac the left click opens the menu (its first line opens the window); on Windows it opens the window.
            if !left_click_opens_menu(cfg!(target_os = "macos"))
                && let TrayIconEvent::Click {
                    button: MouseButton::Left,
                    button_state: MouseButtonState::Up,
                    ..
                } = event
            {
                show_main(tray.app_handle());
            }
        })
        .build(app)?;
    Ok(())
}

/// What a left click on the icon opens (pure): the menu on a Mac, as every menu bar item does (with the click opening
/// only the window, the menu could not be found); the window on Windows, where a right click opens the menu.
pub fn left_click_opens_menu(macos: bool) -> bool {
    macos
}

/// "Open EchoLedger" from the menu: on a Mac an open main window comes to the Space in use while it opens (another
/// app full screen, another desktop) instead of taking the person to the Space it is on; a new one opens there anyway.
fn open_here(app: &AppHandle) {
    #[cfg(target_os = "macos")]
    if let Some(window) = app.get_webview_window(crate::lifecycle::MAIN) {
        let _ = app.run_on_main_thread(move || {
            if let Ok(pointer) = window.ns_window() {
                // SAFETY: Tauri hands out the window's own NSWindow, alive while `window` is, on the main thread here.
                crate::spaces::mac::bring_here_while_opening(unsafe { &*(pointer as *const objc2_app_kit::NSWindow) });
            }
        });
    }
    show_main(app);
}

/// Opens the main window (built on demand, C9: closing it destroys it).
pub fn show_main(app: &AppHandle) {
    crate::lifecycle::show_main(app);
}

/// The two tray switches: live captions and automatic call recording.
#[derive(Clone, Debug, Default)]
pub struct Switches {
    pub captions: bool,
    pub auto: bool,
    /// The Ready check (Phase 1C): none before the first check or while signed out.
    pub calls_ready: Option<bool>,
    /// The main window in screen shares (v2.0).
    pub main_share: Option<MainShare>,
    /// A card or captions kept off because its flag did not hold.
    pub held_back: Option<String>,
    /// EchoLedger's windows are off the screen, and why.
    pub hidden: Option<Hidden>,
    /// Live Translate is listening to an app now.
    pub translate: bool,
}

/// While Live Translate runs, whatever its window shows (hidden, or every EchoLedger window off the screen): the tray
/// always says it is listening, with Stop.
pub const TRANSLATE_LISTENING: &str = "Live Translate is listening · Stop";

/// Applies a new state to the tray: icon, tooltip and menu, each only when it changed. `generation`: the status read it
/// comes from ([`next_generation`]), so an older one queued behind it never draws over it; `mic_note`: the recording is a
/// voice note spoken into the microphone; `viewer`: the viewer sentence when this computer may not record.
#[allow(clippy::too_many_arguments)]
pub fn update(app: &AppHandle, generation: u64, state: TrayState, paused: bool, mic_note: bool, pill_hidden: bool, pending: usize, switches: Switches, viewer: Option<String>) {
    let recording = matches!(state, TrayState::Recording | TrayState::Paused);
    let shape = MenuShape {
        recording,
        paused: recording && paused,
        show_controls: recording && pill_hidden,
        mic_note: recording && mic_note,
        review: pending,
        captions: switches.captions,
        auto: switches.auto,
        calls_ready: switches.calls_ready,
        viewer,
        main_share: switches.main_share,
        held_back: switches.held_back,
        hidden: switches.hidden,
        translate: switches.translate,
    };
    // The tray is drawn on the main thread and never waited for: a sync command there (Hide EchoLedger windows, a setting)
    // refreshes the status while other threads (the safety net, uploads) do too, so nobody holds the tray's locks while
    // waiting for the main thread. On the main thread itself this runs at once.
    let handle = app.clone();
    let _ = app.run_on_main_thread(move || draw(&handle, generation, state, shape));
}

/// On the main thread: what changed is decided under the cache's lock ([`TrayCache::changes`]), let go before the tray is
/// touched.
fn draw(app: &AppHandle, generation: u64, state: TrayState, shape: MenuShape) {
    let (Some(cache), Some(tray)) = (app.try_state::<TrayCache>(), app.tray_by_id(TRAY_ID)) else {
        return;
    };
    let (state, shape) = cache.changes(generation, state, shape);
    if let Some(state) = state {
        let _ = tray.set_tooltip(Some(tooltip(state)));
        if let Some(base) = tray_base(app) {
            let shown = look(state, cfg!(target_os = "macos"));
            let mut rgba = base.rgba().to_vec();
            if let Some((badge, ink)) = shown.badge {
                paint_badge(&mut rgba, base.width(), base.height(), badge, ink);
            }
            if let Some(colour) = shown.dot {
                paint_dot(&mut rgba, base.width(), base.height(), colour);
            }
            let _ = tray.set_icon_with_as_template(Some(Image::new_owned(rgba, base.width(), base.height())), shown.template);
        }
    }
    if let Some(shape) = shape
        && let Ok(menu) = build_menu(app, &shape)
    {
        let _ = tray.set_menu(Some(menu));
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn labels(shape: &MenuShape) -> Vec<String> {
        menu_entries(shape)
            .into_iter()
            .map(|e| match e {
                Entry::Separator => "---".into(),
                Entry::Item { label, checked: Some(true), .. } => format!("[x] {label}"),
                Entry::Item { label, checked: Some(false), .. } => format!("[ ] {label}"),
                Entry::Item { label, .. } => label,
                Entry::Submenu { label, items } => format!("{label} ▸ {}", items.iter().map(|i| match i { Entry::Item { label, .. } => label.as_str(), _ => "" }).collect::<Vec<_>>().join(" | ")),
            })
            .collect()
    }

    #[test]
    fn idle_menu_is_short_and_grouped() {
        let shape = MenuShape { auto: true, ..MenuShape::default() };
        assert_eq!(
            labels(&shape),
            ["Open EchoLedger", "Hide EchoLedger windows", "---", "Record now", "Pause recording", "Capture voice note playing now", "Record a voice note", "---", "[ ] Live captions", "[x] Record calls automatically", "---", "Quit EchoLedger"]
        );
        let pause = menu_entries(&shape).into_iter().find(|e| matches!(e, Entry::Item { id: "pause", .. })).unwrap();
        assert!(matches!(pause, Entry::Item { enabled: false, .. }), "pause needs a recording");
    }

    #[test]
    fn recording_menu_shows_its_state_and_the_review_queue() {
        let shape = MenuShape { recording: true, paused: true, show_controls: true, mic_note: false, review: 2, captions: true, auto: false, calls_ready: None, viewer: None, main_share: Some(MainShare::Shown { call: false }), held_back: None, hidden: None, translate: false };
        let labels = labels(&shape);
        assert!(labels.contains(&"Stop recording".to_string()));
        assert!(labels.contains(&"Resume recording".to_string()));
        assert!(labels.contains(&"Show recording controls".to_string()));
        assert!(labels.contains(&"[x] Live captions".to_string()));
        assert!(labels.contains(&"[ ] Record calls automatically".to_string()));
        assert!(labels.contains(&"Review recordings (2)".to_string()));
        assert_eq!(labels.iter().filter(|l| *l == "---").count(), 3);
        assert!(labels.len() <= 15, "the tray menu stays short (with Hide EchoLedger windows since v1.8.1)");
    }

    #[test]
    fn the_main_window_is_hidden_from_screen_shares_for_a_chosen_time_and_shown_with_one_line() {
        let shown = labels(&MenuShape { main_share: Some(MainShare::Shown { call: false }), ..MenuShape::default() });
        assert!(shown.contains(&"Hide the EchoLedger window from screen shares for… ▸ 15 min | 1 hour | Until tomorrow | Until I turn it off | Custom…".to_string()), "{shown:?}");
        let in_call = labels(&MenuShape { main_share: Some(MainShare::Shown { call: true }), ..MenuShape::default() });
        assert!(in_call.contains(&"Hide the EchoLedger window from screen shares for… ▸ Until this call ends | 15 min | 1 hour | Until tomorrow | Until I turn it off | Custom…".to_string()), "offered only while a call is detected");
        let hidden = labels(&MenuShape { main_share: Some(MainShare::Hidden("Hidden until 14:30 · Show now".into())), ..MenuShape::default() });
        assert!(hidden.contains(&"Hidden until 14:30 · Show now".to_string()));
        assert!(!hidden.iter().any(|l| l.starts_with(TRAY_HIDE_MAIN)));
        let held = labels(&MenuShape { held_back: Some(crate::privacy::held_back_line(crate::privacy::ASSIST)), ..MenuShape::default() });
        assert!(held.contains(&"The Live Assist card couldn't be hidden from screen shares, so it stays off.".to_string()), "the tray tells the person");
        let none = labels(&MenuShape::default());
        assert!(!none.iter().any(|l| l.contains("screen share")), "nothing before the first status");
        // Each duration's id stands for its choice; Custom… opens Settings.
        use crate::privacy::share::HideFor;
        assert_eq!(hide_choice("share_hide_call"), Some(HideFor::CallEnds));
        assert_eq!(hide_choice("share_hide_15"), Some(HideFor::Minutes15));
        assert_eq!(hide_choice("share_hide_60"), Some(HideFor::Hour1));
        assert_eq!(hide_choice("share_hide_tomorrow"), Some(HideFor::Tomorrow));
        assert_eq!(hide_choice("share_hide_off"), Some(HideFor::UntilOff));
        assert_eq!(hide_choice("share_hide_custom"), None);
        let source = include_str!("tray.rs");
        assert!(source.contains("\"share_show_now\" => crate::privacy::show_main_in_shares(&app, None)"));
    }

    #[test]
    fn calls_that_will_not_be_recorded_show_on_the_icon() {
        assert_eq!(with_readiness(TrayState::Idle, true), TrayState::Attention);
        assert_eq!(with_readiness(TrayState::Uploading, true), TrayState::Attention);
        assert_eq!(with_readiness(TrayState::Recording, true), TrayState::Recording, "a recording's indicator always wins");
        assert_eq!(with_readiness(TrayState::Idle, false), TrayState::Idle);
    }

    #[test]
    fn the_tray_says_whether_calls_will_be_recorded() {
        let ready = labels(&MenuShape { calls_ready: Some(true), ..MenuShape::default() });
        assert_eq!(&ready[3..5], ["Calls: Ready", "Record now"]);
        let broken = labels(&MenuShape { calls_ready: Some(false), ..MenuShape::default() });
        assert_eq!(broken[3], "Calls: Not ready — Fix");
        let ids: Vec<&str> = menu_entries(&MenuShape { calls_ready: Some(false), ..MenuShape::default() })
            .into_iter()
            .filter_map(|e| match e { Entry::Item { id, .. } => Some(id), _ => None })
            .collect();
        assert_eq!(ids[2], "ready", "the line opens the Ready check");
        assert!(!labels(&MenuShape::default()).iter().any(|l| l.starts_with("Calls:")), "nothing before the first check");
    }

    fn mic_note_entry(shape: &MenuShape) -> Entry {
        menu_entries(shape).into_iter().find(|e| matches!(e, Entry::Item { id: "mic_note", .. })).unwrap()
    }

    #[test]
    fn a_voice_note_is_recorded_and_stopped_from_the_same_line() {
        let idle = mic_note_entry(&MenuShape::default());
        assert!(matches!(idle, Entry::Item { ref label, enabled: true, .. } if label == "Record a voice note"));
        let recording = mic_note_entry(&MenuShape { recording: true, mic_note: true, ..MenuShape::default() });
        assert!(matches!(recording, Entry::Item { ref label, enabled: true, .. } if label == "Stop voice note"));
        let call = mic_note_entry(&MenuShape { recording: true, ..MenuShape::default() });
        assert!(matches!(call, Entry::Item { enabled: false, .. }), "a call recording is not stopped from this line");
    }

    #[test]
    fn the_tray_says_live_translate_is_listening_with_stop_whatever_its_window_shows() {
        let off = labels(&MenuShape::default());
        assert!(!off.iter().any(|l| l.contains("Live Translate")));
        let on = labels(&MenuShape { translate: true, ..MenuShape::default() });
        assert_eq!(on[1], "Live Translate is listening · Stop", "right under Open EchoLedger");
        // With every EchoLedger window hidden, and on a viewer's computer, it is still said.
        let hidden = labels(&MenuShape { translate: true, hidden: Some(Hidden::Person), ..MenuShape::default() });
        assert!(hidden.contains(&TRANSLATE_LISTENING.to_string()) && hidden.contains(&"Show EchoLedger windows".to_string()));
        let viewer = labels(&MenuShape { translate: true, viewer: Some("You are a viewer.".into()), ..MenuShape::default() });
        assert!(viewer.contains(&TRANSLATE_LISTENING.to_string()));
        // Its line stops Live Translate.
        let source = include_str!("tray.rs");
        let arm = &source[source.find("\"translate_stop\" =>").unwrap()..];
        assert!(arm[..arm.find("Ok(())").unwrap()].contains("crate::translate::stop(&app)"));
        let commands = include_str!("commands.rs");
        assert!(commands.contains("translate: crate::translate::is_running()"), "the status says whether it runs");
    }

    #[test]
    fn menu_ids_keep_their_actions() {
        let shape = MenuShape { recording: true, show_controls: true, review: 1, main_share: Some(MainShare::Hidden("Hidden until you show it · Show now".into())), ..MenuShape::default() };
        let ids: Vec<&str> = menu_entries(&shape).into_iter().filter_map(|e| match e { Entry::Item { id, .. } => Some(id), _ => None }).collect();
        assert_eq!(ids, ["open", "hide_windows", "record", "pause", "controls", "voice_note", "mic_note", "captions", "auto", "share_show_now", "review", "quit"]);
    }

    #[test]
    fn the_tray_says_when_the_windows_are_hidden_and_brings_them_back() {
        let person = labels(&MenuShape { hidden: Some(Hidden::Person), ..MenuShape::default() });
        assert_eq!(&person[..3], ["Open EchoLedger", "Show EchoLedger windows", "---"]);
        assert!(!person.contains(&"Hide EchoLedger windows".to_string()));
        let share = MenuShape { hidden: Some(Hidden::Share), ..MenuShape::default() };
        assert_eq!(&labels(&share)[..4], ["Open EchoLedger", "Hidden while you share your screen", "Show EchoLedger windows", "---"]);
        let line = menu_entries(&share).into_iter().find(|e| matches!(e, Entry::Item { id: "hidden_share", .. })).unwrap();
        assert!(matches!(line, Entry::Item { enabled: false, .. }), "the reason is not an action");
        // With the sharing bar on screen "Show" would last until the next look: the tray says when they come back (R1).
        let bar = MenuShape { hidden: Some(Hidden::ShareBar), ..MenuShape::default() };
        assert_eq!(&labels(&bar)[..3], ["Open EchoLedger", "Hidden while you share your screen. They come back when the meeting ends.", "---"]);
        assert!(menu_entries(&bar).iter().all(|e| !matches!(e, Entry::Item { id: "show_windows", .. }) && !matches!(e, Entry::Item { enabled: true, id: "hidden_share", .. })));
    }

    #[test]
    fn the_tray_draws_what_changed_and_never_an_older_status_over_a_newer_one() {
        let cache = TrayCache::default();
        let idle = MenuShape::default();
        assert_eq!(cache.changes(1, TrayState::Idle, idle.clone()), (Some(TrayState::Idle), Some(idle.clone())), "the first draw");
        assert_eq!(cache.changes(2, TrayState::Idle, idle.clone()), (None, None), "nothing changed: nothing is drawn");
        let hidden = MenuShape { hidden: Some(Hidden::Person), ..MenuShape::default() };
        assert_eq!(cache.changes(3, TrayState::Idle, hidden.clone()), (None, Some(hidden.clone())));

        // Two threads read the status, the later read (Y: recording, hidden) is queued on the main thread first, the
        // earlier one (X: idle, shown) after it: Y stays.
        let cache = TrayCache::default();
        let (x, y) = ((TrayState::Idle, idle.clone()), (TrayState::Recording, hidden.clone()));
        assert_eq!(cache.changes(2, y.0, y.1.clone()), (Some(y.0), Some(y.1.clone())));
        assert_eq!(cache.changes(1, x.0, x.1.clone()), (None, None), "an older read changes nothing");
        assert_eq!(cache.changes(2, y.0, y.1.clone()), (None, None), "Y is what the tray shows");
        assert_eq!(cache.changes(3, x.0, x.1.clone()), (Some(x.0), Some(x.1)), "a newer read draws again");
        // The numbers go up, from any thread.
        let first = next_generation();
        let other = std::thread::spawn(next_generation).join().unwrap();
        assert!(other > first && next_generation() > other);
        // commands::status takes its number before it reads anything, and draws with it.
        let commands = include_str!("commands.rs");
        let status = &commands[commands.find("pub fn status(app: &AppHandle) -> AppStatus {").unwrap()..];
        let status = &status[..status.find("\n}\n").unwrap()];
        let taken = status.find("crate::tray::next_generation()").expect("a number is taken");
        for read in ["app.state::<", ".lock()", "UploadQueue::load(", "DecisionFiles::", "capture.", "settings"] {
            assert!(status.find(read).is_none_or(|first| taken < first), "the number is taken before `{read}`");
        }
        assert!(status.contains("crate::tray::update(app, generation,"), "the tray is drawn with it");

        // Structurally: the update any thread calls takes no lock and posts the drawing to the main thread, and no lock
        // is held across a call that waits for the main thread (main_thread.rs holds the whole crate to that).
        let code = include_str!("tray.rs").split("#[cfg(test)]").next().unwrap();
        let body = |name: &str| {
            let at = code.find(name).unwrap_or_else(|| panic!("{name}"));
            &code[at..at + code[at..].find("\n}\n").unwrap()]
        };
        let update = body("pub fn update(");
        assert!(update.contains("app.run_on_main_thread(") && !update.contains(".lock()"), "update never waits for the main thread");
        let draw = body("fn draw(");
        assert!(!draw.contains(".lock()") && draw.contains("cache.changes("), "the locks are taken only inside changes()");
        let changes = body("fn changes(");
        for waits in ["set_menu", "set_tooltip", "set_icon", "build_menu", "Menu::new", "run_on_main_thread"] {
            assert!(!changes.contains(waits), "{waits} under the tray's locks");
        }
    }

    #[test]
    fn a_viewer_s_menu_offers_no_recording_and_says_why() {
        let sentence = "You are a viewer in Acme team: you can open what is shared with you, not record.";
        let shape = MenuShape { auto: true, review: 1, viewer: Some(sentence.into()), ..MenuShape::default() };
        assert_eq!(labels(&shape), ["Open EchoLedger", "Hide EchoLedger windows", "---", sentence, "Review recordings (1)", "---", "Quit EchoLedger"]);
        let line = menu_entries(&shape).into_iter().find(|e| matches!(e, Entry::Item { id: "viewer", .. })).unwrap();
        assert!(matches!(line, Entry::Item { enabled: false, .. }), "the sentence is not an action");

        // A recording that ran before the role changed keeps its Stop line.
        let recording = MenuShape { recording: true, ..shape };
        assert!(labels(&recording).contains(&"Stop recording".to_string()));
    }

    fn windows_icon() -> Image<'static> {
        Image::from_bytes(include_bytes!("../icons/tray-windows.png")).unwrap().to_owned()
    }

    fn mac_icon() -> Image<'static> {
        Image::from_bytes(include_bytes!("../icons/tray-macos.png")).unwrap().to_owned()
    }

    fn pixel(icon: &Image<'_>, x: u32, y: u32) -> [u8; 4] {
        let at = ((y * icon.width() + x) * 4) as usize;
        [icon.rgba()[at], icon.rgba()[at + 1], icon.rgba()[at + 2], icon.rgba()[at + 3]]
    }

    #[test]
    fn the_windows_tray_draws_the_small_mark_on_its_tile() {
        let icon = windows_icon();
        let size = 32;
        assert_eq!((icon.width(), icon.height()), (size, size));
        // A rounded tile: the corner is transparent, the top edge is brand indigo, the ledger line is white.
        assert_eq!(pixel(&icon, 0, 0)[3], 0);
        assert_eq!(pixel(&icon, size / 2, 2), [0x3E, 0x63, 0xDD, 0xFF]);
        assert!(pixel(&icon, size / 2, size * 45 / 64)[..3].iter().all(|&c| c > 0xF0));
    }

    #[test]
    fn the_mac_icon_is_a_template_image_at_2x() {
        let icon = mac_icon();
        assert_eq!((icon.width(), icon.height()), (36, 36), "18 pt at 2x: sharp on Retina");
        let pixels = icon.rgba().as_chunks::<4>().0;
        assert!(pixels.iter().all(|p| p[..3] == [0, 0, 0] || p[3] == 0), "only black and alpha: macOS tints it");
        let drawn = pixels.iter().filter(|p| p[3] > 128).count();
        assert!((80..700).contains(&drawn), "strokes, not a filled tile: {drawn} px");
        assert_eq!(pixel(&icon, 0, 0)[3], 0, "the corners are empty");
        assert_eq!(pixel(&icon, 35, 35)[3], 0);
    }

    #[test]
    fn a_mac_icon_is_a_template_except_while_recording() {
        assert_eq!(look(TrayState::Idle, true), TrayLook { template: true, badge: None, dot: None });
        assert!(!look(TrayState::Recording, true).template, "a red picture must stay red");
        assert_eq!(look(TrayState::Recording, true).badge, Some(([0xE5, 0x48, 0x4D, 0xFF], [0xFF, 0xFF, 0xFF, 0xFF])), "red, its mark white");
        assert_eq!(look(TrayState::Paused, true).badge, Some(([0xFF, 0xC5, 0x3D, 0xFF], [0x1C, 0x20, 0x24, 0xFF])), "amber, its mark dark");
        assert!(!look(TrayState::Paused, true).template);
        for state in [TrayState::Uploading, TrayState::Attention] {
            let shown = look(state, true);
            assert!(shown.template && shown.badge.is_none());
            assert_eq!(shown.dot, Some(TEMPLATE_INK), "a dot in the template's ink, tinted with the rest");
        }
    }

    #[test]
    fn windows_keeps_its_coloured_dots() {
        for state in [TrayState::Idle, TrayState::Recording, TrayState::Paused, TrayState::Uploading, TrayState::Attention] {
            assert_eq!(look(state, false), TrayLook { template: false, badge: None, dot: dot_colour(state) });
        }
    }

    /// The picture a Mac's menu bar gets in a state, made as `draw` makes it.
    fn mac_picture(state: TrayState) -> Image<'static> {
        let icon = mac_icon();
        let mut rgba = icon.rgba().to_vec();
        let shown = look(state, true);
        if let Some((badge, ink)) = shown.badge {
            paint_badge(&mut rgba, 36, 36, badge, ink);
        }
        if let Some(colour) = shown.dot {
            paint_dot(&mut rgba, 36, 36, colour);
        }
        Image::new_owned(rgba, 36, 36)
    }

    /// The box (left, top, right, bottom, inclusive) of the pixels `keep` picks.
    fn bounds(icon: &Image<'_>, keep: impl Fn([u8; 4]) -> bool) -> (u32, u32, u32, u32) {
        let mut b = (u32::MAX, u32::MAX, 0, 0);
        for y in 0..icon.height() {
            for x in 0..icon.width() {
                if keep(pixel(icon, x, y)) {
                    b = (b.0.min(x), b.1.min(y), b.2.max(x), b.3.max(y));
                }
            }
        }
        b
    }

    #[test]
    fn the_recording_badge_sits_centred_in_a_clear_margin() {
        for state in [TrayState::Recording, TrayState::Paused] {
            let (badge, ink) = look(state, true).badge.unwrap();
            let painted = mac_picture(state);
            // 14 of the 18 points at 2x: 28 px of 36, centred, with 4 px (2 pt) of nothing all round.
            assert_eq!(bounds(&painted, |p| p[3] > 0), (4, 4, 31, 31), "{state:?}");
            for (x, y) in (0..36).flat_map(|y| (0..36).map(move |x| (x, y))).filter(|&(x, y)| !(4..=31).contains(&x) || !(4..=31).contains(&y)) {
                assert_eq!(pixel(&painted, x, y)[3], 0, "{state:?}: the margin at {x},{y} is clear");
            }
            // Rounded in the brand's proportion: the corner pixel is cut, the curve smooth; a 1 px rim of the badge's colour,
            // 20 % darker, all round, the badge's own colour right inside it.
            assert_eq!(pixel(&painted, 4, 4)[3], 0, "a rounded corner");
            assert!(pixel(&painted, 5, 6)[3] > 0 && pixel(&painted, 5, 6)[3] < 0xFF, "the curve is smooth");
            let rim = rim_of(badge);
            for (x, y) in [(18, 4), (4, 18), (18, 31), (31, 18)] {
                assert_eq!(pixel(&painted, x, y), rim, "{state:?}: the rim at {x},{y}");
            }
            for (x, y) in [(18, 5), (5, 18), (18, 30), (30, 18)] {
                assert_eq!(pixel(&painted, x, y), badge, "{state:?}: the badge's own colour inside the rim at {x},{y}");
            }
            // The mark in its ink, inset in the badge as the brand draws it, and centred in it.
            let mark = bounds(&painted, |p| p[3] == 0xFF && p[..3] == ink[..3]);
            assert!(mark.0 >= 4 + 4 && mark.2 + 4 <= 31 && mark.1 >= 4 + 7 && mark.3 + 6 <= 31, "{state:?}: the mark at {mark:?}");
            assert!((mark.0 as i32 - 4).abs_diff(31 - mark.2 as i32) <= 1, "{state:?}: as much badge left of the mark as right: {mark:?}");
            let inked = painted.rgba().as_chunks::<4>().0.iter().filter(|p| p[3] == 0xFF && p[..3] == ink[..3]).count();
            assert!(inked > 60, "{state:?}: the mark shows: {inked} px");
        }
        // Idle stays the template image itself.
        assert_eq!(mac_picture(TrayState::Idle).rgba(), mac_icon().rgba());
    }

    /// WCAG contrast of two sRGB colours.
    fn contrast(a: [u8; 4], b: [u8; 4]) -> f64 {
        let luminance = |c: [u8; 4]| {
            let linear = |v: u8| {
                let v = f64::from(v) / 255.0;
                if v <= 0.040_45 { v / 12.92 } else { ((v + 0.055) / 1.055).powf(2.4) }
            };
            0.2126 * linear(c[0]) + 0.7152 * linear(c[1]) + 0.0722 * linear(c[2])
        };
        let (x, y) = (luminance(a), luminance(b));
        (x.max(y) + 0.05) / (x.min(y) + 0.05)
    }

    #[test]
    fn the_badges_edge_reads_on_a_light_menu_bar() {
        let light_bar = [0xF6, 0xF6, 0xF6, 0xFF];
        let (amber, _) = look(TrayState::Paused, true).badge.unwrap();
        assert!(contrast(amber, light_bar) < 1.6, "amber alone hardly shows its edge there");
        assert_eq!(rim_of(amber), [0xCC, 0x9E, 0x31, 0xFF], "20 % darker");
        assert!(contrast(rim_of(amber), light_bar) > 2.2, "{}", contrast(rim_of(amber), light_bar));
        let (red, _) = look(TrayState::Recording, true).badge.unwrap();
        assert_eq!(rim_of(red), [0xB7, 0x3A, 0x3E, 0xFF]);
        assert!(contrast(rim_of(red), light_bar) > contrast(red, light_bar));
        assert_eq!(BADGE_RIM, 1.0, "1 px at 2x: a hairline on Retina");
    }

    #[test]
    fn the_badge_is_the_brands_small_mark() {
        // docs/design/brand/mark-small.svg: the tile's radius, the strokes, the wave moved down and the ledger line.
        let svg = include_str!("../../../docs/design/brand/mark-small.svg");
        assert!(svg.contains("viewBox=\"0 0 64 64\"") && MARK_BOX == 64.0);
        assert!(svg.contains("rx=\"15\"") && MARK_RADIUS == 15.0);
        assert!(svg.contains("stroke-width=\"6.5\"") && MARK_STROKE == 6.5);
        assert!(svg.contains("transform=\"translate(0 5)\"") && MARK_WAVE_SHIFT == 5.0);
        assert!(svg.contains(&format!("d=\"{MARK_WAVE}\"")) && svg.contains("M13 45h26") && MARK_LINE == [(13.0, 45.0), (39.0, 45.0)]);
        // The idle template is drawn from the same wave.
        assert!(include_str!("../../scripts/tray-icon.mjs").contains(MARK_WAVE));
        // The wave as drawn: from (13, 29) along its curves to (51, 29), its lowest point near the brand's 33.2.
        let strokes = mark_strokes();
        let wave = &strokes[0];
        assert_eq!((wave[0], *wave.last().unwrap()), ((13.0, 29.0), (51.0, 29.0)));
        let lowest = wave.iter().map(|p| p.1).fold(f32::MIN, f32::max);
        assert!((33.0..33.4).contains(&lowest), "{lowest}");
    }

    #[test]
    fn a_left_click_opens_the_menu_on_a_mac_and_the_window_on_windows() {
        assert!(left_click_opens_menu(true), "a Mac: like every menu bar item");
        assert!(!left_click_opens_menu(false), "Windows: the window, and the menu on a right click");
        let code = include_str!("tray.rs").split("#[cfg(test)]").next().unwrap();
        assert!(code.contains(".show_menu_on_left_click(left_click_opens_menu(cfg!(target_os = \"macos\")))"));
        let click = &code[code.find(".on_tray_icon_event(").unwrap()..];
        let click = &click[..click.find(".build(app)").unwrap()];
        assert!(click.find("if !left_click_opens_menu(cfg!(target_os = \"macos\"))").unwrap() < click.find("show_main(").unwrap(), "the window opens on a left click only where the menu does not");
        // The menu's first line opens the window.
        assert_eq!(menu_entries(&MenuShape::default())[0], item("open", "Open EchoLedger"));
    }

    /// A PNG of RGBA pixels (zlib's stored blocks: no compression needed for a few icons).
    fn png(width: u32, height: u32, rgba: &[u8]) -> Vec<u8> {
        fn crc(data: &[u8]) -> u32 {
            let mut c = 0xFFFF_FFFFu32;
            for &byte in data {
                c ^= u32::from(byte);
                for _ in 0..8 {
                    c = if c & 1 == 1 { 0xEDB8_8320 ^ (c >> 1) } else { c >> 1 };
                }
            }
            !c
        }
        fn chunk(out: &mut Vec<u8>, kind: &[u8; 4], data: &[u8]) {
            out.extend((data.len() as u32).to_be_bytes());
            let body = [kind.as_slice(), data].concat();
            out.extend(&body);
            out.extend(crc(&body).to_be_bytes());
        }
        let raw: Vec<u8> = rgba.chunks(width as usize * 4).flat_map(|row| std::iter::once(0).chain(row.iter().copied())).collect();
        let mut zlib = vec![0x78, 0x01];
        let blocks: Vec<&[u8]> = raw.chunks(65_535).collect();
        for (i, block) in blocks.iter().enumerate() {
            zlib.push(u8::from(i + 1 == blocks.len()));
            zlib.extend((block.len() as u16).to_le_bytes());
            zlib.extend((!(block.len() as u16)).to_le_bytes());
            zlib.extend(*block);
        }
        let (a, b) = raw.iter().fold((1u32, 0u32), |(a, b), &x| ((a + u32::from(x)) % 65_521, (b + (a + u32::from(x)) % 65_521) % 65_521));
        zlib.extend(((b << 16) | a).to_be_bytes());
        let mut out = vec![0x89, b'P', b'N', b'G', 0x0D, 0x0A, 0x1A, 0x0A];
        chunk(&mut out, b"IHDR", &[width.to_be_bytes().as_slice(), height.to_be_bytes().as_slice(), &[8, 6, 0, 0, 0]].concat());
        chunk(&mut out, b"IDAT", &zlib);
        chunk(&mut out, b"IEND", &[]);
        out
    }

    /// `TRAY_ICONS_OUT=<folder> cargo test tray::tests::the_mac_menu_bar_icons_for_review -- --ignored`: the recording and
    /// paused icons as the menu bar gets them (18 pt at 2x, 36 px), and each next to the idle template icon on a light and
    /// on a dark menu bar (24 pt at 2x, the template tinted as macOS tints it), the strips shown at 200 %.
    #[test]
    #[ignore = "writes PNG files for a design review"]
    fn the_mac_menu_bar_icons_for_review() {
        let Some(out) = std::env::var_os("TRAY_ICONS_OUT").map(std::path::PathBuf::from) else { return };
        std::fs::create_dir_all(&out).unwrap();
        let template = mac_icon();
        for (state, name) in [(TrayState::Recording, "tray-recording-mac"), (TrayState::Paused, "tray-paused-mac")] {
            let icon = mac_picture(state);
            std::fs::write(out.join(format!("{name}.png")), png(36, 36, icon.rgba())).unwrap();
            // A light and a dark menu bar, 48 px high: the idle icon, then this one, 16 px (8 pt) apart.
            let (width, height, zoom) = (16 + 36 + 16 + 36 + 16, 48u32, 2u32);
            for (theme, bar, ink) in [("light", [0xF6, 0xF6, 0xF6], [0u8, 0, 0]), ("dark", [0x26, 0x26, 0x26], [0xFF, 0xFF, 0xFF])] {
                let mut rows = vec![0u8; (width * height * 4) as usize];
                for p in rows.as_chunks_mut::<4>().0 {
                    *p = [bar[0], bar[1], bar[2], 0xFF];
                }
                let mut put = |left: u32, picture: &Image<'_>, tint: Option<[u8; 3]>| {
                    for (y, x) in (0..36).flat_map(|y| (0..36).map(move |x| (y, x))) {
                        let p = pixel(picture, x, y);
                        // macOS draws a template in the menu bar's text colour (85 %); a picture as it is.
                        let (colour, alpha) = match tint {
                            Some(t) => (t, f32::from(p[3]) / 255.0 * 0.85),
                            None => ([p[0], p[1], p[2]], f32::from(p[3]) / 255.0),
                        };
                        let at = (((y + 6) * width + left + x) * 4) as usize;
                        for c in 0..3 {
                            rows[at + c] = (f32::from(rows[at + c]) * (1.0 - alpha) + f32::from(colour[c]) * alpha).round() as u8;
                        }
                    }
                };
                put(16, &template, Some(ink));
                put(16 + 36 + 16, &icon, None);
                let (w, h) = (width * zoom, height * zoom);
                let zoomed: Vec<u8> = (0..h)
                    .flat_map(|y| (0..w).map(move |x| (y, x)))
                    .flat_map(|(y, x)| (0..4).map(move |c| (y, x, c)))
                    .map(|(y, x, c)| rows[(((y / zoom) * width + x / zoom) * 4 + c) as usize])
                    .collect();
                std::fs::write(out.join(format!("{name}-menu-bar-{theme}.png")), png(w, h, &zoomed)).unwrap();
            }
        }
    }

    #[test]
    fn a_status_dot_on_the_template_is_cut_clear_of_the_mark() {
        let icon = mac_icon();
        let mut rgba = icon.rgba().to_vec();
        paint_dot(&mut rgba, 36, 36, TEMPLATE_INK);
        let at = ((30 * 36 + 30) * 4) as usize;
        assert_eq!(&rgba[at..at + 4], &TEMPLATE_INK);
        assert!(rgba.as_chunks::<4>().0.iter().all(|p| p[..3] == [0, 0, 0] || p[3] == 0), "still a template image");
    }

    #[test]
    fn a_status_dot_fits_the_tray_icon() {
        let icon = windows_icon();
        let mut rgba = icon.rgba().to_vec();
        paint_dot(&mut rgba, icon.width(), icon.height(), [0xE5, 0x48, 0x4D, 0xFF]);
        let at = (((icon.height() - 6) * icon.width() + icon.width() - 6) * 4) as usize;
        assert_eq!(&rgba[at..at + 4], &[0xE5, 0x48, 0x4D, 0xFF]);
    }

    #[test]
    fn status_dots_use_the_design_system_colours() {
        assert_eq!(dot_colour(TrayState::Idle), None);
        assert_eq!(dot_colour(TrayState::Recording), Some([0xE5, 0x48, 0x4D, 0xFF]));
        assert_eq!(dot_colour(TrayState::Paused), Some([0xFF, 0xC5, 0x3D, 0xFF]));
        assert_eq!(dot_colour(TrayState::Uploading), Some([0x00, 0x90, 0xFF, 0xFF]));
        assert_eq!(dot_colour(TrayState::Attention), Some([0x3E, 0x63, 0xDD, 0xFF]));
    }
}
