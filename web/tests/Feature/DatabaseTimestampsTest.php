<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Models\CaptureSession;
use App\Models\Device;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * MySQL / MariaDB without explicit_defaults_for_timestamp used to move `started_at` to "now" on every update of a
 * session. These checks run on every database the suite runs on (SQLite by default, MariaDB on request).
 */

it('never moves a session start when the session is updated', function () {
    $device = Device::factory()->create();
    $session = CaptureSession::factory()->create([
        'user_id' => $device->user_id, 'device_id' => $device->id,
        'started_at' => Carbon::parse('2026-10-20 09:00:00'), 'ended_at' => Carbon::parse('2026-10-20 09:30:00'),
    ]);
    $this->travel(3)->days();

    $session->update(['title' => 'Renamed', 'status' => SessionStatus::Completed]);
    $session->markers()->create(['at_ms' => 1, 'source' => 'device']); // touches the session

    expect($session->fresh()->started_at->toDateTimeString())->toBe('2026-10-20 09:00:00');
});

it('repairs sessions whose start was moved past their end', function () {
    $migration = 'database/migrations/2026_10_27_000003_stop_auto_updating_started_at.php';
    $device = Device::factory()->create();
    $moved = CaptureSession::factory()->create(['user_id' => $device->user_id, 'device_id' => $device->id, 'duration_ms' => 1_800_000]);
    $paused = CaptureSession::factory()->create(['user_id' => $device->user_id, 'device_id' => $device->id, 'duration_ms' => 600_000]);
    $fine = CaptureSession::factory()->create(['user_id' => $device->user_id, 'device_id' => $device->id, 'duration_ms' => 60_000]);
    DB::table('capture_sessions')->where('id', $moved->id)->update(['started_at' => '2026-10-25 12:00:00', 'ended_at' => '2026-10-20 09:30:00']);
    DB::table('capture_sessions')->where('id', $paused->id)->update([
        'started_at' => '2026-10-25 12:00:00', 'ended_at' => '2026-10-20 10:00:00', 'pauses' => json_encode([['at_ms' => 1000, 'paused_ms' => 120_000]]),
    ]);
    DB::table('capture_sessions')->where('id', $fine->id)->update(['started_at' => '2026-10-20 08:00:00', 'ended_at' => '2026-10-20 08:01:00']);

    Artisan::call('migrate:rollback', ['--path' => $migration, '--force' => true]);
    Artisan::call('migrate', ['--path' => $migration, '--force' => true]);

    expect($moved->fresh()->started_at->toDateTimeString())->toBe('2026-10-20 09:00:00')
        ->and($paused->fresh()->started_at->toDateTimeString())->toBe('2026-10-20 09:48:00')
        ->and($fine->fresh()->started_at->toDateTimeString())->toBe('2026-10-20 08:00:00');
});
