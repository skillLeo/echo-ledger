//! Tray / menu-bar icon: the app keeps running here when the main window is closed. The icon shows the app's state
//! (A3.4): on Windows a coloured dot on the app icon while recording, paused, uploading or when something needs
//! attention. On a Mac the icon is a template image, so it follows light and dark menu bars; while recording it is a
//! coloured tile of its own, and a dot in the template's ink means uploading or attention (`look`).

use std::sync::Mutex;

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
    /// A tile behind the mark, and the mark's colour on it: a picture that reads on any menu bar without being tinted.
    pub tile: Option<([u8; 4], [u8; 4])>,
    pub dot: Option<[u8; 4]>,
}

/// The Mac's ink in a template image: only the alpha counts.
const TEMPLATE_INK: [u8; 4] = [0, 0, 0, 0xFF];

/// The look of a state: Windows keeps its coloured dots; a Mac stays a template image, except while recording, when a
/// red tile (amber when paused) must show whatever the menu bar's colour.
pub fn look(state: TrayState, macos: bool) -> TrayLook {
    if !macos {
        return TrayLook { template: false, tile: None, dot: dot_colour(state) };
    }
    match state {
        TrayState::Idle => TrayLook { template: true, tile: None, dot: None },
        TrayState::Recording => TrayLook { template: false, tile: Some(([0xE5, 0x48, 0x4D, 0xFF], [0xFF, 0xFF, 0xFF, 0xFF])), dot: None },
        TrayState::Paused => TrayLook { template: false, tile: Some(([0xFF, 0xC5, 0x3D, 0xFF], [0x1C, 0x20, 0x24, 0xFF])), dot: None },
        TrayState::Uploading | TrayState::Attention => TrayLook { template: true, tile: None, dot: Some(TEMPLATE_INK) },
    }
}

/// Paints a rounded tile behind a mark that is black on transparent (pure): the mark's alpha decides how much of `ink`
/// shows over `tile`.
pub fn paint_tile(rgba: &mut [u8], width: u32, height: u32, tile: [u8; 4], ink: [u8; 4]) {
    let radius = width.min(height) as f32 * 0.24;
    let (w, h) = (width as f32, height as f32);
    for y in 0..height {
        for x in 0..width {
            let at = ((y * width + x) * 4) as usize;
            if at + 3 >= rgba.len() {
                continue;
            }
            let (fx, fy) = (x as f32 + 0.5, y as f32 + 0.5);
            let (dx, dy) = ((radius - fx).max(fx - (w - radius)).max(0.0), (radius - fy).max(fy - (h - radius)).max(0.0));
            let coverage = (radius + 0.5 - (dx * dx + dy * dy).sqrt()).clamp(0.0, 1.0);
            let mark = rgba[at + 3] as f32 / 255.0;
            for channel in 0..3 {
                rgba[at + channel] = (tile[channel] as f32 * (1.0 - mark) + ink[channel] as f32 * mark).round() as u8;
            }
            rgba[at + 3] = (coverage * 255.0).round() as u8;
        }
    }
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
}

#[derive(Default)]
pub struct TrayCache {
    shape: Mutex<Option<MenuShape>>,
    state: Mutex<Option<TrayState>>,
}

/// One line of the tray menu: an action, a check item (`checked` is `Some`) or a separator (C2: short, grouped).
#[derive(Clone, Debug, PartialEq, Eq)]
enum Entry {
    Item { id: &'static str, label: String, enabled: bool, checked: Option<bool> },
    Separator,
}

fn item(id: &'static str, label: impl Into<String>) -> Entry {
    Entry::Item { id, label: label.into(), enabled: true, checked: None }
}

/// The menu for a state: open, then recording, then the two switches and the review queue, then quit. A viewer's menu
/// has the viewer sentence (greyed out) where recording, captions and automatic recording were; a recording that was
/// already running when the role changed can still be stopped.
fn menu_entries(shape: &MenuShape) -> Vec<Entry> {
    let mut entries = vec![item("open", "Open EchoLedger"), Entry::Separator];
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
        }
    }
    Ok(menu)
}
pub fn create(app: &AppHandle) -> tauri::Result<()> {
    app.manage(TrayCache::default());
    let shape = MenuShape::default();
    let menu = build_menu(app, &shape)?;
    *app.state::<TrayCache>()
        .shape
        .lock()
        .unwrap_or_else(|p| p.into_inner()) = Some(shape);

    let mut builder = TrayIconBuilder::with_id(TRAY_ID)
        .tooltip(tooltip(TrayState::Idle))
        .menu(&menu)
        .icon_as_template(look(TrayState::Idle, cfg!(target_os = "macos")).template)
        .show_menu_on_left_click(false);
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
                        show_main(&app);
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
                    "quit" => {
                        let state = app.state::<crate::AppState>();
                        if state.capture.is_recording() {
                            // Never lose audio on quit: the recording stays as a decision for the next start.
                            let _ = crate::actions::stop_recording_for_quit(&app).await;
                        }
                        app.exit(0);
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
            if let TrayIconEvent::Click {
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

/// Opens the main window (built on demand, C9: closing it destroys it).
pub fn show_main(app: &AppHandle) {
    crate::lifecycle::show_main(app);
}

/// The two tray switches: live captions and automatic call recording.
#[derive(Clone, Copy, Debug, Default)]
pub struct Switches {
    pub captions: bool,
    pub auto: bool,
    /// The Ready check (Phase 1C): none before the first check or while signed out.
    pub calls_ready: Option<bool>,
}

/// Applies a new state to the tray: icon, tooltip and menu, each only when it changed. `mic_note`: the recording is a
/// voice note spoken into the microphone; `viewer`: the viewer sentence when this computer may not record.
#[allow(clippy::too_many_arguments)]
pub fn update(app: &AppHandle, state: TrayState, paused: bool, mic_note: bool, pill_hidden: bool, pending: usize, switches: Switches, viewer: Option<String>) {
    let Some(cache) = app.try_state::<TrayCache>() else {
        return;
    };
    let Some(tray) = app.tray_by_id(TRAY_ID) else {
        return;
    };
    let recording = matches!(state, TrayState::Recording | TrayState::Paused);

    let mut last = cache.state.lock().unwrap_or_else(|p| p.into_inner());
    if *last != Some(state) {
        *last = Some(state);
        let _ = tray.set_tooltip(Some(tooltip(state)));
        if let Some(base) = tray_base(app) {
            let shown = look(state, cfg!(target_os = "macos"));
            let mut rgba = base.rgba().to_vec();
            if let Some((tile, ink)) = shown.tile {
                paint_tile(&mut rgba, base.width(), base.height(), tile, ink);
            }
            if let Some(colour) = shown.dot {
                paint_dot(&mut rgba, base.width(), base.height(), colour);
            }
            let _ = tray.set_icon_with_as_template(Some(Image::new_owned(rgba, base.width(), base.height())), shown.template);
        }
    }
    drop(last);

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
    };
    let mut current = cache.shape.lock().unwrap_or_else(|p| p.into_inner());
    if current.as_ref() != Some(&shape) {
        if let Ok(menu) = build_menu(app, &shape) {
            let _ = tray.set_menu(Some(menu));
        }
        *current = Some(shape);
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
            })
            .collect()
    }

    #[test]
    fn idle_menu_is_short_and_grouped() {
        let shape = MenuShape { auto: true, ..MenuShape::default() };
        assert_eq!(
            labels(&shape),
            ["Open EchoLedger", "---", "Record now", "Pause recording", "Capture voice note playing now", "Record a voice note", "---", "[ ] Live captions", "[x] Record calls automatically", "---", "Quit EchoLedger"]
        );
        let pause = menu_entries(&shape).into_iter().find(|e| matches!(e, Entry::Item { id: "pause", .. })).unwrap();
        assert!(matches!(pause, Entry::Item { enabled: false, .. }), "pause needs a recording");
    }

    #[test]
    fn recording_menu_shows_its_state_and_the_review_queue() {
        let shape = MenuShape { recording: true, paused: true, show_controls: true, mic_note: false, review: 2, captions: true, auto: false, calls_ready: None, viewer: None };
        let labels = labels(&shape);
        assert!(labels.contains(&"Stop recording".to_string()));
        assert!(labels.contains(&"Resume recording".to_string()));
        assert!(labels.contains(&"Show recording controls".to_string()));
        assert!(labels.contains(&"[x] Live captions".to_string()));
        assert!(labels.contains(&"[ ] Record calls automatically".to_string()));
        assert!(labels.contains(&"Review recordings (2)".to_string()));
        assert_eq!(labels.iter().filter(|l| *l == "---").count(), 3);
        assert!(labels.len() <= 13, "the tray menu stays short");
    }

    #[test]
    fn the_tray_says_whether_calls_will_be_recorded() {
        let ready = labels(&MenuShape { calls_ready: Some(true), ..MenuShape::default() });
        assert_eq!(&ready[2..4], ["Calls: Ready", "Record now"]);
        let broken = labels(&MenuShape { calls_ready: Some(false), ..MenuShape::default() });
        assert_eq!(broken[2], "Calls: Not ready — Fix");
        let ids: Vec<&str> = menu_entries(&MenuShape { calls_ready: Some(false), ..MenuShape::default() })
            .into_iter()
            .filter_map(|e| match e { Entry::Item { id, .. } => Some(id), Entry::Separator => None })
            .collect();
        assert_eq!(ids[1], "ready", "the line opens the Ready check");
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
    fn menu_ids_keep_their_actions() {
        let shape = MenuShape { recording: true, show_controls: true, review: 1, ..MenuShape::default() };
        let ids: Vec<&str> = menu_entries(&shape).into_iter().filter_map(|e| match e { Entry::Item { id, .. } => Some(id), Entry::Separator => None }).collect();
        assert_eq!(ids, ["open", "record", "pause", "controls", "voice_note", "mic_note", "captions", "auto", "review", "quit"]);
    }

    #[test]
    fn a_viewer_s_menu_offers_no_recording_and_says_why() {
        let sentence = "You are a viewer in Acme team: you can open what is shared with you, not record.";
        let shape = MenuShape { auto: true, review: 1, viewer: Some(sentence.into()), ..MenuShape::default() };
        assert_eq!(labels(&shape), ["Open EchoLedger", "---", sentence, "Review recordings (1)", "---", "Quit EchoLedger"]);
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
        assert_eq!(look(TrayState::Idle, true), TrayLook { template: true, tile: None, dot: None });
        assert!(!look(TrayState::Recording, true).template, "a red picture must stay red");
        assert!(look(TrayState::Recording, true).tile.is_some());
        assert!(!look(TrayState::Paused, true).template);
        for state in [TrayState::Uploading, TrayState::Attention] {
            let shown = look(state, true);
            assert!(shown.template && shown.tile.is_none());
            assert_eq!(shown.dot, Some(TEMPLATE_INK), "a dot in the template's ink, tinted with the rest");
        }
    }

    #[test]
    fn windows_keeps_its_coloured_dots() {
        for state in [TrayState::Idle, TrayState::Recording, TrayState::Paused, TrayState::Uploading, TrayState::Attention] {
            assert_eq!(look(state, false), TrayLook { template: false, tile: None, dot: dot_colour(state) });
        }
    }

    #[test]
    fn the_recording_tile_is_solid_with_a_white_mark_and_round_corners() {
        let icon = mac_icon();
        let mut rgba = icon.rgba().to_vec();
        let (tile, ink) = look(TrayState::Recording, true).tile.unwrap();
        paint_tile(&mut rgba, 36, 36, tile, ink);
        let painted = Image::new_owned(rgba, 36, 36);
        assert_eq!(pixel(&painted, 0, 0)[3], 0, "a rounded corner");
        assert_eq!(pixel(&painted, 18, 2), tile, "the tile's own colour where the mark is not");
        let strokes = painted.rgba().as_chunks::<4>().0.iter().filter(|p| p[..3] == ink[..3]).count();
        assert!(strokes > 80, "the mark shows in white: {strokes} px");
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
