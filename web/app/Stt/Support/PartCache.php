<?php

declare(strict_types=1);

namespace App\Stt\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * The engine's answers for the parts of one transcript that are done (since v1.5), so a run that has to wait for the
 * engine's limits (App\Stt\GroqPacer) goes on where it stopped instead of sending, and paying for, the same audio again.
 * Encrypted with the app key; dropped when the transcript is made, otherwise after two days.
 */
final class PartCache
{
    public const KEEP_HOURS = 48;

    /** @return array<string, mixed>|null */
    public function get(string $transcriptId, string $part): ?array
    {
        $stored = Cache::get(self::key($transcriptId, $part));
        if (! is_string($stored)) {
            return null;
        }
        try {
            $answer = json_decode(Crypt::decryptString($stored), true);
        } catch (Throwable) {
            return null; // another app key: ask the engine again
        }

        return is_array($answer) ? $answer : null;
    }

    /** @param  array<string, mixed>  $answer */
    public function put(string $transcriptId, string $part, array $answer): void
    {
        $until = now()->addHours(self::KEEP_HOURS);
        $index = Cache::get(self::indexKey($transcriptId));
        $index = is_array($index) ? $index : [];
        $index[] = self::key($transcriptId, $part);
        Cache::put(self::indexKey($transcriptId), array_values(array_unique($index)), $until);
        Cache::put(self::key($transcriptId, $part), Crypt::encryptString((string) json_encode($answer)), $until);
    }

    public function forget(string $transcriptId): void
    {
        $index = Cache::pull(self::indexKey($transcriptId));
        foreach (is_array($index) ? $index : [] as $key) {
            Cache::forget((string) $key);
        }
    }

    private static function key(string $transcriptId, string $part): string
    {
        return 'stt-part:'.$transcriptId.':'.sha1($part);
    }

    private static function indexKey(string $transcriptId): string
    {
        return 'stt-parts:'.$transcriptId;
    }
}
