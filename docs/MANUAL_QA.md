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
| S-02 | Sign in to `/admin` for the first time. | MFA enrolment is forced; a real authenticator app (Google Authenticator, 1Password, Aegis) accepts the QR; recovery codes are shown once and each works exactly once. |
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
