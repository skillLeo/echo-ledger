//! What the system lets EchoLedger use (Phase 1B): the microphone, system audio and Screen Recording. A missing
//! permission never crashes and never silently records nothing: a recording starts only when it can hold something, and
//! the Record page, the pill and the captions say what is missing and what the recording will contain without it.
//!
//! An unsigned Mac app can lose its permissions at an update, so the core remembers which were granted and says once,
//! calmly, when one has gone (`Memory`). The decisions are pure and tested; only `read` and `request` ask the system,
//! and neither shows anything unless the person pressed Allow (`request`).

use std::path::{Path, PathBuf};

use serde::{Deserialize, Serialize};

use crate::audio::RecordingMode;

#[cfg(target_os = "macos")]
pub mod macos;

#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum Access {
    Allowed,
    Denied,
    /// The system has not asked yet, or does not say (macOS asks the first time).
    Unknown,
    /// Allowed in System Settings while EchoLedger was running: macOS applies Screen Recording when an app opens, so this
    /// copy can use it once it is quit and reopened. Only Screen Recording reads this way.
    Reopen,
}

#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum Kind {
    Microphone,
    SystemAudio,
    Screen,
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct Permissions {
    pub microphone: Access,
    pub system_audio: Access,
    pub screen: Access,
}

impl Permissions {
    pub fn of(&self, kind: Kind) -> Access {
        match kind {
            Kind::Microphone => self.microphone,
            Kind::SystemAudio => self.system_audio,
            Kind::Screen => self.screen,
        }
    }
}

/// What this computer says now, asking nothing of the person. Windows has only its microphone switches: no permission
/// stands between it and system audio or the screen.
pub fn read() -> Permissions {
    #[cfg(target_os = "macos")]
    {
        macos::read()
    }
    #[cfg(windows)]
    {
        let microphone = match crate::audio::health::microphone_permission() {
            "allowed" => Access::Allowed,
            "denied" => Access::Denied,
            _ => Access::Unknown,
        };
        Permissions { microphone, system_audio: Access::Allowed, screen: Access::Allowed }
    }
    #[cfg(not(any(windows, target_os = "macos")))]
    {
        Permissions { microphone: Access::Unknown, system_audio: Access::Unknown, screen: Access::Unknown }
    }
}

/// Something missing, and what a recording holds without it.
#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct Missing {
    pub kind: Kind,
    pub sentence: String,
    /// A call's other side found not recorded or not heard by the recording itself (2.0.6), not read from the system.
    #[serde(skip_serializing_if = "Option::is_none")]
    pub other_side: Option<OtherSide>,
}

/// What the recording found about its other side (`audio::health::SystemProblem`).
#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum OtherSide {
    /// Not recorded: the tap did not open or carries no data. System Audio Recording is named, with its settings.
    Permission,
    /// Only silence for a long while although another app plays sound: neutral words, never the permission.
    Silent,
}

const NO_MICROPHONE: &str = "The microphone is off for EchoLedger: a recording will have the other side of the call, not your voice.";
const NO_SYSTEM_AUDIO: &str = "System audio is off for EchoLedger: a recording will have your voice, not the other side of the call.";
const NO_SCREEN: &str = "Screen Recording is off for EchoLedger: recordings will have their sound but no screen moments.";
const NOTHING_TO_RECORD: &str = "The microphone and system audio are both off for EchoLedger, so there is nothing to record. Allow them in System Settings.";
const NO_NOTE_MICROPHONE: &str = "The microphone is off for EchoLedger, so a voice note has nothing to record. Allow it in your computer's privacy settings.";
/// Short enough for the dictation box, which it is shown in.
pub const NO_DICTATION: &str = "The microphone is off for EchoLedger. Allow it in your computer's privacy settings.";
const NO_NOTE_SYSTEM_AUDIO: &str = "System audio is off for EchoLedger, so sound playing on this computer cannot be captured. Allow it in System Settings.";

fn missing(kind: Kind, sentence: &str) -> Missing {
    Missing { kind, sentence: sentence.to_string(), other_side: None }
}

/// What the Record page says now: what is off and what a call recording holds without it. Screen Recording counts only
/// while screen moments are wanted; "not asked yet" is not missing (macOS asks when the recording starts, and the running
/// recording then reads the answer, `missing_during`).
pub fn missing_now(permissions: &Permissions, screens_wanted: bool) -> Vec<Missing> {
    let mut out = Vec::new();
    match (permissions.microphone == Access::Denied, permissions.system_audio == Access::Denied) {
        (true, true) => out.push(missing(Kind::Microphone, NOTHING_TO_RECORD)),
        (true, false) => out.push(missing(Kind::Microphone, NO_MICROPHONE)),
        (false, true) => out.push(missing(Kind::SystemAudio, NO_SYSTEM_AUDIO)),
        (false, false) => {}
    }
    if screens_wanted && permissions.screen == Access::Denied {
        out.push(missing(Kind::Screen, NO_SCREEN));
    }
    out
}

/// What a recording about to start may do.
#[derive(Clone, Debug, PartialEq, Eq)]
pub enum StartCheck {
    /// It starts; what is listed will be missing from it.
    Go(Vec<Missing>),
    /// It would hold nothing, so it does not start: the sentence says why.
    Refuse(String),
}

pub fn check_start(mode: RecordingMode, permissions: &Permissions) -> StartCheck {
    let (mic_off, system_off) = (permissions.microphone == Access::Denied, permissions.system_audio == Access::Denied);
    match mode {
        RecordingMode::Call if mic_off && system_off => StartCheck::Refuse(NOTHING_TO_RECORD.to_string()),
        RecordingMode::MicNote if mic_off => StartCheck::Refuse(NO_NOTE_MICROPHONE.to_string()),
        RecordingMode::VoiceNote if system_off => StartCheck::Refuse(NO_NOTE_SYSTEM_AUDIO.to_string()),
        _ => StartCheck::Go(missing_during(mode, permissions)),
    }
}

/// What a running recording of `mode` is missing now. There is nothing left to refuse: the prompt that settles "not
/// asked yet" comes after the start, and a permission can be turned off during a recording, so all of what is off is
/// listed. Both sides of a call off is both kinds, which is how the pill and the captions know nothing is being recorded.
pub fn missing_during(mode: RecordingMode, permissions: &Permissions) -> Vec<Missing> {
    let (mic_off, system_off) = (permissions.microphone == Access::Denied, permissions.system_audio == Access::Denied);
    match mode {
        RecordingMode::Call if mic_off && system_off => vec![missing(Kind::Microphone, NOTHING_TO_RECORD), missing(Kind::SystemAudio, NOTHING_TO_RECORD)],
        RecordingMode::Call if mic_off => vec![missing(Kind::Microphone, NO_MICROPHONE)],
        RecordingMode::Call if system_off => vec![missing(Kind::SystemAudio, NO_SYSTEM_AUDIO)],
        RecordingMode::MicNote if mic_off => vec![missing(Kind::Microphone, NO_NOTE_MICROPHONE)],
        RecordingMode::VoiceNote if system_off => vec![missing(Kind::SystemAudio, NO_NOTE_SYSTEM_AUDIO)],
        _ => Vec::new(),
    }
}

/// What was granted last time, and what the person has not been told is gone yet (`permissions.json`, this computer's).
#[derive(Clone, Debug, Default, PartialEq, Eq, Serialize, Deserialize)]
pub struct Memory {
    #[serde(default)]
    pub granted: Vec<Kind>,
    #[serde(default)]
    pub notice: Vec<Kind>,
    /// Every permission ever granted on this computer, kept whatever happens later (D-120): one that macOS now calls
    /// "not asked" was allowed before ([`Memory::allowed_before`]).
    #[serde(default)]
    pub ever: Vec<Kind>,
    /// The run of EchoLedger ([`this_run`]) in which Screen Recording first read [`Access::Reopen`]: a later run that
    /// still reads it was reopened and macOS did not apply it, so reopening again would not help either.
    #[serde(default)]
    pub reopen_since: Option<u64>,
    /// The copy of EchoLedger this memory was made by ([`identity`]: its version and code signature). macOS keeps a
    /// permission for one signature: another copy (an update signed otherwise) may find what was granted gone while the
    /// privacy call still says yes, so what was granted is forgotten when it changes ([`Memory::for_identity`]).
    #[serde(default)]
    pub identity: Option<String>,
    /// Why system audio did not record although macOS's privacy call allowed it: a tap that could not open or gave
    /// buffers with no data (`audio::health::SystemProblem::names_permission`; never zero-valued frames, a quiet call).
    /// While set, system audio counts as off ([`Memory::overlay`]); it goes when the other side is heard again or a
    /// check finds it working.
    #[serde(default)]
    pub system_audio_failing: Option<String>,
    /// System audio must be checked for real (a short tap, [`probe_system_audio`]): this copy has not been checked yet.
    /// Set once per copy ([`Memory::for_identity`]), so the check (and macOS's purple indicator with it) comes once,
    /// at the first start after an update, never at every start.
    #[serde(default)]
    pub verify_system_audio: bool,
}

const KINDS: [Kind; 3] = [Kind::Microphone, Kind::SystemAudio, Kind::Screen];

impl Memory {
    pub fn path(data_dir: &Path) -> PathBuf {
        data_dir.join("permissions.json")
    }

    /// This memory for the copy of EchoLedger running now (pure): another version or code signature than the one that
    /// made it forgets what was granted and what was failing, so the permission step asks again, and system audio is
    /// checked for real. What was ever granted stays (D-120's "allowed before").
    pub fn for_identity(&self, identity: &str) -> Memory {
        if self.identity.as_deref() == Some(identity) {
            return self.clone();
        }
        Memory {
            granted: Vec::new(),
            notice: Vec::new(),
            ever: self.ever.clone(),
            reopen_since: None,
            identity: Some(identity.to_string()),
            system_audio_failing: None,
            verify_system_audio: true,
        }
    }

    /// What the system says, with what EchoLedger found itself (pure): system audio that did not record counts as off,
    /// whatever the privacy call says.
    pub fn overlay(&self, now: Permissions) -> Permissions {
        match &self.system_audio_failing {
            Some(_) => Permissions { system_audio: Access::Denied, ..now },
            None => now,
        }
    }

    /// What a real look at system audio found (pure): `Err` why it did not record, `Ok` it did, none nothing could be
    /// told (what was found before stays). Either way this copy has been checked.
    pub fn with_system_audio(&self, result: Option<Result<(), String>>) -> Memory {
        let system_audio_failing = match result {
            Some(result) => result.err(),
            None => self.system_audio_failing.clone(),
        };
        Memory { system_audio_failing, verify_system_audio: false, ..self.clone() }
    }

    /// Whether this start looks at system audio for real (pure): once per copy of EchoLedger, and only where it was
    /// allowed before, so no first-time prompt comes unasked. A signed copy's code hash (CDHash) changes with every
    /// build, but a person only gets a new build through an update, so this is the first start after an update, before
    /// the first call. System audio found failing is not looked at again at every start: the next call says so itself,
    /// and Allow (or the Mac check) looks again.
    pub fn wants_probe(&self) -> bool {
        self.verify_system_audio && self.ever.contains(&Kind::SystemAudio)
    }

    pub fn load(path: &Path) -> Self {
        std::fs::read(path).ok().and_then(|bytes| serde_json::from_slice(&bytes).ok()).unwrap_or_default()
    }

    pub fn save(&self, path: &Path) {
        if let Ok(bytes) = serde_json::to_vec_pretty(self) {
            let _ = std::fs::write(path, bytes);
        }
    }

    /// Takes in what the system says now. A permission that was granted and is not any more goes on the notice, once
    /// per loss; granted again it leaves the notice and counts as granted. Screen Recording counts only while screen
    /// moments are wanted: nobody needs to hear about what they do not use. System audio is read only through the
    /// privacy call ([`can_tell_not_asked`]): without it, its "unknown" says nothing, so the memory of it stays as it
    /// was and no loss is noted (D-134). The microphone (AVFoundation) and Screen Recording (CoreGraphics) are read
    /// through public calls and always count.
    pub fn observe(&self, now: &Permissions, screens_wanted: bool, can_tell_not_asked: bool) -> Memory {
        let mut next = self.clone();
        for kind in KINDS {
            if kind == Kind::SystemAudio && !can_tell_not_asked {
                continue;
            }
            let had = next.granted.contains(&kind);
            // Allowed in System Settings and waiting for a reopen is granted: nothing went.
            let allowed = matches!(now.of(kind), Access::Allowed | Access::Reopen);
            if (had || next.notice.contains(&kind) || allowed) && !next.ever.contains(&kind) {
                next.ever.push(kind);
            }
            if allowed {
                if !had {
                    next.granted.push(kind);
                }
                next.notice.retain(|k| *k != kind);
            } else if had {
                next.granted.retain(|k| *k != kind);
                if (kind != Kind::Screen || screens_wanted) && !next.notice.contains(&kind) {
                    next.notice.push(kind);
                }
            }
        }
        next
    }

    /// Notes the run in which Screen Recording first waited for a reopen (pure); forgotten as soon as it reads anything
    /// else (applied, turned off, or switched off and on in System Settings).
    pub fn note_reopen(&self, now: &Permissions, run: u64) -> Memory {
        let reopen_since = if now.screen == Access::Reopen { self.reopen_since.or(Some(run)) } else { None };
        Memory { reopen_since, ..self.clone() }
    }

    /// Whether Screen Recording still waits although EchoLedger was quit and reopened since it was allowed (pure): then
    /// "Quit and reopen" is not said again, only to switch EchoLedger off and on in System Settings.
    pub fn reopen_did_not_apply(&self, now: &Permissions, run: u64) -> bool {
        now.screen == Access::Reopen && self.reopen_since.is_some_and(|since| since != run)
    }

    /// The permissions this computer allowed before that macOS now calls "not asked" (D-120). Another copy of EchoLedger
    /// (an older build) that System Settings still lists can be why, and so can a reset of the privacy settings or a Mac
    /// set up from another one: it is said as a possibility, never as what happened. System audio and Screen Recording
    /// count only when `can_tell_not_asked` (macOS answered the privacy call that tells "not asked" apart for them; without
    /// it they are merely unknown). Screen Recording allowed while EchoLedger ran is [`Access::Reopen`], never this.
    pub fn allowed_before(&self, now: &Permissions, can_tell_not_asked: bool) -> Vec<Kind> {
        KINDS
            .into_iter()
            .filter(|k| can_tell_not_asked || *k == Kind::Microphone)
            .filter(|k| self.ever.contains(k) && now.of(*k) == Access::Unknown)
            .collect()
    }

    /// The one calm sentence about what went; none when nothing did. A loss is said only while it can be checked: system
    /// audio's needs the privacy call ([`can_tell_not_asked`]), so without it nothing is said about system audio, not
    /// even a loss noted in an earlier run (D-134); a call that goes without sound still says so while it records.
    pub fn sentence(&self, can_tell_not_asked: bool) -> Option<String> {
        let names: Vec<&str> = KINDS
            .into_iter()
            .filter(|k| self.notice.contains(k))
            .filter(|k| *k != Kind::SystemAudio || can_tell_not_asked)
            .map(|k| match k {
                Kind::Microphone => "the microphone",
                Kind::SystemAudio => "system audio",
                Kind::Screen => "Screen Recording",
            })
            .collect();
        let list = match names.as_slice() {
            [] => return None,
            [one] => (*one).to_string(),
            [first, second] => format!("{first} and {second}"),
            [first, second, third, ..] => format!("{first}, {second} and {third}"),
        };
        Some(format!("macOS no longer lets EchoLedger use {list}."))
    }
}

/// The running copy of EchoLedger: its version and, on a Mac, its code signature's hash (another build or signing
/// identity is another copy to macOS's privacy settings). The hash changes with every build, so every update is a new
/// copy: what was granted is asked again and system audio is looked at once ([`Memory::wants_probe`]).
pub fn identity() -> String {
    let version = env!("CARGO_PKG_VERSION");
    #[cfg(target_os = "macos")]
    {
        format!("{version} {}", macos::code_hash().unwrap_or_else(|| "unsigned".into()))
    }
    #[cfg(not(target_os = "macos"))]
    {
        version.to_string()
    }
}

/// The sentence of a call recording whose other side is not being recorded (`audio::health::system_side_problem`).
pub const SYSTEM_NOT_RECORDED: &str = "The other side isn't being recorded. Allow System Audio Recording for EchoLedger.";
/// The same once trying again did not help: macOS applies it when EchoLedger opens again.
pub const SYSTEM_NOT_RECORDED_REOPEN: &str = "The other side still isn't being recorded. Allow System Audio Recording for EchoLedger, then quit and reopen EchoLedger.";

/// The neutral words for a call that hears only silence from its other side while another app plays sound.
pub const OTHER_SIDE_SILENT: &str = "Can't hear the other side. Check the call's speaker or output device.";

/// What a running recording is missing (pure): what the system says ([`missing_during`]) and what the recording found
/// about its other side. Not recorded takes the place of any other line about system audio; not heard is added.
pub fn missing_with(mode: RecordingMode, permissions: &Permissions, warning: Option<(OtherSide, &str)>) -> Vec<Missing> {
    let mut out = missing_during(mode, permissions);
    let Some((found, sentence)) = warning.filter(|_| mode == RecordingMode::Call) else { return out };
    // Both sides off already says nothing is recorded.
    if out.iter().any(|m| m.kind == Kind::Microphone) {
        return out;
    }
    if found == OtherSide::Permission {
        out.retain(|m| m.kind != Kind::SystemAudio);
    }
    out.push(Missing { kind: Kind::SystemAudio, sentence: sentence.to_string(), other_side: Some(found) });
    out
}

/// What a short look at system audio found (pure), from what the tap did: `Some(Err)` System Audio Recording is not
/// letting this copy through, `Some(Ok)` it is, `None` nothing can be told.
/// - The tap did not open, or every buffer it gave carried no data (`mDataByteSize` 0): not allowed. macOS gives a copy
///   it does not let record empty buffers, whatever its privacy call says.
/// - It gave frames, zeros included: allowed. Nothing playing is a quiet computer, never a missing permission, so a
///   silent start is never kept as one.
/// - It gave nothing at all (no IOProc call): the device's matter; nothing is kept.
pub fn probe_verdict(opened: Result<(), String>, frames: u64, empty_buffers: u64) -> Option<Result<(), String>> {
    match opened {
        Err(why) => Some(Err(format!("the system audio tap did not open: {why}"))),
        Ok(()) if frames > 0 => Some(Ok(())),
        Ok(()) if empty_buffers > 0 => Some(Err("the system audio tap delivered buffers with no audio data".into())),
        Ok(()) => None,
    }
}

/// Records system audio for a moment to see whether macOS lets it through ([`probe_verdict`]; blocking, ~1.5 s). The
/// privacy call can say yes for a copy macOS no longer lets record (another signature).
pub fn probe_system_audio() -> Option<Result<(), String>> {
    #[cfg(target_os = "macos")]
    {
        use crate::audio::types::Channel;
        let (tx, rx) = std::sync::mpsc::channel();
        let mut backend = match crate::audio::platform::start(crate::audio::platform::Streams { mic: false, system: true }, tx) {
            Ok(backend) => backend,
            Err(e) => return probe_verdict(Err(e.to_string()), 0, 0),
        };
        let until = std::time::Instant::now() + std::time::Duration::from_millis(1_500);
        let mut frames = 0_u64;
        while let Some(left) = until.checked_duration_since(std::time::Instant::now()) {
            match rx.recv_timeout(left) {
                Ok(chunk) if chunk.channel == Channel::System => frames += chunk.samples.len() as u64,
                Ok(_) => {}
                Err(_) => break,
            }
        }
        let empty = backend.system_empty_buffers();
        backend.stop();
        probe_verdict(Ok(()), frames, empty)
    }
    #[cfg(not(target_os = "macos"))]
    {
        Some(Ok(()))
    }
}

/// Whether this computer tells "not asked yet" apart from "told no" for system audio and Screen Recording: on a Mac, the
/// privacy call that reads it is there. Windows has no such permissions.
pub fn can_tell_not_asked() -> bool {
    #[cfg(target_os = "macos")]
    {
        macos::reads_privacy_answers()
    }
    #[cfg(not(target_os = "macos"))]
    {
        false
    }
}

/// This run of EchoLedger: the same number for as long as it runs, another one when it starts again.
pub fn this_run() -> u64 {
    static RUN: std::sync::OnceLock<u64> = std::sync::OnceLock::new();
    *RUN.get_or_init(|| {
        let started = std::time::SystemTime::now().duration_since(std::time::UNIX_EPOCH).map(|d| d.as_nanos() as u64).unwrap_or_default();
        started ^ u64::from(std::process::id()).rotate_left(48)
    })
}

/// Why EchoLedger does not quit and reopen now (pure): never in the middle of a recording or of dictation.
pub fn reopen_refusal(recording: bool, dictating: bool) -> Option<&'static str> {
    if recording {
        Some("Stop the recording first: EchoLedger quits and reopens to finish.")
    } else if dictating {
        Some("Finish dictating first: EchoLedger quits and reopens to finish.")
    } else {
        None
    }
}

/// Asks for a permission because the person pressed Allow: macOS shows its prompt the first time and nothing later on
/// (then only System Settings can change it).
pub fn request(kind: Kind) {
    #[cfg(target_os = "macos")]
    macos::request(kind);
    #[cfg(not(target_os = "macos"))]
    let _ = kind;
}

#[cfg(test)]
mod tests {
    use super::*;

    const ON: Permissions = Permissions { microphone: Access::Allowed, system_audio: Access::Allowed, screen: Access::Allowed };

    fn call(permissions: &Permissions) -> StartCheck {
        check_start(RecordingMode::Call, permissions)
    }

    #[test]
    fn another_copy_of_echoledger_asks_for_its_permissions_again() {
        let granted = Memory {
            granted: vec![Kind::Microphone, Kind::SystemAudio, Kind::Screen],
            ever: vec![Kind::Microphone, Kind::SystemAudio, Kind::Screen],
            identity: Some("2.0.3 0a1b".into()),
            ..Memory::default()
        };
        // The same copy: nothing changes.
        assert_eq!(granted.for_identity("2.0.3 0a1b"), granted);
        // An update signed otherwise (2.0.3 ad hoc → 2.0.4 with the pinned identity): what was granted is asked again and
        // system audio is checked for real; what was ever allowed stays.
        for next in ["2.0.4 9f8e", "2.0.3 9f8e", "2.0.4 0a1b"] {
            let fresh = granted.for_identity(next);
            assert!(fresh.granted.is_empty(), "{next}");
            assert!(fresh.verify_system_audio, "{next}");
            assert_eq!(fresh.identity.as_deref(), Some(next));
            assert_eq!(fresh.ever, granted.ever);
        }
        // A memory from before 2.0.6 has no identity: the same.
        let old: Memory = serde_json::from_str(r#"{"granted":["microphone","system_audio","screen"],"notice":[]}"#).unwrap();
        assert!(old.for_identity("2.0.6 abcd").granted.is_empty());
        assert!(!identity().is_empty());
    }

    #[test]
    fn system_audio_that_did_not_record_counts_as_off_until_it_does() {
        let memory = Memory::default().with_system_audio(Some(Err("the system audio tap delivered no audio in 3 s".into())));
        assert_eq!(memory.overlay(ON).system_audio, Access::Denied, "Setup and the Mac check say it is missing");
        assert_eq!(memory.overlay(ON).microphone, Access::Allowed);
        assert_eq!(missing_now(&memory.overlay(ON), false)[0].kind, Kind::SystemAudio);
        let saved: Memory = serde_json::from_str(&serde_json::to_string(&memory).unwrap()).unwrap();
        assert_eq!(saved.system_audio_failing.as_deref(), Some("the system audio tap delivered no audio in 3 s"), "kept across a restart");
        assert_eq!(memory.with_system_audio(None).system_audio_failing, memory.system_audio_failing, "nothing told: kept");
        let working = memory.with_system_audio(Some(Ok(())));
        assert_eq!(working.overlay(ON), ON);
        assert!(!working.verify_system_audio);
    }

    #[test]
    fn a_quiet_computer_is_never_a_missing_permission() {
        // Zero-valued frames (nothing playing): allowed, never kept as a failure.
        assert_eq!(probe_verdict(Ok(()), 24_000, 0), Some(Ok(())));
        assert_eq!(probe_verdict(Ok(()), 24_000, 3), Some(Ok(())), "a few empty buffers before frames: allowed");
        // Buffers with no data at all, or a tap that did not open: System Audio Recording is not letting it through.
        assert_eq!(probe_verdict(Ok(()), 0, 140), Some(Err("the system audio tap delivered buffers with no audio data".into())));
        assert_eq!(probe_verdict(Err("system audio tap (OSStatus 1852797029)".into()), 0, 0).unwrap().unwrap_err(), "the system audio tap did not open: system audio tap (OSStatus 1852797029)");
        // Nothing at all (no IOProc call): the device's matter; nothing is kept.
        assert_eq!(probe_verdict(Ok(()), 0, 0), None);
    }

    #[test]
    fn the_start_looks_at_system_audio_once_per_copy() {
        let ever = Memory { ever: vec![Kind::SystemAudio], identity: Some("2.0.5 aa".into()), ..Memory::default() };
        let updated = ever.for_identity("2.0.6 bb");
        assert!(updated.wants_probe(), "the first start after an update");
        let looked = updated.with_system_audio(Some(Ok(())));
        assert!(!looked.wants_probe(), "not the next start");
        assert!(!updated.with_system_audio(None).wants_probe(), "nothing told is still looked: once");
        let failing = updated.with_system_audio(Some(Err("no data".into())));
        assert!(!failing.wants_probe(), "failing is not looked at again at every start");
        assert_eq!(failing.for_identity("2.0.6 bb"), failing, "the same copy");
        assert!(!Memory::default().for_identity("2.0.6 bb").wants_probe(), "never allowed before: no prompt unasked");
    }

    #[test]
    fn a_call_starts_with_what_it_has_and_says_what_it_will_hold() {
        assert_eq!(call(&ON), StartCheck::Go(Vec::new()));
        let not_asked = Permissions { microphone: Access::Unknown, system_audio: Access::Unknown, screen: Access::Unknown };
        assert_eq!(call(&not_asked), StartCheck::Go(Vec::new()), "macOS asks when it starts");

        let StartCheck::Go(no_mic) = call(&Permissions { microphone: Access::Denied, ..ON }) else { panic!("the other side still records") };
        assert_eq!(no_mic[0].kind, Kind::Microphone);
        assert!(no_mic[0].sentence.contains("the other side of the call, not your voice"));

        let StartCheck::Go(no_system) = call(&Permissions { system_audio: Access::Denied, ..ON }) else { panic!("your voice still records") };
        assert_eq!(no_system[0].kind, Kind::SystemAudio);
        assert!(no_system[0].sentence.contains("your voice, not the other side"));
    }

    #[test]
    fn a_recording_that_would_hold_nothing_does_not_start() {
        let neither = Permissions { microphone: Access::Denied, system_audio: Access::Denied, ..ON };
        assert!(matches!(call(&neither), StartCheck::Refuse(s) if s.contains("nothing to record")));
        let no_mic = Permissions { microphone: Access::Denied, ..ON };
        assert!(matches!(check_start(RecordingMode::MicNote, &no_mic), StartCheck::Refuse(_)));
        assert_eq!(check_start(RecordingMode::VoiceNote, &no_mic), StartCheck::Go(Vec::new()), "a capture of this computer's sound needs no microphone");
        let no_system = Permissions { system_audio: Access::Denied, ..ON };
        assert!(matches!(check_start(RecordingMode::VoiceNote, &no_system), StartCheck::Refuse(_)));
        assert_eq!(check_start(RecordingMode::MicNote, &no_system), StartCheck::Go(Vec::new()));
    }

    #[test]
    fn a_prompt_answered_after_the_start_is_heard_during_the_recording() {
        let not_asked = Permissions { microphone: Access::Unknown, system_audio: Access::Unknown, screen: Access::Unknown };
        assert_eq!(call(&not_asked), StartCheck::Go(Vec::new()), "the start knows nothing yet");
        assert_eq!(missing_during(RecordingMode::Call, &not_asked), Vec::new(), "the prompts are still up");

        // Don't Allow for the microphone: the recording goes on with one side, and now it says so.
        let no_mic = missing_during(RecordingMode::Call, &Permissions { microphone: Access::Denied, ..not_asked });
        assert_eq!(no_mic.iter().map(|m| m.kind).collect::<Vec<_>>(), [Kind::Microphone]);
        assert!(no_mic[0].sentence.contains("not your voice"));

        // Don't Allow for both: a start would have refused, a running recording lists both.
        let both = missing_during(RecordingMode::Call, &Permissions { microphone: Access::Denied, system_audio: Access::Denied, ..not_asked });
        assert_eq!(both.iter().map(|m| m.kind).collect::<Vec<_>>(), [Kind::Microphone, Kind::SystemAudio]);
        assert!(both[0].sentence.contains("nothing to record"));

        // Allowed after all: the warning goes.
        assert_eq!(missing_during(RecordingMode::Call, &ON), Vec::new());
    }

    #[test]
    fn a_note_during_its_recording_counts_only_what_it_records() {
        let no_mic = Permissions { microphone: Access::Denied, ..ON };
        let no_system = Permissions { system_audio: Access::Denied, ..ON };
        assert_eq!(missing_during(RecordingMode::MicNote, &no_mic)[0].kind, Kind::Microphone);
        assert_eq!(missing_during(RecordingMode::VoiceNote, &no_system)[0].kind, Kind::SystemAudio);
        assert_eq!(missing_during(RecordingMode::MicNote, &no_system), Vec::new());
        assert_eq!(missing_during(RecordingMode::VoiceNote, &no_mic), Vec::new());
    }

    #[test]
    fn the_record_page_lists_what_is_off() {
        assert!(missing_now(&ON, true).is_empty());
        let no_screen = Permissions { screen: Access::Denied, ..ON };
        assert!(missing_now(&no_screen, false).is_empty(), "screen moments are not on: nothing is missing");
        assert_eq!(missing_now(&no_screen, true)[0].kind, Kind::Screen);
        let neither = Permissions { microphone: Access::Denied, system_audio: Access::Denied, screen: Access::Denied };
        let all = missing_now(&neither, true);
        assert_eq!(all.len(), 2);
        assert!(all[0].sentence.contains("nothing to record"));
        let not_asked = Permissions { microphone: Access::Unknown, system_audio: Access::Unknown, screen: Access::Unknown };
        assert!(missing_now(&not_asked, true).is_empty(), "not asked yet is not missing");
    }

    #[test]
    fn a_permission_that_goes_is_said_once_per_loss() {
        let start = Memory::default().observe(&ON, true, true);
        assert_eq!(start.notice, Vec::<Kind>::new());
        assert_eq!(start.granted.len(), 3);

        // An update: macOS forgot the microphone.
        let lost = start.observe(&Permissions { microphone: Access::Unknown, ..ON }, true, true);
        assert_eq!(lost.notice, vec![Kind::Microphone]);
        assert!(lost.sentence(true).unwrap().contains("the microphone"));

        // The person dismissed it; the next starts say nothing more.
        let dismissed = Memory { notice: Vec::new(), ..lost };
        let later = dismissed.observe(&Permissions { microphone: Access::Unknown, ..ON }, true, true);
        assert_eq!(later.notice, Vec::<Kind>::new(), "no nagging");

        // Granted again, then lost again: that is a new loss.
        let again = later.observe(&ON, true, true);
        assert!(again.granted.contains(&Kind::Microphone));
        let lost_again = again.observe(&Permissions { microphone: Access::Denied, ..ON }, true, true);
        assert_eq!(lost_again.notice, vec![Kind::Microphone]);
    }

    #[test]
    fn a_permission_granted_again_leaves_the_notice_by_itself() {
        let lost = Memory::default().observe(&ON, true, true).observe(&Permissions { system_audio: Access::Denied, ..ON }, true, true);
        assert_eq!(lost.notice, vec![Kind::SystemAudio]);
        assert_eq!(lost.observe(&ON, true, true).notice, Vec::<Kind>::new());
    }

    #[test]
    fn screen_recording_counts_only_while_screen_moments_are_wanted() {
        let start = Memory::default().observe(&ON, false, true);
        let without = start.observe(&Permissions { screen: Access::Denied, ..ON }, false, true);
        assert_eq!(without.notice, Vec::<Kind>::new());
        assert!(!without.granted.contains(&Kind::Screen), "forgotten: a later grant counts afresh");
        assert_eq!(start.observe(&Permissions { screen: Access::Denied, ..ON }, true, true).notice, vec![Kind::Screen]);
    }

    #[test]
    fn something_never_granted_was_not_lost() {
        let first = Memory::default().observe(&Permissions { microphone: Access::Unknown, system_audio: Access::Denied, screen: Access::Denied }, true, true);
        assert_eq!(first, Memory::default());
    }

    #[test]
    fn a_permission_allowed_before_but_not_asked_now_is_said_as_allowed_before() {
        let start = Memory::default().observe(&ON, true, true);
        assert_eq!(start.ever, vec![Kind::Microphone, Kind::SystemAudio, Kind::Screen]);
        // macOS calls the microphone "not asked" now (another build, a reset, a migrated Mac): allowed before.
        let fresh = Permissions { microphone: Access::Unknown, ..ON };
        let after = start.observe(&fresh, true, true);
        assert_eq!(after.allowed_before(&fresh, true), vec![Kind::Microphone]);
        // Dismissing the banner forgets the notice, never what was granted.
        let dismissed = Memory { notice: Vec::new(), ..after.clone() };
        assert_eq!(dismissed.allowed_before(&fresh, true), vec![Kind::Microphone]);
        // Turned off on purpose (denied) is said as off; never granted is just "not asked".
        assert!(after.allowed_before(&Permissions { microphone: Access::Denied, ..ON }, true).is_empty());
        assert!(Memory::default().allowed_before(&fresh, true).is_empty());
        // A memory saved before this field existed learns it from what it knew.
        let old: Memory = serde_json::from_str(r#"{"granted":[],"notice":["microphone"]}"#).unwrap();
        assert_eq!(old.observe(&fresh, true, true).allowed_before(&fresh, true), vec![Kind::Microphone]);
    }

    #[test]
    fn what_macos_cannot_say_or_a_permission_waiting_for_a_reopen_is_never_allowed_before() {
        let start = Memory::default().observe(&ON, true, true);
        let unknown = Permissions { microphone: Access::Unknown, system_audio: Access::Unknown, screen: Access::Unknown };
        // Without the privacy call, system audio and Screen Recording are only unknown: nothing is said about them.
        assert_eq!(start.observe(&unknown, true, true).allowed_before(&unknown, false), vec![Kind::Microphone]);
        assert_eq!(start.observe(&unknown, true, true).allowed_before(&unknown, true), vec![Kind::Microphone, Kind::SystemAudio, Kind::Screen]);
        // Screen Recording switched on in System Settings while EchoLedger ran: waiting for a reopen, never "remove it".
        let reopen = Permissions { screen: Access::Reopen, ..ON };
        let waiting = start.observe(&Permissions { screen: Access::Denied, ..ON }, true, true).observe(&reopen, true, true);
        assert!(waiting.allowed_before(&reopen, true).is_empty());
        assert!(waiting.notice.is_empty(), "allowed again: the loss is not said");
        assert!(waiting.granted.contains(&Kind::Screen));
    }

    #[test]
    fn a_reopen_that_did_not_apply_screen_recording_is_not_offered_again() {
        let reopen = Permissions { screen: Access::Reopen, ..ON };
        // Allowed in System Settings while run 1 ran: quit and reopen is the advice.
        let first = Memory::default().observe(&reopen, true, true).note_reopen(&reopen, 1);
        assert_eq!(first.reopen_since, Some(1));
        assert!(!first.reopen_did_not_apply(&reopen, 1));
        // The same run reads it again: still the first run.
        assert_eq!(first.note_reopen(&reopen, 1).reopen_since, Some(1));
        // Reopened (run 2) and it still waits: reopening did not apply it.
        let second = first.note_reopen(&reopen, 2);
        assert_eq!(second.reopen_since, Some(1));
        assert!(second.reopen_did_not_apply(&reopen, 2));
        // Applied, turned off, or switched off and on: forgotten, and the next wait starts afresh.
        for other in [ON, Permissions { screen: Access::Denied, ..ON }, Permissions { screen: Access::Unknown, ..ON }] {
            let moved = second.note_reopen(&other, 2);
            assert_eq!(moved.reopen_since, None);
            assert!(!moved.reopen_did_not_apply(&reopen, 2));
            assert!(!moved.note_reopen(&reopen, 2).reopen_did_not_apply(&reopen, 2), "a new wait offers Quit and reopen again");
        }
        // Only while it reads Reopen.
        assert!(!second.reopen_did_not_apply(&ON, 2));
        // Kept on this computer like the rest; a memory from before this field reads none.
        let old: Memory = serde_json::from_str(r#"{"granted":["screen"],"notice":[]}"#).unwrap();
        assert_eq!(old.reopen_since, None);
        assert_eq!(this_run(), this_run(), "one number per run");
    }

    #[test]
    fn it_quits_and_reopens_only_between_recordings_and_dictation() {
        assert_eq!(reopen_refusal(false, false), None);
        assert!(reopen_refusal(true, false).unwrap().starts_with("Stop the recording first"));
        assert!(reopen_refusal(false, true).unwrap().starts_with("Finish dictating first"));
    }

    #[test]
    fn system_audio_is_never_said_lost_without_the_privacy_call() {
        // D-134: without the privacy call, system audio reads "unknown" whatever is true: nothing is noted or said.
        let start = Memory::default().observe(&ON, true, true);
        let unknown = Permissions { system_audio: Access::Unknown, ..ON };
        let without = start.observe(&unknown, true, false);
        assert!(without.notice.is_empty(), "a guess is not a loss");
        assert!(without.granted.contains(&Kind::SystemAudio), "nothing is known, so nothing is forgotten");
        assert_eq!(without.sentence(false), None);
        // With the call, the same reading is a real loss and is said.
        let with = start.observe(&unknown, true, true);
        assert_eq!(with.notice, vec![Kind::SystemAudio]);
        assert_eq!(with.sentence(true).as_deref(), Some("macOS no longer lets EchoLedger use system audio."));
        // A loss noted in an earlier run is not said while it cannot be checked; the others still are.
        assert_eq!(with.sentence(false), None);
        let both = Memory { notice: vec![Kind::Microphone, Kind::SystemAudio], ..with.clone() };
        assert_eq!(both.sentence(false).as_deref(), Some("macOS no longer lets EchoLedger use the microphone."));
        // The microphone is read through a public call: its loss is said without the privacy call too.
        let no_mic = start.observe(&Permissions { microphone: Access::Unknown, system_audio: Access::Unknown, ..ON }, true, false);
        assert_eq!(no_mic.notice, vec![Kind::Microphone]);
        assert_eq!(no_mic.sentence(false).as_deref(), Some("macOS no longer lets EchoLedger use the microphone."));
    }

    #[test]
    fn the_sentence_is_one_calm_line() {
        let two = Memory { granted: Vec::new(), notice: vec![Kind::SystemAudio, Kind::Microphone], ..Memory::default() };
        let line = two.sentence(true).unwrap();
        assert!(line.contains("the microphone and system audio"), "{line}");
        // One short sentence on the banner (its Set up button opens the steps).
        assert_eq!(line, "macOS no longer lets EchoLedger use the microphone and system audio.");
        assert_eq!(Memory::default().sentence(true), None);
    }

    #[test]
    fn the_memory_survives_a_restart_and_a_broken_file_is_empty() {
        let dir = tempfile::tempdir().unwrap();
        let path = Memory::path(dir.path());
        assert_eq!(Memory::load(&path), Memory::default());
        let memory = Memory { granted: vec![Kind::Microphone], notice: vec![Kind::Screen], reopen_since: Some(7), ..Memory::default() };
        memory.save(&path);
        assert_eq!(Memory::load(&path), memory);
        std::fs::write(&path, "not json").unwrap();
        assert_eq!(Memory::load(&path), Memory::default());
    }

    /// Where the app locks a mutex field: per function, the names it calls (fn and method calls, by name) and whether
    /// it locks the field itself.
    struct Locks<'f> {
        field: &'f str,
        calls: std::collections::BTreeMap<String, std::collections::BTreeSet<String>>,
        locks: std::collections::BTreeSet<String>,
        /// How many functions of the app have each name: only a name defined once is followed.
        defined: std::collections::BTreeMap<String, usize>,
        current: Vec<String>,
    }

    /// `x.<field>.lock()`.
    fn locks_field(e: &syn::Expr, field: &str) -> bool {
        matches!(e, syn::Expr::MethodCall(m) if m.method == "lock" && matches!(&*m.receiver, syn::Expr::Field(f) if matches!(&f.member, syn::Member::Named(n) if n == field)))
    }

    /// The names of the calls in an expression or statement, and how many times it locks the field.
    #[derive(Default)]
    struct CallsIn<'f> {
        field: &'f str,
        names: Vec<String>,
        field_locks: usize,
        /// Leave nested blocks out: their statements are read on their own (a guard made in one dies at its end).
        shallow: bool,
        depth: usize,
    }

    impl<'ast> syn::visit::Visit<'ast> for CallsIn<'_> {
        fn visit_expr_method_call(&mut self, m: &'ast syn::ExprMethodCall) {
            if locks_field(&syn::Expr::MethodCall(m.clone()), self.field) {
                self.field_locks += 1;
            }
            self.names.push(m.method.to_string());
            syn::visit::visit_expr_method_call(self, m);
        }
        fn visit_expr_call(&mut self, c: &'ast syn::ExprCall) {
            if let syn::Expr::Path(p) = &*c.func {
                self.names.extend(p.path.segments.last().map(|s| s.ident.to_string()));
            }
            syn::visit::visit_expr_call(self, c);
        }
        // A closure runs later, not where its guard lives.
        fn visit_expr_closure(&mut self, _: &'ast syn::ExprClosure) {}
        fn visit_block(&mut self, b: &'ast syn::Block) {
            if self.shallow && self.depth > 0 {
                return;
            }
            self.depth += 1;
            syn::visit::visit_block(self, b);
            self.depth -= 1;
        }
    }

    fn calls_in<'f>(field: &'f str, visit: impl FnOnce(&mut CallsIn<'f>)) -> CallsIn<'f> {
        let mut found = CallsIn { field, ..Default::default() };
        visit(&mut found);
        found
    }

    impl<'ast> syn::visit::Visit<'ast> for Locks<'_> {
        fn visit_item_mod(&mut self, m: &'ast syn::ItemMod) {
            let test = m.attrs.iter().any(|a| a.path().is_ident("cfg") && a.parse_args::<syn::Ident>().is_ok_and(|i| i == "test"));
            if !test {
                syn::visit::visit_item_mod(self, m);
            }
        }
        fn visit_item_fn(&mut self, f: &'ast syn::ItemFn) {
            self.function(f.sig.ident.to_string(), |v| syn::visit::visit_item_fn(v, f), |c| syn::visit::Visit::visit_block(c, &f.block));
        }
        fn visit_impl_item_fn(&mut self, f: &'ast syn::ImplItemFn) {
            self.function(f.sig.ident.to_string(), |v| syn::visit::visit_impl_item_fn(v, f), |c| syn::visit::Visit::visit_block(c, &f.block));
        }
    }

    impl<'f> Locks<'f> {
        fn function<'a>(&mut self, name: String, visit: impl FnOnce(&mut Self), body: impl FnOnce(&mut CallsIn<'f>)) {
            let found = calls_in(self.field, body);
            if found.field_locks > 0 {
                self.locks.insert(name.clone());
            }
            *self.defined.entry(name.clone()).or_default() += 1;
            self.calls.entry(name.clone()).or_default().extend(found.names);
            self.current.push(name);
            visit(self);
            self.current.pop();
        }

        /// Every function that locks the field, itself or through calls (by name; a name the app gives several
        /// functions, `run` or `new`, is not followed: the lockers have their own).
        fn lockers(&self) -> std::collections::BTreeSet<String> {
            let mut found = self.locks.clone();
            loop {
                let more: Vec<String> = self
                    .calls
                    .iter()
                    .filter(|(f, c)| !found.contains(*f) && self.defined.get(*f) == Some(&1) && c.iter().any(|n| found.contains(n)))
                    .map(|(f, _)| f.clone())
                    .collect();
                if more.is_empty() {
                    return found;
                }
                found.extend(more);
            }
        }
    }

    /// `<field>.lock()` bound by a `let` (with `.unwrap…`/`.expect…` after it): the guard lives to the end of the block.
    fn binds_guard(local: &syn::Local, field: &str) -> bool {
        let Some(init) = &local.init else { return false };
        let mut e = &*init.expr;
        while let syn::Expr::MethodCall(m) = e {
            if locks_field(e, field) {
                return true;
            }
            if !["unwrap_or_else", "unwrap", "expect"].contains(&m.method.to_string().as_str()) {
                return false;
            }
            e = &m.receiver;
        }
        false
    }

    /// The app's own sources, parsed (`tests.rs` files left out).
    fn app_files() -> Vec<(String, syn::File)> {
        fn files(dir: &std::path::Path, out: &mut Vec<(String, syn::File)>) {
            for entry in std::fs::read_dir(dir).unwrap().flatten() {
                let path = entry.path();
                if path.is_dir() {
                    files(&path, out);
                } else if path.extension().is_some_and(|e| e == "rs") && path.file_name().is_some_and(|n| n != "tests.rs") {
                    let name = path.display().to_string();
                    out.push((name.rsplit("/src/").next().unwrap_or(&name).to_string(), syn::parse_file(&std::fs::read_to_string(&path).unwrap()).unwrap()));
                }
            }
        }
        let mut all = Vec::new();
        files(&std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("src"), &mut all);
        all
    }

    /// The app's sources: [`guards_alive_in`].
    fn guards_alive_while_locked_again(field: &str) -> (Vec<String>, usize) {
        guards_alive_in(&app_files(), field)
    }

    /// The calls (by name) anywhere in an expression, nested blocks included.
    fn all_calls_in(field: &str, e: &syn::Expr) -> Vec<String> {
        calls_in(field, |c| syn::visit::Visit::visit_expr(c, e)).names
    }

    /// How many times an expression itself locks the field (its nested blocks left out).
    fn locks_in(field: &str, e: &syn::Expr) -> usize {
        calls_in(field, |c| {
            (c.shallow, c.depth) = (true, 1);
            syn::visit::Visit::visit_expr(c, e)
        })
        .field_locks
    }

    /// Every place a guard on `field` is alive while something that locks `field` again runs (pure, over parsed
    /// files): a temporary guard and such a call in one statement (a struct literal's fields, an argument list); a
    /// bound guard and such a call later in its block; or a guard in an `if let`, `while let` or `match` scrutinee and
    /// such a call in the block it guards (edition 2024: an `if let`'s guard is gone in its `else`, a `match`'s lives
    /// through every arm). Gives the findings and how many locks of the field it saw.
    fn guards_alive_in(all: &[(String, syn::File)], field: &str) -> (Vec<String>, usize) {
        let mut locks = Locks { field, calls: Default::default(), locks: Default::default(), defined: Default::default(), current: Vec::new() };
        for (_, file) in all {
            syn::visit::Visit::visit_file(&mut locks, file);
        }
        let lockers = locks.lockers();

        struct Blocks<'a, 'f> {
            field: &'f str,
            lockers: &'a std::collections::BTreeSet<String>,
            file: String,
            found: Vec<String>,
            sites: usize,
        }
        impl<'ast> syn::visit::Visit<'ast> for Blocks<'_, '_> {
            fn visit_item_mod(&mut self, m: &'ast syn::ItemMod) {
                let test = m.attrs.iter().any(|a| a.path().is_ident("cfg") && a.parse_args::<syn::Ident>().is_ok_and(|i| i == "test"));
                if !test {
                    syn::visit::visit_item_mod(self, m);
                }
            }
            fn visit_expr_if(&mut self, e: &'ast syn::ExprIf) {
                if let syn::Expr::Let(l) = &*e.cond {
                    let guarded = syn::Expr::Block(syn::ExprBlock { attrs: Vec::new(), label: None, block: e.then_branch.clone() });
                    self.scrutinee("if let", &l.expr, &[&guarded]);
                }
                syn::visit::visit_expr_if(self, e);
            }
            fn visit_expr_while(&mut self, e: &'ast syn::ExprWhile) {
                if let syn::Expr::Let(l) = &*e.cond {
                    let guarded = syn::Expr::Block(syn::ExprBlock { attrs: Vec::new(), label: None, block: e.body.clone() });
                    self.scrutinee("while let", &l.expr, &[&guarded]);
                }
                syn::visit::visit_expr_while(self, e);
            }
            fn visit_expr_match(&mut self, e: &'ast syn::ExprMatch) {
                let arms: Vec<&syn::Expr> = e.arms.iter().map(|a| &*a.body).collect();
                self.scrutinee("match", &e.expr, &arms);
                syn::visit::visit_expr_match(self, e);
            }
            fn visit_block(&mut self, block: &'ast syn::Block) {
                for (i, stmt) in block.stmts.iter().enumerate() {
                    let here = calls_in(self.field, |c| {
                        (c.shallow, c.depth) = (true, 1);
                        syn::visit::Visit::visit_stmt(c, stmt)
                    });
                    if here.field_locks == 0 {
                        continue;
                    }
                    self.sites += here.field_locks;
                    let bound = matches!(stmt, syn::Stmt::Local(l) if binds_guard(l, self.field));
                    let again: Vec<String> = if bound {
                        // Alive to the end of the block.
                        block.stmts[i + 1..].iter().flat_map(|s| calls_in(self.field, |c| syn::visit::Visit::visit_stmt(c, s)).names).filter(|n| self.lockers.contains(n)).collect()
                    } else {
                        here.names.iter().filter(|n| self.lockers.contains(*n)).cloned().collect()
                    };
                    let twice = if bound { 0 } else { here.field_locks.saturating_sub(1) };
                    if !again.is_empty() || twice > 0 {
                        let text = quote::ToTokens::to_token_stream(stmt).to_string();
                        self.found.push(format!("{}: {} lock again while its guard lives ({:?}{}): {}", self.file, self.field, again, if twice > 0 { ", locked twice in one statement" } else { "" }, &text[..text.len().min(160)]));
                    }
                }
                syn::visit::visit_block(self, block);
            }
        }
        impl Blocks<'_, '_> {
            /// A scrutinee that locks the field: its guard lives through `guarded`.
            fn scrutinee(&mut self, kind: &str, scrutinee: &syn::Expr, guarded: &[&syn::Expr]) {
                if locks_in(self.field, scrutinee) == 0 {
                    return;
                }
                let again: Vec<String> = guarded.iter().flat_map(|e| all_calls_in(self.field, e)).filter(|n| self.lockers.contains(n)).collect();
                let twice = guarded.iter().any(|e| calls_in(self.field, |c| syn::visit::Visit::visit_expr(c, e)).field_locks > 0);
                if !again.is_empty() || twice {
                    let text = quote::ToTokens::to_token_stream(scrutinee).to_string();
                    self.found.push(format!("{}: {} lock again while its {kind} guard lives ({again:?}{}): {}", self.file, self.field, if twice { ", locked again inside" } else { "" }, &text[..text.len().min(160)]));
                }
            }
        }
        let mut blocks = Blocks { field, lockers: &lockers, file: String::new(), found: Vec::new(), sites: 0 };
        for (path, file) in all {
            blocks.file.clone_from(path);
            syn::visit::Visit::visit_file(&mut blocks, file);
        }
        (blocks.found, blocks.sites)
    }

    /// The app's two lockers as they are, then `body`: what the checker says about it.
    fn checked(body: &str) -> Vec<String> {
        let source = format!(
            "impl AppState {{
                fn permission_memory_now(&self) -> Memory {{ self.permission_memory.lock().unwrap_or_else(|p| p.into_inner()).clone() }}
                fn permissions_allowed_before(&self, now: &Permissions) -> Vec<Kind> {{ self.permission_memory_now().allowed_before(now, true) }}
            }}
            {body}"
        );
        guards_alive_in(&[("sample.rs".into(), syn::parse_file(&source).unwrap())], "permission_memory").0
    }

    #[test]
    fn the_checker_reports_a_guard_alive_while_the_memory_is_locked_again() {
        // The struct literal that froze the app.
        let literal = checked(
            "fn status(state: &AppState, now: &Permissions) -> Status {
                Status {
                    lost: state.permission_memory.lock().unwrap_or_else(|p| p.into_inner()).sentence(true),
                    allowed_before: state.permissions_allowed_before(now),
                }
            }",
        );
        assert_eq!(literal.len(), 1, "{literal:?}");
        assert!(literal[0].contains("permissions_allowed_before"), "{literal:?}");
        // A guard bound by a let, then a locker later in its block.
        let bound = checked(
            "fn status(state: &AppState, now: &Permissions) {
                let memory = state.permission_memory.lock().unwrap();
                let lost = memory.sentence(true);
                let before = state.permissions_allowed_before(now);
            }",
        );
        assert_eq!(bound.len(), 1, "{bound:?}");
        // Guards in a scrutinee: if let, while let, match.
        for body in [
            "fn f(state: &AppState, now: &Permissions) { if let Some(k) = state.permission_memory.lock().unwrap().notice.first() { state.permissions_allowed_before(now); } }",
            "fn f(state: &AppState, now: &Permissions) { while let Some(k) = state.permission_memory.lock().unwrap().notice.pop() { state.permissions_allowed_before(now); } }",
            "fn f(state: &AppState, now: &Permissions) { match state.permission_memory.lock().unwrap().notice.len() { 0 => {} _ => { state.permissions_allowed_before(now); } } }",
            "fn f(state: &AppState) { match state.permission_memory.lock().unwrap().notice.len() { 0 => {} _ => { let m = state.permission_memory.lock().unwrap(); } } }",
        ] {
            assert_eq!(checked(body).len(), 1, "{body}");
        }
    }

    #[test]
    fn the_checker_passes_copies_and_guards_that_are_gone() {
        // Two copies in one literal: each lock is let go before the next.
        assert!(checked(
            "fn status(state: &AppState, now: &Permissions) -> Status {
                Status { lost: state.permission_memory_now().sentence(true), seen: state.permission_memory_now().seen() }
            }"
        )
        .is_empty());
        // A guard in its own statement, then the locker; and an if let's guard is gone in its else (edition 2024).
        assert!(checked(
            "fn status(state: &AppState, now: &Permissions) {
                let lost = state.permission_memory.lock().unwrap().sentence(true);
                let before = state.permissions_allowed_before(now);
                if let Some(k) = state.permission_memory.lock().unwrap().notice.first() { } else { state.permissions_allowed_before(now); }
            }"
        )
        .is_empty());
    }

    #[test]
    fn no_permission_memory_guard_lives_while_it_is_locked_again() {
        // A guard left alive in permissions_status's struct literal while a later field locked the memory again froze
        // the app: a std mutex never lets its own holder in. Each read takes a copy (permission_memory_now) instead.
        let (found, sites) = guards_alive_while_locked_again("permission_memory");
        assert!(found.is_empty(), "{}", found.join("\n"));
        assert!(sites >= 3, "the check sees the memory's locks ({sites})");
    }
}
