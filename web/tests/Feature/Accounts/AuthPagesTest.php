<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Filament\Auth\Login;
use App\Filament\Auth\Register;
use App\Mail\EmailCodeMail;
use App\Models\Workspace;
use App\Services\Accounts\Invitations;
use App\Services\Accounts\SignUp;
use App\Services\CredentialVault;
use App\Services\SettingsRepository;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * The auth pages (v1.6.2): the Turnstile check on the sign-up page's first step, the official Google button, passkeys
 * offered from the email box (conditional mediation) with a Passkey button for the rest, "or" and the two side by
 * side, and every auth page in the same card.
 */
beforeEach(function () {
    Filament::setCurrentPanel('app');
    Mail::fake();
    app(SettingsRepository::class)->set('signup.mode', SignUp::ANYONE);
});

function withTurnstile(): void
{
    app(CredentialVault::class)->put('turnstile', 'site_key', '0x4AAAA-site');
    app(CredentialVault::class)->put('turnstile', 'secret_key', '0x4AAAA-secret');
}

describe('Turnstile', function () {
    it('shows the managed widget with the email on the sign-up page, with "Protected by Cloudflare", while its keys are saved', function () {
        withTurnstile();
        $html = (string) $this->get('/app/register')->assertOk()->getContent();

        expect($html)->toContain('data-turnstile')->toContain('0x4AAAA-site')->toContain("appearance: 'always'")->toContain('Protected by Cloudflare Turnstile')
            ->not->toContain('0x4AAAA-secret');
        // The sign-in page asks only a new address, at its own step.
        expect((string) $this->get('/app/login')->getContent())->not->toContain('data-turnstile');
    });

    it('is not there without its keys, or with the switch off', function () {
        expect((string) $this->get('/app/register')->getContent())->not->toContain('data-turnstile');

        withTurnstile();
        app(SettingsRepository::class)->set('signup.turnstile', false);
        expect((string) $this->get('/app/register')->getContent())->not->toContain('data-turnstile');
    });

    it('emails the code at once when the answer came with Continue, and checks it on the server (turnstile_failed)', function () {
        withTurnstile();
        Http::fake(['challenges.cloudflare.com/*' => Http::sequence()->push(['success' => false])->push(['success' => true])]);

        Livewire::test(Register::class)->assertSeeHtml('data-turnstile')
            ->fillForm(['email' => 'new.one@example.test'])->set('data.turnstile', 'bad-answer')->call('authenticate')
            ->assertNotSet('step', Login::STEP_CODE)->assertNotified();
        Mail::assertNotQueued(EmailCodeMail::class);

        Livewire::test(Register::class)->fillForm(['email' => 'new.two@example.test'])->set('data.turnstile', 'good-answer')->call('authenticate')
            ->assertSet('step', Login::STEP_CODE);
        Http::assertSentCount(2);
    });

    it('asks for the check when Continue came without an answer', function () {
        withTurnstile();

        Livewire::test(Register::class)->fillForm(['email' => 'new.one@example.test'])->call('authenticate')->assertSet('step', Login::STEP_HUMAN)
            ->assertSeeHtml('data-turnstile');
    });
});

describe('the other ways in', function () {
    it('offers passkeys from the email box: autocomplete "username webauthn" and a quiet conditional request, never a dialog on load', function () {
        $html = (string) $this->get('/app/login')->assertOk()->getContent();

        expect($html)->toContain('autocomplete="username webauthn"')->toContain("mediation: 'conditional'")->toContain('isConditionalMediationAvailable')
            ->toContain('data-passkey')->toContain('>Passkey<')->toContain('el-auth-btn-icon');
        // On load only the conditional request: the dialog waits for the button.
        expect($html)->toMatch('/async init\(\) \{\s*if \(! \(await window\.elPasskeys\.conditional\(\)\)\) return;/');
    });

    it('draws Continue with Google to Google\'s branding: the multicolour G, a white bordered button, the same height', function () {
        app(CredentialVault::class)->put('google', 'client_id', '1234-test');
        app(CredentialVault::class)->put('google', 'client_secret', 'secret');
        $html = (string) $this->get('/app/register')->assertOk()->getContent();

        expect($html)->toContain('class="el-auth-btn el-google-btn"')->toContain('Continue with Google')
            ->toContain('fill="#EA4335"')->toContain('fill="#4285F4"')->toContain('fill="#FBBC05"')->toContain('fill="#34A853"')
            ->toContain('el-other-ways-row')->toContain('role="separator" aria-label="or"');
        $css = (string) file_get_contents(resource_path('css/filament/admin/theme.css'));
        expect($css)->toContain('border-color: #747775;')->toContain('background-color: #131314;')->toMatch('/\.el-auth-btn \{[^}]*min-height: 40px;/');
    });

    it('leaves Google out until its client is saved', function () {
        expect((string) $this->get('/app/login')->getContent())->not->toContain('el-google-btn')->not->toContain('Continue with Google');
    });

    it('shows the other ways on the first step only', function () {
        Fx::user(['email' => 'farah@example.test', 'password' => 'a-long-password-1234']);

        Livewire::test(Login::class)->assertSeeHtml('data-other-ways')
            ->fillForm(['email' => 'farah@example.test'])->call('authenticate')->assertSet('step', Login::STEP_PASSWORD)->assertDontSeeHtml('data-other-ways');
    });
});

describe('every auth page', function () {
    it('renders in the same card', function () {
        $user = Fx::user(['email' => 'farah@example.test']);

        $this->get('/app/login')->assertOk()->assertSee('fi-simple-main', false);
        $this->get('/app/register')->assertOk()->assertSee('Create your free account');
        $this->get('/app/password-reset/request')->assertOk()->assertSee('fi-simple-main', false);
        $reset = Filament::getPanel('app')->getResetPasswordUrl(Password::broker('users')->createToken($user), $user);
        $this->get($reset)->assertOk()->assertSee('fi-simple-main', false);
        $this->get('/account/delete')->assertOk()->assertSee('class="account"', false)->assertSee('Delete your account');

        $owner = Fx::user();
        $invite = app(Invitations::class)->invite(Workspace::query()->findOrFail($owner->current_workspace_id), $owner, 'farah@example.test', WorkspaceRole::Member);
        $this->actingAs($user, 'web');
        $this->get($invite)->assertOk()->assertSee('class="account"', false);
        $device = '/device/sign-in?'.http_build_query(['code_challenge' => str_repeat('A', 43), 'code_challenge_method' => 'S256', 'state' => 's', 'redirect_uri' => 'echoledger://signed-in', 'name' => 'Work laptop', 'platform' => 'windows']);
        $this->get($device)->assertOk()->assertSee('class="account"', false)->assertSee('Sign in EchoLedger on Work laptop?');

        $css = (string) file_get_contents(resource_path('views/auth/form-styles.blade.php'));
        expect($css)->toContain('max-width: 440px')->toContain('min-height: 40px');
    });
});
