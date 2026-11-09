<?php

declare(strict_types=1);

use App\Filament\Auth\Login;
use App\Models\AuditEvent;
use App\Models\ConnectedApp;
use App\Services\Connector\ConnectedApps;
use Carbon\CarbonInterval;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Passport\Passport;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

require_once __DIR__.'/Support/connector_helpers.php';

/*
 * docs/API.md "Claude and ChatGPT connector": OAuth 2.1 through Passport with only what the connector needs, over real
 * HTTP from registration to a tool call.
 */

beforeEach(function () {
    connectorKeys();
    Mail::fake();
    $this->person = Fx::user(['name' => 'Hassam', 'email' => 'hassam@example.test']);
});

describe('discovery and registration', function () {
    it('tells an app where to sign in, with S256 and the one scope only', function () {
        $this->getJson('/.well-known/oauth-protected-resource/mcp')->assertOk()->assertExactJson([
            'resource' => url('/mcp'), 'authorization_servers' => [url('/')], 'scopes_supported' => ['mcp:use'],
        ]);
        $this->getJson('/.well-known/oauth-protected-resource')->assertOk()->assertJsonPath('scopes_supported', ['mcp:use']);

        $server = $this->getJson('/.well-known/oauth-authorization-server')->assertOk();
        expect($server->json('authorization_endpoint'))->toBe(url('/oauth/authorize'))
            ->and($server->json('token_endpoint'))->toBe(url('/oauth/token'))
            ->and($server->json('registration_endpoint'))->toBe(url('/oauth/register'))
            ->and($server->json('code_challenge_methods_supported'))->toBe(['S256'])
            ->and($server->json('scopes_supported'))->toBe(['mcp:use'])
            ->and($server->json('grant_types_supported'))->toBe(['authorization_code', 'refresh_token']);
    });

    it('answers a missing or wrong token with 401 and where to find the sign-in', function () {
        $response = connectorMessage($this, null, 'tools/list')->assertUnauthorized();
        expect($response->headers->get('WWW-Authenticate'))->toContain('resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp').'"');

        connectorMessage($this, 'not-a-token', 'tools/list')->assertUnauthorized();
        // A browser opening the address gets no sign-in page either: this is no page.
        app('auth')->forgetGuards();
        $this->post('/mcp', [], ['Authorization' => 'Bearer nope'])->assertUnauthorized();
    });

    it('registers an app as a public client with only the code and refresh grants', function () {
        $response = $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [CONNECTOR_REDIRECT]])->assertCreated();

        expect($response->json('token_endpoint_auth_method'))->toBe('none')
            ->and($response->json('scope'))->toBe('mcp:use')
            ->and($response->json('grant_types'))->toBe(['authorization_code', 'refresh_token'])
            ->and($response->json())->not->toHaveKey('client_secret');
        $client = Passport::client()->newQuery()->findOrFail($response->json('client_id'));
        expect($client->name)->toBe('Claude')->and($client->confidential())->toBeFalse();
    });

    it('refuses a redirect address of an app it does not know, unless the server allows it', function () {
        $this->postJson('/oauth/register', ['client_name' => 'Evil', 'redirect_uris' => ['https://evil.example/callback']])
            ->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
        // The host is compared whole: this is not claude.ai.
        $this->postJson('/oauth/register', ['client_name' => 'Evil', 'redirect_uris' => ['https://claude.ai.evil.example/callback']])->assertStatus(400);
        $this->postJson('/oauth/register', ['client_name' => 'Evil', 'redirect_uris' => ['claude://callback']])->assertStatus(400);
        // Claude Code and other desktop clients listen on this machine, on a port of their own.
        $this->postJson('/oauth/register', ['client_name' => 'Claude Code', 'redirect_uris' => ['http://localhost:49152/callback']])->assertCreated();
        // A new address of a real app needs no release: MCP_REDIRECT_DOMAINS on the server.
        config(['mcp.redirect_domains' => ['https://new.example']]);
        $this->postJson('/oauth/register', ['client_name' => 'New', 'redirect_uris' => ['https://new.example/cb']])->assertCreated();
    });

    it('takes the apps\' own sign-in addresses and no other page of their sites', function (string $uri) {
        $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [$uri]])->assertCreated();
    })->with([
        'https://claude.ai/api/mcp/auth_callback',
        'https://claude.com/api/mcp/auth_callback',
        'https://chatgpt.com/connector_platform_oauth_redirect',
        'https://chatgpt.com/connector/oauth/cb_8f3a-41',
        'http://127.0.0.1:9999/cb',
    ]);

    it('refuses any other address on the apps\' sites, whoever registers it', function (string $uri) {
        $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [$uri]])
            ->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');

        expect(Passport::client()->newQuery()->count())->toBe(0);
    })->with([
        'https://claude.ai/public/artifacts/abc',
        'https://claude.ai/',
        'https://claude.ai/../../evil',
        'https://claude.ai/api/mcp/auth_callback/../../../share/abc',
        'https://claude.ai/api/mcp/auth_callbackevil',
        'https://claude.ai/api/mcp/auth_callback/more',
        'https://claude.ai/api/mcp/auth_callback?next=https://evil.example',
        'https://claude.ai/api/mcp/auth_callback#fragment',
        'http://claude.ai/api/mcp/auth_callback',
        'https://chatgpt.com/share/abc',
        'https://chatgpt.com/aip/g-attacker/oauth/callback',
        'https://chatgpt.com/connector/oauth/',
        'https://chatgpt.com/connector/oauth/a/b',
        'https://chatgpt.com/connector/oauth/..',
        'https://chatgpt.com/connector/oauth/%2e%2e/share',
        'https://chat.openai.com/',
        'http://localhost:49152/../x',
        'https://localhost:49152/callback',
    ]);

    it('lets the server open a whole site when an app moves, still with no way out of it', function () {
        config(['mcp.redirect_domains' => ['https://new.example', 'https://exact.example/cb', 'https://one.example/oauth/*']]);

        $this->postJson('/oauth/register', ['client_name' => 'New', 'redirect_uris' => ['https://new.example/any/page']])->assertCreated();
        $this->postJson('/oauth/register', ['client_name' => 'New', 'redirect_uris' => ['https://exact.example/cb']])->assertCreated();
        $this->postJson('/oauth/register', ['client_name' => 'New', 'redirect_uris' => ['https://one.example/oauth/abc']])->assertCreated();
        foreach (['https://new.example/a/../../b', 'https://new.example.evil.test/cb', 'https://exact.example/other', 'https://one.example/oauth/a/b', CONNECTOR_REDIRECT] as $refused) {
            $this->postJson('/oauth/register', ['client_name' => 'New', 'redirect_uris' => [$refused]])->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
        }
    });

    it('checks every address of a registration', function () {
        $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [CONNECTOR_REDIRECT, 'https://claude.ai/public/artifacts/abc']])
            ->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
        $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => 'https://claude.ai/public/artifacts/abc'])->assertStatus(400);
        $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [['nested']]])->assertStatus(400);
    });

    it('limits registrations to 10 a minute per address', function () {
        foreach (range(1, 10) as $i) {
            connectorRegister($this, "App {$i}");
        }

        $this->postJson('/oauth/register', ['client_name' => 'Eleven', 'redirect_uris' => [CONNECTOR_REDIRECT]])->assertTooManyRequests();
    });

    it('limits registrations to 30 an hour and 100 a day per address', function () {
        $register = fn () => $this->postJson('/oauth/register', ['client_name' => 'App', 'redirect_uris' => [CONNECTOR_REDIRECT]]);
        foreach (range(1, 3) as $minute) {
            foreach (range(1, 10) as $i) {
                $register()->assertCreated();
            }
            $this->travel(61)->seconds();
        }
        $register()->assertTooManyRequests();

        $made = 30;
        while ($made < 100) {
            $this->travel(61)->minutes();
            foreach (range(1, 3) as $minute) {
                foreach (range(1, min(10, 100 - $made) ?: 1) as $i) {
                    if ($made < 100) {
                        $register()->assertCreated();
                        $made++;
                    }
                }
                $this->travel(61)->seconds();
            }
        }
        $this->travel(61)->minutes();
        $register()->assertTooManyRequests();
        expect(Passport::client()->newQuery()->count())->toBe(100);

        $this->travelTo(now()->addDay());
        $register()->assertCreated();
    });

    it('serves only the routes the connector uses, none of Passport\'s others', function () {
        $served = collect(app('router')->getRoutes()->getRoutes())->map(fn ($route): string => $route->uri())->filter(fn (string $uri): bool => str_starts_with($uri, 'oauth'))->unique()->sort()->values()->all();

        expect($served)->toBe(['oauth/authorize', 'oauth/register', 'oauth/token']);
        foreach (['oauth/clients', 'oauth/personal-access-tokens', 'oauth/scopes', 'oauth/tokens', 'oauth/token/refresh', 'oauth/device', 'oauth/device/code'] as $uri) {
            $this->postJson('/'.$uri)->assertNotFound();
        }
    });
});

describe('the code flow with PKCE', function () {
    it('goes from registration to a tool call, naming the app and asking the person first', function () {
        $client = connectorRegister($this);
        [$verifier, $challenge] = connectorPkce();

        $page = $this->actingAs($this->person, 'web')->get(connectorAuthorizeUrl($client, $challenge))->assertOk();
        $page->assertSee('Allow Claude to read your EchoLedger recordings?')->assertSee('hassam@example.test')
            ->assertSee('It can only read')->assertSee('claude.ai')->assertSee('Allow')->assertSee('Cancel');
        // The form can only go back to the app that registered that address.
        expect($page->headers->get('Content-Security-Policy'))->toContain("form-action 'self' https://claude.ai")->not->toContain('*');

        $code = connectorAllow($this, $this->person, $client, $challenge);
        $tokens = connectorExchange($this, $client, $code, $verifier)->assertOk();

        expect($tokens->json('token_type'))->toBe('Bearer')
            ->and($tokens->json('expires_in'))->toBe(3600)
            ->and($tokens->json('refresh_token'))->toBeString();
        $names = collect(connectorMessage($this, $tokens->json('access_token'), 'tools/list')->assertOk()->json('result.tools'))->pluck('name')->all();
        expect($names)->toBe(['list_projects', 'search', 'get_recording', 'get_project_brief', 'list_recent']);
        expect(connectorTool($this, $tokens->json('access_token'), 'list_recent')['text'])->toBe('No recordings yet.');
    });

    it('sends someone not signed in to the console\'s sign-in and back to the same page', function () {
        $client = connectorRegister($this);
        [, $challenge] = connectorPkce();
        $url = connectorAuthorizeUrl($client, $challenge);

        $this->get($url)->assertRedirect(route('filament.app.auth.login'));

        parse_str((string) parse_url((string) session('url.intended'), PHP_URL_QUERY), $kept);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $asked);
        expect((string) session('url.intended'))->toStartWith(url('/oauth/authorize?'))->and($kept)->toEqual($asked);
    });

    it('lets the person cancel, and gives the app nothing', function () {
        $client = connectorRegister($this);
        [, $challenge] = connectorPkce();
        $page = $this->actingAs($this->person, 'web')->get(connectorAuthorizeUrl($client, $challenge))->assertOk();
        preg_match('/name="auth_token" value="([^"]+)"/', (string) $page->getContent(), $token);

        $location = (string) $this->delete('/oauth/authorize', ['auth_token' => $token[1]])->assertRedirect()->headers->get('Location');

        expect($location)->toContain('error=access_denied')->not->toContain('code=')
            ->and(Passport::token()->newQuery()->count())->toBe(0)
            ->and(ConnectedApp::query()->count())->toBe(0);
    });

    it('refuses a form sent with another token', function () {
        $client = connectorRegister($this);
        [, $challenge] = connectorPkce();
        $this->actingAs($this->person, 'web')->get(connectorAuthorizeUrl($client, $challenge))->assertOk();

        expect($this->post('/oauth/authorize', ['auth_token' => 'wrong'])->status())->toBeGreaterThanOrEqual(400);
        expect(Passport::authCode()->newQuery()->count())->toBe(0);
    });

    it('refuses the plain method, a missing method and a missing challenge before asking the person', function () {
        $client = connectorRegister($this);
        [$verifier, $challenge] = connectorPkce();
        $this->actingAs($this->person, 'web');

        foreach ([
            ['code_challenge' => $verifier, 'code_challenge_method' => 'plain'],
            ['code_challenge_method' => ''],
            ['code_challenge' => '', 'code_challenge_method' => ''],
            ['code_challenge' => ''],
        ] as $overrides) {
            $response = $this->get(connectorAuthorizeUrl($client, $challenge, $overrides));
            expect($response->getStatusCode())->toBe(400, (string) json_encode($overrides))
                ->and($response->json('error'))->toBe('invalid_request')
                ->and((string) $response->getContent())->not->toContain('Allow Claude');
        }
    });

    it('refuses a wrong verifier', function () {
        $client = connectorRegister($this);
        [, $challenge] = connectorPkce();
        $code = connectorAllow($this, $this->person, $client, $challenge);

        connectorExchange($this, $client, $code, str_repeat('x', 64))->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        expect(Passport::token()->newQuery()->count())->toBe(0);
    });

    it('refuses a code without a verifier', function () {
        $client = connectorRegister($this);
        [, $challenge] = connectorPkce();
        $code = connectorAllow($this, $this->person, $client, $challenge);

        $this->post('/oauth/token', ['grant_type' => 'authorization_code', 'client_id' => $client, 'redirect_uri' => CONNECTOR_REDIRECT, 'code' => $code])->assertStatus(400);
        expect(Passport::token()->newQuery()->count())->toBe(0);
    });

    it('takes a code once', function () {
        $client = connectorRegister($this);
        [$verifier, $challenge] = connectorPkce();
        $code = connectorAllow($this, $this->person, $client, $challenge);
        connectorExchange($this, $client, $code, $verifier)->assertOk();

        connectorExchange($this, $client, $code, $verifier)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        expect(Passport::token()->newQuery()->count())->toBe(1);
    });

    it('treats a used code coming back as a leak: what it gave stops and the app is disconnected', function () {
        $client = connectorRegister($this);
        [$verifier, $challenge] = connectorPkce();
        $code = connectorAllow($this, $this->person, $client, $challenge);
        $tokens = connectorExchange($this, $client, $code, $verifier)->assertOk()->json();
        connectorMessage($this, $tokens['access_token'], 'tools/list')->assertOk();

        connectorExchange($this, $client, $code, $verifier)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

        connectorMessage($this, $tokens['access_token'], 'tools/list')->assertUnauthorized();
        connectorRefresh($this, $client, $tokens['refresh_token'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        expect(ConnectedApp::query()->connected()->count())->toBe(0);
        $row = AuditEvent::query()->where('event', 'connector_token_reused')->sole();
        expect($row->user_id)->toBe($this->person->id)->and($row->summary)->toContain('Claude')->and($row->meta['kind'])->toBe('code');
        expect((string) $row->toJson())->not->toContain($code);
    });

    it('ends nothing for a wrong verifier, another app or a code that means nothing', function () {
        $client = connectorRegister($this);
        $other = connectorRegister($this, 'Other');
        [$verifier, $challenge] = connectorPkce();
        $code = connectorAllow($this, $this->person, $client, $challenge);
        $tokens = connectorExchange($this, $client, $code, $verifier)->assertOk()->json();
        [$verifierTwo, $challengeTwo] = connectorPkce();
        $unused = connectorAllow($this, $this->person, $client, $challengeTwo);

        // Not used yet, so nothing came back: the right verifier can still use it.
        connectorExchange($this, $client, $unused, str_repeat('x', 64))->assertStatus(400);
        // Another app holding a used code cannot end someone's connection with it.
        connectorExchange($this, $other, $code, $verifier)->assertStatus(400);
        connectorExchange($this, $client, 'not-a-code', $verifier)->assertStatus(400);
        $this->post('/oauth/token', ['grant_type' => 'authorization_code', 'client_id' => $client, 'code' => ['x'], 'code_verifier' => $verifier])->assertStatus(400);

        connectorMessage($this, $tokens['access_token'], 'tools/list')->assertOk();
        expect(ConnectedApp::query()->connected()->count())->toBe(1)
            ->and(AuditEvent::query()->where('event', 'connector_token_reused')->count())->toBe(0);
        connectorExchange($this, $client, $unused, $verifierTwo)->assertOk();
    });

    it('keeps a code for 10 minutes, for the app it was made for', function () {
        $client = connectorRegister($this);
        $other = connectorRegister($this, 'Other');
        [$verifier, $challenge] = connectorPkce();
        $code = connectorAllow($this, $this->person, $client, $challenge);

        expect(now()->diffInMinutes(Passport::authCode()->newQuery()->sole()->expires_at))->toBeGreaterThan(9)->toBeLessThanOrEqual(10);
        connectorExchange($this, $other, $code, $verifier)->assertStatus(400);
    });

    it('refuses any scope but mcp:use, and gives an app that names none that one', function () {
        $client = connectorRegister($this);
        [$verifier, $challenge] = connectorPkce();
        $this->actingAs($this->person, 'web');

        foreach (['admin', 'mcp:use admin', '*'] as $scope) {
            $response = $this->get(connectorAuthorizeUrl($client, $challenge, ['scope' => $scope]));
            expect($response->isRedirect() ? (string) $response->headers->get('Location') : (string) $response->getContent())->toContain('invalid_scope')
                ->and((string) $response->getContent())->not->toContain('Allow Claude');
        }

        $page = $this->get(connectorAuthorizeUrl($client, $challenge, ['scope' => '']))->assertOk();
        preg_match('/name="auth_token" value="([^"]+)"/', (string) $page->getContent(), $token);
        $location = (string) $this->post('/oauth/authorize', ['auth_token' => $token[1]])->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $access = connectorExchange($this, $client, (string) $query['code'], $verifier)->assertOk();
        expect(Passport::token()->newQuery()->sole()->scopes)->toBe(['mcp:use'])
            ->and($access->json('expires_in'))->toBe(3600);
    });

    it('refuses a redirect the app did not register, without sending the browser there', function () {
        $client = connectorRegister($this);
        [, $challenge] = connectorPkce();

        $response = $this->actingAs($this->person, 'web')->get(connectorAuthorizeUrl($client, $challenge, ['redirect_uri' => 'https://claude.ai/elsewhere']));

        expect($response->getStatusCode())->toBe(401)->and($response->json('error'))->toBe('invalid_client')->and($response->isRedirect())->toBeFalse();
    });

    it('knows no password, client credentials or implicit grant', function () {
        $client = connectorRegister($this);
        foreach ([
            ['grant_type' => 'password', 'username' => $this->person->email, 'password' => 'password', 'client_id' => $client],
            ['grant_type' => 'client_credentials', 'client_id' => $client],
            ['grant_type' => 'urn:ietf:params:oauth:grant-type:device_code', 'client_id' => $client, 'device_code' => 'x'],
        ] as $body) {
            $this->post('/oauth/token', $body)->assertStatus(400)->assertJsonPath('error', 'unsupported_grant_type');
        }
        [, $challenge] = connectorPkce();
        $this->actingAs($this->person, 'web')->get(connectorAuthorizeUrl($client, $challenge, ['response_type' => 'token']))->assertStatus(400);
    });

    it('tells an account that cannot use it why, instead of asking', function () {
        $client = connectorRegister($this);
        [, $challenge] = connectorPkce();

        $this->person->forceFill(['deletes_at' => now()->addDays(7)])->save();
        $page = $this->actingAs($this->person, 'web')->get(connectorAuthorizeUrl($client, $challenge));
        $page->assertForbidden()->assertSee('This account cannot connect an app')->assertSee('being deleted')->assertDontSee('auth_token');

        $unconfirmed = Fx::user(['email_verified_at' => null]);
        $this->actingAs($unconfirmed, 'web')->get(connectorAuthorizeUrl($client, $challenge))->assertForbidden()->assertSee('Confirm your email')->assertDontSee('auth_token');

        $nowhere = withoutWorkspace(Fx::user());
        $this->actingAs($nowhere, 'web')->get(connectorAuthorizeUrl($client, $challenge))->assertForbidden()->assertSee('no workspace')->assertDontSee('auth_token');
    });

    it('needs the two-step code before the person is signed in and asked', function () {
        Filament::setCurrentPanel('app');
        $provider = AppAuthentication::make();
        $provider->saveSecret($this->person, $secret = $provider->generateSecret());
        $client = connectorRegister($this);
        [, $challenge] = connectorPkce();
        $url = connectorAuthorizeUrl($client, $challenge);
        $this->get($url)->assertRedirect(route('filament.app.auth.login'));

        $login = Livewire::test(Login::class)
            ->fillForm(['email' => $this->person->email, 'password' => 'password'])
            ->call('authenticate');
        $this->assertGuest('web');

        $login->set('data.multiFactor.app.code', $provider->getCurrentCode($this->person, $secret))->call('authenticate')->assertHasNoErrors();
        $this->assertAuthenticatedAs($this->person, 'web');
        // The console's sign-in goes on to where the browser was going.
        expect((string) $login->effects['redirect'])->toStartWith(url('/oauth/authorize?'));
        $this->get($url)->assertOk()->assertSee('Allow Claude');
    });

    it('sends the form to Passport as the browser would, with the token and the method it needs', function () {
        $client = connectorRegister($this);
        [, $challenge] = connectorPkce();

        $html = (string) $this->actingAs($this->person, 'web')->get(connectorAuthorizeUrl($client, $challenge))->assertOk()->getContent();

        expect($html)->toContain('action="'.url('/oauth/authorize').'"')->toContain('name="_method" value="DELETE"')->toContain('name="_token"')
            // The request's own details stay in the session, not in the page.
            ->not->toContain($challenge)->not->toContain('state-1');
    });

    it('asks every time, also while the app still has working tokens: anyone can register a name', function () {
        $first = connectorConnect($this, $this->person);
        [, $challenge] = connectorPkce();

        $this->actingAs($this->person, 'web')->get(connectorAuthorizeUrl($first['client'], $challenge))->assertOk()->assertSee('Allow Claude');

        Passport::token()->newQuery()->update(['revoked' => true]);
        $this->get(connectorAuthorizeUrl($first['client'], $challenge))->assertOk()->assertSee('Allow Claude');
    });

    it('gives no code without a click: prompt=none is answered consent_required, or login_required when signed out', function () {
        $first = connectorConnect($this, $this->person);
        [, $challenge] = connectorPkce();
        $url = connectorAuthorizeUrl($first['client'], $challenge, ['prompt' => 'none']);

        $location = (string) $this->actingAs($this->person, 'web')->get($url)->assertRedirect()->headers->get('Location');
        expect($location)->toStartWith(CONNECTOR_REDIRECT.'?')->toContain('error=consent_required')->not->toContain('code=')
            ->and(Passport::authCode()->newQuery()->where('revoked', false)->count())->toBe(0);

        auth('web')->logout();
        $location = (string) $this->get($url)->assertRedirect()->headers->get('Location');
        expect($location)->toContain('error=login_required')->not->toContain('code=')
            ->and(Passport::authCode()->newQuery()->where('revoked', false)->count())->toBe(0);
    });

    it('sends a session the console has ended to the sign-in, with no consent page and no code', function () {
        $client = connectorRegister($this);
        [, $challenge] = connectorPkce();
        $url = connectorAuthorizeUrl($client, $challenge);
        $page = $this->actingAs($this->person, 'web')->get($url)->assertOk();
        preg_match('/name="auth_token" value="([^"]+)"/', (string) $page->getContent(), $token);

        // The password changes elsewhere: this session is one from before it, and the console already refuses it.
        $this->person->forceFill(['password' => Hash::make('a-new-good-password-43')])->save();

        $this->post('/oauth/authorize', ['auth_token' => $token[1]])->assertRedirect(route('filament.app.auth.login'));
        $this->assertGuest('web');
        expect(Passport::authCode()->newQuery()->count())->toBe(0);

        $this->withSession(['password_hash_web' => 'from before the change'])->actingAs($this->person, 'web')
            ->get($url)->assertRedirect(route('filament.app.auth.login'));
        $this->assertGuest('web');

        $this->withSession(['password_hash_web' => 'from before the change'])->actingAs($this->person, 'web')
            ->delete('/oauth/authorize', ['auth_token' => 'x'])->assertRedirect(route('filament.app.auth.login'));
        // The same address still works after signing in again.
        $this->actingAs($this->person, 'web')->get($url)->assertOk()->assertSee('Allow Claude');
    });
});

describe('tokens', function () {
    it('makes access tokens last 1 hour and refresh tokens 30 days', function () {
        $connected = connectorConnect($this, $this->person);
        $token = Passport::token()->newQuery()->sole();
        $refresh = Passport::refreshToken()->newQuery()->sole();
        $claims = json_decode((string) base64_decode(strtr(explode('.', $connected['access'])[1], '-_', '+/')), true);

        expect($token->expires_at->diffInMinutes(now(), true))->toBeGreaterThan(59)->toBeLessThanOrEqual(60)
            ->and($refresh->expires_at->diffInDays(now(), true))->toBeGreaterThan(29)->toBeLessThanOrEqual(30)
            // The signed token says the same.
            ->and((int) $claims['exp'] - time())->toBeGreaterThan(3590)->toBeLessThanOrEqual(3600)
            ->and($claims['scopes'])->toBe(['mcp:use']);
    });

    it('stops an access token when its time is over', function () {
        Passport::tokensExpireIn(CarbonInterval::seconds(1));
        $connected = connectorConnect($this, $this->person);

        // The OAuth library reads the real clock, so this test waits for it.
        sleep(2);

        connectorMessage($this, $connected['access'], 'tools/list')->assertUnauthorized();
    });

    it('rotates on refresh: a new pair, and the old tokens stop', function () {
        $connected = connectorConnect($this, $this->person);

        $new = connectorRefresh($this, $connected['client'], $connected['refresh'])->assertOk();
        expect($new->json('refresh_token'))->not->toBe($connected['refresh'])
            ->and($new->json('access_token'))->not->toBe($connected['access'])
            ->and($new->json('expires_in'))->toBe(3600);

        connectorRefresh($this, $connected['client'], $connected['refresh'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        connectorMessage($this, $connected['access'], 'tools/list')->assertUnauthorized();
        connectorMessage($this, $new->json('access_token'), 'tools/list')->assertOk();
    });

    it('treats a used refresh token coming back as a leak: what came after it stops and the app is disconnected', function () {
        $connected = connectorConnect($this, $this->person);
        $new = connectorRefresh($this, $connected['client'], $connected['refresh'])->assertOk()->json();
        $newer = connectorRefresh($this, $connected['client'], $new['refresh_token'])->assertOk()->json();
        $this->travel(ConnectedApps::REFRESH_RETRY_SECONDS + 1)->seconds();
        connectorMessage($this, $newer['access_token'], 'tools/list')->assertOk();

        connectorRefresh($this, $connected['client'], $connected['refresh'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

        connectorMessage($this, $newer['access_token'], 'tools/list')->assertUnauthorized();
        connectorRefresh($this, $connected['client'], $newer['refresh_token'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        expect(ConnectedApp::query()->connected()->count())->toBe(0)
            ->and(Passport::token()->newQuery()->where('revoked', false)->count())->toBe(0)
            ->and(Passport::refreshToken()->newQuery()->where('revoked', false)->count())->toBe(0);
        $row = AuditEvent::query()->where('event', 'connector_token_reused')->sole();
        expect($row->user_id)->toBe($this->person->id)->and($row->summary)->toContain('Claude')->and($row->meta['kind'])->toBe('refresh');
        expect((string) $row->toJson())->not->toContain($connected['refresh']);

        // Connecting again works: the person is asked and the app gets new tokens.
        [$verifier, $challenge] = connectorPkce();
        $again = connectorExchange($this, $connected['client'], connectorAllow($this, $this->person, $connected['client'], $challenge), $verifier)->assertOk()->json();
        connectorMessage($this, $again['access_token'], 'tools/list')->assertOk();
    });

    it('still knows a used refresh token after the clean-up has removed its row', function () {
        $connected = connectorConnect($this, $this->person);
        $new = connectorRefresh($this, $connected['client'], $connected['refresh'])->assertOk()->json();
        $this->travel(ConnectedApps::REFRESH_RETRY_SECONDS + 1)->seconds();
        Artisan::call('passport:purge', ['--revoked' => true]);
        expect(Passport::refreshToken()->newQuery()->count())->toBe(1);

        connectorRefresh($this, $connected['client'], $connected['refresh'])->assertStatus(400);

        connectorMessage($this, $new['access_token'], 'tools/list')->assertUnauthorized();
    });

    it('takes the same refresh token twice within seconds for an app asking twice at once, and refuses the second', function () {
        $connected = connectorConnect($this, $this->person);
        $new = connectorRefresh($this, $connected['client'], $connected['refresh'])->assertOk()->json();

        connectorRefresh($this, $connected['client'], $connected['refresh'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

        connectorMessage($this, $new['access_token'], 'tools/list')->assertOk();
        connectorRefresh($this, $connected['client'], $new['refresh_token'])->assertOk();
        expect(ConnectedApp::query()->connected()->count())->toBe(1)
            ->and(AuditEvent::query()->where('event', 'connector_token_reused')->count())->toBe(0);
    });

    it('does not let a token from before Disconnect end the connection made after it', function () {
        $connected = connectorConnect($this, $this->person);
        app(ConnectedApps::class)->disconnect($this->person, ConnectedApp::query()->sole()->id);
        $this->travel(ConnectedApps::REFRESH_RETRY_SECONDS + 1)->seconds();
        connectorRefresh($this, $connected['client'], $connected['refresh'])->assertStatus(400);
        [$verifier, $challenge] = connectorPkce();
        $again = connectorExchange($this, $connected['client'], connectorAllow($this, $this->person, $connected['client'], $challenge), $verifier)->assertOk()->json();

        connectorRefresh($this, $connected['client'], $connected['refresh'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

        connectorMessage($this, $again['access_token'], 'tools/list')->assertOk();
        expect(ConnectedApp::query()->connected()->count())->toBe(1)
            ->and(AuditEvent::query()->where('event', 'connector_token_reused')->count())->toBe(0);
    });

    it('ends nothing for a refresh token that means nothing or belongs to another app, and only this person\'s connection for a leak', function () {
        $connected = connectorConnect($this, $this->person);
        $someoneElse = connectorConnect($this, Fx::user(), 'Claude');
        $other = connectorRegister($this, 'Other');
        $new = connectorRefresh($this, $connected['client'], $connected['refresh'])->assertOk()->json();
        $this->travel(ConnectedApps::REFRESH_RETRY_SECONDS + 1)->seconds();

        connectorRefresh($this, $connected['client'], 'not-a-token')->assertStatus(400);
        connectorRefresh($this, $other, $connected['refresh'])->assertStatus(400);
        $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $connected['client'], 'refresh_token' => ['x']])->assertStatus(400);
        connectorMessage($this, $new['access_token'], 'tools/list')->assertOk();
        expect(AuditEvent::query()->where('event', 'connector_token_reused')->count())->toBe(0);

        connectorRefresh($this, $connected['client'], $connected['refresh'])->assertStatus(400);

        connectorMessage($this, $new['access_token'], 'tools/list')->assertUnauthorized();
        connectorMessage($this, $someoneElse['access'], 'tools/list')->assertOk();
    });

    it('counts the token route per address too: changing the client id each time gets no further', function () {
        foreach (range(1, 300) as $i) {
            $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => "guess-{$i}", 'refresh_token' => 'x'])->assertStatus(401);
        }

        $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => 'guess-301', 'refresh_token' => 'x'])->assertTooManyRequests();
    });

    it('limits one client to 60 token requests a minute per address', function () {
        foreach (range(1, 60) as $i) {
            $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => 'same', 'refresh_token' => 'x'])->assertStatus(401);
        }

        $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => 'same', 'refresh_token' => 'x'])->assertTooManyRequests();
        $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => 'another', 'refresh_token' => 'x'])->assertStatus(401);
    });

    it('answers a client id that is no string as a bad request, counted like any other, and logs nothing', function () {
        Exceptions::fake();

        foreach (range(1, 60) as $i) {
            $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => ['x'], 'refresh_token' => 'x'])->assertStatus(400)->assertJsonPath('error', 'invalid_request');
        }

        $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => ['x'], 'refresh_token' => 'x'])->assertTooManyRequests();
        Exceptions::assertNothingReported();
    });

    it('takes a refresh token only from the app it was given to', function () {
        $connected = connectorConnect($this, $this->person);
        $other = connectorRegister($this, 'Other');

        connectorRefresh($this, $other, $connected['refresh'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        connectorRefresh($this, $connected['client'], $connected['refresh'])->assertOk();
    });

    it('stops a refresh token when its time is over', function () {
        Passport::refreshTokensExpireIn(CarbonInterval::seconds(1));
        $connected = connectorConnect($this, $this->person);

        sleep(2);

        connectorRefresh($this, $connected['client'], $connected['refresh'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    });

    it('keeps a wrong or expired token out of the log', function () {
        Exceptions::fake();

        connectorMessage($this, 'not-a-token', 'tools/list')->assertUnauthorized();

        Exceptions::assertNothingReported();
    });

    it('never takes a device token for a person\'s, or the other way round', function () {
        $device = Fx::device($this->person);
        connectorMessage($this, Fx::token($device), 'tools/list')->assertUnauthorized();

        $connected = connectorConnect($this, $this->person);
        app('auth')->forgetGuards();
        $this->getJson('/api/library', ['Authorization' => "Bearer {$connected['access']}"])->assertUnauthorized();
    });

    it('refuses a token that lacks the scope', function () {
        Passport::actingAs($this->person, [], 'api');

        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Accept' => 'application/json, text/event-stream'])->assertForbidden();
    });

    it('records the connection in the audit log, once', function () {
        $connected = connectorConnect($this, $this->person);
        connectorRefresh($this, $connected['client'], $connected['refresh'])->assertOk();

        $rows = AuditEvent::query()->where('event', 'connector_connected')->get();
        expect($rows)->toHaveCount(1)->and($rows->sole()->user_id)->toBe($this->person->id)->and($rows->sole()->summary)->toContain('Claude');
    });
});
