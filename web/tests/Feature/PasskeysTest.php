<?php

declare(strict_types=1);

use App\Filament\Pages\Security;
use App\Models\AuditEvent;
use App\Models\Passkey;
use App\Services\Passkeys;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Admin\Support\AdminTestHelpers;
use Tests\Feature\Support\SoftAuthenticator;

uses(AdminTestHelpers::class);

/* Passkeys for the console (B16): adding one on the Security page and signing in with it on the login page. */

/** Adds a passkey for the signed-in admin through the Security page, as the browser would. */
function addPasskey(object $test, SoftAuthenticator $authenticator, string $name = 'Work laptop'): object
{
    $page = $test->panelComponent(Security::class);
    $options = json_decode((string) $page->instance()->passkeyOptions(), true);
    $page->call('savePasskey', $authenticator->create($options), $name);

    return $page;
}

/** Signs in with a passkey: options, then the signed answer. */
function passkeySignIn(object $test, SoftAuthenticator $authenticator, array $tweak = []): TestResponse
{
    $options = $test->postJson('/passkeys/login/options')->assertOk()->json();
    $answer = $authenticator->get($options, $tweak['origin'] ?? null, $tweak['verified'] ?? true);
    if (isset($tweak['signature'])) {
        $answer['response']['signature'] = SoftAuthenticator::b64($tweak['signature']);
    }

    return $test->postJson('/passkeys/login', ['credential' => $answer]);
}

describe('adding a passkey', function () {
    it('asks for a discoverable, user-verified passkey for this site and never repeats one', function () {
        $admin = $this->signInAsAdmin();
        $authenticator = new SoftAuthenticator;
        $page = addPasskey($this, $authenticator);
        $page->assertNotified('Passkey added');

        $options = json_decode((string) $page->instance()->passkeyOptions(), true);
        expect($options['rp'])->toBe(['id' => 'echoledger.test', 'name' => 'EchoLedger'])
            ->and(SoftAuthenticator::unb64($options['user']['id']))->toBe(Passkeys::userHandle($admin))
            ->and($options['user']['name'])->toBe($admin->email)
            ->and(strlen(SoftAuthenticator::unb64($options['challenge'])))->toBe(32)
            ->and($options['authenticatorSelection'])->toMatchArray(['residentKey' => 'required', 'userVerification' => 'required'])
            ->and(array_column($options['excludeCredentials'], 'id'))->toBe([SoftAuthenticator::b64($authenticator->credentialId)]);

        $passkey = Passkey::query()->sole();
        expect($passkey->name)->toBe('Work laptop')->and($passkey->user_id)->toBe($admin->id)
            ->and(AuditEvent::query()->where('event', 'passkey_added')->exists())->toBeTrue();
    });

    it('refuses an answer from another site or for a challenge that was not asked', function () {
        $this->signInAsAdmin();
        $page = $this->panelComponent(Security::class);
        $options = json_decode((string) $page->instance()->passkeyOptions(), true);
        $page->call('savePasskey', (new SoftAuthenticator)->create($options, 'https://evil.example'), 'Phish')->assertNotified('That passkey could not be added');

        // The challenge is used once: the same answer again is refused too.
        $answer = (new SoftAuthenticator)->create(json_decode((string) $page->instance()->passkeyOptions(), true));
        $page->call('savePasskey', $answer, 'First');
        $page->call('savePasskey', $answer, 'Replay')->assertNotified('That passkey could not be added');
        expect(Passkey::query()->pluck('name')->all())->toBe(['First']);
    });

    it('removes a passkey', function () {
        $this->signInAsAdmin();
        addPasskey($this, new SoftAuthenticator)->call('removePasskey', Passkey::query()->sole()->id)->assertNotified('Passkey removed');

        expect(Passkey::query()->count())->toBe(0)->and(AuditEvent::query()->where('event', 'passkey_removed')->exists())->toBeTrue();
    });
});

describe('signing in with a passkey', function () {
    beforeEach(function () {
        $this->admin = $this->signInAsAdmin();
        $this->authenticator = new SoftAuthenticator;
        addPasskey($this, $this->authenticator);
        auth('web')->logout();
        session()->flush();
    });

    it('signs in without the password or the two-step code and records it', function () {
        passkeySignIn($this, $this->authenticator)->assertOk()->assertJsonPath('redirect', url('/admin'));

        expect(auth('web')->id())->toBe($this->admin->id)
            ->and(Passkey::query()->sole()->last_used_at)->not->toBeNull()
            ->and(AuditEvent::query()->where('user_id', $this->admin->id)->latest('id')->value('event'))->toBe('passkey_login')
            ->and(AuditEvent::query()->where('event', 'login')->where('created_at', '>=', now()->subSecond())->exists())->toBeFalse();
    });

    it('signs in again with the next counter value', function () {
        passkeySignIn($this, $this->authenticator)->assertOk();
        auth('web')->logout();
        passkeySignIn($this, $this->authenticator)->assertOk();
    });

    it('refuses a bad signature, another origin, no user verification or a stale challenge', function () {
        passkeySignIn($this, $this->authenticator, ['signature' => random_bytes(70)])->assertStatus(422);
        passkeySignIn($this, $this->authenticator, ['origin' => 'https://evil.example'])->assertStatus(422);
        passkeySignIn($this, $this->authenticator, ['verified' => false])->assertStatus(422);
        $this->postJson('/passkeys/login', ['credential' => $this->authenticator->get(['challenge' => SoftAuthenticator::b64(random_bytes(32)), 'rpId' => 'echoledger.test'])])->assertStatus(422);

        expect(auth('web')->check())->toBeFalse()
            ->and(AuditEvent::query()->where('event', 'login_failed')->count())->toBe(4);
    });

    it('refuses a passkey of someone who may not use the console', function () {
        // Neither a super admin nor a member of any workspace (B20).
        $this->admin->workspaces()->detach();
        $this->admin->forceFill(['is_super_admin' => false, 'current_workspace_id' => null])->save();

        passkeySignIn($this, $this->authenticator)->assertStatus(422)->assertJsonPath('message', 'That passkey could not sign you in. Try again or use your password.');
        expect(auth('web')->check())->toBeFalse();
    });

    it('offers the button on the login page and throttles attempts', function () {
        $this->get('/admin/login')->assertOk()->assertSee('Sign in with a passkey');
        foreach (range(1, 20) as $_) {
            $this->postJson('/passkeys/login/options')->assertOk();
        }
        $this->postJson('/passkeys/login/options')->assertStatus(429);
    });
});
