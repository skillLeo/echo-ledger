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
| 2026-11-16 | Windows 11 24H2 (10.0.26100, Server 2025 CI runner) | v1.8.1 desktop (commit 23e58956, `--smoke-test --share-check`) | WGC and DXGI | **Pass** | Protected magenta test window 0 px with both; green control 28,704 of 28,704 px (CI run 43783710402) |
| 2026-11-16 | Windows 10 22H2 kernel (10.0.20348, Server 2022 CI runner) | the same | WGC and DXGI | **Pass** | Magenta 0 px; green 28,704 of 28,704 px |
| 2026-11-16 | Windows 11 25H2 on Arm (x64 app under emulation, CI runner) | the same | WGC and DXGI | Skipped | The runner has no capture session (`control_missing`): nothing could be measured; needs a real Arm PC |

So on this Mac the window flag holds for ScreenCaptureKit display captures, streams and a mid-capture filter change.
Meeting apps can still capture differently (another filter, another API, their own modes such as Zoom's "without window
filtering"), so the meeting-app rows below still need the guided test with a second device.

## Meeting apps (whole screen / one window / tab)

| App | Windows 11 | Windows 10 | macOS 27 (this Mac) | macOS 15 |
|---|---|---|---|---|
| Zoom desktop | — / — / n/a | — / — / n/a | — / — / n/a (and each Screen capture mode) | — |
| Google Meet in Chrome | — / — / — | — / — / — | — / — / — | — |
| Microsoft Teams | — / — / n/a | — / — / n/a | — / — / n/a | — |
| Slack huddle | — / — / n/a | — / — / n/a | — / — / n/a | — |
| WhatsApp desktop | — / — / n/a | — / — / n/a | — / — / n/a | — |

## Windows in each test

Baseline of the installed app before Phase S (v1.7.0 desktop build, macOS 27.3, read with `CGWindowListCopyWindowInfo`
on 2026-11-16): the dictation overlay "EchoLedger dictation" reports sharing state 0 (left out of captures, as
`content_protected(true)` sets); the main window reports sharing state 1 (captured, so visible in a whole-screen share).
Live Translate is built with protection off (`create_translate_window`), so it is visible in shares too.

Captions, the recording pill, the after-call card, Live Translate and the main window (and the Assist card once it
exists).

From v1.8.1 every one of these windows registers with the privacy service and is built with the capture flag set
(`desktop/src-tauri/src/privacy`): with the default "All EchoLedger windows" the main window and Live Translate are
protected too. To fill: the same `CGWindowListCopyWindowInfo` read on the installed v1.8.1 build (sharing state 0 for
every EchoLedger window).
