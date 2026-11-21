<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\TranscriptKind;
use App\Models\CaptureSession;
use App\Models\Transcript;
use App\Services\SettingsRepository;
use App\Services\TranscriptEditor;
use App\Stt\LanguageChoice;
use App\Support\UnsureMarkers;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Copy for AI in the new console (parity plan 3 and 4), the same text as the desktop and phone apps write
 * (desktop/src/v2/library/organise.tsx aiHeader and copyForAi): each recording's short header (title; date, time,
 * source, length; people; language), then its lines. For several, the note on unsure words once at the top and the
 * recordings apart by "---"; recordings without a transcript are left out and counted. The lines are the recording
 * player's own (transcript-player.blade.php markedLine and transcriptText: who said what, [m:ss], unsure words as the
 * console marks them); tests/js/v2-copy-ai.test.mjs holds this class to the player's methods.
 */
final class V2CopyForAi
{
    /** At most this many recordings in one copy, as a selection's other actions. */
    public const MAX = 200;

    /**
     * Copy for AI of several recordings: the text, how many were copied and how many had no transcript.
     *
     * @param  iterable<CaptureSession>  $sessions
     * @return array{text: string, copied: int, skipped: int}
     */
    public static function forMany(iterable $sessions): array
    {
        $unsure = UnsureMarkers::settings();
        $style = $unsure['style'] === 'off' ? 'brackets' : $unsure['style'];
        $parts = [];
        $skipped = 0;
        // Every transcript and project of the recordings in two queries, not a few for each recording.
        $sessions = new EloquentCollection(is_array($sessions) ? $sessions : iterator_to_array($sessions, false));
        $sessions->loadMissing('client');
        $transcripts = Transcript::query()->whereIn('capture_session_id', $sessions->modelKeys())
            ->whereIn('kind', [TranscriptKind::Final->value, TranscriptKind::Live->value])->get()->groupBy('capture_session_id');
        foreach ($sessions as $session) {
            $own = $transcripts->get($session->getKey(), collect());
            $session->setRelation('finalTranscript', $own->first(fn (Transcript $t): bool => $t->kind === TranscriptKind::Final));
            $lines = self::lines($session, $unsure['threshold'], $own);
            if ($lines === []) {
                $skipped++;

                continue;
            }
            $parts[] = self::header($session, self::people($lines))."\n\n".self::body($lines, true, $style);
        }
        $text = $parts === [] ? '' : self::note($unsure, $style)."\n\n".implode("\n\n---\n\n", $parts);

        return ['text' => $text, 'copied' => count($parts), 'skipped' => $skipped];
    }

    /**
     * The header above a recording: "# Title", then "16 November 2026, 4:30 pm · Zoom · 1:00", "People: …" and
     * "Language: UR+EN" (desktop aiHeader). In English on every platform: it is written for the AI, not shown.
     *
     * @param  list<string>  $people
     */
    public static function header(CaptureSession $session, array $people): string
    {
        $zone = app(SettingsRepository::class)->displayTimezone();
        $at = $session->started_at->copy()->setTimezone($zone);
        $final = $session->relationLoaded('finalTranscript') ? $session->finalTranscript : $session->finalTranscript()->first();
        $chip = LanguageChoice::chip($final->language ?? $session->language);

        return implode("\n", array_filter([
            '# '.(trim($session->displayTitle()) !== '' ? trim($session->displayTitle()) : 'Recording'),
            implode(' · ', array_filter([
                $at->format('j F Y').', '.$at->format(Shell::timeFormat() === 'H:i' ? 'H:i' : 'g:i a'),
                (string) $session->source_app->getLabel(),
                self::length($session->duration_ms),
            ], fn (?string $part): bool => $part !== null && $part !== '')),
            $people !== [] ? 'People: '.implode(', ', $people) : null,
            filled($chip) ? 'Language: '.$chip : null,
        ], fn (?string $line): bool => $line !== null));
    }

    /**
     * The lines the player has for a recording (its final transcript when it has lines, else the live one), each with the
     * speaker's name as the page shows it and the words the engine was unsure of; empty without a transcript.
     *
     * @param  iterable<Transcript>|null  $transcripts
     * @return list<array{name: string, start_ms: int, text: string, words: list<array{t: string, u: bool}>}>
     */
    public static function lines(CaptureSession $session, float $threshold, ?iterable $transcripts = null): array
    {
        // The recording's transcripts when they are already loaded (forMany), else asked for.
        $pick = fn (TranscriptKind $kind): ?Transcript => $transcripts !== null
            ? collect($transcripts)->first(fn (Transcript $t): bool => $t->kind === $kind)
            : $session->transcripts()->where('kind', $kind->value)->first();
        $final = $pick(TranscriptKind::Final);
        $live = $pick(TranscriptKind::Live);
        /** @var Transcript|null $shown */
        $shown = ($final !== null && $final->segments) ? $final : ($live ?? $final);
        if ($shown === null || ! $shown->segments) {
            return [];
        }
        $names = app(TranscriptEditor::class)->speakerNames($session);
        $lines = [];
        foreach ($shown->segments as $s) {
            $words = [];
            foreach (is_array($s['words'] ?? null) ? $s['words'] : [] as $w) {
                if (is_array($w) && isset($w['start_ms'], $w['text'])) {
                    $words[] = ['t' => (string) $w['text'], 'u' => is_numeric($w['confidence'] ?? null) && (float) $w['confidence'] < $threshold];
                }
            }
            $speaker = (string) ($s['speaker'] ?? 'Speaker');
            $lines[] = ['name' => filled($names[$speaker] ?? null) ? (string) $names[$speaker] : $speaker, 'start_ms' => (int) ($s['start_ms'] ?? 0),
                'text' => (string) ($s['text'] ?? ''), 'words' => $words];
        }

        return $lines;
    }

    /**
     * The people in the lines, in the order they first speak.
     *
     * @param  list<array{name: string}>  $lines
     * @return list<string>
     */
    public static function people(array $lines): array
    {
        return array_values(array_unique(array_column($lines, 'name')));
    }

    /**
     * The player's transcriptText without the note: "[m:ss] Name: line", the name left out when one person speaks, each
     * unsure word marked in the console's style (brackets [?word] or suffix word(?)).
     *
     * @param  list<array{name: string, start_ms: int, text: string, words: list<array{t: string, u: bool}>}>  $lines
     */
    public static function body(array $lines, bool $withTimes, string $style): string
    {
        $single = count(array_unique(array_column($lines, 'name'))) <= 1;
        $out = [];
        foreach ($lines as $line) {
            $out[] = ($withTimes ? '['.self::clock($line['start_ms']).'] ' : '').($single ? '' : $line['name'].': ').self::marked($line, $style);
        }

        return implode("\n", $out);
    }

    /**
     * The player's markedLine on the original words: each unsure word, found in order, wrapped where it stands.
     *
     * @param  array{text: string, words: list<array{t: string, u: bool}>}  $line
     */
    public static function marked(array $line, string $style): string
    {
        $text = $line['text'];
        $out = '';
        $at = 0;
        foreach ($line['words'] as $w) {
            $pos = $w['t'] !== '' ? mb_strpos($text, $w['t'], $at) : false;
            if ($pos === false) {
                continue;
            }
            $out .= mb_substr($text, $at, $pos - $at).($w['u'] && ! str_starts_with($w['t'], '[') ? '[?'.$w['t'].']' : $w['t']);
            $at = $pos + mb_strlen($w['t']);
        }
        $raw = $out.mb_substr($text, $at);

        return match ($style) {
            'suffix' => (string) preg_replace('/\[\?([^\]]+)\]/u', '$1(?)', $raw),
            'off' => (string) preg_replace('/\[\?([^\]]+)\]/u', '$1', $raw),
            default => $raw,
        };
    }

    /**
     * The note on unsure words, as the player writes it for Copy for AI.
     *
     * @param  array<string, mixed>  $unsure  UnsureMarkers::settings()
     */
    public static function note(array $unsure, string $style): string
    {
        $text = UnsureMarkers::note((string) $unsure['style']) ?: 'Note: [?word] = the transcription is unsure about this word; [unclear] = inaudible audio.';

        return str_replace('[?word]', $style === 'suffix' ? 'word(?)' : '[?word]', $text);
    }

    /** The player's clock: "m:ss", or "h:mm:ss" from an hour. */
    public static function clock(int $ms): string
    {
        $t = max(0, intdiv($ms, 1000));
        $h = intdiv($t, 3600);
        $m = intdiv($t % 3600, 60);

        return ($h > 0 ? $h.':'.str_pad((string) $m, 2, '0', STR_PAD_LEFT) : (string) $m).':'.str_pad((string) ($t % 60), 2, '0', STR_PAD_LEFT);
    }

    /** The length as the apps write it: "1:00", "1:02:03"; empty for none. */
    public static function length(?int $ms): string
    {
        if ($ms === null || $ms <= 0) {
            return '';
        }
        $total = (int) round($ms / 1000);
        $h = intdiv($total, 3600);
        $m = intdiv($total % 3600, 60);
        $s = $total % 60;

        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }
}
