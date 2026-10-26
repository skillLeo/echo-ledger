<?php

declare(strict_types=1);

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Transcript;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

/* C9: lists never run a query per row. Lazy loading on a model from a list throws outside production. */

/** Queries the list page runs to show every row (50 per page). */
function adminQueriesFor(object $test, string $page): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $test->panelComponent($page)->set('tableRecordsPerPage', 50)->assertOk();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}
function adminSessionsWithTranscripts(int $userId, int $count): void
{
    $client = Client::factory()->create(['user_id' => $userId]);
    CaptureSession::factory()->count($count)->create(['user_id' => $userId, 'client_id' => $client->id])->each(function (CaptureSession $s): void {
        Transcript::query()->create(['capture_session_id' => $s->id, 'kind' => TranscriptKind::Final, 'provider' => 'assemblyai',
            'status' => TranscriptStatus::Completed, 'segments' => [['speaker' => 'Me', 'channel' => 1, 'start_ms' => 0, 'end_ms' => 900, 'text' => 'Hello']]]);
    });
}

it('turns lazy loading into an error outside production', function () {
    expect(Model::preventsLazyLoading())->toBeTrue();
    $admin = $this->signInAsAdmin();
    CaptureSession::factory()->count(2)->create(['user_id' => $admin->id, 'client_id' => Client::factory()->create(['user_id' => $admin->id])->id]);

    $sessions = CaptureSession::query()->get();
    expect(fn () => $sessions->last()->client)->toThrow(LazyLoadingViolationException::class);
});

it('renders the sessions list and the clients list with the same number of queries for 5 or 40 rows', function () {
    $admin = $this->signInAsAdmin();
    adminSessionsWithTranscripts($admin->id, 5);
    Client::factory()->count(4)->create(['user_id' => $admin->id]);
    $sessionsSmall = adminQueriesFor($this, ListCaptureSessions::class);
    $clientsSmall = adminQueriesFor($this, ListClients::class);

    adminSessionsWithTranscripts($admin->id, 35);
    Client::factory()->count(30)->create(['user_id' => $admin->id]);
    // A query per row would add 35 (sessions) or 30 (clients); counts and tab badges may add a few.
    expect(adminQueriesFor($this, ListCaptureSessions::class))->toBeLessThanOrEqual($sessionsSmall + 5)
        ->and(adminQueriesFor($this, ListClients::class))->toBeLessThanOrEqual($clientsSmall + 5);
});
