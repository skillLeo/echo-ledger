<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Filament\Auth\EmailVerificationPrompt;
use App\Filament\Auth\Register;
use App\Filament\Auth\RequestPasswordReset;
use App\Filament\Pages\Settings;
use App\Filament\Pages\WorkspaceSettings;
use App\Mail\InvitationMail;
use App\Mail\ResetPasswordMail;
use App\Mail\VerifyEmailMail;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Services\Accounts\Invitations;
use App\Services\Accounts\SignUp;
use App\Services\SettingsRepository;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    Mail::fake();
});

function openSignUp(string $mode = SignUp::ANYONE): void
{
    app(SettingsRepository::class)->set('signup.mode', $mode);
}

/** @return array<string, string> */
function signUpForm(array $overrides = []): array
{
    return array_merge(['name' => 'Ayesha Malik', 'email' => 'ayesha@example.test', 'password' => 'a-good-password-42', 'passwordConfirmation' => 'a-good-password-42'], $overrides);
}

it('keeps sign-up closed by default: no page and no link on the sign-in page', function () {
    expect(app(SignUp::class)->mode())->toBe(SignUp::NOBODY);

    $this->get('/admin/register')->assertNotFound();
    expect($this->get('/admin/login')->assertOk()->getContent())->not->toContain('/admin/register');
});

it('lets anyone make an account when sign-up is open, with a personal workspace and an unconfirmed email', function () {
    openSignUp();
    expect($this->get('/admin/login')->getContent())->toContain('/admin/register');
    $this->get('/admin/register')->assertOk();

    Livewire::test(Register::class)->fillForm(signUpForm(['email' => 'Ayesha@Example.test']))->call('register')->assertHasNoFormErrors();

    $user = User::query()->where('email', 'ayesha@example.test')->firstOrFail();
    expect($user->hasVerifiedEmail())->toBeFalse()
        ->and($user->is_super_admin)->toBeFalse()
        ->and($user->workspaces()->count())->toBe(1)
        ->and(Workspace::query()->findOrFail($user->current_workspace_id)->roleOf($user))->toBe(WorkspaceRole::Owner)
        ->and($user->consent_seen_at)->toBeNull();
    $this->assertAuthenticatedAs($user, 'web');
    Mail::assertQueued(VerifyEmailMail::class, fn (VerifyEmailMail $mail): bool => $mail->hasTo('ayesha@example.test') && str_contains($mail->url, '/email-verification/verify/'));
    expect(AuditEvent::query()->where('event', 'account_created')->where('user_id', $user->id)->exists())->toBeTrue();
});

it('asks for 12 characters with letters and numbers, and no address that has an account already', function (array $form, string $field) {
    openSignUp();
    User::factory()->create(['email' => 'taken@example.test']);

    Livewire::test(Register::class)->fillForm(signUpForm($form))->call('register')->assertHasFormErrors([$field]);
    expect(User::query()->where('name', 'Ayesha Malik')->exists())->toBeFalse();
})->with([
    'short' => [['password' => 'short-pw-1', 'passwordConfirmation' => 'short-pw-1'], 'password'],
    'no numbers' => [['password' => 'only-letters-here', 'passwordConfirmation' => 'only-letters-here'], 'password'],
    'no letters' => [['password' => '123456789012', 'passwordConfirmation' => '123456789012'], 'password'],
    'taken, other case' => [['email' => 'Taken@Example.test'], 'email'],
]);

it('keeps an unconfirmed account out of the console until its email is confirmed', function () {
    $user = User::factory()->unverified()->create();
    $this->actingAs($user, 'web');

    $this->get('/admin')->assertRedirect(route('filament.admin.auth.email-verification.prompt'));
    $this->get('/admin/sessions')->assertRedirect(route('filament.admin.auth.email-verification.prompt'));
    $this->get('/admin-upload/recordings/'.str_repeat('0', 26))->assertRedirect(route('filament.admin.auth.email-verification.prompt'));
    $this->get(route('filament.admin.auth.email-verification.prompt'))->assertOk()->assertSee($user->email);

    Livewire::test(EmailVerificationPrompt::class)->callAction('resendNotification');
    Mail::assertQueued(VerifyEmailMail::class, fn (VerifyEmailMail $mail): bool => $mail->hasTo($user->email));

    $this->get(Filament::getPanel('admin')->getVerifyEmailUrl($user))->assertRedirect();
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    $this->get('/admin')->assertOk();
});

it('signs up by invitation only for the invited address, joins the workspace and starts confirmed', function () {
    openSignUp(SignUp::INVITED);
    $owner = Fx::user();
    $workspace = Workspace::query()->findOrFail($owner->current_workspace_id);
    $url = app(Invitations::class)->invite($workspace, $owner, 'Guest@Example.test', WorkspaceRole::Member);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    Mail::assertQueued(InvitationMail::class, fn (InvitationMail $mail): bool => $mail->hasTo('guest@example.test') && $mail->signUp && $mail->url === $url);

    // Without the link the page only explains, and nothing can be registered.
    $this->get('/admin/register')->assertOk()->assertSee('by invitation');
    Livewire::test(Register::class)->fillForm(signUpForm(['email' => 'guest@example.test']))->call('register')->assertNotified('Sign-up here is by invitation');
    expect(User::query()->where('email', 'guest@example.test')->exists())->toBeFalse();

    // The link fills in its address; another address is refused even when the field is changed by hand.
    $page = Livewire::withQueryParams(['invite' => $query['invite']])->test(Register::class)->assertSet('data.email', 'guest@example.test');
    $page->set('data.email', 'someone.else@example.test')->set('data.name', 'G')->set('data.password', 'a-good-password-42')->set('data.passwordConfirmation', 'a-good-password-42')
        ->call('register')->assertHasErrors();
    expect(User::query()->where('email', 'someone.else@example.test')->exists())->toBeFalse();

    Livewire::withQueryParams(['invite' => $query['invite']])->test(Register::class)
        ->fillForm(signUpForm(['email' => 'guest@example.test', 'name' => 'Guest']))->call('register')->assertHasNoFormErrors();
    $guest = User::query()->where('email', 'guest@example.test')->firstOrFail();
    expect($guest->hasVerifiedEmail())->toBeTrue()
        ->and($workspace->roleOf($guest))->toBe(WorkspaceRole::Member)
        ->and($guest->current_workspace_id)->toBe($workspace->id)
        ->and($guest->workspaces()->count())->toBe(2)
        ->and(WorkspaceInvitation::query()->firstOrFail()->accepted_at)->not->toBeNull()
        ->and(app(Invitations::class)->find($query['invite']))->toBeNull();
    Mail::assertNotQueued(VerifyEmailMail::class);
});

it('does not let an open sign-up take an invited address without the invitation link', function () {
    openSignUp();
    $owner = Fx::user();
    $workspace = Workspace::query()->findOrFail($owner->current_workspace_id);
    $url = app(Invitations::class)->invite($workspace, $owner, 'bob@company.test', WorkspaceRole::Member);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    // Someone who only knows the address types it in: the account is theirs to confirm, not the team's.
    Livewire::test(Register::class)->fillForm(signUpForm(['email' => 'bob@company.test', 'name' => 'Not Bob']))->call('register')->assertHasNoFormErrors();

    $user = User::query()->where('email', 'bob@company.test')->firstOrFail();
    expect($user->hasVerifiedEmail())->toBeFalse()
        ->and($workspace->fresh()->roleOf($user))->toBeNull()
        ->and($user->current_workspace_id)->not->toBe($workspace->id)
        ->and($user->workspaces()->count())->toBe(1)
        ->and(WorkspaceInvitation::query()->firstOrFail()->accepted_at)->toBeNull()
        ->and(app(Invitations::class)->find($query['invite']))->not->toBeNull();
    Mail::assertQueued(VerifyEmailMail::class, fn (VerifyEmailMail $mail): bool => $mail->hasTo('bob@company.test'));
});

it('refuses an expired or used invitation link', function () {
    openSignUp(SignUp::INVITED);
    $owner = Fx::user();
    $url = app(Invitations::class)->invite(Workspace::query()->findOrFail($owner->current_workspace_id), $owner, 'late@example.test', WorkspaceRole::Member);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    WorkspaceInvitation::query()->update(['expires_at' => now()->subMinute()]);

    $this->get('/admin/register?invite='.$query['invite'])->assertOk()->assertSee('by invitation');
    expect(app(Invitations::class)->find($query['invite']))->toBeNull()
        ->and(app(Invitations::class)->find('not-a-token'))->toBeNull();
});

it('invites an address without an account from the Workspace page while sign-up is open, and tells an existing account it was added', function () {
    openSignUp(SignUp::INVITED);
    $owner = Fx::user();
    $existing = Fx::user();
    $this->actingAs($owner, 'web');

    Livewire::test(WorkspaceSettings::class)
        ->callAction('addMember', ['email' => 'new.person@example.test', 'role' => 'member'])
        ->assertNotified('Invitation sent to new.person@example.test')
        ->callAction('addMember', ['email' => $existing->email, 'role' => 'member']);

    expect(User::query()->where('email', 'new.person@example.test')->exists())->toBeFalse()
        ->and(WorkspaceInvitation::query()->where('email', 'new.person@example.test')->exists())->toBeTrue()
        ->and(Workspace::query()->findOrFail($owner->current_workspace_id)->roleOf($existing))->toBe(WorkspaceRole::Member);
    Mail::assertQueued(InvitationMail::class, fn (InvitationMail $mail): bool => $mail->hasTo('new.person@example.test') && $mail->signUp);
    Mail::assertQueued(InvitationMail::class, fn (InvitationMail $mail): bool => $mail->hasTo($existing->email) && ! $mail->signUp);
});

it('sends a reset link to an account and says the same for an unknown address', function () {
    $user = User::factory()->create(['email' => 'reset.me@example.test']);
    $this->get('/admin/password-reset/request')->assertOk();

    Livewire::test(RequestPasswordReset::class)->fillForm(['email' => 'Reset.Me@example.test'])->call('request')->assertNotified('Check your email');
    Mail::assertQueued(ResetPasswordMail::class, fn (ResetPasswordMail $mail): bool => $mail->hasTo('reset.me@example.test') && str_contains($mail->url, '/password-reset/reset'));

    Livewire::test(RequestPasswordReset::class)->fillForm(['email' => 'nobody@example.test'])->call('request')->assertNotified('Check your email');
    Mail::assertQueuedCount(1);
    expect($user->fresh()->password)->toBe($user->password);
});

it('switches sign-up on the Settings page, for the server admin only', function () {
    $admin = Fx::superAdmin();
    $this->actingAs($admin, 'web');

    Livewire::test(Settings::class)->set('activeTab', 'privacy')->assertSee('Who can sign up')
        ->fillForm(['signup_mode' => SignUp::INVITED])->call('save')->assertHasNoFormErrors();
    expect(app(SettingsRepository::class)->get('signup.mode'))->toBe(SignUp::INVITED);

    $this->actingAs(Fx::user(), 'web');
    $this->get('/admin/settings')->assertForbidden();
});
