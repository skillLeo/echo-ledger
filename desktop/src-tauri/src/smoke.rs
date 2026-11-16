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

/// Stops the test: the temporary folder goes and the process ends with `code`.
fn finish(dir: &Path, code: i32) -> ! {
    let _ = std::fs::remove_dir_all(dir);
    std::process::exit(code);
}

/// Opens every window and waits for their pages (from the setup, on the async runtime as every window build is).
pub fn drive(app: tauri::AppHandle) {
    let Some(dir) = DIR.get().cloned() else { return };

    // The hang guard runs beside the event loop, so a frozen main thread is caught as well.
    let watched = dir.clone();
    std::thread::spawn(move || {
        std::thread::sleep(TIMEOUT);
        say(&format!("failed: not every window was ready after {} s", TIMEOUT.as_secs()));
        finish(&watched, EXIT_TIMED_OUT);
    });

    tauri::async_runtime::spawn(async move {
        let started = Instant::now();
        lifecycle::show_main(&app);
        for label in overlay::WINDOWS {
            lifecycle::show(&app, label, USE);
        }
        let mut labels = vec![MAIN];
        labels.extend(overlay::WINDOWS);
        let mut last_waiting = Vec::new();
        loop {
            match verdict(&lifecycle::phases(&app, &labels)) {
                Verdict::AllReady => break,
                Verdict::Failed(label) => {
                    say(&format!("failed: the {label} window could not be opened"));
                    finish(&dir, EXIT_WINDOW_FAILED);
                }
                Verdict::Waiting(waiting) => {
                    if waiting != last_waiting {
                        say(&format!("waiting for: {}", waiting.join(", ")));
                        last_waiting = waiting;
                    }
                }
            }
            tokio::time::sleep(POLL).await;
        }
        say(&format!("ok: {} windows ready in {} ms", labels.len(), started.elapsed().as_millis()));
        let _ = std::fs::remove_dir_all(&dir);
        app.exit(0);
    });
}

/// `--smoke-test --share-check`: the real Share Check in the smoke test's isolation (its own folder, no account, a
/// running EchoLedger left alone). Prints each method's counts; exits 0 on Pass, [`EXIT_CHECK_FAILED`] when the test
/// window showed in a capture, [`EXIT_CHECK_SKIPPED`] when nothing could be captured (no capture session on a CI
/// runner, no Screen Recording permission), with the reason printed: never a silent pass.
pub const SHARE_CHECK_ARG: &str = "--share-check";
pub const EXIT_CHECK_FAILED: i32 = 4;
pub const EXIT_CHECK_SKIPPED: i32 = 5;

pub fn share_check_requested(args: impl IntoIterator<Item = String>) -> bool {
    args.into_iter().any(|a| a == SHARE_CHECK_ARG)
}

/// The exit code of a Share Check run (pure).
pub fn share_check_exit(result: crate::privacy::pixels::Outcome) -> i32 {
    match result {
        crate::privacy::pixels::Outcome::Pass => 0,
        crate::privacy::pixels::Outcome::Fail => EXIT_CHECK_FAILED,
        crate::privacy::pixels::Outcome::Error => EXIT_CHECK_SKIPPED,
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
                let code = share_check_exit(view.result);
                match code {
                    0 => say("share check: pass"),
                    EXIT_CHECK_FAILED => say("share check: FAIL, the protected window was in the capture"),
                    _ => say(&format!("share check: SKIPPED, nothing could be measured ({})", view.reason.unwrap_or_default())),
                }
                let _ = std::fs::remove_dir_all(&dir);
                app.exit(code);
            }
            Err(e) => {
                say(&format!("share check: SKIPPED, the check could not run: {e}"));
                let _ = std::fs::remove_dir_all(&dir);
                app.exit(EXIT_CHECK_SKIPPED);
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

    #[test]
    fn the_share_check_mode_never_passes_without_a_measurement() {
        use crate::privacy::pixels::Outcome;
        assert!(share_check_requested(args(&["EchoLedger", "--smoke-test", "--share-check"])));
        assert!(!share_check_requested(args(&["EchoLedger", "--smoke-test"])));
        assert_eq!(share_check_exit(Outcome::Pass), 0);
        assert_eq!(share_check_exit(Outcome::Fail), EXIT_CHECK_FAILED);
        assert_eq!(share_check_exit(Outcome::Error), EXIT_CHECK_SKIPPED, "nothing measured is never a pass");
        assert_ne!(EXIT_CHECK_SKIPPED, 0);
    }

    #[test]
    fn the_test_never_reads_the_keychain() {
        assert!(NoKeys.load_or_create().is_err());
    }
}
