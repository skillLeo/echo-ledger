<?php

declare(strict_types=1);

namespace App\Services\Library;

use App\Exceptions\ApiError;
use App\Models\SavedView;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * A person's saved Library views in one workspace (contract 2.0.0-p, docs/API.md "Saved views"): My views in the
 * sidebar, the same on every device. A view keeps filters (LibraryFilters), a search and how the list is shown (sort,
 * group, density), never content. At most MAX a person in a workspace; every change bumps the person's `views_version`
 * (users.views_version, one number for all their workspaces: a change anywhere only makes the apps read their views
 * again), which the library sync carries.
 *
 * @phpstan-type ViewPayload array{id: string, name: string, filters: object, search: string|null, sort: string, group: string, density: string, position: int, client_ref: string|null, created_at: string, updated_at: string}
 */
final class SavedViews
{
    public const MAX = 30;

    public const MAX_NAME = 60;

    public const MAX_SEARCH = 200;

    /** How the list is ordered; an app that offers fewer shows a value it lacks as Newest. */
    public const SORTS = ['newest', 'oldest', 'longest', 'shortest', 'name', 'opened'];

    /** How the list is grouped; an app that offers fewer shows a value it lacks as Date. */
    public const GROUPS = ['date', 'source', 'project', 'language', 'status', 'device', 'none'];

    public const DENSITIES = ['comfortable', 'compact'];

    public const DEFAULTS = ['sort' => 'newest', 'group' => 'date', 'density' => 'comfortable'];

    /**
     * The person's views in their order.
     *
     * @return list<ViewPayload>
     */
    public function index(int $workspaceId, int $userId): array
    {
        return $this->rows($workspaceId, $userId)->map(fn (SavedView $view): array => self::payload($view))->values()->all();
    }

    /** The person's views version: 0 before their first view, then a new number at every change. */
    public function version(int $workspaceId, int $userId): int
    {
        unset($workspaceId);

        return (int) (DB::table('users')->where('id', $userId)->value('views_version') ?? 0);
    }

    /**
     * A new view at the end of My views. The same `client_ref` again answers the view it made, unchanged (`created`
     * false), so a view saved offline is never made twice.
     *
     * @param  array<string, mixed>  $data
     * @return array{view: ViewPayload, created: bool}
     */
    public function create(int $workspaceId, int $userId, array $data): array
    {
        $clean = self::clean($data, true);
        $ref = isset($data['client_ref']) && is_string($data['client_ref']) && $data['client_ref'] !== '' ? $data['client_ref'] : null;

        return DB::transaction(function () use ($workspaceId, $userId, $clean, $ref): array {
            $this->lock($workspaceId, $userId);
            if ($ref !== null) {
                $existing = $this->query($workspaceId, $userId)->where('client_ref', $ref)->first();
                if ($existing instanceof SavedView) {
                    return ['view' => self::payload($existing), 'created' => false];
                }
            }
            $count = $this->query($workspaceId, $userId)->count();
            if ($count >= self::MAX) {
                throw new ApiError(422, 'views_limit', 'You can keep up to '.self::MAX.' views. Delete one to save another.', ['limit' => self::MAX]);
            }
            $view = new SavedView;
            $view->forceFill([
                'workspace_id' => $workspaceId, 'user_id' => $userId, 'client_ref' => $ref, 'position' => $count,
                'name' => $clean['name'], 'filters' => $clean['filters'] ?? [], 'search' => $clean['search'] ?? null,
                'sort' => $clean['sort'] ?? self::DEFAULTS['sort'], 'group_by' => $clean['group'] ?? self::DEFAULTS['group'],
                'density' => $clean['density'] ?? self::DEFAULTS['density'],
            ])->save();
            $this->bump($workspaceId, $userId);

            return ['view' => self::payload($view->refresh()), 'created' => true];
        });
    }

    /**
     * Renames a view and/or changes what it keeps; fields left out stay.
     *
     * @param  array<string, mixed>  $data
     * @return ViewPayload
     */
    public function update(int $workspaceId, int $userId, string $id, array $data): array
    {
        $clean = self::clean($data, false);

        return DB::transaction(function () use ($workspaceId, $userId, $id, $clean): array {
            $this->lock($workspaceId, $userId);
            $view = $this->find($workspaceId, $userId, $id, 'update');
            foreach (['name' => 'name', 'filters' => 'filters', 'search' => 'search', 'sort' => 'sort', 'group' => 'group_by', 'density' => 'density'] as $field => $column) {
                if (array_key_exists($field, $clean)) {
                    $view->setAttribute($column, $clean[$field]);
                }
            }
            if ($view->isDirty()) {
                $view->save();
                $this->bump($workspaceId, $userId);
            }

            return self::payload($view->refresh());
        });
    }

    /**
     * Deletes a view; the ones after it move up.
     *
     * @return array{id: string, views_version: int}
     */
    public function delete(int $workspaceId, int $userId, string $id): array
    {
        return DB::transaction(function () use ($workspaceId, $userId, $id): array {
            $this->lock($workspaceId, $userId);
            $view = $this->find($workspaceId, $userId, $id, 'delete');
            $view->delete();
            $this->renumber($this->rows($workspaceId, $userId));
            $version = $this->bump($workspaceId, $userId);

            return ['id' => $id, 'views_version' => $version];
        });
    }

    /**
     * Puts the person's views in this order: every one of them, once.
     *
     * @param  list<mixed>  $ids
     * @return list<ViewPayload>
     */
    public function order(int $workspaceId, int $userId, array $ids): array
    {
        return DB::transaction(function () use ($workspaceId, $userId, $ids): array {
            $this->lock($workspaceId, $userId);
            $rows = $this->rows($workspaceId, $userId)->keyBy('id');
            $given = array_map(fn (mixed $id): string => is_string($id) ? $id : '', $ids);
            if (count($given) !== $rows->count() || count(array_unique($given)) !== count($given) || array_diff($given, $rows->keys()->all()) !== []) {
                throw new ApiError(422, 'validation_failed', 'Name every one of your views once.', ['errors' => ['ids' => ['Name every one of your views once.']]]);
            }
            $ordered = new Collection(array_map(fn (string $id): SavedView => $rows[$id], $given));
            if ($this->renumber($ordered)) {
                $this->bump($workspaceId, $userId);
            }

            return $this->index($workspaceId, $userId);
        });
    }

    /**
     * A view as the API answers it. `filters` is always an object ({} when it keeps none).
     *
     * @return ViewPayload
     */
    public static function payload(SavedView $view): array
    {
        // Read forgivingly: a view kept before an option was withdrawn still opens, without it.
        $filters = LibraryFilters::read($view->filters);

        return [
            'id' => $view->id,
            'name' => $view->name,
            'filters' => (object) array_map(fn (array $f): object => (object) $f, $filters),
            'search' => $view->search,
            'sort' => $view->sort,
            'group' => $view->group_by,
            'density' => $view->density,
            'position' => $view->position,
            'client_ref' => $view->client_ref,
            'created_at' => $view->created_at->toAtomString(),
            'updated_at' => $view->updated_at->toAtomString(),
        ];
    }

    /**
     * The fields given, checked and cleaned; `name` is required to create.
     *
     * @param  array<string, mixed>  $data
     * @return array{name?: string, filters?: array<string, mixed>, search?: string|null, sort?: string, group?: string, density?: string}
     */
    public static function clean(array $data, bool $creating): array
    {
        $clean = [];
        if ($creating || array_key_exists('name', $data)) {
            $name = is_string($data['name'] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\p{C}+/u', '', $data['name']))) : '';
            if ($name === '' || mb_strlen($name) > self::MAX_NAME) {
                self::fail('name', 'Give the view a name of up to '.self::MAX_NAME.' characters.');
            }
            $clean['name'] = $name;
        }
        if (array_key_exists('filters', $data)) {
            $clean['filters'] = LibraryFilters::normalize($data['filters']);
        }
        if (array_key_exists('search', $data)) {
            $search = $data['search'];
            if ($search !== null && ! is_string($search)) {
                self::fail('search', 'The search is text.');
            }
            $search = $search === null ? '' : trim((string) preg_replace('/\s+/u', ' ', $search));
            if (mb_strlen($search) > self::MAX_SEARCH) {
                self::fail('search', 'The search is at most '.self::MAX_SEARCH.' characters.');
            }
            $clean['search'] = $search === '' ? null : $search;
        }
        foreach (['sort' => self::SORTS, 'group' => self::GROUPS, 'density' => self::DENSITIES] as $field => $allowed) {
            if (array_key_exists($field, $data)) {
                if (! is_string($data[$field]) || ! in_array($data[$field], $allowed, true)) {
                    self::fail($field, "The {$field} must be one of ".implode(', ', $allowed).'.');
                }
                $clean[$field] = $data[$field];
            }
        }

        return $clean;
    }

    /** @return Builder<SavedView> */
    private function query(int $workspaceId, int $userId): Builder
    {
        // Named by the explicit ids (the scopes are the second layer, absent in jobs and the console's own calls).
        return SavedView::query()->withoutGlobalScopes()->where('workspace_id', $workspaceId)->where('user_id', $userId);
    }

    /** @return Collection<int, SavedView> */
    private function rows(int $workspaceId, int $userId): Collection
    {
        return $this->query($workspaceId, $userId)->orderBy('position')->orderBy('created_at')->orderBy('id')->get();
    }

    /** The person's view, or 404 view_not_found (another person's or workspace's view is answered the same). */
    private function find(int $workspaceId, int $userId, string $id, string $ability): SavedView
    {
        $view = $this->query($workspaceId, $userId)->find($id);
        $user = User::query()->find($userId);
        if (! $view instanceof SavedView || ! $user instanceof User || ! Gate::forUser($user)->allows($ability, $view)) {
            throw new ApiError(404, 'view_not_found', 'View not found.');
        }

        return $view;
    }

    /** @param Collection<int, SavedView> $views  whether any place changed */
    private function renumber(Collection $views): bool
    {
        $changed = false;
        foreach ($views->values() as $position => $view) {
            if ($view->position !== $position) {
                $view->position = $position;
                $view->save();
                $changed = true;
            }
        }

        return $changed;
    }

    /** The person's row, held until the change is saved: one change of their views at a time (the limit holds). */
    private function lock(int $workspaceId, int $userId): void
    {
        unset($workspaceId);
        DB::table('users')->where('id', $userId)->lockForUpdate()->first(['id']);
    }

    private function bump(int $workspaceId, int $userId): int
    {
        DB::table('users')->where('id', $userId)->increment('views_version');

        return $this->version($workspaceId, $userId);
    }

    private static function fail(string $field, string $message): never
    {
        throw new ApiError(422, 'validation_failed', $message, ['errors' => [$field => [$message]]]);
    }
}
