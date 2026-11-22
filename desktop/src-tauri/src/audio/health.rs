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

/// How soon after a call recording starts (or resumes, or tries the other side again) its system side must deliver.
pub const SYSTEM_CHECK_AFTER_MS: u64 = 3_000;
/// How long the system side may deliver only silence, while another app plays sound, before the neutral warning.
pub const SILENT_WHILE_PLAYING_MS: u64 = FAR_SIDE_SILENT_MS;
/// A sample this small or smaller is digital silence: below half of a 16-bit step.
pub const DIGITAL_SILENCE: f32 = 1.0 / 65_536.0;

/// What the other side of a running call recording (system audio) has done since it last (re)started: the facts the
/// system-side check is made from. Times are milliseconds since that start.
///
/// Two things look alike in the files and must never be taken for each other (2.0.6):
/// - A tap macOS does not let through (System Audio Recording not allowed for this copy, whatever its privacy call
///   says) delivers buffers with no data at all (`mDataByteSize` 0): no frames, `empty_buffers` counting. That is the
///   permission, and is said as one.
/// - A tap that is let through but has nothing to hear (nothing playing, the other person muted or not there yet, you
///   speaking first) delivers frames of exact zeros: `frames` counting, no `signal`. That is a quiet call, never the
///   permission; it is said, in neutral words, only after a long while and only while another app is playing sound.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct SystemSide {
    /// Since the call recording started, resumed or tried the other side again.
    pub running_ms: u64,
    /// Why the system side could not open (the tap, the aggregate device or the IOProc); the microphone records on.
    pub failure: Option<String>,
    /// System audio frames delivered since then (zero-valued ones too).
    pub frames: u64,
    /// IOProc calls since then whose tap buffer had no data.
    pub empty_buffers: u64,
    /// Some frames were more than digital silence: the other side was heard.
    pub signal: bool,
    /// The microphone heard sound since then.
    pub mic_heard: bool,
    /// Whether another app is playing sound now (Core Audio's process list); none when it was not read.
    pub others_playing: Option<bool>,
}

/// Why the other side is not being recorded or heard.
#[derive(Clone, Debug, PartialEq, Eq)]
pub enum SystemProblem {
    /// The system side could not open: the reason. The permission (or the device) is the cause.
    Failed(String),
    /// It opened but its buffers carry no data at all: what macOS does when System Audio Recording is not allowed.
    NoData,
    /// It delivers only silence (zeros) for a long while although another app is playing sound: the call's output or
    /// the device, not the permission.
    Silent,
}

impl SystemProblem {
    /// The reason for the recording's notes, the log and Copy diagnostics.
    pub fn reason(&self) -> String {
        match self {
            SystemProblem::Failed(why) => format!("the system audio tap did not open: {why}"),
            SystemProblem::NoData => format!("the system audio tap delivered buffers with no audio data for {} s", SYSTEM_CHECK_AFTER_MS / 1_000),
            SystemProblem::Silent => format!("the system audio tap delivered only silence for {} s while another app was playing sound", SILENT_WHILE_PLAYING_MS / 1_000),
        }
    }

    /// The permission is the cause: said as System Audio Recording, and counted as missing beyond this recording
    /// (Setup, the Mac check). Silence is never this.
    pub fn names_permission(&self) -> bool {
        !matches!(self, SystemProblem::Silent)
    }
}

/// Whether the other side of a call recording is not being recorded or heard (pure). A tap that failed, at once;
/// buffers with no data and no frames, from [`SYSTEM_CHECK_AFTER_MS`] on (the permission); only zero-valued frames for
/// [`SILENT_WHILE_PLAYING_MS`] while another app is playing sound (neutral). Zero-valued frames alone, however long,
/// are a quiet call: never a problem while nothing else plays.
pub fn system_side_problem(side: &SystemSide) -> Option<SystemProblem> {
    if let Some(why) = &side.failure {
        return Some(SystemProblem::Failed(why.clone()));
    }
    if side.running_ms < SYSTEM_CHECK_AFTER_MS || side.signal {
        return None;
    }
    if side.frames == 0 {
        // No IOProc call at all is the device's matter (the supervisor reopens it and says so), not the permission's.
        return (side.empty_buffers > 0).then_some(SystemProblem::NoData);
    }
    (side.running_ms >= SILENT_WHILE_PLAYING_MS && side.others_playing == Some(true)).then_some(SystemProblem::Silent)
}

/// Whether the check needs to know if another app is playing sound (pure): zero-valued frames only, long enough.
pub fn needs_playing_check(side: &SystemSide) -> bool {
    side.failure.is_none() && side.frames > 0 && !side.signal && side.running_ms >= SILENT_WHILE_PLAYING_MS
}

/// What the running call recording's check said last (`actions::check_system_side`): the problem shown, whether the
/// other side was tried again since, and whether it was found recording.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct SystemWatch {
    pub problem: Option<SystemProblem>,
    /// "Try again" was pressed since the warning first showed.
    pub retried: bool,
    /// The check after that try found it still missing: the warning says to quit and reopen.
    pub after_retry: bool,
    pub confirmed: bool,
}

/// What the check makes of one look (pure): what to keep and what to do.
#[derive(Clone, Debug, PartialEq, Eq)]
pub enum SystemVerdict {
    /// Nothing new.
    Same,
    /// The other side is not being recorded or heard (newly, for another reason, or still after a try).
    Missing(SystemProblem),
    /// The other side is heard (for the first time in this recording, or again): system audio works.
    Recording,
}

impl SystemWatch {
    /// Takes in one look at the system side (pure). A warning stays until the other side is heard: a resume or a try
    /// again starts the counts afresh, and those first seconds (no problem known yet) never take it away.
    pub fn look(&mut self, side: &SystemSide) -> SystemVerdict {
        if side.signal {
            let had = self.problem.take().is_some();
            (self.retried, self.after_retry) = (false, false);
            if had || !self.confirmed {
                self.confirmed = true;
                return SystemVerdict::Recording;
            }
            return SystemVerdict::Same;
        }
        let Some(now) = system_side_problem(side) else { return SystemVerdict::Same };
        let reopen_turn = self.retried && !self.after_retry && now.names_permission();
        if reopen_turn {
            self.after_retry = true;
        }
        if self.problem.as_ref() == Some(&now) && !reopen_turn {
            return SystemVerdict::Same;
        }
        self.problem = Some(now.clone());
        self.confirmed = false;
        SystemVerdict::Missing(now)
    }

    /// "Try again" was pressed: the warning stays as it is until the next look says more.
    pub fn retry(&mut self) {
        self.retried = true;
        self.after_retry = false;
    }

    /// The warning for the running recording: what kind and its words; none while the other side records.
    pub fn warning(&self) -> Option<(crate::permissions::OtherSide, &'static str)> {
        use crate::permissions::{OTHER_SIDE_SILENT, OtherSide, SYSTEM_NOT_RECORDED, SYSTEM_NOT_RECORDED_REOPEN};
        self.problem.as_ref().map(|problem| match problem {
            SystemProblem::Silent => (OtherSide::Silent, OTHER_SIDE_SILENT),
            _ if self.after_retry => (OtherSide::Permission, SYSTEM_NOT_RECORDED_REOPEN),
            _ => (OtherSide::Permission, SYSTEM_NOT_RECORDED),
        })
    }
}

/// Whether a packet is more than digital silence.
pub fn has_signal(samples: &[f32]) -> bool {
    samples.iter().any(|s| s.abs() > DIGITAL_SILENCE)
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
