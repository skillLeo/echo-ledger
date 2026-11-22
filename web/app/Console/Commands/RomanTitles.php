<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\CaptureSession;
use App\Models\Transcript;
use App\Services\Titles\AutoTitles;
use Illuminate\Console\Command;

/**
 * Since U19: the automatic titles made in Urdu letters before titles followed the Roman Urdu view. Each recording that
 * opens in Roman Urdu, whose Roman Urdu view is made and whose title is still an automatic one in Urdu letters, gets its
 * title written again in Roman Urdu (AutoTitles::afterViews). A title a person gave is never touched, nor a call whose
 * person is known (it shows them). A real run asks the title writer once per recording, which is billed like any
 * automatic title; `--from-view-only` takes the Roman Urdu view's first words instead, with no model call. `--dry-run`
 * counts them and changes nothing; the titles themselves are never printed (they are people's words).
 */
final class RomanTitles extends Command
{
    protected $signature = 'echoledger:roman-titles
        {--dry-run : Count the titles that would change; change nothing}
        {--from-view-only : Use the first words of the Roman Urdu view, with no call to the title writer (nothing billed)}
        {--user= : Only this person\'s recordings (their id)}
        {--limit= : Change at most this many recordings}';

    protected $description = 'Write automatic titles still in Urdu letters again in Roman Urdu, for recordings that open in Roman Urdu';

    public function handle(AutoTitles $titles): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $fromView = (bool) $this->option('from-view-only');
        $limit = is_numeric($this->option('limit')) ? max(1, (int) $this->option('limit')) : null;
        $user = is_numeric($this->option('user')) ? (int) $this->option('user') : null;
        $found = 0;
        $changed = 0;
        $query = CaptureSession::query()->withoutGlobalScopes()->whereNull('deleted_at')
            ->where('title_auto', true)->where('title_by_person', false)->whereNotNull('title')
            ->when($user !== null, fn ($q) => $q->where('user_id', $user));
        foreach ($query->lazyById(200, 'id') as $session) {
            if ($limit !== null && $found >= $limit) {
                break;
            }
            // A call whose person is known shows them, not this title.
            if (! AutoTitles::wantsRoman($session) || $session->personTitle() !== null) {
                continue;
            }
            $final = Transcript::query()->where('capture_session_id', $session->id)->where('kind', TranscriptKind::Final->value)
                ->where('status', TranscriptStatus::Completed->value)->first();
            if ($final === null || ! AutoTitles::opensInRoman($final) || AutoTitles::romanText($final) === '') {
                continue;
            }
            $found++;
            if (! $dryRun) {
                $titles->afterViews($final, askWriter: ! $fromView);
                $changed += AutoTitles::hasUrduLetters((string) $session->refresh()->title) ? 0 : 1;
            }
        }
        $word = $found === 1 ? 'title' : 'titles';
        $cost = $fromView ? 'With --from-view-only each one is the Roman Urdu view\'s first words: nothing is billed.'
            : 'A real run asks the title writer once for each one, billed to that person\'s usage like any automatic title; --from-view-only takes the view\'s first words instead.';
        $this->line($dryRun ? "Would write {$found} {$word} again in Roman Urdu. Dry run: nothing was changed." : "Wrote {$changed} of {$found} {$word} again in Roman Urdu.");
        $this->line($cost);

        return self::SUCCESS;
    }
}
