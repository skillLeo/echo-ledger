//! The two-way audio decision (pure). A call is an application process tree that holds the microphone AND plays
//! audio; dictation holds only the microphone. Browsers are judged by the site they show, never by name.

use std::collections::{HashMap, HashSet};

use serde::Serialize;

use super::classify::{SourceApp, classify_any, is_browser};
use super::sites::{SiteRule, SiteVerdict, match_site, never_by_name, site_of};
use super::state::{MicUseEvent, MicUseState, MicUseStateMachine};

/// Start window: both signals must hold this long before a recording starts.
pub const START_AFTER_MS: u64 = 3_000;
/// Stop window: the recorded tree must release the microphone this long before the recording stops.
pub const STOP_AFTER_MS: u64 = 5_000;
/// The unknown-app chip is offered at most once per tree in this period.
pub const ASK_AGAIN_AFTER_MS: u64 = 10 * 60 * 1_000;

/// One application process tree that holds the microphone.
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct TreeAudio {
    pub root_pid: u32,
    /// Root image file name (Windows) / bundle id (macOS); "a protected process" if unreadable.
    pub name: String,
    pub pfn: Option<String>,
    /// Some process of the tree has an Active capture session.
    pub capture_active: bool,
    /// Some process of the tree has an Active render session.
    pub render_active: bool,
    /// Visible top-level window titles of the tree (Windows).
    pub titles: Vec<String>,
    /// Active-tab URLs (macOS with Automation granted).
    pub urls: Vec<String>,
}

#[derive(Clone, Debug, PartialEq, Eq)]
pub enum Verdict {
    Record(SourceApp),
    Ask,
    Ignore(IgnoreReason),
}

#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum IgnoreReason {
    Dictation,
    UnknownSite,
    UnknownApp,
    NeverRecord,
    AutoOff,
}

impl IgnoreReason {
    pub fn as_str(self) -> &'static str {
        match self {
            IgnoreReason::Dictation => "dictation",
            IgnoreReason::UnknownSite => "unknown_site",
            IgnoreReason::UnknownApp => "unknown_app",
            IgnoreReason::NeverRecord => "never_record",
            IgnoreReason::AutoOff => "auto_off",
        }
    }
}

/// The call app the tree's own identity names (native apps such as WhatsApp, Zoom, Teams).
pub fn native_app(t: &TreeAudio) -> Option<SourceApp> {
    classify_any(&t.name, t.pfn.as_deref())
}

/// A browser tree: a browser identity that is not a native call app (a WhatsApp package outranks its image name).
pub fn is_browser_tree(t: &TreeAudio) -> bool {
    native_app(t).is_none() && is_browser(&t.name)
}

/// What the tree is: (identified app, matched site host or rule id, blocked by a never-record rule).
pub fn identify(t: &TreeAudio, user_rules: &[SiteRule]) -> (Option<SourceApp>, Option<String>, bool) {
    if is_browser_tree(t) {
        return match match_site(&t.titles, &t.urls, user_rules) {
            SiteVerdict::App { app, rule_id } => (SourceApp::from_wire(&app), Some(site_of(&rule_id, user_rules)), false),
            SiteVerdict::Never { rule_id } => (None, Some(site_of(&rule_id, user_rules)), true),
            SiteVerdict::Unknown => (None, None, false),
        };
    }
    if never_by_name(&t.name, user_rules) {
        return (None, None, true);
    }
    (native_app(t), None, false)
}

/// The decision for one tree. Native call apps and recognised sites record only with two-way audio; unknown
/// trees are ignored, or offered as a chip when `ask_unknown` is on and the tree plays audio.
pub fn decide(t: &TreeAudio, user_rules: &[SiteRule], ask_unknown: bool, auto_mode: bool) -> Verdict {
    if !auto_mode {
        return Verdict::Ignore(IgnoreReason::AutoOff);
    }
    let two_way = t.capture_active && t.render_active;
    let (app, _, never) = identify(t, user_rules);
    if never {
        return Verdict::Ignore(IgnoreReason::NeverRecord);
    }
    match app {
        Some(app) if two_way => Verdict::Record(app),
        Some(_) => Verdict::Ignore(IgnoreReason::Dictation),
        None if ask_unknown && two_way => Verdict::Ask,
        None if is_browser_tree(t) => Verdict::Ignore(IgnoreReason::UnknownSite),
        None => Verdict::Ignore(IgnoreReason::UnknownApp),
    }
}

/// The tree auto mode would record: the first `Record` with native apps before browsers, then ascending root pid.
pub fn pick_candidate(trees: &[TreeAudio], verdicts: &[Verdict]) -> Option<(u32, SourceApp)> {
    trees
        .iter()
        .zip(verdicts)
        .filter_map(|(t, v)| match v {
            Verdict::Record(app) => Some((is_browser_tree(t), t.root_pid, *app)),
            _ => None,
        })
        .min_by_key(|(browser, pid, _)| (*browser, *pid))
        .map(|(_, pid, app)| (pid, app))
}

fn holds_mic(trees: &[TreeAudio], pid: u32) -> bool {
    trees.iter().any(|t| t.root_pid == pid && t.capture_active)
}

/// Feeds [`MicUseStateMachine`] once per tick. While idle or waiting, the candidate tree must stay the same and
/// keep both signals for the whole start window (a change of tree or app restarts it). Once recording, the label
/// is fixed: the recorded tree only has to keep the microphone (its audio may go quiet, tab switches never
/// relabel).
#[derive(Clone, Debug)]
pub struct CallTracker {
    machine: MicUseStateMachine,
    candidate_pid: Option<u32>,
    recorded: Option<(u32, SourceApp)>,
    adopted: Option<u32>,
}

impl Default for CallTracker {
    fn default() -> Self {
        Self::new()
    }
}

impl CallTracker {
    pub fn new() -> Self {
        Self { machine: MicUseStateMachine::new(START_AFTER_MS, STOP_AFTER_MS), candidate_pid: None, recorded: None, adopted: None }
    }

    pub fn state(&self) -> MicUseState {
        self.machine.state()
    }

    /// Root pid and label of the tree being recorded by auto mode.
    pub fn recorded(&self) -> Option<(u32, SourceApp)> {
        match self.machine.state() {
            MicUseState::Recording { .. } | MicUseState::Releasing { .. } => self.recorded,
            _ => None,
        }
    }

    /// The user chose "Record" for an unknown tree: follow it like a call labelled `other`, so the recording stops
    /// when that tree releases the microphone.
    pub fn adopt(&mut self, root_pid: u32) {
        self.adopted = Some(root_pid);
    }

    pub fn reset(&mut self) {
        self.machine.reset();
        self.candidate_pid = None;
        self.recorded = None;
        self.adopted = None;
    }

    pub fn tick(&mut self, now_ms: u64, trees: &[TreeAudio], verdicts: &[Verdict]) -> Option<MicUseEvent> {
        if self.adopted.is_some_and(|pid| !holds_mic(trees, pid)) {
            self.adopted = None;
        }
        match self.machine.state() {
            MicUseState::Idle | MicUseState::Candidate { .. } => {
                let pick = self.adopted.map(|pid| (pid, SourceApp::Other)).or_else(|| pick_candidate(trees, verdicts));
                let pid = pick.map(|(pid, _)| pid);
                if self.candidate_pid.is_some() && pid.is_some() && self.candidate_pid != pid {
                    self.machine.reset(); // another tree: its start window begins now
                }
                self.candidate_pid = pid;
                let event = self.machine.update(now_ms, pick.map(|(_, app)| app));
                if let (Some(MicUseEvent::Start(app)), Some(pid)) = (event, pid) {
                    self.recorded = Some((pid, app));
                }
                event
            }
            MicUseState::Recording { app } | MicUseState::Releasing { app, .. } => {
                let held = self.recorded.is_some_and(|(pid, _)| holds_mic(trees, pid));
                let event = self.machine.update(now_ms, held.then_some(app));
                if matches!(event, Some(MicUseEvent::Stop(_))) {
                    self.recorded = None;
                    self.candidate_pid = None;
                    self.adopted = None;
                }
                event
            }
        }
    }
}

/// When to offer the unknown-app chip: a tree whose verdict stayed `Ask` for the whole start window while nothing
/// records, at most once per tree per 10 minutes, never for a tree the user chose to ignore until it releases
/// the microphone.
#[derive(Clone, Debug, Default)]
pub struct AskTracker {
    since: HashMap<u32, u64>,
    shown: HashMap<u32, u64>,
    suppressed: HashSet<u32>,
}

impl AskTracker {
    /// "Ignore": no chip and no ask for this tree until it releases the microphone.
    pub fn suppress(&mut self, root_pid: u32) {
        self.suppressed.insert(root_pid);
        self.since.remove(&root_pid);
    }

    pub fn is_suppressed(&self, root_pid: u32) -> bool {
        self.suppressed.contains(&root_pid)
    }

    /// The tree to offer a chip for now, if any (lowest root pid first).
    pub fn tick(&mut self, now_ms: u64, trees: &[TreeAudio], verdicts: &[Verdict], recording: bool) -> Option<u32> {
        self.suppressed.retain(|pid| holds_mic(trees, *pid));
        self.shown.retain(|_, at| now_ms.saturating_sub(*at) < ASK_AGAIN_AFTER_MS);
        let asking: Vec<u32> = trees
            .iter()
            .zip(verdicts)
            .filter(|(t, v)| **v == Verdict::Ask && !self.suppressed.contains(&t.root_pid))
            .map(|(t, _)| t.root_pid)
            .collect();
        self.since.retain(|pid, _| asking.contains(pid));
        for pid in &asking {
            self.since.entry(*pid).or_insert(now_ms);
        }
        if recording {
            return None;
        }
        let mut ready: Vec<u32> = asking
            .into_iter()
            .filter(|pid| self.since.get(pid).is_some_and(|s| now_ms.saturating_sub(*s) >= START_AFTER_MS))
            .filter(|pid| !self.shown.contains_key(pid))
            .collect();
        ready.sort_unstable();
        let pid = ready.first().copied()?;
        self.shown.insert(pid, now_ms);
        Some(pid)
    }
}
