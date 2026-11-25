<?php

declare(strict_types=1);

use App\Exceptions\ApiError;
use App\Filament\Auth\Login;
use App\Filament\Auth\RequestPasswordReset;
use App\Mail\EmailCodeMail;
use App\Services\Accounts\EmailCodes;
use App\Services\Accounts\Invitations;
use App\Services\Accounts\InviteLinks;
use App\Services\Accounts\SignInGuard;
use App\Services\Accounts\SignUp;
use App\Services\CredentialVault;
use App\Services\SettingsRepository;
use App\Support\RecordingConsent;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * Urdu package 5: the sign-in page's words come through the translator, in the visitor's language (VisitorLanguage:
 * the browser's, or the one picked on the page; VisitorLanguageTest). These checks set the locale by hand for each step.
 * English is compared with the HTML saved in tests/Feature/V2/golden/ before the words were wrapped (the full page also
 * has the language picker since v2.1). UPDATE_GOLDEN=1 saves them again on purpose.
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

it('reads the sign-in page in the visitor\'s browser language, and English to an English browser', function () {
    expect((string) $this->withHeaders(['Accept-Language' => 'ur-PK,ur;q=0.9'])->get('/app/login')->assertOk()->getContent())
        ->toContain('سائن اِن کریں یا اکاؤنٹ بنائیں')->not->toContain('Sign in or create an account');
    app()->setLocale('en');
    expect((string) $this->withHeaders(['Accept-Language' => 'en-GB,en;q=0.9'])->get('/app/login')->assertOk()->getContent())
        ->toContain('Sign in or create an account')->not->toContain('سائن اِن کریں');
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

it('words the sign-up step in each language, the Terms one sentence with its two links', function (string $language, string $heading, string $sub, string $name, string $button, string $terms, string $accept, string $consent) {
    app()->setLocale($language);
    $page = signInAtProfile();
    expect($page->html())->toContain($heading)->toContain($sub)->toContain($name)->toContain($button)->toContain($terms)->toContain($consent);
    $page->fillForm(['name' => 'Omar', 'accept_terms' => false])->call('authenticate')->assertHasFormErrors(['accept_terms' => $accept]);
})->with([
    'Urdu' => ['ur', 'اپنا اکاؤنٹ بنائیں', 'آخری مرحلہ: آپ کا نام، اور شرائط۔', 'آپ کا نام', 'میرا اکاؤنٹ بنائیں',
        'میں <a href="https://echoledger.test/terms" target="_blank" class="underline">شرائط</a> اور <a href="https://echoledger.test/privacy" target="_blank" class="underline">پرائیویسی پالیسی</a> سے متفق ہوں',
        'اپنا اکاؤنٹ بنانے کے لیے شرائط اور پرائیویسی پالیسی قبول کریں۔',
        'ریکارڈنگ کے قوانین ہر ملک میں مختلف ہیں۔ جہاں قانون کا تقاضا ہو، اپنی میٹنگ کے لوگوں کو بتائیں کہ آپ ریکارڈ کر رہے ہیں۔'],
    'English as before' => ['en', 'Create your account', 'Last step: your name, and the Terms.', 'Your name', 'Create my account',
        'I accept the <a href="https://echoledger.test/terms" target="_blank" class="underline">Terms</a> and the <a href="https://echoledger.test/privacy" target="_blank" class="underline">Privacy Policy</a>',
        'Accept the Terms and the Privacy Policy to make your account.', RecordingConsent::TEXT],
]);

it('renders the English sign-up page, with the Google button, and its invitation-only line byte for byte as before', function () {
    app(CredentialVault::class)->put('google', 'client_id', '1234-test.apps.googleusercontent.com');
    app(CredentialVault::class)->put('google', 'client_secret', 'secret');
    app(SettingsRepository::class)->set('signup.mode', SignUp::ANYONE);
    signInGolden('signup-en', normaliseClassic((string) $this->get('/app/register')->assertOk()->getContent(), (string) session()->token()));
    app(SettingsRepository::class)->set('signup.mode', SignUp::INVITED);
    signInGolden('signup-en-invited', normaliseClassic((string) $this->get('/app/register')->assertOk()->getContent(), (string) session()->token()));
});

/** A code that is not [$right]. */
function signInWrongCode(string $right): string
{
    return $right === '000000' ? '111111' : '000000';
}

it('answers a wrong email code with the tries left, then an expired code, in each language', function (string $language, string $four, string $one, string $none, string $expired, string $wait) {
    app()->setLocale($language);
    $page = Livewire::test(Login::class)->fillForm(['email' => 'farah@example.test'])->call('authenticate')->call('emailCode')->assertSet('step', Login::STEP_CODE);
    $wrong = signInWrongCode(signInCode('farah@example.test'));
    $page->fillForm(['code' => $wrong])->call('authenticate')->assertHasFormErrors(['code' => $four]);
    foreach (range(1, 2) as $ignored) {
        $page->fillForm(['code' => $wrong])->call('authenticate');
    }
    $page->fillForm(['code' => $wrong])->call('authenticate')->assertHasFormErrors(['code' => $one]);
    $page->fillForm(['code' => $wrong])->call('authenticate')->assertHasFormErrors(['code' => $none]);

    // A new code at once: wait; a minute later it comes, and ten minutes after that it has expired.
    signInNotices();
    $page->call('emailCode');
    expect(signInNotices())->toContain($wait);
    Carbon::setTestNow(now()->addMinutes(2));
    Mail::fake();
    $page->call('emailCode');
    $right = signInCode('farah@example.test');
    Carbon::setTestNow(now()->addMinutes(EmailCodes::CODE_MINUTES + 1));
    $page->fillForm(['code' => $right])->call('authenticate')->assertHasFormErrors(['code' => $expired]);
})->with([
    'Urdu' => ['ur', 'یہ کوڈ درست نہیں۔ '.signInIsolated('4').' کوششیں باقی ہیں۔', 'یہ کوڈ درست نہیں۔ '.signInIsolated('1').' کوشش باقی ہے۔',
        'یہ کوڈ درست نہیں۔ نیا کوڈ منگوائیں۔', 'اس کوڈ کی میعاد ختم ہو گئی ہے۔ نیا کوڈ منگوائیں۔', 'نیا کوڈ منگوانے سے پہلے '.signInIsolated('60').' سیکنڈ انتظار کریں۔'],
    'English as before' => ['en', 'That code is not right. 4 tries left.', 'That code is not right. 1 try left.', 'That code is not right. Ask for a new one.',
        'This code has expired. Ask for a new one.', 'Wait 60 seconds before asking for a new code.'],
]);

it('says every email-code answer in English word for word as the API does, and in Urdu in Urdu', function () {
    $codes = app(EmailCodes::class);
    $catch = function (Closure $call): ApiError {
        try {
            $call();
        } catch (ApiError $e) {
            return $e;
        }
        throw new RuntimeException('no ApiError');
    };
    $errors = [];
    $codes->send('farah@example.test', null, '10.0.0.9');
    $right = signInCode('farah@example.test');
    foreach ([4, 3, 2, 1, 0] as $ignored) {
        $errors[] = $catch(fn () => $codes->check('farah@example.test', signInWrongCode($right)));
    }
    $errors[] = $catch(fn () => $codes->check('farah@example.test', $right));
    $errors[] = $catch(fn () => $codes->send('farah@example.test', null, '10.0.0.9'));
    foreach (range(1, EmailCodes::PER_EMAIL_HOUR - 1) as $ignored) {
        Carbon::setTestNow(now()->addSeconds(EmailCodes::RESEND_SECONDS + 1));
        $codes->send('farah@example.test', null, '10.0.0.9');
    }
    Carbon::setTestNow(now()->addSeconds(EmailCodes::RESEND_SECONDS + 1));
    $errors[] = $catch(fn () => $codes->send('farah@example.test', null, '10.0.0.9'));
    app(SettingsRepository::class)->set('signup.mode', SignUp::INVITED);
    $errors[] = $catch(fn () => $codes->start('someone.new@example.test'));
    app(SettingsRepository::class)->set('signup.mode', SignUp::ANYONE);
    $errors[] = $catch(fn () => $codes->start('someone@mailinator.com'));
    app(CredentialVault::class)->put('turnstile', 'site_key', '0x4AAAA-site');
    app(CredentialVault::class)->put('turnstile', 'secret_key', '0x4AAAA-secret');
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false])]);
    $errors[] = $catch(fn () => $codes->send('someone.new@example.test', 'bad-answer', '10.0.0.10', requireTurnstile: true));
    foreach (range(1, $codes->signUpsPerIpDay()) as $ignored) {
        $codes->countSignUp('10.0.0.11');
    }
    $errors[] = $catch(fn () => $codes->assertSignUpQuota('10.0.0.11'));
    $errors[] = $catch(fn () => $codes->redeemSignUpToken('not-a-token'));
    $errors[] = new ApiError(422, 'invalid_code', 'An account with this email exists already. Sign in instead.', ['account' => 'existing']);

    app()->setLocale('en');
    expect(array_map(Login::codeError(...), $errors))->toBe(array_map(fn (ApiError $e): string => $e->getMessage(), $errors))
        ->and(array_map(fn (ApiError $e): string => $e->apiCode(), $errors))->toBe(['invalid_code', 'invalid_code', 'invalid_code', 'invalid_code', 'invalid_code',
            'code_expired', 'resend_too_soon', 'too_many_attempts', 'sign_up_closed', 'email_not_allowed', 'turnstile_failed', 'sign_up_limit', 'invalid_code', 'invalid_code']);

    app()->setLocale('ur');
    $urdu = array_map(Login::codeError(...), $errors);
    expect($urdu[0])->toBe('یہ کوڈ درست نہیں۔ '.signInIsolated('4').' کوششیں باقی ہیں۔')
        ->and($urdu[4])->toBe('یہ کوڈ درست نہیں۔ نیا کوڈ منگوائیں۔')
        ->and(array_slice($urdu, 5))->toBe(['اس کوڈ کی میعاد ختم ہو گئی ہے۔ نیا کوڈ منگوائیں۔', 'نیا کوڈ منگوانے سے پہلے '.signInIsolated((string) $errors[6]->extra()['resend_after']).' سیکنڈ انتظار کریں۔',
            'بہت زیادہ کوڈ منگوائے گئے۔ بعد میں دوبارہ کوشش کریں۔', 'اس سرور پر نئے اکاؤنٹ بنانا بند ہے۔ مالک سے دعوت مانگیں۔',
            'اس ای میل ایڈریس سے اکاؤنٹ نہیں بن سکتا۔ اپنا معمول کا ای میل ایڈریس استعمال کریں۔', 'روبوٹ نہ ہونے کی جانچ کامیاب نہیں ہوئی۔ دوبارہ کوشش کریں۔',
            'آج اس نیٹ ورک سے بہت زیادہ نئے اکاؤنٹس بنے۔ کل دوبارہ کوشش کریں۔', 'اکاؤنٹ بنانے کا وقت ختم ہو گیا۔ اپنی ای میل سے دوبارہ شروع کریں۔',
            'اس ای میل کا اکاؤنٹ پہلے سے موجود ہے۔ اس کی جگہ سائن اِن کریں۔']);
    RateLimiter::clear('sign-ups-per-ip:'.hash('sha256', '10.0.0.11'));
});

it('answers Forgot password in each language', function (string $language, string $notice) {
    app()->setLocale($language);
    signInNotices();
    Livewire::test(RequestPasswordReset::class)->fillForm(['email' => 'farah@example.test'])->call('request');
    expect(signInNotices())->toContain($notice);
})->with([
    'Urdu' => ['ur', 'اپنی ای میل دیکھیں · اگر کوئی اکاؤنٹ یہ ایڈریس استعمال کرتا ہے تو نیا پاس ورڈ چننے کا لنک آ رہا ہے۔'],
    'English as before' => ['en', 'Check your email · If an account uses that address, a link to choose a new password is on its way.'],
]);

it('tells a closed invitation and a closed invite link in each language, the English as the services write it', function () {
    $invitation = new ApiError(410, 'invitation_closed', Invitations::CLOSED);
    $link = new ApiError(410, 'link_closed', InviteLinks::CLOSED);

    app()->setLocale('ur');
    expect(Login::codeError($invitation))->toBe('یہ دعوت پہلے ہی استعمال ہو چکی ہے، ختم ہو گئی ہے، واپس لے لی گئی ہے، یا بھیجنے والا اب دعوت نہیں دے سکتا۔ نئی دعوت مانگیں۔')
        ->and(Login::codeError($link))->toBe('دعوت کا یہ لنک اب کھلا نہیں: یہ ختم ہو گیا، واپس لے لیا گیا، یا جتنے لوگوں کی اجازت تھی وہ شامل ہو چکے ہیں۔ نیا لنک مانگیں۔');

    app()->setLocale('en');
    expect(Login::codeError($invitation))->toBe(Invitations::CLOSED)
        ->and(Login::codeError($link))->toBe(InviteLinks::CLOSED);
});
