<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiError;
use App\Filament\Support\Shell;
use App\Http\Controllers\Controller;
use App\Models\AccountExport;
use App\Models\Device;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Accounts\AccountDeletion;
use App\Services\Accounts\AccountExports;
use App\Services\Accounts\AccountProof;
use App\Services\Accounts\AccountSessions;
use App\Services\Accounts\Avatars;
use App\Services\Accounts\EmailChange;
use App\Services\DeviceConfigPayload;
use App\Services\InterfaceFlags;
use App\Services\Passkeys;
use App\Services\Staff\StaffAccess;
use App\Services\Workspaces\Workspaces;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Your account (since v1.5, docs/API.md "Your account"): the device's owner, as the device acts for them. */
final class AccountController extends Controller
{
    /** GET /api/account — `config:read`. */
    public function show(Request $request, Workspaces $workspaces): JsonResponse
    {
        $device = $this->device($request);
        $user = $this->owner($request);
        $workspace = Workspace::query()->find($device->getAttribute('workspace_id'));

        return response()->json([
            'name' => $user->name,
            'email' => $user->email,
            'email_verified' => $user->hasVerifiedEmail(),
            'two_factor' => $user->hasTwoFactor(),
            'google' => $user->google_id !== null,
            'created_at' => $user->created_at?->toAtomString(),
            'workspace' => $workspace === null ? null : [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'role' => $workspaces->roleOf($user, $workspace->id)?->value,
            ],
            'deletes_at' => $user->deletes_at?->toAtomString(),
            // Since v1.6 (P4): platform staff may open the admin panel (any staff role); every workspace of this person,
            // the device's marked current (only theirs: the person's own memberships).
            'super_admin' => StaffAccess::isStaff($user),
            'workspaces' => $user->workspaces()->orderBy('workspaces.id')->get(['workspaces.id', 'workspaces.name'])
                ->map(fn (Workspace $w): array => [
                    'id' => $w->id,
                    'name' => $w->name,
                    'role' => (string) $w->getRelationValue('pivot')?->getAttribute('role'),
                    'current' => $w->id === $workspace?->id,
                ])->values()->all(),
            // Since 2.0.0-l (docs/API.md "Account and security from the apps"): the picture, the password, a change of
            // address waiting for its confirmation, and whether the phone can add passkeys on this server.
            ...Avatars::of($user),
            'has_password' => AccountProof::hasPassword($user),
            ...app(EmailChange::class)->payload($user),
            'passkeys_available' => Passkeys::availableOnPhone(),
            // Since 2.0.0-r §6 (BE-06): the interface language, the console's own setting (avatar_color is beside avatar_url).
            'language' => Shell::preferences($user)['app_language'],
        ]);
    }

    /**
     * PUT /api/account/ui — `config:read` (since 2.0.0-a): the person's "Switch back to classic". Answers the new
     * `ui_flags` and `ui_classic`, as GET /api/config gives them.
     */
    public function ui(Request $request, InterfaceFlags $flags): JsonResponse
    {
        $device = $this->device($request);
        // The device's own person (the model the rest of this request reads), so what is answered is what was saved.
        $user = $device->user;
        $workspaceId = (int) $device->getAttribute('workspace_id');
        if ($request->has('design')) {
            // Since 2.0.0-g: {"design": "v2" | "classic" | null}.
            $data = $request->validate(['design' => ['present', 'nullable', Rule::in(InterfaceFlags::DESIGNS)]]);
            $design = $data['design'];
            if ($design === 'v2') {
                $now = $flags->design($user, $workspaceId);
                if (! $now['can_try'] && $now['current'] !== 'v2') {
                    throw new ApiError(422, 'design_not_available', 'The new design is not open to try yet.');
                }
            }
            $flags->setDesign($user, $design);
        } else {
            // 2.0.0-a: {"classic": true | false}.
            $data = $request->validate(['classic' => ['required', 'boolean']]);
            $flags->setClassic($user, (bool) $data['classic']);
        }

        return response()->json(DeviceConfigPayload::uiFlags($user->refresh(), $workspaceId));
    }

    /** GET /api/account/ui (since 2.0.0-g): the flags, ui_classic and the design switch, as GET /api/config has them. */
    public function showUi(Request $request): JsonResponse
    {
        $device = $this->device($request);

        return response()->json(DeviceConfigPayload::uiFlags($device->user, (int) $device->getAttribute('workspace_id')));
    }

    /** POST /api/account/sign-out-everywhere — every device (this one included) and console session of the account. */
    public function signOutEverywhere(Request $request, AccountSessions $sessions): Response
    {
        $sessions->signOutEverywhere($this->owner($request));

        return response()->noContent();
    }

    /** DELETE /api/account — `{"password"}` or `{"otp"}`; erased after 7 days unless "Keep my account" is chosen. */
    public function destroy(Request $request, AccountDeletion $deletion): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required_without:otp', 'nullable', 'string', 'max:4096'],
            'otp' => ['required_without:password', 'nullable', 'string', 'max:64'],
        ]);
        $user = $this->owner($request);
        $deletion->confirm($user, $data['password'] ?? null, $data['otp'] ?? null);
        $deletesAt = $deletion->schedule($user);

        return response()->json(['deletes_at' => $deletesAt->toAtomString()], 202);
    }

    /** POST /api/account/export — `library:read`: one ZIP of everything the account owns, made in the background. */
    public function export(Request $request, AccountExports $exports): JsonResponse
    {
        return response()->json($exports->start($this->owner($request))->toApi(), 202);
    }

    /** GET /api/account/export/{id} — `library:read`. */
    public function exportStatus(Request $request, string $id): JsonResponse
    {
        $export = AccountExport::query()->where('user_id', $this->device($request)->user_id)->find($id);
        if (! $export instanceof AccountExport) {
            throw new ApiError(404, 'not_found', 'Not found.');
        }

        return response()->json($export->toApi());
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
