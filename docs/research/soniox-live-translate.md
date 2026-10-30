# Live Translate with Soniox: protocol, cost, privacy (checked 2026-10-31)

Game translate and Watch translate (v1.3, Phase B) stream one app's sound from the phone straight to Soniox's
real-time API and show the translation in a floating box. This note records what the implementation relies on and
where each fact comes from.

## Protocol (what `mobile/.../translate/` implements)

| Fact | Source | Where it is used |
|---|---|---|
| WebSocket `wss://stt-rt.soniox.com/transcribe-websocket`; the first text frame is the JSON config, then audio frames. | [WebSocket API](https://docs.soniox.com/stt/api-reference/websocket-api) | `SonioxProtocol.config` |
| Raw audio needs `audio_format` (e.g. `pcm_s16le`), `sample_rate`, `num_channels`. Containers (ogg, webm, wav, flac, …) are detected with `"auto"`; Opus is not named. | [Real-time transcription](https://docs.soniox.com/stt/rt/real-time-transcription) | PCM s16le 16 kHz mono. Ogg-Opus only after it is proven on the phone. |
| `translation: {type: "one_way", target_language}`; tokens carry `translation_status` (original / translation), `is_final`, `language`. | WebSocket API | `SonioxProtocol.parse` |
| `enable_endpoint_detection`; `max_endpoint_delay_ms` 500-3000 (default 2000). | WebSocket API | Speed presets: Fastest 500, Balanced 1000, Accurate 1500 |
| End of audio: an **empty text frame**; Soniox answers with the last tokens and `"finished": true`, then closes. An empty binary frame is not the end. | WebSocket API | `SonioxProtocol.END_OF_AUDIO` |
| Errors: `error_code` (HTTP status), `error_type`, `error_message`. | WebSocket API | 401/403 → new key; 402 → "no credit" notice |
| Keepalive `{"type": "keepalive"}` at least every 20 s while no audio is sent; after 20 s with neither, the stream ends with a 408. | [Connection keepalive](https://docs.soniox.com/stt/rt/connection-keepalive) | Sent every 10 s (`KEEPALIVE_EVERY_MS`) |
| Up to 300 minutes of audio per stream. | WebSocket API | A new stream after 290 min (`ROTATE_AFTER_MS`) |
| Temporary keys: `POST https://api.soniox.com/v1/auth/temporary-api-key` with `usage_type: "transcribe_websocket"`, `expires_in_seconds`. | Soniox API reference | Server: `POST /api/live-translate/token` (10-minute key; the account key never leaves the server) |

## Cost

- Real-time translation is **$0.18 per hour** (transcription alone $0.12/h); translation is part of the same stream.
  Source: [soniox.com/pricing](https://soniox.com/pricing), [comparison](https://soniox.com/compare/stt/pricing).
- **Billing is for the time a stream is open**, not only the audio sent ("you are charged for the full stream
  duration", [keepalive page](https://docs.soniox.com/stt/rt/connection-keepalive)). Silence held open with keepalives
  therefore costs the same as speech. EchoLedger opens the stream when the speech gate hears speech (300 ms pre-roll,
  frames wait up to 5 s while it connects) and closes it after 30 s without speech (`REST_AFTER_FRAMES`). A typical
  game with talk in bursts costs well under the full hour rate.
- Budget: Live Translate has its own monthly budget (default $10, console Settings → Game translate; phone Game
  translate → Privacy and budget). Warning at 80 %, pause at 100 %, "Continue this month anyway" lifts it for the month.
  It is not counted against the transcription limit. Minutes and cost per mode appear in the console's Usage page.
- Data: 16 kHz mono 16-bit PCM is 256 kbit/s (about 115 MB per hour of speech sent); nothing is sent while quiet.

## Privacy

- Live audio goes only from the phone to Soniox; nothing passes through the EchoLedger server, which only issues the
  10-minute key.
- Soniox: real-time audio is "never stored, never saved. Audio stays in memory, everything is processed in real-time"
  ([speech-to-text page](https://soniox.com/speech-to-text)); content "is never used to train or improve our models"
  ([data residency](https://docs.soniox.com/stt/data-residency)). Data residency regions (US, EU, Japan, India) are
  chosen per Soniox project.
- EchoLedger stores nothing by default. "Save session text" (Game) and "Save subtitles (.srt)" (Watch) are off; when on,
  only the translated text is written to Downloads/EchoLedger on the phone.
- Watch translate respects apps that opt out of capture: the helper's mix never asks for privileged playback capture,
  so protected video (Netflix and similar) stays silent and the app says "This app doesn't allow live captions."
- Anti-cheat: no accessibility service, no touch injection, no access to game files or memory; only Android's audio
  routing (a loop-back copy of the game's sound) and an overlay window that takes no touches while locked.
