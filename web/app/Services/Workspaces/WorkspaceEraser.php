<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Device;
use App\Models\DevicePairingCode;
use App\Models\RequestLink;
use App\Models\RequestUpload;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AuditLog;
use App\Services\Projects\ProjectTree;
use App\Services\Sharing\RequestLinks;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/**
 * Deleting a workspace (since v1.5, its owner only): allowed once nobody else is in it and it is not the owner's only
 * workspace. Everything still in it goes, recordings with their encrypted audio and screens (purgeMedia()), projects
 * with their files, request links, devices and the usage rows, the same way an erased account's own workspace goes;
 * what people removed from it had made goes too. Then the row, whose other tables cascade.
 */
final class WorkspaceEraser
{
    public function __construct(
        private readonly ProjectTree $projects,
        private readonly RequestLinks $requestLinks,
        private readonly Workspaces $workspaces,
        private readonly AuditLog $audit,
    ) {}

    public function assertCanDelete(Workspace $workspace, User $owner): void
    {
        if ($workspace->members()->whereKeyNot($owner->getKey())->exists()) {
            throw new InvalidArgumentException('Remove everyone else first: deleting a workspace deletes everything in it.');
        }
        if ($owner->workspaces()->count() <= 1) {
            throw new InvalidArgumentException('This is your only workspace.');
        }
    }

    public function erase(Workspace $workspace, User $owner): void
    {
        $this->assertCanDelete($workspace, $owner);
        $id = $workspace->id;
        CaptureSession::acrossPeople()->withTrashed()->where('workspace_id', $id)
            ->chunkById(100, function (Collection $sessions): void {
                foreach ($sessions as $session) {
                    /** @var CaptureSession $session */
                    if (! $session->trashed()) {
                        $session->delete(); // erases the provider's copy, as every delete does
                    }
                    $session->purgeMedia();
                    $session->forceDelete();
                }
            });
        foreach (Client::acrossPeople()->where('workspace_id', $id)->pluck('id') as $projectId) {
            $project = Client::acrossPeople()->find($projectId);
            if ($project instanceof Client) {
                $this->projects->delete($project);
            }
        }
        RequestLink::acrossPeople()->where('workspace_id', $id)->get()->each(function (RequestLink $link): void {
            RequestUpload::query()->where('request_link_id', $link->id)->where('status', 'uploading')->get()
                ->each(fn (RequestUpload $upload) => $this->requestLinks->abandon($upload));
            $link->delete();
        });
        Device::query()->withoutGlobalScopes()->where('workspace_id', $id)->get()->each(fn (Device $device) => $device->delete());
        DevicePairingCode::query()->withoutGlobalScopes()->where('workspace_id', $id)->delete();
        // Its usage rows stay, without the workspace (the foreign key sets it to null): costs already paid stay on
        // Server usage.

        $name = $workspace->name;
        $workspace->members()->detach();
        $workspace->delete();
        $owner->forceFill(['current_workspace_id' => null])->save();
        $this->workspaces->current($owner);
        $this->audit->record('workspace_deleted', $owner->id, null, "Deleted the workspace {$name}");
    }
}
