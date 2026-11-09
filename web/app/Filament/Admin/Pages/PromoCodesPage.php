<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Support\Notice as Notification;
use App\Models\Plan;
use App\Models\PromoCode;
use App\Models\PromoCodeUse;
use App\Services\AuditLog;
use App\Services\SettingsRepository;
use App\Services\Staff\StaffAccess;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * Admin panel → Promo codes (since v1.6.2, docs/API.md "Promo codes"): a code for one plan with a discount of 1–100 %,
 * an end date and a number of uses if wanted, one use per person (the default) and the months the plan lasts once
 * switched on. Staff with the Plans permission see them; making, changing and switching one off needs "Make and change
 * plans". Each code lists its uses (who, when, which request); customers never see any of this.
 */
class PromoCodesPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Ticket;

    protected static string|UnitEnum|null $navigationGroup = 'Plans';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Promo codes';

    protected static ?string $slug = 'promo-codes';

    public static function canAccess(): bool
    {
        return StaffAccess::can('plans.view');
    }

    public function getSubheading(): ?string
    {
        return 'A code takes a share off one plan\'s price. At 100 % the plan switches on at once, with nothing to pay.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')->label('New promo code')->icon(Heroicon::OutlinedPlus)
                ->authorize(fn (): bool => StaffAccess::can('plans.manage'))
                ->schema(fn (): array => self::form(true))
                ->fillForm(['percent' => 50, 'one_per_person' => true, 'months' => 1, 'active' => true])
                ->action(function (array $data): void {
                    $promo = PromoCode::query()->create([...self::values($data), 'code' => PromoCode::normalize((string) $data['code']), 'created_by' => auth()->id()]);
                    app(AuditLog::class)->record('promo_code_created', (int) auth()->id(), $promo, "Promo code {$promo->code}: {$promo->percent} % off {$promo->plan}");
                    Notification::make()->title(e("{$promo->code} is ready"))->success()->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        $plans = fn (): array => Plan::query()->orderBy('sort_order')->orderBy('id')->pluck('name', 'key')->all();
        $zone = app(SettingsRepository::class)->displayTimezone();

        return $table
            ->query(fn () => PromoCode::query()->withCount('uses'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('code')->fontFamily('mono')->weight('bold')->searchable()->copyable()->description(fn (PromoCode $p): ?string => $p->note),
                TextColumn::make('plan')->formatStateUsing(fn (string $state): string => $plans()[$state] ?? $state)->badge(),
                TextColumn::make('percent')->label('Off')->formatStateUsing(fn (int $state): string => $state.' %'),
                TextColumn::make('months')->label('Months')->formatStateUsing(fn (int $state): string => $state === 1 ? '1 month' : "{$state} months"),
                TextColumn::make('uses_count')->label('Used')->formatStateUsing(fn (int $state, PromoCode $p): string => $p->max_uses === null ? (string) $state : "{$state} of {$p->max_uses}"),
                IconColumn::make('one_per_person')->label('Once each')->boolean(),
                TextColumn::make('ends_at')->label('Ends')->dateTime('M j, Y H:i', $zone)->placeholder('Never'),
                TextColumn::make('active')->label('State')->badge()
                    ->state(fn (PromoCode $p): string => match (true) {
                        ! $p->active => 'Off', $p->hasEnded() => 'Ended', $p->max_uses !== null && $p->uses_count >= $p->max_uses => 'Used up', default => 'On',
                    })
                    ->color(fn (string $state): string => $state === 'On' ? 'success' : 'gray'),
            ])
            ->filters([TernaryFilter::make('active')->label('On')])
            ->recordActions([
                Action::make('uses')->label('Uses')->icon(Heroicon::OutlinedUsers)->color('gray')
                    ->modalHeading(fn (PromoCode $record): string => "Uses of {$record->code}")
                    ->modalContent(fn (PromoCode $record): View => view('filament.admin.promo-code-uses', [
                        'uses' => PromoCodeUse::query()->where('promo_code_id', $record->id)->with(['user:id,name,email', 'upgradeRequest:id,status'])
                            ->orderByDesc('used_at')->limit(200)->get(),
                        'zone' => $zone,
                    ]))
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close'),
                Action::make('edit')->label('Edit')->icon(Heroicon::OutlinedPencilSquare)->color('gray')
                    ->authorize(fn (): bool => StaffAccess::can('plans.manage'))
                    ->fillForm(fn (PromoCode $record): array => $record->only(['plan', 'percent', 'ends_at', 'max_uses', 'one_per_person', 'months', 'active', 'note']))
                    ->schema(fn (): array => self::form(false))
                    ->action(function (PromoCode $record, array $data): void {
                        $record->update(self::values($data));
                        app(AuditLog::class)->record('promo_code_changed', (int) auth()->id(), $record, "Promo code {$record->code} changed");
                        Notification::make()->title(e("{$record->code} saved"))->success()->send();
                    }),
                Action::make('toggle')->label(fn (PromoCode $record): string => $record->active ? 'Switch off' : 'Switch on')
                    ->icon(fn (PromoCode $record) => $record->active ? Heroicon::OutlinedPause : Heroicon::OutlinedPlay)
                    ->color(fn (PromoCode $record): string => $record->active ? 'danger' : 'gray')
                    ->authorize(fn (): bool => StaffAccess::can('plans.manage'))
                    ->requiresConfirmation()
                    ->modalDescription(fn (PromoCode $record): string => $record->active
                        ? 'Nobody can use it from now on. Requests already made with it keep it.' : 'It can be used again, within its end date and uses.')
                    ->action(function (PromoCode $record): void {
                        $record->update(['active' => ! $record->active]);
                        app(AuditLog::class)->record('promo_code_changed', (int) auth()->id(), $record, "Promo code {$record->code} switched ".($record->active ? 'on' : 'off'));
                        Notification::make()->title(e($record->code.($record->active ? ' is on' : ' is off')))->success()->send();
                    }),
            ])
            ->emptyStateIcon(Heroicon::OutlinedTicket)
            ->emptyStateHeading('No promo codes yet')
            ->emptyStateDescription('Make one with New promo code. People type it in the upgrade sheet.');
    }

    /** @return list<Component|Field> */
    private static function form(bool $creating): array
    {
        return [
            Grid::make(2)->schema([
                TextInput::make('code')->label('Code')->visible($creating)->required($creating)->maxLength(24)
                    ->placeholder('SAVE50')->helperText('6 to 24 letters, digits or dashes; capitals or not, it is the same code.')
                    ->rule('regex:/^[A-Za-z0-9-]{6,24}$/')
                    ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                        if (PromoCode::query()->where('code', PromoCode::normalize((string) $value))->exists()) {
                            $fail('That code exists already.');
                        }
                    }),
                Select::make('plan')->label('Plan')->required()->selectablePlaceholder(false)
                    ->options(fn (): array => Plan::query()->where('on_offer', true)->whereNull('archived_at')->where('key', '!=', Plan::FREE)
                        ->orderBy('sort_order')->orderBy('id')->pluck('name', 'key')->all()),
                TextInput::make('percent')->label('Discount (%)')->required()->integer()->minValue(1)->maxValue(100)
                    ->helperText('100 % switches the plan on at once.'),
                TextInput::make('months')->label('Months the plan lasts')->required()->integer()->minValue(1)->maxValue(36),
                DateTimePicker::make('ends_at')->label('Ends (optional)')->seconds(false),
                TextInput::make('max_uses')->label('Uses at most (optional)')->integer()->minValue(1)->maxValue(1000000)->placeholder('No limit'),
                Toggle::make('one_per_person')->label('Once per person')->inline(false),
                Toggle::make('active')->label('On')->inline(false),
                TextInput::make('note')->label('Note (only staff see it)')->maxLength(500)->columnSpanFull(),
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function values(array $data): array
    {
        return [
            'plan' => (string) $data['plan'],
            'percent' => max(1, min(100, (int) $data['percent'])),
            'months' => max(1, min(36, (int) $data['months'])),
            'ends_at' => filled($data['ends_at'] ?? null) ? Carbon::parse((string) $data['ends_at']) : null,
            'max_uses' => filled($data['max_uses'] ?? null) ? (int) $data['max_uses'] : null,
            'one_per_person' => (bool) ($data['one_per_person'] ?? true),
            'active' => (bool) ($data['active'] ?? true),
            'note' => filled($data['note'] ?? null) ? trim((string) $data['note']) : null,
        ];
    }
}
