<?php

declare(strict_types=1);

namespace App\Filament\Auth\Settings;

use App\Exceptions\ApiError;
use App\Filament\Pages\GetTheApps;
use App\Filament\Pages\RecentlyDeleted;
use App\Filament\Support\Notice as Notification;
use App\Filament\Support\SettingsContext;
use App\Filament\Support\Shell;
use App\Models\CaptureSession;
use App\Services\Accounts\EmailPreferences;
use App\Services\Accounts\SettingsRegistry;
use App\Services\Entitlements\TranscriptionKeys;
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

        return [
            'prefs' => $prefs,
            'rows' => [
                'style' => ['appearance.sidebar', Shell::STYLES],
                'density' => ['appearance.density', Shell::DENSITIES],
                'clock' => ['appearance.clock', ['12h' => '12-hour', '24h' => '24-hour']],
                'layout' => ['appearance.library_view', Shell::LAYOUTS],
            ],
            'languages' => ['' => 'Automatic', 'en' => 'English', 'ur' => 'Urdu', 'ar' => 'Arabic'],
            'more' => LanguageChoice::MORE,
        ];
    }

    /** One preference, saved at once. */
    public function setPreference(string $key, string $value): bool
    {
        if (! in_array($key, ['style', 'density', 'clock', 'layout', 'language'], true) || ! Shell::remember($this->account(), $key, $value)) {
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
                    if (isset($columns[$id])) {
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
                $this->js('document.getElementById("fi-main-sidebar")?.setAttribute("data-style", '.json_encode($prefs['style']).'); document.documentElement.dataset.density = '.json_encode($prefs['density']));
                Notification::make()->title(SettingsRegistry::pageTitle($this->subpage).' reset to defaults')->success()->send();
            });
    }

    /** @return array<string, mixed> Notifications: the emails, each on or off */
    public function notificationsData(): array
    {
        return ['rows' => EmailPreferences::rows($this->account())];
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
            'bin' => CaptureSession::BIN_HOURS.' hours',
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
            ],
            'In the Library' => [
                ['J  K', 'Next and previous recording'],
                ['Enter', 'Open it'],
                ['X', 'Select it'],
                ['Esc', 'Close the recording on the right, or clear the selection'],
            ],
            'On a recording' => [
                ['Space', 'Play or pause'],
                ['←  →', 'Back or forward 5 seconds (Shift: 10)'],
            ],
        ];
    }
}
