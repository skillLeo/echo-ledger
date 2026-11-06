<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Scopes\OwnerScope;
use App\Models\Scopes\WorkspaceScope;
use App\Services\Workspaces\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;

/**
 * Content that belongs to the person who made it (since v1.5): the OwnerScope next to the WorkspaceScope. Code that
 * crosses people on purpose (a share link, an item sent to a teammate, plan storage of the whole workspace) asks
 * for `acrossPeople()` and filters by explicit ids itself.
 *
 * @property int $user_id
 */
trait OwnedByMaker
{
    public static function bootOwnedByMaker(): void
    {
        static::addGlobalScope(new OwnerScope);
    }

    /**
     * The person's own rows, said in the query itself (the OwnerScope is the second layer): the given person, else the
     * person of the request; with neither (a job, a webhook) every row, as the caller names them by explicit ids.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOfPerson(Builder $query, ?int $userId = null): Builder
    {
        $userId ??= CurrentWorkspace::userId();

        return $userId === null ? $query : $query->where($query->qualifyColumn('user_id'), $userId);
    }

    /**
     * Neither the workspace nor the person of the request: every row, for code that names its rows by explicit ids.
     *
     * @return Builder<static>
     */
    public static function acrossPeople(): Builder
    {
        return static::query()->withoutGlobalScopes([WorkspaceScope::class, OwnerScope::class]);
    }
}
