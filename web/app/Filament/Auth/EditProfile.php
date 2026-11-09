<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use App\Filament\Auth\Settings\ManagesAccountPages;
use App\Filament\Auth\Settings\ManagesAppPages;
use App\Filament\Auth\Settings\ManagesPlanPage;
use App\Filament\Auth\Settings\ManagesSecurityPages;
use App\Filament\Concerns\WatchesPairing;
use App\Filament\Pages\WorkspaceSettings;
use App\Filament\Support\Notice as Notification;
use App\Filament\Support\SettingsContext;
use App\Models\User;
use App\Services\Accounts\SettingsIndex;
use App\Services\Accounts\SettingsRegistry;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Livewire\Attributes\Locked;

/**
 * Settings (the account page since v1.5, Settings since v1.7) at /app/settings/{page}: on the left the search and the
 * sections (Transcription, App, Account, Support, then Sign out), on the right the page that is open. With nothing
 * open, the Overview: who you are (the profile card), what needs you (one state row) and this month's use. Narrower
 * than 760 px it is a list and then the page, as on the phone. Every row comes from SettingsRegistry, so search finds
 * each one and opens the page on it (?highlight=). The route keeps its name (filament.app.auth.profile); /app/profile
 * and /app/security redirect here.
 */
class EditProfile extends BaseEditProfile
{
    use ManagesAccountPages;
    use ManagesAppPages;
    use ManagesPlanPage;
    use ManagesSecurityPages;
    use WatchesPairing;

    protected static ?string $slug = 'settings/{subpage?}';

    protected string $view = 'filament.settings.page';

    /** The open page ('' = the Overview). */
    #[Locked]
    public string $subpage = '';

    /** What the Settings search box holds. */
    public string $settingsQuery = '';

    /** The address of a Settings page, scrolled to [$highlight] (a registry id). */
    public static function settingsUrl(?string $page = null, ?string $highlight = null): string
    {
        $url = route('filament.app.auth.profile', array_filter(['subpage' => $page]));

        return $highlight !== null ? $url.'?highlight='.rawurlencode($highlight) : $url;
    }

    public function mount(?string $subpage = null): void
    {
        $subpage = (string) $subpage;
        if ($subpage !== '') {
            $context = SettingsContext::current();
            $root = SettingsRegistry::byId()[SettingsRegistry::PAGES[$subpage] ?? ''] ?? null;
            abort_if($root === null || $context === null || ! $context->available($root), 404);
            if ($subpage === 'workspace') {
                $this->redirect(WorkspaceSettings::getUrl(panel: 'app'));

                return;
            }
        }
        $this->subpage = $subpage;
        parent::mount();
        $status = session()->pull('google.status');
        if (is_string($status)) {
            Notification::make()->title($status)->info()->send();
        }
    }

    public function getTitle(): string
    {
        return $this->subpage === '' ? 'Settings' : SettingsRegistry::pageTitle($this->subpage);
    }

    /** The page draws its own title in the content column. */
    public function getHeading(): string
    {
        return '';
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components($this->subpage === '' ? [] : $this->pageComponents());
    }

    /** @return list<Component> */
    private function pageComponents(): array
    {
        if ($this->subpage === 'profile') {
            return $this->profileComponents();
        }

        return [View::make('filament.settings.pages.'.$this->subpage)->viewData(fn (): array => $this->pageData())];
    }

    /** @return array<string, mixed> what the open page's view shows */
    public function pageData(): array
    {
        return match ($this->subpage) {
            'plan' => $this->planData(),
            'security' => $this->securityData(),
            'devices' => $this->devicesData(),
            'privacy' => $this->privacyData(),
            'notifications' => $this->notificationsData(),
            'appearance', 'language' => $this->appearanceData(),
            'storage' => $this->storageData(),
            'transcription-key' => $this->keyData(),
            'help' => $this->helpData(),
            default => [],
        };
    }

    /**
     * The left list: each root section with the pages this person has, each with its value, icon and (while
     * searching) how many results it holds.
     *
     * @return list<array{title: string, rows: list<array{id: string, page: string, title: string, icon: string, value: string|null, url: string, count: int, current: bool}>}>
     */
    public function settingsSections(): array
    {
        $context = $this->context();
        $counts = [];
        foreach ($this->searchResults()['settings'] as $hit) {
            $page = $hit['entry']['page'] ?? null;
            if (is_string($page)) {
                $counts[$page] = ($counts[$page] ?? 0) + 1;
            }
        }
        $searching = $this->isSearching();
        $sections = [];
        foreach (SettingsRegistry::SECTIONS as $title => $pages) {
            $rows = [];
            foreach ($pages as $page) {
                $entry = SettingsRegistry::byId()[SettingsRegistry::PAGES[$page]];
                if (! $context->available($entry) || ($searching && ! isset($counts[$page]))) {
                    continue;
                }
                $rows[] = ['id' => (string) $entry['id'], 'page' => $page, 'title' => (string) $entry['title'], 'icon' => self::ICONS[$page],
                    'value' => $context->value($entry), 'url' => $context->url($entry), 'count' => $counts[$page] ?? 0, 'current' => $this->subpage === $page];
            }
            if ($rows !== []) {
                $sections[] = ['title' => $title, 'rows' => $rows];
            }
        }

        return $sections;
    }

    /** Heroicons (outline) of the root rows, one family with the console's chrome. */
    public const ICONS = [
        'language' => 'heroicon-o-language', 'transcription-key' => 'heroicon-o-key', 'appearance' => 'heroicon-o-swatch', 'notifications' => 'heroicon-o-bell',
        'storage' => 'heroicon-o-archive-box', 'shortcuts' => 'heroicon-o-command-line', 'profile' => 'heroicon-o-user', 'plan' => 'heroicon-o-credit-card',
        'security' => 'heroicon-o-shield-check', 'devices' => 'heroicon-o-device-phone-mobile', 'workspace' => 'heroicon-o-user-group',
        'privacy' => 'heroicon-o-circle-stack', 'help' => 'heroicon-o-question-mark-circle',
    ];

    public function isSearching(): bool
    {
        return mb_strlen(SettingsIndex::normalize($this->settingsQuery)) >= 2;
    }

    /**
     * Settings search for what the box holds: rows with their breadcrumb, value and address.
     *
     * @return array{settings: list<array{entry: array<string, mixed>, score: int}>, help: list<array{entry: array<string, mixed>, score: int}>, closest: list<array<string, mixed>>}
     */
    public function searchResults(): array
    {
        if (! $this->isSearching()) {
            return ['settings' => [], 'help' => [], 'closest' => []];
        }
        $key = 'el.settings.results.'.md5($this->settingsQuery);
        $cached = request()->attributes->get($key);
        if (is_array($cached)) {
            /** @var array{settings: list<array{entry: array<string, mixed>, score: int}>, help: list<array{entry: array<string, mixed>, score: int}>, closest: list<array<string, mixed>>} $cached */
            return $cached;
        }
        $results = $this->context()->index()->search($this->settingsQuery);
        request()->attributes->set($key, $results);

        return $results;
    }

    /**
     * The root icon a result shows: the icon of the page it lives on.
     *
     * @param  array<string, mixed>  $entry
     */
    public function resultIcon(array $entry): string
    {
        return $entry['kind'] === 'help' ? 'heroicon-o-question-mark-circle' : (self::ICONS[(string) ($entry['page'] ?? '')] ?? 'heroicon-o-cog-6-tooth');
    }

    public function clearSettingsSearch(): void
    {
        $this->settingsQuery = '';
    }

    /** Sign out (the root's last row): "Sign out?" then the sign-in page. */
    public function signOutAction(): Action
    {
        return Action::make('signOut')->label('Sign out')
            ->requiresConfirmation()->modalHeading('Sign out?')->modalDescription(null)->modalIcon(null)
            ->modalSubmitActionLabel('Sign out')->color('danger')
            ->action(function (): void {
                Filament::auth()->logout();
                session()->invalidate();
                session()->regenerateToken();
                $this->redirect(Filament::getPanel('app')->getLoginUrl() ?? '/app/login');
            });
    }

    public function context(): SettingsContext
    {
        $context = SettingsContext::current();
        abort_if($context === null, 403);

        return $context;
    }

    private function account(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
