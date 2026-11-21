<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Account;

use App\Exceptions\ApiError;
use App\Filament\Auth\EditProfile;
use App\Http\Controllers\Api\SecurityController;
use App\Http\Controllers\Controller;
use App\Mail\SecurityChangeMail;
use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\KnownBrowser;
use App\Models\Passkey;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Accounts\AccountProof;
use App\Services\Accounts\AccountSessions;
use App\Services\Accounts\TwoFactorSetup;
use App\Services\AuditLog;
use App\Services\Passkeys;
use App\Services\WebSessions;
use App\Support\Api\FieldErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;

/**
 * Sign-in methods, browsers, devices, two-step and passkeys from the apps (since 2.0.0-l, docs/API.md "Account and
 * security from the apps" › 6, 7 and 8), on the services the console's Settings › Sign-in & security and Devices use.
 * The person's own only: another person's device, browser or passkey is the same 404 as an unknown id.
 */
final class AccountSecurityController extends Controller
{
    /** Signed-out devices stay in the list this long. */
    public const SIGNED_OUT_DAYS = 30;

    public function __construct(private readonly AccountProof $proof) {}

    /** GET /api/account/security — `config:read`. */
    public function show(Request $request, WebSessions $browsers): JsonResponse
    {
        $device = $this->device($request);
        $user = $this->owner($request);
        $workspaces = Workspace::query()->whereIn('id', Device::query()->withoutGlobalScopes()->where('user_id', $user->id)->select('workspace_id'))
            ->get(['id', 'name'])->keyBy('id');
        $all = app(AccountSessions::class)->devices($user);
        $devices = $all->filter(fn (Device $d): bool => $d->signed_out_at === null || $d->signed_out_at->gte(now()->subDays(self::SIGNED_OUT_DAYS)));
        $events = AuditEvent::query()->where('user_id', $user->id)->latest('id')->limit(50)->get();

        return response()->json([
            'password' => AccountProof::hasPassword($user),
            // The server keeps only Google's id of the person, not the Google address.
            'google' => ['linked' => $user->google_id !== null, 'email' => null],
            'passkeys' => Passkey::query()->where('user_id', $user->id)->latest()->get()->map(fn (Passkey $p): array => self::passkeyPayload($p))->values(),
            'two_factor' => ['enabled' => $user->hasTwoFactor(), 'recovery_codes_left' => TwoFactorSetup::recoveryCodesLeft($user)],
            'browsers' => $this->browsers($user, $browsers),
            'devices' => $devices->map(fn (Device $d): array => [
                ...$d->listItem($device->id),
                'workspace' => ($w = $workspaces->get($d->workspace_id)) instanceof Workspace ? ['id' => $w->id, 'name' => $w->name] : null,
            ])->values(),
            'events' => SecurityController::eventsPayload($events, $all->mapWithKeys(fn (Device $d): array => [$d->id => $d->readableName()])->all()),
        ]);
    }

    /** PATCH /api/account/devices/{id} {"name"} — a device of the person in any of their workspaces. */
    public function renameDevice(Request $request, string $id, AuditLog $audit): JsonResponse
    {
        $data = FieldErrors::validate(['name' => is_string($request->input('name')) ? trim($request->input('name')) : $request->input('name')],
            ['name' => ['required', 'string', 'min:1', 'max:60']]);
        $target = $this->ownDevice($request, $id);
        $target->forceFill(['name' => $data['name'], 'name_chosen' => true])->save();
        $audit->record('device_renamed', $target->user_id, $target, 'Device renamed: '.$target->name);

        return response()->json($target->listItem($this->device($request)->id));
    }

    /** DELETE /api/account/devices/{id} — signs one out (the row stays, "Signed out by you"); `409 current_device`. */
    public function revokeDevice(Request $request, string $id, AccountSessions $sessions): Response
    {
        $target = $this->ownDevice($request, $id);
        if ($target->id === $this->device($request)->id) {
            throw new ApiError(409, 'current_device', 'This is the device you are using. Sign out of it in its settings instead.');
        }
        $sessions->revokeDevice($this->owner($request), $target->id);

        return response()->noContent();
    }

    /** DELETE /api/account/browsers/{id} — `404 browser_not_found`. */
    public function revokeBrowser(Request $request, string $id, WebSessions $browsers): Response
    {
        $user = $this->owner($request);
        $session = $this->sessionOf($user, $id, $browsers);
        if ($session === null || ! $browsers->revoke($user->id, $session, null)) {
            throw new ApiError(404, 'browser_not_found', 'That browser is not signed in.');
        }

        return response()->noContent();
    }

    /** DELETE /api/account/google {…proof} — `409 last_sign_in_method` without a password or a passkey. */
    public function unlinkGoogle(Request $request, AuditLog $audit): Response
    {
        $user = $this->owner($request);
        $this->proof->check($user, $request->only(['password', 'otp', 'email_code']));
        if ($user->google_id === null) {
            return response()->noContent();
        }
        if (! AccountProof::hasPassword($user) && ! Passkey::query()->where('user_id', $user->id)->exists()) {
            throw new ApiError(409, 'last_sign_in_method', 'Google is your only way to sign in. Choose a password or add a passkey first.');
        }
        $user->forceFill(['google_id' => null])->save();
        $audit->record('google_unlinked', $user->id, $user);

        return response()->noContent();
    }

    /**
     * POST /api/account/passkeys/options {…proof} — the native ceremony's creation options; `409 too_many_passkeys`.
     * The proof is asked here: the ceremony it opens is the only way to POST /api/account/passkeys, so a device alone
     * (a stolen token) cannot add a way into the account.
     */
    public function passkeyOptions(Request $request, Passkeys $passkeys): JsonResponse
    {
        $user = $this->owner($request);
        $this->assertPhonePasskeys();
        if (Passkey::query()->where('user_id', $user->id)->count() >= Passkeys::MAX_PER_USER) {
            throw new ApiError(409, 'too_many_passkeys', 'You have '.Passkeys::MAX_PER_USER.' passkeys already. Remove one first.');
        }
        $this->proof->check($user, $request->only(['password', 'otp', 'email_code']));

        return response()->json($passkeys->apiRegistrationOptions($user));
    }

    /** POST /api/account/passkeys {"ceremony", "name", "credential"} — `201`; `422 invalid_passkey`. */
    public function storePasskey(Request $request, Passkeys $passkeys): JsonResponse
    {
        $data = FieldErrors::validate($request->all(), [
            'ceremony' => ['required', 'string', 'max:64'],
            'name' => ['nullable', 'string', 'max:80'],
            'credential' => ['required', 'array'],
        ]);
        $user = $this->owner($request);
        $this->assertPhonePasskeys();
        $passkey = $passkeys->register($user, (string) json_encode($data['credential']), (string) ($data['name'] ?? ''), (string) $data['ceremony']);
        if (! $passkey instanceof Passkey) {
            throw new ApiError(422, 'invalid_passkey', "Couldn't add that passkey. Try again.");
        }
        self::notify($user, SecurityChangeMail::PASSKEY_ADDED, $this->device($request), $passkey->name);

        return response()->json(['id' => $passkey->id, 'name' => $passkey->name, 'created_at' => $passkey->created_at->toAtomString()], 201);
    }

    /** PATCH /api/account/passkeys/{id} {"name"} — `404 passkey_not_found`. */
    public function renamePasskey(Request $request, string $id, Passkeys $passkeys): JsonResponse
    {
        $data = FieldErrors::validate(['name' => is_string($request->input('name')) ? trim($request->input('name')) : $request->input('name')],
            ['name' => ['required', 'string', 'min:1', 'max:80']]);
        $passkey = $passkeys->rename($this->owner($request), $id, (string) $data['name']);
        if (! $passkey instanceof Passkey) {
            throw self::passkeyNotFound();
        }

        return response()->json(self::passkeyPayload($passkey));
    }

    /** DELETE /api/account/passkeys/{id} {…proof} — `404 passkey_not_found`, `409 last_sign_in_method`. */
    public function removePasskey(Request $request, string $id, Passkeys $passkeys): Response
    {
        $user = $this->owner($request);
        $passkey = Passkey::query()->where('user_id', $user->id)->find($id);
        if (! $passkey instanceof Passkey) {
            throw self::passkeyNotFound();
        }
        $this->proof->check($user, $request->only(['password', 'otp', 'email_code']));
        $others = Passkey::query()->where('user_id', $user->id)->whereKeyNot($passkey->id)->exists();
        if (! AccountProof::hasPassword($user) && $user->google_id === null && ! $others) {
            throw new ApiError(409, 'last_sign_in_method', 'This passkey is your only way to sign in. Choose a password first.');
        }
        $passkeys->remove($user, $passkey->id);

        return response()->noContent();
    }

    /**
     * POST /api/account/two-factor {…proof} — a new secret, saved only once confirmed; 10 an hour. The proof is asked
     * here: the ceremony it opens is the only way to confirm, so a device alone cannot enrol its own authenticator.
     */
    public function beginTwoFactor(Request $request, TwoFactorSetup $setup): JsonResponse
    {
        $user = $this->owner($request);
        $setup->assertNotEnabled($user);
        $this->proof->check($user, $request->only(['password', 'email_code']));

        return response()->json($setup->begin($user));
    }

    /** POST /api/account/two-factor/confirm {"ceremony", "code"} — the recovery codes, shown only now. */
    public function confirmTwoFactor(Request $request, TwoFactorSetup $setup): JsonResponse
    {
        $data = FieldErrors::validate($request->all(), ['ceremony' => ['required', 'string', 'max:64'], 'code' => ['required', 'string', 'max:16']]);

        $user = $this->owner($request);
        $codes = $setup->confirm($user, (string) $data['ceremony'], (string) $data['code']);
        self::notify($user, SecurityChangeMail::TWO_FACTOR_ON, $this->device($request));

        return response()->json(['recovery_codes' => $codes]);
    }

    /** POST /api/account/two-factor/recovery-codes {…proof} — new codes; the old ones stop working. */
    public function recoveryCodes(Request $request, TwoFactorSetup $setup): JsonResponse
    {
        $user = $this->owner($request);
        $this->proof->check($user, $request->only(['password', 'otp', 'email_code']));

        return response()->json(['recovery_codes' => $setup->regenerate($user)]);
    }

    /**
     * DELETE /api/account/two-factor {"otp"} — only an authenticator or recovery code proves it here, never a password
     * or an email code alone; `409 required_for_staff`.
     */
    public function disableTwoFactor(Request $request, TwoFactorSetup $setup): Response
    {
        $user = $this->owner($request);
        if (! $user->hasTwoFactor()) {
            return response()->noContent();
        }
        $setup->assertMayDisable($user);
        $this->proof->check($user, $request->only(['otp']), authenticatorOnly: true);
        $setup->disable($user);

        return response()->noContent();
    }

    /** The account holder hears of a way to sign in added from an app (SecurityChangeMail). */
    private static function notify(User $user, string $change, Device $device, ?string $passkey = null): void
    {
        Mail::to($user->email)->queue(new SecurityChangeMail($change, $device->readableName(), EditProfile::settingsUrl('security'), $passkey));
    }

    /** @return array{id: string, name: string, created_at: string, last_used_at: string|null} */
    private static function passkeyPayload(Passkey $passkey): array
    {
        return ['id' => $passkey->id, 'name' => $passkey->name, 'created_at' => $passkey->created_at->toAtomString(), 'last_used_at' => $passkey->last_used_at?->toAtomString()];
    }

    private static function passkeyNotFound(): ApiError
    {
        return new ApiError(404, 'passkey_not_found', 'Passkey not found.');
    }

    private function assertPhonePasskeys(): void
    {
        if (! Passkeys::availableOnPhone()) {
            throw new ApiError(409, 'passkeys_unavailable', 'Passkeys are managed on the website on this server.');
        }
    }

    /**
     * The person's console browsers as the apps see them: an opaque id (a keyed hash of the session, never the session
     * id itself), the browser and system, the country when the server's proxy named it, and when it was last used.
     *
     * @return list<array{id: string, agent: string, country: string|null, last_active_at: string, current: bool}>
     */
    private function browsers(User $user, WebSessions $browsers): array
    {
        $list = $browsers->list($user->id, null);
        $countries = KnownBrowser::query()->where('user_id', $user->id)->whereNotNull('country')
            ->whereIn('ip', array_values(array_filter(array_column($list, 'ip'))))
            ->orderByDesc('last_used_at')->get(['ip', 'country'])->unique('ip')->pluck('country', 'ip');

        return array_map(fn (array $b): array => [
            'id' => self::browserId($user, $b['id']),
            'agent' => $b['browser'],
            'country' => $b['ip'] !== null ? ($countries[$b['ip']] ?? null) : null,
            'last_active_at' => $b['last_active']->toAtomString(),
            'current' => false,
        ], $list);
    }

    private function sessionOf(User $user, string $id, WebSessions $browsers): ?string
    {
        foreach ($browsers->list($user->id, null) as $browser) {
            if (hash_equals(self::browserId($user, $browser['id']), $id)) {
                return $browser['id'];
            }
        }

        return null;
    }

    private static function browserId(User $user, string $session): string
    {
        return substr(hash_hmac('sha256', 'browser|'.$user->id.'|'.$session, (string) config('app.key')), 0, 32);
    }

    private function ownDevice(Request $request, string $id): Device
    {
        $target = Device::query()->withoutGlobalScopes()->where('user_id', $this->device($request)->user_id)->find($id);
        if (! $target instanceof Device) {
            throw new ApiError(404, 'device_not_found', 'Device not found.');
        }

        return $target;
    }

    private function device(Request $request): Device
    {
        /** @var Device $device */
        $device = $request->user();

        return $device;
    }

    private function owner(Request $request): User
    {
        return User::query()->findOrFail($this->device($request)->user_id);
    }
}
