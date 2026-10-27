<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\SttProviderKey;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Jobs\TranscribeFinalJob;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\ClientKeyterm;
use App\Models\Transcript;
use App\Models\TranscriptRevision;
use App\Services\Retranscriber;
use App\Services\SettingsRepository;
use App\Services\TextReplacements;
use App\Services\TranscriptDiff;
use App\Services\TranscriptStore;
use App\Stt\Data\NormalizedTranscript;
use App\Stt\Data\Segment;
use App\Stt\LanguageChoice;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
 * Vocabulary 2.0 (global keyterms, text replacements, "Add to vocabulary"), language routing and re-transcribe and
 * compare (since v1.1).
 */

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll(platform: 'windows');
    // The shared (no client) vocabulary is server-wide: its writer is the super admin (B20).
    $this->device->user->forceFill(['is_super_admin' => true])->save();
    $this->asToken($this->token);
});

/** Runs the final job for a new session with $language and returns the body AssemblyAI received. */
function submittedBody(object $test, ?string $language, ?Client $client = null): array
{
    $test->configureAssemblyAi();
    $test->fakeAssemblyAi('job_lang');
    $session = $test->makeReadySession($test->device, Fixtures::audio(4000), ['language' => $language, 'client_id' => $client?->id]);
    $transcript = $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Pending]);
    dispatch_sync(new TranscribeFinalJob($transcript->id));
    $body = null;
    Http::assertSent(function (HttpRequest $r) use (&$body): bool {
        if (str_ends_with($r->url(), '/v2/transcript')) {
            $body = $r->data();

            return true;
        }

        return false;
    });

    return $body;
}

describe('language choices', function () {
    it('normalises what is stored', function () {
        expect(LanguageChoice::normalize(' UR '))->toBe('ur')
            ->and(LanguageChoice::normalize('auto'))->toBeNull()
            ->and(LanguageChoice::normalize(''))->toBeNull()
            ->and(LanguageChoice::normalize('en+ur'))->toBe('en+ur')
            ->and(LanguageChoice::normalize('en+ur+hi'))->toBe('en+ur')
            ->and(LanguageChoice::normalize('<script>'))->toBeNull()
            ->and(LanguageChoice::single('en+ur'))->toBeNull()
            ->and(LanguageChoice::single('hi'))->toBe('hi')
            ->and(LanguageChoice::assemblyAiCodeSwitch('en+ur'))->toBeTrue()
            ->and(LanguageChoice::assemblyAiCodeSwitch('hi+ur'))->toBeFalse()
            ->and(LanguageChoice::normalize('ur+en'))->toBe('en+ur')
            ->and(LanguageChoice::primary('en+ur'))->toBe('ur')
            ->and(LanguageChoice::primary('en'))->toBe('en')
            ->and(LanguageChoice::primary(null))->toBeNull()
            ->and(LanguageChoice::chip('en+ur'))->toBe('UR+EN')
            ->and(LanguageChoice::chip(null))->toBe('Auto')
            ->and(LanguageChoice::label('en+hi'))->toBe('Hindi + English')
            ->and(LanguageChoice::label('en+ur'))->toBe('Urdu + English')
            ->and(LanguageChoice::label(null))->toBe('Automatic');
    });

    it('sends English + Urdu to AssemblyAI as Universal-2 code switching', function () {
        $body = submittedBody($this, 'en+ur');

        expect($body['language_codes'])->toBe(['en', 'ur'])
            ->and($body['speech_models'])->toBe(['universal-2'])
            ->and($body)->not->toHaveKey('language_code')
            ->and($body)->not->toHaveKey('language_detection');
    });

    it('sends one language as its code and nothing as automatic detection', function () {
        expect(submittedBody($this, 'ur')['language_code'])->toBe('ur');
        Http::fake();
        $auto = submittedBody($this, null);
        expect($auto['language_detection'])->toBeTrue()->and($auto)->not->toHaveKey('language_code');
    });

    it('detects a pair without English automatically, as AssemblyAI requires English in the pair', function () {
        $body = submittedBody($this, 'hi+ur');

        expect($body['language_detection'])->toBeTrue()->and($body)->not->toHaveKey('language_codes');
    });

    it('falls back to the client language, then the console default', function () {
        $client = Client::factory()->create(['user_id' => $this->device->user_id, 'language' => 'hi']);
        expect(submittedBody($this, null, $client)['language_code'])->toBe('hi');

        Http::fake();
        app(SettingsRepository::class)->set('default_language', 'en+ur');
        expect(submittedBody($this, null)['language_codes'])->toBe(['en', 'ur']);
    });
});

describe('vocabulary', function () {
    it('sends the client keyterms first and then the global ones, without duplicates', function () {
        $client = Client::factory()->create(['user_id' => $this->device->user_id]);
        ClientKeyterm::query()->create(['client_id' => $client->id, 'term' => 'Farah Khan']);
        app(SettingsRepository::class)->set('vocabulary.keyterms', ['EchoLedger', 'farah khan']);

        expect(submittedBody($this, 'en', $client)['keyterms_prompt'])->toBe(['Farah Khan', 'EchoLedger']);
    });

    it('applies text replacements to a new final transcript and keeps the original restorable', function () {
        $client = Client::factory()->create(['user_id' => $this->device->user_id, 'replacements' => [['from' => 'acme limited', 'to' => 'Acme Ltd']]]);
        app(SettingsRepository::class)->set('vocabulary.replacements', [['from' => 'eco ledger', 'to' => 'EchoLedger'], ['from' => 'Acme Ltd', 'to' => 'WRONG']]);
        $session = CaptureSession::factory()->create(['user_id' => $this->device->user_id, 'device_id' => $this->device->id, 'client_id' => $client->id]);
        $transcript = $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Processing]);

        app(TranscriptStore::class)->complete($transcript, new NormalizedTranscript('x', [
            new Segment(1, 'Me', 0, 1000, 'Welcome to eco ledger, Acme Limited.'),
        ]));

        $fresh = $transcript->fresh();
        expect($fresh->segments[0]['text'])->toBe('Welcome to EchoLedger, WRONG.')
            ->and(TranscriptRevision::query()->where('source', 'original')->sole()->segments[0]['text'])->toBe('Welcome to eco ledger, Acme Limited.')
            ->and(TranscriptRevision::query()->where('source', 'replace')->sole()->author)->toBe('Vocabulary');
        $this->postJson("/api/transcripts/{$transcript->id}/restore")->assertOk()->assertJsonPath('segments.0.text', 'Welcome to eco ledger, Acme Limited.');
    });

    it('leaves live captions and transcripts without rules alone', function () {
        app(SettingsRepository::class)->set('vocabulary.replacements', [['from' => 'hello', 'to' => 'Hi']]);
        $session = CaptureSession::factory()->create(['user_id' => $this->device->user_id, 'device_id' => $this->device->id]);
        $live = $session->transcripts()->create(['kind' => TranscriptKind::Live, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Processing]);
        app(TranscriptStore::class)->complete($live, new NormalizedTranscript('x', [new Segment(1, 'Me', 0, 1000, 'hello there')]));

        expect($live->fresh()->segments[0]['text'])->toBe('hello there')->and(TranscriptRevision::query()->count())->toBe(0);
    });

    it('cleans replacement rules', function () {
        expect(TextReplacements::rules([
            ['from' => ' acme ', 'to' => 'Acme'], ['from' => 'ACME', 'to' => 'x'], ['from' => '', 'to' => 'y'], 'junk', ['from' => 'same', 'to' => 'same'],
        ]))->toBe([['from' => 'acme', 'to' => 'Acme', 'match_case' => false, 'whole_word' => true]]);
        [$segments, $count] = TextReplacements::apply([['text' => 'Acme acmes ACME', 'words' => [1]]], TextReplacements::rules([['from' => 'acme', 'to' => 'Acme Ltd']]));
        expect($count)->toBe(2)->and($segments[0]['text'])->toBe('Acme Ltd acmes Acme Ltd')->and($segments[0])->not->toHaveKey('words');
    });

    it('shows and extends the vocabulary from the apps', function () {
        $client = Client::factory()->create(['user_id' => $this->device->user_id]);

        $this->postJson('/api/vocabulary/keyterms', ['term' => 'EchoLedger'])->assertCreated()->assertJsonPath('keyterms', ['EchoLedger']);
        $this->postJson('/api/vocabulary/keyterms', ['term' => 'echoledger'])->assertCreated()->assertJsonPath('keyterms', ['EchoLedger']);
        $this->postJson('/api/vocabulary/keyterms', ['term' => 'Farah Khan', 'client_id' => $client->id])->assertCreated()->assertJsonPath('keyterms', ['Farah Khan']);
        $this->postJson('/api/vocabulary/replacements', ['from' => 'acme limited', 'to' => 'Acme Ltd', 'client_id' => $client->id])->assertCreated()
            ->assertJsonPath('replacements.0.to', 'Acme Ltd');

        $shown = $this->getJson('/api/vocabulary?client_id='.$client->id)->assertOk();
        expect($shown->json('keyterms'))->toBe(['EchoLedger'])
            ->and($shown->json('client.keyterms'))->toBe(['Farah Khan'])
            ->and($shown->json('client.replacements.0.from'))->toBe('acme limited');
    });

    it('refuses bad terms, other owners\' clients and a full vocabulary', function () {
        $this->postJson('/api/vocabulary/keyterms', ['term' => str_repeat('word ', 6)])->assertUnprocessable()->assertJsonPath('code', 'validation_failed');
        $foreign = Client::factory()->create();
        $this->postJson('/api/vocabulary/keyterms', ['term' => 'x', 'client_id' => $foreign->id])->assertNotFound()->assertJsonPath('code', 'client_not_found');
        app(SettingsRepository::class)->set('vocabulary.keyterms', array_map(fn (int $i) => "term {$i}", range(1, 200)));
        $this->postJson('/api/vocabulary/keyterms', ['term' => 'one more'])->assertStatus(409)->assertJsonPath('code', 'vocabulary_full');
        $this->postJson('/api/vocabulary/replacements', ['from' => '  ', 'to' => 'x'])->assertUnprocessable();
    });
});

describe('re-transcribe and compare', function () {
    function finishedSession(object $test): CaptureSession
    {
        $session = $test->makeReadySession($test->device, Fixtures::audio(4000), ['status' => SessionStatus::Completed, 'final_provider' => 'assemblyai']);
        $session->transcripts()->create([
            'kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Completed,
            'segments' => [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1000, 'text' => 'The price is fine']],
            'meta' => ['speech_model_used' => 'universal-3-5-pro'],
        ]);

        return $session;
    }

    it('starts a second version with another engine and keeps the final one', function () {
        Queue::fake();
        $session = finishedSession($this);

        $alternate = app(Retranscriber::class)->start($session, SttProviderKey::ElevenLabs, 'ur');

        expect($alternate->kind)->toBe(TranscriptKind::Alternate)
            ->and($alternate->provider)->toBe('elevenlabs')
            ->and($alternate->language)->toBe('ur')
            ->and($session->finalTranscript->provider)->toBe('assemblyai');
        Queue::assertPushed(TranscribeFinalJob::class, fn (TranscribeFinalJob $job) => $job->transcriptId === $alternate->id);
    });

    it('replaces an earlier second version', function () {
        Queue::fake();
        $session = finishedSession($this);
        $first = app(Retranscriber::class)->start($session, SttProviderKey::ElevenLabs);
        app(Retranscriber::class)->start($session, SttProviderKey::AzureMai);

        expect(Transcript::query()->find($first->id))->toBeNull()
            ->and($session->transcripts()->where('kind', 'alternate')->sole()->provider)->toBe('azure_mai');
    });

    it('needs a finished transcript', function () {
        $session = $this->makeReadySession($this->device, Fixtures::audio(4000));
        expect(fn () => app(Retranscriber::class)->start($session, SttProviderKey::ElevenLabs))->toThrow(RuntimeException::class);
    });

    it('makes the second version the final one and keeps the old one as the second', function () {
        $session = finishedSession($this);
        $oldFinal = $session->finalTranscript;
        $alternate = $session->transcripts()->create([
            'kind' => TranscriptKind::Alternate, 'provider' => 'elevenlabs', 'status' => TranscriptStatus::Completed,
            'segments' => [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1000, 'text' => 'The prize is fine']],
        ]);

        app(Retranscriber::class)->choose($alternate);

        expect($alternate->fresh()->kind)->toBe(TranscriptKind::Final)
            ->and($oldFinal->fresh()->kind)->toBe(TranscriptKind::Alternate)
            ->and($session->fresh()->final_provider)->toBe('elevenlabs');
        expect(collect($this->getJson('/api/library')->json('data'))->firstWhere('id', $session->id)['transcript']['final_transcript_id'])->toBe($alternate->id);
    });

    it('marks the words that differ, window by window', function () {
        $diff = TranscriptDiff::compare(
            [['start_ms' => 0, 'text' => 'The price is fine.'], ['start_ms' => 40_000, 'text' => 'See you Friday']],
            [['start_ms' => 500, 'text' => 'the prize is fine'], ['start_ms' => 41_000, 'text' => 'See you on Friday']],
        );

        expect($diff['rows'])->toHaveCount(2)
            ->and($diff['rows'][0]['a'])->toBe([['The', false], ['price', true], ['is', false], ['fine.', false]])
            ->and($diff['rows'][0]['b'][1])->toBe(['prize', true])
            ->and($diff['rows'][1]['b'])->toBe([['See', false], ['you', false], ['on', true], ['Friday', false]])
            ->and($diff['changed'])->toBe(3);
    });

    it('shows the model the engine used', function () {
        $session = finishedSession($this);
        $this->getJson('/api/transcripts/'.$session->finalTranscript->id)->assertOk()->assertJsonPath('model', 'universal-3-5-pro');
    });
});
