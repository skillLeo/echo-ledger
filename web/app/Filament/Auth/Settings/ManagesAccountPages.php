<?php

declare(strict_types=1);

namespace App\Filament\Auth\Settings;

use App\Exceptions\ApiError;
use App\Filament\Pages\AccountBeingDeleted;
use App\Filament\Support\Notice as Notification;
use App\Filament\Support\PlanLock;
use App\Filament\Support\UpgradeFlow;
use App\Models\ConnectedApp;
use App\Models\Device;
use App\Services\Accounts\AccountExports;
use App\Services\Accounts\AccountSessions;
use App\Services\AuditLog;
use App\Services\Connector\ConnectedApps;
use App\Services\PairingService;
use App\Services\SettingsRepository;
use App\Support\Formats;
use App\Support\RecordingConsent;
use chillerlan\QRCode\QRCode;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Settings › Profile, Devices and Privacy & data.
 */
trait ManagesAccountPages
{
    /** Profile: the name and the email, edited in place; "Save changes" once something changed. */
    public function form(Schema $schema): Schema
    {
        $name = $this->getNameFormComponent();
        $email = $this->getEmailFormComponent();
        foreach (['profile.name' => $name, 'profile.email' => $email] as $id => $field) {
            if ($field instanceof Field) {
                $field->extraFieldWrapperAttributes(['id' => 'setting-'.$id, 'tabindex' => '-1', 'data-setting' => $id]);
            }
        }

        return $schema->inlineLabel()->components([$name, $email, $this->getCurrentPasswordFormComponent()]);
    }

    /** @return list<Component> */
    private function profileComponents(): array
    {
        return [
            $this->getFormContentComponent()->extraAttributes(['class' => 'el-set-form', 'x-data' => '{ changed: false }', 'x-on:input' => 'changed = true']),
            View::make('filament.settings.pages.profile')->viewData(fn (): array => $this->profileData()),
        ];
    }

    /** @return array<int|string, Action> */
    protected function getFormActions(): array
    {
        return [$this->getSaveFormAction()->label('Save changes')->extraAttributes(['x-show' => 'changed', 'x-cloak' => true])];
    }

    /** @return array<string, mixed> */
    public function profileData(): array
    {
        $context = $this->context();
        $workspace = $context->workspace();

        return [
            'team' => $context->flag('team_member') && $workspace !== null,
            'workspace' => $workspace !== null ? $workspace->name.($context->role() !== null ? ', '.$context->role()->label() : '') : null,
        ];
    }

    /** @return array<string, mixed> Devices: every phone, computer and browser, the QR to sign one in, the connected apps */
    public function devicesData(): array
    {
        $timezone = app(SettingsRepository::class)->displayTimezone();

        return [
            'devices' => app(AccountSessions::class)->devices($this->account())->map(fn (Device $d): array => [
                'id' => $d->id,
                'name' => $d->readableName(),
                'computer' => in_array($d->platform->value, ['windows', 'macos', 'linux', 'desktop'], true),
                'status' => $d->status(),
                'value' => match ($d->status()) {
                    Device::ACTIVE => $d->last_seen_at !== null ? 'Active '.$d->last_seen_at->diffForHumans(short: true) : 'Signed in',
                    default => Device::STATUS_LABELS[$d->status()],
                },
            ])->all(),
            'connectorLocked' => ! PlanLock::allows('connector'),
            'connectorTag' => PlanLock::tagFor('connector'),
            'connectorUrl' => UpgradeFlow::chooseUrl(feature: 'connector'),
            'apps' => app(ConnectedApps::class)->forUser($this->account())->map(fn (ConnectedApp $app): array => [
                'id' => $app->id,
                'name' => ConnectedApps::name($app->client_id),
                'value' => $app->last_used_at !== null ? 'Used '.$app->last_used_at->diffForHumans(short: true) : 'Connected '.Formats::shortDate($app->created_at->copy()->setTimezone($timezone)),
            ])->all(),
        ];
    }

    /** Devices › Sign in a device: a one-time QR and code (10 minutes). */
    public function signInDeviceAction(): Action
    {
        return Action::make('signInDevice')->label('Sign in a device')
            ->modalHeading('Sign in a phone or computer')
            ->modalDescription('Scan the QR code in the EchoLedger app, or type the code. It works once and expires in 10 minutes.')
            ->fillForm(function (PairingService $pairing): array {
                try {
                    $code = $pairing->createCode($this->account());
                } catch (ApiError $e) {
                    return ['pairing' => ['error' => $e->getMessage()]];
                }
                $this->watchPairing($code);

                return ['pairing' => ['code' => $code, 'qr' => (new QRCode)->render($pairing->qrPayload($code)), 'url' => rtrim((string) config('app.url'), '/')]];
            })
            ->schema([ViewField::make('pairing')->view('filament.pair-device')->hiddenLabel()])
            ->modalSubmitAction(false)->modalCancelActionLabel('Done')->modalWidth('md');
    }

    /** A device's sheet: rename it, sign it out, sign it in again or remove it from the list. */
    public function deviceAction(): Action
    {
        return Action::make('device')
            ->modalHeading(fn (array $arguments): string => $this->ownDevice($arguments)->readableName())
            ->modalWidth('md')
            ->fillForm(fn (array $arguments): array => ['name' => $this->ownDevice($arguments)->readableName()])
            ->schema([TextInput::make('name')->label('Name')->required()->maxLength(60)])
            ->modalSubmitActionLabel('Save')
            ->action(function (array $data, array $arguments): void {
                $device = $this->ownDevice($arguments);
                $device->forceFill(['name' => trim((string) $data['name']), 'name_chosen' => true])->save();
                Notification::make()->title('Device renamed')->success()->send();
            })
            ->extraModalFooterActions(fn (array $arguments): array => array_values(array_filter([
                $this->ownDevice($arguments)->signed_out_at === null ? Action::make('signOutDevice')->label('Sign out')->color('danger')
                    ->requiresConfirmation()->modalHeading('Sign out '.$this->ownDevice($arguments)->readableName().'?')
                    ->modalDescription('It will need to sign in again.')->modalSubmitActionLabel('Sign out')->modalIcon(null)
                    ->action(function () use ($arguments): void {
                        $done = app(AccountSessions::class)->revokeDevice($this->account(), (string) $arguments['device']);
                        Notification::make()->title($done ? 'Device signed out' : 'That device was already signed out')->success()->send();
                    })->cancelParentActions() : null,
                $this->ownDevice($arguments)->status() !== Device::ACTIVE ? Action::make('reauthDevice')->label('Sign in again')->color('gray')
                    ->action(fn () => $this->replaceMountedAction('signInAgain', ['device' => $arguments['device']])) : null,
                $this->ownDevice($arguments)->signed_out_at !== null ? Action::make('forgetDevice')->label('Remove from list')->color('danger')
                    ->requiresConfirmation()->modalHeading('Remove '.$this->ownDevice($arguments)->readableName().' from the list?')
                    ->modalDescription(null)->modalSubmitActionLabel('Remove')->modalIcon(null)
                    ->action(function () use ($arguments): void {
                        $done = app(AccountSessions::class)->removeDevice($this->account(), (string) $arguments['device']);
                        Notification::make()->title($done ? 'Removed from the list' : 'That device was already removed')->success()->send();
                    })->cancelParentActions() : null,
            ])));
    }

    /** Devices › Sign in again: a one-time code bound to one of the person's own signed-out devices. */
    public function signInAgainAction(): Action
    {
        return Action::make('signInAgain')
            ->modalHeading(fn (array $arguments): string => 'Sign in '.$this->ownDevice($arguments)->readableName().' again')
            ->modalDescription('Open the EchoLedger app on that device and scan the QR code, or type the code. It works once, on that device only, and expires in 10 minutes. Its recordings and name stay.')
            ->fillForm(function (array $arguments, PairingService $pairing): array {
                try {
                    $code = $pairing->createBoundCode($this->account(), $this->ownDevice($arguments)->id);
                } catch (ApiError|ValidationException $e) {
                    return ['pairing' => ['error' => $e->getMessage()]];
                }
                $this->watchPairing($code);

                return ['pairing' => ['code' => $code, 'qr' => (new QRCode)->render($pairing->qrPayload($code)), 'url' => rtrim((string) config('app.url'), '/')]];
            })
            ->schema([ViewField::make('pairing')->view('filament.pair-device')->hiddenLabel()])
            ->modalSubmitAction(false)->modalCancelActionLabel('Done')->modalWidth('md');
    }

    /** @param  array<string, mixed>  $arguments */
    private function ownDevice(array $arguments): Device
    {
        return Device::query()->withoutGlobalScopes()->where('user_id', $this->account()->id)->whereKey((string) ($arguments['device'] ?? ''))->firstOrFail();
    }

    public function revokeDevice(string $id): void
    {
        $done = app(AccountSessions::class)->revokeDevice($this->account(), $id);
        Notification::make()->title($done ? 'Device signed out' : 'That device was already signed out')->success()->send();
    }

    public function removeDevice(string $id): void
    {
        $done = app(AccountSessions::class)->removeDevice($this->account(), $id);
        Notification::make()->title($done ? 'Removed from the list' : 'That device was already removed')->success()->send();
    }

    /** Devices › Connected apps › Disconnect, confirmed. */
    public function disconnectAppAction(): Action
    {
        return Action::make('disconnectApp')->label('Disconnect')->color('danger')
            ->requiresConfirmation()->modalIcon(null)
            ->modalHeading(fn (array $arguments): string => 'Disconnect '.ConnectedApps::name((string) (app(ConnectedApps::class)->forUser($this->account())->firstWhere('id', (int) ($arguments['app'] ?? 0))->client_id ?? '')).'?')
            ->modalDescription('It can no longer read your recordings.')
            ->modalSubmitActionLabel('Disconnect')
            ->action(fn (array $arguments) => $this->disconnectApp((int) ($arguments['app'] ?? 0)));
    }

    public function disconnectApp(int $id): void
    {
        $done = app(ConnectedApps::class)->disconnect($this->account(), $id);
        Notification::make()->title($done ? 'App disconnected' : 'That app was already disconnected')->success()->send();
    }

    /** @return array<string, mixed> Privacy & data */
    public function privacyData(): array
    {
        $user = $this->account();
        $export = app(AccountExports::class)->latest($user);
        $until = $user->support_access_until;

        return [
            'consent' => $user->consent_seen_at !== null ? 'Acknowledged on '.Formats::shortDate($user->consent_seen_at, year: true) : 'Not yet',
            'export' => match (true) {
                $export === null => null,
                $export->status === 'making' => 'Making your export…',
                $export->downloadable() => 'Ready until '.Formats::shortDate($export->expires_at).', '.Formats::time($export->expires_at),
                $export->status === 'failed' => "Couldn't make the export",
                default => null,
            },
            'exportUrl' => $export?->downloadable() === true ? $export->downloadUrl() : null,
            'support' => $until !== null && $until->isFuture() ? 'On until '.Formats::shortDate($until).', '.Formats::time($until) : 'Off',
            'supportOn' => $until !== null && $until->isFuture(),
            'deleting' => $user->deletes_at !== null ? 'Deletes on '.Formats::shortDate($user->deletes_at, year: true) : null,
            'deleteUrl' => route('account.delete'),
            'keepUrl' => AccountBeingDeleted::getUrl(panel: 'app'),
        ];
    }

    /** Privacy & data › Recording consent: the notice, and "I understand" once. */
    public function consentAction(): Action
    {
        return Action::make('consent')->label('Recording consent')
            ->modalHeading('Recording consent')->modalWidth('md')
            ->schema([Text::make(RecordingConsent::TEXT), Text::make(RecordingConsent::APPS)])
            ->modalSubmitAction(fn (Action $action) => $this->account()->consent_seen_at === null ? $action->label('I understand') : false)
            ->modalCancelActionLabel(fn (): string => $this->account()->consent_seen_at === null ? 'Cancel' : 'Close')
            ->action(function (): void {
                $user = $this->account();
                if ($user->consent_seen_at === null) {
                    $user->forceFill(['consent_seen_at' => now()])->save();
                }
            });
    }

    /** Privacy & data › Export my data: one ZIP, made in the background, the link by email. */
    public function exportDataAction(): Action
    {
        return Action::make('exportData')->label('Export my data')
            ->modalHeading('Export my data?')
            ->modalDescription('One ZIP with your recordings, their transcripts, the screens and your projects\' files and notes. We email you a link, which works for 24 hours.')
            ->modalSubmitActionLabel('Make the export')
            ->action(function (): void {
                app(AccountExports::class)->start($this->account());
                Notification::make()->title('Making your export')->body('The link comes by email.')->success()->send();
            });
    }

    /** Privacy & data › Support access: let support look for a while, or stop now. */
    public function supportAccessAction(): Action
    {
        return Action::make('supportAccess')->label('Support access')
            ->modalHeading('Support access')->modalWidth('md')
            ->modalDescription('Support never sees your recordings. To help with one, you can let support look for a while. Every look is in your activity log.')
            ->schema(fn (): array => $this->account()->support_access_until?->isFuture() === true ? [] : [
                Select::make('hours')->label('For')->required()->selectablePlaceholder(false)->default('24')
                    ->options(['1' => '1 hour', '24' => '1 day', '168' => '7 days']),
            ])
            ->modalSubmitActionLabel(fn (): string => $this->account()->support_access_until?->isFuture() === true ? 'Stop now' : 'Let support look')
            ->action(function (array $data): void {
                $user = $this->account();
                if ($user->support_access_until?->isFuture() === true) {
                    $user->forceFill(['support_access_until' => null])->save();
                    app(AuditLog::class)->record('support_access_ended', $user->id, $user, 'Support access turned off');
                    Notification::make()->title('Support access is off')->success()->send();

                    return;
                }
                $hours = in_array((int) ($data['hours'] ?? 0), [1, 24, 168], true) ? (int) $data['hours'] : 24;
                $user->forceFill(['support_access_until' => now()->addHours($hours)])->save();
                app(AuditLog::class)->record('support_access_granted', $user->id, $user, "Support access on for {$hours} h");
                Notification::make()->title('Support can look until '.Formats::shortDate($user->support_access_until).', '.Formats::time($user->support_access_until))->success()->send();
            });
    }
}
