<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Filament\Admin\Pages\Settings;
use App\Filament\Auth\EmailVerificationPrompt;
use App\Filament\Auth\Login;
use App\Filament\Auth\Register;
use App\Filament\Auth\RequestPasswordReset;
use App\Filament\Pages\GetTheApps;
use App\Filament\Pages\WorkspaceSettings;
use App\Mail\EmailCodeMail;
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
    Filament::setCurrentPanel('app');
    Mail::fake();
});

function openSignUp(string $mode = SignUp::ANYONE): void
{
    app(SettingsRepository::class)->set('signup.mode', $mode);
}

/** The console's sign-up of [$email] (since v1.6: email → code → name and Terms) on [$page], a Login or Register test. */
function consoleSignUp(mixed $page, string $email, string $name = 'Ayesha Malik', bool $accept = true): mixed
{
    $page->fillForm(['email' => $email])->call('authenticate')->assertHasNoFormErrors();
    $code = null;
    Mail::assertQueued(EmailCodeMail::class, function (EmailCodeMail $mail) use ($email, &$code): bool {
        if ($mail->hasTo(strtolower($email))) {
            $code = $mail->code;
        }

        return true;
    });

    return $page->fillForm(['code' => $code])->call('authenticate')->assertSet('step', Login::STEP_PROFILE)
        ->fillForm(['name' => $name, 'accept_terms' => $accept])->call('authenticate');
}

it('keeps sign-up closed by default: no page and no link on the sign-in page', function () {
    expect(app(SignUp::class)->mode())->toBe(SignUp::NOBODY);

    $this->get('/app/register')->assertNotFound();
    expect($this->get('/app/login')->assertOk()->getContent())->not->toContain('/app/register');
    $this->get('/sign-up')->assertRedirect(route('filament.app.auth.login'));
    // A new address is told sign-up is closed; nothing is emailed.
    Livewire::test(Login::class)->fillForm(['email' => 'new.person@example.test'])->call('authenticate')->assertHasFormErrors(['email'])->assertSet('step', Login::STEP_EMAIL);
    Mail::assertNotQueued(EmailCodeMail::class);
});

it('lets anyone make an account when sign-up is open: confirmed by the code, a personal workspace, then Get the apps', function () {
    openSignUp();
    $this->get('/sign-up')->assertRedirect(route('filament.app.auth.register'));
    $this->get('/app/register')->assertOk()->assertSee('Create your free account');

    consoleSignUp(Livewire::test(Register::class), 'Ayesha@Example.test')->assertHasNoFormErrors()->assertRedirect(GetTheApps::getUrl(panel: 'app'));

    $user = User::query()->where('email', 'ayesha@example.test')->firstOrFail();
    expect($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->name)->toBe('Ayesha Malik')
        ->and($user->has_password)->toBeFalse()
        ->and($user->terms_accepted_at)->not->toBeNull()
        ->and($user->is_super_admin)->toBeFalse()
        ->and($user->workspaces()->count())->toBe(1)
        ->and(Workspace::query()->findOrFail($user->current_workspace_id)->roleOf($user))->toBe(WorkspaceRole::Owner)
        ->and($user->consent_seen_at)->not->toBeNull();
    $this->assertAuthenticatedAs($user, 'web');
    Mail::assertNotQueued(VerifyEmailMail::class);
    expect(AuditEvent::query()->where('event', 'account_created')->where('user_id', $user->id)->exists())->toBeTrue();
    $this->get(GetTheApps::getUrl(panel: 'app'))->assertOk()->assertSee('Get the apps')->assertSee('downloads');
});

it('makes the account from the sign-in page too, and needs a name and the Terms', function () {
    openSignUp();
    User::factory()->create(['email' => 'taken@example.test']);

    consoleSignUp(Livewire::test(Login::class), 'nida@example.test', 'Nida', accept: false)->assertHasFormErrors(['accept_terms']);
    expect(User::query()->where('email', 'nida@example.test')->exists())->toBeFalse();
    // An address with an account goes to the password, not to a sign-up.
    Livewire::test(Register::class)->fillForm(['email' => 'Taken@Example.test'])->call('authenticate')->assertSet('step', Login::STEP_PASSWORD);
});

it('keeps an unconfirmed account out of the console until its email is confirmed', function () {
    $user = User::factory()->unverified()->create();
    $this->actingAs($user, 'web');

    $this->get('/app')->assertRedirect(route('filament.app.auth.email-verification.prompt'));
    $this->get('/app/sessions')->assertRedirect(route('filament.app.auth.email-verification.prompt'));
    $this->get('/admin-upload/recordings/'.str_repeat('0', 26))->assertRedirect(route('filament.app.auth.email-verification.prompt'));
    $this->get(route('filament.app.auth.email-verification.prompt'))->assertOk()->assertSee($user->email);

    Livewire::test(EmailVerificationPrompt::class)->callAction('resendNotification');
    Mail::assertQueued(VerifyEmailMail::class, fn (VerifyEmailMail $mail): bool => $mail->hasTo($user->email));

    $this->get(Filament::getPanel('app')->getVerifyEmailUrl($user))->assertRedirect();
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    $this->get('/app')->assertOk();
});

it('signs up by invitation only for the invited address, joins the workspace and starts confirmed', function () {
    openSignUp(SignUp::INVITED);
    $owner = Fx::user();
    $workspace = Workspace::query()->findOrFail($owner->current_workspace_id);
    $url = app(Invitations::class)->invite($workspace, $owner, 'Guest@Example.test', WorkspaceRole::Member);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    Mail::assertQueued(InvitationMail::class, fn (InvitationMail $mail): bool => $mail->hasTo('guest@example.test') && $mail->signUp && $mail->url === $url);

    // Without the link the page only explains.
    $this->get('/app/register')->assertOk()->assertSee('by invitation');
    // Someone else's address is refused on the sign-in page: sign-up is by invitation.
    Livewire::test(Login::class)->fillForm(['email' => 'someone.else@example.test'])->call('authenticate')->assertHasFormErrors(['email']);

    // The link fills in its address and keeps it.
    $page = Livewire::withQueryParams(['invite' => $query['invite']])->test(Register::class)->assertSet('data.email', 'guest@example.test');
    consoleSignUp($page, 'guest@example.test', 'Guest')->assertHasNoFormErrors();
    $guest = User::query()->where('email', 'guest@example.test')->firstOrFail();
    expect($guest->hasVerifiedEmail())->toBeTrue()
        ->and($workspace->roleOf($guest))->toBe(WorkspaceRole::Member)
        ->and($guest->current_workspace_id)->toBe($workspace->id)
        ->and($guest->workspaces()->count())->toBe(2)
        ->and(WorkspaceInvitation::query()->firstOrFail()->accepted_at)->not->toBeNull()
        ->and(app(Invitations::class)->find($query['invite']))->toBeNull();
    Mail::assertNotQueued(VerifyEmailMail::class);
});

it('joins a waiting invitation when an open sign-up proves the invited address with the code', function () {
    openSignUp();
    $owner = Fx::user();
    $workspace = Workspace::query()->findOrFail($owner->current_workspace_id);
    app(Invitations::class)->invite($workspace, $owner, 'bob@company.test', WorkspaceRole::Member);

    // Since v1.6 the emailed code proves the address, as Google does: the invitation waiting for it is accepted.
    consoleSignUp(Livewire::test(Register::class), 'bob@company.test', 'Bob')->assertHasNoFormErrors();

    $user = User::query()->where('email', 'bob@company.test')->firstOrFail();
    expect($user->hasVerifiedEmail())->toBeTrue()
        ->and($workspace->fresh()->roleOf($user))->toBe(WorkspaceRole::Member)
        ->and(WorkspaceInvitation::query()->firstOrFail()->accepted_at)->not->toBeNull();
});

it('refuses an expired or used invitation link', function () {
    openSignUp(SignUp::INVITED);
    $owner = Fx::user();
    $url = app(Invitations::class)->invite(Workspace::query()->findOrFail($owner->current_workspace_id), $owner, 'late@example.test', WorkspaceRole::Member);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    WorkspaceInvitation::query()->update(['expires_at' => now()->subMinute()]);

    $this->get('/app/register?invite='.$query['invite'])->assertOk()->assertSee('by invitation');
    expect(app(Invitations::class)->find($query['invite']))->toBeNull()
        ->and(app(Invitations::class)->find('not-a-token'))->toBeNull();
});

it('invites an address without an account from the Workspace page while sign-up is open, and asks an existing account to join by a signed link', function () {
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
        ->and(WorkspaceInvitation::query()->where('email', $existing->email)->exists())->toBeTrue()
        ->and(Workspace::query()->findOrFail($owner->current_workspace_id)->roleOf($existing))->toBeNull();
    Mail::assertQueued(InvitationMail::class, fn (InvitationMail $mail): bool => $mail->hasTo('new.person@example.test') && $mail->signUp);
    Mail::assertQueued(InvitationMail::class, fn (InvitationMail $mail): bool => $mail->hasTo($existing->email) && ! $mail->signUp);
});

it('lets one account add or invite 20 people an hour, so a workspace cannot mail a list of strangers', function () {
    openSignUp();
    $owner = Fx::user();
    $this->actingAs($owner, 'web');
    $page = Livewire::test(WorkspaceSettings::class);

    foreach (range(1, WorkspaceSettings::ADDS_PER_HOUR) as $i) {
        $page->callAction('addMember', ['email' => "person{$i}@example.test", 'role' => 'member'])->assertNotified("Invitation sent to person{$i}@example.test");
    }
    $page->callAction('addMember', ['email' => 'one.more@example.test', 'role' => 'member'])->assertNotified('Too many people added for now');

    expect(WorkspaceInvitation::query()->count())->toBe(WorkspaceSettings::ADDS_PER_HOUR)
        ->and(WorkspaceInvitation::query()->where('email', 'one.more@example.test')->exists())->toBeFalse();
    Mail::assertQueuedCount(WorkspaceSettings::ADDS_PER_HOUR);

    $this->travel(61)->minutes();
    $page->callAction('addMember', ['email' => 'one.more@example.test', 'role' => 'member'])->assertNotified('Invitation sent to one.more@example.test');
});

it('sends a reset link to an account and says the same for an unknown address', function () {
    $user = User::factory()->create(['email' => 'reset.me@example.test']);
    $this->get('/app/password-reset/request')->assertOk();

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
