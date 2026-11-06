<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TranscriptErrorCode;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Enums\UploadStatus;
use App\Enums\WorkspaceRole;
use App\Filament\Pages\SharedWithMe;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Mail\TranscriptReadyMail;
use App\Models\CaptureSession;
use App\Models\Device;
use App\Models\Recording;
use App\Models\Screen;
use App\Models\Transcript;
use App\Models\UsageLedgerEntry;
use App\Models\User;
use App\Services\ClientAi\ClientWorkspace;
use App\Services\Usage\UsagePeriod;
use App\Services\Usage\UsageSummary;
use App\Services\Workspaces\PlanLimits;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Number;
use Throwable;

/**
 * The activity centre (plan B18): "Transcript ready", "Transcription failed", "Upload failed", "Device paired" and
 * "Monthly limit at 80 % / reached", since v1.5 also for the plan's storage and AI credits. One database notification
 * per event: the console bell shows it, the device apps read it with GET /api/activity and turn it into an OS
 * notification that opens the recording.
 */
final class ActivityFeed
{
    /** Which of a plan's limits a budget notice is about (none: the minutes, as before v1.5). */
    public const LIMIT_STORAGE = 'storage';

    public const LIMIT_AI_CREDITS = 'ai_credits';

    public const KINDS = ['transcript_ready', 'transcript_failed', 'upload_failed', 'device_paired', 'budget_warn', 'budget_reached', 'shared_with_you'];

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
            if ($r->wasChanged('upload_status') && $r->upload_status === UploadStatus::Ready) {
                $workspace = CaptureSession::acrossPeople()->withTrashed()->whereKey($r->capture_session_id)->value('workspace_id');
                $workspace === null || $feed()->planLimitChanged((int) $workspace, self::LIMIT_STORAGE);
            }
        });
        Screen::created(fn (Screen $s) => $feed()->planLimitChanged($s->workspace_id, self::LIMIT_STORAGE));
        Device::created(fn (Device $d) => $feed()->post($d->user, 'device_paired', "{$d->name} signed in", 'It can now record, sync and see the library.', null, 'heroicon-o-device-phone-mobile'));
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
            TranscriptStatus::Completed => $this->transcriptReady($session),
            // A provider failure goes to the next engine first (TranscriptStore::fail queues EngineFallbackJob), so
            // "failed" waits until no engine is left: EngineFallbackJob posts it then.
            TranscriptStatus::Failed => self::fallsBack($transcript) ? null : $this->transcriptFailed($transcript, $session),
            default => null,
        };
    }

    /** The bell, and since v1.5 an email for people who turned "Email me when a transcript is ready" on. */
    private function transcriptReady(CaptureSession $session): void
    {
        $this->post($session->user, 'transcript_ready', 'Transcript ready', $session->displayTitle(), $session, 'heroicon-o-document-text');
        if ($session->user->notify_transcript_ready) {
            Mail::to($session->user->email)->queue(new TranscriptReadyMail($session->displayTitle(), url('/admin/sessions/'.$session->id)));
        }
    }

    /** True when a failure of this final transcript is handed to another engine before anyone is told. */
    public static function fallsBack(Transcript $transcript): bool
    {
        return TranscriptErrorCode::tryFrom((string) $transcript->error_code)?->retryable() === true;
    }

    public function transcriptFailed(Transcript $transcript, ?CaptureSession $session = null): void
    {
        $session ??= $transcript->captureSession()->first();
        if ($session instanceof CaptureSession && ! $session->trashed()) {
            $this->post($session->user, 'transcript_failed', 'Transcription failed', $session->displayTitle().': '.($transcript->error ?? 'try again from the recording.'), $session, 'heroicon-o-exclamation-triangle');
        }
    }

    public function uploadFailed(Recording $recording): void
    {
        $session = $recording->captureSession()->first();
        if ($session instanceof CaptureSession && ! $session->trashed()) {
            $this->post($session->user, 'upload_failed', 'Upload failed — retry', $session->displayTitle().' did not finish uploading. The device that recorded it can send it again.', $session, 'heroicon-o-arrow-up-tray');
        }
    }

    /**
     * "Monthly limit at 80 %" and "reached", each once per month, to every owner of the entry's workspace (B20). The
     * limit is the tighter of the server cap and the workspace plan (UsageSummary).
     */
    public function budgetChanged(UsageLedgerEntry $entry): void
    {
        // Client AI spends the plan's credits; those have their own notice.
        if ($entry->provider === 'claude' && str_starts_with($entry->use_case, 'client_')) {
            $this->planLimitChanged($entry->workspace_id, self::LIMIT_AI_CREDITS);
        }
        $usage = app(UsageSummary::class)->toArray($entry->workspace_id);
        $kind = $usage['limit_reached'] ? 'budget_reached' : ($usage['warn'] ? 'budget_warn' : null);
        if ($kind === null) {
            return;
        }
        foreach ($this->owners($entry->workspace_id) as $user) {
            if ($this->sentThisMonth($user, $kind, (string) $usage['period_start'])) {
                continue;
            }
            $kind === 'budget_reached'
                ? $this->post($user, $kind, 'Monthly transcription limit reached', 'New transcripts wait until the limit is raised in Settings or the month ends.', null, 'heroicon-o-no-symbol')
                : $this->post($user, $kind, "Transcription at {$usage['warn_at_percent']}% of the monthly limit", "{$usage['minutes']} minutes used this month.", null, 'heroicon-o-chart-bar');
        }
    }

    /**
     * "Storage at 80 % of the plan" and "reached", the same for AI credits (since v1.5), each once per month, to every
     * owner of the workspace. Only the plan's own limits: a plan without the limit says nothing.
     */
    public function planLimitChanged(int $workspaceId, string $limit): void
    {
        try {
            $limits = app(PlanLimits::class);
            $plan = $limits->plan($workspaceId);
            $max = $limit === self::LIMIT_STORAGE ? $plan->storage_bytes : $plan->aiCredits();
            if ($max === null || $max <= 0) {
                return;
            }
            $used = $limit === self::LIMIT_STORAGE ? (float) $limits->storageUsed($workspaceId) : app(ClientWorkspace::class)->budget($workspaceId)['spent_usd'];
            $settings = app(SettingsRepository::class);
            $warnAt = $settings->warnPercent();
            $percent = $used / $max * 100;
            $kind = $percent >= 100 ? 'budget_reached' : ($percent >= $warnAt ? 'budget_warn' : null);
            if ($kind === null) {
                return;
            }
            $name = $limit === self::LIMIT_STORAGE ? 'Storage' : 'AI credits';
            [$title, $body] = $kind === 'budget_reached'
                ? ["{$name} of your plan used up", $limit === self::LIMIT_STORAGE ? 'New recordings stay on the device until there is room or the plan is bigger.' : 'Project notes and Ask wait until next month or a bigger plan.']
                : ["{$name} at {$warnAt}% of your plan", $limit === self::LIMIT_STORAGE ? Number::fileSize((int) $used, precision: 1).' used.' : '$'.number_format($used, 2).' used this month.'];
            $start = (string) UsagePeriod::current($settings)['start']->toIso8601String();
            foreach ($this->owners($workspaceId) as $user) {
                if (! $this->sentThisMonth($user, $kind, $start, $limit)) {
                    $this->post($user, $kind, $title, $body, null, $kind === 'budget_reached' ? 'heroicon-o-no-symbol' : 'heroicon-o-chart-bar', $limit);
                }
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @return Collection<int, User> */
    private function owners(int $workspaceId): Collection
    {
        return User::query()->whereIn('id', DB::table('workspace_user')->where('workspace_id', $workspaceId)
            ->where('role', WorkspaceRole::Owner->value)->select('user_id'))->get();
    }

    private function sentThisMonth(User $user, string $kind, string $periodStart, ?string $limit = null): bool
    {
        // A date, not the ISO string: compared as text, "2026-11-01 09:00:00" sorts before "2026-11-01T00:00:00+00:00",
        // so on the first day of a month every warning looked unsent and was posted again.
        return $user->notifications()->where('created_at', '>=', Carbon::parse($periodStart))->get()
            ->contains(fn (DatabaseNotification $n): bool => self::kindOf($n) === $kind && ($n->data['viewData']['limit'] ?? null) === $limit);
    }

    public function post(User $user, string $kind, string $title, string $body, ?CaptureSession $session, string $icon, ?string $limit = null): void
    {
        try {
            $notification = self::make($title, $body, $kind, $session?->id, $limit)->icon($icon);
            if ($session !== null) {
                $notification->actions([Action::make('open')->label('Open')->url(CaptureSessionResource::getUrl('view', ['record' => $session]), shouldOpenInNewTab: false)->markAsRead()]);
            }
            // Written at once, not queued: the bell, the device poll and the once-a-month checks see it immediately.
            $user->notifyNow($notification->toDatabase());
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * "Ana sent you Call with Omar" (since v1.5). The recording is in the sender's workspace: the apps open it by
     * `session_id` (they may read what was sent to them), the console's Open goes to Shared with me.
     */
    public function sharedWithYou(User $recipient, string $title, string $body, ?string $sessionId): void
    {
        try {
            $notification = self::make($title, $body, 'shared_with_you', $sessionId)->icon('heroicon-o-users')
                ->actions([Action::make('open')->label('Open')->url(SharedWithMe::getUrl(panel: 'admin'), shouldOpenInNewTab: false)->markAsRead()]);
            $recipient->notifyNow($notification->toDatabase());
        } catch (Throwable $e) {
            report($e);
        }
    }

    public static function kindOf(DatabaseNotification $n): ?string
    {
        $kind = $n->data['viewData']['kind'] ?? null;

        return is_string($kind) ? $kind : null;
    }

    /**
     * The bell renders a notification's title and body as HTML (sanitised, but links and styles stay), and they hold
     * what people typed (titles, notes, names; since v1.5 from anyone on the server): they are stored escaped, so the
     * bell shows them as text. `escaped` tells payload() to give the apps the words as they were typed.
     */
    private static function make(string $title, string $body, string $kind, ?string $sessionId, ?string $limit = null): Notification
    {
        return Notification::make()->title(e($title))->body(e($body))
            ->viewData(['kind' => $kind, 'session_id' => $sessionId, 'escaped' => true, ...($limit !== null ? ['limit' => $limit] : [])]);
    }

    /** @return array<string, mixed> */
    public static function payload(DatabaseNotification $n): array
    {
        $session = $n->data['viewData']['session_id'] ?? null;
        $text = fn (mixed $value): string => ($n->data['viewData']['escaped'] ?? false) === true
            ? htmlspecialchars_decode((string) $value, ENT_QUOTES) : (string) $value;

        return [
            'id' => $n->id,
            'kind' => self::kindOf($n),
            'title' => $text($n->data['title'] ?? ''),
            'body' => $text($n->data['body'] ?? ''),
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
