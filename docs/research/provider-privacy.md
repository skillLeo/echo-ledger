# Speech providers: what they keep and whether they train on it

Checked on 2026-10-28 against each provider's own pages (linked below). Terms change; check again before relying on
this for a client contract. "Our copy" is the copy at the provider of a recording EchoLedger sent; EchoLedger always
keeps its own encrypted copy on the server.

## Summary

| Provider | Used in EchoLedger for | What the provider keeps | Trains on it? | What EchoLedger deletes | What the owner should switch on |
|---|---|---|---|---|---|
| AssemblyAI | Final transcripts (English first), live captions | Uploaded audio: deleted 24-48 h after upload. Transcripts: 30 days unless deleted or a shorter TTL is set | "Only certain files … as permitted by the applicable contract"; never with a BAA, on the EU servers, or after opting out | The transcript job (and with it the uploaded audio) right after the result is stored | Opt out of model training in the AssemblyAI dashboard, or choose **Settings → AssemblyAI → Data region: EU** |
| ElevenLabs Scribe | Final transcripts (Arabic first), Double-check, live captions | History is kept by default; deleted items stay in backups up to 30 days. Zero Retention Mode is for Enterprise only | Uses "certain data you provide" to improve its models unless the account opts out; Enterprise accounts are not trained on by default | The transcript right after the result is stored (since v1.2 also when Scribe answers in the same call), and each Double-check clip at once | Profile → **Terms and privacy → Data use** → turn off **Improve the models for everyone** |
| Whisper large-v3 on Groq | Final transcripts (Urdu first), Double-check | By default no inference data; logs may be kept up to 30 days for reliability and abuse monitoring | "Groq is not permitted to use Inputs or Outputs for training" (Services Agreement 4.2) | Nothing to delete (no file is stored) | Console → **Data Controls → Zero Data Retention** (open to all customers) |
| Soniox | Final transcripts (fallback for Urdu and Arabic) | Nothing, except audio and transcripts of the Async API, which are deleted automatically after 30 days | "Never used to improve Soniox models or services" | The transcription and its uploaded file right after the result is stored | Nothing |
| Azure Speech (fast transcription) | Final transcripts (optional) | "Microsoft does not retain or store the data" for fast transcription | Not addressed on the speech privacy page | Nothing to delete | Nothing |
| Claude API (Anthropic) | Planned: "Show as" Roman Urdu / English views (text only, no audio) | Inputs and outputs deleted within 30 days | "We do not use API data for training (unless you have an agreement with us that states otherwise)" | Nothing can be deleted ad hoc | Nothing |
| whisper.cpp, Parakeet | On the user's own machine or phone | Nothing leaves the device | No | — | — |

## Details and sources

### AssemblyAI
- Asynchronous (pre-recorded) audio uploaded without a TTL: "Deletion process begins at 24 hours and is at most 48
  hours." Final transcripts: "Deletion process begins at 30 days and is automatic thereafter." A TTL can be set down to
  1 hour; a transcript can be deleted at any time through the API.
- Training: "Only certain files submitted to the API, as permitted by the applicable contract, are used for model
  training." "We will not use files you submit for model training if you are subject to a Business Associate
  Addendum", if you use the European servers, or if you have opted out (opting out applies to new requests only).
- Streaming: zero data retention of audio and transcripts for customers who opted out of training.
- EchoLedger calls `DELETE /v2/transcript/{id}` after each result (this also removes the uploaded audio) and again
  when a recording is deleted; failures are retried by `stt:reconcile` for 7 days.
- Sources: [Data retention and model training](https://assemblyai.com/docs/data-retention-and-model-training),
  [Data controls](https://assemblyai.com/docs/data-controls), [Delete transcripts](https://assemblyai.com/docs/delete-transcripts).

### ElevenLabs Scribe
- ElevenLabs "uses certain data you provide to us to improve the quality of our audio models for everyone"; turning
  off **Improve the models for everyone** stops it for new data. "By default, we don't train on any data from our
  Enterprise customers."
- Zero Retention Mode (`enable_logging=false`, covers `/v1/speech-to-text/`) is for Enterprise customers only.
  Without it, deleting removes the audio and text from the database, but deleted items are "retained in backups for
  up to 30 days" and debugging and moderation logs may still hold data.
- EchoLedger deletes each Scribe transcript (`DELETE /v1/speech-to-text/transcripts/{id}`) as soon as the result is
  stored, after a webhook and (since v1.2) when Scribe answers in the same call; Double-check clips are deleted the
  same way right after they are heard.
- Sources: [Is my data used to improve ElevenLabs' AI models?](https://elevenlabs.io/docs/help-center/legal/is-my-data-used-to-improve-eleven-labs-ai-models),
  [Zero Retention Mode](https://elevenlabs.io/docs/eleven-api/resources/zero-retention-mode),
  [Privacy policy](https://elevenlabs.io/privacy-policy).

### Whisper large-v3 on Groq
- "By default, Groq does not retain customer data for inference requests." Logs for reliability and abuse monitoring
  "are retained for up to 30 days, unless legally required to retain longer." "All customers may enable Zero Data
  Retention (ZDR) in Data Controls settings"; audio transcription is eligible.
- Services Agreement 4.2: "Groq is not permitted to use Inputs or Outputs for training or fine-tuning any AI Model
  Services or other models" (unless the customer explicitly allows it).
- The audio travels with each request; there is no stored file for EchoLedger to delete.
- Sources: [Your data in GroqCloud](https://console.groq.com/docs/your-data),
  [Groq Services Agreement](https://console.groq.com/docs/legal/services-agreement).

### Soniox
- "Soniox does not store your audio or transcript data unless explicitly requested through a service that supports
  storage, i.e. Async API." "Audio and transcriptions stored via the Async API are automatically deleted after 30
  days." "Your audio and transcripts are never used to improve Soniox models or services."
- EchoLedger uses the Async API and deletes the transcription and its file right after the result is stored.
- Source: [Security and privacy](https://soniox.com/docs/stt/security-and-privacy).

### Azure Speech
- "When doing real-time speech to text, fast transcription, pronunciation assessment, and speech translation,
  Microsoft does not retain or store the data provided by customers." The page does not discuss training.
- Source: [Data, privacy, and security for Speech to text](https://learn.microsoft.com/legal/cognitive-services/speech-service/speech-to-text/data-privacy-security).

### Claude API (planned for "Show as")
- "For Anthropic API users, we automatically delete inputs and outputs on our backend within 30 days of receipt or
  generation" (exceptions: a separate agreement, the Files API, Usage Policy enforcement). "We do not use API data for
  training (unless you have an agreement with us that states otherwise)." Paid API customers cannot ask for ad hoc
  deletion.
- Only transcript text would be sent, never audio.
- Sources: [How long do you store my organization's data?](https://privacy.claude.com/en/articles/7996866-how-long-do-you-store-my-organization-s-data),
  [Can you delete data that I sent via API?](https://privacy.claude.com/en/articles/7996875-can-you-delete-data-that-i-sent-via-api).

## What this means for a client conversation
- With the three switches above (AssemblyAI opt-out or EU region, ElevenLabs "Improve the models" off, Groq Zero Data
  Retention on), no provider EchoLedger uses trains on the recordings, and no provider keeps a copy for more than 30
  days; most keep none after EchoLedger's delete.
- The encrypted recording and its transcript stay on the EchoLedger server for the retention period chosen in
  Settings (and 48 hours in Recently deleted after that).
