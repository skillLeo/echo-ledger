<?php

declare(strict_types=1);

use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Filament\Resources\CaptureSessions\Pages\ViewCaptureSession;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\RelationManagers\KeytermsRelationManager;
use App\Filament\Resources\Devices\Pages\ListDevices;
use App\Models\Client;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

afterEach(fn () => Fx::cleanupDisks());

/** Markup that must reach the browser only as escaped text. */
dataset('xss payloads', [
    'script element' => ['<script>alert(1)</script>', ['<script>alert(1)</script>']],
    'image onerror' => ['"><img src=x onerror=alert(1)>', ['<img src=x onerror', '"><img']],
    'svg onload' => ['<svg/onload=alert(1)>', ['<svg/onload']],
    'closing script breakout' => ['</script><script>alert(1)</script>', ['</script><script>']],
    'attribute breakout' => ["' onmouseover='alert(1)", ["' onmouseover='alert(1)'"]],
    'iframe' => ['<iframe src="javascript:alert(1)"></iframe>', ['<iframe src']],
    'blade echo' => ['{{ 7*7 }}{!! "<b>x</b>" !!}', ['<b>x</b>']],
    'blade directive' => ['@php echo "owned"; @endphp', ['owned</']],
]);

function secAdminWith(string $payload): array
{
    Fx::fakeRecordingsDisk();
    $admin = Fx::superAdmin();
    test()->actingAs($admin);
    Filament::setCurrentPanel('admin');

    $client = Client::query()->create(['user_id' => $admin->id, 'name' => 'C '.$payload, 'notes' => $payload]);
    $client->keyterms()->create(['term' => substr($payload, 0, 90)]);
    $device = Fx::device($admin, ['name' => 'D '.$payload, 'app_version' => substr($payload, 0, 30)]);
    $session = Fx::session($admin, $device, ['title' => 'S '.$payload, 'client_id' => $client->id, 'language' => substr($payload, 0, 15)]);
    $transcript = Fx::transcript($session, 'T '.$payload);
    $transcript->update([
        'segments' => [['channel' => 1, 'speaker' => $payload, 'start_ms' => 0, 'end_ms' => 900, 'text' => 'text '.$payload, 'words' => []]],
        'error' => 'E '.$payload, 'language' => substr($payload, 0, 15),
    ]);
    Fx::readyRecording($session);

    return [$admin, $client, $device, $session];
}

/** @param  list<string>  $forbidden  raw fragments that would mean the payload was not escaped */
function secAssertEscaped(string $html, array $forbidden, string $where): void
{
    foreach ($forbidden as $fragment) {
        expect(str_contains($html, $fragment))->toBeFalse("{$where} rendered the raw fragment: {$fragment}");
    }
}

it('escapes hostile text in the sessions table', function (string $payload, array $forbidden) {
    secAdminWith($payload);

    $html = Livewire::test(ListCaptureSessions::class)->assertSuccessful()->html();

    expect($html)->toContain(e('S '.$payload)); // the text is shown, as text
    secAssertEscaped($html, $forbidden, 'sessions table');
})->with('xss payloads');

it('escapes hostile text in the transcript player and session details', function (string $payload, array $forbidden) {
    [, , , $session] = secAdminWith($payload);

    $html = Livewire::test(ViewCaptureSession::class, ['record' => $session->id])->assertSuccessful()->html();

    secAssertEscaped($html, $forbidden, 'transcript player');
})->with('xss payloads');

it('escapes hostile text in the clients table, the client form and the keyterms manager', function (string $payload, array $forbidden) {
    [, $client] = secAdminWith($payload);

    secAssertEscaped(Livewire::test(ListClients::class)->assertSuccessful()->html(), $forbidden, 'clients table');
    secAssertEscaped(Livewire::test(EditClient::class, ['record' => $client->id])->assertSuccessful()->html(), $forbidden, 'client form');
    secAssertEscaped(Livewire::test(KeytermsRelationManager::class, ['ownerRecord' => $client, 'pageClass' => EditClient::class])->assertSuccessful()->html(), $forbidden, 'keyterms');
})->with('xss payloads');

it('escapes hostile text in the devices table', function (string $payload, array $forbidden) {
    secAdminWith($payload);

    secAssertEscaped(Livewire::test(ListDevices::class)->assertSuccessful()->html(), $forbidden, 'devices table');
})->with('xss payloads');

it('encodes transcript segments inside the Alpine state so a closing tag cannot break out', function () {
    [, , , $session] = secAdminWith('</script><script>alert(1)</script>');

    $html = Livewire::test(ViewCaptureSession::class, ['record' => $session->id])->html();

    expect($html)->not->toContain('</script><script>alert(1)')->and($html)->not->toContain('<script>alert(1)</script>');
});

it('serves JSON API responses with a JSON content type so uploaded markup is never rendered', function () {
    $device = Fx::device();
    $token = Fx::token($device);
    $payload = '<script>alert(1)</script>';
    $this->api($token)->postJson('/api/sessions', Fx::sessionPayload(['title' => $payload]))->assertCreated();

    $response = $this->api($token)->getJson('/api/library');

    expect($response->headers->get('Content-Type'))->toStartWith('application/json');
    expect($response->json('data.0.title'))->toBe($payload);
});
