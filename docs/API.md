# EchoLedger REST API (v1.1) — authoritative contract

Server: `web/` (Laravel). Clients: `desktop/` (Tauri, all HTTP in Rust) and `mobile/` (Kotlin, OkHttp).
If implementation and this file disagree, fix one of them in the same change — never leave drift.

**Contract revision: 2.0.0-n (2026-11-21, waiting for the lead)** — bumped whenever a section below changes; briefs name the revision they build on. After the freeze, only the lead changes this file, always with a bump.
2.0.0-n (proposed 2026-11-21, accepted when merged; U19 "Roman Urdu by default"): `views.status` (a transcript's `views`, `GET /api/transcripts/{id}/views` and a library item's `transcript.views`) is `making` from the moment a finished transcript's default view is on its way, also before its job has started (`progress` is `{}` until the job counts the lines), so apps show "Converting to Roman Urdu…" at once; a `making` that moved no line for 20 minutes, and a view that never started within 20 minutes of the transcript (until 24 hours after it, `none` then), is `failed` (`error` "Converting took too long. Try again."), never busy for ever, and `POST` starts it again. A library item's `transcript.views` counts only views made (or failed) after the transcript's last edit. A library item's `transcript.preview` is in the default view once it is `ready`, and the new `transcript.preview_view` (`"roman"` | `"urdu"` | `"english"` | `"original"`) says which, so rows need not fetch the views. A recording that opens in Roman Urdu gets its automatic title in Roman Urdu, and an automatic title in Urdu letters is written again in Roman Urdu when the view is made. No other field changes.
2.0.0-m (proposed 2026-11-21, accepted when merged): lists (B11, see "Lists (since 2.0.0-m)"). A person's lists are their labels with a name, a colour and a place in the apps' row, kept by the server so every device shows the same ones: `GET /api/lists`, `POST /api/lists` (an empty list), `PATCH` and `DELETE /api/lists/{key}` (rename or delete changes every recording of the person in one transaction) and `PUT /api/lists/order`. Labelling a recording makes its list when it is new (never for a label spelt All, Unread or Important, which stays a plain label). `GET /api/library/views` gives the labels in list order. A label's colour is its list's (`PUT /api/labels/color` and `label_colors` answer as before). New codes `name_reserved`, `list_exists`, `list_not_found`. Only additions: older apps ignore them.
2.0.0-l (proposed 2026-11-21, accepted by the lead and EL-UX 2026-11-21, served when merged, after 2.0.0-m): the person's account and security from the apps — name, profile picture (`avatar_url` and `avatar_version` beside every person shown), email change, password, sign-in methods, browsers and devices of every workspace, two-step setup, phone passkeys, Keep my account through sign-in (`403 deletion_scheduled`, then `"keep": true`), `error_codes` beside `errors`. Additions only, except that an app sign-in during a deletion's grace now answers `deletion_scheduled` instead of `not_allowed`. See "Account and security from the apps (since 2.0.0-l)".
2.0.0-k (proposed 2026-11-21, accepted when merged): a call's title comes from the person called (see "Title from the person"): a person's own title (any rename wins), then "Call with <name>" or the number, then the automatic title. `title` is that title; the new `title_source` (`own` | `person` | `auto` | `app`) says which, and `title_auto` stays `true` only for `auto`. Outside the account (share links, exports, WhatsApp, the audit log) the title never names the person called.
2.0.0-j (accepted 2026-11-21): "Audio only and Transcribe later" (1.8.0-e) is served: the three endpoints, `audio_only`, `transcribe_estimate` and projects' `transcribe_calls`. Clarified where the server had to decide: minutes are plan minutes (0 on the person's own key or an on-device engine); the estimate lists only the person's audio-only recordings among the ids (what bulk would start); bulk on Free without an own key is `403 needs_transcription_key` and nothing starts; the open create takes `transcribe` too.
2.0.0-i (accepted 2026-11-21): the parity plan's row indicators (docs/PLAN_V2_PARITY.md section 3). Library items carry
`important`, `label_colors` and `expires_at`; `PATCH /api/sessions/{id}` takes `important` and answers with `opened`;
`POST /api/sessions/bulk` takes `important` and `unimportant`; `GET /api/library` takes `important=1|0`; `GET
/api/library/views` gives each label its `color`; the new `PUT /api/labels/color` sets one. The unread dot is the
existing `opened` (since v1.3). Only additions: older apps ignore the new fields. See "Important, label colours and days
left (since 2.0.0-i)".
2.0.0-h (accepted 2026-11-18): `POST /api/sessions/export` takes `html` too (parity plan 3: Export ▸ Web page for many recordings); the words are escaped in each page.
2.0.0-g: the design switch both ways (docs/PLAN_V2_PARITY.md section 0): each `ui_v2.*` flag has an availability (`off`,
`opt_in`, `everyone`); a person's own design choice (`classic`, `v2` or none); `GET /api/config` and the new `GET
/api/account/ui` carry `ui_design`; `PUT /api/account/ui` takes `{"design": …}` (the 2.0.0-a `{"classic": …}` still works).
See "The design switch (since 2.0.0-g)".
2.0.0-f: `503 server_busy` on picture uploads when the server is decoding as many pictures as it allows.
2.0.0-e: meeting assets (v1.8 P4): screens' `file_name` and `said`, near-duplicates, 120 an hour, 320 px thumbnails,
the pack's `text` format, `screens/` in the ZIP, parts of the printed pack.
2.0.0-d: inviting people (email invitations with 1/7/30 days, invite links, seats) and help (guides, what's new,
support messages), v1.8 P6.3 and P6.4.
2.0.0-c: the account export includes Live Assist; deleting the account erases it.
2.0.0-b: `GET /api/config` gains `"trash": {"keep_hours": 48}`, how long a deleted recording can be restored; the apps
show this number and never a fixed one (missing on an older server: 48).
2.0.0-a: `GET /api/config` gains `ui_flags` and the new `PUT /api/account/ui`; see "Interface flags (since 2.0.0-a)". The
Live Assist contract is [API_ASSIST.md](API_ASSIST.md), frozen at the same date.
1.8.1-b: the new `error_code` `own_key_rejected` (the person's own Groq key was refused), and `recording_missing` also
covers audio that is no longer stored; `stt/token`'s 503 names no engine; transcript exports carry `provider` only
for the super admin.
1.8.1-a: a failed transcript's `error` is the plain sentence of its `error_code` only — never the provider's own text
(bugs D-129/D-131); the same sentences in Activity; see "Transcript `error_code` messages".

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
| 401 | `unauthenticated` | Token missing, expired or revoked | Stop syncing and uploading, keep local recordings, show the signed-out state |
| 403 | `missing_ability` | Token lacks the route's ability | Permanent: do not retry |
| 403 | `budget_exceeded` | Monthly limit reached: the server cap (message `Monthly transcription limit reached`) or, since B20, the workspace plan's minutes (message `Monthly limit reached — raise it in Settings`) | Keep recording without captions; show the message; do not retry until `usage.limit_reached` is false |
| 403 | `storage_limit_reached` | Since B20: a new recording would pass the workspace plan's storage (message `Storage limit reached — raise it in Settings`) | Permanent for this upload: keep the recording on the device, show the message |
| 403 | `device_limit_reached` | Since B20: pairing would pass the workspace plan's devices (message `Device limit reached — raise it in Settings`) | Show the message; do not retry |
| 403 | `not_allowed` | The role does not allow it: since v1.5 a viewer recording or uploading, or a non-admin device changing the shared vocabulary (someone else's recording or project answers `404` since v1.5: it is private to its maker) | Permanent; show the message |
| 403 | `ai_budget_reached` | Since v1.4: the workspace's monthly AI budget for client workspaces (`client_ai.monthly_usd`, default $5) is used up; since v1.5 the plan's AI credits | Show the message; client notes and Ask wait until next month or a higher budget |
| 403 | `feature_not_in_plan` | Since v1.5: the workspace's plan leaves the feature out (`feature`: `live_translate`, `screen_moments`, `ai_notes`, `connector`) | Hide the feature; show the message once |
| 403 | `seat_limit_reached` | Since v1.5: an invitation would pass the plan's seats | Show the message |
| 404 | `not_found` | Unknown route | Permanent |
| 404 | `session_not_found` | Session unknown, deleted, or (finish, stt/token) not created by this device | finish: create a new session with a new `client_ref`; delete: treat as done |
| 404 | `recording_not_found` | Recording unknown or its session was deleted | Drop the upload slot |
| 404 | `transcript_not_found` | Transcript unknown or its session was deleted | Refresh the library |
| 404 | `client_not_found` | `client_id` unknown or in another workspace | Clear the selected client and retry once without it |
| 404 | `device_not_found` | `DELETE devices/{id}`: the device is unknown or someone else's | Refresh the device list |
| 404 | `notes_not_found` | Since v1.4: `GET sessions/{id}/notes` for a recording without notes | Offer to write them (`POST sessions/{id}/notes`) |
| 404 | `memory_not_found` | Since v1.4: the memory line is unknown or belongs to another client | Refresh the workspace |
| 404 | `file_not_found` | Since v1.4: the client file is unknown or already deleted | Refresh the workspace |
| 404 | `suggestion_not_found` | Since v1.4: the suggested fix is unknown or was already accepted or dismissed | Refresh the transcript |
| 404 | `rule_not_found` | Since v1.5: the filing rule is unknown or belongs to another project | Refresh the rules |
| 409 | `rule_exists` | Since v1.5: another project already has a rule with that kind and value | Show which project has it |
| 409 | `too_many_screens` | Since v1.5: a recording keeps at most 400 screen moments | Stop sending screens for this recording |
| 401 | `otp_required` | Since v1.5: the account has two-factor sign-in; send `otp` | Ask for the code and send again |
| 403 | `email_not_verified` | Since v1.5: the account's email is not verified yet | Say so; the console's email has the link |
| 409 | `owner_of_team` | Since v1.5: the owner of a workspace with other members must hand it over before deleting the account | Open the console's Workspace page |
| 422 | `invalid_credentials` | Since v1.5: wrong email or password (one answer for both) | Ask again |
| 422 | `invalid_otp` | Since v1.5: the two-factor code is wrong or used | Ask again |
| 422 | `invalid_code` | Since v1.5: the browser sign-in code is wrong, used, expired or for another verifier | Start the browser sign-in again |
| 429 | `too_many_attempts` | Since v1.5: sign-in locked for a while after failures | Wait for `Retry-After` |
| 422 | `invalid_parent` | Since v1.5: a project cannot go under itself or one of its own subfolders | Pick another folder |
| 422 | `too_deep` | Since v1.5: projects nest at most 4 levels | Pick a higher folder |
| 405 | `method_not_allowed` | Wrong HTTP method | Permanent |
| 409 | `upload_complete` | Chunk sent after the recording started assembling | Stop sending chunks; poll `GET /api/recordings/{id}` |
| 409 | `session_not_recording` | `stt/token`: the session is not in status `recording`, or is 4 hours old or older | Captions are unavailable for this recording; keep recording |
| 409 | `too_many_open_uploads` | 50 or more unfinished recordings exist for this device | Finish pending uploads, then retry |
| 409 | `too_many_open_sessions` | 10 or more sessions of this device are still `recording` | Finish or discard them, then retry |
| 409 | `current_device` | `DELETE devices/{id}` for the device making the request | Sign out in the device's own settings |
| 409 | `nothing_to_export` | `sessions/export`: none of the chosen recordings has a finished transcript | Permanent for those recordings |
| 409 | `duplicate_recording` | `POST /api/sessions` with `skip_duplicate`: the same file (by SHA-256) is already a live recording of this workspace; `session_id` names it (since v1.4) | Mark the import as skipped (already in the Library); do not retry |
| 409 | `views_unavailable` | `transcripts/{id}/views`: the transcript is not finished, or no Anthropic key is set (since v1.2) | Add the key, or wait for the transcript |
| 409 | `cannot_retranscribe` | `sessions/{id}/retranscribe`: the recording's audio is not on the server (not uploaded yet, or erased; since v1.2) | Retry once the upload has finished |
| 409 | `ai_unavailable` | Since v1.4: client notes, brief or Ask without an Anthropic key in API Credentials | Show the message; retry after the key is added |
| 409 | `no_client` | Since v1.4: `POST sessions/{id}/notes` for a recording not filed under a project | File it under a project first |
| 410 | `restore_expired` | `sessions/{id}/restore` after the 48-hour undo bin ended | Permanent: the recording is gone |
| 413 | `too_large` | Body or declared size above the limit | Permanent |
| 422 | `validation_failed` | Field errors in `errors` | Permanent for that request |
| 422 | `session_required` | `stt/token` for `live_captions` without `session_id` | Open a session first (v1.1 clients); v1 clients lose captions only |
| 422 | `upload_incomplete` | `complete` while chunks are missing (`missing_chunks`) | Send the missing chunks, then call `complete` again |
| 422 | `verification_failed` | `complete`: the assembled file failed the size/SHA-256 check; the parts were discarded | Upload the whole file again from chunk 0 |
| 422 | `invalid_cursor` | `library` cursor is malformed | Restart paging without a cursor |
| 422 | `too_many_labels` | More than 20 labels on one recording | Permanent: remove some labels first |
| 422 | `name_reserved` | Since 2.0.0-m: a list can't be called All, Unread or Important (in English or Urdu: سب, نہ پڑھی گئی, اہم) | Ask for another name |
| 409 | `list_exists` | Since 2.0.0-m: the person already has a list of that name (capitals and spaces ignored); `name` is that list's | Say "You already have a list called …" |
| 404 | `list_not_found` | Since 2.0.0-m: no list of the person's has this key | Refresh the lists |
| 429 | `rate_limited` | Throttled; `Retry-After` is always set | Wait `Retry-After` seconds |
| 502 | `provider_error` | `stt/token`: the provider refused or could not be reached | Retry later |
| 503 | `provider_not_configured` | `stt/token`: provider key or region missing. Since 1.8.1-b the `message` is "Live captions are not set up on the server yet. Ask your admin." (or "Dictation …" for dictation); it never names an engine | Show the message; retry later |

## Enumerations
| Field | Values |
|---|---|
| `platform` | `android`, `ios`, `windows`, `macos`, `linux` |
| `source_device` (derived by server: android/ios→`phone`, windows/macos/linux→`desktop`, admin upload→`web`) | `phone` "Phone" icon `device-phone-mobile` · `desktop` "Desktop" icon `computer-desktop` · `web` "Web" icon `globe-alt` |
| `source_app` | `whatsapp` "WhatsApp" · `whatsapp_business` "WhatsApp Business" · `whatsapp_web` "WhatsApp Web" (since v1.1) · `google_meet` "Google Meet" · `zoom` "Zoom" · `microsoft_teams` "Microsoft Teams" (since v1.1) · `telegram` "Telegram" · `signal` "Signal" · `messenger` "Messenger" · `instagram` "Instagram" · `skype` "Skype" · `slack` "Slack" · `discord` "Discord" (these seven since v1.3, calls recorded on the phone) · `phone_call` "Phone call" (since v1.2, a SIM call recorded on the phone) · `voice_note` "Voice note" · `other` "Other" |
| session `status` | `recording`, `uploading`, `processing`, `completed`, `failed`, `audio_only` (since v1.8: uploaded, not transcribed; apps that don't know it show it like a completed recording without a transcript) |
| recording `role` | `primary` (stereo or mono file used for playback + multichannel providers), `channel_1` (mono "Me"), `channel_2` (mono "Client") |
| upload `status` | `pending`, `uploading`, `assembling`, `ready`, `failed` |
| transcript `kind` / `status` | `live`, `final`, `alternate` (since v1.1), `trial` (since v1.1.2: a Compare engines run, never in the library or search) / `pending`, `submitted`, `processing`, `awaiting_device`, `completed`, `failed` |
| provider | `assemblyai`, `elevenlabs`, `azure_mai`, `whispercpp_local`, `parakeet_local`, `soniox` (since v1.1.2, final transcripts only), `groq` (since v1.2: Whisper large-v3, final transcripts only), `gemini` (since v1.5: Google Gemini, final transcripts only) |
| use case | `live_captions`, `dictation`, `final_transcript`, `voice_note`, `game_translate`, `watch_translate` |
| transcript `error_code` (since v1.1, set when `status` is `failed`) | `no_audio`, `budget_exceeded`, `provider_unreachable`, `provider_rejected`, `provider_not_configured`, `provider_timeout`, `interrupted`, `recording_missing`, `needs_transcription_key` (since v1.6: the Free plan without the person's own key), `own_key_rejected` (since 1.8.1-b: the provider refused the person's own key) |
| transcript `backup_reason` / `retry_reason` (since v1.5) | `waited`, `rejected` / `rate_limited`, `unavailable` |

**Segment** (shared transcript shape; channel 1 = "Me" mic/left, channel 2 = "Client" system/right; mono = channel 1 "Speaker"):
```json
{"channel": 1, "speaker": "Me", "start_ms": 250, "end_ms": 2600, "text": "Smoke from the north.",
 "words": [{"text": "Smoke", "start_ms": 250, "end_ms": 650, "confidence": 0.97}]}
```

**Transcript `error_code` messages** (since v1.1; plain words since contract 1.8.1-a). The `error` field of a failed
transcript (in `GET /api/transcripts/{id}`, a session's `transcripts` and the session detail) is exactly the sentence for
its code below — never the provider's own text, which stays in the server logs and the operator's view. Library items
carry no `error`, only `transcript.final_error_code`. Apps show their own words per `error_code` (desktop: check the
microphone and the audio device for `no_audio`; Android: check Setup → Call capture) and may ignore `error`.

| `error_code` | `error` (message) |
|---|---|
| `no_audio` | `Nothing could be heard in this recording.` |
| `budget_exceeded` | `The monthly transcription limit was reached.` |
| `provider_unreachable` | `The speech engines could not transcribe it this time. Your audio is safe: try again.` |
| `provider_rejected` | `The speech engines could not transcribe it this time. Your audio is safe: try again.` |
| `provider_timeout` | `The speech engines could not transcribe it this time. Your audio is safe: try again.` |
| `provider_not_configured` | `No speech engine is set up for this language yet.` |
| `interrupted` | `Transcription was interrupted. Your audio is safe: try again.` |
| `recording_missing` | `The audio did not fully reach the server, or it is no longer stored.` |
| `needs_transcription_key` | `Add your free Groq key in Account → Transcription key.` (the one vendor name people must act on) |
| `own_key_rejected` | `Groq did not accept your key. Check it in Account → Transcription key, then try again.` |
| missing or unknown code | `The speech engines could not transcribe it this time. Your audio is safe: try again.` |

The Activity item for a failed transcript (`transcript_failed`) reads `<recording title>: <the sentence above>`.

---

### Workspaces (since B20)

Every device, client, recording and usage row belongs to one workspace; a device token acts for the workspace it was
paired into (the console user's current workspace when the code was made). Everything a device reads or changes is
limited to that workspace: an id from another workspace answers exactly like an unknown id (`404`). Until v1.5,
members of a workspace shared its library (a member deleted, restored and erased only recordings they made, `403
not_allowed` otherwise; owners any); since v1.5 each person's recordings and projects are private to them until they
share them (see "Workspaces, roles and privacy"). Plan limits (minutes per month, storage, devices) answer
`403 budget_exceeded`, `403 storage_limit_reached` and `403 device_limit_reached`. The shared vocabulary (no
`client_id`) is server-wide and only a super admin's device may change it.

## Pairing & configuration

### `POST /api/devices/register` — public, throttled (5/min/IP)
The admin creates a one-time pairing code in Filament (Profile → Devices → "Sign in a device"; valid 10 min, single use).
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

### `POST /api/devices/report` — `config:read`, throttled (10/hour/device) (since v1.5)
The result of an app's device check (phone: Device check; Mac: Run Mac check), sent only when the person taps the
button that sends it. Nothing is sent by itself. It holds no recordings, names or phone numbers. One report per device:
a new one replaces the last. Any role may send one, a viewer too.
```json
{"platform": "android", "app_version": "1.5.0", "brand": "Xiaomi", "model": "23078PND5G", "os_version": "14",
 "checks": [{"key": "app_calls", "state": "limited", "detail": "Your voice only: the other side needs Android 14."}]}
```
`platform` is an enumerated device platform; `brand`, `model`, `os_version` at most 64 characters, `app_version` 32;
`checks`: 1 to 40, `key` lower-case letters, digits and `_` (at most 40), `state` is `ok`, `limited`, `off` or
`failed`, `detail` at most 300 characters or null. `201 {"data": {"reported_at": "…"}}`; `422 validation_failed`;
`429 rate_limited`. The console shows them under System → Device reports: the super admin every device's, owners and
admins those of their workspace.

### `POST /api/devices/missed-recordings` — `config:read`, throttled (60/hour/device) (since v1.8, contract 1.8.0-c)
A call the app saw but did not record (v1.8 P0 0.2). The app sends one when its missed-recording detector compares a
call event with its recordings and finds none. It never sends names, phone numbers or audio; the call is described only
by its app, its time and how long it lasted.
```json
{"client_ref": "c1f0…", "platform": "android", "app_version": "1.8.0", "source_app": "whatsapp",
 "call_started_at": "2026-11-16T09:12:00Z", "call_duration_s": 312,
 "reason": "helper_not_armed"}
```
- Required: `client_ref`, `platform`, `source_app`, `call_started_at`, `reason`. Optional: `app_version` (at most 32),
  `call_duration_s` (0–86400 or null). Any other key (an older app's `detail`) is ignored and never stored.
- `client_ref`: the app's own opaque id for the event (`^[A-Za-z0-9._:-]{1,64}$`; a UUID fits), so a retry never makes
  a second item: the same device + `client_ref` → `200` with the existing id (also when two copies arrive at once).
- `platform` as elsewhere; `source_app`: a session `source_app` wire value (the table under Sessions: `whatsapp`,
  `whatsapp_business`, `whatsapp_web`, `google_meet`, `zoom`, `microsoft_teams`, `telegram`, `signal`, `messenger`,
  `instagram`, `skype`, `slack`, `discord`, `phone_call`, `voice_note`, `other`), or `browser` for a meeting in a browser
  that no known app was recognised in.
- `call_started_at`: ISO-8601, at most 30 days old; a device clock up to 5 minutes ahead is accepted and stored as the
  server's time.
- `reason`: `helper_not_armed` | `permission` | `detection` | `mic_busy` | `crash` | `plan` | `unknown`.
- The app shows its own words and fix button for the reason; the server keeps no free text.
- `201 {"data": {"id": "…"}}` (or `200` for a repeated `client_ref`); `422 validation_failed`; `429 rate_limited`.
- The server adds an activity item `kind: "missed_recording"` for the device's person: title "Missed recording", body
  "<app> call · <length> · <reason phrase>" (reason phrases: helper_not_armed "The helper was not connected", permission
  "A permission was off", detection "The call was not detected", mic_busy "Another app was using the microphone", crash
  "The app stopped during the call", plan "Your plan did not allow the recording", unknown "The reason is not known");
  the item also carries `reason` and `missed_recording_id`; its `at` is when the server received it. It **needs
  action**: every app counts it in the unread badge and shows it in its "Needs action" group, like failed transcripts.
- Admin: Diagnostics → Missed recordings (staff with the device-reports permission; there is no owner/admin view since
  v1.6): counts per device, reason, system and the app version that reported it, over the last 7 and 30 days. No call
  details beyond the stored fields.
- Rows are deleted 90 days after the call, and with the person or the workspace; the activity item goes with its row.
- Isolation: a device only ever writes its own events; body ids are ignored.

### Signing in with an account (since v1.5)
People have their own accounts (email and password, or Google), and a phone or computer signs in to its owner's
account instead of being paired by an admin. The device's token then acts for that person in their current
workspace, exactly like a paired device's; everything above about tokens, abilities and workspaces still holds.
Pairing codes keep working for older apps, and every signed-in person (not only an admin) can make one for their own
account in the console (Profile → Devices → "Sign in a device", the QR of `POST /api/devices/register`).

- `POST /api/auth/password` — public, throttled (5/min/IP and 5 per 15 min per email): `{"email", "password",
  "otp": "123456" | null, "name", "platform", "app_version", "os_version"}` → `201` exactly like
  `POST /api/devices/register` (`device`, `token`, `token_expires_at`, `config`). An account with two-factor sign-in
  needs `otp` (a TOTP code or a recovery code): without it `401 otp_required`, wrong `422 invalid_otp`. Wrong email
  or password `422 invalid_credentials` (the same answer for both); an address not verified yet `403
  email_not_verified`; after 5 failures `429 too_many_attempts` with `Retry-After`. An account in its 7-day deletion grace
  signs in no app (`403 not_allowed`, "Sign in on the console and choose Keep my account"); its pairing codes are
  `422 validation_failed`.
- Browser sign-in (Google, passkeys and everything the console's sign-in page offers): the app opens
  `https://<server>/device/sign-in?code_challenge=<BASE64URL(SHA-256(verifier))>&code_challenge_method=S256&state=<random>&redirect_uri=echoledger://signed-in&name=<device name>&platform=android|windows|macos`
  in the browser. The person signs in (and approves "Sign in EchoLedger on <name>?"), and the browser is sent to
  `echoledger://signed-in?code=<one-time code, 5 minutes>&state=<the same state>`. The app then calls
  `POST /api/auth/device/token` — public, throttled — with `{"code", "code_verifier", "name", "platform",
  "app_version", "os_version"}` → `201` like `POST /api/devices/register`. A wrong or used code, another verifier or
  an expired code is `422 invalid_code`.
- Every new device sign-in sends the account an email ("New sign-in: S25 Ultra, Android") and goes into the audit
  log.

### Your account (since v1.5)
- `GET /api/account` — `config:read` → `200 {"name", "email", "email_verified": true, "two_factor": true,
  "google": true, "created_at", "workspace": {"id", "name", "role": "owner" | "admin" | "member" | "viewer"},
  "deletes_at": null}`.
- Since v1.6 (P4) it also has `"super_admin": false` (true for platform staff who may open the admin panel: the apps
  then show **Admin panel** in the avatar menu, which opens the console's `/admin` in the browser) and `"workspaces":
  [{"id", "name", "role", "current": true}]`, every workspace this person belongs to (the switcher; the current one is
  the device's). Missing on an older server: no Admin panel item, and the switcher shows only `workspace`. Switching
  opens the console for now (a device token belongs to one workspace).
- `POST /api/account/sign-out-everywhere` — `sessions:write`: signs out every device and console session of the
  account, this device included → `204`.
- `DELETE /api/account` — `sessions:write`: `{"password": "…"}` or `{"otp": "123456"}` (one of them, to confirm it is
  you) → `202 {"deletes_at": "…"}`: the account and everything only it owns (recordings, transcripts, screens, files,
  projects of its own workspace, devices) are deleted after 7 days; signing in again before then and choosing "Keep my
  account" cancels it. The owner of a workspace with other members must hand it over first (`409 owner_of_team`).
  The same is offered without the app on the public page `/account/delete` (sign in, confirm), which Google Play
  links to.
- `POST /api/account/export` — `library:read` → `202 {"id": "01JF…", "status": "making"}`; `GET
  /api/account/export/{id}` → `{"id", "status": "making" | "ready" | "failed", "url": "…" | null, "expires_at"}`:
  one ZIP with every recording's audio, transcripts (all views made), screens, project files and notes, and a
  `manifest.json`; since 2.0.0-c also a `live-assist/` folder (settings, preps, and every Live Assist session with its
  lines, suggestions, facts, summary, report and usage, from every workspace). The link works for 24 hours; the account's email gets it too.

### Workspaces, roles and privacy (since v1.5)
Every account has its own personal workspace (it is the owner). A team is a workspace with members; a person can
belong to several and picks one in the console (Profile → Workspace). A device acts in the workspace it was signed in
or paired to.

**Private by default.** A recording (with its transcripts, views, screens, notes and bookmarks) and a project (with
its files, notes, rules and memory) belong to the person who made them. Nobody else in the workspace sees them in the
library, search, projects, activity, usage details or the API (`404`, as for another workspace's ids), owners and
admins included, until that person shares: Send to a teammate (a copy in their library), or a share link. Data made
before v1.5 belongs to the person who made it (on a single-person server: its owner).

| Per workspace | Owner | Admin | Member | Viewer |
|---|---|---|---|---|
| Record, upload, import | yes | yes | yes | no |
| Sign in their own phones and computers (a viewer's only open what is shared with them) | yes | yes | yes | yes |
| Their own recordings and projects: open, edit, organise, share, delete | yes | yes | yes | (has none) |
| Shared with me: open; Save to my library | yes | yes | yes | open only |
| Invite and remove people, change roles | yes | members and viewers | no | no |
| Workspace usage: minutes and costs per person (never content) | yes | yes | their own | no |
| Revoke another person's device | yes | yes | no | no |
| Plan, workspace name, transfer ownership, delete the workspace | yes | no | no | no |

The super admin (the server's operator) manages plans, sees every workspace's members and usage (never content),
and the server settings and API credentials. A workspace always keeps one owner; leaving it needs a transfer first.

- `GET /api/account` gives the role (`workspace.role`). A viewer's device gets `403 not_allowed` from recording and
  upload routes (`POST /api/sessions`, uploads, `stt/token`, `live-translate/token`, request uploads into their name); the apps show "You are
  a viewer in <workspace>: you can open what is shared with you, not record."

### Plans and features (since v1.5)
Plans are made by the super admin: price (0 until billing comes), minutes per month, storage, retention days, seats,
AI credits per month (US dollars of optional AI work), and the features `live_translate`, `screen_moments`,
`ai_notes` (client notes, memory, Ask) and `connector` (Claude and ChatGPT). Roman Urdu and the other transcript views,
suggested fixes and screen descriptions are never switched off by a plan.

- `GET /api/config` gains `"plan": {"key": "personal", "name": "Personal", "features": {"live_translate": true,
  "screen_moments": true, "ai_notes": false, "connector": true}, "limits": {"monthly_minutes": null,
  "storage_bytes": null, "retention_days": null, "seats": null}, "used": {"minutes": 123, "storage_bytes": 456}}`
  (`null` = no limit), and `"workspace": {"id": 1, "name": "Acme team", "role": "owner" | "admin" | "member" |
  "viewer"}` (the device's workspace and its person's role there). Every v1.5 server sends both, so a config without
  `plan` comes from an older server (the desktop then leaves screen moments off). Until the Phase 4 plans, every
  feature is `true`, `retention_days` and `seats` are `null`, and the limits are the workspace's minutes and storage. Missing (an older server): every feature on, no
  limits, role unknown (act as a member). The apps hide what the plan leaves out, show the limits next to the usage,
  and a viewer's app offers no recording.
- New errors: `403 feature_not_in_plan` with `"feature": "screen_moments"` ("Screen moments are not in your plan"),
  `403 seat_limit_reached` (an invitation past the plan's seats), and `403 ai_budget_reached` now also when the
  plan's AI credits for the month are used (it replaces the v1.4 `client_ai.monthly_usd` budget). The B20 errors (`budget_exceeded`, `storage_limit_reached`, `device_limit_reached`) stay.

### Sign-up and sign-in with an email code (since v1.6, P1)
One flow in every app and on the web: the email first, then what fits it. The apps no longer ask for a server address:
they use the production server and keep "Use a different server" under Advanced.

- `POST /api/auth/start` — public, throttled (10/min/IP, 5/min per email): `{"email"}` → `200 {"account": "existing"
  | "new", "methods": ["password", "code", "google"], "sign_up": true}`. `methods` for an existing account lists what
  it can use (`password` only if it has one, `google` only when the server offers Google); for a new email `["code",
  "google"]` when sign-up is open. A new email while sign-up is closed: `403 sign_up_closed` ("Ask the owner for an
  invitation"); an address on the disposable-email list or outside the allowed domains: `422 email_not_allowed`.
  Saying whether an account exists is deliberate (the person must know whether they are signing up); the limits make
  probing many addresses slow.
- `POST /api/auth/code` — public, throttled: `{"email", "purpose": "sign_in" | "sign_up", "turnstile": "…" | null}` →
  `202 {"resend_after": 60, "expires_in": 600}` and a 6-digit code by email (valid 10 minutes; a new code replaces the
  old one). Again before 60 s: `429 resend_too_soon` with `Retry-After`; at most 5 codes an hour per email and 20 per
  IP (`429 too_many_attempts`); sign-ups per IP a day (Admin → Sign-up, default 5): `429 sign_up_limit`. `turnstile`
  is required on the web sign-up page when Turnstile is set (`422 turnstile_failed`); the apps send none.
- `POST /api/auth/code/verify` — public, throttled: `{"email", "code", "otp": null, "name", "platform",
  "app_version", "os_version", "install_id"}` (the device fields as in `POST /api/auth/password`):
  - an existing account: `201` exactly like `POST /api/auth/password` (`device`, `token`, `token_expires_at`,
    `config`), or `401 otp_required` when it has two-factor sign-in (send again with `otp`, the same code is still
    good for 10 minutes);
  - a new email: `200 {"next": "profile", "sign_up_token": "…"}` (valid 15 minutes, one use);
  - a wrong code: `422 invalid_code` with `attempts_left`; after 5 wrong codes, or after 10 minutes, `422
    code_expired` (ask for a new one).
- `POST /api/auth/sign-up` — public, throttled: `{"sign_up_token", "name", "accept_terms": true, "device_name",
  "platform", "app_version", "os_version", "install_id"}` (`name` is the person's; `device_name` optional, else the
  readable name from the platform and OS) → `201` like `POST /api/auth/password`: the account (email verified by
  the code), its personal workspace on the default plan (Free) and the device's token. `accept_terms` must be `true`
  (`422 validation_failed`); a used or old token `422 invalid_code`. The web page `/sign-up` does the same with the
  console session instead of a device token.
- `install_id` (since v1.6, P3): an opaque string of 8–64 characters `[A-Za-z0-9-]` the app makes once per install and
  keeps (Android: a random UUID in its private storage; desktop: 64 hex characters, a hash of the machine id and the
  app's data folder). Every sign-in route takes it; a device of the same
  account with the same `install_id` is signed in again on its own row instead of a new one. Optional for older apps.
- `GET /api/auth/options` gains `"code": true` (email codes offered) and `"server": "https://…"` (its own address).

### Entitlements (since v1.6, P1)
The server alone decides what a person may use. Features are in a registry (key, name, description, `cost`: `free`
or `paid`); plans grant features and limits; the super admin can override any of them for one person, with an expiry
for the plan (then the default plan). Every route checks them; the apps only show what the answer says.

- `GET /api/me/entitlements` — `config:read`:
  ```json
  {"plan": {"key": "free", "name": "Free", "badge": "FREE", "color": "#64748b", "expires_at": null,
            "trial_minutes_left": 0},
   "features": {"platform_transcription": {"enabled": false, "name": "Transcription on EchoLedger",
                  "description": "…", "cost": "paid", "lowest_plan": "pro"},
                "views": {"enabled": false, "…": "…"}},
   "limits": {"monthly_minutes": {"limit": 0, "used": 0}, "devices": {"limit": 2, "used": 1},
              "storage_bytes": {"limit": 1073741824, "used": 52428800}, "retention_days": {"limit": 30},
              "seats": {"limit": 1, "used": 1}, "max_file_minutes": {"limit": 180},
              "uploads_per_day": {"limit": 50, "used": 3}, "ai_usd_month": {"limit": 0, "used": 0}},
   "engines": ["own_groq", "on_device"],
   "transcription_key": {"provider": "groq", "set": true, "last4": "3f9a"},
   "upgrade_request": {"id": "01JF…", "plan": "pro", "status": "pending" | "approved" | "declined", "created_at": "…"},
   "plans": [{"key": "pro", "name": "Pro", "badge": "PRO", "color": "#7c3aed", "price": "Rs 2,000 a month",
              "description": "…", "features": ["platform_transcription", "views", "…"],
              "limits": {"monthly_minutes": 600, "devices": 5, "…": "…"}}]}
  ```
  `limit: null` = no limit; `limit: 0` = not in the plan (the apps say so). `upgrade_request` is the newest one or `null`. `plans` are the plans on offer, cheapest
  first, for the comparison sheet.
- Feature keys: free — `record`, `organise`, `search`, `share_links`, `export`, `ready_check`; paid —
  `platform_transcription`, `views` (Roman Urdu, English and Urdu views; until v1.6 never switched off),
  `double_check`, `screen_moments`, `connector`, `team`, `premium_engines`, `live_translate`, `ai_notes`.
- Errors: `403 feature_not_in_plan` with `"feature"` and `"lowest_plan"` (the badge the app shows); `403
  limit_reached` with `"limit"` (a key above), replacing `budget_exceeded`, `storage_limit_reached` and
  `device_limit_reached` (still sent to apps older than v1.6 by their old codes).
- `GET /api/config` keeps its `plan` block for older apps, made from the entitlements.

### Your own transcription key (since v1.6, P1 — the Free plan)
- `PUT /api/me/transcription-key` — `sessions:write`, throttled (10/hour): `{"provider": "groq", "key": "gsk_…"}` →
  the server checks it with Groq once → `200 {"provider": "groq", "set": true, "last4": "3f9a"}`; a key Groq refuses
  is `422 invalid_key`. Kept encrypted for that person only, never sent back, used only for their own recordings.
  `DELETE /api/me/transcription-key` → `204`.
- A recording of someone without `platform_transcription` is transcribed with their own key; without one, its
  transcript is `failed` with `error_code: "needs_transcription_key"` ("Add your free Groq key in Account →
  Transcription key"). Retry and Re-transcribe use the same key. (The desktop has no on-device engine today: a Free
  computer uses the person's key too.)

### Errors and counting rules (since v1.6)
| `code` | Status | When |
|---|---|---|
| `sign_up_closed` | 403 | A new email while sign-up is off |
| `email_not_allowed` | 422 | A disposable address, or outside the allowed domains |
| `resend_too_soon` | 429 | A code again before 60 s (`resend_after` in the body) |
| `sign_up_limit` | 429 | Too many new accounts from one network today |
| `turnstile_failed` | 422 | The web sign-up's anti-bot check failed |
| `invalid_code` | 422 | A wrong email code (`attempts_left`) or an old sign-up token |
| `code_expired` | 422 | The code is used up (5 wrong) or older than 10 minutes |
| `limit_reached` | 403 | A plan limit (`limit`: the key in entitlements); to apps older than v1.6 the old code plus `limit` |
| `invalid_key` | 422 | Groq refused the person's key |
| `provider_error` | 502 | Groq could not be asked about the key (try again) |
| `request_pending` | 409 | An upgrade request is already waiting (`request` holds it) |
| `request_decided` | 409 | Since v1.7: the upgrade request was already approved, declined or cancelled | Refresh the request; show its outcome |
| `proof_required` | 422 | Since v1.7: `…/paid` with no unsent receipt and no transaction ID | Ask for a receipt or the transaction ID |
| `proof_locked` | 409 | Since v1.7: the receipt was already sent with `…/paid` and can't be removed | Keep it; refresh |
| `proof_not_found` | 404 | Since v1.7: unknown receipt, or not on this request | Refresh the request |
| `too_many_proofs` | 409 | Since v1.7: 3 unsent receipts at a time, 10 on one request (`max`) | Remove one first |
| `account_suspended` | 403 | The account is suspended: every call of its devices |
| `invalid_promo_code` | 422 | A promo code that is unknown, ended, used up or already used by this person |
| `promo_other_plan` | 422 | A valid promo code for another plan (`plan` names it) |
| `update_required` | 426 | The app is older than the minimum and the server refuses older apps |
| `maintenance` | 503 | The server is in maintenance (`until`, `Retry-After`) |

- Devices are counted per person (active, not expired); "recordings a day" is recordings started in the last 24 hours
  by that person in that workspace; "longest recording" is checked when a recording is created and finished.
- `GET /api/security` devices carry readable names ("Galaxy S25 Ultra", "Windows 11 PC"), `status` (`active` |
  `signed_out` | `revoked` | `expired`, 90 days without use) and `signed_out_at`. `DELETE /api/devices/{id}` signs the
  device out and keeps its row (Remove from list deletes it in the console).
- `GET /api/upgrade-requests/latest` also has `approved_plan` and `approved_until` once approved.
- Activity kinds for the super admin only: `upgrade_requested`, `unusual_usage`.
- `GET /api/config` keeps its v1.5 `plan.features` (`live_translate`, `screen_moments`, `ai_notes`, `connector`);
  everything else is in entitlements.

### Renaming a device (since v1.6, P3)
- `PATCH /api/devices/{id}` — `sessions:write`: `{"name": "…"}` (1–60 characters, trimmed) → `200` with the device as
  `GET /api/security` lists it. Another person's device `404 not_found`. An older server answers `404`/`405`: the apps
  say to rename it in the console.

### Updates, announcements and maintenance (since v1.6, P2.7)
- `GET /api/config` gains three fields, each `null` when unused:
  - `"app_update": {"minimum": "1.6.0", "required": true, "refused": false, "message": "Please update EchoLedger to
    1.6.0 or later.", "url": "https://<server>/downloads"}`: this app's version is below the minimum the admin set. The
    apps show the message with **Update** (the url; the desktop's own updater first, when it has one). `refused`: the
    admin turned on "Refuse older apps".
  - `"announcement": {"text": "…", "level": "info" | "warning", "until": "<ISO>" | null}`: a banner at the top until
    `until`; the person may close it for that text.
  - `"maintenance": {"message": "…", "until": "<ISO>" | null}`: the server is in maintenance.
- `426 {"message", "code": "update_required", "minimum": "1.6.0", "url": "…"}` on every device route except
  `GET /api/config`, only while "Refuse older apps" is on and the app is below the minimum. The apps keep their
  recordings and say to update.
- `503 {"message", "code": "maintenance", "until": "<ISO>" | null}` with `Retry-After` on every device route except
  `GET /api/config` while in maintenance (staff devices are let through). The apps keep recordings and uploads waiting
  and try again after `Retry-After`; it is not an error to show as a failure.
- Activity kind `cost_alert` (staff only: the month's provider spend at 80 % and 100 % of the budget).
- `GET /api/upgrade-requests/latest`: `reply` is also set on an approval when the admin wrote a message.

### Promo codes (since v1.6.2)
Staff make promo codes in the admin panel (Promo codes): a code (6–24 characters `A-Z 0-9 -`, matched without case), **one
plan** it is for, a **discount of 1–100 %**, and optionally an end date, a maximum number of uses, one use per person (the
default) and how many months the plan lasts once switched on (default 1). Only staff with the Plans permission see or
make codes; customers can never list them. Every use is in the activity log.
- `POST /api/promo-codes/check` — `config:read`, throttled (10/min per person, 30/hour per IP): `{"code", "plan": "pro" |
  null}` → `200 {"code": "SAVE50", "plan": "pro", "plan_name": "Pro", "percent": 50, "price": "Rs 2,999 / month",
  "final_price": "Rs 1,500 / month", "free": false, "months": 1}`. `free` is true at 100 %. Unknown, ended, used up or
  already used by this person: `422 {"message", "code": "invalid_promo_code"}`. Valid but for another plan than the
  one asked: `422 {"message": "This code is for Pro.", "code": "promo_other_plan", "plan": "pro"}` (the apps then offer
  that plan). The answer never says how many uses are left or who else used it.
- `POST /api/upgrade-requests` accepts `"promo_code": "SAVE50"` (optional), checked as above for the request's plan; a
  code for another plan is the same `422 promo_other_plan`. The answer gains `"promo_code"`, `"percent"` and
  `"amount_due"` (the price after the discount, as shown). **At 100 %** nothing is paid: the request is approved at once
  (`status: "approved"`, `approved_plan`, `approved_until` = now + the code's months) and the person's plan switches on;
  `payment_instructions` is then null. Below 100 % it waits for the staff's approval as before, with the code and the
  amount on the request in the inbox.
- `GET /api/upgrade-requests/latest` carries the same three fields.

### Signing a device in again (since v1.6, P3)
- In the console a device row that is signed out, revoked or expired has **Sign in again**: a one-time pairing code
  (the usual 8 characters, 10 minutes, single use, shown as a code and a QR) **bound to that row**.
- The app redeems it with `POST /api/devices/register` as any pairing code (no app change). The answer is `201` with
  `device.id` = **the same row**: it is active again (`signed_out_at` cleared, `status` `active`), keeps its name (a
  chosen name stays) and its recordings, gets a new token, and the old tokens stay revoked. The request's
  `install_id`, when sent, replaces the row's (the same phone installed again).
- A bound code used by another platform than the row's (a desktop code on a phone) is `422 validation_failed`
  (`errors.pairing_code`: "This code signs in <device name> again; use it on that device"). A bound code for a row
  that was removed from the list meanwhile is `422` too. The person's device limit counts the row once (it was
  already theirs). Another person's device is never offered and its id is never accepted (`404`).

### Inviting people (since 2.0.0-d, v1.8 P6.3)
Owners and admins only (admins give members and viewers): anyone else gets `403 not_allowed`. A team is a paid feature
(`403 feature_not_in_plan`, `feature: "team"`). An open email invitation takes a seat; an invite link takes none until
someone joins with it. `403 seat_limit_reached` when a seat is needed and none is left.

- `GET /api/workspace/invitations` — `config:read` →
  `{"seats": {"used": 3, "limit": 10}, "invitations": [{"id": 12, "email": "sara@example.com", "role": "member",
  "expires_at": "…", "created_at": "…"}], "links": [<link>]}`; only open ones, at most 100 each.
- `POST /api/workspace/invitations` — `sessions:write`, 20 an hour per person. `{"email", "role": "admin" | "member" |
  "viewer", "days": 1 | 7 | 30}` (7 by default) → `201 {"invitation": {…}}`. The address gets an email with a link
  that works once, for that address, for those days; one waiting for the same address is replaced. `422
  validation_failed` for someone already in the workspace.
- `DELETE /api/workspace/invitations/{id}` — `sessions:write` → `204`; its link stops working, its seat is free.
- `POST /api/workspace/invite-links` — `sessions:write`, 20 an hour per person. `{"role": "member" | "viewer",
  "days": 1 | 7 | 30, "max_uses": 1-500 | null}` → `201 {"link": <link>, "url": "https://…/join/…"}`, `Cache-Control:
  no-store`. The `url` is in this answer only: show it once with Copy and Share.
- `DELETE /api/workspace/invite-links/{id}` — `sessions:write` → `204`; it stops working at once, people who joined stay.
- `<link>`: `{"id", "role", "expires_at", "max_uses", "uses", "open", "revoked_at", "created_at"}`.
- The link opens "Join Acme on EchoLedger" on the web: sign in or create an account (email code or Google), then the
  apps. A link that ended, was revoked or was used up answers `410`.

### Help (since 2.0.0-d, v1.8 P6.4)
- `GET /api/help/guides?q=` — `config:read` → `{"data": [{"id": "after-a-call", "title": "After a call", "excerpt"}]}`:
  the user guide's sections: without `q`, all of them; with `q`, those that match, best first (at most 20).
- `GET /api/help/guides/{id}` — `config:read` → `{"id", "title", "markdown"}`; `404` for an unknown id.
- `GET /api/help/whats-new?platform=android|windows|mac` — `config:read` → `{"data": [{"version", "platform",
  "released_at", "notes"}]}`: the platform's last 10 releases that have notes, newest first.
- `POST /api/support/messages` — `sessions:write`, 5 an hour per person. `{"subject": ≤ 120, "message": ≤ 5000,
  "include_device_report": false}` → `202`. Emails the server's support address (Admin → Settings, default
  contact@<app domain>) with the person's name and email as reply-to; the device's last report (`POST
  /api/devices/report`) is attached only when `include_device_report` is true. The audit log records that a message
  was sent, never its text.

### Upgrade requests (since v1.6, P1)
- `POST /api/upgrade-requests` — `sessions:write`, throttled (5/day): `{"plan": "pro", "message": "…" | null}` →
  `201 {"id", "plan", "status": "pending", "message", "payment_instructions": "…", "created_at"}`. One pending
  request at a time: another is `409 {"message", "code": "request_pending", "request": {…the pending one…}}`.
- `GET /api/upgrade-requests/latest` — `config:read` → the newest request (with `decided_at` and the admin's
  `reply` once decided) or `404 not_found`.
- The admin approves (the plan, until a date or not) or declines with a message; the person gets an email and an
  activity item (`kind`: `upgrade_approved` | `upgrade_declined`).

### Screen-share privacy (since v1.8.1)
EchoLedger's own windows stay out of screen shares where the system allows it, and the desktop app checks it on each
computer (docs/research/screen-share-privacy.md, docs/SCREEN_SHARE_MATRIX.md). Two endpoints support that:
- `POST /api/diagnostics/share-checks` — `config:read`, throttled (30/hour per device): one Share Check result,
  sent only when the person allows diagnostics. Body: `{"platform": "windows" | "macos" | "android", "os_build":
  "10.0.26100" (≤ 64), "app_version": "1.8.1" (≤ 32), "method": "wgc" | "dxgi" | "sck" | "readback" | "guided" |
  "recording_callback", "result": "pass" | "fail" | "error", "reason": null | "permission_missing" |
  "capture_failed" | "control_missing" | "old_windows" | "remote_desktop" | "flag_mismatch", "meeting_app": null |
  "zoom" | "meet" | "teams" | "slack" | "whatsapp" | "other", "meeting_app_version": null | "6.4.1" (≤ 32),
  "share_mode": null | "screen" | "window" | "tab"}` → `201 {"data": {"id"}}`; `422 validation_failed`. No pictures,
  window titles or window contents are ever sent. The server keeps the result with the device and its person; staff
  with the device-reports permission see them only as a matrix in Admin → Diagnostics → Screen-share checks (platform ×
  OS build × app version × method or meeting app → pass / fail / error counts and the last date). Nobody else can read
  them, and a person's own results are not listed back to any app. Since contract 1.8.0-d: `platform` must be the
  sending device's own platform (`422` otherwise); `os_build`, `app_version` and `meeting_app_version` hold only
  letters, digits, spaces and `. ( ) _ + -`; results are deleted 180 days after they arrive, and with their person.
- `GET /api/config/share-bars` — `config:read`: the window patterns that mean "a screen share is running" (Zoom's
  share toolbar, Teams' sharing bar, the browsers' "is sharing your screen" bars, Slack and WhatsApp), so they can
  change without an app release → `200 {"version": 1, "updated_at": "…", "patterns": [{"id":
  "zoom-share-toolbar-mac", "app": "zoom", "platform": "macos", "owner": "^zoom\\.us$", "title": "…"}]}` with an
  `ETag` (strong, of the file's bytes) and `Cache-Control: private, max-age=300`; `If-None-Match` with the same tag → `304`. `owner` and `title` are regular expressions without look-behind or inline
  flags (they run in both Rust and JavaScript), with `"flags": "i"` for case-insensitive; a pattern matches a window
  whose owner name and title both match. The file is web/resources/data/share-bars.json, the one copy for both the
  server and the desktop app's fallback. The desktop
  app keeps the last answer and ships the same file as its fallback.

### Payment receipts (since v1.7)
A paid plan is bought by bank transfer. After `POST /api/upgrade-requests` the customer pays, sends a receipt (a
screenshot or a PDF) and/or the bank's transaction ID, and staff approve or decline it in Admin → Upgrade requests.
Everything here is additive: `status` stays `pending | approved | declined` and `payment_instructions` (now generated
from the payment details) stays for v1.6 apps.

**The request object** (every answer below, `GET /api/upgrade-requests/latest`, and `upgrade_request` in
`GET /api/me/entitlements`) gains:
```json
{"reference_code": "EL-7K2Q9M", "amount_due": "Rs 1,500", "amount_due_pkr": 1500, "currency": "PKR",
 "payment": {
   "stage": "awaiting_payment",
   "accounts": [{"bank": "Example Bank", "branch": "Main Branch (0001)", "title": "EXAMPLE PRIVATE LIMITED",
                 "account_number": "0123456789", "iban": "PK00XXXX0000000123456789"}],
   "note": "Put EL-7K2Q9M in the transfer's note.", "review_hours": "We check receipts every day. Most are done within a few hours.",
   "paid_at": null, "reference": null, "payer_name": null,
   "proof_request": null,
   "proofs": [],
   "max_proofs": 3, "max_bytes": 5242880, "accepts": ["image/jpeg", "image/png", "image/webp", "application/pdf"]},
 "timeline": [{"step": "requested", "at": "2026-11-09T10:10:00Z"}]}
```
- `payment.stage` (computed, first match wins): `approved` · `cancelled` · `declined` · `needs_receipt` (staff asked
  for a new receipt after the last one; `proof_request` is `{"note", "at"}`) · `in_review` (a receipt or transaction ID
  was sent) · `awaiting_payment`.
- Each `proofs[]` entry: `{"id", "kind": "image" | "pdf", "mime", "name", "bytes", "width", "height", "created_at",
  "locked", "thumb_url", "file_url"}` (the URLs need the device token; a PDF has no `thumb_url`). Files sent with
  `…/paid` are `locked` and can no longer be removed.
- `timeline[].step`: `requested`, `receipt_sent`, `receipt_requested`, `decided`, `cancelled`, each with `at`.
- Plan cards in `GET /api/me/entitlements` gain `price_pkr` (whole rupees, null when free or not set) and `price_text`
  ("Rs 2,999 a month"); `limits.monthly_minutes` gains `resets_at` (the 1st of next month, Asia/Karachi).

**Endpoints** (each looks the request up among the caller's own: anyone else's id, a teammate's too, is `404
not_found`, the same as an unknown one):
- `POST /api/upgrade-requests/{id}/proofs` — `sessions:write`, throttled (`upgrade-payment`: 20/hour, 60/day per
  person): multipart `file` (JPEG, PNG, WebP or PDF, at most 5 MB; the type is read from the bytes) → `201 {"data":
  <proof>}`; the same file again on the same request → `200` with the existing one. Images are re-encoded (metadata
  removed); a PDF with a password or active content is refused. Errors: `413 too_large`; `422 validation_failed`
  (`errors.file`, `reason`: `file_type` | `pdf_password` | `pdf_active` | `unreadable`); `409 too_many_proofs`
  (`max`: 3 unsent files at a time, 10 on a request); `409 request_decided`.
- `DELETE /api/upgrade-requests/{id}/proofs/{proof}` — `sessions:write`, same throttle → `204`; `404
  proof_not_found`; `409 proof_locked` (already sent); `409 request_decided`.
- `GET /api/upgrade-requests/{id}/proofs/{proof}` — `config:read`, `?variant=thumb|full` (default `full`) → the
  decrypted file with `Cache-Control: private, no-store` and `X-Content-Type-Options: nosniff`; images inline, a PDF
  always as an attachment (`EL-XXXXXX-receipt.pdf`, `Content-Security-Policy: default-src 'none'; sandbox`). `404
  proof_not_found` for an unknown proof, a PDF asked for as `thumb`, or another `variant`.
- `POST /api/upgrade-requests/{id}/paid` — `sessions:write`, same throttle: `{"payment_reference": "…" | null,
  "payer_name": "…" | null}` (the bank's transaction ID, at most 64 characters; the name the money came from, at
  most 120) → `200 <request>` with stage `in_review`; the files sent so far are locked and staff are told. Needs at
  least one unsent file or a `payment_reference`: otherwise `422 {"code": "proof_required"}`. `409 request_decided`.
- `POST /api/upgrade-requests/{id}/cancel` — `sessions:write`, same throttle → `200 <request>` (stage `cancelled`,
  `status` `declined`, reply "Cancelled by you"; a promo use is given back). `409 request_decided`.

Staff (Admin → Upgrade requests) see the receipts next to the request (an image opens full size, a PDF downloads),
approve or decline with a reason, or **Ask for a new receipt** with a note (stage `needs_receipt`; the customer gets an
email and an activity item `upgrade_receipt_requested` and sends another round of up to 3 files). Receipts are
encrypted on the private disk, never public, seen only by the person and by staff with the upgrade-requests
permission, and erased 90 days after the request is decided or cancelled (the request row stays). The payment details
(bank, branch, account title, account number, IBAN, a note) are set in Admin → Plans → Payment details.

### Public pages and app downloads (since v1.5)
No sign-in, throttled (60/min/IP). The apps open these pages in the browser.
- `GET /terms`, `GET /privacy` (the processors that handle recordings are listed there), `GET /account/delete` (see
  "Your account"), `GET /downloads` (the latest phone, Windows and Mac builds with version, size and SHA-256, and the
  older versions).
- `GET /downloads/android.json` — the newest phone build of the website ("full") flavor, `Cache-Control: public,
  max-age=300`:
  ```json
  {"version": "1.5.0", "code": 16, "url": "https://<server>/downloads/files/EchoLedger-1.5.0.apk",
   "sha256": "<64 lowercase hex>", "bytes": 41234567, "min_sdk": 29, "notes": "…", "published_at": "…"}
  ```
  `404 not_found` while no phone build is published. The full build looks at most every 12 hours (and when the
  person taps Check for updates); a `code` above its own is offered, downloaded from `url` (same server only), checked
  against `sha256` and `bytes`, and handed to Android's installer, which itself refuses a file not signed like the
  installed app. The Google Play build never looks.
- `GET /downloads/desktop.json` — the desktop updater's manifest (Tauri's static format): `{"version", "notes",
  "pub_date", "platforms": {"windows-x86_64": {"url", "signature"}, "darwin-aarch64": {…}, "darwin-x86_64": {…}}}`,
  each `signature` made with the updater key (the public half is built into the app). `404 not_found` while none.
- Builds are published by the release step, not uploaded through the console: `php artisan echoledger:publish-release
  <file> --platform=android|windows|macos-arm|macos-intel --app-version=1.5.0 [--code=16] [--notes=…]
  [--updater-file=<the Mac .app.tar.gz>] [--signature=<file.sig>]` stores the file, measures it, computes the SHA-256
  and keeps the older builds listed. The phone needs `--code`; a desktop build is offered to the updater only with a
  `--signature` (of the Windows installer, or of the Mac `--updater-file`). Files are served from
  `/downloads/files/<name>` as attachments (ranges honoured), e.g. `EchoLedger-1.5.0.apk`,
  `EchoLedger-1.5.0-setup.exe`, `EchoLedger-1.5.0-mac-apple-silicon.dmg`.
- `GET /` is the public landing page (it shows nobody's data); `ECHOLEDGER_SITE_INDEXABLE=true` lets search engines
  index the landing and downloads pages (off until the public launch).

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
           "warn_at_percent": 80, "warn": false, "limit_reached": false},
 "live_translate": {"model": "stt-rt-v5", "monthly_usd": 10.0,
                    "game": {"target": "en", "spoken": ["ar", "en"], "speed": "fastest"},
                    "watch": {"target": "ur", "speed": "balanced"},
                    "budget": {"used_usd": 0.9, "limit_usd": 10.0, "warn": false, "reached": false}}}
```
- `live_translate` **(since v1.3)**: the console's Live Translate defaults (Settings → Game translate / Watch
  translate) and this month's Live Translate spending against its own budget.
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

### Interface flags (since 2.0.0-a)

The redesigned screens ship behind server-controlled flags, off by default. The apps read them from `GET /api/config`
and never decide them on the device.

- `GET /api/config` gains `"ui_flags": {"ui_v2.shell": false, "ui_v2.home": false, "ui_v2.library": false,
  "ui_v2.recording_controls": false, "ui_v2.after_call": false, "ui_v2.live": false, "ui_v2.ask": false,
  "ui_v2.settings": false, "ui_v2.setup_health": false, "ui_v2.admin": false, "ui_v2.website": false,
  "live_assist": false}, "ui_classic": false`. Each value is the result for this person: the super admin switches a
  flag for everyone, for a percentage of accounts (stable per account), for one workspace or for one person. A flag
  the app doesn't know is ignored; a flag missing from the answer (an older server) is `false`.
- `ui_classic: true` means the person chose "Switch back to classic". Every `ui_v2.*` flag then answers `false` for
  them (`live_assist` is unaffected). The apps show the switch in Settings while any `ui_v2.*` flag is on for them.
- `PUT /api/account/ui` — `config:read`. Body `{"classic": true|false}`. `200` with the new `ui_flags` and
  `ui_classic`. The web console uses the same setting for the signed-in person.
- The apps re-read `GET /api/config` at start, on resume after 15 minutes, and right after `PUT /api/account/ui`;
  a change applies on the next screen the person opens, never in the middle of a recording.

### The design switch (since 2.0.0-g)

Classic is the design everyone sees until the new one (v2) has every classic feature and the owner approves it
(docs/PLAN_V2_PARITY.md). Until then v2 is **opt-in**: a person can try it and go back, both ways, on every app.

**What the super admin sets** (Admin › Interface flags): each `ui_v2.*` flag has an **availability**:

| `availability` | Who gets the flag |
|---|---|
| `off` | Not available: nobody by choosing it. Only the admin's own lists (people, workspaces, a share of accounts) get it, for testing. |
| `opt_in` | Everyone who chose the new design (`design: "v2"`), and the admin's lists. |
| `everyone` | Everyone, unless the person chose classic. |

`live_assist` is not a design and has no `opt_in`: `off` (its lists only) or `everyone`. The admin page sets the nine
console flags (`ui_v2.shell` … `ui_v2.setup_health`) together or one by one, and `ui_v2.admin` and `ui_v2.website` on
their own. From 2.0.0-g every `ui_v2.*` flag that was not on for everyone starts on `opt_in` (the owner, 2026-11-18),
so nothing changes for anyone who does not choose.

**What the person sets:** `design` is `null` (never chose: classic, unless a flag is `everyone` or lists them),
`"v2"` (tried the new design) or `"classic"` (went back). For each `ui_v2.*` flag, the first that applies wins:
1. `design` is `"classic"` → off (whatever the admin set; this is 2.0.0-a's `ui_classic`).
2. availability `everyone` → on.
3. `design` is `"v2"` and availability `opt_in` → on.
4. the person, their workspace or their share is on the admin's lists → on.
5. otherwise off.

- `GET /api/config` gains, next to `ui_flags` and `ui_classic`:
  ```json
  "ui_design": {"choice": null, "current": "classic", "can_try": true, "can_switch_back": false}
  ```
  `choice` is the person's `design`. `current` is `"v2"` when any of the nine console flags is on for them, else
  `"classic"`. `can_try` (show **Try the new design**): `current` is `"classic"` and choosing `"v2"` would turn at
  least one console flag on. `can_switch_back` (show **Switch back to classic**): `current` is `"v2"`. `ui_classic` stays
  and equals `choice == "classic"`. Missing on an older server: show only 2.0.0-a's switch, as before.
- `GET /api/account/ui` — `config:read` → `200 {"ui_flags": {…}, "ui_classic": false, "ui_design": {…}}`.
- `PUT /api/account/ui` — `config:read`. Body `{"design": "v2" | "classic" | null}` (`null`: back to the default) →
  `200`, the same document as the GET, already showing the new answers. `422 design_not_available` for `"v2"` when
  `can_try` is false and `current` is not already `"v2"` (nothing is open to try); `422` for any other value. The
  2.0.0-a body `{"classic": true | false}` still works: `true` is `"classic"`, `false` is `null`.
- The web console uses the same setting for the signed-in person: classic Settings › Appearance shows **Try the new
  design** while `can_try`, and v2 Settings › Appearance shows **Switch back to classic** while `can_switch_back`.
  The phone and desktop apps show the same two rows in their Settings › Appearance, re-read `GET /api/config` right
  after the `PUT`, and switch on the next screen the person opens, never in the middle of a recording.

### `GET /api/clients` — `library:read`
`200 {"data": [{"id": "01JB…", "name": "Acme Holdings", "color": "#8B5CF6", "avatar": "AH", "pinned": true, "retention_days": null, "keyterm_count": 12, "session_count": 40, "updated_at": "…"}]}`

Pinned folders come first, then by name. `avatar` (one emoji or up to two letters), `pinned` and `session_count`
are since v1.1; client folders are created and changed with `POST /api/clients` and `PATCH /api/clients/{id}`
(see "Organising the library").

---

## Live captions & dictation

### `POST /api/live-translate/token` — `stt:token`, throttled (30/min/device) (since v1.3)
Live Translate on the phone (Phase B: game voice chat and videos). Body `{"mode": "game"|"watch", "target_language":
"en", "language_hints": ["ar"]}` (two-letter codes). The server asks Soniox for a temporary key
(`transcribe_websocket`, 10 minutes) and answers `200 {"provider": "soniox", "api_key": "temp:…", "expires_at": "…",
"websocket_url": "wss://stt-rt.soniox.com/transcribe-websocket", "model": "stt-rt-v5", "translation": {"type":
"one_way", "target_language": "en"}, "language_hints": ["ar"]}` (`Cache-Control: no-store`). The phone streams to
Soniox directly and asks again before `expires_at`; no audio passes through the server and the account key never
leaves it. The answer also carries `budget`: `{"used_usd": 0.9, "limit_usd": 10.0, "warn": false, "reached": false}`,
Live Translate's own monthly budget (`live_translate.monthly_usd`, default $10; Game and Watch translate together,
not counted in the transcription limit). Errors: `403 budget_exceeded` (Live Translate's budget is used up; a workspace owner or admin may send
`"continue_over_budget": true` once they chose to continue this month, up to twice the budget; a member, or anyone past
that, gets `403 budget_exceeded` whatever the flag says), `503 provider_not_configured` (no
Soniox key), `502 provider_error` (Soniox refused: the message says whether the key, the credit or Soniox itself is
the problem).

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
  (keyterms included as repeated query params), `frame_ms: 50` (100 before 2026-11-18; the apps take 50 to 250), `init_messages: []`.
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
server answers 200/201; a `422` report is dropped. Since v1.5 a report counts only for streaming this device was
granted: the server notes each `stt/token` and `live-translate/token` it issues, and a device's reports since its first
grant of the last 48 hours cannot add up to more than the time since that grant (5 minutes' slack). A report past that is
stored with what was left, down to `streamed_ms: 0`, and still answers 200/201 so the device stops retrying. A viewer's
device answers `403 not_allowed`.
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
- `use_case` ∈ `live_captions` | `dictation` | `game_translate` | `watch_translate` (final transcripts are recorded by
  the server, never reported). Live Translate reports (since v1.3) use provider `soniox` only, with `streamed_ms` = the
  time the Soniox stream was open; they count against Live Translate's own budget, not the monthly limit, and the
  answer carries `live_translate` (same shape as the token answer's `budget`).
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
- **Imports (since v1.4):** `"skip_duplicate": true` (one-shot create only) asks the server to skip a file whose primary
  artifact has the same `sha256` as a live recording of the same workspace: `409 duplicate_recording` with
  `session_id` of that recording, and nothing is stored. A repeat with the same `client_ref` still answers `200` with
  the existing session. Deleted recordings do not count. Without the key the server stores every file, as before.

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
`label` and `pinned` (since v1.1, see "Organising the library"), and `important` (since 2.0.0-i).
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
                   "live_transcript_id": "01JB…", "preview": "first 160 characters…", "preview_view": "roman"},
    "marker_count": 2, "tags": ["Budget"], "pinned": false, "keep": false, "labels": ["Invoice"], "opened": false,
    "important": false, "label_colors": {"Invoice": "#16a34a"}, "expires_at": "2026-12-14T09:00:00+00:00",
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
`200 {"views": {"today": 2, "this_week": 9, "waiting": 0, "bookmarked": 4, "failed": 1, "pinned": 3}, "labels": [{"label": "Invoice", "count": 5, "color": "#16a34a"}]}`.
Counts match what `GET /api/library?view=…` lists. Each label's `color` (since 2.0.0-i) is `null` until one is chosen.
Since 2.0.0-m the labels come in the order of the person's lists (`GET /api/lists`); a label without a list comes after them, by name.

### Important, label colours and days left (since 2.0.0-i)
What a Library row shows beside the title and the time (docs/PLAN_V2_PARITY.md section 3):
- `important` (boolean, default `false`): the person flagged the recording. Set it with `PATCH /api/sessions/{id}`
  `{"important": true}` or `POST /api/sessions/bulk` with `important` / `unimportant`; `GET /api/library?important=1`
  lists the flagged ones (`0` the others). It changes nothing else (not the order, not retention).
- `label_colors`: `{label: "#rrggbb"}` for the item's labels that have a colour, the label spelt as on this recording
  (labels without one are left out; an empty object `{}` when none has). Labels belong to the person who made the
  recording, and so do their colours: every recording of theirs with that label (capitals ignored) shows it, and a
  teammate in the same workspace has colours of their own. A label without a colour is drawn in the apps' neutral
  colour; the colours the apps offer are the design system's (any `#rrggbb` is accepted).
- `expires_at`: when retention moves the recording to Trash (audio and text), worked out as the hourly clean-up does:
  `created_at` plus the project's own days, else the general setting; the plan's days (or the owner's own, set by the
  super admin) when they come first, for recordings made since the plan's retention was set. `null` when it is kept
  forever (`keep`), still `recording`, or on a tombstone. The clean-up runs hourly, so the move happens within an hour
  after `expires_at`. The apps show "N days left" with N = the hours left divided by 24, rounded up, and never below 0
  ("0 days left" on its last day); they work it out from `expires_at` and the device clock, so the number stays right
  between syncs. Changing `keep`, the recording's project or that project's days gives the recording a new
  `updated_at`; a change of the general setting or of a plan's days reaches the apps on their next full refresh.
- The unread dot is `opened: false` (since v1.3): the asking person has not opened the recording yet. Library lists and
  (since 2.0.0-i) the `PATCH /api/sessions/{id}` answer carry it; opening never changes `updated_at`.

### `PUT /api/labels/color` — `sessions:write` (since 2.0.0-i)
The colour of one of the asking person's labels in the device's workspace.
`{"label": "Invoice", "color": "#16A34A"}` → `200 {"label": "Invoice", "color": "#16a34a"}`; `"color": null` takes the
colour away (`200` with `"color": null`). `label` is matched ignoring capitals only (accents and other marks count:
"Résumé" and "resume" are two labels; "ÉTÉ" finds "Été") and cleaned as labels are (trimmed,
single spaces, ≤ 40); the answer spells it as the person's first recording with it does. `color` is `#rrggbb`, kept and
returned in lower case as a project's colour is. A label none of the person's recordings in this workspace carries is
`404 label_not_found` (a teammate's or another workspace's label counts as none). Every recording of theirs with the
label gets a new `updated_at`, so the other devices pick the colour up on their next sync. `422 validation_failed` for
an empty label, a missing `color`, or a colour that is not `#rrggbb`. A viewer's device gets `403 not_allowed`, as for
every other change.

### Lists (since 2.0.0-m)
The apps' row of lists (All · Unread · Important · the person's lists · +). A list is one of the person's labels with its
name as typed, its colour and its place in the row; there is no other model. Like labels and their colours, lists belong
to the person who made the recordings in the device's workspace: a teammate has lists of their own, and another person's
list is answered like an unknown one. All, Unread and Important are the apps' own chips, never lists.

- **The list:** `{"key": "clients", "name": "Clients", "color": "#e15f56", "position": 0, "count": 3}`. `key` is the
  name in lower case (single spaces); it names the list in the paths below (URL-encoded) and in every label it matches,
  capitals ignored only, as label colours are ("Résumé" and "resume" are two lists). `name` is cleaned as a label is
  (trimmed, single spaces, at most 40 characters). `color` is one of the eight label colours (`#8e8a80` grey, `#e15f56`
  red, `#dc6718` orange, `#b3820f` amber, `#2d9a63` green, `#26978f` teal, `#458cde` blue, `#d7609c` pink), or `null`
  for a list made by labelling, or a colour set before 2.0.0-m (any `#rrggbb` through `PUT /api/labels/color`). `position` counts from
  0 with no gaps. `count` is how many of the person's recordings, not in Trash, carry the label.
- **Labelling makes the list:** a label given through `PATCH /api/sessions/{id}` `labels` or `POST /api/sessions/bulk`
  `label` that is new to the person is a new list at the end of the row, without a colour. Taking the label off every
  recording keeps the list, empty.
- **A label spelt as a fixed chip** (All, Unread or Important, in English or Urdu, capitals ignored; written before
  2.0.0-m or given through `labels`) stays on its recordings and in `labels` but is never a list: neither labelling,
  `GET /api/lists` nor the upgrade makes one, and `/api/lists/{key}` answers `404 list_not_found` for it. Its colour is
  kept as before 2.0.0-m (`PUT /api/labels/color`, `label_colors`).
- **Names:** a list can't be called All, Unread or Important, in English or in the Urdu the apps show (سب, نہ پڑھی گئی,
  اہم), capitals and spaces ignored → `422 name_reserved`. A name another list of the person's has, capitals and spaces
  ignored → `409 list_exists` with `name`, that list's name ("You already have a list called Clients."). The same
  answer, with nothing changed, when another device takes the name in the same moment, or when the database holds the
  new name as a label one of the recordings already has (MySQL compares labels ignoring accents too: renaming a list
  to "Resume" when a recording in it also carries "Résumé"). An empty name or
  one over 40 characters → `422 validation_failed`. A colour outside the eight → `422 validation_failed`.
- Every change gives each recording it touches a new `updated_at`, so the other devices sync its labels and
  `label_colors`. The audit log records `list_created`, `list_renamed` and `list_deleted` (staff see the event's name, not
  the list's).

### `GET /api/lists` — `config:read` (since 2.0.0-m)
`200 {"data": [<list>]}` in `position` order. A label of the person's recordings (in Trash too) that has no list yet gets
one at the end first, unless it is spelt as a fixed chip.

### `POST /api/lists` — `sessions:write` (since 2.0.0-m)
`{"name": "Clients", "color": "#e15f56"}` (`color` optional) → `201 {"data": <list>}`, empty (`count` 0), at the end of
the row. Without `color` the list takes the one of the eight fewest of the person's lists have, the earliest in the order
above on a tie. `name_reserved`, `list_exists` and `validation_failed` as above.

### `PATCH /api/lists/{key}` — `sessions:write` (since 2.0.0-m)
`{"name": "Customers", "color": "#d7609c"}`, either or both → `200 {"data": <list>}`. A new name is the label's new
spelling on every recording of the person that carries it, those in Trash too, all in one transaction: either every
recording changes or none does. A change of capitals alone is allowed; a recording that carries the label twice in other capitals keeps one.
`404 list_not_found`; the name checks as above (`409 list_exists` also for a clash found while saving).

### `DELETE /api/lists/{key}` — `sessions:write` (since 2.0.0-m)
The label comes off every recording of the person that carries it (in Trash too), in one transaction, and the list goes;
the recordings stay. The lists after it move up one. `200 {"key": "clients", "changed": 3}` (`changed`: the recordings
that lost the label). `404 list_not_found`.

### `PUT /api/lists/order` — `sessions:write` (since 2.0.0-m)
`{"keys": ["family", "clients", "home"]}` → `200 {"data": [<list>]}` in the new order. `keys` names every list of the
person exactly once (as keys or names, capitals ignored); a list left out, named twice, or not the person's →
`422 validation_failed` and nothing moves.

### `PATCH /api/sessions/{id}` — `sessions:write` (since v1.1)
Body, every field optional: `title` (≤ 200; empty clears it), `client_id` (a client of the owner, `null` = no
client), `pinned` (boolean), `keep` (boolean, since B17: never deleted by retention), `important` (boolean, since 2.0.0-i) and `labels` (the complete list: trimmed, single spaces, ≤ 40 characters, duplicates
ignoring capitals and blanks dropped, at most 20 → else `422 too_many_labels`). Returns `200 {"data": <library item>}`.
`404 session_not_found`; another owner's client is `404 client_not_found`.

### `POST /api/sessions/bulk` — `sessions:write` (since v1.1)
`{"ids": ["01JB…"], "action": "move|label|unlabel|pin|unpin|keep|unkeep|important|unimportant|delete", "client_id": "01JB…" | null, "label": "Invoice"}`
(`important` / `unimportant` since 2.0.0-i).
Up to 200 ids; ids of other owners are ignored. `move` uses `client_id` (`null` = no client), `label` / `unlabel`
need `label`, `delete` makes tombstones exactly like `DELETE /api/sessions/{id}`. `200 {"changed": 2}`.

### `POST /api/sessions/export` — `library:read` (since v1.1)
`{"ids": [...], "format": "txt|md|srt|vtt|json|docx|html"}` (`html` since 2.0.0-h, as `GET /api/transcripts/{id}/export` has it) → `200 application/zip` with one file per finished final
transcript (names as in `GET /api/transcripts/{id}/export`, numbered when two match) and the header
`X-Export-Count`. Recordings without a finished transcript are skipped; when none has one, `409 nothing_to_export`.
Since v1.4 an optional `"view"` (roman | english | urdu) exports each transcript in that Show as view when it is
ready (the others keep their own words), with the same unsure-word markers.

### `POST /api/clients` — `sessions:write` (since v1.1)
`{"name": "Acme", "color": "#22d3ee", "avatar": "AC", "pinned": false}` → `201 {"data": <client>}`. `name` is
required and unique per owner (≤ 120), `color` is `#rrggbb`, `avatar` keeps one emoji or two letters (upper case).

### `PATCH /api/clients/{id}` — `sessions:write` (since v1.1)
Any of `name`, `color`, `avatar`, `pinned`, `retention_days` (1-365, `null` = the general setting) →
`200 {"data": <client>}`; `404 client_not_found`.
### Projects (since v1.5)
The apps call client folders **Projects**; the API keeps the name `clients`, and everything above still holds. A
project can sit inside another one (subfolders, at most 4 levels), and recordings that are in no project are the
**Inbox**.

- Client objects (in `GET /api/clients`, `POST`/`PATCH /api/clients…` answers) gain `parent_id` (string or `null` =
  top level), `path` (the ancestors from the top: `[{"id": "01JB…", "name": "Acme"}]`, empty at the top),
  `children_count` and `nested_session_count` (recordings in it and its subfolders; `session_count` stays the
  recordings directly in it).
- `POST /api/clients` and `PATCH /api/clients/{id}` accept `parent_id` (a project of the same workspace, or `null`).
  A move under itself or one of its own subfolders is `422 invalid_parent`; deeper than 4 levels is `422 too_deep`.
- `DELETE /api/clients/{id}` — `sessions:write`: deletes the project. Its recordings go to the Inbox (nothing is
  deleted), its subfolders move up to its parent, and its files, brief, notes, memory and rules go with it.
  `200 {"moved_sessions": 12, "moved_projects": 2}`; `404 client_not_found` (since v1.5 also for a project someone
  else made: projects are private to their maker).
- `GET /api/library` gains `folder` (the smart folders, computed views and never copies): `inbox` (no project),
  `whatsapp`, `whatsapp_business`, `google_meet`, `zoom`, `teams`, `phone` (SIM calls), `voice_notes`, `imported`
  (files imported on a device, uploaded on the console or sent through a request link) and `shared` (recordings
  others sent you, see "Sending to a teammate"). With `client_id`, `include_subfolders=1` also lists the recordings
  of its subfolders. `GET /api/library/views` gains `folders`: `{"inbox": 4, "whatsapp": 31, …, "shared": 1}`.
- Library items gain `filed_by`: `user` (moved by hand or chosen when recording), `contact` (the caller is one of the
  project's contacts), `rule` (a filing rule) or `null` (Inbox).

### Filing rules (since v1.5)
A recording that ends without a project is filed by the first match: the caller is one of a project's contacts
(as since v1.4), then a `title` rule (the title contains the text, ignoring capitals; desktop titles come from the
meeting window), then an `app` rule (`source_app`); otherwise it stays in the Inbox. A rule never moves a recording
that is already in a project.

- `GET /api/clients/{id}/rules` — `library:read` → `200 {"data": [{"id": 7, "kind": "title", "value": "Acme", "created_at": "…"}]}`.
- `POST /api/clients/{id}/rules` — `sessions:write`: `{"kind": "title" | "app" | "contact", "value": "Acme"}`
  (`contact` is a phone number or an email and adds a contact; `app` is a `source_app` wire value; ≤ 120
  characters) → `201 {"data": <rule>}`; the same rule again on the same project → `200` with the existing rule. The
  same kind and value on another project is `409 rule_exists` (with `client_id` and `client_name` of that project).
  Within a kind the oldest rule wins.
- `DELETE /api/clients/{id}/rules/{rule}` — `sessions:write` → `204`; `404 rule_not_found`.

### Share links (since v1.5)
Share links can now be for a project too, and say who may open them.

- `POST /api/sessions/{id}/shares` and the new `POST /api/clients/{id}/shares` — `sessions:write` take, besides
  the v1.1 fields (`expires_in` 3600 | 86400 | 604800, `password`, `include_audio`, all still accepted), `audience`:
  `public` (anyone with the link, the default and what older apps get), `password` (anyone with the link and
  `password`, 6–100 characters; a `password` given without `audience` means `password` as in v1.1) or `people`
  (`people`: up to 20 emails; they open it signed in to the console with one of those emails), `allow_download`
  (`true`: audio and transcript files can be downloaded; `false` = read and listen only; when only the v1.1
  `include_audio` is sent it decides), `expires_at` (ISO, optional, instead of `expires_in`; neither = no expiry
  for the new fields, 24 h for a v1.1 body) and `view` (as before). The answer stays as in v1.1, not wrapped:
  `201 {ShareLink, "url": "https://…/s/…"}` (or `/p/…` for a project), `url` shown once.
- ShareLink gains `kind` (`session` | `project`), `client_id` (a project's link; `session_id` is then null),
  `audience`, `people` (the emails, only for `people`) and `allow_download`; the v1.1 fields stay (`has_password`
  is true for `password`, `include_audio` mirrors `allow_download`).
- `GET /api/shares` — `library:read`: every share of the workspace's recordings and projects, newest first
  (`?session_id=` or `?client_id=` to narrow) → `200 {"data": [ShareLink]}`; `GET /api/sessions/{id}/shares` and
  `DELETE /api/shares/{id}` work as before.
- Pages: `/s/{token}` (a recording, as before) and `/p/{token}` (a project: up to 500 of its recordings, newest
  first, each opening at `/p/{token}/{recording}` as `/s/…` would). With `allow_download`, `/s/{token}/download`
  gives a ZIP of the audio and the transcript (in the link's view). People-only links ask for the console sign-in
  first and let in only the listed emails (compared without capitals; members of the workspace are not let in
  unless listed). `GET /api/shares` returns the newest 1,000.

### Sending to a teammate (since v1.5)
- `POST /api/sessions/{id}/send` and `POST /api/clients/{id}/send` — `sessions:write`: `{"to": ["ana@example.com"],
  "note": "for tomorrow"}` (up to 20 emails; `note` ≤ 500; throttled to 30 sends an hour per person) →
  `201 {"sent": 2}`, the number of different addresses asked. So that this route cannot be used to learn who has an account, an
  address with no confirmed account on this server answers the same and simply receives nothing; apps say so ("People
  without an account here will not receive it"). `recipient_not_found` is no longer answered.
- **What a sent project holds:** the recordings in it (and in its subfolders) when it was sent, while they stay there.
  A recording filed in it later is not sent until the project is sent again; its brief, notes and files stay current.
  Saving a copy keeps it whatever happens next.
- `GET /api/sent?session_id=…` or `?client_id=…` — `library:read` (one of the two is required, else `422`): what you
  sent of that recording or project, newest first: `{"data": [{"id": "01JD…", "kind": "session" | "project", "to": {"name": "Ana", "email": "…"}, "title": "Call
  with Omar", "note": "…", "sent_at": "…", "saved_at": null}]}`.
- `DELETE /api/sent/{id}` — `sessions:write`: **stop sharing** what you sent → `204`; it leaves the person's list and
  they can no longer open it. A copy they already saved stays theirs. Someone else's id is `404`.
- Removing a person from a workspace ends what was sent to them from that workspace (copies they saved stay theirs) and
  what they shared by link there. An account in its 7-day deletion grace serves no links until it cancels.
- `GET /api/shared-with-me` — `library:read`: `{"data": [{"id": "01JD…", "kind": "session" | "project", "from":
  {"name": "Hassam", "email": "…"}, "note": "…", "title": "Call with Omar", "sent_at": "…", "saved_at": null,
  "session": <library item, read-only> | null, "client": <client> | null}]}`. Received recordings are also in the
  `shared` smart folder (`GET /api/library?folder=shared`), not in the normal library sync. They can be played and
  read, not edited: `GET /api/sessions/{id}` (with `client`, `device` and `filed_by` null), `POST
  /api/sessions/{id}/opened`, `GET /api/transcripts/{id}`, `GET /api/transcripts/{id}/views` and the signed
  `playback_url` work; every edit, export and share route answers `404`.
- The recipient's activity gets `shared_with_you` ("Ana sent you Call with Omar", the note as its text);
  `session_id` is the sent recording (opened read-only), null for a project.
- `POST /api/shared-with-me/{id}/save` — `sessions:write`: copies it into your own workspace (a recording with its
  audio, final transcript and views; a project with its recordings) → `201 {"session_id": "…"}` or
  `{"client_id": "…"}`; the copy is yours and independent of the original.
- `DELETE /api/shared-with-me/{id}` → `204` (takes it off your list; the sender's recording stays).

### Request links (since v1.5)
- `POST /api/request-links` — `sessions:write`: `{"title": "Send me your voice notes", "client_id": "01JB…" | null,
  "expires_at": "…", "max_files": 20, "max_mb": 100}` → `201 {"data": {"id", "url", "title", "client_id",
  "expires_at", "max_files", "max_mb", "received": 0, "revoked_at": null}}` (`url` shown once). `GET
  /api/request-links` lists them; `DELETE /api/request-links/{id}` revokes.
- `/r/{token}` is a public upload page (throttled, no account): audio and video (opus, ogg, mp3, m4a, aac, wav, mp4,
  webm) become recordings in the link's project or the Inbox, titled from the file name; with a project, PDF,
  DOCX, TXT and images (PNG, JPG, WebP) go to its Files. Other types, files over `max_mb` or more than `max_files`
  are refused with a plain message. The page's upload calls answer `413 too_large`, `422 too_many_files`, `422
  unsupported_type`, `409 upload_finished` and `403 storage_limit_reached`; types are checked by content (the
  console's upload set, so also FLAC, AMR and OGA). `max_files` counts files in progress and finished; `received`
  counts finished ones.

### Links in the apps (since v1.5)
`/.well-known/assetlinks.json` lets Android open `/s/…`, `/p/…` and `/r/…` links of this server in the phone app
when it is installed; the desktop app registers `echoledger://open?link=<url>` and the share page offers "Open in
EchoLedger". Without the app the web page opens as before.

`GET /api/links/resolve?url=<a /s/, /p/ (also /p/{token}/{recording}) or /r/ link of this server>` — `library:read`: what the link is for the
caller, so the app can open it in place: `200 {"kind": "session", "session_id": "…"}` (a recording in the caller's
own library), `{"kind": "project", "client_id": "…"}` (one of the caller's projects), `{"kind": "shared",
"shared_id": "…"}` (something a teammate sent the caller, a recording or a project: its item in Shared with me) or
`{"kind": "request", "request_link_id": "…"}` (one of the caller's request links). Anything else — someone else's link, revoked, expired, unknown — is `404 not_found`, and the app opens the
web page instead. The token is checked like the page checks it; nothing about other workspaces is told.

### Screen moments (since v1.5)
During a meeting the desktop app keeps what was on the shared screen at each moment ("screen moments"), linked to the
recording's timeline, so the transcript and an AI reading it know what "this page" or "click here" meant. Screens
are stored encrypted like recordings, go with the recording (retention, the undo bin, delete) and are never public
unless a share link of the recording allows downloads.

- `POST /api/sessions/{id}/screens` — `sessions:write`, throttled (uploads limiter): multipart with `image` (WebP,
  PNG or JPEG, at most 3 MB; checked by content), `at_ms` (the moment in the recording, 0 or more), `width`,
  `height`, `trigger` (`change` = the picture changed and settled, `words` = someone said "this page", "click
  here", "yeh dekhein" …, `manual` = the person pressed the button) and `sha256` (of the image; the same image twice
  for one recording is `200` with the existing screen, not a copy). → `201 {"data": <screen>}`. At most 400 screens
  per recording (`409 too_many_screens`); a recording in the undo bin is `404 session_not_found`. `at_ms` may be at
  most the recording's length (else its audio's, else the time since it started) plus 60 s; `width` and `height` are
  1 to 16384; a picture over 25 megapixels is refused (`422`). The server needs PHP's GD with WebP and an upload
  limit of at least 4 MB (web/DEPLOY.md).
- A screen: `{"id": "01JE…", "index": 7, "at_ms": 872000, "width": 1920, "height": 1080, "trigger": "change",
  "ocr_text": "Invoice #2041 …" | null, "description": "The client's invoice page with the Pay button" | null,
  "image_url": "…", "thumb_url": "…", "created_at": "…"}`. `index` numbers the screens of the recording in time
  order from 1 ("Screen 7"). `image_url` and `thumb_url` are signed links valid for 30 minutes (thumbnails are 480 px
  wide), on the web route `/screen-media/{screen}/image|thumb` (like `/s/` links, not under `/api/`): fetch them with no
  token; they start no session and set no cookie. The picture keeps the type it was sent in (WebP, PNG or JPEG).
- `GET /api/sessions/{id}/screens` — `library:read` → `200 {"data": [<screen>]}` in time order;
  `DELETE /api/sessions/{id}/screens/{screen}` — `sessions:write` → `204` (the others keep their numbers until the
  list is read again, then they are renumbered).
- Text and a one-line description per screen (`ocr_text`, `description`) are made on the server with Claude Haiku
  5.5 when Settings → Screen moments → "Describe screens" is on (the default); they make screens searchable
  (`GET /api/search` also finds screen text) and are billed to the workspace's AI usage. Off: both stay `null`.
  A screen hit in `GET /api/search` has the line-hit shape plus `"kind": "screen"` and `screen_id`: `speaker`
  "Screen 7", `start_ms` = `end_ms` = `at_ms`, `segment_index` = minus the screen number, `transcript_id` the
  recording's final (else live) transcript, so apps that only know line hits open the recording at that moment.
- A recording a teammate sent: its screens can be listed and seen like its transcript, never sent or deleted, and
  its meeting pack is `404` (as every export of a sent recording).
- `GET /api/clients/{id}/screens?include_subfolders=0|1&cursor=` — `library:read` → `200 {"data": [<screen> +
  "session_id", "session_title", "session_started_at"], "next_cursor": "…" | null}`: the project's Screens tab, newest
  recording first and each recording's screens in time order, 60 per page.
- Library items and `GET /api/sessions/{id}` gain `screen_count`.
- Projects (client objects) gain `screens` (bool, default `true`): `false` = the desktop does not capture screens for
  meetings filed under that project. `PATCH /api/clients/{id}` accepts it.

### Meeting assets (since 2.0.0-e, v1.8 P4)
- Every screen (`POST /sessions/{id}/screens`, `GET /sessions/{id}/screens`, project screens) gains `file_name` (e.g.
  `S07_14-32_invoice-page.webp`: `S<number, at least 2 digits>_<mm-ss or h-mm-ss>_<slug of the description, up to 40
  characters, or "screen">.<ext>`, its name in the ZIP's `screens/` folder) and `said` (the transcript lines within 15 s
  of the screen as `Speaker: text`, one per line, at most 600 characters ending in `…` when cut; the final transcript
  when there is one, else the latest live one; `null` without a transcript).
- Near-duplicates: a picture that matches the previous or next screen of the same recording (same claimed size, within
  5 minutes, at most 2 pixels differing by more than 12 shades of grey at 640 px) is that screen: `200` with the
  existing screen, as for a byte-identical picture. A picture not the size it claims, above 25 MP or longer than 4 to 1
  is never compared and is kept as its own screen.
- `503 server_busy` (since 2.0.0-f): `POST /sessions/{id}/screens` and `POST /upgrade-requests/{id}/proofs` when the
  server is already decoding as many pictures as it allows. Nothing is kept; `Retry-After: 5`. Apps send the same picture
  again later; it is not a failure of the picture.
- At most 400 screens a recording and at most 120 in any hour of it; both answer `409 too_many_screens` ("This
  recording already keeps 120 screens in an hour."). Thumbnails are 320 px wide WebP from v1.8 (480 before; payment
  receipts stay 480).
- `GET /sessions/{id}/pack?format=html|zip|text`. `text`: a plain-text file for an AI (a one-line note, the title and
  facts, then `[mm:ss] Speaker: text` lines with `[Screen N at mm:ss — description]` lines in time order). `zip`: the
  pictures in `screens/` (was `images/`) under their `file_name`, a `screens.md` table (`# | Time | File | Description |
  What was said`), and in `transcript.md` each screen as `**[Screen N at t — description]**` followed by its image.
  `html`: parts of about 100 printed pages; `part` (from 1, default 1) picks one, out of range `404`; every answer has
  `X-Pack-Part` and `X-Pack-Parts`; a file with more parts is named `… (part k of n).html`.
- Labels everywhere (pack, ZIP, text, connector): `Screen N at t — description`, or `Screen N at t` without one.

### Meeting pack (since v1.5)
- `GET /api/sessions/{id}/pack?format=html|zip&view=roman|english|urdu|original&include_audio=0|1` — `library:read`:
  - `html`: one self-contained page, ready to print: a title page (title, date, length, people, project), then the
    transcript in the view, each screen placed at its moment with the label "[Screen 7 at 14:32]" (images embedded,
    print CSS, Urdu in Noto Nastaliq right to left). The apps make the **PDF** from it the way transcript PDFs are
    made today (the print dialog's "Save as PDF"), so Urdu letters are shaped correctly; Claude and ChatGPT read PDF
    pages as text and images.
  - `zip`: `transcript.md` (the same order, each screen as a Markdown image titled "Screen 7 at 14:32" that points to
    `images/screen-07.webp` (`.png` or `.jpg` for a screen sent in that type; three digits past 99 screens), with its
    description), the `images/` folder, and with `include_audio=1` the recording's audio, stored uncompressed in the
    ZIP. Past 20 MB of pictures the `html` pack embeds every screen's 480 px thumbnail instead.
  - `409 nothing_to_export` without a finished transcript. Names like `GET /api/transcripts/{id}/export`.
- "Copy for AI" (in the apps) puts `[Screen N at mm:ss: <description>]` lines between the transcript lines at the
  screens' moments; the apps build it from `GET /api/sessions/{id}/screens`.

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
v1.1, `null` unless `failed`; since v1.5 also `backup_engine`, `backup_reason`, `retry_at` and `retry_reason`, as in
`GET /api/transcripts/{id}`), `"markers"` and `"pauses"` (since v1.1, shapes as above), `"speaker_names"`
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
- **Since v1.5:** `backup_engine` (bool), `backup_reason` (`waited`, `rejected` or `null`), `retry_at` (ISO 8601 or
  `null`) and `retry_reason` (`rate_limited`, `unavailable` or `null`). Urdu or mixed speech (a recording whose
  language is Urdu, or not chosen) never falls back to a weaker engine for a passing problem: when its engine is rate
  limited (HTTP 429, or the provider says rate limit or quota) or down (a 5xx, unreachable, timed out, interrupted),
  the final transcript stays `pending`, the session `processing`, and the same engine is tried again after 2, 4, 8,
  16 and then every 30 minutes (or when the provider's `Retry-After` says), for up to 6 hours after the first failure
  (console: Settings → Engine per language, "Urdu waits for its best engine up to N hours"). Meanwhile `retry_at`
  says when and `retry_reason` why, and no `transcript_failed` activity is posted. After the wait the next engine of
  the routing table writes it, with `backup_engine: true` and `backup_reason: "waited"`; an engine that refuses the
  recording hands it on at once (`"rejected"`). Apps show "Backup engine — Re-transcribe?";
  `POST /api/sessions/{id}/retranscribe` with the same language tries the preferred engine again. A finished
  transcript has `retry_at` `null`. English, Arabic and the other languages still go to the next engine at once
  (`backup_engine` stays `false`). The four fields are also on the transcripts of `GET /api/sessions/{id}` and on
  every response that returns a transcript (`PATCH /api/transcripts/{id}`, replace, restore, suggestions,
  `POST /api/sessions/{id}/transcripts`). Older servers send none of them: read a missing field as `false` / `null`.

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

- **Since v1.4:** for a recording filed under a client the answer also carries top-level `"learn": [{"from": "la
  ravel", "to": "Laravel", "client_id": "01JB…", "client_name": "Acme"}]`: the words the edit changed (1–4 words, not
  just punctuation, at most 3, none the client already has a rule for). Apps ask "Always write *la ravel* as
  *Laravel* for Acme?" and, on yes, send `POST /api/vocabulary/replacements` with that `client_id`. Empty for other
  recordings.

### `POST /api/transcripts/{id}/suggestions/{suggestion}` — `sessions:write` (since v1.4)
**Suggested fixes.** After a client's call notes are written, Claude compares the transcript with the client's
vocabulary (keyterms, contacts, the people and terms of its brief) and flags likely mishearings. A transcript lists
the open ones as `"suggestions": [{"id": 0, "index": 1, "from": "la ravel", "to": "Laravel", "reason": "framework",
"status": "open"}]` (`index` = the segment). Nothing changes until a person decides: `{"accept": true}` replaces the
words in that segment (an edit, with a revision); `{"accept": false}` dismisses it. `200` transcript;
`404 suggestion_not_found` when it is unknown or already decided.

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

### `POST /api/sessions/{id}/opened` — `library:read` (since v1.3)
The device's user has opened this recording: `204`. Idempotent (opening again only moves the time); it never changes
the recording, so it does not reorder or re-sync the library. Library items carry `"opened"` for the asking user
(`false` until then), which the apps show as a dot; the console counts viewing a recording's page as opening it.
Another workspace's id → `404`.

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
(Since 2.0.0-j:) on an `audio_only` recording it starts the first transcript exactly as `POST /sessions/{id}/transcribe`
does (budget, key, the same errors) and is throttled like it (30/min/device); the new language is kept only when the
transcript starts.

### Audio only and "Transcribe later" (since v1.8, contract 1.8.0-e; served since 2.0.0-j)
v1.8 P1: when Ready, a call is always recorded as audio, but transcribed only with the person's permission (the
after-call card: Transcribe · Save audio · Delete; no answer within 12 s = Save audio).
- `POST /api/sessions` (one-shot create) and `POST /api/sessions/{id}/finish` take `"transcribe": true | false`.
  Absent = `true`, so older apps keep today's behaviour. With `false` the recording and its screens upload as usual,
  no transcript is started, and when the upload completes the session's status is `audio_only`. A repeated create or
  finish (idempotency) never changes the stored choice. (Since 2.0.0-j:) the open create (`POST /api/sessions` without
  `artifacts`) takes it too; a `transcribe` sent with the finish replaces it.
- Audio-only recordings count for storage, never for minutes; minutes are counted only when a transcript runs.
- Every Library item carries `transcribe_estimate`: `null` unless the status is `audio_only`, then
  `{"minutes": 5, "minutes_left": 120}` — the minutes a transcript would use (the recording's length rounded up, times
  the billed channels of the engine the routing would pick) and the minutes left this month for this workspace
  (`null` = no limit). These are plan minutes: `0` when it would run on the person's own key (Free) or an on-device
  engine, which count for none (since 2.0.0-j). Only the person whose recording it is sees it: on a recording a
  teammate sent (the `shared` folder) it is `null`.
- `GET /api/library?status=audio_only` lists them (the apps' "Not transcribed" smart folder and filter).
- `POST /api/sessions/{id}/transcribe` — `sessions:write`, throttled (30/min/device): starts the transcript of an
  `audio_only` session with the usual routing → `202 {"session": { …status "processing"… }, "transcript": {"id",
  "kind", "provider", "status", "language"}}`. `409 not_audio_only` when the session is not `audio_only` (already
  transcribed or still uploading); `403 budget_exceeded` (`minutes_needed`, `minutes_left`) when the month's limit
  can't cover it, and nothing starts; `403 needs_transcription_key` as for any transcript on Free.
- `POST /api/sessions/transcribe` — `sessions:write`: bulk "Transcribe selected", `{"ids": ["…"]}` (1–50) →
  `202 {"started": ["…"], "skipped": [{"id": "…", "code": "not_audio_only"}], "minutes": 42}`. All or nothing for the
  budget: when the total exceeds what's left, `403 budget_exceeded` with `minutes_needed` and `minutes_left`, and
  nothing starts. Ids of another workspace are skipped as `not_found`. (Since 2.0.0-j:) so are a teammate's, as only
  the person's own recordings are theirs to transcribe; an id sent twice counts once; on Free without an own key the
  answer is `403 needs_transcription_key` and nothing starts. Throttled like the one-recording call (30/min/device).
  A recording whose transcript cannot be handed to the engine stays `audio_only` and is skipped as `not_started`;
  the others still start (try it again later).
- (Since 2.0.0-j, for every start:) the minutes left count the transcripts already started and not finished at their
  estimate (the month's usage counts a transcript only when it completes), and starts of one workspace run one at a
  time: a start that waits more than a few seconds for another is `429 rate_limited` with `Retry-After`. A server
  that cannot keep starts apart answers `503 server_busy` (`Retry-After: 60`) and starts nothing. A start that fails
  inside the server (`5xx`) leaves the recording `audio_only` with no transcript running, to be started again.
- `POST /api/sessions/transcribe-estimate` — `library:read`: `{"ids": ["…"]}` (1–50) → `200 {"minutes": 42,
  "minutes_left": 120, "items": [{"id": "…", "minutes": 5}]}` (no side effects). `items` lists the person's
  audio-only recordings among the ids, the ones bulk would start (since 2.0.0-j), and `minutes` is their sum.
- Projects: `transcribe_calls` = `"ask"` (default) | `"always"` on the project (`GET /api/clients`, `PATCH
  /api/clients/{id}`): "Always transcribe calls in this project". An app that files a call into such a project sends
  `"transcribe": true` without asking.
- App rules, the same on every app (not server data): the "After a call" setting is `ask` (default) |
  `always_transcribe` | `always_audio`; v1.5's "Keep automatically" and "Ask me" both migrate to `ask` (the new rule
  is permission first), and a person who wants the old behaviour picks Always transcribe. "Always transcribe calls with
  this contact" is kept on the phone only and never sent.

### `GET /api/transcripts/{id}/views` — `library:read` (since v1.2)
"Show as": reading views of a finished transcript, made with the Claude API (text only, no audio) and kept with
the transcript: `roman` (Roman Urdu), `english` and `urdu` (translations).
`200 {"status","available","roman","english","urdu","default","model","made_at","error"}`: `status` is `unavailable`
(not finished), `none`, `making`, `ready` or `failed` (with `error`); `available` lists the views this transcript can
have (`roman`, `urdu` and `english` for Urdu, `urdu` for English, `english` and `urdu` for Arabic, `english` for the
other languages; as made since v1.3);
when ready, each available view is a list aligned with the segments (`null` for an empty line); `default` is the view
the apps open it in (console setting, `original` unless that view is available). An edit to the transcript makes the
views stale (`none`) until they are made again. Every transcript carries `views: {"available", "status", "default"}`.
Since 2.0.0-n (U19): `making` also while the default view is about to be made: for 20 minutes after the transcript is
finished or edited, when it opens in a view, a key is set and the plan has views (a short note is checked by a second
engine first, so its job starts a little later); `progress` stays `{}` until the job has counted the lines. A `making`
that moved no line for 20 minutes, and a default view that never started within 20 minutes of the transcript (up to 24
hours after it; older ones read `none`), read `failed` with `error` "Converting took too long. Try again.", and `POST`
starts it again. Apps show "Converting to Roman Urdu… 60%" (with a spinner) while `making`, the percent once `progress` has it,
and "Couldn't convert to Roman Urdu · Try again" on `failed`. Copies (Copy text, with timestamps, Copy for AI, WhatsApp
text) use the default view once it is `ready` and the original words before; Copy for AI's header then says
`Language: Roman Urdu`.

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
**Since v1.4** the client's contacts' names and the people and glossary terms of its brief ("Ali (owner)" → `Ali`,
"Laravel: the PHP framework" → `Laravel`) follow its keyterms. A call whose caller is a person (not a window title or
calendar entry) names the far side after them: the session starts with `speaker_names.Client` = the caller's name,
unless a name was already given. Double-check (`accuracy.double_check_clients`, on by default) also runs for every
recording filed under a client when the general switch is off.

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

## Client workspaces (since v1.4)

Each client has a workspace: files and pasted instructions, a one-page **brief**, **call notes** after every kept
recording filed under the client, a living **memory**, **Ask** and a **Copy for AI** bundle. Claude (the console's
Anthropic key) writes the brief, notes and answers; nothing is trained on them (docs/research/anthropic-data-use.md).
Every Claude request is logged per client (`usage_ledger.client_id`, use cases `client_notes`, `client_brief`,
`client_file`, `client_ask`) and counts against the workspace's monthly AI budget (`client_ai.monthly_usd`, default
$5): once it is used up, requests answer `403 ai_budget_reached` and automatic notes are `skipped` with that message.
Without a key: `409 ai_unavailable`. Client payloads (`GET /api/clients`, `PATCH /api/clients/{id}`) carry
`ai_notes` (boolean, default `true`); `PATCH` accepts it. With `ai_notes` off no automatic notes are written.

**Call notes.** When a recording of a client with `ai_notes` on gets its final transcript, its notes are written on
the queue (`status` `pending` → `ready` | `failed` | `skipped`). Only sections with content are listed, in this order
and with these headings: `asked_for` (Asked for), `changes` (Changes; each item has `kind` add | remove | modify),
`new_items` (New, not in the brief), `decisions` (Decisions), `to_confirm` (To confirm with the client), `numbers`
(Deadlines, prices, numbers), `my_actions` (My action items). Every item: `text`, `verify` (true = check it; the
transcript was unclear), `lines` (indexes into the transcript's segments) and `at_ms` (the moment of the first one).
An item that points at no real line is dropped. Decisions, changes and points to confirm also become memory lines.

### `GET /api/clients/{id}/workspace` — `library:read` (since v1.4)
```json
{"data": {
  "client": {"id": "01JB…", "name": "Acme", "ai_notes": true},
  "ai": {"configured": true, "spent_usd": 0.42, "budget_usd": 5, "client_spent_usd": 0.12},
  "brief": {"sections": {"goal": "An online shop", "scope": ["Login"], "constraints": [], "deadlines": ["Nov 15"],
            "budget": [], "people": ["Ali (owner)"], "glossary": []},
            "edited": false, "status": "ready", "error": null, "generated_at": "…", "updated_at": "…"},
  "memory": [{"id": "01JC…", "kind": "decision", "text": "Launch on November 15", "happened_on": "2026-10-30",
              "pinned": false, "source": {"type": "call", "id": "01JB…", "at_ms": 125000}}],
  "notes": [{"id": "01JD…", "session_id": "01JB…", "status": "ready", "error": null, "language": "en",
             "sections": [{"key": "decisions", "heading": "Decisions",
                           "items": [{"text": "Launch on November 15", "verify": false, "lines": [3], "at_ms": 125000}]}],
             "created_at": "…", "session": {"id": "01JB…", "title": "Kick-off", "started_at": "…", "duration_ms": 1800000}}],
  "files": [{"id": "01JE…", "name": "scope.pdf", "mime": "application/pdf", "size_bytes": 182340, "pages": 4,
             "status": "ready", "error": null, "instructions": false, "created_at": "…"}]
}}
```
`memory` lists pinned lines first, then the newest; `notes` the last 10; `files` newest first. `kind` is scope |
decision | question | change; `source.type` is call (with `at_ms`) | file | user. `404 client_not_found`.

### `PUT /api/clients/{id}/brief` — `sessions:write` (since v1.4)
`{"sections": {"goal": "…", "scope": ["…"], …}}`: a person's edit (goal ≤ 300 characters, lists of at most 20 lines of
300). The brief is then `edited` and never rewritten by itself. `200 {"data": <brief>}`.

### `POST /api/clients/{id}/brief/refresh` — `sessions:write` (since v1.4)
Writes the brief again from the client's files (or, with none, from its call notes), replacing an edited one, on the
queue. `202 {"data": <brief with "status": "working">}`; `403 ai_budget_reached`, `409 ai_unavailable`.

### `POST /api/clients/{id}/memory` — `sessions:write` (since v1.4)
`{"kind": "decision", "text": "Use Stripe", "happened_on": "2026-10-31"}` (`happened_on` optional, default today).
`201 {"data": <memory line>}` with `source.type` `user`.

### `PATCH /api/clients/{id}/memory/{item}` — `sessions:write` (since v1.4)
`{"text": "…", "pinned": true}` (each optional). Pinned lines stay when a call's notes are written again.
`200 {"data": <memory line>}`; `404 memory_not_found`.

### `DELETE /api/clients/{id}/memory/{item}` — `sessions:write` (since v1.4)
`204`; `404 memory_not_found`.

### `POST /api/clients/{id}/files` — `sessions:write` (since v1.4)
Multipart `file` (PDF, Word .docx, text or Markdown, ≤ 20 MB; `422` otherwise), or JSON `{"text": "…", "name":
"Rules"}` for pasted instructions (≤ 100 000 characters). Files are stored encrypted like recordings; their text is
read on the queue (PDFs through Claude Haiku, page by page) and the brief is refreshed unless edited.
`201 {"data": <file>}` with `status` `extracting` (a file) or `ready` (instructions).

### `DELETE /api/clients/{id}/files/{file}` — `sessions:write` (since v1.4)
Deletes the file and its encrypted original. `204`; `404 file_not_found`.

### `POST /api/clients/{id}/ask` — `sessions:write`, throttled (20/min/device) (since v1.4)
`{"question": "When do we launch?", "language": "en"}` (`language` en | roman_urdu, default en; question 3–500
characters). The server picks the passages of the client's transcripts and files that match the question best (about
12) and sends them with the brief and memory. `200 {"data": {"answer": "Launch is on November 15.", "citations":
[{"type": "call", "session_id": "01JB…", "title": "Kick-off · Oct 30", "at_ms": 125000, "quote": "…"},
{"type": "file", "file_id": "01JE…", "title": "scope.pdf", "page": 2, "quote": "…"}]}}`. `403 ai_budget_reached`,
`409 ai_unavailable`, `502 provider_error`.

### `GET /api/clients/{id}/bundle?notes=` — `library:read` (since v1.4)
"Copy for AI": `200 {"data": {"text": "# Acme: project context\n\n## Brief\n…\n## Memory\n…\n## Recent call notes\n…"}}`
with the last `notes` (0–20, default 5) call notes, ready to paste into a chat project.

### `GET /api/sessions/{id}/notes` — `library:read` (since v1.4)
`200 {"data": <notes, with "session">}`; `404 notes_not_found` when none were written; `404 session_not_found`.

### `POST /api/sessions/{id}/notes` — `sessions:write` (since v1.4)
`{"language": "roman_urdu"}` (optional, en | roman_urdu): writes the recording's notes again on the queue.
`202 {"data": <notes with "status": "pending">}`; `409 no_client` when the recording is not filed under a client;
`403 ai_budget_reached`, `409 ai_unavailable`.

### Account and security from the apps (since 2.0.0-l)
The person's own account, from the phone and desktop apps, with the same rules as the console's Settings.

#### Rules for every endpoint below
- The person's own account only. Ids of someone else's browser, passkey or device answer `404`, and the tenant tests
  cover every route.
- Reading uses `config:read`. Changing anything uses `sessions:write`. A viewer may change their own account, because
  the account is theirs and not the workspace's.
- **Proof** (the same as `DELETE /api/account`): `{"password": "…"}`, or `{"otp": "123456"}` (an authenticator or
  recovery code). An account with neither a password nor two-step (made with Google) uses `{"email_code": "123456"}`
  from `POST /api/account/proof-code` → `202`. That sends a 6-digit code to the current address, valid 10 minutes,
  with at most 5 sent an hour. A wrong proof is `422 wrong_password`, `422 invalid_otp` or `422 invalid_code`. After
  5 wrong proofs in 15 minutes the answer is `429 too_many_attempts` with `Retry-After`, through the same SignInGuard
  as sign-in. Each proof is counted before it is checked (an atomic count), so proofs sent at the same moment are
  never more than 5 tries; a right one clears the count. Every route that takes a proof also has a per-person limit.
  - **With two-step on, the proof must include an `otp`** (an authenticator or recovery code): the password alone is
    never enough, or the password and a device would stand in for the second step. A password sent with it is checked
    too (`422 wrong_password`). A right password without a code is `422 invalid_otp` with `"accepts": ["otp"]`, not
    counted as a failure. `PUT /api/account/password` then takes `current` and `otp` together.
  - No proof at all is `422 proof_required` with `"accepts": ["password", "otp", "email_code"]` (the ones this account
    can give; only `["otp"]` with two-step on). `email_code` counts only for an account with neither a password nor two-step; for any other it is
    `422 invalid_code`, and `POST /api/account/proof-code` answers `409 proof_code_not_needed`.
  - Every way to add a way in (two-step, a passkey) asks for the proof when its ceremony starts, and the ceremony is
    the only way to finish, so a device on its own (a stolen token) cannot add one. The account holder gets an email
    when one was added from an app (see 7 and 8).
- **Field error codes**: every `422 validation_failed` from these routes keeps `errors` (`{"field":
  ["message"]}`, as today). Next to it, `error_codes` gives `{"new": [{"code": "too_short", "min": 12},
  {"code": "needs_numbers"}]}`, so the apps can write their own words (Urdu).
  - Codes: `required`, `too_short` (`min`), `too_long` (`max`), `needs_letters`, `needs_numbers`, `same_as_current`,
    `invalid_characters`, `invalid_email`, `same_as_now`, `email_taken`.
  - For images: `invalid_image` with `reason` = `format` | `broken` | `too_small` (under 128 px) | `ratio` |
    `too_many_pixels`.
- Rate limits are per person, not per device. Going over answers `429 rate_limited` with `Retry-After`.
- Every change is written to the audit log (see the last section). Logs never contain passwords, codes, secrets,
  otpauth URIs, recovery codes or picture bytes.
- Every rule comes from the service the console already uses (Password::defaults, Filament's email change,
  AppAuthentication, Passkeys, WebSessions, AccountDeletion), so the web and the apps never disagree.

#### The endpoints, with their ability and limit
- `GET /api/account/security` — `config:read`: sign-in methods, browsers, devices, events.
- `POST /api/account/proof-code` — `sessions:write`: an email code for proof (5 an hour).
- `PATCH /api/account` — `sessions:write`: the name (10 an hour).
- `POST /api/account/avatar` — `sessions:write`: a profile picture (10 changes an hour with removing).
- `DELETE /api/account/avatar` — `sessions:write`: removes it.
- `GET /api/avatars/{user}/{version}` — signed URL, no token: the picture itself (see "Avatar URLs").
- `POST /api/account/email` — `sessions:write`: starts an email change (3 an hour).
- `POST /api/account/email/confirm` — `sessions:write`: the code (5 attempts an hour).
- `DELETE /api/account/email` — `sessions:write`: cancels it.
- `PUT /api/account/password` — `sessions:write`: changes or sets the password (5 an hour).
- `PATCH /api/account/devices/{id}` — `sessions:write`: renames a device of any of the person's workspaces.
- `DELETE /api/account/devices/{id}` — `sessions:write`: signs one out.
- `DELETE /api/account/browsers/{id}` — `sessions:write`: signs a console browser out.
- `DELETE /api/account/google` — `sessions:write`: unlinks Google (proof).
- `POST /api/account/passkeys/options` — `sessions:write`: starts a phone passkey (proof; 10 an hour).
- `POST /api/account/passkeys` — `sessions:write`: saves it.
- `PATCH /api/account/passkeys/{id}` — `sessions:write`: renames one.
- `DELETE /api/account/passkeys/{id}` — `sessions:write`: removes one (proof).
- `POST /api/account/two-factor` — `sessions:write`: starts two-step (proof; 10 an hour).
- `POST /api/account/two-factor/confirm` — `sessions:write`: turns it on, answers the recovery codes.
- `POST /api/account/two-factor/recovery-codes` — `sessions:write`: new recovery codes (proof).
- `DELETE /api/account/two-factor` — `sessions:write`: turns it off (an authenticator or recovery code).

#### 1. `GET /api/account` gains these fields
`"avatar_url": "https://…" | null` and `"avatar_version": 3 | null` (see "Avatar URLs" below; `null` means the apps
show initials), `"has_password": true`, `"pending_email": "new@example.com" | null`, `"pending_email_expires_at"`,
`"passkeys_available": true` (false when the server is not set up for phone passkeys).
`pending_email` is `null` again once the change was confirmed (by code or by link), cancelled, blocked or expired.

#### 2. Name
`PATCH /api/account {"name": "Hassam"}`: 1 to 120 characters, trimmed at both ends.
- Allowed: letters, marks and numbers of any script, spaces, `.`, `'`, `-`, `(`, `)`, `,`, and, inside a name, U+200C/U+200D
  (ZWNJ/ZWJ, which Urdu and Persian need) and U+200E/U+200F (LRM/RLM).
- Rejected rather than stripped, with `invalid_characters`: other control and format characters, including the bidi
  embeddings, overrides and isolates U+202A–U+202E and U+2066–U+2069. The answer is
`200` with the account as in `GET`. Limit: 10 an hour.

#### 3. Profile picture
- `POST /api/account/avatar` takes multipart `image`: JPEG, PNG or WebP (decided from the bytes, not the name or
  type), at most 5 MB, at most 25 MP, aspect ratio at most 4 to 1. The phone converts HEIC before upload. The server
  decodes the picture, crops it to a centred square and saves WebP copies at 512 px and 128 px. EXIF, GPS and every
  other piece of metadata are dropped. The answer is `200 {"avatar_url": "…", "avatar_version": 1763700000}`. A JPEG
  is turned upright by its orientation first. The version is a number that only grows (a timestamp), never reused,
  even after a removal.
- Errors:
  - `422 invalid_image` for an unknown format, a broken file or the wrong size;
  - `413 too_large`;
  - `503 server_busy` with `Retry-After: 5`, from the same picture limit as screens.
- **Avatar URLs**:
  - Every `avatar_url` comes with `avatar_version`, an integer that changes only when the picture changes. The apps
    cache the picture by person id and `avatar_version`, and keep showing the cached copy offline.
  - The URL is signed, has the version in it, and is valid for 7 days. A new URL for the same version is the same
    picture, so there is nothing to download again.
  - An expired or wrong signature answers `403` and a version that has been replaced or removed answers `404`, both
    with an empty body. The apps then use their cached copy or the initials quietly, and refresh the URL on their
    next read.
  - Sizes: `?size=128` (default) or `512`.
- `DELETE /api/account/avatar` → `204`, and the stored copies are deleted.
- Limit: 10 changes an hour, for adding and removing together.
- `avatar_url` and `avatar_version` appear beside every person the API already shows:
  - Shared with me `from`;
  - a sent item's `to[]`;
  - `GET /api/account` itself (the person's own).
  `GET /api/account` `workspaces`, activity items and audit entries name no other person today, so they carry none; a
  person added there later gets the two fields with it. A URL is only given where the person is already shown, and
  erasing the account deletes the picture.

#### 4. Email change
1. `POST /api/account/email {"email", …proof}` → `202 {"pending_email", "pending_email_expires_at"}` (expires after
   60 minutes).
   - The new address gets a 6-digit code and the console's verification link, and either one confirms.
   - The old address gets the usual notice with "This wasn't me", which blocks the change.
   - Errors:
     - `422 email_taken`;
     - `422 validation_failed` (the address is not valid, or is the same as now);
     - the proof errors listed above.
   - The address is checked first (`invalid_email`, `same_as_now`), so a one-time code is not used up by a mistyped
     address; `email_taken` is said only after the proof.
2. `POST /api/account/email/confirm {"code"}` → `200`, with the account carrying the new `email` and
   `pending_email: null`.
   - The change is audited, other browsers are signed out and "remember me" is renewed.
   - The change opens a 7-day **restore window**, anchored to the address before the first change (A). A change made
     in the console during the window keeps the window and A. A gets "Your email address was changed" with **Put my
     address back**: a signed link for the whole window that needs no sign-in (whoever changed it may hold the
     account). The new address (B) is told that A got it. The link opens a page whose button puts A back (confirmed)
     and **resets every way in**: every passkey, the Google link and two-step (with its recovery codes) are removed,
     the password stops working and a reset link goes to A, and everything is signed out (audited as
     `email_change_reverted`). B is told of the restore too. The link works once (under a lock of the account's row).
   - **A is reserved during the window:** no account can be given it or changed to it (sign-up, a member added with a
     password, staff, the console's change, an app's change answer `email_not_allowed`, `email_taken` or a field error),
     so nothing can block the restore. The database compares it as it compares addresses (a column of users.email's own
     collation), so another case or an accented lookalike is the same address. At the restore only an account with A
     made before the change can block it.
   - **A change proven with the account's two-step code**, when two-step has been on for at least 7 days and its recovery
     codes were last made at least 7 days ago (on any path, the console's too), opens no window and sends no link: A is told of the change and to contact
     support, so a mailbox someone else reads cannot take the account back. Any other change gets the link.
   - While the window is open, and after a restore until the new password is chosen (or the owner signs in again) or
     24 hours have passed, whichever is later, `POST /api/account/email` answers `409 email_change_locked {"until"}` before any proof, so a chain of
     changes cannot move the restore link to an address the changer controls.
3. `DELETE /api/account/email` → `204` cancels a pending change.
4. Noticing a confirmation made by link in a browser: there is no push for it. While the "Check your new
   address" screen is open, the app reads `GET /api/account` every 10 s until `pending_email` is `null` or the change
   expires, and again on resume.
5. "This wasn't me" in the old address's email cancels the change (audited as `email_change_blocked`) and
   signs out every device and browser, because whoever asked knew the password or a code. That email and the page it
   opens tell the person to change their password. The app's next call gets `401`, and the app goes to sign-in.

Limits: 3 requests an hour; 5 code attempts an hour, after which the change is cancelled.

#### 5. Password
- `PUT /api/account/password {"current": "…" | null, "new": "…", "sign_out_others": true}` → `204`.
  - `current` is required when `has_password` is true. An account made with Google sets a first password with proof
    instead (an `otp` or `email_code`).
  - `sign_out_others` is true by default. It does what Sign out everywhere does (every other device, every browser,
    sign-in and pairing codes not used yet, connected apps, "remember me"). This device's token stays
    valid: the answer is `204` with nothing to sign in again and no new token.
  - Errors:
    - `422 wrong_password` for the current password, counted by SignInGuard like a failed sign-in;
    - `422 validation_failed` with field errors on `new` (too short, missing letters or numbers, the same as the current one)
      or on `current` (`required`). The rules on `new` are checked before the proof, so a one-time code is not used up
      by a password that is too short; `same_as_current` only after it.
    - for an account without a password: the proof errors listed above.
  - Limit: 5 an hour, on top of the sign-in throttles.
  - The account holder gets an email "Your password was changed" naming the device.
- `GET /api/auth/options` gains `"password_rules": {"min": 12, "letters": true, "numbers": true}`, so the apps can check
  before sending. The server still decides.

#### 6. Sign-in methods, browsers and devices
- `GET /api/account/security` returns:
  ```json
  {"password": true, "google": {"linked": true, "email": null},
   "passkeys": [{"id", "name", "created_at", "last_used_at"}],
   "two_factor": {"enabled": true, "recovery_codes_left": 7},
   "browsers": [{"id", "agent": "Chrome on macOS", "country": "PK", "last_active_at", "current": false}],
   "devices": [{"id", "name", "platform", "app_version", "last_seen_at", "paired_at", "current", "workspace": {"id", "name"}}]}
  ```
  `google.email` is `null`: the server keeps only Google's id of the person, not the Google address. A browser's `id`
  is opaque (never the session itself) and works only for `DELETE /api/account/browsers/{id}`; `current` is always
  false for the apps, which are not browsers. `devices` covers every workspace the person belongs to, as on the web. Each device also has
  `"status": "active" | "signed_out"` and `"signed_out_at"`, and signed-out devices are listed for 30 days.
  The answer also has `"events"`: the latest 50 audit entries, the same shape as `GET /api/security`, which is the one
  the apps should use from 2.0.0-l on. `GET /api/security` stays as it is for older apps.
- `PATCH /api/account/devices/{id} {"name"}` → `200`, and `DELETE /api/account/devices/{id}` → `204`, for a device in
  any of the person's workspaces. Errors: `404 device_not_found`, and `409 current_device` as for
  `/api/devices/{id}` (which stays limited to the current workspace).
- `DELETE /api/account/browsers/{id}` → `204`. Error: `404 browser_not_found`.
- `DELETE /api/account/google {…proof}` → `204`. Error: `409 last_sign_in_method` when there is no password and no
  passkey left. Limit: 10 an hour with passkey options and removal. The account holder gets an email.
- `DELETE /api/account/passkeys/{id} {…proof}` → `204`. Errors: `404 passkey_not_found`, and `409 last_sign_in_method`.
  Limit: 10 an hour (as above). The account holder gets an email naming the passkey.
- `PATCH /api/account/passkeys/{id} {"name"}` → `200` renames one.

#### 7. Two-step (authenticator app)
1. `POST /api/account/two-factor {…proof}` (the password, or an `email_code` for an account made with Google) →
   `200 {"ceremony": "…", "secret": "BASE32…", "otpauth_uri": "otpauth://totp/…",
   "issuer": "EchoLedger", "label": "h…@example.com", "digits": 6, "period": 30, "algorithm": "SHA1"}`.
   - Nothing is saved until it is confirmed, and a ceremony is valid for 10 minutes.
   - The proof is asked here, and only this ceremony (of this person) can be confirmed, so a device on its own cannot
     enrol its own authenticator. `409 already_enabled` comes before the proof, so nothing is used up.
   - Errors: `409 already_enabled`, and the proof errors listed above.
   - Limit: 10 an hour.
2. `POST /api/account/two-factor/confirm {"ceremony", "code"}` → `200 {"recovery_codes": ["…" ×8]}`. Recovery codes
   are shown only here and on regeneration, and are never sent again. Error: `422 invalid_otp` (also for a ceremony
   that ended, expired or is someone else's; 5 wrong codes end a ceremony). The account holder gets an email "Two-step
   sign-in is on" naming the device, never the secret.
3. `POST /api/account/two-factor/recovery-codes {"otp"}` → `200 {"recovery_codes": […]}`. The old codes stop working.
   The proof is an authenticator or recovery code, never a password alone. Limit: 10 an hour with starting two-step and
   turning it off. Errors: `409 not_enabled` without two-step
   (before any proof), `422 invalid_otp`. The account holder gets an email "New recovery codes were made" naming the
   device, never a code.
4. `DELETE /api/account/two-factor {"otp"}` → `204`. The proof here must be an authenticator or recovery code, never
   only a password or `email_code`; otherwise anyone who controls the email could turn two-step off. A person who has
   lost both gets help from support, as on the web. Error: `409 required_for_staff` for platform staff. Without
   two-step on, the answer is `204` and nothing changes. The account holder gets an email "Two-step sign-in is off"
   naming the device.

#### 8. Passkeys on the phone
1. `POST /api/account/passkeys/options {…proof}` → `200 {"ceremony": "…", "options": {…WebAuthn PublicKeyCredentialCreationOptions JSON…}}`.
   - The proof is asked here, and only this ceremony (of this person, used once) can add the passkey.
   - The rp id is the console host, the same passkeys as the website.
   - The options are valid for 5 minutes.
   - Errors: `409 too_many_passkeys` (10), `409 passkeys_unavailable` when `passkeys_available` is false, and the proof
     errors listed above.
   - Limit: 10 an hour.
2. `POST /api/account/passkeys {"ceremony", "name", "credential": {…registration response JSON…}}` → `201
   {"id", "name", "created_at"}`. Errors: `422 invalid_passkey` (also for a ceremony that was used, expired or is
   someone else's), and `422 validation_failed` on `name`, which follows the rules of a person's name (section 2) at
   up to 80 characters (`invalid_characters`, `too_long`); no name is "Passkey". `PATCH` takes the same rules. The account holder gets an email "A passkey was added" naming the passkey and the device.
3. The Android origin `android:apk-key-hash:<base64url of the signing cert SHA-256>` is accepted next to the console
   origin, built from `echoledger.android_app.fingerprints` (`ECHOLEDGER_ANDROID_FINGERPRINTS`; empty by default, so a
   server sets its release key and a local `.env` the debug keys, see `.env.example`). `/.well-known/assetlinks.json` gains the
   `delegate_permission/common.get_login_creds` relation, which Credential Manager needs.
4. The desktop app opens `https://<console>/app/settings/security/passkeys`, a stable link added by B9 that
   redirects to wherever the console's passkeys section is (classic Profile or the v2 Sign-in & security), after
   sign-in if needed. WebView2 and WKWebView ceremonies are out of scope.
5. When `passkeys_available` is false (no Android signing key configured, for example on a self-hosted server), the
   phone hides "Add a passkey" and shows "Manage passkeys on the website".

#### 9. Keep my account
Asking for deletion signs the account out everywhere (`AccountDeletion::schedule`), so no app is signed in to cancel
it. The fix is in sign-in:
- During the 7-day grace, `POST /api/auth/password` and `POST /api/auth/device/token` answer
  `403 deletion_scheduled {"deletes_at"}`. Until now they answered `403 not_allowed`; an older app shows the message
  as before. `POST /api/auth/code/verify` (the email code) does the same, and the email code stays good for the call
  with `keep`, so an account without a password can keep itself too.
- The answer comes only once the password (or email code) and the two-step code are right, and after the account's
  own state: a suspended or unconfirmed account gets `account_suspended` or `email_not_verified` first. The two-step
  code is checked without being used up (a code used already is refused, as at any sign-in), so the call with `keep`
  sends the same one.
- The app then asks "Keep my account?" and repeats the same call with `"keep": true`. That cancels the deletion
  (audited as `account_deletion_cancelled`, and the account gets an email) and signs in as usual (`201`).
- Browser sign-in (desktop, and the phone's browser path) gets the same: `POST /api/auth/device/token` answers
  `403 deletion_scheduled`, the one-time code stays valid for its 5 minutes, and the same call with `"keep": true`
  cancels the deletion and signs in.
- `keep` counts only after the whole sign-in has passed: password, `otp`, SignInGuard and the staff rules.
- An app that does not know `deletion_scheduled` shows its message as a plain error ("This account is being
  deleted on <date>. Sign in on the console and choose Keep my account."), tested.
- The proof is the sign-in itself (password with `otp`, or the browser sign-in), so there is no extra endpoint and no
  token that is only half valid.

#### Audit events (added to "Security centre")
These are new: `name_changed`, `email_change_requested`, `email_changed`, `email_change_cancelled`,
`email_change_blocked`, `avatar_changed`, `avatar_removed`, `recovery_codes_regenerated`, `passkey_renamed`.

`email_change_reverted` is new too: the original address's "Put my address back". `email_changed` is written for every change of address (the console's link, an app's code, staff).

These already exist and are now also written from the apps: `password_changed`, `mfa_enabled`, `mfa_disabled`,
`passkey_added`, `passkey_removed`, `web_session_revoked`, `google_unlinked`, `account_deletion_cancelled` ("Keep my
account", the same event as the console's).

Summaries never contain the addresses in full ("Email changed to h…@example.com").

The account holder's emails about these changes (two-step on or off, new recovery codes, a passkey added or removed,
the password changed, Google unlinked) name the
device and the passkey as plain text: every character but letters, marks, numbers and spaces is written as a
character reference and control and direction characters are dropped, so a name can never become a link, an image or
emphasis.

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
(the monthly limit's warning threshold) and `budget_reached`, each budget notice once per month, since v1.5
`shared_with_you` (a teammate sent a recording or a project; see "Sending to a teammate"), and since v1.8
`missed_recording` (a call an app saw but did not record; it needs action, see `POST /api/devices/missed-recordings`).
Recordings in the undo bin make none. Read notifications are kept 90 days, unread ones 180. Device apps poll and show
new ones as OS notifications that open `session_id`, outside the quiet hours the person chose on that device.

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

## Easy, trustworthy, clear (since v1.5, Phase 1C)

- **`GET /api/auth/options`** — public, throttled like the site pages: what a sign-in screen offers on this server.
  `200 {"password": true, "google": false, "sign_up": false, "sign_up_url": "https://…/admin/register" | null,
  "reset_url": "https://…/admin/password-reset/request", "browser_sign_in": true}`. `sign_up` follows Settings →
  Who can sign up (`true` only for *Anyone*); `google` is true when the Google client is set. Apps show Create account
  and Google only when offered, and open these pages in the browser. An older server answers `404`: show email and
  password only.
- **Transcription progress with time left.** A transcript's `progress` (and a library item's `transcript.final_progress`)
  is present for every run while it is being made, not only for runs in parts: `{"done": 1, "total": 3, "eta_seconds":
  20}` (`total` 1 for a run in one piece; `eta_seconds` from the audio still to do and the engine's measured speed on
  this server, `null` until there is a measure). `null` once the run is finished or failed.
- **Views in Library items.** A library item's `transcript.views` (since v1.5, Phase 1C) is null until the final
  transcript is finished, then `{"status": "none" | "making" | "ready" | "failed", "default": "roman", "progress":
  {"roman": {"done": 12, "total": 20}}}` from what the server stored (no lines are read), so a row can say "Improving
  Roman Urdu… 60%".
- **Preview in the view shown** (since 2.0.0-n). A library item's `transcript.preview` is the default view's words (at
  most 160 characters, one line of the view per line, no speaker names) once that view is `ready` and was made after the
  last edit, else the transcript's own words as before. `transcript.preview_view` says which: `"roman"`, `"urdu"`,
  `"english"` or `"original"` (always present; `"original"` also when there is no transcript yet).
- **Views progress.** While a view is being made, `views.progress` carries `{"roman": {"done": 12, "total": 20}}` (lines
  done of lines), `{}` otherwise. `views.status` is unchanged.
- **Views only transliterate.** The Roman Urdu, English and Urdu views keep every word of the original: a view line whose
  words do not match the original's words sound for sound is made again, and if it still does not match, the original's
  word is kept in a plain transliteration. A word the model believes was misheard is never changed in the view: it
  becomes a suggested fix (`suggestions`, `reason: "view_check"`), accepted or dismissed as other suggestions are.
  Numbers, units, prices, dates and times are written as digits in every view ("16 GB", "M6 Pro", "Rs 2,500", "3:30").
- **Automatic titles.** When a recording's final transcript is ready and nobody has given it a title, the server gives it
  a short one from what was said (at most 60 characters, digits for numbers, for example "Laptop — 16 vs 32 GB"). Since
  2.0.0-n a recording that opens in Roman Urdu (Urdu and mixed calls by default) gets it in Roman Urdu ("Meeting ka time
  aur tax form"), from the Roman Urdu view once it is made; an automatic title still in Urdu letters is written again in
  Roman Urdu when that view is made (never a title a person gave). English and Arabic titles stay in their language. Library
  items and `GET /api/sessions/{id}` carry `title_auto: true` for such a title, and only for such a title (since 2.0.0-k
  it is `title_source == "auto"`). A title set by a person (`PATCH` with `title`, or a title an app sent at upload that is
  not generic) is never replaced. Setting `title` to `null` asks for the automatic one again.
- **Title from the person** (since 2.0.0-k). Every `title` the API gives to the person and their workspace (Library
  items, `GET /api/sessions/{id}`, search, Shared with me, activity, call notes) follows one order:
  1. a title the person chose: any title given by renaming (`PATCH` with `title`, the console's Rename), even an app's
     generic name ("WhatsApp call"); or a title an app sent at upload that is not a generic name ("WhatsApp call",
     "Voice note", a file name such as "PTT-20261108-WA0012"). It always wins, and a transcript never changes it;
  2. the person called, unless it is a voice note: "Call with <name>" from `caller.name` (a `window_title` or `calendar`
     name as it is), or, without a name, "Call with <number>" from `caller.number`, for every call app. A name that is
     only an app's label ("WhatsApp", "WhatsApp call", "Unknown", "Private number") counts as no name;
  3. the automatic title, made only when no person is known;
  4. the app's own name for an untitled recording ("WhatsApp call", "Voice note").
  `title_source` says which step gave the title: `"own"` (1), `"person"` (2), `"auto"` (3) or `"app"` (4).
  `title_auto` is `true` only for `"auto"`, as in v1.5, so older apps' "named from what was said" badge stays right.
  Apps pre-fill their rename box only for `"own"`. A `PATCH` whose `title` is the "Call with …" shown (English, or the
  console's language) changes nothing: the title stays the person called's. An automatic title made before the caller
  was known gives way to the person as soon as `caller` is known. Nothing stored changes, and clearing a title
  (`PATCH` `title: ""`) goes back to steps 2 to 4. Search and the name sort follow the same order: "Ahmed" and
  "call with Ahmed" find a call shown as "Call with Ahmed".
  The API always says "Call with" in English, and the console says it in its own language.
  **Outside the account** the title never names the person called: on share pages (links already sent too), in
  WhatsApp messages and file names, exported files and meeting packs, the audit log, support staff's views and the
  operators' checks, it is the stored title, or the app's name for an untitled recording. Teammates of the workspace
  see it as the person does (Shared with me, the bell, the email).
  A teammate's saved copy keeps the stored title and its kind and works out its own title from the caller.
- **Two engines on every short note** (Settings → Recording → Double-check short notes, on by default). A recording of
  up to 3 minutes is heard by a second engine, the cheapest other one with a key; the words are lined up. A segment word
  the engines disagree on keeps the first engine's `text` and gains `alt` (the other engine's word, `""` when it heard
  none) and `alt_by` (that engine); its `confidence` is lowered below 0.5, so it is unsure in every app. When a paid
  engine broke the tie (`tie_by`): on the first engine's side the word is not unsure and keeps `alt`; on the second's it
  also has `probably` (the word two engines heard). A line with a disagreement has `uncertain: true` and the line-level
  `check` (`{"provider", "label", "text"}`) with the second engine's words. Apps show `alt` (and `probably`) on a tap
  of the word: «أفحم» → "probably أفهم". The transcript's `meta.short_check` summarises it (`provider`,
  `tie_breaker`, `disputed`, `probably`, `cost_usd`). The text is never changed by the check.
- **Views never guess.** An unsure word with another engine's version reaches the views model with it; every view keeps
  that word as `[?word]` (a translation gives the likelier reading in the brackets: "I [?understand] English"), never a
  smooth sentence over it, and the two words become a suggested fix (`reason: "view_check"`). Settings →
  `views.short_model` may give notes of up to 3 minutes a cheaper model (off until measured).
- **Engines, cheapest first.** Every language starts on Whisper large-v3 (Groq) except Arabic, which starts on
  ElevenLabs Scribe v2; a recording on Auto that turns out to be a language with another first engine is transcribed
  again there (`meta.rerouted_from`), and the first run becomes the second opinion of the check.
- **Fast path.** A recording of a minute or less is transcribed, checked and given its views right after `complete`
  answers, not on the next queue run.

## Claude and ChatGPT connector (since v1.5)
An MCP server, so that Claude (Customize → Connectors → Add custom connector) and ChatGPT read a person's recordings
the way the person can in the apps. It only reads: it never records, edits, shares or deletes.

- **Endpoint:** `https://<server>/mcp` (MCP over Streamable HTTP, the official `laravel/mcp` package).
- **Sign-in:** OAuth 2.1 through Laravel Passport: discovery at `/.well-known/oauth-protected-resource` and
  `/.well-known/oauth-authorization-server`, dynamic client registration, the authorization-code flow with PKCE
  (S256 only), scope `mcp:use`. The person signs in on the console (with two-factor sign-in when it is on) and
  approves "Allow Claude to read your EchoLedger recordings?". Access tokens last 1 hour, refresh tokens 30 days.
  The page asks every time, and `prompt=none` answers `consent_required`. Registration takes only the apps' own return
  addresses, compared whole (`MCP_REDIRECT_DOMAINS` replaces the list), at 10 a minute, 30 an hour and 100 a day per
  address; an app that never connects is removed after a day. A used refresh token or code that comes back ends that
  connection and writes `connector_token_reused` to the audit log, except a refresh token back within 10 seconds of its
  swap, which is only refused. Profile → Connected apps lists each connection with its last use and Disconnect; Sign out
  everywhere disconnects them too.
- **What it sees:** exactly what the person can open in the apps: their own recordings and projects and what was
  shared with them: their own in their current workspace, and what a teammate sent them from any workspace, as in the apps. The plan's `connector` feature must be on; otherwise every tool
  answers "The connector is not in your plan."
- **Limits and records:** 60 tool calls a minute per person; every call goes into the audit log (tool, arguments
  without content, result size).

| Tool | Input | Output |
|---|---|---|
| `list_projects` | `parent_id` (optional) | Projects with id, name, path, recording count and last recording date. |
| `search` | `query` (2–200 characters), `project_id` (optional), `limit` (1–25, default 10) | Matches in titles, transcript text and screen text: recording id, title, date, project, the matching line and its time. |
| `get_recording` | `id`, `view` (`original`, `roman`, `english`, `urdu`; default: the console's default view when it is made), `include_screens` (default true) | Title, date, length, people, project, then the transcript as `[mm:ss] Speaker: text` lines with `[Screen N at mm:ss: description]` lines at the screens' moments, and each screen's text and a signed image link (30 minutes). |
| `get_project_brief` | `project_id` | The project's brief, notes and instructions, contacts, file names, memory and the latest call notes (memory and call notes only when AI notes are in the plan). |
| `list_recent` | `n` (1–50, default 10), `project_id` (optional) | The latest recordings: id, title, date, length, project and app. |

Errors come back as tool errors in plain words ("No recording with that id", "The connector is not in your plan").

---

## Provider webhooks (server-to-server; not for device apps)
`POST /api/webhooks/stt/{provider}` with `provider` ∈ `assemblyai` | `elevenlabs`. Public, throttled,
signature-verified (AssemblyAI: per-recording HMAC in `X-EchoLedger-Webhook-Token`; ElevenLabs:
`elevenlabs-signature` `t=…,v0=…`), idempotent via `webhook_events` (the ElevenLabs de-duplication key is derived
from the signed payload, never from an unsigned header). `401` bad signature or malformed recording id; `5xx` only
for transient faults.
