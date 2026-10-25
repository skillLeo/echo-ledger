# EchoLedger v1.1 — Handoff

## Snapshot
- 2026-10-25 23:32 (Asia/Karachi), branch `v1.1`, last code commit `d9c6db5` "v1.1: checkpoint before handoff" (this file is committed right after).
- Tests:
  - Web (Pest, SQLite, `--parallel`): 2961 passed, 1 failed. MariaDB run of the same suite (last run at B18): all passed.
  - Desktop core (`cargo test`): 799 passed, 0 failed (1 ignored).
  - Desktop UI (`vitest`): 948 tests; one flaky failure under load (see below), 948/948 when re-run.
  - Android (`testDebugUnitTest`): 793 passed, 0 failed.
  - Repo policy (`node --test tools/repo-policy.test.mjs`): 23/23.
- Linters: Pint clean, PHPStan 0 errors, `cargo clippy --all-targets -D warnings` clean, macOS clippy (`mac-check.ps1 -Clippy`) clean, `tsc --noEmit` clean, ktlint and Android lint clean.
- Known failing / flaky tests:
  - `Tests\Feature\Api\TranscriptValidationSpeedTest` "accepts 20,000 segments in under two seconds": timing budget (4 s parallel, 2 s alone). Fails on SQLite only when the laptop runs on battery or under load (seen 2.2-6 s); passes on MariaDB and on mains power. Not a regression (fails the same with the changes stashed).
  - `LibraryView.test.tsx` "handles a very long transcript without dropping segments" and `PillApp.test.tsx` "brings the pill back when Stop fails": time out only when Gradle or cargo run at the same time; pass alone.

## Progress
- Plan items: 148 in PLAN_V1_1.md: 93 Done ([x]), 32 Partial ([~]), 22 Not started, 1 Blocked. Ticked = 93/148 = **63 %**.
- Of the 32 Partial, 31 are built and covered by automated tests on every platform and wait only for on-device checks (docs/MANUAL_QA.md sections 7-14); B19 is genuinely half done. Weighted by remaining effort, about **66 %** of docs/UPGRADE_V1_1.md is done.

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
| A6.M9 | [low] Rotating the phone returns to the library (tab, open recordin... | Not started |
| A6.M10 | [low] Status bar icons are dark on the dark app background in the l... | Not started |
| A6.M11 | [low] In landscape the library header, search, banner and filters l... | Not started |
| A6.M12 | [low] The Record tab upload card does not update until the tab is o... | Not started |
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
| B20 | Usage dashboard and SaaS foundations (W): minutes and cost per prov... | Not started |
| C1 | One token file per app (colour, 8-pt spacing, radius 8/12/16, shado... | Not started |
| C2 | Desktop: Mica/Acrylic (Windows 11) with solid fallback, macOS vibra... | Not started |
| C3 | Web: custom Filament theme (Vite): page headers, breadcrumbs, stick... | Not started |
| C4 | Android: Material 3 with optional dynamic colour, edge-to-edge, pre... | Not started |
| C5 | Motion 150–220 ms ease-out, respects reduce-motion. | Not started |
| C6 | Loading, empty, error (Retry) and offline states on every list and ... | Not started |
| C7 | Accessibility: WCAG 2.2 AA contrast, focus rings, full keyboard use... | Not started |
| C8 | Copy pass: short, plain, sentence case. | Not started |
| C9 | Performance budgets measured and reported: desktop idle ≤ 150 MB an... | Not started |
| P7.1 | All suites and linters green; tests for every new feature. | Not started |
| P7.2 | Security re-sweep: no key leaks, pins intact, every API route autho... | Not started |
| P7.3 | docs/MANUAL_QA.md, docs/API.md, docs/ARCHITECTURE.md, README.md upd... | Not started |
| P8.1 | Windows installer built. | Not started |
| P8.2 | Android release APK built and signed. | Not started |
| P8.3 | Production deploy: database backup confirmed, maintenance mode, upl... | Not started |
| P8.4 | `v1.1` merged into `main` and pushed. | Not started |

## In progress right now
- **B19 Onboarding and system-style settings.**
- Done (desktop, committed in `d9c6db5`):
  - Grouped, searchable settings (General, Recording, Captions, Dictation, Shortcuts, Privacy & security, Devices, Storage, About): `desktop/src/views/main/SettingsView.tsx`.
  - App lock and devices split into `LockCard` / `DevicesCard`: `desktop/src/views/main/SecuritySettings.tsx`.
  - Shortcut conflict warnings: `desktop/src/lib/shortcutConflicts.ts`; defaults moved to Ctrl/Cmd+Alt+Shift+R / C: `desktop/src-tauri/src/shortcuts.rs`.
  - Settings export / import without the pairing: `settings_export` / `settings_import` in `desktop/src-tauri/src/commands.rs`.
  - First-run wizard: `desktop/src/views/main/OnboardingView.tsx`, gated in `MainApp.tsx`; setting `onboarding_done` (installs paired before v1.1 count as done) in `desktop/src-tauri/src/settings.rs`.
  - Tests: `desktop/src/views/main/SystemSettings.test.tsx`, `src-tauri/src/settings/tests.rs`, `src-tauri/src/commands/tests.rs`, `src-tauri/tests/shortcut_bindings.rs`.
- Left: Android wizard (permissions → pairing → 10-second mic test → consent) and grouped / searchable settings with export / import; console settings tabs; MANUAL_QA section 15; tick B19.

## Next steps (in order)
1. Android B19 wizard: add `onboardingDone` to `AppSettings` (`mobile/app/src/main/java/com/echoledger/mobile/data/SettingsStore.kt`), new `ui/OnboardingScreen.kt`, gate in `MainActivity.kt` (reuse `recording/MicTest.kt` and the pairing screen in `ui/Screens.kt`).
2. Android settings groups and search plus export / import: `ui/Screens.kt` (`SettingsScreen`), `ui/CaptureScreens.kt`, `ui/SecurityScreens.kt`, `ui/ActivityScreens.kt`; export to the app's `exports/` cache and share it (see `MainViewModel.export`).
3. Console settings tabs: `web/app/Filament/Pages/Settings.php`; then MANUAL_QA section 15 in `docs/MANUAL_QA.md`; tick B19 in `PLAN_V1_1.md`.
4. B20 usage dashboard and SaaS foundations: `web/app/Services/Usage/*`, `web/app/Filament/Widgets/*`, new `workspaces` migration scoping every owned table, roles (owner / member), plan-limits table enforced by one policy; migrate existing data into a default workspace owned by the super admin.
5. Phase 6 (C1-C9), then Phase 7 QA (P7.1-P7.3, write `docs/USER_GUIDE.md`), then Phase 8 release (P8.1-P8.4).

## Decisions made (do not redo)
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
- Android dictation is a voice-keyboard IME; its token is `use_case: dictation` with no session (never a recording).
- Default desktop shortcuts for recording / copying are Ctrl/Cmd+Alt+Shift+R / C (Ctrl+Shift+R / C clash with browsers).
- The brief `docs/UPGRADE_V1_1.md` stays out of git (repo rule: no instruction or prompt files committed); it is untracked on purpose.

## Gotchas learned
- PowerShell 5.1: no `&&`; use `;` and `if ($?)`. `[IO.File]` needs absolute paths (`"$PWD\..."`). Inside double quotes backtick sequences (` `, ` 	`) become control characters; use single-quoted here-strings for code with `$`. Write files with `New-Object Text.UTF8Encoding($false)` (no BOM) and a final LF (repo policy checks it).
- `-like` treats a backtick as an escape: filter lines with `.StartsWith` / `.Contains` instead.
- `.Replace` of a common substring replaces every occurrence (it duplicated imports and doc comments once); anchor on unique text.
- ktlint reformats long Kotlin signatures; a later text replace on the old one-line form silently does nothing; re-read before editing.
- Rust UI contract test parses the TS `Settings` interface line by line: no comments with a colon inside it.
- `composer require pkg:^5.3` loses the caret (cmd escape): leave the constraint out.
- `openssl_pkey_new` on Windows XAMPP needs a config: the test helper writes a minimal openssl.cnf.
- Robolectric: `runBlocking` + `viewModelScope.launch(...).join()` deadlocks the main looper; don't join refresh. `AlertDialog` never idles under `createComposeRule`: test dialog content composables directly; long settings pages need `performScrollTo()`.
- Relations cached by `` (Transcript → CaptureSession) go stale after a delete: re-query (`->captureSession()->first()`).
- Filament `sendToDatabase` queues; use `notifyNow(`...`->toDatabase())`.
- Same-second `created_at` ties make ordering differ on MariaDB: compare as sets or add travel.
- Vitest: a failed load that retries in a `useEffect` can loop for ever and hang the run; don't retry on failure. Clear `node_modules/.vitest-cache` if results look stale.
- Gradle runs > 10 min: start the full Android gate with `run_in_background` and poll the output file.
- Running cargo, vitest and Gradle together can hit "failed to mmap file" (memory): run them one after another. `desktop/src-tauri/target` is ~44 GB.

## Commands that work here
- Web (`web/`):
  - `php vendor/bin/pest --parallel` (filter: `| Select-String 'FAILED|Tests:'`)
  - MariaDB: start `C:\xampp\mysql\bin\mysqld.exe --defaults-file=C:\xampp\mysql\bin\my.ini --standalone`, run `SET GLOBAL max_allowed_packet=67108864`, set `DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_DATABASE=echoledger_test DB_USERNAME=root` then `php vendor/bin/pest --parallel --processes=6`, then remove the env vars.
  - `php vendor/bin/pint` / `php vendor/bin/pint --test`; `php vendor/bin/phpstan analyse --no-progress --memory-limit=2G --error-format=raw`
- Desktop (`desktop/`): `npx vitest run`, `npx tsc --noEmit`, `npm run build`; in `src-tauri/`: `cargo test --no-fail-fast`, `cargo clippy --all-targets -- -D warnings`; macOS check: `powershell -File <scratchpad>\mac-check.ps1 -Clippy` (cross-target clippy with stub C toolchain; the script lives in the session scratchpad, not the repo). `cargo fmt` is not a gate.
- Android (`mobile/`): `$env:JAVA_HOME="$env:LOCALAPPDATA\Android\Sdk\jdk"`; `.\gradlew.bat ktlintFormat` then `.\gradlew.bat assembleDebug testDebugUnitTest ktlintCheck assembleDebugAndroidTest lintDebug`; count tests from `app\build\test-results\testDebugUnitTest\*.xml`.
- Repo policy (root): `node --test tools/repo-policy.test.mjs`.
- Commits: author/committer `skillLeo <hassam.dev.571@gmail.com>` only; message from a UTF-8 (no BOM) file: `git commit -q -F <file>`; `git push -q origin v1.1`.

## Not started / blocked
- On-device checks for B1-B18 (docs/MANUAL_QA.md sections 7-14): needs the user's phone, Windows PC and a Mac.
- A6.R6 Authenticode signing of the Windows installer: needs a code-signing certificate.
- P8.2 signed APK: needs the release keystore and its password (never stored in files; pass via environment at build time).
- P8.3 production deploy: needs SSH access; take a database backup first; never run seeders on production; never rotate `ECHOLEDGER_RECORDINGS_KEY`; server PHP CLI is `/opt/alt/php84/usr/bin/php`; run `composer install` (new dependency web-auth/webauthn-lib) and migrations; APP_URL must be the final HTTPS host (passkeys are tied to it).
- Clicking a desktop notification to open a recording works only in installed builds (the OS starts the app by its id).
