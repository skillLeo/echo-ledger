# Contributing to EchoLedger

Engineering conventions shared by the server (`web/`), desktop (`desktop/`) and Android (`mobile/`) apps.
The REST contract in [`docs/API.md`](docs/API.md) is authoritative for all clients; change the server and the
contract together, never one without the other.

## Principles

1. **Accuracy first, then security, then "no disturbance".** Never show repeated pop-ups, never steal focus,
   never interrupt a call.
2. **Verify third-party APIs against primary documentation** before relying on them. The notes in
   [`docs/research/`](docs/research) cite their sources; keep them current when a provider changes.
3. **Hardware-dependent behaviour** (audio capture, overlays, widgets, signing) must compile, have its pure
   logic unit-tested, and have a step in [`docs/MANUAL_QA.md`](docs/MANUAL_QA.md) describing how to verify it.
4. **Lean footprint.** No telemetry or analytics, no heavy runtimes, minimal dependencies.
5. **Secrets never ship in clients.** Provider keys live only on the server (encrypted); devices receive
   short-lived streaming tokens from `POST /api/stt/token`.

## Shared vocabulary

Use these exact values in code, APIs and UI.

| Concept | Values |
|---|---|
| `source_device` | `phone` "Phone" · `desktop` "Desktop" · `web` "Web" — always rendered as **icon + text** |
| `source_app` | `whatsapp` · `whatsapp_business` · `google_meet` · `zoom` · `voice_note` · `other` |
| Session `status` | `recording` · `uploading` · `processing` · `completed` · `failed` |
| Transcript `kind` | `live` (streaming captions) · `final` (accurate file pass) |
| STT providers | `assemblyai` (default) · `elevenlabs` · `azure_mai` · `whispercpp_local` · `parakeet_local` |
| Use cases | `live_captions` · `dictation` · `final_transcript` · `voice_note` |
| Channels | channel 1 (left) = **"Me"** (local microphone) · channel 2 (right) = **"Client"** (system / far party) · mono = "Speaker" |

Normalized transcript segment: `{channel, speaker, start_ms, end_ms, text, words:[{text, start_ms, end_ms, confidence}]}`.

## Data rules

- Capture sessions are stored in `capture_sessions` (Laravel's session driver owns `sessions`); API paths use
  `/api/sessions`.
- IDs exposed by the API are ULIDs; timestamps are ISO-8601 UTC; durations are milliseconds.
- Recordings and transcripts are purged after `retention_days` (default 30). Deletes sync as tombstones.
- Audio is encrypted at rest on the server and on Android. No third-party cloud storage.

## Design language

Premium, minimal, dark. Overlays are translucent, compact, never focusable, and click-through except for their
own controls. Tokens: background `#0B0D12`, surface `rgba(18,20,28,0.78)`, border `rgba(255,255,255,0.08)`,
text `#E6E8EE`, muted `#9AA3B2`, violet `#8B5CF6`, cyan `#22D3EE`, danger `#F43F5E`, success `#10B981`.
A visible recording indicator is mandatory whenever audio is captured.

## Server (`web/`)

- PHP 8.4+ with `declare(strict_types=1);`, constructor promotion, `final` services, readonly DTOs.
- Filament 5 APIs only (`Filament\Schemas\Schema`, `Filament\Actions\*`, `recordActions()`, `Filter::schema()`).
- Custom Blade views use scoped styles, not arbitrary Tailwind classes (no theme build required).
- Read secrets only through `App\Services\CredentialVault`; never log them, never fill them back into forms,
  never include them in API resources.
- Webhooks: verify the signature, record the event idempotently, respond fast, process in a queued job.
  Return `401` for bad signatures and `5xx` only for transient faults.
- Every outbound provider call sets `connectTimeout()` and `timeout()`; never retry a submission that may
  already have created a billable job.
- Shared hosting constraints: database queue, no daemons, no Redis, no WebSockets.

## Desktop (`desktop/`)

- All network I/O happens in Rust; the device token never reaches the webview (CSP `connect-src ipc:` only).
- Overlay windows are created once with `focused(false)` and `focusable(false)`; never call `show()` or
  `set_focus()` on them. Toggle click-through only on transitions.
- Never create windows inside synchronous commands or event handlers (deadlocks on Windows).
- Register/unregister global shortcuts only from async contexts; type dictation text after key release.
- Install the rustls crypto provider before any TLS use. Keep platform code thin and logic pure.

## Android (`mobile/`)

- AGP 9 built-in Kotlin (do not apply `org.jetbrains.kotlin.android`); JDK 21; compile/target SDK 37; min SDK 29.
- Start the microphone foreground service only from guaranteed exemptions (in-app, notification action,
  widget). Quick Settings tile and caption bubble paths are marked "verify on device".
- "Save this recording?" is a post-call notification with Keep / Discard, never a pop-up during a call.
- Recordings stay app-private and encrypted; uploads go only to the pinned server host.
- Call-capture code is an independent implementation built from AOSP documentation and the MIT-licensed
  Shizuku-API. Do not copy code, strings or assets from GPL-licensed call recorders.

## Workflow

- Branch from `main`; keep commits focused, with imperative, descriptive messages (`feat: …`, `fix: …`, `docs: …`).
- Before pushing, run the checks for every app you touched:
  - Server: `php artisan test`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`
  - Desktop: `npm run test`, `npm run build`, `cargo test`, `cargo clippy --all-targets -- -D warnings`
  - Android: `./gradlew assembleDebug test ktlintCheck`
- Never commit `.env` files, keystores, signing keys, recordings or real credentials.

## Local environment notes (Windows)

- On Windows, invoke Composer as `php composer.phar …` if the `composer.bat` shim strips `^` from version
  constraints.
- Rust lives in `%USERPROFILE%\.cargo\bin`; Android tooling expects `JAVA_HOME` to point at a JDK 21
  (the Android SDK's bundled `jdk` folder works).
