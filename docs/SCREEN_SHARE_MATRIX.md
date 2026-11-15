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
| 2026-11-16 | macOS 27.3 (26C5035r) | test harness | ScreenCaptureKit display capture, `screencapture` | Not run | The process had no Screen Recording permission ("The user declined TCCs for application, window, display capture"); needs a Mac window with Hassam, or the in-app Share Check once the permission is granted |

## Meeting apps (whole screen / one window / tab)

| App | Windows 11 | Windows 10 | macOS 27 (this Mac) | macOS 15 |
|---|---|---|---|---|
| Zoom desktop | — / — / n/a | — / — / n/a | — / — / n/a (and each Screen capture mode) | — |
| Google Meet in Chrome | — / — / — | — / — / — | — / — / — | — |
| Microsoft Teams | — / — / n/a | — / — / n/a | — / — / n/a | — |
| Slack huddle | — / — / n/a | — / — / n/a | — / — / n/a | — |
| WhatsApp desktop | — / — / n/a | — / — / n/a | — / — / n/a | — |

## Windows in each test

Captions, the recording pill, the after-call card, Live Translate and the main window (and the Assist card once it
exists).
