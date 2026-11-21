<?php

declare(strict_types=1);

use App\Filament\Auth\EditProfile;
use App\Mail\EmailCodeMail;
use App\Mail\SecurityChangeMail;
use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\Passkey;
use App\Models\User;
use App\Services\AuditLog;
use App\Services\Passkeys;
use App\Services\Workspaces\Workspaces;
use Filament\Facades\Filament;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;
use Tests\Feature\Support\SoftAuthenticator;

uses(InteractsWithDeviceApi::class);

require_once __DIR__.'/../Accounts/Support/account_helpers.php';

/* Account and security from the apps (2.0.0-l): sign-in methods, browsers, devices, two-step and passkeys. */

beforeEach(function () {
    Filament::setCurrentPanel('app');
    Mail::fake();
});

/** A console browser of the user in the database session store; returns the raw session id. */
function secApiBrowser(User $user, string $agent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/120.0 Safari/537.36'): string
{
    $id = bin2hex(random_bytes(20));
    DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'ip_address' => '203.0.113.7', 'user_agent' => $agent, 'payload' => '', 'last_activity' => now()->timestamp]);

    return $id;
}

/** Asks for an email proof code and returns the one that was mailed. */
function secApiProofCode(object $test, string $token): string
{
    $test->asToken($token)->postJson('/api/account/proof-code')->assertStatus(202);

    return (string) Mail::queued(EmailCodeMail::class)->last()->code;
}

/** A passkey row that needs no ceremony. */
function secApiPasskey(User $user, string $name = 'Pixel'): Passkey
{
    return Passkey::query()->create(['user_id' => $user->id, 'name' => $name, 'credential_id' => SoftAuthenticator::b64(random_bytes(16)), 'credential' => '{}']);
}

/** The phone's ceremony: options, then a software authenticator's answer from [$origin]. */
function secApiAddPasskey(object $test, string $token, ?string $origin = null, string $name = 'My phone', array $proof = ['password' => 'password']): TestResponse
{
    $options = $test->asToken($token)->postJson('/api/account/passkeys/options', $proof)->assertOk()->json();
    $answer = (new SoftAuthenticator)->create($options['options'], $origin ?? Passkeys::androidOrigins()[0]);

    return $test->asToken($token)->postJson('/api/account/passkeys', ['ceremony' => $options['ceremony'], 'name' => $name, 'credential' => $answer]);
}

function secApiAudited(string $event, int $userId): bool
{
    return AuditEvent::query()->where('event', $event)->where('user_id', $userId)->exists();
}

describe('GET /api/account/security', function () {
    it('lists sign-in methods, browsers, devices of every workspace and the latest events', function () {
        config(['session.driver' => 'database']);
        $user = Fx::user();
        $user->forceFill(['google_id' => 'google-sub-9'])->save();
        accountsEnrolTotp($user);
        $passkey = secApiPasskey($user, 'Laptop');
        $rawSession = secApiBrowser($user);
        [$device, $token] = $this->enroll($user->fresh(), ['config:read']);

        $team = app(Workspaces::class)->createFor($user, 'Team B');
        $other = Device::factory()->create(['user_id' => $user->id, 'workspace_id' => $team->id, 'name' => 'Team laptop']);
        $recent = Device::factory()->create(['user_id' => $user->id]);
        $recent->forceFill(['signed_out_at' => now()->subDays(5)])->save();
        $old = Device::factory()->create(['user_id' => $user->id]);
        $old->forceFill(['signed_out_at' => now()->subDays(31)])->save();
        foreach (range(1, 55) as $_) {
            app(AuditLog::class)->record('login', $user->id);
        }

        $json = $this->asToken($token)->getJson('/api/account/security')->assertOk()->json();

        expect($json['password'])->toBeTrue()
            ->and($json['google'])->toBe(['linked' => true, 'email' => null])
            ->and($json['passkeys'])->toBe([['id' => $passkey->id, 'name' => 'Laptop', 'created_at' => $passkey->created_at->toAtomString(), 'last_used_at' => null]])
            ->and($json['two_factor'])->toBe(['enabled' => true, 'recovery_codes_left' => 2]);

        expect($json['browsers'])->toHaveCount(1)
            ->and($json['browsers'][0]['id'])->toMatch('/^[0-9a-f]{32}$/')->not->toBe($rawSession)
            ->and($json['browsers'][0])->toMatchArray(['agent' => 'Chrome on macOS', 'current' => false, 'country' => null])
            ->and($json['browsers'][0]['last_active_at'])->toBeString();

        $devices = collect($json['devices'])->keyBy('id');
        expect($devices->keys()->sort()->values()->all())->toBe(collect([$device->id, $other->id, $recent->id])->sort()->values()->all())
            ->and($devices[$other->id]['workspace'])->toBe(['id' => $team->id, 'name' => 'Team B'])
            ->and($devices[$device->id]['workspace'])->toBe(['id' => $device->workspace_id, 'name' => 'Personal'])
            ->and($devices[$device->id])->toMatchArray(['current' => true, 'status' => 'active', 'signed_out_at' => null])
            ->and($devices[$recent->id])->toMatchArray(['current' => false, 'status' => 'signed_out', 'signed_out_at' => $recent->fresh()->signed_out_at->toAtomString()])
            ->and(array_keys($devices[$other->id]))->toEqualCanonicalizing(['id', 'name', 'platform', 'app_version', 'last_seen_at', 'paired_at', 'current', 'status', 'signed_out_at', 'workspace']);

        $latest = AuditEvent::query()->where('user_id', $user->id)->latest('id')->limit(50)->pluck('id')->all();
        expect($json['events'])->toHaveCount(50)
            ->and(array_column($json['events'], 'id'))->toBe($latest)
            ->and(array_keys($json['events'][0]))->toBe(['id', 'event', 'label', 'summary', 'actor', 'device', 'at'])
            ->and($json['events'][0]['event'])->toBe('login');

        // The same shape as GET /api/security.
        $security = $this->asToken($token)->getJson('/api/security')->assertOk()->json('events');
        expect(array_keys($security[0]))->toBe(array_keys($json['events'][0]));
    });

    it('answers an account without a password, Google, passkeys or two-step', function () {
        $user = Fx::user();
        $user->forceFill(['has_password' => false])->save();
        [, $token] = $this->enroll($user, ['config:read']);

        $this->asToken($token)->getJson('/api/account/security')->assertOk()
            ->assertJsonPath('password', false)
            ->assertJsonPath('google', ['linked' => false, 'email' => null])
            ->assertJsonPath('passkeys', [])
            ->assertJsonPath('two_factor', ['enabled' => false, 'recovery_codes_left' => 0])
            ->assertJsonPath('browsers', []);
    });

    it('refuses every change to a token that may only read (403 missing_ability)', function () {
        $user = Fx::user();
        [$device, $token] = $this->enroll($user, ['config:read']);
        $passkey = secApiPasskey($user);

        $calls = [
            ['patchJson', "/api/account/devices/{$device->id}", ['name' => 'X']],
            ['deleteJson', "/api/account/devices/{$device->id}", []],
            ['deleteJson', '/api/account/browsers/'.str_repeat('a', 32), []],
            ['deleteJson', '/api/account/google', ['password' => 'password']],
            ['postJson', '/api/account/passkeys/options', []],
            ['postJson', '/api/account/passkeys', []],
            ['patchJson', "/api/account/passkeys/{$passkey->id}", ['name' => 'X']],
            ['deleteJson', "/api/account/passkeys/{$passkey->id}", ['password' => 'password']],
            ['postJson', '/api/account/two-factor', []],
            ['postJson', '/api/account/two-factor/confirm', []],
            ['postJson', '/api/account/two-factor/recovery-codes', ['password' => 'password']],
            ['deleteJson', '/api/account/two-factor', []],
            ['postJson', '/api/account/proof-code', []],
        ];
        foreach ($calls as [$method, $uri, $body]) {
            $this->asToken($token)->{$method}($uri, $body)->assertStatus(403)->assertJsonPath('code', 'missing_ability');
        }
        expect(Passkey::query()->count())->toBe(1);
    });
});

describe('devices', function () {
    it('renames a device in another workspace of the same person', function () {
        $user = Fx::user();
        [$device, $token] = $this->enroll($user);
        $team = app(Workspaces::class)->createFor($user, 'Team B');
        $other = Device::factory()->create(['user_id' => $user->id, 'workspace_id' => $team->id]);

        $this->asToken($token)->patchJson("/api/account/devices/{$other->id}", ['name' => '  Studio Mac  '])->assertOk()
            ->assertJsonPath('id', $other->id)->assertJsonPath('name', 'Studio Mac')->assertJsonPath('current', false);
        expect(Device::query()->withoutGlobalScopes()->find($other->id)->name)->toBe('Studio Mac')
            ->and(AuditEvent::query()->where('event', 'device_renamed')->where('subject_id', $other->id)->exists())->toBeTrue();

        $this->asToken($token)->patchJson("/api/account/devices/{$device->id}", ['name' => ''])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->asToken($token)->patchJson("/api/account/devices/{$device->id}", ['name' => str_repeat('a', 61)])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    });

    it('answers 404 device_not_found for another person\'s device', function () {
        [, $token] = $this->enroll(Fx::user());
        $stranger = Device::factory()->create(['user_id' => Fx::user()->id, 'name' => 'Theirs']);

        $this->asToken($token)->patchJson("/api/account/devices/{$stranger->id}", ['name' => 'Mine now'])->assertStatus(404)->assertJsonPath('code', 'device_not_found');
        $this->asToken($token)->deleteJson("/api/account/devices/{$stranger->id}")->assertStatus(404)->assertJsonPath('code', 'device_not_found');
        $this->asToken($token)->deleteJson('/api/account/devices/does-not-exist')->assertStatus(404)->assertJsonPath('code', 'device_not_found');

        $fresh = Device::query()->withoutGlobalScopes()->find($stranger->id);
        expect($fresh->name)->toBe('Theirs')->and($fresh->signed_out_at)->toBeNull();
    });

    it('signs another device out, keeps its row, and refuses the calling device (409 current_device)', function () {
        $user = Fx::user();
        [$device, $token] = $this->enroll($user);
        $team = app(Workspaces::class)->createFor($user, 'Team B');
        [$other, $otherToken] = $this->enroll($user);
        $other->forceFill(['workspace_id' => $team->id])->save();

        $this->asToken($token)->deleteJson("/api/account/devices/{$other->id}")->assertNoContent();
        $row = Device::query()->withoutGlobalScopes()->find($other->id);
        expect($row)->not->toBeNull()->and($row->signed_out_at)->not->toBeNull();
        $this->asToken($otherToken)->getJson('/api/account/security')->assertUnauthorized();

        $listed = collect($this->asToken($token)->getJson('/api/account/security')->json('devices'))->firstWhere('id', $other->id);
        expect($listed['status'])->toBe('signed_out');

        $this->asToken($token)->deleteJson("/api/account/devices/{$device->id}")->assertStatus(409)->assertJsonPath('code', 'current_device');
        expect($device->fresh()->signed_out_at)->toBeNull();
    });
});

describe('browsers', function () {
    beforeEach(fn () => config(['session.driver' => 'database']));

    it('signs a console browser out by its opaque id', function () {
        $user = Fx::user();
        $raw = secApiBrowser($user);
        $keep = secApiBrowser($user, 'Mozilla/5.0 (Windows NT 10.0) Gecko/20100101 Firefox/130.0');
        [, $token] = $this->enroll($user);

        $browsers = collect($this->asToken($token)->getJson('/api/account/security')->json('browsers'));
        $id = $browsers->firstWhere('agent', 'Chrome on macOS')['id'];

        // The raw session id is not an id the API knows.
        $this->asToken($token)->deleteJson("/api/account/browsers/{$raw}")->assertStatus(404)->assertJsonPath('code', 'browser_not_found');
        $this->asToken($token)->deleteJson("/api/account/browsers/{$id}")->assertNoContent();

        expect(DB::table('sessions')->where('id', $raw)->exists())->toBeFalse()
            ->and(DB::table('sessions')->where('id', $keep)->exists())->toBeTrue()
            ->and(secApiAudited('web_session_revoked', $user->id))->toBeTrue();
        $this->asToken($token)->deleteJson("/api/account/browsers/{$id}")->assertStatus(404)->assertJsonPath('code', 'browser_not_found');
    });

    it('answers 404 browser_not_found for an unknown id or another person\'s browser', function () {
        $user = Fx::user();
        [, $token] = $this->enroll($user);
        $stranger = Fx::user();
        $theirs = secApiBrowser($stranger);
        [, $strangerToken] = $this->enroll($stranger);
        $theirId = $this->asToken($strangerToken)->getJson('/api/account/security')->json('browsers.0.id');

        $this->asToken($token)->deleteJson('/api/account/browsers/'.str_repeat('0', 32))->assertStatus(404)->assertJsonPath('code', 'browser_not_found');
        $this->asToken($token)->deleteJson("/api/account/browsers/{$theirId}")->assertStatus(404)->assertJsonPath('code', 'browser_not_found');
        expect(DB::table('sessions')->where('id', $theirs)->exists())->toBeTrue();
    });
});

describe('DELETE /api/account/google', function () {
    it('needs proof, then unlinks Google', function () {
        $user = Fx::user();
        $user->forceFill(['google_id' => 'google-sub-1'])->save();
        [, $token] = $this->enroll($user);

        $this->asToken($token)->deleteJson('/api/account/google')->assertStatus(422)->assertJsonPath('code', 'proof_required')
            ->assertJsonPath('accepts', ['password']);
        $this->asToken($token)->deleteJson('/api/account/google', ['password' => 'not-the-password'])->assertStatus(422)->assertJsonPath('code', 'wrong_password');
        expect($user->fresh()->google_id)->toBe('google-sub-1');

        $this->asToken($token)->deleteJson('/api/account/google', ['password' => 'password'])->assertNoContent();
        expect($user->fresh()->google_id)->toBeNull()->and(secApiAudited('google_unlinked', $user->id))->toBeTrue();
    });

    it('takes an authenticator code as proof', function () {
        $user = Fx::user();
        $user->forceFill(['google_id' => 'google-sub-2'])->save();
        [$secret] = accountsEnrolTotp($user);
        [, $token] = $this->enroll($user->fresh());

        $this->asToken($token)->deleteJson('/api/account/google', ['otp' => '000000'])->assertStatus(422)->assertJsonPath('code', 'invalid_otp');
        $this->asToken($token)->deleteJson('/api/account/google', ['otp' => accountsTotp($user, $secret)])->assertNoContent();
        expect($user->fresh()->google_id)->toBeNull();
    });

    it('refuses Google as the last way to sign in (409 last_sign_in_method), proved with an email code', function () {
        $user = Fx::user(['email' => 'googler@example.test']);
        $user->forceFill(['google_id' => 'google-sub-3', 'has_password' => false])->save();
        [, $token] = $this->enroll($user->fresh());

        $this->asToken($token)->deleteJson('/api/account/google')->assertStatus(422)->assertJsonPath('code', 'proof_required')->assertJsonPath('accepts', ['email_code']);
        $code = secApiProofCode($this, $token);
        Mail::assertQueued(EmailCodeMail::class, fn (EmailCodeMail $mail): bool => $mail->hasTo('googler@example.test') && preg_match('/^\d{6}$/', $mail->code) === 1);

        $this->asToken($token)->deleteJson('/api/account/google', ['email_code' => $code])->assertStatus(409)->assertJsonPath('code', 'last_sign_in_method');
        expect($user->fresh()->google_id)->toBe('google-sub-3');

        // With a passkey there is another way in.
        secApiPasskey($user);
        $this->asToken($token)->deleteJson('/api/account/google', ['email_code' => secApiProofCode($this, $token)])->assertNoContent();
        expect($user->fresh()->google_id)->toBeNull();
    });
});

describe('passkeys', function () {
    it('answers the creation options of a ceremony for the console host', function () {
        $user = Fx::user();
        $existing = secApiPasskey($user);
        [, $token] = $this->enroll($user);

        $json = $this->asToken($token)->postJson('/api/account/passkeys/options', ['password' => 'password'])->assertOk()->json();
        expect(array_keys($json))->toBe(['ceremony', 'options'])
            ->and($json['ceremony'])->toBeString()->not->toBe('')
            ->and($json['options']['rp'])->toBe(['id' => 'echoledger.test', 'name' => 'EchoLedger'])
            ->and(SoftAuthenticator::unb64($json['options']['user']['id']))->toBe(Passkeys::userHandle($user))
            ->and($json['options']['timeout'])->toBe(300000)
            ->and($json['options']['authenticatorSelection'])->toMatchArray(['residentKey' => 'required', 'userVerification' => 'required'])
            ->and(array_column($json['options']['excludeCredentials'], 'id'))->toBe([$existing->credential_id]);
    });

    it('adds a passkey made on the phone (Android origin)', function () {
        $user = Fx::user();
        [, $token] = $this->enroll($user);

        $json = secApiAddPasskey($this, $token, null, 'Galaxy S25')->assertCreated()->json();
        $passkey = Passkey::query()->sole();
        expect($json)->toBe(['id' => $passkey->id, 'name' => 'Galaxy S25', 'created_at' => $passkey->created_at->toAtomString()])
            ->and($passkey->user_id)->toBe($user->id)
            ->and(secApiAudited('passkey_added', $user->id))->toBeTrue();
    });

    it('accepts the console origin too and refuses any other', function () {
        [, $token] = $this->enroll(Fx::user());

        secApiAddPasskey($this, $token, Passkeys::origin())->assertCreated();
        secApiAddPasskey($this, $token, 'https://evil.example')->assertStatus(422)->assertJsonPath('code', 'invalid_passkey');
        secApiAddPasskey($this, $token, 'android:apk-key-hash:'.SoftAuthenticator::b64(random_bytes(32)))->assertStatus(422)->assertJsonPath('code', 'invalid_passkey');
        expect(Passkey::query()->count())->toBe(1);
    });

    it('uses a ceremony once', function () {
        [, $token] = $this->enroll(Fx::user());
        $options = $this->asToken($token)->postJson('/api/account/passkeys/options', ['password' => 'password'])->assertOk()->json();
        $answer = (new SoftAuthenticator)->create($options['options'], Passkeys::androidOrigins()[0]);
        $body = ['ceremony' => $options['ceremony'], 'name' => 'Phone', 'credential' => $answer];

        $this->asToken($token)->postJson('/api/account/passkeys', $body)->assertCreated();
        $this->asToken($token)->postJson('/api/account/passkeys', $body)->assertStatus(422)->assertJsonPath('code', 'invalid_passkey');
        $this->asToken($token)->postJson('/api/account/passkeys', [...$body, 'ceremony' => 'made-up'])->assertStatus(422)->assertJsonPath('code', 'invalid_passkey');
        expect(Passkey::query()->count())->toBe(1);
    });

    it('refuses another person\'s ceremony', function () {
        [, $token] = $this->enroll(Fx::user());
        [, $strangerToken] = $this->enroll(Fx::user());
        $options = $this->asToken($strangerToken)->postJson('/api/account/passkeys/options', ['password' => 'password'])->assertOk()->json();
        $answer = (new SoftAuthenticator)->create($options['options'], Passkeys::androidOrigins()[0]);

        $this->asToken($token)->postJson('/api/account/passkeys', ['ceremony' => $options['ceremony'], 'credential' => $answer])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_passkey');
        expect(Passkey::query()->count())->toBe(0);
    });

    it('stops at 10 passkeys (409 too_many_passkeys)', function () {
        $user = Fx::user();
        [, $token] = $this->enroll($user);
        foreach (range(1, 10) as $i) {
            secApiPasskey($user, 'Key '.$i);
        }

        $this->asToken($token)->postJson('/api/account/passkeys/options', ['password' => 'password'])->assertStatus(409)->assertJsonPath('code', 'too_many_passkeys');
    });

    it('is off without the Android app\'s signing key (409 passkeys_unavailable)', function () {
        config(['echoledger.android_app.fingerprints' => []]);
        [, $token] = $this->enroll(Fx::user());

        $this->asToken($token)->getJson('/api/account')->assertOk()->assertJsonPath('passkeys_available', false);
        $this->asToken($token)->postJson('/api/account/passkeys/options', ['password' => 'password'])->assertStatus(409)->assertJsonPath('code', 'passkeys_unavailable');
        $this->asToken($token)->postJson('/api/account/passkeys', ['ceremony' => 'c', 'credential' => ['id' => 'x']])->assertStatus(409)->assertJsonPath('code', 'passkeys_unavailable');
    });

    it('renames one of the person\'s passkeys, not another person\'s', function () {
        $user = Fx::user();
        $mine = secApiPasskey($user, 'Old');
        $theirs = secApiPasskey(Fx::user(), 'Theirs');
        [, $token] = $this->enroll($user);

        $this->asToken($token)->patchJson("/api/account/passkeys/{$mine->id}", ['name' => ' Work phone '])->assertOk()
            ->assertExactJson(['id' => $mine->id, 'name' => 'Work phone', 'created_at' => $mine->created_at->toAtomString(), 'last_used_at' => null]);
        expect($mine->fresh()->name)->toBe('Work phone')->and(secApiAudited('passkey_renamed', $user->id))->toBeTrue();

        $this->asToken($token)->patchJson("/api/account/passkeys/{$theirs->id}", ['name' => 'Mine'])->assertStatus(404)->assertJsonPath('code', 'passkey_not_found');
        $this->asToken($token)->patchJson("/api/account/passkeys/{$mine->id}", ['name' => ''])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        expect($theirs->fresh()->name)->toBe('Theirs');
    });

    it('removes a passkey with proof', function () {
        $user = Fx::user();
        $passkey = secApiPasskey($user);
        $theirs = secApiPasskey(Fx::user());
        [, $token] = $this->enroll($user);

        $this->asToken($token)->deleteJson("/api/account/passkeys/{$theirs->id}", ['password' => 'password'])->assertStatus(404)->assertJsonPath('code', 'passkey_not_found');
        $this->asToken($token)->deleteJson("/api/account/passkeys/{$passkey->id}")->assertStatus(422)->assertJsonPath('code', 'proof_required');
        $this->asToken($token)->deleteJson("/api/account/passkeys/{$passkey->id}", ['password' => 'wrong-one'])->assertStatus(422)->assertJsonPath('code', 'wrong_password');
        $this->asToken($token)->deleteJson("/api/account/passkeys/{$passkey->id}", ['password' => 'password'])->assertNoContent();

        expect(Passkey::query()->whereKey($passkey->id)->exists())->toBeFalse()
            ->and(Passkey::query()->whereKey($theirs->id)->exists())->toBeTrue()
            ->and(secApiAudited('passkey_removed', $user->id))->toBeTrue();
    });

    it('keeps the only passkey of an account with no password and no Google (409 last_sign_in_method)', function () {
        $user = Fx::user();
        $user->forceFill(['has_password' => false])->save();
        $passkey = secApiPasskey($user);
        [, $token] = $this->enroll($user->fresh());

        $this->asToken($token)->deleteJson("/api/account/passkeys/{$passkey->id}", ['email_code' => secApiProofCode($this, $token)])
            ->assertStatus(409)->assertJsonPath('code', 'last_sign_in_method');
        expect(Passkey::query()->whereKey($passkey->id)->exists())->toBeTrue();

        // A second passkey is another way in.
        secApiPasskey($user, 'Spare');
        $this->asToken($token)->deleteJson("/api/account/passkeys/{$passkey->id}", ['email_code' => secApiProofCode($this, $token)])->assertNoContent();
    });

    it('limits the options to 10 an hour (429 rate_limited)', function () {
        [, $token] = $this->enroll(Fx::user());
        foreach (range(1, 10) as $_) {
            $this->asToken($token)->postJson('/api/account/passkeys/options', ['password' => 'password'])->assertOk();
        }
        $this->asToken($token)->postJson('/api/account/passkeys/options', ['password' => 'password'])->assertStatus(429)->assertJsonPath('code', 'rate_limited')->assertHeader('Retry-After');
    });
});

describe('two-step', function () {
    it('begins with a secret that is not saved until a code confirms it', function () {
        $user = Fx::user(['email' => 'farah@example.test']);
        [, $token] = $this->enroll($user);

        $json = $this->asToken($token)->postJson('/api/account/two-factor', ['password' => 'password'])->assertOk()->json();
        expect(array_keys($json))->toBe(['ceremony', 'secret', 'otpauth_uri', 'issuer', 'label', 'digits', 'period', 'algorithm'])
            ->and($json['secret'])->toMatch('/^[A-Z2-7]{16,}$/')
            ->and($json['otpauth_uri'])->toStartWith('otpauth://totp/')->toContain('secret='.$json['secret'])
            ->and($json)->toMatchArray(['issuer' => 'EchoLedger', 'label' => 'f…@example.test', 'digits' => 6, 'period' => 30, 'algorithm' => 'SHA1'])
            ->and($user->fresh()->hasTwoFactor())->toBeFalse();

        $this->asToken($token)->postJson('/api/account/two-factor/confirm', ['ceremony' => $json['ceremony'], 'code' => '000000'])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_otp');
        $this->asToken($token)->postJson('/api/account/two-factor/confirm', ['ceremony' => 'made-up', 'code' => app(Google2FA::class)->getCurrentOtp($json['secret'])])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_otp');
        expect($user->fresh()->hasTwoFactor())->toBeFalse();

        $codes = $this->asToken($token)->postJson('/api/account/two-factor/confirm', ['ceremony' => $json['ceremony'], 'code' => app(Google2FA::class)->getCurrentOtp($json['secret'])])
            ->assertOk()->json('recovery_codes');
        expect($codes)->toHaveCount(8)->each->toBeString()
            ->and($user->fresh()->hasTwoFactor())->toBeTrue()
            ->and($user->fresh()->app_authentication_secret)->toBe($json['secret'])
            ->and(secApiAudited('mfa_enabled', $user->id))->toBeTrue();
        $this->asToken($token)->getJson('/api/account/security')->assertJsonPath('two_factor', ['enabled' => true, 'recovery_codes_left' => 8]);

        $this->asToken($token)->postJson('/api/account/two-factor', ['password' => 'password'])->assertStatus(409)->assertJsonPath('code', 'already_enabled');
    });

    it('ends a ceremony after 5 wrong codes', function () {
        $user = Fx::user();
        [, $token] = $this->enroll($user);
        $json = $this->asToken($token)->postJson('/api/account/two-factor', ['password' => 'password'])->assertOk()->json();
        foreach (range(1, 5) as $_) {
            $this->asToken($token)->postJson('/api/account/two-factor/confirm', ['ceremony' => $json['ceremony'], 'code' => '000000'])->assertStatus(422);
        }

        $this->asToken($token)->postJson('/api/account/two-factor/confirm', ['ceremony' => $json['ceremony'], 'code' => app(Google2FA::class)->getCurrentOtp($json['secret'])])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_otp');
        expect($user->fresh()->hasTwoFactor())->toBeFalse();
    });

    it('limits beginning to 10 an hour (429 rate_limited)', function () {
        [, $token] = $this->enroll(Fx::user());
        foreach (range(1, 10) as $_) {
            $this->asToken($token)->postJson('/api/account/two-factor', ['password' => 'password'])->assertOk();
        }
        $this->asToken($token)->postJson('/api/account/two-factor', ['password' => 'password'])->assertStatus(429)->assertJsonPath('code', 'rate_limited')->assertHeader('Retry-After');
    });

    it('makes new recovery codes with proof; the old ones stop working', function () {
        $user = Fx::user();
        [, $old] = accountsEnrolTotp($user);
        [, $token] = $this->enroll($user->fresh());

        $this->asToken($token)->postJson('/api/account/two-factor/recovery-codes')->assertStatus(422)->assertJsonPath('code', 'proof_required');
        $codes = $this->asToken($token)->postJson('/api/account/two-factor/recovery-codes', ['otp' => $old[1]])->assertOk()->json('recovery_codes');
        expect($codes)->toHaveCount(8)->and(array_intersect($codes, $old))->toBe([])
            ->and(secApiAudited('recovery_codes_regenerated', $user->id))->toBeTrue();

        $this->asToken($token)->postJson('/api/account/two-factor/recovery-codes', ['otp' => $old[0]])->assertStatus(422)->assertJsonPath('code', 'invalid_otp');
        $this->asToken($token)->postJson('/api/account/two-factor/recovery-codes', ['otp' => $codes[0]])->assertOk();
    });

    it('answers 409 not_enabled for new recovery codes without two-step', function () {
        [, $token] = $this->enroll(Fx::user());

        $this->asToken($token)->postJson('/api/account/two-factor/recovery-codes', ['password' => 'password'])->assertStatus(409)->assertJsonPath('code', 'not_enabled');
    });

    it('turns off only with an authenticator or recovery code, never a password or email code', function () {
        $user = Fx::user();
        [$secret] = accountsEnrolTotp($user);
        [, $token] = $this->enroll($user->fresh());

        $this->asToken($token)->deleteJson('/api/account/two-factor', ['password' => 'password'])->assertStatus(422)->assertJsonPath('code', 'invalid_otp');
        $this->asToken($token)->deleteJson('/api/account/two-factor', ['email_code' => '123456'])->assertStatus(422)->assertJsonPath('code', 'invalid_otp');
        $this->asToken($token)->deleteJson('/api/account/two-factor', ['password' => 'password', 'email_code' => '123456'])->assertStatus(422)->assertJsonPath('code', 'invalid_otp');
        expect($user->fresh()->hasTwoFactor())->toBeTrue();

        $this->asToken($token)->deleteJson('/api/account/two-factor', ['otp' => accountsTotp($user, $secret)])->assertNoContent();
        expect($user->fresh()->hasTwoFactor())->toBeFalse()
            ->and($user->fresh()->getAppAuthenticationRecoveryCodes())->toBeNull()
            ->and(secApiAudited('mfa_disabled', $user->id))->toBeTrue();
    });

    it('turns off with a recovery code', function () {
        $user = Fx::user();
        [, $codes] = accountsEnrolTotp($user);
        [, $token] = $this->enroll($user->fresh());

        $this->asToken($token)->deleteJson('/api/account/two-factor', ['otp' => $codes[1]])->assertNoContent();
        expect($user->fresh()->hasTwoFactor())->toBeFalse();
    });

    it('stays on for staff (409 required_for_staff)', function () {
        $staff = Fx::superAdmin();
        [, $token] = $this->enroll($staff);

        $this->asToken($token)->deleteJson('/api/account/two-factor', ['otp' => app(Google2FA::class)->getCurrentOtp(Fx::STAFF_TOTP_SECRET)])
            ->assertStatus(409)->assertJsonPath('code', 'required_for_staff');
        expect($staff->fresh()->hasTwoFactor())->toBeTrue();
    });
});

describe('proof', function () {
    it('locks after 5 wrong proofs in 15 minutes across routes and devices of the person (429 too_many_attempts)', function () {
        $user = Fx::user();
        $user->forceFill(['google_id' => 'google-sub-4'])->save();
        accountsEnrolTotp($user);
        $passkey = secApiPasskey($user);
        [, $phone] = $this->enroll($user->fresh());
        [, $laptop] = $this->enroll($user->fresh());
        $recovery = accountsEnrolTotp($user)[1];

        $this->asToken($phone)->deleteJson('/api/account/google', ['password' => 'wrong-1'])->assertStatus(422);
        $this->asToken($laptop)->deleteJson("/api/account/passkeys/{$passkey->id}", ['password' => 'wrong-2'])->assertStatus(422);
        $this->asToken($phone)->postJson('/api/account/two-factor/recovery-codes', ['otp' => '000000'])->assertStatus(422);
        $this->asToken($laptop)->deleteJson('/api/account/two-factor', ['otp' => '111111'])->assertStatus(422);
        $this->asToken($phone)->deleteJson('/api/account/google', ['password' => 'wrong-5'])->assertStatus(422);

        $this->asToken($laptop)->deleteJson('/api/account/google', ['password' => 'password'])
            ->assertStatus(429)->assertJsonPath('code', 'too_many_attempts')->assertHeader('Retry-After');
        expect($user->fresh()->google_id)->toBe('google-sub-4');

        $this->travel(16)->minutes();
        $this->asToken($laptop)->deleteJson('/api/account/google', ['password' => 'password', 'otp' => $recovery[0]])->assertNoContent();
    });

    it('refuses an email code from an account that has a password (422 invalid_code)', function () {
        $user = Fx::user();
        $user->forceFill(['google_id' => 'google-sub-5'])->save();
        [, $token] = $this->enroll($user);

        $this->asToken($token)->deleteJson('/api/account/google', ['email_code' => '123456'])->assertStatus(422)->assertJsonPath('code', 'invalid_code');
        expect($user->fresh()->google_id)->toBe('google-sub-5');
    });

    it('sends a proof code only to an account with nothing else to prove itself with', function () {
        [, $withPassword] = $this->enroll(Fx::user());
        $this->asToken($withPassword)->postJson('/api/account/proof-code')->assertStatus(409)->assertJsonPath('code', 'proof_code_not_needed');

        $totp = Fx::user();
        $totp->forceFill(['has_password' => false])->save();
        accountsEnrolTotp($totp);
        [, $withTotp] = $this->enroll($totp->fresh());
        $this->asToken($withTotp)->postJson('/api/account/proof-code')->assertStatus(409)->assertJsonPath('code', 'proof_code_not_needed');
        Mail::assertNothingQueued();

        $google = Fx::user();
        $google->forceFill(['has_password' => false])->save();
        [, $token] = $this->enroll($google->fresh());
        $this->asToken($token)->postJson('/api/account/proof-code')->assertStatus(202);
        Mail::assertQueued(EmailCodeMail::class, 1);
    });

    it('takes an email code once, and not a wrong one', function () {
        $user = Fx::user();
        $user->forceFill(['has_password' => false, 'google_id' => 'google-sub-6'])->save();
        secApiPasskey($user);
        [, $token] = $this->enroll($user->fresh());
        $code = secApiProofCode($this, $token);
        $wrong = $code === '000000' ? '000001' : '000000';

        $this->asToken($token)->deleteJson('/api/account/google', ['email_code' => $wrong])->assertStatus(422)->assertJsonPath('code', 'invalid_code');
        $this->asToken($token)->deleteJson('/api/account/google', ['email_code' => $code])->assertNoContent();
        $user->forceFill(['google_id' => 'google-sub-6'])->save();
        $this->asToken($token)->deleteJson('/api/account/google', ['email_code' => $code])->assertStatus(422)->assertJsonPath('code', 'invalid_code');
    });

    it('sends at most 5 proof codes an hour per person, whichever device asks', function () {
        $user = Fx::user();
        $user->forceFill(['has_password' => false])->save();
        [, $phone] = $this->enroll($user->fresh());
        [, $laptop] = $this->enroll($user->fresh());

        foreach (range(1, 5) as $i) {
            $this->asToken($i % 2 === 0 ? $laptop : $phone)->postJson('/api/account/proof-code')->assertStatus(202);
        }
        $this->asToken($laptop)->postJson('/api/account/proof-code')->assertStatus(429)->assertJsonPath('code', 'rate_limited')->assertHeader('Retry-After');
        $this->asToken($phone)->postJson('/api/account/proof-code')->assertStatus(429);

        // Another person has their own allowance.
        $other = Fx::user();
        $other->forceFill(['has_password' => false])->save();
        [, $otherToken] = $this->enroll($other->fresh());
        $this->asToken($otherToken)->postJson('/api/account/proof-code')->assertStatus(202);
    });

    it('shares the two-step and passkey limits between the person\'s devices', function () {
        $user = Fx::user();
        [, $phone] = $this->enroll($user);
        [, $laptop] = $this->enroll($user);

        foreach (range(1, 10) as $i) {
            $this->asToken($i <= 5 ? $phone : $laptop)->postJson('/api/account/two-factor', ['password' => 'password'])->assertOk();
            $this->asToken($i <= 5 ? $laptop : $phone)->postJson('/api/account/passkeys/options', ['password' => 'password'])->assertOk();
        }
        $this->asToken($phone)->postJson('/api/account/two-factor', ['password' => 'password'])->assertStatus(429)->assertJsonPath('code', 'rate_limited');
        $this->asToken($laptop)->postJson('/api/account/passkeys/options', ['password' => 'password'])->assertStatus(429)->assertJsonPath('code', 'rate_limited');
    });
});

describe('logs', function () {
    it('never write secrets, otpauth URIs, recovery codes or codes, nor do audit entries', function () {
        $user = Fx::user();
        [, $token] = $this->enroll($user);
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged): void {
            $logged[] = $e->message.' '.json_encode($e->context);
        });

        $begin = $this->asToken($token)->postJson('/api/account/two-factor', ['password' => 'password'])->assertOk()->json();
        $wrong = '000000';
        $this->asToken($token)->postJson('/api/account/two-factor/confirm', ['ceremony' => $begin['ceremony'], 'code' => $wrong])->assertStatus(422);
        $totp = app(Google2FA::class)->getCurrentOtp($begin['secret']);
        $first = $this->asToken($token)->postJson('/api/account/two-factor/confirm', ['ceremony' => $begin['ceremony'], 'code' => $totp])->assertOk()->json('recovery_codes');
        $second = $this->asToken($token)->postJson('/api/account/two-factor/recovery-codes', ['otp' => $first[0]])->assertOk()->json('recovery_codes');
        $third = $this->asToken($token)->postJson('/api/account/two-factor/recovery-codes', ['otp' => $second[0]])->assertOk()->json('recovery_codes');
        secApiAddPasskey($this, $token, null, 'My phone', ['otp' => $third[0]])->assertCreated();
        // A refused passkey answer is reported; that report must not carry a secret either.
        secApiAddPasskey($this, $token, 'https://evil.example', 'My phone', ['otp' => $third[1]])->assertStatus(422);

        $secrets = [$begin['secret'], $begin['otpauth_uri'], urlencode($begin['otpauth_uri']), $totp, ...$first, ...$second, ...$third];
        $audits = AuditEvent::query()->where('user_id', $user->id)->get()->map(fn (AuditEvent $a): string => $a->summary.' '.json_encode($a->meta))->all();
        expect($audits)->not->toBe([]);
        foreach ([...$logged, ...$audits] as $line) {
            foreach ($secrets as $secret) {
                expect(str_contains($line, $secret))->toBeFalse("Found a secret in: {$line}");
            }
        }
    });
});

describe('adding a way in needs proof, and the account holder hears of it (security review of 2.0.0-l)', function () {
    it('refuses to begin two-step or a passkey ceremony without proof (422), so a device alone cannot add a way in', function () {
        $user = Fx::user();
        [, $token] = $this->enroll($user);

        $this->asToken($token)->postJson('/api/account/two-factor')->assertStatus(422)->assertJsonPath('code', 'proof_required');
        $this->asToken($token)->postJson('/api/account/passkeys/options')->assertStatus(422)->assertJsonPath('code', 'proof_required');
        $this->asToken($token)->postJson('/api/account/two-factor', ['password' => 'wrong-password-1'])->assertStatus(422)->assertJsonPath('code', 'wrong_password');
        $this->asToken($token)->postJson('/api/account/passkeys/options', ['password' => 'wrong-password-1'])->assertStatus(422)->assertJsonPath('code', 'wrong_password');

        // A made-up ceremony is no way around the proof.
        $this->asToken($token)->postJson('/api/account/two-factor/confirm', ['ceremony' => str_repeat('a', 40), 'code' => '123456'])->assertStatus(422)->assertJsonPath('code', 'invalid_otp');
        $this->asToken($token)->postJson('/api/account/passkeys', ['ceremony' => str_repeat('a', 40), 'credential' => ['id' => 'x']])->assertStatus(422)->assertJsonPath('code', 'invalid_passkey');
        expect($user->fresh()->hasTwoFactor())->toBeFalse()->and(Passkey::query()->where('user_id', $user->id)->count())->toBe(0);
    });

    it('counts wrong proofs at both starts toward the 5 tries, then locks (429 too_many_attempts)', function () {
        $user = Fx::user();
        [, $token] = $this->enroll($user);

        foreach (range(1, 5) as $i) {
            $route = $i % 2 === 0 ? '/api/account/two-factor' : '/api/account/passkeys/options';
            $this->asToken($token)->postJson($route, ['password' => "wrong-password-{$i}"])->assertStatus(422)->assertJsonPath('code', 'wrong_password');
        }

        $locked = $this->asToken($token)->postJson('/api/account/two-factor', ['password' => 'password'])->assertStatus(429);
        expect($locked->json('code'))->toBe('too_many_attempts')->and((int) $locked->headers->get('Retry-After'))->toBeGreaterThan(0);
        $this->asToken($token)->postJson('/api/account/passkeys/options', ['password' => 'password'])->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');
    });

    it('lets an account made with Google begin two-step with an emailed code', function () {
        $user = Fx::user();
        $user->forceFill(['has_password' => false, 'google_id' => 'google-sub-9'])->save();
        [, $token] = $this->enroll($user);

        $this->asToken($token)->postJson('/api/account/two-factor', ['password' => 'password'])->assertStatus(422)->assertJsonPath('code', 'wrong_password');
        $this->asToken($token)->postJson('/api/account/two-factor', ['email_code' => secApiProofCode($this, $token)])->assertOk()->assertJsonStructure(['ceremony', 'secret']);
    });

    it('emails the account holder when two-step is turned on from an app, never the secret', function () {
        $user = Fx::user(['email' => 'farah@example.test']);
        [$device, $token] = $this->enroll($user);
        $begin = $this->asToken($token)->postJson('/api/account/two-factor', ['password' => 'password'])->assertOk()->json();
        Mail::assertNotQueued(SecurityChangeMail::class);

        $codes = $this->asToken($token)->postJson('/api/account/two-factor/confirm', ['ceremony' => $begin['ceremony'], 'code' => app(Google2FA::class)->getCurrentOtp($begin['secret'])])
            ->assertOk()->json('recovery_codes');

        Mail::assertQueued(SecurityChangeMail::class, function (SecurityChangeMail $mail) use ($device, $begin, $codes): bool {
            $html = $mail->render();

            return $mail->hasTo('farah@example.test') && $mail->change === SecurityChangeMail::TWO_FACTOR_ON && str_contains(html_entity_decode($html, ENT_QUOTES | ENT_HTML5), $device->readableName())
                && ! str_contains($html, $begin['secret']) && ! str_contains($html, $codes[0]);
        });
    });

    it('emails the account holder when a passkey is added from an app', function () {
        $user = Fx::user(['email' => 'farah@example.test']);
        [, $token] = $this->enroll($user);

        secApiAddPasskey($this, $token, null, 'Work phone')->assertCreated();

        Mail::assertQueued(SecurityChangeMail::class, fn (SecurityChangeMail $mail): bool => $mail->hasTo('farah@example.test')
            && $mail->change === SecurityChangeMail::PASSKEY_ADDED && $mail->passkey === 'Work phone' && str_contains($mail->render(), 'Work phone'));
    });

    it('never lets another person use a ceremony someone else proved (isolation)', function () {
        $bob = Fx::user();
        [, $bobToken] = $this->enroll($bob);
        $alice = Fx::user();
        [, $aliceToken] = $this->enroll($alice);
        $begin = $this->asToken($bobToken)->postJson('/api/account/two-factor', ['password' => 'password'])->assertOk()->json();
        $options = $this->asToken($bobToken)->postJson('/api/account/passkeys/options', ['password' => 'password'])->assertOk()->json();

        $this->asToken($aliceToken)->postJson('/api/account/two-factor/confirm', ['ceremony' => $begin['ceremony'], 'code' => app(Google2FA::class)->getCurrentOtp($begin['secret'])])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_otp');
        $this->asToken($aliceToken)->postJson('/api/account/passkeys', ['ceremony' => $options['ceremony'], 'credential' => (new SoftAuthenticator)->create($options['options'], Passkeys::androidOrigins()[0])])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_passkey');

        expect($alice->fresh()->hasTwoFactor())->toBeFalse()->and($bob->fresh()->hasTwoFactor())->toBeFalse()
            ->and(Passkey::query()->count())->toBe(0);
        Mail::assertNotQueued(SecurityChangeMail::class);
    });
});

describe('names in the security emails (security review of 2.0.0-l)', function () {
    it('shows a crafted device name as plain text in the alert, never a link, emphasis or HTML', function () {
        $user = Fx::user(['email' => 'farah@example.test']);
        [$device, $token] = $this->enroll($user);
        $crafted = "[click](https://evil.example) **now** <a href=\"https://evil.example\">x</a> ![i](https://evil.example/i.png)\n# Heading \u{202E}txt";
        $device->forceFill(['name' => $crafted, 'name_chosen' => true])->save();

        secApiAddPasskey($this, $token, null, 'Work phone')->assertCreated();

        Mail::assertQueued(SecurityChangeMail::class, function (SecurityChangeMail $mail): bool {
            $html = $mail->render();
            $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // The name may read as text, but nothing in it is a link, an image, emphasis, a heading or HTML.
            $dom = new DOMDocument;
            @$dom->loadHTML($html);
            $links = array_map(fn (DOMElement $a): string => $a->getAttribute('href'), iterator_to_array($dom->getElementsByTagName('a')));
            $images = array_map(fn (DOMElement $i): string => $i->getAttribute('src'), iterator_to_array($dom->getElementsByTagName('img')));
            expect(array_filter([...$links, ...$images], fn (string $url): bool => str_contains($url, 'evil')))->toBe([])
                ->and($links)->toContain(EditProfile::settingsUrl('security'))
                ->and($html)->not->toContain('<img src="https://evil')->not->toContain('<strong>now</strong>')->not->toContain('<h1>Heading')
                ->and($html)->not->toContain("\u{202E}")
                ->and($text)->toContain('[click](https://evil.example) **now**');

            return true;
        });
    });

    it('validates passkey names as account names, so none can carry link syntax or bidi controls', function () {
        $user = Fx::user();
        [, $token] = $this->enroll($user);

        secApiAddPasskey($this, $token, null, '[click](https://evil.example)')->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')->assertJsonPath('error_codes.name', [['code' => 'invalid_characters']]);
        secApiAddPasskey($this, $token, null, "Phone\u{202E}")->assertStatus(422)->assertJsonPath('error_codes.name', [['code' => 'invalid_characters']]);
        secApiAddPasskey($this, $token, null, str_repeat('a', 81))->assertStatus(422)->assertJsonPath('error_codes.name', [['code' => 'too_long', 'max' => 80]]);
        expect(Passkey::query()->count())->toBe(0);

        $id = secApiAddPasskey($this, $token, null, '  Pixel 8 (work)  ')->assertCreated()->assertJsonPath('name', 'Pixel 8 (work)')->json('id');
        $this->asToken($token)->patchJson("/api/account/passkeys/{$id}", ['name' => '**bold** <b>'])->assertStatus(422)->assertJsonPath('error_codes.name', [['code' => 'invalid_characters']]);
        $this->asToken($token)->patchJson("/api/account/passkeys/{$id}", ['name' => 'فون'])->assertOk()->assertJsonPath('name', 'فون');
        Mail::assertQueued(SecurityChangeMail::class, fn (SecurityChangeMail $m): bool => str_contains(html_entity_decode($m->render()), 'Pixel 8 (work)'));
    });
});

describe('with two-step on, the password alone is never proof (security review of 2.0.0-l)', function () {
    it('refuses a password-only proof on recovery codes, email change, password change, passkey add and two-step off', function () {
        $user = Fx::user(['email' => 'farah@example.test']);
        [$secret, $recovery] = accountsEnrolTotp($user);
        [, $token] = $this->enroll($user->fresh());
        $password = ['password' => 'password'];

        foreach ([
            ['postJson', '/api/account/two-factor/recovery-codes', $password],
            ['postJson', '/api/account/email', [...$password, 'email' => 'new@example.test']],
            ['putJson', '/api/account/password', ['current' => 'password', 'new' => 'a-new-password-42']],
            ['postJson', '/api/account/passkeys/options', $password],
            ['deleteJson', '/api/account/two-factor', $password],
            ['deleteJson', '/api/account/google', $password],
        ] as [$method, $uri, $body]) {
            $this->asToken($token)->{$method}($uri, $body)->assertStatus(422)->assertJsonPath('code', 'invalid_otp')->assertJsonPath('accepts', ['otp']);
        }
        // Right passwords guessed nothing: no lock, and nothing changed.
        expect($user->fresh()->hasTwoFactor())->toBeTrue()->and($user->fresh()->email)->toBe('farah@example.test')
            ->and(Hash::check('password', $user->fresh()->password))->toBeTrue();
        Mail::assertNotQueued(EmailCodeMail::class);
        Mail::assertNotQueued(SecurityChangeMail::class);

        // A wrong password with a right code is still refused, and counted.
        $this->asToken($token)->putJson('/api/account/password', ['current' => 'wrong-1', 'otp' => $recovery[0], 'new' => 'a-new-password-42'])->assertStatus(422)->assertJsonPath('code', 'wrong_password');
        // The password and a code together do it.
        $this->asToken($token)->putJson('/api/account/password', ['current' => 'password', 'otp' => accountsTotp($user->fresh(), $secret), 'new' => 'a-new-password-42'])->assertNoContent();
        $this->asToken($token)->postJson('/api/account/email', ['email' => 'new@example.test', 'otp' => $recovery[1]])->assertStatus(202);
    });

    it('emails the account holder when recovery codes are made again and when two-step is turned off', function () {
        $user = Fx::user(['email' => 'farah@example.test']);
        [, $recovery] = accountsEnrolTotp($user);
        [$device, $token] = $this->enroll($user->fresh());

        $codes = $this->asToken($token)->postJson('/api/account/two-factor/recovery-codes', ['otp' => $recovery[0]])->assertOk()->json('recovery_codes');
        Mail::assertQueued(SecurityChangeMail::class, function (SecurityChangeMail $mail) use ($codes, $device): bool {
            $html = $mail->render();

            return $mail->change === SecurityChangeMail::RECOVERY_CODES && $mail->hasTo('farah@example.test') && ! str_contains($html, $codes[0])
                && str_contains(html_entity_decode($html, ENT_QUOTES | ENT_HTML5), $device->readableName());
        });

        $this->asToken($token)->deleteJson('/api/account/two-factor', ['otp' => $codes[0]])->assertNoContent();
        Mail::assertQueued(SecurityChangeMail::class, fn (SecurityChangeMail $mail): bool => $mail->change === SecurityChangeMail::TWO_FACTOR_OFF
            && $mail->hasTo('farah@example.test') && str_contains($mail->render(), 'Two-step sign-in is off'));
        expect($user->fresh()->hasTwoFactor())->toBeFalse();
    });
});
