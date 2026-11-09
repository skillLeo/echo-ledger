<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Auth\EditProfile;
use App\Filament\Concerns\FullConsoleOnly;
use App\Models\AuditEvent;
use App\Models\Device;
use App\Services\AuditLog;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Settings › Sign-in & security › Sign-in activity (the security centre's log since plan B16; its passkeys and
 * browsers moved to Settings in v1.7): sign-ins, deletes, exports, share links and credential changes.
 */
class Security extends Page implements HasTable
{
    use FullConsoleOnly;
    use InteractsWithTable;

    protected static ?string $slug = 'settings/security/activity';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Sign-in activity';

    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'security';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Text::make('Sign-ins, deleted recordings, exports, share links and credential changes from the last '.AuditEvent::KEEP_DAYS.' days.'),
            EmbeddedTable::make(),
        ]);
    }

    /** @return array<string, string> */
    public function getBreadcrumbs(): array
    {
        return [EditProfile::settingsUrl() => 'Settings', EditProfile::settingsUrl('security') => 'Sign-in & security', 'Sign-in activity'];
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
                        'login_failed', 'session_deleted', 'device_revoked', 'mfa_disabled', 'passkey_removed', 'connector_token_reused' => 'danger',
                        'credentials_changed', 'password_changed', 'share_created', 'connector_connected' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('summary')->label('What')->limit(80)->wrap(),
                TextColumn::make('actor')->label('By')->formatStateUsing(fn (AuditEvent $record): string => self::actorLabel($record)),
                TextColumn::make('ip')->label('IP address')->placeholder('—')->toggleable(),
            ])
            // The filter button's badge shows once a filter is on, not as a 0 (see the theme).
            ->filtersTriggerAction(fn (Action $action, Table $table): Action => $action
                ->extraAttributes(fn (): array => ['data-active-filters' => (string) $table->getActiveFiltersCount()]))
            ->filters([
                SelectFilter::make('event')->label('Event')->options(AuditLog::EVENTS),
            ])
            ->paginated([25, 50, 100])
            ->emptyStateIcon(Heroicon::OutlinedShieldCheck)
            ->emptyStateHeading('Nothing recorded yet');
    }

    public static function actorLabel(AuditEvent $event): string
    {
        return match ($event->actor) {
            'device' => Device::query()->whereKey($event->device_id)->value('name') ?? 'A removed device',
            'web' => 'Console',
            // Since v1.5: the app the connector's call came from ("Claude").
            'connector' => (string) ($event->meta['client'] ?? 'A connected app'),
            default => 'EchoLedger',
        };
    }
}
