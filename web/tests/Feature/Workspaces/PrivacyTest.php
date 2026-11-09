<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Filament\Support\CommandPalette;
use App\Jobs\MakeCallNotesJob;
use App\Mail\ShareReceivedMail;
use App\Models\CallNote;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\ClientMemoryItem;
use App\Models\FilingRule;
use App\Models\Screen;
use App\Models\SessionMarker;
use App\Models\ShareLink;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Callers\CallerMatcher;
use App\Services\ClientAi\ClientWorkspace;
use App\Services\CredentialVault;
use App\Services\Projects\FilingRules;
use App\Services\ShareLinks;
use App\Services\Sharing\RequestLinks;
use App\Services\Workspaces\Workspaces;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
| Private by default (since v1.5, docs/API.md "Workspaces, roles and privacy"): a person's recordings and projects are
| theirs alone. The owner of the workspace (a super admin too, so every console page opens) gets the same 404 as for
| an unknown id on every route, with the case list of WorkspaceIsolationTest; shares and Send to a teammate are the only
| ways across.
*/

uses(ApiClient::class);

beforeEach(fn () => Fx::fakeRecordingsDisk());
afterEach(fn () => Fx::cleanupDisks());

require_once __DIR__.'/Support/isolation_cases.php';

/** What of Bob's the owner may see: his device, to revoke it (not content). */
function privacyLeaks(string $body, array $b): array
{
    $allowed = ['BOB-SECRET-DEVICE', $b['device']->id];

    return array_values(array_diff(isoLeaks($body, $b), $allowed));
}

/** The owner's role when several tests look at it. */
function privacyWorld(WorkspaceRole $actor = WorkspaceRole::Owner): array
{
    $b = isoWorld(sameWorkspace: true);
    if ($actor !== WorkspaceRole::Owner) {
        // Alice is what the actor is; the workspace keeps an owner apart from them.
        $team = Workspace::query()->findOrFail($b['alice']->current_workspace_id);
        $owner = withoutWorkspace(Fx::user());
        app(Workspaces::class)->addMember($team, $owner, WorkspaceRole::Owner);
        app(Workspaces::class)->setRole($team, $b['alice'], $actor);
    }

    return $b;
}

it('answers the owner of the workspace 404 for a member\'s content on every API route', function () {
    $b = privacyWorld();
    expect($b['session']->workspace_id)->toBe($b['aliceDevice']->workspace_id);
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
            ->and(ShareLink::query()->find($b['share']->id)?->revoked_at)->toBeNull()
            ->and(SessionMarker::query()->find($b['marker']->id))->not->toBeNull()
            ->and(Screen::query()->find($b['screen']->id))->not->toBeNull()
            ->and($b['transcript']->fresh()->text)->toContain('BOBSECRETTRANSCRIPT');
    });
});

it('answers an admin of the workspace the same 404 on the routes of a recording', function () {
    $b = privacyWorld(WorkspaceRole::Admin);
    $s = $b['session']->id;
    $t = $b['transcript']->id;
    $token = Fx::token($b['aliceDevice']);

    foreach (["/api/sessions/{$s}", "/api/transcripts/{$t}", "/api/sessions/{$s}/screens", "/api/sessions/{$s}/pack?format=html", "/api/transcripts/{$t}/export?format=txt",
        "/api/sessions/{$s}/shares", "/api/clients/{$b['client']->id}/workspace", "/api/clients/{$b['client']->id}/bundle"] as $uri) {
        $this->api($token)->getJson($uri)->assertNotFound();
    }
    expect(collect($this->api($token)->getJson('/api/library?limit=100')->json('data'))->pluck('id')->all())->not->toContain($s)
        ->and((string) $this->api($token)->getJson('/api/search?q=BOBSECRETTRANSCRIPT')->getContent())->not->toContain('BOB');
});

it('answers the owner 404 on every console route of a member\'s recording', function () {
    $b = privacyWorld();
    $this->actingAs($b['alice'], 'web');

    foreach ([
        route('admin.recordings.stream', $b['recording']),
        route('admin.transcripts.export', [$b['transcript']->id, 'txt']),
        route('admin.screens.media', [$b['screen']->id, 'image']),
        route('admin.sessions.pack', [$b['session']->id, 'html']),
        route('admin.uploads.show', $b['upload']->id),
    ] as $url) {
        expect($this->get($url)->status())->toBe(404, $url);
    }
    foreach ([route('filament.app.resources.sessions.view', ['record' => $b['session']->id]), route('filament.app.resources.clients.view', ['record' => $b['client']->id])] as $url) {
        expect($this->get($url)->status())->toBe(404, $url);
    }
});

it('never shows a member\'s content to the owner on any console page, widget or global search', function () {
    $b = privacyWorld();
    $this->actingAs($b['alice'], 'web');
    $panel = Filament::getPanel('app');
    Filament::setCurrentPanel($panel);
    $failures = [];

    foreach ($panel->getPages() as $page) {
        if (str_contains($page::getSlug(), '{')) {
            continue; // one upgrade request's pages: the person's own only (UpgradeFlowPagesTest)
        }
        $response = $this->get($page::getUrl());
        if (! in_array($response->status(), [200, 403], true) || ($leaks = privacyLeaks((string) $response->getContent(), $b)) !== []) {
            $failures[] = "{$page}: {$response->status()} ".implode(', ', $leaks ?? []);
        }
    }
    foreach ($panel->getResources() as $resource) {
        $html = (string) $this->get($resource::getUrl('index'))->getContent();
        if (($leaks = privacyLeaks($html, $b)) !== []) {
            $failures[] = "{$resource}: ".implode(', ', $leaks);
        }
    }
    foreach ($panel->getWidgets() as $widget) {
        if (($leaks = privacyLeaks(Livewire::test($widget)->html(), $b)) !== []) {
            $failures[] = "{$widget}: ".implode(', ', $leaks);
        }
    }
    foreach (['BOB', 'BOB-SECRET-TITLE', 'BOBSECRETTRANSCRIPT', 'BOB-SECRET-CLIENT'] as $query) {
        $results = app(CommandPalette::class)->getResults($query);
        if (($leaks = privacyLeaks((string) json_encode($results?->getCategories()->map(fn ($c) => collect($c)->map(fn ($r) => [$r->title, $r->url])->all())->all()), $b)) !== []) {
            $failures[] = "global search {$query}: ".implode(', ', $leaks);
        }
    }

    expect($failures)->toBe([]);
});

it('keeps the member\'s library working for the member, in the same workspace', function () {
    $b = privacyWorld();
    $token = Fx::token($b['device']);

    $ids = collect($this->api($token)->getJson('/api/library?since=1970-01-01T00:00:00Z&limit=100')->assertOk()->json('data'))->pluck('id')->all();
    expect($ids)->toContain($b['session']->id)->not->toContain(CaptureSession::query()->where('title', 'Alice call')->value('id'));
    $this->api($token)->getJson("/api/sessions/{$b['session']->id}")->assertOk();
    $this->api($token)->getJson("/api/transcripts/{$b['transcript']->id}")->assertOk();
    $this->api($token)->getJson("/api/clients/{$b['client']->id}/workspace")->assertOk();
    expect($this->api($token)->getJson('/api/search?q=BOBSECRETTRANSCRIPT')->assertOk()->json('data'))->not->toBe([]);
});

describe('the ways across', function () {
    it('lets a member send a recording to the owner: read-only, and a copy when they save it', function () {
        Mail::fake();
        $b = privacyWorld();
        $bobToken = Fx::token($b['device']);

        $this->api($bobToken)->postJson("/api/sessions/{$b['session']->id}/send", ['to' => [$b['alice']->email]])->assertCreated();
        Mail::assertQueued(ShareReceivedMail::class);
        $this->app['auth']->forgetGuards();

        $alice = Fx::token($b['aliceDevice']);
        $this->api($alice)->getJson("/api/sessions/{$b['session']->id}")->assertOk()->assertJsonPath('data.client', null);
        $this->api($alice)->getJson("/api/transcripts/{$b['transcript']->id}")->assertOk();
        // Read-only: no edit, export or share route opens for the recipient.
        $this->api($alice)->patchJson("/api/sessions/{$b['session']->id}", ['title' => 'hijacked'])->assertNotFound();
        $this->api($alice)->getJson("/api/transcripts/{$b['transcript']->id}/export?format=txt")->assertNotFound();
        $this->api($alice)->postJson("/api/sessions/{$b['session']->id}/shares", ['expires_in' => 3600])->assertNotFound();

        $sharedId = $this->api($alice)->getJson('/api/shared-with-me')->assertOk()->json('data.0.id');
        $copy = $this->api($alice)->postJson("/api/shared-with-me/{$sharedId}/save")->assertCreated()->json('session_id');
        $this->api($alice)->getJson("/api/sessions/{$copy}")->assertOk();
        expect(unscoped(fn () => CaptureSession::query()->find($copy)?->user_id))->toBe($b['alice']->id);
    });

    it('lets a member send a project to the owner, and only what they filed in it comes along', function () {
        Mail::fake();
        $b = privacyWorld();
        // Filed in Bob's project before v1.5 by someone else: it stays that person's.
        $stray = Fx::session($b['alice'], $b['aliceDevice'], ['title' => 'Alice stray', 'client_id' => $b['client']->id]);

        $this->api(Fx::token($b['device']))->postJson("/api/clients/{$b['client']->id}/send", ['to' => [$b['alice']->email]])->assertCreated();
        $this->app['auth']->forgetGuards();

        $ids = collect($this->api(Fx::token($b['aliceDevice']))->getJson('/api/library?folder=shared&limit=100')->assertOk()->json('data'))->pluck('id')->all();
        expect($ids)->toContain($b['session']->id)->not->toContain($stray->id);
    });

    it('opens a member\'s share link for anyone, the owner of the workspace signed in or not', function () {
        $b = privacyWorld();
        $this->get($b['shareUrl'])->assertOk()->assertSee('BOB-SECRET-TITLE');

        $this->actingAs($b['alice'], 'web');
        $this->get($b['shareUrl'])->assertOk()->assertSee('BOB-SECRET-TITLE');
    });

    it('opens a people-only link for a listed person in the same workspace, and not for anyone else in it', function () {
        $b = privacyWorld();
        $options = ['audience' => 'people', 'include_audio' => false, 'allow_download' => false, 'expires_at' => null];
        ['url' => $listed] = app(ShareLinks::class)->make($b['session'], [...$options, 'people' => [$b['alice']->email]], 'Bob');
        ['url' => $other] = app(ShareLinks::class)->make($b['session'], [...$options, 'people' => ['someone@example.test']], 'Bob');
        app('auth')->forgetGuards();

        $this->actingAs($b['alice'], 'web');
        $this->get($listed)->assertOk()->assertSee('BOB-SECRET-TITLE');
        expect((string) $this->get($other)->getContent())->not->toContain('BOB-SECRET-TITLE');
    });

    it('resolves a member\'s link for the owner as nothing of theirs', function () {
        $b = privacyWorld();

        $this->api(Fx::token($b['aliceDevice']))->getJson('/api/links/resolve?url='.rawurlencode($b['shareUrl']))->assertNotFound();
    });
});

describe('what one person sets up stays out of the others\' way', function () {
    it('lets two people of a workspace have a top-level project of the same name', function () {
        $b = privacyWorld();

        $this->api(Fx::token($b['aliceDevice']))->postJson('/api/clients', ['name' => 'BOB-SECRET-CLIENT'])->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->api(Fx::token($b['device']))->postJson('/api/clients', ['name' => 'BOB-SECRET-CLIENT'])->assertStatus(422);
    });

    it('files a recording only by its maker\'s rules, and two people may have the same rule', function () {
        $b = privacyWorld();
        $aliceProject = Fx::client($b['alice'], 'Alice client');
        app(FilingRules::class)->add($b['client'], 'title', 'standup');
        // The same rule on Alice's project is hers: no rule_exists naming Bob's project.
        app(FilingRules::class)->add($aliceProject, 'title', 'standup');
        expect(unscoped(fn () => FilingRule::query()->where('match_value', 'standup')->count()))->toBe(2);

        $mine = Fx::session($b['alice'], $b['aliceDevice'], ['title' => 'Weekly standup']);
        $his = Fx::session($b['bob'], $b['device'], ['title' => 'Weekly standup']);
        app(FilingRules::class)->apply($mine);
        app(FilingRules::class)->apply($his);

        expect($mine->fresh()->client_id)->toBe($aliceProject->id)->and($his->fresh()->client_id)->toBe($b['client']->id);
    });

    it('matches a caller only among the projects of the recording\'s maker', function () {
        $b = privacyWorld();

        expect(app(CallerMatcher::class)->match($b['session']->workspace_id, 'BOB-SECRET-CLIENT', null, $b['alice']->id)['client'])->toBeNull()
            ->and(app(CallerMatcher::class)->match($b['session']->workspace_id, 'BOB-SECRET-CLIENT', null, $b['bob']->id)['client']?->id)->toBe($b['client']->id);
    });

    it('does not report a file another person has as a duplicate', function () {
        $b = privacyWorld();
        $body = Fx::sessionPayload(['skip_duplicate' => true, 'artifacts' => [Fx::artifact(['sha256' => $b['recording']->sha256, 'size_bytes' => 4096])]]);

        $this->api(Fx::token($b['aliceDevice']))->postJson('/api/sessions', $body)->assertCreated();
    });

    it('takes a request link down when its person becomes a viewer or leaves', function () {
        $b = privacyWorld();
        ['token' => $token, 'link' => $link] = (function () use ($b): array {
            $made = app(RequestLinks::class)->create($b['client']->workspace_id, $b['bob']->id, $b['client'], 'Send', null, 20, 100);

            return ['token' => basename($made['url']), 'link' => $made['link']];
        })();
        $team = Workspace::query()->findOrFail($b['alice']->current_workspace_id);
        expect(app(RequestLinks::class)->usable($token)?->id)->toBe($link->id);

        app(Workspaces::class)->setRole($team, $b['bob'], WorkspaceRole::Viewer);
        expect(app(RequestLinks::class)->usable($token))->toBeNull();
    });
});

it('leaves a removed member\'s content in the workspace, seen by nobody, and theirs again when they come back', function () {
    $b = privacyWorld();
    $team = Workspace::query()->findOrFail($b['alice']->current_workspace_id);
    app(Workspaces::class)->createFor($b['bob'], 'Own');

    app(Workspaces::class)->removeMember($team, $b['bob']);

    expect(unscoped(fn () => CaptureSession::query()->find($b['session']->id)?->workspace_id))->toBe($team->id);
    $this->actingAs(User::query()->findOrFail($b['alice']->id), 'web');
    expect($this->get(route('filament.app.resources.sessions.view', ['record' => $b['session']->id]))->status())->toBe(404);

    app(Workspaces::class)->addMember($team, $b['bob']->fresh());
    app(Workspaces::class)->switchTo($b['bob']->fresh(), $team);
    $this->actingAs($b['bob']->fresh(), 'web');
    expect($this->get(route('filament.app.resources.sessions.view', ['record' => $b['session']->id]))->status())->toBe(200);
});

it('keeps the notes and memory lines of a member\'s recording filed under the owner\'s project before v1.5 out of that project', function () {
    Queue::fake();
    app(CredentialVault::class)->put('anthropic', 'api_key', 'sk-ant-test-1234567890');
    $b = privacyWorld();
    $alices = Fx::client($b['alice'], 'Alice project');
    $alices->forceFill(['ai_notes' => true])->save();
    // Projects were shared before v1.5: Bob filed his call under Alice's project, and its notes were written there.
    unscoped(fn () => CaptureSession::acrossPeople()->whereKey($b['session']->id)->update(['client_id' => $alices->id]));
    $note = CallNote::query()->withoutGlobalScopes()->create(['workspace_id' => $alices->workspace_id, 'client_id' => $alices->id, 'capture_session_id' => $b['session']->id,
        'status' => 'ready', 'sections' => ['decisions' => [['text' => 'BOBSECRETNOTE decided', 'verify' => false, 'lines' => [0], 'at_ms' => 0]]]]);
    $line = ClientMemoryItem::query()->withoutGlobalScopes()->create(['workspace_id' => $alices->workspace_id, 'client_id' => $alices->id, 'kind' => 'decision',
        'text' => 'BOBSECRETNOTE in memory', 'source_type' => 'call', 'source_id' => $b['session']->id]);
    ClientMemoryItem::query()->withoutGlobalScopes()->create(['workspace_id' => $alices->workspace_id, 'client_id' => $alices->id, 'kind' => 'scope',
        'text' => 'Alice wrote this', 'source_type' => 'user']);
    $alice = $this->api(Fx::token($b['aliceDevice']));

    $workspace = $alice->getJson("/api/clients/{$alices->id}/workspace")->assertOk();
    expect($workspace->getContent())->not->toContain('BOBSECRETNOTE')->and($workspace->json('data.memory.0.text'))->toBe('Alice wrote this')
        ->and($workspace->json('data.notes'))->toBe([])
        ->and($alice->getJson("/api/clients/{$alices->id}/bundle")->assertOk()->getContent())->not->toContain('BOBSECRETNOTE');
    $alice->patchJson("/api/clients/{$alices->id}/memory/{$line->id}", ['pinned' => true])->assertNotFound()->assertJsonPath('code', 'memory_not_found');
    $this->app['auth']->forgetGuards();
    $this->actingAs($b['alice'], 'web');
    $page = $this->get(route('filament.app.resources.clients.view', ['record' => $alices->id]))->assertOk();
    expect((string) $page->getContent())->not->toContain('BOBSECRETNOTE');

    // Nothing new is written there either: a new transcript or Bob asking for notes again.
    app(ClientWorkspace::class)->afterTranscript($b['transcript']->fresh());
    $this->app['auth']->forgetGuards();
    $this->api(Fx::token($b['device']))->postJson("/api/sessions/{$b['session']->id}/notes")->assertStatus(409)->assertJsonPath('code', 'no_client');
    Queue::assertNotPushed(MakeCallNotesJob::class);
    expect($note->fresh()->status)->toBe('ready')->and($note->fresh()->client_id)->toBe($alices->id);
});
