<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Exceptions\ApiError;
use App\Filament\Pages\GetTheApps;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WorkspaceInviteLink;
use App\Services\Accounts\InviteLinks;
use App\Services\Accounts\SignUp;
use App\Support\Brand;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * An invite link's page (v1.8 P6.3): "Join Acme on EchoLedger", who invited you and as what. Signed in: Join, then
 * "Get the apps". Signed out: sign in (and come back here), or make an account with the link (the sign-up page with
 * `?join=`, which joins the team when the account is made, then "Get the apps"). A link that is not open any more
 * answers 410 with plain words.
 */
final class JoinController extends Controller
{
    public function __construct(private readonly InviteLinks $links) {}

    public function show(Request $request, string $token): Response
    {
        $link = $this->links->find($token);
        if (! $link instanceof WorkspaceInviteLink) {
            return $this->closed();
        }
        $user = Auth::guard('web')->user();
        if (! $user instanceof User) {
            // Signing in brings them back here.
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return response()->view('auth.join', [
            'brand' => Brand::name(),
            'workspace' => $link->workspace->name,
            'inviter' => $link->creator?->name,
            'role' => Str::lower($link->role->label()),
            'user' => $user instanceof User ? $user : null,
            'member' => $user instanceof User && $link->workspace->roleOf($user) !== null,
            'action' => route('join.accept', ['token' => $token]),
            'signIn' => Filament::getPanel('app')->getLoginUrl() ?? url('/app/login'),
            'signUp' => app(SignUp::class)->open() ? (Filament::getPanel('app')->getRegistrationUrl(['join' => $token]) ?? url('/app/register?join='.$token)) : null,
        ]);
    }

    public function accept(Request $request, string $token): Response
    {
        $link = $this->links->find($token);
        /** @var User $user */
        $user = $request->user('web');
        if (! $link instanceof WorkspaceInviteLink) {
            return $this->closed();
        }
        try {
            $this->links->join($link, $user);
        } catch (ApiError $e) {
            return $e->apiCode() === 'link_closed' ? $this->closed()
                : response()->view('auth.message', ['title' => 'This team is full', 'message' => $e->getMessage(), 'back' => url('/app'), 'backLabel' => 'Open the console'], 403);
        }

        return redirect()->to(GetTheApps::getUrl(panel: 'app'));
    }

    private function closed(): Response
    {
        return response()->view('auth.message', ['title' => 'This invite link is no longer open', 'message' => 'It ended, it was revoked, or as many people as it allowed have joined. Ask for a new one.',
            'back' => null, 'backLabel' => null], 410);
    }
}
