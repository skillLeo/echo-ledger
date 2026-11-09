<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Filament\Pages\GetTheApps;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Support\CommandPalette;
use App\Filament\Support\Shell;
use App\Livewire\Shell\Sidebar;
use App\Models\CaptureSession;
use App\Models\Workspace;
use App\Services\Workspaces\Workspaces;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * The console's app shell (v1.6, P4.1): the desktop app's sidebar (brand, Find a page, Home · Library · Projects ·
 * Activity · Shared · Upload, then Help & shortcuts · Account · Collapse sidebar), its width, rail and style kept per
 * person, and recordings dropped on a project, the Inbox or Pinned. Every place is the person's own.
 */
beforeEach(function () {
    Filament::setCurrentPanel('app');
});

/** The labels of the sidebar's main navigation, in order. */
function shellNavLabels(string $html): array
{
    $nav = (string) preg_replace('/^.*?<nav class="el-sb-nav"|<\/nav>.*$/s', '', $html);
    preg_match_all('/<a href="[^"]*" class="el-sb-item[^"]*"[^>]*>.*?<span class="el-sb-label">\s*([^<]+?)\s*</s', $nav, $m);

    return $m[1];
}

describe('the sidebar', function () {
    it('lists Home, Library, Projects, Activity, Shared and Upload, then Help, Account and Collapse at the bottom', function () {
        $this->actingAs(Fx::user(), 'web');
        $html = (string) $this->get('/app')->assertOk()->getContent();

        expect(shellNavLabels($html))->toBe(['Home', 'Library', 'Projects', 'Activity', 'Shared', 'Upload'])
            ->and($html)->toContain('placeholder="Find a page"')
            ->toContain('Help &amp; shortcuts')->toContain('Collapse sidebar')->toContain('>Account<')
            ->toContain('role="separator"')->toContain('aria-label="Resize the sidebar"');
    });

    it('marks where you are with aria-current and a solid icon', function () {
        $this->actingAs(Fx::user(), 'web');
        $html = (string) $this->get('/app/sessions')->assertOk()->getContent();

        expect($html)->toMatch('/class="el-sb-item el-on"\s+aria-current="page"[^>]*>\s*(?:<!--.*?-->\s*)*<svg class="el-sb-icon"[^>]*fill="currentColor"/s');
        expect(substr_count($html, 'aria-current="page"'))->toBeGreaterThanOrEqual(1);
    });

    it('shows the smart folders with counts and the pinned projects, the person\'s own only', function () {
        $a = Fx::user();
        $b = Fx::user();
        $mine = Fx::client($a, 'Acme Holdings');
        $mine->forceFill(['pinned' => true])->save();
        $theirs = Fx::client($b, 'Rival Pinned Co');
        $theirs->forceFill(['pinned' => true])->save();
        Fx::session($a, null, ['source_app' => 'whatsapp', 'client_id' => $mine->id]);
        Fx::session($b, null, ['source_app' => 'zoom']);

        $this->actingAs($a, 'web');
        $html = (string) $this->get('/app')->assertOk()->getContent();

        expect($html)->toContain('aria-label="Smart folders"')->toContain('>WhatsApp<')->toContain('Acme Holdings')
            ->toContain('data-el-drop="project:'.$mine->id.'"')->toContain('data-el-drop="inbox"')->toContain('data-el-drop="pinned"')
            ->not->toContain('Rival Pinned Co')->not->toContain($theirs->id)->not->toContain('>Zoom<');
    });

    it('lists in Find a page only pages the person may open, and never the admin panel for a customer', function () {
        $this->actingAs(Fx::user(), 'web');
        $customer = collect(Shell::pages())->pluck('label')->all();
        expect($customer)->toContain('Home', 'Library', 'Devices', 'Preferences', 'Plan & usage', 'Privacy & data')->not->toContain('Admin panel');
        expect((string) $this->get('/app')->getContent())->not->toContain('"Admin panel"');

        $this->actingAs(Fx::superAdmin(), 'web');
        expect(collect(Shell::pages())->pluck('label')->all())->toContain('Admin panel');
    });

    it('gives the small console (a viewer) no Upload, no Projects and no drop targets', function () {
        $owner = Fx::user();
        $viewer = withoutWorkspace(Fx::user());
        app(Workspaces::class)->addMember(Workspace::query()->findOrFail($owner->current_workspace_id), $viewer, WorkspaceRole::Viewer);

        $this->actingAs($viewer->fresh(), 'web');
        $html = (string) $this->get('/app/sessions')->assertOk()->getContent();

        expect(shellNavLabels($html))->not->toContain('Upload')->not->toContain('Projects')
            ->and($html)->not->toContain('data-el-drop=');
    });
});

describe('the person\'s choices', function () {
    it('keeps the width (200–320), the rail, the open groups and the style, and refuses anything else', function () {
        $user = Fx::user();
        $this->actingAs($user, 'web');

        Livewire::test(Sidebar::class)
            ->call('rememberShell', 'width', 280)->assertReturned(true)
            ->call('rememberShell', 'collapsed', true)->assertReturned(true)
            ->call('rememberShell', 'open', ['library', 'nope'])->assertReturned(true)
            ->call('rememberShell', 'style', 'dark')->assertReturned(true)
            ->call('rememberShell', 'style', 'neon')->assertReturned(false)
            ->call('rememberShell', 'is_super_admin', true)->assertReturned(false);

        $prefs = Shell::preferences($user->fresh());
        expect($prefs)->toMatchArray(['width' => 280, 'collapsed' => true, 'open' => ['library'], 'style' => 'dark'])
            ->and($user->fresh()->is_super_admin)->toBeFalse();

        Livewire::test(Sidebar::class)->call('rememberShell', 'width', 9000);
        expect(Shell::preferences($user->fresh())['width'])->toBe(Shell::WIDTH_MAX);
        Livewire::test(Sidebar::class)->call('rememberShell', 'width', 10);
        expect(Shell::preferences($user->fresh())['width'])->toBe(Shell::WIDTH_MIN);
    });

    it('starts every page with the person\'s width, rail and style, and never another person\'s', function () {
        $a = Fx::user();
        $b = Fx::user();
        Shell::remember($a, 'width', 300);
        Shell::remember($a, 'collapsed', true);
        Shell::remember($a, 'style', 'soft');

        $this->actingAs($a->fresh(), 'web');
        $html = (string) $this->get('/app')->assertOk()->getContent();
        expect($html)->toContain('--sidebar-width: 300px')->toContain('--collapsed-sidebar-width: 64px')
            ->toContain("localStorage.setItem('filament:sidebar:desktop_open', 'false')")->toContain('data-style="soft"');

        $this->actingAs($b, 'web');
        $html = (string) $this->get('/app')->assertOk()->getContent();
        expect($html)->toContain('--sidebar-width: 240px')->toContain("localStorage.setItem('filament:sidebar:desktop_open', 'true')")
            ->toContain('data-style="light"');
    });

    it('keeps a guest out of the sidebar\'s choices', function () {
        Livewire::test(Sidebar::class)->call('rememberShell', 'width', 280)->assertForbidden();
    });
});

describe('recordings dropped on a place', function () {
    it('moves them to a project, to the Inbox, or pins them', function () {
        $a = Fx::user();
        $project = Fx::client($a, 'Acme Holdings');
        $one = Fx::session($a);
        $two = Fx::session($a, null, ['client_id' => $project->id]);
        $this->actingAs($a, 'web');

        Livewire::test(Sidebar::class)->call('dropRecordings', 'project:'.$project->id, [$one->id])->assertOk()->assertDispatched('el-library-changed');
        expect($one->fresh()->client_id)->toBe($project->id);

        Livewire::test(Sidebar::class)->call('dropRecordings', 'inbox', [$two->id]);
        expect($two->fresh()->client_id)->toBeNull();

        Livewire::test(Sidebar::class)->call('dropRecordings', 'pinned', [$one->id, $two->id]);
        expect($one->fresh()->pinned)->toBeTrue()->and($two->fresh()->pinned)->toBeTrue();
    });

    it('answers 404 when user B drops user A\'s recording, or onto user A\'s project', function () {
        $a = Fx::user();
        $b = Fx::user();
        $aProject = Fx::client($a, 'Acme Holdings');
        $aRecording = Fx::session($a);
        $bRecording = Fx::session($b);
        $this->actingAs($b, 'web');

        Livewire::test(Sidebar::class)->call('dropRecordings', 'pinned', [$aRecording->id])->assertNotFound();
        Livewire::test(Sidebar::class)->call('dropRecordings', 'project:'.$aProject->id, [$bRecording->id])->assertNotFound();
        Livewire::test(Sidebar::class)->call('dropRecordings', 'somewhere', [$bRecording->id])->assertNotFound();

        expect($aRecording->fresh()->pinned)->toBeFalse()->and($bRecording->fresh()->client_id)->toBeNull();
    });

    it('lets a viewer move nothing (403)', function () {
        $owner = Fx::user();
        $viewer = withoutWorkspace(Fx::user());
        app(Workspaces::class)->addMember(Workspace::query()->findOrFail($owner->current_workspace_id), $viewer, WorkspaceRole::Viewer);
        $session = CaptureSession::factory()->create(['user_id' => $viewer->id, 'workspace_id' => $owner->current_workspace_id]);
        $this->actingAs($viewer->fresh(), 'web');

        Livewire::test(Sidebar::class)->call('dropRecordings', 'pinned', [$session->id])->assertForbidden();
        expect($session->fresh()->pinned)->toBeFalse();
    });
});

describe('the nested column (P4.2)', function () {
    it('lists the Library\'s places with their counts and the person\'s projects beside the list', function () {
        $a = Fx::user();
        $b = Fx::user();
        $project = Fx::client($a, 'Acme Holdings');
        Fx::client($b, 'Rival Column Co');
        Fx::session($a, null, ['source_app' => 'whatsapp', 'client_id' => $project->id]);
        Fx::session($a, null, ['source_app' => 'voice_note']);
        $this->actingAs($a, 'web');

        $html = Livewire::test(ListCaptureSessions::class)->html();
        expect($html)->toContain('class="el-col"')->toContain('wire:click="openPlace(\'waiting\')"')->toContain('wire:click="openPlace(\'folder:whatsapp\')"')
            ->toContain('Acme Holdings')->toContain('New project')->toContain('data-el-drop="project:'.$project->id.'"')
            ->not->toContain('Rival Column Co');
    });

    it('opens a place: a view, a smart folder or every recording, and ignores anything else', function () {
        $a = Fx::user();
        $this->actingAs($a, 'web');

        Livewire::test(ListCaptureSessions::class)
            ->call('openPlace', 'waiting')->assertSet('activeTab', 'waiting')->assertSet('folder', null)->assertDispatched('el-place', place: 'waiting')
            ->call('openPlace', 'folder:zoom')->assertSet('folder', 'zoom')->assertSet('activeTab', 'all')
            ->call('openPlace', 'folder:../../etc')->assertSet('folder', 'zoom')
            ->call('openPlace', 'all')->assertSet('folder', null)->assertSet('activeTab', 'all');
    });

    it('moves a recording dropped on a project in the column, and answers 404 for user A\'s recording or project', function () {
        $a = Fx::user();
        $b = Fx::user();
        $aProject = Fx::client($a, 'Acme Holdings');
        $bProject = Fx::client($b, 'B Project');
        $aRecording = Fx::session($a);
        $bRecording = Fx::session($b);

        $this->actingAs($b, 'web');
        Livewire::test(ListCaptureSessions::class)->call('dropRecordings', 'project:'.$bProject->id, [$bRecording->id])->assertOk();
        expect($bRecording->fresh()->client_id)->toBe($bProject->id);

        Livewire::test(ListCaptureSessions::class)->call('dropRecordings', 'project:'.$bProject->id, [$aRecording->id])->assertNotFound();
        Livewire::test(ListCaptureSessions::class)->call('dropRecordings', 'project:'.$aProject->id, [$bRecording->id])->assertNotFound();
        Livewire::test(ViewClient::class, ['record' => $bProject->id])->call('dropRecordings', 'project:'.$aProject->id, [$bRecording->id])->assertNotFound();
        expect($aRecording->fresh()->client_id)->toBeNull()->and($bRecording->fresh()->client_id)->toBe($bProject->id);
    });

    it('puts the person\'s projects beside a project page, the open one marked, and no one else\'s', function () {
        $a = Fx::user();
        $b = Fx::user();
        $one = Fx::client($a, 'Acme Holdings');
        Fx::client($a, 'Nova Clinics');
        Fx::client($b, 'Rival Column Co');
        $this->actingAs($a, 'web');

        $html = Livewire::test(ViewClient::class, ['record' => $one->id])->html();
        expect($html)->toContain('class="el-col"')->toContain('Nova Clinics')->not->toContain('Rival Column Co')
            ->toMatch('/class="el-col-item el-col-project el-on"\s+aria-current="page"[^>]*title="Acme Holdings"/');
    });

    it('makes the rows draggable, except in the read-only console', function () {
        $a = Fx::user();
        Fx::session($a, null, ['title' => 'Drag me']);
        $this->actingAs($a, 'web');

        expect(Livewire::test(ListCaptureSessions::class)->html())->toMatch('/class="el-row" data-recording="[0-9A-Za-z]{26}"\s+draggable="true"/');
    });
});

describe('the top bar (P4.3)', function () {
    it('has the workspace with your role, search and commands, +, the bell, help and the avatar menu', function () {
        $this->actingAs(Fx::user(), 'web');
        $html = (string) $this->get('/app')->assertOk()->getContent();

        expect($html)->toContain('el-tb-workspace-name')->toContain('>Owner<')
            ->toContain('placeholder="Search recordings, transcripts, pages… or type a command"')
            ->toContain('aria-label="Create"')->toContain('data-create="upload"')->toContain('data-create="project"')->toContain('data-create="request"')
            ->toContain('fi-topbar-database-notifications-btn')->toContain('aria-label="Help &amp; shortcuts"')
            ->toContain('>Account<')->toContain('Plan &amp; usage')->toContain('Sign out')
            ->not->toContain('>Admin panel<');
    });

    it('gives staff the admin panel in the avatar menu, and the admin panel a way back', function () {
        $this->actingAs(Fx::superAdmin(), 'web');
        expect((string) $this->get('/app')->getContent())->toContain('>Admin panel<');
        Filament::setCurrentPanel('admin');
        $admin = (string) $this->get('/admin')->assertOk()->getContent();
        expect($admin)->toContain('Back to the app')->toContain('el-tb-admin-label')->not->toContain('el-tb-workspace-btn')->not->toContain('aria-label="Create"');
    });

    it('offers the read-only console no + menu', function () {
        $owner = Fx::user();
        $viewer = withoutWorkspace(Fx::user());
        app(Workspaces::class)->addMember(Workspace::query()->findOrFail($owner->current_workspace_id), $viewer, WorkspaceRole::Viewer);
        $this->actingAs($viewer->fresh(), 'web');

        expect((string) $this->get('/app/sessions')->assertOk()->getContent())->not->toContain('aria-label="Create"');
    });

    it('answers commands in Ctrl/⌘+K: Record now (the apps\' page without a device), Upload, New project, Invite', function () {
        $owner = Fx::user();
        $this->actingAs($owner, 'web');
        $palette = app(CommandPalette::class);

        $record = $palette->getResults('record')->getCategories()['Commands'][0];
        expect($record->title)->toBe('Record now')->and($record->url)->toBe(GetTheApps::getUrl(panel: 'app'));
        expect($palette->getResults('upload')->getCategories()['Commands'][0]->title)->toBe('Upload')
            ->and($palette->getResults('new project')->getCategories()['Commands'][0]->title)->toBe('New project')
            ->and(collect($palette->getResults('devices')->getCategories()['Go to'])->pluck('title')->all())->toContain('Devices')
            ->and(collect($palette->getResults('preferences')->getCategories()['Go to'])->pluck('title')->all())->toContain('Preferences');

        Fx::device($owner);
        expect(app(CommandPalette::class)->getResults('record')->getCategories()['Commands'][0]->url)->toContain('/app/upload?record=1');
    });

    it('offers a viewer no command that changes anything', function () {
        $owner = Fx::user();
        $viewer = withoutWorkspace(Fx::user());
        app(Workspaces::class)->addMember(Workspace::query()->findOrFail($owner->current_workspace_id), $viewer, WorkspaceRole::Viewer);
        $this->actingAs($viewer->fresh(), 'web');

        expect(CommandPalette::actions())->toBe([]);
    });

    it('shows Record a voice note on the Upload page, for a phone\'s own recorder', function () {
        $this->actingAs(Fx::user(), 'web');

        expect((string) $this->get('/app/upload?record=1')->assertOk()->getContent())
            ->toContain('data-record-here')->toContain('el-record-asked')->toContain('Record a voice note')->toContain('accept="audio/*" capture');
    });
});
