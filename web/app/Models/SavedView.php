<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\OwnedByMaker;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One of a person's saved Library views in one workspace (contract 2.0.0-p, My views): the filters, the search and how
 * the list is shown, under a name and in a place in the sidebar. Never a recording or any of its words. The person's
 * own, as their lists are: a teammate in the same workspace has views of their own.
 *
 * @property string $id
 * @property int $workspace_id
 * @property int $user_id
 * @property string|null $client_ref
 * @property string $name
 * @property array<string, array{op: string, values: list<string>, from?: string, to?: string}> $filters
 * @property string|null $search
 * @property string $sort
 * @property string $group_by
 * @property string $density
 * @property int $position
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class SavedView extends Model
{
    use BelongsToWorkspace, HasUlids, OwnedByMaker;

    protected $fillable = ['workspace_id', 'user_id', 'client_ref', 'name', 'filters', 'search', 'sort', 'group_by', 'density', 'position'];

    protected function casts(): array
    {
        return ['filters' => 'array', 'position' => 'integer', 'workspace_id' => 'integer', 'user_id' => 'integer'];
    }
}
