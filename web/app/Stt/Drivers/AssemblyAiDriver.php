<?php

declare(strict_types=1);

namespace App\Stt\Drivers;

use App\Enums\CompletionMode;
use App\Enums\SttProviderKey;
use App\Models\Transcript;
use App\Services\CredentialVault;
use App\Services\SettingsRepository;
use App\Stt\Contracts\SttProvider;
use App\Stt\Data\FinalJobRequest;
use App\Stt\Data\NormalizedTranscript;
use App\Stt\Data\ProviderCapabilities;
use App\Stt\Data\Segment;
use App\Stt\Data\StreamingGrant;
use App\Stt\Data\StreamingRequest;
use App\Stt\Data\SubmitResult;
use App\Stt\Data\WebhookEnvelope;
use App\Stt\Data\Word;
use App\Stt\Exceptions\InvalidWebhookSignature;
use App\Stt\Exceptions\ProviderJobFailed;
use App\Stt\KeytermPolicy;
use App\Stt\LanguageChoice;
use App\Stt\TranscriptNormalizer;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * AssemblyAI: Universal-Streaming v3 temp tokens + async multichannel transcripts with webhooks.
 * Every endpoint/field below is from docs/research/stt-assemblyai.md (R2–R8).
 */
final class AssemblyAiDriver implements SttProvider
{
    public const WEBHOOK_HEADER = 'X-EchoLedger-Webhook-Token';

    private const STREAM_HOST = 'streaming.assemblyai.com';

    public function __construct(
        private readonly CredentialVault $vault,
        private readonly SettingsRepository $settings,
    ) {}

    public function key(): SttProviderKey
    {
        return SttProviderKey::AssemblyAi;
    }

    public function capabilities(): ProviderCapabilities
    {
        // Streaming hard limit 100 terms ≤ 50 chars; one list (≤ 6 words) also fits async + the universal-2 fallback.
        return new ProviderCapabilities(CompletionMode::AsyncWebhook, 'cloud', true, false, [], 100, 50, 6, 100, 50);
    }

    private function restBase(): string
    {
        return $this->settings->get('assemblyai.region') === 'eu' ? 'https://api.eu.assemblyai.com' : 'https://api.assemblyai.com';
    }

    private function http(): PendingRequest
    {
        // Raw key, no "Bearer" prefix.
        return Http::withHeaders(['Authorization' => $this->vault->require('assemblyai', 'api_key')])
            ->acceptJson()->connectTimeout(10)->timeout(30);
    }

    public function streamingGrant(StreamingRequest $request): StreamingGrant
    {
        $token = (string) $this->http()->timeout(10)
            ->get('https://'.self::STREAM_HOST.'/v3/token', [
                'expires_in_seconds' => 60,
                'max_session_duration_seconds' => 10800,
            ])->throw()->json('token');
        if ($token === '') {
            throw new RuntimeException('AssemblyAI returned no streaming token.');
        }

        $keyterms = KeytermPolicy::forProvider($request->keyterms, $this->capabilities(), true);
        $model = (string) $this->settings->get('assemblyai.streaming_model', 'universal-3-6-pro');
        $query = http_build_query([
            'speech_model' => $model,
            'encoding' => 'pcm_s16le',
            'sample_rate' => $request->sampleRate,
            'inactivity_timeout' => 120,
            'token' => $token,
        ], '', '&', PHP_QUERY_RFC3986);
        $wsUrl = 'wss://'.self::STREAM_HOST.'/v3/ws?'.$query;
        // Universal-3.6 Pro is steered to the chosen languages with language_codes in UpdateConfiguration (an array;
        // language_code is ignored by streaming). Automatic leaves it out: native code switching.
        $update = [];
        if ($keyterms !== []) {
            $update['keyterms_prompt'] = $keyterms;
        }
        if ($request->language !== null && self::streamingSupportsLanguages($model)) {
            $codes = LanguageChoice::codes($request->language);
            if ($codes !== []) {
                $update['language_codes'] = $codes;
            }
        }
        $init = $update === [] ? [] : [['type' => 'UpdateConfiguration', ...$update]];
        $expires = now()->addSeconds(60);

        // Tokens are multi-use and not config-bound: one token serves every channel session.
        $grants = array_map(fn (string $channel): array => [
            'channel' => $channel,
            'transport' => 'websocket',
            'protocol' => 'assemblyai_v3',
            'ws_url' => $wsUrl,
            'redeem_by' => $expires->toAtomString(),
            'max_session_seconds' => 10800,
            'audio' => ['encoding' => 'pcm_s16le', 'sample_rate' => $request->sampleRate, 'channels' => 1, 'frame_ms' => 50],
            'init_messages' => $init,
        ], $request->channels);

        return new StreamingGrant('assemblyai', 'cloud', $expires, $keyterms, $grants);
    }

    public function submitFinal(FinalJobRequest $request): SubmitResult
    {
        $base = $this->restBase();
        $apiKey = $this->vault->require('assemblyai', 'api_key');

        $stream = fopen($request->primaryPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Cannot read decrypted recording.');
        }
        try {
            $uploadUrl = (string) Http::withHeaders(['Authorization' => $apiKey])->connectTimeout(15)->timeout(900)
                ->withBody(Utils::streamFor($stream), 'application/octet-stream')
                ->post($base.'/v2/upload')->throw()->json('upload_url');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $body = [
            'audio_url' => $uploadUrl,
            'speech_models' => array_values((array) $this->settings->get('assemblyai.async_models', ['universal-3-5-pro', 'universal-2'])),
            'multichannel' => $request->isStereo(),
            'punctuate' => true,
            'format_text' => true,
        ];
        // English + another language: Universal-2 code switching (two codes, one English); one language: its code;
        // otherwise automatic detection. These three options are mutually exclusive (docs/research/stt-assemblyai.md).
        if (LanguageChoice::assemblyAiCodeSwitch($request->language)) {
            $body['language_codes'] = LanguageChoice::codes($request->language);
            $body['speech_models'] = ['universal-2'];
        } elseif (($single = LanguageChoice::single($request->language)) !== null) {
            $body['language_code'] = $single;
        } else {
            $body['language_detection'] = true;
        }
        $keyterms = KeytermPolicy::forProvider($request->keyterms, $this->capabilities(), false);
        if ($keyterms !== []) {
            $body['keyterms_prompt'] = $keyterms;
        }
        $secret = $this->vault->get('assemblyai', 'webhook_secret');
        if ($request->webhookUrl !== null && $secret !== null) {
            $body['webhook_url'] = $request->webhookUrl;
            $body['webhook_auth_header_name'] = self::WEBHOOK_HEADER;
            $body['webhook_auth_header_value'] = self::webhookToken($secret, $request->transcript->id);
        }

        $id = (string) Http::withHeaders(['Authorization' => $apiKey])->acceptJson()->connectTimeout(15)->timeout(60)
            ->post($base.'/v2/transcript', $body)->throw()->json('id');
        if ($id === '') {
            throw new RuntimeException('AssemblyAI returned no transcript id.');
        }

        return SubmitResult::submitted($id);
    }

    /**
     * Streaming models that take language_codes: Universal-3.6 Pro (32 languages incl. Urdu and Hindi, code switching in
     * one stream). The English and the six-language multilingual models do not cover Urdu or Hindi (docs: models.md).
     */
    public static function streamingSupportsLanguages(string $model): bool
    {
        return $model === 'universal-3-6-pro';
    }

    /** Stateless per-recording webhook secret: base64url(HMAC-SHA256(secret, "assemblyai|{rid}")). */
    public static function webhookToken(string $secret, string $rid): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', 'assemblyai|'.$rid, $secret, true)), '+/', '-_'), '=');
    }

    public function verifyWebhook(Request $request): WebhookEnvelope
    {
        $secret = $this->vault->get('assemblyai', 'webhook_secret')
            ?? throw new InvalidWebhookSignature('AssemblyAI webhook secret is not configured.');
        // A malformed recording id (for example `rid[]=x`) is a bad signature, never a server error.
        $rid = $request->query('rid', '');
        $rid = is_string($rid) ? $rid : '';
        $given = (string) $request->header(self::WEBHOOK_HEADER, '');
        if ($rid === '' || $given === '' || ! hash_equals(self::webhookToken($secret, $rid), $given)) {
            throw new InvalidWebhookSignature('Invalid AssemblyAI webhook token.');
        }

        $payload = json_decode($request->getContent(), true);
        $payload = is_array($payload) ? $payload : [];
        $jobId = is_scalar($payload['transcript_id'] ?? null) ? (string) $payload['transcript_id'] : '';
        $status = is_scalar($payload['status'] ?? null) ? (string) $payload['status'] : '';
        if ($jobId === '') {
            throw new InvalidWebhookSignature('Webhook body has no transcript_id.');
        }

        return new WebhookEnvelope("assemblyai:{$jobId}:{$status}", $rid, $jobId, $status === 'completed' ? 'completed' : ($status === 'error' ? 'error' : $status), $payload);
    }

    public function fetchResult(Transcript $transcript, ?array $webhookPayload = null): ?NormalizedTranscript
    {
        $jobId = (string) $transcript->provider_job_id;
        if ($jobId === '') {
            return null;
        }
        $data = (array) $this->http()->get($this->restBase().'/v2/transcript/'.rawurlencode($jobId))->throw()->json();
        $status = (string) ($data['status'] ?? '');
        if ($status === 'error') {
            throw new ProviderJobFailed((string) ($data['error'] ?? 'AssemblyAI reported an error.'));
        }

        return $status === 'completed' ? $this->normalize($data) : null;
    }

    /** @param array<string, mixed> $data GET /v2/transcript/{id} body */
    public function normalize(array $data): NormalizedTranscript
    {
        $channelCount = (int) ($data['audio_channels'] ?? 1);
        $multichannel = $channelCount >= 2;
        $segments = [];

        $utterances = is_array($data['utterances'] ?? null) ? $data['utterances'] : [];
        if ($utterances !== []) {
            foreach ($utterances as $u) {
                $channel = max(1, min(2, (int) ($u['channel'] ?? 1))); // channel is a string: "1" = left = Me
                $words = array_map(fn (array $w): Word => new Word((string) $w['text'], (int) $w['start'], (int) $w['end'],
                    isset($w['confidence']) ? (float) $w['confidence'] : null), array_values((array) ($u['words'] ?? [])));
                $speaker = $multichannel ? TranscriptNormalizer::speakerFor($channel, 2)
                    : (isset($u['speaker']) ? 'Speaker '.$u['speaker'] : 'Speaker');
                $segments[] = new Segment($channel, $speaker, (int) $u['start'], (int) $u['end'], trim((string) $u['text']), $words);
            }
        } else {
            $byChannel = [];
            foreach ((array) ($data['words'] ?? []) as $w) {
                $channel = max(1, min(2, (int) ($w['channel'] ?? 1)));
                $byChannel[$channel][] = new Word((string) $w['text'], (int) $w['start'], (int) $w['end'],
                    isset($w['confidence']) ? (float) $w['confidence'] : null);
            }
            foreach ($byChannel as $channel => $words) {
                array_push($segments, ...TranscriptNormalizer::segmentWords($words, $channel,
                    TranscriptNormalizer::speakerFor($channel, $multichannel ? 2 : 1)));
            }
        }

        $segments = TranscriptNormalizer::sortByStart($segments);
        if ($multichannel) {
            $segments = TranscriptNormalizer::dedupeBleed($segments);
        }

        return new NormalizedTranscript(
            $multichannel ? TranscriptNormalizer::plainText($segments) : trim((string) ($data['text'] ?? TranscriptNormalizer::plainText($segments))),
            $segments,
            isset($data['language_code']) ? (string) $data['language_code'] : null,
            array_filter([
                'speech_model_used' => $data['speech_model_used'] ?? null,
                'audio_channels' => $channelCount,
                'audio_duration_s' => $data['audio_duration'] ?? null,
            ], fn ($v) => $v !== null),
        );
    }

    public function deleteRemote(Transcript $transcript): void
    {
        if ($transcript->provider_job_id === null) {
            return;
        }
        // DELETE also removes the uploaded audio (no standalone upload delete exists). It may run inside a device's
        // DELETE /api/sessions request, so it gets at most 10 seconds; a failure is retried by stt:reconcile.
        $response = $this->http()->connectTimeout(5)->timeout(10)->delete($this->restBase().'/v2/transcript/'.rawurlencode($transcript->provider_job_id));
        if ($response->failed() && $response->status() !== 404) {
            $response->throw();
        }
    }
}
