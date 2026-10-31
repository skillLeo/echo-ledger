<?php

declare(strict_types=1);

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Jobs\ExtractClientFileJob;
use App\Jobs\MakeCallNotesJob;
use App\Jobs\RefreshClientBriefJob;
use App\Models\CallNote;
use App\Models\Client;
use App\Models\ClientBrief;
use App\Models\ClientFile;
use App\Models\ClientMemoryItem;
use App\Models\Transcript;
use App\Models\UsageLedgerEntry;
use App\Services\ClientAi\ClientWorkspace;
use App\Services\ClientAi\PassageSearch;
use App\Services\CredentialVault;
use App\Services\SettingsRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

require_once __DIR__.'/Support/client_workspace_helpers.php';

/*
 * Client workspaces (v1.4, P4): call notes after each kept recording of a client, its memory, the brief, files, Ask
 * with citations and "Copy for AI", with Claude replaced by a fake and its spend counted against the AI budget.
 */
beforeEach(function () {
    Storage::fake('recordings');
    [$this->device, $this->token] = $this->enroll(platform: 'android');
    $this->asToken($this->token);
    app(CredentialVault::class)->put('anthropic', 'api_key', 'sk-ant-test-1234567890');
    $this->client = Client::factory()->create(['user_id' => $this->device->user_id, 'name' => 'Acme']);
    $this->ai = fakeClientAi();
});

describe('call notes and memory', function () {
    it('queues notes for a finished transcript of a client with AI notes on, and only then', function () {
        Queue::fake();
        $session = clientCall($this, $this->client);
        $transcript = Transcript::query()->where('capture_session_id', $session->id)->sole();

        app(ClientWorkspace::class)->afterTranscript($transcript);
        Queue::assertPushed(MakeCallNotesJob::class, fn (MakeCallNotesJob $job): bool => $job->sessionId === $session->id);
        expect(CallNote::query()->sole()->status)->toBe('pending');

        $this->client->update(['ai_notes' => false]);
        $other = clientCall($this, $this->client);
        app(ClientWorkspace::class)->afterTranscript(Transcript::query()->where('capture_session_id', $other->id)->sole());
        Queue::assertPushed(MakeCallNotesJob::class, 1);
    });

    it('does nothing without an Anthropic key or without a client', function () {
        Queue::fake();
        $noClient = $this->makeReadySession($this->device, Fixtures::audio(3000), ['status' => 'completed']);
        $transcript = $noClient->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'groq', 'status' => TranscriptStatus::Completed, 'text' => 'x', 'segments' => []]);
        app(ClientWorkspace::class)->afterTranscript($transcript);

        app(CredentialVault::class)->put('anthropic', 'api_key', '');
        $session = clientCall($this, $this->client);
        app(ClientWorkspace::class)->afterTranscript(Transcript::query()->where('capture_session_id', $session->id)->sole());

        Queue::assertNothingPushed();
    });

    it('writes only items that point at real lines, with their moment, then the memory lines, and counts the spend', function () {
        Queue::fake();
        $session = clientCall($this, $this->client);

        $note = app(ClientWorkspace::class)->makeNotes($session);

        expect($note->status)->toBe('ready')
            ->and($note->sections['to_confirm'])->toHaveCount(1)
            ->and($note->sections['to_confirm'][0])->toMatchArray(['text' => 'Hosting plan', 'verify' => true, 'at_ms' => 61_000])
            ->and($note->sections['changes'][0]['kind'])->toBe('add')
            ->and($this->ai->calls[0][1][0])->toMatchArray(['i' => 0, 't' => '0:00', 'speaker' => 'You', 'text' => 'Hi Ali'])
            ->and($this->ai->calls[0][1][3]['t'])->toBe('2:05');
        $memory = ClientMemoryItem::query()->orderBy('kind')->pluck('text', 'kind')->all();
        expect($memory)->toBe(['change' => 'Added: Dark mode', 'decision' => 'Launch on November 15', 'question' => 'Hosting plan (verify)']);
        expect(ClientMemoryItem::query()->where('kind', 'decision')->sole()->source_id)->toBe($session->id);
        $usage = UsageLedgerEntry::query()->sole();
        expect($usage->use_case)->toBe('client_notes')
            ->and($usage->client_id)->toBe($this->client->id)
            ->and((float) $usage->cost_usd)->toBe(0.03);
        // No files: the brief grows from the calls.
        Queue::assertPushed(RefreshClientBriefJob::class);

        // Writing the notes again replaces the memory lines of that call, except a pinned one.
        ClientMemoryItem::query()->where('kind', 'decision')->update(['pinned' => true]);
        app(ClientWorkspace::class)->makeNotes($session);
        expect(ClientMemoryItem::query()->count())->toBe(4)
            ->and(ClientMemoryItem::query()->where('kind', 'decision')->count())->toBe(2);
    });

    it('skips the notes with the reason when the month\'s AI budget is used up', function () {
        app(SettingsRepository::class)->set('client_ai.monthly_usd', 0.01);
        UsageLedgerEntry::query()->create(['user_id' => $this->device->user_id, 'workspace_id' => $this->device->workspace_id, 'client_id' => $this->client->id,
            'report_id' => 'x', 'source' => 'server', 'use_case' => 'client_ask', 'provider' => 'claude', 'duration_ms' => 0, 'channels' => 1, 'billed_ms' => 0,
            'unit_price_usd' => 0, 'cost_usd' => 0.02, 'billable' => true, 'occurred_at' => now()]);

        $note = app(ClientWorkspace::class)->makeNotes(clientCall($this, $this->client));

        expect($note->status)->toBe('skipped')
            ->and($note->error)->toContain("This month's AI budget (\$0.01) is used up")
            ->and($this->ai->calls)->toBe([]);
        $this->postJson("/api/clients/{$this->client->id}/ask", ['question' => 'When do we launch?'])
            ->assertStatus(403)->assertJsonPath('code', 'ai_budget_reached');
    });

    it('serves a recording\'s notes and writes them again on request, in Roman Urdu if asked', function () {
        Queue::fake();
        $session = clientCall($this, $this->client);
        $this->getJson("/api/sessions/{$session->id}/notes")->assertNotFound()->assertJsonPath('code', 'notes_not_found');

        $this->postJson("/api/sessions/{$session->id}/notes", ['language' => 'roman_urdu'])
            ->assertStatus(202)->assertJsonPath('data.status', 'pending')->assertJsonPath('data.language', 'roman_urdu');
        Queue::assertPushed(MakeCallNotesJob::class);
        app(ClientWorkspace::class)->makeNotes($session);

        $this->getJson("/api/sessions/{$session->id}/notes")->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.sections.0.heading', 'Asked for')
            ->assertJsonPath('data.sections.0.items.0.at_ms', 1500)
            ->assertJsonPath('data.session.title', 'Kick-off');

        $loose = $this->makeReadySession($this->device, Fixtures::audio(3000), ['status' => 'completed']);
        $this->postJson("/api/sessions/{$loose->id}/notes")->assertStatus(409)->assertJsonPath('code', 'no_client');
    });
});

describe('the workspace, brief and memory', function () {
    it('shows brief, memory, notes, files and the AI budget, and hides another workspace\'s client', function () {
        Queue::fake();
        app(ClientWorkspace::class)->makeNotes(clientCall($this, $this->client));

        $this->getJson("/api/clients/{$this->client->id}/workspace")->assertOk()
            ->assertJsonPath('data.client.ai_notes', true)
            ->assertJsonPath('data.ai.configured', true)
            ->assertJsonPath('data.ai.budget_usd', fn ($usd): bool => (float) $usd === 5.0)
            ->assertJsonPath('data.ai.client_spent_usd', 0.03)
            ->assertJsonPath('data.brief.sections.goal', '')
            ->assertJsonCount(3, 'data.memory')
            ->assertJsonCount(1, 'data.notes')
            ->assertJsonPath('data.files', []);

        $stranger = Client::factory()->create(['name' => 'Elsewhere']);
        $this->getJson("/api/clients/{$stranger->id}/workspace")->assertNotFound()->assertJsonPath('code', 'client_not_found');
    });

    it('makes the brief from the calls when there are no files, keeps a person\'s edit, and makes it again when asked', function () {
        Queue::fake();
        app(ClientWorkspace::class)->makeNotes(clientCall($this, $this->client));
        $brief = app(ClientWorkspace::class)->refreshBrief($this->client);
        expect($brief->sections['goal'])->toBe('An online shop')
            ->and($brief->sections['scope'])->toBe(['Login'])
            ->and($this->ai->calls[1][1]['notes'][0])->toContain('### Kick-off')->toContain('Decisions:');

        $this->putJson("/api/clients/{$this->client->id}/brief", ['sections' => ['goal' => 'A shop for shoes', 'scope' => ['Login', 'Cart']]])
            ->assertOk()->assertJsonPath('data.edited', true)->assertJsonPath('data.sections.scope', ['Login', 'Cart']);
        app(ClientWorkspace::class)->refreshBrief($this->client->refresh());
        expect(ClientBrief::query()->sole()->sections['goal'])->toBe('A shop for shoes');

        $this->postJson("/api/clients/{$this->client->id}/brief/refresh")->assertStatus(202)->assertJsonPath('data.status', 'working');
        Queue::assertPushed(RefreshClientBriefJob::class, fn (RefreshClientBriefJob $job): bool => $job->force);
    });

    it('lets a person add, edit, pin and remove memory lines', function () {
        $id = $this->postJson("/api/clients/{$this->client->id}/memory", ['kind' => 'decision', 'text' => ' Use Stripe '])
            ->assertCreated()->assertJsonPath('data.text', 'Use Stripe')->assertJsonPath('data.source.type', 'user')->json('data.id');
        $this->patchJson("/api/clients/{$this->client->id}/memory/{$id}", ['text' => 'Use Stripe Checkout', 'pinned' => true])
            ->assertOk()->assertJsonPath('data.pinned', true)->assertJsonPath('data.text', 'Use Stripe Checkout');
        $this->postJson("/api/clients/{$this->client->id}/memory", ['kind' => 'opinion', 'text' => 'x'])->assertUnprocessable();
        $this->deleteJson("/api/clients/{$this->client->id}/memory/{$id}")->assertNoContent();
        $this->deleteJson("/api/clients/{$this->client->id}/memory/{$id}")->assertNotFound()->assertJsonPath('code', 'memory_not_found');
    });

    it('switches AI notes off for a client', function () {
        $this->patchJson("/api/clients/{$this->client->id}", ['ai_notes' => false])->assertOk()->assertJsonPath('data.ai_notes', false);
        expect($this->client->refresh()->ai_notes)->toBeFalse();
    });
});

describe('files', function () {
    it('keeps pasted instructions as text and refreshes the brief', function () {
        Queue::fake();
        $this->postJson("/api/clients/{$this->client->id}/files", ['text' => 'Always use the blue logo.', 'name' => 'Rules'])
            ->assertCreated()->assertJsonPath('data.status', 'ready')->assertJsonPath('data.instructions', true)->assertJsonPath('data.name', 'Rules');
        Queue::assertPushed(RefreshClientBriefJob::class);
    });

    it('stores a PDF encrypted, reads its pages through the cheap model, and deletes it with its original', function () {
        Queue::fake();
        $pdf = "%PDF-1.4\nSECRET-SCOPE-TEXT\n%%EOF";
        $id = $this->post("/api/clients/{$this->client->id}/files", ['file' => UploadedFile::fake()->createWithContent('scope.pdf', $pdf)], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.status', 'extracting')->json('data.id');
        $file = ClientFile::query()->findOrFail($id);
        expect(Storage::disk('recordings')->get($file->path))->not->toContain('SECRET-SCOPE-TEXT');
        Queue::assertPushed(ExtractClientFileJob::class);

        app(ClientWorkspace::class)->extract($file);
        $file->refresh();
        expect($file->status)->toBe('ready')->and($file->pages)->toBe(2)->and($file->text)->toContain('50% upfront')
            ->and(UsageLedgerEntry::query()->sole()->use_case)->toBe('client_file');

        $this->deleteJson("/api/clients/{$this->client->id}/files/{$id}")->assertNoContent();
        expect(Storage::disk('recordings')->exists($file->path))->toBeFalse();
        $this->deleteJson("/api/clients/{$this->client->id}/files/{$id}")->assertNotFound()->assertJsonPath('code', 'file_not_found');
    });

    it('reads Word files here and refuses other kinds', function () {
        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', '<w:document><w:body><w:p><w:r><w:t>Deadline: Nov 15</w:t></w:r></w:p><w:p><w:r><w:t>Budget &amp; scope</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();
        expect(ClientWorkspace::docxText((string) file_get_contents($path)))->toBe("Deadline: Nov 15\nBudget & scope");
        @unlink($path);

        expect(ClientWorkspace::fileType('application/octet-stream', 'notes.MD'))->toBe('text/markdown')
            ->and(ClientWorkspace::fileType(null, 'brief.docx'))->toBe('application/vnd.openxmlformats-officedocument.wordprocessingml.document')
            ->and(ClientWorkspace::fileType('application/x-msdownload', 'setup.exe'))->toBeNull();
        $this->post("/api/clients/{$this->client->id}/files", ['file' => UploadedFile::fake()->createWithContent('setup.exe', 'MZ')], ['Accept' => 'application/json'])
            ->assertUnprocessable();
    });
});

describe('Ask and Copy for AI', function () {
    it('answers from the best passages with citations to the call and its moment', function () {
        Queue::fake();
        $session = clientCall($this, $this->client);

        $this->postJson("/api/clients/{$this->client->id}/ask", ['question' => 'When do we launch?', 'language' => 'roman_urdu'])->assertOk()
            ->assertJsonPath('data.answer', 'Launch is on November 15.')
            ->assertJsonPath('data.citations.0.type', 'call')
            ->assertJsonPath('data.citations.0.session_id', $session->id)
            ->assertJsonPath('data.citations.0.at_ms', 125_000)
            ->assertJsonPath('data.citations.0.quote', 'We launch on November 15');
        $asked = $this->ai->calls[0][1];
        expect($asked['language'])->toBe('roman_urdu')
            ->and($asked['passages'][0]['blocks'][3])->toBe('[2:05] You: We launch on November 15');
        expect(UsageLedgerEntry::query()->sole()->use_case)->toBe('client_ask');
        $this->postJson("/api/clients/{$this->client->id}/ask", ['question' => 'x'])->assertUnprocessable();
    });

    it('finds file paragraphs with their page', function () {
        ClientFile::query()->create(['workspace_id' => $this->client->workspace_id, 'user_id' => $this->device->user_id, 'client_id' => $this->client->id,
            'name' => 'terms.pdf', 'mime' => 'application/pdf', 'size_bytes' => 10, 'status' => 'ready', 'text' => "=== Page 1 ===\nScope\n\n=== Page 2 ===\nPayment: 50% upfront"]);

        $passages = (new PassageSearch(app(SettingsRepository::class)))->top($this->client, 'What are the payment terms?', 5);

        expect($passages)->toHaveCount(1)
            ->and($passages[0]['kind'])->toBe('file')
            ->and($passages[0]['where'])->toBe([1, 2])
            ->and($passages[0]['blocks'][1])->toBe('Payment: 50% upfront');
    });

    it('bundles the brief, memory and recent notes for pasting into an AI project', function () {
        Queue::fake();
        app(ClientWorkspace::class)->makeNotes(clientCall($this, $this->client));
        app(ClientWorkspace::class)->refreshBrief($this->client);

        $text = $this->getJson("/api/clients/{$this->client->id}/bundle?notes=3")->assertOk()->json('data.text');

        expect($text)->toStartWith('# Acme: project context')
            ->toContain('Goal: An online shop')
            ->toContain('- Decision (')
            ->toContain('## Recent call notes')
            ->toContain('- Launch on November 15 [2:05]')
            ->toContain('- Hosting plan [1:01] (verify)');
    });
});
