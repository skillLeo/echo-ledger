<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\DevicePlatform;
use App\Exceptions\ApiError;
use App\Http\Controllers\Controller;
use App\Http\Middleware\SecurityHeaders;
use App\Models\User;
use App\Services\Accounts\DeviceSignIn;
use App\Services\Workspaces\Workspaces;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

/**
 * The apps' browser sign-in (since v1.5, docs/API.md "Signing in with an account"): the app opens /device/sign-in
 * with a PKCE S256 challenge, its state, its redirect and its name. The person signs in on the console's own page
 * (password and two-step code, a passkey or Google), approves "Sign in EchoLedger on <name>?", and the browser goes on
 * to echoledger://signed-in with a one-time code and the same state. Nothing is sent anywhere else: a link with any
 * other redirect, method or a malformed challenge is refused here, never redirected.
 */
final class DeviceSignInController extends Controller
{
    public function __construct(private readonly DeviceSignIn $signIn) {}

    public function show(Request $request): Response
    {
        $params = $this->params($request->query());
        if ($params === null) {
            return $this->message(400, 'This sign-in link is not valid', 'Start the sign-in again from the EchoLedger app.');
        }
        $user = Auth::guard('web')->user();
        if (! $user instanceof User) {
            // The console's sign-in page brings the browser back here afterwards.
            return redirect()->guest(Filament::getPanel('admin')->getLoginUrl() ?? '/admin/login');
        }
        try {
            $this->signIn->assertCanSignIn($user);
        } catch (ApiError $e) {
            return $this->message(403, 'This account cannot sign in a device', $e->getMessage(), url('/admin'), 'Open the console');
        }

        $response = response()->view('auth.device-sign-in', [
            'params' => $params,
            'platform' => DevicePlatform::from($params['platform'])->getLabel(),
            'email' => $user->email,
            'workspace' => app(Workspaces::class)->current($user)?->name,
        ]);
        // The approval form sends the browser on to the app's own link; the console's policy allows only this site.
        $response->headers->set('Content-Security-Policy', str_replace("form-action 'self'", "form-action 'self' echoledger:", SecurityHeaders::CONSOLE_CSP));

        return $response;
    }

    public function approve(Request $request): Response
    {
        $params = $this->params($request->post());
        if ($params === null) {
            return $this->message(400, 'This sign-in link is not valid', 'Start the sign-in again from the EchoLedger app.');
        }
        /** @var User $user */
        $user = $request->user('web');
        if ($request->boolean('cancel')) {
            return redirect()->away(DeviceSignIn::REDIRECT_URI.'?'.http_build_query(['error' => 'access_denied', 'state' => $params['state']]));
        }
        try {
            $code = $this->signIn->approve($user, $params);
        } catch (ApiError $e) {
            return $this->message(403, 'This device cannot be signed in', $e->getMessage(), url('/admin'), 'Open the console');
        }

        return redirect()->away(DeviceSignIn::REDIRECT_URI.'?'.http_build_query(['code' => $code, 'state' => $params['state']]));
    }

    /**
     * @param  array<mixed>  $input
     * @return array{code_challenge: string, code_challenge_method: string, state: string, redirect_uri: string, name: string, platform: string}|null
     */
    private function params(array $input): ?array
    {
        $validator = Validator::make($input, [
            // BASE64URL(SHA-256(verifier)) is 43 characters without padding.
            'code_challenge' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/'],
            'code_challenge_method' => ['required', 'string', 'in:S256'],
            'state' => ['required', 'string', 'max:255', 'regex:/^[\x21-\x7E]+$/'],
            'redirect_uri' => ['required', 'string', 'in:'.DeviceSignIn::REDIRECT_URI],
            'name' => ['required', 'string', 'max:120'],
            'platform' => ['required', 'string', 'in:'.implode(',', array_column(DevicePlatform::cases(), 'value'))],
        ]);
        if ($validator->fails()) {
            return null;
        }
        /** @var array{code_challenge: string, code_challenge_method: string, state: string, redirect_uri: string, name: string, platform: string} $valid */
        $valid = $validator->validated();

        return $valid;
    }

    private function message(int $status, string $title, string $message, ?string $back = null, ?string $backLabel = null): Response
    {
        return response()->view('auth.message', ['title' => $title, 'message' => $message, 'back' => $back, 'backLabel' => $backLabel], $status);
    }
}
