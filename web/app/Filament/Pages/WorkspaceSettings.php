<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\WorkspaceRole;
use App\Exceptions\ApiError;
use App\Filament\Support\ConsoleV2;
use App\Filament\Support\Notice as Notification;
use App\Filament\Support\NoticeTargets;
use App\Filament\Support\PersonAvatar;
use App\Filament\Support\PlanLock;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceInviteLink;
use App\Services\Accounts\EmailChange;
use App\Services\Accounts\Invitations;
use App\Services\Accounts\InviteLinks;
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
use Illuminate\Support\HtmlString;
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

    /** What each role may do in a workspace (docs/API.md "Workspaces, roles and privacy"): owner, admin, member, viewer. */
    public const ROLE_MATRIX = [
        'Record, upload, import' => ['yes', 'yes', 'yes', 'no'],
        'Sign in their own phones and computers' => ['yes', 'yes', 'yes', 'yes'],
        'Their own recordings and projects: open, edit, share, delete' => ['yes', 'yes', 'yes', 'has none'],
        'Shared with me: open, Save to my library' => ['yes', 'yes', 'yes', 'open only'],
        'Invite and remove people, change roles' => ['yes', 'members and viewers', 'no', 'no'],
        'Workspace usage: minutes and costs per person (never content)' => ['yes', 'yes', 'their own', 'no'],
        'Revoke another person\'s device' => ['yes', 'yes', 'no', 'no'],
        'Plan, workspace name, hand over, delete the workspace' => ['yes', 'no', 'no', 'no'],
    ];

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

    /** In the console's language (the new frame in Urdu); English reads as before. */
    public function getTitle(): string
    {
        return __('Workspace');
    }

    public static function getNavigationLabel(): string
    {
        return __('Workspace');
    }

    public function getSubheading(): ?string
    {
        $workspace = $this->workspace();
        $role = $workspace !== null ? $this->roleOf($this->user()) : null;
        if ($workspace === null || $role === null) {
            return $workspace?->name;
        }
        // English keeps its "an owner", "an admin", "a member", "a viewer".
        $you = $role === WorkspaceRole::Owner || $role === WorkspaceRole::Admin
            ? __('You are an :role', ['role' => Str::lower(__($role->label()))])
            : __('You are a :role', ['role' => Str::lower(__($role->label()))]);

        return $workspace->name.' · '.$you
            .($this->can('manage') ? ' · '.__('Plan :plan', ['plan' => $workspace->plan->name]) : '');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
            View::make('filament.pages.workspace-invitations')
                ->visible(fn (): bool => $this->can('invite'))
                ->viewData(fn (): array => ['invitations' => $this->openInvitations(), 'links' => $this->openLinks(), 'plan' => $this->planLine(), 'v2' => ConsoleV2::any()]),
            // Since v1.6 (P2.5): what each role may do here, as the admin panel shows staff roles.
            View::make('filament.pages.workspace-roles-matrix')->viewData(fn (): array => ['rows' => self::ROLE_MATRIX, 'mine' => $this->roleOf($this->user())?->value]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => User::query()->whereIn('id', fn ($q) => $q->select('user_id')->from('workspace_user')->where('workspace_id', CurrentWorkspace::id())))
            ->heading(__('People'))
            ->description(__("Each person's recordings and projects are theirs alone, owners and admins included, until they share them. Owners and admins invite and remove people and revoke devices; viewers open what is shared with them and record nothing."))
            ->columns([
                // DES-08: in the new console each person's avatar is beside their name.
                ConsoleV2::any()
                    ? TextColumn::make('name')->label(__('Name'))->formatStateUsing(fn (User $record): HtmlString => PersonAvatar::cell($record))
                    : TextColumn::make('name')->label(__('Name')),
                TextColumn::make('email')->label(__('Email')),
                TextColumn::make('role')->label(__('Role'))->badge()
                    ->state(fn (User $record): string => $this->roleOf($record)?->label() ?? '')
                    // The badge's colour goes by the English label (the state); the words are the reader's.
                    ->formatStateUsing(fn (string $state): string => $state === '' ? '' : __($state))
                    ->color(fn (string $state): string => match ($state) {
                        WorkspaceRole::Owner->label() => 'primary',
                        WorkspaceRole::Admin->label() => 'info',
                        default => 'gray',
                    }),
            ])
            ->recordActions([
                Action::make('changeRole')->label(__('Change role'))
                    ->icon(Heroicon::OutlinedArrowsRightLeft)->color('gray')
                    ->authorize(fn (User $record): bool => $this->can('manageMember', $record))
                    ->fillForm(fn (User $record): array => ['role' => $this->roleOf($record)?->value])
                    ->schema([Select::make('role')->label(__('Role'))->options(fn (): array => $this->assignableRoles())->required()->selectablePlaceholder(false)])
                    ->action(function (User $record, array $data): void {
                        $to = WorkspaceRole::tryFrom((string) ($data['role'] ?? ''));
                        if ($to === null || ! $this->can('assignRole', $to)) {
                            Notification::make()->title(__('You cannot give that role.'))->danger()->send();

                            return;
                        }
                        if ($this->attempt(fn () => app(Workspaces::class)->setRole($this->workspaceOrFail(), $record, $to), __(':name is now :role', ['name' => $record->name, 'role' => Str::lower(__($to->label()))]))) {
                            app(AuditLog::class)->record('role_changed', $this->user()->id, $this->workspaceOrFail(), "{$record->email} is now ".Str::lower($to->label()).' of '.$this->workspaceOrFail()->name);
                        }
                    }),
                Action::make('remove')->label(__('Remove'))->icon(Heroicon::OutlinedUserMinus)->color('danger')
                    ->authorize(fn (User $record): bool => $this->can('manageMember', $record))
                    ->requiresConfirmation()->modalDescription(__('They lose access to this workspace at once and their devices here are signed out. Their links stop working, and what was sent to them from here ends (copies they saved stay theirs). What they recorded stays theirs: nobody here sees it, and it is theirs again if they come back.'))
                    ->action(function (User $record): void {
                        if ($this->attempt(fn () => app(Workspaces::class)->removeMember($this->workspaceOrFail(), $record), __(':name removed', ['name' => $record->name]))) {
                            app(AuditLog::class)->record('member_removed', $this->user()->id, $this->workspaceOrFail(), "Removed {$record->email} from ".$this->workspaceOrFail()->name);
                        }
                    }),
            ])
            ->paginated([25, 50, 100]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('switch')->label(__('Switch workspace'))->icon(Heroicon::OutlinedArrowsRightLeft)->color('gray')
                ->visible(fn (): bool => $this->user()->workspaces()->count() > 1)
                ->schema([Select::make('workspace_id')->label(__('Workspace'))->required()
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
            ...(PlanLock::allows('team') ? [] : [PlanLock::sheet('upgradeForTeam', 'team', __('Invite'))->authorize(fn (): bool => $this->can('invite'))]),
            Action::make('addMember')->label(__('Invite'))->icon(Heroicon::OutlinedUserPlus)
                ->visible(fn (): bool => PlanLock::allows('team'))
                ->authorize(fn (): bool => $this->can('invite'))
                ->modalDescription(fn (): string => (app(SignUp::class)->open()
                    ? __('They get an email with a link to join; someone without an account makes one with it.')
                    : __('They get an email with a link to join. Someone without an account gets one with a one-time password, shown to you once.'))
                    .(($seats = $this->planLine()) !== null ? ' '.$seats : ''))
                ->schema([
                    TextInput::make('email')->label(__('Email'))->email()->required()->maxLength(255),
                    TextInput::make('name')->label(__('Name'))->maxLength(255)->helperText(__('For a new account.')),
                    Select::make('role')->label(__('Role'))->options(fn (): array => $this->assignableRoles())
                        ->default(WorkspaceRole::Member->value)->selectablePlaceholder(false)->required(),
                    // Since v1.8 P6.3: how long the link in the email works.
                    Select::make('days')->label(__('The link works for'))->options(self::dayOptions())->default('7')->selectablePlaceholder(false)->required(),
                ])
                ->action(fn (array $data) => $this->addMember($data)),
            // Since v1.8 P6.3: a link anyone can use to join as a member or a viewer, shown once.
            Action::make('inviteLink')->label(__('Invite link'))->icon(Heroicon::OutlinedLink)->color('gray')
                ->visible(fn (): bool => PlanLock::allows('team'))
                ->authorize(fn (): bool => $this->can('invite'))
                ->modalDescription(fn (): string => __('Anyone with the link can join as the role you pick, until it ends or you revoke it. Each person takes a seat when they join.')
                    .(($seats = $this->planLine()) !== null ? ' '.$seats : ''))
                ->schema([
                    Select::make('role')->label(__('Role'))->options(fn (): array => array_intersect_key($this->assignableRoles(), array_flip([WorkspaceRole::Member->value, WorkspaceRole::Viewer->value])))
                        ->default(WorkspaceRole::Member->value)->selectablePlaceholder(false)->required(),
                    Select::make('days')->label(__('Open for'))->options(self::dayOptions())->default('7')->selectablePlaceholder(false)->required(),
                    TextInput::make('max_uses')->label(__('People at most'))->numeric()->integer()->minValue(1)->maxValue(InviteLinks::MAX_USES)
                        ->placeholder(__('Any number')),
                ])
                ->modalSubmitActionLabel(__('Make the link'))
                ->action(function (array $data): void {
                    $role = WorkspaceRole::tryFrom((string) $data['role']);
                    if ($role === null || ! in_array($role, InviteLinks::ROLES, true) || ! $this->can('assignRole', $role)) {
                        Notification::make()->title(__('You cannot give that role.'))->danger()->send();

                        return;
                    }
                    try {
                        ['url' => $this->newInviteLink] = app(InviteLinks::class)->create($this->workspaceOrFail(), $this->user(), $role, (int) $data['days'],
                            filled($data['max_uses'] ?? null) ? (int) $data['max_uses'] : null);
                    } catch (ApiError $e) {
                        Notification::make()->title($e->getMessage())->url(NoticeTargets::forError($e))->danger()->send();

                        return;
                    }
                    $this->replaceMountedAction('inviteLinkShown');
                }),
            Action::make('rename')->label(__('Rename'))->icon(Heroicon::OutlinedPencilSquare)->color('gray')
                ->authorize(fn (): bool => $this->can('manage'))
                ->fillForm(fn (): array => ['name' => $this->workspace()?->name])
                ->schema([TextInput::make('name')->label(__('Name'))->required()->maxLength(120)])
                ->action(function (array $data): void {
                    $this->workspaceOrFail()->update(['name' => trim((string) $data['name'])]);
                    app(AuditLog::class)->record('workspace_renamed', $this->user()->id, $this->workspaceOrFail(), 'Workspace renamed to '.$this->workspaceOrFail()->name);
                    Notification::make()->title(__('Workspace renamed'))->success()->send();
                }),
            Action::make('transfer')->label(__('Hand over'))->icon(Heroicon::OutlinedKey)->color('gray')
                ->authorize(fn (): bool => $this->can('manage'))
                ->modalDescription(__('The person you pick becomes the owner; you stay as an admin. Only they can hand it back.'))
                ->schema([Select::make('user_id')->label(__('New owner'))->required()
                    ->options(fn (): array => $this->workspaceOrFail()->members()
                        ->wherePivotIn('role', [WorkspaceRole::Admin->value, WorkspaceRole::Member->value])
                        ->orderBy('name')->pluck('users.name', 'users.id')->all())])
                ->action(function (array $data): void {
                    $to = $this->workspaceOrFail()->members()->whereKey((int) $data['user_id'])->first();
                    if ($to instanceof User && $this->attempt(fn () => app(Workspaces::class)->transfer($this->workspaceOrFail(), $this->user(), $to), __(':name is now the owner', ['name' => $to->name]))) {
                        app(AuditLog::class)->record('workspace_transferred', $this->user()->id, $this->workspaceOrFail(), "Handed {$this->workspaceOrFail()->name} over to {$to->email}");
                    }
                }),
            Action::make('leave')->label(__('Leave'))->icon(Heroicon::OutlinedArrowRightStartOnRectangle)->color('danger')
                ->authorize(fn (): bool => $this->can('leave'))
                ->requiresConfirmation()->modalDescription(__('You lose access to this workspace and your devices here are signed out. Your links here stop working, and what was sent to you from here ends (copies you saved stay yours). What you recorded here stays yours, seen by nobody else.'))
                ->action(function (): void {
                    $workspace = $this->workspaceOrFail();
                    if ($this->attempt(fn () => app(Workspaces::class)->leave($workspace, $this->user()), __('You left :name', ['name' => $workspace->name]))) {
                        app(AuditLog::class)->record('member_left', $this->user()->id, $workspace, "Left {$workspace->name}");
                        $this->redirect(Dashboard::getUrl());
                    }
                }),
            Action::make('deleteWorkspace')->label(__('Delete workspace'))->icon(Heroicon::OutlinedTrash)->color('danger')
                ->authorize(fn (): bool => $this->can('manage'))
                ->requiresConfirmation()
                ->modalDescription(__('Everything in it is erased for good: recordings, transcripts, screens, projects and their files, request links, devices and its usage. Only possible once nobody else is in it.'))
                ->action(function (): void {
                    $workspace = $this->workspaceOrFail();
                    if ($this->attempt(fn () => app(WorkspaceEraser::class)->erase($workspace, $this->user()), __(':name deleted', ['name' => $workspace->name]))) {
                        $this->redirect(Dashboard::getUrl());
                    }
                }),
        ];
    }

    /** How long an invitation or an invite link stays open (since v1.8 P6.3). */
    public const DAY_OPTIONS = ['1' => '1 day', '7' => '7 days', '30' => '30 days'];

    /** @return array<int, string> DAY_OPTIONS in the console's language (PHP keeps its "1", "7" and "30" as integer keys) */
    private static function dayOptions(): array
    {
        return array_map(fn (string $days): string => __($days), self::DAY_OPTIONS);
    }

    /** An invite link just made, until its modal has read it (never kept beyond that). */
    public ?string $newInviteLink = null;

    /** The new invite link, shown once with a copy box. */
    public function inviteLinkShownAction(): Action
    {
        return Action::make('inviteLinkShown')
            ->modalHeading(__('Invite link ready'))
            ->modalDescription(__('Copy it now: it is shown only once. Send it to the people you want in the team.'))
            ->fillForm(function (): array {
                $url = $this->newInviteLink;
                $this->newInviteLink = null;

                return ['url' => $url];
            })
            ->schema([TextInput::make('url')->label(__('Invite link'))->readOnly()->copyable(copyMessage: __('Copied'))])
            ->modalSubmitAction(false)->modalCancelActionLabel(__('Done'))->modalWidth('lg');
    }

    /** Revokes an invite link: it stops working at once; people who joined with it stay. */
    public function revokeLink(int $id): void
    {
        if (! $this->can('invite')) {
            return;
        }
        $link = WorkspaceInviteLink::query()->where('workspace_id', CurrentWorkspace::id())->find($id);
        if ($link instanceof WorkspaceInviteLink) {
            app(InviteLinks::class)->revoke($link, $this->user());
            Notification::make()->title(__('Invite link revoked'))->success()->send();
        }
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
            Notification::make()->title(__('That invitation is no longer pending.'))->warning()->send();

            return;
        }
        if (! $this->can('assignRole', $invitation->role)) {
            Notification::make()->title(__('You cannot give that role.'))->danger()->send();

            return;
        }
        $key = 'add-member:'.$this->user()->id;
        if (RateLimiter::tooManyAttempts($key, self::ADDS_PER_HOUR)) {
            Notification::make()->title(__('Too many invitations sent for now'))
                ->body(__('Try again in :minutes minutes.', ['minutes' => ceil(RateLimiter::availableIn($key) / 60)]))->danger()->send();

            return;
        }
        RateLimiter::hit($key, 3600);
        $this->attempt(fn () => app(Invitations::class)->invite($this->workspaceOrFail(), $this->user(), $invitation->email, $invitation->role), __('Invitation sent again to :email', ['email' => $invitation->email]));
    }

    /** Cancels an invitation that was not used yet; its link stops working and its seat is free again. */
    public function revokeInvitation(int $id): void
    {
        if (! $this->can('invite')) {
            return;
        }
        $key = 'cancel-invitation:'.$this->user()->id;
        if (RateLimiter::tooManyAttempts($key, self::CANCELS_PER_HOUR)) {
            Notification::make()->title(__('Too many invitations cancelled for now'))
                ->body(__('Try again in :minutes minutes.', ['minutes' => ceil(RateLimiter::availableIn($key) / 60)]))->danger()->send();

            return;
        }
        RateLimiter::hit($key, 3600);
        $invitation = WorkspaceInvitation::query()->where('workspace_id', CurrentWorkspace::id())->whereNull('accepted_at')->find($id);
        if ($invitation instanceof WorkspaceInvitation) {
            $invitation->delete();
            app(AuditLog::class)->record('invitation_revoked', $this->user()->id, $this->workspaceOrFail(), "Took back the invitation of {$invitation->email}");
            Notification::make()->title(e(__('Invitation for :email cancelled', ['email' => $invitation->email])))->success()->send();
        }
    }

    /** @param  array{email: string, name?: string|null, role: string, days?: string|int}  $data */
    private function addMember(array $data): void
    {
        // Each one emails the address: 20 an hour, so a workspace cannot be used to send this server's emails to a
        // list of strangers.
        $key = 'add-member:'.$this->user()->id;
        if (RateLimiter::tooManyAttempts($key, self::ADDS_PER_HOUR)) {
            Notification::make()->title(__('Too many people added for now'))
                ->body(__('Try again in :minutes minutes.', ['minutes' => ceil(RateLimiter::availableIn($key) / 60)]))->danger()->send();

            return;
        }
        $role = WorkspaceRole::tryFrom($data['role']);
        if ($role === null || ! $this->can('assignRole', $role)) {
            Notification::make()->title(__('You cannot give that role.'))->danger()->send();

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
        $this->attempt(fn () => app(Invitations::class)->invite($this->workspaceOrFail(), $this->user(), $email, $role, (int) ($data['days'] ?? Invitations::DAYS)), __('Invitation sent to :email', ['email' => $email]));
    }

    private function addWithPassword(string $email, string $name, WorkspaceRole $role): void
    {
        $workspace = $this->workspaceOrFail();
        // Since the security review of 2.0.0-l: an address reserved for an account's restore window is nobody's.
        if (EmailChange::isReserved($email)) {
            Notification::make()->title(__('That address is not available. Use another one.'))->danger()->send();

            return;
        }
        try {
            app(PlanLimits::class)->assertFeature($workspace->id, 'team', $this->user()->id);
            app(PlanLimits::class)->assertSeat($workspace->id, null, $this->user()->id);
        } catch (ApiError $e) {
            Notification::make()->title($e->getMessage())->url(NoticeTargets::forError($e))->danger()->send();

            return;
        }
        $password = Str::password(20, symbols: false);
        $user = User::query()->create(['name' => $name ?: Str::before($email, '@'), 'email' => $email, 'password' => $password]);
        if (! $this->attempt(fn () => app(Workspaces::class)->addMember($workspace, $user, $role), __(':name added', ['name' => $user->name]))) {
            return;
        }
        app(Invitations::class)->tellAdded($workspace, $this->user(), $user);
        app(AuditLog::class)->record('member_added', $this->user()->id, $workspace, "Added {$email} to {$workspace->name}");
        Notification::make()->title(__('One-time password for :email', ['email' => e($email)]))
            ->body($password."\n".__('Share it privately; they can change it on their profile after signing in.'))
            ->persistent()->warning()->send();
    }

    private function attempt(callable $change, string $done): bool
    {
        try {
            $change();
        } catch (InvalidArgumentException|ApiError $e) {
            Notification::make()->title($e->getMessage())->url(NoticeTargets::forError($e))->danger()->send();

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
            ->mapWithKeys(fn (WorkspaceRole $r): array => [$r->value => __($r->label())])->all();
    }

    /** @return list<array{id: int, email: string, role: string, expires: string, sent: string|null}> */
    private function openInvitations(): array
    {
        return WorkspaceInvitation::query()->where('workspace_id', CurrentWorkspace::id())->whereNull('accepted_at')->where('expires_at', '>', now())
            ->orderBy('expires_at')->limit(100)->get()
            ->map(fn (WorkspaceInvitation $i): array => ['id' => $i->id, 'email' => $i->email, 'role' => __($i->role->label()), 'expires' => $i->expires_at->diffForHumans(),
                'sent' => $i->created_at?->diffForHumans()])
            ->all();
    }

    /** @return list<array<string, mixed>> the invite links still open */
    private function openLinks(): array
    {
        return WorkspaceInviteLink::query()->where('workspace_id', CurrentWorkspace::id())->whereNull('revoked_at')->where('expires_at', '>', now())
            ->with('creator')->orderBy('expires_at')->limit(100)->get()->filter(fn (WorkspaceInviteLink $l): bool => $l->usable())
            ->map(fn (WorkspaceInviteLink $l): array => ['id' => $l->id, 'role' => __($l->role->label()), 'by' => $l->creator->name ?? '—', 'creator' => $l->creator,
                'uses' => $l->max_uses === null ? __(':uses joined', ['uses' => $l->uses]) : __(':uses of :max joined', ['uses' => $l->uses, 'max' => $l->max_uses]),
                'expires' => $l->expires_at->diffForHumans()])
            ->values()->all();
    }

    /** The seats taken and the plan's (since v1.8 P6.3 for everyone who invites, not the owner alone). */
    private function planLine(): ?string
    {
        if (! $this->can('invite')) {
            return null;
        }
        $seats = app(PlanLimits::class)->summary((int) CurrentWorkspace::id())['seats'];

        return $seats['limit'] === null ? null : __(':used of :limit seats taken (open invitations count).', ['used' => $seats['used'], 'limit' => $seats['limit']]);
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
