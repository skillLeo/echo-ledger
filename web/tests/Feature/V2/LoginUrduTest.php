<?php

declare(strict_types=1);

use App\Filament\Auth\Login;
use App\Mail\EmailCodeMail;
use App\Services\Accounts\SignInGuard;
use App\Services\Accounts\SignUp;
use App\Services\SettingsRepository;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * Urdu package 5: the sign-in page's words come through the translator. A signed-out visitor has no console language
 * (ConsoleV2::language() is English without a signed-in person in the new frame), so the page reads English to everyone;
 * these checks set the locale by hand to show the words are ready. English is compared with the HTML saved in
 * tests/Feature/V2/golden/ before the words were wrapped. UPDATE_GOLDEN=1 saves them again on purpose.
 */

require_once __DIR__.'/Support/golden.php';
require_once __DIR__.'/../Accounts/Support/account_helpers.php';

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-17 11:42:00', 'UTC'));
    Filament::setCurrentPanel('app');
    Mail::fake();
    Http::preventStrayRequests();
    $this->person = Fx::user(['name' => 'Farah Siddiqui', 'email' => 'farah@example.test', 'password' => 'a-good-password-42']);
});

afterEach(function () {
    app()->setLocale('en');
});

/** Compares [$html] with the saved copy [$name] (UPDATE_GOLDEN=1 saves it). */
function signInGolden(string $name, string $html): void
{
    $file = __DIR__."/golden/{$name}.html";
    if (getenv('UPDATE_GOLDEN') === '1') {
        file_put_contents($file, $html);
    }
    expect(file_exists($file))->toBeTrue("no saved copy of {$name}: run with UPDATE_GOLDEN=1 once")
        ->and($html)->toBe(file_get_contents($file), "the English {$name} changed");
}

it('renders the English sign-in page byte for byte as before', function () {
    expect(config('app.debug'))->toBeFalse('the saved pages need debug off: run the suite with APP_DEBUG unset');
    $html = normaliseClassic((string) $this->get('/app/login')->assertOk()->getContent(), (string) session()->token());
    signInGolden('signin-en', $html);
});

it('renders the English password and code steps byte for byte as before', function () {
    $page = Livewire::test(Login::class)->fillForm(['email' => 'farah@example.test'])->call('authenticate')->assertSet('step', Login::STEP_PASSWORD);
    signInGolden('signin-en-password', normaliseClassic($page->html()));
    $page->call('emailCode')->assertSet('step', Login::STEP_CODE);
    signInGolden('signin-en-code', normaliseClassic($page->html()));
});

/** The code emailed to [$email] last. */
function signInCode(string $email): string
{
    $code = '';
    Mail::assertQueued(EmailCodeMail::class, function (EmailCodeMail $mail) use ($email, &$code): bool {
        if ($mail->hasTo($email)) {
            $code = $mail->code;
        }

        return true;
    });

    return $code;
}

/** The page at its two-step step: the email code was right, the authenticator's code comes next. */
function signInAtTwoStep(object $test): mixed
{
    accountsEnrolTotp($test->person);
    $page = Livewire::test(Login::class)->fillForm(['email' => 'farah@example.test'])->call('authenticate')->call('emailCode');

    return $page->fillForm(['code' => signInCode('farah@example.test')])->call('authenticate')->assertSet('step', Login::STEP_OTP);
}

/** The page at its last sign-up step (name and the Terms), for a new address. */
function signInAtProfile(): mixed
{
    app(SettingsRepository::class)->set('signup.mode', SignUp::ANYONE);
    $page = Livewire::test(Login::class)->fillForm(['email' => 'new.one@example.test'])->call('authenticate')->assertSet('step', Login::STEP_CODE);

    return $page->fillForm(['code' => signInCode('new.one@example.test')])->call('authenticate')->assertSet('step', Login::STEP_PROFILE);
}

it('renders the English two-step and sign-up steps byte for byte as before', function () {
    signInGolden('signin-en-otp', normaliseClassic(signInAtTwoStep($this)->html()));
    signInGolden('signin-en-profile', normaliseClassic(signInAtProfile()->html()));
});

/** A value kept whole in a right-to-left sentence: between FIRST STRONG ISOLATE and POP DIRECTIONAL ISOLATE. */
function signInIsolated(string $value): string
{
    return "\u{2068}{$value}\u{2069}";
}

/** The component's HTML with its entities read. */
function signInHtml(mixed $page): string
{
    return html_entity_decode($page->html(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * The notices sent since the last look, as "title · body" (read as Filament's own assertNotified reads them).
 *
 * @return list<string>
 */
function signInNotices(): array
{
    app(NotificationMailbox::class)->flush();
    $notices = new Notifications;
    $notices->mount();
    $notices->pullNotificationsFromSession();

    return $notices->notifications->map(fn ($n): string => trim($n->getTitle().' · '.$n->getBody(), ' ·'))->values()->all();
}

it('has no console language before sign-in: the sign-in page reads English to everyone', function () {
    expect(app()->getLocale())->toBe('en');
    $html = (string) $this->withCookie('locale', 'ur')->withHeaders(['Accept-Language' => 'ur-PK,ur;q=0.9'])->get('/app/login')->assertOk()->getContent();
    expect($html)->toContain('Sign in or create an account')->not->toContain('سائن اِن');
});

it('words the sign-in steps in Urdu once the console is in Urdu, with the address kept whole, and English as before', function () {
    $steps = function (string $language): array {
        app()->setLocale($language);
        $page = Livewire::test(Login::class);
        $first = signInHtml($page);
        $page->fillForm(['email' => 'farah@example.test'])->call('authenticate')->assertSet('step', Login::STEP_PASSWORD);
        $password = signInHtml($page);
        $page->call('emailCode')->assertSet('step', Login::STEP_CODE);

        return [$first, $password, signInHtml($page), signInNotices()];
    };

    [$first, $password, $code, $notices] = $steps('ur');
    expect($first)->toContain('سائن اِن کریں یا اکاؤنٹ بنائیں')->toContain('جاری رکھنے کے لیے اپنی ای میل لکھیں۔')->toContain('ای میل')->toContain('جاری رکھیں')
        ->not->toContain('Sign in or create an account')->not->toContain('Enter your email')
        ->and($password)->toContain('خوش آمدید')->toContain('سائن اِن')->toContain('اس کی جگہ مجھے کوڈ ای میل کریں')->toContain('مجھے سائن اِن رکھیں')
        ->toContain('کوئی اور ای میل استعمال کریں')->not->toContain('Welcome back')->not->toContain('Email me a code instead')->not->toContain('Keep me signed in')
        ->and($code)->toContain('اپنی ای میل دیکھیں')->toContain('ہم نے '.signInIsolated('farah@example.test').' پر 6 ہندسوں کا کوڈ بھیجا ہے۔ یہ 10 منٹ تک چلے گا۔')
        ->toContain('ای میل والا کوڈ')->toContain('placeholder="6 ہندسے"')->toContain('نیا کوڈ بھیجیں')
        ->not->toContain('Check your email')->not->toContain('Code from the email')->not->toContain('Send a new code')
        ->and($notices)->toContain('کوڈ بھیج دیا گیا · '.signInIsolated('farah@example.test').' میں 6 ہندسوں کا کوڈ دیکھیں۔');

    Carbon::setTestNow(now()->addMinutes(2));
    [$first, $password, $code, $notices] = $steps('en');
    expect($first)->toContain('Sign in or create an account')->toContain('Enter your email to continue.')->not->toContain('جاری رکھیں')
        ->and($password)->toContain('Welcome back')->toContain('Email me a code instead')->toContain('Keep me signed in')->toContain('Use another email')
        ->and($code)->toContain('We sent a 6-digit code to farah@example.test. It works for 10 minutes.')->toContain('Code from the email')
        ->toContain('placeholder="6 digits"')->toContain('Send a new code')
        ->and($notices)->toContain('Code sent · Check farah@example.test for a 6-digit code.');
});

it('tells a suspended account in each language', function (string $language, string $title, string $why) {
    $this->person->forceFill(['suspended_at' => now()])->save();
    app()->setLocale($language);
    Livewire::test(Login::class)->fillForm(['email' => 'farah@example.test'])->call('authenticate')->assertHasFormErrors(['email' => $why]);
    session()->put('account.suspended', true);
    Livewire::test(Login::class);
    expect(signInNotices())->toContain("{$title} · {$why}");
})->with([
    'Urdu' => ['ur', 'یہ اکاؤنٹ معطل ہے', 'یہ اکاؤنٹ معطل ہے۔ وجہ جاننے کے لیے اس سرور کے مالک کو لکھیں۔'],
    'English as before' => ['en', 'This account is suspended', 'This account is suspended. Write to the owner of this server to ask why.'],
]);

it('words the two-step step, its wrong code and the lock in each language', function (string $language, string $heading, string $sub, string $label, string $help, string $wrong, string $locked) {
    app()->setLocale($language);
    $page = signInAtTwoStep($this);
    expect(signInHtml($page))->toContain($heading)->toContain($sub)->toContain($label)->toContain($help);
    $page->fillForm(['otp' => '000000'])->call('authenticate')->assertHasFormErrors(['otp' => $wrong]);
    foreach (range(1, SignInGuard::MAX_FAILURES) as $ignored) {
        app(SignInGuard::class)->fail('farah@example.test', $this->person->id);
    }
    signInNotices();
    $page->fillForm(['otp' => '000000'])->call('authenticate');
    expect(signInNotices())->toContain($locked);
})->with([
    'Urdu' => ['ur', 'دو مرحلوں والا سائن اِن', 'اپنی آتھنٹیکیٹر ایپ کا کوڈ لکھیں۔', 'آپ کی آتھنٹیکیٹر ایپ کا کوڈ', 'یا آپ کے ریکوری کوڈز میں سے کوئی ایک۔',
        'یہ کوڈ غلط ہے یا پہلے استعمال ہو چکا ہے۔', 'بہت زیادہ ناکام سائن اِن۔ بعد میں دوبارہ کوشش کریں۔'],
    'English as before' => ['en', 'Two-step sign-in', 'Enter the code from your authenticator app.', 'Code from your authenticator app', 'Or one of your recovery codes.',
        'That code is wrong or was used already.', 'Too many failed sign-ins. Try again later.'],
]);

it('words the sign-up step in each language, the Terms one sentence with its two links', function (string $language, string $heading, string $sub, string $name, string $button, string $terms, string $accept) {
    app()->setLocale($language);
    $page = signInAtProfile();
    expect($page->html())->toContain($heading)->toContain($sub)->toContain($name)->toContain($button)->toContain($terms);
    $page->fillForm(['name' => 'Omar', 'accept_terms' => false])->call('authenticate')->assertHasFormErrors(['accept_terms' => $accept]);
})->with([
    'Urdu' => ['ur', 'اپنا اکاؤنٹ بنائیں', 'آخری مرحلہ: آپ کا نام، اور شرائط۔', 'آپ کا نام', 'میرا اکاؤنٹ بنائیں',
        'میں <a href="https://echoledger.test/terms" target="_blank" class="underline">شرائط</a> اور <a href="https://echoledger.test/privacy" target="_blank" class="underline">پرائیویسی پالیسی</a> سے متفق ہوں',
        'اپنا اکاؤنٹ بنانے کے لیے شرائط اور پرائیویسی پالیسی قبول کریں۔'],
    'English as before' => ['en', 'Create your account', 'Last step: your name, and the Terms.', 'Your name', 'Create my account',
        'I accept the <a href="https://echoledger.test/terms" target="_blank" class="underline">Terms</a> and the <a href="https://echoledger.test/privacy" target="_blank" class="underline">Privacy Policy</a>',
        'Accept the Terms and the Privacy Policy to make your account.'],
]);
