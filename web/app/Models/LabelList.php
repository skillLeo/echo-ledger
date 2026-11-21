<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One of a person's lists in one workspace (contract 2.0.0-m): a label with the name as typed, its colour and its place
 * in the apps' list row. `label_key` is the label in lower case, so "Clients" and "clients" are one list. Lists belong to
 * the person, as their labels do: a teammate in the same workspace has lists of their own. Since 2.0.0-m a label's
 * colour lives here (label_colors keeps what it had before, for a rollback).
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $user_id
 * @property string $label_key
 * @property string $name
 * @property string|null $color "#rrggbb", lower case
 * @property int $position
 */
class LabelList extends Model
{
    protected $fillable = ['workspace_id', 'user_id', 'label_key', 'name', 'color', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'workspace_id' => 'integer', 'user_id' => 'integer'];
    }
}
