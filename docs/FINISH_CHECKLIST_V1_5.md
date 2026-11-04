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
| N6 | Mac test (from v1.4) and macOS version | Mac call capture needs macOS 14.2+ | Apple menu → About This Mac; then MANUAL_QA "Mac app v1.4". |
| N7 | Google sign-in client (Phase 4) | "Sign in with Google" | Steps come with Phase 4 (Google Cloud console → OAuth client for the console address). |
| N8 | SMTP account and DNS (Phase 4) | Invitations, verification, reset and alert emails | Mail host, port, user and password into the console's email settings; add the SPF, DKIM and DMARC records from the docs checklist. |
| N9 | A teammate test account (Phase 2, 4, 8) | Sharing and invitation tests | One email address (a teammate's or a second one of yours). |
| N10 | Review the legal drafts (Phase 4.7) | Terms and Privacy before anyone else signs up | Read the drafts and send changes. |
| N11 | Paid accounts before a public release | Store and signing | Google Play $25 once; Apple Developer $99/year; Windows code-signing certificate; a VPS (about $10–20/month). |
| N12 | Keystore (optional) | Signed APK | Path, alias and passwords at build time, or keep debug builds. |
| N13 | Soniox credit | Live Translate | soniox.com → Billing → add funds. |
| N14 | Back up the desktop updater key | Installed desktop apps only take updates signed with it; losing it means reinstalling by hand | Copy C:echoledger-keysdesktop-updater.key (made 2026-11-04, no password yet) to your password manager or an offline drive. Say if you want a password on it before the public release. |

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

## Phase 2 — Projects, smart folders, sharing

- [x] 2.1 Library unchanged (everything, newest first, filters). **Live** 2026-11-04 (beta.2).
- [x] 2.2 Projects tab replaces Clients (clients migrate to projects): folders with subfolders, contacts, instructions/notes, files, recordings; smart folders (WhatsApp · WhatsApp Business · Google Meet · Zoom · Teams · Phone (SIM) · Voice notes · Imported · Shared with me · Inbox); auto-filing rules (contact/number, meeting title text, app → project, else Inbox); move (drag-and-drop / Move, bulk); project page tabs (Recordings · Files · Notes · Screens · AI notes when on); breadcrumbs, search in project, sort, rename, delete (recordings go to Inbox). *Accept:* migration test on a copy of production; Pest, phone and desktop tests. **Live** 2026-11-04 (beta.2): production copy up/down/up with equal counts; web 3,974 on SQLite and MariaDB, phone 1,131 + 11, desktop vitest 1,423 + cargo.
- [ ] 2.3 Sharing: links (public / password / chosen signed-in people; expiry, download on/off, revoke, views); send to a teammate ("Shared with me", "Save to my library"); "Request files" link into the Inbox (size/type limits); links open in the app when installed. *Accept:* authorisation tests for every share route; **(phone)** a teammate share.

## Phase 3 — Screen moments

- [ ] 3.1 Desktop capture (Windows Graphics Capture; Mac ScreenCaptureKit): meeting window by default, change detection (~1 fps, 64×36 luminance + perceptual hash, stable 1.5 s, WebP ~150 KB, pointing words, cap ~120/h), privacy (Screens on/pause in the pill, auto-pause when hidden, excluded apps, never outside a meeting, encrypted). *Accept:* Rust tests for the detector; **(mac)** a Meet call with screen share.
- [ ] 3.2 In the transcript: thumbnails at their times, enlarge, gallery; OCR + one-line description (Haiku 5.5, toggle). *Accept:* web/desktop/phone tests.
- [ ] 3.3 Meeting pack: PDF (title page + transcript with "[Screen N at mm:ss]" images) and ZIP (transcript.md + images/); Copy for AI with markers. *Accept:* export tests; the PDF read by Claude.
- [ ] 3.4 Phone MediaProjection capture (optional; else backlog).

## Phase 4 — SaaS foundation

- [ ] 4.1 Accounts: email + password (verified, reset), Google sign-in, 2FA (TOTP + recovery codes); phone/desktop sign in with the account (browser OAuth with PKCE) + biometric unlock; "Sign in with QR" instead of pairing codes; sessions & devices with revoke, sign out everywhere, login alerts; rate limits, lockout, CSRF, audit log. *Accept:* auth tests per route.
- [ ] 4.2 Workspaces and roles (Super admin, Owner, Admin, Member, Viewer) with a tested permission matrix; private by default; email invitations; join/leave; transfer ownership; existing data migrated into Hassam's workspace. *Accept:* policy matrix tests; migration on a production copy.
- [ ] 4.3 Plans: price (0), minutes, storage, retention, seats, AI credits, feature flags; limits enforced with friendly messages. *Accept:* tests. Phone done (`PlanStateTest`, plan/viewer screen tests); server and desktop with the Phase 4 helpers.
- [ ] 4.4 Usage and costs per user, workspace and provider ($ from an editable price table, % of plan, trends, date filters, CSV, 80 %/100 % alerts). *Accept:* tests.
- [ ] 4.5 Console for super admin and workspace admins; members read-only (toggle). *Accept:* tests.
- [ ] 4.6 Account deletion in the apps and on a public web page; data export ZIP. *Accept:* tests; **(phone)** deletion of a test account.
- [ ] 4.7 Legal drafts: Terms, Privacy (processors listed), recording-consent notice in setup and settings. *Accept:* pages render; N10. Phone: the consent notice is in onboarding and Settings → Privacy & consent already.
- [ ] 4.8 Email: SMTP settings, templates (invite, verify, reset, login alert, share received, transcript ready), SPF/DKIM/DMARC checklist. *Accept:* mail tests.

## Phase 5 — Distribution ready

- [ ] Android flavors "full" and "play" (Play: no READ_CALL_LOG / PROCESS_OUTGOING_CALLS, no Accessibility, FGS types, current target SDK, Data safety draft, deletion link, privacy URL); limits documented. *Accept:* both flavors build; lint per flavor. **Phone done** (mobile/DISTRIBUTION.md; the Play build also goes without the capture helper and the special-use services, see HANDOFF); lint per flavor: both pass (2026-11-04, after the App Links placeholder became applinks.invalid); the AAB at the release.
- [ ] Updates: Android full checks the website; desktop Tauri updater with signed manifests. *Accept:* tests. **Phone done** (`SelfUpdateTest`); server `/downloads/android.json` and `desktop.json` + `echoledger:publish-release` with web Phase 5; desktop updater with desktop Phase 5.
- [ ] Windows code-signing ready (documented; CI signs when the certificate exists); Mac notarization-ready workflow. *Accept:* workflow runs unsigned today.
- [ ] Download page (APK, Windows, Mac, versions, checksums) and a landing page. *Accept:* page tests. **Built** (`DownloadsTest`, 10 tests; with the publish command and both update manifests); live with the next web deploy.

## Phase 6 — Claude / ChatGPT connector (MCP)

- [ ] MCP server (laravel/mcp) with OAuth 2.1 via Passport (PKCE, dynamic client registration, scope `mcp:use`), per-user; read-only tools list_projects, search, get_recording (views + screen moments with signed image links), get_project_brief/notes, list_recent; rate-limited, audited. *Accept:* tool + auth tests; connected from Claude (custom connector) and documented.
- [ ] In-app AI notes / memory / Ask optional per plan, off by default (Roman Urdu, suggested fixes and screen descriptions stay). *Accept:* tests.

## Phase 7 — Brand, icons, motion, consistency

- [x] Original logo (mark + wordmark, 16 px, light/dark), app icons (Android adaptive + themed, Windows, Mac), splash, docs/design/brand page. **Done** 2026-11-04 (docs/design/brand/README.md); phone screenshots re-recorded and the Mac icon looked at in the consistency pass.
- [ ] One icon family across the apps.
- [ ] Motion (tabs, list → detail, record button, after-call chip, success), 60/120 fps, reduced motion respected.
- [ ] Consistency pass with light/dark screenshots on phone, desktop and web.

## Phase 8 — Release + verify

- [ ] Full suites green; security review of auth, sharing and MCP (authorisation test for every route); performance check.
- [ ] Web deploy (migrations tested on a production copy first); Windows installer; Mac DMG (CI); APK full (+ Play AAB).
- [ ] **(phone)** unanswered WhatsApp call skipped; connected call starts at pick-up; after-call chip; Projects auto-filing; teammate share; Google sign-in; test-account deletion.
- [ ] **(mac)** screen moments in a Meet call with screen share; the meeting pack PDF in Claude.
- [ ] Docs: USER_GUIDE, ADMIN_GUIDE, MANUAL_QA, HANDOFF; tag v1.5.0; push; final report.
- [ ] Infrastructure: configurable queue/storage/cache drivers and docs/DEPLOY_VPS.md (nothing moved). **docs/DEPLOY_VPS.md written** (2026-11-04); the code switches it names (`QUEUE_VIA_SCHEDULER`, `RECORDINGS_DRIVER=s3`) come with web Phase 5.
