<?php

declare(strict_types=1);

namespace App\Services\Assist;

use App\Http\Controllers\Api\Assist\AssistSessionController;
use App\Models\AssistPrep;
use App\Models\AssistSession;
use App\Models\ClientMemoryItem;

/**
 * What the device API and the console do alike with a person's Live Assist preps and sessions (v1.9 L1, L8): erase one
 * with every word of it, and write a report as Markdown.
 */
final class AssistRecords
{
    public const MEETING_TYPES = ['interview' => 'Interview', 'boss' => 'Boss', 'existing_client' => 'Existing client', 'new_client' => 'New client', 'general' => 'General'];

    public const MODES = ['fast' => 'Fast', 'balanced' => 'Balanced', 'deep' => 'Deep', 'max' => 'Max'];

    public const HINT_LEVELS = ['keywords' => 'Keywords', 'outline' => 'Outline', 'full' => 'Full answer'];

    public const LANGUAGE_HELP = ['off' => 'Off', 'urdu' => 'Urdu', 'roman_urdu' => 'Roman Urdu', 'arabic' => 'Arabic'];

    public const VERDICTS = ['good' => 'Good', 'weak' => 'Weak', 'missed' => 'Missed'];

    /** Deletes [$prep]: its texts and documents now; the soft-deleted row keeps only what a session started from it points at. */
    public static function erasePrep(AssistPrep $prep): void
    {
        $prep->forceFill(array_fill_keys(AssistPrep::TEXT_FIELDS, null))->save();
        $prep->documents()->detach();
        $prep->delete();
    }

    /** Deletes [$session] and every word of it (ending it first); its usage stays counted. */
    public static function eraseSession(AssistSession $session): void
    {
        if (! $session->ended()) {
            AssistSessionController::close($session, now());
        }
        $session->turns()->delete();
        $session->suggestions()->delete();
        // The action items its report added to a project's memory go with it; lines a person pinned stay.
        ClientMemoryItem::query()->withoutGlobalScopes()->where('assist_session_id', $session->id)->where('pinned', false)->delete();
        $session->forceFill(['report' => null, 'summary' => null, 'facts' => null, 'report_status' => 'none'])->save();
        $session->delete();
    }

    /**
     * The report as Markdown (the console's Download), from what GET …/report gives. Every word that came from the call
     * or the model goes through md(): nothing in it can become a link, an image, HTML or a table cell of its own.
     *
     * @param  array<string, mixed>  $report
     */
    public static function markdown(array $report, string $timezone): string
    {
        $one = fn (mixed $v): string => self::md((string) preg_replace('/\s+/u', ' ', is_scalar($v) ? (string) $v : ''));
        $when = isset($report['started_at']) ? now()->parse((string) $report['started_at'])->setTimezone($timezone)->format('j M Y, H:i') : '';
        $kind = ($report['practice'] ?? false) ? 'Practice' : 'Live Assist';
        $title = trim((string) ($report['title'] ?? ''));
        $out = ['# '.($title !== '' ? $one($title) : $kind.' report'), ''];
        $out[] = self::md(sprintf('%s · %s · %s · %s', $kind, self::MEETING_TYPES[$report['meeting_type'] ?? 'general'] ?? 'General', $when, self::duration((int) ($report['seconds'] ?? 0))));
        $out[] = '';
        if (is_string($report['summary'] ?? null) && trim($report['summary']) !== '') {
            $out = [...$out, '## Summary', '', self::paragraphs($report['summary']), ''];
        }
        if (is_array($report['summary_translation'] ?? null)) {
            $language = self::LANGUAGE_HELP[$report['summary_translation']['language'] ?? ''] ?? 'Translated';
            $out = [...$out, '## Summary ('.$language.')', '', self::paragraphs((string) ($report['summary_translation']['text'] ?? '')), ''];
        }
        if (($report['qa'] ?? []) !== []) {
            $out = [...$out, '## Questions and answers', '', '| # | Question | What I said | Suggested | How it went | A better answer |', '|---|---|---|---|---|---|'];
            foreach ((array) $report['qa'] as $row) {
                $out[] = sprintf('| %d | %s | %s | %s | %s | %s |', (int) ($row['index'] ?? 0), $one($row['question'] ?? ''), ($row['answer'] ?? null) === null ? '—' : $one($row['answer']),
                    ($row['hint']['text'] ?? null) === null ? '—' : $one($row['hint']['text']), self::VERDICTS[$row['verdict'] ?? ''] ?? '—', $one($row['better_answer'] ?? ''));
            }
            $out[] = '';
        }
        $lists = [
            ['mistakes', 'Against the facts', fn (mixed $m): string => is_array($m) ? $one($m['said'] ?? '').' — '.$one($m['fact'] ?? '') : $one($m)],
            ['promises', 'Promises I made', $one],
            ['action_items', 'Action items', $one],
        ];
        foreach ($lists as [$key, $heading, $format]) {
            if (($report[$key] ?? []) !== []) {
                $out = [...$out, '## '.$heading, '', ...array_map(fn (mixed $item): string => '- '.$format($item), (array) $report[$key]), ''];
            }
        }
        $c = (array) ($report['coaching'] ?? []);
        $out = [...$out, '## Coaching', ''];
        $out[] = '- Words per minute: '.(is_int($c['words_per_minute'] ?? null) ? $c['words_per_minute'] : '—');
        $top = array_map(fn (mixed $f): string => $one($f['word'] ?? '').' ×'.(int) ($f['count'] ?? 0), (array) ($c['filler_words']['top'] ?? []));
        $out[] = '- Filler words: '.(int) ($c['filler_words']['count'] ?? 0).($top !== [] ? ' ('.implode(', ', $top).')' : '');
        $out[] = '- I talked: '.(is_int($c['talk_ratio']['me'] ?? null) ? $c['talk_ratio']['me'].'%' : '—');
        $out[] = '- Long pauses before an answer: '.(int) ($c['long_pauses']['count'] ?? 0);
        foreach ((array) ($c['grammar'] ?? []) as $fix) {
            $out[] = '- “'.$one($fix['said'] ?? '').'” → “'.$one($fix['better'] ?? '').'” ('.$one($fix['why'] ?? '').')';
        }
        $out[] = '';
        if (is_array($report['follow_up_email'] ?? null)) {
            $out = [...$out, '## Follow-up email', '', '**'.$one($report['follow_up_email']['subject'] ?? '').'**', '', self::paragraphs((string) ($report['follow_up_email']['body'] ?? '')), ''];
        }
        if (($report['facts'] ?? []) !== []) {
            $out = [...$out, '## Facts noted during the call', '', ...array_map(fn (mixed $f): string => '- '.$one($f), (array) $report['facts']), ''];
        }

        return implode("\n", $out);
    }

    /**
     * One line of free text made inert in Markdown: the backslash first, then & and < as HTML entities (no tag, no
     * entity of its own), then every character Markdown reads (` * _ [ ] ( ) > # ! | ~), and a line-start marker
     * (- + or a number and a dot) so it cannot start a list. What shows is the text as written.
     */
    public static function md(string $text): string
    {
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace(['&', '<'], ['&amp;', '&lt;'], $text);
        $text = (string) preg_replace('/([`*_\[\]()>#!|~])/u', '\\\\$1', $text);
        $text = (string) preg_replace('/^(\s*)([-+])/u', '$1\\\\$2', $text);

        return (string) preg_replace('/^(\s*\d+)\./u', '$1\\\\.', $text);
    }

    /** Several lines of free text as escaped paragraphs, one a line. */
    public static function paragraphs(string $text): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $text) ?: []), fn (string $l): bool => $l !== ''));

        return implode("\n\n", array_map(fn (string $l): string => self::md((string) preg_replace('/\s+/u', ' ', $l)), $lines));
    }

    public static function duration(int $seconds): string
    {
        return $seconds >= 3600 ? sprintf('%d h %02d min', intdiv($seconds, 3600), intdiv($seconds % 3600, 60)) : sprintf('%d min %02d s', intdiv($seconds, 60), $seconds % 60);
    }
}
