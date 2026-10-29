# EchoLedger REST API (v1.1) — authoritative contract

Server: `web/` (Laravel). Clients: `desktop/` (Tauri, all HTTP in Rust) and `mobile/` (Kotlin, OkHttp).
If implementation and this file disagree, fix one of them in the same change — never leave drift.

**What changed in v1.1** (everything else is unchanged from v1 and stays valid):

- `GET /api/config` gains `api_version` and a `usage` block (monthly usage and limit).
- Open sessions: `POST /api/sessions` without `artifacts` creates a session in status `recording`; the new
  `POST /api/sessions/{id}/finish` attaches the artifacts later. The one-shot create stays valid.
- `POST /api/stt/token` requires `session_id` for `live_captions` and is refused when the monthly limit is reached.
- New `POST /api/usage` (idempotent streaming usage reports).
- Business errors carry a machine-readable `code`; `429` always sends `Retry-After`.
- `complete` answers `422 verification_failed` when the whole file does not verify; a failed upload is sent again.
- Library sync: `since` is inclusive; a malformed cursor is `422`; transcript changes bump the session.
- New source apps `whatsapp_web` and `microsoft_teams`.
- Transcripts carry an `error_code`; `rotate-token` keeps the old token's abilities; a range past the end is `416`.

Every addition below is marked **(since v1.1)**.

## Conventions
- Base URL: `{APP_URL}/api`. HTTPS only in production. JSON (`Accept: application/json`) except chunk bodies and audio.
- Auth: `Authorization: Bearer <device token>` (Sanctum token owned by a `Device`). Abilities on every device
  token: `config:read`, `stt:token`, `sessions:write`, `recordings:upload`, `library:read`.
- IDs are ULIDs (26 chars). Timestamps are ISO-8601; the server stores and returns UTC (`2026-10-24T09:00:00+00:00`)
  and accepts any offset on input (`…Z`, `…+05:00`), converting it to the instant it names **(since v1.1)**.
  Durations in ms.
- Errors use Laravel's shape plus a machine-readable `code` **(since v1.1)**:
  `{"message": "…", "code": "…", "errors": {"field": ["…"]}}` (`errors` only on validation failures).
  Clients branch on `code`, never on `message`. Messages never name internal classes **(since v1.1)**.
  `401` token missing/expired/revoked (client must re-pair) · `403` missing ability, or monthly limit reached ·
  `404` · `409` conflict · `413` body too large · `416` range not satisfiable · `422` validation ·
  `429` rate limited — the response always carries `Retry-After` (seconds) and clients wait at least that long
  before the next request to the same route **(since v1.1)** · `502`/`503` speech provider unavailable.
- Rate limits: pairing 5 per minute per IP; `stt/token` 30 per minute per device; recording upload routes
  (`GET /api/recordings/{id}`, chunks, complete) 600 per minute per device; every other authenticated route 240
  per minute per device **(since v1.1: upload routes are no longer also counted against the general limit)**.
- `GET /api/config` → `api_version` tells a client which contract the server speaks **(since v1.1)**. When the field
  is absent the server is v1: clients then skip open sessions, `session_id`, `POST /api/usage` and use the one-shot
  `POST /api/sessions`.
- Device apps pin the server's SPKI SHA-256 set (`pins`), current + backup. Provider WebSockets are NOT pinned.
- Provider API keys never appear in any response. Streaming grants are short-lived tokens only.

### Error codes (since v1.1)

| HTTP | `code` | Meaning | What the client does |
|---|---|---|---|
| 401 | `unauthenticated` | Token missing, expired or revoked | Stop syncing and uploading, keep local recordings, show the unpaired state |
| 403 | `missing_ability` | Token lacks the route's ability | Permanent: do not retry |
| 403 | `budget_exceeded` | Monthly limit reached: the server cap (message `Monthly transcription limit reached`) or, since B20, the workspace plan's minutes (message `Monthly limit reached — raise it in Settings`) | Keep recording without captions; show the message; do not retry until `usage.limit_reached` is false |
| 403 | `storage_limit_reached` | Since B20: a new recording would pass the workspace plan's storage (message `Storage limit reached — raise it in Settings`) | Permanent for this upload: keep the recording on the device, show the message |
| 403 | `device_limit_reached` | Since B20: pairing would pass the workspace plan's devices (message `Device limit reached — raise it in Settings`) | Show the message; do not retry |
| 403 | `not_allowed` | Since B20: the role does not allow it — a member deleting, restoring or erasing someone else's recording, or a non-admin device changing the shared vocabulary | Permanent; show the message |
| 404 | `not_found` | Unknown route | Permanent |
| 404 | `session_not_found` | Session unknown, deleted, or (finish, stt/token) not created by this device | finish: create a new session with a new `client_ref`; delete: treat as done |
| 404 | `recording_not_found` | Recording unknown or its session was deleted | Drop the upload slot |
| 404 | `transcript_not_found` | Transcript unknown or its session was deleted | Refresh the library |
| 404 | `client_not_found` | `client_id` unknown or in another workspace | Clear the selected client and retry once without it |
| 404 | `device_not_found` | `DELETE devices/{id}`: the device is unknown or someone else's | Refresh the device list |
| 405 | `method_not_allowed` | Wrong HTTP method | Permanent |
| 409 | `upload_complete` | Chunk sent after the recording started assembling | Stop sending chunks; poll `GET /api/recordings/{id}` |
| 409 | `session_not_recording` | `stt/token`: the session is not in status `recording`, or is 4 hours old or older | Captions are unavailable for this recording; keep recording |
| 409 | `too_many_open_uploads` | 50 or more unfinished recordings exist for this device | Finish pending uploads, then retry |
| 409 | `too_many_open_sessions` | 10 or more sessions of this device are still `recording` | Finish or discard them, then retry |
| 409 | `current_device` | `DELETE devices/{id}` for the device making the request | Unpair from the device's own settings |
| 409 | `nothing_to_export` | `sessions/export`: none of the chosen recordings has a finished transcript | Permanent for those recordings |
| 409 | `views_unavailable` | `transcripts/{id}/views`: the transcript is not finished, or no Anthropic key is set (since v1.2) | Add the key, or wait for the transcript |
| 409 | `cannot_retranscribe` | `sessions/{id}/retranscribe`: the recording's audio is not on the server (not uploaded yet, or erased; since v1.2) | Retry once the upload has finished |
| 410 | `restore_expired` | `sessions/{id}/restore` after the 48-hour undo bin ended | Permanent: the recording is gone |
| 413 | `too_large` | Body or declared size above the limit | Permanent |
| 422 | `validation_failed` | Field errors in `errors` | Permanent for that request |
| 422 | `session_required` | `stt/token` for `live_captions` without `session_id` | Open a session first (v1.1 clients); v1 clients lose captions only |
| 422 | `upload_incomplete` | `complete` while chunks are missing (`missing_chunks`) | Send the missing chunks, then call `complete` again |
| 422 | `verification_failed` | `complete`: the assembled file failed the size/SHA-256 check; the parts were discarded | Upload the whole file again from chunk 0 |
| 422 | `invalid_cursor` | `library` cursor is malformed | Restart paging without a cursor |
| 422 | `too_many_labels` | More than 20 labels on one recording | Permanent: remove some labels first |
| 429 | `rate_limited` | Throttled; `Retry-After` is always set | Wait `Retry-After` seconds |
| 502 | `provider_error` | `stt/token`: the provider refused or could not be reached | Retry later |
| 503 | `provider_not_configured` | `stt/token`: provider key or region missing | Show the message; retry later |

## Enumerations
| Field | Values |
|---|---|
| `platform` | `android`, `ios`, `windows`, `macos`, `linux` |
| `source_device` (derived by server: android/ios→`phone`, windows/macos/linux→`desktop`, admin upload→`web`) | `phone` "Phone" icon `device-phone-mobile` · `desktop` "Desktop" icon `computer-desktop` · `web` "Web" icon `globe-alt` |
| `source_app` | `whatsapp` "WhatsApp" · `whatsapp_business` "WhatsApp Business" · `whatsapp_web` "WhatsApp Web" (since v1.1) · `google_meet` "Google Meet" · `zoom` "Zoom" · `microsoft_teams` "Microsoft Teams" (since v1.1) · `telegram` "Telegram" · `signal` "Signal" · `messenger` "Messenger" · `instagram` "Instagram" · `skype` "Skype" · `slack` "Slack" · `discord` "Discord" (these seven since v1.3, calls recorded on the phone) · `phone_call` "Phone call" (since v1.2, a SIM call recorded on the phone) · `voice_note` "Voice note" · `other` "Other" |
| session `status` | `recording`, `uploading`, `processing`, `completed`, `failed` |
| recording `role` | `primary` (stereo or mono file used for playback + multichannel providers), `channel_1` (mono "Me"), `channel_2` (mono "Client") |
| upload `status` | `pending`, `uploading`, `assembling`, `ready`, `failed` |
| transcript `kind` / `status` | `live`, `final`, `alternate` (since v1.1), `trial` (since v1.1.2: a Compare engines run, never in the library or search) / `pending`, `submitted`, `processing`, `awaiting_device`, `completed`, `failed` |
| provider | `assemblyai`, `elevenlabs`, `azure_mai`, `whispercpp_local`, `parakeet_local`, `soniox` (since v1.1.2, final transcripts only), `groq` (since v1.2: Whisper large-v3, final transcripts only) |
| use case | `live_captions`, `dictation`, `final_transcript`, `voice_note` |
| transcript `error_code` (since v1.1, set when `status` is `failed`) | `no_audio`, `budget_exceeded`, `provider_unreachable`, `provider_rejected`, `provider_not_configured`, `provider_timeout`, `interrupted`, `recording_missing` |

**Segment** (shared transcript shape; channel 1 = "Me" mic/left, channel 2 = "Client" system/right; mono = channel 1 "Speaker"):
```json
{"channel": 1, "speaker": "Me", "start_ms": 250, "end_ms": 2600, "text": "Smoke from the north.",
 "words": [{"text": "Smoke", "start_ms": 250, "end_ms": 650, "confidence": 0.97}]}
```

**Transcript `error_code` messages** (since v1.1). The server message is platform-neutral; each app adds its own
hint for `no_audio` (desktop: check the microphone and the audio device; Android: check Setup → Call capture).

| `error_code` | `error` (message) |
|---|---|
| `no_audio` | `No audio was captured in this recording.` |
| `budget_exceeded` | `Monthly transcription limit reached` |
| `provider_unreachable` | `The speech provider could not be reached. Try again later.` |
| `provider_rejected` | `The speech provider could not transcribe this recording: <provider reason, at most 200 characters>` |
| `provider_not_configured` | `<what to configure>` (for example `AssemblyAI is not configured. Add its API key on API Credentials.`) |
| `provider_timeout` | `The speech provider did not finish within 6 hours.` |
| `interrupted` | `Transcription was interrupted. Retry it from the console.` |
| `recording_missing` | `The recording is missing or not ready.` |

---

### Workspaces (since B20)

Every device, client, recording and usage row belongs to one workspace; a device token acts for the workspace it was
paired into (the console user's current workspace when the code was made). Everything a device reads or changes is
limited to that workspace: an id from another workspace answers exactly like an unknown id (`404`). Members of a
workspace share its library. A member deletes, restores and erases only recordings they made (`403 not_allowed`
otherwise); owners may do this for any recording. Plan limits (minutes per month, storage, devices) answer
`403 budget_exceeded`, `403 storage_limit_reached` and `403 device_limit_reached`. The shared vocabulary (no
`client_id`) is server-wide and only a super admin's device may change it.

## Pairing & configuration

### `POST /api/devices/register` — public, throttled (5/min/IP)
The admin creates a one-time pairing code in Filament (Devices → "Pair a device"; valid 10 min, single use).
The QR shows JSON `{"v":1,"url":"https://ledger.example.com","code":"7KQ2M9XD","pins":["sha256/…=","sha256/…="]}`.
Codes are 8 Crockford-base32 chars, displayed `7KQ2-M9XD`; the server ignores hyphens/case.
```json
// request
{"pairing_code": "7KQ2-M9XD", "name": "S25 Ultra", "platform": "android", "app_version": "1.0.0", "os_version": "16"}
// 201
{"device": {"id": "01JB…", "name": "S25 Ultra", "platform": "android", "source_device": "phone", "created_at": "…"},
 "token": "<plain-text token, returned once>", "token_expires_at": "2027-10-24T00:00:00+00:00",
 "config": { …same body as GET /api/config… }}
```
`422 validation_failed` invalid, expired or used code (`errors.pairing_code`).

### `POST /api/devices/rotate-token` — auth
`200 {"token": "…", "token_expires_at": "…"}`; the old token is revoked immediately. The new token carries exactly the
abilities of the token that asked for it, never more **(since v1.1)**.

### `GET /api/config` — `config:read`
```json
{"api_version": "1.1",
 "device": {"id": "01JB…", "name": "S25 Ultra", "platform": "android", "source_device": "phone"},
 "server_time": "2026-10-24T09:00:00+00:00",
 "retention_days": 30,
 "upload": {"chunk_size_bytes": 5242880, "max_recording_bytes": 2147483648,
            "accepted_mimes": ["audio/ogg", "audio/opus", "audio/flac", "audio/x-flac", "audio/wav", "audio/x-wav",
                               "audio/wave", "audio/mpeg", "audio/mp4", "audio/x-m4a", "audio/aac", "audio/webm",
                               "audio/amr", "application/ogg"]},
 "providers": {
   "live_captions":    {"provider": "assemblyai", "mode": "cloud"},
   "dictation":        {"provider": "assemblyai", "mode": "cloud"},
   "final_transcript": {"provider": "assemblyai", "mode": "async_webhook", "required_artifacts": ["primary"], "preferred_primary": "ogg_opus"},
   "voice_note":       {"provider": "assemblyai", "mode": "async_webhook", "required_artifacts": ["primary"], "preferred_primary": "ogg_opus"}
 },
 "captions": {"channels": "both"},
 "markers": {"tags": ["Requirement", "Budget", "Deadline", "Decision", "Follow-up"]},
 "pins": ["sha256/AAAA…=", "sha256/BBBB…="],
 "usage": {"month": "2026-10", "time_zone": "Asia/Karachi",
           "period_start": "2026-09-30T19:00:00+00:00", "period_end": "2026-10-31T19:00:00+00:00",
           "minutes": 812.5, "cost_usd": 6.42,
           "limit_minutes": 3000, "limit_usd": null,
           "warn_at_percent": 80, "warn": false, "limit_reached": false}}
```
- `api_version` **(since v1.1)**: `"1.1"`. Absent on v1 servers.
- Live `mode`: `cloud` | `on_device`. Final `mode`: `async_webhook` | `sync` | `device`.
- `required_artifacts` is `["primary","channel_1","channel_2"]` when the final provider needs per-channel mono
  16 kHz WAV/FLAC (`azure_mai` with MAI model, `whispercpp_local`). `preferred_primary`: `ogg_opus` | `flac` | `wav`.
- `captions.channels`: `both` | `client_only` | `off`.
- `usage` **(since v1.1)** — this calendar month (in the console's display time zone) for the whole server:
  - `month` `YYYY-MM`; `time_zone` the IANA zone the month is cut in; `period_start` / `period_end` the month's
    bounds as UTC instants (end exclusive).
  - `minutes`: billable provider minutes, one decimal (audio minutes × billed channels, summed over the usage
    ledger; on-device and self-hosted engines are not billable). `cost_usd`: estimate from the console price
    table, two decimals.
  - Since B20 `minutes` and `cost_usd` are those of the device's workspace (a single-workspace server sees no change).
  - `limit_minutes` / `limit_usd`: the server-wide monthly limits set in the console, `null` when not set.
  - `warn`: `true` once usage reaches `warn_at_percent` (80) of any set limit. `limit_reached`: `true` once usage
    reaches 100 % of any set limit: the server limits on the whole server's usage, or the workspace plan's monthly
    minutes on the workspace's usage. While `limit_reached` is true, `stt/token` answers `403 budget_exceeded` and
    new final transcripts fail with `error_code` `budget_exceeded`. Both are `false` when no limit is set.

### `GET /api/clients` — `library:read`
`200 {"data": [{"id": "01JB…", "name": "Acme Holdings", "color": "#8B5CF6", "avatar": "AH", "pinned": true, "retention_days": null, "keyterm_count": 12, "session_count": 40, "updated_at": "…"}]}`

Pinned folders come first, then by name. `avatar` (one emoji or up to two letters), `pinned` and `session_count`
are since v1.1; client folders are created and changed with `POST /api/clients` and `PATCH /api/clients/{id}`
(see "Organising the library").

---

## Live captions & dictation

### `POST /api/stt/token` — `stt:token`, throttled (30/min/device)
```json
// request
{"use_case": "live_captions", "session_id": "01JB…", "client_id": "01JB…", "channels": ["me", "client"],
 "sample_rate": 16000, "language": null}
```
- `use_case` ∈ `live_captions` | `dictation` (dictation is always `["me"]`). `client_id` selects that client's keyterms.
- `session_id` **(since v1.1)**: required for `live_captions`, ignored for `dictation`. It must be a session created
  by the calling device (`POST /api/sessions` without artifacts), still in status `recording`, created less than
  4 hours ago. Missing → `422 session_required`; unknown, deleted or another device's → `404 session_not_found`;
  not `recording` or too old → `409 session_not_recording`.
- Monthly limit **(since v1.1)**: when `usage.limit_reached` is true, both use cases answer
  `403 {"message": "Monthly transcription limit reached", "code": "budget_exceeded"}` and no provider is called.
- Checks run in this order: validation → session → client (`404 client_not_found`) → captions `off` (200 below) →
  monthly limit → provider.
```json
// 200 — AssemblyAI (protocol assemblyai_v3)
{"provider": "assemblyai", "mode": "cloud", "expires_at": "2026-10-24T09:01:00+00:00",
 "keyterms": ["Acme Holdings", "Farah Khan"],
 "grants": [
   {"channel": "me", "transport": "websocket", "protocol": "assemblyai_v3",
    "ws_url": "wss://streaming.assemblyai.com/v3/ws?speech_model=universal-3-6-pro&encoding=pcm_s16le&sample_rate=16000&inactivity_timeout=120&token=…",
    "redeem_by": "2026-10-24T09:01:00+00:00", "max_session_seconds": 10800,
    "audio": {"encoding": "pcm_s16le", "sample_rate": 16000, "channels": 1, "frame_ms": 50},
    "init_messages": [{"type": "UpdateConfiguration", "keyterms_prompt": ["Acme Holdings", "Farah Khan"]}]},
   {"channel": "client", "…": "same shape"}
 ]}
```
- `elevenlabs` → `protocol: "elevenlabs_realtime"`, one single-use token per grant baked into `ws_url`
  (keyterms included as repeated query params), `frame_ms: 100`, `init_messages: []`.
- `azure_mai` → `transport: "azure_speech_sdk"`, grant fields `token`, `region`, `expires_in` (600), `refresh_after` (540), `phrases`.
- `whispercpp_local` / `parakeet_local` → `{"provider": "…", "mode": "on_device", "grants": []}`.
- Captions off in the console (`captions.channels = off`) → `200 {"provider": null, "mode": "off", "expires_at": null, "keyterms": [], "grants": []}`.
- `502 provider_error` when the provider refuses or cannot be reached; `503 provider_not_configured` when keys or
  region are missing.
- Wire protocols (message names, framing, termination, rollover) are specified in
  `docs/research/stt-assemblyai.md` (R3) and `docs/research/stt-elevenlabs.md` (R8). Clients send each
  `init_messages` entry as a JSON text frame right after the provider's session-start message, before audio.
- Devices open a provider socket only when the user started captions or dictation, or when the auto-record rules
  approved a call; they close every socket on pause and on stop, and report each socket-open period with
  `POST /api/usage` **(since v1.1)**.

### `POST /api/usage` — `stt:token` (since v1.1)
Idempotent streaming usage report. Devices send one report per streaming period: from the moment the provider
sockets of a captions or dictation run open until they close (stop, pause, or the end of the recording). A pause
or a restart starts a new period with a new `report_id`. Reports are queued on the device and retried until the
server answers 200/201; a `422` report is dropped.
```json
// request
{"report_id": "6c0d0b2e-2a4e-4c8e-9a9f-0c3f1f5b7d11", "use_case": "live_captions", "provider": "assemblyai",
 "session_id": "01JB…", "streamed_ms": 61000, "channels": 2, "ended_at": "2026-10-24T09:43:00Z"}
// 201 (new report) — 200 with "duplicate": true when report_id was already stored (the stored row is returned)
{"report": {"id": "01JC…", "report_id": "6c0d0b2e-…", "use_case": "live_captions", "provider": "assemblyai",
            "session_id": "01JB…", "streamed_ms": 61000, "channels": 2, "billed_ms": 122000, "cost_usd": 0.0153,
            "occurred_at": "2026-10-24T09:43:00+00:00", "duplicate": false},
 "usage": { …same as GET /api/config usage… }}
```
- `report_id`: client-generated, 8–64 chars of `A-Z a-z 0-9 . _ : -` (a UUID v4); unique per owner.
- `use_case` ∈ `live_captions` | `dictation` (final transcripts are recorded by the server, never reported).
- `provider` ∈ the provider enumeration (the `provider` of the token answer).
- `session_id` optional: the open session the captions belonged to. A deleted session is still linked; an unknown
  id is stored as `null` (usage is never refused for that reason).
- `streamed_ms`: per channel, 0–86 400 000. AssemblyAI: `session_duration_seconds × 1000` from the `Termination`
  message when it arrived, otherwise the measured socket-open time. ElevenLabs: the measured socket-open time
  (silence frames are billed too). When the channels differ, send the longest.
- `channels`: number of provider sockets in the period, 1–2.
- `ended_at` optional: when the period ended (ISO-8601); defaults to the time the report arrives; values more than
  35 days in the past or 5 minutes in the future are clamped. It decides the month the usage counts in.
- Reports are accepted even when the monthly limit is reached. `billed_ms = streamed_ms × channels`;
  `cost_usd = billed_ms / 3 600 000 × price(provider, use_case)` from the price table at the time of the report.

---

## Sessions, recordings, uploads

**Session lifecycle (since v1.1).** A device creates a server session at recording start **only** when it needs a
live-caption token. With captions off nothing reaches the server until the user keeps the recording.

| Step | Request | Session status |
|---|---|---|
| Recording starts with captions on | `POST /api/sessions` without `artifacts` (open session) | `recording` |
| Captions run | `POST /api/stt/token` with `session_id`; `POST /api/usage` per streaming period | `recording` |
| User keeps the recording | `POST /api/sessions/{id}/finish` with the artifacts → upload slots | `uploading` |
| User keeps a recording made with captions off, a voice note, an import, or any v1 client | `POST /api/sessions` with `artifacts` (one-shot) | `uploading` |
| Upload, assembly, final transcript | chunks → `complete` → poll `GET /api/recordings/{id}` | `uploading` → `processing` → `completed` / `failed` |
| User discards the recording | `DELETE /api/sessions/{id}` (only if a session was opened) | tombstone |

A scheduled sweep (every 15 minutes) deletes sessions still in status `recording` that are 6 hours old or older and
have no recordings; they become tombstones like any delete. Sessions in status `recording` are not listed by
`GET /api/library` (unless `status=recording` is asked for explicitly); their tombstones are.

### `POST /api/sessions` — `sessions:write`
Idempotent on `(device, client_ref)`: repeating the call returns `200` with the existing session.

**One-shot create (v1, still valid)** — with `artifacts` (1–3), status `uploading`:
```json
// request
{"client_ref": "3f2b6c1e-8d4a-4d3e-9a51-2c7f0e9b1a22", "source_app": "whatsapp", "client_id": null, "title": null,
 "started_at": "2026-10-24T09:00:00Z", "ended_at": "2026-10-24T09:42:10Z", "duration_ms": 2530000, "language": null,
 "artifacts": [
   {"role": "primary", "mime": "audio/ogg", "codec": "opus", "channels": 2, "sample_rate": 48000,
    "size_bytes": 10485760, "sha256": "<hex>", "duration_ms": 2530000}
 ]}
// 201
{"session": { …Library item… },
 "recordings": [{"id": "01JB…", "role": "primary",
                 "upload": {"chunk_size_bytes": 5242880, "total_chunks": 2, "received_chunks": [], "status": "pending", "error": null}}],
 "required_artifacts": ["primary"]}
```
**Open session (since v1.1)** — without the `artifacts` key, status `recording`, `recordings: []`:
```json
// request
{"client_ref": "3f2b6c1e-8d4a-4d3e-9a51-2c7f0e9b1a22", "source_app": "google_meet", "client_id": null, "title": null,
 "started_at": "2026-10-24T09:00:00Z", "language": null}
// 201
{"session": { …Library item, "status": "recording"… }, "recordings": [], "required_artifacts": ["primary"]}
```
- `source_device` is derived from the calling device (never sent by clients). `final_provider` is fixed at creation.
- `artifacts`, when present, must hold 1–3 entries with exactly one `primary` and unique roles (`422`). An empty
  array is a `422`.
- Artifact `sample_rate` is an integer 8000–192000 or `null`; `0` means unknown and is stored as `null`; anything
  else (text, fractions) is a `422` **(since v1.1: no coercion)**.
- If `client_ref` matches an existing session of this device that is still `recording` and the request carries
  `artifacts`, the server finishes that session exactly like `POST /api/sessions/{id}/finish` and answers `200`
  **(since v1.1)**. If it matches a deleted session the tombstone is returned unchanged (`200`, upload slots
  refuse chunks) — create the recording again with a new `client_ref`.
- `404 client_not_found` for an unknown `client_id`; `409 too_many_open_uploads` / `409 too_many_open_sessions`.

### `POST /api/sessions/{id}/finish` — `sessions:write` (since v1.1)
Moves an open session to `uploading` and returns its upload slots. Only the device that created the session may
finish it (others: `404 session_not_found`; a deleted session: `404 session_not_found`).
```json
// request
{"ended_at": "2026-10-24T09:42:10Z", "duration_ms": 2530000, "title": "Budget review", "client_id": "01JB…",
 "artifacts": [
   {"role": "primary", "mime": "audio/flac", "codec": "flac", "channels": 2, "sample_rate": 16000,
    "size_bytes": 10485760, "sha256": "<hex>", "duration_ms": 2530000}
 ]}
// 200 — same shape as the create answer
{"session": { …Library item, "status": "uploading"… },
 "recordings": [{"id": "01JB…", "role": "primary",
                 "upload": {"chunk_size_bytes": 5242880, "total_chunks": 2, "received_chunks": [], "status": "pending", "error": null}}],
 "required_artifacts": ["primary"]}
```
- `ended_at` (≥ `started_at`) and `duration_ms` (0–86 400 000) are required; `artifacts` 1–3 with the same rules as
  create. `title` (≤ 200) and `client_id` are optional: when the key is present (even `null`) the value replaces
  the stored one; when absent the stored value stays.
- Idempotent: when the session already left `recording` (a repeated finish after a lost answer) the server changes
  nothing and returns `200` with the current session and slots.
- `404 client_not_found`, `409 too_many_open_uploads`, `422 validation_failed`.

### `POST /api/sessions/{id}/recordings` — `sessions:write`
Add one artifact later (body = one artifact object). `201` → one recording entry as above.
`422 validation_failed` when the role already exists.

### `GET /api/recordings/{id}` — `recordings:upload`
`200 {"id": "01JB…", "role": "primary", "upload": {"chunk_size_bytes": 5242880, "total_chunks": 2, "received_chunks": [0], "status": "uploading", "error": null}}`
- `upload.error` **(since v1.1)**: a readable reason when `status` is `failed`, otherwise `null`.
- After `complete` answers `202` with `assembling`, clients poll this endpoint (every 5 s for up to 2 minutes,
  then on their next upload cycle) and **keep their local audio until it reports `ready`**. On `failed` the server
  has discarded the parts (`received_chunks` is `[]`): the client uploads the whole file again from chunk 0 and
  completes again.

### `POST /api/recordings/{id}/chunks` — `recordings:upload`, throttled
Headers: `Content-Type: application/octet-stream`, `Upload-Chunk-Index: <0-based>`, `Upload-Chunk-Sha256: <hex of this chunk>`.
Body: raw bytes. Every chunk except the last is exactly `chunk_size_bytes`.
`200 {"received_chunks": [0, 1], "total_chunks": 2, "status": "uploading"}`. Re-sending an index with the same
checksum is a no-op `200`. Chunks are accepted again after a `failed` upload (a fresh start).
`409 upload_complete` already assembling or ready · `413 too_large` · `422 validation_failed` checksum/size/index invalid.

### `POST /api/recordings/{id}/complete` — `recordings:upload`
`202 {"status": "assembling"}` or `202 {"status": "ready"}`. The server verifies chunk set, total size and file
SHA-256, encrypts the file at rest, and starts the final transcript when every required artifact of the session is
`ready`. Small recordings are assembled within the request (`ready`); larger ones are assembled by the queue
(`assembling`): poll `GET /api/recordings/{id}`.
- `422 {"message": "Upload is incomplete.", "code": "upload_incomplete", "missing_chunks": [3, 4]}` when chunks are missing.
- `422 {"message": "The uploaded file did not match its checksum. Send it again.", "code": "verification_failed"}`
  **(since v1.1)** when the assembled file fails the size or SHA-256 check. The parts are discarded, the upload
  `status` is `failed` and `received_chunks` is `[]`; the same answer is given when `complete` is repeated before
  new chunks arrive.
- A scheduled recovery (every 5 minutes) finishes recordings left in `assembling` for more than 15 minutes when
  every part is still on disk, and marks them `failed` (upload again) otherwise **(since v1.1)**.

### `POST /api/sessions/{id}/transcripts` — `sessions:write`
Device-supplied transcripts: `kind: "live"` (captions captured during the session) or `kind: "final"` (only when the
session's final provider mode is `device`, e.g. `parakeet_local`).
```json
{"kind": "live", "provider": "assemblyai", "language": "en", "segments": [ …Segment… ]}
```
Validation: `0 ≤ start_ms ≤ end_ms ≤ duration_ms + 500`, `channel ∈ {1, 2}`, at most 20 000 segments. `201` →
Transcript. Live caption times are on the recording timeline: paused time is not counted.

### Bookmarks and pauses (since v1.1)
Both `POST /api/sessions` (with artifacts) and `POST /api/sessions/{id}/finish` accept two optional lists. Times are
recording time in ms (paused time excluded), so they line up with the transcript.
```json
{"markers": [{"at_ms": 61250, "tag": "Budget", "note": null}],
 "pauses":  [{"at_ms": 300000, "paused_ms": 125000}]}
```
- `markers`: at most 500; `at_ms` 0–86 400 000; `tag` at most 40 characters (the presets come from `config.markers.tags`,
  other text is allowed); `note` at most 200. A repeated create or finish does not add the bookmarks twice.
- `pauses`: at most 200; `paused_ms` ≥ 1. The pause happened at `at_ms` and lasted `paused_ms` (not recorded).

### `POST /api/sessions/{id}/markers` — `sessions:write` (since v1.1)
A bookmark added after the call: `{"at_ms": 61250, "tag": "Decision", "note": "Ship in May"}` →
`201 {"id","at_ms","tag","note","source","created_at"}`. `409 too_many_markers` at 500.

### `DELETE /api/sessions/{id}/markers/{marker}` — `sessions:write` (since v1.1)
`204`; `404 marker_not_found`. Adding or deleting a bookmark updates the session's `updated_at`.

---

## Library & sync

### `GET /api/library` — `library:read`
Query: `since` (ISO-8601 with any offset; items with `updated_at >= since`, **including tombstones**), `source`
(comma list of `phone,desktop,web`), `source_app`, `client_id`, `status`, `cursor`, `limit` (1–100, default 50), `tag`
(sessions with a bookmark of that tag) and `bookmarked=1` (sessions with any bookmark; both since v1.1), `view`, `tz`,
`label` and `pinned` (since v1.1, see "Organising the library").
Returns every session of the owner from **every** device, except sessions still in status `recording` (listed only
when `status=recording` is given).
```json
{"data": [
   {"id": "01JB…", "client_ref": "3f2b…", "deleted": false,
    "source_device": "desktop", "source_device_label": "Desktop", "source_device_icon": "computer-desktop",
    "source_app": "zoom", "source_app_label": "Zoom",
    "device": {"id": "01JB…", "name": "Work laptop"},
    "client": {"id": "01JB…", "name": "Acme Holdings", "color": "#8B5CF6", "avatar": "AH"},
    "title": "Zoom call", "started_at": "…", "ended_at": "…", "duration_ms": 2530000, "status": "completed",
    "transcript": {"final_status": "completed", "final_transcript_id": "01JB…", "live_available": true,
                   "live_transcript_id": "01JB…", "preview": "first 160 characters…"},
    "marker_count": 2, "tags": ["Budget"], "pinned": false, "keep": false, "labels": ["Invoice"],
    "updated_at": "…"},
   {"id": "01JC…", "client_ref": "9a1d…", "deleted": true, "updated_at": "…"}
 ],
 "next_cursor": null, "server_time": "2026-10-24T09:05:00+00:00"}
```
Sync rule **(since v1.1: inclusive boundary)**:
- `server_time` is taken when the query starts. Store it and send it as `since` next time.
- `since` is inclusive (`>=`), so an item changed in the same second as the previous sync is delivered again on
  the next one. Clients merge idempotently: upsert by `id`, last write wins on `updated_at`, delete local items
  with `deleted: true` (deleting an unknown id is a no-op).
- Adding, completing or failing a transcript (live or final) updates the session's `updated_at`.
- Tombstones are kept for `retention_days` counted from the deletion, not from the session's creation.
- `next_cursor` pages through one query: keep the first page's `server_time`. A cursor the server did not issue,
  or one with missing or null fields, is `422 invalid_cursor`. A client that sees the same `next_cursor` twice stops
  paging.
- Clients poll on open and after each capture (no websockets).

### Organising the library (since v1.1)
Client folders, labels, pins, smart views and bulk actions. Every change updates the session's `updated_at`, so the
other devices pick it up on their next sync. `tags` on a library item are bookmark tags; `labels` belong to the
recording itself.

Smart views (`view` on `GET /api/library`): `today` and `this_week` (calendar day or Monday-based week of `started_at`
in `tz`, an IANA zone, default the console's display zone), `waiting` (still `recording`: the phone or computer has
not decided Keep or Discard), `bookmarked`, `failed` (session `failed` or an upload that failed) and `pinned`.
`label` matches a recording label ignoring capitals; `pinned=1` / `pinned=0` filter by pin.

### `GET /api/library/views?tz=` — `library:read` (since v1.1)
`200 {"views": {"today": 2, "this_week": 9, "waiting": 0, "bookmarked": 4, "failed": 1, "pinned": 3}, "labels": [{"label": "Invoice", "count": 5}]}`.
Counts match what `GET /api/library?view=…` lists.

### `PATCH /api/sessions/{id}` — `sessions:write` (since v1.1)
Body, every field optional: `title` (≤ 200; empty clears it), `client_id` (a client of the owner, `null` = no
client), `pinned` (boolean), `keep` (boolean, since B17: never deleted by retention) and `labels` (the complete list: trimmed, single spaces, ≤ 40 characters, duplicates
ignoring capitals and blanks dropped, at most 20 → else `422 too_many_labels`). Returns `200 {"data": <library item>}`.
`404 session_not_found`; another owner's client is `404 client_not_found`.

### `POST /api/sessions/bulk` — `sessions:write` (since v1.1)
`{"ids": ["01JB…"], "action": "move|label|unlabel|pin|unpin|keep|unkeep|delete", "client_id": "01JB…" | null, "label": "Invoice"}`.
Up to 200 ids; ids of other owners are ignored. `move` uses `client_id` (`null` = no client), `label` / `unlabel`
need `label`, `delete` makes tombstones exactly like `DELETE /api/sessions/{id}`. `200 {"changed": 2}`.

### `POST /api/sessions/export` — `library:read` (since v1.1)
`{"ids": [...], "format": "txt|md|srt|vtt|json|docx"}` → `200 application/zip` with one file per finished final
transcript (names as in `GET /api/transcripts/{id}/export`, numbered when two match) and the header
`X-Export-Count`. Recordings without a finished transcript are skipped; when none has one, `409 nothing_to_export`.

### `POST /api/clients` — `sessions:write` (since v1.1)
`{"name": "Acme", "color": "#22d3ee", "avatar": "AC", "pinned": false}` → `201 {"data": <client>}`. `name` is
required and unique per owner (≤ 120), `color` is `#rrggbb`, `avatar` keeps one emoji or two letters (upper case).

### `PATCH /api/clients/{id}` — `sessions:write` (since v1.1)
Any of `name`, `color`, `avatar`, `pinned`, `retention_days` (1-365, `null` = the general setting) →
`200 {"data": <client>}`; `404 client_not_found`.
### `GET /api/search` — `library:read`, throttled (60/min/device) (since v1.1)
Searches titles and transcript text (final and live) of every device. Query: `q` (2–200 characters; every word
must match, `"quoted phrases"` stay together, case does not matter), and the optional filters `client_id`,
`source_app`, `source_device`, `from` / `to` (`YYYY-MM-DD`, by start date), `tag`, `bookmarked=1`, `status`, plus
`limit` (1–50, default 20) and `page` (1-based). Newest first.

```json
{"data": [{"session": {LibraryItem},
           "hits": [{"transcript_id": "01J…", "kind": "final", "segment_index": 12, "start_ms": 65000, "end_ms": 69000,
                     "speaker": "Client", "snippet": "…the invoice is due on Friday.", "highlights": [[5, 7]]}],
           "hit_count": 4}],
 "next_page": 2, "engine": "fulltext"}
```
- `hits` lists at most 3 matching segments (a title-only match has none); `hit_count` counts them all. The live
  captions are not counted again when the final transcript has the same line.
- `highlights` are `[start, length]` character ranges inside `snippet` (not HTML); `snippet` is at most 160
  characters with `…` where the text was cut. Jump to the moment with `start_ms`.
- `engine` is `fulltext` (MySQL / MariaDB FULLTEXT index) or `like` (short words, other databases, or when the index
  finds nothing).

### `GET /api/sessions/{id}` — `library:read`
Library item fields plus `"recordings": [{"id","role","mime","size_bytes","duration_ms","status"}]`,
`"transcripts": [{"id","kind","provider","status","language","error","error_code"}]` (`error`/`error_code` since
v1.1, `null` unless `failed`), `"markers"` and `"pauses"` (since v1.1, shapes as above), `"speaker_names"`
(since v1.1, see "Editing transcripts") and `"playback_url"`
(temporary signed URL, 30 min).

### `GET /api/transcripts/{id}` — `library:read`
`200 {"id","session_id","kind","provider","status","language","model","text","segments":[Segment],"error","error_code","created_at","completed_at","edited_at"}`
- `model` (since v1.1) is the model the engine reports it used (for example `universal-2`), or `null`.
- `kind` `alternate` (since v1.1) is a second engine's version made from the console for comparison; the library
  always points at the `final` one.
- `edited_at` (since v1.1) is set while the text differs from the provider's original.
- `error` / `error_code` are `null` unless `status` is `failed` (`error_code` since v1.1; values in the
  enumeration table). A transcript that is `pending`, `submitted` or `processing` has no text yet: clients show
  "Transcribing…", never "no speech".

### Editing transcripts (since v1.1)
Only `completed` transcripts can be edited (`409 transcript_not_ready` otherwise). Timings never change: an edited
segment keeps `start_ms` / `end_ms` and loses its `words` (their timings no longer match). The first edit keeps the
provider's text as the `original` revision; every change adds a revision (the newest 50 are kept besides the
original). Each change bumps the session's `updated_at`, so other devices sync it.

Speaker names are per session, keyed by the speaker label used in the segments (`"Me"`, `"Client"`,
`"Speaker B"`…). Clients show `speaker_names[segment.speaker] ?? segment.speaker`. A session of a client starts with
the names remembered for that client; the session's own names win.

### `PATCH /api/transcripts/{id}` — `sessions:write` (since v1.1)
`{"edits": [{"index": 0, "text": "Corrected text"}]}` (1–2000 edits, text 1–5000 characters after trimming;
whitespace runs collapse) → `200` transcript. `422 segment_not_found` for an index that does not exist,
`422 segment_empty` for an empty text. Unchanged text saves nothing.

### `POST /api/transcripts/{id}/replace` — `sessions:write` (since v1.1)
`{"find": "acme", "replace": "Acme Ltd", "match_case": false, "whole_word": false}` → `200 {"replaced": 3,
"transcript": {…}}`. The search is literal (no patterns) and Unicode-aware; `replaced: 0` saves nothing.

### `POST /api/transcripts/{id}/restore` — `sessions:write` (since v1.1)
Puts the provider's original text back (`edited_at` becomes `null`) and records a `restore` revision. A transcript
that was never edited is returned unchanged.

### `GET /api/transcripts/{id}/revisions` — `library:read` (since v1.1)
`200 {"data": [{"id","source","author","created_at"}]}`, newest first; `source` is `original`, `edit`, `replace` or
`restore`; `author` is the device or console user that made the change.

### `PUT /api/sessions/{id}/speakers` — `sessions:write` (since v1.1)
`{"names": {"Client": "Jane (Acme)", "Me": "Sam"}, "remember_for_client": true}` (1–20 names, ≤ 60 characters;
an empty name removes the rename) → `200 {"speaker_names": {…}}` (the effective names). With
`remember_for_client` the names also become the defaults for the session's client.

### `GET /api/transcripts/{id}/export` — `library:read` (since v1.1)
`format` = `txt` | `md` | `srt` | `vtt` | `json` | `docx` | `html`, `timestamps` (default `1`) and `speakers`
(default `1`). The answer is the file (`Content-Disposition: attachment`, named after the title and date) with
the session's speaker names applied. `md` and `json` include the bookmarks; `docx` marks Urdu and other
right-to-left lines as right-to-left; `html` is a print-ready page: the apps and browsers save it as PDF, which keeps
Urdu and Hindi shaping right. `409 transcript_not_ready` until the transcript is `completed`.

### Share links (since v1.1)
A share link opens a view-only page at `/s/{token}` with the transcript (speaker names applied) and, when asked
for, a player for the recording. The token (256 random bits) is shown once, when the link is made; the server keeps
only its SHA-256. Links expire after 1 hour, 1 day or 7 days, may need a password, can be revoked at any time, and
stop working when the recording is deleted. Every access is logged (`viewed`, `wrong_password`, `audio`) with a keyed
hash of the IP address. The page is not indexed (`X-Robots-Tag: noindex`) and sends no referrer. Unknown, expired
and revoked links all answer `404` with the same page; password attempts are limited to 10 per minute per address.

### `POST /api/sessions/{id}/shares` — `sessions:write` (since v1.1)
`{"expires_in": 86400, "password": "optional, 6-100 characters", "include_audio": false}` (`expires_in` is 3600,
86400 or 604800) → `201 {ShareLink, "url": "https://…/s/…"}`. At most 20 active links per recording
(`409 too_many_shares`).

ShareLink: `{"id","session_id","has_password","include_audio","expires_at","revoked_at","active","view_count",
"last_viewed_at","created_by","created_at"}`.

### `GET /api/sessions/{id}/shares` — `library:read` (since v1.1)
`200 {"data": [ShareLink]}`, newest first. The URL is not shown again.

### `DELETE /api/shares/{id}` — `sessions:write` (since v1.1)
`204`. The link stops working at once (`404 share_not_found` for a link of another owner).

### Languages (since v1.1)
A session's `language` (create, open), a client's language and the console default accept `null` (automatic),
one ISO 639-1 code (`en`, `ur`, `hi`, …) or two codes joined by `+` for calls that switch languages (`en+ur`).
The session's choice wins, then the client's, then the default. AssemblyAI transcribes `en+ur` with Universal-2
code switching (two codes, one of them English), one code with that language and nothing with automatic detection;
the other engines get the single code or detect the language themselves. Live captions always detect the language.

**Since v1.1.2.** The offered codes are `en`, `ur`, `en+ur`, `hi`, `en+hi`; mixed codes are stored English first
(`ur+en` becomes `en+ur`) and anything else is automatic. New fields:
- `POST /api/stt/token` takes an optional `language` (the recording's chip); without it the session's, the
  client's or the default language is used, and live captions are started in it.
- `POST /api/sessions/{id}/finish` takes `language` (string up to 16 characters, or `null`/`""` for automatic). When
  the key is present it replaces the session's language, so the Keep card's chip decides the final transcript.
- `PATCH /api/sessions/{id}` and `PATCH /api/clients/{id}` accept `language`; `GET /api/clients` and the config's
  clients carry each client's `language`.
- `GET /api/config` has `languages: {default, options: [{value, label, chip}]}`.
- Library items have `language` and `language_chip` ("UR+EN"); their `transcript` has `final_language`,
  `final_provider`, `final_provider_label`, `final_model`, `live_language` and `script_warning` (`devanagari`,
  `latin`, `arabic` or `null`). Transcripts have `language_label`, `provider_label`, `script_warning` and `fallback`
  (`{used, wanted, language, fallback}` when another engine than the first one ran).

**Since v1.2.** The offered codes are `en`, `ur`, `ar` and the "More languages" `fr`, `de`, `es`, `it`, `pt`, `nl`,
`tr`, `ru`. Urdu and Arabic always allow English words, so the mixed codes are gone: a stored or sent `en+ur`,
`ur+en`, `hi` or `en+hi` reads as `ur` and `en+ar` as `ar` (stored values were migrated). Automatic detection picks
only `en`, `ur` or `ar` (plus the More languages ticked in the console); a final transcript in another language is
run once more in the console's fallback language (Urdu by default), and one written in Hindi letters (Devanagari) is
run once more as Urdu. New fields:
- `GET /api/config`: each `languages.options` item has `more` (true for the More languages) and `languages.auto`
  lists the codes automatic detection may pick.
- `POST /api/sessions` and `POST /api/sessions/{id}/finish` take `language_marks`: the language chip changed during
  the recording, `[{"at_ms": 0, "language": "en"}, {"at_ms": 61250, "language": "ur"}]` (at most 50, in time
  order; `language` null is automatic). The final transcript is cut there and each piece is transcribed in its
  language (Whisper on Groq); the marks win over detection. `GET /api/sessions/{id}` returns `language_marks`.
- Caller names: `POST /api/sessions` and `POST /api/sessions/{id}/finish` take `caller: {"name", "number", "source"}`
  (all optional; name up to 120 characters, number up to 40, `source` one of `whatsapp_notification`, `window_title`,
  `calendar`, `contacts`, `phone`, `manual`). The server matches it to a client contact (phone number by its last 10
  digits, then the contact's or the client's name): a match files the recording under that client when the device
  chose none, and gives it the contact's language when it has none. The title becomes "Call with <name>" (a
  `window_title` or `calendar` name is used as it is) unless a title was set. Library items and `GET /api/sessions/{id}`
  carry `caller` (or `null`). Moving a recording into a client folder (`PATCH /api/sessions/{id}`) saves its caller as
  a contact of that client. Contacts are kept in the console (Clients → Contacts).
- Transcript segments: `uncertain: true` when the engine was not sure of the whole line (Whisper's average
  log-probability below -0.8, Azure's phrase confidence below 0.5), and `words[].confidence` (0-1; AssemblyAI,
  ElevenLabs, Soniox) below 0.5 marks a word uncertain. The apps and the console underline both with dots and a tap
  plays from there; the text stays as heard. With Double-check on (console setting, off by default) a line a second
  engine heard differently carries `check: {"provider": "groq", "label": "Groq Whisper large-v3", "text": "…"}`,
  shown under the line and never merged; editing the line removes `uncertain` and `check`.
- Library items: `transcript.final_error_code` (since v1.1.2; `no_audio` when nothing was heard),
  `alternate_transcript_id`, `alternate_status` and `alternate_language` (the other version: the previous
  transcript after "Re-transcribe as", or a second engine's run), and `final_progress`.
- Transcripts have `progress: {"done": 2, "total": 5}` while a long recording is transcribed in parts (Whisper on
  Groq), `null` otherwise; library items carry the same as `transcript.final_progress`.

### `POST /api/sessions/{id}/retranscribe` — `sessions:write` (since v1.2)
"Re-transcribe as": `{"language": "ur"}` (`null` is automatic; the key is required) →
`202 {"transcript": {"id","kind","provider","status","language"}}`. The session's language becomes the new one and the
engine is chosen by the routing for that language. A finished transcript stays the transcript until the new run is
ready (`kind` `alternate`); then the new one becomes the `final` transcript and the old one is kept as the
`alternate`. Two versions are kept, the newest and the one before it; a new request replaces a run that is still
going. A failed or unfinished transcript is simply run again in the new language (`kind` `final`).
`409 cannot_retranscribe` when the recording's audio is not on the server (not uploaded yet, or erased).

### `GET /api/transcripts/{id}/views` — `library:read` (since v1.2)
"Show as": reading views of a finished transcript, made with the Claude API (text only, no audio) and kept with
the transcript: `roman` (Roman Urdu), `english` and `urdu` (translations).
`200 {"status","available","roman","english","urdu","default","model","made_at","error"}`: `status` is `unavailable`
(not finished), `none`, `making`, `ready` or `failed` (with `error`); `available` lists the views this transcript can
have (`roman` and `english` for Urdu, `roman` and `urdu` for English, `english` for Arabic and the other languages);
when ready, each available view is a list aligned with the segments (`null` for an empty line); `default` is the view
the apps open it in (console setting, `original` unless that view is available). An edit to the transcript makes the
views stale (`none`) until they are made again. Every transcript carries `views: {"available", "status", "default"}`.

### `POST /api/transcripts/{id}/views` — `sessions:write` (since v1.2)
Starts making the views on the queue: `202` with the state (`making`), or `200` when they are ready.
`409 views_unavailable` when the transcript is not finished or no Anthropic key is set. `GET /api/transcripts/{id}/export`
takes `view=roman|english|urdu` to export a ready view instead of the original words, and `POST /api/sessions/{id}/shares`
takes `view` (share links have `view`): the page opens in that view once it is made, with a label, and in the original
words until then.

### Vocabulary (since v1.1)
Keyterms (names and jargon) of the session's client come first, then the global ones; each engine receives as many
as it accepts. Text replacements ("always write X as Y") run on every new final transcript, the client's rules
first; the provider's text stays restorable (`POST /api/transcripts/{id}/restore`).

### `GET /api/vocabulary?client_id=` — `library:read` (since v1.1)
`200 {"keyterms": [...], "replacements": [{"from","to","match_case","whole_word"}], "client": {"id","keyterms","replacements"} | null}`.

### `POST /api/vocabulary/keyterms` — `sessions:write` (since v1.1)
`{"term": "Farah Khan", "client_id": "optional"}` → `201 {"keyterms": [...]}` (the list it was added to; a
duplicate is not added twice). Terms are at most 50 characters and 5 words; 200 per list (`409 vocabulary_full`).

### `POST /api/vocabulary/replacements` — `sessions:write` (since v1.1)
`{"from": "acme limited", "to": "Acme Ltd", "match_case": false, "whole_word": true, "client_id": "optional"}` →
`201 {"replacements": [...]}`. The newest rule comes first; at most 200 rules of 100 characters each.

### Retention and the undo bin (since v1.1)
Recordings older than their retention (the client's `retention_days`, else the general setting: 7, 14, 30 or a custom
1-365 days) move to the undo bin every hour, unless `keep` is set or they are still recording. A delete, by a device,
the console or retention, makes a tombstone at once (devices sync it as before) and erases the provider's copy; the
encrypted audio and the transcripts stay **48 hours** in the bin, unplayable and unshared, then are erased. A
recording deleted while still recording or uploading is erased at once.

### `GET /api/bin` — `library:read` (since v1.1)
`200 {"data": [{"id": "01JB…", "title": "Zoom call", "client": null, "started_at": "…", "duration_ms": 2530000,
"deleted_at": "…", "erase_at": "…"}]}`: the owner's binned recordings, most recently deleted first.

### `POST /api/sessions/{id}/restore` — `sessions:write` (since v1.1)
Takes a recording out of the bin: `200 {"data": <library item>}` with a new `updated_at`, so every device brings it
back on its next sync. `410 restore_expired` after the 48 hours; `404 session_not_found` for anything else.

### `DELETE /api/bin/{id}` — `sessions:write` (since v1.1)
Erases a binned recording now instead of after 48 hours. `204`.

### `GET /api/storage` — `library:read` (since v1.1)
`200 {"recordings_bytes": 51234567, "recordings_count": 42, "disk_free_bytes": 80123456789, "disk_total_bytes": 107374182400, "retention_days": 30}`.
The disk fields are `null` when the server cannot read them.
### `DELETE /api/sessions/{id}` — `sessions:write`
`204`. The session becomes a tombstone at once; its recordings (files) and transcripts wait 48 hours in the undo bin
(since v1.1, see "Retention and the undo bin"), unplayable and unshared, then are erased. A provider
job still running for it is cancelled and its provider-side copy deleted, retried by `stt:reconcile` until the
provider accepts **(since v1.1)**. Any device of the owner may delete. A repeated delete is
`404 session_not_found`: clients treat it as done.

### `GET /api/recordings/{id}/audio?expires=…&signature=…` — temporary signed URL (no bearer)
Decrypted audio, `Content-Type` = stored MIME, HTTP Range supported, `Cache-Control: private, no-store`.
`206` with `Content-Range: bytes <start>-<end>/<size>` for a satisfiable range; a range that starts at or past the
end of the file is `416` with `Content-Range: bytes */<size>` **(since v1.1)**. The server decrypts to a private
temporary file per request (never the whole recording in memory).

---

## Security centre (since v1.1)

The console's Security page lists signed-in browsers, paired devices and the audit log. Device apps read the same
devices and the latest 50 audit entries, and can revoke another device of the owner. Audit entries are kept for 365
days and never contain passwords, keys or secrets; `actor` is `web` (console), `device` or `system`.

Audited events: `login`, `passkey_login`, `login_failed`, `logout`, `web_session_revoked`, `session_deleted`,
`transcript_exported`, `sessions_exported`, `share_created`, `share_revoked`, `device_paired`, `device_revoked`,
`device_token_rotated`, `password_changed`, `mfa_enabled`, `mfa_disabled`, `passkey_added`, `passkey_removed`,
`credentials_changed`.

### `GET /api/security` — `config:read` (since v1.1)
```json
{"devices": [{"id": "01JB…", "name": "Pixel 9", "platform": "android", "app_version": "1.1.0",
              "last_seen_at": "…", "paired_at": "…", "current": true}],
 "events": [{"id": 812, "event": "session_deleted", "label": "Recording deleted",
             "summary": "Recording deleted: Acme kickoff", "actor": "device", "device": "Work laptop", "at": "…"}]}
```
`device` names the device that did it (`"A removed device"` once it is revoked), `null` for the console and the
system.

### `DELETE /api/devices/{id}` — `sessions:write` (since v1.1)
Revokes another paired device of the owner: its tokens stop working at once; its recordings stay. `204`;
`404 device_not_found`; `409 current_device` for the device making the request (it unpairs itself in its settings).

---

## Activity centre (since v1.1)

The console bell and the device apps share one list of notifications: `transcript_ready`, `transcript_failed`,
`upload_failed` (a recording did not finish uploading; its device can send it again), `device_paired`, `budget_warn`
(the monthly limit's warning threshold) and `budget_reached`, each budget notice once per month. Recordings in the
undo bin make none. Read notifications are kept 90 days, unread ones 180. Device apps poll and show new ones as OS
notifications that open `session_id`, outside the quiet hours the person chose on that device.

### `GET /api/activity?limit=` — `library:read` (since v1.1)
`limit` 1-100 (default 30), newest first:
```json
{"data": [{"id": "9b1c…", "kind": "transcript_ready", "title": "Transcript ready", "body": "Zoom call",
           "session_id": "01JB…", "read": false, "at": "…"}],
 "unread_count": 3}
```

### `POST /api/activity/read` — `library:read` (since v1.1)
`{"ids": ["9b1c…"]}` marks those as read; without `ids`, everything. `200 {"unread_count": 0}`.

---

## Usage and monthly limit (since v1.1)

The console (Settings → Monthly limit) sets a limit in minutes and/or US dollars and a price table. Usage is kept in
the usage ledger:

- **Streaming** (`live_captions`, `dictation`): one row per `POST /api/usage` report, as above.
- **Final transcripts** (`final_transcript`, `voice_note`): one row written by the server when a provider job
  completes (failed jobs are free and write nothing). Audio duration = the provider's reported duration
  (AssemblyAI `audio_duration`), otherwise the primary recording's `duration_ms`. Billed channels: AssemblyAI the
  reported `audio_channels` (else the recording's channels); ElevenLabs the recording's channels (each channel is
  billed at full duration); Azure MAI-Transcribe-2 two when per-channel files were sent, else one; Azure standard
  one (one multiplexed file). whisper.cpp and Parakeet rows have cost 0 and are not billable.
- `cost_usd = billed_ms / 3 600 000 × price` with the price (USD per audio hour per channel) of
  `(provider, use case)` at the time the row is written. Defaults (keyterm add-ons included, so the estimate errs
  high): AssemblyAI live captions 0.45, dictation 0.45, final transcript 0.26, voice note 0.26; ElevenLabs 0.47,
  0.47, 0.27, 0.27; Azure MAI final transcript 0.10, voice note 0.10 (launch price until 2026-12-31; the standard
  model is 0.36).
- The month is the calendar month in the console's display time zone. The limit applies to the whole server.
- When the limit is reached: `stt/token` → `403 budget_exceeded`; a final transcript that has not been submitted
  yet is not submitted and fails with `error_code` `budget_exceeded` (it can be retried from the console after the
  limit is raised); sockets already open are not cut. Devices read `usage` from `GET /api/config` (and from the
  `POST /api/usage` answer) to show the limit on every screen.

---

## Provider webhooks (server-to-server; not for device apps)
`POST /api/webhooks/stt/{provider}` with `provider` ∈ `assemblyai` | `elevenlabs`. Public, throttled,
signature-verified (AssemblyAI: per-recording HMAC in `X-EchoLedger-Webhook-Token`; ElevenLabs:
`elevenlabs-signature` `t=…,v0=…`), idempotent via `webhook_events` (the ElevenLabs de-duplication key is derived
from the signed payload, never from an unsigned header). `401` bad signature or malformed recording id; `5xx` only
for transient faults.
