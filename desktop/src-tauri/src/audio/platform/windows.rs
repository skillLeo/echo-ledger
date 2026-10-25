//! Windows capture via the `wasapi` crate (docs/research/desktop-audio-native.md §2).
//! System audio: process loopback EXCLUDING our own process tree (build ≥ 20348), else endpoint loopback of the
//! default render device. Mic: default communications capture device. Both request 16 kHz mono f32 with
//! AUTOCONVERTPCM, event-driven shared mode, and timestamps from GetBuffer's QPC position (100 ns units).

use std::collections::{HashMap, VecDeque};
use std::sync::Arc;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::mpsc::Sender;
use std::thread::JoinHandle;
use std::time::Duration;

use wasapi::{AudioClient, DeviceEnumerator, Direction, Role, SampleType, StreamMode, WaveFormat, initialize_mta};

use super::{CaptureBackend, Streams, supports_process_loopback};
use crate::audio::types::{AudioChunk, BackendKind, CaptureError, Channel, TARGET_RATE};

/// Windows build number via RtlGetVersion (GetVersionEx lies to unmanifested apps).
pub fn os_build() -> Option<u32> {
    use windows::Wdk::System::SystemServices::RtlGetVersion;
    use windows::Win32::System::SystemInformation::OSVERSIONINFOW;
    let mut info = OSVERSIONINFOW { dwOSVersionInfoSize: std::mem::size_of::<OSVERSIONINFOW>() as u32, ..Default::default() };
    if unsafe { RtlGetVersion(&mut info) }.is_ok() { Some(info.dwBuildNumber) } else { None }
}

pub struct WindowsBackend {
    kind: BackendKind,
    stop: Arc<AtomicBool>,
    threads: Vec<JoinHandle<()>>,
}

impl WindowsBackend {
    pub fn start(streams: Streams, tx: Sender<AudioChunk>) -> Result<Self, CaptureError> {
        let stop = Arc::new(AtomicBool::new(false));
        let process_loopback = os_build().is_some_and(supports_process_loopback);
        let kind = if process_loopback { BackendKind::ProcessLoopback } else { BackendKind::EndpointLoopback };
        let mut threads = Vec::new();

        if streams.system {
            let (stop, tx) = (stop.clone(), tx.clone());
            threads.push(spawn_named("el-audio-system", move || capture_loop(Channel::System, process_loopback, &stop, &tx)));
        }
        if streams.mic {
            let (stop, tx) = (stop.clone(), tx.clone());
            threads.push(spawn_named("el-audio-mic", move || capture_loop(Channel::Mic, false, &stop, &tx)));
        }
        Ok(Self { kind, stop, threads })
    }
}

impl CaptureBackend for WindowsBackend {
    fn kind(&self) -> BackendKind {
        self.kind.clone()
    }

    fn stop(&mut self) {
        self.stop.store(true, Ordering::SeqCst);
        for handle in self.threads.drain(..) {
            let _ = handle.join();
        }
    }
}

impl Drop for WindowsBackend {
    fn drop(&mut self) {
        self.stop();
    }
}

fn spawn_named(name: &str, f: impl FnOnce() + Send + 'static) -> JoinHandle<()> {
    std::thread::Builder::new().name(name.into()).spawn(f).expect("spawn audio thread")
}

/// Opens the stream, captures until `stop`, and transparently reopens after device loss or default-device change
/// (each reopen leaves a gap the timeline fills with silence).
fn capture_loop(channel: Channel, process_loopback: bool, stop: &AtomicBool, tx: &Sender<AudioChunk>) {
    if initialize_mta().is_err() {
        // Already initialized on this thread is fine; anything else surfaces on the first call below.
    }
    let mut use_process_loopback = process_loopback;
    let mut backoff = Duration::from_millis(250);
    while !stop.load(Ordering::SeqCst) {
        match run_stream(channel, use_process_loopback, stop, tx) {
            Ok(()) => break, // stopped normally
            Err(err) => {
                eprintln!("[echoledger] {channel:?} capture error: {err}; reopening");
                if channel == Channel::System && use_process_loopback {
                    use_process_loopback = false; // feature-test fallback to endpoint loopback
                }
                std::thread::sleep(backoff);
                backoff = (backoff * 2).min(Duration::from_secs(3));
            }
        }
    }
}

fn run_stream(channel: Channel, process_loopback: bool, stop: &AtomicBool, tx: &Sender<AudioChunk>) -> Result<(), String> {
    let format = WaveFormat::new(32, 32, &SampleType::Float, TARGET_RATE as usize, 1, None);
    let mode = StreamMode::EventsShared { autoconvert: true, buffer_duration_hns: 0 };
    let enumerator = DeviceEnumerator::new().map_err(|e| e.to_string())?;

    let (mut client, device_id): (AudioClient, Option<String>) = match channel {
        Channel::System if process_loopback => {
            // `false` = PROCESS_LOOPBACK_MODE_EXCLUDE_TARGET_PROCESS_TREE: everything except EchoLedger itself.
            (AudioClient::new_application_loopback_client(std::process::id(), false).map_err(|e| e.to_string())?, None)
        }
        Channel::System => {
            let device = enumerator.get_default_device(&Direction::Render).map_err(|e| e.to_string())?;
            let id = device.get_id().ok();
            (device.get_iaudioclient().map_err(|e| e.to_string())?, id)
        }
        Channel::Mic => {
            let device = enumerator.get_default_device_for_role(&Direction::Capture, &Role::Communications).map_err(|e| e.to_string())?;
            let id = device.get_id().ok();
            (device.get_iaudioclient().map_err(|e| e.to_string())?, id)
        }
    };

    client.initialize_client(&format, &Direction::Capture, &mode).map_err(|e| e.to_string())?;
    let event = client.set_get_eventhandle().map_err(|e| e.to_string())?;
    let capture = client.get_audiocaptureclient().map_err(|e| e.to_string())?;
    client.start_stream().map_err(|e| e.to_string())?;

    let mut queue: VecDeque<u8> = VecDeque::new();
    let mut last_default_check = std::time::Instant::now();
    let result = (|| -> Result<(), String> {
        while !stop.load(Ordering::SeqCst) {
            // Follow default-device changes (headset unplug / Bluetooth switch) for endpoint streams.
            if let Some(id) = &device_id
                && last_default_check.elapsed() > Duration::from_secs(1)
            {
                last_default_check = std::time::Instant::now();
                let current = match channel {
                    Channel::Mic => enumerator.get_default_device_for_role(&Direction::Capture, &Role::Communications),
                    Channel::System => enumerator.get_default_device(&Direction::Render),
                };
                if let Ok(device) = current
                    && device.get_id().ok().as_ref() != Some(id)
                {
                    return Err("default device changed".into());
                }
            }
            if event.wait_for_event(200).is_err() {
                continue; // timeout: no packets (silence on endpoint loopback); the timeline fills the gap
            }
            while capture.get_next_packet_size().map_err(|e| e.to_string())?.unwrap_or(0) > 0 {
                let start = queue.len();
                let info = capture.read_from_device_to_deque(&mut queue).map_err(|e| e.to_string())?;
                if info.flags.silent {
                    queue.range_mut(start..).for_each(|b| *b = 0); // wasapi does not zero-fill SILENT packets
                }
                let bytes: Vec<u8> = queue.drain(..).collect();
                let samples: Vec<f32> = bytes.as_chunks::<4>().0.iter().map(|b| f32::from_le_bytes(*b)).collect();
                let ts_ns = if info.flags.timestamp_error { None } else { Some(info.timestamp.saturating_mul(100)) };
                if tx.send(AudioChunk { channel, samples, ts_ns, discontinuity: info.flags.data_discontinuity }).is_err() {
                    return Ok(()); // recorder gone
                }
            }
        }
        Ok(())
    })();
    let _ = client.stop_stream();
    result
}

/// Pids with an Active audio session on any active endpoint of `direction` (EchoLedger and the idle process
/// excluded). Sessions come from `IAudioSessionManager2::GetSessionEnumerator` / `IAudioSessionEnumerator`, the pid
/// from `IAudioSessionControl2::GetProcessId` (wasapi-0.26.0 `src/api.rs`: `get_device_collection` line 375,
/// `get_iaudiosessionmanager` 601, `get_audiosessionenumerator`/`get_count`/`get_session` 1690-1712,
/// `get_state`/`get_process_id` 1813-1851).
pub fn active_session_pids(direction: Direction) -> Vec<u32> {
    let mut out = Vec::new();
    let _ = initialize_mta();
    let Ok(enumerator) = DeviceEnumerator::new() else { return out };
    let Ok(collection) = enumerator.get_device_collection(&direction) else { return out };
    let own = std::process::id();
    let count = collection.get_nbr_devices().unwrap_or(0);
    for i in 0..count {
        let Ok(device) = collection.get_device_at_index(i) else { continue };
        let Ok(manager) = device.get_iaudiosessionmanager() else { continue };
        let Ok(sessions) = manager.get_audiosessionenumerator() else { continue };
        let n = sessions.get_count().unwrap_or(0);
        for s in 0..n {
            let Ok(control) = sessions.get_session(s) else { continue };
            if !matches!(control.get_state(), Ok(wasapi::SessionState::Active)) {
                continue;
            }
            let Ok(pid) = control.get_process_id() else { continue };
            if pid != 0 && pid != own && !out.contains(&pid) {
                out.push(pid);
            }
        }
    }
    out
}

/// Active capture sessions on every capture endpoint: (pid, image file name, package family name).
pub fn active_capture_processes() -> Vec<(u32, String, Option<String>)> {
    active_session_pids(Direction::Capture)
        .into_iter()
        .map(|pid| {
            let (image, pfn) = process_identity(pid);
            (pid, image, pfn)
        })
        .collect()
}

/// Is `identity` a call app or a browser (the processes a tree is anchored on)?
fn recognised(identity: &(String, Option<String>)) -> bool {
    crate::detect::classify::classify_windows(&identity.0, identity.1.as_deref()).is_some() || crate::detect::classify::is_browser_image(&identity.0)
}

/// From `pid`, climbs while the parent has the same image name (case-insensitive) and returns the top-most one.
fn top_same_name(pid: u32, image: &str, parents: &HashMap<u32, u32>, identity: &impl Fn(u32) -> (String, Option<String>)) -> u32 {
    let mut top = pid;
    if image.is_empty() {
        return top;
    }
    for _ in 0..MAX_ANCESTORS {
        let Some(&parent) = parents.get(&top) else { break };
        if parent == 0 || parent == top || parent == pid {
            break; // root, self-parented or a cycle
        }
        if !identity(parent).0.eq_ignore_ascii_case(image) {
            break;
        }
        top = parent;
    }
    top
}

/// How far the parent walks go (helpers sit 1-4 levels below the app; the bound keeps stale tables cheap).
const MAX_ANCESTORS: usize = 8;

/// The root of the application tree `pid` belongs to. Anchor: the process itself if it is a call app or a browser,
/// else the nearest of up to 8 ancestors that is; the root is then the top-most same-name ancestor of the anchor
/// (a Chrome utility process → the Chrome browser process; WebView2 → WhatsApp). Without an anchor: the top-most
/// same-name ancestor of `pid` (multi-process apps), else `pid`. `identity` maps a pid to (image file name, package
/// family name) and is injectable for tests.
pub fn resolve_root_with(pid: u32, parents: &HashMap<u32, u32>, identity: impl Fn(u32) -> (String, Option<String>)) -> u32 {
    let own = identity(pid);
    let mut anchor = recognised(&own).then_some((pid, own.0.clone()));
    if anchor.is_none() {
        let mut current = pid;
        for _ in 0..MAX_ANCESTORS {
            let Some(&parent) = parents.get(&current) else { break };
            if parent == 0 || parent == current || parent == pid {
                break;
            }
            let who = identity(parent);
            if recognised(&who) {
                anchor = Some((parent, who.0));
                break;
            }
            current = parent;
        }
    }
    match anchor {
        Some((at, image)) => top_same_name(at, &image, parents, &identity),
        None => top_same_name(pid, &own.0, parents, &identity),
    }
}

/// Is `pid` the process `ancestor` or one of its descendants within 8 levels (EchoLedger's own WebView2
/// processes; windows of a browser tree)?
fn descends_from(pid: u32, ancestor: u32, parents: &HashMap<u32, u32>) -> bool {
    let mut current = pid;
    for _ in 0..=MAX_ANCESTORS {
        if current == ancestor {
            return true;
        }
        match parents.get(&current) {
            Some(&parent) if parent != 0 && parent != current => current = parent,
            _ => return false,
        }
    }
    false
}

/// The application trees that hold a microphone, each with whether it also plays audio and, for the roots
/// `need_titles` accepts (browsers), its visible window titles. Does nothing beyond the capture enumeration when no
/// other process holds a microphone.
pub fn observe_trees(need_titles: impl Fn(&str) -> bool) -> Vec<crate::detect::decide::TreeAudio> {
    use crate::detect::decide::TreeAudio;

    let capture = active_session_pids(Direction::Capture);
    if capture.is_empty() {
        return Vec::new();
    }
    let render = active_session_pids(Direction::Render);
    let parents = parent_map();
    let own = std::process::id();
    let cache: std::cell::RefCell<HashMap<u32, (String, Option<String>)>> = Default::default();
    let identity = |pid: u32| cache.borrow_mut().entry(pid).or_insert_with(|| process_identity(pid)).clone();

    let mut trees: Vec<TreeAudio> = Vec::new();
    for pid in capture {
        if descends_from(pid, own, &parents) {
            continue;
        }
        let root = resolve_root_with(pid, &parents, identity);
        if trees.iter().any(|t| t.root_pid == root) {
            continue;
        }
        let (image, pfn) = identity(root);
        let name = if image.is_empty() { "a protected process".to_string() } else { image };
        trees.push(TreeAudio { root_pid: root, name, pfn, capture_active: true, render_active: false, titles: Vec::new(), urls: Vec::new() });
    }
    for pid in render {
        if descends_from(pid, own, &parents) {
            continue;
        }
        let root = resolve_root_with(pid, &parents, identity);
        if let Some(tree) = trees.iter_mut().find(|t| t.root_pid == root) {
            tree.render_active = true;
        }
    }
    let wanted: Vec<u32> = trees.iter().filter(|t| need_titles(&t.name)).map(|t| t.root_pid).collect();
    if !wanted.is_empty() {
        for (pid, title) in visible_window_titles() {
            // Cheap filter first (the parent walk), then the exact root (a Zoom window launched by Chrome is Zoom's).
            if !wanted.iter().any(|root| descends_from(pid, *root, &parents)) {
                continue;
            }
            let root = resolve_root_with(pid, &parents, identity);
            if let Some(tree) = trees.iter_mut().find(|t| t.root_pid == root && wanted.contains(&root))
                && !tree.titles.contains(&title)
            {
                tree.titles.push(title);
            }
        }
    }
    trees.sort_by_key(|t| t.root_pid);
    trees
}

/// (owning pid, title) of every visible top-level window with a non-empty title. `EnumWindows`, `IsWindowVisible`,
/// `GetWindowTextLengthW`, `GetWindowTextW`, `GetWindowThreadProcessId` (windows-0.62.2
/// `Win32/UI/WindowsAndMessaging/mod.rs` lines 652, 1350, 1174, 1179, 1184). One UTF-16 buffer is reused.
fn visible_window_titles() -> Vec<(u32, String)> {
    use windows::Win32::Foundation::{HWND, LPARAM};
    use windows::Win32::UI::WindowsAndMessaging::{EnumWindows, GetWindowTextLengthW, GetWindowTextW, GetWindowThreadProcessId, IsWindowVisible};
    use windows::core::BOOL;

    struct Collect {
        buf: Vec<u16>,
        out: Vec<(u32, String)>,
    }

    unsafe extern "system" fn each(hwnd: HWND, lparam: LPARAM) -> BOOL {
        // SAFETY: `lparam` is the `&mut Collect` passed to EnumWindows below, alive for the whole enumeration.
        let ctx = unsafe { &mut *(lparam.0 as *mut Collect) };
        if !unsafe { IsWindowVisible(hwnd) }.as_bool() {
            return BOOL::from(true);
        }
        let len = unsafe { GetWindowTextLengthW(hwnd) };
        if len <= 0 {
            return BOOL::from(true);
        }
        let need = len as usize + 1;
        if ctx.buf.len() < need {
            ctx.buf.resize(need, 0);
        }
        let copied = unsafe { GetWindowTextW(hwnd, &mut ctx.buf[..need]) };
        if copied <= 0 {
            return BOOL::from(true);
        }
        let mut pid = 0u32;
        unsafe { GetWindowThreadProcessId(hwnd, Some(&mut pid)) };
        if pid != 0 {
            let title = String::from_utf16_lossy(&ctx.buf[..copied as usize]);
            if !title.trim().is_empty() {
                ctx.out.push((pid, title));
            }
        }
        BOOL::from(true)
    }

    let mut ctx = Collect { buf: vec![0u16; 256], out: Vec::new() };
    let _ = unsafe { EnumWindows(Some(each), LPARAM(&mut ctx as *mut Collect as isize)) };
    ctx.out
}

/// Walks up to 8 parents of `pid` and returns the first recognised call app. `identity` maps a pid to
/// (image file name, package family name); injectable so the walk is testable without live processes.
pub fn ancestor_app_with(
    pid: u32,
    parents: &std::collections::HashMap<u32, u32>,
    identity: impl Fn(u32) -> (String, Option<String>),
) -> Option<crate::detect::classify::SourceApp> {
    let mut current = pid;
    for _ in 0..8 {
        let parent = *parents.get(&current)?;
        if parent == 0 || parent == current {
            return None; // reached the root, or a self-parented / stale entry
        }
        let (image, pfn) = identity(parent);
        if let Some(app) = crate::detect::classify::classify_windows(&image, pfn.as_deref()) {
            return Some(app);
        }
        current = parent;
    }
    None
}

/// pid → parent pid for every running process (a single toolhelp snapshot).
fn parent_map() -> std::collections::HashMap<u32, u32> {
    use windows::Win32::Foundation::CloseHandle;
    use windows::Win32::System::Diagnostics::ToolHelp::{CreateToolhelp32Snapshot, PROCESSENTRY32W, Process32FirstW, Process32NextW, TH32CS_SNAPPROCESS};

    let mut map = std::collections::HashMap::new();
    let Ok(snapshot) = (unsafe { CreateToolhelp32Snapshot(TH32CS_SNAPPROCESS, 0) }) else { return map };
    let mut entry = PROCESSENTRY32W { dwSize: std::mem::size_of::<PROCESSENTRY32W>() as u32, ..Default::default() };
    if unsafe { Process32FirstW(snapshot, &mut entry) }.is_ok() {
        loop {
            map.insert(entry.th32ProcessID, entry.th32ParentProcessID);
            if unsafe { Process32NextW(snapshot, &mut entry) }.is_err() {
                break;
            }
        }
    }
    let _ = unsafe { CloseHandle(snapshot) };
    map
}

fn process_identity(pid: u32) -> (String, Option<String>) {
    use windows::Win32::Foundation::CloseHandle;
    use windows::Win32::Storage::Packaging::Appx::GetPackageFamilyName;
    use windows::Win32::System::Threading::{OpenProcess, PROCESS_NAME_FORMAT, PROCESS_QUERY_LIMITED_INFORMATION, QueryFullProcessImageNameW};
    use windows::core::PWSTR;

    let Ok(handle) = (unsafe { OpenProcess(PROCESS_QUERY_LIMITED_INFORMATION, false, pid) }) else {
        return (String::new(), None);
    };
    let mut buf = vec![0u16; 1024];
    let mut len = buf.len() as u32;
    let image = if unsafe { QueryFullProcessImageNameW(handle, PROCESS_NAME_FORMAT(0), PWSTR(buf.as_mut_ptr()), &mut len) }.is_ok() {
        String::from_utf16_lossy(&buf[..len as usize])
    } else {
        String::new()
    };
    let mut pfn_len: u32 = 256;
    let mut pfn_buf = vec![0u16; pfn_len as usize];
    let pfn = if unsafe { GetPackageFamilyName(handle, &mut pfn_len, Some(PWSTR(pfn_buf.as_mut_ptr()))) }.0 == 0 {
        Some(String::from_utf16_lossy(&pfn_buf[..pfn_len.saturating_sub(1) as usize]))
    } else {
        None
    };
    let _ = unsafe { CloseHandle(handle) };
    let file = image.rsplit(['\\', '/']).next().unwrap_or("").to_string();
    (file, pfn)
}
