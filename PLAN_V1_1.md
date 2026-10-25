# EchoLedger v1.1 plan

Branch `v1.1`, started from `main` at `e9e8d93`. This file is the single source of truth for v1.1: every item
has an ID, and an item is ticked only when it is built, tested and committed. Work resumes from the first
unticked item.

**Status marks:** `[x]` done · `[~]` done, needs a check on a real device (listed in
[`docs/MANUAL_QA.md`](docs/MANUAL_QA.md)) · `[ ]` open · `[-]` not done (reason given).

**Rules for every item**

- Transcription only: no LLM calls, no new paid services. Speech-to-text runs only for recordings the user kept.
- Every bug fix ships with a regression test that fails before the fix and passes after.
- After every phase all suites and linters are green: `php artisan test`, Pint, Larastan (level 6),
  `cargo test`, `cargo clippy --all-targets -- -D warnings`, `npm run test`, `npm run build`,
  `./gradlew assembleDebug testDebugUnitTest ktlintCheck`.
- OS APIs, crates, libraries and provider parameters are checked against their real documentation or installed
  sources first; non-obvious calls cite the source in a comment.
- Provider keys stay on the server. Pinning, Sanctum abilities, encryption at rest and 2FA stay. No telemetry.
  The recordings key is never rotated or printed. Seeders never run in production.
- UI copy: short, plain, sentence case.

---

## Phase 0 — prepare

- [x] P0.1 Branch `v1.1` from `main` (`e9e8d93`).
- [x] P0.2 Read CONTRIBUTING.md, PLAN.md, docs/* and docs/research/*.
- [x] P0.3 Baseline: all suites and linters recorded (counts below).
- [x] P0.4 Expanded test suites added (API, console, security, Rust core, desktop UI, Android unit, server
      contract fixtures, desktop end-to-end, Android instrumented). Tests that expose a known defect are marked
      skipped with the defect ID below and are re-enabled by the fix.
- [x] P0.5 This plan written.

**Baseline at `e9e8d93` (before v1.1)**

| App | Tests | Linters |
|---|---|---|
| Server | 63 passed | Pint clean · Larastan L6 clean |
| Desktop core (Rust) | 35 passed | clippy clean |
| Desktop UI | 26 passed | tsc clean · build OK |
| Android | 66 passed | ktlint clean · Android lint: 4 errors (A6.M6) |

**After Phase 0 (expanded suites; skipped = tests waiting for a defect fix, tagged with its ID)**

| App | Tests | Passed | Skipped | Linters |
|---|---|---|---|---|
| Server | 2,168 | 2,065 | 103 | Pint clean · Larastan L6 clean |
| Desktop core (Rust) | 563 | 519 | 44 | clippy clean |
| Desktop UI | 372 | 364 | 8 | tsc clean · build OK |
| Android (unit) | 617 | 594 | 23 | ktlint clean |
| Repository checks | 23 | 11 | 12 | — |

---

## Part A — P0 bugs (Phase 1)

### A1. Auto-record only real calls, label browsers by site

- [ ] A1.1 A call needs two-way audio: the same process tree holds an active **capture** session and an active
      **render** session for the whole start window. Windows: `IAudioSessionManager2` / `IAudioSessionEnumerator`
      on capture and render endpoints, state `AudioSessionStateActive`, PID → process tree.
- [ ] A1.2 macOS 14.4+: Core Audio process objects for running input/output (property names checked against the
      installed bindings).
- [ ] A1.3 Browsers are identified by site, never by process name: Windows `EnumWindows` /
      `GetWindowThreadProcessId` / `GetWindowTextW` over the browser's process tree; macOS Apple Events for tab
      URLs (opt-in Automation permission), otherwise "unknown".
- [ ] A1.4 Data-driven, unit-tested site rules (user-extendable in Settings): Google Meet, WhatsApp Web, Zoom web;
      built-in never-record list (chatgpt.com, claude.ai, gemini.google.com).
- [ ] A1.5 Unknown browser mic use does nothing. Optional setting (off by default): "Ask me when an unrecognised
      app uses the microphone" chip (Record / Ignore / Always ignore this site), auto-dismiss after 8 s.
- [ ] A1.6 The label is decided once at start and kept for the session.
- [ ] A1.7 `whatsapp_web` end to end (server enum, validation, API docs, console badges and filters, desktop,
      Android labels, library filters); `microsoft_teams` the same way.
- [ ] A1.8 Record screen explains each decision ("Holds mic ✓ · Playing audio ✓ · Identified as Google Meet ·
      Decision: auto-record" / "Ignored: dictation (no audio playing)" / "Ignored: unknown site").
- [ ] A1.9 Tests: ChatGPT title → none; "Meet - abc-defg-hij" → google_meet; "WhatsApp" → whatsapp_web;
      WhatsApp capture-only → not a call; capture + render → call; tab switch keeps the label; both signals
      needed for the full start window.

### A2. Keep / Discard after a recording ends (desktop, phone parity)

- [ ] A2.1 `PendingDecision` state: a finished recording stays local and is not uploaded.
- [ ] A2.2 Review card window (unfocusable, always on top, bottom-right, hidden from screen share, 180 ms slide):
      source, client chip, duration, first caption line, Keep · Discard · ⋯ (Rename, Assign client, Open).
- [ ] A2.3 Discard deletes local files, uploads nothing, requests no transcript, deletes any server session.
- [ ] A2.4 Keep enqueues the upload and the final transcript.
- [ ] A2.5 "If I don't choose within 60 s": Keep (default) / Discard / Keep waiting; undecided items listed under
      "Waiting for your decision" and persisted across restarts.
- [ ] A2.6 "Ask before saving": Always (default) / Only automatic calls / Never.
- [ ] A2.7 Phone: heads-up post-call notification with the same preview fields and timeout rule; pending count on
      the widget.
- [ ] A2.8 Tests: Keep → enqueued; Discard → files deleted, no upload, no transcript request; timeout → default;
      pending list survives restart.

### A3. Caption overlay and recording controls

- [ ] A3.1 Overlay card is a flex column (`max-height: 100%`), header always visible, body scrolls, auto-scroll
      with "Jump to live"; hit regions recomputed on scroll and resize.
- [ ] A3.2 Visible lines setting (2 / 3 / 5); long partial turns wrap inside the body.
- [ ] A3.3 Recording pill (≤ 280 × 44, draggable, remembers position, unfocusable, hidden from screen share):
      REC + timer, source, Me/Client meters, Pause/Resume, Stop (→ review card), Cancel (inline confirm),
      Captions on/off, Hide.
- [ ] A3.4 Tray icon states: idle / recording / paused / uploading / needs attention.
- [ ] A3.5 Tests: toolbar stays inside the window with 50 long lines; pill actions call the right commands.

### A4. Android app lock

- [ ] A4.1 Settings expose "not loaded yet"; a neutral splash is shown until DataStore emits; `locked` is computed
      after load.
- [ ] A4.2 Re-lock on `ON_STOP` with "Lock after: Immediately / 1 min / 5 min".
- [ ] A4.3 `BIOMETRIC_WEAK or DEVICE_CREDENTIAL`, `canAuthenticate()` at runtime, `KeyguardManager` fallback where a
      combination is unsupported; no negative button with `DEVICE_CREDENTIAL`.
- [ ] A4.4 The App lock row is always visible (disabled + reason + "Open security settings" when there is no
      screen lock); turning the lock on needs one successful unlock.
- [ ] A4.5 `FLAG_SECURE` while the lock is on (user setting); every activity that shows recordings or transcripts
      respects the lock; the lock cover blocks touches and accessibility focus.
- [ ] A4.6 Robolectric tests: late settings emission → locked; `ON_STOP` → locked; authenticator per API level; row
      visible with reason.

### A5. Never spend speech-to-text without approval

- [ ] A5.1 `POST /api/stt/token` only for an open session owned by the device, in recording state, younger than 4 h.
- [ ] A5.2 Monthly budget cap (minutes or USD) in the console; over the cap → "Monthly transcription limit
      reached" on every device.
- [ ] A5.3 Devices open a streaming socket only when the user started it or the auto-record rules approved it;
      sockets close on Pause; final transcription only after Keep.
- [ ] A5.4 `usage_ledger` table: streamed minutes reported at session end; final minutes from provider responses ×
      billed channels.

### A6. Defects found by the full QA sweep

Severity in brackets. Each item lists the platform; the matching regression tests carry the same ID.

**Server and console (W)**

- [ ] A6.W1 [high] Console audio route returns 403 to admins without 2FA although 2FA is optional.
- [ ] A6.W2 [medium] Console audio route is not scoped to the recording's owner.
- [ ] A6.W3 [high] `complete` answers 202 when whole-file verification fails, so apps delete their only copy;
      `received_chunks` still lists discarded parts.
- [ ] A6.W4 [high] A provider error during the inline submit leaves the final transcript "processing" for ever.
- [ ] A6.W5 [high] A recording left in "assembling" by a request that died is never finished.
- [ ] A6.W6 [medium] Incremental sync misses same-second changes and live transcripts added after a sync;
      tombstones expire by creation age; `since` ignores an offset; a cursor with null fields gives 500.
- [ ] A6.W7 [medium] Deleting a session mid-transcription never deletes the provider-side copy.
- [ ] A6.W8 [medium] Playback decrypts the whole recording into memory twice (large recordings exceed
      `memory_limit`).
- [ ] A6.W9 [medium] Certificate pins are saved and pushed to devices without validation.
- [ ] A6.W10 [medium] Provider endpoint URLs accept non-HTTPS schemes and any host (lets a console session send
      the write-only keys elsewhere).
- [ ] A6.W11 [medium] The seeder creates a super admin with a published default password in any environment.
- [ ] A6.W12 [medium] Docs and CLI messages still say 2FA is required.
- [ ] A6.W13 [medium] Transcript upload validation is super-linear; the 20,000-segment cap is unreachable.
- [ ] A6.W14 [medium] Console transcript player is unreadable in the light theme and mouse-only.
- [ ] A6.W15 [low] A Range past the end returns 200 with the whole file instead of 416.
- [ ] A6.W16 [low] HTTP 500 instead of 4xx: webhook `rid[]=x`, library cursor with null fields, malformed
      authenticated input, non-text settings values; provider connection failure on `stt/token` should be 502.
- [ ] A6.W17 [low] 404 responses name internal model classes.
- [ ] A6.W18 [low] `rotate-token` widens a restricted token to the full ability set.
- [ ] A6.W19 [low] The `uploads` rate limiter can never trigger (the `device` limiter is stricter).
- [ ] A6.W20 [low] ElevenLabs webhook de-duplication keys on an unsigned header.
- [ ] A6.W21 [low] Garbage `sample_rate` values are coerced and accepted; timestamps with an offset are stored as
      UTC wall clock.
- [ ] A6.W22 [low] Orphaned decrypted/assembled temp audio is never swept.
- [ ] A6.W23 [low] Console profile accepts 8-character passwords (CLI requires 12).
- [ ] A6.W24 [low] No limit on open upload slots.
- [ ] A6.W25 [low] Security headers exist only in the hosting `.htaccess`; API answers CORS for any origin.
- [ ] A6.W26 [low] Console details: dashboard chart and date filter ignore the display time zone, durations ≥ 24 h
      wrap, search misses displayed titles, empty search shows "No sessions yet", revoked-device sessions say
      "Web upload", client filter lists other admins' clients, client colour unvalidated, cramped at 360 px,
      recovery-code first click ignored, filter button accessible name.
- [ ] A6.W27 [low] Console loads third-party fonts and avatars (fonts.bunny.net, ui-avatars.com).
- [ ] A6.W28 [low] Console voice-note upload refuses AMR, ADTS AAC and WebM despite the allowlist.
- [ ] A6.W29 [low] `.env.example` defaults (`APP_DEBUG=true`, `APP_ENV=local`, `APP_NAME=Laravel`), composer.json
      skeleton metadata, `npm ci` in DEPLOY.md without a lock file, mis-encoded characters in comments.
- [ ] A6.W30 [low] docs/API.md differs from real responses.

**Desktop core (DR)**

- [ ] A6.DR1 [high] AssemblyAI streaming flushes pre-Begin audio with a trailing frame under 50 ms, which the
      provider rejects.
- [ ] A6.DR2 [medium] The loopback check is a string prefix: look-alike hosts get cleartext HTTP without pinning.
- [ ] A6.DR3 [medium] Pinning fails open when none of the configured pins parses.
- [ ] A6.DR4 [medium] Permanent upload errors (403, 404, 413, 422) are retried for ever; `Retry-After` is ignored.
- [ ] A6.DR5 [medium] Imported `.oga`, `.aac` and `.amr` files are declared `application/octet-stream`; imported
      notes declare 16 kHz and 0 ms without measuring.
- [ ] A6.DR6 [medium] The recorder inflates a recording when the first packet has no timestamp.
- [ ] A6.DR7 [medium] A client deleted in the console blocks every upload and caption start of a device that still
      has it selected.
- [ ] A6.DR8 [medium] An upload that fails server verification is counted as uploaded and the local audio is
      deleted.
- [ ] A6.DR9 [low] FLAC with a final block of 1–15 samples writes an invalid STREAMINFO minimum block size.
- [ ] A6.DR10 [low] Stopping a long recording needs about 2.3 GB of RAM per hour of stereo audio.
- [ ] A6.DR11 [low] ElevenLabs live transcript loses turn order; an AssemblyAI session never ends if Terminate is
      not answered.
- [ ] A6.DR12 [low] TLS and pin failures are reported as a generic network error.
- [ ] A6.DR13 [low] One unknown enum value in settings.json discards the whole file.
- [ ] A6.DR14 [low] A single NaN/inf sample poisons the resampler.
- [ ] A6.DR15 [low] The HTTP client is rebuilt on every call; no guard against a repeating `next_cursor`; a clock
      step back makes a recording unsendable.
- [ ] A6.DR16 [low] Version drift: tauri.conf.json says 0.1.1, Cargo.toml/package.json say 0.1.0.
- [ ] A6.DR17 [low] `zip` 4.6.1 (RUSTSEC advisories) via the updater plugin; installer publisher/copyright metadata
      and the placeholder updater key.
- [ ] A6.DR18 [low] The "no audio captured" message tells desktop users to check Android settings.
- [ ] A6.DR19 [low] The macOS code had never been compiled: `cargo clippy --target aarch64-apple-darwin` fails
      (`type_complexity` in `audio/platform/macos.rs`). A macOS type-check and clippy gate now runs on Windows
      (C and Objective-C build scripts compile against stand-in headers; nothing is linked).

**Desktop UI (DU)**

- [ ] A6.DU1 [high] Pairing errors (wrong code, network, pin mismatch, "unpaired on the server") are never shown.
- [ ] A6.DU2 [medium] The library shows another recording's transcript when responses arrive out of order.
- [ ] A6.DU3 [medium] A processing transcript is shown as "The transcript is empty: no speech was detected."
- [ ] A6.DU4 [medium] "No client" in Settings cannot clear the selected client.
- [ ] A6.DU5 [medium] The library layout breaks below about 1030 px (minimum window is 860 px).
- [ ] A6.DU6 [medium] Library search only looks at the 160-character preview (covered by feature 8).
- [ ] A6.DU7 [medium] Accessibility: unnamed selects, misused tablist role, transcript rows not keyboard-operable,
      contrast below AA, no main landmark, switches without state.
- [ ] A6.DU8 [low] Layout details: long titles push the status pill out, an unbroken URL widens the transcript
      grid, the dictation box overflows, Record cards misaligned, raw app ids shown, macOS "Control" label, an
      expired audio URL fails silently.

**Android (M)**

- [ ] A6.M1 [high] UploadWorker leaves a recording stuck "uploading" on an unexpected exception; a missing file is
      retried 12 times; a failed import leaves an orphaned file.
- [ ] A6.M2 [high] Startup races when settings load after the first frame: lock not engaged (A4), on-open sync and
      upload restart skipped, a paired phone shows the Pair form.
- [ ] A6.M3 [medium] The share entry accepts `file://` URIs.
- [ ] A6.M4 [medium] Delete and Unpair act on one tap with no confirmation.
- [ ] A6.M5 [medium] A processing transcript is shown as empty.
- [ ] A6.M6 [medium] Android lint: 4 errors, 19 warnings.
- [ ] A6.M7 [low] Parsing details: media types with codec parameters, pairing payloads with embedded credentials,
      upper-case `HTTPS`, locale digits in copied text, durations truncated instead of rounded, `Pcm.rms` above 1,
      `SttProtocol` throws on a non-object word.
- [ ] A6.M8 [low] "No recordings yet" during the first sync, "Pull to refresh" copy without pull-to-refresh, a 401
      does not return to the unpaired state, `Retry-After` ignored.

**Repository (R)**

- [ ] A6.R1 [medium] docs/research notes reference files that no longer exist and read as working logs.
- [ ] A6.R2 [medium] `.gitignore` does not ignore `.env` files outside `web/`.
- [ ] A6.R3 [low] Unreachable objects in the local object store; pushed history verified clean.
- [ ] A6.R4 [low] A unit test contains the production host name, a real-looking pairing code and production pins.
- [ ] A6.R5 [low] Dead files, stale docs, inconsistent metadata across manifests.
- [-] A6.R6 [info] The Windows installer is not Authenticode-signed: needs a code-signing certificate from the
      owner (documented in the release notes).

---

## Part B — features

`W` web · `D` desktop · `P` phone.

### Phase 2 — capture and control

- [ ] B1 Auto-record rules (D, P): per app/site toggles, editable site list, never-record list, decision explainer.
- [ ] B2 Pause / resume and "off the record" (D, P): paused time not captured, streamed or billed; gap markers.
- [ ] B3 Bookmarks and tags (D, P, W): shortcut / pill / notification "Mark"; presets Requirement · Budget ·
      Deadline · Decision · Follow-up (editable); shown on timeline, transcript and search filters.
- [ ] B4 Caption styles (D, P): size, opacity, lines, width, position presets, compact mode, auto-hide, Me/Client
      colours, hidden from screen share.
- [ ] B5 Capture health and pre-flight (D, P): device picker, level meters, 10-second test recording, permissions
      centre, "Not hearing the other side for 30 s" warning.

### Phase 3 — transcript experience

- [ ] B6 Pro transcript player (W, D, P): speaker blocks, word highlight, click-to-seek, 0.5–2×, skip silence,
      waveform with bookmarks, auto-follow, Space / J / K / L / ← / →.
- [ ] B7 Transcript editing and speaker names (W, D): inline edit, rename speakers (remembered per client), find &
      replace, revisions with "Restore original".
- [ ] B8 Global full-text search (W, D, P): filters (client, app, device, date, tag, bookmarked, status), jump to
      the moment, highlighted snippet; MySQL FULLTEXT with a LIKE fallback.
- [ ] B9 Command palette and keyboard-first (W, D): Ctrl/Cmd + K.
- [ ] B10 Export and secure share (W, D, P): TXT, Markdown, DOCX, PDF, SRT, VTT, JSON; copy variants; share links
      (1 h / 1 d / 7 d, optional password, view-only, revocable, audio optional, access logged).
- [ ] B11 Custom vocabulary 2.0 (W, D, P): global + per-client keyterms within provider limits, text replacements,
      "Add to vocabulary" from edits.
- [ ] B12 Language routing (W, D, P): English, Urdu, Hindi, English + Urdu, Auto; model chosen from documented
      provider language lists; the chosen model is shown.
- [ ] B13 Re-transcribe and compare (W): second engine, both versions kept, side-by-side diff, choose primary.

### Phase 4 — dictation

- [ ] B14 Dictation 2.0 (D, P): push-to-talk and toggle, insert at cursor with clipboard restore, dictionary and
      replacements, voice snippets, rule-based clean-up, history, start/stop sounds, flow bar; never creates a
      recording session or a Keep/Discard prompt.

### Phase 5 — organisation, security, SaaS foundations

- [ ] B15 Clients and smart views (W, D, P): client folders (colour, avatar), tags, pins, bulk actions, smart views
      (Today, This week, Waiting for decision, Bookmarked, Failed uploads).
- [ ] B16 Security centre (W, D, P): desktop app lock (Windows Hello / Touch ID, PIN fallback), passkeys for web
      login (TOTP kept), devices and web sessions with revoke, audit log.
- [ ] B17 Retention controls (W, D, P): 7 / 14 / 30 / custom days globally and per client, per-recording keep pin,
      48-hour undo bin, storage meter.
- [ ] B18 Notifications and activity centre (W, D, P): bell, OS notifications that open the recording, quiet hours.
- [ ] B19 Onboarding and system-style settings (W, D, P): first-run wizard, grouped searchable settings, shortcut
      editor with conflict detection, settings import/export without secrets.
- [ ] B20 Usage dashboard and SaaS foundations (W): minutes and cost per provider/month, storage, per device, budget
      alerts; `workspaces` with `workspace_id` scoping, roles, plan limits enforced by one policy; existing data
      migrated into a default workspace.

---

## Part C — premium built-in-app standard (Phase 6)

- [ ] C1 One token file per app (colour, 8-pt spacing, radius 8/12/16, shadows, motion); one icon set; system fonts.
- [ ] C2 Desktop: Mica/Acrylic (Windows 11) with solid fallback, macOS vibrancy and inset traffic lights, system
      light/dark and accent, tray-first, translucent hairline overlays.
- [ ] C3 Web: custom Filament theme (Vite): page headers, breadcrumbs, sticky table headers, empty states with one
      action, skeleton loaders, responsive to phone width; fonts self-hosted.
- [ ] C4 Android: Material 3 with optional dynamic colour, edge-to-edge, predictive back, haptics, Glance widgets in
      2×1, 2×2 and 4×2.
- [ ] C5 Motion 150–220 ms ease-out, respects reduce-motion.
- [ ] C6 Loading, empty, error (Retry) and offline states on every list and screen.
- [ ] C7 Accessibility: WCAG 2.2 AA contrast, focus rings, full keyboard use, screen-reader labels.
- [ ] C8 Copy pass: short, plain, sentence case.
- [ ] C9 Performance budgets measured and reported: desktop idle ≤ 150 MB and < 1 % CPU, recording < 8 % CPU,
      detection ≤ 1 Hz without per-tick allocation; Android no wake locks or polling outside recording; web lists
      paginated, lazy-loading guard test, < 300 ms server time.

---

## Phase 7 — full QA

- [ ] P7.1 All suites and linters green; tests for every new feature.
- [ ] P7.2 Security re-sweep: no key leaks, pins intact, every API route authorised, share tokens ≥ 128-bit.
- [ ] P7.3 docs/MANUAL_QA.md, docs/API.md, docs/ARCHITECTURE.md, README.md updated; docs/USER_GUIDE.md written.

## Phase 8 — release

- [ ] P8.1 Windows installer built.
- [ ] P8.2 Android release APK built and signed.
- [ ] P8.3 Production deploy: database backup confirmed, maintenance mode, upload, dependencies, assets,
      `migrate --force`, caches, back up; checks: `/admin/login` 200, `.env` 403, a device syncs, an old
      recording plays.
- [ ] P8.4 `v1.1` merged into `main` and pushed.
