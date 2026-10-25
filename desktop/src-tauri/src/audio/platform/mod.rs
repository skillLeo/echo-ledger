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
