<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Support\OverridesForm;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AuditLog;
use App\Services\Entitlements\Entitlements;
use App\Services\Entitlements\Features;
use App\Services\SettingsRepository;
use App\Services\Staff\StaffAccess;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::RectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Plans';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Plans';

    public static function canAccess(): bool
    {
        return StaffAccess::can('plans.view');
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
                TextColumn::make('name')->description(fn (Plan $record): string => $record->key.($record->is_default ? ' · default for sign-ups' : '').($record->on_offer ? ' · on offer' : '')),
                TextColumn::make('badge')->label('Badge')->state(fn (Plan $record): string => $record->badgeText())->badge()
                    ->color(fn (Plan $record): array => Color::hex($record->colorOrDefault())),
                TextColumn::make('price_usd')->label('Price')->state(fn (Plan $record): string => Entitlements::planCard($record)['price']),
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
                // Since v1.6: what people read after "Request upgrade" (bank or JazzCash details, what to write).
                Action::make('paymentInstructions')->label('Payment instructions')->icon(Heroicon::OutlinedBanknotes)->color('gray')
                    ->modalDescription('Shown after someone presses "Request upgrade", in the apps and the console, with "We\'ll activate it once confirmed".')
                    ->fillForm(fn (): array => ['text' => app(SettingsRepository::class)->get('upgrade.payment_instructions')])
                    ->schema([Textarea::make('text')->label('Payment instructions')->rows(6)->maxLength(2000)
                        ->placeholder('Bank: … · Account name: … · Account number: … · Reference: your email')])
                    ->action(function (array $data): void {
                        $text = trim((string) ($data['text'] ?? ''));
                        app(SettingsRepository::class)->set('upgrade.payment_instructions', $text !== '' ? $text : null);
                        app(AuditLog::class)->record('settings_changed', (int) auth()->id(), null, 'Payment instructions changed');
                        Notification::make()->title('Payment instructions saved')->success()->send();
                    }),
                // The suspend switch (since v1.6): signs the person in nowhere; their devices are refused on every call.
                Action::make('suspend')->label('Suspend or restore a person')->icon(Heroicon::OutlinedNoSymbol)->color('gray')
                    ->modalDescription('A suspended person cannot sign in anywhere, and their phones and computers are refused on every call (they stay signed in, so lifting it brings them back).')
                    ->schema(fn (): array => [
                        Select::make('user_id')->label('Person')->required()->searchable()->live()
                            ->getSearchResultsUsing(fn (string $search): array => User::query()->where('email', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%')
                                ->where('is_super_admin', false)->orderBy('email')->limit(20)->pluck('email', 'id')->all())
                            ->getOptionLabelUsing(fn ($value): ?string => User::query()->whereKey($value)->value('email'))
                            ->afterStateUpdated(function ($state, $set): void {
                                $user = User::query()->find($state);
                                $set('suspended', $user?->isSuspended() ?? false);
                                $set('reason', $user?->suspended_reason);
                            }),
                        Toggle::make('suspended')->label('Suspended'),
                        TextInput::make('reason')->label('Why (for you)')->maxLength(500),
                    ])
                    ->action(function (array $data): void {
                        $user = User::query()->find((int) $data['user_id']);
                        if (! $user instanceof User || $user->is_super_admin) {
                            return;
                        }
                        $on = (bool) ($data['suspended'] ?? false);
                        $user->forceFill(['suspended_at' => $on ? ($user->suspended_at ?? now()) : null, 'suspended_reason' => $on ? (filled($data['reason'] ?? null) ? trim((string) $data['reason']) : null) : null])->save();
                        app(AuditLog::class)->record($on ? 'account_suspended' : 'account_unsuspended', (int) auth()->id(), $user, ($on ? 'Suspended ' : 'Suspension lifted for ').Str::limit($user->email, 120, ''));
                        Notification::make()->title(e(($on ? 'Suspended: ' : 'Restored: ').$user->email))->success()->send();
                    }),
                // A person's own features and limits (since v1.6): on top of their workspace's plan, for them only.
                Action::make('overrides')->label('Overrides for a person')->icon(Heroicon::OutlinedAdjustmentsHorizontal)->color('gray')
                    ->modalDescription('For one person, on top of the plan of the workspace they work in. "The plan\'s" keeps what the plan says. Retention counts for the workspaces the person owns.')
                    ->schema(fn (): array => [
                        Select::make('user_id')->label('Person')->required()->searchable()->live()
                            ->getSearchResultsUsing(fn (string $search): array => User::query()->where('email', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%')
                                ->orderBy('email')->limit(20)->pluck('email', 'id')->all())
                            ->getOptionLabelUsing(fn ($value): ?string => User::query()->whereKey($value)->value('email'))
                            ->afterStateUpdated(fn ($state, $set) => collect(OverridesForm::toForm(User::query()->find($state)))->each(fn ($v, $k) => $set($k, $v))),
                        ...OverridesForm::fields(),
                    ])
                    ->action(function (array $data): void {
                        $user = User::query()->find((int) $data['user_id']);
                        if (! $user instanceof User) {
                            return;
                        }
                        $user->forceFill(['entitlement_overrides' => OverridesForm::fromForm($data)])->save();
                        app(Entitlements::class)->forget();
                        app(AuditLog::class)->record('entitlements_changed', (int) auth()->id(), $user, 'Features and limits changed for '.Str::limit($user->email, 120, ''));
                        Notification::make()->title(e("Saved for {$user->email}"))->success()->send();
                    }),
                Action::make('create')->label('New plan')->icon(Heroicon::OutlinedPlus)
                    ->schema(fn (): array => [
                        TextInput::make('key')->required()->maxLength(32)->regex('/^[a-z0-9][a-z0-9_-]*$/')
                            ->helperText('A short name for the apps; it cannot change later.')
                            ->rule(fn (): Unique => Rule::unique('plans', 'key')),
                        ...$this->planFields(),
                    ])
                    // A new plan grants no paid feature until someone switches it on (each costs money to run).
                    ->fillForm(fn (): array => ['price_usd' => 0, 'trial_minutes' => 0, 'sort_order' => 50, 'color' => '#64748b', 'engines' => Features::OWN_ENGINES,
                        ...array_fill_keys(array_map(fn (string $f): string => "feature_{$f}", Plan::FEATURES), false)])
                    ->action(function (array $data): void {
                        $plan = Plan::query()->create(['key' => $data['key'], ...self::fromForm($data)]);
                        self::keepOneDefault($plan);
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
                        self::keepOneDefault($record);
                        app(AuditLog::class)->record('plan_changed', (int) auth()->id(), $record, "Plan {$record->name} changed");
                        Notification::make()->title("Plan {$record->name} saved")->success()->send();
                    }),
                Action::make('assign')->label('Assign')->icon(Heroicon::OutlinedArrowRightCircle)->color('gray')
                    ->modalDescription('The workspace is on this plan from now on: its limits and features apply at once. With an end date it goes back to the default plan then.')
                    ->schema([
                        Select::make('workspace_id')->label('Workspace')->required()->searchable()->options(fn (): array => self::workspaceOptions()),
                        DateTimePicker::make('until')->label('Until (optional)')->minDate(now())->seconds(false),
                    ])
                    ->action(function (Plan $record, array $data): void {
                        $workspace = Workspace::query()->find((int) $data['workspace_id']);
                        if ($workspace instanceof Workspace) {
                            $until = filled($data['until'] ?? null) ? Carbon::parse((string) $data['until']) : null;
                            $workspace->forceFill(['plan_id' => $record->id, 'plan_expires_at' => $until])->save();
                            app(Entitlements::class)->forget();
                            app(AuditLog::class)->record('plan_assigned', (int) auth()->id(), $workspace, "{$workspace->name} is now on the {$record->name} plan".($until !== null ? ' until '.$until->toDateString() : ''));
                            Notification::make()->title(e("{$workspace->name} is now on {$record->name}"))->success()->send();
                        }
                    }),
                Action::make('delete')->label('Delete')->icon(Heroicon::OutlinedTrash)->color('danger')
                    ->visible(fn (Plan $record): bool => ! in_array($record->key, [Plan::PERSONAL, Plan::FREE], true) && ! $record->is_default && (int) $record->getAttribute('workspaces_count') === 0)
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
                TextInput::make('badge')->label('Badge')->maxLength(16)->helperText('Shown on locked features, e.g. PRO.'),
                ColorPicker::make('color')->label('Badge colour')->regex('/^#[0-9a-fA-F]{6}$/'),
                TextInput::make('price_label')->label('Price as shown')->maxLength(80)->placeholder('Rs 2,000 a month'),
                TextInput::make('price_usd')->label('Price (US$ a month)')->numeric()->minValue(0)->maxValue(100000)->required(),
                TextInput::make('sort_order')->label('Order on the comparison')->integer()->minValue(0)->maxValue(1000)->required(),
                TextInput::make('trial_minutes')->label('Trial minutes')->integer()->minValue(0)->maxValue(100000)->required()
                    ->helperText('Transcription on EchoLedger a person may try once without that feature.'),
            ]),
            Textarea::make('description')->maxLength(500)->rows(2),
            Grid::make(['default' => 2])->schema([
                Toggle::make('on_offer')->label('On offer')->helperText('Listed in the apps\' plan comparison.'),
                Toggle::make('is_default')->label('Default for sign-ups')->helperText('New accounts get it, and a plan that ends falls back to it.'),
            ]),
            Grid::make(['default' => 2])->schema([
                TextInput::make('monthly_minutes')->label('Minutes a month')->integer()->minValue(0)->maxValue(100000000),
                TextInput::make('storage_gb')->label('Storage (GB)')->numeric()->minValue(0)->maxValue(1000000),
                TextInput::make('max_devices')->label('Devices per person')->integer()->minValue(0)->maxValue(100000),
                TextInput::make('retention_days')->label('Retention (days)')->integer()->minValue(1)->maxValue(3650)
                    ->helperText('Applies to recordings made after it is set; older ones are never deleted by it.'),
                TextInput::make('seats')->integer()->minValue(1)->maxValue(100000),
                TextInput::make('ai_credits_usd')->label('AI credits (US$ a month)')->numeric()->minValue(0)->maxValue(100000),
                TextInput::make('max_file_minutes')->label('Longest recording (minutes)')->integer()->minValue(1)->maxValue(100000),
                TextInput::make('uploads_per_day')->label('Recordings a day')->integer()->minValue(1)->maxValue(100000),
            ]),
            CheckboxList::make('engines')->label('Engines')->columns(2)
                ->options(self::ENGINE_LABELS)
                ->helperText('Leave every box ticked for every engine. Premium engines also need the Premium engines feature.'),
            ...array_map(fn (string $feature): Toggle => Toggle::make("feature_{$feature}")->label(Plan::FEATURE_LABELS[$feature])
                ->helperText(Features::ALL[$feature]['description']), Plan::FEATURES),
        ];
    }

    /** What the engines are called on the Plans page. */
    private const ENGINE_LABELS = [
        'own_groq' => 'The person\'s own Groq key', 'on_device' => 'On the computer (desktop)', 'assemblyai' => 'AssemblyAI', 'groq' => 'Groq (the server\'s key)',
        'soniox' => 'Soniox', 'azure_mai' => 'Azure Speech', 'elevenlabs' => 'ElevenLabs (premium)', 'gemini' => 'Gemini (premium)',
    ];

    /** @return array<int, string> workspaces as "Name · owner's email", for the Assign list */
    private static function workspaceOptions(): array
    {
        $owners = DB::table('workspace_user')->join('users', 'users.id', '=', 'workspace_user.user_id')->where('workspace_user.role', 'owner')
            ->pluck('users.email', 'workspace_user.workspace_id');

        return Workspace::query()->orderBy('name')->limit(1000)->get(['id', 'name'])
            ->mapWithKeys(fn (Workspace $w): array => [$w->id => $w->name.(isset($owners[$w->id]) ? ' · '.$owners[$w->id] : '')])->all();
    }

    /** One plan is the default for sign-ups: marking another one moves it. */
    private static function keepOneDefault(Plan $plan): void
    {
        if ($plan->is_default) {
            Plan::query()->whereKeyNot($plan->id)->where('is_default', true)->update(['is_default' => false]);
        }
        app(Entitlements::class)->forget();
    }

    /** @return array<string, mixed> */
    private static function toForm(Plan $plan): array
    {
        return [
            'name' => $plan->name,
            'badge' => $plan->badge,
            'color' => $plan->colorOrDefault(),
            'price_label' => $plan->price_label,
            'sort_order' => $plan->sort_order,
            'trial_minutes' => $plan->trial_minutes,
            'description' => $plan->description,
            'on_offer' => $plan->on_offer,
            'is_default' => $plan->is_default,
            'max_file_minutes' => $plan->max_file_minutes,
            'uploads_per_day' => $plan->uploads_per_day,
            'engines' => $plan->engineList(),
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

        $engines = array_values(array_intersect(Features::ENGINES, (array) ($data['engines'] ?? [])));

        return [
            'name' => trim((string) $data['name']),
            'badge' => filled($data['badge'] ?? null) ? mb_strtoupper(trim((string) $data['badge'])) : null,
            'color' => is_string($data['color'] ?? null) && preg_match('/^#[0-9a-fA-F]{6}$/', $data['color']) === 1 ? strtolower($data['color']) : null,
            'price_label' => filled($data['price_label'] ?? null) ? trim((string) $data['price_label']) : null,
            'sort_order' => max(0, (int) ($data['sort_order'] ?? 0)),
            'trial_minutes' => max(0, (int) ($data['trial_minutes'] ?? 0)),
            'description' => filled($data['description'] ?? null) ? trim((string) $data['description']) : null,
            'on_offer' => (bool) ($data['on_offer'] ?? false),
            'is_default' => (bool) ($data['is_default'] ?? false),
            'max_file_minutes' => $number('max_file_minutes'),
            'uploads_per_day' => $number('uploads_per_day'),
            'engines' => count($engines) === count(Features::ENGINES) ? null : $engines,
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
