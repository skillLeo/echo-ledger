<?php

declare(strict_types=1);

use Anthropic\Client;
use App\Enums\SessionStatus;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Filament\Pages\Search;
use App\Jobs\DescribeScreenJob;
use App\Models\Screen;
use App\Models\UsageLedgerEntry;
use App\Models\User;
use App\Services\CredentialVault;
use App\Services\Screens\ClaudeScreenDescriber;
use App\Services\Screens\ScreenDescriber;
use App\Services\Screens\ScreenDescriptions;
use App\Services\SettingsRepository;
use App\Services\Usage\UsageReport;
use Filament\Facades\Filament;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Psr\Http\Message\RequestInterface;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

require_once __DIR__.'/Support/screen_helpers.php';

/*
 * docs/API.md "Screen moments": the text and a one-line description of each screen, made on the queue with Claude
 * Haiku 5.5 when Settings → Screen moments → "Describe screens" is on, billed to the workspace's AI usage, found by
 * search. A failure leaves both null and never blocks the upload.
 */

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll(platform: 'windows');
    $this->asToken($this->token);
    $this->session = $this->makeReadySession($this->device, Fixtures::audio(3000), ['status' => SessionStatus::Completed, 'title' => 'Billing call', 'duration_ms' => 900_000]);
    app(CredentialVault::class)->put('anthropic', 'api_key', 'sk-ant-test-1234567890');
});

function postScreen(object $test, string $sessionId, string $bytes, int $atMs = 872000): array
{
    return $test->post("/api/sessions/{$sessionId}/screens", [
        'image' => UploadedFile::fake()->createWithContent('screen.webp', $bytes),
        'at_ms' => $atMs, 'width' => 1920, 'height' => 1080, 'trigger' => 'change', 'sha256' => hash('sha256', $bytes),
    ], ['Accept' => 'application/json'])->assertCreated()->json('data');
}

/** The real describer with Anthropic's answer faked at the HTTP layer; the requests it sent are kept. */
function claudeDescriber(array $answer, string $stopReason = 'end_turn'): ClaudeScreenDescriber
{
    return new class(app(CredentialVault::class), $answer, $stopReason) extends ClaudeScreenDescriber
    {
        /** @var list<RequestInterface> */
        public array $sent = [];

        public function __construct(CredentialVault $vault, private readonly array $answer, private readonly string $stopReason)
        {
            parent::__construct($vault);
        }

        protected function client(string $key): Client
        {
            return new Client(apiKey: $key, requestOptions: ['maxRetries' => 0, 'middleware' => [function (RequestInterface $request): Response {
                $this->sent[] = $request;

                return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                    'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5', 'stop_reason' => $this->stopReason, 'stop_sequence' => null,
                    'content' => [['type' => 'text', 'text' => json_encode($this->answer, JSON_UNESCAPED_UNICODE)]],
                    'usage' => ['input_tokens' => 1800, 'output_tokens' => 90, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0],
                ], JSON_UNESCAPED_UNICODE));
            }]]);
        }
    };
}

it('keeps the text and description Claude read and bills the workspace for it', function () {
    $fake = fakeScreenDescriber();

    $screen = postScreen($this, $this->session->id, screenPicture('png'));

    expect($fake->calls)->toHaveCount(1)->and($fake->calls[0][0])->toBe('image/png')->and($fake->calls[0][1])->toBe(screenPicture('png'));
    $stored = unscoped(fn () => Screen::query()->findOrFail($screen['id']));
    expect($stored->ocr_text)->toBe("Invoice #2041\nTotal PKR 85,000")
        ->and($stored->description)->toBe("The client's invoice page with the Pay button")
        ->and($stored->described_at)->not->toBeNull()
        ->and($stored->search_text)->toContain('invoice #2041');
    $listed = $this->getJson("/api/sessions/{$this->session->id}/screens")->assertOk()->json('data.0');
    expect($listed['ocr_text'])->toBe("Invoice #2041\nTotal PKR 85,000")->and($listed['description'])->toBe("The client's invoice page with the Pay button");

    $row = unscoped(fn () => UsageLedgerEntry::query()->sole());
    expect($row->only(['provider', 'use_case', 'model', 'input_tokens', 'output_tokens', 'source', 'capture_session_id', 'workspace_id']))->toBe([
        'provider' => 'anthropic', 'use_case' => 'screen_describe', 'model' => 'claude-haiku-5-5', 'input_tokens' => 1500, 'output_tokens' => 60,
        'source' => 'server', 'capture_session_id' => $this->session->id, 'workspace_id' => $this->session->workspace_id,
    ])->and((float) $row->cost_usd)->toBe(0.00018)->and($row->billable)->toBeTrue();
    $report = app(UsageReport::class)->forMonth($this->session->workspace_id, now(app(SettingsRepository::class)->displayTimezone())->format('Y-m'));
    expect(collect($report['providers'])->firstWhere('provider', 'anthropic'))->toMatchArray(['label' => 'Claude (screen moments)', 'cost_usd' => 0.00018]);
});

it('leaves the text and description empty when Claude fails, and still keeps the screen', function () {
    fakeScreenDescriber(failure: new RuntimeException('overloaded'));

    $screen = postScreen($this, $this->session->id, screenPicture());

    $stored = unscoped(fn () => Screen::query()->findOrFail($screen['id']));
    expect($stored->ocr_text)->toBeNull()->and($stored->description)->toBeNull()->and($stored->described_at)->toBeNull()
        ->and(unscoped(fn () => UsageLedgerEntry::query()->count()))->toBe(0);
});

it('describes nothing when Describe screens is off or no Anthropic key is set', function () {
    $fake = fakeScreenDescriber();
    Queue::fake();
    app(SettingsRepository::class)->set(ScreenDescriptions::SETTING, false);
    postScreen($this, $this->session->id, screenPicture(seed: 1));
    app(SettingsRepository::class)->set(ScreenDescriptions::SETTING, true);
    app(CredentialVault::class)->forget('anthropic', 'api_key');
    postScreen($this, $this->session->id, screenPicture(seed: 2), 1000);

    Queue::assertNotPushed(DescribeScreenJob::class);
    // Switched off after it was queued: the job does nothing either.
    app(CredentialVault::class)->put('anthropic', 'api_key', 'sk-ant-test-1234567890');
    app(SettingsRepository::class)->set(ScreenDescriptions::SETTING, false);
    (new DescribeScreenJob(unscoped(fn () => Screen::query()->value('id'))))->handle(app(ScreenDescriptions::class));
    expect($fake->calls)->toBe([])->and(unscoped(fn () => Screen::query()->whereNotNull('described_at')->count()))->toBe(0);
});

it('does not describe a screen whose recording went to the undo bin before the job ran', function () {
    $fake = fakeScreenDescriber();
    Queue::fake();
    $screen = postScreen($this, $this->session->id, screenPicture());
    $this->deleteJson("/api/sessions/{$this->session->id}")->assertNoContent();

    (new DescribeScreenJob($screen['id']))->handle(app(ScreenDescriptions::class));

    expect($fake->calls)->toBe([])->and(unscoped(fn () => Screen::query()->findOrFail($screen['id']))->description)->toBeNull();
});

it('sends Claude Haiku 5.5 the picture as a base64 image and reads one structured answer', function () {
    $describer = claudeDescriber(['ocr_text' => str_repeat('A', 4100), 'description' => "  The client's invoice page.  It has a Pay button.  "]);
    $webp = screenPicture();

    $answer = $describer->describe($webp, 'image/webp');

    expect($answer['ocr_text'])->toHaveLength(4000)
        ->and($answer['description'])->toBe("The client's invoice page.")
        ->and($answer['input_tokens'])->toBe(1800)->and($answer['output_tokens'])->toBe(90)->and($answer['model'])->toBe('claude-haiku-5-5');
    expect($describer->sent)->toHaveCount(1);
    $body = json_decode((string) $describer->sent[0]->getBody(), true);
    expect($body['model'])->toBe('claude-haiku-5-5')
        ->and($body['messages'][0]['content'][0])->toBe(['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/webp', 'data' => base64_encode($webp)]])
        ->and($body['output_config']['format']['type'])->toBe('json_schema')
        ->and($body['output_config']['format']['schema']['required'])->toBe(['ocr_text', 'description'])
        ->and($body)->not->toHaveKey('fallbacks')
        ->and($describer->sent[0]->getUri()->getPath())->toEndWith('/v1/messages');
});

it('keeps a one-sentence description of at most 200 characters', function () {
    expect(ClaudeScreenDescriber::oneSentence(str_repeat('word ', 80)))->toHaveLength(200)
        ->and(ClaudeScreenDescriber::oneSentence("Invoice page\nwith a total. More"))->toBe('Invoice page with a total.')
        ->and(ClaudeScreenDescriber::oneSentence('صفحہ انوائس کا ہے۔ اور'))->toBe('صفحہ انوائس کا ہے۔');
});

it('treats a refusal or an answer in another shape as a failure that leaves both empty', function (array $answer, string $stop) {
    app()->instance(ScreenDescriber::class, claudeDescriber($answer, $stop));

    $screen = postScreen($this, $this->session->id, screenPicture());

    expect(unscoped(fn () => Screen::query()->findOrFail($screen['id']))->only(['ocr_text', 'description']))->toBe(['ocr_text' => null, 'description' => null]);
})->with([
    'a refusal' => [['ocr_text' => '', 'description' => ''], 'refusal'],
    'no description' => [['ocr_text' => 'Hello'], 'end_turn'],
    'cut short' => [['ocr_text' => 'Hel', 'description' => 'x'], 'max_tokens'],
]);

describe('a copy saved with "Save to my library" before its screens were described', function () {
    beforeEach(function () {
        $this->fake = fakeScreenDescriber();
        Queue::fake();
        $this->screen = postScreen($this, $this->session->id, screenPicture('png'));
        $this->omar = User::factory()->create(['email' => 'omar@example.com']);
        [, $this->omarToken] = $this->enroll($this->omar);
        $this->postJson("/api/sessions/{$this->session->id}/send", ['to' => ['omar@example.com']])->assertCreated();
        $item = $this->asToken($this->omarToken)->getJson('/api/shared-with-me')->assertOk()->json('data.0.id');
        $copyId = $this->postJson("/api/shared-with-me/{$item}/save")->assertCreated()->json('session_id');
        $this->copy = unscoped(fn () => Screen::query()->where('capture_session_id', $copyId)->sole());
    });

    it('takes the original\'s description once it has come, without asking Claude again', function () {
        Queue::assertPushed(DescribeScreenJob::class, 2);
        Queue::assertPushed(DescribeScreenJob::class, fn (DescribeScreenJob $job): bool => $job->screenId === $this->copy->id && $job->copyOf === $this->screen['id']);

        (new DescribeScreenJob($this->screen['id']))->handle(app(ScreenDescriptions::class));
        (new DescribeScreenJob($this->copy->id, $this->screen['id']))->handle(app(ScreenDescriptions::class));

        $copy = unscoped(fn () => Screen::query()->findOrFail($this->copy->id));
        expect($copy->ocr_text)->toBe("Invoice #2041\nTotal PKR 85,000")
            ->and($copy->description)->toBe("The client's invoice page with the Pay button")
            ->and($copy->described_at)->not->toBeNull()
            ->and($this->fake->calls)->toHaveCount(1)
            ->and(unscoped(fn () => UsageLedgerEntry::query()->pluck('workspace_id')->all()))->toBe([$this->session->workspace_id]);
        // The copy's workspace finds it by its screen's text.
        expect($this->getJson('/api/search?q=invoice')->assertOk()->json('data.0.session.id'))->toBe($this->copy->capture_session_id);
    });

    it('describes the copy itself, billed to its own workspace, when the original has no description', function () {
        (new DescribeScreenJob($this->copy->id, $this->screen['id']))->handle(app(ScreenDescriptions::class));

        $copy = unscoped(fn () => Screen::query()->findOrFail($this->copy->id));
        expect($copy->description)->toBe("The client's invoice page with the Pay button")
            ->and($this->fake->calls)->toHaveCount(1)
            ->and(unscoped(fn () => UsageLedgerEntry::query()->sole())->workspace_id)->toBe($this->omar->fresh()->current_workspace_id)
            ->and(unscoped(fn () => Screen::query()->findOrFail($this->screen['id']))->described_at)->toBeNull();
    });
});

it('queues nothing for a copy whose screens are described already', function () {
    fakeScreenDescriber();
    $screen = postScreen($this, $this->session->id, screenPicture('png'));
    expect(unscoped(fn () => Screen::query()->findOrFail($screen['id']))->described_at)->not->toBeNull();
    Queue::fake();
    $omar = User::factory()->create(['email' => 'omar@example.com']);
    [, $omarToken] = $this->enroll($omar);
    $this->postJson("/api/sessions/{$this->session->id}/send", ['to' => ['omar@example.com']])->assertCreated();
    $item = $this->asToken($omarToken)->getJson('/api/shared-with-me')->assertOk()->json('data.0.id');

    $copyId = $this->postJson("/api/shared-with-me/{$item}/save")->assertCreated()->json('session_id');

    Queue::assertNotPushed(DescribeScreenJob::class);
    expect(unscoped(fn () => Screen::query()->where('capture_session_id', $copyId)->sole())->description)->toBe("The client's invoice page with the Pay button");
});

describe('search', function () {
    beforeEach(function () {
        fakeScreenDescriber(['ocr_text' => "Invoice #2041\nPay now", 'description' => 'The Falcon billing page with the invoice', 'input_tokens' => 1, 'output_tokens' => 1, 'model' => 'claude-haiku-5-5']);
        $this->session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'groq', 'status' => TranscriptStatus::Completed, 'language' => 'en',
            'text' => 'Please look at this page', 'segments' => [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 900, 'text' => 'Please look at this page']]]);
        postScreen($this, $this->session->id, screenPicture(seed: 1), 30_000);
        $this->screen = postScreen($this, $this->session->id, screenPicture(seed: 2), 872_000);
    });

    it('finds a recording by the text on its screens, with the screen as a hit at its moment', function () {
        $found = $this->getJson('/api/search?q=falcon')->assertOk()->json('data');

        expect($found)->toHaveCount(1)->and($found[0]['session']['id'])->toBe($this->session->id)->and($found[0]['hit_count'])->toBe(2);
        $hit = $found[0]['hits'][1];
        expect($hit)->toMatchArray(['kind' => 'screen', 'screen_id' => $this->screen['id'], 'segment_index' => -2, 'start_ms' => 872_000, 'end_ms' => 872_000, 'speaker' => 'Screen 2'])
            ->and($hit['transcript_id'])->toBe($found[0]['session']['transcript']['final_transcript_id'])
            ->and($hit['snippet'])->toContain('Falcon billing page')
            ->and(mb_substr($hit['snippet'], $hit['highlights'][0][0], $hit['highlights'][0][1]))->toBe('Falcon');
        expect($this->getJson('/api/search?q="pay now"')->assertOk()->json('data.0.hits.0.kind'))->toBe('screen');
    });

    it('finds nothing of another workspace\'s screens', function () {
        [, $otherToken] = $this->enroll();

        expect($this->asToken($otherToken)->getJson('/api/search?q=falcon')->assertOk()->json('data'))->toBe([]);
    });

    it('finds screens on the console search too, linked to their moment', function () {
        $this->actingAs($this->device->user, 'web');
        Filament::setCurrentPanel('admin');

        Livewire::test(Search::class)->fillForm(['q' => 'falcon'])
            ->assertSee('Billing call')->assertSee('Screen 2:')->assertSee('<mark>Falcon</mark>', false)->assertSee('?t=872000', false);
    });
});
