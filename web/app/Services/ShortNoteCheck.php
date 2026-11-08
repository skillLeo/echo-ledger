<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SttProviderKey;
use App\Enums\SttUseCase;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\Recording;
use App\Models\Transcript;
use App\Services\Usage\BudgetGuard;
use App\Services\Usage\UsageLedger;
use App\Services\Workspaces\PlanLimits;
use App\Stt\Audio\AudioPrep;
use App\Stt\Contracts\ClipTranscriber;
use App\Stt\Data\Word;
use App\Stt\LanguageRouter;
use App\Stt\SttManager;
use App\Stt\WordAlign;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Double-check short notes (since v1.5, Phase 1C; Settings → Recording, on by default): a recording of at most
 * [MAX_MS] is heard by a second engine too, the cheapest other one that knows the language, and the two are lined up
 * word by word. Where they agree the text is plain; where they differ the word is marked unsure and keeps the other
 * engine's version (`alt`), so every app shows both on a tap. Only when they differ, and only when its key is set, a
 * paid engine (Gemini, else ElevenLabs Scribe) breaks the tie: two engines against one say which word it "probably"
 * is. The text itself always stays the first engine's: nothing is replaced by a guess.
 *
 * Every engine run is billed as its own usage row, so Usage shows what the check costs per note and per language.
 */
final class ShortNoteCheck
{
    public const SETTING = 'accuracy.double_check_short';

    /** Recordings up to 3 minutes. */
    public const MAX_MS = 180_000;

    /** Engines used only to break a tie (they cost more): never the second engine. */
    public const TIE_BREAKERS = [SttProviderKey::Gemini, SttProviderKey::ElevenLabs];

    /** Below this share of matching words the second engine heard something else (a wrong language): nothing is marked. */
    public const MIN_AGREEMENT = 0.3;

    /** The confidence an unsure word gets, so every app's existing unsure style shows it. */
    public const UNSURE_CONFIDENCE = 0.2;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly SttManager $stt,
        private readonly LanguageRouter $router,
        private readonly AudioPrep $prep,
        private readonly UsageLedger $ledger,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get(self::SETTING, true);
    }

    /** How long the recording is: its files' length, else where its last line ends. */
    public static function lengthMs(Transcript $transcript): int
    {
        $session = $transcript->captureSession;
        $fromFiles = (int) ($session->duration_ms ?? 0);
        if ($fromFiles <= 0) {
            $fromFiles = (int) $session->recordings()->max('duration_ms');
        }
        $fromLines = (int) collect($transcript->segments ?? [])->max(fn (array $s): int => (int) ($s['end_ms'] ?? 0));

        return $fromFiles > 0 ? $fromFiles : $fromLines;
    }

    public function wanted(Transcript $transcript): bool
    {
        return $this->enabled()
            // The final transcript only: the second version a wild disagreement starts is not checked again.
            && $transcript->kind === TranscriptKind::Final
            && $transcript->status === TranscriptStatus::Completed
            && ! isset(($transcript->meta ?? [])['short_check'])
            && trim((string) $transcript->text) !== ''
            && self::lengthMs($transcript) <= self::MAX_MS;
    }

    /**
     * Engines that can hear a clip in [$language] here, cheapest first (console price table), without [$except].
     *
     * @param  list<SttProviderKey>  $except
     * @return list<SttProviderKey>
     */
    public function cheapest(?string $language, array $except): array
    {
        $out = [];
        foreach ($this->router->candidates($language) as $engine) {
            if (! in_array($engine, $except, true) && $this->stt->driver($engine) instanceof ClipTranscriber) {
                $out[] = $engine;
            }
        }
        usort($out, fn (SttProviderKey $a, SttProviderKey $b): int => $this->settings->priceFor($a, SttUseCase::VoiceNote) <=> $this->settings->priceFor($b, SttUseCase::VoiceNote));

        return $out;
    }

    /** The second engine: the cheapest other one that is not a tie-breaker. */
    public function checkerFor(Transcript $transcript): ?SttProviderKey
    {
        $first = $transcript->providerKey();

        return $this->cheapest($transcript->language, array_values(array_filter([$first, ...self::TIE_BREAKERS])))[0] ?? null;
    }

    /** The tie-breaker: the cheapest paid engine with its key, other than the two already heard. */
    public function tieBreakerFor(Transcript $transcript, ?SttProviderKey $checker): ?SttProviderKey
    {
        $first = $transcript->providerKey();
        foreach ($this->cheapest($transcript->language, array_values(array_filter([$first, $checker]))) as $engine) {
            if (in_array($engine, self::TIE_BREAKERS, true)) {
                return $engine;
            }
        }

        return null;
    }

    /**
     * Hears the note again and marks the words the engines disagree on. Returns the summary kept in `meta.short_check`.
     *
     * @return array<string, mixed>
     */
    public function run(Transcript $transcript): array
    {
        if (! $this->wanted($transcript)) {
            return ['skipped' => 'not_wanted'];
        }
        $session = $transcript->captureSession;
        $checker = $this->checkerFor($transcript);
        // A note first heard by another engine (Auto, then its language's own engine) already has a second opinion.
        $firstRun = ($transcript->meta ?? [])['first_run'] ?? null;
        $reuse = is_array($firstRun) && is_array($firstRun['channels'] ?? null) && ($firstRun['provider'] ?? null) !== $transcript->provider;
        if ($reuse) {
            $checker = SttProviderKey::tryFrom((string) $firstRun['provider']) ?? $checker;
        }
        if ($checker === null) {
            return $this->remember($transcript, ['skipped' => 'no_second_engine']);
        }
        $skip = match (true) {
            ! $reuse && ! $this->prep->available() => 'no_ffmpeg',
            app(BudgetGuard::class)->limitReached() || app(PlanLimits::class)->minutesReached($session->workspace_id) => 'limit_reached',
            default => null,
        };
        if ($skip !== null) {
            return $this->remember($transcript, ['skipped' => $skip]);
        }

        $segments = $transcript->segments ?? [];
        $channels = array_values(array_unique(array_map(fn ($s): int => (int) ($s['channel'] ?? 1) === 2 ? 2 : 1, $segments)));
        $temps = [];
        $billed = [];
        $tie = null;
        try {
            $sides = null;
            $sidesFor = function () use (&$sides, &$temps, $session): array {
                return $sides ??= app(DoubleCheck::class)->sides($session->recordings()->get()->keyBy(fn (Recording $r): string => $r->role->value)->all(), $temps);
            };
            $heard = [];
            foreach ($channels as $channel) {
                if ($reuse) {
                    $heard[$channel] = (string) ($firstRun['channels'][(string) $channel] ?? $firstRun['channels'][$channel] ?? '');

                    continue;
                }
                $heard[$channel] = $this->hear($checker, $sidesFor()[$channel] ?? $sidesFor()[1] ?? null, $transcript, $billed);
            }
            $marked = self::mark($segments, $heard, $checker);
            if ($marked['stats']['disputed'] > 0) {
                $tie = $this->tieBreakerFor($transcript, $checker);
                if ($tie !== null) {
                    $tieHeard = [];
                    foreach ($channels as $channel) {
                        if (in_array($channel, $marked['stats']['disputed_channels'], true)) {
                            $tieHeard[$channel] = $this->hear($tie, $sidesFor()[$channel] ?? $sidesFor()[1] ?? null, $transcript, $billed);
                        }
                    }
                    $marked = self::mark($segments, $heard, $checker, $tieHeard, $tie);
                }
            }
        } catch (Throwable $e) {
            Log::warning('Short-note check failed', ['transcript' => $transcript->id, 'error' => mb_substr($e->getMessage(), 0, 200)]);

            return $this->remember($transcript, ['skipped' => 'failed', 'provider' => $checker->value]);
        } finally {
            foreach ($temps as $temp) {
                @unlink($temp);
            }
        }

        $cost = 0.0;
        foreach ($billed as $engine => $ms) {
            $key = SttProviderKey::from($engine);
            $this->ledger->recordCheck($transcript, $key, $ms);
            $cost += $ms / 3_600_000 * $this->settings->priceFor($key, SttUseCase::VoiceNote);
        }
        $summary = [
            'provider' => $checker->value,
            'tie_breaker' => $tie?->value,
            'reused_first_run' => $reuse,
            ...array_diff_key($marked['stats'], ['disputed_channels' => true]),
            'cost_usd' => round($cost, 6),
            'at' => now()->toAtomString(),
        ];
        // The engines heard different things altogether (one of them did not transcribe this audio): every line is
        // unsure, so no view smooths over it, and the second engine writes a whole second version beside it, once.
        $heardSides = array_keys(array_filter($heard, fn (string $t): bool => trim($t) !== ''));
        $wild = $heardSides !== [] && array_diff($heardSides, $marked['stats']['unreliable']) === [];
        if ($wild) {
            foreach ($marked['segments'] as $i => $s) {
                if (trim((string) ($s['text'] ?? '')) !== '') {
                    $marked['segments'][$i]['uncertain'] = true;
                }
            }
            $summary['unreliable'] = true;
        }
        $before = $segments;
        DB::transaction(function () use ($transcript, $marked, $summary, $before): void {
            $fresh = Transcript::query()->lockForUpdate()->find($transcript->id);
            if ($fresh === null) {
                return;
            }
            // Edited meanwhile: the person's words stay, unmarked; the summary is still kept.
            $segments = TranscriptEditor::plainText($fresh->segments ?? []) === TranscriptEditor::plainText($before) ? $marked['segments'] : ($fresh->segments ?? []);
            $fresh->forceFill(['segments' => $segments, 'meta' => [...($fresh->meta ?? []), 'short_check' => $summary]])->save();
        });

        if ($wild) {
            try {
                $summary['second_version'] = app(Retranscriber::class)->start($transcript->captureSession, $checker, $transcript->language)->id;
            } catch (Throwable $e) {
                Log::info('Short-note check: no second version', ['transcript' => $transcript->id, 'error' => mb_substr($e->getMessage(), 0, 200)]);
            }
            $fresh = Transcript::query()->find($transcript->id);
            $fresh?->forceFill(['meta' => [...($fresh->meta ?? []), 'short_check' => $summary]])->save();
        }

        return $summary;
    }

    /**
     * One side heard by [$engine]; its billed audio is added to [$billed].
     *
     * @param  array<string, int>  $billed
     */
    private function hear(SttProviderKey $engine, ?string $wav, Transcript $transcript, array &$billed): string
    {
        $driver = $this->stt->driver($engine);
        if ($wav === null || ! $driver instanceof ClipTranscriber) {
            return '';
        }
        $seconds = max(0.5, (float) $this->prep->durationSeconds($wav));
        $answer = $driver->transcribeClip($wav, $seconds, $transcript->language);
        $billed[$engine->value] = ($billed[$engine->value] ?? 0) + (int) $answer['billed_ms'];

        return (string) $answer['text'];
    }

    /**
     * Marks the words [$heard] (the second engine's text per channel) disagrees on. With [$tieHeard], a third engine's
     * text per channel decides: on the first engine's side the word is not unsure (the other version stays as `alt`);
     * on the second's it is unsure and "probably" that word; otherwise it is unsure with both versions. A line with any
     * disagreement keeps the second engine's whole line as `check`, as the line-level double-check does.
     *
     * @param  array<int, array<string, mixed>>  $segments
     * @param  array<int, string>  $heard
     * @param  array<int, string>  $tieHeard
     * @return array{segments: array<int, array<string, mixed>>, stats: array{words: int, disputed: int, probably: int, kept: int, unreliable: list<int>, disputed_channels: list<int>}}
     */
    public static function mark(array $segments, array $heard, SttProviderKey $checker, array $tieHeard = [], ?SttProviderKey $tie = null): array
    {
        $stats = ['words' => 0, 'disputed' => 0, 'probably' => 0, 'kept' => 0, 'unreliable' => [], 'disputed_channels' => []];
        foreach ($heard as $channel => $text) {
            // The first engine's words on this side, each with where it lives (line, word or null for a line without words).
            $refs = [];
            $mine = [];
            foreach ($segments as $i => $s) {
                if (((int) ($s['channel'] ?? 1) === 2 ? 2 : 1) !== $channel || isset($s['flag'])) {
                    continue;
                }
                $words = array_values(array_filter((array) ($s['words'] ?? []), 'is_array'));
                if ($words !== []) {
                    foreach ($words as $j => $w) {
                        if (WordAlign::key((string) ($w['text'] ?? '')) !== '') {
                            $refs[] = [$i, $j];
                            $mine[] = (string) $w['text'];
                        }
                    }
                } else {
                    foreach (WordAlign::tokens((string) ($s['text'] ?? '')) as $token) {
                        $refs[] = [$i, null];
                        $mine[] = $token;
                    }
                }
            }
            if ($mine === []) {
                continue;
            }
            $stats['words'] += count($mine);
            $disputedBefore = $stats['disputed'];
            $other = WordAlign::tokens($text);
            $ops = WordAlign::align($mine, $other);
            if ($other === [] || WordAlign::agreement($ops) < self::MIN_AGREEMENT) {
                $stats['unreliable'][] = $channel; // it heard something else, or nothing: no word is marked on its say-so

                continue;
            }
            // What the third engine heard at each of my words (only when it is asked).
            $third = [];
            if (isset($tieHeard[$channel])) {
                $tieTokens = WordAlign::tokens($tieHeard[$channel]);
                foreach (WordAlign::align($mine, $tieTokens) as $o) {
                    if ($o['a'] !== null) {
                        $third[$o['a']] = $o['b'] === null ? '' : $tieTokens[$o['b']];
                    }
                }
            }
            // The second engine's words per line (for `check`), and the words it disputes.
            $lineHeard = [];
            $lastLine = $refs[0][0];
            $disputedLines = [];
            foreach ($ops as $o) {
                $line = $o['a'] !== null ? $refs[$o['a']][0] : $lastLine;
                $lastLine = $line;
                if ($o['b'] !== null) {
                    $lineHeard[$line][] = $other[$o['b']];
                }
                if ($o['op'] === 'same' || $o['op'] === 'ins') {
                    if ($o['op'] === 'ins') {
                        $disputedLines[$line] = true;
                    }

                    continue;
                }
                $a = (int) $o['a'];
                $alt = $o['b'] === null ? '' : $other[$o['b']];
                [$i, $j] = $refs[$a];
                $disputedLines[$i] = true;
                $verdict = 'unsure';
                if (array_key_exists($a, $third)) {
                    $verdict = match (WordAlign::key($third[$a])) {
                        WordAlign::key($mine[$a]) => 'kept',
                        WordAlign::key($alt) => $alt === '' ? 'unsure' : 'probably',
                        default => 'unsure',
                    };
                }
                $stats[$verdict === 'kept' ? 'kept' : 'disputed']++;
                if ($verdict === 'probably') {
                    $stats['probably']++;
                }
                if ($j === null) {
                    $segments[$i]['uncertain'] = $segments[$i]['uncertain'] ?? true;

                    continue;
                }
                $word = $segments[$i]['words'][$j];
                $word['alt'] = $alt;
                $word['alt_by'] = $checker->value;
                if ($verdict !== 'kept') {
                    $confidence = is_numeric($word['confidence'] ?? null) ? (float) $word['confidence'] : 1.0;
                    $word['confidence'] = min($confidence, self::UNSURE_CONFIDENCE);
                }
                if ($verdict === 'probably') {
                    $word['probably'] = $alt;
                }
                if ($tie !== null && array_key_exists($a, $third)) {
                    $word['tie_by'] = $tie->value;
                }
                $segments[$i]['words'][$j] = $word;
            }
            foreach (array_keys($disputedLines) as $i) {
                $segments[$i]['check'] = ['provider' => $checker->value, 'label' => $checker->getLabel(), 'text' => implode(' ', $lineHeard[$i] ?? [])];
                $unsure = array_filter((array) ($segments[$i]['words'] ?? []), fn ($w): bool => is_array($w) && Word::isUncertain($w['confidence'] ?? null));
                if ($unsure !== [] || ($segments[$i]['words'] ?? []) === []) {
                    $segments[$i]['uncertain'] = true;
                }
            }
            if ($stats['disputed'] > $disputedBefore) {
                $stats['disputed_channels'][] = $channel;
            }
        }

        return ['segments' => $segments, 'stats' => $stats];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function remember(Transcript $transcript, array $summary): array
    {
        $summary['at'] = now()->toAtomString();
        Transcript::query()->whereKey($transcript->id)->update(['meta' => json_encode([...($transcript->meta ?? []), 'short_check' => $summary])]);

        return $summary;
    }
}
