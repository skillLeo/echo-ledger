<?php

declare(strict_types=1);

use App\Mail\DeletionCancelledMail;
use App\Mail\EmailCodeMail;
use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\DeviceSignInCode;
use App\Models\Plan;
use App\Services\Accounts\DeviceSignIn;
use App\Services\SettingsRepository;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

require_once __DIR__.'/../Accounts/Support/account_helpers.php';

/*
| Keep my account through the apps' sign-in (2.0.0-l): during the 7-day deletion grace a sign-in answers
| `403 deletion_scheduled`, and the same call with "keep": true cancels the deletion and signs in, only once the whole
| sign-in has passed.
*/

beforeEach(function () {
    Mail::fake();
    $this->person = Fx::user(['email' => 'farah@example.test', 'password' => 'a-good-password-42']);
    $this->person->forceFill(['deletion_requested_at' => now(), 'deletes_at' => now()->addDays(7)])->save();
});

/** @return array<string, mixed> */
function keepBody(array $overrides = []): array
{
    return array_merge(['email' => 'farah@example.test', 'password' => 'a-good-password-42', 'name' => 'S25 Ultra', 'platform' => 'android', 'app_version' => '2.0.0'], $overrides);
}

/** A browser sign-in code approved for the person (as the console's page does) and its verifier. */
function keepBrowserCode(int $userId, int $workspaceId): array
{
    $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $code = bin2hex(random_bytes(16));
    DeviceSignInCode::query()->create(['user_id' => $userId, 'workspace_id' => $workspaceId, 'code_hash' => hash('sha256', $code),
        'code_challenge' => DeviceSignIn::challengeOf($verifier), 'name' => 'Work laptop', 'platform' => 'windows', 'expires_at' => now()->addMinutes(5)]);

    return [$code, $verifier];
}

it('answers 403 deletion_scheduled with the date, then keeps the account and signs in with "keep": true', function () {
    $refused = $this->postJson('/api/auth/password', keepBody())->assertForbidden();
    $date = $this->person->deletes_at->copy()->setTimezone(app(SettingsRepository::class)->displayTimezone())->format('M j, Y');
    expect($refused->json('code'))->toBe('deletion_scheduled')
        ->and($refused->json('deletes_at'))->toBe($this->person->deletes_at->toAtomString())
        // What an app that does not know the code shows as it is.
        ->and($refused->json('message'))->toBe("This account is being deleted on {$date}. Sign in on the console and choose Keep my account.")
        ->and(Device::query()->where('user_id', $this->person->id)->count())->toBe(0)
        ->and($this->person->fresh()->isBeingDeleted())->toBeTrue();

    $this->postJson('/api/auth/password', keepBody(['keep' => true]))->assertCreated()->assertJsonStructure(['token', 'device']);
    expect($this->person->fresh()->isBeingDeleted())->toBeFalse()
        ->and(AuditEvent::query()->where('event', 'account_deletion_cancelled')->where('user_id', $this->person->id)->count())->toBe(1);
    Mail::assertQueued(DeletionCancelledMail::class, fn (DeletionCancelledMail $m): bool => $m->hasTo('farah@example.test'));
});

it('counts keep only once the whole sign-in has passed', function () {
    // A wrong password: nothing is said about the deletion and nothing is kept.
    $this->postJson('/api/auth/password', keepBody(['password' => 'wrong-password-1', 'keep' => true]))->assertStatus(422)->assertJsonPath('code', 'invalid_credentials');
    $this->postJson('/api/auth/password', keepBody(['password' => 'wrong-password-2']))->assertStatus(422)->assertJsonPath('code', 'invalid_credentials');
    expect($this->person->fresh()->isBeingDeleted())->toBeTrue();

    // Suspended (the staff rules come before keep): still being deleted.
    $this->person->forceFill(['suspended_at' => now()])->save();
    $this->postJson('/api/auth/password', keepBody(['keep' => true]))->assertForbidden()->assertJsonPath('code', 'account_suspended');
    expect($this->person->fresh()->isBeingDeleted())->toBeTrue();
    $this->person->forceFill(['suspended_at' => null])->save();

    // A device that cannot be added (the plan's limit) keeps nothing either.
    Plan::personal()->update(['max_devices' => 1]);
    Device::factory()->create(['user_id' => $this->person->id]);
    $this->postJson('/api/auth/password', keepBody(['keep' => true]))->assertForbidden()->assertJsonPath('code', 'limit_reached');
    expect($this->person->fresh()->isBeingDeleted())->toBeTrue()
        ->and(AuditEvent::query()->where('event', 'account_deletion_cancelled')->exists())->toBeFalse();
    Mail::assertNotQueued(DeletionCancelledMail::class);

    // Locked by SignInGuard: keep waits too.
    Plan::personal()->update(['max_devices' => null]);
    $this->travel(61)->seconds(); // the route's 5 a minute from one address
    foreach (range(3, 7) as $i) {
        $this->postJson('/api/auth/password', keepBody(['password' => "wrong-password-{$i}"]))->assertStatus(422);
    }
    $this->postJson('/api/auth/password', keepBody(['keep' => true]))->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');
    expect($this->person->fresh()->isBeingDeleted())->toBeTrue();
});

it('asks for the two-step code first, checks it without using it up, and takes the same code with keep', function () {
    [$secret] = accountsEnrolTotp($this->person);
    $this->postJson('/api/auth/password', keepBody())->assertStatus(401)->assertJsonPath('code', 'otp_required');
    $this->postJson('/api/auth/password', keepBody(['otp' => '000000']))->assertStatus(422)->assertJsonPath('code', 'invalid_otp');
    $this->postJson('/api/auth/password', keepBody(['otp' => '000000', 'keep' => true]))->assertStatus(422)->assertJsonPath('code', 'invalid_otp');
    expect($this->person->fresh()->isBeingDeleted())->toBeTrue();

    $otp = accountsTotp($this->person, $secret);
    $this->postJson('/api/auth/password', keepBody(['otp' => $otp]))->assertForbidden()->assertJsonPath('code', 'deletion_scheduled');
    $this->postJson('/api/auth/password', keepBody(['otp' => $otp, 'keep' => true]))->assertCreated();
    expect($this->person->fresh()->isBeingDeleted())->toBeFalse();
    // Used up now, as any sign-in's code (a minute later: the route allows 5 a minute from one address).
    $this->travel(61)->seconds();
    $this->postJson('/api/auth/password', keepBody(['otp' => $otp, 'name' => 'Second']))->assertStatus(422)->assertJsonPath('code', 'invalid_otp');
});

it('keeps the browser sign-in code good for its 5 minutes after deletion_scheduled, then keeps the account', function () {
    [$code, $verifier] = keepBrowserCode($this->person->id, (int) $this->person->current_workspace_id);
    $body = ['code' => $code, 'code_verifier' => $verifier, 'name' => 'Work laptop', 'platform' => 'windows'];

    $this->postJson('/api/auth/device/token', $body)->assertForbidden()->assertJsonPath('code', 'deletion_scheduled')->assertJsonStructure(['deletes_at', 'message']);
    expect(DeviceSignInCode::query()->sole()->used_at)->toBeNull();
    $this->postJson('/api/auth/device/token', $body)->assertForbidden()->assertJsonPath('code', 'deletion_scheduled');

    $this->postJson('/api/auth/device/token', [...$body, 'keep' => true])->assertCreated();
    expect($this->person->fresh()->isBeingDeleted())->toBeFalse()->and(DeviceSignInCode::query()->sole()->used_at)->not->toBeNull();
    $this->postJson('/api/auth/device/token', [...$body, 'keep' => true])->assertStatus(422)->assertJsonPath('code', 'invalid_code');
});

it('uses the browser code up on a wrong verifier, whatever keep says, and lets it expire', function () {
    [$code] = keepBrowserCode($this->person->id, (int) $this->person->current_workspace_id);
    $wrong = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $this->postJson('/api/auth/device/token', ['code' => $code, 'code_verifier' => $wrong, 'keep' => true, 'name' => 'x', 'platform' => 'windows'])
        ->assertStatus(422)->assertJsonPath('code', 'invalid_code');
    expect(DeviceSignInCode::query()->sole()->used_at)->not->toBeNull()->and($this->person->fresh()->isBeingDeleted())->toBeTrue();

    [$late, $verifier] = keepBrowserCode($this->person->id, (int) $this->person->current_workspace_id);
    $this->travel(6)->minutes();
    $this->postJson('/api/auth/device/token', ['code' => $late, 'code_verifier' => $verifier, 'keep' => true, 'name' => 'x', 'platform' => 'windows'])->assertStatus(422);
    expect($this->person->fresh()->isBeingDeleted())->toBeTrue();
});

it('does the same for the email code sign-in, the code staying good for the call with keep', function () {
    $this->postJson('/api/auth/code', ['email' => 'farah@example.test'])->assertStatus(202);
    $code = (string) Mail::queued(EmailCodeMail::class)->last()->code;
    $body = ['email' => 'farah@example.test', 'code' => $code, 'name' => 'S25', 'platform' => 'android'];

    $this->postJson('/api/auth/code/verify', $body)->assertForbidden()->assertJsonPath('code', 'deletion_scheduled');
    $this->postJson('/api/auth/code/verify', [...$body, 'keep' => true])->assertCreated();
    expect($this->person->fresh()->isBeingDeleted())->toBeFalse();
});

it('signs in as before with keep when nothing is being deleted', function () {
    $this->person->forceFill(['deletion_requested_at' => null, 'deletes_at' => null])->save();

    $this->postJson('/api/auth/password', keepBody(['keep' => true]))->assertCreated();
    expect(AuditEvent::query()->where('event', 'account_deletion_cancelled')->exists())->toBeFalse();
    Mail::assertNotQueued(DeletionCancelledMail::class);
    $this->postJson('/api/auth/password', keepBody(['keep' => 'yes please']))->assertStatus(422)->assertJsonPath('code', 'validation_failed');
});
