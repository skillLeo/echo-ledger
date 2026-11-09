<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\StaffRole;
use App\Models\User;
use App\Services\AuditLog;
use App\Services\Staff\StaffAccess;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Admin panel → Staff (since v1.6, P2.5): who works on the platform, with their role, and the permissions matrix: a
 * box per permission and role. The super admin has every permission and needs no role; four roles come ready (Admin,
 * Support, Billing, Read-only) and more can be made. Every member of staff needs two-step sign-in before /admin opens.
 */
class Staff extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Identification;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Staff';

    protected static ?string $slug = 'staff';

    public static function canAccess(): bool
    {
        return StaffAccess::can('staff.manage');
    }

    public function getSubheading(): ?string
    {
        return 'Who works on EchoLedger and what each may do here. Everyone needs two-step sign-in before the admin panel opens.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
            View::make('filament.admin.permissions-matrix')->viewData(fn (): array => [
                'roles' => StaffRole::query()->withCount('users')->orderByDesc('is_system')->orderBy('id')->get(),
                'permissions' => StaffAccess::PERMISSIONS,
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => User::query()->with('staffRole')->where(fn (Builder $q) => $q->where('is_super_admin', true)->orWhereNotNull('staff_role_id')))
            ->defaultSort('name')
            ->heading('Staff')
            ->columns([
                TextColumn::make('name')->description(fn (User $record): string => $record->email)->searchable(['name', 'email']),
                TextColumn::make('role')->badge()->state(fn (User $record): string => StaffAccess::roleName($record) ?? '—')
                    ->color(fn (User $record): string => $record->is_super_admin ? 'primary' : 'gray'),
                IconColumn::make('two_factor')->label('Two-step sign-in')->boolean()->state(fn (User $record): bool => $record->hasTwoFactor()),
            ])
            ->headerActions([
                Action::make('addStaff')->label('Add staff')->icon(Heroicon::OutlinedUserPlus)
                    ->modalDescription('The person needs an account first (they sign up or you invite them). They open the admin panel from their avatar menu once two-step sign-in is on.')
                    ->schema([
                        TextInput::make('email')->label('Their email')->email()->required()->maxLength(255),
                        Select::make('staff_role_id')->label('Role')->required()->options(fn (): array => StaffRole::query()->orderBy('id')->pluck('name', 'id')->all()),
                    ])
                    ->action(function (array $data): void {
                        $user = User::query()->where('email', mb_strtolower(trim((string) $data['email'])))->first();
                        $role = StaffRole::query()->find((int) $data['staff_role_id']);
                        if (! $user instanceof User || ! $role instanceof StaffRole) {
                            Notification::make()->title('No account has that email')->danger()->send();

                            return;
                        }
                        if (! self::mayGive($role) || $user->is_super_admin) {
                            Notification::make()->title('You can give only a role whose permissions you have yourself')->danger()->send();

                            return;
                        }
                        $user->forceFill(['staff_role_id' => $role->id])->save();
                        app(AuditLog::class)->record('staff_role_changed', (int) auth()->id(), $user, Str::limit($user->email, 120, '')." is now {$role->name} staff");
                        Notification::make()->title(e("{$user->email} is now {$role->name} staff"))->success()->send();
                    }),
                Action::make('newRole')->label('New role')->icon(Heroicon::OutlinedPlus)->color('gray')
                    ->schema([TextInput::make('name')->required()->maxLength(80)])
                    ->action(function (array $data): void {
                        $name = trim((string) $data['name']);
                        $key = Str::limit(Str::slug($name, '_'), 30, '') ?: 'role';
                        for ($base = $key, $n = 2; StaffRole::query()->where('key', $key)->exists(); $n++) {
                            $key = $base.'_'.$n;
                        }
                        $role = StaffRole::query()->create(['key' => $key, 'name' => $name, 'permissions' => [], 'is_system' => false]);
                        app(AuditLog::class)->record('staff_role_changed', (int) auth()->id(), $role, "Staff role {$role->name} made");
                        Notification::make()->title(e("Role {$role->name} made: tick what it may do below"))->success()->send();
                    }),
            ])
            ->recordActions([
                Action::make('changeRole')->label('Change role')->icon(Heroicon::OutlinedArrowsRightLeft)->color('gray')
                    ->visible(fn (User $record): bool => ! $record->is_super_admin)
                    ->fillForm(fn (User $record): array => ['staff_role_id' => $record->staff_role_id])
                    ->schema([Select::make('staff_role_id')->label('Role')->required()->options(fn (): array => StaffRole::query()->orderBy('id')->pluck('name', 'id')->all())])
                    ->action(function (User $record, array $data): void {
                        $role = StaffRole::query()->findOrFail((int) $data['staff_role_id']);
                        if (! self::mayGive($role) || $record->id === auth()->id()) {
                            Notification::make()->title('You can give only a role whose permissions you have yourself, and not to yourself')->danger()->send();

                            return;
                        }
                        $record->forceFill(['staff_role_id' => $role->id])->save();
                        app(AuditLog::class)->record('staff_role_changed', (int) auth()->id(), $record, Str::limit($record->email, 120, '')." is now {$role->name} staff");
                        Notification::make()->title('Saved')->success()->send();
                    }),
                Action::make('removeStaff')->label('Remove from staff')->icon(Heroicon::OutlinedUserMinus)->color('danger')
                    ->visible(fn (User $record): bool => ! $record->is_super_admin && $record->id !== auth()->id())
                    ->requiresConfirmation()
                    ->modalDescription('They keep their own account and recordings; the admin panel closes for them at once.')
                    ->action(function (User $record): void {
                        $record->forceFill(['staff_role_id' => null])->save();
                        app(AuditLog::class)->record('staff_role_changed', (int) auth()->id(), $record, Str::limit($record->email, 120, '').' is no longer staff');
                        Notification::make()->title('Removed from staff')->success()->send();
                    }),
            ])
            ->paginated(false);
    }

    /** One box of the matrix: [$permission] on or off for [$role]. */
    public function togglePermission(int $role, string $permission): void
    {
        abort_unless(StaffAccess::can('staff.manage'), 403);
        abort_unless(isset(StaffAccess::PERMISSIONS[$permission]), 404);
        $record = StaffRole::query()->findOrFail($role);
        $permissions = $record->permissions;
        $on = ! in_array($permission, $permissions, true);
        // Only the super admin changes their own kind of role or gives a permission they would not have themselves.
        $me = auth()->user();
        abort_unless($me instanceof User && ($me->is_super_admin || ($me->staff_role_id !== $record->id && (! $on || StaffAccess::can($permission, $me)))), 403);
        $permissions = $on ? [...$permissions, $permission] : array_values(array_diff($permissions, [$permission]));
        $record->forceFill(['permissions' => array_values(array_intersect(array_keys(StaffAccess::PERMISSIONS), $permissions))])->save();
        app(AuditLog::class)->record('staff_role_changed', (int) auth()->id(), $record, "{$record->name}: ".StaffAccess::PERMISSIONS[$permission].($on ? ' allowed' : ' taken away'));
        Notification::make()->title(e($record->name.($on ? ' may now: ' : ' may no longer: ').StaffAccess::PERMISSIONS[$permission]))->success()->send();
    }

    /** Whether the signed-in person may give [$role]: the super admin any; other staff only one within their own permissions. */
    private static function mayGive(StaffRole $role): bool
    {
        $me = auth()->user();
        if (! $me instanceof User) {
            return false;
        }

        return $me->is_super_admin || array_diff($role->permissions, array_keys(array_filter(StaffAccess::PERMISSIONS, fn (string $label, string $p): bool => StaffAccess::can($p, $me), ARRAY_FILTER_USE_BOTH))) === [];
    }

    /** Deletes a role that was made here and nobody has (the four that come ready stay). */
    public function deleteRole(int $role): void
    {
        abort_unless(StaffAccess::can('staff.manage'), 403);
        $record = StaffRole::query()->withCount('users')->findOrFail($role);
        if ($record->is_system || (int) $record->getAttribute('users_count') > 0) {
            Notification::make()->title($record->is_system ? 'The roles that come ready stay' : 'Give its people another role first')->danger()->send();

            return;
        }
        $name = $record->name;
        $record->delete();
        app(AuditLog::class)->record('staff_role_changed', (int) auth()->id(), null, "Staff role {$name} deleted");
        Notification::make()->title(e("Role {$name} deleted"))->success()->send();
    }
}
