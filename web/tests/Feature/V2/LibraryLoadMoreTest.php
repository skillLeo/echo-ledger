<?php

declare(strict_types=1);

use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Services\InterfaceFlags;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * WEB-LIB-033 (U9b, docs/PLAN_V2_PARITY.md section 3): the new Library is one list that grows (Load more, also as it
 * scrolls into view) rather than pages of 5 to 50, and its counts stay the whole Library's. Classic keeps its pages.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-17 11:42:00', 'UTC'));
    $this->owner = Fx::user();
    $device = Fx::device($this->owner);
    foreach (range(1, 120) as $i) {
        Fx::session($this->owner, $device, ['title' => "Call {$i}", 'started_at' => now()->subHours($i)]);
    }
    Filament::setCurrentPanel('app');
    $this->actingAs($this->owner->fresh(), 'web');
});

function loadMoreV2(): void
{
    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, true, 0, [], [], 'everyone');
    }
}

/** How many rows the page draws, what its footer counts, and its Load more. */
function loadMoreState(string $html): array
{
    preg_match('~data-load-more data-shown="(\d+)" data-total="(\d+)"~', $html, $more);

    return [substr_count($html, 'class="v2-lib-row"'), $more !== [] ? [(int) $more[1], (int) $more[2]] : null];
}

it('shows the first 50 with the whole count, grows by 50 with Load more, and has none left at the end', function () {
    loadMoreV2();
    $page = Livewire::test(ListCaptureSessions::class);
    expect(loadMoreState($page->html()))->toBe([50, [50, 120]])
        ->and($page->html())->toContain('Show 50 more')->toMatch('~Showing\s+1\s+to\s+50\s+of\s+120~')
        ->and($page->html())->not->toContain('fi-pagination-items');

    $page->call('v2LoadMore');
    expect(loadMoreState($page->html()))->toBe([100, [100, 120]])->and($page->html())->toContain('Show 20 more')
        ->toMatch('~Showing\s+1\s+to\s+100\s+of\s+120~');
    $page->call('v2LoadMore');
    expect(loadMoreState($page->html()))->toBe([120, null])->and($page->html())->toMatch('~Showing\s+1\s+to\s+120\s+of\s+120~');

    // A search starts over at the first chunk, its count its own.
    $page->set('tableSearch', 'Call 1');
    expect($page->get('v2Shown'))->toBe(ListCaptureSessions::V2_CHUNK);
});

it('keeps classic\'s pages and per-page choice', function () {
    $page = Livewire::test(ListCaptureSessions::class);
    expect(loadMoreState($page->html())[1])->toBeNull()
        ->and($page->html())->not->toContain('data-load-more')
        ->and(substr_count($page->html(), 'fi-ta-record '))->toBeLessThan(120);
    $page->call('v2LoadMore');
    expect($page->get('v2Shown'))->toBe(ListCaptureSessions::V2_CHUNK);
});
