<?php

declare(strict_types=1);

use App\Models\CaptureSession;
use App\Models\Screen;
use App\Services\Screens\ScreenDescriber;
use App\Services\Screens\ScreenStore;

// Shared by the screen moment tests (v1.5): pictures made with GD, a Claude stand-in and a screen kept directly.

if (function_exists('screenPicture')) {
    return;
}

/** A picture of a screen: a light page, a coloured box and a line of text, different for each seed. */
function screenPicture(string $type = 'webp', int $width = 1280, int $height = 720, int $seed = 1): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 240, 240, 245));
    imagefilledrectangle($image, 20 + $seed % 200, 20, 220 + $seed % 200, 120, (int) imagecolorallocate($image, ($seed * 37) % 256, ($seed * 91) % 256, 200));
    imagestring($image, 5, 40, 160, 'Invoice #'.$seed, (int) imagecolorallocate($image, 20, 20, 20));
    ob_start();
    match ($type) {
        'png' => imagepng($image),
        'jpeg' => imagejpeg($image, null, 85),
        default => imagewebp($image, null, 80),
    };

    return (string) ob_get_clean();
}

/**
 * Claude stand-in for screen descriptions: a fixed answer (or a failure), and what it was given.
 *
 * @param  array{ocr_text: string, description: string, input_tokens: int, output_tokens: int, model: string}|null  $answer
 */
function fakeScreenDescriber(?array $answer = null, ?Throwable $failure = null): object
{
    $fake = new class($answer, $failure) implements ScreenDescriber
    {
        /** @var list<array{0: string, 1: string}> */
        public array $calls = [];

        public function __construct(private readonly ?array $answer, private readonly ?Throwable $failure) {}

        public function describe(string $image, string $mime): array
        {
            $this->calls[] = [$mime, $image];
            if ($this->failure !== null) {
                throw $this->failure;
            }

            return $this->answer ?? ['ocr_text' => "Invoice #2041\nTotal PKR 85,000", 'description' => "The client's invoice page with the Pay button",
                'input_tokens' => 1500, 'output_tokens' => 60, 'model' => 'claude-haiku-5-5'];
        }
    };
    app()->instance(ScreenDescriber::class, $fake);

    return $fake;
}

/**
 * Keeps a picture for the recording as POST /api/sessions/{id}/screens would.
 *
 * @param  array<string, mixed>  $meta
 */
function keepScreen(CaptureSession $session, string $bytes, array $meta = []): Screen
{
    $path = (string) tempnam(sys_get_temp_dir(), 'eld-screen');
    file_put_contents($path, $bytes);
    try {
        $picture = ScreenStore::sniff($path);
        expect($picture)->not->toBeNull();
        [$screen] = app(ScreenStore::class)->add($session, $path, $picture, [
            'at_ms' => 1000, 'width' => 1920, 'height' => 1080, 'trigger' => 'change', 'sha256' => hash('sha256', $bytes), ...$meta,
        ]);
    } finally {
        @unlink($path);
    }

    return $screen;
}
