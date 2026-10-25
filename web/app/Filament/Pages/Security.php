<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\Devices\DeviceResource;
use App\Models\AuditEvent;
use App\Models\Device;
use App\Services\AuditLog;
use App\Services\WebSessions;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The security centre (plan B16): browsers signed in to the console, paired devices and the audit log of sign-ins,
 * deletes, exports, share links and credential changes.
 */
class Security extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 0;

    protected static ?string $title = 'Security';

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Signed-in browsers')
                ->description('Sign out a browser you no longer use. Your phone and computer apps are under Devices.')
                ->schema([View::make('filament.pages.security-browsers')->viewData(fn (): array => $this->browserData())]),
            Section::make('Audit log')
                ->description('Sign-ins, deleted recordings, exports, share links and credential changes from the last '.AuditEvent::KEEP_DAYS.' days.')
                ->schema([EmbeddedTable::make()]),
        ]);
    }

    /** @return array<string, mixed> */
    public function browserData(): array
    {
        $userId = (int) auth()->id();

        return [
            'available' => WebSessions::available(),
            'browsers' => app(WebSessions::class)->list($userId, session()->getId()),
            'devices' => Device::query()->where('user_id', $userId)->count(),
            'devicesUrl' => DeviceResource::getUrl(),
        ];
    }

    public function signOutBrowser(string $id): void
    {
        $done = app(WebSessions::class)->revoke((int) auth()->id(), $id, session()->getId());
        Notification::make()->title($done ? 'Browser signed out' : 'That browser was already signed out')->success()->send();
    }

    public function signOutOtherBrowsers(): void
    {
        $count = app(WebSessions::class)->revokeOthers((int) auth()->id(), session()->getId());
        Notification::make()->title($count === 1 ? '1 browser signed out' : "{$count} browsers signed out")->success()->send();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => AuditEvent::query()->where('user_id', auth()->id()))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('M j, Y · g:i A'),
                TextColumn::make('event')->label('Event')->badge()->formatStateUsing(fn (string $state): string => AuditLog::label($state))
                    ->color(fn (string $state): string => match ($state) {
                        'login_failed', 'session_deleted', 'device_revoked', 'mfa_disabled', 'passkey_removed' => 'danger',
                        'credentials_changed', 'password_changed', 'share_created' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('summary')->label('What')->limit(80)->wrap(),
                TextColumn::make('actor')->label('By')->formatStateUsing(fn (AuditEvent $record): string => self::actorLabel($record)),
                TextColumn::make('ip')->label('IP address')->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('event')->label('Event')->options(AuditLog::EVENTS),
            ])
            ->paginated([25, 50, 100])
            ->emptyStateHeading('Nothing recorded yet');
    }

    public static function actorLabel(AuditEvent $event): string
    {
        return match ($event->actor) {
            'device' => Device::query()->whereKey($event->device_id)->value('name') ?? 'A removed device',
            'web' => 'Console',
            default => 'EchoLedger',
        };
    }
}
