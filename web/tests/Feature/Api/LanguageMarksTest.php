<?php

declare(strict_types=1);

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Jobs\TranscribeFinalJob;
use App\Models\CaptureSession;
use App\Services\CredentialVault;
use App\Services\SessionMarkers;
use App\Services\SettingsRepository;
use App\Stt\Audio\AudioPrep;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
 * Changing the language during a recording (v1.2): the apps send `language_marks`; the chunked engines cut at them
 * and transcribe each piece in its language. The user's marks win over detection.
 */

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll(platform: 'android');
    $this->asToken($this->token);
});

it('keeps the marks in order, as today\'s languages, without repeats', function () {
    expect(SessionMarkers::cleanLanguageMarks([
        ['at_ms' => 9000, 'language' => 'en'],
        ['at_ms' => 1000, 'language' => 'en+ur'],
        ['at_ms' => 4000, 'language' => 'ur'],
        ['at_ms' => 6000, 'language' => null],
        'junk',
    ]))->toBe([
        ['at_ms' => 1000, 'language' => 'ur'],
        ['at_ms' => 6000, 'language' => null],
        ['at_ms' => 9000, 'language' => 'en'],
    ]);
    $marks = [['at_ms' => 1000, 'language' => 'ur'], ['at_ms' => 9000, 'language' => 'en']];
    expect(SessionMarkers::languageAt($marks, 500, 'ar'))->toBe('ar')
        ->and(SessionMarkers::languageAt($marks, 1000, 'ar'))->toBe('ur')
        ->and(SessionMarkers::languageAt($marks, 20000, 'ar'))->toBe('en');
});

it('stores the marks sent when an open session is finished', function () {
    Queue::fake();
    $id = $this->openSession(['client_ref' => 'marks-1']);

    $this->postJson("/api/sessions/{$id}/finish", Fixtures::finishBody(Fixtures::audio(4000), [
        'language' => 'en',
        'language_marks' => [['at_ms' => 0, 'language' => 'ur'], ['at_ms' => 120000, 'language' => 'en']],
    ]))->assertOk();

    $session = unscoped(fn () => CaptureSession::query()->find($id));
    expect($session->language_marks)->toBe([['at_ms' => 0, 'language' => 'ur'], ['at_ms' => 120000, 'language' => 'en']]);
    $this->postJson("/api/sessions/{$id}/finish", Fixtures::finishBody(Fixtures::audio(4000), ['language_marks' => [['at_ms' => -5]]]))->assertStatus(422);
});

it('never lets a chunk span a language change', function () {
    expect(AudioPrep::plan(1300, [[595, 597], [1000, 1010]], 600, 3.0, [700.0]))
        ->toBe([[0.0, 596.0], [596.0, 700.0], [700.0, 1000.25], [1009.75, 1300.0]]);
});

it('transcribes each chunk in the language the chip showed there', function () {
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
            return 1300.0;
        }

        public function silences(string $wav): array
        {
            return [[595.0, 597.0], [1000.0, 1010.0]];
        }

        public function cut(string $wav, float $startS, float $endS, string $out): string
        {
            file_put_contents($out, 'chunk');

            return $out;
        }
    });
    app(CredentialVault::class)->put('groq', 'api_key', 'gsk_test_key_1234567890');
    $languages = [];
    Http::fake(['api.groq.com/*' => function (HttpRequest $r) use (&$languages) {
        preg_match('/name="language"\s+(\w+)/', $r->body(), $m);
        $languages[] = $m[1] ?? 'auto';

        return Http::response(['language' => 'urdu', 'segments' => [['start' => 1.0, 'end' => 2.0, 'text' => 'ٹھیک ہے', 'avg_logprob' => -0.2, 'compression_ratio' => 1.2, 'no_speech_prob' => 0.01]], 'words' => []], 200);
    }]);
    // Urdu from the start, English from 700 s (the chip was changed mid-call).
    $session = $this->makeReadySession($this->device, Fixtures::audio(4000), ['language' => 'en', 'final_provider' => 'groq',
        'language_marks' => [['at_ms' => 0, 'language' => 'ur'], ['at_ms' => 700000, 'language' => 'en']]]);
    $transcript = $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'groq', 'status' => TranscriptStatus::Pending, 'language' => 'en']);

    dispatch_sync(new TranscribeFinalJob($transcript->id));

    // Per side: 0-596 Urdu, 596-700 Urdu, 700-1000 English, 1010-1300 English.
    expect($languages)->toBe(['ur', 'ur', 'en', 'en', 'ur', 'ur', 'en', 'en'])
        ->and($transcript->refresh()->status)->toBe(TranscriptStatus::Completed);
});
