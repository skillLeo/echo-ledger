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
    Missing { kind, sentence: sentence.to_string() }
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
}

const KINDS: [Kind; 3] = [Kind::Microphone, Kind::SystemAudio, Kind::Screen];

impl Memory {
    pub fn path(data_dir: &Path) -> PathBuf {
        data_dir.join("permissions.json")
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
    /// moments are wanted: nobody needs to hear about what they do not use.
    pub fn observe(&self, now: &Permissions, screens_wanted: bool) -> Memory {
        let mut next = self.clone();
        for kind in KINDS {
            let had = next.granted.contains(&kind);
            if now.of(kind) == Access::Allowed {
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

    /// The one calm sentence about what went; none when nothing did.
    pub fn sentence(&self) -> Option<String> {
        let names: Vec<&str> = KINDS
            .into_iter()
            .filter(|k| self.notice.contains(k))
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
        Some(format!("macOS no longer lets EchoLedger use {list}, which can happen after an update. Setting it up again takes a moment."))
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
        let start = Memory::default().observe(&ON, true);
        assert_eq!(start.notice, Vec::<Kind>::new());
        assert_eq!(start.granted.len(), 3);

        // An update: macOS forgot the microphone.
        let lost = start.observe(&Permissions { microphone: Access::Unknown, ..ON }, true);
        assert_eq!(lost.notice, vec![Kind::Microphone]);
        assert!(lost.sentence().unwrap().contains("the microphone"));

        // The person dismissed it; the next starts say nothing more.
        let dismissed = Memory { notice: Vec::new(), ..lost };
        let later = dismissed.observe(&Permissions { microphone: Access::Unknown, ..ON }, true);
        assert_eq!(later.notice, Vec::<Kind>::new(), "no nagging");

        // Granted again, then lost again: that is a new loss.
        let again = later.observe(&ON, true);
        assert!(again.granted.contains(&Kind::Microphone));
        let lost_again = again.observe(&Permissions { microphone: Access::Denied, ..ON }, true);
        assert_eq!(lost_again.notice, vec![Kind::Microphone]);
    }

    #[test]
    fn a_permission_granted_again_leaves_the_notice_by_itself() {
        let lost = Memory::default().observe(&ON, true).observe(&Permissions { system_audio: Access::Denied, ..ON }, true);
        assert_eq!(lost.notice, vec![Kind::SystemAudio]);
        assert_eq!(lost.observe(&ON, true).notice, Vec::<Kind>::new());
    }

    #[test]
    fn screen_recording_counts_only_while_screen_moments_are_wanted() {
        let start = Memory::default().observe(&ON, false);
        let without = start.observe(&Permissions { screen: Access::Denied, ..ON }, false);
        assert_eq!(without.notice, Vec::<Kind>::new());
        assert!(!without.granted.contains(&Kind::Screen), "forgotten: a later grant counts afresh");
        assert_eq!(start.observe(&Permissions { screen: Access::Denied, ..ON }, true).notice, vec![Kind::Screen]);
    }

    #[test]
    fn something_never_granted_was_not_lost() {
        let first = Memory::default().observe(&Permissions { microphone: Access::Unknown, system_audio: Access::Denied, screen: Access::Denied }, true);
        assert_eq!(first, Memory::default());
    }

    #[test]
    fn the_sentence_is_one_calm_line() {
        let two = Memory { granted: Vec::new(), notice: vec![Kind::SystemAudio, Kind::Microphone] };
        let line = two.sentence().unwrap();
        assert!(line.contains("the microphone and system audio"), "{line}");
        assert_eq!(line.matches('.').count(), 2);
        assert_eq!(Memory::default().sentence(), None);
    }

    #[test]
    fn the_memory_survives_a_restart_and_a_broken_file_is_empty() {
        let dir = tempfile::tempdir().unwrap();
        let path = Memory::path(dir.path());
        assert_eq!(Memory::load(&path), Memory::default());
        let memory = Memory { granted: vec![Kind::Microphone], notice: vec![Kind::Screen] };
        memory.save(&path);
        assert_eq!(Memory::load(&path), memory);
        std::fs::write(&path, "not json").unwrap();
        assert_eq!(Memory::load(&path), Memory::default());
    }
}
