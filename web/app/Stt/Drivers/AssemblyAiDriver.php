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

    /**
     * Caption speed (Admin › Settings › AssemblyAI): the provider's turn presets, with their turn silences in ms
     * (docs: streaming/turn-detection). min_latency ends a turn after 640 ms of silence instead of balanced's 1280 ms
     * and sends the first partial at once, so captions follow speech by about a second (partials every 1 to 1.5 s).
     * The silences are also sent explicitly, so a change of the server's presets does not slow captions down.
     * The values assume no speaker_labels (with them the provider's defaults are 640 / 768 ms whatever the mode); the
     * grant never asks for speaker labels, because the two channels already tell the speakers apart.
     */
    public const STREAMING_MODES = [
        'min_latency' => ['min_turn_silence' => self::FASTEST_MIN_SILENCE_DEFAULT, 'max_turn_silence' => 640],
        'balanced' => ['min_turn_silence' => 128, 'max_turn_silence' => 1280],
        'max_accuracy' => ['min_turn_silence' => 512, 'max_turn_silence' => 2560],
    ];

    public const STREAMING_MODE_DEFAULT = 'min_latency';

    /**
     * Fastest's min_turn_silence (ms): how soon AssemblyAI checks whether a line has ended, so its words show sooner
     * (target: words on screen within a second). Admin › Settings › AssemblyAI sets it; 80 by default, never under the
     * provider's floor of 50, and below Fastest's max_turn_silence.
     */
    public const FASTEST_MIN_SILENCE_DEFAULT = 80;

    /** The streaming model; the only one offered in Admin › Settings since the Universal-Streaming ones left AssemblyAI's docs. */
    public const STREAMING_MODEL = 'universal-3-6-pro';

    /**
     * "Fast English captions" (Admin › Settings, off by default): English-only recordings stream on AssemblyAI-STT-3.7
     * Realtime (docs: api-reference/specs/streaming.yaml, 2026-11-18): partials while speech is in progress (on by
     * default), the presets, the silences and language_codes accepted; its end-of-turn check never runs before 320 ms.
     */
    public const FAST_ENGLISH_MODEL = 'assemblyai-stt-3-7-realtime';

    public const FAST_ENGLISH_MIN_SILENCE = 320;

    public const FASTEST_MIN_SILENCE_FLOOR = 50;

    public const FASTEST_MIN_SILENCE_CEILING = 600;

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
        $model = $this->streamingModelFor($request->language);
        $query = http_build_query([
            'speech_model' => $model,
            'encoding' => 'pcm_s16le',
            'sample_rate' => $request->sampleRate,
            'inactivity_timeout' => 120,
            ...$this->turnSettings($model),
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
        // Urdu and Arabic come with English words: Universal-2 code switching (two codes, one English; Universal-3.5 Pro
        // does not list Urdu). Another language: its code. Auto: detection limited to the languages Auto may pick
        // (`expected_languages` is a hint, so the result is checked again when it arrives). These three options are
        // mutually exclusive (docs/research/stt-assemblyai.md).
        $codes = LanguageChoice::codes($request->language);
        if (count($codes) === 2) {
            $body['language_codes'] = $codes;
            $body['speech_models'] = ['universal-2'];
        } elseif ($codes !== []) {
            $body['language_code'] = $codes[0];
        } else {
            $body['language_detection'] = true;
            $body['language_detection_options'] = ['expected_languages' => LanguageChoice::autoLanguages($this->settings), 'fallback_language' => 'auto'];
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
     * The turn settings for the stream: `mode` on Universal-3.6 Pro (the model with the presets), and the preset's turn silences on every model (the budget Universal-Streaming models take
     * those too). An unknown stored mode falls back to min_latency.
     *
     * @return array<string, string|int>
     */
    public function turnSettings(string $model): array
    {
        $mode = (string) $this->settings->get('assemblyai.streaming_mode', self::STREAMING_MODE_DEFAULT);
        if (! array_key_exists($mode, self::STREAMING_MODES)) {
            $mode = self::STREAMING_MODE_DEFAULT;
        }

        $silences = self::STREAMING_MODES[$mode];
        if ($mode === 'min_latency') {
            $silences['min_turn_silence'] = self::fastestMinSilence($this->settings->get('assemblyai.fastest_min_silence_ms'));
        }

        if ($model === self::FAST_ENGLISH_MODEL) {
            // Its end-of-turn check never runs before 320 ms: say so rather than send a value it ignores.
            $silences['min_turn_silence'] = max(self::FAST_ENGLISH_MIN_SILENCE, (int) $silences['min_turn_silence']);
        }

        return (in_array($model, [self::STREAMING_MODEL, self::FAST_ENGLISH_MODEL], true) ? ['mode' => $mode] : []) + $silences;
    }

    /**
     * The streaming model stored by the admin, read as Universal-3.6 Pro when it is anything else (the Universal-Streaming
     * models are no longer documented).
     */
    public static function storedStreamingModel(mixed $value): string
    {
        return $value === self::STREAMING_MODEL ? $value : self::STREAMING_MODEL;
    }

    /** The model for a stream in [$language]: 3.7 Realtime for English only when Fast English captions is on. */
    public function streamingModelFor(?string $language): string
    {
        if ((bool) $this->settings->get('assemblyai.fast_english', false) && LanguageChoice::codes($language) === ['en']) {
            return self::FAST_ENGLISH_MODEL;
        }

        return self::storedStreamingModel($this->settings->get('assemblyai.streaming_model'));
    }

    /** The admin's Fastest min_turn_silence, kept between the floor and the ceiling; anything else is the default. */
    public static function fastestMinSilence(mixed $value): int
    {
        if (! is_numeric($value)) {
            return self::FASTEST_MIN_SILENCE_DEFAULT;
        }

        return max(self::FASTEST_MIN_SILENCE_FLOOR, min(self::FASTEST_MIN_SILENCE_CEILING, (int) $value));
    }

    /**
     * Streaming models that take language_codes: Universal-3.6 Pro (32 languages incl. Urdu and Hindi, code switching in
     * one stream). The English and the six-language multilingual models do not cover Urdu or Hindi (docs: models.md).
     */
    public static function streamingSupportsLanguages(string $model): bool
    {
        return $model === self::STREAMING_MODEL || $model === self::FAST_ENGLISH_MODEL;
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
