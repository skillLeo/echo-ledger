<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Models\User;
use App\Services\ActivityFeed;
use App\Services\LibraryOrganiser;
use App\Services\SettingsRepository;
use App\Services\Workspaces\CurrentWorkspace;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

/**
 * Activity (plan B18), as the phone shows it: what needs the person first (calls waiting for Keep or Discard, failed
 * transcripts and uploads), then today and earlier. The same notifications as the bell and GET /api/activity.
 */
class Activity extends Page
{
    /** How many notifications the page lists; older ones stay in the bell until they are pruned. */
    public const LIMIT = 60;

    /** Kinds that need the person while they are unread. */
    public const NEEDS_ACTION = ['transcript_failed', 'upload_failed', 'budget_reached'];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Bell;

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Activity';

    protected static ?string $slug = 'activity';

    protected string $view = 'filament.pages.activity';

    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();
        $unread = $user instanceof User ? $user->unreadNotifications()->count() : 0;

        return $unread > 0 ? (string) $unread : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('markAllRead')->label('Mark all as read')->color('gray')->icon(Heroicon::OutlinedCheck)
                ->visible(fn (): bool => self::getNavigationBadge() !== null)
                ->action(fn () => $this->user()?->unreadNotifications()->update(['read_at' => now()])),
        ];
    }

    /** Opens what a notification is about and marks it as read. */
    public function open(string $id): void
    {
        $notification = $this->user()?->notifications()->find($id);
        if (! $notification instanceof DatabaseNotification) {
            return;
        }
        $notification->markAsRead();
        $session = ActivityFeed::payload($notification)['session_id'];
        if ($session !== null) {
            $this->redirect(CaptureSessionResource::getUrl('view', ['record' => $session]), navigate: true);
        }
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $zone = app(SettingsRepository::class)->displayTimezone();
        $today = Carbon::now($zone)->startOfDay();
        $groups = ['needs' => [], 'today' => [], 'earlier' => []];
        $notifications = $this->user()?->notifications()->latest()->limit(self::LIMIT)->get() ?? collect();
        foreach ($notifications as $notification) {
            $item = ActivityFeed::payload($notification);
            $at = $notification->created_at?->copy()->setTimezone($zone);
            $item += [
                'time' => $at === null ? '' : ($at->gte($today) ? $at->format('g:i A') : $at->format('M j')),
                'full_time' => $at?->format('M j, Y · g:i A') ?? '',
                'tone' => self::tone($item['kind']),
                'icon' => self::icon($item['kind']),
            ];
            $group = ! $item['read'] && in_array($item['kind'], self::NEEDS_ACTION, true) ? 'needs'
                : ($at !== null && $at->gte($today) ? 'today' : 'earlier');
            $groups[$group][] = $item;
        }

        return [
            'groups' => $groups,
            'waiting' => app(LibraryOrganiser::class)->waitingSummary((int) CurrentWorkspace::id()),
            'reviewUrl' => CaptureSessionResource::getUrl('index', ['tab' => 'waiting']),
        ];
    }

    private static function tone(?string $kind): string
    {
        return match ($kind) {
            'transcript_ready' => 'indigo',
            'transcript_failed', 'upload_failed', 'budget_reached' => 'red',
            'budget_warn' => 'amber',
            default => 'slate',
        };
    }

    private static function icon(?string $kind): string
    {
        return match ($kind) {
            'transcript_ready' => 'heroicon-s-check-circle',
            'transcript_failed' => 'heroicon-o-exclamation-circle',
            'upload_failed' => 'heroicon-o-arrow-up-tray',
            'device_paired' => 'heroicon-o-device-phone-mobile',
            'budget_warn', 'budget_reached' => 'heroicon-o-chart-bar',
            default => 'heroicon-o-bell',
        };
    }

    private function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
