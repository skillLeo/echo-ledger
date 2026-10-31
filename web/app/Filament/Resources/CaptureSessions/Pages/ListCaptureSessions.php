<?php

declare(strict_types=1);

namespace App\Filament\Resources\CaptureSessions\Pages;

use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Filament\Resources\CaptureSessions\Widgets\WaitingDecisionsWidget;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Services\ConsoleUploads;
use App\Services\LibraryOrganiser;
use App\Services\Workspaces\CurrentWorkspace;
use App\Stt\LanguageChoice;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
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
use Livewire\Attributes\Url;

class ListCaptureSessions extends ListRecords
{
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

    /** The "Keep or discard?" card, only while recordings wait for a decision. */
    protected function getHeaderWidgets(): array
    {
        return [WaitingDecisionsWidget::class];
    }

    /**
     * The uploader (its drop zone shows while files are dragged over the page), then the list with its search pill
     * and smart-view chips, and on wide screens the chosen recording beside it.
     */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.library.uploader')->viewData(fn (): array => ['config' => self::uploaderConfig(), 'compact' => true]),
            Grid::make(['default' => 1, 'xl' => 2])->extraAttributes(['class' => 'el-library'])->schema([
                // The smart views are drawn as chips under the search pill (filament.library.view-chips).
                Group::make([
                    RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                    EmbeddedTable::make(),
                    RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
                ])->extraAttributes(['class' => 'el-list el-library-list']),
                View::make('filament.library.detail')->viewData(fn (): array => ['detail' => $this->selectedRecording()]),
            ]),
        ]);
    }

    /**
     * The smart views as the phone's filter chips, drawn between the search pill and the rows. Registered when the
     * page boots, so the chips are there however the page is rendered.
     */
    public function boot(): void
    {
        if (! FilamentView::hasRenderHook(TablesRenderHook::CONTENT_BEFORE, self::class)) {
            FilamentView::registerRenderHook(TablesRenderHook::CONTENT_BEFORE, fn (): ViewContract => view('filament.library.view-chips'), scopes: self::class);
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
            'clients' => Client::query()->where('workspace_id', CurrentWorkspace::id())->orderByDesc('pinned')->orderBy('name')
                ->get(['id', 'name'])->map(fn (Client $c): array => ['value' => $c->id, 'label' => $c->name])->values()->all(),
            'languages' => LanguageChoice::selectOptions(),
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
