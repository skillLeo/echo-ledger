<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Account;

use App\Exceptions\ApiError;
use App\Filament\Auth\EditProfile;
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Controller;
use App\Mail\SecurityChangeMail;
use App\Models\Device;
use App\Models\User;
use App\Services\Accounts\AccountProof;
use App\Services\Accounts\AccountSessions;
use App\Services\Accounts\EmailChange;
use App\Services\AuditLog;
use App\Services\Workspaces\Workspaces;
use App\Support\Api\AccountRules;
use App\Support\Api\FieldErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * The person's name, email and password from the apps (since 2.0.0-l, docs/API.md "Account and security from the
 * apps" › 1, 2, 4 and 5), with the rules the console's Settings use. `sessions:write`; the person's own account only.
 */
final class AccountProfileController extends Controller
{
    public function __construct(private readonly AccountProof $proof) {}

    /** POST /api/account/proof-code — a 6-digit code to the current address, for an account with nothing else (5 an hour). */
    public function proofCode(Request $request): Response
    {
        $this->proof->sendCode($this->owner($request));

        return response('', 202);
    }

    /** PATCH /api/account {"name"} — 10 an hour; answers the account as GET /api/account does. */
    public function update(Request $request, Workspaces $workspaces, AuditLog $audit): JsonResponse
    {
        $user = $this->owner($request);
        if (! $request->has('name')) {
            throw FieldErrors::fail(['name' => [['required', 'Enter your name.']]]);
        }
        [$name, $problems] = AccountRules::name($request->input('name'));
        if ($problems !== []) {
            throw FieldErrors::fail(['name' => $problems]);
        }
        if ($name !== $user->name) {
            $user->forceFill(['name' => $name])->save();
            $audit->record('name_changed', $user->id, $user);
        }

        return app(AccountController::class)->show($request, $workspaces);
    }

    /** POST /api/account/email {"email", …proof} — 3 an hour. */
    public function email(Request $request, EmailChange $change): JsonResponse
    {
        $user = $this->owner($request);
        // The address first, so a one-time code is not used up by a mistyped address.
        $email = $change->validated($user, $request->input('email'));
        $this->proof->check($user, $request->only(['password', 'otp', 'email_code']));

        return response()->json($change->request($user, $email), 202);
    }

    /** POST /api/account/email/confirm {"code"} — 5 code attempts an hour; the fifth wrong one ends the change. */
    public function confirmEmail(Request $request, EmailChange $change, Workspaces $workspaces): JsonResponse
    {
        $user = $this->owner($request);
        $change->confirm($user, $request->input('code'));

        return app(AccountController::class)->show($request, $workspaces);
    }

    /** DELETE /api/account/email — ends a change waiting for its confirmation. */
    public function cancelEmail(Request $request, EmailChange $change): Response
    {
        $change->cancel($this->owner($request));

        return response()->noContent();
    }

    /**
     * PUT /api/account/password {"current", "new", "sign_out_others"} — 5 an hour, and a wrong current password counts
     * in SignInGuard like a failed sign-in. An account without a password sets its first one with an `otp` or an
     * `email_code`. Every other device and every browser is signed out unless `sign_out_others` is false; this device's
     * token stays.
     */
    public function password(Request $request, AccountSessions $sessions): Response
    {
        $user = $this->owner($request);
        $new = $request->input('new');
        $signOutOthers = $request->has('sign_out_others') ? $request->boolean('sign_out_others') : true;
        // What can be said without the proof first, so a one-time code is not used up by a password that is too short.
        $problems = AccountRules::passwordProblems(is_string($new) ? $new : '');
        if ($problems !== []) {
            throw FieldErrors::fail(['new' => $problems]);
        }
        if (AccountProof::hasPassword($user)) {
            $current = $request->input('current');
            if (! is_string($current) || $current === '') {
                throw FieldErrors::fail(['current' => [['required', 'Enter your current password.']]]);
            }
            // With two-step on, the `otp` too (AccountProof).
            $this->proof->check($user, ['password' => $current, 'otp' => $request->input('otp')]);
            // Only after the proof: otherwise this would tell anyone holding the device whether a guess is the password.
            if (Hash::check((string) $new, (string) $user->password)) {
                throw FieldErrors::fail(['new' => [['same_as_current', 'Choose a password you are not using now.']]]);
            }
        } else {
            $this->proof->check($user, $request->only(['otp', 'email_code']));
        }
        /** @var string $new */
        $user->forceFill(['password' => Hash::make($new), 'has_password' => true])->save();
        if ($signOutOthers) {
            // Everything Sign out everywhere ends (devices, browsers, sign-in and pairing codes not used yet, connected
            // apps, "remember me"), this device kept.
            $sessions->signOutEverywhere($user, $this->device($request)->id);
        }
        Mail::to($user->email)->queue(new SecurityChangeMail(SecurityChangeMail::PASSWORD_CHANGED, $this->device($request)->readableName(), EditProfile::settingsUrl('security')));

        return response()->noContent();
    }

    private function device(Request $request): Device
    {
        $device = $request->user();
        if (! $device instanceof Device) {
            throw new ApiError(401, 'unauthenticated', 'Unauthenticated.');
        }

        return $device;
    }

    private function owner(Request $request): User
    {
        return User::query()->findOrFail($this->device($request)->user_id);
    }
}
