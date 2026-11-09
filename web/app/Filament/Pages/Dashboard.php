<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Dashboard as FilamentDashboard;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Filament's dashboard with the solid icon when active, like every other page in the sidebar. Since v1.6 (P4) it is
 * Home, as in the computer app: what needs you, your devices and your numbers.
 */
class Dashboard extends FilamentDashboard
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Home;

    protected static ?string $navigationLabel = 'Home';

    public function getTitle(): string|Htmlable
    {
        return 'Home';
    }
}
