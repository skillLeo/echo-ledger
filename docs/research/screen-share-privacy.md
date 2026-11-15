# Screen-share privacy: what each system can do

Research for v1.9 Phase S ("Private screen share"), from Hassam's report *Private Overlay: Screen-Share Privacy
Research* (15 Nov 2026) and the FACTS in the v1.9 plan. Sections marked **Verified here** were measured on our own
machines; everything else comes from the sources listed under each point. Results from our own tests go to
[SCREEN_SHARE_MATRIX.md](../SCREEN_SHARE_MATRIX.md).

## The short version

| Where | Hidden in a screen share? | How | How sure |
|---|---|---|---|
| Windows 11, Windows 10 version 2004+ (build 19041+) | Yes, see-through: people see what is behind our window | `SetWindowDisplayAffinity(WDA_EXCLUDEFROMCAPTURE)` | High |
| Windows 10 before 2004 | No: the same call acts like `WDA_MONITOR`, a black box | — | Warn the user |
| macOS 14 and older | Yes, in most apps | `NSWindow.sharingType = .none` | Medium |
| macOS 15, 26 and later | Depends on how the meeting app captures: hidden in Zoom with one setting, usually in Teams, not reliably in Meet; one window or one tab is always safe | Window flag + per-app setup + safety net | Medium, must be tested on each Mac |
| Android 15+ | Hidden when sharing starts (to be tested on the S25); black box as a last resort | `addScreenRecordingCallback`, `FLAG_SECURE` | Medium |
| iPhone | Not applicable: an app cannot float over other apps | The phone is a second screen | — |

The promise to users: "Your notes stay private when you share your screen, and EchoLedger tests it on your computer."
Never "undetectable", "stealth", "invisible to proctors", "cheat" or "bypass" (the repo-policy words check enforces this
in user-facing text).

## How a screen share sees the screen

The compositor (DWM on Windows, WindowServer on a Mac) draws every window into one picture for the monitor. A meeting
app asks the system for a copy of that picture. A DRM-style window (Netflix, `WDA_MONITOR`, `FLAG_SECURE`) asks for its
area to be blacked out in the copy. EchoLedger asks for something else: leave our window out of the copy, so the copy
shows the app behind it.

## Windows

- `SetWindowDisplayAffinity(hwnd, WDA_EXCLUDEFROMCAPTURE = 0x11)`: "The window is displayed only on a monitor.
  Everywhere else, the window does not appear at all."
  https://learn.microsoft.com/en-us/windows/win32/api/winuser/nf-winuser-setwindowdisplayaffinity
- Available from Windows 10 version 2004 (build 19041). On older builds the same call silently behaves like
  `WDA_MONITOR` (a black box) and reports success.
- Only for top-level windows of our own process, while DWM composes. Set it before the window first shows: a share that
  is already running can keep the previous picture for a moment.
- DWM enforces it for PrtSc, Snipping Tool, Zoom, Teams and Meet sharing, OBS and Windows Recall (IOActive).
- Not a lock: Microsoft says it is not DRM. It does not apply in a Remote Desktop session (the window shows there), and
  code injected into our process can turn it off. IOActive advises reading the flag back and re-checking while the app
  runs: https://www.ioactive.com/the-drm-flag-that-isnt-drm/ (3 Sep 2026).
- Tauri/tao: `content_protected(true)` calls `SetWindowDisplayAffinity` with `WDA_EXCLUDEFROMCAPTURE`, ignores the
  result and has no fallback, so EchoLedger must read it back itself (`GetWindowDisplayAffinity`).

## Mac

- Tauri's `content_protected(true)` sets `NSWindow.sharingType = .none`, which Apple now calls legacy.
- macOS 14 and older: respected by both the CoreGraphics capture path and ScreenCaptureKit.
- macOS 15: ScreenCaptureKit stopped respecting it. Apple DTS, July 2025: "At this time there are no public APIs for
  preventing screen capture." https://developer.apple.com/forums/thread/792152 · Electron closed the same issue as
  "unfortunate expected behavior" (#48258) · Tauri tracks it in #14200.
- Apps on ScreenCaptureKit can show our window on macOS 15: current Zoom, new Teams, QuickTime, system screenshots,
  Safari, new OBS (JobPath; Pierce Freeman, 20 Jul 2025).
- Chrome (Google Meet) has used the older capture path, so it often hid the window; Chrome is moving to ScreenCaptureKit,
  so this can change with any Chrome update.
- macOS 26 is mixed: a Nov 2026 test on macOS 26.6.2 found the window hidden in ScreenCaptureKit, `screencapture` and
  CoreGraphics captures
  (https://dev.to/gianlucabave/how-i-made-a-window-invisible-to-screen-sharing-on-macos-and-checked-that-it-really-is-png),
  while other reports saw QuickTime record a protected window at once.
- Why it is mixed: ScreenCaptureKit leaves such a window out only for some capture setups. In a Nov 2025 report
  (FB21115847) a display capture left the window out when it started but showed it after the filter changed, while
  QuickTime showed it at once; Apple is investigating. https://developer.apple.com/forums/thread/808016
- Zoom on Mac respects our window in its window-filtering capture modes: Settings → Share Screen → Advanced → Screen
  capture mode → "Advanced capture with window filtering". "Advanced capture without window filtering" shows it; "auto"
  switches between the two.
  https://community.zoom.com/t5/Meetings/Flickering-window-in-the-screen-capture-of-Zoom-Mac-desktop/td-p/110428 ·
  https://support.yoodli.ai/en/articles/9048658-troubleshooting-the-yoodli-desktop-app-hide-from-screen-share-setting-on-macs ·
  https://shownotes.app/notes-invisible-to-screen-share/
- Teams desktop on Mac: hidden in Yoodli's guide; "may vary" for the whole screen per ShowNotes. Treat it as "usually
  hidden, confirm once".
- Meet in Chrome or Safari: whole-screen sharing does not hide it reliably (Yoodli): share a tab or one window.
- Private Apple APIs exist (Chromium reportedly used one). We do not use them: they break with updates and risk App
  Store rejection.

### Making it work on a Mac: four layers

| Layer | What it does | What it covers |
|---|---|---|
| 1. Window flag (already on) | `sharingType = .none` on every EchoLedger window | Apps that respect it: older capture paths, Webex, Zoom in a filtering mode |
| 2. One Zoom setting | "Advanced capture with window filtering" | Zoom whole-screen sharing on the newest Macs |
| 3. Share less | One window or one tab, or keep EchoLedger on a display that isn't shared (a second monitor, an iPad with Sidecar) | Every app, every macOS |
| 4. Safety net | When a whole-screen share starts in an app not confirmed on this Mac, EchoLedger hides its windows and the cards continue on the phone | Everything else |

Proof on a Mac comes from a 1-minute guided test per meeting app (join the meeting on the phone, share the whole
screen, look for EchoLedger's test card), remembered per app version and macOS version and repeated after updates.

## Meeting apps and how people share

The safest share is one window or one tab: only that window or tab is sent, so a floating window on top never shows,
on any system.

| App | Windows 10 2004+ / 11, whole screen | macOS 15+, whole screen | Safest way |
|---|---|---|---|
| Zoom desktop | Hidden (IOActive) | Hidden after the one Zoom setting | One window, or another display |
| Google Meet in Chrome / Edge | Hidden (IOActive) | Not reliable | A tab or one window |
| Microsoft Teams (new) | Hidden (IOActive) | Usually hidden; confirm once | One window |
| Slack huddles | Expected hidden (same Windows capture); to test | To test | One window |
| WhatsApp desktop | Expected hidden; to test | To test | One window |
| Any website in a browser | Hidden | Depends on the browser | The tab |
| QuickTime / system screenshots (Mac) | — | Can show it | — |

## Phones

- Android: `FLAG_SECURE` turns a window into a black box in screenshots, recordings and shares; it draws attention, so
  it is a last resort. Android 15 adds `WindowManager.addScreenRecordingCallback` with the install-time permission
  `DETECT_SCREEN_RECORDING`; it is documented for the app's activities, so whether it fires when only our floating call
  card is visible must be tested on the S25. Android 14 QPR2+ can share a single app.
- iPhone: an app can't draw over WhatsApp, Meet or Zoom. Its role is a second screen; `UITraitCollection.sceneCaptureState`
  (iOS 17+) says when its own screen is recorded or mirrored, so cards can be blurred then.

## What is never hidden

Phone cameras and people in the room, projectors, HDMI capture cards, Remote Desktop and some remote-help tools or
virtual machines, other software on the same computer (any process can see our window and read its flag; company
monitoring, lockdown browsers and proctoring apps can see EchoLedger, and we never try to get around them), and the app
icon in the tray, menu bar or Dock. Behaviour (reading from the screen, answer timing) can be noticed.

**Scope limit (permanent):** EchoLedger hides its windows from screen sharing only. It never hides from other software:
no renamed processes, no hiding from window lists, no tricks against proctoring apps, lockdown browsers or company
monitoring tools.

## How other products do it

| Product | What it does | Lesson |
|---|---|---|
| Murmury (open source, Mac) | Magenta test box + green control, captures three ways, counts pixels, reruns after macOS updates | Our Share Check copies this |
| JobPath | On macOS 15 watches for the system's capture sign and closes its window | Hiding when sharing starts is the only public-API backup on a Mac |
| Cluely | Floating window hidden from screen share; captured by QuickTime and Zoom on macOS 15 (Pierce Freeman) | Don't promise what the Mac can't keep |
| CueNotch | `sharingType = .none`, asks users to test with QuickTime | A self-test belongs in the product |
| Pluely (Tauri) | tauri-nspanel on Mac, never takes focus | The same stack works |
| Signal Desktop, OBS, Zoom, Bitwarden | The same switch to keep content out of Recall or captures; Bitwarden promises "most" shares | Call it privacy, not stealth |

## Sources

- SetWindowDisplayAffinity, Microsoft Learn: https://learn.microsoft.com/en-us/windows/win32/api/winuser/nf-winuser-setwindowdisplayaffinity
- The DRM Flag That Isn't DRM, IOActive, 3 Sep 2026: https://www.ioactive.com/the-drm-flag-that-isnt-drm/
- Apple Developer Forums, no public API to prevent capture (Jul 2025): https://developer.apple.com/forums/thread/792152
- Apple Developer Forums, ScreenCaptureKit omits a sharing-none window only sometimes (FB21115847, Nov 2025): https://developer.apple.com/forums/thread/808016
- Electron issue #48258 and Tauri issue #14200 (GitHub)
- JobPath, how apps hide windows from screen capture and what broke in macOS 15 (Product Hunt)
- Pierce Freeman, Building a (kind of) invisible mac app, 20 Jul 2025
- DEV Community, macOS 26.6.2 test, Nov 2026: https://dev.to/gianlucabave/how-i-made-a-window-invisible-to-screen-sharing-on-macos-and-checked-that-it-really-is-png
- Kopya, WDA_EXCLUDEFROMCAPTURE & setContentProtection explained, 22 Sep 2026
- Zoom Community, window filtering: https://community.zoom.com/t5/Meetings/Flickering-window-in-the-screen-capture-of-Zoom-Mac-desktop/td-p/110428
- Yoodli Help, hide from screen share on Macs: https://support.yoodli.ai/en/articles/9048658-troubleshooting-the-yoodli-desktop-app-hide-from-screen-share-setting-on-macs
- ShowNotes: https://shownotes.app/notes-invisible-to-screen-share/
- Android 15 features (screen recording detection), Android Developers; ProAndroidDev, screen recording detection on Android 15
- Vendor pages (marketing, not evidence): Mirly (15 May 2026), CueNotch (16 Jul 2026), Interview Coder (Jun 2026), Pluely (GitHub)

## Verified here

(Filled in as Phase S measures this repo's own builds; each entry names the machine, OS build, app version and method.)

- 2026-11-16, Hassam's MacBook, macOS 27.3 (26C5035r): the macOS 27 SDK marks `CGWindowListCreateImage` unavailable
  ("Please use ScreenCaptureKit instead"), so apps built today capture only through ScreenCaptureKit; the automated
  capture needs the Screen Recording permission (without it ScreenCaptureKit answers "The user declined TCCs for
  application, window, display capture"), so the Mac result comes from the in-app Share Check and the guided test.
