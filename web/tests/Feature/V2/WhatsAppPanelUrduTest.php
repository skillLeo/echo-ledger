<?php

declare(strict_types=1);

use App\Filament\Support\WhatsAppActions;
use Illuminate\Support\Carbon;

/*
 * U9e: the Send to WhatsApp window (filament.library.whatsapp-panel) and the Sent to window (filament.pages.sent-to)
 * in the console's language. Urdu reads every line in Urdu; English reads exactly as before.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-17 11:42:00', 'UTC'));
});

afterEach(function () {
    app()->setLocale('en');
});

/** The WhatsApp window as the recording page draws it, with a transcript and a voice. */
function whatsAppPanel(string $language, ?string $audio = 'https://ledger.test/a.m4a'): string
{
    app()->setLocale($language);

    return view('filament.library.whatsapp-panel', [
        'id' => 's1', 'view' => null, 'text' => 'The proforma is ready.', 'cut' => false, 'audio' => $audio,
        'audio_name' => 'Payment terms.m4a', 'url' => 'https://wa.me/?text=x', 'copy_ai' => true,
    ])->render();
}

/** The Sent to window with one teammate who saved a copy. */
function sentToWindow(string $language): string
{
    app()->setLocale($language);
    $item = (object) [
        'id' => 'i1', 'note' => null,
        'recipient' => (object) ['name' => 'Ayesha', 'email' => 'ayesha@example.com'],
        'sent_at' => Carbon::parse('2026-11-17 11:42:00', 'UTC'), 'saved_at' => Carbon::parse('2026-11-17 12:00:00', 'UTC'),
    ];

    return view('filament.pages.sent-to', ['items' => collect([$item])])->render()
        .view('filament.pages.sent-to', ['items' => collect()])->render();
}

it('reads the WhatsApp window in Urdu: choices, hints, the attach steps and the words its buttons say', function () {
    $html = whatsAppPanel('ur');

    expect($html)
        ->toContain('صرف متن')->toContain('متن اور آواز')->toContain('لنک (7 دن تک چلے گا)')
        ->toContain('جس کے پاس یہ لنک ہو، وہ 7 دن تک پڑھ اور سن سکتا ہے۔')
        ->toContain('آواز آپ کے ڈاؤن لوڈز میں محفوظ ہو گئی ہے:')
        ->toContain('محفوظ کی گئی آواز کی فائل واٹس ایپ میں لگائیں')->toContain('<b>دستاویز</b>')
        ->toContain('متن کاپی کریں')->toContain('اے آئی کے لیے کاپی کریں')
        // The words the buttons say after a click come from the same translations, as JSON for Alpine.
        ->toContain('لنک نہیں بن سکا۔ دوبارہ کوشش کریں۔')->toContain('متن کاپی ہو گیا۔')->toContain('لنک بن رہا ہے…')
        ->not->toContain('Text only')->not->toContain('Copy text')->not->toContain('in the chat, click');
    expect(whatsAppPanel('ur', null))->toContain('جس کے پاس یہ لنک ہو، وہ 7 دن تک پڑھ سکتا ہے۔')->not->toContain('متن اور آواز');
});

it('keeps the WhatsApp window\'s English exactly as it was', function () {
    $html = whatsAppPanel('en');

    expect($html)
        ->toContain('<span>Text only</span>')->toContain('<span>Text and voice</span>')
        ->toContain('The text of the view you are reading.')
        ->toContain('The text in the message; the voice saved in your downloads to attach.')
        ->toContain('Anyone with it can read and listen for 7 days.')
        ->toContain('The voice is saved in your downloads: <b dir="auto">Payment terms.m4a</b>')
        ->toContain('<p><b>'.WhatsAppActions::ATTACH_NOTE.'</b>: in the chat, click <b>+</b> (or the paper clip), then <b>Document</b>, and choose this file. The text is in the message, and copied too.</p>')
        ->toContain('>Link (works for 7 days)</span>')->toContain('>Copy text</button>')->toContain('>Copy for AI</button>');
    expect(whatsAppPanel('en', null))->toContain('Anyone with it can read for 7 days.');
});

it('reads the Sent to window in Urdu, with the date in Urdu month names, and English as before', function () {
    $urdu = sentToWindow('ur');
    expect($urdu)
        ->toContain('بھیجا گیا')->toContain('17 نومبر 2026')
        ->toContain('انہوں نے ایک کاپی محفوظ کی، جو انہی کی رہے گی')
        ->toContain('شیئر کرنا بند کریں')->toContain('وہ اسے مزید نہیں کھول سکیں گے۔')
        ->toContain('ابھی کسی کو نہیں بھیجا۔ ساتھی کو بھیجیں استعمال کریں۔')
        ->not->toContain('Stop sharing');

    $english = sentToWindow('en');
    expect($english)
        ->toMatch('/Sent Nov 17, 2026 · \d{1,2}:\d{2} (AM|PM) · They saved a copy, which stays theirs /')
        ->toContain('wire:confirm="They will no longer be able to open it."')->toContain('Stop sharing')
        ->toContain('Not sent to anyone. Use Send to a teammate.');
});
