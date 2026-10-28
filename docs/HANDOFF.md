# EchoLedger v1.1 — Handoff

## Snapshot
- 2026-10-27 (Asia/Karachi), branch `v1.1`: **v1.1 is live on the production site**. Phase 8 remaining: Android signed APK (needs the keystore from the user), then the user runs docs/MANUAL_QA.md on the live system. The Windows installer is built, unsigned.
- Tests (end of Phase 7):
  - Web (Pest): SQLite `--parallel` 3065 passed, 0 failed, 0 risky; MariaDB 10.4 `--parallel --processes=6` 3065 passed, 0 failed. The timing test that used to fail on battery is now stable (see "Phase 7 test coverage").
  - Desktop core (`cargo test`): 825 passed, 0 failed (1 ignored). Desktop UI (`vitest`): 994/994; `npm run build` ok.
  - Android (`testDebugUnitTest`): 878 tests, 0 failed (862 at the end of Phase 6 + 12 `DictationScreenTest` + 3 `RotationTest` + 1 `MainViewModelTest`); Android lint 0 errors (21 warnings, all older: `PrivateApi`, `UseKtx`, `InlinedApi`, `UnusedAttribute`, `ExportedContentProvider`, `UnusedResources`); `assembleDebug`, `assembleDebugAndroidTest`, ktlint and lint passed in the same run.
  - Repo policy and design tokens: see "Phase 7 test coverage" (run after the Phase 7 commit, because the policy reads tracked files).
- Linters: Pint clean, PHPStan 0 errors, `cargo clippy --all-targets -D warnings` clean, `tsc --noEmit` clean, ktlint and Android lint clean. macOS cross clippy not re-run (the `mac-check.ps1` script lived in an older session's scratchpad); Phase 7 added no platform-specific code.
- Known failing / flaky tests:
  - `LibraryView.test.tsx` "handles a very long transcript without dropping segments" and `PillApp.test.tsx` "brings the pill back when Stop fails" time out only when Gradle or cargo run at the same time; they passed alone and in the sequential Phase 7 run.## Progress
- Plan items: 148 in PLAN_V1_1.md: 107 Done ([x]), 36 Partial ([~]), 4 Not started (Phase 8), 1 Blocked. Ticked = 107/148 = **72 %**.
- All 36 Partial items are built and covered by automated tests on every platform and wait only for on-device checks, now one ordered list: docs/MANUAL_QA.md (125 steps, about 2 hours; the old section IDs are kept in its last column). Weighted by remaining effort, about **88 %** of the upgrade brief is done; what is left is Phase 8 (installer, signed APK, deploy, merge) and the on-device run.

| Item | Summary | Status |
|---|---|---|
| P0.1 | Branch `v1.1` from `main` (`e9e8d93`). | Done |
| P0.2 | Read CONTRIBUTING.md, PLAN.md, docs/* and docs/research/*. | Done |
| P0.3 | Baseline: all suites and linters recorded (counts below). | Done |
| P0.4 | Expanded test suites added (API, console, security, Rust core, desk... | Done |
| P0.5 | This plan written. | Done |
| A1.1 | A call needs two-way audio: the same process tree holds an active *... | Partial |
| A1.2 | macOS 14.4+: Core Audio process objects for running input/output (p... | Partial |
| A1.3 | Browsers are identified by site, never by process name: Windows `En... | Partial |
| A1.4 | Data-driven, unit-tested site rules (user-extendable in Settings): ... | Done |
| A1.5 | Unknown browser mic use does nothing. Optional setting (off by defa... | Partial |
| A1.6 | The label is decided once at start and kept for the session. | Done |
| A1.7 | `whatsapp_web` end to end (server enum, validation, API docs, conso... | Done |
| A1.8 | Record screen explains each decision ("Holds mic ✓ · Playing audio ... | Partial |
| A1.9 | Tests: ChatGPT title → none; "Meet - abc-defg-hij" → google_meet; "... | Done |
| A2.1 | `PendingDecision` state: a finished recording stays local and is no... | Done |
| A2.2 | Review card window (unfocusable, always on top, bottom-right, hidde... | Partial |
| A2.3 | Discard deletes local files, uploads nothing, requests no transcrip... | Done |
| A2.4 | Keep enqueues the upload and the final transcript. | Done |
| A2.5 | "If I don't choose within 60 s": Keep (default) / Discard / Keep wa... | Done |
| A2.6 | "Ask before saving": Always (default) / Only automatic calls / Never. | Done |
| A2.7 | Phone: heads-up post-call notification with the same preview fields... | Partial |
| A2.8 | Tests: Keep → enqueued; Discard → files deleted, no upload, no tran... | Done |
| A3.1 | Overlay card is a flex column (`max-height: 100%`), header always v... | Partial |
| A3.2 | Visible lines setting (2 / 3 / 5); long partial turns wrap inside t... | Partial |
| A3.3 | Recording pill (≤ 280 × 44, draggable, remembers position, unfocusa... | Partial |
| A3.4 | Tray icon states: idle / recording / paused / uploading / needs att... | Partial |
| A3.5 | Tests: toolbar stays inside the window with 50 long lines; pill act... | Done |
| A4.1 | Settings expose "not loaded yet"; a neutral splash is shown until D... | Done |
| A4.2 | Re-lock on `ON_STOP` with "Lock after: Immediately / 1 min / 5 min". | Partial |
| A4.3 | `BIOMETRIC_WEAK or DEVICE_CREDENTIAL`, `canAuthenticate()` at runti... | Partial |
| A4.4 | The App lock row is always visible (disabled + reason + "Open secur... | Done |
| A4.5 | `FLAG_SECURE` while the lock is on (user setting); every activity t... | Partial |
| A4.6 | Robolectric tests: late settings emission → locked; `ON_STOP` → loc... | Done |
| A5.1 | `POST /api/stt/token` only for an open session owned by the device,... | Done |
| A5.2 | Monthly budget cap (minutes or USD) in the console; over the cap → ... | Done |
| A5.3 | Devices open a streaming socket only when the user started it or th... | Done |
| A5.4 | `usage_ledger` table: streamed minutes reported at session end; fin... | Done |
| A6.W1 | [high] Console audio route returns 403 to admins without 2FA althou... | Done |
| A6.W2 | [medium] Console audio route is not scoped to the recording's owner. | Done |
| A6.W3 | [high] `complete` answers 202 when whole-file verification fails, s... | Done |
| A6.W4 | [high] A provider error during the inline submit leaves the final t... | Done |
| A6.W5 | [high] A recording left in "assembling" by a request that died is n... | Done |
| A6.W6 | [medium] Incremental sync misses same-second changes and live trans... | Done |
| A6.W7 | [medium] Deleting a session mid-transcription never deletes the pro... | Done |
| A6.W8 | [medium] Playback decrypts the whole recording into memory twice (l... | Done |
| A6.W9 | [medium] Certificate pins are saved and pushed to devices without v... | Done |
| A6.W10 | [medium] Provider endpoint URLs accept non-HTTPS schemes and any ho... | Done |
| A6.W11 | [medium] The seeder creates a super admin with a published default ... | Done |
| A6.W12 | [medium] Docs and CLI messages describe two-factor sign-in as enfor... | Done |
| A6.W13 | [medium] Transcript upload validation is super-linear; the 20,000-s... | Done |
| A6.W14 | [medium] Console transcript player is unreadable in the light theme... | Done |
| A6.W15 | [low] A Range past the end returns 200 with the whole file instead ... | Done |
| A6.W16 | [low] HTTP 500 instead of 4xx: webhook `rid[]=x`, library cursor wi... | Done |
| A6.W17 | [low] 404 responses name internal model classes. | Done |
| A6.W18 | [low] `rotate-token` widens a restricted token to the full ability ... | Done |
| A6.W19 | [low] The `uploads` rate limiter can never trigger (the `device` li... | Done |
| A6.W20 | [low] ElevenLabs webhook de-duplication keys on an unsigned header. | Done |
| A6.W21 | [low] Garbage `sample_rate` values are coerced and accepted; timest... | Done |
| A6.W22 | [low] Orphaned decrypted/assembled temp audio is never swept. | Done |
| A6.W23 | [low] Console profile accepts 8-character passwords (CLI requires 12). | Done |
| A6.W24 | [low] No limit on open upload slots. | Done |
| A6.W25 | [low] Security headers exist only in the hosting `.htaccess`; API a... | Done |
| A6.W26 | [low] Console details: dashboard chart and date filter ignore the d... | Done |
| A6.W27 | [low] Console loads third-party fonts and avatars (fonts.bunny.net,... | Done |
| A6.W28 | [low] Console voice-note upload refuses AMR, ADTS AAC and WebM desp... | Done |
| A6.W29 | [low] `.env.example` defaults (`APP_DEBUG=true`, `APP_ENV=local`, `... | Done |
| A6.W30 | [low] docs/API.md differs from real responses. | Done |
| A6.DR1 | [high] AssemblyAI streaming flushes pre-Begin audio with a trailing... | Done |
| A6.DR2 | [medium] The loopback check is a string prefix: look-alike hosts ge... | Done |
| A6.DR3 | [medium] Pinning fails open when none of the configured pins parses. | Done |
| A6.DR4 | [medium] Permanent upload errors (403, 404, 413, 422) are retried f... | Done |
| A6.DR5 | [medium] Imported `.oga`, `.aac` and `.amr` files are declared `app... | Done |
| A6.DR6 | [medium] The recorder inflates a recording when the first packet ha... | Done |
| A6.DR7 | [medium] A client deleted in the console blocks every upload and ca... | Done |
| A6.DR8 | [medium] An upload that fails server verification is counted as upl... | Done |
| A6.DR9 | [low] FLAC with a final block of 1–15 samples writes an invalid STR... | Done |
| A6.DR10 | [low] Stopping a long recording needs about 2.3 GB of RAM per hour ... | Done |
| A6.DR11 | [low] ElevenLabs live transcript loses turn order; an AssemblyAI se... | Done |
| A6.DR12 | [low] TLS and pin failures are reported as a generic network error. | Done |
| A6.DR13 | [low] One unknown enum value in settings.json discards the whole file. | Done |
| A6.DR14 | [low] A single NaN/inf sample poisons the resampler. | Done |
| A6.DR15 | [low] The HTTP client is rebuilt on every call; no guard against a ... | Done |
| A6.DR16 | [low] Version drift: tauri.conf.json says 0.1.1, Cargo.toml/package... | Done |
| A6.DR17 | [low] `zip` 4.6.1 (RUSTSEC advisories) via the updater plugin; inst... | Done |
| A6.DR18 | [low] The "no audio captured" message tells desktop users to check ... | Done |
| A6.DR19 | [low] The macOS code had never been compiled: `cargo clippy --targe... | Done |
| A6.DU1 | [high] Pairing errors (wrong code, network, pin mismatch, "unpaired... | Done |
| A6.DU2 | [medium] The library shows another recording's transcript when resp... | Done |
| A6.DU3 | [medium] A processing transcript is shown as "The transcript is emp... | Done |
| A6.DU4 | [medium] "No client" in Settings cannot clear the selected client. | Done |
| A6.DU5 | [medium] The library layout breaks below about 1030 px (minimum win... | Done |
| A6.DU6 | [medium] Library search only looks at the 160-character preview (co... | Done |
| A6.DU7 | [medium] Accessibility: unnamed selects, misused tablist role, tran... | Done |
| A6.DU8 | [low] Layout details: long titles push the status pill out, an unbr... | Done |
| A6.M1 | [high] UploadWorker leaves a recording stuck "uploading" on an unex... | Done |
| A6.M2 | [high] Startup races when settings load after the first frame: lock... | Done |
| A6.M3 | [medium] The share entry accepts `file://` URIs. | Done |
| A6.M4 | [medium] Delete and Unpair act on one tap with no confirmation. | Done |
| A6.M5 | [medium] A processing transcript is shown as empty. | Done |
| A6.M6 | [medium] Android lint: 4 errors, 19 warnings. | Done |
| A6.M7 | [low] Parsing details: media types with codec parameters, pairing p... | Done |
| A6.M8 | [low] "No recordings yet" during the first sync, "Pull to refresh" ... | Done |
| A6.M9 | [low] Rotating the phone returns to the library (tab, open recordin... | Done |
| A6.M10 | [low] Status bar icons are dark on the dark app background in the l... | Done |
| A6.M11 | [low] In landscape the library header, search, banner and filters l... | Done |
| A6.M12 | [low] The Record tab upload card does not update until the tab is o... | Done |
| A6.R1 | [medium] docs/research notes reference files that no longer exist a... | Done |
| A6.R2 | [medium] `.gitignore` does not ignore `.env` files outside `web/`. | Done |
| A6.R3 | [low] Unreachable objects in the local object store; pushed history... | Done |
| A6.R4 | [low] A unit test contains the production host name, a real-looking... | Done |
| A6.R5 | [low] Dead files, stale docs, inconsistent metadata across manifests. | Done |
| A6.R6 | [info] The Windows installer is not Authenticode-signed: needs a co... | Blocked |
| B1 | Auto-record rules (D, P): per app/site toggles, editable site list,... | Partial |
| B2 | Pause / resume and "off the record" (D, P): paused time not capture... | Partial |
| B3 | Bookmarks and tags (D, P, W): shortcut / pill / notification "Mark"... | Partial |
| B4 | Caption styles (D, P): size, opacity, lines, width, position preset... | Partial |
| B5 | Capture health and pre-flight (D, P): device picker, level meters, ... | Partial |
| B6 | Pro transcript player (W, D, P): speaker blocks, word highlight, cl... | Partial |
| B7 | Transcript editing and speaker names (W, D): inline edit, rename sp... | Partial |
| B8 | Global full-text search (W, D, P): filters (client, app, device, da... | Partial |
| B9 | Command palette and keyboard-first (W, D): Ctrl/Cmd + K. | Partial |
| B10 | Export and secure share (W, D, P): TXT, Markdown, DOCX, PDF, SRT, V... | Partial |
| B11 | Custom vocabulary 2.0 (W, D, P): global + per-client keyterms withi... | Partial |
| B12 | Language routing (W, D, P): English, Urdu, Hindi, English + Urdu, A... | Partial |
| B13 | Re-transcribe and compare (W): second engine, both versions kept, s... | Partial |
| B14 | Dictation 2.0 (D, P): push-to-talk and toggle, insert at cursor wit... | Partial |
| B15 | Clients and smart views (W, D, P): client folders (colour, avatar),... | Partial |
| B16 | Security centre (W, D, P): desktop app lock (Windows Hello / Touch ... | Partial |
| B17 | Retention controls (W, D, P): 7 / 14 / 30 / custom days globally an... | Partial |
| B18 | Notifications and activity centre (W, D, P): bell, OS notifications... | Partial |
| B19 | Onboarding and system-style settings (W, D, P): first-run wizard, g... | Partial |
| B20 | Usage dashboard and SaaS foundations (W): minutes and cost per prov... | Done |
| C1 | One token file per app (colour, 8-pt spacing, radius 8/12/16, shado... | Done |
| C2 | Desktop: Mica/Acrylic (Windows 11) with solid fallback, macOS vibra... | Partial |
| C3 | Web: custom Filament theme (Vite): page headers, breadcrumbs, stick... | Done |
| C4 | Android: Material 3 with optional dynamic colour, edge-to-edge, pre... | Partial |
| C5 | Motion 150–220 ms ease-out, respects reduce-motion. | Done |
| C6 | Loading, empty, error (Retry) and offline states on every list and ... | Done |
| C7 | Accessibility: WCAG 2.2 AA contrast, focus rings, full keyboard use... | Partial |
| C8 | Copy pass: short, plain, sentence case. | Done |
| C9 | Performance budgets measured and reported: desktop idle ≤ 150 MB an... | Done |
| P7.1 | All suites and linters green; tests for every new feature. | Done |
| P7.2 | Security re-sweep: no key leaks, pins intact, every API route autho... | Done |
| P7.3 | docs/MANUAL_QA.md, docs/API.md, docs/ARCHITECTURE.md, README.md upd... | Done |
| P8.1 | Windows installer built. | Not started |
| P8.2 | Android release APK built and signed. | Not started |
| P8.3 | Production deploy: database backup confirmed, maintenance mode, upl... | Not started |
| P8.4 | `v1.1` merged into `main` and pushed. | Not started |

## In progress right now
- **Phase 7 done.** Nothing in progress. The session ended with the Phase 7 commit on `v1.1`; no push, no deploy.
- B20 is done (Done, not Partial: web only; its checks in docs/MANUAL_QA.md section 16 are deploy verifications).
- Where things are:
  - Schema: `web/database/migrations/2026_11_01_00000{1,2,3}_*.php`; models `Plan`, `Workspace`, trait `Models/Concerns/BelongsToWorkspace.php`, `Models/Scopes/WorkspaceScope.php`.
  - Services: `Services/Workspaces/{CurrentWorkspace,Workspaces,PlanLimits}.php`, `Services/Usage/UsageReport.php`; `UsageSummary::toArray(?int $workspaceId)`.
  - Console: `Filament/Pages/Usage.php` (+ `Widgets/Usage/DailyUsageChart.php`, not on the dashboard), `Filament/Pages/WorkspaceSettings.php` (slug `workspace`), Settings → "Plan limits" (section key `plan`), middleware `Http/Middleware/EnsureWorkspaceMember.php` (replaces `EnsureSuperAdmin`), policies `Policies/{CaptureSession,Device}Policy.php`.
  - Tests: `tests/Feature/Workspaces/*` (isolation, roles and limits, usage, workspace page) and `tests/Feature/Database/WorkspaceMigrationCycleTest.php`; test helpers `unscoped()` and `withoutWorkspace()` in `tests/Pest.php`.
- Migration cycle result (B20): `WorkspaceMigrationCycleTest` seeds 3 users (2 super admins, 1 plain) × device, pairing code, client + keyterm, 7 sessions (1 binned) each with recording, transcript, marker, label, share link + view and a usage row; up → down → up keeps every row count identical, maps every row to its user's workspace, NOT NULL and the foreign key reject bad rows, and a second backfill run changes nothing. Passed on SQLite and on MariaDB 10.4 (2/2 each). Step 3 refuses to run while a row has no workspace.

## Next steps (in order)
1. **Next: Phase 8 release** (P8.1-P8.4, with the B20 deploy notes below). Needs from the user: the Windows signing certificate (optional, A6.R6), the Android release keystore and password (P8.2), SSH access (P8.3). Do not deploy before the user has run docs/MANUAL_QA.md Parts 1-4 on a copy or confirmed they accept shipping with the 36 [~] items unchecked.
2. Before the Windows installer: `tools/scan-secrets.ps1 -Targets <installer folder> <apk>` must print nothing before "done" (also in MANUAL_QA Appendix C).
## Phase 6 audit
- Screens. Desktop main window: Library (list, detail and player, decision and upload queue, bin), Record, Settings (10 groups), first-run wizard, pairing, lock screen, command palette, activity bell, toasts and banners. Desktop overlays: captions, control pill, review card (and "ask" chip), dictation bar; tray menu. Web: login (passkey), dashboard (checklist, stats, chart), sessions list (smart-view tabs), session page (player, share links, history, compare), search, clients (+ keyterms), recently deleted, security, devices, API credentials, settings (8 tabs), usage, workspace, profile, public share pages. Phone: library, session (player, tools, organise), record, call setup, settings hub + 8 groups, dictation, security and activity, onboarding, pairing, lock screen, QR scanner, home-screen widget.
- Screenshot tooling: desktop `desktop/screenshots/harness.html` (Vite dev, mocked Tauri IPC through `@tauri-apps/api/mocks`); phone `ScreenshotsTest` (Robolectric native graphics, writes `app/build/screenshots`); web = browser driver against `php artisan serve` on a scratch SQLite seeded with `--seed`. Shots go to `design-review/` (git-ignored).
- Problems, most visible first:
  1. Overlays (captions, pill, review, dictation): gradient top stripe and glowing gradient buttons look third-party; always dark (ignore light theme); caption body shows a half-cut line at the top; toolbar buttons 36 px and always visible; pill crams 9 controls, source name truncated ("Go…"), meters are two odd bars, icons of mixed weight; the four do not read as one set.
  2. Desktop library: every control boxed (toolbar has 5 bordered controls), 3 chip rows, heavy item cards; detail header cramped ("Copy text" wraps), "Final transcript · assemblyai" (technical), thin empty waveform bar; bell in a big boxed button.
  3. Web dashboard: the setup checklist renders as unstyled text (no theme build: our Blade Tailwind classes never compile) — same risk on every custom Blade view (usage, search, player, share log).
  4. Web sessions/player: tall two-line rows, table overflows at 1280 px and at phone width, empty toolbar row, breadcrumb "Sessions > List", oversized page titles, native `<audio>` player, "Engine assemblyai", "Language en".
  5. Phone: dark only (no light theme, no dynamic colour); library header crams subtitle + 4 actions (subtitle wraps 4 lines); 3 chip rows before the list; heavy cards repeat device/app as badges; session page puts "Organise" above the transcript; gradient REC orb.
  6. Desktop settings: section label touches the group nav (no gutter), title duplicated (GENERAL / General), cards touch; quiet-hours time fields full width; subtitle "certificate pinned".
  7. Desktop record and onboarding: glowing REC orb, raw "chrome.exe", technical copy ("never takes focus; clicks pass through", file extension list); wizard Continue / Skip misaligned, steps look like filter chips.
  8. Main window has no window material (no Mica / vibrancy), font stack starts with Inter (not bundled), polls status every 1.5 s even when hidden.
  9. All apps: no shared spacing / radius / motion tokens (radii 9-14 px mixed, transitions 0.12 s), several hard-coded colours.

## Phase 6 results
- **Desktop idle** (release build `npx tauri build --no-bundle`, fresh unpaired data with `%APPDATA%\com.echoledger.desktop` moved aside and restored unchanged after each run, `--autostart`, 30 s settle + 60 s sample, private working set as in Task Manager, summed over the app and every `msedgewebview2.exe` in its process tree; method: docs/MANUAL_QA.md P-1). Budgets ≤ 150 MB and < 1 % CPU: **met**.
  | State | Processes | Private working set | CPU (of 12 cores) |
  |---|---|---|---|
  | Before C9 (5 windows built at start) | 11 | 310 MB | 0.03 % |
  | After: idle, only the dictation bar kept ready | 7 | **96 MB** | **0.04 %** |
  | After a dictation and opening + closing the main window | 8 | 126 MB | 0.22 % |
  | No window at all (dictation shortcut unbound) | 1 | 3-5 MB | 0.01-0.02 % |
  - What the 96 MB is: WebView2 browser ~35 MB, GPU ~27 MB, the dictation bar's renderer ~15-18 MB, utility ~11 MB, the app 4 MB. The browser grows to ~59 MB once the main window has been open (caches), hence 126 MB.
- **How (C9, `desktop/src-tauri/src/lifecycle.rs`)**: nothing is built in the setup hook. Main window `"create": false`, built from its tauri.conf.json entry when opened (tray, second launch, notification, navigate), shown when its page is ready (fallback 1.5 s), **destroyed on close**; where the user was (page, open recording, scroll of `.content` / `.list` / `.detail`) is kept in the core's memory for this run (`main_view_get` / `main_view_set`, `lib/mainView.ts`). Overlays (captions, pill, review card + ask chip, dictation) are built on first use and **destroyed 30 s after they are hidden** (`HIDE_GRACE`); a rebuilt one reads corner, size, content protection and pill position from the settings. Events for a window still being built are kept (latest of each kind, in order) and sent when the page calls `window_ready` (after its listeners are attached); captions are replayed by `CaptionHub` (status, last 200 final lines, each channel's partial). No-focus rule unchanged: same builder (`focused(false)`, `focusable(false)`, never `show()`); tao-0.37.2 builds such windows with `WS_EX_NOACTIVATE` and `SW_SHOWNOACTIVATE` (window_state.rs 293 / 461), so one built mid-call does not take focus. Device checks: MANUAL_QA V-D8-V-D11.
- **Open latency** (trigger → page ready = rendered and listening, release build, logged by `lifecycle::ready`): cold, no web view running: captions 1104 ms, pill 990 ms, review 1007 ms, dictation 981 ms. With WebView2 already running (dictation bar kept ready): captions 534 ms, pill 593 ms, review 594 ms, main window 456-695 ms. **Dictation**: building it already starts on the key going down, but ~1 s is not instant, so the **dictation bar is the one window kept warm** (`lifecycle::WARM`, while a dictation shortcut is bound): pressing the shortcut shows it with one event. Overlay latencies were measured with a temporary probe build (not committed) that opened each overlay through `lifecycle::show`, because a real recording needs pairing and pairing a test copy would overwrite the real device token in Windows Credential Manager (see Gotchas).
- **Fixed on the way**: a dictation that could not start (not paired, no network) left the bar on screen for good, drawing at ~3.9 % CPU; it now hides 4 s after showing why (`DictationApp` `ENDED_MESSAGE_MS`, regression test). The Phase 6 screenshot review also fixed: quiet-hours time fields full width (desktop settings), wizard "Skip setup" and "Continue" on separate rows, review-card harness deadline in the past ("Keeping in 0 s").
- Fixed in C9: the hidden main window stopped polling (1.5 s status, 2 s microphone scan); the overlay click-through loop idles at 4 Hz (was 30 Hz) and wakes on a new hit region; detection already runs at 1 Hz.
- **Web** (3,005 recordings with transcripts + 30 clients on SQLite, `php artisan serve` with opcache off, median of 5 warm loads, request start to first byte): dashboard 210 ms, sessions list 231 ms, session page with player 90 ms, clients 100 ms, search 127 ms, usage 86 ms, settings 83 ms (budget < 300 ms: met). Lists are paginated (the members table now too); lazy loading throws outside production and found 3 N+1s (bulk zip export, transcript purge, console player) plus a per-row role query on the sessions list; query-budget tests guard the sessions and clients lists and `/api/library`.
- **Phone**: no wake locks; the widget updates on events only (`updatePeriodMillis="0"`); the only periodic job is the 15-minute activity check decided in B18.
- **Recording with captions (< 8 % CPU)**: needs a real call and a provider key; how to measure: docs/MANUAL_QA.md P-2.
- Before / after screenshots (design-review/, git-ignored; every after shot re-taken at the end of Phase 6 from the final code): `before/<name>.png` → `after/<name>.png` for
  - desktop (22): desktop-{library,library-empty,record,settings,onboarding,lock,overlay,pill,review,dictation}-{dark,light}, plus desktop-mica-accent-{dark,light} (after only);
  - web (28): web-{login,dashboard,sessions,player,clients,settings,usage}-{dark,light}[-phone] (the old after "dashboard" shots showed the sign-in page; the new ones are signed in);
  - phone (16): phone-{library,library-empty,session,record,settings,settings-recording,onboarding,lock}-{dark,light} (before: dark only, without the suffix).
## Phase 7 gap check
Every item of PLAN_V1_1.md that was not `[x]` when Phase 7 started, and what became of it.

| Item(s) | State | Result |
|---|---|---|
| A6.M9, A6.M11, A6.M12 | (b) unfinished code | **Finished with tests.** M9: `MainActivity` keeps the tab, the open recording (id) and the setup page with `rememberSaveable`, and takes the recording from the live library (`RotationTest`, 2 tests). M11: below 480 dp of height the Library page (header, search, banners, filters) scrolls inside the list (`RotationTest`, fails with `compact = false`). M12: `RecordingStore.changes` counts every save / delete and `MainViewModel` reloads `pending` from it through one `collectLatest` loader that `reloadLocal()` also feeds, so a newer trigger cancels an older read (`MainViewModelTest`). The A6.M10 row of the table below was stale (the item was already done). |
| A1.1-A1.3, A1.5, A1.8 | (a) device check | MANUAL_QA steps 25-29; appendix W-04, W-05, W-06, C-D1, V-D5, V-D6, M-03. |
| A2.2, A2.7 | (a) | Steps 37, 41-44 (desktop review card) and 77-80 (phone heads-up). |
| A3.1-A3.4 | (a) | Steps 27-28, 30-34, 44-45; appendix C-D5. |
| A4.2, A4.3, A4.5 | (a) | Steps 96-98; appendix V-A3, V-A4 (old section 7 IDs are kept in the Ref column). |
| B1-B5 | (a) | Steps 24-36, 70-76, 82; appendix C-D1, C-D5-C-D7, C-A1-C-A7. |
| B6-B13 | (a) | Steps 46-52, 84-88, 108-116 and appendix T-D3, T-D5, T-A4, T-W8 (languages, vocabulary). |
| B14 | (a) | Steps 56-60, 92-95; appendix DI-D3, DI-D5-DI-D7, DI-A3-DI-A7. |
| B15-B17 | (a) | Steps 4-6, 53-55, 63-65, 90-91, 117, 121-123; appendix O-*, S-*, R-4. |
| B18, B19 | (a) | Steps 7-10, 19-22, 39, 68-73, 99-100; appendix N-*, O-*, S-*. |
| C2, C4, C7 | (a) | Steps 61, 101; appendix §17 V-D3/V-D5, V-A2/V-A3, B (screen readers, reduce motion). |
| A6.R6 | blocked | Needs a code-signing certificate (user). Noted in MANUAL_QA step 17 and appendix C R-01. |
| P8.1-P8.4 | Phase 8 | Not part of this session. |

Every old MANUAL_QA ID is still in the new file (checked by script against `git show HEAD:docs/MANUAL_QA.md`).

## Phase 7 test coverage and the speed tests
- **Speed tests (`TranscriptValidationSpeedTest`).** The old tests compared the wall clock with 2 s (4 s in parallel) and failed on battery. The new ones never compare with a fixed budget:
  1. *Linear time:* post 2,000 segments (best of 3) and 20,000 segments (best of 2) in the same process after a warm-up; the ratio must stay under 40 (linear measures about 10, the old super-linear validation about 100, whatever the CPU speed). A 60 s hang guard stays.
  2. *Same queries:* the 20,000-segment request issues exactly as many queries as a 200-segment one.
  3. *Refusing 20,001 is no slower than accepting 20,000* (measured in the same test, 3x + 0.2 s).
  Proof: 3 runs in a row on SQLite and 3 on MariaDB were green, and 2 more with 16 busy loops saturating the CPU. The full suites: SQLite 3065 / MariaDB 3065 passed.
- **End-to-end web test (`EndToEndMockedSttTest`).** Keep: pair a device through `/api/devices/register`, open a session, mint stream tokens, add a bookmark, assert nothing paid was called yet, finish (stop + Keep), upload chunks out of order, `complete`, the signed webhook, the final transcript, the provider copy erased, usage report, search (by words, bookmark filter, tag filter), export (TXT, SRT), share link (opens, access logged), retention (aged 40 days → bin → share link 404 → bin emptied → audio, transcript and file gone). Discard: open session, token, bookmark, delete the session → no recording, no transcript, no `/v2/` call, no server-side ledger row, no stored file, token for it refused.
- **Filament coverage.** Added tests for the two actions that had none: the share-link "Access log" (outcomes, user agent, hashed visitor, nothing raw) and "Remove the second version" in the compare dialog. `openSecurity` / `openDevices` are plain links. Every other page / resource / relation manager / widget / action named in `app/Filament` is exercised (checked by name against `tests/`).
- **API coverage.** All 48 `/api/*` routes appear in the tests (checked from `route:list`), `ApiDocumentationTest` ties them to `docs/API.md`, `WorkspaceIsolationTest` generates one isolation case per route and resource.
- **Desktop.** Rust logic is in `tests/*.rs` (36 files) plus unit tests in `src/`; UI in 45 Vitest files, one per view or overlay (pill, review card, captions, dictation, lock, onboarding, settings, library, transcript tools). New: the consent-notice test below.
- **Phone.** Robolectric tests exist per flow (pairing, onboarding, record, setup, library, session, transcript tools, organise, retention, security centre, activity, settings hub / files, app lock, widgets, share import). Added `DictationScreenTest` (12 tests: the voice keyboard keys and status line, the settings cards, replacements, snippets, history) because only the text rules had tests, and `RotationTest` (3).

## Phase 7 security re-sweep
Checked 2026-10-27 against the code and the built artefacts. Nothing needed the user's keys; the local database has no provider credentials.

| Area | Finding | Result |
|---|---|---|
| Provider keys in builds | `tools/scan-secrets.ps1` (new, run by hand; its patterns: ElevenLabs `sk_` keys, private key blocks, minisign secret keys, AWS ids, JWTs, webhook secrets, Laravel app keys, the recordings key name, provider key variable names, Sanctum tokens, long bearer literals) over `desktop/dist`, `echoledger-desktop.exe`, `app-debug.apk` and `app-release.apk` (the 24 Oct build; all entries, dex and resources included). A canary string proved the scanner works. | **0 hits.** |
| Keys in logs / responses | No `println!`, `eprintln!`, `log::` or `Log.` call prints a token, key, password or authorization value (grep over `desktop/src-tauri/src`, `mobile/app/src/main`); the only server log line mentioning a key is the audit text "Device key renewed". API responses: `AdminSecretLeakTest`, `SecretsHandlingTest`, `ApiAuthenticationTest` pass. | Clean. |
| API authorisation | All 48 `/api/*` routes listed with middleware: every route except `devices/register` (pairing code, 5/min/IP), `recordings/{recording}/audio` (signed URL) and `webhooks/stt/{provider}` (signature, 300/min) has `auth:sanctum` and a `CheckAbilities`; `devices/rotate-token` has none by design (a token can renew only itself, and keeps its abilities, A6.W18). Workspace scope: `WorkspaceIsolationTest` (route-list driven) passes. | Clean. |
| Share links | Token = 32 random bytes (256 bit, base64url, 43 chars), only the SHA-256 is stored; expiry, revocation, binned session and password are re-checked on every request; 10 password tries / minute / address / link, 120 requests / minute / address; every opening and wrong password is logged with a keyed hash of the IP; headers `noindex`, no referrer, no store. | Clean. New tests: unique 256-bit tokens, password throttle (11th try gets 429), one unlocked link does not open another. |
| Uploads, exports, imports | Size, MIME allowlist, per-chunk and whole-file SHA-256, server-generated ULID paths (`UploadAbuseTest`, `MassAssignmentTest`, `RecordingEncryptionTest` include `../../etc/passwd`, `C:\boot.ini`, `%2e%2e%2f`, NUL bytes); export file names are built from the title and stripped for every file system; the bulk zip names are unique. | Clean. |
| Settings import | Console: unknown keys, `credentials` and `api_key` ignored (`AdminSettingsGroupsTest`). Phone: pairing, token, lock and consent untouched (`SettingsTransferTest`, `SettingsFilesTest`). Desktop: **FIXED** `consentAcknowledged` was importable, so a settings file could tick the recording-law notice for the person; it is now in `NOT_PORTABLE` (not exported, not imported) with test `an_import_never_acknowledges_the_consent_notice`. The app lock still cannot be turned on from a file without a PIN. | One fix. |
| Pinning, lock, passkeys | `tls_pinning.rs`, `ApiClientPinningTest`, `AppLockTest`, `app_lock.rs`, `AppLock.test.tsx`, `PasskeysTest` all pass. A forgotten desktop PIN has no reset in the app; recovery is deleting `app_lock.json` with the app closed (documented in the user guide). | Intact. |
| Audit log | Events recorded: login, login_failed, logout, passkey_added / removed / login, password_changed, device_paired / revoked / token_rotated, session_deleted, transcript_exported, **sessions_exported**, share_created / revoked, credentials_changed, web_session_revoked. `sessions_exported` was the only one without a test; added (it also asserts no content in the row). Opening a share link is logged in `share_link_views`. | Clean. |
| Dependencies | `composer audit`: no advisories. `npm audit --omit=dev`: 0 in `web/` and `desktop/`. `cargo audit` (installed with `cargo install cargo-audit --locked`; 1378 advisories, 626 crates): **0 vulnerabilities**, 5 warnings (`proc-macro-error` unmaintained, `zbus_names`, `zvariant`, `zvariant_derive` unmaintained, `glib` 0.18.5 unsound `VariantStrIter`) that come only through Linux-only GTK / D-Bus crates of Tauri: `cargo tree --target x86_64-pc-windows-msvc` and `aarch64-apple-darwin` show none of them, so they are not in our builds. Gradle: no dependency-check plugin is configured and adding one needs an NVD key; the versions are in `mobile/gradle/libs.versions.toml`. Run the OWASP dependency-check (or a Dependabot-style review) once before the signed APK. | No high / critical issue. |
| Left as is | After a recording is binned and erased its `share_links` row stays (the page answers 404 because the link checks the session). Harmless; not changed. | Documented. |

## Phase 7 docs
- New: `docs/USER_GUIDE.md` (plain English: install, pairing, auto-record, captions, Keep / Discard, bookmarks, dictation, search, export / share, clients and vocabulary, languages, app lock, retention, usage, troubleshooting). Statements were checked against the UI strings (e.g. "Auto-record calls", "Transcribe again", Settings → Devices → This phone → Setup guide).
- Rewritten: `docs/MANUAL_QA.md` is one ordered list of 125 steps in four parts (web 16, Windows 51, Android 38, web again 20), a result table, and appendices for long checks, accessibility, macOS and release day. Each step has one action and one expected result, a tick box and the old ID.
- Updated to the real code: `README.md`, `docs/ARCHITECTURE.md` (data model with every v1.1 table, Keep / Discard, bookmarks, transcript tools, share links, search, retention and the bin, workspaces and plan limits, usage, sign-in and audit, desktop structure and windows built on demand, security rows, scheduler), `docs/PROVIDERS.md` (where each setting lives, languages, vocabulary, second engine, usage and the monthly limit). `docs/API.md` already matched the router (`ApiDocumentationTest`); no change needed.
## Prompt 1 (languages and accuracy): steps 1 and 2 live (v1.2 server, deploy 2026-10-27 22:34 server time)
- **Provider facts checked 2026-10-28:** AssemblyAI pre-recorded Urdu only on Universal-2 (Universal-3.5 Pro does not list it); U-3.6 Pro streaming lists Urdu and takes `language_codes` (connection or UpdateConfiguration; we already sent it since v1.1.2, so "live captions get no language" was only true for Auto or old apps). Groq `whisper-large-v3` US$0.111/h; **direct upload 25 MB** (100 MB only by URL, dev tier), `prompt` ≤ 224 tokens, verbose_json segments carry avg_logprob / compression_ratio / no_speech_prob, words only word/start/end. OpenAI whisper-1 and gpt-4o-transcribe shut down 2027-02-26. Gemini 3.5 Transcribe lists no Urdu (only ar-EG Arabic); the only current 3.x Pro id is `gemini-3.1-pro-preview` and the models page does not confirm audio input (`gemini-3-pro-preview` is shut down). Soniox strict hints are best-effort. The published benchmarks were not re-run.
- **Step 1 (`f70c284`):** `LanguageChoice` = Auto / en / ur / ar + MORE (fr de es it pt nl tr ru); ur and ar always come with English; aliases (hi, urd, Hindi, ar-EG, …); `fromDetected` (Hindi → Urdu), `autoLanguages` (`languages.auto_more`), `allows`, `fallback` (`languages.auto_fallback`, default ur). Migration `2026_11_04_000001_unify_languages` (sessions, clients, transcripts, default_language, routing keys). Routing per en / ur / ar / other / auto (`LanguageRouter::routeKey`, merge folds v1.1 keys; the old Urdu + English list wins over the Hindi ones). `ScriptCheck`: Devanagari wrong for every recording, Roman Urdu/Arabic flagged. `TranscriptStore::complete`: a result in a language the recording may not use (Auto) or in Devanagari is run once more forced (`meta.forced_language`, `forced_reason`, billed first run kept); lines still Devanagari become `[unclear: wrong script, re-transcribe]` with `raw_text` + `flag`. AssemblyAI Auto uses `language_detection_options.expected_languages`; Soniox Auto adds non-strict hints. Config `languages.options[].more` and `languages.auto`.
- **Step 2 (`0f66d6a`):** `GroqWhisperDriver` (sync) + `App\Stt\Audio\AudioPrep` (static ffmpeg: split each side to 16 kHz mono, silencedetect, `plan()` cuts at pauses ≤ 600 s and skips silences ≥ 3 s, FLAC chunks) + `WhisperGuards` (standard thresholds, loops, uncertain < -0.8) + `UrduText::normaliseUrdu` + `Segment::uncertain`. Groq runs on the queue, never on the upload request. Routing defaults: en AssemblyAI → Groq → ElevenLabs → Soniox; ur Groq → Soniox → ElevenLabs → AssemblyAI; ar and other ElevenLabs → Soniox → Groq → AssemblyAI (four slots per language in Settings). Price groq 0.111, billed by `meta.billed_audio_ms`. Progress in `meta.progress` {chunks_done, chunks_total} (not shown by the apps yet).
- **Production host (tested):** static ffmpeg 7.0.2 in `~/bin` (unpacked with python3 lzma: no xz on the host), PHP CLI `proc_open` allowed (exec/shell_exec/popen disabled), split + resample of 10 min stereo Opus ≈ 1 s; ffmpeg must get `-nostdin`. `audio.ffmpeg_path` is set in the production settings (not in the repo). **The queue worker runs** (database queue; a probe job was picked up within 100 s), so the cron question is settled.
- **Deploy:** backup `/home/u290685119/echoledger-backups/pre-v1.2-20261027-223341/` (33 tables, dump completed, 19 replaced files; keep 30+ days) + laptop copy `C:\echoledger-backups\pre-v1.2-20261027-223341\`; 23 files + migration; down 4 s; verified `/admin/login` 200, `/.env` 403, `/api/config` 401, migration ran, 0 old codes left, routing keys en/ur/ar/other, ffmpeg found, no new log errors. **Production has no Groq, Soniox or ElevenLabs key yet**: Urdu falls back to AssemblyAI Universal-2 until the Groq key is added (API Credentials → Groq).
- Web: 3133 tests on SQLite and MariaDB, PHPStan 0, Pint clean, repo policy 23/23. Old apps keep working (they send `en+ur` / `hi`, read as Urdu) but still show the old menu.

### Prompt 1: done after the v1.2 deploy (committed, not deployed yet; the apps need the new installer and APK)
- **Apps' language list (`d3ec68f`):** desktop and phone offer Auto, EN, UR, AR, then More languages; the chip steps Auto → EN → UR → AR; stored `en+ur` / `hi` / `en+hi` read as `ur`.
- **Language marks (`4d33bab`):** a chip change during a recording is saved as `{at_ms, language}` (`capture_sessions.language_marks`, migration `2026_11_05_000001_add_language_marks`, at most 50); the desktop and the phone send them with create / finish; `AudioPrep::plan` cuts there and Whisper on Groq takes each piece's language (`SessionMarkers::languageAt`); marks win over detection.
- **Live captions (`e3a4f64`):** Devanagari from an engine that guessed Hindi is shown in Urdu script (desktop `lib/devanagari.ts`, phone `core/Devanagari.kt`; display only). Soniox real-time for languages the streaming engine lacks moves to Prompt 3 (Live Translate builds the Soniox real-time client).
- **Re-transcribe as… with versions:** `Retranscriber::startAs` + `POST /api/sessions/{id}/retranscribe` (`sessions:write`, 202, `409 cannot_retranscribe`) + console action `retranscribe_as`. The new run is an `alternate` with `meta.promote_when_done`; `TranscriptStore::complete` makes it the final one when it is done and the old one becomes the `alternate` (two versions kept). A failed transcript runs again as the final one. Library items carry `alternate_transcript_id`, `alternate_status`, `alternate_language`. Desktop Library detail: language select + **Re-transcribe** + **Current / Previous (…)**; phone recording page: **Re-transcribe as…** dialog + the same switch (`ui/Retranscribe.kt`).

### Prompt 1: next steps (in order)
1. **Uncertain words:** done in the desktop Library, the phone recording page and the console player (`Word::UNCERTAIN_BELOW` 0.5 on AssemblyAI / ElevenLabs / Soniox word confidences, `Segment::uncertain` from Whisper and Azure phrases; dotted underline, a tap plays from the word). **Next:** optional "Double-check" (second engine on uncertain chunks, disagreements shown, no merging).
2. **Gemini 3.1 Pro preview driver** (candidate only, paid tier, delete the file after), after confirming audio input on the model page.
3. **Show as** (Original · Roman Urdu · English): Claude API text-only views cached per transcript, labelled; Noto Nastaliq Urdu / Noto Naskh Arabic bundled in the apps and the console; exports and share links pick the view.
4. **Accuracy Lab** (owner only): reference transcripts, CER/WER per engine (normalised), speed and cost, "Use the winners" → routing; reuse `EngineComparison` trials. USER_GUIDE how-to with 10 recordings.
5. **Cost per engine in Usage**; a new provider-privacy page in the research folder (retention / no-training per provider with links).
6. **Per-contact default language** (needs the client contacts from the caller-names scope).
7. Progress (chunks done / total) in the apps from `meta.progress`.
- **Queued after Prompt 1, each in its own session:** Prompt 3 (Live Translate, Phase 0 needs the phone and the games), then Prompt 2 (mobile redesign, plus the Live Translate screens). Earlier, not started: caller names on recordings, SIM calls as a source. Signed APK still waits for the keystore.

## v1.1.2 live (production deploy 2026-10-27 18:44 server time)
- **Contents:** language accuracy (server part of `d8f20e6`) and the "No audio" display (`05e97e5`): 42 changed or new files under `web/` (no deleted files, no dependency changes) and the migration `2026_11_02_000001_add_language_accuracy`.
- **Backup (keep at least 30 days, do not delete):** server `/home/u290685119/echoledger-backups/pre-v1.1.2-20261027-183249/` (database.sql.gz "Dump completed", files-before.tar.gz with the 34 replaced files, files.txt, SHA256SUMS; mode 700/600) and a verified copy on the laptop `C:\echoledger-backups\pre-v1.1.2-20261027-183249\`.
- **Steps:** bundle sent through `plink` (stdin, base64; `pscp` kept being cut off and too many attempts got the IP blocked for a while) and checked by SHA-256; `down` → extract → `migrate --force` → `optimize` → `filament:optimize` → `queue:restart` → `up`, run under `nohup` with a log (`~/v112-deploy.log`). Down for 4 seconds.
- **Verified:** `/admin/login` 200, `/.env` 403, `/api/config` 401, log file 403; migration ran; all 46 transcripts have `search_text`; no new errors in `laravel.log`; a real library item renders with `language_chip` and `final_error_code` (the 19:15 WhatsApp Business call is the one "No audio" recording).
- **Rollback, if ever needed:** `down`, `tar xzf files-before.tar.gz` in public_html, delete the 8 new files (EngineComparison, SonioxDriver, LanguageRouter, RouteDecision, ScriptCheck, UrduText, the migration, engine-results.blade.php) after `migrate:rollback --step=1`, `optimize`, `up`.
- **Apps:** installer `desktop/src-tauri/target/release/bundle/nsis/EchoLedger_1.1.0_x64-setup.exe` (unsigned) and debug APK `mobile/app/build/outputs/apk/debug/app-debug.apk` (versionCode 5, 1.1.2), both scanned clean. Signed release APK waits for the keystore.

## Phone call capture: "No audio" on the S25 Ultra (v1.1.2, 2026-10-27)
- **Where the sound is lost (traced in code, the device itself was not available):** call detection works (the session is created as WhatsApp Business), upload works (the server stored the file and reported `no_audio`); the stage that yields nothing is the **helper's recorder**: 0 PCM bytes reached the app's pipe, so the encoder wrote 0 frames (duration 0).
- **Defects found that produce exactly that:** (1) the helper's `ShellContext` overrode the package name but not `getAttributionSource()`, which still named package `android` for UID 2000; since Android 12 the audio server checks the attribution source, so recording can be refused or muted; (2) the capture loop did `if (m <= 0) continue`: a recorder that never started (`read` returns an error) looped forever writing nothing, and nothing was reported; (3) `startCapture` always returned true and errors went only to Logcat; (4) the Shizuku daemon is replaced only when `UserServiceArgs.version` changes, and it was `versionCode` = 4 for every build, so **phones kept running the first helper they ever started** — no helper fix could reach them; (5) after Android stopped the app nothing reconnected the helper (Shizuku bound only on the Connect button, the ADB helper handed its binder over once), and auto-record turned itself off silently when the helper was missing.
- **Fixed:** shell attribution source; microphone opened with fallbacks (chosen source, `MIC`, `VOICE_RECOGNITION`, with and without the context) and only a recorder that is really recording is used; far side as before (dynamic policy mix), mic-only or far-only allowed; neither side → `startCapture` returns false with the reason; 50 failed reads in a row stop with a reason; `HELPER_BUILD` (minutes since 2026-01-01, a new number every build) is the Shizuku version and `version()`; Setup shows an orange "older version" note; `versionCode` 5 / `versionName` 1.1.2.
- **Liveness:** `HelperConnection.autoConnect()` (Shizuku sticky binder listener) at app start; the ADB helper repeats its hand-off every 30 s; `RearmReceiver` (BOOT_COMPLETED, MY_PACKAGE_REPLACED) turns auto-record back on; armed without a helper stays on, shows "Call capture isn't ready — tap to fix" and a 5 s watchdog rebinds Shizuku; a call (`MODE_IN_COMMUNICATION`) seen without the helper posts the same notice at once (opens Setup).
- **Package list:** `core/CallTargets` (`com.whatsapp`, `com.whatsapp.w4b`) is used by the helper's voice-call player lookup and `SourceApp.fromPackage`; capture itself matches by usage (voice communication), not by package. Business was already covered.
- **Test call capture** (Setup): `CaptureTest.run` records 10 s through the helper exactly like a call (not kept), `CaptureTest.classify` (pure, unit-tested) gives helper / WhatsApp / your voice / other side (only judged during a call) / saving with a plain result and fix. **Copy diagnostics**: `CaptureLog` ring buffer (200 lines; stages, app labels, counts, helper messages; never audio, names, numbers or text).
- **No audio, everywhere:** library items carry `transcript.final_error_code`; phone and desktop show a grey "No audio" dot/pill, the phone hides the player and sharing and offers Run capture test / Delete; the console shows a grey "No audio" status with the hint, no player, no Share action, no share-links section (`CaptureSession::hasNoAudio()`).
- **Still to confirm on the S25 Ultra (Android 16):** whether the far side reaches the dynamic policy mix on One UI 8.5. The test says it plainly ("Your voice is recorded, but the other side of the call is silent") if not; that would be an OS limit, not something the app can fake.

## Language accuracy (v1.1.2, 2026-10-27)
- **Built on B12/B13, not rebuilt.** Server (commit `d8f20e6`): `LanguageChoice` codes `en`, `ur`, `en+ur`, `hi`, `en+hi` (English first), `LanguageRouter` (engine per language from `routing.final`, fallback to the next configured engine, `RouteDecision` in `meta.route`), Soniox driver (async, per-channel jobs or diarization), `ScriptCheck` (wrong script, `meta.script_warning`), `UrduText` folding into `transcripts.search_text` (FULLTEXT on MySQL/MariaDB), `EngineComparison` (trial transcripts, unique key now session + kind + provider). Web 3114 tests on SQLite and MariaDB, PHPStan 0, Pint clean.
- **Desktop:** `AppState.recording_language` (client_ref + choice) used for the open session, the caption token (`language`) and the pending decision; `set_recording_language` restarts captions only (the capture keeps running); `pending_set_language`; `FinishSessionRequest.language` always sent; `CLIENT_PATCH_KEYS` has `language`; pill window 352 x 84 with a language chip that steps through the choices (the window cannot open a menu); review card chip with a list and the learn prompt; waiting-list select with the learn prompt; library chip and "Transcribed in … · engine" plus the wrong-script notice. 1011 Vitest and the full Rust suite pass, clippy clean.
- **Phone:** `core/Languages`; `ACTION_LANGUAGE` on `RecordingService` (closes the caption sockets, reports their usage, fetches a new token with the language; capture untouched); a tappable chip window beside the click-through caption bubble; chips on the Record tab (live card and waiting list, learn prompt in `MainViewModel`) and on the popup; `FinishSessionRequest.language` ("" = automatic so the choice always arrives); `ApiClient.setClientLanguage`. Notification actions stay Pause / Mark / Stop (Android shows three); the text shows the chip. 900 tests (one Compose timeout flake in `ScreenStatesTest` passed on rerun), ktlint clean.
- **Not done here:** production deploy, signed APK, new installer (see the top of this file for the state).

## Round 3 fixes (2026-10-27 evening)
- **Phone Library selection:** the bulk panel no longer pushes the list off the screen. The whole Library page now scrolls (header, search and filters scroll away with the list), and while selecting, the actions sit in a panel pinned to the bottom (max 320 dp, scrolls itself) with **Select all** and Done, so more recordings can be ticked. Tests: `LibraryOrganiseTest` (Select all / Done), `RotationTest` landscape.
- **Screenshots:** "Hide content in Recents and screenshots" is now **off by default** and an older stored "on" (it was only the old default) is reset once (`settingsVersion`, migration in `SettingsStore.toSettings`); screenshots are allowed unless the person turns it on. Test: `SettingsStoreTest`, `SettingsScreenTest`.
- **Failing recordings, diagnosis:** the server is fine (32 of 32 uploads `ready`). Every failure on production is `no_audio`: the phone uploaded recordings that contain no sound (WhatsApp calls with 0 ms), i.e. call capture is not running (the helper, Shizuku or ADB, is not connected, or Android gave no audio). The phone now drops a 0 ms recording itself, deletes the open server session and posts "No audio was captured" with where to look, instead of asking Keep / Discard and uploading silence. **To really fix it on the phone: Settings, Devices, This phone, Setup guide, connect the helper, then test a call.** Test: `PostCallPromptTest`.
- **Desktop Activity panel:** now closes with its X button, Esc and a click outside (test in `ActivityCentre.test.tsx`).
- **Desktop Library filters:** the three rows of chips are replaced by one **Filters** button (count badge) that opens a dropdown of checkboxes in three groups (Show, Recorded on, Bookmark tags; ticked boxes add up inside a group, groups narrow each other, "Clear all", closes with Esc / X / outside click). `LibraryFilters.tsx`, `applyFilters` in `LibraryView.tsx`; tests updated; 996 desktop tests pass.
- **Mistake and fix:** a `git add -A docs` committed the private upgrade brief (pushed in `27d5c07`); it is untracked again but still in that commit's history on GitHub until history is rewritten (needs the owner's go-ahead for a force push).
## Hotfix 1 and the call popup (2026-10-27, after v1.1 went live)
- **Bug:** the phone showed "The duration ms field is required." and Retry failed. Cause (read from the code at `e9e8d93` and the v1.1 phone): the **v1.1** phone (the debug APK installed that morning, not the v1.0 app: v1.0 never calls `finish`) opens a server session for live captions and ends it with `POST /api/sessions/{id}/finish`. That body leaves `duration_ms` out when the recording measured 0 ms (`rec.durationMs.takeIf { it > 0 }`), and the v1.1 server required it. The v1.0 one-shot create (`POST /api/sessions`) always had it nullable, so real v1.0 apps were never affected; `V1ClientCompatibilityTest` replayed only the happy path and missed the 0 ms case.
- **Why the call was 0 ms:** the recording captured no audio (the helper or microphone gave no frames). Worth checking on the phone: Settings → Devices → This phone → Setup guide (helper connected?). The hotfix only makes the upload go through; a 0-length recording still has nothing to transcribe.
- **Fix (server, deployed):** `finish` takes `duration_ms` as optional (still 0 to 86,400,000, integer) and derives it when absent: longest artifact duration, else `ended_at - started_at`. Phone: `finish` always sends the duration. Desktop already always did.
- **Tests:** `V1ClientCompatibilityTest` now replays the shipped payloads: v1.0 phone create (nulls omitted, 0 duration and sample rate left out) with chunks, complete, live transcript, delete; v1.0 desktop create (explicit nulls); the v1.1 phone finish without `duration_ms` (fails before the fix with 422, passes after); nonsense durations still 422. `SessionLifecycleTest` covers the optional field. Web: SQLite 3068 and MariaDB 3075 passed, Pint and PHPStan clean.
- **Deployed:** backup `/home/u290685119/echoledger-backups/pre-hotfix1-20261027-120858/` (database dump 33 tables + the two original files, SHA256SUMS); `down` → two files (`SessionLifecycle.php`, `SessionController.php`) → `optimize`, `filament:optimize` → `up`. `/admin/login` 200, `.env` 403. Live replay with a throwaway token inside a rolled-back transaction: open session 201, finish without `duration_ms` 200 (duration 0), finish with -5 422; no test row left. The phone's stuck session (WhatsApp, 11:49, status `recording`) is finished by pressing Retry.
- **Call popup:** after a WhatsApp or WhatsApp Business call recording ends, `PostCallPrompt` shows `DecisionPopup` (a small overlay card, bottom, 90 % wide, about 72 dp, theme light/dark, not focusable, outside touches pass through, owned by the app process so it survives the call screen closing). Keep / Discard run `Decisions.apply` (upload + transcript / delete, no upload, no cost) and close the card; a decision anywhere (notification, Record tab) closes it too. After 10 s it only disappears; the recording stays in "Waiting for your decision". Without "Display over other apps" no card: the heads-up notification (always posted as well) carries Keep / Discard. Default after-call timeout is now **Keep waiting**: `AppSettings.afterCallTimeout` is "wait" until the user picks an option in Settings (`decisionTimeoutChosen`), so an older stored "keep" (it was only the default) no longer uploads on its own. Tests: `PostCallPromptTest` (9) and `DecisionTimeoutWorkerTest`. Android: 889 tests, 0 failed (one Windows temp-file rename flake in `SettingsScreenTest` passed on rerun), lint 0 errors. MANUAL_QA rows 80a-80e.
- **Not on production yet:** the popup is phone-only; it needs the new APK.
## v1.1 live (Phase 8, production deploy 2026-10-27)
- **Backups (keep at least 30 days, do not delete):** server `/home/u290685119/echoledger-backups/pre-v1.1-20261027-084853/` (database.sql.gz 55 KB, public_html.tar.gz 25 MB incl. the 28 recordings, env.backup, SHA256SUMS; mode 700/600) and a verified copy on the laptop `C:\echoledger-backups\pre-v1.1-20261027-084853\` (outside the repo, all three hashes OK). The release tarball `~/echoledger-v1.1.0.tar.gz` also stays on the server.
- **Steps run:** `artisan down` with a bypass secret (given in chat only) → tar extracted over public_html (public/.htaccess and .env untouched) → 2 stale files removed (`EnsureAdminMfa.php`, `welcome.blade.php`) → `composer install --no-dev --optimize-autoloader` → `migrate --force` (18 migrations) → `optimize`, `filament:optimize` → `artisan up`. PHP 8.4 (`/opt/alt/php84`). The server dropped the connection many times (SSH throttling); the long session was abandoned and the state was checked step by step; nothing was redone.
- **Verified live:** `/admin/login` 200, `/.env` 403, `/storage/logs/laravel.log` 403, recordings folder 403, `/composer.json` 404, `/api/config` without token 401; row counts equal the backup (users 1, sessions 28, recordings 28, transcripts 41, devices 2, credentials 2, settings 20, webhook_events 22, tokens 2), 0 rows without `workspace_id`, 1 workspace "Personal", 24 migrations. A throwaway device token (rolled back; token count stayed 2) got `/api/config` 200 with `api_version` 1.1, the library (28 items), a session with its recording and 2 transcripts, a transcript with segments and a **decrypted audio range (206, 100 bytes, audio/flac)**, so the recordings key and the old encrypted files work. Pins: the `app.pins` setting is untouched and the domain and certificate did not change. `laravel.log` has no new errors.
- **Not verified:** the scheduler. `schedule:list` shows all 7 tasks, but `crontab` is not available over SSH and `~/laravel-schedule.sh` belongs to another site on the same account. Check hPanel → Cron Jobs for a `* * * * *` entry running `/opt/alt/php84/usr/bin/php artisan schedule:run` in this folder; without it webhooks still deliver transcripts, but `stt:reconcile`, retention and the sweeps do not run. Also not tested live: the user's 2FA login and a share-link page.
- **No rollback was needed.**
- **Artifacts:** installer `desktop/src-tauri/target/release/bundle/nsis/EchoLedger_1.1.0_x64-setup.exe` (5.3 MB, unsigned, scan clean). APK: not built yet.
- **Next:** build and sign the APK (keystore path, alias, passwords from the user, environment variables only); the user runs docs/MANUAL_QA.md on the live system; update the apps (v1.0 apps lose live captions only).
## Phase 8 progress (production release, no staging)
- **Artifacts.** Windows installer built unsigned: `desktop/src-tauri/target/release/bundle/nsis/EchoLedger_1.1.0_x64-setup.exe` (5.3 MB); `tools/scan-secrets.ps1` clean on it and on `echoledger-desktop.exe` (one target per call). Android signed APK: waiting for the keystore values (the message held placeholders).
- **v1.0 apps against the v1.1 server** (new `V1ClientCompatibilityTest`, 3 tests; the v1.0 contract is `docs/API.md` at `main`): pairing, config, the one-shot `POST /api/sessions`, chunk upload, `complete` (202), live-caption upload, library sync with `since`, session and transcript reads, deletion, token rotation all still work; the v1.0 Rust models and Kotlin `ApiJson` ignore unknown fields and read enums as strings, so new fields and values do not break them. **One known loss:** a v1.0 app's live captions get `422 session_required` (v1.1 only mints caption tokens for an open session, A5.1). The app keeps recording and uploading; dictation tokens still work. Update the apps to get captions back. No compatibility path was added on purpose (it would reopen the spending hole A5.1 closed).
- **Local rehearsal on a read-only copy of production** (2026-10-27). Production: MariaDB 11.8.9, 20 tables, 1 user, 28 sessions, 41 transcripts, 28 recordings (28 files, 12 MB in `storage/app/private/recordings`), 2 devices, 6 migrations applied (the v1.0 set). Dump: `mysqldump --single-transaction --skip-lock-tables` streamed to a folder outside the repo (289 KB, complete), imported into local MariaDB 10.4.32 (the only difference to production's engine version; no collation or syntax problem, the first `/*M!999999` line is stripped for the 10.4 client).
  - 18 migrations ran in **1.0 s**. Exact row counts of every pre-existing table unchanged (only `migrations` 6 → 24); 13 new tables; all 28 sessions and 2 devices have `workspace_id` (0 NULL); one workspace "Personal" on plan Personal with the one user as owner and current workspace.
  - A completed session and its final transcript (13 segments) load through the models; a share link made on the copy opens (200); `model:prune --pretend` finds nothing; `recordings:retention` moved 0 to the bin (all recordings are 3 days old, retention 30).
  - `migrate:rollback --step=18` (0.7 s) then `migrate` again (1.1 s): table counts identical before and after, `workspace_id` gone after the rollback and back after the migrate.
  - Not testable on the copy: decryption (the APP_KEY and the recordings key never left the server), real device tokens.
  - The dump and the rehearsal database were deleted afterwards.
## B20 design (workspaces, roles, plan limits, usage)
- **New tables:** `plans` (key, name, `monthly_minutes`, `storage_bytes`, `max_devices`; null = no limit), `workspaces` (name, `plan_id`), `workspace_user` (workspace, user, `role` owner|member, unique pair); `users.current_workspace_id` (nullable, null on delete).
- **Get `workspace_id`:** the five root tables that own user data: `devices`, `device_pairing_codes`, `clients`, `capture_sessions`, `usage_ledger`. Their `user_id` stays (creator / device owner; notifications and per-user rules use it).
- **No `workspace_id` (reason):** children reached only through a scoped parent with cascade delete: `recordings`, `transcripts`, `transcript_revisions`, `session_markers`, `session_labels`, `share_links`, `share_link_views` (→ session), `client_keyterms` (→ client). Per person, not per workspace: `users`, `passkeys`, `audit_events`, `notifications`, `sessions`, `personal_access_tokens` (tokenable = device). Server-wide: `settings`, `credentials`, `webhook_events`, `provider_deletions`, cache/jobs tables.
- **Current workspace:** web user → `users.current_workspace_id` (must be a membership; `SetCurrentWorkspace` panel middleware repairs it to the first membership, a user with none is refused the panel); device token → `devices.workspace_id`; webhook → the transcript's session; public share → the share link's session; console commands and jobs → none: they work on explicit ids and pass `workspace_id` explicitly when they create rows.
- **Scoping:** `BelongsToWorkspace` trait = `WorkspaceScope` global scope + `creating` hook + `workspace()` relation. The scope filters by `CurrentWorkspace::id()` (explicit `WorkspaceContext::runAs()` id, else the already-authenticated user/device — `auth()->hasUser()` only, never resolving a guard); with no current workspace it adds nothing, so code paths without one must filter explicitly. Every request path also filters explicitly (`where('workspace_id', …)` replaces `where('user_id', …)`), so the scope is a second layer. The `creating` hook fills `workspace_id` from: given value → current workspace → the row's `user_id`'s current workspace; none → `LogicException` (writes fail closed).
- **Deliberate bypasses (no current workspace, explicit ids only):** pairing redeem (workspace from the pairing code), STT webhooks and `stt:reconcile` (by transcript id / provider job id), public share links (by token hash → session), signed audio URLs (signature), `recordings:retention` / `recordings:recover` / `sessions:sweep-open` / `model:prune` (all workspaces on purpose, per row), queued jobs (by model id). `WorkspaceContext::bypass()` exists for code that runs inside a request but must see every workspace (none needed today).
- **Roles:** member = record (pair devices), view / search / play / export / share / edit transcripts / organise all recordings of the workspace, delete and restore own recordings, revoke own devices, manage clients. Owner = member + delete / purge any recording, revoke any device, empty the bin, add / remove members and change roles, rename the workspace. A workspace always keeps ≥ 1 owner. Super admin (`is_super_admin`, server operator) = all console pages incl. Settings, API credentials, plan limits and the workspaces list (names, plan, usage — no content); content is still only that of their current workspace. Others reach the panel only with a membership.
- **Plan limits:** one `PlanLimits` service: `assertMinutes(ws)` (STT token mint + final transcript, next to the A5 `BudgetGuard`), `assertStorage(ws, bytes)` (upload slot + console voice-note upload), `assertDevices(ws)` (pairing code + redeem). Errors: `403 budget_exceeded` for minutes (same code devices already handle, so old builds behave), `403 storage_limit_reached`, `403 device_limit_reached`; message "Monthly limit reached — raise it in Settings" style. Default plan **Personal**: 30 000 min / month, 500 GB, 25 devices. Super admin edits the current workspace's plan in Settings → "Plan and limits".
- **Usage:** `UsageSummary` gains the workspace: `minutes` / `cost_usd` are the workspace's, `warn` / `limit_reached` = tightest of (server A5 cap on server totals, plan minutes on workspace totals); new field `plan_limit_minutes`. Alerts (80 % / 100 %) go to the workspace owners once a month. Usage page reads only `usage_ledger` (cost = stored `cost_usd`, priced from Settings when written).
- **Migrations:** (a) `2026_11_01_000001` create tables + nullable indexed `workspace_id` + `users.current_workspace_id`; (b) `…000002` backfill in chunks of 500 with the query builder (no model events): plan Personal, one workspace per user — the first super admin's is the default, named "Personal", owner role; every row → its `user_id`'s workspace (this keeps today's per-user isolation exactly); (c) `…000003` `workspace_id` NOT NULL + foreign keys (restrict on delete).
- **Rollback:** `migrate:rollback --step=3`: (c) drops the keys and makes the columns nullable, (b) nulls the columns and deletes memberships / workspaces / plans, (a) drops the columns and tables. No existing column is renamed or dropped, so the v1.1-B19 code runs on the rolled-back schema.

## Production deploy notes for B20
- Before: `select count(*) from users where is_super_admin = 0;` — any such account becomes the owner of its own workspace (its own data only) and can then sign in to the console. Expected 0 on production.
- Backup first: `mysqldump --single-transaction --routines --triggers -u <user> -p <database> > ~/backup-pre-b20-$(date +%F-%H%M).sql`, copy it off the server. B20 never touches `storage/app/recordings`.
- Order: `php artisan down` → deploy code → `composer install --no-dev --optimize-autoloader` → `/opt/alt/php84/usr/bin/php artisan migrate --force` (runs `2026_11_01_000001` tables + nullable columns, `000002` backfill, `000003` NOT NULL + foreign keys, in that order; `000003` runs outside a transaction and stops before changing anything if a row has no workspace) → `php artisan optimize:clear` → `php artisan up`. Never run seeders. No manual data steps.
- Verify: `select id, name from workspaces;` (one per user, the owner's is "Personal"); `select count(*) from capture_sessions where workspace_id is null;` = 0 (same for devices, device_pairing_codes, clients, usage_ledger); then docs/MANUAL_QA.md W-1 to W-4 (console data, phone/desktop sync and captions, an old share link and playback, `php artisan recordings:retention`).
- Roll back: `php artisan down` → `php artisan migrate:rollback --step=3 --force` (drops the keys, nulls and drops the columns, drops the three tables; recordings, transcripts and files untouched) → deploy the previous code (`68c2142`) → `php artisan optimize:clear` → `php artisan up`. Restore the dump only if the rollback itself fails.

## Decisions made (do not redo)
- B20: one workspace per existing user at migration (the first super admin's is the default "Personal"): keeps today's per-user isolation exactly; members are added later on the Workspace page.
- B20: console access = super admin or any workspace membership; Settings and API credentials stay super-admin only; content is always the current workspace's, also for the super admin (the Workspace page shows them only names and totals of other workspaces).
- B20: the plan minute limit reuses `403 budget_exceeded` (old app builds already stop captions on it); new codes `storage_limit_reached`, `device_limit_reached`, `not_allowed`. The `usage` block keeps its fields (the apps parse it strictly).
- B20: the shared vocabulary (no client) and every other setting stay server-wide; only a super admin's device may change the shared vocabulary.
- B20: `workspace_id` only on the five root tables; children are reached through their parent. The unique (user_id, name) on clients stays; names are unique per workspace by validation.
- Every server suite run also runs on MariaDB (XAMPP): it found real bugs (`started_at` silently got ON UPDATE CURRENT_TIMESTAMP; `transcripts.kind` too short for `alternate`).
- Recording tags are called **labels** (`session_labels` table, `labels` field): `tags` already means bookmark tags in the API (B15).
- Client-not-found from organise endpoints is `404 client_not_found` (same as session create), not 422.
- Passkeys use `web-auth/webauthn-lib` ^5.3 (standard PHP WebAuthn library, no advisories); a verified passkey with user verification signs in without the TOTP step; relying party = host of APP_URL (B16).
- Audit log in `audit_events`, kept 365 days, never stores secrets; actor read only from guards that already authenticated (`hasUser()`), never by resolving a guard.
- Desktop app lock: PIN as PBKDF2-SHA256 (210 000 rounds, ring) in `app_lock.json`, never in settings; Windows Hello via `IUserConsentVerifierInterop` on the main window; Touch ID via LocalAuthentication; locking hides the UI only; data commands refuse while locked.
- Android app lock stays the A4 lock; the phone shows devices and activity only.
- **Undo bin (B17):** every delete (device, console, retention) makes a tombstone at once and erases the provider copy, but keeps our encrypted media 48 h (restorable, never playable or shareable, provider callbacks ignored); recordings deleted while recording / uploading are erased at once. Retention moved from `model:prune` to hourly `recordings:retention`; `model:prune` now removes only tombstones whose media is erased.
- Activity centre uses Laravel database notifications written synchronously (`notifyNow`), so Filament's bell works and the once-a-month budget checks are deterministic.
- Desktop polls activity every 60 s; Android every 15 min through WorkManager (no wake locks). A clicked desktop notification opens its recording via the single-instance callback (installed builds only).
- B19 phone: settings groups are General, Recording, Captions, Dictation, Privacy & security, Devices, Storage, About (no Shortcuts on the phone). Import never turns on the app lock or consent, and never touches the pairing; the lock is switched on only by unlocking once.
- B19 console: the Settings tabs bind `Tabs::livewireProperty('activeTab')` (value = a group key); inactive tabs are not rendered, a search jumps to the first group with a match. Export / import are page header actions; the import fills the form and saves, and rolls the form back if validation fails.
- Android dictation is a voice-keyboard IME; its token is `use_case: dictation` with no session (never a recording).
- Default desktop shortcuts for recording / copying are Ctrl/Cmd+Alt+Shift+R / C (Ctrl+Shift+R / C clash with browsers).
- C9: windows are built on demand (lifecycle.rs). The main window is destroyed on close (not hidden); its view is kept in memory only for this run (a restart opens the Library as before). Overlays are destroyed 30 s after hiding. Only the dictation bar is kept built while its shortcut is bound, because a cold build takes ~1 s; that one web view is the 96 MB idle cost. Removing it too would give ~4 MB idle but a ~1 s dictation start.
- C9: phone settings export keeps `themeMode` and `dynamicColour` on the phone (not portable), as the desktop theme is not in its export either.
- The upgrade brief (the untracked file in docs/) stays out of git (repo rule: no instruction or prompt files committed); it is untracked on purpose.

## Gotchas learned
- SQLite `->change()` / adding a foreign key rebuilds the table (copy, drop, rename). Inside a transaction `PRAGMA foreign_keys=off` is ignored, so dropping `capture_sessions` would cascade-delete recordings and transcripts: such migrations need `public $withinTransaction = false;` and never run inside a test transaction (the cycle test uses its own `cycle` connection).
- Laravel infers the pivot `user_workspace` (alphabetical); ours is `workspace_user`, so both `belongsToMany` calls name it.
- After an API call in a test the guard keeps the device, so the workspace scope narrows later test queries: read stored truth with `unscoped(fn () => ...)`; go back to the console with `actingAs($user, 'web')` (a bare `actingAs` lands on the sanctum guard after an API call).
- Filament runs a policy only for methods that exist (missing → allowed); `DeleteBulkAction` needs `authorizeIndividualRecords('delete')` to check each record.
- PowerShell: `R` is an alias (Invoke-History) and beats a function of the same name; don't name helpers `R`.
- Filament `Tabs::livewireProperty()`: tab keys must be the array keys, the property holds that key, only the active tab is rendered (assert other tabs with `->set('activeTab', 'x')`), and `getFlatFields()` keys are prefixed with the tab key (`general.display_timezone`). `Section::extraAttributes()` lands on the wrapper `div.fi-sc-section`. Dashboard widgets are lazy by default (`$isLazy = false` to see them in a plain GET).
- Test failures that print a whole page (`assertSee` on a Filament page) flood the output: write the HTML to a file and grep it.
- PowerShell 5.1: no `&&`; use `;` and `if ($?)`. `[IO.File]` needs absolute paths (`"$PWD\..."`). Inside double quotes backtick sequences (` `, ` 	`) become control characters; use single-quoted here-strings for code with `$`. Write files with `New-Object Text.UTF8Encoding($false)` (no BOM) and a final LF (repo policy checks it).
- `-like` treats a backtick as an escape: filter lines with `.StartsWith` / `.Contains` instead.
- `.Replace` of a common substring replaces every occurrence (it duplicated imports and doc comments once); anchor on unique text.
- ktlint reformats long Kotlin signatures; a later text replace on the old one-line form silently does nothing; re-read before editing.
- Rust UI contract test parses the TS `Settings` interface line by line: no comments with a colon inside it.
- `composer require pkg:^5.3` loses the caret (cmd escape): leave the constraint out.
- `openssl_pkey_new` on Windows XAMPP needs a config: the test helper writes a minimal openssl.cnf.
- Robolectric: `runBlocking` + `viewModelScope.launch(...).join()` deadlocks the main looper; don't join refresh. `AlertDialog` never idles under `createComposeRule`: test dialog content composables directly; long settings pages need `performScrollTo()`.
- Two coroutines that both read the recordings folder and write one `StateFlow` can finish out of order (a read that overlaps a save, seen on Windows, drops the item and then wins): feed them through one `combine(...).collectLatest` loader, as `MainViewModel.pending` does. Tests then wait with `pumpUntil` for the list instead of asserting right after a job.
- Relations cached by `` (Transcript → CaptureSession) go stale after a delete: re-query (`->captureSession()->first()`).
- Filament `sendToDatabase` queues; use `notifyNow(`...`->toDatabase())`.
- Same-second `created_at` ties make ordering differ on MariaDB: compare as sets or add travel.
- Vitest: a failed load that retries in a `useEffect` can loop for ever and hang the run; don't retry on failure. Clear `node_modules/.vitest-cache` if results look stale.
- Gradle runs > 10 min: start the full Android gate with `run_in_background` and poll the output file.
- Running cargo, vitest and Gradle together can hit "failed to mmap file" (memory): run them one after another. `desktop/src-tauri/target` is ~44 GB.
- `ZipArchive::close(): Renaming temporary file failed` in the export tests is transient on this PC (antivirus on %TEMP%): rerun the file.
- Console sign-in is throttled: a browser script that signs in for every page gets locked out for a minute; sign in once and reuse the storage state.
- Desktop measurements: moving `%APPDATA%\com.echoledger.desktop` aside does **not** isolate the device token: it is in Windows Credential Manager under the fixed service `com.echoledger.desktop`, so pairing a test copy would replace the real one. Measure unpaired, or back up the credential first.
- Desktop windows: building a webview inside a sync command or an event handler deadlocks on Windows; lifecycle.rs always builds and destroys from `tauri::async_runtime::spawn`. `WindowEvent::Destroyed` reaches `on_window_event` after Tauri freed the label, so the same label can be built again from there.
- A release build logs `[echoledger] <label> window ready N ms after it was asked for` to stderr; `Start-Process -RedirectStandardError` captures it from the GUI-subsystem exe.
- PowerShell: a function that returns a `HashSet` unrolls it (a one-element set becomes an int): `return ,$set`.
- Console pages link the Vite theme; tests call `withoutVite()` (tests/TestCase.php), so they never need `npm run build`.

## Commands that work here
- Web (`web/`):
  - `php vendor/bin/pest --parallel` (filter: `| Select-String 'FAILED|Tests:'`)
  - MariaDB: start `C:\xampp\mysql\bin\mysqld.exe --defaults-file=C:\xampp\mysql\bin\my.ini --standalone`, run `SET GLOBAL max_allowed_packet=67108864`, set `DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_DATABASE=echoledger_test DB_USERNAME=root` then `php vendor/bin/pest --parallel --processes=6`, then remove the env vars.
  - `php vendor/bin/pint` / `php vendor/bin/pint --test`; `php vendor/bin/phpstan analyse --no-progress --memory-limit=2G --error-format=raw`
- Desktop (`desktop/`): `npx vitest run`, `npx tsc --noEmit`, `npm run build`; in `src-tauri/`: `cargo test --no-fail-fast`, `cargo clippy --all-targets -- -D warnings`; macOS check: `powershell -File <scratchpad>\mac-check.ps1 -Clippy` (cross-target clippy with stub C toolchain; the script lives in the session scratchpad, not the repo). `cargo fmt` is not a gate.
- Android (`mobile/`): `$env:JAVA_HOME="$env:LOCALAPPDATA\Android\Sdk\jdk"`; `.\gradlew.bat ktlintFormat` then `.\gradlew.bat assembleDebug testDebugUnitTest ktlintCheck assembleDebugAndroidTest lintDebug`; count tests from `app\build\test-results\testDebugUnitTest\*.xml`.
- Repo policy (root): `node --test tools/repo-policy.test.mjs` (reads tracked files: `git add` new docs first); design tokens shared by the three apps: `node --test tools/design-tokens.test.mjs`.
- Secrets in built files: `powershell -File tools/scan-secrets.ps1 -Targets <abs path folder|exe|apk> ...` (absolute paths: .NET ignores PowerShell's location). Dependencies: `composer audit`, `npm audit --omit=dev` (web, desktop), `cargo audit` (needs `cargo install cargo-audit --locked` once).
- Screenshots (Phase 6): desktop `npx vite --port 1420` in `desktop/` then open `/screenshots/harness.html?w=main|overlay|pill|review|dictation&theme=dark|light&view=library|record|settings|onboarding|lock` (`&material=mica&accent=0078d4` previews the material); phone `.\gradlew.bat testDebugUnitTest --tests "com.echoledger.mobile.ui.ScreenshotsTest"` writes `app/build/screenshots`; web: `php artisan migrate:fresh --seed` on a scratch SQLite (`DB_DATABASE=<file>`), `php artisan serve`, `npm run build` for the theme. A browser driver (playwright-core in the session scratchpad) takes the PNGs.
- Desktop footprint: `npx tauri build --no-bundle` in `desktop/` (~4-8 min), then MANUAL_QA P-1 with the data folder moved aside; the Phase 6 script (`measure.ps1`: process tree via `Win32_Process`, private working set via `Win32_PerfFormattedData_PerfProc_Process`, CPU from `TotalProcessorTime`) lived in that session's scratchpad. Web shots: `migrate:fresh --seed` on a scratch SQLite with `ADMIN_EMAIL` / `ADMIN_PASSWORD` set in the environment, `php artisan serve --port=8123`, sign in once and reuse the storage state.
- Commits: author/committer `skillLeo <hassam.dev.571@gmail.com>` only; message from a UTF-8 (no BOM) file: `git commit -q -F <file>`; `git push -q origin v1.1`.

## Not started / blocked
- On-device checks for B1-B19 (docs/MANUAL_QA.md sections 7-15): needs the user's phone, Windows PC and a Mac.
- A6.R6 Authenticode signing of the Windows installer: needs a code-signing certificate.
- P8.2 signed APK: needs the release keystore and its password (never stored in files; pass via environment at build time).
- P8.3 production deploy: needs SSH access; take a database backup first; never run seeders on production; never rotate `ECHOLEDGER_RECORDINGS_KEY`; server PHP CLI is `/opt/alt/php84/usr/bin/php`; run `composer install` (new dependency web-auth/webauthn-lib) and migrations; APP_URL must be the final HTTPS host (passkeys are tied to it).
- Clicking a desktop notification to open a recording works only in installed builds (the OS starts the app by its id).
