<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use App\Models\WorkspaceInvitation;
use App\Services\Accounts\Invitations;
use App\Services\Accounts\SignUp;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Locked;

/**
 * Sign-up on the console (since v1.5; since v1.6 the sign-in's own flow: email → code → name and Terms). Only when
 * Settings → "Who can sign up" allows it (404 while it is "nobody"); with "only people with an invitation" through an
 * invitation's link, for the address it was sent to (the emailed code proves it), or for an address with an invitation
 * waiting. `/sign-up` leads here.
 */
class Register extends Login
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

    public function content(Schema $schema): Schema
    {
        if (! $this->canSignUp()) {
            return $schema->components([
                Text::make('Sign-up on this server is by invitation. Ask the person who runs it, or a workspace owner, to invite you, then open the link in that email.'),
            ]);
        }

        return parent::content($schema);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Create your account';
    }

    public function getHeading(): string|Htmlable|null
    {
        return $this->step === self::STEP_EMAIL ? 'Create your free account' : parent::getHeading();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->step === self::STEP_EMAIL ? 'Enter your email: we send you a code to confirm it.' : parent::getSubheading();
    }

    protected function getEmailFormComponent(): Component
    {
        /** @var TextInput $field */
        $field = parent::getEmailFormComponent();

        return $field->readOnly(fn (): bool => $this->step !== self::STEP_EMAIL || $this->invitation() !== null);
    }

    /** The Cloudflare check shows with the email here, where the code is asked for (since v1.6.2). */
    protected function asksForTurnstileFirst(): bool
    {
        return true;
    }

    protected function invitationForSignUp(): ?WorkspaceInvitation
    {
        return $this->invitation();
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
