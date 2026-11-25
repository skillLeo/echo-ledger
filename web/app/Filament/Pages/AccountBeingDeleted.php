<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Support\ConsoleDates;
use App\Filament\Support\Notice as Notification;
use App\Models\User;
use App\Services\Accounts\AccountDeletion;
use App\Services\SettingsRepository;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * What an account in its 7-day deletion grace sees after signing in (since v1.5): the date, "Keep my account" and
 * Sign out. Every other console page leads here meanwhile (AccountGate).
 */
class AccountBeingDeleted extends Page
{
    protected static ?string $title = 'Your account is being deleted';

    protected static ?string $slug = 'account-deletion';

    protected static bool $shouldRegisterNavigation = false;

    public function getTitle(): string
    {
        return __('Your account is being deleted');
    }

    public function content(Schema $schema): Schema
    {
        if (! $this->account()->isBeingDeleted()) {
            return $schema->components([
                Section::make()->schema([
                    Text::make(__('Your account is not being deleted.')),
                    Actions::make([Action::make('openDashboard')->label(__('Open the dashboard'))->url(Dashboard::getUrl())]),
                ]),
            ]);
        }

        return $schema->components([
            Section::make()->schema([
                Text::make(fn (): string => __('You asked to delete this account. It is erased on :date, with its recordings, transcripts, screens, files, projects and devices.', ['date' => $this->date()])),
                Text::make(__('Keep it, and everything stays as it was: sign your phone and computer in again afterwards.')),
                Actions::make([
                    Action::make('keepAccount')->label(__('Keep my account'))->icon(Heroicon::OutlinedCheckCircle)
                        ->action(function (): void {
                            app(AccountDeletion::class)->cancel($this->account());
                            Notification::make()->title(__('Your account stays'))->success()->send();
                            $this->redirect(Dashboard::getUrl());
                        }),
                    Action::make('signOut')->label(__('Sign out'))->color('gray')
                        ->action(function (): void {
                            Filament::auth()->logout();
                            session()->invalidate();
                            session()->regenerateToken();
                            $this->redirect(Filament::getPanel('app')->getLoginUrl() ?? '/app/login');
                        }),
                ])->key('deletion-actions'),
            ]),
        ]);
    }

    /** "November 24, 2026" · "24 نومبر 2026". */
    private function date(): string
    {
        $at = $this->account()->deletes_at?->copy()->setTimezone(app(SettingsRepository::class)->displayTimezone());

        return $at !== null ? ConsoleDates::format($at, 'F j, Y', 'j F Y') : '';
    }

    private function account(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
