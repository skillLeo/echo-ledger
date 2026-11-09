<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Filament\Auth\EditProfile;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Accounts\Invitations;
use App\Services\Accounts\SignUp;
use App\Services\SettingsRepository;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

require_once __DIR__.'/Support/account_helpers.php';

beforeEach(function () {
    Filament::setCurrentPanel('app');
    Mail::fake();
});

it('offers nothing until API Credentials has a Google client', function () {
    expect($this->get('/app/login')->getContent())->not->toContain('Continue with Google');
    $this->get('/auth/google/redirect')->assertNotFound();
    $this->get('/auth/google/callback')->assertNotFound();
});

it('shows the button once configured and sends the browser to Google', function () {
    fakeGoogle();

    expect($this->get('/app/login')->getContent())->toContain('Continue with Google')->toContain(route('auth.google.redirect'));
    $this->get('/auth/google/redirect')->assertRedirect('https://accounts.google.com/o/oauth2/auth?client_id=1234-test');
});

it('signs a linked account in and records it', function () {
    $user = Fx::user(['email' => 'person@example.test']);
    $user->forceFill(['google_id' => 'google-sub-1'])->save();
    fakeGoogle();

    $this->get('/auth/google/callback?code=abc&state=xyz')->assertRedirect('/app');

    $this->assertAuthenticatedAs($user, 'web');
    expect(AuditEvent::query()->where('event', 'google_login')->where('user_id', $user->id)->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('event', 'login')->exists())->toBeFalse();
});

it('still asks a linked account with two-step sign-in for its code', function () {
    $user = Fx::user(['email' => 'person@example.test']);
    $user->forceFill(['google_id' => 'google-sub-1'])->save();
    [$secret] = accountsEnrolTotp($user);
    fakeGoogle();

    $this->get('/auth/google/callback')->assertRedirect(route('auth.google.confirm'));
    $this->assertGuest('web');
    $this->get(route('auth.google.confirm'))->assertOk()->assertSee('two-step sign-in')->assertSee('name="code"', false)->assertDontSee('name="password"', false);

    $this->post(route('auth.google.confirm.store'), ['code' => '000000'])->assertOk()->assertSee('That code is wrong');
    $this->assertGuest('web');
    $this->post(route('auth.google.confirm.store'), ['code' => accountsTotp($user, $secret)])->assertRedirect('/app');
    $this->assertAuthenticatedAs($user, 'web');
});

it('links a Google account to an existing address only after its password, never silently', function () {
    $user = Fx::user(['email' => 'person@example.test']);
    fakeGoogle();

    $this->get('/auth/google/callback')->assertRedirect(route('auth.google.confirm'));
    $this->assertGuest('web');
    expect($user->fresh()->google_id)->toBeNull();
    $this->get(route('auth.google.confirm'))->assertOk()->assertSee('person@example.test')->assertSee('name="password"', false);

    $this->post(route('auth.google.confirm.store'), ['password' => 'not-the-password'])->assertOk()->assertSee('That password is wrong');
    expect($user->fresh()->google_id)->toBeNull();
    $this->assertGuest('web');

    $this->post(route('auth.google.confirm.store'), ['password' => 'password'])->assertRedirect('/app');
    expect($user->fresh()->google_id)->toBe('google-sub-1');
    $this->assertAuthenticatedAs($user->fresh(), 'web');
    expect(AuditEvent::query()->where('event', 'google_linked')->where('user_id', $user->id)->exists())->toBeTrue();
});

it('links an address with two-step sign-in after its code, and locks after five wrong tries', function () {
    $user = Fx::user(['email' => 'person@example.test']);
    [$secret] = accountsEnrolTotp($user);
    fakeGoogle();
    $this->get('/auth/google/callback');

    foreach (range(1, 5) as $_) {
        $this->post(route('auth.google.confirm.store'), ['code' => '111111'])->assertSee('That code is wrong');
    }
    $this->post(route('auth.google.confirm.store'), ['code' => accountsTotp($user, $secret)])->assertSee('Too many tries');
    expect($user->fresh()->google_id)->toBeNull()
        ->and(AuditEvent::query()->where('event', 'sign_in_locked')->exists())->toBeTrue();
    $this->assertGuest('web');
});

it('makes an account for a new Google address only when sign-up allows it', function (string $mode, bool $made) {
    app(SettingsRepository::class)->set('signup.mode', $mode);
    fakeGoogle(email: 'newcomer@example.test', name: 'New Comer');

    $response = $this->get('/auth/google/callback');

    $user = User::query()->where('email', 'newcomer@example.test')->first();
    expect($user !== null)->toBe($made);
    if ($made) {
        $response->assertRedirect('/app');
        expect($user->google_id)->toBe('google-sub-1')->and($user->hasVerifiedEmail())->toBeTrue()->and($user->workspaces()->count())->toBe(1);
        $this->assertAuthenticatedAs($user, 'web');
    } else {
        $response->assertStatus(422)->assertSee('No account uses this address');
        $this->assertGuest('web');
    }
})->with([
    'nobody' => [SignUp::NOBODY, false],
    'anyone' => [SignUp::ANYONE, true],
    'invited, without an invitation' => [SignUp::INVITED, false],
]);

it('makes an account for an invited Google address and joins the workspace', function () {
    app(SettingsRepository::class)->set('signup.mode', SignUp::INVITED);
    $owner = Fx::user();
    $workspace = Workspace::query()->findOrFail($owner->current_workspace_id);
    app(Invitations::class)->invite($workspace, $owner, 'newcomer@example.test', WorkspaceRole::Member);
    fakeGoogle(email: 'newcomer@example.test');

    $this->get('/auth/google/callback')->assertRedirect('/app');

    $user = User::query()->where('email', 'newcomer@example.test')->firstOrFail();
    expect($workspace->roleOf($user))->toBe(WorkspaceRole::Member);
});

it('refuses a Google address Google has not verified', function () {
    app(SettingsRepository::class)->set('signup.mode', SignUp::ANYONE);
    Fx::user(['email' => 'person@example.test']);
    fakeGoogle(verified: false);

    $this->get('/auth/google/callback')->assertStatus(422)->assertSee('not verified');
    $this->assertGuest('web');
    expect(User::query()->count())->toBe(1);
});

it('links and unlinks from the profile, never a Google account another account uses', function () {
    $user = Fx::user();
    $other = Fx::user();
    $other->forceFill(['google_id' => 'google-sub-taken'])->save();
    $this->actingAs($user, 'web');

    fakeGoogle(id: 'google-sub-taken');
    $this->get('/auth/google/redirect?intent=link')->assertRedirect();
    $this->get('/auth/google/callback')->assertRedirect('/app/profile');
    expect($user->fresh()->google_id)->toBeNull();

    fakeGoogle(id: 'google-sub-mine');
    $this->get('/auth/google/redirect?intent=link');
    $this->get('/auth/google/callback')->assertRedirect('/app/profile');
    expect($user->fresh()->google_id)->toBe('google-sub-mine');

    $this->actingAs($user->fresh(), 'web');
    Livewire::test(EditProfile::class)->assertSee('A Google account is linked')->callAction(TestAction::make('unlinkGoogle')->schemaComponent('google-actions', schema: 'content'))->assertNotified('Google unlinked');
    expect($user->fresh()->google_id)->toBeNull()
        ->and(AuditEvent::query()->where('event', 'google_unlinked')->exists())->toBeTrue();
});

it('throttles the Google routes per address (429)', function () {
    fakeGoogle();
    foreach (range(1, 20) as $_) {
        $this->get('/auth/google/redirect');
    }

    $this->get('/auth/google/redirect')->assertStatus(429)->assertHeader('Retry-After');
});
