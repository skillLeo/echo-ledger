# Manual QA — real-device verification

Automated suites cover the pure logic of every app (state machines, protocol parsers, PCM framing, chunk planning,
pin handling, sync merge, API contract). Everything that depends on real audio hardware, OS permissions, window
managers, signing infrastructure or live provider accounts is verified here, on real devices, before each
release.

**How to use:** copy this file into the release ticket, run every item, and record the device / OS build and the
result. An item passes only when the **Expected** outcome is observed exactly. Any failure blocks the release
unless explicitly waived.

Test fixtures: a paired desktop, a paired Android 14+ phone, a second phone (or a contact) to call, a WhatsApp and
WhatsApp Business account, a Zoom account, a Google Meet link, and a server with real provider credentials.

---

## 1. Server (production-like host)

| ID | Steps | Expected |
|---|---|---|
| S-01 | Deploy to the shared host per `web/DEPLOY.md`; add the cron line. | `php artisan schedule:list` shows the queue worker, `stt:reconcile`, `model:prune`, `sanctum:prune-expired`. Queue drains within a minute of a new job. |
| S-02 | Sign in to `/admin` for the first time, then enrol two-factor sign-in from Profile. | Not forced at first sign-in; a real authenticator app (Google Authenticator, 1Password, Aegis) accepts the QR; recovery codes are shown once and each works exactly once. |
| S-03 | Enter AssemblyAI + ElevenLabs keys on **API Credentials**; reload. | Only "configured ••••last4" is shown; the raw values are never rendered in HTML (view source). |
| S-04 | Record a 2-minute two-party call from the desktop with AssemblyAI as final provider. | Webhook arrives (session reaches `completed` without waiting for `stt:reconcile`); Me/Client segments are correct; the AssemblyAI dashboard no longer lists the transcript (deleted after store). |
| S-05 | Repeat S-04 with ElevenLabs as final provider. | Signed webhook accepted; transcript stored; the ElevenLabs history no longer lists it. |
| S-06 | Block the webhook URL temporarily (e.g. maintenance mode), record a call, unblock. | Within 5 minutes `stt:reconcile` completes the transcript. |
| S-07 | Configure Azure MAI (endpoint + key) as final provider; record a call. | Devices upload `channel_1` + `channel_2`; transcript completes synchronously with both speakers. |
| S-08 | Open a session in the console and play it. | Audio plays and seeks (HTTP 206); the active segment highlights and clicking a segment seeks. |
| S-09 | Set retention to 1 day; age a session in the DB; run `php artisan model:prune --pretend`, then without `--pretend`. | Pretend lists exactly the old rows; the real run deletes rows and their encrypted files. |
| S-10 | Configure SPKI pins (current + backup) on **Settings**; pair a device. | Pairing succeeds; replacing the server certificate with one whose key matches the backup pin keeps devices connected; an unpinned certificate is refused by both device apps. |

## 2. Desktop — Windows

Run on **Windows 11 (build ≥ 22000)** and **Windows 10 22H2**.

| ID | Steps | Expected |
|---|---|---|
| W-01 | Install the signed NSIS installer on a clean VM. | SmartScreen / UAC shows the publisher name; `signtool verify /pa /v` passes; the app starts in the tray. |
| W-02 | Launch a second instance. | The existing instance's main window is focused; no second tray icon. |
| W-03 | Pair with the code and with the QR text. | Library loads; the token is in Credential Manager (`EchoLedger`), not in any file under `%APPDATA%`. |
| W-04 | Win 11: make a WhatsApp Desktop call while another app plays audio. | Process loopback is used (logged); `system.wav` contains the far party and other apps' audio but nothing from EchoLedger itself; `mic.wav` contains only the local voice; both files have identical length. |
| W-05 | Win 10: same call. | Endpoint-loopback fallback is used (logged); EchoLedger produces no sound while recording; both channels captured and equal length. |
| W-06 | Auto mode on: start/stop calls in WhatsApp Desktop, Zoom, Google Meet in Chrome and in Edge. | Recording + captions start within ~2 s of the call's mic use and stop after it ends; `source_app` is correct for each. A browser tab using the mic for something other than Meet is labelled `other`. |
| W-07 | Unplug the headset mid-call; plug it back. | Capture continues on the new default device without crashing; a short gap (silence) is acceptable; channels stay aligned. |
| W-08 | Caption overlay during a call. | Translucent, always on top, **never takes focus** (typing in another app continues uninterrupted), clicks on empty areas pass through to the app below, the overlay can be dragged by its handle and docks bottom-right / top-right on each monitor. |
| W-09 | Share the screen in Zoom/Teams/OBS with captions visible. | With content protection on (default) captions are invisible in the shared stream; turning it off in Settings makes them visible. |
| W-10 | Multi-monitor with mixed DPI (100 % + 150 %). | Docking positions are correct on both monitors; hit regions match the visible captions. |
| W-11 | Global shortcuts (defaults and a custom binding). | Each action fires from any app; a binding already used by another app shows a conflict message in Settings. |
| W-12 | Dictation into Notepad, Word, a browser text field and an elevated (admin) window. | Final text is typed into the focused field after the dictation ends. For the elevated window, Windows blocks synthetic input; the text is left on the clipboard so it can be pasted manually. With output set to "clipboard" the text is only copied. |
| W-13 | "Copy last 2 minutes" during a call. | Clipboard contains the last two minutes of captions with Me/Client labels. |
| W-14 | Disconnect the network during an upload; reconnect; also quit and relaunch mid-upload. | Upload resumes from the last confirmed chunk; local audio is deleted only after the server confirms. |
| W-15 | Idle resource check: tray only, auto mode on, 10 minutes. | CPU ≈ 0–1 %; RAM within the release budget (record the figure). |

## 3. Desktop — macOS 14.4+

| ID | Steps | Expected |
|---|---|---|
| M-01 | Install the notarized DMG on a clean Mac. | Gatekeeper opens it without warnings; `spctl -a -vvv -t install` and `xcrun stapler validate` pass. |
| M-02 | First recording. | Microphone prompt and system-audio-capture prompt both show the usage strings from `Info.plist`; denying either shows a clear in-app explanation. |
| M-03 | WhatsApp / Zoom / Meet call with auto mode on. | Process tap captures the far party but not EchoLedger; the aggregate device is created for the session and removed afterwards (Audio MIDI Setup shows no leftovers). |
| M-04 | Change the default input/output mid-call (AirPods connect). | Capture follows the new devices; no crash; channels aligned. |
| M-05 | Overlay and dictation. | Same as W-08 and W-12; dictation asks for Accessibility permission once and types into TextEdit, Notes and Safari. |
| M-06 | App runs as a menu-bar app. | No Dock icon; quitting from the menu ends all capture and removes the tap. |

## 4. Android

Run on **one Pixel (Android 15 or 16)** and **one Samsung (One UI on Android 14+)**; spot-check on an
**Android 12/13** device.

### 4.1 Install, pairing, storage

| ID | Steps | Expected |
|---|---|---|
| A-01 | Install the release APK (signed per `mobile/RELEASE.md`). | `apksigner verify --print-certs` shows the release certificate; Play Protect flow completes; app opens to the pairing screen. |
| A-02 | Pair three ways: tap **Scan QR code** and scan the code shown in Admin → Devices → Pair a device; type the server address + code; paste the QR text into the code field. | The camera permission prompt appears once; a valid scan pairs immediately and the library loads with icon + text badges for Phone / Desktop / Web items. Scanning an unrelated QR code shows "That isn't an EchoLedger pairing code" and pairs nothing. |
| A-02b | Deny the camera permission, then press Scan QR code; also press Back while the scanner is open. | A message explains that the code can be entered by hand; Back closes the scanner without pairing; the camera indicator turns off as soon as the scanner closes. |
| A-03 | Inspect app storage (`adb shell run-as` on a debug build). | Recordings under `no_backup/recordings/*.elr` are not playable (encrypted); `library.bin` is not readable JSON; no plaintext `.ogg` remains in `cache/` after saving. |
| A-04 | Pinning: point the app at a server whose certificate does not match the pins. | All API calls fail; the app shows the offline message; nothing is uploaded. |

### 4.2 Call capture (Android 14+)

| ID | Steps | Expected |
|---|---|---|
| A-10 | Setup → Connect via Shizuku (Shizuku started with Wireless debugging). | Helper status turns green with source `shizuku` and uid 2000. |
| A-11 | Setup → copy the ADB command; run it from a computer. | Helper status turns green with source `adb`. A third-party app calling the hand-off provider is rejected (verify with a test app or `adb shell content call` as a non-shell UID). |
| A-12 | Arm auto-record; lock the phone; receive a WhatsApp **voice** call; talk 1 minute; hang up. | During the call the user hears the far party normally (loop-back render); the "Recording call" notification is visible; **no** prompt appears during the call. After hang-up a silent "Save this recording?" notification appears. |
| A-13 | Tap **Keep** on that notification. | Upload completes (Wi-Fi or mobile data); the session appears on desktop and web as Phone · WhatsApp with Me (left) and Client (right) transcribed separately. |
| A-14 | Repeat with **Discard**. | The recording disappears locally; nothing is uploaded. |
| A-15 | Repeat A-12 with WhatsApp **Business** and with a WhatsApp **video** call. | Source app is WhatsApp Business / WhatsApp respectively; both channels captured. |
| A-16 | Ring a WhatsApp call and reject it / let it go unanswered. | Nothing is saved and no prompt appears (calls under 3 s are discarded). |
| A-17 | Record a call with captions enabled and the overlay permission granted. | The caption bubble shows Me/Client turns, does not take focus, does not block the call UI, and passes touches through. |
| A-18 | Reboot the phone with auto-record armed. | After reboot the helper is gone; the Setup screen explains how to restart it (Shizuku or ADB). Re-arming works without re-pairing Wireless debugging. |
| A-19 | Samsung: leave the phone idle overnight armed, then call. | With Battery set to Unrestricted the call is still captured; if not, the setup guidance is accurate. |
| A-20 | Android 12/13 device. | Auto-record switch is disabled with "Requires Android 14 or later"; manual recording uses the microphone only. |

### 4.3 Manual recording surfaces

| ID | Steps | Expected |
|---|---|---|
| A-30 | In-app REC button (no helper). | Microphone recording starts with a notification containing **Stop**; stopping uploads a mono `other` session. |
| A-31 | Home-screen widget: add it; tap record with the app in the background. | Recording starts (mic foreground service allowed from widget); widget shows the recording state and returns to idle after stop. |
| A-32 | Notification action **Record now** (armed notification). | Recording starts from the background without a crash. |
| A-33 | Quick Settings tile (Android 14+ and 13). | Tile toggles recording; on Android 14+ the shade collapses via the trampoline activity; no `ForegroundServiceStartNotAllowedException` in logcat. |
| A-34 | Revoke the microphone permission, then press REC / widget / tile. | No crash; nothing records; the Setup screen shows the missing permission. |
| A-35 | Toggle **Turn off** on the armed notification when the app process was killed. | No crash (`did not then call startForeground` must not appear in logcat). |

### 4.4 Voice notes and sync

| ID | Steps | Expected |
|---|---|---|
| A-40 | In WhatsApp, long-press a voice note → Share → EchoLedger (single and multiple notes). | Each note is imported as a Voice note session and uploaded; nothing is recorded automatically when voice notes play. |
| A-41 | Delete a session on the desktop. | It disappears from the phone after the next sync (pull to refresh / reopen). |
| A-42 | Revoke the phone under Admin → Devices. | The app shows "This phone was unpaired on the server" and stops syncing; pairing again restores access. |
| A-43 | Airplane mode while 3 recordings are queued; re-enable network. | WorkManager resumes and completes all uploads; no duplicates on the server. |

## 5. Signing and distribution

| ID | Steps | Expected |
|---|---|---|
| R-01 | Windows: verify the installer and the main executable with `signtool verify /pa /v`. | Valid signature chain with timestamp. |
| R-02 | macOS: verify notarization (`spctl`, `stapler validate`) on a Mac that never ran the app. | Accepted; no quarantine warning. |
| R-03 | Android: install the new release over the previous one. | Update succeeds (same signing certificate); local pending recordings and pairing survive. |
| R-04 | Optional desktop auto-update: publish a signed update manifest. | The app downloads, verifies and installs only when not recording; a tampered signature is rejected. |

## 6. Privacy checks per release

- No analytics, crash-reporting or telemetry traffic from any app (watch with a proxy for 10 minutes of use: only
  the EchoLedger server and the chosen speech provider appear).
- Search the desktop installer and the APK for provider key patterns (`sk_`, `xi-api-key`, AssemblyAI keys): none.
- A recording indicator (notification / tray state / overlay dot) is visible whenever audio is being captured.

## 7. v1.1 — recording decisions, call detection and app lock

### 7.1 Server and console

| ID | Steps | Expected |
|---|---|---|
| V-S1 | Sign in as an admin without two-factor and play a session's audio. | It plays. |
| V-S2 | On a session page, Tab to a transcript line and press Enter; focus the player and press Space. | Playback seeks to the line; Space plays and pauses. |
| V-S3 | With two-factor on, sign in with a recovery code and click "Confirm sign in" once. | Signed in on the first click. |
| V-S4 | Open a session page and API Credentials at 360 px width. | No horizontal scrolling. |
| V-S5 | Set Settings → Monthly limit to 1 minute, then start live captions on a device. | The device shows "Monthly transcription limit reached"; the recording continues. |

### 7.2 Desktop — call detection (Windows)

| ID | Steps | Expected |
|---|---|---|
| V-D1 | Auto-record on. Use voice input in ChatGPT, Claude and Gemini for 30 s each. | Nothing is recorded; the Record screen shows "Ignored: never record". |
| V-D2 | Record a 20 s WhatsApp Desktop voice message. | No recording; the explainer shows "Playing audio ✗ · Ignored: dictation". |
| V-D3 | Start WhatsApp, Zoom, Teams, Google Meet (Chrome) and WhatsApp Web calls. | Each records within about 3 s, labelled WhatsApp, Zoom, Microsoft Teams, Google Meet and WhatsApp Web. |
| V-D4 | During a Meet call, switch to a ChatGPT tab for a minute. | The recording continues as Google Meet. |
| V-D5 | Turn on "Ask me when an unrecognised app uses the microphone" and call on an unknown site. | The chip appears after about 3 s and closes after 8 s; Record, Ignore and "Always ignore this site" work. |
| V-D6 | macOS: with automation on, a Meet call in Chrome or Safari; then with it off. | On: recorded as Google Meet after the permission prompt. Off: ignored as an unknown site. |

### 7.3 Desktop — recording controls and Keep / Discard

| ID | Steps | Expected |
|---|---|---|
| V-D7 | Start a call recording while typing in another app. | The pill appears top-centre without taking focus; every button works on the first click; clicks around it pass through. |
| V-D8 | Pause, then resume. | The timer freezes, the dot turns amber and the OS microphone indicator turns off; resume continues. |
| V-D9 | Cancel → "Discard? Yes / No". | No restores the buttons; Yes discards with no review card. |
| V-D10 | Hide the pill, then use the tray item "Show recording controls". | The recording continues while hidden; the pill comes back. |
| V-D11 | Drag the pill to a second monitor and restart; then unplug that monitor and restart. | It reopens where it was; without the monitor it opens top-centre of the main screen. |
| V-D12 | Stop a recording. | The review card slides in bottom-right without taking focus; the countdown follows the timeout setting; Rename opens the Library. |
| V-D13 | Keep one recording, Discard another, let a third time out with "Keep" and with "Discard". | Kept ones upload and get a transcript; discarded ones leave nothing locally or on the server. |
| V-D14 | Quit from the tray while recording, then start the app again. | The recording is listed under "Waiting for your decision". |
| V-D15 | Share the screen in a call app. | The pill, the review card and the caption overlay are not visible to the other side. |
| V-D16 | Turn on the OS "Reduce motion" setting. | No pulse and no slide-in. |
| V-D17 | Watch the tray icon while idle, recording, paused, uploading and with a recording waiting. | The icon and tooltip change for each state. |

### 7.4 Desktop — captions, uploads and layout

| ID | Steps | Expected |
|---|---|---|
| V-D18 | Turn on captions during a call and let 50+ long turns arrive (include a long URL), with 2, 3 and 5 caption lines. | The header (drag, copy, close) stays inside the overlay; long text wraps; scrolling up shows "Jump to live", which returns to the newest line. |
| V-D19 | Record a call longer than one chunk and keep it. | It shows uploading, then verifying; its local folder is removed only once the server shows it ready. |
| V-D20 | Select a client, delete it in the console, then record and keep a call. | The toast says the client was removed; Settings shows no client; the recording uploads. |
| V-D21 | Stop the server during an upload, then start it again. | The upload finishes on its own; a failed item shows Retry and Discard. |
| V-D22 | Stop a recording that ran over 30 minutes. | Memory does not jump while it stops; the upload file plays completely. |
| V-D23 | Resize the main window to 860 px wide, then to 1030 px and wider. | The library stacks at 860 px and sits side by side from 1030 px, with no horizontal scrollbar. |
| V-D24 | In light and dark themes, check group labels, status pills, badges and primary buttons. | All are clearly readable. |
| V-D25 | macOS: build and run on a Mac. | The pill and review card appear and are hidden from screen recording. |

### 7.5 Android

| ID | Steps | Expected |
|---|---|---|
| V-A1 | With a PIN on the phone, switch Settings → App lock on: cancel the prompt, then try again and enter the PIN. Close and reopen the app. | Cancelling leaves it off; after the PIN it stays on; reopening asks to unlock before anything shows. |
| V-A2 | Lock after "Immediately", "After 1 minute": leave the app and come back. | Immediately locks at once; 1 minute stays open within 30 s and locks after a minute. |
| V-A3 | Android 10 with only a PIN (no fingerprint). | App lock can be switched on and unlocks with the PIN. |
| V-A4 | No screen lock on the phone. | The switch is disabled with "Set a screen lock on this phone to use App lock." and "Open security settings" works. |
| V-A5 | App lock and "Hide content in Recents and screenshots" on. | Recents shows a blank thumbnail and screenshots are blocked; turning the option off restores both. |
| V-A6 | Finish a WhatsApp call. | A heads-up "Save this recording?" shows app, duration, client and the first caption line; Keep and Discard work; it times out after 60 s with Keep or Discard, never with "Keep waiting". |
| V-A7 | Add the widget while a recording waits; turn App lock on. | The widget shows "N to review" and opens the waiting list; with App lock on it shows no titles or transcript text. |
| V-A8 | Start captions on a v1.1 server; let the monthly limit be reached. | Captions show "Monthly transcription limit reached" in the bubble; the recording continues and uploads after Keep. |
| V-A9 | Revoke the phone in the console, then open the app. | It returns to the pairing screen with "This phone was unpaired on the server. Pair it again."; recordings waiting to upload stay. |
| V-A10 | Rotate the phone on the library, a recording and the setup page. | Known issue from the emulator run: the screen returns to the library (fixed in a later phase). |

## 8. v1.1 — capture and control

Bookmarks, pauses, per-app auto-record, caption styles and capture health. The server side is covered by the
automated suite; these checks need real devices and calls.

### 8.1 Desktop

| ID | Steps | Expected |
|---|---|---|
| C-D1 | Settings → Auto-record apps: switch Zoom off, then join a Zoom call. Open the Record view. | No recording starts; the explainer says "Ignored: auto-record is off for this app". Switching Zoom back on records the next call. |
| C-D2 | During a call press Ctrl+Shift+B (Cmd+Shift+B on macOS), then click Mark on the pill. | Each press shows "Bookmarked m:ss" under the pill with the tag presets and Undo; the tray closes after about 6 s. |
| C-D3 | Tag a bookmark "Budget", undo another, finish and keep the recording. Open it in the console and in the desktop library. | The console player and the desktop library show the remaining bookmarks at the right times; clicking one seeks the audio there; the library filter "Budget" finds the recording. |
| C-D4 | Pause a call for 30 s, resume, keep it. | The file has no gap; the console timeline shows the pause marker at the right point; captions stop while paused. |
| C-D5 | Settings → Caption style: Large, Wide, 50 % opacity, compact, auto-hide 5 s, a custom Client colour. | The caption overlay resizes and restyles at once; speakers are coloured without labels; it fades after 5 s of silence and returns with the next caption. |
| C-D6 | Settings → Microphone: pick a USB headset, run "Test 10 seconds" while speaking and playing a video. | Both meters move; the result says both sides were heard and plays back. Unplugging the headset falls back to the default microphone with the "not connected" note. |
| C-D7 | Windows: block microphone access for desktop apps in Privacy settings. | Settings → Microphone permission says "Blocked" and "Open settings" opens the Windows microphone page. |
| C-D8 | Record a call while the other side stays silent (mute the call audio) for 30 s. | The pill shows "Not hearing the other side for 30 s"; it disappears once the other side is heard; switching the warning off in Settings stops it. |

### 8.2 Android

| ID | Steps | Expected |
|---|---|---|
| C-A1 | Settings → Capture and captions: switch WhatsApp off with auto-record armed; take a WhatsApp call. | Nothing is recorded; the row explains it is off for WhatsApp; WhatsApp Business calls still record. |
| C-A2 | During a call use the notification's Pause, then Resume, then Mark. | The notification title shows "Paused ·" while paused; Mark shows "Bookmarked m:ss"; the Record tab shows the same with the tag chips and Undo. |
| C-A3 | Keep the call and open it in the library. | The bookmark chips seek the player; the library shows "N bookmarks" and the tag filter finds it. |
| C-A4 | Caption style: Large, top, compact, 30 % less opacity, auto-hide 10 s. | The floating bubble follows every setting on the next recording. |
| C-A5 | Microphone: pick a Bluetooth headset and run the 10-second test, then play it back. | The meter moves; the verdict says the microphone works; playback is audible. |
| C-A6 | Permissions: revoke the overlay permission and the notification permission. | Both rows show their reason and "Fix" opens the matching system page. |
| C-A7 | During a call through the helper, mute the far side for 30 s. | The Record tab and the caption bubble say "Not hearing the other side for 30 s". |

## 9. v1.1 — transcript experience

### 9.1 Console

| ID | Steps | Expected |
|---|---|---|
| T-W1 | Open a finished session; play it at 1.5×, turn on Skip silence, press J, K, L and the arrows on the player. | Speed and silence skipping work; J/L jump 10 s, arrows 5 s, Space/K play and pause; the spoken word is highlighted when the engine gave word timings. |
| T-W2 | Edit text: change a line and leave the field; Edit → Rename speakers with "remember for this client"; then open another recording of the client. | The line is saved (no timing change); the names show on both recordings. |
| T-W3 | Edit → Find and replace a word, then Edit → History → Restore original. | The count is reported; History lists the change with your name; Restore brings back the engine's text. |
| T-W4 | Export → Word, Print or save as PDF, Subtitles (.srt) on an Urdu transcript. | Word opens with right-to-left Urdu lines; the print page saves a PDF with correct Urdu shaping; the SRT plays in a video player. |
| T-W5 | Share → 1 hour, a password and the recording; open the link in a private window. | The password page appears, then the transcript and player; the link stops working after Revoke or an hour; Share links lists the views. |
| T-W6 | ⋯ → Transcribe with another engine (Urdu); when done ⋯ → Compare versions → Use the second version. | Changed words are highlighted side by side; the chosen version becomes the transcript on every device. |
| T-W7 | Search page and Ctrl/Cmd+K: type a word from a transcript. | Results show the matching line with the word highlighted; choosing it opens the recording at that moment. |
| T-W8 | Settings → Vocabulary: add a keyterm and a correction; record a new call. | The correction is applied to the new transcript and can be undone with Restore original. |

### 9.2 Desktop

| ID | Steps | Expected |
|---|---|---|
| T-D1 | In the library, type two letters of a word spoken in a call. | "In transcripts" lists the line; clicking it opens the recording and plays from there. |
| T-D2 | Edit text, Find and replace, Speakers, History and Export → Word / Print or save as PDF. | The same results as in the console; the Word file lands in Downloads; the print page opens in the browser. |
| T-D3 | After correcting a name in a line, use the vocabulary suggestion. | The name is added to the client's keyterms (visible in the console). |
| T-D4 | Ctrl/Cmd+K → "pause", "bookmark", "go to settings" and a transcript word. | Each command runs; the word opens the recording at the moment. |
| T-D5 | Settings → Transcription language: English + Urdu; record a mixed call. | The session's language is en+ur and the transcript keeps both languages. |

### 9.3 Android

| ID | Steps | Expected |
|---|---|---|
| T-A1 | Open a recording; choose 1.5× and Skip silence; follow the highlighted word. | Speed and silence skipping work; the speaker label shows once per turn with renamed names. |
| T-A2 | Export → PDF (print) and Word; Share link with a password. | The print dialog saves a correctly shaped PDF; Word opens in a document app; the link opens as on the web. |
| T-A3 | Library search for a spoken word. | "In transcripts" shows the line; tapping it plays from that moment. |
| T-A4 | Settings → Transcription: Urdu; add a vocabulary term. | New recordings are sent with Urdu; the term appears for the selected client in the console. |

## 10. v1.1 — dictation

Dictation must never create a recording, a library item or a Keep / Discard prompt. Check the library and the
notification shade after every step.

### 10.1 Desktop

| ID | Steps | Expected |
|---|---|---|
| DI-D1 | Settings → Dictation: Press to start. In Notepad, press the shortcut, say "hello team new paragraph thanks", press again. | A start sound, the flow bar moves while you speak, then a stop sound; "Hello team." and "Thanks." appear on separate paragraphs at the cursor. |
| DI-D2 | Switch to Hold to talk; hold the shortcut, speak, let go. | Text is inserted when you let go; a very short tap inserts nothing. |
| DI-D3 | Windows: copy some text, keep the output on "Type into the focused app", then dictate into an app running as administrator (typing into it is blocked). | The text is pasted instead, and about a second later the earlier clipboard content is back. |
| DI-D4 | Add the replacement "echo ledger → EchoLedger" and the snippet "insert signature"; dictate both. | The spelling is fixed and the signature text is inserted, line breaks included. |
| DI-D5 | Turn Clean up off and dictate "um so yes". | The text is inserted as spoken, without capitals or a full stop. |
| DI-D6 | Recent dictations: Copy one, then Clear history; restart the app. | Copy puts the text on the clipboard; the list stays empty after the restart. |
| DI-D7 | Turn Start and stop sounds off. | Dictation is silent. |

### 10.2 Android

| ID | Steps | Expected |
|---|---|---|
| DI-A1 | Settings → Dictation → Turn on the voice keyboard; enable "EchoLedger voice keyboard", then pick it in a messaging app. | The keyboard shows the microphone, space, delete, enter and switch keyboard. |
| DI-A2 | Tap the microphone, say "send the invoice new line thanks", tap again. | Beeps at start and stop, the flow bar and phrase update live, the text lands at the cursor on two lines. |
| DI-A3 | Choose Hold to talk; hold the microphone, speak, let go. | The text is inserted on release. |
| DI-A4 | Revoke the microphone permission, open the keyboard. | "Allow the microphone in EchoLedger" opens the app; the microphone stays disabled. |
| DI-A5 | Airplane mode, then tap the microphone. | A short "No connection" message; nothing is inserted and nothing is recorded. |
| DI-A6 | Replacements, snippets, clean-up off and Recent dictations (copy, clear). | Same results as DI-D4 to DI-D6. |
| DI-A7 | Switch keyboard button. | Returns to the previous keyboard (or shows the keyboard picker). |

## 11. v1.1 — organising the library

### 11.1 Console

| ID | Steps | Expected |
|---|---|---|
| O-W1 | Sessions: open each tab (Today, This week, Waiting for decision, Bookmarked, Failed uploads, Pinned). | Each tab lists only its recordings; the badge matches the count. |
| O-W2 | Select three sessions → Move to client, Add label, Pin; then Remove label and Unpin. | Client, labels and the star change on all three; phones and the desktop show it after their next sync. |
| O-W3 | Select sessions with and without transcripts → Export transcripts (Word). | One zip downloads with a .docx per finished transcript; with none finished, a warning instead. |
| O-W4 | Clients: give a client an avatar (two letters or an emoji) and pin it. | The folder shows the avatar and comes first everywhere. |

### 11.2 Desktop

| ID | Steps | Expected |
|---|---|---|
| O-D1 | Library: try each smart view chip, the Client folder and Label filters. | The list and counts match the console. |
| O-D2 | Open a recording: Pin, choose a client (also New client…), add and remove labels. | The star and labels show on the card at once and in the console. |
| O-D3 | Select → pick several recordings → Export transcripts → Word. | A zip lands in Downloads; Delete asks once more and removes them on every device. |

### 11.3 Android

| ID | Steps | Expected |
|---|---|---|
| O-A1 | Library: smart view chips, folder and label chips. | Same lists and counts as the desktop. |
| O-A2 | Long-press a recording, tap two more, then Move to, Add label, Pin. | The changes show after the sync; Done leaves selection mode. |
| O-A3 | Selection → Export as zip. | The share sheet offers the zip. |
| O-A4 | Open a recording → Organise: pin, client, labels. | Saved and visible in the library list. |

## 12. v1.1 — security centre

### 12.1 Console

| ID | Steps | Expected |
|---|---|---|
| S-W1 | Security → Add a passkey (Windows Hello, Touch ID or a phone), sign out, then Sign in with a passkey on the login page. | Signed in without the password or the two-step code; the audit log shows "Signed in with a passkey". |
| S-W2 | Remove the passkey; try it again. | The sign-in is refused with a plain message; the password still works. |
| S-W3 | Sign in from a second browser; on Security, sign that browser out. | The other browser is back at the login page on its next click. |
| S-W4 | Delete a recording, export a transcript, make and revoke a share link, change a provider key. | Each appears in the audit log with who did it; no key or password appears anywhere. |

### 12.2 Desktop

| ID | Steps | Expected |
|---|---|---|
| S-D1 | Settings → Privacy & security: set a PIN, turn on Lock EchoLedger, choose After 1 minute; wait. | The window shows "EchoLedger is locked"; Windows Hello (or Touch ID) is offered at once. |
| S-D2 | While locked, record a call from the tray or auto-record. | Recording, captions and uploads carry on; the library stays hidden until unlocked. |
| S-D3 | Type a wrong PIN five times. | The sixth try is refused with "Try again in 30 seconds"; the right PIN works after the wait. |
| S-D4 | Revoke another device under Devices. | It disappears there and in the console; that device returns to pairing on its next sync. |

### 12.3 Android

| ID | Steps | Expected |
|---|---|---|
| S-A1 | Settings → Devices and activity. | Lists this phone and the other devices, and recent activity with who did it. |
| S-A2 | Revoke another device (tap Revoke, then Revoke now). | It is gone from the list and the console. |

## 13. v1.1 — retention and the undo bin

| ID | Steps | Expected |
|---|---|---|
| R-1 | Console Settings: choose 7 days; give one client 30 days; mark one old recording Keep. Run `php artisan recordings:retention`. | Recordings older than their retention move to Recently deleted, except the kept one and the client's younger ones. |
| R-2 | Delete a recording on the phone; on the desktop open Recently deleted and Restore it. | It comes back on every device after the next sync, with its audio and transcript. |
| R-3 | Delete a recording and try its old playback link or share link. | Both answer "not found" at once. |
| R-4 | Leave a deleted recording for 48 hours (or run the command after moving its delete time back). | It disappears from Recently deleted; Restore says it can no longer be restored. |
| R-5 | Console dashboard, desktop Settings and phone Settings. | The storage meter shows the recordings' size and the server disk; the retention days match the console. |
