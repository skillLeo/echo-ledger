<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Mail\EmailCodeMail;
use App\Mail\LoginAlertMail;
use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\EmailCode;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Accounts\EmailGate;
use App\Services\Accounts\Invitations;
use App\Services\Accounts\SignUp;
use App\Services\CredentialVault;
use App\Services\SettingsRepository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

require_once __DIR__.'/Support/account_helpers.php';

/*
| Sign-up and sign-in with an email code (since v1.6, P1, docs/API.md): the email first, a 6-digit code by email, the
| account or the new account's name and Terms. Mail is faked: the code is read from the queued email.
*/

beforeEach(function () {
    Mail::fake();
    Http::preventStrayRequests();
    app(SettingsRepository::class)->set('signup.mode', SignUp::ANYONE);
    $this->person = Fx::user(['email' => 'farah@example.test', 'password' => 'a-good-password-42']);
});

/** The code of the newest email sent to [$email]. */
function emailedCode(string $email): string
{
    $code = null;
    Mail::assertQueued(EmailCodeMail::class, function (EmailCodeMail $mail) use ($email, &$code): bool {
        if ($mail->hasTo($email)) {
            $code = $mail->code;
        }

        return true;
    });
    expect($code)->toMatch('/^\d{6}$/');

    return (string) $code;
}

/** @return array<string, mixed> */
function verifyBody(string $email, string $code, array $overrides = []): array
{
    return array_merge(['email' => $email, 'code' => $code, 'otp' => null, 'name' => 'S25 Ultra', 'platform' => 'android', 'app_version' => '1.6.0', 'os_version' => '16', 'install_id' => 'install-'.bin2hex(random_bytes(4))], $overrides);
}

/** A new address all the way to its sign-up token. */
function signUpTokenFor(object $test, string $email): string
{
    $test->postJson('/api/auth/code', ['email' => $email, 'purpose' => 'sign_up'])->assertStatus(202);

    return (string) $test->postJson('/api/auth/code/verify', verifyBody($email, emailedCode($email)))->assertOk()
        ->assertJsonPath('next', 'profile')->json('sign_up_token');
}

describe('POST /api/auth/start', function () {
    it('lists the ways in of an account and offers a code to a new address', function () {
        $this->postJson('/api/auth/start', ['email' => 'Farah@Example.test'])->assertOk()
            ->assertExactJson(['account' => 'existing', 'methods' => ['password', 'code'], 'sign_up' => true]);
        $this->person->forceFill(['has_password' => false])->save();
        $this->postJson('/api/auth/start', ['email' => 'farah@example.test'])->assertOk()->assertJsonPath('methods', ['code']);

        fakeGoogle();
        $this->postJson('/api/auth/start', ['email' => 'new.person@example.test'])->assertOk()
            ->assertExactJson(['account' => 'new', 'methods' => ['code', 'google'], 'sign_up' => true]);
        $this->postJson('/api/auth/start', ['email' => 'farah@example.test'])->assertOk()->assertJsonPath('methods', ['code', 'google']);
    });

    it('says sign-up is closed to a new address, and refuses throwaway and outside addresses', function () {
        app(SettingsRepository::class)->set('signup.mode', SignUp::NOBODY);
        $this->postJson('/api/auth/start', ['email' => 'new.person@example.test'])->assertForbidden()->assertJsonPath('code', 'sign_up_closed');
        // An account signs in whatever the sign-up mode.
        $this->postJson('/api/auth/start', ['email' => 'farah@example.test'])->assertOk()->assertJsonPath('account', 'existing');

        app(SettingsRepository::class)->set('signup.mode', SignUp::ANYONE);
        foreach (['someone@mailinator.com', 'someone@eu.yopmail.com', 'someone@10minutemail.com'] as $throwaway) {
            $this->postJson('/api/auth/start', ['email' => $throwaway])->assertStatus(422)->assertJsonPath('code', 'email_not_allowed');
        }
        app(SettingsRepository::class)->set('signup.allowed_domains', ['company.test']);
        $this->postJson('/api/auth/start', ['email' => 'someone@gmail.test'])->assertStatus(422)->assertJsonPath('code', 'email_not_allowed');
        $this->postJson('/api/auth/start', ['email' => 'someone@company.test'])->assertOk()->assertJsonPath('account', 'new');
        $this->postJson('/api/auth/start', ['email' => 'someone@sales.company.test'])->assertOk();
        $this->postJson('/api/auth/start', ['email' => 'not-an-email'])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    });

    it('keeps the disposable list as data, one domain a line', function () {
        $lines = file(resource_path(EmailGate::LIST_PATH), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $domains = array_values(array_filter($lines, fn (string $l): bool => ! str_starts_with($l, '#')));

        expect(count($domains))->toBeGreaterThan(200)
            ->and($domains)->each->toMatch('/^[a-z0-9.-]+\.[a-z]{2,}$/')
            ->and(count($domains))->toBe(count(array_unique($domains)))
            ->and(EmailGate::isDisposable('gmail.com'))->toBeFalse();
    });

    it('answers 10 questions a minute per IP address and 5 per address, then 429 with Retry-After', function () {
        foreach (range(1, 5) as $i) {
            $this->postJson('/api/auth/start', ['email' => 'farah@example.test'])->assertOk();
        }
        $this->postJson('/api/auth/start', ['email' => 'farah@example.test'])->assertStatus(429)->assertHeader('Retry-After');
        // The refused one is not counted: five more addresses make the IP address's ten.
        foreach (range(1, 5) as $i) {
            $this->postJson('/api/auth/start', ['email' => "p{$i}@example.test"])->assertOk();
        }
        $this->postJson('/api/auth/start', ['email' => 'p9@example.test'])->assertStatus(429)->assertHeader('Retry-After');
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])->postJson('/api/auth/start', ['email' => 'p9@example.test'])->assertOk();
    });
});

describe('POST /api/auth/code', function () {
    it('emails a 6-digit code kept only as a keyed hash, and a new one replaces the old one', function () {
        $answer = $this->postJson('/api/auth/code', ['email' => 'farah@example.test', 'purpose' => 'sign_in'])->assertStatus(202)
            ->assertExactJson(['resend_after' => 60, 'expires_in' => 600]);
        $first = emailedCode('farah@example.test');
        $row = EmailCode::query()->firstOrFail();
        expect($answer->getContent())->not->toContain($first)
            ->and($row->code_hash)->not->toContain($first)->toHaveLength(64)
            ->and($row->purpose)->toBe('sign_in')
            ->and($row->getAttributes())->not->toContain($first);

        $this->travel(61)->seconds();
        $this->postJson('/api/auth/code', ['email' => 'farah@example.test'])->assertStatus(202);
        $second = emailedCode('farah@example.test');
        if ($second !== $first) {
            $this->postJson('/api/auth/code/verify', verifyBody('farah@example.test', $first))->assertStatus(422);
        }
        $this->postJson('/api/auth/code/verify', verifyBody('farah@example.test', $second))->assertCreated();
    });

    it('waits 60 seconds between codes, sends 5 an hour per address and 20 per IP address', function () {
        $this->postJson('/api/auth/code', ['email' => 'farah@example.test'])->assertStatus(202);
        $this->postJson('/api/auth/code', ['email' => 'farah@example.test'])->assertStatus(429)
            ->assertJsonPath('code', 'resend_too_soon')->assertHeader('Retry-After');
        foreach (range(2, 5) as $i) {
            $this->travel(61)->seconds();
            $this->postJson('/api/auth/code', ['email' => 'farah@example.test'])->assertStatus(202);
        }
        $this->travel(61)->seconds();
        $this->postJson('/api/auth/code', ['email' => 'farah@example.test'])->assertStatus(429)->assertJsonPath('code', 'too_many_attempts')->assertHeader('Retry-After');

        // 20 an hour from one IP address, whatever the addresses (the route also takes 10 a minute).
        $this->travel(2)->hours();
        foreach (range(1, 20) as $i) {
            if ($i % 10 === 0) {
                $this->travel(61)->seconds();
            }
            $this->postJson('/api/auth/code', ['email' => "someone{$i}@example.test"])->assertStatus(202);
        }
        $this->travel(61)->seconds();
        $this->postJson('/api/auth/code', ['email' => 'someone21@example.test'])->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');
    });

    it('limits sign-ups per IP address a day (Settings → Sign-up)', function () {
        app(SettingsRepository::class)->set('signup.per_ip_day', 2);
        foreach (['one@example.test', 'two@example.test'] as $email) {
            $token = signUpTokenFor($this, $email);
            $this->postJson('/api/auth/sign-up', ['sign_up_token' => $token, 'name' => 'A', 'accept_terms' => true, 'platform' => 'android'])->assertCreated();
        }
        $this->postJson('/api/auth/code', ['email' => 'three@example.test'])->assertStatus(429)->assertJsonPath('code', 'sign_up_limit')->assertHeader('Retry-After');
        // An account still gets its code.
        $this->postJson('/api/auth/code', ['email' => 'farah@example.test'])->assertStatus(202);
        $this->travel(25)->hours();
        $this->postJson('/api/auth/code', ['email' => 'three@example.test'])->assertStatus(202);
    });

    it('checks the Turnstile answer when one is sent and Turnstile is set; the apps send none', function () {
        app(CredentialVault::class)->put('turnstile', 'site_key', '0x4AAAA-site');
        app(CredentialVault::class)->put('turnstile', 'secret_key', '0x4AAAA-secret');
        Http::fake(['challenges.cloudflare.com/*' => Http::sequence()->push(['success' => false])->push(['success' => true])]);

        $this->postJson('/api/auth/code', ['email' => 'new.one@example.test', 'turnstile' => 'bad'])->assertStatus(422)->assertJsonPath('code', 'turnstile_failed');
        $this->postJson('/api/auth/code', ['email' => 'new.one@example.test', 'turnstile' => 'good'])->assertStatus(202);
        $this->postJson('/api/auth/code', ['email' => 'new.two@example.test'])->assertStatus(202);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
            && $request['secret'] === '0x4AAAA-secret' && $request['response'] === 'good');
        Http::assertSentCount(2);
    });

    it('refuses a new address while sign-up is closed', function () {
        app(SettingsRepository::class)->set('signup.mode', SignUp::NOBODY);
        $this->postJson('/api/auth/code', ['email' => 'new.one@example.test'])->assertForbidden()->assertJsonPath('code', 'sign_up_closed');
        Mail::assertNotQueued(EmailCodeMail::class);
    });
});

describe('POST /api/auth/code/verify', function () {
    it('signs an account in with the right code, as a password sign-in does', function () {
        $this->postJson('/api/auth/code', ['email' => 'farah@example.test'])->assertStatus(202);
        $answer = $this->postJson('/api/auth/code/verify', verifyBody('farah@example.test', emailedCode('farah@example.test')))->assertCreated()
            ->assertJsonStructure(['device' => ['id', 'name', 'platform'], 'token', 'token_expires_at', 'config']);

        $device = Device::query()->findOrFail($answer->json('device.id'));
        expect($device->user_id)->toBe($this->person->id)
            ->and(AuditEvent::query()->where('event', 'device_signed_in')->where('user_id', $this->person->id)->exists())->toBeTrue();
        Mail::assertQueued(LoginAlertMail::class, fn (LoginAlertMail $m): bool => $m->hasTo('farah@example.test'));
        $this->withToken($answer->json('token'))->getJson('/api/account')->assertOk()->assertJsonPath('email', 'farah@example.test');
        // Used up.
        $this->postJson('/api/auth/code/verify', verifyBody('farah@example.test', emailedCode('farah@example.test')))->assertStatus(422)->assertJsonPath('code', 'code_expired');
    });

    it('counts wrong codes down from 5, then the code has expired; after 10 minutes too', function () {
        $this->postJson('/api/auth/code', ['email' => 'farah@example.test'])->assertStatus(202);
        $right = emailedCode('farah@example.test');
        $wrong = $right === '000000' ? '111111' : '000000';
        foreach ([4, 3, 2, 1, 0] as $left) {
            $this->postJson('/api/auth/code/verify', verifyBody('farah@example.test', $wrong))->assertStatus(422)
                ->assertJsonPath('code', 'invalid_code')->assertJsonPath('attempts_left', $left);
        }
        $this->postJson('/api/auth/code/verify', verifyBody('farah@example.test', $right))->assertStatus(422)->assertJsonPath('code', 'code_expired');

        $this->travel(61)->seconds();
        $this->postJson('/api/auth/code', ['email' => 'farah@example.test'])->assertStatus(202);
        $code = emailedCode('farah@example.test');
        $this->travel(11)->minutes();
        $this->postJson('/api/auth/code/verify', verifyBody('farah@example.test', $code))->assertStatus(422)->assertJsonPath('code', 'code_expired');
    });

    it('asks a two-step account for its authenticator code, the same email code staying good', function () {
        [$secret] = accountsEnrolTotp($this->person);
        $this->postJson('/api/auth/code', ['email' => 'farah@example.test'])->assertStatus(202);
        $code = emailedCode('farah@example.test');

        $this->postJson('/api/auth/code/verify', verifyBody('farah@example.test', $code))->assertStatus(401)->assertJsonPath('code', 'otp_required');
        $this->postJson('/api/auth/code/verify', verifyBody('farah@example.test', $code, ['otp' => '000000']))->assertStatus(422)->assertJsonPath('code', 'invalid_otp');
        $this->postJson('/api/auth/code/verify', verifyBody('farah@example.test', $code, ['otp' => accountsTotp($this->person, $secret)]))->assertCreated();
    });

    it('confirms an unconfirmed address with the code, and refuses an account being deleted', function () {
        $this->person->forceFill(['email_verified_at' => null])->save();
        $this->postJson('/api/auth/code', ['email' => 'farah@example.test'])->assertStatus(202);
        $this->postJson('/api/auth/code/verify', verifyBody('farah@example.test', emailedCode('farah@example.test')))->assertCreated();
        expect($this->person->fresh()->hasVerifiedEmail())->toBeTrue();

        $this->person->forceFill(['deletes_at' => now()->addDays(7)])->save();
        $this->travel(61)->seconds();
        $this->postJson('/api/auth/code', ['email' => 'farah@example.test'])->assertStatus(202);
        $this->postJson('/api/auth/code/verify', verifyBody('farah@example.test', emailedCode('farah@example.test')))->assertForbidden()->assertJsonPath('code', 'not_allowed');
    });

    it('gives a new address a one-time sign-up token, not an account', function () {
        $token = signUpTokenFor($this, 'new.person@example.test');

        expect($token)->toHaveLength(48)
            ->and(User::query()->where('email', 'new.person@example.test')->exists())->toBeFalse()
            ->and(EmailCode::query()->where('email', 'new.person@example.test')->value('sign_up_token_hash'))->toBe(hash('sha256', $token));
    });
});

describe('POST /api/auth/sign-up', function () {
    it('makes the confirmed account on the default plan with its own workspace and signs the device in', function () {
        $token = signUpTokenFor($this, 'new.person@example.test');
        $answer = $this->postJson('/api/auth/sign-up', ['sign_up_token' => $token, 'name' => 'Nida Khan', 'accept_terms' => true, 'device_name' => 'Nida phone',
            'platform' => 'android', 'app_version' => '1.6.0', 'os_version' => '16', 'install_id' => 'nida-install-1'])->assertCreated()
            ->assertJsonPath('device.name', 'Nida phone');

        $user = User::query()->where('email', 'new.person@example.test')->firstOrFail();
        $workspace = Workspace::query()->findOrFail($user->current_workspace_id);
        expect($user->name)->toBe('Nida Khan')
            ->and($user->hasVerifiedEmail())->toBeTrue()
            ->and($user->has_password)->toBeFalse()
            ->and($user->terms_accepted_at)->not->toBeNull()
            ->and($user->is_super_admin)->toBeFalse()
            ->and($workspace->plan->key)->toBe(Plan::FREE)
            ->and(Device::query()->findOrFail($answer->json('device.id'))->user_id)->toBe($user->id)
            ->and(AuditEvent::query()->where('event', 'account_created')->where('user_id', $user->id)->value('summary'))->toBe('Account made with an email code');
        $this->postJson('/api/auth/start', ['email' => 'new.person@example.test'])->assertJsonPath('methods', ['code']);

        // One use.
        $this->postJson('/api/auth/sign-up', ['sign_up_token' => $token, 'name' => 'Again', 'accept_terms' => true, 'platform' => 'android'])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_code');
    });

    it('names the device after its platform when the app sends no device name', function () {
        $token = signUpTokenFor($this, 'pc.person@example.test');
        $this->postJson('/api/auth/sign-up', ['sign_up_token' => $token, 'name' => 'PC Person', 'accept_terms' => true, 'platform' => 'windows', 'os_version' => 'build 26100'])
            ->assertCreated()->assertJsonPath('device.name', 'Windows 11 PC');
    });

    it('needs the Terms accepted and a fresh token', function () {
        $token = signUpTokenFor($this, 'new.person@example.test');
        foreach ([false, null, 'no'] as $accepted) {
            $this->postJson('/api/auth/sign-up', ['sign_up_token' => $token, 'name' => 'Nida', 'accept_terms' => $accepted, 'platform' => 'android'])
                ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        }
        $this->postJson('/api/auth/sign-up', ['sign_up_token' => 'not-a-token', 'name' => 'Nida', 'accept_terms' => true, 'platform' => 'android'])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_code');
        $this->travel(16)->minutes();
        $this->postJson('/api/auth/sign-up', ['sign_up_token' => $token, 'name' => 'Nida', 'accept_terms' => true, 'platform' => 'android'])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_code');
        expect(User::query()->where('email', 'new.person@example.test')->exists())->toBeFalse();
    });

    it('joins the workspace an invitation for the address waits in: the code proved it', function () {
        app(SettingsRepository::class)->set('signup.mode', SignUp::INVITED);
        $owner = Fx::user();
        $team = Workspace::query()->findOrFail($owner->current_workspace_id);
        app(Invitations::class)->invite($team, $owner, 'guest@example.test', WorkspaceRole::Member);

        $this->postJson('/api/auth/code', ['email' => 'stranger@example.test'])->assertForbidden()->assertJsonPath('code', 'sign_up_closed');
        $token = signUpTokenFor($this, 'guest@example.test');
        $this->postJson('/api/auth/sign-up', ['sign_up_token' => $token, 'name' => 'Guest', 'accept_terms' => true, 'platform' => 'android'])->assertCreated();
        expect($team->roleOf(User::query()->where('email', 'guest@example.test')->firstOrFail()))->toBe(WorkspaceRole::Member);
    });

    it('throttles each sign-in route per IP address', function (string $uri, array $body, int $perMinute) {
        foreach (range(1, $perMinute) as $i) {
            expect($this->postJson($uri, $body)->status())->not->toBe(429);
        }
        $this->postJson($uri, $body)->assertStatus(429)->assertHeader('Retry-After');
    })->with([
        'code' => ['/api/auth/code', ['email' => 'x'], 10],
        'verify' => ['/api/auth/code/verify', ['email' => 'x'], 10],
        'sign-up' => ['/api/auth/sign-up', ['name' => 'x'], 10],
    ]);
});
