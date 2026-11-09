<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Filament\Concerns\WatchesPairing;
use App\Filament\Resources\Devices\Pages\ListDevices;
use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\DevicePairingCode;
use App\Models\Plan;
use App\Models\Workspace;
use App\Services\PairingService;
use App\Services\Workspaces\PlanLimits;
use App\Services\Workspaces\Workspaces;
use App\Support\DeviceNames;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
| Devices (since v1.6, P3): one row per install (`install_id`), readable names, Rename, statuses (Active, Signed out by
| you, Revoked by admin, Expired), Remove from list, the device limit per plan, and the QR modal's live status.
*/

uses(ApiClient::class);

const PHONE_INSTALL = '3f1c2b9e-7a44-4d61-9c1e-2f0b8a7d6e51';

beforeEach(function () {
    Mail::fake();
    $this->person = Fx::user(['email' => 'farah@example.test', 'password' => 'a-good-password-42']);
});

/** @return array<string, mixed> */
function signInBody(array $overrides = []): array
{
    return array_merge(['email' => 'farah@example.test', 'password' => 'a-good-password-42', 'name' => 'samsung SM-S938B', 'platform' => 'android',
        'app_version' => '1.6.0', 'os_version' => '16', 'install_id' => PHONE_INSTALL], $overrides);
}

describe('one row per install', function () {
    it('signs the same install in again on its own row, with a new token, and says so in the audit log', function () {
        $first = $this->postJson('/api/auth/password', signInBody())->assertCreated();
        $again = $this->postJson('/api/auth/password', signInBody(['app_version' => '1.6.1']))->assertCreated();

        expect($again->json('device.id'))->toBe($first->json('device.id'))
            ->and(Device::query()->withoutGlobalScopes()->where('user_id', $this->person->id)->count())->toBe(1)
            ->and(PersonalAccessToken::findToken($first->json('token')))->toBeNull()
            ->and(PersonalAccessToken::findToken($again->json('token')))->not->toBeNull()
            ->and(Device::query()->withoutGlobalScopes()->find($first->json('device.id'))->app_version)->toBe('1.6.1')
            ->and(AuditEvent::query()->where('event', 'device_signed_in_again')->value('summary'))->toBe('Signed in again: Galaxy S25 Ultra');
        // Another install is another device; another person's same install id is theirs.
        $this->postJson('/api/auth/password', signInBody(['install_id' => 'c0ffee00-1111-2222-3333-444455556666']))->assertCreated();
        expect(Device::query()->withoutGlobalScopes()->where('user_id', $this->person->id)->count())->toBe(2);
        Fx::user(['email' => 'other@example.test', 'password' => 'a-good-password-42']);
        $theirs = $this->postJson('/api/auth/password', signInBody(['email' => 'other@example.test']))->assertCreated();
        expect($theirs->json('device.id'))->not->toBe($first->json('device.id'));
    });

    it('takes install ids of 8 to 64 letters, digits and dashes only', function (string $id, int $status) {
        $this->postJson('/api/auth/password', signInBody(['install_id' => $id]))->assertStatus($status);
    })->with([
        'uuid' => [PHONE_INSTALL, 201],
        'desktop hash' => [str_repeat('ab12', 16), 201],
        'too short' => ['abc', 422],
        'too long' => [str_repeat('a', 65), 422],
        'dots' => ['abc.def.ghi', 422],
    ]);

    it('keeps a name the person chose when the install signs in again', function () {
        $id = $this->postJson('/api/auth/password', signInBody())->json('device.id');
        $token = $this->postJson('/api/auth/password', signInBody())->json('token');
        $this->api($token)->patchJson("/api/devices/{$id}", ['name' => '  Work phone  '])->assertOk()->assertJsonPath('name', 'Work phone');
        $this->postJson('/api/auth/password', signInBody())->assertCreated()->assertJsonPath('device.name', 'Work phone');
    });

    it('signs a signed-out install in again (within the plan), and counts only signed-in, recent devices', function () {
        Workspace::query()->whereKey($this->person->current_workspace_id)->update(['plan_id' => Plan::free()->id]);
        $phone = $this->postJson('/api/auth/password', signInBody())->assertCreated();
        $laptop = $this->postJson('/api/auth/password', signInBody(['name' => 'DESKTOP-7Q2M', 'platform' => 'windows', 'os_version' => 'build 26100', 'install_id' => str_repeat('ab12', 16)]))->assertCreated();
        // A third install is past the Free plan's 2 devices; the same phone again is not.
        $this->postJson('/api/auth/password', signInBody(['install_id' => 'c0ffee00-1111-2222-3333-444455556666']))->assertForbidden()
            ->assertJson(['code' => 'limit_reached', 'limit' => 'devices', 'message' => 'Your plan signs in 2 devices. Sign one out (Account → Devices), or upgrade.']);
        $phone = $this->postJson('/api/auth/password', signInBody())->assertCreated();

        // Signing the laptop out frees its place; it stays listed.
        $this->api($phone->json('token'))->deleteJson('/api/devices/'.$laptop->json('device.id'))->assertNoContent();
        $this->postJson('/api/auth/password', signInBody(['install_id' => 'c0ffee00-1111-2222-3333-444455556666']))->assertCreated();
        // Expired devices (90 days without a call) do not count either.
        Device::query()->withoutGlobalScopes()->where('install_id', PHONE_INSTALL)->update(['last_seen_at' => now()->subDays(91)]);
        expect(app(PlanLimits::class)->devicesUsed($this->person->current_workspace_id, $this->person->id))->toBe(1);
    });
});

describe('PATCH /api/devices/{id}', function () {
    it('renames the person\'s own device, 1 to 60 characters, and lists it so', function () {
        $device = Fx::device($this->person, ['name' => 'samsung SM-S938B']);
        $other = Fx::device($this->person, ['name' => 'Laptop', 'platform' => 'windows']);
        $token = Fx::token($device);

        $this->api($token)->patchJson("/api/devices/{$other->id}", ['name' => str_repeat('x', 61)])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->api($token)->patchJson("/api/devices/{$other->id}", ['name' => '   '])->assertStatus(422);
        $answer = $this->api($token)->patchJson("/api/devices/{$other->id}", ['name' => 'Office PC'])->assertOk();
        expect(array_keys($answer->json()))->toBe(['id', 'name', 'platform', 'app_version', 'last_seen_at', 'paired_at', 'current', 'status', 'signed_out_at'])
            ->and($answer->json('name'))->toBe('Office PC')->and($answer->json('status'))->toBe('active');
        $list = collect($this->api($token)->getJson('/api/security')->assertOk()->json('devices'))->keyBy('id');
        expect($list[$device->id]['name'])->toBe('Galaxy S25 Ultra')->and($list[$other->id]['name'])->toBe('Office PC')->and($list[$device->id]['current'])->toBeTrue();
    });

    it('answers 404 for someone else\'s device, in the same workspace too, and changes nothing', function () {
        $theirs = Fx::device($stranger = Fx::user(), ['name' => 'Their phone']);
        $team = Workspace::query()->findOrFail($this->person->current_workspace_id);
        $member = Fx::user();
        app(Workspaces::class)->addMember($team, $member);
        app(Workspaces::class)->switchTo($member, $team);
        $teammate = Fx::device($member->refresh(), ['name' => 'Teammate phone']);
        $token = Fx::token(Fx::device($this->person));

        foreach ([$theirs, $teammate] as $device) {
            $this->api($token)->patchJson("/api/devices/{$device->id}", ['name' => 'Hijacked'])->assertNotFound()->assertJsonPath('code', 'device_not_found');
            $this->api($token)->deleteJson("/api/devices/{$device->id}")->assertNotFound();
            expect(Device::query()->withoutGlobalScopes()->find($device->id)->name)->not->toBe('Hijacked');
        }
        expect(Device::query()->withoutGlobalScopes()->where('user_id', $stranger->id)->first()->status())->toBe(Device::ACTIVE);
    });
});

describe('statuses and the console', function () {
    beforeEach(fn () => Filament::setCurrentPanel('app'));

    it('shows Active, Signed out by you, Revoked by admin and Expired', function () {
        $owner = Fx::user();
        $member = Fx::user();
        $team = Workspace::query()->findOrFail($owner->current_workspace_id);
        app(Workspaces::class)->addMember($team, $member, WorkspaceRole::Member);
        app(Workspaces::class)->switchTo($member, $team);
        $active = Fx::device($owner, ['name' => 'Pixel 9']);
        $mine = Fx::device($owner, ['name' => 'Old phone']);
        $theirs = Fx::device($member->refresh(), ['name' => 'Member phone']);
        $stale = Fx::device($owner, ['name' => 'Tablet', 'last_seen_at' => now()->subDays(91)]);
        $owner->forceFill(['consent_seen_at' => now()])->save();
        $this->actingAs($owner, 'web');

        Livewire::test(ListDevices::class)
            ->callTableAction('signOut', $mine)
            ->callTableAction('signOut', $theirs);
        expect($active->fresh()->status())->toBe(Device::ACTIVE)
            ->and($mine->fresh()->status())->toBe(Device::SIGNED_OUT)
            ->and($theirs->fresh()->status())->toBe(Device::REVOKED)
            ->and($theirs->tokens()->count())->toBe(0)
            ->and($stale->fresh()->status())->toBe(Device::EXPIRED);

        // Signed-out devices leave the list until the filter shows them; Remove from list deletes the row.
        Livewire::test(ListDevices::class)->assertCanSeeTableRecords([$active, $stale])->assertCanNotSeeTableRecords([$mine, $theirs])
            ->filterTable('shown', 'signed_out')->assertCanSeeTableRecords([$mine, $theirs])->assertSee(['Signed out by you', 'Revoked by admin'])
            ->callTableAction('delete', $mine);
        expect(Device::query()->withoutGlobalScopes()->find($mine->id))->toBeNull();
    });

    it('shows readable names in the console list', function () {
        $owner = Fx::user();
        Fx::device($owner, ['name' => 'samsung SM-S938B']);
        Fx::device($owner, ['name' => 'DESKTOP-K2Q9', 'platform' => 'windows', 'os_version' => 'build 26100']);
        $owner->forceFill(['consent_seen_at' => now()])->save();
        $this->actingAs($owner, 'web');

        Livewire::test(ListDevices::class)->assertSee(['Galaxy S25 Ultra', 'Windows 11 PC'])->assertDontSee('SM-S938B');
    });

    it('watches the QR code of the modal: Waiting while the code is unused, the browser cannot point it elsewhere', function () {
        $owner = Fx::user();
        $owner->forceFill(['consent_seen_at' => now()])->save();
        $this->actingAs($owner, 'web');
        $page = Livewire::test(ListDevices::class)->mountAction('pair');

        expect($page->instance()->pairingStatus())->toBe(['state' => 'waiting', 'name' => null])
            ->and(DevicePairingCode::query()->withoutGlobalScopes()->where('code_hash', $page->get('pairingHash'))->where('user_id', $owner->id)->exists())->toBeTrue();
        expect(fn () => $page->set('pairingHash', str_repeat('a', 64)))->toThrow(CannotUpdateLockedPropertyException::class);
    });

    it('reports a used code as signed in with the device\'s readable name', function () {
        $owner = Fx::user();
        $owner->forceFill(['consent_seen_at' => now()])->save();
        $display = app(PairingService::class)->createCode($owner);
        $this->postJson('/api/devices/register', ['pairing_code' => $display, 'name' => 'samsung SM-S938B', 'platform' => 'android'])->assertCreated();

        $watcher = new class
        {
            use WatchesPairing;

            public function start(string $code): void
            {
                $this->watchPairing($code);
            }
        };
        $this->actingAs(Fx::user(), 'web');
        $watcher->start($display);
        expect($watcher->pairingStatus())->toBe(['state' => 'waiting', 'name' => null]); // someone else's code: nothing
        $this->actingAs($owner, 'web');
        expect($watcher->pairingStatus())->toBe(['state' => 'signed_in', 'name' => 'Galaxy S25 Ultra']);
    });
});

it('makes names readable, and keeps the ones people chose', function (string $name, string $platform, ?string $os, string $readable, bool $chosen = false) {
    expect(DeviceNames::readable($name, $platform, $os, $chosen))->toBe($readable);
})->with([
    ['samsung SM-S938B', 'android', '16', 'Galaxy S25 Ultra'],
    ['SM-S938B', 'android', null, 'Galaxy S25 Ultra'],
    ['samsung SM-S931U1', 'android', null, 'Galaxy S25'],
    ['samsung SM-S936B', 'android', null, 'Galaxy S25+'],
    ['samsung SM-A556E', 'android', null, 'Galaxy A55'],
    ['Google Pixel 9 Pro', 'android', null, 'Pixel 9 Pro'],
    ['xiaomi 23078PND5G', 'android', null, 'Xiaomi 23078PND5G'],
    ['S25 Ultra', 'android', null, 'S25 Ultra'],
    ['DESKTOP-7Q2M3K', 'windows', 'build 26100', 'Windows 11 PC'],
    ['DESKTOP-7Q2M3K', 'windows', 'build 19045', 'Windows 10 PC'],
    ['Windos 11', 'windows', null, 'Windows PC'],
    ['Work laptop', 'windows', 'build 26100', 'Work laptop'],
    ['Hassams-MacBook-Pro.local', 'macos', '15.1', 'MacBook Pro'],
    ['Hassams-MacBook-Air.local', 'macos', '15.1', 'MacBook Air'],
    ['Office-iMac.local', 'macos', '15.1', 'iMac'],
    ['studio.local', 'macos', '15.1', 'Mac'],
    ['samsung SM-S938B', 'android', null, 'samsung SM-S938B', true],
]);
