<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AuditLog;
use App\Services\Passkeys;
use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * "Sign in with a passkey" on the console login page (plan B16). A verified passkey (with user verification) is
 * both factors, so the two-step code is not asked for. Both routes are throttled per IP.
 */
final class PasskeyLoginController extends Controller
{
    public function __construct(private readonly Passkeys $passkeys) {}

    /** POST /passkeys/login/options */
    public function options(): JsonResponse
    {
        return response()->json(json_decode($this->passkeys->loginOptions(), true, flags: JSON_THROW_ON_ERROR));
    }

    /** POST /passkeys/login */
    public function login(Request $request, AuditLog $audit): JsonResponse
    {
        $data = $request->validate(['credential' => ['required', 'array']]);
        $user = $this->passkeys->verifyLogin(json_encode($data['credential'], JSON_THROW_ON_ERROR));
        $panel = Filament::getPanel('admin');
        if ($user === null || ! $user->canAccessPanel($panel)) {
            $audit->record('login_failed', $user?->id, null, 'Sign-in with a passkey failed');

            return response()->json(['message' => 'That passkey could not sign you in. Try again or use your password.'], 422);
        }
        $request->session()->put('audit.passkey_login', true);
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $audit->record('passkey_login', $user->id);

        return response()->json(['redirect' => $panel->getUrl()]);
    }
}
