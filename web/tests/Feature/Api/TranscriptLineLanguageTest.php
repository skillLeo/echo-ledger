<?php

declare(strict_types=1);

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\Transcript;
use App\Stt\Data\Segment;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

/*
 * Each line's language (2.0.0-o, docs/API.md "Segment"): `segments[].language` is the language the engine heard the line
 * in, or null when it said none (every line before 2.0.0-o), so the apps can skip a language's lines.
 */

beforeEach(function () {
    $this->device = Fx::device();
    $this->token = Fx::token($this->device);
    $this->session = Fx::session($this->device->user, $this->device, ['duration_ms' => 10_000]);
});

it('sends every segment with its language, null for a line from before 2.0.0-o', function () {
    $transcript = Transcript::query()->create(['capture_session_id' => $this->session->id, 'kind' => TranscriptKind::Final, 'provider' => 'groq',
        'status' => TranscriptStatus::Completed, 'language' => 'ur', 'text' => 'x', 'completed_at' => now(), 'segments' => [
            ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 900, 'text' => 'کیا حال ہے', 'language' => 'ur'],
            ['channel' => 2, 'speaker' => 'Client', 'start_ms' => 1000, 'end_ms' => 1900, 'text' => 'All good, thanks', 'language' => 'en'],
            ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 2000, 'end_ms' => 2900, 'text' => 'An older line'],
        ]]);

    $this->api($this->token)->getJson("/api/transcripts/{$transcript->id}")->assertOk()
        ->assertJsonPath('segments.0.language', 'ur')
        ->assertJsonPath('segments.1.language', 'en')
        ->assertJsonPath('segments.2.language', null)
        ->assertJsonPath('segments.2.text', 'An older line');
    expect(array_key_exists('language', $this->api($this->token)->getJson("/api/transcripts/{$transcript->id}")->json('segments.2')))->toBeTrue();
});

it('keeps a line\'s language through an edit, and never shows another person\'s transcript', function () {
    $transcript = Transcript::query()->create(['capture_session_id' => $this->session->id, 'kind' => TranscriptKind::Final, 'provider' => 'groq',
        'status' => TranscriptStatus::Completed, 'language' => 'en', 'text' => 'x', 'completed_at' => now(), 'segments' => [
            ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 900, 'text' => 'Helo there', 'language' => 'en'],
        ]]);

    $this->api($this->token)->patchJson("/api/transcripts/{$transcript->id}", ['edits' => [['index' => 0, 'text' => 'Hello there']]])->assertOk()
        ->assertJsonPath('segments.0.text', 'Hello there')
        ->assertJsonPath('segments.0.language', 'en');

    $stranger = Fx::device();
    $this->api(Fx::token($stranger))->getJson("/api/transcripts/{$transcript->id}")->assertNotFound();
});

it('takes the language of each line a device uploads, kept only when it is one of the server\'s languages', function () {
    $line = ['channel' => 1, 'start_ms' => 0, 'end_ms' => 900, 'text' => 'Hello'];

    $this->api($this->token)->postJson("/api/sessions/{$this->session->id}/transcripts", ['kind' => 'live', 'provider' => 'assemblyai', 'segments' => [
        ['language' => 'en'] + $line,
        ['language' => 'Urdu', 'start_ms' => 1000, 'end_ms' => 1900] + $line,
        ['language' => 'xx', 'start_ms' => 2000, 'end_ms' => 2900] + $line,
        ['start_ms' => 3000, 'end_ms' => 3900] + $line,
    ]])->assertCreated()
        ->assertJsonPath('segments.0.language', 'en')
        ->assertJsonPath('segments.1.language', 'ur')
        ->assertJsonPath('segments.2.language', null)
        ->assertJsonPath('segments.3.language', null);

    foreach ([123, ['en'], str_repeat('e', 17)] as $bad) {
        $this->api($this->token)->postJson("/api/sessions/{$this->session->id}/transcripts", ['kind' => 'live', 'provider' => 'assemblyai', 'segments' => [['language' => $bad] + $line]])
            ->assertUnprocessable()->assertJsonValidationErrors(['segments.0.language']);
    }
});

it('keeps the language through the shared segment shape, and leaves it out when unknown', function () {
    $heard = Segment::fromArray(['channel' => 2, 'speaker' => 'Client', 'start_ms' => 5, 'end_ms' => 9, 'text' => 'Salaam', 'language' => 'hi']);
    $plain = Segment::fromArray(['channel' => 1, 'start_ms' => 0, 'end_ms' => 1, 'text' => 'x']);

    expect($heard->language)->toBe('ur')
        ->and($heard->toArray()['language'])->toBe('ur')
        ->and(Segment::fromArray($heard->toArray())->language)->toBe('ur')
        ->and($plain->language)->toBeNull()
        ->and($plain->toArray())->not->toHaveKey('language')
        ->and($plain->withLanguage('en')->toArray()['language'])->toBe('en');
});
