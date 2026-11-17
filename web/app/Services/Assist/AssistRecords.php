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
     * The report as Markdown (the console's Download), from what GET …/report gives.
     *
     * @param  array<string, mixed>  $report
     */
    public static function markdown(array $report, string $timezone): string
    {
        $line = fn (mixed $v): string => trim(preg_replace('/\s+/u', ' ', (string) $v) ?? '');
        $cell = fn (mixed $v): string => str_replace('|', '\|', $line($v));
        $when = isset($report['started_at']) ? now()->parse((string) $report['started_at'])->setTimezone($timezone)->format('j M Y, H:i') : '';
        $kind = ($report['practice'] ?? false) ? 'Practice' : 'Live Assist';
        $out = ['# '.($line($report['title'] ?? '') !== '' ? $line($report['title']) : $kind.' report'), ''];
        $out[] = sprintf('%s · %s · %s · %s', $kind, self::MEETING_TYPES[$report['meeting_type'] ?? 'general'] ?? 'General', $when, self::duration((int) ($report['seconds'] ?? 0)));
        $out[] = '';
        if (($report['summary'] ?? null) !== null && $report['summary'] !== '') {
            $out = [...$out, '## Summary', '', (string) $report['summary'], ''];
        }
        if (is_array($report['summary_translation'] ?? null)) {
            $out = [...$out, '## Summary ('.(self::LANGUAGE_HELP[$report['summary_translation']['language']] ?? '').')', '', (string) $report['summary_translation']['text'], ''];
        }
        if (($report['qa'] ?? []) !== []) {
            $out = [...$out, '## Questions and answers', '', '| # | Question | What I said | Suggested | How it went | A better answer |', '|---|---|---|---|---|---|'];
            foreach ($report['qa'] as $row) {
                $out[] = sprintf('| %d | %s | %s | %s | %s | %s |', $row['index'], $cell($row['question']), $cell($row['answer'] ?? '—'),
                    $cell($row['hint']['text'] ?? '—'), self::VERDICTS[$row['verdict'] ?? ''] ?? '—', $cell($row['better_answer'] ?? ''));
            }
            $out[] = '';
        }
        foreach ([['mistakes', 'Against the facts', fn (array $m): string => $line($m['said']).' — '.$line($m['fact'])], ['promises', 'Promises I made', $line], ['action_items', 'Action items', $line]] as [$key, $heading, $format]) {
            if (($report[$key] ?? []) !== []) {
                $out = [...$out, '## '.$heading, '', ...array_map(fn (mixed $item): string => '- '.$format($item), $report[$key]), ''];
            }
        }
        $c = (array) ($report['coaching'] ?? []);
        $out = [...$out, '## Coaching', ''];
        $out[] = '- Words per minute: '.($c['words_per_minute'] ?? '—');
        $out[] = '- Filler words: '.(int) ($c['filler_words']['count'] ?? 0).(($c['filler_words']['top'] ?? []) !== [] ? ' ('.implode(', ', array_map(fn (array $f): string => $f['word'].' ×'.$f['count'], $c['filler_words']['top'])).')' : '');
        $out[] = '- I talked: '.(isset($c['talk_ratio']['me']) ? $c['talk_ratio']['me'].'%' : '—');
        $out[] = '- Long pauses before an answer: '.(int) ($c['long_pauses']['count'] ?? 0);
        foreach ((array) ($c['grammar'] ?? []) as $fix) {
            $out[] = '- "'.$line($fix['said']).'" → "'.$line($fix['better']).'" ('.$line($fix['why']).')';
        }
        $out[] = '';
        if (is_array($report['follow_up_email'] ?? null)) {
            $out = [...$out, '## Follow-up email', '', '**'.$line($report['follow_up_email']['subject']).'**', '', (string) $report['follow_up_email']['body'], ''];
        }
        if (($report['facts'] ?? []) !== []) {
            $out = [...$out, '## Facts noted during the call', '', ...array_map(fn (mixed $f): string => '- '.$line($f), $report['facts']), ''];
        }

        return implode("\n", $out);
    }

    public static function duration(int $seconds): string
    {
        return $seconds >= 3600 ? sprintf('%d h %02d min', intdiv($seconds, 3600), intdiv($seconds % 3600, 60)) : sprintf('%d min %02d s', intdiv($seconds, 60), $seconds % 60);
    }
}
