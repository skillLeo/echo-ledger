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
| 403 | `budget_exceeded` | Monthly transcription limit reached (message `Monthly transcription limit reached`) | Keep recording without captions; show the message; do not retry until `usage.limit_reached` is false |
| 404 | `not_found` | Unknown route | Permanent |
| 404 | `session_not_found` | Session unknown, deleted, or (finish, stt/token) not created by this device | finish: create a new session with a new `client_ref`; delete: treat as done |
| 404 | `recording_not_found` | Recording unknown or its session was deleted | Drop the upload slot |
| 404 | `transcript_not_found` | Transcript unknown or its session was deleted | Refresh the library |
| 404 | `client_not_found` | `client_id` unknown or owned by someone else | Clear the selected client and retry once without it |
| 405 | `method_not_allowed` | Wrong HTTP method | Permanent |
| 409 | `upload_complete` | Chunk sent after the recording started assembling | Stop sending chunks; poll `GET /api/recordings/{id}` |
| 409 | `session_not_recording` | `stt/token`: the session is not in status `recording`, or is 4 hours old or older | Captions are unavailable for this recording; keep recording |
| 409 | `too_many_open_uploads` | 50 or more unfinished recordings exist for this device | Finish pending uploads, then retry |
| 409 | `too_many_open_sessions` | 10 or more sessions of this device are still `recording` | Finish or discard them, then retry |
| 413 | `too_large` | Body or declared size above the limit | Permanent |
| 422 | `validation_failed` | Field errors in `errors` | Permanent for that request |
| 422 | `session_required` | `stt/token` for `live_captions` without `session_id` | Open a session first (v1.1 clients); v1 clients lose captions only |
| 422 | `upload_incomplete` | `complete` while chunks are missing (`missing_chunks`) | Send the missing chunks, then call `complete` again |
| 422 | `verification_failed` | `complete`: the assembled file failed the size/SHA-256 check; the parts were discarded | Upload the whole file again from chunk 0 |
| 422 | `invalid_cursor` | `library` cursor is malformed | Restart paging without a cursor |
| 429 | `rate_limited` | Throttled; `Retry-After` is always set | Wait `Retry-After` seconds |
| 502 | `provider_error` | `stt/token`: the provider refused or could not be reached | Retry later |
| 503 | `provider_not_configured` | `stt/token`: provider key or region missing | Show the message; retry later |

## Enumerations
| Field | Values |
|---|---|
| `platform` | `android`, `ios`, `windows`, `macos`, `linux` |
| `source_device` (derived by server: android/ios→`phone`, windows/macos/linux→`desktop`, admin upload→`web`) | `phone` "Phone" icon `device-phone-mobile` · `desktop` "Desktop" icon `computer-desktop` · `web` "Web" icon `globe-alt` |
| `source_app` | `whatsapp` "WhatsApp" · `whatsapp_business` "WhatsApp Business" · `whatsapp_web` "WhatsApp Web" (since v1.1) · `google_meet` "Google Meet" · `zoom` "Zoom" · `microsoft_teams` "Microsoft Teams" (since v1.1) · `voice_note` "Voice note" · `other` "Other" |
| session `status` | `recording`, `uploading`, `processing`, `completed`, `failed` |
| recording `role` | `primary` (stereo or mono file used for playback + multichannel providers), `channel_1` (mono "Me"), `channel_2` (mono "Client") |
| upload `status` | `pending`, `uploading`, `assembling`, `ready`, `failed` |
| transcript `kind` / `status` | `live`, `final` / `pending`, `submitted`, `processing`, `awaiting_device`, `completed`, `failed` |
| provider | `assemblyai`, `elevenlabs`, `azure_mai`, `whispercpp_local`, `parakeet_local` |
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
  - `limit_minutes` / `limit_usd`: the monthly limits set in the console, `null` when not set.
  - `warn`: `true` once usage reaches `warn_at_percent` (80) of any set limit. `limit_reached`: `true` once usage
    reaches 100 % of any set limit. While `limit_reached` is true, `stt/token` answers `403 budget_exceeded` and
    new final transcripts fail with `error_code` `budget_exceeded`. Both are `false` when no limit is set.

### `GET /api/clients` — `library:read`
`200 {"data": [{"id": "01JB…", "name": "Acme Holdings", "color": "#8B5CF6", "keyterm_count": 12, "updated_at": "…"}]}`

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

---

## Library & sync

### `GET /api/library` — `library:read`
Query: `since` (ISO-8601 with any offset; items with `updated_at >= since`, **including tombstones**), `source`
(comma list of `phone,desktop,web`), `source_app`, `client_id`, `status`, `cursor`, `limit` (1–100, default 50).
Returns every session of the owner from **every** device, except sessions still in status `recording` (listed only
when `status=recording` is given).
```json
{"data": [
   {"id": "01JB…", "client_ref": "3f2b…", "deleted": false,
    "source_device": "desktop", "source_device_label": "Desktop", "source_device_icon": "computer-desktop",
    "source_app": "zoom", "source_app_label": "Zoom",
    "device": {"id": "01JB…", "name": "Work laptop"},
    "client": {"id": "01JB…", "name": "Acme Holdings"},
    "title": "Zoom call", "started_at": "…", "ended_at": "…", "duration_ms": 2530000, "status": "completed",
    "transcript": {"final_status": "completed", "final_transcript_id": "01JB…", "live_available": true,
                   "live_transcript_id": "01JB…", "preview": "first 160 characters…"},
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

### `GET /api/sessions/{id}` — `library:read`
Library item fields plus `"recordings": [{"id","role","mime","size_bytes","duration_ms","status"}]`,
`"transcripts": [{"id","kind","provider","status","language","error","error_code"}]` (`error`/`error_code` since
v1.1, `null` unless `failed`) and `"playback_url"` (temporary signed URL, 30 min).

### `GET /api/transcripts/{id}` — `library:read`
`200 {"id","session_id","kind","provider","status","language","text","segments":[Segment],"error","error_code","created_at","completed_at"}`
- `error` / `error_code` are `null` unless `status` is `failed` (`error_code` since v1.1; values in the
  enumeration table). A transcript that is `pending`, `submitted` or `processing` has no text yet: clients show
  "Transcribing…", never "no speech".

### `DELETE /api/sessions/{id}` — `sessions:write`
`204`. The session becomes a tombstone; its recordings (files) and transcripts are erased immediately. A provider
job still running for it is cancelled and its provider-side copy deleted, retried by `stt:reconcile` until the
provider accepts **(since v1.1)**. Any device of the owner may delete. A repeated delete is
`404 session_not_found`: clients treat it as done.

### `GET /api/recordings/{id}/audio?expires=…&signature=…` — temporary signed URL (no bearer)
Decrypted audio, `Content-Type` = stored MIME, HTTP Range supported, `Cache-Control: private, no-store`.
`206` with `Content-Range: bytes <start>-<end>/<size>` for a satisfiable range; a range that starts at or past the
end of the file is `416` with `Content-Range: bytes */<size>` **(since v1.1)**. The server decrypts to a private
temporary file per request (never the whole recording in memory).

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
