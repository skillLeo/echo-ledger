<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Services\Workspaces\ConsoleAccess;

/**
 * UX-22 3b on the web console, past the Library's rows: what the right-click menus of the other objects need (a view, a
 * label, a project, an Activity item, a recording in Trash, Trash itself), for the page or panel that draws them
 * (filament.v2.object-menus; resources/js/context-menu-core.cjs objectMenu decides what is in each). The actions are
 * actions.json's, in the console's language where it has their words; `runnable` is what that component can do for
 * each kind, and nothing in a read-only console.
 */
final class ObjectMenus
{
    /**
     * @param  array<string, list<string>>  $runnable  kind => the action ids the component runs
     * @return array{actions: list<array<string, mixed>>, runnable: array<string, list<string>>, say: array<string, string>, colours: list<array{value: string, label: string}>, words: array<string, string>}
     */
    public static function data(array $runnable, bool $colours = false): array
    {
        $runnable = ConsoleAccess::readOnly() ? [] : $runnable;
        $ids = array_values(array_unique(array_merge([], ...array_values($runnable))));
        /** @var array{actions: list<array<string, mixed>>} $list */
        $list = json_decode((string) file_get_contents(resource_path('js/actions.json')), true);
        $actions = array_values(array_filter($list['actions'], fn (array $a): bool => in_array($a['id'] ?? null, $ids, true)));
        $say = [];
        foreach ($actions as $a) {
            foreach ([$a['label'] ?? null, $a['state']['on'] ?? null] as $words) {
                if (is_string($words) && trans()->has($words)) {
                    $say[$words] = __($words);
                }
            }
        }

        return [
            'actions' => $actions,
            'runnable' => $runnable,
            'say' => $say,
            'colours' => $colours ? array_map(fn (string $name): array => ['value' => $name, 'label' => __(ucfirst($name))], array_keys(V2RecordingRow::LABEL_COLOURS)) : [],
            'words' => ['menu' => __('Actions')],
        ];
    }

    /**
     * One action of a kind the component runs, from an object's menu: the pick as it was sent, checked.
     *
     * @param  array<string, list<string>>  $runnable
     */
    public static function picked(array $runnable, mixed $kind, mixed $action): bool
    {
        return ! ConsoleAccess::readOnly() && is_string($kind) && is_string($action) && in_array($action, $runnable[$kind] ?? [], true);
    }
}
