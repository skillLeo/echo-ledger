//! Dictation: shortcut opens a tiny unfocusable box, streams the mic, then types the cleaned text into the app
//! that still has focus (the box never takes focus). Falls back to clipboard + paste.

pub mod cleanup;

use std::sync::atomic::Ordering;
use std::time::Duration;

use enigo::{Direction, Enigo, Key, Keyboard, Settings};
use tauri::{AppHandle, Manager};
use tauri_plugin_clipboard_manager::ClipboardExt;

use crate::audio::MicStream;
use crate::audio::types::Channel;
use crate::error::AppResult;
use crate::overlay::{DICTATION, set_overlay_visible};
use crate::stt::captions::{CaptionEvent, CaptionHub};

const TARGETS: &[&str] = &[DICTATION];

pub fn is_active(app: &AppHandle) -> bool {
    app.state::<crate::AppState>().dictation.lock().map(|d| d.is_some()).unwrap_or(false)
}

pub async fn start(app: &AppHandle) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    if is_active(app) {
        return Ok(());
    }
    state.dictation_text.lock().unwrap().clear();
    state.dictation_stop_requested.store(false, Ordering::SeqCst);
    set_overlay_visible(app, DICTATION, true);
    app.state::<CaptionHub>().send(TARGETS, CaptionEvent::Clear);

    // Dictation needs no server session; its streaming period is reported without one (A5.4).
    let senders = match state.dictation_live.start(app, "dictation", &[Channel::Mic], TARGETS, false, 0, None).await {
        Ok(s) => s,
        Err(e) => {
            app.state::<CaptionHub>().send(TARGETS, CaptionEvent::Status { live: false, recording: false, provider: None, message: Some(e.to_string()) });
            return Err(e);
        }
    };
    if let Some((_, sink)) = senders.into_iter().next() {
        match MicStream::start(sink) {
            Ok(mic) => *state.dictation.lock().unwrap() = Some(mic),
            Err(e) => {
                state.dictation_live.stop(); // the socket must not stay open without audio
                return Err(e);
            }
        }
    }
    Ok(())
}

/// Stops streaming, waits briefly for the final turn, then types (or copies) the cleaned text.
pub async fn finish(app: &AppHandle) -> AppResult<String> {
    let state = app.state::<crate::AppState>();
    let mic = state.dictation.lock().unwrap().take();
    if let Some(mic) = mic {
        mic.stop();
    }
    state.dictation_live.stop();
    tokio::time::sleep(Duration::from_millis(900)).await; // let the provider flush the last turn
    let turns = state.dictation_text.lock().unwrap().clone();
    let text = cleanup::professionalize(&turns);
    set_overlay_visible(app, DICTATION, false);
    if text.is_empty() {
        return Ok(text);
    }

    let copy_only = state.settings().dictation_output == "clipboard";
    let typed = if copy_only { false } else { type_text(&text).is_ok() };
    if !typed {
        // UIPI (elevated targets) or "clipboard" mode: copy, then paste unless copy-only was chosen.
        app.clipboard().write_text(text.clone()).map_err(|e| crate::error::AppError::msg(e.to_string()))?;
        if !copy_only {
            let _ = paste();
        }
    }
    Ok(text)
}

fn new_enigo() -> Result<Enigo, String> {
    // No repeated macOS permission prompts: we show our own one-time explanation instead.
    Enigo::new(&Settings { open_prompt_to_get_permissions: false, ..Default::default() }).map_err(|e| format!("{e:?}"))
}

fn type_text(text: &str) -> Result<(), String> {
    let mut enigo = new_enigo()?;
    enigo.text(text).map_err(|e| format!("{e:?}"))
}

fn paste() -> Result<(), String> {
    let mut enigo = new_enigo()?;
    let modifier = if cfg!(target_os = "macos") { Key::Meta } else { Key::Control };
    enigo.key(modifier, Direction::Press).map_err(|e| format!("{e:?}"))?;
    enigo.key(Key::Unicode('v'), Direction::Click).map_err(|e| format!("{e:?}"))?;
    enigo.key(modifier, Direction::Release).map_err(|e| format!("{e:?}"))
}
