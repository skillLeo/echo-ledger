<?php

declare(strict_types=1);

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\ClientBrief;
use App\Models\Transcript;
use App\Services\Callers\CallerMatcher;
use App\Services\ClientAi\ClientWorkspace;
use App\Services\CredentialVault;
use App\Services\DoubleCheck;
use App\Services\SettingsRepository;
use App\Services\TranscriptEditor;
use App\Stt\SttManager;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

require_once __DIR__.'/Support/client_workspace_helpers.php';

/*
 * Accuracy boosters (v1.4, P5): the client's contacts and brief terms reach the engines, edits offer "Always write X
 * as Y for this client?", the far side is named after the caller, suggested fixes wait for Accept, and a client's
 * recordings are double-checked by default.
 */

beforeEach(function () {
    Storage::fake('recordings');
    [$this->device, $this->token] = $this->enroll(platform: 'android');
    $this->asToken($this->token);
    app(CredentialVault::class)->put('anthropic', 'api_key', 'sk-ant-test-1234567890');
    $this->client = Client::factory()->create(['user_id' => $this->device->user_id, 'name' => 'Acme']);
    $this->ai = fakeClientAi();
});

it('sends the engines the client\'s contacts and the people and terms of its brief', function () {
    $this->client->contacts()->create(['name' => 'Farah Siddiqui', 'phone' => '+923001234567']);
    ClientBrief::query()->create(['workspace_id' => $this->client->workspace_id, 'client_id' => $this->client->id,
        'sections' => ['goal' => 'Shop', 'people' => ['Ali (owner)'], 'glossary' => ['Laravel: the PHP framework', 'A term far too long to be a keyterm for any engine at all']]]);

    $terms = app(SttManager::class)->keytermsFor($this->client);

    expect($terms)->toContain('Farah Siddiqui', 'Ali', 'Laravel')
        ->not->toContain('A term far too long to be a keyterm for any engine at all');
});

it('offers the changed words of an edit as a rule for the client, and nothing for a punctuation fix', function () {
    expect(TranscriptEditor::learnable([0 => 'we use la ravel for the backend'], [0 => 'we use Laravel for the backend']))
        ->toBe([['from' => 'la ravel', 'to' => 'Laravel']])
        ->and(TranscriptEditor::learnable([0 => 'Hello, Ali'], [0 => 'Hello Ali.']))->toBe([])
        ->and(TranscriptEditor::learnable([0 => 'we use la ravel'], [0 => 'we use Laravel'], [['from' => 'la ravel', 'to' => 'Laravel']]))->toBe([])
        ->and(TranscriptEditor::learnable([0 => 'one two three four five six'], [0 => 'a b c d e f']))->toBe([]);

    $session = clientCall($this, $this->client);
    $transcript = Transcript::query()->where('capture_session_id', $session->id)->sole();
    $this->patchJson("/api/transcripts/{$transcript->id}", ['edits' => [['index' => 2, 'text' => 'Also add Dark Mode']]])->assertOk()
        ->assertJsonPath('learn.0.from', 'dark mode')
        ->assertJsonPath('learn.0.to', 'Dark Mode')
        ->assertJsonPath('learn.0.client_id', $this->client->id);

    $loose = $this->makeReadySession($this->device, Fixtures::audio(3000), ['status' => 'completed']);
    $other = $loose->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'groq', 'status' => TranscriptStatus::Completed, 'text' => 'x',
        'segments' => [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1000, 'text' => 'la ravel']]]);
    $this->patchJson("/api/transcripts/{$other->id}", ['edits' => [['index' => 0, 'text' => 'Laravel']]])->assertOk()->assertJsonPath('learn', []);
});

it('names the far side of a call after the person who called, but not after a meeting title', function () {
    $session = CaptureSession::factory()->create(['user_id' => $this->device->user_id, 'device_id' => $this->device->id]);
    app(CallerMatcher::class)->apply($session, ['name' => 'Omar Khan', 'source' => 'whatsapp_notification']);
    expect($session->refresh()->speaker_names)->toBe(['Client' => 'Omar Khan']);

    $meeting = CaptureSession::factory()->create(['user_id' => $this->device->user_id, 'device_id' => $this->device->id]);
    app(CallerMatcher::class)->apply($meeting, ['name' => 'Weekly sync', 'source' => 'window_title']);
    expect($meeting->refresh()->speaker_names)->toBeNull();

    $named = CaptureSession::factory()->create(['user_id' => $this->device->user_id, 'device_id' => $this->device->id, 'speaker_names' => ['Client' => 'Farah']]);
    app(CallerMatcher::class)->apply($named, ['name' => 'Omar Khan', 'source' => 'whatsapp_notification']);
    expect($named->refresh()->speaker_names)->toBe(['Client' => 'Farah']);
});

it('keeps only suggested fixes that are really in their line, and applies one only when accepted', function () {
    Queue::fake();
    $this->client->update(['replacements' => []]);
    $this->client->keyterms()->create(['term' => 'Login']);
    $session = clientCall($this, $this->client);
    app(ClientWorkspace::class)->makeNotes($session);
    $transcript = Transcript::query()->where('capture_session_id', $session->id)->sole();

    $this->getJson("/api/transcripts/{$transcript->id}")->assertOk()
        ->assertJsonCount(1, 'suggestions')
        ->assertJsonPath('suggestions.0', ['id' => 0, 'index' => 1, 'from' => 'login page', 'to' => 'Login page', 'reason' => 'product name', 'status' => 'open']);
    expect($transcript->refresh()->segments[1]['text'])->toBe('I need a login page');

    $this->postJson("/api/transcripts/{$transcript->id}/suggestions/0", ['accept' => true])->assertOk()
        ->assertJsonPath('segments.1.text', 'I need a Login page')
        ->assertJsonPath('suggestions', []);
    $this->postJson("/api/transcripts/{$transcript->id}/suggestions/0", ['accept' => false])->assertNotFound()->assertJsonPath('code', 'suggestion_not_found');
});

it('double-checks a client\'s recordings by default, even with the general switch off', function () {
    $session = clientCall($this, $this->client);
    $transcript = Transcript::query()->where('capture_session_id', $session->id)->sole();
    $transcript->update(['segments' => [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1000, 'text' => 'hello', 'uncertain' => true]]]);
    $check = app(DoubleCheck::class);

    expect($check->enabled())->toBeFalse()->and($check->wanted($transcript->refresh()))->toBeTrue();

    app(SettingsRepository::class)->set('accuracy.double_check_clients', false);
    expect($check->wanted($transcript))->toBeFalse();

    app(SettingsRepository::class)->set('accuracy.double_check_clients', true);
    $session->update(['client_id' => null]);
    expect($check->wanted($transcript))->toBeFalse();
});
