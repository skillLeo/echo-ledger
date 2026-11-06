<?php

declare(strict_types=1);

use App\Mail\LoginAlertMail;
use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\DeviceSignInCode;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Accounts\DeviceSignIn;
use App\Services\Workspaces\Workspaces;
use Illuminate\Hashing\HashManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

require_once __DIR__.'/../Accounts/Support/account_helpers.php';

beforeEach(function () {
    Mail::fake();
    $this->person = Fx::user(['email' => 'farah@example.test', 'password' => 'a-good-password-42']);
});

/** @return array<string, mixed> */
function passwordBody(array $overrides = []): array
{
    return array_merge(['email' => 'farah@example.test', 'password' => 'a-good-password-42', 'otp' => null, 'name' => 'S25 Ultra', 'platform' => 'android', 'app_version' => '1.5.0', 'os_version' => '16'], $overrides);
}

/** A verifier and its S256 challenge, as the apps make them. */
function pkcePair(): array
{
    $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');

    return [$verifier, DeviceSignIn::challengeOf($verifier)];
}

/** @return array<string, string> */
function browserQuery(string $challenge, array $overrides = []): array
{
    return array_merge(['code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'state' => 'st-'.bin2hex(random_bytes(6)), 'redirect_uri' => 'echoledger://signed-in', 'name' => 'Work laptop', 'platform' => 'windows'], $overrides);
}

/** Approves a browser sign-in as [$user] and returns [code, state]. */
function approveBrowserSignIn(object $test, User $user, string $challenge): array
{
    $query = browserQuery($challenge);
    $location = (string) $test->actingAs($user, 'web')->post('/device/sign-in', $query)->headers->get('Location');
    expect($location)->toStartWith('echoledger://signed-in?');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $answer);
    expect($answer['state'])->toBe($query['state']);

    return [$answer['code'], $answer['state']];
}

describe('POST /api/auth/password', function () {
    it('signs a device in exactly like pairing, in the person\'s current workspace, and tells the account', function () {
        $response = $this->postJson('/api/auth/password', passwordBody())->assertCreated()
            ->assertJsonStructure(['device' => ['id', 'name', 'platform', 'source_device', 'created_at'], 'token', 'token_expires_at', 'config' => ['api_version', 'device', 'plan', 'workspace']]);

        $device = unscoped(fn () => Device::query()->findOrFail($response->json('device.id')));
        expect($device->user_id)->toBe($this->person->id)
            ->and($device->workspace_id)->toBe($this->person->current_workspace_id)
            ->and($response->json('device.source_device'))->toBe('phone')
            ->and(PersonalAccessToken::findToken((string) $response->json('token'))?->abilities)->toBe(Device::ABILITIES);
        Mail::assertQueued(LoginAlertMail::class, fn (LoginAlertMail $mail): bool => $mail->hasTo('farah@example.test') && $mail->what === 'S25 Ultra, Android');
        expect(AuditEvent::query()->where('event', 'device_signed_in')->where('user_id', $this->person->id)->exists())->toBeTrue();

        $this->withToken((string) $response->json('token'))->getJson('/api/config')->assertOk()->assertJsonPath('device.id', $device->id);
    });

    it('gives one answer for a wrong email and a wrong password, and checks a password hash either way', function () {
        $hasher = new class(app()) extends HashManager
        {
            public int $checks = 0;

            public int $makes = 0;

            public function check(#[SensitiveParameter] $value, $hashedValue, array $options = []): bool
            {
                $this->checks++;

                return parent::check($value, $hashedValue, $options);
            }

            public function make(#[SensitiveParameter] $value, array $options = []): string
            {
                $this->makes++;

                return parent::make($value, $options);
            }
        };
        app()->instance('hash', $hasher);
        Hash::clearResolvedInstance('hash');

        $wrongEmail = $this->postJson('/api/auth/password', passwordBody(['email' => 'nobody@example.test']))->assertStatus(422);
        $wrongPassword = $this->postJson('/api/auth/password', passwordBody(['password' => 'not-the-password-1']))->assertStatus(422);

        expect($wrongEmail->json())->toBe(['message' => 'Wrong email or password.', 'code' => 'invalid_credentials'])
            ->and($wrongPassword->json())->toBe($wrongEmail->json());
        expect(unscoped(fn () => Device::query()->count()))->toBe(0)
            ->and($hasher->checks)->toBe(2)
            ->and($hasher->makes)->toBe(1);

        // The stand-in hash is made once per server, not once per request (PHP-FPM forgets statics between requests),
        // so a later wrong email costs one hash check, exactly like a wrong password.
        $hasher->checks = $hasher->makes = 0;
        $this->postJson('/api/auth/password', passwordBody(['email' => 'someone.else@example.test']))->assertStatus(422);
        expect($hasher->checks)->toBe(1)->and($hasher->makes)->toBe(0);

        // A change of the hashing cost makes it again at the new cost.
        config(['hashing.bcrypt.rounds' => 5]);
        app()->forgetInstance('hash');
        app()->instance('hash', $hasher = new $hasher(app()));
        Hash::clearResolvedInstance('hash');
        $this->postJson('/api/auth/password', passwordBody(['email' => 'third@example.test']))->assertStatus(422);
        expect($hasher->makes)->toBe(1)->and($hasher->checks)->toBe(1);
    });

    it('asks an account with two-step sign-in for its code, and takes a code or a recovery code once', function () {
        [$secret, $recovery] = accountsEnrolTotp($this->person);

        $this->postJson('/api/auth/password', passwordBody())->assertStatus(401)->assertJsonPath('code', 'otp_required');
        $this->postJson('/api/auth/password', passwordBody(['otp' => '000000']))->assertStatus(422)->assertJsonPath('code', 'invalid_otp');
        $this->postJson('/api/auth/password', passwordBody(['otp' => accountsTotp($this->person, $secret)]))->assertCreated();

        $this->postJson('/api/auth/password', passwordBody(['otp' => $recovery[0], 'name' => 'Spare phone']))->assertCreated();
        $this->postJson('/api/auth/password', passwordBody(['otp' => $recovery[0], 'name' => 'Third']))->assertStatus(422)->assertJsonPath('code', 'invalid_otp');
    });

    it('refuses an unconfirmed email, an account being deleted and a full plan', function () {
        $this->person->forceFill(['email_verified_at' => null])->save();
        $this->postJson('/api/auth/password', passwordBody())->assertForbidden()->assertJsonPath('code', 'email_not_verified');

        $this->person->forceFill(['email_verified_at' => now(), 'deletes_at' => now()->addDays(7)])->save();
        $this->postJson('/api/auth/password', passwordBody())->assertForbidden()->assertJsonPath('code', 'not_allowed');

        $this->person->forceFill(['deletes_at' => null])->save();
        Plan::personal()->update(['max_devices' => 1]);
        Device::factory()->create(['user_id' => $this->person->id]);
        $this->postJson('/api/auth/password', passwordBody())->assertForbidden()->assertJsonPath('code', 'device_limit_reached');
    });

    it('locks an email for 15 minutes after five failures, from any address (429 too_many_attempts)', function () {
        foreach (range(1, 5) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "198.51.100.{$i}"])->postJson('/api/auth/password', passwordBody(['password' => 'wrong-password-'.$i]))->assertStatus(422);
        }

        $locked = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])->postJson('/api/auth/password', passwordBody())->assertStatus(429);
        expect($locked->json('code'))->toBe('too_many_attempts')
            ->and((int) $locked->headers->get('Retry-After'))->toBeGreaterThan(60)->toBeLessThanOrEqual(900)
            ->and(AuditEvent::query()->where('event', 'sign_in_locked')->count())->toBe(1);

        $this->travel(16)->minutes();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])->postJson('/api/auth/password', passwordBody())->assertCreated();
    });

    it('allows 5 attempts a minute from one address (429 too_many_attempts)', function () {
        foreach (range(1, 5) as $i) {
            $this->postJson('/api/auth/password', passwordBody(['email' => "someone{$i}@example.test"]))->assertStatus(422);
        }

        $blocked = $this->postJson('/api/auth/password', passwordBody())->assertStatus(429);
        expect($blocked->json('code'))->toBe('too_many_attempts')->and((int) $blocked->headers->get('Retry-After'))->toBeGreaterThan(0);
    });

    it('validates the device fields', function () {
        $this->postJson('/api/auth/password', passwordBody(['platform' => 'toaster']))->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    });
});

describe('browser sign-in', function () {
    it('sends a guest to the console sign-in and back again', function () {
        [, $challenge] = pkcePair();
        $query = browserQuery($challenge);

        $this->get('/device/sign-in?'.http_build_query($query))->assertRedirect(route('filament.admin.auth.login'));
        $intended = (string) session('url.intended');
        parse_str((string) parse_url($intended, PHP_URL_QUERY), $kept);
        expect($intended)->toStartWith(url('/device/sign-in?'))->and($kept)->toEqual($query);
    });

    it('refuses any other redirect, method or a malformed challenge without sending the browser anywhere', function (array $overrides) {
        [, $challenge] = pkcePair();

        $this->actingAs($this->person, 'web')->get('/device/sign-in?'.http_build_query(browserQuery($challenge, $overrides)))
            ->assertStatus(400)->assertSee('not valid')->assertHeaderMissing('Location');
        $this->post('/device/sign-in', browserQuery($challenge, $overrides))->assertStatus(400);
        expect(DeviceSignInCode::query()->count())->toBe(0);
    })->with([
        'another redirect' => [['redirect_uri' => 'https://evil.example/cb']],
        'a look-alike redirect' => [['redirect_uri' => 'echoledger://signed-in.evil']],
        'plain method' => [['code_challenge_method' => 'plain']],
        'short challenge' => [['code_challenge' => 'abc']],
        'no state' => [['state' => '']],
        'unknown platform' => [['platform' => 'toaster']],
    ]);

    it('asks "Sign in EchoLedger on <name>?" and lets the form go on to the app', function () {
        [, $challenge] = pkcePair();

        $page = $this->actingAs($this->person, 'web')->get('/device/sign-in?'.http_build_query(browserQuery($challenge)))->assertOk()
            ->assertSee('Sign in EchoLedger on Work laptop?')->assertSee('farah@example.test');
        expect((string) $page->headers->get('Content-Security-Policy'))->toContain("form-action 'self' echoledger:")->toContain("frame-ancestors 'none'");
    });

    it('trades the code and its verifier for a device once', function () {
        [$verifier, $challenge] = pkcePair();
        [$code] = approveBrowserSignIn($this, $this->person, $challenge);
        expect(DeviceSignInCode::query()->where('code_hash', $code)->exists())->toBeFalse()
            ->and(DeviceSignInCode::query()->where('code_hash', hash('sha256', $code))->exists())->toBeTrue();
        app('auth')->forgetGuards();

        $body = ['code' => $code, 'code_verifier' => $verifier, 'name' => 'Work laptop', 'platform' => 'windows', 'app_version' => '1.5.0', 'os_version' => '11'];
        $first = $this->postJson('/api/auth/device/token', $body)->assertCreated()->assertJsonPath('device.source_device', 'desktop');
        expect(unscoped(fn () => Device::query()->findOrFail($first->json('device.id'))->workspace_id))->toBe($this->person->current_workspace_id);
        Mail::assertQueued(LoginAlertMail::class, fn (LoginAlertMail $mail): bool => $mail->what === 'Work laptop, Windows');

        $this->postJson('/api/auth/device/token', $body)->assertStatus(422)->assertExactJson(['message' => 'This sign-in code is wrong, used or expired. Start the sign-in again.', 'code' => 'invalid_code']);
    });

    it('refuses another verifier and then the right one too, an expired code and an unknown one', function () {
        [$verifier, $challenge] = pkcePair();
        [$other] = pkcePair();
        [$code] = approveBrowserSignIn($this, $this->person, $challenge);
        app('auth')->forgetGuards();
        $body = fn (string $c, string $v): array => ['code' => $c, 'code_verifier' => $v, 'name' => 'Work laptop', 'platform' => 'windows'];

        $this->postJson('/api/auth/device/token', $body($code, $other))->assertStatus(422)->assertJsonPath('code', 'invalid_code');
        $this->postJson('/api/auth/device/token', $body($code, $verifier))->assertStatus(422)->assertJsonPath('code', 'invalid_code');

        [$late] = approveBrowserSignIn($this, $this->person, $challenge);
        app('auth')->forgetGuards();
        $this->travel(DeviceSignIn::CODE_MINUTES * 60 + 1)->seconds();
        $this->postJson('/api/auth/device/token', $body($late, $verifier))->assertStatus(422)->assertJsonPath('code', 'invalid_code');
        $this->postJson('/api/auth/device/token', $body(str_repeat('x', 43), $verifier))->assertStatus(422)->assertJsonPath('code', 'invalid_code');
        expect(unscoped(fn () => Device::query()->count()))->toBe(0);
    });

    it('sends Cancel back to the app without a code', function () {
        [, $challenge] = pkcePair();
        $query = browserQuery($challenge);

        $location = (string) $this->actingAs($this->person, 'web')->post('/device/sign-in', [...$query, 'cancel' => '1'])->headers->get('Location');

        expect($location)->toBe('echoledger://signed-in?'.http_build_query(['error' => 'access_denied', 'state' => $query['state']]))
            ->and(DeviceSignInCode::query()->count())->toBe(0);
    });

    it('needs a signed-in, confirmed account to approve', function () {
        [, $challenge] = pkcePair();
        $this->post('/device/sign-in', browserQuery($challenge))->assertRedirect(route('filament.admin.auth.login'));

        $unconfirmed = User::factory()->unverified()->create();
        $this->actingAs($unconfirmed, 'web')->get('/device/sign-in?'.http_build_query(browserQuery($challenge)))->assertForbidden()->assertSee('Confirm your email');
        $this->post('/device/sign-in', browserQuery($challenge))->assertForbidden();
        expect(DeviceSignInCode::query()->count())->toBe(0);
    });

    it('refuses the code once the person left the workspace it was approved for', function () {
        $owner = Fx::user();
        $team = Workspace::query()->findOrFail($owner->current_workspace_id);
        app(Workspaces::class)->addMember($team, $this->person);
        app(Workspaces::class)->switchTo($this->person->fresh(), $team);
        [$verifier, $challenge] = pkcePair();
        [$code] = approveBrowserSignIn($this, $this->person->fresh(), $challenge);
        expect(DeviceSignInCode::query()->value('workspace_id'))->toBe($team->id);
        app(Workspaces::class)->removeMember($team, $this->person->fresh());
        app('auth')->forgetGuards();

        $this->postJson('/api/auth/device/token', ['code' => $code, 'code_verifier' => $verifier, 'name' => 'Laptop', 'platform' => 'macos'])->assertForbidden()->assertJsonPath('code', 'not_allowed');
    });

    it('throttles the approval page and the code exchange per address (429)', function () {
        foreach (range(1, 30) as $_) {
            $this->get('/device/sign-in');
        }
        $this->get('/device/sign-in')->assertStatus(429);

        foreach (range(1, 10) as $_) {
            $this->postJson('/api/auth/device/token', ['code' => 'x']);
        }
        $this->postJson('/api/auth/device/token', ['code' => 'x'])->assertStatus(429)->assertJsonPath('code', 'rate_limited')->assertHeader('Retry-After');
    });

    it('stores only the hash of the code and never logs it', function () {
        [, $challenge] = pkcePair();
        [$code] = approveBrowserSignIn($this, $this->person, $challenge);

        $dump = json_encode([DB::table('device_sign_in_codes')->get(), DB::table('audit_events')->get()]);
        expect($dump)->not->toContain($code);
    });
});
