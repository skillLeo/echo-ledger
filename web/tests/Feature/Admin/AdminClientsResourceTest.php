<?php

declare(strict_types=1);

use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\RelationManagers\KeytermsRelationManager;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\ClientKeyterm;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

function adminKeytermsManager($test, Client $client)
{
    return $test->panelComponent(KeytermsRelationManager::class, ['ownerRecord' => $client, 'pageClass' => EditClient::class]);
}

it('renders the clients list, create and edit pages', function () {
    $admin = $this->signInAsAdmin();
    $client = Client::factory()->create(['user_id' => $admin->id, 'name' => 'Acme Holdings']);

    $this->get('/admin/clients')->assertOk()->assertSee('Clients');
    $this->get('/admin/clients/create')->assertOk();
    $this->get('/admin/clients/'.$client->id.'/edit')->assertOk()->assertSee('Acme Holdings', false);
});

it('lists clients with keyterm and session counts, and "auto" for no language', function () {
    $admin = $this->signInAsAdmin();
    $client = Client::factory()->create(['user_id' => $admin->id, 'name' => 'Acme Holdings', 'language' => null]);
    foreach (['Farah Khan', 'Project Falcon', 'Q3 roadmap'] as $term) {
        $client->keyterms()->create(['term' => $term]);
    }
    CaptureSession::factory()->count(2)->create(['user_id' => $admin->id, 'client_id' => $client->id]);

    $list = $this->panelComponent(ListClients::class)
        ->assertCanSeeTableRecords([$client])
        ->assertSee('Acme Holdings')
        ->assertSee('Automatic');

    $record = $list->instance()->getTableRecords()->first();
    expect($record->keyterms_count)->toBe(3)->and($record->capture_sessions_count)->toBe(2);
});

it('shows only the admin\'s own clients', function () {
    $admin = $this->signInAsAdmin();
    $mine = Client::factory()->create(['user_id' => $admin->id]);
    $theirs = Client::factory()->create(['user_id' => $this->makeAdmin()->id]);

    $this->panelComponent(ListClients::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('sorts by name ascending by default and descending on request', function () {
    $admin = $this->signInAsAdmin();
    $b = Client::factory()->create(['user_id' => $admin->id, 'name' => 'Beta']);
    $a = Client::factory()->create(['user_id' => $admin->id, 'name' => 'Alpha']);
    $c = Client::factory()->create(['user_id' => $admin->id, 'name' => 'Gamma']);

    $list = $this->panelComponent(ListClients::class);
    $list->assertCanSeeTableRecords([$a, $b, $c], inOrder: true);
    $list->sortTable('name', 'desc')->assertCanSeeTableRecords([$c, $b, $a], inOrder: true);
});

it('searches clients by name', function () {
    $admin = $this->signInAsAdmin();
    $acme = Client::factory()->create(['user_id' => $admin->id, 'name' => 'Acme Holdings']);
    $globex = Client::factory()->create(['user_id' => $admin->id, 'name' => 'Globex']);

    $this->panelComponent(ListClients::class)
        ->searchTable('acme')
        ->assertCanSeeTableRecords([$acme])
        ->assertCanNotSeeTableRecords([$globex]);
});

it('creates a client owned by the signed-in admin', function () {
    $admin = $this->signInAsAdmin();

    $this->panelComponent(CreateClient::class)
        ->fillForm(['name' => 'Acme Holdings', 'color' => '#10B981', 'language' => 'ur', 'notes' => 'Prefers voice notes.'])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $client = Client::query()->firstOrFail();
    expect($client->user_id)->toBe($admin->id)
        ->and($client->name)->toBe('Acme Holdings')
        ->and($client->color)->toBe('#10B981')
        ->and($client->language)->toBe('ur')
        ->and($client->notes)->toBe('Prefers voice notes.');
});

it('defaults a new client to the violet accent colour', function () {
    $this->signInAsAdmin();

    $this->panelComponent(CreateClient::class)->assertFormSet(['color' => '#8B5CF6']);
});

it('requires a client name', function () {
    $this->signInAsAdmin();

    $this->panelComponent(CreateClient::class)
        ->fillForm(['name' => ''])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required']);

    expect(Client::query()->count())->toBe(0);
});

it('limits name, language and notes length', function () {
    $this->signInAsAdmin();

    $this->panelComponent(CreateClient::class)
        ->fillForm(['name' => str_repeat('n', 121), 'language' => 'klingon', 'notes' => str_repeat('x', 2001)])
        ->call('create')
        ->assertHasFormErrors(['name' => 'max', 'language' => 'in', 'notes' => 'max']);
});

it('rejects a duplicate client name for the same admin but allows it for another', function () {
    $admin = $this->signInAsAdmin();
    Client::factory()->create(['user_id' => $admin->id, 'name' => 'Acme Holdings']);
    Client::factory()->create(['user_id' => $this->makeAdmin()->id, 'name' => 'Globex']);

    $this->panelComponent(CreateClient::class)
        ->fillForm(['name' => 'Acme Holdings'])
        ->call('create')
        ->assertHasFormErrors(['name' => 'unique']);

    $this->panelComponent(CreateClient::class)
        ->fillForm(['name' => 'Globex'])
        ->call('create')
        ->assertHasNoFormErrors();
    expect(Client::query()->where('user_id', $admin->id)->count())->toBe(2);
});

it('rejects a colour that is not a hex value', function () {
    $this->signInAsAdmin();

    $this->panelComponent(CreateClient::class)
        ->fillForm(['name' => 'Acme', 'color' => 'purple'])
        ->call('create')
        ->assertHasFormErrors(['color']);
});

it('cannot be tricked into creating a client for another user', function () {
    $admin = $this->signInAsAdmin();
    $other = $this->makeAdmin();

    $this->panelComponent(CreateClient::class)
        ->fillForm(['name' => 'Sneaky'])
        ->set('data.user_id', $other->id)
        ->call('create');

    expect(Client::query()->where('name', 'Sneaky')->value('user_id'))->toBe($admin->id);
});

it('edits a client and keeps its own name valid', function () {
    $admin = $this->signInAsAdmin();
    $client = Client::factory()->create(['user_id' => $admin->id, 'name' => 'Acme Holdings']);

    $this->panelComponent(EditClient::class, ['record' => $client->id])
        ->assertFormSet(['name' => 'Acme Holdings'])
        ->fillForm(['name' => 'Acme Holdings', 'language' => 'en'])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($client->fresh()->language)->toBe('en');

    $this->panelComponent(EditClient::class, ['record' => $client->id])
        ->fillForm(['name' => 'Acme Group'])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($client->fresh()->name)->toBe('Acme Group');
});

it('refuses to rename a client to a name already in use', function () {
    $admin = $this->signInAsAdmin();
    Client::factory()->create(['user_id' => $admin->id, 'name' => 'Taken']);
    $client = Client::factory()->create(['user_id' => $admin->id, 'name' => 'Mine']);

    $this->panelComponent(EditClient::class, ['record' => $client->id])
        ->fillForm(['name' => 'Taken'])
        ->call('save')
        ->assertHasFormErrors(['name' => 'unique']);
});

it('returns 404 when editing another admin\'s client', function () {
    $this->signInAsAdmin();
    $theirs = Client::factory()->create(['user_id' => $this->makeAdmin()->id]);

    $this->get('/admin/clients/'.$theirs->id.'/edit')->assertNotFound();
});

it('deletes a client from the list, keeping its sessions but detaching them', function () {
    $admin = $this->signInAsAdmin();
    $client = Client::factory()->create(['user_id' => $admin->id]);
    $client->keyterms()->create(['term' => 'Falcon']);
    $session = CaptureSession::factory()->create(['user_id' => $admin->id, 'client_id' => $client->id]);

    $this->panelComponent(ListClients::class)
        ->callAction(TestAction::make(DeleteAction::class)->table($client));

    expect(Client::query()->find($client->id))->toBeNull()
        ->and(ClientKeyterm::query()->count())->toBe(0)
        ->and($session->fresh())->not->toBeNull()
        ->and($session->fresh()->client_id)->toBeNull();
});

it('deletes a client from its edit page and returns to the list', function () {
    $admin = $this->signInAsAdmin();
    $client = Client::factory()->create(['user_id' => $admin->id]);

    $this->panelComponent(EditClient::class, ['record' => $client->id])
        ->callAction(DeleteAction::class)
        ->assertRedirect(ListClients::getUrl());

    expect(Client::query()->count())->toBe(0);
});

it('has a create button on the list page', function () {
    $this->signInAsAdmin();

    $this->panelComponent(ListClients::class)->assertActionExists('create');
});

// --- Keyterms -------------------------------------------------------------------------------------------------

it('shows the keyterms relation manager on the edit page', function () {
    $admin = $this->signInAsAdmin();
    $client = Client::factory()->create(['user_id' => $admin->id]);

    $this->get('/admin/clients/'.$client->id.'/edit')->assertOk()->assertSee('Keyterms');
});

it('lists a client\'s keyterms and not another client\'s', function () {
    $admin = $this->signInAsAdmin();
    $client = Client::factory()->create(['user_id' => $admin->id]);
    $other = Client::factory()->create(['user_id' => $admin->id]);
    $mine = $client->keyterms()->create(['term' => 'Project Falcon']);
    $theirs = $other->keyterms()->create(['term' => 'Unrelated']);

    adminKeytermsManager($this, $client)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('adds a keyterm, trimming and collapsing whitespace', function () {
    $admin = $this->signInAsAdmin();
    $client = Client::factory()->create(['user_id' => $admin->id]);

    adminKeytermsManager($this, $client)
        ->callAction(TestAction::make('create')->table(), ['term' => "  Farah    Khan \n"])
        ->assertHasNoFormErrors();

    expect($client->keytermList())->toBe(['Farah Khan']);
});

it('refreshes the client\'s updated_at when a keyterm is added so devices pick it up', function () {
    $admin = $this->signInAsAdmin();
    $client = Client::factory()->create(['user_id' => $admin->id, 'updated_at' => Carbon::parse('2026-01-01 00:00:00')]);

    adminKeytermsManager($this, $client)->callAction(TestAction::make('create')->table(), ['term' => 'Falcon']);

    expect($client->fresh()->updated_at->greaterThan(Carbon::parse('2026-01-02')))->toBeTrue();
});

it('requires a keyterm', function () {
    $admin = $this->signInAsAdmin();
    $client = Client::factory()->create(['user_id' => $admin->id]);

    adminKeytermsManager($this, $client)
        ->callAction(TestAction::make('create')->table(), ['term' => ''])
        ->assertHasFormErrors(['term' => 'required']);
});

it('rejects a keyterm longer than 50 characters or with more than 5 words', function () {
    $admin = $this->signInAsAdmin();
    $client = Client::factory()->create(['user_id' => $admin->id]);

    adminKeytermsManager($this, $client)
        ->callAction(TestAction::make('create')->table(), ['term' => str_repeat('a', 51)])
        ->assertHasFormErrors(['term']);
    adminKeytermsManager($this, $client)
        ->callAction(TestAction::make('create')->table(), ['term' => 'one two three four five six'])
        ->assertHasFormErrors(['term']);

    expect($client->keyterms()->count())->toBe(0);
});

it('accepts a keyterm of exactly 50 characters and exactly 5 words', function () {
    $admin = $this->signInAsAdmin();
    $client = Client::factory()->create(['user_id' => $admin->id]);

    adminKeytermsManager($this, $client)
        ->callAction(TestAction::make('create')->table(), ['term' => str_repeat('a', 50)])
        ->assertHasNoFormErrors();
    adminKeytermsManager($this, $client)
        ->callAction(TestAction::make('create')->table(), ['term' => 'one two three four five'])
        ->assertHasNoFormErrors();

    expect($client->keyterms()->count())->toBe(2);
});

it('rejects a duplicate keyterm for the same client but allows it on another client', function () {
    $admin = $this->signInAsAdmin();
    $client = Client::factory()->create(['user_id' => $admin->id]);
    $other = Client::factory()->create(['user_id' => $admin->id]);
    $client->keyterms()->create(['term' => 'Falcon']);

    adminKeytermsManager($this, $client)
        ->callAction(TestAction::make('create')->table(), ['term' => 'Falcon'])
        ->assertHasFormErrors(['term' => 'unique']);
    adminKeytermsManager($this, $other)
        ->callAction(TestAction::make('create')->table(), ['term' => 'Falcon'])
        ->assertHasNoFormErrors();
});

it('strips characters that providers reject from a keyterm', function () {
    $admin = $this->signInAsAdmin();
    $client = Client::factory()->create(['user_id' => $admin->id]);

    adminKeytermsManager($this, $client)
        ->callAction(TestAction::make('create')->table(), ['term' => 'AT&T [Mobile] {beta} <b>x</b>']);

    expect($client->keytermList())->toBe(['AT&T Mobile beta bx/b']);
});

it('edits and deletes a keyterm', function () {
    $admin = $this->signInAsAdmin();
    $client = Client::factory()->create(['user_id' => $admin->id]);
    $term = $client->keyterms()->create(['term' => 'Falcon']);

    adminKeytermsManager($this, $client)
        ->callAction(TestAction::make('edit')->table($term), ['term' => 'Falcon Heavy'])
        ->assertHasNoFormErrors();
    expect($term->fresh()->term)->toBe('Falcon Heavy');

    adminKeytermsManager($this, $client)->callAction(TestAction::make('delete')->table($term));
    expect($client->keyterms()->count())->toBe(0);
});

it('bulk-deletes selected keyterms', function () {
    $admin = $this->signInAsAdmin();
    $client = Client::factory()->create(['user_id' => $admin->id]);
    $terms = collect(['One', 'Two', 'Three'])->map(fn (string $t) => $client->keyterms()->create(['term' => $t]));

    adminKeytermsManager($this, $client)
        ->selectTableRecords($terms->take(2)->pluck('id')->all())
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk());

    expect($client->keytermList())->toBe(['Three']);
});

it('searches keyterms', function () {
    $admin = $this->signInAsAdmin();
    $client = Client::factory()->create(['user_id' => $admin->id]);
    $falcon = $client->keyterms()->create(['term' => 'Project Falcon']);
    $khan = $client->keyterms()->create(['term' => 'Farah Khan']);

    adminKeytermsManager($this, $client)
        ->searchTable('falcon')
        ->assertCanSeeTableRecords([$falcon])
        ->assertCanNotSeeTableRecords([$khan]);
});

it('pastes a list of keyterms split on commas and new lines, skipping duplicates and invalid ones', function () {
    $admin = $this->signInAsAdmin();
    $client = Client::factory()->create(['user_id' => $admin->id]);
    $client->keyterms()->create(['term' => 'Falcon']);
    $tooLong = str_repeat('x', 51);

    adminKeytermsManager($this, $client)
        ->callAction(TestAction::make('bulkAdd')->table(), ['terms' => "Farah Khan, falcon\nQ3 roadmap\n{$tooLong}\none two three four five six\nFarah Khan\n\n  ,  "])
        ->assertNotified('Added 2 keyterms');

    expect($client->keytermList())->toBe(['Falcon', 'Farah Khan', 'Q3 roadmap']);
});

it('requires text when pasting a list', function () {
    $admin = $this->signInAsAdmin();
    $client = Client::factory()->create(['user_id' => $admin->id]);

    adminKeytermsManager($this, $client)
        ->callAction(TestAction::make('bulkAdd')->table(), ['terms' => ''])
        ->assertHasFormErrors(['terms' => 'required']);
});
