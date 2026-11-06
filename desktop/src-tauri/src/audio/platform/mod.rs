//! Platform capture backends. Each produces [`AudioChunk`]s (mono f32, 16 kHz, shared clock) for the
//! mic ("Me") and system audio ("Client") on dedicated threads — never on the UI thread.

use std::sync::RwLock;
use std::sync::mpsc::Sender;

use serde::Serialize;

use super::types::{AudioChunk, BackendKind, CaptureError};

/// A microphone the user can pick (B5).
#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct InputDevice {
    /// Windows endpoint id / macOS device UID (what `inputDevice` stores).
    pub id: String,
    pub name: String,
    /// The system's default microphone for calls.
    pub is_default: bool,
    /// A Bluetooth headset or earbuds (v1.5): in a call their microphone runs in the narrowband hands-free profile, so
    /// the app says "Accuracy is lower with Bluetooth mics".
    pub bluetooth: bool,
}

/// How a microphone is connected, as far as the system says.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum MicBus {
    Bluetooth,
    Other,
    /// The system did not say (the property could not be read).
    Unknown,
}

/// Windows: the bus in an endpoint's device instance id ("{1}.BTHHFENUM\BthHFPAudio\…" for a Bluetooth headset,
/// "{1}.USB\VID_…" or "{1}.HDAUDIO\…" for a wired one); none or empty is unknown.
pub fn bus_of_instance_id(instance_id: Option<&str>) -> MicBus {
    let Some(id) = instance_id.map(str::trim).filter(|i| !i.is_empty()) else { return MicBus::Unknown };
    let id = id.to_ascii_uppercase();
    // BTHLE also covers BTHLEDEVICE (Bluetooth LE Audio).
    if ["BTHENUM", "BTHHFENUM", "BTHLE"].iter().any(|bus| id.contains(bus)) { MicBus::Bluetooth } else { MicBus::Other }
}

/// Names only Bluetooth microphones have ("Headset (WH-1000XM4 Hands-Free AG Audio)", "AirPods Pro", "Galaxy Buds2
/// Pro").
const BLUETOOTH_NAMES: [&str; 5] = ["hands-free", "handsfree", "bluetooth", "airpods", "buds"];

/// Whether a microphone is Bluetooth (pure): the system says so, or its name does. A Bluetooth name counts whatever
/// the bus, because a headset behind the audio chip's Bluetooth offload reports that chip's bus. "Headset" alone
/// counts only when the system says nothing, so a wired "Headset Microphone" on the laptop's audio jack is not one.
pub fn is_bluetooth_mic(bus: MicBus, name: &str) -> bool {
    let name = name.to_lowercase();
    let bluetooth_name = BLUETOOTH_NAMES.iter().any(|word| name.contains(word));
    match bus {
        MicBus::Bluetooth => true,
        MicBus::Other => bluetooth_name,
        MicBus::Unknown => bluetooth_name || name.contains("headset"),
    }
}

/// The microphone a recording opens (pure): the chosen one while it is connected, else the system default.
pub fn mic_in_use<'a>(devices: &'a [InputDevice], preferred: Option<&str>) -> Option<&'a InputDevice> {
    match choose_mic(devices, preferred) {
        Some(id) => devices.iter().find(|d| d.id == id),
        None => devices.iter().find(|d| d.is_default),
    }
}

static PREFERRED_MIC: RwLock<Option<String>> = RwLock::new(None);

/// The microphone the next stream opens (`inputDevice`); none follows the system default.
pub fn set_preferred_mic(id: Option<String>) {
    *PREFERRED_MIC.write().unwrap_or_else(|p| p.into_inner()) = id.filter(|i| !i.trim().is_empty());
}

pub fn preferred_mic() -> Option<String> {
    PREFERRED_MIC.read().unwrap_or_else(|p| p.into_inner()).clone()
}

/// The device to open (pure): the preferred one while it is connected, else none (= the system default).
pub fn choose_mic(devices: &[InputDevice], preferred: Option<&str>) -> Option<String> {
    let preferred = preferred?;
    devices.iter().find(|d| d.id == preferred).map(|d| d.id.clone())
}

/// The default microphone first, then by name.
pub fn sort_devices(devices: &mut [InputDevice]) {
    devices.sort_by_key(|d| (!d.is_default, d.name.to_lowercase()));
}

/// The connected microphones, the default first. Empty where capture is unsupported.
pub fn input_devices() -> Vec<InputDevice> {
    #[cfg(windows)]
    let mut devices = windows::input_devices();
    #[cfg(target_os = "macos")]
    let mut devices = macos::input_devices();
    #[cfg(not(any(windows, target_os = "macos")))]
    let mut devices: Vec<InputDevice> = Vec::new();
    sort_devices(&mut devices);
    devices
}

#[cfg(target_os = "macos")]
pub mod macos;
#[cfg(windows)]
pub mod windows;

/// Which streams to open.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct Streams {
    pub mic: bool,
    pub system: bool,
}

pub trait CaptureBackend: Send {
    fn kind(&self) -> BackendKind;
    /// Stops all capture threads and waits for them to exit.
    fn stop(&mut self);
    /// What happened to the devices since this was last asked (Phase 1B).
    fn take_notices(&mut self) -> Vec<crate::audio::devices::DeviceNotice> {
        Vec::new()
    }
}

pub fn start(streams: Streams, tx: Sender<AudioChunk>) -> Result<Box<dyn CaptureBackend>, CaptureError> {
    #[cfg(windows)]
    {
        windows::WindowsBackend::start(streams, tx).map(|b| Box::new(b) as Box<dyn CaptureBackend>)
    }
    #[cfg(target_os = "macos")]
    {
        macos::MacBackend::start(streams, tx).map(|b| Box::new(b) as Box<dyn CaptureBackend>)
    }
    #[cfg(not(any(windows, target_os = "macos")))]
    {
        let _ = (streams, tx);
        Err(CaptureError::Unsupported)
    }
}

/// Pure gate (unit-tested): WASAPI process loopback needs Windows build ≥ 20348 (all Windows 11).
pub const fn supports_process_loopback(build: u32) -> bool {
    build >= 20_348
}

/// What the app says on a Windows that has no per-app capture (Record page and Settings).
pub const WHOLE_SYSTEM_SENTENCE: &str = "This Windows version records all sound playing on this computer, not just the call";

/// Whether system audio here means everything playing on this computer (pure): below build 20348, or when the build
/// could not be read, the backend uses endpoint loopback (`WindowsBackend::start`).
pub const fn records_whole_system(build: Option<u32>) -> bool {
    match build {
        Some(build) => !supports_process_loopback(build),
        None => true,
    }
}

/// The sentence for a Windows build (pure): none where per-app capture works.
pub fn system_audio_note_for(build: Option<u32>) -> Option<&'static str> {
    records_whole_system(build).then_some(WHOLE_SYSTEM_SENTENCE)
}

/// The sentence for this computer: Windows below build 20348, or a recording that fell back to endpoint loopback
/// because process loopback failed. Never on a Mac, whose tap always leaves EchoLedger out.
pub fn system_audio_note() -> Option<&'static str> {
    #[cfg(windows)]
    {
        if windows::fell_back() { Some(WHOLE_SYSTEM_SENTENCE) } else { system_audio_note_for(windows::os_build()) }
    }
    #[cfg(not(windows))]
    {
        None
    }
}
