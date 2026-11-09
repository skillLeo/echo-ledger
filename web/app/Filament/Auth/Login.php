<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use App\Exceptions\ApiError;
use App\Filament\Pages\GetTheApps;
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
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
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
            Notification::make()->title('This account is suspended')->body(self::SUSPENDED)->danger()->persistent()->send();
        }
    }

    /** What a suspended account is told when it tries to sign in (since v1.6). */
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
            throw ValidationException::withMessages(['data.email' => self::SUSPENDED]);
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
            throw ValidationException::withMessages(['data.email' => $e->getMessage()]);
        }
        if ($answer['account'] === 'existing' && User::query()->where('email', $email)->whereNotNull('suspended_at')->exists()) {
            throw ValidationException::withMessages(['data.email' => self::SUSPENDED]);
        }
        $this->confirmedEmail = $email;
        $this->data['email'] = $email;
        $this->existing = $answer['account'] === 'existing';
        if ($this->existing && in_array('password', $answer['methods'], true)) {
            $this->step = self::STEP_PASSWORD;

            return null;
        }
        if (! $this->existing && app(Turnstile::class)->configured()) {
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
            Notification::make()->title($e->getMessage())->danger()->send();

            return null;
        }
        $this->data['code'] = null;
        $this->step = self::STEP_CODE;
        Notification::make()->title('Code sent')->body("Check {$this->confirmedEmail} for a 6-digit code.")->success()->send();

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
            throw ValidationException::withMessages(['data.code' => $e->getMessage()]);
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
            Notification::make()->title('Too many failed sign-ins. Try again later.')->danger()->send();

            return null;
        }
        $codes = app(EmailCodes::class);
        try {
            $row = $codes->check($this->confirmedEmail, (string) ($this->data['code'] ?? ''));
        } catch (ApiError $e) {
            $this->step = self::STEP_CODE;

            throw ValidationException::withMessages(['data.code' => $e->getMessage()]);
        }
        $user = User::query()->where('email', $this->confirmedEmail)->firstOrFail();
        if (! app(TwoFactorCodes::class)->verify($user, trim((string) ($this->data['otp'] ?? '')))) {
            $guard->fail($this->confirmedEmail, $user->id);

            throw ValidationException::withMessages(['data.otp' => 'That code is wrong or was used already.']);
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
                    throw new ApiError(422, 'invalid_code', 'An account with this email exists already. Sign in instead.');
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
            Notification::make()->title($e->getMessage())->danger()->send();

            return null;
        }
        $codes->countSignUp($ip);
        Filament::auth()->login($user);
        session()->regenerate();
        $this->redirect(GetTheApps::getUrl(panel: 'app'));

        return null;
    }

    /** The invitation the sign-up page was opened with (the Register page has one). */
    protected function invitationForSignUp(): ?WorkspaceInvitation
    {
        return null;
    }

    private function signInAs(User $user): LoginResponse
    {
        if ($user->isSuspended()) {
            throw ValidationException::withMessages(['data.code' => self::SUSPENDED]);
        }
        if (! $this->isUserAllowedToAccessPanel($user)) {
            throw ValidationException::withMessages(['data.code' => 'This account cannot use the console. Ask a workspace owner to add you.']);
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
                ->visible(fn (): bool => $this->step === self::STEP_HUMAN && $turnstile->configured()),
            Hidden::make('turnstile'),
            TextInput::make('code')->label('Code from the email')->placeholder('6 digits')
                ->required(fn (): bool => in_array($this->step, [self::STEP_CODE, self::STEP_OTP], true))
                ->autocomplete('one-time-code')->extraInputAttributes(['inputmode' => 'numeric', 'maxlength' => '9'])
                ->maxLength(9)->autofocus()
                ->visible(fn (): bool => $this->step === self::STEP_CODE),
            TextInput::make('otp')->label('Code from your authenticator app')->helperText('Or one of your recovery codes.')
                ->required(fn (): bool => $this->step === self::STEP_OTP)->autocomplete('one-time-code')->maxLength(64)
                ->visible(fn (): bool => $this->step === self::STEP_OTP),
            TextInput::make('name')->label('Your name')->required(fn (): bool => $this->step === self::STEP_PROFILE)->maxLength(120)->autocomplete('name')
                ->visible(fn (): bool => $this->step === self::STEP_PROFILE),
            Text::make(RecordingConsent::TEXT)->visible(fn (): bool => $this->step === self::STEP_PROFILE),
            Checkbox::make('accept_terms')
                ->label(new HtmlString('I accept the <a href="'.e(route('legal.terms')).'" target="_blank" class="underline">Terms</a> and the <a href="'.e(route('legal.privacy')).'" target="_blank" class="underline">Privacy Policy</a>'))
                ->accepted(fn (): bool => $this->step === self::STEP_PROFILE)
                ->validationMessages(['accepted' => 'Accept the Terms and the Privacy Policy to make your account.'])
                ->visible(fn (): bool => $this->step === self::STEP_PROFILE),
            $this->getRememberFormComponent(),
        ]);
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label('Email')
            ->email()
            ->required()
            ->maxLength(255)
            ->autocomplete('email')
            ->autofocus()
            ->readOnly(fn (): bool => $this->step !== self::STEP_EMAIL)
            ->hintAction(Action::make('startOver')->label('Use another email')->action(fn () => $this->startOver())
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
        return Checkbox::make('remember')->label('Keep me signed in')
            ->visible(fn (): bool => in_array($this->step, [self::STEP_PASSWORD, self::STEP_CODE], true));
    }

    protected function getFormActions(): array
    {
        $label = match ($this->step) {
            self::STEP_PASSWORD => 'Sign in',
            self::STEP_HUMAN => 'Email me a code',
            self::STEP_PROFILE => 'Create my account',
            default => 'Continue',
        };

        return [
            $this->getAuthenticateFormAction()->label($label),
            Action::make('emailMeACode')->label('Email me a code instead')->link()->color('gray')
                ->visible(fn (): bool => $this->step === self::STEP_PASSWORD)
                ->action(fn () => $this->emailCode()),
            Action::make('sendAgain')->label('Send a new code')->link()->color('gray')
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
            self::STEP_PASSWORD => 'Welcome back',
            self::STEP_HUMAN => 'One quick check',
            self::STEP_CODE => 'Check your email',
            self::STEP_OTP => 'Two-step sign-in',
            self::STEP_PROFILE => 'Create your account',
            default => 'Sign in or create an account',
        };
    }

    public function getSubheading(): string|Htmlable|null
    {
        if (filled($this->userUndertakingMultiFactorAuthentication)) {
            return parent::getSubheading();
        }

        return match ($this->step) {
            self::STEP_CODE => 'We sent a 6-digit code to '.$this->confirmedEmail.'. It works for 10 minutes.',
            self::STEP_HUMAN => 'Confirm you are not a robot, then we email you a code.',
            self::STEP_OTP => 'Enter the code from your authenticator app.',
            self::STEP_PROFILE => 'Last step: your name, and the Terms.',
            self::STEP_PASSWORD => null,
            default => app(SignUp::class)->mode() === SignUp::ANYONE ? 'Enter your email. New here? We will make your free account.' : 'Enter your email to continue.',
        };
    }
}
