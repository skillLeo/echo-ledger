//! Shared audio types. Everything downstream of the platform backends is mono f32 at [`TARGET_RATE`].

use serde::{Deserialize, Serialize};

/// All capture is normalized to 16 kHz mono per channel (what every STT provider uses internally).
pub const TARGET_RATE: u32 = 16_000;

/// Channel 1 (left) = "Me" (local mic); channel 2 (right) = "Client" (system / far party).
#[derive(Clone, Copy, Debug, PartialEq, Eq, Hash, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum Channel {
    Mic,
    System,
}

impl Channel {
    pub fn index(self) -> usize {
        match self {
            Channel::Mic => 0,
            Channel::System => 1,
        }
    }

    pub fn speaker(self) -> &'static str {
        match self {
            Channel::Mic => "Me",
            Channel::System => "Client",
        }
    }

    /// Name used by the server's streaming grants (`grants[].channel`).
    pub fn grant_name(self) -> &'static str {
        match self {
            Channel::Mic => "me",
            Channel::System => "client",
        }
    }
}

/// A packet from a backend: mono samples at [`TARGET_RATE`], with the device timestamp of its first frame.
#[derive(Clone, Debug)]
pub struct AudioChunk {
    pub channel: Channel,
    pub samples: Vec<f32>,
    /// Nanoseconds on the backend's shared clock (QPC on Windows, host time on macOS). `None` = unknown.
    pub ts_ns: Option<u64>,
    /// Device reported a discontinuity (re-anchor from `ts_ns`).
    pub discontinuity: bool,
}

#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum BackendKind {
    /// Windows 11+: WASAPI process loopback excluding EchoLedger's own process tree.
    ProcessLoopback,
    /// Windows 10 (< build 20348): endpoint loopback of the default render device (captures all system audio).
    EndpointLoopback,
    /// macOS 14.4+: Core Audio process tap + aggregate device with the default input.
    CoreAudioTap,
    /// Deterministic synthetic source (tests, demos).
    Mock,
}

#[derive(Debug, thiserror::Error)]
pub enum CaptureError {
    #[error("audio device error: {0}")]
    Device(String),
    #[error("audio capture is not supported on this platform")]
    Unsupported,
    #[error("permission denied for {0}")]
    Permission(String),
}
