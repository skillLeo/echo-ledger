//! Windows capture via the `wasapi` crate (docs/research/desktop-audio-native.md §2).
//! System audio: process loopback EXCLUDING our own process tree (build ≥ 20348), else endpoint loopback of the
//! default render device. Mic: the chosen microphone (`inputDevice`) while connected, else the default
//! communications capture device. Both request 16 kHz mono f32 with
//! AUTOCONVERTPCM, event-driven shared mode, and timestamps from GetBuffer's QPC position (100 ns units).

use std::collections::{HashMap, VecDeque};
use std::sync::Arc;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::mpsc::Sender;
use std::thread::JoinHandle;
use std::time::{Duration, Instant};

use wasapi::{AudioClient, DeviceEnumerator, Direction, Role, SampleType, StreamMode, WaveFormat, initialize_mta};

use super::{CaptureBackend, Streams, supports_process_loopback};
use crate::audio::devices::{DeviceLog, DeviceNotice, Reopener, fall_back_to_endpoint, packet_limit};
use crate::audio::types::{AudioChunk, BackendKind, CaptureError, Channel, TARGET_RATE};

/// Windows build number via RtlGetVersion (GetVersionEx lies to unmanifested apps).
pub fn os_build() -> Option<u32> {
    use windows::Wdk::System::SystemServices::RtlGetVersion;
    use windows::Win32::System::SystemInformation::OSVERSIONINFOW;
    let mut info = OSVERSIONINFOW { dwOSVersionInfoSize: std::mem::size_of::<OSVERSIONINFOW>() as u32, ..Default::default() };
    if unsafe { RtlGetVersion(&mut info) }.is_ok() { Some(info.dwBuildNumber) } else { None }
}

/// The running recording's process loopback failed and it records through endpoint loopback (all system sound) now.
static FELL_BACK: AtomicBool = AtomicBool::new(false);

pub fn fell_back() -> bool {
    FELL_BACK.load(Ordering::SeqCst)
}

pub struct WindowsBackend {
    kind: BackendKind,
    stop: Arc<AtomicBool>,
    threads: Vec<JoinHandle<()>>,
    log: DeviceLog,
}

impl WindowsBackend {
    pub fn start(streams: Streams, tx: Sender<AudioChunk>) -> Result<Self, CaptureError> {
        let stop = Arc::new(AtomicBool::new(false));
        let process_loopback = os_build().is_some_and(supports_process_loopback);
        let kind = if process_loopback { BackendKind::ProcessLoopback } else { BackendKind::EndpointLoopback };
        let mut threads = Vec::new();
        let log = DeviceLog::default();

        if streams.system {
            FELL_BACK.store(false, Ordering::SeqCst); // this recording tries process loopback afresh
            let (stop, tx, log) = (stop.clone(), tx.clone(), log.clone());
            threads.push(spawn_named("el-audio-system", move || capture_loop(Channel::System, process_loopback, &stop, &tx, &log)));
        }
        if streams.mic {
            let (stop, tx, log) = (stop.clone(), tx.clone(), log.clone());
            threads.push(spawn_named("el-audio-mic", move || capture_loop(Channel::Mic, false, &stop, &tx, &log)));
        }
        Ok(Self { kind, stop, threads, log })
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

    fn take_notices(&mut self) -> Vec<DeviceNotice> {
        self.log.take()
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
/// (each reopen leaves a gap the timeline fills with silence; `devices.rs` decides the waits and what is said).
fn capture_loop(channel: Channel, process_loopback: bool, stop: &AtomicBool, tx: &Sender<AudioChunk>, log: &DeviceLog) {
    if initialize_mta().is_err() {
        // Already initialized on this thread is fine; anything else surfaces on the first call below.
    }
    let mut use_process_loopback = process_loopback;
    let mut reopener = Reopener::new(channel);
    while !stop.load(Ordering::SeqCst) {
        let result = run_stream(channel, use_process_loopback, stop, tx, &mut |news: StreamNews| {
            let notice = match news {
                StreamNews::Opened { id, name } => reopener.opened_on(Instant::now(), id, name),
                StreamNews::Delivering => reopener.delivering(),
            };
            if let Some(notice) = notice {
                log.push(notice);
            }
        });
        match result {
            Ok(()) => break, // stopped normally
            Err(err) => {
                eprintln!("[echoledger] {channel:?} capture error: {err}; reopening");
                if err == NO_AUDIO
                    && let Some(notice) = reopener.stalled(Instant::now())
                {
                    log.push(notice);
                }
                let (wait, notice) = reopener.failed(Instant::now());
                if let Some(notice) = notice {
                    log.push(notice);
                }
                if channel == Channel::System && use_process_loopback && fall_back_to_endpoint(reopener.ever_opened(), reopener.failures()) {
                    use_process_loopback = false; // feature-test fallback to endpoint loopback
                    FELL_BACK.store(true, Ordering::SeqCst);
                    eprintln!("[echoledger] process loopback failed: this recording now has all sound playing on this computer");
                }
                std::thread::sleep(wait);
            }
        }
    }
}

/// What a stream tells `capture_loop` while it runs.
enum StreamNews {
    /// It runs, on this device when it has one of its own.
    Opened { id: Option<String>, name: Option<String> },
    /// Its first packet arrived.
    Delivering,
}

/// The error of a microphone stream that stayed open and delivered nothing.
const NO_AUDIO: &str = "no audio from the microphone";

/// One stream until `stop` (`Ok`) or until its device goes or should change (`Err`). `news` is told once it runs and when
/// its first packet arrives.
fn run_stream(channel: Channel, process_loopback: bool, stop: &AtomicBool, tx: &Sender<AudioChunk>, news: &mut dyn FnMut(StreamNews)) -> Result<(), String> {
    let format = WaveFormat::new(32, 32, &SampleType::Float, TARGET_RATE as usize, 1, None);
    let mode = StreamMode::EventsShared { autoconvert: true, buffer_duration_hns: 0 };
    let enumerator = DeviceEnumerator::new().map_err(|e| e.to_string())?;
    let mut follow_default = true;

    let (mut client, device_id, device_name): (AudioClient, Option<String>, Option<String>) = match channel {
        Channel::System if process_loopback => {
            // `false` = PROCESS_LOOPBACK_MODE_EXCLUDE_TARGET_PROCESS_TREE: everything except EchoLedger itself.
            (AudioClient::new_application_loopback_client(std::process::id(), false).map_err(|e| e.to_string())?, None, None)
        }
        Channel::System => {
            let device = enumerator.get_default_device(&Direction::Render).map_err(|e| e.to_string())?;
            let id = device.get_id().ok();
            let name = device.get_friendlyname().ok();
            (device.get_iaudioclient().map_err(|e| e.to_string())?, id, name)
        }
        Channel::Mic => {
            // The chosen microphone while it is connected, else the default communications device.
            let chosen = super::choose_mic(&input_devices_with(&enumerator), super::preferred_mic().as_deref());
            if let Some(device) = chosen.and_then(|id| enumerator.get_device(&id).ok()) {
                follow_default = false;
                let id = device.get_id().ok();
                let name = device.get_friendlyname().ok();
                (device.get_iaudioclient().map_err(|e| e.to_string())?, id, name)
            } else {
                let device = enumerator.get_default_device_for_role(&Direction::Capture, &Role::Communications).map_err(|e| e.to_string())?;
                let id = device.get_id().ok();
                let name = device.get_friendlyname().ok();
                (device.get_iaudioclient().map_err(|e| e.to_string())?, id, name)
            }
        }
    };

    client.initialize_client(&format, &Direction::Capture, &mode).map_err(|e| e.to_string())?;
    let event = client.set_get_eventhandle().map_err(|e| e.to_string())?;
    let capture = client.get_audiocaptureclient().map_err(|e| e.to_string())?;
    client.start_stream().map_err(|e| e.to_string())?;
    news(StreamNews::Opened { id: device_id.clone(), name: device_name });

    let mut queue: VecDeque<u8> = VecDeque::new();
    let mut last_device_check = Instant::now();
    let mut last_packet = Instant::now();
    let mut delivered = false;
    let result = (|| -> Result<(), String> {
        while !stop.load(Ordering::SeqCst) {
            // Follow device changes (headset unplug / Bluetooth switch, a chosen microphone going or coming back) for
            // endpoint streams.
            if let Some(id) = device_id.as_ref()
                && last_device_check.elapsed() > Duration::from_secs(1)
            {
                last_device_check = Instant::now();
                if channel == Channel::Mic {
                    let chosen = super::preferred_mic().and_then(|preferred| super::choose_mic(&input_devices_with(&enumerator), Some(&preferred)));
                    match chosen {
                        Some(chosen) if chosen != *id => return Err("the chosen microphone changed".into()),
                        None if !follow_default => return Err("the chosen microphone is gone".into()),
                        _ => {}
                    }
                }
                if follow_default {
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
            }
            if event.wait_for_event(200).is_err() {
                // Timeout: no packets (silence on endpoint loopback); the timeline fills the gap. A device that was
                // removed sends no more events either: asking for the next packet is what reports it.
                capture.get_next_packet_size().map_err(|e| e.to_string())?;
                // A microphone delivers packets even in silence, so none for a while means it died quietly. A new stream
                // has longer for its first one, so a device that is slow to wake is not torn down.
                if channel == Channel::Mic && last_packet.elapsed() >= packet_limit(delivered) {
                    return Err(NO_AUDIO.into());
                }
                continue;
            }
            while capture.get_next_packet_size().map_err(|e| e.to_string())?.unwrap_or(0) > 0 {
                last_packet = Instant::now();
                if !delivered {
                    delivered = true;
                    news(StreamNews::Delivering);
                }
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

/// The active capture endpoints with their friendly names; the default communications device is marked, and
/// Bluetooth ones are told by their device instance id or their name (`is_bluetooth_mic`).
fn input_devices_with(enumerator: &DeviceEnumerator) -> Vec<super::InputDevice> {
    let default = enumerator.get_default_device_for_role(&Direction::Capture, &Role::Communications).ok().and_then(|d| d.get_id().ok());
    let Ok(collection) = enumerator.get_device_collection(&Direction::Capture) else { return Vec::new() };
    (&collection)
        .into_iter()
        .filter_map(Result::ok)
        .filter_map(|device| {
            let id = device.get_id().ok()?;
            let name = device.get_friendlyname().unwrap_or_else(|_| "Microphone".into());
            let bluetooth = super::is_bluetooth_mic(super::bus_of_instance_id(instance_id(&device).as_deref()), &name);
            Some(super::InputDevice { is_default: default.as_deref() == Some(id.as_str()), id, name, bluetooth })
        })
        .collect()
}

/// The device instance id of the hardware behind an endpoint ("{1}.BTHHFENUM\BthHFPAudio\8&…" for a Bluetooth
/// headset), from the endpoint's property store ({b3f8fa53-0004-438e-9003-51a46e139bfc},2); none when it cannot be read.
fn instance_id(device: &wasapi::Device) -> Option<String> {
    use windows::Win32::Foundation::PROPERTYKEY;
    use windows::Win32::System::Com::StructuredStorage::{PropVariantClear, PropVariantToStringAlloc};
    use windows::Win32::System::Com::{CoTaskMemFree, STGM_READ};
    const KEY: PROPERTYKEY = PROPERTYKEY { fmtid: windows::core::GUID::from_u128(0xb3f8fa53_0004_438e_9003_51a46e139bfc), pid: 2 };
    // The store and the value are released here: PropVariantClear for the value, CoTaskMemFree for the copied text.
    let store = unsafe { device.as_immdevice().OpenPropertyStore(STGM_READ) }.ok()?;
    let mut value = unsafe { store.GetValue(&KEY) }.ok()?;
    let text = unsafe { PropVariantToStringAlloc(&value) };
    let _ = unsafe { PropVariantClear(&mut value) };
    let text = text.ok()?;
    let id = unsafe { text.to_string() }.ok();
    unsafe { CoTaskMemFree(Some(text.0.cast())) };
    id.filter(|id| !id.is_empty())
}

/// The connected microphones (`input_devices` command).
pub fn input_devices() -> Vec<super::InputDevice> {
    let _ = initialize_mta();
    DeviceEnumerator::new().map(|e| input_devices_with(&e)).unwrap_or_default()
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

/// Apps playing sound now (Live Translate, v1.3): (pid, image file name) of every active render session.
pub fn active_playback_processes() -> Vec<(u32, String)> {
    active_session_pids(Direction::Render).into_iter().map(|pid| (pid, process_identity(pid).0)).filter(|(_, image)| !image.is_empty()).collect()
}

/// One app's sound for Live Translate (v1.3): process loopback that INCLUDES `pid` and its child processes (a
/// browser with all its tabs), 16 kHz mono f32 (WASAPI converts), handed to `on_samples` until `stop` is set. Needs
/// Windows build 20348 or later; no injection into the app, only the audio engine's own copy of its sound.
pub fn app_loopback(pid: u32, stop: &AtomicBool, mut on_samples: impl FnMut(Vec<f32>)) -> Result<(), String> {
    if !os_build().is_some_and(supports_process_loopback) {
        return Err("Live Translate needs Windows 10 21H2 (build 20348) or later to hear one app.".into());
    }
    let _ = initialize_mta();
    let format = WaveFormat::new(32, 32, &SampleType::Float, 16_000, 1, None);
    let mode = StreamMode::EventsShared { autoconvert: true, buffer_duration_hns: 0 };
    // `true` = PROCESS_LOOPBACK_MODE_INCLUDE_TARGET_PROCESS_TREE: only this app (and its children).
    let mut client = AudioClient::new_application_loopback_client(pid, true).map_err(|e| e.to_string())?;
    client.initialize_client(&format, &Direction::Capture, &mode).map_err(|e| e.to_string())?;
    let event = client.set_get_eventhandle().map_err(|e| e.to_string())?;
    let capture = client.get_audiocaptureclient().map_err(|e| e.to_string())?;
    client.start_stream().map_err(|e| e.to_string())?;
    let mut queue: VecDeque<u8> = VecDeque::new();
    let result = (|| -> Result<(), String> {
        while !stop.load(Ordering::SeqCst) {
            if event.wait_for_event(200).is_err() {
                continue;
            }
            while capture.get_next_packet_size().map_err(|e| e.to_string())?.unwrap_or(0) > 0 {
                let start = queue.len();
                let info = capture.read_from_device_to_deque(&mut queue).map_err(|e| e.to_string())?;
                if info.flags.silent {
                    queue.range_mut(start..).for_each(|b| *b = 0);
                }
                let bytes: Vec<u8> = queue.drain(..).collect();
                on_samples(bytes.as_chunks::<4>().0.iter().map(|b| f32::from_le_bytes(*b)).collect());
            }
        }
        Ok(())
    })();
    let _ = client.stop_stream();
    result
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
pub(crate) fn descends_from(pid: u32, ancestor: u32, parents: &HashMap<u32, u32>) -> bool {
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

/// (owning pid, title) of every visible top-level window of another process with a non-empty title. `EnumWindows`,
/// `IsWindowVisible`, `GetWindowThreadProcessId`, `InternalGetWindowText` (windows-0.62.2
/// `Win32/UI/WindowsAndMessaging/mod.rs`). One UTF-16 buffer is reused.
///
/// Never sends a window message: `GetWindowTextW` sends WM_GETTEXT to windows of this process and waits for the UI
/// thread, so reading titles from the auto-mode thread during a call (since v1.2, for caller names) could freeze the
/// app. EchoLedger's own windows are skipped and `InternalGetWindowText` reads the stored caption.
fn visible_window_titles() -> Vec<(u32, String)> {
    use windows::Win32::Foundation::{HWND, LPARAM};
    use windows::Win32::UI::WindowsAndMessaging::{EnumWindows, GetWindowThreadProcessId, InternalGetWindowText, IsWindowVisible};
    use windows::core::BOOL;

    struct Collect {
        own: u32,
        buf: Vec<u16>,
        out: Vec<(u32, String)>,
    }

    unsafe extern "system" fn each(hwnd: HWND, lparam: LPARAM) -> BOOL {
        // SAFETY: `lparam` is the `&mut Collect` passed to EnumWindows below, alive for the whole enumeration.
        let ctx = unsafe { &mut *(lparam.0 as *mut Collect) };
        if !unsafe { IsWindowVisible(hwnd) }.as_bool() {
            return BOOL::from(true);
        }
        let mut pid = 0u32;
        unsafe { GetWindowThreadProcessId(hwnd, Some(&mut pid)) };
        if pid == 0 || pid == ctx.own {
            return BOOL::from(true);
        }
        let copied = unsafe { InternalGetWindowText(hwnd, &mut ctx.buf) };
        if copied <= 0 {
            return BOOL::from(true);
        }
        let title = String::from_utf16_lossy(&ctx.buf[..copied as usize]);
        if !title.trim().is_empty() {
            ctx.out.push((pid, title));
        }
        BOOL::from(true)
    }

    let mut ctx = Collect { own: std::process::id(), buf: vec![0u16; 512], out: Vec::new() };
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
pub(crate) fn parent_map() -> std::collections::HashMap<u32, u32> {
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

pub(crate) fn process_identity(pid: u32) -> (String, Option<String>) {
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
