<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use App\Exceptions\ApiError;
use App\Filament\Pages\GetTheApps;
use App\Filament\Support\Notice as Notification;
use App\Filament\Support\NoticeTargets;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Services\Accounts\EmailCodes;
use App\Services\Accounts\SignInGuard;
use App\Services\Accounts\SignUp;
use App\Services\Accounts\Turnstile;
use App\Services\Accounts\TwoFactorCodes;
use App\Support\RecordingConsent;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * The console's sign-in and sign-up (since v1.6, P1: one flow, as in the apps). "Email → Continue"; an account then
 * signs in with its password or "Email me a code" (and its two-step code when it has one); a new address gets a
 * 6-digit code by email (after the Turnstile check when it is set), then gives its name and accepts the Terms, and
 * lands on "Get the apps". Passkeys, Google and the apps' browser sign-in stay as they were. Five failed passwords for
 * one email lock it here too, as in the apps (since v1.5).
 */
class Login extends BaseLogin
{
    public const STEP_EMAIL = 'email';

    public const STEP_PASSWORD = 'password';

    public const STEP_HUMAN = 'human';

    public const STEP_CODE = 'code';

    public const STEP_OTP = 'otp';

    public const STEP_PROFILE = 'profile';

    #[Locked]
    public string $step = self::STEP_EMAIL;

    /** Whether the address has an account (set by Continue). */
    #[Locked]
    public bool $existing = false;

    /** The one-time sign-up token once a new address's code was right. */
    #[Locked]
    public ?string $signUpToken = null;

    /** The address the steps after the first are for; the field cannot be changed then. */
    #[Locked]
    public ?string $confirmedEmail = null;

    public function mount(): void
    {
        parent::mount();
        if (session()->pull('account.suspended') === true) {
            Notification::make()->title(__('This account is suspended'))->body(__(self::SUSPENDED))->danger()->persistent()->send();
        }
    }

    /** What a suspended account is told when it tries to sign in (since v1.6); the English key, shown through the translator. */
    public const SUSPENDED = 'This account is suspended. Write to the owner of this server to ask why.';

    public function authenticate(): ?LoginResponse
    {
        $this->resetErrorBag();
        // A password manager (or an older form) may send the email and the password together: sign in with them.
        if ($this->step === self::STEP_EMAIL && filled($this->data['password'] ?? null)) {
            $this->step = self::STEP_PASSWORD;
        }

        return match ($this->step) {
            self::STEP_EMAIL => $this->continueWithEmail(),
            self::STEP_HUMAN => $this->emailCode(),
            self::STEP_CODE => $this->checkCode(),
            self::STEP_OTP => $this->checkOtp(),
            self::STEP_PROFILE => $this->createAccount(),
            default => $this->withPassword(),
        };
    }

    private function withPassword(): ?LoginResponse
    {
        $email = $this->email();
        if (User::query()->where('email', $email)->whereNotNull('suspended_at')->exists()) {
            throw ValidationException::withMessages(['data.email' => __(self::SUSPENDED)]);
        }
        $guard = app(SignInGuard::class);
        $locked = $guard->lockedFor($email);
        if ($locked > 0) {
            $this->getRateLimitedNotification(new TooManyRequestsException(static::class, 'authenticate', (string) request()->ip(), $locked))?->send();

            return null;
        }
        $response = parent::authenticate();
        if ($response !== null) {
            $guard->clear($email);
        }

        return $response;
    }

    /** "Continue": what the address can do decides the next step. */
    private function continueWithEmail(): ?LoginResponse
    {
        if (! $this->limited(10)) {
            return null;
        }
        $state = $this->form->getState();
        $email = EmailCodes::normalize((string) ($state['email'] ?? ''));
        try {
            $answer = app(EmailCodes::class)->start($email);
        } catch (ApiError $e) {
            throw ValidationException::withMessages(['data.email' => self::codeError($e)]);
        }
        if ($answer['account'] === 'existing' && User::query()->where('email', $email)->whereNotNull('suspended_at')->exists()) {
            throw ValidationException::withMessages(['data.email' => __(self::SUSPENDED)]);
        }
        $this->confirmedEmail = $email;
        $this->data['email'] = $email;
        $this->existing = $answer['account'] === 'existing';
        if ($this->existing && in_array('password', $answer['methods'], true)) {
            $this->step = self::STEP_PASSWORD;

            return null;
        }
        if (! $this->existing && app(Turnstile::class)->active()) {
            // The sign-up page shows the check with the email (since v1.6.2): its answer goes along with Continue.
            if (filled($this->data['turnstile'] ?? null)) {
                return $this->emailCode();
            }
            $this->step = self::STEP_HUMAN;

            return null;
        }

        return $this->emailCode();
    }

    /** "Email me a code" (also after the Turnstile check, and to send a new one). */
    public function emailCode(): ?LoginResponse
    {
        if ($this->confirmedEmail === null || ! $this->limited(10, 'emailCode')) {
            return null;
        }
        try {
            app(EmailCodes::class)->send($this->confirmedEmail, $this->data['turnstile'] ?? null, request()->ip(), requireTurnstile: ! $this->existing);
        } catch (ApiError $e) {
            if ($e->apiCode() === 'turnstile_failed') {
                $this->data['turnstile'] = null;
                $this->dispatch('turnstile-reset');
            }
            Notification::make()->title(self::codeError($e))->url(NoticeTargets::forError($e))->danger()->send();

            return null;
        }
        $this->data['code'] = null;
        $this->step = self::STEP_CODE;
        Notification::make()->title(__('Code sent'))->body(__('Check :email for a 6-digit code.', ['email' => $this->confirmedEmail]))->success()->send();

        return null;
    }

    private function checkCode(): ?LoginResponse
    {
        if ($this->confirmedEmail === null || ! $this->limited(10)) {
            return null;
        }
        $codes = app(EmailCodes::class);
        try {
            $row = $codes->check($this->confirmedEmail, (string) ($this->data['code'] ?? ''));
            $user = User::query()->where('email', $this->confirmedEmail)->first();
            if (! $user instanceof User) {
                $codes->assertMaySignUp($this->confirmedEmail);
                $this->signUpToken = $codes->signUpToken($row);
                $this->step = self::STEP_PROFILE;

                return null;
            }
        } catch (ApiError $e) {
            throw ValidationException::withMessages(['data.code' => self::codeError($e)]);
        }
        if ($user->hasTwoFactor()) {
            $this->step = self::STEP_OTP;

            return null;
        }
        $codes->useUp($row);

        return $this->signInAs($user);
    }

    /** The two-step code after an email code; the email code is checked again (it stays good for 10 minutes). */
    private function checkOtp(): ?LoginResponse
    {
        if ($this->confirmedEmail === null || ! $this->limited(5)) {
            return null;
        }
        $guard = app(SignInGuard::class);
        if ($guard->lockedFor($this->confirmedEmail) > 0) {
            Notification::make()->title(__('Too many failed sign-ins. Try again later.'))->danger()->send();

            return null;
        }
        $codes = app(EmailCodes::class);
        try {
            $row = $codes->check($this->confirmedEmail, (string) ($this->data['code'] ?? ''));
        } catch (ApiError $e) {
            $this->step = self::STEP_CODE;

            throw ValidationException::withMessages(['data.code' => self::codeError($e)]);
        }
        $user = User::query()->where('email', $this->confirmedEmail)->firstOrFail();
        if (! app(TwoFactorCodes::class)->verify($user, trim((string) ($this->data['otp'] ?? '')))) {
            $guard->fail($this->confirmedEmail, $user->id);

            throw ValidationException::withMessages(['data.otp' => __('That code is wrong or was used already.')]);
        }
        $codes->useUp($row);

        return $this->signInAs($user);
    }

    /** Name and the Terms: the account, its personal workspace on the default plan, then "Get the apps". */
    private function createAccount(): null
    {
        if ($this->signUpToken === null || ! $this->limited(5)) {
            return null;
        }
        $state = $this->form->getState();
        $codes = app(EmailCodes::class);
        $ip = request()->ip();
        try {
            $codes->assertSignUpQuota($ip);
            $user = DB::transaction(function () use ($codes, $state): User {
                $email = $codes->redeemSignUpToken((string) $this->signUpToken);
                if (User::query()->where('email', $email)->exists()) {
                    throw new ApiError(422, 'invalid_code', 'An account with this email exists already. Sign in instead.', ['account' => 'existing']);
                }
                $codes->assertMaySignUp($email);
                $user = app(SignUp::class)->create(['name' => (string) $state['name'], 'email' => $email, 'password' => Str::password(40)],
                    $this->invitationForSignUp(), emailVerified: true, via: 'code', acceptedTerms: true);
                // The profile step showed the recording-consent words with the Terms.
                $user->forceFill(['consent_seen_at' => now()])->save();

                return $user;
            });
        } catch (ApiError $e) {
            $this->signUpToken = null;
            $this->step = self::STEP_EMAIL;
            Notification::make()->title(self::codeError($e))->url(NoticeTargets::forError($e))->danger()->send();

            return null;
        }
        $codes->countSignUp($ip);
        Filament::auth()->login($user);
        session()->regenerate();
        $this->redirect(GetTheApps::getUrl(panel: 'app'));

        return null;
    }

    /**
     * The words for an answer of EmailCodes (docs/API.md "Sign-up and sign-in with an email code") in the console's
     * language; the API's own words stay English. Each English line is the API's, word for word.
     */
    public static function codeError(ApiError $e): string
    {
        $extra = $e->extra();
        $left = $extra['attempts_left'] ?? null;

        return match ($e->apiCode()) {
            'invalid_code' => match (true) {
                is_int($left) && $left > 0 => trans_choice('That code is not right. :count try left.|That code is not right. :count tries left.', $left),
                is_int($left) => __('That code is not right. Ask for a new one.'),
                ($extra['account'] ?? null) === 'existing' => __('An account with this email exists already. Sign in instead.'),
                // EmailCodes::redeemSignUpToken ("This sign-up has expired. Start again with your email."), or any other
                // wording of the API's: its own words, in the console's language when they have a line.
                default => __($e->getMessage()),
            },
            'code_expired' => __('This code has expired. Ask for a new one.'),
            'resend_too_soon' => is_int($extra['resend_after'] ?? null)
                ? __('Wait :seconds seconds before asking for a new code.', ['seconds' => (string) $extra['resend_after']])
                : __($e->getMessage()),
            'too_many_attempts' => __('Too many codes asked for. Try again later.'),
            'turnstile_failed' => __('The check that you are not a robot did not pass. Try it again.'),
            'sign_up_closed' => __('Sign-up is closed on this server. Ask the owner for an invitation.'),
            'email_not_allowed' => __('This email address cannot be used to sign up. Use your usual email address.'),
            'sign_up_limit' => __('Too many new accounts from this network today. Try again tomorrow.'),
            default => __($e->getMessage()),
        };
    }

    /** Whether the Turnstile check shows with the email, on the first step: on the sign-up page (since v1.6.2). */
    protected function asksForTurnstileFirst(): bool
    {
        return false;
    }

    /**
     * The form, then (on the first step) "or" and the other ways in, side by side: Continue with Google and a passkey
     * (since v1.6.2; they were render hooks under the form).
     */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            RenderHook::make(PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE),
            $this->getFormContentComponent(),
            $this->getMultiFactorChallengeFormContentComponent(),
            View::make('filament.auth.other-ways')
                ->visible(fn (): bool => $this->step === self::STEP_EMAIL && blank($this->userUndertakingMultiFactorAuthentication)),
            RenderHook::make(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER),
        ]);
    }

    /** The invitation the sign-up page was opened with (the Register page has one). */
    protected function invitationForSignUp(): ?WorkspaceInvitation
    {
        return null;
    }

    private function signInAs(User $user): LoginResponse
    {
        if ($user->isSuspended()) {
            throw ValidationException::withMessages(['data.code' => __(self::SUSPENDED)]);
        }
        if (! $this->isUserAllowedToAccessPanel($user)) {
            throw ValidationException::withMessages(['data.code' => __('This account cannot use the console. Ask a workspace owner to add you.')]);
        }
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }
        app(SignInGuard::class)->clear((string) $this->confirmedEmail);
        Filament::auth()->login($user, (bool) ($this->data['remember'] ?? false));
        session()->regenerate();

        return app(LoginResponse::class);
    }

    /** "Use another email": back to the first step. */
    public function startOver(): void
    {
        $this->step = self::STEP_EMAIL;
        $this->existing = false;
        $this->signUpToken = null;
        $this->confirmedEmail = null;
        $this->userUndertakingMultiFactorAuthentication = null;
        $this->form->fill(['email' => $this->data['email'] ?? null]);
    }

    private function limited(int $perMinute, string $method = 'authenticate'): bool
    {
        try {
            $this->rateLimit($perMinute, method: $method);

            return true;
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return false;
        }
    }

    private function email(): string
    {
        return EmailCodes::normalize(is_string($this->data['email'] ?? null) ? $this->data['email'] : '');
    }

    public function form(Schema $schema): Schema
    {
        $turnstile = app(Turnstile::class);

        return $schema->components([
            $this->getEmailFormComponent(),
            $this->getPasswordFormComponent(),
            View::make('filament.auth.turnstile')->viewData(['siteKey' => $turnstile->siteKey()])
                ->visible(fn (): bool => $turnstile->active() && ($this->step === self::STEP_HUMAN || ($this->step === self::STEP_EMAIL && $this->asksForTurnstileFirst()))),
            Hidden::make('turnstile'),
            TextInput::make('code')->label(__('Code from the email'))->placeholder(__('6 digits'))
                ->required(fn (): bool => in_array($this->step, [self::STEP_CODE, self::STEP_OTP], true))
                ->autocomplete('one-time-code')->extraInputAttributes(['inputmode' => 'numeric', 'maxlength' => '9'])
                ->maxLength(9)->autofocus()
                ->visible(fn (): bool => $this->step === self::STEP_CODE),
            TextInput::make('otp')->label(__('Code from your authenticator app'))->helperText(__('Or one of your recovery codes.'))
                ->required(fn (): bool => $this->step === self::STEP_OTP)->autocomplete('one-time-code')->maxLength(64)
                ->visible(fn (): bool => $this->step === self::STEP_OTP),
            TextInput::make('name')->label(__('Your name'))->required(fn (): bool => $this->step === self::STEP_PROFILE)->maxLength(120)->autocomplete('name')
                ->visible(fn (): bool => $this->step === self::STEP_PROFILE),
            Text::make(__(RecordingConsent::TEXT))->visible(fn (): bool => $this->step === self::STEP_PROFILE),
            Checkbox::make('accept_terms')
                // One sentence; each link is made around its own words (escaped), the address never inside the words.
                ->label(new HtmlString(__('I accept the <terms>Terms</terms> and the <privacy>Privacy Policy</privacy>', [
                    'terms' => fn (string $words): string => '<a href="'.e(route('legal.terms')).'" target="_blank" class="underline">'.e($words).'</a>',
                    'privacy' => fn (string $words): string => '<a href="'.e(route('legal.privacy')).'" target="_blank" class="underline">'.e($words).'</a>',
                ])))
                ->accepted(fn (): bool => $this->step === self::STEP_PROFILE)
                ->validationMessages(['accepted' => __('Accept the Terms and the Privacy Policy to make your account.')])
                ->visible(fn (): bool => $this->step === self::STEP_PROFILE),
            $this->getRememberFormComponent(),
        ]);
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label(__('Email'))
            ->email()
            ->required()
            ->maxLength(255)
            // Since v1.6.2 a saved passkey is offered from the email box itself (WebAuthn conditional mediation).
            ->autocomplete('username webauthn')
            ->autofocus()
            ->readOnly(fn (): bool => $this->step !== self::STEP_EMAIL)
            ->hintAction(Action::make('startOver')->label(__('Use another email'))->action(fn () => $this->startOver())
                ->visible(fn (): bool => $this->step !== self::STEP_EMAIL));
    }

    protected function getPasswordFormComponent(): Component
    {
        /** @var TextInput $field */
        $field = parent::getPasswordFormComponent();

        return $field->visible(fn (): bool => $this->step === self::STEP_PASSWORD);
    }

    protected function getRememberFormComponent(): Component
    {
        return Checkbox::make('remember')->label(__('Keep me signed in'))
            ->visible(fn (): bool => in_array($this->step, [self::STEP_PASSWORD, self::STEP_CODE], true));
    }

    protected function getFormActions(): array
    {
        return [
            // The label follows the step (a closure: the actions are made once for the page).
            $this->getAuthenticateFormAction()->label(fn (): string => match ($this->step) {
                self::STEP_PASSWORD => __('Sign in'),
                self::STEP_HUMAN => __('Email me a code'),
                self::STEP_PROFILE => __('Create my account'),
                default => __('Continue'),
            }),
            Action::make('emailMeACode')->label(__('Email me a code instead'))->link()->color('gray')
                ->visible(fn (): bool => $this->step === self::STEP_PASSWORD)
                ->action(fn () => $this->emailCode()),
            Action::make('sendAgain')->label(__('Send a new code'))->link()->color('gray')
                ->visible(fn (): bool => $this->step === self::STEP_CODE)
                ->action(fn () => $this->emailCode()),
        ];
    }

    public function getHeading(): string|Htmlable|null
    {
        if (filled($this->userUndertakingMultiFactorAuthentication)) {
            return parent::getHeading();
        }

        return match ($this->step) {
            self::STEP_PASSWORD => __('Welcome back'),
            self::STEP_HUMAN => __('One quick check'),
            self::STEP_CODE => __('Check your email'),
            self::STEP_OTP => __('Two-step sign-in'),
            self::STEP_PROFILE => __('Create your account'),
            default => __('Sign in or create an account'),
        };
    }

    public function getSubheading(): string|Htmlable|null
    {
        if (filled($this->userUndertakingMultiFactorAuthentication)) {
            return parent::getSubheading();
        }

        return match ($this->step) {
            self::STEP_CODE => __('We sent a 6-digit code to :email. It works for 10 minutes.', ['email' => (string) $this->confirmedEmail]),
            self::STEP_HUMAN => __('Confirm you are not a robot, then we email you a code.'),
            self::STEP_OTP => __('Enter the code from your authenticator app.'),
            self::STEP_PROFILE => __('Last step: your name, and the Terms.'),
            self::STEP_PASSWORD => null,
            default => app(SignUp::class)->mode() === SignUp::ANYONE ? __('Enter your email. New here? We will make your free account.') : __('Enter your email to continue.'),
        };
    }
}
