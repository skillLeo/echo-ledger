//! Windows capture via the `wasapi` crate (docs/research/desktop-audio-native.md §2).
//! System audio: process loopback EXCLUDING our own process tree (build ≥ 20348), else endpoint loopback of the
//! default render device. Mic: default communications capture device. Both request 16 kHz mono f32 with
//! AUTOCONVERTPCM, event-driven shared mode, and timestamps from GetBuffer's QPC position (100 ns units).

use std::collections::VecDeque;
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
            if let Some(id) = &device_id {
                if last_default_check.elapsed() > Duration::from_secs(1) {
                    last_default_check = std::time::Instant::now();
                    let current = match channel {
                        Channel::Mic => enumerator.get_default_device_for_role(&Direction::Capture, &Role::Communications),
                        Channel::System => enumerator.get_default_device(&Direction::Render),
                    };
                    if let Ok(device) = current {
                        if device.get_id().ok().as_ref() != Some(id) {
                            return Err("default device changed".into());
                        }
                    }
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
                let samples: Vec<f32> = bytes.chunks_exact(4).map(|b| f32::from_le_bytes([b[0], b[1], b[2], b[3]])).collect();
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

/// Active capture sessions on every capture endpoint: (pid, image file name, package family name).
/// Used by auto mode to see which app holds the microphone.
pub fn active_capture_processes() -> Vec<(u32, String, Option<String>)> {
    let mut out = Vec::new();
    let _ = initialize_mta();
    let Ok(enumerator) = DeviceEnumerator::new() else { return out };
    let Ok(collection) = enumerator.get_device_collection(&Direction::Capture) else { return out };
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
            if pid == 0 || pid == std::process::id() {
                continue;
            }
            let (image, pfn) = process_identity(pid);
            out.push((pid, image, pfn));
        }
    }
    out
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
