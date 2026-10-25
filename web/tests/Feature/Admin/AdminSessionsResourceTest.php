<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\SourceDevice;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Filament\Resources\CaptureSessions\Pages\ViewCaptureSession;
use App\Filament\Resources\CaptureSessions\Tables\CaptureSessionsTable;
use App\Jobs\TranscribeFinalJob;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Device;
use App\Models\Recording;
use App\Models\Transcript;
use App\Services\RecordingIngestor;
use App\Services\SettingsRepository;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    Http::preventStrayRequests();
});

/** A minimal but genuine RIFF/WAVE file, so MIME sniffing recognises it as audio. */
function adminTinyWav(int $samples = 800): string
{
    $data = str_repeat("\x00\x00", $samples);

    return 'RIFF'.pack('V', 36 + strlen($data)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', strlen($data)).$data;
}

it('renders the sessions list page', function () {
    $this->signInAsAdmin();

    $this->get('/admin/sessions')->assertOk()->assertSee('Sessions')->assertSee('Upload voice note');
});

it('shows a helpful empty state when there are no sessions', function () {
    $this->signInAsAdmin();

    $this->panelComponent(ListCaptureSessions::class)
        ->assertSee('No sessions yet')
        ->assertSee('Pair a phone or desktop under Devices, or upload a voice note.');
});

it('lists the admin\'s sessions with source and app labels as text', function () {
    $admin = $this->signInAsAdmin();
    $phone = CaptureSession::factory()->create(['user_id' => $admin->id, 'source_device' => SourceDevice::Phone, 'source_app' => SourceApp::WhatsApp, 'title' => 'Call with Acme']);
    $desktop = CaptureSession::factory()->create(['user_id' => $admin->id, 'source_device' => SourceDevice::Desktop, 'source_app' => SourceApp::Zoom, 'title' => 'Zoom sync']);
    $web = CaptureSession::factory()->create(['user_id' => $admin->id, 'source_device' => SourceDevice::Web, 'source_app' => SourceApp::VoiceNote, 'title' => 'Dictated']);

    $this->panelComponent(ListCaptureSessions::class)
        ->assertCanSeeTableRecords([$phone, $desktop, $web])
        ->assertSee(['Phone', 'Desktop', 'Web', 'WhatsApp', 'Zoom', 'Voice note', 'Call with Acme']);
});

it('falls back to a readable title when a session has none', function () {
    $admin = $this->signInAsAdmin();
    CaptureSession::factory()->create(['user_id' => $admin->id, 'title' => null, 'source_app' => SourceApp::GoogleMeet]);
    CaptureSession::factory()->create(['user_id' => $admin->id, 'title' => null, 'source_app' => SourceApp::VoiceNote]);
    CaptureSession::factory()->create(['user_id' => $admin->id, 'title' => '', 'source_app' => SourceApp::Other]);

    $this->panelComponent(ListCaptureSessions::class)
        ->assertSee(['Google Meet call', 'Voice note', 'Recording']);
});

it('only lists the signed-in admin\'s sessions and hides deleted ones', function () {
    $admin = $this->signInAsAdmin();
    $other = $this->makeAdmin();
    $mine = CaptureSession::factory()->create(['user_id' => $admin->id, 'title' => 'Mine']);
    $theirs = CaptureSession::factory()->create(['user_id' => $other->id, 'title' => 'Theirs']);
    $gone = CaptureSession::factory()->create(['user_id' => $admin->id, 'title' => 'Gone']);
    $gone->delete();

    $this->panelComponent(ListCaptureSessions::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs, $gone])
        ->assertCountTableRecords(1);
});

it('shows the newest session first by default and can sort by start time both ways', function () {
    $admin = $this->signInAsAdmin();
    $old = CaptureSession::factory()->create(['user_id' => $admin->id, 'started_at' => now()->subDays(3)]);
    $mid = CaptureSession::factory()->create(['user_id' => $admin->id, 'started_at' => now()->subDays(2)]);
    $new = CaptureSession::factory()->create(['user_id' => $admin->id, 'started_at' => now()->subDay()]);

    $list = $this->panelComponent(ListCaptureSessions::class);
    $list->assertCanSeeTableRecords([$new, $mid, $old], inOrder: true);
    $list->sortTable('started_at')->assertCanSeeTableRecords([$old, $mid, $new], inOrder: true);
    $list->sortTable('started_at', 'desc')->assertCanSeeTableRecords([$new, $mid, $old], inOrder: true);
});

it('searches sessions by title', function () {
    $admin = $this->signInAsAdmin();
    $invoice = CaptureSession::factory()->create(['user_id' => $admin->id, 'title' => 'Invoice dispute']);
    $kickoff = CaptureSession::factory()->create(['user_id' => $admin->id, 'title' => 'Project kickoff']);

    $this->panelComponent(ListCaptureSessions::class)
        ->searchTable('dispute')
        ->assertCanSeeTableRecords([$invoice])
        ->assertCanNotSeeTableRecords([$kickoff])
        ->searchTable('zzz-no-such-title')
        ->assertCountTableRecords(0);
});

it('searches sessions by client name', function () {
    $admin = $this->signInAsAdmin();
    $acme = Client::factory()->create(['user_id' => $admin->id, 'name' => 'Acme Holdings']);
    $globex = Client::factory()->create(['user_id' => $admin->id, 'name' => 'Globex']);
    $forAcme = CaptureSession::factory()->create(['user_id' => $admin->id, 'client_id' => $acme->id]);
    $forGlobex = CaptureSession::factory()->create(['user_id' => $admin->id, 'client_id' => $globex->id]);

    $this->panelComponent(ListCaptureSessions::class)
        ->searchTable('Acme')
        ->assertCanSeeTableRecords([$forAcme])
        ->assertCanNotSeeTableRecords([$forGlobex]);
});

it('treats a search for a percent sign or quote as plain text', function () {
    $admin = $this->signInAsAdmin();
    $literal = CaptureSession::factory()->create(['user_id' => $admin->id, 'title' => '100% done']);
    $plain = CaptureSession::factory()->create(['user_id' => $admin->id, 'title' => 'Other']);

    $this->panelComponent(ListCaptureSessions::class)
        ->searchTable("'; DROP TABLE capture_sessions; --")
        ->assertCountTableRecords(0);

    expect(CaptureSession::query()->count())->toBe(2);
    $this->panelComponent(ListCaptureSessions::class)->searchTable('100%')
        ->assertCanSeeTableRecords([$literal])
        ->assertCanNotSeeTableRecords([$plain]);
});

it('filters by source device', function () {
    $admin = $this->signInAsAdmin();
    $phone = CaptureSession::factory()->create(['user_id' => $admin->id, 'source_device' => SourceDevice::Phone]);
    $desktop = CaptureSession::factory()->create(['user_id' => $admin->id, 'source_device' => SourceDevice::Desktop]);
    $web = CaptureSession::factory()->create(['user_id' => $admin->id, 'source_device' => SourceDevice::Web]);

    $list = $this->panelComponent(ListCaptureSessions::class);
    $list->filterTable('source_device', ['phone'])
        ->assertCanSeeTableRecords([$phone])->assertCanNotSeeTableRecords([$desktop, $web]);
    $list->filterTable('source_device', ['phone', 'web'])
        ->assertCanSeeTableRecords([$phone, $web])->assertCanNotSeeTableRecords([$desktop]);
});

it('filters by source app', function () {
    $admin = $this->signInAsAdmin();
    $whatsapp = CaptureSession::factory()->create(['user_id' => $admin->id, 'source_app' => SourceApp::WhatsApp]);
    $business = CaptureSession::factory()->create(['user_id' => $admin->id, 'source_app' => SourceApp::WhatsAppBusiness]);
    $zoom = CaptureSession::factory()->create(['user_id' => $admin->id, 'source_app' => SourceApp::Zoom]);

    $this->panelComponent(ListCaptureSessions::class)
        ->filterTable('source_app', ['whatsapp_business', 'zoom'])
        ->assertCanSeeTableRecords([$business, $zoom])
        ->assertCanNotSeeTableRecords([$whatsapp]);
});

it('filters by client', function () {
    $admin = $this->signInAsAdmin();
    $acme = Client::factory()->create(['user_id' => $admin->id]);
    $other = Client::factory()->create(['user_id' => $admin->id]);
    $forAcme = CaptureSession::factory()->create(['user_id' => $admin->id, 'client_id' => $acme->id]);
    $forOther = CaptureSession::factory()->create(['user_id' => $admin->id, 'client_id' => $other->id]);
    $unassigned = CaptureSession::factory()->create(['user_id' => $admin->id, 'client_id' => null]);

    $this->panelComponent(ListCaptureSessions::class)
        ->filterTable('client', $acme->id)
        ->assertCanSeeTableRecords([$forAcme])
        ->assertCanNotSeeTableRecords([$forOther, $unassigned]);
});

it('filters by status', function () {
    $admin = $this->signInAsAdmin();
    $done = CaptureSession::factory()->create(['user_id' => $admin->id, 'status' => SessionStatus::Completed]);
    $failed = CaptureSession::factory()->create(['user_id' => $admin->id, 'status' => SessionStatus::Failed]);
    $processing = CaptureSession::factory()->create(['user_id' => $admin->id, 'status' => SessionStatus::Processing]);

    $this->panelComponent(ListCaptureSessions::class)
        ->filterTable('status', 'failed')
        ->assertCanSeeTableRecords([$failed])
        ->assertCanNotSeeTableRecords([$done, $processing]);
});

it('filters by a date range, inclusive at both ends', function () {
    $admin = $this->signInAsAdmin();
    $before = CaptureSession::factory()->create(['user_id' => $admin->id, 'started_at' => Carbon::parse('2026-10-09 23:59:59')]);
    $first = CaptureSession::factory()->create(['user_id' => $admin->id, 'started_at' => Carbon::parse('2026-10-10 00:00:00')]);
    $last = CaptureSession::factory()->create(['user_id' => $admin->id, 'started_at' => Carbon::parse('2026-10-12 23:59:59')]);
    $after = CaptureSession::factory()->create(['user_id' => $admin->id, 'started_at' => Carbon::parse('2026-10-13 00:00:00')]);

    $list = $this->panelComponent(ListCaptureSessions::class);
    $list->filterTable('started_at', ['from' => '2026-10-10', 'until' => '2026-10-12'])
        ->assertCanSeeTableRecords([$first, $last])
        ->assertCanNotSeeTableRecords([$before, $after]);
    $list->filterTable('started_at', ['from' => '2026-10-13', 'until' => null])
        ->assertCanSeeTableRecords([$after])
        ->assertCanNotSeeTableRecords([$before, $first, $last]);
    $list->filterTable('started_at', ['from' => null, 'until' => '2026-10-09'])
        ->assertCanSeeTableRecords([$before])
        ->assertCanNotSeeTableRecords([$first, $last, $after]);
});

it('finds a session by the title it is shown with, including the generated "WhatsApp call" fallback', function () {
    $admin = $this->signInAsAdmin();
    $untitled = CaptureSession::factory()->create(['user_id' => $admin->id, 'title' => null, 'source_app' => SourceApp::WhatsApp]);
    $other = CaptureSession::factory()->create(['user_id' => $admin->id, 'title' => 'Budget review', 'source_app' => SourceApp::Zoom]);

    $this->panelComponent(ListCaptureSessions::class)
        ->assertSee('WhatsApp call')
        ->searchTable('WhatsApp call')
        ->assertCanSeeTableRecords([$untitled])
        ->assertCanNotSeeTableRecords([$other]);
});

it('filters by date using the days shown in the console time zone', function () {
    $admin = $this->signInAsAdmin();
    app(SettingsRepository::class)->set('display.timezone', 'Asia/Karachi'); // UTC+5
    // 20:30 UTC on Oct 24 is 01:30 on Oct 25 in the console, where the list shows it as "Oct 25, 2026 · 1:30 AM".
    $lateNight = CaptureSession::factory()->create(['user_id' => $admin->id, 'started_at' => Carbon::parse('2026-10-24 20:30:00', 'UTC')]);
    $earlier = CaptureSession::factory()->create(['user_id' => $admin->id, 'started_at' => Carbon::parse('2026-10-24 08:00:00', 'UTC')]);

    $this->panelComponent(ListCaptureSessions::class)
        ->filterTable('started_at', ['from' => '2026-10-25', 'until' => '2026-10-25'])
        ->assertCanSeeTableRecords([$lateNight])
        ->assertCanNotSeeTableRecords([$earlier]);
});

it('combines filters and search', function () {
    $admin = $this->signInAsAdmin();
    $match = CaptureSession::factory()->create(['user_id' => $admin->id, 'source_device' => SourceDevice::Desktop, 'status' => SessionStatus::Completed, 'title' => 'Budget review']);
    $wrongDevice = CaptureSession::factory()->create(['user_id' => $admin->id, 'source_device' => SourceDevice::Phone, 'status' => SessionStatus::Completed, 'title' => 'Budget review']);
    $wrongTitle = CaptureSession::factory()->create(['user_id' => $admin->id, 'source_device' => SourceDevice::Desktop, 'status' => SessionStatus::Completed, 'title' => 'Standup']);

    $this->panelComponent(ListCaptureSessions::class)
        ->filterTable('source_device', ['desktop'])
        ->searchTable('Budget')
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$wrongDevice, $wrongTitle]);
});

it('pages through a long library ten at a time, newest first', function () {
    $admin = $this->signInAsAdmin();
    $sessions = collect(range(1, 12))->map(fn (int $i) => CaptureSession::factory()->create([
        'user_id' => $admin->id, 'title' => "Call {$i}", 'started_at' => now()->subHours(100 - $i),
    ]));

    $first = $this->panelComponent(ListCaptureSessions::class);
    $first->assertCountTableRecords(12)
        ->assertCanSeeTableRecords($sessions->reverse()->take(10)->values(), inOrder: true)
        ->assertCanNotSeeTableRecords($sessions->take(2));

    expect($first->instance()->getTableRecords()->count())->toBe(10);
    $first->call('gotoPage', 2)->assertCanSeeTableRecords($sessions->take(2)->reverse()->values(), inOrder: true);
});

it('shows the transcript status of each session, or a dash when none exists', function () {
    $admin = $this->signInAsAdmin();
    $completed = $this->sessionWithTranscript($admin, [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1, 'text' => 'x']], ['title' => 'Has transcript']);
    $failed = $this->sessionWithTranscript($admin, [], ['title' => 'Failed one'], 'final', ['status' => TranscriptStatus::Failed]);
    CaptureSession::factory()->create(['user_id' => $admin->id, 'title' => 'No transcript']);

    $list = $this->panelComponent(ListCaptureSessions::class);
    $list->assertTableColumnStateSet('finalTranscript.status', TranscriptStatus::Completed, $completed)
        ->assertTableColumnStateSet('finalTranscript.status', TranscriptStatus::Failed, $failed)
        ->assertTableColumnExists('device.name')
        ->assertTableColumnExists('client.name')
        ->assertTableColumnExists('source_device')
        ->assertTableColumnExists('source_app');
});

it('lets the Device and Transcript columns be hidden', function () {
    $this->signInAsAdmin();

    $columns = collect($this->panelComponent(ListCaptureSessions::class)->instance()->getTable()->getColumns());

    expect($columns->get('device.name')->isToggleable())->toBeTrue()
        ->and($columns->get('finalTranscript.status')->isToggleable())->toBeTrue()
        ->and($columns->get('started_at')->isToggleable())->toBeFalse();
});

it('offers only the admin\'s own clients in the client filter', function () {
    $admin = $this->signInAsAdmin();
    $mine = Client::factory()->create(['user_id' => $admin->id, 'name' => 'My Client Ltd']);
    $theirs = Client::factory()->create(['user_id' => $this->makeAdmin()->id, 'name' => 'Somebody Else Inc']);

    $this->panelComponent(ListCaptureSessions::class)
        ->assertSee('My Client Ltd', false)
        ->assertDontSee('Somebody Else Inc', false);
    expect(unscoped(fn () => Client::query()->count()))->toBe(2)->and($theirs->name)->toBe('Somebody Else Inc')->and($mine->name)->toBe('My Client Ltd');
});

it('formats durations as mm:ss below an hour and h:mm:ss from an hour', function (?int $ms, string $expected) {
    expect(CaptureSessionsTable::duration($ms))->toBe($expected);
})->with([
    'unknown' => [null, ''],
    'zero' => [0, '00:00'],
    'sub-second' => [999, '00:00'],
    'under a minute' => [59_000, '00:59'],
    'ten minutes' => [600_000, '10:00'],
    'one second under an hour' => [3_599_000, '59:59'],
    'exactly an hour' => [3_600_000, '1:00:00'],
    'one hour two minutes five seconds' => [3_725_000, '1:02:05'],
    'ten hours' => [36_000_000, '10:00:00'],
]);

it('keeps counting hours from a day on instead of wrapping around', function (int $ms, string $expected) {
    expect(CaptureSessionsTable::duration($ms))->toBe($expected);
})->with([
    'a full day' => [86_400_000, '24:00:00'],
    'a day and a bit' => [90_000_000, '25:00:00'],
]);

it('shows the start time as "M j, Y · g:i A" in the configured time zone', function () {
    $admin = $this->signInAsAdmin();
    app(SettingsRepository::class)->set('display.timezone', 'Asia/Karachi'); // UTC+5
    $session = CaptureSession::factory()->create(['user_id' => $admin->id, 'started_at' => Carbon::parse('2026-10-24 09:05:00', 'UTC')]);

    $this->panelComponent(ListCaptureSessions::class)->assertSee('Oct 24, 2026 · 2:05 PM');
    $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id])->assertSee('Oct 24, 2026 · 2:05 PM');
});

it('shows the start time in UTC when no time zone is configured', function () {
    $admin = $this->signInAsAdmin();
    CaptureSession::factory()->create(['user_id' => $admin->id, 'started_at' => Carbon::parse('2026-10-24 21:30:00', 'UTC')]);

    $this->panelComponent(ListCaptureSessions::class)->assertSee('Oct 24, 2026 · 9:30 PM');
});

it('rolls the date forward when the configured zone is past midnight', function () {
    $admin = $this->signInAsAdmin();
    app(SettingsRepository::class)->set('display.timezone', 'Asia/Karachi');
    CaptureSession::factory()->create(['user_id' => $admin->id, 'started_at' => Carbon::parse('2026-10-24 20:30:00', 'UTC')]);

    $this->panelComponent(ListCaptureSessions::class)->assertSee('Oct 25, 2026 · 1:30 AM');
});

it('deletes a session from the list into the undo bin, then erases its audio and transcript, leaving a tombstone', function () {
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 900, 'text' => 'Hello']]);
    $recording = $this->readyRecording($session);
    $path = $recording->stored_path;
    expect(Storage::disk('recordings')->exists($path))->toBeTrue();

    $this->panelComponent(ListCaptureSessions::class)
        ->callAction(TestAction::make(DeleteAction::class)->table($session));

    expect(CaptureSession::query()->find($session->id))->toBeNull()
        ->and(CaptureSession::withTrashed()->find($session->id)?->restorable())->toBeTrue();
    emptyUndoBin();
    expect(CaptureSession::withTrashed()->find($session->id)?->deleted_at)->not->toBeNull()
        ->and(Recording::query()->count())->toBe(0)
        ->and(Transcript::query()->count())->toBe(0)
        ->and(Storage::disk('recordings')->exists($path))->toBeFalse();
});

it('bulk-deletes the selected sessions and leaves the rest', function () {
    $admin = $this->signInAsAdmin();
    $selected = CaptureSession::factory()->count(3)->create(['user_id' => $admin->id]);
    $kept = CaptureSession::factory()->create(['user_id' => $admin->id]);

    $this->panelComponent(ListCaptureSessions::class)
        ->selectTableRecords($selected->pluck('id')->all())
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk());

    expect(CaptureSession::query()->pluck('id')->all())->toBe([$kept->id])
        ->and(CaptureSession::onlyTrashed()->count())->toBe(3);
});

it('cannot delete another admin\'s session through the list', function () {
    $this->signInAsAdmin();
    $theirs = CaptureSession::factory()->create(['user_id' => $this->makeAdmin()->id]);

    $this->panelComponent(ListCaptureSessions::class)
        ->assertCanNotSeeTableRecords([$theirs]);

    expect(unscoped(fn () => CaptureSession::query()->find($theirs->id)))->not->toBeNull();
});

it('has a view link for each row and no create button', function () {
    $admin = $this->signInAsAdmin();
    $session = CaptureSession::factory()->create(['user_id' => $admin->id]);

    $this->panelComponent(ListCaptureSessions::class)
        ->assertActionExists(TestAction::make('view')->table($session))
        ->assertActionDoesNotExist('create');
    $this->get('/admin/sessions/create')->assertNotFound();
});

it('shows the session details on the view page', function () {
    $admin = $this->signInAsAdmin();
    $client = Client::factory()->create(['user_id' => $admin->id, 'name' => 'Acme Holdings']);
    $device = Device::factory()->create(['user_id' => $admin->id, 'name' => 'Work laptop']);
    $session = $this->sessionWithTranscript(
        $admin,
        [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 900, 'text' => 'Hello there']],
        ['client_id' => $client->id, 'device_id' => $device->id, 'source_device' => SourceDevice::Desktop, 'source_app' => SourceApp::Zoom, 'title' => 'Quarterly review', 'duration_ms' => 3_725_000],
    );

    $this->get('/admin/sessions/'.$session->id)
        ->assertOk()
        ->assertSee('Quarterly review')
        ->assertSee('Desktop')
        ->assertSee('Zoom')
        ->assertSee('Acme Holdings')
        ->assertSee('Work laptop')
        ->assertSee('1:02:05')
        ->assertSee('Completed')
        ->assertSee('assemblyai');
});

it('shows placeholders for a session without client, device or transcript', function () {
    $admin = $this->signInAsAdmin();
    $session = CaptureSession::factory()->create(['user_id' => $admin->id, 'device_id' => null, 'client_id' => null, 'source_device' => SourceDevice::Web, 'source_app' => SourceApp::VoiceNote]);

    $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id])
        ->assertSee('Web upload')
        ->assertSee('Not started')
        ->assertSee('No transcript yet');
});

it('shows the failure reason on the view page', function () {
    $admin = $this->signInAsAdmin();
    $session = CaptureSession::factory()->create(['user_id' => $admin->id, 'status' => SessionStatus::Failed]);
    Transcript::query()->create([
        'capture_session_id' => $session->id, 'kind' => TranscriptKind::Final, 'provider' => 'assemblyai',
        'status' => TranscriptStatus::Failed, 'error' => 'Provider rejected the audio (code 42)',
    ]);

    $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id])
        ->assertSee('Provider rejected the audio (code 42)')
        ->assertSee('Failed');
});

it('returns 404 for another admin\'s session and for a deleted one', function () {
    $admin = $this->signInAsAdmin();
    $theirs = CaptureSession::factory()->create(['user_id' => $this->makeAdmin()->id]);
    $deleted = CaptureSession::factory()->create(['user_id' => $admin->id]);
    $deleted->delete();

    $this->get('/admin/sessions/'.$theirs->id)->assertNotFound();
    $this->get('/admin/sessions/'.$deleted->id)->assertNotFound();
    $this->get('/admin/sessions/01JNOSUCHSESSION0000000000')->assertNotFound();
});

it('offers Re-transcribe only for a ready recording without a running or finished final transcript', function () {
    $admin = $this->signInAsAdmin();

    $noAudio = CaptureSession::factory()->create(['user_id' => $admin->id]);
    $pendingUpload = CaptureSession::factory()->create(['user_id' => $admin->id]);
    $noTranscript = CaptureSession::factory()->create(['user_id' => $admin->id]);
    $this->readyRecording($noTranscript);
    $failed = $this->sessionWithTranscript($admin, [], [], 'final', ['status' => TranscriptStatus::Failed, 'error' => 'boom']);
    $this->readyRecording($failed);
    $completed = $this->sessionWithTranscript($admin, [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1, 'text' => 'x']]);
    $this->readyRecording($completed);
    $processing = $this->sessionWithTranscript($admin, [], [], 'final', ['status' => TranscriptStatus::Processing]);
    $this->readyRecording($processing);

    foreach ([[$noAudio, false], [$pendingUpload, false], [$noTranscript, true], [$failed, true], [$completed, false], [$processing, false]] as [$session, $visible]) {
        $page = $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id]);
        $visible ? $page->assertActionVisible('retry') : $page->assertActionHidden('retry');
    }
});

it('re-transcribes a failed session by resetting it and queueing the job', function () {
    Queue::fake();
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, [], ['status' => SessionStatus::Failed], 'final', [
        'status' => TranscriptStatus::Failed, 'error' => 'Provider timed out', 'attempts' => 3, 'provider_job_id' => 'old-job',
    ]);
    $this->readyRecording($session);

    $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id])
        ->callAction('retry')
        ->assertNotified('Transcription queued');

    $transcript = $session->finalTranscript()->first();
    expect($transcript->status)->toBe(TranscriptStatus::Pending)
        ->and($transcript->error)->toBeNull()
        ->and($transcript->attempts)->toBe(0)
        ->and($transcript->provider_job_id)->toBeNull()
        ->and($session->fresh()->status)->toBe(SessionStatus::Processing);
    Queue::assertPushed(TranscribeFinalJob::class, 1);
});

it('re-transcribes a session that never got a final transcript', function () {
    Queue::fake();
    $admin = $this->signInAsAdmin();
    $session = CaptureSession::factory()->create(['user_id' => $admin->id, 'status' => SessionStatus::Processing]);
    $this->readyRecording($session);

    $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id])->callAction('retry');

    expect($session->finalTranscript()->first())->not->toBeNull();
    Queue::assertPushed(TranscribeFinalJob::class, 1);
});

it('deletes a session from its view page and returns to the list', function () {
    $admin = $this->signInAsAdmin();
    $session = CaptureSession::factory()->create(['user_id' => $admin->id]);

    $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id])
        ->callAction(DeleteAction::class)
        ->assertRedirect(ListCaptureSessions::getUrl());

    expect(CaptureSession::query()->find($session->id))->toBeNull();
});

it('puts a voice note in the library as a Web upload, encrypted at rest, and queues transcription', function () {
    Queue::fake();
    $this->isolatedStorage();
    $admin = $this->signInAsAdmin();
    app(SettingsRepository::class)->set('provider.voice_note', 'elevenlabs');
    $client = Client::factory()->create(['user_id' => $admin->id]);
    $wav = adminTinyWav();

    $this->panelComponent(ListCaptureSessions::class)
        ->callAction('uploadVoiceNote', [
            'audio' => UploadedFile::fake()->createWithContent('note.wav', $wav),
            'title' => 'Site visit note',
            'client_id' => $client->id,
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Voice note uploaded');

    $session = CaptureSession::query()->with('primaryRecording')->firstOrFail();
    expect($session->user_id)->toBe($admin->id)
        ->and($session->source_device)->toBe(SourceDevice::Web)
        ->and($session->source_app)->toBe(SourceApp::VoiceNote)
        ->and($session->title)->toBe('Site visit note')
        ->and($session->client_id)->toBe($client->id)
        ->and($session->device_id)->toBeNull()
        ->and($session->final_provider)->toBe('elevenlabs')
        ->and($session->primaryRecording->isReady())->toBeTrue();

    $stored = Storage::disk('recordings')->get($session->primaryRecording->stored_path);
    expect($stored)->not->toContain('RIFF')->not->toContain('WAVEfmt');
    expect(Storage::disk('local')->allFiles('tmp-uploads'))->toBe([]);
    Queue::assertPushed(TranscribeFinalJob::class, 1);
});

/** Smallest byte sequences that identify each container the way real files begin. */
function adminAudioSample(string $kind): string
{
    return match ($kind) {
        'wav' => adminTinyWav(),
        'ogg-opus' => 'OggS'.chr(0).chr(2).str_repeat("\0", 8).pack('V', 1).pack('V', 0).pack('V', 0).chr(1).chr(19).'OpusHead'.chr(1).chr(1).pack('v', 312).pack('V', 48000).pack('v', 0).chr(0).str_repeat("\0", 64),
        'mp3' => 'ID3'.chr(3).chr(0).chr(0).pack('N', 0)."\xFF\xFB\x90\x44".str_repeat("\0", 400),
        'flac' => 'fLaC'.str_repeat("\0", 64),
        'm4a' => pack('N', 28).'ftypM4A '.pack('N', 0).'M4A mp42isom'.str_repeat("\0", 64),
        'aac' => "\xFF\xF1\x50\x80\x02\x1F\xFC".str_repeat("\0", 100),
        'amr' => "#!AMR\n".str_repeat("\x3C", 64),
        'webm' => "\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01\x42\xF7\x81\x01\x42\xF2\x81\x04\x42\xF3\x81\x08\x42\x82\x84webm\x42\x87\x81\x04\x42\x85\x81\x02".str_repeat("\0", 64),
    };
}

/*
 * While tests run, Livewire reports the client-declared type for uploads, so it cannot exercise the content sniffing
 * that runs in production. These cases run the same detector (Flysystem's finfo detector, used by Livewire's
 * TemporaryUploadedFile) over real file signatures and check the result against the rule the upload field applies.
 */
it('recognises each audio container the product lists as supported', function (string $kind) {
    $detected = (new FinfoMimeTypeDetector)->detectMimeTypeFromBuffer(adminAudioSample($kind));

    expect((array) config('echoledger.accepted_mimes'))->toContain($detected);
})->with([
    'wav' => 'wav',
    'ogg opus (WhatsApp voice note)' => 'ogg-opus',
    'mp3' => 'mp3',
    'flac' => 'flac',
    'm4a' => 'm4a',
]);

it('recognises the ADTS AAC, AMR and WebM containers the product also lists as supported', function (string $kind) {
    $detected = (new FinfoMimeTypeDetector)->detectMimeTypeFromBuffer(adminAudioSample($kind));

    expect((array) config('echoledger.accepted_mimes'))->toContain($detected);
})->with([
    'aac' => 'aac',
    'amr' => 'amr',
    'webm' => 'webm',
]);

it('stores an AMR, ADTS AAC or WebM voice note under its audio type (A6.W28)', function (string $kind, string $name, string $stored) {
    Queue::fake();
    $this->isolatedStorage();
    $this->signInAsAdmin();
    $bytes = adminAudioSample($kind);
    $sniffed = (string) (new FinfoMimeTypeDetector)->detectMimeTypeFromBuffer($bytes);

    $this->panelComponent(ListCaptureSessions::class)
        ->callAction('uploadVoiceNote', ['audio' => UploadedFile::fake()->createWithContent($name, $bytes)->mimeType($sniffed)])
        ->assertHasNoActionErrors();

    $recording = Recording::query()->sole();
    expect($recording->mime)->toBe($stored)
        ->and($recording->isReady())->toBeTrue();
})->with([
    'amr' => ['amr', 'note.amr', 'audio/amr'],
    'aac' => ['aac', 'note.aac', 'audio/aac'],
    'webm' => ['webm', 'note.webm', 'audio/webm'],
]);

it('maps the sniffed container names to the stored audio type', function (string $sniffed, string $stored) {
    expect(RecordingIngestor::canonicalMime($sniffed))->toBe($stored);
})->with([
    ['audio/AMR', 'audio/amr'],
    ['audio/x-hx-aac-adts', 'audio/aac'],
    ['video/webm', 'audio/webm'],
    ['audio/ogg', 'audio/ogg'],
    [' Audio/WAV ', 'audio/wav'],
]);

it('still refuses video and image types that are not on the list', function (string $mime) {
    expect((array) config('echoledger.accepted_mimes'))->not->toContain($mime);
})->with(['video/mp4', 'video/quicktime', 'image/svg+xml', 'text/html', 'application/octet-stream']);

it('does not mistake non-audio content for audio, whatever the file is called', function (string $content) {
    $detected = (new FinfoMimeTypeDetector)->detectMimeTypeFromBuffer($content);

    expect((array) config('echoledger.accepted_mimes'))->not->toContain($detected);
})->with([
    'svg with script' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
    'html page' => '<html><script>alert(1)</script></html>',
    'windows executable' => "MZ\x90\x00".str_repeat("\0", 64),
    'php source' => '<?php system($_GET["c"]);',
]);
it('requires an audio file for a voice note', function () {
    Queue::fake();
    $this->signInAsAdmin();

    $this->panelComponent(ListCaptureSessions::class)
        ->callAction('uploadVoiceNote', ['title' => 'No file'])
        ->assertHasActionErrors(['audio' => 'required']);

    expect(CaptureSession::query()->count())->toBe(0);
});

it('rejects a voice note whose type is not an accepted audio type', function () {
    Queue::fake();
    $this->isolatedStorage();
    $this->signInAsAdmin();

    $this->panelComponent(ListCaptureSessions::class)
        ->callAction('uploadVoiceNote', ['audio' => UploadedFile::fake()->createWithContent('payload.exe', "MZ\x90\x00this is not audio")])
        ->assertHasActionErrors(['audio']);

    expect(CaptureSession::query()->count())->toBe(0);
});

it('rejects a voice note larger than 12 MB', function () {
    Queue::fake();
    $this->isolatedStorage();
    $this->signInAsAdmin();
    $big = adminTinyWav(7_000_000); // ~14 MB

    $this->panelComponent(ListCaptureSessions::class)
        ->callAction('uploadVoiceNote', ['audio' => UploadedFile::fake()->createWithContent('long.wav', $big)])
        ->assertHasActionErrors(['audio']);

    expect(CaptureSession::query()->count())->toBe(0);
});

it('rejects an over-long voice note title', function () {
    Queue::fake();
    $this->isolatedStorage();
    $this->signInAsAdmin();

    $this->panelComponent(ListCaptureSessions::class)
        ->callAction('uploadVoiceNote', [
            'audio' => UploadedFile::fake()->createWithContent('note.wav', adminTinyWav()),
            'title' => str_repeat('t', 201),
        ])
        ->assertHasActionErrors(['title' => 'max']);
});

it('does not let a voice note be filed under another admin\'s client', function () {
    Queue::fake();
    $this->isolatedStorage();
    $this->signInAsAdmin();
    $foreign = Client::factory()->create(['user_id' => $this->makeAdmin()->id]);

    $this->panelComponent(ListCaptureSessions::class)
        ->callAction('uploadVoiceNote', [
            'audio' => UploadedFile::fake()->createWithContent('note.wav', adminTinyWav()),
            'client_id' => $foreign->id,
        ])
        ->assertHasActionErrors(['client_id']);

    expect(CaptureSession::query()->count())->toBe(0);
});

// --- Console details (A6.W26) -----------------------------------------------------------------------------------

it('says the search found nothing instead of claiming the library is empty', function () {
    $admin = $this->signInAsAdmin();
    CaptureSession::factory()->create(['user_id' => $admin->id, 'title' => 'Budget review']);

    $this->panelComponent(ListCaptureSessions::class)
        ->searchTable('zzz-nothing-like-this')
        ->assertSee('No sessions match your search')
        ->assertDontSee('No sessions yet');
    $this->panelComponent(ListCaptureSessions::class)
        ->filterTable('status', 'failed')
        ->assertSee('No sessions match these filters');
});

it('matches percent signs and underscores in a search literally', function () {
    $admin = $this->signInAsAdmin();
    $underscore = CaptureSession::factory()->create(['user_id' => $admin->id, 'title' => 'file_name review']);
    $plain = CaptureSession::factory()->create(['user_id' => $admin->id, 'title' => 'filename review']);

    $this->panelComponent(ListCaptureSessions::class)
        ->searchTable('file_name')
        ->assertCanSeeTableRecords([$underscore])
        ->assertCanNotSeeTableRecords([$plain]);
});

it('finds untitled voice notes and other recordings by their shown titles', function () {
    $admin = $this->signInAsAdmin();
    $note = CaptureSession::factory()->create(['user_id' => $admin->id, 'title' => '', 'source_app' => SourceApp::VoiceNote]);
    $other = CaptureSession::factory()->create(['user_id' => $admin->id, 'title' => null, 'source_app' => SourceApp::Other]);
    $titled = CaptureSession::factory()->create(['user_id' => $admin->id, 'title' => 'Zoom planning', 'source_app' => SourceApp::VoiceNote]);

    $this->panelComponent(ListCaptureSessions::class)->searchTable('voice note')
        ->assertCanSeeTableRecords([$note])->assertCanNotSeeTableRecords([$other, $titled]);
    $this->panelComponent(ListCaptureSessions::class)->searchTable('recording')
        ->assertCanSeeTableRecords([$other])->assertCanNotSeeTableRecords([$note, $titled]);
});

it('ends the date filter at midnight of the last chosen day in the console time zone', function () {
    $admin = $this->signInAsAdmin();
    app(SettingsRepository::class)->set('display.timezone', 'Asia/Karachi'); // UTC+5
    $lastMinute = CaptureSession::factory()->create(['user_id' => $admin->id, 'started_at' => Carbon::parse('2026-10-25 18:59:00', 'UTC')]); // Oct 25, 11:59 PM
    $nextDay = CaptureSession::factory()->create(['user_id' => $admin->id, 'started_at' => Carbon::parse('2026-10-25 19:00:00', 'UTC')]);  // Oct 26, 12:00 AM

    $this->panelComponent(ListCaptureSessions::class)
        ->filterTable('started_at', ['from' => null, 'until' => '2026-10-25'])
        ->assertCanSeeTableRecords([$lastMinute])
        ->assertCanNotSeeTableRecords([$nextDay]);
});

it('labels the source of a session whose device was revoked on its view page', function () {
    $admin = $this->signInAsAdmin();
    $session = CaptureSession::factory()->create(['user_id' => $admin->id, 'device_id' => null, 'source_device' => SourceDevice::Desktop]);

    expect(CaptureSessionsTable::deviceLabel($session))->toBe('Desktop');
    $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id])->assertDontSee('Web upload');
});

it('gives the filter button an accessible name that includes the number it shows', function () {
    $admin = $this->signInAsAdmin();
    CaptureSession::factory()->create(['user_id' => $admin->id]);

    $html = $this->panelComponent(ListCaptureSessions::class)->html();
    expect($html)->toContain('aria-label="Filters (0 active)"');

    $filtered = $this->panelComponent(ListCaptureSessions::class)->filterTable('status', 'failed')->html();
    expect($filtered)->toContain('aria-label="Filters (1 active)"');
});
