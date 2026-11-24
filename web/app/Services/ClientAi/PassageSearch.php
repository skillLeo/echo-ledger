<?php

declare(strict_types=1);

namespace App\Services\ClientAi;

use App\Enums\TranscriptStatus;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\ClientFile;
use App\Services\SettingsRepository;
use Closure;

/**
 * The passages of a client's transcripts and files that best match a question (v1.4, Ask): windows of 8 transcript
 * lines and paragraphs of files, scored by the question's words. Only these, not everything, go to Claude, so a
 * question costs about a cent and a half.
 *
 * A passage is: kind (call | file), id (session or file), label, title, blocks (the lines or paragraphs Claude can
 * cite) and where (per block: the moment in ms of a call line, or the page of a file paragraph).
 *
 * @phpstan-type Passage array{kind: string, id: string, label: string, title: string, blocks: list<string>, where: list<int>, score: float, order: int}
 */
final class PassageSearch
{
    private const WINDOW = 8;

    private const STEP = 6;

    private const PARAGRAPH_CHARS = 1200;

    /** Since 2.0.0-o: how many recordings Ask across recordings reads at a time. */
    public const BATCH = 10;

    /** The transcript columns the search reads (never the text, meta or anything else of a large row). */
    public const TRANSCRIPT_COLUMNS = ['id', 'capture_session_id', 'kind', 'status', 'segments'];

    private const STOP = ['the', 'and', 'for', 'was', 'what', 'when', 'did', 'does', 'are', 'with', 'that', 'this', 'from', 'have', 'has',
        'how', 'which', 'who', 'about', 'they', 'there', 'their', 'kya', 'hai', 'tha', 'thi', 'ki', 'ka', 'ke', 'ko', 'aur', 'mein', 'se'];

    public function __construct(private readonly SettingsRepository $settings) {}

    /** @return list<Passage> the best [$count] passages, most recent first among equals */
    public function top(Client $client, string $question, int $count): array
    {
        $sessions = CaptureSession::query()->withoutGlobalScopes()->where('client_id', $client->id)->where('user_id', $client->user_id)->with('finalTranscript')
            ->latest('started_at')->limit(60)->get();

        return self::best([...$this->calls($sessions), ...$this->files($client)], $question, $count);
    }

    /**
     * The best [$count] passages of the given recordings' transcripts only (since 2.0.0-o: Ask about one recording, and
     * Ask across a person's recordings), most recent first among equals. The caller decides whose recordings they are.
     *
     * @param  iterable<CaptureSession>  $sessions  newest first, with their final transcript loaded
     * @return list<Passage>
     */
    public function topOfRecordings(iterable $sessions, string $question, int $count): array
    {
        return self::best($this->calls($sessions), $question, $count);
    }

    /**
     * The same search over many recordings, read [$batch] at a time (since 2.0.0-o, Ask across recordings): only the
     * best [$count] passages are kept from one batch to the next, so memory stays that of one batch however many
     * recordings, and however long, an account has. The answer is the one [topOfRecordings] gives for all of them.
     *
     * @param  list<string>  $sessionIds  newest first, already limited to the person's own recordings
     * @param  Closure(list<string>): iterable<CaptureSession>  $load  one batch of them with their final transcript
     *                                                                 (TRANSCRIPT_COLUMNS), in any order
     * @return list<Passage>
     */
    public function topOfManyRecordings(array $sessionIds, Closure $load, string $question, int $count, int $batch = self::BATCH): array
    {
        $words = self::words($question);
        $matching = [];
        $starts = [];
        foreach (array_chunk($sessionIds, max(1, $batch)) as $n => $ids) {
            $position = array_flip($ids);
            $sessions = [];
            foreach ($load($ids) as $session) {
                $sessions[$position[$session->id] ?? count($ids)] = $session;
            }
            ksort($sessions);
            foreach ($this->calls(array_values($sessions), $n * max(1, $batch)) as $passage) {
                $passage['score'] = self::score($words, implode("\n", $passage['blocks']));
                if ($passage['score'] > 0) {
                    $matching[] = $passage;
                } elseif ($matching === [] && $passage['where'] !== [] && $passage['order'] % 1000 === 0) {
                    $starts[] = $passage;
                }
            }
            unset($sessions);
            $matching = self::ranked($matching, $count);
            $starts = $matching === [] ? self::ranked($starts, $count) : [];
        }

        return $matching !== [] ? $matching : $starts;
    }

    /**
     * @param  list<Passage>  $passages
     * @return list<Passage>
     */
    private static function best(array $passages, string $question, int $count): array
    {
        $words = self::words($question);
        foreach ($passages as &$passage) {
            $passage['score'] = self::score($words, implode("\n", $passage['blocks']));
        }
        unset($passage);
        $matching = array_values(array_filter($passages, fn (array $p): bool => $p['score'] > 0));
        // Nothing matches the words: the start of the latest calls still lets Claude say what it can (or that it cannot).
        if ($matching === []) {
            $matching = array_values(array_filter($passages, fn (array $p): bool => $p['kind'] === 'call' && $p['where'] !== [] && $p['order'] % 1000 === 0));
        }

        return self::ranked($matching, $count);
    }

    /**
     * The best [$count], most recent first among equals.
     *
     * @param  list<Passage>  $passages
     * @return list<Passage>
     */
    private static function ranked(array $passages, int $count): array
    {
        usort($passages, fn (array $a, array $b): int => [$b['score'], $a['order']] <=> [$a['score'], $b['order']]);

        return array_slice($passages, 0, $count);
    }

    /**
     * @param  iterable<CaptureSession>  $sessions  newest first; the first is ranked [$firstRank]
     * @return list<Passage>
     */
    private function calls(iterable $sessions, int $firstRank = 0): array
    {
        $zone = $this->settings->displayTimezone();
        $out = [];
        $rank = $firstRank - 1;
        foreach ($sessions as $session) {
            $rank++;
            $transcript = $session->finalTranscript;
            if ($transcript === null || $transcript->status !== TranscriptStatus::Completed) {
                continue;
            }
            $segments = $transcript->segments ?? [];
            $label = $session->displayTitle().' · '.$session->started_at->copy()->setTimezone($zone)->format('M j');
            for ($start = 0, $window = 0; $start < count($segments); $start += self::STEP, $window++) {
                $slice = array_slice($segments, $start, self::WINDOW);
                $out[] = [
                    'kind' => 'call',
                    'id' => $session->id,
                    'label' => $label,
                    'title' => $label.' · '.ClientWorkspace::clock((int) ($slice[0]['start_ms'] ?? 0)),
                    'blocks' => array_map(fn (array $s): string => '['.ClientWorkspace::clock((int) ($s['start_ms'] ?? 0)).'] '
                        .(in_array($s['speaker'] ?? '', ['Me', ''], true) ? 'You' : $s['speaker']).': '.($s['text'] ?? ''), $slice),
                    'where' => array_map(fn (array $s): int => (int) ($s['start_ms'] ?? 0), $slice),
                    'score' => 0.0,
                    'order' => $rank * 1000 + $window,
                ];
                if ($start + self::WINDOW >= count($segments)) {
                    break;
                }
            }
        }

        return $out;
    }

    /** @return list<Passage> */
    private function files(Client $client): array
    {
        $out = [];
        $files = ClientFile::query()->withoutGlobalScopes()->where('client_id', $client->id)->where('status', 'ready')->latest()->get();
        foreach ($files as $rank => $file) {
            $page = 1;
            $blocks = [];
            $where = [];
            foreach (preg_split('/\R{2,}|(?==== Page \d+ ===)/', (string) $file->text) ?: [] as $chunk) {
                if (preg_match('/^=== Page (\d+) ===/', $chunk, $m) === 1) {
                    $page = (int) $m[1];
                    $chunk = (string) preg_replace('/^=== Page \d+ ===\s*/', '', $chunk);
                }
                foreach (mb_str_split(trim($chunk), self::PARAGRAPH_CHARS) as $piece) {
                    if (trim($piece) !== '') {
                        $blocks[] = $piece;
                        $where[] = $page;
                    }
                }
            }
            // Paragraphs go up four at a time, so one passage keeps its context.
            foreach (array_chunk(array_keys($blocks), 4) as $n => $keys) {
                $out[] = [
                    'kind' => 'file',
                    'id' => $file->id,
                    'label' => $file->name,
                    'title' => $file->name.' · page '.$where[$keys[0]],
                    'blocks' => array_map(fn (int $k): string => $blocks[$k], $keys),
                    'where' => array_map(fn (int $k): int => $where[$k], $keys),
                    'score' => 0.0,
                    'order' => 500_000 + $rank * 1000 + $n,
                ];
            }
        }

        return $out;
    }

    /** @return list<string> */
    public static function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]{3,}/u', mb_strtolower($text), $m);

        return array_values(array_unique(array_diff($m[0], self::STOP)));
    }

    /** How well a passage matches: each question word found counts, rarer long words more, longer passages a little less. */
    /** @param list<string> $words */
    public static function score(array $words, string $text): float
    {
        if ($words === []) {
            return 0.0;
        }
        $haystack = mb_strtolower($text);
        $score = 0.0;
        foreach ($words as $word) {
            $hits = mb_substr_count($haystack, $word);
            if ($hits > 0) {
                $score += (1 + log(1 + $hits)) * min(2.0, mb_strlen($word) / 4);
            }
        }

        return $score / (1 + log(1 + mb_strlen($haystack) / 2000));
    }
}
