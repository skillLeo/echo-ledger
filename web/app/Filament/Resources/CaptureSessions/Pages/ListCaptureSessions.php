<?php

declare(strict_types=1);

namespace App\Filament\Resources\CaptureSessions\Pages;

use App\Exceptions\ApiError;
use App\Filament\Concerns\DropsRecordings;
use App\Filament\Pages\SharedWithMe;
use App\Filament\Pages\Upload;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Filament\Resources\CaptureSessions\Tables\CaptureSessionsTable;
use App\Filament\Resources\CaptureSessions\Widgets\FailedUploadsWidget;
use App\Filament\Resources\CaptureSessions\Widgets\PlanLimitWidget;
use App\Filament\Resources\CaptureSessions\Widgets\WaitingDecisionsWidget;
use App\Filament\Support\ConsoleV2;
use App\Filament\Support\LibraryFilterBar;
use App\Filament\Support\LibraryView;
use App\Filament\Support\Notice as Notification;
use App\Filament\Support\NoticeTargets;
use App\Filament\Support\SendsToWhatsApp;
use App\Filament\Support\Shell;
use App\Filament\Support\WhatsAppActions;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\User;
use App\Services\ConsoleUploads;
use App\Services\Library\LibraryFacets;
use App\Services\Library\LibraryFilters;
use App\Services\Library\SavedViews;
use App\Services\LibraryOrganiser;
use App\Services\RetentionPolicy;
use App\Services\SettingsRepository;
use App\Services\Sharing\Teammates;
use App\Services\Views\TranscriptViews;
use App\Services\Workspaces\ConsoleAccess;
use App\Services\Workspaces\CurrentWorkspace;
use App\Stt\LanguageChoice;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentView;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\View\TablesRenderHook;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Livewire;
use RuntimeException;

class ListCaptureSessions extends ListRecords
{
    use DropsRecordings;
    use SendsToWhatsApp;

    protected static string $resource = CaptureSessionResource::class;

    protected static ?string $title = 'Library';

    /** The recording shown beside the list on wide screens; kept in the address so a reload keeps it. */
    #[Url(as: 'selected')]
    public ?string $selected = null;

    /** Smart views (B15), in the order the tabs show. */
    public const VIEW_LABELS = [
        'today' => 'Today',
        'this_week' => 'This week',
        'waiting' => 'Waiting for decision',
        'bookmarked' => 'Bookmarked',
        'failed' => 'Failed uploads',
        'pinned' => 'Pinned',
    ];

    /** Smart folders (v1.5), in the order the chips show; Shared with me opens its own page. */
    public const FOLDER_LABELS = [
        'inbox' => 'Inbox',
        'whatsapp' => 'WhatsApp',
        'whatsapp_business' => 'WhatsApp Business',
        'google_meet' => 'Google Meet',
        'zoom' => 'Zoom',
        'teams' => 'Teams',
        'phone' => 'Phone',
        'voice_notes' => 'Voice notes',
        'imported' => 'Imported',
        'shared' => 'Shared with me',
    ];

    /** The Heroicon of each smart folder: the same pictures as the apps' source icons. */
    public const FOLDER_ICONS = [
        'inbox' => 'inbox',
        'whatsapp' => 'chat-bubble-left-right',
        'whatsapp_business' => 'building-storefront',
        'google_meet' => 'video-camera',
        'zoom' => 'video-camera',
        'teams' => 'video-camera',
        'phone' => 'phone',
        'voice_notes' => 'microphone',
        'imported' => 'arrow-up-tray',
        'shared' => 'users',
    ];

    /** The smart folder the list is narrowed to (v1.5), kept in the address. */
    #[Url]
    public ?string $folder = null;

    /** Since v1.5 (Phase 1C): the order within the groups (LibraryView::SORTS), remembered per person and view. */
    public string $librarySort = LibraryView::DEFAULT_SORT;

    /** The Group by of the Library (LibraryView::GROUPS), remembered per person and view. */
    public string $libraryGroup = LibraryView::DEFAULT_GROUP;

    /** Since v1.6 (P4.4): the Library as the phone's list or as a table, remembered per person (Shell::LAYOUTS). */
    public string $libraryLayout = 'list';

    /**
     * Since 2.0.0-p (ui_v2.library): the filters that are on (LibraryFilters' shape), in the address, so a link and
     * Back keep them.
     *
     * @var array<string, mixed>
     */
    public array $lf = [];

    /** Since 2.0.0-p: the saved view shown (its id), in the address; the sidebar marks it. */
    public ?string $savedView = null;

    /** Since 2.0.0-p: "+ New view" from the sidebar on another page opens Save as view here. */
    public ?string $compose = null;

    /**
     * Since docs/PLAN_NAV_3_LEVELS.md section 4: the page's top tab, Calls, Meetings or Voice notes (null: All), in the
     * address (`kind`); it narrows the place, the filters and the search by Source (LibraryFilters::KINDS).
     */
    public ?string $kind = null;

    /**
     * The new Library's part of the address (2.0.0-p): the filters (`f`), the saved view (`v`) and "+ New view"
     * (`compose`), each change a step Back can undo. Only under ui_v2.library: the classic page's address stays as it was.
     *
     * @return array<string, array{as: string, history?: bool}>
     */
    protected function queryString(): array
    {
        return ConsoleV2::library() ? ['lf' => ['as' => 'f', 'history' => true], 'savedView' => ['as' => 'v', 'history' => true], 'compose' => ['as' => 'compose', 'history' => false],
            ...(ConsoleV2::frame() ? ['kind' => ['as' => 'kind', 'history' => true]] : [])] : [];
    }

    public function mount(): void
    {
        $user = auth()->user();
        $this->libraryLayout = Shell::preferences($user instanceof User ? $user : null)['layout'];
        parent::mount();
        $this->loadLibraryView();
        if (ConsoleV2::library()) {
            $this->mountFilters();
        }
    }

    /**
     * The new Library's start (2.0.0-p): an old address's folder becomes its Source or Project filter, an old view its
     * place or filter, a saved view in the address is opened, and the filters are read as LibraryFilters keeps them.
     */
    private function mountFilters(): void
    {
        $filters = $this->filterSet();
        $folder = $this->folder;
        if ($folder !== null) {
            $source = ['whatsapp' => 'whatsapp', 'whatsapp_business' => 'whatsapp_business', 'google_meet' => 'google_meet', 'zoom' => 'zoom',
                'teams' => 'microsoft_teams', 'phone' => 'phone_call', 'voice_notes' => 'voice_note', 'imported' => 'imported'][$folder] ?? null;
            if ($source !== null) {
                $filters['source'] = ['op' => 'is', 'values' => [$source]];
            } elseif ($folder === 'inbox') {
                $filters['project'] = ['op' => 'is', 'values' => [LibraryFilters::NO_PROJECT]];
            } elseif ($folder === 'shared') {
                // Others' recordings were never in this list: Shared with me is the sidebar's own page.
                $this->redirect(SharedWithMe::getUrl());
            }
            $this->folder = null;
        }
        // An old view's link opens what holds its rows now: Waiting for decision and Failed uploads (Activity's Review, a
        // notice) open Needs you, where both are; Today, This week (Monday to Sunday in the console's time zone, as the
        // old view counted it), Bookmarked and Pinned become their filters (shown as pills, counted by the panel), without
        // the calls waiting for their decision, which now live only in Needs you; Untranscribed is the place Not
        // transcribed.
        $tab = (string) $this->activeTab;
        $week = Carbon::now(app(SettingsRepository::class)->displayTimezone())->startOfWeek(Carbon::MONDAY);
        $filters += match ($tab) {
            'today' => ['date' => ['op' => 'is', 'values' => ['today']]],
            'this_week' => ['date' => ['op' => 'is', 'values' => ['custom'], 'from' => $week->toDateString(), 'to' => $week->copy()->addDays(6)->toDateString()]],
            'bookmarked' => ['bookmarks' => ['op' => 'is', 'values' => ['any']]],
            'pinned' => ['marked' => ['op' => 'is', 'values' => ['pinned']]],
            default => [],
        };
        $this->activeTab = match ($tab) {
            'waiting', 'failed' => 'needs_you',
            'untranscribed' => 'not_transcribed',
            'today', 'this_week', 'bookmarked', 'pinned' => 'all',
            default => $this->activeTab,
        };
        $this->lf = $filters;
        if ($this->savedView !== null) {
            $this->openSavedView($this->savedView);
        }
    }

    /**
     * The filters that are on, as LibraryFilters keeps them (an address that does not read as filters is no filter).
     *
     * @return array<string, array{op: string, values: list<string>, from?: string, to?: string}>
     */
    public function filterSet(): array
    {
        try {
            return LibraryFilters::normalize($this->lf);
        } catch (ApiError) {
            return [];
        }
    }

    /** How many sections are on: the number on [Filters n]. */
    public function activeFilterCount(): int
    {
        return count($this->filterSet());
    }

    /** The place of the sidebar's Library the list is in (LibraryFacets::PLACES). */
    public function libraryPlace(): string
    {
        return LibraryFacets::place((string) ($this->activeTab ?: 'all'));
    }

    /**
     * The filter panel's counts: every option of every section, in the place, with the search (memoised per render).
     *
     * @return array{total: int, sections: array<string, list<array<string, mixed>>>, needs_you: array{count: int, failed: int, waiting: int, stuck: int}}
     */
    public function libraryFacets(): array
    {
        return $this->facetsOnce ??= app(LibraryFacets::class)->facets((int) CurrentWorkspace::id(), (int) CurrentWorkspace::userId(),
            $this->filterSet(), (string) $this->tableSearch, null, $this->libraryPlace(), $this->kindSources());
    }

    /**
     * The open tab's Sources (null: All, or a tab this address does not name).
     *
     * @return list<string>|null
     */
    public function kindSources(): ?array
    {
        return ConsoleV2::frame() && is_string($this->kind) ? (LibraryFilters::KINDS[$this->kind] ?? null) : null;
    }

    /**
     * The page's top tabs (docs/PLAN_NAV_3_LEVELS.md section 4): All · Calls · Meetings · Voice notes, each with how many
     * the place, the filters and the search keep in it.
     *
     * @return list<array{key: string, label: string, count: int, active: bool}>
     */
    public function libraryTabs(): array
    {
        $facets = app(LibraryFacets::class);
        $ws = (int) CurrentWorkspace::id();
        $user = (int) CurrentWorkspace::userId();
        $current = $this->kindSources() !== null ? (string) $this->kind : 'all';
        $tabs = [];
        foreach (['all' => __('All'), 'calls' => __('Calls'), 'meetings' => __('Meetings'), 'voice_notes' => __('Voice notes')] as $key => $label) {
            $tabs[] = ['key' => $key, 'label' => $label, 'active' => $current === $key,
                'count' => $facets->total($ws, $user, $this->filterSet(), (string) $this->tableSearch, null, $this->libraryPlace(), $key === 'all' ? null : LibraryFilters::KINDS[$key])];
        }

        return $tabs;
    }

    /** A top tab: All (null) or one of LibraryFilters::KINDS. */
    public function setLibraryKind(?string $kind): void
    {
        $this->kind = $kind !== null && isset(LibraryFilters::KINDS[$kind]) ? $kind : null;
        $this->facetsOnce = null;
        $this->resetPage();
        $this->deselectAllTableRecords();
        $this->flushCachedTableRecords();
    }

    /** @var array{total: int, sections: array<string, list<array<string, mixed>>>, needs_you: array{count: int, failed: int, waiting: int, stuck: int}}|null */
    private ?array $facetsOnce = null;

    /** @return list<array<string, mixed>> the filter panel's sections */
    public function filterPanel(): array
    {
        return LibraryFilterBar::panel($this->filterSet(), $this->libraryFacets());
    }

    /** @return list<array<string, mixed>> the pills of the filters that are on */
    public function filterPills(): array
    {
        return LibraryFilterBar::pills($this->filterSet(), $this->libraryFacets());
    }

    /** The view's name at the start of the toolbar: the saved view, else the place. */
    public function libraryHeading(): string
    {
        $view = $this->currentSavedView();
        if ($view !== null) {
            return $view['name'];
        }
        $place = $this->libraryPlace();
        // A label opened from the sidebar (Labels is one value, nothing else on) is named as the sidebar names it.
        $filters = $this->filterSet();
        if ($place === 'all' && array_keys($filters) === ['labels'] && $filters['labels']['op'] === 'is' && count($filters['labels']['values']) === 1) {
            $label = collect($this->libraryFacets()['sections']['labels'])->firstWhere('value', $filters['labels']['values'][0]);
            if (is_array($label)) {
                return (string) ($label['name'] ?? $label['value']);
            }
        }

        return match ($place) {
            'needs_you' => __('Needs you'),
            'unread' => __('Unread'),
            'important' => __('Important'),
            'not_transcribed' => __('Not transcribed'),
            'audio_only' => __('Audio only'),
            default => __('All recordings'),
        };
    }

    /** The breadcrumb's "Library": All recordings, with no filter, search or view (since §0 of docs/PLAN_NAV_3_LEVELS.md). */
    public function libraryHomeUrl(): string
    {
        return CaptureSessionResource::getUrl('index');
    }

    /** The panel's filter set as it was sent (every section at once), 150 ms after the last change. */
    public function applyFilters(mixed $filters): void
    {
        try {
            $this->setFilters(LibraryFilters::normalize(is_array($filters) ? $filters : []));
        } catch (ApiError) {
            // A half-typed custom date or an option that went away: the list stays as it was.
        }
    }

    /** A pill's × or a section's Clear. */
    public function removeFilter(string $section): void
    {
        $filters = $this->filterSet();
        unset($filters[$section]);
        $this->setFilters($filters);
    }

    /** A pill's "is" / "is not". */
    public function setFilterOp(string $section, string $op): void
    {
        $filters = $this->filterSet();
        if (isset($filters[$section]) && in_array($op, LibraryFilters::OPS, true)) {
            $filters[$section]['op'] = $op;
            $this->setFilters($filters);
        }
    }

    /** A pill's value ticked or unticked (a section left with none goes). */
    public function toggleFilterValue(string $section, string $value): void
    {
        $filters = $this->filterSet();
        $values = $filters[$section]['values'] ?? [];
        $values = in_array($value, $values, true) ? array_values(array_diff($values, [$value])) : [...$values, $value];
        $this->applyFilters([...$filters, $section => ['op' => $filters[$section]['op'] ?? 'is', 'values' => $values]]);
    }

    /** The row's source logo or label dot: only those recordings (another click on the same one takes it away). */
    public function quickFilter(string $section, string $value): void
    {
        if (! in_array($section, ['source', 'labels'], true)) {
            return;
        }
        $filters = $this->filterSet();
        if (($filters[$section] ?? null) === ['op' => 'is', 'values' => [mb_strtolower($value)]] || ($filters[$section] ?? null) === ['op' => 'is', 'values' => [$value]]) {
            $this->removeFilter($section);

            return;
        }
        $this->applyFilters([...$filters, $section => ['op' => 'is', 'values' => [$value]]]);
    }

    /** Clear all (the pills) and Clear filters (nothing matches): every filter off. */
    public function clearFilters(): void
    {
        $this->setFilters([]);
    }

    /** @param array<string, array{op: string, values: list<string>, from?: string, to?: string}> $filters */
    private function setFilters(array $filters): void
    {
        $this->lf = $filters;
        $this->facetsOnce = null;
        $this->resetPage();
        $this->deselectAllTableRecords();
        $this->flushCachedTableRecords();
    }

    /** Sort & display › Density, the console's own (Settings › Appearance). */
    public function setLibraryDensity(string $density): void
    {
        $user = auth()->user();
        if ($user instanceof User && isset(Shell::DENSITIES[$density])) {
            Shell::remember($user, 'density', $density);
            $this->js('document.documentElement.dataset.density = '.json_encode($density));
        }
    }

    /**
     * The saved view shown, as SavedViews answers it; null when none is (or it is gone).
     *
     * @return array<string, mixed>|null
     */
    public function currentSavedView(): ?array
    {
        if ($this->savedView === null) {
            return null;
        }

        return $this->viewsOnce[$this->savedView] ??= collect(app(SavedViews::class)->index((int) CurrentWorkspace::id(), (int) CurrentWorkspace::userId()))
            ->firstWhere('id', $this->savedView);
    }

    /** @var array<string, array<string, mixed>|null> */
    private array $viewsOnce = [];

    /** Whether the filters, the search or the order differ from the saved view's (Save changes). */
    public function savedViewChanged(): bool
    {
        $view = $this->currentSavedView();
        if ($view === null) {
            return false;
        }
        $kept = LibraryFilters::read(json_decode((string) json_encode($view['filters']), true));

        return $kept !== $this->filterSet() || (string) ($view['search'] ?? '') !== trim((string) $this->tableSearch)
            || $view['sort'] !== $this->librarySort || $view['group'] !== self::viewGroup($this->libraryGroup);
    }

    /** Opens a saved view: its filters, search and order (a view that is gone opens nothing). */
    private function openSavedView(string $id): void
    {
        $view = $this->currentSavedView();
        if ($view === null) {
            $this->savedView = null;

            return;
        }
        $this->lf = LibraryFilters::read(json_decode((string) json_encode($view['filters']), true));
        $this->tableSearch = (string) ($view['search'] ?? '');
        $group = $view['group'] === 'source' ? 'app' : $view['group'];
        $this->applyLibraryView(isset(LibraryView::GROUPS[$group]) ? $group : LibraryView::DEFAULT_GROUP, isset(LibraryView::SORTS[$view['sort']]) ? $view['sort'] : LibraryView::DEFAULT_SORT, remember: false);
        unset($id);
    }

    /** The console's Group by as a view keeps it (the API calls App / Type `source`). */
    private static function viewGroup(string $group): string
    {
        return $group === 'app' ? 'source' : $group;
    }

    /**
     * What a view keeps of the list as it is now.
     *
     * @return array<string, mixed>
     */
    private function viewState(): array
    {
        return ['filters' => $this->filterSet(), 'search' => trim((string) $this->tableSearch) ?: null, 'sort' => $this->librarySort,
            'group' => self::viewGroup($this->libraryGroup), 'density' => Shell::preferences()['density']];
    }

    /** Save as view (the pills, the panel's foot, + New view): a name, then the view at the end of My views. */
    public function saveViewAction(): Action
    {
        return Action::make('saveView')->label(__('Save as view'))->modalHeading(__('Save as view'))
            ->modalDescription(__('Keeps these filters, the search and the order in My views, on every device.'))
            ->modalSubmitActionLabel(__('Save view'))
            ->schema([TextInput::make('name')->label(__('Name'))->required()->maxLength(SavedViews::MAX_NAME)->autofocus()])
            ->action(function (array $data): void {
                try {
                    $made = app(SavedViews::class)->create((int) CurrentWorkspace::id(), (int) CurrentWorkspace::userId(), ['name' => $data['name'], ...$this->viewState()]);
                } catch (ApiError $e) {
                    Notification::make()->title($e->getMessage())->url(NoticeTargets::forError($e))->warning()->send();

                    return;
                }
                $this->savedView = $made['view']['id'];
                $this->viewsOnce = [];
                Notification::make()->title(__('View saved'))->success()->send();
                $this->dispatch('refresh-sidebar');
            });
    }

    /** Save changes: the open saved view takes the filters, search and order as they are now. */
    public function updateViewAction(): Action
    {
        return Action::make('updateView')->label(__('Save changes'))->requiresConfirmation(false)
            ->action(function (): void {
                if ($this->savedView === null) {
                    return;
                }
                try {
                    app(SavedViews::class)->update((int) CurrentWorkspace::id(), (int) CurrentWorkspace::userId(), $this->savedView, $this->viewState());
                } catch (ApiError $e) {
                    Notification::make()->title($e->getMessage())->url(NoticeTargets::forError($e))->warning()->send();

                    return;
                }
                $this->viewsOnce = [];
                Notification::make()->title(__('View saved'))->success()->send();
                $this->dispatch('refresh-sidebar');
            });
    }

    /** The toolbar's List / Table switch. */
    public function setLibraryLayout(string $layout): void
    {
        if (! isset(Shell::LAYOUTS[$layout])) {
            return;
        }
        $this->libraryLayout = $layout;
        $user = auth()->user();
        if ($user instanceof User) {
            Shell::remember($user, 'layout', $layout);
        }
        // The table is built again with the other columns; the search and the filters stay.
        $this->bootedInteractsWithTable();
        $this->flushCachedTableRecords();
    }

    public function updatedActiveTab(): void
    {
        parent::updatedActiveTab();
        $this->loadLibraryView();
        // v2.0: another place starts with nothing selected, as a search or a filter does (Filament's own), so the
        // selection never holds recordings the list no longer shows.
        if (ConsoleV2::library()) {
            $this->deselectAllTableRecords();
        }
    }

    /** The view the choice is remembered for: the smart view, and the smart folder when one is chosen. */
    public function libraryViewKey(): string
    {
        return ((string) ($this->activeTab ?: 'all')).($this->folder !== null ? ':'.$this->folder : '');
    }

    /** Group by from the toolbar menu. */
    public function setLibraryGroup(string $group): void
    {
        if (isset(LibraryView::GROUPS[$group])) {
            $this->applyLibraryView($group, $this->librarySort, remember: true);
        }
    }

    /** Sort by from the toolbar menu. */
    public function setLibrarySort(string $sort): void
    {
        if (isset(LibraryView::SORTS[$sort])) {
            $this->applyLibraryView($this->libraryGroup, $sort, remember: true);
        }
    }

    /**
     * The group's select box selects the whole group (every page), read for all the groups with one query instead of
     * one per header.
     *
     * @return list<string>
     */
    public function getGroupedSelectableTableRecordKeys(?string $group): array
    {
        $grouping = $this->getTableGrouping();
        if ($grouping === null || ! isset(LibraryView::GROUPS[$grouping->getId()])) {
            return parent::getGroupedSelectableTableRecordKeys($group);
        }

        return LibraryView::buckets($grouping->getId(), $this)[(string) $group] ?? [];
    }

    /** The group counts are counted once per render (LibraryView), and so are the views and the folders. */
    public function rendering(): void
    {
        LibraryView::forgetCounts();
        $this->foldersOnce = null;
        $this->facetsOnce = null;
    }

    /** @var array<string, array{label: string, icon: string, count: int, url: string|null}>|null the folders, for this render */
    private ?array $foldersOnce = null;

    private function loadLibraryView(): void
    {
        $user = auth()->user();
        $choice = LibraryView::remembered($user instanceof User ? $user : null, $this->libraryViewKey());
        $this->applyLibraryView($choice['group'], $choice['sort'], remember: false);
    }

    private function applyLibraryView(string $group, string $sort, bool $remember): void
    {
        $this->libraryGroup = $group;
        $this->librarySort = $sort;
        // The menus decide the order: a click on a column's sort is dropped.
        $this->tableSort = null;
        $this->tableGrouping = $group === 'none' ? null : $group.':'.LibraryView::groupDirection($group, $sort);
        if (isset($this->table)) {
            $this->resetPage();
        }
        $user = auth()->user();
        if ($remember && $user instanceof User) {
            LibraryView::remember($user, $this->libraryViewKey(), $group, $sort);
        }
    }

    /** Files up to this size are hashed by the browser's own SHA-256 (it needs the whole file in memory). */
    public const NATIVE_HASH_MAX_BYTES = 100 * 1024 * 1024;

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        $organiser = app(LibraryOrganiser::class);
        $counts = $organiser->counts((int) CurrentWorkspace::id());
        $tabs = ['all' => Tab::make('All')];
        foreach (self::VIEW_LABELS as $view => $label) {
            $tabs[$view] = Tab::make($label)
                ->badge($counts[$view] > 0 ? $counts[$view] : null)
                ->modifyQueryUsing(fn (Builder $query): Builder => $organiser->applyView($query, $view));
        }
        // Since 2.0.0-p (ui_v2.library): the sidebar's Library places; LibraryFacets narrows the list to each one.
        if (ConsoleV2::library()) {
            foreach (['needs_you', 'unread', 'important', 'not_transcribed', 'audio_only'] as $place) {
                $tabs[$place] = Tab::make($place);
            }
        }
        // Since v2.0 (ui_v2.library): the apps' saved view Untranscribed.
        if (ConsoleV2::library()) {
            $untranscribed = $organiser->untranscribedCount((int) CurrentWorkspace::id());
            $tabs['untranscribed'] = Tab::make(__('Untranscribed'))->badge($untranscribed > 0 ? $untranscribed : null)
                ->modifyQueryUsing(fn (Builder $query): Builder => $organiser->applyView($query, 'untranscribed'));
        }

        return $tabs;
    }

    /**
     * The smart folders as chips (v1.5): each one's label, how many recordings it holds and whether it is chosen.
     *
     * @return array<string, array{label: string, count: int, url: string|null}>
     */
    public function folders(): array
    {
        return $this->foldersOnce ??= $this->countFolders();
    }

    /** @return array<string, array{label: string, icon: string, count: int, url: string|null}> */
    private function countFolders(): array
    {
        $counts = app(LibraryOrganiser::class)->folderCounts((int) CurrentWorkspace::id());
        $user = auth()->user();
        $counts['shared'] = $user instanceof User ? app(Teammates::class)->sharedCount($user) : 0;
        $folders = [];
        foreach (self::FOLDER_LABELS as $key => $label) {
            $folders[$key] = ['label' => $label, 'icon' => self::FOLDER_ICONS[$key], 'count' => $counts[$key] ?? 0, 'url' => $key === 'shared' ? SharedWithMe::getUrl() : null];
        }

        return $folders;
    }

    /** The empty state's "Show all recordings": no smart view and no folder. */
    public function showAllRecordings(): void
    {
        $this->folder = null;
        $this->activeTab = 'all';
        $this->updatedActiveTab();
    }

    /**
     * A place of the Library's column (since v1.6, P4.2): every recording, a view (Waiting, Pinned, Failed uploads…)
     * or a smart folder. The sidebar's list follows.
     */
    public function openPlace(string $key): void
    {
        if ($key === 'all') {
            $this->folder = null;
            $this->activeTab = 'all';
        } elseif (str_starts_with($key, 'folder:') && array_key_exists(substr($key, 7), self::FOLDER_LABELS) && $key !== 'folder:shared') {
            $this->folder = substr($key, 7);
            $this->activeTab = 'all';
        } elseif (array_key_exists($key, self::VIEW_LABELS) || (ConsoleV2::library() && ($key === 'untranscribed' || in_array($key, LibraryFacets::PLACES, true)))) {
            $this->folder = null;
            // Since 2.0.0-p Waiting for decision and Failed uploads are Needs you.
            $this->activeTab = ConsoleV2::library() && in_array($key, ['waiting', 'failed'], true) ? 'needs_you' : $key;
        } else {
            return;
        }
        $this->selected = null;
        $this->updatedActiveTab();
        $this->dispatch('el-place', place: $this->activePlace());
    }

    /** The column's place that is open: a smart folder, else the view, else All. */
    public function activePlace(): string
    {
        if ($this->folder !== null) {
            return 'folder:'.$this->folder;
        }
        $tab = (string) ($this->activeTab ?: 'all');

        return array_key_exists($tab, self::VIEW_LABELS) || (ConsoleV2::library() && ($tab === 'untranscribed' || in_array($tab, LibraryFacets::PLACES, true))) ? $tab : 'all';
    }

    /** A recording moved by a drop on the sidebar: the list is drawn again with it in its new place. */
    /**
     * The page's listeners, with the v2.0 selection's Undo only under the new Library (the classic page stays as it was).
     *
     * @return array<string, string>
     */
    protected function getListeners(): array
    {
        return [...parent::getListeners(), ...(ConsoleV2::library() ? ['el-library-undo-delete' => 'undoDelete'] : [])];
    }

    /** The new Library's rows come in chunks of this many (WEB-LIB-033): the first ones, then Load more. */
    public const V2_CHUNK = 50;

    /** The new Library grows to this many rows at most; past it, the person narrows the list ("Refine your search"). */
    public const V2_MAX = 500;

    /** How many rows the new Library shows now; Load more adds a chunk, and a new search, filter or order starts over. */
    #[Locked]
    public int $v2Shown = self::V2_CHUNK;

    /**
     * The rows on screen that are still moving (uploading, being transcribed, a view being made) when the list was last
     * drawn, and how they stood then: the quiet poll draws the list again only once one of them changed.
     *
     * @var list<string>
     */
    #[Locked]
    public array $v2Moving = [];

    #[Locked]
    public string $v2MovingPrint = '';

    /** Load more (also when its button scrolls into view): the next chunk of the list, the counts unchanged. */
    public function v2LoadMore(): void
    {
        if (ConsoleV2::library()) {
            $this->v2Shown = min(self::V2_MAX, $this->v2Shown + self::V2_CHUNK);
        }
    }

    /**
     * The new Library's poll while a row on screen is moving: a few columns of those rows only, and no redraw of the
     * list (up to 500 rows) unless one of them changed.
     */
    public function v2Poll(): void
    {
        if (! ConsoleV2::library() || ($this->v2Moving !== [] && $this->v2Print($this->v2Moving) === $this->v2MovingPrint)) {
            $this->skipRender();
        }
    }

    /** @param  list<string>  $ids */
    private function v2Print(array $ids): string
    {
        $rows = CaptureSession::query()->whereIn('id', $ids)->orderBy('id')
            ->with(['finalTranscript' => fn ($final) => $final->select(['id', 'capture_session_id', 'kind', 'status', 'updated_at'])])
            ->get(['id', 'status', 'updated_at']);

        // Since U19 also the five minutes it is in: a view that went quiet (or never started) stops "Converting…" by
        // itself, without anything stored changing.
        return md5((string) json_encode([intdiv(now()->getTimestamp(), 300), ...$rows->map(fn (CaptureSession $r): array => [(string) $r->id, $r->status->value, (string) $r->updated_at,
            $r->finalTranscript?->status?->value, (string) $r->finalTranscript?->updated_at])->all()]));
    }

    /**
     * Since U9b (WEB-LIB-033, 2,000+ recordings): the new Library is one growing list instead of pages of 5 to 50. Its
     * first v2Shown rows, with the true total, so "Showing 1 to 50 of 2,000" and the counts stay right; Load more grows
     * it. Classic keeps its pages.
     *
     * @param  Builder<Model>  $query
     * @return Paginator<int, Model>|CursorPaginator<int, Model>
     */
    protected function paginateTableQuery(Builder $query): Paginator|CursorPaginator
    {
        if (! ConsoleV2::library()) {
            return parent::paginateTableQuery($query);
        }
        $total = (clone $query)->toBase()->getCountForPagination();
        $items = (clone $query)->limit(max(1, min(self::V2_MAX, $this->v2Shown)))->get();
        $this->v2Moving = $items->filter(fn (Model $r): bool => $r instanceof CaptureSession && CaptureSessionsTable::isMoving($r))->map(fn (Model $r): string => (string) $r->getKey())->values()->all();
        $this->v2MovingPrint = $this->v2Moving === [] ? '' : $this->v2Print($this->v2Moving);

        return new LengthAwarePaginator($items, $total, max(1, $total), 1, ['pageName' => $this->getTablePaginationPageName()]);
    }

    /** A new search, filter, order or grouping shows the first chunk again (Filament resets the page then). */
    public function resetPage(?string $pageName = null): void
    {
        $this->v2Shown = self::V2_CHUNK;
        parent::resetPage($pageName);
    }

    /** v2.0: another page of the list, or another page size, starts with nothing selected, so Delete takes what is on screen. */
    public function updatedPaginators(): void
    {
        if (ConsoleV2::library()) {
            $this->deselectAllTableRecords();
        }
    }

    public function updatingTableRecordsPerPage(): void
    {
        $this->updatedPaginators();
    }

    /**
     * Undo of the v2.0 selection's Delete: the batch that Delete just moved (its token, this person's only) comes back
     * from Recently deleted while it still keeps it, each recording only when the person may restore it.
     */
    public function undoDelete(string $token = ''): void
    {
        $ids = $token !== '' ? Cache::pull(CaptureSessionsTable::undoKey($token)) : null;
        // A token already used, or older than its half hour: nothing waits for it any more.
        if (! is_array($ids)) {
            Notification::make()->title(__('Undo has expired'))->body(__('Open Recently deleted to bring recordings back.'))->warning()->send();

            return;
        }
        $back = 0;
        $policy = app(RetentionPolicy::class);
        // Every one of the batch, however many select all picked, a few hundred at a time.
        foreach (array_chunk(array_map('strval', $ids), 200) as $chunk) {
            foreach (CaptureSession::onlyTrashed()->where('workspace_id', CurrentWorkspace::id())->ofPerson()->whereIn('id', $chunk)->get() as $session) {
                if ($session->restorable() && Gate::allows('restore', $session)) {
                    $policy->restore($session);
                    $back++;
                }
            }
        }
        $notice = Notification::make()->title($back === 1 ? __('1 recording is back') : __(':count recordings are back', ['count' => $back]));
        // Nothing came back (each one was erased, or not this person's to restore): a warning, not a success.
        ($back > 0 ? $notice->success() : $notice->warning())->send();
        $this->resetTable();
    }

    #[On('el-library-changed')]
    public function libraryChanged(): void
    {
        Shell::forget();
    }

    /** Picks a smart folder, or clears it when it is picked again. */
    public function setFolder(?string $folder): void
    {
        $this->folder = $folder !== null && $folder !== $this->folder && array_key_exists($folder, self::FOLDER_LABELS) && $folder !== 'shared' ? $folder : null;
        $this->resetPage();
        if (ConsoleV2::library()) {
            $this->deselectAllTableRecords();
        }
        $this->loadLibraryView();
    }

    /** The toolbar's Folder menu (since v1.6): picks a smart folder, or none; picking the chosen one keeps it. */
    public function chooseFolder(?string $folder): void
    {
        if ($folder === null || $folder !== $this->folder) {
            $this->setFolder($folder);
        }
    }

    /**
     * The smart view's tab, then the chosen smart folder.
     *
     * @param  Builder<CaptureSession>  $query
     * @return Builder<CaptureSession>
     */
    protected function modifyQueryWithActiveTab(Builder $query, bool $isResolvingRecord = false): Builder
    {
        $query = parent::modifyQueryWithActiveTab($query, $isResolvingRecord);
        // Since 2.0.0-p (ui_v2.library): the place and the filters, as LibraryFacets counts them (a call still waiting
        // for its decision is in Needs you only).
        if (! $isResolvingRecord && ConsoleV2::library()) {
            app(LibraryFacets::class)->apply($query, (int) CurrentWorkspace::id(), (int) CurrentWorkspace::userId(), $this->filterSet(), null, null, $this->libraryPlace(), $this->kindSources());
        }
        if ($isResolvingRecord || $this->folder === null || $this->folder === 'shared' || ! array_key_exists($this->folder, self::FOLDER_LABELS)) {
            return $query;
        }

        return app(LibraryOrganiser::class)->applyFolder($query, $this->folder);
    }

    /**
     * The "4 calls waiting · Review" line while recordings wait for a decision, and since v1.6 "Failed uploads (2) · See
     * why" while something failed.
     */
    protected function getHeaderWidgets(): array
    {
        // Since v2.0 (ui_v2.library) Waiting and Failed uploads are saved views with their counts; Home lists what needs you.
        if (ConsoleV2::library()) {
            return [PlanLimitWidget::class];
        }

        return [WaitingDecisionsWidget::class, FailedUploadsWidget::class, PlanLimitWidget::class];
    }

    /** The smart view, so the Failed uploads line hides while that view is open. */
    public function getWidgetData(): array
    {
        return ['activeTab' => (string) ($this->activeTab ?: 'all')];
    }

    /**
     * The uploader (its drop zone shows while files are dragged over the page), then the list with its search pill
     * and smart-view chips, and on wide screens the chosen recording beside it.
     */
    public function content(Schema $schema): Schema
    {
        // Since v2.0 (ui_v2.library): the apps' Library, one column: the saved views as chips, the search and the
        // quick filters, then the rows grouped by day; the chosen recording still opens beside the list.
        if (ConsoleV2::library()) {
            return $schema->components([
                View::make('filament.library.uploader')->visible(fn (): bool => ! ConsoleAccess::readOnly())
                    ->viewData(fn (): array => ['config' => self::uploaderConfig(), 'compact' => true]),
                // Since 2.0.0-p: the filter panel left of the list, the one toolbar row, then the pills and the rows.
                Group::make([
                    View::make('filament.v2.library-filters'),
                    Group::make([
                        RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                        EmbeddedTable::make(),
                        RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
                    ])->extraAttributes(['class' => 'v2-library el-library']),
                    // Since docs/PLAN_NAV_3_LEVELS.md section 4 (the three levels): the filters come from the right, 320 wide,
                    // beside the list from --el-breakpoint-wide (1440) and over it below.
                ])->extraAttributes(fn (): array => ['class' => ConsoleV2::frame() ? 'v2-lib-shell is-right' : 'v2-lib-shell',
                    'x-data' => 'elV2LibraryShell({ compose: '.($this->compose === 'view' ? 'true' : 'false').', side: '.(ConsoleV2::frame() ? "'--el-breakpoint-wide'" : "'--el-breakpoint-panel'").' })',
                    'x-bind:class' => "{ 'is-drawer': drawer, 'is-pushing': filtersOpen && ! drawer }"]),
                View::make('filament.library.detail')->viewData(fn (): array => ['detail' => $this->selectedRecording()]),
            ]);
        }

        return $schema->components([
            View::make('filament.library.uploader')->visible(fn (): bool => ! ConsoleAccess::readOnly())
                ->viewData(fn (): array => ['config' => self::uploaderConfig(), 'compact' => true]),
            // Since v1.6 (P4.2, P4.4): the column (places and projects) beside the list; the chosen recording opens in a
            // drawer on the right.
            Group::make([
                View::make('filament.shell.column')->viewData(fn (): array => [
                    'places' => Shell::libraryPlaces(), 'active' => $this->activePlace(), 'projects' => Shell::projects(), 'placeAction' => 'openPlace',
                ]),
                // The search pill, then one compact toolbar (filament.library.toolbar), then the rows.
                Group::make([
                    RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                    EmbeddedTable::make(),
                    RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
                ])->extraAttributes(fn (): array => ['class' => 'el-list el-library-list'.($this->libraryLayout === 'table' ? ' el-library-table' : '')]),
            ])->extraAttributes(['class' => 'el-library el-with-col']),
            View::make('filament.library.detail')->viewData(fn (): array => ['detail' => $this->selectedRecording()]),
        ]);
    }

    /**
     * The Library's one compact toolbar (Show, Folder, Group by, Sort by) under the search pill, and the bulk actions in
     * the selection bar (since v1.6, P5.2). Registered when the page boots, so they are there however the page is
     * rendered. The toolbar's hook is not scoped by Filament, so it draws only for this page.
     */
    public function boot(): void
    {
        // Both are registered together: the scoped one says whether this panel's views have them already.
        if (! FilamentView::hasRenderHook(TablesRenderHook::SELECTION_INDICATOR_ACTIONS_BEFORE, self::class)) {
            // The classic toolbar under the search; since 2.0.0-p under the new one the pills, while filters are on.
            FilamentView::registerRenderHook(TablesRenderHook::TOOLBAR_AFTER, fn (): string => Livewire::current() instanceof self
                ? view(ConsoleV2::library() ? 'filament.v2.library-pills' : 'filament.library.toolbar')->render() : '');
            // Since v2.0 (ui_v2.library): the quick filters right after the search field; since 2.0.0-p [Filters n] and
            // Sort & display, the view's name before the search, and the pills on a row of their own under it.
            FilamentView::registerRenderHook(TablesRenderHook::TOOLBAR_SEARCH_AFTER, fn (): string => Livewire::current() instanceof self && ConsoleV2::library() ? view('filament.v2.library-toolbar')->render() : '');
            FilamentView::registerRenderHook(TablesRenderHook::TOOLBAR_SEARCH_BEFORE, fn (): string => Livewire::current() instanceof self && ConsoleV2::library() ? view('filament.v2.library-title')->render() : '');
            FilamentView::registerRenderHook(TablesRenderHook::SELECTION_INDICATOR_ACTIONS_BEFORE, fn (): ViewContract => view(ConsoleV2::library() ? 'filament.v2.bulk-bar' : 'filament.library.bulk-bar'), scopes: self::class);
            // Since U9b: the new Library's Load more, under the list.
            FilamentView::registerRenderHook(TablesRenderHook::CONTENT_AFTER, fn (): string => ConsoleV2::library() ? view('filament.v2.library-more')->render() : '', scopes: self::class);
        }
    }

    /**
     * The v2.0 row's star: pins the recording to the top (its Pinned section), or unpins it, as the classic row's star
     * does. Only a recording of this Library, and never in a read-only console.
     */
    public function togglePin(string $id): void
    {
        if (! ConsoleV2::library() || ConsoleAccess::readOnly()) {
            return;
        }
        $session = CaptureSessionResource::getEloquentQuery()->find($id);
        if (! $session instanceof CaptureSession) {
            return;
        }
        app(LibraryOrganiser::class)->update($session, ['pinned' => ! $session->pinned]);
        $this->flushCachedTableRecords();
    }

    /**
     * Since U19: Try again beside a row's "Couldn't convert to Roman Urdu": the view it opens in is made again (the
     * person's own recording; never from a read-only console).
     */
    public function retryViews(string $id): void
    {
        if (ConsoleAccess::readOnly()) {
            return;
        }
        $session = CaptureSession::query()->where('workspace_id', (int) CurrentWorkspace::id())->ofPerson()->with('finalTranscript')->find($id);
        $final = $session instanceof CaptureSession ? $session->finalTranscript : null;
        if ($final === null) {
            return;
        }
        try {
            app(TranscriptViews::class)->request($final);
        } catch (RuntimeException $e) {
            Notification::make()->title($e->getMessage())->url(NoticeTargets::forError($e))->danger()->send();

            return;
        }
        $this->flushCachedTableRecords();
    }

    /** The recording shown beside the list on wide screens (a plain click on a row picks it). */
    public function selectRecording(string $id): void
    {
        $this->selected = $id;
    }

    public function closeRecording(): void
    {
        $this->selected = null;
    }

    /** Send to WhatsApp from the drawer (the row's own is in its More menu). */
    public function drawerWhatsappAction(): Action
    {
        return WhatsAppActions::send(fn (): CaptureSession => $this->selectedRecording() ?? abort(404), name: 'drawerWhatsapp');
    }

    private function selectedRecording(): ?CaptureSession
    {
        if ($this->selected === null || $this->selected === '') {
            return null;
        }
        $session = CaptureSessionResource::getEloquentQuery()->with(['labels', 'markers'])->find($this->selected);

        return $session instanceof CaptureSession ? $session : null;
    }

    protected function getHeaderActions(): array
    {
        // Since 2.0.0-p (ui_v2.library) Record and Import are the sidebar's ● Record ▾, and the page has no header: its
        // name leads the one toolbar row.
        if (ConsoleAccess::readOnly() || ConsoleV2::library()) {
            return [];
        }

        return [
            // Opens the file picker of the drop zone below (several files at once, on phones too).
            Action::make('upload')
                ->label(__('Upload'))
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->alpineClickHandler("\$dispatch('el-upload-pick')"),
        ];
    }

    /**
     * What the uploader needs: its routes, the batch choices (client, language) and the limits it checks before
     * sending anything. The Upload page uses the same.
     *
     * @return array<string, mixed>
     */
    public static function uploaderConfig(): array
    {
        return [
            'store' => route('admin.uploads.store'),
            'csrf' => csrf_token(),
            'clients' => Client::query()->where('workspace_id', CurrentWorkspace::id())->where('user_id', CurrentWorkspace::userId())->orderByDesc('pinned')->orderBy('name')
                ->get(['id', 'name'])->map(fn (Client $c): array => ['value' => $c->id, 'label' => $c->name])->values()->all(),
            'languages' => LanguageChoice::selectOptions(),
            'language' => Shell::preferences()['language'],
            'extensions' => array_keys(ConsoleUploads::EXTENSIONS),
            'maxBytes' => (int) config('echoledger.max_recording_bytes'),
            'nativeHashMaxBytes' => self::NATIVE_HASH_MAX_BYTES,
        ];
    }

    /** Since 2.0.0-p (ui_v2.library): no header, the list starts under the one toolbar row, which carries the heading. */
    public function getHeader(): ?ViewContract
    {
        return ConsoleV2::library() ? view('filament.v2.library-header') : parent::getHeader();
    }

    public function getTitle(): string|Htmlable
    {
        // Since W-213 the classic Library's title in the console's language too (English reads exactly as before).
        return ConsoleV2::library() ? __('Library') : __((string) parent::getTitle());
    }

    /** A list page is the top of its section: no "Sessions > List" trail (C3). */
    public function getBreadcrumbs(): array
    {
        return [];
    }
}
