<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Support\Translation\IsolatingTranslator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Translation\Translator;

/*
 * B8: in Urdu and Arabic every :replacement of a translated line is a Unicode isolate (U+2068 ... U+2069), so a Latin
 * name or a number inside a right-to-left sentence keeps its direction. English and every other language are exactly
 * what Laravel's own translator makes.
 */

const FSI = "\u{2068}";
const PDI = "\u{2069}";

afterEach(fn () => app()->setLocale('en'));

/** Laravel's own translator on the same lines, for comparing byte for byte. */
function plainTranslator(): Translator
{
    $plain = new Translator(app('translation.loader'), app()->getLocale());
    $plain->setFallback(app('translator')->getFallback());

    return $plain;
}

it('is the translator everywhere', function () {
    expect(app('translator'))->toBeInstanceOf(IsolatingTranslator::class)->and(trans())->toBeInstanceOf(IsolatingTranslator::class);
});

it('leaves English byte for byte as Laravel makes it', function () {
    $plain = plainTranslator();
    $lines = [
        ['Open the menu for :name', ['name' => 'Ahmed Khan']],
        [':Name and :NAME', ['name' => 'ahmed']],
        ['validation.required', ['attribute' => 'email']],
        ['Nothing to fill in', []],
        ['Read <link>the guide</link> for :name', ['name' => 'Sara', 'link' => fn (string $text): string => "<a>{$text}</a>"]],
    ];
    foreach ($lines as [$key, $replace]) {
        expect(__($key, $replace))->toBe($plain->get($key, $replace));
    }
    expect(trans_choice(':count recording|:count recordings', 3))->toBe($plain->choice(':count recording|:count recordings', 3))
        ->and(__('Open the menu for :name', ['name' => 'Ahmed']))->not->toContain(FSI);
});

it('isolates every replacement in Urdu, translated or not', function () {
    app()->setLocale('ur');

    expect(__('Open the menu for :name', ['name' => 'Ahmed Khan']))->toBe(FSI.'Ahmed Khan'.PDI.' کا مینو کھولیں')
        ->and(__('page :n', ['n' => 12]))->toBe('صفحہ '.FSI.'12'.PDI)
        // A line with no Urdu yet falls back to its key, still isolated.
        ->and(__('Sent by :email on :date', ['email' => 'a@b.pk', 'date' => '21 Nov']))->toBe('Sent by '.FSI.'a@b.pk'.PDI.' on '.FSI.'21 Nov'.PDI)
        // Laravel's case changes still apply, inside the isolate.
        ->and(__(':Name / :NAME', ['name' => 'ahmed']))->toBe(FSI.'Ahmed'.PDI.' / '.FSI.'AHMED'.PDI);
});

it('isolates trans_choice counts and @lang in Blade', function () {
    app()->setLocale('ur');

    expect(trans_choice(':count recording|:count recordings', 3))->toBe(FSI.'3'.PDI.' ریکارڈنگز')
        ->and(trans_choice(':count recording|:count recordings', 1))->toBe(FSI.'1'.PDI.' ریکارڈنگ')
        ->and(Blade::render("@lang('Open the menu for :name', ['name' => 'Ali'])"))->toBe(e(FSI.'Ali'.PDI.' کا مینو کھولیں'))
        ->and(Blade::render("{{ __('page :n', ['n' => 2]) }}"))->toBe('صفحہ '.FSI.'2'.PDI);
});

it('follows the line\'s locale, not only the app\'s, and Arabic and regional locales too', function () {
    expect(__('page :n', ['n' => 4], 'ur'))->toBe('صفحہ '.FSI.'4'.PDI)
        ->and(__('Hi :name', ['name' => 'Ali'], 'ar'))->toBe('Hi '.FSI.'Ali'.PDI)
        ->and(__('Hi :name', ['name' => 'Ali'], 'ur_PK'))->toBe('Hi '.FSI.'Ali'.PDI);

    app()->setLocale('ur');
    expect(__('Hi :name', ['name' => 'Ali'], 'en'))->toBe('Hi Ali')
        ->and(trans_choice(':count recording|:count recordings', 2, [], 'en'))->toBe('2 recordings');
});

it('never isolates twice, an empty value, or a <tag> closure', function () {
    app()->setLocale('ur');

    expect(__('Hi :name', ['name' => FSI.'Ali'.PDI]))->toBe('Hi '.FSI.'Ali'.PDI)
        ->and(__('Hi :name', ['name' => "\u{2066}Ali".PDI]))->toBe("Hi \u{2066}Ali".PDI)
        ->and(__('Hi :name.', ['name' => '']))->toBe('Hi .')
        ->and(__('Hi :name.', ['name' => null]))->toBe('Hi .')
        ->and(__('Read <b>this</b> :name', ['name' => 'Ali', 'b' => fn (string $t): string => "<strong>{$t}</strong>"]))
        ->toBe('Read <strong>this</strong> '.FSI.'Ali'.PDI);
});

it('isolates enums and stringable objects by their value', function () {
    app()->setLocale('ur');

    expect(__('Status :status', ['status' => SessionStatus::Completed]))->toBe('Status '.FSI.'completed'.PDI)
        ->and(__('Hi :name', ['name' => new HtmlString('<b>Ali</b>')]))->toBe('Hi '.FSI.'<b>Ali</b>'.PDI);
});
