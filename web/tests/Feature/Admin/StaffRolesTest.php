<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\Plans;
use App\Filament\Admin\Pages\Staff;
use App\Filament\Admin\Resources\Customers\Pages\ViewCustomer;
use App\Models\StaffRole;
use App\Models\User;
use App\Services\Staff\StaffAccess;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
| v1.6 P2.5: staff roles and permissions. The super admin has every permission; Admin, Support, Billing and Read-only
| come ready and more can be made; two-step sign-in before /admin opens; nobody gives more than they have.
*/

uses(InteractsWithDeviceApi::class);

function staffWith(string $role, bool $twoFactor = true): User
{
    $user = Fx::user();
    $user->forceFill(['staff_role_id' => StaffRole::query()->where('key', $role)->value('id'), 'app_authentication_secret' => $twoFactor ? Fx::STAFF_TOTP_SECRET : null])->save();

    return $user->fresh();
}

it('comes with Admin, Support, Billing and Read-only, and gives the super admin everything', function () {
    expect(StaffRole::query()->orderBy('id')->pluck('key')->all())->toBe(['admin', 'support', 'billing', 'read_only'])
        ->and(StaffRole::query()->where('is_system', false)->count())->toBe(0);
    $super = Fx::superAdmin();
    foreach (array_keys(StaffAccess::PERMISSIONS) as $permission) {
        expect(StaffAccess::can($permission, $super))->toBeTrue($permission);
    }
    expect(StaffAccess::isStaff(Fx::user()))->toBeFalse()->and(StaffAccess::can('customers.view', Fx::user()))->toBeFalse();
});

it('opens to each role exactly the pages its permissions name', function () {
    $pages = ['/admin/customers' => 'customers.view', '/admin/plans' => 'plans.view', '/admin/upgrade-requests' => 'upgrades.manage',
        '/admin/server-usage' => 'usage.view', '/admin/api-credentials' => 'credentials.manage', '/admin/settings' => 'settings.manage',
        '/admin/staff' => 'staff.manage', '/admin/device-reports' => 'devices.reports'];
    foreach (['admin', 'support', 'billing', 'read_only'] as $key) {
        $person = staffWith($key);
        $this->actingAs($person, 'web');
        $this->get('/admin')->assertOk();
        foreach ($pages as $url => $permission) {
            $expected = StaffRole::query()->where('key', $key)->firstOrFail()->grants($permission) ? 200 : 403;
            expect($this->get($url)->status())->toBe($expected, "{$key} {$url}");
        }
        auth()->logout();
    }
    // As the plan says: Admin runs customers and plans but no API keys; Support sees and helps; Billing has plans and requests.
    expect(StaffAccess::can('credentials.manage', staffWith('admin')))->toBeFalse()
        ->and(StaffAccess::can('customers.support', staffWith('support')))->toBeTrue()
        ->and(StaffAccess::can('customers.manage', staffWith('support')))->toBeFalse()
        ->and(StaffAccess::can('upgrades.manage', staffWith('billing')))->toBeTrue()
        ->and(StaffAccess::can('customers.manage', staffWith('billing')))->toBeFalse();
});

it('lets each role do only what it may on a page it can open', function () {
    $ana = Fx::user(['email_verified_at' => null]);
    $this->actingAs(staffWith('read_only'), 'web');
    Filament::setCurrentPanel('admin');
    Livewire::test(ViewCustomer::class, ['record' => $ana->id])->assertOk()
        ->assertActionHidden('changePlan')->assertActionHidden('addNote')->assertActionHidden('resendVerification');
    Livewire::test(Plans::class)->assertTableActionHidden('create')->call('toggleFeature', StaffRole::query()->value('id'), 'live_translate')->assertForbidden();

    $this->actingAs(staffWith('support'), 'web');
    Livewire::test(ViewCustomer::class, ['record' => $ana->id])->assertActionVisible('resendVerification')->assertActionHidden('changePlan');
});

it('asks every member of staff for two-step sign-in before /admin opens', function () {
    $person = staffWith('support', twoFactor: false);
    $this->actingAs($person, 'web');

    $this->get('/admin')->assertRedirect('/app/profile?tab=sign-in#two-step-sign-in');
    Notification::assertNotified('Turn on two-step sign-in to open the admin panel');
    $this->get('/app/profile')->assertOk();
    $this->get('/admin/customers')->assertRedirect('/app/profile?tab=sign-in#two-step-sign-in');
    $this->get('/admin/customers', ['X-Livewire' => 'true'])->assertForbidden();

    $person->forceFill(['app_authentication_secret' => Fx::STAFF_TOTP_SECRET])->save();
    $this->get('/admin/customers')->assertOk();
});

it('closes the admin panel at once to someone removed from staff or suspended', function () {
    $person = staffWith('admin');
    $this->actingAs($person, 'web');
    $this->get('/admin/customers')->assertOk();

    $person->forceFill(['suspended_at' => now()])->save();
    expect(StaffAccess::isStaff($person->fresh()))->toBeFalse();
    $person->forceFill(['suspended_at' => null, 'staff_role_id' => null])->save();
    $this->actingAs($person->fresh(), 'web');
    $this->get('/admin/customers')->assertForbidden();
});

it('adds staff by email, changes and removes their role, and makes a role of its own on the matrix', function () {
    $this->actingAs(Fx::superAdmin(), 'web');
    Filament::setCurrentPanel('admin');
    $ana = Fx::user(['email' => 'ana@example.test']);
    $support = StaffRole::query()->where('key', 'support')->firstOrFail();

    Livewire::test(Staff::class)->callTableAction('addStaff', null, ['email' => 'ANA@example.test', 'staff_role_id' => $support->id])->assertNotified();
    expect($ana->fresh()->staff_role_id)->toBe($support->id);

    Livewire::test(Staff::class)->callTableAction('newRole', null, ['name' => 'Moderator']);
    $moderator = StaffRole::query()->where('key', 'moderator')->firstOrFail();
    expect($moderator->permissions)->toBe([])->and($moderator->is_system)->toBeFalse();
    Livewire::test(Staff::class)->call('togglePermission', $moderator->id, 'audit.view')->call('togglePermission', $moderator->id, 'customers.view');
    expect($moderator->fresh()->permissions)->toBe(['customers.view', 'audit.view']);

    Livewire::test(Staff::class)->callTableAction('changeRole', $ana->fresh(), ['staff_role_id' => $moderator->id]);
    expect(StaffAccess::can('audit.view', $ana->fresh()))->toBeTrue()->and(StaffAccess::can('customers.support', $ana->fresh()))->toBeFalse();
    $this->get('/admin/staff')->assertOk()->assertSee('data-permissions-matrix', false)->assertSee('Moderator')->assertSee('ana@example.test');

    Livewire::test(Staff::class)->call('deleteRole', $moderator->id);
    expect(StaffRole::query()->find($moderator->id))->not->toBeNull();
    Livewire::test(Staff::class)->callTableAction('removeStaff', $ana->fresh());
    expect($ana->fresh()->staff_role_id)->toBeNull();
    Livewire::test(Staff::class)->call('deleteRole', $moderator->id)->call('deleteRole', $support->id);
    expect(StaffRole::query()->find($moderator->id))->toBeNull()->and(StaffRole::query()->find($support->id))->not->toBeNull();
});

it('never lets staff give more than they have, nor change their own role', function () {
    $keeper = StaffRole::query()->create(['key' => 'keeper', 'name' => 'Keeper', 'permissions' => ['staff.manage', 'customers.view']]);
    $me = Fx::user();
    $me->forceFill(['staff_role_id' => $keeper->id, 'app_authentication_secret' => Fx::STAFF_TOTP_SECRET])->save();
    $this->actingAs($me->fresh(), 'web');
    Filament::setCurrentPanel('admin');
    $support = StaffRole::query()->where('key', 'support')->firstOrFail();
    $admin = StaffRole::query()->where('key', 'admin')->firstOrFail();
    $other = Fx::user(['email' => 'other@example.test']);

    Livewire::test(Staff::class)->call('togglePermission', $support->id, 'credentials.manage')->assertForbidden();
    Livewire::test(Staff::class)->call('togglePermission', $keeper->id, 'settings.manage')->assertForbidden();
    Livewire::test(Staff::class)->callTableAction('addStaff', null, ['email' => 'other@example.test', 'staff_role_id' => $admin->id]);
    expect($support->fresh()->grants('credentials.manage'))->toBeFalse()->and($keeper->fresh()->grants('settings.manage'))->toBeFalse()
        ->and($other->fresh()->staff_role_id)->toBeNull();
    // Taking away what they have themselves is fine.
    Livewire::test(Staff::class)->call('togglePermission', $support->id, 'usage.view');
    expect($support->fresh()->grants('usage.view'))->toBeFalse();
});

it('is closed to customers: the page and the matrix', function () {
    $this->actingAs(Fx::user(), 'web');
    $this->get('/admin/staff')->assertForbidden();
    Filament::setCurrentPanel('admin');
    Livewire::test(Staff::class)->assertForbidden();
    $support = StaffRole::query()->where('key', 'support')->firstOrFail();
    expect(fn () => (new Staff)->togglePermission($support->id, 'credentials.manage'))->toThrow(HttpException::class);
    expect($support->fresh()->grants('credentials.manage'))->toBeFalse();
});

it('tells the apps that staff with any role may open the admin panel', function () {
    [, $token] = $this->enroll(staffWith('read_only'), ['config:read']);
    $this->asToken($token)->getJson('/api/account')->assertOk()->assertJsonPath('super_admin', true);
});

it('shows what each workspace role may do on the app\'s Workspace page', function () {
    $this->actingAs(Fx::user(), 'web');
    $this->get('/app/workspace')->assertOk()->assertSee('What each role may do')->assertSee('Revoke another person&#039;s device', false)
        ->assertSee('members and viewers');
});
