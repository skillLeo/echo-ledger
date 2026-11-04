# Groq rate limits and the Gemini engine (checked 2026-11-04)

v1.5 Phase 1 paces Whisper on Groq under its rate limits (`web/app/Stt/GroqPacer.php`) and adds Google Gemini as a
final-transcript engine (`web/app/Stt/Drivers/GeminiDriver.php`). This note records what the code relies on and where
each fact comes from.

## Groq (Whisper large-v3)

| Fact | Source | Where it is used |
|---|---|---|
| Free plan, whisper-large-v3 and -turbo: 20 requests a minute, 2,000 a day, 7,200 audio seconds an hour, 28,800 a day. | [Rate limits](https://console.groq.com/docs/rate-limits) (the table readable as text) | `GroqPacer::PLANS['free']` |
| Developer plan, whisper-large-v3: 300 requests a minute, 200,000 a day, 200,000 audio seconds an hour, 4,000,000 a day. | Groq's page puts these on a "Developer Plan Limits" tab that is not readable as text; the figures are the ones third-party tables quote from it (for example [apio.sh](https://apio.sh/apis/groq-speech-to-text)). **Not verified on Groq's own page.** | `GroqPacer::PLANS['developer']` (the default here) |
| Headers on every answer: `x-ratelimit-limit-requests` / `-remaining-requests` / `-reset-requests` are the requests **per day**; `-tokens` are tokens per minute. No audio-seconds headers are listed. Resets read like `2m59.56s` or `7.66s`. | Rate limits | `GroqPacer::observe()`: the reported requests a day win over the plan's; a used-up bucket waits for its reset |
| A 429 carries `retry-after` in seconds. | Rate limits | Nothing is sent with that key before it; an Urdu or Auto transcript waits that long |
| Limits are per organisation, not per key: keys of one organisation share them. | Rate limits | Requests are still counted per key (a hash of it): two keys of one organisation can together go over a limit, and Groq's 429 then paces them |
| At least 10 s of audio is billed per request; US$0.111 per audio hour (large-v3). | [whisper-large-v3](https://console.groq.com/docs/model/whisper-large-v3), [pricing](https://groq.com/pricing) | `GroqWhisperDriver::MIN_BILLED_MS`, the price table |

## Google Gemini

| Fact | Source | Where it is used |
|---|---|---|
| No Pro model is labelled stable. `gemini-3.1-pro-preview` is the current Pro; `gemini-2.5-pro` is deprecated (answered by Gemini 3.8 Flash from 2026-12-01); `gemini-3-pro-preview` is shut down. Preview models are retired with at least two weeks' notice. | [Models](https://aistudio.google.com/docs/models) (ai.google.dev now redirects there) | `GeminiDriver::DEFAULT_MODEL`; the model is a console setting so a replacement can be typed in |
| Files API resumable upload: `POST /upload/v1beta/files` with `X-Goog-Upload-Protocol: resumable`, `-Command: start`, `-Header-Content-Length`, `-Header-Content-Type`; the bytes go to the `X-Goog-Upload-URL` answer with `-Command: upload, finalize` and `-Offset: 0`. Files are kept 48 hours; up to 2 GB a file, 20 GB a project. `DELETE /v1beta/files/{name}`. | [Files API](https://aistudio.google.com/docs/files) | Upload per chunk, deleted in `finally` |
| `POST /v1beta/models/{model}:generateContent` with `fileData {mimeType, fileUri}`, `generationConfig.responseMimeType` / `responseJsonSchema`, `thinkingConfig.thinkingLevel` (`low` on Gemini 3 and later), `usageMetadata` token counts, `finishReason` values. | [generateContent](https://aistudio.google.com/api-reference/generate-content), [Thinking](https://aistudio.google.com/docs/thinking) | `GeminiDriver::generate()` |
| Audio is 32 tokens a second; up to 9.5 hours a prompt; ogg, flac, wav, mp3, m4a, opus and others. | [Audio understanding](https://aistudio.google.com/docs/audio) | Chunks of `audio.chunk_seconds` (600 s) as FLAC |
| `gemini-3.1-pro-preview`, paid tier: US$2 per million input tokens and US$12 per million output tokens (prompts up to 200K tokens); no free tier. So about US$0.23 per audio hour of input, plus the answer's tokens. | [Pricing](https://aistudio.google.com/docs/pricing) | Price table `gemini` 0.23 (input only) |
| On the free tier (other models) content is used to improve Google's products; on the paid tier it is not. | Pricing | API Credentials help: use a key from a project with billing |
