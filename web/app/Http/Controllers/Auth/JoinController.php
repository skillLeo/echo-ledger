<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Exceptions\ApiError;
use App\Filament\Pages\GetTheApps;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WorkspaceInviteLink;
use App\Services\Accounts\InviteLinks;
use App\Services\Accounts\JoinSession;
use App\Services\Accounts\SignUp;
use App\Support\Brand;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * An invite link's page (v1.8 P6.3): "Join Acme on EchoLedger", who invited you and as what. Opening /join/{token}
 * keeps the link in the session, encrypted (JoinSession), and every step after uses that instead of the token: the
 * page again at /join (where signing in comes back to), Join (POST /join), then "Get the apps"; or "Create your
 * account" (the sign-up page, which joins the team when the account is made). No page here sends a referrer. A link
 * that is not open any more answers 410 with plain words.
 */
final class JoinController extends Controller
{
    public function __construct(private readonly InviteLinks $links) {}

    /** GET /join/{token}: the link opened; kept in the session from here on. */
    public function show(Request $request, string $token): Response
    {
        JoinSession::put($token);

        return $this->page($request, $token);
    }

    /** GET /join: the same page, from the session (signing in comes back here). */
    public function resume(Request $request): Response
    {
        return $this->page($request, JoinSession::token());
    }

    /** POST /join: join with the link of the session, then the apps. */
    public function accept(Request $request): Response
    {
        $link = $this->links->find(JoinSession::token());
        /** @var User $user */
        $user = $request->user('web');
        if (! $link instanceof WorkspaceInviteLink) {
            return $this->closed();
        }
        try {
            $this->links->join($link, $user);
        } catch (ApiError $e) {
            return $e->apiCode() === 'link_closed' ? $this->closed()
                : self::private(response()->view('auth.message', ['title' => 'This team is full', 'message' => $e->getMessage(), 'back' => url('/app'), 'backLabel' => 'Open the console'], 403));
        }
        JoinSession::forget();

        return self::private(redirect()->to(GetTheApps::getUrl(panel: 'app')));
    }

    private function page(Request $request, ?string $token): Response
    {
        $link = $this->links->find($token);
        if (! $link instanceof WorkspaceInviteLink) {
            return $this->closed();
        }
        $user = Auth::guard('web')->user();
        if (! $user instanceof User) {
            // Signing in brings them back to /join, which reads the link from the session.
            $request->session()->put('url.intended', route('join.resume'));
        }

        return self::private(response()->view('auth.join', [
            'brand' => Brand::name(),
            'workspace' => $link->workspace->name,
            'inviter' => $link->creator?->name,
            'role' => Str::lower($link->role->label()),
            'user' => $user instanceof User ? $user : null,
            'member' => $user instanceof User && $link->workspace->roleOf($user) !== null,
            'action' => route('join.accept'),
            'signIn' => Filament::getPanel('app')->getLoginUrl() ?? url('/app/login'),
            'signUp' => app(SignUp::class)->open() ? (Filament::getPanel('app')->getRegistrationUrl() ?? url('/app/register')) : null,
        ]));
    }

    private function closed(): Response
    {
        return self::private(response()->view('auth.message', ['title' => 'This invite link is no longer open', 'message' => InviteLinks::CLOSED,
            'back' => null, 'backLabel' => null], 410));
    }

    /** No referrer leaves these pages (their address carries the link's token). */
    public static function private(Response $response): Response
    {
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
