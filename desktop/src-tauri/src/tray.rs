//! Tray / menu-bar icon: the app keeps running here when the main window is closed. The icon shows the app's state
//! (A3.4): on Windows a coloured dot on the app icon while recording, paused, uploading or when something needs
//! attention. On a Mac the icon is a template image, so it follows light and dark menu bars; while recording it is a
//! coloured tile of its own, and a dot in the template's ink means uploading or attention (`look`).

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
    /// Screen-share privacy (v1.8.1): the demo is on (`Some(true)`), off (`Some(false)`), or EchoLedger is not hidden
    /// from shares at all (none: no line).
    share_demo: Option<bool>,
    /// EchoLedger's windows are off the screen, and why.
    hidden: Option<Hidden>,
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
}

fn item(id: &'static str, label: impl Into<String>) -> Entry {
    Entry::Item { id, label: label.into(), enabled: true, checked: None }
}

/// The menu for a state: open, then recording, then the two switches and the review queue, then quit. A viewer's menu
/// has the viewer sentence (greyed out) where recording, captions and automatic recording were; a recording that was
/// already running when the role changed can still be stopped.
fn menu_entries(shape: &MenuShape) -> Vec<Entry> {
    let mut entries = vec![item("open", "Open EchoLedger")];
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
    match shape.share_demo {
        Some(false) => entries.push(item("share_demo", "Show EchoLedger in screen share for 10 minutes")),
        Some(true) => entries.push(item("share_demo", "Hide EchoLedger from screen share again")),
        None => {}
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
                    "hide_windows" => {
                        crate::privacy::set_hidden(&app, crate::privacy::HideReason::Person, true);
                        Ok(())
                    }
                    "show_windows" => {
                        crate::privacy::show_windows(&app);
                        Ok(())
                    }
                    "share_demo" => {
                        if app.state::<crate::privacy::Privacy>().demo_active(std::time::Instant::now()) {
                            crate::privacy::end_demo(&app);
                        } else {
                            crate::privacy::show_in_shares_for(&app, crate::privacy::DEMO);
                        }
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
    /// The screen-share demo is on; none while EchoLedger is not hidden from shares.
    pub share_demo: Option<bool>,
    /// EchoLedger's windows are off the screen, and why.
    pub hidden: Option<Hidden>,
}

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
        share_demo: switches.share_demo,
        hidden: switches.hidden,
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
            if let Some((tile, ink)) = shown.tile {
                paint_tile(&mut rgba, base.width(), base.height(), tile, ink);
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
        let shape = MenuShape { recording: true, paused: true, show_controls: true, mic_note: false, review: 2, captions: true, auto: false, calls_ready: None, viewer: None, share_demo: Some(false), hidden: None };
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
    fn the_screen_share_demo_is_one_line_that_turns_itself_back() {
        let off = labels(&MenuShape { share_demo: Some(false), ..MenuShape::default() });
        assert!(off.contains(&"Show EchoLedger in screen share for 10 minutes".to_string()));
        let on = labels(&MenuShape { share_demo: Some(true), ..MenuShape::default() });
        assert!(on.contains(&"Hide EchoLedger from screen share again".to_string()));
        let shown_anyway = labels(&MenuShape { share_demo: None, ..MenuShape::default() });
        assert!(!shown_anyway.iter().any(|l| l.contains("screen share")), "nothing to lift while the setting is Off");
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
            .filter_map(|e| match e { Entry::Item { id, .. } => Some(id), Entry::Separator => None })
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
    fn menu_ids_keep_their_actions() {
        let shape = MenuShape { recording: true, show_controls: true, review: 1, share_demo: Some(false), ..MenuShape::default() };
        let ids: Vec<&str> = menu_entries(&shape).into_iter().filter_map(|e| match e { Entry::Item { id, .. } => Some(id), Entry::Separator => None }).collect();
        assert_eq!(ids, ["open", "hide_windows", "record", "pause", "controls", "voice_note", "mic_note", "captions", "auto", "share_demo", "review", "quit"]);
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
        assert!(include_str!("commands.rs").contains("pub fn status(app: &AppHandle) -> AppStatus {\n    // Taken before anything is read: the tray drawing from a later read always wins over an earlier one queued behind it.\n    let generation = crate::tray::next_generation();\n    let state = app.state::<AppState>();"), "taken first in commands::status");

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
