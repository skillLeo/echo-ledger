//! Live Translate on the desktop (v1.3, plan B6): one app's sound (WASAPI process loopback that includes the app's
//! process tree: a game, or a browser with all its tabs) → speech gate → Soniox real-time with a 10-minute key from
//! the server → the "translate" overlay window (click-through while locked, topmost, never focused). Windows first;
//! exclusive-fullscreen games hide every overlay, so the UI says borderless or windowed. Nothing is stored.

pub mod gate;
pub mod protocol;

use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::{Arc, Mutex, OnceLock};
use std::time::{Duration, Instant};

use futures_util::{SinkExt, StreamExt};
use serde::{Deserialize, Serialize};
use tauri::{AppHandle, Emitter, Manager};
use tokio::sync::mpsc;
use tokio_tungstenite::tungstenite::Message;

use self::gate::{FRAME_SAMPLES, SpeechGate};
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
}

fn running() -> &'static Mutex<Option<Running>> {
    static R: OnceLock<Mutex<Option<Running>>> = OnceLock::new();
    R.get_or_init(|| Mutex::new(None))
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
    #[cfg(not(windows))]
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
        let _ = w.close();
    }
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
            Err(_) => taken.push(accelerator.replace("CommandOrControl", "Ctrl")),
        }
    }
    taken
}

fn unregister_hotkeys(app: &AppHandle) {
    use crate::shortcuts::{Action, Bindings};
    use tauri_plugin_global_shortcut::GlobalShortcutExt;
    let state = app.state::<Bindings>();
    let mut bindings = state.0.lock().unwrap();
    for action in [Action::TranslateLock, Action::TranslateHide] {
        if let Some(shortcut) = bindings.remove(&action) {
            let _ = app.global_shortcut().unregister(shortcut);
        }
    }
}

/// Starts Live Translate for `pid`; `fetch_token` asks the server for a temporary Soniox key.
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
        ..Default::default()
    }));
    *running()
        .lock()
        .map_err(|_| "Live Translate is busy.".to_string())? = Some(Running {
        stop: stop_flag.clone(),
        feed: feed.clone(),
    });

    let (tx, rx) = mpsc::channel::<Vec<i16>>(64);
    #[cfg(windows)]
    {
        let stop_capture = stop_flag.clone();
        let app_for_capture = app.clone();
        let feed_for_capture = feed.clone();
        std::thread::Builder::new()
            .name("el-translate-capture".into())
            .spawn(move || {
                let mut pending: Vec<i16> = Vec::with_capacity(FRAME_SAMPLES * 2);
                let result =
                    crate::audio::platform::windows::app_loopback(pid, &stop_capture, |samples| {
                        pending.extend(gate::to_pcm16(&samples, 16_000));
                        while pending.len() >= FRAME_SAMPLES {
                            let frame: Vec<i16> = pending.drain(..FRAME_SAMPLES).collect();
                            let _ = tx.blocking_send(frame);
                        }
                    });
                if let Err(message) = result
                    && let Ok(mut f) = feed_for_capture.lock()
                {
                    f.notice = Some(message);
                    let _ = app_for_capture.emit(FEED_EVENT, &*f);
                }
            })
            .map_err(|e| e.to_string())?;
    }
    #[cfg(not(windows))]
    {
        drop(tx);
        return Err("Live Translate on the Mac comes in a later version.".into());
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
