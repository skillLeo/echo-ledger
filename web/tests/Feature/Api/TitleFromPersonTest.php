<?php

declare(strict_types=1);

use App\Enums\SourceApp;
use App\Models\CallNote;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\SentItem;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Sharing\Teammates;
use App\Services\Titles\AutoTitles;
use App\Services\Titles\GenericTitles;
use App\Services\Workspaces\Workspaces;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
 * docs/API.md "Title from the person" (2.0.0-k, B10): a person's own title, then the person called ("Call with Ahmed",
 * or the number), then the automatic title, then the app's name. The same title everywhere the API shows one.
 */

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll();
    $this->asToken($this->token);
});

function titled(object $test, array $state): CaptureSession
{
    return CaptureSession::factory()->create(['user_id' => $test->device->user_id, 'source_app' => SourceApp::WhatsApp, ...$state]);
}

it('puts the person called before an automatic or an app\'s generic title, and a person\'s own title first', function () {
    $cases = [
        'person typed' => [['title' => 'Rent for March', 'title_auto' => false, 'caller_name' => 'Ahmed'], 'Rent for March'],
        'automatic' => [['title' => 'Laptop — 16 vs 32 GB', 'title_auto' => true, 'caller_name' => 'Ahmed'], 'Call with Ahmed'],
        'app label' => [['title' => 'WhatsApp call', 'title_auto' => false, 'caller_name' => 'Ahmed'], 'Call with Ahmed'],
        'no title' => [['title' => null, 'caller_name' => 'Ahmed'], 'Call with Ahmed'],
        'meeting' => [['title' => null, 'source_app' => SourceApp::GoogleMeet, 'caller_name' => 'Weekly sync', 'caller_source' => 'calendar'], 'Weekly sync'],
        'number only' => [['title' => null, 'caller_number' => '+92 300 1234567'], 'Call with +92 300 1234567'],
        'generic name' => [['title' => null, 'caller_name' => 'WhatsApp', 'caller_number' => '+92 321 7654321'], 'Call with +92 321 7654321'],
        'unknown name' => [['title' => 'Budget talk', 'title_auto' => true, 'caller_name' => 'Unknown'], 'Budget talk'],
        'nobody' => [['title' => null, 'caller_name' => 'WhatsApp call'], 'WhatsApp call'],
        'voice note' => [['title' => 'Groceries', 'title_auto' => true, 'source_app' => SourceApp::VoiceNote, 'caller_name' => 'Ahmed'], 'Groceries'],
    ];
    $ids = [];
    foreach ($cases as $name => [$state, $expected]) {
        $ids[$name] = [titled($this, $state)->id, $expected];
    }

    $items = collect($this->getJson('/api/library?limit=100')->assertOk()->json('data'))->keyBy('id');
    foreach ($ids as $name => [$id, $expected]) {
        expect($items[$id]['title'])->toBe($expected, $name)
            ->and($this->getJson("/api/sessions/{$id}")->assertOk()->json('title'))->toBe($expected, $name);
    }
});

it('makes no automatic title where a person is known, a name or a number, and one where nobody is', function () {
    expect(AutoTitles::wanted(titled($this, ['title' => null, 'caller_name' => 'Ahmed'])))->toBeFalse()
        ->and(AutoTitles::wanted(titled($this, ['title' => null, 'caller_number' => '+92 300 1234567'])))->toBeFalse()
        ->and(AutoTitles::wanted(titled($this, ['title' => 'WhatsApp call', 'caller_name' => 'WhatsApp'])))->toBeTrue()
        ->and(AutoTitles::wanted(titled($this, ['title' => null, 'source_app' => SourceApp::VoiceNote, 'caller_name' => 'Ahmed'])))->toBeTrue()
        ->and(AutoTitles::wanted(titled($this, ['title' => 'Rent for March', 'title_auto' => false])))->toBeFalse();
});

it('never lets a transcript change a title a person typed', function () {
    $session = titled($this, ['title' => 'Rent for March', 'title_auto' => false, 'caller_name' => 'Ahmed']);
    app(AutoTitles::class)->make($session);

    expect($session->fresh()->title)->toBe('Rent for March')->and($session->fresh()->title_auto)->toBeFalse()
        ->and($session->fresh()->displayTitle())->toBe('Rent for March');
});

it('switches an older recording with an automatic title once its caller is known, changing nothing stored', function () {
    $session = titled($this, ['title' => 'Laptop — 16 vs 32 GB', 'title_auto' => true]);
    expect($session->displayTitle())->toBe('Laptop — 16 vs 32 GB');

    $session->forceFill(['caller_name' => 'Ahmed'])->save();

    expect($session->fresh()->displayTitle())->toBe('Call with Ahmed')
        ->and($session->fresh()->title)->toBe('Laptop — 16 vs 32 GB');
});

it('names the person in what a teammate is sent and in search', function () {
    $mate = User::factory()->create();
    app(Workspaces::class)->addMember(Workspace::query()->findOrFail($this->device->workspace_id), $mate);
    $session = titled($this, ['title' => 'Laptop — 16 vs 32 GB', 'title_auto' => true, 'caller_name' => 'Ahmed']);
    $session->transcripts()->create(['kind' => 'final', 'provider' => 'assemblyai', 'status' => 'completed', 'text' => 'the retainer fee is due']);

    app(Teammates::class)->send(User::query()->findOrFail($this->device->user_id), $session, [$mate->email], null);
    app('auth')->forgetGuards();

    expect(SentItem::query()->withoutGlobalScopes()->latest('id')->value('title'))->toBe('Call with Ahmed')
        ->and(collect($this->getJson('/api/search?q=retainer')->assertOk()->json('data'))->first()['session']['title'])->toBe('Call with Ahmed');
});

it('reads an app\'s label or an unknown caller as nobody, and a real name as someone', function () {
    foreach (['', 'WhatsApp', 'whatsapp call', 'WhatsApp Business', 'Zoom', 'Unknown', 'Private number', 'Phone call'] as $nobody) {
        expect(GenericTitles::namesNobody($nobody))->toBeTrue($nobody);
    }
    foreach (['Ahmed', 'Farah Khan', 'Acme Holdings', 'احمد'] as $someone) {
        expect(GenericTitles::namesNobody($someone))->toBeFalse($someone);
    }
});

it('gives the same title with a recording\'s call notes', function () {
    $project = Client::factory()->create(['workspace_id' => $this->device->workspace_id, 'user_id' => $this->device->user_id]);
    $call = titled($this, ['title' => 'WhatsApp call', 'caller_name' => 'Ahmed', 'client_id' => $project->id, 'workspace_id' => $this->device->workspace_id]);
    CallNote::query()->create(['workspace_id' => $call->workspace_id, 'client_id' => $project->id, 'capture_session_id' => $call->id, 'status' => 'ready', 'language' => 'en']);

    expect($this->getJson("/api/sessions/{$call->id}/notes")->assertOk()->json('data.session.title'))->toBe('Call with Ahmed');
});
