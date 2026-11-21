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
 * title written again in Roman Urdu (AutoTitles::afterViews). A title a person gave is never touched. `--dry-run`
 * counts them and changes nothing; the titles themselves are never printed (they are people's words).
 */
final class RomanTitles extends Command
{
    protected $signature = 'echoledger:roman-titles
        {--dry-run : Count the titles that would change; change nothing}
        {--user= : Only this person\'s recordings (their id)}
        {--limit= : Change at most this many recordings}';

    protected $description = 'Write automatic titles still in Urdu letters again in Roman Urdu, for recordings that open in Roman Urdu';

    public function handle(AutoTitles $titles): int
    {
        $dryRun = (bool) $this->option('dry-run');
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
            if (! AutoTitles::wantsRoman($session)) {
                continue;
            }
            $final = Transcript::query()->where('capture_session_id', $session->id)->where('kind', TranscriptKind::Final->value)
                ->where('status', TranscriptStatus::Completed->value)->first();
            if ($final === null || ! AutoTitles::opensInRoman($final) || AutoTitles::romanText($final) === '') {
                continue;
            }
            $found++;
            if (! $dryRun) {
                $titles->afterViews($final);
                $changed += AutoTitles::hasUrduLetters((string) $session->refresh()->title) ? 0 : 1;
            }
        }
        $this->line($dryRun
            ? sprintf('Would write %d %s again in Roman Urdu. Dry run: nothing was changed.', $found, $found === 1 ? 'title' : 'titles')
            : sprintf('Wrote %d of %d %s again in Roman Urdu.', $changed, $found, $found === 1 ? 'title' : 'titles'));

        return self::SUCCESS;
    }
}
