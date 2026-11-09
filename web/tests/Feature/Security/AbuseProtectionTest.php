<?php

declare(strict_types=1);

use App\Exceptions\ApiError;
use App\Filament\Auth\Login;
use App\Filament\Pages\Plans;
use App\Filament\Pages\Settings;
use App\Mail\EmailCodeMail;
use App\Mail\UnusualUsageMail;
use App\Models\AuditEvent;
use App\Models\CaptureSession;
use App\Models\Plan;
use App\Models\Workspace;
use App\Services\ActivityFeed;
use App\Services\Entitlements\UnusualUsage;
use App\Services\PairingService;
use App\Services\SettingsRepository;
use App\Services\Workspaces\PlanLimits;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
| Abuse protection (since v1.6, P1.5): the suspend switch (every API call 403 account_suspended, no sign-in anywhere, the
| devices keep their tokens), the unusual-usage alert, and the device limit's message. Turnstile, throwaway addresses
| and sign-ups per network a day are in EmailCodeSignInTest and ConsoleEmailCodeTest.
*/

uses(ApiClient::class);

beforeEach(function () {
    Mail::fake();
    Filament::setCurrentPanel('admin');
});

describe('the suspend switch', function () {
    it('refuses every call of the person\'s devices, which keep their tokens, until it is lifted', function () {
        $person = Fx::user(['email' => 'farah@example.test', 'password' => 'a-good-password-42']);
        $token = Fx::token($device = Fx::device($person));
        $person->forceFill(['suspended_at' => now()])->save();

        foreach (['/api/config', '/api/library', '/api/me/entitlements', '/api/account'] as $uri) {
            $this->api($token)->getJson($uri)->assertForbidden()->assertJsonPath('code', 'account_suspended');
        }
        $this->api($token)->postJson('/api/sessions', Fx::sessionPayload())->assertForbidden()->assertJsonPath('code', 'account_suspended');
        expect($device->tokens()->count())->toBe(1);

        $person->forceFill(['suspended_at' => null])->save();
        $this->api($token)->getJson('/api/config')->assertOk();
    });

    it('signs the person in nowhere: password, email code, pairing code', function () {
        $person = Fx::user(['email' => 'farah@example.test', 'password' => 'a-good-password-42']);
        $code = app(PairingService::class)->createCode($person);
        $person->forceFill(['suspended_at' => now()])->save();

        $this->postJson('/api/auth/password', ['email' => 'farah@example.test', 'password' => 'a-good-password-42', 'name' => 'Phone', 'platform' => 'android'])
            ->assertForbidden()->assertJsonPath('code', 'account_suspended');
        // A wrong password says nothing more than for anyone.
        $this->postJson('/api/auth/password', ['email' => 'farah@example.test', 'password' => 'wrong-password-1', 'name' => 'Phone', 'platform' => 'android'])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_credentials');
        $this->postJson('/api/auth/code', ['email' => 'farah@example.test'])->assertStatus(202);
        $emailed = null;
        Mail::assertQueued(EmailCodeMail::class, function (EmailCodeMail $m) use (&$emailed): bool {
            $emailed = $m->code;

            return true;
        });
        $this->postJson('/api/auth/code/verify', ['email' => 'farah@example.test', 'code' => $emailed, 'name' => 'Phone', 'platform' => 'android'])
            ->assertForbidden()->assertJsonPath('code', 'account_suspended');
        $this->postJson('/api/devices/register', ['pairing_code' => $code, 'name' => 'Phone', 'platform' => 'android'])->assertStatus(422);
        expect($person->devices()->count())->toBe(0);
    });

    it('refuses the console sign-in with a clear message, and signs an open console out', function () {
        $person = Fx::user(['email' => 'farah@example.test', 'password' => 'a-good-password-42']);
        $person->forceFill(['suspended_at' => now(), 'consent_seen_at' => now()])->save();

        Livewire::test(Login::class)->fillForm(['email' => 'farah@example.test'])->call('authenticate')
            ->assertHasFormErrors(['email' => Login::SUSPENDED])->assertSet('step', Login::STEP_EMAIL);
        Livewire::test(Login::class)->fillForm(['email' => 'farah@example.test', 'password' => 'a-good-password-42'])->call('authenticate')
            ->assertHasFormErrors(['email']);
        $this->assertGuest('web');

        $this->actingAs($person, 'web');
        $this->get('/admin')->assertRedirect(route('filament.admin.auth.login'));
        $this->assertGuest('web');
    });

    it('is switched by the super admin on the Plans page, and never for a super admin', function () {
        $admin = Fx::superAdmin();
        $person = Fx::user(['email' => 'nida@example.test']);
        $this->actingAs($admin, 'web');

        Livewire::test(Plans::class)->callTableAction('suspend', data: ['user_id' => $person->id, 'suspended' => true, 'reason' => 'Many accounts'])->assertHasNoTableActionErrors();
        expect($person->fresh()->isSuspended())->toBeTrue()->and($person->fresh()->suspended_reason)->toBe('Many accounts')
            ->and(AuditEvent::query()->where('event', 'account_suspended')->exists())->toBeTrue();
        Livewire::test(Plans::class)->callTableAction('suspend', data: ['user_id' => $person->id, 'suspended' => false])->assertHasNoTableActionErrors();
        expect($person->fresh()->isSuspended())->toBeFalse();

        Livewire::test(Plans::class)->callTableAction('suspend', data: ['user_id' => $admin->id, 'suspended' => true]);
        expect($admin->fresh()->isSuspended())->toBeFalse();
    });
});

describe('the unusual-usage alert', function () {
    it('emails and tells every super admin once a day per person above the hours set', function () {
        app(SettingsRepository::class)->set('abuse.hours_per_day', 2);
        $admin = Fx::superAdmin();
        $person = Fx::user(['name' => 'Nida', 'email' => 'nida@example.test']);
        $device = Fx::device($person);
        $first = Fx::session($person, $device, ['duration_ms' => 90 * 60000]);
        Fx::readyRecording($first, 'audio one');
        Mail::assertNotQueued(UnusualUsageMail::class);

        $second = Fx::session($person, $device, ['duration_ms' => 60 * 60000]);
        Fx::pendingRecording($second, str_repeat('b', 2048), 1024)->forceFill(['upload_status' => 'ready'])->save();
        Mail::assertQueued(UnusualUsageMail::class, fn (UnusualUsageMail $m): bool => $m->hasTo($admin->email) && $m->email === 'nida@example.test' && $m->hours === 2.5);
        expect($admin->notifications()->get()->contains(fn ($n): bool => ActivityFeed::kindOf($n) === 'unusual_usage'))->toBeTrue();

        // Once a day per person.
        app(UnusualUsage::class)->check($person->id);
        Mail::assertQueuedCount(1);
        $this->travel(25)->hours();
        Fx::session($person, $device, ['duration_ms' => 3 * 3600000]);
        app(UnusualUsage::class)->check($person->id);
        Mail::assertQueuedCount(2);
        // Nothing of what was said: only the hours.
        expect(CaptureSession::query()->withoutGlobalScopes()->count())->toBe(3);
    });

    it('is set on the Settings page, 6 hours by default', function () {
        expect(app(UnusualUsage::class)->hoursPerDay())->toBe(6);
        $this->actingAs(Fx::superAdmin(), 'web');
        Livewire::test(Settings::class)->set('activeTab', 'privacy')->assertSee('records more than')
            ->fillForm(['abuse_hours_per_day' => 9])->call('save')->assertHasNoFormErrors();
        expect(app(UnusualUsage::class)->hoursPerDay())->toBe(9);
    });
});

it('says the device limit in plain words', function () {
    $person = Fx::user();
    Workspace::query()->whereKey($person->current_workspace_id)->update(['plan_id' => Plan::free()->id]);
    Fx::device($person);
    Fx::device($person);

    $this->postJson('/api/devices/register', ['pairing_code' => 'XXXX-XXXX', 'name' => 'x', 'platform' => 'android']);
    expect(fn () => app(PairingService::class)->createCode($person->fresh()))
        ->toThrow(ApiError::class, 'Your plan signs in 2 devices. Sign one out (Account → Devices), or upgrade.')
        ->and(PlanLimits::devicesMessage(2))->not->toContain('token');
});
