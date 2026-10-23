<?php

declare(strict_types=1);

namespace App\Filament\Resources\Devices;

use App\Filament\Resources\Devices\Pages\ListDevices;
use App\Models\Device;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Paired phones and desktops. Revoking deletes the device and all of its tokens immediately. */
class DeviceResource extends Resource
{
    protected static ?string $model = Device::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        return false; // devices pair themselves with a one-time code
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('user_id', auth()->id())->withCount('captureSessions');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_seen_at', 'desc')
            ->columns([
                TextColumn::make('name')->weight('semibold')->searchable(),
                TextColumn::make('source_device')->label('Type')->badge()
                    ->state(fn (Device $record) => $record->sourceDevice()),
                TextColumn::make('platform'),
                TextColumn::make('app_version')->label('App')->placeholder('—'),
                TextColumn::make('capture_sessions_count')->label('Sessions')->badge()->color('gray'),
                TextColumn::make('last_seen_at')->label('Last seen')->since()->placeholder('never'),
                TextColumn::make('created_at')->label('Paired')->date(),
            ])
            ->recordActions([
                Action::make('rename')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->color('gray')
                    ->fillForm(fn (Device $record): array => ['name' => $record->name])
                    ->schema([TextInput::make('name')->required()->maxLength(120)])
                    ->action(fn (Device $record, array $data) => $record->update(['name' => $data['name']])),
                DeleteAction::make()->label('Revoke')
                    ->modalHeading('Revoke device')
                    ->modalDescription('The device loses access immediately. Its past recordings stay in the library.'),
            ])
            ->emptyStateHeading('No devices paired')
            ->emptyStateDescription('Use "Pair a device" to connect the EchoLedger phone or desktop app.');
    }

    public static function getPages(): array
    {
        return ['index' => ListDevices::route('/')];
    }
}
