//! Screen moments (docs/API.md "Screen moments", since v1.5): during a meeting the desktop keeps what was on the
//! shared screen at each moment, on the recording's clock, so the transcript and an AI reading it know what "this
//! page" meant. A capture thread runs beside every call recording: once a second (`sampler`) it checks the privacy
//! rules (`privacy`), finds the meeting window or the screen (`platform`), samples one frame and lets the detector
//! (`detect`) decide; a kept frame is encoded (`encode`) and sealed on disk (`store`) until the recording reached the
//! server, then sent and deleted (`upload`).

pub mod detect;
pub mod encode;
pub mod platform;
pub mod privacy;
pub mod sampler;
pub mod store;
pub mod upload;
pub mod words;

use std::collections::HashSet;
use std::sync::atomic::{AtomicBool, AtomicU8, AtomicU32, Ordering};
use std::sync::{Arc, Condvar, Mutex, MutexGuard};
use std::thread::JoinHandle;
use std::time::{Duration, Instant};

use chrono::Utc;
use serde::Serialize;
use tauri::{AppHandle, Manager};

use crate::audio::{RecordingInfo, RecordingMode};
use crate::net::api::ApiClient;
use crate::net::models::ServerConfig;
use platform::ScreenSystem;
use privacy::{Facts, HoldReason};
use sampler::{Sample, Sampler};
use store::{KeySource, ScreenKey, ScreenStore};

/// One sample a second, never more.
const SAMPLE_EVERY: Duration = Duration::from_millis(1_000);
/// The project's switch is read at the start of a recording; a server slower than this lets the capture go on (the
/// switch is read again before anything is sent).
const PROJECT_CHECK_FOR: Duration = Duration::from_secs(10);

/// What the recording pill shows (`RecordingState.screens`); none while screens are off for this recording.
#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct ScreensPill {
    /// Screens are being kept now; false while paused, and `reason` says why.
    pub on: bool,
    pub reason: Option<HoldReason>,
    /// Screens kept during this recording.
    pub count: u32,
}

/// What Settings → Screen moments says about this computer and server (`screens_status`).
#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct ScreensStatus {
    /// This system can capture windows and screens.
    pub supported: bool,
    /// `windows`, `macos` or `other`.
    pub platform: String,
    /// The capture draws no border (Windows 11); on Windows 10 a yellow border shows around what is captured.
    pub borderless: bool,
    /// macOS: Screen Recording is allowed for EchoLedger (always true elsewhere).
    pub permission: bool,
    /// The workspace's plan has screen moments.
    pub in_plan: bool,
    /// The server has screen moments (false once it answered that it has no such route).
    pub on_server: bool,
}

/// Wakes the capture thread early (stop).
#[derive(Default)]
struct Wake {
    stop: Mutex<bool>,
    signal: Condvar,
}

impl Wake {
    /// Sleeps `for_` or until stopped; true when stopped.
    fn wait(&self, for_: Duration) -> bool {
        let stop = self.stop.lock().unwrap_or_else(|p| p.into_inner());
        if *stop {
            return true;
        }
        let (stop, _) = self.signal.wait_timeout(stop, for_).unwrap_or_else(|p| p.into_inner());
        *stop
    }

    fn stop(&self) {
        *self.stop.lock().unwrap_or_else(|p| p.into_inner()) = true;
        self.signal.notify_all();
    }

    fn stopped(&self) -> bool {
        *self.stop.lock().unwrap_or_else(|p| p.into_inner())
    }
}

/// What the capture thread shares with the pill and the captions.
#[derive(Default)]
struct Live {
    user_paused: AtomicBool,
    /// Pointing words were heard since the last sample.
    words: AtomicBool,
    /// The recording's project: 0 being read, 1 keeps screens, 2 screens off.
    project: AtomicU8,
    count: AtomicU32,
    pill: Mutex<Option<ScreensPill>>,
}

impl Live {
    fn project(&self) -> Option<bool> {
        match self.project.load(Ordering::SeqCst) {
            1 => Some(true),
            2 => Some(false),
            _ => None,
        }
    }
}

struct Running {
    recording: String,
    wake: Arc<Wake>,
    thread: JoinHandle<()>,
}

/// Screen moments of the app: the key, what the server and the plan said, and the capture of the running recording.
pub struct Screens {
    system: Box<dyn ScreenSystem>,
    keys: Box<dyn KeySource>,
    key: Mutex<Option<Arc<ScreenKey>>>,
    /// The plan leaves screen moments out (`403 feature_not_in_plan`, or the config's plan) until a config says otherwise.
    plan_off: AtomicBool,
    /// The server answered that it has no screen routes (`404 not_found`); for this run.
    server_missing: AtomicBool,
    running: Mutex<Option<Running>>,
    live: Arc<Live>,
}

fn lock<T>(m: &Mutex<T>) -> MutexGuard<'_, T> {
    m.lock().unwrap_or_else(|p| p.into_inner())
}

impl Screens {
    pub fn new(system: Box<dyn ScreenSystem>, keys: Box<dyn KeySource>) -> Self {
        Self { system, keys, key: Mutex::new(None), plan_off: AtomicBool::new(false), server_missing: AtomicBool::new(false), running: Mutex::new(None), live: Arc::default() }
    }

    /// The system's capture (Windows.Graphics.Capture, ScreenCaptureKit), or a stand-in in tests.
    pub fn system(&self) -> &dyn ScreenSystem {
        self.system.as_ref()
    }

    /// The key screens are sealed with, read from the keychain (or made) on first use.
    pub fn key(&self) -> Option<Arc<ScreenKey>> {
        let mut key = lock(&self.key);
        if key.is_none() {
            match self.keys.load_or_create() {
                Ok(bytes) => *key = Some(Arc::new(ScreenKey::from_bytes(&bytes))),
                Err(e) => eprintln!("[echoledger] screen key unavailable: {e}"),
            }
        }
        key.clone()
    }

    /// A fresh server config: its plan says whether screen moments are in it. A server without plans says nothing, so
    /// a `403 feature_not_in_plan` it answered stays in force.
    pub fn note_config(&self, config: &ServerConfig) {
        if let Some(in_plan) = config.screens_in_plan() {
            self.plan_off.store(!in_plan, Ordering::SeqCst);
        }
    }

    pub fn in_plan(&self) -> bool {
        !self.plan_off.load(Ordering::SeqCst)
    }

    pub fn on_server(&self) -> bool {
        !self.server_missing.load(Ordering::SeqCst)
    }

    /// The pill's chip while a recording captures screens (or could, once a pause ends).
    pub fn pill(&self) -> Option<ScreensPill> {
        if lock(&self.running).is_none() {
            return None;
        }
        lock(&self.live.pill).clone()
    }

    /// One tap in the pill: pause or resume screens for this recording.
    pub fn set_paused(&self, paused: bool) {
        self.live.user_paused.store(paused, Ordering::SeqCst);
        let mut pill = lock(&self.live.pill);
        if let Some(pill) = pill.as_mut() {
            pill.on = !paused && pill.on;
            if paused {
                pill.reason = Some(HoldReason::You);
            }
        }
    }

    /// A caption line of the running recording: pointing words take a screen at the next sample.
    pub fn heard(&self, text: &str) {
        if lock(&self.running).is_some() && words::points_at_screen(text) {
            self.live.words.store(true, Ordering::SeqCst);
        }
    }

    pub fn status(&self) -> ScreensStatus {
        let platform = if cfg!(windows) {
            "windows"
        } else if cfg!(target_os = "macos") {
            "macos"
        } else {
            "other"
        };
        ScreensStatus {
            supported: self.system.supported(),
            platform: platform.into(),
            borderless: self.system.borderless(),
            permission: self.system.permission(),
            in_plan: self.in_plan(),
            on_server: self.on_server(),
        }
    }
}

/// The recording `client_ref` keeps screens of its project? Reads `GET /api/clients` (missing flag = on).
async fn project_keeps_screens(app: &AppHandle, client_id: &str) -> bool {
    let Ok(api) = app.state::<crate::AppState>().api() else { return true };
    match tokio::time::timeout(PROJECT_CHECK_FOR, api.clients()).await {
        Ok(Ok(list)) => list["data"]
            .as_array()
            .and_then(|l| l.iter().find(|c| c["id"].as_str() == Some(client_id)))
            .and_then(|c| c.get("screens").and_then(serde_json::Value::as_bool))
            .unwrap_or(true),
        _ => true,
    }
}

/// Does the server have screen moments, as far as is known? It said no (`404 not_found`), or the library it sent
/// has items and none of them carries `screen_count` (a server older than screen moments).
fn server_has_screens(app: &AppHandle) -> bool {
    let state = app.state::<crate::AppState>();
    if !state.screens.on_server() {
        return false;
    }
    let library = state.library.blocking_lock();
    library.items.is_empty() || library.items.iter().any(|i| i.rest.contains_key("screen_count"))
}

/// A call recording started: its capture thread starts too (it keeps nothing until the rules allow it). Voice notes,
/// dictation and tests never capture.
pub fn on_recording_started(app: &AppHandle, info: &RecordingInfo) {
    if info.mode != RecordingMode::Call || app.state::<crate::AppState>().capture_test.load(Ordering::SeqCst) {
        return;
    }
    let state = app.state::<crate::AppState>();
    let screens = &state.screens;
    let mut running = lock(&screens.running);
    if running.is_some() {
        return;
    }
    let live = screens.live.clone();
    live.user_paused.store(false, Ordering::SeqCst);
    live.words.store(false, Ordering::SeqCst);
    live.count.store(0, Ordering::SeqCst);
    live.project.store(if info.client_id.is_some() { 0 } else { 1 }, Ordering::SeqCst);
    *lock(&live.pill) = None;
    if let Some(client) = info.client_id.clone() {
        let (app, live) = (app.clone(), live.clone());
        tauri::async_runtime::spawn(async move {
            let keeps = project_keeps_screens(&app, &client).await;
            live.project.store(if keeps { 1 } else { 2 }, Ordering::SeqCst);
        });
    }
    let wake = Arc::new(Wake::default());
    let recording = info.client_ref.clone();
    let thread = {
        let (app, recording, wake) = (app.clone(), recording.clone(), wake.clone());
        std::thread::Builder::new().name("el-screens".into()).spawn(move || capture_loop(&app, &recording, &live, &wake))
    };
    match thread {
        Ok(thread) => *running = Some(Running { recording, wake, thread }),
        Err(e) => eprintln!("[echoledger] screen capture thread: {e}"),
    }
}

/// The recording ended: the capture stops (a screen being written is finished first). Returns the recording whose
/// capture ran.
pub async fn on_recording_ended(app: &AppHandle) -> Option<String> {
    let running = lock(&app.state::<crate::AppState>().screens.running).take()?;
    running.wake.stop();
    let recording = running.recording.clone();
    let _ = tauri::async_runtime::spawn_blocking(move || running.thread.join()).await;
    Some(recording)
}

/// Deletes every stored screen of `recording` (the recording was discarded, undone, cut or skipped for good).
pub fn forget(app: &AppHandle, recording: &str) {
    ScreenStore::in_dir(&app.state::<crate::AppState>().data_dir).forget(recording);
}

/// The kept recording reached the server as `session_id`: its screens may go.
pub fn link(app: &AppHandle, recording: &str, session_id: &str) {
    if let Err(e) = ScreenStore::in_dir(&app.state::<crate::AppState>().data_dir).link(recording, session_id) {
        eprintln!("[echoledger] linking screens of {recording}: {e}");
    }
}

/// Deletes what can never be sent; `waiting`: the recordings still deciding, queued or skipped.
pub fn sweep(app: &AppHandle, mut waiting: HashSet<String>) {
    let state = app.state::<crate::AppState>();
    if let Some(running) = lock(&state.screens.running).as_ref() {
        waiting.insert(running.recording.clone());
    }
    ScreenStore::in_dir(&state.data_dir).sweep(&waiting, Utc::now());
}

/// Whether screens are due to be sent now.
pub fn due(app: &AppHandle) -> bool {
    !ScreenStore::in_dir(&app.state::<crate::AppState>().data_dir).due(Utc::now()).is_empty()
}

/// Sends the screens that are due (the upload worker, after the recordings). A server without screen moments or a
/// plan without them ends the capture and deletes every stored screen: they could never be sent. Returns how many
/// were sent.
pub async fn send(app: &AppHandle, api: &ApiClient) -> usize {
    let state = app.state::<crate::AppState>();
    let store = ScreenStore::in_dir(&state.data_dir);
    if store.due(Utc::now()).is_empty() {
        return 0;
    }
    let Some(key) = state.screens.key() else { return 0 };
    let drain = upload::drain(api, &store, &key, Utc::now()).await;
    match drain.stopped {
        Some(upload::Next::ServerMissing) => {
            state.screens.server_missing.store(true, Ordering::SeqCst);
            store.recordings().iter().for_each(|r| store.forget(r));
        }
        Some(upload::Next::NotInPlan) => {
            state.screens.plan_off.store(true, Ordering::SeqCst);
            store.recordings().iter().for_each(|r| store.forget(r));
        }
        _ => {}
    }
    drain.sent
}

/// The capture thread of one recording: once a second the sampler, and a kept frame encoded and sealed on disk.
fn capture_loop(app: &AppHandle, recording: &str, live: &Live, wake: &Wake) {
    let state = app.state::<crate::AppState>();
    let system = state.screens.system();
    system.thread_init();
    let store = ScreenStore::in_dir(&state.data_dir);
    let key = state.screens.key();
    let server = server_has_screens(app);
    let clock = Instant::now();
    let mut sampler = Sampler::new(system);
    let show = |pill: Option<ScreensPill>| *lock(&live.pill) = pill;
    while !wake.wait(SAMPLE_EVERY) {
        let settings = state.settings();
        let facts = Facts {
            enabled: settings.screen_moments,
            call: true,
            plan: state.screens.in_plan(),
            server: server && state.screens.on_server(),
            project: live.project(),
            supported: true,
            keychain: key.is_some(),
            permission: true,
            user_paused: live.user_paused.load(Ordering::SeqCst),
            recording_paused: state.capture.is_paused(),
            capped: false,
        };
        let meeting = state.detect.meeting();
        let wanted = platform::Wanted { mode: &settings.screen_target, meeting: meeting.as_ref(), excluded: &settings.screen_excluded_apps, site_rules: &settings.site_rules };
        let sample = sampler.tick(facts, &wanted, clock.elapsed().as_millis() as u64, &live.words);
        let count = live.count.load(Ordering::SeqCst);
        let (frame, trigger) = match sample {
            Sample::Off(_) => {
                show(None);
                continue;
            }
            Sample::Held(reason) => {
                show(Some(ScreensPill { on: false, reason: Some(reason), count }));
                continue;
            }
            Sample::Watching => {
                show(Some(ScreensPill { on: true, reason: None, count }));
                continue;
            }
            Sample::Keep { frame, trigger } => (frame, trigger),
        };
        // The moment on the recording's clock, read before encoding takes its time.
        let at_ms = state.capture.elapsed_ms();
        if let Some(key) = key.as_ref()
            && !wake.stopped()
        {
            match encode::encode(&frame.bgra, frame.width, frame.height, frame.stride) {
                Ok(encoded) if encoded.webp.len() <= encode::MAX_BYTES => {
                    match store.add(recording, key, &encoded.webp, at_ms, encoded.width, encoded.height, trigger.as_str(), Utc::now()) {
                        Ok(_) => {
                            live.count.fetch_add(1, Ordering::SeqCst);
                        }
                        Err(e) => eprintln!("[echoledger] storing a screen: {e}"),
                    }
                }
                Ok(_) => {}
                Err(e) => eprintln!("[echoledger] encoding a screen: {e}"),
            }
        }
        show(Some(ScreensPill { on: true, reason: None, count: live.count.load(Ordering::SeqCst) }));
    }
    drop(sampler);
    show(None);
}
