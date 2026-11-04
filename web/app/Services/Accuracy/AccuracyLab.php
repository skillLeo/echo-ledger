<?php

declare(strict_types=1);

namespace App\Services\Accuracy;

use App\Enums\SttProviderKey;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Jobs\ScoreAccuracyJob;
use App\Models\CaptureSession;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Transcript;
use App\Models\UsageLedgerEntry;
use App\Services\Views\TranscriptViews;
use App\Stt\LanguageRouter;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * Accuracy Lab (since v1.2, owner only): recordings with a corrected reference transcript are run through several
 * engines ("Compare engines"); each run is scored against the reference (word and character error rates, after
 * normalising), timed and priced. The Lab adds the scores up per category and engine, and "Apply winners" puts the
 * best engine first in each category's list.
 *
 * Since v1.5 a recording may also have a Roman Urdu reference: the run's Roman Urdu view (made by Show as) is scored
 * against it on its own, next to the original transcript. Categories are the routing keys: Urdu (`ur`), English (`en`)
 * and mixed (`auto`, recordings whose language is not chosen; most are Urdu with English), chosen with the reference
 * or taken from the recording's language (Arabic and the other languages keep their own).
 */
final class AccuracyLab
{
    /** @var array<string, string> the categories a reference can be put in => label */
    public const CATEGORIES = ['ur' => 'Urdu', 'en' => 'English', LanguageRouter::AUTO => 'Mixed (Urdu and English)'];

    /**
     * The lists "Apply winners" may change: the categories, and Arabic as before v1.5. The list of the other languages is
     * shared by eight of them, so one French recording never reorders it (the Lab still shows its scores).
     */
    private const WINNER_LISTS = ['ur', 'en', LanguageRouter::AUTO, 'ar'];

    public function __construct(private readonly LanguageRouter $router, private readonly TranscriptViews $views) {}

    /** The spoken words of a transcript, line by line (the default reference before the owner corrects it). */
    public static function spokenText(?Transcript $transcript): string
    {
        return implode("\n", array_values(array_filter(array_map(
            fn ($s): string => trim((string) ($s['text'] ?? '')), $transcript->segments ?? []), fn (string $t): bool => $t !== '')));
    }

    /**
     * Saves the references: the words as spoken and, when given, in Roman Urdu (each cleaned of time codes and speaker
     * labels, so TurboScribe or subtitle texts can be pasted as they are), and the category the recording counts in
     * (Urdu, English or mixed; anything else counts in the recording's own language).
     */
    public function setReference(CaptureSession $session, ?string $original, ?string $roman = null, ?string $category = null): void
    {
        $original = self::usable($original);
        $roman = self::usable($roman);
        if ($original === null && $roman === null) {
            throw new RuntimeException('The reference needs the spoken words of the recording.');
        }
        $session->forceFill([
            'reference_text' => $original,
            'reference_roman' => $roman,
            'reference_category' => $category !== null && isset(self::CATEGORIES[$category]) ? $category : null,
            'reference_set_at' => now(),
        ])->save();
        ScoreAccuracyJob::dispatch($session->id);
    }

    public function clearReference(CaptureSession $session): void
    {
        $session->forceFill(['reference_text' => null, 'reference_roman' => null, 'reference_category' => null, 'reference_set_at' => null])->save();
    }

    /** The category a reference recording counts in: the owner's choice, else the routing key of its language. */
    public function category(CaptureSession $session): string
    {
        $chosen = $session->reference_category;

        return $chosen !== null && isset(self::CATEGORIES[$chosen]) ? $chosen : LanguageRouter::routeKey($this->router->languageFor($session));
    }

    public static function categoryLabel(string $key): string
    {
        return self::CATEGORIES[$key] ?? LanguageRouter::routeLabel($key);
    }

    /**
     * What the reference of [$session] can count in: the categories, and the recording's own language when it is none
     * of them (Arabic or another language), so an Arabic call is never scored as mixed Urdu and English.
     *
     * @return array<string, string>
     */
    public function categoryOptions(CaptureSession $session): array
    {
        $own = LanguageRouter::routeKey($this->router->languageFor($session));

        return [...self::CATEGORIES, $own => self::categoryLabel($own)];
    }

    /** Changes with either reference, so the runs are scored again. */
    public static function referenceHash(CaptureSession $session): string
    {
        $original = (string) $session->reference_text;

        return $session->reference_roman === null ? sha1($original) : sha1($original."\n\x1E".$session->reference_roman);
    }

    /**
     * Scores every finished comparison run of the recording that has no score for the current references: the original
     * transcript against the original reference, its Roman Urdu view against the Roman Urdu one. A run whose Roman Urdu
     * view is not made yet has it made; the recording is scored again when it is ready (MakeTranscriptViewsJob).
     */
    public function scoreSession(CaptureSession $session): int
    {
        if (! $session->hasReference()) {
            return 0;
        }
        $hash = self::referenceHash($session);
        $scored = 0;
        foreach ($session->trials()->with('captureSession')->where('status', TranscriptStatus::Completed->value)->get() as $run) {
            $previous = $run->meta['score'] ?? null;
            if (is_array($previous) && ($previous['reference'] ?? null) === $hash && ($previous['roman_pending'] ?? false) !== true) {
                continue;
            }
            $roman = $session->reference_roman !== null ? $this->romanView($run) : null;
            $seconds = $run->completed_at !== null ? max(0, $run->created_at->diffInSeconds($run->completed_at, true)) : null;
            $audioMinutes = max(1 / 60, (int) ($session->duration_ms ?? 0) / 60000);
            $run->forceFill(['meta' => [...($run->meta ?? []), 'score' => array_filter([
                'reference' => $hash,
                'original' => $session->reference_text !== null ? self::rates($session->reference_text, self::spokenText($run), false) : null,
                'roman' => is_string($roman) ? self::rates((string) $session->reference_roman, $roman, true) : null,
                'roman_pending' => $roman === false ? true : null,
                'seconds_per_audio_minute' => $seconds !== null ? round($seconds / $audioMinutes, 1) : null,
            ], fn ($value): bool => $value !== null)]])->save();
            $scored++;
        }

        return $scored;
    }

    /**
     * Per category and engine: recordings scored, average word and character error rates of the original transcript
     * and of the Roman Urdu view, speed and cost per audio hour, the winner per category, and the recordings with their
     * scores. The winner has the lowest word error rate on the original transcript; the Roman Urdu view breaks ties,
     * and decides when a category has Roman Urdu references only. An engine with no rate yet (its Roman Urdu view is
     * being made, or cannot be made) is listed last and never wins; a category none of whose engines has one has no
     * winner.
     *
     * @return array{languages: list<array{language: string, label: string, winner: string|null, roman: bool, engines: list<array<string, mixed>>}>, recordings: list<array<string, mixed>>}
     */
    public function report(int $workspaceId): array
    {
        $sessions = CaptureSession::query()->withoutGlobalScope(WorkspaceScope::class)->where('workspace_id', $workspaceId)
            ->where(fn (Builder $q) => $q->whereNotNull('reference_text')->orWhereNotNull('reference_roman'))
            ->with('client')->orderByDesc('reference_set_at')->get();
        $runs = Transcript::query()->whereIn('capture_session_id', $sessions->pluck('id'))->where('kind', TranscriptKind::Trial->value)
            ->where('status', TranscriptStatus::Completed->value)->get();
        $costs = UsageLedgerEntry::query()->withoutGlobalScope(WorkspaceScope::class)->whereIn('transcript_id', $runs->pluck('id'))
            ->selectRaw('transcript_id, SUM(cost_usd) as cost')->groupBy('transcript_id')->pluck('cost', 'transcript_id');

        $groups = [];
        $recordings = [];
        foreach ($sessions as $session) {
            $hash = self::referenceHash($session);
            $hours = max(1 / 3600, (int) ($session->duration_ms ?? 0) / 3_600_000);
            $category = $this->category($session);
            $row = ['id' => $session->id, 'title' => $session->displayTitle(), 'language' => self::categoryLabel($category), 'scores' => []];
            foreach ($runs->where('capture_session_id', $session->id) as $run) {
                $score = $run->meta['score'] ?? null;
                if (! is_array($score) || ($score['reference'] ?? null) !== $hash) {
                    $row['scores'][$run->provider] = null; // being scored

                    continue;
                }
                // A score from before v1.5 has the original's rates at the top.
                $original = is_array($score['original'] ?? null) ? $score['original'] : (isset($score['wer']) ? $score : null);
                $roman = is_array($score['roman'] ?? null) ? $score['roman'] : null;
                $engine = $groups[$category][$run->provider] ?? ['original' => [], 'roman' => [], 'speed' => [], 'cost_per_hour' => []];
                if ($original !== null) {
                    $engine['original'][] = [(float) $original['wer'], (float) $original['cer']];
                }
                if ($roman !== null) {
                    $engine['roman'][] = [(float) $roman['wer'], (float) $roman['cer']];
                }
                if (is_numeric($score['seconds_per_audio_minute'] ?? null)) {
                    $engine['speed'][] = (float) $score['seconds_per_audio_minute'];
                }
                if (isset($costs[$run->id])) {
                    $engine['cost_per_hour'][] = (float) $costs[$run->id] / $hours;
                }
                $groups[$category][$run->provider] = $engine;
                $row['scores'][$run->provider] = [
                    'wer' => $original !== null ? (float) $original['wer'] : null,
                    'cer' => $original !== null ? (float) $original['cer'] : null,
                    'roman_wer' => $roman !== null ? (float) $roman['wer'] : null,
                    'roman_cer' => $roman !== null ? (float) $roman['cer'] : null,
                    'roman_pending' => ($score['roman_pending'] ?? false) === true,
                ];
            }
            $recordings[] = $row;
        }

        $languages = [];
        foreach ($groups as $category => $engines) {
            $list = [];
            foreach ($engines as $provider => $e) {
                $list[] = [
                    'provider' => $provider,
                    'label' => SttProviderKey::tryFrom($provider)?->getLabel() ?? $provider,
                    'recordings' => count($e['original']),
                    'wer' => self::mean(array_column($e['original'], 0)),
                    'cer' => self::mean(array_column($e['original'], 1)),
                    'roman_recordings' => count($e['roman']),
                    'roman_wer' => self::mean(array_column($e['roman'], 0)),
                    'roman_cer' => self::mean(array_column($e['roman'], 1)),
                    'seconds_per_audio_minute' => $e['speed'] !== [] ? round(array_sum($e['speed']) / count($e['speed']), 1) : null,
                    'cost_per_hour_usd' => $e['cost_per_hour'] !== [] ? round(array_sum($e['cost_per_hour']) / count($e['cost_per_hour']), 3) : null,
                ];
            }
            $rank = fn (array $e): array => [$e['wer'] ?? PHP_FLOAT_MAX, $e['roman_wer'] ?? PHP_FLOAT_MAX, $e['cer'] ?? PHP_FLOAT_MAX, $e['roman_cer'] ?? PHP_FLOAT_MAX];
            usort($list, fn (array $a, array $b): int => $rank($a) <=> $rank($b));
            $languages[] = [
                'language' => (string) $category,
                'label' => self::categoryLabel((string) $category),
                // Ranked first, so when the first engine has no rate, none has.
                'winner' => $list[0]['wer'] !== null || $list[0]['roman_wer'] !== null ? $list[0]['provider'] : null,
                'roman' => collect($list)->contains(fn (array $e): bool => $e['roman_recordings'] > 0),
                'engines' => $list,
            ];
        }
        $order = array_flip([...array_keys(self::CATEGORIES), 'ar', LanguageRouter::OTHER]);
        usort($languages, fn (array $a, array $b): int => ($order[$a['language']] ?? 99) <=> ($order[$b['language']] ?? 99));

        return ['languages' => $languages, 'recordings' => $recordings];
    }

    /**
     * "Apply winners": each category's winner goes to the front of that category's engine list (Settings → Engine per
     * language: Urdu, English, Auto for mixed, and Arabic); the other engines keep their order behind it. The list the
     * other languages share is left as it is.
     *
     * @return list<array{language: string, provider: string}>
     */
    public function useWinners(int $workspaceId): array
    {
        $changed = [];
        foreach ($this->report($workspaceId)['languages'] as $category) {
            $winner = $category['winner'] !== null ? SttProviderKey::tryFrom($category['winner']) : null;
            if ($winner !== null && in_array($category['language'], self::WINNER_LISTS, true) && $this->router->preferKey($category['language'], $winner)) {
                $changed[] = ['language' => $category['label'], 'provider' => $winner->getLabel()];
            }
        }

        return $changed;
    }

    /**
     * The run's Roman Urdu view as one text; false while it is being made (or was just asked for), null when it cannot
     * be made (not an Urdu or mixed run, no Anthropic key, or making it failed).
     */
    private function romanView(Transcript $run): string|false|null
    {
        $state = $this->views->state($run);
        if (! in_array('roman', $state['available'], true) || $state['status'] === 'failed') {
            return null;
        }
        if ($state['status'] !== 'ready') {
            if (! $this->views->configured()) {
                return null;
            }
            try {
                $state = $this->views->request($run);
            } catch (RuntimeException) {
                return null;
            }
        }

        return match ($state['status']) {
            'ready' => is_array($state['roman']) ? implode("\n", array_filter($state['roman'], fn (?string $line): bool => $line !== null && trim($line) !== '')) : null,
            'making' => false,
            default => null,
        };
    }

    /** @return array{wer: float, cer: float, words: int} */
    private static function rates(string $reference, string $hypothesis, bool $roman): array
    {
        return [
            'wer' => round(ErrorRates::wer($reference, $hypothesis, $roman), 4),
            'cer' => round(ErrorRates::cer($reference, $hypothesis, $roman), 4),
            'words' => count(ErrorRates::words($reference, $roman)),
        ];
    }

    /** @param  list<float>  $values */
    private static function mean(array $values): ?float
    {
        return $values === [] ? null : round(array_sum($values) / count($values), 4);
    }

    private static function usable(?string $text): ?string
    {
        $text = ReferenceText::clean((string) $text);

        return ErrorRates::words($text) === [] ? null : $text;
    }
}
