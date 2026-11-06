<?php

declare(strict_types=1);

namespace App\Services\Projects;

use App\Exceptions\ApiError;
use App\Http\Controllers\Api\ConfigController;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\ClientFile;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Storage\RecordingFiles;
use App\Services\Workspaces\Workspaces;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Projects with subfolders (docs/API.md "Projects", since v1.5). A project (a `clients` row) sits at the top of its
 * workspace or inside another project, at most Client::MAX_DEPTH levels deep and never inside itself; names are
 * unique among the projects of one folder. Recordings in no project are the Inbox. Every lookup is by the explicit
 * workspace id, like the rest of the library, and since v1.5 by the person: projects are private to whoever made them,
 * so a tree is one person's projects in one workspace.
 */
final class ProjectTree
{
    public const NAME_TAKEN = 'A project with this name is already in this folder.';

    public function __construct(private readonly Workspaces $workspaces) {}

    /**
     * Parent of every project of one person in the workspace (every person's when none is named), by id.
     *
     * @return array<string, string|null>
     */
    public function parents(int $workspaceId, ?int $userId = null): array
    {
        return Client::acrossPeople()->where('workspace_id', $workspaceId)->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->pluck('parent_id', 'id')->map(fn ($p): ?string => $p === null ? null : (string) $p)->all();
    }

    /**
     * The project's subfolders at every level below it (not the project itself).
     *
     * @return list<string>
     */
    public function descendantIds(Client $project): array
    {
        return self::below($project->id, $this->parents($project->workspace_id, $project->user_id));
    }

    /**
     * The project and every subfolder below it.
     *
     * @return list<string>
     */
    public function withDescendants(Client $project): array
    {
        return [$project->id, ...$this->descendantIds($project)];
    }

    /**
     * @param  array<string, string|null>  $parents
     * @return list<string>
     */
    public static function below(string $id, array $parents): array
    {
        $children = [];
        foreach ($parents as $child => $parent) {
            if ($parent !== null) {
                $children[$parent][] = (string) $child;
            }
        }
        $found = [];
        $queue = $children[$id] ?? [];
        while ($queue !== []) {
            $next = array_shift($queue);
            if (in_array($next, $found, true) || $next === $id) {
                continue; // a cycle in stored data must not loop for ever
            }
            $found[] = $next;
            array_push($queue, ...($children[$next] ?? []));
        }

        return $found;
    }

    /**
     * The levels from the top down to this project: 1 for a top-level project.
     *
     * @param  array<string, string|null>  $parents
     */
    public static function depth(string $id, array $parents): int
    {
        $depth = 1;
        $seen = [$id];
        while (($parent = $parents[$id] ?? null) !== null && ! in_array($parent, $seen, true)) {
            $seen[] = $parent;
            $id = $parent;
            $depth++;
        }

        return $depth;
    }

    /**
     * How many levels the project and its subfolders take: 1 without subfolders.
     *
     * @param  array<string, string|null>  $parents
     */
    public static function height(string $id, array $parents): int
    {
        $height = 1;
        foreach (self::below($id, $parents) as $descendant) {
            $height = max($height, self::depth($descendant, $parents) - self::depth($id, $parents) + 1);
        }

        return $height;
    }

    /**
     * The ancestors from the top, as the API shows them: `[{"id", "name"}]`, empty for a top-level project.
     *
     * @param  array<string, array{parent_id: string|null, name: string}>  $nodes
     * @return list<array{id: string, name: string}>
     */
    public static function pathOf(string $id, array $nodes): array
    {
        $path = [];
        $seen = [$id];
        $parent = $nodes[$id]['parent_id'] ?? null;
        while ($parent !== null && isset($nodes[$parent]) && ! in_array($parent, $seen, true)) {
            $seen[] = $parent;
            array_unshift($path, ['id' => $parent, 'name' => $nodes[$parent]['name']]);
            $parent = $nodes[$parent]['parent_id'];
        }

        return $path;
    }

    /**
     * The project of the workspace a `parent_id` names, or null for the top level; another workspace's or an unknown
     * id is `404 client_not_found` (the same as every other client id).
     */
    public function parentFor(int $workspaceId, mixed $parentId): ?Client
    {
        if ($parentId === null || $parentId === '') {
            return null;
        }
        $parent = Client::query()->withoutGlobalScope(WorkspaceScope::class)->where('workspace_id', $workspaceId)->ofPerson()->find((string) $parentId);
        if (! $parent instanceof Client) {
            throw new ApiError(404, 'client_not_found', 'Client not found.');
        }

        return $parent;
    }

    /**
     * Whether $project (null: a new one) may go into $parent: never into itself or one of its own subfolders
     * (`422 invalid_parent`), and never deeper than Client::MAX_DEPTH levels with its subfolders (`422 too_deep`).
     */
    public function assertCanPlace(?Client $project, ?Client $parent): void
    {
        if ($parent === null) {
            return;
        }
        $parents = $this->parents($parent->workspace_id, $parent->user_id);
        if ($project !== null && ($parent->id === $project->id || in_array($parent->id, self::below($project->id, $parents), true))) {
            throw self::invalidParent();
        }
        $height = $project !== null ? self::height($project->id, $parents) : 1;
        if (self::depth($parent->id, $parents) + $height > Client::MAX_DEPTH) {
            throw self::tooDeep();
        }
    }

    /**
     * Makes one change to the workspace's projects (a new one, a move, a rename) with every other change of them
     * waiting: the workspace row stays locked until the transaction ends, so the checks inside $change see every
     * change made before it. Then the project is checked where it ended up (never in a loop, at most MAX_DEPTH levels,
     * its name free in its folder), which refuses whatever still slipped past; a refusal rolls the change back.
     *
     * @param  Closure(): Client  $change
     */
    public function place(int $workspaceId, Closure $change): Client
    {
        return DB::transaction(function () use ($workspaceId, $change): Client {
            self::lock($workspaceId);
            $project = $change();
            $this->assertPlaced($project);

            return $project;
        });
    }

    /** Holds the other changes of the workspace's projects until the transaction ends (SQLite writes one at a time). */
    public static function lock(int $workspaceId): void
    {
        Workspace::query()->whereKey($workspaceId)->lockForUpdate()->first();
    }

    /** The checks of assertCanPlace() and assertNameFree() for a project just made, moved or renamed, where it now is. */
    public function assertPlaced(Client $project): void
    {
        $moved = $project->wasRecentlyCreated || $project->wasChanged('parent_id');
        if ($moved) {
            $parents = $this->parents($project->workspace_id, $project->user_id);
            if (self::inLoop($project->id, $parents)) {
                throw self::invalidParent();
            }
            if (self::depth($project->id, $parents) + self::height($project->id, $parents) - 1 > Client::MAX_DEPTH) {
                throw self::tooDeep();
            }
        }
        if ($moved || $project->wasChanged('name')) {
            $this->assertNameFree($project->workspace_id, $project->parent_id, $project->name, $project->id, $project->user_id);
        }
    }

    /**
     * True when the folders above the project never reach the top: going up comes back to one already passed.
     *
     * @param  array<string, string|null>  $parents
     */
    public static function inLoop(string $id, array $parents): bool
    {
        $seen = [$id];
        for ($parent = $parents[$id] ?? null; $parent !== null; $parent = $parents[$parent] ?? null) {
            if (in_array($parent, $seen, true)) {
                return true;
            }
            $seen[] = $parent;
        }

        return false;
    }

    private static function invalidParent(): ApiError
    {
        return new ApiError(422, 'invalid_parent', 'A project cannot go inside itself or one of its own subfolders.', ['errors' => ['parent_id' => ['Pick another folder.']]]);
    }

    private static function tooDeep(): ApiError
    {
        return new ApiError(422, 'too_deep', 'Projects nest at most '.Client::MAX_DEPTH.' levels.', ['errors' => ['parent_id' => ['Pick a higher folder.']]]);
    }

    /**
     * True when another project of the same folder already has this name (capitals ignored): one person's projects
     * (the request's person when none is named), as each person's top level is their own.
     */
    public function nameTaken(int $workspaceId, ?string $parentId, string $name, ?string $ignoreId = null, ?int $userId = null): bool
    {
        return Client::query()->withoutGlobalScope(WorkspaceScope::class)->where('workspace_id', $workspaceId)->ofPerson($userId)
            ->where(fn ($q) => $parentId === null ? $q->whereNull('parent_id') : $q->where('parent_id', $parentId))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
    }

    /** `422 validation_failed` on `name` when the folder already has a project of that name. */
    public function assertNameFree(int $workspaceId, ?string $parentId, string $name, ?string $ignoreId = null, ?int $userId = null): void
    {
        if ($this->nameTaken($workspaceId, $parentId, $name, $ignoreId, $userId)) {
            throw new ApiError(422, 'validation_failed', self::NAME_TAKEN, ['errors' => ['name' => [self::NAME_TAKEN]]]);
        }
    }

    /** "Notes", or "Notes (2)" when the folder already has a "Notes" (moving subfolders up, saving a copy). */
    public function freeName(int $workspaceId, ?string $parentId, string $name, ?string $ignoreId = null, ?int $userId = null): string
    {
        $base = mb_substr(trim($name), 0, 110);
        $candidate = $base;
        for ($n = 2; $this->nameTaken($workspaceId, $parentId, $candidate, $ignoreId, $userId); $n++) {
            $candidate = "{$base} ({$n})";
        }

        return $candidate;
    }

    /**
     * Since v1.5 only the person who made a project deletes it (owners and admins do not see it), while they belong
     * to its workspace; never across workspaces.
     */
    public function canDelete(User $actor, Client $project): bool
    {
        return $this->workspaces->roleOf($actor, $project->workspace_id)?->records() === true && (int) $project->user_id === (int) $actor->getKey();
    }

    /**
     * Deletes a project. Its recordings go to the Inbox (nothing is deleted; tombstones too, so a restore lands in the
     * Inbox), its subfolders move up to its parent (renamed "Name (2)" when that folder has the name already), and its
     * files (with their encrypted originals), brief, call notes, memory, contacts, keyterms, rules and share links go
     * with it. `403 not_allowed` for a member.
     *
     * @return array{moved_sessions: int, moved_projects: int}
     */
    public function delete(Client $project, ?User $actor = null): array
    {
        if ($actor !== null && ! $this->canDelete($actor, $project)) {
            throw new ApiError(403, 'not_allowed', 'Only the person who made a project can delete it.');
        }
        $paths = [];

        $result = DB::transaction(function () use ($project, &$paths): array {
            // No subfolder, name or file of the project changes meanwhile (moving them up checks names in the parent).
            self::lock($project->workspace_id);
            $paths = ClientFile::query()->withoutGlobalScope(WorkspaceScope::class)->where('client_id', $project->id)
                ->whereNotNull('path')->pluck('path')->map(fn ($p): string => (string) $p)->all();
            $now = now();
            $sessions = CaptureSession::query()->withoutGlobalScopes()->where('client_id', $project->id);
            $moved = (clone $sessions)->whereNull('deleted_at')->count();
            // Bumping updated_at makes every device pick the move up on its next sync.
            $sessions->update(['client_id' => null, 'filed_by' => null, 'updated_at' => $now]);

            /** @var Collection<int, Client> $children */
            $children = Client::acrossPeople()->where('parent_id', $project->id)->orderBy('name')->get();
            foreach ($children as $child) {
                $child->forceFill([
                    'parent_id' => $project->parent_id,
                    'name' => $this->freeName($project->workspace_id, $project->parent_id, $child->name, $child->id, $child->user_id),
                ])->save();
            }
            $project->delete();

            return ['moved_sessions' => $moved, 'moved_projects' => $children->count()];
        });

        // The rows are gone; the encrypted originals of the project's files go after the commit.
        app(RecordingFiles::class)->erase($paths);

        return $result;
    }

    /**
     * Every project of one person in the workspace (the request's person when none is named) as devices see it,
     * pinned first, then by name.
     *
     * @return list<array<string, mixed>>
     */
    public function payloads(int $workspaceId, ?int $userId = null): array
    {
        $clients = Client::query()->withoutGlobalScope(WorkspaceScope::class)->where('workspace_id', $workspaceId)->ofPerson($userId)
            ->withCount(['keyterms', 'captureSessions'])->orderByDesc('pinned')->orderBy('name')->get();

        return array_values(array_map(fn (Client $c): array => self::payloadIn($c, $clients), $clients->all()));
    }

    /**
     * One project as devices see it, with its place in the tree.
     *
     * @return array<string, mixed>
     */
    public function payload(Client $client): array
    {
        $clients = Client::acrossPeople()->where('workspace_id', $client->workspace_id)->where('user_id', $client->user_id)
            ->withCount(['keyterms', 'captureSessions'])->get();
        $fresh = $clients->firstWhere('id', $client->id) ?? $client->loadCount('keyterms', 'captureSessions');

        return self::payloadIn($fresh, $clients);
    }

    /**
     * @param  Collection<int, Client>  $all  every project of the workspace, with `withCount` counts
     * @return array<string, mixed>
     */
    private static function payloadIn(Client $client, Collection $all): array
    {
        $nodes = [];
        $parents = [];
        $counts = [];
        foreach ($all as $c) {
            $nodes[$c->id] = ['parent_id' => $c->parent_id, 'name' => $c->name];
            $parents[$c->id] = $c->parent_id;
            $counts[$c->id] = (int) $c->getAttribute('capture_sessions_count');
        }
        $nested = (int) $client->getAttribute('capture_sessions_count');
        foreach (self::below($client->id, $parents) as $descendant) {
            $nested += $counts[$descendant] ?? 0;
        }

        return [
            ...ConfigController::clientPayload($client),
            // Since v1.5: its place in the tree, its subfolders and the recordings in it and below it.
            'parent_id' => $client->parent_id,
            'path' => self::pathOf($client->id, $nodes),
            'children_count' => count(array_filter($parents, fn (?string $p): bool => $p === $client->id)),
            'nested_session_count' => $nested,
        ];
    }
}
