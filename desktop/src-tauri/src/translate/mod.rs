//! Live Translate on the desktop (v1.3, plan B6): one app's sound (WASAPI process loopback that includes the app's
//! process tree: a game, or a browser with all its tabs) → speech gate → Soniox real-time with a 10-minute key from
//! the server → the "translate" overlay window (click-through while locked, topmost, never focused). Windows first;
//! exclusive-fullscreen games hide every overlay, so the UI says borderless or windowed. Nothing is stored.

pub mod gate;
pub mod players;
pub mod protocol;

use std::sync::atomic::{AtomicBool, AtomicU64, Ordering};
use std::sync::{Arc, Mutex, OnceLock};
use std::time::{Duration, Instant};

use futures_util::{SinkExt, StreamExt};
use serde::{Deserialize, Serialize};
use tauri::{AppHandle, Emitter, Manager};
use tokio::sync::mpsc;
use tokio_tungstenite::tungstenite::Message;

#[cfg(any(windows, target_os = "macos"))]
use self::gate::FRAME_SAMPLES;
use self::gate::SpeechGate;
use self::protocol::{LiveTranslateToken, Speed};

/// Overlay window label (main.tsx routes it to the translate view).
pub const WINDOW: &str = "translate";
/// The feed event the overlay listens to.
pub const FEED_EVENT: &str = "translate-feed";
/// 30 s without speech closes the stream (Soniox bills open time); the next word opens it again.
const REST_AFTER_FRAMES: u32 = 300;
const KEEPALIVE_EVERY: Duration = Duration::from_secs(10);
const TAIL_CHARS: usize = 600;

#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "lowercase")]
pub enum Mode {
    Game,
    Watch,
}

impl Mode {
    pub fn key(self) -> &'static str {
        match self {
            Mode::Game => "game",
            Mode::Watch => "watch",
        }
    }
}

/// What the overlay shows (and the main window's status line).
#[derive(Debug, Clone, Default, Serialize, PartialEq)]
pub struct Feed {
    pub mode: Option<Mode>,
    pub app: String,
    pub text: String,
    pub partial: String,
    pub original: String,
    pub status: String,
    pub notice: Option<String>,
    pub locked: bool,
    pub hidden: bool,
    /// The keys that show it again and that lock or move it, as this system writes them (⌘⇧H, Ctrl+Shift+H): hidden,
    /// the window still says it is listening, and how to bring it back.
    pub hide_keys: String,
    pub lock_keys: String,
}

/// The keys of a Live Translate action as this system writes them, tight for the small window: "⌘⇧H", "Ctrl+Shift+H".
pub fn keys(action: crate::shortcuts::Action, macos: bool) -> String {
    action.default_accelerator().map(|a| crate::shortcuts::label(a, macos).replace(" + ", "+")).unwrap_or_default()
}

/// `translate_start`: which app (pid and name), Game or Watch, the language to write in, the languages people speak
/// (hints; empty = any) and the speed preset.
#[derive(Debug, Clone, Deserialize)]
pub struct StartRequest {
    pub pid: u32,
    pub name: String,
    pub mode: Mode,
    pub target: String,
    #[serde(default)]
    pub hints: Vec<String>,
    #[serde(default)]
    pub speed: Speed,
}

/// An app playing sound now.
#[derive(Debug, Clone, Serialize)]
pub struct TranslateApp {
    pub pid: u32,
    pub name: String,
}

struct Running {
    stop: Arc<AtomicBool>,
    feed: Arc<Mutex<Feed>>,
    /// The window it was started with ([`window_generation`]): only that window's going ends it.
    window: u64,
}

/// Numbers the Live Translate windows built: a session belongs to the window it started with, so a Stop then a quick
/// Start never loses the new session when the old window is destroyed.
static WINDOW_GEN: AtomicU64 = AtomicU64::new(0);
/// The window Stop closed and that is not destroyed yet: a Start waits for it to go instead of using it.
static CLOSING: AtomicU64 = AtomicU64::new(0);

/// A Live Translate window was built: its number.
pub fn window_built() -> u64 {
    WINDOW_GEN.fetch_add(1, Ordering::SeqCst) + 1
}

/// The number of the newest Live Translate window.
pub fn window_generation() -> u64 {
    WINDOW_GEN.load(Ordering::SeqCst)
}

/// The window there now is one Stop closed and is still going.
pub fn window_closing() -> bool {
    let current = window_generation();
    current != 0 && CLOSING.load(Ordering::SeqCst) == current
}

/// Whether window `gone` going ends the session (pure): only the session started with it; none runs, nothing to end.
pub fn gone_ends(session_window: Option<u64>, gone: u64) -> bool {
    session_window == Some(gone)
}

fn running() -> &'static Mutex<Option<Running>> {
    static R: OnceLock<Mutex<Option<Running>>> = OnceLock::new();
    R.get_or_init(|| Mutex::new(None))
}

/// Why Live Translate cannot start on this computer now.
#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum Unavailable {
    /// This system has no capture of one app's sound for it yet (a Mac).
    Platform,
    /// The workspace's plan leaves Live Translate out.
    Plan,
}

/// Whether Live Translate can start here, and why not (`live_translate_status`): the page shows it disabled with the
/// reason instead of failing at Start.
#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize)]
pub struct Availability {
    pub available: bool,
    pub reason: Option<Unavailable>,
}

/// Whether this system can hear one app for Live Translate: Windows (its process loopback says so itself when the
/// build is too old), a Mac with macOS 14.2 or later (Core Audio process taps).
pub fn platform_supported() -> bool {
    #[cfg(target_os = "macos")]
    {
        mac_supports(crate::mac_check::native::version())
    }
    #[cfg(not(target_os = "macos"))]
    {
        cfg!(windows)
    }
}

/// macOS 14.2 or later has process taps (pure).
pub fn mac_supports(version: (u32, u32, u32)) -> bool {
    (version.0, version.1) >= crate::mac_check::CALL_AUDIO_FROM
}

/// The availability (pure): the system first (no plan brings it to a Mac), then the plan.
pub fn availability(platform: bool, in_plan: bool) -> Availability {
    let reason = if !platform {
        Some(Unavailable::Platform)
    } else if !in_plan {
        Some(Unavailable::Plan)
    } else {
        None
    };
    Availability { available: reason.is_none(), reason }
}

/// The apps that play sound now (EchoLedger itself left out).
pub fn apps() -> Vec<TranslateApp> {
    #[cfg(windows)]
    {
        let mut list: Vec<TranslateApp> =
            crate::audio::platform::windows::active_playback_processes()
                .into_iter()
                .map(|(pid, image)| TranslateApp {
                    pid,
                    name: display_name(&image),
                })
                .collect();
        list.sort_by_key(|a| a.name.to_lowercase());
        list.dedup_by(|a, b| a.name == b.name);
        list
    }
    #[cfg(target_os = "macos")]
    {
        crate::audio::platform::macos::playing_apps().into_iter().map(|(pid, name)| TranslateApp { pid, name }).collect()
    }
    #[cfg(not(any(windows, target_os = "macos")))]
    {
        Vec::new()
    }
}

/// "chrome.exe" → "Chrome".
pub fn display_name(image: &str) -> String {
    let stem = image.rsplit(['\\', '/']).next().unwrap_or(image);
    let stem = stem
        .strip_suffix(".exe")
        .or_else(|| stem.strip_suffix(".EXE"))
        .unwrap_or(stem);
    let mut chars = stem.chars();
    match chars.next() {
        Some(first) => first.to_uppercase().collect::<String>() + chars.as_str(),
        None => stem.to_string(),
    }
}

pub fn current_feed() -> Option<Feed> {
    running()
        .lock()
        .ok()?
        .as_ref()
        .and_then(|r| r.feed.lock().ok().map(|f| f.clone()))
}

pub fn is_running() -> bool {
    running().lock().map(|r| r.is_some()).unwrap_or(false)
}

/// Edit (movable, takes the mouse) or locked (click-through); Ctrl+Shift+T.
pub fn toggle_lock(app: &AppHandle) {
    let locked = update_feed(app, |f| f.locked = !f.locked)
        .map(|f| f.locked)
        .unwrap_or(true);
    if let Some(w) = app.get_webview_window(WINDOW) {
        let _ = w.set_ignore_cursor_events(locked);
    }
}

/// Hide or show the overlay; Ctrl+Shift+H.
pub fn toggle_hidden(app: &AppHandle) {
    update_feed(app, |f| f.hidden = !f.hidden);
}

fn update_feed(app: &AppHandle, change: impl FnOnce(&mut Feed)) -> Option<Feed> {
    let guard = running().lock().ok()?;
    let r = guard.as_ref()?;
    let mut feed = r.feed.lock().ok()?;
    change(&mut feed);
    let copy = feed.clone();
    let _ = app.emit(FEED_EVENT, &copy);
    Some(copy)
}

/// Ends the session, closes the window and gives Ctrl+Shift+T / H back to other apps.
pub fn stop(app: &AppHandle) {
    stop_session();
    unregister_hotkeys(app);
    let _ = app.emit(
        FEED_EVENT,
        Feed {
            status: "stopped".into(),
            ..Default::default()
        },
    );
    if let Some(w) = app.get_webview_window(WINDOW) {
        CLOSING.store(window_generation(), Ordering::SeqCst);
        let _ = w.close();
    }
}

/// Before a Start builds its window: a window Stop closed is waited for (at most 2 s), so the new session gets a new
/// window instead of the one that is going.
pub async fn wait_closed(app: &AppHandle) -> Result<(), String> {
    let until = Instant::now() + Duration::from_secs(2);
    while window_closing() && app.get_webview_window(WINDOW).is_some() {
        if Instant::now() >= until {
            return Err("Live Translate is still closing. Try again in a moment.".into());
        }
        tokio::time::sleep(Duration::from_millis(50)).await;
    }
    Ok(())
}

/// Window `gone` went, however (closed, destroyed by the system): its session ends (a newer window's never), the keys go
/// back to other apps and the main window's panel says it stopped. Blocking (giving keys back waits for the main
/// thread): run it with `spawn_blocking`, never on the main thread.
pub fn window_gone(app: &AppHandle, gone: u64) {
    let ended = match running().lock() {
        Ok(mut r) if gone_ends(r.as_ref().map(|r| r.window), gone) => r.take().inspect(|run| run.stop.store(true, Ordering::SeqCst)).is_some(),
        _ => false,
    };
    if !ended {
        return;
    }
    unregister_hotkeys(app);
    let _ = app.emit(FEED_EVENT, Feed { status: "stopped".into(), ..Default::default() });
    crate::actions::emit_status(app);
}

fn stop_session() {
    if let Ok(mut r) = running().lock()
        && let Some(run) = r.take()
    {
        run.stop.store(true, Ordering::SeqCst);
    }
}

/// Ctrl+Shift+T (Edit / Lock) and Ctrl+Shift+H (hide), only while Live Translate runs: held all the time they would
/// take "reopen closed tab" and history from every browser. Returns the ones another app already holds.
pub fn register_hotkeys(app: &AppHandle) -> Vec<String> {
    use crate::shortcuts::{Action, Bindings, parse};
    use tauri_plugin_global_shortcut::GlobalShortcutExt;
    let gs = app.global_shortcut();
    let mut taken = Vec::new();
    for action in [Action::TranslateLock, Action::TranslateHide] {
        let Some(accelerator) = action.default_accelerator() else {
            continue;
        };
        match parse(accelerator).and_then(|s| gs.register(s).map(|_| s).map_err(|e| e.to_string()))
        {
            Ok(shortcut) => {
                app.state::<Bindings>()
                    .0
                    .lock()
                    .unwrap()
                    .insert(action, shortcut);
            }
            Err(_) => taken.push(crate::shortcuts::label(accelerator, cfg!(target_os = "macos"))),
        }
    }
    taken
}

fn unregister_hotkeys(app: &AppHandle) {
    use crate::shortcuts::{Action, Bindings};
    use tauri_plugin_global_shortcut::GlobalShortcutExt;
    // Out of the table first, then unregistered with no lock held: each unregister waits for the main thread, and a key
    // press there looks the table up.
    for shortcut in app.state::<Bindings>().take(&[Action::TranslateLock, Action::TranslateHide]) {
        let _ = app.global_shortcut().unregister(shortcut);
    }
}

/// Why Live Translate cannot start on this system.
pub const PLATFORM_TEXT: &str = "Live Translate needs macOS 14.2 or later on a Mac, or Windows 10 21H2 or later.";

/// What Live Translate says when the app picked plays nothing by the time it starts.
pub const STOPPED_PLAYING: &str = "This app stopped playing sound. Play something in it, then start Live Translate again.";

/// The capture thread: `hear` hands it one app's sound (16 kHz mono) until `stop`; it is cut into the speech gate's
/// frames for the session. Why it ended early is said in the window.
#[cfg(any(windows, target_os = "macos"))]
fn spawn_capture(
    app: AppHandle,
    feed: Arc<Mutex<Feed>>,
    stop: Arc<AtomicBool>,
    tx: mpsc::Sender<Vec<i16>>,
    hear: impl FnOnce(&AtomicBool, &mut dyn FnMut(Vec<f32>)) -> Result<(), String> + Send + 'static,
) -> Result<(), String> {
    std::thread::Builder::new()
        .name("el-translate-capture".into())
        .spawn(move || {
            let mut pending: Vec<i16> = Vec::with_capacity(FRAME_SAMPLES * 2);
            let mut send = |samples: Vec<f32>| {
                pending.extend(gate::to_pcm16(&samples, 16_000));
                while pending.len() >= FRAME_SAMPLES {
                    let frame: Vec<i16> = pending.drain(..FRAME_SAMPLES).collect();
                    let _ = tx.blocking_send(frame);
                }
            };
            let result = hear(&stop, &mut send);
            // Stopped by the person (Stop, the window going): they already ended the session. Otherwise the capture
            // ended on its own: the session ends too, the window keeps the reason and the tray stops saying "listening".
            let Some(reason) = ended_reason(stop.load(Ordering::SeqCst), result) else { return };
            stop.store(true, Ordering::SeqCst);
            // Only the session still current says anything: an older one's thread never flashes "ended" on a newer
            // session's window, and never takes its keys.
            if !end_if_current(&stop) {
                return;
            }
            unregister_hotkeys(&app);
            if let Ok(mut f) = feed.lock() {
                f.status = "ended".into();
                f.notice = Some(reason);
                // Hidden, the window said it was listening: it shows why it stopped instead.
                f.hidden = false;
                let _ = app.emit(FEED_EVENT, &*f);
            }
            crate::actions::emit_status(&app);
        })
        .map(|_| ())
        .map_err(|e| e.to_string())
}

/// What Live Translate says when the app's sound ended without a reason given.
pub const STOPPED_HEARING: &str = "Live Translate stopped hearing this app. Start it again to go on.";

/// Why the capture ended on its own (pure): none when the person stopped it; the capture's reason, else
/// [`STOPPED_HEARING`].
pub fn ended_reason(stopped_by_person: bool, result: Result<(), String>) -> Option<String> {
    if stopped_by_person {
        return None;
    }
    Some(result.err().unwrap_or_else(|| STOPPED_HEARING.to_string()))
}

/// Takes the session out of `running` when it is still the one `stop` belongs to (a newer one is left alone).
fn end_if_current(stop: &Arc<AtomicBool>) -> bool {
    match running().lock() {
        Ok(mut r) if r.as_ref().is_some_and(|run| Arc::ptr_eq(&run.stop, stop)) => r.take().is_some(),
        _ => false,
    }
}

/// Starts Live Translate for `pid`; `fetch_token` asks the server for a temporary Soniox key.
#[cfg_attr(not(any(windows, target_os = "macos")), allow(unused_variables))]
pub fn start<F, Fut>(
    app: AppHandle,
    pid: u32,
    name: String,
    mode: Mode,
    speed: Speed,
    fetch_token: F,
) -> Result<(), String>
where
    F: Fn() -> Fut + Send + Sync + 'static,
    Fut: std::future::Future<Output = Result<LiveTranslateToken, String>> + Send + 'static,
{
    stop_session();
    let stop_flag = Arc::new(AtomicBool::new(false));
    let feed = Arc::new(Mutex::new(Feed {
        mode: Some(mode),
        app: name,
        status: "listening".into(),
        locked: true,
        hide_keys: keys(crate::shortcuts::Action::TranslateHide, cfg!(target_os = "macos")),
        lock_keys: keys(crate::shortcuts::Action::TranslateLock, cfg!(target_os = "macos")),
        ..Default::default()
    }));
    *running()
        .lock()
        .map_err(|_| "Live Translate is busy.".to_string())? = Some(Running {
        stop: stop_flag.clone(),
        feed: feed.clone(),
        window: window_generation(),
    });

    let (tx, rx) = mpsc::channel::<Vec<i16>>(64);
    #[cfg(windows)]
    spawn_capture(app.clone(), feed.clone(), stop_flag.clone(), tx, move |stop, send| {
        crate::audio::platform::windows::app_loopback(pid, stop, send)
    })?;
    #[cfg(target_os = "macos")]
    spawn_capture(app.clone(), feed.clone(), stop_flag.clone(), tx, move |stop, send| {
        // The app's processes that play sound now (a browser's helpers with it), read when it starts.
        let pids = crate::audio::platform::macos::playing_pids(pid).ok_or_else(|| STOPPED_PLAYING.to_string())?;
        crate::audio::platform::macos::app_tap(&pids, stop, send)
    })?;
    #[cfg(not(any(windows, target_os = "macos")))]
    {
        drop(tx);
        return Err("Live Translate is not available on this system.".into());
    }
    #[allow(unreachable_code)]
    {
        tauri::async_runtime::spawn(run_session(
            app,
            rx,
            stop_flag,
            feed,
            mode,
            speed,
            fetch_token,
        ));
        Ok(())
    }
}

async fn run_session<F, Fut>(
    app: AppHandle,
    mut rx: mpsc::Receiver<Vec<i16>>,
    stop: Arc<AtomicBool>,
    feed: Arc<Mutex<Feed>>,
    mode: Mode,
    speed: Speed,
    fetch_token: F,
) where
    F: Fn() -> Fut + Send + Sync,
    Fut: std::future::Future<Output = Result<LiveTranslateToken, String>> + Send + 'static,
{
    let mut gate = SpeechGate::new(mode == Mode::Watch);
    let mut attempt: u32 = 0;
    let emit = |f: &Feed| {
        let _ = app.emit(FEED_EVENT, f);
    };
    'outer: while !stop.load(Ordering::SeqCst) {
        // Wait (cheaply) for speech before opening a stream.
        let mut first = Vec::new();
        while first.is_empty() {
            let Some(frame) = rx.recv().await else {
                break 'outer;
            };
            if stop.load(Ordering::SeqCst) {
                break 'outer;
            }
            first = gate.offer(frame).frames;
        }
        let token = match fetch_token().await {
            Ok(t) => t,
            Err(message) => {
                if let Ok(mut f) = feed.lock() {
                    f.notice = Some(message);
                    f.status = "reconnecting".into();
                    emit(&f);
                }
                attempt += 1;
                tokio::time::sleep(Duration::from_millis(
                    (1_000u64 << attempt.min(5)).min(30_000),
                ))
                .await;
                continue;
            }
        };
        let Ok((ws, _)) = tokio_tungstenite::connect_async_tls_with_config(
            token.websocket_url.as_str(),
            None,
            true,
            Some(tokio_tungstenite::Connector::Rustls(
                crate::net::pinning::public_tls_config(),
            )),
        )
        .await
        else {
            if let Ok(mut f) = feed.lock() {
                if attempt == 0 {
                    f.notice = Some("No connection — retrying".into());
                }
                f.status = "reconnecting".into();
                emit(&f);
            }
            attempt += 1;
            tokio::time::sleep(Duration::from_millis(
                (1_000u64 << attempt.min(5)).min(30_000),
            ))
            .await;
            continue;
        };
        attempt = 0;
        let (mut sink, mut stream) = ws.split();
        if sink
            .send(Message::Text(protocol::config(&token, speed).into()))
            .await
            .is_err()
        {
            continue;
        }
        if let Ok(mut f) = feed.lock() {
            f.notice = None;
            f.status = "live".into();
            emit(&f);
        }
        for frame in first {
            let _ = sink.send(Message::Binary(pcm_bytes(&frame).into())).await;
        }
        let mut last_audio = Instant::now();
        let mut keepalive = tokio::time::interval(KEEPALIVE_EVERY);
        loop {
            tokio::select! {
                frame = rx.recv() => {
                    let Some(frame) = frame else { break 'outer };
                    if stop.load(Ordering::SeqCst) { break 'outer; }
                    let out = gate.offer(frame);
                    for f in out.frames {
                        last_audio = Instant::now();
                        if sink.send(Message::Binary(pcm_bytes(&f).into())).await.is_err() { break; }
                    }
                    if gate.quiet_frames >= REST_AFTER_FRAMES {
                        let _ = sink.send(Message::Text(protocol::END_OF_AUDIO.into())).await;
                        let _ = sink.close().await;
                        if let Ok(mut f) = feed.lock() { f.status = "quiet".into(); f.partial.clear(); emit(&f); }
                        break;
                    }
                }
                reply = stream.next() => {
                    let Some(Ok(Message::Text(text))) = reply else { break };
                    let r = protocol::parse(&text);
                    if let Some(code) = r.error_code {
                        if let Ok(mut f) = feed.lock() {
                            f.notice = Some(if code == 402 { "Soniox has no credit left. Add credit to the Soniox account.".into() } else { "Reconnecting…".into() });
                            emit(&f);
                        }
                        break;
                    }
                    if let Ok(mut f) = feed.lock() {
                        f.text = tail(&(f.text.clone() + &r.final_translation));
                        f.partial = r.partial_translation;
                        f.original = tail(&(f.original.clone() + &r.final_original + if r.endpoint { " " } else { "" }));
                        emit(&f);
                    }
                }
                _ = keepalive.tick() => {
                    if last_audio.elapsed() >= KEEPALIVE_EVERY && sink.send(Message::Text(protocol::KEEPALIVE.into())).await.is_err() { break; }
                }
            }
        }
    }
    stop.store(true, Ordering::SeqCst);
}

fn pcm_bytes(frame: &[i16]) -> Vec<u8> {
    frame.iter().flat_map(|s| s.to_le_bytes()).collect()
}

fn tail(text: &str) -> String {
    let count = text.chars().count();
    if count <= TAIL_CHARS {
        text.to_string()
    } else {
        text.chars().skip(count - TAIL_CHARS).collect()
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_keys_are_written_as_each_system_writes_them() {
        use crate::shortcuts::Action;
        assert_eq!(keys(Action::TranslateHide, false), "Ctrl+Shift+H");
        assert_eq!(keys(Action::TranslateLock, false), "Ctrl+Shift+T");
        assert_eq!(keys(Action::TranslateHide, true), "⌘⇧H");
        assert_eq!(keys(Action::TranslateLock, true), "⌘⇧T");
    }

    /// The tests that set the one running session, one at a time.
    static SESSION: Mutex<()> = Mutex::new(());

    #[test]
    fn a_capture_that_ends_on_its_own_ends_its_session_and_says_why() {
        let _one = SESSION.lock().unwrap_or_else(|p| p.into_inner());
        assert_eq!(ended_reason(true, Ok(())), None, "Stop: nothing more to say");
        assert_eq!(ended_reason(true, Err("x".into())), None);
        assert_eq!(ended_reason(false, Err(STOPPED_PLAYING.into())).as_deref(), Some(STOPPED_PLAYING), "the capture's reason");
        assert_eq!(ended_reason(false, Ok(())).as_deref(), Some(STOPPED_HEARING));

        let mine = Arc::new(AtomicBool::new(true));
        *running().lock().unwrap() = Some(Running { stop: mine.clone(), feed: Arc::default(), window: 3 });
        assert!(end_if_current(&mine), "its own session ends: the tray stops saying listening");
        assert!(running().lock().unwrap().is_none());
        // A newer session started meanwhile is left alone.
        let newer = Arc::new(AtomicBool::new(false));
        *running().lock().unwrap() = Some(Running { stop: newer.clone(), feed: Arc::default(), window: 4 });
        assert!(!end_if_current(&mine));
        assert!(running().lock().unwrap().is_some() && !newer.load(Ordering::SeqCst));
        *running().lock().unwrap() = None;
        // The capture thread does it, and says it.
        let source = include_str!("mod.rs");
        let thread = &source[source.find("fn spawn_capture(").unwrap()..];
        let thread = &thread[..thread.find("\n}").unwrap()];
        for step in ["ended_reason(", "status = \"ended\"", "f.hidden = false", "emit_status(&app)"] {
            assert!(thread.contains(step), "{step}");
        }
        // A session no longer current says nothing: the check comes before the keys, the feed and the status.
        let gate = thread.find("if !end_if_current(&stop) {\n                return;\n            }").expect("an old session's thread returns first");
        for step in ["unregister_hotkeys(&app)", "app.emit(FEED_EVENT", "emit_status(&app)"] {
            assert!(thread.find(step).is_some_and(|at| at > gate), "{step} only for the current session");
        }
    }

    #[test]
    fn a_window_gone_any_way_ends_the_session_and_its_keys() {
        let _one = SESSION.lock().unwrap_or_else(|p| p.into_inner());
        let stop = Arc::new(AtomicBool::new(false));
        *running().lock().unwrap() = Some(Running { stop: stop.clone(), feed: Arc::default(), window: 1 });
        stop_session();
        assert!(stop.load(Ordering::SeqCst), "the capture is told to stop");
        assert!(running().lock().unwrap().is_none(), "nothing runs");
        // Stop, then a quick Start in a new window: the old window's going never ends the new session.
        assert!(gone_ends(Some(7), 7), "its own window");
        assert!(!gone_ends(Some(8), 7), "the window before the new session's");
        assert!(!gone_ends(None, 7), "nothing runs: nothing to end");
        // The window's destruction is handled whatever closed it, off the main thread.
        let lib = include_str!("../lib.rs");
        let destroyed = &lib[lib.find("WindowEvent::Destroyed =>").unwrap()..];
        let arm = &destroyed[..destroyed.find("\n            }").unwrap()];
        assert!(arm.contains("translate::WINDOW") && arm.contains("translate::window_gone(&app, gone)"), "{arm}");
        assert!(arm.contains("window_generation()") && arm.find("window_generation()") < arm.find("spawn_blocking"), "the window's number is read when it goes: {arm}");
        assert!(arm.contains("async_runtime::spawn_blocking"), "blocking, never on the main thread: {arm}");
        let gone = include_str!("mod.rs");
        let body = &gone[gone.find("pub fn window_gone").unwrap()..];
        let body = &body[..body.find("\n}").unwrap()];
        assert!(body.contains("unregister_hotkeys(app)") && body.contains("status: \"stopped\""), "{body}");
        // A Start never uses the window Stop closed.
        let commands = include_str!("../commands.rs");
        let start = &commands[commands.find("pub async fn translate_start").unwrap()..];
        assert!(start[..start.find("create_translate_window").unwrap()].contains("wait_closed(&app)"));
    }

    #[test]
    fn live_translate_says_why_it_cannot_start() {
        let none = |platform, plan| serde_json::to_value(availability(platform, plan)).unwrap();
        assert_eq!(none(true, true), serde_json::json!({"available": true, "reason": null}));
        assert_eq!(none(true, false), serde_json::json!({"available": false, "reason": "plan"}));
        assert_eq!(none(false, true), serde_json::json!({"available": false, "reason": "platform"}));
        assert_eq!(none(false, false), serde_json::json!({"available": false, "reason": "platform"}), "the system first: no plan brings it to a Mac");
        #[cfg(target_os = "macos")]
        assert_eq!(platform_supported(), mac_supports(crate::mac_check::native::version()));
        #[cfg(windows)]
        assert!(platform_supported());
        assert!(mac_supports((14, 2, 0)) && mac_supports((15, 0, 0)) && mac_supports((26, 1, 0)));
        assert!(!mac_supports((14, 1, 2)) && !mac_supports((13, 6, 0)), "below 14.2 the reason stays platform");
    }

    #[test]
    fn app_names_read_like_names() {
        assert_eq!(display_name("chrome.exe"), "Chrome");
        assert_eq!(display_name(r"C:\Games\VALORANT.exe"), "VALORANT");
        assert_eq!(display_name("vlc"), "Vlc");
    }

    #[test]
    fn the_feed_keeps_only_its_recent_tail() {
        let long = "a".repeat(TAIL_CHARS + 50) + "end";
        assert!(tail(&long).ends_with("end"));
        assert_eq!(tail(&long).chars().count(), TAIL_CHARS);
        assert_eq!(pcm_bytes(&[1, -2]), vec![1, 0, 0xfe, 0xff]);
    }
}
