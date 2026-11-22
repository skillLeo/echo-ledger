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
//! - Back-to-back calls: one grouped card ("2 calls saved as audio · Review"), never a stack. Whatever was on screen
//!   when another call needs the window (a card, a confirmation, the grouped card) joins it there, each call with its
//!   Undo, and every choice in it is applied only when the grouped card goes.
//! - A pending choice is never applied while its Undo can't be reached: a Delete row always shows (only Transcribe and
//!   Save audio only rows fold into "+N more"); Undo on one row reopens that call's card with the rest of the group under
//!   it, their time paused until the card is answered; Review applies the rest and keeps the Deletes on screen; only the
//!   grouped card's × is a deliberate "apply everything now".
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
    /// The "After each call" value that makes this choice every time ("Always do this?"). Delete has none: Don't record
    /// calls from one tap is too much (lead decision); per-contact rules will cover it.
    pub fn always(self) -> Option<AfterEachCall> {
        match self {
            CallChoice::Transcribe => Some(AfterEachCall::AlwaysTranscribe),
            CallChoice::Audio => Some(AfterEachCall::AlwaysAudio),
            CallChoice::Delete => None,
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

/// Whether the confirmation offers "Always do this?" now (pure): Transcribe or Save audio only [`ALWAYS_AFTER`] times in
/// a row on the card (never Delete), never offered before, and the person still asked after each call.
pub fn offers_always(streak: CardStreak, offered_before: bool, now: AfterEachCall) -> Option<AfterEachCall> {
    streak.choice.always().filter(|_| streak.count >= ALWAYS_AFTER && !offered_before && now == AfterEachCall::Ask)
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

/// "3 calls saved as audio · Review" (`AfterCallState.group`): back-to-back calls, each with what happens to it and its
/// Undo (the newest last).
#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct CallGroup {
    pub count: usize,
    /// RFC 3339: when the grouped card goes and the choices in it are applied.
    pub until: String,
    pub calls: Vec<CallDone>,
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
    /// The rest of a grouped card under a reopened card (Undo on one of its rows), each with its own Undo; their time
    /// is paused until the card is answered.
    pub held: Vec<CallDone>,
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

/// What was on screen when another call needed the after-call window. Anything but `Nothing` goes into the grouped
/// card with the new call (`actions`), so no Undo is lost and nothing stacks.
#[derive(Clone, Debug, PartialEq, Eq)]
pub enum Before {
    Nothing,
    /// A card was counting (with the rows held under it): its call joins the group, saved as audio unless the person
    /// says otherwise there.
    Card(String, Vec<CallDone>),
    /// A confirmation within its 5 s.
    Done(CallDone),
    /// The grouped card, still up.
    Group(Vec<CallDone>),
}

/// The grouped card's calls when `newest` needs the window and `before` was there (pure): a counting card's call is saved
/// as audio (`card_as_audio` gives that confirmation), a confirmation and the grouped card's calls keep theirs with their
/// Undo; each call once, the newest last.
pub fn grouped(before: Before, card_as_audio: impl Fn(&str) -> Option<CallDone>, newest: CallDone) -> Vec<CallDone> {
    let mut calls = match before {
        Before::Nothing => Vec::new(),
        Before::Card(id, held) => held.into_iter().chain(card_as_audio(&id)).collect(),
        Before::Done(done) => vec![done],
        Before::Group(calls) => calls,
    };
    calls.retain(|c| c.id != newest.id);
    calls.push(newest);
    calls
}

/// The rows the grouped card lists before "+N more".
pub const GROUP_ROWS: usize = 3;

/// The rows a list of grouped calls shows (pure): every Delete, always, and the newest Transcribe and Save audio only rows
/// up to [`GROUP_ROWS`] rows in all; the other rows fold into "+N more" (their choices keep the audio, so nothing is lost
/// while they are folded). Returns the shown rows in order and how many are folded.
pub fn visible_rows(calls: &[CallDone]) -> (Vec<&CallDone>, usize) {
    let deletes = calls.iter().filter(|c| c.kind == CallChoice::Delete).count();
    let others = calls.len() - deletes;
    let room = GROUP_ROWS.saturating_sub(deletes).min(others);
    let skip = others - room;
    let mut seen = 0;
    let shown = calls
        .iter()
        .filter(|c| {
            if c.kind == CallChoice::Delete {
                return true;
            }
            seen += 1;
            seen > skip
        })
        .collect();
    (shown, skip)
}

#[derive(Clone, Debug)]
enum Shown {
    Card { id: String, hint: bool, held: Vec<CallDone> },
    Done { done: CallDone, until: Instant },
    Group { calls: Vec<CallDone>, until: Instant },
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

    /// The rows held under the card (the rest of a grouped card whose row was undone).
    pub fn held(&self) -> &[CallDone] {
        match &self.shown {
            Some(Shown::Card { held, .. }) => held,
            _ => &[],
        }
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
        self.group_calls(now).map(|(calls, _)| calls.len())
    }

    /// The grouped card's calls and how long it stays, while it is on screen.
    pub fn group_calls(&self, now: Instant) -> Option<(&[CallDone], Duration)> {
        match &self.shown {
            Some(Shown::Group { calls, until }) if *until > now => Some((calls, until.saturating_duration_since(now))),
            _ => None,
        }
    }

    /// Takes what is on screen now: a confirmation or the grouped card only while it lasts.
    fn take_live(&mut self, now: Instant) -> Before {
        match self.shown.take() {
            Some(Shown::Card { id, held, .. }) => Before::Card(id, held),
            Some(Shown::Done { done, until }) if until > now => Before::Done(done),
            Some(Shown::Group { calls, until }) if until > now => Before::Group(calls),
            _ => Before::Nothing,
        }
    }

    /// A call that would ask (`id`): its card shows when the window is free. Otherwise what was there is returned and
    /// the caller puts it in the grouped card with this call ([`Surfaces::group_with`]): never two cards, never a stack.
    pub fn arrive(&mut self, id: &str, hint: bool, now: Instant) -> Before {
        let before = self.take_live(now);
        if before == Before::Nothing {
            self.shown = Some(Shown::Card { id: id.to_string(), hint, held: Vec::new() });
        }
        before
    }

    /// A confirmation for [`CONFIRM_SECONDS`] when the window is free, or in place of the same call's confirmation (its
    /// Transcribe). Otherwise what was there is returned and the caller groups it with this one, so a choice made on the
    /// pill while another call's card is up still gets its confirmation and Undo.
    pub fn show_done(&mut self, done: CallDone, now: Instant) -> Before {
        let before = self.take_live(now);
        let same = matches!(&before, Before::Done(d) if d.id == done.id);
        if before == Before::Nothing || same {
            self.shown = Some(Shown::Done { done, until: now + Duration::from_secs(CONFIRM_SECONDS) });
            return Before::Nothing;
        }
        before
    }

    /// The grouped card with `calls` (the newest last) for [`GROUP_SECONDS`] from `now`; returns when it goes.
    pub fn group_with(&mut self, calls: Vec<CallDone>, now: Instant) -> Instant {
        let until = now + Duration::from_secs(GROUP_SECONDS);
        self.shown = Some(Shown::Group { calls, until });
        until
    }

    /// The row of `id` in the grouped card or under the card, while it shows (its `until`: when it is applied).
    pub fn listed(&self, id: &str, now: Instant) -> Option<&CallDone> {
        let rows: &[CallDone] = match &self.shown {
            Some(Shown::Group { calls, until }) if *until > now => calls,
            Some(Shown::Card { held, .. }) => held,
            _ => &[],
        };
        rows.iter().find(|c| c.id == id)
    }

    /// A call's row (in the grouped card or under the card) changes (Transcribe on it); false when it is not listed.
    pub fn update_listed(&mut self, done: CallDone) -> bool {
        let rows = match &mut self.shown {
            Some(Shown::Group { calls, .. }) => calls,
            Some(Shown::Card { held, .. }) => held,
            _ => return false,
        };
        if let Some(at) = rows.iter().position(|c| c.id == done.id) {
            rows[at] = done;
            return true;
        }
        false
    }

    /// The card of `id` goes (answered, timed out or opened): the rows held under it, which go back to a grouped card;
    /// none when the card shows another call or none.
    pub fn clear_card(&mut self, id: &str) -> Option<Vec<CallDone>> {
        if self.card() != Some(id) {
            return None;
        }
        match self.shown.take() {
            Some(Shown::Card { held, .. }) => Some(held),
            _ => None,
        }
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

    /// The grouped card goes (its ×, or Review with nothing left to keep on screen): its rows, for the caller to apply.
    pub fn take_group(&mut self, now: Instant) -> Vec<CallDone> {
        match self.shown.take() {
            Some(Shown::Group { calls, until }) if until > now => calls,
            other => {
                self.shown = other;
                Vec::new()
            }
        }
    }

    /// Undo on the confirmation of `id`, or on its row in the grouped card or under the card: its card is back, and
    /// whatever else was on screen stays under it (another card's call saved as audio by `card_as_audio`). Returns the
    /// rows now held under the card, whose time the caller pauses until the card is answered.
    pub fn ask_again(&mut self, id: &str, card_as_audio: impl Fn(&str) -> Option<CallDone>, now: Instant) -> Vec<CallDone> {
        let mut held = match self.take_live(now) {
            Before::Nothing => Vec::new(),
            Before::Card(other, held) if other == id => held,
            Before::Card(other, held) => held.into_iter().chain(card_as_audio(&other)).collect(),
            Before::Done(done) => vec![done],
            Before::Group(calls) => calls,
        };
        held.retain(|c| c.id != id);
        self.shown = Some(Shown::Card { id: id.to_string(), hint: false, held: held.clone() });
        held
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
        let audio = |id: &str| Some(done(id, CallChoice::Audio));
        assert_eq!(s.arrive("a", true, t0), Before::Nothing);
        assert_eq!(s.card(), Some("a"));
        assert!(s.hint(), "the first card says where to change it");
        let before = s.arrive("b", false, t0);
        assert_eq!(before, Before::Card("a".into(), Vec::new()), "a's card was counting: both go to the group");
        s.group_with(grouped(before, audio, done("b", CallChoice::Audio)), t0);
        assert_eq!((s.card(), s.group(t0)), (None, Some(2)));
        let later = t0 + Duration::from_secs(3);
        let before = s.arrive("c", false, later);
        assert!(matches!(&before, Before::Group(calls) if calls.len() == 2));
        s.group_with(grouped(before, audio, done("c", CallChoice::Audio)), later);
        assert_eq!(s.group(later), Some(3), "3 calls saved as audio");
        assert_eq!(s.group_calls(later).unwrap().0.iter().map(|c| c.id.as_str()).collect::<Vec<_>>(), ["a", "b", "c"], "the newest last");
        assert_eq!(s.group(later + Duration::from_secs(GROUP_SECONDS)), None, "gone 8 s after the last one");
        assert_eq!(s.arrive("d", false, later + Duration::from_secs(GROUP_SECONDS)), Before::Nothing, "a call after that has its own card");
    }

    #[test]
    fn a_new_call_inside_the_5_s_keeps_the_earlier_undo_in_the_grouped_card() {
        let mut s = Surfaces::default();
        let t0 = Instant::now();
        assert_eq!(s.show_done(done("a", CallChoice::Transcribe), t0), Before::Nothing, "a free window: the confirmation shows");
        // Another call ends 2 s later: its card would take the window, so both go to the group, a's Undo with it.
        let before = s.arrive("b", false, t0 + Duration::from_secs(2));
        assert_eq!(before, Before::Done(done("a", CallChoice::Transcribe)));
        let calls = grouped(before, |_| None, done("b", CallChoice::Audio));
        assert_eq!(calls.iter().map(|c| (c.id.as_str(), c.kind)).collect::<Vec<_>>(), [("a", CallChoice::Transcribe), ("b", CallChoice::Audio)]);
        s.group_with(calls, t0 + Duration::from_secs(2));
        assert!(s.card().is_none() && s.done(t0).is_none(), "never two at once");
        // Transcribe on b's row updates it in place.
        assert!(s.update_listed(done("b", CallChoice::Transcribe)));
        assert!(!s.update_listed(done("z", CallChoice::Transcribe)), "not in the group");
        assert_eq!(s.group_calls(t0).unwrap().0[1].kind, CallChoice::Transcribe);
        // After the 5 s of a confirmation, the window is free again.
        let mut free = Surfaces::default();
        free.show_done(done("a", CallChoice::Audio), t0);
        assert_eq!(free.arrive("b", false, t0 + Duration::from_secs(CONFIRM_SECONDS)), Before::Nothing);
    }

    #[test]
    fn stop_and_delete_on_the_pill_while_another_card_is_up_still_gets_its_deleted_and_undo() {
        let mut s = Surfaces::default();
        let t0 = Instant::now();
        s.arrive("a", false, t0);
        let before = s.show_done(done("b", CallChoice::Delete), t0);
        assert_eq!(before, Before::Card("a".into(), Vec::new()), "the card is up: the two are grouped, not hidden");
        let calls = grouped(before, |id| Some(done(id, CallChoice::Audio)), done("b", CallChoice::Delete));
        assert_eq!(calls.iter().map(|c| (c.id.as_str(), c.kind)).collect::<Vec<_>>(), [("a", CallChoice::Audio), ("b", CallChoice::Delete)]);
        s.group_with(calls, t0);
        assert_eq!(s.group(t0), Some(2));
        // A card answered replaces itself with its confirmation; Transcribe on a confirmation replaces the same call's.
        let mut one = Surfaces::default();
        one.arrive("c", false, t0);
        assert_eq!(one.clear_card("c"), Some(Vec::new()));
        assert_eq!(one.show_done(done("c", CallChoice::Audio), t0), Before::Nothing);
        assert_eq!(one.show_done(done("c", CallChoice::Transcribe), t0), Before::Nothing, "the same call: in place");
        assert_eq!(one.done(t0).map(|d| d.kind), Some(CallChoice::Transcribe));
        assert_eq!(one.done(t0 + Duration::from_secs(CONFIRM_SECONDS)), None, "gone after 5 s");
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
        assert!(s.ask_again("a", |_| None, t0).is_empty(), "its own confirmation: nothing to keep under the card");
        assert_eq!((s.card(), s.hint()), (Some("a"), false));
        assert!(s.done(t0).is_none());
    }

    #[test]
    fn undo_on_one_row_keeps_the_delete_row_on_screen_under_the_card() {
        let mut s = Surfaces::default();
        let t0 = Instant::now();
        s.group_with(vec![done("del", CallChoice::Delete), done("aud", CallChoice::Audio)], t0);
        // Undo on the Audio row: its card comes back, and the Delete row stays under it with its own Undo.
        let held = s.ask_again("aud", |_| None, t0);
        assert_eq!(held.iter().map(|c| (c.id.as_str(), c.kind)).collect::<Vec<_>>(), [("del", CallChoice::Delete)], "the caller pauses it");
        assert_eq!(s.card(), Some("aud"));
        assert_eq!(s.held().iter().map(|c| c.id.as_str()).collect::<Vec<_>>(), ["del"], "on screen, under the card");
        assert!(s.listed("del", t0 + Duration::from_secs(60)).is_some(), "listed as long as the card is up, whatever the time");
        // Another call arriving takes the card and its rows into the group: nothing drops out of sight.
        let before = s.arrive("new", false, t0);
        assert_eq!(before, Before::Card("aud".into(), vec![done("del", CallChoice::Delete)]));
        let calls = grouped(before, |id| Some(done(id, CallChoice::Audio)), done("new", CallChoice::Audio));
        assert_eq!(calls.iter().map(|c| c.id.as_str()).collect::<Vec<_>>(), ["del", "aud", "new"]);
        // The card answered: its rows come back with its answer.
        let mut t = Surfaces::default();
        t.group_with(vec![done("del", CallChoice::Delete), done("aud", CallChoice::Audio)], t0);
        t.ask_again("aud", |_| None, t0);
        assert_eq!(t.clear_card("aud").unwrap().iter().map(|c| c.id.as_str()).collect::<Vec<_>>(), ["del"]);
        // Undo on a row under the card: that one is the card now, the card's call a row (saved as audio).
        let mut u = Surfaces::default();
        u.group_with(vec![done("del", CallChoice::Delete), done("aud", CallChoice::Audio)], t0);
        u.ask_again("aud", |_| None, t0);
        let held = u.ask_again("del", |id| Some(done(id, CallChoice::Audio)), t0);
        assert_eq!((u.card(), held.iter().map(|c| (c.id.as_str(), c.kind)).collect::<Vec<_>>()), (Some("del"), vec![("aud", CallChoice::Audio)]));
    }

    #[test]
    fn every_delete_row_shows_and_only_kept_rows_fold_into_more() {
        let kinds = |calls: &[&CallDone]| calls.iter().map(|c| c.id.clone()).collect::<Vec<_>>();
        // 5 calls, the oldest a Delete: it stays, with the two newest others; two fold into "+2 more".
        let five = [done("d1", CallChoice::Delete), done("a1", CallChoice::Audio), done("a2", CallChoice::Audio), done("t1", CallChoice::Transcribe), done("a3", CallChoice::Audio)];
        let (shown, more) = visible_rows(&five);
        assert_eq!((kinds(&shown), more), (vec!["d1".to_string(), "t1".into(), "a3".into()], 2));
        // Four Deletes: all four show, past the usual three, and nothing else.
        let deletes: Vec<CallDone> = (0..4).map(|i| done(&format!("d{i}"), CallChoice::Delete)).chain([done("a", CallChoice::Audio)]).collect();
        let (shown, more) = visible_rows(&deletes);
        assert_eq!((shown.len(), more), (4, 1));
        assert!(shown.iter().all(|c| c.kind == CallChoice::Delete));
        let (shown, more) = visible_rows(&five[1..3]);
        assert_eq!((shown.len(), more), (2, 0), "a short group shows everything");
    }

    #[test]
    fn review_and_close_take_the_group_for_the_caller_to_apply() {
        let mut s = Surfaces::default();
        let t0 = Instant::now();
        s.group_with(vec![done("del", CallChoice::Delete), done("aud", CallChoice::Audio)], t0);
        let rows = s.take_group(t0);
        assert_eq!(rows.len(), 2);
        assert!(s.group(t0).is_none());
        s.arrive("x", false, t0);
        assert!(s.take_group(t0).is_empty(), "no group: a card is left alone");
        assert_eq!(s.card(), Some("x"));
        let code = std::fs::read_to_string(std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("src/actions.rs")).unwrap();
        let review = &code[code.find("pub fn group_review(").unwrap()..];
        let review = &review[..review.find("\n}\n").unwrap()];
        assert!(review.contains("partition(|c| c.kind == CallChoice::Delete)") && review.contains("show_group(app, deletes)"),
            "Review applies what is kept and keeps every Delete on screen with its Undo");
        let undo = &code[code.find("pub fn call_undo(").unwrap()..];
        let undo = &undo[..undo.find("\n}\n").unwrap()];
        assert!(undo.contains("let paused = at +") && undo.contains("set_apply_time(&state, held, paused)"), "the rows under the card wait past it");
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
        assert_eq!(CallChoice::Audio.always(), Some(AfterEachCall::AlwaysAudio));
    }

    #[test]
    fn three_saves_as_audio_offer_save_audio_only_and_three_deletes_never_offer_anything() {
        let audio = (0..3).fold(None, |s, _| Some(next_streak(s, CallChoice::Audio))).unwrap();
        assert_eq!(offers_always(audio, false, AfterEachCall::Ask), Some(AfterEachCall::AlwaysAudio));
        let deletes = (0..5).fold(None, |s, _| Some(next_streak(s, CallChoice::Delete))).unwrap();
        assert_eq!(deletes.count, 5);
        assert_eq!(offers_always(deletes, false, AfterEachCall::Ask), None, "Don't record calls is never one tap away");
        assert_eq!(CallChoice::Delete.always(), None);
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
        assert!(restore.contains("keep_due(app);"), "a restart applies the kept choices that are due");
        assert!(restore.contains("restore_unapplied_deletes()") && !restore.contains("discard_due(app)"),
            "a Delete whose Undo could no longer be shown goes back to the waiting list, never applied at start");
        let commands = code("commands.rs");
        let discard = &commands[commands.find("pub fn after_call_discard(").unwrap()..];
        assert!(discard[..discard.find("\n}\n").unwrap()].contains("crate::actions::discard_from_chip(&app, &id)"), "an older window's Delete goes the same way");
    }
}
