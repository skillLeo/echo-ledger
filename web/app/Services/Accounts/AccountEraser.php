<?php

declare(strict_types=1);

namespace App\Services\Accounts;

use App\Enums\WorkspaceRole;
use App\Models\AccountExport;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\ClientFile;
use App\Models\Device;
use App\Models\RequestLink;
use App\Models\RequestUpload;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AuditLog;
use App\Services\Connector\ConnectedApps;
use App\Services\Projects\ProjectTree;
use App\Services\Sharing\RequestLinks;
use App\Services\Storage\RecordingFiles;
use App\Services\WebSessions;
use App\Services\Workspaces\Workspaces;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Erases an account at the end of its 7-day grace (since v1.5, `echoledger:erase-accounts`): its recordings in every
 * workspace with their audio, transcripts and screens (CaptureSession::purgeMedia(), so the encrypted files go too),
 * its projects with their files (ProjectTree::delete(), which moves anyone else's recordings in them to the Inbox),
 * the files it added to teammates' projects, its request links, exports, devices and tokens, its console sessions;
 * every workspace only it belongs to with everything still in it; then the account. Workspace rows are deleted only once they are empty, never to let foreign
 * keys cascade over encrypted files. Provider copies were erased when the recordings were transcribed or deleted; a
 * live recording is deleted first so ProviderCopyEraser runs for it too. Safe to run again after a failure.
 */
final class AccountEraser
{
    public function __construct(
        private readonly ProjectTree $projects,
        private readonly RequestLinks $requestLinks,
        private readonly Workspaces $workspaces,
        private readonly AuditLog $audit,
    ) {}

    /** @return array{recordings: int, projects: int, workspaces: int} */
    public function erase(User $user): array
    {
        $this->handOverTeams($user);
        $solo = $this->soloWorkspaces($user);

        $recordings = 0;
        CaptureSession::withTrashed()->withoutGlobalScopes()
            ->where(fn ($q) => $q->where('user_id', $user->id)->orWhereIn('workspace_id', $solo))
            ->chunkById(100, function (Collection $sessions) use (&$recordings): void {
                foreach ($sessions as $session) {
                    /** @var CaptureSession $session */
                    if (! $session->trashed()) {
                        $session->delete(); // erases the provider's copy, as every delete does
                    }
                    $session->purgeMedia();
                    $session->forceDelete();
                    $recordings++;
                }
            });

        $projects = 0;
        $clients = Client::query()->withoutGlobalScopes()->where(fn ($q) => $q->where('user_id', $user->id)->orWhereIn('workspace_id', $solo))->pluck('id');
        foreach ($clients as $id) {
            $client = Client::query()->withoutGlobalScopes()->find($id);
            if ($client instanceof Client) {
                $this->projects->delete($client);
                $projects++;
            }
        }

        // Files they added to a teammate's project. Their rows would go with the account by its foreign key and leave
        // the encrypted originals on the disk, so each goes here, file first.
        ClientFile::query()->withoutGlobalScopes()->where('user_id', $user->id)->lazyById(100)
            ->each(function (ClientFile $file): void {
                self::deleteFile($file->path);
                $file->delete();
            });

        RequestLink::query()->withoutGlobalScopes()->where(fn ($q) => $q->where('user_id', $user->id)->orWhereIn('workspace_id', $solo))->get()
            ->each(function (RequestLink $link): void {
                RequestUpload::query()->where('request_link_id', $link->id)->where('status', 'uploading')->get()
                    ->each(fn (RequestUpload $upload) => $this->requestLinks->abandon($upload));
                $link->delete();
            });

        AccountExport::query()->where('user_id', $user->id)->get()->each(function (AccountExport $export): void {
            self::deleteFile($export->path);
            $export->delete();
        });

        Device::query()->withoutGlobalScopes()->where(fn ($q) => $q->where('user_id', $user->id)->orWhereIn('workspace_id', $solo))->get()
            ->each(fn (Device $device) => $device->delete());

        foreach ($user->workspaces()->whereNotIn('workspaces.id', $solo)->get() as $team) {
            $this->workspaces->removeMember($team, $user);
        }
        // Only empty rows are left in them now (members, plans' usage rows, settings of the workspace).
        Workspace::query()->whereIn('id', $solo)->get()->each(fn (Workspace $workspace) => $workspace->delete());

        if (WebSessions::available()) {
            DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
        $user->notifications()->delete();
        // The connector's tables have no foreign key to the account: every token and code of the person ends here.
        app(ConnectedApps::class)->disconnectAll($user);
        $id = $user->id;
        $user->delete();
        $this->audit->record('account_erased', null, null, "Account #{$id} erased after its 7 days", ['recordings' => $recordings, 'projects' => $projects, 'workspaces' => count($solo)]);

        return ['recordings' => $recordings, 'projects' => $projects, 'workspaces' => count($solo)];
    }

    /**
     * A workspace the account owns alone keeps an owner: the member who joined first becomes one. (Deleting is refused
     * while the account owns a team; this covers members who joined during the 7 days.)
     */
    private function handOverTeams(User $user): void
    {
        foreach ($user->workspaces()->wherePivot('role', WorkspaceRole::Owner->value)->get() as $workspace) {
            $others = DB::table('workspace_user')->where('workspace_id', $workspace->id)->where('user_id', '!=', $user->id);
            if ((clone $others)->where('role', WorkspaceRole::Owner->value)->exists()) {
                continue;
            }
            $heir = (clone $others)->orderBy('created_at')->orderBy('user_id')->value('user_id');
            if ($heir !== null) {
                DB::table('workspace_user')->where('workspace_id', $workspace->id)->where('user_id', $heir)->update(['role' => WorkspaceRole::Owner->value]);
                $this->audit->record('role_changed', (int) $heir, $workspace, "Became an owner of {$workspace->name}: its owner's account was deleted");
            }
        }
    }

    /** @return list<int> the workspaces nobody else belongs to */
    private function soloWorkspaces(User $user): array
    {
        return $user->workspaces()->get()
            ->filter(fn (Workspace $w): bool => ! DB::table('workspace_user')->where('workspace_id', $w->id)->where('user_id', '!=', $user->id)->exists())
            ->map(fn (Workspace $w): int => $w->id)->values()->all();
    }

    private static function deleteFile(?string $path): void
    {
        app(RecordingFiles::class)->erase($path);
    }
}
