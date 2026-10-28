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
use App\Stt\LanguageChoice;
use App\Stt\LanguageRouter;
use RuntimeException;

/**
 * Accuracy Lab (since v1.2, owner only): recordings with a corrected reference transcript are run through several
 * engines ("Compare engines"); each run is scored against the reference (word and character error rates, after
 * normalising), timed and priced. The Lab adds the scores up per language and engine, and "Use the winners" puts the
 * engine with the lowest word error rate first for each language.
 */
final class AccuracyLab
{
    public function __construct(private readonly LanguageRouter $router) {}

    /** The spoken words of a transcript, line by line (the default reference before the owner corrects it). */
    public static function spokenText(?Transcript $transcript): string
    {
        return implode("\n", array_values(array_filter(array_map(
            fn ($s): string => trim((string) ($s['text'] ?? '')), $transcript->segments ?? []), fn (string $t): bool => $t !== '')));
    }

    public function setReference(CaptureSession $session, string $text): void
    {
        $text = trim($text);
        if (ErrorRates::words($text) === []) {
            throw new RuntimeException('The reference needs the spoken words of the recording.');
        }
        $session->forceFill(['reference_text' => $text, 'reference_set_at' => now()])->save();
        ScoreAccuracyJob::dispatch($session->id);
    }

    public function clearReference(CaptureSession $session): void
    {
        $session->forceFill(['reference_text' => null, 'reference_set_at' => null])->save();
    }

    /** Scores every finished comparison run of the recording that has no score for the current reference. */
    public function scoreSession(CaptureSession $session): int
    {
        $reference = (string) $session->reference_text;
        if ($reference === '') {
            return 0;
        }
        $hash = sha1($reference);
        $scored = 0;
        foreach ($session->trials()->with('captureSession')->where('status', TranscriptStatus::Completed->value)->get() as $run) {
            if (($run->meta['score']['reference'] ?? null) === $hash) {
                continue;
            }
            $hypothesis = self::spokenText($run);
            $seconds = $run->completed_at !== null ? max(0, $run->created_at->diffInSeconds($run->completed_at, true)) : null;
            $audioMinutes = max(1 / 60, (int) ($session->duration_ms ?? 0) / 60000);
            $run->forceFill(['meta' => [...($run->meta ?? []), 'score' => [
                'reference' => $hash,
                'wer' => round(ErrorRates::wer($reference, $hypothesis), 4),
                'cer' => round(ErrorRates::cer($reference, $hypothesis), 4),
                'words' => count(ErrorRates::words($reference)),
                'seconds_per_audio_minute' => $seconds !== null ? round($seconds / $audioMinutes, 1) : null,
            ]]])->save();
            $scored++;
        }

        return $scored;
    }

    /**
     * Per language and engine: recordings scored, average word and character error rates, speed and cost per audio
     * hour, the winner per language, and the recordings with their scores.
     *
     * @return array{languages: list<array{language: string, label: string, winner: string|null, engines: list<array<string, mixed>>}>, recordings: list<array<string, mixed>>}
     */
    public function report(int $workspaceId): array
    {
        $sessions = CaptureSession::query()->withoutGlobalScope(WorkspaceScope::class)->where('workspace_id', $workspaceId)
            ->whereNotNull('reference_text')->orderByDesc('reference_set_at')->get();
        $runs = Transcript::query()->whereIn('capture_session_id', $sessions->pluck('id'))->where('kind', TranscriptKind::Trial->value)
            ->where('status', TranscriptStatus::Completed->value)->get();
        $costs = UsageLedgerEntry::query()->withoutGlobalScope(WorkspaceScope::class)->whereIn('transcript_id', $runs->pluck('id'))
            ->selectRaw('transcript_id, SUM(cost_usd) as cost')->groupBy('transcript_id')->pluck('cost', 'transcript_id');

        /** @var array<string, array<string, array{recordings: int, wer: float, cer: float, speed: list<float>, cost_per_hour: list<float>}>> $groups */
        $groups = [];
        $recordings = [];
        foreach ($sessions as $session) {
            $hash = sha1((string) $session->reference_text);
            $hours = max(1 / 3600, (int) ($session->duration_ms ?? 0) / 3_600_000);
            $row = ['id' => $session->id, 'title' => $session->displayTitle(), 'language' => LanguageChoice::label($session->language), 'scores' => []];
            foreach ($runs->where('capture_session_id', $session->id) as $run) {
                $score = $run->meta['score'] ?? null;
                if (! is_array($score) || ($score['reference'] ?? null) !== $hash) {
                    $row['scores'][$run->provider] = null; // being scored

                    continue;
                }
                $language = LanguageRouter::routeKey($run->language ?? $session->language);
                $engine = $groups[$language][$run->provider] ?? ['recordings' => 0, 'wer' => 0.0, 'cer' => 0.0, 'speed' => [], 'cost_per_hour' => []];
                $engine['recordings']++;
                $engine['wer'] += (float) $score['wer'];
                $engine['cer'] += (float) $score['cer'];
                if (is_numeric($score['seconds_per_audio_minute'] ?? null)) {
                    $engine['speed'][] = (float) $score['seconds_per_audio_minute'];
                }
                if (isset($costs[$run->id])) {
                    $engine['cost_per_hour'][] = (float) $costs[$run->id] / $hours;
                }
                $groups[$language][$run->provider] = $engine;
                $row['scores'][$run->provider] = ['wer' => (float) $score['wer'], 'cer' => (float) $score['cer']];
            }
            $recordings[] = $row;
        }

        $languages = [];
        foreach ($groups as $language => $engines) {
            $list = [];
            foreach ($engines as $provider => $e) {
                $list[] = [
                    'provider' => $provider,
                    'label' => SttProviderKey::tryFrom($provider)?->getLabel() ?? $provider,
                    'recordings' => $e['recordings'],
                    'wer' => round($e['wer'] / $e['recordings'], 4),
                    'cer' => round($e['cer'] / $e['recordings'], 4),
                    'seconds_per_audio_minute' => $e['speed'] !== [] ? round(array_sum($e['speed']) / count($e['speed']), 1) : null,
                    'cost_per_hour_usd' => $e['cost_per_hour'] !== [] ? round(array_sum($e['cost_per_hour']) / count($e['cost_per_hour']), 3) : null,
                ];
            }
            usort($list, fn (array $a, array $b): int => [$a['wer'], $a['cer']] <=> [$b['wer'], $b['cer']]);
            $languages[] = ['language' => (string) $language, 'label' => LanguageRouter::routeLabel((string) $language), 'winner' => $list[0]['provider'] ?? null, 'engines' => $list];
        }
        usort($languages, fn (array $a, array $b): int => strcmp($a['language'], $b['language']));

        return ['languages' => $languages, 'recordings' => $recordings];
    }

    /**
     * Puts each language's winner first in the engine list (Settings → Engine per language).
     *
     * @return list<array{language: string, provider: string}>
     */
    public function useWinners(int $workspaceId): array
    {
        $changed = [];
        foreach ($this->report($workspaceId)['languages'] as $language) {
            $winner = $language['winner'] !== null ? SttProviderKey::tryFrom($language['winner']) : null;
            $code = match ($language['language']) {
                LanguageRouter::OTHER, LanguageRouter::AUTO => null,
                default => $language['language'],
            };
            if ($winner !== null && $code !== null) {
                $this->router->prefer($code, $winner);
                $changed[] = ['language' => $language['label'], 'provider' => $winner->getLabel()];
            }
        }

        return $changed;
    }
}
