<?php

declare(strict_types=1);

namespace App\Filament\Resources\Devices;

use App\Filament\Concerns\FullConsoleOnly;
use App\Filament\Resources\Devices\Pages\ListDevices;
use App\Models\Device;
use App\Models\User;
use App\Services\Workspaces\CurrentWorkspace;
use App\Services\Workspaces\Workspaces;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Paired phones and desktops. Since v1.6: readable names, a status (Active, Signed out by you, Revoked by admin,
 * Expired), Rename, Sign out (the row stays, its tokens stop at once) and Remove from list.
 */
class DeviceResource extends Resource
{
    use FullConsoleOnly;

    protected static ?string $model = Device::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::DevicePhoneMobile;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        return false; // devices pair themselves with a one-time code
    }

    public static function getEloquentQuery(): Builder
    {
        // Owners and admins see every device of the workspace (to revoke one); members and viewers their own.
        $user = auth()->user();
        $manages = $user instanceof User && app(Workspaces::class)->roleOf($user, (int) CurrentWorkspace::id())?->manages() === true;

        return parent::getEloquentQuery()->where('workspace_id', CurrentWorkspace::id())
            ->when(! $manages, fn (Builder $q) => $q->where('user_id', (int) CurrentWorkspace::userId()))->withCount('captureSessions');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_seen_at', 'desc')
            ->columns([
                // Since v1.6 the readable name ("Galaxy S25 Ultra", "Windows 11 PC"), or the one the person chose.
                TextColumn::make('name')->weight('semibold')->searchable()
                    ->formatStateUsing(fn (Device $record): string => $record->readableName()),
                TextColumn::make('status')->label('Status')->badge()
                    ->state(fn (Device $record): string => Device::STATUS_LABELS[$record->status()])
                    ->color(fn (Device $record): string => match ($record->status()) {
                        Device::ACTIVE => 'success', Device::EXPIRED => 'gray', default => 'warning',
                    })
                    ->description(fn (Device $record): ?string => $record->signed_out_at?->diffForHumans()),
                TextColumn::make('source_device')->label('Type')->badge()
                    ->state(fn (Device $record) => $record->sourceDevice()),
                TextColumn::make('platform'),
                TextColumn::make('app_version')->label('App')->placeholder('—'),
                TextColumn::make('capture_sessions_count')->label('Recordings')->badge()->color('gray'),
                TextColumn::make('last_seen_at')->label('Last seen')->since()->placeholder('never'),
                TextColumn::make('created_at')->label('Signed in')->date(),
            ])
            ->filters([
                // Since v1.6 signed-out devices stay listed until they are removed: shown with this filter.
                SelectFilter::make('shown')->label('Show')
                    ->options(['signed_in' => 'Signed in', 'signed_out' => 'Signed out', 'all' => 'All'])
                    ->default('signed_in')->selectablePlaceholder(false)
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? 'signed_in') {
                        'signed_out' => $query->whereNotNull('signed_out_at'),
                        'all' => $query,
                        default => $query->whereNull('signed_out_at'),
                    }),
            ])
            ->recordActions([
                Action::make('rename')
                    ->authorize(fn (Device $record): bool => Gate::allows('update', $record))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->color('gray')
                    ->fillForm(fn (Device $record): array => ['name' => $record->readableName()])
                    ->schema([TextInput::make('name')->required()->maxLength(60)])
                    ->action(fn (Device $record, array $data) => $record->forceFill(['name' => trim((string) $data['name']), 'name_chosen' => true])->save()),
                // Since v1.6: the person's own device that is signed out, revoked or expired comes back on its row.
                Action::make('signInAgain')->label('Sign in again')->icon(Heroicon::OutlinedQrCode)->color('gray')
                    ->visible(fn (Device $record): bool => $record->user_id === (int) auth()->id() && $record->status() !== Device::ACTIVE)
                    ->modalHeading(fn (Device $record): string => 'Sign in '.$record->readableName().' again')
                    ->modalDescription('Open the EchoLedger app on that device and scan the QR code, or type the code. It works once, on that device only, and expires in 10 minutes.')
                    ->fillForm(fn (Device $record, ListDevices $livewire): array => $livewire->signInAgainForm($record))
                    ->schema([ViewField::make('pairing')->view('filament.pair-device')->hiddenLabel()])
                    ->modalSubmitAction(false)->modalCancelActionLabel('Done')->modalWidth('md'),
                Action::make('signOut')->label('Sign out')->icon(Heroicon::OutlinedArrowRightStartOnRectangle)->color('danger')
                    ->authorize(fn (Device $record): bool => Gate::allows('delete', $record))
                    ->visible(fn (Device $record): bool => $record->signed_out_at === null)
                    ->requiresConfirmation()
                    ->modalHeading('Sign out this device')
                    ->modalDescription('It stops working at once and asks to sign in again. Its past recordings stay in the library.')
                    ->action(fn (Device $record) => $record->signOut($record->user_id === (int) auth()->id() ? 'self' : 'admin', (int) auth()->id())),
                DeleteAction::make()->label('Remove from list')
                    ->modalHeading('Remove from the list')
                    ->modalDescription('The device is signed out (if it is not yet) and leaves this list. Its past recordings stay in the library.'),
            ])
            ->emptyStateIcon(Heroicon::OutlinedDevicePhoneMobile)
            ->emptyStateHeading('No devices signed in')
            ->emptyStateDescription('Use "Sign in a device" to connect the EchoLedger phone or desktop app.');
    }

    public static function getPages(): array
    {
        return ['index' => ListDevices::route('/')];
    }
}
