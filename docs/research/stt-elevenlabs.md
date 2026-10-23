# ElevenLabs Scribe (alternative STT provider), with short OpenAI and Groq notes

> Verified research note for EchoLedger, written 2026-10-24. Scope comes from `docs/spec/MASTER_PROMPT.md`. Claims taken from `docs/spec/BUILD_PLAN.md` were treated as hypotheses and checked (see §5.3).
> **Rule:** every API fact carries a source label from the legend below. Each label points to a primary source opened during research. Anything I could not confirm is marked **UNVERIFIED**, with the reason.
> The ElevenLabs docs serve raw markdown when you append `.md` to a page URL (index at https://elevenlabs.io/docs/llms.txt). Most quotes below come from those `.md` copies and from the OpenAPI JSON.

## Sources legend

| Label | Primary source |
|---|---|
| E1 | ElevenLabs OpenAPI 3.1.0 spec, https://api.elevenlabs.io/openapi.json (`info.version` "1.0"). Schemas are named inline, e.g. `Body_Speech_to_Text_v1_speech_to_text_post`. |
| E2 | Create transcript reference, https://elevenlabs.io/docs/api-reference/speech-to-text/convert |
| E3 | Transcription capability overview, https://elevenlabs.io/docs/overview/capabilities/speech-to-text |
| E4 | Multichannel guide, https://elevenlabs.io/docs/eleven-api/guides/how-to/speech-to-text/batch/multichannel-transcription |
| E5 | Asynchronous STT (webhooks) guide, https://elevenlabs.io/docs/eleven-api/guides/how-to/speech-to-text/batch/webhooks |
| E6 | Webhooks resource page, https://elevenlabs.io/docs/eleven-api/resources/webhooks |
| E7 | Create workspace webhook, https://elevenlabs.io/docs/api-reference/webhooks/create. **E7b** is Update, https://elevenlabs.io/docs/api-reference/webhooks/update |
| E8 | Get transcript, https://elevenlabs.io/docs/api-reference/speech-to-text/get. **E8b** is Delete, https://elevenlabs.io/docs/api-reference/speech-to-text/delete |
| E9 | Create single-use token, https://elevenlabs.io/docs/api-reference/tokens/create |
| E10 | Realtime WebSocket reference (embedded AsyncAPI 2.6.0), https://elevenlabs.io/docs/api-reference/speech-to-text/v-1-speech-to-text-realtime |
| E11 | Realtime transcripts and commit strategies, https://elevenlabs.io/docs/eleven-api/guides/how-to/speech-to-text/realtime/transcripts-and-commit-strategies |
| E12 | Realtime event reference, https://elevenlabs.io/docs/eleven-api/guides/how-to/speech-to-text/realtime/event-reference |
| E13 | Client-side streaming, https://elevenlabs.io/docs/eleven-api/guides/how-to/speech-to-text/realtime/client-side-streaming, and the JS Scribe SDK page, https://elevenlabs.io/docs/eleven-api/resources/libraries/scribe-stt/javascript-scribe |
| E14 | Keyterm prompting, https://elevenlabs.io/docs/eleven-api/guides/how-to/speech-to-text/batch/keyterm-prompting |
| E15 | Models (including deprecated models and the concurrency table), https://elevenlabs.io/docs/overview/models |
| E16 | API pricing page, https://elevenlabs.io/pricing/api (raw HTML table read on 2026-10-24) |
| E17 | Zero Retention Mode, https://elevenlabs.io/docs/eleven-api/resources/zero-retention-mode |
| E18 | API keys, https://elevenlabs.io/docs/overview/administration/workspaces/api-keys. **E18b** is Data residency, https://elevenlabs.io/docs/overview/administration/data-residency |
| E19 | Changelog, https://elevenlabs.io/docs/changelog |
| S1 | `elevenlabs-python` `src/elevenlabs/webhooks_custom.py` (main), https://github.com/elevenlabs/elevenlabs-python/blob/main/src/elevenlabs/webhooks_custom.py |
| S2 | `elevenlabs-js` `src/wrapper/webhooks.ts` (main), https://github.com/elevenlabs/elevenlabs-js/blob/main/src/wrapper/webhooks.ts |
| S3 | `elevenlabs-python` `src/elevenlabs/speech_to_text/raw_client.py` (main), https://github.com/elevenlabs/elevenlabs-python/blob/main/src/elevenlabs/speech_to_text/raw_client.py |
| S4 | httpx `httpx/_multipart.py` (`_iter_fields`), https://github.com/encode/httpx/blob/master/httpx/_multipart.py |
| S5 | `@elevenlabs/client` `packages/client/src/scribe/scribe.ts` and `connection.ts`, https://github.com/elevenlabs/packages/tree/main/packages/client/src/scribe |
| L1 | `web/vendor/laravel/framework/src/Illuminate/Http/Client/PendingRequest.php` (laravel/framework v13.38.0) |
| L2 | `web/vendor/guzzlehttp/psr7/src/MultipartStream.php` (guzzlehttp/psr7 3.2.0) |
| L3 | `web/vendor/guzzlehttp/guzzle/src/Client.php` (guzzlehttp/guzzle 8.3.0) |
| L4 | `web/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php`: `createOrFirst()` at line 771 catches `UniqueConstraintViolationException` |
| L5 | `web/vendor/laravel/framework/src/Illuminate/Http/Client/Factory.php` (`fake()` line 331, `preventStrayRequests()` line 428, `assertSent()` line 496) and `.../Http/Client/Request.php` (`data()` line 170, `isMultipart()` line 237) |
| O1 | OpenAI file transcription guide, https://developers.openai.com/api/docs/guides/speech-to-text (platform.openai.com redirects here with a 301) |
| O2 | OpenAI Create transcription reference, https://developers.openai.com/api/reference/resources/audio/subresources/transcriptions/methods/create |
| O3 | OpenAI `gpt-transcribe` model page, https://developers.openai.com/api/docs/models/gpt-transcribe. **O3b** is `gpt-live-transcribe`, https://developers.openai.com/api/docs/models/gpt-live-transcribe |
| O4 | OpenAI deprecations, https://developers.openai.com/api/docs/deprecations |
| O5 | OpenAI "Your data", https://developers.openai.com/api/docs/guides/your-data |
| G1 | Groq Speech to Text, https://console.groq.com/docs/speech-to-text |
| G2 | Groq `whisper-large-v3-turbo` model page, https://console.groq.com/docs/model/whisper-large-v3-turbo |
| G3 | Groq "Your data", https://console.groq.com/docs/your-data |
| G4 | Groq rate limits, https://console.groq.com/docs/rate-limits |

## Versions verified on 2026-10-24

| Item | Version / ID | Source |
|---|---|---|
| ElevenLabs REST API | `/v1`, OpenAPI 3.1.0 | E1 |
| Batch models | `scribe_v2`, `scribe_v2_medical`. `scribe_v1` is deprecated (replacement `scribe_v2`) | E15, E1 |
| Realtime model | `scribe_v2_realtime` (the only enum value, and the default) | E10 |
| Python SDK `elevenlabs` | 2.74.1 (PyPI, uploaded 2026-10-20) | pypi.org/pypi/elevenlabs/json |
| `@elevenlabs/elevenlabs-js` / `@elevenlabs/client` / `@elevenlabs/react` | 2.74.0 / 1.30.0 / 1.19.1 | registry.npmjs.org |
| Laravel / Guzzle / psr7 / PHP (installed) | v13.38.0 / 8.3.0 / 3.2.0 / 8.5.10 | `web/vendor/composer/installed.json`, `php -v` |
| OpenAI transcription models | `gpt-transcribe` (file), `gpt-live-transcribe` (realtime). `whisper-1`, `gpt-4o-transcribe`, `gpt-4o-mini-transcribe` and `gpt-4o-transcribe-diarize` shut down 2027-02-26 | O1, O3, O3b, O4 |
| Groq transcription models | `whisper-large-v3-turbo`, `whisper-large-v3` | G1 |

We do **not** plan to ship any ElevenLabs SDK. Laravel calls the REST API directly, and the devices speak the WebSocket protocol directly (minimal-deps rule). The SDKs were read only as primary sources for wire behaviour.

## TL;DR

- Batch call: `POST https://api.elevenlabs.io/v1/speech-to-text` as multipart, with header `xi-api-key` [E1].
- Our two-channel final transcript uses these fields [E1][E4]:
  - `model_id=scribe_v2`
  - `use_multi_channel=true`
  - `diarize=false`, and no `num_speakers`
  - one repeated `keyterms` field per term
  - `webhook=true`, `webhook_id=…`, `webhook_metadata={json}`
- Channel 0 becomes `speaker_0` and channel 1 becomes `speaker_1`. Every word carries `channel_index` [E4].
- Keyterm limits [E1]:
  - batch: up to 1000 terms, each under 50 chars and at most 5 words, none of `< > { } [ ] \`, plus a 20% surcharge
  - realtime: up to 50 terms of at most 20 chars [E14][S5]
- **PHP trap:** Guzzle psr7 3.2.0 expands array contents to `keyterms[0]`, `keyterms[1]` [L2], and PHP's `http_build_query` emits `keyterms%5B0%5D` (checked locally). Both differ from the documented repeated `keyterms` field [S3][S4][E14][E19]. Build one part (or one query pair) per term by hand.
- Webhooks are HMAC-signed [S1][S2]:
  - header `elevenlabs-signature: t=<unix>,v0=<hex>`
  - `v0 = hex(HMAC_SHA256(secret, "<t>.<rawBody>"))`
  - the SDK rejects timestamps older than 30 min
- STT webhooks are **delivered once by default**. Turn on `retry_enabled` for retries on 5xx/408/429 [E5][E7b]. The `ElevenLabs-Webhook-Delivery-Id` header is stable across retries and makes a good idempotency key [E5].
- Transcript management [E1][E8][E8b]:
  - fetch: `GET /v1/speech-to-text/transcripts/{transcription_id}`
  - delete: `DELETE /v1/speech-to-text/transcripts/{transcription_id}`
  - zero retention (`enable_logging=false`) is **Enterprise-only** [E1][E17]
- Realtime [E1][E9][E10][E11]:
  - mint a token with `POST /v1/single-use-token/realtime_scribe`, which returns `{"token":"sutkn_…"}`; the token expires after 15 min and is consumed on use
  - connect to `wss://api.elevenlabs.io/v1/speech-to-text/realtime?model_id=scribe_v2_realtime&token=…`
  - audio is **mono only**, so two channels need two sessions and two tokens
- Price per audio hour, the same on every listed plan [E16]:

  | Item | Batch (Scribe v2) | Realtime |
  |---|---|---|
  | Base rate | $0.22 | $0.39 |
  | Keyterms add-on | $0.05 | $0.08 |

  Multichannel bills each channel at full duration [E1].
- OpenAI now recommends `gpt-transcribe` ($0.0045/min) [O1][O3]. It returns **no word timestamps**: `timestamp_granularities[]` works only on deprecated `whisper-1` [O1]. Groq `whisper-large-v3-turbo` costs $0.04/h and returns word timestamps, but has no keyterms and downmixes to mono [G1]. Both are optional future drivers only.

---

## 1. Batch transcription: `POST /v1/speech-to-text`

### 1.1 Endpoint, hosts, auth
- `POST https://api.elevenlabs.io/v1/speech-to-text`, `Content-Type: multipart/form-data`, operationId `speech_to_text` [E1][E2].
- Other servers are `https://api.us.elevenlabs.io`, `https://api.eu.residency.elevenlabs.io`, `https://api.in.residency.elevenlabs.io` and `https://api.sg.residency.elevenlabs.io` [E2]. A residency environment is a separate account: "you will need to hit a different API URL with a different API key" [E18b].
- Auth uses the header `xi-api-key: <key>` (a header parameter on every operation; the spec declares no `securitySchemes`) [E1].
- Alternative auth for frontend clients: query `token=`, a token from `POST /v1/single-use-token/batch_scribe` that "can only be used once and expires after 15 minutes" [E1]. EchoLedger does batch on the server, so it uses the header.
- Operation description [E1]: "If webhook is set to true, the request will be processed asynchronously and results sent to configured webhooks. When use_multi_channel is true and the provided audio has multiple channels, a 'transcripts' object with separate transcripts for each channel is returned…"

### 1.2 Models available today

| `model_id` | Lane | Status | Source |
|---|---|---|---|
| `scribe_v2` | batch | current; OpenAPI example | E15, E1 |
| `scribe_v2_medical` | batch | current; "Same features, languages, pricing, and API as Scribe v2"; GA 2026-09-11 | E3, E15, E19 |
| `scribe_v2_realtime` | realtime WS only | current | E10, E15 |
| `scribe_v1` | batch | **deprecated**, "outclassed by v2 models", replacement `scribe_v2`. I found no shutdown date | E15 |

### 1.3 Request parameters
Names, types and defaults are verbatim from `Body_Speech_to_Text_v1_speech_to_text_post` and the operation parameters in [E1].

Query parameters:

| Param | Type / default | Meaning |
|---|---|---|
| `enable_logging` | bool, default `true` | "When enable_logging is set to false zero retention mode will be used… Zero retention mode may only be used by enterprise customers." [E1] |
| `token` | string, nullable | Single-use `batch_scribe` token (see above) [E1] |

Multipart fields (only `model_id` is required):

| Field | Type / allowed values | Default | Notes |
|---|---|---|---|
| `model_id` | string | — | examples `scribe_v2`, `scribe_v2_medical` |
| `file` | binary | — | "100ms minimum"; "less than 5.0GB"; "Exactly one of the file or cloud_storage_url parameters must be provided" (but see §1.4 for a 3 GB figure) |
| `source_url` | string | null | "hosted video or audio files, YouTube video URLs, TikTok video URLs…" |
| `cloud_storage_url` | string | null | **deprecated**: "Use 'source_url' instead". HTTPS, under 2GB; "URLs can be pre-signed or include authentication tokens" |
| `language_code` | ISO-639-1 or ISO-639-3 | null (auto-detect) | |
| `tag_audio_events` | bool | **`true`** | emits `(laughter)` and similar as `type:"audio_event"` |
| `num_speakers` | int 1–32 | null | not allowed with multichannel [E4] |
| `timestamps_granularity` | `none` \| `word` \| `character` | `word` | |
| `diarize` | bool | `false` | must stay `false` with multichannel [E4] |
| `diarization_threshold` | number 0.1–0.4 | null (about 0.22) | only when `diarize=true` and `num_speakers` is null |
| `additional_formats` | array (max 10) of `ExportOptions` | — | `format` is one of `segmented_json`, `docx`, `pdf`, `txt`, `html`, `srt` |
| `file_format` | `pcm_s16le_16` \| `other` | `other` | `pcm_s16le_16` means 16-bit, 16 kHz, **mono**, little-endian, so it cannot carry our stereo files |
| `webhook` | bool | `false` | "the request will return early without the transcription" |
| `webhook_id` | string | null | "Only valid when webhook is set to true." Without it, delivery goes to all configured STT webhooks |
| `webhook_metadata` | JSON string or object | null | "maximum depth of 2 levels and maximum size of 16KB" |
| `temperature` | 0.0–2.0 | model default ("usually 0") | |
| `seed` | int 0–2147483647 | null | best effort; determinism not guaranteed |
| `use_multi_channel` | bool | `false` | "A maximum of 5 channels… Each channel is billed independently at the full audio duration" |
| `multichannel_output_style` | `separate` \| `combined` | `separate` | `combined` needs timestamps, does not support entity detection/redaction, and is **not supported with webhooks** [E4] |
| `keyterms` | array of strings | `[]` | see the row breakdown below |
| `entity_detection` / `entity_redaction` | `'all'`, a category, or a list | null | categories `pii`, `phi`, `pci`, `other`, `offensive_language` |
| `entity_redaction_mode` | `redacted` \| `entity_type` \| `enumerated_entity_type` | `enumerated_entity_type` | |
| `no_verbatim` | bool | `false` | the spec says "Only supported with scribe_v2 model". [E3] also lists v2 Medical and v2 Realtime |
| `use_speaker_library` | bool | `false` | requires diarize |
| `detect_speaker_roles` | bool | `false` | requires diarize; not with multichannel; adds a 10% surcharge |
| `transcript_edit` | string, max 2000 chars | null | not with multichannel; adds 30%, billed for at least 10 s. **Do not use**: v1 has no AI rewriting |

The `keyterms` row in detail (text quoted from [E1]):
- "The number of keyterms cannot exceed 1000."
- "The length of each keyterm must be less than 50 characters."
- "at most 5 words (after normalisation)"
- Unsupported characters: `<`, `>`, `{`, `}`, `[`, `]`, `\`.
- "additional 20% surcharge"
- "When more than 100 keyterms are provided, a minimum billable duration of 20 seconds applies per request."

### 1.4 Limits and formats
- **File size conflict:**
  - The capability FAQ says "Files up to 3 GB", and its Key facts list "Maximum file size: 3 GB" [E3].
  - The OpenAPI `file` description says "less than 5.0GB" [E1].
  - Treat 3 GB as the limit.
- **Duration conflict:**
  - Standard mode allows "Up to 10 hours" [E3].
  - For multichannel, the FAQ says "The combined duration of all channels must be less than 10 hours" (so under 5 h for stereo) [E3].
  - The Key facts on the **same page** say "1 hour (multichannel mode)" [E3].
  - **UNVERIFIED which limit is enforced.** Design for 1 h or less per two-channel file until a live test settles it.
- **Accepted formats** [E3]:
  - audio: aac, x-aac, x-aiff, ogg, mpeg, mp3, mpeg3, x-mpeg-3, opus, wav, x-wav, webm, flac, x-flac, mp4, aiff, x-m4a
  - video: mp4, x-msvideo, x-matroska, quicktime, x-ms-wmv, x-flv, webm, mpeg, 3gpp
- **Multichannel guidance** [E4]:
  - "Use 16kHz sample rate for better performance", "Use lossless formats (WAV) when possible", "Maximum 5 channels per audio file".
  - A mono file sent with `use_multi_channel=true` returns the standard single-channel shape.
  - More than 5 channels is an error.
  - Processing-time estimate: `(D·0.3) + 2 + (N·0.5)` seconds, where D is the duration in seconds and N the channel count. A 60 s stereo file takes about 21 s.
- **Concurrency:**
  - Files longer than 8 minutes are split internally: concurrency = `min(4, round_up(duration_secs/480))` [E3].
  - Multichannel "concurrency cost increases linearly with the number of channels" [E4].
  - Batch STT concurrency per plan: Free 8, Starter 12, Creator 20, Pro 40, Scale 60, Business 60 [E15].
  - Requests over the limit queue; they do not fail [E15].
  - Response headers `current-concurrent-requests` and `maximum-concurrent-requests` show usage [E15].

### 1.5 Multipart encoding rules (critical for our PHP driver)
1. **Arrays go out as repeated same-name fields.**
   - The official Python SDK sends `data={… "keyterms": keyterms …}` with `force_multipart=True` [S3].
   - httpx's `_iter_fields` turns a list value into `yield DataField(name=name, value=item)` per item, so the field name is repeated [S4].
   - Changelog, 2026-08-24: Python SDK v2.65.0 "preserves repeated multipart fields for Speech to Text `keyterms`" [E19].
2. **JSON-typed fields go out as strings.** `webhook_metadata`, `entity_detection` and `entity_redaction` are sent as `json.dumps(...)` strings. `additional_formats` is sent as its own part with content type `application/json` [S3].
3. **Booleans go out as literal `true` / `false`.** The docs' cURL uses `-F "use_multi_channel=true"` and `-F "diarize=false"` [E4].
4. **Laravel/Guzzle behaviour, verified in the installed vendor code:**
   - `PendingRequest::parseMultipartBodyFormat()` passes through any array element that already has `name` and `contents`, and wraps everything else as `['name' => $key, 'contents' => $value]` [L1 lines 1196–1209].
   - `MultipartStream::addNestedElements()` then renames array contents to `sprintf('%s[%s]', $root, $key)`, which produces **`keyterms[0]`, `keyterms[1]`** [L2 lines 165–176]. That is the wrong wire format.
   - psr7 casts non-string scalars with `(string) $contents` [L2 lines 128–138], so PHP `false` becomes `""`.
   - **Therefore:** pass a list of pre-built parts, one `['name' => 'keyterms', 'contents' => $term]` per term, and use string `'true'` / `'false'` values.

### 1.6 Response shapes [E1][E4][E8]

**Synchronous, single channel (`SpeechToTextChunkResponseModel`)**
- Required: `language_code` (string; the description says "e.g. 'eng'", while examples show `"en"`, so accept both), `language_probability` (0–1), `text`, `words[]`.
- Optional: `channel_index`, `additional_formats[]`, `transcription_id`, `entities[]`, `audio_duration_secs`, `edited_transcript`.

**Word object (`SpeechToTextWordResponseModel`)**
- Required: `text`, `type` (`word` \| `spacing` \| `audio_event`), `logprob` (≤ 0).
- Optional: `start`, `end` (seconds), `speaker_id`, `characters[]` (`{text,start,end}`), `channel_index` ("Null for single-channel transcriptions").
- `spacing` entries do not exist for languages written without spaces (Japanese, Mandarin, Thai, Lao, Burmese, Cantonese) [E3].

**Multichannel, `separate` (`MultichannelSpeechToTextResponseModel`)**
- `{ "transcripts": [ChunkModel…], "transcription_id"?: string, "audio_duration_secs"?: number }`, where `audio_duration_secs` is "across all channels" [E1].
- Verbatim example from [E4]:
```json
{ "transcripts": [
    { "language_code": "en", "language_probability": 0.98, "text": "Hello from channel one.", "channel_index": 0, "words": [...] },
    { "language_code": "en", "language_probability": 0.97, "text": "Greetings from channel two.", "channel_index": 1, "words": [...] } ] }
```

**Multichannel, `combined`**
- The flat single-channel shape. Words are sorted by start time and each carries `channel_index` and `speaker_id`. The top-level `language_code` is "the most confident channel" [E4].

**Async (`webhook=true`)**
- The spec lists 200 with `SpeechToTextWebhookResponseModel` = `{message, request_id, transcription_id?}` and also a 202 example: `{"message": "Request accepted. Transcription result will be sent to the webhook.", "request_id": "abc123"}` [E1]. **Accept any 2xx.**
- Store `request_id` (always present) and `transcription_id` (nullable).
- The multichannel guide's sample prints `result.task_id` [E4]. The schema has no such field, so trust `request_id` [E1][E5].

**Errors**
- 422: `{"detail":[{"loc":[…],"msg":"…","type":"…"}]}` [E1].
- 401 (documented on GET/DELETE): `{"detail":{"status":"unauthorized","message":"Authentication required."}}` [E1].

### 1.7 Example: two-channel file, keyterms, webhook
This request is assembled from documented fields only. Keyterms repeat as in [S3][S4][E14].
```bash
curl -X POST "https://api.elevenlabs.io/v1/speech-to-text" \
  -H "xi-api-key: $ELEVENLABS_API_KEY" \
  -F "file=@call.flac;type=audio/flac" \
  -F "model_id=scribe_v2" -F "use_multi_channel=true" -F "diarize=false" \
  -F "timestamps_granularity=word" -F "tag_audio_events=false" \
  -F "keyterms=Acme Corp" -F "keyterms=Zed-9 Pro" \
  -F "webhook=true" -F "webhook_id=$EL_WEBHOOK_ID" \
  -F 'webhook_metadata={"recording_id":"rec_123","session_id":"ses_456"}'
```

---

## 2. Webhooks

### 2.1 Configuration
**Dashboard**
- Path: Developers > Webhooks (https://elevenlabs.io/app/developers/webhooks), then **Create webhook** [E5].
- Fields to set [E5]:
  - **Name**
  - **Callback URL**: public HTTPS
  - **Webhook Auth Method**: `HMAC` or `OAuth`
  - **Events**: **Transcription completed**
- Other dashboard rules:
  - "For users within Workspaces, only the workspace admins can configure the webhooks" [E6].
  - "Webhook URLs must use HTTPS" [E5].
  - Retries are off by default and can be enabled in the webhook settings or via the API [E6].

**API, as an alternative** [E1][E7][E7b]
- **Create:** `POST /v1/workspace/webhooks` with JSON:
  ```json
  {"settings":{"auth_type":"hmac","name":"EchoLedger STT","webhook_url":"https://host/api/webhooks/stt/elevenlabs","request_headers":{"X-EchoLedger-Hook":"<random>"}}}
  ```
  - The response is `{"webhook_id": "...", "webhook_secret": "..." | null}`.
  - The create schema is `WebhookHMACSettings` only: `auth_type` is the constant `"hmac"`, and `name` and `webhook_url` are required.
  - The create schema has no `retry_enabled` or `events` field, even though [E6] says retries can be enabled "when creating or updating". Set both with PATCH.
- **Subscribe and enable retries:** `PATCH /v1/workspace/webhooks/{webhook_id}` with:
  ```json
  {"is_disabled": false, "name": "EchoLedger STT", "retry_enabled": true, "events": ["speech_to_text"]}
  ```
  - `is_disabled` and `name` are required.
  - `events` is one of `voice_library_removal_notice`, `speech_to_text`, `agent_qa`, `flows`. It is "the complete set" of subscriptions.
  - The response is `{"status":"ok"}`.
- **List:** `GET /v1/workspace/webhooks?include_usages=false` returns `{"webhooks":[…]}`. Each entry has `name`, `webhook_id`, `webhook_url`, `is_disabled`, `is_auto_disabled`, `created_at_unix`, `auth_type` (`hmac` \| `oauth2` \| `mtls`), and optional `usage[]`, `events[]`, `most_recent_failure_error_code`, `most_recent_failure_timestamp`.
- **Delete:** `DELETE /v1/workspace/webhooks/{webhook_id}` returns `{"status":"ok"}`. "Webhooks can be deleted if not in use by any products" [E6].
- **UNVERIFIED:** that `events:["speech_to_text"]` set via PATCH is exactly the dashboard's "Transcription completed" tick. The enum value and `WebhookUsageType` "Speech to Text" strongly suggest so [E1].

### 2.2 Delivery payloads and headers
**Success event** (from [E5]; the original uses JS-style keys):
```json
{ "type": "speech_to_text_transcription",
  "data": { "request_id": "some-request-id-123",
            "webhook_metadata": { "...": "..." },
            "transcription": { "language_code": "en", "language_probability": 0.98, "text": "Hello world!",
                               "words": [ { "text": "Hello", "start": 0.0, "end": 0.5, "type": "word", "speaker_id": "speaker_1" } ] } } }
```
- `transcription.edited_transcript` appears only when `transcript_edit` was sent [E5].

**Failure event** [E5]
- `type: "webhook_error"`. "It has no `data` object". It carries a top-level `request_id`, `error_message` and `webhook_metadata`.

**Generic top-level fields**
- The webhooks page lists `type`, `data` and `event_timestamp` [E6], and documents `event_timestamp` as a string while its example uses an integer.
- The STT example omits `event_timestamp`, so do not depend on it.

**Multichannel payloads**
- "Webhooks return the `separate` (per-channel) format. `multichannel_output_style=combined` is not currently supported with webhook delivery" [E4].
- The exact nesting `data.transcription.transcripts[]` is inferred from the response models. **Verify with a live contract test.**
- If the uploaded file was actually mono, expect the single-channel shape [E4].

**`transcription_id` inside the payload**
- **UNVERIFIED.** The field is optional in the models [E1] and absent from the example [E5].

**Headers**
- `elevenlabs-signature` [E5][E6].
- `ElevenLabs-Webhook-Delivery-Id`: "Every attempt for the same event carries the same `ElevenLabs-Webhook-Delivery-Id` header, so your handler can ignore duplicates." [E5]

### 2.3 Signature scheme, exactly as the official SDKs implement it [S1][S2]
- **Header value:** comma-separated parts, for example `t=1700000000,v0=9f2c…`. The parser takes the part starting `t=` as the timestamp (unix **seconds**) and the part starting `v0=` as the signature.
- **Signed message:** `f"{timestamp}.{rawBody}"`, the exact raw request body. The docs add: "The raw body is required: verification runs over the exact bytes sent." [E5]
- **MAC:** `"v0=" + hex(HMAC_SHA256(key=secret_utf8, msg=message_utf8))` with lowercase hex. Python uses `hmac.new(...).hexdigest()` and JS uses WebCrypto plus `padStart(2,'0')` hex.
- **Timestamp tolerance:** reject when `t*1000 < now_ms − 30*60*1000` (older than 30 min). The SDKs do **not** reject future timestamps.
- **Comparison:** the SDKs use `!=` / `!==`, which is not constant-time, so use `hash_equals` in PHP. Python keeps the *last* `v0=` part and JS the *first*, so we accept a match on any `v0=` part.
- **Enforcement:** "It is up to the client to implement the verification mechanism. ElevenLabs sends headers that allow for verification but we do not enforce it." [E5]
- **Secret:** "the shared secret generated upon creation of the webhook" [E6]. The API returns it as `webhook_secret` [E7].
- Worked sample, computed locally: `secret="whsec_test"`, `t=1700000000`, `body={"type":"x"}` gives `v0=29cc951fc439d64c216bbea1a7361669bacc196ec2ea452cfa008c48a61829ad`. Use this vector in a Pest test.

### 2.4 Delivery semantics, retries, auto-disable
- **Default:** "Speech to Text transcription webhooks are delivered once. Every non-2xx response counts as a failed delivery, and repeated failures auto-disable the webhook" [E5].
- **With `retry_enabled`:** failures on `5xx`, `408`, `429` or connection errors are "retried up to 5 more times, roughly 30 seconds, 2 minutes, 8 minutes, 30 minutes and 30 minutes after the previous attempt" [E5].
  - The generic table lists Immediate / 30 s / 2 min / 8 min / 30 min, with jitter up to 10% [E6].
  - Other 4xx responses are not retried [E5][E6].
  - Each webhook can hold at most 100 pending retries [E6].
- **Zero-retention requests:** "up to 2 quick retries, 5 seconds and 30 seconds after", and "The transcript is never stored" [E5].
- **Auto-disable:** triggers at "10 or more consecutive failures", **and** when the last success was more than 7 days ago or never happened. Admins get an email, and re-enabling is manual [E6].
- **Handler expectations:** "return HTTP 200 promptly". Retries carry an identical payload, so be idempotent [E6].

---

## 3. Fetch and delete a transcript; retention
**Fetch:** `GET /v1/speech-to-text/transcripts/{transcription_id}` (operationId `get_transcript_by_id`) [E1][E8]
- 200: `SpeechToTextChunkResponseModel` or `MultichannelSpeechToTextResponseModel`.
- 401: `unauthorized`.
- 404: `{"detail":{"status":"not_found_error","message":"Transcript not found."}}`.
- 422.

**Delete:** `DELETE /v1/speech-to-text/transcripts/{transcription_id}` (operationId `delete_transcript_by_id`) [E1][E8b]
- 200: "Delete completed successfully." with an empty schema `{}`.
- 401, 422.
- 404 is *not documented* for DELETE.

**Other notes**
- The spec has no list endpoint for STT transcripts. These are the only two paths under `/v1/speech-to-text/transcripts` [E1].
- Fallback: "If a delivery still fails, fetch the result with Get transcript using the `transcription_id` returned when the request was accepted. Transcripts are not stored for requests made with `enable_logging` set to `false`." [E5]

**Retention**
- Default: "Customers by default have history preservation enabled. All customers can use the API to delete generations at any time. This action will immediately remove the corresponding audio and text from our database; however, debugging and moderation logs may still retain data related to the generation." [E17]
- Backups: "database items are retained in backups for up to 30 days" after deletion [E17].
- The statement talks about generic "generations". Whether the transcript DELETE also purges the uploaded **source audio** for STT is **UNVERIFIED**.
- Zero Retention Mode (Enterprise only):
  - It is enabled per request with `enable_logging=false` [E17].
  - "All endpoints beginning with `/v1/speech-to-text/` are covered by ZRM" [E17].
  - "access to this feature may be restricted" [E17].

---

## 4. Realtime STT (Scribe v2 Realtime)

### 4.1 Single-use token
- `POST https://api.elevenlabs.io/v1/single-use-token/{token_type}` with header `xi-api-key` and **no request body** [E1][E9]. The SDK page shows a bare `fetch(…, {method:"POST", headers:{"xi-api-key": …}})` [E13].
- `token_type` is one of `realtime_scribe`, `batch_scribe`, `tts_websocket` [E1][E9].
- 200: `{"token":"sutkn_1234567890"}`, described as "A time bound single use token that expires after 15 minutes. Will be consumed on use." [E1][E9]
- The token is **not** bound to keyterms or other parameters, because the endpoint takes no body. Parameters travel in the WS query string, which the device controls [E1][E10].
- Not documented: whether an already-open session survives past the token's 15 minutes. One token opens one connection, so reconnects need a new token.

### 4.2 WebSocket URL and query parameters [E10] (plus limits from [E11] and [S5])
- **URL:** `wss://api.elevenlabs.io/v1/speech-to-text/realtime?…`.
  - The SDK builds `${baseUri}/v1/speech-to-text/realtime` with default base `wss://api.elevenlabs.io` and always appends `model_id` and `token` [S5].
  - Other hosts: `wss://api.us.elevenlabs.io/` and the `wss://api.{eu,in,sg}.residency.elevenlabs.io/` hosts [E10].
- **Auth:** "either by providing a valid API key in the `xi-api-key` header or by providing a valid token in the `token` query parameter" [E10]. Devices must use `token`.

| Query param | Type / allowed values | Default | Notes |
|---|---|---|---|
| `model_id` | `scribe_v2_realtime` | `scribe_v2_realtime` | |
| `token` | string | — | single-use |
| `audio_format` | `pcm_8000`, `pcm_16000`, `pcm_22050`, `pcm_24000`, `pcm_44100`, `pcm_48000`, `ulaw_8000` | `pcm_16000` | PCM formats are "16-bit PCM, little-endian"; 16 kHz is "recommended" [E11] |
| `language_code` | ISO 639-1 or 639-3 | auto | |
| `secondary_languages` | repeated | — | |
| `commit_strategy` | `manual` \| `vad` \| `turn_prediction` | manual [E11][S5] | `turn_prediction` is "only for models that predict turns" [E10]. `session_started.config` enumerates only `manual` and `vad` |
| `vad_silence_threshold_secs` | double | — | API accepts 0.192–3.0; JS/React SDKs accept 0.3–3.0 [E11] |
| `vad_threshold` | double | — | SDK validates 0.1–0.9 [S5]. With `filter_background_audio` it defaults to 0.15 [E11] |
| `min_speech_duration_ms`, `min_silence_duration_ms` | int | — | SDK validates 50–2000 [S5] |
| `include_timestamps` | bool | `false` | enables `committed_transcript_with_timestamps` |
| `include_language_detection` | bool | `false` | |
| `keyterms` | repeated query param | — | ≤50 terms of ≤20 chars [E14][E3][S5]. Example: `…&keyterms=ElevenLabs&keyterms=AnotherTerm` [E14] |
| `no_verbatim` | bool | `false` | |
| `entity_detection` | string or repeated | — | results arrive as `committed_transcript_entities` |
| `transcript_edit` | string | — | not for v1 |
| `filter_background_audio` | bool | `false` | "can't be combined with `include_timestamps`" or the server sends `invalid_request` and closes [E11] |
| `keepalive_interval_ms` | int 500–10000 | disabled | server sends a keepalive `partial_transcript` [E11] |
| `enable_logging` | bool | `true` | `false` means ZRM (Enterprise) [E10] |

### 4.3 Client to server
Only one message type is sent: `input_audio_chunk` [E10][E12].
```json
{"message_type":"input_audio_chunk","audio_base_64":"<base64 PCM>","commit":false,"sample_rate":16000}
```
- **Required fields:** `message_type`, `audio_base_64`, `commit`, `sample_rate` [E10].
- **Optional `previous_text`:** "Can only be sent alongside the first audio chunk. If sent in a subsequent chunk, an error will be returned" [E10]. It "works best when it's under *50* characters" [E11].
- **Manual commit:** the SDK sends `{"message_type":"input_audio_chunk","audio_base_64":"","commit":true,"sample_rate":<rate>}` [S5].

### 4.4 Server to client [E10][E12]

**Session and transcript events**

| Event | Fields | Notes |
|---|---|---|
| `session_started` | `session_id`, `config` | `config` echoes `sample_rate`, `audio_format`, `language_code`, `commit_strategy`, the VAD params, `model_id`, `enable_logging`, `include_timestamps`, `include_language_detection`, `keyterms`, `no_verbatim`, and also `keepalive_interval_ms` [E11] |
| `partial_transcript` | `text` | "Later partials replace earlier ones"; also used as the keepalive |
| `committed_transcript` | `text` | "stable, final text for the segment". There is no separate `final_transcript` event |
| `committed_transcript_with_timestamps` | `text`, `language_code`, `words[]` | each word has `text`, `start`, `end`, `type` (`word` \| `spacing`), `speaker_id`, `logprob`, `characters` |
| `committed_transcript_entities` | entity results | only when `entity_detection` is set |
| `edited_transcript` | edited text | only when `transcript_edit` is set |
| `warning` | `warning` | |

**Error events**
- Every error event has the shape `{message_type, error}`.
- Types: `error`, `auth_error`, `quota_exceeded`, `commit_throttled`, `unaccepted_terms`, `rate_limited`, `queue_overflow`, `resource_exhausted`, `session_time_limit_exceeded`, `input_error`, `invalid_request`, `chunk_size_exceeded`, `insufficient_audio_activity`, `transcriber_error`.
- "an error message will be returned before the WebSocket connection is closed" [E12].

### 4.5 Audio and session constraints
- "Only mono audio is supported at this time" [E11]. Two channels need two sessions.
- **Audio flow:**
  - Send chunks of 0.1–1 s [E11]. At pcm_16000 mono, 100 ms is 3,200 bytes before base64.
  - "Transcript processing starts after the first 2 seconds of audio are sent." [E11]
- **Commits:**
  - In manual mode the model "automatically commits after approximately 36 seconds of accumulated audio" [E11].
  - Committing every 20–30 s is good practice; rapid repeated commits "can degrade model performance" [E11].
  - VAD is "recommended" for client-side microphone input [E11].
- **Keeping the connection open:**
  - "the server closes the connection after 15 seconds without any client messages. This server limit is not configurable." [E11]
  - Keep streaming, even silence frames. Keepalives are not a free-running ping [E11].
- **Max session duration:** not documented. **UNVERIFIED.** The `session_time_limit_exceeded` error exists [E12].
- **Realtime STT concurrency per plan:** Free 6, Starter 9, Creator 15, Pro 30, Scale 45, Business 45 [E15].

---

## 5. Pricing and quotas

### 5.1 Price table (read from the raw HTML of [E16] on 2026-10-24)

| Model | Price / audio hour | Keyterm prompting / h | Transcript editing / h | Hours included, by row |
|---|---|---|---|---|
| Scribe v2 and Scribe v2 Medical | **$0.22** | $0.050 | $0.070 | 4 h 30 m, 27, 100, 450, 1359, 4500 |
| Scribe v2 Realtime | **$0.39** | $0.080 | $0.120 | 2 h 30 m, 15, 56, 254, 767, 2538 |

- The per-hour rate is identical in all six rows. The rows are not labelled in the table.
- The hours line up with the listed plan fees: $6 Starter, $22 Creator, $99 Pro, $299 Scale, $990 Business, plus a free row. **Inference.**
- **Keyterm surcharge:**
  - The API spec says "additional 20% surcharge" [E1], and the page tooltip says "adds a 20% premium over the base transcription price" [E16].
  - The table shows $0.050/h, which is about 22.7% of $0.22. Budget with the table value.
- **Multichannel:** "Each channel is billed independently at the full audio duration, so cost scales linearly with the number of channels." [E1]
- **Silence counts:** "Both batch and realtime transcription are metered on every second of audio you send, including pauses" [E3]. Background filtering does not reduce billing [E11].
- **Minimums:** more than 100 keyterms means a minimum of 20 s billed per request [E1]. `transcript_edit` has a 10 s minimum [E1].

### 5.2 Worked example: one-hour two-channel call
These are computed from the verified unit prices. Applying the keyterm add-on per channel is an **inference**.

| Lane | Calculation | Cost |
|---|---|---|
| Final transcript (batch) | 2 ch × $0.22 + 2 × $0.05 keyterms | **≈ $0.54** |
| Live captions, both channels | 2 sessions × ($0.39 + $0.08) | **≈ $0.94** |
| Live captions, Client channel only | 1 session × ($0.39 + $0.08) | ≈ $0.47 |

So one call costs about $1.48 with both lanes, or about $1.01 with Client-only captions.

### 5.3 BUILD_PLAN hypotheses checked

| BUILD_PLAN claim | Result |
|---|---|
| "ElevenLabs Scribe v2 … $3.67 /1,000 min" | **Confirmed**: $0.22/h is $3.67 per 1,000 min [E16] |
| "multichannel (≤5 ch)" | **Confirmed** [E1][E4] |
| "keyterms, webhooks, single-use tokens" | **Confirmed** [E1][E5][E9] |
| "deletion via API" | **Confirmed** [E8b] |
| "AA-WER 2.2%" | Not checked; out of scope and not a primary-source API fact |

---

## 6. Optional future drivers: OpenAI and Groq (short)

### 6.1 OpenAI `/v1/audio/transcriptions`
- **Request:** `POST https://api.openai.com/v1/audio/transcriptions`, multipart, `Authorization: Bearer $OPENAI_API_KEY` [O1].
- **Models:**
  - Recommended: `gpt-transcribe` [O1][O3], $0.0045/min ≈ $0.27/h [O3].
  - Realtime: `gpt-live-transcribe` via `v1/realtime/transcription_sessions`, $0.017/min [O3b]. Its client-secret flow was not researched, so treat it as **UNVERIFIED**.
  - `whisper-1`, `gpt-4o-transcribe`, `gpt-4o-mini-transcribe`, `gpt-4o-transcribe-diarize` and `/v1/audio/translations` "are deprecated and will shut down on **February 26, 2027**" [O1][O4].
- **Limits:** "Files can be up to 25 MB". Formats are `mp3`, `mp4`, `mpeg`, `mpga`, `m4a`, `wav`, `webm` only; **no flac or ogg** [O1].
- **`gpt-transcribe` parameters** [O1]:
  - `file`, `model`, `prompt`.
  - `keywords[]` (multipart `-F 'keywords[]=…'`) and `languages[]`. `languages` "replaces the singular `language` field. Don't send both". Keywords must not contain `<`, `>`, CR or LF, or the API rejects the entire request.
  - `stream=true` emits `transcript.text.delta` and then `transcript.text.done` [O1].
  - The response is `{"text": "...", "languages": [{"code": "fr"}]}` plus `usage` (token or duration usage) [O1][O2].
- **Timestamps:** "The `timestamp_granularities[]` parameter is only supported for `whisper-1`" (`word` / `segment`, which needs `response_format=verbose_json`) [O1][O2]. So **no word timestamps on the recommended model**, and the synced-transcript view cannot use it.
- **Other options:**
  - Response formats are `json`, `diarized_json`, `verbose_json`, `text`, `srt` and `vtt`; "Supported formats depend on the model" [O2].
  - Diarization uses the deprecated `gpt-4o-transcribe-diarize` with `diarized_json`. It needs `chunking_strategy` for audio over 30 s, and `known_speaker_names[]` / `known_speaker_references[]` take up to 4 data-URL clips of 2–10 s [O1].
- **Data:** `/v1/audio/transcriptions` shows training "No", abuse-monitoring retention "None", application state "None", ZDR eligible "Yes" [O5].
- **No webhook or async job** is documented in [O1]. Requests are synchronous or streamed, with no multichannel option, so you would split channels into two mono files, which doubles the cost.

### 6.2 Groq (OpenAI-compatible)
- **Endpoint:** `POST https://api.groq.com/openai/v1/audio/transcriptions` [G1].
- **Auth:** the page I opened shows the header only via SDKs, so it is **UNVERIFIED**. It is presumably `Authorization: Bearer` as with OpenAI.
- **Models** [G1][G2]:

  | Model | Price | Translation | WER | Speed factor |
  |---|---|---|---|---|
  | `whisper-large-v3-turbo` | $0.04/h | no | 12% | 216 |
  | `whisper-large-v3` | $0.111/h | yes | 10.3% | — |

- **Limits** [G1][G2]:
  - Max file is 25 MB on the free tier and 100MB on the dev tier. The attachment cap is 25 MB; for larger files "use the `url` parameter".
  - Minimum file length is 0.01 s; the minimum billed length is **10 s**.
  - Formats: flac, mp3, mp4, mpeg, mpga, m4a, ogg, wav, webm.
- **Channels:** "Only the first track will be transcribed for files with multiple audio tracks" and the models "downsample audio to 16KHz mono" [G1]. Split channels first.
- **Parameters** [G1]: `file` \| `url`, `model`, `language` (ISO-639-1), `prompt` (≤224 tokens; there is no keyterms feature), `response_format` (`json` \| `verbose_json` \| `text`), `temperature` (0–1, default 0), and `timestamp_granularities[]` (`segment` \| `word`, requires `verbose_json`).
- **Rate limits:** the page lists 20 RPM, 2K RPD, ASH 7.2K and ASD 28.8K audio-seconds for both Whisper models, described as "the base limits for the Developer plan" [G4].
- **Data:** "By default, Groq does not retain customer data for inference requests". Reliability and abuse logs are kept up to 30 days. Any customer can enable ZDR in Data Controls, and data is stored in GCP US [G3].
- **Fit:** a cheap, word-timestamped offline-ish fallback. It has no keyterms, no webhooks and no channel separation.

---

## Recommendations for EchoLedger

**R1. Driver contract.**
- Implement `ElevenLabsDriver` behind `SttProvider` with provider-neutral DTOs. Suggested methods:
  - `submitFinal(Recording, list<string> $keyterms): ProviderJobRef`
  - `verifyWebhook(Request): array`
  - `parseWebhook(array $event): ParsedWebhook`
  - `fetchFinal(string $transcriptionId): TranscriptData`
  - `deleteRemote(string $transcriptionId): void`
  - `mintStreaming(Device, ?Client, list<string> $channels): list<StreamingCredential>`
- Mirror the sibling AssemblyAI note: store the provider id as `provider_job_id`, and add `provider_transcript_id`.

**R2. Credentials and settings.** All are encrypted with `Crypt` and live on the Filament API Credentials page.
- `elevenlabs.api_key`
- `elevenlabs.webhook_secret`
- `elevenlabs.webhook_id`
- `elevenlabs.base_url`, a select defaulting to `https://api.elevenlabs.io`, with the residency hosts [E2]. Derive `wss://` from it.
- `elevenlabs.model_id`, either `scribe_v2` or `scribe_v2_medical`.
- `elevenlabs.zero_retention`, default off; label it "Enterprise only" [E17].

Use a **service-account API key** with these restrictions [E18]:
- endpoint scope limited to STT and single-use tokens ("limit which API endpoints the key can access"; I did not see the exact scope names)
- **IP allowlist** set to the server's public egress IP ("1 and 100 entries"; "Private IP ranges … are not accepted"; other IPs get `403`)
- a credit limit

**R3. Submitting the two-channel file.**
- **Channel contract:** mic/"Me" is channel 0 (left) and system/"Client" is channel 1 (right). This matches the AssemblyAI note. Channel 0 is the first channel in the file, which is left in standard stereo order. ElevenLabs labels it `speaker_0` [E4].
- **Formats:** FLAC 16 kHz stereo on desktop, which is lossless and smaller than WAV [E4][E3]. Ogg/Opus stereo is accepted from mobile [E3].
- **File length:** keep each file at 1 h or less until the duration conflict in §1.4 is resolved. Have devices roll segments at 55 min, so the server needs no ffmpeg on shared hosting.
- **No HTTP-level retry on submit.** A timed-out response may still have created a billed job. Make the job idempotent: skip if `provider_job_id` is already set.
- Sketch, using the installed `baseUrl`, `withHeaders`, `acceptJson`, `connectTimeout`, `timeout`, `asMultipart`, `post` and `throw` [L1]:

```php
$parts = [
    ['name' => 'model_id', 'contents' => $cfg->modelId],            // 'scribe_v2'
    ['name' => 'use_multi_channel', 'contents' => 'true'],          // strings, never PHP bools [L2]
    ['name' => 'diarize', 'contents' => 'false'],                   // required false with multichannel [E4]
    ['name' => 'timestamps_granularity', 'contents' => 'word'],
    ['name' => 'tag_audio_events', 'contents' => 'false'],          // default is true [E1]
    ['name' => 'webhook', 'contents' => 'true'],
    ['name' => 'webhook_id', 'contents' => $cfg->webhookId],
    ['name' => 'webhook_metadata', 'contents' => json_encode(
        ['recording_id' => (string) $rec->id, 'session_id' => (string) $rec->session_id],
        JSON_THROW_ON_ERROR)],                                      // depth <= 2, <= 16KB [E1]
];
foreach (ElevenLabsKeyterms::forBatch($keyterms) as $term) {
    $parts[] = ['name' => 'keyterms', 'contents' => $term];         // repeated field, NOT keyterms[] [S3][S4][L2]
}
$parts[] = ['name' => 'file', 'contents' => $stream /* decrypted temp stream */,
            'filename' => 'recording.flac', 'headers' => ['Content-Type' => 'audio/flac']];

$res = Http::baseUrl($cfg->baseUrl)
    ->withHeaders(['xi-api-key' => $cfg->apiKey])
    ->acceptJson()->connectTimeout(15)->timeout(900)
    ->asMultipart()
    ->post('/v1/speech-to-text'.($cfg->zeroRetention ? '?enable_logging=false' : ''), $parts)
    ->throw();                                        // 200 or 202 are both success [E1]

$rec->update([
    'provider_job_id'        => (string) $res->json('request_id'),
    'provider_transcript_id' => $res->json('transcription_id'),   // nullable [E1]
    'status'                 => 'submitted',
]);
```

**R4. Keyterm sanitising, used both in Filament validation and at submit time.**
- Batch rules: trim, collapse whitespace, case-insensitive dedupe, drop any term containing one of `< > { } [ ] \`. Require `mb_strlen < 50` and at most 5 space-separated words, then cap at 1000 terms [E1].
- Realtime rules: the same cleaning, plus `mb_strlen <= 20` and at most 50 terms [E14][S5].
- Prioritise the client's own terms before global ones.
- Above 100 terms there is a 20 s minimum per request, which is irrelevant for calls [E1].
- The "after normalisation" word rule is ElevenLabs-side, so counting space-separated words is an approximation. **UNVERIFIED.**

**R5. Webhook verification, written to match [S1][S2] and to be stricter than them.**
```php
final class ElevenLabsSignature
{
    /** @return array<string, mixed> */
    public static function verify(string $rawBody, ?string $header, string $secret, int $now, int $tolerance = 1800): array
    {
        if ($header === null || $header === '' || $secret === '') {
            throw new InvalidWebhookSignature('missing header or secret');
        }
        $t = null; $sigs = [];
        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if (str_starts_with($part, 't=')) { $t = substr($part, 2); }
            elseif (str_starts_with($part, 'v0=')) { $sigs[] = $part; }
        }
        if ($t === null || ! ctype_digit($t) || $sigs === []) {
            throw new InvalidWebhookSignature('malformed header');          // SDK: "expected scheme v0"
        }
        if ((int) $t < $now - $tolerance) { throw new InvalidWebhookSignature('stale'); }       // SDK: 30 min
        if ((int) $t > $now + 300)       { throw new InvalidWebhookSignature('future'); }      // stricter than SDK
        $expected = 'v0='.hash_hmac('sha256', $t.'.'.$rawBody, $secret);    // lowercase hex like hexdigest()
        foreach ($sigs as $sig) {
            if (hash_equals($expected, $sig)) {
                return json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
            }
        }
        throw new InvalidWebhookSignature('mismatch');
    }
}
```
Controller flow for `POST /api/webhooks/stt/elevenlabs` (public route, no Sanctum):
1. Verify with `ElevenLabsSignature::verify($request->getContent(), $request->header('elevenlabs-signature'), $secret, time())`. Use the raw body, never a re-encoded body. On failure return 401. 4xx responses are not retried, which is fine [E6].
2. Insert into `webhook_events` with a unique `(provider, idempotency_key)`, using `createOrFirst` [L4]. The key is `elevenlabs:` plus the `ElevenLabs-Webhook-Delivery-Id` header [E5]. Fall back to `elevenlabs:{request_id}:{type}`.
3. Return `200 {"received":true}` immediately [E6], and queue `ProcessSttWebhook` for the rest.
4. In the job, match `data.request_id` (or the top-level `request_id` for `webhook_error`) to `provider_job_id`, and cross-check `webhook_metadata.recording_id`. The metadata is covered by the HMAC. It may arrive as an object or a string, so decode either.
   - If `type === 'webhook_error'`, mark the recording failed with `error_message`.
   - Otherwise accept either shape. Multichannel arrives as `data.transcription.transcripts[]`; a mono fallback arrives as `data.transcription.words[]`, so flag it as "single channel: labels unavailable".
   - Map `channel_index` 0 to "Me" and 1 to "Client".
5. Build the synced view per channel:
   - Keep each channel's `words[]` order intact, including `spacing` tokens; this also covers languages written without spaces [E3].
   - Split each channel into utterances on gaps of about 1 s or more, then interleave utterances by `start`.
   - Store the raw words JSON in `transcripts.words`.

**R6. Fetch, delete, and reconcile.**
- After the DB transaction commits, dispatch `DeleteProviderTranscriptJob`.
  - Use `data.transcription.transcription_id` if present, otherwise the stored `provider_transcript_id`.
  - Call `DELETE /v1/speech-to-text/transcripts/{id}` [E8b]. Treat 2xx, and 404, as done. A 404 on DELETE is undocumented, so treating it as done is a defensive choice.
  - Back off on 429/5xx, then set `provider_deleted_at`.
  - If no transcription id is known anywhere, record "provider copy not deleted" and surface it in Filament.
- Because STT webhooks are delivered once by default [E5], enable `retry_enabled` [E7b]. Also schedule a **reconciler** every 5 minutes that, for recordings `submitted` longer than `max(15 min, 2 × ((D·0.3)+2+(N·0.5)) s)` [E4], calls `GET /v1/speech-to-text/transcripts/{id}`. A 200 is processed exactly like the webhook; a 404 means keep waiting until a 2 h timeout, then mark the recording failed [E1][E8].
- Show webhook health on the dashboard using `GET /v1/workspace/webhooks`: `is_auto_disabled` and `most_recent_failure_error_code` [E1].

**R7. Minting realtime credentials (`POST /api/stt/token`).**
- Return **one token per channel**: `channels: ["me","client"]` gives two tokens, because tokens are single-use [E9] and audio is mono only [E11].
- Build the full `ws_url` on the server, so devices need no ElevenLabs-specific parameter logic. Build the query by hand, because `http_build_query` emits `keyterms%5B0%5D=` (checked locally with PHP 8.5.10).
- Do not log tokens or URLs. Throttle the endpoint per device. Never return the API key.
- The token call is a body-less POST: `send('POST', $url)` leaves `json` as null, so Guzzle sends no body [L1 lines 1161–1177][L3 line 1569]. `post($url)` with no data would send `[]`, which is probably harmless but unnecessary.

```php
$token = Http::baseUrl($cfg->baseUrl)->withHeaders(['xi-api-key' => $cfg->apiKey])
    ->acceptJson()->timeout(10)
    ->send('POST', '/v1/single-use-token/realtime_scribe')->throw()->json('token');   // [E9]

$q = ['model_id=scribe_v2_realtime', 'token='.rawurlencode($token),
      'audio_format=pcm_16000', 'commit_strategy=vad'];                               // [E10][E11]
if ($lang !== null) { $q[] = 'language_code='.rawurlencode($lang); }
foreach (ElevenLabsKeyterms::forRealtime($keyterms) as $t) { $q[] = 'keyterms='.rawurlencode($t); }  // [E14]
$wsUrl = $cfg->wsBase.'/v1/speech-to-text/realtime?'.implode('&', $q);                 // wss://api.elevenlabs.io
// Response item: {channel, ws_url, expires_at: now()+14min, audio:{encoding:"pcm_s16le",sample_rate:16000,channels:1}}
```

**R8. Device protocol for desktop (Tauri) and Android, same for both.**
- Connect immediately after minting, since the token expires in 15 min [E9].
- Capture and send:
  - Resample each channel to 16 kHz mono s16le.
  - Send `input_audio_chunk` frames of 100–250 ms with `commit:false` and `sample_rate:16000` [E10][E11].
  - Keep sending silence frames during pauses to avoid the 15 s idle close [E11].
- Render captions:
  - Replace the caption on each `partial_transcript`.
  - Append on each `committed_transcript` [E12].
  - Optionally enable `include_timestamps=true` to save a timed live transcript as a fallback. It cannot be combined with `filter_background_audio` [E11].
- **On reconnect** (network failure, `session_time_limit_exceeded`, `resource_exhausted`):
  - Fetch a new token.
  - Send `previous_text` (under 50 chars of the last committed text) with the first chunk only [E10][E11].
- In QA, assert that `session_started.config.keyterms` echoes what we sent [E10].
- The WebSocket goes straight to api.elevenlabs.io. Do **not** cert-pin it; per the product requirements, pinning is for the Laravel host only.

**R9. Pest tests** (with `Http::fake()` and `Http::preventStrayRequests()`, both installed [L5]).
- **Multipart parts:** assert exactly N parts named `keyterms`, with no `keyterms[0]`. In tests, `collect($request->data())->where('name','keyterms')` works because `Request::data()` returns the multipart parts.
- **Booleans:** assert they are the strings `'true'` / `'false'`.
- **Signature:** cover the valid vector from §2.3, a tampered body, a stale timestamp (31 min), a future timestamp, a missing header, and multiple `v0=` parts.
- **Idempotency:** the same Delivery-Id produces one job.
- **Payload shapes:** `webhook_error` handling, and the mono-fallback shape.
- **Token endpoint:** it never echoes `xi-api-key`; it builds repeated `keyterms=`; it returns one token per channel.
- **Delete:** 404 counts as success.

**R10. Default parameter policy.** These follow the "accuracy first, transcription only" rule.
- `no_verbatim=false`
- `tag_audio_events=false`
- `timestamps_granularity=word`
- `language_code` left null for per-channel auto-detect, or the client's configured language
- no `transcript_edit` and no `entity_*`, which avoids AI-ish rewriting and extra cost
- `scribe_v2_medical` only when an admin opts in per client

---

## Gotchas
1. **Repeated `keyterms`:** Laravel + psr7 3.2.0 turn an array into `keyterms[0]`… [L2], and `http_build_query` gives `keyterms%5B0%5D`. Hand-build the parts and the query pairs.
2. **PHP `false` in multipart becomes `""`** through psr7's `(string)` cast [L2]. Always send `'true'` / `'false'` strings.
3. **Multichannel requires** `diarize=false` and no `num_speakers` [E4]. `detect_speaker_roles` and `transcript_edit` are not allowed with multichannel either [E1].
4. **`combined` output does not work with webhooks** [E4]. Expect the per-channel `separate` shape and merge it yourself.
5. **Mono uploads silently return the single-channel shape** [E4]. Detect and flag them; do not assume `transcripts[]` exists.
6. **Duration and size limits conflict in the docs:** 1 h vs a 10 h combined multichannel limit, and 3 GB vs 5 GB [E3][E1]. Segment at 1 h or less.
7. **STT webhooks are delivered once unless `retry_enabled`** [E5]. Shared-hosting downtime then loses the event, so the reconciler is mandatory.
8. **Auto-disable** follows 10 consecutive failures with no success in 7 days [E6]. A wrong secret (our 401s) can kill the webhook, so monitor `is_auto_disabled`.
9. **Async acceptance** may be 200 or 202, and `transcription_id` is optional [E1]. Never hard-require it, and keep `request_id`.
10. **Realtime is mono-only and tokens are single-use:** two channels mean two tokens, and two sessions double the cost [E11][E9][E16].
11. **The realtime server closes after 15 s without client frames** [E11]. Stream silence, and do not pause the socket when the mic is quiet.
12. **Realtime keyterm limits are much smaller** (50 × 20 chars) than batch (1000 × 49 chars) [E14]. Reuse the batch list only after trimming it.
13. **`filter_background_audio=true` plus `include_timestamps=true`** gives `invalid_request` and the connection closes [E11].
14. **`tag_audio_events` defaults to true** [E1]. If left on, `text` contains `(laughter)`-style tokens.
15. **`language_code` may be ISO-639-3** (`eng`) per the field description, while examples show `en` [E1]. Normalise before display.
16. **`enable_logging=false` is Enterprise-only** [E1][E17]. Its transcripts are never stored, so the GET fallback and reconciler cannot work. In that mode the webhook is the only copy.
17. **Webhook payload size:** a long two-channel call with per-word JSON (including `spacing` entries) can be several MB. This is my estimate, about 2 MB per channel-hour. Check `post_max_size` and the web server body limits for `/api/webhooks/stt/*`.
18. **Docs inconsistencies** to ignore: `task_id` in one sample [E4] (the schema has `request_id` [E1]), and the keyterm price shown as 20% in the docs vs $0.05/h in the table [E1][E16].
19. **Residency hosts need a different API key and account** [E18b]. Keep the REST base and the `wss://` base from the same environment.

## Load-bearing claims

| # | Claim | Source | Confidence |
|---|---|---|---|
| 1 | Batch is `POST /v1/speech-to-text` (multipart, `xi-api-key`) with fields `model_id=scribe_v2`, `use_multi_channel`, `keyterms`, `webhook`, `webhook_id`, `webhook_metadata`; multichannel supports at most 5 channels and bills each channel at full duration | E1 (`Body_Speech_to_Text_v1_speech_to_text_post`), E4 | high |
| 2 | Multichannel needs `diarize=false` and no `num_speakers`; channel N maps to `speaker_N`; each word has `channel_index`; webhooks deliver the `separate` format | E4, E1 | high |
| 3 | `keyterms` limits: ≤1000 terms, each <50 chars, ≤5 words, forbidden `< > { } [ ] \`, +20% (table $0.05/h); realtime ≤50 × ≤20 chars | E1, E14, E16, S5 | high |
| 4 | Arrays must be sent as repeated same-name multipart fields (`keyterms`), while Guzzle psr7 3.2.0 would emit `keyterms[0]…` | S3, S4, E19 (2026-08-24), L2 | high |
| 5 | Signature header `elevenlabs-signature: t=…,v0=…`; `v0=hex(HMAC_SHA256(secret, t + "." + rawBody))`; 30-min past tolerance | S1, S2, E5, E6 | high |
| 6 | STT webhooks are delivered once by default; `retry_enabled` (PATCH) retries 5xx/408/429 up to 5 times; `ElevenLabs-Webhook-Delivery-Id` is stable per event | E5, E7b, E6 | high |
| 7 | Payloads: `type=speech_to_text_transcription` with `data.{request_id, webhook_metadata, transcription}`, or `type=webhook_error` with top-level `request_id`, `error_message`, `webhook_metadata` | E5 | high (single-channel); medium (exact multichannel nesting) |
| 8 | `GET` / `DELETE /v1/speech-to-text/transcripts/{transcription_id}`; deletion is immediate but backups are kept up to 30 days | E1, E8, E8b, E17 | high (endpoints); medium (whether source audio is purged) |
| 9 | `POST /v1/single-use-token/realtime_scribe` returns `{token}`, single-use, 15-min expiry, no body | E1, E9, E13 | high |
| 10 | Realtime WS is `wss://api.elevenlabs.io/v1/speech-to-text/realtime?model_id=scribe_v2_realtime&token=…`; `input_audio_chunk {audio_base_64, commit, sample_rate}`; mono only; idle close after 15 s | E10, E11, S5 | high |
| 11 | Prices: Scribe v2 $0.22/h, Realtime $0.39/h, the same on every listed plan | E16 | high (as of 2026-10-24; prices change) |
| 12 | OpenAI `gpt-transcribe` has no word timestamps (`timestamp_granularities[]` is `whisper-1` only); legacy models shut down 2027-02-26 | O1, O4 | high |

## Open questions
1. Which multichannel duration limit is enforced: the 1 h in "Key facts", or "combined duration of all channels must be less than 10 hours" [E3]? Run a live test with a 70-minute stereo FLAC.
2. Is `transcription_id` returned in the async 200/202 acceptance body and/or in `data.transcription` of the webhook? Without it we cannot DELETE or GET-reconcile. Can `request_id` stand in? Use a live contract test and save the fixtures under `web/tests/Fixtures/elevenlabs/`.
3. What exactly does the multichannel webhook look like? Confirm the nesting `data.transcription.transcripts[]`, and whether `channel_index` is set on both the transcript and each word.
4. Does `DELETE /v1/speech-to-text/transcripts/{id}` also purge the uploaded source audio? What does DELETE return for an unknown or already-deleted id?
5. Is `PATCH … {"events":["speech_to_text"]}` equivalent to the dashboard's "Transcription completed" event? Does a webhook created via the API (HMAC) show up as usable for STT?
6. What is the maximum realtime session duration (the limit behind `session_time_limit_exceeded`), and does an open session survive past the 15-minute token TTL?
7. Is the keyterm surcharge applied per channel in multichannel mode, and is it a flat $0.05/h or 20% of base?
8. What are the exact API-key scope names that cover STT plus single-use tokens, so we can apply least privilege [E18]?
9. What does a non-Enterprise request with `enable_logging=false` return: an error or silently ignored? Keep the setting off by default until we know.
