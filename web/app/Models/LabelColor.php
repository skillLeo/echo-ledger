<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The colour of one person's label in one workspace (contract 2.0.0-i): every recording of theirs with that label shows
 * it. `label_key` is the label in lower case, so "Invoice" and "invoice" share one colour. Labels belong to the person
 * who made the recording, so a teammate in the same workspace has colours of their own.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $user_id
 * @property string $label_key
 * @property string $color "#rrggbb", lower case as a project's colour
 */
class LabelColor extends Model
{
    protected $fillable = ['workspace_id', 'user_id', 'label_key', 'color'];
}
