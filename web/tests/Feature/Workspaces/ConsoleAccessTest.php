<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Filament\Pages\Activity;
use App\Filament\Pages\Plans;
use App\Filament\Pages\RecentlyDeleted;
use App\Filament\Pages\RequestLinksPage;
use App\Filament\Pages\Search;
use App\Filament\Pages\Security;
use App\Filament\Pages\ServerUsage;
use App\Filament\Pages\Settings;
use App\Filament\Pages\SharedWithMe;
use App\Filament\Pages\Shares;
use App\Filament\Pages\Upload;
use App\Filament\Pages\Usage;
use App\Filament\Pages\WorkspaceSettings;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Filament\Resources\CaptureSessions\Pages\ViewCaptureSession;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Devices\DeviceResource;
use App\Filament\Support\CommandPalette;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SettingsRepository;
use App\Services\Workspaces\ConsoleAccess;
use App\Services\Workspaces\Workspaces;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
| Console access (since v1.5): the super admin every page, owners and admins their workspace's, members and viewers a
| small read-only console (their library, Shared with me, profile and devices, the Workspace page, a member's usage)
| while Settings → "Members can use the console" is on, else a page that points to the apps.
*/

beforeEach(function () {
    Fx::fakeRecordingsDisk();
    Filament::setCurrentPanel('admin');
});
afterEach(fn () => Fx::cleanupDisks());

/** @return array{ws: Workspace, owner: User, admin: User, member: User, viewer: User} */
function consoleTeam(): array
{
    $owner = Fx::user();
    $ws = Workspace::query()->findOrFail($owner->current_workspace_id);
    $people = ['ws' => $ws, 'owner' => $owner];
    foreach ([WorkspaceRole::Admin, WorkspaceRole::Member, WorkspaceRole::Viewer] as $role) {
        $person = withoutWorkspace(Fx::user());
        app(Workspaces::class)->addMember($ws, $person, $role);
        $people[$role->value] = $person->fresh();
    }

    return $people;
}

/** The pages of the whole console, with whether a small-console member and viewer may open them. */
const SMALL_CONSOLE = [
    // url resolver => [member, viewer]
    'library' => [true, true],
    'shared' => [true, true],
    'workspace' => [true, true],
    'usage' => [true, false],
    'profile' => [true, true],
    'projects' => [false, false],
    'devices' => [false, false],
    'upload' => [false, false],
    'search' => [false, false],
    'activity' => [false, false],
    'shares' => [false, false],
    'requests' => [false, false],
    'deleted' => [false, false],
    'security' => [false, false],
    'settings' => [false, false],
    'plans' => [false, false],
    'server-usage' => [false, false],
];

function consoleUrl(string $page): string
{
    return match ($page) {
        'library' => CaptureSessionResource::getUrl('index'),
        'shared' => SharedWithMe::getUrl(),
        'workspace' => WorkspaceSettings::getUrl(),
        'usage' => Usage::getUrl(),
        'profile' => (string) Filament::getPanel('admin')->getProfileUrl(),
        'projects' => ClientResource::getUrl('index'),
        'devices' => DeviceResource::getUrl('index'),
        'upload' => Upload::getUrl(),
        'search' => Search::getUrl(),
        'activity' => Activity::getUrl(),
        'shares' => Shares::getUrl(),
        'requests' => RequestLinksPage::getUrl(),
        'deleted' => RecentlyDeleted::getUrl(),
        'security' => Security::getUrl(),
        'settings' => Settings::getUrl(),
        'plans' => Plans::getUrl(),
        'server-usage' => ServerUsage::getUrl(),
    };
}

it('opens the small console to members and viewers, and nothing else of it', function () {
    $t = consoleTeam();
    $failures = [];
    foreach (['member' => 0, 'viewer' => 1] as $who => $column) {
        $this->actingAs($t[$who], 'web');
        foreach (SMALL_CONSOLE as $page => $allowed) {
            $status = $this->get(consoleUrl($page))->status();
            if (($status === 200) !== $allowed[$column]) {
                $failures[] = "{$who} {$page}: {$status}";
            }
        }
    }

    expect($failures)->toBe([]);
});

it('gives owners and admins their workspace\'s pages, and the server pages only to the super admin', function () {
    $t = consoleTeam();
    $serverPages = ['settings', 'plans', 'server-usage'];
    foreach (['owner', 'admin'] as $who) {
        $this->actingAs($t[$who], 'web');
        foreach (array_keys(SMALL_CONSOLE) as $page) {
            expect($this->get(consoleUrl($page))->status())->toBe(in_array($page, $serverPages, true) ? 403 : 200, "{$who} {$page}");
        }
    }
    $this->actingAs(Fx::superAdmin(), 'web');
    foreach (array_keys(SMALL_CONSOLE) as $page) {
        expect($this->get(consoleUrl($page))->status())->toBe(200, "super admin {$page}");
    }
});

it('lets a member open and play their recording, and change nothing of it in the console', function () {
    $t = consoleTeam();
    $session = Fx::session($t['member'], Fx::device($t['member']), ['title' => 'Member call', 'workspace_id' => $t['ws']->id]);
    $recording = Fx::readyRecording($session, 'member audio');
    $this->actingAs($t['member'], 'web');

    $this->get(CaptureSessionResource::getUrl('view', ['record' => $session]))->assertOk()->assertSee('Member call');
    $this->get(route('admin.recordings.stream', $recording))->assertOk();
    Livewire::test(ListCaptureSessions::class)
        ->assertCanSeeTableRecords([$session])
        ->assertActionDoesNotExist(TestAction::make(DeleteAction::class)->table($session))
        ->assertActionDoesNotExist('upload');
    $page = Livewire::test(ViewCaptureSession::class, ['record' => $session->id]);
    $page->call('togglePin')->assertForbidden();
    $page = Livewire::test(ViewCaptureSession::class, ['record' => $session->id]);
    $page->call('saveSegment', 0, 'hijacked')->assertForbidden();
    expect($session->fresh()->pinned)->toBeFalse();
    // No console upload either.
    $this->postJson(route('admin.uploads.store'), ['name' => 'x.wav', 'size' => 4096, 'sha256' => str_repeat('a', 64)])->assertForbidden();
});

it('keeps editing in the console for owners and admins', function () {
    $t = consoleTeam();
    $session = Fx::session($t['admin'], Fx::device($t['admin']), ['title' => 'Admin call', 'workspace_id' => $t['ws']->id]);
    $this->actingAs($t['admin'], 'web');

    Livewire::test(ListCaptureSessions::class)->assertActionVisible(TestAction::make(DeleteAction::class)->table($session));
    Livewire::test(ViewCaptureSession::class, ['record' => $session->id])->call('togglePin');
    expect($session->fresh()->pinned)->toBeTrue();
});

it('points members and viewers to the apps when Settings closes the console to them', function () {
    $t = consoleTeam();
    app(SettingsRepository::class)->set(ConsoleAccess::SETTING, false);
    $session = Fx::session($t['member'], null, ['workspace_id' => $t['ws']->id]);
    $recording = Fx::readyRecording($session, 'member audio');

    foreach (['member', 'viewer'] as $who) {
        $this->actingAs($t[$who], 'web');
        $this->get('/admin')->assertOk()->assertSee('Use the EchoLedger apps')->assertSee(route('downloads'));
        foreach (array_keys(SMALL_CONSOLE) as $page) {
            expect($this->get(consoleUrl($page))->status())->toBe($page === 'profile' ? 200 : 403, "{$who} {$page}");
        }
    }
    $this->actingAs($t['member'], 'web');
    $this->get(route('admin.recordings.stream', $recording))->assertForbidden();

    // Owners and admins keep the console.
    $this->actingAs($t['admin'], 'web');
    $this->get('/admin')->assertOk()->assertDontSee('Use the EchoLedger apps');
    $this->get(ClientResource::getUrl('index'))->assertOk();
});

it('has the switch on Settings, on by default', function () {
    expect(app(ConsoleAccess::class)->membersMayUse())->toBeTrue();
    $this->actingAs(Fx::superAdmin(), 'web');

    Livewire::test(Settings::class)->set('activeTab', 'privacy')->assertSee('Members can use the console')
        ->set('data.console_members', false)->call('save');

    expect(app(SettingsRepository::class)->get(ConsoleAccess::SETTING))->toBeFalse();
});

it('offers members only the pages they may open in the command palette and the bottom bar', function () {
    $t = consoleTeam();
    $this->actingAs($t['member'], 'web');

    $titles = collect(app(CommandPalette::class)->getResults('s')?->getCategories()->get('Go to'))->map(fn ($r) => $r->title)->all();
    expect($titles)->not->toContain('Settings', 'Projects', 'Devices', 'Search recordings');
    $html = (string) $this->get(CaptureSessionResource::getUrl('index'))->getContent();
    expect($html)->toContain('el-bottom-nav')->not->toContain(Upload::getUrl());
});
