<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Services\InterfaceFlags;
use App\Support\Translation\IsolatingTranslator;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Translation\Translator;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

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

it('always isolates, even a value that looks isolated, and leaves empty values and <tag> closures alone', function () {
    app()->setLocale('ur');

    expect(__('Hi :name', ['name' => FSI.'Ali'.PDI]))->toBe('Hi '.FSI.FSI.'Ali'.PDI.PDI)
        // Two isolated values joined (SourceStatsOverview's "of :limits") are isolated as one.
        ->and(__('of :limits', ['limits' => FSI.'60 min'.PDI.' · '.FSI.'$5'.PDI]))->toBe(FSI.FSI.'60 min'.PDI.' · '.FSI.'$5'.PDI.PDI.' میں سے')
        ->and(__('Hi :name.', ['name' => '']))->toBe('Hi .')
        ->and(__('Hi :name.', ['name' => null]))->toBe('Hi .')
        ->and(__('Read <b>this</b> :name', ['name' => 'Ali', 'b' => fn (string $t): string => "<strong>{$t}</strong>"]))
        ->toBe('Read <strong>this</strong> '.FSI.'Ali'.PDI);
});

it('never lets a typed name close its isolate and flip the sentence', function () {
    app()->setLocale('ur');
    $rlo = "\u{202E}";

    // A name that ends an isolate it never opened, then overrides the direction of everything after it.
    $line = __('Open the menu for :name', ['name' => FSI.'x'.PDI.PDI.$rlo.'evil']);

    expect($line)->toBe(FSI.FSI.'x'.PDI.$rlo.'evil'.PDI.' کا مینو کھولیں')
        // Every opener is closed inside the value's own isolate: the Urdu words come after the last PDI.
        ->and(mb_substr_count($line, PDI))->toBe(mb_substr_count($line, FSI));
});

it('closes every isolate a typed name opens, inside its own, so the rest of the sentence is never left isolated (W-233)', function () {
    app()->setLocale('ur');
    $rlo = "\u{202E}";
    $lri = "\u{2066}";

    expect(IsolatingTranslator::isolate('Ali'.FSI.'evil'))->toBe(FSI.'Ali'.FSI.'evil'.PDI.PDI)
        ->and(IsolatingTranslator::isolate('Ali'.$rlo.$lri.'x'))->toBe(FSI.'Ali'.$rlo.$lri.'x'.PDI.PDI)
        ->and(__('Open the menu for :name', ['name' => 'Ali'.FSI.'evil']))->toBe(FSI.'Ali'.FSI.'evil'.PDI.PDI.' کا مینو کھولیں');
});

it('uses the app\'s locale when the one given is empty, as Laravel does', function () {
    app()->setLocale('ur');

    expect(__('Hi :name', ['name' => 'Ali'], ''))->toBe('Hi '.FSI.'Ali'.PDI)
        ->and(trans_choice(':count recording|:count recordings', 2, [], ''))->toBe(FSI.'2'.PDI.' ریکارڈنگز')
        // A line Urdu has no plural for still fills in Urdu's isolates (not the English fallback's).
        ->and(trans_choice(':count file|:count files', 2))->toBe(FSI.'2'.PDI.' files');
});

it('is the translator the validator uses', function () {
    expect(validator([], [])->getTranslator())->toBeInstanceOf(IsolatingTranslator::class);
});

it('isolates a name on an Urdu console page', function () {
    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, true, 0, [], [], 'everyone');
    }
    $owner = Fx::user(['name' => 'Ahmed Khan']);
    $owner->forceFill(['console_preferences' => ['shell' => ['app_language' => 'ur']]])->save();
    Filament::setCurrentPanel('app');
    $this->actingAs($owner->fresh(), 'web');

    $html = (string) $this->get('/app')->assertOk()->getContent();

    expect($html)->toContain('aria-label="'.FSI.'Ahmed Khan'.PDI.' کا مینو کھولیں"');
});

it('isolates enums and stringable objects by their value', function () {
    app()->setLocale('ur');

    expect(__('Status :status', ['status' => SessionStatus::Completed]))->toBe('Status '.FSI.'completed'.PDI)
        ->and(__('Hi :name', ['name' => new HtmlString('<b>Ali</b>')]))->toBe('Hi '.FSI.'<b>Ali</b>'.PDI);
});

it('never puts isolate marks in the API, even for a person whose console is in Urdu', function () {
    $owner = Fx::user();
    $owner->forceFill(['console_preferences' => ['shell' => ['app_language' => 'ur']]])->save();
    $device = Fx::device($owner);
    $session = Fx::session($owner, $device, ['title' => 'Payment terms', 'caller_name' => 'Ahmed']);
    Fx::transcript($session, 'The proforma is ready.');
    $token = Fx::token($device);

    foreach (['/api/library', "/api/sessions/{$session->id}", '/api/activity', '/api/account', '/api/config'] as $uri) {
        $body = (string) $this->withToken($token)->getJson($uri)->assertOk()->getContent();
        expect($body)->not->toMatch('/\\\\u206[6-9]/i', $uri)->not->toMatch('/[\x{2066}-\x{2069}]/u', $uri);
    }
    expect(app()->getLocale())->toBe('en');
});
