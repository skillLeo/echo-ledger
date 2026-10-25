# EchoLedger v1.1 — Handoff

## Snapshot
- 2026-10-26 (Asia/Karachi), branch `v1.1`, B20 finished (commits `abef106`..`f9fe21f` plus the final docs commit).
- Tests (end of B20):
  - Web (Pest, SQLite, `--parallel`): 3049 passed, 1 failed (the timing test below). MariaDB: 3048 passed, 2 failed (the same timing test and its 20,001 twin, run while Gradle was building; alone: 8/9 then 9/9).
  - Desktop core (`cargo test`): 801 passed, 0 failed (1 ignored). Desktop UI (`vitest`): 948/948.
  - Android (`testDebugUnitTest`): 834 passed, 0 failed.
  - Repo policy (`node --test tools/repo-policy.test.mjs`): 23/23.
- Linters: Pint clean, PHPStan 0 errors, `cargo clippy --all-targets -D warnings` clean, `tsc --noEmit` clean, ktlint and Android lint clean. macOS cross clippy not re-run (the `mac-check.ps1` script lived in an older session's scratchpad; B20 changed only `error.rs` / `stt/mod.rs` text, no platform code).
- Known failing / flaky tests:
  - `Tests\Feature\Api\TranscriptValidationSpeedTest` "accepts 20,000 segments in under two seconds": timing budget (4 s parallel, 2 s alone). Fails on battery or under load (seen 2.0-8 s on SQLite and MariaDB). Not a B20 regression: with the workspace scope switched off it measured 2.24 s / 2.24 s / pass, the same as with it on.
  - `LibraryView.test.tsx` "handles a very long transcript without dropping segments" and `PillApp.test.tsx` "brings the pill back when Stop fails": time out only when Gradle or cargo run at the same time; pass alone.
## Progress
- Plan items: 148 in PLAN_V1_1.md: 94 Done ([x]), 32 Partial ([~]), 21 Not started, 1 Blocked. Ticked = 94/148 = **64 %**.
- All 32 Partial items are built and covered by automated tests on every platform and wait only for on-device checks (docs/MANUAL_QA.md sections 7-15). Weighted by remaining effort, about **71 %** of the upgrade brief is done.

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
| B20 | Usage dashboard and SaaS foundations (W): minutes and cost per prov... | Done |
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
- Nothing open. **B20 is done** (Done, not Partial: web only; its checks in docs/MANUAL_QA.md section 16 are deploy verifications).
- Where things are:
  - Schema: `web/database/migrations/2026_11_01_00000{1,2,3}_*.php`; models `Plan`, `Workspace`, trait `Models/Concerns/BelongsToWorkspace.php`, `Models/Scopes/WorkspaceScope.php`.
  - Services: `Services/Workspaces/{CurrentWorkspace,Workspaces,PlanLimits}.php`, `Services/Usage/UsageReport.php`; `UsageSummary::toArray(?int $workspaceId)`.
  - Console: `Filament/Pages/Usage.php` (+ `Widgets/Usage/DailyUsageChart.php`, not on the dashboard), `Filament/Pages/WorkspaceSettings.php` (slug `workspace`), Settings → "Plan limits" (section key `plan`), middleware `Http/Middleware/EnsureWorkspaceMember.php` (replaces `EnsureSuperAdmin`), policies `Policies/{CaptureSession,Device}Policy.php`.
  - Tests: `tests/Feature/Workspaces/*` (isolation, roles and limits, usage, workspace page) and `tests/Feature/Database/WorkspaceMigrationCycleTest.php`; test helpers `unscoped()` and `withoutWorkspace()` in `tests/Pest.php`.
- Migration cycle result (B20): `WorkspaceMigrationCycleTest` seeds 3 users (2 super admins, 1 plain) × device, pairing code, client + keyterm, 7 sessions (1 binned) each with recording, transcript, marker, label, share link + view and a usage row; up → down → up keeps every row count identical, maps every row to its user's workspace, NOT NULL and the foreign key reject bad rows, and a second backfill run changes nothing. Passed on SQLite and on MariaDB 10.4 (2/2 each). Step 3 refuses to run while a row has no workspace.

## Next steps (in order)
1. **Next: Phase 6 design polish (use Opus 5.5 High)** — C1-C9.
2. Then Phase 7 QA (P7.1-P7.3, write the user guide), then Phase 8 release (P8.1-P8.4, with the B20 deploy notes below).
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
- On-device checks for B1-B19 (docs/MANUAL_QA.md sections 7-15): needs the user's phone, Windows PC and a Mac.
- A6.R6 Authenticode signing of the Windows installer: needs a code-signing certificate.
- P8.2 signed APK: needs the release keystore and its password (never stored in files; pass via environment at build time).
- P8.3 production deploy: needs SSH access; take a database backup first; never run seeders on production; never rotate `ECHOLEDGER_RECORDINGS_KEY`; server PHP CLI is `/opt/alt/php84/usr/bin/php`; run `composer install` (new dependency web-auth/webauthn-lib) and migrations; APP_URL must be the final HTTPS host (passkeys are tied to it).
- Clicking a desktop notification to open a recording works only in installed builds (the OS starts the app by its id).
