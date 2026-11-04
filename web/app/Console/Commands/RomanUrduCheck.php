<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\Transcript;
use App\Services\SettingsRepository;
use App\Services\TranscriptExporter;
use App\Services\Views\TranscriptViews;
use App\Services\Workspaces\CurrentWorkspace;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Does the Roman Urdu view keep every word of the original? (since v1.5, plan 1.4f). For the latest Urdu and Auto
 * recordings with a Roman Urdu view it reports the words of the original transcript and of the view, the share of
 * lines whose word counts differ by more than 15 %, the lines with words dropped or added, and a verdict per recording
 * and overall. Read-only; with --generate the missing views are made first (Claude, billed like Show as).
 */
final class RomanUrduCheck extends Command
{
    protected $signature = 'echoledger:roman-urdu-check
        {--limit=20 : How many recordings, the latest first}
        {--generate : Make the Roman Urdu view of the recordings that have none (Claude, billed)}';

    protected $description = 'Check that the Roman Urdu view keeps the words of the original transcript';

    /** A line is off when its word counts are further apart than this share of the original's. */
    public const LINE_TOLERANCE = 0.15;

    /** Lines shown per recording that is not OK. */
    private const WORST_LINES = 5;

    public function handle(TranscriptViews $views, SettingsRepository $settings): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $generate = (bool) $this->option('generate');
        if ($generate && ! $views->configured()) {
            $this->error(TranscriptViews::missingKeyMessage());

            return self::FAILURE;
        }
        $zone = $settings->displayTimezone();
        $checked = [];
        $missing = 0;
        CurrentWorkspace::bypass(function () use ($views, $limit, $generate, &$checked, &$missing): void {
            $candidates = Transcript::query()->where('kind', TranscriptKind::Final->value)->where('status', TranscriptStatus::Completed->value)
                ->where(fn (Builder $q) => $q->whereNull('language')->orWhere('language', 'ur'))
                ->whereHas('captureSession')->with('captureSession')
                ->orderByDesc('completed_at')->orderByDesc('id');
            foreach ($candidates->lazy(100) as $transcript) {
                if (count($checked) >= $limit) {
                    break;
                }
                // An Auto call that came out English has no Roman Urdu view.
                if (! in_array('roman', TranscriptViews::available($transcript), true)) {
                    continue;
                }
                $roman = $views->textsFor($transcript, 'roman');
                if ($roman === null && $generate) {
                    $views->make($transcript);
                    $roman = $views->textsFor($transcript->refresh(), 'roman');
                }
                if ($roman === null) {
                    $missing++;

                    continue;
                }
                $checked[] = [$transcript, self::compare($transcript->segments ?? [], $roman)];
            }
        });

        if ($checked === []) {
            $this->warn('No Urdu or Auto recording with a Roman Urdu view yet.'.($missing > 0 ? " {$missing} have none: run with --generate to make them." : ''));

            return self::SUCCESS;
        }
        $rows = [];
        $verdicts = [];
        foreach ($checked as [$transcript, $check]) {
            $verdict = self::verdict($check);
            $verdicts[] = $verdict;
            $session = $transcript->captureSession;
            $rows[] = [
                mb_strimwidth($session->displayTitle(), 0, 40, '…'),
                $session->started_at->copy()->setTimezone($zone)->format('M j, g:i A'),
                $check['original'],
                $check['roman'],
                self::percent(self::difference($check)),
                $check['off'].' of '.$check['lines'].' ('.self::percent($check['lines'] > 0 ? $check['off'] / $check['lines'] : 0.0).')',
                $check['dropped'],
                $check['added'],
                $verdict,
            ];
        }
        $this->table(['Recording', 'Started', 'Original words', 'Roman words', 'Difference', 'Lines off by >15 %', 'Lines with fewer words', 'Lines with more words', 'Verdict'], $rows);
        foreach ($checked as $i => [$transcript, $check]) {
            if ($verdicts[$i] === 'OK' || $check['worst'] === []) {
                continue;
            }
            $this->line($rows[$i][0].':');
            foreach ($check['worst'] as $line) {
                $this->line(sprintf('  line %d at %s: %d words in the original, %d in Roman Urdu', $line['index'] + 1, TranscriptExporter::clock($line['start_ms']), $line['original'], $line['roman']));
            }
        }

        $counts = array_count_values($verdicts);
        $overall = isset($counts['Problem']) ? 'Problem' : (isset($counts['Check']) ? 'Check' : 'OK');
        $this->newLine();
        $this->line(sprintf('Overall: %s (%d recordings: %d OK, %d to check, %d with a problem).', $overall, count($verdicts), $counts['OK'] ?? 0, $counts['Check'] ?? 0, $counts['Problem'] ?? 0));
        $this->line(match ($overall) {
            'OK' => 'The Roman Urdu view keeps the words of the original.',
            'Check' => 'Some lines lose or gain words: read the lines above against the original.',
            default => 'The Roman Urdu view loses or adds words: read the lines above against the original before relying on it.',
        });
        if ($missing > 0) {
            $this->line("{$missing} recent Urdu or Auto recordings have no Roman Urdu view yet: run with --generate to make and check them.");
        }

        return self::SUCCESS;
    }

    /**
     * The words of each line of the original and of its Roman Urdu view, the lines off by more than 15 %, the lines with
     * fewer or more words, and the lines furthest apart. Only tokens with a letter or a digit count as words. Pure.
     *
     * @param  list<array<string, mixed>>  $segments
     * @param  list<string|null>  $roman  the view, line by line, aligned with the segments
     * @return array{original: int, roman: int, lines: int, off: int, dropped: int, added: int, worst: list<array{index: int, start_ms: int, original: int, roman: int}>}
     */
    public static function compare(array $segments, array $roman): array
    {
        $out = ['original' => 0, 'roman' => 0, 'lines' => 0, 'off' => 0, 'dropped' => 0, 'added' => 0, 'worst' => []];
        $apart = [];
        foreach ($segments as $i => $segment) {
            $original = self::words((string) ($segment['text'] ?? ''));
            if ($original === 0) {
                continue;
            }
            $view = self::words((string) ($roman[$i] ?? ''));
            $out['original'] += $original;
            $out['roman'] += $view;
            $out['lines']++;
            $share = abs($view - $original) / $original;
            if ($share > self::LINE_TOLERANCE) {
                $out['off']++;
            }
            if ($view < $original) {
                $out['dropped']++;
            } elseif ($view > $original) {
                $out['added']++;
            }
            if ($view !== $original) {
                $apart[] = ['share' => $share, 'line' => ['index' => $i, 'start_ms' => (int) ($segment['start_ms'] ?? 0), 'original' => $original, 'roman' => $view]];
            }
        }
        usort($apart, fn (array $a, array $b): int => $b['share'] <=> $a['share']);
        $out['worst'] = array_column(array_slice($apart, 0, self::WORST_LINES), 'line');

        return $out;
    }

    /**
     * OK: the view's words are within 5 % of the original's and at most 5 % of the lines are off; Check: within 10 % and
     * 15 %; Problem otherwise.
     *
     * @param  array{original: int, roman: int, lines: int, off: int}  $check
     */
    public static function verdict(array $check): string
    {
        $share = $check['lines'] > 0 ? $check['off'] / $check['lines'] : 0.0;
        $difference = self::difference($check);

        return match (true) {
            $difference <= 0.05 && $share <= 0.05 => 'OK',
            $difference <= 0.10 && $share <= 0.15 => 'Check',
            default => 'Problem',
        };
    }

    /** @param  array{original: int, roman: int}  $check */
    private static function difference(array $check): float
    {
        return $check['original'] > 0 ? abs($check['roman'] - $check['original']) / $check['original'] : 0.0;
    }

    private static function words(string $text): int
    {
        return count(array_filter(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [], fn (string $w): bool => preg_match('/[\p{L}\p{N}]/u', $w) === 1));
    }

    private static function percent(float $share): string
    {
        return number_format($share * 100, 1).' %';
    }
}
