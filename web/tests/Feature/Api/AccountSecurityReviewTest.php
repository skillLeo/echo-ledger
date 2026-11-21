<?php

declare(strict_types=1);

use App\Filament\Pages\WorkspaceSettings;
use App\Mail\EmailChangedMail;
use App\Mail\EmailCodeMail;
use App\Mail\EmailRestoreNoticeMail;
use App\Mail\ResetPasswordMail;
use App\Mail\SecurityChangeMail;
use App\Models\AuditEvent;
use App\Models\ConnectedApp;
use App\Models\Device;
use App\Models\DevicePairingCode;
use App\Models\DeviceSignInCode;
use App\Models\Passkey;
use App\Models\User;
use App\Services\Accounts\DeviceSignIn;
use App\Services\Accounts\EmailChange;
use App\Services\Accounts\SignInGuard;
use App\Services\Accounts\SignUp;
use App\Services\Accounts\TwoFactorCodes;
use App\Services\SettingsRepository;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(InteractsWithDeviceApi::class);

require_once __DIR__.'/../Accounts/Support/account_helpers.php';

/* The security review of B9 (2.0.0-l): each finding with its test. */

beforeEach(function () {
    Filament::setCurrentPanel('app');
    Mail::fake();
    Notification::fake();
});

/** Changes the address by code from an app; returns the "Put my address back" link mailed to the old one. */
function reviewChangeEmail(object $test, string $token, string $to = 'new@example.test'): string
{
    $test->asToken($token)->postJson('/api/account/email', ['email' => $to, 'password' => 'password'])->assertStatus(202);
    $code = (string) Mail::queued(EmailCodeMail::class)->last()->code;
    $test->asToken($token)->postJson('/api/account/email/confirm', ['code' => $code])->assertOk();

    return (string) Mail::queued(EmailChangedMail::class)->last()->restoreUrl;
}

describe('the old address can undo an email change (major 1, and the reviews of the restore link)', function () {
    it('mails the old address a no-sign-in link, tells the new one, and the restore resets every way in', function () {
        $user = Fx::user(['email' => 'farah@example.test']);
        accountsEnrolTotp($user);
        $user->forceFill(['google_id' => 'my-own-google'])->save();
        Passkey::query()->create(['user_id' => $user->id, 'name' => 'Mine from before', 'credential_id' => 'b2xk', 'credential' => '{}']);
        [, $token] = $this->enroll($user->fresh());
        $remember = $user->fresh()->remember_token;

        // Changed by the console's link (proven with the password there): the window opens.
        $user = $user->fresh();
        $user->forceFill(['email' => 'new@example.test'])->save();
        app(EmailChange::class)->tellOldAddress($user, 'farah@example.test');
        $url = (string) Mail::queued(EmailChangedMail::class)->last()->restoreUrl;

        $mail = Mail::queued(EmailChangedMail::class)->last();
        expect($mail->hasTo('farah@example.test'))->toBeTrue()->and($mail->maskedNew)->toBe('n…@example.test')
            ->and($mail->render())->toContain('Put my address back')->not->toContain('new@example.test');
        Mail::assertQueued(EmailRestoreNoticeMail::class, fn (EmailRestoreNoticeMail $m): bool => $m->hasTo('new@example.test') && $m->kind === EmailRestoreNoticeMail::LINK_SENT
            && str_contains($m->render(), 'f…@example.test'));

        // Opening the link (a mail scanner does) changes nothing; the button does, without a sign-in.
        app('auth')->forgetGuards();
        $this->get($url)->assertOk()->assertSee('Put it back and sign out everywhere');
        expect($user->fresh()->email)->toBe('new@example.test');
        $this->post($url)->assertOk()->assertSee('Your address is back');

        $user = $user->fresh();
        expect($user->email)->toBe('farah@example.test')->and($user->hasVerifiedEmail())->toBeTrue()
            ->and($user->has_password)->toBeFalse()->and(Hash::check('password', $user->password))->toBeFalse()
            // Every way in, the owner's own from before too: the owner secures it again from the reset.
            ->and($user->hasTwoFactor())->toBeFalse()->and($user->app_authentication_recovery_codes)->toBeNull()->and($user->google_id)->toBeNull()
            ->and(Passkey::query()->where('user_id', $user->id)->count())->toBe(0)
            ->and($user->email_restore_address)->toBeNull()
            ->and(AuditEvent::query()->where('event', 'email_change_reverted')->where('user_id', $user->id)->exists())->toBeTrue()
            ->and(AuditEvent::query()->where('event', 'signed_out_everywhere')->where('user_id', $user->id)->exists())->toBeTrue()
            ->and($user->remember_token)->not->toBe($remember);
        Mail::assertQueued(ResetPasswordMail::class, fn ($m): bool => $m->hasTo('farah@example.test'));
        Mail::assertQueued(EmailRestoreNoticeMail::class, fn (EmailRestoreNoticeMail $m): bool => $m->hasTo('new@example.test') && $m->kind === EmailRestoreNoticeMail::RESTORED);
        $this->asToken($token)->getJson('/api/account')->assertUnauthorized();

        // Once only.
        $this->post($url)->assertStatus(410);
    });

    it('keeps the apps from changing the address after a restore until the reset is done, and at least 24 hours', function () {
        $user = Fx::user(['email' => 'farah@example.test']);
        [, $token] = $this->enroll($user);
        $url = reviewChangeEmail($this, $token);
        app('auth')->forgetGuards();
        $this->post($url)->assertOk();

        [, $token] = $this->enroll($user->fresh());
        $this->travel(25)->hours();
        // Past 24 hours, but no new password yet: still locked.
        $this->asToken($token)->postJson('/api/account/email', ['email' => 'later@example.test', 'email_code' => '000000'])->assertStatus(409)->assertJsonPath('code', 'email_change_locked');
        event(new PasswordReset($user->fresh()));
        [, $token] = $this->enroll($user->fresh());
        $this->asToken($token)->postJson('/api/account/proof-code')->assertStatus(202);
        $this->asToken($token)->postJson('/api/account/email', ['email' => 'later@example.test', 'email_code' => (string) Mail::queued(EmailCodeMail::class)->last()->code])->assertStatus(202);

        // A reset within the first 24 hours does not end the lock before them.
        $other = Fx::user(['email' => 'omar@example.test']);
        [, $otherToken] = $this->enroll($other);
        $otherUrl = reviewChangeEmail($this, $otherToken, 'omar.new@example.test');
        app('auth')->forgetGuards();
        $this->post($otherUrl)->assertOk();
        event(new PasswordReset($other->fresh()));
        $other->fresh()->forceFill(['password' => 'a-new-password-42'])->save();
        [, $otherToken] = $this->enroll($other->fresh());
        $this->asToken($otherToken)->postJson('/api/account/email', ['email' => 'omar.third@example.test', 'password' => 'a-new-password-42'])->assertStatus(409);
        $this->travel(25)->hours();
        [, $otherToken] = $this->enroll($other->fresh());
        $this->asToken($otherToken)->postJson('/api/account/email', ['email' => 'omar.third@example.test', 'password' => 'a-new-password-42'])->assertStatus(202);
    });

    it('locks further changes from the apps for the window, and keeps the link anchored to the first address', function () {
        $user = Fx::user(['email' => 'farah@example.test']);
        [, $token] = $this->enroll($user);
        $url = reviewChangeEmail($this, $token);

        // The chain A→B→C is refused from the apps: 409 email_change_locked with until, before any proof is used.
        $this->travel(2)->hours();
        $locked = $this->asToken($token)->postJson('/api/account/email', ['email' => 'third@example.test', 'password' => 'password'])->assertStatus(409);
        expect($locked->json('code'))->toBe('email_change_locked')
            ->and($locked->json('until'))->toBe(now()->subHours(2)->addDays(7)->toAtomString());
        expect($user->fresh()->email)->toBe('new@example.test');

        // A change by the console's link meanwhile keeps the window: the link goes to the FIRST address again.
        $user = $user->fresh();
        app(EmailChange::class)->tellOldAddress($user->forceFill(['email' => 'via-console@example.test']), 'new@example.test');
        $user->save();
        Mail::assertQueued(EmailChangedMail::class, fn (EmailChangedMail $m): bool => $m->hasTo('farah@example.test') && $m->restoreUrl === $url);
        Mail::assertNotQueued(EmailChangedMail::class, fn (EmailChangedMail $m): bool => $m->hasTo('new@example.test'));

        // The first link still works for the whole window, and puts back the first address.
        $this->travel(6)->days();
        app('auth')->forgetGuards();
        $this->post($url)->assertOk();
        expect($user->fresh()->email)->toBe('farah@example.test');
    });

    it('reserves the first address for the window: no sign-up, member, console or app change may take it', function () {
        $user = Fx::user(['email' => 'farah@example.test']);
        [, $token] = $this->enroll($user);
        $url = reviewChangeEmail($this, $token);
        expect(EmailChange::isReserved('Farah@Example.test'))->toBeTrue()->and(EmailChange::isReserved('farah@example.test', $user->id))->toBeFalse();

        // Sign-up by code.
        app(SettingsRepository::class)->set('signup.mode', SignUp::ANYONE);
        $this->postJson('/api/auth/start', ['email' => 'farah@example.test'])->assertStatus(422)->assertJsonPath('code', 'email_not_allowed');
        // A member added with a one-time password.
        $owner = Fx::user();
        $this->actingAs($owner, 'web');
        Livewire\Livewire::test(WorkspaceSettings::class)->callAction('addMember', ['email' => 'farah@example.test', 'name' => 'X', 'role' => 'member']);
        expect(User::query()->where('email', 'farah@example.test')->exists())->toBeFalse();
        // Staff or any other code path: the model refuses it.
        expect(fn () => Fx::user(['email' => 'farah@example.test']))->toThrow(ValidationException::class);
        expect(fn () => $owner->forceFill(['email' => 'FARAH@example.test'])->save())->toThrow(ValidationException::class);
        // Another account's app change.
        [, $ownerToken] = $this->enroll($owner->fresh());
        $this->asToken($ownerToken)->postJson('/api/account/email', ['email' => 'farah@example.test', 'password' => 'password'])->assertStatus(422)->assertJsonPath('code', 'email_taken');

        // So the restore cannot be blocked; once the window is over the address is free.
        app('auth')->forgetGuards();
        $this->post($url)->assertOk();
        expect($user->fresh()->email)->toBe('farah@example.test');
        $this->travel(8)->days();
        expect(EmailChange::isReserved('farah@example.test'))->toBeFalse();
    });

    it('gives no restore link for a change proven with two-step on for 7 days: the old address is told to contact support', function () {
        $user = Fx::user(['email' => 'farah@example.test']);
        [, $recovery] = accountsEnrolTotp($user);
        $this->travel(8)->days();
        [, $token] = $this->enroll($user->fresh());

        $this->asToken($token)->postJson('/api/account/email', ['email' => 'new@example.test', 'otp' => $recovery[0]])->assertStatus(202);
        $this->asToken($token)->postJson('/api/account/email/confirm', ['code' => (string) Mail::queued(EmailCodeMail::class)->last()->code])->assertOk();

        $mail = Mail::queued(EmailChangedMail::class)->last();
        expect($mail->hasTo('farah@example.test'))->toBeTrue()->and($mail->restoreUrl)->toBeNull()
            ->and($mail->render())->toContain('contact support')->not->toContain('Put my address back')
            ->and(EmailChange::window($user->fresh()))->toBeNull()->and(EmailChange::isReserved('farah@example.test'))->toBeFalse();
        Mail::assertNotQueued(EmailRestoreNoticeMail::class);
    });

    it('gives the usual restore link when two-step is newer than 7 days, or its recovery codes were made again since', function () {
        $recent = Fx::user(['email' => 'recent@example.test']);
        [, $recovery] = accountsEnrolTotp($recent);
        $this->travel(2)->days();
        [, $token] = $this->enroll($recent->fresh());
        $this->asToken($token)->postJson('/api/account/email', ['email' => 'recent.new@example.test', 'otp' => $recovery[0]])->assertStatus(202);
        $this->asToken($token)->postJson('/api/account/email/confirm', ['code' => (string) Mail::queued(EmailCodeMail::class)->last()->code])->assertOk();
        expect(Mail::queued(EmailChangedMail::class)->last()->restoreUrl)->not->toBeNull()
            ->and(EmailChange::window($recent->fresh()))->not->toBeNull();

        $regenerated = Fx::user(['email' => 'regen@example.test']);
        [, $codes] = accountsEnrolTotp($regenerated);
        $this->travel(10)->days();
        [, $token] = $this->enroll($regenerated->fresh());
        $fresh = $this->asToken($token)->postJson('/api/account/two-factor/recovery-codes', ['otp' => $codes[0]])->assertOk()->json('recovery_codes');
        expect(EmailChange::twoStepIsOld($regenerated->fresh()))->toBeFalse();
        $this->asToken($token)->postJson('/api/account/email', ['email' => 'regen.new@example.test', 'otp' => $fresh[0]])->assertStatus(202);
        $this->asToken($token)->postJson('/api/account/email/confirm', ['code' => (string) Mail::queued(EmailCodeMail::class)->last()->code])->assertOk();
        expect(Mail::queued(EmailChangedMail::class)->last()->restoreUrl)->not->toBeNull();

        // Two-step turned off and on again restarts the 7 days.
        $again = Fx::user(['email' => 'again@example.test']);
        accountsEnrolTotp($again);
        $this->travel(10)->days();
        $again->fresh()->forceFill(['app_authentication_secret' => null])->save();
        accountsEnrolTotp($again->fresh());
        expect(EmailChange::twoStepIsOld($again->fresh()))->toBeFalse();
    });

    it('counts recovery codes made in the console too: the timestamp is the model\'s, whatever path made them', function () {
        $user = Fx::user(['email' => 'farah@example.test']);
        accountsEnrolTotp($user);
        expect($user->fresh()->recovery_codes_generated_at)->not->toBeNull();
        $this->travel(10)->days();
        expect(EmailChange::twoStepIsOld($user->fresh()))->toBeTrue();

        // What Settings › Sign-in & security's "Regenerate recovery codes" does (Filament's action saves them so).
        $provider = AppAuthentication::make();
        $provider->saveRecoveryCodes($user->fresh(), $provider->generateRecoveryCodes());
        expect($user->fresh()->recovery_codes_generated_at->isToday())->toBeTrue()
            ->and(EmailChange::twoStepIsOld($user->fresh()))->toBeFalse()
            ->and(AuditEvent::query()->where('event', 'recovery_codes_regenerated')->exists())->toBeFalse();
        $this->travel(8)->days();
        expect(EmailChange::twoStepIsOld($user->fresh()))->toBeTrue();
        // Two-step off: no codes, no time.
        $user->fresh()->forceFill(['app_authentication_secret' => null, 'app_authentication_recovery_codes' => null])->save();
        expect($user->fresh()->recovery_codes_generated_at)->toBeNull();
    });

    it('reserves a mixed-case original address as the probe normalises it', function () {
        $user = Fx::user(['email' => 'farah@example.test']);
        DB::table('users')->where('id', $user->id)->update(['email' => 'Farah.Khan@Example.TEST']);
        [, $token] = $this->enroll($user->fresh());
        reviewChangeEmail($this, $token);

        expect($user->fresh()->email_restore_address)->toBe('farah.khan@example.test')
            ->and(EmailChange::isReserved('farah.khan@example.test'))->toBeTrue()
            ->and(EmailChange::isReserved('  FARAH.KHAN@example.test '))->toBeTrue();
    });

    it('reserves an accented or upper-case lookalike too: the database compares it as it compares addresses', function () {
        // A stand-in for MySQL's utf8mb4_unicode_ci on SQLite: case and accents do not count.
        $pdo = DB::connection()->getPdo();
        $fold = fn (string $v): string => mb_strtolower((string) preg_replace('/\p{Mn}+/u', '', (string) Normalizer::normalize($v, Normalizer::FORM_D)));
        $pdo->sqliteCreateCollation('el_unicode_ci', fn (string $a, string $b): int => strcmp($fold($a), $fold($b)));
        Schema::table('users', function ($table): void {
            $table->dropIndex(['email_restore_address']);
            $table->dropColumn('email_restore_address');
        });
        DB::statement('ALTER TABLE users ADD COLUMN email_restore_address varchar(255) COLLATE el_unicode_ci');

        $user = Fx::user(['email' => 'john@example.test']);
        [, $token] = $this->enroll($user);
        $url = reviewChangeEmail($this, $token);
        app(SettingsRepository::class)->set('signup.mode', SignUp::ANYONE);

        expect(EmailChange::isReserved('jóhn@example.test'))->toBeTrue()->and(EmailChange::isReserved('JÖHN@example.test'))->toBeTrue()
            ->and(EmailChange::isReserved('jon@example.test'))->toBeFalse();
        expect(fn () => Fx::user(['email' => 'jóhn@example.test']))->toThrow(ValidationException::class);
        $this->postJson('/api/auth/start', ['email' => 'jóhn@example.test'])->assertStatus(422)->assertJsonPath('code', 'email_not_allowed');

        app('auth')->forgetGuards();
        $this->post($url)->assertOk();
        expect($user->fresh()->email)->toBe('john@example.test');
    });

    it('lets a lookalike account made after the change not block the restore, while one from before does', function () {
        $user = Fx::user(['email' => 'farah@example.test']);
        [, $token] = $this->enroll($user);
        $url = reviewChangeEmail($this, $token);
        $window = EmailChange::window($user->fresh());
        // Made after the change, past the reservation (written straight into the table).
        $this->travel(1)->minutes();
        DB::table('users')->insert(['name' => 'Lookalike', 'email' => 'farah@example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('users')->where('email', 'farah@example.test')->where('name', 'Lookalike')->update(['email' => 'farah@example.test ']);

        app('auth')->forgetGuards();
        expect(User::query()->where('email', 'farah@example.test ')->value('created_at'))->not->toBeNull()
            ->and($window)->not->toBeNull();
        $this->post($url)->assertOk();
        expect($user->fresh()->email)->toBe('farah@example.test');
    });

    it('errors, never allows, when the reservation column is missing', function () {
        Schema::table('users', function ($table): void {
            $table->dropIndex(['email_restore_address']);
            $table->dropColumn('email_restore_address');
        });

        expect(fn () => EmailChange::isReserved('anyone@example.test'))->toThrow(RuntimeException::class)
            ->and(fn () => Fx::user(['email' => 'anyone@example.test']))->toThrow(RuntimeException::class);
    });

    it('ends the lock after a restore at 24 hours once the owner signs in again', function () {
        $user = Fx::user(['email' => 'farah@example.test']);
        [, $token] = $this->enroll($user);
        $url = reviewChangeEmail($this, $token);
        app('auth')->forgetGuards();
        $this->post($url)->assertOk();

        // Signed in again with an email code (the password was made unusable).
        $this->postJson('/api/auth/code', ['email' => 'farah@example.test'])->assertStatus(202);
        $signedIn = $this->postJson('/api/auth/code/verify', ['email' => 'farah@example.test', 'code' => (string) Mail::queued(EmailCodeMail::class)->last()->code,
            'name' => 'S25', 'platform' => 'android'])->assertCreated();
        expect($user->fresh()->email_restore['awaiting_reset'] ?? null)->toBeFalse();

        $this->travel(25)->hours();
        $token = (string) $signedIn->json('token');
        $this->asToken($token)->postJson('/api/account/proof-code')->assertStatus(202);
        $this->asToken($token)->postJson('/api/account/email', ['email' => 'later@example.test', 'email_code' => (string) Mail::queued(EmailCodeMail::class)->last()->code])->assertStatus(202);
    });

    it('uses a link once even when two clicks arrive together', function () {
        $user = Fx::user(['email' => 'farah@example.test']);
        [, $token] = $this->enroll($user);
        reviewChangeEmail($this, $token);
        $nonce = (string) EmailChange::window($user->fresh())['nonce'];

        // The second, with the model read before the first finished, still sees the record used (read under the lock).
        $stale = $user->fresh();
        expect(app(EmailChange::class)->restore($user->fresh(), $nonce))->toBeTrue()
            ->and(app(EmailChange::class)->restore($stale, $nonce))->toBeFalse()
            ->and(AuditEvent::query()->where('event', 'email_change_reverted')->count())->toBe(1);
    });

    it('opens the apps again and ends the link once the window is over', function () {
        $user = Fx::user(['email' => 'farah@example.test']);
        [, $token] = $this->enroll($user);
        $url = reviewChangeEmail($this, $token);

        $this->travel(7)->days();
        $this->travel(2)->minutes();
        app('auth')->forgetGuards();
        $this->post($url)->assertForbidden();
        expect($user->fresh()->email)->toBe('new@example.test');
        $this->asToken($token)->postJson('/api/account/email', ['email' => 'later@example.test', 'password' => 'password'])->assertStatus(202);
    });

    it('refuses a link with another key', function () {
        $user = Fx::user(['email' => 'farah@example.test']);
        [, $token] = $this->enroll($user);
        $url = reviewChangeEmail($this, $token);
        app('auth')->forgetGuards();

        $this->post(preg_replace('/n=[^&]+/', 'n=forged', $url))->assertForbidden();
        expect($user->fresh()->email)->toBe('new@example.test');
    });

    it('mails the old address too when the console\'s link confirmed the change', function () {
        $user = Fx::user(['email' => 'farah@example.test']);
        [, $token] = $this->enroll($user);
        $this->asToken($token)->postJson('/api/account/email', ['email' => 'linked@example.test', 'password' => 'password'])->assertStatus(202);
        $link = (string) Mail::queued(EmailCodeMail::class)->last()->url;

        $this->actingAs($user, 'web')->get($link)->assertRedirect();
        expect($user->fresh()->email)->toBe('linked@example.test');
        Mail::assertQueued(EmailChangedMail::class, fn (EmailChangedMail $m): bool => $m->hasTo('farah@example.test'));
        $window = EmailChange::window($user->fresh());
        expect($window['to'] ?? null)->toBe('farah@example.test')
            ->and(app(EmailChange::class)->restore($user->fresh(), (string) $window['nonce']))->toBeTrue()
            ->and($user->fresh()->email)->toBe('farah@example.test');
    });
});

describe('proof tries are counted first (major 2)', function () {
    it('refuses a right proof while five tries are in flight, as parallel requests would be', function () {
        $user = Fx::user();
        [, $token] = $this->enroll($user);
        // Five proofs sent at the same moment have each claimed their try before any of them was checked.
        foreach (range(1, 5) as $_) {
            app(SignInGuard::class)->claim($user->email);
        }

        $this->asToken($token)->deleteJson('/api/account/google', ['password' => 'password'])->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');
        $this->asToken($token)->postJson('/api/account/passkeys/options', ['password' => 'password'])->assertStatus(429);
    });

    it('lets only five of many wrong proofs reach the check, and counts each before checking it', function () {
        $user = Fx::user();
        [, $token] = $this->enroll($user);
        $checked = 0;
        // Every password check sees its own try counted already.
        Hash::shouldReceive('check')->andReturnUsing(function () use (&$checked, $user): bool {
            $checked++;
            expect(RateLimiter::attempts('sign-in-failures:'.hash('sha256', Str::lower($user->email))))->toBe($checked);

            return false;
        });

        foreach (range(1, 8) as $i) {
            $this->asToken($token)->postJson('/api/account/two-factor', ['password' => "guess-{$i}"]);
        }
        expect($checked)->toBe(5);
    });

    it('gives back the try of a right password sent without the two-step code', function () {
        $user = Fx::user();
        accountsEnrolTotp($user);
        [, $token] = $this->enroll($user->fresh());
        foreach (range(1, 8) as $_) {
            $this->asToken($token)->postJson('/api/account/email', ['email' => 'x@example.test', 'password' => 'password'])->assertStatus(422)->assertJsonPath('code', 'invalid_otp');
            $this->travel(21)->minutes(); // past the 3 an hour
        }
        expect(app(SignInGuard::class)->lockedFor($user->email))->toBe(0);
    });

    it('throttles every route that takes a proof, per person', function () {
        $routes = ['api.account.google.unlink' => 'throttle:account-passkeys', 'api.account.passkeys.remove' => 'throttle:account-passkeys',
            'api.account.two-factor.recovery-codes' => 'throttle:account-two-factor', 'api.account.two-factor.disable' => 'throttle:account-two-factor'];
        foreach ($routes as $name => $throttle) {
            expect(Route::getRoutes()->getByName($name)?->gatherMiddleware())->toContain($throttle);
        }

        $user = Fx::user();
        [, $phone] = $this->enroll($user);
        [, $laptop] = $this->enroll($user, null, 'windows');
        foreach (range(1, 10) as $i) {
            $this->asToken($i % 2 ? $phone : $laptop)->deleteJson('/api/account/google', [])->assertStatus(422);
        }
        $this->asToken($phone)->deleteJson('/api/account/google', [])->assertStatus(429)->assertJsonPath('code', 'rate_limited');
    });
});

describe('a password change from an app signs the others out as Sign out everywhere does (major 3)', function () {
    it('ends codes not used yet, connected apps and "remember me", keeping this device', function () {
        $user = Fx::user();
        [$device, $token] = $this->enroll($user);
        [$other, $otherToken] = $this->enroll($user, null, 'windows');
        DevicePairingCode::query()->create(['user_id' => $user->id, 'code_hash' => hash('sha256', 'pair'), 'expires_at' => now()->addMinutes(10)]);
        DeviceSignInCode::query()->create(['user_id' => $user->id, 'workspace_id' => $device->workspace_id, 'code_hash' => hash('sha256', 'browser'),
            'code_challenge' => DeviceSignIn::challengeOf(str_repeat('v', 43)), 'name' => 'Laptop', 'platform' => 'windows', 'expires_at' => now()->addMinutes(5)]);
        ConnectedApp::query()->create(['user_id' => $user->id, 'client_id' => (string) Str::uuid()]);
        $remember = $user->fresh()->remember_token;

        $this->asToken($token)->putJson('/api/account/password', ['current' => 'password', 'new' => 'a-new-password-42'])->assertNoContent();

        expect($other->fresh()->signed_out_at)->not->toBeNull()->and($device->fresh()->signed_out_at)->toBeNull()
            ->and(DevicePairingCode::query()->withoutGlobalScopes()->where('user_id', $user->id)->count())->toBe(0)
            ->and(DeviceSignInCode::query()->withoutGlobalScopes()->where('user_id', $user->id)->count())->toBe(0)
            ->and(ConnectedApp::query()->where('user_id', $user->id)->whereNull('disconnected_at')->count())->toBe(0)
            ->and($user->fresh()->remember_token)->not->toBe($remember);
        $this->asToken($token)->getJson('/api/account')->assertOk();
        $this->asToken($otherToken)->getJson('/api/account')->assertUnauthorized();
    });
});

describe('the minor findings', function () {
    it('emails the account holder when the password changes, Google is unlinked or a passkey is removed from an app', function () {
        $user = Fx::user(['email' => 'farah@example.test']);
        $user->forceFill(['google_id' => 'google-sub-1'])->save();
        $passkey = Passkey::query()->create(['user_id' => $user->id, 'name' => 'Old phone', 'credential_id' => 'b2xk', 'credential' => '{}']);
        [, $token] = $this->enroll($user->fresh());

        $this->asToken($token)->putJson('/api/account/password', ['current' => 'password', 'new' => 'a-new-password-42', 'sign_out_others' => false])->assertNoContent();
        $this->asToken($token)->deleteJson('/api/account/google', ['password' => 'a-new-password-42'])->assertNoContent();
        $this->asToken($token)->deleteJson("/api/account/passkeys/{$passkey->id}", ['password' => 'a-new-password-42'])->assertNoContent();

        foreach ([SecurityChangeMail::PASSWORD_CHANGED, SecurityChangeMail::GOOGLE_UNLINKED, SecurityChangeMail::PASSKEY_REMOVED] as $change) {
            Mail::assertQueued(SecurityChangeMail::class, fn (SecurityChangeMail $m): bool => $m->change === $change && $m->hasTo('farah@example.test') && ! str_contains($m->render(), 'a-new-password-42'));
        }
        Mail::assertQueued(SecurityChangeMail::class, fn (SecurityChangeMail $m): bool => $m->change === SecurityChangeMail::PASSKEY_REMOVED && str_contains(html_entity_decode($m->render()), 'Old phone'));
    });

    it('has no Android signing key by default, so phone passkeys and App Links are off until one is set', function () {
        $source = (string) file_get_contents(config_path('echoledger.php'));
        expect($source)->toMatch("/'ECHOLEDGER_ANDROID_FINGERPRINTS',\\s*(\\/\\/[^\\n]*\\n\\s*)*'',/")->not->toContain('34:AC:18:19');

        config(['echoledger.android_app.fingerprints' => []]);
        [, $token] = $this->enroll(Fx::user());
        $this->asToken($token)->getJson('/api/account')->assertJsonPath('passkeys_available', false);
        $this->asToken($token)->postJson('/api/account/passkeys/options', ['password' => 'password'])->assertStatus(409)->assertJsonPath('code', 'passkeys_unavailable');
        expect($this->get('/.well-known/assetlinks.json')->json('0.target.sha256_cert_fingerprints'))->toBe([]);
    });

    it('answers a suspended or unconfirmed account in its deletion grace as such, before anything about the deletion', function () {
        $user = Fx::user(['email' => 'farah@example.test', 'password' => 'a-good-password-42']);
        $user->forceFill(['deletes_at' => now()->addDays(7), 'suspended_at' => now()])->save();
        $body = ['email' => 'farah@example.test', 'password' => 'a-good-password-42', 'name' => 'S25', 'platform' => 'android'];

        $this->postJson('/api/auth/password', $body)->assertForbidden()->assertJsonPath('code', 'account_suspended');
        $this->postJson('/api/auth/password', [...$body, 'keep' => true])->assertForbidden()->assertJsonPath('code', 'account_suspended');
        $user->forceFill(['suspended_at' => null, 'email_verified_at' => null])->save();
        $this->postJson('/api/auth/password', $body)->assertForbidden()->assertJsonPath('code', 'email_not_verified');

        $verifier = str_repeat('v', 43);
        DeviceSignInCode::query()->create(['user_id' => $user->id, 'workspace_id' => $user->current_workspace_id, 'code_hash' => hash('sha256', 'the-code'),
            'code_challenge' => DeviceSignIn::challengeOf($verifier), 'name' => 'Laptop', 'platform' => 'windows', 'expires_at' => now()->addMinutes(5)]);
        $user->forceFill(['email_verified_at' => now(), 'suspended_at' => now()])->save();
        $this->postJson('/api/auth/device/token', ['code' => 'the-code', 'code_verifier' => $verifier, 'name' => 'Laptop', 'platform' => 'windows'])
            ->assertForbidden()->assertJsonPath('code', 'account_suspended');
        expect($user->fresh()->isBeingDeleted())->toBeTrue();
    });

    it('checks the new address before the proof, so a one-time code is not used up by a mistyped address', function () {
        $user = Fx::user();
        [, $recovery] = accountsEnrolTotp($user);
        [, $token] = $this->enroll($user->fresh());

        $this->asToken($token)->postJson('/api/account/email', ['email' => 'not an address', 'otp' => $recovery[0]])->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')->assertJsonPath('error_codes.email', [['code' => 'invalid_email']]);
        $this->asToken($token)->postJson('/api/account/email', ['email' => $user->email, 'otp' => $recovery[0]])->assertStatus(422)
            ->assertJsonPath('error_codes.email', [['code' => 'same_as_now']]);
        // The recovery code still works.
        $this->asToken($token)->postJson('/api/account/email', ['email' => 'new@example.test', 'otp' => $recovery[0]])->assertStatus(202);
    });

    it('never takes a two-step code used already in the sign-in\'s check without using it up', function () {
        $user = Fx::user(['email' => 'farah@example.test', 'password' => 'a-good-password-42']);
        [$secret] = accountsEnrolTotp($user);
        $user->forceFill(['deletes_at' => now()->addDays(7)])->save();
        $code = accountsTotp($user->fresh(), $secret);
        expect(app(TwoFactorCodes::class)->peek($user->fresh(), $code))->toBeTrue();
        // Used once (a sign-in elsewhere): the same code again is wrong, also where it is only peeked at.
        expect(app(TwoFactorCodes::class)->verify($user->fresh(), $code))->toBeTrue()
            ->and(app(TwoFactorCodes::class)->peek($user->fresh(), $code))->toBeFalse();

        $this->postJson('/api/auth/password', ['email' => 'farah@example.test', 'password' => 'a-good-password-42', 'otp' => $code, 'name' => 'S25', 'platform' => 'android'])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_otp');
        expect(DB::table('devices')->where('user_id', $user->id)->count())->toBe(0)->and(Device::query()->count())->toBe(0);
    });
});
