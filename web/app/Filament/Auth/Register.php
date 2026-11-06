<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Services\Accounts\Invitations;
use App\Services\Accounts\SignUp;
use Filament\Auth\Http\Responses\Contracts\RegistrationResponse;
use Filament\Auth\Pages\Register as BaseRegister;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use SensitiveParameter;

/**
 * Sign-up on the console (since v1.5): only when Settings → "Who can sign up" allows it (404 while it is "nobody"),
 * and with "only people with an invitation" only through an invitation's link, for the address it was sent to.
 * Passwords follow Password::defaults() (12 characters with letters and numbers). The account starts unconfirmed and
 * gets the confirmation email, unless the invitation link proved the address.
 */
class Register extends BaseRegister
{
    /** The invitation token from the link (`?invite=…`); the browser cannot change it. */
    #[Locked]
    public ?string $invite = null;

    public function mount(): void
    {
        abort_unless(app(SignUp::class)->open(), 404);
        $token = request()->query('invite');
        $this->invite = is_string($token) ? mb_substr($token, 0, 128) : null;

        parent::mount();

        $invitation = $this->invitation();
        if ($invitation !== null) {
            $this->form->fill(['email' => $invitation->email]);
        }
    }

    public function register(): ?RegistrationResponse
    {
        abort_unless(app(SignUp::class)->open(), 404);
        if (! $this->canSignUp()) {
            Notification::make()->title('Sign-up here is by invitation')->body('Open the link in your invitation email.')->danger()->send();

            return null;
        }

        return parent::register();
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRegistration(#[SensitiveParameter] array $data): Model
    {
        $invitation = $this->invitation();
        $email = Str::lower(trim((string) $data['email']));
        if (! app(SignUp::class)->allows($email, $invitation)) {
            throw ValidationException::withMessages(['data.email' => $invitation !== null ? 'This invitation is for another email address.' : 'Sign-up here is by invitation.']);
        }

        return app(SignUp::class)->create(['name' => (string) $data['name'], 'email' => $email, 'password' => (string) $data['password']], $invitation);
    }

    protected function sendEmailVerificationNotification(Model $user): void
    {
        if ($user instanceof User && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }
    }

    public function content(Schema $schema): Schema
    {
        if (! $this->canSignUp()) {
            return $schema->components([
                Text::make('Sign-up on this server is by invitation. Ask the person who runs it, or a workspace owner, to invite you, then open the link in that email.'),
            ]);
        }

        return parent::content($schema);
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label(__('filament-panels::auth/pages/register.form.email.label'))
            ->email()
            ->required()
            ->maxLength(255)
            ->readOnly(fn (): bool => $this->invitation() !== null)
            // Addresses are stored in lower case; "Name@Example.com" is the same account as "name@example.com".
            ->rule(static fn (): \Closure => static function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && User::query()->where('email', Str::lower(trim($value)))->exists()) {
                    $fail('An account with this email already exists. Sign in instead.');
                }
            });
    }

    private function canSignUp(): bool
    {
        $signUp = app(SignUp::class);

        return $signUp->mode() === SignUp::ANYONE || ($signUp->open() && $this->invitation() !== null);
    }

    private function invitation(): ?WorkspaceInvitation
    {
        return app(Invitations::class)->find($this->invite);
    }
}
