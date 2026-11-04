<?php

declare(strict_types=1);

use App\Enums\SourceDevice;
use App\Exceptions\ApiError;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\ClientFile;
use App\Models\Plan;
use App\Models\Recording;
use App\Models\RequestLink;
use App\Models\RequestUpload;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ChunkedUploadService;
use App\Services\ClientAi\ClientWorkspace;
use App\Services\Sharing\RequestLinks;
use App\Services\TempFileSweeper;
use App\Stt\Support\WavFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
 * docs/API.md "Request links (since v1.5)": the device API (make, list, revoke) and the public /r/{token} page with its
 * chunked uploads: sound and video become recordings, documents and images go to the project's Files, everything else
 * and anything over the link's limits is refused.
 */

beforeEach(function () {
    Queue::fake();
    config(['echoledger.max_chunk_bytes' => 256 * 1024]);
    [$this->device, $this->token] = $this->enroll(platform: 'android');
    $this->asToken($this->token);
    $this->project = Client::factory()->create(['user_id' => $this->device->user_id, 'name' => 'Acme']);
});

function requestLink(object $test, array $body = []): array
{
    return $test->asToken($test->token)->postJson('/api/request-links', ['title' => 'Send me your voice notes', 'client_id' => $test->project->id, ...$body])
        ->assertCreated()->json('data');
}

/** A request from someone without an account. */
function visitor(object $test): object
{
    app('auth')->forgetGuards();

    return $test->withHeaders(['Authorization' => '']);
}

function wav(int $bytes = 300_000): string
{
    return WavFile::header(1, 16000, 16, $bytes - 44).random_bytes($bytes - 44);
}

function docx(bool $word = true, string $text = 'Scope'): string
{
    $path = tempnam(sys_get_temp_dir(), 'el-test-docx-');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
    $zip->addFromString($word ? 'word/document.xml' : 'other/thing.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>'.$text.'</w:t></w:r></w:p></w:body></w:document>');
    $zip->close();
    $bytes = (string) file_get_contents($path);
    @unlink($path);

    return $bytes;
}

function startUpload(object $test, string $url, string $bytes, string $name, string $type = ''): TestResponse
{
    return visitor($test)->postJson(parse_url($url, PHP_URL_PATH).'/files', ['name' => $name, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'type' => $type]);
}

function sendChunk(object $test, string $url, string $upload, int $index, string $bytes, int $size): TestResponse
{
    $chunk = substr($bytes, $index * $size, $size);
    $server = ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json', 'HTTP_UPLOAD_CHUNK_SHA256' => hash('sha256', $chunk)];

    return $test->call('POST', parse_url($url, PHP_URL_PATH)."/files/{$upload}/chunks/{$index}", [], [], [], $server, $chunk);
}

/** Sends a whole file through the page and returns the answer of `complete`. */
function sendWhole(object $test, string $url, string $bytes, string $name, string $type = ''): TestResponse
{
    $start = startUpload($test, $url, $bytes, $name, $type)->assertCreated();
    foreach ($start->json('missing_chunks') as $index) {
        sendChunk($test, $url, $start->json('upload_id'), $index, $bytes, $start->json('chunk_size'))->assertOk();
    }

    return visitor($test)->postJson(parse_url($url, PHP_URL_PATH).'/files/'.$start->json('upload_id').'/complete');
}

describe('the API', function () {
    it('makes a link shown once, lists it without its address and revokes it', function () {
        $link = requestLink($this, ['max_files' => 5, 'max_mb' => 50, 'expires_at' => now()->addDay()->toIso8601String()]);
        expect($link['url'])->toStartWith(url('/r/'))
            ->and($link)->toMatchArray(['title' => 'Send me your voice notes', 'client_id' => $this->project->id, 'max_files' => 5, 'max_mb' => 50, 'received' => 0, 'revoked_at' => null]);

        $listed = $this->getJson('/api/request-links')->assertOk()->json('data');
        expect($listed)->toHaveCount(1)->and($listed[0])->not->toHaveKey('url')->and($listed[0]['id'])->toBe($link['id']);

        $this->deleteJson('/api/request-links/'.$link['id'])->assertNoContent();
        expect($this->getJson('/api/request-links')->json('data.0.revoked_at'))->not->toBeNull();
        visitor($this)->get(parse_url($link['url'], PHP_URL_PATH))->assertNotFound()->assertSee('no longer available');
    });

    it('validates the link and keeps other workspaces out', function () {
        $foreignProject = Client::factory()->create(['user_id' => User::factory()]);
        $this->postJson('/api/request-links', ['client_id' => $foreignProject->id])->assertNotFound()->assertJsonPath('code', 'client_not_found');
        $this->postJson('/api/request-links', ['max_files' => 101])->assertStatus(422);
        $this->postJson('/api/request-links', ['max_mb' => 0])->assertStatus(422);
        $this->postJson('/api/request-links', ['expires_at' => now()->subHour()->toIso8601String()])->assertStatus(422);
        expect($this->postJson('/api/request-links', [])->assertCreated()->json('data'))->toMatchArray(['title' => 'Send me your files', 'client_id' => null, 'max_files' => 20, 'max_mb' => 100]);

        [$other, $otherToken] = $this->enroll();
        $mine = requestLink($this);
        $this->asToken($otherToken)->deleteJson('/api/request-links/'.$mine['id'])->assertNotFound();
        expect($this->asToken($otherToken)->getJson('/api/request-links')->json('data'))->toBe([]);
    });
});

describe('the page', function () {
    it('shows the upload page with its limits and the app link, and nothing for a bad token', function () {
        $link = requestLink($this, ['max_files' => 3, 'max_mb' => 10]);
        $path = parse_url($link['url'], PHP_URL_PATH);

        $page = visitor($this)->get($path)->assertOk()->assertSee('Send me your voice notes')->assertSee('Up to 3 files, 10 MB each')
            ->assertSee('PDF, Word, text and images')->assertDontSee('Acme');
        expect($page->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow')
            ->and($page->getContent())->toContain('echoledger://open?link='.rawurlencode(url($path)));
        visitor($this)->get('/r/'.str_repeat('A', 43))->assertNotFound();
        visitor($this)->get('/r/short')->assertNotFound();
        $this->travel(1)->days();
        RequestLink::query()->update(['expires_at' => now()->subMinute()]);
        visitor($this)->get($path)->assertNotFound();
        startUpload($this, $link['url'], wav(), 'a.wav', 'audio/wav')->assertNotFound()->assertJsonPath('code', 'not_found');
    });

    it('turns sound into a recording of the link\'s project, titled from the file name', function () {
        $link = requestLink($this);
        $bytes = wav(600_000);

        sendWhole($this, $link['url'], $bytes, 'Ali_voice note 3.wav', 'audio/wav')->assertOk()->assertExactJson(['status' => 'ready']);
        $session = unscoped(fn () => CaptureSession::query()->with('primaryRecording')->sole());
        expect($session)->client_id->toBe($this->project->id)->origin->toBe('requested')->title->toBe('Ali voice note 3')
            ->user_id->toBe($this->device->user_id)->workspace_id->toBe($this->device->workspace_id)->source_device->toBe(SourceDevice::Web)
            ->and($session->primaryRecording->isReady())->toBeTrue()
            ->and($session->primaryRecording->sha256)->toBe(hash('sha256', $bytes))
            ->and($this->asToken($this->token)->getJson('/api/request-links')->json('data.0.received'))->toBe(1)
            ->and($this->getJson('/api/library/views')->json('folders.imported'))->toBe(1);
    });

    it('puts sound sent through a link without a project in the Inbox and refuses documents there', function () {
        $link = requestLink($this, ['client_id' => null]);

        sendWhole($this, $link['url'], wav(), 'note.wav', 'audio/wav')->assertOk();
        expect(unscoped(fn () => CaptureSession::query()->sole()->client_id))->toBeNull();
        startUpload($this, $link['url'], "%PDF-1.4\nbody", 'scope.pdf', 'application/pdf')->assertStatus(422)
            ->assertJsonPath('code', 'unsupported_type')->assertJsonPath('message', 'This link takes sound and video files only.');
    });

    it('adds PDF, Word, text and image files to the project\'s Files, encrypted', function () {
        $link = requestLink($this);
        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

        sendWhole($this, $link['url'], "%PDF-1.4\n%âãÏÓ\n1 0 obj << >> endobj\ntrailer\n%%EOF\n", 'scope.pdf')->assertOk();
        sendWhole($this, $link['url'], docx(), 'brief.docx')->assertOk();
        sendWhole($this, $link['url'], "Plain notes from the client.\nSecond line.\n", 'notes.txt', 'text/plain')->assertOk();
        sendWhole($this, $link['url'], $png, 'screen.png', 'image/png')->assertOk();

        $files = unscoped(fn () => ClientFile::query()->where('client_id', $this->project->id)->orderBy('name')->get());
        expect($files->pluck('mime', 'name')->all())->toBe([
            'brief.docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'notes.txt' => 'text/plain',
            'scope.pdf' => 'application/pdf',
            'screen.png' => 'image/png',
        ])->and($files->firstWhere('name', 'screen.png')->status)->toBe('ready')
            ->and(CaptureSession::query()->count())->toBe(0);
        foreach ($files as $file) {
            expect(Storage::disk('recordings')->exists((string) $file->path))->toBeTrue()
                ->and(Storage::disk('recordings')->get((string) $file->path))->not->toContain('client');
        }
        expect(Storage::disk('recordings')->directories('request-parts'))->toBe([]);
    });

    it('keeps a sent file\'s name as plain text: no path, control or direction-changing characters', function () {
        $link = requestLink($this);

        // "notes‮fdp.txt" would show as "notestxt.pdf" in the console.
        sendWhole($this, $link['url'], "Plain notes.\n", "C:\\Users\\x\\notes\u{202E}fdp\u{0000}.txt", 'text/plain')->assertOk();
        sendWhole($this, $link['url'], "More notes.\n", "\u{202E}\u{0007}", 'text/plain')->assertOk();
        expect(unscoped(fn () => ClientFile::query()->orderBy('created_at')->orderBy('id')->pluck('name')->all()))->toEqualCanonicalizing(['notesfdp.txt', 'File']);
    });

    it('refuses types by their content, not only their name', function () {
        $link = requestLink($this);

        startUpload($this, $link['url'], 'MZ fake program', 'setup.exe', 'application/octet-stream')->assertStatus(422)->assertJsonPath('code', 'unsupported_type');
        // A PDF named like a sound file is refused at its first chunk, and nothing of it stays.
        $fake = "%PDF-1.4\n".str_repeat('x', 1000);
        $start = startUpload($this, $link['url'], $fake, 'call.mp3', 'audio/mpeg')->assertCreated();
        sendChunk($this, $link['url'], $start->json('upload_id'), 0, $fake, $start->json('chunk_size'))->assertStatus(422)->assertJsonPath('code', 'unsupported_type');
        expect(unscoped(fn () => CaptureSession::query()->count()))->toBe(0)
            ->and(RequestUpload::query()->find($start->json('upload_id'))->status)->toBe('refused');
        // Sound named like a document, and a ZIP named like a Word file.
        $sound = wav(2000);
        $start = startUpload($this, $link['url'], $sound, 'notes.txt', 'text/plain')->assertCreated();
        sendChunk($this, $link['url'], $start->json('upload_id'), 0, $sound, $start->json('chunk_size'))->assertStatus(422);
        sendWhole($this, $link['url'], docx(word: false), 'fake.docx')->assertStatus(422)->assertJsonPath('code', 'unsupported_type');
        expect(unscoped(fn () => ClientFile::query()->count()))->toBe(0)
            ->and($this->asToken($this->token)->getJson('/api/request-links')->json('data.0.received'))->toBe(0);
    });

    it('refuses files over the link\'s size and more files than it takes', function () {
        $link = requestLink($this, ['max_files' => 1, 'max_mb' => 1]);

        startUpload($this, $link['url'], wav(1024 * 1024 + 10), 'big.wav', 'audio/wav')->assertStatus(413)->assertJsonPath('code', 'too_large');
        sendWhole($this, $link['url'], wav(), 'first.wav', 'audio/wav')->assertOk();
        startUpload($this, $link['url'], wav(), 'second.wav', 'audio/wav')->assertStatus(422)->assertJsonPath('code', 'too_many_files')
            ->assertJsonPath('message', 'This link takes at most 1 file.');
    });

    it('counts files still being sent against the plan\'s storage, so unfinished uploads cannot pass it', function () {
        $small = Plan::query()->create(['key' => 'small', 'name' => 'Small', 'storage_bytes' => 1_000_000]);
        Workspace::query()->whereKey($this->device->workspace_id)->update(['plan_id' => $small->id]);
        $link = requestLink($this);

        startUpload($this, $link['url'], wav(600_000), 'first.wav', 'audio/wav')->assertCreated();
        // The first is not finished, but its 600 KB are taken: a second one of that size does not fit.
        startUpload($this, $link['url'], wav(600_000), 'second.wav', 'audio/wav')->assertForbidden()
            ->assertJsonPath('code', 'storage_limit_reached')->assertJsonPath('message', 'This link cannot take more files right now.');
        startUpload($this, $link['url'], wav(300_000), 'small.wav', 'audio/wav')->assertCreated();
        expect(RequestUpload::query()->count())->toBe(2);
    });

    it('takes no more files than the link allows when another start lands at the same time', function () {
        $link = requestLink($this, ['max_files' => 1]);
        // Another sender's start that slipped in while this one was being made (two requests at once).
        $raced = false;
        RequestUpload::created(function (RequestUpload $upload) use (&$raced): void {
            if (! $raced) {
                $raced = true;
                RequestUpload::query()->create(['request_link_id' => $upload->request_link_id, 'kind' => 'file', 'name' => 'other.pdf', 'size_bytes' => 10,
                    'sha256' => str_repeat('b', 64), 'status' => 'uploading', 'chunk_size_bytes' => 10, 'total_chunks' => 1, 'received_chunks' => []]);
            }
        });

        startUpload($this, $link['url'], wav(), 'mine.wav', 'audio/wav')->assertStatus(422)->assertJsonPath('code', 'too_many_files');
        expect(RequestUpload::query()->count())->toBeLessThanOrEqual(1)
            ->and(unscoped(fn () => CaptureSession::query()->count()))->toBe(0);
    });

    it('keeps every chunk of a document when two of them arrive at once', function () {
        $link = requestLink($this);
        $pdf = "%PDF-1.4\n".str_repeat('x', 600_000);
        $start = startUpload($this, $link['url'], $pdf, 'scope.pdf', 'application/pdf')->assertCreated();
        [$id, $size] = [$start->json('upload_id'), $start->json('chunk_size')];
        // This request read the upload before the other one stored chunk 1.
        $stale = RequestUpload::query()->findOrFail($id);
        sendChunk($this, $link['url'], $id, 1, $pdf, $size)->assertOk();

        $chunk = substr($pdf, 0, $size);
        $body = fopen('php://memory', 'r+');
        fwrite($body, $chunk);
        rewind($body);
        app(RequestLinks::class)->chunk($stale, 0, hash('sha256', $chunk), $body);
        expect(RequestUpload::query()->findOrFail($id)->received_chunks)->toBe([0, 1]);
    });

    it('adds a document once and counts it once when it is finished twice at the same time', function () {
        $link = requestLink($this);
        $text = "Plain notes from the client.\n";
        $start = startUpload($this, $link['url'], $text, 'notes.txt', 'text/plain')->assertCreated();
        $id = $start->json('upload_id');
        sendChunk($this, $link['url'], $id, 0, $text, $start->json('chunk_size'))->assertOk();
        // The same `complete` again while the first one is storing the file (two requests at once).
        $second = null;
        ClientFile::created(function () use (&$second, $id): void {
            if ($second === null) {
                $second = 'running';
                try {
                    $second = app(RequestLinks::class)->complete(RequestUpload::query()->findOrFail($id));
                } catch (ApiError $e) {
                    $second = $e->apiCode();
                }
            }
        });

        visitor($this)->postJson(parse_url($link['url'], PHP_URL_PATH)."/files/{$id}/complete")->assertOk()->assertExactJson(['status' => 'ready']);
        expect($second)->toBe('upload_finished')
            ->and(unscoped(fn () => ClientFile::query()->count()))->toBe(1)
            ->and(RequestLink::query()->withoutGlobalScopes()->findOrFail($link['id'])->received)->toBe(1);
        // Sent again later, it is simply done.
        visitor($this)->postJson(parse_url($link['url'], PHP_URL_PATH)."/files/{$id}/complete")->assertOk()->assertExactJson(['status' => 'ready']);
    });

    it('refuses a small Word file whose body would unpack into too much', function () {
        $link = requestLink($this);
        $bomb = docx(text: str_repeat('a', ClientWorkspace::MAX_WORD_BODY_BYTES));
        expect(strlen($bomb))->toBeLessThan(1024 * 1024);

        sendWhole($this, $link['url'], $bomb, 'huge.docx')->assertStatus(413)->assertJsonPath('code', 'too_large')
            ->assertJsonPath('message', ClientWorkspace::WORD_TOO_LARGE);
        expect(unscoped(fn () => ClientFile::query()->count()))->toBe(0)
            ->and(RequestUpload::query()->sole()->status)->toBe('refused')
            ->and(Storage::disk('recordings')->directories('request-parts'))->toBe([]);
    });

    it('refuses documents over 20 MB even when the link takes more', function () {
        $link = requestLink($this, ['max_mb' => 100]);

        visitor($this)->postJson(parse_url($link['url'], PHP_URL_PATH).'/files', ['name' => 'huge.pdf', 'size' => 21 * 1024 * 1024, 'sha256' => str_repeat('a', 64)])
            ->assertStatus(413)->assertJsonPath('code', 'too_large');
    });

    it('picks up an unfinished upload of the same file', function () {
        $link = requestLink($this);
        $bytes = wav(600_000);
        $first = startUpload($this, $link['url'], $bytes, 'call.wav', 'audio/wav')->assertCreated();
        sendChunk($this, $link['url'], $first->json('upload_id'), 0, $bytes, $first->json('chunk_size'))->assertOk();

        $again = startUpload($this, $link['url'], $bytes, 'call.wav', 'audio/wav')->assertOk();
        expect($again->json('upload_id'))->toBe($first->json('upload_id'))->and($again->json('missing_chunks'))->toBe([1, 2]);
    });

    it('ends uploads nobody finished after a day, with their recording and parts, and stops counting them', function () {
        $link = requestLink($this, ['max_files' => 2]);
        $sound = wav(600_000);
        $call = startUpload($this, $link['url'], $sound, 'call.wav', 'audio/wav')->assertCreated();
        sendChunk($this, $link['url'], $call->json('upload_id'), 0, $sound, $call->json('chunk_size'))->assertOk();
        $pdf = "%PDF-1.4\n".str_repeat('x', 300_000);
        $scope = startUpload($this, $link['url'], $pdf, 'scope.pdf', 'application/pdf')->assertCreated();
        sendChunk($this, $link['url'], $scope->json('upload_id'), 0, $pdf, $scope->json('chunk_size'))->assertOk();
        $recording = RequestUpload::query()->findOrFail($call->json('upload_id'))->recording_id;
        startUpload($this, $link['url'], wav(), 'third.wav', 'audio/wav')->assertStatus(422)->assertJsonPath('code', 'too_many_files');

        app(TempFileSweeper::class)->sweep();
        expect(RequestUpload::query()->where('status', 'uploading')->count())->toBe(2);

        $this->travel(RequestLinks::ABANDONED_HOURS + 1)->hours();
        // Before any sweep, uploads a day old no longer take the link's places.
        $third = startUpload($this, $link['url'], wav(), 'third.wav', 'audio/wav')->assertCreated()->json('upload_id');
        app(TempFileSweeper::class)->sweep();

        expect(RequestUpload::query()->whereKey([$call->json('upload_id'), $scope->json('upload_id')])->pluck('status')->all())->toBe(['failed', 'failed'])
            ->and(RequestUpload::query()->findOrFail($third)->status)->toBe('uploading')
            ->and(unscoped(fn () => CaptureSession::query()->pluck('title')->all()))->toBe(['third'])
            ->and(Recording::query()->find($recording))->toBeNull()
            ->and(Storage::disk('recordings')->directoryExists("parts/{$recording}"))->toBeFalse()
            ->and(Storage::disk('recordings')->directories('request-parts'))->toBe([]);
        // Coming back to one says it was not finished.
        sendChunk($this, $link['url'], $scope->json('upload_id'), 1, $pdf, $scope->json('chunk_size'))->assertStatus(409)
            ->assertJsonPath('code', 'upload_finished')->assertJsonPath('message', RequestLinks::ABANDONED);
    });

    it('ends an unfinished upload an hour after its link is revoked, and keeps one whose every chunk came in', function () {
        $link = requestLink($this);
        $pdf = "%PDF-1.4\n".str_repeat('x', 300_000);
        $scope = startUpload($this, $link['url'], $pdf, 'scope.pdf', 'application/pdf')->assertCreated();
        sendChunk($this, $link['url'], $scope->json('upload_id'), 0, $pdf, $scope->json('chunk_size'))->assertOk();
        // A recording whose request died after every chunk was in and the file put together.
        $sound = wav(200_000);
        $call = startUpload($this, $link['url'], $sound, 'late.wav', 'audio/wav')->assertCreated();
        foreach ($call->json('missing_chunks') as $index) {
            sendChunk($this, $link['url'], $call->json('upload_id'), $index, $sound, $call->json('chunk_size'))->assertOk();
        }
        app(ChunkedUploadService::class)->markComplete(Recording::query()->findOrFail(RequestUpload::query()->findOrFail($call->json('upload_id'))->recording_id));
        $this->asToken($this->token)->deleteJson('/api/request-links/'.$link['id'])->assertNoContent();

        $this->travel(30)->minutes();
        app(TempFileSweeper::class)->sweep();
        expect(RequestUpload::query()->findOrFail($scope->json('upload_id'))->status)->toBe('uploading');

        $this->travel(31)->minutes();
        app(TempFileSweeper::class)->sweep();
        expect(RequestUpload::query()->findOrFail($scope->json('upload_id')))->status->toBe('failed')->error->toBe(RequestLinks::ABANDONED)
            ->and(RequestUpload::query()->findOrFail($call->json('upload_id'))->status)->toBe('ready')
            ->and(unscoped(fn () => CaptureSession::query()->where('title', 'late')->sole()->primaryRecording->isReady()))->toBeTrue()
            ->and(RequestLink::query()->withoutGlobalScopes()->findOrFail($link['id'])->received)->toBe(1)
            ->and(Storage::disk('recordings')->directories('request-parts'))->toBe([]);
    });

    it('throttles the page and every upload call', function () {
        expect(Route::getRoutes()->getByName('request.show')->gatherMiddleware())->toContain('throttle:share');
        foreach (['request.files.store', 'request.files.chunk', 'request.files.complete'] as $name) {
            expect(Route::getRoutes()->getByName($name)->gatherMiddleware())->toContain('throttle:request-upload');
        }
    });
});
