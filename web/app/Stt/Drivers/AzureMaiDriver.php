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
use App\Stt\Data\Segment;
use App\Stt\Data\StreamingGrant;
use App\Stt\Data\StreamingRequest;
use App\Stt\Data\SubmitResult;
use App\Stt\Data\WebhookEnvelope;
use App\Stt\Data\Word;
use App\Stt\Exceptions\ProviderNotConfigured;
use App\Stt\Exceptions\UnsupportedFeature;
use App\Stt\KeytermPolicy;
use App\Stt\ProviderEndpoints;
use App\Stt\Support\WavFile;
use App\Stt\TranscriptNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Azure Speech fast transcription (synchronous). MAI-Transcribe-2 has no channel separation and accepts only
 * WAV/MP3/FLAC, so it runs once per channel artifact; otherwise the standard model splits stereo itself
 * (`channels:[0,1]`, Opus accepted). Live captions use 10-minute STS tokens. docs/research/stt-azure-local.md.
 */
final class AzureMaiDriver implements SttProvider
{
    private const API_VERSION = '2025-10-15';

    public function __construct(
        private readonly CredentialVault $vault,
        private readonly SettingsRepository $settings,
        private readonly ChunkedUploadService $uploads,
    ) {}

    public function key(): SttProviderKey
    {
        return SttProviderKey::AzureMai;
    }

    private function usesMai(): bool
    {
        return $this->settings->get('azure.model', 'MAI-Transcribe-2') === 'MAI-Transcribe-2';
    }

    public function capabilities(): ProviderCapabilities
    {
        $mai = $this->usesMai();

        return new ProviderCapabilities(CompletionMode::Sync, 'cloud', ! $mai, $mai,
            $mai ? ['audio/wav', 'audio/x-wav', 'audio/flac', 'audio/mpeg'] : [], 500, 50, 6, 500, 50);
    }

    private function endpoint(): string
    {
        $stored = $this->settings->get('azure.endpoint', '');
        $endpoint = rtrim(is_string($stored) ? $stored : '', '/');
        if ($endpoint === '') {
            throw new ProviderNotConfigured('Set the Azure Speech endpoint on Admin → Settings.');
        }
        // The request carries the subscription key: only Azure's own hosts over HTTPS (A6.W10).
        if (! ProviderEndpoints::allowsAzureEndpoint($endpoint)) {
            throw new ProviderNotConfigured('Set an HTTPS Azure Speech resource endpoint on Admin → Settings.');
        }

        return $endpoint;
    }

    public function streamingGrant(StreamingRequest $request): StreamingGrant
    {
        $stored = $this->settings->get('azure.region', '');
        $region = is_string($stored) ? $stored : '';
        if ($region === '') {
            throw new ProviderNotConfigured('Set the Azure Speech region on Admin → Settings.');
        }
        // The region becomes part of the token host name, so it must be a plain region name (A6.W10).
        if (! ProviderEndpoints::allowsAzureRegion($region)) {
            throw new ProviderNotConfigured('Set a valid Azure Speech region on Admin → Settings, such as centralindia.');
        }
        $token = Http::withHeaders(['Ocp-Apim-Subscription-Key' => $this->vault->require('azure', 'api_key')])
            ->connectTimeout(10)->timeout(10)
            ->withBody('', 'application/x-www-form-urlencoded')
            ->post("https://{$region}.api.cognitive.microsoft.com/sts/v1.0/issueToken")->throw()->body();

        $phrases = KeytermPolicy::forProvider($request->keyterms, $this->capabilities(), true);
        $expires = now()->addSeconds(600);
        $grants = array_map(fn (string $channel): array => [
            'channel' => $channel,
            'transport' => 'azure_speech_sdk',
            'protocol' => 'azure_speech_sdk',
            'token' => $token,
            'region' => $region,
            'expires_in' => 600,
            'refresh_after' => 540,
            'phrases' => $phrases,
            'audio' => ['encoding' => 'pcm_s16le', 'sample_rate' => 16000, 'channels' => 1, 'frame_ms' => 100],
        ], $request->channels);

        return new StreamingGrant('azure_mai', 'cloud', $expires, $phrases, $grants);
    }

    public function submitFinal(FinalJobRequest $request): SubmitResult
    {
        $phrases = KeytermPolicy::forProvider($request->keyterms, $this->capabilities(), false);
        $segments = [];
        // The model that actually ran: the usage ledger bills MAI per channel file and standard once per file.
        $model = 'MAI-Transcribe-2';

        if ($this->usesMai() && $request->hasChannelArtifacts()) {
            foreach ([1 => [$request->channel1Path, $request->channel1Mime], 2 => [$request->channel2Path, $request->channel2Mime]] as $channel => [$path, $mime]) {
                $phrasesJson = $this->transcribe((string) $path, (string) $mime, $this->maiDefinition($phrases, $request->language));
                array_push($segments, ...$this->mapPhrases($phrasesJson, $channel, 2));
            }
        } elseif ($this->usesMai() && ! $request->isStereo()) {
            $phrasesJson = $this->transcribe($request->primaryPath, $request->primary->mime, $this->maiDefinition($phrases, $request->language));
            $segments = $this->mapPhrases($phrasesJson, 1, 1);
        } elseif ($this->usesMai() && WavFile::isWav($request->primaryPath)) {
            // Stereo WAV without channel artifacts: split it locally (pure PHP), then one MAI request per channel.
            $left = $this->uploads->tempPath('azure-left');
            $right = $this->uploads->tempPath('azure-right');
            try {
                WavFile::open($request->primaryPath)->splitStereo($left, $right);
                foreach ([1 => $left, 2 => $right] as $channel => $path) {
                    array_push($segments, ...$this->mapPhrases($this->transcribe($path, 'audio/wav', $this->maiDefinition($phrases, $request->language)), $channel, 2));
                }
            } finally {
                @unlink($left);
                @unlink($right);
            }
        } else {
            // Standard fast-transcription model separates stereo channels itself and accepts Opus.
            $model = 'standard';
            $definition = ['profanityFilterMode' => 'None'];
            if ($request->isStereo()) {
                $definition['channels'] = [0, 1];
            }
            if ($request->language !== null && $request->language !== '') {
                $definition['locales'] = [$request->language];
            }
            if ($phrases !== []) {
                $definition['phraseList'] = ['phrases' => $phrases];
            }
            $phrasesJson = $this->transcribe($request->primaryPath, $request->primary->mime, $definition);
            $segments = $this->mapPhrases($phrasesJson, null, $request->isStereo() ? 2 : 1);
        }

        $segments = TranscriptNormalizer::sortByStart($segments);
        $stereo = $request->isStereo() || $request->hasChannelArtifacts();
        if ($stereo) {
            $segments = TranscriptNormalizer::dedupeBleed($segments);
        }

        return SubmitResult::completed(new NormalizedTranscript(TranscriptNormalizer::plainText($segments), $segments,
            $request->language, ['model' => $model, 'audio_channels' => $stereo ? 2 : 1]));
    }

    /**
     * @param  list<string>  $phrases
     * @return array<string, mixed>
     */
    private function maiDefinition(array $phrases, ?string $language): array
    {
        $definition = [
            'enhancedMode' => [
                'enabled' => true,
                'model' => 'MAI-Transcribe-2',
                'modelOptions' => ['timestamps' => 'word', 'transcribeStyle' => 'verbatim'],
            ],
            'profanityFilterMode' => 'None',
        ];
        if ($phrases !== []) {
            $definition['phraseList'] = ['phrases' => $phrases];
        }
        if ($language !== null && $language !== '') {
            $definition['locales'] = [strtolower(explode('-', $language)[0])]; // MAI: exactly one bare code
        }

        return $definition;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return list<array<string, mixed>>
     */
    private function transcribe(string $path, string $mime, array $definition): array
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Cannot read audio for Azure.');
        }
        try {
            $response = Http::withHeaders(['Ocp-Apim-Subscription-Key' => $this->vault->require('azure', 'api_key')])
                ->acceptJson()->connectTimeout(15)->timeout(900)
                ->attach('audio', $stream, 'audio', ['Content-Type' => $mime])
                ->post($this->endpoint().'/speechtotext/transcriptions:transcribe?api-version='.self::API_VERSION, [
                    'definition' => json_encode($definition, JSON_THROW_ON_ERROR),
                ])->throw();
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return array_values((array) $response->json('phrases', []));
    }

    /**
     * @param  list<array<string, mixed>>  $phrases
     * @return list<Segment>
     */
    private function mapPhrases(array $phrases, ?int $fixedChannel, int $channelCount): array
    {
        $segments = [];
        foreach ($phrases as $phrase) {
            $channel = $fixedChannel ?? ((int) ($phrase['channel'] ?? 0) + 1);
            $start = (int) ($phrase['offsetMilliseconds'] ?? 0);
            $words = array_map(fn (array $w): Word => new Word((string) $w['text'], (int) $w['offsetMilliseconds'],
                (int) $w['offsetMilliseconds'] + (int) $w['durationMilliseconds']), array_values((array) ($phrase['words'] ?? [])));
            $segments[] = new Segment($channel, TranscriptNormalizer::speakerFor($channel, $channelCount), $start,
                $start + (int) ($phrase['durationMilliseconds'] ?? 0), trim((string) ($phrase['text'] ?? '')), $words);
        }

        return $segments;
    }

    public function verifyWebhook(Request $request): WebhookEnvelope
    {
        throw new UnsupportedFeature('Azure fast transcription is synchronous; no webhooks.');
    }

    public function fetchResult(Transcript $transcript, ?array $webhookPayload = null): ?NormalizedTranscript
    {
        return null; // sync: results are stored by TranscribeFinalJob directly
    }

    public function deleteRemote(Transcript $transcript): void
    {
        // Fast transcription does not retain audio or results (Microsoft data-privacy page) — nothing to delete.
    }
}
