<?php

declare(strict_types=1);

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Transcript;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/* C9: the library sync the apps call runs the same number of queries for 5 or 45 recordings (no N+1). */

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll(platform: 'windows');
    $this->asToken($this->token);
});

function librarySessions(object $test, int $count): void
{
    $client = Client::factory()->create(['user_id' => $test->device->user_id]);
    CaptureSession::factory()->count($count)->create(['user_id' => $test->device->user_id, 'client_id' => $client->id])->each(function (CaptureSession $s): void {
        Transcript::query()->create(['capture_session_id' => $s->id, 'kind' => TranscriptKind::Final, 'provider' => 'assemblyai',
            'status' => TranscriptStatus::Completed, 'segments' => [['speaker' => 'Me', 'channel' => 1, 'start_ms' => 0, 'end_ms' => 900, 'text' => 'Hello there']]]);
    });
}

function libraryQueries(object $test): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $test->getJson('/api/library?limit=100')->assertOk();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('syncs the library without a query per recording', function () {
    librarySessions($this, 5);
    $small = libraryQueries($this);
    librarySessions($this, 40);

    expect(libraryQueries($this))->toBeLessThanOrEqual($small + 3);
});
