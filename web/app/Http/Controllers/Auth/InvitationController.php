<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Services\Accounts\Invitations;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accepting an invitation to a workspace (since v1.5). The link in the email is signed and works for 7 days, once,
 * and only for the invited address: signed in with it, the person confirms and joins; signed out, an address with an
 * account signs in first and one without makes its account on the sign-up page with the same invitation.
 */
final class InvitationController extends Controller
{
    public function __construct(private readonly Invitations $invitations) {}

    public function show(Request $request, string $token): Response
    {
        $invitation = $this->invitations->find($token);
        if (! $invitation instanceof WorkspaceInvitation) {
            return $this->message(410, 'This invitation is no longer valid', 'It was used already, it ended after 7 days, or a newer one replaced it. Ask for a new one.');
        }
        $user = Auth::guard('web')->user();
        if (! $user instanceof User) {
            if (User::query()->where('email', $invitation->email)->exists()) {
                return redirect()->guest(Filament::getPanel('app')->getLoginUrl() ?? '/app/login');
            }

            return redirect()->to($this->invitations->signUpUrl($token));
        }
        if (! $this->invitations->matches($invitation, $user->email)) {
            return $this->message(403, 'This invitation is for another address', "Sign in as {$invitation->email} to accept it.", url('/app'), 'Open the console');
        }

        return response()->view('auth.invitation', [
            'workspace' => $invitation->workspace->name, 'role' => $invitation->role->label(), 'email' => $user->email,
            'action' => $request->fullUrl(),
        ]);
    }

    public function accept(Request $request, string $token): Response
    {
        $invitation = $this->invitations->find($token);
        /** @var User $user */
        $user = $request->user('web');
        if (! $invitation instanceof WorkspaceInvitation || ! $this->invitations->matches($invitation, $user->email)) {
            return $this->message(410, 'This invitation is no longer valid', 'It was used already, it ended after 7 days, or it is for another address.');
        }
        try {
            $this->invitations->accept($invitation, $user);
        } catch (ApiError $e) {
            return $this->message(403, 'This workspace is full', $e->getMessage(), url('/app'), 'Open the console');
        }

        return redirect()->to(Filament::getPanel('app')->getUrl() ?? '/app');
    }

    private function message(int $status, string $title, string $message, ?string $back = null, ?string $backLabel = null): Response
    {
        return response()->view('auth.message', ['title' => $title, 'message' => $message, 'back' => $back, 'backLabel' => $backLabel], $status);
    }
}
