# Live Assist relay protocol (protocol 1)

**Status: draft, adopted into docs/API.md by the lead.** Until then this file is the reference for the relay in
`services/assist-relay`.

> **Being aligned with the assist contract 1.9.0-b (docs/API_ASSIST.md), which wins where they differ.** The code
> already follows it: the endpoint is `wss://<relay>/v1/assist` (the session is the token's `sid`), with
> `Authorization: Bearer` or a first message `{"type": "auth", "token": …}`; claims `ws`, `budget.seconds_left`,
> `budget.cents_left` (what is left at minting; the relay counts down from it), `practice`, `jti`; one secret
> (`RELAY_SECRET`, the `assist_relay` secret) for tokens and the signed internal API; every message carries `seq`;
> `error` carries `retry` (and `in_reply_to`, the app's `seq`); `context_update.brief` (was `prep`) and
> `turn_*.language` (was `lang`); the context is rendered from the server's pack 1.0.1 (`pack/`). The sections below
> still show the earlier draft in places and are rewritten next. Field names follow the REST contract: snake_case, machine-readable `code`, plain-words
`message`, durations in milliseconds, timestamps ISO-8601 UTC.

## Connecting

```
GET wss://<relay host>/v1/assist/sessions/{session_id}/ws
Authorization: Bearer <assist token>
```

- The token is checked **before** the upgrade. A refused connection gets an HTTP answer and no socket:
  `401 {"code": "token_missing" | "token_invalid" | "token_expired", "message": "…"}`, or
  `403 {"code": "wrong_session"}` when the token names another session (or the session belongs to someone else).
  A request without WebSocket headers gets `400 websocket_required`.
- One connection per session. A second connection with a valid token takes over: the first is closed with `4409`.
  After a dropped connection the session waits 120 s (`resume_grace_secs`); a reconnect within that time resumes it
  (`ready.resumed: true`) with its context, transcript and spend. Suggestions in flight when the connection dropped are
  cancelled.
- Frames are JSON text, one object per frame, named by `type`. Binary frames get `error bad_message`. The largest
  message is 2 MiB (larger closes the socket with `1009`). Unknown fields are ignored; unknown types get
  `error bad_message` and the connection stays open.
- The relay pings every 20 s. A connection that sends nothing (not even a pong) for 60 s is closed with `4408`.
- At most 40 messages a second, bursts of 80; the rest are dropped with `error rate_limited` (at most one a second).
- `GET /healthz` answers `200 {"status": "ok", "version": "0.1.0"}` (no authentication, no session data).

### The assist token

A compact JWS signed with HS256 (`{"alg":"HS256","typ":"JWT"}`), minted by the server at assist start and token
refresh, with the shared secret `RELAY_TOKEN_SECRET` (at least 32 characters; during a rotation the relay also accepts
`RELAY_TOKEN_SECRET_PREVIOUS`). Only HS256 is accepted; `alg: none`, other algorithms and `crit` headers are refused.

| Claim | Type | Meaning |
|---|---|---|
| `iss` | string | `echoledger` (`RELAY_TOKEN_ISSUER`) |
| `aud` | string | `assist-relay` |
| `sub` | string | the user id |
| `tid` | string, optional | the workspace id |
| `sid` | string | the assist session id; must equal `{session_id}` in the URL |
| `plan` | `free` \| `pro` \| `ultra` | the relay checks it again: Pro allows `fast`, `balanced`; Ultra all modes; Free no live suggestions |
| `modes` | list of modes | the modes this session may use |
| `models` | list of model ids | the models this session may use (primary, fallback and upgrade models alike) |
| `budget.limit_usd` | number | the most this session may spend on models, counted from the session's start |
| `budget.assist_seconds` | integer, optional | the most assist time this session may use, counted from its start |
| `iat`, `exp` | unix seconds | `exp - iat` at most 900; 30 s of clock leeway |
| `jti` | string, optional | not checked by the relay |

Ids (`sub`, `tid`, `sid`, model ids) are 1–128 characters of `A-Z a-z 0-9 - _ . : /`.

## App → relay

### `context_update`

Everything about the call except the transcript. Every field is optional; a field that is present replaces what the
relay had (an empty string or list clears it). Send it once after `ready` and again when something changes.

```json
{"type": "context_update",
 "prep": {"meeting_type": "interview", "title": "Backend role", "counterpart": "Sara", "company": "Acme",
          "goals": "…", "done": "…", "not_done": "…", "to_discuss": "…", "instructions": "…"},
 "settings": {"mode": "balanced", "hint_level": "outline", "language_help": "urdu", "assist": "on"},
 "documents": [{"id": "01JD…", "title": "CV", "text": "…"}],
 "memory": "…",
 "facts": [{"id": "f1", "text": "Budget: 5,000 USD (minute 2)"}],
 "summary": "…"}
```

- `meeting_type`: `interview` | `boss` | `existing_client` | `new_client` | `general` (default).
- `settings.mode`: `fast` | `balanced` | `deep` | `max` (default `balanced`). The relay runs the most capable allowed
  mode at or below it and answers with `mode_changed` when that changes. `hint_level`: `keywords` | `outline` (default) |
  `full`. `language_help`: `off` (default) | `urdu` | `roman_urdu` | `arabic`. `assist`: `on` (default) | `paused`
  (no automatic suggestions; help now and the rewrites still work; captions and the transcript continue).
- Limits: 20 documents of at most 400,000 characters each and about 60,000 tokens together (more gets
  `error documents_too_large` and the old documents stay); 200 facts of 1,000 characters; other fields 20,000.

### `turn_partial` and `turn_final`

The speech engine's text for one turn. Partials and the final of one turn share `turn_id`.

```json
{"type": "turn_partial", "turn_id": "o-12", "channel": "other", "text": "so what is your notice",
 "lang": "en", "start_ms": 731200, "silence_ms": 140}
{"type": "turn_final", "turn_id": "o-12", "channel": "other", "text": "So what is your notice period?",
 "lang": "en", "start_ms": 731200, "end_ms": 733900}
```

- `channel`: `me` (the person using EchoLedger) or `other` (`client`, the speech grants' name, is accepted too).
- `start_ms`, `end_ms`: since the start of the session, on the audio clock. `silence_ms`: on partials, how long the
  speaker has been silent as the speech engine reports it. Send a partial again when only the silence grows.
- `text` at most 4,000 characters; `turn_id` 1–64 characters.
- Finals of both channels form the transcript the relay sends to the model. **The `me` channel never starts a
  suggestion.**

### `help_now`

`{"type": "help_now"}` or `{"type": "help_now", "turn_id": "o-12"}`: a suggestion now, for the latest turns.

### `regenerate`, `shorter`, `formal`, `translate`

```json
{"type": "shorter", "suggestion_id": "1f3a-7"}
{"type": "translate", "suggestion_id": "1f3a-7", "language": "urdu"}
```

A new suggestion based on a finished one (the latest when `suggestion_id` is absent); it carries `replaces`.
`translate.language` is `urdu`, `roman_urdu` or `arabic`. With nothing to change: `error nothing_to_rewrite`.

### `cancel`

`{"type": "cancel"}` or `{"type": "cancel", "suggestion_id": "1f3a-7"}`: stops the suggestion (all shown ones when
absent); each gets `suggestion_end` with `status: "cancelled"`. The provider request is closed, so it stops costing.

### `token_refresh` (addition)

`{"type": "token_refresh", "token": "<new assist token>"}`, before `expires_at`. The new token must name the same
session and user. Answer: `token_refreshed {"expires_at": "…"}`, or `error token_refresh_rejected` (the old token
stays valid until it expires). The new token's plan, modes, models and budget apply at once.

## Relay → app

### `ready` (addition)

The first message on every connection.

```json
{"type": "ready", "protocol": 1, "session_id": "01JS…", "mode": "balanced", "requested_mode": "balanced",
 "allowed_modes": ["balanced", "fast"], "expires_at": "2026-11-16T09:15:00+00:00", "resumed": false,
 "limits": {"max_message_bytes": 2097152, "max_turn_chars": 4000, "messages_per_second": 40}}
```

`mode` is `fast` | `balanced` | `deep` | `max` | `captions_only`.

### `suggestion_start`

```json
{"type": "suggestion_start", "suggestion_id": "1f3a-7", "turn_id": "o-12", "kind": "answer", "mode": "balanced",
 "model": "claude-haiku-5-5", "speculative": true, "draft": true, "replaces": null}
```

- `kind`: `answer` (the other person's words) | `help_now` | `regenerate` | `shorter` | `formal` | `translate` |
  `upgrade`.
- `speculative`: started on a partial, before the end of the turn.
- `draft`: Balanced sent a hard question to the upgrade model too; an `upgrade` may replace this suggestion.
- `replaces`: the suggestion this one takes the place of on the card.
- Every `suggestion_start` is followed by exactly one of `suggestion_end`, `skip`, or `error` with this
  `suggestion_id`.

### `token`

`{"type": "token", "suggestion_id": "1f3a-7", "text": "Yes, I "}`: the next piece of the answer, in order. Append it.
The answer follows the output contract: a first line of at most 25 words, at most 3 bullets with the key words in
`**bold**`, an optional `Say next:` line, and the language line when language help is on.

### `suggestion_end`

```json
{"type": "suggestion_end", "suggestion_id": "1f3a-7", "status": "done", "model": "claude-haiku-5-5",
 "latency": {"end_of_turn_to_request_ms": 0.4, "request_to_first_token_ms": 351.2,
             "end_of_turn_to_first_token_ms": 352.0, "relay_overhead_ms": 0.8, "total_ms": 702.5},
 "usage": {"input_tokens": 312, "cached_input_tokens": 4100, "cache_write_tokens": 0, "output_tokens": 31,
           "cost_usd": 0.000088, "estimated": false}}
```

- `status`: `done` | `cancelled` (the app cancelled it, or the other person kept talking within 700 ms of a
  speculative start) | `superseded` (a newer suggestion took its place).
- `model`: the model that answered (the fallback model, when the first one failed before its first token).
- `latency` is measured from the "end of turn": when the relay read the message that started the suggestion.
  `request_to_first_token_ms` is the model's share; `relay_overhead_ms` is the rest. Fields the suggestion never
  reached are `null`.
- `usage.estimated`: the provider sent no counts (cancelled early), so these are the relay's estimate.

### `skip`

```json
{"type": "skip", "suggestion_id": "1f3a-7", "turn_id": "o-12", "latency": {…}, "usage": {…}}
```

The model answered exactly `SKIP`: no reply is needed. No `token` was sent for this suggestion (the relay holds the
first tokens back while they could still be `SKIP`), and a `draft`'s upgrade is dropped.

### `error`

```json
{"type": "error", "code": "provider_timeout", "message": "The model did not answer in time. Try again.",
 "suggestion_id": "1f3a-7", "fatal": false}
```

With a `suggestion_id` it ends that suggestion. `fatal: true` means the relay closes the connection next.

| `code` | When | What the app does |
|---|---|---|
| `bad_message` | Not JSON, unknown `type`, a field too long, a binary frame | Fix the message; the connection stays |
| `rate_limited` | More than 40 messages a second | Send less often |
| `documents_too_large` | `documents` above about 60,000 tokens | Send the parts that matter, or retrieval chunks |
| `nothing_to_rewrite` | A rewrite before any finished suggestion | Nothing |
| `budget_exhausted` | Help now or a rewrite while the mode is `captions_only` | Show "Suggestions are off"; captions continue |
| `provider_not_configured` | The model's provider has no key on the relay | Show the message |
| `provider_unavailable`, `provider_timeout`, `model_declined` | The model failed (after the fallback model, if any) | Show the message; help now may work |
| `token_refresh_rejected` | `token_refresh` with a bad, expired or other token | Get a new token |
| `token_expired` (fatal) | The token expired without a refresh | Reconnect with a new token (the session resumes) |

### `mode_changed` (addition: the budget notice)

```json
{"type": "mode_changed", "from": "deep", "to": "balanced", "reason": "budget"}
```

`reason`: `requested` (the app's `settings.mode`) | `plan` (the plan or token does not allow the requested mode) |
`budget` (the budget left pays for fewer than 3 more suggestions in this mode, or 1 in Fast) | `budget_restored` (a
refreshed token brought more budget) | `assist_time` (the plan's assist time is used up). The steps are
`max` → `deep` → `balanced` → `fast` → `captions_only`. In `captions_only` the relay makes no suggestions but keeps
the connection, the transcript and the context, so suggestions resume when a new token brings budget.

### `token_refreshed` (addition)

`{"type": "token_refreshed", "expires_at": "2026-11-16T09:30:00+00:00"}`

## When suggestions start

- **Speculative start:** an `other` partial whose words look like a question (English, Urdu in both scripts, Arabic,
  or a `?`/`؟`) with `silence_ms` ≥ 128 starts a suggestion (`speculative: true`).
- **Keep talking:** a partial of the same turn with different words within 700 ms of that start cancels it
  (`suggestion_end` `cancelled`); the next qualifying pause starts it again. After 700 ms the suggestion stands.
- **End of turn:** a `turn_final` from `other` starts a suggestion unless the speculative one already answers the
  same words (at most one word in ten changed). When the words changed, the speculative one is superseded or replaced.
  Short replies that need no answer ("okay", "theek hai", "تمام") start nothing.
- **Never** from `me`. A suggestion in flight is superseded by the next one: one answer on the card at a time.
- **Balanced upgrade:** a hard question (14+ words, or "why", "explain", "walk me through"…) also goes to the upgrade
  model. When it finishes, it replaces the draft (`kind: upgrade`, `replaces: <draft id>`, the whole text in one
  `token`) unless I spoke after the question, the draft was a SKIP, or the draft is gone.

## Close codes

| Code | Meaning |
|---|---|
| `1000` | Normal close |
| `1009` | A message above 2 MiB |
| `1012` | The relay is restarting: reconnect (the session resumes on the same relay) |
| `4401` | The token expired (after `error token_expired`) |
| `4403` | The session belongs to someone else |
| `4408` | Nothing received for 60 s |
| `4409` | Replaced by a newer connection for this session |

## Open questions for the contract

1. **Relay URL and session id:** the server's `assist/start` returns `relay_url` (this path, with the session id) and
   the first token. Is `session_id` the `assist_sessions` ULID?
2. **Budget semantics:** proposed as cumulative from the session's start (`limit_usd`, `assist_seconds`). The relay
   keeps each session's spend for 24 hours in memory; after a relay restart the server's next token must already
   subtract what was reported. That needs the signed internal usage endpoint (relay → server), not built yet.
3. **Internal endpoints (HMAC):** usage per suggestion (the `suggestion_end` numbers), facts ledger updates and the
   running summary. Who writes the facts ledger and the summary: the relay's background jobs (every 3 final turns, every
   ~5 minutes) or the server? Until decided the app sends both in `context_update`.
4. **Provider keys:** keys live only in Admin → API Credentials. Proposal: the relay fetches them at start over a signed
   internal endpoint and keeps them in memory only.
5. **The instruction pack:** the plan keeps it on the server with the other assist resources. The relay carries a
   copy (`instructions/pack.toml`); proposal: one source, fetched by the relay or copied into its build.
6. **First render:** the plan's telemetry includes "first render", which only the app knows. Add
   `rendered {"suggestion_id", "first_render_ms"}` (app → relay), or send it with the batched turns to the server?
7. **Channel name:** the speech grants call the other side `client`; the plan says `other`. The relay accepts both and
   sends `other`.
8. **Browsers:** practice mode in the console cannot set `Authorization` on a WebSocket. If it uses the relay, accept
   the token in `Sec-WebSocket-Protocol` too?
9. **Second screen (L6):** a linked phone would be a second connection to the same session that only receives. That
   needs a viewer role in the token (`role: viewer`), since today a second connection takes over.
