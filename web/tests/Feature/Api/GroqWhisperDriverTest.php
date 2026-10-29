<?php

declare(strict_types=1);

use App\Enums\SttProviderKey;
use App\Enums\SttUseCase;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Jobs\TranscribeFinalJob;
use App\Models\Transcript;
use App\Models\UsageLedgerEntry;
use App\Services\CredentialVault;
use App\Services\SettingsRepository;
use App\Stt\Audio\AudioPrep;
use App\Stt\Drivers\GroqWhisperDriver;
use App\Stt\LanguageRouter;
use App\Stt\WhisperGuards;
use App\Support\UrduText;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
 * Whisper large-v3 on Groq (v1.2): one request per side and chunk, the language rules per chunk, Whisper's invented
 * text removed, Urdu letter forms, exact offsets.
 */

/** Audio prep without ffmpeg: the whole file is sent. */
function noPrep(): void
{
    app()->instance(AudioPrep::class, new class(app(SettingsRepository::class)) extends AudioPrep
    {
        public function available(): bool
        {
            return false;
        }
    });
}

/** Audio prep that "finds" 1300 s per side with pauses at 595-597 s and 1000-1010 s. */
function fakePrep(): void
{
    app()->instance(AudioPrep::class, new class(app(SettingsRepository::class)) extends AudioPrep
    {
        public function available(): bool
        {
            return true;
        }

        public function extractChannel(string $input, ?int $channel, string $out): string
        {
            file_put_contents($out, 'wav-'.$channel);

            return $out;
        }

        public function durationSeconds(string $wav): float
        {
            return 1300.0;
        }

        public function silences(string $wav): array
        {
            return [[595.0, 597.0], [1000.0, 1010.0]];
        }

        public function cut(string $wav, float $startS, float $endS, string $out): string
        {
            file_put_contents($out, "chunk {$startS}-{$endS}");

            return $out;
        }
    });
}

/** @param  list<array<string, mixed>>  $segments */
function groqAnswer(string $language, array $segments, array $words = []): array
{
    return ['task' => 'transcribe', 'language' => $language, 'duration' => 30.0, 'text' => implode(' ', array_column($segments, 'text')), 'segments' => $segments, 'words' => $words];
}

function seg(float $start, float $end, string $text, array $extra = []): array
{
    return [...['id' => 0, 'start' => $start, 'end' => $end, 'text' => $text, 'avg_logprob' => -0.2, 'compression_ratio' => 1.3, 'no_speech_prob' => 0.01], ...$extra];
}

beforeEach(function () {
    [$this->device] = $this->enroll(platform: 'android');
    app(CredentialVault::class)->put('groq', 'api_key', 'gsk_test_key_1234567890');
});

function runGroq(object $test, ?string $language, array $sessionAttributes = []): Transcript
{
    $session = $test->makeReadySession($test->device, Fixtures::audio(4000), ['language' => $language, 'final_provider' => 'groq', ...$sessionAttributes]);
    $transcript = $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'groq', 'status' => TranscriptStatus::Pending, 'language' => $language]);
    dispatch_sync(new TranscribeFinalJob($transcript->id));

    return $transcript->refresh();
}

it('sends large-v3 at temperature 0 with segment and word timestamps, the language and an Urdu-script prompt', function () {
    noPrep();
    $bodies = [];
    Http::fake(['api.groq.com/*' => function (HttpRequest $r) use (&$bodies) {
        $bodies[] = $r->body();

        return Http::response(groqAnswer('urdu', [seg(1.0, 3.5, 'آپ کل کی meeting reschedule کر دیں')]), 200);
    }]);

    $transcript = runGroq($this, 'ur');

    expect($bodies)->toHaveCount(1);
    expect($bodies[0])->toMatch('/name="model"\s+whisper-large-v3\s/')
        ->toMatch('/name="response_format"\s+verbose_json/')
        ->toMatch('/name="temperature"\s+0\s/')
        ->toMatch('/name="timestamp_granularities\[\]"\s+segment/')
        ->toMatch('/name="timestamp_granularities\[\]"\s+word/')
        ->toMatch('/name="language"\s+ur\s/')
        ->toContain('meeting میں ہم invoice');
    expect($transcript->status)->toBe(TranscriptStatus::Completed)
        ->and($transcript->segments[0]['text'])->toBe('آپ کل کی meeting reschedule کر دیں')
        ->and($transcript->meta['model'])->toBe('whisper-large-v3')
        ->and($transcript->meta['audio_prep'])->toBe('whole_file');
});

it('runs a chunk again in Urdu when Auto hears French, and when Whisper writes Hindi letters', function () {
    noPrep();
    $bodies = [];
    $answers = [
        groqAnswer('french', [seg(0, 2, 'Bonjour à tous, la réunion est demain.')]),
        groqAnswer('urdu', [seg(0, 2, 'سب کو سلام، میٹنگ کل ہے')]),
        groqAnswer('hindi', [seg(0, 2, str_repeat('नमस्ते आप कैसे हैं ', 4))]),
        groqAnswer('urdu', [seg(0, 2, 'السلام علیکم آپ کیسے ہیں')]),
    ];
    Http::fake(['api.groq.com/*' => function (HttpRequest $r) use (&$bodies, &$answers) {
        $bodies[] = $r->body();

        return Http::response(array_shift($answers), 200);
    }]);

    $french = runGroq($this, null);
    expect($bodies[0])->not->toMatch('/name="language"/')
        ->and($bodies[1])->toMatch('/name="language"\s+ur\s/')
        ->and($french->segments[0]['text'])->toBe('سب کو سلام، میٹنگ کل ہے')
        ->and($french->language)->toBe('ur')
        ->and($french->meta['forced_chunks'])->toBe(1);

    $hindi = runGroq($this, null);
    expect($bodies[3])->toMatch('/name="language"\s+ur\s/')
        ->and($hindi->segments[0]['text'])->toBe('السلام علیکم آپ کیسے ہیں')
        ->and($hindi->text)->not->toMatch('/[\x{0900}-\x{097F}]/u');
});

it('removes what Whisper invents in silence and in loops, keeps unsure lines marked, and logs the removals', function () {
    noPrep();
    Http::fake(['api.groq.com/*' => Http::response(groqAnswer('english', [
        seg(0, 2, 'Hello, this is Omar.'),
        seg(2, 4, 'Thanks for watching!', ['no_speech_prob' => 0.92, 'avg_logprob' => -1.4]),
        seg(4, 6, 'the the the the the the the', ['compression_ratio' => 3.1]),
        seg(6, 8, 'Okay.'), seg(8, 9, 'Okay.'), seg(9, 10, 'Okay.'),
        seg(10, 12, 'I think the budget is final', ['avg_logprob' => -0.95]),
    ]), 200)]);

    $transcript = runGroq($this, 'en');

    expect(array_column($transcript->segments, 'text'))->toBe(['Hello, this is Omar.', 'Okay.', 'Okay.', 'I think the budget is final'])
        ->and($transcript->segments[3]['uncertain'] ?? false)->toBeTrue()
        ->and($transcript->segments[0])->not->toHaveKey('uncertain')
        ->and($transcript->meta['removed_count'])->toBe(3)
        ->and(array_column($transcript->meta['removed_segments'], 'reason'))->toBe(['silence', 'loop', 'repeat']);
});

it('sends each side of the call in chunks cut at pauses, with the exact offsets, and bills what was sent', function () {
    fakePrep();
    $sent = [];
    Http::fake(['api.groq.com/*' => function (HttpRequest $r) use (&$sent) {
        preg_match('/filename="(groq-chunk[^"]*)"/', $r->body(), $m);
        $sent[] = $m[1] ?? '?';

        return Http::response(groqAnswer('english', [seg(1.0, 2.0, 'Line '.count($sent))]), 200);
    }]);

    $transcript = runGroq($this, 'en');

    // Speech 0-1000.25 s (cut at the pause at 596 s) and 1009.75-1300 s, for each of the two sides.
    expect($sent)->toHaveCount(6)
        ->and($transcript->meta['chunks'])->toBe(6)
        ->and($transcript->meta['audio_prep'])->toBe('ffmpeg');
    $starts = collect($transcript->segments)->groupBy('channel')->map(fn ($s) => $s->pluck('start_ms')->sort()->values()->all())->all();
    expect($starts[1])->toBe([1000, 597000, 1010750])
        ->and($starts[2])->toBe([1000, 597000, 1010750])
        ->and($transcript->segments[0]['speaker'])->toBeIn(['Me', 'Client']);
    $entry = UsageLedgerEntry::query()->where('transcript_id', $transcript->id)->sole();
    // 596 + 404.25 + 290.25 seconds per side, two sides.
    expect($entry->duration_ms)->toBe(2 * (596000 + 404250 + 290250))->and($entry->channels)->toBe(1);
});

it('on Auto gives each side one language: Urdu anywhere runs that side\'s other chunks again as Urdu, never forcing English', function () {
    fakePrep();
    $bodies = [];
    // Side 1: Urdu, then Roman Urdu heard as English, then a long pure English stretch; side 2 all English.
    $answers = [
        groqAnswer('urdu', [seg(1.0, 3.0, 'السلام علیکم، آپ کیسے ہیں؟')]),
        groqAnswer('english', [seg(1.0, 3.0, 'kya kar rahe ho, meeting kab hai?')]),
        groqAnswer('english', [seg(1.0, 3.0, 'The invoice is ready and the payment is due on Friday.')]),
        groqAnswer('urdu', [seg(1.0, 3.0, 'کیا کر رہے ہو، meeting کب ہے؟')]),
        groqAnswer('english', [seg(1.0, 3.0, 'Hello, all good here.')]),
        groqAnswer('english', [seg(1.0, 3.0, 'Yes, I will send it.')]),
        groqAnswer('english', [seg(1.0, 3.0, 'Thank you, bye.')]),
    ];
    Http::fake(['api.groq.com/*' => function (HttpRequest $r) use (&$bodies, &$answers) {
        $bodies[] = $r->body();

        return Http::response(array_shift($answers), 200);
    }]);

    $transcript = runGroq($this, null);

    expect($bodies)->toHaveCount(7)
        ->and($bodies[3])->toMatch('/name="language"\s+ur\s/')
        ->and(collect($bodies)->filter(fn (string $b): bool => preg_match('/name="language"\s+en\s/', $b) === 1))->toBeEmpty();
    $side1 = collect($transcript->segments)->where('channel', 1)->sortBy('start_ms')->pluck('text')->values()->all();
    expect($side1)->toBe(['السلام علیکم، آپ کیسے ہیں؟', 'کیا کر رہے ہو، meeting کب ہے؟', 'The invoice is ready and the payment is due on Friday.'])
        ->and($transcript->meta['channel_languages'])->toBe([1 => 'ur', 2 => 'en'])
        ->and($transcript->meta['rerun_chunks'])->toBe(1)
        ->and($transcript->language)->toBe('ur');
});

it('plans chunks at pauses, leaves long silences out and never cuts more than the limit', function () {
    expect(AudioPrep::plan(1300, [[595, 597], [1000, 1010]], 600))->toBe([[0.0, 596.0], [596.0, 1000.25], [1009.75, 1300.0]])
        ->and(AudioPrep::plan(1500, [], 600))->toBe([[0.0, 600.0], [600.0, 1200.0], [1200.0, 1500.0]])
        ->and(AudioPrep::plan(30, [[0, 30]], 600))->toBe([])
        ->and(AudioPrep::parseSilences("[silencedetect @ 0x1] silence_start: 12.5\n[silencedetect @ 0x1] silence_end: 14.25 | silence_duration: 1.75\n[silencedetect] silence_start: 40", 50.0))
        ->toBe([[12.5, 14.25], [40.0, 50.0]]);
});

it('collapses a phrase loop inside one line and keeps normal repetition', function () {
    expect(WhisperGuards::collapseLoop('thank you thank you thank you thank you thank you'))->toBe('thank you')
        ->and(WhisperGuards::collapseLoop('no no, I said no'))->toBe('no no, I said no');
});

it('writes Urdu letter forms and Urdu punctuation without touching words, English or digits', function () {
    expect(UrduText::normaliseUrdu('كيا آپ نے invoice بھیجا?'))->toBe('کیا آپ نے invoice بھیجا؟')
        ->and(UrduText::normaliseUrdu('یہ meeting ہے, کل 3.5 بجے.'))->toBe('یہ meeting ہے، کل 3.5 بجے۔')
        ->and(UrduText::normaliseUrdu('دیکھو'))->toBe('دیکھو')
        ->and(UrduText::normaliseUrdu('Hello, world.'))->toBe('Hello, world.');
});

it('routes Urdu to Groq first, English to AssemblyAI, Arabic to ElevenLabs, and skips Groq without a key', function () {
    foreach (['assemblyai', 'elevenlabs'] as $p) {
        app(CredentialVault::class)->put($p, 'api_key', $p.'_test_key_1234567890');
    }
    $router = app(LanguageRouter::class);
    expect($router->final('ur', SttUseCase::FinalTranscript)->provider)->toBe(SttProviderKey::Groq)
        ->and($router->final('en', SttUseCase::FinalTranscript)->provider)->toBe(SttProviderKey::AssemblyAi)
        ->and($router->final('ar', SttUseCase::FinalTranscript)->provider)->toBe(SttProviderKey::ElevenLabs);

    app(CredentialVault::class)->forget('groq', 'api_key');
    $decision = app(LanguageRouter::class)->final('ur', SttUseCase::FinalTranscript);
    expect($decision->provider)->toBe(SttProviderKey::ElevenLabs)->and($decision->wanted)->toBe(SttProviderKey::Groq)->and($decision->fallback)->toBeTrue();
    expect(GroqWhisperDriver::stylePrompt('ur', ['Omar', 'Acme Ltd']))->toEndWith('Omar, Acme Ltd.');
});
