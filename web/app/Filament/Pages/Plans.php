<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AuditLog;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

/**
 * The server admin's plans (since v1.5, docs/API.md "Plans and features"): price (0 until billing comes), minutes per
 * month, storage, devices, retention days, seats, AI credits and features, and which workspace is on which plan.
 * An empty limit is no limit. PlanLimits enforces them.
 */
class Plans extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 6;

    protected static ?string $title = 'Plans';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->is_super_admin;
    }

    public function getSubheading(): ?string
    {
        return 'What each workspace may use. No billing yet: the prices are only shown. New sign-ups get the Free plan.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Plan::query()->withCount('workspaces'))
            ->defaultSort('id')
            ->columns([
                TextColumn::make('name')->description(fn (Plan $record): string => $record->key),
                TextColumn::make('price_usd')->label('Price')->formatStateUsing(fn ($state): string => '$'.number_format((float) $state, 2)),
                TextColumn::make('monthly_minutes')->label('Minutes')->numeric()->placeholder('No limit'),
                TextColumn::make('storage_bytes')->label('Storage')->formatStateUsing(fn ($state): string => round((int) $state / 1024 ** 3, 1).' GB')->placeholder('No limit'),
                TextColumn::make('max_devices')->label('Devices')->placeholder('No limit'),
                TextColumn::make('retention_days')->label('Retention')->suffix(' days')->placeholder('No limit'),
                TextColumn::make('seats')->placeholder('No limit'),
                TextColumn::make('ai_credits_usd')->label('AI credits')->formatStateUsing(fn ($state): string => '$'.number_format((float) $state, 2))->placeholder('No limit'),
                TextColumn::make('features')->badge()->separator(',')
                    ->state(fn (Plan $record): array => array_keys(array_filter($record->featureMap())))
                    ->formatStateUsing(fn (string $state): string => Plan::FEATURE_LABELS[$state] ?? $state)
                    ->placeholder('None'),
                TextColumn::make('workspaces_count')->label('Workspaces')->numeric(),
            ])
            ->headerActions([
                Action::make('create')->label('New plan')->icon(Heroicon::OutlinedPlus)
                    ->schema(fn (): array => [
                        TextInput::make('key')->required()->maxLength(32)->regex('/^[a-z0-9][a-z0-9_-]*$/')
                            ->helperText('A short name for the apps; it cannot change later.')
                            ->rule(fn (): Unique => Rule::unique('plans', 'key')),
                        ...$this->planFields(),
                    ])
                    // In-app AI is off in a new plan until someone switches it on (it costs money on every call).
                    ->fillForm(fn (): array => ['price_usd' => 0, ...array_fill_keys(array_map(fn (string $f): string => "feature_{$f}", Plan::FEATURES), true), 'feature_ai_notes' => false])
                    ->action(function (array $data): void {
                        $plan = Plan::query()->create(['key' => $data['key'], ...self::fromForm($data)]);
                        app(AuditLog::class)->record('plan_created', (int) auth()->id(), $plan, "Plan {$plan->name} made");
                        Notification::make()->title("Plan {$plan->name} made")->success()->send();
                    }),
            ])
            ->recordActions([
                Action::make('edit')->label('Edit')->icon(Heroicon::OutlinedPencilSquare)->color('gray')
                    ->schema(fn (): array => $this->planFields())
                    ->fillForm(fn (Plan $record): array => self::toForm($record))
                    ->action(function (Plan $record, array $data): void {
                        $record->update(self::fromForm($data));
                        app(AuditLog::class)->record('plan_changed', (int) auth()->id(), $record, "Plan {$record->name} changed");
                        Notification::make()->title("Plan {$record->name} saved")->success()->send();
                    }),
                Action::make('assign')->label('Assign')->icon(Heroicon::OutlinedArrowRightCircle)->color('gray')
                    ->modalDescription('The workspace is on this plan from now on: its limits and features apply at once.')
                    ->schema([Select::make('workspace_id')->label('Workspace')->required()->searchable()
                        ->options(fn (): array => Workspace::query()->orderBy('name')->limit(1000)->pluck('name', 'id')->all())])
                    ->action(function (Plan $record, array $data): void {
                        $workspace = Workspace::query()->find((int) $data['workspace_id']);
                        if ($workspace instanceof Workspace) {
                            $workspace->forceFill(['plan_id' => $record->id])->save();
                            app(AuditLog::class)->record('plan_assigned', (int) auth()->id(), $workspace, "{$workspace->name} is now on the {$record->name} plan");
                            Notification::make()->title("{$workspace->name} is now on {$record->name}")->success()->send();
                        }
                    }),
                Action::make('delete')->label('Delete')->icon(Heroicon::OutlinedTrash)->color('danger')
                    ->visible(fn (Plan $record): bool => ! in_array($record->key, [Plan::PERSONAL, Plan::FREE], true) && (int) $record->getAttribute('workspaces_count') === 0)
                    ->requiresConfirmation()
                    ->action(function (Plan $record): void {
                        // Checked again: a workspace may have moved to it since the list was drawn (the key restricts too).
                        if ($record->workspaces()->exists()) {
                            Notification::make()->title('A workspace is on this plan')->danger()->send();

                            return;
                        }
                        $name = $record->name;
                        $record->delete();
                        app(AuditLog::class)->record('plan_deleted', (int) auth()->id(), null, "Plan {$name} deleted");
                        Notification::make()->title("Plan {$name} deleted")->success()->send();
                    }),
            ])
            ->paginated(false);
    }

    /** @return list<Component> the fields of a plan; an empty limit is no limit */
    private function planFields(): array
    {
        return [
            TextInput::make('name')->required()->maxLength(80),
            Grid::make(['default' => 2])->schema([
                TextInput::make('price_usd')->label('Price (US$ a month)')->numeric()->minValue(0)->maxValue(100000)->required(),
                TextInput::make('monthly_minutes')->label('Minutes a month')->integer()->minValue(0)->maxValue(100000000),
                TextInput::make('storage_gb')->label('Storage (GB)')->numeric()->minValue(0)->maxValue(1000000),
                TextInput::make('max_devices')->label('Devices')->integer()->minValue(0)->maxValue(100000),
                TextInput::make('retention_days')->label('Retention (days)')->integer()->minValue(1)->maxValue(3650),
                TextInput::make('seats')->integer()->minValue(1)->maxValue(100000),
                TextInput::make('ai_credits_usd')->label('AI credits (US$ a month)')->numeric()->minValue(0)->maxValue(100000),
            ]),
            ...array_map(fn (string $feature): Toggle => Toggle::make("feature_{$feature}")->label(Plan::FEATURE_LABELS[$feature]), Plan::FEATURES),
        ];
    }

    /** @return array<string, mixed> */
    private static function toForm(Plan $plan): array
    {
        return [
            'name' => $plan->name,
            'price_usd' => $plan->price_usd,
            'monthly_minutes' => $plan->monthly_minutes,
            'storage_gb' => $plan->storage_bytes === null ? null : round($plan->storage_bytes / 1024 ** 3, 2),
            'max_devices' => $plan->max_devices,
            'retention_days' => $plan->retention_days,
            'seats' => $plan->seats,
            'ai_credits_usd' => $plan->ai_credits_usd,
            ...collect($plan->featureMap())->mapWithKeys(fn (bool $on, string $f): array => ["feature_{$f}" => $on])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function fromForm(array $data): array
    {
        $number = fn (string $key): ?int => filled($data[$key] ?? null) ? (int) $data[$key] : null;

        return [
            'name' => trim((string) $data['name']),
            'price_usd' => (float) ($data['price_usd'] ?? 0),
            'monthly_minutes' => $number('monthly_minutes'),
            'storage_bytes' => filled($data['storage_gb'] ?? null) ? (int) round((float) $data['storage_gb'] * 1024 ** 3) : null,
            'max_devices' => $number('max_devices'),
            'retention_days' => $number('retention_days'),
            'seats' => $number('seats'),
            'ai_credits_usd' => filled($data['ai_credits_usd'] ?? null) ? (float) $data['ai_credits_usd'] : null,
            'features' => collect(Plan::FEATURES)->mapWithKeys(fn (string $f): array => [$f => (bool) ($data["feature_{$f}"] ?? false)])->all(),
        ];
    }
}
