<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use App\Exceptions\ApiError;
use App\Models\Device;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Accounts\AccountSessions;
use App\Services\Accounts\GoogleSignIn;
use App\Services\AuditLog;
use App\Services\PairingService;
use App\Services\WebSessions;
use chillerlan\QRCode\QRCode;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;

/**
 * The profile (since v1.5): name, email, password and two-step sign-in as before, and below them the account's own
 * things: Sign in with Google, and Devices (every phone, computer and browser signed in, "Sign in a device" with a
 * pairing QR for one's own account, Revoke and "Sign out everywhere").
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
            $this->devicesSection(),
            $this->emailsSection(),
        ]);
    }

    /** Emails that are a choice (sign-in alerts, invitations and the like always come). */
    private function emailsSection(): Section
    {
        return Section::make('Emails')
            ->schema([
                Text::make(fn (): string => $this->account()->notify_transcript_ready
                    ? 'You get an email when a transcript of yours is ready.'
                    : 'No email when a transcript is ready: the bell in the console and the apps tell you.'),
                Actions::make([
                    Action::make('toggleTranscriptEmails')->color('gray')->icon(Heroicon::OutlinedEnvelope)
                        ->label(fn (): string => $this->account()->notify_transcript_ready ? 'Stop the transcript emails' : 'Email me when a transcript is ready')
                        ->action(function (): void {
                            $on = ! $this->account()->notify_transcript_ready;
                            $this->account()->forceFill(['notify_transcript_ready' => $on])->save();
                            Notification::make()->title($on ? 'Transcript emails on' : 'Transcript emails off')->success()->send();
                        }),
                ])->key('email-actions'),
            ]);
    }

    /** Profile → Devices: every phone, computer and browser signed in to this account, with Revoke. */
    private function devicesSection(): Section
    {
        return Section::make('Devices')
            ->description('Where your account is signed in. Revoke anything you do not recognise.')
            ->schema([
                View::make('filament.profile.devices')->viewData(fn (): array => $this->devicesData()),
                Actions::make([
                    Action::make('signInDevice')->label('Sign in a device')->icon(Heroicon::OutlinedQrCode)
                        ->modalHeading('Sign in a phone or computer')
                        ->modalDescription('Scan the QR code in the EchoLedger app, or type the code. It signs the app in to your account, works once and expires in 10 minutes.')
                        // fillForm runs once on mount, so the code is made exactly once per modal.
                        ->fillForm(function (PairingService $pairing): array {
                            try {
                                $code = $pairing->createCode($this->account());
                            } catch (ApiError $e) {
                                return ['pairing' => ['error' => $e->getMessage()]]; // the plan's device limit
                            }

                            return ['pairing' => ['code' => $code, 'qr' => (new QRCode)->render($pairing->qrPayload($code)), 'url' => rtrim((string) config('app.url'), '/')]];
                        })
                        ->schema([ViewField::make('pairing')->view('filament.pair-device')->hiddenLabel()])
                        ->modalSubmitAction(false)->modalCancelActionLabel('Done')->modalWidth('md'),
                    Action::make('signOutEverywhere')->label('Sign out everywhere')->icon(Heroicon::OutlinedArrowRightStartOnRectangle)->color('danger')
                        ->requiresConfirmation()
                        ->modalDescription('Every phone and computer is signed out (sign them in again to keep recording) and every browser, this one included.')
                        ->action(function (): void {
                            app(AccountSessions::class)->signOutEverywhere($this->account());
                            Filament::auth()->logout();
                            session()->invalidate();
                            session()->regenerateToken();
                            $this->redirect(Filament::getPanel('admin')->getLoginUrl() ?? '/admin/login');
                        }),
                ])->key('device-actions'),
            ]);
    }

    /** @return array<string, mixed> */
    public function devicesData(): array
    {
        $devices = app(AccountSessions::class)->devices($this->account());
        $workspaces = Workspace::query()->whereIn('id', $devices->pluck('workspace_id')->unique()->all())->pluck('name', 'id');

        return [
            'devices' => $devices->map(fn (Device $d): array => [
                'id' => $d->id,
                'name' => $d->name,
                'platform' => $d->platform->getLabel(),
                'workspace' => $workspaces[$d->getAttribute('workspace_id')] ?? 'A workspace',
                'last_seen' => $d->last_seen_at?->diffForHumans(),
                'country' => $d->last_country,
            ])->all(),
            'browsersAvailable' => WebSessions::available(),
            'browsers' => app(WebSessions::class)->list($this->account()->id, session()->getId()),
        ];
    }

    public function revokeDevice(string $id): void
    {
        $done = app(AccountSessions::class)->revokeDevice($this->account(), $id);
        Notification::make()->title($done ? 'Device revoked' : 'That device was already revoked')->success()->send();
    }

    public function signOutBrowser(string $id): void
    {
        $done = app(WebSessions::class)->revoke($this->account()->id, $id, session()->getId());
        Notification::make()->title($done ? 'Browser signed out' : 'That browser was already signed out')->success()->send();
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
