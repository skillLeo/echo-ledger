//! Never miss a recording (Phase 1C, A4 and A5): Record now chooses between a call and a voice note; the watchdog starts
//! a detected meeting's recording itself when it has not started after 5 seconds ("Recording started (backup)"); after
//! each call both sides are checked, and a call where the other side was never heard says "Only your voice was
//! recorded", why, and how to fix it. Pure and tested; actions.rs and detect/mod.rs act on the answers.

use crate::detect::classify::SourceApp;
use crate::permissions::{Access, Permissions};

/// A meeting detected this long without a recording is started by the watchdog.
pub const BACKUP_AFTER_MS: u64 = 5_000;
/// Calls shorter than this are not checked for a silent side (a call that rang out, a quick hello).
pub const CHECK_SIDES_FROM_MS: u64 = 20_000;

pub const BACKUP_TITLE: &str = "Recording started (backup)";
pub const BACKUP_BODY: &str = "A meeting was on but its recording had not started after 5 seconds, so EchoLedger started it.";

/// What Record now records.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum RecordNow {
    Stop,
    /// A meeting or call is on: call capture, both sides.
    Call(SourceApp),
    VoiceNote,
}

/// Record now (pure): stops a running recording, records the meeting on screen with both sides, else a voice note.
pub fn record_now(recording: bool, meeting: Option<SourceApp>) -> RecordNow {
    match (recording, meeting) {
        (true, _) => RecordNow::Stop,
        (false, Some(app)) => RecordNow::Call(app),
        (false, None) => RecordNow::VoiceNote,
    }
}

/// The watchdog for one meeting: fires once when the meeting has been detected for [`BACKUP_AFTER_MS`] and nothing
/// recorded it at any moment since (a recording the person stopped is never started again).
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct Watchdog {
    since_ms: Option<u64>,
    seen_recording: bool,
    fired: bool,
}

impl Watchdog {
    /// `detected` is the meeting auto mode would record now (none when auto mode is off or nothing qualifies).
    pub fn tick(&mut self, now_ms: u64, detected: Option<SourceApp>, recording: bool) -> Option<SourceApp> {
        let Some(app) = detected else {
            *self = Self::default();
            return None;
        };
        let since = *self.since_ms.get_or_insert(now_ms);
        self.seen_recording |= recording;
        if self.seen_recording || self.fired || now_ms.saturating_sub(since) < BACKUP_AFTER_MS {
            return None;
        }
        self.fired = true;
        Some(app)
    }
}

/// What the after-call check says: a title, why, and how to fix it.
#[derive(Clone, Debug, PartialEq, Eq, serde::Serialize)]
#[serde(rename_all = "camelCase")]
pub struct OneSided {
    pub title: &'static str,
    pub reason: &'static str,
    pub fix: &'static str,
}

const ONLY_YOU: &str = "Only your voice was recorded";
const ONLY_THEM: &str = "Only the other side was recorded";

/// The after-call check (pure): a call of at least [`CHECK_SIDES_FROM_MS`] where one side was never heard.
/// `heard` is (this side, the other side).
pub fn one_sided(duration_ms: u64, heard: (bool, bool), permissions: &Permissions, whole_system: bool) -> Option<OneSided> {
    if duration_ms < CHECK_SIDES_FROM_MS {
        return None;
    }
    match heard {
        (true, false) if permissions.system_audio == Access::Denied => Some(OneSided {
            title: ONLY_YOU,
            reason: "EchoLedger may not record this computer's sound.",
            fix: "Allow it in System Settings → Privacy & Security → Screen & System Audio Recording, then run the Ready check on the Record page.",
        }),
        (true, false) if whole_system => Some(OneSided {
            title: ONLY_YOU,
            reason: "Nothing was heard from this computer's sound: the call may have played through a headset or device that is not the default output.",
            fix: "Make the device you hear calls on the default output in Sound settings, then play the test sound in Settings → Recording.",
        }),
        (true, false) => Some(OneSided {
            title: ONLY_YOU,
            reason: "Nothing was heard from the call app: the other side was muted, or the call played through another app or device.",
            fix: "Play the test sound in Settings → Recording to check the other side, and run the Ready check on the Record page.",
        }),
        (false, true) if permissions.microphone == Access::Denied => Some(OneSided {
            title: ONLY_THEM,
            reason: "EchoLedger may not use the microphone.",
            fix: "Allow the microphone for EchoLedger in your computer's privacy settings, then run the Ready check on the Record page.",
        }),
        (false, true) => Some(OneSided {
            title: ONLY_THEM,
            reason: "Your microphone was silent: it may be muted, or another microphone was used for the call.",
            fix: "Choose the microphone you speak into in Settings → Recording and try the test recording.",
        }),
        _ => None,
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    const ALLOWED: Permissions = Permissions { microphone: Access::Allowed, system_audio: Access::Allowed, screen: Access::Allowed };

    #[test]
    fn record_now_records_the_meeting_on_screen_else_a_voice_note() {
        assert_eq!(record_now(false, Some(SourceApp::Zoom)), RecordNow::Call(SourceApp::Zoom));
        assert_eq!(record_now(false, None), RecordNow::VoiceNote);
        assert_eq!(record_now(true, Some(SourceApp::Zoom)), RecordNow::Stop, "pressed again, it stops");
    }

    #[test]
    fn the_watchdog_starts_a_meeting_that_did_not_start_after_5_seconds_once() {
        let mut dog = Watchdog::default();
        assert_eq!(dog.tick(0, Some(SourceApp::GoogleMeet), false), None);
        assert_eq!(dog.tick(4_000, Some(SourceApp::GoogleMeet), false), None);
        assert_eq!(dog.tick(5_000, Some(SourceApp::GoogleMeet), false), Some(SourceApp::GoogleMeet));
        assert_eq!(dog.tick(6_000, Some(SourceApp::GoogleMeet), false), None, "once per meeting");
        assert_eq!(dog.tick(7_000, None, false), None);
        assert_eq!(dog.tick(8_000, Some(SourceApp::Zoom), false), None, "a new meeting starts its own 5 seconds");
        assert_eq!(dog.tick(13_000, Some(SourceApp::Zoom), false), Some(SourceApp::Zoom));
    }

    #[test]
    fn the_watchdog_leaves_a_recording_alone_and_never_restarts_one_the_person_stopped() {
        let mut dog = Watchdog::default();
        dog.tick(0, Some(SourceApp::MicrosoftTeams), false);
        assert_eq!(dog.tick(3_000, Some(SourceApp::MicrosoftTeams), true), None, "started normally");
        assert_eq!(dog.tick(9_000, Some(SourceApp::MicrosoftTeams), false), None, "stopped by the person: stays stopped");
    }

    #[test]
    fn only_your_voice_says_why_and_how_to_fix_it() {
        let said = one_sided(60_000, (true, false), &ALLOWED, false).unwrap();
        assert_eq!(said.title, "Only your voice was recorded");
        assert!(said.reason.contains("call app"));
        let denied = Permissions { system_audio: Access::Denied, ..ALLOWED };
        assert!(one_sided(60_000, (true, false), &denied, false).unwrap().fix.contains("Screen & System Audio Recording"));
        assert!(one_sided(60_000, (true, false), &ALLOWED, true).unwrap().fix.contains("default output"));
        assert_eq!(one_sided(60_000, (false, true), &ALLOWED, false).unwrap().title, "Only the other side was recorded");
    }

    #[test]
    fn a_call_with_both_sides_or_a_short_one_says_nothing() {
        assert_eq!(one_sided(60_000, (true, true), &ALLOWED, false), None);
        assert_eq!(one_sided(10_000, (true, false), &ALLOWED, false), None);
        assert_eq!(one_sided(60_000, (false, false), &ALLOWED, false), None, "nothing at all is the silent-recording message's");
    }
}
