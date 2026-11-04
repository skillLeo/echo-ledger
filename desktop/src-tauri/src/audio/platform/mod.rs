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

/// Whether a microphone is Bluetooth (pure): what the system says about its bus wins, so a wired "Headset Microphone"
/// on the laptop's audio jack is not one; when the system says nothing the name decides ("Headset (WH-1000XM4
/// Hands-Free AG Audio)", "AirPods Pro", "Galaxy Buds2 Pro").
pub fn is_bluetooth_mic(bus: MicBus, name: &str) -> bool {
    match bus {
        MicBus::Bluetooth => true,
        MicBus::Other => false,
        MicBus::Unknown => {
            let name = name.to_lowercase();
            ["hands-free", "handsfree", "bluetooth", "airpods", "buds", "headset"].iter().any(|word| name.contains(word))
        }
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
