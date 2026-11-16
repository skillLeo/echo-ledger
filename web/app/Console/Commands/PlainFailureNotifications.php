<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TranscriptErrorCode;
use App\Models\CaptureSession;
use App\Services\ActivityFeed;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Since v1.8.1 (D-131): a "Transcription failed" notification sent before it said why in the engine's own words
 * ("Call with Omar: The speech provider could not transcribe this recording: Gemini could not read the audio."). This
 * rewrites each such notification in plain words per error code (ActivityFeed::failedBody), from the recording's name
 * and its final transcript's error code; one whose recording is gone keeps only the plain reason.
 *
 * Safe to run any number of times: a notification whose reason already is one of the plain sentences (as ActivityFeed
 * writes it since v1.8.1, and as every earlier run left it) is left alone, so a newer failure's words are never
 * replaced. The migration runs it once; run it again after `php artisan queue:restart`, so a notification a worker
 * still running the old code sent meanwhile is rewritten too.
 */
final class PlainFailureNotifications extends Command
{
    protected $signature = 'echoledger:plain-failure-notifications {--dry-run : Count what would change; change nothing}';

    protected $description = 'Rewrite "Transcription failed" notifications sent before v1.8.1 in plain words (safe to run again)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $changed = self::rewrite($dryRun);
        $this->line(sprintf('%s %d %s.', $dryRun ? 'Would rewrite' : 'Rewrote', $changed, $changed === 1 ? 'notification' : 'notifications'));

        return self::SUCCESS;
    }

    /** Rewrites the notifications not yet in plain words and returns how many (or would, with [$dryRun]). */
    public static function rewrite(bool $dryRun = false): int
    {
        $changed = 0;
        DB::table('notifications')->where('data->viewData->kind', 'transcript_failed')->lazyById(200, 'id')->each(function (object $row) use ($dryRun, &$changed): void {
            $data = json_decode((string) $row->data, true);
            if (! is_array($data)) {
                return;
            }
            $viewData = is_array($data['viewData'] ?? null) ? $data['viewData'] : [];
            if (self::plain(is_string($data['body'] ?? null) ? $data['body'] : '', ($viewData['escaped'] ?? false) === true)) {
                return;
            }
            $changed++;
            if ($dryRun) {
                return;
            }
            $sessionId = $viewData['session_id'] ?? null;
            $session = is_string($sessionId) ? CaptureSession::query()->withoutGlobalScopes()->find($sessionId) : null;
            $code = $session instanceof CaptureSession
                ? DB::table('transcripts')->where('capture_session_id', $session->id)->where('kind', 'final')->value('error_code') : null;
            $body = $session instanceof CaptureSession
                ? ActivityFeed::failedBody($session->displayTitle(), is_string($code) ? $code : null)
                : TranscriptErrorCode::plainWords(null);
            // Written as ActivityFeed writes it: escaped, and marked so.
            $data['body'] = e($body);
            $data['viewData'] = [...$viewData, 'escaped' => true];
            DB::table('notifications')->where('id', $row->id)->update(['data' => json_encode($data)]);
        });

        return $changed;
    }

    /** True when [$body] already says why in plain words: "<recording>: <plain sentence>", or the sentence alone. */
    private static function plain(string $body, bool $escaped): bool
    {
        $text = $escaped ? htmlspecialchars_decode($body, ENT_QUOTES) : $body;
        foreach ([null, ...TranscriptErrorCode::cases()] as $code) {
            $sentence = TranscriptErrorCode::plainWords($code);
            if ($text === $sentence || str_ends_with($text, ': '.$sentence)) {
                return true;
            }
        }

        return false;
    }
}
