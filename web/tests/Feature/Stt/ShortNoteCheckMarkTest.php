<?php

declare(strict_types=1);

use App\Enums\SttProviderKey;
use App\Services\ShortNoteCheck;
use App\Stt\Data\Word;
use App\Stt\WordAlign;

/*
 * Phase 1C: two engines lined up word by word. Agreement is plain text; a disagreement is an unsure word with the
 * other version; a tie-breaker says which it probably is; the text itself never changes.
 */

/** The 8:19 pm note: Whisper heard «أفحم» where the speaker said «أفهم» ("I understand English"). */
function arabicNote(): array
{
    return [[
        'channel' => 1, 'speaker' => 'Speaker', 'start_ms' => 0, 'end_ms' => 2000, 'text' => 'أنا أفحم الإنجليزية',
        'words' => [
            ['text' => 'أنا', 'start_ms' => 0, 'end_ms' => 400, 'confidence' => 0.95],
            ['text' => 'أفحم', 'start_ms' => 450, 'end_ms' => 1100, 'confidence' => 0.81],
            ['text' => 'الإنجليزية', 'start_ms' => 1150, 'end_ms' => 2000, 'confidence' => 0.93],
        ],
    ]];
}

it('lines up words after folding case, punctuation and diacritics, and keeps real differences', function () {
    expect(WordAlign::key('Ahmed,'))->toBe(WordAlign::key('ahmed'))
        ->and(WordAlign::key('أفحم'))->not->toBe(WordAlign::key('أفهم'));

    $ops = WordAlign::align(['send', 'it', 'to', 'Farah'], ['send', 'it', 'Sara', 'today']);
    expect(array_column($ops, 'op'))->toBe(['same', 'same', 'sub', 'sub'])
        ->and(WordAlign::agreement($ops))->toBe(0.5);
    expect(array_column(WordAlign::align(['a', 'b', 'c'], ['a', 'c']), 'op'))->toBe(['same', 'del', 'same'])
        ->and(array_column(WordAlign::align(['a', 'c'], ['a', 'b', 'c']), 'op'))->toBe(['same', 'ins', 'same']);
});

it('marks the word two engines disagree on as unsure, with the other version, and leaves the text alone', function () {
    $out = ShortNoteCheck::mark(arabicNote(), [1 => 'أنا أفهم الإنجليزية'], SttProviderKey::ElevenLabs);
    $words = $out['segments'][0]['words'];

    expect($out['segments'][0]['text'])->toBe('أنا أفحم الإنجليزية')
        ->and($words[1]['alt'])->toBe('أفهم')
        ->and($words[1]['alt_by'])->toBe('elevenlabs')
        ->and(Word::isUncertain($words[1]['confidence']))->toBeTrue()
        ->and(isset($words[0]['alt']))->toBeFalse()
        ->and(Word::isUncertain($words[0]['confidence']))->toBeFalse()
        ->and($out['segments'][0]['uncertain'])->toBeTrue()
        ->and($out['segments'][0]['check']['text'])->toBe('أنا أفهم الإنجليزية')
        ->and($out['stats'])->toMatchArray(['words' => 3, 'disputed' => 1, 'probably' => 0, 'disputed_channels' => [1]]);
});

it('says which word it probably is when the tie-breaker sides with the second engine', function () {
    $out = ShortNoteCheck::mark(arabicNote(), [1 => 'أنا أفهم الإنجليزية'], SttProviderKey::ElevenLabs, [1 => 'أنا أفهم الإنجليزية.'], SttProviderKey::Gemini);
    $word = $out['segments'][0]['words'][1];

    expect($word['probably'])->toBe('أفهم')
        ->and($word['tie_by'])->toBe('gemini')
        ->and(Word::isUncertain($word['confidence']))->toBeTrue()
        ->and($out['stats']['probably'])->toBe(1);
});

it('keeps the first word, not unsure, when the tie-breaker sides with it', function () {
    $out = ShortNoteCheck::mark(arabicNote(), [1 => 'أنا أفهم الإنجليزية'], SttProviderKey::Groq, [1 => 'أنا أفحم الإنجليزية'], SttProviderKey::Gemini);
    $word = $out['segments'][0]['words'][1];

    expect(Word::isUncertain($word['confidence']))->toBeFalse()
        ->and($word['alt'])->toBe('أفهم')
        ->and(isset($word['probably']))->toBeFalse()
        ->and($out['stats'])->toMatchArray(['disputed' => 0, 'kept' => 1]);
});

it('marks nothing when both engines agree, or when the second heard something else entirely', function () {
    $same = ShortNoteCheck::mark(arabicNote(), [1 => 'أنا أفحم الإنجليزية'], SttProviderKey::Groq);
    expect($same['segments'])->toBe(arabicNote())->and($same['stats']['disputed'])->toBe(0);

    $other = ShortNoteCheck::mark(arabicNote(), [1 => 'I understand English very well'], SttProviderKey::Groq);
    expect($other['segments'])->toBe(arabicNote())->and($other['stats']['unreliable'])->toBe([1]);
});

it('marks a line without words as unsure as a whole, side by side', function () {
    $lines = [
        ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 2000, 'text' => 'Send it to Farah today'],
        ['channel' => 2, 'speaker' => 'Client', 'start_ms' => 2000, 'end_ms' => 4000, 'text' => 'Okay I will'],
    ];
    $out = ShortNoteCheck::mark($lines, [1 => 'Send it to Sara today', 2 => 'Okay I will'], SttProviderKey::Soniox);

    expect($out['segments'][0]['uncertain'])->toBeTrue()
        ->and($out['segments'][0]['check']['text'])->toBe('Send it to Sara today')
        ->and(isset($out['segments'][1]['uncertain']))->toBeFalse()
        ->and($out['stats']['disputed_channels'])->toBe([1]);
});
