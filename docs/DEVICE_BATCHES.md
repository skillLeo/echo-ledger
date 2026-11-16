# Device batches

Three batches are kept ready, so a free device slot is never wasted: the phone (S25), the Windows laptop, and the Mac
with the meeting apps. Each runs in order. "You" is the person holding the device; everything else is done from the
development Mac. Results go into [SCREEN_SHARE_MATRIX.md](SCREEN_SHARE_MATRIX.md), the checklists and HANDOFF.

Phone rules for every batch: `pm install -r` only; never uninstall, never clear data; check `mCallState=0` first.

## Phone (S25, Android 17) — v1.8.1 S5 retest + v1.8 0.3, about 35 minutes

Before: the charger plugged in; not on a call; the new build installed (`pm install -r`). Measured on 2026-11-16 with
the first S5 build: Android never reports a floating window in a screen share, so the phone now keeps every floating
window secure (a black box for viewers) unless Settings → Privacy & data → During screen sharing is **Show**.

**Part A — from the Mac over adb (you keep the phone unlocked; tap only when a system prompt appears):**

| # | Step | Pass when |
|---|---|---|
| A1 | `dumpsys window windows`: every "EchoLedger …" window | SECURE next to each while the setting is Private |
| A2 | Start a test voice note; Samsung's screen recorder (full screen) on, then off | The video shows a black box where the recording controls are; the phone shows them normally |
| A3 | Setting → **Show**, record again; then back to **Private (black box)** | Show: the video shows the recording controls with the clock; back to Private: black again, also for windows already open |
| A4 | The recording controls → **Hide EchoLedger windows**, tap the grey dot; then the tile twice (`cmd statusbar expand-settings` first, then `click-tile`) | Grey dot, then back; the tile hides and shows |
| A5 | Hide, then force a new process (the app updated or stopped by Android), start a voice note | Shown again after the restart (Hide lasts until shown or a restart), and still a black box in a screen recording |
| A6 | Live Translate → Watch translate on a video (you tap **Start now**), record the screen | Box and toolbar are black in the video |
| A7 | Discard the test voice note; delete the test videos | Nothing left behind |

**Part B — with you and a second screen as the viewer (the Mac or the laptop):**

| # | Step | What you watch on the viewer |
|---|---|---|
| B1 | Google Meet: the phone shares its whole screen with the test voice note running | A black box, never the clock or text |
| B2 | Meet again, sharing a single app if Android offers it | EchoLedger not shown at all |
| B3 | Zoom: the phone shares its screen | As B1 |
| B4 | WhatsApp video call with a second device or person, share the phone screen | As B1 |

**Part C — v1.8 0.3, SIM calls (you make the calls):** with the helper connected, one incoming and one outgoing SIM
call, an unsaved number, a 5-second call and two calls back to back. EchoLedger reads its capture log over adb after
each: which audio source (VOICE_CALL, uplink, downlink) opened and whether both sides had sound. If One UI blocks them,
the next build imports Samsung's own call recordings instead (Phone app → Settings → Record calls → Auto record).

## Windows laptop — v1.8.1 S0/S6/S7, about 45 minutes

The phone is the viewer. Rows from [MANUAL_QA.md](MANUAL_QA.md) "Screen-share privacy".

1. Record the Windows build (`winver`) in the matrix's Systems table (S0).
2. Install the v1.8.1 Windows build from CI.
3. S-1 Share Check (WGC and DXGI on real hardware).
4. S-2 whole-screen shares in Zoom, Meet (Chrome), Teams, a Slack huddle and WhatsApp desktop, with the captions, the
   pill, the after-call card, Live Translate and the main window open; watch on the phone.
5. S-4 Remote Desktop into the laptop (the amber line).
6. S-8 Show in shares for 10 minutes; S-9 the hide-all shortcut; S-10 Copy diagnostics.
7. S-3 (Windows 10 before 2004) only if a VM is at hand.

## Mac with the meeting apps — v1.8.1 S0/S6/S7, about 45 minutes

The phone is the viewer. Rows from [MANUAL_QA.md](MANUAL_QA.md) "Screen-share privacy".

1. Install the v1.8.1 Mac build from CI over the installed v1.6.0 (Hassam present: macOS asks again for the microphone
   and Screen Recording, because the build is not Apple-signed yet); the window states were already read from the CI
   build on 2026-11-16 (0 for each).
2. S-1 Share Check on the installed build.
3. S-5 Test with your phone: Zoom, Teams, Meet in Chrome, Slack, WhatsApp, whole screen.
4. S-6 Zoom in each Screen capture mode, and confirm Zoom 7's menu labels for the setup steps.
5. S-7 a whole-screen share in an app not yet confirmed (the windows leave within 300 ms and come back).
6. S-8, S-9, S-10.
7. MAC-31 (v1.8 0.4): opening EchoLedger again from Finder, Launchpad, Spotlight and the Dock opens its window.
