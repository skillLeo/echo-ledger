//! Dictation: shortcut opens a tiny unfocusable box, streams the mic, then types the cleaned text into the app
//! that still has focus (the box never takes focus). Falls back to clipboard + paste.

pub mod cleanup;
pub mod rules;

use std::sync::atomic::Ordering;
use std::time::Duration;

use enigo::{Direction, Enigo, Key, Keyboard, Settings};
use tauri::{AppHandle, Emitter, Manager};
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

/// `dictation:state`: dictation started or stopped (the box plays its start / stop sound when `sounds` is on).
#[derive(Clone, Debug, serde::Serialize)]
#[serde(rename_all = "camelCase")]
pub struct DictationState {
    pub active: bool,
    pub sounds: bool,
    /// `toggle` or `push_to_talk`, so the box says how to finish.
    pub mode: String,
}

fn emit_state(app: &AppHandle, active: bool) {
    let settings = app.state::<crate::AppState>().settings();
    let state = DictationState { active, sounds: settings.dictation_sounds, mode: settings.dictation_mode.clone() };
    let _ = app.emit_to(DICTATION, "dictation:state", state);
}

pub async fn start(app: &AppHandle) -> AppResult<()> {
    let state = app.state::<crate::AppState>();
    if is_active(app) {
        return Ok(());
    }
    state.dictation_text.lock().unwrap().clear();
    state.dictation_stop_requested.store(false, Ordering::SeqCst);
    set_overlay_visible(app, DICTATION, true);
    emit_state(app, true);
    app.state::<CaptionHub>().send(TARGETS, CaptionEvent::Clear);

    // Dictation needs no server session; its streaming period is reported without one (A5.4).
    let senders = match state.dictation_live.start(app, "dictation", &[Channel::Mic], TARGETS, false, 0, None).await {
        Ok(s) => s,
        Err(e) => {
            app.state::<CaptionHub>().send(TARGETS, CaptionEvent::Status { live: false, recording: false, provider: None, message: Some(e.to_string()) });
            emit_state(app, false);
            return Err(e);
        }
    };
    if let Some((_, sink)) = senders.into_iter().next() {
        let level = std::sync::Arc::new(std::sync::atomic::AtomicU32::new(0));
        match MicStream::start_with_level(sink, level.clone()) {
            Ok(mic) => *state.dictation.lock().unwrap() = Some(mic),
            Err(e) => {
                state.dictation_live.stop(); // the socket must not stay open without audio
                emit_state(app, false);
                return Err(e);
            }
        }
        spawn_level_meter(app.clone(), level);
    }
    // Hold to talk: the shortcut may have been released while the stream was still opening.
    if state.settings().dictation_mode == "push_to_talk" && state.dictation_stop_requested.swap(false, Ordering::SeqCst) {
        let app = app.clone();
        tauri::async_runtime::spawn(async move {
            let _ = finish(&app).await;
        });
    }
    Ok(())
}

/// `dictation:level` ten times a second while dictating: the flow bar's meter.
fn spawn_level_meter(app: AppHandle, level: std::sync::Arc<std::sync::atomic::AtomicU32>) {
    tauri::async_runtime::spawn(async move {
        while is_active(&app) {
            let _ = app.emit_to(DICTATION, "dictation:level", f32::from_bits(level.load(Ordering::Relaxed)));
            tokio::time::sleep(Duration::from_millis(100)).await;
        }
    });
}

/// The text to insert: the clean-up (when on), then the spoken commands, dictionary, replacements and snippets.
pub fn compose_text(turns: &[String], settings: &crate::settings::Settings) -> String {
    let cleaned = if settings.dictation_cleanup { cleanup::professionalize(turns) } else { turns.iter().map(|t| t.trim()).filter(|t| !t.is_empty()).collect::<Vec<_>>().join(" ") };
    rules::apply(&cleaned, &settings.dictation_replacements, &settings.dictation_snippets)
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
    let settings = state.settings();
    let text = compose_text(&turns, &settings);
    set_overlay_visible(app, DICTATION, false);
    emit_state(app, false);
    if text.is_empty() {
        return Ok(text);
    }
    remember(app, &text);

    let copy_only = settings.dictation_output == "clipboard";
    let typed = if copy_only { false } else { type_text(&text).is_ok() };
    if !typed {
        // UIPI (elevated targets) or "clipboard" mode: copy, then paste unless copy-only was chosen. A paste puts
        // the user's own clipboard back afterwards, so dictating never loses what they had copied.
        let previous = if copy_only { None } else { app.clipboard().read_text().ok() };
        app.clipboard().write_text(text.clone()).map_err(|e| crate::error::AppError::msg(e.to_string()))?;
        if !copy_only {
            let _ = paste();
            if let Some(previous) = previous {
                let app = app.clone();
                let ours = text.clone();
                tauri::async_runtime::spawn(async move {
                    tokio::time::sleep(Duration::from_millis(600)).await; // the target app reads the clipboard first
                    if app.clipboard().read_text().ok().as_deref() == Some(ours.as_str()) {
                        let _ = app.clipboard().write_text(previous);
                    }
                });
            }
        }
    }
    Ok(text)
}

/// Adds a finished dictation to the history on this computer (the newest 50).
fn remember(app: &AppHandle, text: &str) {
    let path = app.state::<crate::AppState>().data_dir.join("dictation_history.json");
    let mut history = rules::History::load(&path);
    history.push(chrono::Utc::now().to_rfc3339(), text);
    let _ = history.save(&path);
}

/// The dictation history, newest first.
pub fn history(app: &AppHandle) -> Vec<rules::HistoryEntry> {
    rules::History::load(&app.state::<crate::AppState>().data_dir.join("dictation_history.json")).entries
}

pub fn clear_history(app: &AppHandle) -> AppResult<()> {
    rules::History::default().save(&app.state::<crate::AppState>().data_dir.join("dictation_history.json"))?;
    Ok(())
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
