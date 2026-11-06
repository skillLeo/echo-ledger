<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\User;
use App\Support\RecordingConsent;
use Filament\Actions\Action;
use Filament\Pages\Dashboard;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * The recording-consent notice, shown once after sign-up (since v1.5): the words the apps show, and "I understand".
 * Until then every console page leads here (AccountGate); the profile keeps the notice afterwards.
 */
class RecordingConsentNotice extends Page
{
    protected static ?string $title = 'Before you record';

    protected static ?string $slug = 'welcome';

    protected static bool $shouldRegisterNavigation = false;

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                Text::make(RecordingConsent::TEXT),
                Text::make(RecordingConsent::APPS),
                Text::make('You are responsible for having the right to record. The Terms of Service and the Privacy Policy say more.'),
                Actions::make([
                    Action::make('understood')->label(RecordingConsent::CONFIRM)->icon(Heroicon::OutlinedCheck)
                        ->action(function (): void {
                            /** @var User $user */
                            $user = auth()->user();
                            $user->forceFill(['consent_seen_at' => now()])->save();
                            $this->redirect(Dashboard::getUrl());
                        }),
                    Action::make('terms')->label('Terms of Service')->color('gray')->url(route('legal.terms'), shouldOpenInNewTab: true),
                    Action::make('privacy')->label('Privacy Policy')->color('gray')->url(route('legal.privacy'), shouldOpenInNewTab: true),
                ])->key('consent-actions'),
            ]),
        ]);
    }
}
