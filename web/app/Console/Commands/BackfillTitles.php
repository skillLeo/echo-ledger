<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Titles\TitleBackfill;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Automatic titles for older recordings (since v1.6, P5.3): "Voice note", "WhatsApp call", a file's name or no title
 * become the transcript's first words; a title a person set is never touched. `--dry-run` lists what would change,
 * every real run writes a log, and `--undo=<log>` puts the titles back. The new titles are shown only with
 * `--show-titles` (they are people's words).
 */
final class BackfillTitles extends Command
{
    protected $signature = 'echoledger:backfill-titles
        {--dry-run : List what would change; change nothing}
        {--user= : Only this person\'s recordings (an id or an email)}
        {--limit= : Change at most this many recordings}
        {--show-titles : Show the new titles, not only how long they are}
        {--undo= : Put back the titles a run changed (its log\'s name, from title-backfills/)}';

    protected $description = 'Give recordings without a real title an automatic one from the first words of their transcript (reversible)';

    public function handle(TitleBackfill $backfill): int
    {
        if (is_string($undo = $this->option('undo')) && $undo !== '') {
            return $this->undo($backfill, $undo);
        }
        $userId = $this->userId();
        if ($userId === false) {
            $this->error('No such person: '.$this->option('user'));

            return self::FAILURE;
        }
        $limit = is_numeric($this->option('limit')) ? max(1, (int) $this->option('limit')) : null;
        $dryRun = (bool) $this->option('dry-run');

        $result = $backfill->run($dryRun, $userId, $limit);
        $this->table(['Recording', 'Person', 'Title before', 'Becomes'], array_map(fn (array $c): array => [
            $c['id'], $c['user_id'], $c['before'] === null || $c['before'] === '' ? '(none)' : $c['before'],
            match (true) {
                $c['after'] === null => '(none: shows the caller\'s name)',
                (bool) $this->option('show-titles') => $c['after'],
                default => 'first words ('.mb_strlen($c['after']).' characters)',
            },
        ], $result['changes']));
        $count = count($result['changes']);
        $skipped = $result['skipped'];
        $this->line(sprintf('%s %d %s. Left alone: %d without a finished transcript, %d with no words, %d showing the caller\'s name%s.',
            $dryRun ? 'Would change' : 'Changed', $count, $count === 1 ? 'recording' : 'recordings',
            $skipped['no_transcript'], $skipped['no_words'], $skipped['caller_shown'],
            $skipped['changed_meanwhile'] > 0 ? ", {$skipped['changed_meanwhile']} renamed while this ran" : ''));
        if ($dryRun) {
            $this->info('Dry run: nothing was changed.');
        } elseif ($result['log'] !== null) {
            $this->info('Log: '.$result['log']);
            $this->info('To undo: php artisan echoledger:backfill-titles --undo='.basename($result['log']));
        }
        // Counts only: never a title or a transcript's words in the log.
        Log::info('Title backfill', ['dry_run' => $dryRun, 'changed' => $count, 'skipped' => $skipped, 'log' => $result['log']]);

        return self::SUCCESS;
    }

    private function undo(TitleBackfill $backfill, string $log): int
    {
        try {
            $result = $backfill->undo($log);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("Put back {$result['restored']} titles. Left {$result['kept']} that were changed again since (or deleted).");
        Log::info('Title backfill undone', ['log' => basename($log), ...$result]);

        return self::SUCCESS;
    }

    /** @return int|null|false the person's id, null for everyone, false when the person is unknown */
    private function userId(): int|null|false
    {
        $user = $this->option('user');
        if (! is_string($user) || $user === '') {
            return null;
        }
        $found = ctype_digit($user) ? User::query()->find((int) $user) : User::query()->where('email', $user)->first();

        return $found instanceof User ? (int) $found->id : false;
    }
}
