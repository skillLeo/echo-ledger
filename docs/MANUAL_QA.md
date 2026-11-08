# Manual QA — one ordered checklist

Automated tests cover the logic of every app. This list covers what only a real PC, phone, call and browser can
show. Follow it from top to bottom. It takes about **1½ to 2 hours**.

- Every line is one action and one expected result. Tick the box when you see exactly the expected result.
- Write down the device, the build and the step number of anything that fails. A failure blocks the release unless
  you decide to waive it.
- The last column is the check's old ID, so the older notes in `docs/HANDOFF.md` and `PLAN_V1_1.md` still match. The
  old file used V-D1 to V-D11 and V-A1 to V-A4 twice: "§17" marks the look-and-feel checks, the others are the
  recording checks.
- Checks that take long, need special hardware or only matter on release day are in **Appendix A to C**. They are
  not part of the 2-hour run.

## What you need

| Item | Notes |
|---|---|
| The server | Open at `https://…/admin`, with an owner login and (for the real transcript steps) an AssemblyAI key. |
| A Windows 11 PC | The current installer, a microphone and a headset. |
| An Android 14+ phone | The current APK, WhatsApp, and Shizuku set up (or a computer with ADB). |
| A second person | A second phone or a friend to call, for the call steps. |
| Browsers | Chrome (for Google Meet) and a private window. |
| A Google Meet link | And a WhatsApp contact. |

---

## Part 1 — Web console (about 20 minutes)

| ☐ | # | Do this | You should see | Ref |
|---|---|---|---|---|
| ☐ | 1 | Open `/admin/login` and sign in with the owner's e-mail and password. | The dashboard opens. "Finish setting up" lists two-factor, provider key, certificate pins, first device and retention. | S-8 |
| ☐ | 2 | Click the "Provider key" step. | **API Credentials** opens. | S-8 |
| ☐ | 3 | Enter the AssemblyAI key and the webhook secret (use Generate), save, then reload the page. | Only "configured ••••last4" is shown. View the page source: the key does not appear. | S-03 |
| ☐ | 4 | Open **Security** and add a passkey (Windows Hello, Touch ID or a phone). | The passkey is listed. | S-W1 |
| ☐ | 5 | Sign out, then use **Sign in with a passkey** on the login page. | You are signed in without the password or the two-step code. | S-W1 |
| ☐ | 6 | On **Security**, remove the passkey, sign out and try the passkey again. | The sign-in is refused with a plain message. The password still works. | S-W2 |
| ☐ | 7 | Open **Settings**. | Eight tabs: General, Recording, Captions, Dictation, Privacy & security, Devices, Storage, About. | S-5 |
| ☐ | 8 | Type "budget" in the settings search box. | The Storage tab opens and sections without a match are hidden. | S-6 |
| ☐ | 9 | On **Settings → Storage**, set retention to 7 days and save, then use **Export settings** from the page menu. | A JSON file downloads. It has no API key or secret. | S-7 |
| ☐ | 10 | Change retention to 30 days, save, then **Import settings** with the file from step 9. | "Settings imported" and retention shows 7 again. Set it back to 30 afterwards. | S-7 |
| ☐ | 11 | Open **Workspace**. | A workspace named "Personal" with you as owner. | W-1 |
| ☐ | 12 | Click **Add member**, enter a new e-mail, then sign in as that person in a private window. | A one-time password was shown once. The member sees the same library, cannot open Settings or API credentials, and has no Add member button. | W-5 |
| ☐ | 13 | As the member, try to delete one of the owner's recordings. | The console offers no delete for it. | W-6 |
| ☐ | 14 | As the owner, remove the member. | The member is gone and their devices are unpaired. | W-7 |
| ☐ | 15 | Open **Usage**. | The current month shows with minutes, cost, storage and devices. Nothing is broken. | W-8 |
| ☐ | 16 | Open **Profile → Devices** and click **Sign in a device**. | A code and a QR code appear. Keep this page open for Parts 2 and 3. | — |

---

## Part 2 — Windows PC (about 45 minutes)

### Install, pair, first run

| ☐ | # | Do this | You should see | Ref |
|---|---|---|---|---|
| ☐ | 17 | Run the installer on the PC. | The app starts in the tray. If the installer is not signed yet, Windows says so (known, see Appendix C). | W-01 |
| ☐ | 18 | Start EchoLedger a second time. | The first window is brought forward. There is no second tray icon. | W-02 |
| ☐ | 19 | In the setup guide, continue past Permissions, then pair with the server address and the code from step 16. | The library appears. | O-1, W-03 |
| ☐ | 20 | Open **Credential Manager → Windows Credentials** and look for "EchoLedger". | The device key is there. No key is stored in a file under `%APPDATA%`. | W-03 |
| ☐ | 21 | In the guide, run the 10-second test while you speak and play a sound. | The test says what it heard on both sides. | O-2 |
| ☐ | 22 | Finish the guide with the consent tick. | The main window opens. The guide never returns. | O-2 |
| ☐ | 23 | Open the Library. | "No recordings yet" shows, with no error message. | — |
| ☐ | 24 | Open **Settings → Recording** and turn on **Auto-record calls**. | The switch is on. The app list shows WhatsApp, Zoom, Teams and Meet. | C-D1 |

### Calls, captions and controls

| ☐ | # | Do this | You should see | Ref |
|---|---|---|---|---|
| ☐ | 25 | Use voice input in ChatGPT, Claude and Gemini for about 30 seconds each. | Nothing is recorded. The Record screen says "Ignored: never record". | V-D1 |
| ☐ | 26 | Record a 20-second voice message in WhatsApp Desktop. | No recording starts. The Record screen says "Playing audio ✗ · Ignored: dictation". | V-D2 |
| ☐ | 27 | Start a Google Meet call in Chrome with your second person. Keep typing in Notepad. | Recording starts within about 3 seconds. The pill appears at the top without taking the focus, and your typing is not interrupted. | V-D3, V-D7 |
| ☐ | 28 | Click a button on the pill straight away. | It works on the first click. Clicks next to the pill reach the window underneath. | V-D7 |
| ☐ | 29 | In the Meet call, open a ChatGPT tab for a minute, then come back. | The recording continues, still labelled Google Meet. | V-D4 |
| ☐ | 30 | Press **Ctrl+Shift+Space**. | Live captions appear: "Me" and "Client" in different colours. Notepad keeps the focus. | W-08 |
| ☐ | 31 | Let many long sentences come in and scroll the captions up. | The header stays inside the card, long text wraps, and "Jump to live" returns to the newest line. | V-D18 |
| ☐ | 32 | Share your screen in Meet (or Zoom). | The captions, the pill and the review card cannot be seen in the shared view. | W-09, V-D15 |
| ☐ | 33 | Press **Ctrl+Shift+B**, then click **Mark** on the pill and pick "Budget". | "Bookmarked m:ss" shows under the pill with the tag presets and Undo. | C-D2 |
| ☐ | 34 | Click **Pause**, wait 30 seconds, click **Resume**. | The timer freezes and the dot turns amber while paused. Captions stop. Recording continues after Resume. | V-D8, C-D4 |
| ☐ | 35 | Ask the other side to stay silent for 30 seconds. | The pill says "Not hearing the other side for 30 s". It goes away when they speak. | C-D8 |
| ☐ | 36 | Press **Ctrl+Alt+Shift+C**, then paste into Notepad. | The last 2 minutes of captions, labelled Me and Client. | W-13 |
| ☐ | 37 | Click **Stop**. | With **After a call: Keep automatically** (the default since v1.5), a small "Saved · <title> · m:ss · Undo" toast shows near the tray for 6 s without taking the focus. With **Ask me**, a small chip with the title, ✓ and ✕ instead. | V-D12 |
| ☐ | 38 | Let the toast go (or click ✓ on the Ask me chip). | The recording uploads. The Library shows it as uploading, then verifying. | V-D19 |
| ☐ | 39 | Wait a few minutes. | A Windows notification says the transcript is ready. Clicking it opens that recording. | N-2 |

### After a call, skipped calls and waiting (v1.5)

| ☐ | # | Do this | You should see | Ref |
|---|---|---|---|---|
| ☐ | 40 | Press **Ctrl+Alt+Shift+R**, speak for 20 seconds, press it again, then click **Undo** on the Saved toast. | The recording is deleted; nothing is left in the Library and nothing is uploaded. | — |
| ☐ | 41 | Settings → Calls & meetings → **After a call: Ask me**; record 20 seconds; click **✕** on the chip. | The chip closes. Nothing is left in the Library and nothing is uploaded. | V-D13 |
| ☐ | 42 | With Ask me, record another 20 seconds and leave the chip alone. | After 10 s (the thin line runs out) the recording is kept and uploads. | V-D13 |
| ☐ | 43 | With Ask me, record 20 seconds, press Stop, quit EchoLedger from the tray at once and start it again. | The recording is listed under "N calls waiting · Review" (or was kept if its 10 s had passed). | V-D14 |
| ☐ | 44 | Click **Cancel** on a new recording and answer "Yes". | It is discarded with no review card. "No" restores the buttons. | V-D9 |
| ☐ | 45 | Watch the tray icon while idle, recording, paused and uploading. | The icon and tooltip change for each state. | V-D17 |
| ☐ | 45a | Join a call automatically detected, leave it after 5 seconds. | Nothing asks; Activity shows "Skipped 1 short call"; **Show → Restore** keeps it. | v1.5 |
| ☐ | 45b | Record with a Bluetooth headset as the microphone. | "Accuracy is lower with Bluetooth mics" on the Record page and under the pill. | v1.5 |

### Library, transcript and search

| ☐ | # | Do this | You should see | Ref |
|---|---|---|---|---|
| ☐ | 46 | Open the Meet recording in the Library when its transcript is ready. | Speaker blocks with Me and Client. The bookmark from step 33 is on the timeline. | C-D3 |
| ☐ | 47 | Click a transcript line. | The audio plays from that moment and the current line is highlighted. | T-W1 |
| ☐ | 48 | Type two letters of a spoken word in the Library search box. | "In transcripts" lists the line. Clicking it opens the recording at that moment. | T-D1 |
| ☐ | 49 | Edit one line, use **Find and replace**, then open **History**. | The edit is saved. History lists the changes and **Restore original** brings back the engine's text. | T-D2 |
| ☐ | 50 | Choose **Export → Word**. | A .docx lands in Downloads and opens. | T-D2 |
| ☐ | 51 | Choose **Print or save as PDF**. | The print page opens in the browser and saves as a PDF. | T-D2 |
| ☐ | 52 | Press **Ctrl+K**, type "settings", then type a word from the transcript. | "Go to Settings" opens Settings. The word opens the recording at that moment. | T-D4 |
| ☐ | 53 | Pin the recording, choose a project (or **New project…**) and add a label. | The star and label show on the card at once. | O-D2 |
| ☐ | 54 | Open **Filters** and tick the "Show" boxes one at a time, then the "Recorded on" boxes; also try the client and label lists. | Each lists only its recordings, the counts match, ticked boxes add up inside a group, and the menu closes with Esc, the X button or a click outside. | O-D1 |
| ☐ | 55 | Delete a recording, open **Recently deleted** and restore it. | It comes back with its audio and transcript. | R-2 |

### Dictation

| ☐ | # | Do this | You should see | Ref |
|---|---|---|---|---|
| ☐ | 56 | In Notepad, press **Ctrl+Shift+D**, say "hello team new paragraph thanks", press it again. | A start sound, a moving bar, a stop sound. "Hello team." and "Thanks." appear on separate paragraphs. | DI-D1 |
| ☐ | 57 | In **Settings → Dictation**, choose **Hold to talk**, hold the shortcut, speak, let go. | The text is inserted when you let go. A very short tap inserts nothing. | DI-D2 |
| ☐ | 58 | Add the replacement "echo ledger → EchoLedger" and dictate it. | It is written as "EchoLedger". | DI-D4 |
| ☐ | 59 | Open the Library and the tray menu. | Dictation made no recording, no library item and no Keep / Discard card. | DI-D1 |
| ☐ | 60 | Disconnect the network and press the dictation shortcut. | The bar shows why for a few seconds, then goes away. | §17 V-D10 |

### Look, lock and memory

| ☐ | # | Do this | You should see | Ref |
|---|---|---|---|---|
| ☐ | 61 | Switch the theme between dark and light while the pill is visible. | The main window, the title bar and the pill all follow. On Windows 11 the sidebar shows Mica. | §17 V-D1, V-D4 |
| ☐ | 62 | Resize the main window to its smallest width, then wider. | No horizontal scrollbar. The library stacks when narrow and sits side by side when wide. | V-D23 |
| ☐ | 63 | In **Settings → Privacy & security**, set a PIN, turn on **App lock**, choose "After 1 minute" and wait. | "EchoLedger is locked" shows and Windows Hello is offered at once. | S-D1 |
| ☐ | 64 | While locked, start a recording from the tray. | Recording and captions work. The library stays hidden until you unlock. | S-D2 |
| ☐ | 65 | Enter a wrong PIN five times. | The sixth try is refused with "Try again in 30 seconds". | S-D3 |
| ☐ | 66 | Close the main window with X, then open it again from the tray. | It opens on the same page. Task Manager shows fewer `msedgewebview2.exe` while it is closed. | §17 V-D11 |
| ☐ | 67 | Close the main window and wait 30 seconds. In Task Manager add up the private memory of EchoLedger and its `msedgewebview2.exe` processes. | At most 150 MB and under 1 % CPU. | P-1 |

---

## Part 3 — Android phone (about 30 minutes)

### Install, pair, first run

| ☐ | # | Do this | You should see | Ref |
|---|---|---|---|---|
| ☐ | 68 | Install the APK. | The install finishes and the app opens to the setup guide. | A-01 |
| ☐ | 69 | In the guide, allow the microphone, notifications, display over other apps and battery. | Each row says "Allowed" when you return. | O-4 |
| ☐ | 70 | Tap **Scan QR code** and scan any other QR code first. | "That isn't an EchoLedger sign-in code" and nothing is paired. | A-02 |
| ☐ | 71 | Scan the QR code on **Profile → Devices → Sign in a device** (new code if needed). | The phone pairs and the guide moves on by itself. | O-5 |
| ☐ | 72 | Run the 10-second test while you speak. | The meter moves and the result says the microphone works. | O-5 |
| ☐ | 73 | Finish the guide. | The Library shows the recordings from Windows with Phone, Desktop and Web labels. | A-02 |
| ☐ | 74 | Open **Settings → Devices → This phone → Setup guide** and connect through Shizuku (or paste the ADB command on a computer). | The helper status turns green. | A-10, A-11 |

### Calls

| ☐ | # | Do this | You should see | Ref |
|---|---|---|---|---|
| ☐ | 75 | On the Record tab, turn on **Auto-record WhatsApp calls**, lock the phone, and take a WhatsApp voice call for 1 minute. | You hear the other side normally. A "Recording call" notification shows. No question appears during the call. | A-12 |
| ☐ | 76 | While in the call, use **Pause**, **Resume** and **Mark** in the notification. | The title says "Paused ·" while paused. Mark shows "Bookmarked m:ss". | C-A2 |
| ☐ | 77 | Hang up. | A heads-up "Save this recording?" shows the app, the length, the client and the first caption line. | V-A6 |
| ☐ | 78 | Tap **Keep**. | It uploads. The recording appears on Windows and the web as Phone · WhatsApp, with Me and Client apart. | A-13 |
| ☐ | 79 | Take a second call, hang up, and tap **Discard**. | It disappears from the phone. Nothing is uploaded. | A-14 |
| ☐ | 80 | Take a third call and let the question time out. | After 60 seconds Keep or Discard is chosen, as set. It never "keeps waiting". | V-A6 |
| ☐ | 80a | End a WhatsApp call recording with the phone unlocked and WhatsApp still on screen. | A small card appears at the bottom (about 90 % wide): the WhatsApp icon, "Save this call recording?", the length, **Keep** and **Discard**. It does not take the keyboard focus and the screen above it still scrolls and taps. | popup |
| ☐ | 80b | Leave the card alone for 10 seconds. | It goes away. The recording is still in the Record tab under "Waiting for your decision": nothing is uploaded and no transcript is requested (Usage shows no new final minutes). | popup |
| ☐ | 80c | End another call and tap **Keep** on the card, then another and tap **Discard**. Also close WhatsApp while the card is showing. | Keep uploads it and a transcript follows. Discard removes it from the phone with no upload and no cost. The card stays when WhatsApp closes. The same Keep / Discard work from the Record tab list and the notification, and closing the card from one place closes it everywhere. | popup |
| ☐ | 80d | In Android settings turn off "Display over other apps" for EchoLedger, then end a call. | No card; the heads-up "Save this recording?" notification shows instead with Keep and Discard. | popup |
| ☐ | 80e | Open **Settings → After a call**. | "Keep waiting (default)" is selected on a fresh install, and stays selected until you pick another option. | popup |
| ☐ | 80f | Install this version, then **Setup → Call capture**. | If the helper was started by an older version it says so in orange; tap **Connect via Shizuku** (or run the command again) and it turns green. | capture |
| ☐ | 80g | **Setup → Call capture → Test call capture**, speak for a few seconds. | After about 10 seconds: ✓ helper, ✓ WhatsApp found, ✓ your voice, – other side (only during a call), ✓ saving, and "Call capture works". | capture |
| ☐ | 80h | During a normal **WhatsApp** call, run the test again (from the notification shade). | ✓ for the other side too: "both sides were heard". Then end the call and Keep: the recording has sound on Me and Client. | capture |
| ☐ | 80i | Same with a **WhatsApp Business** call. | The recording is labelled WhatsApp Business and has both sides. | capture |
| ☐ | 80j | Stop the helper (stop Shizuku, or restart the phone without starting it), keep auto-record on, start a WhatsApp call. | The notification says "Call capture isn't ready — tap to fix" within a few seconds; tapping it opens Setup. Nothing silent is uploaded. | capture |
| ☐ | 80k | With **CallVault** (or another call recorder) installed and recording calls, run the test during a call. | If sound is missing, the result says to turn off other call recorders. Turn CallVault's recording off and the test passes. | capture |
| ☐ | 80l | Open an old recording that says "No audio". | A grey dot and "No audio" (not red "Failed"), no player and no Share link; **Run capture test** opens Setup and **Delete** removes it in one tap. The console shows the same grey "No audio". | capture |
| ☐ | 80m | **Setup → Call capture → Copy diagnostics**, paste it into a note. | Phone model, Android version, app and helper builds and the capture log (call started, capture start, sound arriving, recording saved). No names, numbers or transcript text. | capture |
| ☐ | 81 | Let a WhatsApp call ring out, or reject it. | Nothing is saved and nothing is asked. | A-16 |
| ☐ | 82 | Take a call, tap the pill, **Show captions**. | A caption panel opens (at most a third of the screen) with Me and Client; it scrolls, shows **Jump to latest** when scrolled back, moves by its title bar and closes with its × while the recording carries on. | A-17 |
| ☐ | 83 | Stay on the Record tab during a Keep that is uploading. | The upload card updates by itself, without leaving the tab. | A6.M12 |

### Calls on Android 16/17 (v1.3 device run, done together over adb)

Run with the phone on USB or Wireless debugging and a second phone to call from. Before the run: `adb shell run-as com.echoledger.mobile touch files/debug-keep-plain` (debug builds keep a plain WAV of each recording in `files/debug-recordings/` for this check; release builds never do). After each call: `adb exec-out run-as com.echoledger.mobile cat files/debug-recordings/<id>.wav > call.wav` and measure each channel (RMS / peak / seconds of speech, L = me, R = the other side); the capture log (`run-as … tail files/capture-log.txt`) has a `levels` line too. "Both sides" means both channels above -45 dBFS RMS during speech and a transcript with Me and Client lines.

| ☐ | # | Do this | You should see | Ref |
|---|---|---|---|---|
| ☐ | 84a | Install the build, open the app once, wait 10 s. `adb shell dumpsys audio \| grep registerAudioPolicy \| tail -2` | One `registerAudioPolicy … u/pid:2000/<helper pid>` and **no** `unregisterAudioPolicy` after it: the voice-call mix is armed before any call. | C3-01 |
| ☐ | 84b | Setup → Call capture → **Test the other side** (or `app_process … HelperMain --self-test`). | "ok sink=shell rate=48000 … peak=1200": the far-side path works without a call. | C3-02 |
| ☐ | 84c | WhatsApp voice call, 1 minute, both people talk. Hang up, **Keep**. | Nothing while it rings. When it connects: the pill "● 00:01" at the right edge, top third. WAV: both channels with speech; transcript has Me and Client. | C3-03 |
| ☐ | 84d | Same with **WhatsApp Business**, then a **Google Meet** app call, then a **Zoom** app meeting. | Each is detected and labelled with its app (WhatsApp Business, Google Meet, Zoom) and has both sides. A game with voice chat starts nothing. | C3-04 |
| ☐ | 84e | During a call: tap the pill → **Pause**, wait 10 s, **Resume**; **Shrink to a dot**; drag the dot to the left edge and let go. | The paused 10 s are not in the file. The dot snaps to the left edge and comes back there on the next call. | C3-05 |
| ☐ | 84f | Tap the pill → **Discard now**, then **Undo** within 5 s; repeat and let the 5 s run out. | Undo keeps recording as before. After 5 s the pill goes, nothing is uploaded, nothing appears in the Library or on the server. | C3-06 |
| ☐ | 84g | Tap the pill → **Show captions**, then close them with ×; then **Hide controls**. | Captions open in their own panel and close without stopping the recording; hidden controls come back from the notification's Live view (**Show controls**). | C3-07 |
| ☐ | 84h | Pull down the notification during a call. | "Recording call with <name>" with a running clock and Pause · Stop · Captions · Discard. A tap opens the Record tab's Live view with the full transcript and the same buttons. | C3-08 |
| ☐ | 84i | Settings → Record phone calls = Always. Receive a SIM call, then make one. | Both recorded through the helper (phone line), or, when the line gives silence, the notice says so and the Phone app's own recording is imported after the call (needs its **Auto record calls** on). | C3-09 |
| ☐ | 84j | During a WhatsApp recording, take a SIM call, hang it up, carry on in WhatsApp. | The pill says "On hold" during the SIM call; the WhatsApp recording continues in the same file afterwards. | C3-10 |
| ☐ | 84k | Record a voice note from the Record tab with captions shown. | The same pill and caption panel; Stop uploads it. | C3-11 |
| ☐ | 84l | Reboot the phone, start Shizuku, wait 30 s, take a WhatsApp call. | 84a holds again (armed after the reboot) and the call has both sides. | C3-12 |
| ☐ | 84m | Call with a third app that is not in the list (Settings → Auto-record apps → add its package name), then remove it again. | Recorded as "Other" while listed; nothing after removing it. | C3-13 |

### Live Translate (v1.3, Phase B; needs Soniox credit for text)

Before: Shizuku running (helper connected), Display over other apps allowed, Soniox credit on the account. Capture
checks (L1-L3) work without credit; the box then shows "Soniox has no credit left…".

| ☐ | # | Do this | You should see | Ref |
|---|---|---|---|---|
| ☐ | L1 | You → General → Help & diagnostics → **Audio probe**, with PUBG Mobile (squad voice chat on), then CODM, YouTube, Chrome, VLC and Netflix playing in turn. **Capture 5 s** on each, **Play**, then **Copy findings**. | Each app with its usage (Voice chat / Game / Media), content and capture policy. Voice chat captured with speech; Netflix "This app doesn't allow live captions". Paste the findings table in the report (B0). | B0-1 |
| ☐ | L2 | Game translate → choose PUBG Mobile, **Start with the game** on. Close PUBG, wait 10 s, open it, join squad voice. | Game translate starts by itself when voice chat plays (`translate` lines in the capture log); no call recording, no Keep/Discard. Closing the game stops it within a minute. | B3-1 |
| ☐ | L3 | In a match with the box showing: tap, swipe and shoot through the box area for a minute. | Every touch reaches the game; the box never takes focus, no sound, no vibration. | B2-1 |
| ☐ | L4 | Tap the grip dot → drag the box to the left edge and resize it from the corner → leave it 5 s. Rotate the phone. | Edit shows a dashed outline; it snaps to the edge and locks itself after 5 s. Each orientation keeps its own spot. | B2-2 |
| ☐ | L5 | Long-press the grip: Pause, Hide, Text − / +, Opacity − / +, Original, Language, Stop. | Each acts at once; Hide brings the box back after 5 minutes or from the notification. | B2-3 |
| ☐ | L6 | FPS: Game Booster's monitor (or `adb shell dumpsys SurfaceFlinger --timestats`) 2 minutes without and 2 minutes with the box. | No measurable drop at 120 Hz. | B8-1 |
| ☐ | L7 | Arabic teammates speak (or the page's **Test translate**). | English text within about 1.5 s after a sentence on Fastest; words still changing are lighter. | B1-1 |
| ☐ | L8 | Watch translate → YouTube, English video, Subtitles in Urdu. Pause the video for 10 s, play again. | Urdu subtitles in Nastaliq at the bottom, white with an outline; they fade while the video is paused. | B4-1 |
| ☐ | L9 | Netflix with Watch translate. | "This app doesn't allow live captions." in the notification; nothing is captured. | B4-2 |
| ☐ | L10 | During Game translate switch Wi-Fi off and on. | "No connection — retrying" once, then text again by itself. | B1-2 |
| ☐ | L11 | 30-minute session. | The phone stays usable; battery and heat noted; usage appears in the console Usage page under Live Translate. | B8-2 |
| ☐ | L12 | Stop the helper (Shizuku off), then Start Game translate. | Android's own "share sound" dialog; the page says voice chat may be missing. | B0-4 |

### Feature parity after the redesign (C10)

Every action of the old screens is still reachable: record a voice note (centre button, long-press) · import a file ·
calls auto-record on/off · Keep / Discard (card, notification, Calls view) · pause, captions, discard on the pill ·
library search, filters, select, move, label, delete, restore (⋮ → Recently deleted) · pin · keep forever ·
open a recording, play, ±10 s, speed, skip silences · copy, copy with timestamps, Copy for AI, export, share link ·
re-transcribe as · views (Roman Urdu, اردو, English, Original) · rename speakers (console) · clients and keyterms ·
app lock · appearance and phone font · dictation keyboard settings · storage and retention · devices and sign out ·
export / import settings · capture test, test the other side, copy diagnostics.

| ☐ | # | Do this | You should see | Ref |
|---|---|---|---|---|
| ☐ | P1 | Walk the list above on the phone, one by one. | Each is reachable in at most three taps from its tab. | C10-1 |

### Library, transcript, voice notes

| ☐ | # | Do this | You should see | Ref |
|---|---|---|---|---|
| ☐ | 84 | Open a finished recording, choose 1.5× and **Skip silence**, and follow the highlighted word. | Speed and silence skipping work. The speaker name shows once per turn. | T-A1 |
| ☐ | 85 | Tap a bookmark chip. | The audio jumps to that moment. | C-A3 |
| ☐ | 86 | Type a spoken word in the Library search. | "In transcripts" shows the line. Tapping it plays from there. | T-A3 |
| ☐ | 87 | Choose **Export → Word**, then **Print**. | Word opens in a document app and the print dialog saves a PDF with correct shaping. | T-A2 |
| ☐ | 88 | Make a share link with a password and open it on another device. | The password page appears, then the transcript. | T-A2 |
| ☐ | 89 | In WhatsApp, share a voice note to EchoLedger. | It is imported as a voice note and uploaded. | A-40 |
| ☐ | 90 | Long-press a recording, tap two more, then **Move to**, **Add label** and **Pin**. | The changes show after the sync. **Done** leaves selection mode. | O-A2 |
| ☐ | 91 | Delete a recording on the phone and confirm. | It moves to Recently deleted. Restore brings it back. | R-2 |

### Dictation keyboard

| ☐ | # | Do this | You should see | Ref |
|---|---|---|---|---|
| ☐ | 92 | Open **Settings → Dictation → Turn on the voice keyboard**, enable it and pick it in a messaging app. | The keyboard shows the microphone, space, delete, enter and switch keyboard. | DI-A1 |
| ☐ | 93 | Tap the microphone, say "send the invoice new line thanks", tap again. | Beeps at start and end. The text lands at the cursor on two lines. | DI-A2 |
| ☐ | 94 | Switch the phone to airplane mode and tap the microphone. | A short "No connection" message. Nothing is inserted and nothing is recorded. | DI-A5 |
| ☐ | 95 | Check the Library. | Dictation made no recording and no Keep / Discard notification. | DI-A2 |

### Lock, settings, widget, turning the phone

| ☐ | # | Do this | You should see | Ref |
|---|---|---|---|---|
| ☐ | 96 | In **Settings → Privacy & security**, turn on **App lock** and cancel the prompt. | The switch stays off. | V-A1 |
| ☐ | 97 | Turn it on again and unlock. Choose "Immediately", leave the app and come back. | The app asks to unlock before anything shows. | V-A1, V-A2 |
| ☐ | 98 | Turn on **Hide content in Recents and screenshots**, open the app switcher. | The thumbnail is blank and screenshots are blocked. | V-A5 |
| ☐ | 99 | Search "quiet" in **Settings**. | The setting is found and its group opens scrolled to it. | S-2 |
| ☐ | 100 | In **About**, export the settings, change a few, then import the file. | "Settings imported" and the changes are back. The file holds no server address, token, lock or consent. | S-3 |
| ☐ | 101 | Add the home-screen widget at 2×1, 2×2 and 4×2. | Each size shows its content. With the app lock on it shows no titles or text. | §17 V-A4, V-A7 |
| ☐ | 102 | Open a recording, turn the phone sideways, then back. | The same recording stays open. | A6.M9 |
| ☐ | 103 | Open the Record tab, turn the phone sideways, then back. | The Record tab stays open. | A6.M9 |
| ☐ | 104 | Open the Library with the phone sideways and scroll. | The header scrolls away with the page and the first recording can be reached. | A6.M11 |
| ☐ | 105 | Turn on airplane mode, keep three recordings, then turn it off. | The uploads continue by themselves with no duplicates. | A-43 |

---

## Part 4 — Back in the web console (about 25 minutes)

| ☐ | # | Do this | You should see | Ref |
|---|---|---|---|---|
| ☐ | 106 | Open **Sessions**. | The kept recordings show with source badges (Desktop, Phone) and app labels. The discarded ones are not there. | A-13 |
| ☐ | 107 | Open the **Bookmarked** tab. | The recordings with a bookmark are listed. | O-W1 |
| ☐ | 108 | Open the Meet recording. Press J, K, L, the arrows and Space on the player, and set 1.5×. | J and L jump 10 seconds, the arrows 5 seconds, Space and K play and pause. | T-W1 |
| ☐ | 109 | Look at the timeline. | The bookmark from step 33 and the pause from step 34 are at the right places. | C-D3, C-D4 |
| ☐ | 110 | Edit a line, then **Edit → Rename speakers** with "remember for this client". | The line is saved. The names show on this client's other recordings. | T-W2 |
| ☐ | 111 | Use **Find and replace**, then **History → Restore original**. | The count is shown. The history lists your name. | T-W3 |
| ☐ | 112 | Choose **Export** for Word, Print or PDF, and Subtitles (.srt). | The files download and open. | T-W4 |
| ☐ | 113 | Choose **Share** with 1 hour, a password and the recording. Open the link in a private window. | The password page, then the transcript and player. | T-W5 |
| ☐ | 114 | Open **Share links** on the recording. | The link shows its views. Revoke it, then reload the link: "This link is no longer available". | T-W5 |
| ☐ | 115 | Choose **Transcribe with another engine**, wait, then **Compare versions → Use the second version**. | The differing words are highlighted. The chosen version becomes the transcript. (This costs one more transcript.) | T-W6 |
| ☐ | 116 | Open **Search** and press **Ctrl+K**. Type a spoken word. | The matching line is highlighted and opens the recording at that moment. | T-W7 |
| ☐ | 117 | Open **Security → audit log**. | It lists your sign-ins, the passkey, the deletes, the export, the share link and the key change, with who did each. No key or password appears. | S-W4 |
| ☐ | 118 | Open **Usage**. | The kept recordings' final minutes and the streamed caption minutes appear. The discarded recordings added no final minutes. | W-8 |
| ☐ | 119 | In **Settings → Storage**, set the monthly limit to 1 minute. Start a recording with captions on Windows. | Windows shows "Monthly transcription limit reached". The recording continues. | V-S5, W-9 |
| ☐ | 120 | Set the limit back to empty (no limit) and start captions again. | Captions work. | W-9 |
| ☐ | 121 | Set retention to 7 days and mark one old recording **Keep**. On the server run `php artisan recordings:retention`. | Old recordings move to **Recently deleted**. The kept one stays. | R-1 |
| ☐ | 122 | Open the old share link of a deleted recording. | "Not found". | R-3 |
| ☐ | 123 | Set retention back to 30 days. | Saved. | — |
| ☐ | 124 | Open **Devices** and revoke the phone. | The phone shows "This phone was signed out on the server. Sign in again." Its waiting uploads stay. | A-42, V-A9 |
| ☐ | 125 | Pair the phone again. | The library loads again. | A-42 |

---

## Result

| | |
|---|---|
| Build / version | |
| Date and tester | |
| Windows and Android builds | |
| Steps that failed (numbers) | |
| Waived, and why | |

---

## Appendix A — Extra checks (not in the 2-hour run)

Run these when the change touches the area, or before a release.

### Windows

| # | Check | Expected |
|---|---|---|
| W-04 | Windows 11: a WhatsApp Desktop call while another app plays sound. | Process loopback is used. The system channel has the far party and other apps but nothing from EchoLedger. Both channels have the same length. |
| W-05 | Windows 10: the same call. | Endpoint loopback is used and EchoLedger makes no sound. Both channels are captured. |
| W-06 | Auto-record with WhatsApp Desktop, Zoom, Meet in Chrome and in Edge. | Each records within 2 seconds and is labelled right. A tab using the microphone for something else is ignored. |
| W-07 | Unplug the headset mid-call, plug it in again. | Capture continues on the new default device. A short gap is fine. |
| W-10 | Two monitors with different scaling. | Docking and click areas are right on both. |
| W-11 | Each global shortcut, and one that another app already uses. | Each works from any app. The conflict shows a message in Settings. |
| W-12 | Dictation into Word, a browser field, and an app running as administrator. | Text is typed. For the administrator window it is pasted and the old clipboard comes back. |
| W-14 | Cut the network during an upload, then quit and restart. | The upload resumes. Local audio is deleted only after the server confirms. |
| C-D1 | Switch Zoom off in **Auto-record apps**, then join a Zoom call. | No recording. The Record screen says "Ignored: auto-record is off for this app". |
| C-D5 | **Caption style**: Large, Wide, 50 % opacity, compact, auto-hide, a custom Client colour. | The captions restyle at once and fade after the set time. |
| C-D6 | **Microphone**: pick a USB headset, run the 10-second test, unplug it. | Both meters move and the test plays back. Unplugging falls back with a note. |
| C-D7 | Block microphone access in Windows privacy settings. | **Microphone** says "Blocked" and **Open settings** opens the right page. |
| V-D5 | Turn on "Ask me when an unrecognised app uses the microphone" and call on an unknown site. | A chip appears after about 3 seconds and closes after 8. Record, Ignore and "Always ignore this site" work. |
| V-D20 | Select a client, delete it in the console, record and keep a call. | The toast says the client was removed. The recording uploads. |
| V-D21 | Stop the server during an upload, start it again. | The upload finishes by itself. A failed item shows Retry and Discard. |
| V-D22 | Stop a recording that ran over 30 minutes. | Memory does not jump. The file plays completely. |
| §17 V-D3 | Windows Settings → Colours: pick another accent, restart EchoLedger. | Buttons, selections and focus rings use it. White button text stays readable. |
| V-D16 | Turn on "Animation effects off". | No pulse and no slide-in. |
| §17 V-D8 | Overlay latency: in a call, start a recording by shortcut, type in a chat box. | The pill, then captions, then the review card each appear about half a second later without taking the focus. |
| P-2 | Record a 10-minute call with live captions and watch the CPU for 60 seconds. | All app processes together use less than 8 % of the machine. |
| N-4 | Turn on quiet hours that include now, then finish a transcript. | No notification. The item waits in the bell. |
| N-5 | Lock the app, then let a notification arrive. | It says only "New activity. Unlock EchoLedger to see it." |
| O-1a | Install fresh with no settings. | The guide opens. A computer paired before v1.1 does not see it. |
| W-15 | Idle resource check: tray only, auto mode on, 10 minutes. | CPU ≈ 0–1 %; RAM within the release budget (record the figure). |
| V-D24 | In light and dark themes, check group labels, status pills, badges and primary buttons. | All are clearly readable. |
| DI-D3 | Windows: copy some text, keep the output on "Type into the focused app", then dictate into an app running as administrator (typing into it is blocked). | The text is pasted instead, and about a second later the earlier clipboard content is back. |
| DI-D5 | Turn Clean up off and dictate "um so yes". | The text is inserted as spoken, without capitals or a full stop. |
| DI-D6 | Recent dictations: Copy one, then Clear history; restart the app. | Copy puts the text on the clipboard; the list stays empty after the restart. |
| DI-D7 | Turn Start and stop sounds off. | Dictation is silent. |
| T-D3 | After correcting a name in a line, use the vocabulary suggestion. | The name is added to the client's keyterms (visible in the console). |
| T-D5 | Settings → Transcription language: English + Urdu; record a mixed call. | The session's language is en+ur and the transcript keeps both languages. |
| O-D3 | Select → pick several recordings → Export transcripts → Word. | A zip lands in Downloads; Delete asks once more and removes them on every device. |
| S-D4 | Revoke another device under Devices. | It disappears there and in the console; that device returns to pairing on its next sync. |
| §17 V-D2 | Windows 10: open the main window. | A solid background (no Mica, nothing see-through); everything else as on Windows 11. |
| §17 V-D6 | Start a recording with captions; speak several long sentences. | The caption card keeps one height (no jumps as lines arrive), shows at most the chosen number of lines with whole lines, its buttons fade in on hover; pill, review card, dictation bar and captions look like one set (same glass, hairline border, radius). |
| §17 V-D7 | Right-click the tray icon while idle, then while recording and paused. | A short grouped menu: Open; Start/Stop, Pause/Resume, (Show recording controls), Capture voice note; check marks on Live captions and Record calls automatically; Review recordings (N) when something waits; Quit. |
| §17 V-D9 | After V-D8: drag the pill somewhere else; change the caption corner and text size and turn on "Hide from screen sharing"; wait a minute (the overlays are closed), then start a recording with captions and share your screen in the call. | The pill opens where it was dragged, the captions in the chosen corner and size; the shared screen shows neither. Clicks next to the pill and the captions reach the app underneath; their buttons work on the first click. |
| V-D10 | Hide the pill, then use the tray item "Show recording controls". | The recording continues while hidden; the pill comes back. |
| V-D11 | Drag the pill to a second monitor and restart; then unplug that monitor and restart. | It reopens where it was; without the monitor it opens top-centre of the main screen. |

### Android

| # | Check | Expected |
|---|---|---|
| A-04 | Point the app at a server whose certificate does not match the pins. | Every request fails, the offline message shows and nothing uploads. |
| A-18 | Restart the phone with auto-record armed. | The setup screen explains how to restart the helper. Re-arming works. |
| A-19 | Samsung: leave the phone idle overnight armed, then call. | With Battery set to Unrestricted the call is captured. |
| A-20 | An Android 12 or 13 phone. | Auto-record is off with "Requires Android 14 or later". Manual recording uses the microphone. |
| A-30 | The **REC** button with no helper. | Microphone recording starts with a **Stop** notification. |
| A-31 | The widget's record button with the app in the background. | Recording starts and the widget returns to idle after Stop. |
| A-32 | The "Record now" notification action. | Recording starts from the background without a crash. |
| A-33 | The Quick Settings tile. | It toggles recording. There is no `ForegroundServiceStartNotAllowedException` in logcat. |
| A-34 | Revoke the microphone permission, then press REC. | No crash and nothing records. The setup screen shows what is missing. |
| A-41 | Delete a recording on Windows. | It disappears from the phone after the next sync. |
| C-A1 | Switch WhatsApp off with auto-record armed and take a call. | Nothing is recorded. WhatsApp Business still records. |
| C-A4 | **Caption style**: Large, top, compact, less opacity, auto-hide. | The bubble follows every setting on the next recording. |
| C-A5 | **Microphone**: pick a Bluetooth headset and run the test. | The meter moves and playback is audible. |
| C-A6 | Revoke the overlay and notification permissions. | Both rows show a reason and **Fix** opens the matching page. |
| C-A7 | Mute the far side for 30 seconds during a call. | The Record tab and the bubble say "Not hearing the other side for 30 s". |
| DI-A3 | Choose **Hold to talk** in the voice keyboard. | The text is inserted when you let go. |
| DI-A4 | Revoke the microphone, open the keyboard. | "Allow the microphone in EchoLedger" opens the app. The microphone stays off. |
| §17 V-A2 | Predictive back from a recording, the setup guide and a settings group. | The preview shows the screen underneath. |
| §17 V-A3 | Tap REC, Stop, Keep and Discard. | A short haptic tick on each. |
| V-A8 | Reach the monthly limit during a call with captions. | The bubble says "Monthly transcription limit reached". The recording continues and uploads after Keep. |
| O-7 | Update an install that was already paired. | No setup guide. |
| N-3 | Leave the phone app closed while a transcript finishes. | A notification arrives within about 15 minutes. Tapping it opens the recording. |
| P-3 | After a day with no recording, open Battery use for EchoLedger. | No wake locks. Background use comes only from the 15-minute check and uploads. |
| A-02b | Deny the camera permission, then press Scan QR code; also press Back while the scanner is open. | A message explains that the code can be entered by hand; Back closes the scanner without pairing; the camera indicator turns off as soon as the scanner closes. |
| A-03 | Inspect app storage (`adb shell run-as` on a debug build). | Recordings under `no_backup/recordings/*.elr` are not playable (encrypted); `library.bin` is not readable JSON; no plaintext `.ogg` remains in `cache/` after saving. |
| A-15 | Repeat A-12 with WhatsApp **Business** and with a WhatsApp **video** call. | Source app is WhatsApp Business / WhatsApp respectively; both channels captured. |
| A-35 | Toggle **Turn off** on the armed notification when the app process was killed. | No crash (`did not then call startForeground` must not appear in logcat). |
| O-3 | Phone: install fresh (clear app data), open EchoLedger. | The wizard opens: Welcome, Permissions, Pair, Test, Done, with "Step n of 5". Back (button and gesture) goes one step back. |
| O-6 | Phone: tap "Skip for now" on Pair, then finish. | The app shows the pairing screen as before; after pairing there is no wizard. |
| O-A1 | Library: smart view chips, folder and label chips. | Same lists and counts as the desktop. |
| O-A3 | Selection → Export as zip. | The share sheet offers the zip. |
| O-A4 | Open a recording → Organise: pin, client, labels. | Saved and visible in the library list. |
| S-1 | Phone: Settings. | A search box and eight groups: General, Recording, Captions, Dictation, Privacy & security, Devices, Storage, About. Each opens as its own screen; back and the back gesture return to the list. |
| S-4 | Phone: import a file that is not a settings file. | "That is not an EchoLedger settings file." and nothing changes. |
| DI-A6 | Replacements, snippets, clean-up off and Recent dictations (copy, clear). | Same results as DI-D4 to DI-D6. |
| DI-A7 | Switch keyboard button. | Returns to the previous keyboard (or shows the keyboard picker). |
| S-A1 | Settings → Devices and activity. | Lists this phone and the other devices, and recent activity with who did it. |
| S-A2 | Revoke another device (tap Revoke, then Revoke now). | It is gone from the list and the console. |
| T-A4 | Settings → Transcription: Urdu; add a vocabulary term. | New recordings are sent with Urdu; the term appears for the selected client in the console. |
| §17 V-A1 | Phone: Settings > General > Appearance: System, Light, Dark; then Wallpaper colours on (Android 12+). | Every screen follows the choice at once; status and navigation bar icons stay readable; with wallpaper colours the accent and surfaces take the wallpaper's tones (also on the widget). |
| V-A3 | Android 10 with only a PIN (no fingerprint). | App lock can be switched on and unlocks with the PIN. |
| V-A4 | No screen lock on the phone. | The switch is disabled with "Set a screen lock on this phone to use App lock." and "Open security settings" works. |
| V-A10 | (Old check) Rotate the phone on the library, a recording and the setup page. | Fixed by A6.M9 and A6.M11; covered by steps 102 to 104. |

### Web console

| # | Check | Expected |
|---|---|---|
| V-S1 | Sign in without two-factor and play a recording. | It plays. |
| V-S2 | Tab to a transcript line and press Enter. | Playback seeks to the line. |
| V-S3 | With two-factor on, sign in with a recovery code. | Signed in on the first click. |
| V-S4 | Open a recording and API Credentials at 360 px width. | No horizontal scrolling. |
| S-W3 | Sign in from a second browser, then sign that browser out on Security. | The other browser is back at the login page on its next click. |
| T-W8 | **Settings → General → Vocabulary**: add a keyterm and a correction, record a new call. | The correction applies to the new transcript and can be undone with **Restore original**. |
| O-W2 | Select three recordings: move to a client, label, pin, then undo. | The change shows on all three and on the devices after their next sync. |
| O-W3 | Select recordings and choose **Export transcripts (Word)**. | One zip with a .docx for each finished transcript. |
| O-W4 | Give a client an avatar and pin it. | The folder shows the avatar and comes first everywhere. |
| R-4 | Leave a deleted recording for 48 hours. | It leaves Recently deleted and cannot be restored. |
| N-1 | Upload a recording. | The bell shows "Transcript ready". |
| N-6 | Sign in a device and go past 80 % of a small monthly limit. | "<device> signed in" and the limit notices appear once each. |
| W-11 | Set the device limit to the number paired, then pair one more. | "Device limit reached — raise it in Settings". |
| W-10 | Set the storage limit below what is used and upload. | "Storage limit reached — raise it in Settings". The recording stays on the device. |
| W-12 | Pass 80 % and 100 % of the monthly limit. | The bell shows each notice once per owner. |
| S-02 | Sign in to `/admin` for the first time, then enrol two-factor sign-in from Profile. | Not forced at first sign-in; a real authenticator app (Google Authenticator, 1Password, Aegis) accepts the QR; recovery codes are shown once and each works exactly once. |
| S-08 | Open a session in the console and play it. | Audio plays and seeks (HTTP 206); the active segment highlights and clicking a segment seeks. |
| S-09 | Set retention to 1 day; age a session in the DB; run `php artisan model:prune --pretend`, then without `--pretend`. | Pretend lists exactly the old rows; the real run deletes rows and their encrypted files. |
| S-9 | Console: finish all five steps, or press Dismiss. | The card disappears and does not come back (finishing: it returns if a step is undone). |
| L-1 | Give a client the language Urdu; record a call with that client on the desktop. | The pill chip shows UR; the transcript says "Urdu" and names the engine. |
| L-8 | With the Groq key added, record an **English-only** call (language English). | Transcribed by AssemblyAI (Universal-3.5 Pro); no Urdu or Hindi letters. |
| L-9 | Record an **Urdu-only** call (language Urdu). | Transcribed by Groq Whisper large-v3; Urdu script throughout, no Hindi letters; Me and Client apart. |
| L-10 | Record a **mixed Urdu + English** call ("کل کی meeting reschedule کر دیں"), language Urdu. | Urdu words in Urdu script, English words in Latin letters, as spoken; nothing translated. |
| L-11 | Record an **Arabic** call (language Arabic). | Transcribed by ElevenLabs Scribe v2 (with its key) in Arabic script. |
| L-12 | Record a call with language **Auto** in Urdu, then one in English. | Tagged Urdu and English, never French or Hindi; Settings → General shows what Auto may pick. |
| L-13 | Record a long call (over 10 minutes) with long silences, language Urdu. | One transcript with times matching the audio across the chunk joins; no invented lines during the silences. |
| L-14 | Record a call: a minute in English with the chip on EN, then tap the chip to UR and speak Urdu; Keep. | The English part is English and the Urdu part is in Urdu script; the switch is where the chip was tapped. |
| L-15 | With captions on, speak Urdu while the caption engine guesses Hindi (Auto). | Desktop captions and the phone bubble show Urdu letters, never Hindi letters. |
| L-16 | Desktop Library: open a finished English recording, pick **Urdu** next to **Re-transcribe** and press it. | "Re-transcribing in Urdu…"; the English text stays until the Urdu one is ready, then Urdu shows with **Current / Previous (English)**. |
| L-17 | Phone: open a finished recording, **Re-transcribe as…**, pick **UR**, **Re-transcribe**. | As L-16; after a sync **Previous (English)** shows the old text. |
| L-18 | Console: a recording page, **Re-transcribe as…** → Arabic. | "Re-transcribing in Arabic with …" names the engine; when done the Arabic text is the transcript and the old one is the second version. |
| L-19 | Open a transcript with words the engine was unsure of (names, a noisy line) on the desktop, the phone and in the console. | Those words (or the whole line, for Whisper) have a dotted amber underline; a click or tap plays from that word; the text is unchanged. |
| L-20 | Turn on **Settings → Engine per language → Double-check**, then record a call with a hard name or a noisy line. | Under the unsure lines that the second engine heard differently: "Groq Whisper large-v3 heard: …" (desktop, phone, console); the transcript text is unchanged; Usage shows a `double-check` row. |
| L-21 | Record an Urdu call over 20 minutes; open it in the desktop Library and on the phone while it is transcribed. | "Transcribing… part 2 of 3" (the part moves on); then the transcript. |
| L-22 | Console, an Urdu recording: **Roman Urdu & English** (with the Anthropic key), wait two minutes, reload. | The player has **Original · Roman Urdu · English**; Roman Urdu reads "aap kaise hain"-style with English words unchanged; English is a faithful translation; the line times are the same. |
| L-23 | **Export → Word (.docx) in Roman Urdu**, then edit one line and reload. | The file has the Roman Urdu text; after the edit the switch is gone until the views are made again. |
| L-24 | Mark three Urdu recordings as Accuracy Lab references (corrected text), run **Compare engines** with Groq, Soniox and AssemblyAI on each, open **Accuracy Lab**. | One Urdu table with WER, CER, seconds per audio minute and cost per audio hour per engine; the best one is marked; each recording lists its scores. |
| L-25 | **Use the winners**, then open **Settings → Engine per language**. | The best Urdu engine is first in the Urdu list; the other engines follow. |
| L-26 | Phone: **Settings → Microphone → Permissions → Notification access**, allow **EchoLedger caller names**, then take a WhatsApp voice call with a saved contact while auto mode records. | The recording is called **Call with <the WhatsApp name>** on the phone, desktop and console; a WhatsApp message notification during the call changes nothing. The other person hears you the whole call. The transcript has the other person's lines; on Android 16/17 your own lines are missing and the capture log says "Your own voice is not recorded in this call". `dumpsys audio` never shows WhatsApp `silenced`. |
| L-27 | Console: **Clients → Acme → Contacts**, add "Omar Khan", his number and Urdu; record a WhatsApp call with Omar (no client chosen). | The recording is filed under Acme, transcribed as Urdu and called **Call with Omar Khan**. |
| L-28 | Windows: join a Microsoft Teams call with one person while auto mode records; then a Google Meet with a named meeting. | The Teams recording is called by the person's name and the Meet one by the meeting name; a Meet with only a code (abc-defg-hij) keeps the app's name ("Google Meet call"). |
| L-29 | Move a recording with a caller name but no matching contact to a client; record another call with the same person. | The caller appears in that client's Contacts; the next call is filed under the client by itself. |
| L-30 | Phone: **Settings → Auto-record apps → Record phone calls → Always**; allow Call log and Contacts; with auto-record on, take a SIM call from a saved contact for 30 s. | "Recording phone call" during the call; after it, "Save this recording? Call with <contact> · 0:30"; Keep: the console shows **Call with <contact>** with source Phone call, both sides heard (or one track on phones without a downlink). On phones whose maker keeps the line (Samsung), the capture log says "The phone line gave no sound" after 8 s. |
| L-31 | **Record phone calls → Ask each time**, take a SIM call, do not tap Record; take another and tap **Record** after 10 s. | First call: nothing recorded and the question disappears when the call ends. Second call: recorded from the tap. |
| L-32 | **Record phone calls → Off**, take a SIM call; then a WhatsApp call. | The SIM call is not recorded; the WhatsApp call is recorded as before. |
| L-33 | A SIM call from a number that is not a contact and not in any client's contacts. | The recording is called **Call with <number>**; it has no client. |
| L-2 | During a recording with captions on, tap the pill's language chip twice. | Captions continue in the new language within a few seconds; the timer never stops; the saved file has no gap. |
| L-3 | Stop a recording, change the chip on the Keep card, then Keep. | The transcript uses the chip's language; with a client of another language you are asked "Use … for … from now on?". |
| L-4 | Phone: after a call, tap the chip on the popup, then Keep. | The transcript uses the new language. |
| L-5 | Search the library for a word spelled with Arabic letters (for example كيا) in an Urdu transcript written كیا / کیا. | The recording is found and the word is highlighted. |
| L-6 | A recording with an Urdu transcript in Hindi letters. | "Wrong script detected" shows; one tap queues the next engine. |
| L-7 | Add Soniox and ElevenLabs keys, then **⋯ → Compare engines** with Urdu + English and three engines. | Three runs with text and cost; **Make default for Urdu + English** moves that engine to first place in Settings → Engine per language. |
| R-5 | Console dashboard, desktop Settings and phone Settings. | The storage meter shows the recordings' size and the server disk; the retention days match the console. |

### macOS 14.4+ (only if you have a Mac)

| # | Check | Expected |
|---|---|---|
| M-01 | Install the notarized DMG. | Gatekeeper opens it without warnings. |
| M-02 | First recording. | The microphone and system-audio prompts show their text. Denying either explains what to do. |
| M-03 | A WhatsApp, Zoom or Meet call with auto-record on. | The far party is captured, but not EchoLedger. No leftover audio device remains. |
| M-04 | Change the audio devices mid-call (AirPods). | Capture follows with no crash. |
| M-05 | Overlays and dictation. | Same as steps 30 and 56. Dictation asks for Accessibility once. |
| M-06 | Menu-bar behaviour. | No Dock icon. Quitting ends all capture. |
| §17 V-D5 | The main window. | Vibrancy behind the sidebar; the traffic lights sit inset; the accent follows the system. |
| V-D6 | Browser tab detection with automation on, then off. | On: recorded as Google Meet after the prompt. Off: ignored as an unknown site. |
| V-D25 | The pill and review card on screen recording. | They appear but are hidden from the recording. |

### Mac app v1.4 (about 30 minutes; paste the results back)

First check the macOS version: Apple menu → **About This Mac**. The app needs 14.4 or newer and does not open on older
versions. Get the `.dmg` from the latest **Desktop (macOS)** run on GitHub
(Actions → the run → Artifacts: `EchoLedger-mac-apple-silicon` for M1–M4, `EchoLedger-mac-intel` for Intel).

| # | Check | Expected |
|---|---|---|
| MAC-1 | Open the `.dmg`, drag EchoLedger to Applications, open it once, close the message, then System Settings → Privacy & Security → **Open Anyway** (macOS 15 and later; right-click → Open no longer works there). | It starts (menu-bar icon, no Dock icon). Later opens ask nothing. |
| MAC-2 | Pair with the console (QR or code). | Paired; the Library loads. |
| MAC-3 | First recording: allow the microphone and **system audio recording**. | Both prompts show EchoLedger's text; denying one says what to do. |
| MAC-4 | A Google Meet call in Chrome or Safari, 1 minute, both people talk. | Recorded as Google Meet; the transcript has both sides (You and the other person). |
| MAC-5 | A Zoom call, 1 minute. | Recorded as Zoom with both sides. |
| MAC-6 | A WhatsApp desktop call, 1 minute. | Recorded as WhatsApp with both sides. |
| MAC-7 | A voice note (Record in the app, 15 seconds). | Shows in the Library at once, then the transcript. |
| MAC-8 | Import a WhatsApp `.opus` and an `.mp3` (Library → Import or drop them on the window). | Two recordings named after the files; importing one again says it is already in your Library. |
| MAC-9 | Live captions overlay during MAC-4. | Captions show over the call; ⌘ shortcuts from Settings → Shortcuts work. |
| MAC-10 | Quit from the menu-bar icon during a recording. | It asks first; after quitting, no audio device is left behind (Audio MIDI Setup shows no extra device). |

### Mac app v1.5, what only a real Mac can prove (Phase 1B; CI proves the build, the tests and the smoke test)

| # | Check | Expected |
|---|---|---|
| MAC-11 | First start: the setup's Microphone, System audio and Screen recording steps; **Allow**, then **Open System Settings** on each. | Each prompt shows EchoLedger's text; each button opens the right pane (Microphone; Screen & System Audio Recording); coming back shows the new state without a restart. |
| MAC-12 | Turn off system audio in System Settings, open the Record page, record a call. | The banner says your voice only, the pill and captions say so too, and the recording has your voice. Far-side warning after 30 s. |
| MAC-13 | Turn off the microphone too, press REC. | It does not start and says why; nothing is saved. Voice note with only the microphone off: refused the same way. |
| MAC-14 | Install an update (an unsigned build): start the app. | If macOS forgot a permission: one calm sentence with **Set up**; dismissed, it does not come back at the next start; turning the permission off again later says so again. |
| MAC-15 | **Run Mac check** on a healthy Mac; speak during the microphone test. | One progress line at a time; a soft tone plays; all ✓. Turn off each permission in turn: ✗ with **Open System Settings**, its test not run. During a recording: the sentence and no button. |
| MAC-16 | **Copy report** and read it; **Send to my admin**, signed in and signed out. | No name, no path with a user name, no account. It appears under the console's System → Device reports with the model (`Mac14,2`) and says when it was sent. Signed out: greyed out with the reason. |
| MAC-17 | Menu-bar icon on a light and a dark menu bar, Retina and a 1x monitor. | Black or white with the bar, sharp at both; red tile while recording, amber while paused, a small dot while uploading. |
| MAC-18 | With the window in front: ⌘, ⌘W ⌘Q; ⌘C and ⌘V in a text field. Settings → Shortcuts. | Settings opens; the window closes and EchoLedger stays in the menu bar; it quits, and ⌘Q during a recording saves the recording first; copy and paste work; shortcuts read ⌘ ⌥ ⇧. |
| MAC-19 | A notched MacBook: start a recording, then System Settings → Control Center → Automatically hide and show the menu bar. | The pill sits below the menu bar and the notch, also with the menu bar hidden; captions and the Saved toast clear the Dock. |
| MAC-20 | MacBook at 2x with a 1x monitor: drag the pill and captions to the monitor; click their buttons there. Restart the recording. | Clicks land where the buttons are; the pill opens where it was left. |
| MAC-21 | Unplug that monitor with the pill and captions on it. | Within 5 s both are back on the laptop's screen. |
| MAC-22 | A full-screen Zoom or Meet window, then swipe between Spaces. | Pill and captions stay over the full-screen meeting and follow every Space; ⌥⌘H from another app leaves them. They never take focus from the meeting. |
| MAC-23 | Settings → General → Start EchoLedger when I sign in: on, log out and in; then off. | Off by default. On: it starts in the menu bar with no window. Off: `~/Library/LaunchAgents/EchoLedger.plist` is gone. |
| MAC-24 | A signed update: Settings → About → Check for updates, on Apple Silicon and Intel. | Downloads, says restart, the app comes back on the new version. Recordings and settings stay. |
| MAC-25 | During a recording: connect AirPods; switch input and output in the menu bar; pull a USB headset; turn the AirPods off. | The pill says what changed and goes on; a device gone says what the recording holds until one returns. In the file nothing is at the wrong speed and the gap is silence, not a cut. |
| MAC-26 | Press Stop during MAC-25 and on a plain recording. | Stop answers at once (it used to be able to hang); Audio MIDI Setup shows no extra device. |
| MAC-27 | Terminal, with EchoLedger running and signed in: `/Applications/EchoLedger.app/Contents/MacOS/echoledger-desktop --smoke-test; echo $?` | A second icon for a moment, 0, and the running app, its data and its sign-in untouched. |
| MAC-28 | Close the main window and turn off the microphone and system audio. Press the record shortcut; click **Record** in the menu-bar icon; close the window again and join a meeting with auto-record on; press the dictation shortcut in a text field. | The shortcut and the menu open the window with the reason in it and the banner. The meeting gets a system notification saying why, and no window jumps in front of it (this is also the first proof that an unsigned build's notifications arrive). Dictation's box says the microphone is off and that dictation did not start, then hides. |
| MAC-29 | First recording after setup was skipped (or after an update made macOS forget): press **Don't Allow** at the microphone prompt, then at system audio. Then, during a recording, turn the microphone off and on again in System Settings. | Within a few seconds of each answer the pill, the captions and the Record page say what the recording holds; both refused: "Nothing is being recorded". Turned back on: the line goes; check the microphone is really back in the file. |
| MAC-30 | **Run Mac check**; during its tone test press the record shortcut. Run it again and press the dictation shortcut during the microphone test. On a Mac that never asked for Screen Recording, run it once more. | The tone stops at once and the check says a recording (or dictation) started, with no failed result for the test it cut short; the recording has no tone in it, and a meeting recorded this way still gets screen moments. Never asked: both Screen Recording rows say **Limited** and point to Settings → Screen moments, not "off". |

### Windows v1.5, what only a real PC can prove (Phase 1B; CI ran the smoke test on Server 2022, Server 2025 and Windows 11 on Arm)

| # | Check | Expected |
|---|---|---|
| WIN-1 | Windows 10 below build 20348 (`winver`; 22H2 is 19045): Record page and Settings → Microphone. Record a call with music playing in another app. | Both say "This Windows version records all sound playing on this computer, not just the call"; the music and EchoLedger's own sounds are in the recording, and the recording goes on normally. |
| WIN-2 | Windows 11: the same, and play a recording inside EchoLedger meanwhile. | No sentence; EchoLedger's own sound is not in the recording (the other apps' still is: the capture leaves out only EchoLedger itself). |
| WIN-3 | During a recording: plug in a USB headset, switch the default device, connect a Bluetooth headset, pull the USB headset. | The pill says what changed (on Windows 11 a change of speakers alone needs no move, because per-app capture does not depend on the device, so it may say nothing about that), the recording goes on, the gap is silence. A microphone that opens again on the same device says nothing; one that stays silent for good says "No microphone" after about 15 s. |
| WIN-4 | Settings → Microphone: choose the USB microphone, pull it during a recording, plug it back. | Moves to the default and says so; moves back when it returns. |
| WIN-5 | Unplug every microphone during a call. | "No microphone: recording the other side only" until one returns; the file is whole. |
| WIN-6 | From a command prompt, with EchoLedger running: `start /wait "" "%LOCALAPPDATA%\EchoLedger\echoledger-desktop.exe" --smoke-test`, then `echo %ERRORLEVEL%`. (Without `start /wait` the prompt does not wait for a windowed app and always shows 0.) Or with PowerShell 7: `pwsh -File desktop\scripts\smoke-windows.ps1 "$env:LOCALAPPDATA\EchoLedger\echoledger-desktop.exe"`. | 0, after a few seconds; the running app and its sign-in untouched. |
| WIN-7 | Close the main window and turn off the microphone in Windows Settings → Privacy → Microphone. Click **Record a voice note** in the tray; press the dictation shortcut in a text field. | The tray click opens the window with the reason in it. Dictation's box says the microphone is off and that dictation did not start, then hides. |

### Phone v1.5 (phone windows; the S25, installed with `pm install -r`, data kept)

| # | Check | Expected |
|---|---|---|
| P15-1 | Auto-record on. A WhatsApp call that rings and is never answered. | Nothing is saved; no chip, no notification. The capture log says "waiting for the answer" and "not answered". |
| P15-2 | A WhatsApp call answered after a few rings; talk 20 s. | The recording starts at the answer (no ringing at the start); the chip "Saved · Call with … · 0:2x · Undo" rises in for 6 s. The capture log names the answer signal. |
| P15-3 | A SIM call answered; talk 20 s. | Starts at pick-up; kept as in P15-2. |
| P15-4 | An answered call of 5 s. | Skipped silently; Activity shows "Skipped 1 short call" with Restore for 24 h; Restore uploads it. |
| P15-5 | Settings → After a call → Ask me; another answered call. | The chip asks with ✓ and ✕; the title opens the call to rename it or pick a project; nothing chosen = kept after 10 s. |
| P15-6 | Projects tab: make a project with a subfolder, a title filing rule, then a recording whose title matches. | The recording files itself into the project; Move to another project and back works; deleting the subfolder keeps its recordings (Inbox). |
| P15-7 | A recording's Share: people only, one email; Send to a teammate (the test account). | The link opens only for that email; the teammate sees it in Shared with me and can Save to my library. |
| P15-8 | Open a share link of this server on the phone (in a message). | It opens in EchoLedger (App Links, when the build has the host), else the browser. |
| P15-9 | Sign out of the app (Account → Sign out everywhere), then Sign in with email, password and the 2FA code. | Signed in; the Library is back; uploads waiting on the phone were kept. |
| P15-10 | Sign in in the browser (Google or a passkey). | The browser comes back to EchoLedger and the phone is signed in. |
| P15-11 | Publish a newer APK on the server (`echoledger:publish-release`), then You → About → Check for updates. | "EchoLedger x.y.z is ready to install"; Update downloads it, Android asks once to allow installs, then installs it; recordings and settings stay. |
| P15-12 | A recording with screen moments (from the desktop). | "Screen 7 · 14:32" rows between the lines; tap opens the screen with Play from here; Export → Meeting pack (PDF) prints with the screens. |
| P15-13 | With Remove animations on (Accessibility), keep a call. | The chip and its check appear without moving. |

### Accounts on the computer v1.5 (desktop; after the accounts deploy, with two test accounts)

| # | Check | Expected |
|---|---|---|
| AC-1 | Sign in as account A with the password. Sign out, sign in with **Sign in with the browser**. | Both reach the Library; the browser shows the console's approval page and returns to the app. |
| AC-2 | As A, stop the network and make a recording. Sign out, sign in as B, turn the network back on. | The Library says the recording waits for A's email; nothing reaches B's library. Discard stays possible. |
| AC-3 | With a recording of A's that already reached the server and has screens still waiting: sign in as B, wait for a few uploads, sign back in as A. | The screens wait while B is here and arrive on A's recording afterwards. |
| AC-4 | Sign in as a Viewer. | The tray and the app offer no recording; the app says what a viewer can do. Signing in itself works. |
| AC-5 | On a plan with AI notes off, open a recording. | No notes or Ask button; Roman Urdu, suggested fixes and screen descriptions stay. |
| AC-6 | Enter a wrong password six times. | The app says how long to wait, in plain words; no crash, no lockout of a signed-in device. |
| AC-7 | Settings → Account → Delete my account… → **Delete on the web page**. | The browser opens the public page of this server. Cancelling in the app leaves everything as it was. |
| AC-8 | First build from the signing workflow (when a certificate exists). | Windows shows the publisher's name, not "Unknown publisher"; the Mac build opens without a Gatekeeper warning. |

### Teammate phone check (any Android 10+ phone; about 15 minutes)

Send this to a teammate with a phone that is not a Samsung. It needs an account on the server, the website APK and one
short test call. Nothing here changes what is already on the phone, other than installing EchoLedger.

| # | Check | Expected |
|---|---|---|
| TP-1 | Install the APK and open it. | No crash. The second setup step lists "What works on this phone" with a reason for each line. |
| TP-2 | Sign in, then You → General → Help & diagnostics → Device check → **Send to my admin**. | "Sent to your admin". The report shows in the console under System → Device reports. |
| TP-3 | Follow "Keep EchoLedger running on …": tap **Open settings**. | The phone maker's own screen opens, or this app's page; the steps match what the phone shows. Note any that differ. |
| TP-4 | Record a 20-second voice note and open it in the Library. | It plays and gets a transcript. |
| TP-5 | Android 12 or newer: set up the helper with Setup's steps for this brand. | The Developer options path matches this phone, and the helper says it is running. |
| TP-6 | With the helper, a WhatsApp call to a friend; talk for 20 seconds. | Android 14+: both voices. Android 12 and 13: **your voice only**, labelled so, with no silent track. |
| TP-7 | Without the helper, a WhatsApp call. | Nothing recorded, and the Record tab said so before the call. |
| TP-8 | Leave the phone alone for an hour with auto-record on, then repeat TP-6. | The call still records, without opening the app first. |
| TP-9 | Settings → Display: largest text. Open the Library, Record and Device check. | Nothing is cut off or overlaps. |

Send back the brand, model, Android version and the rows that differ.

### Screen moments v1.5 (desktop; the first run of the real capture)

Use the CI build of the branch or the release (Actions → "Desktop (macOS)" or "Desktop (Windows)" → Artifacts), a
second person or device to share a screen, and a slide deck with a few slides.

| # | Check | Expected |
|---|---|---|
| SM-M1 | Mac: Settings → Screen moments on → **Allow Screen Recording**, approve in System Settings → Privacy & Security → Screen & System Audio Recording. | The app sees the permission without a restart; Settings explains that macOS asks again about once a month. |
| SM-M2 | A Zoom call, then a Meet call in Chrome and in Safari, with the deck shared. | The pill's monitor chip says **Screens on**; "Screens on for this meeting" appears once. |
| SM-M3 | Change slides and wait about 2 s; on a new slide say "look at this page". | A screen is kept after each change, and at once after the words. |
| SM-M4 | Minimise the meeting window, then Cmd-H. | The chip says screens are paused while the window is minimised or hidden; they resume when it is back. |
| SM-M5 | Whole screen; open 1Password on the meeting's display, then move it to another display. | Screens pause, then resume. |
| SM-M6 | Tap the chip twice. | Paused by you, then on again. |
| SM-M7 | Stop the recording and let it upload. | `~/Library/Application Support/com.echoledger.desktop/screens/` empties; the recording page shows thumbnails at their lines; viewer, Play from here, Copy for AI and both Meeting pack exports work; the phone shows the same screens. |
| SM-M8 | A project with **Keep screen moments** off; record a meeting into it. | Nothing is kept. |
| SM-M9 | Revoke the Screen Recording permission, record again. | The chip names the missing permission. |
| SM-W1 | Windows: the same flow with Zoom, Teams, WhatsApp desktop, and Meet in Chrome and Edge. | As SM-M2 to SM-M8. |
| SM-W2 | Windows 11, then Windows 10. | No yellow border on 11; on 10 the border shows and Settings says so. |
| SM-W3 | Minimise the meeting window; move it to another virtual desktop. | Screens pause. |
| SM-W4 | Whole screen with Bitwarden or KeePass on the same monitor. | Screens pause. |
| SM-W5 | Switch Chrome to another tab during a Meet call. | Screens wait for the meeting tab. |
| SM-W6 | A Windows 11 PC that still shows the border. | The **Windows Settings** button opens the borderless setting. |
| SM-M10 | Mac: add `zoom.us` to Settings → Screen moments → excluded apps, then (separately) `Google Chrome` with a Meet tab open; meeting-window mode. | The pill says screens are paused for an excluded app; nothing is kept. |
| SM-M11 | Mac, whole-screen mode, 1Password 7 open beside the meeting. | Screens pause. |
| SM-W7 | Windows, whole-screen: Bitwarden mostly on monitor 2 with a strip on the meeting's monitor 1; then fully on monitor 2. | Paused, then resumed. |
| SM-W8 | Windows, whole-screen, EchoLedger's main window open on the meeting's monitor (also opened during the capture). | Kept screens show what is behind it, never EchoLedger; Zoom's own share and Snipping Tool do not show it while screens are on, and do again after pausing or stopping. |
| SM-X1 | Both: unplug the network, start a call filed under a project; plug it back in. | The chip says "starting" and nothing is kept; within about 30 s screens start if the project keeps them, and stay off if not. |

### Desktop v1.5 Phase 1C: easy, trustworthy, clear (what only a real computer can prove)

Use the CI build of the branch (Actions → "Desktop (macOS)" or "Desktop (Windows)" → Artifacts). Tests cover the decisions
with fakes; these rows prove the system around them.

| # | Check | Expected |
|---|---|---|
| 1C-A1 | Start the app signed in, with everything allowed. | Within a few seconds the sidebar and the tray say **Calls: Ready**; Record shows **✓ Ready to record calls**. |
| 1C-A2 | Turn off **Record calls automatically**, then press **Turn on** in the Ready check. | The chip turns to **Not ready — Fix** at once and back to **Calls: Ready** after the button. No notification for your own choice. |
| 1C-A3 | Mac: in System Settings take away Screen & System Audio Recording (or the microphone) while the app runs; also with quiet hours on all day. | Within about 10 s a notification "Calls will not be recorded fully" names the problem, also in quiet hours; the chip says **Not ready — Fix**; the fix button opens the right pane. |
| 1C-A4 | Windows: unplug the only microphone (USB headset) while the app runs. | The Critical notification and **Not ready — Fix**; plugging it back turns the chip ready again. |
| 1C-A5 | With a Zoom or Meet call running, press Ctrl+Shift+R (⌘⇧R); then again. With no call, press it again. | A call recording with both sides starts ("Recording the call, both sides"), then stops; without a call a voice note starts. Same from the tray's **Record now**. |
| 1C-A6 | Settings → Shortcuts: Record now shows the browser warning; change it to another key and back. | The warning names the browser reload; the new key works at once. |
| 1C-A7 | Make a call start fail once (for example a second app holding an exclusive device), so auto mode does not start it. | About 5 s after the call is detected: **Recording started (backup)**. Stop it yourself mid-call: it stays stopped. |
| 1C-B1 | Settings → Notifications: press **Test** on each kind, with Sound and with Silent. | Each shows a notification titled "Test: <kind>"; Silent ones make no sound (Windows and Mac). |
| 1C-B2 | Turn Sharing off and quiet hours on all day; have a teammate share a call; then cause 1C-A3. | No Sharing notification (it waits in the bell); the readiness one still shows. |
| 1C-C1 | Import five audio files at once (one of them 100 MB). | Each file is a row under Importing with its own percent; Cancel on one leaves the others going. |
| 1C-C2 | Open a long Urdu recording while it is transcribed in parts, on a server with Phase 1C. | Row, page and Activity → In progress say "Transcribing… part N of M · about X left"; the time left goes down. Against an older server: "Transcribing… part N of M" only. |
| 1C-C3 | When the transcript arrives, scroll to the middle and wait for the Roman Urdu view. | The text is readable at once with "Improving Roman Urdu… N%"; when the view is made it swaps in without the page jumping. |
| 1C-C4 | Press **Notify me when ready**, turn Transcription notifications off, close the main window. | A notification when the transcript is ready (within about a minute). |
| 1C-D1 | Library with a few hundred recordings: Group by Project, Sort by Longest; open the Inbox, choose None and Name A–Z; restart the app. | Each view comes back with its own choice; scrolling a long group keeps its header at the top; Windows and Mac. |
| 1C-D2 | Tick a group's box, move the group to a project; tick a box with half the group picked. | All its recordings move; a half-picked box shows a dash and ticks the rest. |
| 1C-E1 | Open an 18 s Urdu voice note; switch Original, Roman Urdu, English and Urdu while it plays. | The player shows 0:18 in every view and keeps playing. |
| 1C-E4 | A new voice note without a title, once its transcript is ready; then rename it; then empty the title. | An automatic short title marked Automatic title; your title stays after a re-sync and on the phone; emptying brings an automatic one back. |
| 1C-F1 | Sign out; on the sign-in screen type the staging server's address. With Settings → Who can sign up on Anyone and Google set, then Invite only and no Google. | First: Continue with Google, Create account and Forgot password (each opens the server's page in the browser). Second: email and password, Forgot password, the passkey browser button; no Google, no Create account. |
| 1C-F2 | Sign in with an account that has two-step sign-in. | The code field appears; after it, Record opens with the Ready check. |
| 1C-A8 | A 30 s call where the other side plays through a headset that is not the default output (or is muted). | After the call: **Only your voice was recorded**, with the reason and the fix; the banner opens the Ready check. |

## Appendix B — Accessibility

| # | Check | Expected |
|---|---|---|
| V-X1 | Narrator or NVDA (Windows), VoiceOver (Mac): Tab through the main window. | Every control has a name and a state. The focus ring is visible. |
| V-X2 | TalkBack: swipe through Library, a recording, Record and Settings. | Every button and switch is read with a name. |
| V-X3 | Turn on reduce motion in each system. | Overlays appear without sliding. Nothing pulses. |

## Appendix C — Release day

| # | Check | Expected |
|---|---|---|
| S-01 | Deploy per `web/DEPLOY.md` and add the cron line. | `php artisan schedule:list` shows the queue worker, `stt:reconcile`, `recordings:recover`, `sessions:sweep-open`, `recordings:retention`, `model:prune` and `sanctum:prune-expired`. |
| S-04 | Record a 2-minute two-party call with AssemblyAI. | The webhook arrives, Me and Client are right, and the provider no longer lists the transcript. |
| S-05 | The same with ElevenLabs. | The signed webhook is accepted and the provider's history is empty. |
| S-06 | Block the webhook for a moment, then record a call. | Within 5 minutes `stt:reconcile` completes it. |
| S-07 | Azure MAI as the final provider. | Devices upload `channel_1` and `channel_2`. The transcript completes at once. |
| S-10 | Set the certificate pins and pair. | Pairing works. A certificate matching only the backup pin keeps devices connected. An unpinned certificate is refused. |
| W-1 | After the migrations, open the console as the owner. | Every recording, client and device is there. Workspace shows "Personal". |
| W-2 | A device paired before the update records a short call. | Sync, upload, captions and the transcript work with no re-pairing. |
| W-3 | Open a share link made before the update and play a recording. | Both work. |
| W-4 | Run `php artisan recordings:retention`. | It finishes without errors. |
| R-01 | `signtool verify /pa /v` on the installer and the main executable. | Valid chain with a timestamp. (The installer is not signed yet: needs a certificate.) |
| R-02 | macOS: `spctl` and `stapler validate` on a Mac that never ran the app. | Accepted. |
| R-03 | Install the new APK over the previous one. | The update works. Pairing and waiting recordings survive. |
| R-04 | Optional auto-update with a signed manifest. | It installs only when not recording. A tampered signature is rejected. |
| Privacy | Watch a proxy for 10 minutes of use; run `tools/scan-secrets.ps1` on the installer folder and the APK. | Only the server and the chosen speech provider appear. No provider key is found. |

### After the deploy: security checks v1.5 (about 15 minutes)

Replace `<server>` with the console's address.

| # | Check | Expected |
|---|---|---|
| SEC-1 | `curl -s -o /dev/null -w '%{http_code}' -H 'Host: example.com' https://<server>/up` | `400`, or the host's own error page. The same without the `-H` part answers `200`. |
| SEC-2 | Console → Forgot password for your own account. Open the email. | The link starts with `https://<server>/`. |
| SEC-3 | Play a recording in the console and on a phone. | It plays: signed links use the server's own address. |
| SEC-4 | Profile: change your email to a second address you own. Before opening the link in that mailbox, sign out and sign in with the old address. | The old address still works; the new one takes over only after the link is opened. |
| SEC-5 | Send a project to a teammate, then file a new recording in it. | They see what was there when you sent it, not the new one. Send it again: now they see it. |
| SEC-6 | **Share** on a recording or project you sent, on the phone, the desktop and the console. Press **Stop sharing**. | The person is listed with the date; after pressing, they can no longer open it. A copy they saved stays theirs. |
| SEC-7 | Remove a teammate from the workspace. | What was sent to them from it leaves their Shared with me, and links they made there stop working. |
| SEC-8 | As a member, not an owner or admin, with Live Translate's budget used up: press **Continue this month**. | Refused, with a message to ask an owner. An owner can continue, up to twice the budget. |
| SEC-9 | Fail the console sign-in six times for one email, then enter the right password. | Refused for about 15 minutes, in plain words. |
| SEC-10 | **Sign out everywhere** with the console open in a second browser. | The second browser goes to the sign-in page on its next click. |
