# Live Assist contract — revision 1.9.0-q (frozen 2026-11-17 for the sprint)

1.9.0-q: two transports for the live messages, chosen by the server per session: the relay's WebSocket, or HTTP mode
(each app message POSTed to the server, the answer streamed back as server-sent events, with the same messages). The
start lists the transports the app knows and answers `transport`; an app that knows only the relay is refused with
`426 app_update_required` when the server runs HTTP mode.

1.9.0-p: a report that could not be made shows `"report": "failed"` on the session; prep routes one per line.

1.9.0-o: Ask over a session; usage kind `ask`; a prep's mode, hint level and language help default to the person's
Live Assist settings.

1.9.0-n: what the apps must handle as the relay behaves (ends of a suggestion, every error code, context replaces,
limits, reconnect, slow readers, practice ends, aliases), one relay connection per session, the "shown" report.

1.9.0-m: `GET /api/assist/preps/{id}` returns its documents' extracted text, which the app sends in `context_update`.

1.9.0-l: `POST …/end` works on the person's own session in any of their workspaces; the report's shape; the
server-side usage kind `report`.

1.9.0-k: `409 session_active` names the active session; a start from the same device may end it first.

1.9.0-j: `practice_question` numbers questions with `index`; the apps send the relay's `turn_id` as each `other`
turn's `client_ref`; a token refresh never fails because captions are down.

1.9.0-i: one active assist session per person; `stt` may be `null` only when a recording is running.

1.9.0-h: practice messages over the relay, the facts ledger and running summary from the relay, and the usage kinds.

1.9.0-g: the relay host's pins are `relay_pins` in `GET /api/config`.

1.9.0-f: the suggestion fields follow the relay as built: `kind` names the request, a fallback is recognised by
`replaces`, `status` is `done` / `cancelled` / `superseded`, a failure ends with an `error` carrying the
`suggestion_id`, `mode_changed` adds `requested`, and the rule for a refusal after words were shown.

1.9.0-e: the fields of `suggestion_start` and `suggestion_end` that carry fallbacks and upgrades, and the
`mode_changed` reasons.

1.9.0-d: the connection messages (`ready`, `auth`, `token_refresh` / `token_refreshed`), the full `error` shape,
`seq`, the refusals before the upgrade (incl. `503 relay_busy`), the close codes, the token rules on refresh and
resume, `relay.url`, and pinning of the relay host.

The contract for Live Assist (v1.9 Phase L): text-only help on screen while the other person talks. It is frozen for
the sprint: only the lead changes it, always with a bump. Its HTTP part moves into [API.md](API.md) when the L1 server
work merges, and the API documentation test covers it from then on. Everything here is invisible to users until v1.9 ships, and nothing of it ships before
Phase S is verified on real devices.

Conventions are those of [API.md](API.md): JSON, a device token with abilities, ids from the token and never from the
body, another workspace's ids answer `404`, errors as `{"message", "code"}`.

## Who talks to whom

```
 app (desktop / phone) ──REST──▶ server (Laravel)          preps, start, token refresh, turns, end, reports
 app ──WebSocket──▶ assist relay (services/assist-relay)    live turns in, suggestions out (transport "relay")
 app ──HTTPS + SSE──▶ server (Laravel)                     the same, one POST per message (transport "http")
 app ──WebSocket──▶ STT provider                            audio, with the short-lived STT token from the server
 relay ──HMAC REST──▶ server (internal)                     provider config, usage, suggestions, facts, summary
```

- The server is the only place provider keys live (Admin → API Credentials). The relay fetches the provider
  configuration from the server over the internal API at start and every 10 minutes, keeps it in memory only, and
  never writes it to disk or logs.
- Text is user data: it is encrypted at rest on the server, never logged by the relay, never used for training, and
  deleted with the session or the account.

## Plans (decided 2026-11-16, D5; revised 2026-11-17; per-plan values, editable in Admin › Plans)

| Plan | Live modes | Practice | Monthly allowance |
|---|---|---|---|
| Free | none | yes | 30 minutes of Practice, AI budget $1 |
| Pro (Rs 2,999) | `fast`, `balanced` | yes | 5 assist-hours, AI budget $15 |
| Ultra (Rs 12,499) | `fast`, `balanced`, `deep`, `max` | yes | 10 assist-hours, AI budget $60 |

Every LLM and STT call goes through the per-user budget and usage ledger (feature `live_assist`). An assist-hour is
wall-clock session time while the session is on, whichever transport carries it.

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
- `GET /api/assist/preps?status=` · `POST /api/assist/preps`
- `GET /api/assist/preps/{id}`
- `PATCH /api/assist/preps/{id}`
- `DELETE /api/assist/preps/{id}`
  ```json
  {"meeting_type": "interview", "title": "Interview at Acme", "counterpart": "Sara Khan", "company": "Acme",
   "project_id": "01JB…", "goals": "…", "done": "…", "not_done": "…", "to_discuss": "…", "instructions": "…",
   "hint_level": "outline", "mode": "balanced", "language_help": "roman_urdu",
   "scheduled_at": "2026-12-01T10:00:00Z", "status": "draft"}
  ```
  `meeting_type`: `interview` | `boss` | `existing_client` | `new_client` | `general`; `hint_level`: `keywords` |
  `outline` | `full` (interview defaults to `outline`); `mode`: `fast` | `balanced` | `deep` | `max` (refused per
  plan at start, not at save); `language_help`: `off` | `urdu` | `roman_urdu` | `arabic`; `status`: `draft` |
  `scheduled` | `armed` | `done` | `cancelled`. Text fields at most 4,000 characters each. Soft deleted. A prep created without `mode`, `hint_level` or
  `language_help` takes the person's Live Assist settings for that meeting type (Live Assist → Settings).
- `POST /api/assist/preps/{id}/documents` `{"file_ids": ["…"]}` attaches project files (the server extracts text and
  counts tokens); `GET /api/assist/preps/{id}` returns `documents: [{"file_id", "name", "tokens", "text"}]` (the
  text only on this single read, never in lists), and the app sends them in its first `context_update`; `DELETE /api/assist/preps/{id}/documents/{fileId}`.
- `POST /api/assist/preps/{id}/arm` → `200 {"armed_until": "…"}`: a scheduled prep is armed 2 minutes early and waits
  up to 10 minutes for the meeting; then it is cancelled.

### Sessions
- `POST /api/assist/sessions` — start (also "Start now" from a prep):
  ```json
  {"prep_id": "01JB…", "mode": "balanced", "platform": "desktop", "recording_session_id": "01JB…",
   "practice": false, "transports": ["relay", "http"]}
  ```
  → `201`
  ```json
  {"session": {"id": "01JC…", "auto_saved": true, "mode": "balanced", "practice": false},
   "transport": "relay",
   "relay": {"url": "wss://relay.example/v1/assist", "token": "…", "expires_at": "…"},
   "http": null,
   "stt": {"provider": "soniox", "ws_url": "…", "token": "…", "expires_at": "…", "translate_other": true},
   "plan": {"modes": ["fast", "balanced"], "seconds_left": 31200}}
  ```
  **Transport (since 1.9.0-q).** `transports` lists what the app can use (`relay`, `http`); absent means
  `["relay"]` (apps before 1.9.0-q). The server picks one for the whole session from Admin › Settings › Live Assist
  connection: **Automatic** (the relay when its address is set and its health check passed within the last minute,
  otherwise HTTP mode), **Shared hosting** (always HTTP mode) or **Dedicated relay** (always the relay). `transport`
  says which: `"relay"` with the `relay` block and `"http": null`, or `"http"` with `"relay": null` and
  ```json
  "http": {"live_url": "https://<server>/api/assist/sessions/01JC…/live",
           "ready": {"protocol": 1, "session_id": "01JC…", "mode": "balanced", "requested_mode": "balanced",
                     "allowed_modes": ["balanced", "fast"], "resumed": false, "practice": false,
                     "limits": {"max_message_bytes": 2097152, "max_turn_chars": 4000, "messages_per_second": 10}}}
  ```
  Refusals: `426 app_update_required` ("Live Assist needs the latest version of the app.") when the server's
  transport is not in `transports`; `503 relay_unavailable` ("Live Assist can't connect right now. Try again in a few
  minutes.") under Dedicated relay while the relay is down.
  One active session (live or practice) per person, across all their workspaces: a second start answers
  `409 session_active` with `{"session_id": "…", "practice": true|false, "device_id": "…"}`; the app may end it
  (`POST …/end`) and start again. `stt` is `null`
  when the server can't issue a captions token; that is allowed only with a running recording (the app then uses its
  own captions through `POST /api/stt/token`). Otherwise the start is refused with `503 provider_not_configured`.
  `auto_saved` is true when the session started from a prep: that call gets no after-call card. `recording_session_id`
  links the recording made at the same time (a session in status `recording` of this device). Practice:
  `"practice": true` without `recording_session_id`; the AI asks the questions.
- Final turns: for the `other` channel the apps send the relay's `turn_id` as the turn's `client_ref`, so the report
  pairs each hint with its question; `me` turns use the app's own ref.
- `POST /api/assist/sessions/{id}/token` → `200` with new `relay` and `stt` blocks (`stt` may be `null` when captions
  are down right now; the refresh still succeeds and the app asks again in 30 s, showing that captions are paused) (tokens last at most 15 minutes;
  apps refresh 2 minutes early and on a relay `error.code = "token_expired"`). In HTTP mode it answers `transport`,
  `"relay": null`, a fresh `http` block (its `ready` holds the mode as it is now) and `stt`; the app still refreshes
  every 13 minutes, which keeps the session on (a session not refreshed for 20 minutes is ended).
- `POST /api/assist/sessions/{id}/turns` — final turns in batches (every 5 s or 10 turns):
  `{"turns": [{"client_ref": "…", "channel": "other", "start_ms": 81200, "end_ms": 86400, "text": "…",
  "language": "en", "is_question": true}]}` → `200 {"accepted": 3}`; a repeated `client_ref` is ignored.
- `POST /api/assist/sessions/{id}/end` → `200 {"report": "queued"}`. It ends the person's own session in any of their
  workspaces (so the app can act on a `409 session_active`); anyone else's answers `404`.
- `GET /api/assist/sessions?limit=` · `GET /api/assist/sessions/{id}` · `GET /api/assist/sessions/{id}/report`
  (`404 report_not_ready` until the first report exists; a remade report replaces it; a session whose report could
  not be made shows `"report": "failed"`, and the report itself still answers `404 report_not_ready`). The report:
  `{"version", "mode", "practice", "meeting_type", "title", "started_at", "ended_at", "seconds", "turns",
  "ai": {"status", "reason", "model", "runs"}, "summary", "summary_translation", "qa": [{question, answer, hint,
  verdict, better_answer, missed}], "mistakes": [], "promises": [], "coaching": {"words_per_minute", "fillers",
  "talk_ratio", "pauses", "grammar": []}, "action_items": [], "memory_added", "follow_up_email", "facts",
  "running_summary", "suggestions", "used"}`; without the model (`ai.status` not `done`) the Q&A table, coaching,
  running summary and facts are still there · `DELETE /api/assist/sessions/{id}`.
- `POST /api/assist/sessions/{id}/ask` — `sessions:write`, 20 a minute per person. `{"question": "…"}` (at most 500
  characters) → `200 {"answer": "…", "citations": [{"at_ms", "channel", "text"}]}`: the answer comes only from the
  session's own lines, summary and facts; citations are the session's own lines, never the model's words; nothing is
  stored. Billed as usage kind `ask` to the session's allowance (reserved at the worst case before the call). `402
  budget_exhausted`, `503 provider_not_configured`, `502` when the call fails, `422` for a question over the limit or a
  session with nothing said.
- `POST /api/assist/sessions/{id}/suggestions/{suggestionId}/action` `{"action": "shown" | "used" | "dismissed" |
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

### Connecting (since 1.9.0-d)

- `seq` counts per direction and per connection, from 1.
- The first relay message on every connection is `ready`:
  `{"type":"ready","seq":1,"protocol":1,"session_id":"…","mode":"fast|balanced|deep|max|captions_only",
  "requested_mode":"fast|balanced|deep|max","allowed_modes":["balanced","fast"],"expires_at":"…+00:00",
  "resumed":false,"practice":false,"limits":{"max_message_bytes":2097152,"max_turn_chars":4000,
  "messages_per_second":40}}`. `allowed_modes` lists the most capable first; `requested_mode` is `balanced` until
  `context_update.settings.mode` says otherwise.
- `auth` (client → relay), only when the client can't send the header: `{"type":"auth","seq":1,"token":"…"}` within
  5 s of connecting. After sign-in it is answered with `error bad_message`.
- `token_refresh` (client → relay): `{"type":"token_refresh","seq":n,"token":"…"}` before `expires_at`. Success:
  `{"type":"token_refreshed","seq":m,"expires_at":"…"}`, possibly followed by `mode_changed`. Failure: `error`
  `token_refresh_rejected` (`retry: false`, `fatal: false`, `in_reply_to: n`); the old token stays valid until it
  expires. A token older (`iat`) than the newest one this session has used is refused, on refresh and on reconnect;
  going back to a token seen before continues its spend, never restores it.
- `error` always carries every field: `code`, `message`, `retry`, `suggestion_id` (or `null`), `in_reply_to` (or
  `null`), `fatal`. A sign-in failure is an `error` with `fatal: true` (`token_missing`, `token_invalid`,
  `token_expired`, `wrong_session`), then a close.
- Refused before the upgrade (HTTP): `401` `token_missing` / `token_invalid` / `token_expired`, `403`
  `wrong_session`, `400` `websocket_required`, `503 relay_busy` (connection ceiling; retry with backoff), `429` from
  the per-address limits in front of the relay.
- Close codes: `1009` a message above `max_message_bytes`; `1012` the relay is restarting (reconnect and resume);
  `4401` token expired or failed first-message `auth`; `4403` the session belongs to someone else; `4408` nothing
  received for 60 s; `4409` replaced by a newer connection for the same session (don't reconnect). The relay never
  sends `1000` itself.
- Resume: a reconnect with a valid token for the same `sid` gets `ready` with `resumed: true`. A relay restart loses
  live sessions; the client then sends its `context_update` again.
- `relay.url` (in the session start answer) is exactly `wss://<relay host>/v1/assist`: from one server setting, never
  from the request's host; no port, query or trailing slash; the same for the whole session.
- The apps pin the relay host's public key (plus a backup key) like the server's. Since 1.9.0-g `GET /api/config`
  carries them as `"relay_pins": ["sha256/…", "sha256/…"]` (present while `live_assist` is on for the person; an empty
  or missing list means Live Assist can't connect yet), read before the first connection. A pin mismatch refuses the connection with a clear message; recording is
  unaffected.
- Clients set `TCP_NODELAY` on the socket.

### Suggestions, fallbacks and upgrades (since 1.9.0-e, revised in 1.9.0-f)

- `suggestion_start` also carries `turn_id` (the `other` turn it answers), `kind` (what asked for it: `answer`,
  `help_now`, `regenerate`, `shorter`, `formal`, `translate`, `upgrade`), `speculative` (started on a partial; it may be
  cancelled) and `replaces` (the `suggestion_id` it replaces on the card, or `null`). A fallback after a provider
  failure keeps the original `kind` and is recognised by `replaces`.
- `suggestion_end` also carries `status`: `done`, `cancelled` (the person kept talking, or `cancel`) or `superseded`
  (replaced: by the suggestion whose `replaces` names it, or by the next question's suggestion taking the card, which
  has `replaces: null`). A suggestion that fails ends with an `error` carrying its `suggestion_id`, with no
  `suggestion_end`.
- `mode_changed.reason`: `budget`, `budget_restored`, `plan`, `assist_time`, `requested` (the app asked for the mode).
- Refusals: a model whose answers are held until complete (Sonnet and Opus, `hold_until_complete` in the model
  catalog) never shows refused text; the fallback answers. For a streamed model (Haiku, Groq), a refusal after words
  were shown ends that suggestion at once with `status: superseded`, and the app clears its text from the card at
  that moment, before the fallback's first token.

### Practice, facts and summary (since 1.9.0-h)

- Practice (a session started with `practice: true`): the client sends `practice_next` to get the next question. The
  relay plays Them for the meeting type and answers `practice_question` (`question_id`, `turn_id` `q-N`, `index`,
  `total`, `text`); the question counts as Them's turn, so a hint follows unless suggestions are paused, and the
  person answers by voice. After the last question (8) the relay sends `practice_done`. Errors: `not_practice`,
  `practice_busy` (retry), `budget_exhausted`. The app posts each question in its batched turns: channel `other`,
  `client_ref` = `question_id`, `is_question: true`, so the report pairs each question with the answers after it.
- The relay keeps the facts ledger (after every 3 final lines) and the running summary (at most every 5 minutes) on
  the Fast model in the background, within the budget; a call that could cross the budget is skipped.
  `POST /api/internal/assist/facts` `{session_id, facts: [...], at}` and `POST /api/internal/assist/summary`
  `{session_id, summary, at}` replace what the server stored.
- Usage kinds posted by the relay: `suggestion`, `practice_question`, `facts`, `summary`; idempotent per id. The
  server's own calls are usage kinds `report` and `ask`, charged to the session's allowance. Practice
  usage is billed to the plan's Practice allowance.

### What the apps must handle (since 1.9.0-n)

- Every `suggestion_start` ends with exactly one of `suggestion_end`, `skip` (`suggestion_id`, `turn_id`, `latency`,
  `usage`; no tokens) or an `error` naming it. On `ready` with `resumed: true`, clear any card still streaming: a
  suggestion running when the connection dropped gets no end.
- Error codes besides those above: `provider_not_configured` (retry false; show the relay's fixed message, which
  names no vendor), `provider_unavailable` and `provider_timeout` (retry true), `model_declined` (retry false),
  `nothing_to_rewrite`, `documents_too_large` (the old documents stay), `rate_limited`, `bad_message` (an unknown
  `type` or a binary frame; the connection stays open). Apps show their own plain sentence per code.
- `budget_exhausted`: with `retry: false` (help now or a rewrite in `captions_only`) say suggestions are off; with
  `retry: true` (the budget gate refused it for now) say "try again in a moment". An automatic question the gate holds
  gets no message and may start up to 5 s later.
- `context_update` replaces: a field that is present replaces what the relay had, an empty value clears it. Fields:
  `brief`, `documents`, `memory`, `facts`, `summary`, `settings` (`mode`, `hint_level`, `language_help`,
  `assist`: `on` | `paused`). Limits: 20 documents, 400,000 characters each, about 60,000 tokens in all, 200 facts.
- Upgrades arrive as one `token` with the whole text; `replaces` may name a draft that already ended `done`. A
  speculative answer whose final changed ends `superseded` (still streaming; the new start has `replaces: null`) or is
  named by the new start's `replaces` (already finished).
- `token_expired` mid-session: an `error` with `fatal: true`, `retry: true`, then close `4401`; the session waits 120 s
  for a reconnect with a new token. A reader too slow for the relay is dropped without a close frame (the app sees
  1006): reconnect and resume.
- Practice: a question that can't be written fails twice with `retry: true`, the third time with `retry: false`; the
  next `practice_next` gets `practice_done` (`asked`). A practice hint is an ordinary suggestion with `turn_id` `q-N`;
  with `assist: paused` there is no hint, but help now and rewrites still work.
- Optional fields: `help_now.turn_id`, `cancel.suggestion_id` (absent: every shown suggestion), `translate.language`;
  `mode_changed` carries `from` and `to`. Refusals before the upgrade answer `{"code", "message"}`.
- One relay connection per session: a second connection for the same session replaces the first (`4409`). There is
  no viewer role; a phone shows Live Assist only for its own sessions.
- The app reports when a suggestion was first drawn with `POST /api/assist/sessions/{id}/suggestions/{suggestionId}/action`
  `{"action": "shown", "render_ms": 412}` (time from the relay's `suggestion_start` to the first text on screen).

Rules: a suggestion is never triggered from the `me` channel; a speculative start on an `other` partial that looks
like a question (≥ 128 ms of silence) is cancelled and restarted if they keep talking within 700 ms; a balanced draft
is replaced by its upgrade only if the person has not started speaking; captions never stop when the budget runs out.
The relay's own draft of these messages is `services/assist-relay/PROTOCOL.md`; this file wins where they differ.

## HTTP mode (since 1.9.0-q)

The same messages as the WebSocket, without a long-lived connection: for servers that can't hold one (shared
hosting). Everything under "WebSocket (relay)" holds unless this section says otherwise.

- **One POST per app message:** `POST {http.live_url}` — `sessions:write`, the device token (no relay token),
  `Accept: text/event-stream`, the body one app message exactly as on the WebSocket (`context_update`, `turn_final`,
  `turn_partial`, `help_now`, `regenerate`, `shorter`, `formal`, `translate`, `cancel`, `practice_next`; no `auth`,
  `token_refresh` or `seq` needed). Before the stream starts the usual REST errors apply (`401`, `404`,
  `409 session_ended`, `422`, `429`, `426 app_update_required` on a session whose transport is `relay`).
- **The answer is a stream** of server-sent events, `200 text/event-stream`, one relay message per event:
  `event: <type>` and `data: <the message as JSON>`, the same JSON as on the WebSocket (`suggestion_start`, `token`,
  `suggestion_end`, `skip`, `mode_changed`, `practice_question`, `practice_done`, `error`); `seq` counts per stream
  from 1. The stream ends when the work for that message is done (`event: end`, `data: {}`, then the response closes).
  A message with nothing to say (a `me` turn, `context_update`) answers just `end`. Lines starting with `:` are
  keep-alives. A stream lasts at most 60 s.
- **Card semantics are those of the WebSocket:** the app feeds the events of every open stream into one card, in
  arrival order. Whoever ends a suggestion sends its end: a new question's stream sends `suggestion_end` `superseded`
  for the suggestion on the card before its own `suggestion_start`; a `cancel` stream sends `suggestion_end`
  `cancelled`; the stream that was writing that suggestion then stops without another event about it. An upgrade and
  a fallback (`replaces`) come in the stream of the question they answer. In such an end sent by another stream,
  `usage` is the estimate at that moment (`estimated: true`).
- **Turns:** send every `turn_final` (both channels; `client_ref` is not needed: the server stores the turns of HTTP
  mode itself, and `POST …/turns` is not needed either). Send `turn_partial` only for `me`, once per turn, as soon as
  Me starts speaking (it tells an upgrade or a held question that the moment has passed); `other` partials are not
  sent: HTTP mode has no speculative starts (`speculative` is always false).
- **Held questions:** a question the budget gate can't take yet keeps its stream open, quiet except for keep-alives,
  for at most 5 s; it starts when calls in flight finish, or the stream ends with nothing when Me speaks, the other
  side sends another final, or the 5 s pass.
- **Not in HTTP mode:** `ready` (it is `http.ready` in the start and refresh answers), `auth`, `token_refresh` /
  `token_refreshed`, close codes, resume, `relay_pins` (the server's own pins apply), `rate_limited` (HTTP `429`
  instead). At most 4 streams open per session; a fifth answers `429`.
- **Practice:** `practice_next` answers `practice_question` and then, in the same stream, the hint for it
  (unless paused), or `practice_done`.
- **Apps:** use the HTTP/2 connection the platform keeps alive (no new TLS handshake per message); keep the card logic
  shared with the relay transport; on a dropped stream, the suggestion it was writing gets no end: clear it as after a
  resumed `ready`.

## Output contract (prompt pack)

First line at most 25 words in simple English; at most 3 bullets with the key words in bold; an optional "Say next";
an optional Urdu / Roman Urdu line explaining the question; exactly `SKIP` when no reply is needed; never ask for
something already said; consistent with the facts ledger; never invent experience, numbers or promises.

The pack (web/resources/prompts/assist/, version 1.0.1) fixes two things the apps rely on: the language line starts
with "Urdu:", "Roman Urdu:" or "Arabic:" (apps may style it apart), and the consent line for boss, client and general
meetings is "Just so you know: I record this call and use an AI assistant that takes notes and suggests points to me.
Is that okay with you?" (the honest version: it also suggests answers; Hassam reviews the wording before launch, N7).
