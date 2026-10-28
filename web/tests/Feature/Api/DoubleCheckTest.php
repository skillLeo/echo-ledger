<?php

declare(strict_types=1);

use App\Enums\SttProviderKey;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Filament\Infolists\TranscriptPlayerEntry;
use App\Jobs\DoubleCheckJob;
use App\Models\CaptureSession;
use App\Models\Transcript;
use App\Models\UsageLedgerEntry;
use App\Services\CredentialVault;
use App\Services\DoubleCheck;
use App\Services\SettingsRepository;
use App\Services\TranscriptEditor;
use App\Services\TranscriptStore;
use App\Stt\Audio\AudioPrep;
use App\Stt\Data\NormalizedTranscript;
use App\Stt\Data\Segment;
use App\Stt\Data\Word;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
 * Double-check (v1.2, off by default): a second engine hears again the lines the first one was not sure of; when they
 * disagree its words are kept beside the line. The transcript text is never changed.
 */

/** Audio prep that cuts without ffmpeg and remembers each clip. */
function checkPrep(): object
{
    $prep = new class(app(SettingsRepository::class)) extends AudioPrep
    {
        /** @var list<array{0: float, 1: float}> */
        public array $clips = [];

        public function available(): bool
        {
            return true;
        }

        public function extractChannel(string $input, ?int $channel, string $out): string
        {
            file_put_contents($out, 'side-'.$channel);

            return $out;
        }

        public function cut(string $wav, float $startS, float $endS, string $out): string
        {
            $this->clips[] = [$startS, $endS];
            file_put_contents($out, "clip {$startS}-{$endS}");

            return $out;
        }
    };
    app()->instance(AudioPrep::class, $prep);

    return $prep;
}

/** @return list<array<string, mixed>> */
function unsureLines(): array
{
    return [
        ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 2000, 'text' => 'Hello there', 'words' => [
            ['text' => 'Hello', 'start_ms' => 0, 'end_ms' => 900, 'confidence' => 0.97], ['text' => 'there', 'start_ms' => 950, 'end_ms' => 2000, 'confidence' => 0.95]]],
        ['channel' => 2, 'speaker' => 'Client', 'start_ms' => 3000, 'end_ms' => 5000, 'text' => 'Send it to Farah', 'words' => [
            ['text' => 'Send', 'start_ms' => 3000, 'end_ms' => 3400, 'confidence' => 0.9], ['text' => 'it', 'start_ms' => 3450, 'end_ms' => 3600, 'confidence' => 0.9],
            ['text' => 'to', 'start_ms' => 3650, 'end_ms' => 3800, 'confidence' => 0.9], ['text' => 'Farah', 'start_ms' => 3850, 'end_ms' => 5000, 'confidence' => 0.22]]],
        ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 6000, 'end_ms' => 8000, 'text' => 'Fine, see you Monday', 'uncertain' => true],
    ];
}

function checkedTranscript(object $test, string $provider = 'assemblyai'): Transcript
{
    $session = $test->makeReadySession($test->device, Fixtures::audio(4000), ['language' => 'en', 'status' => 'completed']);

    return $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => $provider, 'status' => TranscriptStatus::Completed,
        'language' => 'en', 'text' => 'x', 'segments' => unsureLines()]);
}

function groqClip(string $text): array
{
    return ['task' => 'transcribe', 'language' => 'english', 'duration' => 2.6, 'text' => $text,
        'segments' => [['id' => 0, 'start' => 0.0, 'end' => 2.6, 'text' => $text, 'avg_logprob' => -0.2, 'compression_ratio' => 1.2, 'no_speech_prob' => 0.01]], 'words' => []];
}

beforeEach(function () {
    [$this->device] = $this->enroll(platform: 'android');
    app(CredentialVault::class)->put('groq', 'api_key', 'gsk_test_key_1234567890');
    app(SettingsRepository::class)->set('accuracy.double_check', true);
});

it('picks the lines with an unsure word or marked unsure, never empty, flagged or very short ones', function () {
    $lines = unsureLines();
    $lines[] = ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 9000, 'end_ms' => 9200, 'text' => 'Ok', 'uncertain' => true];
    $lines[] = ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 9500, 'end_ms' => 12000, 'text' => '[unclear: wrong script, re-transcribe]', 'uncertain' => true, 'flag' => 'wrong_script'];

    expect(DoubleCheck::linesToCheck($lines))->toBe([1, 2])
        ->and(DoubleCheck::linesToCheck(array_fill(0, 20, $lines[2])))->toHaveCount(DoubleCheck::MAX_LINES);
});

it('agrees when only case, punctuation or Urdu spelling differ, and not when the words do', function () {
    expect(DoubleCheck::agrees('Send it to Farah.', 'send it to farah'))->toBeTrue()
        ->and(DoubleCheck::agrees('میں کل آؤں گا', 'ميں کل آؤں گا'))->toBeTrue()
        ->and(DoubleCheck::agrees('Send it to Farah', 'Send it to Sara'))->toBeFalse()
        ->and(DoubleCheck::agrees('', ''))->toBeTrue();
});

it('hears the unsure lines again on their own side and keeps what the second engine heard when it disagrees', function () {
    $prep = checkPrep();
    $sent = [];
    Http::fake(['api.groq.com/*' => function (HttpRequest $r) use (&$sent) {
        $sent[] = $r->body();

        return Http::response(count($sent) === 1 ? groqClip('Send it to Sara') : groqClip('Fine, see you Monday.'), 200);
    }]);
    $transcript = checkedTranscript($this);

    $summary = app(DoubleCheck::class)->run($transcript);

    $segments = $transcript->refresh()->segments;
    expect($summary)->toMatchArray(['provider' => 'groq', 'checked' => 2, 'disagreements' => 1, 'failed' => 0])
        ->and($segments[1]['text'])->toBe('Send it to Farah')
        ->and($segments[1]['check'])->toBe(['provider' => 'groq', 'label' => SttProviderKey::Groq->getLabel(), 'text' => 'Send it to Sara'])
        ->and($segments[2])->not->toHaveKey('check')
        ->and($segments[0])->not->toHaveKey('check')
        ->and($transcript->meta['double_check']['disagreements'])->toBe(1)
        ->and($prep->clips)->toBe([[2.7, 5.3], [5.7, 8.3]]);
    expect($sent)->toHaveCount(2)->and($sent[0])->toContain('whisper-large-v3')->toContain('clip 2.7-5.3');
    $usage = UsageLedgerEntry::query()->where('report_id', 'like', 'check/'.$transcript->id.'/%')->sole();
    expect($usage->provider)->toBe('groq')->and($usage->duration_ms)->toBe(20_000);
});

it('shows the second engine\'s words under the line in the console and drops them when the line is edited', function () {
    checkPrep();
    Http::fake(['api.groq.com/*' => Http::response(groqClip('Something else entirely'), 200)]);
    $transcript = checkedTranscript($this);
    app(DoubleCheck::class)->run($transcript);

    $player = TranscriptPlayerEntry::payload(CaptureSession::query()->findOrFail($transcript->capture_session_id));
    expect($player['segments'][1]['check'])->toBe(['label' => SttProviderKey::Groq->getLabel(), 'text' => 'Something else entirely'])
        ->and($player['segments'][0]['check'])->toBeNull();

    app(TranscriptEditor::class)->edit($transcript->refresh(), [1 => 'Send it to Farah Khan']);
    expect($transcript->refresh()->segments[1])->not->toHaveKey('check')->not->toHaveKey('uncertain')
        ->and($transcript->segments[2]['check'])->toBe(['provider' => 'groq', 'label' => SttProviderKey::Groq->getLabel(), 'text' => 'Something else entirely']);
});

it('uses ElevenLabs when Whisper wrote the transcript, and skips without a second engine', function () {
    checkPrep();
    Http::fake(['api.elevenlabs.io/v1/speech-to-text' => Http::response(['transcription_id' => 'tr_clip', 'text' => 'Send it to Sara'], 200),
        'api.elevenlabs.io/v1/speech-to-text/transcripts/*' => Http::response([], 200)]);
    $transcript = checkedTranscript($this, 'groq');
    expect(app(DoubleCheck::class)->run($transcript))->toMatchArray(['skipped' => 'no_second_engine']);

    app(CredentialVault::class)->put('elevenlabs', 'api_key', 'xi_test_key_1234567890');
    $summary = app(DoubleCheck::class)->run($transcript->refresh());

    expect($summary)->toMatchArray(['provider' => 'elevenlabs', 'disagreements' => 2])
        ->and($transcript->refresh()->segments[1]['check']['provider'])->toBe('elevenlabs');
    Http::assertSent(fn (HttpRequest $r): bool => $r->method() === 'DELETE' && str_ends_with($r->url(), '/v1/speech-to-text/transcripts/tr_clip'));
});

it('runs after a final transcript is done only when it is on and there is something unsure', function () {
    Queue::fake();
    $session = $this->makeReadySession($this->device, Fixtures::audio(4000), ['language' => 'ur']);
    $make = fn () => $session->transcripts()->updateOrCreate(['kind' => TranscriptKind::Final->value], ['provider' => 'assemblyai', 'status' => TranscriptStatus::Processing, 'language' => 'ur']);
    $unsure = new NormalizedTranscript('Me: فرح خان', [new Segment(1, 'Me', 0, 2000, 'فرح خان', [new Word('فرح', 0, 900, 0.3), new Word('خان', 950, 2000, 0.9)])], 'ur');
    $sure = new NormalizedTranscript('Me: فرح خان', [new Segment(1, 'Me', 0, 2000, 'فرح خان', [new Word('فرح', 0, 900, 0.9), new Word('خان', 950, 2000, 0.9)])], 'ur');

    app(TranscriptStore::class)->complete($make(), $sure);
    Queue::assertNotPushed(DoubleCheckJob::class);
    app(TranscriptStore::class)->complete($make(), $unsure);
    Queue::assertPushed(DoubleCheckJob::class, 1);

    app(SettingsRepository::class)->set('accuracy.double_check', false);
    app(TranscriptStore::class)->complete($make(), $unsure);
    Queue::assertPushed(DoubleCheckJob::class, 1);
});

it('sends nothing once the monthly limit is reached', function () {
    checkPrep();
    Http::fake();
    app(SettingsRepository::class)->set('budget.monthly_minutes', 1);
    UsageLedgerEntry::query()->create([
        'user_id' => $this->device->user_id, 'source' => 'device', 'report_id' => 'seed-limit', 'use_case' => 'dictation',
        'provider' => 'assemblyai', 'duration_ms' => 120_000, 'channels' => 1, 'billed_ms' => 120_000,
        'unit_price_usd' => 0.45, 'cost_usd' => 0.015, 'billable' => true, 'occurred_at' => now(),
    ]);

    expect(app(DoubleCheck::class)->run(checkedTranscript($this)))->toMatchArray(['skipped' => 'limit_reached']);
    Http::assertNothingSent();
});
