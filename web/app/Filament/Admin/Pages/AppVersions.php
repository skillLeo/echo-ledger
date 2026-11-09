<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Support\Notice as Notification;
use App\Models\Device;
use App\Services\AuditLog;
use App\Services\SettingsRepository;
use App\Services\Staff\StaffAccess;
use App\Services\System\PlatformNotices;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
 * Admin panel → App versions (since v1.6, P2.7): the lowest version of each app. An older app reads "Please update"
 * (GET /api/config `app_update`); with "Refuse older apps" its calls are refused with `426 update_required` until it
 * is updated (only GET /api/config still answers, so it can say why).
 *
 * @property-read Schema $form
 */
class AppVersions extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::ArrowPathRoundedSquare;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 7;

    protected static ?string $title = 'App versions';

    protected static ?string $slug = 'app-versions';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return StaffAccess::can('settings.manage');
    }

    public function mount(PlatformNotices $notices, SettingsRepository $settings): void
    {
        $this->form->fill([
            ...collect($notices->minimums())->mapWithKeys(fn (?string $v, string $p): array => ["min_{$p}" => $v])->all(),
            'message' => $settings->get(PlatformNotices::UPDATE_MESSAGE),
            'refuse_older' => $notices->refusesOlder(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Lowest version')->description('Empty: any version. An older app shows "Please update" with a link to the downloads page.')->schema([
                Grid::make(['default' => 1, 'sm' => 3])->schema(array_map(
                    fn (string $platform, string $label): TextInput => TextInput::make("min_{$platform}")->label($label)->placeholder('1.6.0')->maxLength(20)
                        ->regex('/^\d{1,4}(\.\d{1,4}){0,3}$/')->helperText(fn (): string => $this->inUse($platform)),
                    array_keys(PlatformNotices::PLATFORMS), PlatformNotices::PLATFORMS,
                )),
                TextInput::make('message')->label('Message (optional)')->maxLength(300)->placeholder('Please update EchoLedger to 1.6.0 or later.'),
                Toggle::make('refuse_older')->label('Refuse older apps')
                    ->helperText('Their calls are refused until they update. Leave it off to only ask them to update.'),
            ]),
            Text::make('Builds are published with php artisan echoledger:publish-release; the downloads page lists the newest of each.'),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('save')
                ->footer([Actions::make([Action::make('save')->label('Save')->submit('save')])]),
        ]);
    }

    public function save(SettingsRepository $settings): void
    {
        abort_unless(StaffAccess::can('settings.manage'), 403);
        $data = $this->form->getState();
        $minimums = [];
        foreach (array_keys(PlatformNotices::PLATFORMS) as $platform) {
            $value = trim((string) ($data["min_{$platform}"] ?? ''));
            $minimums[$platform] = $value !== '' && PlatformNotices::isVersion($value) ? $value : null;
        }
        $message = trim((string) ($data['message'] ?? ''));
        $settings->setMany([
            PlatformNotices::VERSIONS => $minimums,
            PlatformNotices::UPDATE_MESSAGE => $message !== '' ? $message : null,
            PlatformNotices::REFUSE_OLDER => (bool) ($data['refuse_older'] ?? false),
        ]);
        app(AuditLog::class)->record('settings_changed', (int) auth()->id(), null, 'App versions changed');
        Notification::make()->title('Saved')->success()->send();
    }

    /** "3 signed in, the oldest 1.5.2". */
    private function inUse(string $platform): string
    {
        $versions = Device::query()->withoutGlobalScopes()->counting()->where('platform', $platform)->whereNotNull('app_version')->pluck('app_version')->all();
        if ($versions === []) {
            return 'None signed in.';
        }
        usort($versions, 'version_compare');

        return count($versions).' signed in, the oldest '.$versions[0].'.';
    }
}
