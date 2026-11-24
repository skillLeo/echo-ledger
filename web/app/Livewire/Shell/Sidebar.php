<?php

declare(strict_types=1);

namespace App\Livewire\Shell;

use App\Exceptions\ApiError;
use App\Filament\Concerns\DropsRecordings;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Filament\Support\ConsoleV2;
use App\Filament\Support\Notice as Notification;
use App\Filament\Support\NoticeTargets;
use App\Filament\Support\ObjectMenus;
use App\Filament\Support\Shell;
use App\Filament\Support\V2RecordingRow;
use App\Models\Client;
use App\Models\User;
use App\Services\Library\SavedViews;
use App\Services\Lists\LabelLists;
use App\Services\Projects\ProjectTree;
use App\Services\RetentionPolicy;
use App\Services\Workspaces\ConsoleAccess;
use App\Services\Workspaces\CurrentWorkspace;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Livewire\Sidebar as FilamentSidebar;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\View\View as IlluminateView;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Throwable;

/**
 * The console's sidebar (since v1.6, P4.1), in the desktop app's frame: the brand, "Find a page", then Home · Library
 * (its smart folders) · Projects (the pinned ones) · Activity · Shared (with me, my links, request links) · Upload, and
 * at the bottom Help & shortcuts · Account · Collapse sidebar. The admin panel shows its own navigation in the same
 * frame. Its width, the rail and the open groups are the person's (App\Filament\Support\Shell).
 */
class Sidebar extends FilamentSidebar
{
    use DropsRecordings;

    /**
     * The address the page was opened at: the sidebar draws again on its own requests and still marks where you are.
     * Locked: only the page sets it (the frame is drawn from the route it names).
     */
    #[Locked]
    public ?string $here = null;

    public function mount(): void
    {
        $this->here = request()->getRequestUri();
    }

    /**
     * With the three levels the frame is drawn from the page it is on (its place, its panel, what is chosen there). On
     * the sidebar's own requests (a view renamed or deleted from the panel, a dialog closed, refresh-sidebar) the
     * request is Livewire's update, not the page: this is the page's request again ($here, on the same scheme, host and
     * port, with the same headers, person and session), for the frame's view to be drawn from. Null on the page itself,
     * without the frame, or when $here names no page of the console.
     */
    private function pageRequest(): ?Request
    {
        $current = request();
        $here = (string) $this->here;
        if (! ConsoleV2::frame() || $here === '' || $here[0] !== '/' || str_starts_with($here, '//') || $current->getRequestUri() === $here) {
            return null;
        }
        $page = Request::create($current->getSchemeAndHttpHost().$here, 'GET', [], [], [], $current->server->all());
        try {
            $route = app('router')->getRoutes()->match($page);
        } catch (Throwable) {
            return null;
        }
        $page->setUserResolver($current->getUserResolver());
        if ($current->hasSession()) {
            $page->setLaravelSession($current->session());
        }
        $route->bind($page);
        $page->setRouteResolver(fn () => $route);

        return $page;
    }

    /** Since 2.0.0-p: a saved view's ⋯ › Rename. */
    public function renameViewAction(): Action
    {
        return Action::make('renameView')->label(__('Rename view'))->modalSubmitActionLabel(__('Rename'))
            ->schema([TextInput::make('name')->label(__('Name'))->required()->maxLength(SavedViews::MAX_NAME)->autofocus()])
            ->fillForm(fn (array $arguments): array => ['name' => $this->viewName((string) ($arguments['id'] ?? ''))])
            ->action(fn (array $data, array $arguments) => $this->changeViews(fn (SavedViews $views, int $ws, int $user) => $views->update($ws, $user, (string) ($arguments['id'] ?? ''), ['name' => $data['name']])));
    }

    /** Since 2.0.0-p: a saved view's ⋯ › Delete (the recordings are untouched). */
    public function deleteViewAction(): Action
    {
        return Action::make('deleteView')->label(__('Delete view'))->color('danger')->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => __('Delete the view :name?', ['name' => $this->viewName((string) ($arguments['id'] ?? ''))]))
            ->modalDescription(__('The view goes from My views on every device. No recording is deleted.'))
            ->modalSubmitActionLabel(__('Delete view'))
            ->action(fn (array $arguments) => $this->changeViews(fn (SavedViews $views, int $ws, int $user) => $views->delete($ws, $user, (string) ($arguments['id'] ?? ''))));
    }

    /**
     * Since 2.0.0-p: a saved view dragged to another place in My views.
     *
     * @param  list<mixed>  $ids
     */
    public function orderViews(array $ids): void
    {
        $this->changeViews(fn (SavedViews $views, int $ws, int $user) => $views->order($ws, $user, $ids));
    }

    /** Since 2.0.0-p: a saved view's ⋯ › Move up / Move down (the keyboard's way to reorder). */
    public function moveView(string $id, int $by): void
    {
        $ws = (int) CurrentWorkspace::id();
        $user = (int) CurrentWorkspace::userId();
        $ids = array_column(app(SavedViews::class)->index($ws, $user), 'id');
        $at = array_search($id, $ids, true);
        $to = is_int($at) ? $at + ($by < 0 ? -1 : 1) : -1;
        if (! is_int($at) || $to < 0 || $to >= count($ids)) {
            return;
        }
        [$ids[$at], $ids[$to]] = [$ids[$to], $ids[$at]];
        $this->orderViews($ids);
    }

    /** Since 2.0.0-p: LABELS › + New label: a list with a name and one of the eight colours. */
    public function newLabelAction(): Action
    {
        $colours = [];
        foreach (array_keys(V2RecordingRow::LABEL_COLOURS) as $name) {
            $colours[V2RecordingRow::LABEL_COLOURS[$name]] = __(ucfirst($name));
        }

        return Action::make('newLabel')->label(__('New label'))->modalSubmitActionLabel(__('Create label'))
            ->visible(fn (): bool => ! ConsoleAccess::readOnly())
            ->schema([
                TextInput::make('name')->label(__('Name'))->required()->maxLength(LabelLists::MAX_NAME)->autofocus(),
                Select::make('color')->label(__('Colour'))->options($colours)->placeholder(__('Chosen for you')),
            ])
            ->action(function (array $data): void {
                try {
                    app(LabelLists::class)->create((int) CurrentWorkspace::id(), (int) CurrentWorkspace::userId(), $data['name'], $data['color'] ?? null);
                } catch (ApiError $e) {
                    Notification::make()->title($e->getMessage())->url(NoticeTargets::forError($e))->warning()->send();

                    return;
                }
                Notification::make()->title(__('Label created'))->success()->send();
            });
    }

    /**
     * UX-22 3b: what the panel's objects' right-click menus (and a view's or a label's ⋯) can do here: a view (Open,
     * Rename…, Edit filters…, Duplicate, Move up / down, Delete view…), a label (Show recordings, Rename…, Colour ›,
     * Delete label…), a project (Open, Pin, Rename…, Delete project…; the web keeps no archive, members or requests from
     * the panel) and Trash (Empty trash…). Opening is the object's own link.
     */
    public const OBJECT_ACTIONS = [
        'view' => ['place.open', 'file.rename', 'view.editFilters', 'view.duplicate', 'view.moveUp', 'view.moveDown', 'view.delete'],
        'label' => ['label.showRecordings', 'file.rename', 'label.colour', 'label.delete'],
        'project' => ['project.open', 'project.pin', 'project.rename', 'project.delete'],
        'trash' => ['trash.empty'],
    ];

    /** @return array<string, mixed> */
    public function objectMenus(): array
    {
        return ObjectMenus::data(self::OBJECT_ACTIONS, colours: true);
    }

    /**
     * A pick from one of the panel's objects' menus (filament.v2.context-menu-script): only the person's own view, label
     * or project in this workspace (anything else is not found, and nothing happens).
     */
    public function objectAction(string $kind, string $action, string $id, ?string $value = null): void
    {
        if (! ObjectMenus::picked(self::OBJECT_ACTIONS, $kind, $action)) {
            return;
        }
        $ws = (int) CurrentWorkspace::id();
        $user = (int) CurrentWorkspace::userId();
        if ($kind === 'view') {
            $view = collect(app(SavedViews::class)->index($ws, $user))->firstWhere('id', $id);
            if (! is_array($view)) {
                return;
            }
            match ($action) {
                'file.rename' => $this->mountAction('renameView', ['id' => $id]),
                'view.delete' => $this->mountAction('deleteView', ['id' => $id]),
                'view.moveUp' => $this->moveView($id, -1),
                'view.moveDown' => $this->moveView($id, 1),
                'view.duplicate' => $this->changeViews(function (SavedViews $views, int $ws, int $user) use ($view): void {
                    // What the view keeps (its filters, search, order, grouping and density), under a new name.
                    // The filters as the index sends them (an object) are sections again.
                    $kept = array_filter(array_intersect_key($view, array_flip(['filters', 'search', 'sort', 'group', 'density'])), fn (mixed $v): bool => $v !== null);
                    $kept['filters'] = json_decode((string) json_encode($view['filters']), true) ?: [];
                    $views->create($ws, $user, [...$kept,
                        'name' => mb_substr(__(':name (copy)', ['name' => $view['name']]), 0, SavedViews::MAX_NAME)]);
                    Notification::make()->title(__('View duplicated'))->success()->send();
                }),
                // The view, its filter panel open (Save changes keeps what is changed there).
                'view.editFilters' => $this->redirect(CaptureSessionResource::getUrl('index', ['v' => $id, 'compose' => 'filters'])),
                default => null,
            };

            return;
        }
        if ($kind === 'label') {
            match ($action) {
                'file.rename' => $this->mountAction('renameLabel', ['key' => $id]),
                'label.delete' => $this->mountAction('deleteLabel', ['key' => $id]),
                'label.colour' => is_string($value) && isset(V2RecordingRow::LABEL_COLOURS[$value])
                    ? $this->changeLabels(fn (LabelLists $lists) => $lists->update($ws, $user, $id, false, null, true, V2RecordingRow::LABEL_COLOURS[$value])) : null,
                default => null,
            };

            return;
        }
        if ($kind === 'project') {
            $project = $this->project(['id' => $id]);
            if ($project === null) {
                return;
            }
            match ($action) {
                'project.pin' => $project->update(['pinned' => ! $project->pinned]),
                'project.rename' => $this->mountAction('renameProject', ['id' => $id]),
                'project.delete' => $this->mountAction('deleteProject', ['id' => $id]),
                default => null,
            };

            return;
        }
        if ($kind === 'trash' && $action === 'trash.empty') {
            $this->mountAction('emptyTrash');
        }
    }

    /** UX-22 3b: a label's menu › Rename…: its new name on every recording of the person that has it. */
    public function renameLabelAction(): Action
    {
        return Action::make('renameLabel')->label(__('Rename label'))->modalSubmitActionLabel(__('Rename'))
            ->schema([TextInput::make('name')->label(__('Name'))->required()->maxLength(LabelLists::MAX_NAME)->autofocus()])
            ->fillForm(fn (array $arguments): array => ['name' => $this->labelName((string) ($arguments['key'] ?? ''))])
            ->action(fn (array $data, array $arguments) => $this->changeLabels(fn (LabelLists $lists) => $lists->update((int) CurrentWorkspace::id(),
                (int) CurrentWorkspace::userId(), (string) ($arguments['key'] ?? ''), true, $data['name'], false, null)));
    }

    /** UX-22 3b: a label's menu › Delete label…: the label goes, its recordings stay. */
    public function deleteLabelAction(): Action
    {
        return Action::make('deleteLabel')->label(__('Delete label'))->color('danger')->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => __('Delete the label :name?', ['name' => $this->labelName((string) ($arguments['key'] ?? ''))]))
            ->modalDescription(__('Delete this label? Its recordings stay; only the label goes.'))
            ->modalSubmitActionLabel(__('Delete label'))
            ->action(fn (array $arguments) => $this->changeLabels(fn (LabelLists $lists) => $lists->delete((int) CurrentWorkspace::id(),
                (int) CurrentWorkspace::userId(), (string) ($arguments['key'] ?? ''))));
    }

    /** UX-22 3b: a project's menu › Rename… (a name its siblings don't have). */
    public function renameProjectAction(): Action
    {
        return Action::make('renameProject')->label(__('Rename project'))->modalSubmitActionLabel(__('Rename'))
            ->schema([TextInput::make('name')->label(__('Name'))->required()->maxLength(120)->autofocus()])
            ->fillForm(fn (array $arguments): array => ['name' => (string) $this->project($arguments)?->name])
            ->action(function (array $data, array $arguments): void {
                $project = $this->project($arguments);
                $name = trim((string) $data['name']);
                if ($project === null || $name === '') {
                    return;
                }
                try {
                    app(ProjectTree::class)->assertNameFree((int) $project->workspace_id, $project->parent_id !== null ? (string) $project->parent_id : null,
                        $name, (string) $project->getKey(), (int) $project->user_id);
                } catch (ApiError $e) {
                    Notification::make()->title($e->getMessage())->url(NoticeTargets::forError($e))->warning()->send();

                    return;
                }
                $project->update(['name' => $name]);
                Notification::make()->title(__('Project renamed'))->success()->send();
            });
    }

    /**
     * UX-22 3b: a project's menu › Delete project…, asking as the project's own page does: its recordings stay, with no
     * project; its subfolders move up a level.
     */
    public function deleteProjectAction(): Action
    {
        return Action::make('deleteProject')->label(__('Delete project'))->color('danger')->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => __('Delete “:name”?', ['name' => (string) $this->project($arguments)?->name]))
            ->modalDescription(__('Its recordings are not deleted: they stay in All recordings, with no project. Its subfolders move up a level. Its files, brief, notes, memory, rules and share links are deleted.'))
            ->modalSubmitActionLabel(__('Delete project'))
            ->action(function (array $arguments): void {
                $project = $this->project($arguments);
                $user = auth()->user();
                if ($project === null || ! $user instanceof User || ! app(ProjectTree::class)->canDelete($user, $project)) {
                    return;
                }
                try {
                    $moved = app(ProjectTree::class)->delete($project, $user);
                } catch (ApiError $e) {
                    Notification::make()->title($e->getMessage())->url(NoticeTargets::forError($e))->danger()->send();

                    return;
                }
                Notification::make()->title(__('Project deleted'))
                    ->body(trans_choice(':count recording now has no project.|:count recordings now have no project.', $moved['moved_sessions'], ['count' => $moved['moved_sessions']]))
                    ->success()->send();
            });
    }

    /** UX-22 3b: Trash's menu › Empty trash…: every recording of the person in Trash here is erased now. */
    public function emptyTrashAction(): Action
    {
        return Action::make('emptyTrash')->label(__('Empty trash'))->color('danger')->requiresConfirmation()
            ->modalHeading(__('Empty trash?'))->modalDescription(__('Empty the trash? Its recordings can\'t be brought back.'))
            ->modalSubmitActionLabel(__('Empty trash'))
            ->visible(fn (): bool => ! ConsoleAccess::readOnly())
            ->action(function (): void {
                $retention = app(RetentionPolicy::class);
                $bin = $retention->bin((int) CurrentWorkspace::id());
                foreach ($bin as $session) {
                    $retention->deleteNow($session);
                }
                Notification::make()->title(trans_choice(':count recording erased|:count recordings erased', $bin->count(), ['count' => $bin->count()]))->success()->send();
            });
    }

    /**
     * The person's own project in this workspace named by an action's arguments (id), else null.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function project(array $arguments): ?Client
    {
        $id = $arguments['id'] ?? null;

        return is_scalar($id) ? Client::query()->where('workspace_id', (int) CurrentWorkspace::id())->where('user_id', (int) CurrentWorkspace::userId())->find((string) $id) : null;
    }

    private function labelName(string $key): string
    {
        $list = collect(app(LabelLists::class)->index((int) CurrentWorkspace::id(), (int) CurrentWorkspace::userId()))->firstWhere('key', $key);

        return is_array($list) ? (string) $list['name'] : '';
    }

    /** Runs a change of the person's labels and says what went wrong in their words (a label not theirs is not found). */
    private function changeLabels(\Closure $change): void
    {
        abort_unless(auth()->user() instanceof User && CurrentWorkspace::id() !== null, 403);
        try {
            $change(app(LabelLists::class));
        } catch (ApiError $e) {
            Notification::make()->title($e->getMessage())->url(NoticeTargets::forError($e))->warning()->send();
        }
    }

    /** Runs a change of the person's views and says what went wrong in their words. */
    private function changeViews(\Closure $change): void
    {
        $user = auth()->user();
        $workspace = CurrentWorkspace::id();
        abort_unless($user instanceof User && $workspace !== null, 403);
        try {
            $change(app(SavedViews::class), (int) $workspace, (int) $user->id);
        } catch (ApiError $e) {
            Notification::make()->title($e->getMessage())->url(NoticeTargets::forError($e))->warning()->send();
        }
    }

    private function viewName(string $id): string
    {
        $view = collect(app(SavedViews::class)->index((int) CurrentWorkspace::id(), (int) CurrentWorkspace::userId()))->firstWhere('id', $id);

        return is_array($view) ? (string) $view['name'] : '';
    }

    /** A choice made in the sidebar (its width, the rail, a group opened, the style); nothing is drawn again. */
    #[Renderless]
    public function rememberShell(string $key, mixed $value): bool
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return Shell::remember($user, $key, $value);
    }

    public function render(): View
    {
        // Since v2.0: the five-module sidebar under ui_v2.shell (the classic one otherwise, unchanged).
        // Since docs/PLAN_NAV_3_LEVELS.md: the rail, the place's panel and the page's tabs with the new frame and Library.
        $view = view(match (true) {
            ConsoleV2::frame() => 'filament.v2.frame',
            ConsoleV2::shell() => 'filament.v2.sidebar',
            default => 'filament.shell.sidebar',
        });
        $page = $this->pageRequest();
        if ($page === null) {
            return $view;
        }

        // Drawn with the page's request bound, and the update's bound again afterwards, also when drawing fails.
        return new class($view, $page) extends IlluminateView
        {
            public function __construct(IlluminateView $view, private readonly Request $page)
            {
                parent::__construct($view->getFactory(), $view->getEngine(), $view->name(), $view->getPath(), $view->getData());
            }

            public function render(?callable $callback = null): string
            {
                $update = app('request');
                app()->instance('request', $this->page);
                try {
                    return parent::render($callback);
                } finally {
                    app()->instance('request', $update);
                }
            }
        };
    }
}
