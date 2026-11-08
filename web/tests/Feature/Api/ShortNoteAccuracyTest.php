<?php

declare(strict_types=1);

use App\Enums\SttProviderKey;
use App\Enums\SttUseCase;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Jobs\ShortNoteCheckJob;
use App\Jobs\TranscribeFinalJob;
use App\Models\Setting;
use App\Models\Transcript;
use App\Models\UsageLedgerEntry;
use App\Services\CredentialVault;
use App\Services\FastPath;
use App\Services\SettingsRepository;
use App\Services\ShortNoteCheck;
use App\Services\TranscriptStore;
use App\Services\Usage\UsageLedger;
use App\Services\Usage\UsageReport;
use App\Stt\Data\NormalizedTranscript;
use App\Stt\Data\Segment;
use App\Stt\Data\Word;
use App\Stt\DialectHint;
use App\Stt\Drivers\GeminiDriver;
use App\Stt\Drivers\GroqWhisperDriver;
use App\Stt\Drivers\SonioxDriver;
use App\Stt\LanguageRouter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
 * Phase 1C, Arabic and short-note accuracy: the cheapest engine first per language (ElevenLabs Scribe for Arabic), a
 * note on Auto that is Arabic goes to Arabic's engine, short notes are heard by the two cheapest engines with paid ones
 * only breaking ties, and a minute-long note takes the fast path.
 */

beforeEach(function () {
    [$this->device] = $this->enroll(platform: 'android');
    foreach (['groq' => 'gsk_test_key_1234567890', 'soniox' => 'soniox-test-key-123456', 'assemblyai' => 'aai-test-key-1234567890'] as $p => $k) {
        app(CredentialVault::class)->put($p, 'api_key', $k);
    }
});

function arabicResult(string $word = 'أفحم'): NormalizedTranscript
{
    return new NormalizedTranscript('Speaker: أنا '.$word.' الإنجليزية', [new Segment(1, 'Speaker', 0, 2000, 'أنا '.$word.' الإنجليزية', [
        new Word('أنا', 0, 400, 0.95), new Word($word, 450, 1100, 0.81), new Word('الإنجليزية', 1150, 2000, 0.93),
    ])], 'arabic');
}

function voiceNoteTranscript(object $test, ?string $language, string $provider, int $ms = 2000): Transcript
{
    $session = $test->makeReadySession($test->device, Fixtures::audio(2000), ['language' => $language, 'source_app' => 'voice_note', 'duration_ms' => $ms]);

    return $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => $provider, 'status' => TranscriptStatus::Pending, 'language' => $language]);
}

it('routes every language to the cheapest engine first, and Arabic to ElevenLabs Scribe when its key is set', function () {
    $router = app(LanguageRouter::class);
    foreach (['en', 'ur', 'de', null] as $language) {
        expect($router->final($language, SttUseCase::VoiceNote)->provider)->toBe(SttProviderKey::Groq);
    }
    expect($router->final('ar', SttUseCase::VoiceNote)->provider)->toBe(SttProviderKey::Groq)
        ->and($router->final('ar', SttUseCase::VoiceNote)->fallback)->toBeTrue();

    app(CredentialVault::class)->put('elevenlabs', 'api_key', 'el-test-key-1234567890');
    expect($router->final('ar', SttUseCase::VoiceNote)->provider)->toBe(SttProviderKey::ElevenLabs);
});

it('moves stored lists still equal to the old defaults to the new ones, keeps a chosen list, and can go back', function () {
    Setting::query()->updateOrCreate(['key' => 'routing.final'], ['value' => [...LanguageRouter::PREVIOUS_FINAL_ROUTES, 'ur' => ['soniox', 'groq']]]);
    $migration = require database_path('migrations/2026_11_27_000001_cheapest_first_engine_routes.php');

    $migration->up();
    $routes = Setting::query()->where('key', 'routing.final')->value('value');
    expect($routes['en'])->toBe(LanguageRouter::DEFAULT_FINAL_ROUTES['en'])
        ->and($routes['ar'])->toBe(LanguageRouter::DEFAULT_FINAL_ROUTES['ar'])
        ->and($routes['ur'])->toBe(['soniox', 'groq']);

    $migration->down();
    expect(Setting::query()->where('key', 'routing.final')->value('value')['en'])->toBe(LanguageRouter::PREVIOUS_FINAL_ROUTES['en'])
        ->and(Setting::query()->where('key', 'routing.final_before_cheapest')->exists())->toBeFalse();
});

it('sends an Auto note that turns out Arabic to Arabic\'s engine and keeps the first run as the second opinion', function () {
    Queue::fake();
    app(CredentialVault::class)->put('elevenlabs', 'api_key', 'el-test-key-1234567890');
    $transcript = voiceNoteTranscript($this, null, 'groq');

    app(TranscriptStore::class)->complete($transcript, arabicResult());

    $transcript->refresh();
    expect($transcript->provider)->toBe('elevenlabs')
        ->and($transcript->status)->toBe(TranscriptStatus::Pending)
        ->and($transcript->language)->toBe('ar')
        ->and($transcript->meta['rerouted_from'])->toBe('groq')
        ->and($transcript->meta['first_run'])->toBe(['provider' => 'groq', 'channels' => ['1' => 'أنا أفحم الإنجليزية']]);
    Queue::assertPushed(TranscribeFinalJob::class, fn (TranscribeFinalJob $j): bool => $j->transcriptId === $transcript->id);
    expect(UsageLedgerEntry::query()->where('provider', 'groq')->count())->toBe(1);

    // ElevenLabs answers: the short-note check uses Whisper's first run (no new request) and marks «أفحم».
    app(TranscriptStore::class)->complete($transcript->refresh(), arabicResult('أفهم'));
    Queue::assertPushed(ShortNoteCheckJob::class);
    $summary = app(ShortNoteCheck::class)->run($transcript->refresh());

    $word = $transcript->refresh()->segments[0]['words'][1];
    expect($summary)->toMatchArray(['provider' => 'groq', 'reused_first_run' => true, 'disputed' => 1, 'cost_usd' => 0.0])
        ->and($word['text'])->toBe('أفهم')
        ->and($word['alt'])->toBe('أفحم')
        ->and(Word::isUncertain($word['confidence']))->toBeTrue();
});

it('keeps an Auto note on its engine when the language\'s own engine has no key', function () {
    Queue::fake();
    $transcript = voiceNoteTranscript($this, null, 'groq');

    app(TranscriptStore::class)->complete($transcript, arabicResult());

    expect($transcript->refresh()->status)->toBe(TranscriptStatus::Completed)
        ->and($transcript->provider)->toBe('groq');
});

it('pairs the two cheapest engines and keeps the paid ones for ties', function () {
    $check = app(ShortNoteCheck::class);
    $groq = voiceNoteTranscript($this, 'en', 'groq');
    expect($check->checkerFor($groq))->toBe(SttProviderKey::Soniox)
        ->and($check->tieBreakerFor($groq, SttProviderKey::Soniox))->toBeNull();

    app(CredentialVault::class)->put('elevenlabs', 'api_key', 'el-test-key-1234567890');
    app(CredentialVault::class)->put('gemini', 'api_key', 'gemini-test-key-1234567890');
    expect($check->tieBreakerFor($groq, SttProviderKey::Soniox))->toBe(SttProviderKey::Gemini);

    $arabic = voiceNoteTranscript($this, 'ar', 'elevenlabs');
    expect($check->checkerFor($arabic))->toBe(SttProviderKey::Soniox)
        ->and($check->tieBreakerFor($arabic, SttProviderKey::Soniox))->toBe(SttProviderKey::Gemini);
});

it('checks notes up to 3 minutes only, once, and only when the setting is on', function () {
    $check = app(ShortNoteCheck::class);
    $make = function (int $ms) {
        $t = voiceNoteTranscript($this, 'en', 'groq', $ms);
        $t->update(['status' => TranscriptStatus::Completed, 'text' => 'Speaker: hello', 'segments' => [['channel' => 1, 'speaker' => 'Speaker', 'start_ms' => 0, 'end_ms' => 900, 'text' => 'hello']]]);

        return $t->refresh();
    };
    expect($check->wanted($make(180_000)))->toBeTrue()
        ->and($check->wanted($make(181_000)))->toBeFalse();

    $done = $make(30_000);
    $done->update(['meta' => ['short_check' => ['disputed' => 0]]]);
    expect($check->wanted($done->refresh()))->toBeFalse();

    app(SettingsRepository::class)->set(ShortNoteCheck::SETTING, false);
    expect($check->wanted($make(30_000)))->toBeFalse();
});

it('takes the fast path for a note of a minute or less and the queue for longer ones', function () {
    expect(FastPath::isShort(voiceNoteTranscript($this, 'en', 'groq', 60_000)->captureSession))->toBeTrue()
        ->and(FastPath::isShort(voiceNoteTranscript($this, 'en', 'groq', 61_000)->captureSession))->toBeFalse();
});

it('gives Soniox and Gemini the Arabic dialect, Whisper never a sentence it could write back', function () {
    expect(DialectHint::text('ar'))->toBeNull();

    app(SettingsRepository::class)->set(DialectHint::SETTING, 'gulf');
    expect(GroqWhisperDriver::stylePrompt('ar', []))->toBe('')
        ->and(GroqWhisperDriver::stylePrompt('ur', ['Omar']))->toBe('Omar.')
        ->and(DialectHint::text('ar'))->toBe('Gulf (Saudi) Arabic speech, with English words.')
        ->and(DialectHint::text('ur'))->toBeNull()
        ->and(GeminiDriver::prompt('ar', [], 2.0))->toContain('Dialect: Gulf (Saudi) Arabic');
});

it('bills the second engine and the tie-breaker as their own rows and shows the cost per language', function () {
    $transcript = voiceNoteTranscript($this, 'ar', 'elevenlabs', 30_000);
    $transcript->update(['status' => TranscriptStatus::Completed, 'text' => 'x']);
    $ledger = app(UsageLedger::class);
    $ledger->recordCheck($transcript, SttProviderKey::Soniox, 30_000);
    $ledger->recordCheck($transcript, SttProviderKey::Gemini, 30_000);
    expect(UsageLedgerEntry::query()->where('model', 'double-check')->count())->toBe(2);

    $report = app(UsageReport::class)->forMonth($transcript->captureSession->workspace_id, now()->format('Y-m'));
    $arabic = collect($report['languages'])->firstWhere('language', 'ar');
    expect($arabic['label'])->toBe('Arabic')
        ->and($arabic['recordings'])->toBe(1)
        ->and($arabic['check_usd'])->toBeGreaterThan(0.0)
        ->and($arabic['check_usd'])->toBe($arabic['cost_usd']);
});

it('hears a clip on Soniox, waits for it, and deletes the file and the job at once', function () {
    $clip = tempnam(sys_get_temp_dir(), 'clip').'.wav';
    file_put_contents($clip, 'RIFF');
    app(SettingsRepository::class)->set(DialectHint::SETTING, 'egyptian');
    $deleted = [];
    Http::fake(function (Request $r) use (&$deleted) {
        $path = (string) parse_url($r->url(), PHP_URL_PATH);

        return match (true) {
            $r->method() === 'DELETE' => (function () use (&$deleted, $path) {
                $deleted[] = $path;

                return Http::response([], 204);
            })(),
            $path === '/v1/files' => Http::response(['id' => 'file_1']),
            $path === '/v1/transcriptions' => Http::response(['id' => 'job_1']),
            $path === '/v1/transcriptions/job_1' => Http::response(['status' => 'completed']),
            $path === '/v1/transcriptions/job_1/transcript' => Http::response(['tokens' => [
                ['text' => 'أنا', 'start_ms' => 0, 'end_ms' => 300, 'confidence' => 0.9],
                ['text' => ' أفهم', 'start_ms' => 400, 'end_ms' => 900, 'confidence' => 0.9],
            ]]),
            default => Http::response([], 404),
        };
    });

    $answer = app(SonioxDriver::class)->transcribeClip($clip, 2.0, 'ar');

    expect($answer)->toBe(['text' => 'أنا أفهم', 'billed_ms' => 2000])
        ->and($deleted)->toEqualCanonicalizing(['/v1/transcriptions/job_1', '/v1/files/file_1']);
    Http::assertSent(fn ($r): bool => str_ends_with($r->url(), '/v1/transcriptions') && $r->method() === 'POST'
        && in_array('ar', (array) $r['language_hints'], true) && $r['language_hints_strict'] === true && $r['context']['text'] === 'Egyptian Arabic speech, with English words.');
    @unlink($clip);
});

it('marks every line unsure and starts a second version when the engines heard different things altogether', function () {
    Queue::fake();
    $transcript = voiceNoteTranscript($this, 'ur', 'groq', 18_700);
    $transcript->update([
        'status' => TranscriptStatus::Completed,
        'text' => 'Speaker: ٹھیک ہے، میں email بھیج دوں گا۔',
        'segments' => [['channel' => 1, 'speaker' => 'Speaker', 'start_ms' => 0, 'end_ms' => 18_500, 'text' => 'ٹھیک ہے، میں email بھیج دوں گا۔']],
        'meta' => ['first_run' => ['provider' => 'soniox', 'channels' => ['1' => 'فوڈ منیجمنٹ تو کرنا ہے لیکن فوڈ منیجمنٹ کے علاوہ وہ جو اس میں ساری چیزیں تھی']]],
    ]);

    $summary = app(ShortNoteCheck::class)->run($transcript->refresh());

    expect($summary['unreliable'])->toBeTrue()
        ->and($transcript->refresh()->segments[0]['uncertain'])->toBeTrue()
        ->and($transcript->meta['short_check']['second_version'])->toBeString();
    $second = Transcript::query()->find($transcript->meta['short_check']['second_version']);
    expect($second->kind)->toBe(TranscriptKind::Alternate)->and($second->provider)->toBe('soniox');
    Queue::assertPushed(TranscribeFinalJob::class, fn (TranscribeFinalJob $j): bool => $j->transcriptId === $second->id);
    expect(app(ShortNoteCheck::class)->wanted($second->refresh()->forceFill(['status' => TranscriptStatus::Completed, 'text' => 'x'])))->toBeFalse();
});
