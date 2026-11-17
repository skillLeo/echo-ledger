<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\WorkspaceRole;
use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceInviteLink;
use App\Services\Accounts\Invitations;
use App\Services\Accounts\InviteLinks;
use App\Services\AuditLog;
use App\Services\Workspaces\PlanLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Inviting people to the device's workspace (v1.8 P6.3, docs/API.md 2.0.0-d): the seats, the open email invitations
 * and invite links; invite by email for 1, 7 or 30 days; make an invite link (members or viewers only, shown once);
 * cancel an invitation; revoke a link. Owners and admins only (admins give members and viewers): `403 not_allowed`
 * for anyone else.
 */
final class WorkspaceInvitesController extends Controller
{
    public function __construct(private readonly Invitations $invitations, private readonly InviteLinks $links, private readonly PlanLimits $limits) {}

    /** GET /api/workspace/invitations — `config:read`. */
    public function index(Request $request): JsonResponse
    {
        [$workspace] = $this->allowed($request);
        $seats = $this->limits->summary($workspace->id)['seats'];

        return response()->json([
            'seats' => ['used' => $seats['used'], 'limit' => $seats['limit']],
            'invitations' => WorkspaceInvitation::query()->where('workspace_id', $workspace->id)->whereNull('accepted_at')->where('expires_at', '>', now())
                ->orderBy('expires_at')->limit(100)->get()->map(fn (WorkspaceInvitation $i): array => [
                    'id' => $i->id, 'email' => $i->email, 'role' => $i->role->value, 'expires_at' => $i->expires_at->toAtomString(), 'created_at' => $i->created_at?->toAtomString(),
                ])->values()->all(),
            'links' => WorkspaceInviteLink::query()->where('workspace_id', $workspace->id)->whereNull('revoked_at')->where('expires_at', '>', now())
                ->orderBy('expires_at')->limit(100)->get()->filter(fn (WorkspaceInviteLink $l): bool => $l->usable())
                ->map(fn (WorkspaceInviteLink $l): array => $l->toApi())->values()->all(),
        ]);
    }

    /** POST /api/workspace/invitations `{"email", "role", "days"}` — `sessions:write`, 20 an hour. */
    public function store(Request $request): JsonResponse
    {
        [$workspace, $user] = $this->allowed($request);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in([WorkspaceRole::Admin->value, WorkspaceRole::Member->value, WorkspaceRole::Viewer->value])],
            'days' => ['sometimes', 'integer', Rule::in(InviteLinks::DAYS)],
        ]);
        $role = $this->role($user, $workspace, (string) $data['role']);
        try {
            $this->invitations->invite($workspace, $user, (string) $data['email'], $role, (int) ($data['days'] ?? Invitations::DAYS));
        } catch (InvalidArgumentException $e) {
            throw new ApiError(422, 'validation_failed', $e->getMessage(), ['errors' => ['email' => [$e->getMessage()]]]);
        }
        $invitation = WorkspaceInvitation::query()->where('workspace_id', $workspace->id)->where('email', mb_strtolower(trim((string) $data['email'])))
            ->whereNull('accepted_at')->latest('id')->firstOrFail();

        return response()->json(['invitation' => [
            'id' => $invitation->id, 'email' => $invitation->email, 'role' => $invitation->role->value,
            'expires_at' => $invitation->expires_at->toAtomString(), 'created_at' => $invitation->created_at?->toAtomString(),
        ]], 201);
    }

    /** DELETE /api/workspace/invitations/{id} — `sessions:write`: its link stops working and its seat is free. */
    public function destroy(Request $request, string $id): Response
    {
        [$workspace, $user] = $this->allowed($request);
        $invitation = ctype_digit($id) ? WorkspaceInvitation::query()->where('workspace_id', $workspace->id)->whereNull('accepted_at')->find((int) $id) : null;
        if (! $invitation instanceof WorkspaceInvitation) {
            throw new NotFoundHttpException;
        }
        $invitation->delete();
        app(AuditLog::class)->record('invitation_revoked', $user->id, $workspace, "Took back the invitation of {$invitation->email}");

        return response()->noContent();
    }

    /** POST /api/workspace/invite-links `{"role", "days", "max_uses"}` — `sessions:write`: `url` is in this answer only. */
    public function storeLink(Request $request): JsonResponse
    {
        [$workspace, $user] = $this->allowed($request);
        $data = $request->validate([
            'role' => ['required', Rule::in([WorkspaceRole::Member->value, WorkspaceRole::Viewer->value])],
            'days' => ['sometimes', 'integer', Rule::in(InviteLinks::DAYS)],
            'max_uses' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.InviteLinks::MAX_USES],
        ]);
        $role = $this->role($user, $workspace, (string) $data['role']);
        ['link' => $link, 'url' => $url] = $this->links->create($workspace, $user, $role, (int) ($data['days'] ?? 7), isset($data['max_uses']) ? (int) $data['max_uses'] : null);

        return response()->json(['link' => $link->fresh()?->toApi(), 'url' => $url], 201)->header('Cache-Control', 'no-store');
    }

    /** DELETE /api/workspace/invite-links/{id} — `sessions:write`: it stops working; people who joined stay. */
    public function destroyLink(Request $request, string $id): Response
    {
        [$workspace, $user] = $this->allowed($request);
        $link = ctype_digit($id) ? WorkspaceInviteLink::query()->where('workspace_id', $workspace->id)->find((int) $id) : null;
        if (! $link instanceof WorkspaceInviteLink) {
            throw new NotFoundHttpException;
        }
        $this->links->revoke($link, $user);

        return response()->noContent();
    }

    /** @return array{0: Workspace, 1: User} the device's workspace and person, when they may invite */
    private function allowed(Request $request): array
    {
        /** @var Device $device */
        $device = $request->user();
        $workspace = Workspace::query()->findOrFail($device->workspace_id);
        $user = $device->user;
        if (! Gate::forUser($user)->allows('invite', [$workspace])) {
            throw new ApiError(403, 'not_allowed', 'Only the owner and the admins invite people to this workspace.');
        }

        return [$workspace, $user];
    }

    private function role(User $user, Workspace $workspace, string $value): WorkspaceRole
    {
        $role = WorkspaceRole::from($value);
        if (! Gate::forUser($user)->allows('assignRole', [$workspace, $role])) {
            throw new ApiError(403, 'not_allowed', 'You cannot give that role.');
        }

        return $role;
    }
}
