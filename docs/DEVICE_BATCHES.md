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

Run 2026-11-16 16:13–16:24 on the v1.1 build: A1–A5 and A7 pass (SCREEN_SHARE_MATRIX.md, "Retest with the merged S5
build"); A6 waiting (the phone locked by its power key). Samsung's recorder can't be started over adb, so the captures
use adb's recorder and screenshots; the overlay buttons are found with `uiautomator dump --windows`.

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

**Part C — v1.8 0.3, SIM calls through the Phone app's own recordings (you make the calls):**

2026-11-16 22:20: A6 PASS on the merged build (13ced291): Live Translate's box SECURE and 100 % black in a screen
recording (it started through Android's sound sharing while the helper was down). Part C CANNOT run on this S25:
Samsung hides Record calls in the Phone app for its region (Samsung's own setting for the phone's region), so
there are no Phone app recordings to import. Part C needs a Galaxy from a region with call recording (Pakistan
CSC). Part D left for a later slot. After wireless debugging is switched off, Shizuku stops and the helper with it:
restart Shizuku (its start command over adb, or the Shizuku app), then open EchoLedger.

1. Setup: the full build; auto-record on; Record phone calls → Always; allow Call log, Contacts and Music and audio.
   Phone app → ⋮ → Settings → Record calls → Auto record calls → All calls. Check over adb:
   `settings get system record_calls_automatically_on_off` = 1. Run `adb logcat -s EchoLedgerSim`.
2. Calls: incoming from a saved contact (30 s); outgoing to a saved contact (30 s); an unsaved number (30 s — the
   after-call card says "Call with <number>"); a 5-second call ("Not imported, shorter than 10 s"; then Skip calls
   shorter than → Never, repeat: imported); two calls back to back (~20 s each: two recordings, each named right).
3. Per call note: the `sim call:` line (which source opened, whether each side had sound), the `phone app recording:`
   line, the card title, Settings "Last call …", one Library item with the right length, the files still in My Files →
   Recordings → Call, and no "Missed recording".
4. Once: `content query --uri content://media/external/audio/media --projection
   relative_path:owner_package_name:date_added:date_modified:_size:duration --where "relative_path LIKE 'Recordings/Call%'"`
   — the owner should be `com.samsung.android.dialer` or `com.samsung.android.incallui`; another package is a one-line
   addition; null → the "Import this recording" flow appears instead.

**Part D — v1.8 0.2 missed recordings (after the build with the detector is installed):**

| # | Step | Pass when |
|---|---|---|
| D1 | A WhatsApp call with the helper stopped | About 2.5 minutes later: Activity → Needs action "Missed recording" with **Start the helper**, and one notification |
| D2 | A SIM call (phone calls set to Always) with the helper stopped | An item, found through the call log |
| D3 | A call in an app switched off for auto-record; a call shorter than *Skip calls shorter than* | No item |
| D4 | A normally recorded call | No item; Copy diagnostics shows all five stages for it |
| D5 | The console | The server copy appears; no second phone notification |
| D6 | Live Translate (Game and Watch) while hidden; its notification's **Show** | The grey dot where the grip was; Show brings everything back |
| D7 | Hide, then restart the app | Shown again |
| D8 | TalkBack double-tap on the minimised red dot | The recording controls open |
| D9 | 0.2c, after a few days of use: Copy diagnostics → "Calls, last 7 days" and "Auto-record" | The real causes of any missed calls, each then fixed with a test |

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
   The Finder path passed on 2026-11-16; Dock, Launchpad, ⌘W and ⌘M are left.
8. v1.8.1 follow-up build (the freeze fix, the menu on a click, the badge, the Dock app): MAC-22 is a release gate
   (full-screen Zoom and Meet, a recording running), then MAC-32 (Dock click, Dock Quit / ⌘Q twice / logout while
   recording, start at login, ⌘H). One list for Hassam, about 20 minutes, all clicks written out in plain words.
   Run 2026-11-16 ~21:30 on macOS 27.3 with the CI build of 4f8f775d (merged bee66862), Hassam's answers: all yes —
   Dock icon and dot, ⌘Tab; the menu opens on a click; recording controls over full-screen Zoom and full-screen Meet,
   Pause leaves the meeting in front; ⌘H hides the window, not the controls; Dock Quit while recording closes within
   20 s and the recording is kept (not "Recovered"); ⌘Q twice keeps it too. Not run: start at login (MAC-32 e), the
   phone-as-viewer whole-screen shares for Zoom (both capture modes), MAC-22 on macOS 14.4/15 (no machine).
