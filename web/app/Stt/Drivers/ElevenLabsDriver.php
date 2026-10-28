<?php

declare(strict_types=1);

namespace App\Stt\Drivers;

use App\Enums\CompletionMode;
use App\Enums\SttProviderKey;
use App\Models\Transcript;
use App\Services\CredentialVault;
use App\Services\ProviderCopyEraser;
use App\Services\SettingsRepository;
use App\Stt\Contracts\ClipTranscriber;
use App\Stt\Contracts\SttProvider;
use App\Stt\Data\FinalJobRequest;
use App\Stt\Data\NormalizedTranscript;
use App\Stt\Data\ProviderCapabilities;
use App\Stt\Data\StreamingGrant;
use App\Stt\Data\StreamingRequest;
use App\Stt\Data\SubmitResult;
use App\Stt\Data\WebhookEnvelope;
use App\Stt\Data\Word;
use App\Stt\Exceptions\InvalidWebhookSignature;
use App\Stt\Exceptions\ProviderJobFailed;
use App\Stt\Exceptions\ProviderNotConfigured;
use App\Stt\KeytermPolicy;
use App\Stt\LanguageChoice;
use App\Stt\ProviderEndpoints;
use App\Stt\TranscriptNormalizer;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * ElevenLabs Scribe v2 batch (multichannel + keyterms + signed webhooks) and Scribe v2 realtime single-use tokens.
 * Wire details: docs/research/stt-elevenlabs.md R3–R8.
 */
final class ElevenLabsDriver implements ClipTranscriber, SttProvider
{
    public function __construct(
        private readonly CredentialVault $vault,
        private readonly SettingsRepository $settings,
    ) {}

    public function key(): SttProviderKey
    {
        return SttProviderKey::ElevenLabs;
    }

    public function capabilities(): ProviderCapabilities
    {
        // Batch: ≤ 1000 terms, < 50 chars, ≤ 5 words. Realtime: ≤ 50 terms, ≤ 20 chars.
        return new ProviderCapabilities(CompletionMode::AsyncWebhook, 'cloud', true, false, [], 1000, 49, 5, 50, 20);
    }

    private function baseUrl(): string
    {
        $stored = $this->settings->get('elevenlabs.base_url', 'https://api.elevenlabs.io');
        $url = rtrim(is_string($stored) ? $stored : '', '/');
        // Every request carries the API key: refuse an address that is not ElevenLabs' own (A6.W10).
        if (! ProviderEndpoints::allowsElevenLabs($url)) {
            throw new ProviderNotConfigured('Set an HTTPS elevenlabs.io API base URL on Admin → Settings.');
        }

        return $url;
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->withHeaders(['xi-api-key' => $this->vault->require('elevenlabs', 'api_key')])
            ->acceptJson()->connectTimeout(15)->timeout(30);
    }

    public function streamingGrant(StreamingRequest $request): StreamingGrant
    {
        $keyterms = KeytermPolicy::forProvider($request->keyterms, $this->capabilities(), true);
        $wsBase = (string) preg_replace('#^http#', 'ws', $this->baseUrl());
        $expires = now()->addMinutes(14);
        $grants = [];

        // Tokens are single-use and realtime audio is mono: one token per channel.
        foreach ($request->channels as $channel) {
            $token = (string) $this->http()->timeout(10)->send('POST', '/v1/single-use-token/realtime_scribe')->throw()->json('token');
            if ($token === '') {
                throw new RuntimeException('ElevenLabs returned no realtime token.');
            }
            // Built by hand: http_build_query would emit keyterms%5B0%5D instead of repeated keyterms=.
            $query = [
                'model_id='.rawurlencode((string) $this->settings->get('elevenlabs.realtime_model_id', 'scribe_v2_realtime')),
                'token='.rawurlencode($token),
                'audio_format=pcm_16000',
                'commit_strategy=vad',
            ];
            if (($language = LanguageChoice::primary($request->language)) !== null) {
                $query[] = 'language_code='.rawurlencode($language);
            }
            foreach ($keyterms as $term) {
                $query[] = 'keyterms='.rawurlencode($term);
            }
            $grants[] = [
                'channel' => $channel,
                'transport' => 'websocket',
                'protocol' => 'elevenlabs_realtime',
                'ws_url' => $wsBase.'/v1/speech-to-text/realtime?'.implode('&', $query),
                'redeem_by' => $expires->toAtomString(),
                'max_session_seconds' => null,
                'audio' => ['encoding' => 'pcm_s16le', 'sample_rate' => 16000, 'channels' => 1, 'frame_ms' => 100],
                'init_messages' => [],
            ];
        }

        return new StreamingGrant('elevenlabs', 'cloud', $expires, $keyterms, $grants);
    }

    public function submitFinal(FinalJobRequest $request): SubmitResult
    {
        $webhookId = $this->vault->get('elevenlabs', 'webhook_id');
        $useWebhook = $webhookId !== null && $request->webhookUrl !== null;

        // Multipart booleans must be the strings 'true'/'false' (PHP false becomes '').
        $parts = [
            ['name' => 'model_id', 'contents' => (string) $this->settings->get('elevenlabs.model_id', 'scribe_v2')],
            ['name' => 'use_multi_channel', 'contents' => $request->isStereo() ? 'true' : 'false'],
            ['name' => 'diarize', 'contents' => 'false'],
            ['name' => 'timestamps_granularity', 'contents' => 'word'],
            ['name' => 'tag_audio_events', 'contents' => 'false'],
            ['name' => 'webhook', 'contents' => $useWebhook ? 'true' : 'false'],
        ];
        if ($useWebhook) {
            $parts[] = ['name' => 'webhook_id', 'contents' => (string) $webhookId];
            $parts[] = ['name' => 'webhook_metadata', 'contents' => json_encode(
                ['transcript_id' => $request->transcript->id, 'session_id' => $request->session->id], JSON_THROW_ON_ERROR)];
        }
        // Scribe takes one language_code (ISO 639-1 or 639-3): a mixed call goes out as its non-English language, so Urdu
        // with English words is not left to detection (which picks Hindi or English for Urdu speech).
        if (($language = LanguageChoice::primary($request->language)) !== null) {
            $parts[] = ['name' => 'language_code', 'contents' => $language];
        }
        foreach (KeytermPolicy::forProvider($request->keyterms, $this->capabilities(), false) as $term) {
            $parts[] = ['name' => 'keyterms', 'contents' => $term]; // repeated field, never keyterms[0]
        }

        $stream = fopen($request->primaryPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Cannot read decrypted recording.');
        }
        try {
            $parts[] = ['name' => 'file', 'contents' => $stream, 'filename' => 'recording', 'headers' => ['Content-Type' => $request->primary->mime]];
            $response = $this->http()->timeout(900)->asMultipart()->post('/v1/speech-to-text', $parts)->throw();
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $data = (array) $response->json();
        if (! $useWebhook) {
            return SubmitResult::completed($this->normalize($data), (string) ($data['transcription_id'] ?? ''));
        }

        return SubmitResult::submitted((string) ($data['request_id'] ?? ''), isset($data['transcription_id']) ? (string) $data['transcription_id'] : null);
    }

    /**
     * Double-check (since v1.2): one short clip, answered in the same call. Scribe keeps the transcript, so its copy
     * is deleted right away (stt:reconcile retries when that fails).
     */
    public function transcribeClip(string $path, float $seconds, ?string $language): array
    {
        $parts = [
            ['name' => 'model_id', 'contents' => (string) $this->settings->get('elevenlabs.model_id', 'scribe_v2')],
            ['name' => 'diarize', 'contents' => 'false'],
            ['name' => 'tag_audio_events', 'contents' => 'false'],
        ];
        if (($code = LanguageChoice::primary($language)) !== null) {
            $parts[] = ['name' => 'language_code', 'contents' => $code];
        }
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Cannot read the clip.');
        }
        try {
            $parts[] = ['name' => 'file', 'contents' => $stream, 'filename' => 'clip.flac', 'headers' => ['Content-Type' => 'audio/flac']];
            $data = (array) $this->http()->timeout(120)->asMultipart()->post('/v1/speech-to-text', $parts)->throw()->json();
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        $id = (string) ($data['transcription_id'] ?? '');
        if ($id !== '') {
            try {
                $deleted = $this->http()->connectTimeout(5)->timeout(10)->delete('/v1/speech-to-text/transcripts/'.rawurlencode($id));
                $gone = $deleted->successful() || $deleted->status() === 404;
            } catch (Throwable) {
                $gone = false;
            }
            if (! $gone) {
                app(ProviderCopyEraser::class)->schedule(SttProviderKey::ElevenLabs, $id, $id);
            }
        }

        return ['text' => trim((string) ($data['text'] ?? '')), 'billed_ms' => (int) round($seconds * 1000)];
    }

    public function verifyWebhook(Request $request): WebhookEnvelope
    {
        $secret = $this->vault->get('elevenlabs', 'webhook_secret')
            ?? throw new InvalidWebhookSignature('ElevenLabs webhook secret is not configured.');
        $rawBody = $request->getContent();
        $header = $request->header('elevenlabs-signature');
        $payload = ElevenLabsSignature::verify($rawBody, $header, $secret, time());

        $type = (string) ($payload['type'] ?? '');
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $requestId = (string) ($data['request_id'] ?? $payload['request_id'] ?? '');
        $metadata = $data['webhook_metadata'] ?? $payload['webhook_metadata'] ?? null;
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true);
        }

        return new WebhookEnvelope(
            // Keyed on what the signature covers (timestamp + exact body), never on the unsigned delivery-id header:
            // a replay with a new delivery id is still the same event (A6.W20).
            'el:'.hash('sha256', ElevenLabsSignature::timestamp((string) $header).'.'.$rawBody),
            is_array($metadata) ? (string) ($metadata['transcript_id'] ?? '') : null,
            $requestId !== '' ? $requestId : null,
            $type === 'webhook_error' ? 'error' : 'completed',
            $payload,
            $type === 'webhook_error' ? (string) ($payload['error_message'] ?? 'ElevenLabs reported an error.') : null,
        );
    }

    public function fetchResult(Transcript $transcript, ?array $webhookPayload = null): ?NormalizedTranscript
    {
        if ($webhookPayload !== null) {
            if (($webhookPayload['type'] ?? '') === 'webhook_error') {
                throw new ProviderJobFailed((string) ($webhookPayload['error_message'] ?? 'ElevenLabs reported an error.'));
            }
            $transcription = $webhookPayload['data']['transcription'] ?? null;
            if (is_array($transcription)) {
                if (isset($transcription['transcription_id'])) {
                    $transcript->update(['provider_transcript_id' => (string) $transcription['transcription_id']]);
                }

                return $this->normalize($transcription);
            }
        }

        $id = $transcript->provider_transcript_id;
        if ($id === null || $id === '') {
            return null;
        }
        $response = $this->http()->get('/v1/speech-to-text/transcripts/'.rawurlencode($id));
        if ($response->status() === 404) {
            return null; // still processing
        }

        return $this->normalize((array) $response->throw()->json());
    }

    /** @param array<string, mixed> $data single-channel or multichannel transcription body */
    public function normalize(array $data): NormalizedTranscript
    {
        $channels = [];
        if (is_array($data['transcripts'] ?? null) && $data['transcripts'] !== []) {
            foreach ($data['transcripts'] as $i => $channelTranscript) {
                $index = (int) ($channelTranscript['channel_index'] ?? $i);
                $channels[$index + 1] = (array) ($channelTranscript['words'] ?? []);
            }
        } else {
            $channels[1] = (array) ($data['words'] ?? []);
        }

        $multichannel = count($channels) >= 2;
        $segments = [];
        foreach ($channels as $channel => $rawWords) {
            $words = [];
            foreach ($rawWords as $w) {
                if (($w['type'] ?? 'word') !== 'word') {
                    continue; // skip spacing + audio_event tokens
                }
                $words[] = new Word(trim((string) $w['text']), TranscriptNormalizer::secondsToMs($w['start'] ?? 0),
                    TranscriptNormalizer::secondsToMs($w['end'] ?? 0), isset($w['logprob']) ? round(exp((float) $w['logprob']), 4) : null);
            }
            $speaker = TranscriptNormalizer::speakerFor((int) $channel, $multichannel ? 2 : 1);
            array_push($segments, ...TranscriptNormalizer::segmentWords($words, (int) $channel, $speaker));
        }

        $segments = TranscriptNormalizer::sortByStart($segments);
        if ($multichannel) {
            $segments = TranscriptNormalizer::dedupeBleed($segments);
        }
        $language = $data['language_code'] ?? ($data['transcripts'][0]['language_code'] ?? null);

        return new NormalizedTranscript(TranscriptNormalizer::plainText($segments), $segments,
            is_string($language) ? $language : null, ['audio_channels' => $multichannel ? 2 : 1, 'labels_from_channels' => $multichannel]);
    }

    public function deleteRemote(Transcript $transcript): void
    {
        $id = $transcript->provider_transcript_id;
        if ($id === null || $id === '') {
            return; // surfaced in admin as "provider copy not deleted" via provider_deleted_at = null
        }
        // May run inside a device's DELETE /api/sessions request: at most 10 seconds, stt:reconcile retries failures.
        $response = $this->http()->connectTimeout(5)->timeout(10)->delete('/v1/speech-to-text/transcripts/'.rawurlencode($id));
        if ($response->failed() && $response->status() !== 404) {
            $response->throw();
        }
    }
}
