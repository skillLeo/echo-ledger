<?php

declare(strict_types=1);

namespace App\Services\ClientAi;

use App\Enums\TranscriptStatus;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\ClientFile;
use App\Services\SettingsRepository;

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

    private const STOP = ['the', 'and', 'for', 'was', 'what', 'when', 'did', 'does', 'are', 'with', 'that', 'this', 'from', 'have', 'has',
        'how', 'which', 'who', 'about', 'they', 'there', 'their', 'kya', 'hai', 'tha', 'thi', 'ki', 'ka', 'ke', 'ko', 'aur', 'mein', 'se'];

    public function __construct(private readonly SettingsRepository $settings) {}

    /** @return list<Passage> the best [$count] passages, most recent first among equals */
    public function top(Client $client, string $question, int $count): array
    {
        $words = self::words($question);
        $passages = [...$this->calls($client), ...$this->files($client)];
        foreach ($passages as &$passage) {
            $passage['score'] = self::score($words, implode("\n", $passage['blocks']));
        }
        unset($passage);
        $matching = array_values(array_filter($passages, fn (array $p): bool => $p['score'] > 0));
        // Nothing matches the words: the start of the latest calls still lets Claude say what it can (or that it cannot).
        if ($matching === []) {
            $matching = array_values(array_filter($passages, fn (array $p): bool => $p['kind'] === 'call' && $p['where'] !== [] && $p['order'] % 1000 === 0));
        }
        usort($matching, fn (array $a, array $b): int => [$b['score'], $a['order']] <=> [$a['score'], $b['order']]);

        return array_slice($matching, 0, $count);
    }

    /** @return list<Passage> */
    private function calls(Client $client): array
    {
        $zone = $this->settings->displayTimezone();
        $sessions = CaptureSession::query()->withoutGlobalScopes()->where('client_id', $client->id)->with('finalTranscript')
            ->latest('started_at')->limit(60)->get();
        $out = [];
        foreach ($sessions as $rank => $session) {
            $transcript = $session->finalTranscript;
            if ($transcript === null || $transcript->status !== TranscriptStatus::Completed) {
                continue;
            }
            $segments = $transcript->segments ?? [];
            $label = ($session->title ?: 'Call').' · '.$session->started_at->copy()->setTimezone($zone)->format('M j');
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
