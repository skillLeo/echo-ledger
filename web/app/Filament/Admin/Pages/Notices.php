<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Services\AuditLog;
use App\Services\SettingsRepository;
use App\Services\Staff\StaffAccess;
use App\Services\System\PlatformNotices;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * Admin panel → Announcement and maintenance (since v1.6, P2.7): one banner for everyone in the app (and in the apps'
 * GET /api/config `announcement`) until a time, and maintenance: the app shows a pause page and the API answers
 * `503 maintenance` to everyone but staff, with the message and when it should end.
 *
 * @property-read Schema $form
 */
class Notices extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Megaphone;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 8;

    protected static ?string $title = 'Announcement and maintenance';

    protected static ?string $navigationLabel = 'Announcement';

    protected static ?string $slug = 'announcement';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return StaffAccess::can('settings.manage');
    }

    public function mount(SettingsRepository $settings): void
    {
        $announcement = $settings->get(PlatformNotices::ANNOUNCEMENT);
        $maintenance = $settings->get(PlatformNotices::MAINTENANCE);
        $this->form->fill([
            'text' => is_array($announcement) ? ($announcement['text'] ?? null) : null,
            'level' => is_array($announcement) ? ($announcement['level'] ?? 'info') : 'info',
            'until' => is_array($announcement) ? ($announcement['until'] ?? null) : null,
            'maintenance_on' => is_array($maintenance) && ($maintenance['on'] ?? false) === true,
            'maintenance_message' => is_array($maintenance) ? ($maintenance['message'] ?? null) : null,
            'maintenance_until' => is_array($maintenance) ? ($maintenance['until'] ?? null) : null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Announcement')->description('A line at the top of the app for everyone, and in the phone and computer apps. Empty: none.')->schema([
                Textarea::make('text')->label('Text')->rows(2)->maxLength(300),
                Select::make('level')->label('Kind')->options(PlatformNotices::LEVELS)->selectablePlaceholder(false),
                DateTimePicker::make('until')->label('Until (optional)')->seconds(false),
            ]),
            Section::make('Maintenance')->description('While it is on, customers see a pause page in the app and the apps wait; staff keep working. Webhooks and share links keep working.')->schema([
                Toggle::make('maintenance_on')->label('Maintenance is on'),
                Textarea::make('maintenance_message')->label('Message')->rows(2)->maxLength(300)
                    ->placeholder('EchoLedger is being updated. Please try again in a few minutes; your recordings are safe.'),
                DateTimePicker::make('maintenance_until')->label('Expected back (optional)')->seconds(false),
            ]),
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
        $text = trim((string) ($data['text'] ?? ''));
        $time = fn (mixed $v): ?string => filled($v) ? Carbon::parse((string) $v)->toAtomString() : null;
        $on = (bool) ($data['maintenance_on'] ?? false);
        $was = app(PlatformNotices::class)->maintenance() !== null;
        $settings->setMany([
            PlatformNotices::ANNOUNCEMENT => $text === '' ? null : ['text' => $text, 'level' => isset(PlatformNotices::LEVELS[$data['level'] ?? '']) ? $data['level'] : 'info', 'until' => $time($data['until'] ?? null)],
            PlatformNotices::MAINTENANCE => ['on' => $on, 'message' => filled($data['maintenance_message'] ?? null) ? trim((string) $data['maintenance_message']) : null, 'until' => $time($data['maintenance_until'] ?? null)],
        ]);
        app(AuditLog::class)->record('settings_changed', (int) auth()->id(), null, $on !== $was ? ($on ? 'Maintenance turned on' : 'Maintenance turned off') : 'Announcement changed');
        Notification::make()->title($on ? 'Saved. Maintenance is on: customers wait.' : 'Saved')->{$on ? 'warning' : 'success'}()->send();
    }
}
