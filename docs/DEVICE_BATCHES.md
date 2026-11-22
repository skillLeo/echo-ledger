# Device batches

Three batches are kept ready, so a free device slot is never wasted: the phone (S25), the Windows laptop, and the Mac
with the meeting apps. Each runs in order. "You" is the person holding the device; everything else is done from the
development Mac. Results go into [SCREEN_SHARE_MATRIX.md](SCREEN_SHARE_MATRIX.md), the checklists and HANDOFF.

Phone rules for every batch: `pm install -r` only; never uninstall, never clear data; check `mCallState=0` first.

## Tonight 20:00–21:30 PKT (2026-11-22) — 2.0.4 on the Mac and Windows, phone 2.0.5, about 85 minutes

Builds:
- **Mac:** 2.0.4, signed with the pinned identity, already in /Applications. It starts only after a quit and reopen, because 2.0.3 was still running.
- **Phone:** 2.0.5 (code 25), already installed with data kept.
- **Windows:** `EchoLedger_2.0.4_x64-setup.exe` from https://github.com/skillLeo/echo-ledger/releases/tag/v2.0.4, SHA-256 `1cfaba63…a867`.

Not in tonight's batch:
- the call choice redesign (PLAN_CALL_CHOICE), the phone screenshot fix and the desktop system-audio warning are still in build or review. They get their own batch when they're installed.
- "ship" waits for the call choice, so tonight ends with a fix list, not "ship".

**1. Mac, about 30 minutes**
1. **Permissions.** Quit EchoLedger (⌘Q). In System Settings › Privacy & Security:
   - Microphone: EchoLedger on.
   - Screen & System Audio Recording: EchoLedger on under Screen Recording and under System Audio Recording Only. Remove an old EchoLedger entry with "–" first, if one is there.
   Open EchoLedger. Help › About shows 2.0.4.
2. **Window buttons.** If the window is in full screen, leave it with Ctrl+⌘+F. Pass if the close, minimize and zoom buttons show top left. Send a photo if they don't.
3. **The waiting call.** In the Library, Review › open the waiting WhatsApp call with Muneeb (17:47) › Keep. It uploads and transcribes; only Muneeb's side has sound (2.0.3's silent mic).
4. **Titles.** Pass if no automatic title is in Urdu letters, e.g. yesterday's 8:41 pm voice note now reads in Roman Urdu.
5. **Zoom in Chrome.** Join a test meeting with the phone as the other person; talk from both for 2 minutes; stop.
   - Pass if the transcript has both sides.
   - The lead checks the audio files at the same time: system.wav must not be empty.
6. **WhatsApp desktop.** A 1-minute call. Pass if both sides are heard and the after-call card works (the current card; the new one comes later).

**2. Phone (S25), about 25 minutes.** Check `mCallState=0` first.
1. **A WhatsApp call, 1 minute.**
   - The pill shows; the recording saves; the title is in Roman Urdu if you spoke Urdu.
   - Take a screenshot while the pill shows. On 2.0.5 it is still blocked; this confirms the fix that comes next.
2. **Settings › Microphone & audio › Reset.** Pass if SIM calls and the Phone app's recordings stay as they were (Calls & meetings page).
3. **Settings › Plan & usage.** The plan card and the meters. **Your data:** Export my data reaches "ready" or says why. **About:** version 2.0.5.
4. **Urdu notifications.** Set the app language to Urdu, then make a short call.
   - The recording notification reads right to left, with the name at the right.
   - At the Largest text size, its buttons are whole: `ہٹانا واپس لیں` may wrap but is never cut.
   - Set the language back.
5. **Widget.** Add the EchoLedger widget. In Urdu its lines sit at the right; at Largest its text is larger.
6. **Help › Phone check.** It runs in English and in Urdu; Copy report works.

**3. Windows laptop, about 20 minutes**
1. Install `EchoLedger_2.0.4_x64-setup.exe` over the old version; Help › About shows 2.0.4.
2. Record a WhatsApp or Zoom call with both sides for 1 minute. Pass if the transcript has both sides.
3. Make the window half the screen. Pass if nothing overlaps and the Library header actions stay on one row.

**4. Approvals, about 10 minutes**
1. **D12 Library rows.** Look at the pictures in `~/Desktop/EchoLedger-row-redesign`; reply "rows OK" or what to change.
2. **Fix list.** Send what failed above. The call choice batch (3 WhatsApp calls, a choice from the pill, At the start, a screenshot while recording) follows when those builds are in.

## Tonight 20:00–21:30 PKT (2026-11-17) — the v1.8.1 release candidate, about 85 minutes

Builds: the v1.8.1 release candidate from CI on `release/v1.8.1` (desktop 1.8.1; Android 1.8.1, code 18). Windows
first: it gates the release.

Update 19:15: the first 1.8.1 candidate froze on permissions (S0, found in the Mac session) and was withdrawn before
release. The fixed candidate adds the start-up stall fix and the permissions fix; the lead installs it on the Mac and
posts the Windows download link when its CI is green. Gate 3 and the decisions were settled this afternoon, so
items 2 and 3 below are done. On the Windows laptop, also check that Record and Live captions respond at once after
the app opens (the S0 path). The phone already has 1.8.1 (code 18); nothing to install there tonight.

**1. Windows laptop, about 50 minutes**
1. Install the 1.8.1 Windows build from CI over the old one; open it; note the version under Help › About.
2. Run the batch above ("Windows laptop — v1.8.1 S0/S6/S7"), steps 1–6, with the phone as the viewer.
3. DC-02, the recording clock after sleep:
   1. Start a recording with both sides heard (play a video for the other side) and let it run 1 minute.
   2. Sleep the laptop (Start › Power › Sleep) for 5 minutes, then wake it and sign in.
   3. Pass if the pill's time carries on from about 1:00; fail if it jumped to about 6:00. Note it if the recording
      stopped by itself.
   4. Press Capture screen now, then Bookmark; wait 30 seconds; stop.
   5. After upload, open the recording: pass if it is about 1:30 long with the screen and bookmark at about 1:00.
      Send a photo of the pill after waking and of the recording page.
4. DC-03 (needs the v2 test build from the main code, not the 1.8.1 installer; only if time is left and a 150 %
   second display is at hand). Start it from PowerShell so it keeps a log:
   `Start-Process "<path>\echoledger-desktop.exe" -RedirectStandardError "$env:TEMP\echoledger-log.txt"`
   1. Start a recording; drag the pill onto the 150 % display and press Mark at once: it answers at once, same size,
      not cut off. Drag it back and repeat.
   2. Drag the Live Assist card near the right edge of the 150 % display; quit and restart: pill and card open there,
      whole.
   3. With both on the 150 % display, unplug it: within about 5 s both move to the other display, whole, clickable.
   4. Make the 150 % display the primary and stop a recording: the after-call card docks bottom-right, whole.
   Send the log lines that start "[echoledger] placing" and "... was off screen at".

**2. Gate 3, about 15 minutes**
Look at the 24 sample screens (phone and desktop, light and dark, English and Urdu) in `docs/v2/gate3/` and choose the
look, or say what to change. Also look at the website (`docs/v2/web/site/`, Home, Pricing and Download, light and
dark) and approve the new Home lines: "written down" instead of "word for word", "transcripts you can trust" instead
of "accurate transcripts", "Urdu and Roman Urdu" instead of "Urdu, accurately".

**3. Decisions, 5 minutes**
Trash 48 hours or 30 days; the Live Assist plan numbers; who deploys the website (the lead with backup and rollback).
Also: the desktop app's words are English only (the phone is translated). Its Urdu pictures show the right-to-left
layout with English words. Translating the desktop interface into Urdu is a separate piece of work: now, or later?
Each Gate 3 folder (phone and desktop) has an index of its pictures.

**4. Mac, if time is left, about 15 minutes**
DC-03 on the Mac, and the MAC-32 Dock and quit check with the 1.8.1 Mac build. Later, in a v2 Mac build: Cmd+Z on an
Undo toast undoes the delete, and Edit › Undo still works in text fields (D-150) (the first self-signed build asks once
for the microphone, system audio and Screen Recording; allow them, then they stay).

**5. Mac, Live Translate (next free Mac slot, about 35 minutes)**
macOS 14.2 or later. The account signed in to EchoLedger on this Mac needs a plan with Live Translate, and its server
a Soniox key: Start asks the server for a 10-minute key. Check first in the installed app: the Live tab shows Live
Translate's app list, not "Not in your plan".

Build (about 10 minutes; ad-hoc signed, same app id as the installed EchoLedger, so it opens on the same account):
    cd /Applications/XAMPP/htdocs/echoledger
    git fetch origin
    git worktree add --detach /tmp/el-lt origin/v1.1
    cd /tmp/el-lt/desktop && npm install
    export PATH=/opt/homebrew/opt/rustup/bin:$PATH
    npx tauri build --bundles app --no-sign
    APP=/tmp/el-lt/desktop/src-tauri/target/release/bundle/macos/EchoLedger.app
    sh scripts/smoke-mac.sh "$APP" 180                   # expect "Smoke test passed"
Run:
    Quit the installed EchoLedger first (menu bar icon > Quit EchoLedger): a second EchoLedger hands over to it.
    "$APP/Contents/MacOS/echoledger-desktop" 2>&1 | tee ~/Desktop/echoledger-live-translate.log
    The build is not Developer-ID signed: macOS asks once for System Audio Recording on the first Start, and the
    keychain may ask for the signed-in account (Always Allow).

1. Chrome: play a YouTube video with speech, open Live Translate, pick "Google Chrome", start Watch. Subtitles appear
   bottom-centre within a few seconds; the video still sounds normal; no orange microphone dot. Stop: the window
   closes and Cmd+Shift+T reopens a closed tab in Chrome again.
2. While it runs: Cmd+Shift+T unlocks (drag it) and locks (clicks pass through). Cmd+Shift+H hides it: a small pill
   "Live Translate is listening" stays; Cmd+Shift+H brings it back. The menu bar menu says "Live Translate is
   listening · Stop" the whole time, and its Stop stops it.
3. Only the app picked: also play Music or Spotify; none of their words appear.
4. Safari: play a video; Safari is expected not to be listed. Note what the list shows.
5. Zoom: join zoom.us/test, play its audio, pick "zoom.us", start Game: captions top-centre.
6. A full-screen app or game: the window stays above it, in its full-screen Space too.
7. AirPods as output: repeat step 1; no microphone indicator at any point.
8. It ends on its own: while it runs on Chrome, quit Chrome. The overlay closes, the menu bar line goes, and the Live
   tab says it ended and why, with Close. Then pause a video and Start: either the app is no longer listed or the
   Live tab says the app stopped playing sound.
After: Quit from the menu bar, reopen the installed EchoLedger, then: git worktree remove /tmp/el-lt
Send the "[echoledger]" lines from ~/Desktop/echoledger-live-translate.log and any notice with an OSStatus number.

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

## Paused 2026-11-18 03:52 PKT

Owner devices now: Mac 2.0.1 (installed 2026-11-17, start-up check passed), phone S25 2.0.1 (code 21, `install -r`). Windows 1.8.2 is not yet tested on the owner's laptop. Next device step after resume: build Mac 2.0.2 from v1.1 once b-desktop-share-default is merged, run `sh scripts/smoke-mac.sh <app> 180`, then install it over 2.0.1. See docs/RESUME_HERE.md.

## Tonight 20:00–21:30 PKT (2026-11-18) — 2.0.2, about 75 minutes

Builds:
- **Mac:** 2.0.2 is already installed in /Applications. Its start-up check passed.
- **Phone:** the 2.0.2 APK (code 22) is built from release/v2.0.2. It is installed over Wi-Fi with `adb install -r`, so data is kept. The S25 needs Settings › Developer options › Wireless debugging on.
- **Windows:** the 2.0.2 installer is the `EchoLedger-windows-x64` artifact of the Desktop (Windows) run on release/v2.0.2 (Actions → Desktop (Windows) → release/v2.0.2 → Artifacts).

Gate 3: approved 2026-11-17, no images pending.

### 1. Mac (2.0.2), 30 min
1. **Screen share, 12 min.** Start a Zoom or Meet call and share the whole screen.
   - The EchoLedger main window shows in the share.
   - Tray › Hide from screen shares › 15 minutes: the main window disappears from the share, and the tray reads "Hidden until …".
   - Start Live Assist: the card is not in the share.
   - Tap the card's badge › Show in screen shares: the card appears. Close the card, start it again: it is hidden again.
   - Same with captions.
   - The one-time notice "EchoLedger now shows in screen shares unless you hide it" appeared once.
2. **Captions, 5 min.** Play a YouTube video in Chrome and start Live Captions. Words appear within about 1 second of speech.
3. **Chrome in Live Translate, 5 min (S2).** Start Live Translate and check Chrome is in the app list while a video plays. If it isn't, note the Chrome version.
4. **Black window / scrolling, 8 min (S1).** Open Library, a recording, Settings, Projects and Activity. Scroll each with the trackpad, then a mouse. Note any screen that goes black or won't scroll.

### 2. Phone S25 (2.0.2), 20 min
1. Turn on Wireless debugging; the lead installs 2.0.2 (2 min, no data cleared).
2. Open the app: classic, recordings all there, record a 20 s voice note and check it transcribes.
3. Settings › Appearance › Try the new design. The text uses the app's font, not the Samsung handwriting font.
4. Library: long-press a row for selection mode, then Move, Label, Delete with Undo; filter chips; Sort and group.
5. Open a recording: Show as Roman Urdu / اردو / English, Copy ▾, ⋮ › Export, player speed and skip silence.
6. Settings › Appearance › Switch back to classic. Classic is back.

### 3. Windows laptop (2.0.2), 25 min (this replaces the open 1.8.2 test)
1. Download the artifact and run the installer over the existing install.
2. It starts once with no crash; a second launch while it starts doesn't crash. Sign in is kept.
3. Record 1 minute from the microphone, stop, and check it transcribes.
4. Live Captions on a YouTube video: words within about 1 second.
5. Share the screen in Meet in Chrome. The main window shows; Live Assist card and captions don't. Hide the main window for 15 minutes from the tray.

The phone and Windows parts above were not run; they are carried into the next section.

## Next free slots (from 2026-11-21) — Mac 2.0.3, phone 2.0.2, Windows 2.0.2, about 75 minutes

Each part stands alone: run whichever device is free. Write "pass" or what you saw next to each step; a photo helps
for anything that looks wrong. The lead installs the builds; you only use the apps.

Builds:
- **Mac 2.0.3** (the half-screen fixes): built from release/v2.0.3, start-up check passed
  (`sh desktop/scripts/smoke-mac.sh <app> 180`), installed over 2.0.2 in /Applications.
- **Phone 2.0.2** (code 22, release/v2.0.2): installed over Wi-Fi with `adb install -r` (data kept). Turn on
  Settings › Developer options › Wireless debugging first. Not on a call.
- **Windows 2.0.2**: the `EchoLedger-windows-x64` artifact of the Desktop (Windows) run on release/v2.0.2
  (Actions → Desktop (Windows) → release/v2.0.2 → Artifacts).

### 1. Mac 2.0.3 — the half-screen window, 25 min
Before: Settings › Appearance in light mode, the app in English. At least 10 recordings in the Library, one with an
Urdu title (rename one to "ادائیگی کا منصوبہ اور قرض کی واپسی" if none has).

**A. Half screen (720–756 px wide), 10 min**
1. Put the EchoLedger window on the left half of the screen: menu bar › Window › Move & Resize › Left (or drag it to
   the left edge until the half outline shows). The sidebar becomes a column of icons.
2. Drag the window's right edge to the left as far as it goes. Pass: it stops (about half of a 13-inch screen) and the
   top bar still shows the team name, search, "Calls: Ready", +, the bell, ? and your picture, none of them cut.
3. Library: open a recording. Pass: it fills the page with **Back** at the top left; the list is gone.
4. Scroll the recording to its end with the trackpad. Pass: the transcript's last line, and the Speakers, History and
   Review tabs above it, can all be seen above the player bar; nothing shows through the player.
5. Press Esc. Pass: the list is back, with the recording you had open highlighted. Open it again and press Back:
   same result.
6. In the list, press J five times, then K twice. Pass: the highlighted row is always fully in sight, never under the
   "Today" / "Yesterday" / "This week" heading.
7. Scroll the list to its end. Pass: the last recording sits fully above the player bar (play any recording first so
   the bar shows).
8. Open a recording again. Pass: "Project" and "Language" sit beside their values ("Inbox", "Auto (English / Urdu /
   Arabic)" or shorter), never on top of them, and Re-transcribe fits on the same row or the next.
9. The Urdu-titled recording in the list. Pass: if the title is cut, the "…" is on its left (its end), not its right.

**B. Other pages at half screen, 8 min**
1. Projects, then open a project. Pass: its name, Share, Copy for AI, the filing rules and Edit rules are all visible,
   nothing on top of anything.
2. Shared (the sidebar's share icon) › With me › Open. Pass: the recording opens with Back; Esc returns to the cards.
3. Settings › Calls & meetings, then Microphone & audio, Captions, Voice notes & dictation, Custom words. Pass: every
   drop-down shows its whole value or a clean "…", nothing written over it.
4. Library › the table icon above the list (its tooltip says "Table"), open a recording, press Esc. Pass: the
   recording closes and the row you opened stays highlighted.

**C. Full screen and dark, 7 min**
1. Window › Fill (full screen width). Pass: the list and the recording sit side by side, as in 2.0.2.
2. Settings › Appearance › Dark, then repeat A3–A7 at half screen. Pass: as in light; the player bar is solid.
3. Back to your usual appearance. Note anything else that looks off, with a photo.

### 2. Phone S25 (2.0.2), 20 min
1. Turn on Wireless debugging; the lead installs 2.0.2 (2 min, no data cleared).
2. Open the app: classic, recordings all there; record a 20 s voice note and check it transcribes.
3. Settings › Appearance › Try the new design. Pass: the text uses the app's font, not the Samsung handwriting font.
4. Library: long-press a row for selection mode, then Move, Label, Delete with Undo; filter chips; Sort and group.
5. Open a recording: Show as Roman Urdu / اردو / English, Copy ▾, ⋮ › Export, player speed and skip silence.
6. Settings › Appearance › Switch back to classic. Pass: classic is back, nothing lost.

### 3. Windows laptop (2.0.2), 25 min
1. Download the artifact and run the installer over the existing install.
2. It starts once with no crash; a second launch while it starts doesn't crash. Sign-in is kept.
3. Record 1 minute from the microphone, stop, and check it transcribes.
4. Live Captions on a YouTube video: words within about 1 second of speech.
5. Share the screen in Meet in Chrome. Pass: the main window shows; the Live Assist card and captions don't. Then
   tray › Hide from screen shares › 15 minutes: the main window leaves the share.
6. Half the screen (Win + ←): open a recording and scroll it to the end. Note whether the transcript can be reached
   (2.0.2 is before the half-screen fix; this is the baseline for 2.0.3 on Windows).

After each part, the lead turns every "fail" into a bug with an ID (EL-Fix triages within the hour).

### F6/U15 — Send to WhatsApp, Text and voice (the builds with F6 and U15), Mac 10 min, Windows 15 min, phone 15 min
Use a recording with audio and a transcript, in the classic design. Send to a test chat (your own number or a test
group), never to a client. Before you start, note what is in Downloads.

**Mac, WhatsApp installed**
1. Library › open the recording › Send to WhatsApp › Text and voice. Pass: only that button changes to "Sending…";
   Text only and Link look as before, and clicking them does nothing while it runs.
2. WhatsApp 26.40 for Mac has no share extension, so expect the fallback: WhatsApp opens with the text in a new
   message, and the sheet says "Saved in Downloads: …" and "The voice file is copied: in the chat, press ⌘V to attach
   it." Choose the chat, press ⌘V. Pass: the voice file is attached (with the text), and sends and plays.
3. Close the sheet and do step 1 again. Pass: Downloads has the same one file, no "(2)" copy.
4. If a WhatsApp version with a share extension is installed (WhatsApp appears under Finder › right-click a file ›
   Share): step 1 instead shows WhatsApp's share on the window with the text and the file. Choose the chat and send.
   Pass: the sheet closes with "Text and voice sent to WhatsApp", and nothing new is in Downloads. Repeat and close the
   share with Cancel: the sheet stays open, nothing is said, nothing new is in Downloads.

**Mac, WhatsApp not installed** (or on another Mac without it)
5. Same click. Pass: the browser opens WhatsApp Web with the text; the sheet says to press ⌘V. In the chat, ⌘V
   attaches the file.

**Windows 11, WhatsApp installed (Microsoft Store app)**
6. Same click. Pass: the Windows Share window opens over EchoLedger titled "Send to WhatsApp"; WhatsApp is in its
   list. Choose WhatsApp, then the chat, and send. Pass: the voice and the text arrive; the sheet closes with "Text
   and voice sent to WhatsApp"; nothing new is in Downloads.
7. Repeat and close the Share window without choosing (Esc or click outside). Pass: the sheet stays open, quietly,
   within a second or two; nothing new is in Downloads.
8. Repeat and choose another app (Mail, Nearby sharing). Pass: the sheet closes with "Text and voice shared" (not
   "sent to WhatsApp").

**Windows 10 (build below 20348), WhatsApp installed**
9. Same click. Pass: the Share window opens, and at once the sheet says "The share is open: finish sending there."
   (no "sent" claim, no waiting). Send through WhatsApp, then Done.
10. Repeat, close the Share window, then click "Open WhatsApp with the text instead". Pass: the fallback of step 2
    (Ctrl+V attaches the file).

**Windows, WhatsApp not installed**
11. Same click. Pass: no Share window; the browser opens WhatsApp Web with the text; the sheet says "press Ctrl+V to
    attach it". In the chat, Ctrl+V attaches the file. Doing it again leaves one file in Downloads, no "(2)".

**Both, a long transcript** (an hour-long recording)
12. In the fallback, the sheet also says "The message has the start of the text" with "Copy the whole text". Attach
    the voice first (⌘V / Ctrl+V), then click Copy the whole text and paste it. Pass: the whole text arrives.

**Phone S25 (U15)** — phone rules above: install with `pm install -r` only, never uninstall, never clear data; check
`mCallState=0` first. Use a recording with audio and a transcript; send to a test chat only.
13. Classic design: open the recording › Send to WhatsApp › Text and voice. Pass: only that button shows progress
    (the other two look as before). WhatsApp opens; pick the test chat. Pass: the chat preview shows the voice
    attached, with the transcript as its caption. Tap Send. Pass: the voice arrives and plays.
14. New design (Settings › Appearance › Try the new design): open the same recording › Share › Send to WhatsApp ›
    Text and voice. Pass: the sheet stays open until WhatsApp opens; then as in step 13. Switch back to classic after.
15. Run steps 13–14 twice: once on a phone with WhatsApp, once on a phone with only WhatsApp Business (a test phone
    that has only Business; never uninstall WhatsApp on the S25 for this). Pass: each time the installed app opens,
    with the voice and the caption.
16. Turn on Airplane mode, then Text and voice again. Pass: the app says "The audio could not be downloaded…" and
    WhatsApp does not open. Turn Airplane mode off.
