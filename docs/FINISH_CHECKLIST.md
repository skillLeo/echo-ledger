# EchoLedger v1.3 — finish checklist

The single list of what is left before v1.3.0. Tick an item only when its acceptance test passes. Items marked
**(phone)** are checked on the S25 in one of the two phone windows; until then they are built and covered by
laptop tests.

## Needs Hassam

| # | What | Why | Exact steps |
|---|---|---|---|
| H1 | Phone window 1 (~30 min) | B0 probe, game box, Watch subtitles on the real phone | Say "phone free"; open PUBG Mobile / CODM with squad voice chat, YouTube, Netflix when asked. |
| H2 | Phone window 2 (~45 min) | Final install and call checks | Say "phone free"; make the calls listed under "Phone window 2" when asked. |
| H3 | TurboScribe reference texts | A5 needs the corrected text of 3 recordings (English, mixed Urdu + English, Arabic) | Paste each text in chat (or in the console: recording → ⋯ → Use as Accuracy Lab reference). |
| H4 | Signed APK | The phone runs a debug build; a release build needs the keystore | In chat: keystore path (`C:\echoledger-keys\…`), alias, both passwords. Switching the phone to the signed build needs one uninstall (data is wiped): OK only after nothing waits to upload. |
| H5 | Soniox credit | Live Translate text and Soniox async in the Accuracy Lab: Soniox answers 402 organization_balance_exhausted | soniox.com → Billing → add funds (or enable autopay). Nothing else to change: the key works. |

## Phase C — design

- [x] C1 design system, C2 navigation, C3 Library (`5c1f1bc`, `7e6dc0a`, `91046b1`).
- [x] C4 rest — controls under the timer, control pill, floating Keep/Discard card, live card (`a1b3f68`; the floating card gained "Add title or client", which opens the in-app card: the overlay stays non-focusable over calls and games, `3dadb5f`). *Accept:* `phone-record-live-*` and `phone-pill-*` screenshots reviewed; `RecordScreenTest`, `PillMachineTest` green.
- [x] C5 recording page — find in transcript, long-press a line to copy/share/play, title into the bar on scroll, compact error row (`3dadb5f`; `SessionScreenTest` +3). *Accept:* `SessionScreenTest` covers find + long-press; screenshots light/dark.
- [x] C6 Game and Watch pages (`3dadb5f`: Start/Stop, health card, Test translate over a sample frame, the mode panels; screenshots still to record in C10) — start/stop, picked apps with icons, presets, live preview over a sample frame, test button. *Accept:* screenshots + `LiveTranslateScreensTest`.
- [x] C7 You — General (a list of its own pages) + Voice notes & files + Calls & meetings + Game translate + Watch translate panels, each with "Reset this panel"; settings search; sign out at the bottom; Clients list with count/last call; Activity Needs action / Today / Earlier + Mark all as read. *Accept:* `SettingsPanelsTest`; screenshots.
- [x] C8 lock, onboarding, splash, app icon, notification channels (`df8781a`, `617e720`).
- [x] C8 permissions health card (`PermissionHealthCard` on the Game and Watch pages) with one-tap fixes (display over apps, helper armed, notifications, never-sleeping apps). *Accept:* unit test of the card's checks; shown on You and in Game setup.
- [x] C9 copy pass (upload errors and the shared-note failure say what to do; ✓ ✗ ★ ☆ glyphs replaced by icons; `CopyRulesTest` keeps it so) — sentence case, consistent verbs, no emoji, every error says what happened and what to do. *Accept:* `CopyRulesTest` scans strings/UI text for title case on buttons, emoji, "Error:"-only messages.
- [x] C10 quality (laptop part: `AccessibilityTest` — every tappable element named, unnamed switches fixed; 200 % text renders `phone-*-font200-*`; Library rows keyed with contentType; hand-written Baseline Profile `baseline-prof.txt`; renders reviewed — Watch health note and General summary fixed. Cold start and gfxinfo need the phone: window 2) — TalkBack labels on every icon button, 48 dp targets, 200 % font renders, 1,000-row Library smooth (stable keys, contentType), Baseline Profile, screenshot review of every screen light/dark. *Accept:* `AccessibilityTest` (unlabelled icons = 0), `ScreenshotsTest` at 2.0 font scale, baseline-prof.txt in the APK. Cold start < 1 s **(phone)**.
- [x] C12 row → recording shared transition (`3dadb5f`; skipped when animations are off). *Accept:* `MainActivityTest` opens a row with the transition; no crash with animations off.
- [x] C12 server "opened" + dot (`d2f397a`, `ea6e4f3`), recent searches kept, Activity swipe-to-dismiss (`0b584c2`).

## Phase A — remainder

- [x] A5 Accuracy Lab ready to run (`AccuracyLabTest` 5/5; running it needs H3, and Soniox async needs the credit) — categories English / Urdu + English / Arabic, Groq, Soniox async and AssemblyAI selectable in Compare engines, before/after report. *Accept:* `AccuracyLabTest` green with 3 fixtures. Running it needs H3.
- [ ] A6 on-phone checks — Roman Urdu default, Copy for AI **(phone, window 2)**.

## Phase B — Live Translate

- [x] B0 Audio probe (built `3dadb5f`; the findings table comes from phone window 1) in Help & diagnostics (helper: active players with uid/usage/content type/capture policy, "Capture this player for 5 s" + playback); UID mix armed before the game starts. *Accept:* `AudioProbeTest` (parsing, arming rules, games excluded from call apps). Findings table **(phone, window 1)**.
- [x] B1 engine (`3dadb5f`, server `e987f4f`; PCM — Ogg-Opus only if window 1 shows Soniox takes it) — Soniox real-time, temporary keys, PCM (Opus only if Soniox accepts it), speech gate with 300 ms pre-roll, keepalive, reconnect with backoff, key renewal, 300-min limit, offline notice once, budget guard ($10/month, warn 80 %, pause 100 %, override), minutes per mode, debug HUD. *Accept:* `LiveTranslateTest`, `SpeechGateTest`, `TranslateBudgetTest` green.
- [x] B2 floating box (`3dadb5f`) — one overlay window, View-based, ≤ 10 updates/s, Locked / Edit / Hidden / Paused / Stopped, grip dot + toolbar, notification, QS tiles, app shortcuts, styles, fade, thermal guard, per-app/orientation memory. *Accept:* `TranslateBoxMachineTest`, `BoxPlacementTest`. Touch pass-through + FPS **(phone, window 1)**.
- [x] B3 Game mode (`3dadb5f`; "Save session text" writes Downloads/EchoLedger: a text-only Library item needs a server session type that has no recording — left for later) — setup screen (games, permissions, Test translate with TTS over a sample frame), auto-start/stop by game playback, per-game profile, profanity mask, Save session text. *Accept:* `GameModeTest`.
- [x] B4 Watch mode (`3dadb5f`; .srt to Downloads/EchoLedger) — source app or "whatever is playing", Urdu default, subtitle style, pause with playback, stop after 2 min silence, opt-out apps say "This app doesn't allow live captions", Save .srt. *Accept:* `WatchModeTest`, `SrtWriterTest`.
- [x] B5 four modes (`3dadb5f`, `e987f4f`, `4ea46df`: the phone follows the console defaults until the person changes a panel) on the Record sheet + settings panels + migration + web console mirror (Settings → Live Translate defaults, budget). *Accept:* `SettingsMigrationTest`; Pest `LiveTranslateSettingsTest`.
- [x] B6 desktop (`4ea46df`; Windows; macOS later) — Game/Watch through per-app loopback → Soniox → caption overlay, click-through when locked, Ctrl+Shift+T / Ctrl+Shift+H, "borderless or windowed only" note. *Accept:* vitest + cargo tests for the session and overlay state.
- [x] B7 privacy and cost (`docs/research/soniox-live-translate.md`; estimate on the Game/Watch pages and panels; USER_GUIDE section at release) — `docs/research/soniox-live-translate.md` (retention with sources, silence billing), cost and data per hour in each panel, USER_GUIDE section. *Accept:* doc present, panels show the estimate.
- [x] B8 tests (unit tests green; device tests L1-L12 in MANUAL_QA) — all unit tests above green; device tests added to MANUAL_QA.md.

## Bug sweep

- [x] Open items from HANDOFF/MANUAL_QA: the desktop English-only review-card note now has two tests; "auto-record back on after an update" is checked in phone window 2.
- [x] Production logs, last 7 days: 0 failed jobs; 5 failed transcripts 27-28 Oct, all silent calls of the old helper (fixed 29 Oct); log errors known or from diagnostic one-liners. `af890d9` (AssemblyAI 400 while processing) ships with this deploy.
- [x] Phone crash risks: a 200 with an empty or unreadable body crashed callers (null into `!!`, SerializationException); now an IOException with words for people (`ApiClientErrorHandlingTest`). No runBlocking in app code; receivers are light.
- [x] Desktop errors: no log files on this machine; `unwrap()` only on mutex locks and in tests; new IPC calls all catch.

## Release

- [x] Milestone suites green (Phase C + Phase B): phone 1064 unit tests + ktlint + lint 0 errors; web Pest 3295/3295, PHPStan 0, Pint clean; desktop vitest 1060/1060 (after the IPC contract fix), tsc clean, cargo test all ok, clippy -D warnings clean. Final run before the tag.
- [x] Web deploy v1.3.0 (2026-10-30 23:55 UTC): backup `~/echoledger-backups/pre-v1.3.0-20261030-235514/` (34 tables, Dump completed; laptop copy `C:\echoledger-backups\pre-v1.3.0-20261030-235514\` hash-equal), down → 23 files → migrate (session_opens) → optimize → filament:optimize → queue:restart → up. Verified: 23/23 files = HEAD, migration Ran, new routes 401 without a token, /admin/login 200, /.env 403, 0 jobs / 0 failed, no new errors.
- [x] Desktop installer built: `C:\echoledger-releases\v1.3.0\EchoLedger_1.3.0_x64-setup.exe` (NSIS, unsigned: Authenticode needs a code-signing certificate).
- [x] APK: debug build 1.3.0 (versionCode 9) `C:\echoledger-releases\v1.3.0\EchoLedger-1.3.0-debug.apk` (installs over the phone's debug build keeping data). Signed build waits for H4.
- [x] USER_GUIDE (§16 Live Translate), MANUAL_QA (L1-L12, P1), HANDOFF updated; tag `v1.3.0`; pushed.

## Phone window 1 (after Phase B is built)

Steps (about 30 minutes; I install over adb keeping app data, nothing is uninstalled or cleared):
1. I install the new debug build (`pm install -r`) and restart the capture helper (its version changed).
2. You open EchoLedger → You → General → Help & diagnostics → **Audio probe**.
3. Start PUBG Mobile, join a squad with voice chat on, come back to the probe: **Capture 5 s** on PUBG's voice-chat
   row and on its game-sound row, then **Play**. Same for CODM, then YouTube (any video), then Netflix.
4. **Copy findings** and paste them in chat (I read the capture log too).
5. Game translate → choose PUBG, **Start**; play one match: tap through the box, hold the dot, move and resize it.
   I read FPS from SurfaceFlinger before and after (read-only).
6. With Soniox credit: Watch translate → YouTube → **Start**, a minute of an English video.

- [ ] B0 probe: PUBG Mobile / CODM voice chat, YouTube, Netflix (usage, separable voice, MMAP bypass, arm timing, blocked apps).
- [ ] Game box over a real game: touches pass through, move/resize, FPS before/after.
- [ ] Watch subtitles on YouTube.

## Phone window 2 (final)

Steps (about 45 minutes): I install the release build keeping app data (or, only with your OK, the one-time switch to
the signed build: uninstall + re-pair), then **you do not open the app**: I check from the capture log that auto-record
came back by itself. Then, one at a time, with the other phone: share a WhatsApp voice note to EchoLedger; a 4-minute
WhatsApp call; a 40-second WhatsApp call muting yourself for 10 seconds in the middle; a WhatsApp Business call; a
normal SIM call; a Google Meet or Zoom app call. After each I check the log, the WAV levels (both sides) and the
transcript. Last: open an Urdu call → Roman Urdu view → Copy for AI → paste into a note; then screenshots of each main
screen in light and dark (I take them with `screencap`).

- [ ] Install keeping app data (or the one-time signed switch with OK).
- [ ] Share a WhatsApp voice note → opens in EchoLedger.
- [ ] 4-minute WhatsApp call recorded whole (`stopped: call ended`).
- [ ] 40 s call with a 10 s mute (`self_muted` / `self_unmuted`).
- [ ] WhatsApp Business call, SIM call, Meet or Zoom app call: both sides.
- [ ] Roman Urdu view + Copy for AI.
- [ ] Auto-record back on after the update without opening the app.
- [ ] Screenshots of every main screen, light and dark.
- [ ] The app never opens by itself; no data wiped.
