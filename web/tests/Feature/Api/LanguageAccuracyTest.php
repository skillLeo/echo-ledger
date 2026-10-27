<?php

declare(strict_types=1);

use App\Enums\SttProviderKey;
use App\Enums\SttUseCase;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Jobs\TranscribeFinalJob;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Transcript;
use App\Services\CredentialVault;
use App\Services\FinalTranscriptCoordinator;
use App\Services\SettingsRepository;
use App\Services\TranscriptStore;
use App\Stt\Data\NormalizedTranscript;
use App\Stt\Data\Segment;
use App\Stt\LanguageRouter;
use App\Stt\ScriptCheck;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
 * Language accuracy (v1.1.2): the language decides the engine (manual > client > console default), each language goes
 * to an engine that supports it with a fallback when a key is missing, the language is always sent explicitly when it
 * is known, and a transcript in the wrong script is flagged.
 */

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll(platform: 'android');
    $this->asToken($this->token);
});

function keys(string ...$providers): void
{
    foreach ($providers as $provider) {
        app(CredentialVault::class)->put($provider, 'api_key', $provider.'_test_key_1234567890');
    }
}

describe('which language a recording uses', function () {
    it('takes the recording\'s own choice, then its client\'s, then the console default', function (?string $own, ?string $client, ?string $default, ?string $expected) {
        app(SettingsRepository::class)->set('default_language', $default);
        $model = Client::factory()->create(['user_id' => $this->device->user_id, 'language' => $client]);
        $session = $this->makeReadySession($this->device, Fixtures::audio(4000), ['language' => $own, 'client_id' => $model->id]);

        expect(app(LanguageRouter::class)->languageFor($session))->toBe($expected);
    })->with([
        'manual wins' => ['en', 'ur', 'hi', 'en'],
        'client next' => [null, 'en+ur', 'hi', 'en+ur'],
        'console default last' => [null, null, 'hi', 'hi'],
        'automatic' => [null, null, null, null],
    ]);

    it('starts the final transcript in that language with the routed engine and records the route', function () {
        Queue::fake();
        keys('assemblyai', 'elevenlabs');
        $client = Client::factory()->create(['user_id' => $this->device->user_id, 'language' => 'en+ur']);
        $session = $this->makeReadySession($this->device, Fixtures::audio(4000), ['client_id' => $client->id]);

        $transcript = app(FinalTranscriptCoordinator::class)->start($session);

        expect($transcript->language)->toBe('en+ur')
            ->and($transcript->provider)->toBe('elevenlabs')
            ->and($transcript->meta['route'])->toBe(['used' => 'elevenlabs', 'wanted' => 'elevenlabs', 'language' => 'en+ur', 'fallback' => false])
            ->and($session->fresh()->final_provider)->toBe('elevenlabs');
    });

    it('uses the language chosen on the Keep card when the open session is finished', function () {
        Queue::fake();
        keys('assemblyai', 'elevenlabs');
        $id = $this->openSession(['client_ref' => 'keep-card-1']);
        $bytes = Fixtures::audio(4000);

        $this->postJson("/api/sessions/{$id}/finish", Fixtures::finishBody($bytes, ['language' => 'ur']))->assertOk();
        $session = unscoped(fn () => CaptureSession::query()->find($id));

        expect($session->language)->toBe('ur');
        $transcript = app(FinalTranscriptCoordinator::class)->start($session);
        expect($transcript->language)->toBe('ur')->and($transcript->provider)->toBe('elevenlabs');
    });

    it('keeps the engine chosen at creation for automatic, so the uploaded files still fit', function () {
        Queue::fake();
        $session = $this->makeReadySession($this->device, Fixtures::audio(4000), ['final_provider' => 'elevenlabs']);

        expect(app(FinalTranscriptCoordinator::class)->start($session)->provider)->toBe('elevenlabs');
    });
});

describe('routing each language to an engine', function () {
    it('routes Urdu and Urdu + English to ElevenLabs, English and Hindi to AssemblyAI', function () {
        keys('assemblyai', 'elevenlabs');
        $router = app(LanguageRouter::class);

        expect($router->final('ur', SttUseCase::FinalTranscript)->provider)->toBe(SttProviderKey::ElevenLabs)
            ->and($router->final('en+ur', SttUseCase::FinalTranscript)->provider)->toBe(SttProviderKey::ElevenLabs)
            ->and($router->final('en', SttUseCase::FinalTranscript)->provider)->toBe(SttProviderKey::AssemblyAi)
            ->and($router->final('hi', SttUseCase::FinalTranscript)->provider)->toBe(SttProviderKey::AssemblyAi)
            ->and($router->final(null, SttUseCase::FinalTranscript)->provider)->toBe(SttProviderKey::AssemblyAi);
    });

    it('falls back to the next engine with a key and says so', function () {
        keys('assemblyai', 'soniox');
        $decision = app(LanguageRouter::class)->final('ur', SttUseCase::FinalTranscript);

        expect($decision->provider)->toBe(SttProviderKey::Soniox)
            ->and($decision->wanted)->toBe(SttProviderKey::ElevenLabs)
            ->and($decision->fallback)->toBeTrue();

        app(CredentialVault::class)->forget('soniox', 'api_key');
        expect(app(LanguageRouter::class)->final('ur', SttUseCase::FinalTranscript)->provider)->toBe(SttProviderKey::AssemblyAi);
    });

    it('follows the order the console saved, and ignores unknown engines', function () {
        keys('assemblyai', 'elevenlabs', 'soniox');
        app(SettingsRepository::class)->set('routing.final', ['ur' => ['skynet', 'soniox', 'elevenlabs']]);

        expect(app(LanguageRouter::class)->final('ur', SttUseCase::FinalTranscript)->provider)->toBe(SttProviderKey::Soniox)
            ->and(app(LanguageRouter::class)->final('en', SttUseCase::FinalTranscript)->provider)->toBe(SttProviderKey::AssemblyAi);
    });

    it('never picks Azure MAI or Parakeet for Urdu, which they do not support', function () {
        keys('azure');
        app(SettingsRepository::class)->set('routing.final', ['ur' => ['azure_mai', 'parakeet_local']]);
        $router = app(LanguageRouter::class);

        expect($router->supportsFinal(SttProviderKey::AzureMai, 'ur'))->toBeFalse()
            ->and($router->supportsFinal(SttProviderKey::ParakeetLocal, 'ur'))->toBeFalse()
            ->and($router->final('ur', SttUseCase::FinalTranscript)->fallback)->toBeTrue();
    });

    it('moves the comparison winner to the front of its language', function () {
        app(LanguageRouter::class)->prefer('en+ur', SttProviderKey::Soniox);

        expect(app(LanguageRouter::class)->routes('routing.final', LanguageRouter::DEFAULT_FINAL_ROUTES)['en+ur'])->toBe(['soniox', 'elevenlabs', 'assemblyai']);
    });
});

describe('telling the engine the language', function () {
    it('sends Urdu + English to ElevenLabs as Urdu, never as automatic detection', function () {
        keys('elevenlabs');
        $captured = null;
        Http::fake(['api.elevenlabs.io/v1/speech-to-text' => function (HttpRequest $request) use (&$captured) {
            $captured = $request->body();

            return Http::response(['transcription_id' => 'tr_1', 'language_code' => 'ur', 'transcripts' => [
                ['channel_index' => 0, 'words' => [['text' => 'سلام', 'start' => 0.1, 'end' => 0.3, 'type' => 'word']]],
                ['channel_index' => 1, 'words' => [['text' => 'جی', 'start' => 1.1, 'end' => 1.5, 'type' => 'word']]],
            ]], 200);
        }]);
        $session = $this->makeReadySession($this->device, Fixtures::audio(4000), ['language' => 'en+ur', 'final_provider' => 'elevenlabs']);
        $transcript = $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'elevenlabs', 'status' => TranscriptStatus::Pending, 'language' => 'en+ur']);

        dispatch_sync(new TranscribeFinalJob($transcript->id));

        expect($captured)->toMatch('/name="language_code"\s+ur/');
        expect($transcript->refresh()->language)->toBe('en+ur')->and($transcript->meta['detected_language'])->toBe('ur');
    });

    it('steers AssemblyAI live captions to the chosen languages with language_codes', function () {
        $this->configureAssemblyAi();
        Http::fake(['streaming.assemblyai.com/v3/token*' => Http::response(['token' => 'tmp_stream_token'], 200)]);
        $id = $this->openSession(['client_ref' => 'live-ur']);

        $grants = $this->postJson('/api/stt/token', ['use_case' => 'live_captions', 'session_id' => $id, 'channels' => ['me'], 'language' => 'ur+en'])->assertOk()->json('grants');
        expect($grants[0]['init_messages'][0])->toMatchArray(['type' => 'UpdateConfiguration', 'language_codes' => ['en', 'ur']]);

        $auto = $this->postJson('/api/stt/token', ['use_case' => 'live_captions', 'session_id' => $id, 'channels' => ['me']])->json('grants');
        expect(json_encode($auto))->not->toContain('language_codes');
    });

    it('switches live captions for Urdu to ElevenLabs when the AssemblyAI streaming model has no Urdu', function () {
        $this->configureAssemblyAi();
        keys('elevenlabs');
        app(SettingsRepository::class)->set('assemblyai.streaming_model', 'universal-streaming-english');
        Http::fake(['api.elevenlabs.io/v1/single-use-token/*' => Http::response(['token' => 'xi_single'], 200), '*' => Http::response(['token' => 'x'], 200)]);
        $id = $this->openSession(['client_ref' => 'live-ur-2']);

        $response = $this->postJson('/api/stt/token', ['use_case' => 'live_captions', 'session_id' => $id, 'channels' => ['me'], 'language' => 'ur'])->assertOk();

        expect($response->json('provider'))->toBe('elevenlabs')->and($response->json('grants.0.ws_url'))->toContain('language_code=ur');
    });
});

describe('the script of the text', function () {
    it('names Devanagari in an Urdu transcript and Urdu script in a Hindi one', function () {
        $hindiScript = str_repeat('नमस्ते आप कैसे हैं ', 5);
        $urduScript = str_repeat('السلام علیکم آپ کیسے ہیں ', 5);

        expect(ScriptCheck::wrongScript('ur', $hindiScript))->toBe('devanagari')
            ->and(ScriptCheck::wrongScript('en+ur', $hindiScript))->toBe('devanagari')
            ->and(ScriptCheck::wrongScript('ur', $urduScript))->toBeNull()
            ->and(ScriptCheck::wrongScript('hi', $urduScript))->toBe('arabic')
            ->and(ScriptCheck::wrongScript('hi', $hindiScript))->toBeNull()
            ->and(ScriptCheck::wrongScript(null, $hindiScript))->toBeNull();
    });

    it('accepts English words inside Urdu + English but not a whole Urdu call in Latin letters', function () {
        $roman = str_repeat('aap kaise hain meeting kal hai ', 4);
        $mixed = 'میں نے invoice بھیج دیا ہے اور meeting کل ہوگی '.str_repeat('پھر ہم بات کریں گے ', 3);

        expect(ScriptCheck::wrongScript('en+ur', $roman))->toBeNull()
            ->and(ScriptCheck::wrongScript('ur', $roman))->toBe('latin')
            ->and(ScriptCheck::wrongScript('en+ur', $mixed))->toBeNull()
            ->and(ScriptCheck::wrongScript('ur', 'नमस्ते'))->toBeNull(); // too short to judge
    });

    it('marks a finished transcript and shows the warning to the apps', function () {
        $session = $this->makeReadySession($this->device, Fixtures::audio(4000), ['language' => 'ur']);
        $transcript = $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Processing, 'language' => 'ur']);
        $text = str_repeat('नमस्ते आप कैसे हैं ', 5);

        app(TranscriptStore::class)->complete($transcript, new NormalizedTranscript($text, [new Segment(1, 'Me', 0, 1000, $text)], 'hi', []));

        expect($transcript->refresh()->meta['script_warning'])->toBe('devanagari');
        $this->getJson("/api/transcripts/{$transcript->id}")->assertOk()->assertJsonPath('script_warning', 'devanagari')->assertJsonPath('language', 'ur');
        $item = collect($this->getJson('/api/library')->json('data'))->firstWhere('id', $session->id);
        expect($item['transcript']['script_warning'])->toBe('devanagari')->and($item['language_chip'])->toBe('UR');
    });
});

describe('what the apps learn about languages', function () {
    it('lists the language choices with their chips and the console default', function () {
        app(SettingsRepository::class)->set('default_language', 'en+ur');

        $languages = $this->getJson('/api/config')->assertOk()->json('languages');

        expect($languages['default'])->toBe('en+ur')
            ->and(collect($languages['options'])->pluck('chip')->all())->toBe(['Auto', 'EN', 'UR', 'UR+EN', 'HI', 'HI+EN'])
            ->and($languages['options'][0]['value'])->toBeNull();
    });

    it('saves a client\'s language (Use Urdu for Ali from now on) and a recording\'s language', function () {
        $client = Client::factory()->create(['user_id' => $this->device->user_id, 'name' => 'Ali']);
        $this->patchJson("/api/clients/{$client->id}", ['language' => 'ur+en'])->assertOk()->assertJsonPath('data.language', 'en+ur');
        expect($client->fresh()->language)->toBe('en+ur');

        $session = $this->makeReadySession($this->device, Fixtures::audio(4000));
        $this->patchJson("/api/sessions/{$session->id}", ['language' => 'hi'])->assertOk()->assertJsonPath('data.language', 'hi');
        $this->patchJson("/api/sessions/{$session->id}", ['language' => 'auto'])->assertOk()->assertJsonPath('data.language', null);
    });
});

it('never shows a comparison run as the recording\'s transcript', function () {
    $session = $this->makeReadySession($this->device, Fixtures::audio(4000), ['status' => 'completed']);
    Transcript::query()->create(['capture_session_id' => $session->id, 'kind' => TranscriptKind::Trial, 'provider' => 'soniox', 'status' => TranscriptStatus::Completed, 'text' => 'trial text only']);

    expect($session->transcripts()->count())->toBe(0)->and($session->trials()->count())->toBe(1);
    $this->getJson("/api/sessions/{$session->id}")->assertOk()->assertJsonCount(0, 'transcripts');
    expect($this->getJson('/api/search?q=trial')->json('data'))->toBe([]);
});
