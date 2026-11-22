//! The call choice (docs/v2/copy/call-choice.md): what the small window beside the recording pill shows once a call
//! ends. "After each call" is Ask me (default), Transcribe every call, Save audio only or Don't record calls; with Ask me,
//! "When to ask" is after the call (default: the card) or at the start (a 5 s banner by the pill, `start_banner`).
//!
//! - A choice made from the pill during the call (Transcribe this call, Save audio only, Stop and delete) means no card.
//! - The card (Ask me, after the call, no choice made during it) waits 8 s ([`CARD_SECONDS`]); with no choice, its ×,
//!   Esc or a swipe the call is saved as audio only.
//! - After any choice, one confirmation with Undo for 5 s ([`CONFIRM_SECONDS`]): "Transcribing…", "Saved as audio ·
//!   Transcribe" or "Deleted". The choice itself is applied only once that time is over (`pending::DecisionFiles::choose`,
//!   `discard_later`), so Undo really undoes all three: the card comes back and nothing was uploaded or deleted.
//! - Back-to-back calls: one grouped card ("2 calls saved as audio · Review"), never a stack.
//! - Quiet hours (Settings › Notifications): no card; the call is saved as audio only and waits in the Library.
//!
//! [`outcome`] and [`Surfaces`] are plain data (unit-tested below); `actions` adds the files, timers and events.

use std::collections::{HashMap, HashSet};
use std::time::{Duration, Instant};

use serde::{Deserialize, Serialize};

use crate::audio::RecordingMode;
use crate::pending::{PendingDecision, PendingItem};
use crate::settings::{AfterCall, AfterEachCall, AskWhen};

/// How long the card waits for a choice before it saves the audio only (it was 12 s before the call choice).
pub const CARD_SECONDS: i64 = 8;
/// How long a confirmation and its Undo stay; the choice is applied when this time is over.
pub const CONFIRM_SECONDS: u64 = 5;
/// How long the grouped card stays.
pub const GROUP_SECONDS: u64 = 8;
/// How long the "Recording this call · Don't record" banner stays beside the pill ("At the start of the call").
pub const START_BANNER_SECONDS: u64 = 5;
/// A deleted recording's files stay this much longer than its confirmation, so an Undo pressed at the last moment still
/// finds them.
pub const DISCARD_SLACK: Duration = Duration::from_secs(2);
/// How long a recording that finished uploading can still be undone (kept for an upload that raced an older Undo).
pub const UNDO_UPLOADED_FOR: Duration = Duration::from_secs(60);
/// The choices in a row after which the confirmation offers "Always do this?" (once).
pub const ALWAYS_AFTER: u8 = 3;

/// What happens to a call: the three choices of the card and the pill menu (`delete` is "Stop and delete" on the pill).
#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum CallChoice {
    Transcribe,
    Audio,
    Delete,
}

impl CallChoice {
    /// The "After each call" value that makes this choice every time ("Always do this?").
    pub fn always(self) -> AfterEachCall {
        match self {
            CallChoice::Transcribe => AfterEachCall::AlwaysTranscribe,
            CallChoice::Audio => AfterEachCall::AlwaysAudio,
            CallChoice::Delete => AfterEachCall::Off,
        }
    }
}

/// The same choice made on the card this many times in a row (`Settings.card_streak`).
#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct CardStreak {
    pub choice: CallChoice,
    pub count: u8,
}

/// The streak after `choice` (pure): one more of the same, else a new streak of one.
pub fn next_streak(previous: Option<CardStreak>, choice: CallChoice) -> CardStreak {
    match previous {
        Some(s) if s.choice == choice => CardStreak { choice, count: s.count.saturating_add(1) },
        _ => CardStreak { choice, count: 1 },
    }
}

/// Whether the confirmation offers "Always do this?" now (pure): the same choice [`ALWAYS_AFTER`] times in a row on the
/// card, never offered before, and the person still asked after each call.
pub fn offers_always(streak: CardStreak, offered_before: bool, now: AfterEachCall) -> Option<AfterEachCall> {
    (streak.count >= ALWAYS_AFTER && !offered_before && now == AfterEachCall::Ask).then(|| streak.choice.always())
}

/// "Transcribing…", "Saved as audio · Transcribe", "Deleted", each with Undo (`AfterCallState.done`).
#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct CallDone {
    pub kind: CallChoice,
    /// The recording: its folder name, which is also its id in the upload queue.
    pub id: String,
    pub title: Option<String>,
    /// Who the call was with (a meeting window's title).
    pub caller_name: Option<String>,
    pub source_app: String,
    pub mode: RecordingMode,
    pub duration_ms: u64,
    /// RFC 3339: when the confirmation goes and the choice is applied.
    pub until: String,
    /// "Always do this?" [Yes]: the After each call value Yes sets; offered once.
    pub offer_always: Option<AfterEachCall>,
}

impl CallDone {
    /// The confirmation of `kind` for a recording, shown at `now` for [`CONFIRM_SECONDS`].
    pub fn of(decision: &PendingDecision, kind: CallChoice, now: chrono::DateTime<chrono::Utc>) -> Self {
        Self {
            kind,
            id: decision.id.clone(),
            title: decision.title.clone(),
            caller_name: decision.caller_name().map(str::to_string),
            source_app: decision.source_app.clone(),
            mode: decision.mode,
            duration_ms: decision.duration_ms,
            until: (now + chrono::Duration::seconds(CONFIRM_SECONDS as i64)).to_rfc3339(),
            offer_always: None,
        }
    }
}

/// "3 calls saved as audio · Review" (`AfterCallState.group`).
#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct CallGroup {
    pub count: usize,
    /// RFC 3339: when the grouped card goes.
    pub until: String,
}

/// What the after-call window shows (`aftercall:state`, `after_call_state`): one thing at a time, the card, one
/// confirmation or the grouped card, never two.
#[derive(Clone, Debug, Default, PartialEq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct AfterCallState {
    /// The card: the call waiting for a choice; its `deadlineAt` is when it saves the audio only.
    pub ask: Option<PendingItem>,
    /// The card's first time ever: "You can change this in Settings › After each call."
    pub hint: bool,
    pub done: Option<CallDone>,
    pub group: Option<CallGroup>,
}

impl AfterCallState {
    pub fn is_empty(&self) -> bool {
        self.ask.is_none() && self.done.is_none() && self.group.is_none()
    }
}

/// What a finished recording does next.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Outcome {
    /// Kept at once without a word: transcribed, or audio only (a Live Assist prep's call, a quit, quiet hours).
    KeepNow { transcribe: bool },
    /// Waits in the Library for the person (Ask me, while the app quits).
    Wait,
    /// Chosen already (the pill, the setting, a voice note, "At the start"): the confirmation with Undo for 5 s, then
    /// applied.
    Confirm(CallChoice),
    /// The card asks (Ask me, after the call).
    Ask,
}

/// What `outcome` reads.
#[derive(Clone, Copy, Debug)]
pub struct OutcomeInput {
    pub setting: AfterCall,
    pub ask_when: AskWhen,
    pub mode: RecordingMode,
    /// Chosen from the pill during the call.
    pub pill: Option<CallChoice>,
    /// A Live Assist prep's session keeps its call without a question (docs/API_ASSIST.md `auto_saved`).
    pub assist_saved: bool,
    /// False while the app quits: nothing is shown.
    pub interactive: bool,
    /// Inside the quiet hours (Settings › Notifications).
    pub quiet: bool,
}

/// What a finished recording does next (pure). Calls follow "After each call"; voice notes were stopped on purpose
/// and are transcribed; a choice made on the pill wins over both.
pub fn outcome(input: OutcomeInput) -> Outcome {
    if input.assist_saved {
        return Outcome::KeepNow { transcribe: true };
    }
    let chosen = if let Some(pill) = input.pill {
        Some(pill)
    } else if input.mode != RecordingMode::Call {
        Some(CallChoice::Transcribe)
    } else {
        match input.setting {
            AfterCall::AlwaysTranscribe => Some(CallChoice::Transcribe),
            AfterCall::AlwaysAudio => Some(CallChoice::Audio),
            AfterCall::Ask => None,
        }
    };
    match chosen {
        // Delete was asked for: nothing is kept, also while quitting (the files go at the next start, `discard_due`).
        Some(CallChoice::Delete) => Outcome::Confirm(CallChoice::Delete),
        Some(choice) if !input.interactive => Outcome::KeepNow { transcribe: choice == CallChoice::Transcribe },
        // Quiet hours: a choice the setting made is applied without a word; one made on the pill is confirmed.
        Some(choice) if input.quiet && input.pill.is_none() => Outcome::KeepNow { transcribe: choice == CallChoice::Transcribe },
        Some(choice) => Outcome::Confirm(choice),
        None if !input.interactive => Outcome::Wait,
        None if input.quiet => Outcome::KeepNow { transcribe: false },
        // Asked at the start already: doing nothing kept it recording, and without a choice it is saved as audio.
        None if input.ask_when == AskWhen::Start => Outcome::Confirm(CallChoice::Audio),
        None => Outcome::Ask,
    }
}

/// Whether the "Recording this call · Don't record" banner shows when a recording starts (pure): an automatic call
/// recording under Ask me, asked at the start.
pub fn start_banner(after: AfterEachCall, ask_when: AskWhen, auto: bool, mode: RecordingMode) -> bool {
    after == AfterEachCall::Ask && ask_when == AskWhen::Start && auto && mode == RecordingMode::Call
}

/// Where a call that would ask goes, given what is on screen.
#[derive(Clone, Debug, PartialEq, Eq)]
pub enum Arrival {
    /// Its card shows.
    Card,
    /// Back to back: grouped. `earlier` is the call whose card was counting (saved as audio now too).
    Group { earlier: Option<String> },
}

#[derive(Clone, Debug)]
enum Shown {
    Card { id: String, hint: bool },
    Done { done: CallDone, until: Instant },
    Group { ids: Vec<String>, until: Instant },
}

/// The core's side of the after-call window (managed state): what it shows, one at a time.
#[derive(Debug, Default)]
pub struct Surfaces {
    shown: Option<Shown>,
    /// Recently kept recordings that finished uploading: their server session, for an Undo that raced the upload.
    uploaded: HashMap<String, (String, Instant)>,
    /// Recordings undone while an upload may still be running for them.
    undone: HashSet<String>,
}

impl Surfaces {
    /// The call whose card is shown.
    pub fn card(&self) -> Option<&str> {
        match &self.shown {
            Some(Shown::Card { id, .. }) => Some(id),
            _ => None,
        }
    }

    /// The card shows the first-time hint.
    pub fn hint(&self) -> bool {
        matches!(&self.shown, Some(Shown::Card { hint: true, .. }))
    }

    /// The confirmation while it is on screen.
    pub fn done(&self, now: Instant) -> Option<&CallDone> {
        match &self.shown {
            Some(Shown::Done { done, until }) if *until > now => Some(done),
            _ => None,
        }
    }

    /// How many calls the grouped card counts, while it is on screen.
    pub fn group(&self, now: Instant) -> Option<usize> {
        match &self.shown {
            Some(Shown::Group { ids, until }) if *until > now => Some(ids.len()),
            _ => None,
        }
    }

    /// How many calls the grouped card counts and how long it stays, while it is on screen.
    pub fn group_left(&self, now: Instant) -> Option<(usize, Duration)> {
        match &self.shown {
            Some(Shown::Group { ids, until }) if *until > now => Some((ids.len(), until.saturating_duration_since(now))),
            _ => None,
        }
    }

    /// A call that would ask (`id`): its card shows, unless another card is counting or the grouped card is up. Then the
    /// calls are grouped: never two cards, never a stack.
    pub fn arrive(&mut self, id: &str, hint: bool, now: Instant) -> Arrival {
        let until = now + Duration::from_secs(GROUP_SECONDS);
        match self.shown.take() {
            Some(Shown::Card { id: earlier, .. }) => {
                self.shown = Some(Shown::Group { ids: vec![earlier.clone(), id.to_string()], until });
                Arrival::Group { earlier: Some(earlier) }
            }
            Some(Shown::Group { mut ids, until: was }) if was > now => {
                ids.push(id.to_string());
                self.shown = Some(Shown::Group { ids, until });
                Arrival::Group { earlier: None }
            }
            _ => {
                self.shown = Some(Shown::Card { id: id.to_string(), hint });
                Arrival::Card
            }
        }
    }

    /// A confirmation for [`CONFIRM_SECONDS`]: it replaces another confirmation, never a card or the grouped card (a call
    /// waiting for an answer comes first; the choice confirmed is still applied on time). False when it is not shown.
    pub fn show_done(&mut self, done: CallDone, now: Instant) -> bool {
        let busy = match &self.shown {
            Some(Shown::Card { .. }) => true,
            Some(Shown::Group { until, .. }) => *until > now,
            _ => false,
        };
        if busy {
            return false;
        }
        self.shown = Some(Shown::Done { done, until: now + Duration::from_secs(CONFIRM_SECONDS) });
        true
    }

    /// The card of `id` goes (answered, timed out or opened); false when it shows another call or none.
    pub fn clear_card(&mut self, id: &str) -> bool {
        if self.card() == Some(id) {
            self.shown = None;
            return true;
        }
        false
    }

    /// The confirmation of `id` goes; false when it shows another call or none.
    pub fn clear_done(&mut self, id: &str) -> bool {
        if matches!(&self.shown, Some(Shown::Done { done, .. }) if done.id == id) {
            self.shown = None;
            return true;
        }
        false
    }

    /// "Always do this?" was answered: the offer leaves the confirmation of `id`.
    pub fn drop_offer(&mut self, id: &str) {
        if let Some(Shown::Done { done, .. }) = &mut self.shown
            && done.id == id
        {
            done.offer_always = None;
        }
    }

    /// The grouped card goes (× or Review).
    pub fn clear_group(&mut self) {
        if matches!(self.shown, Some(Shown::Group { .. })) {
            self.shown = None;
        }
    }

    /// Undo on the confirmation of `id`: its card is back (in place of whatever shows).
    pub fn ask_again(&mut self, id: &str) {
        self.shown = Some(Shown::Card { id: id.to_string(), hint: false });
    }

    /// `id` finished uploading as server session `session`; entries older than [`UNDO_UPLOADED_FOR`] are dropped.
    pub fn uploaded(&mut self, id: &str, session: &str, now: Instant) {
        self.uploaded.retain(|_, (_, at)| now.saturating_duration_since(*at) < UNDO_UPLOADED_FOR);
        self.uploaded.insert(id.to_string(), (session.to_string(), now));
    }

    /// The server session of `id` when it finished uploading a moment ago.
    pub fn uploaded_session(&self, id: &str, now: Instant) -> Option<String> {
        self.uploaded.get(id).filter(|(_, at)| now.saturating_duration_since(*at) < UNDO_UPLOADED_FOR).map(|(s, _)| s.clone())
    }

    /// An upload of `id` is to be deleted when it ends (`saved_undo`): its confirmation goes.
    pub fn undo(&mut self, id: &str) {
        self.clear_done(id);
        self.uploaded.remove(id);
        self.undone.insert(id.to_string());
    }

    /// Called by the upload worker once an attempt for `id` ended: true (once) when it was undone meanwhile.
    pub fn take_undone(&mut self, id: &str) -> bool {
        self.undone.remove(id)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn done(id: &str, kind: CallChoice) -> CallDone {
        CallDone {
            kind,
            id: id.into(),
            title: None,
            caller_name: Some("Muneeb".into()),
            source_app: "whatsapp".into(),
            mode: RecordingMode::Call,
            duration_ms: 44_000,
            until: "2026-11-22T10:00:05+00:00".into(),
            offer_always: None,
        }
    }

    fn input(setting: AfterCall) -> OutcomeInput {
        OutcomeInput { setting, ask_when: AskWhen::After, mode: RecordingMode::Call, pill: None, assist_saved: false, interactive: true, quiet: false }
    }

    #[test]
    fn each_after_each_call_value_gives_its_result() {
        assert_eq!(outcome(input(AfterCall::Ask)), Outcome::Ask, "Ask me: the card");
        assert_eq!(outcome(input(AfterCall::AlwaysTranscribe)), Outcome::Confirm(CallChoice::Transcribe), "Transcribe every call: no question");
        assert_eq!(outcome(input(AfterCall::AlwaysAudio)), Outcome::Confirm(CallChoice::Audio), "Save audio only: no question");
        // Don't record calls is auto mode off: nothing starts by itself (detect::decide), and a call recorded by hand
        // follows the choice under it.
        let off = crate::settings::Settings { auto_mode: false, after_call: AfterCall::AlwaysAudio, ..Default::default() };
        assert_eq!(off.after_each_call(), AfterEachCall::Off);
        assert_eq!(outcome(input(off.after_call)), Outcome::Confirm(CallChoice::Audio));
    }

    #[test]
    fn a_choice_made_on_the_pill_means_no_card_whatever_the_setting() {
        for setting in [AfterCall::Ask, AfterCall::AlwaysTranscribe, AfterCall::AlwaysAudio] {
            for pill in [CallChoice::Transcribe, CallChoice::Audio, CallChoice::Delete] {
                assert_eq!(outcome(OutcomeInput { pill: Some(pill), ..input(setting) }), Outcome::Confirm(pill), "{setting:?} {pill:?}");
                assert_eq!(outcome(OutcomeInput { pill: Some(pill), quiet: true, ..input(setting) }), Outcome::Confirm(pill), "chosen in quiet hours too");
            }
        }
    }

    #[test]
    fn asked_at_the_start_there_is_no_card_and_doing_nothing_saves_the_audio() {
        let start = OutcomeInput { ask_when: AskWhen::Start, ..input(AfterCall::Ask) };
        assert_eq!(outcome(start), Outcome::Confirm(CallChoice::Audio));
        assert_eq!(outcome(OutcomeInput { pill: Some(CallChoice::Transcribe), ..start }), Outcome::Confirm(CallChoice::Transcribe));
        assert!(start_banner(AfterEachCall::Ask, AskWhen::Start, true, RecordingMode::Call), "an automatic call: the banner");
        assert!(!start_banner(AfterEachCall::Ask, AskWhen::Start, false, RecordingMode::Call), "started by hand: no banner");
        assert!(!start_banner(AfterEachCall::Ask, AskWhen::After, true, RecordingMode::Call), "asked after the call: no banner");
        assert!(!start_banner(AfterEachCall::AlwaysTranscribe, AskWhen::Start, true, RecordingMode::Call), "nothing to ask");
        assert!(!start_banner(AfterEachCall::Ask, AskWhen::Start, true, RecordingMode::MicNote), "a voice note is not a call");
    }

    #[test]
    fn quiet_hours_show_no_card_and_save_the_audio() {
        assert_eq!(outcome(OutcomeInput { quiet: true, ..input(AfterCall::Ask) }), Outcome::KeepNow { transcribe: false });
        assert_eq!(outcome(OutcomeInput { quiet: true, ..input(AfterCall::AlwaysTranscribe) }), Outcome::KeepNow { transcribe: true }, "the setting, quietly");
    }

    #[test]
    fn voice_notes_quits_and_live_assist_keep_their_old_rules() {
        assert_eq!(outcome(OutcomeInput { mode: RecordingMode::MicNote, ..input(AfterCall::Ask) }), Outcome::Confirm(CallChoice::Transcribe));
        assert_eq!(outcome(OutcomeInput { interactive: false, ..input(AfterCall::Ask) }), Outcome::Wait, "waits in the Library");
        assert_eq!(outcome(OutcomeInput { interactive: false, ..input(AfterCall::AlwaysAudio) }), Outcome::KeepNow { transcribe: false });
        assert_eq!(outcome(OutcomeInput { assist_saved: true, ..input(AfterCall::Ask) }), Outcome::KeepNow { transcribe: true });
        assert_eq!(CARD_SECONDS, 8, "the card's line is 8 s (it was 12 s)");
    }

    #[test]
    fn back_to_back_calls_get_one_grouped_card_never_a_stack() {
        let mut s = Surfaces::default();
        let t0 = Instant::now();
        assert_eq!(s.arrive("a", true, t0), Arrival::Card);
        assert_eq!(s.card(), Some("a"));
        assert!(s.hint(), "the first card says where to change it");
        assert_eq!(s.arrive("b", false, t0), Arrival::Group { earlier: Some("a".into()) }, "a's card was counting: both go to the group");
        assert_eq!((s.card(), s.group(t0)), (None, Some(2)));
        assert_eq!(s.arrive("c", false, t0 + Duration::from_secs(3)), Arrival::Group { earlier: None });
        assert_eq!(s.group(t0 + Duration::from_secs(3)), Some(3), "3 calls saved as audio");
        assert_eq!(s.group(t0 + Duration::from_secs(12)), None, "gone 8 s after the last one");
        assert_eq!(s.arrive("d", false, t0 + Duration::from_secs(12)), Arrival::Card, "a call after that has its own card");
    }

    #[test]
    fn one_message_at_a_time_and_a_card_comes_first() {
        let mut s = Surfaces::default();
        let t0 = Instant::now();
        s.arrive("a", false, t0);
        assert!(!s.show_done(done("b", CallChoice::Transcribe), t0), "never over a card");
        assert!(s.clear_card("a"));
        assert!(s.show_done(done("a", CallChoice::Audio), t0), "the answer replaces the card");
        assert!(s.show_done(done("b", CallChoice::Delete), t0), "a newer confirmation replaces the older one");
        assert_eq!(s.done(t0).map(|d| d.id.as_str()), Some("b"));
        assert_eq!(s.done(t0 + Duration::from_secs(CONFIRM_SECONDS)), None, "gone after 5 s");
        assert_eq!(s.arrive("c", false, t0), Arrival::Card, "a card replaces a confirmation");
        assert!(s.done(t0).is_none());
    }

    #[test]
    fn undo_brings_the_card_back_and_the_offer_goes_once_answered() {
        let mut s = Surfaces::default();
        let t0 = Instant::now();
        s.show_done(CallDone { offer_always: Some(AfterEachCall::AlwaysTranscribe), ..done("a", CallChoice::Transcribe) }, t0);
        s.drop_offer("b");
        assert!(s.done(t0).unwrap().offer_always.is_some(), "another call's answer leaves it");
        s.drop_offer("a");
        assert!(s.done(t0).unwrap().offer_always.is_none());
        s.ask_again("a");
        assert_eq!((s.card(), s.hint()), (Some("a"), false));
        assert!(s.done(t0).is_none());
    }

    #[test]
    fn three_same_choices_in_a_row_offer_always_once() {
        let mut streak = None;
        for _ in 0..2 {
            streak = Some(next_streak(streak, CallChoice::Transcribe));
        }
        assert_eq!(offers_always(streak.unwrap(), false, AfterEachCall::Ask), None, "two are not enough");
        let third = next_streak(streak, CallChoice::Transcribe);
        assert_eq!(offers_always(third, false, AfterEachCall::Ask), Some(AfterEachCall::AlwaysTranscribe));
        assert_eq!(offers_always(third, true, AfterEachCall::Ask), None, "once only");
        assert_eq!(offers_always(third, false, AfterEachCall::AlwaysAudio), None, "nothing to offer when it does not ask");
        assert_eq!(next_streak(Some(third), CallChoice::Audio), CardStreak { choice: CallChoice::Audio, count: 1 }, "another choice starts again");
        assert_eq!(CallChoice::Audio.always(), AfterEachCall::AlwaysAudio);
        assert_eq!(CallChoice::Delete.always(), AfterEachCall::Off, "always deleting is not recording calls");
    }

    #[test]
    fn an_upload_that_ends_after_undo_is_reported_once() {
        let mut s = Surfaces::default();
        let t0 = Instant::now();
        s.uploaded("a", "01SESSION", t0);
        assert_eq!(s.uploaded_session("a", t0 + Duration::from_secs(5)).as_deref(), Some("01SESSION"));
        assert_eq!(s.uploaded_session("a", t0 + UNDO_UPLOADED_FOR), None);
        s.show_done(done("a", CallChoice::Transcribe), t0);
        s.undo("a");
        assert!(s.done(t0).is_none(), "the confirmation goes with it");
        assert!(s.take_undone("a"));
        assert!(!s.take_undone("a"), "only once");
    }

    #[test]
    fn the_core_applies_a_choice_only_after_its_undo_time() {
        let code = |file: &str| std::fs::read_to_string(std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("src").join(file)).unwrap();
        let actions = code("actions.rs");
        let answer = &actions[actions.find("pub fn card_answer(").unwrap()..];
        let answer = &answer[..answer.find("\n}\n").unwrap()];
        assert!(answer.contains(".choose(") && answer.contains("discard_later("), "chosen now, applied later");
        assert!(!answer.contains("keep_decision_as(") && !answer.contains(".discard(id)"), "nothing is kept or deleted by the click");
        let restore = &actions[actions.find("pub fn restore_pending(").unwrap()..];
        let restore = &restore[..restore.find("\n}\n").unwrap()];
        assert!(restore.contains("keep_due(app);") && restore.contains("discard_due(app);"), "a restart applies only what is due and waits for the rest");
        let commands = code("commands.rs");
        let discard = &commands[commands.find("pub fn after_call_discard(").unwrap()..];
        assert!(discard[..discard.find("\n}\n").unwrap()].contains("crate::actions::discard_from_chip(&app, &id)"), "an older window's Delete goes the same way");
    }
}
