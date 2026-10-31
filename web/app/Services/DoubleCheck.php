<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RecordingRole;
use App\Enums\SttProviderKey;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\CaptureSession;
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
use App\Support\UrduText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Double-check (since v1.2, off by default): a second engine listens again to the lines the first one was not sure
 * of. Each such line is cut from its speaker's audio and sent on its own; when the two engines disagree, the second
 * one's words are kept beside the line (`check`) and every app shows them under it. Nothing is merged: the transcript
 * text stays the first engine's. The clips are billed as their own usage row.
 */
final class DoubleCheck
{
    /** At most this many lines per transcript are heard again. */
    public const MAX_LINES = 12;

    /** A clip is at most this long; shorter lines than [MIN_LINE_MS] are not worth a request. */
    public const MAX_CLIP_S = 30.0;

    public const MIN_LINE_MS = 400;

    /** Audio around the line, so its first and last words are not cut. */
    public const PAD_S = 0.3;

    /** Two texts agree when at least this share of their words match (after folding spelling and punctuation). */
    public const AGREE_AT = 0.8;

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly SttManager $stt,
        private readonly LanguageRouter $router,
        private readonly AudioPrep $prep,
        private readonly ChunkedUploadService $uploads,
        private readonly UsageLedger $ledger,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('accuracy.double_check', false);
    }

    /** Since v1.4: recordings filed under a client are double-checked even with the general switch off (on by default). */
    public function enabledForClients(): bool
    {
        return (bool) $this->settings->get('accuracy.double_check_clients', true);
    }

    /** Whether a finished transcript should be double-checked now. */
    public function wanted(Transcript $transcript): bool
    {
        if ($transcript->kind !== TranscriptKind::Final || $transcript->status !== TranscriptStatus::Completed) {
            return false;
        }
        $forClient = $this->enabledForClients()
            && CaptureSession::query()->withoutGlobalScopes()->whereKey($transcript->capture_session_id)->whereNotNull('client_id')->exists();

        return ($this->enabled() || $forClient) && self::linesToCheck($transcript->segments ?? []) !== [];
    }

    /** The engine that listens again: Whisper on Groq, or ElevenLabs Scribe when Whisper wrote the transcript; null without a key. */
    public function checkerFor(Transcript $transcript): ?SttProviderKey
    {
        $first = $transcript->providerKey();
        foreach ([SttProviderKey::Groq, SttProviderKey::ElevenLabs] as $candidate) {
            if ($candidate !== $first && $this->router->configured($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The lines to hear again: marked uncertain, or with a word below the confidence threshold; never an empty, a
     * flagged or a very short line. The first [MAX_LINES] in time order.
     *
     * @param  array<int, array<string, mixed>>  $segments
     * @return list<int>
     */
    public static function linesToCheck(array $segments): array
    {
        $out = [];
        foreach ($segments as $i => $s) {
            if (trim((string) ($s['text'] ?? '')) === '' || isset($s['flag']) || ((int) ($s['end_ms'] ?? 0) - (int) ($s['start_ms'] ?? 0)) < self::MIN_LINE_MS) {
                continue;
            }
            $unsureWord = array_filter((array) ($s['words'] ?? []), fn ($w): bool => is_array($w) && Word::isUncertain($w['confidence'] ?? null)) !== [];
            if (($s['uncertain'] ?? false) === true || $unsureWord) {
                $out[] = (int) $i;
            }
            if (count($out) >= self::MAX_LINES) {
                break;
            }
        }

        return $out;
    }

    /** Whether two engines heard the same words (case, punctuation and Urdu spelling variants aside). */
    public static function agrees(string $a, string $b): bool
    {
        $words = fn (string $t): array => array_values(array_filter(preg_split('/\s+/u', trim((string) preg_replace('/[\p{P}\p{S}]+/u', ' ', mb_strtolower(UrduText::fold($t))))) ?: []));
        $x = $words($a);
        $y = $words($b);
        $longest = max(count($x), count($y));
        if ($longest === 0) {
            return true;
        }

        return 1 - self::distance($x, $y) / $longest >= self::AGREE_AT;
    }

    /**
     * Word-level edit distance.
     *
     * @param  list<string>  $x
     * @param  list<string>  $y
     */
    private static function distance(array $x, array $y): int
    {
        $previous = range(0, count($y));
        foreach ($x as $i => $wx) {
            $current = [$i + 1];
            foreach ($y as $j => $wy) {
                $current[] = min($previous[$j + 1] + 1, $current[$j] + 1, $previous[$j] + ($wx === $wy ? 0 : 1));
            }
            $previous = $current;
        }

        return $previous[count($y)];
    }

    /**
     * Hears the unsure lines again and stores the disagreements. Returns what it did (also kept in
     * `meta.double_check`).
     *
     * @return array<string, mixed>
     */
    public function run(Transcript $transcript): array
    {
        if (! $this->wanted($transcript)) {
            return ['skipped' => 'nothing_to_check'];
        }
        $session = $transcript->captureSession;
        $checker = $this->checkerFor($transcript);
        if ($checker === null) {
            return $this->remember($transcript, ['skipped' => 'no_second_engine']);
        }
        $skip = match (true) {
            ! $this->prep->available() => 'no_ffmpeg',
            app(BudgetGuard::class)->limitReached() || app(PlanLimits::class)->minutesReached($session->workspace_id) => 'limit_reached',
            default => null,
        };
        if ($skip !== null) {
            return $this->remember($transcript, ['skipped' => $skip]);
        }
        $driver = $this->stt->driver($checker);
        if (! $driver instanceof ClipTranscriber) {
            return $this->remember($transcript, ['skipped' => 'no_second_engine']);
        }

        $segments = $transcript->segments ?? [];
        $lines = self::linesToCheck($segments);
        $checks = [];
        $billedMs = 0;
        $failed = 0;
        $temps = [];
        try {
            $sides = $this->sides($session->recordings()->get()->keyBy(fn (Recording $r): string => $r->role->value)->all(), $temps);
            foreach ($lines as $i) {
                $s = $segments[$i];
                $wav = $sides[(int) ($s['channel'] ?? 1) === 2 ? 2 : 1] ?? $sides[1] ?? null;
                if ($wav === null) {
                    break;
                }
                $start = max(0.0, (int) $s['start_ms'] / 1000 - self::PAD_S);
                $end = min($start + self::MAX_CLIP_S, (int) $s['end_ms'] / 1000 + self::PAD_S);
                try {
                    $clip = $temps[] = $this->uploads->tempPath('check-'.$i).'.flac';
                    $heard = $driver->transcribeClip($this->prep->cut($wav, $start, $end, $clip), $end - $start, $transcript->language);
                } catch (Throwable $e) {
                    $failed++;
                    Log::warning('Double-check clip failed', ['transcript' => $transcript->id, 'provider' => $checker->value, 'error' => mb_substr($e->getMessage(), 0, 200)]);
                    if ($failed >= 2) {
                        break; // a key or quota problem: stop instead of failing every line
                    }

                    continue;
                }
                $billedMs += $heard['billed_ms'];
                if ($heard['text'] !== '' && ! self::agrees((string) $s['text'], $heard['text'])) {
                    $checks[$i] = ['text' => (string) $s['text'], 'heard' => $heard['text']];
                }
            }
        } finally {
            foreach ($temps as $temp) {
                @unlink($temp);
            }
        }

        $summary = ['provider' => $checker->value, 'checked' => count($lines), 'disagreements' => count($checks), 'failed' => $failed, 'at' => now()->toAtomString()];
        DB::transaction(function () use ($transcript, $checker, $checks, $summary): void {
            $fresh = Transcript::query()->lockForUpdate()->find($transcript->id);
            if ($fresh === null) {
                return;
            }
            $segments = $fresh->segments ?? [];
            foreach ($checks as $i => $check) {
                // A line edited meanwhile keeps the user's words and gets no note.
                if (($segments[$i]['text'] ?? null) === $check['text']) {
                    $segments[$i]['check'] = ['provider' => $checker->value, 'label' => $checker->getLabel(), 'text' => $check['heard']];
                }
            }
            $fresh->forceFill(['segments' => $segments, 'meta' => [...($fresh->meta ?? []), 'double_check' => $summary]])->save();
        });
        if ($billedMs > 0) {
            $this->ledger->recordCheck($transcript, $checker, $billedMs);
        }

        return $summary;
    }

    /**
     * Each speaker's audio as 16 kHz mono: the per-channel files when the device sent them, else each channel of a
     * stereo file, else the one mono file.
     *
     * @param  array<string, Recording>  $recordings
     * @param  list<string>  $temps
     * @return array<int, string>
     */
    private function sides(array $recordings, array &$temps): array
    {
        $out = [];
        $one = $recordings[RecordingRole::Channel1->value] ?? null;
        $two = $recordings[RecordingRole::Channel2->value] ?? null;
        if ($one?->isReady() && $two?->isReady()) {
            foreach ([1 => $one, 2 => $two] as $channel => $recording) {
                $plain = $temps[] = $this->uploads->decryptToTemp($recording);
                $out[$channel] = $this->prep->extractChannel($plain, null, $temps[] = $this->uploads->tempPath('check-side-'.$channel).'.wav');
            }

            return $out;
        }
        $primary = $recordings[RecordingRole::Primary->value] ?? null;
        if (! $primary instanceof Recording || ! $primary->isReady()) {
            return [];
        }
        $plain = $temps[] = $this->uploads->decryptToTemp($primary);
        if ((int) ($primary->channels ?? 1) === 2) {
            foreach ([1, 2] as $channel) {
                $out[$channel] = $this->prep->extractChannel($plain, $channel, $temps[] = $this->uploads->tempPath('check-side-'.$channel).'.wav');
            }

            return $out;
        }

        return [1 => $this->prep->extractChannel($plain, null, $temps[] = $this->uploads->tempPath('check-side-1').'.wav')];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function remember(Transcript $transcript, array $summary): array
    {
        $summary['at'] = now()->toAtomString();
        Transcript::query()->whereKey($transcript->id)->update(['meta' => json_encode([...($transcript->meta ?? []), 'double_check' => $summary])]);

        return $summary;
    }
}
