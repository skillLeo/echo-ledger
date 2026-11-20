<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Enums\UploadStatus;
use App\Http\Resources\LibraryItemResource;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use App\Services\LibraryOrganiser;
use App\Services\Workspaces\Workspaces;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
 * Plan B6: GET /api/library/views counts every smart view and folder in one query each, and GET /api/library loads only
 * the transcript columns a row reads. Here both still answer exactly what they answered before: each count is what its
 * own list holds, on a library with something of everything, and a library row is the same as the full one.
 */

beforeEach(function () {
    // A Wednesday: Monday is this week, last Sunday is not.
    Carbon::setTestNow(Carbon::parse('2026-11-18 14:00:00', 'UTC'));
    [$this->device, $this->token] = $this->enroll();
    $this->asToken($this->token);
    $this->owner = $this->device->user_id;
});

afterEach(fn () => Carbon::setTestNow());

/** @param array<string, mixed> $state */
function counted(object $test, array $state = []): CaptureSession
{
    return CaptureSession::factory()->create(['user_id' => $test->owner, 'started_at' => now()->subDays(20), ...$state]);
}

/** How many items GET /api/library lists for [$query], every page. */
function listed(object $test, string $query): int
{
    $count = 0;
    $cursor = null;
    do {
        $page = $test->getJson('/api/library?limit=100&'.$query.($cursor !== null ? '&cursor='.urlencode($cursor) : ''))->assertOk();
        $count += count($page->json('data'));
        $cursor = $page->json('next_cursor');
    } while ($cursor !== null);

    return $count;
}

it('counts each view and folder exactly as its list holds it, on a library with something of everything', function () {
    $project = Client::factory()->create(['user_id' => $this->owner]);
    $apps = [SourceApp::WhatsApp, SourceApp::WhatsAppWeb, SourceApp::WhatsAppBusiness, SourceApp::GoogleMeet, SourceApp::Zoom,
        SourceApp::MicrosoftTeams, SourceApp::PhoneCall, SourceApp::VoiceNote, SourceApp::Telegram];
    foreach ($apps as $i => $app) {
        counted($this, ['source_app' => $app, 'started_at' => now()->subHours($i), 'client_id' => $i % 2 === 0 ? $project->id : null]);
        counted($this, ['source_app' => $app, 'started_at' => now()->subDays(2 + $i), 'pinned' => $i % 3 === 0]);
    }
    counted($this, ['source_app' => SourceApp::VoiceNote, 'origin' => CaptureSession::ORIGIN_IMPORTED]);
    counted($this, ['source_app' => SourceApp::Other, 'origin' => CaptureSession::ORIGIN_REQUESTED, 'started_at' => now()->subMinutes(5)]);
    counted($this, ['source_app' => SourceApp::VoiceNote, 'origin' => CaptureSession::ORIGIN_SAVED]);
    counted($this, ['status' => SessionStatus::Recording, 'started_at' => now()->subMinutes(2)]);
    counted($this, ['status' => SessionStatus::Recording, 'pinned' => true, 'source_app' => SourceApp::Zoom]);
    counted($this, ['status' => SessionStatus::Failed, 'started_at' => now()->subHour()]);
    $this->makeReadyRecording(counted($this), Fixtures::audio(1000))->update(['upload_status' => UploadStatus::Failed]);
    // Last Sunday, and a minute before today in UTC.
    counted($this, ['started_at' => Carbon::parse('2026-11-16 00:00:00', 'UTC')->subMinute()]);
    counted($this, ['started_at' => Carbon::parse('2026-11-18 00:00:00', 'UTC')->subMinute(), 'pinned' => true]);
    $bookmarked = counted($this, ['started_at' => now()->subMinutes(30)]);
    $bookmarked->markers()->create(['at_ms' => 1000, 'tag' => 'Decision']);
    $bookmarked->markers()->create(['at_ms' => 2000, 'tag' => 'Price']);
    counted($this, ['pinned' => true])->markers()->create(['at_ms' => 5, 'tag' => null]);
    // Deleted, a teammate's and another workspace's never count.
    counted($this, ['pinned' => true, 'started_at' => now()->subMinutes(10)])->delete();
    $mate = User::factory()->create();
    app(Workspaces::class)->addMember(Workspace::query()->findOrFail($this->device->workspace_id), $mate);
    CaptureSession::factory()->create(['user_id' => $mate->id, 'workspace_id' => $this->device->workspace_id, 'pinned' => true, 'started_at' => now()->subMinutes(3)]);
    CaptureSession::factory()->create(['pinned' => true, 'started_at' => now()->subMinutes(3)]);

    foreach (['UTC', 'Asia/Karachi', 'America/Los_Angeles'] as $zone) {
        $views = $this->getJson('/api/library/views?tz='.urlencode($zone))->assertOk()->json();
        foreach (LibraryOrganiser::VIEWS as $view) {
            expect($views['views'][$view])->toBe(listed($this, "view={$view}&tz=".urlencode($zone)), "{$view} in {$zone}");
        }
    }
    $folders = $this->getJson('/api/library/views')->json('folders');
    expect(array_keys($folders))->toBe(LibraryOrganiser::FOLDERS);
    foreach (LibraryOrganiser::FOLDERS as $folder) {
        expect($folders[$folder])->toBe(listed($this, "folder={$folder}"), $folder);
    }
    // Something in every folder and view, so none of them passes by being zero on both sides.
    expect(collect($views['views'])->filter()->count())->toBe(count(LibraryOrganiser::VIEWS))
        ->and(collect($folders)->except('shared')->filter()->count())->toBe(count(LibraryOrganiser::FOLDERS) - 1);
});

it('counts zero everywhere in an empty library', function () {
    $json = $this->getJson('/api/library/views')->assertOk()->json();

    expect(array_sum($json['views']))->toBe(0)->and(array_sum($json['folders']))->toBe(0);
});

it('gives the same row in the library as the full recording, without loading the lines', function () {
    $session = counted($this, ['started_at' => now()->subHour(), 'language' => null]);
    // An Auto recording whose Urdu letters come after the preview: the views' language reads the whole text.
    $text = str_repeat('Hello there. ', 40).'یہ اردو ہے';
    $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Completed, 'text' => $text,
        'segments' => [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 900, 'text' => $text]],
        'meta' => ['speech_model_used' => 'universal', 'views' => ['status' => 'making', 'started_at' => now()->toAtomString(), 'lines_total' => 4, 'lines_done' => 1]]]);
    $session->transcripts()->create(['kind' => TranscriptKind::Live, 'provider' => 'deepgram', 'status' => TranscriptStatus::Completed, 'text' => 'live words']);
    $session->transcripts()->create(['kind' => TranscriptKind::Alternate, 'provider' => 'groq', 'status' => TranscriptStatus::Processing,
        'meta' => ['progress' => ['chunks_total' => 4, 'chunks_done' => 1]]]);
    $failed = counted($this, ['started_at' => now()->subHours(2)]);
    $failed->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Failed, 'error_code' => 'no_audio']);
    $this->postJson("/api/sessions/{$session->id}/opened")->assertNoContent();

    $rows = collect($this->getJson('/api/library')->assertOk()->json('data'))->keyBy('id');
    foreach ([$session, $failed] as $one) {
        $full = $this->getJson("/api/sessions/{$one->id}")->assertOk()->json();
        $row = $rows[$one->id];
        expect($row['transcript'])->toBe($full['transcript'])
            ->and(collect($row)->except(['transcript', 'opened'])->all())->toBe(collect($full)->only(array_keys($row))->except(['transcript', 'opened'])->all());
    }
    expect($rows[$session->id]['transcript']['views']['default'])->toBe('roman')
        ->and($rows[$session->id]['transcript']['alternate_status'])->toBe('processing');
});

it('names every transcript column but the lines and the search copy', function () {
    expect(LibraryItemResource::TRANSCRIPT_COLUMNS)
        ->toEqualCanonicalizing(array_values(array_diff(Schema::getColumnListing('transcripts'), ['segments', 'search_text'])));
});
