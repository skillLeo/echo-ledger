<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\AppRelease;
use Filament\Pages\Page;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

/**
 * "Get the apps" (since v1.6, P1): where a new account lands after the web sign-up. The phone and computer apps
 * record calls and meetings; this page links the newest builds of the downloads page and says how to sign in there
 * (the same email). Reachable afterwards from the Dashboard's link; not in the sidebar.
 */
class GetTheApps extends Page
{
    protected static ?string $title = 'Get the apps';

    protected static ?string $slug = 'get-the-apps';

    protected static bool $shouldRegisterNavigation = false;

    public function getSubheading(): ?string
    {
        return 'Your account is ready. Record calls on your phone and meetings on your computer: sign in there with the same email.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.pages.get-the-apps')->viewData(fn (): array => [
                'releases' => collect(AppRelease::PLATFORMS)->mapWithKeys(fn (string $p): array => [$p => AppRelease::latest($p)])->filter()->all(),
                'labels' => AppRelease::LABELS,
            ]),
        ]);
    }
}
