<?php

declare(strict_types=1);

use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Jobs\ExtractClientFileJob;
use App\Jobs\RefreshClientBriefJob;
use App\Models\CallNote;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\ClientBrief;
use App\Models\ClientFile;
use App\Models\ClientMemoryItem;
use App\Models\UsageLedgerEntry;
use App\Services\ClientAi\ClientWorkspace;
use App\Services\CredentialVault;
use App\Services\SettingsRepository;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

require_once __DIR__.'/../Api/Support/client_workspace_helpers.php';

/*
 * A client's workspace in the console (P4): Overview (AI notes, the budget, the brief, the memory, the latest call
 * notes, Copy for AI), Recordings, Files and Ask, all through ClientWorkspace, refreshing only while something is made.
 */
beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->admin = $this->signInAsAdmin();
    $this->isolatedStorage();
    app(CredentialVault::class)->put('anthropic', 'api_key', 'sk-ant-test-1234567890');
    $this->client = Client::factory()->create(['user_id' => $this->admin->id, 'name' => 'Acme']);
    $this->ai = fakeClientAi();
});

function workspaceCall(object $test, array $session = []): CaptureSession
{
    return $test->sessionWithTranscript($test->admin, [
        ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1000, 'text' => 'Hi Ali'],
        ['channel' => 2, 'speaker' => 'Client', 'start_ms' => 1500, 'end_ms' => 3000, 'text' => 'I need a login page'],
        ['channel' => 2, 'speaker' => 'Client', 'start_ms' => 61_000, 'end_ms' => 64_000, 'text' => 'Also add dark mode'],
        ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 125_000, 'end_ms' => 128_000, 'text' => 'We launch on November 15'],
    ], ['client_id' => $test->client->id, 'title' => 'Kick-off', ...$session]);
}

function workspacePage(object $test, string $tab = 'overview'): Testable
{
    return $test->panelComponent(ViewClient::class, ['record' => $test->client->id])->set('tab', $tab);
}

describe('the list', function () {
    it('lists clients as rows that open their workspace', function () {
        workspaceCall($this);

        $html = $this->panelComponent(ListClients::class)->html();

        expect($html)->toContain('Acme')->toContain('1 recording · Last call')
            ->toContain(e(ViewClient::getUrl(['record' => $this->client])));
        $this->get(ViewClient::getUrl(['record' => $this->client]))->assertOk()->assertSee('Overview')->assertSee('Recordings')->assertSee('Files')->assertSee('Ask');
    });
});

describe('overview', function () {
    it('shows the AI switch, the month\'s budget with this client\'s share, the brief, the memory and the call notes', function () {
        $call = workspaceCall($this);
        UsageLedgerEntry::query()->create(['workspace_id' => $this->client->workspace_id, 'user_id' => $this->admin->id, 'client_id' => $this->client->id, 'report_id' => 'client-ai/1', 'source' => UsageLedgerEntry::SOURCE_SERVER,
            'use_case' => 'client_notes', 'provider' => 'claude', 'model' => 'claude-haiku-5-5', 'duration_ms' => 0, 'channels' => 1, 'billed_ms' => 0,
            'unit_price_usd' => 0, 'cost_usd' => 0.42, 'billable' => true, 'occurred_at' => now()]);
        ClientBrief::query()->create(['workspace_id' => $this->client->workspace_id, 'client_id' => $this->client->id, 'status' => 'ready', 'sections' => ClientBrief::clean(['goal' => 'An online shop', 'scope' => ['Login page']])]);
        ClientMemoryItem::query()->create(['workspace_id' => $this->client->workspace_id, 'client_id' => $this->client->id, 'kind' => 'decision', 'text' => 'Launch on November 15', 'happened_on' => now()->toDateString(),
            'source_type' => 'call', 'source_id' => $call->id, 'at_ms' => 125_000]);
        ClientMemoryItem::query()->create(['workspace_id' => $this->client->workspace_id, 'client_id' => $this->client->id, 'kind' => 'question', 'text' => 'Which payment provider?', 'happened_on' => now()->toDateString(), 'source_type' => 'user']);
        CallNote::query()->create(['workspace_id' => $this->client->workspace_id, 'client_id' => $this->client->id, 'capture_session_id' => $call->id, 'status' => 'ready', 'language' => 'en',
            'sections' => ['decisions' => [['text' => 'Launch on November 15', 'verify' => true, 'lines' => [3], 'at_ms' => 125_000]]]]);

        $html = workspacePage($this)->html();

        expect($html)->toContain('role="switch"')->toContain('aria-checked="true"')
            ->toContain('AI this month: $0.42 of $5.00 · this client $0.42')
            ->toContain('An online shop')->toContain('Login page')
            ->toContain('Decisions')->toContain('Open questions')->toContain('Which payment provider?')
            ->toContain('Launch on November 15')->toContain('verify')
            // Each note line opens the recording at its moment.
            ->toContain(e(CaptureSessionResource::getUrl('view', ['record' => $call]).'?t=125000'))
            ->toContain('Copy for AI');
    });

    it('turns AI notes off and on', function () {
        workspacePage($this)->call('toggleAiNotes');
        expect($this->client->fresh()->ai_notes)->toBeFalse();

        workspacePage($this)->call('toggleAiNotes');
        expect($this->client->fresh()->ai_notes)->toBeTrue();
    });

    it('saves an edited brief as edited, and Make again queues a new one that replaces it', function () {
        Queue::fake();

        workspacePage($this)->callAction('editBrief', data: ['goal' => 'A shop', 'scope' => "Login\nCheckout\n", 'deadlines' => 'Nov 15'])->assertHasNoActionErrors();
        $brief = ClientBrief::query()->where('client_id', $this->client->id)->sole();
        expect($brief->edited)->toBeTrue()->and($brief->sections['goal'])->toBe('A shop')->and($brief->sections['scope'])->toBe(['Login', 'Checkout']);

        workspacePage($this)->call('remakeBrief');
        expect($brief->fresh()->status)->toBe('working');
        Queue::assertPushed(RefreshClientBriefJob::class, fn (RefreshClientBriefJob $job): bool => $job->clientId === $this->client->id && $job->force);
    });

    it('does not queue a new brief without an Anthropic key', function () {
        Queue::fake();
        app(CredentialVault::class)->forget('anthropic', 'api_key');

        workspacePage($this)->call('remakeBrief')->assertNotified('Add your Anthropic key in API Credentials to use client notes and Ask.');
        Queue::assertNothingPushed();
    });

    it('adds, pins and removes memory lines', function () {
        workspacePage($this)->set('memoryKind', 'change')->set('memoryText', '  Added: dark mode  ')->call('addMemory')->assertHasNoErrors();
        $item = ClientMemoryItem::query()->where('client_id', $this->client->id)->sole();
        expect($item->kind)->toBe('change')->and($item->text)->toBe('Added: dark mode')->and($item->source_type)->toBe('user');

        workspacePage($this)->call('togglePinMemory', $item->id);
        expect($item->fresh()->pinned)->toBeTrue();

        workspacePage($this)->call('removeMemory', $item->id);
        expect(ClientMemoryItem::query()->count())->toBe(0);

        workspacePage($this)->set('memoryText', '')->call('addMemory')->assertHasErrors(['memoryText' => 'required']);
    });

    it('copies the same bundle the apps get for Copy for AI', function () {
        ClientBrief::query()->create(['workspace_id' => $this->client->workspace_id, 'client_id' => $this->client->id, 'status' => 'ready', 'sections' => ClientBrief::clean(['goal' => 'An online shop'])]);

        expect(workspacePage($this)->instance()->bundle())->toBe(app(ClientWorkspace::class)->bundle($this->client))->toContain('Goal: An online shop');
    });
});

describe('recordings', function () {
    it('lists the client\'s recordings and only those', function () {
        workspaceCall($this);
        $this->sessionWithTranscript($this->admin, [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1000, 'text' => 'x']], ['title' => 'Someone else']);

        expect(workspacePage($this, 'recordings')->html())->toContain('Kick-off')->not->toContain('Someone else');
    });
});

describe('files', function () {
    it('uploads a document through ClientWorkspace::addFile and reads it on the queue', function () {
        Queue::fake();

        workspacePage($this, 'files')->set('upload', UploadedFile::fake()->createWithContent('scope.md', "# Scope\nLogin"))->assertHasNoErrors();

        $file = ClientFile::query()->where('client_id', $this->client->id)->sole();
        expect($file->name)->toBe('scope.md')->and($file->status)->toBe('extracting')->and($file->path)->not->toBeNull();
        Queue::assertPushed(ExtractClientFileJob::class, fn (ExtractClientFileJob $job): bool => $job->fileId === $file->id);
        expect(workspacePage($this, 'files')->html())->toContain('scope.md')->toContain('Reading');
    });

    it('refuses a file it cannot read', function () {
        workspacePage($this, 'files')->set('upload', UploadedFile::fake()->createWithContent('photo.png', 'png'))
            ->assertHasErrors(['upload']);

        expect(ClientFile::query()->count())->toBe(0);
    });

    it('adds pasted instructions, shows each file\'s status and removes a file', function () {
        Queue::fake();
        workspacePage($this, 'files')->set('instructionsName', 'From the email')->set('instructions', 'Phase one only')->call('addInstructions')->assertHasNoErrors();
        ClientFile::query()->create(['workspace_id' => $this->client->workspace_id, 'client_id' => $this->client->id, 'user_id' => $this->admin->id, 'name' => 'Broken.pdf', 'mime' => 'application/pdf',
            'size_bytes' => 10, 'status' => 'failed', 'error' => 'The file could not be read.']);

        $instructions = ClientFile::query()->where('name', 'From the email')->sole();
        expect($instructions->status)->toBe('ready')->and($instructions->text)->toBe('Phase one only');
        expect(workspacePage($this, 'files')->html())->toContain('From the email')->toContain('Instructions')->toContain('Failed')->toContain('The file could not be read.');

        workspacePage($this, 'files')->callAction(TestAction::make('removeFile')->arguments(['file' => $instructions->id]))->assertNotified('Removed From the email');
        expect(ClientFile::query()->whereKey($instructions->id)->exists())->toBeFalse();
    });
});

describe('ask', function () {
    it('answers in the chosen language with links to the recording and its moment', function () {
        $call = workspaceCall($this);

        $page = workspacePage($this, 'ask')->set('question', 'When do we launch?')->set('askLanguage', 'roman_urdu')->call('ask')->assertHasNoErrors();

        expect($this->ai->calls[0][0])->toBe('ask')->and($this->ai->calls[0][1]['language'])->toBe('roman_urdu');
        expect($page->html())->toContain('Launch is on November 15.')->toContain('We launch on November 15')
            ->toContain(e(CaptureSessionResource::getUrl('view', ['record' => $call]).'?t=125000'));
    });

    it('says why there is no answer when the budget is used up', function () {
        app(SettingsRepository::class)->set('client_ai.monthly_usd', 0.0);

        workspacePage($this, 'ask')->set('question', 'When do we launch?')->call('ask')->assertSee('AI budget');
        expect($this->ai->calls)->toBe([]);
    });

    it('needs a question of a few words', function () {
        workspacePage($this, 'ask')->set('question', 'x')->call('ask')->assertHasErrors(['question' => 'min']);
    });
});

describe('polling', function () {
    it('refreshes only while the brief is being made, a file read or notes written', function () {
        expect(workspacePage($this)->html())->not->toContain('wire:poll');

        $brief = ClientBrief::query()->create(['workspace_id' => $this->client->workspace_id, 'client_id' => $this->client->id, 'status' => 'working', 'sections' => ClientBrief::emptySections()]);
        expect(workspacePage($this)->html())->toContain('wire:poll.5s="refreshWorkspace"');

        $brief->update(['status' => 'ready']);
        $file = ClientFile::query()->create(['workspace_id' => $this->client->workspace_id, 'client_id' => $this->client->id, 'user_id' => $this->admin->id, 'name' => 'a.pdf', 'mime' => 'application/pdf', 'size_bytes' => 1, 'status' => 'extracting']);
        expect(workspacePage($this, 'files')->html())->toContain('wire:poll');

        $file->update(['status' => 'ready']);
        $note = CallNote::query()->create(['workspace_id' => $this->client->workspace_id, 'client_id' => $this->client->id, 'capture_session_id' => workspaceCall($this)->id, 'status' => 'pending', 'language' => 'en']);
        expect(workspacePage($this)->html())->toContain('wire:poll');

        $note->update(['status' => 'ready', 'sections' => []]);
        expect(workspacePage($this)->html())->not->toContain('wire:poll');
    });
});

it('keeps another workspace\'s client out', function () {
    $other = Client::factory()->create(['user_id' => $this->makeAdmin()->id]);

    $this->get(ViewClient::getUrl(['record' => $other]))->assertNotFound();
});
