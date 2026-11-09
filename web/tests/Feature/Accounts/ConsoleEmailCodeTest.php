<?php

declare(strict_types=1);

use App\Filament\Auth\Login;
use App\Filament\Auth\Register;
use App\Mail\EmailCodeMail;
use App\Models\User;
use App\Services\Accounts\SignUp;
use App\Services\CredentialVault;
use App\Services\SettingsRepository;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

require_once __DIR__.'/Support/account_helpers.php';

/*
| The console's own sign-in (since v1.6, P1): "Email → Continue", then the password or "Email me a code", the two-step
| code when the account has one; Turnstile before a new address gets its code when the keys are set.
*/

beforeEach(function () {
    Filament::setCurrentPanel('app');
    Mail::fake();
    Http::preventStrayRequests();
    $this->person = Fx::user(['email' => 'farah@example.test', 'password' => 'a-good-password-42']);
});

function consoleCode(string $email): string
{
    $code = null;
    Mail::assertQueued(EmailCodeMail::class, function (EmailCodeMail $mail) use ($email, &$code): bool {
        if ($mail->hasTo($email)) {
            $code = $mail->code;
        }

        return true;
    });

    return (string) $code;
}

it('signs an account in with "Email me a code" instead of the password', function () {
    $page = Livewire::test(Login::class)->fillForm(['email' => 'farah@example.test'])->call('authenticate')->assertSet('step', Login::STEP_PASSWORD)
        ->call('emailCode')->assertSet('step', Login::STEP_CODE)->assertSee('Check your email');

    $page->fillForm(['code' => '000000'])->call('authenticate')->assertHasFormErrors(['code'])->assertSet('step', Login::STEP_CODE);
    $this->assertGuest('web');
    $page->fillForm(['code' => consoleCode('farah@example.test')])->call('authenticate')->assertHasNoFormErrors();
    $this->assertAuthenticatedAs($this->person, 'web');
});

it('goes straight to the code for an account without a password of its own', function () {
    $this->person->forceFill(['has_password' => false])->save();
    Livewire::test(Login::class)->fillForm(['email' => 'farah@example.test'])->call('authenticate')->assertSet('step', Login::STEP_CODE);
    expect(consoleCode('farah@example.test'))->toMatch('/^\d{6}$/');
});

it('asks for the two-step code after the email code, and counts a wrong one as a failed sign-in', function () {
    [$secret] = accountsEnrolTotp($this->person);
    $page = Livewire::test(Login::class)->fillForm(['email' => 'farah@example.test'])->call('authenticate')->call('emailCode');
    $page->fillForm(['code' => consoleCode('farah@example.test')])->call('authenticate')->assertSet('step', Login::STEP_OTP);
    $this->assertGuest('web');

    $page->fillForm(['otp' => '000000'])->call('authenticate')->assertHasFormErrors(['otp']);
    $this->assertGuest('web');
    $page->fillForm(['otp' => accountsTotp($this->person, $secret)])->call('authenticate')->assertHasNoFormErrors();
    $this->assertAuthenticatedAs($this->person, 'web');
});

it('starts over with another email', function () {
    Livewire::test(Login::class)->fillForm(['email' => 'farah@example.test'])->call('authenticate')->assertSet('step', Login::STEP_PASSWORD)
        ->call('startOver')->assertSet('step', Login::STEP_EMAIL)->assertSet('confirmedEmail', null);
});

it('keeps the steps out of the browser\'s hands', function () {
    Livewire::test(Login::class)->set('step', Login::STEP_PROFILE);
})->throws(CannotUpdateLockedPropertyException::class);

it('asks a new address for the Turnstile check before emailing a code when Turnstile is set', function () {
    app(SettingsRepository::class)->set('signup.mode', SignUp::ANYONE);
    app(CredentialVault::class)->put('turnstile', 'site_key', '0x4AAAA-site');
    app(CredentialVault::class)->put('turnstile', 'secret_key', '0x4AAAA-secret');
    Http::fake(['challenges.cloudflare.com/*' => Http::sequence()->push(['success' => false])->push(['success' => true])]);

    expect((string) $this->get('/app/register')->headers->get('Content-Security-Policy'))->toContain('https://challenges.cloudflare.com');
    $page = Livewire::test(Register::class)->fillForm(['email' => 'new.one@example.test'])->call('authenticate')
        ->assertSet('step', Login::STEP_HUMAN)->assertSeeHtml('0x4AAAA-site')->assertDontSeeHtml('0x4AAAA-secret');
    $page->call('authenticate')->assertSet('step', Login::STEP_HUMAN)->assertNotified();
    $page->set('data.turnstile', 'bad-answer')->call('authenticate')->assertSet('step', Login::STEP_HUMAN);
    Mail::assertNotQueued(EmailCodeMail::class);
    $page->set('data.turnstile', 'good-answer')->call('authenticate')->assertSet('step', Login::STEP_CODE);
    expect(consoleCode('new.one@example.test'))->toMatch('/^\d{6}$/');
    // An account signs in without the check.
    Livewire::test(Login::class)->fillForm(['email' => 'farah@example.test'])->call('authenticate')->call('emailCode')->assertSet('step', Login::STEP_CODE);
    Http::assertSentCount(2);
});

it('skips Turnstile without its keys, and the console policy loads nothing from another origin then', function () {
    app(SettingsRepository::class)->set('signup.mode', SignUp::ANYONE);
    expect((string) $this->get('/app/register')->headers->get('Content-Security-Policy'))->not->toContain('cloudflare');
    Livewire::test(Register::class)->fillForm(['email' => 'new.one@example.test'])->call('authenticate')->assertSet('step', Login::STEP_CODE);
});

it('refuses a throwaway address on the sign-up page', function () {
    app(SettingsRepository::class)->set('signup.mode', SignUp::ANYONE);
    Livewire::test(Register::class)->fillForm(['email' => 'someone@mailinator.com'])->call('authenticate')->assertHasFormErrors(['email']);
    expect(User::query()->where('email', 'someone@mailinator.com')->exists())->toBeFalse();
});
