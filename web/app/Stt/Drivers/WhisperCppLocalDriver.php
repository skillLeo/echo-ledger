<?php

declare(strict_types=1);

namespace App\Stt\Drivers;

use App\Enums\CompletionMode;
use App\Enums\SttProviderKey;
use App\Models\Transcript;
use App\Services\ChunkedUploadService;
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
use App\Stt\Exceptions\ProviderJobFailed;
use App\Stt\Exceptions\ProviderNotConfigured;
use App\Stt\Exceptions\UnsupportedFeature;
use App\Stt\Support\WavFile;
use App\Stt\TranscriptNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Owner-hosted whisper.cpp `whisper-server` (behind HTTPS + bearer token on a reverse proxy).
 * Whisper is mono: each channel is transcribed separately, long audio in ~10-minute slices with 2 s overlap.
 * docs/research/stt-azure-local.md R3.
 */
final class WhisperCppLocalDriver implements SttProvider
{
    private const OVERLAP_MS = 2000;

    public function __construct(
        private readonly CredentialVault $vault,
        private readonly SettingsRepository $settings,
        private readonly ChunkedUploadService $uploads,
    ) {}

    public function key(): SttProviderKey
    {
        return SttProviderKey::WhisperCppLocal;
    }

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(CompletionMode::Sync, 'on_device', false, true,
            ['audio/wav', 'audio/x-wav', 'audio/flac', 'audio/mpeg'], 60, 50, 6, 0, 0);
    }

    public function streamingGrant(StreamingRequest $request): StreamingGrant
    {
        return new StreamingGrant('whispercpp_local', 'on_device', null, [], []);
    }

    public function submitFinal(FinalJobRequest $request): SubmitResult
    {
        $baseUrl = rtrim((string) $this->settings->get('whisper.base_url', ''), '/');
        if ($baseUrl === '') {
            throw new ProviderNotConfigured('Set the whisper-server URL on Admin → Settings.');
        }

        $temps = [];
        try {
            $inputs = $this->channelInputs($request, $temps);
            $segments = [];
            foreach ($inputs as $channel => $path) {
                $words = $this->transcribeChannel($baseUrl, $path, $request);
                $speaker = TranscriptNormalizer::speakerFor($channel, count($inputs) >= 2 ? 2 : 1);
                array_push($segments, ...TranscriptNormalizer::segmentWords($words, $channel, $speaker));
            }
        } finally {
            foreach ($temps as $temp) {
                @unlink($temp);
            }
        }

        $segments = TranscriptNormalizer::sortByStart($segments);
        if (count($inputs) >= 2) {
            $segments = TranscriptNormalizer::dedupeBleed($segments);
        }

        return SubmitResult::completed(new NormalizedTranscript(TranscriptNormalizer::plainText($segments), $segments,
            $request->language, ['engine' => 'whisper.cpp', 'audio_channels' => count($inputs)]));
    }

    /**
     * @param  list<string>  $temps
     * @return array<int, string> channel => audio path
     */
    private function channelInputs(FinalJobRequest $request, array &$temps): array
    {
        if ($request->hasChannelArtifacts()) {
            return [1 => (string) $request->channel1Path, 2 => (string) $request->channel2Path];
        }
        if (! $request->isStereo()) {
            return [1 => $request->primaryPath];
        }
        if (WavFile::isWav($request->primaryPath)) {
            $left = $temps[] = $this->uploads->tempPath('whisper-left');
            $right = $temps[] = $this->uploads->tempPath('whisper-right');
            WavFile::open($request->primaryPath)->splitStereo($left, $right);

            return [1 => $left, 2 => $right];
        }

        throw new ProviderJobFailed('whisper.cpp needs per-channel WAV/FLAC artifacts for stereo recordings (update the device app).');
    }

    /** @return list<Word> absolute-time words for one mono channel */
    private function transcribeChannel(string $baseUrl, string $path, FinalJobRequest $request): array
    {
        if (! WavFile::isWav($path)) {
            return $this->infer($baseUrl, $path, 0, $request); // FLAC/MP3: one request, no slicing
        }

        $wav = WavFile::open($path);
        $duration = $wav->durationMs();
        $chunkMs = max(60, (int) $this->settings->get('whisper.chunk_seconds', 600)) * 1000;
        $words = [];
        for ($start = 0, $index = 0; $start < $duration; $start += $chunkMs, $index++) {
            $sliceStart = max(0, $start - ($index > 0 ? self::OVERLAP_MS : 0));
            $slicePath = $this->uploads->tempPath('whisper-slice');
            try {
                $wav->slice($slicePath, $sliceStart, min($duration, $start + $chunkMs));
                foreach ($this->infer($baseUrl, $slicePath, $sliceStart, $request) as $word) {
                    // Cut duplicated overlap at the slice boundary midpoint.
                    if ($index > 0 && $word->startMs < $start - self::OVERLAP_MS / 2) {
                        continue;
                    }
                    $words[] = $word;
                }
            } finally {
                @unlink($slicePath);
            }
        }

        return $words;
    }

    /** @return list<Word> */
    private function infer(string $baseUrl, string $path, int $offsetMs, FinalJobRequest $request): array
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Cannot read audio for whisper-server.');
        }
        $glossary = $request->keyterms === [] ? '' : 'Glossary: '.mb_substr(implode(', ', array_slice($request->keyterms, 0, 60)), 0, 600);
        try {
            $http = Http::acceptJson()->connectTimeout(15)->timeout(600);
            $token = $this->vault->get('whispercpp', 'bearer_token');
            if ($token !== null) {
                $http = $http->withToken($token);
            }
            $response = $http->attach('file', $stream, 'audio')->post($baseUrl.'/inference', array_filter([
                'response_format' => 'verbose_json',
                'language' => $request->language ?: 'auto',
                'temperature' => '0.0',
                'temperature_inc' => '0.2',
                'vad' => $this->settings->get('whisper.vad', true) ? 'true' : 'false',
                'prompt' => $glossary,
                'carry_initial_prompt' => $glossary !== '' ? 'true' : null,
                'no_language_probabilities' => 'true',
            ], fn ($v) => $v !== null && $v !== ''))->throw();
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $words = [];
        foreach ((array) $response->json('segments', []) as $segment) {
            $segmentWords = (array) ($segment['words'] ?? []);
            if ($segmentWords === []) {
                $segmentWords = [['word' => $segment['text'] ?? '', 'start' => $segment['start'] ?? 0, 'end' => $segment['end'] ?? 0]];
            }
            foreach ($segmentWords as $w) {
                $text = trim((string) ($w['word'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $words[] = new Word($text, $offsetMs + TranscriptNormalizer::secondsToMs($w['start'] ?? 0),
                    $offsetMs + TranscriptNormalizer::secondsToMs($w['end'] ?? 0), isset($w['probability']) ? (float) $w['probability'] : null);
            }
        }

        return $words;
    }

    public function verifyWebhook(Request $request): WebhookEnvelope
    {
        throw new UnsupportedFeature('whisper-server is synchronous; no webhooks.');
    }

    public function fetchResult(Transcript $transcript, ?array $webhookPayload = null): ?NormalizedTranscript
    {
        return null;
    }

    public function deleteRemote(Transcript $transcript): void
    {
        // Self-hosted: whisper-server keeps nothing.
    }
}
