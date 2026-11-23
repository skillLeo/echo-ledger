<?php

declare(strict_types=1);

namespace App\Filament\Auth\Settings;

use App\Exceptions\ApiError;
use App\Filament\Pages\GetTheApps;
use App\Filament\Pages\RecentlyDeleted;
use App\Filament\Support\ConsoleV2;
use App\Filament\Support\ConsoleV2Nav;
use App\Filament\Support\Notice as Notification;
use App\Filament\Support\SettingsContext;
use App\Filament\Support\Shell;
use App\Models\CaptureSession;
use App\Services\Accounts\EmailPreferences;
use App\Services\Accounts\SettingsRegistry;
use App\Services\Entitlements\TranscriptionKeys;
use App\Services\InterfaceFlags;
use App\Services\Workspaces\PlanLimits;
use App\Stt\LanguageChoice;
use App\Support\Formats;
use Filament\Actions\Action;

/**
 * Settings › Language, Transcription key, Appearance, Notifications, Storage, Keyboard shortcuts and Help.
 */
trait ManagesAppPages
{
    /** What the Groq key box holds (Transcription key). */
    public string $groqKey = '';

    /** Why the key could not be saved, under the box. */
    public ?string $groqKeyError = null;

    /** @return array<string, mixed> Appearance and Language */
    public function appearanceData(): array
    {
        $prefs = Shell::preferences($this->account());
        // Since the parity work the new frame offers the sidebar's style too; it starts on Soft grey, its own look.
        if (ConsoleV2::shell()) {
            $prefs['style'] = ConsoleV2Nav::style($this->account());
        }

        return [
            'prefs' => $prefs,
            'rows' => [
                'style' => ['appearance.sidebar', ConsoleV2::shell() ? array_map(fn (string $label): string => __($label), Shell::STYLES) : Shell::STYLES],
                'density' => ['appearance.density', Shell::DENSITIES],
                'clock' => ['appearance.clock', ['12h' => '12-hour', '24h' => '24-hour']],
                'layout' => ['appearance.library_view', Shell::LAYOUTS],
            ],
            'languages' => ['' => 'Automatic', 'en' => 'English', 'ur' => 'Urdu', 'ar' => 'Arabic'],
            // Since 2.0.0-g: the design switch both ways (classic: Try the new design; v2: Switch back to classic).
            'design' => app(InterfaceFlags::class)->design($this->account()),
            'more' => LanguageChoice::MORE,
            // Since v2.0: the console's language, under the new frame only.
            'appLanguages' => ConsoleV2::shell() ? ConsoleV2::LANGUAGES : null,
        ];
    }

    /** One preference, saved at once. */
    public function setPreference(string $key, string $value): bool
    {
        // Since v2.0 the console's language too, under the new frame only.
        $keys = ['style', 'density', 'clock', 'layout', 'language', ...(ConsoleV2::shell() ? ['app_language'] : [])];
        if (! in_array($key, $keys, true) || ! Shell::remember($this->account(), $key, $value)) {
            return false;
        }
        $this->account()->refresh();
        SettingsContext::forget();
        if ($key === 'style') {
            $this->js('document.getElementById("fi-main-sidebar")?.setAttribute("data-style", '.json_encode($value).')');
        }
        if ($key === 'density') {
            $this->js('document.documentElement.dataset.density = '.json_encode($value));
        }
        if ($key === 'app_language') {
            // Every word and the direction change: the page is drawn again in the new language.
            $this->js('setTimeout(() => window.location.reload(), 400)');
        }

        return true;
    }

    /** "Reset to defaults" at the end of a page: only the settings on that page that the registry marks resettable. */
    public function resetPageAction(): Action
    {
        return Action::make('resetPage')->label('Reset to defaults')->color('gray')->link()
            ->requiresConfirmation()->modalIcon(null)
            ->modalHeading(fn (): string => 'Reset '.SettingsRegistry::pageTitle($this->subpage).' to defaults?')
            ->modalDescription(null)->modalSubmitActionLabel('Reset')
            ->action(function (): void {
                $user = $this->account();
                $columns = ['appearance.sidebar' => 'style', 'appearance.density' => 'density', 'appearance.clock' => 'clock', 'appearance.library_view' => 'layout', 'transcription.language' => 'language'];
                foreach (SettingsRegistry::entries() as $entry) {
                    if (($entry['page'] ?? null) !== $this->subpage || ($entry['resettable'] ?? false) !== true) {
                        continue;
                    }
                    $id = (string) $entry['id'];
                    if ($id === 'appearance.sidebar' && ConsoleV2::shell()) {
                        // The new frame's own default is Soft grey: the choice is forgotten, so each design shows its default.
                        $preferences = is_array($user->console_preferences) ? $user->console_preferences : [];
                        unset($preferences['shell']['style']);
                        $user->forceFill(['console_preferences' => $preferences])->save();
                    } elseif (isset($columns[$id])) {
                        Shell::remember($user, $columns[$id], $entry['default']);
                    } elseif (str_starts_with($id, 'notifications.email.')) {
                        EmailPreferences::set($user, substr($id, strlen('notifications.email.')), (bool) $entry['default']);
                    } elseif ($id === 'appearance.theme') {
                        $this->js("window.dispatchEvent(new CustomEvent('theme-changed', { detail: 'system' }))");
                    }
                }
                $user->refresh();
                SettingsContext::forget();
                $prefs = Shell::preferences($user);
                if (ConsoleV2::shell()) {
                    $prefs['style'] = ConsoleV2Nav::style($user);
                }
                $this->js('document.getElementById("fi-main-sidebar")?.setAttribute("data-style", '.json_encode($prefs['style']).'); document.documentElement.dataset.density = '.json_encode($prefs['density']));
                Notification::make()->title(SettingsRegistry::pageTitle($this->subpage).' reset to defaults')->success()->send();
            });
    }

    /** @return array<string, mixed> Notifications: the emails, each on or off */
    public function notificationsData(): array
    {
        return ['rows' => EmailPreferences::rows($this->account())];
    }

    /** Settings › Appearance → Try the new design (since 2.0.0-g): the same setting as PUT /api/account/ui. */
    public function tryNewDesign(): void
    {
        $user = $this->account();
        $flags = app(InterfaceFlags::class);
        if (! $flags->design($user)['can_try']) {
            return;
        }
        $flags->setDesign($user, 'v2');
        $this->reopenAppearance();
    }

    /** Settings › Appearance → Switch back to classic (since 2.0.0-a; 2.0.0-g: the design choice "classic"). */
    public function switchBackToClassic(): void
    {
        $user = $this->account();
        $flags = app(InterfaceFlags::class);
        if (! $flags->design($user)['can_switch_back']) {
            return;
        }
        $flags->setDesign($user, 'classic');
        $this->reopenAppearance();
    }

    /** 2.0.0-a's toggle, kept for older links: classic on or off. */
    public function toggleClassic(): void
    {
        $this->account()->ui_classic ? $this->tryNewDesign() : $this->switchBackToClassic();
    }

    /** The other design draws the whole page (its frame too): open Appearance again in it. */
    private function reopenAppearance(): void
    {
        SettingsContext::forget();
        $this->redirect(route('filament.app.auth.profile', ['subpage' => 'appearance']));
    }

    /** One category of emails on or off. */
    public function toggleEmail(string $category): void
    {
        if (! isset(EmailPreferences::CATEGORIES[$category])) {
            return;
        }
        EmailPreferences::set($this->account(), $category, ! EmailPreferences::wants($this->account(), $category));
        SettingsContext::forget();
    }

    /** @return array<string, mixed> Storage: used of the plan's limit, how long recordings are kept, the bin */
    public function storageData(): array
    {
        $context = $this->context();
        $used = app(PlanLimits::class)->storageUsed($context->workspaceId());
        $limit = $context->storageLimit();
        $retention = app(PlanLimits::class)->retentionDays($context->workspaceId());

        return [
            'used' => $used,
            'limit' => $limit,
            'value' => Formats::bytes($used).($limit !== null ? ' of '.Formats::bytes($limit) : ''),
            'percent' => $limit !== null && $limit > 0 ? min(100, (int) floor($used * 100 / $limit)) : null,
            'retention' => $retention !== null ? Formats::days($retention) : 'Until you delete them',
            'bin' => CaptureSession::binHours().' hours',
            'binUrl' => RecentlyDeleted::canAccess() ? RecentlyDeleted::getUrl(panel: 'app') : null,
        ];
    }

    /** @return array<string, mixed> Transcription key */
    public function keyData(): array
    {
        $key = app(TranscriptionKeys::class)->describe($this->account());
        $context = $this->context();

        return [
            'set' => (bool) $key['set'],
            'last4' => $key['last4'] ?? null,
            'platform' => ! $context->flag('free_or_key') || ($key['set'] && app(PlanLimits::class)->allows($context->workspaceId(), 'platform_transcription', $this->account()->id)),
        ];
    }

    /** Transcription key › Save: Groq is asked once whether it takes the key; errors stay under the box. */
    public function saveGroqKey(): void
    {
        $this->groqKeyError = null;
        $key = trim($this->groqKey);
        if (mb_strlen($key) < 20) {
            $this->groqKeyError = 'Paste the whole key. It starts with gsk_.';

            return;
        }
        try {
            app(TranscriptionKeys::class)->put($this->account(), TranscriptionKeys::PROVIDER, $key);
        } catch (ApiError $e) {
            $this->groqKeyError = $e->getStatusCode() >= 500 ? "Couldn't reach Groq. Try again." : "That key didn't work. Check it on console.groq.com and paste it again.";

            return;
        }
        $this->groqKey = '';
        SettingsContext::forget();
        Notification::make()->title('Your Groq key is saved')->body('New recordings are transcribed with it.')->success()->send();
    }

    /** Transcription key › Remove key, confirmed. */
    public function removeKeyAction(): Action
    {
        return Action::make('removeKey')->label('Remove key')->color('danger')
            ->requiresConfirmation()->modalIcon(null)->modalHeading('Remove your Groq key?')
            ->modalDescription("New recordings won't be transcribed until you add a key or upgrade.")
            ->modalSubmitActionLabel('Remove key')
            ->action(function (): void {
                app(TranscriptionKeys::class)->forget($this->account());
                SettingsContext::forget();
                Notification::make()->title('Your Groq key is removed')->success()->send();
            });
    }

    /** @return array<string, mixed> Help: the questions people ask, with their answers and the setting each opens */
    public function helpData(): array
    {
        $context = $this->context();
        $articles = [];
        foreach ($context->entries() as $entry) {
            if ($entry['kind'] !== 'help') {
                continue;
            }
            $target = SettingsRegistry::byId()[(string) $entry['target']] ?? null;
            $articles[] = ['id' => $entry['id'], 'question' => $entry['title'], 'answer' => $entry['answer'],
                'target' => $target !== null && $context->available($target) ? ['title' => $target['title'], 'url' => $context->url($target)] : null];
        }

        return ['articles' => $articles, 'apps' => GetTheApps::getUrl(panel: 'app')];
    }

    /**
     * The shortcuts of Help & shortcuts, by where they work.
     *
     * @return array<string, list<array{0: string, 1: string}>>
     */
    public static function shortcuts(): array
    {
        $mac = str_contains((string) request()->userAgent(), 'Mac OS X');

        return [
            'Everywhere' => [
                [$mac ? '⌘ K' : 'Ctrl K', 'Search recordings, transcripts, pages and settings'],
                ['/', 'Search the list on this page'],
                ['[', 'Fold the sidebar to icons, and back'],
                ['?', 'Help & shortcuts'],
                ['Esc', 'Close a panel, a menu or a dialog'],
                // Since docs/PLAN_NAV_3_LEVELS.md section 0 (the new Library's frame): Back and Forward, as the apps have them.
                ...(ConsoleV2::library() ? [[$mac ? '⌘ [  ⌘ ]' : 'Ctrl [  Ctrl ]', 'Back and forward']] : []),
            ],
            'In the Library' => [
                ['J  K', 'Next and previous recording'],
                ['Enter', 'Open it'],
                ['X', 'Select it'],
                ['Esc', 'Close the recording on the right, or clear the selection'],
                // Since v2.0 (ui_v2.library, parity plan 3): the selection's keys and mouse.
                ...(ConsoleV2::library() ? [
                    [$mac ? '⌘ A' : 'Ctrl A', 'Select every recording on the page'],
                    ['Shift click', 'Select a range'],
                    ['Delete', 'Delete the selection (Undo in the notice)'],
                    ['Right-click', 'The recording\'s menu, or the selection\'s'],
                    // Since 2.0.0-p (docs/PLAN_LIBRARY_FILTERS.md section 5): the filters, the search and the places.
                    ['F', 'Open or close the filters (while the Library has focus)'],
                    [$mac ? '⌘ F' : 'Ctrl F', 'Search titles, transcripts and projects in the Library'],
                    [$mac ? '⌘ 1…9  ⌥ 1…9' : 'Ctrl 1…9  Alt 1…9', 'Open the sidebar\'s first nine places'],
                ] : []),
            ],
            'On a recording' => [
                ['Space', 'Play or pause'],
                ['←  →', 'Back or forward 5 seconds (Shift: 10)'],
            ],
        ];
    }
}
