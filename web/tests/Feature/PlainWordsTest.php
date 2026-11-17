<?php

declare(strict_types=1);

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIStatusException;
use App\Enums\TranscriptErrorCode;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Filament\Support\UpgradeFlow;
use App\Models\CallNote;
use App\Models\CaptureSession;
use App\Models\ClientBrief;
use App\Models\ClientFile;
use App\Models\Plan;
use App\Models\Transcript;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Accounts\SettingsRegistry;
use App\Services\ClientAi\ClaudeClientAi;
use App\Services\ClientAi\ClientAi;
use App\Services\ClientAi\ClientWorkspace;
use App\Services\CredentialVault;
use App\Services\Entitlements\Entitlements;
use App\Services\Entitlements\Features;
use App\Services\TranscriptExporter;
use App\Services\Views\TranscriptViews;
use App\Support\ProviderText;
use Filament\Facades\Filament;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Psr\Http\Message\RequestInterface;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

/*
| No provider, model or vendor names in what people read (since v1.8.1, D-129, and the UI sweep's rule 5): the features'
| names and descriptions the API sends (the apps show them on the plan badge, its screen-reader name and the upgrade
| sheet), the plan pages, the messages and the console. The one name people act on stays: the Free plan's own Groq key.
| The feature keys never change: the apps key on them.
*/

/** Provider, model and vendor names people must not read. */
const VENDOR_NAMES = '/\b(?:Claude|ChatGPT|OpenAI|Anthropic|Gemini|ElevenLabs|Groq|AssemblyAI|Soniox|Azure|Whisper|Parakeet|Haiku|Sonnet|universal-\d|claude-[a-z]+)\b/';

/** The vendor names in [$text], leaving out the one people act on: the Free plan's own Groq key, and where to make it. */
function vendorNamesIn(string $text): array
{
    $text = (string) preg_replace(['/\bGroq key\b/', '/\bconsole\.groq\.com\b/'], '', $text);
    preg_match_all(VENDOR_NAMES, $text, $found);

    return array_values(array_unique($found[0]));
}

/**
 * Where the code and the views may still write a provider's name, and why: what only the server's operator reads (they
 * add each provider's key and pick the engines), where a person acts on a provider by name, and the law. A path ending
 * in "/" is a folder.
 */
const VENDOR_NAMES_ALLOWED = [
    // The admin panel, the operator's commands and the admin Settings' search.
    'app/Filament/Admin/' => 'the operator adds the keys and picks the engines',
    'app/Console/Commands/' => 'the operator runs them',
    'app/Services/SettingsSearch.php' => 'the admin Settings\' search',
    'resources/views/filament/pages/accuracy-lab.blade.php' => 'the admin Accuracy Lab',
    'resources/views/filament/pages/server-usage.blade.php' => 'the admin Server usage',
    // Shown only with a link to API Credentials, which only the operator opens.
    'resources/views/filament/clients/tab-overview.blade.php' => 'the operator\'s "Add your Anthropic key"',
    'resources/views/filament/clients/tab-ask.blade.php' => 'the operator\'s "Add your Anthropic key"',
    // Engine names: the engine settings, the console's engine tools that pick one by name, the operator's "Transcribed
    // by", and the API's provider_label (docs/API.md).
    'app/Enums/SttProviderKey.php' => 'engines picked by name',
    // An engine's own error text: the operator's Error row and the logs; people read TranscriptErrorCode::plainWords().
    'app/Stt/Drivers/' => 'the engines\' own error text',
    // The Free plan's own Groq key, checked with Groq.
    'app/Services/Entitlements/TranscriptionKeys.php' => 'the Free plan\'s own key',
    'app/Filament/Auth/Settings/ManagesAppPages.php' => 'the Free plan\'s own key',
    // The pattern that keeps them from people, never shown itself.
    'app/Support/ProviderText.php' => 'the names it hides',
    // The admin panel's cost per provider (Server usage, a customer's page); the console's Usage page shows the month
    // only added up, and its CSV per use (W-001).
    'app/Services/Usage/UsageBreakdown.php' => 'the admin panel\'s cost per provider',
    'app/Services/Usage/UsageReport.php' => 'the month per engine, for the ledger\'s checks; the console adds it up',
    // A person adds the connector in Claude or ChatGPT themselves, and the website says where it works.
    'resources/views/auth/connector-consent.blade.php' => 'the person adds the connector there',
    'resources/views/site/home.blade.php' => 'where the connector works',
    'resources/views/site/v2/home.blade.php' => 'where the connector works (the v2.0 website, the same words)',
    // The privacy policy names every provider that receives data, as the law asks.
    'resources/views/legal/privacy.blade.php' => 'the law',
];

/** Single strings that may name a provider, each with why (W-003). */
const VENDOR_STRINGS_ALLOWED = [
    // The phone tells "no key" apart by "Soniox key" (TranslateBudget.kt); only the person who runs the server adds it.
    'app/Http/Controllers/Api/LiveTranslateTokenController.php' => ['Live Translate needs a Soniox key. Add it in API Credentials, then try again.'],
    // The person's own Groq key, refused by Groq (own_key_rejected): they fix it by name in Account → Transcription key.
    'app/Enums/TranscriptErrorCode.php' => ['Groq did not accept the person\'s own key.', 'Groq did not accept your key. Check it in Account → Transcription key, then try again.'],
    'app/Filament/Support/FailedUpload.php' => ['Groq did not accept your key'],
];

/** Every string of the code and the views that names a provider, outside VENDOR_NAMES_ALLOWED: "path:line: text". */
function vendorNamesInSources(): array
{
    $hits = [];
    $read = function (string $path, string $code) use (&$hits): void {
        foreach (array_keys(VENDOR_NAMES_ALLOWED) as $allowed) {
            if (str_ends_with($allowed, '/') ? str_starts_with($path, $allowed) : $path === $allowed) {
                return;
            }
        }
        foreach (token_get_all($code) as $token) {
            if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)
                && vendorNamesIn($token[1]) !== [] && preg_match('/^[a-z0-9_.\-:\/]+$/', trim($token[1], '\'"')) !== 1
                && ! in_array(trim($token[1], '\'"'), VENDOR_STRINGS_ALLOWED[$path] ?? [], true)) {
                $hits[] = "{$path}:{$token[2]}: ".mb_substr(trim($token[1], '\'"'), 0, 120);
            }
        }
    };
    foreach (['app' => '.php', 'resources/views' => '.blade.php'] as $dir => $suffix) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with($file->getFilename(), $suffix)) {
                $source = (string) file_get_contents($file->getPathname());
                // A view compiled first: its comments are gone, what is left is page text or a PHP string.
                $read(substr($file->getPathname(), strlen(base_path()) + 1), $suffix === '.blade.php' ? Blade::compileString($source) : $source);
            }
        }
    }

    return $hits;
}

it('writes no provider, model or vendor name in what the code and the views show, outside the operator\'s own places', function () {
    expect(vendorNamesInSources())->toBe([]);
});

it('finds a vendor name and lets the Free plan\'s own Groq key be', function () {
    expect(vendorNamesIn('Ask Claude or ChatGPT'))->toBe(['Claude', 'ChatGPT'])
        ->and(vendorNamesIn('Transcribed by AssemblyAI · universal-2'))->toBe(['AssemblyAI', 'universal-2'])
        ->and(vendorNamesIn('Waiting for Groq Whisper large-v3'))->toBe(['Groq', 'Whisper'])
        ->and(vendorNamesIn('Add your free Groq key in Account → Transcription key, from console.groq.com'))->toBe([])
        ->and(vendorNamesIn('Ask an AI assistant'))->toBe([]);
});

it('names and describes every feature in plain words, under the keys the apps know', function () {
    expect(Features::keys())->toBe(['record', 'organise', 'search', 'share_links', 'export', 'ready_check', 'platform_transcription', 'views',
        'double_check', 'screen_moments', 'connector', 'team', 'premium_engines', 'live_translate', 'ai_notes']);
    foreach (Features::all() as $key => $feature) {
        expect(vendorNamesIn($feature['name'].' | '.$feature['description']))->toBe([], $key);
    }
    foreach (Plan::FEATURES as $key) {
        expect(vendorNamesIn(Plan::featureLabel($key)))->toBe([], "the Plans page's {$key}")
            ->and(vendorNamesIn(Entitlements::lockedMessage($key)))->toBe([], "feature_not_in_plan for {$key}");
    }
    foreach (UpgradeFlow::HIGHLIGHTS as $key => $line) {
        expect(vendorNamesIn($line))->toBe([], "the plan card's line for {$key}");
    }
    expect(Features::name('connector'))->toBe('Ask an AI assistant')
        ->and(Features::all()['connector']['description'])->toBe('Ask your AI assistant about your recordings.')
        ->and(Features::name('premium_engines'))->toBe('Premium engines')
        ->and(Features::all()['premium_engines']['description'])->toBe('Extra transcription engines for the hardest calls and languages.')
        ->and(Plan::featureLabel('connector'))->toBe('Ask an AI assistant')
        ->and(Entitlements::lockedMessage('connector'))->toBe('Asking an AI assistant about your recordings is not in your plan');
});

it('shows every Settings row and help answer in plain words', function () {
    foreach (SettingsRegistry::entries() as $entry) {
        $shown = implode(' | ', [$entry['title'], $entry['description'], ...$entry['options']]);
        expect(vendorNamesIn($shown))->toBe([], $entry['id']);
    }
    foreach (SettingsRegistry::HELP as $id => [$question, $answer]) {
        expect(vendorNamesIn($question.' | '.$answer))->toBe([], $id);
    }
    expect(SettingsRegistry::byId()['devices.connected']['title'])->toBe('Ask an AI assistant');
});

it('sends every feature and every plan on offer without a vendor name, on every plan', function (string $plan) {
    $device = Fx::device();
    Workspace::query()->whereKey($device->workspace_id)->update(['plan_id' => Plan::query()->where('key', $plan)->value('id')]);

    $answer = $this->api(Fx::token($device))->getJson('/api/me/entitlements')->assertOk();
    foreach ($answer->json('features') as $key => $feature) {
        expect(vendorNamesIn($feature['name'].' | '.$feature['description']))->toBe([], $key);
    }
    expect($answer->json('plans'))->not->toBe([]);
    foreach ($answer->json('plans') as $offered) {
        expect(vendorNamesIn($offered['name'].' | '.(string) $offered['description']))->toBe([], $offered['key']);
    }
    expect($answer->json('features.connector.name'))->toBe('Ask an AI assistant')
        ->and($answer->json('features.premium_engines.name'))->toBe('Premium engines');
})->with(['free', 'pro', 'business']);

it('says the features in plain words on the console\'s plan pages and the upgrade sheet of a locked one', function () {
    Filament::setCurrentPanel('app');
    $person = Fx::user();
    Workspace::query()->whereKey($person->current_workspace_id)->update(['plan_id' => Plan::free()->id]);
    $this->actingAs($person, 'web');

    $upgrade = (string) $this->get('/app/settings/plan/upgrade?feature=connector')->assertOk()->assertSee('Ask an AI assistant is in ')->getContent();
    $plan = (string) $this->get('/app/settings/plan')->assertOk()->assertSee('Ask an AI assistant')->getContent();
    expect(vendorNamesIn($upgrade))->toBe([])->and(vendorNamesIn($plan))->toBe([]);
});

it('says in plain words that AI is not set up, and that it could not answer', function () {
    $device = Fx::device();
    $project = Fx::client($device->user);
    $answer = $this->api(Fx::token($device))->postJson("/api/clients/{$project->id}/ask", ['question' => 'When do we launch?'])
        ->assertStatus(409)->assertJsonPath('code', 'ai_unavailable');

    expect($answer->json('message'))->toBe(ClientWorkspace::NOT_SET_UP)
        ->and(vendorNamesIn(ClientWorkspace::NOT_SET_UP))->toBe([]);

    app(CredentialVault::class)->put('anthropic', 'api_key', 'sk-ant-test-1234567890');
    $this->mock(ClientAi::class)->shouldReceive('ask')->andThrow(new RuntimeException('Claude is overloaded right now.'));
    $this->api(Fx::token($device))->postJson("/api/clients/{$project->id}/ask", ['question' => 'When do we launch?'])
        ->assertStatus(502)->assertJsonPath('code', 'provider_error')->assertJsonPath('message', 'The AI could not answer right now. Try again in a minute.');
});

it('says in plain words that the AI could not write a project\'s notes, keeping the provider\'s own words for the log', function () {
    app(CredentialVault::class)->put('anthropic', 'api_key', 'sk-ant-test-1234567890');
    Log::spy();
    $ai = new class(app(CredentialVault::class)) extends ClaudeClientAi
    {
        protected function client(string $key): Client
        {
            // The provider refuses the key: its answer names it and is not for people.
            return new Client(apiKey: $key, requestOptions: ['maxRetries' => 0, 'middleware' => [fn (RequestInterface $request): Response => new Response(401,
                ['Content-Type' => 'application/json'], '{"type":"error","error":{"type":"authentication_error","message":"invalid x-api-key for api.anthropic.com"}}')]]);
        }
    };

    expect(fn () => $ai->callNotes('Acme', [], [['i' => 0, 't' => '0:00', 'speaker' => 'You', 'text' => 'Send the invoice.']], 'en'))
        ->toThrow(RuntimeException::class, ClaudeClientAi::UNAVAILABLE);
    expect(vendorNamesIn(ClaudeClientAi::UNAVAILABLE))->toBe([]);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'Project AI request failed'
        && str_contains((string) $context['error'], 'authentication_error'));
});

it('never sends or shows a reason the AI\'s work failed for that names a provider, even one kept from before', function () {
    $device = Fx::device();
    $owner = $device->user;
    $project = Fx::client($owner);
    $session = Fx::session($owner, $device, ['client_id' => $project->id]);
    $transcript = Fx::transcript($session, 'Send the invoice.');
    // What v1.8.0 and older kept: the AI's maker, its model, a credential's name.
    CallNote::query()->create(['workspace_id' => $session->workspace_id, 'client_id' => $project->id, 'capture_session_id' => $session->id,
        'status' => 'failed', 'error' => 'Claude did not return the notes in the expected form.']);
    // The SDK's own words for a 529, as it writes them into the exception message (no model name in them).
    $overloaded = APIStatusException::from(new Psr7Request('POST', 'https://example.test/v1/messages'),
        new Response(529, [], '{"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}'))->getMessage();
    ClientBrief::query()->create(['workspace_id' => $session->workspace_id, 'client_id' => $project->id, 'sections' => ClientBrief::emptySections(),
        'status' => 'failed', 'error' => $overloaded]);
    ClientFile::query()->create(['workspace_id' => $session->workspace_id, 'user_id' => $owner->id, 'client_id' => $project->id, 'name' => 'Scope.pdf',
        'mime' => 'application/pdf', 'size_bytes' => 10, 'status' => 'failed', 'error' => 'Missing credential anthropic.api_key. Add it on Admin → API Credentials.']);
    $transcript->update(['meta' => ['views' => ['status' => 'failed', 'fingerprint' => TranscriptViews::fingerprint($transcript), 'error' => 'Claude declined to convert this transcript.']]]);
    $api = $this->api(Fx::token($device));
    $unavailable = ClaudeClientAi::UNAVAILABLE;

    $workspace = $api->getJson("/api/clients/{$project->id}/workspace")->assertOk();
    expect($workspace->json('data.brief.error'))->toBe($unavailable)
        // A missing credential is not a passing fault: trying again does not help until the server is set up.
        ->and($workspace->json('data.files.0.error'))->toBe(ClientWorkspace::NOT_SET_UP)
        ->and($workspace->json('data.notes.0.error'))->toBe($unavailable)
        ->and(vendorNamesIn((string) $workspace->getContent()))->toBe([]);
    expect($api->getJson("/api/sessions/{$session->id}/notes")->assertOk()->json('data.error'))->toBe($unavailable)
        ->and($api->getJson("/api/transcripts/{$transcript->id}/views")->assertOk()->json('error'))->toBe($unavailable);

    // A plain reason stays as it is.
    CallNote::query()->where('capture_session_id', $session->id)->update(['error' => 'The transcript is not ready yet.']);
    expect($api->getJson("/api/sessions/{$session->id}/notes")->assertOk()->json('data.error'))->toBe('The transcript is not ready yet.');

    // The console's project page says the same.
    Filament::setCurrentPanel('app');
    $this->actingAs($owner, 'web');
    $html = (string) $this->get('/app/clients/'.$project->id)->assertOk()->getContent()
        .(string) $this->get('/app/clients/'.$project->id.'?tab=files')->assertOk()->getContent();
    expect($html)->toContain(e($unavailable))->toContain(e(ClientWorkspace::NOT_SET_UP))->not->toContain('overloaded_error')->not->toContain('Missing credential')
        ->and(vendorNamesIn($html))->toBe([]);
});

it('reads the provider\'s own error JSON and a credential\'s words as not for people, and leaves plain words alone', function () {
    $overloaded = APIStatusException::from(new Psr7Request('POST', 'https://example.test/v1/messages'),
        new Response(529, [], '{"type":"error","error":{"type":"overloaded_error","message":"Overloaded"}}'))->getMessage();
    // Only the JSON, without the SDK's first line: still the provider's own words.
    $json = mb_substr($overloaded, (int) mb_strpos($overloaded, '{'));
    expect($json)->toStartWith('{')
        ->and(ProviderText::forPeople($overloaded))->toBe(ClaudeClientAi::UNAVAILABLE)
        ->and(ProviderText::forPeople($json))->toBe(ClaudeClientAi::UNAVAILABLE)
        ->and(ProviderText::forPeople('{"type":"error","error":{"type":"rate_limit_error","message":"slow down"}}'))->toBe(ClaudeClientAi::UNAVAILABLE)
        ->and(ProviderText::forPeople('Missing credential anthropic.api_key. Add it on Admin → API Credentials.'))->toBe(ClientWorkspace::NOT_SET_UP)
        ->and(ProviderText::forPeople('{"type":"error","error":{"type":"authentication_error","message":"invalid x-api-key"}}'))->toBe(ClientWorkspace::NOT_SET_UP)
        ->and(ProviderText::forPeople(ClientWorkspace::NOT_SET_UP))->toBe(ClientWorkspace::NOT_SET_UP)
        ->and(ProviderText::forPeople(ClaudeClientAi::UNAVAILABLE))->toBe(ClaudeClientAi::UNAVAILABLE)
        ->and(ProviderText::forPeople('The transcript is not ready yet.'))->toBe('The transcript is not ready yet.')
        ->and(ProviderText::forPeople(null))->toBeNull();
});

/** A recording of [$owner] with a ready recording and a final transcript holding [$transcript]. */
function plainWordsRecording(User $owner, array $transcript): CaptureSession
{
    $session = Fx::session($owner, null, ['title' => 'Call with Omar', 'language' => 'ur', 'status' => 'completed']);
    Fx::readyRecording($session);
    Transcript::query()->create(['capture_session_id' => $session->id, 'kind' => TranscriptKind::Final, 'language' => 'ur', ...$transcript]);

    return $session->fresh();
}

describe('the console\'s recording page', function () {
    beforeEach(function () {
        Fx::fakeRecordingsDisk();
        Filament::setCurrentPanel('app');
    });

    afterEach(fn () => Fx::cleanupDisks());

    it('says why a transcript failed in plain words, never the engine\'s own text', function () {
        $person = Fx::user();
        $session = plainWordsRecording($person, ['provider' => 'gemini', 'status' => TranscriptStatus::Failed,
            'error' => 'The speech provider could not transcribe this recording: Gemini could not read the audio.', 'error_code' => 'provider_rejected']);
        $this->actingAs($person, 'web');

        $html = (string) $this->get(CaptureSessionResource::getUrl('view', ['record' => $session]))->assertOk()
            ->assertSee('Transcription failed.')
            ->assertSee(TranscriptErrorCode::plainWords(TranscriptErrorCode::ProviderRejected))
            ->assertDontSee('Transcribed by')
            ->getContent();
        expect(vendorNamesIn($html))->toBe([]);
    });

    it('says a backup engine wrote it, with the second engine\'s words and the view, and names neither engine nor model', function () {
        $person = Fx::user();
        $session = plainWordsRecording($person, ['provider' => 'assemblyai', 'status' => TranscriptStatus::Completed, 'completed_at' => now(), 'text' => 'Kal invoice bhejun ga',
            'segments' => [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1000, 'text' => 'Kal invoice bhejun ga', 'uncertain' => true,
                'check' => ['provider' => 'groq', 'label' => 'Groq Whisper large-v3', 'text' => 'Kal invoice bhej dun ga']]],
            'meta' => ['speech_model_used' => 'universal-2',
                'route' => ['used' => 'assemblyai', 'wanted' => 'groq', 'backup' => true, 'backup_reason' => 'waited',
                    'tried' => [['engine' => 'groq', 'code' => 'provider_rejected', 'transient' => 'rate_limited']]]]]);
        // Its views are made, by a model the page never names.
        $final = $session->finalTranscript;
        $final->update(['meta' => [...$final->meta, 'views' => ['status' => 'ready', 'fingerprint' => TranscriptViews::fingerprint($final), 'model' => 'claude-sonnet-5-5',
            'made_at' => now()->toAtomString(), 'roman' => ['Kal invoice bhejun ga'], 'english' => ['I will send the invoice tomorrow'], 'kept' => []]]]);
        expect(app(TranscriptViews::class)->state($final->fresh())['model'])->toBe('claude-sonnet-5-5');
        $this->actingAs($person, 'web');

        $html = (string) $this->get(CaptureSessionResource::getUrl('view', ['record' => $session]))->assertOk()
            ->assertSee('Backup engine — Re-transcribe?')
            ->assertSee('The first engine for this language stayed busy, so a backup engine wrote this transcript.')
            ->assertSee('Second engine', false)
            ->getContent();
        expect(vendorNamesIn($html))->toBe([]);
    });

    it('says a transcript waits for its engine without naming it', function () {
        $person = Fx::user();
        $session = plainWordsRecording($person, ['provider' => 'groq', 'status' => TranscriptStatus::Pending,
            'meta' => ['route' => ['used' => 'groq', 'retry_at' => now()->addHour()->toAtomString(), 'retry_reason' => 'rate_limited', 'wait_started_at' => now()->toAtomString()]]]);
        $session->update(['status' => 'processing']);
        $this->actingAs($person, 'web');

        $html = (string) $this->get(CaptureSessionResource::getUrl('view', ['record' => $session]))->assertOk()
            ->assertSee('Waiting to transcribe — retrying at')->getContent();
        expect(vendorNamesIn($html))->toBe([]);
    });

    it('shows the server\'s operator which engine wrote it and the engine\'s own error text', function () {
        $operator = Fx::superAdmin();
        $session = plainWordsRecording($operator, ['provider' => 'gemini', 'status' => TranscriptStatus::Failed,
            'error' => 'The speech provider could not transcribe this recording: Gemini could not read the audio.', 'error_code' => 'provider_rejected']);
        $this->actingAs($operator, 'web');

        $this->get(CaptureSessionResource::getUrl('view', ['record' => $session]))->assertOk()
            ->assertSee('Transcribed by')->assertSee('Google Gemini')
            ->assertSee('Gemini could not read the audio.')
            ->assertSee(TranscriptErrorCode::plainWords(TranscriptErrorCode::ProviderRejected));
    });
});

describe('the console\'s Library side panel', function () {
    beforeEach(function () {
        Fx::fakeRecordingsDisk();
        Filament::setCurrentPanel('app');
    });

    afterEach(fn () => Fx::cleanupDisks());

    /** A failed recording of [$owner] whose engines' own words name them, as EngineFallbackJob writes them. */
    function plainWordsFailedRecording(User $owner): CaptureSession
    {
        $session = plainWordsRecording($owner, ['provider' => 'gemini', 'status' => TranscriptStatus::Failed, 'error_code' => 'provider_rejected',
            'error' => 'The speech provider could not transcribe this recording: Gemini could not read the audio. (Tried: AssemblyAI, Google Gemini. No other engine with a key can do this language: add one on API Credentials.)']);
        $session->update(['status' => 'failed']);

        return $session->fresh();
    }

    it('says why a recording failed in plain words to someone who is not the server\'s operator', function () {
        $owner = Fx::user();
        $session = plainWordsFailedRecording($owner);
        $this->actingAs($owner, 'web');

        $html = Livewire::test(ListCaptureSessions::class)->set('selected', $session->id)->html();

        // Without Livewire's block markers, as people read it.
        expect((string) preg_replace('/<!--.*?-->/s', '', $html))->toContain('Failed: '.e(TranscriptErrorCode::plainWords(TranscriptErrorCode::ProviderRejected)))
            ->and(vendorNamesIn($html))->toBe([])
            ->and($html)->not->toContain('data-engine-error');
    });

    it('also shows the engine\'s own words to the server\'s operator', function () {
        $operator = Fx::superAdmin();
        $session = plainWordsFailedRecording($operator);
        $this->actingAs($operator, 'web');

        expect(Livewire::test(ListCaptureSessions::class)->set('selected', $session->id)->html())
            ->toContain(e(TranscriptErrorCode::plainWords(TranscriptErrorCode::ProviderRejected)))
            ->toContain('data-engine-error')->toContain('Gemini could not read the audio.');
    });
});

it('says why a transcript failed in plain words for every error code, and the Free plan\'s key by name', function () {
    foreach (TranscriptErrorCode::cases() as $code) {
        // The person's own Groq key, refused by Groq: they fix it by name.
        expect(vendorNamesIn(TranscriptErrorCode::plainWords($code)))->toBe($code === TranscriptErrorCode::OwnKeyRejected ? ['Groq'] : [], $code->value);
    }
    expect(TranscriptErrorCode::plainWords(null))->toBe(TranscriptErrorCode::plainWords(TranscriptErrorCode::ProviderRejected))
        ->and(TranscriptErrorCode::plainWords(TranscriptErrorCode::NeedsTranscriptionKey))->toContain('Groq key');
});

it('leaves which engine wrote a transcript out of its JSON export, except for the server\'s operator', function () {
    $device = Fx::device();
    $owner = $device->user;
    $session = Fx::session($owner, $device, ['status' => 'completed']);
    $transcript = Fx::transcript($session, 'Send the invoice.');

    // The apps, a share link's download and an account export: never.
    $api = $this->api(Fx::token($device))->get("/api/transcripts/{$transcript->id}/export?format=json")->assertOk();
    $file = json_decode((string) $api->getContent(), true);
    expect($file['transcript'])->not->toHaveKey('provider')->and($file['transcript']['segments'][0]['text'])->toBe('Send the invoice.');
    expect(json_decode(app(TranscriptExporter::class)->export($transcript, 'json')['body'], true)['transcript'])->not->toHaveKey('provider');

    // The console: the owner does not see it; the super admin, who picks the engines, does.
    Filament::setCurrentPanel('app');
    $this->actingAs($owner, 'web');
    expect(json_decode((string) $this->get(route('admin.transcripts.export', ['transcript' => $transcript->id, 'format' => 'json']))->assertOk()->getContent(), true)['transcript'])
        ->not->toHaveKey('provider');
    $owner->forceFill(['is_super_admin' => true])->save();
    $this->actingAs($owner->fresh(), 'web');
    expect(json_decode((string) $this->get(route('admin.transcripts.export', ['transcript' => $transcript->id, 'format' => 'json']))->assertOk()->getContent(), true)['transcript']['provider'])
        ->toBe('assemblyai');
});
