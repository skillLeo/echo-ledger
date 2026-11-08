<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\DevicePlatform;
use App\Http\Controllers\Controller;
use App\Services\Accounts\DeviceSignIn;
use App\Services\Accounts\GoogleSignIn;
use App\Services\Accounts\SignUp;
use App\Services\DeviceConfigPayload;
use App\Support\ClientCountry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Signing in with an account (since v1.5, docs/API.md): the apps' two public sign-in routes. */
final class AuthController extends Controller
{
    public function __construct(private readonly DeviceSignIn $signIn, private readonly DeviceConfigPayload $config) {}

    /**
     * GET /api/auth/options — public, throttled like the site pages (since v1.5, Phase 1C): what a sign-in screen
     * offers on this server. Sign-up is offered only when anyone may make an account; the pages open in the browser.
     */
    public function options(SignUp $signUp, GoogleSignIn $google): JsonResponse
    {
        $signUpOpen = $signUp->mode() === SignUp::ANYONE;

        return response()->json([
            'password' => true,
            'google' => $google->configured(),
            'sign_up' => $signUpOpen,
            'sign_up_url' => $signUpOpen ? route('filament.admin.auth.register') : null,
            'reset_url' => route('filament.admin.auth.password-reset.request'),
            'browser_sign_in' => true,
        ]);
    }

    /** POST /api/auth/password — public, throttled (5/min/IP, and 5 failures per 15 minutes per email). */
    public function password(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:4096'],
            'otp' => ['nullable', 'string', 'max:64'],
            ...self::deviceRules(),
        ]);
        [$device, $token] = $this->signIn->withPassword($data, $request->ip(), ClientCountry::of($request));

        return DeviceController::created($device, $token, $this->config);
    }

    /** POST /api/auth/device/token — public, throttled: the browser sign-in's code and the PKCE verifier. */
    public function deviceToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:128'],
            // RFC 7636: 43 to 128 characters of A-Z a-z 0-9 - . _ ~
            'code_verifier' => ['required', 'string', 'regex:/^[A-Za-z0-9\-._~]{43,128}$/'],
            ...self::deviceRules(),
        ]);
        [$device, $token] = $this->signIn->withCode($data, $request->ip(), ClientCountry::of($request));

        return DeviceController::created($device, $token, $this->config);
    }

    /** @return array<string, list<mixed>> */
    private static function deviceRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'platform' => ['required', Rule::enum(DevicePlatform::class)],
            'app_version' => ['nullable', 'string', 'max:32'],
            'os_version' => ['nullable', 'string', 'max:32'],
        ];
    }
}
