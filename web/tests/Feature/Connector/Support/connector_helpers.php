<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

// Shared by the connector tests (v1.5): OAuth keys made once per test process, the OAuth flow over HTTP and tool calls.

/** A key pair for Passport, made once per test process: storage/ is left alone and no test needs `passport:keys`. */
function connectorKeys(): void
{
    static $keys = null;
    if ($keys === null) {
        $pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        expect($pair)->not->toBeFalse();
        openssl_pkey_export($pair, $private);
        $keys = ['private' => $private, 'public' => openssl_pkey_get_details($pair)['key']];
    }
    config(['passport.private_key' => $keys['private'], 'passport.public_key' => $keys['public']]);
}

const CONNECTOR_REDIRECT = 'https://claude.ai/api/mcp/auth_callback';

/** @return array{0: string, 1: string} the verifier and its S256 challenge */
function connectorPkce(): array
{
    $verifier = Str::random(64);

    return [$verifier, rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
}

/** Dynamic client registration, as Claude does it when a connector is added. */
function connectorRegister(object $test, string $name = 'Claude'): string
{
    return (string) $test->postJson('/oauth/register', ['client_name' => $name, 'redirect_uris' => [CONNECTOR_REDIRECT]])->assertCreated()->json('client_id');
}

/** @param array<string, string> $overrides */
function connectorAuthorizeUrl(string $clientId, string $challenge, array $overrides = []): string
{
    return '/oauth/authorize?'.http_build_query(array_filter([
        'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => CONNECTOR_REDIRECT, 'scope' => 'mcp:use', 'state' => 'state-1',
        'code_challenge' => $challenge, 'code_challenge_method' => 'S256', ...$overrides,
    ], fn (string $v): bool => $v !== ''));
}

/** The person opens the consent page and presses Allow: the one-time code the app gets back. */
function connectorAllow(object $test, User $person, string $clientId, string $challenge): string
{
    $page = $test->actingAs($person, 'web')->get(connectorAuthorizeUrl($clientId, $challenge))->assertOk();
    preg_match('/name="auth_token" value="([^"]+)"/', (string) $page->getContent(), $token);
    expect($token)->not->toBe([]);
    $location = (string) $test->post('/oauth/authorize', ['auth_token' => $token[1]])->assertRedirect()->headers->get('Location');
    expect($location)->toStartWith(CONNECTOR_REDIRECT.'?');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    expect($query['state'])->toBe('state-1');

    return (string) $query['code'];
}

/** @return TestResponse<Response> */
function connectorExchange(object $test, string $clientId, string $code, string $verifier): TestResponse
{
    return $test->post('/oauth/token', ['grant_type' => 'authorization_code', 'client_id' => $clientId, 'redirect_uri' => CONNECTOR_REDIRECT, 'code' => $code, 'code_verifier' => $verifier]);
}

/** @return TestResponse<Response> */
function connectorRefresh(object $test, string $clientId, string $refreshToken): TestResponse
{
    return $test->post('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $clientId, 'refresh_token' => $refreshToken]);
}

/**
 * The whole sign-in for a person: registration, consent, the code exchanged.
 *
 * @return array{client: string, access: string, refresh: string}
 */
function connectorConnect(object $test, User $person, string $name = 'Claude'): array
{
    $client = connectorRegister($test, $name);
    [$verifier, $challenge] = connectorPkce();
    $code = connectorAllow($test, $person, $client, $challenge);
    $tokens = connectorExchange($test, $client, $code, $verifier)->assertOk()->json();

    return ['client' => $client, 'access' => $tokens['access_token'], 'refresh' => $tokens['refresh_token']];
}

/**
 * One JSON-RPC message to /mcp with a person's token. The guards are forgotten first, as each real request starts
 * with none: a guard kept from the last call in this test would answer without looking at the token again.
 *
 * @param  array<string, mixed>  $params
 * @return TestResponse<Response>
 */
function connectorMessage(object $test, ?string $token, string $method, array $params = []): TestResponse
{
    app('auth')->forgetGuards();

    return $test->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], array_filter([
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => $token !== null ? "Bearer {$token}" : null,
    ]));
}

/**
 * A tool's answer: the text, and whether it is a tool error.
 *
 * @param  array<string, mixed>  $arguments
 * @return array{text: string, error: bool}
 */
function connectorTool(object $test, string $token, string $tool, array $arguments = []): array
{
    $result = connectorMessage($test, $token, 'tools/call', ['name' => $tool, 'arguments' => (object) $arguments])->assertOk()->json('result');

    return ['text' => (string) ($result['content'][0]['text'] ?? ''), 'error' => (bool) ($result['isError'] ?? false)];
}
