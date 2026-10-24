# Speech-to-text providers

EchoLedger talks to speech providers only from the server. Devices never receive a provider API key. For live
captions they receive a short-lived, single-purpose streaming token, and final transcripts are always produced
server-side (or, for the on-device engine, uploaded by the device).

## Choosing providers

**Admin → Settings → Providers** assigns a driver to each use case:

| Use case | Setting | Used for |
|---|---|---|
| Live captions | `provider.live_captions` | Desktop overlay and Android caption bubble during calls |
| Dictation | `provider.dictation` | Desktop dictation box (microphone only) |
| Final transcript | `provider.final_transcript` | The accurate second pass over every finished call or meeting |
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
recording remains stored (encrypted); after switching, use **Re-transcribe** on the session page to run the final
pass again with the current provider.
