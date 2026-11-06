<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\FullConsoleOnly;
use App\Models\DeviceReport;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * What works on each phone and computer (since v1.5): the device checks people chose to send from the apps. The super
 * admin sees every device's, owners and admins those of their workspace.
 */
class DeviceReports extends Page implements HasTable
{
    use FullConsoleOnly;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::DevicePhoneMobile;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 8;

    protected static ?string $title = 'Device reports';

    protected static ?string $slug = 'device-reports';

    public function getSubheading(): ?string
    {
        return 'What works on each phone and computer. A report arrives only when someone sends it from the app’s device check.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->reports())
            ->defaultSort('reported_at', 'desc')
            ->emptyStateHeading('No device reports yet')
            ->emptyStateDescription('On a phone: Settings → Help & diagnostics → Device check → Send to my admin.')
            ->columns([
                TextColumn::make('device.name')->label('Device')->placeholder('Unnamed'),
                TextColumn::make('user.email')->label('Person')->searchable(),
                TextColumn::make('platform')->label('System')->formatStateUsing(fn (DeviceReport $record): string => trim($record->platform.' '.$record->os_version)),
                TextColumn::make('model')->label('Model')->formatStateUsing(fn (DeviceReport $record): string => trim($record->brand.' '.$record->model))->placeholder('Not given'),
                TextColumn::make('app_version')->label('App')->placeholder('Not given'),
                TextColumn::make('summary')->label('Checks')->state(fn (DeviceReport $record): string => $record->summary()),
                TextColumn::make('reported_at')->label('Sent')->since()->sortable(),
            ])
            ->recordActions([
                Action::make('open')->label('Open')->icon(Heroicon::OutlinedEye)->color('gray')
                    ->modalHeading(fn (DeviceReport $record): string => trim($record->brand.' '.$record->model) ?: 'Device report')
                    ->modalContent(fn (DeviceReport $record) => view('filament.pages.device-report', ['report' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
            ]);
    }

    /** @return Builder<DeviceReport> */
    private function reports(): Builder
    {
        $user = auth()->user();
        $query = DeviceReport::query()->with(['device', 'user']);

        return $user instanceof User && $user->is_super_admin ? $query->withoutGlobalScope(WorkspaceScope::class) : $query;
    }
}
