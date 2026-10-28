<?php

declare(strict_types=1);

use App\Stt\Data\Segment;
use App\Stt\Data\Word;

/*
 * Uncertain words (v1.2): one threshold for every engine's word confidence; a line can be uncertain as a whole
 * (Whisper's average log-probability, Azure's phrase score). The text always stays as heard.
 */

it('marks a word uncertain only below the threshold', function (mixed $confidence, bool $uncertain) {
    expect(Word::isUncertain($confidence))->toBe($uncertain);
})->with([
    'no confidence' => [null, false],
    'sure' => [0.97, false],
    'at the threshold' => [Word::UNCERTAIN_BELOW, false],
    'below' => [0.49, true],
    'zero' => [0, true],
    'a stored string' => ['0.2', true],
    'not a number' => ['low', false],
]);

it('keeps a line marked uncertain through storage', function () {
    $stored = (new Segment(1, 'Me', 0, 1000, 'Farah', [new Word('Farah', 0, 1000, 0.31)], true))->toArray();

    $read = Segment::fromArray($stored);

    expect($stored['uncertain'])->toBeTrue()
        ->and($read->uncertain)->toBeTrue()
        ->and(Word::isUncertain($stored['words'][0]['confidence']))->toBeTrue()
        ->and((new Segment(1, 'Me', 0, 1000, 'Hello'))->toArray())->not->toHaveKey('uncertain');
});
