//! Audio devices that change during a recording (Phase 1B): a Bluetooth headset connecting or dropping, a USB headset
//! pulled out, the default device switched in the menu. The recording never ends and never goes silent without saying
//! so: the stream is opened again on the new device (the timeline fills the gap with silence), a line goes to the log,
//! and the pill and the Record page say what happened.
//!
//! The decisions are here, pure and tested with fakes; the platform backends only read the system and open streams.
//! Windows opens one stream per channel and uses [`Reopener`] for each; the Mac opens one aggregate device for both
//! channels and runs [`Supervisor`], which adds watching the device route.

use std::collections::VecDeque;
use std::sync::{Arc, Mutex};
use std::time::{Duration, Instant};

use serde::Serialize;

use super::types::Channel;

/// The first wait before opening again, and the longest one.
pub const FIRST_RETRY: Duration = Duration::from_millis(250);
pub const LONGEST_RETRY: Duration = Duration::from_secs(3);
/// A device still gone after this long is said out loud.
pub const LOST_AFTER: Duration = Duration::from_secs(3);
/// A stream that ran this long was fine: the next failure starts the waits over.
pub const HEALTHY_AFTER: Duration = Duration::from_secs(10);
/// A running stream that delivers nothing for this long has stalled (a device died without telling anyone).
pub const STALL_AFTER: Duration = Duration::from_secs(4);
/// A new stream's first packet may take longer: macOS's own permission prompt can hold it back.
pub const FIRST_PACKET_WITHIN: Duration = Duration::from_secs(10);
/// How long "Microphone changed to …" stays.
pub const MOVED_NOTE_FOR: Duration = Duration::from_secs(8);

/// Something the person should know about the devices of the running recording.
#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "snake_case", tag = "kind")]
pub enum DeviceNotice {
    /// The channel now records from another device.
    Moved { channel: Channel, name: Option<String> },
    /// The channel has had no device for a while; the recording goes on without it.
    Lost { channel: Channel },
    /// A lost channel has a device again.
    Back { channel: Channel, name: Option<String> },
}

impl DeviceNotice {
    /// The line for the log: which side and what happened, never the device's name.
    pub fn log_line(&self) -> String {
        match self {
            DeviceNotice::Moved { channel, .. } => format!("{channel:?} capture moved to another device; the recording goes on"),
            DeviceNotice::Lost { channel } => format!("{channel:?} capture has no device; the recording goes on without it"),
            DeviceNotice::Back { channel, .. } => format!("{channel:?} capture has a device again"),
        }
    }
}

/// What the capture threads have to say until the core next asks (once a second while recording).
#[derive(Clone, Debug, Default)]
pub struct DeviceLog(Arc<Mutex<VecDeque<DeviceNotice>>>);

impl DeviceLog {
    pub fn push(&self, notice: DeviceNotice) {
        eprintln!("[echoledger] {}", notice.log_line());
        let mut queue = self.0.lock().unwrap_or_else(|p| p.into_inner());
        if queue.len() < 64 {
            queue.push_back(notice);
        }
    }

    pub fn take(&self) -> Vec<DeviceNotice> {
        self.0.lock().unwrap_or_else(|p| p.into_inner()).drain(..).collect()
    }
}

/// One channel's way back to a device: how long to wait, when to say it is gone, what to say when it returns.
#[derive(Clone, Debug)]
pub struct Reopener {
    channel: Channel,
    wait: Duration,
    /// When the channel last lost its stream, until it has one again.
    down_since: Option<Instant>,
    said_lost: bool,
    open_since: Option<Instant>,
    opened_before: bool,
    failures: u32,
}

impl Reopener {
    pub fn new(channel: Channel) -> Self {
        Self { channel, wait: FIRST_RETRY, down_since: None, said_lost: false, open_since: None, opened_before: false, failures: 0 }
    }

    /// The stream is open. The first open says nothing; later ones say where the channel went.
    pub fn opened(&mut self, now: Instant, name: Option<String>) -> Option<DeviceNotice> {
        let reopened = self.opened_before;
        let said_lost = self.said_lost;
        self.opened_before = true;
        self.open_since = Some(now);
        self.down_since = None;
        self.said_lost = false;
        self.failures = 0;
        if said_lost {
            Some(DeviceNotice::Back { channel: self.channel, name })
        } else if reopened {
            Some(DeviceNotice::Moved { channel: self.channel, name })
        } else {
            None
        }
    }

    /// The stream ended or could not be opened: how long to wait, and the notice when the device has been gone long
    /// enough to say so (once per loss).
    pub fn failed(&mut self, now: Instant) -> (Duration, Option<DeviceNotice>) {
        if self.open_since.take().is_some_and(|since| now.saturating_duration_since(since) >= HEALTHY_AFTER) {
            self.wait = FIRST_RETRY;
        }
        self.failures = self.failures.saturating_add(1);
        let since = *self.down_since.get_or_insert(now);
        let notice = (!self.said_lost && now.saturating_duration_since(since) >= LOST_AFTER).then(|| {
            self.said_lost = true;
            DeviceNotice::Lost { channel: self.channel }
        });
        let wait = self.wait;
        self.wait = (self.wait * 2).min(LONGEST_RETRY);
        (wait, notice)
    }

    /// Whether the channel ever had a stream in this recording.
    pub fn ever_opened(&self) -> bool {
        self.opened_before
    }

    /// Failures in a row since the last open.
    pub fn failures(&self) -> u32 {
        self.failures
    }

    pub fn is_lost(&self) -> bool {
        self.said_lost
    }
}

/// Windows: whether a failed process-loopback stream gives way to endpoint loopback (all system sound). At once when it
/// never opened (the feature does not work on this computer), after three failures in a row when it did.
pub fn fall_back_to_endpoint(ever_opened: bool, failures: u32) -> bool {
    if ever_opened { failures >= 3 } else { failures >= 1 }
}

/// The devices a recording should be on (their ids, never shown): `None` when the system has none.
#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct Route {
    pub mic: Option<String>,
    pub output: Option<String>,
}

/// Why a running stream must be opened again.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Change {
    Mic,
    Output,
    /// The device's sample rate moved under the stream (a Bluetooth headset switching profile), so its audio would
    /// come out at the wrong speed.
    Rate,
    Stalled,
}

impl Change {
    fn says(self) -> &'static str {
        match self {
            Change::Mic => "the microphone changed",
            Change::Output => "the sound output changed",
            Change::Rate => "the sample rate changed",
            Change::Stalled => "no audio arrived",
        }
    }
}

/// What the Mac's supervisor needs from the system.
pub trait CaptureHost {
    type Stream;
    /// The devices a recording should be on now.
    fn want(&self) -> Route;
    fn open(&mut self, route: &Route) -> Result<Self::Stream, String>;
    fn close(&mut self, stream: Self::Stream);
    /// Packets the stream delivered so far.
    fn packets(&self, stream: &Self::Stream) -> u64;
    /// The device's sample rate is no longer the one the stream opened with.
    fn rate_moved(&self, stream: &Self::Stream) -> bool;
    /// A device's name for the person (only ever shown on this computer).
    fn device_name(&self, id: &str) -> Option<String>;
}

/// Watches one open stream and its route, and keeps a stream open on the right devices while a recording runs. Call
/// [`Supervisor::tick`] about once a second.
pub struct Supervisor<S> {
    stream: Option<S>,
    route: Route,
    /// The microphone and the system channel share the one stream: both are told what happens to it.
    wants_mic: bool,
    wants_system: bool,
    mic: Reopener,
    system: Reopener,
    next_try: Option<Instant>,
    last_packets: u64,
    last_packet_at: Instant,
    /// Streams open but deliver nothing: since when, and whether the person was told.
    stalled_since: Option<Instant>,
    said_stalled: bool,
}

impl<S> Supervisor<S> {
    /// `stream` is the first stream, already open on `route`.
    pub fn new(stream: S, route: Route, wants_mic: bool, wants_system: bool, now: Instant) -> Self {
        let (mut mic, mut system) = (Reopener::new(Channel::Mic), Reopener::new(Channel::System));
        mic.opened(now, None);
        system.opened(now, None);
        Self { stream: Some(stream), route, wants_mic, wants_system, mic, system, next_try: None, last_packets: 0, last_packet_at: now, stalled_since: None, said_stalled: false }
    }

    pub fn is_open(&self) -> bool {
        self.stream.is_some()
    }

    /// The stream, to close it when the recording stops.
    pub fn take_stream(&mut self) -> Option<S> {
        self.stream.take()
    }

    /// Why the open stream must go, if it must.
    fn change<H: CaptureHost<Stream = S>>(&self, host: &H, want: &Route, now: Instant) -> Option<Change> {
        let stream = self.stream.as_ref()?;
        if self.wants_mic && want.mic != self.route.mic {
            return Some(Change::Mic);
        }
        if want.output != self.route.output {
            return Some(Change::Output);
        }
        if host.rate_moved(stream) {
            return Some(Change::Rate);
        }
        let limit = if self.last_packets == 0 { FIRST_PACKET_WITHIN } else { STALL_AFTER };
        (now.saturating_duration_since(self.last_packet_at) >= limit).then_some(Change::Stalled)
    }

    /// The channels this recording records.
    fn channels(&self) -> Vec<Channel> {
        let mut channels = Vec::new();
        if self.wants_mic {
            channels.push(Channel::Mic);
        }
        if self.wants_system {
            channels.push(Channel::System);
        }
        channels
    }

    /// One look at the system. Moves the stream when the devices changed, keeps trying while it cannot open, and
    /// returns what the person should be told.
    pub fn tick<H: CaptureHost<Stream = S>>(&mut self, host: &mut H, now: Instant) -> Vec<DeviceNotice> {
        let mut notices = Vec::new();
        let want = host.want();
        if let Some(stream) = &self.stream {
            let packets = host.packets(stream);
            if packets != self.last_packets {
                self.last_packets = packets;
                self.last_packet_at = now;
                self.stalled_since = None;
                if std::mem::take(&mut self.said_stalled) {
                    notices.extend(self.channels().into_iter().map(|channel| DeviceNotice::Back { channel, name: None }));
                }
            }
        }
        // A microphone that is simply absent stays lost while the other side records on.
        if self.stream.is_some() && self.wants_mic && self.route.mic.is_none() {
            notices.extend(self.mic.failed(now).1);
        }
        if let Some(change) = self.change(host, &want, now) {
            eprintln!("[echoledger] capture: {}; opening the stream again", change.says());
            if change == Change::Stalled {
                // A stream that keeps delivering nothing is the same as no device: said once, until audio arrives.
                let since = *self.stalled_since.get_or_insert(now);
                if !self.said_stalled && now.saturating_duration_since(since) >= STALL_AFTER {
                    self.said_stalled = true;
                    notices.extend(self.channels().into_iter().map(|channel| DeviceNotice::Lost { channel }));
                }
            }
            if let Some(stream) = self.stream.take() {
                host.close(stream);
            }
            self.next_try = Some(now);
            // A move to a new device is not a failure: it says Moved when it opens.
            let _ = self.mic.failed(now);
            let _ = self.system.failed(now);
        }
        if self.stream.is_some() {
            return notices;
        }
        if self.next_try.is_some_and(|at| now < at) {
            return notices;
        }
        let (mic_was, output_was) = (self.route.mic.clone(), self.route.output.clone());
        match host.open(&want) {
            Ok(stream) => {
                self.stream = Some(stream);
                self.last_packets = 0;
                self.last_packet_at = now;
                self.next_try = None;
                // A microphone that is simply absent leaves the system channel recording: the microphone is lost.
                if self.wants_mic {
                    match &want.mic {
                        Some(id) => {
                            let name = host.device_name(id);
                            let notice = self.mic.opened(now, name);
                            // Only a microphone that really changed is news; a stream reopened for the speakers is not.
                            let moved = mic_was.as_deref() != Some(id.as_str());
                            notices.extend(notice.filter(|n| moved || matches!(n, DeviceNotice::Back { .. })));
                        }
                        None => {
                            let (_, notice) = self.mic.failed(now);
                            notices.extend(notice);
                        }
                    }
                }
                if self.wants_system {
                    let name = want.output.as_deref().and_then(|id| host.device_name(id));
                    let notice = self.system.opened(now, name);
                    let moved = output_was != want.output;
                    notices.extend(notice.filter(|n| moved || matches!(n, DeviceNotice::Back { .. })));
                }
                self.route = want;
            }
            Err(error) => {
                eprintln!("[echoledger] capture could not open: {error}");
                let (wait, notice) = if self.wants_system { self.system.failed(now) } else { self.mic.failed(now) };
                notices.extend(notice);
                if self.wants_system && self.wants_mic {
                    let (_, notice) = self.mic.failed(now);
                    notices.extend(notice);
                }
                self.next_try = Some(now + wait);
            }
        }
        notices
    }
}

/// One line about the recording's devices, for the pill and the Record page.
#[derive(Clone, Debug, PartialEq, Eq, Serialize)]
#[serde(rename_all = "camelCase")]
pub struct DeviceNote {
    pub text: String,
    /// Something is missing from the recording right now (not just news).
    pub warning: bool,
}

/// What the person is told about the running recording's devices: a lost channel until it returns, news for a few
/// seconds.
#[derive(Clone, Debug, Default)]
pub struct DeviceStatus {
    lost: [bool; 2],
    news: Option<(String, Instant)>,
}

impl DeviceStatus {
    pub fn apply(&mut self, notice: &DeviceNotice, now: Instant) {
        match notice {
            DeviceNotice::Moved { channel, name } => self.tell(*channel, name.as_deref(), now),
            DeviceNotice::Lost { channel } => {
                self.lost[channel.index()] = true;
                self.news = None;
            }
            DeviceNotice::Back { channel, name } => {
                self.lost[channel.index()] = false;
                self.tell(*channel, name.as_deref(), now);
            }
        }
    }

    /// One line of news. A headset that takes over both sides at once is told by its microphone, which is what the
    /// person is speaking into.
    fn tell(&mut self, channel: Channel, name: Option<&str>, now: Instant) {
        let same_moment = self.news.as_ref().is_some_and(|(_, at)| *at == now);
        if channel == Channel::Mic || !same_moment {
            self.news = Some((moved_sentence(channel, name), now));
        }
    }

    pub fn note(&self, now: Instant) -> Option<DeviceNote> {
        let (mic, system) = (self.lost[Channel::Mic.index()], self.lost[Channel::System.index()]);
        let warning = match (mic, system) {
            (true, true) => Some("No sound devices: nothing is recorded until one returns."),
            (true, false) => Some("No microphone: recording the other side only."),
            (false, true) => Some("No computer sound: recording your voice only."),
            (false, false) => None,
        };
        if let Some(text) = warning {
            return Some(DeviceNote { text: text.to_string(), warning: true });
        }
        self.news
            .as_ref()
            .filter(|(_, at)| now.saturating_duration_since(*at) < MOVED_NOTE_FOR)
            .map(|(text, _)| DeviceNote { text: text.clone(), warning: false })
    }
}

fn moved_sentence(channel: Channel, name: Option<&str>) -> String {
    match (channel, name.map(str::trim).filter(|n| !n.is_empty())) {
        (Channel::Mic, Some(name)) => format!("Microphone changed to {name}. Still recording."),
        (Channel::Mic, None) => "Microphone changed. Still recording.".to_string(),
        (Channel::System, Some(name)) => format!("Sound output changed to {name}. Still recording."),
        (Channel::System, None) => "Sound output changed. Still recording.".to_string(),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_first_open_says_nothing_and_a_new_device_says_where_it_went() {
        let t0 = Instant::now();
        let mut r = Reopener::new(Channel::Mic);
        assert_eq!(r.opened(t0, Some("MacBook Pro Microphone".into())), None);
        let (wait, notice) = r.failed(t0 + Duration::from_secs(60));
        assert_eq!((wait, notice), (FIRST_RETRY, None), "a quick switch waits a moment and says nothing yet");
        let back = r.opened(t0 + Duration::from_secs(61), Some("AirPods".into()));
        assert_eq!(back, Some(DeviceNotice::Moved { channel: Channel::Mic, name: Some("AirPods".into()) }));
    }

    #[test]
    fn a_device_gone_for_a_while_is_said_once_and_its_return_too() {
        let t0 = Instant::now();
        let mut r = Reopener::new(Channel::Mic);
        r.opened(t0, None);
        assert_eq!(r.failed(t0 + Duration::from_secs(20)).1, None);
        assert_eq!(r.failed(t0 + Duration::from_secs(21)).1, None, "not yet");
        assert_eq!(r.failed(t0 + Duration::from_secs(24)).1, Some(DeviceNotice::Lost { channel: Channel::Mic }));
        assert_eq!(r.failed(t0 + Duration::from_secs(30)).1, None, "said once per loss");
        assert!(r.is_lost());
        let back = r.opened(t0 + Duration::from_secs(31), Some("Built-in".into()));
        assert_eq!(back, Some(DeviceNotice::Back { channel: Channel::Mic, name: Some("Built-in".into()) }));
        assert!(!r.is_lost());
        // A later loss is a new one.
        assert_eq!(r.failed(t0 + Duration::from_secs(50)).1, None);
        assert_eq!(r.failed(t0 + Duration::from_secs(54)).1, Some(DeviceNotice::Lost { channel: Channel::Mic }));
    }

    #[test]
    fn the_waits_grow_to_a_ceiling_and_start_over_after_a_healthy_stretch() {
        let t0 = Instant::now();
        let mut r = Reopener::new(Channel::System);
        let waits: Vec<Duration> = (0..6).map(|i| r.failed(t0 + Duration::from_millis(i)).0).collect();
        assert_eq!(waits[0], FIRST_RETRY);
        assert!(waits.windows(2).all(|w| w[1] >= w[0]));
        assert_eq!(*waits.last().unwrap(), LONGEST_RETRY);
        r.opened(t0, None);
        assert_eq!(r.failed(t0 + HEALTHY_AFTER).0, FIRST_RETRY, "ten good seconds forget the old trouble");
        r.opened(t0, None);
        assert!(r.failed(t0 + Duration::from_secs(1)).0 > FIRST_RETRY, "a stream that keeps dying keeps waiting longer");
    }

    #[test]
    fn process_loopback_gives_way_at_once_if_it_never_worked_and_only_after_retries_if_it_did() {
        assert!(fall_back_to_endpoint(false, 1));
        assert!(!fall_back_to_endpoint(true, 1));
        assert!(!fall_back_to_endpoint(true, 2));
        assert!(fall_back_to_endpoint(true, 3));
    }

    /// A pretend computer: its devices, whether opening works, what was opened.
    struct FakeHost {
        want: Route,
        fail_open: bool,
        packets: u64,
        rate_moved: bool,
        opened: Vec<Route>,
        closed: u32,
    }

    impl FakeHost {
        fn new(mic: Option<&str>, output: Option<&str>) -> Self {
            Self {
                want: Route { mic: mic.map(String::from), output: output.map(String::from) },
                fail_open: false,
                packets: 0,
                rate_moved: false,
                opened: Vec::new(),
                closed: 0,
            }
        }
    }

    impl CaptureHost for FakeHost {
        type Stream = u32;
        fn want(&self) -> Route {
            self.want.clone()
        }
        fn open(&mut self, route: &Route) -> Result<u32, String> {
            if self.fail_open {
                return Err("no device".into());
            }
            self.opened.push(route.clone());
            Ok(self.opened.len() as u32)
        }
        fn close(&mut self, _stream: u32) {
            self.closed += 1;
        }
        fn packets(&self, _stream: &u32) -> u64 {
            self.packets
        }
        fn rate_moved(&self, _stream: &u32) -> bool {
            self.rate_moved
        }
        fn device_name(&self, id: &str) -> Option<String> {
            Some(format!("name of {id}"))
        }
    }

    fn at(t0: Instant, seconds: u64) -> Instant {
        t0 + Duration::from_secs(seconds)
    }

    fn start(host: &FakeHost, t0: Instant) -> Supervisor<u32> {
        Supervisor::new(0, host.want(), true, true, t0)
    }

    #[test]
    fn nothing_changes_nothing_happens() {
        let t0 = Instant::now();
        let mut host = FakeHost::new(Some("mic"), Some("speakers"));
        let mut s = start(&host, t0);
        for second in 1..4 {
            host.packets += 100;
            assert!(s.tick(&mut host, at(t0, second)).is_empty());
        }
        assert_eq!((host.opened.len(), host.closed), (0, 0));
    }

    #[test]
    fn a_headset_connecting_moves_the_recording_to_it_and_says_so() {
        let t0 = Instant::now();
        let mut host = FakeHost::new(Some("built-in"), Some("speakers"));
        let mut s = start(&host, t0);
        host.want = Route { mic: Some("airpods".into()), output: Some("airpods".into()) };
        let notices = s.tick(&mut host, at(t0, 1));
        assert_eq!(host.closed, 1, "the old stream went");
        assert_eq!(host.opened, vec![host.want.clone()], "the new one is on the headset");
        assert_eq!(
            notices,
            vec![
                DeviceNotice::Moved { channel: Channel::Mic, name: Some("name of airpods".into()) },
                DeviceNotice::Moved { channel: Channel::System, name: Some("name of airpods".into()) },
            ]
        );
        assert!(s.tick(&mut host, at(t0, 2)).is_empty(), "settled");
    }

    #[test]
    fn only_the_side_that_changed_is_told() {
        let t0 = Instant::now();
        let mut host = FakeHost::new(Some("built-in"), Some("speakers"));
        let mut s = start(&host, t0);
        host.want.output = Some("hdmi".into());
        let notices = s.tick(&mut host, at(t0, 1));
        assert_eq!(notices, vec![DeviceNotice::Moved { channel: Channel::System, name: Some("name of hdmi".into()) }]);
    }

    #[test]
    fn the_last_microphone_going_keeps_the_other_side_recording_and_says_so() {
        let t0 = Instant::now();
        let mut host = FakeHost::new(Some("usb"), Some("speakers"));
        let mut s = start(&host, t0);
        host.want.mic = None;
        assert!(s.tick(&mut host, at(t0, 20)).is_empty(), "a moment first: it may be a switch");
        assert!(s.is_open(), "the system channel goes on");
        assert_eq!(host.opened.last().unwrap().mic, None);
        // Still none a few seconds later: said once.
        let mut said = Vec::new();
        for second in 21..30 {
            host.packets += 100;
            said.extend(s.tick(&mut host, at(t0, second)));
        }
        assert_eq!(said, vec![DeviceNotice::Lost { channel: Channel::Mic }]);
        host.want.mic = Some("usb".into());
        let notices = s.tick(&mut host, at(t0, 30));
        assert_eq!(notices, vec![DeviceNotice::Back { channel: Channel::Mic, name: Some("name of usb".into()) }]);
    }

    #[test]
    fn a_stream_that_never_delivers_is_said_once_and_its_return_too() {
        let t0 = Instant::now();
        let mut host = FakeHost::new(Some("mic"), Some("speakers"));
        let mut s = start(&host, t0);
        for second in 1..10 {
            assert!(s.tick(&mut host, at(t0, second)).is_empty());
            assert_eq!(host.closed, 0, "a first packet gets time: macOS may be asking for permission");
        }
        let mut said = Vec::new();
        for second in 10..=25 {
            said.extend(s.tick(&mut host, at(t0, second)));
        }
        assert!(host.closed >= 2, "it keeps trying");
        assert_eq!(said, vec![DeviceNotice::Lost { channel: Channel::Mic }, DeviceNotice::Lost { channel: Channel::System }]);
        host.packets = 7;
        let back = s.tick(&mut host, at(t0, 26));
        assert_eq!(back, vec![DeviceNotice::Back { channel: Channel::Mic, name: None }, DeviceNotice::Back { channel: Channel::System, name: None }]);
    }

    #[test]
    fn when_nothing_can_open_it_keeps_trying_says_so_and_comes_back() {
        let t0 = Instant::now();
        let mut host = FakeHost::new(Some("mic"), Some("speakers"));
        let mut s = start(&host, t0);
        host.want.output = Some("gone".into());
        host.fail_open = true;
        let mut lost = Vec::new();
        for second in 20..30 {
            lost.extend(s.tick(&mut host, at(t0, second)));
        }
        assert!(!s.is_open());
        assert!(lost.contains(&DeviceNotice::Lost { channel: Channel::System }), "{lost:?}");
        assert!(lost.contains(&DeviceNotice::Lost { channel: Channel::Mic }), "{lost:?}");
        assert_eq!(lost.len(), 2, "said once each: {lost:?}");
        host.fail_open = false;
        let back = s.tick(&mut host, at(t0, 40));
        assert!(s.is_open(), "it recovered by itself");
        assert_eq!(back.len(), 2);
        assert!(back.iter().all(|n| matches!(n, DeviceNotice::Back { .. })), "{back:?}");
    }

    #[test]
    fn a_stream_that_stops_delivering_is_opened_again() {
        let t0 = Instant::now();
        let mut host = FakeHost::new(Some("mic"), Some("speakers"));
        let mut s = start(&host, t0);
        host.packets = 10;
        assert!(s.tick(&mut host, at(t0, 1)).is_empty());
        for second in 2..5 {
            assert!(s.tick(&mut host, at(t0, second)).is_empty());
            assert_eq!(host.closed, 0, "not yet at {second} s");
        }
        s.tick(&mut host, at(t0, 5));
        assert_eq!((host.closed, host.opened.len()), (1, 1), "four seconds without a packet: reopened");
    }

    #[test]
    fn a_sample_rate_moved_by_a_bluetooth_profile_reopens_without_news() {
        let t0 = Instant::now();
        let mut host = FakeHost::new(Some("airpods"), Some("airpods"));
        let mut s = start(&host, t0);
        host.rate_moved = true;
        let notices = s.tick(&mut host, at(t0, 1));
        assert_eq!((host.closed, host.opened.len()), (1, 1), "the stream reads the new rate");
        assert!(notices.is_empty(), "the same devices: nothing to tell");
    }

    #[test]
    fn a_voice_note_of_this_computer_s_sound_ignores_the_microphone() {
        let t0 = Instant::now();
        let mut host = FakeHost::new(None, Some("speakers"));
        let mut s: Supervisor<u32> = Supervisor::new(0, host.want(), false, true, t0);
        host.want.mic = Some("airpods".into());
        host.packets = 1;
        assert!(s.tick(&mut host, at(t0, 1)).is_empty());
        assert_eq!(host.closed, 0);
    }

    #[test]
    fn the_person_is_told_what_is_missing_until_it_returns_and_news_for_a_few_seconds() {
        let t0 = Instant::now();
        let mut status = DeviceStatus::default();
        assert_eq!(status.note(t0), None);
        status.apply(&DeviceNotice::Moved { channel: Channel::Mic, name: Some("AirPods Pro".into()) }, t0);
        let note = status.note(t0).unwrap();
        assert!(note.text.contains("AirPods Pro") && !note.warning);
        assert_eq!(status.note(t0 + MOVED_NOTE_FOR), None, "news goes away");

        status.apply(&DeviceNotice::Lost { channel: Channel::Mic }, t0);
        let note = status.note(t0 + Duration::from_secs(600)).unwrap();
        assert!(note.warning && note.text.contains("other side only"), "stays while it is missing: {note:?}");
        status.apply(&DeviceNotice::Lost { channel: Channel::System }, t0);
        assert!(status.note(t0).unwrap().text.contains("until one returns"));
        status.apply(&DeviceNotice::Back { channel: Channel::Mic, name: None }, t0);
        assert!(status.note(t0).unwrap().text.contains("your voice only"));
        let later = t0 + Duration::from_secs(1);
        status.apply(&DeviceNotice::Back { channel: Channel::System, name: Some("Speakers".into()) }, later);
        let note = status.note(later).unwrap();
        assert!(!note.warning && note.text.contains("Speakers"));
    }

    #[test]
    fn a_headset_taking_over_both_sides_is_told_by_its_microphone() {
        let t0 = Instant::now();
        let mut status = DeviceStatus::default();
        status.apply(&DeviceNotice::Moved { channel: Channel::Mic, name: Some("AirPods Pro".into()) }, t0);
        status.apply(&DeviceNotice::Moved { channel: Channel::System, name: Some("AirPods Pro".into()) }, t0);
        assert!(status.note(t0).unwrap().text.starts_with("Microphone changed"));
        // Later news of the other side is its own.
        let later = t0 + Duration::from_secs(1);
        status.apply(&DeviceNotice::Moved { channel: Channel::System, name: Some("HDMI".into()) }, later);
        assert!(status.note(later).unwrap().text.starts_with("Sound output changed to HDMI"));
    }

    #[test]
    fn the_log_never_names_a_device() {
        let line = DeviceNotice::Moved { channel: Channel::Mic, name: Some("Hassam's AirPods".into()) }.log_line();
        assert!(line.contains("Mic") && !line.contains("Hassam"), "{line}");
        let log = DeviceLog::default();
        log.push(DeviceNotice::Lost { channel: Channel::Mic });
        assert_eq!(log.take().len(), 1);
        assert!(log.take().is_empty(), "taken once");
    }
}
