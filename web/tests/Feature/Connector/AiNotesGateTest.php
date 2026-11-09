<?php

declare(strict_types=1);

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Filament\Admin\Pages\Plans;
use App\Jobs\MakeCallNotesJob;
use App\Jobs\MakeSuggestedFixesJob;
use App\Jobs\RefreshClientBriefJob;
use App\Models\CallNote;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\ClientBrief;
use App\Models\Plan;
use App\Models\Screen;
use App\Models\Transcript;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ClientAi\ClientWorkspace;
use App\Services\CredentialVault;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

require_once dirname(__DIR__).'/Api/Support/client_workspace_helpers.php';
require_once dirname(__DIR__).'/Api/Support/screen_helpers.php';

/*
 * docs/API.md "Plans and features": in-app AI is optional per plan. `ai_notes` gates client notes, memory, Ask and the
 * brief's remake (PlansTest has those routes); Roman Urdu and the other views, suggested fixes and screen descriptions
 * are never gated.
 */

beforeEach(function () {
    Fx::fakeRecordingsDisk();
    app(CredentialVault::class)->put('anthropic', 'api_key', 'sk-ant-test-1234567890');
    $this->owner = Fx::user();
    $plan = Plan::query()->create(['key' => 'test-'.Str::lower(Str::random(6)), 'name' => 'Test', 'features' => ['ai_notes' => false]]);
    Workspace::query()->findOrFail($this->owner->current_workspace_id)->forceFill(['plan_id' => $plan->id])->save();
    $this->device = Fx::device($this->owner);
    $this->client = Fx::client($this->owner, 'Acme');
    $this->client->keyterms()->create(['term' => 'Login']);
});
afterEach(fn () => Fx::cleanupDisks());

function gatedCall(User $owner, Client $client): Transcript
{
    $session = Fx::session($owner, null, ['client_id' => $client->id]);

    return $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'groq', 'status' => TranscriptStatus::Completed, 'language' => 'en', 'text' => 'x', 'segments' => [
        ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1000, 'text' => 'Hi Ali'],
        ['channel' => 2, 'speaker' => 'Client', 'start_ms' => 1500, 'end_ms' => 3000, 'text' => 'I need a login page'],
    ]]);
}

it('still suggests fixes on a plan without AI notes, and writes no notes', function () {
    $ai = fakeClientAi();
    $transcript = gatedCall($this->owner, $this->client);

    app(ClientWorkspace::class)->afterTranscript($transcript);

    expect(collect($ai->calls)->pluck(0)->all())->toBe(['fixes'])
        ->and($transcript->refresh()->meta['suggestions'][0]['to'])->toBe('Login page')
        ->and(unscoped(fn (): int => CallNote::query()->count()))->toBe(0);
    $this->api(Fx::token($this->device))->getJson("/api/transcripts/{$transcript->id}")->assertOk()->assertJsonCount(1, 'suggestions');
});

it('keeps the views\' own suggested fixes (since v1.5, Phase 1C) when the client fixes are made', function () {
    fakeClientAi();
    $transcript = gatedCall($this->owner, $this->client);
    $transcript->update(['meta' => ['suggestions' => [['line' => 0, 'from' => 'Ali', 'to' => 'Aali', 'reason' => 'view_check', 'status' => 'open']]]]);

    app(ClientWorkspace::class)->afterTranscript($transcript->refresh());

    $open = $this->api(Fx::token($this->device))->getJson("/api/transcripts/{$transcript->id}")->assertOk()->json('suggestions');
    expect(collect($open)->pluck('reason', 'id')->all())->toBe([0 => 'view_check', 1 => $open[1]['reason']])
        ->and($open[1]['to'])->toBe('Login page');
});

it('queues the fixes on their own, not the notes', function () {
    Queue::fake();
    $transcript = gatedCall($this->owner, $this->client);

    app(ClientWorkspace::class)->afterTranscript($transcript);

    Queue::assertPushed(MakeSuggestedFixesJob::class, fn (MakeSuggestedFixesJob $job): bool => $job->sessionId === $transcript->capture_session_id);
    Queue::assertNotPushed(MakeCallNotesJob::class);
});

it('asks Claude nothing for a project with AI switched off, on any plan', function () {
    $ai = fakeClientAi();
    $this->client->forceFill(['ai_notes' => false])->save();

    app(ClientWorkspace::class)->afterTranscript(gatedCall($this->owner, $this->client));

    expect($ai->calls)->toBe([]);
});

it('stays within the plan\'s AI credits for the fixes', function () {
    $ai = fakeClientAi();
    Workspace::query()->findOrFail($this->owner->current_workspace_id)->plan->forceFill(['ai_credits_usd' => 0])->save();

    app(ClientWorkspace::class)->afterTranscript(gatedCall($this->owner, $this->client));

    expect($ai->calls)->toBe([]);
});

it('leaves the brief alone after a file on a plan without AI notes', function () {
    Queue::fake();

    $this->api(Fx::token($this->device))->postJson("/api/clients/{$this->client->id}/files", ['text' => 'Use the blue logo.', 'name' => 'Rules'])->assertCreated();

    Queue::assertNotPushed(RefreshClientBriefJob::class);
    expect(unscoped(fn () => ClientBrief::query()->where('client_id', $this->client->id)->value('status')))->not->toBe('failed');
});

it('still offers Roman Urdu and the other views', function () {
    Queue::fake();
    $transcript = gatedCall($this->owner, $this->client);

    $response = $this->api(Fx::token($this->device))->postJson("/api/transcripts/{$transcript->id}/views");

    expect($response->getStatusCode())->toBeLessThan(300)->and($response->json('code'))->not->toBe('feature_not_in_plan');
});

it('still describes screens', function () {
    fakeScreenDescriber();
    $session = Fx::session($this->owner, $this->device);

    $screen = keepScreen($session, screenPicture('png', 1280, 720, seed: 9));

    expect(unscoped(fn () => Screen::query()->find($screen->id)->description))->toBe("The client's invoice page with the Pay button");
    expect(CaptureSession::query()->withoutGlobalScopes()->find($session->id))->not->toBeNull();
});

it('leaves AI notes off in a new plan unless they are switched on', function () {
    Filament::setCurrentPanel('app');
    $this->actingAs(Fx::superAdmin(), 'web');

    Livewire::test(Plans::class)->callTableAction('create', null, ['key' => 'plain', 'name' => 'Plain', 'price_usd' => 0])->assertHasNoTableActionErrors();

    expect(Plan::query()->where('key', 'plain')->firstOrFail()->allows('ai_notes'))->toBeFalse()
        ->and(Plan::free()->allows('ai_notes'))->toBeFalse();
});
