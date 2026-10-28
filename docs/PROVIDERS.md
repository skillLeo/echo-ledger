# Speech-to-text providers

EchoLedger talks to speech providers only from the server. Devices never receive a provider API key. For live
captions they receive a short-lived, single-purpose streaming token, and final transcripts are always produced
server-side (or, for the on-device engine, uploaded by the device).

## Choosing providers

In the console, **Settings** assigns a driver to each use case: final transcripts and voice notes on the **Recording** tab,
live captions on **Captions**, dictation on **Dictation**. Each provider's own options (model, region, endpoint) are in
collapsible sections of the **Recording** tab.

| Use case | Setting | Used for |
|---|---|---|
| Live captions | `provider.live_captions` | Desktop overlay and Android caption bubble during calls the user started or the auto-record rules approved |
| Dictation | `provider.dictation` | Desktop dictation and the Android voice keyboard (microphone only; never saved as a recording) |
| Final transcript | `provider.final_transcript` | The accurate second pass over every call or meeting the user **kept**. A discarded recording is never sent. |
| Voice notes | `provider.voice_note` | Imported or uploaded voice notes (mono) |

Changes apply to the next token request or job; nothing in flight is interrupted. When the final provider needs
separate channel files (Azure MAI, whisper.cpp), `GET /api/config` tells devices to upload `channel_1` and
`channel_2` in addition to the stereo recording.

**Captions channels** (`captions.channels`): `both` (Me + Client), `client_only` (halves the live-caption cost) or
`off`.

## Credentials

Secrets are entered on **Admin → API Credentials** (super admin only). They are encrypted at rest, never shown
again after saving (only "configured ••••last4 · updated …"), and never sent to devices.

| Provider | Credential keys | Where to get them |
|---|---|---|
| AssemblyAI | `api_key`, `webhook_secret` (use **Generate**) | AssemblyAI dashboard → API keys |
| ElevenLabs | `api_key`, `webhook_secret`, `webhook_id` | ElevenLabs → Developers → API keys; Webhooks → create an HMAC webhook pointing to `https://<server>/api/webhooks/stt/elevenlabs` |
| Azure Speech | `api_key` (Speech resource key) | Azure portal → Speech resource → Keys and endpoint |
| whisper.cpp | `bearer_token` | The token your reverse proxy expects in front of `whisper-server` |

The webhook URL for AssemblyAI is set per job by the server (`https://<server>/api/webhooks/stt/assemblyai`); no
dashboard configuration is needed. `APP_URL` must be the public HTTPS address of the server.

## Providers

### AssemblyAI (default)

| | |
|---|---|
| Live captions | Universal streaming v3 over WebSocket, one session per channel. Default model `universal-3-6-pro` (keyterms included); budget option `universal-streaming-english` (`assemblyai.streaming_model`). |
| Final transcript | Async `multichannel: true`, `language_detection: true`, `speech_models: ["universal-3-5-pro", "universal-2"]` (`assemblyai.async_models`), `keyterms_prompt`. |
| Completion | Webhook with a per-transcript HMAC token header; `stt:reconcile` polls if a webhook is missed. |
| Keyterms | Final: up to 100 terms (≤ 50 characters, ≤ 6 words each). Live: up to 100 terms (≤ 50 characters), sent right after the session starts. |
| Region | `assemblyai.region`: `us` or `eu`. The EU endpoint is excluded from model training by AssemblyAI's policy. |
| Provider-side data | The server deletes each transcript after storing it (`DELETE /v2/transcript/{id}`), which also deletes the uploaded audio immediately. Without deletion: uploads expire after 24–48 h, transcripts after 30 days. Streaming has zero data retention when you opt out of model training (paid plans). |

### ElevenLabs Scribe

| | |
|---|---|
| Live captions | `scribe_v2_realtime` with a single-use token per channel (`elevenlabs.realtime_model_id`). |
| Final transcript | `scribe_v2` batch (`elevenlabs.model_id`) with `use_multi_channel=true`, `diarize=false`, keyterms, and webhook delivery (`webhook_id` + metadata). |
| Completion | Webhook verified with `elevenlabs-signature: t=…,v0=…` (HMAC-SHA256, 30-minute tolerance); `stt:reconcile` as fallback (ElevenLabs delivers STT webhooks once). |
| Keyterms | Final: up to 1,000 terms (< 50 characters, ≤ 5 words). Live: up to 50 terms (≤ 20 characters). |
| Provider-side data | The server deletes each transcript after storing it. ElevenLabs keeps deleted items in backups for up to 30 days; zero-retention mode is Enterprise-only. |

### Azure Speech — MAI-Transcribe

| | |
|---|---|
| Final transcript | Fast transcription, synchronous, one request per channel (WAV/FLAC, word timestamps, profanity filter off, keyterms as a phrase list). With `azure.model = standard`, a single stereo request with `channels: [0, 1]` is used instead. |
| Settings | `azure.endpoint` (resource endpoint for fast transcription), `azure.region` (for live tokens), `azure.model` (`MAI-Transcribe-2` or `standard`). |
| Live captions | The server can mint 10-minute Azure STS tokens, but the desktop and Android clients stream only to AssemblyAI and ElevenLabs today. Use one of those for live captions and dictation. |
| Provider-side data | Microsoft does not retain fast-transcription data, so there is nothing to delete remotely. |

### Soniox (optional)

| | |
|---|---|
| Final transcript | Async API (`stt-async-v5`, `soniox.async_model`). One job per channel when the device uploaded separate Me and Client files, otherwise one stereo job with speaker diarization ("Speaker 1", "Speaker 2"). The language goes in `language_hints` (non-English first) with `language_hints_strict`; Automatic uses language identification. Keyterms go in `context.terms`. |
| Credentials | `api_key`; a `webhook_secret` (press **Generate** on API Credentials). The webhook header `X-EchoLedger-Webhook-Token` is an HMAC of the recording, checked on arrival. |
| Live captions | Not available: the apps stream only to AssemblyAI and ElevenLabs. |
| Provider-side data | The transcriptions and the uploaded files are deleted at Soniox after the result is saved. |
| Price | `prices.soniox.final_transcript` (default US$0.10 per hour per channel; check your plan). |

### whisper.cpp (self-hosted)

| | |
|---|---|
| Final transcript | Your own `whisper-server` behind HTTPS and a bearer-token reverse proxy (`whisper.base_url`). Each channel is sent as mono WAV in windows of `whisper.chunk_seconds` (default 600 s) with 2 s overlap; optional VAD (`whisper.vad`). |
| Live captions | Not available (returns no streaming grants). |
| Provider-side data | Stays on your hardware. |

### Parakeet (on-device)

The server side is complete: when selected, final transcripts are marked `awaiting_device` and the device uploads
its own transcript through `POST /api/sessions/{id}/transcripts`. The device-side engine (sherpa-onnx with a
600 MB+ model) is not bundled in this release, so choose another final provider for production use.

## Languages

The spoken language is chosen per recording (the chip on the pill, the Keep card, the phone popup), per client, or
as the server default (**Settings → General**). The first one set wins: recording, then client, then default.

**The choices (since v1.2)**, stored as codes: **Auto** (none), **English** (`en`), **Urdu** (`ur`), **Arabic**
(`ar`), and the **More languages** `fr`, `de`, `es`, `it`, `pt`, `nl`, `tr`, `ru` (add one in
`App\Stt\LanguageChoice::MORE`). Urdu and Arabic always allow English words inside. Hindi and the mixed choices are
gone: stored `hi`, `en+ur`, `en+hi` were rewritten to `ur` (migration `unify_languages`), and an engine that
detects Hindi is read as Urdu.

**Auto** picks only English, Urdu or Arabic (plus the More languages ticked under **Settings → General → Auto may
also pick**). When an engine detects another language (French for an Urdu call), the transcript is run once more
in **When Auto hears another language, use** (Urdu unless changed). Any transcript in Hindi (Devanagari) letters
is run once more forced as Urdu; lines still in Hindi letters after that are shown as
`[unclear: wrong script, re-transcribe]`, never as Hindi.

**Engine per language.** **Settings → Recording → Engine per language** lists up to four engines for English,
Urdu, Arabic, Other (the More languages) and, optionally, Auto, tried in order. An engine without a key, or one that
does not support the language, is skipped; the transcript records which engine was used and which one was wanted
(`meta.route`). Defaults until the Accuracy Lab says otherwise:

| Language | 1st | 2nd | 3rd | 4th |
|---|---|---|---|---|
| English | AssemblyAI (Universal-3.5 Pro) | Groq Whisper large-v3 | ElevenLabs Scribe v2 | Soniox |
| Urdu | Groq Whisper large-v3 | Soniox | ElevenLabs Scribe v2 | AssemblyAI (Universal-2) |
| Arabic | ElevenLabs Scribe v2 | Soniox | Groq Whisper large-v3 | AssemblyAI |
| Other | ElevenLabs Scribe v2 | Soniox | Groq Whisper large-v3 | AssemblyAI |

Auto with no Auto list keeps the provider chosen when the recording was created. Azure MAI and Parakeet are offered
for English only.

| Choice | AssemblyAI | ElevenLabs Scribe | Soniox | Groq Whisper |
|---|---|---|---|---|
| Urdu, Arabic | Universal-2 code switching with English | `language_code` | `language_hints` [ur, en] strict | `language` per chunk + Urdu-script style prompt |
| English, More | `language_code` | `language_code` | `language_hints` strict | `language` per chunk |
| Auto | detection limited to `expected_languages` | detection, checked after | identification + hints | detected per chunk, checked |

### Groq Whisper large-v3 (since v1.2)

| | |
|---|---|
| Final transcript | `whisper-large-v3` (not turbo), temperature 0, `verbose_json` with segment and word timestamps. Each side of the call as 16 kHz mono, cut at pauses into chunks of at most `audio.chunk_seconds` (600) with exact offsets; silent stretches are not sent. Uses the static ffmpeg on the server (`audio.ffmpeg_path`, else `~/bin/ffmpeg`); without it the whole file is sent (a signed link over 25 MB). |
| Guards | Per chunk: the recording's language, or Auto then only allowed languages (else run again in the fallback), Hindi letters run again as Urdu. Whisper's invented text is removed and logged in `meta.removed_segments` (`no_speech_prob` > 0.6 with `avg_logprob` < -1.0, `compression_ratio` > 2.4, three identical lines in a row, a phrase looping four times); lines with `avg_logprob` < -0.8 are kept and marked `uncertain`. Urdu letter forms (ي ك ه ة → ی ک ہ ۃ) and Urdu punctuation after Urdu words; no word is changed. |
| Prompt | A short style sentence in Urdu script with English business words in Latin letters (Arabic and English have their own), then the client's names and keyterms (at most 20). |
| Credentials | `groq.api_key` (console.groq.com → API Keys). |
| Price | US$0.111 per audio hour, at least 10 s billed per request; the usage ledger counts exactly what was sent (`meta.billed_audio_ms`). |
| Live captions | Not available. |
| Provider-side data | The audio is sent with the request; nothing is kept to delete. |

**Live captions** use the admin's live provider first, then the other streaming engine, and get the language too
(AssemblyAI `language_codes` with Universal-3 Pro; ElevenLabs `language_code`). Changing the chip during a call
reconnects the caption stream; the recording is not interrupted.

**Wrong script.** After a final transcript, the server counts letters per script (at least 40 letters). An Urdu
transcript that is mostly Devanagari, or mostly Latin letters for plain Urdu, or a Hindi one that is mostly Arabic
script, gets `meta.script_warning`; the recording page offers **Wrong script detected · Transcribe with …** (the next
engine for the language).

**Search.** Urdu text is folded before it is searched (`transcripts.search_text`): Arabic and Urdu letter forms
(ي/ی, ك/ک, ه/ہ, أ/ا …), vowel marks, tatweel and zero-width characters, and Arabic-Indic digits become one form,
and Latin text is lower-cased, so English loanwords in Urdu speech are found. Highlights still mark the original text.

**Compare engines (console).** On a recording: **⋯ → Compare engines**, a language and up to three engines. Each run
is kept as a comparison run (never searched or shared). **Engine results** lists them side by side with the cost per
run; **Make default for …** puts that engine first for the language, and **Use as transcript** swaps it in (the old
one becomes the second version).

## Vocabulary

Keyterms come from the client's own list first, then the global list (**Settings → General → Vocabulary**). A name
added from an edited transcript joins the client's list. Each engine takes as many as it accepts (limits in the
provider sections above). **Replacements** ("when the transcript says … write …") are applied by the server to every
new final transcript. The engine's original text stays in the transcript history and **Restore original** brings it
back.

## A second opinion

On a session page, **Transcribe with another engine** sends the same audio to another provider and keeps the result
as an `alternate` transcript. **Compare versions** shows the words that differ side by side. **Use the second
version** swaps it in (the old one becomes the alternate), and **Remove the second version** deletes it and its
provider copy. This uses the provider's final-transcript price again.

**Re-transcribe as…** (since v1.2) does the same in another language: the routing for that language picks the
engine, the current transcript stays until the new one is ready, then the new one becomes the transcript and the old
one is kept as the second version.

### Double-check (since v1.2, off by default)
**Settings → Engine per language → Double-check unsure lines with a second engine.** After a final transcript is
done, up to 12 lines the engine was unsure of (a word below 0.5 confidence, or a line Whisper or Azure marked unsure)
are cut from that speaker's audio (the line plus 0.3 s each side, at most 30 s) and sent one by one to a second
engine: Whisper on Groq, or ElevenLabs Scribe when Whisper wrote the transcript. When the two disagree (fewer than 80 %
of the words match, after folding case, punctuation and Urdu spelling), the second engine's words are stored on the
line (`check`) and every app shows them under it. Nothing is merged. The clips are one usage row (`check/…`, model
`double-check`); Groq bills at least 10 s per clip. ElevenLabs keeps a copy of each clip's transcript, which is
deleted right after (stt:reconcile retries). Nothing is sent once the monthly limit is reached, and without ffmpeg on
the server or a key for the second engine the check is skipped (`meta.double_check.skipped`).

## Privacy per provider

What each provider keeps, whether it trains on the recordings, what EchoLedger deletes there and the three account
switches worth turning on (AssemblyAI training opt-out or the EU region, ElevenLabs "Improve the models for everyone"
off, Groq Zero Data Retention on): [research/provider-privacy.md](research/provider-privacy.md), checked 2026-10-28.

## Usage, prices and the monthly limit

Devices report the minutes they streamed and the server records the minutes of every final transcript in the usage
ledger. Cost is minutes × the price table in **Settings → Storage → Monthly limit → Prices** (US$ per audio hour per channel,
filled with the list prices in the table below and editable). The console **Usage** page and the dashboard show the
totals per month, provider, use case, device and client.

A monthly limit, in minutes or US$, can be set for the server, and the workspace plan has its own minutes limit; the
tighter one applies. At the limit:

- a new streaming token is refused (`403 budget_exceeded`) and the apps show "Monthly transcription limit reached";
  recording itself carries on;
- a final transcript is not sent to a paid provider; it fails with the same message and can be run later with
  **Transcribe again** after the limit is raised.

Self-hosted engines (whisper.cpp, Parakeet) are not billable and are never blocked by the limit.
## Cost per recorded hour

List prices as researched on 2026-10-24 for a two-channel call (Me + Client). Providers bill each channel
separately, and streaming sessions bill for the time the connection is open, including silence. Check the
providers' pricing pages before budgeting; promotions change.

| Lane | AssemblyAI | ElevenLabs | Azure MAI-Transcribe-2 |
|---|---|---|---|
| Final transcript, 2 channels + keyterms | (0.21 + 0.05) × 2 ≈ **$0.52** | (0.22 + 0.05) × 2 ≈ **$0.54** | 0.10 × 2 ≈ **$0.20** (launch promotion until 2026-12-31) |
| Live captions, both channels | 2 × 0.45 ≈ **$0.90** (`universal-3-6-pro`) · ≈ $0.38 (`universal-streaming-english`) | 2 × (0.39 + 0.08) ≈ **$0.94** | — |
| Live captions, Client only | ≈ $0.45 | ≈ $0.47 | — |

whisper.cpp and Parakeet have no per-minute cost beyond your own hardware.

To reduce cost without losing final accuracy: set captions to `client_only` or `off`, or use the budget streaming
model — the final transcript is unaffected.

## Switching providers safely

1. Add the new provider's credentials on **API Credentials**.
2. For webhook providers, confirm `APP_URL` is publicly reachable over HTTPS.
3. Change the use case on **Settings** and record a short test call.
4. Jobs already submitted finish on the provider they were sent to; `stt:reconcile` keeps polling them.

If a provider is unavailable, failed final jobs stay `failed` with the provider's error on the session page. The
recording remains stored (encrypted); after switching, use **Transcribe again** on the session page to run the final
pass again with the current provider.
