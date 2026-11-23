<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\Workspace;
use App\Services\Health\ServerHealth;
use App\Services\Staff\StaffAccess;
use App\Services\Workspaces\PlanLimits;
use BackedEnum;
use Filament\Pages\Dashboard as FilamentDashboard;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

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

    /** @var list<array{where: string, php: string, checked_at: string|null, summary: string}>|null */
    private ?array $serverProblems = null;

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.admin.server-health')
                ->visible(fn (): bool => $this->serverProblems() !== [])
                ->viewData(fn (): array => ['problems' => $this->serverProblems()]),
            $this->getWidgetsContentComponent(),
            View::make('filament.pages.workspaces-overview')
                ->visible(fn (): bool => StaffAccess::can('customers.view'))
                ->viewData(fn (): array => ['rows' => $this->overview()]),
        ]);
    }

    /**
     * What the server's PHPs lack (W-INC-1124): the web PHP checks itself now, and the scheduled tasks' PHP (the queue's)
     * is shown by its last failed check until one passes.
     *
     * @return list<array{where: string, php: string, checked_at: string|null, summary: string}>
     */
    private function serverProblems(): array
    {
        if ($this->serverProblems !== null) {
            return $this->serverProblems;
        }
        $problems = [];
        $web = app(ServerHealth::class)->check();
        if (! ServerHealth::healthy($web)) {
            $problems[] = ['where' => 'Web PHP', 'php' => PHP_VERSION, 'checked_at' => null, 'summary' => ServerHealth::summary($web)];
        }
        $scheduled = ServerHealth::lastScheduledFailure();
        if ($scheduled !== null) {
            $problems[] = [
                'where' => 'PHP of the scheduled tasks and the queue', 'php' => $scheduled['php'],
                'checked_at' => Carbon::parse($scheduled['checked_at'])->format('Y-m-d H:i T'), 'summary' => ServerHealth::summary($scheduled),
            ];
        }

        return $this->serverProblems = $problems;
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
