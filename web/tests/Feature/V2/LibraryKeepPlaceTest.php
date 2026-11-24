<?php

declare(strict_types=1);

use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Filament\Support\NavFrame;
use App\Services\InterfaceFlags;
use Filament\Facades\Filament;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * UX-25 (PLAN_V2_1_POLISH, an owner request) on the web console: the Library keeps your place coming back from a
 * recording, the recording you opened keeps a light tint, and Back to top on the long lists (filament.v2.keep-place;
 * resources/js/keep-place-core.cjs decides, tests/js/keep-place-core.test.mjs). Only the new Library has it; signing
 * out forgets it.
 */

beforeEach(function () {
    $this->owner = Fx::user();
    $this->recording = Fx::session($this->owner, Fx::device($this->owner), ['title' => 'Kick-off']);
    Filament::setCurrentPanel('app');
    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, true, 0, [], [], 'everyone');
    }
    $this->actingAs($this->owner->fresh(), 'web');
});

it('gives the Library its place, the tint and Back to top, in the console\'s words', function () {
    $html = (string) $this->get('/app/sessions')->assertOk()->getContent();

    expect($html)->toContain('window.ElKeepPlaceCore')->toContain('window.elV2KeepPlace')
        ->toContain('<template id="el-back-top">')->toContain('data-back-top aria-label="Back to top"')
        // It comes back with as many rows as Load more showed, in one request, never past the list's limit.
        ->toContain('core.showUpTo(saved.shown, rows().length, 500)')->toContain('await wire.v2ShowUpTo(upTo);')
        // One person's place in one workspace: the frame's memory scope.
        ->toContain('core.keys("'.NavFrame::memoryScope().'")')
        // The row takes the focus only if nothing else took it meanwhile, and anything the person does while the list
        // comes back (a click anywhere, the wheel, a touch, a key) leaves the list theirs: no scroll, tint or focus.
        ->toContain('core.focusFree(document.activeElement, focusAtStart, document.body)')
        ->toContain("const INTERACTIONS = ['pointerdown', 'wheel', 'touchstart', 'keydown'];")
        // A sideways wheel (a Mac's swipe back) is not the person's (core.takes).
        ->toContain('window.addEventListener(type, (e) => { if (core.takes(e)) taken = true; }, { capture: true, passive: true })')
        ->toContain('const steps = core.restoreSteps(taken, ')
        // Home is the control's own in a toolbar, tabs and a slider.
        ->toContain('[role="toolbar"], [role="tablist"], [role="slider"]');
});

it('shows as many rows as were shown in one request, in whole chunks, never past 500 nor fewer than now', function () {
    $page = Livewire::test(ListCaptureSessions::class);
    $page->call('v2ShowUpTo', 120)->assertSet('v2Shown', 150)
        ->call('v2ShowUpTo', 60)->assertSet('v2Shown', 150)
        ->call('v2ShowUpTo', 9000)->assertSet('v2Shown', ListCaptureSessions::V2_MAX);
    expect(fn () => Livewire::test(ListCaptureSessions::class)->set('v2Shown', 400))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('gives Back to top to the four long lists only, by their addresses', function () {
    $html = (string) $this->get('/app/sessions')->assertOk()->getContent();
    preg_match('/const LISTS = (\[[^\]]*\]);/', $html, $m);
    expect(json_decode($m[1] ?? 'null', true))->toBe(['/app/sessions', '/app/activity', '/app/shared-with-me', '/app/recently-deleted']);
});

it('tells the Library which recording was opened, and the recording\'s Library link goes back to the list as it was left', function () {
    $html = (string) $this->get('/app/sessions/'.$this->recording->id)->assertOk()->getContent();

    expect($html)->toContain('data-recording-page="'.$this->recording->id.'"')
        ->toContain("document.querySelectorAll('a.v2-back').forEach((a) => { a.href = saved.url; })")
        // Any other page but the Library ends the way back (Library, a recording, Activity, Library: the top).
        ->toContain('if (! library()) store.set(KEYS.opened, null);');
});

it('puts Back to top on the other long lists too: Activity, Shared with me and Recently deleted', function () {
    foreach (['/app/activity', '/app/shared-with-me', '/app/recently-deleted'] as $path) {
        expect((string) $this->get($path)->assertOk()->getContent())->toContain('<template id="el-back-top">', $path);
    }
});

it('has none of it in the classic console, and signing out forgets where this tab left the Library', function () {
    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, false, 0, [], [], 'off');
    }
    expect((string) $this->get('/app/sessions')->assertOk()->getContent())->not->toContain('ElKeepPlaceCore')->not->toContain('el-back-top');

    auth()->logout();
    expect((string) $this->get('/app/login')->assertOk()->getContent())
        ->toContain("Object.keys(sessionStorage).filter((k) => k.startsWith('el-place:'))");
});
