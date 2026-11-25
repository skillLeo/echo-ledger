<?php

declare(strict_types=1);

use App\Http\Middleware\VisitorLanguage;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures;

/*
 * v2.1: the language of the console's signed-out pages. The browser's Accept-Language, unless the person picked a
 * language on the sign-in page (kept in a cookie for signed-out pages only); signed in, the account's console language
 * always wins over both.
 */

require_once __DIR__.'/Support/parity_world.php';

afterEach(function () {
    app()->setLocale('en');
});

/** A signed-out page as drawn, sent with these headers and cookies. */
function visitorPage(object $test, string $url = '/app/login', array $headers = [], array $cookies = []): string
{
    app()->setLocale('en');
    $request = $test->withHeaders($headers);
    foreach ($cookies as $name => $value) {
        $request = $request->withCookie($name, $value);
    }

    return (string) $request->get($url)->assertOk()->getContent();
}

it('reads the sign-in page in Urdu for a browser that asks for Urdu: right to left, the Urdu face, the picker on Urdu', function (string $header) {
    expect(visitorPage($this, headers: ['Accept-Language' => $header]))
        ->toMatch('~<html[^>]*lang="ur"[^>]*dir="rtl"~')
        ->toContain('سائن اِن کریں یا اکاؤنٹ بنائیں')->not->toContain('Sign in or create an account')
        ->toContain('font-family: "Noto Nastaliq Urdu"')
        ->toContain('<span lang="ur" aria-current="true" style="font-weight:600;color:var(--gray-950)">اردو</span>')
        ->toMatch('~<a href="[^"]*\?lang=en" lang="en" hreflang="en"[^>]*>English</a>~');
})->with(['ur', 'ur-PK', 'ur-PK,ur;q=0.9,en;q=0.8']);

it('reads the sign-in page in English for an English, another or no browser language', function (?string $header) {
    expect(visitorPage($this, headers: $header === null ? [] : ['Accept-Language' => $header]))
        ->toMatch('~<html[^>]*lang="en"~')
        ->toContain('Sign in or create an account')->not->toContain('سائن اِن کریں')
        ->not->toContain('Noto Nastaliq Urdu')
        ->toContain('<span lang="en" aria-current="true" style="font-weight:600;color:var(--gray-950)">English</span>')
        ->toMatch('~<a href="[^"]*\?lang=ur" lang="ur" hreflang="ur"[^>]*>اردو</a>~');
})->with([['en-GB,en;q=0.9'], ['fr-FR,fr;q=0.9'], ['en-US,ur;q=0.5'], [null]]);

it('keeps a picked language in a cookie, and the cookie wins over the browser', function () {
    app()->setLocale('en');
    $this->withHeaders(['Accept-Language' => 'en'])->get('/app/login?lang=ur')->assertOk()
        ->assertSee('سائن اِن کریں یا اکاؤنٹ بنائیں')->assertCookie(VisitorLanguage::COOKIE, 'ur');

    expect(visitorPage($this, headers: ['Accept-Language' => 'en'], cookies: [VisitorLanguage::COOKIE => 'ur']))->toContain('سائن اِن کریں یا اکاؤنٹ بنائیں')
        ->and(visitorPage($this, headers: ['Accept-Language' => 'ur'], cookies: [VisitorLanguage::COOKIE => 'en']))->toContain('Sign in or create an account')
        ->not->toContain('سائن اِن کریں')
        // Another signed-out page follows the kept choice too.
        ->and(visitorPage($this, '/app/password-reset/request', ['Accept-Language' => 'en'], [VisitorLanguage::COOKIE => 'ur']))->toMatch('~<html[^>]*lang="ur"~');
});

it('ignores a language it does not have, in the address or the cookie', function () {
    app()->setLocale('en');
    $this->withHeaders(['Accept-Language' => 'en'])->get('/app/login?lang=fr')->assertOk()->assertCookieMissing(VisitorLanguage::COOKIE)
        ->assertSee('Sign in or create an account');
    expect(visitorPage($this, headers: ['Accept-Language' => 'ur'], cookies: [VisitorLanguage::COOKIE => 'fr']))->toContain('سائن اِن کریں یا اکاؤنٹ بنائیں');
});

it('runs for the sign-in form\'s own requests too (persistent), so its errors read in the same language', function () {
    Filament::getPanel('app');
    expect(Livewire::getPersistentMiddleware())->toContain(VisitorLanguage::class)
        ->and(Filament::getPanel('app')->getMiddleware())->toContain(VisitorLanguage::class);
});

it('lets the account\'s console language win once signed in, over the browser and the cookie', function () {
    [$owner] = webParityWorld();
    webParityDesign(true);
    $as = function (string $language) use ($owner): void {
        $prefs = is_array($owner->console_preferences) ? $owner->console_preferences : [];
        $prefs['shell'] = ['app_language' => $language];
        $owner->forceFill(['console_preferences' => $prefs])->save();
        app('auth')->forgetGuards();
        $this->actingAs($owner->fresh(), 'web');
    };

    $as('en');
    expect(visitorPage($this, '/app/settings', ['Accept-Language' => 'ur'], [VisitorLanguage::COOKIE => 'ur']))
        ->toMatch('~<html[^>]*lang="en"~')->not->toContain('data-visitor-language');
    $as('ur');
    expect(visitorPage($this, '/app/settings', ['Accept-Language' => 'en'], [VisitorLanguage::COOKIE => 'en']))
        ->toMatch('~<html[^>]*lang="ur"~');
});

it('answers the sign-in form\'s own request in the kept language: a suspended account is told in Urdu', function () {
    require_once __DIR__.'/../Accounts/Support/account_helpers.php';
    SecurityFixtures::user(['email' => 'farah@example.test', 'suspended_at' => now()]);
    $send = function (string $language): string {
        app()->setLocale('en');
        $page = $this->withCookie(VisitorLanguage::COOKIE, $language)->get('/app/login')->assertOk();
        preg_match('~wire:snapshot="([^"]+)"[^>]*wire:id="[^"]+"[^>]*wire:name="[^"]*login~i', (string) $page->getContent(), $m);
        expect($m)->not->toBeEmpty('the sign-in component on the page');
        $snapshot = html_entity_decode($m[1], ENT_QUOTES);
        app()->setLocale('en');
        $update = $this->withCookie(VisitorLanguage::COOKIE, $language)->withHeaders(['X-Livewire' => 'true', 'Accept-Language' => 'en'])
            ->postJson(app('livewire')->getUpdateUri(), ['_token' => session()->token(), 'components' => [[
                'snapshot' => $snapshot, 'updates' => ['data.email' => 'farah@example.test'], 'calls' => [['path' => '', 'method' => 'authenticate', 'params' => []]],
            ]]]);

        $component = $update->assertOk()->json('components.0');

        return html_entity_decode((string) ($component['effects']['html'] ?? ''), ENT_QUOTES).' '.(string) $component['snapshot'];
    };

    expect($send('ur'))->toContain('یہ اکاؤنٹ معطل ہے')
        ->and($send('en'))->toContain('This account is suspended')->not->toContain('یہ اکاؤنٹ معطل ہے');
});
