<?php

declare(strict_types=1);

use App\Services\InterfaceFlags;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Process;
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
