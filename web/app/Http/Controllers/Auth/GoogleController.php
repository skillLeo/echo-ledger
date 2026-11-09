<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Filament\Auth\EditProfile;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Accounts\GoogleSignIn;
use App\Services\Accounts\SignInGuard;
use App\Services\Accounts\SignUp;
use App\Services\Accounts\TwoFactorCodes;
use App\Services\AuditLog;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Two\AbstractProvider;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * "Sign in with Google" on the console (since v1.5). A Google account already linked signs in (with the two-step code
 * when the account has two-step sign-in: Google stands in for the password, never for the second step). A verified
 * Google address that matches an account not linked yet links only after that account's password, or its two-step
 * code, is given here: never silently. A new address makes an account only while sign-up allows it. From the
 * profile, a signed-in person links the Google account they choose; on /account/delete, an account linked to Google
 * may confirm with it instead of a password (one made with Google has none it knows).
 */
final class GoogleController extends Controller
{
    /** Minutes a "confirm it is you" step waits after Google answered. */
    private const PENDING_MINUTES = 10;

    public function __construct(
        private readonly GoogleSignIn $google,
        private readonly AuditLog $audit,
        private readonly SignInGuard $guard,
        private readonly TwoFactorCodes $codes,
    ) {}

    public function redirect(Request $request): Response
    {
        abort_unless($this->google->configured(), 404);
        $signedIn = Auth::guard('web')->check();
        $intent = match (true) {
            $signedIn && $request->query('intent') === 'link' => 'link',
            $signedIn && $request->query('intent') === 'confirm' => 'confirm',
            default => 'sign-in',
        };
        if ($intent === 'link' && $this->unconfirmed(Auth::guard('web')->user())) {
            return $this->notLinked();
        }
        $request->session()->put('google.intent', $intent);
        $provider = $this->google->provider();
        // Confirming it is you: Google asks which account, rather than taking whichever is open in the browser.
        if ($intent === 'confirm' && $provider instanceof AbstractProvider) {
            $provider->with(['prompt' => 'select_account']);
        }

        return $provider->redirect();
    }

    public function callback(Request $request): Response
    {
        abort_unless($this->google->configured(), 404);
        $intent = $request->session()->pull('google.intent', 'sign-in');
        $signedIn = Auth::guard('web')->user();
        $confirming = $intent === 'confirm' && $signedIn instanceof User;
        if ($request->query('error') !== null) {
            return $confirming ? $this->backToDelete('Google did not confirm it. Nothing changed.')
                : $this->message('Google sign-in was cancelled', 'Nothing changed. You can sign in with your password or try Google again.');
        }
        try {
            $profile = $this->google->profile($this->google->provider()->user());
        } catch (Throwable) {
            return $confirming ? $this->backToDelete('Google did not confirm it. Try again.')
                : $this->message('Google sign-in did not finish', 'Google did not confirm the sign-in. Try again from the sign-in page.');
        }
        if ($profile['id'] === '') {
            return $confirming ? $this->backToDelete('Google did not say which account it was. Try again.')
                : $this->message('Google sign-in did not finish', 'Google did not say which account signed in. Try again.');
        }

        if ($intent === 'link' && $signedIn instanceof User) {
            return $this->unconfirmed($signedIn) ? $this->notLinked() : $this->linkSignedIn($signedIn, $profile['id']);
        }
        if ($confirming) {
            return $this->confirmSignedIn($request, $signedIn, $profile['id']);
        }

        $linked = User::query()->where('google_id', $profile['id'])->first();
        if ($linked instanceof User) {
            return $linked->hasTwoFactor() ? $this->pending($request, $linked, $profile, link: false) : $this->signIn($request, $linked);
        }
        if (! $profile['verified'] || $profile['email'] === '') {
            return $this->message('That Google address is not verified', 'Google has not confirmed this address, so it cannot sign in here. Sign in with your password instead.');
        }
        $existing = User::query()->where('email', $profile['email'])->first();
        if ($existing instanceof User) {
            return $this->pending($request, $existing, $profile, link: true);
        }
        $signUp = app(SignUp::class);
        if (! $signUp->allows($profile['email'])) {
            return $this->message('No account uses this address', 'Sign-up on this server is closed or by invitation. Ask the person who runs it to add you.');
        }
        $user = $signUp->create(['name' => $profile['name'], 'email' => $profile['email'], 'password' => Str::password(40), 'google_id' => $profile['id']], emailVerified: true, via: 'google');

        return $this->signIn($request, $user);
    }

    public function confirmForm(Request $request): View|RedirectResponse
    {
        $pending = $this->pendingUser($request);
        if ($pending === null) {
            return redirect()->to(Filament::getPanel('app')->getLoginUrl() ?? '/app/login');
        }
        [$user, $state] = $pending;

        return view('auth.google-confirm', ['email' => $user->email, 'link' => (bool) $state['link'], 'twoFactor' => $user->hasTwoFactor()]);
    }

    public function confirm(Request $request): View|Response
    {
        $pending = $this->pendingUser($request);
        if ($pending === null) {
            return redirect()->to(Filament::getPanel('app')->getLoginUrl() ?? '/app/login');
        }
        [$user, $state] = $pending;
        $data = $request->validate(['password' => ['nullable', 'string', 'max:4096'], 'code' => ['nullable', 'string', 'max:64']]);
        if ($this->guard->lockedFor($user->email) > 0) {
            return $this->confirmAgain($user, $state, 'Too many tries. Wait a few minutes, then try again.');
        }
        // With two-step sign-in the code is the proof (Google already stood in for the password); without it, the password.
        $ok = $user->hasTwoFactor()
            ? filled($data['code'] ?? null) && $this->codes->verify($user, (string) $data['code'])
            : (bool) $state['link'] && filled($data['password'] ?? null) && Hash::check((string) $data['password'], $user->password);
        if (! $ok) {
            $this->guard->fail($user->email, $user->id);
            $this->audit->record('login_failed', $user->id, null, 'Google sign-in not confirmed for '.Str::limit($user->email, 120, ''));

            return $this->confirmAgain($user, $state, $user->hasTwoFactor() ? 'That code is wrong or was used already.' : 'That password is wrong.');
        }
        $this->guard->clear($user->email);
        $request->session()->forget('google.pending');
        if ($state['link']) {
            if (User::query()->where('google_id', $state['google_id'])->whereKeyNot($user->id)->exists()) {
                return $this->message('That Google account is linked elsewhere', 'It already signs in to another EchoLedger account.');
            }
            // Google verified the address and its owner proved the account is theirs: the address is confirmed too.
            $user->forceFill(['google_id' => $state['google_id'], 'email_verified_at' => $user->email_verified_at ?? now()])->save();
            $this->audit->record('google_linked', $user->id, $user, 'Google account linked at sign-in');
        }

        return $this->signIn($request, $user);
    }

    /**
     * Whoever signed up with an address they never confirmed may not be its owner: a Google account linked now would
     * still sign in after the real owner took the address over with "Forgot password?".
     */
    private function unconfirmed(mixed $user): bool
    {
        return $user instanceof User && ! $user->hasVerifiedEmail();
    }

    private function notLinked(): Response
    {
        return $this->message('Confirm your email first', 'A Google account can be linked once the email of this account is confirmed. Nothing changed.');
    }

    private function linkSignedIn(User $user, string $googleId): RedirectResponse
    {
        $profile = EditProfile::settingsUrl('security');
        if (User::query()->where('google_id', $googleId)->whereKeyNot($user->id)->exists()) {
            session()->flash('google.status', 'That Google account already signs in to another EchoLedger account.');

            return redirect()->to($profile);
        }
        $user->forceFill(['google_id' => $googleId])->save();
        $this->audit->record('google_linked', $user->id, $user);
        session()->flash('google.status', 'Google account linked. You can now sign in with Google.');

        return redirect()->to($profile);
    }

    /** "Confirm with Google" on /account/delete: only the Google account linked to the signed-in account counts. */
    private function confirmSignedIn(Request $request, User $user, string $googleId): RedirectResponse
    {
        if ($user->google_id === null || ! hash_equals($user->google_id, $googleId)) {
            $this->audit->record('login_failed', $user->id, null, 'Confirm with Google: another Google account was chosen');

            return $this->backToDelete('That is not the Google account linked to this account. Choose the linked one, or use your password.');
        }
        GoogleSignIn::markConfirmed($request->session(), $user);
        $this->audit->record('google_confirmed', $user->id, $user, 'Confirmed with Google to delete the account');

        return redirect()->route('account.delete');
    }

    private function backToDelete(string $error): RedirectResponse
    {
        return redirect()->route('account.delete')->with('account.delete.error', $error);
    }

    /** @param  array{id: string, email: string, name: string, verified: bool}  $profile */
    private function pending(Request $request, User $user, array $profile, bool $link): RedirectResponse
    {
        $request->session()->put('google.pending', ['user_id' => $user->id, 'google_id' => $profile['id'], 'link' => $link, 'until' => now()->addMinutes(self::PENDING_MINUTES)->getTimestamp()]);

        return redirect()->route('auth.google.confirm');
    }

    /** @return array{0: User, 1: array{user_id: int, google_id: string, link: bool, until: int}}|null */
    private function pendingUser(Request $request): ?array
    {
        $state = $request->session()->get('google.pending');
        if (! is_array($state) || (int) ($state['until'] ?? 0) < now()->getTimestamp()) {
            $request->session()->forget('google.pending');

            return null;
        }
        $user = User::query()->find((int) $state['user_id']);

        return $user instanceof User ? [$user, $state] : null;
    }

    /** @param  array<string, mixed>  $state */
    private function confirmAgain(User $user, array $state, string $error): View
    {
        return view('auth.google-confirm', ['email' => $user->email, 'link' => (bool) $state['link'], 'twoFactor' => $user->hasTwoFactor(), 'error' => $error]);
    }

    private function signIn(Request $request, User $user): Response
    {
        if (! $user->canAccessPanel(Filament::getPanel('app'))) {
            return $this->message('This account cannot open the console', 'It belongs to no workspace. Ask a workspace owner to add you.');
        }
        $request->session()->put('audit.google_login', true);
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $this->audit->record('google_login', $user->id, $user);

        return redirect()->intended(Filament::getPanel('app')->getUrl() ?? '/app');
    }

    private function message(string $title, string $message): Response
    {
        return response()->view('auth.message', ['title' => $title, 'message' => $message, 'back' => Filament::getPanel('app')->getLoginUrl()], 422);
    }
}
