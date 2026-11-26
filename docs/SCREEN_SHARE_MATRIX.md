# Screen-share matrix

What the other side of a screen share sees of each EchoLedger window, measured on real machines (v1.9 Phase S, S0 and
S7). Background and sources: [research/screen-share-privacy.md](research/screen-share-privacy.md).

**Hidden** = the app behind our window shows · **Black** = a black box where our window is · **Visible** = our window
and its text show · **—** = not tested yet.

## Systems

| Machine | System | Build | Notes |
|---|---|---|---|
| Hassam's MacBook (Apple silicon) | macOS 27.3 | 26C5035r | 2026-11-16: the macOS 27 SDK no longer offers `CGWindowListCreateImage` ("Please use ScreenCaptureKit instead"), so captures made by current apps go through ScreenCaptureKit |
| Hassam's Windows laptop | Windows (build to record in S0) | — | |

## Automated check (Share Check method, no meeting app)

| Date | System | App version | Method | Result | Notes |
|---|---|---|---|---|---|
| 2026-11-16 | macOS 27.3 (26C5035r) | test harness | ScreenCaptureKit display capture, `screencapture` | Not run | First attempt: the process had no Screen Recording permission ("The user declined TCCs for application, window, display capture") |
| 2026-11-16 | macOS 27.3 (26C5035r) | test harness (`sharingType = .none`, the flag Tauri's `content_protected(true)` sets) | `screencapture -x -m` | **Pass** | Protected magenta window 0 px; green control 153,600 px (its whole area at 2×) |
| 2026-11-16 | macOS 27.3 (26C5035r) | test harness | ScreenCaptureKit `SCScreenshotManager`, display filter, nothing excluded | **Pass** | Magenta 0 px; green 153,600 px |
| 2026-11-16 | macOS 27.3 (26C5035r) | test harness | ScreenCaptureKit `SCStream` (5 fps), display filter, nothing excluded | **Pass** | Magenta 0 px; green 152,004 px |
| 2026-11-16 | macOS 27.3 (26C5035r) | test harness | The same stream after `updateContentFilter` (excluding no applications), then changed back | **Pass** | Magenta 0 px in both frames (the FB21115847 case did not show the window here); green 152,004 and 15,908 px (the second looks like a frame caught mid-change) |
| 2026-11-16 | macOS 27.3 (26C5035r) | v1.8.1 desktop (debug build, `--smoke-test --share-check`, the privacy service's own Share Check) | ScreenCaptureKit `SCScreenshotManager`, display filter, nothing excluded, sRGB | **Pass** (twice) | Protected magenta test window 0 px; green control 64,480 of 64,480 px (its whole area at the 1920 px capture size) |
| 2026-11-16 | macOS 27.3 (26C5035r) | the same build with the test window built unprotected (a check that the check can fail) | the same | **Fail**, as it should | Magenta 28,620 px (the check pattern's magenta half); green 64,480 px |
| 2026-11-16 | macOS 27.3 (26C5035r) | the same build before the capture asked for sRGB | the same, in the display's colour space | Error (control missing) | The green control (sRGB 0, 200, 0) came back as about (90, 197, 58) in Display P3, outside the tolerance of 25: the check now captures in sRGB |
| 2026-11-16 | macOS 27.3 (26C5035r) | v1.8.1 desktop, the CI build (commit 23e58956, ad-hoc signed, run beside the installed v1.6.0 with `--smoke-test --share-check`) | ScreenCaptureKit, nothing excluded, sRGB | **Pass** | Magenta 0 px; green 64,480 of 64,480 px; 8.9 s |
| 2026-11-16 | macOS 27.3 (26C5035r) | v1.8.1, the INSTALLED app (CI build of `5d9f9779`, launched by LaunchServices as itself with `--smoke-test --share-check`) | ScreenCaptureKit, nothing excluded, sRGB | **Pass** | Magenta 0 px; green 61,433 of 64,480 px. Capture works for the installed identity: on macOS 27 EchoLedger's "Device Control and Data Access" grant (already on, for dictation) covers screen capture, so it needs no separate Screen & System Audio Recording entry |
| 2026-11-16 | Windows 11 24H2 (10.0.26100, Server 2025 CI runner) | v1.8.1 desktop (commit 23e58956, `--smoke-test --share-check`) | WGC and DXGI | **Pass** | Protected magenta test window 0 px with both; green control 28,704 of 28,704 px (CI run 43783710402) |
| 2026-11-16 | Windows 10 22H2 kernel (10.0.20348, Server 2022 CI runner) | the same | WGC and DXGI | **Pass** | Magenta 0 px; green 28,704 of 28,704 px |
| 2026-11-16 | Windows 11 25H2 on Arm (x64 app under emulation, CI runner) | the same | WGC and DXGI | Skipped | The runner has no capture session (`control_missing`): nothing could be measured; needs a real Arm PC |

So on this Mac the window flag holds for ScreenCaptureKit display captures, streams and a mid-capture filter change.
Meeting apps can still capture differently (another filter, another API, their own modes such as Zoom's "without window
filtering"), so the meeting-app rows below still need the guided test with a second device.

## Phone (S25, SM-S938B, Android 17, build CP2A.260605.016.S938BXXSDDZJ1)

Measured 2026-11-16 with the v1.8.1 build (commit 79dd2992) and Samsung's screen recorder (full screen, a
MediaProjection display capture), checked on the recorded video.

| Check | Result | Notes |
|---|---|---|
| `addScreenRecordingCallback` registered (DETECT_SCREEN_RECORDING granted) | Yes | `dumpsys window` lists the app's callback |
| The callback for a floating card (the recording pill, TYPE_APPLICATION_OVERLAY) | **Never fires** | `isVisibleInScreenRecording=false` all through the recording; it turns true only while the app's own screen (an activity) is in front. Android counts activities, not overlay windows |
| Another public signal | None found | The recorder's virtual display is FLAG_PRIVATE, so other apps don't see it in DisplayManager |
| "Hide cards" while recording | **Visible** | The video shows the pill with its clock |
| "Black box" (FLAG_SECURE on the card) | **Black** | The video shows a black rectangle where the pill is; on the phone the pill shows normally |
| Hide button on the pill | Works | The pill becomes a grey dot without text; a tap brings it back |
| Quick Settings tile | First tap works | It hides the cards; a second tap sent by `adb cmd statusbar click-tile` did not show them again (to recheck by hand) |

So on the phone, protection while sharing has to come from FLAG_SECURE (the black box), not from detection.
Meet, Zoom and WhatsApp shares are tested after that change.

### Retest with the merged S5 build (2026-11-16, 16:13–16:24)

The v1.1 build (phone code as of `5411d52e`: every floating window secure unless the setting is Show), installed with
`pm install -r` and driven over adb. Captures: the phone's own screenshot (`screencap`) and adb's screen recorder
(`screenrecord`). Both go through the same Android rule that leaves secure windows out of any non-secure display, the
rule a MediaProjection share (Meet, Zoom, WhatsApp, Samsung's recorder) meets too. Each result is the share of black
pixels inside the window's own frame, on every frame of the video; the rest of the screen was never looked at.

| # | Check | Result |
|---|---|---|
| A1 | `dumpsys window`: "EchoLedger recording controls" (TYPE_APPLICATION_OVERLAY), setting Private | **SECURE** (Android 17 prints the flags as hex: `fl=1042108` holds 0x2000) |
| A2 | A test voice note: a screenshot and a 4-second recording | **Black**: 100 % of the controls' frame, every frame |
| A3 | Setting → Show, record; back to Private with the window still open | Show: not secure, the video shows the red dot and the clock (0–0.3 % black). Private again: SECURE and 100 % black at once, without reopening the window |
| A4 | Hide EchoLedger windows on the controls; tap the grey dot; the tile twice (`click-tile`) | The controls become a 180 × 180 dot (still secure, 100 % black in the recording); the dot brings them back; the tile's first tap hides, the second shows them again (the earlier "second tap did nothing" is fixed) |
| A5 | Hide (tile), then a new process (an app update: pid 13736 → 20791), a new voice note | Shown again, SECURE, 100 % black in the recording |
| A6 | Live Translate (Watch translate), recorded | Waiting: blocked by a Live Translate bug (16:36, twice). With a test video playing in Samsung's video player (USAGE_MEDIA, started, uid 10133) and the helper ready, the Watch translate tile says "Nothing is playing yet"; no Watch app list is saved. Handed to the phone work |
| A7 | Clean-up | Both test notes discarded from the controls; every test video deleted from the phone right after it was copied; quick-settings tiles and the screen timeout back as before |

Samsung's screen recorder could not be started from adb (its "Start recording" button ignored taps sent by adb,
twice), so A2–A5 used adb's recorder. The first measurement above, made with Samsung's recorder by hand, showed the
same black rectangle.

## Meeting apps (whole screen / one window / tab)

| App | Windows 11 | Windows 10 | macOS 27 (this Mac) | macOS 15 |
|---|---|---|---|---|
| Zoom desktop | — / — / n/a | — / — / n/a | — / — / n/a (and each Screen capture mode) | — |
| Google Meet in Chrome | — / — / — | — / — / — | **Hidden** / — / — | — |
| Microsoft Teams | — / — / n/a | — / — / n/a | — / — / n/a | — |
| Slack huddle | — / — / n/a | — / — / n/a | — / — / n/a | — |
| WhatsApp desktop | — / — / n/a | — / — / n/a | — / — / n/a | — |

### Google Meet in Chrome, whole screen (macOS 27.3, 2026-11-16 17:01)

The installed CI build 5d9f9779 on the built-in display (Chrome's "Entire Screen" → Screen 1), a call recording
running. The viewer: the S25 in the same meeting (the Meet app, a second Google account, camera and microphone off).
A local script (`meetmap.py`) maps each EchoLedger window from its CGWindowList frame into the presenter's tile on
the phone (1.23 phone pixels per Mac point), measures the red of the recording dot there, and crops only those small
windows; the rest of the shared screen is never looked at.

| Window (sharing state) | 17:01:30 | 17:02:40 |
|---|---|---|
| EchoLedger recording controls, 352 × 84 (0) | Not shown: the phone shows Chrome's sharing bar behind it ("Skill Leo (You, presenting)"); red 0 % | Not shown: the phone shows Meet's tile behind it (its 4.8 % red is Meet's red tile, no timer) |
| EchoLedger dictation, 420 × 110 (0) | Not shown: Chrome's tabs and address bar behind it; red 0 % | Not shown; red 0.2 % |

Result: **hidden** in a Meet whole-screen share. The macOS screenshot Hassam took during the share left EchoLedger out
too (the same capture rule). Not yet run: "Show EchoLedger in screen share for 10 minutes" (S-8) as the positive
control, because the app froze (HANDOFF, 17:10).

## Windows in each test

Baseline of the installed app before Phase S (v1.7.0 desktop build, macOS 27.3, read with `CGWindowListCopyWindowInfo`
on 2026-11-16): the dictation overlay "EchoLedger dictation" reports sharing state 0 (left out of captures, as
`content_protected(true)` sets); the main window reports sharing state 1 (captured, so visible in a whole-screen share).
Live Translate is built with protection off (`create_translate_window`), so it is visible in shares too.

Captions, the recording pill, the after-call card, Live Translate and the main window (and the Assist card once it
exists).

From v1.8.1 every one of these windows registers with the privacy service and is built with the capture flag set
(`desktop/src-tauri/src/privacy`): with the default "All EchoLedger windows" the main window and Live Translate are
protected too. Read on 2026-11-16 from the v1.8.1 CI build (run beside the installed v1.6.0 with `--smoke-test`, sampled every 0.1 s
while its windows were open): "EchoLedger recording controls", "EchoLedger captions", "EchoLedger dictation",
"EchoLedger after a call" and the main window "EchoLedger" all report sharing state 0 (left out of captures; the main
window was 1 in v1.6.0). One untitled 500 × 500 window reports 1 but is never on screen (onscreen false; also present in
v1.6.0; no EchoLedger code makes a window of that size), most likely a framework helper window: to identify. Live
Translate is not opened by the smoke test and still needs its read.

Installed on the development Mac on 2026-11-16 (the CI build of `5d9f9779`, ad hoc, in /Applications over v1.6.0):
the main window "EchoLedger" and "EchoLedger dictation" report sharing state 0 while running normally, and the untitled
500 × 500 window (macOS's text-input indicator window, TUINSWindow) now reports 0 too (the audit fix sets every window
of the app's own process). EchoLedger was listed only under "System Audio Recording Only", not under Screen & System
Audio Recording — the same as the v1.6.0 it replaced: the cause of "no screen moments" on this Mac (v1.8 0.1 c).
