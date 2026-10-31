<?php

declare(strict_types=1);

namespace App\Filament\Resources\CaptureSessions\Pages;

use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Filament\Resources\CaptureSessions\Widgets\WaitingDecisionsWidget;
use App\Models\Client;
use App\Services\ConsoleUploads;
use App\Services\LibraryOrganiser;
use App\Services\Workspaces\CurrentWorkspace;
use App\Stt\LanguageChoice;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;

class ListCaptureSessions extends ListRecords
{
    protected static string $resource = CaptureSessionResource::class;

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

    /** The upload drop zone sits above the smart views and the list. */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.library.uploader')->viewData(fn (): array => ['config' => $this->uploaderConfig()]),
            $this->getTabsContentComponent(),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
            EmbeddedTable::make(),
            RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
        ]);
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
     * sending anything.
     *
     * @return array<string, mixed>
     */
    protected function uploaderConfig(): array
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
