<?php

declare(strict_types=1);

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Filament\Admin\Pages\ApiCredentials;
use App\Jobs\EngineFallbackJob;
use App\Jobs\TranscribeFinalJob;
use App\Models\ProviderRequest;
use App\Models\Transcript;
use App\Services\ChunkedUploadService;
use App\Services\CredentialVault;
use App\Services\SettingsRepository;
use App\Services\TranscriptStore;
use App\Stt\Audio\AudioPrep;
use App\Stt\GroqPacer;
use App\Stt\SttManager;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
 * Groq's rate limits (since v1.5, App\Stt\GroqPacer): every request is counted per API key, a request that would go
 * over the plan's limits (or the ones Groq reports) is not sent and the transcript waits for room, without a failure
 * or another engine, and goes on later where it stopped.
 */

const PACING_KEY = 'gsk_pacing_key_1234567890';

beforeEach(function () {
    Queue::fake();
    Carbon::setTestNow('2026-11-04 10:00:00');
    [$this->device, $this->token] = $this->enroll(platform: 'android');
    app(CredentialVault::class)->put('groq', 'api_key', PACING_KEY);
    app(CredentialVault::class)->put('assemblyai', 'api_key', 'aai_test_key_1234567890');
    app(SettingsRepository::class)->set('groq.plan', 'free');
    app(SettingsRepository::class)->set('routing.final', ['ur' => ['groq', 'assemblyai'], 'en' => ['groq', 'assemblyai']]);
    $this->sent = [];
    $this->answers = [];
    Http::fake(function (HttpRequest $request) {
        $this->sent[] = $request->body(); // read now: the driver closes the file it streamed

        return array_shift($this->answers) ?? Http::response(['error' => ['message' => 'unexpected request']], 500);
    });
});

/** Audio prep that cuts one mono side of 900 s at the pause at 500-502 s: two chunks, 0-501 s and 501-900 s. */
function pacingPrep(): void
{
    app()->instance(AudioPrep::class, new class(app(SettingsRepository::class)) extends AudioPrep
    {
        public function available(): bool
        {
            return true;
        }

        public function extractChannel(string $input, ?int $channel, string $out): string
        {
            file_put_contents($out, 'wav');

            return $out;
        }

        public function durationSeconds(string $wav): float
        {
            return 900.0;
        }

        public function silences(string $wav): array
        {
            return [[500.0, 502.0]];
        }

        public function cut(string $wav, float $startS, float $endS, string $out): string
        {
            file_put_contents($out, "chunk {$startS}-{$endS}");

            return $out;
        }
    });
}

/** No ffmpeg: the whole 60-second file is one request. */
function pacingWholeFile(): void
{
    app()->instance(AudioPrep::class, new class(app(SettingsRepository::class)) extends AudioPrep
    {
        public function available(): bool
        {
            return false;
        }
    });
}

/** @param  array<string, string>  $headers */
function pacingAnswer(string $text, float $start = 1.0, array $headers = []): mixed
{
    return Http::response(['task' => 'transcribe', 'language' => 'english', 'text' => $text,
        'segments' => [['id' => 0, 'start' => $start, 'end' => $start + 2.0, 'text' => $text, 'avg_logprob' => -0.2, 'compression_ratio' => 1.3, 'no_speech_prob' => 0.01]], 'words' => []], 200, $headers);
}

function pacingTranscript(object $test, ?string $language = 'en', int $channels = 1): Transcript
{
    $session = $test->makeReadySession($test->device, Fixtures::audio(4000), ['language' => $language, 'final_provider' => 'groq'], ['channels' => $channels]);

    return $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'groq', 'status' => TranscriptStatus::Pending, 'language' => $language]);
}

function pacingRun(Transcript $transcript, ?string $token = null): Transcript
{
    (new TranscribeFinalJob($transcript->id, $token))->handle(app(SttManager::class), app(ChunkedUploadService::class), app(TranscriptStore::class));

    return $transcript->refresh();
}

/** [$count] requests of [$seconds] audio each sent [$ago] seconds ago with [$apiKey]. */
function pacingUsed(int $count, int $ago, int $seconds = 10, string $apiKey = PACING_KEY): void
{
    for ($i = 0; $i < $count; $i++) {
        ProviderRequest::query()->create(['provider' => 'groq', 'key_id' => GroqPacer::keyId($apiKey), 'audio_ms' => $seconds * 1000, 'requested_at' => now()->subSeconds($ago)]);
    }
}

describe('pacing', function () {
    it('holds a part back until the minute has room, then goes on where it stopped without sending the first part again', function () {
        pacingPrep();
        pacingUsed(19, 10); // the free plan allows 20 requests a minute: room for one more
        $transcript = pacingTranscript($this);
        $this->answers = [pacingAnswer('First part.')];

        pacingRun($transcript);

        expect($this->sent)->toHaveCount(1)
            ->and($transcript->status)->toBe(TranscriptStatus::Pending)
            ->and($transcript->error)->toBeNull()
            ->and($transcript->provider)->toBe('groq')
            // The oldest of the 19 leaves the minute 50 seconds from now.
            ->and($transcript->meta['route']['retry_at'])->toBe('2026-11-04T10:00:50+00:00')
            ->and($transcript->meta['route']['retry_reason'])->toBe('rate_limited');
        Queue::assertNotPushed(EngineFallbackJob::class);

        $this->travelTo(Carbon::parse('2026-11-04 10:00:50'));
        $this->answers = [pacingAnswer('Second part.')];
        pacingRun($transcript, '2026-11-04T10:00:50+00:00');

        expect($this->sent)->toHaveCount(2)
            ->and($this->sent[1])->toContain('chunk 501-900')
            ->and($transcript->status)->toBe(TranscriptStatus::Completed)
            ->and($transcript->text)->toContain('First part.')->toContain('Second part.')
            ->and($transcript->meta['billed_audio_ms'])->toBe(900_000)
            ->and($transcript->meta['route'])->not->toHaveKeys(['retry_at', 'retry_reason']);
        expect(ProviderRequest::query()->where('requested_at', '>=', '2026-11-04 10:00:00')->count())->toBe(2);
    });

    it('holds a request back while this hour has no room for its audio', function () {
        pacingPrep();
        pacingUsed(14, 1800, 500); // 7,000 of the 7,200 audio seconds an hour, used half an hour ago
        $transcript = pacingTranscript($this);

        pacingRun($transcript);

        expect($this->sent)->toBe([])
            ->and($transcript->status)->toBe(TranscriptStatus::Pending)
            // 501 seconds have to fit: the hour frees its first 500-second requests at 10:30.
            ->and($transcript->meta['route']['retry_at'])->toBe('2026-11-04T10:30:00+00:00');
    });

    it('counts each API key on its own', function () {
        pacingWholeFile();
        pacingUsed(20, 5, 10, 'gsk_another_key_0987654321');
        $transcript = pacingTranscript($this);
        $this->answers = [pacingAnswer('Hello.')];

        pacingRun($transcript);

        expect($this->sent)->toHaveCount(1)
            ->and($transcript->status)->toBe(TranscriptStatus::Completed)
            ->and(app(GroqPacer::class)->usage(GroqPacer::keyId(PACING_KEY))['minute_requests'])->toBe(1)
            ->and(app(GroqPacer::class)->usage(GroqPacer::keyId('gsk_another_key_0987654321'))['minute_requests'])->toBe(20);
    });

    it('bills at least ten seconds a request', function () {
        pacingWholeFile();
        $transcript = pacingTranscript($this);
        $transcript->captureSession->recordings()->update(['duration_ms' => 3000]);
        $this->answers = [pacingAnswer('Hi.')];

        pacingRun($transcript);

        expect(ProviderRequest::query()->sole()->audio_ms)->toBe(10_000);
    });
});

describe('what Groq says', function () {
    it('keeps the limits from Groq\'s headers, which win over the plan', function () {
        pacingWholeFile();
        $this->answers = [pacingAnswer('Hello.', 1.0, [
            'x-ratelimit-limit-requests' => '14400', 'x-ratelimit-remaining-requests' => '0', 'x-ratelimit-reset-requests' => '2m59.56s',
            'x-ratelimit-limit-tokens' => '18000', 'x-ratelimit-remaining-tokens' => '17990', 'x-ratelimit-reset-tokens' => '7.66s',
        ])];
        pacingRun(pacingTranscript($this));

        $pacer = app(GroqPacer::class);
        $id = GroqPacer::keyId(PACING_KEY);
        expect($pacer->limits($id))->toMatchArray(['rpd' => 14_400, 'rpm' => 20, 'plan' => 'free', 'from_groq' => true])
            // Groq says no request is left today until its reset in 2 min 59.56 s.
            ->and($pacer->waitFor($id, 10_000))->toBe(180);

        $next = pacingTranscript($this);
        pacingRun($next);
        expect($this->sent)->toHaveCount(1)
            ->and($next->status)->toBe(TranscriptStatus::Pending)
            ->and($next->meta['route']['retry_at'])->toBe('2026-11-04T10:03:00+00:00');
    });

    it('waits as long as retry-after says after a 429, also for the next recording', function () {
        pacingWholeFile();
        $urdu = pacingTranscript($this, 'ur');
        $this->answers = [Http::response(['error' => ['message' => 'Rate limit reached for model `whisper-large-v3`', 'code' => 'rate_limit_exceeded']], 429, ['retry-after' => '30'])];

        pacingRun($urdu);
        expect($urdu->status)->toBe(TranscriptStatus::Pending)
            ->and($urdu->meta['route']['retry_at'])->toBe('2026-11-04T10:00:30+00:00')
            ->and(app(GroqPacer::class)->blockedUntil(GroqPacer::keyId(PACING_KEY))?->toAtomString())->toBe('2026-11-04T10:00:30+00:00');

        // Ten seconds later another recording does not send anything before the 30 seconds are over.
        $this->travelTo(Carbon::parse('2026-11-04 10:00:10'));
        $other = pacingTranscript($this, 'ur');
        pacingRun($other);
        expect($this->sent)->toHaveCount(1)
            ->and($other->status)->toBe(TranscriptStatus::Pending)
            ->and($other->meta['route']['retry_at'])->toBe('2026-11-04T10:00:30+00:00');
        Queue::assertNotPushed(EngineFallbackJob::class);
    });

    it('reads the durations Groq writes', function (string $value, ?float $seconds) {
        expect(GroqPacer::seconds($value))->toBe($seconds);
    })->with([
        ['2m59.56s', 179.56],
        ['7.66s', 7.66],
        ['1h2m3s', 3723.0],
        ['120ms', 0.12],
        ['30', 30.0],
        ['', null],
        ['soon', null],
    ]);
});

describe('the plan and the console', function () {
    it('uses the Developer plan unless the console says Free', function () {
        app(SettingsRepository::class)->set('groq.plan', null);
        $pacer = app(GroqPacer::class);
        app(SettingsRepository::class)->flush();
        expect(SettingsRepository::DEFAULTS['groq.plan'])->toBe('developer')
            ->and($pacer->limits('x')['rpm'])->toBe(300);

        app(SettingsRepository::class)->set('groq.plan', 'free');
        expect($pacer->limits('x'))->toMatchArray(['rpm' => 20, 'rpd' => 2_000, 'ash' => 7_200, 'asd' => 28_800, 'from_groq' => false]);
    });

    it('shows the limits and this hour\'s and today\'s use on the Groq row of API Credentials', function () {
        pacingUsed(3, 20, 60);
        pacingUsed(2, 7200, 100);

        expect(ApiCredentials::groqUse())
            ->toContain('Limits (Free plan): 20 requests a minute, 2,000 a day; 7,200 audio seconds an hour, 28,800 a day.')
            ->toContain('Used: 3 requests this minute, 180 audio seconds this hour, 380 today (5 requests).');

        app(CredentialVault::class)->forget('groq', 'api_key');
        expect(ApiCredentials::groqUse())->toBeNull();
    });

    it('never stores the key itself', function () {
        pacingWholeFile();
        $this->answers = [pacingAnswer('Hello.')];
        pacingRun(pacingTranscript($this));

        expect(json_encode(ProviderRequest::query()->get()->toArray()))->not->toContain(PACING_KEY)
            ->and(ProviderRequest::query()->sole()->key_id)->toBe(substr(hash('sha256', PACING_KEY), 0, 16));
    });
});
