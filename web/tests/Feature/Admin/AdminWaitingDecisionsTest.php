<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Filament\Resources\CaptureSessions\Widgets\WaitingDecisionsWidget;
use App\Models\CaptureSession;
use App\Models\User;
use App\Services\LibraryOrganiser;
use App\Services\SettingsRepository;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

/* The "Keep or discard?" card above the recordings list: the calls still waiting for the phone or computer. */

/** A recording of the user's workspace that has not been kept or discarded yet. */
function waitingCall(User $owner, string $startedAtUtc, ?int $durationMs, array $attributes = []): CaptureSession
{
    return CaptureSession::factory()->open()->create([
        'user_id' => $owner->id,
        'started_at' => Carbon::parse($startedAtUtc, 'UTC'),
        'duration_ms' => $durationMs,
    ] + $attributes);
}

function waitingSummaryFor(User $owner): ?string
{
    return app(LibraryOrganiser::class)->waitingSummary((int) $owner->current_workspace_id);
}

beforeEach(function () {
    // Karachi is UTC+5: a call started late on Oct 29 UTC is on Oct 30 in the console's zone.
    app(SettingsRepository::class)->set('display.timezone', 'Asia/Karachi');
});

it('words one waiting call with its day in the display time zone and its length', function () {
    $owner = User::factory()->create();
    waitingCall($owner, '2026-10-29 21:30:00', 42_000);

    expect(waitingSummaryFor($owner))->toBe('1 call waiting · Oct 30 · 0:42 total');
});

it('words many waiting calls with the oldest day and the total length', function () {
    $owner = User::factory()->create();
    waitingCall($owner, '2026-10-31 08:00:00', 60_000);
    waitingCall($owner, '2026-10-29 19:15:00', 78_000); // oldest: Oct 30 00:15 in Karachi
    waitingCall($owner, '2026-10-30 12:00:00', null); // not measured yet: adds nothing
    $other = waitingCall($owner, '2026-10-30 13:00:00', 5_000);
    CaptureSession::factory()->create(['user_id' => $owner->id, 'started_at' => Carbon::parse('2026-10-01 09:00:00', 'UTC'), 'duration_ms' => 900_000]); // decided
    $other->delete(); // discarded: no longer waiting

    expect(waitingSummaryFor($owner))->toBe('3 calls waiting · oldest Oct 30 · 2:18 total');
});

it('counts hours past an hour of waiting calls', function () {
    $owner = User::factory()->create();
    waitingCall($owner, '2026-10-30 09:00:00', 3_600_000);
    waitingCall($owner, '2026-10-30 10:00:00', 123_000);

    expect(waitingSummaryFor($owner))->toBe('2 calls waiting · oldest Oct 30 · 1:02:03 total');
});

it('only counts the waiting calls of the given workspace', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    waitingCall($alice, '2026-10-30 09:00:00', 30_000);
    waitingCall($bob, '2026-10-20 09:00:00', 600_000);
    waitingCall($bob, '2026-10-21 09:00:00', 600_000);

    expect($alice->current_workspace_id)->not->toBe($bob->current_workspace_id)
        ->and(waitingSummaryFor($alice))->toBe('1 call waiting · Oct 30 · 0:30 total')
        ->and(waitingSummaryFor($bob))->toBe('2 calls waiting · oldest Oct 20 · 20:00 total');

    // Signed in to Alice's workspace, Bob's waiting calls never show a card.
    CaptureSession::query()->where('user_id', $alice->id)->update(['status' => SessionStatus::Completed->value]);
    $this->actingAs($alice, 'web');
    expect(WaitingDecisionsWidget::canView())->toBeFalse();
});

it('hides the card when nothing is waiting', function () {
    $admin = $this->signInAsAdmin();
    CaptureSession::factory()->create(['user_id' => $admin->id]);

    expect(waitingSummaryFor($admin))->toBeNull()
        ->and(WaitingDecisionsWidget::canView())->toBeFalse();
    $this->get(CaptureSessionResource::getUrl('index'))->assertOk()
        ->assertDontSee('Keep or discard?')->assertDontSee('decides Keep or Discard');
});

it('shows the card with a Review link to the waiting view above the recordings list', function () {
    $admin = $this->signInAsAdmin();
    $waiting = waitingCall($admin, '2026-10-29 21:30:00', 138_000);
    $kept = CaptureSession::factory()->create(['user_id' => $admin->id]);
    $reviewUrl = CaptureSessionResource::getUrl('index', ['tab' => 'waiting']);

    expect(WaitingDecisionsWidget::canView())->toBeTrue()
        ->and($reviewUrl)->toEndWith('/admin/sessions?tab=waiting');
    $this->get(CaptureSessionResource::getUrl('index'))->assertOk()
        ->assertSeeInOrder(['Keep or discard?', 'Review', '1 call waiting · Oct 30 · 2:18 total', 'The phone or computer that recorded them decides Keep or Discard.'])
        ->assertSee('href="'.e($reviewUrl).'"', escape: false);

    Livewire::withQueryParams(['tab' => 'waiting'])->test(ListCaptureSessions::class)
        ->assertSet('activeTab', 'waiting')
        ->assertCanSeeTableRecords([$waiting])->assertCanNotSeeTableRecords([$kept]);
});
