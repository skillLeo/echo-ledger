<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use App\Exceptions\ApiError;
use App\Filament\Concerns\WatchesPairing;
use App\Filament\Support\PlanLock;
use App\Models\ConnectedApp;
use App\Models\Device;
use App\Models\KnownBrowser;
use App\Models\UpgradeRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Accounts\AccountExports;
use App\Services\Accounts\AccountSessions;
use App\Services\Accounts\EmailPreferences;
use App\Services\Accounts\GoogleSignIn;
use App\Services\AuditLog;
use App\Services\Connector\ConnectedApps;
use App\Services\Entitlements\Entitlements;
use App\Services\Entitlements\TranscriptionKeys;
use App\Services\Entitlements\UpgradeRequests;
use App\Services\PairingService;
use App\Services\SettingsRepository;
use App\Services\WebSessions;
use App\Services\Workspaces\PlanLimits;
use App\Support\ClientCountry;
use App\Support\RecordingConsent;
use chillerlan\QRCode\QRCode;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * The profile (since v1.5): name, email, password and two-step sign-in as before, and below them the account's own
 * things: Sign in with Google, Devices (every phone, computer and browser signed in, "Sign in a device" with a pairing
 * QR for one's own account, Revoke and "Sign out everywhere"), and Connected apps (Claude, ChatGPT) with Disconnect.
 */
class EditProfile extends BaseEditProfile
{
    use WatchesPairing;

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
            $this->planSection(),
            $this->googleSection(),
            $this->transcriptionKeySection(),
            $this->devicesSection(),
            $this->connectedAppsSection(),
            Section::make('Recording consent')->schema([
                Text::make(RecordingConsent::TEXT.' '.RecordingConsent::APPS),
            ]),
            $this->emailsSection(),
            $this->supportAccessSection(),
            $this->dataSection(),
        ]);
    }

    /** Profile → Your plan (since v1.6): the plan with its badge, until when, and the plans side by side. */
    private function planSection(): Section
    {
        return Section::make('Your plan')
            ->schema([
                Text::make(function (): HtmlString {
                    $workspace = (int) ($this->account()->current_workspace_id ?? 0);
                    $plan = app(Entitlements::class)->plan($workspace);
                    $until = Workspace::query()->whereKey($workspace)->value('plan_expires_at');
                    $request = app(UpgradeRequests::class)->latestFor($this->account());

                    return new HtmlString(PlanLock::badgeFor($plan, locked: false)->toHtml().' '.e($plan->name)
                        .($until !== null ? e(' until '.Carbon::parse((string) $until)->setTimezone(app(SettingsRepository::class)->displayTimezone())->format('M j, Y')) : '')
                        .($request?->status === UpgradeRequest::PENDING ? e(' · your request for '.$request->plan.' is waiting') : ''));
                }),
                Actions::make([PlanLock::sheet('comparePlans', null, 'Compare plans')])->key('plan-actions'),
            ]);
    }

    /**
     * Profile → Transcription key (since v1.6, the Free plan): the person's own free Groq key, set up in three steps.
     * Groq is asked once whether it takes the key; it is kept encrypted and only its last four characters are shown.
     */
    private function transcriptionKeySection(): Section
    {
        return Section::make('Transcription key')
            ->description('On the Free plan your recordings are transcribed with your own free Groq key. Only your recordings use it.')
            ->schema([
                Text::make(fn (): string => $this->keyStatus()),
                Actions::make([
                    Action::make('addTranscriptionKey')
                        ->label(fn (): string => app(TranscriptionKeys::class)->describe($this->account())['set'] ? 'Change key' : 'Add your Groq key')
                        ->icon(Heroicon::OutlinedKey)
                        ->modalHeading('Add your free Groq key')
                        ->modalDescription('Three steps, about two minutes. Groq is free for this.')
                        ->schema([
                            Text::make('1. Open console.groq.com and sign in (with Google is fine).'),
                            Text::make('2. Open API Keys, press Create API Key, give it a name such as EchoLedger, and copy the key.'),
                            Text::make('3. Paste it here and press Save. We check it with Groq once and keep it locked; only your recordings use it.'),
                            TextInput::make('key')->label('Your Groq key')->placeholder('gsk_…')->password()->revealable()->required()->maxLength(300)->autocomplete('off'),
                        ])
                        ->modalSubmitActionLabel('Save')
                        ->action(function (array $data, Action $action): void {
                            try {
                                app(TranscriptionKeys::class)->put($this->account(), TranscriptionKeys::PROVIDER, (string) $data['key']);
                            } catch (ApiError $e) {
                                Notification::make()->title($e->getMessage())->danger()->send();
                                $action->halt();
                            }
                            Notification::make()->title('Your Groq key is saved')->body('New recordings are transcribed with it.')->success()->send();
                        }),
                    Action::make('removeTranscriptionKey')->label('Remove')->icon(Heroicon::OutlinedTrash)->color('danger')->link()
                        ->visible(fn (): bool => app(TranscriptionKeys::class)->describe($this->account())['set'])
                        ->requiresConfirmation()
                        ->modalDescription('New recordings are not transcribed until you add a key again (on the Free plan).')
                        ->action(function (): void {
                            app(TranscriptionKeys::class)->forget($this->account());
                            Notification::make()->title('Your Groq key is removed')->success()->send();
                        }),
                ])->key('transcription-key-actions'),
            ]);
    }

    private function keyStatus(): string
    {
        $key = app(TranscriptionKeys::class)->describe($this->account());
        $workspace = (int) ($this->account()->current_workspace_id ?? 0);
        $platform = $workspace > 0 && app(PlanLimits::class)->allows($workspace, 'platform_transcription', $this->account()->id);

        return match (true) {
            $key['set'] && $platform => "Saved: the key ending in {$key['last4']}. Your plan transcribes for you, so it is not used now.",
            $key['set'] => "Saved: the key ending in {$key['last4']}. New recordings are transcribed with it.",
            $platform => 'Your plan transcribes for you: no key needed.',
            default => 'No key yet: new recordings are not transcribed until you add one (or transcribe them on your computer).',
        };
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
                    Action::make('downloadExport')->label('Download my export')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
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

    /**
     * Profile → Emails (since v1.5, Phase 1C): on or off per category, as the apps' notification categories; Account &
     * security emails always come and say so.
     */
    private function emailsSection(): Section
    {
        return Section::make('Emails')
            ->description('Choose which emails you get. The bell in the console and the apps tell you either way.')
            ->schema([
                View::make('filament.profile.emails')->viewData(fn (): array => ['rows' => EmailPreferences::rows($this->account()), 'always' => EmailPreferences::ALWAYS]),
            ]);
    }

    /**
     * Profile → Support access (since v1.6, P2.2): EchoLedger's support never sees anyone's recordings, unless the
     * person lets them for a while, to help with a problem. Until the end staff may open the recordings and their
     * transcripts; every look is in the person's activity log. Off by default; Stop now ends it at once.
     */
    private function supportAccessSection(): Section
    {
        return Section::make('Support access')
            ->description('Support never sees your recordings. To get help with one, you can let support look for a while; every look is in your activity log.')
            ->schema([
                Text::make(fn (): string => ($until = $this->account()->support_access_until) !== null && $until->isFuture()
                    ? 'On until '.$until->copy()->setTimezone(app(SettingsRepository::class)->displayTimezone())->format('M j, Y H:i').'.'
                    : 'Off.'),
                Actions::make([
                    Action::make('grantSupportAccess')->label('Let support look')->icon(Heroicon::OutlinedLifebuoy)->color('gray')
                        ->modalDescription('Support can open your recordings and their transcripts until the time you choose. You can stop it at any time.')
                        ->schema([Select::make('hours')->label('For')->required()->selectablePlaceholder(false)->default('24')
                            ->options(['1' => '1 hour', '24' => '1 day', '168' => '7 days'])])
                        ->action(function (array $data): void {
                            $hours = in_array((int) ($data['hours'] ?? 0), [1, 24, 168], true) ? (int) $data['hours'] : 24;
                            $user = $this->account();
                            $user->forceFill(['support_access_until' => now()->addHours($hours)])->save();
                            app(AuditLog::class)->record('support_access_granted', $user->id, $user, "Support access on for {$hours} h");
                            Notification::make()->title('Support can look until '.$user->support_access_until?->copy()->setTimezone(app(SettingsRepository::class)->displayTimezone())->format('M j, H:i'))->success()->send();
                        }),
                    Action::make('endSupportAccess')->label('Stop now')->icon(Heroicon::OutlinedXMark)->color('gray')
                        ->visible(fn (): bool => $this->account()->support_access_until?->isFuture() === true)
                        ->action(function (): void {
                            $user = $this->account();
                            $user->forceFill(['support_access_until' => null])->save();
                            app(AuditLog::class)->record('support_access_ended', $user->id, $user, 'Support access turned off');
                            Notification::make()->title('Support access is off')->success()->send();
                        }),
                ])->key('support-access-actions'),
            ]);
    }

    /** One category of emails on or off (Profile → Emails). */
    public function toggleEmail(string $category): void
    {
        if (! isset(EmailPreferences::CATEGORIES[$category])) {
            return;
        }
        $on = ! EmailPreferences::wants($this->account(), $category);
        EmailPreferences::set($this->account(), $category, $on);
        Notification::make()->title(EmailPreferences::CATEGORIES[$category]['label'].' emails '.($on ? 'on' : 'off'))->success()->send();
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
                            $this->watchPairing($code);

                            return ['pairing' => ['code' => $code, 'qr' => (new QRCode)->render($pairing->qrPayload($code)), 'url' => rtrim((string) config('app.url'), '/')]];
                        })
                        ->schema([ViewField::make('pairing')->view('filament.pair-device')->hiddenLabel()])
                        ->modalSubmitAction(false)->modalCancelActionLabel('Done')->modalWidth('md'),
                    Action::make('signOutEverywhere')->label('Sign out everywhere')->icon(Heroicon::OutlinedArrowRightStartOnRectangle)->color('danger')
                        ->requiresConfirmation()
                        ->modalDescription('Every phone and computer is signed out (sign them in again to keep recording), every browser, this one included, and every connected app such as Claude or ChatGPT.')
                        ->action(function (): void {
                            app(AccountSessions::class)->signOutEverywhere($this->account());
                            Filament::auth()->logout();
                            session()->invalidate();
                            session()->regenerateToken();
                            $this->redirect(Filament::getPanel('app')->getLoginUrl() ?? '/app/login');
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
                'name' => $d->readableName(),
                'status' => $d->status(),
                'status_label' => Device::STATUS_LABELS[$d->status()],
                'signed_out' => $d->signed_out_at?->diffForHumans(),
                'platform' => $d->platform->getLabel(),
                'workspace' => $workspaces[$d->getAttribute('workspace_id')] ?? 'A workspace',
                'last_seen' => $d->last_seen_at?->diffForHumans(),
                'country' => $d->last_country,
            ])->all(),
            'browsersAvailable' => WebSessions::available(),
            'browsers' => $this->browsersWithCountry(),
        ];
    }

    /**
     * The console browsers with the country of their address when the server's proxy named it: this browser's from
     * the request, the others' from the account's last console sign-in from that address (KnownBrowsers).
     *
     * @return list<array<string, mixed>>
     */
    private function browsersWithCountry(): array
    {
        $browsers = app(WebSessions::class)->list($this->account()->id, session()->getId());
        $countries = KnownBrowser::query()->where('user_id', $this->account()->id)->whereNotNull('country')
            ->whereIn('ip', array_values(array_filter(array_column($browsers, 'ip'))))
            ->orderByDesc('last_used_at')->get(['ip', 'country'])->unique('ip')->pluck('country', 'ip');

        return array_map(fn (array $browser): array => [
            ...$browser,
            'country' => $browser['current'] ? (ClientCountry::of(request()) ?? $countries[$browser['ip']] ?? null) : ($countries[$browser['ip']] ?? null),
        ], $browsers);
    }

    public function revokeDevice(string $id): void
    {
        $done = app(AccountSessions::class)->revokeDevice($this->account(), $id);
        Notification::make()->title($done ? 'Device signed out' : 'That device was already signed out')->success()->send();
    }

    /** "Remove from list" (since v1.6): a signed-out device's row goes. */
    public function removeDevice(string $id): void
    {
        $done = app(AccountSessions::class)->removeDevice($this->account(), $id);
        Notification::make()->title($done ? 'Removed from the list' : 'That device was already removed')->success()->send();
    }

    public function signOutBrowser(string $id): void
    {
        $done = app(WebSessions::class)->revoke($this->account()->id, $id, session()->getId());
        Notification::make()->title($done ? 'Browser signed out' : 'That browser was already signed out')->success()->send();
    }

    /** Profile → Connected apps (since v1.5): Claude, ChatGPT and other apps that read this account's recordings. */
    private function connectedAppsSection(): Section
    {
        return Section::make('Connected apps')
            ->description('Apps you allowed to read your recordings. They only read; Disconnect ends their access at once.')
            ->schema([
                View::make('filament.profile.connected-apps')->viewData(fn (): array => $this->connectedAppsData()),
            ]);
    }

    /** @return array<string, mixed> */
    public function connectedAppsData(): array
    {
        $timezone = app(SettingsRepository::class)->displayTimezone();

        return [
            'apps' => app(ConnectedApps::class)->forUser($this->account())->map(fn (ConnectedApp $app): array => [
                'id' => $app->id,
                'name' => ConnectedApps::name($app->client_id),
                'connected' => $app->created_at->copy()->setTimezone($timezone)->format('M j, Y'),
                'last_used' => $app->last_used_at?->diffForHumans(),
            ])->all(),
        ];
    }

    public function disconnectApp(int $id): void
    {
        $done = app(ConnectedApps::class)->disconnect($this->account(), $id);
        Notification::make()->title($done ? 'App disconnected' : 'That app was already disconnected')->success()->send();
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
