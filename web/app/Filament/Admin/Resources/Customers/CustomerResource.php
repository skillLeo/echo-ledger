<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Customers;

use App\Filament\Admin\Resources\Customers\Pages\ListCustomers;
use App\Filament\Admin\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Pages\Usage;
use App\Models\CaptureSession;
use App\Models\Device;
use App\Models\Plan;
use App\Models\Recording;
use App\Models\Scopes\WorkspaceScope;
use App\Models\UsageLedgerEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SettingsRepository;
use App\Services\Staff\Customers;
use App\Services\Staff\StaffAccess;
use App\Services\Usage\UsagePeriod;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Admin panel → Customers (since v1.6, P2.2): every account with its plan, status, devices, this month's minutes and
 * US dollars per provider, how much of the plan is used, storage, when it was last active and when it signed up.
 * Search, filters, bulk plan change and suspend, CSV. Metadata and usage only, never anyone's recordings.
 */
class CustomerResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'customers';

    protected static ?string $modelLabel = 'customer';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Users;

    protected static string|UnitEnum|null $navigationGroup = 'Customers';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'email';

    public static function canAccess(): bool
    {
        return StaffAccess::can('customers.view');
    }

    public static function canViewAny(): bool
    {
        return StaffAccess::can('customers.view');
    }

    public static function canView(Model $record): bool
    {
        return StaffAccess::can('customers.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /** @return Builder<User> every account with this month's totals as columns the table can sort by */
    public static function getEloquentQuery(): Builder
    {
        $period = UsagePeriod::current(app(SettingsRepository::class));
        $ledger = fn (string $sum) => UsageLedgerEntry::query()->withoutGlobalScope(WorkspaceScope::class)->selectRaw("COALESCE(SUM({$sum}), 0)")
            ->whereColumn('usage_ledger.user_id', 'users.id')->where('billable', true)
            ->where('occurred_at', '>=', $period['start'])->where('occurred_at', '<', $period['end']);

        return User::query()->select('users.*')
            ->addSelect([
                'month_ms' => $ledger('billed_ms'),
                'storage_used' => Recording::query()->selectRaw('COALESCE(SUM(stored_size_bytes), 0)')->whereNotNull('stored_path')
                    ->whereIn('capture_session_id', CaptureSession::acrossPeople()->withoutGlobalScope(WorkspaceScope::class)->withTrashed()->select('id')->whereColumn('capture_sessions.user_id', 'users.id')),
                'last_active_at' => Device::query()->withoutGlobalScopes()->selectRaw('MAX(last_seen_at)')->whereColumn('devices.user_id', 'users.id'),
                'active_devices' => Device::query()->withoutGlobalScopes()->counting()->selectRaw('COUNT(*)')->whereColumn('devices.user_id', 'users.id'),
            ]);
    }

    /** The quick look: the numbers, the plan and the latest devices, metadata only. */
    public static function peekAction(): Action
    {
        return Action::make('peek')->label('Quick look')->icon(Heroicon::OutlinedEye)->color('gray')->iconButton()
            ->slideOver()->modalWidth('lg')
            ->modalHeading(fn (User $record): string => $record->name)
            ->modalDescription(fn (User $record): string => $record->email.' · '.Customers::status($record))
            ->modalContent(function (User $record): ViewContract {
                $customers = app(Customers::class);
                $home = $customers->homeWorkspaceId($record);

                return view('filament.admin.customer-peek', [
                    'user' => $record,
                    'stats' => $customers->stats($record),
                    'plan' => $customers->plan($record),
                    'until' => $home !== null ? Workspace::query()->whereKey($home)->value('plan_expires_at') : null,
                    'devices' => Device::query()->withoutGlobalScopes()->where('user_id', $record->id)->orderByDesc('last_seen_at')->limit(5)->get(),
                ]);
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->extraModalFooterActions(fn (User $record): array => [
                Action::make('openCustomer')->label('Open')->icon(Heroicon::OutlinedArrowTopRightOnSquare)->url(ViewCustomer::getUrl(['record' => $record])),
            ]);
    }

    public static function table(Table $table): Table
    {
        $customers = app(Customers::class);

        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('semibold')->description(fn (User $record): string => $record->email),
                TextColumn::make('email')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('plan')->label('Plan')->badge()
                    ->state(fn (User $record): string => $customers->plan($record)->badgeText())
                    ->color(fn (User $record): array => Color::hex($customers->plan($record)->colorOrDefault())),
                TextColumn::make('status')->badge()
                    ->state(fn (User $record): string => Customers::status($record))
                    ->color(fn (string $state): string => match ($state) {
                        'Active' => 'success', 'Suspended' => 'danger', default => 'warning',
                    }),
                TextColumn::make('active_devices')->label('Devices')->numeric()->sortable(),
                TextColumn::make('month_ms')->label('Minutes this month')->sortable()
                    ->formatStateUsing(fn ($state): string => number_format((int) $state / 60000, 1)),
                TextColumn::make('cost')->label('Cost this month')
                    ->state(fn (User $record): string => '$'.number_format($customers->month($record)['cost_usd'], 2))
                    ->description(fn (User $record): ?string => self::providerLine($customers->month($record)['providers'])),
                TextColumn::make('plan_used')->label('Of plan')
                    ->state(fn (User $record): string => ($p = $customers->month($record)['plan_percent']) === null ? 'No limit' : rtrim(rtrim(number_format($p, 1), '0'), '.').'%'),
                TextColumn::make('storage_used')->label('Storage')->sortable()
                    ->formatStateUsing(fn ($state): string => Number::fileSize((int) $state, precision: 1)),
                TextColumn::make('last_active_at')->label('Last active')->since()->sortable()->placeholder('Never'),
                TextColumn::make('created_at')->label('Signed up')->date()->sortable(),
            ])
            ->filters([
                SelectFilter::make('plan')->label('Plan')
                    ->options(fn (): array => Plan::query()->orderBy('sort_order')->orderBy('id')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null) ? $query : $query->whereIn('users.id', DB::table('workspace_user')
                        ->join('workspaces', 'workspaces.id', '=', 'workspace_user.workspace_id')
                        ->where('workspace_user.role', 'owner')->where('workspaces.plan_id', (int) $data['value'])->select('workspace_user.user_id'))),
                SelectFilter::make('status')->label('Status')
                    ->options(['active' => 'Active', 'suspended' => 'Suspended', 'unconfirmed' => 'Not confirmed', 'deleting' => 'Being deleted'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'active' => $query->whereNull('suspended_at')->whereNull('deletes_at')->whereNotNull('email_verified_at'),
                        'suspended' => $query->whereNotNull('suspended_at'),
                        'unconfirmed' => $query->whereNull('email_verified_at'),
                        'deleting' => $query->whereNotNull('deletes_at'),
                        default => $query,
                    }),
            ])
            // Since v1.6 (P4.4): a row opens a quick look in a drawer on the right (Esc closes it); Open is the full page.
            ->recordActions([self::peekAction(), ViewAction::make()->label('Open')])
            ->recordAction('peek')
            ->recordUrl(null)
            ->headerActions([
                Action::make('csv')->label('Download CSV')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                    ->action(fn (): StreamedResponse => self::csv(self::getEloquentQuery()->orderBy('id')->limit(10000)->get())),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('changePlan')->label('Change plan')->icon(Heroicon::OutlinedArrowsRightLeft)
                        ->authorize(fn (): bool => StaffAccess::can('customers.manage'))
                        ->modalDescription('Each person\'s own workspace goes on the plan at once. With an end date it goes back to the default plan then.')
                        ->schema([
                            Select::make('plan_id')->label('Plan')->required()->options(fn (): array => Plan::query()->orderBy('sort_order')->pluck('name', 'id')->all()),
                            DateTimePicker::make('until')->label('Until (optional)')->minDate(now())->seconds(false),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $plan = Plan::query()->findOrFail((int) $data['plan_id']);
                            $until = filled($data['until'] ?? null) ? Carbon::parse((string) $data['until']) : null;
                            /** @var User $staff */
                            $staff = auth()->user();
                            $done = 0;
                            foreach ($records as $user) {
                                $done += $user instanceof User && app(Customers::class)->changePlan($user, $plan, $until, $staff) ? 1 : 0;
                            }
                            Notification::make()->title("{$done} moved to {$plan->name}")->success()->send();
                        }),
                    BulkAction::make('suspend')->label('Suspend')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                        ->authorize(fn (): bool => StaffAccess::can('customers.manage'))
                        ->modalDescription('They cannot sign in anywhere and their phones and computers are refused until you restore them. Staff are left out.')
                        ->schema([TextInput::make('reason')->label('Why (for you)')->maxLength(500)])
                        ->action(function (Collection $records, array $data): void {
                            /** @var User $staff */
                            $staff = auth()->user();
                            foreach ($records as $user) {
                                if ($user instanceof User && ! $user->is_super_admin && ! StaffAccess::isStaff($user)) {
                                    app(Customers::class)->suspend($user, true, $data['reason'] ?? null, $staff);
                                }
                            }
                            Notification::make()->title('Suspended')->success()->send();
                        }),
                    BulkAction::make('restore')->label('Restore')->icon(Heroicon::OutlinedCheckCircle)
                        ->authorize(fn (): bool => StaffAccess::can('customers.manage'))
                        ->action(function (Collection $records): void {
                            /** @var User $staff */
                            $staff = auth()->user();
                            foreach ($records as $user) {
                                if ($user instanceof User) {
                                    app(Customers::class)->suspend($user, false, null, $staff);
                                }
                            }
                            Notification::make()->title('Restored')->success()->send();
                        }),
                    BulkAction::make('csvSelected')->label('Download CSV')->icon(Heroicon::OutlinedArrowDownTray)
                        ->action(fn (Collection $records): StreamedResponse => self::csv($records)),
                ]),
            ])
            ->emptyStateIcon(Heroicon::OutlinedUsers)
            ->emptyStateHeading('No customers yet')
            ->emptyStateDescription('People who sign up show here.');
    }

    /** @param  list<array{label: string, minutes: float, cost_usd: float}>  $providers */
    private static function providerLine(array $providers): ?string
    {
        $parts = array_map(fn (array $p): string => $p['label'].' $'.number_format($p['cost_usd'], 2), array_filter($providers, fn (array $p): bool => $p['cost_usd'] > 0));

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /** @param  iterable<mixed>  $users */
    public static function csv(iterable $users): StreamedResponse
    {
        $customers = app(Customers::class);
        $cell = fn (string $v): string => preg_match('/^[=+\-@\t\r]/', $v) === 1 ? "'".$v : $v;
        $rows = [['Name', 'Email', 'Plan', 'Status', 'Devices', 'Minutes this month', 'Cost this month (US$)', '% of plan minutes', 'Storage (bytes)', 'Last active', 'Signed up']];
        foreach ($users as $user) {
            if (! $user instanceof User) {
                continue;
            }
            $month = $customers->month($user);
            $rows[] = [$cell($user->name), $cell($user->email), $customers->plan($user)->name, Customers::status($user),
                Device::query()->withoutGlobalScopes()->where('user_id', $user->id)->counting()->count(),
                $month['minutes'], $month['cost_usd'], $month['plan_percent'] ?? '', $customers->storageUsed($user),
                (string) Device::query()->withoutGlobalScopes()->where('user_id', $user->id)->max('last_seen_at'), $user->created_at?->toDateString() ?? ''];
        }

        return Usage::csvResponse($rows, 'customers-'.now()->format('Y-m-d').'.csv');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'view' => ViewCustomer::route('/{record}'),
        ];
    }
}
