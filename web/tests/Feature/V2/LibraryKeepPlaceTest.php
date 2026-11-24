<?php

declare(strict_types=1);

use App\Services\InterfaceFlags;
use Filament\Facades\Filament;
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
        // It comes back with as many rows as Load more showed, never past the list's limit.
        ->toContain('core.moreNeeded(saved.shown, rows().length, 50, 500)');
});

it('tells the Library which recording was opened, and the recording\'s Library link goes back to the list as it was left', function () {
    $html = (string) $this->get('/app/sessions/'.$this->recording->id)->assertOk()->getContent();

    expect($html)->toContain('data-recording-page="'.$this->recording->id.'"')
        ->toContain("document.querySelectorAll('a.v2-back').forEach((a) => { a.href = saved.url; })");
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
