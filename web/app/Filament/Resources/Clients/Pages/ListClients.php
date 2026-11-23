<?php

declare(strict_types=1);

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Support\ConsoleV2;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;

class ListClients extends ListRecords
{
    protected static string $resource = ClientResource::class;

    /**
     * Since docs/PLAN_NAV_3_LEVELS.md (Projects' panel): All projects (''), Pinned or Recent (those with a recording in
     * the last 30 days, the latest first), every level of the tree; in the address, so the panel's links and Back open it.
     */
    public string $show = '';

    /**
     * In the address with the three levels only: without them the page's address stays as it was.
     *
     * @return array<string, array{except: string}>
     */
    protected function queryString(): array
    {
        return ConsoleV2::frame() ? ['show' => ['except' => '']] : [];
    }

    /** With the three levels + New project is the panel's, only there. */
    protected function getHeaderActions(): array
    {
        return ConsoleV2::frame() ? [] : [CreateAction::make()->label(__('New project'))->icon('heroicon-o-plus')];
    }

    /** The phone's flat list with its search pill, one column wide. */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Group::make([
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
            ])->extraAttributes(ConsoleV2::frame() ? ['class' => 'el-list v2-projects-overview'] : ['class' => 'el-list', 'style' => 'max-width: 880px']),
        ]);
    }

    /** A list page is the top of its section: no "Sessions > List" trail (C3). */
    public function getBreadcrumbs(): array
    {
        return [];
    }
}
