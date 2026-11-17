<?php

declare(strict_types=1);

use App\Exceptions\ApiError;
use App\Filament\Admin\Pages\Settings;
use App\Models\AuditEvent;
use App\Services\Assist\AssistTransport;
use App\Services\Assist\RelayHealth;
use App\Services\SettingsRepository;
use Filament\Facades\Filament;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

require_once __DIR__.'/Support/assist_helpers.php';

uses(ApiClient::class);

/*
 * Live Assist's connection (1.9.0-q): Automatic, Shared hosting or Dedicated relay, the relay's health check, and the
 * admin's section on Settings › General.
 */

beforeEach(fn () => assistRelaySetUp(healthy: false));
afterEach(fn () => RelayHealth::$resolveUsing = null);

/** The relay answers its health check (or not), asked afresh. */
function relayAnswers(bool $up): void
{
    test()->travel(61)->seconds();
    Http::fake(['relay.example.com/healthz' => $up
        ? Http::response(['status' => 'ok', 'version' => '1.0.2'])
        : Http::response('down', 502)]);
}

function connection(string $choice): AssistTransport
{
    app(SettingsRepository::class)->set('assist.connection', $choice);

    return app(AssistTransport::class);
}

describe('the transport a session gets', function () {
    it('is the relay under Automatic while the relay answers, and this server while it does not', function () {
        relayAnswers(true);
        expect(connection(AssistTransport::AUTOMATIC)->choose(['relay', 'http']))->toBe('relay');

        app(RelayHealth::class)->record(['ok' => false]);
        expect(connection(AssistTransport::AUTOMATIC)->choose(['relay', 'http']))->toBe('http');
    });

    it('is this server under Automatic when no relay is set, without asking anyone', function () {
        Http::fake();
        app(SettingsRepository::class)->set('relay.url', null);

        expect(connection(AssistTransport::AUTOMATIC)->choose(['relay', 'http']))->toBe('http');
        Http::assertNothingSent();
    });

    it('is always this server under Shared hosting, even with the relay up', function () {
        relayAnswers(true);

        expect(connection(AssistTransport::SHARED)->choose(['relay', 'http']))->toBe('http');
    });

    it('is always the relay under Dedicated relay, and refused in plain words while it is down', function () {
        relayAnswers(true);
        expect(connection(AssistTransport::RELAY)->choose(['relay', 'http']))->toBe('relay');

        app(RelayHealth::class)->record(['ok' => false]);
        expect(fn () => connection(AssistTransport::RELAY)->choose(['relay', 'http']))
            ->toThrow(fn (ApiError $e) => expect([$e->getStatusCode(), $e->apiCode(), $e->getMessage()])
                ->toBe([503, 'relay_unavailable', 'Live Assist can\'t connect right now. Try again in a few minutes.']));
    });

    it('refuses an app that does not know the transport the server runs, with a clear message', function () {
        relayAnswers(false);

        expect(fn () => connection(AssistTransport::AUTOMATIC)->choose(['relay']))
            ->toThrow(fn (ApiError $e) => expect([$e->getStatusCode(), $e->apiCode(), $e->getMessage()])
                ->toBe([426, 'app_update_required', 'Live Assist needs the latest version of the app.']));
        expect(connection(AssistTransport::SHARED)->choose(['http']))->toBe('http');
    });

    it('treats an unknown setting as Automatic', function () {
        relayAnswers(true);

        expect(connection('something-else')->choice())->toBe(AssistTransport::AUTOMATIC);
    });
});

describe('the relay health check', function () {
    it('asks the relay at most once a minute, and again after that', function () {
        relayAnswers(true);
        $health = app(RelayHealth::class);

        expect($health->healthy())->toBeTrue()->and($health->healthy())->toBeTrue();
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://relay.example.com/healthz');

        $this->travel(61)->seconds();
        expect($health->healthy())->toBeTrue();
        Http::assertSentCount(2);
    });

    it('is not ok when the relay answers anything but status ok, or nothing', function () {
        Http::fake(['relay.example.com/healthz' => Http::response(['status' => 'starting'])]);
        expect(app(RelayHealth::class)->check()['ok'])->toBeFalse();

        Http::fake(['relay.example.com/healthz' => fn () => throw new ConnectionException('refused')]);
        $this->travel(61)->seconds();
        expect(app(RelayHealth::class)->check())->toMatchArray(['ok' => false, 'error' => 'The relay did not answer.']);
    });

    it('forgets the result when the relay address changes', function () {
        relayAnswers(true);
        expect(app(RelayHealth::class)->healthy())->toBeTrue();

        app(SettingsRepository::class)->set('relay.url', 'relay.example.org');
        expect(app(RelayHealth::class)->recent())->toBeNull();
    });
});

describe('Admin › Settings › Live Assist connection', function () {
    beforeEach(function () {
        Filament::setCurrentPanel('admin');
        $this->actingAs(Fx::superAdmin(), 'web');
    });

    it('defaults to Automatic and saves the choice with an activity entry naming before and after', function () {
        Livewire::test(Settings::class)->assertSee('Live Assist connection')
            ->assertSet('data.assist_connection', 'automatic')->assertSet('data.relay_url', 'relay.example.com')
            ->set('data.assist_connection', 'shared')->call('save')->assertHasNoErrors();

        expect(app(SettingsRepository::class)->get('assist.connection'))->toBe('shared');
        $entry = AuditEvent::query()->where('event', 'assist_connection_changed')->sole();
        expect($entry->summary)->toBe('Live Assist connection: Automatic (relay.example.com) → Shared hosting (relay.example.com)')
            ->and($entry->meta)->toMatchArray(['from' => ['connection' => 'automatic', 'relay' => 'relay.example.com'], 'to' => ['connection' => 'shared', 'relay' => 'relay.example.com']]);
    });

    it('logs a relay address change too, and nothing when neither changed', function () {
        Livewire::test(Settings::class)->set('data.relay_url', '')->call('save')->assertHasNoErrors();
        expect(AuditEvent::query()->where('event', 'assist_connection_changed')->sole()->summary)
            ->toBe('Live Assist connection: Automatic (relay.example.com) → Automatic (no relay)');

        Livewire::test(Settings::class)->call('save')->assertHasNoErrors();
        expect(AuditEvent::query()->where('event', 'assist_connection_changed')->count())->toBe(1);
    });

    it('checks the relay and says whether it is up', function () {
        Http::fake(['relay.example.com/healthz' => Http::sequence()->push(['status' => 'ok', 'version' => '1.0.2'])->push('down', 502)]);
        Livewire::test(Settings::class)->call('checkRelay')->assertNotified('The relay is up');
        expect(app(RelayHealth::class)->recent())->toMatchArray(['ok' => true, 'version' => '1.0.2']);

        Livewire::test(Settings::class)->call('checkRelay')->assertNotified('The relay is down');
        expect(app(RelayHealth::class)->recent())->toMatchArray(['ok' => false]);
    });

    it('checks a typed address before it is saved, without remembering it', function () {
        Http::fake(['relay.example.org/healthz' => Http::response(['status' => 'ok'])]);

        Livewire::test(Settings::class)->set('data.relay_url', 'relay.example.org')->call('checkRelay')->assertNotified('The relay is up');
        // The saved relay's remembered state is untouched by a check of another address.
        expect(app(RelayHealth::class)->recent())->toBeNull();
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://relay.example.org/healthz');
    });
});

describe('the relay check never reaches inside the server\'s network', function () {
    beforeEach(function () {
        Filament::setCurrentPanel('admin');
        $this->actingAs(Fx::superAdmin(), 'web');
    });

    it('refuses a private, loopback, link-local or reserved address, without sending anything', function (string $typed, array $resolved) {
        Http::fake();
        RelayHealth::$resolveUsing = fn (string $host): array => $resolved;

        Livewire::test(Settings::class)->set('data.relay_url', $typed)->call('checkRelay')->assertNotified('The relay is down');
        expect(RelayHealth::probe((string) parse_url('https://'.$typed, PHP_URL_HOST)))
            ->toMatchArray(['ok' => false, 'error' => 'The relay\'s address points inside a private network; it must be a public server.']);
        Http::assertNothingSent();
    })->with([
        'loopback' => ['localhost.example.com', ['127.0.0.1']],
        'private 10.x' => ['inside.example.com', ['10.1.2.3']],
        'link-local (cloud metadata)' => ['meta.example.com', ['169.254.169.254']],
        'IPv6 loopback' => ['six.example.com', ['::1']],
        'a host that resolves to a private address' => ['relay.attacker.example', ['93.184.215.14', '192.168.1.5']],
        'IPv4 mapped in IPv6' => ['mapped.example.com', ['::ffff:10.0.0.1']],
    ]);

    it('judges the addresses themselves', function (string $ip, bool $public) {
        expect(RelayHealth::isPublic($ip))->toBe($public);
    })->with([
        ['127.0.0.1', false], ['10.0.0.8', false], ['172.16.0.1', false], ['192.168.0.1', false], ['169.254.169.254', false],
        ['100.64.0.1', false], ['0.0.0.0', false], ['::1', false], ['fe80::1', false], ['fd00::1', false], ['::', false],
        ['93.184.215.14', true], ['2606:2800:220:1::1', true],
    ]);

    it('pins the checked address and follows no redirect', function () {
        Http::fake(['relay.example.com/healthz' => Http::response('', 302, ['Location' => 'http://169.254.169.254/'])]);
        $this->travel(61)->seconds();

        expect(app(RelayHealth::class)->check())->toMatchArray(['ok' => false, 'error' => 'The relay answered HTTP 302.']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://relay.example.com/healthz');
        Http::assertSentCount(1);
    });

    it('limits Check relay to 6 a minute', function () {
        Http::fake(['relay.example.com/healthz' => Http::response(['status' => 'ok'])]);
        $page = Livewire::test(Settings::class);
        for ($i = 0; $i < 6; $i++) {
            $page->call('checkRelay');
        }
        $page->call('checkRelay')->assertNotified('Wait a moment');
    });
});

describe('starting a session in HTTP mode', function () {
    it('answers transport http with the live endpoint and the ready the relay would send, and keeps it on refresh', function () {
        app(SettingsRepository::class)->set('assist.connection', AssistTransport::SHARED);
        $api = $this->api(Fx::token(assistPerson('pro')));

        $start = $api->postJson('/api/assist/sessions', ['platform' => 'android', 'mode' => 'balanced', 'transports' => ['relay', 'http']])
            ->assertCreated()->assertJsonPath('transport', 'http')->assertJsonPath('relay', null);
        $id = (string) $start->json('session.id');
        expect($start->json('http'))->toBe([
            'live_url' => 'https://echoledger.test/api/assist/sessions/'.$id.'/live',
            'ready' => [
                'protocol' => 1, 'session_id' => $id, 'mode' => 'balanced', 'requested_mode' => 'balanced',
                'allowed_modes' => ['balanced', 'fast'], 'resumed' => false, 'practice' => false,
                'limits' => ['max_message_bytes' => 2097152, 'max_turn_chars' => 4000, 'messages_per_second' => 10],
            ],
        ]);

        // The session keeps its transport, whatever the setting says later.
        app(SettingsRepository::class)->set('assist.connection', AssistTransport::RELAY);
        $api->postJson("/api/assist/sessions/{$id}/token")->assertOk()
            ->assertJsonPath('transport', 'http')->assertJsonPath('relay', null)->assertJsonPath('http.ready.session_id', $id);
    });

    it('answers transport relay with the relay block for an app that knows both, while the relay is up', function () {
        app(RelayHealth::class)->record(['ok' => true]);
        $this->api(Fx::token(assistPerson('pro')))->postJson('/api/assist/sessions', ['platform' => 'desktop', 'practice' => true, 'transports' => ['http', 'relay']])
            ->assertCreated()->assertJsonPath('transport', 'relay')->assertJsonPath('http', null)
            ->assertJsonPath('relay.url', 'wss://relay.example.com/v1/assist');
    });
});
