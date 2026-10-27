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
use App\Stt\Data\StreamingGrant;
use App\Stt\Data\StreamingRequest;
use App\Stt\Data\SubmitResult;
use App\Stt\Data\WebhookEnvelope;
use App\Stt\Data\Word;
use App\Stt\Exceptions\InvalidWebhookSignature;
use App\Stt\Exceptions\ProviderJobFailed;
use App\Stt\Exceptions\UnsupportedFeature;
use App\Stt\KeytermPolicy;
use App\Stt\LanguageChoice;
use App\Stt\TranscriptNormalizer;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Soniox async transcription (docs checked 2026-10-27: soniox.com/docs/stt/api-reference):
 * - `POST /v1/files` (multipart `file`) → file id; `POST /v1/transcriptions` with `model` (`stt-async-v5`), `file_id`,
 *   `language_hints` (+ `language_hints_strict` when the user chose the language), `context.terms`, `webhook_url`
 *   and `webhook_auth_header_name` / `_value`; the webhook body is `{id, status}` with status completed or error.
 * - `GET /v1/transcriptions/{id}` (status queued | processing | completed | error), `GET …/{id}/transcript`
 *   (`text`, `tokens[]` with `text`, `start_ms`, `end_ms`, `confidence`, `speaker` when diarized), and DELETE of the
 *   transcription and the file.
 *
 * Soniox has no per-channel mode. With the per-channel files a device uploaded, each channel is its own job (Me and
 * Client stay exact); otherwise the stereo file is sent once with speaker diarization and the speakers are labelled
 * "Speaker 1" / "Speaker 2". Job ids and file ids are kept comma-separated (channel order) in provider_job_id and
 * provider_transcript_id. Live captions are not offered: the apps do not speak Soniox's WebSocket protocol yet.
 */
final class SonioxDriver implements SttProvider
{
    public const WEBHOOK_HEADER = 'X-EchoLedger-Webhook-Token';

    private const BASE = 'https://api.soniox.com';

    public function __construct(
        private readonly CredentialVault $vault,
        private readonly SettingsRepository $settings,
    ) {}

    public function key(): SttProviderKey
    {
        return SttProviderKey::Soniox;
    }

    public function capabilities(): ProviderCapabilities
    {
        // Context is limited to 8,000 tokens (~10,000 characters); 200 short terms stay well inside it.
        return new ProviderCapabilities(CompletionMode::AsyncWebhook, 'cloud', false, false, [], 200, 100, 8, 0, 0, usesChannelArtifacts: true);
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->vault->require('soniox', 'api_key'))->acceptJson()->baseUrl(self::BASE)->connectTimeout(15)->timeout(60);
    }

    public function streamingGrant(StreamingRequest $request): StreamingGrant
    {
        throw new UnsupportedFeature('Soniox is used for final transcripts only; the apps cannot stream to it yet.');
    }

    /**
     * The request body of `POST /v1/transcriptions` (public for the request-building tests).
     *
     * @return array<string, mixed>
     */
    public function transcriptionBody(string $fileId, FinalJobRequest $request, bool $diarize, string $rid): array
    {
        $body = [
            'model' => (string) $this->settings->get('soniox.async_model', 'stt-async-v5'),
            'file_id' => $fileId,
            'client_reference_id' => $rid,
        ];
        $codes = LanguageChoice::codes($request->language);
        if ($codes === []) {
            // Auto: detect per part, steered (not restricted) to the languages Auto may pick; the result is checked again.
            $body['enable_language_identification'] = true;
            $body['language_hints'] = LanguageChoice::autoLanguages($this->settings);
        } else {
            // A chosen language is a hint the model should rely on, so Urdu is not drifted to Hindi.
            $body['language_hints'] = array_values(array_unique([...array_filter($codes, fn (string $c): bool => $c !== 'en'), ...array_filter($codes, fn (string $c): bool => $c === 'en')]));
            $body['language_hints_strict'] = true;
        }
        if ($diarize) {
            $body['enable_speaker_diarization'] = true;
        }
        $terms = KeytermPolicy::forProvider($request->keyterms, $this->capabilities(), false);
        if ($terms !== []) {
            $body['context'] = ['terms' => $terms];
        }
        $secret = $this->vault->get('soniox', 'webhook_secret');
        if ($request->webhookUrl !== null && $secret !== null) {
            $body['webhook_url'] = $request->webhookUrl;
            $body['webhook_auth_header_name'] = self::WEBHOOK_HEADER;
            $body['webhook_auth_header_value'] = self::webhookToken($secret, $rid);
        }

        return $body;
    }

    public function submitFinal(FinalJobRequest $request): SubmitResult
    {
        $rid = $request->transcript->id;
        $channels = $request->hasChannelArtifacts()
            ? [[$request->channel1Path, (string) $request->channel1Mime], [$request->channel2Path, (string) $request->channel2Mime]]
            : [[$request->primaryPath, $request->primary->mime]];
        $diarize = ! $request->hasChannelArtifacts() && $request->isStereo();

        $jobs = [];
        $files = [];
        try {
            foreach ($channels as [$path, $mime]) {
                $fileId = $this->upload((string) $path, $mime);
                $files[] = $fileId;
                $id = (string) $this->http()->post('/v1/transcriptions', $this->transcriptionBody($fileId, $request, $diarize, $rid))->throw()->json('id');
                if ($id === '') {
                    throw new RuntimeException('Soniox returned no transcription id.');
                }
                $jobs[] = $id;
            }
        } catch (\Throwable $e) {
            // Nothing half-submitted is left behind on the provider.
            $this->deleteIds($jobs, $files);
            throw $e;
        }

        return SubmitResult::submitted(implode(',', $jobs), implode(',', $files));
    }

    private function upload(string $path, string $mime): string
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Cannot read decrypted recording.');
        }
        try {
            $id = (string) $this->http()->timeout(900)
                ->attach('file', $stream, 'recording', ['Content-Type' => $mime])
                ->post('/v1/files')->throw()->json('id');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        if ($id === '') {
            throw new RuntimeException('Soniox returned no file id.');
        }

        return $id;
    }

    /** Stateless per-recording webhook secret: base64url(HMAC-SHA256(secret, "soniox|{rid}")). */
    public static function webhookToken(string $secret, string $rid): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', 'soniox|'.$rid, $secret, true)), '+/', '-_'), '=');
    }

    public function verifyWebhook(Request $request): WebhookEnvelope
    {
        $secret = $this->vault->get('soniox', 'webhook_secret')
            ?? throw new InvalidWebhookSignature('Soniox webhook secret is not configured.');
        $rid = $request->query('rid', '');
        $rid = is_string($rid) ? $rid : '';
        $given = (string) $request->header(self::WEBHOOK_HEADER, '');
        if ($rid === '' || $given === '' || ! hash_equals(self::webhookToken($secret, $rid), $given)) {
            throw new InvalidWebhookSignature('Invalid Soniox webhook token.');
        }
        $payload = json_decode($request->getContent(), true);
        $payload = is_array($payload) ? $payload : [];
        $id = is_scalar($payload['id'] ?? null) ? (string) $payload['id'] : '';
        $status = is_scalar($payload['status'] ?? null) ? (string) $payload['status'] : '';
        if ($id === '') {
            throw new InvalidWebhookSignature('Webhook body has no transcription id.');
        }

        // A two-channel recording has two jobs: the job id is not cross-checked against the stored list here;
        // fetchResult reads every job and only finishes when all are done.
        return new WebhookEnvelope("soniox:{$id}:{$status}", $rid, null, $status === 'completed' ? 'completed' : ($status === 'error' ? 'error' : $status), $payload);
    }

    public function fetchResult(Transcript $transcript, ?array $webhookPayload = null): ?NormalizedTranscript
    {
        $jobs = self::ids($transcript->provider_job_id);
        if ($jobs === []) {
            return null;
        }
        $tokensByJob = [];
        foreach ($jobs as $job) {
            $status = (array) $this->http()->get('/v1/transcriptions/'.rawurlencode($job))->throw()->json();
            $state = (string) ($status['status'] ?? '');
            if ($state === 'error') {
                throw new ProviderJobFailed((string) ($status['error_message'] ?? 'Soniox reported an error.'));
            }
            if ($state !== 'completed') {
                return null;
            }
            $tokensByJob[] = (array) $this->http()->get('/v1/transcriptions/'.rawurlencode($job).'/transcript')->throw()->json();
        }

        return $this->normalize($tokensByJob, $transcript->language);
    }

    /**
     * Soniox transcripts (one per job) as our segments. One job per channel: job 1 is Me, job 2 is Client. One job
     * for a stereo file: diarized speakers 1 and 2 go to channels 1 and 2 as "Speaker 1" / "Speaker 2". One mono
     * job: "Speaker".
     *
     * @param  list<array<string, mixed>>  $transcripts  GET /v1/transcriptions/{id}/transcript bodies, in channel order
     */
    public function normalize(array $transcripts, ?string $language = null): NormalizedTranscript
    {
        $segments = [];
        $perChannel = count($transcripts) >= 2;
        foreach ($transcripts as $index => $data) {
            $tokens = array_values(array_filter((array) ($data['tokens'] ?? []), 'is_array'));
            if ($perChannel) {
                $channel = $index + 1;
                array_push($segments, ...TranscriptNormalizer::segmentWords(self::words($tokens), $channel, TranscriptNormalizer::speakerFor($channel, 2)));

                continue;
            }
            $bySpeaker = [];
            foreach ($tokens as $token) {
                $bySpeaker[(string) ($token['speaker'] ?? '')][] = $token;
            }
            $diarized = count($bySpeaker) > 1 || array_key_first($bySpeaker) !== '';
            foreach ($bySpeaker as $speaker => $speakerTokens) {
                $channel = $diarized && (string) $speaker !== '1' ? 2 : 1;
                $label = $diarized ? 'Speaker '.($speaker === '' ? '1' : $speaker) : 'Speaker';
                array_push($segments, ...TranscriptNormalizer::segmentWords(self::words($speakerTokens), $channel, $label));
            }
        }
        $segments = TranscriptNormalizer::sortByStart($segments);
        if ($perChannel) {
            $segments = TranscriptNormalizer::dedupeBleed($segments);
        }
        $detected = null;
        foreach ($transcripts as $data) {
            foreach ((array) ($data['tokens'] ?? []) as $token) {
                if (is_array($token) && isset($token['language']) && is_string($token['language'])) {
                    $detected ??= $token['language'];
                }
            }
        }

        return new NormalizedTranscript(
            TranscriptNormalizer::plainText($segments),
            $segments,
            LanguageChoice::normalize($language) ?? $detected,
            ['model' => (string) $this->settings->get('soniox.async_model', 'stt-async-v5'), 'audio_channels' => $perChannel ? 2 : 1],
        );
    }

    /**
     * Soniox tokens are pieces of words: a piece that starts with a space begins a new word, the others continue it.
     *
     * @param  list<array<string, mixed>>  $tokens
     * @return list<Word>
     */
    public static function words(array $tokens): array
    {
        $words = [];
        $current = null;
        foreach ($tokens as $token) {
            $text = (string) ($token['text'] ?? '');
            if ($text === '' || $text === '<end>') {
                continue;
            }
            $start = (int) ($token['start_ms'] ?? 0);
            $end = (int) ($token['end_ms'] ?? $start);
            $confidence = isset($token['confidence']) ? (float) $token['confidence'] : null;
            $startsWord = $current === null || preg_match('/^\s/u', $text) === 1;
            if ($startsWord) {
                if ($current !== null) {
                    $words[] = new Word($current['text'], $current['start'], $current['end'], $current['confidence']);
                }
                $current = ['text' => trim($text), 'start' => $start, 'end' => $end, 'confidence' => $confidence];
            } else {
                $current['text'] .= $text;
                $current['end'] = max($current['end'], $end);
                $current['confidence'] = $confidence === null ? $current['confidence'] : min($current['confidence'] ?? $confidence, $confidence);
            }
        }
        if ($current !== null && $current['text'] !== '') {
            $words[] = new Word($current['text'], $current['start'], $current['end'], $current['confidence']);
        }

        return array_values(array_filter($words, fn (Word $w): bool => $w->text !== ''));
    }

    public function deleteRemote(Transcript $transcript): void
    {
        $this->deleteIds(self::ids($transcript->provider_job_id), self::ids($transcript->provider_transcript_id), true);
    }

    /**
     * @param  list<string>  $jobs
     * @param  list<string>  $files
     */
    private function deleteIds(array $jobs, array $files, bool $throw = false): void
    {
        foreach ([['/v1/transcriptions/', $jobs], ['/v1/files/', $files]] as [$path, $ids]) {
            foreach ($ids as $id) {
                try {
                    $response = $this->http()->connectTimeout(5)->timeout(10)->delete($path.rawurlencode($id));
                    if ($throw && $response->failed() && $response->status() !== 404) {
                        $response->throw();
                    }
                } catch (\Throwable $e) {
                    if ($throw) {
                        throw $e;
                    }
                }
            }
        }
    }

    /** @return list<string> */
    private static function ids(?string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn (string $id): bool => $id !== ''));
    }
}
