<?php

declare(strict_types=1);

namespace App\Filament\Pages\LiveAssist;

use App\Filament\Concerns\LiveAssistOnly;
use App\Filament\Support\Notice as Notification;
use App\Models\AssistPrep;
use App\Models\User;
use App\Services\Assist\AssistDefaults;
use App\Services\Assist\AssistRecords;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Live Assist's settings (v1.9 L8): for each meeting type the mode, the hint level and the language help a new prep
 * starts with (App\Services\Assist\AssistDefaults), here and in the apps. The shortcuts are the desktop app's own
 * (Settings → Shortcuts there), as its other shortcuts are. The small read-only console reads them and changes nothing.
 *
 * @property-read Schema $form
 */
class AssistSettings extends Page
{
    use LiveAssistOnly;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::AdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Live Assist';

    protected static ?string $navigationLabel = 'Settings';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Live Assist settings';

    protected static ?string $slug = 'live-assist/settings';

    /** @var array<string, mixed>|null meeting type => mode, hint level, language help */
    public ?array $data = [];

    /** In the console's language (the new frame in Urdu); English reads as before. */
    public function getTitle(): string
    {
        return __('Live Assist settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('Settings');
    }

    public function getSubheading(): string
    {
        return __('What a new prep starts with, for each kind of meeting. You can change it on each prep.');
    }

    public function mount(): void
    {
        $this->form->fill(AssistDefaults::all(self::user()));
    }

    public function form(Schema $schema): Schema
    {
        $sections = [];
        foreach (AssistPrep::MEETING_TYPES as $type) {
            $sections[] = Section::make(__(AssistRecords::MEETING_TYPES[$type]))->compact()->statePath($type)->schema([
                Grid::make(['default' => 1, 'sm' => 3])->schema([
                    Select::make('mode')->label(__('Mode'))->options(self::words(AssistRecords::MODES))->required()->selectablePlaceholder(false)
                        ->helperText(__('Your plan decides which modes start.')),
                    Select::make('hint_level')->label(__('Hints'))->options(self::words(AssistRecords::HINT_LEVELS))->required()->selectablePlaceholder(false),
                    Select::make('language_help')->label(__('Language help'))->options(self::words(AssistRecords::LANGUAGE_HELP))->required()->selectablePlaceholder(false),
                ]),
            ]);
        }

        return $schema->components([
            ...$sections,
            Text::make(__("Live Assist's shortcuts (help me now, shorter, hide the card and the rest) are set in the desktop app, under Settings → Shortcuts.")),
        ])->statePath('data')->disabled(fn (): bool => ! self::canChange());
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('save')
                ->footer([Actions::make([Action::make('save')->label(__('Save'))->submit('save')->visible(fn (): bool => self::canChange())])]),
        ]);
    }

    public function save(): void
    {
        $user = self::user();
        if ($user === null || ! self::canChange()) {
            return;
        }
        AssistDefaults::save($user, $this->form->getState());
        Notification::make()->title(__('Live Assist settings saved'))->success()->send();
    }

    /**
     * A list's labels in the console's language; its keys stay as they are.
     *
     * @param  array<string, string>  $labels
     * @return array<string, string>
     */
    private static function words(array $labels): array
    {
        return array_map(fn (string $label): string => __($label), $labels);
    }

    private static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
