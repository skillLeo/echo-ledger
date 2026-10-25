# EchoLedger — Product Requirements

## Vision

A personal-first, commercially viable transcription suite: capture conversations on desktop and phone, see live
captions, and keep an accurate, speaker-separated transcript library on infrastructure the owner controls.
Transcription only — no AI summaries in this release (extension points are kept).

## Priorities

1. **Accuracy** — two-pass transcription, per-client vocabulary, clean two-channel audio.
2. **Security** — keys server-side only, encryption at rest, pinned transport, optional (recommended) admin 2FA.
3. **No disturbance** — no repeated pop-ups, no focus stealing, nothing that interrupts a call.

## Users

- **Owner / professional** who records client calls (WhatsApp, WhatsApp Business, Google Meet, Zoom) and
  voice notes, and needs reliable transcripts organized by client.
- **Administrator** (the owner) who manages devices, providers, credentials and retention from a web console.

## Functional requirements

### Server and admin console
- Data model: users, devices, clients and keyterms, capture sessions, recordings, transcripts, settings,
  encrypted credentials, webhook events.
- Admin console at `/admin` with optional TOTP two-factor sign-in and a super-administrator role.
- Screens: dashboard (counts by source), sessions library (filter by source device, app, client and date;
  playback with synced transcript; delete), clients and keyterms, devices (pairing, revocation), settings
  (retention, provider per use case), API credentials (write-only, encrypted).
- REST API for devices (see [`API.md`](API.md)): pairing, configuration, streaming tokens, sessions,
  resumable chunked uploads, library sync with tombstones, transcripts, deletion, provider webhooks.
- Final transcription runs asynchronously (queue + provider webhook), with a reconciler for missed webhooks;
  the provider-side copy is deleted after retrieval.
- Recordings and transcripts are deleted automatically after the retention period (default 30 days).

### Desktop (Windows 10/11, macOS 14.4+)
- Tray / menu-bar application that keeps running when the main window is closed; optional start at sign-in.
- Two-channel capture: microphone ("Me") and system audio ("Client"). Windows: WASAPI process loopback
  (excluding EchoLedger) on Windows 11, endpoint loopback on Windows 10. macOS: Core Audio process tap.
  Follow default-device changes (headsets, Bluetooth).
- Live caption overlay: compact, translucent, always on top, click-through except its controls, draggable,
  docks to a screen corner, never steals focus, keeps working when the main window is closed.
- Configurable global shortcuts: toggle captions, start/stop saving, copy the last two minutes, dictation box.
- Dictation: a small box transcribes speech and inserts the text into the focused application (or clipboard).
- Automatic mode: start recording and captions when WhatsApp, Zoom or a browser (Meet) uses the microphone;
  stop and upload when it releases it. Voice notes are never recorded automatically (drag-and-drop or a
  capture-while-playing shortcut instead).

### Android (Android 14+ for call capture; app supports Android 10+)
- Record WhatsApp and WhatsApp Business calls (both sides as two channels) through a privileged helper
  enabled once via Shizuku or an ADB command; after each call ask "Save this recording?" (Keep / Discard).
- Voice notes are imported through the Android share sheet, never recorded automatically.
- Start recording from a notification action, a home-screen widget, the app, a Quick Settings tile and an
  optional floating caption bubble.
- Home-screen widget: quick record, status, last transcript.
- Live captions in a floating bubble that does not disturb other apps.
- Encrypted, app-private storage; uploads only to the paired server.

### Cross-device
- Every device shows the full library (phone items on desktop and vice versa), labeled by source device and app.
- Sync: last-write-wins per session; devices poll on open and after each capture.

## Non-functional requirements

- **Lightweight:** native shells (Tauri, Kotlin) instead of heavyweight runtimes; no telemetry.
- **Shared-hosting friendly server:** PHP + database queue + one cron entry; no long-running processes.
- **Provider abstraction:** AssemblyAI (default), ElevenLabs, Azure Speech, self-hosted whisper.cpp,
  on-device Parakeet — selectable per use case.
- **Licensing:** independent implementations only; permissive dependencies (e.g. MIT Shizuku-API) so the
  product can be sold without copyleft obligations.
- **Consent:** visible recording indicator and an in-app consent acknowledgement.

## Out of scope (this release)

- iPhone call recording (not permitted by iOS); an iPhone companion may follow for voice-note import only.
- AI summaries or other generative features.
- Third-party cloud storage (Google Drive etc.).

## Acceptance criteria

- Admin signs in (2FA optional), stores provider keys encrypted, sees a library mixing phone, desktop and web items
  with correct labels, plays audio with a synced transcript and deletes a session.
- Desktop builds on Windows and macOS; overlay is transparent, click-through and always on top; shortcuts
  work; a simulated two-channel capture produces aligned, equal-length `mic.wav` and `system.wav`; captions
  render from a streaming token.
- Android builds; save-after-call, widget, notification action and share-import flows are covered by tests.
- An end-to-end test covers pairing → token → session → chunked upload → webhook → transcript visible on all
  devices with the correct source label.
- All automated test suites and linters pass; hardware checks are listed in [`MANUAL_QA.md`](MANUAL_QA.md).
