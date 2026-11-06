<?php

declare(strict_types=1);

use App\Filament\Auth\EditProfile;
use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\DevicePairingCode;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspaces\Workspaces;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(InteractsWithDeviceApi::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    Mail::fake();
});

/** A console session row as the database session driver keeps it. */
function plantBrowser(User $user, string $id, string $agent = 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0 Safari/537.36'): void
{
    DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'ip_address' => '203.0.113.5', 'user_agent' => $agent, 'payload' => base64_encode(serialize([])), 'last_activity' => now()->getTimestamp()]);
}

it('lists the person\'s own devices in every workspace, and nobody else\'s', function () {
    $member = Fx::user(['name' => 'Member']);
    $owner = Fx::user();
    $team = Workspace::query()->findOrFail($owner->current_workspace_id);
    app(Workspaces::class)->addMember($team, $member);
    Device::factory()->create(['user_id' => $member->id, 'name' => 'Member phone', 'platform' => 'android', 'last_seen_at' => now()->subMinutes(5)]);
    unscoped(fn () => Device::factory()->create(['user_id' => $member->id, 'workspace_id' => $team->id, 'name' => 'Team laptop', 'platform' => 'windows']));
    Device::factory()->create(['user_id' => $owner->id, 'name' => 'Owner secret tablet']);
    $this->actingAs($member->fresh(), 'web');

    $this->get('/admin/profile')->assertOk()->assertSee('Devices')->assertSee('Member phone')->assertSee('Team laptop')
        ->assertSee($team->name)->assertSee('Sign in a device')->assertSee('Sign out everywhere')->assertDontSee('Owner secret tablet');
});

it('makes a pairing code for the person\'s own account, not only for admins', function () {
    $member = Fx::user();
    expect($member->fresh()->is_super_admin)->toBeFalse();
    $this->actingAs($member, 'web');

    Livewire::test(EditProfile::class)->mountAction(TestAction::make('signInDevice')->schemaComponent('device-actions', schema: 'content'))
        ->assertHasNoErrors();

    $pairing = unscoped(fn () => DevicePairingCode::query()->sole());
    expect($pairing->user_id)->toBe($member->id)->and($pairing->getAttribute('workspace_id'))->toBe($member->current_workspace_id);
});

it('revokes one of the person\'s devices but never someone else\'s', function () {
    $member = Fx::user();
    $other = Fx::user();
    $mine = Device::factory()->create(['user_id' => $member->id]);
    $theirs = Device::factory()->create(['user_id' => $other->id]);
    $this->actingAs($member, 'web');

    Livewire::test(EditProfile::class)->call('revokeDevice', $theirs->id)->assertNotified('That device was already revoked')
        ->call('revokeDevice', $mine->id)->assertNotified('Device revoked');

    expect(unscoped(fn () => Device::query()->pluck('id')->all()))->toBe([$theirs->id]);
});

it('signs out everywhere from the profile: every device, token and browser, this one included', function () {
    config(['session.driver' => 'database']);
    $member = Fx::user();
    $member->forceFill(['remember_token' => 'old-remember-token'])->save();
    [, $token] = $this->enroll($member);
    Device::factory()->create(['user_id' => $member->id]);
    plantBrowser($member, str_repeat('a', 40));
    plantBrowser(Fx::user(), str_repeat('b', 40));
    $this->actingAs($member, 'web');

    Livewire::test(EditProfile::class)->callAction(TestAction::make('signOutEverywhere')->schemaComponent('device-actions', schema: 'content'))
        ->assertRedirect(Filament::getPanel('admin')->getLoginUrl());

    expect(unscoped(fn () => Device::query()->where('user_id', $member->id)->count()))->toBe(0)
        ->and(PersonalAccessToken::findToken($token))->toBeNull()
        ->and(DB::table('sessions')->where('user_id', $member->id)->count())->toBe(0)
        ->and(DB::table('sessions')->count())->toBe(1)
        ->and($member->fresh()->remember_token)->not->toBe('old-remember-token')
        ->and(AuditEvent::query()->where('event', 'signed_out_everywhere')->where('user_id', $member->id)->exists())->toBeTrue();
    $this->assertGuest('web');
});

it('signs out everywhere from the API, the asking device included (204)', function () {
    config(['session.driver' => 'database']);
    $member = Fx::user();
    [$device, $token] = $this->enroll($member);
    Device::factory()->create(['user_id' => $member->id]);
    plantBrowser($member, str_repeat('c', 40));
    $bystander = Fx::user();
    Device::factory()->create(['user_id' => $bystander->id]);

    $this->asToken($token)->postJson('/api/account/sign-out-everywhere')->assertNoContent();

    expect(unscoped(fn () => Device::query()->where('user_id', $member->id)->count()))->toBe(0)
        ->and(unscoped(fn () => Device::query()->where('user_id', $bystander->id)->count()))->toBe(1)
        ->and(DB::table('sessions')->where('user_id', $member->id)->count())->toBe(0);
    app('auth')->forgetGuards();
    $this->asToken($token)->getJson('/api/config')->assertUnauthorized();
});

it('shows the device\'s country when the server\'s proxy names it', function () {
    config(['echoledger.country_header' => 'CF-IPCountry']);
    $member = Fx::user();
    [$device, $token] = $this->enroll($member);
    // The last-seen time (and with it the address and country) is written at most once a minute.
    $this->travel(2)->minutes();
    $this->asToken($token)->withHeaders(['CF-IPCountry' => 'pk'])->getJson('/api/config')->assertOk();
    expect(unscoped(fn () => $device->fresh()->last_country))->toBe('PK');

    config(['echoledger.country_header' => null]);
    $this->travel(2)->minutes();
    $this->asToken($token)->withHeaders(['CF-IPCountry' => 'US'])->getJson('/api/config')->assertOk();
    expect(unscoped(fn () => $device->fresh()->last_country))->toBeNull();

    $this->actingAs($member, 'web');
    $this->get('/admin/profile')->assertOk()->assertSee($device->name);
});
