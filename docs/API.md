# EchoLedger REST API (v1) — authoritative contract

Server: `web/` (Laravel). Clients: `desktop/` (Tauri, all HTTP in Rust) and `mobile/` (Kotlin, OkHttp).
If implementation and this file disagree, fix one of them in the same change — never leave drift.

## Conventions
- Base URL: `{APP_URL}/api`. HTTPS only in production. JSON (`Accept: application/json`) except chunk bodies and audio.
- Auth: `Authorization: Bearer <device token>` (Sanctum token owned by a `Device`). Abilities on every device
  token: `config:read`, `stt:token`, `sessions:write`, `recordings:upload`, `library:read`.
- IDs are ULIDs (26 chars). Timestamps are ISO-8601 UTC (`2026-10-24T09:00:00Z`). Durations in ms.
- Errors use Laravel's shape: `{"message": "...", "errors": {"field": ["..."]}}`.
  `401` token missing/expired/revoked (client must re-pair) · `403` missing ability · `404` · `409` conflict ·
  `413` body too large · `422` validation · `429` rate limited (honour `Retry-After`).
- Device apps pin the server's SPKI SHA-256 set (`pins`), current + backup. Provider WebSockets are NOT pinned.
- Provider API keys never appear in any response. Streaming grants are short-lived tokens only.

## Enumerations
| Field | Values |
|---|---|
| `platform` | `android`, `ios`, `windows`, `macos`, `linux` |
| `source_device` (derived by server: android/ios→`phone`, windows/macos/linux→`desktop`, admin upload→`web`) | `phone` "Phone" icon `device-phone-mobile` · `desktop` "Desktop" icon `computer-desktop` · `web` "Web" icon `globe-alt` |
| `source_app` | `whatsapp` "WhatsApp" · `whatsapp_business` "WhatsApp Business" · `google_meet` "Google Meet" · `zoom` "Zoom" · `voice_note` "Voice note" · `other` "Other" |
| session `status` | `recording`, `uploading`, `processing`, `completed`, `failed` |
| recording `role` | `primary` (stereo or mono file used for playback + multichannel providers), `channel_1` (mono "Me"), `channel_2` (mono "Client") |
| upload `status` | `pending`, `uploading`, `assembling`, `ready`, `failed` |
| transcript `kind` / `status` | `live`, `final` / `pending`, `submitted`, `processing`, `awaiting_device`, `completed`, `failed` |
| provider | `assemblyai`, `elevenlabs`, `azure_mai`, `whispercpp_local`, `parakeet_local` |
| use case | `live_captions`, `dictation`, `final_transcript`, `voice_note` |

**Segment** (shared transcript shape; channel 1 = "Me" mic/left, channel 2 = "Client" system/right; mono = channel 1 "Speaker"):
```json
{"channel": 1, "speaker": "Me", "start_ms": 250, "end_ms": 2600, "text": "Smoke from the north.",
 "words": [{"text": "Smoke", "start_ms": 250, "end_ms": 650, "confidence": 0.97}]}
```

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
 "token": "<plain-text token, returned once>", "token_expires_at": "2027-10-24T00:00:00Z",
 "config": { …same body as GET /api/config… }}
```
`422` invalid, expired or used code.

### `POST /api/devices/rotate-token` — auth
`200 {"token": "…", "token_expires_at": "…"}`; the old token is revoked immediately.

### `GET /api/config` — `config:read`
```json
{"device": {"id": "01JB…", "name": "S25 Ultra", "platform": "android", "source_device": "phone"},
 "server_time": "2026-10-24T09:00:00Z",
 "retention_days": 30,
 "upload": {"chunk_size_bytes": 5242880, "max_recording_bytes": 2147483648,
            "accepted_mimes": ["audio/ogg", "audio/opus", "audio/flac", "audio/wav", "audio/x-wav", "audio/mpeg",
                               "audio/mp4", "audio/aac", "audio/webm", "audio/amr"]},
 "providers": {
   "live_captions":    {"provider": "assemblyai", "mode": "cloud"},
   "dictation":        {"provider": "assemblyai", "mode": "cloud"},
   "final_transcript": {"provider": "assemblyai", "mode": "async_webhook", "required_artifacts": ["primary"], "preferred_primary": "ogg_opus"},
   "voice_note":       {"provider": "assemblyai", "mode": "async_webhook", "required_artifacts": ["primary"]}
 },
 "captions": {"channels": "both"},
 "pins": ["sha256/AAAA…=", "sha256/BBBB…="]}
```
- Live `mode`: `cloud` | `on_device`. Final `mode`: `async_webhook` | `sync` | `device`.
- `required_artifacts` is `["primary","channel_1","channel_2"]` when the final provider needs per-channel mono
  16 kHz WAV/FLAC (`azure_mai` with MAI model, `whispercpp_local`). `preferred_primary`: `ogg_opus` | `flac` | `wav`.
- `captions.channels`: `both` | `client_only` | `off`.

### `GET /api/clients` — `library:read`
`200 {"data": [{"id": "01JB…", "name": "Acme Holdings", "color": "#8B5CF6", "keyterm_count": 12, "updated_at": "…"}]}`

---

## Live captions & dictation

### `POST /api/stt/token` — `stt:token`, throttled (30/min/device)
```json
// request
{"use_case": "live_captions", "client_id": "01JB…", "channels": ["me", "client"], "sample_rate": 16000, "language": null}
```
`use_case` ∈ `live_captions` | `dictation` (dictation is always `["me"]`). `client_id` selects that client's keyterms.
```json
// 200 — AssemblyAI (protocol assemblyai_v3)
{"provider": "assemblyai", "mode": "cloud", "expires_at": "2026-10-24T09:01:00Z",
 "keyterms": ["Acme Holdings", "Farah Khan"],
 "grants": [
   {"channel": "me", "transport": "websocket", "protocol": "assemblyai_v3",
    "ws_url": "wss://streaming.assemblyai.com/v3/ws?speech_model=universal-3-6-pro&encoding=pcm_s16le&sample_rate=16000&inactivity_timeout=120&token=…",
    "redeem_by": "2026-10-24T09:01:00Z", "max_session_seconds": 10800,
    "audio": {"encoding": "pcm_s16le", "sample_rate": 16000, "channels": 1, "frame_ms": 50},
    "init_messages": [{"type": "UpdateConfiguration", "keyterms_prompt": ["Acme Holdings", "Farah Khan"]}]},
   {"channel": "client", "…": "same shape"}
 ]}
```
- `elevenlabs` → `protocol: "elevenlabs_realtime"`, one single-use token per grant baked into `ws_url`
  (keyterms included as repeated query params), `frame_ms: 100`, `init_messages: []`.
- `azure_mai` → `transport: "azure_speech_sdk"`, grant fields `token`, `region`, `expires_in` (600), `refresh_after` (540), `phrases`.
- `whispercpp_local` / `parakeet_local` → `{"provider": "…", "mode": "on_device", "grants": []}`.
- Wire protocols (message names, framing, termination, rollover) are specified in
  `docs/research/stt-assemblyai.md` (R3) and `docs/research/stt-elevenlabs.md` (R8). Clients send each
  `init_messages` entry as a JSON text frame right after the provider's session-start message, before audio.

---

## Sessions, recordings, uploads

### `POST /api/sessions` — `sessions:write`
Idempotent on `(device, client_ref)`: repeating the call returns `200` with the existing session.
```json
// request
{"client_ref": "3f2b6c1e-8d4a-4d3e-9a51-2c7f0e9b1a22", "source_app": "whatsapp", "client_id": null, "title": null,
 "started_at": "2026-10-24T09:00:00Z", "ended_at": "2026-10-24T09:42:10Z", "duration_ms": 2530000, "language": null,
 "artifacts": [
   {"role": "primary", "mime": "audio/ogg", "codec": "opus", "channels": 2, "sample_rate": 48000,
    "size_bytes": 10485760, "sha256": "<hex>", "duration_ms": 2530000}
 ]}
// 201
{"session": { …Session… },
 "recordings": [{"id": "01JB…", "role": "primary",
                 "upload": {"chunk_size_bytes": 5242880, "total_chunks": 2, "received_chunks": [], "status": "pending"}}],
 "required_artifacts": ["primary"]}
```
`source_device` is derived from the calling device (never sent by clients).

### `POST /api/sessions/{id}/recordings` — `sessions:write`
Add one artifact later (body = one artifact object). `201` → one recording entry as above.

### `GET /api/recordings/{id}` — `recordings:upload`
`200 {"id": "01JB…", "role": "primary", "upload": {"chunk_size_bytes": 5242880, "total_chunks": 2, "received_chunks": [0], "status": "uploading"}}`

### `POST /api/recordings/{id}/chunks` — `recordings:upload`, throttled
Headers: `Content-Type: application/octet-stream`, `Upload-Chunk-Index: <0-based>`, `Upload-Chunk-Sha256: <hex of this chunk>`.
Body: raw bytes. Every chunk except the last is exactly `chunk_size_bytes`.
`200 {"received_chunks": [0, 1], "total_chunks": 2, "status": "uploading"}`. Re-sending an index with the same
checksum is a no-op `200`. `409` already complete · `413` too large · `422` checksum/size/index invalid.

### `POST /api/recordings/{id}/complete` — `recordings:upload`
`202 {"status": "assembling"}`. The server verifies chunk set, total size and file SHA-256, encrypts the file at rest,
and starts the final transcript when every required artifact of the session is `ready`.
`422 {"message": "…", "missing_chunks": [3, 4]}` when incomplete.

### `POST /api/sessions/{id}/transcripts` — `sessions:write`
Device-supplied transcripts: `kind: "live"` (captions captured during the session) or `kind: "final"` (only when the
session's final provider mode is `device`, e.g. `parakeet_local`).
```json
{"kind": "live", "provider": "assemblyai", "language": "en", "segments": [ …Segment… ]}
```
Validation: `0 ≤ start_ms ≤ end_ms ≤ duration_ms + 500`, `channel ∈ {1, 2}`. `201` → Transcript.

---

## Library & sync

### `GET /api/library` — `library:read`
Query: `since` (ISO-8601; items with `updated_at > since`, **including tombstones**), `source` (comma list of
`phone,desktop,web`), `source_app`, `client_id`, `status`, `cursor`, `limit` (1–100, default 50).
Returns every session of the owner from **every** device.
```json
{"data": [
   {"id": "01JB…", "client_ref": "3f2b…", "deleted": false,
    "source_device": "desktop", "source_device_label": "Desktop", "source_device_icon": "computer-desktop",
    "source_app": "zoom", "source_app_label": "Zoom",
    "device": {"id": "01JB…", "name": "Work laptop"},
    "client": {"id": "01JB…", "name": "Acme Holdings"},
    "title": "Zoom call", "started_at": "…", "ended_at": "…", "duration_ms": 2530000, "status": "completed",
    "transcript": {"final_status": "completed", "final_transcript_id": "01JB…", "live_available": true,
                   "preview": "first 160 characters…"},
    "updated_at": "…"},
   {"id": "01JC…", "client_ref": "9a1d…", "deleted": true, "updated_at": "…"}
 ],
 "next_cursor": null, "server_time": "2026-10-24T09:05:00Z"}
```
Sync rule: store `server_time`, send it as `since` next time; delete local items with `deleted: true`;
last-write-wins on `updated_at`. Clients poll on open and after each capture (no websockets).

### `GET /api/sessions/{id}` — `library:read`
Library item fields plus `"recordings": [{"id","role","mime","size_bytes","duration_ms","status"}]`,
`"transcripts": [{"id","kind","provider","status","language"}]` and `"playback_url"` (temporary signed URL, 30 min).

### `GET /api/transcripts/{id}` — `library:read`
`200 {"id","session_id","kind","provider","status","language","text","segments":[Segment],"created_at","completed_at"}`

### `DELETE /api/sessions/{id}` — `sessions:write`
`204`. The session becomes a tombstone; its recordings (files) and transcripts are erased immediately.

### `GET /api/recordings/{id}/audio?expires=…&signature=…` — temporary signed URL (no bearer)
Decrypted audio, `Content-Type` = stored MIME, HTTP Range supported (`206`/`416`), `Cache-Control: private, no-store`.

---

## Provider webhooks (server-to-server; not for device apps)
`POST /api/webhooks/stt/{provider}` with `provider` ∈ `assemblyai` | `elevenlabs`. Public, throttled,
signature-verified (AssemblyAI: per-recording HMAC in `X-EchoLedger-Webhook-Token`; ElevenLabs:
`elevenlabs-signature` `t=…,v0=…`), idempotent via `webhook_events`. `401` bad signature; `5xx` only for transient faults.
