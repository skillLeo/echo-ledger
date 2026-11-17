<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Exceptions\ApiError;
use App\Models\Screen;
use App\Services\Screens\ScreenSimilarity;
use App\Services\Screens\ScreenStore;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

require_once __DIR__.'/Support/screen_helpers.php';

/*
 * The server's capture limits (v1.8 P4.1): a near-duplicate (the same screen sent again, re-compressed) is the screen
 * kept already, conservatively, so a genuinely different screen is never dropped; and 120 screens in any hour.
 */

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll(platform: 'windows');
    $this->asToken($this->token);
    $this->session = $this->makeReadySession($this->device, Fixtures::audio(3000), ['status' => SessionStatus::Completed]);
});

/**
 * A 1920×1080 screen: a title bar, a table of rows with [$rows] text, and a box at [$boxX]; as [$type] at [$quality].
 *
 * @param  list<string>  $rows
 */
function appScreen(array $rows, int $boxX = 1200, string $type = 'webp', int $quality = 80, ?array $pointer = null): string
{
    $image = imagecreatetruecolor(1920, 1080);
    imagefilledrectangle($image, 0, 0, 1919, 1079, (int) imagecolorallocate($image, 250, 250, 252));
    imagefilledrectangle($image, 0, 0, 1919, 60, (int) imagecolorallocate($image, 40, 60, 120));
    $ink = (int) imagecolorallocate($image, 20, 20, 30);
    foreach ($rows as $i => $row) {
        imagestring($image, 5, 80, 120 + $i * 40, $row, $ink);
        imageline($image, 60, 145 + $i * 40, 1100, 145 + $i * 40, (int) imagecolorallocate($image, 210, 210, 220));
    }
    imagefilledrectangle($image, $boxX, 300, $boxX + 400, 600, (int) imagecolorallocate($image, 230, 235, 250));
    if ($pointer !== null) {
        imagefilledpolygon($image, [$pointer[0], $pointer[1], $pointer[0], $pointer[1] + 22, $pointer[0] + 14, $pointer[1] + 16], $ink);
    }
    ob_start();
    match ($type) {
        'png' => imagepng($image),
        'jpeg' => imagejpeg($image, null, $quality),
        default => imagewebp($image, null, $quality),
    };

    return (string) ob_get_clean();
}

function orders(): array
{
    return ['Order 1041   Ali Traders      PKR 12,500   Paid', 'Order 1042   Noor Textiles    PKR 8,200    Due', 'Order 1043   Karim & Sons     PKR 3,900    Paid'];
}

it('keeps the same screen sent again, re-compressed, as the screen kept already', function () {
    $first = keepScreen($this->session, appScreen(orders(), type: 'webp', quality: 80), ['at_ms' => 60000]);
    foreach ([['jpeg', 92, 61000], ['png', 0, 75000], ['webp', 60, 50000]] as [$type, $quality, $at]) {
        expect(keepScreen($this->session, appScreen(orders(), type: $type, quality: $quality), ['at_ms' => $at])->id)->toBe($first->id, "{$type} at {$at}");
    }
    expect(Screen::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('never drops a genuinely different screen: one character, a moved box, a new row, another size, or far in time', function () {
    keepScreen($this->session, appScreen(orders()), ['at_ms' => 60000]);
    $changed = orders();
    $changed[1] = 'Order 1042   Noor Textiles    PKR 8,200    Paid'; // Due → Paid
    $oneDigit = orders();
    $oneDigit[0] = 'Order 1041   Ali Traders      PKR 12,600   Paid'; // one digit
    $variants = [
        'a word' => [appScreen($changed), []],
        'one digit' => [appScreen($oneDigit), []],
        'a moved box' => [appScreen(orders(), boxX: 1260), []],
        'a new row' => [appScreen([...orders(), 'Order 1044   Bilal Foods      PKR 1,100    Due']), []],
        'another size' => [appScreen(orders(), quality: 79), ['width' => 1280, 'height' => 720]],
        'the same, 6 minutes later' => [appScreen(orders(), quality: 70), ['at_ms' => 60000 + 360000]],
    ];
    $at = 61000;
    foreach ($variants as $what => [$bytes, $meta]) {
        $screen = keepScreen($this->session, $bytes, ['at_ms' => $at += 1000, ...$meta]);
        expect($screen->wasRecentlyCreated)->toBeTrue("{$what} was dropped");
    }
    expect(Screen::query()->withoutGlobalScopes()->count())->toBe(1 + count($variants));
});

it('judges the same picture the same and a changed one different, in grey at 640 px', function () {
    $a = ScreenSimilarity::signature(appScreen(orders()));
    $b = ScreenSimilarity::signature(appScreen(orders(), type: 'jpeg', quality: 85));
    $changed = orders();
    $changed[2] = 'Order 1043   Karim & Sons     PKR 3,900    Late';
    $c = ScreenSimilarity::signature(appScreen($changed));
    $digit = orders();
    $digit[0] = 'Order 1041   Ali Traders      PKR 12,600   Paid';
    expect(ScreenSimilarity::same($a, $b))->toBeTrue()->and(ScreenSimilarity::same($a, $c))->toBeFalse()
        ->and(ScreenSimilarity::same($a, ScreenSimilarity::signature(appScreen($digit))))->toBeFalse()
        ->and(ScreenSimilarity::same($a, ScreenSimilarity::signature(appScreen(orders(), type: 'png'))))->toBeTrue()
        ->and(ScreenSimilarity::signature('not a picture'))->toBeNull();
});

/** A PNG of [$width] × [$height] in one colour, made with GD. */
function plainPng(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    ob_start();
    imagepng($image, null, 9);

    return (string) ob_get_clean();
}

/** A PNG whose header claims [$width] × [$height] but holds no picture: only its header can be read. */
function claimedPng(int $width, int $height): string
{
    $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

    return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0)).$chunk('IEND', '');
}

it('never enlarges a picture to compare it, and leaves strips, huge and mis-sized pictures uncompared, quickly', function () {
    $started = microtime(true);
    expect(ScreenSimilarity::signature(plainPng(1, 100000)))->toBeNull()
        ->and(ScreenSimilarity::signature(plainPng(100000, 1)))->toBeNull()
        // Refused from the header alone, before GD decodes it.
        ->and(ScreenSimilarity::signature(claimedPng(30000, 30000)))->toBeNull()
        ->and(ScreenSimilarity::signature(appScreen(orders()), 1280, 720))->toBeNull()
        ->and(ScreenSimilarity::signature(appScreen(orders()), 1920, 1080))->not->toBeNull()
        ->and(microtime(true) - $started)->toBeLessThan(2.0);
    // A small picture keeps its size; a 4 to 1 one is still compared, at most 640 × 2560.
    [$w, $h, $grey] = ScreenSimilarity::signature(plainPng(320, 200));
    [$tallW, $tallH] = ScreenSimilarity::signature(plainPng(1000, 4000));
    expect([$w, $h, strlen($grey)])->toBe([320, 200, 64000])->and([$tallW, $tallH])->toBe([640, 2560]);
});

it('keeps a picture that is not the size it claims as a screen of its own', function () {
    $first = keepScreen($this->session, appScreen(orders()), ['at_ms' => 60000, 'width' => 1280, 'height' => 720]);
    // The same 1920 × 1080 picture again, re-compressed, claiming 1280 × 720 like its neighbour: never compared.
    $again = keepScreen($this->session, appScreen(orders(), type: 'jpeg', quality: 92), ['at_ms' => 61000, 'width' => 1280, 'height' => 720]);
    expect($again->id)->not->toBe($first->id)->and($again->wasRecentlyCreated)->toBeTrue();
});

/**
 * A screen at [$width] × [$height] drawn at half size and doubled, as a Retina or 4K screen at 200 % shows it: the
 * table of [$rows], and a 2 px separator at [$lineY] when given. PNG, quickly compressed.
 *
 * @param  list<string>  $rows
 */
function hiDpiScreen(int $width, int $height, array $rows, ?int $lineY = null): string
{
    $base = imagecreatetruecolor(intdiv($width, 2), intdiv($height, 2));
    imagefilledrectangle($base, 0, 0, $width, $height, (int) imagecolorallocate($base, 250, 250, 252));
    imagefilledrectangle($base, 0, 0, $width, 30, (int) imagecolorallocate($base, 40, 60, 120));
    foreach ($rows as $i => $row) {
        imagestring($base, 5, 80, 120 + $i * 40, $row, (int) imagecolorallocate($base, 20, 20, 30));
    }
    $image = imagecreatetruecolor($width, $height);
    imagecopyresized($image, $base, 0, 0, 0, 0, $width, $height, intdiv($width, 2), intdiv($height, 2));
    if ($lineY !== null) {
        imagefilledrectangle($image, 160, $lineY, 1400, $lineY + 1, (int) imagecolorallocate($image, 200, 200, 208));
    }
    ob_start();
    imagepng($image, null, 1);

    return (string) ob_get_clean();
}

it('keeps one changed digit or a new 2 px line on 4K and 5K screens: shrunk by at most 3 times', function () {
    $digit = orders();
    $digit[0] = 'Order 1041   Ali Traders      PKR 12,600   Paid';
    $at = 0;
    foreach ([[3840, 2160], [5120, 2160]] as [$w, $h]) {
        $size = ['width' => $w, 'height' => $h];
        $first = keepScreen($this->session, hiDpiScreen($w, $h, orders()), ['at_ms' => $at += 10_000, ...$size]);
        expect(ScreenSimilarity::signature(hiDpiScreen($w, $h, orders()))[0])->toBe(max(640, (int) ceil($w / 3)));
        foreach (['one digit' => hiDpiScreen($w, $h, $digit), 'a 2 px line' => hiDpiScreen($w, $h, orders(), 701)] as $what => $bytes) {
            $screen = keepScreen($this->session, $bytes, ['at_ms' => $at += 1000, ...$size]);
            expect($screen->wasRecentlyCreated)->toBeTrue("{$what} at {$w} px was dropped")->and($screen->id)->not->toBe($first->id);
        }
    }
    expect(Screen::query()->withoutGlobalScopes()->count())->toBe(6);
});

it('compares no picture above 12 megapixels: a 5K screen is kept as a screen of its own', function () {
    expect(ScreenSimilarity::signature(plainPng(5120, 2880)))->toBeNull()
        ->and(ScreenSimilarity::signature(plainPng(4000, 3000)))->not->toBeNull();
});

/** A [$width] × [$height] RGB PNG of one colour, written row by row: a large picture in a small file, cheaply. */
function largePng(int $width, int $height): string
{
    $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    $deflate = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => 9]);
    $row = "\0".str_repeat("\xF0\xF0\xF5", $width);
    $data = '';
    for ($y = 0; $y < $height; $y++) {
        $data .= deflate_add($deflate, $row, ZLIB_NO_FLUSH);
    }
    $data .= deflate_add($deflate, '', ZLIB_FINISH);

    return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0)).$chunk('IDAT', $data).$chunk('IEND', '');
}

/** 128M, or a little above what this test process already uses (a long parallel run can pass 128M): below 512M. */
function lowMemoryLimit(): string
{
    $megabytes = max(128, intdiv(memory_get_usage(true), 1024 * 1024) + 64);
    if ($megabytes >= 512) {
        test()->markTestSkipped('This test process already uses most of 512M.');
    }

    return $megabytes.'M';
}

it('stores a 25 megapixel screen on a server with a 128M memory limit, raising it to decode', function () {
    // GD counts its pixels toward memory_limit only when PHP is built with its own (bundled) GD, as many servers are;
    // a system libgd (Homebrew's) does not, so the raise itself is checked too.
    $bytes = largePng(5000, 5000);
    $limit = ini_get('memory_limit');
    try {
        expect(ini_set('memory_limit', lowMemoryLimit()))->not->toBeFalse();
        $response = $this->post("/api/sessions/{$this->session->id}/screens", [
            'image' => UploadedFile::fake()->createWithContent('screen.png', $bytes),
            'at_ms' => 1000, 'width' => 5000, 'height' => 5000, 'trigger' => 'change', 'sha256' => hash('sha256', $bytes),
        ], ['Accept' => 'application/json']);
        $during = ini_get('memory_limit');
    } finally {
        ini_set('memory_limit', (string) $limit);
    }
    $response->assertCreated();
    expect(Screen::query()->withoutGlobalScopes()->count())->toBe(1)->and($during)->toBe((string) ScreenStore::DECODE_MEMORY);
});

it('raises a lower memory limit to 512M for decoding, and leaves a higher or no limit alone', function () {
    $limit = ini_get('memory_limit');
    try {
        $after = [];
        $low = lowMemoryLimit();
        foreach ([$low, ((int) $low * 1024).'K', '2G', '-1'] as $set) {
            ini_set('memory_limit', $set);
            ScreenStore::roomToDecode();
            $after[] = ini_get('memory_limit');
        }
    } finally {
        ini_set('memory_limit', (string) $limit);
    }
    expect($after)->toBe([(string) ScreenStore::DECODE_MEMORY, (string) ScreenStore::DECODE_MEMORY, '2G', '-1']);
});

it('keeps at most 120 screens in any hour of a recording, on top of 400 in all', function () {
    foreach (range(1, 120) as $i) {
        Screen::query()->withoutGlobalScopes()->create(['workspace_id' => $this->session->workspace_id, 'capture_session_id' => $this->session->id,
            'at_ms' => $i * 25000, 'width' => 1920, 'height' => 1080, 'trigger' => 'change', 'sha256' => hash('sha256', (string) $i), 'mime' => 'image/webp', 'bytes' => 1]);
    }
    // The 121st within the hour up to its moment: refused.
    expect(fn () => keepScreen($this->session, appScreen(['A new screen']), ['at_ms' => 3_000_000]))
        ->toThrow(ApiError::class, '120 screens in an hour');
    // An hour after the first ones: fits.
    expect(keepScreen($this->session, appScreen(['Later on']), ['at_ms' => 3_000_000 + 3_600_000])->wasRecentlyCreated)->toBeTrue()
        ->and(ScreenStore::MAX_PER_RECORDING)->toBe(400);
});
