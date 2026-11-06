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
/// The project's switch is read at the start of a recording, each try waiting this long for the server. Until it
/// answered nothing is kept (the switch fails closed), and it is read again before anything is sent.
const PROJECT_CHECK_FOR: Duration = Duration::from_secs(10);
/// How long the next try waits after the server could not answer (offline, away or slow).
const PROJECT_RETRY_EVERY: Duration = Duration::from_secs(30);

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
    /// The server has screen moments (false once its config showed it is older, or it answered that it has no such
    /// route).
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
    count: AtomicU32,
    pill: Mutex<Option<ScreensPill>>,
}

/// The recording's project switch, one per recording (a read still going for the last recording never reaches the
/// next one): 0 not known yet, 1 keeps screens, 2 screens off.
fn project_of(cell: &AtomicU8) -> Option<bool> {
    match cell.load(Ordering::SeqCst) {
        1 => Some(true),
        2 => Some(false),
        _ => None,
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
    /// The last server config came from a server older than screen moments: it has no `plan`, which every v1.5 config
    /// has. Read from each fresh config (at start and before each upload round), never from cached library items,
    /// which keep the shape they had before the server was updated.
    server_old: AtomicBool,
    /// Windows: EchoLedger's main window is hidden from screen captures while a whole screen is captured, so a main
    /// window built meanwhile starts hidden too.
    own_hidden: AtomicBool,
    running: Mutex<Option<Running>>,
    live: Arc<Live>,
}

fn lock<T>(m: &Mutex<T>) -> MutexGuard<'_, T> {
    m.lock().unwrap_or_else(|p| p.into_inner())
}

impl Screens {
    pub fn new(system: Box<dyn ScreenSystem>, keys: Box<dyn KeySource>) -> Self {
        Self {
            system,
            keys,
            key: Mutex::new(None),
            plan_off: AtomicBool::new(false),
            server_missing: AtomicBool::new(false),
            server_old: AtomicBool::new(false),
            own_hidden: AtomicBool::new(false),
            running: Mutex::new(None),
            live: Arc::default(),
        }
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

    /// A fresh server config: its plan says whether screen moments are in it. A config without a plan comes from a
    /// server older than screen moments (plans and screens are both v1.5); it says nothing about the plan, so a
    /// `403 feature_not_in_plan` it answered stays in force.
    pub fn note_config(&self, config: &ServerConfig) {
        self.server_old.store(config.plan.is_none(), Ordering::SeqCst);
        if let Some(in_plan) = config.screens_in_plan() {
            self.plan_off.store(!in_plan, Ordering::SeqCst);
        }
    }

    pub fn in_plan(&self) -> bool {
        !self.plan_off.load(Ordering::SeqCst)
    }

    /// The server answered `403 feature_not_in_plan`: nothing is captured until a config says the plan has screens.
    pub fn plan_lacks_screens(&self) {
        self.plan_off.store(true, Ordering::SeqCst);
    }

    /// A screen list as the pages show it: `None` hides screen moments on a server without them (`404 not_found`) or a
    /// plan without them (`403 feature_not_in_plan`, which also stops the capture until a config says otherwise).
    pub fn listed(&self, answer: crate::error::AppResult<serde_json::Value>) -> crate::error::AppResult<Option<serde_json::Value>> {
        match answer {
            Err(e) if e.code() == Some("feature_not_in_plan") => {
                self.plan_lacks_screens();
                Ok(None)
            }
            other => crate::commands::when_available(other),
        }
    }

    /// The server has screen moments, as far as is known: neither its config nor a `404 not_found` said otherwise.
    /// Before the first config (offline at start) it counts as yes; a 404 from the first upload then ends it.
    pub fn on_server(&self) -> bool {
        !self.server_missing.load(Ordering::SeqCst) && !self.server_old.load(Ordering::SeqCst)
    }

    /// EchoLedger's main window is to be hidden from screen captures now (Windows, while a whole screen is captured).
    pub fn own_hidden(&self) -> bool {
        self.own_hidden.load(Ordering::SeqCst)
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
            if paused {
                pill.on = false;
                pill.reason = Some(HoldReason::You);
            } else if pill.reason == Some(HoldReason::You) {
                // Until the next sample says on or why it waits, the chip reads "starting" instead of "paused by you".
                pill.reason = None;
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

/// The project's `screens` switch in a `GET /api/clients` answer (pure). A missing switch is on (a server older than
/// it); a project the list does not have keeps none (deleted, or not this workspace's); `None` when the answer is not
/// a list.
fn project_flag(list: &serde_json::Value, client_id: &str) -> Option<bool> {
    let list = list["data"].as_array()?;
    Some(list.iter().find(|c| c["id"].as_str() == Some(client_id)).is_some_and(|c| c.get("screens").and_then(serde_json::Value::as_bool).unwrap_or(true)))
}

/// Does the project `client_id` keep screens? `None` while it cannot be known: not paired, the server away or slower
/// than [`PROJECT_CHECK_FOR`].
async fn read_project(app: AppHandle, client_id: String) -> Option<bool> {
    let api = app.state::<crate::AppState>().api().ok()?;
    let list = tokio::time::timeout(PROJECT_CHECK_FOR, api.clients()).await.ok()?.ok()?;
    project_flag(&list, &client_id)
}

/// Reads the project's switch into `cell` until the server answered, a try every `retry`, while `running` says the
/// recording goes on. Until then the cell says "not known" and the capture keeps nothing.
async fn settle_project<F, Fut>(mut read: F, cell: &AtomicU8, running: impl Fn() -> bool, retry: Duration)
where
    F: FnMut() -> Fut,
    Fut: std::future::Future<Output = Option<bool>>,
{
    while running() {
        if let Some(keeps) = read().await {
            cell.store(if keeps { 1 } else { 2 }, Ordering::SeqCst);
            return;
        }
        tokio::time::sleep(retry).await;
    }
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
    *lock(&live.pill) = None;
    let wake = Arc::new(Wake::default());
    // The Inbox keeps screens; a project's switch is read first.
    let project = Arc::new(AtomicU8::new(if info.client_id.is_some() { 0 } else { 1 }));
    if let Some(client) = info.client_id.clone() {
        let (app, project, wake) = (app.clone(), project.clone(), wake.clone());
        tauri::async_runtime::spawn(async move {
            settle_project(|| read_project(app.clone(), client.clone()), &project, || !wake.stopped(), PROJECT_RETRY_EVERY).await;
        });
    }
    let recording = info.client_ref.clone();
    let thread = {
        let (app, recording, wake) = (app.clone(), recording.clone(), wake.clone());
        std::thread::Builder::new().name("el-screens".into()).spawn(move || capture_loop(&app, &recording, &live, &project, &wake))
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
    ScreenStore::in_dir(&state.data_dir).sweep(&waiting, state.owner().as_ref(), Utc::now());
}

/// Whether screens are due to be sent now.
pub fn due(app: &AppHandle) -> bool {
    let state = app.state::<crate::AppState>();
    !ScreenStore::in_dir(&state.data_dir).due(Utc::now(), state.owner().as_ref()).is_empty()
}

/// Sends the screens that are due (the upload worker, after the recordings). A server without screen moments or a
/// plan without them ends the capture and deletes the stored screens of the account signed in: they could never be
/// sent (another account's wait for it). Returns how many were sent.
pub async fn send(app: &AppHandle, api: &ApiClient) -> usize {
    let state = app.state::<crate::AppState>();
    let store = ScreenStore::in_dir(&state.data_dir);
    let current = state.owner();
    if store.due(Utc::now(), current.as_ref()).is_empty() {
        return 0;
    }
    let Some(key) = state.screens.key() else { return 0 };
    let drain = upload::drain(api, &store, &key, current.as_ref(), Utc::now()).await;
    match drain.stopped {
        Some(upload::Next::ServerMissing) => {
            state.screens.server_missing.store(true, Ordering::SeqCst);
            store.forget_for(current.as_ref());
        }
        Some(upload::Next::NotInPlan) => {
            state.screens.plan_lacks_screens();
            store.forget_for(current.as_ref());
        }
        _ => {}
    }
    drain.sent
}

/// How long the capture thread waits for the main thread to hide EchoLedger's main window (Windows).
#[cfg(windows)]
const SHIELD_WAIT: Duration = Duration::from_secs(2);

/// Windows: a monitor's capture cannot leave EchoLedger's own windows out of its picture as macOS does, so while a
/// whole screen is captured the main window, which can show other recordings, is hidden from screen captures (as
/// Settings' "hide from screen sharing" hides the overlays, which keep that switch), and given back once the capture
/// closed. Waits until Windows applied it; true when the main window is as asked (or there is none).
#[cfg(windows)]
fn hide_own_windows(app: &AppHandle, hidden: bool) -> bool {
    app.state::<crate::AppState>().screens.own_hidden.store(hidden, Ordering::SeqCst);
    let main = app.get_webview_window(crate::lifecycle::MAIN);
    let (tx, rx) = std::sync::mpsc::channel();
    let posted = app.run_on_main_thread(move || {
        // On the main thread the change takes effect before the call returns.
        let done = main.is_none_or(|w| {
            let _ = w.set_content_protected(hidden);
            w.hwnd().ok().and_then(|h| platform::windows::hidden_from_capture(h.0 as isize)) == Some(hidden)
        });
        let _ = tx.send(done);
    });
    posted.is_ok() && rx.recv_timeout(SHIELD_WAIT).unwrap_or(false)
}

/// The capture thread of one recording: once a second the sampler, and a kept frame encoded and sealed on disk.
fn capture_loop(app: &AppHandle, recording: &str, live: &Live, project: &AtomicU8, wake: &Wake) {
    let state = app.state::<crate::AppState>();
    let system = state.screens.system();
    system.thread_init();
    let store = ScreenStore::in_dir(&state.data_dir);
    let key = state.screens.key();
    let clock = Instant::now();
    #[cfg(not(windows))]
    let mut sampler = Sampler::new(system);
    #[cfg(windows)]
    let mut sampler = Sampler::new(system).with_shield(Box::new(|hidden| hide_own_windows(app, hidden)));
    let show = |pill: Option<ScreensPill>| *lock(&live.pill) = pill;
    while !wake.wait(SAMPLE_EVERY) {
        let settings = state.settings();
        let facts = Facts {
            enabled: settings.screen_moments,
            call: true,
            plan: state.screens.in_plan(),
            server: state.screens.on_server(),
            project: project_of(project),
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

#[cfg(test)]
mod tests {
    use serde_json::json;

    use super::*;
    use crate::error::AppError;
    use crate::net::models::ServerConfig;
    use platform::{Grab, Target, Wanted};
    use store::MemoryKeys;

    /// A computer that cannot capture: these tests never reach a capture.
    struct NoCapture;

    impl ScreenSystem for NoCapture {
        fn supported(&self) -> bool {
            false
        }
        fn borderless(&self) -> bool {
            false
        }
        fn permission(&self) -> bool {
            false
        }
        fn request_permission(&self) -> bool {
            false
        }
        fn find_target(&self, _: &Wanted<'_>) -> Result<Target, HoldReason> {
            Err(HoldReason::NoWindow)
        }
        fn open(&self, _: Target) -> Result<Box<dyn Grab>, String> {
            Err("no capture in tests".into())
        }
    }

    fn screens() -> Screens {
        Screens::new(Box::new(NoCapture), Box::new(MemoryKeys::default()))
    }

    fn server(status: u16, code: &str) -> AppError {
        AppError::Server { status, message: "x".into(), code: Some(code.into()), retry_after_secs: None }
    }

    #[test]
    fn a_plan_without_screen_moments_hides_the_lists_and_stops_the_capture() {
        let s = screens();
        assert!(s.in_plan());
        assert_eq!(s.listed(Err(server(403, "feature_not_in_plan"))).unwrap(), None);
        assert!(!s.in_plan(), "nothing is captured any more");
        // A config whose plan has them again brings them back.
        let mut config: ServerConfig = serde_json::from_str(include_str!("../../tests/fixtures/config.json")).unwrap();
        config.plan = serde_json::from_value(json!({"key": "team", "features": {"screen_moments": true}})).unwrap();
        s.note_config(&config);
        assert!(s.in_plan());
    }

    #[test]
    fn an_older_server_hides_the_lists_and_other_errors_stay_errors() {
        let s = screens();
        assert_eq!(s.listed(Err(server(404, "not_found"))).unwrap(), None);
        assert_eq!(s.listed(Ok(json!({"data": []}))).unwrap(), Some(json!({"data": []})));
        assert!(s.listed(Err(server(500, "server_error"))).is_err());
        assert!(s.in_plan(), "only the plan's answer stops the capture");
    }

    #[test]
    fn a_pause_in_the_pill_shows_at_once_and_a_resume_waits_for_the_next_sample() {
        let s = screens();
        *lock(&s.live.pill) = Some(ScreensPill { on: true, reason: None, count: 2 });
        s.set_paused(true);
        assert_eq!(*lock(&s.live.pill), Some(ScreensPill { on: false, reason: Some(HoldReason::You), count: 2 }));
        assert!(s.live.user_paused.load(Ordering::SeqCst));
        s.set_paused(false);
        assert_eq!(*lock(&s.live.pill), Some(ScreensPill { on: false, reason: None, count: 2 }), "not on before a sample says so");
        assert!(!s.live.user_paused.load(Ordering::SeqCst));
        // A resume never hides another reason to wait.
        *lock(&s.live.pill) = Some(ScreensPill { on: false, reason: Some(HoldReason::Minimised), count: 2 });
        s.set_paused(false);
        assert_eq!(lock(&s.live.pill).as_ref().and_then(|p| p.reason), Some(HoldReason::Minimised));
    }

    #[test]
    fn the_projects_switch_is_read_from_the_clients_list_and_fails_closed() {
        let list = json!({"data": [{"id": "p1", "name": "Acme", "screens": false}, {"id": "p2", "name": "Beta", "screens": true}, {"id": "p3", "name": "Old"}]});
        assert_eq!(project_flag(&list, "p1"), Some(false));
        assert_eq!(project_flag(&list, "p2"), Some(true));
        assert_eq!(project_flag(&list, "p3"), Some(true), "no switch (a server older than it): on");
        assert_eq!(project_flag(&list, "gone"), Some(false), "a project the list does not have keeps nothing");
        assert_eq!(project_flag(&json!({"message": "x"}), "p1"), None, "not a list: not known");
        assert_eq!(project_of(&AtomicU8::new(0)), None);
        assert_eq!(project_of(&AtomicU8::new(2)), Some(false));
    }

    #[tokio::test]
    async fn the_projects_switch_is_tried_again_until_the_server_answers() {
        // Offline twice, then the server says the project keeps no screens.
        let tries = std::cell::Cell::new(0);
        let cell = AtomicU8::new(0);
        let answers = [None, None, Some(false)];
        settle_project(
            || {
                let n = tries.get();
                tries.set(n + 1);
                async move { answers[n] }
            },
            &cell,
            || true,
            Duration::from_millis(1),
        )
        .await;
        assert_eq!((tries.get(), project_of(&cell)), (3, Some(false)));

        // The recording ended while the server was away: the switch stays unknown, so nothing was kept.
        let tries = std::cell::Cell::new(0);
        let cell = AtomicU8::new(0);
        settle_project(
            || {
                tries.set(tries.get() + 1);
                async { None }
            },
            &cell,
            || tries.get() < 4,
            Duration::from_millis(1),
        )
        .await;
        assert_eq!((tries.get(), project_of(&cell)), (4, None));
    }

    #[test]
    fn a_config_without_a_plan_comes_from_an_older_server() {
        let s = screens();
        assert!(s.on_server(), "no config yet (offline at start): not known, so not off");
        let mut old: ServerConfig = serde_json::from_str(include_str!("../../tests/fixtures/config.json")).unwrap();
        old.plan = None;
        s.note_config(&old);
        assert!(!s.on_server() && !s.status().on_server, "nothing is captured, and Settings says why");
        assert!(s.in_plan(), "an older server says nothing about the plan");
        // The server was updated: the next config has a plan, whatever the cached library items still look like.
        let mut updated = old.clone();
        updated.plan = serde_json::from_value(json!({"key": "personal", "features": {}})).unwrap();
        s.note_config(&updated);
        assert!(s.on_server());
        // A 404 from the screen routes outlasts any config of this run.
        s.server_missing.store(true, Ordering::SeqCst);
        s.note_config(&updated);
        assert!(!s.on_server());
    }

    #[test]
    fn the_settings_page_reads_the_system_and_the_server() {
        let s = screens();
        let status = s.status();
        assert!(!status.supported && !status.borderless && !status.permission, "asked of the system given, never of this computer's");
        assert!(status.in_plan && status.on_server);
        assert_eq!(s.pill(), None, "no recording, no chip");
    }
}
