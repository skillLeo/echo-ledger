<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\DevicePlatform;
use App\Http\Controllers\Controller;
use App\Services\Accounts\DeviceSignIn;
use App\Services\Accounts\EmailCodes;
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
    /** `install_id` (since v1.6, docs/API.md): opaque, 8 to 64 of A-Z a-z 0-9 and "-". */
    public const INSTALL_ID_RULE = ['nullable', 'string', 'regex:/^[A-Za-z0-9-]{8,64}$/'];

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
            'sign_up_url' => $signUpOpen ? route('filament.app.auth.register') : null,
            'reset_url' => route('filament.app.auth.password-reset.request'),
            'browser_sign_in' => true,
            // Since v1.6: email codes, and the server's own address (the apps keep "Use a different server" under Advanced).
            'code' => true,
            'server' => rtrim((string) config('app.url'), '/'),
        ]);
    }

    /** POST /api/auth/start — public, throttled (10/min per IP, 5/min per email; since v1.6): what the address can do. */
    public function start(Request $request, EmailCodes $codes): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);

        return response()->json($codes->start($data['email']));
    }

    /** POST /api/auth/code — public, throttled (since v1.6): a 6-digit code by email. */
    public function code(Request $request, EmailCodes $codes): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'purpose' => ['nullable', 'string', Rule::in([EmailCodes::SIGN_IN, EmailCodes::SIGN_UP])],
            'turnstile' => ['nullable', 'string', 'max:2048'],
        ]);

        return response()->json($codes->send($data['email'], $data['turnstile'] ?? null, $request->ip()), 202);
    }

    /** POST /api/auth/code/verify — public, throttled (since v1.6): signs an account in, or gives a new address its sign-up token. */
    public function verifyCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:16'],
            'otp' => ['nullable', 'string', 'max:64'],
            ...self::deviceRules(),
        ]);
        $answer = $this->signIn->withEmailCode($data, $request->ip(), ClientCountry::of($request));
        if (isset($answer['sign_up_token'])) {
            return response()->json(['next' => 'profile', 'sign_up_token' => $answer['sign_up_token']]);
        }
        [$device, $token] = $answer;

        return DeviceController::created($device, $token, $this->config);
    }

    /** POST /api/auth/sign-up — public, throttled (since v1.6): the account, its Free workspace and this device. */
    public function signUp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sign_up_token' => ['required', 'string', 'max:128'],
            'name' => ['required', 'string', 'max:120'],
            'accept_terms' => ['required', 'accepted'],
            'device_name' => ['nullable', 'string', 'max:120'],
            ...array_diff_key(self::deviceRules(), ['name' => true]),
        ]);
        [$device, $token] = $this->signIn->signUp($data, $request->ip(), ClientCountry::of($request));

        return DeviceController::created($device, $token, $this->config);
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
            // Since v1.6 (P3): the app's own id of this install (an opaque 8-64 characters: the phone sends a UUID, the
            // desktop 64 hex); the same one signs in again on its own row.
            'install_id' => self::INSTALL_ID_RULE,
        ];
    }
}
