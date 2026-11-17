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
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

require_once __DIR__.'/Support/assist_helpers.php';

/*
 * Live Assist's connection (1.9.0-q): Automatic, Shared hosting or Dedicated relay, the relay's health check, and the
 * admin's section on Settings › General.
 */

beforeEach(fn () => assistRelaySetUp());

function relayAnswers(bool $up): void
{
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
        expect(app(RelayHealth::class)->recent())->toBeNull();
    });
});
