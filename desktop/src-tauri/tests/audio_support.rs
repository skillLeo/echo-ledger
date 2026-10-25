//! Audio support code that needs no hardware: the deterministic mock source, the idle capture controller and the
//! platform gate; plus read-only smoke checks of the Windows detection APIs.

use desktop_lib::audio::CaptureController;
use desktop_lib::audio::mock::MockSource;
use desktop_lib::audio::platform::supports_process_loopback;
use desktop_lib::audio::types::{AudioChunk, Channel, TARGET_RATE};

fn count(chunks: &[AudioChunk], channel: Channel) -> usize {
    chunks.iter().filter(|c| c.channel == channel).count()
}

#[test]
fn the_working_sample_rate_is_sixteen_kilohertz() {
    assert_eq!(TARGET_RATE, 16_000);
}

// ---- Mock source -----------------------------------------------------------------------------------------------

#[test]
fn the_default_mock_is_three_seconds_of_ten_millisecond_packets_with_a_gap() {
    let chunks = MockSource::default().chunks();
    assert_eq!(count(&chunks, Channel::Mic), 300);
    assert_eq!(
        count(&chunks, Channel::System),
        240,
        "60 packets are missing between 1.0 s and 1.6 s"
    );
    assert!(
        chunks
            .iter()
            .all(|c| c.samples.len() == 160 && !c.discontinuity)
    );
    assert!(
        chunks
            .iter()
            .all(|c| c.samples.iter().all(|s| s.abs() <= 0.25 + 1e-6))
    );
}

#[test]
fn mock_timestamps_are_anchored_and_monotonic_per_channel() {
    let source = MockSource::default();
    let chunks = source.chunks();
    for channel in [Channel::Mic, Channel::System] {
        let stamps: Vec<u64> = chunks
            .iter()
            .filter(|c| c.channel == channel)
            .map(|c| c.ts_ns.unwrap())
            .collect();
        assert!(
            stamps.windows(2).all(|w| w[1] > w[0]),
            "{channel:?} timestamps must increase"
        );
    }
    let first_mic = chunks.iter().find(|c| c.channel == Channel::Mic).unwrap();
    assert_eq!(first_mic.ts_ns, Some(source.t0_ns));
    let mic_stamps: Vec<u64> = chunks
        .iter()
        .filter(|c| c.channel == Channel::Mic)
        .map(|c| c.ts_ns.unwrap())
        .collect();
    assert!(
        mic_stamps.windows(2).all(|w| w[1] - w[0] == 10_000_000),
        "the mic clock is exact"
    );
}

#[test]
fn the_mock_is_deterministic() {
    let (a, b) = (
        MockSource::default().chunks(),
        MockSource::default().chunks(),
    );
    assert_eq!(a.len(), b.len());
    for (x, y) in a.iter().zip(&b) {
        assert_eq!((x.channel, x.ts_ns), (y.channel, y.ts_ns));
        assert_eq!(x.samples, y.samples);
    }
}

#[test]
fn the_mock_can_be_reshaped() {
    let source = MockSource {
        duration_ms: 500,
        drift_ppm: 0,
        system_gap_ms: (0, 0),
        ..MockSource::default()
    };
    let chunks = source.chunks();
    assert_eq!(count(&chunks, Channel::Mic), 50);
    assert_eq!(count(&chunks, Channel::System), 50);
    let silent_system = MockSource {
        system_gap_ms: (0, 10_000),
        ..MockSource::default()
    }
    .chunks();
    assert_eq!(count(&silent_system, Channel::System), 0);
    let larger_packets = MockSource {
        packet: 480,
        ..MockSource::default()
    }
    .chunks();
    assert_eq!(count(&larger_packets, Channel::Mic), 100);
    assert!(larger_packets.iter().all(|c| c.samples.len() == 480));
}

// ---- Capture controller (idle) ---------------------------------------------------------------------------------

#[test]
fn an_idle_controller_reports_nothing_and_stops_cleanly() {
    let controller = CaptureController::default();
    assert!(!controller.is_recording());
    assert!(controller.info().is_none());
    assert_eq!(controller.levels(), [0.0, 0.0]);
    assert!(
        controller.stop().unwrap().is_none(),
        "stopping when nothing records is not an error"
    );
    controller.set_live_sink(Channel::Mic, None);
    controller.set_live_sink(Channel::System, None);
    assert!(controller.stop().unwrap().is_none());
}

// ---- Platform gate ---------------------------------------------------------------------------------------------

#[test]
fn process_loopback_needs_windows_build_20348_or_later() {
    for (build, expected) in [
        (0, false),
        (10_240, false),
        (17_763, false),
        (19_041, false),
        (19_045, false),
        (20_347, false),
        (20_348, true),
        (22_000, true),
        (22_631, true),
        (26_300, true),
        (u32::MAX, true),
    ] {
        assert_eq!(supports_process_loopback(build), expected, "build {build}");
    }
}

// ---- Windows detection APIs (read-only) ------------------------------------------------------------------------

#[cfg(windows)]
mod windows_apis {
    use desktop_lib::audio::platform::windows::os_build;
    use desktop_lib::detect::{detect_call_app, mic_users};

    #[test]
    fn the_os_build_is_readable_and_modern() {
        let build = os_build().expect("RtlGetVersion should succeed");
        assert!(build >= 17_763, "build {build}");
    }

    #[test]
    fn listing_microphone_users_never_fails_and_only_names_real_processes() {
        for user in mic_users() {
            assert!(!user.name.is_empty());
            assert!(user.holds_mic, "only trees holding the microphone are listed");
            assert!(
                ["auto_record", "recording", "ignored", "ask", "off"].contains(&user.decision.as_str()),
                "{}",
                user.decision
            );
            if let Some(app) = &user.app {
                assert!(
                    [
                        "whatsapp",
                        "whatsapp_business",
                        "whatsapp_web",
                        "google_meet",
                        "zoom",
                        "microsoft_teams",
                        "voice_note",
                        "other"
                    ]
                    .contains(&app.as_str()),
                    "{app}"
                );
            }
        }
        // The call-app lookup is the same enumeration seen through the classifier.
        let _ = detect_call_app();
    }

    #[test]
    fn this_test_process_is_never_reported_as_a_microphone_user() {
        let exe = std::env::current_exe().unwrap();
        let name = exe.file_name().unwrap().to_string_lossy().to_string();
        assert!(
            mic_users().iter().all(|u| u.name != name),
            "{name} listed itself"
        );
    }
}
