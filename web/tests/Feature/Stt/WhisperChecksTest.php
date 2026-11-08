<?php

declare(strict_types=1);

use App\Stt\WhisperChecks;

/*
 * The shared 18.7 s Urdu voice note of 2026-11-08: Whisper wrote back the last words of our style prompt, and on a second
 * share looped. An answer like that is never kept.
 */

const OLD_URDU_PROMPT = 'السلام علیکم، آج کی meeting میں ہم invoice، payment اور deadline کی بات کریں گے۔ ٹھیک ہے، میں email بھیج دوں گا۔';

/** @param  list<string>  $lines */
function whisperJson(array $lines, ?float $duration = 18.5): array
{
    $segments = array_map(fn (string $t, int $i): array => ['start' => $i * 4.0, 'end' => $i * 4.0 + 4.0, 'text' => $t], $lines, array_keys($lines));

    return array_filter(['text' => implode(' ', $lines), 'duration' => $duration, 'segments' => $segments], fn ($v) => $v !== null);
}

it('catches the echo of the old style prompt on the 18.7 s voice note', function () {
    expect(WhisperChecks::problem(whisperJson(['ٹھیک ہے، میں email بھیج دوں گا۔']), 18.5, 17.0, OLD_URDU_PROMPT))->toBe('prompt_echo');
});

it('catches the loop of the second share, and far too few words for the speech', function () {
    expect(WhisperChecks::loops('ملتے ہیں، میں ملتے ہوں، میں ملتے ہوں۔'))->toBeTrue()
        ->and(WhisperChecks::loops('ok ok ok ok'))->toBeTrue()
        ->and(WhisperChecks::problem(whisperJson(['ملتے ہیں، میں ملتے ہوں، میں ملتے ہوں۔']), 18.5, 17.0, ''))->toBe('loop')
        ->and(WhisperChecks::problem(whisperJson(['جی ٹھیک ہے']), 18.5, 17.0, ''))->toBe('too_few_words');
});

it('catches audio of another length than was sent', function () {
    expect(WhisperChecks::problem(whisperJson(['فوڈ منیجمنٹ تو کرنا ہے'], 37.0), 18.5, 17.0, ''))->toBe('duration')
        ->and(WhisperChecks::problem(whisperJson(['فوڈ منیجمنٹ تو کرنا ہے'], 18.6), 18.5, 4.0, ''))->toBeNull();
});

it('keeps a real transcript of the note, a short answer to little speech, and silence', function () {
    $right = [
        'فوڈ منیجمنٹ تو کرنا ہے لیکن فوڈ منیجمنٹ کے علاوہ وہ جو اس میں ساری چیزیں تھی',
        'اوڈرز کسٹمرز وغیرہ وہ باقی جائے فیس ٹو میں کرے فیس تھری میں کرے لیکن کرنے تو ہے نا',
        'مطلب وہ جو کوسٹ آپ سے میں نے کہی تھی کہ کلاوڈ آپ کو لے دیں گے اور سکس ہنڈرڈ دے',
        'فائنل کیے تھے ڈالر میں',
    ];
    expect(WhisperChecks::problem(whisperJson($right), 18.5, 17.0, 'Omar, Acme Ltd.'))->toBeNull()
        ->and(WhisperChecks::problem(whisperJson(['Yes, see you then.'], 3.0), 3.0, 2.0, ''))->toBeNull()
        ->and(WhisperChecks::problem(whisperJson([], 10.0), 10.0, 0.5, ''))->toBeNull()
        ->and(WhisperChecks::loops('میں نے کہا ہاں ہاں ٹھیک ہے پھر ملتے ہیں کل'))->toBeFalse();
});
