<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Dashboard as FilamentDashboard;
use Filament\Support\Icons\Heroicon;

/** Filament's dashboard with the solid icon when active, like every other page in the sidebar. */
class Dashboard extends FilamentDashboard
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Home;
}
