<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Support\Notice as Notification;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AuditLog;
use App\Services\InterfaceFlags;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Admin panel → Interface flags (since 2.0.0-a): the super admin turns each redesigned screen and Live Assist on for
 * everyone, for a share of accounts (the same accounts every time), for workspaces or for people. Off by default.
 *
 * @property-read Schema $form
 */
class InterfaceFlagsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Flag;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 9;

    protected static ?string $title = 'Interface flags';

    protected static ?string $slug = 'interface-flags';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->is_super_admin;
    }

    public function mount(InterfaceFlags $flags): void
    {
        $rules = $flags->rules();
        $state = [];
        foreach (InterfaceFlags::names() as $name) {
            $rule = $rules[$name] ?? null;
            $state[self::key($name)] = [
                'everyone' => $rule !== null && $rule->everyone,
                'percent' => $rule !== null ? $rule->percent : 0,
                'workspaces' => array_map('intval', $rule !== null ? ($rule->workspace_ids ?? []) : []),
                'people' => array_map('intval', $rule !== null ? ($rule->user_ids ?? []) : []),
            ];
        }
        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        $sections = [];
        foreach (InterfaceFlags::names() as $name) {
            $key = self::key($name);
            $sections[] = Section::make(InterfaceFlags::LABELS[$name])->description($name)->statePath($key)->columns(2)->schema([
                Toggle::make('everyone')->label('On for everyone'),
                TextInput::make('percent')->label('Share of accounts')->suffix('%')->integer()->minValue(0)->maxValue(100)->required()
                    ->helperText('The same accounts every time.'),
                Select::make('workspaces')->label('Workspaces')->multiple()->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => Workspace::query()->where('name', 'like', '%'.$search.'%')->orderBy('name')->limit(20)->pluck('name', 'id')->all())
                    ->getOptionLabelsUsing(fn (array $values): array => Workspace::query()->whereIn('id', $values)->pluck('name', 'id')->all()),
                Select::make('people')->label('People')->multiple()->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => User::query()->where(fn ($q) => $q->where('email', 'like', '%'.$search.'%')->orWhere('name', 'like', '%'.$search.'%'))
                        ->orderBy('email')->limit(20)->pluck('email', 'id')->all())
                    ->getOptionLabelsUsing(fn (array $values): array => User::query()->whereIn('id', $values)->pluck('email', 'id')->all()),
            ]);
        }

        return $schema->statePath('data')->components($sections);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('save')
                ->footer([Actions::make([Action::make('save')->label('Save')->submit('save')])]),
        ]);
    }

    public function save(InterfaceFlags $flags): void
    {
        abort_unless(self::canAccess(), 403);
        $data = $this->form->getState();
        foreach (InterfaceFlags::names() as $name) {
            $row = (array) ($data[self::key($name)] ?? []);
            $flags->set($name, (bool) ($row['everyone'] ?? false), (int) ($row['percent'] ?? 0), (array) ($row['workspaces'] ?? []), (array) ($row['people'] ?? []));
        }
        app(AuditLog::class)->record('settings_changed', (int) auth()->id(), null, 'Interface flags changed');
        Notification::make()->title('Saved')->success()->send();
    }

    /** A flag's name as a form key ("ui_v2.home" has a dot, which a state path would read as nesting). */
    private static function key(string $flag): string
    {
        return str_replace('.', '__', $flag);
    }
}
