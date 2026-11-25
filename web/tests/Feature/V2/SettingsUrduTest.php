<?php

declare(strict_types=1);

use App\Filament\Auth\EditProfile;
use App\Filament\Support\CommandPalette;
use App\Filament\Support\SettingsContext;
use App\Models\Plan;
use App\Models\Workspace;
use App\Services\Accounts\SettingsIndex;
use App\Services\Accounts\SettingsRegistry;
use Filament\Actions\Action;
use Livewire\Livewire;

/*
 * Urdu package 3: Settings in the console's language. Every Settings page (the views under filament.settings.pages and
 * their data: ManagesSecurityPages, ManagesPlanPage, ManagesAppPages), the settings search, Sign-in activity and Get the
 * apps read in Urdu in the new frame, with the security actions' windows and notices and the promo code window; in English
 * the same places read exactly as before, and classic keeps its saved HTML (ClassicGoldenTest).
 */

require_once __DIR__.'/Support/parity_world.php';

beforeEach(function () {
    [$this->owner, $this->pages] = webParityWorld();
});

/** Signs the person in with the console in this language, in the new design. */
function settingsUrduAs(object $test, string $language): void
{
    webParityDesign(true);
    $prefs = is_array($test->owner->console_preferences) ? $test->owner->console_preferences : [];
    $prefs['shell'] = ['app_language' => $language];
    $test->owner->forceFill(['console_preferences' => $prefs])->save();
    app('auth')->forgetGuards();
    SettingsContext::forget();
    $test->actingAs($test->owner->fresh(), 'web');
    app()->setLocale($language);
}

/** A page as drawn, its entities read and without the invisible direction marks around names and numbers (B8). */
function settingsUrduHtml(object $test, string $url): string
{
    SettingsContext::forget();
    $html = (string) $test->get($url)->assertOk()->getContent();

    return html_entity_decode(preg_replace('/[\x{2066}-\x{2069}]/u', '', $html) ?? $html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** The title of a Settings row (`#setting-{id}`), as shown. */
function settingsUrduRow(string $html, string $id): ?string
{
    $doc = new DOMDocument;
    @$doc->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $node = (new DOMXPath($doc))->query('//*[@id="setting-'.$id.'"]//*[contains(@class, "el-set-title")]')->item(0);

    return $node !== null ? trim($node->textContent) : null;
}

/*
 * Each page with what it says in Urdu and in English, the same places: a Settings row by its id ("#id"), or words as
 * they stand in the page.
 */
dataset('settings pages', [
    'profile' => ['/app/settings/profile', ['#profile.workspace' => ['ورک اسپیس', 'Workspace']]],
    'security' => ['/app/settings/security', [
        '#security.two_step' => ['دو مرحلوں والا سائن اِن', 'Two-step sign-in'],
        '#security.signout_all' => ['ہر جگہ سے سائن آؤٹ کریں', 'Sign out everywhere'],
        '<h2 class="el-set-head">پاس کیز</h2>', '<h2 class="el-set-head">Passkeys</h2>',
    ]],
    'plan' => ['/app/settings/plan', [
        '<h2 class="el-plan-name">Personal پلان</h2>', '<h2 class="el-plan-name">Personal plan</h2>',
        '<h2 class="el-set-head">آپ کے پلان میں شامل</h2>', '<h2 class="el-set-head">Included in your plan</h2>',
        '<td>ماہانہ ٹرانسکرپشن منٹ</td>', '<td>Transcription minutes a month</td>',
        '<p class="el-set-foot">ادائیگی بینک ٹرانسفر سے ہوتی ہے۔ رقم ہمارے اکاؤنٹ میں نظر آنے کے بعد ہم آپ کا پلان آن کر دیتے ہیں۔</p>',
        '<p class="el-set-foot">Payments are by bank transfer. We switch your plan on after we see the money in our account.</p>',
        '#plan.promo' => ['پرومو کوڈ', 'Promo code'],
    ]],
    'devices' => ['/app/settings/devices', [
        '#devices.pair' => ['ڈیوائس سائن اِن کریں', 'Sign in a device'],
        '<h2 class="el-set-head">منسلک ایپس</h2>', '<h2 class="el-set-head">Connected apps</h2>',
    ]],
    'privacy' => ['/app/settings/privacy', [
        '#privacy.consent' => ['ریکارڈنگ کی رضامندی', 'Recording consent'],
        '#privacy.export' => ['میرا ڈیٹا ایکسپورٹ کریں', 'Export my data'],
        'سپورٹ آپ کی ریکارڈنگز کبھی نہیں دیکھتی، جب تک آپ کچھ دیر کے لیے اجازت نہ دیں۔', 'Support never sees your recordings unless you let them look for a while.',
    ]],
    'notifications' => ['/app/settings/notifications', [
        '<h2 class="el-set-head">مجھے ای میل کریں</h2>', '<h2 class="el-set-head">Email me about</h2>',
        '#notifications.email.account' => ['اکاؤنٹ اور سکیورٹی', 'Account & security'],
        '#notifications.email.enhancement' => ['بہتری', 'Enhancement'],
    ]],
    'appearance' => ['/app/settings/appearance', [
        'تھیم اسی براؤزر میں رہتی ہے۔ باقی ترتیبات آپ کے اکاؤنٹ کے ساتھ چلتی ہیں۔', 'The theme is kept in this browser. The others follow your account.',
        "wire:click=\"mountAction('resetPage')\">ڈیفالٹ پر واپس لائیں</button>", "wire:click=\"mountAction('resetPage')\">Reset to defaults</button>",
    ]],
    'language' => ['/app/settings/language', [
        'وہ زبان جس میں آپ کی ریکارڈنگز ٹرانسکرائب ہوتی ہیں۔', 'The language your recordings are transcribed in.',
        '<optgroup label="مزید زبانیں">', '<optgroup label="More languages">',
    ]],
    'storage' => ['/app/settings/storage', [
        '<span class="el-set-title">آپ کی اسٹوریج</span>', '<span class="el-set-title">Your storage</span>',
        'حذف شدہ ریکارڈنگز 48 گھنٹے تک حال ہی میں حذف شدہ میں رہتی ہیں، پھر مٹا دی جاتی ہیں۔', 'Deleted recordings stay in Recently deleted for 48 hours, then they are erased.',
    ]],
    'help' => ['/app/settings/help', [
        '<h2 class="el-set-head">سوالات</h2>', '<h2 class="el-set-head">Questions</h2>',
        '#help.article.dark_mode' => ['ڈارک موڈ کیسے آن کروں؟', 'How do I turn on dark mode?'],
        '>تھیم کھولیں</a>', '>Open Theme</a>',
    ]],
    'shortcuts' => ['/app/settings/shortcuts', [
        '<h2 class="el-set-head">ہر جگہ</h2>', '<h2 class="el-set-head">Everywhere</h2>',
        'صفحے کی ہر ریکارڈنگ منتخب کریں', 'Select every recording on the page',
    ]],
    'search' => ['/app/settings?q=theme', ['<h1 class="el-set-h1">“theme” کے نتائج</h1>', '<h1 class="el-set-h1">Results for “theme”</h1>']],
    'sign-in activity' => ['/app/settings/security/activity', [
        'پچھلے 365 دن کے سائن اِن، حذف شدہ ریکارڈنگز، ایکسپورٹس، شیئر لنکس اور اسناد کی تبدیلیاں۔',
        'Sign-ins, deleted recordings, exports, share links and credential changes from the last 365 days.',
        // The Event filter's choices (Filament draws each option on its own lines).
        'پاس کی سے سائن اِن', 'Signed in with a passkey',
    ]],
    'get the apps' => ['/app/get-the-apps', [
        'آپ کا اکاؤنٹ تیار ہے۔ کالز اپنے فون پر اور میٹنگز اپنے کمپیوٹر پر ریکارڈ کریں',
        'Your account is ready. Record calls on your phone and meetings on your computer',
        '<li>ایپ کھولیں اور اپنی ای میل لکھیں۔</li>', '<li>Open the app and enter your email.</li>',
    ]],
]);

/**
 * The checks of one page in one language: "#id" => [Urdu, English] for a row's title, or the Urdu words followed by
 * the English ones.
 *
 * @param  array<int|string, string|array{0: string, 1: string}>  $checks
 */
function settingsUrduExpect(string $html, array $checks, string $language): void
{
    $words = [];
    foreach ($checks as $key => $check) {
        if (is_string($key)) {
            expect(settingsUrduRow($html, substr($key, 1)))->toBe($language === 'ur' ? $check[0] : $check[1], $key);
        } else {
            $words[] = $check;
        }
    }
    foreach (array_chunk($words, 2) as [$urdu, $english]) {
        $language === 'ur' ? expect($html)->toContain($urdu)->not->toContain($english) : expect($html)->toContain($english)->not->toContain($urdu);
    }
}

it('words each Settings page in Urdu in the new frame', function (string $url, array $checks) {
    settingsUrduAs($this, 'ur');
    settingsUrduExpect(settingsUrduHtml($this, $url), $checks, 'ur');
})->with('settings pages');

it('keeps each Settings page\'s English exactly as it was', function (string $url, array $checks) {
    settingsUrduAs($this, 'en');
    settingsUrduExpect(settingsUrduHtml($this, $url), $checks, 'en');
})->with('settings pages');

it('words the Transcription key page in Urdu on Free, and keeps its English', function () {
    Workspace::query()->whereKey($this->owner->current_workspace_id)->update(['plan_id' => Plan::query()->where('key', 'free')->value('id'), 'plan_expires_at' => null]);
    settingsUrduAs($this, 'ur');
    expect(settingsUrduHtml($this, '/app/settings/transcription-key'))
        ->toContain('مفت پلان پر ٹرانسکرپٹس آپ کی اپنی مفت Groq کلید سے بنتے ہیں۔ اس میں تقریباً 2 منٹ لگتے ہیں۔')
        ->toContain('<label for="el-groq-key">Groq کلید</label>');
    Livewire::test(EditProfile::class, ['subpage' => 'transcription-key'])->set('groqKey', 'short')->call('saveGroqKey')
        ->assertSet('groqKeyError', 'پوری کلید پیسٹ کریں۔ یہ gsk_ سے شروع ہوتی ہے۔');

    settingsUrduAs($this, 'en');
    expect(settingsUrduHtml($this, '/app/settings/transcription-key'))
        ->toContain('Transcripts on Free use your own free Groq key. It takes about 2 minutes.')
        ->toContain('<label for="el-groq-key">Groq key</label>');
});

it('words the security actions\' windows and notices in Urdu, and keeps their English', function () {
    settingsUrduAs($this, 'ur');
    Livewire::test(EditProfile::class, ['subpage' => 'security'])
        ->assertActionExists('changePassword', fn (Action $a): bool => $a->getModalHeading() === 'پاس ورڈ بدلیں')
        ->assertActionExists('signOutEverywhere', fn (Action $a): bool => $a->getModalHeading() === 'ہر جگہ سے سائن آؤٹ کریں؟'
            && $a->getModalDescription() === 'آپ ہر فون، کمپیوٹر اور براؤزر سے سائن آؤٹ ہو جائیں گے، اس براؤزر سے بھی۔')
        ->call('signOutBrowser', 'a-browser-that-is-gone')->assertNotified('وہ براؤزر پہلے ہی سائن آؤٹ تھا')
        ->callAction('changePassword', data: ['currentPassword' => 'password', 'password' => 'Lahore-Monsoon-2026!', 'passwordConfirmation' => 'Lahore-Monsoon-2026!'])
        ->assertHasNoActionErrors()->assertNotified('پاس ورڈ محفوظ ہو گیا');

    settingsUrduAs($this, 'en');
    Livewire::test(EditProfile::class, ['subpage' => 'security'])
        ->assertActionExists('changePassword', fn (Action $a): bool => $a->getModalHeading() === 'Change password')
        ->assertActionExists('signOutEverywhere', fn (Action $a): bool => $a->getModalHeading() === 'Sign out everywhere?'
            && $a->getModalDescription() === "You'll be signed out on every phone, computer and browser, including this one.")
        ->call('signOutBrowser', 'a-browser-that-is-gone')->assertNotified('That browser was already signed out')
        ->callAction('changePassword', data: ['currentPassword' => 'Lahore-Monsoon-2026!', 'password' => 'Karachi-Breeze-2026!', 'passwordConfirmation' => 'Karachi-Breeze-2026!'])
        ->assertHasNoActionErrors()->assertNotified('Password saved');
});

it('words the promo code window and its answer in Urdu, and keeps their English', function () {
    $promo = function (string $language): array {
        settingsUrduAs($this, $language);
        $page = Livewire::test(EditProfile::class, ['subpage' => 'plan']);
        $window = $page->instance()->getAction('promoCode');
        $page->callAction('promoCode', data: ['code' => 'NOSUCHCODE']);

        return [$window?->getModalHeading(), $window?->getModalSubmitActionLabel(), $page->errors()->first('mountedActions.0.data.code')];
    };

    expect($promo('ur'))->toBe(['پرومو کوڈ', 'لاگو کریں', 'ہم یہ کوڈ نہیں پہچانتے۔ ہجے چیک کریں۔'])
        ->and($promo('en'))->toBe(['Promo code', 'Apply', "We don't know this code. Check the spelling."]);
});

/**
 * A Settings search result as shown (`data-result` = the setting's id): its title, path and value.
 *
 * @return array{title: ?string, path: ?string, value: ?string}|null
 */
function settingsUrduHit(string $html, string $id): ?array
{
    $doc = new DOMDocument;
    @$doc->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $xpath = new DOMXPath($doc);
    $row = $xpath->query('//a[@data-result="'.$id.'"]')->item(0);
    if ($row === null) {
        return null;
    }
    $text = function (string $class) use ($xpath, $row): ?string {
        $node = $xpath->query('.//*[contains(@class, "'.$class.'")]', $row)->item(0);

        return $node !== null ? trim($node->textContent) : null;
    };

    return ['title' => $text('el-set-title'), 'path' => $text('el-set-sub'), 'value' => $text('el-set-value')];
}

it('finds a setting in Urdu by its Urdu and its English words, and shows its title and path in Urdu', function () {
    settingsUrduAs($this, 'ur');
    foreach (['پاس ورڈ', 'password', 'پاس'] as $query) {
        expect(settingsUrduHit(settingsUrduHtml($this, '/app/settings?q='.rawurlencode($query)), 'security.password'))
            ->toBe(['title' => 'پاس ورڈ', 'path' => 'ترتیبات › سائن اِن اور سکیورٹی › پاس ورڈ', 'value' => null], $query);
    }
    expect(settingsUrduHtml($this, '/app/settings?q='.rawurlencode('پاس ورڈ')))->toContain('<mark>پاس</mark> <mark>ورڈ</mark>')
        ->and(settingsUrduHit(settingsUrduHtml($this, '/app/settings?q=security'), 'security'))
        ->toBe(['title' => 'سائن اِن اور سکیورٹی', 'path' => 'ترتیبات › سائن اِن اور سکیورٹی', 'value' => 'دو مرحلوں والا سائن اِن آف']);
});

it('keeps Settings search in English exactly as it was', function () {
    settingsUrduAs($this, 'en');
    $html = settingsUrduHtml($this, '/app/settings?q=password');
    expect(settingsUrduHit($html, 'security.password'))->toBe(['title' => 'Password', 'path' => 'Settings › Sign-in & security › Password', 'value' => null])
        ->and($html)->toContain('<mark>Password</mark>')
        ->and(settingsUrduHit(settingsUrduHtml($this, '/app/settings?q=security'), 'security'))
        ->toBe(['title' => 'Sign-in & security', 'path' => 'Settings › Sign-in & security', 'value' => 'Two-step off'])
        // The registry's own Urdu words still find it in English, shown in English.
        ->and(settingsUrduHit(settingsUrduHtml($this, '/app/settings?q='.rawurlencode('پاس ورڈ')), 'security.password')['title'] ?? null)->toBe('Password');
});

it('searches a row\'s shown words only in that language, never typo-forgiven', function () {
    $ids = fn (string $query): array => array_map(fn (array $hit): string => (string) $hit['entry']['id'], (new SettingsIndex(SettingsRegistry::entries()))->search($query)['settings']);

    app()->setLocale('ur');
    expect($ids('کلید ہٹائیں'))->toContain('key.remove')
        ->and($ids('موجودہ پلان'))->toContain('plan.current')
        ->and($ids('کلید ہٹایں'))->not->toContain('key.remove')
        ->and(SettingsIndex::marked('سائن اِن', 'سائن ان'))->toBe('<mark>سائن</mark> <mark>اِن</mark>');

    app()->setLocale('en');
    expect($ids('کلید ہٹائیں'))->toBe([])
        ->and($ids('remove key'))->toContain('key.remove');
});

it('words the values beside the Settings rows in Urdu, and keeps their English', function () {
    settingsUrduAs($this, 'ur');
    expect(settingsUrduHtml($this, '/app/settings'))->toContain('دو مرحلوں والا سائن اِن آف')->not->toContain('Two-step off')
        ->toMatch('/\d+ میں سے \d+ ای میلز آن/u')->not->toMatch('/emails on/');

    settingsUrduAs($this, 'en');
    expect(settingsUrduHtml($this, '/app/settings'))->toContain('Two-step off')->toMatch('/\d+ of \d+ emails on/')->not->toContain('دو مرحلوں');
});

it('shows a settings hit in the command palette with its Urdu title and path, and its English unchanged', function () {
    $hits = fn (string $query): string => collect(app(CommandPalette::class)->getResults($query)?->getCategories()['Settings'] ?? [])
        ->map(fn ($result): string => html_entity_decode(strip_tags((string) $result->title), ENT_QUOTES | ENT_HTML5, 'UTF-8'))->implode('|');

    settingsUrduAs($this, 'ur');
    SettingsContext::forget();
    expect($hits('پاس ورڈ'))->toContain('پاس ورڈ · ترتیبات › سائن اِن اور سکیورٹی › پاس ورڈ')
        ->and($hits('password'))->toContain('پاس ورڈ · ترتیبات › سائن اِن اور سکیورٹی › پاس ورڈ')->not->toContain('Settings ›');

    settingsUrduAs($this, 'en');
    SettingsContext::forget();
    expect($hits('password'))->toContain('Password · Settings › Sign-in & security › Password')->not->toContain('ترتیبات');
});

it('reads the short context words from lang/*/settings_context.php, and "Settings" still as words in English', function () {
    app()->setLocale('en');
    expect(__('Settings'))->toBe('Settings')
        ->and(array_map(fn (string $k): string => __('settings_context.'.$k), ['col_by', 'recordings_kept', 'password_not_set', 'plan_yours', 'reset_page']))
        ->toBe(['By', 'Recordings kept', 'Not set', '(yours)', 'Reset']);

    app()->setLocale('ur');
    expect(__('Settings'))->toBe('ترتیبات')
        ->and(array_map(fn (string $k): string => __('settings_context.'.$k), ['col_by', 'recordings_kept', 'password_not_set', 'plan_yours', 'reset_page']))
        ->toBe(['کس نے', 'ریکارڈنگز کتنی دیر رہیں', 'نہیں رکھا', '(آپ کا)', 'واپس لائیں']);
});

it('shows the context words in place in Urdu, and the same English as before', function () {
    settingsUrduAs($this, 'ur');
    expect(settingsUrduRow(settingsUrduHtml($this, '/app/settings/storage'), 'storage.retention'))->toBe('ریکارڈنگز کتنی دیر رہیں');
    Livewire::test(EditProfile::class, ['subpage' => 'appearance'])
        ->assertActionExists('resetPage', fn (Action $a): bool => $a->getModalSubmitActionLabel() === 'واپس لائیں');

    settingsUrduAs($this, 'en');
    expect(settingsUrduRow(settingsUrduHtml($this, '/app/settings/storage'), 'storage.retention'))->toBe('Recordings kept');
    Livewire::test(EditProfile::class, ['subpage' => 'appearance'])
        ->assertActionExists('resetPage', fn (Action $a): bool => $a->getModalSubmitActionLabel() === 'Reset');
});
