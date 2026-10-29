<?php

declare(strict_types=1);

use App\Stt\ChannelLanguage;

/*
 * One language per side of a recording on Auto (v1.3): Urdu anywhere makes the side Urdu, English is kept only where
 * it is confidently pure, nothing is ever forced to English.
 */

function heard(?string $language, string $text, float $seconds = 30.0): array
{
    return ['language' => $language, 'text' => $text, 'seconds' => $seconds];
}

it('makes a side Urdu when any chunk is Urdu, however many were heard as English', function () {
    $side = [
        heard('en', 'Okay so the invoice is ready.'),
        heard('ur', 'آپ کل کی meeting کب ہے بتائیں'),
        heard('en', 'Send me the email please.'),
        heard('en', 'Yes, thank you.'),
    ];

    expect(ChannelLanguage::decide($side))->toBe('ur')
        ->and(ChannelLanguage::rerunAs('ur', $side[0]))->toBe('ur')
        ->and(ChannelLanguage::rerunAs('ur', $side[1]))->toBeNull();
});

it('hears Roman Urdu written by Whisper as Urdu, and plain English words as English', function () {
    expect(ChannelLanguage::evidence(heard('en', 'kya kar rahe ho, meeting kab hai?')))->toBe('ur')
        ->and(ChannelLanguage::evidence(heard('en', 'theek hai yaar')))->toBe('ur')
        // One word is not enough, and English words that look like Urdu are not counted.
        ->and(ChannelLanguage::evidence(heard('en', 'Okay, acha.')))->toBeNull()
        ->and(ChannelLanguage::evidence(heard('en', 'Talk to me on the main line, par for the course.')))->toBeNull()
        ->and(ChannelLanguage::decide([heard('en', 'kya kar rahe ho, meeting kab hai?')]))->toBe('ur');
});

it('keeps a pure English side English and never forces English on anything', function () {
    $side = [heard('en', 'The invoice is ready.'), heard('en', 'The payment is due on Friday.')];

    expect(ChannelLanguage::decide($side))->toBe('en')
        ->and(ChannelLanguage::rerunAs('en', $side[0]))->toBeNull()
        ->and(ChannelLanguage::rerunAs(null, $side[0]))->toBeNull();
});

it('keeps a long, pure English stretch English on an Urdu side, but not a short one or a mixed one', function () {
    $long = heard('en', 'We reviewed the whole contract and the payment terms in detail.', 75.0);
    $short = heard('en', 'We reviewed the contract.', 20.0);
    $mixed = heard('en', 'We reviewed the contract, theek hai, kya kehte ho?', 90.0);

    expect(ChannelLanguage::rerunAs('ur', $long))->toBeNull()
        ->and(ChannelLanguage::rerunAs('ur', $short))->toBe('ur')
        ->and(ChannelLanguage::rerunAs('ur', $mixed))->toBe('ur');
});

it('does not trust a two-word chunk heard as Urdu', function () {
    expect(ChannelLanguage::decide([heard('ur', 'ہاں جی'), heard('en', 'Right, see you tomorrow.')]))->toBe('en');
});

it('reads Urdu named as Arabic or Persian by its letters, and real Arabic as Arabic', function () {
    $urduAsArabic = heard('ar', 'میں نے آپ کو کل فون کیا تھا لیکن آپ نہیں تھے');
    $persian = heard(null, 'میں نے آپ کو کل فون کیا تھا');
    $arabic = heard('ar', 'مرحبا، سأرسل لك الفاتورة غداً إن شاء الله');

    expect(ChannelLanguage::evidence($urduAsArabic))->toBe('ur')
        ->and(ChannelLanguage::evidence($persian))->toBe('ur')
        ->and(ChannelLanguage::evidence($arabic))->toBe('ar')
        ->and(ChannelLanguage::decide([$arabic, heard('en', 'Okay, perfect.')]))->toBe('ar');
});

it('reads Hindi letters as Urdu, and says nothing for a silent side', function () {
    expect(ChannelLanguage::evidence(heard('ur', 'नमस्ते आप कैसे हैं')))->toBe('ur')
        ->and(ChannelLanguage::decide([heard(null, ''), heard('en', '')]))->toBeNull()
        ->and(ChannelLanguage::rerunAs('ur', heard('en', '')))->toBeNull();
});
