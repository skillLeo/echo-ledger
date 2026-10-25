<?php

declare(strict_types=1);

use App\Filament\Widgets\OnboardingChecklistWidget;
use App\Models\Device;
use App\Models\Passkey;
use App\Models\Setting;
use App\Models\User;
use App\Services\CredentialVault;
use App\Services\OnboardingChecklist;
use App\Services\SettingsRepository;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

function adminChecklist(): OnboardingChecklist
{
    return app(OnboardingChecklist::class);
}

/** Does every step the checklist knows about. */
function adminFinishSetup(User $admin): void
{
    $admin->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
    app(CredentialVault::class)->put('assemblyai', 'api_key', 'aai-secret-key-1234567890');
    app(SettingsRepository::class)->set('app.pins', ['sha256/'.str_repeat('A', 43).'=']);
    Device::factory()->create(['user_id' => $admin->id]);
    app(SettingsRepository::class)->set('retention_days', 30);
}

it('starts with every step to do on a fresh install', function () {
    $admin = $this->signInAsAdmin();

    expect(adminChecklist()->status($admin))->toBe([
        'two_factor' => false,
        'provider_key' => false,
        'cert_pins' => false,
        'first_device' => false,
        'retention' => false,
    ])->and(adminChecklist()->visibleTo($admin))->toBeTrue()
        ->and(OnboardingChecklistWidget::canView())->toBeTrue();
});

it('lists the steps in order with their links', function () {
    $this->signInAsAdmin();

    $steps = Livewire::test(OnboardingChecklistWidget::class)->instance()->steps();

    expect(array_column($steps, 'id'))->toBe(OnboardingChecklist::STEPS)
        ->and(array_column($steps, 'title'))->toBe([
            'Turn on two-factor sign-in',
            'Add a provider key',
            'Set certificate pins',
            'Pair your first device',
            'Choose how long to keep recordings',
        ])
        ->and($steps[0]['url'])->toEndWith('/admin/security')
        ->and($steps[1]['url'])->toEndWith('/admin/api-credentials')
        ->and($steps[2]['url'])->toEndWith('/admin/settings')
        ->and($steps[3]['url'])->toEndWith('/admin/devices')
        ->and($steps[4]['url'])->toEndWith('/admin/settings');
});

it('ticks two-factor sign-in for an authenticator app or a passkey', function () {
    $admin = $this->signInAsAdmin();
    expect(adminChecklist()->status($admin)['two_factor'])->toBeFalse();

    $admin->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
    expect(adminChecklist()->status($admin)['two_factor'])->toBeTrue();

    $admin->forceFill(['app_authentication_secret' => null])->save();
    expect(adminChecklist()->status($admin)['two_factor'])->toBeFalse();

    Passkey::query()->create([
        'user_id' => $admin->id,
        'name' => 'Laptop',
        'credential_id' => 'cred-1',
        'credential' => '{}',
    ]);
    expect(adminChecklist()->status($admin)['two_factor'])->toBeTrue();
});

it('ticks the provider key once any provider has a key', function () {
    $admin = $this->signInAsAdmin();
    $vault = app(CredentialVault::class);

    $vault->put('assemblyai', 'webhook_secret', 'only-a-webhook-secret');
    expect(adminChecklist()->status($admin)['provider_key'])->toBeFalse();

    $vault->put('elevenlabs', 'api_key', 'xi-secret-key-0987654321');
    expect(adminChecklist()->status($admin)['provider_key'])->toBeTrue();
});

it('ticks certificate pins, the first device and a chosen retention', function () {
    $admin = $this->signInAsAdmin();
    $settings = app(SettingsRepository::class);

    $settings->set('app.pins', ['sha256/'.str_repeat('B', 43).'=']);
    Device::factory()->create(['user_id' => $admin->id]);
    expect(adminChecklist()->status($admin))->toMatchArray(['cert_pins' => true, 'first_device' => true, 'retention' => false]);

    $settings->set('retention_days', 30);
    expect(adminChecklist()->status($admin)['retention'])->toBeTrue();
});

it('ignores certificate pins that devices could not use', function () {
    $admin = $this->signInAsAdmin();
    app(SettingsRepository::class)->set('app.pins', ['not a pin']);

    expect(adminChecklist()->status($admin)['cert_pins'])->toBeFalse();
});

it('shows the checklist on the dashboard with the steps that are left', function () {
    $this->signInAsAdmin();

    $this->get('/admin')->assertOk()
        ->assertSee('Finish setting up')
        ->assertSee('0 of 5 done')
        ->assertSee('Turn on two-factor sign-in')
        ->assertSee('Open Security')
        ->assertSee('Open API Credentials')
        ->assertSee('Dismiss');
});

it('counts the finished steps and drops their link', function () {
    $admin = $this->signInAsAdmin();
    app(CredentialVault::class)->put('assemblyai', 'api_key', 'aai-secret-key-1234567890');
    Device::factory()->create(['user_id' => $admin->id]);

    $this->get('/admin')->assertOk()
        ->assertSee('2 of 5 done')
        ->assertDontSee('Open API Credentials')
        ->assertDontSee('Open Devices')
        ->assertSee('Open Security');
});

it('disappears once every step is done', function () {
    $admin = $this->signInAsAdmin();
    adminFinishSetup($admin);

    expect(adminChecklist()->complete($admin))->toBeTrue()
        ->and(adminChecklist()->visibleTo($admin))->toBeFalse()
        ->and(OnboardingChecklistWidget::canView())->toBeFalse();
    $this->get('/admin')->assertOk()->assertDontSee('Finish setting up');
});

it('comes back if a finished step is undone', function () {
    $admin = $this->signInAsAdmin();
    adminFinishSetup($admin);
    app(SettingsRepository::class)->set('app.pins', []);

    expect(adminChecklist()->visibleTo($admin))->toBeTrue();
});

it('can be dismissed and stays away', function () {
    $this->signInAsAdmin();

    Livewire::test(OnboardingChecklistWidget::class)->call('dismiss');

    expect(Setting::query()->where('key', 'onboarding.dismissed')->exists())->toBeTrue()
        ->and(adminChecklist()->dismissed())->toBeTrue()
        ->and(OnboardingChecklistWidget::canView())->toBeFalse();
    $this->get('/admin')->assertOk()->assertDontSee('Finish setting up');
});

it('is for a super admin only', function () {
    $user = $this->makeRegularUser();

    expect(adminChecklist()->visibleTo($user))->toBeFalse()
        ->and(adminChecklist()->visibleTo(null))->toBeFalse();
});

it('never reveals a credential in the checklist', function () {
    $this->signInAsAdmin();
    app(CredentialVault::class)->put('assemblyai', 'api_key', 'aai-secret-key-1234567890');

    $html = $this->get('/admin')->assertOk()->getContent();

    expect($html)->not->toContain('aai-secret-key-1234567890')->not->toContain('7890');
});
