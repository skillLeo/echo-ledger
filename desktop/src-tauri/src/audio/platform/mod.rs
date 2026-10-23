//! Platform capture backends. Each produces [`AudioChunk`]s (mono f32, 16 kHz, shared clock) for the
//! mic ("Me") and system audio ("Client") on dedicated threads — never on the UI thread.

use std::sync::mpsc::Sender;

use super::types::{AudioChunk, BackendKind, CaptureError};

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
