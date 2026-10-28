<?php

declare(strict_types=1);

use Anthropic\Client;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Filament\Infolists\TranscriptPlayerEntry;
use App\Filament\Resources\CaptureSessions\Pages\ViewCaptureSession;
use App\Jobs\MakeTranscriptViewsJob;
use App\Models\CaptureSession;
use App\Models\Transcript;
use App\Models\UsageLedgerEntry;
use App\Services\CredentialVault;
use App\Services\SettingsRepository;
use App\Services\TranscriptEditor;
use App\Services\Views\ClaudeTranscriptConverter;
use App\Services\Views\TooLongForOneRequest;
use App\Services\Views\TranscriptConverter;
use App\Services\Views\TranscriptViews;
use Filament\Facades\Filament;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Queue;
use Psr\Http\Message\RequestInterface;
use Tests\Feature\Admin\Support\AdminTestHelpers;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class, AdminTestHelpers::class);

/*
 * Show as (v1.2): Roman Urdu and English reading views of a finished transcript, made once with the Claude API on the
 * queue and kept with the transcript; the transcript itself never changes.
 */

/** A converter that writes "R:" / "E:" before each line and records the chunks it was given. */
function fakeConverter(int $maxLines = 1000): object
{
    $fake = new class($maxLines) implements TranscriptConverter
    {
        /** @var list<array<int, string>> */
        public array $chunks = [];

        public function __construct(private readonly int $maxLines) {}

        public function convert(array $lines, string $language, array $views): array
        {
            if (count($lines) > $this->maxLines) {
                throw new TooLongForOneRequest('too long');
            }
            $this->chunks[] = $lines;

            return [
                'lines' => array_map(fn (string $t): array => array_intersect_key(['roman' => 'R: '.$t, 'english' => 'E: '.$t, 'urdu' => 'U: '.$t], array_flip($views)), $lines),
                'input_tokens' => 1000, 'output_tokens' => 2000, 'model' => 'claude-opus-5-5',
            ];
        }
    };
    app()->instance(TranscriptConverter::class, $fake);

    return $fake;
}

function urduTranscript(object $test, string $language = 'ur'): Transcript
{
    $session = $test->makeReadySession($test->device, Fixtures::audio(4000), ['language' => $language, 'status' => 'completed']);

    return $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'groq', 'status' => TranscriptStatus::Completed, 'language' => $language,
        'text' => "Me: آپ کیسے ہیں\nClient: میں ٹھیک ہوں", 'segments' => [
            ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1000, 'text' => 'آپ کیسے ہیں'],
            ['channel' => 2, 'speaker' => 'Client', 'start_ms' => 1500, 'end_ms' => 3000, 'text' => 'میں ٹھیک ہوں'],
        ]]);
}

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll(platform: 'android');
    $this->asToken($this->token);
});

it('offers Roman Urdu and English for Urdu, Roman Urdu and Urdu for English, and English for the other languages', function () {
    expect(TranscriptViews::available(urduTranscript($this)))->toBe(['roman', 'english'])
        ->and(TranscriptViews::available(urduTranscript($this, 'ar')))->toBe(['english'])
        ->and(TranscriptViews::available(urduTranscript($this, 'fr')))->toBe(['english'])
        ->and(TranscriptViews::available(urduTranscript($this, 'en')))->toBe(['roman', 'urdu']);
});

it('needs the Anthropic key, then makes the views on the queue once', function () {
    Queue::fake();
    $transcript = urduTranscript($this);

    $this->postJson("/api/transcripts/{$transcript->id}/views")->assertStatus(409)->assertJsonPath('code', 'views_unavailable');
    app(CredentialVault::class)->put('anthropic', 'api_key', 'sk-ant-test-1234567890');

    $this->postJson("/api/transcripts/{$transcript->id}/views")->assertStatus(202)->assertJsonPath('status', 'making');
    $this->postJson("/api/transcripts/{$transcript->id}/views")->assertStatus(202);
    Queue::assertPushed(MakeTranscriptViewsJob::class, 1);
    $this->getJson("/api/transcripts/{$transcript->id}")->assertOk()->assertJsonPath('views', ['available' => ['roman', 'english'], 'status' => 'making', 'default' => 'original']);
});

it('stores both views aligned with the lines, bills the tokens and serves them until the transcript is edited', function () {
    app(CredentialVault::class)->put('anthropic', 'api_key', 'sk-ant-test-1234567890');
    fakeConverter();
    $transcript = urduTranscript($this);

    dispatch_sync(new MakeTranscriptViewsJob($transcript->id));

    $state = $this->getJson("/api/transcripts/{$transcript->id}/views")->assertOk()->json();
    expect($state['status'])->toBe('ready')
        ->and($state['roman'])->toBe(['R: آپ کیسے ہیں', 'R: میں ٹھیک ہوں'])
        ->and($state['english'])->toBe(['E: آپ کیسے ہیں', 'E: میں ٹھیک ہوں'])
        ->and($state['model'])->toBe('claude-opus-5-5')
        ->and($transcript->fresh()->segments[0]['text'])->toBe('آپ کیسے ہیں');
    $usage = UsageLedgerEntry::query()->where('provider', 'claude')->sole();
    expect($usage->cost_usd)->toEqualWithDelta((1000 * 4 + 2000 * 20) / 1_000_000, 1e-9)->and($usage->use_case)->toBe('show_as');
    $this->postJson("/api/transcripts/{$transcript->id}/views")->assertOk()->assertJsonPath('status', 'ready');

    app(TranscriptEditor::class)->edit($transcript->fresh(), [1 => 'میں بالکل ٹھیک ہوں']);
    $this->getJson("/api/transcripts/{$transcript->id}/views")->assertOk()->assertJsonPath('status', 'none')->assertJsonPath('roman', null);
});

it('sends long transcripts in chunks and splits a chunk whose answer does not fit', function () {
    app(CredentialVault::class)->put('anthropic', 'api_key', 'sk-ant-test-1234567890');
    $fake = fakeConverter(maxLines: 3);
    $session = $this->makeReadySession($this->device, Fixtures::audio(4000), ['language' => 'ur']);
    $segments = array_map(fn (int $i): array => ['channel' => 1, 'speaker' => 'Me', 'start_ms' => $i * 1000, 'end_ms' => $i * 1000 + 900, 'text' => "لائن {$i}"], range(0, 7));
    $transcript = $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'groq', 'status' => TranscriptStatus::Completed, 'language' => 'ur', 'text' => 'x', 'segments' => $segments]);

    app(TranscriptViews::class)->make($transcript);

    expect(array_map('count', $fake->chunks))->toBe([2, 2, 2, 2])
        ->and(app(TranscriptViews::class)->state($transcript->fresh())['english'][7])->toBe('E: لائن 7')
        ->and(TranscriptViews::chunks(array_fill(0, 3, str_repeat('ا', 2500))))->toHaveCount(2);
});

it('keeps the reason when the views could not be made', function () {
    app(CredentialVault::class)->put('anthropic', 'api_key', 'sk-ant-test-1234567890');
    app()->instance(TranscriptConverter::class, new class implements TranscriptConverter
    {
        public function convert(array $lines, string $language, array $views): array
        {
            throw new RuntimeException('Claude declined to convert this transcript.');
        }
    });
    $transcript = urduTranscript($this);

    app(TranscriptViews::class)->make($transcript);

    $this->getJson("/api/transcripts/{$transcript->id}/views")->assertOk()
        ->assertJsonPath('status', 'failed')->assertJsonPath('error', 'Claude declined to convert this transcript.');
});

it('exports a view instead of the original words and shows the views in the console player', function () {
    app(CredentialVault::class)->put('anthropic', 'api_key', 'sk-ant-test-1234567890');
    fakeConverter();
    $transcript = urduTranscript($this);
    app(TranscriptViews::class)->make($transcript);

    $roman = $this->get("/api/transcripts/{$transcript->id}/export?format=txt&view=roman")->assertOk()->getContent();
    $original = $this->get("/api/transcripts/{$transcript->id}/export?format=txt")->assertOk()->getContent();
    expect($roman)->toContain('R: آپ کیسے ہیں')->and($original)->not->toContain('R: ')->and($original)->toContain('آپ کیسے ہیں');
    $this->getJson("/api/transcripts/{$transcript->id}/export?format=txt&view=klingon")->assertStatus(422);

    $player = TranscriptPlayerEntry::payload(CaptureSession::query()->findOrFail($transcript->capture_session_id));
    expect($player['views']['status'])->toBe('ready')->and($player['views']['english'][1])->toBe('E: میں ٹھیک ہوں');
});

it('asks Claude for structured JSON with the model from Settings and the refusal fallback, and reads the answer', function () {
    app(CredentialVault::class)->put('anthropic', 'api_key', 'sk-ant-test-1234567890');
    $sent = [];
    $converter = new class(app(CredentialVault::class), app(SettingsRepository::class), $sent) extends ClaudeTranscriptConverter
    {
        /** @param  list<RequestInterface>  $sent */
        public function __construct(CredentialVault $vault, SettingsRepository $settings, public array &$sent)
        {
            parent::__construct($vault, $settings);
        }

        protected function client(string $key): Client
        {
            $answer = ['lines' => [['index' => 0, 'roman' => 'aap kaise hain', 'english' => 'How are you?', 'urdu' => ''], ['index' => 1, 'roman' => 'main theek hoon', 'english' => 'I am fine.', 'urdu' => '']]];

            return new Client(apiKey: $key, requestOptions: ['maxRetries' => 0, 'middleware' => [function (RequestInterface $request) use (&$answer): Response {
                $this->sent[] = $request;

                return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                    'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5', 'stop_reason' => 'end_turn', 'stop_sequence' => null,
                    'content' => [['type' => 'text', 'text' => json_encode($answer, JSON_UNESCAPED_UNICODE)]],
                    'usage' => ['input_tokens' => 120, 'output_tokens' => 40, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0],
                ], JSON_UNESCAPED_UNICODE));
            }]]);
        }
    };

    $answer = $converter->convert([0 => 'آپ کیسے ہیں', 1 => 'میں ٹھیک ہوں'], 'ur', ['roman', 'english']);

    expect($answer['lines'])->toBe([0 => ['roman' => 'aap kaise hain', 'english' => 'How are you?'], 1 => ['roman' => 'main theek hoon', 'english' => 'I am fine.']])
        ->and($answer['input_tokens'])->toBe(120)->and($answer['output_tokens'])->toBe(40);
    expect($sent)->toHaveCount(1);
    $body = json_decode((string) $sent[0]->getBody(), true);
    expect($body['model'])->toBe('claude-opus-5-5')
        ->and($body['output_config']['format']['type'])->toBe('json_schema')
        ->and($body['output_config']['effort'])->toBe('medium')
        ->and($body['fallbacks'])->toBe('default')
        ->and($body['system'][0]['cache_control'])->toBe(['type' => 'ephemeral'])
        ->and(json_decode($body['messages'][0]['content'], true)['views_wanted'])->toBe(['roman', 'english'])
        ->and(json_decode($body['messages'][0]['content'], true)['lines'][1])->toBe(['index' => 1, 'text' => 'میں ٹھیک ہوں'])
        ->and($sent[0]->getHeaderLine('anthropic-beta'))->toContain('server-side-fallback-2026-07-01');
});

it('offers Show as on the recording page and says when the key is missing', function () {
    Queue::fake();
    Filament::setCurrentPanel('admin');
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1000, 'text' => 'آپ کیسے ہیں']],
        ['status' => 'completed', 'language' => 'ur'], transcript: ['language' => 'ur']);

    $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id])
        ->callAction('show_as')->assertNotified('Add the Anthropic API key on API Credentials to make the Show as views.');
    app(CredentialVault::class)->put('anthropic', 'api_key', 'sk-ant-test-1234567890');
    $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id])
        ->callAction('show_as')->assertNotified('Making the Show as views (Roman Urdu, English or Urdu). Reload the page in a minute or two.');
    Queue::assertPushed(MakeTranscriptViewsJob::class, 1);
});

it('opens a share link in the chosen view once it is made, and in the original words otherwise', function () {
    app(CredentialVault::class)->put('anthropic', 'api_key', 'sk-ant-test-1234567890');
    fakeConverter();
    $transcript = urduTranscript($this);
    $session = CaptureSession::query()->findOrFail($transcript->capture_session_id);

    $created = $this->postJson("/api/sessions/{$session->id}/shares", ['expires_in' => 3600, 'view' => 'english'])->assertCreated();
    expect($created->json('view'))->toBe('english');
    $this->get($created->json('url'))->assertOk()->assertSee('میں ٹھیک ہوں')->assertDontSee('English view, written by Claude');
    // The page loads the Nastaliq font from this server for the Urdu lines.
    $plain = $this->postJson("/api/sessions/{$session->id}/shares", ['expires_in' => 3600])->assertCreated();
    $this->get($plain->json('url'))->assertOk()->assertSee('lang="ur"', false)->assertSee('fonts/noto-nastaliq-urdu-arabic.woff2', false);

    app(TranscriptViews::class)->make($transcript);
    $this->get($created->json('url'))->assertOk()->assertSee('E: میں ٹھیک ہوں')->assertSee('English view, written by Claude from the transcript.');
    $this->postJson("/api/sessions/{$session->id}/shares", ['expires_in' => 3600, 'view' => 'french'])->assertStatus(422);
});

it('translates an English call into Urdu (and Roman Urdu) and opens it in the default view from Settings', function () {
    app(CredentialVault::class)->put('anthropic', 'api_key', 'sk-ant-test-1234567890');
    $fake = fakeConverter();
    $session = $this->makeReadySession($this->device, Fixtures::audio(4000), ['language' => 'en', 'status' => 'completed']);
    $transcript = $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Completed, 'language' => 'en',
        'text' => 'Me: I will send the invoice', 'segments' => [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1000, 'text' => 'I will send the invoice']]]);

    app(TranscriptViews::class)->make($transcript);

    $state = $this->getJson("/api/transcripts/{$transcript->id}/views")->assertOk()->json();
    expect($state['urdu'])->toBe(['U: I will send the invoice'])->and($state['roman'])->toBe(['R: I will send the invoice'])
        ->and($state['english'])->toBeNull()->and($state['default'])->toBe('original');
    $this->getJson("/api/transcripts/{$transcript->id}")->assertJsonPath('views.default', 'original');

    app(SettingsRepository::class)->set('views.default', 'urdu');
    $this->getJson("/api/transcripts/{$transcript->id}")->assertJsonPath('views', ['available' => ['roman', 'urdu'], 'status' => 'ready', 'default' => 'urdu']);
    $this->get("/api/transcripts/{$transcript->id}/export?format=txt&view=urdu")->assertOk()->assertSee('U: I will send the invoice');
    app(SettingsRepository::class)->set('views.default', 'english'); // not a view of this transcript
    $this->getJson("/api/transcripts/{$transcript->id}")->assertJsonPath('views.default', 'original');
});
