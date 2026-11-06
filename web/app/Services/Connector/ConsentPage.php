<?php

declare(strict_types=1);

namespace App\Services\Connector;

use App\Exceptions\ApiError;
use App\Http\Middleware\SecurityHeaders;
use App\Models\User;
use App\Services\Accounts\DeviceSignIn;
use App\Services\Workspaces\Workspaces;
use Laravel\Passport\Client as OAuthClient;
use Laravel\Passport\Scope;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Allow Claude to read your EchoLedger recordings?" (since v1.5): Passport's authorization view, shown after the
 * person signed in on the console (with two-step sign-in when it is on). It names the app as it registered, says in
 * plain words what it may read and where the browser goes next. An account that could not sign in a device (email not
 * confirmed, being deleted, in no workspace) gets the reason instead of the question.
 */
final class ConsentPage
{
    public function __construct(private readonly DeviceSignIn $signIn, private readonly Workspaces $workspaces) {}

    /** @param array<string, mixed> $parameters Passport's: client, user, scopes, request and authToken */
    public function __invoke(array $parameters): Response
    {
        /** @var OAuthClient $client */
        $client = $parameters['client'];
        /** @var User $user */
        $user = $parameters['user'];
        try {
            $this->signIn->assertCanSignIn($user);
        } catch (ApiError $e) {
            return response()->view('auth.message', ['title' => 'This account cannot connect an app', 'message' => $e->getMessage(), 'back' => url('/admin'), 'backLabel' => 'Open the console'], 403);
        }
        $origins = self::origins($client);
        /** @var list<Scope> $scopes */
        $scopes = $parameters['scopes'];

        $response = response()->view('auth.connector-consent', [
            'name' => mb_substr(trim((string) $client->name) ?: 'An app', 0, 80),
            'email' => $user->email,
            'workspace' => $this->workspaces->current($user)?->name,
            'scopes' => array_map(fn (Scope $scope): string => (string) $scope->description, $scopes),
            'hosts' => array_values(array_unique(array_map(fn (string $origin): string => (string) parse_url($origin, PHP_URL_HOST), $origins))),
            // Passport finds the request in the session by this token; nothing else of it goes through the form.
            'authToken' => (string) $parameters['authToken'],
        ]);
        // Allow and Cancel send the browser on to the app's registered address; the console's policy allows only this site.
        $response->headers->set('Content-Security-Policy', str_replace("form-action 'self'", trim("form-action 'self' ".implode(' ', $origins)), SecurityHeaders::CONSOLE_CSP));

        return $response;
    }

    /**
     * The origins of the app's registered redirect addresses (https, or http on this machine), for the form's policy.
     *
     * @return list<string>
     */
    private static function origins(OAuthClient $client): array
    {
        $origins = [];
        foreach ((array) $client->getAttribute('redirect_uris') as $uri) {
            $parts = parse_url((string) $uri);
            if (! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['https', 'http'], true) || ($parts['host'] ?? '') === '') {
                continue;
            }
            $origins[] = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        }

        return array_values(array_unique($origins));
    }
}
