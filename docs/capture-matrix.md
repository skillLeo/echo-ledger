# Capture matrix: which EchoLedger windows show in screenshots and screen shares

Since desktop 2.0.14 (PLAN_V2_1_POLISH FIX-01, I-01 and I-02). One policy decides every window; this page is that
policy, how it is enforced, and the manual checks for the device slot, with their results.

## The policy

Only the windows with **live words** are hidden. Everything else shows, in screen shares and in the person's own
screenshots and screen recordings.

| Window | Default | What changes it | macOS | Windows |
|---|---|---|---|---|
| Main window | shown | "Hide for…" (Settings › Screen-share privacy, the tray, the share notice), until the time chosen | `sharingType` read-only; none while hidden | `WDA_NONE`; `WDA_EXCLUDEFROMCAPTURE` while hidden |
| Recording controls (the pill) | **shown** | nothing | read-only | `WDA_NONE` |
| After-call card | **shown** | nothing | read-only | `WDA_NONE` |
| Dictation box (the person's own words as they type) | **shown** | nothing | read-only | `WDA_NONE` |
| Tray and menu-bar menus, tooltips, the input indicator (windows the system makes for EchoLedger) | **shown** | nothing | read-only | not affected |
| Captions | **hidden** | only its own window: "Show in screen shares" on the captions, until they close (never kept, never by a setting) | none | `WDA_EXCLUDEFROMCAPTURE` (a black box at least on Windows before 2004) |
| Live Assist card | **hidden** | only its own window: "Show in screen shares" on the card, until it closes (never kept, never by a setting) | none | `WDA_EXCLUDEFROMCAPTURE` (a black box at least on Windows before 2004) |
| Live Translate | **hidden** | Settings › Screen-share privacy › Live Translate (`hideFromSharing`), and "Show Live Translate for 10 min" | none | `WDA_EXCLUDEFROMCAPTURE` |
| Share Check test window and test card | hidden | nothing (they measure the flag) | none | `WDA_EXCLUDEFROMCAPTURE` |
| Share Check green control | shown | nothing | read-only | `WDA_NONE` |

"Hide EchoLedger windows" (the shortcut, the tray, Settings) takes every window off the screen, whatever the table says.
On a Mac, a whole-screen share in a meeting app not confirmed hidden takes the hidden windows (captions, Live Assist,
Live Translate) off the screen until it ends; the shown windows stay.

### Decisions behind it

- **The pill, the after-call card and the dictation box show** (the owner): they carry no one else's words, and they
  were missing from the owner's own screenshots and recordings (I-02).
- **The tray is a native menu**, not a window of EchoLedger's. It was still missing from screenshots: the privacy
  service gave every window the system makes for EchoLedger (the tray's and the menu bar's menus, tooltips) the floating
  windows' flag. Those windows are shown now (`privacy::others_protected`).
- **Captions and the Live Assist card keep their stricter rule** (the lead, 2026-11-24): hidden unless shown from their
  own window, whatever the Screen-share privacy setting says. Nothing was migrated, and nothing can start showing live
  words for anyone who had set the setting to Off.
- **Live Translate follows the same switch as before** (`hideFromSharing`), now its only window.

### What Settings says

- Settings › Screen-share privacy, first: "Live words (Live Assist, captions, translation) are hidden from screen
  shares. To show one, use its own window's menu." Then the system's line:
  - macOS: "Hidden from screenshots and most screen shares. If you share your entire screen in some apps, it may still
    show. Share a single window to be safe." (On macOS 15 and later ScreenCaptureKit can ignore the flag; Apple has no
    public way to block capture.)
  - Windows: "Hidden from screen shares and recordings (Windows 10 version 2004 or later)."
  - Then: "The recording controls, the after-call card and the dictation box always show in screen shares and
    screenshots, like the main window."
- The tray: "Hide the EchoLedger window from screen shares for… ›" (the main window only), and "Hide EchoLedger windows".
- The desktop has no Urdu interface yet; the page is checked right to left.

## How it is enforced

- **One place.** Every window is built through `privacy::window()` (`desktop/src-tauri/src/privacy/mod.rs`), which sets
  the flag on the builder before the first show and again whenever a choice changes (`apply_all`). `KNOWN` gives each
  window its kind and `protected()` is the rule; `privacy::policy()` is the table above, window by window.
- **The CI checks** (they run in `cargo test` and `npx vitest run` on both desktop workflows):
  - `every_window_is_built_through_the_privacy_service`: no window built outside `privacy::window`, and no
    `set_content_protected`, `setSharingType` or `SetWindowDisplayAffinity` outside `src-tauri/src/privacy`;
  - `capturePolicyWords.test.tsx`: no page code sets a window's capture flag;
  - `the_capture_policy_table`: the table above.
- **The smoke test** (`EchoLedger --smoke-test`, run on the built app on both CI runners) opens every window and reads
  its flag back from the system:
  - macOS: each window's `NSWindow.sharingType` and the window server's `kCGWindowSharingState`
    (`CGWindowListCopyWindowInfo`), the main window included, against the table; it fails with exit 7;
  - Windows: each window's `GetWindowDisplayAffinity` against the table; it fails with exit 10. A window the table hides
    whose flag the runner did not keep is the service's own case (it says so and keeps the window off the screen), and
    is reported, not failed.

## Manual checks (device slot)

Set up: EchoLedger 2.0.14, signed in, defaults. Start a recording with captions on, open Live Assist if available, and
open Live Translate on Windows. The pill and the captions are on screen; open the tray menu for the tray rows.

Expected: the **shown** windows are in the picture; the **hidden** ones are not (on macOS whole-screen shares, may be).

### macOS 27.3 (the owner's Mac)

| # | How | Main window | Pill | After-call card | Dictation box | Tray menu | Captions | Live Assist | Result |
|---|---|---|---|---|---|---|---|---|---|
| M1 | ⇧⌘4 then Space, click the pill (window screenshot) | – | shows | – | – | – | – | – | |
| M2 | ⇧⌘3 (whole screen) | shows | shows | shows | shows | shows (open it first) | hidden | hidden | |
| M3 | `screencapture -x ~/Desktop/m3.png` in Terminal | shows | shows | shows | shows | shows | hidden | hidden | |
| M4 | ⇧⌘5 › Record Entire Screen, 5 s | shows | shows | shows | shows | shows | hidden | hidden | |
| M5 | Zoom › Share Screen › Desktop (whole screen) | shows | shows | shows | shows | – | may show | may show | |
| M6 | Zoom › Share Screen › one window (EchoLedger's main window) | shows | – | – | – | – | – | – | |
| M7 | Meet in Chrome › Present › Entire screen | shows | shows | shows | shows | – | may show | may show | |
| M8 | Meet in Chrome › Present › A tab (another tab) | – | – | – | – | – | – | – | |
| M9 | Teams › Share › Screen | shows | shows | shows | shows | – | may show | may show | |

"May show": macOS 15 and later lets ScreenCaptureKit capture windows that ask to be hidden; that is why the settings say
"most screen shares". Write what each app did, with its version.

### Windows 11

| # | How | Main window | Pill | After-call card | Dictation box | Captions | Live Assist | Live Translate | Result |
|---|---|---|---|---|---|---|---|---|---|
| W1 | Win+Shift+S › Full screen | shows | shows | shows | shows | hidden | hidden | hidden | |
| W2 | Snipping Tool › Record, 5 s | shows | shows | shows | shows | hidden | hidden | hidden | |
| W3 | Zoom › Share Screen › Screen | shows | shows | shows | shows | hidden | hidden | hidden | |
| W4 | Teams › Share › Screen | shows | shows | shows | shows | hidden | hidden | hidden | |
| W5 | Meet in Chrome or Edge › Present › Entire screen | shows | shows | shows | shows | hidden | hidden | hidden | |

## Results

Filled in after each device slot: the date, the system and app versions, and each row's result.

| Date | System | EchoLedger | Rows | Notes |
|---|---|---|---|---|
| | | | | |
