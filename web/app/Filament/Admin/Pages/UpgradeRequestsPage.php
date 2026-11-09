<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Support\Notice as Notification;
use App\Models\Plan;
use App\Models\PromoCode;
use App\Models\UpgradeRequest;
use App\Models\User;
use App\Services\Entitlements\Entitlements;
use App\Services\Entitlements\UpgradeRequests;
use App\Services\Staff\StaffAccess;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * Admin panel → Upgrade requests (since v1.6, P1.4; the inbox since P2.4): who asked for which plan, what they are on
 * now, and their message. Staff approve a request (the plan, until a date or not, with an optional word) or decline it
 * with a reply; the person gets an email and an activity item either way. Nothing of anyone's recordings is shown.
 */
class UpgradeRequestsPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingUp;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::ArrowTrendingUp;

    protected static string|UnitEnum|null $navigationGroup = 'Plans';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Upgrade requests';

    protected static ?string $slug = 'upgrade-requests';

    public static function canAccess(): bool
    {
        return StaffAccess::can('upgrades.manage');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = UpgradeRequest::query()->where('status', UpgradeRequest::PENDING)->count();

        return $count > 0 ? (string) $count : null;
    }

    private static function promoUntil(UpgradeRequest $request): ?string
    {
        $months = $request->promo_code !== null ? PromoCode::query()->where('code', $request->promo_code)->value('months') : null;

        return $months !== null ? now()->addMonths((int) $months)->format('Y-m-d H:i') : null;
    }

    public function getSubheading(): ?string
    {
        return 'People who asked for a plan. Approve once the payment is confirmed; the plan is on at once.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        $plans = fn (): array => Plan::query()->orderBy('sort_order')->orderBy('id')->pluck('name', 'key')->all();
        $current = fn (UpgradeRequest $r): string => app(Entitlements::class)->plan($r->workspace_id)->name;

        return $table
            ->query(fn () => UpgradeRequest::query()->with('user:id,name,email'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('user.name')->label('Person')->description(fn (UpgradeRequest $r): string => $r->user->email)->searchable(['name', 'email']),
                TextColumn::make('current')->label('On now')->state($current),
                TextColumn::make('plan')->label('Asks for')->formatStateUsing(fn (string $state) => $plans()[$state] ?? $state)->badge(),
                // Since v1.6.2: the promo code and what is to be paid after it.
                TextColumn::make('promo_code')->label('Code')->fontFamily('mono')->placeholder('—')
                    ->description(fn (UpgradeRequest $r): ?string => $r->percent !== null ? $r->percent.' % off' : null),
                TextColumn::make('amount_due')->label('To pay')->placeholder('—'),
                TextColumn::make('message')->limit(80)->placeholder('—')->wrap(),
                TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                    UpgradeRequest::APPROVED => 'success', UpgradeRequest::DECLINED => 'danger', default => 'warning',
                })->formatStateUsing(fn (string $state): string => ucfirst($state)),
                TextColumn::make('created_at')->label('Asked')->since(),
                TextColumn::make('decided_at')->label('Decided')->since()->placeholder('—')
                    ->description(fn (UpgradeRequest $r): ?string => $r->approved_until !== null ? 'Until '.$r->approved_until->format('M j, Y') : $r->reply),
            ])
            ->filters([
                SelectFilter::make('status')->options([UpgradeRequest::PENDING => 'Waiting', UpgradeRequest::APPROVED => 'Approved', UpgradeRequest::DECLINED => 'Declined'])
                    ->default(UpgradeRequest::PENDING),
            ])
            ->recordActions([
                Action::make('customer')->label('Customer')->icon(Heroicon::OutlinedUser)->color('gray')
                    ->visible(fn (): bool => StaffAccess::can('customers.view'))
                    ->url(fn (UpgradeRequest $r): string => ViewCustomer::getUrl(['record' => $r->user_id], panel: 'admin')),
                Action::make('approve')->label('Approve')->icon(Heroicon::OutlinedCheck)->color('success')
                    ->authorize(fn (): bool => StaffAccess::can('upgrades.manage'))
                    ->visible(fn (UpgradeRequest $r): bool => $r->status === UpgradeRequest::PENDING)
                    ->modalDescription('The person\'s workspace goes on the plan at once. With an end date it goes back to the default plan then.')
                    // With a promo code the plan lasts the code's months unless staff choose otherwise.
                    ->fillForm(fn (UpgradeRequest $r): array => ['plan' => $r->plan, 'until' => self::promoUntil($r)])
                    ->schema([
                        Select::make('plan')->required()->options(fn (): array => Plan::query()->whereNull('archived_at')->orderBy('sort_order')->orderBy('id')->pluck('name', 'key')->all())->selectablePlaceholder(false),
                        DateTimePicker::make('until')->label('Until (optional)')->minDate(now())->seconds(false),
                        Textarea::make('message')->label('A word to them (optional)')->maxLength(1000)->rows(2)
                            ->helperText('Sent with the good news by email and shown in their apps.'),
                    ])
                    ->action(function (UpgradeRequest $record, array $data): void {
                        /** @var User $admin */
                        $admin = auth()->user();
                        $until = filled($data['until'] ?? null) ? Carbon::parse((string) $data['until']) : null;
                        app(UpgradeRequests::class)->approve($record, $admin, (string) $data['plan'], $until, $data['message'] ?? null);
                        Notification::make()->title(e("Approved for {$record->user->email}"))->url(ViewCustomer::getUrl(['record' => $record->user], panel: 'admin'))->success()->send();
                    }),
                Action::make('decline')->label('Decline')->icon(Heroicon::OutlinedXMark)->color('danger')
                    ->authorize(fn (): bool => StaffAccess::can('upgrades.manage'))
                    ->visible(fn (UpgradeRequest $r): bool => $r->status === UpgradeRequest::PENDING)
                    ->schema([Textarea::make('reply')->label('Your reply')->required()->maxLength(1000)->rows(3)
                        ->helperText('Sent to the person by email and shown in their apps.')])
                    ->action(function (UpgradeRequest $record, array $data): void {
                        /** @var User $admin */
                        $admin = auth()->user();
                        app(UpgradeRequests::class)->decline($record, $admin, (string) $data['reply']);
                        Notification::make()->title(e("Declined for {$record->user->email}"))->url(ViewCustomer::getUrl(['record' => $record->user], panel: 'admin'))->success()->send();
                    }),
            ])
            ->emptyStateIcon(Heroicon::OutlinedArrowTrendingUp)
            ->emptyStateHeading('No requests waiting')
            ->emptyStateDescription('When someone asks for a plan in the apps or the console, it shows here.');
    }
}
