<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Services\Workspaces\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Second layer of privacy (since v1.5, docs/API.md "Workspaces, roles and privacy"): a person's recordings and
 * projects are theirs alone, owners and admins included, so whenever someone is signed in only the rows they made are
 * seen. Request paths filter by `user_id` themselves as well; shares and Send to a teammate are the only ways across,
 * and they leave this scope out on purpose (by the share's or the sent item's explicit id).
 *
 * @implements Scope<Model>
 */
final class OwnerScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $id = CurrentWorkspace::userId();
        if ($id !== null) {
            $builder->where($model->qualifyColumn('user_id'), $id);
        }
    }
}
