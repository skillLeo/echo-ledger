//! Settings → Help & diagnostics → Run Mac check (Phase 1B): one check per thing a Mac can get wrong, run one at a time,
//! each ending as ok, limited, off or failed with a plain sentence and, where System Settings has a pane for it, a button
//! there. Everything that touches the system is behind [`MacProbe`], so tests run on fakes; `native` is the real one.
//!
//! The report holds no names, no paths with a user name and no account (`scrub`). It goes anywhere only when the person
//! presses Copy report or Send to my admin (`POST /api/devices/report`).

use std::sync::mpsc::{Receiver, RecvTimeoutError};
use std::time::{Duration, Instant};

use chrono::{DateTime, Utc};
use serde::Serialize;

use crate::audio::health::HEARD_LEVEL;
use crate::audio::types::{AudioChunk, Channel, TARGET_RATE};
use crate::detect::classify::{classify_macos, is_browser_bundle};
use crate::permissions::{Access, Kind, Permissions};

#[cfg(target_os = "macos")]
pub mod native;

/// Call audio needs the Core Audio process tap of macOS 14.2 and later.
pub const CALL_AUDIO_FROM: (u32, u32) = (14, 2);
pub const MICROPHONE_TEST_SECONDS: u32 = 5;
pub const TONE_TEST_SECONDS: u32 = 5;
/// The soft test tone: A5 at about -26 dBFS, played for a little less than the test listens.
pub const TONE_HZ: f32 = 880.0;
pub const TONE_LEVEL: f32 = 0.05;
pub const TONE_SECONDS: f32 = 3.0;
/// The server's limit for `detail` (docs/API.md).
pub const DETAIL_MAX: usize = 300;

#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum CheckState {
    Ok,
    Limited,
    Off,
    Failed,
}

impl CheckState {
    fn word(self) -> &'static str {
        match self {
            CheckState::Ok => "ok",
            CheckState::Limited => "limited",
            CheckState::Off => "off",
            CheckState::Failed => "failed",
        }
    }
}

/// A button: what it says and the System Settings pane it opens (`privacy_settings_url`).
#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct CheckFix {
    pub label: &'static str,
    pub settings: &'static str,
}

#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct CheckItem {
    pub key: &'static str,
    pub title: &'static str,
    pub state: CheckState,
    pub detail: String,
    pub fix: Option<CheckFix>,
}

/// The progress line while the check runs.
#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct CheckProgress {
    pub index: usize,
    pub total: usize,
    pub line: &'static str,
    /// The checks that finished so far.
    pub done: Vec<CheckItem>,
}

#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct CheckReport {
    pub items: Vec<CheckItem>,
    /// Why the check stopped early (a recording started).
    pub stopped: Option<String>,
    pub finished_at: DateTime<Utc>,
    pub app_version: String,
    pub os_version: String,
    pub model: String,
    /// When "Send to my admin" last sent this report.
    pub sent_at: Option<String>,
}

/// An EchoLedger window as the system sees it.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct WindowFacts {
    pub label: &'static str,
    pub exists: bool,
    pub visible: bool,
    pub on_screen: bool,
    pub level_ok: bool,
    pub spaces_ok: bool,
}

/// Why a screen could not be captured.
#[derive(Clone, Debug, PartialEq, Eq)]
pub enum FrameProblem {
    Permission,
    Other(String),
}

/// Everything the check asks of the system. The tests answer with fakes, so they never reach a real microphone, tap,
/// window or capture.
pub trait MacProbe {
    /// (major, minor, patch).
    fn os_version(&self) -> Option<(u32, u32, u32)>;
    fn permissions(&self) -> Permissions;
    /// Listens to the microphone and returns the loudest 100 ms (RMS, 0-1). `stop` ends it early (a recording started).
    fn listen_to_microphone(&self, seconds: u32, stop: &dyn Fn() -> bool) -> Result<f32, String>;
    /// Runs the system-audio tap while the soft tone plays and returns what the tap captured (16 kHz mono). `stop` ends it
    /// and the tone early: a recording that starts now must not get the tone in it.
    fn tap_while_tone_plays(&self, seconds: u32, stop: &dyn Fn() -> bool) -> Result<Vec<f32>, String>;
    fn is_installed(&self, bundle_id: &str) -> bool;
    /// Opens the captions and the pill (their content stays hidden) and says how they stand.
    fn overlay_windows(&self) -> Vec<WindowFacts>;
    /// Takes one frame of the screen and throws it away.
    fn capture_one_frame(&self) -> Result<(), FrameProblem>;
}

const OPEN_SETTINGS: &str = "Open System Settings";
const SCREEN_NOT_ASKED: &str = "macOS has not asked yet: turn on screen moments in Settings → Screen moments and press Allow Screen Recording.";

fn fix(settings: &'static str) -> Option<CheckFix> {
    Some(CheckFix { label: OPEN_SETTINGS, settings })
}

/// The meeting apps a person is most likely to use, with the bundle ids the detector knows them by.
pub const MEETING_APPS: [(&str, &str); 7] = [
    ("Zoom", "us.zoom.xos"),
    ("Teams", "com.microsoft.teams2"),
    ("WhatsApp", "net.whatsapp.WhatsApp"),
    ("Chrome", "com.google.Chrome"),
    ("Safari", "com.apple.Safari"),
    ("Edge", "com.microsoft.edgemac"),
    ("Arc", "company.thebrowser.Browser"),
];

/// The auto-mode detector knows this app: as a call app, or as a browser whose tab it can read.
pub fn known_to_detector(bundle_id: &str) -> bool {
    classify_macos(bundle_id).is_some() || is_browser_bundle(bundle_id)
}

fn call_audio_supported(version: (u32, u32, u32)) -> bool {
    (version.0, version.1) >= CALL_AUDIO_FROM
}

fn version_text(version: (u32, u32, u32)) -> String {
    if version.2 == 0 { format!("{}.{}", version.0, version.1) } else { format!("{}.{}.{}", version.0, version.1, version.2) }
}

fn item(key: &'static str, title: &'static str, state: CheckState, detail: impl Into<String>, fix: Option<CheckFix>) -> CheckItem {
    CheckItem { key, title, state, detail: scrub(&detail.into()), fix }
}

pub fn check_version(version: Option<(u32, u32, u32)>) -> CheckItem {
    const KEY: &str = "macos_version";
    const TITLE: &str = "macOS version";
    match version {
        None => item(KEY, TITLE, CheckState::Limited, "macOS did not say which version this is.", None),
        Some(v) if call_audio_supported(v) => item(KEY, TITLE, CheckState::Ok, format!("macOS {}: call audio works on this version.", version_text(v)), None),
        Some(v) => item(
            KEY,
            TITLE,
            CheckState::Limited,
            format!("macOS {}: recording calls needs macOS 14.2 or newer. Your library, transcripts, sharing and file imports still work here.", version_text(v)),
            fix("software_update"),
        ),
    }
}

fn check_permission(kind: Kind, access: Access, version: Option<(u32, u32, u32)>) -> CheckItem {
    let (key, title, settings, what_for): (&'static str, &'static str, &'static str, &str) = match kind {
        Kind::Microphone => ("microphone_permission", "Microphone permission", "microphone", "Without it a recording has the other side of the call, not your voice."),
        Kind::SystemAudio => ("system_audio_permission", "System audio permission", "system_audio", "Without it a recording has your voice, not the other side of the call."),
        Kind::Screen => ("screen_permission", "Screen Recording permission", "screen", "Only screen moments need it; recordings keep their sound without it."),
    };
    match access {
        Access::Allowed if kind == Kind::Screen && version.is_some_and(|v| v.0 >= 15) => {
            item(key, title, CheckState::Ok, "Allowed. macOS asks again about once a month: choose Allow to keep screen moments.", None)
        }
        Access::Allowed => item(key, title, CheckState::Ok, "Allowed.", None),
        Access::Denied => item(key, title, CheckState::Off, format!("Off. {what_for}"), fix(settings)),
        // Recording never asks for this one, and System Settings does not list EchoLedger until it has asked.
        Access::Unknown if kind == Kind::Screen => item(key, title, CheckState::Limited, SCREEN_NOT_ASKED, None),
        Access::Unknown => item(key, title, CheckState::Limited, "macOS has not asked yet. It asks the first time you record.", fix(settings)),
    }
}

pub fn check_microphone_test(access: Access, heard: impl FnOnce() -> Result<f32, String>) -> CheckItem {
    const KEY: &str = "microphone_test";
    const TITLE: &str = "Microphone test";
    if access == Access::Denied {
        return item(KEY, TITLE, CheckState::Off, "Not tested: the microphone is off for EchoLedger.", fix("microphone"));
    }
    match heard() {
        Ok(peak) if peak >= HEARD_LEVEL => item(KEY, TITLE, CheckState::Ok, format!("Heard your microphone (loudest about {:.0} dB).", 20.0 * peak.log10()), None),
        Ok(_) => item(KEY, TITLE, CheckState::Limited, format!("Heard nothing in {MICROPHONE_TEST_SECONDS} seconds. Speak during the test, and check the input device in Sound."), fix("sound")),
        Err(e) => item(KEY, TITLE, CheckState::Failed, format!("The microphone could not be opened: {e}"), fix("microphone")),
    }
}

/// How loud `hz` is in a block of samples (amplitude 0-1, the Goertzel filter).
fn tone_amplitude(samples: &[f32], hz: f32, rate: f32) -> f32 {
    if samples.is_empty() {
        return 0.0;
    }
    let omega = 2.0 * std::f32::consts::PI * hz / rate;
    let coefficient = 2.0 * omega.cos();
    let (mut previous, mut before) = (0.0_f32, 0.0_f32);
    for &sample in samples {
        let value = sample + coefficient * previous - before;
        before = previous;
        previous = value;
    }
    let power = previous * previous + before * before - coefficient * previous * before;
    2.0 * power.max(0.0).sqrt() / samples.len() as f32
}

/// Blocks of about 64 ms the tone must show in (of about 3 s played): some of it was captured, not a chance peak.
const TONE_BLOCKS_NEEDED: usize = 8;
/// The tone's amplitude that counts, well under what is played: the tap sits before the output volume.
const TONE_HEARD: f32 = 0.004;

/// The test tone is in what the tap captured (pure): other sound playing at the time does not count, and does not
/// hide it.
pub fn tone_heard(samples: &[f32]) -> bool {
    samples.chunks(1024).filter(|block| tone_amplitude(block, TONE_HZ, TARGET_RATE as f32) >= TONE_HEARD).count() >= TONE_BLOCKS_NEEDED
}

pub fn check_tone_test(version: Option<(u32, u32, u32)>, access: Access, captured: impl FnOnce() -> Result<Vec<f32>, String>) -> CheckItem {
    const KEY: &str = "system_audio_test";
    const TITLE: &str = "System audio test";
    if version.is_some_and(|v| !call_audio_supported(v)) {
        return item(KEY, TITLE, CheckState::Off, "Not tested: this macOS has no system-audio tap (it needs 14.2 or newer).", None);
    }
    if access == Access::Denied {
        return item(KEY, TITLE, CheckState::Off, "Not tested: system audio is off for EchoLedger.", fix("system_audio"));
    }
    match captured() {
        Ok(samples) if tone_heard(&samples) => item(KEY, TITLE, CheckState::Ok, "The tap captured the test tone.", None),
        Ok(_) if access == Access::Unknown => item(KEY, TITLE, CheckState::Limited, "The tap ran but captured no tone. macOS may not have been asked yet: try again after your first recording.", fix("system_audio")),
        Ok(_) => item(KEY, TITLE, CheckState::Failed, "The tap ran but did not capture the test tone, so the other side of a call may be missing.", fix("system_audio")),
        Err(e) => item(KEY, TITLE, CheckState::Failed, format!("The tap could not start: {e}"), fix("system_audio")),
    }
}

pub fn check_meeting_apps(installed: impl Fn(&str) -> bool) -> CheckItem {
    const KEY: &str = "meeting_apps";
    const TITLE: &str = "Meeting apps";
    let (mut here, mut not_here, mut unknown) = (Vec::new(), Vec::new(), Vec::new());
    for (name, bundle) in MEETING_APPS {
        if !known_to_detector(bundle) {
            unknown.push(name);
        }
        if installed(bundle) { here.push(name) } else { not_here.push(name) }
    }
    let list = |names: &[&str]| if names.is_empty() { "none".to_string() } else { names.join(", ") };
    if !unknown.is_empty() {
        return item(KEY, TITLE, CheckState::Failed, format!("EchoLedger does not recognise {}.", list(&unknown)), None);
    }
    item(KEY, TITLE, CheckState::Ok, format!("Installed: {}. Not installed: {}. EchoLedger recognises all of them.", list(&here), list(&not_here)), None)
}

pub fn check_overlay_windows(windows: &[WindowFacts]) -> CheckItem {
    const KEY: &str = "overlay_windows";
    const TITLE: &str = "Captions and pill on screen";
    let name = |label: &str| if label == crate::overlay::PILL { "The recording pill" } else { "The captions" };
    if windows.is_empty() {
        return item(KEY, TITLE, CheckState::Failed, "The captions and the pill could not be opened.", None);
    }
    for window in windows {
        let who = name(window.label);
        let problem = if !window.exists {
            Some(format!("{who} could not be opened."))
        } else if !window.visible {
            Some(format!("{who} did not show."))
        } else if !window.on_screen {
            Some(format!("{who} is off screen."))
        } else if !window.level_ok {
            Some(format!("{who} is not above other windows."))
        } else if !window.spaces_ok {
            Some(format!("{who} does not follow you across Spaces and over full-screen windows."))
        } else {
            None
        };
        if let Some(problem) = problem {
            return item(KEY, TITLE, CheckState::Failed, problem, None);
        }
    }
    item(KEY, TITLE, CheckState::Ok, "The captions and the pill are on screen, above other windows, in every Space and over full-screen meetings.", None)
}

pub fn check_screen_capture(access: Access, frame: impl FnOnce() -> Result<(), FrameProblem>) -> CheckItem {
    const KEY: &str = "screen_capture_test";
    const TITLE: &str = "Screen moment test";
    if access == Access::Denied {
        return item(KEY, TITLE, CheckState::Off, "Not tested: Screen Recording is off for EchoLedger. Recordings keep their sound without screen moments.", fix("screen"));
    }
    match frame() {
        Ok(()) => item(KEY, TITLE, CheckState::Ok, "One frame captured and thrown away. Nothing was stored.", None),
        Err(FrameProblem::Permission) if access == Access::Unknown => item(KEY, TITLE, CheckState::Limited, format!("Not tested. {SCREEN_NOT_ASKED}"), None),
        Err(FrameProblem::Permission) => item(KEY, TITLE, CheckState::Off, "Screen Recording is off for EchoLedger. Recordings keep their sound without screen moments.", fix("screen")),
        Err(FrameProblem::Other(e)) => item(KEY, TITLE, CheckState::Failed, format!("No frame came back: {e}"), fix("screen")),
    }
}

/// The checks in the order they run, each with its progress line.
pub const STEPS: [(&str, &str); 9] = [
    ("macos_version", "Reading the macOS version…"),
    ("microphone_permission", "Reading the microphone permission…"),
    ("system_audio_permission", "Reading the system audio permission…"),
    ("screen_permission", "Reading the Screen Recording permission…"),
    ("microphone_test", "Listening to the microphone for 5 seconds: say something…"),
    ("system_audio_test", "Playing a soft tone for the system-audio tap, 5 seconds…"),
    ("meeting_apps", "Looking for meeting apps…"),
    ("overlay_windows", "Opening the captions and the pill…"),
    ("screen_capture_test", "Capturing one frame of the screen…"),
];

/// Runs every check, one at a time. `on_step` is told before each; `stop_reason` is asked before each and all through the
/// two listening tests, and a reason ends the check there (a recording or a dictation started). A test cut short proves
/// nothing, so it leaves no result.
pub fn run_all(probe: &dyn MacProbe, mut on_step: impl FnMut(CheckProgress), stop_reason: impl Fn() -> Option<String>) -> (Vec<CheckItem>, Option<String>) {
    let mut items: Vec<CheckItem> = Vec::new();
    let version = probe.os_version();
    let stop = || stop_reason().is_some();
    for (index, (key, line)) in STEPS.into_iter().enumerate() {
        if let Some(reason) = stop_reason() {
            return (items, Some(reason));
        }
        on_step(CheckProgress { index, total: STEPS.len(), line, done: items.clone() });
        let permissions = probe.permissions();
        let result = match key {
            "macos_version" => check_version(version),
            "microphone_permission" => check_permission(Kind::Microphone, permissions.microphone, version),
            "system_audio_permission" => check_permission(Kind::SystemAudio, permissions.system_audio, version),
            "screen_permission" => check_permission(Kind::Screen, permissions.screen, version),
            "microphone_test" => check_microphone_test(permissions.microphone, || probe.listen_to_microphone(MICROPHONE_TEST_SECONDS, &stop)),
            "system_audio_test" => check_tone_test(version, permissions.system_audio, || probe.tap_while_tone_plays(TONE_TEST_SECONDS, &stop)),
            "meeting_apps" => check_meeting_apps(|bundle| probe.is_installed(bundle)),
            "overlay_windows" => check_overlay_windows(&probe.overlay_windows()),
            _ => check_screen_capture(permissions.screen, || probe.capture_one_frame()),
        };
        if let Some(reason) = stop_reason() {
            return (items, Some(reason));
        }
        items.push(result);
    }
    (items, None)
}

/// What a capture delivers for `wanted` until the time is up or `stop` says so, which it looks at every 50 ms at most.
pub fn collect_samples(rx: &Receiver<AudioChunk>, wanted: Channel, time: Duration, stop: &dyn Fn() -> bool) -> Vec<f32> {
    let until = Instant::now() + time;
    let mut samples = Vec::new();
    while Instant::now() < until && !stop() {
        match rx.recv_timeout(Duration::from_millis(50)) {
            Ok(chunk) if chunk.channel == wanted => samples.extend_from_slice(&chunk.samples),
            Ok(_) | Err(RecvTimeoutError::Timeout) => {}
            Err(RecvTimeoutError::Disconnected) => break,
        }
    }
    samples
}

/// Why a running check stops; none while nothing else needs the sound.
pub fn interrupted(recording: bool, dictating: bool) -> Option<&'static str> {
    if recording {
        Some("A recording started, so the check stopped.")
    } else if dictating {
        Some("Dictation started, so the check stopped.")
    } else {
        None
    }
}

/// Why the check does not run now; none when it may.
pub fn refusal(recording: bool, dictating: bool, test_running: bool) -> Option<&'static str> {
    if recording || dictating {
        Some("Finish the current recording to run the Mac check.")
    } else if test_running {
        Some("A test is already running.")
    } else {
        None
    }
}

/// One line that names nobody: a home folder in an error message loses its user name, and the text fits the server's
/// limit.
pub fn scrub(text: &str) -> String {
    let mut out = String::with_capacity(text.len());
    let mut rest = text;
    while let Some(at) = ["/Users/", "/home/"].iter().filter_map(|home| rest.find(home)).min() {
        let (before, after) = rest.split_at(at);
        out.push_str(before);
        out.push('~');
        let name_starts = after[1..].find('/').map_or(1, |i| i + 2);
        let tail = &after[name_starts..];
        let name_ends = tail.find(|c: char| c == '/' || c.is_whitespace() || c == '"' || c == '\'').unwrap_or(tail.len());
        rest = &tail[name_ends..];
    }
    out.push_str(rest);
    let flat: String = out.split_whitespace().collect::<Vec<_>>().join(" ");
    flat.chars().take(DETAIL_MAX).collect()
}

/// `Copy report`: plain text, with nothing that names a person or an account.
pub fn report_text(report: &CheckReport) -> String {
    let mut text = format!(
        "EchoLedger Mac check\nApp {} · macOS {} · {}\n{}\n",
        report.app_version,
        if report.os_version.is_empty() { "unknown" } else { &report.os_version },
        if report.model.is_empty() { "unknown model" } else { &report.model },
        report.finished_at.format("%Y-%m-%d %H:%M UTC")
    );
    for item in &report.items {
        let mark = if item.state == CheckState::Ok { "✓" } else { "✗" };
        text.push_str(&format!("\n{mark} {} ({}): {}", item.title, item.state.word(), item.detail));
    }
    if let Some(reason) = &report.stopped {
        text.push_str(&format!("\n\nStopped early: {reason}"));
    }
    text.push('\n');
    text
}

/// A soft tone as a WAV file: `TONE_HZ` at `TONE_LEVEL` with short fades, 16-bit mono, for the system's own player (a
/// separate process, so the tap that leaves EchoLedger out still hears it).
pub fn tone_wav() -> Result<Vec<u8>, String> {
    let rate = 44_100_u32;
    let spec = hound::WavSpec { channels: 1, sample_rate: rate, bits_per_sample: 16, sample_format: hound::SampleFormat::Int };
    let mut cursor = std::io::Cursor::new(Vec::new());
    {
        let mut writer = hound::WavWriter::new(&mut cursor, spec).map_err(|e| e.to_string())?;
        let total = (TONE_SECONDS * rate as f32) as u32;
        let fade = rate / 50;
        for i in 0..total {
            let edge = (i.min(total - i - 1) as f32 / fade as f32).min(1.0);
            let sample = (2.0 * std::f32::consts::PI * TONE_HZ * i as f32 / rate as f32).sin() * TONE_LEVEL * edge;
            writer.write_sample(crate::audio::dsp::f32_to_i16(sample)).map_err(|e| e.to_string())?;
        }
        writer.finalize().map_err(|e| e.to_string())?;
    }
    Ok(cursor.into_inner())
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::cell::{Cell, RefCell};

    const ON: Permissions = Permissions { microphone: Access::Allowed, system_audio: Access::Allowed, screen: Access::Allowed };

    fn sine(hz: f32, amplitude: f32, seconds: f32) -> Vec<f32> {
        (0..(seconds * TARGET_RATE as f32) as usize).map(|i| (2.0 * std::f32::consts::PI * hz * i as f32 / TARGET_RATE as f32).sin() * amplitude).collect()
    }

    fn good_windows() -> Vec<WindowFacts> {
        ["overlay", "pill"].map(|label| WindowFacts { label, exists: true, visible: true, on_screen: true, level_ok: true, spaces_ok: true }).to_vec()
    }

    /// A Mac on which everything works; each test changes what it is about. It records which tests really ran.
    struct FakeMac {
        version: Option<(u32, u32, u32)>,
        permissions: Permissions,
        peak: Result<f32, String>,
        tapped: Result<Vec<f32>, String>,
        windows: Vec<WindowFacts>,
        frame: Result<(), FrameProblem>,
        ran: RefCell<Vec<&'static str>>,
        /// A recording starts during this test.
        recording_starts_in: Option<&'static str>,
        recording: Cell<bool>,
        /// What `stop` said once the recording had started.
        stop_saw_it: Cell<bool>,
    }

    impl FakeMac {
        /// Runs the listening test `name`: the recording starts in it if this test is about that.
        fn listening(&self, name: &'static str, stop: &dyn Fn() -> bool) {
            self.ran.borrow_mut().push(name);
            assert!(!stop(), "nothing has started yet");
            if self.recording_starts_in == Some(name) {
                self.recording.set(true);
                self.stop_saw_it.set(stop());
            }
        }

        fn stop_reason(&self) -> Option<String> {
            interrupted(self.recording.get(), false).map(str::to_string)
        }
    }

    impl Default for FakeMac {
        fn default() -> Self {
            Self {
                version: Some((15, 1, 0)),
                permissions: ON,
                peak: Ok(0.1),
                tapped: Ok(sine(TONE_HZ, TONE_LEVEL, 3.0)),
                windows: good_windows(),
                frame: Ok(()),
                ran: RefCell::default(),
                recording_starts_in: None,
                recording: Cell::new(false),
                stop_saw_it: Cell::new(false),
            }
        }
    }

    impl MacProbe for FakeMac {
        fn os_version(&self) -> Option<(u32, u32, u32)> {
            self.version
        }
        fn permissions(&self) -> Permissions {
            self.permissions
        }
        fn listen_to_microphone(&self, seconds: u32, stop: &dyn Fn() -> bool) -> Result<f32, String> {
            assert_eq!(seconds, 5);
            self.listening("microphone", stop);
            self.peak.clone()
        }
        fn tap_while_tone_plays(&self, seconds: u32, stop: &dyn Fn() -> bool) -> Result<Vec<f32>, String> {
            assert_eq!(seconds, 5);
            self.listening("tap", stop);
            self.tapped.clone()
        }
        fn is_installed(&self, bundle_id: &str) -> bool {
            matches!(bundle_id, "us.zoom.xos" | "com.apple.Safari")
        }
        fn overlay_windows(&self) -> Vec<WindowFacts> {
            self.windows.clone()
        }
        fn capture_one_frame(&self) -> Result<(), FrameProblem> {
            self.ran.borrow_mut().push("frame");
            self.frame.clone()
        }
    }

    fn run(mac: &FakeMac) -> Vec<CheckItem> {
        run_all(mac, |_| {}, || None).0
    }

    fn find<'a>(items: &'a [CheckItem], key: &str) -> &'a CheckItem {
        items.iter().find(|i| i.key == key).unwrap_or_else(|| panic!("no {key}"))
    }

    #[test]
    fn a_healthy_mac_passes_every_check_in_order() {
        let items = run(&FakeMac::default());
        assert_eq!(items.iter().map(|i| i.key).collect::<Vec<_>>(), STEPS.map(|(k, _)| k));
        assert!(items.iter().all(|i| i.state == CheckState::Ok), "{items:#?}");
        assert!(items.iter().all(|i| i.fix.is_none()));
    }

    #[test]
    fn the_progress_line_comes_before_each_check_with_what_is_done_so_far() {
        let mut lines = Vec::new();
        let (items, stopped) = run_all(&FakeMac::default(), |p| lines.push((p.index, p.line, p.done.len(), p.total)), || None);
        assert_eq!(lines.len(), items.len());
        assert_eq!(lines[0], (0, STEPS[0].1, 0, STEPS.len()));
        assert_eq!(lines[4].1, "Listening to the microphone for 5 seconds: say something…");
        assert_eq!(lines[8].2, 8);
        assert_eq!(stopped, None);
    }

    #[test]
    fn it_stops_when_a_recording_starts_and_says_so() {
        let mac = FakeMac::default();
        let (items, stopped) = run_all(
            &mac,
            |p| {
                if p.index == 3 {
                    mac.recording.set(true); // while the fourth reading runs
                }
            },
            || mac.stop_reason(),
        );
        assert_eq!(items.len(), 3, "the checks already done are kept, the one it cut short leaves nothing");
        assert_eq!(stopped.as_deref(), Some("A recording started, so the check stopped."));
        assert!(mac.ran.borrow().is_empty(), "no test ran after it");
    }

    #[test]
    fn a_recording_that_starts_during_the_tone_ends_the_test_at_once_and_it_claims_nothing() {
        let mac = FakeMac { recording_starts_in: Some("tap"), tapped: Ok(Vec::new()), ..FakeMac::default() };
        let (items, stopped) = run_all(&mac, |_| {}, || mac.stop_reason());
        assert!(mac.stop_saw_it.get(), "the running test is told, so it ends and kills the tone");
        assert_eq!(stopped.as_deref(), Some("A recording started, so the check stopped."));
        assert_eq!(items.len(), 5, "what was finished before it");
        assert!(items.iter().all(|i| i.key != "system_audio_test"), "no \"did not capture the tone\" from a test cut short");
        assert_eq!(*mac.ran.borrow(), ["microphone", "tap"], "nothing after it");
    }

    #[test]
    fn a_recording_that_starts_during_the_microphone_test_ends_it_too() {
        let mac = FakeMac { recording_starts_in: Some("microphone"), peak: Ok(0.0), ..FakeMac::default() };
        let (items, stopped) = run_all(&mac, |_| {}, || mac.stop_reason());
        assert!(mac.stop_saw_it.get());
        assert!(stopped.is_some());
        assert!(items.iter().all(|i| i.key != "microphone_test"), "no \"heard nothing\" from a test cut short");
        assert_eq!(*mac.ran.borrow(), ["microphone"]);
    }

    #[test]
    fn dictation_stops_it_as_a_recording_does() {
        assert_eq!(interrupted(false, false), None);
        assert!(interrupted(true, false).unwrap().contains("recording"));
        assert!(interrupted(false, true).unwrap().contains("Dictation"));
        assert!(interrupted(true, true).unwrap().contains("recording"));
    }

    #[test]
    fn listening_ends_as_soon_as_it_is_told_to() {
        let (tx, rx) = std::sync::mpsc::channel();
        let chunk = |channel| AudioChunk { channel, samples: vec![0.5; 4], ts_ns: None, discontinuity: false };
        tx.send(chunk(Channel::System)).unwrap();
        tx.send(chunk(Channel::Mic)).unwrap();
        let started = Instant::now();
        let looks = Cell::new(0);
        let samples = collect_samples(&rx, Channel::System, Duration::from_secs(5), &|| {
            looks.set(looks.get() + 1);
            looks.get() > 3
        });
        assert_eq!(samples.len(), 4, "only the side that was asked for");
        assert!(started.elapsed() < Duration::from_secs(1), "not the 5 seconds it was given: {:?}", started.elapsed());
    }

    #[test]
    fn listening_ends_when_the_time_is_up_or_the_capture_has_gone() {
        let (tx, rx) = std::sync::mpsc::channel::<AudioChunk>();
        let started = Instant::now();
        assert!(collect_samples(&rx, Channel::Mic, Duration::from_millis(120), &|| false).is_empty());
        assert!(started.elapsed() >= Duration::from_millis(120));
        drop(tx);
        let started = Instant::now();
        assert!(collect_samples(&rx, Channel::Mic, Duration::from_secs(5), &|| false).is_empty());
        assert!(started.elapsed() < Duration::from_secs(1));
    }

    #[test]
    fn it_never_runs_during_a_recording() {
        assert!(refusal(true, false, false).unwrap().contains("recording"));
        assert!(refusal(false, true, false).is_some());
        assert!(refusal(false, false, true).is_some());
        assert_eq!(refusal(false, false, false), None);
    }

    #[test]
    fn the_version_says_what_works() {
        let new = check_version(Some((14, 2, 0)));
        assert_eq!(new.state, CheckState::Ok);
        assert!(new.detail.contains("14.2"));
        let old = check_version(Some((14, 1, 2)));
        assert_eq!(old.state, CheckState::Limited);
        assert!(old.detail.contains("14.1.2") && old.detail.contains("needs macOS 14.2") && old.detail.contains("library"), "{}", old.detail);
        assert_eq!(old.fix.unwrap().settings, "software_update");
        assert_eq!(check_version(Some((13, 6, 0))).state, CheckState::Limited);
        assert_eq!(check_version(Some((27, 3, 0))).state, CheckState::Ok);
        assert_eq!(check_version(None).state, CheckState::Limited);
    }

    #[test]
    fn a_permission_that_is_off_offers_the_right_pane_and_its_test_does_not_run() {
        let mac = FakeMac { permissions: Permissions { microphone: Access::Denied, system_audio: Access::Denied, screen: Access::Denied }, ..FakeMac::default() };
        let items = run(&mac);
        for (key, settings) in [
            ("microphone_permission", "microphone"),
            ("system_audio_permission", "system_audio"),
            ("screen_permission", "screen"),
            ("microphone_test", "microphone"),
            ("system_audio_test", "system_audio"),
            ("screen_capture_test", "screen"),
        ] {
            let check = find(&items, key);
            assert_eq!(check.state, CheckState::Off, "{key}");
            let button = check.fix.as_ref().unwrap_or_else(|| panic!("{key} has no button"));
            assert_eq!((button.label, button.settings), ("Open System Settings", settings), "{key}");
        }
        assert!(mac.ran.borrow().is_empty(), "nothing is opened without the permission: {:?}", mac.ran.borrow());
    }

    #[test]
    fn a_permission_not_asked_yet_is_limited_and_says_when_macos_asks() {
        let mac = FakeMac { permissions: Permissions { microphone: Access::Unknown, system_audio: Access::Allowed, screen: Access::Allowed }, ..FakeMac::default() };
        let check = find(&run(&mac), "microphone_permission").clone();
        assert_eq!(check.state, CheckState::Limited);
        assert!(check.detail.contains("first time you record"));
    }

    #[test]
    fn screen_recording_not_asked_yet_points_to_where_it_is_asked_and_does_not_say_off() {
        let not_asked = Permissions { screen: Access::Unknown, ..ON };
        // What the real capture answers while nothing was asked.
        let mac = FakeMac { permissions: not_asked, frame: Err(FrameProblem::Permission), ..FakeMac::default() };
        let items = run(&mac);
        let permission = find(&items, "screen_permission");
        assert_eq!(permission.state, CheckState::Limited);
        assert!(permission.detail.contains("Settings → Screen moments"), "{}", permission.detail);
        assert!(!permission.detail.contains("first time you record"), "recording never asks for this one");
        assert_eq!(permission.fix, None, "System Settings does not list EchoLedger until it has asked");

        let test = find(&items, "screen_capture_test");
        assert_eq!(test.state, CheckState::Limited, "it was never refused");
        assert!(test.detail.contains("Not tested") && test.detail.contains("Allow Screen Recording"), "{}", test.detail);
        assert!(!test.detail.contains("is off"), "{}", test.detail);
        assert_eq!(test.fix, None);
    }

    #[test]
    fn macos_15_s_monthly_question_is_mentioned_where_it_applies() {
        let on_15 = find(&run(&FakeMac::default()), "screen_permission").detail.clone();
        assert!(on_15.contains("once a month"), "{on_15}");
        let on_14 = find(&run(&FakeMac { version: Some((14, 6, 0)), ..FakeMac::default() }), "screen_permission").detail.clone();
        assert!(!on_14.contains("month"), "{on_14}");
    }

    #[test]
    fn the_microphone_test_says_whether_it_heard_anything() {
        assert_eq!(check_microphone_test(Access::Allowed, || Ok(0.1)).state, CheckState::Ok);
        assert!(check_microphone_test(Access::Allowed, || Ok(0.1)).detail.contains("dB"));
        let silent = check_microphone_test(Access::Allowed, || Ok(0.0));
        assert_eq!(silent.state, CheckState::Limited);
        assert_eq!(silent.fix.unwrap().settings, "sound");
        assert_eq!(check_microphone_test(Access::Unknown, || Ok(0.1)).state, CheckState::Ok, "macOS asked and it worked");
        let failed = check_microphone_test(Access::Allowed, || Err("no device".into()));
        assert_eq!(failed.state, CheckState::Failed);
        assert!(failed.detail.contains("no device"));
    }

    #[test]
    fn the_tone_is_told_from_silence_and_from_other_sound() {
        assert!(tone_heard(&sine(TONE_HZ, TONE_LEVEL, 3.0)));
        assert!(tone_heard(&sine(TONE_HZ, 0.01, 3.0)), "quieter than played still counts");
        assert!(!tone_heard(&vec![0.0; 80_000]));
        assert!(!tone_heard(&[]));
        assert!(!tone_heard(&sine(300.0, 0.5, 3.0)), "a loud other sound is not the tone");
        assert!(!tone_heard(&sine(TONE_HZ, TONE_LEVEL, 0.2)), "a blip is not the tone");
        // Music playing at the same time does not hide it.
        let mixed: Vec<f32> = sine(TONE_HZ, TONE_LEVEL, 3.0).iter().zip(sine(300.0, 0.3, 3.0)).map(|(a, b)| a + b).collect();
        assert!(tone_heard(&mixed));
    }

    #[test]
    fn the_tap_test_says_what_it_captured() {
        let tone = || Ok(sine(TONE_HZ, TONE_LEVEL, 3.0));
        assert_eq!(check_tone_test(Some((15, 0, 0)), Access::Allowed, tone).state, CheckState::Ok);
        let silence = check_tone_test(Some((15, 0, 0)), Access::Allowed, || Ok(vec![0.0; 80_000]));
        assert_eq!(silence.state, CheckState::Failed);
        assert_eq!(silence.fix.unwrap().settings, "system_audio");
        assert_eq!(check_tone_test(Some((15, 0, 0)), Access::Unknown, || Ok(vec![0.0; 10])).state, CheckState::Limited, "macOS may not have asked");
        assert_eq!(check_tone_test(Some((15, 0, 0)), Access::Allowed, || Err("OSStatus -1".into())).state, CheckState::Failed);
        let old = check_tone_test(Some((14, 1, 0)), Access::Allowed, || panic!("no tap on this macOS"));
        assert_eq!(old.state, CheckState::Off);
    }

    #[test]
    fn every_meeting_app_is_known_to_the_detector() {
        for (name, bundle) in MEETING_APPS {
            assert!(known_to_detector(bundle), "{name}: {bundle}");
        }
        assert!(!known_to_detector("com.apple.TextEdit"));
        let check = check_meeting_apps(|b| matches!(b, "us.zoom.xos" | "com.apple.Safari"));
        assert_eq!(check.state, CheckState::Ok);
        assert!(check.detail.contains("Installed: Zoom, Safari"), "{}", check.detail);
        assert!(check.detail.contains("Not installed: Teams, WhatsApp, Chrome, Edge, Arc"), "{}", check.detail);
        assert!(check_meeting_apps(|_| false).detail.contains("Installed: none"));
    }

    #[test]
    fn the_windows_must_exist_show_be_on_screen_and_stay_above_everything() {
        assert_eq!(check_overlay_windows(&good_windows()).state, CheckState::Ok);
        type Change = fn(&mut WindowFacts);
        let cases: [(Change, &str); 5] = [
            (|w| w.exists = false, "could not be opened"),
            (|w| w.visible = false, "did not show"),
            (|w| w.on_screen = false, "off screen"),
            (|w| w.level_ok = false, "above other windows"),
            (|w| w.spaces_ok = false, "across Spaces"),
        ];
        for (change, says) in cases {
            let mut windows = good_windows();
            change(&mut windows[1]);
            let check = check_overlay_windows(&windows);
            assert_eq!(check.state, CheckState::Failed, "{says}");
            assert!(check.detail.contains(says) && check.detail.contains("pill"), "{}", check.detail);
        }
        assert_eq!(check_overlay_windows(&[]).state, CheckState::Failed);
    }

    #[test]
    fn the_screen_test_takes_one_frame_and_keeps_nothing() {
        let check = check_screen_capture(Access::Allowed, || Ok(()));
        assert_eq!(check.state, CheckState::Ok);
        assert!(check.detail.contains("thrown away"));
        assert_eq!(check_screen_capture(Access::Allowed, || Err(FrameProblem::Permission)).state, CheckState::Off);
        assert_eq!(check_screen_capture(Access::Allowed, || Err(FrameProblem::Other("timed out".into()))).state, CheckState::Failed);
    }

    #[test]
    fn nothing_in_a_report_names_a_person() {
        assert_eq!(scrub("could not open /Users/hassam/Library/x.wav now"), "could not open ~/Library/x.wav now");
        assert_eq!(scrub("/home/someone/a and /Users/other"), "~/a and ~");
        assert_eq!(scrub("two\nlines   here"), "two lines here");
        assert_eq!(scrub(&"x".repeat(500)).chars().count(), DETAIL_MAX);

        let mac = FakeMac { peak: Err("cannot read /Users/hassam/Music/test".into()), ..FakeMac::default() };
        let report = CheckReport {
            items: run(&mac),
            stopped: None,
            finished_at: Utc::now(),
            app_version: "1.5.0".into(),
            os_version: "15.1".into(),
            model: "Mac14,2".into(),
            sent_at: None,
        };
        let text = report_text(&report);
        assert!(!text.contains("hassam") && !text.contains("/Users/"), "{text}");
        assert!(text.contains("App 1.5.0 · macOS 15.1 · Mac14,2"), "{text}");
        assert!(text.contains("✓ macOS version (ok)"), "{text}");
        assert!(text.contains("✗ Microphone test (failed)"), "{text}");
    }

    #[test]
    fn every_key_and_detail_fits_the_server_s_contract() {
        let items = run(&FakeMac { peak: Err("e".repeat(1000)), ..FakeMac::default() });
        for check in &items {
            assert!(check.key.len() <= 40 && check.key.chars().all(|c| c.is_ascii_lowercase() || c.is_ascii_digit() || c == '_'), "{}", check.key);
            assert!(check.detail.chars().count() <= DETAIL_MAX, "{}", check.key);
        }
        assert!(items.len() <= 40);
        assert_eq!(serde_json::to_value(CheckState::Limited).unwrap(), "limited");
        assert_eq!(serde_json::to_value(CheckState::Ok).unwrap(), "ok");
    }

    #[test]
    fn the_tone_file_is_soft_and_the_tap_would_hear_it() {
        let bytes = tone_wav().unwrap();
        let mut reader = hound::WavReader::new(std::io::Cursor::new(bytes)).unwrap();
        let spec = reader.spec();
        assert_eq!((spec.channels, spec.sample_rate), (1, 44_100));
        let samples: Vec<f32> = reader.samples::<i16>().map(|s| s.unwrap() as f32 / 32768.0).collect();
        let loudest = samples.iter().fold(0.0_f32, |a, s| a.max(s.abs()));
        assert!((TONE_LEVEL - 0.005..=TONE_LEVEL + 0.001).contains(&loudest), "{loudest}");
        assert!(samples[0].abs() < 0.001, "it fades in");
    }
}
