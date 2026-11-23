<?php

declare(strict_types=1);

namespace App\Livewire\Shell;

use App\Exceptions\ApiError;
use App\Filament\Concerns\DropsRecordings;
use App\Filament\Support\ConsoleV2;
use App\Filament\Support\Notice as Notification;
use App\Filament\Support\NoticeTargets;
use App\Filament\Support\Shell;
use App\Filament\Support\V2RecordingRow;
use App\Models\User;
use App\Services\Library\SavedViews;
use App\Services\Lists\LabelLists;
use App\Services\Workspaces\ConsoleAccess;
use App\Services\Workspaces\CurrentWorkspace;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Livewire\Sidebar as FilamentSidebar;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Renderless;

/**
 * The console's sidebar (since v1.6, P4.1), in the desktop app's frame: the brand, "Find a page", then Home · Library
 * (its smart folders) · Projects (the pinned ones) · Activity · Shared (with me, my links, request links) · Upload, and
 * at the bottom Help & shortcuts · Account · Collapse sidebar. The admin panel shows its own navigation in the same
 * frame. Its width, the rail and the open groups are the person's (App\Filament\Support\Shell).
 */
class Sidebar extends FilamentSidebar
{
    use DropsRecordings;

    /** The address the page was opened at: the sidebar draws again on its own requests and still marks where you are. */
    public ?string $here = null;

    public function mount(): void
    {
        $this->here = request()->getRequestUri();
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
        return view(ConsoleV2::shell() ? 'filament.v2.sidebar' : 'filament.shell.sidebar');
    }
}
