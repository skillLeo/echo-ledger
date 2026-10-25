<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Services\Workspaces\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** Second layer of tenant isolation: rows of the current workspace only, whenever there is one. */
final class WorkspaceScope implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $id = CurrentWorkspace::id();
        if ($id !== null) {
            $builder->where($model->qualifyColumn('workspace_id'), $id);
        }
    }
}
