<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\Transcript;
use App\Services\SettingsRepository;
use App\Services\TranscriptExporter;
use App\Services\Views\SoundCheck;
use App\Services\Views\TranscriptViews;
use App\Services\Workspaces\CurrentWorkspace;
use Illuminate\Console\Command;

/**
 * Do the views keep every word? (since v1.5, Phase 1C, E2). For the latest recordings whose views are made, it counts per
 * recording and in total the view lines that differ from the original word for word: a word whose sound changed
 * ("تعل" → "dekh"), dropped or added (App\Services\Views\SoundCheck). Only the transliterations are compared (Roman
 * Urdu, and اردو of an Urdu call); a translation rewords by nature. Read-only: no AI calls, nothing written.
 */
final class ViewsCheck extends Command
{
    protected $signature = 'echoledger:views-check {--limit=20 : How many recordings with views, the latest first}';

    protected $description = 'Count the view lines that differ from the original word for word (read-only)';

    /** Lines listed per recording that has some. */
    private const LINES_SHOWN = 5;

    public function handle(TranscriptViews $views, SettingsRepository $settings): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $zone = $settings->displayTimezone();
        $checked = [];
        CurrentWorkspace::bypass(function () use ($views, $limit, &$checked): void {
            $candidates = Transcript::query()->where('kind', TranscriptKind::Final->value)->where('status', TranscriptStatus::Completed->value)
                ->whereHas('captureSession')->with('captureSession')->orderByDesc('completed_at')->orderByDesc('id');
            foreach ($candidates->lazy(100) as $transcript) {
                if (count($checked) >= $limit) {
                    break;
                }
                $language = TranscriptViews::sourceLanguage($transcript);
                $compared = array_values(array_filter(TranscriptViews::available($transcript), fn (string $v): bool => TranscriptViews::isTransliteration($v, $language)));
                $texts = [];
                foreach ($compared as $view) {
                    $text = $views->textsFor($transcript, $view);
                    if ($text !== null) {
                        $texts[$view] = $text;
                    }
                }
                if ($texts !== []) {
                    $checked[] = [$transcript, self::compare($transcript->segments ?? [], $texts)];
                }
            }
        });

        if ($checked === []) {
            $this->warn('No recording with a Roman Urdu or اردو view yet.');

            return self::SUCCESS;
        }
        $rows = [];
        $total = ['lines' => 0, 'differ' => 0, 'words' => 0];
        foreach ($checked as [$transcript, $check]) {
            $session = $transcript->captureSession;
            $rows[] = [
                mb_strimwidth($session->displayTitle(), 0, 40, '…'),
                $session->started_at->copy()->setTimezone($zone)->format('M j, g:i A'),
                implode(', ', array_keys($check['views'])),
                $check['lines'],
                $check['differ'].' ('.self::percent($check['lines'] > 0 ? $check['differ'] / $check['lines'] : 0.0).')',
                $check['words'],
            ];
            foreach ($total as $key => $value) {
                $total[$key] = $value + $check[$key];
            }
        }
        $this->table(['Recording', 'Started', 'Views', 'View lines', 'Lines that differ', 'Words changed, dropped or added'], $rows);
        foreach ($checked as $i => [$transcript, $check]) {
            if ($check['examples'] === []) {
                continue;
            }
            $this->line($rows[$i][0].':');
            foreach ($check['examples'] as $line) {
                $this->line(sprintf('  %s line %d at %s: %d words differ', $line['view'], $line['index'] + 1, TranscriptExporter::clock($line['start_ms']), $line['words']));
            }
        }
        $this->newLine();
        $this->line(sprintf('Total: %d of %d view lines differ from the original word for word (%s) in %d recordings, %d words.',
            $total['differ'], $total['lines'], self::percent($total['lines'] > 0 ? $total['differ'] / $total['lines'] : 0.0), count($checked), $total['words']));
        $this->line($total['differ'] === 0
            ? 'The views keep every word of the original.'
            : 'Views made before this check keep their old lines until they are made again (an edit, or Show as on a new transcript).');

        return self::SUCCESS;
    }

    /**
     * Per view line: whether it differs from the original word for word, and how many words. Pure.
     *
     * @param  list<array<string, mixed>>  $segments
     * @param  array<string, list<string|null>>  $views  view => its lines, aligned with the segments
     * @return array{views: array<string, int>, lines: int, differ: int, words: int, examples: list<array{view: string, index: int, start_ms: int, words: int}>}
     */
    public static function compare(array $segments, array $views): array
    {
        $out = ['views' => [], 'lines' => 0, 'differ' => 0, 'words' => 0, 'examples' => []];
        foreach ($views as $view => $lines) {
            $out['views'][$view] = 0;
            foreach ($segments as $i => $segment) {
                $source = (string) ($segment['text'] ?? '');
                $text = $lines[$i] ?? null;
                if (SoundCheck::words($source) === [] || ! is_string($text)) {
                    continue;
                }
                $out['lines']++;
                $words = count(SoundCheck::failing($source, $text)) + SoundCheck::added($source, $text);
                if ($words > 0) {
                    $out['differ']++;
                    $out['views'][$view]++;
                    $out['words'] += $words;
                    if (count($out['examples']) < self::LINES_SHOWN) {
                        $out['examples'][] = ['view' => TranscriptViews::VIEWS[$view] ?? $view, 'index' => (int) $i, 'start_ms' => (int) ($segment['start_ms'] ?? 0), 'words' => $words];
                    }
                }
            }
        }

        return $out;
    }

    private static function percent(float $share): string
    {
        return number_format($share * 100, 1).' %';
    }
}
