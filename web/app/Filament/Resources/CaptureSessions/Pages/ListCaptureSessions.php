<?php

declare(strict_types=1);

namespace App\Filament\Resources\CaptureSessions\Pages;

use App\Filament\Concerns\DropsRecordings;
use App\Filament\Pages\SharedWithMe;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Filament\Resources\CaptureSessions\Widgets\FailedUploadsWidget;
use App\Filament\Resources\CaptureSessions\Widgets\WaitingDecisionsWidget;
use App\Filament\Support\LibraryView;
use App\Filament\Support\SendsToWhatsApp;
use App\Filament\Support\Shell;
use App\Filament\Support\WhatsAppActions;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\User;
use App\Services\ConsoleUploads;
use App\Services\LibraryOrganiser;
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
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Database\Eloquent\Builder;
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

    /** The group counts are counted once per render (LibraryView). */
    public function rendering(): void
    {
        LibraryView::forgetCounts();
    }

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

        return $tabs;
    }

    /**
     * The smart folders as chips (v1.5): each one's label, how many recordings it holds and whether it is chosen.
     *
     * @return array<string, array{label: string, count: int, url: string|null}>
     */
    public function folders(): array
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
        } elseif (array_key_exists($key, self::VIEW_LABELS)) {
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

        return array_key_exists($tab, self::VIEW_LABELS) ? $tab : 'all';
    }

    /** A recording moved by a drop on the sidebar: the list is drawn again with it in its new place. */
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
        return [WaitingDecisionsWidget::class, FailedUploadsWidget::class];
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
            FilamentView::registerRenderHook(TablesRenderHook::TOOLBAR_AFTER, fn (): string => Livewire::current() instanceof self ? view('filament.library.toolbar')->render() : '');
            FilamentView::registerRenderHook(TablesRenderHook::SELECTION_INDICATOR_ACTIONS_BEFORE, fn (): ViewContract => view('filament.library.bulk-bar'), scopes: self::class);
        }
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

    /** A list page is the top of its section: no "Sessions > List" trail (C3). */
    public function getBreadcrumbs(): array
    {
        return [];
    }
}
