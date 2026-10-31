<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A client's one-page brief (v1.4): goal, scope, constraints, deadlines, budget, people and glossary. Made from the
 * client's files (or, with none, from its calls); once a person edits it, it is never rewritten by itself.
 *
 * @property string $id
 * @property string $client_id
 * @property array<string, mixed> $sections
 * @property bool $edited
 * @property string $status ready | working | failed
 * @property string|null $error
 * @property string|null $model
 * @property Carbon|null $generated_at
 * @property Carbon $updated_at
 */
class ClientBrief extends Model
{
    use BelongsToWorkspace, HasUlids;

    /** The sections, in the order they are shown. */
    public const SECTIONS = ['goal', 'scope', 'constraints', 'deadlines', 'budget', 'people', 'glossary'];

    protected $fillable = ['workspace_id', 'client_id', 'sections', 'edited', 'status', 'error', 'model', 'generated_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sections' => 'array', 'edited' => 'boolean', 'generated_at' => 'datetime'];
    }

    /**
     * An empty brief: every section present, none filled. The goal is one line; the others are lists of short lines.
     *
     * @return array<string, mixed>
     */
    public static function emptySections(): array
    {
        return ['goal' => '', 'scope' => [], 'constraints' => [], 'deadlines' => [], 'budget' => [], 'people' => [], 'glossary' => []];
    }

    /**
     * Keeps only the known sections, trimmed: a goal of at most 300 characters and lists of at most 20 lines of 300.
     *
     * @return array<string, mixed>
     */
    public static function clean(mixed $sections): array
    {
        $sections = is_array($sections) ? $sections : [];
        $out = self::emptySections();
        $out['goal'] = mb_substr(trim(is_string($sections['goal'] ?? null) ? $sections['goal'] : ''), 0, 300);
        foreach (array_slice(self::SECTIONS, 1) as $key) {
            $lines = is_array($sections[$key] ?? null) ? $sections[$key] : [];
            $out[$key] = array_values(array_slice(array_filter(array_map(
                fn (mixed $line): string => mb_substr(trim(is_string($line) ? $line : ''), 0, 300),
                $lines,
            ), fn (string $line): bool => $line !== ''), 0, 20));
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'sections' => self::clean($this->sections),
            'edited' => $this->edited,
            'status' => $this->status,
            'error' => $this->error,
            'generated_at' => $this->generated_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at->toIso8601ZuluString(),
        ];
    }
}
