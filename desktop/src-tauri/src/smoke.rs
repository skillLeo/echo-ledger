//! The smoke test (v1.5, Phase 1B): `EchoLedger --smoke-test` (or `ECHOLEDGER_SMOKE_TEST=1`) starts the real app against
//! a temporary folder with no account, opens its windows, waits until every page says it is ready, quits and exits 0.
//! A window that does not come up, or a hang past [`TIMEOUT`], exits non-zero. The CI workflows run it on the built app
//! (`desktop/scripts/smoke-mac.sh`, `smoke-windows.ps1`).
//!
//! It does nothing else, and it is harmless if a person runs it: no network, no capture, no permission asked, no
//! shortcut taken, no keychain read, nothing written to the real data folder, and a running EchoLedger is left alone
//! (the single-instance hand-over is not installed, so the test is its own process). `run()` in lib.rs returns from
//! its setup before anything that records, uploads or polls starts; `tests/app_config.rs` holds it to that.

use std::path::{Path, PathBuf};
use std::sync::OnceLock;
use std::time::{Duration, Instant};

use crate::lifecycle::{self, MAIN, Phase};
use crate::overlay;
use crate::screens::store::KeySource;

pub const ARG: &str = "--smoke-test";
pub const ENV: &str = "ECHOLEDGER_SMOKE_TEST";
/// The whole test, from the first window asked for to the last one ready (a first WebView2 start on a runner is slow).
pub const TIMEOUT: Duration = Duration::from_secs(120);
/// How often the windows are looked at.
const POLL: Duration = Duration::from_millis(200);
/// What `lifecycle` calls the test's use of a window.
const USE: &str = "smoke";

/// Exit codes other than 0: something failed to open, or the test ran out of time.
pub const EXIT_WINDOW_FAILED: i32 = 2;
pub const EXIT_TIMED_OUT: i32 = 3;
/// A Mac only: the app is not set up as a normal app must be (`mac_app_problem`). Not 4, 5 or 6, the Share Check's.
pub const EXIT_MAC_APP: i32 = 7;
/// The async runtime (where windows are built and the pages' commands run) made no progress for [`STALL`].
pub const EXIT_STALLED: i32 = 8;
/// How long the async runtime may make no progress before the test says it stalled.
pub const STALL: Duration = Duration::from_secs(20);
/// How often a heartbeat on the async runtime counts, and how often a waiting test says so again.
const HEARTBEAT: Duration = Duration::from_millis(250);
const SAY_AGAIN: Duration = Duration::from_secs(10);
/// The runtime is alive when its heartbeat moved within this long.
pub const ALIVE: Duration = Duration::from_millis(4 * 250);
/// Once every window is ready, the runtime must stay alive this long before the test passes.
pub const SETTLE: Duration = Duration::from_secs(5);

/// What is wrong with the Mac app's set-up, if anything (pure): the Dock's Quit must keep a recording first, and every
/// floating window must be a non-activating panel (it floats over a full-screen meeting without taking the focus) that,
/// under the default setting, is still hidden from screen capture after its class changed.
pub fn mac_app_problem(answers_quits: bool, windows: &[crate::mac_check::WindowFacts]) -> Option<String> {
    if !answers_quits {
        return Some("the application delegate does not answer applicationShouldTerminate:".into());
    }
    if let Some(w) = windows.iter().find(|w| !w.panel) {
        return Some(format!("the {} window is not a non-activating panel ({})", w.label, w.line()));
    }
    windows.iter().find(|w| !w.hidden_from_capture()).map(|w| format!("the {} window is not hidden from screen capture ({})", w.label, w.line()))
}

/// The test's own folder while it runs; everything that would touch the real one asks here first.
static DIR: OnceLock<PathBuf> = OnceLock::new();

/// Whether this start is a smoke test: the argument, or the variable set to anything but empty or 0.
pub fn requested(args: impl IntoIterator<Item = String>, env: Option<String>) -> bool {
    args.into_iter().any(|a| a == ARG) || env.is_some_and(|v| !v.trim().is_empty() && v.trim() != "0")
}

/// A fresh folder of this process under the system's temporary folder.
pub fn temp_data_dir(temp: &Path, pid: u32) -> PathBuf {
    temp.join(format!("echoledger-smoke-{pid}"))
}

/// Starts the mode and returns the folder the app runs in (made empty).
pub fn begin() -> PathBuf {
    let dir = temp_data_dir(&std::env::temp_dir(), std::process::id());
    let _ = std::fs::remove_dir_all(&dir);
    DIR.set(dir.clone()).ok();
    // What ran, and on which thread, for a stall report (`stall`).
    crate::stall::start();
    say("started, with a temporary folder and no account");
    dir
}

pub fn active() -> bool {
    DIR.get().is_some()
}

/// A window of the smoke test keeps nothing: a private web view whose folder (WebView2's) is the test's own.
pub fn isolate<'a, R: tauri::Runtime, M: tauri::Manager<R>>(builder: tauri::WebviewWindowBuilder<'a, R, M>) -> tauri::WebviewWindowBuilder<'a, R, M> {
    match DIR.get() {
        Some(dir) => builder.incognito(true).data_directory(dir.join("webview")),
        None => builder,
    }
}

fn say(line: &str) {
    eprintln!("[echoledger] smoke test: {line}");
}

/// Where the windows stand.
#[derive(Clone, Debug, PartialEq, Eq)]
pub enum Verdict {
    /// These are still being built.
    Waiting(Vec<String>),
    AllReady,
    /// This one could not be built: it went back to absent after it was asked for.
    Failed(String),
}

pub fn verdict(phases: &[(&str, Phase)]) -> Verdict {
    if let Some((label, _)) = phases.iter().find(|(_, phase)| *phase == Phase::Absent) {
        return Verdict::Failed((*label).to_string());
    }
    let waiting: Vec<String> = phases.iter().filter(|(_, phase)| *phase != Phase::Ready).map(|(label, _)| (*label).to_string()).collect();
    if waiting.is_empty() { Verdict::AllReady } else { Verdict::Waiting(waiting) }
}

/// What the watcher does after one look (pure).
#[derive(Clone, Debug, PartialEq, Eq)]
pub enum Step {
    /// Every window is ready.
    Done,
    /// This window could not be built.
    Failed(String),
    /// The async runtime made no progress for [`STALL`]: these were still waited for.
    Stalled(Vec<String>),
    /// Still waiting for these; `say` when the list changed or [`SAY_AGAIN`] went by since it was last said.
    Waiting { waiting: Vec<String>, say: bool },
}

/// One look of the watcher (pure). A window that could not be built fails the test. Every window ready is done only
/// while the async runtime is alive (`since_beat`, since its heartbeat last moved, within [`ALIVE`]): ready windows on a
/// stalled runtime are a freeze a person would see, and fail as a stall. A runtime that made no progress for [`STALL`]
/// fails the test whatever the windows say; between, the watcher waits.
pub fn step(phases: &[(&str, Phase)], since_beat: Duration, last_said: &[String], since_said: Duration) -> Step {
    match verdict(phases) {
        Verdict::Failed(label) => Step::Failed(label),
        Verdict::AllReady if since_beat <= ALIVE => Step::Done,
        Verdict::AllReady if since_beat >= STALL => Step::Stalled(Vec::new()),
        Verdict::AllReady => Step::Waiting { waiting: Vec::new(), say: false },
        Verdict::Waiting(waiting) if since_beat >= STALL => Step::Stalled(waiting),
        Verdict::Waiting(waiting) => {
            let say = waiting != last_said || since_said >= SAY_AGAIN;
            Step::Waiting { waiting, say }
        }
    }
}

/// A pause of the async runtime longer than this is warned about on a passing run, so a near-stall shows in CI history.
pub const PAUSE_WARN: Duration = Duration::from_secs(3);

/// The ok line's words for the runtime's longest pause, and the warning above [`PAUSE_WARN`] (pure).
pub fn pause_words(longest: Duration) -> (String, Option<String>) {
    let seconds = format!("{:.1} s", longest.as_secs_f64());
    let warning = (longest > PAUSE_WARN).then(|| format!("warning: the async runtime paused for {seconds} (a run fails at {} s)", STALL.as_secs()));
    (format!("longest pause {seconds}"), warning)
}

/// The settle period after every window is ready (pure): passed once the runtime stayed alive for [`SETTLE`] in a row
/// (`alive_for`); a [`STALL`]-length gap fails it; else it goes on.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Settle {
    Passed,
    Stalled,
    Going,
}

pub fn settle(since_beat: Duration, alive_for: Duration) -> Settle {
    if since_beat >= STALL {
        Settle::Stalled
    } else if alive_for >= SETTLE {
        Settle::Passed
    } else {
        Settle::Going
    }
}

/// A stall: says it with the stall report (the runtime, what the main thread is in, the last commands, every thread's
/// stack in a file), and ends the test with [`EXIT_STALLED`].
fn stalled(dir: &Path, what: &str) -> ! {
    say(&format!("failed: the async runtime made no progress for {} s ({what})", STALL.as_secs()));
    say(&crate::stall::report());
    finish(dir, EXIT_STALLED);
}

/// Stops the test: the temporary folder goes and the process ends with `code`.
fn finish(dir: &Path, code: i32) -> ! {
    let _ = std::fs::remove_dir_all(dir);
    std::process::exit(code);
}

/// Opens every window and waits for their pages, then for a runtime that stays alive. The watcher is a thread of its
/// own, apart from the async runtime where the windows are built and the pages' commands run, and a heartbeat on that
/// runtime says whether it runs. A Server 2025 run (aab16d95) saw all six windows ready in 9 s while the runtime made no
/// progress for 111 s: the old watcher, a task on that runtime, never looked again and the run ended at the 120 s guard.
/// Here that run fails as a stall (exit 8) after 20 s, with a report of where it stalled; windows ready on a stalled
/// runtime never pass.
pub fn drive(app: tauri::AppHandle) {
    let Some(dir) = DIR.get().cloned() else { return };

    // The hang guard runs beside the event loop, so a frozen main thread is caught as well.
    let watched = dir.clone();
    std::thread::spawn(move || {
        std::thread::sleep(TIMEOUT);
        say(&format!("failed: not every window was ready after {} s", TIMEOUT.as_secs()));
        finish(&watched, EXIT_TIMED_OUT);
    });

    // The runtime's heartbeat: counts while the async runtime runs its tasks.
    let beats = std::sync::Arc::new(std::sync::atomic::AtomicU64::new(0));
    let beating = beats.clone();
    tauri::async_runtime::spawn(async move {
        loop {
            beating.fetch_add(1, std::sync::atomic::Ordering::SeqCst);
            tokio::time::sleep(HEARTBEAT).await;
        }
    });

    let watcher = std::thread::Builder::new().name("el-smoke-watch".into()).spawn(move || {
        let started = Instant::now();
        lifecycle::show_main(&app);
        for label in overlay::WINDOWS {
            lifecycle::show(&app, label, USE);
        }
        let mut labels = vec![MAIN];
        labels.extend(overlay::WINDOWS);
        let (mut last_said, mut said_at) = (Vec::new(), Instant::now());
        let (mut beat, mut beat_at) = (beats.load(std::sync::atomic::Ordering::SeqCst), Instant::now());
        // The runtime's longest pause seen, said on the ok line (and warned about above PAUSE_WARN).
        let mut longest = Duration::ZERO;
        loop {
            let now_beat = beats.load(std::sync::atomic::Ordering::SeqCst);
            if now_beat != beat {
                (beat, beat_at) = (now_beat, Instant::now());
            }
            longest = longest.max(beat_at.elapsed());
            match step(&lifecycle::phases(&app, &labels), beat_at.elapsed(), &last_said, said_at.elapsed()) {
                Step::Done => break,
                Step::Failed(label) => {
                    say(&format!("failed: the {label} window could not be opened"));
                    finish(&dir, EXIT_WINDOW_FAILED);
                }
                Step::Stalled(waiting) if waiting.is_empty() => stalled(&dir, "every window was ready"),
                Step::Stalled(waiting) => stalled(&dir, &format!("still waiting for: {}", waiting.join(", "))),
                Step::Waiting { waiting, say: true } => {
                    say(&format!("waiting for: {} ({} ms)", waiting.join(", "), started.elapsed().as_millis()));
                    (last_said, said_at) = (waiting, Instant::now());
                }
                Step::Waiting { .. } => {}
            }
            std::thread::sleep(POLL);
        }
        // Then the runtime must stay alive for a while: a freeze right after the windows came up fails too.
        let mut alive_since = Instant::now();
        loop {
            let now_beat = beats.load(std::sync::atomic::Ordering::SeqCst);
            if now_beat != beat {
                (beat, beat_at) = (now_beat, Instant::now());
            }
            longest = longest.max(beat_at.elapsed());
            if beat_at.elapsed() > ALIVE {
                alive_since = Instant::now();
            }
            match settle(beat_at.elapsed(), alive_since.elapsed()) {
                Settle::Passed => break,
                Settle::Stalled => stalled(&dir, "after every window was ready"),
                Settle::Going => std::thread::sleep(POLL),
            }
        }
        let (pause, warning) = pause_words(longest);
        if let Some(warning) = warning {
            say(&warning);
        }
        say(&format!("ok: {} windows ready in {} ms, the async runtime alive {} s after; {pause}", labels.len(), started.elapsed().as_millis(), SETTLE.as_secs()));
        // A Mac: the app runs as a normal one, its quit answer is in and the floating windows are panels.
        #[cfg(target_os = "macos")]
        {
            use tauri::Manager;
            let windows: Vec<crate::mac_check::WindowFacts> = overlay::WINDOWS
                .iter()
                .filter_map(|label| app.get_webview_window(label).and_then(|window| overlay::macos::window_facts(&window, label)))
                .collect();
            if windows.len() < overlay::WINDOWS.len() {
                say("failed: a floating window could not be read");
                finish(&dir, EXIT_MAC_APP);
            }
            if let Some(problem) = mac_app_problem(crate::app_life::mac::answers_quits(), &windows) {
                say(&format!("failed: {problem}"));
                finish(&dir, EXIT_MAC_APP);
            }
            for window in &windows {
                say(&format!("  {}: {}", window.label, window.line()));
            }
            let setter = windows.iter().filter(|w| w.activation_setter).count();
            say(&format!("ok: quits from the Dock keep a recording first; the floating windows are non-activating panels hidden from screen capture (activation setter applied to {setter} of {})", windows.len()));
        }
        let _ = std::fs::remove_dir_all(&dir);
        app.exit(0);
    });
    if let Err(e) = watcher {
        say(&format!("failed: the watcher could not start: {e}"));
    }
}

/// `--smoke-test --share-check`: the real Share Check in the smoke test's isolation (its own folder, no account, a
/// running EchoLedger left alone). Prints each method's counts; exits 0 on Pass, [`EXIT_CHECK_FAILED`] when the test
/// window showed in a capture, [`EXIT_CHECK_SKIPPED`] only when the machine has no capture session (no screen to
/// duplicate, Windows.Graphics.Capture unsupported or giving no frame, a blank capture, no Screen Recording permission),
/// and [`EXIT_CHECK_BROKEN`] for anything else that measured nothing (the windows did not open, draw or stay
/// uncovered, a real picture without the control): a regression fails CI, never a silent pass.
pub const SHARE_CHECK_ARG: &str = "--share-check";
pub const EXIT_CHECK_FAILED: i32 = 4;
pub const EXIT_CHECK_SKIPPED: i32 = 5;
pub const EXIT_CHECK_BROKEN: i32 = 6;

/// What the captures say when the machine has no screen session (`privacy::capture`, `screens::platform::windows`).
const NO_SESSION: [&str; 5] = [
    "No screen to duplicate",
    "Desktop Duplication could not start",
    "Desktop Duplication gave no",
    "Windows.Graphics.Capture is not supported",
    "The screen capture gave no frame",
];

/// Whether every capture failed for want of a capture session, which is not the check's fault (pure).
pub fn no_capture_session(methods: &[crate::privacy::check::MethodResult]) -> bool {
    let session_missing = |m: &crate::privacy::check::MethodResult| match m.reason.as_deref() {
        _ if m.method == "none" => false, // the check's own windows did not draw, or something covered them
        Some("permission_missing") => true,
        Some("capture_failed") => m.detail.as_deref().is_some_and(|d| NO_SESSION.iter().any(|s| d.contains(s))),
        Some("control_missing") => m.blank,
        _ => false,
    };
    !methods.is_empty() && methods.iter().all(|m| m.result == crate::privacy::pixels::Outcome::Error && session_missing(m))
}

pub fn share_check_requested(args: impl IntoIterator<Item = String>) -> bool {
    args.into_iter().any(|a| a == SHARE_CHECK_ARG)
}

/// The exit code of a Share Check run (pure).
pub fn share_check_exit(result: crate::privacy::pixels::Outcome, methods: &[crate::privacy::check::MethodResult]) -> i32 {
    match result {
        crate::privacy::pixels::Outcome::Pass => 0,
        crate::privacy::pixels::Outcome::Fail => EXIT_CHECK_FAILED,
        crate::privacy::pixels::Outcome::Error if no_capture_session(methods) => EXIT_CHECK_SKIPPED,
        crate::privacy::pixels::Outcome::Error => EXIT_CHECK_BROKEN,
    }
}

pub fn drive_share_check(app: tauri::AppHandle) {
    let Some(dir) = DIR.get().cloned() else { return };
    let watched = dir.clone();
    std::thread::spawn(move || {
        std::thread::sleep(TIMEOUT);
        say("share check failed: no result in time");
        finish(&watched, EXIT_TIMED_OUT);
    });
    tauri::async_runtime::spawn(async move {
        say("share check: a protected magenta window and an unprotected green control, the screen captured with nothing left out");
        match crate::privacy::check::run(&app, crate::privacy::check::Why::Asked).await {
            Ok(view) => {
                say(&format!("share check on {} ({})", view.system, crate::privacy::check::os_build()));
                for m in &view.methods {
                    let counts = m.counts.map(|c| format!("test {} px, control {} of {} px", c.test, c.control, c.control_area)).unwrap_or_else(|| "no picture".into());
                    let why = [m.reason.clone(), m.detail.clone()].into_iter().flatten().collect::<Vec<_>>().join(": ");
                    say(&format!("  {} {}: {counts}{}", m.method, crate::privacy::check::wire(m.result), if why.is_empty() { String::new() } else { format!(" ({why})") }));
                }
                let code = share_check_exit(view.result, &view.methods);
                match code {
                    0 => say("share check: pass"),
                    EXIT_CHECK_FAILED => say("share check: FAIL, the protected window was in the capture"),
                    EXIT_CHECK_SKIPPED => say(&format!("share check: SKIPPED, this machine has no capture session ({})", view.reason.unwrap_or_default())),
                    _ => say(&format!("share check: FAILED, nothing was measured on a working capture ({})", view.reason.unwrap_or_default())),
                }
                let _ = std::fs::remove_dir_all(&dir);
                app.exit(code);
            }
            Err(e) => {
                say(&format!("share check: FAILED, the check could not run: {e}"));
                let _ = std::fs::remove_dir_all(&dir);
                app.exit(EXIT_CHECK_BROKEN);
            }
        }
    });
}

/// The screens key of the test: never asks the keychain, and nothing is sealed with it.
pub struct NoKeys;

impl KeySource for NoKeys {
    fn load_or_create(&self) -> Result<[u8; 32], String> {
        Err("no keychain in the smoke test".into())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_mac_smoke_test_fails_without_the_quit_answer_the_panels_or_the_capture_flag() {
        let panel = |label: &'static str| crate::mac_check::WindowFacts { label, exists: true, visible: true, on_screen: true, level_ok: true, spaces_ok: true, class: "EchoLedgerFloatingPanel".into(), style: 0x80, panel: true, activation_setter: true, sharing: 0 };
        let good = [panel("overlay"), panel("pill")];
        assert_eq!(mac_app_problem(true, &good), None);
        assert!(mac_app_problem(false, &good).unwrap().contains("applicationShouldTerminate:"));
        let plain = crate::mac_check::WindowFacts { class: "TaoWindow".into(), style: 0, panel: false, ..panel("pill") };
        assert!(mac_app_problem(true, &[panel("overlay"), plain]).unwrap().starts_with("the pill window is not a non-activating panel (TaoWindow, style 0x0"));
        // The capture flag must survive the class change: a panel shown in screen capture fails.
        let shown = crate::mac_check::WindowFacts { sharing: 1, ..panel("overlay") };
        assert!(mac_app_problem(true, &[shown, panel("pill")]).unwrap().starts_with("the overlay window is not hidden from screen capture (EchoLedgerFloatingPanel, style 0x80, sharing 1"));
        // Without the activation setter it still passes (the output says how many got it).
        assert_eq!(mac_app_problem(true, &[crate::mac_check::WindowFacts { activation_setter: false, ..panel("pill") }]), None);
        assert_eq!(EXIT_MAC_APP, 7);
        assert!(![EXIT_WINDOW_FAILED, EXIT_TIMED_OUT, EXIT_CHECK_FAILED, EXIT_CHECK_SKIPPED, EXIT_CHECK_BROKEN].contains(&EXIT_MAC_APP), "its own code");
    }

    #[test]
    fn ready_windows_pass_only_on_a_runtime_that_stays_alive_and_a_stall_says_where() {
        let all_ready = [("main", Phase::Ready), ("pill", Phase::Ready)];
        let one_left = [("main", Phase::Ready), ("pill", Phase::Creating)];
        let s = Duration::from_secs;
        // Every window ready on a stalled runtime is a freeze: the Server 2025 run (ready at 9 s, then 111 s of nothing)
        // fails as a stall. With a fresh heartbeat it is done; a short gap waits.
        assert_eq!(step(&all_ready, s(111), &[], s(0)), Step::Stalled(Vec::new()));
        assert_eq!(step(&all_ready, Duration::from_millis(250), &[], s(0)), Step::Done);
        assert_eq!(step(&all_ready, ALIVE, &[], s(0)), Step::Done);
        assert_eq!(step(&all_ready, s(3), &[], s(0)), Step::Waiting { waiting: Vec::new(), say: false });
        // Then the settle period: the runtime alive 5 s in a row passes; a stall there fails.
        assert_eq!(settle(Duration::from_millis(250), SETTLE), Settle::Passed);
        assert_eq!(settle(Duration::from_millis(250), s(2)), Settle::Going);
        assert_eq!(settle(STALL, s(4)), Settle::Stalled, "a stall during the settle period");
        assert!(ALIVE == HEARTBEAT * 4 && SETTLE < TIMEOUT - STALL);
        // The longest pause is said on the ok line, and warned about above 3 s without failing.
        assert_eq!(pause_words(Duration::from_millis(300)), ("longest pause 0.3 s".to_string(), None));
        assert_eq!(pause_words(PAUSE_WARN).1, None);
        assert_eq!(pause_words(Duration::from_millis(14_200)), ("longest pause 14.2 s".to_string(), Some("warning: the async runtime paused for 14.2 s (a run fails at 20 s)".to_string())));
        let source = include_str!("smoke.rs");
        let drive = &source[source.find("pub fn drive(").unwrap()..];
        let drive = &drive[..drive.find("\n}\n").unwrap()];
        assert_eq!(drive.matches("longest = longest.max(beat_at.elapsed());").count(), 2, "measured while waiting and while settling");
        assert_eq!(step(&[("main", Phase::Absent), ("pill", Phase::Ready)], s(0), &[], s(0)), Step::Failed("main".into()));
        // A stalled runtime while a window is still being built is its own failure, at once.
        assert_eq!(step(&one_left, STALL, &[], s(0)), Step::Stalled(vec!["pill".into()]));
        assert_eq!(step(&one_left, STALL - Duration::from_millis(1), &["pill".into()], s(1)), Step::Waiting { waiting: vec!["pill".into()], say: false });
        // Said when the list changed, and again every 10 s, so a log shows when a wait began.
        assert_eq!(step(&one_left, s(0), &["main".into(), "pill".into()], s(1)), Step::Waiting { waiting: vec!["pill".into()], say: true });
        assert_eq!(step(&one_left, s(0), &["pill".into()], SAY_AGAIN), Step::Waiting { waiting: vec!["pill".into()], say: true });
        assert!(STALL < TIMEOUT, "a stall is said before the hang guard");
        assert!(![EXIT_WINDOW_FAILED, EXIT_TIMED_OUT, EXIT_CHECK_FAILED, EXIT_CHECK_SKIPPED, EXIT_CHECK_BROKEN, EXIT_MAC_APP].contains(&EXIT_STALLED), "its own code");
        // The watcher is a thread of its own: it never sleeps on the async runtime.
        let source = include_str!("smoke.rs");
        let drive = &source[source.find("pub fn drive(").unwrap()..];
        let drive = &drive[..drive.find("\n}\n").unwrap()];
        assert!(drive.contains("std::thread::Builder::new().name(\"el-smoke-watch\""), "{drive}");
        assert!(drive.contains("std::thread::sleep(POLL)") && !drive.contains("tokio::time::sleep(POLL)"));
        // A stall says where: the stall report goes with exit 8.
        let stalled = &source[source.find("fn stalled(").unwrap()..];
        assert!(stalled[..stalled.find("\n}").unwrap()].contains("crate::stall::report()"));
    }

    fn args(list: &[&str]) -> Vec<String> {
        list.iter().map(|s| s.to_string()).collect()
    }

    #[test]
    fn the_mode_is_asked_for_by_the_argument_or_the_variable() {
        assert!(requested(args(&["EchoLedger", "--smoke-test"]), None));
        assert!(requested(args(&["EchoLedger"]), Some("1".into())));
        assert!(!requested(args(&["EchoLedger"]), None));
        assert!(!requested(args(&["EchoLedger", "--autostart"]), None));
        assert!(!requested(args(&["EchoLedger"]), Some("0".into())), "0 and empty mean off");
        assert!(!requested(args(&["EchoLedger"]), Some(" ".into())));
    }

    #[test]
    fn the_test_runs_in_a_folder_of_its_own() {
        let dir = temp_data_dir(Path::new("/tmp"), 42);
        assert_eq!(dir, Path::new("/tmp/echoledger-smoke-42"));
        assert!(!dir.to_string_lossy().contains("com.echoledger.desktop"), "never the real data folder");
    }

    #[test]
    fn it_waits_for_every_window_and_a_failed_build_ends_it() {
        let ready = [("main", Phase::Ready), ("pill", Phase::Ready)];
        assert_eq!(verdict(&ready), Verdict::AllReady);
        let building = [("main", Phase::Ready), ("pill", Phase::Creating), ("review", Phase::Creating)];
        assert_eq!(verdict(&building), Verdict::Waiting(vec!["pill".to_string(), "review".to_string()]));
        // `spawn_build` puts a window that failed back to absent.
        let failed = [("main", Phase::Ready), ("pill", Phase::Absent)];
        assert_eq!(verdict(&failed), Verdict::Failed("pill".to_string()));
    }

    #[test]
    fn the_timeout_leaves_room_for_a_slow_first_web_view_and_still_ends() {
        assert!(TIMEOUT >= Duration::from_secs(60) && TIMEOUT <= Duration::from_secs(180));
        assert_ne!(EXIT_WINDOW_FAILED, 0);
        assert_ne!(EXIT_TIMED_OUT, 0);
    }

    fn method(method: &str, reason: &str, detail: Option<&str>, blank: bool) -> crate::privacy::check::MethodResult {
        crate::privacy::check::MethodResult {
            method: method.into(),
            result: crate::privacy::pixels::Outcome::Error,
            reason: Some(reason.into()),
            counts: None,
            detail: detail.map(str::to_string),
            blank,
        }
    }

    #[test]
    fn the_share_check_mode_never_passes_without_a_measurement() {
        use crate::privacy::pixels::Outcome;
        assert!(share_check_requested(args(&["EchoLedger", "--smoke-test", "--share-check"])));
        assert!(!share_check_requested(args(&["EchoLedger", "--smoke-test"])));
        assert_eq!(share_check_exit(Outcome::Pass, &[]), 0);
        assert_eq!(share_check_exit(Outcome::Fail, &[]), EXIT_CHECK_FAILED);
        assert_ne!(EXIT_CHECK_SKIPPED, 0);
        assert_ne!(EXIT_CHECK_BROKEN, EXIT_CHECK_SKIPPED);
    }

    #[test]
    fn only_a_machine_without_a_capture_session_is_skipped() {
        use crate::privacy::pixels::Outcome::Error;
        let no_session = [
            method("wgc", "capture_failed", Some("Windows.Graphics.Capture is not supported here."), false),
            method("dxgi", "capture_failed", Some("No screen to duplicate (no desktop session)."), false),
        ];
        assert_eq!(share_check_exit(Error, &no_session), EXIT_CHECK_SKIPPED);
        let blank = [method("wgc", "control_missing", None, true), method("dxgi", "control_missing", None, true)];
        assert_eq!(share_check_exit(Error, &blank), EXIT_CHECK_SKIPPED, "both captures came back black");
        assert_eq!(share_check_exit(Error, &[method("sck", "permission_missing", None, false)]), EXIT_CHECK_SKIPPED);
        // Everything else that measured nothing is the check's own failure.
        assert_eq!(share_check_exit(Error, &[method("none", "capture_failed", Some("the check's windows did not draw"), false)]), EXIT_CHECK_BROKEN);
        assert_eq!(share_check_exit(Error, &[method("none", "covered", Some("another window covered the test window"), false)]), EXIT_CHECK_BROKEN);
        let missing = [method("wgc", "control_missing", None, false), method("dxgi", "control_missing", None, false)];
        assert_eq!(share_check_exit(Error, &missing), EXIT_CHECK_BROKEN, "a real picture without the control");
        let mixed = [method("wgc", "control_missing", None, false), method("dxgi", "capture_failed", Some("No screen to duplicate (no desktop session)."), false)];
        assert_eq!(share_check_exit(Error, &mixed), EXIT_CHECK_BROKEN, "one capture worked");
        assert_eq!(share_check_exit(Error, &[]), EXIT_CHECK_BROKEN);
    }

    #[test]
    fn the_test_never_reads_the_keychain() {
        assert!(NoKeys.load_or_create().is_err());
    }
}
