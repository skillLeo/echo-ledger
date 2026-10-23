# EchoLedger — Build Plan & Checklist

**Status:** Phase 0 ☑ · Phase 1 ☐ · Phase 2 ☐ · Phase 3 ☐ · Phase 4 ☐

Legend: `[ ]` todo · `[x]` done and verified by automated tests · `[~]` built + compiled +
unit-tested, **needs real-hardware verification** (listed in `docs/MANUAL_QA.md`).

**Delivery approach:** one-day delivery. Phases 1–3 deliver complete, compiling and booting code;
automated test suites, linters, the security review and resulting fixes are consolidated in Phase 4.
Quality gates are unchanged — only their order.

How to use this plan:
- Work phase by phase, top to bottom. One git commit per phase: `phase N: <summary>`.
- Before touching any third-party API, read the matching note in `docs/research/` (verified,
  with primary sources). Product requirements: `docs/REQUIREMENTS.md`.
- After each phase: run its Definition-of-Done commands, tick boxes, re-read this file.

---

## Key decisions and deliberate deviations (from verified research)

| # | Decision | Why / source |
|---|---|---|
| D1 | Capture sessions live in table `capture_sessions` (model `CaptureSession`); API paths stay `/api/sessions`, Filament slug `sessions`. | Laravel's database session driver owns `sessions`; `Session` is a facade alias. `web-laravel-filament.md` §0 |
| D2 | `Device` is the Sanctum tokenable (own `devices` auth provider; `sanctum.guard => []`). Devices register with a **one-time pairing code** (10 min, single use) created by a Filament action and shown as code + QR (`{v,url,code,pins}`). Tokens carry explicit abilities and an expiry; revoking a device deletes its tokens. | Admin password + TOTP never typed on devices. `web-laravel-filament.md` R3 |
| D3 | Web 2FA = Filament 5 built-in app (TOTP) MFA, **required**, with recovery codes. Email-code MFA skipped (needs SMTP). Panel access only for `is_super_admin` users. | `web-laravel-filament.md` §1 |
| D4 | Chunk uploads are raw `application/octet-stream` bodies (`Upload-Chunk-Index`, `Upload-Chunk-Sha256` headers), size advertised by the server (≤ 5 MiB, derived from `post_max_size`). | Multipart would be capped by `upload_max_filesize` (2 MB locally). `web-laravel-filament.md` R11 |
| D5 | A session owns 1–3 **recording artifacts**: `primary` (stereo Ogg/Opus preferred; FLAC/WAV accepted; voice notes keep their original format) plus optional mono 16 kHz WAV/FLAC `channel_1` / `channel_2`, requested only when the selected final provider cannot split channels or decode Opus (`azure_mai` with MAI model, `whispercpp_local`). `GET /api/config` tells devices which artifacts to produce. | MAI-Transcribe-2 has no channel separation and no Opus; whisper-server needs WAV/FLAC/MP3 without ffmpeg. `stt-azure-local.md` |
| D6 | `SttProvider` drivers declare a completion mode: `async_webhook` (assemblyai, elevenlabs), `sync` (azure_mai, whispercpp_local), `device` (parakeet_local — the device transcribes and uploads). | `stt-azure-local.md` R1 |
| D7 | AssemblyAI: streaming `universal-3-6-pro` (budget option `universal-streaming-english`); temp token `expires_in_seconds=60`, `max_session_duration_seconds=10800`; **one WS session per channel** (Me, Client); tokens are not config-bound, so clients send keyterms via `UpdateConfiguration` right after `Begin`. Final: `speech_models:["universal-3-5-pro","universal-2"]`, `multichannel:true`, `language_detection:true`, `keyterms_prompt` (cap 100 terms, ≤ 50 chars, ≤ 6 words), webhook auth header = per-recording HMAC; GET then DELETE (DELETE also purges the upload). | `stt-assemblyai.md` R1–R8 |
| D8 | ElevenLabs: `scribe_v2` batch with `use_multi_channel=true`, `diarize=false`, repeated `keyterms` parts (never `keyterms[0]`), string booleans, `webhook=true` + `webhook_id` + `webhook_metadata`; webhook HMAC `elevenlabs-signature: t=…,v0=…`; realtime `scribe_v2_realtime` single-use token **per channel**. | `stt-elevenlabs.md` R3–R8 |
| D9 | `azure_mai`: MAI-Transcribe-2 fast transcription (sync, one request per channel, WAV/FLAC, `timestamps:"word"`, `profanityFilterMode:"None"`, phrase list = keyterms); falls back to the standard model with `channels:[0,1]` when only stereo Opus exists. Live captions use a 10-min STS token + Speech SDK. No remote delete needed (not retained). | `stt-azure-local.md` R2, R5 |
| D10 | `whispercpp_local` = owner-hosted `whisper-server` behind HTTPS + bearer token; per-channel WAV sliced into ~10-min chunks with 2 s overlap. `parakeet_local` = on-device (sherpa-onnx) — v1 ships the server side (`awaiting_device_transcript` + transcript upload endpoint) and the device-side seam; bundling the 600 MB+ model is out of v1 scope. | `stt-azure-local.md` R3–R4 |
| D11 | Missed webhooks are recovered by a scheduled `stt:reconcile` (every 5 min) that GETs stuck jobs. | AssemblyAI bulk guide; ElevenLabs delivers STT webhooks once by default |
| D12 | Server encryption at rest: libsodium secretstream (XChaCha20-Poly1305) with a **dedicated** `ECHOLEDGER_RECORDINGS_KEY` (not derived from `APP_KEY`), reader enforces `TAG_FINAL`. Playback decrypts to memory for HTTP Range. Back the key up offline (DEPLOY.md). | `web-laravel-filament.md` §8 |
| D13 | Acoustic-bleed dedupe during normalization: drop a "Me" segment whose text closely matches an overlapping "Client" segment. | `stt-assemblyai.md` gotcha 19, `stt-azure-local.md` gotcha 18 |
| D14 | Desktop: all network I/O in Rust; the device token never reaches JS (CSP `connect-src ipc:` only). SPKI pin **set** (current + backup) for the Laravel host; provider WebSockets use standard WebPKI. Explicit rustls crypto provider installed at startup. | `desktop-tauri.md` R10 |
| D15 | Desktop overlays (`overlay`, `dictation`) are created once with `focused(false)` + `focusable(false)`, never `show()`/`set_focus()`, hidden via content + click-through; `content_protected` default **on** (captions hidden from screen share; user setting). | tao ≥ 0.37.1 focuses on `show()`. `desktop-tauri.md` R4 |
| D16 | Desktop default shortcuts `CommandOrControl+Shift+Space/R/C/D`, user-configurable from day 1, registration conflicts surfaced in Settings. | Ctrl+Shift+C/R collide with Chromium DevTools/hard reload. `desktop-tauri.md` gotchas |
| D17 | Windows < build 20348 (all Windows 10) → endpoint loopback fallback (captures all system audio; EchoLedger stays silent while recording). Process loopback (exclude own tree) on Windows 11. | `desktop-audio-native.md` claim 1 |
| D18 | Desktop upload artifact: stereo Ogg/Opus (libopus via `opus` crate, needs CMake + MSVC) with FLAC fallback; per-channel `mic.wav` / `system.wav` kept locally (and uploaded as `channel_1/2` when D5 requires). | `desktop-audio-native.md` R |
| D19 | Android far-party (VoIP) capture requires **Android 14+**: shell UID gains `CAPTURE_VOICE_COMMUNICATION_OUTPUT` in A14. App `minSdk 29`; call capture gated to API 34+ with a visible capability check. | `android-capture.md` claim 1 |
| D20 | Android privilege on-ramps: **Shizuku mode** (MIT Shizuku-API `UserService`; Shizuku itself performs the one-time 6-digit Wireless-debugging pairing) and **ADB command mode** (user runs a printed `adb shell … app_process …` command; the helper hands its binder to the app through an exported ContentProvider that checks `getCallingUidOrThrow() ∈ {2000, 0, ownUid}`). Built-in SPAKE2 pairing is **deferred**: the only JVM implementation is GPL-3.0 and would infect the paid app; a clean-room port needs device interop testing. | `android-capture.md` R3–R6 |
| D21 | Far party = `AudioMix` (players, `USAGE_VOICE_COMMUNICATION`, `ROUTE_FLAG_LOOP_BACK_RENDER` so the user still hears the call) via hidden `registerAudioPolicy`/`createAudioRecordSink`; near end = concurrent mic `AudioRecord` in the same helper. HiddenApiBypass (Apache-2.0) first in `main()`. Interleave L = mic, R = far → MediaCodec Opus → MediaMuxer OGG. | `android-capture.md` R2, R5 |
| D22 | "Save this recording?" = post-call notification with Keep / Discard actions plus an in-app pending list — never a popup before or during a call. | Priority #3 "no disturbance". `android-app.md` R6 |
| D23 | Android toolchain: AGP 9.4.1, Gradle 9.8.2, Kotlin 2.4.21 (AGP built-in Kotlin + compose/serialization plugins), JDK 21 (SDK-bundled), `compileSdk 37` / `targetSdk 37` ("target latest"; install `platforms;android-37`), `minSdk 29`. | `android-app.md` R1–R2 |
| D24 | Android secrets: DataStore + `datastore-tink` AEAD keyset wrapped by Android Keystore (security-crypto is deprecated). Recordings: Tink StreamingAead, recording UUID as associated data. Uploads via WorkManager. | `android-app.md` R7, R9 |
| D25 | No iPhone app in v1 (optional in spec; iOS cannot record VoIP calls). | `docs/REQUIREMENTS.md` |

---

## Phase 0 — Plan, conventions, verified research ☑

- [x] `docs/REQUIREMENTS.md` (product requirements) and `docs/API.md` (REST contract)
- [x] `docs/research/` — 8 API research notes, each fact cited to a primary source and cross-checked
- [x] `PLAN.md` (this file), `CONTRIBUTING.md` (engineering conventions for all three apps)
- [x] Toolchain ready: PHP 8.5 + Composer, Node 24, Rust 1.99 (MSVC) + VS 2022 Build Tools, Android SDK + JDK 21

**DoD:** files present; commit `phase 0: plan, conventions, verified research`.

---

## Phase 1 — Web (Laravel 13 + Filament 5)

**Goal:** the server of record — data model, encrypted storage, chunked uploads, STT orchestration
(token minting, final jobs, webhooks, reconciler), REST API for devices, and a premium dark Filament
admin with required 2FA.

### 1.1 Foundation
- [ ] Tooling: `pestphp/pest-plugin-livewire` (dev), `pest --init`, `RefreshDatabase` in `tests/Pest.php`, `phpstan.neon` (Larastan, level 6), `pint.json`, phpunit sqlite `:memory:`
- [ ] Enums (`app/Enums`, each `HasLabel`/`HasIcon`/`HasColor`): `SourceDevice`, `SourceApp`, `DevicePlatform`, `SessionStatus`, `TranscriptKind`, `TranscriptStatus`, `SttProviderKey`, `SttUseCase`, `RecordingRole`, `CompletionMode`
- [ ] Migrations + models + factories: `users` (+ `is_super_admin`, MFA columns), `devices`, `device_pairing_codes`, `clients`, `client_keyterms`, `capture_sessions` (ULID, soft deletes = tombstones, unique `(device_id, client_ref)`), `recordings` (ULID, role, upload state), `transcripts`, `settings`, `credentials` (`encrypted` cast, unique `(provider, key)`), `webhook_events` (unique `(provider, idempotency_key)`)
- [ ] Auth wiring: `devices` provider + Sanctum guard config, morph map (`user`, `device`), `bootstrap/app.php` (ability middleware aliases, JSON for `api/*`, guest redirect), named rate limiters (`device`, `uploads`, `webhooks`, `pairing`)
- [ ] `docs/API.md` — full REST contract with request/response JSON (authoritative for Phases 2–3)

### 1.2 Storage, encryption, uploads
- [ ] `recordings` private disk (no `serve`); `local` disk `serve => false`
- [ ] `RecordingCipher` (secretstream encrypt/decrypt streams, `TAG_FINAL` enforced, dedicated key from config; `php artisan echoledger:key` generates it)
- [ ] `ChunkedUploadService`: idempotent per-index writes, SHA-256 per chunk, resume state, size caps, chunk size from `ini_get('post_max_size')` ≤ 5 MiB
- [ ] `AssembleRecordingJob`: concatenate in order, verify size + SHA-256, encrypt, delete parts, then queue `TranscribeFinalJob` once all required artifacts are assembled

### 1.3 STT providers (`app/Stt`)
- [ ] `SttProvider` contract + DTOs (`StreamingGrant`, `FinalJobRequest`, `SubmittedJob`, `WebhookEnvelope`, `NormalizedTranscript`, `Segment`, `Word`) + `ProviderCapabilities` (mode, native multichannel, formats, keyterm limits, live token)
- [ ] `SttManager` resolving drivers from settings per use-case; `KeytermPolicy` (per-provider limits, dedupe, sanitize)
- [ ] Drivers: `AssemblyAiDriver`, `ElevenLabsDriver`, `AzureMaiDriver`, `WhisperCppLocalDriver`, `ParakeetLocalDriver`
- [ ] Normalization to the shared segment shape (channel 1 = Me, 2 = Client) + acoustic-bleed dedupe (D13)
- [ ] `TranscribeFinalJob` (idempotent: skip if `provider_job_id` set; branches on completion mode), `ProcessSttWebhookJob` (GET → store in one transaction → DELETE remote → `provider_deleted_at`), `stt:reconcile` command

### 1.4 REST API (Sanctum, abilities, throttled)
- [ ] `POST /api/devices/register` (pairing code) · `GET /api/config` · `POST /api/devices/rotate-token`
- [ ] `POST /api/stt/token` → per-channel grants with ready-to-use `ws_url`/params + keyterms; **never** the provider key
- [ ] `POST /api/sessions` (idempotent on `client_ref`; `source_device` derived from the device platform) · `GET /api/sessions/{id}` · `DELETE /api/sessions/{id}` (tombstone + immediate file/transcript deletion) · `POST /api/sessions/{id}/recordings` · `POST /api/sessions/{id}/transcripts` (device live/final transcripts, timing-validated)
- [ ] `GET /api/recordings/{id}` (resume state) · `POST /api/recordings/{id}/chunks` · `POST /api/recordings/{id}/complete` · `GET /api/recordings/{id}/audio` (temporary signed URL, Range)
- [ ] `GET /api/library?since=&source=&source_app=&client_id=&status=&cursor=` (all devices' items + tombstones, labels + icon keys) · `GET /api/transcripts/{id}` · `GET /api/clients`
- [ ] `POST /api/webhooks/stt/{provider}` (public, signature-verified, idempotent via `webhook_events`, 401 on bad signature, 5xx only for transient faults)

### 1.5 Filament admin (`/admin`, dark, violet primary, no global search)
- [ ] Panel: required app MFA + recovery codes, profile page, `FilamentUser::canAccessPanel` = super admin, branding "EchoLedger"
- [ ] Dashboard: stats by `source_device` (icon + label) and status, minutes transcribed (30 d), sessions-per-day chart; no polling
- [ ] Sessions library (`CaptureSessionResource`, list + view, no create): source device/app badges (icon + text), filters (source_device, source_app, client, date range, status), view + delete actions, header action "Upload voice note" (`source_device=web`)
- [ ] Session view: metadata infolist + `TranscriptPlayerEntry` (HTML5 audio from the admin media route + synced transcript; active segment highlight; click to seek; Me/Client colors; `wire:ignore`; scoped CSS — no arbitrary Tailwind)
- [ ] Clients resource + Keyterms relation manager (validation from `KeytermPolicy`)
- [ ] Devices resource (platform, source badge, last seen, version; rename, revoke) + "Pair a device" action (code + QR)
- [ ] Settings page (retention days, provider per use-case, provider options, caption channels, app URL, SPKI pin set)
- [ ] API Credentials page (write-only secret inputs, "configured ••••last4 · updated" status, generate webhook secrets) — super admin only
- [ ] Admin media route `GET /admin-media/recordings/{recording}` (web + auth + MFA middleware + policy) with Range

### 1.6 Jobs, scheduler, deploy
- [ ] `routes/console.php`: `queue:work --stop-when-empty --max-time=55 --tries=3 --timeout=900` everyMinute `withoutOverlapping(15)`; `stt:reconcile` every 5 min; `model:prune` daily; `sanctum:prune-expired --hours=24` daily
- [ ] Recordings + transcripts (+ webhook events, tombstoned sessions) `Prunable` with `retention_days` from settings; files deleted in model `deleted` events (tolerant of missing files)
- [ ] `DatabaseSeeder`: super admin from env/command; `DemoSeeder`: clients + keyterms, devices, library mixing phone + desktop + web items with playable generated stereo audio and synced transcripts
- [ ] `web/DEPLOY.md`: shared hosting (cPanel) steps, cron `* * * * * php /path/artisan schedule:run >> /dev/null 2>&1`, PHP ≥ 8.4.1 limits, `DB_QUEUE_RETRY_AFTER=960`, recordings key backup, `npm run build` before upload, `model:prune --pretend`

### 1.7 Tests (Pest)
- [ ] Prune cutoff (recordings/transcripts older than retention removed + files deleted; newer kept)
- [ ] Credentials encrypted at rest (raw DB value ≠ plaintext; round trip)
- [ ] Token endpoint never leaks the raw provider key (all drivers; upstream call carries it)
- [ ] Library filtering + source labels + tombstones + cross-device visibility
- [ ] Webhook idempotency + signature rejection (AssemblyAI header HMAC, ElevenLabs `t=,v0=` incl. stale/future/tampered)
- [ ] Chunked upload assembly (out of order, resume, duplicate chunk, checksum mismatch, oversize, encrypt/decrypt round trip, tamper/truncation detection)
- [ ] Drivers: request builders (exact JSON/multipart), fixture responses → normalized segments, DELETE after store, reconciler
- [ ] Filament: MFA required, super-admin-only access, list filters, view page renders player, delete, settings save, credentials write-only, device pairing code
- [ ] Audio endpoint: signed URL required, Range 206/416, no-store caching

**Files to create (main):** `app/Enums/*`, `app/Models/*`, `app/Stt/**`, `app/Services/{RecordingCipher,ChunkedUploadService,LibraryQuery,SettingsRepository,CredentialVault,PairingService}.php`,
`app/Http/Controllers/Api/*`, `app/Http/Controllers/AdminMediaController.php`, `app/Http/Requests/Api/*`, `app/Http/Resources/*`,
`app/Jobs/{AssembleRecordingJob,TranscribeFinalJob,ProcessSttWebhookJob}.php`, `app/Console/Commands/*`, `app/Filament/**`,
`resources/views/filament/**`, `database/migrations/*`, `database/factories/*`, `database/seeders/*`, `routes/api.php`,
`routes/console.php`, `tests/{Feature,Unit}/**`, `tests/Fixtures/stt/**`, `docs/API.md`, `web/DEPLOY.md`.

**Acceptance:** admin logs in with 2FA, pastes keys (stored encrypted), sees a seeded library mixing
phone + desktop + web items each labeled with icon + text, plays audio with a synced transcript, deletes
a session. **DoD:** `php artisan test` green · `vendor/bin/pint --test` clean · `vendor/bin/phpstan analyse` clean · commit `phase 1: ...`.

---

## Phase 2 — Desktop (Tauri 2 + React + TypeScript)

**Goal:** a tiny tray app for Windows 10/11 + macOS 14.4+ that captures mic ("Me") + system audio
("Client") as two aligned channels, shows a translucent click-through caption overlay, offers a
dictation box, auto-records calls, and uploads to the server.

### 2.1 Shell
- [ ] Identity `com.echoledger.desktop` / "EchoLedger"; versions pinned in lockstep (tauri 2.12.x; plugin crates = npm versions, npm `^` removed)
- [ ] Plugins: single-instance (first), global-shortcut, autostart (optional, off by default), notification, positioner, store, updater (signed, `requireSignedVersion`), clipboard-manager
- [ ] Windows: `main` (library/settings; close = hide), `overlay` + `dictation` created in Rust (D15); one `index.html`, view chosen by window label
- [ ] Tray/menu bar: open, captions on/off, start/stop saving, auto mode toggle, quit (`app.exit(0)`); `ExitRequested` with no code → `prevent_exit()`; macOS `ActivationPolicy::Accessory`
- [ ] Per-window capability files; CSP `connect-src ipc: http://ipc.localhost` (+ dev HMR exception)

### 2.2 Audio (`src-tauri/src/audio/`, layout per `desktop-audio-native.md`)
- [ ] Pure core: `types`, `timeline` (gap fill, T0 anchoring), `drift`, `align` (pad to equal length, interleave, f32→i16), writers (`wav` hound, `opus_ogg`, `flac`), `mock` deterministic source
- [~] Windows: `os_version` (RtlGetVersion gating ≥ 20348), process loopback (`wasapi` `new_application_loopback_client(own_pid, false)` = exclude tree) with endpoint-loopback fallback, mic capture, device-change notifications (IMMNotificationClient, debounced), `AUDCLNT_E_DEVICE_INVALIDATED` recovery
- [~] macOS: Core Audio process tap (`CATapDescription` excluding own process) + aggregate device with default input, IOProc → SPSC ring, default-device listeners; `NSAudioCaptureUsageDescription` + `NSMicrophoneUsageDescription`
- [~] Mic-in-use detection: Windows audio-session enumeration on capture endpoints (primary) + ConsentStore (secondary); macOS process objects; `MicUseStateMachine` (debounce, own-PID filter) + `classify` (exe/PFN/bundle id → `source_app`)

### 2.3 Overlay, shortcuts, dictation
- [~] Caption overlay: translucent, always-on-top, click-through on empty space (30 Hz Rust cursor poll toggling `set_ignore_cursor_events` on transitions; hit regions pushed from React), draggable, docks bottom-right/top-right from `Monitor::work_area()`, persists corner/offset, never steals focus, survives main-window close
- [~] Global shortcuts (configurable): toggle captions, start/stop saving, copy last 2 minutes, dictation box; conflict errors shown in Settings
- [~] Dictation: tiny overlay, mic-only streaming session, types final turns via `enigo` after shortcut release; clipboard + paste fallback; "professional" text cleanup (pure, tested)

### 2.4 STT + server sync
- [ ] Pinned API client (rustls 0.23 custom verifier = WebPKI + SPKI pin set), device token in OS keychain (`keyring`), pairing flow (server URL + code/QR payload)
- [ ] Streaming client (tokio-tungstenite over rustls) for AssemblyAI v3 (Begin → UpdateConfiguration keyterms → 50 ms PCM frames → Turn handling → Terminate/Termination, rollover before max duration) + ElevenLabs realtime; provider-neutral `CaptionEvent` via `tauri::ipc::Channel`
- [ ] Rolling caption buffer (copy last 2 minutes), live transcript upload at session end
- [ ] Chunked resumable uploader (plan/resume pure logic; artifacts per `GET /api/config`); library sync (`GET /api/library?since=`), tombstones
- [ ] Auto mode: WhatsApp/Zoom/Chrome-Meet mic use → start recording + captions; release → stop, encode, upload. Voice notes never auto-recorded: drag-drop import + "capture while playing" shortcut

### 2.5 UI (React 19 + TS, dark premium)
- [ ] Main: unified library (source icon + text badges, filters, transcript view), pairing, settings (shortcuts, captions channels, auto mode, content protection, autostart)
- [ ] Overlay view: captions with Me/Client styling, live indicator, minimal footprint; dictation view
- [ ] Vitest + RTL with `@tauri-apps/api/mocks`; Playwright smoke against `vite --mode e2e` (system Edge channel)

### 2.6 Tests
- [ ] `cargo test`: channel mixing/interleave, timeline gap fill + drift, equal-length alignment, build-number gating, overlay hit-testing + docking math (negative coords, mixed DPI), shortcut parsing, mic-use state machine + classifier, AssemblyAI/ElevenLabs message parsing, caption buffer, upload planning, Ogg/Opus header builders, SPKI pin verifier logic
- [ ] **Acceptance test:** mocked 2-channel capture writes `mic.wav` + `system.wav` of equal length, aligned
- [ ] Vitest: captions render from a mocked streaming token + mocked Turn events; library labels; settings
- [ ] `desktop/RELEASE.md`: Windows Authenticode/SmartScreen, macOS notarization, updater key handling

**Acceptance:** app builds on Windows (`npm run tauri build`), macOS code type-checked where possible
(real macOS build = manual QA); overlay transparent/click-through/on-top; shortcuts registered; mocked
capture acceptance test passes; captions render from a mocked token. **DoD:** `cargo test` + `cargo clippy --all-targets -- -D warnings` + `npm run test` + `npm run build` green · MANUAL_QA lines added · commit `phase 2: ...`.

---

## Phase 3 — Mobile (Kotlin + Jetpack Compose + Glance)

**Goal:** a sideloaded Android app that records WhatsApp / WhatsApp Business calls (both sides, Android
14+), imports shared voice notes, shows a caption bubble, offers widget/notification/tile triggers, and
syncs with the server.

### 3.1 Project
- [ ] Gradle wrapper 9.8.2 (bootstrapped), AGP 9.4.1, Kotlin 2.4.21, version catalog, compile/target 37, min 29, JDK 21; `.editorconfig` + ktlint 1.8.0 (plugin 14.2.0); app id `com.echoledger.mobile`; no analytics
- [ ] Manifest: permissions (RECORD_AUDIO, FOREGROUND_SERVICE(_MICROPHONE), POST_NOTIFICATIONS, SYSTEM_ALERT_WINDOW, INTERNET, Shizuku API permission), network security config (no cleartext; debug LAN override)

### 3.2 Capture core (clean-room; no CallVault code/strings/assets)
- [~] Helper (`app_process` / Shizuku UserService, UID 2000): HiddenApiBypass, system context, `AudioPolicy` far-party mix (D21), concurrent mic recorder, call detection (`MODE_IN_COMMUNICATION` + WhatsApp `USAGE_VOICE_COMMUNICATION` stream owner), AIDL `ICaptureHelper`
- [~] Shizuku mode (Shizuku-API `UserService`) and ADB command mode (printed command + exported `BinderHandoffProvider` with UID allowlist + signature permission)
- [x] Pure logic unit-tested: caller-UID policy, PCM interleaver/two-channel writer framing, call state machine, capability gating (API ≥ 34)
- [~] Two-channel Opus/OGG encoding (MediaCodec + MediaMuxer), encrypted app-private storage (Tink StreamingAead, Keystore-wrapped)
- [ ] In-app setup guide: Shizuku pairing / ADB command, keep Wireless/USB debugging on, re-arm after reboot (notification action / widget), consent setting + recording indicator

### 3.3 Recording UX
- [~] `RecordingService` (FGS type microphone, immediate notification with Stop) started from: in-app button, notification action, Glance widget (`actionStartService(isForegroundService = true)`), QS tile (verify on device), caption bubble (verify on device)
- [ ] Auto-record WhatsApp + WhatsApp Business calls; post-call "Save this recording?" notification (Keep / Discard) + in-app pending list
- [ ] Share-sheet import (ACTION_SEND/SEND_MULTIPLE, `audio/*`, `application/ogg`) → voice-note session; never auto-recorded
- [~] Glance widget: quick record, status, last transcript; floating caption bubble (`TYPE_APPLICATION_OVERLAY`, not focusable, own lifecycle owner)

### 3.4 Network + data
- [ ] OkHttp with `CertificatePinner` (current + backup SPKI pins) for the server host only; device token in DataStore + Tink (Keystore); pairing via code/QR payload
- [ ] STT token fetch + streaming client (AssemblyAI v3 / ElevenLabs realtime) for captions; WorkManager chunked resumable uploads; library sync with tombstones
- [ ] Compose UI: unified library with source icon + text, session detail/transcript, setup, settings

### 3.5 Tests (JUnit + Robolectric 4.17 + MockWebServer + Glance unit tests)
- [ ] Binder caller-UID checks · two-channel writer · save-after-call state machine · share-intent parsing · widget state/actions · notification action PendingIntent · token fetch · chunk upload/resume · AssemblyAI message parsing · library labels

**Acceptance:** `./gradlew assembleDebug` builds; unit tests pass; save-after-call, widget,
notification action, share-import flows unit-tested with fakes. **DoD:** `./gradlew assembleDebug test ktlintCheck` green · MANUAL_QA lines added · commit `phase 3: ...`.

---

## Phase 4 — Integration, security, QA

- [ ] E2E Pest test (mocked STT): register device → mint token → create session → upload 2-channel file in chunks → complete → final job → signed webhook → transcript stored → library on phone, desktop and web "devices" shows it with the correct source label
- [ ] Security sweep: no provider key reaches any client (grep + tests); credentials encrypted at rest; cert pinning on both device apps; Sanctum + abilities on every API route except the signed webhook; 2FA enforced; upload validation (size, MIME allowlist, checksums, path safety); no telemetry deps; secrets not logged
- [ ] Docs: `README.md` (run each app), `docs/ARCHITECTURE.md` (data model + API + sync), `docs/MANUAL_QA.md` (device checklist incl. signing: Authenticode, notarization, APK signing), `docs/PROVIDERS.md` (switching drivers, credentials, costs, retention)
- [ ] Final: `php artisan test` + Pint + Larastan; `cargo test` + clippy + Vitest + build; `./gradlew assembleDebug test ktlintCheck` — all green; commit `phase 4: green`

**Definition of done (project):** this file fully ticked (`[~]` items listed in MANUAL_QA); all four
contributor docs present; every suite + linter green; one commit per phase; final report separates
**DONE** from **NEEDS REAL-DEVICE VERIFICATION** — audio capture is reported as "compiles + unit-tested",
never as working.
