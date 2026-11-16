# Device batches

Three batches are kept ready, so a free device slot is never wasted: the phone (S25), the Windows laptop, and the Mac
with the meeting apps. Each runs in order. "You" is the person holding the device; everything else is done from the
development Mac. Results go into [SCREEN_SHARE_MATRIX.md](SCREEN_SHARE_MATRIX.md), the checklists and HANDOFF.

Phone rules for every batch: `pm install -r` only; never uninstall, never clear data; check `mCallState=0` first.

## Phone (S25, Android 17) — v1.8.1 S5, about 30 minutes

Before: the charger plugged in (a screen recording drains the battery); not on a call; the new build installed.

**Part A — from the Mac over adb (you keep the phone unlocked; tap only when a system prompt appears):**

| # | Step | Pass when |
|---|---|---|
| A1 | Read the permissions | DETECT_SCREEN_RECORDING granted; "Display over other apps" on |
| A2 | Start a test voice note | The recording pill shows (screenshot) |
| A3 | Samsung screen recorder on (its Quick Settings tile; you may tap **Start recording**), then off | Within about a second the pill is a plain grey dot with no clock or text, and it comes back when the recorder stops; the recorded video shows only the dot |
| A4 | A shell recording (`adb shell screenrecord`) | Note whether Android reports it (documents the best public signal) |
| A5 | Tap the pill → **Hide EchoLedger cards**, then tap the dot | The dot, then the pill again |
| A6 | The **Hide EchoLedger cards** tile (added for the test, removed after) | First tap: dot; second tap: the pill |
| A7 | Settings → Privacy & data → During screen sharing → **Black box**, record; then **Show**, record; then back to **Hide cards** | Black box: the video shows black where the pill is, the phone shows the pill. Show: the video shows the pill |
| A8 | Live Translate → Watch translate on a video (you tap **Start now**) with no share running | The box stays visible: our own sound capture is not a screen share |
| A9 | Discard the test voice note; delete the test videos made in this batch | Nothing left behind |

**Part B — with you and a second screen as the viewer (the Mac or the laptop):**

| # | Step | What you watch on the viewer |
|---|---|---|
| B1 | Google Meet: the phone and the viewer join one meeting; the phone shares its whole screen with the test voice note running | A grey dot, never the clock or text |
| B2 | Meet again, sharing a single app if Android offers it | EchoLedger not shown at all |
| B3 | Zoom: the phone shares its screen | As B1 |
| B4 | WhatsApp video call with a second device or person, share the phone screen | As B1 |

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

1. Install the v1.8.1 Mac build from CI; read every EchoLedger window's sharing state with `CGWindowListCopyWindowInfo`
   (0 for each) for the matrix.
2. S-1 Share Check on the installed build.
3. S-5 Test with your phone: Zoom, Teams, Meet in Chrome, Slack, WhatsApp, whole screen.
4. S-6 Zoom in each Screen capture mode, and confirm Zoom 7's menu labels for the setup steps.
5. S-7 a whole-screen share in an app not yet confirmed (the windows leave within 300 ms and come back).
6. S-8, S-9, S-10.
