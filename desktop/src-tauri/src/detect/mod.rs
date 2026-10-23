//! Auto mode: when WhatsApp / Zoom / a browser (Meet) starts using the mic, start a two-channel recording (and
//! captions if enabled); when it releases the mic, stop and upload. Voice notes are never auto-recorded.

pub mod classify;
pub mod state;

use std::time::{Duration, Instant};

use tauri::{AppHandle, Manager};

use classify::SourceApp;
use state::{MicUseEvent, MicUseStateMachine};

/// Currently detected call app holding the microphone (excluding EchoLedger).
pub fn detect_call_app() -> Option<SourceApp> {
    #[cfg(windows)]
    {
        crate::audio::platform::windows::active_capture_processes()
            .into_iter()
            .find_map(|(_, image, pfn)| classify::classify_windows(&image, pfn.as_deref()))
    }
    #[cfg(target_os = "macos")]
    {
        crate::audio::platform::macos::active_input_bundle_ids().into_iter().find_map(|id| classify::classify_macos(&id))
    }
    #[cfg(not(any(windows, target_os = "macos")))]
    {
        None
    }
}

pub fn spawn_auto_mode(app: AppHandle) {
    std::thread::Builder::new()
        .name("el-auto-mode".into())
        .spawn(move || {
            let clock = Instant::now();
            let mut machine = MicUseStateMachine::new(3_000, 5_000);
            loop {
                std::thread::sleep(Duration::from_millis(1_000));
                let state = app.state::<crate::AppState>();
                if !state.settings().auto_mode {
                    machine.reset();
                    continue;
                }
                let active = detect_call_app();
                match machine.update(clock.elapsed().as_millis() as u64, active) {
                    Some(MicUseEvent::Start(source)) => {
                        let app = app.clone();
                        tauri::async_runtime::spawn(async move {
                            if let Err(e) = crate::actions::start_call_recording(&app, source.as_str(), true).await {
                                eprintln!("[echoledger] auto-mode start failed: {e}");
                            }
                        });
                    }
                    Some(MicUseEvent::Stop(_)) => {
                        let app = app.clone();
                        tauri::async_runtime::spawn(async move {
                            if let Err(e) = crate::actions::stop_recording(&app, true).await {
                                eprintln!("[echoledger] auto-mode stop failed: {e}");
                            }
                        });
                    }
                    None => {}
                }
            }
        })
        .expect("spawn auto-mode thread");
}
