<?php

declare(strict_types=1);

use App\Http\Middleware\VisitorLanguage;
use App\Services\Accounts\SignUp;
use App\Services\CredentialVault;
use App\Services\SettingsRepository;
use Filament\Facades\Filament;
use Illuminate\Support\Js;
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
        ->toContain('<span lang="ur" aria-current="true" style="font-weight:600;color:var(--text)">اردو</span>')
        ->toMatch('~<a href="[^"]*\?lang=en" lang="en" hreflang="en"[^>]*>English</a>~');
})->with(['ur', 'ur-PK', 'ur-PK,ur;q=0.9,en;q=0.8']);

it('reads the sign-in page in English for an English, another or no browser language', function (?string $header) {
    expect(visitorPage($this, headers: $header === null ? [] : ['Accept-Language' => $header]))
        ->toMatch('~<html[^>]*lang="en"~')
        ->toContain('Sign in or create an account')->not->toContain('سائن اِن کریں')
        ->not->toContain('Noto Nastaliq Urdu')
        ->toContain('<span lang="en" aria-current="true" style="font-weight:600;color:var(--text)">English</span>')
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

it('words the other ways to sign in in the visitor\'s language, with the passkey errors and the 429 line, and English as before', function () {
    app(CredentialVault::class)->put('google', 'client_id', '1234-test.apps.googleusercontent.com');
    app(CredentialVault::class)->put('google', 'client_secret', 'secret');
    $js = fn (string $words): string => (string) Js::from($words);

    expect(visitorPage($this, cookies: [VisitorLanguage::COOKIE => 'ur']))
        ->toContain('<div class="el-or" role="separator" aria-label="یا"><span>یا</span></div>')
        ->toContain('<span>Google کے ساتھ جاری رکھیں</span>')
        ->toContain('title="پاس کی سے سائن اِن کریں: آپ کا فنگر پرنٹ، چہرہ یا ڈیوائس کا PIN"')
        ->toContain('<span>پاس کی</span>')
        ->toContain($js('اس پاس کی سے سائن اِن نہیں ہو سکا۔'))
        ->toContain($js('پاس کی سے سائن اِن منسوخ ہو گیا۔'))
        ->toContain($js('بہت زیادہ کوششیں ہو گئیں۔ ایک منٹ رکیں، پھر دوبارہ کوشش کریں۔'))
        ->not->toContain('Continue with Google')->not->toContain("'That passkey could not sign you in.'");

    expect(visitorPage($this, cookies: [VisitorLanguage::COOKIE => 'en']))
        ->toContain('<div class="el-or" role="separator" aria-label="or"><span>or</span></div>')
        ->toContain('<span>Continue with Google</span>')->toContain('title="Sign in with a passkey: your fingerprint, face or device PIN"')
        ->toContain('<span>Passkey</span>')->toContain("'That passkey could not sign you in.'")->toContain("'Passkey sign-in was cancelled.'")
        // A wording change in English too (since v2.1): a 429 says to wait, not Laravel's "Too Many Attempts.".
        ->toContain("answer.status === 429 ? 'Too many tries. Wait a minute, then try again.'");
});

it('answers a failed passkey sign-in in the visitor\'s language', function () {
    // A JSON request carries cookies only withCredentials(), as the page's fetch does with credentials: 'same-origin'.
    $answer = fn (string $language) => $this->withCredentials()->withCookie(VisitorLanguage::COOKIE, $language)
        ->postJson('/passkeys/login', ['credential' => ['id' => 'nope', 'rawId' => 'nope', 'type' => 'public-key', 'response' => []]]);

    $answer('ur')->assertStatus(422)->assertExactJson(['message' => 'اس پاس کی سے سائن اِن نہیں ہو سکا۔ دوبارہ کوشش کریں یا اپنا پاس ورڈ استعمال کریں۔']);
    app()->setLocale('en');
    $answer('en')->assertStatus(422)->assertExactJson(['message' => 'That passkey could not sign you in. Try again or use your password.']);
});

it('words the sign-up page in the visitor\'s language, and its invitation-only line', function () {
    app(SettingsRepository::class)->set('signup.mode', SignUp::ANYONE);
    expect(html_entity_decode(visitorPage($this, '/app/register', cookies: [VisitorLanguage::COOKIE => 'ur']), ENT_QUOTES))
        ->toContain('اپنا مفت اکاؤنٹ بنائیں')->toContain('اپنی ای میل لکھیں: ہم اس کی تصدیق کے لیے آپ کو ایک کوڈ بھیجتے ہیں۔')
        ->toMatch('~<title>\s*اپنا اکاؤنٹ بنائیں~u')
        ->toContain('<span lang="ur" aria-current="true"')
        ->not->toContain('Create your free account');

    app(SettingsRepository::class)->set('signup.mode', SignUp::INVITED);
    expect(html_entity_decode(visitorPage($this, '/app/register', cookies: [VisitorLanguage::COOKIE => 'ur']), ENT_QUOTES))
        ->toContain('اس سرور پر اکاؤنٹ صرف دعوت سے بنتا ہے۔ اسے چلانے والے سے یا کسی ورک اسپیس کے مالک سے دعوت مانگیں، پھر ان کا بھیجا ہوا لنک کھولیں۔')
        ->not->toContain('Sign-up on this server is by invitation.');
});

it('says a signed-out answer varies by Accept-Language and Cookie, so a cache never hands one visitor\'s language to another', function () {
    app()->setLocale('en');
    // Every Vary value the answer carries (Symfony keeps each one it was given).
    $vary = $this->withHeaders(['Accept-Language' => 'ur'])->get('/app/login')->assertOk()->baseResponse->getVary();
    expect($vary)->toContain('Accept-Language')->toContain('Cookie');
});

it('changes nothing for ?lang= once signed in: no cookie, and the account\'s language stays', function () {
    [$owner] = webParityWorld();
    webParityDesign(true);
    $prefs = is_array($owner->console_preferences) ? $owner->console_preferences : [];
    $prefs['shell'] = ['app_language' => 'en'];
    $owner->forceFill(['console_preferences' => $prefs])->save();
    $this->actingAs($owner->fresh(), 'web');
    app()->setLocale('en');

    $page = $this->get('/app/settings?lang=ur')->assertOk()->assertCookieMissing(VisitorLanguage::COOKIE);
    expect((string) $page->getContent())->toMatch('~<html[^>]*lang="en"~');
});
