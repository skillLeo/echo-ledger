//! When the smoke test sees the async runtime stall (exit 8, `smoke`): what was going on, so the next failure tells
//! where. A user would see such a stall as a freeze.
//!
//! - The runtime: its workers, the tasks alive and the tasks queued (tokio's stable metrics).
//! - The trail: the last IPC commands and window events, each with the thread that received it, when it began and when
//!   it returned there (an asynchronous command returns as soon as it is handed to a task; a synchronous one, which
//!   runs on the main thread, when it is done). One the main thread received and never returned from is what the main
//!   thread is doing.
//! - Every thread's stack: a minidump of the process on Windows (`echoledger-stall-<pid>.dmp`), `sample` on a Mac,
//!   written to `ECHOLEDGER_STALL_DIR` (CI keeps it), else the system's temporary folder.
//!
//! Recorded only during a smoke test: in the app nothing is kept.

use std::collections::VecDeque;
use std::path::PathBuf;
use std::sync::Mutex;
use std::sync::atomic::{AtomicBool, AtomicU64, Ordering};
use std::time::Instant;

/// How many trail entries are kept.
const KEEP: usize = 40;

static ON: AtomicBool = AtomicBool::new(false);
static NEXT: AtomicU64 = AtomicU64::new(1);
static TRAIL: Mutex<Option<(Instant, VecDeque<Entry>)>> = Mutex::new(None);

/// One thing that ran: what, on which thread, when it began and ended (ms since the trail began).
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct Entry {
    pub id: u64,
    pub what: String,
    pub thread: String,
    pub began_ms: u128,
    pub ended_ms: Option<u128>,
}

/// Starts the trail (the smoke test only).
pub fn start() {
    *TRAIL.lock().unwrap_or_else(|p| p.into_inner()) = Some((Instant::now(), VecDeque::new()));
    ON.store(true, Ordering::SeqCst);
}

/// Something begins on this thread; 0 when nothing is recorded.
pub fn begin(what: &str) -> u64 {
    begin_with(|| what.to_string())
}

/// [`begin`], its words made only while the trail is kept.
pub fn begin_with(what: impl FnOnce() -> String) -> u64 {
    if !ON.load(Ordering::Relaxed) {
        return 0;
    }
    let what = what();
    let id = NEXT.fetch_add(1, Ordering::Relaxed);
    let thread = std::thread::current().name().unwrap_or("unnamed").to_string();
    let mut trail = TRAIL.lock().unwrap_or_else(|p| p.into_inner());
    if let Some((since, entries)) = trail.as_mut() {
        if entries.len() == KEEP {
            entries.pop_front();
        }
        entries.push_back(Entry { id, what, thread, began_ms: since.elapsed().as_millis(), ended_ms: None });
    }
    id
}

/// What began as `id` ended.
pub fn end(id: u64) {
    if id == 0 {
        return;
    }
    let mut trail = TRAIL.lock().unwrap_or_else(|p| p.into_inner());
    if let Some((since, entries)) = trail.as_mut()
        && let Some(entry) = entries.iter_mut().find(|e| e.id == id)
    {
        entry.ended_ms = Some(since.elapsed().as_millis());
    }
}

/// The app's command handler with each command noted in the trail: its name, the thread that received it and when
/// it returned there.
pub fn traced<R: tauri::Runtime>(handler: impl Fn(tauri::ipc::Invoke<R>) -> bool + Send + Sync + 'static) -> impl Fn(tauri::ipc::Invoke<R>) -> bool + Send + Sync + 'static {
    move |invoke| {
        let noted = begin_with(|| format!("command {}", invoke.message.command()));
        let handled = handler(invoke);
        end(noted);
        handled
    }
}

/// A window event's name for the trail (its contents, such as dropped paths, are left out).
pub fn event_name(event: &tauri::WindowEvent) -> &'static str {
    use tauri::WindowEvent as E;
    match event {
        E::Resized(_) => "resized",
        E::Moved(_) => "moved",
        E::CloseRequested { .. } => "close requested",
        E::Destroyed => "destroyed",
        E::Focused(_) => "focused",
        E::ScaleFactorChanged { .. } => "scale factor changed",
        E::DragDrop(_) => "drag and drop",
        E::ThemeChanged(_) => "theme changed",
        _ => "other",
    }
}

/// The trail as kept now.
pub fn trail() -> Vec<Entry> {
    TRAIL.lock().unwrap_or_else(|p| p.into_inner()).as_ref().map(|(_, e)| e.iter().cloned().collect()).unwrap_or_default()
}

/// The runtime's counters, as the report says them.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct Runtime {
    pub workers: usize,
    pub alive_tasks: usize,
    pub queued: usize,
}

/// The report's text (pure).
pub fn report_text(runtime: Option<Runtime>, trail: &[Entry], dump: Option<&str>) -> String {
    let mut out = vec!["stall report:".to_string()];
    out.push(match runtime {
        Some(r) => format!("  async runtime: {} workers, {} tasks alive, {} queued", r.workers, r.alive_tasks, r.queued),
        None => "  async runtime: not readable".into(),
    });
    let open: Vec<&Entry> = trail.iter().filter(|e| e.thread == "main" && e.ended_ms.is_none()).collect();
    out.push(match open.last() {
        Some(e) => format!("  the main thread is in: {} (since {} ms)", e.what, e.began_ms),
        None => "  the main thread is in: nothing recorded (an event loop wait, a window build, or a closure of its own)".into(),
    });
    out.push(format!("  the last {} commands and window events:", trail.len()));
    for e in trail {
        let ended = e.ended_ms.map_or_else(|| "had not returned".to_string(), |m| format!("returned at {m} ms"));
        out.push(format!("    {} ms  {}  on {}  ({ended})", e.began_ms, e.what, e.thread));
    }
    out.push(match dump {
        Some(path) => format!("  every thread's stack: {path}"),
        None => "  every thread's stack: could not be written".into(),
    });
    out.join("\n")
}

/// The report now, with every thread's stack written to a file.
pub fn report() -> String {
    let handle = tauri::async_runtime::handle();
    let metrics = handle.inner().metrics();
    let runtime = Runtime { workers: metrics.num_workers(), alive_tasks: metrics.num_alive_tasks(), queued: metrics.global_queue_depth() };
    let dump = dump_threads().map(|p| p.display().to_string());
    report_text(Some(runtime), &trail(), dump.as_deref())
}

/// Where the stack dump goes: `ECHOLEDGER_STALL_DIR` (CI keeps it), else the temporary folder (never the smoke test's
/// own, which goes when it ends).
fn dump_dir() -> PathBuf {
    std::env::var_os("ECHOLEDGER_STALL_DIR").map(PathBuf::from).unwrap_or_else(std::env::temp_dir)
}

/// Windows: a minidump of this process with every thread's stack.
#[cfg(windows)]
fn dump_threads() -> Option<PathBuf> {
    use std::os::windows::io::AsRawHandle;
    use windows::Win32::Foundation::HANDLE;
    use windows::Win32::System::Diagnostics::Debug::{MINIDUMP_TYPE, MiniDumpWithThreadInfo, MiniDumpWithUnloadedModules, MiniDumpWriteDump};
    use windows::Win32::System::Threading::GetCurrentProcess;
    let dir = dump_dir();
    let _ = std::fs::create_dir_all(&dir);
    let path = dir.join(format!("echoledger-stall-{}.dmp", std::process::id()));
    let file = std::fs::File::create(&path).ok()?;
    let kind = MINIDUMP_TYPE(MiniDumpWithThreadInfo.0 | MiniDumpWithUnloadedModules.0);
    let written = unsafe { MiniDumpWriteDump(GetCurrentProcess(), std::process::id(), HANDLE(file.as_raw_handle()), kind, None, None, None) };
    written.is_ok().then_some(path)
}

/// A Mac: `sample` of this process for 2 s (every thread's stack, symbolised).
#[cfg(target_os = "macos")]
fn dump_threads() -> Option<PathBuf> {
    let dir = dump_dir();
    let _ = std::fs::create_dir_all(&dir);
    let path = dir.join(format!("echoledger-stall-{}.txt", std::process::id()));
    let status = std::process::Command::new("/usr/bin/sample").arg(std::process::id().to_string()).arg("2").arg("-file").arg(&path).status().ok()?;
    (status.success() && path.exists()).then_some(path)
}

#[cfg(not(any(windows, target_os = "macos")))]
fn dump_threads() -> Option<PathBuf> {
    None
}

#[cfg(test)]
mod tests {
    use super::*;

    fn entry(id: u64, what: &str, thread: &str, began: u128, ended: Option<u128>) -> Entry {
        Entry { id, what: what.into(), thread: thread.into(), began_ms: began, ended_ms: ended }
    }

    #[test]
    fn the_report_says_the_runtime_what_the_main_thread_is_in_and_where_the_stacks_are() {
        let trail = [
            entry(1, "command window_ready", "main", 8_500, Some(8_501)),
            entry(2, "command app_status", "tokio-runtime-worker", 8_600, None),
            entry(3, "command overlay_set_hit_regions", "main", 8_700, None),
        ];
        let text = report_text(Some(Runtime { workers: 4, alive_tasks: 12, queued: 3 }), &trail, Some("C:\\t\\echoledger-stall-42.dmp"));
        assert!(text.contains("async runtime: 4 workers, 12 tasks alive, 3 queued"), "{text}");
        assert!(text.contains("the main thread is in: command overlay_set_hit_regions (since 8700 ms)"), "{text}");
        assert!(text.contains("8600 ms  command app_status  on tokio-runtime-worker  (had not returned)"), "{text}");
        assert!(text.contains("8500 ms  command window_ready  on main  (returned at 8501 ms)"), "{text}");
        assert!(text.contains("every thread's stack: C:\\t\\echoledger-stall-42.dmp"));
        let bare = report_text(None, &[], None);
        assert!(bare.contains("async runtime: not readable") && bare.contains("nothing recorded") && bare.contains("could not be written"));
    }

    #[test]
    fn the_trail_keeps_the_last_entries_with_their_thread_and_end() {
        start();
        let id = begin("command one");
        end(id);
        for i in 0..KEEP {
            begin(&format!("command {i}"));
        }
        let kept = trail();
        assert_eq!(kept.len(), KEEP, "the oldest go");
        assert!(!kept.iter().any(|e| e.what == "command one"));
        assert!(kept.iter().all(|e| !e.thread.is_empty()));
        ON.store(false, Ordering::SeqCst);
        assert_eq!(begin("not recorded"), 0, "outside the smoke test nothing is kept");
    }
}
