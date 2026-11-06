<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use App\Exceptions\ApiError;
use App\Models\Device;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Accounts\AccountExports;
use App\Services\Accounts\AccountSessions;
use App\Services\Accounts\GoogleSignIn;
use App\Services\AuditLog;
use App\Services\PairingService;
use App\Services\SettingsRepository;
use App\Services\WebSessions;
use App\Support\RecordingConsent;
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
            Section::make('Recording consent')->schema([
                Text::make(RecordingConsent::TEXT.' '.RecordingConsent::APPS),
            ]),
            $this->emailsSection(),
            $this->dataSection(),
        ]);
    }

    /** "Export my data" (one ZIP, emailed, 24 hours) and "Delete my account" (the public page Google Play links to). */
    private function dataSection(): Section
    {
        return Section::make('Your data')
            ->schema([
                Text::make(fn (): string => $this->exportStatus()),
                Actions::make([
                    Action::make('exportData')->label('Export my data')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                        ->modalDescription('One ZIP with every recording\'s audio, its transcripts in each view that was made, the screens, and your projects\' files and notes. It is made in the background; the link comes by email and works for 24 hours.')
                        ->modalSubmitActionLabel('Make the export')
                        ->action(function (): void {
                            app(AccountExports::class)->start($this->account());
                            Notification::make()->title('Your export is being made')->body('The link comes by email.')->success()->send();
                        }),
                    Action::make('downloadExport')->label('Download my export')->icon(Heroicon::OutlinedDocumentArrowDown)->color('gray')
                        ->visible(fn (): bool => app(AccountExports::class)->latest($this->account())?->downloadable() === true)
                        ->url(fn (): ?string => app(AccountExports::class)->latest($this->account())?->downloadUrl()),
                    Action::make('deleteAccount')->label('Delete my account')->icon(Heroicon::OutlinedTrash)->color('danger')->link()
                        ->url(fn (): string => route('account.delete')),
                ])->key('data-actions'),
            ]);
    }

    private function exportStatus(): string
    {
        $export = app(AccountExports::class)->latest($this->account());

        return match (true) {
            $export === null => 'Download a copy of everything you keep here, or delete your account.',
            $export->status === 'making' => 'Your export is being made. The link comes by email.',
            $export->downloadable() => 'Your export is ready until '.$export->expires_at?->copy()->setTimezone(app(SettingsRepository::class)->displayTimezone())->format('M j, g:i A').'.',
            $export->status === 'failed' => 'The last export could not be made. Try again.',
            default => 'Your last export has expired. Make a new one any time.',
        };
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
