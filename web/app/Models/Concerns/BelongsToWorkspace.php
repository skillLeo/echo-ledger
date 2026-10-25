<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspaces\CurrentWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A row owned by a workspace (B20): global WorkspaceScope plus a `creating` hook that fills `workspace_id` from
 * the given value, else the current workspace, else the current workspace of the row's `user_id`. With none of
 * them the write fails (it never lands in a guessed workspace).
 *
 * @property int $workspace_id
 * @property-read Workspace $workspace
 */
trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope(new WorkspaceScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('workspace_id') !== null) {
                return;
            }
            $userId = $model->getAttribute('user_id');
            $id = CurrentWorkspace::id()
                ?? ($userId !== null ? User::query()->whereKey($userId)->value('current_workspace_id') : null);
            if ($id === null || (int) $id === CurrentWorkspace::NONE) {
                throw new LogicException(class_basename($model).' needs a workspace.');
            }
            $model->setAttribute('workspace_id', (int) $id);
        });
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
