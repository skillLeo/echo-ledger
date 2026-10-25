//! The mic-use debounce state machine, driven with the timings auto mode uses (3 s to start, 5 s to stop,
//! polled once per second).

use desktop_lib::detect::classify::SourceApp;
use desktop_lib::detect::state::{MicUseEvent, MicUseState, MicUseStateMachine};

const START_MS: u64 = 3_000;
const STOP_MS: u64 = 5_000;

fn machine() -> MicUseStateMachine {
    MicUseStateMachine::new(START_MS, STOP_MS)
}

/// Feeds `active` once per second for `seconds`, starting at `from_ms`; returns every event with its timestamp.
fn drive(
    m: &mut MicUseStateMachine,
    from_ms: u64,
    seconds: u64,
    active: Option<SourceApp>,
) -> Vec<(u64, MicUseEvent)> {
    (0..seconds)
        .filter_map(|s| {
            m.update(from_ms + s * 1_000, active)
                .map(|e| (from_ms + s * 1_000, e))
        })
        .collect()
}

#[test]
fn nothing_happens_while_no_call_app_holds_the_microphone() {
    let mut m = machine();
    assert!(drive(&mut m, 0, 600, None).is_empty());
    assert_eq!(m.state(), MicUseState::Idle);
}

#[test]
fn recording_starts_exactly_three_seconds_after_the_first_sighting() {
    let mut m = machine();
    assert_eq!(m.update(10_000, Some(SourceApp::Zoom)), None);
    assert!(matches!(
        m.state(),
        MicUseState::Candidate {
            app: SourceApp::Zoom,
            since_ms: 10_000
        }
    ));
    assert_eq!(m.update(11_000, Some(SourceApp::Zoom)), None);
    assert_eq!(
        m.update(12_999, Some(SourceApp::Zoom)),
        None,
        "one millisecond early"
    );
    assert_eq!(
        m.update(13_000, Some(SourceApp::Zoom)),
        Some(MicUseEvent::Start(SourceApp::Zoom))
    );
    assert_eq!(
        m.state(),
        MicUseState::Recording {
            app: SourceApp::Zoom
        }
    );
}

#[test]
fn start_fires_once_however_long_the_call_lasts() {
    let mut m = machine();
    let events = drive(&mut m, 0, 3_600, Some(SourceApp::Whatsapp));
    assert_eq!(
        events,
        vec![(3_000, MicUseEvent::Start(SourceApp::Whatsapp))]
    );
}

#[test]
fn recording_stops_exactly_five_seconds_after_the_microphone_is_released() {
    let mut m = machine();
    drive(&mut m, 0, 10, Some(SourceApp::Zoom)); // recording since 3 s
    assert_eq!(m.update(10_000, None), None);
    assert!(matches!(
        m.state(),
        MicUseState::Releasing {
            app: SourceApp::Zoom,
            since_ms: 10_000
        }
    ));
    assert_eq!(m.update(14_999, None), None, "one millisecond early");
    assert_eq!(
        m.update(15_000, None),
        Some(MicUseEvent::Stop(SourceApp::Zoom))
    );
    assert_eq!(m.state(), MicUseState::Idle);
}

#[test]
fn a_flapping_microphone_never_starts_a_recording() {
    let mut m = machine();
    // Present for two seconds, absent for one, over and over: the candidate timer restarts every time.
    let mut events = Vec::new();
    for cycle in 0..200u64 {
        let base = cycle * 3_000;
        events.extend(drive(&mut m, base, 2, Some(SourceApp::Zoom)));
        events.extend(drive(&mut m, base + 2_000, 1, None));
    }
    assert!(events.is_empty(), "unexpected events {events:?}");
    assert_eq!(m.state(), MicUseState::Idle);
}

#[test]
fn a_short_dropout_during_a_call_does_not_stop_the_recording() {
    let mut m = machine();
    let mut events = drive(&mut m, 0, 10, Some(SourceApp::Whatsapp)); // Start at 3 s
    for round in 0..20u64 {
        let base = 10_000 + round * 5_000;
        events.extend(drive(&mut m, base, 4, None)); // 4 s of silence: still inside the 5 s grace period
        events.extend(drive(&mut m, base + 4_000, 1, Some(SourceApp::Whatsapp)));
    }
    assert_eq!(
        events,
        vec![(3_000, MicUseEvent::Start(SourceApp::Whatsapp))]
    );
    assert_eq!(
        m.state(),
        MicUseState::Recording {
            app: SourceApp::Whatsapp
        }
    );
}

#[test]
fn the_stop_timer_restarts_after_every_reappearance() {
    let mut m = machine();
    drive(&mut m, 0, 5, Some(SourceApp::Zoom)); // Start at 3 s
    assert_eq!(m.update(5_000, None), None); // Releasing since 5 s
    assert_eq!(m.update(9_000, Some(SourceApp::Zoom)), None); // back at 9 s
    assert_eq!(m.update(10_000, None), None); // Releasing since 10 s
    assert_eq!(
        m.update(14_999, None),
        None,
        "the earlier absence must not count"
    );
    assert_eq!(
        m.update(15_000, None),
        Some(MicUseEvent::Stop(SourceApp::Zoom))
    );
}

#[test]
fn a_new_call_after_a_stop_needs_the_full_start_delay_again() {
    let mut m = machine();
    let mut events = drive(&mut m, 0, 10, Some(SourceApp::Zoom));
    events.extend(drive(&mut m, 10_000, 10, None)); // stop at 15 s
    events.extend(drive(&mut m, 20_000, 10, Some(SourceApp::GoogleMeet)));
    assert_eq!(
        events,
        vec![
            (3_000, MicUseEvent::Start(SourceApp::Zoom)),
            (15_000, MicUseEvent::Stop(SourceApp::Zoom)),
            (23_000, MicUseEvent::Start(SourceApp::GoogleMeet))
        ]
    );
}

#[test]
fn reset_during_the_start_delay_forgets_the_candidate() {
    let mut m = machine();
    m.update(0, Some(SourceApp::Zoom));
    m.update(2_000, Some(SourceApp::Zoom));
    m.reset();
    assert_eq!(m.state(), MicUseState::Idle);
    // The same app has to be seen for the whole delay again.
    assert_eq!(m.update(3_000, Some(SourceApp::Zoom)), None);
    assert_eq!(m.update(5_999, Some(SourceApp::Zoom)), None);
    assert_eq!(
        m.update(6_000, Some(SourceApp::Zoom)),
        Some(MicUseEvent::Start(SourceApp::Zoom))
    );
}

#[test]
fn reset_while_recording_emits_no_stop_and_restarts_detection() {
    let mut m = machine();
    drive(&mut m, 0, 5, Some(SourceApp::Whatsapp));
    assert_eq!(
        m.state(),
        MicUseState::Recording {
            app: SourceApp::Whatsapp
        }
    );
    m.reset();
    assert_eq!(m.state(), MicUseState::Idle);
    assert_eq!(
        m.update(5_000, None),
        None,
        "a reset machine has nothing to stop"
    );
    // Still on the call: it is picked up as a brand-new candidate.
    assert_eq!(m.update(6_000, Some(SourceApp::Whatsapp)), None);
    assert_eq!(
        m.update(9_000, Some(SourceApp::Whatsapp)),
        Some(MicUseEvent::Start(SourceApp::Whatsapp))
    );
}

#[test]
fn reset_while_releasing_goes_back_to_idle_without_a_stop() {
    let mut m = machine();
    drive(&mut m, 0, 5, Some(SourceApp::Zoom));
    m.update(5_000, None);
    m.reset();
    assert_eq!(m.update(20_000, None), None);
    assert_eq!(m.state(), MicUseState::Idle);
}

#[test]
fn a_different_app_during_the_start_delay_restarts_the_timer() {
    let mut m = machine();
    m.update(0, Some(SourceApp::Zoom));
    m.update(1_000, Some(SourceApp::Whatsapp)); // candidate switches, timer restarts at 1 s
    assert_eq!(
        m.update(3_000, Some(SourceApp::Whatsapp)),
        None,
        "only 2 s of WhatsApp so far"
    );
    assert_eq!(
        m.update(4_000, Some(SourceApp::Whatsapp)),
        Some(MicUseEvent::Start(SourceApp::Whatsapp))
    );
}

#[test]
fn a_second_app_while_recording_does_not_change_or_restart_the_recording() {
    let mut m = machine();
    drive(&mut m, 0, 5, Some(SourceApp::Zoom)); // recording Zoom
    assert_eq!(m.update(5_000, Some(SourceApp::Whatsapp)), None);
    assert_eq!(
        m.state(),
        MicUseState::Recording {
            app: SourceApp::Zoom
        }
    );
    // Zoom leaves but WhatsApp still holds the microphone: the recording continues.
    let events = drive(&mut m, 6_000, 60, Some(SourceApp::Whatsapp));
    assert!(events.is_empty());
    assert_eq!(
        m.state(),
        MicUseState::Recording {
            app: SourceApp::Zoom
        }
    );
    // Only when nobody holds the microphone does the stop timer run, and the stop names the recorded app.
    let events = drive(&mut m, 66_000, 6, None);
    assert_eq!(events, vec![(71_000, MicUseEvent::Stop(SourceApp::Zoom))]);
}

#[test]
fn a_clock_that_goes_backwards_never_fires_early_or_panics() {
    let mut m = machine();
    assert_eq!(m.update(50_000, Some(SourceApp::Zoom)), None);
    assert_eq!(m.update(10_000, Some(SourceApp::Zoom)), None);
    assert_eq!(m.update(0, Some(SourceApp::Zoom)), None);
    assert!(matches!(m.state(), MicUseState::Candidate { .. }));
}

#[test]
fn extreme_timestamps_do_not_overflow() {
    let mut m = machine();
    assert_eq!(m.update(u64::MAX - 1, Some(SourceApp::Zoom)), None);
    assert_eq!(m.update(u64::MAX, Some(SourceApp::Zoom)), None);
    let mut z = MicUseStateMachine::new(0, 0);
    assert_eq!(z.update(0, Some(SourceApp::Zoom)), None);
    assert_eq!(
        z.update(0, Some(SourceApp::Zoom)),
        Some(MicUseEvent::Start(SourceApp::Zoom))
    );
    assert_eq!(z.update(0, None), None);
    assert_eq!(z.update(0, None), Some(MicUseEvent::Stop(SourceApp::Zoom)));
}

/// Independent model of the documented behaviour: a start needs one app held for the whole start delay, a stop
/// needs the microphone free for the whole stop delay, and a reappearance cancels a pending stop.
struct Model {
    recording: Option<SourceApp>,
    candidate: Option<(SourceApp, u64)>,
    absent_since: Option<u64>,
}

impl Model {
    fn step(&mut self, now: u64, active: Option<SourceApp>) -> Option<MicUseEvent> {
        match self.recording {
            None => {
                match (active, self.candidate) {
                    (Some(app), Some((cur, since))) if app == cur => {
                        if now - since >= START_MS {
                            self.recording = Some(app);
                            self.candidate = None;
                            self.absent_since = None;
                            return Some(MicUseEvent::Start(app));
                        }
                    }
                    (Some(app), _) => self.candidate = Some((app, now)),
                    (None, _) => self.candidate = None,
                }
                None
            }
            Some(app) => {
                if active.is_some() {
                    self.absent_since = None;
                    return None;
                }
                let since = *self.absent_since.get_or_insert(now);
                if now - since >= STOP_MS {
                    self.recording = None;
                    self.absent_since = None;
                    return Some(MicUseEvent::Stop(app));
                }
                None
            }
        }
    }
}

#[test]
fn random_presence_patterns_match_an_independent_model() {
    let apps = [SourceApp::Zoom, SourceApp::Whatsapp, SourceApp::GoogleMeet];
    for seed in 1..=12u64 {
        let mut rng = seed.wrapping_mul(0x9E37_79B9_7F4A_7C15) | 1;
        let mut next = move || {
            rng ^= rng << 13;
            rng ^= rng >> 7;
            rng ^= rng << 17;
            rng
        };
        let mut m = machine();
        let mut model = Model {
            recording: None,
            candidate: None,
            absent_since: None,
        };
        let mut present_for = 0u32;
        let mut current: Option<SourceApp> = None;
        let (mut starts, mut stops) = (0u32, 0u32);
        for second in 0..20_000u64 {
            // Hold each presence/absence pattern for a random 1..12 seconds so both long calls and flaps occur.
            if present_for == 0 {
                present_for = 1 + (next() % 12) as u32;
                current = if next() % 3 == 0 {
                    None
                } else {
                    Some(apps[(next() % 3) as usize])
                };
            }
            present_for -= 1;
            let now = second * 1_000;
            let got = m.update(now, current);
            let expected = model.step(now, current);
            assert_eq!(
                got, expected,
                "seed {seed}, t = {now} ms, active = {current:?}"
            );
            match got {
                Some(MicUseEvent::Start(_)) => starts += 1,
                Some(MicUseEvent::Stop(_)) => stops += 1,
                None => {}
            }
            assert!(
                starts == stops || starts == stops + 1,
                "starts and stops must alternate (seed {seed})"
            );
        }
        assert!(
            starts > 5,
            "the generated pattern should contain calls (seed {seed}, starts {starts})"
        );
    }
}
