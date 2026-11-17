# Live Assist relay protocol (protocol 1)

The messages between an app and the relay in `services/assist-relay`, in detail. They follow the Live Assist contract
**1.9.0-j** (docs/API_ASSIST.md), which wins where the two differ. Field names are snake_case, every error has a
machine-readable `code` and a plain-words `message`, durations are milliseconds and timestamps ISO-8601 UTC
(`2026-11-16T09:15:00+00:00`).

## Connecting

```
GET wss://<relay host>/v1/assist
Authorization: Bearer <assist token>
```

- **The address.** The server's session start answer gives it as `relay.url`: exactly `wss://<relay host>/v1/assist`,
  from one server setting, the same for the whole session. The session is the token's `sid`; nothing about the
  session is in the URL.
- **The token** goes in `Authorization: Bearer` and is checked before the upgrade. An app that can't set headers on a
  WebSocket sends `{"type": "auth", "seq": 1, "token": "…"}` as its first message instead, within 5 s; until then
  nothing else is read. `auth` after sign-in is answered with `error bad_message`.
- **Refused before the upgrade** (an HTTP answer `{"code", "message"}`, no socket):

  | Status | `code` | When |
  |---|---|---|
  | 400 | `websocket_required` | Not a WebSocket request |
  | 401 | `token_missing`, `token_invalid`, `token_expired` | No token, a bad one (signature, audience, claims, an algorithm other than HS256, older than the newest this session used), or an expired one |
  | 403 | `wrong_session` | The session belongs to someone else |
  | 503 | `relay_busy` | The relay holds `max_connections` sockets already: try again with backoff |
  | 429 | (from nginx) | The per-address limits in front of the relay (DEPLOY.md) |

  A failed first-message `auth` is an `error` with `fatal: true` (one of the codes above), then close `4401` (`4403`
  for `wrong_session`).
- **Pinning.** The apps pin the relay host's public key (plus a backup key) as they pin the server's, from the
  device security list, before the first connection. A mismatch refuses the connection with a clear message; recording
  is unaffected.
- **TCP_NODELAY** on the app's socket: a partial and a final sent back to back otherwise wait for an acknowledgement.
  The relay sets it on its side.
- One connection per session. A second connection with a valid token takes over; the first is closed with `4409` and
  should not reconnect.
- **Resume.** After a dropped connection the session waits 120 s (`resume_grace_secs`). A reconnect with a valid token
  for the same `sid` gets `ready` with `resumed: true`, with its context, transcript and spend. Suggestions in flight
  when the connection dropped are cancelled. A relay restart loses live sessions: the app gets `ready` with
  `resumed: false` and sends its `context_update` again.
- Frames are JSON text, one object per frame, named by `type`, each with `seq`: counted per direction and per
  connection, from 1. Binary frames get `error bad_message`. The largest message is 2 MiB; larger closes the socket
  with `1009`. Unknown fields are ignored; an unknown `type` gets `error bad_message` and the connection stays.
- The relay pings every 20 s. A connection that sends nothing (not even a pong) for 60 s is closed with `4408`.
- At most 40 messages a second, bursts of 80; the rest are dropped with `error rate_limited` (at most one a second).
- `GET /healthz` answers `200 {"status": "ok", "version": "…"}` (no token, no session data).

### The assist token

A compact JWS, HS256 only (`alg: none`, other algorithms and `crit` headers are refused), signed with the
`assist_relay` secret (during a rotation the previous secret is accepted too: DEPLOY.md). Claims: `iss`
`"echoledger"`, `aud` `"assist-relay"`, `sub` (user), `ws` (workspace), `sid` (session), `plan` (`free` | `pro` |
`ultra`; the relay checks it again for live sessions: Pro allows `fast` and `balanced`, Ultra every mode, Free no live
suggestions; a Practice session, `practice: true`, gets `fast` on every plan, Free included, within its allowance),
`modes` (allowed), `models` (allowed ids: primary, fallback and upgrade models alike), `budget.seconds_left` and
`budget.cents_left` (what is left when the server mints the token; the relay counts down from the token's first
arrival), `practice`, `iat`, `exp` (`exp − iat` at most 900 s, 30 s of clock leeway), `jti`.

**Tokens over a session.** The relay remembers where each token (by `iat` and `jti`) first arrived. A reconnect or a
refresh with a token seen before continues where that token was: going back and forth between tokens never restores
budget. A token issued before (`iat`) the newest one the session has used is refused, on refresh and on reconnect.

## App → relay

### `context_update`

Everything about the call except the transcript. Every field is optional; a field that is present replaces what the
relay had (an empty string or list clears it). Send it once after `ready` and again when something changes.

```json
{"type": "context_update", "seq": 2,
 "brief": {"meeting_type": "interview", "title": "Backend role", "counterpart": "Sara", "company": "Acme",
           "goals": "…", "done": "…", "not_done": "…", "to_discuss": "…", "instructions": "…"},
 "settings": {"mode": "balanced", "hint_level": "outline", "language_help": "urdu", "assist": "on"},
 "documents": [{"id": "01JD…", "title": "CV", "text": "…"}],
 "memory": "…",
 "facts": [{"id": "f1", "text": "Budget: 5,000 USD (minute 2)"}],
 "summary": "…"}
```

- `meeting_type`: `interview` | `boss` | `existing_client` | `new_client` | `general` (default). `prep` is accepted
  for `brief`.
- `settings.mode`: `fast` | `balanced` | `deep` | `max` (default `balanced`). The relay runs the most capable allowed
  mode at or below it and sends `mode_changed` when that changes. `hint_level`: `keywords` | `outline` | `full`
  (default: the meeting type's). `language_help`: `off` (default) | `urdu` | `roman_urdu` | `arabic`. `assist`:
  `on` (default) | `paused` (no automatic suggestions; help now and the rewrites still work; the transcript continues).
- Limits: 20 documents of at most 400,000 characters each and about 60,000 tokens together (more gets
  `error documents_too_large` and the old documents stay); 200 facts of at most 1,000 characters; other fields
  20,000 characters.

### `turn_partial` and `turn_final`

The speech engine's text for one turn. The partials and the final of one turn share `turn_id`.

```json
{"type": "turn_partial", "seq": 7, "turn_id": "o-12", "channel": "other", "text": "so what is your notice",
 "language": "en", "start_ms": 731200, "silence_ms": 140}
{"type": "turn_final", "seq": 8, "turn_id": "o-12", "channel": "other", "text": "So what is your notice period?",
 "language": "en", "start_ms": 731200, "end_ms": 733900}
```

- `channel`: `me` (the person using EchoLedger) or `other` (`client`, the speech grants' name, is accepted).
- `start_ms`, `end_ms`: since the start of the session, on the audio clock. `silence_ms`: on partials, how long the
  speaker has been silent as the speech engine reports it; send the partial again when only the silence grows.
- `text` at most 4,000 characters; ids 1–64 characters. `lang` is accepted for `language`.
- The finals of both channels form the transcript the relay sends to the model. A session keeps at most about 40,000
  tokens of older transcript (about three hours); past it the oldest parts are dropped and the running summary
  covers them. **The `me` channel never starts a suggestion.**

### `help_now`

`{"type": "help_now", "seq": 9}` or with `"turn_id": "o-12"`: a suggestion now, for the latest turns (or that one).

### `regenerate`, `shorter`, `formal`, `translate`

```json
{"type": "shorter", "seq": 10, "suggestion_id": "1f3a-7"}
{"type": "translate", "seq": 11, "suggestion_id": "1f3a-7", "language": "urdu"}
```

A new suggestion based on a finished one (the latest when `suggestion_id` is absent), with `replaces` set to it. It is
a rewrite, never `SKIP`. `translate.language` is `urdu`, `roman_urdu` or `arabic`: the answer is one or two lines in
that language starting with its prefix (`Urdu:`, `Roman Urdu:`, `Arabic:`), then a `Say:` line in English. With
nothing finished to rewrite: `error nothing_to_rewrite`.

### `cancel`

`{"type": "cancel", "seq": 12}` or with `"suggestion_id"`: stops that suggestion (all shown ones when absent); each
ends with `suggestion_end` `cancelled`. The provider request is closed, so it stops costing.

### `token_refresh`

`{"type": "token_refresh", "seq": 13, "token": "<new assist token>"}`, before `expires_at` (apps refresh 2 minutes
early). The new token must name the same session and user and not be older than the newest one used. Success:
`token_refreshed`, possibly followed by `mode_changed`; the new token's plan, modes, models and budget apply at once.
Failure: `error token_refresh_rejected` (`retry: false`, `fatal: false`, `in_reply_to` the refresh's `seq`); the old
token stays valid until it expires.

### `practice_next` (practice sessions)

`{"type": "practice_next", "seq": 14}`: in a session whose token has `practice: true`, the next practice question.
Send it to start, and again when the person has finished answering (the app decides when: the answer may be several
`me` finals). Answer: `practice_question`, or `practice_done` when every question was asked. Errors: `not_practice`
(a live session), `practice_busy` (the last one is still being written; `retry: true`), `budget_exhausted` (the
Practice allowance is used up, or the question could cross it). A question that can't be written (both models
failed, or the answer was unusable) gets the provider's error with `retry: true` twice; the third failed try at the
same question says `retry: false` and practice stops: the next `practice_next` gets `practice_done`.

## Relay → app

### `ready`

The first message on every connection.

```json
{"type": "ready", "seq": 1, "protocol": 1, "session_id": "01JS…", "mode": "balanced", "requested_mode": "balanced",
 "allowed_modes": ["balanced", "fast"], "expires_at": "2026-11-16T09:15:00+00:00", "resumed": false,
 "practice": false, "limits": {"max_message_bytes": 2097152, "max_turn_chars": 4000, "messages_per_second": 40}}
```

`mode` is `fast` | `balanced` | `deep` | `max` | `captions_only`. `allowed_modes` lists the most capable first.
`requested_mode` is `balanced` until `context_update.settings.mode` says otherwise.

### `token_refreshed`

`{"type": "token_refreshed", "seq": 20, "expires_at": "2026-11-16T09:30:00+00:00"}`

### `suggestion_start`

```json
{"type": "suggestion_start", "seq": 21, "suggestion_id": "1f3a-7", "turn_id": "o-12", "kind": "answer",
 "mode": "balanced", "model": "claude-haiku-5-5", "speculative": true, "draft": true, "replaces": null}
```

- `kind`: `answer` (the other person's words) | `help_now` | `regenerate` | `shorter` | `formal` | `translate` |
  `upgrade`.
- `speculative`: started on a partial, before the end of the turn.
- `draft`: Balanced also sent this hard question to the upgrade model; an `upgrade` may replace it.
- `replaces`: the suggestion this one takes the place of on the card (a rewrite, an upgrade, or the fallback model
  after a broken answer; see below). A fallback keeps the `kind` of what it replaces and is recognised by `replaces`.
- Every `suggestion_start` is followed by exactly one of `suggestion_end`, `skip` or `error` with its
  `suggestion_id`.

### `token`

`{"type": "token", "seq": 22, "suggestion_id": "1f3a-7", "text": "Yes, I "}`: the next piece of the answer, in
order; append it. The answer follows the output contract: a first line of at most 25 words, at most 3 bullets with
the key words in `**bold**`, an optional `Say next:` line, and the language line when language help is on.

### `suggestion_end`

```json
{"type": "suggestion_end", "seq": 30, "suggestion_id": "1f3a-7", "status": "done", "model": "claude-haiku-5-5",
 "latency": {"end_of_turn_to_request_ms": 0.4, "request_to_first_token_ms": 351.2,
             "end_of_turn_to_first_token_ms": 352.0, "relay_overhead_ms": 0.8, "total_ms": 702.5},
 "usage": {"input_tokens": 312, "cached_input_tokens": 4100, "cache_write_tokens": 0, "output_tokens": 31,
           "cost_usd": 0.000088, "estimated": false}}
```

- `status`: `done` | `cancelled` (the app cancelled it, or the other person kept talking within 700 ms of a
  speculative start) | `superseded` (replaced: by the suggestion whose `replaces` names it, such as an upgrade or the
  fallback after a broken answer, or by the next question's suggestion taking the card, which has `replaces: null`).
  A suggestion that fails gets no `suggestion_end`: it ends with an `error` carrying its `suggestion_id`.
- `model`: the model that answered (the fallback model when the first failed before showing anything).
- `latency` counts from the "end of turn": when the relay read the message that started the suggestion.
  `request_to_first_token_ms` is the model's share; `relay_overhead_ms` the rest. Stages never reached are `null`.
- `usage.estimated`: the provider sent no counts (cancelled early), so these are the relay's estimate.

### `skip`

```json
{"type": "skip", "seq": 25, "suggestion_id": "1f3a-7", "turn_id": "o-12", "latency": {…}, "usage": {…}}
```

The model answered exactly `SKIP`: no reply is needed. No `token` was sent for it (the relay holds the first tokens
back while they could still be `SKIP`), and a draft's upgrade is dropped.

### `mode_changed`

```json
{"type": "mode_changed", "seq": 26, "from": "deep", "to": "balanced", "reason": "budget"}
```

`reason`: `requested` (the app's `settings.mode`) | `plan` (the plan or token does not allow the requested mode) |
`budget` (the budget left pays for fewer than 3 more suggestions in this mode, or 1 in Fast, or for less than one
suggestion at its worst: both models, the longest answer) | `budget_restored` (a refreshed token
brought budget back; also when the plan caps how far the mode rises) | `assist_time` (the token's assist time is
used up). The steps are `max` → `deep` → `balanced` → `fast` → `captions_only`. In `captions_only` the relay makes no
suggestions but keeps the connection, the transcript and the context, so suggestions resume when a new token brings
budget. Captions never stop.

### `practice_question` and `practice_done`

```json
{"type": "practice_question", "seq": 40, "question_id": "pq-1f3a-9", "turn_id": "q-1", "index": 1, "total": 8,
 "text": "Tell me about a project you are proud of, and what your part in it was?"}
{"type": "practice_done", "seq": 90, "asked": 8}
```

The relay plays Them for the brief's meeting type (the interviewer, the manager, the client) and writes each question
from the brief and the documents (the job description or the client's brief), one or two sentences, from easier to
harder, sometimes following up on the last answer, never repeating one. It uses only names, companies, numbers and
facts found in the brief, the documents or Me's answers, never assumes anything about Me or anyone else beyond them,
and with an empty brief asks general questions for the meeting type. "Me's instructions" in the brief are followed
(the topics to ask about, for example); anything else there that asks for something is material. The question is the `other` turn `turn_id` of
the transcript: a hint for it follows as for any question (`suggestion_start` with that `turn_id`), unless the app
paused suggestions (`settings.assist: paused`) to practise without help. The person answers by voice: the speech
engine's text as `me` finals, as in a call. **The app sends each question to the server with its batched turns**:
`channel: "other"`, `client_ref` = `question_id`, the `text`, `is_question: true`; the relay does not post turns.
`total` is the relay's setting (`[practice] questions`, 8).

### `error`

```json
{"type": "error", "seq": 27, "code": "provider_timeout", "message": "The model did not answer in time. Try again.",
 "retry": true, "suggestion_id": "1f3a-7", "in_reply_to": null, "fatal": false}
```

Every field is always present. With a `suggestion_id` it ends that suggestion. `in_reply_to` is the app's `seq` the
error answers. `fatal: true` means the relay closes the connection next.

| `code` | When | `retry` | What the app does |
|---|---|---|---|
| `bad_message` | Not JSON, an unknown `type`, a field too long, a binary frame, `auth` after sign-in | no | Fix the message; the connection stays |
| `rate_limited` | More than 40 messages a second | yes | Send less often |
| `documents_too_large` | `documents` above about 60,000 tokens | no | Send the parts that matter, or retrieval chunks |
| `nothing_to_rewrite` | A rewrite before any finished suggestion | no | Nothing |
| `budget_exhausted` | Help now or a rewrite in `captions_only` | no | Show "Suggestions are off"; captions continue |
| `budget_exhausted` (`retry: true`) | Help now or a rewrite: for now, the budget free beside calls in flight doesn't cover it even in the cheapest mode (it passes when they finish) | yes | "Try again in a moment" |
| `provider_not_configured` | The model's provider has no key on the server. Message: "Live Assist is not set up on the server yet. Ask your admin." (no vendor name) | no | Show the message |
| `provider_unavailable`, `provider_timeout` | The model failed, after the fallback model if any | yes | Show the message; help now may work |
| `model_declined` | The model declined, after the fallback model if any | no | Show the message |
| `not_practice` | `practice_next` in a live session | no | Nothing |
| `practice_busy` | `practice_next` while the last question is still being written | yes | Wait for `practice_question` |
| `token_refresh_rejected` | `token_refresh` with a bad, expired, older or other token | no | Get a new token from the server |
| `token_expired` (fatal) | The token expired without a refresh | yes | Reconnect with a new token; the session resumes |
| `token_missing`, `token_invalid`, `wrong_session` (fatal) | A failed first-message `auth` | no | Get a new token |

## When suggestions start

- **Speculative start:** an `other` partial whose words look like a question (English, Urdu in both scripts, Arabic,
  or a `?`/`؟`) with `silence_ms` ≥ 128 starts a suggestion (`speculative: true`).
- **Keep talking:** a partial of the same turn with different words within 700 ms of that start cancels it
  (`suggestion_end` `cancelled`); the next qualifying pause starts it again. After 700 ms it stands.
- **End of turn:** a `turn_final` from `other` starts a suggestion unless the speculative one already answers the same
  words (at most one word in ten changed); when the words changed, it is superseded. Short replies that need no
  answer ("okay", "theek hai", "تمام") start nothing.
- **Never** from `me`. A suggestion in flight is superseded by the next one: one answer on the card at a time.
- **Balanced upgrade:** a hard question (14+ words, or "why", "explain", "walk me through"…) also goes to the upgrade
  model. When it finishes, it replaces the draft (`kind: upgrade`, `replaces: <draft id>`, the whole text in one
  `token`) unless I spoke after the question, the draft was a `SKIP`, or the draft is gone.

## When a model fails

Each mode has a primary model and a fallback model, usually at another provider (Fast: Claude Haiku 5.5, then
gpt-oss-120b on Groq).

- **Busy providers** (429, 5xx, 529) are tried again at most twice, quickly (100 ms, then 200 ms), and only while
  the provider's `retry-after` fits in a second. Otherwise the error is handed back at once, so the fallback model
  can answer within the latency budget.
- **Before anything is shown** (an error, a refusal, a dropped connection, or no first token within the model's
  first-token timeout), the same suggestion goes to the fallback model; the app sees one suggestion whose
  `suggestion_end.model` is the fallback. The abandoned attempt's usage (an estimate when the provider sent none) is
  in that suggestion's `usage`, marked `estimated`; a request the provider turned away unprocessed (rate limited, no
  key) costs nothing.
- **Refusals are never shown** from models with safety classifiers (Sonnet 5.5 and Opus 5.5, `hold_until_complete`
  in the model catalog): their answer is held until the stream ends, so a refusal there is a failure before anything
  is shown. A streamed model (Haiku 5.5, gpt-oss) that refuses after words reached the card: the relay ends that
  suggestion **at once**, at the refusal event (Anthropic's `message_delta` with `stop_reason: "refusal"`, Chat
  Completions' `finish_reason: "content_filter"`), with `suggestion_end` `status: superseded`. The app clears its text
  from the card at that moment, before the fallback's first token. Then the fallback starts as below.
- **After words reached the card** (a dropped connection or a refusal mid-answer), the suggestion ends `superseded`
  and the fallback model answers the same request as a new suggestion with `replaces` set to it, timed from the same
  end of turn. The app replaces the card's text. A Balanced draft's upgrade carries over: the fallback's answer is the
  new draft (`draft: true`), and the upgrade replaces it under the usual rule (not after I spoke, not a `SKIP`).
- Without a fallback (or when it fails too), the suggestion ends with an `error` (`retry: true` except for
  `model_declined` and `provider_not_configured`).

## The facts ledger and the running summary

The relay keeps both for every session (plan L2), in the background with the Fast model (its fallback when it fails),
never holding up a suggestion and not in `captions_only`:

- **Facts:** after every 3 final lines of either channel (backchannels like "okay" don't count), the relay asks for the
  new facts in them (what was said, asked, agreed or promised, figures exactly as said, `Them:` / `Me:` lines), adds
  them to the session's ledger, which the next suggestions read, and posts the whole ledger to the server
  (`POST /api/internal/assist/facts`).
- **Summary:** at most every 5 minutes, when the call moved on, the relay rewrites the running summary from the last
  one and the new lines, and posts it (`POST /api/internal/assist/summary`).
- A `context_update` with `facts` or `summary` from the app still replaces what the relay has; the relay carries on
  from there.
- Each call is charged to the session's spend and reported as usage (`kind` `facts` or `summary`; practice questions
  `practice_question`), including an attempt that failed after its request was taken (estimated; the usage says
  `estimated: true`). The settings are `[background]` in `config/relay.toml`.
- **Budget:** before a background call starts, its worst case (primary and fallback, its longest answer) must fit in
  what is left, leaving the suggestions' reserve (one suggestion in Fast, `reserve_suggestions` otherwise). While it
  runs, that worst case is held back from the budget, so calls running together can't cross it. A call that doesn't
  fit waits, its lines kept, for the next chance; the lines waiting are capped (200 lines, about 12,000 tokens).

## What the relay needs from the server (L7)

The post-call report is the server's queue job (plan L7). For it, and for practice, the relay needs:

1. **`POST /api/internal/assist/facts`** (HMAC, as the other internal endpoints): `{"session_id", "facts": ["Them:
   the budget is 8,000 USD (minute 2)", …], "at"}`. The whole ledger each time: replace what is stored (encrypted).
   The report's "mistakes against the facts ledger, and promises I made" reads it.
2. **`POST /api/internal/assist/summary`**: `{"session_id", "summary", "at"}`. Replace; the report's summary starts
   from it.
3. **`POST /api/internal/assist/usage`** also accepts `kind` `practice_question`, `facts` and `summary` (with `trigger`
   `practice`, `facts`, `summary`, `mode` `fast`, `suggestion_id` `pq-…`, `facts-…`, `summary-…`; idempotent per id),
   and bills them to the session like suggestions: Practice ones to the Practice allowance.
4. **Practice start** (`POST /api/assist/sessions` with `"practice": true`): a token with `practice: true`,
   `modes: ["fast"]` on every plan, Free included, `models` with the Fast mode's primary and fallback models, and the
   budget (`seconds_left`, `cents_left`) from the Practice allowance (Free: 30 minutes a month), not the live one.
5. **Turns:** accept the practice questions the app posts in its batched turns (`channel: "other"`, `client_ref` =
   the `question_id`, `is_question: true`), so the report's Q&A table pairs each question (the `other` turn `q-N`)
   with what I said after it (the `me` turns) and the hint shown (the suggestion with that `turn_id`, from
   `POST /api/internal/assist/suggestions`).
6. **The model catalog** (`GET /api/internal/assist/providers`) carries `hold_until_complete` per model (true for
   Claude Sonnet 5.5 and Opus 5.5), and the Fast mode's models, which the relay's own calls use.
7. **The contract**: `practice_next`, `practice_question`, `practice_done`, the error codes `not_practice` and
   `practice_busy`, and the usage kinds above.

## The budget gate

Every suggestion (and its upgrade, and the cache warm-up) holds its worst case from the budget until it is charged:
both models, no cache read (the cacheable part priced as a cache write when that costs more than plain input),
every non-ASCII character a token, the longest answer; background calls hold theirs too. A suggestion starts only when its worst
case fits in what is left with all of those held back. When the mode's doesn't fit (calls are in flight), the next
cheaper allowed mode answers this one question, with no `mode_changed` (`suggestion_start.mode` says which); a hard
question's upgrade that doesn't fit beside its draft is left out. When none fits, a question the app asked for (help
now, regenerate, shorter, formal, translate) gets `budget_exhausted` with `retry: true` and no suggestion; a question
at the end of the other side's turn gets no message and waits: the relay starts it as soon as calls in flight release
their holds, unless by then it is older than `hold_max_ms` (5 s), the other side has said more, Me has spoken, or the
app has disconnected. A speculative one is not held (the turn's final comes anyway). A background call starts only when it
leaves room for the reserved suggestions at the mode's worst case (one in Fast). The mode itself follows the budget
left without those holds, so it never moves for a call in flight.

## Close codes

| Code | Meaning |
|---|---|
| `1009` | A message above `max_message_bytes` (2 MiB) |
| `1012` | The relay is restarting: reconnect (the session resumes on the same relay, or starts fresh after a restart) |
| `4401` | The token expired (after `error token_expired`), a failed first-message `auth`, or an older token that raced a refresh |
| `4403` | The session belongs to someone else |
| `4408` | Nothing received for 60 s |
| `4409` | Replaced by a newer connection for the same session: don't reconnect |

The relay never sends `1000` itself.

## Still open

1. **First render:** the plan's telemetry includes "first render", which only the app knows. Report it with the
   batched turns to the server, or add `rendered {"suggestion_id", "first_render_ms"}` (app → relay)?
2. **Second screen (L6):** a linked phone is a second connection to the same session that only receives. That needs
   a viewer role in the token, since today a second connection takes over (`4409`).
