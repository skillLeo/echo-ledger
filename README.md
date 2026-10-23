<div align="center">

# EchoLedger

**Private, accuracy-first transcription for calls, meetings and voice notes — running on your own server.**

![Laravel 13](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
![Filament 5](https://img.shields.io/badge/Filament-5-FDAE4B)
![Tauri 2](https://img.shields.io/badge/Tauri-2-24C8DB?logo=tauri&logoColor=white)
![Rust](https://img.shields.io/badge/Rust-1.90%2B-000000?logo=rust&logoColor=white)
![Kotlin](https://img.shields.io/badge/Kotlin-2.4-7F52FF?logo=kotlin&logoColor=white)
![Platforms](https://img.shields.io/badge/platforms-Windows%20%7C%20macOS%20%7C%20Android%20%7C%20Web-0B0D12)
![License](https://img.shields.io/badge/license-proprietary-8B5CF6)

</div>

---

EchoLedger captures both sides of a conversation — your microphone as **"Me"** and the other party as
**"Client"** — shows live captions while you talk, and produces an accurate, speaker-separated transcript
afterwards. Recordings and transcripts live only on infrastructure you control, encrypted at rest, and every
device sees one unified library.

The platform consists of three applications that share one data model and one REST API:

| Application | Stack | Role |
|---|---|---|
| **Server** (`web/`) | Laravel 13 · Filament 5 · Sanctum | System of record, admin console, REST API, speech-to-text orchestration |
| **Desktop** (`desktop/`) | Tauri 2 · Rust · React 19 · TypeScript | Windows 10/11 and macOS tray app: dual-channel capture, caption overlay, dictation |
| **Android** (`mobile/`) | Kotlin · Jetpack Compose · Glance | WhatsApp call capture, home-screen widget, caption bubble, voice-note import |

## Highlights

- **Two-pass accuracy.** Low-latency live captions stream directly from each device to the speech provider;
  a second, high-accuracy pass transcribes the finished two-channel recording with per-client vocabulary.
- **Speakers separated by design.** Mic and system audio are recorded as separate channels, so "Me" and
  "Client" are labeled without guesswork. Acoustic bleed between channels is detected and removed.
- **Per-client keyterms.** Names, products and jargon configured per client boost recognition in both passes.
- **One library, every device.** Phone recordings appear on the desktop and vice versa, each clearly labeled
  with its source device (Phone · Desktop · Web) and app (WhatsApp, Google Meet, Zoom, voice note …).
- **Unobtrusive by default.** A translucent caption overlay that never takes focus, clicks pass through it, it
  hides from screen sharing, and it never interrupts a call with pop-ups.
- **Provider freedom.** AssemblyAI, ElevenLabs Scribe, Azure Speech (MAI-Transcribe), a self-hosted
  whisper.cpp server, or on-device Parakeet — selectable per use case from the admin console.
- **Runs on shared hosting.** No daemons, Redis or WebSocket server: one cron entry drives the queue,
  webhook reconciliation and retention.

## Architecture

```mermaid
flowchart LR
    subgraph Devices
        D["Desktop app<br/>Tauri · Rust · React"]
        A["Android app<br/>Kotlin · Compose"]
    end
    S["EchoLedger Server<br/>Laravel · Filament"]
    P[("Speech-to-text providers<br/>AssemblyAI · ElevenLabs · Azure · whisper.cpp")]

    D -- "pinned HTTPS · pairing, uploads, library" --> S
    A -- "pinned HTTPS · pairing, uploads, library" --> S
    S -- "short-lived streaming tokens" --> D
    S -- "short-lived streaming tokens" --> A
    D -. "live audio (WSS)" .-> P
    A -. "live audio (WSS)" .-> P
    S -- "final transcript jobs (multichannel + keyterms)" --> P
    P -- "signed webhooks" --> S
```

**How a recording flows**

1. A device pairs once with the server using a one-time code (or QR) generated in the admin console and stores
   its scoped token in the OS keychain / Android Keystore.
2. For live captions the device requests a short-lived streaming token; the provider API key never leaves the
   server. Audio streams directly from the device to the provider, one session per channel.
3. When the call ends, the device uploads the two-channel recording in resumable, checksummed chunks.
4. The server verifies and encrypts the file, submits a multichannel transcription job with the client's
   keyterms, receives the signed webhook, stores the normalized transcript and deletes the provider-side copy.
5. Every device picks up the new item on its next library sync; deletions propagate as tombstones.

## Platform capabilities

| Capability | Windows 10 | Windows 11 | macOS 14.4+ | Android 14+ | Web console |
|---|:-:|:-:|:-:|:-:|:-:|
| Microphone ("Me") | ✓ | ✓ | ✓ | ✓ | — |
| Other party ("Client") | ✓ endpoint loopback | ✓ process loopback | ✓ Core Audio tap | ✓ privileged helper | — |
| Live caption overlay | ✓ | ✓ | ✓ | ✓ bubble | — |
| Dictation into any app | ✓ | ✓ | ✓ | — | — |
| Automatic call detection | ✓ | ✓ | ✓ | ✓ WhatsApp | — |
| Voice-note import | ✓ drag & drop | ✓ drag & drop | ✓ drag & drop | ✓ share sheet | ✓ upload |
| Library, playback, transcript | ✓ | ✓ | ✓ | ✓ | ✓ synced player |

iOS does not allow third-party apps to record VoIP calls, so an iPhone client is out of scope for this release.

## Security and privacy

- **Keys stay on the server.** Provider credentials are encrypted at rest and never sent to devices; devices
  receive only short-lived streaming tokens.
- **Encryption at rest.** Recordings are encrypted with XChaCha20-Poly1305 (libsodium secretstream) under a
  dedicated key on the server, and with Tink streaming AEAD (Android Keystore–wrapped keys) on Android.
- **Hardened transport.** Device apps pin the server's public key (current + backup pins); uploads are
  verified chunk by chunk with SHA-256.
- **Strong administration.** The console requires TOTP two-factor authentication with recovery codes; device
  tokens are scoped by ability and revocable per device.
- **Retention.** Recordings, transcripts and webhook payloads are purged automatically after a configurable
  period (30 days by default). Provider copies are deleted as soon as a transcript is stored.
- **No telemetry.** None of the applications include analytics or tracking.
- **Consent.** A visible recording indicator is always shown. Call-recording laws vary by jurisdiction; users
  are responsible for obtaining consent where required.

## Repository layout

```
echoledger/
├── web/        Laravel server: API, admin console, STT orchestration, retention
├── desktop/    Tauri desktop app: Rust core (audio, overlay, networking) + React UI
├── mobile/     Android app: capture helper, recording service, widget, caption bubble
├── docs/       API contract, architecture, providers, requirements, manual QA, research notes
├── PLAN.md     Delivery plan and progress checklist
└── CONTRIBUTING.md  Engineering conventions shared by all three apps
```

## Getting started

### Prerequisites

| Component | Requirements |
|---|---|
| Server | PHP 8.4+ (`sodium`, `intl`, `zip`, `pdo_*`), Composer 2, MySQL 8 / MariaDB 10.6+ or SQLite |
| Desktop | Rust stable (MSVC toolchain on Windows), Node.js 22+, Visual Studio Build Tools (C++) on Windows, Xcode CLT on macOS |
| Android | Android SDK (platform 37), JDK 21 |

### Server

```bash
cd web
composer install
cp .env.example .env
php artisan key:generate
php artisan echoledger:key            # dedicated recordings encryption key — back it up offline
php artisan migrate --seed
php artisan serve
```

Open `http://localhost:8000/admin`, sign in, enrol two-factor authentication, then add provider keys under
**API Credentials**. Production deployment on shared hosting is covered in [`web/DEPLOY.md`](web/DEPLOY.md).

### Desktop

```bash
cd desktop
npm install
npm run tauri dev          # development
npm run tauri build        # signed installers: see desktop/RELEASE.md
```

### Android

```bash
cd mobile
./gradlew assembleDebug    # app/build/outputs/apk/debug/app-debug.apk
```

The Android app is distributed as a signed APK (call recording apps are not eligible for Google Play).
Call capture requires Android 14 or later and a one-time Shizuku or ADB setup; the app guides you through it.

## Speech-to-text providers

| Provider | Live captions | Final transcript | Notes |
|---|:-:|:-:|---|
| AssemblyAI *(default)* | ✓ | ✓ | Universal streaming + multichannel async with keyterms, webhooks |
| ElevenLabs Scribe | ✓ | ✓ | Scribe v2 multichannel, signed webhooks |
| Azure Speech (MAI-Transcribe) | — desktop / ✓ planned | ✓ | Fast transcription, phrase lists |
| whisper.cpp (self-hosted) | — | ✓ | Your own GPU/CPU server behind an authenticated proxy |
| Parakeet (on-device) | — | ✓ | Fully offline, transcript produced on the device |

Providers are assigned per use case (live captions, dictation, calls, voice notes) in **Settings**. See
[`docs/PROVIDERS.md`](docs/PROVIDERS.md) for credentials, costs and data-retention behaviour.

## Quality

```bash
# Server
cd web && php artisan test && vendor/bin/pint --test && vendor/bin/phpstan analyse
# Desktop
cd desktop && npm run test && cd src-tauri && cargo test && cargo clippy --all-targets -- -D warnings
# Android
cd mobile && ./gradlew test ktlintCheck
```

Hardware-dependent behaviour (audio capture, overlays, widgets, code signing) is verified with the checklist in
[`docs/MANUAL_QA.md`](docs/MANUAL_QA.md).

## Documentation

| Document | Contents |
|---|---|
| [`docs/API.md`](docs/API.md) | REST API contract shared by all clients |
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | Data model, sync model, audio pipeline, security design |
| [`docs/PROVIDERS.md`](docs/PROVIDERS.md) | Configuring and switching speech-to-text providers |
| [`docs/REQUIREMENTS.md`](docs/REQUIREMENTS.md) | Product requirements and scope |
| [`docs/MANUAL_QA.md`](docs/MANUAL_QA.md) | Real-device verification checklist |
| [`web/DEPLOY.md`](web/DEPLOY.md) | Shared-hosting deployment and cron setup |
| [`desktop/RELEASE.md`](desktop/RELEASE.md) | Windows code signing, macOS notarization, signed updates |
| [`mobile/RELEASE.md`](mobile/RELEASE.md) | APK signing and sideload distribution |

## License

Copyright © 2026 skillLeo. All rights reserved. This is proprietary software; see [`LICENSE`](LICENSE).
Third-party components remain under their respective licenses.
