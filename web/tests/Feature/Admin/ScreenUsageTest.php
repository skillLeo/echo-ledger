<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\ServerUsage;
use App\Models\Screen;
use App\Services\Screens\ScreenUsage;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * Screen moments' real sizes per meeting hour (v1.8 P4.1) on Admin → Server usage: numbers only.
 */

beforeEach(fn () => Carbon::setTestNow('2026-11-20 12:00:00'));

/** A recording of [$minutes] with [$sizes] screens (picture bytes; stored is twice that). */
function screenedRecording(int $minutes, array $sizes, ?Carbon $at = null): void
{
    $user = Fx::user();
    $session = Fx::session($user, null, ['duration_ms' => $minutes * 60_000, 'started_at' => $at ?? now()->subDay()]);
    foreach ($sizes as $i => $bytes) {
        Screen::query()->withoutGlobalScopes()->create(['workspace_id' => $session->workspace_id, 'capture_session_id' => $session->id, 'at_ms' => $i * 1000,
            'width' => 1, 'height' => 1, 'trigger' => 'change', 'sha256' => hash('sha256', $session->id.$i), 'mime' => 'image/webp', 'bytes' => $bytes, 'stored_bytes' => $bytes * 2]);
    }
}

it('gives the average screen and the screens and megabytes per meeting hour', function () {
    screenedRecording(60, [100_000, 80_000, 120_000]);
    screenedRecording(30, [100_000]);
    screenedRecording(45, [], now()->subDay());
    screenedRecording(60, [999_999], now()->subMonths(2)); // another period

    expect(app(ScreenUsage::class)->between(now()->startOfMonth(), now()->addDay()))->toBe([
        'recordings' => 2, 'screens' => 4, 'hours' => 1.5, 'avg_bytes' => 100_000, 'avg_stored_bytes' => 200_000,
        'screens_per_hour' => 2.7, 'mb_per_hour' => round(800_000 / 1_048_576 / 1.5, 1),
    ]);
    expect(app(ScreenUsage::class)->between(now()->subYears(2), now()->subYear()))->toMatchArray(['screens' => 0, 'avg_bytes' => null, 'mb_per_hour' => null]);
});

it('shows them on Server usage', function () {
    screenedRecording(60, [100_000, 100_000]);
    Filament::setCurrentPanel('admin');
    $this->actingAs(Fx::superAdmin(), 'web');

    Livewire::test(ServerUsage::class)->assertSee('Screen moments')->assertSee('2.0 screens')->assertSee('97.7 KB');
});
