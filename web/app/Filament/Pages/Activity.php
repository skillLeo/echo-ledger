<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\FullConsoleOnly;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Filament\Support\ConsoleDates;
use App\Filament\Support\ConsoleV2;
use App\Filament\Support\Notice as Notification;
use App\Filament\Support\NoticeTargets;
use App\Filament\Support\ObjectMenus;
use App\Filament\Support\Shell;
use App\Models\User;
use App\Services\ActionBadge;
use App\Services\ActivityFeed;
use App\Services\LibraryOrganiser;
use App\Services\SettingsRepository;
use App\Services\UploadRetries;
use App\Services\Workspaces\CurrentWorkspace;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

/**
 * Activity (plan B18), as the phone shows it: what needs the person first (calls waiting for Keep or Discard, failed
 * transcripts and uploads, since v1.8 missed recordings), then today and earlier. The same notifications as the bell
 * and GET /api/activity.
 */
class Activity extends Page
{
    use FullConsoleOnly;

    /** How many notifications the page lists; older ones stay in the bell until they are pruned. */
    public const LIMIT = 60;

    /** Kinds that need the person while they are unread (since v1.8 a missed recording too). */
    public const NEEDS_ACTION = ['transcript_failed', 'upload_failed', 'budget_reached', 'missed_recording'];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Bell;

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Activity';

    protected static ?string $slug = 'activity';

    protected string $view = 'filament.pages.activity';

    /**
     * Since docs/PLAN_NAV_3_LEVELS.md (Activity's panel): All (''), Needs you (what needs action and is not done yet,
     * and the waiting calls) or Done (what was read). Kept in the address with the three levels only (queryString), so
     * the panel's links and Back open it.
     */
    public string $show = '';

    /** @return array<string, array{except: string}> */
    protected function queryString(): array
    {
        return ConsoleV2::frame() ? ['show' => ['except' => '']] : [];
    }

    public function getTitle(): string
    {
        return __('Activity');
    }

    /** Since v1.6 (P6): only what needs the person, as the bell and the apps count it ("99+" above 99 since 2.0.0-r). */
    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();

        return $user instanceof User ? ActionBadge::label(app(ActionBadge::class)->count($user, (int) CurrentWorkspace::id())) : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            // Since 2.0.0-r (BE-03): the devices hold the files, so Retry all asks them, as the apps' Retry all does.
            Action::make('retryAll')->label(__('Retry all uploads'))->color('gray')->icon(Heroicon::OutlinedArrowPath)
                ->visible(fn (): bool => ($user = $this->user()) instanceof User && UploadRetries::failedCount($user) > 0)
                ->action(function (): void {
                    $user = $this->user();
                    if ($user instanceof User) {
                        $count = app(UploadRetries::class)->requestAll($user)['count'];
                        Notification::make()->title(trans_choice('Your devices will send 1 recording again.|Your devices will send :count recordings again.', $count))->success()->send();
                    }
                }),
            Action::make('markAllRead')->label(__('Mark all as read'))->color('gray')->icon(Heroicon::OutlinedCheck)
                ->visible(fn (): bool => $this->user()?->unreadNotifications()->exists() === true)
                ->action(fn () => $this->user()?->unreadNotifications()->update(['read_at' => now()])),
        ];
    }

    /**
     * Opens what a notification is about and marks it as read: the same target as the bell's card (since v1.6.1,
     * NoticeTargets), a recording only while it is in this Library.
     */
    public function open(string $id): void
    {
        $user = $this->user();
        $notification = $user?->notifications()->find($id);
        if (! $user instanceof User || ! $notification instanceof DatabaseNotification) {
            return;
        }
        $notification->markAsRead();
        $target = NoticeTargets::forNotification($notification, $user);
        if ($target !== null) {
            $this->redirect($target, navigate: ! str_contains($target, '/admin'));

            return;
        }
        if (ActivityFeed::payload($notification)['session_id'] !== null) {
            Notification::make()->title(__('This recording is no longer in this workspace\'s library.'))->warning()->send();
        }
    }

    /** UX-22 3b: an Activity item's right-click menu: Open · Mark as read | Retry (an upload that failed). */
    public const OBJECT_ACTIONS = ['activity' => ['place.open', 'activity.markRead', 'activity.retry']];

    /** @return array<string, mixed> */
    public function objectMenus(): array
    {
        return ObjectMenus::data(self::OBJECT_ACTIONS);
    }

    /** A pick from an item's menu (filament.v2.context-menu-script): only the person's own notifications. */
    public function objectAction(string $kind, string $action, string $id, ?string $value = null): void
    {
        $user = $this->user();
        $notification = $user?->notifications()->find($id);
        if (! $user instanceof User || ! $notification instanceof DatabaseNotification || ! ObjectMenus::picked(self::OBJECT_ACTIONS, $kind, $action)) {
            return;
        }
        match ($action) {
            'place.open' => $this->open($id),
            'activity.markRead' => $notification->markAsRead(),
            // The devices hold the files: Retry asks them for every upload that failed, as Retry all uploads does.
            'activity.retry' => ActivityFeed::payload($notification)['kind'] === 'upload_failed'
                ? Notification::make()->title(trans_choice('Your devices will send 1 recording again.|Your devices will send :count recordings again.',
                    $count = app(UploadRetries::class)->requestAll($user)['count'], ['count' => $count]))->success()->send() : null,
            default => null,
        };
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $zone = app(SettingsRepository::class)->displayTimezone();
        $today = Carbon::now($zone)->startOfDay();
        $groups = ['needs' => [], 'today' => [], 'earlier' => []];
        $notifications = $this->user()?->notifications()->latest()->limit(self::LIMIT)->get() ?? collect();
        foreach ($notifications as $notification) {
            // In the reader's language (W-218); notifications made before keep their stored words.
            $item = [...ActivityFeed::payload($notification), ...ActivityFeed::localized($notification)];
            $at = $notification->created_at?->copy()->setTimezone($zone);
            // In the console's language (W-213: "17 نومبر 2026 · 11:42 صبح"; English reads exactly as before).
            $item += [
                'time' => $at === null ? '' : ($at->gte($today) ? ConsoleDates::time($at, Shell::timeFormat()) : ConsoleDates::day($at)),
                'full_time' => $at !== null ? ConsoleDates::dateTime($at, Shell::timeFormat()) : '',
                'tone' => self::tone($item['kind']),
                'icon' => self::icon($item['kind'], $notification->data['icon'] ?? null),
                // UX-22 3b: what its menu leaves out (Open with nothing to open, Mark as read once read, Retry but for a
                // failed upload).
                'not' => implode(' ', array_keys(array_filter([
                    'place.open' => $this->user() instanceof User && NoticeTargets::forNotification($notification, $this->user()) === null,
                    'activity.markRead' => (bool) $item['read'],
                    'activity.retry' => $item['kind'] !== 'upload_failed',
                ]))),
            ];
            $group = ! $item['read'] && in_array($item['kind'], self::NEEDS_ACTION, true) ? 'needs'
                : ($at !== null && $at->gte($today) ? 'today' : 'earlier');
            $groups[$group][] = $item;
        }
        if ($this->show === 'needs_you') {
            $groups = ['needs' => $groups['needs'], 'today' => [], 'earlier' => []];
        } elseif ($this->show === 'done') {
            $groups = array_map(fn (array $items): array => array_values(array_filter($items, fn (array $item): bool => (bool) $item['read'])), $groups);
        }

        return [
            'groups' => $groups,
            'waiting' => $this->show === 'done' ? null : app(LibraryOrganiser::class)->waitingSummary((int) CurrentWorkspace::id()),
            // Since 2.0.0-p the new Library keeps the waiting calls in Needs you.
            'reviewUrl' => CaptureSessionResource::getUrl('index', ['tab' => ConsoleV2::library() ? 'needs_you' : 'waiting']),
        ];
    }

    private static function tone(?string $kind): string
    {
        return match ($kind) {
            'transcript_ready' => 'indigo',
            'transcript_failed', 'upload_failed', 'budget_reached' => 'red',
            // Since v1.8: a call an app did not record; the fix is in that app.
            'budget_warn', 'missed_recording' => 'amber',
            default => 'slate',
        };
    }

    private static function icon(?string $kind, mixed $stored): string
    {
        return match ($kind) {
            'transcript_ready' => 'heroicon-s-check-circle',
            'transcript_failed' => 'heroicon-o-exclamation-circle',
            'upload_failed' => 'heroicon-o-arrow-up-tray',
            // The row knows what signed in; one written before it did stays a phone.
            'device_paired' => $stored === 'heroicon-o-computer-desktop' ? $stored : 'heroicon-o-device-phone-mobile',
            'budget_warn', 'budget_reached' => 'heroicon-o-chart-bar',
            'shared_with_you' => 'heroicon-o-users',
            'missed_recording' => 'heroicon-o-phone-x-mark',
            default => 'heroicon-o-bell',
        };
    }

    private function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
