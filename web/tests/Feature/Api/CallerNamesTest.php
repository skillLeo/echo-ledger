<?php

declare(strict_types=1);

use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\RelationManagers\ContactsRelationManager;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\ClientContact;
use App\Services\Callers\CallerMatcher;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Admin\Support\AdminTestHelpers;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class, AdminTestHelpers::class);

/*
 * Caller names (v1.2): who a recording was with, as the device saw them, matched to a client contact by phone number
 * or name; the recording is titled "Call with <name>" and filed under the client.
 */

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll(platform: 'android');
    $this->asToken($this->token);
    $this->bytes = Fixtures::audio(4000);
});

function acme(object $test, array $contact = []): Client
{
    $client = Client::factory()->create(['user_id' => $test->device->user_id, 'workspace_id' => $test->device->workspace_id, 'name' => 'Acme Holdings']);
    $client->contacts()->create(['name' => 'Omar Khan', 'phone' => '0300-1234567', 'language' => 'ur', ...$contact]);

    return $client;
}

it('compares phone numbers by their last ten digits and names without case or emoji', function () {
    expect(CallerMatcher::digits('+92 300 1234567'))->toBe('3001234567')
        ->and(CallerMatcher::digits('0300-1234567'))->toBe('3001234567')
        ->and(CallerMatcher::digits('12'))->toBeNull()
        ->and(CallerMatcher::normalName('  Omar  KHAN 😀 '))->toBe('omar khan')
        ->and(CallerMatcher::cleanName('+92 300 1234567'))->toBeNull()
        ->and(CallerMatcher::cleanName('WhatsApp'))->toBeNull()
        ->and(CallerMatcher::cleanName(' Omar Khan '))->toBe('Omar Khan');
});

it('keeps the caller of a new recording and titles it Call with <name>', function () {
    $id = $this->createSession($this->bytes, ['caller' => ['name' => 'Sara Ahmed', 'source' => 'whatsapp_notification']])->assertCreated()->json('session.id');

    $session = CaptureSession::query()->findOrFail($id);
    expect($session->caller_name)->toBe('Sara Ahmed')->and($session->caller_source)->toBe('whatsapp_notification')
        ->and($session->displayTitle())->toBe('Call with Sara Ahmed')->and($session->client_id)->toBeNull();
    $item = collect($this->getJson('/api/library')->json('data'))->firstWhere('id', $id);
    expect($item['title'])->toBe('Call with Sara Ahmed')
        ->and($item['caller'])->toBe(['name' => 'Sara Ahmed', 'number' => null, 'source' => 'whatsapp_notification']);
    $this->getJson("/api/sessions/{$id}")->assertJsonPath('caller.name', 'Sara Ahmed');
});

it('files the recording under the client whose contact has that number, in the contact language', function () {
    $client = acme($this);

    $id = $this->createSession($this->bytes, ['caller' => ['number' => '+92 300 1234567', 'source' => 'phone']])->assertCreated()->json('session.id');

    $session = CaptureSession::query()->findOrFail($id);
    expect($session->client_id)->toBe($client->id)->and($session->language)->toBe('ur')
        ->and($session->caller_name)->toBe('Omar Khan')->and($session->displayTitle())->toBe('Call with Omar Khan');
});

it('titles a SIM phone call by the number when the caller is not a contact', function () {
    $id = $this->createSession($this->bytes, ['source_app' => 'phone_call', 'caller' => ['number' => '+92 345 0001112', 'source' => 'phone']])->assertCreated()->json('session.id');
    $bare = $this->createSession($this->bytes, ['client_ref' => 'bare', 'source_app' => 'phone_call'])->json('session.id');

    $item = collect($this->getJson('/api/library')->json('data'))->keyBy('id');
    expect($item[$id]['title'])->toBe('Call with +92 345 0001112')->and($item[$id]['source_app_label'])->toBe('Phone call')
        ->and($item[$bare]['title'])->toBe('Phone call')
        ->and(CaptureSession::query()->find($id)->caller_name)->toBeNull();
});

it('matches a contact or the client itself by name, and never overrides a client or language the device chose', function () {
    $client = acme($this);
    $other = Client::factory()->create(['user_id' => $this->device->user_id, 'workspace_id' => $this->device->workspace_id, 'name' => 'Beta Traders']);

    $byName = $this->createSession($this->bytes, ['caller' => ['name' => 'omar khan', 'source' => 'whatsapp_notification']])->json('session.id');
    $byClient = $this->createSession($this->bytes, ['client_ref' => 'ref-2', 'caller' => ['name' => 'Acme Holdings', 'source' => 'window_title']])->json('session.id');
    $chosen = $this->createSession($this->bytes, ['client_ref' => 'ref-3', 'client_id' => $other->id, 'language' => 'en', 'caller' => ['name' => 'Omar Khan']])->json('session.id');

    expect(CaptureSession::query()->find($byName)->client_id)->toBe($client->id)
        ->and(CaptureSession::query()->find($byClient)->client_id)->toBe($client->id)
        ->and(CaptureSession::query()->find($byClient)->displayTitle())->toBe('Acme Holdings')
        ->and(CaptureSession::query()->find($chosen)->client_id)->toBe($other->id)
        ->and(CaptureSession::query()->find($chosen)->language)->toBe('en');
});

it('takes the caller when an open recording finishes', function () {
    $client = acme($this);
    $id = $this->openSession();

    $this->postJson("/api/sessions/{$id}/finish", Fixtures::finishBody($this->bytes, ['caller' => ['name' => 'Omar Khan', 'source' => 'whatsapp_notification']]))->assertOk();

    expect(CaptureSession::query()->find($id)->client_id)->toBe($client->id);
    $this->postJson('/api/sessions', [...Fixtures::openSessionBody(['client_ref' => 'bad']), 'caller' => ['source' => 'carrier_pigeon']])->assertStatus(422);
});

it('learns the caller as a contact when the recording is filed under a client', function () {
    $client = Client::factory()->create(['user_id' => $this->device->user_id, 'workspace_id' => $this->device->workspace_id, 'name' => 'Gamma']);
    $first = $this->createSession($this->bytes, ['caller' => ['name' => 'Zara Malik', 'number' => '+92 321 7654321', 'source' => 'whatsapp_notification']])->json('session.id');

    $this->patchJson("/api/sessions/{$first}", ['client_id' => $client->id])->assertOk();

    expect($client->contacts()->sole()->only(['name', 'phone', 'phone_digits']))->toBe(['name' => 'Zara Malik', 'phone' => '+92 321 7654321', 'phone_digits' => '3217654321']);
    $next = $this->createSession($this->bytes, ['client_ref' => 'next', 'caller' => ['number' => '0321 7654321']])->json('session.id');
    expect(CaptureSession::query()->find($next)->client_id)->toBe($client->id);
    $this->patchJson("/api/sessions/{$next}", ['client_id' => $client->id])->assertOk();
    expect($client->contacts()->count())->toBe(1);
});

it('lets the owner keep contacts on the client page', function () {
    Filament::setCurrentPanel('admin');
    $admin = $this->signInAsAdmin();
    $client = Client::factory()->create(['user_id' => $admin->id, 'workspace_id' => $admin->current_workspace_id, 'name' => 'Delta']);

    Livewire::test(ContactsRelationManager::class, ['ownerRecord' => $client, 'pageClass' => EditClient::class])
        ->callTableAction('create', data: ['name' => 'Bilal', 'phone' => '+92 333 1112223', 'language' => 'ur'])
        ->assertHasNoTableActionErrors();

    expect(ClientContact::query()->where('client_id', $client->id)->sole()->only(['name', 'phone_digits', 'language']))
        ->toBe(['name' => 'Bilal', 'phone_digits' => '3331112223', 'language' => 'ur']);
});
