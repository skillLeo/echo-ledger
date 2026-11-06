<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use App\Models\User;
use App\Services\Accounts\GoogleSignIn;
use App\Services\AuditLog;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;

/**
 * The profile (since v1.5): name, email, password and two-step sign-in as before, and below them the account's own
 * things: Sign in with Google.
 */
class EditProfile extends BaseEditProfile
{
    public function mount(): void
    {
        parent::mount();
        $status = session()->pull('google.status');
        if (is_string($status)) {
            Notification::make()->title($status)->info()->send();
        }
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getFormContentComponent(),
            ...Arr::wrap($this->getMultiFactorAuthenticationContentComponent()),
            $this->googleSection(),
        ]);
    }

    private function googleSection(): Section
    {
        return Section::make('Sign in with Google')
            ->visible(fn (): bool => app(GoogleSignIn::class)->configured() || $this->account()->google_id !== null)
            ->schema([
                Text::make(fn (): string => $this->account()->google_id !== null
                    ? 'A Google account is linked: you can sign in with it as well as with your password.'
                    : 'Link a Google account to sign in with it as well as with your password.'),
                Actions::make([
                    Action::make('linkGoogle')->label('Link a Google account')->icon(Heroicon::OutlinedLink)->color('gray')
                        ->visible(fn (): bool => $this->account()->google_id === null && app(GoogleSignIn::class)->configured())
                        ->url(fn (): string => route('auth.google.redirect', ['intent' => 'link'])),
                    Action::make('unlinkGoogle')->label('Unlink Google')->icon(Heroicon::OutlinedXMark)->color('danger')
                        ->visible(fn (): bool => $this->account()->google_id !== null)
                        ->requiresConfirmation()
                        ->modalDescription('You then sign in with your email and password. Never set one? Use "Forgot password?" on the sign-in page.')
                        ->action(function (): void {
                            $this->account()->forceFill(['google_id' => null])->save();
                            app(AuditLog::class)->record('google_unlinked', $this->account()->id, $this->account());
                            Notification::make()->title('Google unlinked')->success()->send();
                        }),
                ])->key('google-actions'),
            ]);
    }

    private function account(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
