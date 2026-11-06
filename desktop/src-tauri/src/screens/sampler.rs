//! One second of the capture thread: the privacy gate, the window or screen to capture, one frame and the detector.
//! It reaches the system only through [`ScreenSystem`], so tests drive it with a stand-in system and synthetic frames.

use std::sync::atomic::{AtomicBool, Ordering};

use super::detect::{Detector, Luma, Trigger};
use super::platform::{Frame, Grab, ScreenSystem, Wanted};
use super::privacy::{Facts, Gate, HoldReason, OffReason, gate};

/// What one second came to.
pub enum Sample {
    /// Nothing is captured for this recording (screens off, not in the plan, the project's switch…).
    Off(OffReason),
    /// The capture holds for now, and why (it is closed meanwhile, so Windows 10 drops its border too).
    Held(HoldReason),
    /// The capture looks at its target and found nothing new to keep.
    Watching,
    /// Keep this frame.
    Keep { frame: Frame, trigger: Trigger },
}

/// The capture of one recording: its detector and the open capture.
pub struct Sampler<'a> {
    system: &'a dyn ScreenSystem,
    detector: Detector,
    capture: Option<Box<dyn Grab>>,
}

impl<'a> Sampler<'a> {
    pub fn new(system: &'a dyn ScreenSystem) -> Self {
        Self { system, detector: Detector::new(), capture: None }
    }

    /// The capture is open now.
    pub fn open(&self) -> bool {
        self.capture.is_some()
    }

    /// One sample at `now_ms` (any monotonic clock). `facts` comes from the app; the system's support and permission
    /// and the hour's cap are filled in here. `words`: pointing words were heard since the last frame (taken when a
    /// frame is looked at, dropped while the capture holds).
    pub fn tick(&mut self, facts: Facts, wanted: &Wanted<'_>, now_ms: u64, words: &AtomicBool) -> Sample {
        let facts = Facts { supported: self.system.supported(), permission: self.system.permission(), capped: self.detector.capped(now_ms), ..facts };
        let target = match gate(&facts) {
            Gate::Off(reason) => {
                self.capture = None;
                return Sample::Off(reason);
            }
            Gate::Hold(reason) => Err(reason),
            Gate::Look => self.system.find_target(wanted),
        };
        let target = match target {
            Ok(target) => target,
            Err(reason) => {
                self.capture = None;
                words.store(false, Ordering::SeqCst);
                return Sample::Held(reason);
            }
        };
        // A new target, or back after a hold: the picture may have jumped, so settling starts again.
        if self.capture.as_ref().is_none_or(|c| c.target() != target) {
            self.capture = None;
            self.detector.restart();
            match self.system.open(target) {
                Ok(open) => self.capture = Some(open),
                Err(e) => {
                    eprintln!("[echoledger] {e}");
                    return Sample::Held(HoldReason::NoWindow);
                }
            }
        }
        let Some(capture) = self.capture.as_mut() else { return Sample::Watching };
        let frame = match capture.grab() {
            Ok(Some(frame)) => frame,
            Ok(None) => return Sample::Watching,
            Err(e) => {
                eprintln!("[echoledger] screen capture: {e}");
                self.capture = None;
                return Sample::Watching;
            }
        };
        let words = words.swap(false, Ordering::SeqCst);
        let kept = Luma::from_bgra(&frame.bgra, frame.width as usize, frame.height as usize, frame.stride).and_then(|luma| self.detector.observe(now_ms, &luma, words));
        match kept {
            Some(trigger) => Sample::Keep { frame, trigger },
            None => Sample::Watching,
        }
    }
}

#[cfg(test)]
mod tests {
    use std::sync::{Arc, Mutex};

    use super::*;
    use crate::detect::classify::SourceApp;
    use crate::screens::platform::{MeetingTree, Snapshot, SysWindow, Target, choose};
    use crate::screens::privacy::{MEETING_WINDOW, WHOLE_SCREEN, WindowFacts};

    /// A stand-in system: windows the test arranges, `choose` deciding like the real backends, frames the test paints.
    #[derive(Default)]
    struct FakeSystem {
        denied: bool,
        snapshot: Mutex<Snapshot>,
        /// Brightness of the next frames (a different value is a different screen).
        paint: Arc<Mutex<u8>>,
        opened: Mutex<Vec<Target>>,
    }

    struct FakeGrab {
        target: Target,
        paint: Arc<Mutex<u8>>,
    }

    impl Grab for FakeGrab {
        fn target(&self) -> Target {
            self.target
        }

        fn grab(&mut self) -> Result<Option<Frame>, String> {
            let v = *self.paint.lock().unwrap();
            let (width, height) = (320u32, 180u32);
            // A "slide": text bars whose place depends on the paint, so each paint is a different screen.
            let mut bgra = vec![240u8; (width * height * 4) as usize];
            for y in 0..height as usize {
                for x in 0..width as usize {
                    if (y / 10 + x / 40 + v as usize).is_multiple_of(3) {
                        bgra[(y * width as usize + x) * 4..][..3].copy_from_slice(&[20, 20, 20]);
                    }
                }
            }
            Ok(Some(Frame { width, height, stride: width as usize * 4, bgra }))
        }
    }

    impl ScreenSystem for FakeSystem {
        fn supported(&self) -> bool {
            true
        }
        fn borderless(&self) -> bool {
            true
        }
        fn permission(&self) -> bool {
            !self.denied
        }
        fn request_permission(&self) -> bool {
            !self.denied
        }
        fn find_target(&self, wanted: &Wanted<'_>) -> Result<Target, HoldReason> {
            choose(wanted, &self.snapshot.lock().unwrap())
        }
        fn open(&self, target: Target) -> Result<Box<dyn Grab>, String> {
            self.opened.lock().unwrap().push(target);
            Ok(Box::new(FakeGrab { target, paint: self.paint.clone() }))
        }
    }

    fn meeting_window(title: &str) -> SysWindow {
        SysWindow { id: 7, meeting: true, displays: vec![1], bundle: None, facts: WindowFacts { title: title.into(), process: "Zoom.exe".into(), visible: true, area: 2_000_000, ..Default::default() } }
    }

    fn system() -> FakeSystem {
        let s = FakeSystem::default();
        *s.snapshot.lock().unwrap() = Snapshot { windows: vec![meeting_window("Zoom Meeting")], main: Some(1) };
        s
    }

    fn facts() -> Facts {
        Facts { enabled: true, call: true, plan: true, server: true, project: Some(true), supported: true, keychain: true, permission: true, user_paused: false, recording_paused: false, capped: false }
    }

    fn zoom() -> MeetingTree {
        MeetingTree { root_pid: 1, app: SourceApp::Zoom, name: "Zoom.exe".into(), browser: false }
    }

    /// Ticks once a second over `seconds`; returns what each tick gave as a short word.
    fn run(sampler: &mut Sampler<'_>, facts: Facts, wanted: &Wanted<'_>, seconds: std::ops::Range<u64>, words: &AtomicBool) -> Vec<&'static str> {
        seconds
            .map(|s| match sampler.tick(facts, wanted, s * 1_000, words) {
                Sample::Off(_) => "off",
                Sample::Held(_) => "held",
                Sample::Watching => "watch",
                Sample::Keep { trigger: Trigger::Change, .. } => "change",
                Sample::Keep { trigger: Trigger::Words, .. } => "words",
            })
            .collect()
    }

    #[test]
    fn a_settled_screen_is_kept_and_kept_once() {
        let system = system();
        let mut sampler = Sampler::new(&system);
        let meeting = zoom();
        let wanted = Wanted { mode: MEETING_WINDOW, meeting: Some(&meeting), excluded: &[], site_rules: &[] };
        let words = AtomicBool::new(false);
        assert_eq!(run(&mut sampler, facts(), &wanted, 0..5, &words), ["watch", "watch", "change", "watch", "watch"]);
        assert_eq!(*system.opened.lock().unwrap(), vec![Target::Window(7)], "opened once, kept open");
        // The next slide.
        *system.paint.lock().unwrap() = 1;
        assert_eq!(run(&mut sampler, facts(), &wanted, 5..9, &words), ["watch", "watch", "change", "watch"]);
    }

    #[test]
    fn pointing_words_take_a_changed_screen_at_once() {
        let system = system();
        let mut sampler = Sampler::new(&system);
        let meeting = zoom();
        let wanted = Wanted { mode: MEETING_WINDOW, meeting: Some(&meeting), excluded: &[], site_rules: &[] };
        let words = AtomicBool::new(false);
        run(&mut sampler, facts(), &wanted, 0..4, &words);
        *system.paint.lock().unwrap() = 2;
        words.store(true, Ordering::SeqCst);
        assert_eq!(run(&mut sampler, facts(), &wanted, 4..5, &words), ["words"]);
        assert!(!words.load(Ordering::SeqCst), "the words were used");
        // Said again over the same screen: nothing new.
        words.store(true, Ordering::SeqCst);
        assert_eq!(run(&mut sampler, facts(), &wanted, 5..7, &words), ["watch", "watch"]);
    }

    #[test]
    fn a_minimised_meeting_window_closes_the_capture_until_it_is_back() {
        let system = system();
        let mut sampler = Sampler::new(&system);
        let meeting = zoom();
        let wanted = Wanted { mode: MEETING_WINDOW, meeting: Some(&meeting), excluded: &[], site_rules: &[] };
        let words = AtomicBool::new(true);
        run(&mut sampler, facts(), &wanted, 0..3, &AtomicBool::new(false));
        system.snapshot.lock().unwrap().windows[0].facts.minimised = true;
        assert!(matches!(sampler.tick(facts(), &wanted, 3_000, &words), Sample::Held(HoldReason::Minimised)));
        assert!(!sampler.open(), "nothing is captured while it is minimised");
        assert!(!words.load(Ordering::SeqCst), "words heard while held take no screen later");
        system.snapshot.lock().unwrap().windows[0].facts.minimised = false;
        assert_eq!(run(&mut sampler, facts(), &wanted, 4..8, &AtomicBool::new(false)), ["watch", "watch", "watch", "watch"], "the same screen as before: nothing new");
        assert_eq!(system.opened.lock().unwrap().len(), 2, "opened again once it was back");
    }

    #[test]
    fn an_excluded_app_on_the_screen_holds_the_whole_screen_capture() {
        let system = system();
        let vault = SysWindow { id: 9, meeting: false, displays: vec![1], bundle: None, facts: WindowFacts { title: "Bitwarden".into(), process: "Bitwarden.exe".into(), visible: true, area: 400_000, ..Default::default() } };
        system.snapshot.lock().unwrap().windows.push(vault);
        let mut sampler = Sampler::new(&system);
        let meeting = zoom();
        let excluded = vec!["Bitwarden".to_string()];
        let words = AtomicBool::new(false);
        let whole = Wanted { mode: WHOLE_SCREEN, meeting: Some(&meeting), excluded: &excluded, site_rules: &[] };
        assert!(matches!(sampler.tick(facts(), &whole, 0, &words), Sample::Held(HoldReason::ExcludedApp)));
        assert!(system.opened.lock().unwrap().is_empty());
        // The meeting window alone never shows the vault.
        let window = Wanted { mode: MEETING_WINDOW, ..whole };
        assert!(matches!(sampler.tick(facts(), &window, 1_000, &words), Sample::Watching));
        // The meeting app itself on the list: nothing at all.
        let zoom_excluded = vec!["Zoom".to_string()];
        let listed = Wanted { mode: MEETING_WINDOW, meeting: Some(&meeting), excluded: &zoom_excluded, site_rules: &[] };
        assert!(matches!(sampler.tick(facts(), &listed, 2_000, &words), Sample::Held(HoldReason::ExcludedApp)));
        assert!(!sampler.open());
    }

    #[test]
    fn a_project_with_screens_off_and_the_other_off_switches_capture_nothing() {
        let system = system();
        let mut sampler = Sampler::new(&system);
        let meeting = zoom();
        let wanted = Wanted { mode: MEETING_WINDOW, meeting: Some(&meeting), excluded: &[], site_rules: &[] };
        let words = AtomicBool::new(false);
        let cases = [
            (Facts { project: Some(false), ..facts() }, OffReason::Project),
            (Facts { plan: false, ..facts() }, OffReason::Plan),
            (Facts { server: false, ..facts() }, OffReason::Server),
            (Facts { enabled: false, ..facts() }, OffReason::Setting),
            (Facts { call: false, ..facts() }, OffReason::NotACall),
            (Facts { keychain: false, ..facts() }, OffReason::Keychain),
        ];
        for (facts, expected) in cases {
            for s in 0..4 {
                assert!(matches!(sampler.tick(facts, &wanted, s * 1_000, &words), Sample::Off(reason) if reason == expected), "{expected:?}");
            }
        }
        assert!(system.opened.lock().unwrap().is_empty(), "no window was ever opened");
        // While the project is still being read nothing is kept either.
        assert!(matches!(sampler.tick(Facts { project: None, ..facts() }, &wanted, 5_000, &words), Sample::Held(HoldReason::Starting)));
        assert!(system.opened.lock().unwrap().is_empty());
    }

    #[test]
    fn pauses_and_a_missing_permission_hold_without_asking_the_system() {
        let system = FakeSystem { denied: true, ..system() };
        let mut sampler = Sampler::new(&system);
        let meeting = zoom();
        let wanted = Wanted { mode: MEETING_WINDOW, meeting: Some(&meeting), excluded: &[], site_rules: &[] };
        let words = AtomicBool::new(false);
        assert!(matches!(sampler.tick(facts(), &wanted, 0, &words), Sample::Held(HoldReason::Permission)), "macOS Screen Recording not allowed");
        let system = self::system();
        let mut sampler = Sampler::new(&system);
        assert!(matches!(sampler.tick(Facts { user_paused: true, ..facts() }, &wanted, 0, &words), Sample::Held(HoldReason::You)));
        assert!(matches!(sampler.tick(Facts { recording_paused: true, ..facts() }, &wanted, 1_000, &words), Sample::Held(HoldReason::RecordingPaused)));
        assert!(system.opened.lock().unwrap().is_empty());
    }

    #[test]
    fn the_hour_cap_holds_the_capture() {
        let system = system();
        let mut sampler = Sampler::new(&system);
        let meeting = zoom();
        let wanted = Wanted { mode: MEETING_WINDOW, meeting: Some(&meeting), excluded: &[], site_rules: &[] };
        let words = AtomicBool::new(false);
        let mut kept = 0;
        let mut s = 0u64;
        // A new slide every 3 seconds for half an hour: the cap stops it at 120.
        while s < 1_800 {
            *system.paint.lock().unwrap() = (s / 3 % 3) as u8;
            if let Sample::Keep { .. } = sampler.tick(facts(), &wanted, s * 1_000, &words) {
                kept += 1;
            }
            s += 1;
        }
        assert_eq!(kept, crate::screens::detect::CAP_PER_HOUR);
        assert!(matches!(sampler.tick(facts(), &wanted, s * 1_000, &words), Sample::Held(HoldReason::HourCap)));
    }
}
