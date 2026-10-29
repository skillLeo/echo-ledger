<?php

declare(strict_types=1);

namespace App\Stt\Audio;

use App\Services\SettingsRepository;
use RuntimeException;

/**
 * Audio preparation for engines that work best on short mono pieces (Whisper): each side of the call on its own
 * (me / the other person) as 16 kHz mono, long silences skipped, cut at pauses into chunks of at most
 * `audio.chunk_seconds` (600), each with its exact offset in the recording.
 *
 * Uses a static ffmpeg (tested on the production host: `~/bin/ffmpeg`, started with proc_open, about one second to
 * split and resample ten minutes). Without ffmpeg the caller sends the whole file instead ([available] is false).
 */
class AudioPrep
{
    /** A pause shorter than this does not end a chunk early. */
    public const MIN_PAUSE_SECONDS = 0.6;

    /** @var (callable(list<string>): array{0: int, 1: string})|null */
    private $runner;

    /** @param  (callable(list<string>): array{0: int, 1: string})|null  $runner  runs ffmpeg (args) → [exit code, stderr]; tests pass a fake */
    public function __construct(private readonly SettingsRepository $settings, ?callable $runner = null)
    {
        $this->runner = $runner;
    }

    /** The ffmpeg binary: the console setting, then FFMPEG_PATH, then ~/bin/ffmpeg, then the system one. */
    public function ffmpeg(): ?string
    {
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? null);
        $candidates = array_filter([
            $this->settings->get('audio.ffmpeg_path'),
            getenv('FFMPEG_PATH') ?: null,
            is_string($home) ? rtrim($home, '/').'/bin/ffmpeg' : null,
            '/usr/bin/ffmpeg',
            '/usr/local/bin/ffmpeg',
        ], fn ($p): bool => is_string($p) && $p !== '');
        foreach ($candidates as $path) {
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    public function available(): bool
    {
        return $this->runner !== null || ($this->ffmpeg() !== null && function_exists('proc_open'));
    }

    /**
     * One side of [$input] as 16 kHz mono WAV: channel 1 (left, me) or 2 (right, the other person); null = mix
     * a mono file as it is.
     */
    public function extractChannel(string $input, ?int $channel, string $out): string
    {
        $filter = $channel === null ? 'aresample=16000' : 'pan=mono|c0=c'.($channel - 1).',aresample=16000';
        $this->run(['-i', $input, '-af', $filter, '-ac', '1', '-ar', '16000', '-c:a', 'pcm_s16le', '-y', $out]);

        return $out;
    }

    /**
     * Silences of at least MIN_PAUSE_SECONDS below `audio.silence_db` (-40 dB) in [$wav].
     *
     * @return list<array{0: float, 1: float}> start and end seconds
     */
    public function silences(string $wav): array
    {
        $db = (float) $this->settings->get('audio.silence_db', -40);
        [, $log] = $this->run(['-i', $wav, '-af', 'silencedetect=n='.$db.'dB:d='.self::MIN_PAUSE_SECONDS, '-f', 'null', '-']);

        return self::parseSilences($log);
    }

    /**
     * ffmpeg's silencedetect log as intervals (an open silence at the end is closed at the end of the file).
     *
     * @return list<array{0: float, 1: float}>
     */
    public static function parseSilences(string $log, ?float $durationS = null): array
    {
        $out = [];
        $start = null;
        foreach (preg_split('/\R/', $log) ?: [] as $line) {
            if (preg_match('/silence_start:\s*(-?[\d.]+)/', $line, $m) === 1) {
                $start = max(0.0, (float) $m[1]);
            } elseif (preg_match('/silence_end:\s*([\d.]+)/', $line, $m) === 1 && $start !== null) {
                $out[] = [$start, (float) $m[1]];
                $start = null;
            }
        }
        if ($start !== null && $durationS !== null && $durationS > $start) {
            $out[] = [$start, $durationS];
        }

        return $out;
    }

    /**
     * Since v1.3: a chunk that starts where the one before was cut inside speech (no pause long enough there) starts
     * [$overlapS] earlier, so no word is lost in the cut; `keep_from` is where its own part begins, and anything the
     * engine places before it is left out when the chunks are joined (no word twice). Cuts made in a pause need no
     * overlap. Pure; unit-tested.
     *
     * @param  list<array{0: float, 1: float}>  $plan  from [plan]
     * @param  list<array{0: float, 1: float}>  $silences
     * @return list<array{start: float, end: float, keep_from: float}>
     */
    public static function overlapHardCuts(array $plan, array $silences, float $overlapS = 1.0): array
    {
        $out = [];
        foreach ($plan as $i => [$start, $end]) {
            $previousEnd = $i > 0 ? $plan[$i - 1][1] : null;
            $inPause = false;
            foreach ($silences as [$s, $e]) {
                if ($start >= $s - 0.01 && $start <= $e + 0.01) {
                    $inPause = true;
                    break;
                }
            }
            $hardCut = $previousEnd !== null && abs($previousEnd - $start) < 0.001 && ! $inPause;
            $out[] = ['start' => $hardCut ? max(0.0, round($start - $overlapS, 3)) : $start, 'end' => $end, 'keep_from' => $start];
        }

        return $out;
    }

    /**
     * Where to cut [$durationS] seconds of audio with [$silences]: pieces of speech of at most [$maxS] seconds, cut
     * in the middle of a pause near the limit (never mid-word when a pause exists), with long silences left out.
     * Pure; unit-tested.
     *
     * [$cuts] are extra cut points in seconds (where the language chip changed): a chunk never spans one, so each
     * chunk has one language.
     *
     * @param  list<array{0: float, 1: float}>  $silences
     * @param  list<float>  $cuts
     * @return list<array{0: float, 1: float}> start and end seconds of each chunk
     */
    public static function plan(float $durationS, array $silences, float $maxS = 600.0, float $skipSilenceS = 3.0, array $cuts = []): array
    {
        if ($durationS <= 0) {
            return [];
        }
        usort($silences, fn (array $a, array $b): int => $a[0] <=> $b[0]);
        // Speech = the gaps between silences; a long silence is left out, a short pause stays inside the speech.
        $speech = [];
        $cursor = 0.0;
        foreach ($silences as [$s, $e]) {
            $s = max(0.0, min($durationS, $s));
            $e = max($s, min($durationS, $e));
            if ($e - $s >= $skipSilenceS) {
                if ($s - $cursor > 0.05) {
                    $speech[] = [$cursor, $s + 0.25];
                }
                $cursor = max($cursor, $e - 0.25);
            }
        }
        if ($durationS - $cursor > 0.05) {
            $speech[] = [$cursor, $durationS];
        }
        $pauses = array_map(fn (array $p): float => ($p[0] + $p[1]) / 2, array_values(array_filter($silences, fn (array $p): bool => $skipSilenceS > $p[1] - $p[0])));

        // Language changes cut the speech too.
        $cuts = array_values(array_filter($cuts, fn (float $c): bool => $c > 0 && $c < $durationS));
        sort($cuts);
        $pieces = [];
        foreach ($speech as [$start, $end]) {
            foreach ($cuts as $cut) {
                if ($cut > $start + 0.3 && $cut < $end - 0.3) {
                    $pieces[] = [$start, $cut];
                    $start = $cut;
                }
            }
            $pieces[] = [$start, $end];
        }
        $speech = $pieces;

        $chunks = [];
        foreach ($speech as [$start, $end]) {
            while ($end - $start > $maxS) {
                // The last pause inside the allowed window (not too close to its start); else a hard cut at the limit.
                $limit = $start + $maxS;
                $cut = null;
                foreach ($pauses as $p) {
                    if ($p > $start + $maxS * 0.5 && $p < $limit) {
                        $cut = $p;
                    }
                }
                $cut ??= $limit;
                $chunks[] = [round($start, 3), round($cut, 3)];
                $start = $cut;
            }
            if ($end - $start > 0.3) {
                $chunks[] = [round($start, 3), round($end, 3)];
            }
        }

        return $chunks;
    }

    /** Seconds of audio in a WAV written by [extractChannel]. */
    public function durationSeconds(string $wav): float
    {
        return self::wavSeconds($wav);
    }

    /** Seconds of audio in a 16 kHz mono 16-bit WAV written by [extractChannel]. */
    public static function wavSeconds(string $wav): float
    {
        $bytes = @filesize($wav);

        return $bytes === false ? 0.0 : max(0, $bytes - 44) / 32000;
    }

    /** One chunk of [$wav] as FLAC (lossless, small enough for a direct upload of ten minutes of speech). */
    public function cut(string $wav, float $startS, float $endS, string $out): string
    {
        $this->run(['-ss', sprintf('%.3f', $startS), '-to', sprintf('%.3f', $endS), '-i', $wav, '-c:a', 'flac', '-y', $out]);

        return $out;
    }

    /**
     * @param  list<string>  $args
     * @return array{0: int, 1: string} exit code and stderr (ffmpeg logs there)
     */
    private function run(array $args): array
    {
        $args = ['-nostdin', '-hide_banner', '-loglevel', 'info', ...$args];
        if ($this->runner !== null) {
            return ($this->runner)($args);
        }
        $bin = $this->ffmpeg() ?? throw new RuntimeException('ffmpeg is not installed on this server.');
        $process = proc_open([$bin, ...$args], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('ffmpeg could not be started.');
        }
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) {
            throw new RuntimeException('ffmpeg failed: '.mb_substr(trim(substr($stderr, -400)), 0, 400));
        }

        return [$code, $stderr];
    }
}
