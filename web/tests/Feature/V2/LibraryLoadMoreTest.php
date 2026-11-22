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

it('keeps how many rows show on the server\'s side (the browser can\'t set it) and grows to 500 at most, then asks to refine', function () {
    loadMoreV2();
    $page = Livewire::test(ListCaptureSessions::class);
    expect(fn () => $page->set('v2Shown', 5000))->toThrow(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);

    $device = App\Models\Device::query()->where('user_id', $this->owner->id)->firstOrFail();
    foreach (range(121, 560) as $i) {
        Fx::session($this->owner, $device, ['title' => "Call {$i}", 'started_at' => now()->subHours($i)]);
    }
    // Up to 500 rows a draw (about 9 MB of HTML): each draw's leftovers are let go of before the next, so a parallel
    // worker never holds several at once.
    unset($page, $device);
    gc_collect_cycles();
    $page = Livewire::test(ListCaptureSessions::class);
    $calls = 0;
    while ($page->get('v2Shown') < ListCaptureSessions::V2_MAX && $calls++ < 12) {
        $page->call('v2LoadMore');
        gc_collect_cycles();
    }
    expect($page->get('v2Shown'))->toBe(ListCaptureSessions::V2_MAX);
    // One more Load more at the cap stays at 500.
    $page->call('v2LoadMore');
    expect($page->get('v2Shown'))->toBe(ListCaptureSessions::V2_MAX)
        ->and(substr_count($page->html(), 'class="v2-lib-row"'))->toBe(500)
        ->and($page->html())->toContain('Refine your search to see more')->toContain('data-load-more-cap')->not->toContain('data-load-more data-shown');
});

it('reads only the row\'s columns of each transcript: its status and the start of its text, never its lines', function () {
    loadMoreV2();
    $session = App\Models\CaptureSession::query()->where('title', 'Call 1')->firstOrFail();
    $session->transcripts()->create(['kind' => App\Enums\TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => App\Enums\TranscriptStatus::Completed,
        'text' => 'Assalam o alaikum. '.str_repeat('The proforma is ready. ', 200), 'meta' => ['views' => ['status' => 'ready']],
        'segments' => [['speaker' => 'A', 'start_ms' => 0, 'end_ms' => 1000, 'text' => 'Assalam o alaikum.', 'words' => []]]]);
    $page = Livewire::test(ListCaptureSessions::class);
    $row = $page->instance()->getTableRecords()->first(fn ($r) => $r->id === $session->id);

    expect($row->finalTranscript->getAttributes())->not->toHaveKey('segments')
        ->and(mb_strlen((string) $row->finalTranscript->text))->toBe(600)
        ->and($row->finalTranscript->meta)->toBeNull()
        ->and($page->html())->toContain('Assalam o alaikum. The proforma is ready.');
});

it('polls quietly while a row is moving: the list is drawn again only once that row changed', function () {
    loadMoreV2();
    $moving = App\Models\CaptureSession::query()->where('title', 'Call 1')->firstOrFail();
    $moving->forceFill(['status' => App\Enums\SessionStatus::Processing])->save();
    $page = Livewire::test(ListCaptureSessions::class);
    expect($page->get('v2Moving'))->toBe([(string) $moving->id])->and($page->html())->toContain('wire:poll.5s="v2Poll"')
        ->and($page->html())->not->toMatch('~wire:poll\.5s(?!="v2Poll")~');

    // Nothing moved: no redraw (no HTML goes back).
    $page->call('v2Poll');
    expect($page->effects['html'] ?? null)->toBeNull();

    // It finished: the list is drawn again, and the poll stops.
    Carbon::setTestNow(now()->addSeconds(10));
    $moving->forceFill(['status' => App\Enums\SessionStatus::Completed])->save();
    $page->call('v2Poll');
    expect($page->effects['html'] ?? null)->not->toBeNull()->and($page->get('v2Moving'))->toBe([])
        ->and($page->html())->not->toContain('v2Poll');
});
