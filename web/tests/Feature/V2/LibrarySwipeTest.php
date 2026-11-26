<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Models\CaptureSession;
use App\Models\Workspace;
use App\Services\InterfaceFlags;
use App\Services\Workspaces\ConsoleAccess;
use App\Services\Workspaces\Workspaces;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * WEB-LIB-024 (U9b, docs/PLAN_V2_PARITY.md section 3): at phone width a row of the new Library swipes left to Delete
 * (the row's own Delete, which asks) and right to Pin or Unpin, mirrored right to left. The page side is
 * filament.v2.library-swipe, the decisions resources/js/library-swipe-core.cjs (its Node test runs here too). Classic
 * has no swipe.
 */

beforeEach(function () {
    $this->owner = Fx::user();
    Fx::session($this->owner, Fx::device($this->owner), ['title' => 'Payment terms']);
    Filament::setCurrentPanel('app');
    $this->actingAs($this->owner->fresh(), 'web');
});

it('gives the new Library its swipe, on the rows, with the row\'s own Delete and the star\'s Pin', function () {
    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, true, 0, [], [], 'everyone');
    }
    $html = (string) $this->get('/app/sessions')->assertOk()->getContent();

    expect($html)->toContain('window.elV2LibrarySwipe = true')->toContain('root.ElLibrarySwipeCore = core')
        ->toContain("wire.mountTableAction('delete', id)")->toContain('wire.togglePin(id)')
        ->toContain('class="v2-lib-row" data-recording=');
});

it('leaves classic\'s Library without a swipe', function () {
    expect((string) $this->get('/app/sessions')->assertOk()->getContent())->not->toContain('elV2LibrarySwipe')->not->toContain('ElLibrarySwipeCore');
});

it('decides swipes as the Node test says (phone width only, left Delete, right Pin, mirrored right to left)', function () {
    $result = Process::path(base_path())->timeout(60)->run(['node', '--test', 'tests/js/library-swipe-core.test.mjs']);
    expect($result->successful())->toBeTrue($result->output().$result->errorOutput());
})->skip(fn (): bool => trim((string) shell_exec('command -v node')) === '', 'Node is not installed here');

it('opens the row\'s Delete confirmation when a swipe asks for it, and deletes nothing until it is confirmed', function () {
    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, true, 0, [], [], 'everyone');
    }
    $session = CaptureSession::query()->where('title', 'Payment terms')->firstOrFail();
    $page = Livewire::test(ListCaptureSessions::class)
        ->mountTableAction('delete', $session->id);
    // The confirmation is open on that row, and nothing is gone yet.
    expect(collect($page->get('mountedActions'))->only([0])->map(fn (array $a): array => [$a['name'], $a['context']['recordKey'] ?? null])->all())->toBe([['delete', (string) $session->id]]);
    expect(CaptureSession::query()->whereKey($session->id)->exists())->toBeTrue();

    $page->callMountedTableAction();
    expect(CaptureSession::query()->whereKey($session->id)->exists())->toBeFalse()
        ->and(CaptureSession::withTrashed()->whereKey($session->id)->exists())->toBeTrue();
});

it('gives a read-only console no swipe (it can\'t delete or pin there)', function () {
    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, true, 0, [], [], 'everyone');
    }
    $workspace = Workspace::query()->findOrFail($this->owner->current_workspace_id);
    $member = Fx::user(['name' => 'Sana Malik']);
    app(Workspaces::class)->addMember($workspace, $member, WorkspaceRole::Member);
    $member->forceFill(['current_workspace_id' => $workspace->id])->save();
    $this->actingAs($member->fresh(), 'web');
    expect(ConsoleAccess::readOnly())->toBeTrue();

    $response = $this->get('/app/sessions')->assertOk();
    expect((string) $response->getContent())->not->toContain('elV2LibrarySwipe');
});

it('lets go of a swipe\'s own click after a moment, so the next real tap still opens the row', function () {
    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, true, 0, [], [], 'everyone');
    }
    $html = (string) $this->get('/app/sessions')->assertOk()->getContent();
    expect($html)->toContain("setTimeout(() => row.removeEventListener('click', block, { capture: true }), core.CLICK_HOLD_MS)")
        ->toContain('CLICK_HOLD_MS = 400');
});
