<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspaces\CurrentWorkspace;
use App\Services\Workspaces\PlanLimits;
use App\Services\Workspaces\Workspaces;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard;
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
use Illuminate\Support\Str;
use InvalidArgumentException;
use UnitEnum;

/**
 * The current workspace (B20): its members and their roles, switching between workspaces, and for the server admin
 * an overview of every workspace (names and totals only, never content).
 */
class WorkspaceSettings extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Workspace';

    protected static ?string $slug = 'workspace';

    public function getSubheading(): ?string
    {
        $workspace = $this->workspace();

        return $workspace === null ? null : $workspace->name.' · '.($this->isOwner() ? 'You are an owner' : 'You are a member');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
            View::make('filament.pages.workspaces-overview')
                ->visible(fn (): bool => (bool) $this->user()->is_super_admin)
                ->viewData(fn (): array => ['rows' => $this->overview()]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => User::query()->whereIn('id', fn ($q) => $q->select('user_id')->from('workspace_user')->where('workspace_id', CurrentWorkspace::id())))
            ->heading('Members')
            ->description('Everyone here sees every recording of the workspace. Owners also delete anyone\'s recordings, revoke any device and manage members.')
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('email'),
                TextColumn::make('role')->badge()
                    ->state(fn (User $record): string => app(Workspaces::class)->roleOf($record, (int) CurrentWorkspace::id())?->label() ?? '')
                    ->color(fn (string $state): string => $state === WorkspaceRole::Owner->label() ? 'primary' : 'gray'),
            ])
            ->recordActions([
                Action::make('changeRole')->label(fn (User $record): string => $this->roleOf($record) === WorkspaceRole::Owner ? 'Make member' : 'Make owner')
                    ->icon(Heroicon::OutlinedArrowsRightLeft)->color('gray')
                    ->authorize(fn (): bool => $this->isOwner())
                    ->action(function (User $record): void {
                        $to = $this->roleOf($record) === WorkspaceRole::Owner ? WorkspaceRole::Member : WorkspaceRole::Owner;
                        $this->attempt(fn () => app(Workspaces::class)->setRole($this->workspaceOrFail(), $record, $to), "{$record->name} is now ".Str::lower($to->label()));
                    }),
                Action::make('remove')->label('Remove')->icon(Heroicon::OutlinedUserMinus)->color('danger')
                    ->authorize(fn (): bool => $this->isOwner())
                    ->requiresConfirmation()->modalDescription('They lose access to this workspace at once and their devices here are unpaired. Their recordings stay.')
                    ->action(fn (User $record) => $this->attempt(fn () => app(Workspaces::class)->removeMember($this->workspaceOrFail(), $record), "{$record->name} removed")),
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
            Action::make('rename')->label('Rename')->icon(Heroicon::OutlinedPencilSquare)->color('gray')
                ->authorize(fn (): bool => $this->isOwner())
                ->fillForm(fn (): array => ['name' => $this->workspace()?->name])
                ->schema([TextInput::make('name')->required()->maxLength(120)])
                ->action(function (array $data): void {
                    $this->workspaceOrFail()->update(['name' => trim((string) $data['name'])]);
                    Notification::make()->title('Workspace renamed')->success()->send();
                }),
            Action::make('addMember')->label('Add member')->icon(Heroicon::OutlinedUserPlus)
                ->authorize(fn (): bool => $this->isOwner())
                ->modalDescription('Someone without an account gets one with a one-time password, shown to you once.')
                ->schema([
                    TextInput::make('email')->email()->required()->maxLength(255),
                    TextInput::make('name')->maxLength(255)->helperText('For a new account.'),
                    Select::make('role')->options([WorkspaceRole::Member->value => 'Member', WorkspaceRole::Owner->value => 'Owner'])
                        ->default(WorkspaceRole::Member->value)->selectablePlaceholder(false)->required(),
                ])
                ->action(fn (array $data) => $this->addMember($data)),
        ];
    }

    /** @param  array{email: string, name?: string|null, role: string}  $data */
    private function addMember(array $data): void
    {
        $email = Str::lower(trim($data['email']));
        $user = User::query()->where('email', $email)->first();
        $password = null;
        if (! $user instanceof User) {
            $password = Str::password(20, symbols: false);
            $user = User::query()->create(['name' => trim((string) ($data['name'] ?? '')) ?: Str::before($email, '@'), 'email' => $email, 'password' => $password]);
        }
        $added = $this->attempt(fn () => app(Workspaces::class)->addMember($this->workspaceOrFail(), $user, WorkspaceRole::from($data['role'])), "{$user->name} added");
        if ($added && $password !== null) {
            Notification::make()->title('One-time password for '.$email)
                ->body("{$password}\nShare it privately; they can change it on their profile after signing in.")
                ->persistent()->warning()->send();
        }
    }

    private function attempt(callable $change, string $done): bool
    {
        try {
            $change();
        } catch (InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return false;
        }
        Notification::make()->title($done)->success()->send();

        return true;
    }

    /** @return list<array{name: string, plan: string, members: int, devices: int, minutes: float}> */
    private function overview(): array
    {
        $limits = app(PlanLimits::class);

        return Workspace::query()->with('plan')->withCount('members')->orderBy('name')->limit(200)->get()
            ->map(fn (Workspace $w): array => [
                'name' => $w->name, 'plan' => $w->plan->name, 'members' => (int) $w->getAttribute('members_count'),
                'devices' => $limits->devicesUsed($w->id), 'minutes' => $limits->minutesUsed($w->id),
            ])->all();
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

    private function isOwner(): bool
    {
        return app(Workspaces::class)->isOwner($this->user(), (int) CurrentWorkspace::id());
    }
}
