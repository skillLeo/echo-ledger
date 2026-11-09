<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Filament\Support\LibraryView;
use App\Models\CaptureSession;
use App\Models\SessionOpen;
use Filament\Facades\Filament;
use Tests\Feature\Admin\Support\AdminTestHelpers;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(AdminTestHelpers::class);

/* Group by and Sort by in the console's Library (since v1.5, Phase 1C, D1–D4). */

beforeEach(function () {
    Filament::setCurrentPanel('app');
    $this->admin = $this->signInAsAdmin();
    $this->travelTo(now()->setTime(15, 0));
    $make = fn (string $title, array $extra = []): CaptureSession => CaptureSession::factory()->create(['user_id' => $this->admin->id, 'title' => $title, 'status' => SessionStatus::Completed, ...$extra]);
    $this->today = $make('Budget call', ['started_at' => now()->subHour(), 'duration_ms' => 60_000, 'source_app' => 'whatsapp']);
    $this->todayLong = $make('Annual review', ['started_at' => now()->subHours(2), 'duration_ms' => 900_000, 'source_app' => 'zoom']);
    $this->yesterday = $make('Contract notes', ['started_at' => now()->subDay(), 'duration_ms' => 30_000, 'source_app' => 'whatsapp']);
    $this->older = $make('Zebra project', ['started_at' => now()->subDays(10), 'duration_ms' => null, 'source_app' => 'voice_note']);
});

/** The titles in the order the list shows them. */
function libraryOrder(object $page): array
{
    return $page->instance()->getTableRecords()->map(fn (CaptureSession $s): string => (string) $s->title)->values()->all();
}

it('groups by date by default, with each day\'s name and count, collapsible and with a select box for the group', function () {
    $page = $this->panelComponent(ListCaptureSessions::class);
    $html = $page->html();

    expect($page->get('libraryGroup'))->toBe('date')->and($page->get('librarySort'))->toBe('newest')
        ->and($html)->toContain('Group by: <strong>Date</strong>')->toContain('Sort by: <strong>Newest</strong>')
        ->toContain('fi-ta-group-header')->toContain('fi-collapsible')->toContain('fi-ta-group-checkbox')
        ->toContain('Today')->toContain('2 recordings')->toContain('Yesterday')->toContain('1 recording')
        ->and(libraryOrder($page))->toBe(['Budget call', 'Annual review', 'Contract notes', 'Zebra project']);
});

it('sorts within the groups by every choice', function (string $sort, array $order) {
    $page = $this->panelComponent(ListCaptureSessions::class)->call('setLibraryGroup', 'none')->call('setLibrarySort', $sort);

    expect(libraryOrder($page))->toBe($order);
})->with([
    'newest' => ['newest', ['Budget call', 'Annual review', 'Contract notes', 'Zebra project']],
    'oldest' => ['oldest', ['Zebra project', 'Contract notes', 'Annual review', 'Budget call']],
    'longest' => ['longest', ['Annual review', 'Budget call', 'Contract notes', 'Zebra project']],
    'shortest' => ['shortest', ['Contract notes', 'Budget call', 'Annual review', 'Zebra project']],
    'name' => ['name', ['Annual review', 'Budget call', 'Contract notes', 'Zebra project']],
]);

it('sorts by what this person opened last', function () {
    SessionOpen::query()->create(['capture_session_id' => $this->older->id, 'user_id' => $this->admin->id, 'opened_at' => now()->subMinute()]);
    SessionOpen::query()->create(['capture_session_id' => $this->yesterday->id, 'user_id' => $this->admin->id, 'opened_at' => now()->subMinutes(5)]);
    $page = $this->panelComponent(ListCaptureSessions::class)->call('setLibraryGroup', 'none')->call('setLibrarySort', 'opened');

    expect(libraryOrder($page))->toBe(['Zebra project', 'Contract notes', 'Budget call', 'Annual review']);
});

it('groups by app, and selects a whole group for the bulk actions', function () {
    $page = $this->panelComponent(ListCaptureSessions::class)->call('setLibraryGroup', 'app');
    $html = $page->html();

    expect($html)->toContain('WhatsApp')->toContain('Zoom')->toContain('Voice note')
        ->and($page->instance()->getGroupedSelectableTableRecordKeys('whatsapp'))->toEqualCanonicalizing([$this->today->id, $this->yesterday->id]);
});

it('groups by project, language, status and device', function (string $group, string $title) {
    $this->today->update(['client_id' => Fx::client($this->admin, 'Acme')->id, 'language' => 'ur']);

    expect($this->panelComponent(ListCaptureSessions::class)->call('setLibraryGroup', $group)->html())->toContain($title);
})->with([
    'project' => ['project', 'Acme'],
    'language' => ['language', 'Urdu'],
    'status' => ['status', 'Completed'],
    'device' => ['device', 'recordings'],
]);

it('remembers the choice per person and per view', function () {
    $this->panelComponent(ListCaptureSessions::class)->call('setLibraryGroup', 'app')->call('setLibrarySort', 'longest');

    expect(LibraryView::remembered($this->admin->fresh(), 'all'))->toBe(['group' => 'app', 'sort' => 'longest'])
        ->and(LibraryView::remembered($this->admin->fresh(), 'pinned'))->toBe(['group' => 'date', 'sort' => 'newest']);

    $again = $this->panelComponent(ListCaptureSessions::class);
    expect($again->get('libraryGroup'))->toBe('app')->and($again->get('librarySort'))->toBe('longest');
    // Another view has its own choice.
    $again->set('activeTab', 'pinned');
    expect($again->get('libraryGroup'))->toBe('date');

    $other = Fx::user();
    expect(LibraryView::remembered($other, 'all'))->toBe(['group' => 'date', 'sort' => 'newest']);
    // An unknown choice is ignored.
    $this->panelComponent(ListCaptureSessions::class)->call('setLibraryGroup', 'colour')->assertSet('libraryGroup', 'app');
});
