<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\Workspace;
use App\Services\Staff\StaffAccess;
use App\Services\Workspaces\PlanLimits;
use BackedEnum;
use Filament\Pages\Dashboard as FilamentDashboard;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * The admin panel's home (since v1.6, P2.1): every workspace with its plan and this month's totals. Names and numbers
 * only, never anyone's recordings.
 */
class Dashboard extends FilamentDashboard
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Home;

    protected static ?string $title = 'Overview';

    public static function canAccess(): bool
    {
        return StaffAccess::isStaff();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getWidgetsContentComponent(),
            View::make('filament.pages.workspaces-overview')
                ->visible(fn (): bool => StaffAccess::can('customers.view'))
                ->viewData(fn (): array => ['rows' => $this->overview()]),
        ]);
    }

    /** @return list<array{name: string, plan: string, members: int, devices: int, minutes: float}> */
    private function overview(): array
    {
        $limits = app(PlanLimits::class);

        return Workspace::query()->with('plan')->withCount('members')->orderBy('name')->limit(200)->get()
            ->map(fn (Workspace $w): array => [
                'name' => $w->name, 'plan' => $w->plan->name, 'members' => (int) $w->getAttribute('members_count'),
                'devices' => $limits->devicesUsed($w->id), 'minutes' => $limits->minutesUsed($w->id),
            ])->values()->all();
    }
}
