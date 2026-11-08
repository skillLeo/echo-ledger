<?php

declare(strict_types=1);

use App\Services\Views\SoundCheck;

/* Views only transliterate (since v1.5, Phase 1C): the original's words and a view's words compared sound for sound. */

it('makes the same skeleton of an Urdu word and its Roman Urdu, whatever the vowels and spelling', function (string $urdu, string $roman) {
    expect(SoundCheck::skeleton($urdu))->toBe(SoundCheck::skeleton($roman));
})->with([
    ['ٹھیک', 'theek'], ['ٹھیک', 'thik'], ['کیا', 'kya'], ['نہیں', 'nahi'], ['نہیں', 'nahin'], ['اچھا', 'acha'], ['اچھا', 'achha'],
    ['میٹنگ', 'meeting'], ['انوائس', 'invoice'], ['ضرور', 'zaroor'], ['فون', 'phone'], ['کمپنی', 'company'], ['آپ', 'aap'],
    ['ہے', 'hai'], ['رہا', 'raha'], ['ہوں', 'hoon'], ['خیال', 'khayal'], ['شکریہ', 'shukriya'], ['چاہیے', 'chahiye'],
    ['جی بی', 'GB'], ['۲۵۰۰', '2,500'],
]);

it('tells another word apart: "تعل" is not "dekh"', function () {
    expect(SoundCheck::sameSound(SoundCheck::skeleton('تعل'), SoundCheck::skeleton('dekh')))->toBeFalse()
        ->and(SoundCheck::matches('تعل رہا ہوں', 'dekh raha hoon'))->toBeFalse()
        ->and(SoundCheck::failing('تعل رہا ہوں', 'dekh raha hoon'))->toBe([0])
        ->and(SoundCheck::matches('تعل رہا ہوں', 'tal raha hoon'))->toBeTrue()
        ->and(SoundCheck::matches('کیا', 'hai'))->toBeFalse()
        ->and(SoundCheck::matches('16', '17'))->toBeFalse();
});

it('lets one word become two and two become one', function () {
    expect(SoundCheck::matches('آپ کیلئے لایا ہوں', 'aap ke liye laya hoon'))->toBeTrue()
        ->and(SoundCheck::matches('واٹس ایپ پر بھیج دو', 'WhatsApp par bhej do'))->toBeTrue()
        ->and(SoundCheck::matches('لیپ ٹاپ چاہیے', 'laptop chahiye'))->toBeTrue();
});

it('takes numbers, units, prices, dates and times as digits', function () {
    expect(SoundCheck::matches('مجھے سولہ جی بی والا چاہیے', 'mujhe 16 GB wala chahiye'))->toBeTrue()
        ->and(SoundCheck::matches('مجھے سولہ جی بی والا چاہیے', 'mujhe 16GB wala chahiye'))->toBeTrue()
        ->and(SoundCheck::matches('دو ہزار پانچ سو روپے دے دیں', 'Rs 2,500 de dein'))->toBeTrue()
        ->and(SoundCheck::matches('ساڑھے تین بجے آ جانا', '3:30 aa jana'))->toBeTrue()
        ->and(SoundCheck::matches('M6 Pro لے لو', 'M6 Pro le lo'))->toBeTrue()
        // A number put where a word was is not a transliteration.
        ->and(SoundCheck::matches('کل آ جانا', '16 aa jana'))->toBeFalse();
});

it('keeps markers on their words and notices words added or dropped', function () {
    expect(SoundCheck::matches('کل [?میٹنگ] ہے، [unclear] ٹھیک ہے', 'kal [?meeting] hai, [unclear] theek hai'))->toBeTrue()
        ->and(SoundCheck::matches('کل [unclear] ہے', 'kal hai'))->toBeFalse()
        ->and(SoundCheck::matches('کل میٹنگ ہے', 'kal meeting hai na bhai'))->toBeFalse()
        ->and(SoundCheck::added('کل میٹنگ ہے', 'kal meeting hai na bhai'))->toBe(2)
        ->and(SoundCheck::matches('کل میٹنگ ہے', 'kal hai'))->toBeFalse();
});

it('repairs a view by putting the original word back in plain letters where the sound changed', function () {
    expect(SoundCheck::repair('تعل رہا ہوں', 'dekh raha hoon', SoundCheck::plainRoman(...)))->toBe('tal raha hoon')
        ->and(SoundCheck::repair('کل میٹنگ ہے', 'kal meeting hai na bhai', SoundCheck::plainRoman(...)))->toBe('kal meeting hai')
        ->and(SoundCheck::repair('تعل رہا ہوں', 'دیکھ رہا ہوں', fn (string $w): string => $w))->toBe('تعل رہا ہوں')
        ->and(SoundCheck::plainRoman('سولہ'))->toBe('sola')
        ->and(SoundCheck::plainRoman('meeting'))->toBe('meeting');
});
