# EchoLedger v1.1 — Fix + Premium Upgrade (master instructions for Claude Code)

> **How to run:** save this file as `docs/UPGRADE_V1_1.md` in the `echoledger` workspace, then tell Claude Code:
> `Read docs/UPGRADE_V1_1.md and execute it completely, phase by phase, without stopping to ask unless a rule below says to.`

---

## 0. Role, rules and context

You are a **senior product engineer, product designer and QA lead** working in the existing EchoLedger
monorepo (`web/` Laravel 13 + Filament 5, `desktop/` Tauri 2 + React 19 + Rust, `mobile/` Kotlin + Compose + Glance).
Starting point: commit `e9e8d93` on `main`. The web app is **live in production** at
`https://transcript.skillleo.com` (Hostinger shared hosting, PHP 8.4 at `/opt/alt/php84/usr/bin/php`, cron
`schedule:run` every minute, no daemons, MySQL/MariaDB).

**Goal of v1.1:** fix every known bug (Part A), add the 20 features in Part B, and raise the whole product to a
**premium, built-in-app standard** (Part C): it must feel like a feature of Windows / macOS / Android, not a
third-party tool, and be ready to become a SaaS later.

**Ground rules (non-negotiable):**
1. **Transcription only.** No AI summaries, no LLM calls, no new paid services. Every feature below must work
   without spending extra tokens. The only paid calls are speech-to-text, and only for recordings the user approved.
2. **Never break what works.** Run the full baseline test suites first. After every phase, all suites + linters
   must be green (`php artisan test`, Pint, Larastan L6; `cargo test`, `cargo clippy --all-targets -D warnings`,
   `npm run test`, `npm run build`; `./gradlew assembleDebug testDebugUnitTest ktlintCheck`).
3. **Do not invent APIs.** Before using any OS API, crate, library or provider parameter, read its real docs or the
   installed source/headers. Note the source in a code comment for non-obvious calls. If unsure, write a test first.
4. **Every bug gets a regression test** that fails before the fix and passes after.
5. **Commit after every phase** (and every large sub-step) on branch `v1.1`: `git commit -m "v1.1 phase N: …"`.
   Keep `PLAN_V1_1.md` (create it in Phase 0) as the single source of truth; tick items as you finish them.
   If your context is compacted or you resume later, **re-read `PLAN_V1_1.md` first** and continue from the first
   unticked item.
6. **Security stays strict.** Provider keys never leave the server. Keep cert pinning, Sanctum abilities, encrypted
   storage and 2FA. No telemetry. Never commit secrets. Never rotate or print `ECHOLEDGER_RECORDINGS_KEY`.
   Never run seeders on production.
7. **Clean-room only.** Do not copy code, strings or assets from GPL projects (e.g. CallVault) or the branding of
   other products. Study public UX patterns (Loom, Windows Snipping Tool, Granola, Wispr Flow, Fathom, Otter,
   Linear/Atlassian) and implement our own.
8. **Honest reporting.** Things you cannot prove in your sandbox (real call audio, overlays on real screens, real
   phones, biometrics) are reported as "built + unit-tested, needs device check" and added to `docs/MANUAL_QA.md`.
9. Use simple, short UI copy in sentence case. No jargon in the UI.
10. **Token budget — work alone, no agents.** Do **not** use the Agent/Task tool, subagents, parallel agents,
    forks or background agents for anything (research, exploration, coding, review or testing). Do all work
    yourself, sequentially, in this one session. To save tokens without lowering quality:
    - Read only the files and line ranges you need (use Grep/Glob first, then Read with offset/limit). Never
      re-read a file you just edited or already have in context.
    - Do not paste large logs back to yourself: pipe test/build output through `tail -40` or filter for
      failures; re-run only the failing test file while fixing, then the full suite once per phase.
    - Do web lookups only when an API detail is genuinely unknown; prefer the installed source/headers.
    - Keep chat updates short (one line per finished step); put detail in commits and `PLAN_V1_1.md`.
    - Quality rules above still apply in full: same tests, same linters, same checks. Time is not a
      constraint — go slower rather than spend tokens on agents.
11. Ask the user (in chat) only for: SSH credentials at deploy time, signing keys, or a decision that cannot be
    undone. Never write a credential into any file.

---

## Part A — P0 bugs (fix first, Phase 1)

### A1. Auto-mode records dictation and labels every browser as "Google Meet"
**What the user sees:** clicking the mic in ChatGPT, Claude or any website and speaking starts a "Google Meet call"
recording, uploads it and spends STT tokens. On `web.whatsapp.com` the session is labeled "Google Meet", not
"WhatsApp Web". Also likely: recording a voice message in WhatsApp Desktop is treated as a call.

**Root cause (verified in code):**
- `desktop/src-tauri/src/detect/classify.rs` → `classify_windows()` maps every browser image
  (`chrome.exe | msedge.exe | brave.exe | firefox.exe | opera.exe`) to `SourceApp::GoogleMeet`;
  `classify_macos()` does the same for the `BROWSERS` bundle ids.
- `detect/mod.rs` → `detect_call_app()` treats "an app holds the mic" as "a call". The ancestor walk in
  `audio/platform/windows.rs` (`active_capture_apps`, `ancestor_app`) attributes browser helper processes to the
  browser, so they also become "Google Meet".
- `detect/state.rs` starts after 3 s of mic use, so any dictation longer than 3 s triggers a recording.

**Required fix:**
1. **A call = two-way audio.** Auto-record only when the same app (process tree) has an **active capture session
   AND an active render session** for at least the start window. Windows: enumerate sessions on capture *and*
   render endpoints (`IAudioSessionManager2` / `IAudioSessionEnumerator`, state `AudioSessionStateActive`, map PID →
   process tree). macOS 14.4+: Core Audio process objects — verify the exact property names for "running input" and
   "running output" in the installed headers/bindings before use. Dictation (mic only) is never a call.
2. **Browsers are never identified by process name alone.** Identify the site:
   - Windows: enumerate all visible top-level windows of the browser's process tree (`EnumWindows`,
     `GetWindowThreadProcessId`, `GetWindowTextW`) and match titles. Optional bonus (verify on device, never
     required): UI Automation tab names.
   - macOS: window titles need Screen Recording permission. Prefer Apple Events (Automation permission, opt-in in
     onboarding) to read tab URLs of Chrome/Edge/Brave/Arc/Safari. If not granted → the site is "unknown".
   - Matching rules live in a **data-driven, unit-tested table** that the user can extend in Settings:
     `meet.google.com` / title starts with "Meet -" or contains "Google Meet" → `google_meet`;
     `web.whatsapp.com` / title contains "WhatsApp" → `whatsapp_web`; `zoom.us` web client / "Zoom" → `zoom`.
     Built-in **never-record** list: chatgpt.com, claude.ai, gemini.google.com, and any title containing them.
   - Unknown browser mic use → **do nothing** (default). Optional setting, **off by default**: "Ask me when an
     unrecognised app uses the microphone" → small non-blocking chip (Record / Ignore / Always ignore this site),
     auto-dismiss after 8 s.
3. Decide the label **once, at start**, and keep it for the session (switching tabs must not relabel).
4. Add `whatsapp_web` end-to-end: `web/app/Enums/SourceApp.php` (label "WhatsApp Web", icon, colour), API
   validation, `docs/API.md`, Filament badges and filters, desktop `SourceApp`, mobile label map, library filters.
   Keep old values valid. (Add `microsoft_teams` the same way only if it costs little.)
5. Extend the Record screen's "apps using the microphone" panel to explain each decision:
   `Holds mic ✓ · Playing audio ✓ · Identified as Google Meet · Decision: auto-record` /
   `Ignored: dictation (no audio playing)` / `Ignored: unknown site`.

**Tests:** chrome + "ChatGPT" title → none; chrome + "Meet - abc-defg-hij" → google_meet; chrome + "WhatsApp" →
whatsapp_web; WhatsApp capture-only (voice message) → not a call; WhatsApp capture + render → call; tab switch keeps
the label; state machine needs both signals for the full start window.

### A2. No "Keep / Discard" after a call or recording ends (desktop)
**Root cause:** `desktop/src-tauri/src/actions.rs` → `stop_recording()` calls `enqueue()` immediately, so every
recording is uploaded and transcribed. (The phone already has a post-call decision:
`mobile/.../calls/SaveDecisionReceiver.kt`; match its behaviour.)

**Required fix — Snipping-Tool-style review card:**
1. New state `PendingDecision`: the finished recording stays local and is **not** uploaded.
2. Show a small **review card** (new unfocusable, always-on-top Tauri window, bottom-right, hidden from screen
   share, slides in 180 ms): source icon + label, client chip (tap to change), duration, first caption line,
   buttons **Keep** (primary) · **Discard** · **⋯** (Rename, Assign client, Open). Clicks must work without
   stealing focus, like the caption overlay.
3. **Discard** = delete local files, never upload the live transcript, never request a final transcript; if a
   server session was already created at start, delete it via the API. Zero STT cost after discard.
4. **Keep** = enqueue upload → final transcript (current flow).
5. Setting "If I don't choose within 60 s": **Keep** (default — never lose a call silently) / Discard /
   Keep waiting. Undecided items appear in the Library under **"Waiting for your decision"** and survive restart
   (persist the pending list on disk).
6. Setting "Ask before saving": **Always** (default) / Only automatic calls / Never.
7. Phone parity: make the existing post-call notification a heads-up notification with the same preview fields
   and the same timeout rule; show the pending count on the Glance widget.

**Tests:** Keep → enqueued; Discard → files deleted, no HTTP upload, no transcript request; timeout → default
action; pending list survives restart.

### A3. Caption overlay hides its own buttons + no recording controls
**Root cause:** the overlay window is fixed at `460 × 150` (`desktop/src-tauri/src/overlay/mod.rs`,
`OVERLAY_SIZE`). In `desktop/src/styles.css`, `.overlay-root` uses `align-items: flex-end` and `.overlay-card` has
no max-height and no scroll area, so long captions grow the card **upward** past the window and the header
(drag · copy · close in `views/overlay/OverlayApp.tsx`) is clipped off-screen.

**Required fix:**
1. Card = flex column with `max-height: 100%`; header `flex: none` and always visible; caption body `flex: 1`,
   `overflow-y: auto`, auto-scroll to the newest line unless the user scrolled up (then show a small
   "Jump to live" pill). Recompute hit regions (`desktop/src/lib/hitRegions.ts`) when the body scrolls/resizes.
2. Visible lines setting (2 / 3 / 5); long partial turns wrap inside the body, never resize the window past the
   screen.
3. **Recording control pill** (new, Loom-style) shown whenever a recording runs (auto or manual): pulsing ● REC,
   timer, source icon + label, tiny Me/Client level meters, **Pause/Resume**, **Stop** (→ A2 review card),
   **Cancel** (inline "Discard? Yes / No"), **Captions on/off**, **Hide** (recording continues; tray icon shows
   state). Draggable, remembers position, unfocusable, hidden from screen share, ≤ 280 × 44 px.
4. Tray icon reflects state: idle / recording / paused / uploading / needs attention.

**Tests:** with 50 long lines the toolbar element stays inside the window (Playwright or a layout test; if jsdom
cannot measure, assert the CSS contract and add the visual check to MANUAL_QA); pill actions call the right commands.

### A4. Biometric app lock does not work (Android)
**Root causes (verified in code):**
- `mobile/.../MainActivity.kt`: `var locked by remember { mutableStateOf(lockEligible) }` is computed from
  `vm.settings`, which starts as the default `AppSettings()` (`appLockEnabled = false`) because
  `MainViewModel.settings` uses `stateIn(..., AppSettings())` before DataStore loads. `remember` never re-initialises
  and the `LaunchedEffect` only ever sets `locked = false` → **the lock never engages after a cold start.**
- No re-lock when the app goes to the background → once unlocked it stays unlocked.
- `ui/BiometricLock.kt` uses `BIOMETRIC_STRONG or DEVICE_CREDENTIAL`. Android docs: that combination (and
  `DEVICE_CREDENTIAL` alone) is **not supported on API 29 and lower**, and `minSdk` is 29 → the option silently
  disappears on Android 10. It also excludes weak-class face unlock, which many phones use.
- `ui/Screens.kt` hides the App lock row entirely when unavailable, so the user cannot see why.

**Required fix:** expose settings as "not loaded yet" until DataStore emits (show a neutral splash, never the
library, until loaded); compute `locked` after load; re-lock on `ON_STOP` with "Lock after: Immediately / 1 min /
5 min"; use `BIOMETRIC_WEAK or DEVICE_CREDENTIAL` (it is a UI gate, not a crypto key), check with
`canAuthenticate()` at runtime and fall back to `KeyguardManager` device-credential confirmation where a combination
is unsupported; never call `setNegativeButtonText()` together with `DEVICE_CREDENTIAL`; always show the row (disabled
+ reason + "Open security settings" when no screen lock); require one successful unlock to turn the lock on; when the
lock is on, set `FLAG_SECURE` (hides content in Recents and screenshots — user setting); any activity that shows
recordings or transcripts must respect the lock.
**Tests (Robolectric):** settings flow that emits `appLockEnabled = true` after the first frame → locked; `ON_STOP` →
locked again; authenticator choice per API level; row visible with reason when unavailable.

### A5. Never spend STT tokens without approval (guard)
- Server: `POST /api/stt/token` only for an open session owned by the device, in recording state, younger than
  4 h. Add an admin **monthly budget cap** (minutes or USD). Over the cap → clear error ("Monthly transcription
  limit reached") shown on all devices.
- Devices: never open a streaming socket unless the user started it or the auto-record rules approved it; close
  sockets on Pause; final transcription only after **Keep**.
- Record usage in a `usage_ledger` table (streamed minutes reported by devices at session end; final minutes from
  provider responses × billed channels).

---

## Part B — the 20 features (Phases 2–5)

Legend: **W** web · **D** desktop · **P** phone. Build where marked; keep one shared API.

**Capture and control**
1. **Auto-record rules (D, P).** Settings → Recording rules: per app/site toggles (WhatsApp, WhatsApp Business,
   WhatsApp Web, Google Meet, Zoom, Teams, others = ignore/ask), editable site list, never-record list, and the
   "why did/didn't it record" explainer from A1.
2. **Pause / resume + "off the record" (D, P).** Paused time is not captured, not streamed and not billed;
   markers show the gap in the transcript.
3. **Bookmarks and tags during calls (D, P, W).** Shortcut / pill button / phone notification action "Mark"
   adds a timestamped marker. One-tap tag presets: Requirement · Budget · Deadline · Decision · Follow-up (editable).
   Markers show on the timeline, in the transcript and in search filters.
4. **Caption styles (D, P).** Font size, opacity, lines, width, position presets (top/bottom left/right/centre),
   compact mode, auto-hide after N s of silence, Me/Client colours, "hidden from screen share" toggle.
5. **Capture health and pre-flight (D, P).** Device picker, live level meters, 10-second test recording,
   permissions centre (macOS mic/system audio/automation; Windows privacy; Android call-capture helper status),
   and a warning while recording: "Not hearing the other side for 30 s — check output device".

**Transcript experience**
6. **Pro transcript player (W, D, P).** Chat-style speaker blocks, word-level highlight sync, click-to-seek,
   speed 0.5–2×, skip silence, waveform with bookmarks, auto-follow toggle, keyboard: Space, J/K/L, ←/→ 5 s.
7. **Transcript editing + speaker names (W, D).** Inline edit, rename Me/Client to real names (remembered per
   client), find & replace, original version always kept with "Restore original" (revisions table).
8. **Global full-text search (W, D, P).** Across all transcripts with filters (client, app, device, date, tag,
   bookmarked, status); results jump to the exact moment with highlighted snippet. MySQL FULLTEXT index in
   production with a LIKE fallback for SQLite tests.
9. **Command palette + keyboard-first (W, D).** Ctrl/Cmd + K: search recordings, jump to pages, run actions
   (record, toggle captions, open settings). Every screen fully keyboard-navigable.
10. **Export and secure share (W, D, P).** TXT, Markdown, DOCX, PDF, SRT, VTT, JSON; copy with/without
    timestamps or speaker names. Share links: expiring (1 h / 1 d / 7 d), optional password, view-only,
    revocable, audio optional; access logged.
11. **Custom vocabulary 2.0 (W, D, P).** Global + per-client keyterms (respect each provider's limits), text
    replacements (e.g. "skill leo" → "SkillLeo") applied after transcription, and **learn from edits**: when the
    user corrects a word, offer "Add to vocabulary".
12. **Language routing (W, D, P).** Per client / per session: English, Urdu, Hindi, English + Urdu mixed, Auto.
    Route each to a model that supports it: verify each provider's current language list in its docs first
    (e.g. AssemblyAI Universal-3.5 Pro does not list Urdu, while the streaming Universal-3.6 Pro does). Show
    the user which model will be used.
13. **Re-transcribe and compare (W).** Re-run a recording with another configured engine; keep both versions;
    side-by-side diff; choose the primary version.

**Dictation**
14. **Dictation 2.0 (D, P).** Push-to-talk (hold) and toggle modes; inserts at the cursor in any app (clipboard
    restore afterwards); personal dictionary and replacements; voice snippets ("my email" → text); rule-based
    cleanup (capitalisation, punctuation, filler words "um/uh"), no LLM; history with re-copy; start/stop sounds;
    a slim "flow bar" indicator. Dictation **never** creates a recording session or a Keep/Discard prompt.

**Organisation, security and SaaS foundations**
15. **Clients and smart views (W, D, P).** Client folders with colour and avatar, tags, pins/favourites,
    multi-select bulk actions (move, tag, export, delete), smart views: Today, This week, Waiting for decision,
    Bookmarked, Failed uploads.
16. **Security centre (W, D, P).** App lock on desktop (Windows Hello / Touch ID, verify APIs; PIN fallback),
    fixed Android lock (A4), **passkeys** for web login (keep TOTP), list and revoke active devices and web
    sessions, audit log (logins, deletes, exports, share links, credential changes).
17. **Retention controls (W, D, P).** Retention 7 / 14 / 30 / custom days globally and per client; "Keep" pin per
    recording that exempts it; deleted items go to a 48-hour undo bin; storage meter (server disk used).
18. **Notifications and activity centre (W, D, P).** Bell with "Transcript ready", "Upload failed — retry",
    "Device paired", "Budget at 80%"; OS notifications that open the exact recording; quiet hours.
19. **Onboarding and system-style settings (W, D, P).** First-run wizard (permissions → pairing → 10-second test
    → done). Settings grouped like a system app: General · Recording · Captions · Dictation · Shortcuts · Privacy
    & security · Devices · Storage · About. Searchable settings, shortcut editor with conflict detection
    (Ctrl+Shift+C/R clash with browsers — avoid them as defaults), import/export settings (no secrets).
20. **Usage dashboard + SaaS foundations (W).** Minutes per provider per month, estimated cost, storage, per
    device, budget alerts (A5). Prepare SaaS: `workspaces` table with all data scoped by `workspace_id`, roles
    (owner, member), plan limits table (minutes, storage, devices) enforced by one policy class. **No billing
    yet.** Existing data migrates into a default workspace owned by the current super admin.

---

## Part C — premium "built-in app" standard (Phase 6, applies to every screen)

**Feel:** quiet, fast and native. Nothing jumps, nothing blocks, nothing asks twice.
- **Design system:** one token file per app (colour, spacing on an 8-pt grid, radius 8/12/16, shadows, motion).
  One icon set with one stroke width. System fonts: Segoe UI Variable (Windows), SF Pro (macOS/iOS), Roboto/Google
  Sans Flex where available (Android), Inter as the web fallback.
- **Desktop:** Windows 11 Mica/Acrylic window material (solid fallback on Windows 10), macOS vibrancy with inset
  traffic lights; follow system light/dark and accent colour; tray/menu-bar first; the main window is optional.
  Overlays: translucent blur, 1-px hairline border, radius 14–16, soft shadow, ≤ 3 lines by default.
- **Web:** custom Filament theme (Vite) with Linear/Atlassian-level density and typography: clear page headers,
  breadcrumbs, consistent tables with sticky headers, empty states with one clear action, skeleton loaders,
  responsive down to phone width.
- **Android:** Material 3 with optional dynamic colour, edge-to-edge, predictive back, haptics on key actions,
  polished Glance widgets in 2×1, 2×2 and 4×2 sizes (record button, status, last transcript, pending decisions).
- **Motion:** 150–220 ms ease-out; respect "reduce motion".
- **States:** every list and screen has loading, empty, error (with Retry) and offline states.
- **Accessibility:** WCAG 2.2 AA contrast, visible focus rings, full keyboard use, screen-reader labels.
- **Copy:** short, plain, sentence case, no technical terms; toasts say what happened and what to do.

**Performance budgets (measure and report the real numbers):**
- Desktop, idle with the main window closed: ≤ 150 MB RAM across all app processes, < 1 % CPU; hidden overlays do
  no rendering work; detection polling ≤ 1 Hz without per-tick heap churn.
- Desktop while recording with captions: < 8 % CPU on a typical laptop.
- Android: no wake locks or polling outside recording; widget updates event-driven.
- Web: every list paginated; no N+1 queries (add a test that fails on lazy loading in dev); pages < 300 ms server time
  on shared hosting for normal data sizes.

---

## Phases (execute in order)

- **Phase 0 — Prepare.** `git pull`; create branch `v1.1`; read `CONTRIBUTING.md`, any `CLAUDE.md` files, `PLAN.md`,
  `docs/*` (including `docs/research/`); run all
  suites to record a green baseline; write `PLAN_V1_1.md` with a checkbox for every item in Parts A–C.
- **Phase 1 — Part A (A1–A5)** with regression tests. Commit.
- **Phase 2 — Capture and control** (features 1–5). Commit.
- **Phase 3 — Transcript experience** (features 6–13). Commit.
- **Phase 4 — Dictation 2.0** (feature 14). Commit.
- **Phase 5 — Organisation, security, SaaS foundations** (features 15–20). Migrations must be reversible and safe
  on existing production data. Commit.
- **Phase 6 — Part C premium pass + performance budgets.** Commit.
- **Phase 7 — Full QA.** All suites + linters green; add tests for every new feature (feature tests for each API
  endpoint and Filament page, Rust logic tests, Vitest components, Robolectric for Android flows); security re-sweep
  (no key leaks, pins intact, every API route authorised, share links cannot be guessed — ≥ 128-bit random tokens);
  update `docs/MANUAL_QA.md` (add every device check), `docs/API.md`, `docs/ARCHITECTURE.md`, `README.md`,
  and a user-facing `docs/USER_GUIDE.md` in simple English. Commit.
- **Phase 8 — Release.**
  1. Desktop: `npm run tauri build` (Windows installer); report the output path.
  2. Android: release APK if signing is configured (`mobile/RELEASE.md`), otherwise debug APK; report the path.
  3. Web production deploy to `transcript.skillleo.com` (ask the user for SSH in chat if not available):
     back up the database first (`mysqldump` or hPanel backup) and confirm the backup file exists; `php artisan down`;
     upload; `composer install --no-dev --optimize-autoloader`; build assets locally and upload `public/build`;
     `php artisan migrate --force`; `optimize`; `filament:optimize`; `php artisan up`. Always use
     `/opt/alt/php84/usr/bin/php`. Never seed. Never touch `ECHOLEDGER_RECORDINGS_KEY`. Verify `/admin/login` = 200,
     `.env` = 403, a device can still sync, and an existing recording still plays.
  4. Merge `v1.1` into `main` and push.

---

## Final report (send in chat, simple English)

1. A table: every item in Parts A, B, C → Done / Done, needs device check / Not done (with the reason).
2. Test counts per app before and after, linter status, and measured performance numbers.
3. Exactly what the user must check on real devices (short numbered list pointing to `docs/MANUAL_QA.md`).
4. Anything the user must do (keys, signing, hPanel settings).
Do not claim device-only behaviour works until the user confirms it.