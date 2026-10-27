<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SttProviderKey;
use App\Models\CaptureSession;
use App\Models\ProviderDeletion;
use App\Models\Transcript;
use App\Stt\SttManager;
use Throwable;

/**
 * Deletes the provider-side copy (uploaded audio and transcript) of a session's transcripts when the session is
 * deleted or pruned, also while a provider job is still running (docs/API.md DELETE /api/sessions/{id}). The delete
 * runs inline (drivers cap it at 10 s); a failure is queued in `provider_deletions` and retried by stt:reconcile.
 */
final class ProviderCopyEraser
{
    public const FIRST_RETRY_MINUTES = 5;

    public const MAX_BACKOFF_MINUTES = 360;

    public const GIVE_UP_DAYS = 7;

    public function __construct(private readonly SttManager $stt) {}

    /** Every transcript of $session that still has a copy at the provider. */
    public function eraseFor(CaptureSession $session): void
    {
        $session->allTranscripts()
            ->whereNotNull('provider_job_id')
            ->whereNull('provider_deleted_at')
            ->get()
            ->each(fn (Transcript $transcript): bool => $this->erase($transcript));
    }

    /** Deletes one provider copy now; on failure queues a retry. Returns whether the provider confirmed. */
    public function erase(Transcript $transcript): bool
    {
        $provider = $transcript->providerKey();
        $jobId = $transcript->provider_job_id;
        if ($provider === null || $jobId === null || $jobId === '') {
            return true;
        }

        try {
            $this->stt->driver($provider)->deleteRemote($transcript);

            return true;
        } catch (Throwable $e) {
            report($e);
            $this->schedule($provider, $jobId, $transcript->provider_transcript_id, $e->getMessage());

            return false;
        }
    }

    /** Queues (or re-queues) a provider copy for deletion by stt:reconcile. */
    public function schedule(SttProviderKey $provider, string $jobId, ?string $providerTranscriptId, ?string $error = null): void
    {
        $deletion = ProviderDeletion::query()->firstOrNew(['provider' => $provider->value, 'provider_job_id' => mb_substr($jobId, 0, 128)]);
        $deletion->fill([
            'provider_transcript_id' => $providerTranscriptId !== null ? mb_substr($providerTranscriptId, 0, 128) : $deletion->provider_transcript_id,
            'last_error' => $error !== null ? self::safeError($error) : $deletion->last_error,
            'next_attempt_at' => now()->addMinutes(self::FIRST_RETRY_MINUTES),
        ])->save();
    }

    /**
     * stt:reconcile: retries the deletions that are due, doubling the wait after each failure (5 min up to 6 h) and
     * giving up (with a log entry) once a copy has resisted for 7 days. Returns the number of copies deleted.
     */
    public function retryDue(int $limit = 50): int
    {
        $deleted = 0;
        ProviderDeletion::query()
            ->where('next_attempt_at', '<=', now())
            ->orderBy('next_attempt_at')
            ->limit($limit)
            ->get()
            ->each(function (ProviderDeletion $deletion) use (&$deleted): void {
                $provider = SttProviderKey::tryFrom($deletion->provider);
                if ($provider === null) {
                    $deletion->delete();

                    return;
                }
                // The transcript row is gone; the driver needs only the provider ids.
                $transient = (new Transcript)->forceFill([
                    'provider' => $provider->value,
                    'provider_job_id' => $deletion->provider_job_id,
                    'provider_transcript_id' => $deletion->provider_transcript_id,
                ]);
                try {
                    $this->stt->driver($provider)->deleteRemote($transient);
                    $deletion->delete();
                    $deleted++;
                } catch (Throwable $e) {
                    $this->backOff($deletion, $e);
                }
            });

        return $deleted;
    }

    private function backOff(ProviderDeletion $deletion, Throwable $e): void
    {
        if ($deletion->created_at->lte(now()->subDays(self::GIVE_UP_DAYS))) {
            logger()->warning('Gave up deleting a provider copy', [
                'provider' => $deletion->provider, 'job' => $deletion->provider_job_id, 'attempts' => $deletion->attempts + 1,
                'error' => self::safeError($e->getMessage()),
            ]);
            $deletion->delete();

            return;
        }

        $attempts = $deletion->attempts + 1;
        $wait = min(self::MAX_BACKOFF_MINUTES, self::FIRST_RETRY_MINUTES * (2 ** $attempts));
        $deletion->update([
            'attempts' => $attempts,
            'last_error' => self::safeError($e->getMessage()),
            'next_attempt_at' => now()->addMinutes($wait),
        ]);
    }

    /** Error text without links or key-like strings, short enough for a log line. */
    private static function safeError(string $error): string
    {
        $clean = (string) preg_replace('#\b[a-z][a-z0-9+.-]*://\S+#i', '[link]', $error);

        return mb_substr((string) preg_replace('/[A-Za-z0-9_\-]{32,}/', '[hidden]', $clean), 0, 500);
    }
}
