# EchoLedger v1.5 — finish checklist

The single list of what is left before v1.5.0. Tick an item only when its acceptance test passes. **(phone)** = checked
on the S25 in a phone window; **(mac)** = checked by Hassam on the MacBook from MANUAL_QA.

## Needs Hassam

| # | What | Why | Exact steps |
|---|---|---|---|
| N1 | Groq Developer plan | Urdu stays on Groq for long calls (the free plan allows 2 h of audio per hour and 8 h per day) | console.groq.com → Settings → Billing → upgrade to Developer; then say "Groq upgraded". |
| N2 | TurboScribe texts of 5 Urdu/mixed calls | Accuracy Lab: before/after accuracy per engine | Pick 5 calls in the Library, run them through TurboScribe, correct the text, send the 5 texts with each recording's title and time. |
| N3 | Gemini API key (paid tier) | Gemini as the Urdu challenger | aistudio.google.com → API keys, on a project with billing → enter it in the console: API Credentials → Gemini (not in chat). |
| N4 | Screenshots of desktop bugs you've seen | Desktop bug sweep (1.3) | One message with the screenshots and a line each. |
| N5 | Phone window, ~20 min (Phase 1) | Which "connected" signal works on the S25 | Say "phone free"; I install keeping data and ask for: one WhatsApp call that rings unanswered, one answered WhatsApp call (talk 20 s), one answered SIM call. |
| N6 | Mac test (from v1.4) | Mac call capture needs macOS 14.2+ (this Mac has 27.3) | A Mac window of about 30 minutes: MANUAL_QA "Mac app v1.4" with the CI build (needs a free hour on this Mac and two test calls). |
| N7 | Google sign-in client (Phase 4) | "Sign in with Google" on the console, phone and computer | After the accounts deploy: console.cloud.google.com → APIs & Services → Credentials → Create OAuth client ID → **Web application**; Authorised redirect URI exactly `https://<console address>/auth/google/callback` (API Credentials → Google sign-in shows it); no JavaScript origins needed. OAuth consent screen: External, app name EchoLedger, a support email, the console domain as authorised domain, `https://<console address>/privacy` and `/terms` as policy links; scopes openid, email, profile only; publishing status **In production**. Paste the client ID and secret into API Credentials → Google sign-in (not in chat). |
| N8 | ~~SMTP account and DNS~~ **Done 2026-11-08** (Settings → Email, HANDOFF) | | |
| N9 | ~~A teammate test account~~ **Done 2026-11-08**: the business mailbox, a member of the super admin's workspace | | |
| N10 | Review the legal drafts (Phase 4.7) | Terms and Privacy before anyone else signs up | Read the drafts and send changes. One point to decide: Privacy says files are erased after the 48-hour bin, but a nightly backup or a versioned bucket can keep them up to 30 days. Say whether it should state that. |
| N11 | Paid accounts before a public release | Store and signing | Google Play $25 once; Apple Developer $99/year; Windows code-signing certificate; a VPS (about $10–20/month). |
| N12 | Keystore (optional) | Signed APK | Path, alias and passwords at build time, or keep debug builds. |
| N13 | Soniox credit | Live Translate | soniox.com → Billing → add funds. |
| N14 | Back up the desktop updater key | Installed desktop apps only take updates signed with it; losing it means reinstalling by hand | Copy C:echoledger-keysdesktop-updater.key (made 2026-11-04, no password yet) to your password manager or an offline drive. Say if you want a password on it before the public release. |
| N15 | ~~Production SSH from the Mac~~ **Done 2026-11-08** | | |
| N16 | Three files from the Windows laptop | (1) The phone takes updates only when they are signed like its installed app, which the laptop built: without the laptop's debug key every build from this Mac needs an uninstall (your recordings and settings on the phone would go). (2) The desktop updater's private key (N14) lives only there. | Copy to this Mac: `%USERPROFILE%\.android\debug.keystore` → `~/echoledger-keys/laptop-debug.keystore`; `C:\echoledger-keys\desktop-updater.key` and `.pub` → `~/echoledger-keys/`; optional, `C:\echoledger-releases\v1.5.0-beta.2\` → `~/echoledger-releases/`. Use a USB drive or AirDrop, not chat or e-mail. Then say "keys copied". |
| N17 | A real Mac test (Phase 1B A6) | The Mac check on a Mac with a simulated Meet call and screen share; permission dialogs need a person | Read docs/MAC_TEST_CLOUD.md (coming), rent the Mac, then say "Mac rented". You said you don't own a Mac, but this session runs on a Mac with macOS 27.3: if it stays available, tell me and the test can run here with no rental. |
| N18 | Firebase project for Test Lab (Phase 1B B5) | Smoke tests on physical Xiaomi, Oppo, Vivo, Pixel and Samsung A-series phones | console.firebase.google.com → Add project (the free plan is enough) → then on this Mac `gcloud auth login` and tell me the project id. No key goes in chat. |
| N19 | Three non-Samsung phones (Phase 1B acceptance) | Device reports from real phones | Ask teammates to install the APK, run Help & diagnostics → Device check and allow the upload (checklist in MANUAL_QA, coming); Test Lab results also count. |
| N20 | Connect the real Claude and ChatGPT (Phase 6) | Only a real connection proves the return addresses and the first sign-in | After the deploy: the steps are in docs/CONNECTOR.md. Five minutes each; tell me if either refuses, with its message. |

## Step 0

- [x] Plan saved locally (kept out of git); this checklist; HANDOFF, DESIGN_SYSTEM and the v1.4 checklist read.

## Phase 1 — Calls that don't disturb me + Urdu accuracy

- [ ] 1.1 Record only connected calls (phone): WhatsApp/VoIP and SIM recordings start at "connected" (call notification chronometer / call type, call log talk time for SIM, far-side ringback-vs-speech as the fallback); a rolling buffer from dial-out so nothing is lost; never connected → discarded silently; connected < 10 s (setting) or no speech either side → discarded silently, Activity shows "Skipped N short calls" with Restore for 24 h. *Accept:* `CallConnectDetectorTest`, `SkippedCallsTest`, `CallStateMachineTest`; **(phone)** an unanswered WhatsApp call leaves nothing, an answered one starts at pick-up.
- [x] 1.1 desktop: auto-recordings shorter than the setting or with no speech either side are discarded silently, "Skipped N short calls" with Restore for 24 h. *Accept:* Rust + vitest tests. **Done** (`d99dbc8`; no "not answered" on the desktop: it has no ringing signal).
- [ ] 1.2 After the call (phone, desktop, web): setting "After a call: Keep automatically (default) / Ask me"; keep = tiny "Saved · Call with Omar · 4:12 · Undo" for 6 s; ask = pill-size chip with title, ✓ and ✕, title opens rename/client, keeps itself after 10 s; the large Keep/Discard cards are gone; the Waiting list stays only for Ask me (and leftovers). *Accept:* phone `AfterCallTest` + screenshots, desktop vitest/Rust tests + screenshots, web Pest; **(phone)** the chip after a real call.
- [ ] 1.3 Desktop bug sweep: every screen and flow (record, auto-detect, captions, overlay, import, Library, recording page, settings, tray, updates); fixes with tests; Hassam's screenshots (N4) included. *Accept:* list of bugs found/fixed in HANDOFF; desktop suites green.
- [x] 1.4a Urdu/mixed never goes to a weaker engine: rate limits and outages wait on the same engine with backoff; after 6 h the next engine, labelled "Backup engine — Re-transcribe?" (API `backup_engine`). *Accept:* `UrduRoutingTest`; no Urdu/auto recording on AssemblyAI without `backup_engine`. **Live** 2026-11-04 (`ae9b1e7`, `9874784`, `d567972`).
- [x] 1.4b Groq limits: the plan's limits shown in the console (from Groq's rate-limit headers, defaults for the free plan) and requests paced under them. *Accept:* `GroqPacingTest`. **Live** (Developer plan; Groq's headers win).
- [x] 1.4c Gemini engine (current Pro model, paid tier, uploaded file deleted afterwards) with the strict verbatim rules; in routing and the Accuracy Lab. *Accept:* `GeminiDriverTest` (faked HTTP); a real run once N3 is in. **Live**, model gemini-3.1-pro-preview; key needed (N3 again: there was no Gemini row before this deploy).
- [x] 1.4d Accuracy Lab: Original and Roman Urdu scored separately, CER/WER per engine, winner per category applied to routing. *Accept:* `AccuracyLabTest`; real numbers once N2 is in. **Live**; real numbers wait for N2.
- [x] 1.4e Bluetooth mic warning "Accuracy is lower with Bluetooth mics" (phone and desktop). *Accept:* tests per app. **Done** (phone `BluetoothMic`, desktop classifier `056b858`).
- [x] 1.4f Roman Urdu word-count/alignment check on 20 recordings, reported. *Accept:* the command's report in HANDOFF. **Done** 2026-11-04 on production: 20 recordings, 12 OK, 6 to check, 2 with a problem (long lines lost up to 10 % of their words). Fix: stricter check (fewer words only 5 %, min 2) + word-by-word retry.
- [ ] Phase 1 suites: phone, desktop, web green; lints clean.

## Phase 1B — Runs everywhere

Mac:
- [x] A1 CI: Rust + frontend tests on Apple Silicon and Intel; the built .app is launched, opens its windows and quits cleanly (a crash fails the run); DMGs attached to each tag. *Accept:* both chips green. **Done** (`--smoke-test`; macos-15 Apple M1 and macos-15-intel run everything, macOS 26 on both chips opens the DMG). The release job has not run yet: the first real `v*` tag proves it.
- [ ] A2 Help & diagnostics → Run Mac check: macOS version (14.2+), mic, system-audio and screen-recording permissions, 5 s mic test, 5 s system-audio tap test with a tone, meeting-app detection (Zoom, Teams, WhatsApp, Chrome, Safari, Edge, Arc), overlay + pill visible, a screen-moment capture; ✓/✗ with a fix each, Copy report, opt-in upload shown per device in the admin panel. *Accept:* tests with mocks; **(mac)** passes on a real Mac. **Built** with mocks (`mac_check.rs`, MAC-15, MAC-16, MAC-30); the real Mac is N17.
- [x] A3 Permissions: one guided onboarding step each with Open System Settings; macOS 15's monthly re-approval explained; a missing permission never crashes or silently records nothing (clear banner). *Accept:* tests. **Done**; a refused start reaches the person with the window closed (`refusal.rs`) and a running recording re-reads its permissions every 3 s. Real prompts wait for N17 (MAC-11 to MAC-14, MAC-28, MAC-29).
- [x] A4 Unsigned builds: USER_GUIDE first launch on macOS 15+ (System Settings → Privacy & Security → Open Anyway); permissions re-checked at start after updates; the $99/yr Developer ID documented. *Accept:* docs + test. **Done**; MAC-1 proves the steps on a real DMG (N17).
- [ ] A5 Parity: menu-bar icon, ⌘ shortcuts; pill, overlay and toast respect the notch and menu bar; dark/light, Retina, several displays; overlays over fullscreen windows and Spaces; start at login; updater. *Accept:* tests; **(mac)**. **Built** with tests (MAC-17 to MAC-24); how it looks and behaves on a real Mac is N17. Updater reviewed, no code changed.
- [ ] A6 docs/MAC_TEST_CLOUD.md: renting a cloud Mac for a day, SSH + VNC, BlackHole for a simulated Meet call, the Mac check + Mac rows of MANUAL_QA; then fix what is found (N17). **Guide written**; the rental is N17.

Android:
- [ ] B1 Device check (onboarding, Help & diagnostics): voice notes/import, app calls both sides (Android 14+ and the helper, else "your voice only"), SIM calls, captions, Live Translate, with reasons and one-tap fixes; Copy report; opt-in upload (brand, model, Android, results) in the admin panel. *Accept:* tests. **Built** (`DeviceCheckTest`, `DeviceCheckScreenTest`, `DeviceReportTest`); live with the next deploy and phone build.
- [ ] B2 Honest fallbacks: "This phone can record only your voice in WhatsApp calls" up front; never a silent channel without saying so. *Accept:* tests. **Built**: said on the Record tab, in Setup and onboarding; Android 12 and 13 record your voice through the helper on one channel (emulators pass; Android 10 and 11 record no calls). Not yet run on a real phone there (N19).
- [ ] B3 Background survival per brand (Xiaomi/HyperOS, Oppo/Realme/OnePlus, Vivo, Huawei/Honor, Pixel, Samsung): exact steps and deep links; the helper re-armed after the OS kills it; vendor audio quirks caught and reported. *Accept:* tests. **Built** (`core/PhoneBrand`); the deep links need real phones to prove (N19).
- [x] B4 Helper: Shizuku and the built-in wireless-debugging helper with a setup guide per brand. *Accept:* guide + tests. **Simpler version shipped (time box):** the per-brand guide in Setup leads to Shizuku, which starts over Wireless debugging with no computer on every Android this helper can use (12 and newer), so a separate built-in one would do the same job with a pairing and TLS stack of its own to look after. Option B stays a computer's ADB. A built-in helper is backlog if Shizuku ever becomes a barrier.
- [ ] B5 Firebase Test Lab smoke tests (Xiaomi, Oppo, Vivo, Pixel, Samsung A; Android 10/12/14/15/16): install, onboarding, voice note, library, settings; no crash or ANR; 360 dp and 1.3x fonts; results reported (N18). **Script ready** (`tools/testlab.sh`); needs N18. The free plan allows 5 physical runs a day.
- [ ] B6 3 GB RAM phones stay smooth. *Accept:* a measured check (Test Lab or a profile). **Partly**: on a 3 GB Android 13 emulator the signed-in screens stay at 171 MB and nothing crashes. Smoothness is not proven: the emulator renders in software, so it needs a real phone (N19) or Test Lab (N18).
- [ ] B7 Teammate compatibility checklist in MANUAL_QA; device reports visible in the admin panel (N19). **Built** (MANUAL_QA "Teammate phone check", System → Device reports); the phones are N19.

Windows:
- [x] C1 Windows 10 below build 20348: the whole-system-audio fallback works and the UI says so. *Accept:* tests. **Done** with faked build numbers; no CI runner is below 20348, so WIN-1 is the first real run.
- [x] C2 CI smoke tests on Windows 10 and 11 runners. *Accept:* green, or the nearest runner GitHub still offers, noted. **Done**: GitHub has no Windows 10 client runner; green on Server 2022 (build 20348), Server 2025 (26100) and Windows 11 Arm (26200, x64 under emulation). The bare exe, not the installer.
- [x] C3 Bluetooth/USB headsets and switching audio devices mid-call keep the recording going. *Accept:* tests. **Done** (`audio/devices.rs`, Mac and Windows, with fakes); real headsets: MAC-25, MAC-26, WIN-3 to WIN-5.

Everywhere:
- [ ] D Supported devices on the download page and in USER_GUIDE, saying what works where. *Accept:* page test. **Done** (`DownloadsTest`, USER_GUIDE "What works where"); live with the next deploy.
- [ ] Acceptance: CI green on macOS (both chips) and Windows; the Mac check on a real Mac with a simulated Meet call and screen share; reports from 3 non-Samsung phones; docs updated. **CI green and docs done**; the real Mac is N17, the phones N19.

## Phase 1C — Easy, trustworthy, clear

Never miss a recording:
- [ ] A1 Ready check, one tap and automatic (phone: helper, permissions, mic, far-side test, server, storage, battery; desktop: devices, per-app capture, meeting detection, permissions incl. Mac): "✓ Ready to record calls" or each problem with a one-tap fix. **Phone done** 2026-11-08 (Readiness, ReadyCheckSheet; ReadinessTest); desktop with its helper.
- [ ] A2 Status chip "Calls: Ready" / "Not ready — Fix" on the Library top bar, the Record sheet, the desktop sidebar and tray. **Phone done** 2026-11-08 (Library top bar, Record sheet).
- [x] A3 Critical alert the moment readiness breaks (helper killed, not re-armed after reboot or update, permission revoked, mic blocked); quiet hours never silence it. **Done** 2026-11-08 (phone): ReadinessWatch on helper death (15 s), restart/update (60 s), every 15 min and app start; once per problem, cleared when ready.
- [ ] A4 One smart Record now: an active call or meeting is recorded through call capture (both sides), else a voice note; from the notification, Quick Settings tile, widget, app shortcut, in-call pill, desktop tray and Ctrl/⌘+Shift+R.
- [ ] A5 Watchdog: a call detected but not recording after 5 s starts by itself ("Recording started (backup)"); after each call both channels are checked and "Only your voice was recorded" says why and how to fix it. **Phone done** 2026-11-08: backup start after 5 s, after-call check of both sides (AfterCallCheck).
- [x] A6 Why the S25 lost its WhatsApp setup; setup survives reinstalls, updates and sign-in changes. **Found** 2026-11-08: a restart turns Wireless debugging off, which stops Shizuku and the helper; the banner wrongly said "one-time setup". Now: "The helper stopped when the phone restarted" with Open Shizuku, a critical alert a minute after the restart, and the helper starts by itself once Shizuku is back. Updates and sign-in changes never lost it.

Notifications:
- [x] B1 One Android channel per category (Recording, Transcription, Enhancement, Uploads, Call capture & readiness (Critical), Sharing, Account & security, Usage & limits, Live Translate, App updates, Other), mirrored in Settings → Notifications on phone and desktop; web: email preferences. **Phone done** 2026-11-08 (NotifyCategory; the old "Transcripts ready" channel is split, and kept off if it was off).
- [ ] B2 Per category on/off, sound and vibration where the OS allows, a Test button.
- [x] B3 Quiet hours with start and end; Critical bypasses them. **Phone done** 2026-11-08 (NotifyGate).
- [ ] B4 A category turned off in Android's own settings is shown with a link to that channel.

Clear progress:
- [ ] C1 Sharing several notes at once shows all of them, each with its own status.
- [ ] C2 Three steps with real progress: Upload (% of bytes), Transcribing (parts done, time left), Improving (views, % of lines). **Phone done** 2026-11-09 (the recording page's three steps, v1.6 P5.4).
- [ ] C3 The transcript shows as soon as it is ready, with a slim "Improving Roman Urdu… 60%" bar; views swap in without jumping. **Phone done** 2026-11-09 (the page loads the transcript by itself once ready; the Improving bar).
- [ ] C4 "Notify me when ready"; the same progress in the Library row and in Activity. **Phone done** 2026-11-09 (ReadyWatchWorker; the Library row since I3).

Group by and sort by:
- [ ] D1 Group by Date (default), App/Type, Project, Language, Status, Device, None; sort by Newest, Oldest, Longest, Shortest, Name A–Z, Recently opened; remembered per view. **Phone done** 2026-11-09 (v1.6: LibraryGrouping, the choice per smart view in the phone's settings, never exported; Recently opened from the openings on this phone; LibraryGroupingTest).
- [ ] D2 Group headers with name and count, collapse and expand, sticky.
- [ ] D3 A tri-state select box on each header selects the whole group (phone: in selection mode or by long-pressing a header).
- [ ] D4 Phone: a View sheet (Group by, Sort by, Density); desktop and web: toolbar menus. **Phone done** 2026-11-09 (LibraryViewSheet; Compact rows 56 dp; two quick taps both count; LibraryScreenTest).

Fixes from the screenshots:
- [x] E1 The player shows the real length in every view (Roman Urdu showed 0:00 / 0:00). **Done** 2026-11-08 (phone): the library's length is shown until the file reports its own; an Ogg file without an index reported 0 (RecordingPlayerLengthTest).
- [ ] E2 Views only transliterate; a likely mishearing is a Suggested fix; report how many lines the views changed on the last 20 recordings. Baseline by word count on production, 2026-11-08: 14 OK, 4 to check, 2 with a problem.
- [ ] E3 Numbers and units as digits in every view.
- [ ] E4 Voice notes show their length and an automatic short title; editable, never overwriting a title set by a person.

Sign-in and people:
- [ ] F1 Phone and desktop open on a clean sign-in: email and password, Google, Create account, Forgot password, 2FA; then the Ready check.
- [ ] F2 Exact steps for the Google OAuth clients (web, Android, desktop) and where they go.
- [ ] F3 Console → People: invite by email with a role; pending invitations with resend and cancel.
- [ ] F4 Public sign-up toggle (on: family and friends make free accounts; off: invite only).
- [ ] F5 Download page with "How to get started" in 3 steps.
- [ ] F6 USER_GUIDE: family and friends have their own private accounts, teammates are invited, nobody sees another's recordings unless shared.

Ease of use:
- [ ] G The 15 most common tasks counted in taps and clicks on phone and desktop; the top 10 at 2 or fewer; before and after reported.

Urgent accuracy bug (2026-11-08):
- [x] U1 A shared 18.7 s Urdu voice note came back as nonsense and as a loop. Cause: on Auto, Whisper wrote Hindi letters, the forced Urdu retry carried our style prompt, and Whisper wrote the prompt's last sentence back («ٹھیک ہے، میں email بھیج دوں گا۔»). The audio path was right (the converted chunk is 18.50 s, gives the same text as the original file). Fixed: Whisper's prompt holds names only; WhisperChecks refuses a prompt echo, a loop, far too few words for the speech and a wrong length, asks once more without a prompt, else the next engine writes it; two engines that disagree altogether mark every line unsure and start a second version. CER against the reference 76-91% before, 27% after (16% without the opening clause TurboScribe left out). **Done**, deploy below.

Accuracy, every language, cheapest first (added 2026-11-08; Arabic first, then widened to all languages):
- [ ] H1 Engines cheapest first: Groq Whisper for Urdu, English, German and the rest; ElevenLabs Scribe v2 for Arabic; a language moves only when the Accuracy Lab shows another engine clearly better. **Server done** 2026-11-08 (defaults; stored lists still equal to the old defaults moved by a reversible migration; Auto that hears Arabic is run again on Arabic's engine). Waiting: the ElevenLabs key (Needs Hassam), then the Accuracy Lab on the Arabic notes.
- [x] H2 Double-check short recordings (≤ 3 min, every language) with the two cheapest engines (Groq + Soniox), words aligned; disagreement an unsure word with both versions; Gemini or ElevenLabs only as tie-breakers; cost per note and per language per month in Usage; "Double-check short notes" (on). **Server done** 2026-11-08 (ShortNoteCheck, WordAlign; ShortNoteAccuracyTest, ShortNoteCheckMarkTest).
- [x] H3 Views never guess, in every language and view: they get word confidences and alternatives; an unsure word stays, flagged ("I [understand?] English", «أفحم → probably أفهم»); Haiku 5.5 for short-note translations unless measured worse. Views now wait for the check, so they receive the marks; the prompt change follows the merge of the views work (E2). **Done** 2026-11-08 (ViewsNeverGuessTest with the «أفحم» note); Haiku for short notes is the setting `views.short_model`, off until the measurement on real notes.
- [ ] H4 Fast path for notes ≤ 60 s: transcript under 15 s, views under 30 s; long recordings keep the queue. **Server done** 2026-11-08 (FastPath: after the response, any engine); timing measured on production after the deploy.
- [x] H5 Arabic dialect hint (Gulf/Saudi, Egyptian, Levantine, Standard) for engines that take context. **Done** 2026-11-08 (Whisper style sentence, Soniox and Gemini context; Scribe takes none).
- [ ] H6 Tests (alignment, the «أفحم» no-guess fixture, routing, fast-path timing); the 8:19 pm note re-run, its new Original and English shown.

Share flow, progress, widgets (added 2026-11-08):
- [ ] I1 From a shared voice note, a deep link or a notification, the arrow and system Back go to the logical parent (the Library), never out of the app; a "Back to WhatsApp" button; Back from the Library leaves normally; predictive back. **Phone: shared notes done** 2026-11-08 (arrow and Back to the Library, "Back to WhatsApp" with the sharing app's name, in the note's menu once it opens; MainActivityTest); other deep links next.
- [x] I2 Uploads never stop with the screen ("Upload failed (JobCancellationException)"): unique, expedited, network-constrained WorkManager work with backoff, surviving app kill and reboot; friendly text and Retry, never an exception name. **Fixed** 2026-11-08: opening the app replaced the running upload ("Retry now" on every queued note) and a stopped run was marked failed with the exception's name; now a running upload is never replaced, a stopped one stays queued and runs again, uploads are expedited, and no message names an exception (UploadStoppedTest).
- [ ] I3 Library rows show Uploading %, Transcribing % with time left, Improving %; the transcript opens when transcribing is done; several shared notes each with their own. **Phone done** 2026-11-09 (RowProgress: Uploading % from the upload worker, Transcribing with part and time left, Improving % from the row's `transcript.views`; the same words on a shared note's page).
- [ ] I4 Resizable Glance widgets (2×1 Record now + Ready dot; 4×2 readiness, Record now, last 2 with progress; 4×4 last 5–6, tap to open), Material You, light and dark, updated on events, a hide-titles option, picker previews; on the S25 in the next phone window.

- [ ] Phase 1C suites green; verified on the S25 in the next phone window (after "keys copied").

## Phase 2 — Projects, smart folders, sharing

- [x] 2.1 Library unchanged (everything, newest first, filters). **Live** 2026-11-04 (beta.2).
- [x] 2.2 Projects tab replaces Clients (clients migrate to projects): folders with subfolders, contacts, instructions/notes, files, recordings; smart folders (WhatsApp · WhatsApp Business · Google Meet · Zoom · Teams · Phone (SIM) · Voice notes · Imported · Shared with me · Inbox); auto-filing rules (contact/number, meeting title text, app → project, else Inbox); move (drag-and-drop / Move, bulk); project page tabs (Recordings · Files · Notes · Screens · AI notes when on); breadcrumbs, search in project, sort, rename, delete (recordings go to Inbox). *Accept:* migration test on a copy of production; Pest, phone and desktop tests. **Live** 2026-11-04 (beta.2): production copy up/down/up with equal counts; web 3,974 on SQLite and MariaDB, phone 1,131 + 11, desktop vitest 1,423 + cargo.
- [ ] 2.3 Sharing: links (public / password / chosen signed-in people; expiry, download on/off, revoke, views); send to a teammate ("Shared with me", "Save to my library"); "Request files" link into the Inbox (size/type limits); links open in the app when installed. *Accept:* authorisation tests for every share route; **(phone)** a teammate share.

## Phase 3 — Screen moments

- [ ] 3.1 Desktop capture (Windows Graphics Capture; Mac ScreenCaptureKit): meeting window by default, change detection (~1 fps, 64×36 luminance + perceptual hash, stable 1.5 s, WebP ~150 KB, pointing words, cap ~120/h), privacy (Screens on/pause in the pill, auto-pause when hidden, excluded apps, never outside a meeting, encrypted). *Accept:* Rust tests for the detector; **(mac)** a Meet call with screen share. **Built and merged** 2026-11-06 (Rust 1,014 on the Mac, 1,038 in Windows CI); the real capture waits for the Mac window (MANUAL_QA SM-*).
- [x] 3.2 In the transcript: thumbnails at their times, enlarge, gallery; OCR + one-line description (Haiku 5.5, toggle). *Accept:* web/desktop/phone tests. **Merged** 2026-11-06 (web 72 new tests, desktop, phone since fb0f018); live with beta.4.
- [ ] 3.3 Meeting pack: PDF (title page + transcript with "[Screen N at mm:ss]" images) and ZIP (transcript.md + images/); Copy for AI with markers. *Accept:* export tests; the PDF read by Claude. **Merged** 2026-11-06 (MeetingPackTest, desktop and phone exports); the PDF read by Claude after beta.4 with a real meeting.
- [ ] 3.4 Phone MediaProjection capture (optional; else backlog). **Backlog** (v1.6): the phone shows screens from the computer; capturing on the phone needs Android's screen-capture prompt every meeting.

## Phase 4 — SaaS foundation

- [x] 4.1 Accounts: email + password (verified, reset), Google sign-in, 2FA (TOTP + recovery codes); phone/desktop sign in with the account (browser OAuth with PKCE) + biometric unlock; "Sign in with QR" instead of pairing codes; sessions & devices with revoke, sign out everywhere, login alerts; rate limits, lockout, CSRF, audit log. *Accept:* auth tests per route. **Merged** 2026-11-06 (`a263343`, 4,446 tests on SQLite and MariaDB); live with the next deploy.
- [ ] 4.2 Workspaces and roles (Super admin, Owner, Admin, Member, Viewer) with a tested permission matrix; private by default; email invitations; join/leave; transfer ownership; existing data migrated into Hassam's workspace. *Accept:* policy matrix tests; migration on a production copy. **Merged** (policy matrix green); the migration on a production copy is left, at the deploy.
- [x] 4.3 Plans: price (0), minutes, storage, retention, seats, AI credits, feature flags; limits enforced with friendly messages. *Accept:* tests. Phone done (`PlanStateTest`, plan/viewer screen tests); server and desktop with the Phase 4 helpers. **Merged**; Free plan numbers are placeholders for Hassam.
- [x] 4.4 Usage and costs per user, workspace and provider ($ from an editable price table, % of plan, trends, date filters, CSV, 80 %/100 % alerts). *Accept:* tests. **Merged**; costs stay after an account or workspace is erased.
- [x] 4.5 Console for super admin and workspace admins; members read-only (toggle). *Accept:* tests. **Merged**.
- [ ] 4.6 Account deletion in the apps and on a public web page; data export ZIP. *Accept:* tests; **(phone)** deletion of a test account. **Merged**; the phone test is left.
- [ ] 4.7 Legal drafts: Terms, Privacy (processors listed), recording-consent notice in setup and settings. *Accept:* pages render; N10. Phone: the consent notice is in onboarding and Settings → Privacy & consent already. **Merged**; N10 is left.
- [x] 4.8 Email: SMTP settings, templates (invite, verify, reset, login alert, share received, transcript ready), SPF/DKIM/DMARC checklist. *Accept:* mail tests. **Merged** (docs/EMAIL_DNS.md).

## Phase 5 — Distribution ready

- [ ] Android flavors "full" and "play" (Play: no READ_CALL_LOG / PROCESS_OUTGOING_CALLS, no Accessibility, FGS types, current target SDK, Data safety draft, deletion link, privacy URL); limits documented. *Accept:* both flavors build; lint per flavor. **Phone done** (mobile/DISTRIBUTION.md; the Play build also goes without the capture helper and the special-use services, see HANDOFF); lint per flavor: both pass (2026-11-04, after the App Links placeholder became applinks.invalid); the AAB at the release.
- [x] Updates: Android full checks the website; desktop Tauri updater with signed manifests. *Accept:* tests. **Built and merged** (`SelfUpdateTest`, `DownloadsTest`, desktop `a673009`); live with the next web deploy; the first signed desktop update needs N16's key.
- [x] Windows code-signing ready (documented; CI signs when the certificate exists); Mac notarization-ready workflow. *Accept:* workflow runs unsigned today. **Done** (docs/SIGNING.md, `531e732`; both CI workflows green unsigned on `1836cad`).
- [ ] Download page (APK, Windows, Mac, versions, checksums) and a landing page. *Accept:* page tests. **Built** (`DownloadsTest`, 10 tests; with the publish command and both update manifests); live with the next web deploy.

## Phase 6 — Claude / ChatGPT connector (MCP)

- [ ] MCP server (laravel/mcp) with OAuth 2.1 via Passport (PKCE, dynamic client registration, scope `mcp:use`), per-user; read-only tools list_projects, search, get_recording (views + screen moments with signed image links), get_project_brief/notes, list_recent; rate-limited, audited. *Accept:* tool + auth tests; connected from Claude (custom connector) and documented. **Built and merged**: 93+ tests over the real routes, consent asked every time, reuse of a token ends the connection, exact return addresses (docs/CONNECTOR.md). Not yet connected from the real Claude or ChatGPT: that needs the deploy and one real connection (N20).
- [x] In-app AI notes / memory / Ask optional per plan, off by default (Roman Urdu, suggested fixes and screen descriptions stay). *Accept:* tests. **Phone done** (project tabs and the recording page's call notes follow the plan, `SessionScreenTest`); desktop done with Phase 4; the server's `ai_notes` with web P4b. Server done too: the New plan form starts with AI notes off, suggested fixes run on their own (`MakeSuggestedFixesJob`), a file upload on such a plan no longer marks the brief failed.

## Phase 7 — Brand, icons, motion, consistency

- [x] Original logo (mark + wordmark, 16 px, light/dark), app icons (Android adaptive + themed, Windows, Mac), splash, docs/design/brand page. **Done** 2026-11-04 (docs/design/brand/README.md); phone screenshots re-recorded and the Mac icon looked at in the consistency pass.
- [x] One icon family across the apps. **Phone done** (`MotionAndIconsTest`); desktop and console with the Phase 7 helpers. Desktop and console done (`ConsoleIconsAndBrandTest`).
- [x] Motion (tabs, list → detail, record button, after-call chip, success), 60/120 fps, reduced motion respected. **Phone done**; desktop and console with the Phase 7 helpers. Desktop and console done.
- [x] Consistency pass with light/dark screenshots on phone, desktop and web. **Phone done** (project and Sign out wording, screens re-recorded); desktop and console with the Phase 7 helpers. Desktop and console done (108 console screenshots); a device signs in rather than pairs in all three, including the code field (`Sign-in code`). Old activity rows keep their old words until they age out.

## Phase 8 — Release + verify

- [x] Full suites green; security review of auth, sharing and MCP (authorisation test for every route); performance check. **Performance check done** (20,000 recordings on MariaDB, HANDOFF). **Security review done**: 1 critical, 8 major and 7 minor findings fixed with `ReleaseReviewTest`, each reproduced first and checked again after; the critical one also over real HTTP in production mode.
- [ ] Web deploy (migrations tested on a production copy first; **rehearsed** 2026-11-08 from beta.3 code and data on MariaDB 11.8, up, down and up again, HANDOFF; **v1.5.0-beta.4 console live 2026-11-08**, migrations tried on a copy of production first); Windows installer; Mac DMG (CI); APK full (+ Play AAB).
- [ ] **(phone)** unanswered WhatsApp call skipped; connected call starts at pick-up; after-call chip; Projects auto-filing; teammate share; Google sign-in; test-account deletion.
- [ ] **(mac)** screen moments in a Meet call with screen share; the meeting pack PDF in Claude.
- [ ] Docs: USER_GUIDE, ADMIN_GUIDE, MANUAL_QA, HANDOFF; tag v1.5.0; push; final report.
- [ ] Infrastructure: configurable queue/storage/cache drivers and docs/DEPLOY_VPS.md (nothing moved). **docs/DEPLOY_VPS.md written** (2026-11-04); the code switches are in: `QUEUE_VIA_SCHEDULER`, and `RECORDINGS_DRIVER=s3` with `recordings:check-storage` (73+ tests with a fake bucket that checks every signature; never run on a real bucket, the first check is on the chosen provider).
