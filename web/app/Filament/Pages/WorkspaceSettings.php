<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\WorkspaceRole;
use App\Exceptions\ApiError;
use App\Filament\Support\PlanLock;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Services\Accounts\Invitations;
use App\Services\Accounts\SignUp;
use App\Services\AuditLog;
use App\Services\Workspaces\ConsoleAccess;
use App\Services\Workspaces\CurrentWorkspace;
use App\Services\Workspaces\PlanLimits;
use App\Services\Workspaces\WorkspaceEraser;
use App\Services\Workspaces\Workspaces;
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
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use InvalidArgumentException;
use UnitEnum;

/**
 * The current workspace (B20; v1.5 roles): its people and their roles, invitations, handing it over, leaving it,
 * switching between workspaces (since v1.6 the overview of every workspace is on the admin panel's home). What each
 * role may do is App\Policies\WorkspacePolicy.
 */
class WorkspaceSettings extends Page implements HasTable
{
    use InteractsWithTable;

    /** People one account may add or invite in an hour (since v1.5). */
    public const ADDS_PER_HOUR = 20;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::UserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Workspace';

    /** Since v1.5 in the small console too (members and viewers), unless Settings closes the console to them. */
    public static function canAccess(): bool
    {
        return ConsoleAccess::open();
    }

    protected static ?string $slug = 'workspace';

    public function getSubheading(): ?string
    {
        $workspace = $this->workspace();
        $role = $workspace !== null ? $this->roleOf($this->user()) : null;
        if ($workspace === null || $role === null) {
            return $workspace?->name;
        }

        return $workspace->name.' · You are '.($role === WorkspaceRole::Owner || $role === WorkspaceRole::Admin ? 'an ' : 'a ').Str::lower($role->label())
            .($this->can('manage') ? ' · Plan '.$workspace->plan->name : '');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
            View::make('filament.pages.workspace-invitations')
                ->visible(fn (): bool => $this->can('invite'))
                ->viewData(fn (): array => ['invitations' => $this->openInvitations(), 'plan' => $this->planLine()]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => User::query()->whereIn('id', fn ($q) => $q->select('user_id')->from('workspace_user')->where('workspace_id', CurrentWorkspace::id())))
            ->heading('People')
            ->description('Each person\'s recordings and projects are theirs alone, owners and admins included, until they share them. Owners and admins invite and remove people and revoke devices; viewers open what is shared with them and record nothing.')
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('email'),
                TextColumn::make('role')->badge()
                    ->state(fn (User $record): string => $this->roleOf($record)?->label() ?? '')
                    ->color(fn (string $state): string => match ($state) {
                        WorkspaceRole::Owner->label() => 'primary',
                        WorkspaceRole::Admin->label() => 'info',
                        default => 'gray',
                    }),
            ])
            ->recordActions([
                Action::make('changeRole')->label('Change role')
                    ->icon(Heroicon::OutlinedArrowsRightLeft)->color('gray')
                    ->authorize(fn (User $record): bool => $this->can('manageMember', $record))
                    ->fillForm(fn (User $record): array => ['role' => $this->roleOf($record)?->value])
                    ->schema([Select::make('role')->options(fn (): array => $this->assignableRoles())->required()->selectablePlaceholder(false)])
                    ->action(function (User $record, array $data): void {
                        $to = WorkspaceRole::tryFrom((string) ($data['role'] ?? ''));
                        if ($to === null || ! $this->can('assignRole', $to)) {
                            Notification::make()->title('You cannot give that role.')->danger()->send();

                            return;
                        }
                        if ($this->attempt(fn () => app(Workspaces::class)->setRole($this->workspaceOrFail(), $record, $to), "{$record->name} is now ".Str::lower($to->label()))) {
                            app(AuditLog::class)->record('role_changed', $this->user()->id, $this->workspaceOrFail(), "{$record->email} is now ".Str::lower($to->label()).' of '.$this->workspaceOrFail()->name);
                        }
                    }),
                Action::make('remove')->label('Remove')->icon(Heroicon::OutlinedUserMinus)->color('danger')
                    ->authorize(fn (User $record): bool => $this->can('manageMember', $record))
                    ->requiresConfirmation()->modalDescription('They lose access to this workspace at once and their devices here are signed out. Their links stop working, and what was sent to them from here ends (copies they saved stay theirs). What they recorded stays theirs: nobody here sees it, and it is theirs again if they come back.')
                    ->action(function (User $record): void {
                        if ($this->attempt(fn () => app(Workspaces::class)->removeMember($this->workspaceOrFail(), $record), "{$record->name} removed")) {
                            app(AuditLog::class)->record('member_removed', $this->user()->id, $this->workspaceOrFail(), "Removed {$record->email} from ".$this->workspaceOrFail()->name);
                        }
                    }),
            ])
            ->paginated([25, 50, 100]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('switch')->label('Switch workspace')->icon(Heroicon::OutlinedArrowsRightLeft)->color('gray')
                ->visible(fn (): bool => $this->user()->workspaces()->count() > 1)
                ->schema([Select::make('workspace_id')->label('Workspace')->required()
                    ->options(fn (): array => $this->user()->workspaces()->orderBy('name')->pluck('workspaces.name', 'workspaces.id')->all())
                    ->default(fn (): ?int => CurrentWorkspace::id())])
                ->action(function (array $data): void {
                    $workspace = $this->user()->workspaces()->whereKey((int) $data['workspace_id'])->first();
                    if ($workspace instanceof Workspace) {
                        app(Workspaces::class)->switchTo($this->user(), $workspace);
                        $this->redirect(Dashboard::getUrl());
                    }
                }),
            // Since v1.6 a team is a paid feature: without it Invite carries the plan's badge and opens the sheet.
            ...(PlanLock::allows('team') ? [] : [PlanLock::sheet('upgradeForTeam', 'team', 'Invite')->authorize(fn (): bool => $this->can('invite'))]),
            Action::make('addMember')->label('Invite')->icon(Heroicon::OutlinedUserPlus)
                ->visible(fn (): bool => PlanLock::allows('team'))
                ->authorize(fn (): bool => $this->can('invite'))
                ->modalDescription(fn (): string => app(SignUp::class)->open()
                    ? 'They get an email with a link to join, for 7 days; someone without an account makes one with it.'
                    : 'They get an email with a link to join, for 7 days. Someone without an account gets one with a one-time password, shown to you once.')
                ->schema([
                    TextInput::make('email')->email()->required()->maxLength(255),
                    TextInput::make('name')->maxLength(255)->helperText('For a new account.'),
                    Select::make('role')->options(fn (): array => $this->assignableRoles())
                        ->default(WorkspaceRole::Member->value)->selectablePlaceholder(false)->required(),
                ])
                ->action(fn (array $data) => $this->addMember($data)),
            Action::make('rename')->label('Rename')->icon(Heroicon::OutlinedPencilSquare)->color('gray')
                ->authorize(fn (): bool => $this->can('manage'))
                ->fillForm(fn (): array => ['name' => $this->workspace()?->name])
                ->schema([TextInput::make('name')->required()->maxLength(120)])
                ->action(function (array $data): void {
                    $this->workspaceOrFail()->update(['name' => trim((string) $data['name'])]);
                    app(AuditLog::class)->record('workspace_renamed', $this->user()->id, $this->workspaceOrFail(), 'Workspace renamed to '.$this->workspaceOrFail()->name);
                    Notification::make()->title('Workspace renamed')->success()->send();
                }),
            Action::make('transfer')->label('Hand over')->icon(Heroicon::OutlinedKey)->color('gray')
                ->authorize(fn (): bool => $this->can('manage'))
                ->modalDescription('The person you pick becomes the owner; you stay as an admin. Only they can hand it back.')
                ->schema([Select::make('user_id')->label('New owner')->required()
                    ->options(fn (): array => $this->workspaceOrFail()->members()
                        ->wherePivotIn('role', [WorkspaceRole::Admin->value, WorkspaceRole::Member->value])
                        ->orderBy('name')->pluck('users.name', 'users.id')->all())])
                ->action(function (array $data): void {
                    $to = $this->workspaceOrFail()->members()->whereKey((int) $data['user_id'])->first();
                    if ($to instanceof User && $this->attempt(fn () => app(Workspaces::class)->transfer($this->workspaceOrFail(), $this->user(), $to), "{$to->name} is now the owner")) {
                        app(AuditLog::class)->record('workspace_transferred', $this->user()->id, $this->workspaceOrFail(), "Handed {$this->workspaceOrFail()->name} over to {$to->email}");
                    }
                }),
            Action::make('leave')->label('Leave')->icon(Heroicon::OutlinedArrowRightStartOnRectangle)->color('danger')
                ->authorize(fn (): bool => $this->can('leave'))
                ->requiresConfirmation()->modalDescription('You lose access to this workspace and your devices here are signed out. Your links here stop working, and what was sent to you from here ends (copies you saved stay yours). What you recorded here stays yours, seen by nobody else.')
                ->action(function (): void {
                    $workspace = $this->workspaceOrFail();
                    if ($this->attempt(fn () => app(Workspaces::class)->leave($workspace, $this->user()), "You left {$workspace->name}")) {
                        app(AuditLog::class)->record('member_left', $this->user()->id, $workspace, "Left {$workspace->name}");
                        $this->redirect(Dashboard::getUrl());
                    }
                }),
            Action::make('deleteWorkspace')->label('Delete workspace')->icon(Heroicon::OutlinedTrash)->color('danger')
                ->authorize(fn (): bool => $this->can('manage'))
                ->requiresConfirmation()
                ->modalDescription('Everything in it is erased for good: recordings, transcripts, screens, projects and their files, request links, devices and its usage. Only possible once nobody else is in it.')
                ->action(function (): void {
                    $workspace = $this->workspaceOrFail();
                    if ($this->attempt(fn () => app(WorkspaceEraser::class)->erase($workspace, $this->user()), "{$workspace->name} deleted")) {
                        $this->redirect(Dashboard::getUrl());
                    }
                }),
        ];
    }

    /** Cancels taken back per hour by one account (since v1.5, Phase 1C). */
    public const CANCELS_PER_HOUR = 60;

    /**
     * Sends a pending invitation again (since v1.5, Phase 1C): a new link for 7 more days, the old one stops working.
     * Each one emails the address, so it counts against the same 20 an hour as Invite.
     */
    public function resendInvitation(int $id): void
    {
        if (! $this->can('invite')) {
            return;
        }
        $invitation = WorkspaceInvitation::query()->where('workspace_id', CurrentWorkspace::id())->whereNull('accepted_at')->find($id);
        if (! $invitation instanceof WorkspaceInvitation) {
            Notification::make()->title('That invitation is no longer pending.')->warning()->send();

            return;
        }
        if (! $this->can('assignRole', $invitation->role)) {
            Notification::make()->title('You cannot give that role.')->danger()->send();

            return;
        }
        $key = 'add-member:'.$this->user()->id;
        if (RateLimiter::tooManyAttempts($key, self::ADDS_PER_HOUR)) {
            Notification::make()->title('Too many invitations sent for now')
                ->body('Try again in '.ceil(RateLimiter::availableIn($key) / 60).' minutes.')->danger()->send();

            return;
        }
        RateLimiter::hit($key, 3600);
        $this->attempt(fn () => app(Invitations::class)->invite($this->workspaceOrFail(), $this->user(), $invitation->email, $invitation->role), "Invitation sent again to {$invitation->email}");
    }

    /** Cancels an invitation that was not used yet; its link stops working and its seat is free again. */
    public function revokeInvitation(int $id): void
    {
        if (! $this->can('invite')) {
            return;
        }
        $key = 'cancel-invitation:'.$this->user()->id;
        if (RateLimiter::tooManyAttempts($key, self::CANCELS_PER_HOUR)) {
            Notification::make()->title('Too many invitations cancelled for now')
                ->body('Try again in '.ceil(RateLimiter::availableIn($key) / 60).' minutes.')->danger()->send();

            return;
        }
        RateLimiter::hit($key, 3600);
        $invitation = WorkspaceInvitation::query()->where('workspace_id', CurrentWorkspace::id())->whereNull('accepted_at')->find($id);
        if ($invitation instanceof WorkspaceInvitation) {
            $invitation->delete();
            app(AuditLog::class)->record('invitation_revoked', $this->user()->id, $this->workspaceOrFail(), "Took back the invitation of {$invitation->email}");
            Notification::make()->title(e("Invitation for {$invitation->email} cancelled"))->success()->send();
        }
    }

    /** @param  array{email: string, name?: string|null, role: string}  $data */
    private function addMember(array $data): void
    {
        // Each one emails the address: 20 an hour, so a workspace cannot be used to send this server's emails to a
        // list of strangers.
        $key = 'add-member:'.$this->user()->id;
        if (RateLimiter::tooManyAttempts($key, self::ADDS_PER_HOUR)) {
            Notification::make()->title('Too many people added for now')
                ->body('Try again in '.ceil(RateLimiter::availableIn($key) / 60).' minutes.')->danger()->send();

            return;
        }
        $role = WorkspaceRole::tryFrom($data['role']);
        if ($role === null || ! $this->can('assignRole', $role)) {
            Notification::make()->title('You cannot give that role.')->danger()->send();

            return;
        }
        RateLimiter::hit($key, 3600);
        $email = Str::lower(trim($data['email']));
        $user = User::query()->where('email', $email)->first();
        // While nobody may sign up, an address without an account gets one with a one-time password and joins at once
        // (B20); everyone else gets an invitation link (since v1.5).
        if (! $user instanceof User && ! app(SignUp::class)->open()) {
            $this->addWithPassword($email, trim((string) ($data['name'] ?? '')), $role);

            return;
        }
        $this->attempt(fn () => app(Invitations::class)->invite($this->workspaceOrFail(), $this->user(), $email, $role), "Invitation sent to {$email}");
    }

    private function addWithPassword(string $email, string $name, WorkspaceRole $role): void
    {
        $workspace = $this->workspaceOrFail();
        try {
            app(PlanLimits::class)->assertFeature($workspace->id, 'team', $this->user()->id);
            app(PlanLimits::class)->assertSeat($workspace->id, null, $this->user()->id);
        } catch (ApiError $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }
        $password = Str::password(20, symbols: false);
        $user = User::query()->create(['name' => $name ?: Str::before($email, '@'), 'email' => $email, 'password' => $password]);
        if (! $this->attempt(fn () => app(Workspaces::class)->addMember($workspace, $user, $role), "{$user->name} added")) {
            return;
        }
        app(Invitations::class)->tellAdded($workspace, $this->user(), $user);
        app(AuditLog::class)->record('member_added', $this->user()->id, $workspace, "Added {$email} to {$workspace->name}");
        Notification::make()->title('One-time password for '.e($email))
            ->body("{$password}\nShare it privately; they can change it on their profile after signing in.")
            ->persistent()->warning()->send();
    }

    private function attempt(callable $change, string $done): bool
    {
        try {
            $change();
        } catch (InvalidArgumentException|ApiError $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return false;
        }
        // Toasts render their title as HTML: a person's or a workspace's name is text.
        Notification::make()->title(e($done))->success()->send();

        return true;
    }

    /** @return array<string, string> the roles the signed-in person may give */
    private function assignableRoles(): array
    {
        return collect(WorkspaceRole::cases())->filter(fn (WorkspaceRole $r): bool => $this->can('assignRole', $r))
            ->mapWithKeys(fn (WorkspaceRole $r): array => [$r->value => $r->label()])->all();
    }

    /** @return list<array{id: int, email: string, role: string, expires: string, sent: string|null}> */
    private function openInvitations(): array
    {
        return WorkspaceInvitation::query()->where('workspace_id', CurrentWorkspace::id())->whereNull('accepted_at')->where('expires_at', '>', now())
            ->orderBy('expires_at')->limit(100)->get()
            ->map(fn (WorkspaceInvitation $i): array => ['id' => $i->id, 'email' => $i->email, 'role' => $i->role->label(), 'expires' => $i->expires_at->diffForHumans(),
                'sent' => $i->created_at?->diffForHumans()])
            ->all();
    }

    private function planLine(): ?string
    {
        if (! $this->can('manage')) {
            return null;
        }
        $seats = app(PlanLimits::class)->summary((int) CurrentWorkspace::id())['seats'];

        return $seats['limit'] === null ? null : "{$seats['used']} of {$seats['limit']} seats taken (open invitations count).";
    }

    /** One row of App\Policies\WorkspacePolicy for the signed-in person in the current workspace. */
    private function can(string $ability, mixed ...$arguments): bool
    {
        $workspace = $this->workspace();

        return $workspace !== null && Gate::forUser($this->user())->allows($ability, [$workspace, ...$arguments]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    private function workspace(): ?Workspace
    {
        return Workspace::query()->find(CurrentWorkspace::id());
    }

    private function workspaceOrFail(): Workspace
    {
        return Workspace::query()->findOrFail(CurrentWorkspace::id());
    }

    private function roleOf(User $user): ?WorkspaceRole
    {
        return app(Workspaces::class)->roleOf($user, (int) CurrentWorkspace::id());
    }
}
