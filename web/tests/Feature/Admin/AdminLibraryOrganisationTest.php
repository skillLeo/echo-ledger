<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Models\CaptureSession;
use App\Models\Client;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

/* Console library organisation (B15): smart-view tabs, labels, pins, bulk actions and client folders. */

beforeEach(function () {
    $this->admin = $this->signInAsAdmin();
    $this->recent = CaptureSession::factory()->create(['user_id' => $this->admin->id, 'started_at' => now()->subMinutes(30), 'title' => 'Recent call']);
    $this->older = CaptureSession::factory()->create(['user_id' => $this->admin->id, 'started_at' => now()->subDays(40), 'title' => 'Older call', 'pinned' => true]);
    $this->failed = CaptureSession::factory()->create(['user_id' => $this->admin->id, 'started_at' => now()->subDays(50), 'title' => 'Broken', 'status' => SessionStatus::Failed]);
});

it('shows a tab per smart view with its count', function () {
    $list = $this->panelComponent(ListCaptureSessions::class);
    $html = $list->html();
    foreach (ListCaptureSessions::VIEW_LABELS as $label) {
        expect($html)->toContain($label);
    }

    $list->set('activeTab', 'today')->assertCanSeeTableRecords([$this->recent])->assertCanNotSeeTableRecords([$this->older, $this->failed]);
    $list->set('activeTab', 'pinned')->assertCanSeeTableRecords([$this->older])->assertCanNotSeeTableRecords([$this->recent]);
    $list->set('activeTab', 'failed')->assertCanSeeTableRecords([$this->failed])->assertCanNotSeeTableRecords([$this->recent]);
});

it('moves, labels, pins and unpins the selected recordings', function () {
    $client = Client::factory()->create(['user_id' => $this->admin->id, 'name' => 'Acme']);
    $list = $this->panelComponent(ListCaptureSessions::class);

    $list->callTableBulkAction('move', [$this->recent, $this->failed], data: ['client_id' => $client->id])->assertNotified('2 recordings updated');
    expect($this->recent->fresh()->client_id)->toBe($client->id);

    $list->callTableBulkAction('label', [$this->recent], data: ['label' => ' Follow up '])->assertNotified('1 recording updated');
    expect($this->recent->fresh()->labelList())->toBe(['Follow up']);
    $list->assertTableColumnStateSet('labels.label', ['Follow up'], $this->recent);

    $list->callTableBulkAction('unlabel', [$this->recent], data: ['label' => 'Follow up']);
    expect($this->recent->fresh()->labelList())->toBe([]);

    $list->callTableBulkAction('pin', [$this->recent]);
    expect($this->recent->fresh()->pinned)->toBeTrue();
    $list->callTableBulkAction('unpin', [$this->recent, $this->older]);
    expect($this->older->fresh()->pinned)->toBeFalse();
});

it('filters by label and pins from the star column', function () {
    $this->older->labels()->create(['label' => 'Invoice']);
    $this->panelComponent(ListCaptureSessions::class)
        ->filterTable('label', 'Invoice')->assertCanSeeTableRecords([$this->older])->assertCanNotSeeTableRecords([$this->recent]);

    $this->panelComponent(ListCaptureSessions::class)->callTableColumnAction('pinned', $this->recent);
    expect($this->recent->fresh()->pinned)->toBeTrue();
});

it('exports the selected finished transcripts as one zip and warns when there are none', function () {
    $this->recent->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Completed, 'text' => 'x',
        'segments' => [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 900, 'text' => 'Hello']]]);

    $this->panelComponent(ListCaptureSessions::class)
        ->callTableBulkAction('export', [$this->recent, $this->older], data: ['format' => 'txt'])->assertFileDownloaded();
    $this->panelComponent(ListCaptureSessions::class)
        ->callTableBulkAction('export', [$this->older], data: ['format' => 'txt'])->assertNotified('None of these recordings has a finished transcript.');
});

it('gives client folders an avatar and a pin, pinned folders first', function () {
    Client::factory()->create(['user_id' => $this->admin->id, 'name' => 'Alpha']);
    $this->panelComponent(CreateClient::class)
        ->fillForm(['name' => 'Zulu', 'color' => '#8B5CF6', 'avatar' => 'zulu', 'pinned' => true])->call('create')->assertHasNoFormErrors();

    $zulu = Client::query()->where('name', 'Zulu')->firstOrFail();
    expect($zulu->avatar)->toBe('ZU')->and($zulu->pinned)->toBeTrue();
    $this->panelComponent(ListClients::class)->assertCanSeeTableRecords([$zulu, Client::query()->where('name', 'Alpha')->firstOrFail()], inOrder: true);
});
