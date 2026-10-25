//! Capture health (B5): the "not hearing the other side" warning, the 10-second test recording and the
//! microphone permission state. The decisions are pure; only `run_test` and `microphone_permission` touch devices.

use std::io::Cursor;
use std::sync::mpsc::{RecvTimeoutError, channel};
use std::time::{Duration, Instant};

use base64::Engine;
use serde::Serialize;

use super::platform::{self, Streams};
use super::types::{Channel, TARGET_RATE};

/// The other side counts as silent after this long without system audio above [`HEARD_LEVEL`].
pub const FAR_SIDE_SILENT_MS: u64 = 30_000;
/// RMS (0-1) above which a packet counts as heard (about -54 dBFS).
pub const HEARD_LEVEL: f32 = 0.002;
pub const TEST_SECONDS: u32 = 10;

/// `capture:far_side`: the call recording has not heard the other side for 30 s, or hears it again.
#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct FarSidePayload {
    pub silent: bool,
    pub seconds: u64,
}

/// Turns "milliseconds since the other side was last heard" into one warning per silent stretch and one
/// all-clear when it is heard again (or the recording pauses or ends).
#[derive(Clone, Debug, Default)]
pub struct FarSideMonitor {
    warned: bool,
}

impl FarSideMonitor {
    /// `silent_ms` is `None` while no call is recording or it is paused.
    pub fn tick(&mut self, silent_ms: Option<u64>, enabled: bool) -> Option<FarSidePayload> {
        match silent_ms {
            Some(ms) if enabled && ms >= FAR_SIDE_SILENT_MS && !self.warned => {
                self.warned = true;
                Some(FarSidePayload { silent: true, seconds: ms / 1_000 })
            }
            Some(ms) if self.warned && (ms < FAR_SIDE_SILENT_MS || !enabled) => {
                self.warned = false;
                Some(FarSidePayload { silent: false, seconds: 0 })
            }
            None if self.warned => {
                self.warned = false;
                Some(FarSidePayload { silent: false, seconds: 0 })
            }
            _ => None,
        }
    }
}

/// Root-mean-square of a packet (0 for an empty one).
pub fn rms(samples: &[f32]) -> f32 {
    if samples.is_empty() {
        return 0.0;
    }
    (samples.iter().map(|s| s * s).sum::<f32>() / samples.len() as f32).sqrt()
}

/// Result of the test recording: what each side reached and the recording to play back.
#[derive(Clone, Debug, PartialEq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct TestResult {
    pub seconds: u32,
    /// Loudest 100 ms (RMS, 0-1) per side.
    pub mic_peak: f32,
    pub system_peak: f32,
    pub mic_heard: bool,
    pub system_heard: bool,
    /// Stereo 16 kHz WAV (left = microphone, right = this computer's sound), base64.
    pub wav_base64: String,
}

/// The loudest 100 ms window of a 16 kHz stream.
pub fn peak_level(samples: &[f32]) -> f32 {
    samples.chunks((TARGET_RATE / 10) as usize).map(rms).fold(0.0, f32::max)
}

/// Builds the test result from both streams; the shorter one is padded with silence.
pub fn summarize(seconds: u32, mic: &[f32], system: &[f32]) -> Result<TestResult, String> {
    let (mic_peak, system_peak) = (peak_level(mic), peak_level(system));
    let wav = stereo_wav(mic, system)?;
    Ok(TestResult {
        seconds,
        mic_peak,
        system_peak,
        mic_heard: mic_peak >= HEARD_LEVEL,
        system_heard: system_peak >= HEARD_LEVEL,
        wav_base64: base64::engine::general_purpose::STANDARD.encode(wav),
    })
}

/// 16-bit stereo WAV bytes at 16 kHz.
pub fn stereo_wav(left: &[f32], right: &[f32]) -> Result<Vec<u8>, String> {
    let spec = hound::WavSpec { channels: 2, sample_rate: TARGET_RATE, bits_per_sample: 16, sample_format: hound::SampleFormat::Int };
    let mut cursor = Cursor::new(Vec::new());
    {
        let mut writer = hound::WavWriter::new(&mut cursor, spec).map_err(|e| e.to_string())?;
        for i in 0..left.len().max(right.len()) {
            for side in [left, right] {
                writer.write_sample(super::dsp::f32_to_i16(side.get(i).copied().unwrap_or(0.0))).map_err(|e| e.to_string())?;
            }
        }
        writer.finalize().map_err(|e| e.to_string())?;
    }
    Ok(cursor.into_inner())
}

/// Records both sides for `seconds` (blocking; run off the async runtime) and reports the level of each side
/// every 100 ms through `on_level` ([microphone, this computer]).
pub fn run_test(seconds: u32, on_level: impl Fn([f32; 2])) -> Result<TestResult, String> {
    let (tx, rx) = channel();
    let mut backend = platform::start(Streams { mic: true, system: true }, tx).map_err(|e| e.to_string())?;
    let started = Instant::now();
    let until = started + Duration::from_secs(u64::from(seconds));
    let (mut mic, mut system) = (Vec::new(), Vec::new());
    let mut levels = [0.0_f32; 2];
    let mut last_report = started;
    while Instant::now() < until {
        match rx.recv_timeout(Duration::from_millis(50)) {
            Ok(chunk) => {
                let level = rms(&chunk.samples);
                match chunk.channel {
                    Channel::Mic => {
                        levels[0] = levels[0].max(level);
                        mic.extend_from_slice(&chunk.samples);
                    }
                    Channel::System => {
                        levels[1] = levels[1].max(level);
                        system.extend_from_slice(&chunk.samples);
                    }
                }
            }
            Err(RecvTimeoutError::Timeout) => {}
            Err(RecvTimeoutError::Disconnected) => break,
        }
        if last_report.elapsed() >= Duration::from_millis(100) {
            on_level(levels);
            levels = [0.0, 0.0];
            last_report = Instant::now();
        }
    }
    backend.stop();
    on_level([0.0, 0.0]);
    summarize(seconds, &mic, &system)
}

/// Whether desktop apps may use the microphone: `allowed`, `denied` or `unknown` (not readable here; macOS asks
/// on first use).
pub fn microphone_permission() -> &'static str {
    #[cfg(windows)]
    {
        windows_microphone_consent()
    }
    #[cfg(not(windows))]
    {
        "unknown"
    }
}

/// The Windows privacy switches (Settings → Privacy → Microphone): the device-wide one, the per-user one and
/// "Let desktop apps access your microphone". Any `Deny` blocks capture.
#[cfg(windows)]
fn windows_microphone_consent() -> &'static str {
    use windows::Win32::System::Registry::{HKEY, HKEY_CURRENT_USER, HKEY_LOCAL_MACHINE, RRF_RT_REG_SZ, RegGetValueW};
    use windows::core::{HSTRING, PCWSTR};

    const BASE: &str = r"Software\Microsoft\Windows\CurrentVersion\CapabilityAccessManager\ConsentStore\microphone";
    let read = |root: HKEY, key: &str| -> Option<String> {
        let mut buf = [0u16; 32];
        let mut size = (buf.len() * 2) as u32;
        let status = unsafe {
            RegGetValueW(root, &HSTRING::from(key), PCWSTR(HSTRING::from("Value").as_ptr()), RRF_RT_REG_SZ, None, Some(buf.as_mut_ptr().cast()), Some(&mut size))
        };
        status.is_ok().then(|| String::from_utf16_lossy(&buf[..(size as usize / 2).saturating_sub(1)]))
    };
    let values = [read(HKEY_LOCAL_MACHINE, BASE), read(HKEY_CURRENT_USER, BASE), read(HKEY_CURRENT_USER, &format!(r"{BASE}\NonPackaged"))];
    consent_from(&values)
}

/// The combined state of the consent values (pure): any `Deny` → denied; otherwise allowed when any was read.
pub fn consent_from(values: &[Option<String>]) -> &'static str {
    if values.iter().flatten().any(|v| v.eq_ignore_ascii_case("deny")) {
        "denied"
    } else if values.iter().flatten().any(|v| v.eq_ignore_ascii_case("allow")) {
        "allowed"
    } else {
        "unknown"
    }
}
