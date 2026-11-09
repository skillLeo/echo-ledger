<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\ActivityLog;
use App\Filament\Admin\Pages\AppVersions;
use App\Filament\Admin\Pages\Notices;
use App\Filament\Admin\Pages\Settings;
use App\Filament\Admin\Pages\SignUpSettings;
use App\Models\AuditEvent;
use App\Models\Plan;
use App\Services\Accounts\Turnstile;
use App\Services\AuditLog;
use App\Services\CredentialVault;
use App\Services\SettingsRepository;
use App\Services\System\PlatformNotices;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
| v1.6 P2.7: the admin panel's System pages: sign-up, app versions, the announcement, maintenance and the activity log.
*/

uses(ApiClient::class);

function onAdminAsStaff(): void
{
    test()->actingAs(Fx::superAdmin(), 'web');
    Filament::setCurrentPanel('admin');
}

it('is closed to customers: every System page and its save', function () {
    $this->actingAs(Fx::user(), 'web');
    foreach (['/admin/sign-up', '/admin/app-versions', '/admin/announcement', '/admin/activity-log'] as $url) {
        $this->get($url)->assertForbidden();
    }
    Filament::setCurrentPanel('admin');
    foreach ([SignUpSettings::class, AppVersions::class, Notices::class, ActivityLog::class] as $page) {
        Livewire::test($page)->assertForbidden();
    }
    expect(app(SettingsRepository::class)->get(PlatformNotices::MAINTENANCE))->toBeNull();
});

it('saves who can sign up, the allowed domains, the network limit, the default plan and the payment instructions', function () {
    onAdminAsStaff();
    $pro = Plan::query()->where('key', 'pro')->firstOrFail();

    Livewire::test(SignUpSettings::class)->fillForm([
        'mode' => 'invited', 'allowed_domains' => ['Example.COM'], 'per_ip_day' => 3, 'default_plan' => $pro->id,
        'payment_instructions' => 'JazzCash 0300 0000000',
    ])->call('save')->assertHasNoFormErrors()->assertNotified('Saved');

    $settings = app(SettingsRepository::class);
    expect($settings->get('signup.mode'))->toBe('invited')->and($settings->get('signup.allowed_domains'))->toBe(['example.com'])
        ->and($settings->get('signup.per_ip_day'))->toBe(3)->and(Plan::defaultPlan()->key)->toBe('pro')
        ->and(Plan::query()->where('is_default', true)->count())->toBe(1)
        ->and($settings->get('upgrade.payment_instructions'))->toBe('JazzCash 0300 0000000')
        ->and(AuditEvent::query()->where('summary', 'Sign-up settings changed')->exists())->toBeTrue();
    $this->get('/admin/sign-up')->assertOk()->assertSee('anti-bot check');
});

it('switches the anti-bot check off and on, which works only once its keys are saved (since v1.6)', function () {
    onAdminAsStaff();
    $turnstile = app(Turnstile::class);

    // No keys: the switch is disabled, says why, and a save cannot turn the check on.
    $this->get('/admin/sign-up')->assertOk()->assertSee('Off until its keys are saved under API Credentials');
    Livewire::test(SignUpSettings::class)->assertFormFieldIsDisabled('turnstile')->call('save')->assertHasNoFormErrors();
    expect($turnstile->active())->toBeFalse();

    app(CredentialVault::class)->put('turnstile', 'site_key', '0x4AAAA-site');
    app(CredentialVault::class)->put('turnstile', 'secret_key', '0x4AAAA-secret');
    expect($turnstile->active())->toBeTrue();

    Livewire::test(SignUpSettings::class)->assertFormFieldIsEnabled('turnstile')->fillForm(['turnstile' => false])->call('save')->assertHasNoFormErrors();
    expect(app(SettingsRepository::class)->get(Turnstile::SETTING))->toBeFalse()->and(app(Turnstile::class)->active())->toBeFalse()
        ->and(app(Turnstile::class)->verify('anything', null))->toBeTrue();
    Http::fake();
    app(SettingsRepository::class)->set('signup.mode', 'anyone');
    $this->postJson('/api/auth/code', ['email' => 'new.one@example.test', 'turnstile' => 'bad'])->assertStatus(202);
    Http::assertNothingSent();

    Livewire::test(SignUpSettings::class)->fillForm(['turnstile' => true])->call('save');
    expect(app(Turnstile::class)->active())->toBeTrue();
});

it('keeps the sign-up values on the Sign-up page alone: Settings links there and leaves them as they are', function () {
    onAdminAsStaff();
    app(SettingsRepository::class)->setMany(['signup.mode' => 'anyone', 'signup.per_ip_day' => 7]);

    Livewire::test(Settings::class)->set('activeTab', 'privacy')->assertFormFieldDoesNotExist('signup_mode')->assertFormFieldDoesNotExist('signup_per_ip_day')->assertSee('Open Sign-up')
        ->call('save')->assertHasNoFormErrors();
    expect(app(SettingsRepository::class)->get('signup.mode'))->toBe('anyone')->and(app(SettingsRepository::class)->get('signup.per_ip_day'))->toBe(7);
});

it('asks an older app to update, and refuses it when the admin says so, while GET /api/config still answers', function () {
    onAdminAsStaff();
    Livewire::test(AppVersions::class)->fillForm(['min_android' => '1.6.0', 'min_windows' => '', 'min_macos' => '', 'refuse_older' => false])
        ->call('save')->assertHasNoFormErrors();
    $old = Fx::device(Fx::user(), ['platform' => 'android', 'app_version' => '1.5.2']);
    $new = Fx::device(Fx::user(), ['platform' => 'android', 'app_version' => '1.6.1 (18)']);
    $desktop = Fx::device(Fx::user(), ['platform' => 'windows', 'app_version' => '1.0.0']);
    auth()->logout();

    $this->api(Fx::token($old))->getJson('/api/config')->assertOk()
        ->assertJsonPath('app_update.minimum', '1.6.0')->assertJsonPath('app_update.required', true)->assertJsonPath('app_update.refused', false)
        ->assertJsonPath('app_update.url', url('/downloads'));
    $this->api(Fx::token($old))->getJson('/api/library')->assertOk();
    $this->api(Fx::token($new))->getJson('/api/config')->assertJsonPath('app_update.required', false);
    $this->api(Fx::token($desktop))->getJson('/api/config')->assertJsonPath('app_update', null);

    app(SettingsRepository::class)->set(PlatformNotices::REFUSE_OLDER, true);
    $this->api(Fx::token($old))->getJson('/api/library')->assertStatus(426)->assertJsonPath('code', 'update_required')->assertJsonPath('minimum', '1.6.0');
    $this->api(Fx::token($old))->getJson('/api/config')->assertOk()->assertJsonPath('app_update.refused', true);
    $this->api(Fx::token($new))->getJson('/api/library')->assertOk();
});

it('refuses a version that is not one', function () {
    onAdminAsStaff();
    Livewire::test(AppVersions::class)->fillForm(['min_android' => '1.6; drop'])->call('save')->assertHasFormErrors(['min_android']);
    expect(app(PlatformNotices::class)->minimums()['android'])->toBeNull();
});

it('shows the announcement in the app and the apps until its end, as text', function () {
    onAdminAsStaff();
    Livewire::test(Notices::class)->fillForm(['text' => 'New prices from Dec 1 <script>alert(1)</script>', 'level' => 'warning', 'until' => now()->addDay()->toDateTimeString()])
        ->call('save')->assertHasNoFormErrors();
    $customer = Fx::user();
    $this->actingAs($customer, 'web');

    $html = (string) $this->get('/app')->assertOk()->getContent();
    expect($html)->toContain('data-announcement="warning"')->toContain('New prices from Dec 1 &lt;script&gt;')->not->toContain('<script>alert(1)');
    $this->api(Fx::token(Fx::device($customer)))->getJson('/api/config')->assertJsonPath('announcement.text', 'New prices from Dec 1 <script>alert(1)</script>')
        ->assertJsonPath('announcement.level', 'warning');

    app(SettingsRepository::class)->set(PlatformNotices::ANNOUNCEMENT, ['text' => 'Old', 'level' => 'info', 'until' => now()->subMinute()->toAtomString()]);
    expect((string) $this->get('/app')->getContent())->not->toContain('data-announcement');
});

it('pauses the app and the API for customers during maintenance, never for staff, and says why', function () {
    onAdminAsStaff();
    Livewire::test(Notices::class)->fillForm(['maintenance_on' => true, 'maintenance_message' => 'Moving to a bigger server.', 'maintenance_until' => now()->addHour()->toDateTimeString()])
        ->call('save')->assertNotified();
    $this->get('/app')->assertOk()->assertSee('Maintenance is on');
    $this->get('/admin')->assertOk();
    $staffDevice = Fx::device(Fx::superAdmin());

    $customer = Fx::user();
    $this->actingAs($customer, 'web');
    $this->get('/app')->assertStatus(503)->assertSee('Moving to a bigger server.')->assertHeader('Retry-After');
    $token = Fx::token(Fx::device($customer));
    $this->api($token)->getJson('/api/library')->assertStatus(503)->assertJsonPath('code', 'maintenance')->assertHeader('Retry-After');
    $this->api($token)->getJson('/api/config')->assertOk()->assertJsonPath('maintenance.message', 'Moving to a bigger server.');
    $this->api(Fx::token($staffDevice))->getJson('/api/library')->assertOk();
    // Public pages stay up.
    $this->get('/')->assertOk();
    expect(AuditEvent::query()->where('summary', 'Maintenance turned on')->exists())->toBeTrue();

    app(SettingsRepository::class)->set(PlatformNotices::MAINTENANCE, ['on' => false]);
    $this->api($token)->getJson('/api/library')->assertOk();
});

it('lists the whole platform\'s activity with filters, for staff who may see it', function () {
    $ana = Fx::user(['email' => 'ana@example.test']);
    app(AuditLog::class)->record('password_changed', $ana->id, $ana, 'Password changed by Ana');
    app(AuditLog::class)->record('plan_created', null, null, 'Plan Gold made');
    onAdminAsStaff();

    $this->get('/admin/activity-log')->assertOk()->assertSee('Password changed by Ana')->assertSee('Plan Gold made')->assertSee('ana@example.test');
    $passwords = AuditEvent::query()->where('event', 'password_changed')->get();
    Livewire::test(ActivityLog::class)->filterTable('event', ['plan_created'])->assertCanNotSeeTableRecords($passwords)
        ->resetTableFilters()->searchTable('Ana')->assertCanSeeTableRecords($passwords);
});

it('never shows staff the title of a recording or project in the activity log, nor finds it by search', function () {
    $ana = Fx::user(['email' => 'ana@example.test']);
    $session = Fx::session($ana, null, ['title' => 'Divorce lawyer call']);
    app(AuditLog::class)->record('session_deleted', $ana->id, $session, 'Recording deleted: Divorce lawyer call');
    onAdminAsStaff();

    $this->get('/admin/activity-log')->assertOk()->assertSee('Recording deleted')->assertDontSee('Divorce lawyer call');
    $this->get('/admin/customers/'.$ana->id)->assertOk()->assertDontSee('Divorce lawyer call');
    Livewire::test(ActivityLog::class)->searchTable('Divorce')->assertCountTableRecords(0);
});
