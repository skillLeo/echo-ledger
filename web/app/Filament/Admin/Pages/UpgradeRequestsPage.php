<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Exceptions\ApiError;
use App\Filament\Admin\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Support\Notice as Notification;
use App\Models\Plan;
use App\Models\PromoCode;
use App\Models\UpgradeRequest;
use App\Models\User;
use App\Services\Entitlements\Entitlements;
use App\Services\Entitlements\PaymentProofs;
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
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * Admin panel → Upgrade requests (since v1.6, P1.4; the inbox since P2.4): who asked for which plan, what they are on
 * now, and their message. Staff approve a request (the plan, until a date or not, with an optional word) or decline it
 * with a reply; the person gets an email and an activity item either way. Nothing of anyone's recordings is shown.
 *
 * Since v1.7 (docs/API.md "Payment receipts") each request shows its reference code, stage, amount due, the
 * transaction ID and payer name, and the receipts (a picture opens full size, a PDF downloads); requests in review come
 * first, the oldest receipt at the top. Staff may also ask for a new receipt with a note. Approve, decline and ask run
 * under the request's lock: one decided meanwhile says so instead.
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
        return 'People who asked for a plan. Requests with a receipt come first. Approve once you see the payment in the bank; the plan is on at once.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    /** The stage names staff read, and their badge colours. */
    public const STAGES = [
        UpgradeRequest::STAGE_IN_REVIEW => ['In review', 'info'],
        UpgradeRequest::STAGE_NEEDS_RECEIPT => ['New receipt asked', 'warning'],
        UpgradeRequest::STAGE_AWAITING_PAYMENT => ['Waiting for payment', 'gray'],
        UpgradeRequest::STAGE_APPROVED => ['Approved', 'success'],
        UpgradeRequest::STAGE_DECLINED => ['Declined', 'danger'],
        UpgradeRequest::STAGE_CANCELLED => ['Cancelled', 'gray'],
    ];

    /**
     * Requests in review first (oldest receipt first), then new receipts asked, waiting for payment, decided.
     *
     * @param  Builder<UpgradeRequest>  $query
     * @return Builder<UpgradeRequest>
     */
    private static function byStage(Builder $query): Builder
    {
        $inReview = "status = 'pending' AND cancelled_at IS NULL AND paid_at IS NOT NULL AND (proof_requested_at IS NULL OR proof_requested_at <= paid_at)";

        return $query
            ->orderByRaw("CASE WHEN {$inReview} THEN 0 WHEN status = 'pending' AND cancelled_at IS NULL AND proof_requested_at IS NOT NULL THEN 1 WHEN status = 'pending' AND cancelled_at IS NULL THEN 2 ELSE 3 END")
            ->orderByRaw("CASE WHEN {$inReview} THEN paid_at END ASC")
            ->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * Only the requests at [$stage].
     *
     * @param  Builder<UpgradeRequest>  $query
     * @return Builder<UpgradeRequest>
     */
    private static function atStage(Builder $query, string $stage): Builder
    {
        $open = fn (Builder $q): Builder => $q->where('status', UpgradeRequest::PENDING)->whereNull('cancelled_at');

        return match ($stage) {
            UpgradeRequest::STAGE_IN_REVIEW => $open($query)->whereNotNull('paid_at')
                ->where(fn (Builder $q) => $q->whereNull('proof_requested_at')->orWhereColumn('proof_requested_at', '<=', 'paid_at')),
            UpgradeRequest::STAGE_NEEDS_RECEIPT => $open($query)->whereNotNull('proof_requested_at')
                ->where(fn (Builder $q) => $q->whereNull('paid_at')->orWhereColumn('proof_requested_at', '>', 'paid_at')),
            UpgradeRequest::STAGE_AWAITING_PAYMENT => $open($query)->whereNull('paid_at')->whereNull('proof_requested_at'),
            UpgradeRequest::STAGE_APPROVED => $query->where('status', UpgradeRequest::APPROVED),
            UpgradeRequest::STAGE_CANCELLED => $query->whereNotNull('cancelled_at'),
            UpgradeRequest::STAGE_DECLINED => $query->where('status', UpgradeRequest::DECLINED)->whereNull('cancelled_at'),
            default => $query,
        };
    }

    /** Runs a decision; one already made by someone else (or a cancel) is said in a notice instead. */
    private static function decide(callable $decision): bool
    {
        try {
            $decision();

            return true;
        } catch (ApiError $e) {
            Notification::make()->title(e($e->getMessage()))->danger()->send();

            return false;
        }
    }

    public function table(Table $table): Table
    {
        $plans = fn (): array => Plan::query()->orderBy('sort_order')->orderBy('id')->pluck('name', 'key')->all();
        $current = fn (UpgradeRequest $r): string => app(Entitlements::class)->plan($r->workspace_id)->name;

        return $table
            ->query(fn () => UpgradeRequest::query()->with(['user:id,name,email', 'proofs']))
            ->defaultSort(fn (Builder $query): Builder => self::byStage($query))
            ->columns([
                TextColumn::make('user.name')->label('Person')->description(fn (UpgradeRequest $r): string => $r->user->email)->searchable(['name', 'email']),
                TextColumn::make('current')->label('On now')->state($current),
                TextColumn::make('plan')->label('Asks for')->formatStateUsing(fn (string $state) => $plans()[$state] ?? $state)->badge(),
                // Since v1.7: the code the customer writes in the transfer's note, and where the request is.
                TextColumn::make('reference_code')->label('Reference')->fontFamily('mono')->copyable()->searchable()->placeholder('—'),
                TextColumn::make('stage')->label('Stage')->badge()->state(fn (UpgradeRequest $r): string => $r->stage())
                    ->formatStateUsing(fn (string $state): string => self::STAGES[$state][0] ?? $state)
                    ->color(fn (string $state): string => self::STAGES[$state][1] ?? 'gray')
                    ->description(fn (UpgradeRequest $r): ?string => $r->stage() === UpgradeRequest::STAGE_NEEDS_RECEIPT ? $r->proof_request_note : null),
                // Since v1.6.2: the promo code and what is to be paid after it.
                TextColumn::make('promo_code')->label('Code')->fontFamily('mono')->placeholder('—')
                    ->description(fn (UpgradeRequest $r): ?string => $r->percent !== null ? $r->percent.' % off' : null),
                TextColumn::make('amount_due')->label('To pay')->placeholder('—')
                    ->state(fn (UpgradeRequest $r): ?string => $r->amount_due_pkr !== null ? Plan::rupees($r->amount_due_pkr) : $r->amount_due),
                TextColumn::make('payment_reference')->label('Transaction ID')->fontFamily('mono')->copyable()->placeholder('—')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('payment_reference', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], strtoupper((string) preg_replace('/\s+/', '', $search))).'%'))
                    ->description(fn (UpgradeRequest $r): ?string => $r->payer_name !== null ? 'From '.$r->payer_name : null),
                ViewColumn::make('receipts')->label('Receipts')->view('filament.admin.upgrade-receipts'),
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
                SelectFilter::make('stage')->label('Stage')->options(collect(self::STAGES)->map(fn (array $s): string => $s[0])->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? self::atStage($query, (string) $data['value']) : $query),
            ])
            ->recordActions([
                Action::make('customer')->label('Customer')->icon(Heroicon::OutlinedUser)->color('gray')
                    ->visible(fn (): bool => StaffAccess::can('customers.view'))
                    ->url(fn (UpgradeRequest $r): string => ViewCustomer::getUrl(['record' => $r->user_id], panel: 'admin')),
                Action::make('approve')->label('Approve')->icon(Heroicon::OutlinedCheck)->color('success')
                    ->authorize(fn (): bool => StaffAccess::can('upgrades.manage'))
                    ->visible(fn (UpgradeRequest $r): bool => ! $r->isDecided())
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
                        if (self::decide(fn () => app(UpgradeRequests::class)->approve($record, $admin, (string) $data['plan'], $until, $data['message'] ?? null))) {
                            Notification::make()->title(e("Approved for {$record->user->email}"))->url(ViewCustomer::getUrl(['record' => $record->user], panel: 'admin'))->success()->send();
                        }
                    }),
                // Since v1.7: the receipt does not show the payment; the request stays open for another round of files.
                Action::make('askReceipt')->label('Ask for a new receipt')->icon(Heroicon::OutlinedDocumentMagnifyingGlass)->color('warning')
                    ->authorize(fn (): bool => StaffAccess::can('upgrades.manage'))
                    ->visible(fn (UpgradeRequest $r): bool => ! $r->isDecided())
                    ->modalDescription('The request stays open. The person gets an email and a notice with your note, and can send up to 3 more files.')
                    ->schema([Textarea::make('note')->label('What is needed')->required()->maxLength(500)->rows(3)
                        ->placeholder('The receipt is cut off, so we can\'t see the amount and the date. Please add the full receipt.')])
                    ->action(function (UpgradeRequest $record, array $data): void {
                        /** @var User $admin */
                        $admin = auth()->user();
                        if (self::decide(fn () => app(PaymentProofs::class)->requestNewReceipt($record, $admin, (string) $data['note']))) {
                            Notification::make()->title(e("New receipt asked from {$record->user->email}"))->success()->send();
                        }
                    }),
                Action::make('decline')->label('Decline')->icon(Heroicon::OutlinedXMark)->color('danger')
                    ->authorize(fn (): bool => StaffAccess::can('upgrades.manage'))
                    ->visible(fn (UpgradeRequest $r): bool => ! $r->isDecided())
                    ->schema([Textarea::make('reply')->label('Reason')->required()->maxLength(1000)->rows(3)
                        ->placeholder('We couldn\'t match this receipt to a payment in our account.')
                        ->helperText('Sent to the person by email and shown in their apps.')])
                    ->action(function (UpgradeRequest $record, array $data): void {
                        /** @var User $admin */
                        $admin = auth()->user();
                        if (self::decide(fn () => app(UpgradeRequests::class)->decline($record, $admin, (string) $data['reply']))) {
                            Notification::make()->title(e("Declined for {$record->user->email}"))->url(ViewCustomer::getUrl(['record' => $record->user], panel: 'admin'))->success()->send();
                        }
                    }),
            ])
            ->emptyStateIcon(Heroicon::OutlinedArrowTrendingUp)
            ->emptyStateHeading('No requests waiting')
            ->emptyStateDescription('When someone asks for a plan in the apps or the console, it shows here.');
    }
}
