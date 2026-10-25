<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Enums\UploadStatus;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Models\CaptureSession;
use App\Models\Device;
use App\Models\Recording;
use App\Models\Transcript;
use App\Models\UsageLedgerEntry;
use App\Models\User;
use App\Services\Usage\UsageSummary;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Notifications\DatabaseNotification;
use Throwable;

/**
 * The activity centre (plan B18): "Transcript ready", "Transcription failed", "Upload failed", "Device paired" and
 * "Monthly limit at 80 % / reached". One database notification per event: the console bell shows it, the device apps
 * read it with GET /api/activity and turn it into an OS notification that opens the recording.
 */
final class ActivityFeed
{
    public const KINDS = ['transcript_ready', 'transcript_failed', 'upload_failed', 'device_paired', 'budget_warn', 'budget_reached'];

    /** Read notifications are kept this long, unread ones twice as long. */
    public const KEEP_DAYS = 90;

    /** Hooks the events. Called once at boot. */
    public static function listen(): void
    {
        $feed = fn (): self => app(self::class);
        Transcript::created(fn (Transcript $t) => $feed()->transcriptChanged($t, true));
        Transcript::updated(fn (Transcript $t) => $feed()->transcriptChanged($t, false));
        Recording::updated(function (Recording $r) use ($feed): void {
            if ($r->wasChanged('upload_status') && $r->upload_status === UploadStatus::Failed) {
                $feed()->uploadFailed($r);
            }
        });
        Device::created(fn (Device $d) => $feed()->post($d->user, 'device_paired', "{$d->name} paired", 'It can now record, sync and see the library.', null, 'heroicon-o-device-phone-mobile'));
        UsageLedgerEntry::created(fn (UsageLedgerEntry $e) => $feed()->budgetChanged($e));
    }

    public function transcriptChanged(Transcript $transcript, bool $created): void
    {
        if ($transcript->kind !== TranscriptKind::Final || (! $created && ! $transcript->wasChanged('status'))) {
            return;
        }
        // Fresh from the database: a relation cached before a delete would not know the recording is in the bin.
        $session = $transcript->captureSession()->first();
        if (! $session instanceof CaptureSession || $session->trashed()) {
            return;
        }
        match ($transcript->status) {
            TranscriptStatus::Completed => $this->post($session->user, 'transcript_ready', 'Transcript ready', $session->displayTitle(), $session, 'heroicon-o-document-text'),
            TranscriptStatus::Failed => $this->post($session->user, 'transcript_failed', 'Transcription failed', $session->displayTitle().': '.($transcript->error ?? 'try again from the recording.'), $session, 'heroicon-o-exclamation-triangle'),
            default => null,
        };
    }

    public function uploadFailed(Recording $recording): void
    {
        $session = $recording->captureSession()->first();
        if ($session instanceof CaptureSession && ! $session->trashed()) {
            $this->post($session->user, 'upload_failed', 'Upload failed — retry', $session->displayTitle().' did not finish uploading. The device that recorded it can send it again.', $session, 'heroicon-o-arrow-up-tray');
        }
    }

    /** "Monthly limit at 80 %" and "reached", each once per month. */
    public function budgetChanged(UsageLedgerEntry $entry): void
    {
        $user = User::query()->find($entry->user_id);
        if (! $user instanceof User) {
            return;
        }
        $usage = app(UsageSummary::class)->toArray();
        $kind = $usage['limit_reached'] ? 'budget_reached' : ($usage['warn'] ? 'budget_warn' : null);
        if ($kind === null || $this->sentThisMonth($user, $kind, (string) $usage['period_start'])) {
            return;
        }
        $kind === 'budget_reached'
            ? $this->post($user, $kind, 'Monthly transcription limit reached', 'New transcripts wait until the limit is raised or the month ends.', null, 'heroicon-o-no-symbol')
            : $this->post($user, $kind, "Transcription at {$usage['warn_at_percent']}% of the monthly limit", "{$usage['minutes']} minutes used this month.", null, 'heroicon-o-chart-bar');
    }

    private function sentThisMonth(User $user, string $kind, string $periodStart): bool
    {
        return $user->notifications()->where('created_at', '>=', $periodStart)->get()
            ->contains(fn (DatabaseNotification $n): bool => self::kindOf($n) === $kind);
    }

    public function post(User $user, string $kind, string $title, string $body, ?CaptureSession $session, string $icon): void
    {
        try {
            $notification = Notification::make()->title($title)->body($body)->icon($icon)
                ->viewData(['kind' => $kind, 'session_id' => $session?->id]);
            if ($session !== null) {
                $notification->actions([Action::make('open')->label('Open')->url(CaptureSessionResource::getUrl('view', ['record' => $session]), shouldOpenInNewTab: false)->markAsRead()]);
            }
            // Written at once, not queued: the bell, the device poll and the once-a-month checks see it immediately.
            $user->notifyNow($notification->toDatabase());
        } catch (Throwable $e) {
            report($e);
        }
    }

    public static function kindOf(DatabaseNotification $n): ?string
    {
        $kind = $n->data['viewData']['kind'] ?? null;

        return is_string($kind) ? $kind : null;
    }

    /** @return array<string, mixed> */
    public static function payload(DatabaseNotification $n): array
    {
        $session = $n->data['viewData']['session_id'] ?? null;

        return [
            'id' => $n->id,
            'kind' => self::kindOf($n),
            'title' => (string) ($n->data['title'] ?? ''),
            'body' => (string) ($n->data['body'] ?? ''),
            'session_id' => is_string($session) ? $session : null,
            'read' => $n->read_at !== null,
            'at' => $n->created_at?->toAtomString(),
        ];
    }

    /** Old notifications go: read ones after 90 days, unread ones after 180. */
    public static function prune(): int
    {
        return DatabaseNotification::query()->where(fn ($q) => $q
            ->where(fn ($r) => $r->whereNotNull('read_at')->where('created_at', '<', now()->subDays(self::KEEP_DAYS)))
            ->orWhere('created_at', '<', now()->subDays(self::KEEP_DAYS * 2)))->delete();
    }
}
