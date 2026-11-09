<?php

declare(strict_types=1);

namespace App\Services\Titles;

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\CaptureSession;
use App\Models\Transcript;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Automatic titles for recordings made before them (since v1.6, P5.3; `php artisan echoledger:backfill-titles`). A
 * recording whose title nobody chose (GenericTitles) and whose transcript is ready gets the transcript's first words as
 * its title, marked automatic; a call with a known person drops its generic title so it shows "Call with Omar Khan"
 * again. A title a person set is never touched. No migration and no AI: the first words only, so it costs nothing.
 *
 * Every change is written to a log on the local disk (`title-backfills/<time>.json`: the recording, its title before,
 * and a hash of the new one, never the transcript's words) so `--undo=<log>` puts each title back, unless it was
 * changed again since. Running it twice changes nothing the second time.
 */
final class TitleBackfill
{
    public const LOG_DIRECTORY = 'title-backfills';

    /** Recordings read per database query. */
    private const CHUNK = 200;

    /**
     * The changes a run would make (or makes, without [$dryRun]), oldest recording first (ids are ULIDs).
     *
     * @return array{changes: list<array{id: string, user_id: int, before: string|null, before_auto: bool, after: string|null, action: string}>, skipped: array<string, int>, log: string|null}
     */
    public function run(bool $dryRun, ?int $userId = null, ?int $limit = null): array
    {
        $changes = [];
        $skipped = ['no_transcript' => 0, 'no_words' => 0, 'caller_shown' => 0, 'changed_meanwhile' => 0];
        $query = CaptureSession::query()->withoutGlobalScopes()->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('title')->orWhere('title', '')->orWhere('title_auto', false))
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId));

        foreach ($query->lazyById(self::CHUNK, 'id') as $session) {
            if ($limit !== null && count($changes) >= $limit) {
                break;
            }
            if (! GenericTitles::is($session->title)) {
                continue;
            }
            $plan = $this->plan($session);
            if (is_string($plan)) {
                $skipped[$plan]++;

                continue;
            }
            if (! $dryRun && ! $this->apply($session, $plan['after'])) {
                $skipped['changed_meanwhile']++;

                continue;
            }
            $changes[] = ['id' => $session->id, 'user_id' => (int) $session->user_id, 'before' => $session->title, 'before_auto' => (bool) $session->title_auto, ...$plan];
        }

        return ['changes' => $changes, 'skipped' => $skipped, 'log' => $dryRun || $changes === [] ? null : $this->writeLog($changes)];
    }

    /**
     * Puts back the titles a run's log names, where each still is what that run wrote.
     *
     * @return array{restored: int, kept: int}
     */
    public function undo(string $log): array
    {
        $entries = $this->readLog($log);
        $restored = 0;
        $kept = 0;
        foreach ($entries as $entry) {
            $session = CaptureSession::query()->withoutGlobalScopes()->withTrashed()->find($entry['id']);
            if ($session === null || ! self::isWhatRunWrote($session, $entry)) {
                $kept++;

                continue;
            }
            $session->forceFill(['title' => $entry['before'], 'title_auto' => $entry['before_auto']])->saveQuietly();
            $restored++;
        }

        return ['restored' => $restored, 'kept' => $kept];
    }

    /**
     * What one recording gets, or why it is left as it is.
     *
     * @return array{after: string|null, action: string}|string
     */
    private function plan(CaptureSession $session): array|string
    {
        // A call whose person or meeting is known shows that name when it has no title ("Call with Omar Khan").
        $untitled = clone $session;
        $untitled->title = null;
        if ($untitled->displayTitle() !== CaptureSession::fallbackTitleFor($session->source_app)) {
            return $session->title === null || trim($session->title) === '' ? 'caller_shown' : ['after' => null, 'action' => 'cleared'];
        }
        $final = Transcript::query()->where('capture_session_id', $session->id)->where('kind', TranscriptKind::Final->value)
            ->where('status', TranscriptStatus::Completed->value)->first();
        if ($final === null) {
            return 'no_transcript';
        }
        $title = AutoTitles::firstWords(AutoTitles::plainText($final));

        return $title === '' ? 'no_words' : ['after' => $title, 'action' => 'titled'];
    }

    /** Writes the new title only while the recording still has the title that was read. */
    private function apply(CaptureSession $session, ?string $after): bool
    {
        $before = $session->title;
        $saved = CaptureSession::query()->withoutGlobalScopes()->whereKey($session->id)
            ->where(fn ($q) => $before === null ? $q->whereNull('title') : $q->where('title', $before))
            ->update(['title' => $after, 'title_auto' => $after !== null, 'updated_at' => now()]);

        return $saved > 0;
    }

    /** @param  array{after_sha256: string|null}  $entry */
    private static function isWhatRunWrote(CaptureSession $session, array $entry): bool
    {
        if ($entry['after_sha256'] === null) {
            return $session->title === null || $session->title === '';
        }

        return $session->title !== null && hash_equals($entry['after_sha256'], hash('sha256', $session->title)) && $session->title_auto;
    }

    /**
     * @param  list<array{id: string, user_id: int, before: string|null, before_auto: bool, after: string|null, action: string}>  $changes
     * @return string the log's path on the local disk
     */
    private function writeLog(array $changes): string
    {
        $path = self::LOG_DIRECTORY.'/'.now()->utc()->format('Ymd-His').'-'.bin2hex(random_bytes(3)).'.json';
        $entries = array_map(fn (array $c): array => [
            'id' => $c['id'],
            'action' => $c['action'],
            'before' => $c['before'],
            'before_auto' => $c['before_auto'],
            // The new title came from the transcript: only its hash is kept, to know it was not changed since.
            'after_sha256' => $c['after'] === null ? null : hash('sha256', $c['after']),
        ], $changes);
        Storage::disk('local')->put($path, (string) json_encode(['made_at' => now()->utc()->toIso8601String(), 'changes' => $entries], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    /** @return list<array{id: string, before: string|null, before_auto: bool, after_sha256: string|null}> */
    private function readLog(string $log): array
    {
        // Only a log this command wrote, by its name in the log folder.
        $name = basename($log);
        $path = self::LOG_DIRECTORY.'/'.$name;
        if (preg_match('/^\d{8}-\d{6}-[0-9a-f]{6}\.json$/', $name) !== 1 || ! Storage::disk('local')->exists($path)) {
            throw new RuntimeException("No title backfill log called {$name} in ".self::LOG_DIRECTORY.'.');
        }
        $data = json_decode((string) Storage::disk('local')->get($path), true);
        if (! is_array($data) || ! is_array($data['changes'] ?? null)) {
            throw new RuntimeException("{$name} is not a title backfill log.");
        }
        $entries = [];
        foreach ($data['changes'] as $entry) {
            if (is_array($entry) && is_string($entry['id'] ?? null)) {
                $entries[] = [
                    'id' => $entry['id'],
                    'before' => is_string($entry['before'] ?? null) ? $entry['before'] : null,
                    'before_auto' => (bool) ($entry['before_auto'] ?? false),
                    'after_sha256' => is_string($entry['after_sha256'] ?? null) ? $entry['after_sha256'] : null,
                ];
            }
        }

        return $entries;
    }
}
