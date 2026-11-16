# v1.9 finish checklist — private screen share + Live Assist

Plan: docs/PLAN_V1_9.md (local only). Research: docs/research/screen-share-privacy.md. Phase S runs first, inside
v1.8, and ships as a v1.8.x release; Phase L starts after the v1.8.0 tag. The Live Assist answer card never ships
without Phase S.

## Needs Hassam

| # | What | Why | What to do |
|---|---|---|---|
| N1 | The product name (Baatly recommended, Mukalma backup) | The Android applicationId can never change after the first Play release | Check the domains, the @handles and trademarks (IPO-Pakistan, WIPO, USPTO, EUIPO), then decide (same as v1.8 H4) |
| N2 | A VPS for the assist relay + its region (EU or Gulf, nearest to Pakistan) + its domain and TLS | Live streams need long-lived connections that shared hosting can't hold | Order the VPS and share the region |
| N3 | Provider keys in Admin → API Credentials: Anthropic, OpenAI, Gemini, Groq, Moonshot, Soniox (translation on), AssemblyAI | The relay calls them | Paste them there yourself (never in chat) |
| N4 | Assist-hour caps and prices for Pro and Ultra | Plans | Decide the caps (research suggests 10 h Pro, 40 h Ultra) |
| N5 | Screen-share test windows on Windows and the Mac with a second device watching; a macOS 15 machine if possible | S0, S3 guided test, S7 matrix | Say "laptop free" / "Mac free" with a phone ready |
| N6 | Phone windows for S5 and L5 | Device tests | Say "phone free" |
| N7 | Review the privacy wording, the interview reminder, the consent line, store and website text; a short legal check on US/EU recording consent | Launch | Before the public launch |
| N8 | Run the Pakistan latency benchmark | L9 | Run the script from docs/LATENCY_V1_9.md when it's ready |

## Step 0
- [x] PLAN_V1_9.md saved locally and excluded; this checklist committed; docs/research/screen-share-privacy.md
  committed from the FACTS with the source links (2026-11-16).
- [x] The words rule in tools/repo-policy: "undetectable", "stealth", "invisible to proctors", "cheat", "bypass" fail
  the build in user-facing strings (en, ur, ar). **Acceptance:** a test that plants each word in a fixture and fails,
  and passes on the clean tree. **Done 2026-11-16:** `tools/repo-policy.test.mjs` "words rule" (planted English, Urdu
  and Arabic forms caught; phone, desktop, Rust and web string literals that read as words, string resources, Blade
  pages, USER_GUIDE and docs/store scanned; 26/26 pass).
- [ ] Scope limit recorded in HANDOFF and DESIGN_SYSTEM: windows are hidden from screen sharing only; never from other
  software (no renamed processes, no hiding from window lists, no tricks against proctoring, lockdown browsers or
  monitoring tools).

## Phase S — Private screen share (ships as v1.8.x)
- [ ] S0 Baseline before any change: on the Windows laptop and the Mac, whole-screen shares in Zoom, Meet (Chrome),
  Teams, a Slack huddle and WhatsApp desktop with captions, the pill, the after-call card, Live Translate and the main
  window open, watched from a second device (N5); the exact Windows build and macOS version; docs/SCREEN_SHARE_MATRIX.md
  started (app × system × share mode → hidden / black / visible). **Acceptance:** the matrix's baseline rows.
  **Started 2026-11-16:** docs/SCREEN_SHARE_MATRIX.md with this Mac (macOS 27.3, 26C5035r; the SDK no longer has
  `CGWindowListCreateImage`, so captures go through ScreenCaptureKit); the automated baseline needs the Screen Recording
  permission and the meeting-app rows need N5.
- [ ] S1 One `privacy` module owns the capture flag for every window (captions, dictation, pill, after-call, Live
  Translate, the future Assist card, the main window by setting, any later window), set before the first show; the
  scattered calls replaced; Settings → Privacy "Hide EchoLedger from screen sharing": All windows (default) / Only
  floating windows / Off; the old `content_protection` migrated (someone who turned "Show during screen share" on keeps
  floating windows visible and the main window visible); tray "Show EchoLedger in screen share for 10 minutes"; Live
  Translate follows the setting unless its history shows the visible default was on purpose (then its own switch).
  **Acceptance:** every window registers (test); migration test; the 10-minute demo returns to the setting (test).
  **Desktop done 2026-11-16 (branch v181-desktop):** `desktop/src-tauri/src/privacy/` (`prepare` sets the flag on every
  builder, `KNOWN` lists main, captions, dictation, pill, after-call, Live Translate, the Assist card and the check's
  windows; test `every_window_echoledger_builds_is_registered_with_its_kind`); `hideFromSharing` all / floating / off,
  `contentProtection: false` migrates to off (`show_during_screen_share_from_v1_8_keeps_echoledger_visible_in_shares`);
  tray demo (`the_ten_minute_demo_returns_to_the_setting_by_itself`). Live Translate: `git log -S create_translate_window`
  (4ea46df5) gives no reason for the unprotected build, so it follows the floating windows.
- [ ] S2 Windows: build number (RtlGetVersion); on 19041+ set `WDA_EXCLUDEFROMCAPTURE` and read it back for every
  window (mismatch → amber + diagnostic); older builds → `WDA_MONITOR` black box by default with the amber line and a
  "Black box" / "Show normally" setting; Remote Desktop (SM_REMOTESESSION) → amber line; a 5 s read-back while a
  protected window is visible, re-set on change (no contents in logs). Mac: the macOS version recorded; status from the
  Share Check. **Acceptance:** tests for the build gate, mismatch, RDP and the 5 s re-check.
  **Desktop done 2026-11-16:** `privacy/win.rs` behind the `Affinity` trait (tests: build gate, read-back mismatch, RDP
  and old-Windows lines, the 5 s re-check), `spawn_recheck`; Mac: the system name and build in every result. Windows
  `cargo check`/clippy clean (cross-checked from the Mac); the real calls need the Windows laptop (N5).
- [ ] S3 Share Check ("See what others see") from Settings → Privacy, the badge and onboarding, and by itself after an
  app update, a system update, weekly and 2 min before a scheduled Live Assist call: a protected magenta test window and
  an unprotected green control, the monitor captured like a meeting app (Windows: WGC CreateForMonitor + DXGI Desktop
  Duplication; Mac: SCScreenshotManager display capture, nothing excluded), colours counted (tolerance 25): Pass =
  control found and test 0; Fail = test found; Error = control missing (permission or capture failure, with a fix
  button). Result shown as "What you see" / "What others see" with one plain line. The picture stays in memory only;
  only {platform, OS build, app version, method, result} goes to the server, and only with diagnostics allowed, shown
  as a matrix in the admin panel. Mac: the Screen Recording permission explained with "Open Settings". Guided test per
  meeting app (test card, "I can see it" / "I can't see it"), saved per app + app version + macOS version, asked again
  after their updates. **Acceptance:** pixel-counter tests on fixtures (pass / fail / error); API + isolation tests for
  the diagnostics; the admin matrix test.
  **Desktop done 2026-11-16:** `privacy/check.rs`, `capture.rs` (WGC + DXGI; SCScreenshotManager, nothing excluded,
  sRGB), `pixels.rs` (fixtures pass / fail / black-box / error), Settings › Screen-share privacy › Check screen-share
  privacy, the badge, onboarding's last step, automatic (first run, app update, system update, weekly) and
  `before_call` for Live Assist; reports to `POST /api/diagnostics/share-checks` only with Send diagnostics on (mocked
  in tests/api_client.rs). Measured on this Mac (macOS 27.3): pass, magenta 0 px, green 64,480 / 64,480 px; an
  unprotected test window fails (28,620 px). Guided test per app on the Mac stored per app version + macOS version. The
  server, its isolation tests and the admin matrix are the server's part.
- [ ] S4 Mac per-app setup and safety net: Zoom's "Advanced capture with window filtering" shown with screenshots
  (labels checked in the latest Zoom); Zoom's current mode read read-only from its local settings if possible ("Zoom is
  set up"); share-less tips and the second-display offer; whole-screen share detection from the window list (known
  sharing bars of Zoom, Teams, Chrome/Edge/Brave/Safari, Slack, WhatsApp) + meeting detection, patterns in a
  server-updatable config with fixture tests, unknown bars logged (titles only); in an unconfirmed app every window
  orders out within 300 ms and returns when the share ends (cards continue on the phone when linked, L6); setting "I
  share one window or a tab"; a plain warning before a scheduled call in an unconfirmed app. **Acceptance:** pattern
  tests on window-list fixtures; the 300 ms hide test; MANUAL_QA on the Mac.
  **Desktop done 2026-11-16:** `privacy/bars.rs` (the server's file built in, newer patterns with their ETag, fixture
  tests in tests/share_bars.rs), `privacy/watch.rs` (`CGWindowListCopyWindowInfo` every 140 ms while a window is on
  screen + the meeting auto mode detected; hide test `the_windows_leave_within_300_ms`), `privacy/apps.rs` (versions from
  Info.plist, per-app lines, `warning_before_call`). Zoom's capture mode is in its encrypted `zoomus.enc.db`: not
  readable, never written (research doc). Zoom's setting is shown in words (no third-party screenshots in the app).
  Safari's own sharing bar has no pattern in version 1 of the file: unknown bars are logged to add it. Left: the
  MANUAL_QA S-5 to S-7 rows on the Mac with a phone (N5); phone hand-over of cards is L6.
- [ ] S5 Android card privacy: on Android 15+ `addScreenRecordingCallback` (DETECT_SCREEN_RECORDING) from the
  component that owns the floating pill / card → VISIBLE collapses to a dot without text, NOT_VISIBLE restores; tested
  on the S25 with a Meet share, a Zoom share, a WhatsApp video share, Samsung's screen recorder and single-app sharing
  (N6), the best public signal documented if it doesn't fire for a floating window; on all versions a one-tap Hide on
  the card and a Quick Settings tile "Hide EchoLedger cards"; setting "During screen sharing": Hide cards (default) /
  Black box (FLAG_SECURE) / Show; no hidden or private APIs. **Acceptance:** unit tests for the states; the S25 results
  in SCREEN_SHARE_MATRIX.md.
- [ ] S6 Trust UX: a privacy badge on every floating window and the Assist card (Private / Not checked / Visible in
  shares) from the last real check, a tap opens Share Check; on a Mac the per-app list; a "Hide all EchoLedger windows"
  global shortcut (conflict-checked, not ⌘⌥H); Settings → Privacy page (switch and status, last check + Check now, the
  one-window tip, the honest limits list); USER_GUIDE and website "Private notes" text + a link to the public matrix.
  **Acceptance:** badge state tests; shortcut conflict tests; the copy in USER_GUIDE.
  **Desktop done 2026-11-16:** the badge on the pill, captions, dictation, after-call card and Live Translate (badge
  state tests in Rust `status.rs` and vitest), the per-app list on a Mac, "Hide all EchoLedger windows" Ctrl/⌘+Alt+Shift+P
  (not ⌘⌥H; conflict tests in shortcuts.rs and lib/privacy.test.ts), Settings › Screen-share privacy with the status,
  last check + Check now, the one-window tip and the limits; USER_GUIDE "Private notes". Website text is web's part.
- [ ] S7 Tests and release: unit tests (registration, migration, build gate, read-back mismatch, RDP, re-check, pixel
  counter, sharing-bar patterns, badge states, words rule); Windows CI capture test (protected magenta + green control,
  WGC and DXGI; skipped with a clear reason when the runner has no capture session, never a silent pass); the manual
  matrix with Hassam (Zoom, Meet in Chrome, Teams, Slack huddle, WhatsApp desktop × Windows 11, Windows 10, the Mac,
  macOS 15 if possible × whole screen / one window / tab; Zoom's three capture modes on the Mac); shipped as v1.8.x
  (desktop installers + phone build). **Acceptance:** CI green; the matrix filled; the release recorded in HANDOFF.

## Phase L — Live Assist (after v1.8.0)
  **Desktop part done 2026-11-16:** the unit tests above; Windows CI runs `desktop/scripts/share-check-windows.ps1` on
  each smoke runner (`--smoke-test --share-check`: pass = 0 magenta and green present, fail on magenta, a runner without
  a capture session is marked skipped with its reason, never a silent pass). Left: the manual matrix (N5) and the
  release.
- [ ] L0 Inventory in PLAN_V1_9.md; the assist relay in services/assist-relay (Rust axum + tokio, HTTP/2 keep-alive;
  Dockerfile + systemd unit per docs/DEPLOY_VPS.md; VPS = N2); brand constants in one place per app. **Acceptance:** the
  relay builds and its tests pass; one product-name constant per app.
- [ ] L1 Server: assist_preps, assist_prep_documents, assist_sessions (auto_saved), assist_turns, assist_suggestions,
  assist_facts, assist_summaries, assist_reports (scoped, encrypted text, soft deletes); `/api/v1/assist/...` (preps
  CRUD + documents, arm, start → relay URL + assist token ≤ 15 min + STT token, token refresh, batched turns, end,
  report, practice/start; HMAC-signed relay endpoints for usage, facts, summary); plans in config (Free practice, Pro
  Fast + Balanced with a cap, Ultra + Deep + Max); every LLM/STT call through the budget and usage tracking
  ("live_assist"); the prompt pack in resources/prompts/assist/; no training on user data. **Acceptance:** CRUD,
  isolation on every endpoint, plan gates, 402 on an exhausted budget with a fallback hint, token claims and TTL, HMAC,
  log redaction.
- [ ] L2 Relay: WebSocket protocol; Anthropic (caching, thinking off), OpenAI, Gemini Flash (thinking 0), Groq, Kimi
  behind a flag; mode → model map in config; cache-ordered context; warm-up; speculative start (Other channel only,
  ≥128 ms silence, cancel within 700 ms); draft → upgrade; background facts ledger and summary; budget downgrade (never
  stopping captions); per-suggestion telemetry without content. **Acceptance:** context-order snapshots, speculative
  cancel, SKIP, downgrade, auth rejection, mocked streaming, redaction; load test 50 sessions, overhead p95 < 30 ms.
- [ ] L3 Live STT for assist: Soniox real-time (translation of the Other channel when language help is on), AssemblyAI
  streaming as "English accuracy"; partials/finals per channel to the relay, finals batched to the server; the post-call
  pipeline unchanged. **Acceptance:** channel routing, translation fields, reconnect with token refresh without losing
  turns.
- [ ] L4 Desktop: prep screen, Start now (auto_saved), Schedule (arm 2 min early, Share Check, meeting detection, 10 min
  grace), the Assist card (privacy service, badge, click-through, drag/resize like the v1.8 captions, top-centre, per
  monitor; question gloss, big first line, ≤ 3 bullets, "Say next", Them/Me captions strip, token-by-token), hotkeys
  (conflict-checked), in-call controls, interview and consent reminders. **Acceptance:** component tests, scheduler and
  auto_saved tests, e2e with a two-channel WAV fixture + mocked relay.
- [ ] L5 Android: prep + Start now / Schedule (helper armed; mic-only fallback), the card in the floating pill (dot →
  3 lines; Help now / Regenerate / Shorter / Urdu) under the S5 rules, relay WebSocket with token refresh, a compliant
  foreground service type, no Accessibility. **Acceptance:** ViewModel/scheduler/gate tests, Compose UI tests; the S25
  phone-window test (N6).
- [ ] L6 Second screen: link a phone to a desktop session (QR or session list); cards and captions within 300 ms of
  the desktop; screen kept awake; Android now, iPhone with v1.8 P8. **Acceptance:** latency test with a mocked relay.
- [ ] L7 Report + practice: the post-session job (summary EN/UR, Q&A table with ratings and better answers, mistakes
  and promises, coaching metrics, action items into project memory, follow-up email); practice mode (Free limited);
  report views on console, desktop and phone; PDF/Markdown export; Ask over the session. **Acceptance:** report JSON
  schema, coaching metrics on fixtures, isolation, budget accounting.
- [ ] L8 Console: Preps, Sessions, Report, Assist settings, Usage & budget; admin latency p50/p95, cost per hour by
  mode, error rates (no content), the Share Check matrix. **Acceptance:** Filament page tests, policies, isolation.
- [ ] L9 Quality: the golden eval set (10 transcripts with traps) ≥ 90 % in Balanced; the latency benchmark script for
  Pakistan (N8) → docs/LATENCY_V1_9.md; targets (p50 ≤ 1.5 s Fast, ≤ 2.0 s Balanced draft, ≤ 3.0 s Deep; relay p95 ≤
  30 ms; no suggestions from the Me channel; cache hit ≥ 80 % after the 3rd suggestion). **Acceptance:** the eval
  runner's report; CI with mocked providers.
- [ ] L10 Release: full suites, web deployed, relay on the VPS, desktop installers, phone build; the Windows + Mac
  rehearsal and the phone window; USER_GUIDE (Live Assist, Private notes), ADMIN_GUIDE, HANDOFF; tagged v1.9.0; the
  final report with the monthly running cost per active user. **Acceptance:** recorded in HANDOFF.

Backlog: a Chrome extension for locked work laptops (tab capture); calendar sync for scheduling; Live Assist for SIM
calls on Samsung after v1.8 0.3.
