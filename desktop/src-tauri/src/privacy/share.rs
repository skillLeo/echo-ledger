//! Screen-share visibility per window (v2.0): the main window is shown in screen shares unless the person hides it, for
//! a time they choose; the Live Assist card and the captions are hidden from screen shares unless the person shows that
//! one window, on that window. Pure: the clock, the call and the system's answers come in, so every rule is tested with
//! a fake clock and a fake platform layer.
//!
//! - [`HideFor`] is what the person picks; [`hide_until`] turns it into a [`MainHide`] kept in the settings, so a restart
//!   keeps it hidden. [`MainHideTimer`] ends it by itself: at its time, or a few seconds after the call is over.
//! - [`WindowShare`] is what a card or captions badge says, read back from the system ([`ShareFlag`]), never only the
//!   saved choice: a flag the system did not keep says plainly that it could not be hidden.

use chrono::{DateTime, Datelike, Duration as ChronoDuration, Local, LocalResult, NaiveTime, TimeZone};
use serde::{Deserialize, Serialize};

/// "Custom…": from 1 minute to 7 days.
pub const CUSTOM_MIN_MINUTES: u32 = 1;
pub const CUSTOM_MAX_MINUTES: u32 = 7 * 24 * 60;
/// "Until tomorrow": 08:00 local time the next day.
pub const TOMORROW_AT: (u32, u32) = (8, 0);
/// A call that is no longer detected for this long is over (one missed look of the detector is not the end of a call).
pub const CALL_GONE_AFTER_MS: i64 = 5_000;

/// The one-time line on the main window after the update (with its Hide button).
pub const SHARE_NOTICE: &str = "EchoLedger now shows in screen shares unless you hide it.";

/// What the person picks under "Hide from screen shares".
#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case", tag = "kind")]
pub enum HideFor {
    /// "Until this call ends": offered only while a call is detected.
    CallEnds,
    /// "15 min".
    Minutes15,
    /// "1 hour".
    Hour1,
    /// "Until tomorrow": 08:00 local time the next day.
    Tomorrow,
    /// "Until I turn it off".
    UntilOff,
    /// "Until I quit" (the menu bar's Live › Hide from Screen Shares): until Stop Hiding or EchoLedger quits; never
    /// carried into the next launch (`Settings::from_value` drops it).
    UntilQuit,
    /// "Custom…", in minutes (1 minute to 7 days).
    Custom { minutes: u32 },
}

/// Until when the main window is hidden from screen shares (kept in the settings).
#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "snake_case", tag = "kind")]
pub enum HideUntil {
    /// Until the call that was on when it was chosen is over.
    CallEnds,
    /// Until this moment (Unix milliseconds, so a changed time zone or a sleep never stretches it).
    At { at_ms: i64 },
    /// Until the person shows it again.
    UntilOff,
    /// Until the person shows it again or EchoLedger quits: the next launch starts with it shown.
    UntilQuit,
}

/// The main window's hide, as kept in `settings.json` (`mainShareHide`).
#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "camelCase")]
pub struct MainHide {
    pub until: HideUntil,
    /// When it was chosen (Unix milliseconds).
    pub since_ms: i64,
}

/// Why a hide could not be chosen.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum HideRefused {
    /// "Until this call ends" with no call detected.
    NoCall,
    /// "Custom…" outside 1 minute to 7 days.
    CustomOutOfRange,
}

impl HideRefused {
    pub fn message(self) -> &'static str {
        match self {
            HideRefused::NoCall => "No call is on now. Choose how long to hide EchoLedger from screen shares.",
            HideRefused::CustomOutOfRange => "Choose between 1 minute and 7 days.",
        }
    }
}

/// 08:00 local on the day after `now` (the first valid local time from 08:00 on a day the clocks change).
pub fn tomorrow_morning(now: DateTime<Local>) -> DateTime<Local> {
    let day = now.date_naive() + ChronoDuration::days(1);
    let at = NaiveTime::from_hms_opt(TOMORROW_AT.0, TOMORROW_AT.1, 0).unwrap_or_default();
    let naive = day.and_time(at);
    match Local.from_local_datetime(&naive) {
        LocalResult::Single(t) => t,
        LocalResult::Ambiguous(first, _) => first,
        // 08:00 does not exist that day (the clocks moved): an hour later does.
        LocalResult::None => Local.from_local_datetime(&(naive + ChronoDuration::hours(1))).earliest().unwrap_or(now + ChronoDuration::days(1)),
    }
}

/// What a choice means now (pure): "Until this call ends" needs a call, "Custom…" 1 minute to 7 days.
pub fn hide_until(choice: HideFor, now: DateTime<Local>, call_detected: bool) -> Result<MainHide, HideRefused> {
    let now_ms = now.timestamp_millis();
    let after = |minutes: i64| HideUntil::At { at_ms: now_ms + minutes * 60_000 };
    let until = match choice {
        HideFor::CallEnds if !call_detected => return Err(HideRefused::NoCall),
        HideFor::CallEnds => HideUntil::CallEnds,
        HideFor::Minutes15 => after(15),
        HideFor::Hour1 => after(60),
        HideFor::Tomorrow => HideUntil::At { at_ms: tomorrow_morning(now).timestamp_millis() },
        HideFor::UntilOff => HideUntil::UntilOff,
        HideFor::UntilQuit => HideUntil::UntilQuit,
        HideFor::Custom { minutes } if !(CUSTOM_MIN_MINUTES..=CUSTOM_MAX_MINUTES).contains(&minutes) => return Err(HideRefused::CustomOutOfRange),
        HideFor::Custom { minutes } => after(i64::from(minutes)),
    };
    Ok(MainHide { until, since_ms: now_ms })
}

/// The choices the list offers now, in order (pure): "Until this call ends" only while a call is detected.
pub fn choices(call_detected: bool) -> Vec<&'static str> {
    let mut out = Vec::new();
    if call_detected {
        out.push("Until this call ends");
    }
    out.extend(["15 min", "1 hour", "Until tomorrow", "Until I turn it off", "Custom…"]);
    out
}

/// A hide read at launch (pure): "until I quit" ended with the last run, so it is gone; any other is kept.
pub fn at_launch(hide: Option<MainHide>) -> Option<MainHide> {
    hide.filter(|h| h.until != HideUntil::UntilQuit)
}

/// Whether the main window is hidden at `now_ms` (pure): a time that has passed no longer hides it, even before the timer
/// clears it; "until this call ends" hides until the timer sees the call is over.
pub fn main_hidden(hide: Option<&MainHide>, now_ms: i64) -> bool {
    match hide.map(|h| h.until) {
        None => false,
        Some(HideUntil::At { at_ms }) => now_ms < at_ms,
        Some(HideUntil::CallEnds | HideUntil::UntilOff | HideUntil::UntilQuit) => true,
    }
}

/// Ends the main window's hide by itself (pure; one per app, ticked every couple of seconds).
#[derive(Debug, Default)]
pub struct MainHideTimer {
    /// Since when no call has been detected, during "until this call ends".
    call_gone_since: Option<i64>,
}

impl MainHideTimer {
    /// One look: true when the hide is over and must be cleared (its time came, or the call has been gone for
    /// [`CALL_GONE_AFTER_MS`]).
    pub fn tick(&mut self, hide: Option<&MainHide>, now_ms: i64, call_detected: bool) -> bool {
        match hide.map(|h| h.until) {
            Some(HideUntil::At { at_ms }) => {
                self.call_gone_since = None;
                now_ms >= at_ms
            }
            Some(HideUntil::CallEnds) => {
                if call_detected {
                    self.call_gone_since = None;
                    return false;
                }
                let since = *self.call_gone_since.get_or_insert(now_ms);
                let over = now_ms - since >= CALL_GONE_AFTER_MS;
                if over {
                    self.call_gone_since = None;
                }
                over
            }
            Some(HideUntil::UntilOff | HideUntil::UntilQuit) | None => {
                self.call_gone_since = None;
                false
            }
        }
    }
}

/// The menus' line while the main window is hidden (pure, greyed above Hide from Screen Shares › and its Stop Hiding):
/// "Hidden from screen shares until 14:30", "… until you show it", "… until this call ends"; another day says which.
pub fn menu_line(hide: &MainHide, now: DateTime<Local>) -> String {
    format!("Hidden from screen shares {}", until_words(hide, now))
}

/// "until 14:30", "until tomorrow 08:00", "until Fri 21 Nov 14:30", "until you show it", "until this call ends" (pure).
pub fn until_words(hide: &MainHide, now: DateTime<Local>) -> String {
    match hide.until {
        HideUntil::UntilOff => "until you show it".into(),
        HideUntil::UntilQuit => "until you quit EchoLedger".into(),
        HideUntil::CallEnds => "until this call ends".into(),
        HideUntil::At { at_ms } => {
            let Some(at) = Local.timestamp_millis_opt(at_ms).single() else { return "for a while".into() };
            let days = (at.date_naive() - now.date_naive()).num_days();
            let time = at.format("%H:%M");
            match days {
                ..=0 => format!("until {time}"),
                1 => format!("until tomorrow {time}"),
                _ => format!("until {} {} {time}", at.format("%a"), at.day()).replace("  ", " "),
            }
        }
    }
}

/// The two windows that are hidden from screen shares unless the person shows them, each on its own.
#[derive(Clone, Copy, Debug, PartialEq, Eq, PartialOrd, Ord, Serialize, Deserialize)]
#[serde(rename_all = "snake_case")]
pub enum Pinned {
    /// The Live Assist answer card.
    Card,
    /// The live captions.
    Captions,
}

impl Pinned {
    pub fn of_label(label: &str) -> Option<Self> {
        match label {
            super::ASSIST => Some(Pinned::Card),
            crate::overlay::OVERLAY => Some(Pinned::Captions),
            _ => None,
        }
    }

    pub fn label(self) -> &'static str {
        match self {
            Pinned::Card => super::ASSIST,
            Pinned::Captions => crate::overlay::OVERLAY,
        }
    }

    /// The window's own switch, in its own words.
    pub fn switch_label(self, shown: bool) -> &'static str {
        match (self, shown) {
            (Pinned::Card, false) => "Show this card in screen shares",
            (Pinned::Card, true) => "Hide this card from screen shares",
            (Pinned::Captions, false) => "Show captions in screen shares",
            (Pinned::Captions, true) => "Hide captions from screen shares",
        }
    }
}

/// What a card or captions badge says: the window's real state, read back from the system.
#[derive(Clone, Copy, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case")]
pub enum WindowShare {
    /// The system says the window is left out of screen shares.
    Hidden,
    /// The system says it is in screen shares (the person chose so).
    Shown,
    /// Windows before 10 version 2004: people see a black box where the window is (WDA_MONITOR).
    BlackBox,
    /// It should be hidden, but the open window's flag did not read back as set, or the system could not say.
    CouldNotHide,
    /// The window is not open: nothing to read. It opens hidden from screen shares (the choice to show it ends with it).
    NotOpen,
}

impl WindowShare {
    pub fn badge(self) -> &'static str {
        match self {
            WindowShare::Hidden | WindowShare::NotOpen => "Hidden from screen shares",
            WindowShare::Shown => "Shown in screen shares",
            WindowShare::BlackBox => "Shows as a black box",
            WindowShare::CouldNotHide => "Couldn't hide this from screen shares",
        }
    }
}

/// What the system reads back for a window's flag.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Read {
    /// Left out of screen shares (macOS sharing type none, Windows WDA_EXCLUDEFROMCAPTURE).
    Hidden,
    /// A black box in screen shares (Windows WDA_MONITOR).
    BlackBox,
    /// In screen shares.
    Shown,
}

/// The platform layer under a badge (macOS `NSWindow.sharingType`, Windows `Set/GetWindowDisplayAffinity`): mocked in
/// tests.
pub trait ShareFlag {
    /// The window exists now.
    fn is_open(&self, label: &str) -> bool;
    /// Sets the window's flag; false when the system refused.
    fn set_share_flag(&self, label: &str, hidden: bool) -> bool;
    /// Reads the flag back; none when the system did not say.
    fn read_share_flag(&self, label: &str) -> Option<Read>;
}

/// The badge from what the system answered (pure). A window that is not open is NotOpen (never "couldn't hide": there
/// is nothing to read). For an open one only a read-back is believed: hidden is Hidden, a black box is BlackBox; a
/// window meant to be hidden whose set failed, whose read says shown, or whose read failed could not be hidden.
pub fn window_share(open: bool, wanted_hidden: bool, set_ok: bool, read: Option<Read>) -> WindowShare {
    if !open {
        return WindowShare::NotOpen;
    }
    match (wanted_hidden, read) {
        (true, _) if !set_ok => WindowShare::CouldNotHide,
        (_, Some(Read::Hidden)) => WindowShare::Hidden,
        (_, Some(Read::BlackBox)) => WindowShare::BlackBox,
        (true, _) => WindowShare::CouldNotHide,
        (false, _) => WindowShare::Shown,
    }
}

/// Sets one window's flag through the platform layer and reads it back.
pub fn apply(flag: &dyn ShareFlag, label: &str, hidden: bool) -> WindowShare {
    if !flag.is_open(label) {
        return WindowShare::NotOpen;
    }
    let set_ok = flag.set_share_flag(label, hidden);
    window_share(true, hidden, set_ok, flag.read_share_flag(label))
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::cell::RefCell;
    use std::collections::BTreeMap;

    fn at(y: i32, m: u32, d: u32, h: u32, min: u32) -> DateTime<Local> {
        Local.with_ymd_and_hms(y, m, d, h, min, 0).earliest().unwrap()
    }

    const MIN: i64 = 60_000;

    #[test]
    fn each_duration_hides_for_its_time_and_shows_again_by_itself() {
        let now = at(2026, 11, 18, 14, 0);
        let t = now.timestamp_millis();
        for (choice, minutes) in [(HideFor::Minutes15, 15), (HideFor::Hour1, 60), (HideFor::Custom { minutes: 1 }, 1), (HideFor::Custom { minutes: CUSTOM_MAX_MINUTES }, 7 * 24 * 60)] {
            let hide = hide_until(choice, now, false).unwrap();
            assert_eq!(hide.until, HideUntil::At { at_ms: t + minutes * MIN }, "{choice:?}");
            assert_eq!(hide.since_ms, t);
            let mut timer = MainHideTimer::default();
            assert!(main_hidden(Some(&hide), t + minutes * MIN - 1), "{choice:?}: hidden until its time");
            assert!(!timer.tick(Some(&hide), t + minutes * MIN - 1, false), "{choice:?}: not yet");
            assert!(!main_hidden(Some(&hide), t + minutes * MIN), "{choice:?}: shown at its time, even before the timer looks");
            assert!(timer.tick(Some(&hide), t + minutes * MIN, false), "{choice:?}: the timer clears it");
        }
    }

    #[test]
    fn until_tomorrow_is_08_00_local_the_next_day() {
        for now in [at(2026, 11, 18, 14, 0), at(2026, 11, 18, 0, 30), at(2026, 11, 18, 23, 59), at(2026, 12, 31, 9, 0)] {
            let hide = hide_until(HideFor::Tomorrow, now, false).unwrap();
            let HideUntil::At { at_ms } = hide.until else { panic!("a time") };
            let when = Local.timestamp_millis_opt(at_ms).unwrap();
            assert_eq!(when.date_naive(), now.date_naive() + ChronoDuration::days(1), "{now}");
            assert_eq!((when.format("%H:%M").to_string()), "08:00", "{now}");
            assert!(main_hidden(Some(&hide), when.timestamp_millis() - 1));
            assert!(MainHideTimer::default().tick(Some(&hide), when.timestamp_millis(), false), "shown at 08:00");
        }
    }

    #[test]
    fn until_i_turn_it_off_never_ends_by_itself() {
        let now = at(2026, 11, 18, 14, 0);
        let hide = hide_until(HideFor::UntilOff, now, true).unwrap();
        assert_eq!(hide.until, HideUntil::UntilOff);
        let mut timer = MainHideTimer::default();
        for days in [0, 1, 30, 365] {
            let later = now.timestamp_millis() + days * 24 * 60 * MIN;
            assert!(main_hidden(Some(&hide), later));
            assert!(!timer.tick(Some(&hide), later, false));
        }
    }

    #[test]
    fn custom_is_one_minute_to_seven_days() {
        let now = at(2026, 11, 18, 14, 0);
        assert_eq!(hide_until(HideFor::Custom { minutes: 0 }, now, false), Err(HideRefused::CustomOutOfRange));
        assert_eq!(hide_until(HideFor::Custom { minutes: CUSTOM_MAX_MINUTES + 1 }, now, false), Err(HideRefused::CustomOutOfRange));
        assert!(hide_until(HideFor::Custom { minutes: 90 }, now, false).is_ok());
        assert_eq!(HideRefused::CustomOutOfRange.message(), "Choose between 1 minute and 7 days.");
    }

    #[test]
    fn until_this_call_ends_needs_a_call_and_clears_when_it_ends() {
        let now = at(2026, 11, 18, 14, 0);
        let t = now.timestamp_millis();
        assert_eq!(hide_until(HideFor::CallEnds, now, false), Err(HideRefused::NoCall), "offered only while a call is detected");
        assert_eq!(choices(false), ["15 min", "1 hour", "Until tomorrow", "Until I turn it off", "Custom…"]);
        assert_eq!(choices(true)[0], "Until this call ends");
        let hide = hide_until(HideFor::CallEnds, now, true).unwrap();
        assert_eq!(hide.until, HideUntil::CallEnds);
        let mut timer = MainHideTimer::default();
        // Two hours of call: still hidden.
        for s in (0..7200).step_by(2) {
            assert!(!timer.tick(Some(&hide), t + s * 1000, true));
        }
        // One missed look of the detector is not the end of the call.
        let gap = t + 7_200_000;
        assert!(!timer.tick(Some(&hide), gap, false));
        assert!(!timer.tick(Some(&hide), gap + 2_000, false));
        assert!(!timer.tick(Some(&hide), gap + 4_000, true), "the call is back");
        assert!(!timer.tick(Some(&hide), gap + 6_000, false));
        assert!(!timer.tick(Some(&hide), gap + 6_000 + CALL_GONE_AFTER_MS - 1, false));
        assert!(main_hidden(Some(&hide), gap + 10_000), "hidden until the timer sees the call is over");
        assert!(timer.tick(Some(&hide), gap + 6_000 + CALL_GONE_AFTER_MS, false), "the call is over: shown again");
    }

    #[test]
    fn nothing_hidden_is_shown() {
        assert!(!main_hidden(None, 0));
        assert!(!MainHideTimer::default().tick(None, 0, false));
    }

    #[test]
    fn the_menus_say_until_when() {
        let now = at(2026, 11, 18, 14, 0);
        let fifteen = hide_until(HideFor::Minutes15, now, false).unwrap();
        assert_eq!(menu_line(&fifteen, now), "Hidden from screen shares until 14:15");
        let half_past = MainHide { until: HideUntil::At { at_ms: at(2026, 11, 18, 14, 30).timestamp_millis() }, since_ms: 0 };
        assert_eq!(menu_line(&half_past, now), "Hidden from screen shares until 14:30");
        assert_eq!(menu_line(&hide_until(HideFor::UntilOff, now, false).unwrap(), now), "Hidden from screen shares until you show it");
        assert_eq!(menu_line(&hide_until(HideFor::CallEnds, now, true).unwrap(), now), "Hidden from screen shares until this call ends");
        assert_eq!(menu_line(&hide_until(HideFor::Tomorrow, now, false).unwrap(), now), "Hidden from screen shares until tomorrow 08:00");
        let week = hide_until(HideFor::Custom { minutes: 3 * 24 * 60 }, now, false).unwrap();
        assert_eq!(menu_line(&week, now), "Hidden from screen shares until Sat 21 14:00");
    }

    /// The platform layer, as the system would answer.
    #[derive(Default)]
    struct FakeFlag {
        closed: bool,
        /// Windows before 2004: a hidden flag reads back as a black box.
        black_box: bool,
        refuse_set: bool,
        /// What the system reads back, by label; none when its call fails.
        keeps: bool,
        read_fails: bool,
        flags: RefCell<BTreeMap<String, bool>>,
    }

    impl ShareFlag for FakeFlag {
        fn is_open(&self, _label: &str) -> bool {
            !self.closed
        }
        fn set_share_flag(&self, label: &str, hidden: bool) -> bool {
            if self.refuse_set {
                return false;
            }
            self.flags.borrow_mut().insert(label.into(), hidden && self.keeps);
            true
        }
        fn read_share_flag(&self, label: &str) -> Option<Read> {
            if self.read_fails {
                return None;
            }
            Some(match (*self.flags.borrow().get(label).unwrap_or(&false), self.black_box) {
                (true, false) => Read::Hidden,
                (true, true) => Read::BlackBox,
                (false, _) => Read::Shown,
            })
        }
    }

    #[test]
    fn the_badge_says_what_the_system_reads_back() {
        let ok = FakeFlag { keeps: true, ..FakeFlag::default() };
        assert_eq!(apply(&ok, "assist", true), WindowShare::Hidden);
        assert_eq!(apply(&ok, "assist", false), WindowShare::Shown);
        // The system took the call but did not keep the window hidden: the badge says so, not the saved choice.
        let dropped = FakeFlag { keeps: false, ..FakeFlag::default() };
        assert_eq!(apply(&dropped, "overlay", true), WindowShare::CouldNotHide);
        // The system refused the call.
        let refused = FakeFlag { refuse_set: true, keeps: true, ..FakeFlag::default() };
        assert_eq!(apply(&refused, "overlay", true), WindowShare::CouldNotHide);
        // The system could not say.
        let silent = FakeFlag { keeps: true, read_fails: true, ..FakeFlag::default() };
        assert_eq!(apply(&silent, "assist", true), WindowShare::CouldNotHide);
        assert_eq!(apply(&silent, "assist", false), WindowShare::Shown, "shown on purpose: nothing to hide");
        assert_eq!(WindowShare::CouldNotHide.badge(), "Couldn't hide this from screen shares");
        assert_eq!(WindowShare::Hidden.badge(), "Hidden from screen shares");
        assert_eq!(WindowShare::Shown.badge(), "Shown in screen shares");
        // Older Windows: a black box, said as such.
        let old = FakeFlag { keeps: true, black_box: true, ..FakeFlag::default() };
        assert_eq!(apply(&old, "overlay", true), WindowShare::BlackBox);
        assert_eq!(WindowShare::BlackBox.badge(), "Shows as a black box");
    }

    #[test]
    fn a_window_that_is_not_open_is_not_open_never_couldn_t_hide() {
        // Settings asks about the card while no call is on: nothing to read, and it will open hidden.
        let closed = FakeFlag { closed: true, keeps: false, read_fails: true, ..FakeFlag::default() };
        assert_eq!(apply(&closed, "assist", true), WindowShare::NotOpen);
        assert_eq!(WindowShare::NotOpen.badge(), "Hidden from screen shares");
        assert_eq!(window_share(false, true, false, None), WindowShare::NotOpen);
        // An open window that reads back wrong still says it could not be hidden.
        assert_eq!(window_share(true, true, true, Some(Read::Shown)), WindowShare::CouldNotHide);
        assert_eq!(window_share(true, true, true, None), WindowShare::CouldNotHide);
    }

    #[test]
    fn the_card_and_captions_switches_are_their_own() {
        assert_eq!(Pinned::of_label(super::super::ASSIST), Some(Pinned::Card));
        assert_eq!(Pinned::of_label(crate::overlay::OVERLAY), Some(Pinned::Captions));
        assert_eq!(Pinned::of_label(crate::lifecycle::MAIN), None);
        assert_eq!(Pinned::of_label(crate::overlay::PILL), None);
        assert_eq!(Pinned::Card.switch_label(false), "Show this card in screen shares");
        assert_eq!(Pinned::Captions.switch_label(true), "Hide captions from screen shares");
    }
}
