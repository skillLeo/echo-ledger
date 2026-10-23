<?php

declare(strict_types=1);

namespace App\Stt\Support;

use RuntimeException;

/**
 * Minimal PCM WAV reader/writer (no decoding library needed on shared hosting). Used to split a stereo
 * recording into mono channel files and to slice long audio for whisper-server.
 */
final class WavFile
{
    public function __construct(
        public readonly string $path,
        public readonly int $channels,
        public readonly int $sampleRate,
        public readonly int $bitsPerSample,
        public readonly int $dataOffset,
        public readonly int $dataBytes,
    ) {}

    public static function isWav(string $path): bool
    {
        $h = @fopen($path, 'rb');
        if ($h === false) {
            return false;
        }
        $head = (string) fread($h, 12);
        fclose($h);

        return strlen($head) === 12 && substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WAVE';
    }

    public static function open(string $path): self
    {
        $h = fopen($path, 'rb');
        if ($h === false) {
            throw new RuntimeException('Cannot open WAV file.');
        }
        try {
            $head = (string) fread($h, 12);
            if (substr($head, 0, 4) !== 'RIFF' || substr($head, 8, 4) !== 'WAVE') {
                throw new RuntimeException('Not a RIFF/WAVE file.');
            }
            $fmt = null;
            while (! feof($h)) {
                $chunkHeader = fread($h, 8);
                if ($chunkHeader === false || strlen($chunkHeader) < 8) {
                    break;
                }
                /** @var array{id: string, size: int} $c */
                $c = unpack('a4id/Vsize', $chunkHeader);
                if ($c['id'] === 'fmt ') {
                    $fmt = unpack('vformat/vchannels/Vrate/Vbyterate/vblockalign/vbits', (string) fread($h, 16));
                    fseek($h, $c['size'] - 16 + ($c['size'] % 2), SEEK_CUR);
                } elseif ($c['id'] === 'data') {
                    if (! is_array($fmt) || ! in_array((int) $fmt['format'], [1, 0xFFFE], true)) {
                        throw new RuntimeException('Only PCM WAV is supported.');
                    }

                    return new self($path, (int) $fmt['channels'], (int) $fmt['rate'], (int) $fmt['bits'], (int) ftell($h), (int) $c['size']);
                } else {
                    fseek($h, $c['size'] + ($c['size'] % 2), SEEK_CUR);
                }
            }
            throw new RuntimeException('WAV file has no data chunk.');
        } finally {
            fclose($h);
        }
    }

    public function blockAlign(): int
    {
        return $this->channels * intdiv($this->bitsPerSample, 8);
    }

    public function durationMs(): int
    {
        $bytesPerSecond = $this->sampleRate * $this->blockAlign();

        return $bytesPerSecond > 0 ? (int) round($this->dataBytes / $bytesPerSecond * 1000) : 0;
    }

    public static function header(int $channels, int $sampleRate, int $bits, int $dataBytes): string
    {
        $block = $channels * intdiv($bits, 8);

        return 'RIFF'.pack('V', 36 + $dataBytes).'WAVE'
            .'fmt '.pack('VvvVVvv', 16, 1, $channels, $sampleRate, $sampleRate * $block, $block, $bits)
            .'data'.pack('V', $dataBytes);
    }

    /**
     * Splits an interleaved stereo WAV into two mono WAVs (channel 1 = left = Me, channel 2 = right = Client).
     *
     * @return array{0: string, 1: string}
     */
    public function splitStereo(string $leftPath, string $rightPath): array
    {
        if ($this->channels !== 2) {
            throw new RuntimeException('splitStereo needs a 2-channel WAV.');
        }
        $sampleBytes = intdiv($this->bitsPerSample, 8);
        $frames = intdiv($this->dataBytes, $this->blockAlign());
        $in = fopen($this->path, 'rb');
        $left = fopen($leftPath, 'wb');
        $right = fopen($rightPath, 'wb');
        if ($in === false || $left === false || $right === false) {
            throw new RuntimeException('Cannot split WAV channels.');
        }
        try {
            $monoBytes = $frames * $sampleBytes;
            fwrite($left, self::header(1, $this->sampleRate, $this->bitsPerSample, $monoBytes));
            fwrite($right, self::header(1, $this->sampleRate, $this->bitsPerSample, $monoBytes));
            fseek($in, $this->dataOffset);
            $remaining = $frames * $this->blockAlign();
            $framesPerRead = 16384;
            while ($remaining > 0) {
                $buffer = (string) fread($in, min($remaining, $framesPerRead * $this->blockAlign()));
                if ($buffer === '') {
                    break;
                }
                $remaining -= strlen($buffer);
                $l = '';
                $r = '';
                $len = strlen($buffer) - ($this->blockAlign() - 1);
                for ($i = 0; $i < $len; $i += $this->blockAlign()) {
                    $l .= substr($buffer, $i, $sampleBytes);
                    $r .= substr($buffer, $i + $sampleBytes, $sampleBytes);
                }
                fwrite($left, $l);
                fwrite($right, $r);
            }
        } finally {
            fclose($in);
            fclose($left);
            fclose($right);
        }

        return [$leftPath, $rightPath];
    }

    /**
     * Writes [startMs, endMs) of this WAV to a new file (header + byte range copy, no decoding).
     */
    public function slice(string $outPath, int $startMs, int $endMs): string
    {
        $bytesPerMs = $this->sampleRate * $this->blockAlign() / 1000;
        $start = (int) (floor($startMs * $bytesPerMs / $this->blockAlign()) * $this->blockAlign());
        $end = min($this->dataBytes, (int) (floor($endMs * $bytesPerMs / $this->blockAlign()) * $this->blockAlign()));
        $length = max(0, $end - $start);

        $in = fopen($this->path, 'rb');
        $out = fopen($outPath, 'wb');
        if ($in === false || $out === false) {
            throw new RuntimeException('Cannot slice WAV.');
        }
        try {
            fwrite($out, self::header($this->channels, $this->sampleRate, $this->bitsPerSample, $length));
            fseek($in, $this->dataOffset + $start);
            stream_copy_to_stream($in, $out, $length);
        } finally {
            fclose($in);
            fclose($out);
        }

        return $outPath;
    }
}
