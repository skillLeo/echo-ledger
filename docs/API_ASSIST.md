# Live Assist contract — revision 1.9.0-b (draft)

The contract for Live Assist (v1.9 Phase L): text-only help on screen while the other person talks. It is a draft
until the L1 server work starts (after v1.8.0); then its HTTP part moves into [API.md](API.md) and the API
documentation test covers it. Everything here is invisible to users until v1.9 ships, and nothing of it ships before
Phase S is verified on real devices.

Conventions are those of [API.md](API.md): JSON, a device token with abilities, ids from the token and never from the
body, another workspace's ids answer `404`, errors as `{"message", "code"}`.

## Who talks to whom

```
 app (desktop / phone) ──REST──▶ server (Laravel)          preps, start, token refresh, turns, end, reports
 app ──WebSocket──▶ assist relay (services/assist-relay)    live turns in, suggestions out, token by token
 app ──WebSocket──▶ STT provider                            audio, with the short-lived STT token from the server
 relay ──HMAC REST──▶ server (internal)                     provider config, usage, suggestions, facts, summary
```

- The server is the only place provider keys live (Admin → API Credentials). The relay fetches the provider
  configuration from the server over the internal API at start and every 10 minutes, keeps it in memory only, and
  never writes it to disk or logs.
- Text is user data: it is encrypted at rest on the server, never logged by the relay, never used for training, and
  deleted with the session or the account.

## Plans (decided 2026-11-16, D5; config values, editable in the admin panel)

| Plan | Live modes | Practice | Monthly allowance |
|---|---|---|---|
| Free | none | yes | 30 minutes of Practice |
| Pro (Rs 2,999) | `fast`, `balanced` | yes | 10 assist-hours |
| Ultra (Rs 12,499) | `fast`, `balanced`, `deep`, `max` | yes | 40 assist-hours, fair use |

Every LLM and STT call goes through the per-user budget and usage ledger (feature `live_assist`). An assist-hour is
wall-clock session time with the relay connected.

## REST (server)

Abilities: reads `library:read`; writes `sessions:write`; starting a session and refreshing its tokens `stt:token`.
Errors added by Live Assist:

| Status | `code` | When |
|---|---|---|
| 403 | `feature_not_in_plan` | `feature: "live_assist"` (live mode on Free) or `feature: "assist_mode"` with `mode` and `lowest_plan` |
| 402 | `budget_exhausted` | The month's allowance is used; `fallback`: `"captions_only"` (live) or `"none"` (Practice) |
| 409 | `session_ended` | Turns, refresh or end on a session that has ended |
| 409 | `session_active` | A second live session from the same person while one runs |

### Preps
- `GET /api/assist/preps?status=` · `POST /api/assist/preps` · `GET|PATCH|DELETE /api/assist/preps/{id}`
  ```json
  {"meeting_type": "interview", "title": "Interview at Acme", "counterpart": "Sara Khan", "company": "Acme",
   "project_id": "01JB…", "goals": "…", "done": "…", "not_done": "…", "to_discuss": "…", "instructions": "…",
   "hint_level": "outline", "mode": "balanced", "language_help": "roman_urdu",
   "scheduled_at": "2026-12-01T10:00:00Z", "status": "draft"}
  ```
  `meeting_type`: `interview` | `boss` | `existing_client` | `new_client` | `general`; `hint_level`: `keywords` |
  `outline` | `full` (interview defaults to `outline`); `mode`: `fast` | `balanced` | `deep` | `max` (refused per
  plan at start, not at save); `language_help`: `off` | `urdu` | `roman_urdu` | `arabic`; `status`: `draft` |
  `scheduled` | `armed` | `done` | `cancelled`. Text fields at most 4,000 characters each. Soft deleted.
- `POST /api/assist/preps/{id}/documents` `{"file_ids": ["…"]}` attaches project files (the server extracts text and
  counts tokens); `DELETE /api/assist/preps/{id}/documents/{fileId}`.
- `POST /api/assist/preps/{id}/arm` → `200 {"armed_until": "…"}`: a scheduled prep is armed 2 minutes early and waits
  up to 10 minutes for the meeting; then it is cancelled.

### Sessions
- `POST /api/assist/sessions` — start (also "Start now" from a prep):
  ```json
  {"prep_id": "01JB…", "mode": "balanced", "platform": "desktop", "recording_session_id": "01JB…",
   "practice": false}
  ```
  → `201`
  ```json
  {"session": {"id": "01JC…", "auto_saved": true, "mode": "balanced", "practice": false},
   "relay": {"url": "wss://relay.example/v1/assist", "token": "…", "expires_at": "…"},
   "stt": {"provider": "soniox", "ws_url": "…", "token": "…", "expires_at": "…", "translate_other": true},
   "plan": {"modes": ["fast", "balanced"], "seconds_left": 31200}}
  ```
  `auto_saved` is true when the session started from a prep: that call gets no after-call card. `recording_session_id`
  links the recording made at the same time (a session in status `recording` of this device). Practice:
  `"practice": true` without `recording_session_id`; the AI asks the questions.
- `POST /api/assist/sessions/{id}/token` → `200` with new `relay` and `stt` blocks (tokens last at most 15 minutes;
  apps refresh 2 minutes early and on a relay `error.code = "token_expired"`).
- `POST /api/assist/sessions/{id}/turns` — final turns in batches (every 5 s or 10 turns):
  `{"turns": [{"client_ref": "…", "channel": "other", "start_ms": 81200, "end_ms": 86400, "text": "…",
  "language": "en", "is_question": true}]}` → `200 {"accepted": 3}`; a repeated `client_ref` is ignored.
- `POST /api/assist/sessions/{id}/end` → `200 {"report": "queued"}`.
- `GET /api/assist/sessions?limit=` · `GET /api/assist/sessions/{id}` · `GET /api/assist/sessions/{id}/report`
  (`404 report_not_ready` until the job is done) · `DELETE /api/assist/sessions/{id}`.
- `POST /api/assist/sessions/{id}/suggestions/{suggestionId}/action` `{"action": "used" | "dismissed" |
  "regenerated" | "shorter" | "formal" | "translated"}` → `204`.

### Internal (relay → server)
Signed with HMAC-SHA256 over `timestamp + "\n" + method + "\n" + path + "\n" + body` in `X-Assist-Signature`, with
`X-Assist-Timestamp` (±60 s); the secret is generated in Admin → API Credentials (`assist_relay`) and given to the relay
at deploy time. Never reachable with a device token.
- `GET /api/internal/assist/providers` → models, mode → model map, prices, provider keys (memory only on the relay).
- `POST /api/internal/assist/usage` — per suggestion: session, model, tokens (input / cached / written / output),
  cost, latency stages; idempotent per `suggestion_id`.
- `POST /api/internal/assist/suggestions` — the final text of each suggestion (for the report).
- `POST /api/internal/assist/facts` · `POST /api/internal/assist/summary` — the facts ledger and the running summary.

## Relay token (claims)

A compact signed token (HS256, the `assist_relay` secret): `iss` "echoledger", `aud` "assist-relay", `sub` user id,
`ws` workspace id, `sid` assist session id, `plan`, `modes` (allowed), `models` (allowed ids), `budget`
(`seconds_left`, `cents_left`), `practice`, `iat`, `exp` (≤ 15 minutes), `jti`. The relay rejects a bad signature, a
wrong `aud`, an expired token, or a token whose `sid` does not match the socket's session.

## WebSocket (relay)

`wss://<relay>/v1/assist`, the relay token as `Authorization: Bearer` (or the first message for clients that can't
set headers). Every message is a JSON object with `type` and a per-direction `seq`.

Client → relay: `context_update` (brief, documents, memory deltas) · `turn_partial` · `turn_final` (channel `me` or
`other`, text, language, `silence_ms`) · `help_now` · `regenerate` · `shorter` · `formal` · `translate` · `cancel`.

Relay → client: `suggestion_start` (`suggestion_id`, `mode`, `model`, `draft`) · `token` (`suggestion_id`, `text`) ·
`suggestion_end` (`suggestion_id`, latency stages, usage) · `skip` (no reply needed) · `mode_changed` (budget
downgrade: `max → deep → balanced → fast → captions_only`, with `reason`) · `error` (`code`, `message`, `retry`).

Rules: a suggestion is never triggered from the `me` channel; a speculative start on an `other` partial that looks
like a question (≥ 128 ms of silence) is cancelled and restarted if they keep talking within 700 ms; a balanced draft
is replaced by its upgrade only if the person has not started speaking; captions never stop when the budget runs out.
The relay's own draft of these messages is `services/assist-relay/PROTOCOL.md`; this file wins where they differ.

## Output contract (prompt pack)

First line at most 25 words in simple English; at most 3 bullets with the key words in bold; an optional "Say next";
an optional Urdu / Roman Urdu line explaining the question; exactly `SKIP` when no reply is needed; never ask for
something already said; consistent with the facts ledger; never invent experience, numbers or promises.

The pack (web/resources/prompts/assist/, version 1.0.0) fixes two things the apps rely on: the language line starts
with "Urdu:", "Roman Urdu:" or "Arabic:" (apps may style it apart), and the consent line for boss, client and general
meetings is "Just so you know: I record this call and use an AI assistant that takes notes and suggests points to me.
Is that okay with you?" (the honest version: it also suggests answers; Hassam reviews the wording before launch, N7).
