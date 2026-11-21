<?php

declare(strict_types=1);

namespace App\Filament\Resources\CaptureSessions\Pages;

use App\Filament\Concerns\DropsRecordings;
use App\Filament\Pages\SharedWithMe;
use App\Filament\Pages\Upload;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Filament\Resources\CaptureSessions\Tables\CaptureSessionsTable;
use App\Filament\Resources\CaptureSessions\Widgets\FailedUploadsWidget;
use App\Filament\Resources\CaptureSessions\Widgets\PlanLimitWidget;
use App\Filament\Resources\CaptureSessions\Widgets\WaitingDecisionsWidget;
use App\Filament\Support\ConsoleV2;
use App\Filament\Support\LibraryView;
use App\Filament\Support\Notice as Notification;
use App\Filament\Support\SendsToWhatsApp;
use App\Filament\Support\Shell;
use App\Filament\Support\WhatsAppActions;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\User;
use App\Services\ConsoleUploads;
use App\Services\LibraryOrganiser;
use App\Services\RetentionPolicy;
use App\Services\Sharing\Teammates;
use App\Services\Workspaces\ConsoleAccess;
use App\Services\Workspaces\CurrentWorkspace;
use App\Stt\LanguageChoice;
use Filament\Actions\Action;
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
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Livewire;

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

    public function mount(): void
    {
        $user = auth()->user();
        $this->libraryLayout = Shell::preferences($user instanceof User ? $user : null)['layout'];
        parent::mount();
        $this->loadLibraryView();
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
        $this->v2ViewsOnce = null;
    }

    /** @var array<string, array{label: string, icon: string, count: int, url: string|null}>|null the folders, for this render */
    private ?array $foldersOnce = null;

    /** @var list<array{key: string, label: string, count: int|null, on: bool}>|null the saved views, for this render */
    private ?array $v2ViewsOnce = null;

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

    /**
     * The v2.0 Library's saved views as chips: All, Today, Untranscribed, Pinned and Voice notes, with Waiting and
     * Failed uploads only while they hold something. Each is a place openPlace() opens.
     *
     * @return list<array{key: string, label: string, count: int|null, on: bool}>
     */
    public function v2Views(): array
    {
        return $this->v2ViewsOnce ??= $this->listV2Views();
    }

    /** @return list<array{key: string, label: string, count: int|null, on: bool}> */
    private function listV2Views(): array
    {
        $tabs = $this->getCachedTabs();
        $badge = fn (string $key): ?int => isset($tabs[$key]) && is_numeric($tabs[$key]->getBadge()) ? (int) $tabs[$key]->getBadge() : null;
        $place = $this->activePlace();
        $voice = $this->folders()['voice_notes']['count'] ?? 0;
        $views = [
            ['key' => 'all', 'label' => __('All'), 'count' => null],
            ['key' => 'today', 'label' => __('Today'), 'count' => $badge('today')],
            ['key' => 'untranscribed', 'label' => __('Untranscribed'), 'count' => $badge('untranscribed')],
            ['key' => 'pinned', 'label' => __('Pinned'), 'count' => $badge('pinned')],
            ['key' => 'folder:voice_notes', 'label' => __('Voice notes'), 'count' => $voice > 0 ? $voice : null],
        ];
        foreach (['waiting' => __('Waiting'), 'failed' => __('Failed uploads')] as $key => $label) {
            if ($badge($key) !== null || $place === $key) {
                $views[] = ['key' => $key, 'label' => $label, 'count' => $badge($key)];
            }
        }

        return array_map(fn (array $v): array => $v + ['on' => $v['key'] === $place], $views);
    }

    /**
     * The v2.0 Library's "Views ▾": every view of the classic Library's All recordings menu, in its order, with
     * Untranscribed last, each with its count.
     *
     * @return list<array{key: string, label: string, count: int|null, on: bool}>
     */
    public function v2ViewMenu(): array
    {
        $tabs = $this->getCachedTabs();
        $place = $this->activePlace();
        $menu = [['key' => 'all', 'label' => __('All recordings'), 'count' => null]];
        foreach ([...array_keys(self::VIEW_LABELS), 'untranscribed'] as $key) {
            if (isset($tabs[$key])) {
                $badge = $tabs[$key]->getBadge();
                $menu[] = ['key' => $key, 'label' => (string) $tabs[$key]->getLabel(), 'count' => is_numeric($badge) ? (int) $badge : null];
            }
        }

        return array_map(fn (array $v): array => [...$v, 'label' => __($v['label']), 'on' => $v['key'] === $place], $menu);
    }

    /** How many calls wait for Keep or Discard (the v2.0 Library's quiet line). */
    public function v2Waiting(): int
    {
        $tabs = $this->getCachedTabs();

        return isset($tabs['waiting']) && is_numeric($tabs['waiting']->getBadge()) ? (int) $tabs['waiting']->getBadge() : 0;
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
        } elseif (array_key_exists($key, self::VIEW_LABELS) || ($key === 'untranscribed' && ConsoleV2::library())) {
            $this->folder = null;
            $this->activeTab = $key;
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

        return array_key_exists($tab, self::VIEW_LABELS) || ($tab === 'untranscribed' && ConsoleV2::library()) ? $tab : 'all';
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

    /** How many rows the new Library shows now; Load more adds a chunk, and a new search, filter or order starts over. */
    public int $v2Shown = self::V2_CHUNK;

    /** Load more (also when its button scrolls into view): the next chunk of the list, the counts unchanged. */
    public function v2LoadMore(): void
    {
        if (ConsoleV2::library()) {
            $this->v2Shown += self::V2_CHUNK;
        }
    }

    /**
     * Since U9b (WEB-LIB-033, 2,000+ recordings): the new Library is one growing list instead of pages of 5 to 50. Its
     * first v2Shown rows, with the true total, so "Showing 1 to 50 of 2,000" and the counts stay right; Load more grows
     * it. Classic keeps its pages.
     */
    protected function paginateTableQuery(Builder $query): Paginator|CursorPaginator
    {
        if (! ConsoleV2::library()) {
            return parent::paginateTableQuery($query);
        }
        $total = (clone $query)->toBase()->getCountForPagination();
        $items = (clone $query)->limit(max(1, $this->v2Shown))->get();

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
        foreach (CaptureSession::onlyTrashed()->where('workspace_id', CurrentWorkspace::id())->ofPerson()->whereIn('id', array_slice(array_map('strval', $ids), 0, 200))->get() as $session) {
            if ($session->restorable() && Gate::allows('restore', $session)) {
                $policy->restore($session);
                $back++;
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
                Group::make([
                    View::make('filament.v2.library-views'),
                    RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                    EmbeddedTable::make(),
                    RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
                ])->extraAttributes(['class' => 'v2-library el-library']),
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
            FilamentView::registerRenderHook(TablesRenderHook::TOOLBAR_AFTER, fn (): string => Livewire::current() instanceof self && ! ConsoleV2::library() ? view('filament.library.toolbar')->render() : '');
            // Since v2.0 (ui_v2.library): the quick filters right after the search field.
            FilamentView::registerRenderHook(TablesRenderHook::TOOLBAR_SEARCH_AFTER, fn (): string => Livewire::current() instanceof self && ConsoleV2::library() ? view('filament.v2.library-toolbar')->render() : '');
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
        if (ConsoleAccess::readOnly()) {
            return [];
        }
        // Since v2.0 (ui_v2.library): Upload recording is the one primary action (the web's main action, PLAN_V2_PARITY
        // section 2), with a voice note recorded here beside it.
        if (ConsoleV2::library()) {
            return [
                Action::make('upload')->label(__('Upload recording'))
                    ->alpineClickHandler("\$dispatch('el-upload-pick')"),
                Action::make('voiceNote')->label(__('New voice note'))->color('gray')->outlined()
                    ->url(fn (): string => Upload::getUrl(['record' => 1])),
            ];
        }

        return [
            // Opens the file picker of the drop zone below (several files at once, on phones too).
            Action::make('upload')
                ->label('Upload')
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

    public function getTitle(): string|Htmlable
    {
        return ConsoleV2::library() ? __('Library') : parent::getTitle();
    }

    /** A list page is the top of its section: no "Sessions > List" trail (C3). */
    public function getBreadcrumbs(): array
    {
        return [];
    }
}
