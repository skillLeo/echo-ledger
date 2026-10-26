<?php

declare(strict_types=1);

use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Models\CaptureSession;
use Filament\Actions\Testing\TestAction;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

/* C3: every empty list says what to do next with one clear action. */

it('offers to pair a device when there are no sessions yet', function () {
    $this->signInAsAdmin();

    $this->panelComponent(ListCaptureSessions::class)
        ->assertSee('No sessions yet')
        ->assertActionVisible(TestAction::make('pairDevice')->table())
        ->assertActionHidden(TestAction::make('clearSearchAndFilters')->table())
        ->assertSee('/admin/devices', false);
});

it('offers to clear the search when nothing matches it', function () {
    $admin = $this->signInAsAdmin();
    CaptureSession::factory()->create(['user_id' => $admin->id, 'title' => 'Budget call']);

    $this->panelComponent(ListCaptureSessions::class)
        ->searchTable('nothing like this')
        ->assertSee('No sessions match your search')
        ->assertActionVisible(TestAction::make('clearSearchAndFilters')->table())
        ->assertActionHidden(TestAction::make('pairDevice')->table())
        ->callAction(TestAction::make('clearSearchAndFilters')->table())
        ->assertSet('tableSearch', '')
        ->assertSee('Budget call');
});

it('offers to add a client when there are none', function () {
    $this->signInAsAdmin();

    $this->panelComponent(ListClients::class)
        ->assertSee('No clients yet')
        ->assertSee('Add a client');
});

it('shows an offline notice only while the browser is offline', function () {
    $this->signInAsAdmin();

    $html = $this->get('/admin')->assertOk()->getContent();
    expect($html)->toContain('wire:offline')->toContain("You're offline.");
});
