<?php

declare(strict_types=1);

use App\Filament\Pages\AccountBeingDeleted;
use App\Filament\Pages\Help;
use App\Filament\Pages\Upgrade\ChoosePlan;
use App\Filament\Pages\Upgrade\RequestStatus;
use App\Filament\Pages\Upgrade\SendReceipt;
use App\Filament\Support\SettingsContext;
use App\Filament\Support\UpgradeFlow;
use App\Models\PromoCode;
use App\Models\UpgradeRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Entitlements\PaymentProofs;
use App\Services\Entitlements\UpgradeRequests;
use App\Services\Help\SupportMessages;
use App\Services\SettingsRepository;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * Urdu package 5: the upgrade flow (Upgrade, Pay for {Pro}, Send receipt, the request's page and its Cancel request
 * window), the page of an account being deleted, and Help, in the console's language. Urdu reads every place in Urdu,
 * with an amount, a plan name and a reference or receipt number kept whole in a sentence (U+2068 ... U+2069, D4); English
 * reads exactly as before (the saved copies in tests/Feature/V2/golden/).
 */

require_once __DIR__.'/Support/parity_world.php';
require_once __DIR__.'/Support/golden.php';
require_once __DIR__.'/../Workspaces/Support/receipt_helpers.php';

beforeEach(function () {
    [$this->owner, $this->pages] = webParityWorld();
    Mail::fake();
});

afterEach(function () {
    app()->setLocale('en');
    Fx::cleanupDisks();
});

/**
 * The person's request for Pro with our bank details set: waiting for the payment, or (in review) with one receipt
 * sent, its transaction ID and the name it was paid from; [$unsent] adds a receipt not sent yet.
 */
function upgradeUrduRequest(User $owner, bool $inReview = false, bool $unsent = false): UpgradeRequest
{
    app(SettingsRepository::class)->setMany(['payment.bank' => 'Example Bank', 'payment.branch' => 'Main Branch (0001)', 'payment.account_title' => 'EXAMPLE PRIVATE LIMITED',
        'payment.account_number' => '0123456789', 'payment.iban' => 'PK36SCBL0000001123456702']);
    SettingsContext::forget();
    $request = app(UpgradeRequests::class)->create($owner, (int) $owner->current_workspace_id, 'pro', null);
    if ($inReview || $unsent) {
        $file = (string) tempnam(sys_get_temp_dir(), 'receipt');
        file_put_contents($file, receiptPicture());
        app(PaymentProofs::class)->add($request, $owner, $file, 'receipt.png');
        @unlink($file);
    }
    if ($inReview) {
        app(PaymentProofs::class)->markPaid($request, 'FT26313ABC7781', 'Bilal Qureshi');
    }
    SettingsContext::forget();

    return $request->fresh();
}

/** The page's own part (its title and <main>), normalised: the frame around it belongs to other pages' checks. */
function upgradeUrduMain(object $test, string $url, ?UpgradeRequest $request = null): string
{
    SettingsContext::forget();
    $html = normaliseClassic((string) $test->get($url)->assertOk()->getContent(), (string) session()->token());
    if ($request !== null && $request->reference_code !== null) {
        $html = str_replace($request->reference_code, 'REFERENCE', $html);
    }
    preg_match('~<title>.*?</title>~s', $html, $title);
    preg_match('~<main.*?</main>~s', $html, $main);

    return ($title[0] ?? '')."\n".($main[0] ?? '')."\n";
}

/**
 * Compares [$html] with the copy [$name] saved before the words were wrapped (UPDATE_GOLDEN=1 saves it again), byte for
 * byte: a line with an apostrophe is written raw (ENT_NOQUOTES), as the old template wrote it.
 */
function upgradeUrduGolden(string $name, string $html): void
{
    $file = __DIR__."/golden/{$name}.html";
    if (getenv('UPDATE_GOLDEN') === '1') {
        file_put_contents($file, $html);
    }
    expect(file_exists($file))->toBeTrue("no saved copy of {$name}: run with UPDATE_GOLDEN=1 once")
        ->and($html)->toBe((string) file_get_contents($file), "the English {$name} changed");
}

/** Signs the person in with the console in this language, in the new design. */
function upgradeUrduAs(object $test, string $language): void
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

it('renders the English Upgrade page byte for byte as before', function () {
    expect(config('app.debug'))->toBeFalse('the saved pages need debug off: run the suite with APP_DEBUG unset');
    upgradeUrduAs($this, 'en');
    upgradeUrduGolden('upgrade-choose-en', upgradeUrduMain($this, '/app/settings/plan/upgrade'));
});

it('renders the English Pay, Send receipt and request pages byte for byte as before', function () {
    upgradeUrduAs($this, 'en');
    $waiting = upgradeUrduRequest($this->owner, unsent: true);
    upgradeUrduGolden('upgrade-pay-en', upgradeUrduMain($this, UpgradeFlow::payUrl($waiting), $waiting));
    upgradeUrduGolden('upgrade-receipt-en', upgradeUrduMain($this, UpgradeFlow::receiptUrl($waiting), $waiting));
    upgradeUrduGolden('upgrade-status-waiting-en', upgradeUrduMain($this, UpgradeFlow::statusUrl($waiting), $waiting));
    app(PaymentProofs::class)->markPaid($waiting, 'FT26313ABC7781', 'Bilal Qureshi');
    upgradeUrduGolden('upgrade-status-review-en', upgradeUrduMain($this, UpgradeFlow::statusUrl($waiting), $waiting));
});

it('renders the English Help page and the account-being-deleted page byte for byte as before', function () {
    upgradeUrduAs($this, 'en');
    upgradeUrduGolden('help-en', upgradeUrduMain($this, '/app/help'));
    upgradeUrduGolden('help-empty-en', upgradeUrduMain($this, '/app/help?q=zzqqxx'));
    upgradeUrduGolden('account-deletion-none-en', upgradeUrduMain($this, '/app/account-deletion'));
    $this->owner->forceFill(['deletes_at' => now()->addDays(7)])->save();
    upgradeUrduAs($this, 'en');
    upgradeUrduGolden('account-deletion-en', upgradeUrduMain($this, '/app/account-deletion'));
});

/** A value kept whole in a right-to-left sentence: between FIRST STRONG ISOLATE and POP DIRECTIONAL ISOLATE. */
function upgradeUrduIsolated(string $value): string
{
    return "\u{2068}{$value}\u{2069}";
}

/** A page as drawn, its entities read (the isolate marks kept). */
function upgradeUrduPage(object $test, string $url): string
{
    SettingsContext::forget();

    return html_entity_decode((string) $test->get($url)->assertOk()->getContent(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * [$urdu] in the Urdu page and none of [$english]; then the same page in English has [$english] and none of [$urdu].
 *
 * @param  list<string>  $urdu
 * @param  list<string>  $english
 */
function upgradeUrduBoth(string $ur, string $en, array $urdu, array $english): void
{
    foreach ($urdu as $words) {
        expect($ur)->toContain($words);
        expect($en)->not->toContain($words);
    }
    foreach ($english as $words) {
        expect($en)->toContain($words);
        expect($ur)->not->toContain($words);
    }
}

it('words the Upgrade page in Urdu, with the plan names and prices kept whole, and English as before', function () {
    upgradeUrduAs($this, 'ur');
    $ur = upgradeUrduPage($this, '/app/settings/plan/upgrade');
    upgradeUrduAs($this, 'en');
    $en = upgradeUrduPage($this, '/app/settings/plan/upgrade');

    upgradeUrduBoth($ur, $en, [
        '<h1 class="el-set-h1">اپ گریڈ</h1>', 'پلان اور استعمال</a>', 'aria-label="پلان"',
        upgradeUrduIsolated('Personal').' کا اپ گریڈ', 'آپ '.upgradeUrduIsolated('Personal').' پر ہیں۔',
        // A price in a sentence, and a plan name in a sentence.
        '<span class="el-plan-card-price" style="margin-top:2px">'.upgradeUrduIsolated('Rs 2,999').' ماہانہ</span>',
        upgradeUrduIsolated('Personal').' میں سب کچھ، اور یہ بھی:',
        '<span>ہر مہینے '.upgradeUrduIsolated('600').' ٹرانسکرپشن منٹ</span>', '<span>زیادہ سے زیادہ '.upgradeUrduIsolated('10').' افراد کی ٹیم</span>',
        '<span>مشکل آڈیو کے لیے زیادہ درست ٹرانسکرپشن</span>', '<span>گیمز اور ویڈیوز کے لیے Live Translate</span>',
        '<span class="el-set-title">پرومو کوڈ</span>', '<span class="el-set-value">شامل کریں</span>',
        '<label for="el-note">ہماری ٹیم کے لیے نوٹ (اختیاری)</label>', 'placeholder="مثلاً، میری کمپنی کے نام پر انوائس"',
        '<span class="el-copy-label">قابلِ ادائیگی</span>', upgradeUrduIsolated('Pro').' کی درخواست کریں',
        'ہماری بینک تفصیلات اگلی اسکرین پر نظر آئیں گی۔',
    ], [
        '<h1 class="el-set-h1">Upgrade</h1>', 'Plan & usage</a>', 'aria-label="Plan"', 'Upgrading Personal', "You're on Personal.",
        '<span class="el-plan-card-price" style="margin-top:2px">Rs 2,999 a month</span>', 'Everything in Personal, plus:',
        '<span>600 transcription minutes a month</span>', '<span>A team of up to 10 people</span>',
        '<span>More accurate transcription for difficult audio</span>', '<span class="el-set-title">Promo code</span>',
        '<label for="el-note">Note for our team (optional)</label>', "For example, an invoice in my company's name",
        '<span class="el-copy-label">To pay</span>', 'Request Pro', "You'll see our bank details on the next screen.",
    ]);
    expect($ur)->toMatch('~<title>\s*اپ گریڈ~u')->and($en)->toMatch('~<title>\s*Upgrade~u');
});

it('words the Upgrade page\'s promo code and its answers in Urdu, and keeps their English', function () {
    PromoCode::query()->create(['code' => 'SAVE50', 'plan' => 'pro', 'percent' => 50, 'months' => 3, 'one_per_person' => true, 'active' => true]);
    $answers = function (string $language): array {
        upgradeUrduAs($this, $language);
        $page = Livewire::test(ChoosePlan::class)->set('promoOpen', true)->set('promo', 'AB')->call('applyPromo');
        $wrong = $page->get('promoError');
        $page->set('promo', 'SAVE50')->call('applyPromo');
        $html = html_entity_decode($page->html(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        preg_match('~role="status">(.*?)</p>~su', $html, $line);
        preg_match('~<span class="el-copy-label">([^<]*months?[^<]*|[^<]*مہین[^<]*)</span>~u', $html, $first);
        // A code kept from another plan (the person chose another card since): it says which plan it is for.
        $page->set('promoApplied', ['code' => 'ULTRA20', 'plan' => 'business', 'plan_name' => 'Business', 'percent' => 20, 'free' => false, 'months' => 1]);
        preg_match('~role="status">(.*?)</p>~su', html_entity_decode($page->html(), ENT_QUOTES | ENT_HTML5, 'UTF-8'), $other);

        return [$wrong, $line[1] ?? null, $first[1] ?? null, $other[1] ?? null];
    };

    expect($answers('ur'))->toBe([
        'ہم یہ کوڈ نہیں پہچانتے۔ ہجے چیک کریں۔',
        upgradeUrduIsolated('SAVE50').': '.upgradeUrduIsolated('Pro').' پر '.upgradeUrduIsolated('50').'% رعایت، '.upgradeUrduIsolated('3').' مہینوں کے لیے',
        'پہلے '.upgradeUrduIsolated('3').' مہینوں کے لیے',
        'یہ کوڈ '.upgradeUrduIsolated('Business').' کے لیے ہے۔',
    ])->and($answers('en'))->toBe([
        "We don't know this code. Check the spelling.",
        'SAVE50: 50% off Pro for 3 months',
        'for the first 3 months',
        'This code is for Business.',
    ]);
});

it('words Pay for Pro in Urdu, with the amount, the account and the reference kept whole, and English as before', function () {
    upgradeUrduAs($this, 'ur');
    $request = upgradeUrduRequest($this->owner);
    $reference = (string) $request->reference_code;
    $ur = upgradeUrduPage($this, UpgradeFlow::payUrl($request));
    upgradeUrduAs($this, 'en');
    $en = upgradeUrduPage($this, UpgradeFlow::payUrl($request));

    upgradeUrduBoth($ur, $en, [
        upgradeUrduIsolated('Pro').' کی درخواست</a>', '<h1 class="el-set-h1">'.upgradeUrduIsolated('Pro').' کی ادائیگی کریں</h1>',
        '<span class="el-copy-label">رقم</span>', 'aria-label="رقم کاپی کریں"', 'بالکل یہی رقم بھیجیں۔',
        '<h2 class="el-set-head">ادائیگی اس اکاؤنٹ میں</h2>', '<span class="el-copy-label">اکاؤنٹ ٹائٹل</span>', 'aria-label="اکاؤنٹ ٹائٹل کاپی کریں"',
        '<span class="el-copy-label">بینک</span>', '<span class="el-copy-label">برانچ</span>', '<span class="el-copy-label">اکاؤنٹ نمبر</span>',
        'aria-label="'.upgradeUrduIsolated('اکاؤنٹ نمبر').' کاپی کریں"', 'aria-label="'.upgradeUrduIsolated('IBAN').' کاپی کریں"',
        '<h2 class="el-set-head">حوالہ</h2>', 'aria-label="حوالہ کاپی کریں"',
        // The reference in a sentence.
        'ٹرانسفر کے نوٹ میں '.upgradeUrduIsolated($reference).' لکھیں۔',
        'کسی بھی بینک ایپ، JazzCash، Easypaisa، NayaPay یا SadaPay سے ادائیگی کریں۔',
        'اس پر '.upgradeUrduIsolated('EXAMPLE PRIVATE LIMITED').' لکھا ہونا چاہیے۔',
        'ادائیگی کر دی؟ رسید کا اسکرین شاٹ شامل کریں تاکہ ہم اسے چیک کر سکیں۔', '>ادائیگی کی رسید شامل کریں</a>', '>بعد میں ادائیگی کریں</a>',
        ".title('کاپی ہو گیا')",
    ], [
        'Pro request</a>', '<h1 class="el-set-h1">Pay for Pro</h1>', '<span class="el-copy-label">Amount</span>', 'aria-label="Copy the amount"',
        '<h2 class="el-set-head">Pay to</h2>', 'aria-label="Copy account title"', 'aria-label="Copy Account number"', 'aria-label="Copy IBAN"',
        "Put {$reference} in the transfer's note.", 'Pay from any bank app', 'It should say EXAMPLE PRIVATE LIMITED.',
        '>Add payment receipt</a>', ">I'll pay later</a>", ".title('Copied')",
    ]);
    // The values themselves are as they are, Latin digits and all, beside their Urdu labels.
    expect($ur)->toContain('>Rs 2,999</span>')->toContain('>PK36 SCBL 0000 0011 2345 6702</span>')->toContain('>0123456789</span>')
        ->toMatch('~<title>\s*'.upgradeUrduIsolated('Pro').' کی ادائیگی کریں~u')
        ->and($en)->toMatch('~<title>\s*Pay for Pro~u');
});

it('words Send receipt in Urdu, with its tiles, its answers and its notice, and English as before', function () {
    upgradeUrduAs($this, 'ur');
    $request = upgradeUrduRequest($this->owner, unsent: true);
    $ur = upgradeUrduPage($this, UpgradeFlow::receiptUrl($request));
    upgradeUrduAs($this, 'en');
    $en = upgradeUrduPage($this, UpgradeFlow::receiptUrl($request));

    upgradeUrduBoth($ur, $en, [
        '<h1 class="el-set-h1">رسید بھیجیں</h1>', 'آپ کی رسید کا اسکرین شاٹ یا PDF۔ اس میں رقم، تاریخ اور وقت، اور ٹرانزیکشن ID نظر آنی چاہیے۔',
        // A receipt's number in a sentence.
        'aria-label="رسید '.upgradeUrduIsolated('1').'، تصویر، پورے سائز میں کھولیں"', 'aria-label="رسید '.upgradeUrduIsolated('1').' ہٹائیں"',
        '<span>اسکرین شاٹ یا PDF یہاں چھوڑیں، یا <strong>فائل چنیں</strong></span>',
        'JPEG، PNG، WebP یا PDF، زیادہ سے زیادہ 5 MB۔ اس بار '.upgradeUrduIsolated('2').' اور فائلیں۔',
        '<label for="el-tid">ٹرانزیکشن ID (اختیاری)</label>', 'آپ کی رسید پر Transaction ID، TID یا Reference no. کے نام سے۔',
        '<label for="el-payer">ادا کرنے والا اکاؤنٹ (اختیاری)</label>', 'آپ کی رسید صرف ہماری ادائیگیوں کی ٹیم دیکھتی ہے۔',
        '>ادائیگی کی تفصیل</a>',
    ], [
        '<h1 class="el-set-h1">Send receipt</h1>', 'aria-label="Receipt 1, image, open full size"', 'aria-label="Remove receipt 1"',
        'Drop a screenshot or PDF here, or <strong>choose a file</strong>', 'JPEG, PNG, WebP or PDF, up to 5 MB. 2 more files this time.',
        '<label for="el-tid">Transaction ID (optional)</label>', '<label for="el-payer">Paid from (optional)</label>',
        'Only our payments team sees your receipt.', '>Payment details</a>',
    ]);

    $answers = function (string $language) use ($request): array {
        upgradeUrduAs($this, $language);
        $page = Livewire::test(SendReceipt::class, ['request' => $request->id])
            ->set('uploads', [UploadedFile::fake()->createWithContent('anim.gif', receiptPicture('gif'))]);
        $failed = $page->get('failed.0.message');
        $page->set('transactionId', '!!')->call('send');

        return [$failed, $page->get('error'), $page->set('transactionId', '')->set('payerName', str_repeat('a', 121))->call('send')->get('error')];
    };
    expect($answers('ur'))->toBe([
        'اسکرین شاٹ یا تصویر (JPEG، PNG یا WebP) یا PDF بھیجیں۔',
        // The allowed characters are one isolated run, so they keep their order in a right-to-left line.
        'ٹرانزیکشن ID میں 4 سے 64 حروف، نمبر، اسپیس یا '.upgradeUrduIsolated('. _ / # -').' ہوتے ہیں۔',
        'نام 120 حروف یا اس سے کم میں لکھیں۔',
    ])->and($answers('en'))->toBe([
        'Send a screenshot or photo (JPEG, PNG or WebP) or a PDF.',
        'The transaction ID is 4 to 64 letters, numbers, spaces or . _ / # -.',
        'Write the name in 120 characters or fewer.',
    ]);

    // Send receipt: the request's page announces it.
    upgradeUrduAs($this, 'ur');
    Livewire::test(SendReceipt::class, ['request' => $request->id])->set('transactionId', 'FT26313ABC7781')->call('send')->assertRedirect(UpgradeFlow::statusUrl($request));
    expect(upgradeUrduPage($this, UpgradeFlow::statusUrl($request)))->toContain('رسید بھیج دی گئی۔ ہم آپ کی ادائیگی چیک کریں گے۔')->not->toContain('Receipt sent. We');
});

it('words the request\'s page in Urdu while it waits, with the amount and reference kept whole in its card, and English as before', function () {
    upgradeUrduAs($this, 'ur');
    $request = upgradeUrduRequest($this->owner);
    $reference = (string) $request->reference_code;
    $ur = upgradeUrduPage($this, UpgradeFlow::statusUrl($request));
    upgradeUrduAs($this, 'en');
    $en = upgradeUrduPage($this, UpgradeFlow::statusUrl($request));

    upgradeUrduBoth($ur, $en, [
        '<h1 class="el-set-h1">'.upgradeUrduIsolated('Pro').' کی درخواست</h1>',
        '<span class="el-plan-card-name">'.upgradeUrduIsolated('Pro').' کی درخواست دی گئی</span>', '<span class="el-tag">ادائیگی کا انتظار</span>',
        // A price, then a reference, in one sentence.
        upgradeUrduIsolated('Rs 2,999').' ہمارے بینک اکاؤنٹ میں بھیجیں، پھر رسید کا اسکرین شاٹ شامل کریں۔ حوالہ '.upgradeUrduIsolated($reference).'۔',
        'class="el-btn el-btn-primary">ادائیگی کی تفصیل</a>',
        '<h2 class="el-set-head">پیش رفت</h2>', '<span class="el-set-title">درخواست دی گئی</span>', '<span class="el-set-title">رسید بھیجی گئی</span>',
        '<span class="el-set-title">ہماری ٹیم نے چیک کی</span>', '<span class="el-set-title">'.upgradeUrduIsolated('Pro').' آن</span>',
        'aria-label="'.upgradeUrduIsolated('درخواست دی گئی').'، مکمل، '.upgradeUrduIsolated(upgradeUrduIsolated('17 Nov').'، '.upgradeUrduIsolated('11:42 am')).'"',
        'aria-label="'.upgradeUrduIsolated('رسید بھیجی گئی').'، جاری"', 'aria-label="'.upgradeUrduIsolated(upgradeUrduIsolated('Pro').' آن').'، ابھی نہیں"',
        '<h2 class="el-set-head">تفصیلات</h2>', '<span class="el-set-title">پلان</span>', upgradeUrduIsolated('Pro').'، 1 مہینہ',
        '<span class="el-set-title">رقم</span>', '<span class="el-set-title">حوالہ</span>', 'aria-label="حوالہ کاپی کریں"',
        '<span class="el-set-title">رسید شامل کریں</span>', '<span class="el-set-title">درخواست منسوخ کریں</span>',
    ], [
        '<h1 class="el-set-h1">Pro request</h1>', '<span class="el-plan-card-name">Pro requested</span>', '<span class="el-tag">Waiting for payment</span>',
        "Send Rs 2,999 to our bank account, then add a screenshot of the receipt. Reference {$reference}.",
        '<h2 class="el-set-head">Progress</h2>', 'aria-label="Requested, done, 17 Nov, 11:42 am"', 'aria-label="Receipt sent, current"',
        'aria-label="Pro on, not yet"', '<span class="el-set-title">Checked by our team</span>', 'Pro, 1 month',
        '<span class="el-set-title">Add receipt</span>', '<span class="el-set-title">Cancel request</span>',
    ]);
    expect($ur)->toMatch('~<title>\s*'.upgradeUrduIsolated('Pro').' کی درخواست~u')->and($en)->toMatch('~<title>\s*Pro request~u');
});

it('words the request\'s page in Urdu once the receipt is sent, its tile with the day it was sent, and English as before', function () {
    upgradeUrduAs($this, 'ur');
    $request = upgradeUrduRequest($this->owner, inReview: true);
    $ur = upgradeUrduPage($this, UpgradeFlow::statusUrl($request));
    upgradeUrduAs($this, 'en');
    $en = upgradeUrduPage($this, UpgradeFlow::statusUrl($request));

    upgradeUrduBoth($ur, $en, [
        '<span class="el-plan-card-name">آپ کی ادائیگی چیک ہو رہی ہے</span>', '<span class="el-tag">جانچ میں</span>',
        'ہمیں آپ کی رسید '.upgradeUrduIsolated('17 Nov').' کو '.upgradeUrduIsolated('11:42 am').' پر ملی۔',
        '<span class="el-set-title">ٹرانزیکشن ID</span>', '<span class="el-set-title">ادا کرنے والا اکاؤنٹ</span>',
        '<h2 class="el-set-head">رسیدیں</h2>', '<span class="el-rtile-meta">'.upgradeUrduIsolated('17 Nov').' کو بھیجی گئی</span>',
        '<span class="el-set-title">ادائیگی کی تفصیل</span>',
    ], [
        '<span class="el-plan-card-name">Checking your payment</span>', '<span class="el-tag">In review</span>', 'We got your receipt on 17 Nov at 11:42 am.',
        '<span class="el-set-title">Transaction ID</span>', '<span class="el-set-title">Paid from</span>', '<h2 class="el-set-head">Receipts</h2>',
        '<span class="el-rtile-meta">Sent 17 Nov</span>', '<span class="el-set-title">Payment details</span>',
    ]);
});

it('words the Cancel request window and its notice in Urdu, then the cancelled request, and keeps their English', function () {
    $cancel = function (string $language): string {
        upgradeUrduAs($this, $language);
        $request = upgradeUrduRequest($this->owner);
        $page = Livewire::test(RequestStatus::class, ['request' => $request->id])
            ->assertActionExists('cancelRequest', fn (Action $a): bool => $a->getModalHeading() === ($language === 'ur' ? 'یہ درخواست منسوخ کریں؟' : 'Cancel this request?')
                && $a->getModalDescription() === ($language === 'ur' ? 'اگر آپ ادائیگی کر چکے ہیں تو اس کی جگہ اپنی رسید شامل کریں۔ منسوخ کرنے سے ادائیگی واپس نہیں ہوتی۔'
                    : "If you've already paid, add your receipt instead. Cancelling doesn't refund a payment.")
                && $a->getModalSubmitActionLabel() === ($language === 'ur' ? 'درخواست منسوخ کریں' : 'Cancel request')
                && $a->getModalCancelActionLabel() === ($language === 'ur' ? 'درخواست رہنے دیں' : 'Keep request'))
            ->callAction('cancelRequest')->assertNotified($language === 'ur' ? 'درخواست منسوخ ہو گئی' : 'Request cancelled');
        // Once more on the decided request: the notice says so.
        $page->callAction('cancelRequest')->assertNotified($language === 'ur' ? 'اس درخواست کا فیصلہ ہو چکا ہے۔' : 'This request has already been decided.');

        return upgradeUrduPage($this, UpgradeFlow::statusUrl($request));
    };

    $ur = $cancel('ur');
    expect($ur)->toContain('آپ نے اپنی '.upgradeUrduIsolated('Pro').' کی درخواست منسوخ کر دی')->toContain('<span class="el-tag">منسوخ</span>')
        ->toContain('آپ کسی بھی وقت دوبارہ شروع کر سکتے ہیں۔')->toContain('<span class="el-set-title">آپ نے منسوخ کی</span>')
        ->not->toContain('You cancelled your')->not->toContain('Cancelled by you');
    $en = $cancel('en');
    expect($en)->toContain('You cancelled your Pro request')->toContain('<span class="el-tag">Cancelled</span>')->toContain('Cancelled by you')
        ->not->toContain('آپ نے منسوخ کی');
});

it('says "You\'re on Pro" in Urdu on an approved request, with the date kept whole, and in English as before', function () {
    $plan = Workspace::query()->whereKey($this->owner->current_workspace_id)->value('plan_id');
    $approved = function (string $language) use ($plan): string {
        Workspace::query()->whereKey($this->owner->current_workspace_id)->update(['plan_id' => $plan, 'plan_expires_at' => null]);
        upgradeUrduAs($this, $language);
        UpgradeRequest::query()->delete();
        $request = upgradeUrduRequest($this->owner);
        app(UpgradeRequests::class)->approve($request, Fx::user(), 'pro', now()->addMonth());
        SettingsContext::forget();

        return upgradeUrduPage($this, UpgradeFlow::statusUrl($request));
    };

    expect($approved('ur'))->toContain('>آپ '.upgradeUrduIsolated('Pro').' پر ہیں</h1>')
        ->toContain('شکریہ۔ '.upgradeUrduIsolated('Pro').'، '.upgradeUrduIsolated('17 December 2026').' تک آن ہے۔')->toContain('>ہو گیا</a>')
        ->toContain(upgradeUrduIsolated('17 Dec 2026').' تک')->not->toContain("You're on Pro");
    expect($approved('en'))->toContain(">You're on Pro</h1>")->toContain('Thank you. Pro is on until 17 December 2026.')->toContain('until 17 Dec 2026')
        ->not->toContain('شکریہ');
});

it('words the request card and the state row on Plan & usage in Urdu, and keeps their English', function () {
    upgradeUrduAs($this, 'ur');
    upgradeUrduRequest($this->owner);
    $ur = upgradeUrduPage($this, '/app/settings/plan');
    $row = upgradeUrduPage($this, '/app/settings');
    upgradeUrduAs($this, 'en');
    $en = upgradeUrduPage($this, '/app/settings/plan');

    expect($ur)->toContain(upgradeUrduIsolated('Pro').' کی درخواست دی گئی</a>')->toContain('<span class="el-tag">ادائیگی کا انتظار</span>')
        ->toContain('<span class="el-plan-card-price">'.upgradeUrduIsolated('Rs 12,499').' ماہانہ</span>')
        ->and($row)->toContain(upgradeUrduIsolated('Pro').' کی درخواست دی گئی۔ آپ کی ادائیگی کا انتظار ہے')
        ->and($en)->toContain('Pro requested</a>')->toContain('<span class="el-tag">Waiting for payment</span>')->not->toContain('ادائیگی کا انتظار');
});

it('words the page of an account being deleted in Urdu, with the date in Urdu kept whole, and English as before', function () {
    $deleting = function (bool $deleting): void {
        User::query()->whereKey($this->owner->id)->update(['deletion_requested_at' => $deleting ? now() : null, 'deletes_at' => $deleting ? now()->addDays(7) : null]);
        $this->owner = $this->owner->fresh();
    };
    $page = function (string $language, bool $being) use ($deleting): string {
        $deleting($being);
        upgradeUrduAs($this, $language);

        return upgradeUrduPage($this, '/app/account-deletion');
    };

    upgradeUrduBoth($page('ur', true), $page('en', true), [
        'آپ کا اکاؤنٹ حذف ہو رہا ہے',
        'آپ نے یہ اکاؤنٹ حذف کرنے کو کہا تھا۔ یہ '.upgradeUrduIsolated('24 نومبر 2026').' کو اپنی ریکارڈنگز، ٹرانسکرپٹس، اسکرینز، فائلوں، پروجیکٹس اور ڈیوائسز سمیت مٹا دیا جائے گا۔',
        'اسے رکھیں تو سب کچھ پہلے جیسا رہے گا: بعد میں اپنا فون اور کمپیوٹر دوبارہ سائن اِن کریں۔', 'میرا اکاؤنٹ رکھیں',
    ], [
        'Your account is being deleted', 'You asked to delete this account. It is erased on November 24, 2026, with its recordings',
        'Keep it, and everything stays as it was', 'Keep my account',
    ]);
    upgradeUrduBoth($page('ur', false), $page('en', false), ['آپ کا اکاؤنٹ حذف نہیں ہو رہا۔', 'ڈیش بورڈ کھولیں'], ['Your account is not being deleted.', 'Open the dashboard']);

    foreach (['ur' => 'آپ کا اکاؤنٹ رہے گا', 'en' => 'Your account stays'] as $language => $notice) {
        $deleting(true);
        upgradeUrduAs($this, $language);
        Livewire::test(AccountBeingDeleted::class)->callAction(TestAction::make('keepAccount')->schemaComponent('deletion-actions', schema: 'content'))
            ->assertNotified($notice);
    }
});

it('words Help in Urdu, its empty search and its Contact support window, and English as before', function () {
    upgradeUrduAs($this, 'ur');
    $ur = upgradeUrduPage($this, '/app/help?q=zzqqxx');
    upgradeUrduAs($this, 'en');
    $en = upgradeUrduPage($this, '/app/help?q=zzqqxx');

    upgradeUrduBoth($ur, $en, [
        'گائیڈ میں تلاش کریں، دیکھیں کیا نیا ہے، یا سپورٹ کو لکھیں۔', 'سپورٹ سے رابطہ کریں', '<label for="el-help-q" class="el-sr">گائیڈ میں تلاش کریں</label>',
        'placeholder="گائیڈ میں تلاش کریں: کیپشنز، وائس نوٹس، شیئرنگ…"',
        // The search words in the sentence.
        'گائیڈ میں “'.upgradeUrduIsolated('zzqqxx').'” سے کچھ نہیں ملا۔ دوسرے الفاظ آزمائیں، یا سپورٹ سے رابطہ کریں۔',
        'نیا کیا ہے', 'ایپس کے تازہ ترین ورژن اور ان میں کیا بدلا۔', 'ابھی کوئی ریلیز نوٹس نہیں۔',
    ], [
        'Search the guide, see what is new, or write to support.', 'Contact support', 'placeholder="Search the guide: captions, voice notes, sharing…"',
        'Nothing in the guide matches “zzqqxx”. Try other words, or contact support.', 'The latest versions of the apps and what changed.', 'No release notes yet.',
    ]);
    expect($ur)->toMatch('~<title>\s*مدد~u')->and($en)->toMatch('~<title>\s*Help~u');
    upgradeUrduAs($this, 'ur');
    $urList = upgradeUrduPage($this, '/app/help');
    upgradeUrduAs($this, 'en');
    $enList = upgradeUrduPage($this, '/app/help');
    upgradeUrduBoth($urList, $enList, ['aria-label="گائیڈ کے حصے"'], ['aria-label="Guide sections"']);
    expect($urList)->toMatch('~class="fi-section-header-heading"\s*>\s*گائیڈ\s*</h2>~u')
        ->and($enList)->toMatch('~class="fi-section-header-heading"\s*>\s*The guide\s*</h2>~u');

    // The window: its words, its fields' labels and help, then Send and the notice.
    $window = function (string $language): array {
        upgradeUrduAs($this, $language);
        $page = Livewire::test(Help::class)->mountAction('contactSupport');
        $action = $page->instance()->getAction('contactSupport');
        $fields = (new ReflectionMethod($page->instance(), 'getMountedActionSchema'))->invoke($page->instance())?->getFlatComponents() ?? [];
        $help = fn ($c): string => collect($c->getChildSchema($c::BELOW_CONTENT_SCHEMA_KEY)?->getComponents() ?? [])
            ->map(fn ($t): string => ' · '.(string) $t->getContent())->implode('');
        $labels = collect($fields)->filter(fn ($c): bool => method_exists($c, 'getLabel'))
            ->map(fn ($c): string => (string) $c->getLabel().$help($c))->values()->all();
        $words = [$action?->getLabel(), $action?->getModalDescription(), $action?->getModalSubmitActionLabel(), ...$labels];
        $page->fillForm(['subject' => 'The app stops', 'message' => 'It stops after a minute.'])->callMountedAction();

        return [$words, $page];
    };
    $address = app(SupportMessages::class)->address();
    [$words, $page] = $window('ur');
    expect($words)->toBe(['سپورٹ سے رابطہ کریں', 'آپ کا پیغام '.upgradeUrduIsolated($address).' پر جاتا ہے؛ جواب آپ کی ای میل پر آتا ہے۔', 'بھیجیں',
        'موضوع', 'پیغام', 'میری ڈیوائسز کی تازہ ترین رپورٹس ساتھ لگائیں · ان کی ایپ کے ورژن اور ان کے سیٹ اپ کی جانچ (ریکارڈنگ، کیپشنز، اجازتیں)۔ کوئی ریکارڈنگ نہیں، کوئی الفاظ نہیں۔']);
    $page->assertNotified('پیغام بھیج دیا گیا');

    [$words, $page] = $window('en');
    expect($words)->toBe(['Contact support', "Your message goes to {$address}; the reply comes to your email.", 'Send',
        'Subject', 'Message', "Attach my devices' latest reports · Their app versions and the checks of their setup (recording, captions, permissions). No recordings, no words."]);
    $page->assertNotified('Message sent');
});
