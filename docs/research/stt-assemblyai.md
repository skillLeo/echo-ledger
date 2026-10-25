# AssemblyAI: verified API notes (EchoLedger default STT: streaming + async)

> Researched 2026-10-24. Every API fact below links to a primary source opened during research.
> The three machine-readable specs AssemblyAI publishes were downloaded and read field by field:
>
> | Spec | URL | Version (as published today) |
> |---|---|---|
> | REST (pre-recorded) | [openapi.json][oas] | OpenAPI 3.1.0, `info.version` **1.3.4**, server `https://api.assemblyai.com` |
> | Streaming token | [streaming-token.yaml][tok] | OpenAPI 3.0.3, `info.version` 1.0.0, server `https://streaming.assemblyai.com` |
> | Streaming WebSocket v3 | [streaming.yaml][ws] | AsyncAPI 3.0.0, `info.version` 1.0.0; `Begin` echoes `api_version` (example `"2025-05-12"`) |
> | Legacy v2 realtime (retired) | [asyncapi.json][v2rt] | AsyncAPI 2.6.0, v1.1.2. Only used here to explain where `word_boost` went |
>
> Prose facts come from the docs pages (also exported in full at [llms-full.txt][llmsfull]).
> Anything that could not be confirmed is marked **UNVERIFIED** and gives the reason.
> Laravel facts come from `web/vendor` (laravel/framework **v13.38.0**, guzzlehttp/guzzle 8.3.0, guzzlehttp/psr7 3.2.0, PHP 8.5.10).

---

## 0. TL;DR

- **Auth everywhere:** `Authorization: <API key>`, the raw key with **no `Bearer` prefix**. The scheme is an `apiKey` in header `Authorization` ([openapi][oas]), and the WS spec says "no `Bearer` prefix" ([ws spec][ws]).
- **Streaming = v3 only:** `wss://streaming.assemblyai.com/v3/ws`. The old v2 realtime endpoint returns **HTTP 410** on upgrade ([errors][errs]).
- **Temp token:** `GET https://streaming.assemblyai.com/v3/token?expires_in_seconds=1..600[&max_session_duration_seconds=60..10800]` returns `{token, expires_in_seconds}` ([token spec][tok]).
- **Keyterms CANNOT be bound to the token.** The token endpoint accepts only those two query params ([token spec][tok]). The client sets keyterms at connect time with `keyterms_prompt`, or mid-session with `UpdateConfiguration` ([keyterms][skt], [mid-stream][smid]). The token is **multi-use** until its redemption window ends ([token spec][tok]).
- **No multichannel streaming.** AssemblyAI recommends **one session per channel** ([diarization/multichannel][sdiar]). Each session is billed separately on WebSocket-open time ([billing][billing]).
- **Default streaming model:** `universal-3-6-pro`, applied when `speech_model` is omitted ([ws spec][ws]). It costs **$0.45/h** with keyterms included. `universal-streaming-english` and `universal-streaming-multilingual` cost **$0.15/h** ([pricing][pricing]).
- **Async:** `POST /v2/upload` (raw bytes) returns `upload_url`, then `POST /v2/transcript` with `speech_models` (plural; `speech_model` is deprecated), `multichannel`, `keyterms_prompt`, `webhook_url` and `webhook_auth_header_name`/`_value` ([openapi][oas]). **`word_boost` is gone from the request schema.**
- **Webhooks are not signed.** The payload is `{transcript_id, status}` only. Authenticity comes from your own custom header plus optional source-IP allowlisting ([webhooks][pwh]).
- **`DELETE /v2/transcript/{id}`** removes the transcript data **and immediately deletes the `/upload` file** ([openapi][oas], [delete ref][pdel]).

---

## 1. Auth and base URLs

| Purpose | URL | Source |
|---|---|---|
| REST, US (default) | `https://api.assemblyai.com` | [openapi servers][oas], [region][region] |
| REST, EU data residency | `https://api.eu.assemblyai.com` (eu-west-1 Dublin per FAQ) | [region][region], [EU FAQ][faqeu] |
| Streaming token | `https://streaming.assemblyai.com/v3/token` (the **only** server listed) | [token spec][tok] |
| Streaming WS, Edge Routing (default; nearest US/EU region) | `wss://streaming.assemblyai.com/v3/ws` | [ws spec][ws], [zones][zones] |
| Streaming WS, US data zone | `wss://streaming.us.assemblyai.com/v3/ws` | [ws spec][ws], [zones][zones] |
| Streaming WS, EU data zone | `wss://streaming.eu.assemblyai.com/v3/ws` | [ws spec][ws], [zones][zones] |

- REST header: `Authorization: <API key>` ([openapi `securitySchemes.ApiKey`][oas]). Uploads use `Content-Type: application/octet-stream`. Everything else uses `application/json` ([openapi][oas]).
- The streaming WS accepts either the `Authorization` header (server-side) or the `token` query parameter (browsers and devices). Optional header `AssemblyAI-Version` pins the API version. The default is latest ([ws spec][ws]).
- Edge Routing may process data in **any** US or EU region. Use a Data Zone host if you need residency ([zones][zones]). EU pricing equals US pricing ([pricing][pricing]).

---

## 2. Streaming (Real-time STT, WebSocket v3)

### 2.1 Models available today

| `speech_model` value | Name | Languages | Keyterms | List price (per **session** hour) | Source |
|---|---|---|---|---|---|
| `universal-3-6-pro` (**default**) | Universal-3.6 Pro Streaming / "Realtime" | 32, native code-switching | ≤100, **included** | $0.45/h | [model select][smodel], [ws spec][ws], [pricing][pricing] |
| `universal-3-5-pro` | Universal-3.5 Pro Streaming (previous flagship, still supported) | 19 | ≤100 | $0.45/h (Models page) | [model select][smodel], [models][models] |
| `universal-streaming-english` | Universal-Streaming English | en | ≤100, +$0.04/h | $0.15/h | [pricing][pricing] |
| `universal-streaming-multilingual` | Universal-Streaming Multilingual | en, es, de, fr, pt, it | ≤100, included | $0.15/h | [pricing][pricing] |

- Streaming diarization costs +$0.12/h on all streaming models ([pricing][pricing]). We don't need it because we use two channels.
- **Latency** ([benchmarks][sbench], May 2026). Emission P50/P90: Universal Streaming English 317/597 ms, Multilingual 303/535 ms. Time-to-complete-turn P50/P90: Universal-Streaming English 649/990 ms, Universal-3.5 Pro 568/829 ms. The docs say published 3.6 Pro figures are "not yet available". U3.5 Pro emits segment-level partials, not word-by-word ones, so emission latency is N/A for it. The 3.6 Pro partial cadence is **UNVERIFIED** (not benchmarked yet).
- U3.6 Pro adds 13 languages over 3.5 Pro, including `ur`, `ko`, `ru` and `fa` ([multilingual][smulti]).

### 2.2 Temporary token: `GET /v3/token`

Source: [streaming-token.yaml][tok] (operationId `generateStreamingToken`). Prose: [temp-token guide][tmp].

| Item | Value |
|---|---|
| Method / URL | `GET https://streaming.assemblyai.com/v3/token` |
| Auth | Header `Authorization: <API key>` (spec scheme `apiKey`, `in: header`) |
| `expires_in_seconds` | integer, **required**, min **1**, max **600**. This is the *redemption window*: the time allowed to open a WebSocket. "If the window has elapsed when the WebSocket is opened, the server rejects the session on the first frame." It does **not** cap session length. "The token is not single-use: until this window elapses, the same token can open multiple sessions, including concurrent sessions." |
| `max_session_duration_seconds` | integer, optional, min **60**, max **10800**, default **10800**. At the cap, the session closes "after the current turn ends, or up to 60 seconds later, and this extra time is billed." |
| 200 response | `{"token": "<string>", "expires_in_seconds": <int>}`. Both fields are required. `expires_in_seconds` echoes the redemption window. |
| Errors | `400` invalid params, `401` bad or missing key, `429` rate limited, `500`. Body: `{"error": string, "code"?: string, "details"?: object}` |

Usage is "attributed to the API key that generated it". The docs advise: "request a new token for each session and keep `expires_in_seconds` short" ([temp-token guide][tmp]).

### 2.3 Design question: can keyterms be bound at mint time?

**No.** The token request has no keyterms, model or language parameters. It accepts only `expires_in_seconds` and `max_session_duration_seconds` ([token spec][tok]). The `Begin` echo carries no keyterm binding either ([ws spec][ws]). Keyterms are a **connect-time query parameter** (`keyterms_prompt`) or a **mid-session `UpdateConfiguration`** field. Both are chosen by whoever holds the socket ([keyterms][skt], [mid-stream][smid]). Consequences:

1. Laravel can only *supply* keyterms to the device. It cannot *enforce* them. Treat keyterms as accuracy hints, not a security boundary.
2. One minted token can open **both** channel sessions (and any number of others) until it expires. Keep `expires_in_seconds` short (about 60 s) and rate-limit `/api/stt/token` per device.
3. Unknown or misspelled query params are **silently ignored**. Always check `Begin.configuration.model` ([ws spec][ws], [message sequence][seq]).

### 2.4 WebSocket URL and every connect query parameter

`wss://{host}/v3/ws?<params>`. "All query parameters below are optional"; the defaults are `universal-3-6-pro`, 16 kHz, `pcm_s16le` ([ws spec][ws]).

| Param | Type / allowed | Default | Models | Notes (source: [ws spec][ws] unless noted) |
|---|---|---|---|---|
| `token` | string | none | all | Temp token. Use it where you can't set headers. |
| `speech_model` | `universal-3-6-pro` \| `universal-3-5-pro` \| `universal-streaming-english` \| `universal-streaming-multilingual` | `universal-3-6-pro` | none | Singular. There is no fallback list in streaming ([integration guide][guide]). |
| `encoding` | `pcm_s16le` \| `pcm_mulaw` \| `opus` \| `ogg_opus` \| `aac` | `pcm_s16le` | all | `opus` means exactly one raw Opus packet per binary message. `ogg_opus` and `aac` (ADTS) can be arbitrary chunks. |
| `sample_rate` | int 8000–96000 | 16000 | all | Ignored for `opus`/`ogg_opus`/`aac`. |
| `keyterms_prompt` | JSON array of strings, URL-encoded | none | all | **Max 100 terms per session.** Each term must be **50 characters or less**: longer terms are ignored, and more than 100 terms returns an error ([keyterms][skt]). Raw-WS examples use `json.dumps([...])` / `JSON.stringify([...])` ([keyterms][skt]). Keyterms are included in the U3.6 Pro price and cost +$0.04/h on Universal-Streaming English ([pricing][pricing]). |
| `prompt` | string ≤1750 chars | none | Pro (3.5/3.6) | Context only. Instructions are not followed ([keyterms][skt]). The docs recommend testing with no prompt first. |
| `language_codes` | list of codes | unset (native code-switching) | Pro | Spec example `'["en", "es"]'`. Updatable mid-session. |
| `language_detection` | `true`/`false` | `false` | Pro, Multilingual | Adds `language_code` and `language_confidence` to Turns. |
| `mode` | `max_accuracy` \| `min_latency` \| `balanced` | server preset (guide: `balanced`) | Pro | Sets the turn-detection defaults ([optimizing][opt]). |
| `min_turn_silence` | int ms, clamped 50–10000 | Pro: by mode (128/128/512); Universal Streaming: 400 | Pro, US | [turn detection][turn], [optimizing][opt] |
| `max_turn_silence` | int ms | Pro: by mode (640/1280/2560; 768 with `speaker_labels`); Universal Streaming: 1280 | Pro, US | The spec text says 1536 for 3.5 Pro, which conflicts with the [turn guide][turn]. Set it explicitly if it matters. |
| `end_of_turn_confidence_threshold` | 0.0–1.0 | 0.4 | **Universal Streaming only** | |
| `vad_threshold` | 0.0–1.0 | Pro 0.2 (0.5 with `speaker_labels`); Universal Streaming 0.4 | Pro, US | |
| `interruption_delay` | int 0–1000 ms (+256 ms fixed) | by mode | Pro | |
| `include_partial_turns` | bool | `true` (`false` if `redact_pii`) | all | |
| `format_turns` | bool | `false` | **Universal Streaming only** | When `true`, you get **two** `end_of_turn:true` messages per turn ([message sequence][seq]). |
| `agent_context` | string ≤1750 | none | Pro | Voice-agent feature. Not needed by us. |
| `previous_context_n_turns` | int 0–100 | server (5) | Pro | |
| `speaker_labels` | bool | `false` | all | Adds `speaker_label` per Turn and `speaker` per final word. |
| `max_speakers` | int 1–10 | none | all | Hard cap. Only applies with `speaker_labels`. |
| `speaker_labels_revision_interval_ms` | int (min 120000) | 0 | all | Mid-session `SpeakerRevision` messages. |
| `voice_focus` | `near-field` \| `far-field` | off | none | Noise suppression ([voice focus][vf]). Price **UNVERIFIED** (not on the pricing page). |
| `voice_focus_threshold` | 0.0–1.0 | 0.7 | none | Requires `voice_focus`. |
| `redact_pii`, `redact_pii_policies`, `redact_pii_sub` (`entity_name`\|`hash`) | none | `false`, none, `hash` | none | Final turns only. |
| `filter_profanity` | bool | `false` | none | |
| `domain` | `medical-v1` | none | none | Add-on, +$0.15/h ([pricing][pricing]). |
| `session_heartbeat` | bool | `false` | none | `Heartbeat` every 5 s. |
| `inactivity_timeout` | int s, 5–3600 | none | none | Closes the session if no audio or messages arrive. `KeepAlive` resets it. |
| `llm_gateway` | JSON string | none | none | Not used (no AI in v1). |
| `webhook_url`, `webhook_auth_header_name`, `webhook_auth_header_value` | string | none | none | Streaming webhooks ([streaming webhooks][swh]). See §2.10. |

### 2.5 Audio format requirements

- Send binary WebSocket frames of raw bytes, never JSON or base64. A text frame that is not valid JSON closes the session ([message sequence][seq]).
- The default is 16 kHz, 16-bit, **mono** PCM ([message sequence][seq]). The integration guide says "PCM16 signed little-endian, mono, 16 kHz" ([integration guide][guide]).
- Each audio chunk must be **50–1000 ms** of audio, otherwise the session closes with **`3007`** "Input duration violation" ([ws spec `operations.audio`][ws], [errors][errs]). The recommended size is about 50 ms, which is 800 samples or 1600 bytes at 16 kHz ([message sequence][seq]).
- Throughput is throttled at about **1.25× real time**. More than 5 minutes buffered closes the session with `3007` ([message sequence][seq]).
- The same page says "for Opus, one 20 ms packet (or page) per frame is typical". It is **UNVERIFIED** how the 50 ms minimum applies to Opus, so use PCM in v1.

### 2.6 Client → server messages (text frames are JSON) ([ws spec][ws], [message sequence][seq])

| Message | Payload |
|---|---|
| audio | binary frame |
| `UpdateConfiguration` | `{"type":"UpdateConfiguration", ...}`. Optional fields: `prompt`, `keyterms_prompt` (array; **replaces** the set; `[]` clears it), `min_turn_silence`, `max_turn_silence`, `vad_threshold`, `interruption_delay`, `agent_context`, `mode`, `end_of_turn_confidence_threshold`, `language_codes`, `session_heartbeat`. It is a delta, there is **no acknowledgement**, and it applies only to audio processed after the update ([mid-stream][smid], [message sequence][seq]). |
| `ForceEndpoint` | `{"type":"ForceEndpoint"}` ends the current turn immediately. |
| `KeepAlive` | `{"type":"KeepAlive"}`. Only needed with `inactivity_timeout`. |
| `Terminate` | `{"type":"Terminate"}` |

### 2.7 Server → client messages ([ws spec][ws], [message sequence][seq])

| `type` | Fields |
|---|---|
| `Begin` | `id` (uuid session id), `expires_at` (Unix seconds), `configuration{model, mode, api_version, speaker_labels, redact_pii, filter_profanity, domain, voice_focus}`. `id`, `type` and `expires_at` are required. When `expires_at` is reached the server closes with `3008` after the turn, or up to 60 s later (billed). |
| `SpeechStarted` | `timestamp` (ms from stream start), `confidence`. Pro models only; Universal Streaming skips it. |
| `Turn` | required: `turn_order` (int, monotonically increasing), `turn_is_formatted`, `end_of_turn`, `transcript`, `end_of_turn_confidence`, `words[]`. Optional: `utterance` (filled only on end_of_turn), `language_code`, `language_confidence`, `speaker_label`, `speaker_confidence`. `words[]`: `text`, `start`, `end` (ms from stream start), `confidence`, `word_is_final` (all required), plus `speaker` and `speaker_confidence` when diarized. |
| `SpeakerRevision` | `revisions[{turn_order, speaker_label, words[{text,speaker,start,end}]}]`. Only sent with `speaker_labels`. |
| `Heartbeat` | `total_audio_received_ms`, `total_duration_ms`, `realtime_factor`, `max_speech_probability` |
| `LLMGatewayResponse` | Not used. |
| `Termination` | `audio_duration_seconds` (int), `session_duration_seconds` (int). "This is the duration the session is billed on." Always the last message, followed by close code `1000`. |
| `Error` | `{"type":"Error","error_code":<int>,"error":"..."}`, sent before close. The close code equals `error_code` ([errors][errs]). |

Rendering rules ([turn detection][turn], [message sequence][seq]):
- Each `Turn` **re-transcribes the whole turn**, so replace the previous text rather than appending.
- A turn is complete when `end_of_turn && turn_is_formatted`.
- All messages for one turn arrive before the next `turn_order` begins.

### 2.8 Terminating cleanly

1. Send `{"type":"Terminate"}`.
2. **Keep reading until `Termination`.** The server first flushes in-flight final Turns and, if diarizing, a final `SpeakerRevision`. "Closing the socket as soon as you send `Terminate` silently discards your last transcript." ([message sequence][seq])
3. The server then closes with `1000`.

Unterminated sessions stay open and keep billing until the 3-hour auto-close (`3008`) ([message sequence][seq], [billing][billing]).

### 2.9 Multichannel streaming

There is no multichannel streaming parameter. "To transcribe multichannel streaming audio, we recommend creating a separate session for each channel" ([diarization/multichannel][sdiar]). Each session bills independently: "two streaming sessions running simultaneously for 5 minutes each, including a single call that is dual-streamed under two separate session IDs, bill as 10 minutes" ([billing][billing]). Word timestamps are relative to *each session's* stream start ([ws spec `Word`][ws]).

### 2.10 Streaming webhooks (exist; not recommended for us)

Add `webhook_url` (plus optional auth header name and value) to the WS URL. When the session ends, AssemblyAI POSTs `{session_id, messages:[Turn...]}`. Delivery follows the same 10 s / 10 attempts rule and the same IPs as async ([streaming webhooks][swh]). For EchoLedger this would put a webhook secret into a client-built URL, and the final transcript comes from async anyway. **Skip it.**

### 2.11 Streaming errors and rate limits

| Close code | `error` text (selection) | Action | Source |
|---|---|---|---|
| `1008` | `Unauthorized Connection: Too many concurrent sessions` | Back off and retry **next minute**. The limit counts *session starts per minute*, not open sessions. | [errors][errs], [rate limits][srl] |
| `1008` | `...Missing Authorization header` / `...Invalid API key` / `...Signature has expired` (token expired before use) / `...Insufficient funds` / `...API key is disabled` | Don't retry unchanged. Re-mint the token on "Signature has expired". | [errors][errs] |
| `1011` | `Error authenticating connection` | Retry, then contact support. | [errors][errs] |
| `3005` | `Session Cancelled: An error occurred` | Server error. Reconnect with backoff. | [errors][errs] |
| `3006` | invalid message type / invalid JSON / inactivity timeout | Fix the client. | [errors][errs] |
| `3007` | chunk not 50–1000 ms, or audio faster than real time | Fix the chunker. | [errors][errs] |
| `3008` | `Session Expired: Maximum session duration exceeded` | Roll over to a new session before the cap. | [errors][errs] |
| HTTP 410 | v2 endpoint retired | Use `/v3/ws`. | [errors][errs] |

New sessions per minute: Free **5**, Paid **100+**. The paid limit auto-scales +10% per minute while you use 80% or more of it. Limits apply per account, not per project ([rate limits][srl]).

### 2.12 Streaming data retention

"If you are opted out of model training, we offer zero data retention of audio and transcripts for our Streaming product" (metadata is kept for billing) ([retention][ret]). Streaming accepts TLS 1.3 only ([retention][ret]).

---

## 3. Async (pre-recorded)

### 3.1 Upload: `POST /v2/upload`

- The body is **raw bytes**, `application/octet-stream`, not multipart ([openapi][oas], [integration guide][guide]).
- The response is `{"upload_url": "https://cdn.assemblyai.com/upload/<uuid>"}`. The URL is "accessible only by AssemblyAI's servers" ([openapi `UploadedFile`][oas]).
- **Max 2.2 GB** per upload ([limits FAQ][faqlim]).
- Errors: `422` with a **plain-text** body ("Upload failed, please try again...", for example an empty body), plus `400/401/403/404/429/500/503/504` ([openapi][oas]).
- The transcribe call must use an API key from the **same project** as the upload. Otherwise you get `403` "Cannot access uploaded file" ([openapi][oas]).
- There is **no** endpoint to delete an upload on its own; the paths list has no DELETE on `/v2/upload` ([openapi][oas]). Uploads go away when you DELETE the transcript (§3.7) or when retention expires (§3.8).

### 3.2 Create: `POST /v2/transcript` (relevant params, from [openapi `TranscriptOptionalParams`][oas])

| Param | Type / constraint | Default | Notes |
|---|---|---|---|
| `audio_url` | string, **required** | none | The `upload_url`, or any URL AssemblyAI can fetch. |
| `speech_models` | array of `universal-3-5-pro` \| `universal-2` | `["universal-3-5-pro","universal-2"]` | An ordered fallback. U3.5 Pro covers 18 languages; with `language_detection: true`, other languages fall back to U2 ([model select][pmodel], [languages][langs]). |
| `speech_model` | none | none | **Deprecated** ("replaced with the `speech_models` parameter"). |
| `language_detection` | bool | applied automatically when no `language_code` | Set `false` "only together with a `language_code`". |
| `language_code` | string | none | "Cannot be used together with `language_detection`." |
| `language_codes` | array | none | **Mutually exclusive** with `language_code` and `language_detection` ([code switching][cs]). On U2 the max is 2 codes and one must be `en`. |
| `language_detection_options` | `{expected_languages[], fallback_language="auto", code_switching=false, code_switching_confidence_threshold=0.3, localization[], on_no_speech_detected="error"\|"fallback"}` | none | |
| `language_confidence_threshold` | 0–1 | 0 | Errors if the detected-language confidence is below the threshold. |
| `multichannel` | bool | `false` | Per-channel transcription (§3.4). |
| `punctuate` | bool | `true` | |
| `format_text` | bool | `true` | |
| `keyterms_prompt` | array of strings | none | **≤1000** terms on U3.5 Pro, **≤200** on U2, **max 6 words per phrase**. Each word of a phrase counts toward the limit, and real capacity may be lower because of tokenization ([pkt guide][pkt]). Async add-on +$0.05/h ([pricing][pricing]). |
| `prompt` | string ≤1,500 words | none | U3.5 Pro only. Context, not instructions. |
| `temperature` | 0–1 | 0 | U3.5 Pro only. |
| `speaker_labels` | bool | `false` | "Requires `punctuate` to be `true`." |
| `speakers_expected` | int | null | Requires `speaker_labels`. Exclusive with `speaker_options`. |
| `speaker_options` | `{min_speakers_expected, max_speakers_expected, include_speaker_confidence=false}` | none | Applied **per channel** when combined with multichannel ([multichannel][pmc]). |
| `custom_spelling` | `[{from:[...], to:"..."}]` | none | `to` is a single word; each `from` is at most 5 words. |
| `disfluencies` | bool | `false` | Filler words. |
| `remove_audio_tags` | `"all"` \| `"speaker"` \| null | `"all"` | U3.5 Pro inline annotations. |
| `speech_threshold` | 0–1 | 0 | Rejects files with too little speech. |
| `audio_start_from` / `audio_end_at` | int ms | none | |
| `webhook_url` | string (url) | none | Your own query params are allowed for metadata ([webhooks][pwh]). |
| `webhook_auth_header_name` | 1–1000 chars, **ASCII letters, digits, `-`, `_` only** | null | Requires `webhook_auth_header_value` and `webhook_url`. |
| `webhook_auth_header_value` | 1–1000 chars, **no CR/LF** | null | Requires the header name and `webhook_url`. **Not** returned by GET. The Transcript object has `webhook_auth` (bool) and `webhook_auth_header_name` only. |

The 200 response is the `Transcript` object, already `queued`/`processing`, including `id` ([openapi][oas]).

### 3.3 `word_boost` status

`word_boost` and `boost_param` are **absent** from the current request schema. They appear only inside a stale *example response* in [openapi.json][oas]. `word_boost` was a parameter of the **legacy v2 realtime** API ([asyncapi.json][v2rt]), which is retired (HTTP 410, [errors][errs]). The retention table still mentions "word boost list" as a historical input type ([retention][ret]). **Use `keyterms_prompt`.**

### 3.4 Multichannel semantics ([multichannel][pmc], [openapi][oas])

- The response adds `audio_channels` (int; "only present when multichannel is enabled") and turn-by-turn `utterances`. "Each utterance contains channel information, starting at 1", and each word in `words[]` has a `channel`.
- `TranscriptWord.channel` and `TranscriptUtterance.channel` are typed **`string | null`**: "The left and right channels are channels 1 and 2. Additional channels increment the channel number sequentially."
- With `multichannel` **and** `speaker_labels`, channels are numbered and speakers inside each channel get letters. The combined label is **`{channel}{speaker}`**, for example `1A`, `1B`, `2A`. `speaker_options` applies per channel.
- With multichannel alone, the docs' samples print `utterance.speaker` as "Channel {speaker}". The exact value of `speaker` in that mode is **UNVERIFIED**: the schema describes `speaker` as a letter. **Map on `channel`, not `speaker`.**
- "Multichannel audio increases the transcription time by approximately 40%."
- If both channels carry the same audio (for example mic bleed), you can get duplicate content. AssemblyAI has a guide on detecting identical channels ([duplicate channels][dup]).
- Billing is per channel: "audio duration × hourly rate × number of channels" ([billing][billing]).

### 3.5 Webhook delivery ([webhooks][pwh], [openapi callbacks][oas])

- `POST` to `webhook_url` with JSON `{"transcript_id": "<uuid>", "status": "completed" | "error"}` (`TranscriptReadyNotification`). It contains no text or error detail, so you must `GET` the transcript.
- **Retries:** your endpoint must return 2xx within **10 s**, otherwise AssemblyAI retries "up to a total of 10 attempts", "waiting 10 seconds between each attempt". **A 4xx response is final**: "considered failed and will not be retried".
- **Authenticity:** AssemblyAI does **not** sign payloads. The documentation describes no HMAC. You get an optional custom header (`webhook_auth_header_name`/`_value`) plus fixed source IPs: **US `44.238.19.20`, EU `54.220.25.36`** ([webhooks][pwh], [IP FAQ][faqip]).
- `GET /v2/transcript/{id}` returns `webhook_status_code`, which is what your server answered ([webhooks][pwh]).

### 3.6 `GET /v2/transcript/{transcript_id}`: fields we use ([openapi `Transcript`][oas])

| Field | Type | Meaning |
|---|---|---|
| `id` | uuid string | |
| `status` | `queued` \| `processing` \| `completed` \| `error` | |
| `error` | string | Present when `status=error`. |
| `text` | string \| null | Full text. |
| `words[]` | `{text, start, end, confidence, speaker, channel?, speaker_confidence?}` | `start`/`end` are **integer ms**. `speaker` is required but nullable. `channel` is string or null. |
| `utterances[]` | `{text, start, end, confidence, speaker, words[], channel?, speaker_confidence?}` | Only with `multichannel` or `speaker_labels`. |
| `audio_channels` | int | Only with multichannel. |
| `audio_duration` | int \| null | **Whole seconds** (coarse). |
| `confidence` | 0–1 \| null | |
| `language_code`, `language_confidence` | | The detected language. |
| `speech_model_used` | enum | Which model actually ran, which shows whether U2 fallback happened. |
| `speech_models`, `keyterms_prompt`, `multichannel` | | Echo of the request. |
| `metadata.warnings[].message` | | For example, language detection was skipped. |
| `throttled` | bool \| null | Queued because of the concurrency limit. |
| `webhook_url`, `webhook_auth`, `webhook_auth_header_name`, `webhook_status_code` | | |

The polling limit is **60 GET/min per (API key, transcript id)**. Beyond that you get **429 + `Retry-After`** ([rate limits][prl]).

### 3.7 `DELETE /v2/transcript/{transcript_id}`

- "Remove the data from the transcript and mark it as deleted."
- "**Files uploaded via the `/upload` endpoint are immediately deleted alongside the transcript** when you make a DELETE request."
- 200 returns the (deleted) `Transcript` object. Codes: 200/400/401/404/429/500/503/504 ([openapi][oas], [delete ref][pdel], re-checked live today).
- Use `api.eu.assemblyai.com` for EU data.
- **UNVERIFIED:** whether DELETE on an `error`-status transcript also purges the upload. The docs don't distinguish, but it is probably yes.

### 3.8 Default retention, TTL and model-training opt-out

| Artifact (async, no TTL/BAA) | Default | Source |
|---|---|---|
| Uploaded audio | Deletion "begins at 24 hours and is at most 48 hours" | [retention][ret] |
| Final transcript | "begins at 30 days and is automatic thereafter" | [retention][ret], [delete FAQ][faqdel], [outputs FAQ][faqret] |
| Text inputs (keyterms etc.) | Linked to the transcript's lifecycle | [retention][ret] |
| Customer-initiated DELETE | "Deleted when customer initiates deletion request" | [retention][ret] |

- **TTL:** set it in the Dashboard under Data Controls. Presets are 1/3/7/30 days or Custom; the minimum is 1 h. It applies to *new* requests, and AWS DynamoDB TTL lag can be hours to days ([data controls][dc], [retention][ret]).
- **Training opt-out:** a dashboard toggle "Opt Out of Data Sharing for Model Improvement Program". It is **paid plans only**, Owner/Admin role, forward-looking only ([data controls][dc], [opt-out FAQ][optout]). Files are not used for training if you are under a BAA, **use the EU servers**, or have opted out ([retention][ret]).

---

## 4. Input formats, limits, channels, billing

- **Formats** (pre-recorded): among others, `.ogg/.oga/.mogg`, `.opus`, `.flac`, `.wav`, `.m4a`, `.mp3`, and `.webm` (listed under video) ([formats FAQ][faqfmt]). All input is converted to 16 kHz uncompressed internally ([formats FAQ][faqfmt]).
- **Limits:** `/v2/transcript` takes at most **5 GB and 10 hours**; `/v2/upload` at most **2.2 GB** ([limits FAQ][faqlim]). The minimum duration is **160 ms**, otherwise you get `status:error`, `"Audio duration is too short."` ([min FAQ][faqmin]).
- **Max channels:** **UNVERIFIED**. There is no documented maximum. The docs only say extra channels "increment the channel number sequentially" ([openapi][oas]). We use 2.
- **Billing:** async is pro-rated to the second; failed transcripts are not charged ([billing][billing]). Multichannel is billed per channel. Streaming is billed on WebSocket-open time.

---

## 5. Rate limits and error codes that matter (async)

| Situation | Behavior | Source |
|---|---|---|
| Concurrency | Free 5, Paid **200+** parallel jobs. Excess jobs queue **FIFO** (`throttled: true`); you get one email per day. A negative balance drops the limit to 1. | [rate limits][prl] |
| HTTP volume | **20,000 requests per 5 minutes** per account. Exceeding it returns **HTTP 403** (not 429). | [rate limits][prl] |
| Polling one transcript | 60/min, otherwise **429** with a `Retry-After` header (seconds) | [rate limits][prl], [openapi `TooManyRequests`][oas] |
| Bad key | `401` `{"error":"Authentication error, API token missing/invalid"}` | [openapi][oas] |
| Upload from another project | `403` "Cannot access uploaded file" | [openapi][oas] |
| Empty or failed upload | `422` with a plain-text body | [openapi][oas] |
| Negative balance | `{"error": "Your current account balance is negative. Please top up to continue using the API."}` | [billing][billing] |
| 5xx | Retry with exponential backoff (`500/503/504` documented) | [openapi][oas], [integration guide][guide] |

---

## 6. Other AssemblyAI APIs: not for v1

- The **Dictation API** (`dictation.assemblyai.com`, `POST /v1/transcribe/live`) authenticates with the **API key only** (`securitySchemes.ApiKey`, header `Authorization`). No temp-token scheme is defined ([dictation spec][dict]). It also returns LLM-cleaned text (`llm_response`) by default ([dictation docs][dictdocs]). Devices can't call it without exposing the key, and the cleanup conflicts with the "no AI" rule. **Use a Streaming v3 session for the desktop dictation box instead.**
- **LeMUR** was fully sunset on 2026-03-31 ([integration guide][guide]). Not relevant to us.

---

## 7. Planning assumptions checked

| Planning assumption | Verdict |
|---|---|
| "Universal-3.5 Pro … $3.50/1,000 min" | **Confirmed as base price:** $0.21/h = $3.50 per 1,000 min. Keyterms add +$0.05/h, and multichannel multiplies by the channel count ([pricing][pricing]). The "3.0% AA-WER" figure was not verified; AssemblyAI's own English benchmarks show 5.6% mean WER (Jan 2026) ([pre-recorded benchmarks][pbench]). |
| "Pro Realtime tier" exists | **Confirmed:** "Universal-3.6 Pro Realtime", `universal-3-6-pro`, $0.45/h ([pricing][pricing]). |
| Temp token `GET https://streaming.assemblyai.com/v3/token?expires_in_seconds=…` | **Confirmed** ([token spec][tok]). |
| "multichannel bills per channel" | **Confirmed** ([billing][billing]). |
| "Async audio kept ~24–48h" | **Confirmed** for uploads ([retention][ret]). |
| "transcripts until deleted via API" | **Refuted:** transcripts auto-delete after **30 days** by default ([delete FAQ][faqdel]). |
| Token "WITH the client's keyterms" | **Only as a payload hint.** Keyterms cannot be bound to the token (§2.3). |
| Webhook "verify signature" | AssemblyAI doesn't sign. Use a custom-header secret (§3.5, R6). |

---

## Recommendations for EchoLedger

**R1. Settings** (non-secret; `settings` table):

- REST base: `https://api.assemblyai.com`, or `https://api.eu.assemblyai.com` for EU residency.
- Streaming host: `streaming.assemblyai.com` by default (lowest latency).
- Streaming model: `universal-3-6-pro` by default (accuracy is priority #1). Offer `universal-streaming-english` / `-multilingual` as a "budget captions" option.
- Async model list: `["universal-3-5-pro","universal-2"]`.
- Token: `expires_in_seconds=60`, `max_session_duration_seconds=10800`.
- The API key and the webhook HMAC secret live in `credentials` (encrypted).

**R2. Exact token-mint request from Laravel.** This uses the verified signatures `get(string $url, $query = null)`, `withHeaders(array)`, `timeout(int|float)` and `connectTimeout(int|float)` (`web/vendor/laravel/framework/src/Illuminate/Http/Client/PendingRequest.php` lines 864, 455, 643, 656).

```http
GET https://streaming.assemblyai.com/v3/token?expires_in_seconds=60&max_session_duration_seconds=10800
Authorization: <ASSEMBLYAI_API_KEY>
```

```php
$resp = Http::withHeaders(['Authorization' => $apiKey])   // raw key, NO "Bearer"
    ->acceptJson()->connectTimeout(5)->timeout(10)
    ->get('https://streaming.assemblyai.com/v3/token', [
        'expires_in_seconds' => 60,              // required, 1..600
        'max_session_duration_seconds' => 10800, // optional, 60..10800
    ])->throw();
$token = $resp->json('token');                   // {"token":"…","expires_in_seconds":60}
```

`POST /api/stt/token` then returns the following to the device. It never contains the API key.

```json
{
  "provider": "assemblyai",
  "token": "<temp token>",
  "redeem_within_seconds": 60,
  "max_session_duration_seconds": 10800,
  "ws_url": "wss://streaming.assemblyai.com/v3/ws?speech_model=universal-3-6-pro&encoding=pcm_s16le&sample_rate=16000&inactivity_timeout=120&token=<temp token>",
  "keyterms": ["Acme Holdings", "Farah Khan", "EchoLedger"],
  "keyterms_delivery": "update_configuration",
  "sessions": ["me", "client"]
}
```

**R3. Device streaming contract** (desktop and mobile):

1. Open the **same `ws_url` twice**, once for the mic ("Me") and once for system/far-end audio ("Client"), within the 60 s window. The token is multi-use ([token spec][tok]).
2. On `Begin`, assert that `configuration.model` equals the requested model. Then immediately send `{"type":"UpdateConfiguration","keyterms_prompt":[…]}` **before the first audio frame**. This keeps URLs short and stops keyterms leaking into logs. Putting `keyterms_prompt` as a JSON array in the query string is the documented alternative ([keyterms][skt]).
3. Stream mono `pcm_s16le` at 16 kHz in 50 ms frames (1600 bytes) per session. Never send less than 50 ms or more than 1000 ms per frame.
4. Keep `turn_order → latest Turn`, replace rather than append, and finalize on `end_of_turn && turn_is_formatted`.
5. On stop, send `Terminate`, keep reading until `Termination`, log `session_duration_seconds`, then close.
6. Start a rollover timer at `max_session_duration_seconds − 120`: fetch a new token, open the new session, then terminate the old one.
7. Retry policy: §2.11.
8. The dictation box is a third, mic-only session that opens on demand and is terminated when the box closes, because billing runs on wall-clock time.
9. Offer a "captions: both / client-only / off" setting. Two U3.6 Pro sessions cost $0.90/h.

**R4. Exact final-transcript request** (two-channel file; channel 1 = mic "Me", channel 2 = system "Client"):

```http
POST https://api.assemblyai.com/v2/transcript
Authorization: <ASSEMBLYAI_API_KEY>
Content-Type: application/json
```

```json
{
  "audio_url": "https://cdn.assemblyai.com/upload/<uuid-from-/v2/upload>",
  "speech_models": ["universal-3-5-pro", "universal-2"],
  "language_detection": true,
  "multichannel": true,
  "punctuate": true,
  "format_text": true,
  "keyterms_prompt": ["Acme Holdings", "Farah Khan", "EchoLedger", "Q3 retainer"],
  "webhook_url": "https://ledger.example.com/api/webhooks/stt/assemblyai?rid=01JB8Z5M3Q6T2V9K4W7X0Y1Z2A",
  "webhook_auth_header_name": "X-EchoLedger-Webhook-Token",
  "webhook_auth_header_value": "<43-char base64url HMAC, see R6>"
}
```

- Don't send `speaker_labels` by default. The channel gives the label for free.
- Optional **group-call** mode adds `"speaker_labels": true, "speaker_options": {"max_speakers_expected": 4}`. Labels then come back as `1A`, `2A`, `2B`… ([multichannel][pmc]).
- Cap per-client keyterms at **100 unique terms, each ≤50 chars and ≤6 words**. One list then works for both streaming (hard limit 100) and async, including a U2 fallback (200).

**R5. Upload from the queue job** (stream the file; never load it into memory). `withBody` accepts a `StreamInterface`, string or resource (`PendingRequest.php` 306 and 1678), and `GuzzleHttp\Psr7\Utils::streamFor` is at `web/vendor/guzzlehttp/psr7/src/Utils.php:689`.

```php
$up = Http::withHeaders(['Authorization' => $apiKey])->timeout(900)
    ->withBody(\GuzzleHttp\Psr7\Utils::streamFor(fopen($absPath, 'rb')), 'application/octet-stream')
    ->post("{$base}/v2/upload")->throw();          // 422 => plain-text body
$uploadUrl = $up->json('upload_url');
```

Upload Ogg/Opus stereo with mic on the **left** channel and system audio on the **right**. AssemblyAI numbers left = "1" and right = "2" ([openapi][oas]). Store the returned `id` as `provider_job_id`.

Cross-note conflict (flagged by verification): `stt-azure-local.md` Recommendation 6 needs per-channel mono FLAC/WAV, because MAI and whisper-server cannot ingest Opus, and `stt-elevenlabs.md` R3 prefers stereo FLAC. Settle one device upload contract that works for every enabled provider.

**R6. Webhook handler** (`POST /api/webhooks/stt/assemblyai`; public; throttled):

- **Secret derivation:** `value = base64url(HMAC-SHA256(webhook_secret, "assemblyai|{rid}"))`. Recompute it on receipt and compare with `hash_equals($expected, (string) $request->header('X-EchoLedger-Webhook-Token'))`. It is stateless and unique per recording.
- Check that the body's `transcript_id` equals the stored `provider_job_id` for that `rid`.
- An IP allowlist (44.238.19.20 / 54.220.25.36) is **optional and configurable**, because proxies can rewrite the client IP.
- **Idempotency:** insert into `webhook_events` with a unique key `assemblyai:{transcript_id}:{status}`. On a duplicate, return 200 and do nothing.
- **Respond 2xx within 10 s:** queue `FetchAssemblyAiTranscript` and don't fetch inline.
- **Status codes:** return `401` for a bad secret (final, no retry). Return **5xx only for transient faults** so AssemblyAI retries (10 attempts, 10 s apart).
- **Job:**
  1. `GET /v2/transcript/{id}`.
  2. In one DB transaction, store the text, segments, words, `language_code`, `speech_model_used`, `audio_channels` and `metadata.warnings`.
  3. **Then** `DELETE /v2/transcript/{id}`, which also kills the upload ([delete ref][pdel]). Record `provider_deleted_at`.
  4. If `status=error`, store `error` and DELETE as well.
- **Polling fallback:** a scheduled job GETs any job with no webhook after about 2× the expected turnaround, which the [bulk guide][bulk] recommends. Respect the 60/min limit and `Retry-After`. It also makes local development possible without a public URL.

**R7. Channel → speaker mapping:**

- `channel "1"` → `Me`, `channel "2"` → `Client`. Cast with `(int)`; the type is a string.
- In group mode, `speaker` `1X` → `Me` and `2A`/`2B` → `Client A`/`Client B`.
- Mono voice-note imports use `multichannel:false`, and the user picks the label.
- Sort utterances by `start`.

**R8. Storing word timestamps** (times are ms from the start of *our* uploaded file, so they seek directly in playback):

```json
{"v":1,"unit":"ms",
 "segments":[{"spk":"Me","ch":1,"s":250,"e":2600,"text":"…","w":[0,11]}],
 "words":{"cols":["s","e","ch","conf","t"],"rows":[[250,650,1,0.975,"Smoke"],[730,1022,1,0.99999,"from"]]}}
```

- Keep normalized data only; don't keep the raw provider JSON (30-day privacy).
- Use our own recording duration in the UI; `audio_duration` is whole seconds.
- Live-caption words, if persisted, are session-relative. Store the session start offset.
- Cross-note conflict (flagged by verification): `stt-azure-local.md` §4 proposes `echoledger.transcript.v1` with a 0-based `channel` (0 = Me, 1 = Client) and `start_ms`, so `"ch":1` here means Me but `"channel":1` there means Client. The player in `web-laravel-filament.md` §3 expects seconds. Pick one shape before building.

**R9. Dashboard checklist** (MANUAL_QA):

- Upgrade to a paid plan, then turn on **Data Controls → Opt Out** (this also gives zero streaming retention).
- Set **TTL = 1 day** as a backstop for missed DELETEs and orphaned uploads.
- Consider the **EU endpoint**, which is excluded from training by policy ([retention][ret]).

**R10. Cost per recorded hour** (list prices today):

| Item | Cost |
|---|---|
| Async: U3.5 Pro + keyterms, 2 channels | (0.21 + 0.05) × 2 = **$0.52** |
| Group-mode diarization | about +0.02 × 2 (per-channel scaling of add-ons is **UNVERIFIED**) |
| Captions: 2 × U3.6 Pro | **$0.90** |
| Captions: 2 × Universal-Streaming English | (0.15 + 0.04) × 2 = **$0.38** |
| Captions: 2 × Universal-Streaming Multilingual | **$0.30** |

**R11. Pest tests to add:**

- **Token endpoint.** Use `Http::fake()`/`assertSent()` (`Factory.php` 331/496) and `Request::hasHeader()` (`Client/Request.php` 83). Assert the upstream call carries the key, the response JSON never contains it, and the params are in range.
- **Request builder.** Snapshot the exact JSON. Cover the keyterm cap, dedupe and the 50-char/6-word limits.
- **Webhook.** Missing or bad header gives 401. A replay gives 200 with one job. A mismatched `transcript_id` gives 4xx.
- **Mapper.** A fixture GET response maps to Me/Client segments and words.
- **Ordering.** DELETE runs only after a successful store.

---

## Gotchas

1. There is no `Bearer` prefix: `Authorization: <key>` ([openapi][oas]).
2. Streaming uses `speech_model` (singular). Async uses `speech_models` (plural); async `speech_model` is deprecated ([openapi][oas], [integration guide][guide]).
3. Misspelled WS params are silently ignored, so verify `Begin.configuration` ([message sequence][seq]).
4. Temp tokens are multi-use and not tied to any configuration ([token spec][tok]).
5. Streaming bills wall-clock time per session. Always `Terminate`. Hitting `max_session_duration_seconds` adds up to 60 s of billed overrun ([token spec][tok], [billing][billing]).
6. Closing right after `Terminate` loses the last transcript. Wait for `Termination` ([message sequence][seq]).
7. Chunks must be 50–1000 ms. More than 1.25× real time is throttled, and more than 5 minutes buffered gives `3007` ([message sequence][seq], [errors][errs]).
8. `format_turns=true` (Universal Streaming) sends two finals per turn ([message sequence][seq]).
9. `1008` is ambiguous. Branch on the `error` text, and retry only on "Too many concurrent sessions" ([errors][errs]).
10. A webhook **4xx is permanent**. You have a 10 s budget and the payload has no text ([webhooks][pwh]).
11. Webhooks are not signed. Use a secret header, and treat `GET` as the source of truth ([webhooks][pwh]).
12. DELETE also deletes the upload. There is no standalone upload delete; orphaned uploads expire in 24–48 h or at the TTL ([openapi][oas], [retention][ret]).
13. Upload and transcribe need the same project key, otherwise `403` ([openapi][oas]).
14. `/v2/upload` takes raw binary, and `422` returns plain text (not JSON) ([openapi][oas]).
15. `language_code`, `language_detection` and `language_codes` are mutually exclusive ([openapi][oas], [code switching][cs]).
16. Keyterm limits differ by model: 1000 on U3.5 Pro, 200 on U2 (the fallback), 100 on streaming (error above 100). Streaming terms over 50 chars are silently ignored ([openapi][oas], [keyterms][skt]).
17. `word_boost` is legacy and removed ([openapi][oas], [asyncapi.json][v2rt]).
18. `channel` is a string, and `audio_duration` is integer seconds ([openapi][oas]).
19. Mic bleed of speaker audio creates duplicate text on "Me". Recommend headphones or AEC and document it in MANUAL_QA ([duplicate channels][dup]).
20. Exceeding the HTTP limit returns **403**, not 429 ([rate limits][prl]).
21. Transcripts auto-delete after 30 days even if you don't DELETE them ([delete FAQ][faqdel]).
22. Free accounts can't opt out of training or set a TTL ([data controls][dc]).
23. Doc inconsistency: the spec labels `mode`, `prompt` and `interruption_delay` "Universal-3.5 Pro Streaming only", while the guides say 3.6 Pro too ([ws spec][ws] vs [mid-stream][smid]). Treat them as Pro-family only.
24. `Termination` durations are integers, so don't use them for precise billing reconciliation ([ws spec][ws]).

---

## Load-bearing claims

| # | Claim | Source | Confidence |
|---|---|---|---|
| 1 | Token: `GET https://streaming.assemblyai.com/v3/token`, header `Authorization: <key>`, `expires_in_seconds` 1–600 (required), `max_session_duration_seconds` 60–10800 (default 10800); response `{token, expires_in_seconds}` | [streaming-token.yaml][tok] | high |
| 2 | The token is not bound to any configuration and can open multiple concurrent sessions within its window. Keyterms are set by the client at connect time or via `UpdateConfiguration` | [streaming-token.yaml][tok], [keyterms][skt], [mid-stream][smid] | high |
| 3 | WS `wss://streaming.assemblyai.com/v3/ws`; `token` query auth; `speech_model` enum incl. default `universal-3-6-pro`; `pcm_s16le` 16 kHz default; `keyterms_prompt` max 100 (≤50 chars each) | [streaming.yaml][ws], [keyterms][skt] | high |
| 4 | Messages `Begin{id,expires_at,configuration}`, `Turn{turn_order,end_of_turn,turn_is_formatted,transcript,words[]}`, `Termination{audio_duration_seconds,session_duration_seconds}`, `Error{error_code,error}`; after `Terminate`, read until `Termination` | [streaming.yaml][ws], [message sequence][seq] | high |
| 5 | No multichannel streaming: one session per channel, each billed on its own open time | [diarization/multichannel][sdiar], [billing][billing] | high |
| 6 | Async create accepts `speech_models`, `multichannel`, `keyterms_prompt` (≤1000/≤200, ≤6 words), `webhook_url`, `webhook_auth_header_name`/`_value` (with charset limits) | [openapi.json][oas] | high |
| 7 | Multichannel response: `audio_channels`; `utterances[].channel` and `words[].channel` are strings, left = "1", right = "2"; `{channel}{speaker}` labels with `speaker_labels` | [openapi.json][oas], [multichannel][pmc] | high (types) / medium (multichannel-only `speaker` value) |
| 8 | Webhook body `{transcript_id,status}`; 2xx within 10 s; up to 10 attempts 10 s apart; 4xx not retried; no signature; IPs 44.238.19.20 / 54.220.25.36 | [webhooks][pwh] | high |
| 9 | `DELETE /v2/transcript/{id}` removes the data and immediately deletes the `/upload` file | [openapi.json][oas], [delete ref][pdel] | high |
| 10 | Retention: uploads 24–48 h, transcripts 30 days by default; TTL down to 1 h; opt-out on paid plans only | [retention][ret], [data controls][dc] | high |
| 11 | Prices: U3.5 Pro $0.21/h + keyterms $0.05/h; U3.6 Pro Realtime $0.45/h (keyterms included); Universal-Streaming $0.15/h; per-channel billing | [pricing][pricing], [billing][billing] | medium (prices change) |
| 12 | Limits: upload ≤2.2 GB; transcript ≤5 GB / 10 h; min 160 ms; async 200+ concurrent; 20k HTTP requests per 5 min (403) | [limits FAQ][faqlim], [min FAQ][faqmin], [rate limits][prl] | high |

---

## Open questions

1. What exactly is `utterances[].speaker` in multichannel-only mode: the channel number, or a letter? Verify with a real stereo file. The mapper must key on `channel` regardless.
2. What happens when there are more than 200 keyterms and the request falls back to `universal-2` (error or truncation)? Not documented. Avoided by the 100 cap.
3. In multichannel, is language detection per channel or per file (for example, "Me" in English and "Client" in another language)? Not documented.
4. Does DELETE on an `error` transcript also purge the upload? What does DELETE return for an already-deleted id?
5. What is the maximum channel count for `multichannel`? Not documented.
6. Are add-ons (keyterms, diarization) multiplied per channel like the base rate? Assumed yes.
7. Can a token minted at `streaming.assemblyai.com` be redeemed on the `streaming.us`/`streaming.eu` data-zone hosts? Is there a regional token host? Only the global host is listed ([token spec][tok]).
8. Should `language_codes` on the WS query be JSON-encoded like `keyterms_prompt`? The spec example is a JSON string; guide samples pass a Python list.
9. Does the 50 ms chunk minimum (`3007`) apply to `ogg_opus` arbitrary chunks or 20 ms Opus packets? This matters if mobile bandwidth forces Opus.
10. Is there a maximum WS URL length? It matters only if keyterms go in the query; R3 avoids it.
11. Does the EU REST endpoint have feature parity (U3.5 Pro, multichannel, keyterms)? The pricing page says the price is equal; features aren't stated.
12. Does `UpdateConfiguration` with more than 100 keyterms close the session? There is no ack, so the behavior is unknown.

[oas]: https://www.assemblyai.com/docs/openapi.json
[tok]: https://www.assemblyai.com/docs/api-reference/specs/streaming-token.yaml
[ws]: https://www.assemblyai.com/docs/api-reference/specs/streaming.yaml
[v2rt]: https://www.assemblyai.com/docs/asyncapi.json
[dict]: https://www.assemblyai.com/docs/api-reference/specs/dictation.yaml
[dictdocs]: https://www.assemblyai.com/docs/dictation
[vf]: https://www.assemblyai.com/docs/streaming/voice-focus
[llmsfull]: https://www.assemblyai.com/docs/llms-full.txt
[guide]: https://www.assemblyai.com/docs/coding-agent-prompts
[tmp]: https://www.assemblyai.com/docs/streaming/authenticate-with-a-temporary-token
[seq]: https://www.assemblyai.com/docs/streaming/message-sequence
[errs]: https://www.assemblyai.com/docs/streaming/common-session-errors-and-closures
[srl]: https://www.assemblyai.com/docs/streaming/rate-limits
[zones]: https://www.assemblyai.com/docs/streaming/endpoints-and-data-zones
[smodel]: https://www.assemblyai.com/docs/streaming/select-the-speech-model
[skt]: https://www.assemblyai.com/docs/streaming/prompting-and-keyterms
[smid]: https://www.assemblyai.com/docs/streaming/updating-configuration-mid-stream
[sdiar]: https://www.assemblyai.com/docs/streaming/label-speakers-and-separate-channels
[turn]: https://www.assemblyai.com/docs/streaming/turn-detection
[opt]: https://www.assemblyai.com/docs/streaming/getting-started/optimizing-accuracy-and-latency
[smulti]: https://www.assemblyai.com/docs/streaming/multilingual-transcription
[swh]: https://www.assemblyai.com/docs/streaming/webhooks
[sbench]: https://www.assemblyai.com/docs/streaming/benchmarks
[pbench]: https://www.assemblyai.com/docs/pre-recorded-audio/benchmarks
[models]: https://www.assemblyai.com/docs/getting-started/models
[pricing]: https://www.assemblyai.com/pricing
[billing]: https://www.assemblyai.com/docs/billing-and-pricing
[pmodel]: https://www.assemblyai.com/docs/pre-recorded-audio/select-the-speech-model
[pkt]: https://www.assemblyai.com/docs/pre-recorded-audio/universal-3-5-pro/prompting
[pmc]: https://www.assemblyai.com/docs/pre-recorded-audio/transcribe-multiple-audio-channels
[pwh]: https://www.assemblyai.com/docs/pre-recorded-audio/webhooks
[pdel]: https://www.assemblyai.com/docs/pre-recorded-audio/api-reference/transcripts/delete
[region]: https://www.assemblyai.com/docs/pre-recorded-audio/select-the-region
[prl]: https://www.assemblyai.com/docs/pre-recorded-audio/rate-limits
[langs]: https://www.assemblyai.com/docs/pre-recorded-audio/supported-languages
[cs]: https://www.assemblyai.com/docs/pre-recorded-audio/code-switching
[dup]: https://www.assemblyai.com/docs/pre-recorded-audio/guides/identify_duplicate_channels
[bulk]: https://www.assemblyai.com/docs/pre-recorded-audio/guides/bulk-transcription-and-load-tests-at-scale
[ret]: https://www.assemblyai.com/docs/data-retention-and-model-training
[dc]: https://www.assemblyai.com/docs/data-controls
[optout]: https://www.assemblyai.com/docs/faq/how-to-opt-out-of-data-sharing-for-our-model-improvement-program
[faqlim]: https://www.assemblyai.com/docs/faq/are-there-any-limits-on-file-size-or-file-duration-for-files-submitted-to-the-api
[faqfmt]: https://www.assemblyai.com/docs/faq/what-audio-and-video-file-types-are-supported-by-your-api
[faqip]: https://www.assemblyai.com/docs/faq/what-ip-address-should-i-whitelist-for-assemblyai
[faqmin]: https://www.assemblyai.com/docs/faq/what-is-the-minimum-audio-duration-that-the-api-can-transcribe
[faqdel]: https://www.assemblyai.com/docs/faq/can-i-delete-the-transcripts-i-have-created-using-the-api
[faqret]: https://www.assemblyai.com/docs/faq/how-long-are-outputs-retained
[faqeu]: https://www.assemblyai.com/docs/faq/do-you-offer-servers-in-the-eu
