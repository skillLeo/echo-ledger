<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Upload retries (contract 2.0.0-r, BE-03). The devices hold the files, so the server only says when they retry: the
 * schedule in GET /api/config (`upload_retry`), and Retry all, a time on the person the apps read with the Activity
 * list (`retry_requested_at`): each app that sees it newer than its own last run retries every failed upload it holds,
 * once. The web console's Retry all asks the same way.
 */
final class UploadRetries
{
    /** 1, 5 and 30 minutes after a failure, then on a network change or when the app starts. */
    public const AFTER_SECONDS = [60, 300, 1800];

    public const THEN = ['network_change', 'app_start'];

    /** @return array{after_seconds: list<int>, then: list<string>} */
    public static function schedule(): array
    {
        return ['after_seconds' => self::AFTER_SECONDS, 'then' => self::THEN];
    }

    /**
     * Asks [$user]'s devices to retry every failed upload they hold.
     *
     * @return array{retry_requested_at: string, count: int} count: the person's unresolved upload failures
     */
    public function requestAll(User $user): array
    {
        $at = Carbon::now();
        $user->forceFill(['retry_requested_at' => $at])->save();

        return ['retry_requested_at' => $at->toAtomString(), 'count' => self::failedCount($user)];
    }

    public static function requestedAt(User $user): ?string
    {
        $at = $user->getAttribute('retry_requested_at');

        return $at instanceof Carbon ? $at->toAtomString() : null;
    }

    /** The person's upload failures not resolved yet (one item per recording, BE-02). */
    public static function failedCount(User $user): int
    {
        return $user->notifications()->where('dedupe_key', 'like', 'upload_failed:%')->whereNull('resolved_at')->count();
    }
}
