<?php

declare(strict_types=1);

use App\Filament\Support\CommandPalette;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Device;
use App\Models\Screen;
use App\Models\SessionMarker;
use App\Models\ShareLink;
use App\Models\User;
use App\Services\ActivityFeed;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;
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

require_once __DIR__.'/Support/isolation_cases.php';

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
            ->and(Screen::query()->find($b['screen']->id))->not->toBeNull()
            ->and($b['transcript']->fresh()->text)->toContain('BOBSECRETTRANSCRIPT');
    });
});

it('answers 404 to workspace A on every console route outside Filament that takes an id', function () {
    $b = isoWorld();
    $upload = $b['upload']->id;
    app(ActivityFeed::class)->post($b['bob'], 'transcript_ready', 'BOB-SECRET-TITLE is ready', 'BOBSECRETNOTE', $b['session'], 'heroicon-o-check-circle');
    $bobNotice = (string) $b['bob']->notifications()->latest()->value('id');
    // [method, url, body, status]
    $cases = [
        'admin.recordings.stream' => ['GET', route('admin.recordings.stream', $b['recording']), [], 404],
        'admin.transcripts.export' => ['GET', route('admin.transcripts.export', [$b['transcript']->id, 'txt']), [], 404],
        'admin.screens.media' => ['GET', route('admin.screens.media', [$b['screen']->id, 'image']), [], 404],
        'admin.sessions.pack' => ['GET', route('admin.sessions.pack', [$b['session']->id, 'html']), [], 404],
        // Library → Upload: B's audio is not "already in the Library" of A, and B's client cannot be chosen.
        'admin.uploads.store' => ['POST', route('admin.uploads.store'), ['name' => 'x.wav', 'size' => 4096, 'sha256' => $b['recording']->sha256, 'client_id' => $b['client']->id], 422],
        'admin.uploads.show' => ['GET', route('admin.uploads.show', $upload), [], 404],
        'admin.uploads.chunk' => ['POST', route('admin.uploads.chunk', [$upload, 0]), [], 404],
        'admin.uploads.complete' => ['POST', route('admin.uploads.complete', $upload), [], 404],
        'admin.uploads.destroy' => ['DELETE', route('admin.uploads.destroy', $upload), [], 404],
        // The switcher only knows the person's own workspaces.
        'admin.workspaces.switch' => ['POST', route('admin.workspaces.switch', (int) $b['bob']->current_workspace_id), [], 404],
        // Since v1.6.1: a notification of the bell opened; B's is not A's to open (nor to mark read).
        'notices.open' => ['GET', route('notices.open', $bobNotice), [], 404],
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
    expect(unscoped(fn (): int => CaptureSession::query()->where('user_id', $b['alice']->id)->where('source_device', 'web')->count()))->toBe(0)
        ->and($b['bob']->notifications()->whereKey($bobNotice)->value('read_at'))->toBeNull();
});

it('never shows workspace B in any console resource, page, widget or global search, and 404s its records', function () {
    $b = isoWorld();
    $this->actingAs($b['alice'], 'web');
    $panel = Filament::getPanel('app');
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
