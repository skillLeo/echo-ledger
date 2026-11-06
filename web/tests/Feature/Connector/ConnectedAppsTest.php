<?php

declare(strict_types=1);

use App\Filament\Auth\EditProfile;
use App\Models\AuditEvent;
use App\Models\ConnectedApp;
use App\Services\Accounts\AccountEraser;
use App\Services\Accounts\AccountSessions;
use App\Services\Connector\ConnectedApps;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Laravel\Passport\Passport;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

require_once __DIR__.'/Support/connector_helpers.php';

/*
 * Profile → Connected apps (docs/API.md "Claude and ChatGPT connector"): each connection with its name, when it was made
 * and last used, and Disconnect, which ends its tokens at once. Also the other ways a connection ends.
 */

beforeEach(function () {
    connectorKeys();
    Filament::setCurrentPanel('admin');
    Mail::fake();
    $this->person = Fx::user();
});

it('lists each connection with its name, when it was made and when it was last used', function () {
    $this->travelTo(now()->startOfDay()->addHours(9));
    $claude = connectorConnect($this, $this->person, 'Claude');
    connectorConnect($this, $this->person, 'ChatGPT');
    connectorTool($this, $claude['access'], 'list_recent');
    app('auth')->forgetGuards();

    $page = $this->actingAs($this->person->fresh(), 'web')->get('/admin/profile')->assertOk();

    $page->assertSee('Connected apps')->assertSee('Claude')->assertSee('ChatGPT')->assertSee('Disconnect')
        ->assertSee('Connected '.now()->format('M j, Y'))->assertSee('last used')->assertSee('not used yet');
});

it('says when no app is connected, and shows nobody else\'s', function () {
    connectorConnect($this, Fx::user(), 'SomeoneElsesApp');
    app('auth')->forgetGuards();

    $this->actingAs($this->person->fresh(), 'web')->get('/admin/profile')->assertOk()
        ->assertSee('No app is connected')->assertDontSee('SomeoneElsesApp');
});

it('disconnects: its access and refresh tokens stop at once, and the row and the log say so', function () {
    $connected = connectorConnect($this, $this->person);
    connectorMessage($this, $connected['access'], 'tools/list')->assertOk();
    $app = ConnectedApp::query()->sole();
    app('auth')->forgetGuards();

    $this->actingAs($this->person->fresh(), 'web');
    Livewire::test(EditProfile::class)->call('disconnectApp', $app->id)->assertNotified('App disconnected');

    connectorMessage($this, $connected['access'], 'tools/list')->assertUnauthorized();
    connectorRefresh($this, $connected['client'], $connected['refresh'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    expect(ConnectedApp::query()->count())->toBe(0)
        ->and(Passport::token()->newQuery()->where('revoked', false)->count())->toBe(0)
        ->and(Passport::refreshToken()->newQuery()->where('revoked', false)->count())->toBe(0);
    $row = AuditEvent::query()->where('event', 'connector_disconnected')->sole();
    expect($row->user_id)->toBe($this->person->id)->and($row->summary)->toBe('Claude disconnected');
});

it('ends a code not exchanged yet as well, and asks the person again next time', function () {
    $client = connectorRegister($this);
    [$verifier, $challenge] = connectorPkce();
    $first = connectorAllow($this, $this->person, $client, $challenge);
    connectorExchange($this, $client, $first, $verifier)->assertOk();
    [$newVerifier, $newChallenge] = connectorPkce();
    Passport::token()->newQuery()->update(['revoked' => true]);
    $pending = connectorAllow($this, $this->person, $client, $newChallenge);

    expect(app(ConnectedApps::class)->disconnect($this->person, ConnectedApp::query()->sole()->id))->toBeTrue();

    connectorExchange($this, $client, $pending, $newVerifier)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    $this->actingAs($this->person->fresh(), 'web')->get(connectorAuthorizeUrl($client, $newChallenge))->assertOk()->assertSee('Allow Claude');
});

it('disconnects one app only, and only the person\'s own', function () {
    $claude = connectorConnect($this, $this->person, 'Claude');
    $chatgpt = connectorConnect($this, $this->person, 'ChatGPT');
    $stranger = Fx::user();
    $theirs = connectorConnect($this, $stranger, 'Theirs');
    $theirRow = ConnectedApp::query()->where('user_id', $stranger->id)->sole();
    $mine = ConnectedApp::query()->where('client_id', $claude['client'])->sole();
    app('auth')->forgetGuards();

    $this->actingAs($this->person->fresh(), 'web');
    // Someone else's id: refused, and it changes nothing.
    Livewire::test(EditProfile::class)->call('disconnectApp', $theirRow->id)->assertNotified('That app was already disconnected');
    connectorMessage($this, $theirs['access'], 'tools/list')->assertOk();
    app('auth')->forgetGuards();

    $this->actingAs($this->person->fresh(), 'web');
    Livewire::test(EditProfile::class)->call('disconnectApp', $mine->id);

    connectorMessage($this, $claude['access'], 'tools/list')->assertUnauthorized();
    connectorMessage($this, $chatgpt['access'], 'tools/list')->assertOk();
    connectorMessage($this, $theirs['access'], 'tools/list')->assertOk();
});

it('keeps two people on one registered app apart', function () {
    $client = connectorRegister($this);
    $other = Fx::user();
    [$verifierA, $challengeA] = connectorPkce();
    $tokensA = connectorExchange($this, $client, connectorAllow($this, $this->person, $client, $challengeA), $verifierA)->assertOk()->json();
    [$verifierB, $challengeB] = connectorPkce();
    $tokensB = connectorExchange($this, $client, connectorAllow($this, $other, $client, $challengeB), $verifierB)->assertOk()->json();

    expect(app(ConnectedApps::class)->disconnect($this->person, ConnectedApp::query()->where('user_id', $this->person->id)->sole()->id))->toBeTrue();

    connectorMessage($this, $tokensA['access_token'], 'tools/list')->assertUnauthorized();
    connectorMessage($this, $tokensB['access_token'], 'tools/list')->assertOk();
    expect(Passport::client()->newQuery()->whereKey($client)->exists())->toBeTrue();
});

it('shows an app\'s name as text wherever it appears', function () {
    $evil = '<img src=x onerror=alert(1)>';
    $client = connectorRegister($this, $evil);
    [$verifier, $challenge] = connectorPkce();
    $page = $this->actingAs($this->person, 'web')->get(connectorAuthorizeUrl($client, $challenge))->assertOk();
    expect((string) $page->getContent())->not->toContain($evil)->toContain('&lt;img src=x');

    connectorExchange($this, $client, connectorAllow($this, $this->person, $client, $challenge), $verifier)->assertOk();
    app('auth')->forgetGuards();
    expect((string) $this->actingAs($this->person->fresh(), 'web')->get('/admin/profile')->assertOk()->getContent())->not->toContain($evil);
});

it('disconnects every app when the person signs out everywhere', function () {
    $connected = connectorConnect($this, $this->person);

    app(AccountSessions::class)->signOutEverywhere($this->person->fresh());

    connectorMessage($this, $connected['access'], 'tools/list')->assertUnauthorized();
    connectorRefresh($this, $connected['client'], $connected['refresh'])->assertStatus(400);
    expect(ConnectedApp::query()->count())->toBe(0);
});

it('ends every connection when the account is erased', function () {
    $connected = connectorConnect($this, $this->person);

    app(AccountEraser::class)->erase($this->person->fresh());

    connectorMessage($this, $connected['access'], 'tools/list')->assertUnauthorized();
    expect(ConnectedApp::query()->count())->toBe(0)
        ->and(Passport::token()->newQuery()->where('revoked', false)->count())->toBe(0)
        ->and(Passport::refreshToken()->newQuery()->where('revoked', false)->count())->toBe(0);
});

it('removes apps that registered long ago and never connected, and no others', function () {
    $old = connectorRegister($this, 'Never connected');
    $recent = connectorRegister($this, 'Just registered');
    $used = connectorConnect($this, $this->person, 'Connected')['client'];
    Passport::client()->newQuery()->whereKey([$old, $used])->update(['created_at' => now()->subDays(8)]);

    expect(Artisan::call('echoledger:prune-oauth-clients'))->toBe(0);

    expect(Passport::client()->newQuery()->pluck('id')->sort()->values()->all())->toBe(collect([$recent, $used])->sort()->values()->all());
});

it('purges old tokens with Passport\'s own command, which needs no device-code table', function () {
    $connected = connectorConnect($this, $this->person);
    connectorRefresh($this, $connected['client'], $connected['refresh'])->assertOk();
    expect(Passport::token()->newQuery()->where('revoked', true)->count())->toBe(1);

    expect(Artisan::call('passport:purge', ['--revoked' => true]))->toBe(0);

    expect(Passport::token()->newQuery()->where('revoked', true)->count())->toBe(0)
        ->and(Passport::token()->newQuery()->where('revoked', false)->count())->toBe(1);
});

it('schedules the clean-ups', function () {
    Artisan::call('schedule:list');

    expect(Artisan::output())->toContain('passport:purge')->toContain('echoledger:prune-oauth-clients');
});

it('keeps the keys out of git, private key readable by the owner only', function () {
    $dir = storage_path('framework/testing/oauth-keys-'.getmypid());
    File::ensureDirectoryExists($dir);
    Passport::loadKeysFrom($dir);
    try {
        expect(Artisan::call('passport:keys', ['--length' => 2048]))->toBe(0);
        expect(substr(sprintf('%o', fileperms($dir.'/oauth-private.key')), -3))->toBe('600');
    } finally {
        Passport::$keyPath = null;
        File::deleteDirectory($dir);
    }
    $ignored = (string) file_get_contents(base_path('.gitignore'));
    expect($ignored)->toContain('/storage/*.key');
});
