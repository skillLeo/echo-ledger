<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SourceDevice;
use App\Enums\TranscriptErrorCode;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Enums\UploadStatus;
use App\Enums\WorkspaceRole;
use App\Filament\Pages\SharedWithMe;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Filament\Support\Notice as Notification;
use App\Mail\TranscriptReadyMail;
use App\Models\CaptureSession;
use App\Models\Device;
use App\Models\MissedRecording;
use App\Models\Recording;
use App\Models\Screen;
use App\Models\Transcript;
use App\Models\UsageLedgerEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Accounts\EmailPreferences;
use App\Services\ClientAi\ClientWorkspace;
use App\Services\Entitlements\UnusualUsage;
use App\Services\Library\ReadyNotices;
use App\Services\Usage\CostAlerts;
use App\Services\Usage\UsagePeriod;
use App\Services\Usage\UsageSummary;
use App\Services\Workspaces\PlanLimits;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Notifications\DatabaseNotification as FilamentDatabaseNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The activity centre (plan B18): "Transcript ready", "Transcription failed", "Upload failed", "Device paired" and
 * "Monthly limit at 80 % / reached", since v1.5 also for the plan's storage and AI credits. One database notification
 * per event: the console bell shows it, the device apps read it with GET /api/activity and turn it into an OS
 * notification that opens the recording. Since 2.0.0-r (BE-02) an event about a recording that happened before to the
 * same recording updates its item instead (one item per person, recording and kind: `dedupe_key`), and an upload
 * failure is resolved once the recording reaches the server.
 */
final class ActivityFeed
{
    /** Which of a plan's limits a budget notice is about (none: the minutes, as before v1.5). */
    public const LIMIT_STORAGE = 'storage';

    public const LIMIT_AI_CREDITS = 'ai_credits';

    /**
     * Since v1.6 also the person's upgrade request decided, the super admin's new request and unusual use; since v1.7
     * staff asking the person for another payment receipt; since v1.8 a call an app saw but did not record.
     */
    public const KINDS = ['transcript_ready', 'transcript_failed', 'upload_failed', 'device_paired', 'budget_warn', 'budget_reached', 'shared_with_you',
        'upgrade_approved', 'upgrade_declined', 'upgrade_requested', 'unusual_usage', 'cost_alert', 'upgrade_receipt_requested', 'missed_recording'];

    /** Read notifications are kept this long, unread ones twice as long. */
    public const KEEP_DAYS = 90;

    /** Hooks the events. Called once at boot. */
    public static function listen(): void
    {
        $feed = fn (): self => app(self::class);
        Transcript::created(fn (Transcript $t) => $feed()->transcriptChanged($t, true));
        Transcript::updated(fn (Transcript $t) => $feed()->transcriptChanged($t, false));
        // Since 2.0.0-r (BE-02 review): a deleted recording's upload failure is over.
        CaptureSession::deleted(fn (CaptureSession $session) => $feed()->uploadGone($session));
        Recording::updated(function (Recording $r) use ($feed): void {
            if ($r->wasChanged('upload_status') && $r->upload_status === UploadStatus::Failed) {
                $feed()->uploadFailed($r);
            }
            if ($r->wasChanged('upload_status') && $r->upload_status === UploadStatus::Ready) {
                // Since 2.0.0-r (BE-02): an upload that had failed is on the server now.
                $feed()->uploadResolved($r);
                $session = CaptureSession::acrossPeople()->withTrashed()->whereKey($r->capture_session_id)->first(['id', 'workspace_id', 'user_id']);
                $session === null || $feed()->planLimitChanged((int) $session->workspace_id, self::LIMIT_STORAGE);
                // Since v1.6: more hours in a day than the super admin's alert (UnusualUsage).
                $session === null || app(UnusualUsage::class)->check((int) $session->user_id);
            }
        });
        Screen::created(fn (Screen $s) => $feed()->planLimitChanged($s->workspace_id, self::LIMIT_STORAGE));
        Device::created(fn (Device $d) => $feed()->post($d->user, 'device_paired', self::line(':name signed in', ['name' => $d->readableName()]), self::line('It can now record, sync and see the library.'), null, $d->sourceDevice() === SourceDevice::Desktop ? 'heroicon-o-computer-desktop' : 'heroicon-o-device-phone-mobile'));
        UsageLedgerEntry::created(fn (UsageLedgerEntry $e) => $feed()->budgetChanged($e));
        // Since v1.6 (P2.6): the server's monthly budget alert for staff.
        UsageLedgerEntry::created(fn () => app(CostAlerts::class)->check());
        // Since v1.8 (P0 0.2): an app found a call it did not record. A repeated event is no new row, so no new item.
        MissedRecording::created(fn (MissedRecording $m) => $feed()->missedRecording($m));
        // Contract 1.8.0-c: the item goes with its row, whether one row goes (pruned 90 days after the call, or deleted)
        // or a whole workspace's (its rows go by the foreign key, which tells nobody). An erased account's items go with
        // all its other notifications (AccountEraser).
        MissedRecording::deleted(fn (MissedRecording $m) => $feed()->forgetMissedRecordings($m->user_id, [$m->id]));
        // A closure that answers nothing: `deleting` stops at the first listener that answers, and would skip the rest.
        Workspace::deleting(function (Workspace $w) use ($feed): void {
            MissedRecording::query()->withoutGlobalScopes()->where('workspace_id', $w->id)->get(['id', 'user_id'])
                ->groupBy('user_id')->each(fn ($rows, $userId) => $feed()->forgetMissedRecordings((int) $userId, $rows->pluck('id')->all()));
        });
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
        $this->post($session->user, 'transcript_ready', self::line('Transcript ready'), $session->displayTitle(), $session, 'heroicon-o-document-text');
        if (EmailPreferences::wants($session->user, EmailPreferences::TRANSCRIPTION)) {
            Mail::to($session->user->email)->queue(new TranscriptReadyMail($session->displayTitle(), url('/app/sessions/'.$session->id)));
        }
        // Since v1.6: the one email asked for with "Notify me when ready".
        app(ReadyNotices::class)->deliver($session, ready: true);
    }

    /** True when a failure of this final transcript is handed to another engine before anyone is told. */
    public static function fallsBack(Transcript $transcript): bool
    {
        // Since v1.6 one made with the person's own key does not go to another engine: they hear of it at once.
        return TranscriptErrorCode::tryFrom((string) $transcript->error_code)?->retryable() === true && ! (bool) (($transcript->meta ?? [])['own_key'] ?? false);
    }

    /** The body of "Transcription failed": the recording's name, then why in plain words for [$errorCode]. */
    public static function failedBody(string $title, ?string $errorCode): string
    {
        return self::text(self::failedLine($title, $errorCode), 'en');
    }

    /**
     * The same as a line in the reader's language (since W-218).
     *
     * @return array{key: string, params: array<string, mixed>}
     */
    public static function failedLine(string $title, ?string $errorCode): array
    {
        return self::line(':title: :reason', ['title' => $title, 'reason' => self::line(TranscriptErrorCode::plainWords(TranscriptErrorCode::tryFrom((string) $errorCode)))]);
    }

    /**
     * "Transcription failed" with the reason in plain words per error code (since v1.8.1, D-131): never the engine's own
     * error text, which can name the provider. The apps show this text and build their OS notification from it.
     */
    public function transcriptFailed(Transcript $transcript, ?CaptureSession $session = null): void
    {
        $session ??= $transcript->captureSession()->first();
        if ($session instanceof CaptureSession && ! $session->trashed()) {
            $this->post($session->user, 'transcript_failed', self::line('Transcription failed'), self::failedLine($session->displayTitle(), $transcript->error_code), $session, 'heroicon-o-exclamation-triangle');
            app(ReadyNotices::class)->deliver($session, ready: false);
        }
    }

    /**
     * "Missed recording" (since v1.8): "WhatsApp call · 5:12 · The helper was not connected", to the device's person.
     * The item carries `reason` and `missed_recording_id`: the apps show their own fix for the reason.
     */
    public function missedRecording(MissedRecording $missed): void
    {
        try {
            $user = User::query()->find($missed->user_id);
            if ($user instanceof User) {
                $notification = self::make(self::line('Missed recording'), $missed->activityLine(), 'missed_recording', null,
                    extra: ['reason' => $missed->reason, 'missed_recording_id' => $missed->id])->icon('heroicon-o-phone-x-mark');
                $user->notifyNow($notification->toDatabase());
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Deletes the "Missed recording" items of these rows of one person, as their rows go (contract 1.8.0-c).
     *
     * @param  list<string>  $ids
     */
    public function forgetMissedRecordings(int $userId, array $ids): void
    {
        try {
            $user = User::query()->find($userId);
            foreach ($user instanceof User ? array_chunk($ids, 500) : [] as $chunk) {
                $user->notifications()->whereIn('data->viewData->missed_recording_id', $chunk)->delete();
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function uploadFailed(Recording $recording): void
    {
        $session = $recording->captureSession()->first();
        if ($session instanceof CaptureSession && ! $session->trashed()) {
            $this->post($session->user, 'upload_failed', self::line('Upload failed — retry'),
                self::line(':title did not finish uploading. The device that recorded it can send it again.', ['title' => $session->displayTitle()]), $session, 'heroicon-o-arrow-up-tray');
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
                ? $this->post($user, $kind, self::line('Monthly transcription limit reached'), self::line('New transcripts wait until the limit is raised in Settings or the month ends.'), null, 'heroicon-o-no-symbol')
                : $this->post($user, $kind, self::line('Transcription at :percent% of the monthly limit', ['percent' => $usage['warn_at_percent']]),
                    self::plural(':minutes minute used this month.|:minutes minutes used this month.', (int) $usage['minutes'], ['minutes' => $usage['minutes']]), null, 'heroicon-o-chart-bar');
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
            $storage = $limit === self::LIMIT_STORAGE;
            [$title, $body] = $kind === 'budget_reached'
                ? [self::line($storage ? 'Storage of your plan used up' : 'AI credits of your plan used up'),
                    self::line($storage ? 'New recordings stay on the device until there is room or the plan is bigger.' : 'Project notes and Ask wait until next month or a bigger plan.')]
                : [self::line($storage ? 'Storage at :percent% of your plan' : 'AI credits at :percent% of your plan', ['percent' => $warnAt]),
                    $storage ? self::line(':size used.', ['size' => Number::fileSize((int) $used, precision: 1)]) : self::line(':amount used this month.', ['amount' => '$'.number_format($used, 2)])];
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

    /**
     * [$url]: where the bell's card opens (since v1.6.1), when the kind alone does not say (NoticeTargets). [$title] and
     * [$body] are words as they are (what people wrote) or, since W-218, a line() shown in the reader's language.
     *
     * @param  string|array{key: string, params: array<string, mixed>}  $title
     * @param  string|array{key: string, params: array<string, mixed>}  $body
     */
    public function post(User $user, string $kind, string|array $title, string|array $body, ?CaptureSession $session, string $icon, ?string $limit = null, ?string $url = null): void
    {
        try {
            $notification = self::make($title, $body, $kind, $session?->id, $limit, $url)->icon($icon);
            if ($session !== null) {
                $notification->actions([Action::make('open')->label('Open')->url(CaptureSessionResource::getUrl('view', ['record' => $session]), shouldOpenInNewTab: false)->markAsRead()]);
            }
            // Written at once, not queued: the bell, the device poll and the once-a-month checks see it immediately.
            $session === null ? $user->notifyNow($notification->toDatabase()) : self::upsert($user, $kind, $session->id, $notification->toDatabase()->data);
        } catch (Throwable $e) {
            self::reportSafely($e, 'An Activity item was not written');
        }
    }

    /**
     * Reports a failed write without what it wrote: a QueryException's message carries its bindings (a title, a name),
     * so the log gets its class and SQLSTATE only, never the words (BE-02 review).
     */
    private static function reportSafely(Throwable $e, string $what): void
    {
        $state = $e instanceof QueryException ? ' (SQLSTATE '.((string) ($e->errorInfo[0] ?? $e->getCode())).')' : '';
        report(new RuntimeException($what.': '.$e::class.$state));
    }

    /**
     * One item per person, recording and kind (contract 2.0.0-r, BE-02): the same event again updates the item, newest
     * words, unread, at the top, `repeat_count` one more, no longer resolved. Two requests at once meet at the unique key.
     *
     * @param  array<string, mixed>  $data
     */
    private static function upsert(User $user, string $kind, string $sessionId, array $data): void
    {
        $key = $kind.':'.$sessionId;
        $existing = $user->notifications()->where('dedupe_key', $key)->first();
        if ($existing instanceof DatabaseNotification) {
            $existing->forceFill(['data' => $data, 'read_at' => null, 'resolved_at' => null, 'repeat_count' => (int) $existing->getAttribute('repeat_count') + 1,
                'created_at' => now(), 'updated_at' => now()])->save();

            return;
        }
        try {
            $user->notifications()->create(['id' => (string) Str::uuid(), 'type' => FilamentDatabaseNotification::class, 'data' => $data,
                'read_at' => null, 'dedupe_key' => $key, 'repeat_count' => 1]);
        } catch (UniqueConstraintViolationException) {
            self::upsert($user, $kind, $sessionId, $data);
        }
    }

    /**
     * The recording of an upload failure reached the server after all (contract 2.0.0-r, BE-02): once no recording of its
     * session is still failed, the item reads "Uploaded", is read and is resolved, so it leaves the group and never counts
     * on the badge.
     */
    public function uploadResolved(Recording $recording): void
    {
        try {
            $session = CaptureSession::acrossPeople()->withTrashed()->whereKey($recording->capture_session_id)->first();
            if (! $session instanceof CaptureSession
                || Recording::query()->where('capture_session_id', $session->id)->where('upload_status', UploadStatus::Failed->value)->exists()) {
                return;
            }
            $item = self::uploadFailureOf($session);
            if (! $item instanceof DatabaseNotification) {
                return;
            }
            $data = self::make(self::line('Uploaded'), self::line(':title is on the server now.', ['title' => $session->displayTitle()]), 'upload_failed', $session->id)
                ->icon('heroicon-o-check-circle')->toDatabase()->data;
            $item->forceFill(['data' => $data, 'read_at' => $item->read_at ?? now(), 'resolved_at' => now()])->save();
        } catch (Throwable $e) {
            self::reportSafely($e, 'An upload failure was not resolved');
        }
    }

    /**
     * A recording deleted (to the undo bin, or for good) has nothing left to upload: its upload failure is resolved and
     * read, its words kept, so it leaves the group and the badge (BE-02 review).
     */
    public function uploadGone(CaptureSession $session): void
    {
        try {
            $item = self::uploadFailureOf($session);
            if ($item instanceof DatabaseNotification) {
                $item->forceFill(['read_at' => $item->read_at ?? now(), 'resolved_at' => now()])->save();
            }
        } catch (Throwable $e) {
            self::reportSafely($e, 'An upload failure was not resolved');
        }
    }

    /** The person's unresolved upload failure for a recording, if any. */
    private static function uploadFailureOf(CaptureSession $session): ?DatabaseNotification
    {
        $item = DatabaseNotification::query()->where('notifiable_type', (new User)->getMorphClass())
            ->where('notifiable_id', $session->user_id)->where('dedupe_key', 'upload_failed:'.$session->id)->whereNull('resolved_at')->first();

        return $item instanceof DatabaseNotification ? $item : null;
    }

    /**
     * "Ana sent you Call with Omar" (since v1.5). The recording is in the sender's workspace: the apps open it by
     * `session_id` (they may read what was sent to them), the console's Open goes to Shared with me.
     */
    /**
     * @param  string|array{key: string, params: array<string, mixed>}  $title
     * @param  string|array{key: string, params: array<string, mixed>}  $body
     */
    public function sharedWithYou(User $recipient, string|array $title, string|array $body, ?string $sessionId): void
    {
        try {
            $notification = self::make($title, $body, 'shared_with_you', $sessionId)->icon('heroicon-o-users')
                ->actions([Action::make('open')->label('Open')->url(SharedWithMe::getUrl(panel: 'app'), shouldOpenInNewTab: false)->markAsRead()]);
            $sessionId === null ? $recipient->notifyNow($notification->toDatabase()) : self::upsert($recipient, 'shared_with_you', $sessionId, $notification->toDatabase()->data);
        } catch (Throwable $e) {
            self::reportSafely($e, 'An Activity item was not written');
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
     * bell shows them as text. `escaped` tells payload() to give the apps the words as they were typed. [$extra]: what
     * a kind carries besides (a missed recording's reason and id).
     *
     * Since W-218 a line() is stored twice: in English as the title or body (what the apps and older rows read), and as
     * its key and params in `viewData.lines`, so the console shows it in the reader's language (localized()).
     *
     * @param  string|array{key: string, params: array<string, mixed>}  $title
     * @param  string|array{key: string, params: array<string, mixed>}  $body
     * @param  array<string, string>  $extra
     */
    private static function make(string|array $title, string|array $body, string $kind, ?string $sessionId, ?string $limit = null, ?string $url = null, array $extra = []): Notification
    {
        $lines = array_filter(['title' => is_array($title) ? $title : null, 'body' => is_array($body) ? $body : null]);

        return Notification::make()->title(e(self::text($title, 'en')))->body(e(self::text($body, 'en')))
            ->viewData([...$extra, 'kind' => $kind, 'session_id' => $sessionId, 'escaped' => true, ...($limit !== null ? ['limit' => $limit] : []), ...($url !== null ? ['url' => $url] : []),
                ...($lines !== [] ? ['lines' => $lines] : [])]);
    }

    /**
     * A line of the activity feed to show in the reader's language (W-218): [$key] is the English words with :params,
     * as lang/ur.json knows them; a param may be a line itself (a reason in plain words). Params that are not lines
     * are shown as they are (names, titles, numbers).
     *
     * @param  array<string, mixed>  $params
     * @return array{key: string, params: array<string, mixed>}
     */
    public static function line(string $key, array $params = []): array
    {
        return ['key' => $key, 'params' => $params];
    }

    /**
     * A line with singular and plural forms (`one|many`, as trans_choice() takes them), chosen by [$count] in the
     * reader's language (W-218 follow-up: "1 minute used", and Urdu's own forms).
     *
     * @param  array<string, mixed>  $params
     * @return array{key: string, params: array<string, mixed>, count: int}
     */
    public static function plural(string $key, int $count, array $params = []): array
    {
        return ['key' => $key, 'params' => $params, 'count' => $count];
    }

    /**
     * The words of a line in [$locale] (null: the reader's), or words as they are.
     *
     * @param  string|array<array-key, mixed>  $line
     */
    public static function text(string|array $line, ?string $locale = null): string
    {
        if (is_string($line)) {
            return $line;
        }
        $params = [];
        foreach ((array) ($line['params'] ?? []) as $name => $value) {
            $params[(string) $name] = is_array($value) && isset($value['key']) ? self::text($value, $locale) : (is_scalar($value) ? (string) $value : '');
        }

        if (isset($line['count']) && is_int($line['count'])) {
            return (string) trans_choice((string) ($line['key'] ?? ''), $line['count'], $params, $locale);
        }

        return (string) __((string) ($line['key'] ?? ''), $params, $locale);
    }

    /**
     * The title and body in the reader's language (W-218): from the stored lines when the notification has them, else
     * the stored English words, so notifications made before still read as they did. Words as typed, not escaped.
     *
     * @return array{title: string, body: string}
     */
    public static function localized(DatabaseNotification $n): array
    {
        $payload = self::payload($n);
        $lines = $n->data['viewData']['lines'] ?? null;
        $valid = fn (mixed $line): bool => is_array($line) && is_string($line['key'] ?? null);

        return [
            'title' => is_array($lines) && $valid($lines['title'] ?? null) ? self::text($lines['title']) : $payload['title'],
            'body' => is_array($lines) && $valid($lines['body'] ?? null) ? self::text($lines['body']) : $payload['body'],
        ];
    }

    /** @return array<string, mixed> */
    public static function payload(DatabaseNotification $n): array
    {
        $session = $n->data['viewData']['session_id'] ?? null;
        $text = fn (mixed $value): string => ($n->data['viewData']['escaped'] ?? false) === true
            ? htmlspecialchars_decode((string) $value, ENT_QUOTES) : (string) $value;
        $kind = self::kindOf($n);
        $string = fn (string $key): ?string => is_string($n->data['viewData'][$key] ?? null) ? $n->data['viewData'][$key] : null;

        return [
            'id' => $n->id,
            'kind' => $kind,
            'title' => $text($n->data['title'] ?? ''),
            'body' => $text($n->data['body'] ?? ''),
            'session_id' => is_string($session) ? $session : null,
            'read' => $n->read_at !== null,
            'at' => $n->created_at?->toAtomString(),
            // Since 2.0.0-r (BE-02): how many times it happened, its group, and whether what it asked for is done.
            'repeat_count' => max(1, (int) $n->getAttribute('repeat_count')),
            'group' => $kind === 'upload_failed' ? 'upload_failed' : null,
            'resolved' => $n->getAttribute('resolved_at') !== null,
            // Since v1.8 a missed recording also says why, and which one (docs/API.md `POST /api/devices/missed-recordings`).
            ...($kind === 'missed_recording' ? ['reason' => $string('reason'), 'missed_recording_id' => $string('missed_recording_id')] : []),
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
