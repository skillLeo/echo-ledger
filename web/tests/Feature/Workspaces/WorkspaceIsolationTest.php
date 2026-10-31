<?php

declare(strict_types=1);

use App\Filament\Support\CommandPalette;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Device;
use App\Models\SessionLabel;
use App\Models\SessionMarker;
use App\Models\ShareLink;
use App\Models\User;
use App\Services\ShareLinks;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
| B20 isolation: workspace A (a super admin, so every console page opens) must never read, list, search, export,
| play, share, edit or delete anything of workspace B. Unknown and foreign ids answer the same 404. The route and
| resource lists are read from the application, so a new API route, console route, resource or page without a
| case here fails this file.
*/

uses(ApiClient::class);

beforeEach(fn () => Fx::fakeRecordingsDisk());
afterEach(fn () => Fx::cleanupDisks());

/** API routes outside the device token, each with the reason it is safe without a workspace. */
const ISO_API_BYPASS = [
    'api.devices.register' => 'pairing: the one-time code decides the workspace',
    'api.webhooks.stt' => 'provider callback: found by transcript id and verified signature',
    'api.recordings.audio' => 'signed, expiring URL issued to a device of the workspace',
];

/** Named web routes outside Filament, each with the reason it is safe without a workspace. */
const ISO_WEB_BYPASS = [
    'share.show' => 'public share link: the token is the only key',
    'share.unlock' => 'public share link: the token is the only key',
    'share.audio' => 'public share link: the token is the only key',
    'passkeys.login.options' => 'sign-in, before any workspace',
    'passkeys.login' => 'sign-in, before any workspace',
];

const ISO_SECRETS = ['BOB-SECRET-TITLE', 'BOBSECRETTRANSCRIPT', 'BOB-SECRET-CLIENT', 'BOB-SECRET-DEVICE', 'BOBSECRETTERM', 'bobsecretlabel', 'BOBSECRETNOTE'];

/** @return array<string, mixed> */
function isoWorld(): array
{
    $alice = Fx::superAdmin();
    $aliceDevice = Fx::device($alice, ['name' => 'Alice phone']);
    Fx::readyRecording(Fx::session($alice, $aliceDevice, ['title' => 'Alice call']), 'alice audio');

    $bob = Fx::user();
    $device = Fx::device($bob, ['name' => 'BOB-SECRET-DEVICE']);
    $client = Fx::client($bob, 'BOB-SECRET-CLIENT');
    $client->keyterms()->create(['term' => 'BOBSECRETTERM']);
    $session = Fx::session($bob, $device, ['title' => 'BOB-SECRET-TITLE', 'client_id' => $client->id]);
    $recording = Fx::readyRecording($session, 'BOB-AUDIO-'.random_bytes(64));
    $transcript = Fx::transcript($session, 'BOBSECRETTRANSCRIPT spoken here');
    $marker = SessionMarker::query()->create(['capture_session_id' => $session->id, 'at_ms' => 1000, 'note' => 'BOBSECRETNOTE', 'source' => 'device']);
    SessionLabel::query()->create(['capture_session_id' => $session->id, 'label' => 'bobsecretlabel']);
    ['link' => $share] = app(ShareLinks::class)->create($session, 3600, null, false, 'Bob');
    $binned = Fx::session($bob, $device, ['title' => 'BOB-SECRET-TITLE binned']);
    $binned->delete();
    $uploading = Fx::session($bob, $device, ['title' => 'BOB-SECRET-TITLE uploading']);
    $upload = Fx::pendingRecording($uploading, str_repeat('b', 2048), 1024);
    app('auth')->forgetGuards();

    return compact('alice', 'aliceDevice', 'bob', 'device', 'client', 'session', 'recording', 'transcript', 'marker', 'share', 'binned', 'upload');
}

/** Every id of workspace B; none may appear in an answer to workspace A. */
function isoIds(array $b): array
{
    return [$b['device']->id, $b['client']->id, $b['session']->id, $b['recording']->id, $b['transcript']->id, $b['marker']->id, $b['share']->id, $b['binned']->id, $b['upload']->id];
}

/**
 * API cases by route name: [method, uri, body, expectation]. `404` = the documented not-found answer; `list` = a
 * normal answer for A's own data; a closure decides for answers with a special shape.
 *
 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: int|string|Closure(TestResponse): bool}>
 */
function isoApiCases(array $b): array
{
    $s = $b['session']->id;
    $t = $b['transcript']->id;

    return [
        'api.devices.rotate' => ['POST', '/api/devices/rotate-token', [], 'list'],
        'api.config' => ['GET', '/api/config', [], 'list'],
        'api.security' => ['GET', '/api/security', [], 'list'],
        'api.clients' => ['GET', '/api/clients', [], 'list'],
        'api.stt.token' => ['POST', '/api/stt/token', ['use_case' => 'live_captions', 'session_id' => $s], 404],
        'api.usage.store' => ['POST', '/api/usage', ['report_id' => 'iso-report-1', 'use_case' => 'dictation', 'provider' => 'assemblyai', 'session_id' => $s, 'streamed_ms' => 1000, 'channels' => 1],
            fn (TestResponse $r): bool => $r->status() === 201 && $r->json('report.session_id') === null],
        'api.sessions.store' => ['POST', '/api/sessions', Fx::sessionPayload(['client_id' => $b['client']->id]), 404],
        'api.sessions.finish' => ['POST', "/api/sessions/{$s}/finish", ['ended_at' => '2026-10-24T09:10:00Z', 'duration_ms' => 1000, 'artifacts' => [Fx::artifact()]], 404],
        'api.sessions.recordings' => ['POST', "/api/sessions/{$s}/recordings", Fx::artifact(['role' => 'channel_1']), 404],
        'api.sessions.transcripts' => ['POST', "/api/sessions/{$s}/transcripts", ['kind' => 'live', 'provider' => 'x', 'segments' => Fx::segments('HIJACK')], 404],
        'api.sessions.destroy' => ['DELETE', "/api/sessions/{$s}", [], 404],
        'api.sessions.update' => ['PATCH', "/api/sessions/{$s}", ['title' => 'hijacked'], 404],
        'api.sessions.bulk' => ['POST', '/api/sessions/bulk', ['ids' => [$s], 'action' => 'delete'], fn (TestResponse $r): bool => $r->status() === 200 && $r->json('changed') === 0],
        'api.clients.store' => ['POST', '/api/clients', ['name' => 'Alice new client'], fn (TestResponse $r): bool => $r->status() === 201],
        'api.clients.update' => ['PATCH', "/api/clients/{$b['client']->id}", ['name' => 'hijacked'], 404],
        'api.devices.revoke' => ['DELETE', "/api/devices/{$b['device']->id}", [], 404],
        'api.sessions.restore' => ['POST', "/api/sessions/{$b['binned']->id}/restore", [], 404],
        'api.bin.destroy' => ['DELETE', "/api/bin/{$b['binned']->id}", [], 404],
        'api.sessions.markers.store' => ['POST', "/api/sessions/{$s}/markers", ['at_ms' => 10], 404],
        'api.sessions.retranscribe' => ['POST', "/api/sessions/{$s}/retranscribe", ['language' => 'ur'], 404],
        'api.sessions.markers.destroy' => ['DELETE', "/api/sessions/{$s}/markers/{$b['marker']->id}", [], 404],
        'api.sessions.speakers' => ['PUT', "/api/sessions/{$s}/speakers", ['names' => ['A' => 'X']], 404],
        'api.sessions.shares.store' => ['POST', "/api/sessions/{$s}/shares", ['expires_in' => 3600], 404],
        'api.shares.destroy' => ['DELETE', "/api/shares/{$b['share']->id}", [], 404],
        'api.vocabulary.keyterms' => ['POST', '/api/vocabulary/keyterms', ['term' => 'Hijack', 'client_id' => $b['client']->id], 404],
        'api.vocabulary.replacements' => ['POST', '/api/vocabulary/replacements', ['from' => 'a', 'to' => 'b', 'client_id' => $b['client']->id], 404],
        'api.transcripts.update' => ['PATCH', "/api/transcripts/{$t}", ['edits' => [['index' => 0, 'text' => 'hijacked']]], 404],
        'api.transcripts.replace' => ['POST', "/api/transcripts/{$t}/replace", ['find' => 'BOB', 'replace' => 'X'], 404],
        'api.transcripts.restore' => ['POST', "/api/transcripts/{$t}/restore", ['revision_id' => 1], 404],
        'api.library' => ['GET', '/api/library?since=1970-01-01T00:00:00Z&limit=100&include_deleted=1', [], 'list'],
        'api.library.views' => ['GET', '/api/library/views', [], 'list'],
        'api.bin' => ['GET', '/api/bin', [], 'list'],
        'api.storage' => ['GET', '/api/storage', [], fn (TestResponse $r): bool => $r->status() === 200 && $r->json('recordings_count') === 1],
        'api.activity' => ['GET', '/api/activity', [], 'list'],
        'api.activity.read' => ['POST', '/api/activity/read', [], 'list'],
        'api.sessions.export' => ['POST', '/api/sessions/export', ['ids' => [$s], 'format' => 'txt'],
            // Same answer as for unknown ids: nothing of the workspace matched.
            fn (TestResponse $r): bool => $r->status() === 409 && $r->json('code') === 'nothing_to_export'],
        'api.search' => ['GET', '/api/search?q=BOBSECRETTRANSCRIPT', [], 'list'],
        'api.sessions.show' => ['GET', "/api/sessions/{$s}", [], 404],
        'api.sessions.opened' => ['POST', "/api/sessions/{$s}/opened", [], 404],
        // Live Translate holds no workspace data; without a Soniox key it says so and leaks nothing of the other one.
        'api.live-translate.token' => ['POST', '/api/live-translate/token', ['mode' => 'game', 'target_language' => 'en'],
            fn (TestResponse $r): bool => $r->status() === 503 && $r->json('code') === 'provider_not_configured'],
        'api.transcripts.show' => ['GET', "/api/transcripts/{$t}", [], 404],
        'api.transcripts.revisions' => ['GET', "/api/transcripts/{$t}/revisions", [], 404],
        'api.transcripts.export' => ['GET', "/api/transcripts/{$t}/export?format=txt", [], 404],
        'api.transcripts.views' => ['GET', "/api/transcripts/{$t}/views", [], 404],
        'api.transcripts.views.make' => ['POST', "/api/transcripts/{$t}/views", [], 404],
        'api.sessions.shares' => ['GET', "/api/sessions/{$s}/shares", [], 404],
        'api.vocabulary' => ['GET', '/api/vocabulary?client_id='.$b['client']->id, [], 404],
        'api.recordings.show' => ['GET', "/api/recordings/{$b['upload']->id}", [], 404],
        'api.recordings.chunks' => ['POST', "/api/recordings/{$b['upload']->id}/chunks", [], 404],
        'api.recordings.complete' => ['POST', "/api/recordings/{$b['upload']->id}/complete", [], 404],
    ];
}

function isoLeaks(string $body, array $b): array
{
    return array_values(array_filter([...ISO_SECRETS, ...isoIds($b)], fn (string $needle): bool => str_contains($body, $needle)));
}

/** @return list<RouteDefinition> */
function isoRoutes(string $prefix): array
{
    return array_values(array_filter(Route::getRoutes()->getRoutes(), fn (RouteDefinition $r): bool => str_starts_with($r->uri(), $prefix)));
}

it('has an isolation case for every API route and a reason for every route without a device token', function () {
    $cases = isoApiCases(isoWorld());
    $missing = [];
    foreach (isoRoutes('api/') as $route) {
        $name = (string) $route->getName();
        $sanctum = in_array('auth:sanctum', $route->gatherMiddleware(), true);
        if (array_key_exists($name, ISO_API_BYPASS)) {
            expect($sanctum)->toBeFalse("{$name} is listed as a bypass but needs a device token");

            continue;
        }
        expect($sanctum)->toBeTrue("{$name} has no device token and no documented bypass");
        if (! array_key_exists($name, $cases)) {
            $missing[] = $name;
        }
    }

    expect($missing)->toBe([]);
});

it('never lets workspace A read, change or delete workspace B through the API', function () {
    $b = isoWorld();
    $failures = [];
    foreach (isoApiCases($b) as $name => [$method, $uri, $body, $expect]) {
        $response = $this->api(Fx::token($b['aliceDevice']))->json($method, $uri, $body);
        $ok = match (true) {
            $expect === 404 => $response->status() === 404,
            $expect === 'list' => $response->status() < 400,
            default => $expect($response),
        };
        if (! $ok) {
            $failures[] = "{$name}: unexpected {$response->status()} ".mb_substr((string) $response->getContent(), 0, 160);
        }
        if (($leaks = isoLeaks((string) $response->getContent(), $b)) !== []) {
            $failures[] = "{$name}: leaked ".implode(', ', $leaks);
        }
    }

    expect($failures)->toBe([]);
    unscoped(function () use ($b): void {
        expect(CaptureSession::query()->find($b['session']->id)?->title)->toBe('BOB-SECRET-TITLE')
            ->and(CaptureSession::onlyTrashed()->find($b['binned']->id)?->media_erased_at)->toBeNull()
            ->and(Client::query()->find($b['client']->id)?->name)->toBe('BOB-SECRET-CLIENT')
            ->and(Device::query()->find($b['device']->id))->not->toBeNull()
            ->and(ShareLink::query()->find($b['share']->id)?->revoked_at)->toBeNull()
            ->and(SessionMarker::query()->find($b['marker']->id))->not->toBeNull()
            ->and($b['transcript']->fresh()->text)->toContain('BOBSECRETTRANSCRIPT');
    });
});

it('answers 404 to workspace A on every console route outside Filament that takes an id', function () {
    $b = isoWorld();
    $upload = $b['upload']->id;
    // [method, url, body, status]
    $cases = [
        'admin.recordings.stream' => ['GET', route('admin.recordings.stream', $b['recording']), [], 404],
        'admin.transcripts.export' => ['GET', route('admin.transcripts.export', [$b['transcript']->id, 'txt']), [], 404],
        // Library → Upload: B's audio is not "already in the Library" of A, and B's client cannot be chosen.
        'admin.uploads.store' => ['POST', route('admin.uploads.store'), ['name' => 'x.wav', 'size' => 4096, 'sha256' => $b['recording']->sha256, 'client_id' => $b['client']->id], 422],
        'admin.uploads.show' => ['GET', route('admin.uploads.show', $upload), [], 404],
        'admin.uploads.chunk' => ['POST', route('admin.uploads.chunk', [$upload, 0]), [], 404],
        'admin.uploads.complete' => ['POST', route('admin.uploads.complete', $upload), [], 404],
        'admin.uploads.destroy' => ['DELETE', route('admin.uploads.destroy', $upload), [], 404],
    ];
    $named = collect(Route::getRoutes()->getRoutes())->map(fn (RouteDefinition $r): ?string => $r->getName())->filter()
        ->reject(fn (string $n): bool => str_starts_with($n, 'filament.') || str_starts_with($n, 'livewire.') || str_starts_with($n, 'default-livewire.') || str_starts_with($n, 'api.')
            || in_array($n, ['storage.local', 'storage.local.upload'], true) || str_starts_with($n, 'ignition.') || $n === 'up');
    expect($named->reject(fn (string $n): bool => array_key_exists($n, $cases) || array_key_exists($n, ISO_WEB_BYPASS))->values()->all())->toBe([]);

    $this->actingAs($b['alice'], 'web');
    foreach ($cases as $name => [$method, $url, $body, $status]) {
        $response = $method === 'GET' ? $this->get($url) : $this->json($method, $url, $body);
        expect($response->status())->toBe($status, $name)->and(isoLeaks((string) $response->getContent(), $b))->toBe([], $name);
    }
    expect(unscoped(fn (): int => CaptureSession::query()->where('user_id', $b['alice']->id)->where('source_device', 'web')->count()))->toBe(0);
});

it('never shows workspace B in any console resource, page, widget or global search, and 404s its records', function () {
    $b = isoWorld();
    $this->actingAs($b['alice'], 'web');
    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    $records = [CaptureSession::class => $b['session'], Client::class => $b['client'], Device::class => $b['device']];
    $failures = [];

    foreach ($panel->getResources() as $resource) {
        $record = $records[$resource::getModel()] ?? null;
        if ($record === null) {
            $failures[] = "{$resource}: no isolation case for model ".$resource::getModel();

            continue;
        }
        foreach (array_keys($resource::getPages()) as $page) {
            $url = in_array($page, ['index', 'create'], true) ? $resource::getUrl($page) : $resource::getUrl($page, ['record' => $record]);
            $response = $this->get($url);
            $expected = in_array($page, ['index', 'create'], true) ? 200 : 404;
            if ($response->status() !== $expected || isoLeaks((string) $response->getContent(), $b) !== []) {
                $failures[] = "{$resource} {$page}: {$response->status()} ".implode(', ', isoLeaks((string) $response->getContent(), $b));
            }
        }
        $list = $resource::getPages()['index']->getPage();
        try {
            Livewire::test($list)->callAction(TestAction::make(DeleteAction::class)->table($record));
        } catch (Throwable) {
            // refusing loudly is fine; what matters is that nothing changed
        }
    }

    foreach ($panel->getPages() as $page) {
        $response = $this->get($page::getUrl());
        if (! in_array($response->status(), [200, 403], true) || ($leaks = isoLeaks((string) $response->getContent(), $b)) !== []) {
            $failures[] = "{$page}: {$response->status()} ".implode(', ', $leaks ?? []);
        }
    }

    foreach ($panel->getWidgets() as $widget) {
        $html = Livewire::test($widget)->html();
        if (($leaks = isoLeaks($html, $b)) !== []) {
            $failures[] = "{$widget}: ".implode(', ', $leaks);
        }
    }

    foreach (['BOB', 'BOB-SECRET-TITLE', 'BOBSECRETTRANSCRIPT', 'BOB-SECRET-CLIENT'] as $query) {
        $results = app(CommandPalette::class)->getResults($query);
        if (($leaks = isoLeaks((string) json_encode($results?->getCategories()->map(fn ($c) => collect($c)->map(fn ($r) => [$r->title, $r->url])->all())->all()), $b)) !== []) {
            $failures[] = "global search {$query}: ".implode(', ', $leaks);
        }
    }

    expect($failures)->toBe([]);
    unscoped(fn () => expect(CaptureSession::query()->find($b['session']->id))->not->toBeNull()
        ->and(Client::query()->find($b['client']->id))->not->toBeNull()
        ->and(Device::query()->find($b['device']->id))->not->toBeNull());
});

it('keeps workspace B usable for B: the same routes answer B normally', function () {
    $b = isoWorld();

    $library = $this->api(Fx::token($b['device']))->getJson('/api/library?since=1970-01-01T00:00:00Z&limit=100')->assertOk();
    expect(collect($library->json('data'))->pluck('id')->all())->toContain($b['session']->id);
    $this->api(Fx::token($b['device']))->getJson("/api/sessions/{$b['session']->id}")->assertOk();
    $this->api(Fx::token($b['device']))->getJson("/api/transcripts/{$b['transcript']->id}")->assertOk();
    expect(User::query()->find($b['bob']->id)?->current_workspace_id)->not->toBe($b['alice']->current_workspace_id);
});
