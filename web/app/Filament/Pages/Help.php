<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Exceptions\ApiError;
use App\Filament\Support\Notice as Notification;
use App\Filament\Support\NoticeTargets;
use App\Http\Controllers\Api\HelpController;
use App\Models\User;
use App\Services\Help\Guides;
use App\Services\Help\SupportMessages;
use App\Services\Workspaces\ConsoleAccess;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;

/**
 * Help (v1.8 P6.4): the user guide searched by its words, one section open at a time; What's new in the apps (the
 * releases' notes); and Contact support (an email to the server's support address; the devices' latest reports go
 * with it only when ticked). For everyone who can open the console, the small one too.
 */
class Help extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::QuestionMarkCircle;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Help';

    protected static ?string $slug = 'help';

    protected string $view = 'filament.pages.help';

    #[Url(as: 'q')]
    public string $q = '';

    #[Url(as: 'guide')]
    public ?string $guide = null;

    public static function canAccess(): bool
    {
        return auth()->user() instanceof User && ConsoleAccess::open();
    }

    public function getSubheading(): string
    {
        return 'Search the guide, see what is new, or write to support.';
    }

    public function mount(): void
    {
        // "Contact support" from the ? panel opens the form at once.
        if (request()->query('contact') === '1') {
            $this->mountAction('contactSupport');
        }
    }

    public function open(string $id): void
    {
        $this->guide = app(Guides::class)->find($id) !== null ? $id : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('contactSupport')->label('Contact support')->icon(Heroicon::OutlinedEnvelope)
                ->modalDescription(fn (): string => 'Your message goes to '.app(SupportMessages::class)->address().'; the reply comes to your email.')
                ->schema([
                    TextInput::make('subject')->label('Subject')->required()->maxLength(120),
                    Textarea::make('message')->label('Message')->required()->rows(6)->maxLength(5000),
                    Checkbox::make('include_device_report')->label('Attach my devices\' latest reports')
                        ->helperText('Their app versions and the checks of their setup (recording, captions, permissions). No recordings, no words.'),
                ])
                ->modalSubmitActionLabel('Send')
                ->action(function (array $data): void {
                    /** @var User $user */
                    $user = auth()->user();
                    // One count per person with the apps' (SupportMessages); a confirmed email first.
                    try {
                        app(SupportMessages::class)->send($user, (string) $data['subject'], (string) $data['message'], (bool) ($data['include_device_report'] ?? false));
                    } catch (ApiError $e) {
                        Notification::make()->title($e->getMessage())->url(NoticeTargets::forError($e))->danger()->send();

                        throw new Halt;
                    }
                    Notification::make()->title('Message sent')->body('The reply comes to '.$user->email.'.')->success()->send();
                }),
        ];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $guides = app(Guides::class);
        $open = $this->guide !== null ? $guides->find($this->guide) : null;

        return [
            'results' => $guides->search($this->q),
            'open' => $open,
            'html' => $open !== null ? Str::markdown($open['markdown'], ['html_input' => 'escape', 'allow_unsafe_links' => false]) : null,
            'news' => HelpController::whatsNewFor(null, 8),
        ];
    }
}
