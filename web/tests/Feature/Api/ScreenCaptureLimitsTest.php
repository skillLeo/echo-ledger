<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Exceptions\ApiError;
use App\Models\Screen;
use App\Services\Screens\ScreenSimilarity;
use App\Services\Screens\ScreenStore;
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
