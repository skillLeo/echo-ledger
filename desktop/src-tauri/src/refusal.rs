//! How a refused start reaches the person (Phase 1B). The app usually sits in the menu bar or tray with its main window
//! closed, and a closed window is destroyed (C9), so an `app:error` sent then is heard by nobody. What was refused must
//! still say why: a shortcut or a menu click opens the window with the reason in it, and a meeting that cannot be recorded
//! gets a system notification, because nobody pressed anything and a window must not jump in front of the call.

use tauri::{AppHandle, Emitter, Manager};

use crate::error::AppError;
use crate::lifecycle::MAIN;
use crate::shortcuts::Action;

/// Who is waiting to hear about it.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Asked {
    /// The person pressed something that starts a recording: the answer and the way to fix it come to the front.
    Start,
    /// The person pressed something else, or nothing was left to save: a notification is enough.
    Other,
    /// Auto mode: a meeting is on screen and nobody pressed anything.
    Meeting,
    /// Something else on screen said it already, or nobody needs telling: only a page that is open adds its toast.
    PageOnly,
}

/// How it reaches them.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Route {
    Toast,
    OpenMain,
    Notification,
}

/// A window the person is looking at can say it itself; one that is closed or behind others cannot.
pub fn route(main_focused: bool, asked: Asked) -> Route {
    match asked {
        Asked::PageOnly => Route::Toast,
        _ if main_focused => Route::Toast,
        Asked::Start => Route::OpenMain,
        Asked::Other | Asked::Meeting => Route::Notification,
    }
}

/// A shortcut's failure. A dictation that could not start said why in its own box; captions turned on while idle start a
/// recording, and while recording they are only captions.
pub fn asked_by_shortcut(action: Action, pressed: bool, recording: bool) -> Asked {
    match action {
        Action::Dictation if pressed => Asked::PageOnly,
        Action::ToggleSaving | Action::RecordNow | Action::VoiceNote | Action::ToggleCaptions if !recording => Asked::Start,
        _ => Asked::Other,
    }
}

/// A tray or menu-bar item's failure.
pub fn asked_by_menu(item: &str, recording: bool) -> Asked {
    match item {
        "record" | "captions" | "voice_note" | "mic_note" if !recording => Asked::Start,
        _ => Asked::Other,
    }
}

/// An automatic start's failure. A computer nobody is signed in to has auto mode on by default and knows it is signed
/// out: telling it at every meeting would be nagging.
pub fn asked_by_meeting(error: &AppError) -> Asked {
    if matches!(error, AppError::NotPaired) { Asked::PageOnly } else { Asked::Meeting }
}

/// Says `message` the way `asked` calls for. A notification goes through its category (Phase 1C): a meeting that is not
/// being recorded is Call capture & readiness, which the quiet hours never hold back; the rest is Recording.
pub fn say(app: &AppHandle, message: &str, asked: Asked) {
    let focused = app.get_webview_window(MAIN).is_some_and(|window| window.is_focused().unwrap_or(false));
    match route(focused, asked) {
        Route::Toast => {
            let _ = app.emit("app:error", message);
        }
        Route::OpenMain => open_main_with(app, message),
        Route::Notification => {
            use crate::notifications::{Category, allowed, local_minutes, show};
            // A window behind others has it too, for when the person comes back to it.
            let _ = app.emit("app:error", message);
            let category = if asked == Asked::Meeting { Category::CallCapture } else { Category::Recording };
            let Some(settings) = app.try_state::<crate::AppState>().map(|s| s.settings()) else { return };
            if allowed(&settings, category, local_minutes()) && !show(app, &settings, category, "EchoLedger", message) {
                open_main_with(app, message);
            }
        }
    }
}

/// `lifecycle::emit` keeps the event until the page of a window still being built listens.
fn open_main_with(app: &AppHandle, message: &str) {
    crate::tray::show_main(app);
    crate::lifecycle::emit(app, MAIN, "app:error", message);
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn with_the_window_closed_a_refusal_still_reaches_the_person() {
        assert_eq!(route(false, Asked::Start), Route::OpenMain, "a shortcut or the menu opens the window with the reason in it");
        assert_eq!(route(false, Asked::Meeting), Route::Notification, "no window jumps in front of a call");
        assert_eq!(route(false, Asked::Other), Route::Notification);
    }

    #[test]
    fn a_window_in_front_says_it_itself() {
        assert_eq!(route(true, Asked::Start), Route::Toast);
        assert_eq!(route(true, Asked::Meeting), Route::Toast);
        assert_eq!(route(true, Asked::Other), Route::Toast);
    }

    #[test]
    fn what_something_else_said_is_not_said_twice() {
        assert_eq!(route(false, Asked::PageOnly), Route::Toast);
        assert_eq!(route(true, Asked::PageOnly), Route::Toast);
    }

    #[test]
    fn a_shortcut_that_starts_a_recording_gets_the_window() {
        for action in [Action::ToggleSaving, Action::VoiceNote, Action::ToggleCaptions] {
            assert_eq!(asked_by_shortcut(action, true, false), Asked::Start, "{action:?} while idle");
            assert_eq!(asked_by_shortcut(action, true, true), Asked::Other, "{action:?} during a recording");
        }
        assert_eq!(asked_by_shortcut(Action::Mark, true, false), Asked::Other, "nothing to mark is no reason to open a window");
        assert_eq!(asked_by_shortcut(Action::CopyLast2Min, true, false), Asked::Other);
    }

    #[test]
    fn dictation_says_why_in_its_own_box_and_its_end_does_not() {
        assert_eq!(asked_by_shortcut(Action::Dictation, true, false), Asked::PageOnly);
        assert_eq!(asked_by_shortcut(Action::Dictation, false, false), Asked::Other, "the box has gone when the text cannot be typed");
    }

    #[test]
    fn a_menu_item_that_starts_a_recording_gets_the_window() {
        for item in ["record", "captions", "voice_note", "mic_note"] {
            assert_eq!(asked_by_menu(item, false), Asked::Start, "{item} while idle");
            assert_eq!(asked_by_menu(item, true), Asked::Other, "{item} during a recording");
        }
        assert_eq!(asked_by_menu("pause", false), Asked::Other);
        assert_eq!(asked_by_menu("auto", false), Asked::Other);
    }

    #[test]
    fn a_meeting_is_told_unless_this_computer_is_signed_out() {
        assert_eq!(asked_by_meeting(&AppError::Message("The microphone and system audio are both off.".into())), Asked::Meeting);
        assert_eq!(asked_by_meeting(&AppError::NotPaired), Asked::PageOnly, "auto mode is on by default: no notification at every meeting");
    }
}
