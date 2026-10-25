<?php

declare(strict_types=1);

use App\Stt\Support\WavFile;

/** Write a PCM WAV whose samples are the given frames (each frame an array with one 16-bit value per channel). */
function writeWav(array $frames, int $channels = 1, int $rate = 8000, array $extraChunksBeforeData = [], int $format = 1): string
{
    $data = '';
    foreach ($frames as $frame) {
        foreach ((array) $frame as $sample) {
            $data .= pack('v', $sample & 0xFFFF);
        }
    }
    $block = $channels * 2;
    $extra = implode('', $extraChunksBeforeData);
    $bytes = 'RIFF'.pack('V', 4 + 24 + strlen($extra) + 8 + strlen($data)).'WAVE'
        .'fmt '.pack('VvvVVvv', 16, $format, $channels, $rate, $rate * $block, $block, 16)
        .$extra.'data'.pack('V', strlen($data)).$data;
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wav_'.bin2hex(random_bytes(6)).'.wav';
    file_put_contents($path, $bytes);

    return $path;
}

/** @return list<int> the 16-bit samples of a WAV's data chunk */
function wavSamples(string $path): array
{
    $wav = WavFile::open($path);
    $raw = substr((string) file_get_contents($path), $wav->dataOffset, $wav->dataBytes);

    return array_values(array_map(fn ($v) => $v > 32767 ? $v - 65536 : $v, unpack('v*', $raw) ?: []));
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'wav_*') ?: [] as $file) {
        @unlink($file);
    }
});

describe('reading', function () {
    it('reads the format of a mono file', function () {
        $wav = WavFile::open(writeWav(array_fill(0, 8000, 5), 1, 8000));

        expect($wav)->toMatchObject(['channels' => 1, 'sampleRate' => 8000, 'bitsPerSample' => 16, 'dataBytes' => 16000, 'dataOffset' => 44])
            ->and($wav->blockAlign())->toBe(2)->and($wav->durationMs())->toBe(1000);
    });

    it('computes duration for stereo and other rates', function () {
        expect(WavFile::open(writeWav(array_fill(0, 24000, [1, 2]), 2, 48000))->durationMs())->toBe(500)
            ->and(WavFile::open(writeWav(array_fill(0, 16000, 1), 1, 16000))->durationMs())->toBe(1000);
    });

    it('finds the data chunk after unrelated chunks', function () {
        $list = 'LIST'.pack('V', 5).'abcde'."\0"; // odd-sized chunk plus its pad byte
        $wav = WavFile::open(writeWav(array_fill(0, 100, 7), 1, 8000, [$list]));

        expect($wav->dataBytes)->toBe(200)->and(wavSamples($wav->path))->toBe(array_fill(0, 100, 7));
    });

    it('accepts the extensible PCM format tag', function () {
        $wav = WavFile::open(writeWav([1, 2, 3], 1, 8000, [], 0xFFFE));

        expect($wav->dataBytes)->toBe(6);
    });

    it('rejects float, compressed and non-WAV input', function () {
        expect(fn () => WavFile::open(writeWav([1], 1, 8000, [], 3)))->toThrow(RuntimeException::class, 'PCM');
        expect(fn () => WavFile::open(writeWav([1], 1, 8000, [], 2)))->toThrow(RuntimeException::class);

        $text = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wav_text.txt';
        file_put_contents($text, 'this is not a wav file at all, just text');
        expect(fn () => WavFile::open($text))->toThrow(RuntimeException::class, 'RIFF');
        expect(WavFile::isWav($text))->toBeFalse();
    });

    it('rejects a file with no data chunk', function () {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wav_nodata.wav';
        file_put_contents($path, 'RIFF'.pack('V', 28).'WAVE'.'fmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16));

        expect(fn () => WavFile::open($path))->toThrow(RuntimeException::class, 'no data');
    });

    it('detects a WAV by its signature and copes with missing or tiny files', function () {
        expect(WavFile::isWav(writeWav([1, 2])))->toBeTrue();
        set_error_handler(static fn (): bool => true); // the probe of a missing file raises a (suppressed) warning
        try {
            expect(WavFile::isWav(sys_get_temp_dir().DIRECTORY_SEPARATOR.'wav_does_not_exist.wav'))->toBeFalse();
        } finally {
            restore_error_handler();
        }
        $tiny = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wav_tiny.wav';
        file_put_contents($tiny, 'RIFF');
        expect(WavFile::isWav($tiny))->toBeFalse();
    });
});

describe('splitting stereo', function () {
    it('puts the left samples in the first file and the right in the second', function () {
        $frames = [[1, -1], [2, -2], [3, -3], [4, -4]];
        $source = WavFile::open(writeWav($frames, 2, 16000));
        $left = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wav_left.wav';
        $right = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wav_right.wav';

        $source->splitStereo($left, $right);

        expect(wavSamples($left))->toBe([1, 2, 3, 4])->and(wavSamples($right))->toBe([-1, -2, -3, -4]);
        expect(WavFile::open($left))->toMatchObject(['channels' => 1, 'sampleRate' => 16000, 'bitsPerSample' => 16]);
    });

    it('splits a long file across the internal read buffers without losing or shifting a sample', function () {
        $frames = [];
        for ($i = 0; $i < 50000; $i++) {
            $frames[] = [$i % 30000, -($i % 30000)];
        }
        $source = WavFile::open(writeWav($frames, 2, 16000));
        $left = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wav_left_big.wav';
        $right = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wav_right_big.wav';

        $source->splitStereo($left, $right);

        $l = wavSamples($left);
        $r = wavSamples($right);
        expect(count($l))->toBe(50000)->and(count($r))->toBe(50000)
            ->and($l[0])->toBe(0)->and($l[16384])->toBe(16384)->and($l[49999])->toBe(49999 % 30000)
            ->and($r[16384])->toBe(-16384)->and($r[49999])->toBe(-(49999 % 30000));
    });

    it('drops a trailing partial frame', function () {
        $path = writeWav([[1, 2], [3, 4]], 2, 8000);
        file_put_contents($path, "\x09\x00", FILE_APPEND); // half a frame beyond the declared data
        $source = WavFile::open($path);
        $left = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wav_left_p.wav';
        $right = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wav_right_p.wav';

        $source->splitStereo($left, $right);

        expect(wavSamples($left))->toBe([1, 3])->and(wavSamples($right))->toBe([2, 4]);
    });

    it('refuses a mono file', function () {
        $source = WavFile::open(writeWav([1, 2, 3]));

        expect(fn () => $source->splitStereo('a', 'b'))->toThrow(RuntimeException::class, '2-channel');
    });
});

describe('slicing', function () {
    it('copies the requested millisecond range', function () {
        $source = WavFile::open(writeWav(range(0, 7999), 1, 8000)); // one sample per 0.125 ms
        $out = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wav_slice.wav';

        $source->slice($out, 250, 500);

        $samples = wavSamples($out);
        expect($samples[0])->toBe(2000)->and(count($samples))->toBe(2000)->and(end($samples))->toBe(3999)
            ->and(WavFile::open($out)->durationMs())->toBe(250);
    });

    it('clamps an end beyond the file and returns the remainder', function () {
        $source = WavFile::open(writeWav(range(0, 7999), 1, 8000));
        $out = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wav_slice_end.wav';

        $source->slice($out, 900, 5000);

        expect(count(wavSamples($out)))->toBe(800);
    });

    it('writes a valid empty file for an empty or inverted range', function (int $start, int $end) {
        $source = WavFile::open(writeWav(range(0, 7999), 1, 8000));
        $out = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wav_slice_empty.wav';

        $source->slice($out, $start, $end);

        expect(WavFile::open($out)->dataBytes)->toBe(0);
    })->with([[500, 500], [600, 100], [2000, 3000]]);

    it('slices on frame boundaries for stereo so channels never swap', function () {
        $frames = [];
        for ($i = 0; $i < 8000; $i++) {
            $frames[] = [$i, -$i];
        }
        $source = WavFile::open(writeWav($frames, 2, 8000));
        $out = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wav_slice_stereo.wav';

        $source->slice($out, 100, 200);

        $samples = wavSamples($out);
        expect($samples[0])->toBe(800)->and($samples[1])->toBe(-800)->and(count($samples) % 2)->toBe(0);
    });

    it('produces slices that tile the original without gaps or overlap', function () {
        $source = WavFile::open(writeWav(range(0, 7999), 1, 8000));
        $joined = [];
        foreach ([[0, 300], [300, 700], [700, 1000]] as $i => [$start, $end]) {
            $out = sys_get_temp_dir().DIRECTORY_SEPARATOR."wav_tile_{$i}.wav";
            $source->slice($out, $start, $end);
            $joined = array_merge($joined, wavSamples($out));
        }

        expect($joined)->toBe(range(0, 7999));
    });
});

describe('header', function () {
    it('writes a canonical 44 byte PCM header', function () {
        $header = WavFile::header(2, 48000, 16, 1000);

        expect(strlen($header))->toBe(44)->and(substr($header, 0, 4))->toBe('RIFF')->and(substr($header, 8, 4))->toBe('WAVE')
            ->and(unpack('V', substr($header, 4, 4))[1])->toBe(1036)
            ->and(unpack('v', substr($header, 22, 2))[1])->toBe(2)
            ->and(unpack('V', substr($header, 24, 4))[1])->toBe(48000)
            ->and(unpack('V', substr($header, 28, 4))[1])->toBe(192000)
            ->and(unpack('v', substr($header, 32, 2))[1])->toBe(4)
            ->and(unpack('V', substr($header, 40, 4))[1])->toBe(1000);
    });
});
