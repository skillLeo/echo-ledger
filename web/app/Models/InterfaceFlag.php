<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Who one interface flag is on for (since 2.0.0-a; App\Services\InterfaceFlags). Set only by the super admin.
 *
 * @property int $id
 * @property string $flag
 * @property bool $everyone
 * @property int $percent 0–100: the share of accounts, the same accounts every time
 * @property list<int>|null $workspace_ids
 * @property list<int>|null $user_ids
 * @property string|null $availability since 2.0.0-g: off | opt_in | everyone (null: not filled, read as before)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class InterfaceFlag extends Model
{
    protected $fillable = ['flag', 'everyone', 'percent', 'workspace_ids', 'user_ids', 'availability'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'everyone' => 'boolean',
            'percent' => 'integer',
            'workspace_ids' => 'array',
            'user_ids' => 'array',
        ];
    }
}
