# STT research: Azure Speech (MAI-Transcribe) + local engines (whisper.cpp, sherpa-onnx Parakeet)

> Scope: what the drivers `azure_mai`, `whispercpp_local` and `parakeet_local` need.
> Verified on **2026-10-24** against primary sources. Sources are linked next to each fact.
> **UNVERIFIED** means the fact could not be confirmed in a primary source; the reason is given.
> Where an earlier planning assumption conflicts with this note, this note wins.

---

## 0. Planning assumptions checked

| Planning assumption | Verdict | Evidence |
|---|---|---|
| "Microsoft MAI-Transcribe-2 (Azure)" exists | **Confirmed.** Supported model strings: `MAI-Transcribe-2`, `MAI-Transcribe-1.5`, `MAI-Transcribe-1` ("Deprecated on Aug 20, 2026"). | [mai-transcribe](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/mai-transcribe) |
| It is served by Azure Speech | **Confirmed.** Call the fast transcription REST API with `definition.enhancedMode.enabled=true` and `enhancedMode.model="MAI-Transcribe-2"`. Also available as a Voice Live transcription model, and as MAI-Transcribe-2-Streaming (Speech SDK / Realtime API). | [mai-transcribe](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/mai-transcribe), [voice-live-how-to](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/voice-live-how-to), [mai-transcribe-2-streaming](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/mai-transcribe-2-streaming) |
| "$1.67 / 1,000 min" | **Partly true.** The launch price is $0.10 per audio hour, which works out to $1.67 per 1,000 min, but it is a "limited-time offer until the end of the year" (the post is dated 2026-09-03). The price after that is unknown. | [microsoft.ai MAI-Transcribe-2 post](https://microsoft.ai/news/mai-transcribe-2-is-the-fastest-most-accurate-and-cheapest-speech-recognition-model-in-the-world/) |
| "diarization + phrase list + word timestamps" | **Confirmed** (`diarization.enabled`, `phraseList.phrases`, `enhancedMode.modelOptions.timestamps="word"`). | [mai-transcribe](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/mai-transcribe) |
| Implied: send the two-channel file to Azure MAI | **Refuted.** The feature table marks "Channel (stereo)" as ❌ for MAI-Transcribe-2, and fast transcription "By default, the fast transcription API merges all input channels into a single channel" (corrected by verification: fast-transcription-create, exact wording). Each channel must go up as its own mono file. | [llm-speech feature table](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/llm-speech), [fast-transcription-create](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/fast-transcription-create) |
| Implied: the final transcript comes back by webhook | **Refuted for MAI.** Fast transcription is synchronous ("Synchronous transcription of an audio file"). Batch transcription has webhooks, but it does not support phrase lists, and MAI is not documented for batch. | [REST Transcribe](https://learn.microsoft.com/en-us/rest/api/speechtotext/transcriptions/transcribe), [phrase list doc](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/improve-accuracy-phrase-list) |
| "AA-WER 2.0%" | **UNVERIFIED.** I did not open any primary benchmark. Microsoft's own post only claims "most accurate". | — |
| Offline: whisper.cpp large-v3-turbo | **Confirmed.** `ggml-large-v3-turbo.bin` is 1.62 GB, `-q8_0` is 874 MB and `-q5_0` is 574 MB. | [HF ggerganov/whisper.cpp](https://huggingface.co/ggerganov/whisper.cpp/tree/main) |
| Offline: sherpa-onnx Parakeet TDT 0.6B v3 INT8 | **Confirmed**, with a caveat: it covers **25 European languages only**. There is no Urdu, Hindi or Arabic. | [k2-fsa NeMo models](https://k2-fsa.github.io/sherpa/onnx/pretrained_models/offline-transducer/nemo-transducer-models.html), [nvidia/parakeet-tdt-0.6b-v3](https://huggingface.co/nvidia/parakeet-tdt-0.6b-v3) |

---

## 1. Azure Speech

### 1.1 What "MAI-Transcribe" is today

- **What it is.** An in-house speech-to-text model family from the Microsoft AI team. MAI-Transcribe-2 covers 60 languages and offers diarization, word-level timestamps, automatic language identification, keyword biasing, code switching ("Hinglish and Spanglish") and two transcription styles, `verbatim` and `clean` ([mai-transcribe](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/mai-transcribe)).
- **Release timeline.**
  - March 2026: "The MAI-Transcribe-1 model is available in public preview through enhanced mode."
  - September 2026: "MAI-transcribe-2 release" ([release notes](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/releasenotes)).
  - 2026-09-03: Microsoft AI announcement post ([microsoft.ai](https://microsoft.ai/news/mai-transcribe-2-is-the-fastest-most-accurate-and-cheapest-speech-recognition-model-in-the-world/)).
- **Status: treat as preview, with no SLA.**
  - The Voice Live tables label `mai-transcribe-2` "(preview)" ([regions → Voice Live tab](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/regions?tabs=voice-live), [voice-live-how-to](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/voice-live-how-to)).
  - The release notes contain no GA announcement for MAI-Transcribe-2.
  - The callout boxes on the MAI page did not render in my fetch. Whether MAI-Transcribe-2 is GA is therefore **UNVERIFIED**.
- **Streaming sibling.** MAI-Transcribe-2-Streaming is in "public preview" ([mai-transcribe-2-streaming](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/mai-transcribe-2-streaming)) and was announced 2026-10-01 ([microsoft.ai](https://microsoft.ai/news/our-first-streaming-transcription-model/)).
- **Languages.**
  - MAI-Transcribe-2 lists `ur` (Urdu), `hi` (Hindi) and `pa` ("Punjabi (Gurmukhi script)").
  - MAI-Transcribe-1.5 does **not** list Urdu ([mai-transcribe](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/mai-transcribe)).

### 1.2 API surfaces

| Surface | Use in EchoLedger | Endpoint | Auth | How to select MAI |
|---|---|---|---|---|
| **Fast transcription** (synchronous REST) | Final transcript, called from the server | `POST {endpoint}/speechtotext/transcriptions:transcribe?api-version=2025-10-15` ([REST](https://learn.microsoft.com/en-us/rest/api/speechtotext/transcriptions/transcribe)) | `Ocp-Apim-Subscription-Key`, or Entra `Authorization: Bearer` ([fast-transcription-create](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/fast-transcription-create)) | `enhancedMode.model: "MAI-Transcribe-2"` |
| **Batch transcription** (asynchronous, with webhooks) | Not used for MAI | `POST {endpoint}/speechtotext/transcriptions:submit?api-version=2024-11-15` ([batch-create](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/batch-transcription-create)) | Key | Not documented |
| **Speech SDK** (real-time; the SDK manages the WebSocket) | Live captions on devices | The SDK derives the host from the region | Key, STS token, or Entra ([JS SpeechConfig](https://learn.microsoft.com/en-us/javascript/api/microsoft-cognitiveservices-speech-sdk/speechconfig)) | `speechConfig.setModel("mai-transcribe-2-streaming")`; needs SDK ≥ 1.52 ([MAI streaming SDK](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/mai-transcribe-2-streaming-speech-sdk)) |
| **MAI Realtime API** (WebSocket shaped like the OpenAI Realtime API) | Server-to-server only | `wss://{resource}.services.ai.azure.com/mai/v1/realtime?intent=transcription` | Entra Bearer, or an `api-key` header / query parameter ([MAI realtime](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/mai-transcribe-2-streaming-realtime)) | `session.audio.input.transcription.model = "<deployment-name>"` |
| **Voice Live** | Not a fit (built for voice agents) | `wss://{resource}.services.ai.azure.com/voice-live/realtime?api-version=2026-04-10&model=…` | Entra Bearer or `api-key` ([voice-live-how-to](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/voice-live-how-to)) | `input_audio_transcription.model`: `mai-transcribe`, `mai-transcribe-2` or `mai-transcribe-2-streaming` |

Speech-to-text REST `2025-10-15` "is the latest version that's generally available" ([migrate-2025-10-15](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/migrate-2025-10-15)).

**Regions where "Transcribe with MAI-Transcribe" is available:** `centralindia`, `eastus`, `northeurope`, `southeastasia`, `westus`, `westus2` ([regions → LLM speech tab](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/regions?tabs=llmspeech)).

**Data residency:** Speech "stores and processes speech data in the region where you create your resource". Voice Live is an exception (same page).

### 1.3 Fast transcription: the request

**Endpoint and auth** ([REST Transcribe](https://learn.microsoft.com/en-us/rest/api/speechtotext/transcriptions/transcribe)):

- `endpoint` is "protocol and hostname, for example: https://westus.api.cognitive.microsoft.com".
- The docs' examples use `https://YourResourceName.cognitiveservices.azure.com` ([mai-transcribe](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/mai-transcribe)).
- The body is `multipart/form-data` with two parts:
  - `audio`: the file.
  - `definition`: "a JSON-serialized object of type `TranscribeDefinition`".
- Required header: `Ocp-Apim-Subscription-Key`.
- Alternative: `--form 'definition="{"audioUrl": "https://..."}"'` lets the service fetch the audio from a public URL ([fast-transcription-create](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/fast-transcription-create)). Whether MAI honours `audioUrl` is **UNVERIFIED**; the MAI page does not show it.

**`definition` fields**

| Field | Values | MAI-2? | Source |
|---|---|---|---|
| `enhancedMode.enabled` | `true` (default `false`) | **Required** | [mai-transcribe](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/mai-transcribe) |
| `enhancedMode.model` | `"MAI-Transcribe-2"` | **Required** | same |
| `enhancedMode.modelOptions.timestamps` | `"word"`, `"segment"` or `"none"`. **Default `none`.** | yes | same |
| `enhancedMode.modelOptions.transcribeStyle` | `"verbatim"` (default) or `"clean"` | yes | same |
| `diarization.enabled` / `diarization.maxSpeakers` | bool / int. Range 2–35 per the Python SDK section. | `enabled` only; MAI "Supports shorter recordings" with diarization. The length note did not render, so the limit is **UNVERIFIED**. | [mai-transcribe](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/mai-transcribe), [fast-transcription-create](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/fast-transcription-create) |
| `phraseList.phrases` | string[] | yes ("Terms are hints, not forced output") | [mai-transcribe](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/mai-transcribe) |
| `phraseList.biasingWeight` | float, e.g. `1.6`. Range 0.0–2.0 per the SDK docs. | MAI does not list it, so **UNVERIFIED** | [migrate-2025-10-15](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/migrate-2025-10-15) |
| `locales` | MAI: **one bare language code** such as `["en"]`; omit it for auto-detect. Standard model: BCP-47 codes such as `["en-US"]`. | yes | [mai-transcribe](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/mai-transcribe), [fast-transcription-create](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/fast-transcription-create) |
| `channels` | `[0,1]`, `[0]` or `[1]`; "Up to two channels". Ignored for mono. Cannot be `[0,1]` together with diarization. | **No** (❌ "Channel (stereo)") | [fast-transcription-create](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/fast-transcription-create), [llm-speech](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/llm-speech) |
| `profanityFilterMode` | `None`, `Masked`, `Removed` or `Tags`. **Default `Masked`.** | yes (✅) | same |
| `enhancedMode.task` / `prompt` / `targetLanguage` | LLM Speech only (`transcribe` or `translate`; prompt). | No ("Custom prompting" ❌) | [llm-speech](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/llm-speech) |

**Feature matrix** ([llm-speech](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/llm-speech)):

| | Azure Transcribe (default) | LLM Speech | MAI-Transcribe-2 |
|---|---|---|---|
| Diarization | ✅ | ✅ | ✅ |
| Channel separation (stereo) | ✅ | ✅ | ❌ |
| Profanity filter | ✅ | ✅ | ✅ |
| Phrase list | ✅ | ✅ | ✅ |
| Word timestamps | ✅ | ✅ | ✅ |

**Accepted audio formats**

- **MAI:** "WAV, MP3, or FLAC" ([mai-transcribe](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/mai-transcribe)).
- **Standard fast transcription:** "WAV, MP3, OPUS/OGG, FLAC, WMA, AAC, ALAW in WAV container, MULAW in WAV container, AMR, WebM, and SPEEX" ([fast-transcription-create](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/fast-transcription-create)).

**MAI request built only from documented fields** (I composed this combination; the docs show the fields one at a time):

```bash
curl --location 'https://YourResourceName.cognitiveservices.azure.com/speechtotext/transcriptions:transcribe?api-version=2025-10-15' \
  --header 'Content-Type: multipart/form-data' \
  --header 'Ocp-Apim-Subscription-Key: <key>' \
  --form 'audio=@"me.flac"' \
  --form 'definition={"enhancedMode":{"enabled":true,"model":"MAI-Transcribe-2","modelOptions":{"timestamps":"word","transcribeStyle":"verbatim"}},"phraseList":{"phrases":["Acme","Rehaan"]},"profanityFilterMode":"None"}'
```

**Laravel 13.38 client sketch.** The APIs below were checked in the installed source, `web/vendor/laravel/framework/src/Illuminate/Http/Client/PendingRequest.php`:

- `attach()` accepts a stream resource.
- Extra `post()` data becomes multipart fields through `parseMultipartBodyFormat`.
- Default `timeout` is **30** s and `connect_timeout` is 10 s.
- Signature: `retry(array|int $times, Closure|int $sleepMilliseconds = 0, ?callable $when = null, bool $throw = true)`. An array is treated as a list of backoff delays.
- Multipart streams are rewound before each retry.

```php
$def = ['enhancedMode' => ['enabled' => true, 'model' => 'MAI-Transcribe-2',
            'modelOptions' => ['timestamps' => 'word', 'transcribeStyle' => 'verbatim']],
        'profanityFilterMode' => 'None'];
if ($keyterms) { $def['phraseList'] = ['phrases' => array_values($keyterms)]; }

$res = Http::withHeaders(['Ocp-Apim-Subscription-Key' => $cred->key])   // key never leaves the server
    ->connectTimeout(15)->timeout(600)
    ->retry([2000, 4000, 8000, 16000, 32000], 0,
        fn ($e) => $e instanceof ConnectionException
            || in_array($e->response?->status(), [429, 500, 502, 503, 504], true), throw: false)
    ->attach('audio', fopen($channelPath, 'rb'), basename($channelPath))
    ->post(rtrim($cred->endpoint, '/').'/speechtotext/transcriptions:transcribe?api-version=2025-10-15',
        ['definition' => json_encode($def, JSON_THROW_ON_ERROR)]);
```

The retry policy (retry on 429/500/502/503/504 with 2, 4, 8, 16 and 32 s backoff; do not retry 400, 401 or 422) is Microsoft's own advice. Microsoft also notes that "the default HTTP read timeout (300 seconds) might be exceeded" ([llm-speech](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/llm-speech)). The JS tip says the error "Enhanced mode is currently not supported yet" means you are in the wrong region (same page).

### 1.4 Fast transcription: the response

`TranscribeResult` ([REST Transcribe](https://learn.microsoft.com/en-us/rest/api/speechtotext/transcriptions/transcribe)):

```text
{ durationMilliseconds:int,
  combinedPhrases:[{ channel?:int, text:string }],            // channel: "Only present if channel separation is enabled"
  phrases:[{ channel?:int, speaker?:int,                       // speaker: "Only present if speaker diarization is enabled"
             offsetMilliseconds:int, durationMilliseconds:int, text:string,
             words?:[{ text, offsetMilliseconds, durationMilliseconds }],  // "Only present if word-level timestamps are enabled"
             locale:string, confidence:float }] }
```

- **Two-channel sample (standard model).** The multichannel sample shows `phrases[].channel` set to 0 or 1, and `combinedPhrases` holds one entry per channel ([fast-transcription-create](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/fast-transcription-create)).
- **Confidence.** For LLM Speech, "`confidence` isn't available and is always `0`" ([llm-speech](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/llm-speech)). Store 0 as `null`.
- **Output form.** Fast transcription returns display form only ([fast-transcription-create](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/fast-transcription-create)).
- **Errors.** The body is `{code, message, innerError:{code:DetailedErrorCode,…}}`. Codes worth mapping:
  - `InvalidAudioFormat`
  - `AudioLengthLimitExceeded`
  - `EmptyAudioFile`
  - `BadChannelConfiguration`
  - `InvalidChannelSpecification`
  - `NoLanguageIdentified`
  - `MultipleLanguagesIdentified`
  - `QuotaViolation`

  Source: [REST Transcribe](https://learn.microsoft.com/en-us/rest/api/speechtotext/transcriptions/transcribe).

### 1.5 Limits

**Size and duration: the sources disagree.**

- 2 hours / 250 MB:
  - The REST reference (`2025-10-15` view, updated 2025-10-30): "shorter than 2 hours … smaller than 250 MB" ([REST](https://learn.microsoft.com/en-us/rest/api/speechtotext/transcriptions/transcribe)).
  - The JS `TranscriptionOptions.audioUrl` docs give the same numbers ([JS SDK](https://learn.microsoft.com/en-us/javascript/api/@azure/ai-speech-transcription/transcriptionoptions)).
- 5 hours / 500 MB:
  - The quotas page (updated 2026-10-23): fast transcription and LLM Speech "< 500 MB" and "< 5 hours per file" ([quotas](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/speech-services-quotas-and-limits)).
  - The March 2026 release note: "now supports up to five hours per audio file input" ([release notes](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/releasenotes)).
- **Design for ≤ 2 h and ≤ 250 MB per request**, and split anything longer.

**Rate and tier.**

- Fast and batch transcription share **600 requests per minute** on S0 ([quotas](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/speech-services-quotas-and-limits)).
- F0 shows "Not applicable" for fast transcription and LLM Speech, so plan on S0.

**Batch transcription limits** (same quotas page):

- 1 GB per file.
- Diarization is limited to 240 minutes per file.

**Real-time limits** (same quotas page):

- 100 concurrent requests on S0; 1 on F0.
- Real-time diarization is limited to 240 minutes per session.

### 1.6 Async batch transcription and webhooks (documented, but not for MAI)

**Why it is not used for MAI**

- "The Batch transcription API doesn't support phrase lists" ([phrase list doc](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/improve-accuracy-phrase-list)).
- Enhanced mode / MAI is not documented for batch.

**Submitting a job** ([batch-create](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/batch-transcription-create)):

- Body: `{contentUrls|contentContainerUrl, locale, displayName, model?, properties:{timeToLiveHours (required, 6 h–31 d), wordLevelTimestampsEnabled, channels (default 0 and 1), diarization (mono only), profanityFilterMode, punctuationMode, destinationContainerUrl}}`.
- Delete with `Transcriptions - Delete`.

**Registering a webhook** ([Web Hooks - Create](https://learn.microsoft.com/en-us/rest/api/speechtotext/web-hooks/create?view=rest-speechtotext-2024-11-15)):

- Request: `POST {endpoint}/speechtotext/webhooks?api-version=2024-11-15` with body `{displayName, webUrl, events:{transcriptionCompletion:true,…}, properties:{secret}}`.
- Signature: a SHA256 HMAC of the payload, keyed with `secret`, sent as **`X-MicrosoftSpeechServices-Signature`**. The encoding (hex or base64) is **UNVERIFIED**.
- The event type arrives in `X-MicrosoftSpeechServices-Event`.
- Challenge: `?validationToken=` must be answered with `200` and the raw token as `text/plain`, not JSON ([batch-create](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/batch-transcription-create)).
- Firewall: allow inbound traffic from the service tag `CognitiveServicesManagement` (same page).

### 1.7 Real-time captions on devices (short-lived token flow)

**STS token** ([REST short audio → Authentication](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/rest-speech-to-text-short)):

- Request: `POST https://{region}.api.cognitive.microsoft.com/sts/v1.0/issueToken`.
  - This is for resources without a custom domain.
  - With a custom domain, use `https://{resource}.cognitiveservices.azure.com/sts/v1.0/issueToken`.
- Headers: `Ocp-Apim-Subscription-Key`, `Content-type: application/x-www-form-urlencoded`, `Content-Length: 0`.
- Response: the body is the JWT.
- Validity: "valid for 10 minutes"; Microsoft recommends reusing a token for 9 minutes.
- Host scoping: "Bearer tokens are scoped to the endpoint that issued them". Mint tokens from the **regional** STS endpoint so they match `fromAuthorizationToken(token, region)`. That this pairing is required for the SDK is my inference from that statement (medium confidence).

**SDK versions**

- `microsoft-cognitiveservices-speech-sdk` **1.53.0**, MIT ([npm](https://registry.npmjs.org/microsoft-cognitiveservices-speech-sdk/latest)).
- Android/Java `com.microsoft.cognitiveservices.speech:client-sdk` **1.53.0**; Maven metadata last updated 2026-10-21 ([maven-metadata](https://repo1.maven.org/maven2/com/microsoft/cognitiveservices/speech/client-sdk/maven-metadata.xml)). The Android AAR "includes the necessary libraries and required Android permissions" ([setup-platform](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/quickstarts/setup-platform)).
- SDK 1.52 added "support for selecting a streaming model for speech transcription" ([release notes](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/releasenotes)).

**Desktop (Tauri webview, JS).** Rust pushes PCM frames for one channel; one recognizer runs per channel. API checked in the JS reference: [SpeechConfig](https://learn.microsoft.com/en-us/javascript/api/microsoft-cognitiveservices-speech-sdk/speechconfig), [AudioInputStream](https://learn.microsoft.com/en-us/javascript/api/microsoft-cognitiveservices-speech-sdk/audioinputstream), [AudioStreamFormat](https://learn.microsoft.com/en-us/javascript/api/microsoft-cognitiveservices-speech-sdk/audiostreamformat), [AudioConfig](https://learn.microsoft.com/en-us/javascript/api/microsoft-cognitiveservices-speech-sdk/audioconfig), [SpeechRecognizer](https://learn.microsoft.com/en-us/javascript/api/microsoft-cognitiveservices-speech-sdk/speechrecognizer), [PhraseListGrammar](https://learn.microsoft.com/en-us/javascript/api/microsoft-cognitiveservices-speech-sdk/phraselistgrammar), [ProfanityOption](https://learn.microsoft.com/en-us/javascript/api/microsoft-cognitiveservices-speech-sdk/profanityoption).

```ts
import * as sdk from "microsoft-cognitiveservices-speech-sdk";
sdk.Recognizer.enableTelemetry(false);                       // static; when true, errors "are collected and sent to Microsoft" (default not stated)
const cfg = sdk.SpeechConfig.fromAuthorizationToken(tok.token, tok.region);
cfg.setProfanity(sdk.ProfanityOption.Raw);                   // Masked=0, Removed=1, Raw=2
// cfg.setModel("mai-transcribe-2-streaming");               // SDK >= 1.52, preview; token + model combo UNVERIFIED
const push = sdk.AudioInputStream.createPushStream(sdk.AudioStreamFormat.getWaveFormatPCM(16000, 16, 1));
const rec = new sdk.SpeechRecognizer(cfg, sdk.AudioConfig.fromStreamInput(push));
const pl = sdk.PhraseListGrammar.fromRecognizer(rec); pl.addPhrases(tok.phrases); pl.setWeight(1.5); // [0.0, 2.0]
rec.recognizing = (_s, e) => overlay.partial(e.result.text);
rec.recognized  = (_s, e) => overlay.final(e.result.text);
rec.startContinuousRecognitionAsync();
// every ~9 min: rec.authorizationToken = (await fetchToken()).token;
```

Notes on token refresh:

- The SpeechConfig docs say that for recognizers already created, "you need to set authorization token of the corresponding recognizer".
- The JS reference lists `authorizationToken` with a getter description only. Whether the JS setter exists is **UNVERIFIED** until checked against the installed typings.
- Java's `SpeechRecognizer.setAuthorizationToken(String)` **is** documented ([Java SpeechRecognizer](https://learn.microsoft.com/en-us/java/api/com.microsoft.cognitiveservices.speech.speechrecognizer)).

**Android (Kotlin, Java SDK).** Checked against [Java SpeechConfig](https://learn.microsoft.com/en-us/java/api/com.microsoft.cognitiveservices.speech.speechconfig), [Java AudioConfig.fromStreamInput](https://learn.microsoft.com/en-us/java/api/com.microsoft.cognitiveservices.speech.audio.audioconfig), [MAI streaming SDK](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/mai-transcribe-2-streaming-speech-sdk) and [phrase list doc](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/improve-accuracy-phrase-list).

```kotlin
val cfg = SpeechConfig.fromAuthorizationToken(token, region)
// cfg.setModel("mai-transcribe-2-streaming")  // >= 1.52 (absent from the 1.51 reference page)
val rec = SpeechRecognizer(cfg, AudioConfig.fromStreamInput(pushStream))
PhraseListGrammar.fromRecognizer(rec).apply { phrases.forEach(::addPhrase); setWeight(1.5) }
rec.recognized.addEventListener { _, e -> if (e.result.reason == ResultReason.RecognizedSpeech) ui(e.result.text) }
rec.startContinuousRecognitionAsync().get()
// refresh: rec.setAuthorizationToken(newToken)
```

**Phrase lists in real-time** ([phrase list doc](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/improve-accuracy-phrase-list), [PhraseListGrammar](https://learn.microsoft.com/en-us/javascript/api/microsoft-cognitiveservices-speech-sdk/phraselistgrammar)):

- Supported by the real-time SDK, fast transcription, LLM Speech and Voice Live. Not supported by batch.
- Keep a list to "no more than 2,000 phrases".
- Weight range is 0.0–2.0; the default is 1.0.
- New phrases take effect "at the start of the next recognition, or the next time the SpeechSDK must reconnect".

**MAI-Transcribe-2-Streaming specifics** ([overview](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/mai-transcribe-2-streaming), [realtime](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/mai-transcribe-2-streaming-realtime)):

- **Serving regions.** "You can access MAI-Transcribe-2-Streaming globally". It is served from `swedencentral`, `centralus`, `eastus2` and `southeastasia`, so audio may leave your resource's region.
- **Realtime API protocol:**
  - Audio format: PCM16 mono at 16000 or 24000 Hz.
  - "Each session can last up to one hour".
  - `turn_detection` must be `null`, and the client sends `input_audio_buffer.commit` itself.
  - Events: `conversation.item.input_audio_transcription.delta`, `.intermediate` (MAI-specific), `.completed` and `input_audio_buffer.committed`.
  - Auth is Entra or `api-key` only, so this API is **not usable from devices** without leaking the key.
- **Phrase lists:** documented for Voice Live `mai-transcribe` / `mai-transcribe-2` (`input_audio_transcription.phrase_list`) ([voice-live-how-to](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/voice-live-how-to)). For the Speech SDK streaming path they are **UNVERIFIED**.

### 1.8 Pricing and retention

**Pricing**

- MAI-Transcribe-2: **$0.10 per audio hour** at launch, "as a limited-time offer until the end of the year" ([microsoft.ai](https://microsoft.ai/news/mai-transcribe-2-is-the-fastest-most-accurate-and-cheapest-speech-recognition-model-in-the-world/)).
- MAI-Transcribe-2-Streaming: **$0.54 per audio hour** "through the end of the year" ([microsoft.ai](https://microsoft.ai/news/our-first-streaming-transcription-model/)).
- The Azure pricing page rendered every price as `$-`, so standard and real-time rates are **UNVERIFIED**.
- From the same pricing page:
  - Billing is "hours of audio sent to the service, billed in second increments".
  - F0 includes "5 audio hours free per month" for real-time; batch is not supported on F0 ([pricing](https://azure.microsoft.com/en-us/pricing/details/speech/)).

**Retention**

- "When doing real-time speech to text, fast transcription, … Microsoft does not retain or store the data provided by customers" ([data privacy](https://learn.microsoft.com/en-us/azure/ai-foundry/responsible-ai/speech-service/speech-to-text/data-privacy-security)). So there is no provider copy to delete for the fast-transcription path. MAI is not named explicitly, so this is medium confidence for MAI.
- Batch output stays stored until it is deleted or its TTL expires (same page).

---

## 2. whisper.cpp

### 2.1 Versions and licences

- **whisper.cpp v1.9.5**, published 2026-10-06 ("Maintenance release. Updates to ggml v0.26.0").
  - The release page shows only "Assets 2", so plan to build from source ([release](https://github.com/ggml-org/whisper.cpp/releases/tag/v1.9.5)).
  - Licence: **MIT** ([GitHub license API](https://api.github.com/repos/ggml-org/whisper.cpp/license)).
- **Build:** `cmake -B build` then `cmake --build build -j --config Release`. The binary is `./build/bin/whisper-server` ([README](https://github.com/ggml-org/whisper.cpp/blob/master/README.md), [server README](https://github.com/ggml-org/whisper.cpp/blob/master/examples/server/README.md)).
- **Model** ([HF openai/whisper-large-v3-turbo](https://huggingface.co/openai/whisper-large-v3-turbo)):
  - Licence: MIT. About 809M parameters.
  - The decoder is "reduced from 32 to 4" layers compared with large-v3, with "a minor quality degradation".
  - Whisper's tokenizer includes `"ur": "urdu"`, `"hi": "hindi"` and `"pa": "punjabi"` ([tokenizer.py](https://github.com/openai/whisper/blob/main/whisper/tokenizer.py)).

### 2.2 The official server (`examples/server`, binary `whisper-server`)

**Start-up flags and defaults** ([server README](https://github.com/ggml-org/whisper.cpp/blob/master/examples/server/README.md), [server.cpp](https://github.com/ggml-org/whisper.cpp/blob/master/examples/server/server.cpp)):

- `--host 127.0.0.1`, `--port 8080`, `--inference-path /inference`, `--request-path ""`
- `-m models/ggml-base.en.bin`
- `-l en`. The default is English; `'auto' for auto-detect`.
- `-t/--threads` (up to 4), `-p/--processors 1`
- `--convert` ("requires ffmpeg on the server") with `--tmp-dir`
- `--vad`, `-vm/--vad-model`, `-vt 0.50`, `-vspd 250`, `-vsd 100`
- `-fa/--flash-attn`
- `-dtw <preset>`, where the presets include `large.v3.turbo`
- `-nth/--no-speech-thold 0.60`
- `read_timeout` and `write_timeout` are both 600 s

```bash
./build/bin/whisper-server -m models/ggml-large-v3-turbo-q5_0.bin -l auto -t 8 \
  --host 127.0.0.1 --port 8080 --vad -vm models/ggml-silero-v6.2.0.bin
# VAD model: ./models/download-vad-model.sh silero-v6.2.0   (README)
```

**Endpoints** ([server.cpp](https://github.com/ggml-org/whisper.cpp/blob/master/examples/server/server.cpp)):

- `POST {request_path}{inference_path}`: multipart. The `file` part is required (HTTP 400 if it is missing).
- `POST {request_path}/load`: multipart field `model` holding a server-side model path.
- Also unauthenticated: `GET {request_path}/health` (503 `{"status":"loading model"}` while loading) and `GET {request_path}/` plus static files from the public folder (corrected by verification: server.cpp).
- The README calls the server "HTTP transcription server with OAI-like API". Setting `--inference-path /v1/audio/transcriptions` mimics the OpenAI path.

**Multipart fields read per request** (`get_req_parameters` in [server.cpp](https://github.com/ggml-org/whisper.cpp/blob/master/examples/server/server.cpp)):

| Kind | Fields |
|---|---|
| String | `language` (`auto` accepted), `prompt`, `response_format` |
| Boolean | `translate`, `diarize`, `tinydiarize`, `split_on_word`, `no_timestamps`, `token_timestamps` (default **true**), `detect_language`, `carry_initial_prompt`, `suppress_non_speech`/`suppress_nst`, `vad`, `no_language_probabilities`, `debug_mode` |
| Numeric | `offset_t`, `offset_n`, `duration`, `max_context`, `max_len`, `best_of`, `beam_size`, `audio_ctx`, `word_thold`, `entropy_thold`, `logprob_thold`, `no_speech_thold`, `temperature`, `temperature_inc`, `vad_threshold`, `vad_min_speech_duration_ms`, `vad_min_silence_duration_ms`, `vad_max_speech_duration_s`, `vad_speech_pad_ms`, `vad_samples_overlap` |

`no_context` is not read from requests; it defaults to `true`.

**`response_format` values** ([server.cpp](https://github.com/ggml-org/whisper.cpp/blob/master/examples/server/server.cpp)):

| Value | Response |
|---|---|
| `json` (default) | `{"text": …}`, plus `language` when `detect_language` is set |
| `text` | text/html |
| `srt` | `application/x-subrip` |
| `vtt` | `text/vtt` |
| `verbose_json` | see below |

`verbose_json` shape:

```text
{ task:"transcribe"|"translate", language, duration, text,
  detected_language?, detected_language_probability?, language_probabilities?,   // unless no_language_probabilities
  segments:[{ id, text, start, end,            // seconds (centiseconds * 0.01); omitted if no_timestamps
              tokens:[…], words:[{ word, start, end, t_dtw, probability }],      // start/end only with token timestamps
              temperature, avg_logprob, no_speech_prob,
              speaker? }] }                    // "0"|"1"|"?" only when diarize=true AND input has 2 channels
```

**Errors:** `{"error":"..."}` with HTTP 400 (bad input), 500 (`failed to process audio` or `FFmpeg conversion failed.`) or 499 (`client disconnected`).

**Concurrency and security** ([server.cpp](https://github.com/ggml-org/whisper.cpp/blob/master/examples/server/server.cpp), [server README](https://github.com/ggml-org/whisper.cpp/blob/master/examples/server/README.md)):

- A single `std::mutex whisper_mutex` serialises every inference and every `/load` call.
- There is **no authentication**; only a CORS header allows `authorization`.
- The README warns against running the server with admin rights. It advises running it in a sandbox and validating uploads.

**Audio decoding**

- Without `--convert`, uploads go to `read_audio_data()` from memory, which uses miniaudio with `stb_vorbis` compiled in ([common-whisper.cpp](https://github.com/ggml-org/whisper.cpp/blob/master/examples/common-whisper.cpp)).
- miniaudio decodes "WAV, FLAC, and MP3" natively ([miniaudio README](https://github.com/mackron/miniaudio/blob/master/README.md)). whisper.cpp also compiles in `stb_vorbis` (`#include "stb_vorbis.c" /* Enables Vorbis decoding. */`), so Ogg **Vorbis** decodes natively too (corrected by verification: common-whisper.cpp).
- **Opus needs `--convert` plus ffmpeg.** Opus is not among the built-in decoders (miniaudio supports Opus only through an external libopus decoder in its extras), so this is an inference from those sources.
- Audio is resampled to 16 kHz by the decoder config. On the mono path both channels are **summed**.

**VAD timestamps.** whisper.cpp keeps a `vad_time_mapping` table to map VAD-trimmed time back to the original timeline (commits "#3173 vad: revisit timestamp alignment/mapping" and "#3711 fix … drift"). I only saw this through secondary commit mirrors, so it is **medium confidence**. Verify with a test file that has a known offset.

```bash
curl -s https://whisper.example/inference -H "Authorization: Bearer $PROXY_TOKEN" \
  -F file=@client.wav -F response_format=verbose_json -F language=auto \
  -F temperature=0.0 -F temperature_inc=0.2 -F vad=true \
  -F prompt="Glossary: Acme, Rehaan, Contoso." -F carry_initial_prompt=true -F no_language_probabilities=true
```

### 2.3 Models and resources

| File | Size | Use |
|---|---|---|
| `ggml-large-v3-turbo.bin` | 1.62 GB | GPU, or when CPU RAM is plentiful |
| `ggml-large-v3-turbo-q8_0.bin` | 874 MB | Default for a CPU box |
| `ggml-large-v3-turbo-q5_0.bin` | 574 MB | Low RAM |

Sizes are from [HF](https://huggingface.co/ggerganov/whisper.cpp/tree/main).

**Memory and CPU**

- The README memory table lists "large: 2.9 GiB disk, ~3.9 GB mem" (no quantisation given) ([README](https://github.com/ggml-org/whisper.cpp/blob/master/README.md)).
- No figure is published for turbo. **Estimate (UNVERIFIED):** the model file size plus roughly 0.5–1 GB of working buffers. For example, q5_0 needs about 1.5 GB RAM.
- Measure CPU real-time factor on the owner's box with `examples/bench` before choosing quantisation.

### 2.4 Two channels with a mono model

- **Recommended (A): split, transcribe, merge.**
  - Send `me` and `client` as separate mono files. Two requests, or N chunk requests per channel.
  - Tag the results with the channel, then merge by `start_ms`.
  - Overlapping speech stays intact.
  - Use `vad=true`, plus `no_speech_thold` and `suppress_nst`, to curb hallucinations during the long silences in each split channel.
- **Shortcut (B): a single stereo request with `diarize=true`.**
  - The server sums L+R for recognition.
  - It labels each **segment** `"0"` or `"1"` when one channel's summed amplitude beats the other by more than 10%; otherwise `"?"` ([server.cpp](https://github.com/ggml-org/whisper.cpp/blob/master/examples/server/server.cpp) `estimate_diarization_speaker`).
  - Labels are per segment only, and crosstalk gets mixed, so this option is not suitable for "accuracy #1".

### 2.5 On-device options

**whisper-rs 0.16.0** (2026-03-12, **Unlicense**; the repo moved to codeberg.org/tazz4843/whisper-rs) ([crates.io](https://crates.io/crates/whisper-rs)). API checked on docs.rs ([WhisperContext](https://docs.rs/whisper-rs/0.16.0/whisper_rs/struct.WhisperContext.html), [WhisperContextParameters](https://docs.rs/whisper-rs/0.16.0/whisper_rs/struct.WhisperContextParameters.html), [FullParams](https://docs.rs/whisper-rs/0.16.0/whisper_rs/struct.FullParams.html), [WhisperState](https://docs.rs/whisper-rs/0.16.0/whisper_rs/struct.WhisperState.html), [WhisperSegment](https://docs.rs/whisper-rs/0.16.0/whisper_rs/struct.WhisperSegment.html)):

```rust
let ctx = WhisperContext::new_with_params(model_path, WhisperContextParameters::default())?;
let mut state = ctx.create_state()?;
let mut p = FullParams::new(SamplingStrategy::Greedy { best_of: 1 });
p.set_language(Some("auto")); p.set_token_timestamps(true); p.set_initial_prompt("Glossary: Acme, Rehaan.");
state.full(p, &pcm_f32_16k_mono)?;                 // -> Result<(), WhisperError>
for i in 0..state.full_n_segments() {              // c_int
    let s = state.get_segment(i).unwrap();
    let (t0_ms, t1_ms) = (s.start_timestamp() * 10, s.end_timestamp() * 10); // centiseconds -> ms
    let text = s.to_str_lossy()?;
}
```

**Android JNI** ([whisper.android README](https://github.com/ggml-org/whisper.cpp/tree/master/examples/whisper.android), [LibWhisper.kt](https://github.com/ggml-org/whisper.cpp/blob/master/examples/whisper.android/lib/src/main/java/com/whispercpp/whisper/LibWhisper.kt)):

- `examples/whisper.android` is an example app, not a published library. Package `com.whispercpp.whisper`.
- API:
  - `WhisperContext.createContextFromFile(path)`
  - `suspend fun transcribeData(data: FloatArray, printTimestamp: Boolean = true): String`
- Native library selection: loads `whisper_v8fp16_va`, `whisper_vfpv4` or `whisper` depending on CPU features.
- The README recommends **tiny or base** models on Android.
- No official Maven artifact exists (**UNVERIFIED**: none found).
- GiviMAD/whisper-jni is listed as a Java binding; its Android support is **UNVERIFIED**.

---

## 3. sherpa-onnx + NVIDIA Parakeet TDT 0.6B v3

### 3.1 Versions, licences, model package

**sherpa-onnx v2.0.0** (published **2026-10-23**, the day before this note; **Apache-2.0**) ([release API](https://api.github.com/repos/k2-fsa/sherpa-onnx/releases/latest), [license API](https://api.github.com/repos/k2-fsa/sherpa-onnx/license)):

- Assets include `sherpa-onnx-2.0.0.aar` and `sherpa-onnx-static-link-onnxruntime-2.0.0.aar`.
- The release notes carry no "breaking" label. They mention that JNI now converts C++ exceptions into Java exceptions ([release](https://github.com/k2-fsa/sherpa-onnx/releases/tag/v2.0.0)).
- JitPack coordinates, per the repo's `jitpack.yml`: `com.github.k2-fsa:sherpa-onnx:2.0.0` (aar) ([jitpack.yml](https://github.com/k2-fsa/sherpa-onnx/blob/master/jitpack.yml)).

**Model: `nvidia/parakeet-tdt-0.6b-v3`** ([HF model card](https://huggingface.co/nvidia/parakeet-tdt-0.6b-v3)):

- Licence: **CC-BY-4.0**, "ready for commercial/non-commercial use". Attribution is required: "You must give appropriate credit, provide a link to the license, and indicate if changes were made" ([CC BY 4.0](https://creativecommons.org/licenses/by/4.0/)).
- About 600M parameters.
- **25 languages:** bg, hr, cs, da, nl, en, et, fi, fr, de, el, hu, it, lv, lt, mt, pl, pt, ro, sk, sl, es, sv, ru, uk.
- Automatic punctuation and capitalisation, word- and segment-level timestamps (in NeMo), and automatic language detection.
- Handles up to 24 minutes with full attention, or 3 hours with local attention (NeMo on an A100).
- Input: 16 kHz mono.

**sherpa-onnx package** ([k2-fsa NeMo models](https://k2-fsa.github.io/sherpa/onnx/pretrained_models/offline-transducer/nemo-transducer-models.html)):

- Download: `https://github.com/k2-fsa/sherpa-onnx/releases/download/asr-models/sherpa-onnx-nemo-parakeet-tdt-0.6b-v3-int8.tar.bz2`
- Contents: `encoder.int8.onnx` 622M, `decoder.int8.onnx` 12M, `joiner.int8.onnx` 6.1M, `tokens.txt` 92K.
- Decode with `--model-type=nemo_transducer`. The sample run reports RTF 0.325 on unstated hardware.
- Sample output: tokens carry a **leading space at word starts** (`" A"`, `"sk"`, `" not"`, …), `timestamps` are in seconds, and `"words": []` is empty. You must build words yourself.

### 3.2 Kotlin / Android offline API

API checked in [OfflineRecognizer.kt](https://github.com/k2-fsa/sherpa-onnx/blob/master/sherpa-onnx/kotlin-api/OfflineRecognizer.kt), [OfflineStream.kt](https://github.com/k2-fsa/sherpa-onnx/blob/master/sherpa-onnx/kotlin-api/OfflineStream.kt), [FeatureConfig.kt](https://github.com/k2-fsa/sherpa-onnx/blob/master/sherpa-onnx/kotlin-api/FeatureConfig.kt) and [Vad.kt](https://github.com/k2-fsa/sherpa-onnx/blob/master/sherpa-onnx/kotlin-api/Vad.kt):

- Package `com.k2fsa.sherpa.onnx`; native library `System.loadLibrary("sherpa-onnx-jni")`.
- The official helper's case `40` builds exactly this config for `sherpa-onnx-nemo-parakeet-tdt-0.6b-v3-int8` with `modelType = "nemo_transducer"`.
- When `assetManager` is null the constructor calls `newFromFile(config)`. Download the 640 MB model to app-private storage instead of bundling it in the APK.

```kotlin
import com.k2fsa.sherpa.onnx.*
val d = File(modelRoot, "sherpa-onnx-nemo-parakeet-tdt-0.6b-v3-int8").path   // modelRoot = app-private dir (choice of Android dir not verified here)
val recognizer = OfflineRecognizer(assetManager = null, config = OfflineRecognizerConfig(
    featConfig = FeatureConfig(sampleRate = 16000, featureDim = 80),   // NeMo impl overrides feature_dim from model metadata
    modelConfig = OfflineModelConfig(
        transducer = OfflineTransducerModelConfig(encoder = "$d/encoder.int8.onnx",
            decoder = "$d/decoder.int8.onnx", joiner = "$d/joiner.int8.onnx"),
        tokens = "$d/tokens.txt", numThreads = 4, provider = "cpu", modelType = "nemo_transducer"),
    decodingMethod = "greedy_search"))
val vad = Vad(assetManager = null, config = VadModelConfig(
    sileroVadModelConfig = SileroVadModelConfig(model = "$models/silero_vad.onnx", threshold = 0.5f,
        minSilenceDuration = 0.25f, minSpeechDuration = 0.25f, windowSize = 512, maxSpeechDuration = 20f),
    sampleRate = 16000, numThreads = 1))

fun decodeSegment(seg: SpeechSegment, channel: Int): List<Word> {
    val s = recognizer.createStream()
    s.acceptWaveform(seg.samples, 16000)          // FloatArray in [-1,1], 16 kHz mono
    recognizer.decode(s)
    val r = recognizer.getResult(s)               // text, tokens: Array<String>, timestamps: FloatArray, durations: FloatArray
    s.release()
    val offsetMs = seg.start * 1000 / 16000       // SpeechSegment.start = absolute sample index (Long)
    return tokensToWords(r.tokens, r.timestamps, r.durations, offsetMs, channel)
}
// loop: vad.acceptWaveform(chunk); while (!vad.empty()) { decodeSegment(vad.front(), ch); vad.pop() }; vad.flush(); drain; vad.reset()
```

**Source details behind that code**

- `FeatureConfig` defaults are `sampleRate=16000`, `featureDim=80` and `dither=0` ([FeatureConfig.kt](https://github.com/k2-fsa/sherpa-onnx/blob/master/sherpa-onnx/kotlin-api/FeatureConfig.kt)). The NeMo recognizer sets `feature_dim` from `model_->FeatureDim()` when that value is positive ([offline-recognizer-transducer-nemo-impl.h](https://github.com/k2-fsa/sherpa-onnx/blob/master/sherpa-onnx/csrc/offline-recognizer-transducer-nemo-impl.h)).
- `SpeechSegment.start` is "Start index, in input samples … an absolute position in the input stream". `timestamps` are "token timestamps in seconds … parallel to tokens"; `durations` are token durations in seconds ([c-api.h](https://github.com/k2-fsa/sherpa-onnx/blob/master/sherpa-onnx/c-api/c-api.h)). The Kotlin result marks `durations` "valid only for TDT models" ([OfflineRecognizer.kt](https://github.com/k2-fsa/sherpa-onnx/blob/master/sherpa-onnx/kotlin-api/OfflineRecognizer.kt)).

**Long audio needs VAD.** sherpa-onnx documents `sherpa-onnx-vad-with-offline-asr --silero-vad-model=./silero_vad.onnx --model-type=nemo_transducer …` "to decode a very long audio file with the help of VAD" ([k2-fsa NeMo models](https://k2-fsa.github.io/sherpa/onnx/pretrained_models/offline-transducer/nemo-transducer-models.html)).

**Keyterms (hotwords)**

- The Kotlin config has `hotwordsFile` and `hotwordsScore` (default 1.5f), plus `decodingMethod`.
- The NeMo implementation passes `hotwords_score` and `IsTDT()` to the modified-beam-search decoder.
- Hotwords with Parakeet TDT are therefore *plausible* but **UNVERIFIED**: there is no documentation and no test.

### 3.3 Rust bindings

**Official crate `sherpa-onnx` 2.0.0** (Apache-2.0, published 2026-10-23) ([crates.io](https://crates.io/crates/sherpa-onnx), [docs.rs](https://docs.rs/sherpa-onnx/2.0.0/sherpa_onnx/)):

- `OfflineRecognizer::create(&OfflineRecognizerConfig) -> Option<Self>`
- `create_stream()` and `create_stream_with_hotwords(&str)`
- `decode(&OfflineStream)`
- `OfflineTransducerModelConfig { encoder, decoder, joiner: Option<String> }`
- `OfflineModelConfig { tokens: Option<String>, num_threads: i32, provider: Option<String>, model_type: Option<String>, … }`
- `OfflineRecognizerResult { text, tokens: Vec<String>, timestamps: Option<Vec<f32>>, durations: Option<Vec<f32>> }`

**Linking:** static by default. The build script downloads prebuilt libraries unless `SHERPA_ONNX_LIB_DIR` or `SHERPA_ONNX_ARCHIVE_DIR` is set; the `shared` feature switches to dynamic linking.

```rust
let mut c = OfflineRecognizerConfig::default();
c.model_config.transducer.encoder = Some(format!("{d}/encoder.int8.onnx"));
c.model_config.transducer.decoder = Some(format!("{d}/decoder.int8.onnx"));
c.model_config.transducer.joiner  = Some(format!("{d}/joiner.int8.onnx"));
c.model_config.tokens = Some(format!("{d}/tokens.txt"));
c.model_config.model_type = Some("nemo_transducer".into());
let rec = OfflineRecognizer::create(&c).expect("recognizer");
let st = rec.create_stream(); st.accept_waveform(16000, &samples); rec.decode(&st);
let r = st.get_result().expect("result");   // arg order per crate example: accept_waveform(sample_rate, samples)
```

**Community crate `sherpa-rs` 0.6.8** (MIT): last release 2025-10-05, so it lags upstream ([crates.io](https://crates.io/crates/sherpa-rs)). Prefer the official crate.

### 3.4 Server mode

- `sherpa-onnx-offline-websocket-server`: `--port` (default 6006), `--num-work-threads` (3), `--max-batch-size` (5), `--encoder/--decoder/--joiner/--tokens`. It only accepts `--key=value` syntax ([offline-websocket doc](https://k2-fsa.github.io/sherpa/onnx/websocket/offline-websocket.html)).
- On that page, NeMo `--model-type`, the wire protocol and the response JSON are **UNVERIFIED**: none of them are shown.
- There is no plain HTTP upload endpoint documented, so this is not recommended for v1.

### 3.5 Turning tokens into words (sherpa-onnx; also useful for whisper `tokens`)

```text
for i in tokens:
  start a new word if token starts with " " (or i == 0); append token text otherwise
  word.start = offset + timestamps[first]
  word.end   = offset + (durations? timestamps[last] + durations[last] : timestamps[next_word_first] or segment_end)
trim the leading space; attach channel; confidence = null
```

---

## 4. Proposed common transcript shape (`echoledger.transcript.v1`)

```json
{
  "schema": "echoledger.transcript.v1",
  "provider": "azure_mai",
  "engine": {"name": "MAI-Transcribe-2", "runtime": "azure-fast-transcription", "api_version": "2025-10-15"},
  "language": "en", "duration_ms": 185079,
  "channels": [{"index": 0, "role": "me", "label": "Me"}, {"index": 1, "role": "client", "label": "Client"}],
  "segments": [
    {"channel": 1, "speaker": "Client", "speaker_index": null, "start_ms": 4480, "end_ms": 6080,
     "text": "Hi, my name is Mary Rondo.", "confidence": 0.899, "language": "en-US",
     "words": [{"text": "Hi,", "start_ms": 4480, "end_ms": 4880, "confidence": null}]}
  ],
  "warnings": []
}
```

**Mapping each engine into the shape**

| Engine | segment | start_ms / end_ms | words | channel / speaker |
|---|---|---|---|---|
| Azure fast (per-channel mono file) | one per `phrases[]` entry | `offsetMilliseconds` / `+durationMilliseconds`, plus the chunk offset | `words[].text`, offset, duration | channel = the file we sent. `speaker` = channel label; if diarization was on, use `"Client " + (phrases.speaker+1)` |
| Azure fast (standard model, stereo `channels:[0,1]`) | same | same | same | `phrases[].channel` |
| whisper-server `verbose_json` | `segments[]` | `round(start*1000)` / `round(end*1000)`, plus the chunk offset | `words[].word` (trim), `start`/`end` × 1000, `probability` | channel = the file we sent |
| sherpa-onnx Parakeet | one per VAD segment, re-split at long pauses | first token / last word end | §3.5 | channel = the PCM stream decoded |

**Merge:** sort by `(start_ms, channel)`. Treat `confidence == 0` from Azure as `null`.

**Cross-note conflict (flagged by verification):** this shape uses a 0-based `channel` (0 = Me, 1 = Client) and `start_ms`/`end_ms`. `stt-assemblyai.md` R8 stores a 1-based `ch` (1 = Me) with `s`/`e` in ms, and the player sketch in `web-laravel-filament.md` §3 reads `start`/`end` in seconds. Pick one convention before building.

**Re-segmenting.** MAI segment granularity is "partitioned into segments by language and speaker" ([mai-transcribe](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/mai-transcribe)), so one phrase may span minutes. Re-split words into display segments: break when the gap is ≥ 700 ms, at a sentence-final mark once the segment is longer than 1.5 s, and always by 15 s.

---

## Recommendations for EchoLedger

1. **Give `SttProvider` three completion modes.** Do not assume webhooks.
   - `async_webhook`: assemblyai, elevenlabs.
   - `sync`: azure_mai, whispercpp_local. The job receives the transcript inline; there is no webhook and no `webhook_events` row.
   - `device`: parakeet_local. The server never runs inference.

   Expose these as `capabilities()`: `mode`, `native_multichannel`, `formats`, `max_seconds_per_request`, `keyterms`, `live_token`. `TranscribeFinalJob` branches on the mode.

2. **`azure_mai`: run fast transcription from the queued job, one request per channel.**
   - Use `MAI-Transcribe-2`, `modelOptions.timestamps="word"`, `transcribeStyle="verbatim"`, `profanityFilterMode="None"`, and `phraseList.phrases` filled with the client's keyterms. Omit `locales` unless the client has a configured language; then send one bare code such as `"en"`.
   - Run the two channel requests in sequence inside one job. Map each into the common shape (§4), merge, and store the final transcript.
   - Remote deletion is a no-op: fast transcription is not retained ([data privacy](https://learn.microsoft.com/en-us/azure/ai-foundry/responsible-ai/speech-service/speech-to-text/data-privacy-security)). Record that fact in PROVIDERS.md.
   - Job settings: `$timeout = 900`, `$tries = 3` with backoff. The scheduler must run `queue:work --stop-when-empty --timeout=900`, because the default is `--timeout=60` (`WorkCommand.php`). Set `DB_QUEUE_RETRY_AFTER` above 900, because the default is 90 (`web/config/queue.php`).
   - The worker timeout is only enforced when the `pcntl` extension is loaded (`Worker::supportsAsyncSignals`). Shared hosting often lacks it, so also bound every HTTP call with `->timeout()`.
   - Add a setting `azure.model = MAI-Transcribe-2 | standard`. **Standard** sends one stereo request with `channels:[0,1]`, accepts Opus/Ogg, and can take `locales:["en-US"]` plus a phrase list. Use it when only a stereo Opus file exists.
   - Choose a resource region from the MAI list (§1.2). `centralindia` is the closest to South Asia.
   - Recommendation 7 lists the credentials to store.

3. **`whispercpp_local`: POST to an owner-hosted `whisper-server`.**
   - **Never expose `whisper-server` directly.** It has no auth and handles one request at a time. Put it behind HTTPS on a reverse proxy that checks `Authorization: Bearer <secret>`; the driver sends that header with `Http::withToken()`.
   - Start the server with `-l auto --vad -vm ggml-silero-v6.2.0.bin` and the `large-v3-turbo-q5_0` or `-q8_0` model.
   - Each channel is a 16 kHz mono **WAV**. The PHP job slices it into chunks of about 10 minutes; PCM slicing is just a header plus byte ranges, with no decoding. Keep a 2 s overlap and drop words from the later chunk that start before `boundary + 1 s`.
   - Each request sets `response_format=verbose_json`, `language=auto` (or the client language), `temperature=0.0`, `temperature_inc=0.2`, `vad=true`, `prompt="Glossary: <keyterms>"`, `carry_initial_prompt=true` and `no_language_probabilities=true`.
   - Keep chunks short so each request finishes in under about 2 minutes. Tunnels and proxies time out: Cloudflare returns 524 after "the default 125 seconds" ([Cloudflare 524](https://developers.cloudflare.com/support/troubleshooting/http-status-codes/cloudflare-5xx-errors/error-524/)).
   - Persist per-chunk results so retries resume. A failure sets the session to `failed` with the server's `{"error"}` text.
   - Future option: a pull-mode agent on the owner's PC claims jobs over the API, avoiding inbound NAT and long requests. It would reuse the device-upload path.

4. **`parakeet_local`: transcribe on the device, upload the transcript.**
   - `TranscribeFinalJob` performs no network calls. It marks the recording `awaiting_device_transcript`.
   - The device (Android with the sherpa-onnx AAR, or desktop with the official Rust crate):
     1. Decodes each channel to 16 kHz float PCM.
     2. Runs Silero VAD, then `OfflineRecognizer` (`nemo_transducer`).
     3. Builds words (§3.5).
     4. Uploads `echoledger.transcript.v1` to a new endpoint, e.g. `POST /api/recordings/{id}/transcript`.
   - The server validates the schema and timing (`0 ≤ start ≤ end ≤ duration + 500 ms`, `channel ∈ {0,1}`) and stores the transcript idempotently.
   - Keep a configurable cloud fallback for recordings whose device has no engine, such as web uploads.
   - Ship the model as a first-run download into app-private storage (excluded from backups), verified by SHA-256. I did not verify which Android directory API to use. Show the CC-BY-4.0 attribution in About/Licenses.
   - **Flag in the UI that Parakeet covers 25 European languages only.**

5. **Live captions on Azure (`POST /api/stt/token`).**
   - Laravel mints an STS token from `https://{region}.api.cognitive.microsoft.com/sts/v1.0/issueToken`.
   - Response: `{provider:"azure", token, region, expires_in:600, refresh_after:540, phrases:[client keyterms], profanity:"raw", model:null}`. The `model` field becomes `"mai-transcribe-2-streaming"` behind an "experimental" flag once tested.
   - Devices use the Speech SDK (JS in the Tauri webview; `client-sdk` 1.53.0 on Android). They call `fromAuthorizationToken`, `PhraseListGrammar` and `setProfanity(Raw)`, and refresh the token on the recognizer every 9 minutes.
   - Desktop must call `Recognizer.enableTelemetry(false)`.
   - Default to captioning **only the Client channel**; each channel needs its own recognizer and is billed separately. Make the Me channel optional.

6. **Audio contract for final transcripts.**
   - Upload per-channel mono **16 kHz FLAC or WAV** (`me.*`, `client.*`), aligned to the same t0.
   - That is the common subset of MAI (WAV/MP3/FLAC) and `whisper-server` without ffmpeg (WAV/FLAC/MP3, plus Ogg Vorbis (corrected by verification: common-whisper.cpp)).
   - Cross-note difference: `stt-assemblyai.md` R5 uploads one stereo Ogg/Opus file and `stt-elevenlabs.md` R3 one stereo FLAC, while this contract uses per-channel mono files. docs/API.md settles this: a stereo `primary` artifact plus optional mono `channel_1`/`channel_2` files for providers that need them.
   - Opus can stay as the playback/storage format, but MAI and whisper cannot ingest it, and shared hosting cannot transcode it.

7. **Credentials** (encrypted): Azure `key`, `region`, `endpoint` (`https://{resource}.cognitiveservices.azure.com`), `model`; whisper `base_url`, `bearer_token`, `language`, `chunk_seconds`.

8. **Pest tests (`Http::fake`):**
   - The Azure request is multipart, its `definition.enhancedMode.model == "MAI-Transcribe-2"`, and the key appears only in the header.
   - Docs sample JSON maps to `echoledger.transcript.v1` with correct ms math.
   - The two-channel merge orders segments correctly.
   - The token endpoint response never contains the key.
   - whisper `verbose_json` maps correctly and chunk-offset de-duplication works.
   - Device transcript validation rejects out-of-range timing.

---

## Gotchas

1. **MAI-Transcribe-2 has no channel separation** (❌), and stereo input is merged. The `Me`/`Client` labels are only free if each channel is sent separately.
2. **MAI accepts only WAV, MP3 or FLAC.** Standard fast transcription also accepts Opus/Ogg.
3. **MAI `modelOptions.timestamps` defaults to `"none"`.** Without `"word"` there is no timing for the synced player.
4. **MAI `locales` takes exactly one bare code** (`"en"`). Forcing it disables auto-detect, which hurts code-switched calls.
5. **`profanityFilterMode` defaults to `Masked`** in fast transcription, and SDK profanity is masked by default too. Set `None` / `ProfanityOption.Raw` for verbatim accuracy.
6. **The documented duration and size limits conflict** (2 h / 250 MB vs 5 h / 500 MB). Design for the smaller pair.
7. **Batch transcription has webhooks but no phrase lists**, and MAI is not documented there. Event names also differ between the REST reference (`transcriptionCompletion`) and the how-to page (`transcriptionSucceeded`).
8. **STS tokens last 10 minutes and are scoped to the host that issued them.** Recognizers do not pick up a refreshed token from SpeechConfig; you must set it on the recognizer.
9. **`setModel` needs Speech SDK ≥ 1.52.** The Java reference pages still show 1.51 without `setModel`.
10. **The MAI streaming Realtime API and Voice Live accept only `api-key` or Entra** auth. Never send either to a device.
11. **MAI pricing is a promotion** that ends 2026-12-31 (MAI-Transcribe-2 via fast transcription $0.10/h, streaming $0.54/h; MAI is not used through batch, §1.6) (corrected by verification: microsoft.ai launch posts). Show the current price as configurable, not hard-coded.
12. **whisper-server** defaults:
    - It defaults to `-l en`; pass `language=auto` per request or start it with `-l auto`.
    - It binds 127.0.0.1:8080, has no auth, and serialises requests.
    - Opus input requires `--convert` and ffmpeg.
13. **Split channels contain long silences, and Whisper hallucinates on silence.** Enable VAD and keep `no_speech_thold`.
14. **whisper.cpp v1.9.5 lists only 2 assets**, so build from source. `whisper-rs` moved to Codeberg, and its timestamps are centiseconds.
15. **Parakeet v3 limits:**
    - Its 25 languages exclude Urdu, Hindi and Arabic.
    - The encoder alone is 622 MB.
    - Use VAD for long audio.
    - `"words"` comes back empty, so derive words from tokens with leading spaces.
16. **sherpa-onnx 2.0.0 is one day old.** Pin it, and re-check the Kotlin API against the tag you vendor; I read the `master` branch.
17. **Laravel defaults** are `Http` timeout 30 s, `queue:work --timeout=60` (needs `pcntl`) and DB `retry_after` 90. Long STT calls will be killed or duplicated unless all three are raised.
18. **Acoustic bleed.** The desktop mic can pick up the client's voice from the speakers, so it reappears on channel 0. Run a de-duplication pass: drop a Me segment when its text closely matches an overlapping Client segment.

---

## Load-bearing claims

| # | Claim | Source | Confidence |
|---|---|---|---|
| 1 | MAI-Transcribe-2 is called as `POST {endpoint}/speechtotext/transcriptions:transcribe?api-version=2025-10-15` (multipart `audio` + `definition`, header `Ocp-Apim-Subscription-Key`) with `enhancedMode.enabled=true` and `enhancedMode.model="MAI-Transcribe-2"`. | [mai-transcribe](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/mai-transcribe), [REST](https://learn.microsoft.com/en-us/rest/api/speechtotext/transcriptions/transcribe) | high |
| 2 | MAI-Transcribe-2 does not support stereo channel separation, so send one request per channel. | [llm-speech](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/llm-speech) | high |
| 3 | MAI accepts WAV, MP3 or FLAC. Word timing requires `enhancedMode.modelOptions.timestamps="word"` (default `none`). Keyterms go in `phraseList.phrases`. | [mai-transcribe](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/mai-transcribe) | high |
| 4 | The response has `phrases[]` with `offsetMilliseconds`, `durationMilliseconds`, `words[]`, plus `channel` and `speaker` only when channel separation / diarization are enabled. | [REST](https://learn.microsoft.com/en-us/rest/api/speechtotext/transcriptions/transcribe) | high |
| 5 | Fast transcription is synchronous and Microsoft does not retain its data, so `azure_mai` needs no webhook and no remote delete. | [REST](https://learn.microsoft.com/en-us/rest/api/speechtotext/transcriptions/transcribe), [data privacy](https://learn.microsoft.com/en-us/azure/ai-foundry/responsible-ai/speech-service/speech-to-text/data-privacy-security) | high (MAI named implicitly: medium) |
| 6 | STS tokens come from `https://{region}.api.cognitive.microsoft.com/sts/v1.0/issueToken`, are valid 10 minutes, and are used with `SpeechConfig.fromAuthorizationToken(token, region)`; refresh them on the recognizer. | [REST short audio](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/rest-speech-to-text-short), [JS SpeechConfig](https://learn.microsoft.com/en-us/javascript/api/microsoft-cognitiveservices-speech-sdk/speechconfig) | high |
| 7 | Real-time phrase list: `PhraseListGrammar.fromRecognizer(r).addPhrase(s)`, weight 0–2, up to 2,000 phrases recommended. | [phrase list doc](https://learn.microsoft.com/en-us/azure/ai-services/speech-service/improve-accuracy-phrase-list), [PhraseListGrammar](https://learn.microsoft.com/en-us/javascript/api/microsoft-cognitiveservices-speech-sdk/phraselistgrammar) | high |
| 8 | whisper-server takes `POST /inference` multipart (`file`, `response_format=verbose_json`, `language`, `prompt`, `vad`, …) and returns `segments[].start/end` in seconds with `words[]`. It has no auth and serialises requests. | [server.cpp](https://github.com/ggml-org/whisper.cpp/blob/master/examples/server/server.cpp) | high |
| 9 | whisper-server decodes WAV, FLAC, MP3 and Ogg Vorbis natively (corrected by verification: common-whisper.cpp); Opus needs `--convert` plus ffmpeg. | [common-whisper.cpp](https://github.com/ggml-org/whisper.cpp/blob/master/examples/common-whisper.cpp), [miniaudio](https://github.com/mackron/miniaudio/blob/master/README.md), [server README](https://github.com/ggml-org/whisper.cpp/blob/master/examples/server/README.md) | medium |
| 10 | sherpa-onnx Kotlin runs `OfflineRecognizer` with `OfflineTransducerModelConfig(encoder, decoder, joiner)` and `modelType="nemo_transducer"` for `sherpa-onnx-nemo-parakeet-tdt-0.6b-v3-int8`. The result gives `tokens` and `timestamps` (seconds). | [OfflineRecognizer.kt](https://github.com/k2-fsa/sherpa-onnx/blob/master/sherpa-onnx/kotlin-api/OfflineRecognizer.kt), [c-api.h](https://github.com/k2-fsa/sherpa-onnx/blob/master/sherpa-onnx/c-api/c-api.h) | high |
| 11 | Parakeet TDT 0.6B v3 is CC-BY-4.0 and covers 25 European languages; sherpa-onnx is Apache-2.0; whisper.cpp is MIT. | [HF nvidia](https://huggingface.co/nvidia/parakeet-tdt-0.6b-v3), [GitHub license APIs](https://api.github.com/repos/k2-fsa/sherpa-onnx/license) | high |
| 12 | MAI-Transcribe-2 costs $0.10 per audio hour only until the end of 2026; the regular price is unknown. | [microsoft.ai](https://microsoft.ai/news/mai-transcribe-2-is-the-fastest-most-accurate-and-cheapest-speech-recognition-model-in-the-world/) | medium |

---

## Open questions

1. **MAI with diarization:** what is the maximum length? The page refers to a "note on recording length" that did not render. This matters only if Client-side diarization is enabled.
2. **MAI with `timestamps:"word"`:** how coarse are `phrases[]`? Is it one phrase per language span? Test with a 10-minute file.
3. **MAI-Transcribe-2 status and price:** is it GA or preview, and what will it cost from 2027-01-01?
4. **Speech SDK with an STS token and `setModel("mai-transcribe-2-streaming")`:** does the combination work? Does `PhraseListGrammar` affect the MAI streaming model?
5. **MAI and `definition.audioUrl`:** is it honoured? If so, a short-lived signed Laravel URL would avoid pushing large uploads from PHP.
6. **Real fast-transcription limits** on our resource: 2 h / 250 MB or 5 h / 500 MB?
7. **Azure webhook signature encoding:** hex or base64? This only matters if the batch path is ever used.
8. **Billing for standard-model `channels:[0,1]`:** is each channel billed? The pricing page rendered only placeholders.
9. **whisper.cpp VAD timestamp remapping:** confirm it with an offset test. Also confirm what `parse_str_to_bool` accepts (`"true"` vs `"1"`).
10. **sherpa-onnx hotwords with NeMo TDT** (`modified_beam_search` + `hotwordsFile`): do they work? Also measure the on-device real-time factor and RAM for Parakeet int8 on the Samsung S25 Ultra, and check the AAR's `minSdk`.
11. **Owner call languages:** if they include Urdu, Hindi or Punjabi, Parakeet is ruled out. MAI-2 lists `ur` and `hi`, and `pa` only in Gurmukhi script. Whisper covers `ur` and `hi`. The October 2026 release notes also mention a new "multilingual speech-to-text model for Indic languages" in Fast Transcription that I did not evaluate.
12. **Android Speech SDK telemetry:** I did not find a telemetry opt-out for the Java SDK. Only JS `Recognizer.enableTelemetry(false)` was verified.
