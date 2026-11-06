<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\TranscriptErrorCode;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Enums\UploadStatus;
use App\Jobs\AssembleRecordingJob;
use App\Jobs\ExtractClientFileJob;
use App\Jobs\TranscribeFinalJob;
use App\Models\AccountExport;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\ClientFile;
use App\Models\Recording;
use App\Models\RequestUpload;
use App\Models\Screen;
use App\Models\StorageDeletion;
use App\Models\User;
use App\Services\Accounts\AccountDeletion;
use App\Services\Accounts\AccountExporter;
use App\Services\ChunkedUploadService;
use App\Services\ClientAi\ClientWorkspace;
use App\Services\ConsoleUploads;
use App\Services\CredentialVault;
use App\Services\DoubleCheck;
use App\Services\Projects\ProjectTree;
use App\Services\RecordingCipher;
use App\Services\RetentionPolicy;
use App\Services\Screens\ScreenStore;
use App\Services\SettingsRepository;
use App\Services\Storage\RecordingFiles;
use App\Services\TempFileSweeper;
use App\Services\TranscriptStore;
use App\Stt\Audio\AudioPrep;
use App\Stt\SttManager;
use App\Stt\Support\WavFile;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;
use Tests\Feature\Storage\Support\FakeS3Server;

uses(InteractsWithDeviceApi::class);

require_once __DIR__.'/../Api/Support/screen_helpers.php';

/*
 * RECORDINGS_DRIVER=s3 (docs/DEPLOY_VPS.md): everything that used a path on the recordings disk, with the disk in a
 * bucket: the real adapter over a fake bucket that checks every signature. The storage company must never see plaintext,
 * and no copy may stay on the server's disk.
 */

const S3_MARKER = 'PLAINTEXT-ON-ITS-WAY-TO-THE-BUCKET';

beforeEach(function () {
    Sleep::fake();
    config(['echoledger.max_chunk_bytes' => 256 * 1024]);
    $this->bucket = FakeS3Server::install();
    [$this->device, $this->token] = $this->enroll(platform: 'android');
    $this->asToken($this->token);
});

/** No plaintext or encrypted copy left in the temp directory, and nothing on the server's own recordings disk. */
function s3ExpectNoLocalCopies(object $test): void
{
    expect($test->workFiles())->toBe([])
        ->and(File::allFiles((string) config('filesystems.disks.recordings.root')))->toBe([]);
}

function s3ObjectBody(object $test, string $key): string
{
    return $test->bucket->objects[$key]['body'];
}

function s3PlainOf(Recording $recording): string
{
    $path = app(ChunkedUploadService::class)->decryptToTemp($recording);
    try {
        return (string) file_get_contents($path);
    } finally {
        @unlink($path);
    }
}

function s3PlainOfFile(ClientFile $file): string
{
    $plain = app(RecordingFiles::class)->decryptToMemory((string) $file->path, $file->id);
    $plain->rewind();
    $bytes = '';
    while (! $plain->eof()) {
        $bytes .= $plain->fread(1 << 20);
    }

    return $bytes;
}

function s3NoEnginePrep(): void
{
    app()->instance(AudioPrep::class, new class(app(SettingsRepository::class)) extends AudioPrep
    {
        public function available(): bool
        {
            return false;
        }
    });
}

/** @return array{id: string, size: int, missing: list<int>} */
function s3StartRequestUpload(object $test, string $bytes, string $name): array
{
    $start = $test->postJson($test->linkPath.'/files', ['name' => $name, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'type' => ''])->assertCreated();

    return ['id' => $start->json('upload_id'), 'size' => $start->json('chunk_size'), 'missing' => $start->json('missing_chunks')];
}

function s3SendRequestChunk(object $test, array $upload, int $index, string $bytes): TestResponse
{
    $chunk = substr($bytes, $index * $upload['size'], $upload['size']);
    $server = ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json', 'HTTP_UPLOAD_CHUNK_SHA256' => hash('sha256', $chunk)];

    return $test->call('POST', $test->linkPath."/files/{$upload['id']}/chunks/{$index}", [], [], [], $server, $chunk);
}

/** `complete` sent again finishes a document, with one file and nothing left over. */
function s3ExpectCompleteFinishes(object $test): void
{
    $test->postJson($test->complete)->assertOk()->assertJsonPath('status', 'ready');
    $file = ClientFile::query()->withoutGlobalScopes()->where('client_id', $test->project->id)->sole();
    expect($file->path)->not->toBeNull()
        ->and($test->bucket->keys())->toBe([$file->path])
        ->and(RequestUpload::query()->findOrFail($test->upload['id'])->client_file_id)->toBe($file->id)
        ->and(s3PlainOfFile($file))->toBe($test->text);
    s3ExpectNoLocalCopies($test);
}

/** One job of a real queue: the worker of the deploy notes, with its waits and its failed(). */
function s3RunOneQueuedJob(): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 3, '--sleep' => 0, '--memory' => 4096]);
}

describe('an upload', function () {
    it('keeps the parts encrypted in the bucket, assembles them and removes them', function () {
        $audio = Fixtures::audio(600_000, S3_MARKER);
        $created = $this->createSession($audio)->assertSuccessful();
        $id = (string) $created->json('recordings.0.id');
        $this->uploadAll($id, $audio, (int) $created->json('recordings.0.upload.chunk_size_bytes'));

        $parts = $this->bucket->keys("parts/{$id}/");
        expect($parts)->toHaveCount(3)
            ->and($this->bucket->holds(S3_MARKER))->toBeFalse()
            ->and($this->workFiles())->toBe([]);
        foreach ($parts as $part) {
            expect(s3ObjectBody($this, $part))->toStartWith(RecordingCipher::MAGIC);
        }

        $this->bucket->log = [];
        $this->postJson("/api/recordings/{$id}/complete")->assertStatus(202)->assertJsonPath('status', 'ready');

        // One request a part: a recording of 2 GB has 400 of them.
        expect($this->bucket->requests('HEAD parts/'))->toBe(0)
            ->and($this->bucket->requests("GET parts/{$id}/"))->toBe(3);
        $recording = Recording::query()->findOrFail($id);
        expect($recording->upload_status)->toBe(UploadStatus::Ready)
            ->and($this->bucket->keys())->toBe([$recording->stored_path])
            ->and($recording->stored_size_bytes)->toBe(strlen(s3ObjectBody($this, (string) $recording->stored_path)))
            ->and($this->bucket->holds(S3_MARKER))->toBeFalse()
            ->and(s3ObjectBody($this, (string) $recording->stored_path))->toStartWith(RecordingCipher::MAGIC)
            ->and(s3PlainOf($recording))->toBe($audio);
        s3ExpectNoLocalCopies($this);
    });

    it('says which chunks are missing from one listing, not a question per chunk', function () {
        $audio = Fixtures::audio(600_000);
        $created = $this->createSession($audio)->assertSuccessful();
        $id = (string) $created->json('recordings.0.id');
        $size = (int) $created->json('recordings.0.upload.chunk_size_bytes');
        $this->uploadAll($id, $audio, $size, [0, 2]);

        $this->bucket->log = [];
        $this->postJson("/api/recordings/{$id}/complete")->assertStatus(422)
            ->assertJsonPath('code', 'upload_incomplete')->assertJsonPath('missing_chunks', [1]);
        expect($this->bucket->requests('HEAD '))->toBe(0)->and($this->bucket->log)->toHaveCount(1);

        // A chunk that went missing from the bucket counts as missing, whatever the row says.
        unset($this->bucket->objects["parts/{$id}/0.part"]);
        $this->uploadAll($id, $audio, $size, [1]);
        $this->postJson("/api/recordings/{$id}/complete")->assertStatus(422)->assertJsonPath('missing_chunks', [0]);
    });

    it('checks a chunk before it goes to the bucket and takes it again', function () {
        $audio = Fixtures::audio(600_000);
        $created = $this->createSession($audio)->assertSuccessful();
        $id = (string) $created->json('recordings.0.id');
        $chunks = Fixtures::chunks($audio, (int) $created->json('recordings.0.upload.chunk_size_bytes'));

        $this->postChunk($id, 0, $chunks[0], str_repeat('0', 64))->assertStatus(422);
        $this->postChunk($id, 0, $chunks[0].'x')->assertStatus(413);
        expect($this->bucket->keys())->toBe([])->and($this->workFiles())->toBe([]);

        $this->postChunk($id, 0, $chunks[0])->assertSuccessful();
        $this->postChunk($id, 0, $chunks[0])->assertSuccessful();
        expect($this->bucket->keys())->toBe(["parts/{$id}/0.part"]);
    });

    it('leaves nothing behind when the bucket refuses a chunk, and takes it when it is back', function () {
        $audio = Fixtures::audio(600_000);
        $created = $this->createSession($audio)->assertSuccessful();
        $id = (string) $created->json('recordings.0.id');
        $chunks = Fixtures::chunks($audio, (int) $created->json('recordings.0.upload.chunk_size_bytes'));

        $this->bucket->failNext('PUT', 503, 3);
        $failed = $this->postChunk($id, 0, $chunks[0]);
        expect($failed->status())->toBeGreaterThanOrEqual(500)
            ->and($this->workFiles())->toBe([])
            ->and(Recording::query()->findOrFail($id)->received_chunks)->toBe([]);

        $this->postChunk($id, 0, $chunks[0])->assertSuccessful();
    });

    it('does not take a part that was swapped or changed in the bucket, and leaves no plaintext', function () {
        $audio = Fixtures::audio(600_000);
        $created = $this->createSession($audio)->assertSuccessful();
        $id = (string) $created->json('recordings.0.id');
        $this->uploadAll($id, $audio, (int) $created->json('recordings.0.upload.chunk_size_bytes'));
        $recording = Recording::query()->findOrFail($id);
        $swap = $this->bucket->objects["parts/{$id}/0.part"];
        $this->bucket->objects["parts/{$id}/0.part"] = $this->bucket->objects["parts/{$id}/1.part"];
        $this->bucket->objects["parts/{$id}/1.part"] = $swap;

        expect(fn () => app(ChunkedUploadService::class)->assemble($recording))->toThrow(RuntimeException::class);
        expect($this->workFiles())->toBe([])->and($recording->refresh()->stored_path)->toBeNull();
    });

    it('does not take a bucket that is down for missing chunks', function () {
        $audio = Fixtures::audio(600_000);
        $created = $this->createSession($audio)->assertSuccessful();
        $id = (string) $created->json('recordings.0.id');
        $this->uploadAll($id, $audio, (int) $created->json('recordings.0.upload.chunk_size_bytes'));

        $this->bucket->failNext('GET', 503, 3);
        expect($this->postJson("/api/recordings/{$id}/complete")->status())->toBeGreaterThanOrEqual(500);

        $recording = Recording::query()->findOrFail($id);
        expect($recording->upload_status)->toBe(UploadStatus::Uploading)
            ->and($recording->received_chunks)->toBe([0, 1, 2])
            ->and($this->bucket->keys("parts/{$id}/"))->toHaveCount(3);
    });

    it('keeps the parts and no plaintext when the bucket fails during assembly, and finishes on the next try', function () {
        $audio = Fixtures::audio(600_000);
        $created = $this->createSession($audio)->assertSuccessful();
        $id = (string) $created->json('recordings.0.id');
        $this->uploadAll($id, $audio, (int) $created->json('recordings.0.upload.chunk_size_bytes'));
        $recording = Recording::query()->findOrFail($id);
        $uploads = app(ChunkedUploadService::class);

        $this->bucket->failNext('GET', 503, 3, "parts/{$id}/1.part");
        expect(fn () => $uploads->assemble($recording))->toThrow(UnableToReadFile::class);
        expect($this->workFiles())->toBe([])->and($this->bucket->keys("parts/{$id}/"))->toHaveCount(3);

        $this->bucket->failNext('PUT', 503, 3, 'files/');
        expect(fn () => $uploads->assemble($recording))->toThrow(UnableToWriteFile::class);
        expect($this->workFiles())->toBe([])->and($this->bucket->keys("parts/{$id}/"))->toHaveCount(3)
            ->and($recording->refresh()->stored_path)->toBeNull();

        $uploads->assemble($recording);
        expect(s3PlainOf($recording->refresh()))->toBe($audio)->and($this->bucket->keys('parts/'))->toBe([]);
    });

    it('still goes on when the bucket fails to remove the parts at the end, and the sweep removes them later', function () {
        $audio = Fixtures::audio(600_000);
        $created = $this->createSession($audio)->assertSuccessful();
        $id = (string) $created->json('recordings.0.id');
        $this->uploadAll($id, $audio, (int) $created->json('recordings.0.upload.chunk_size_bytes'));

        $this->bucket->failNext('DELETE', 503, 3, 'parts/');
        $this->postJson("/api/recordings/{$id}/complete")->assertStatus(202)->assertJsonPath('status', 'ready');
        $left = $this->bucket->keys("parts/{$id}/");
        expect(Recording::query()->findOrFail($id)->isReady())->toBeTrue()->and($left)->not->toBe([]);

        $this->travel(TempFileSweeper::ORPHANED_PARTS_HOURS + 1)->hours();
        app(TempFileSweeper::class)->sweep();
        expect($this->bucket->keys("parts/{$id}/"))->toBe([]);
    });

    it('recognises a console upload by its first chunk, through the encryption', function () {
        $session = CaptureSession::factory()->create(['user_id' => $this->device->user_id, 'device_id' => $this->device->id, 'status' => SessionStatus::Uploading]);
        $uploads = app(ChunkedUploadService::class);
        $wav = WavFile::header(1, 16000, 16, 5000).random_bytes(5000);
        $media = $uploads->createRecording($session, Fixtures::artifact($wav), 1024 * 1024);
        $other = CaptureSession::factory()->create(['user_id' => $this->device->user_id, 'device_id' => $this->device->id, 'status' => SessionStatus::Uploading]);
        $script = '<?php echo "not sound"; '.str_repeat(' ', 3000);
        $notMedia = $uploads->createRecording($other, Fixtures::artifact($script), 1024 * 1024);

        expect(ConsoleUploads::firstChunkIsMedia($media))->toBeFalse(); // nothing sent yet

        $stream = fn (string $bytes) => tap(fopen('php://memory', 'w+b'), function ($h) use ($bytes): void {
            fwrite($h, $bytes);
            rewind($h);
        });
        $uploads->storeChunk($media, 0, hash('sha256', $wav), $stream($wav));
        $uploads->storeChunk($notMedia, 0, hash('sha256', $script), $stream($script));

        expect(ConsoleUploads::firstChunkIsMedia($media))->toBeTrue()
            ->and(ConsoleUploads::firstChunkIsMedia($notMedia))->toBeFalse()
            ->and($this->workFiles())->toBe([]);
    });
});

describe('a queued assembly', function () {
    beforeEach(function () {
        config(['queue.default' => 'database']);
        $this->audio = Fixtures::audio(600_000, S3_MARKER);
        $created = $this->createSession($this->audio)->assertSuccessful();
        $this->id = (string) $created->json('recordings.0.id');
        $this->uploadAll($this->id, $this->audio, (int) $created->json('recordings.0.upload.chunk_size_bytes'));
        Recording::query()->findOrFail($this->id)->update(['upload_status' => UploadStatus::Assembling]);
        AssembleRecordingJob::dispatch($this->id);
    });

    it('waits between its tries, so a few seconds of trouble cost nothing', function () {
        $this->bucket->failNext('GET', 503, 3, "parts/{$this->id}/1.part");

        s3RunOneQueuedJob();

        $recording = Recording::query()->findOrFail($this->id);
        expect($recording->upload_status)->toBe(UploadStatus::Assembling)
            ->and($recording->received_chunks)->toBe([0, 1, 2])
            ->and($this->bucket->keys("parts/{$this->id}/"))->toHaveCount(3)
            ->and($this->workFiles())->toBe([])
            ->and(DB::table('failed_jobs')->count())->toBe(0);

        // Not before its wait is over.
        $this->bucket->log = [];
        s3RunOneQueuedJob();
        expect($this->bucket->requests("GET parts/{$this->id}/"))->toBe(0);

        $this->travel(61)->seconds();
        s3RunOneQueuedJob();

        expect($recording->refresh()->upload_status)->toBe(UploadStatus::Ready)
            ->and(s3PlainOf($recording))->toBe($this->audio)
            ->and($this->bucket->keys('parts/'))->toBe([]);
    });

    it('asks for the file again only after a trouble that outlasts every wait', function () {
        $this->bucket->failNext('GET', 503, 9, "parts/{$this->id}/1.part");

        s3RunOneQueuedJob();
        $this->travel(61)->seconds();
        s3RunOneQueuedJob();
        expect($this->bucket->keys("parts/{$this->id}/"))->toHaveCount(3)
            ->and(Recording::query()->findOrFail($this->id)->upload_status)->toBe(UploadStatus::Assembling);

        $this->travel(301)->seconds();
        s3RunOneQueuedJob();

        $recording = Recording::query()->findOrFail($this->id);
        expect($recording->upload_status)->toBe(UploadStatus::Failed)
            ->and($recording->error)->toBe(ChunkedUploadService::NOT_FINISHED)
            ->and($recording->received_chunks)->toBe([])
            ->and($this->bucket->keys('parts/'))->toBe([])
            ->and(DB::table('failed_jobs')->count())->toBe(1)
            ->and($this->workFiles())->toBe([]);
    });
});

describe('playback', function () {
    beforeEach(function () {
        $this->audio = Fixtures::audio(300_000, S3_MARKER);
        $this->session = $this->makeReadySession($this->device, $this->audio, ['status' => SessionStatus::Completed]);
        $this->url = $this->getJson("/api/sessions/{$this->session->id}")->assertOk()->json('playback_url');
        $this->flushHeaders();
    });

    it('plays the whole file and a range from a decrypted copy, gone once sent', function () {
        $whole = $this->get($this->url)->assertOk();
        expect(count($this->workFiles()))->toBe(1) // the decrypted copy being served, the encrypted one is gone
            ->and($whole->streamedContent())->toBe($this->audio)
            ->and($this->workFiles())->toBe([]);

        $range = $this->withHeaders(['Range' => 'bytes=100-199'])->get($this->url);
        $range->assertStatus(206);
        expect($range->headers->get('Content-Range'))->toBe('bytes 100-199/'.strlen($this->audio))
            ->and($range->streamedContent())->toBe(substr($this->audio, 100, 100))
            ->and($this->workFiles())->toBe([]);
    });

    it('answers 416 past the end without a copy left', function () {
        $this->withHeaders(['Range' => 'bytes='.strlen($this->audio).'-'])->get($this->url)->assertStatus(416);

        expect($this->workFiles())->toBe([]);
    });

    it('fails without a copy left when the bucket is down or the download is cut short', function () {
        $this->bucket->failNext('GET', 503, 3);
        expect($this->get($this->url)->status())->toBeGreaterThanOrEqual(500)
            ->and($this->workFiles())->toBe([]);

        $this->bucket->cutNextDownloads(1000, 3);
        expect($this->get($this->url)->status())->toBeGreaterThanOrEqual(500)
            ->and($this->workFiles())->toBe([]);

        $this->get($this->url)->assertOk();
    });

    it('starts a download again when the connection drops once', function () {
        $this->bucket->cutNextDownloads(1000, 1);

        expect($this->get($this->url)->assertOk()->streamedContent())->toBe($this->audio);
    });
});

describe('engines', function () {
    it('sends Groq the decrypted audio from a local copy, removed afterwards', function () {
        s3NoEnginePrep();
        app(CredentialVault::class)->put('groq', 'api_key', 'gsk_test_key_1234567890');
        $audio = Fixtures::audio(4000, S3_MARKER);
        $session = $this->makeReadySession($this->device, $audio, ['language' => 'en', 'final_provider' => 'groq']);
        $transcript = $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'groq', 'status' => TranscriptStatus::Pending, 'language' => 'en']);
        $sent = '';
        Http::fake(['api.groq.com/*' => function (HttpRequest $request) use (&$sent) {
            $sent .= $request->body();

            return Http::response(['task' => 'transcribe', 'language' => 'english', 'duration' => 2.0, 'text' => 'Hello there',
                'segments' => [['id' => 0, 'start' => 0.0, 'end' => 2.0, 'text' => 'Hello there', 'avg_logprob' => -0.2, 'compression_ratio' => 1.2, 'no_speech_prob' => 0.01]], 'words' => []]);
        }]);

        dispatch_sync(new TranscribeFinalJob($transcript->id));

        expect($sent)->toContain(S3_MARKER)
            ->and($transcript->refresh()->status)->toBe(TranscriptStatus::Completed)
            ->and($this->bucket->holds(S3_MARKER))->toBeFalse();
        s3ExpectNoLocalCopies($this);
    });

    it('sends Gemini the decrypted audio from a local copy, removed afterwards', function () {
        Queue::fake();
        s3NoEnginePrep();
        app(CredentialVault::class)->put('gemini', 'api_key', 'AIzaGeminiTestKey_1234567890abcdef');
        $audio = Fixtures::audio(4000, S3_MARKER);
        $session = $this->makeReadySession($this->device, $audio, ['language' => 'en', 'final_provider' => 'gemini']);
        $transcript = $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'gemini', 'status' => TranscriptStatus::Pending, 'language' => 'en']);
        $base = 'https://generativelanguage.googleapis.com';
        $sent = '';
        Http::fake(['generativelanguage.googleapis.com/*' => function (HttpRequest $request) use (&$sent, $base) {
            $url = $request->url();
            $sent .= $request->body();

            return match (true) {
                $request->method() === 'POST' && str_contains($url, '/upload/v1beta/files') && ! str_contains($url, 'upload_id') => Http::response([], 200, ['X-Goog-Upload-URL' => $base.'/upload/v1beta/files?upload_id=up1&upload_protocol=resumable']),
                str_contains($url, 'upload_id=') => Http::response(['file' => ['name' => 'files/a', 'uri' => $base.'/v1beta/files/a', 'mimeType' => 'audio/ogg', 'state' => 'ACTIVE']]),
                str_contains($url, ':generateContent') => Http::response(['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => json_encode(['language' => 'en', 'segments' => [['start_ms' => 0, 'end_ms' => 1500, 'text' => 'Hello there']]])]]], 'finishReason' => 'STOP']],
                    'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 10, 'totalTokenCount' => 20]]),
                default => Http::response([], 200),
            };
        }]);

        (new TranscribeFinalJob($transcript->id))->handle(app(SttManager::class), app(ChunkedUploadService::class), app(TranscriptStore::class));

        expect($sent)->toContain(S3_MARKER)->and($this->bucket->holds(S3_MARKER))->toBeFalse();
        s3ExpectNoLocalCopies($this);
    });

    it('gives ffmpeg a local file in the double check, and removes it', function () {
        app(CredentialVault::class)->put('groq', 'api_key', 'gsk_test_key_1234567890');
        app(SettingsRepository::class)->set('accuracy.double_check', true);
        $audio = Fixtures::audio(4000, S3_MARKER);
        $prep = new class(app(SettingsRepository::class)) extends AudioPrep
        {
            /** @var list<string> */
            public array $heard = [];

            public function available(): bool
            {
                return true;
            }

            public function extractChannel(string $input, ?int $channel, string $out): string
            {
                $this->heard[] = (string) file_get_contents($input);
                file_put_contents($out, 'side');

                return $out;
            }

            public function cut(string $wav, float $startS, float $endS, string $out): string
            {
                file_put_contents($out, 'clip');

                return $out;
            }
        };
        app()->instance(AudioPrep::class, $prep);
        $session = $this->makeReadySession($this->device, $audio, ['language' => 'en', 'status' => 'completed']);
        $transcript = $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Completed, 'language' => 'en', 'text' => 'x',
            'segments' => [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 8000, 'text' => 'Fine, see you Monday', 'uncertain' => true]]]);
        Http::fake(['api.groq.com/*' => Http::response(['task' => 'transcribe', 'language' => 'english', 'duration' => 2.6, 'text' => 'Fine, see you Monday',
            'segments' => [['id' => 0, 'start' => 0.0, 'end' => 2.6, 'text' => 'Fine, see you Monday', 'avg_logprob' => -0.2, 'compression_ratio' => 1.2, 'no_speech_prob' => 0.01]], 'words' => []])]);

        expect(app(DoubleCheck::class)->run($transcript))->toMatchArray(['checked' => 1, 'failed' => 0])
            ->and(array_values(array_unique($prep->heard)))->toBe([$audio]);
        s3ExpectNoLocalCopies($this);
    });
});

describe('screens', function () {
    beforeEach(function () {
        $this->session = $this->makeReadySession($this->device, Fixtures::audio(3000), ['status' => SessionStatus::Completed]);
        $this->png = screenPicture('png', 1280, 720);
        $this->screen = keepScreen($this->session, $this->png);
    });

    it('keeps a picture and its thumbnail encrypted, counts what they take and reads them back', function () {
        $store = app(ScreenStore::class);
        $sizes = 0;
        foreach ([$this->screen->image_path, $this->screen->thumb_path] as $key) {
            $raw = s3ObjectBody($this, (string) $key);
            expect($raw)->toStartWith(RecordingCipher::MAGIC)->not->toContain("\x89PNG")->not->toContain('WEBPVP8');
            $sizes += strlen($raw);
        }

        expect($this->screen->stored_bytes)->toBe($sizes)
            ->and($store->picture($this->screen))->toBe($this->png)
            ->and(getimagesizefromstring((string) $store->picture($this->screen, thumb: true))['mime'])->toBe('image/webp');
        $file = app(ChunkedUploadService::class)->tempPath('out');
        expect($store->pictureToFile($this->screen, $file))->toBe(strlen($this->png))
            ->and(file_get_contents($file))->toBe($this->png);
        @unlink($file);
        expect($this->workFiles())->toBe([]);
    });

    it('answers null for a picture that is gone', function () {
        unset($this->bucket->objects[$this->screen->image_path]);

        expect(app(ScreenStore::class)->picture($this->screen))->toBeNull()
            ->and(app(ScreenStore::class)->pictureToFile($this->screen, app(ChunkedUploadService::class)->tempPath('out')))->toBeNull();
    });

    it('copies a picture for another recording under its own key and id, and erases it', function () {
        $copy = CaptureSession::factory()->create(['user_id' => $this->device->user_id, 'device_id' => $this->device->id, 'status' => SessionStatus::Completed]);
        $written = app(ScreenStore::class)->copy($this->screen, $copy);
        $twin = Screen::query()->where('capture_session_id', $copy->id)->sole();

        expect($written)->toHaveCount(2)
            ->and(app(ScreenStore::class)->picture($twin))->toBe($this->png)
            ->and($twin->stored_bytes)->toBe(strlen(s3ObjectBody($this, (string) $twin->image_path)) + strlen(s3ObjectBody($this, (string) $twin->thumb_path)));
        expect($this->workFiles())->toBe([]);

        app(ScreenStore::class)->delete($twin);
        expect($this->bucket->objects)->not->toHaveKey((string) $twin->image_path)->not->toHaveKey((string) $twin->thumb_path);
    });

    it('builds the meeting pack with the pictures and the audio', function () {
        $this->session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'groq', 'status' => TranscriptStatus::Completed, 'language' => 'en', 'text' => 'x',
            'segments' => [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 900, 'text' => 'Hello']]]);

        $response = $this->get("/api/sessions/{$this->session->id}/pack?format=zip&include_audio=1", ['Accept' => 'application/json'])->assertOk();

        $zip = new ZipArchive;
        expect($zip->open($response->baseResponse->getFile()->getPathname()))->toBeTrue();
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        expect(collect($names)->contains(fn (string $name): bool => str_ends_with($name, '.png')))->toBeTrue()
            ->and(collect($names)->contains('transcript.md'))->toBeTrue()
            ->and($zip->numFiles)->toBe(3);
        $zip->close();
    });
});

describe('a share link', function () {
    it('gives a ZIP with the audio', function () {
        $audio = Fixtures::audio(4000, S3_MARKER);
        $session = $this->makeReadySession($this->device, $audio, ['title' => 'Budget', 'status' => SessionStatus::Completed]);
        $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'groq', 'status' => TranscriptStatus::Completed, 'language' => 'en', 'text' => 'x',
            'segments' => [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 900, 'text' => 'Hello']]]);
        $share = $this->postJson("/api/sessions/{$session->id}/shares", ['allow_download' => true])->assertCreated()->json();
        app('auth')->forgetGuards();

        $response = $this->withHeaders(['Authorization' => ''])->get(parse_url($share['url'], PHP_URL_PATH).'/download')->assertOk();

        $zip = new ZipArchive;
        expect($zip->open($response->baseResponse->getFile()->getPathname()))->toBeTrue();
        $bodies = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $bodies[] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        expect($bodies)->toContain($audio);
    });
});

describe('a project file', function () {
    it('is kept encrypted, read back for its text and deleted', function () {
        Queue::fake();
        $project = Client::factory()->create(['user_id' => $this->device->user_id, 'name' => 'Acme']);
        $plain = app(ChunkedUploadService::class)->tempPath('file');
        file_put_contents($plain, 'Scope of the works: '.S3_MARKER);
        $workspace = app(ClientWorkspace::class);

        $file = $workspace->storeFile($project, $plain, 'scope.txt', 'text/plain', (int) filesize($plain), $this->device->user_id);
        @unlink($plain);

        Queue::assertPushed(ExtractClientFileJob::class);
        expect($file->path)->toBe("client-files/{$project->id}/{$file->id}.elr")
            ->and($this->bucket->holds(S3_MARKER))->toBeFalse();

        $workspace->extract($file->refresh());
        expect($file->refresh()->status)->toBe('ready')->and($file->text)->toContain(S3_MARKER);
        s3ExpectNoLocalCopies($this);

        $workspace->deleteFile($file);
        expect($this->bucket->keys('client-files/'))->toBe([])
            ->and(ClientFile::query()->withoutGlobalScopes()->find($file->id))->toBeNull();
    });

    it('leaves no row behind when the bucket does not take a file, a picture neither, and adding it again adds one', function (string $name, string $mime, string $status) {
        Queue::fake();
        $project = Client::factory()->create(['user_id' => $this->device->user_id, 'name' => 'Acme']);
        $plain = app(ChunkedUploadService::class)->tempPath('file');
        file_put_contents($plain, 'Scope');
        $workspace = app(ClientWorkspace::class);

        $this->bucket->failNext('PUT', 503, 3, 'client-files/');
        expect(fn () => $workspace->storeFile($project, $plain, $name, $mime, 5, $this->device->user_id))->toThrow(UnableToWriteFile::class);

        Queue::assertNothingPushed();
        expect(ClientFile::query()->withoutGlobalScopes()->count())->toBe(0)
            ->and($this->bucket->keys())->toBe([])
            // What it may have kept with only the answer lost.
            ->and($this->bucket->requests('DELETE client-files/'))->toBe(1)
            ->and(StorageDeletion::query()->count())->toBe(0);

        $file = $workspace->storeFile($project, $plain, $name, $mime, 5, $this->device->user_id);
        @unlink($plain);
        expect(ClientFile::query()->withoutGlobalScopes()->sole()->id)->toBe($file->id)
            ->and($file->status)->toBe($status)
            ->and($this->bucket->keys())->toBe([$file->path]);
    })->with([
        'a document' => ['scope.txt', 'text/plain', 'extracting'],
        'a picture' => ['plan.png', 'image/png', 'ready'],
    ]);

    it('remembers to erase a file it could not take when the bucket is down altogether', function () {
        Queue::fake();
        $project = Client::factory()->create(['user_id' => $this->device->user_id, 'name' => 'Acme']);
        $plain = app(ChunkedUploadService::class)->tempPath('file');
        file_put_contents($plain, 'Scope');

        $this->bucket->dropNext('PUT', 3, 'client-files/');
        $this->bucket->dropNext('DELETE', 3, 'client-files/');
        expect(fn () => app(ClientWorkspace::class)->storeFile($project, $plain, 'scope.txt', 'text/plain', 5, $this->device->user_id))->toThrow(UnableToWriteFile::class);
        @unlink($plain);

        expect(ClientFile::query()->withoutGlobalScopes()->count())->toBe(0)
            ->and(StorageDeletion::query()->sole()->path)->toStartWith("client-files/{$project->id}/");
    });

    it('reads a file\'s text on a later try when the bucket has trouble', function () {
        config(['queue.default' => 'database']);
        $project = Client::factory()->create(['user_id' => $this->device->user_id, 'name' => 'Acme']);
        $plain = app(ChunkedUploadService::class)->tempPath('file');
        file_put_contents($plain, 'Scope of the works');
        $file = app(ClientWorkspace::class)->storeFile($project, $plain, 'scope.txt', 'text/plain', 18, $this->device->user_id);
        @unlink($plain);

        // First the question whether it is there, then the read itself.
        $this->bucket->failNext('HEAD', 503, 3, 'client-files/');
        s3RunOneQueuedJob();
        expect($file->refresh()->status)->toBe('extracting');

        $this->travel(61)->seconds();
        $this->bucket->failNext('GET', 503, 3, 'client-files/');
        s3RunOneQueuedJob();
        expect($file->refresh()->status)->toBe('extracting')->and($this->workFiles())->toBe([]);

        $this->travel(301)->seconds();
        s3RunOneQueuedJob();
        expect($file->refresh()->status)->toBe('ready')->and($file->text)->toBe('Scope of the works')
            ->and(DB::table('failed_jobs')->count())->toBe(0);
    });

    it('says so when the file cannot be read after every try, and its page stops waiting', function () {
        config(['queue.default' => 'database']);
        $project = Client::factory()->create(['user_id' => $this->device->user_id, 'name' => 'Acme']);
        $plain = app(ChunkedUploadService::class)->tempPath('file');
        file_put_contents($plain, 'Scope of the works');
        $file = app(ClientWorkspace::class)->storeFile($project, $plain, 'scope.txt', 'text/plain', 18, $this->device->user_id);
        @unlink($plain);

        $this->bucket->failNext('GET', 503, 9, 'client-files/');
        foreach ([0, 61, 301] as $wait) {
            $this->travel($wait)->seconds();
            s3RunOneQueuedJob();
        }

        expect($file->refresh()->status)->toBe('failed')
            ->and($file->error)->toBe(ClientWorkspace::UNREADABLE)
            ->and(DB::table('failed_jobs')->count())->toBe(1)
            ->and(DB::table('jobs')->where('payload', 'like', '%ExtractClientFileJob%')->count())->toBe(0);
    });

    it('stays in the list when the bucket does not delete it, so it can be deleted again', function () {
        Queue::fake();
        $project = Client::factory()->create(['user_id' => $this->device->user_id, 'name' => 'Acme']);
        $plain = app(ChunkedUploadService::class)->tempPath('file');
        file_put_contents($plain, 'Scope');
        $workspace = app(ClientWorkspace::class);
        $file = $workspace->storeFile($project, $plain, 'scope.txt', 'text/plain', 5, $this->device->user_id);
        @unlink($plain);

        $this->bucket->failNext('DELETE', 503, 3);
        expect(fn () => $workspace->deleteFile($file))->toThrow(UnableToDeleteFile::class);
        expect(ClientFile::query()->withoutGlobalScopes()->find($file->id))->not->toBeNull();
    });
});

describe('the account export', function () {
    it('builds the ZIP from local copies, keeps it encrypted and sends it decrypted', function () {
        Mail::fake();
        $audio = Fixtures::audio(5000, S3_MARKER);
        $this->makeReadySession($this->device, $audio, ['title' => 'Budget', 'status' => SessionStatus::Completed]);
        $user = $this->device->user;
        $export = new AccountExport(['user_id' => $user->id, 'status' => 'pending']);
        $export->forceFill(['user_id' => $user->id, 'status' => 'pending'])->save();

        app(AccountExporter::class)->build($export);

        $export->refresh();
        $key = "exports/{$export->id}.zip.elr";
        expect($export->status)->toBe('ready')->and($export->path)->toBe($key)
            ->and(s3ObjectBody($this, $key))->toStartWith(RecordingCipher::MAGIC)
            ->and($this->bucket->holds(S3_MARKER))->toBeFalse()
            ->and(File::isDirectory(AccountExporter::workDirectory($export->id)))->toBeFalse()
            ->and($this->workFiles())->toBe([]);

        $response = $this->get((string) $export->downloadUrl())->assertOk();
        $file = (string) tempnam(sys_get_temp_dir(), 'eld-export');
        file_put_contents($file, $response->streamedContent());
        $zip = new ZipArchive;
        expect($zip->open($file))->toBeTrue();
        $found = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $found = $found || $zip->getFromIndex($i) === $audio;
        }
        $zip->close();
        @unlink($file);
        expect($found)->toBeTrue()->and($this->workFiles())->toBe([]);
    });
});

describe('a request link', function () {
    beforeEach(function () {
        Queue::fake();
        $this->project = Client::factory()->create(['user_id' => $this->device->user_id, 'name' => 'Acme']);
        $link = $this->postJson('/api/request-links', ['title' => 'Send your notes', 'client_id' => $this->project->id])->assertCreated()->json('data');
        $this->linkPath = (string) parse_url($link['url'], PHP_URL_PATH);
        app('auth')->forgetGuards();
        $this->withHeaders(['Authorization' => '']);
    });

    it('keeps a document\'s parts encrypted and files it in the project', function () {
        $text = str_repeat('Scope line of the works '.S3_MARKER."\n", 20_000);
        $upload = s3StartRequestUpload($this, $text, 'scope.txt');
        foreach ($upload['missing'] as $index) {
            s3SendRequestChunk($this, $upload, $index, $text)->assertOk();
        }

        expect($this->bucket->keys("request-parts/{$upload['id']}/"))->toHaveCount(count($upload['missing']))
            ->and(count($upload['missing']))->toBeGreaterThan(1)
            ->and($this->bucket->holds(S3_MARKER))->toBeFalse()
            ->and($this->workFiles())->toBe([]);

        $this->postJson($this->linkPath."/files/{$upload['id']}/complete")->assertOk();

        $file = ClientFile::query()->withoutGlobalScopes()->where('client_id', $this->project->id)->sole();
        expect($this->bucket->keys('request-parts/'))->toBe([])
            ->and($this->bucket->keys())->toBe([$file->path])
            ->and($this->bucket->holds(S3_MARKER))->toBeFalse();
        s3ExpectNoLocalCopies($this);
    });

    describe('when the bucket fails during complete', function () {
        beforeEach(function () {
            $this->text = str_repeat("Scope line of the works\n", 40_000);
            $this->upload = s3StartRequestUpload($this, $this->text, 'scope.txt');
            foreach ($this->upload['missing'] as $index) {
                s3SendRequestChunk($this, $this->upload, $index, $this->text)->assertOk();
            }
            $this->parts = $this->bucket->keys("request-parts/{$this->upload['id']}/");
            $this->complete = "{$this->linkPath}/files/{$this->upload['id']}/complete";
        });

        it('keeps the parts when a part cannot be read', function () {
            $this->bucket->failNext('GET', 503, 3, $this->parts[0]);

            expect($this->postJson($this->complete)->status())->toBeGreaterThanOrEqual(500);

            expect($this->bucket->keys("request-parts/{$this->upload['id']}/"))->toBe($this->parts)
                ->and(ClientFile::query()->withoutGlobalScopes()->count())->toBe(0)
                ->and(RequestUpload::query()->findOrFail($this->upload['id'])->status)->toBe('uploading')
                ->and($this->workFiles())->toBe([]);

            s3ExpectCompleteFinishes($this);
        });

        it('keeps the parts when the finished file cannot be stored, with no row left behind', function () {
            $this->bucket->failNext('PUT', 503, 3, 'client-files/');

            expect($this->postJson($this->complete)->status())->toBeGreaterThanOrEqual(500);

            expect($this->bucket->keys("request-parts/{$this->upload['id']}/"))->toBe($this->parts)
                ->and(ClientFile::query()->withoutGlobalScopes()->count())->toBe(0)
                ->and($this->bucket->keys('client-files/'))->toBe([])
                ->and($this->workFiles())->toBe([]);

            s3ExpectCompleteFinishes($this);
        });

        it('keeps the parts when the bucket cannot even list them', function () {
            $this->bucket->failNext('GET', 503, 3);

            expect($this->postJson($this->complete)->status())->toBeGreaterThanOrEqual(500);

            expect($this->bucket->keys("request-parts/{$this->upload['id']}/"))->toBe($this->parts)
                ->and(RequestUpload::query()->findOrFail($this->upload['id'])->received_chunks)->toBe($this->upload['missing']);

            s3ExpectCompleteFinishes($this);
        });
    });

    it('asks again for the parts of a document that are not in the bucket, as after moving to one', function () {
        $text = str_repeat("Scope line of the works\n", 40_000);
        $upload = s3StartRequestUpload($this, $text, 'scope.txt');
        foreach ($upload['missing'] as $index) {
            s3SendRequestChunk($this, $upload, $index, $text)->assertOk();
        }
        expect(count($upload['missing']))->toBeGreaterThan(2);
        unset($this->bucket->objects["request-parts/{$upload['id']}/0.part"], $this->bucket->objects["request-parts/{$upload['id']}/2.part"]);

        $this->postJson($this->linkPath."/files/{$upload['id']}/complete")->assertStatus(422)
            ->assertJsonPath('code', 'upload_incomplete')->assertJsonPath('missing_chunks', [0, 2]);

        s3SendRequestChunk($this, $upload, 0, $text)->assertOk();
        s3SendRequestChunk($this, $upload, 2, $text)->assertOk();
        $this->postJson($this->linkPath."/files/{$upload['id']}/complete")->assertOk()->assertJsonPath('status', 'ready');

        expect(s3PlainOfFile(ClientFile::query()->withoutGlobalScopes()->sole()))->toBe($text);
    });

    it('adds a document once when the request stored it and died before it said so', function () {
        $text = str_repeat("Scope line of the works\n", 40_000);
        $upload = s3StartRequestUpload($this, $text, 'scope.txt');
        foreach ($upload['missing'] as $index) {
            s3SendRequestChunk($this, $upload, $index, $text)->assertOk();
        }
        $this->postJson($this->linkPath."/files/{$upload['id']}/complete")->assertOk();
        RequestUpload::query()->whereKey($upload['id'])->update(['status' => 'uploading']);

        $this->postJson($this->linkPath."/files/{$upload['id']}/complete")->assertOk()->assertJsonPath('status', 'ready');

        expect(ClientFile::query()->withoutGlobalScopes()->count())->toBe(1);
    });

    it('refuses a recording that is no sound by its first chunk, through the encryption', function () {
        $fake = str_repeat('Just some words, not sound. ', 20_000);
        $upload = s3StartRequestUpload($this, $fake, 'voice.wav');

        expect(s3SendRequestChunk($this, $upload, 0, $fake)->status())->toBe(422)
            ->and($this->bucket->keys('parts/'))->toBe([])
            ->and($this->workFiles())->toBe([]);
    });
});

describe('the sweep', function () {
    it('removes parts of gone recordings once they are old, by the bucket\'s dates', function () {
        $this->bucket->objects['parts/01GONE/0.part'] = ['body' => 'x', 'modified' => now()->subHours(25)->getTimestamp()];
        $this->bucket->objects['parts/01GONE/1.part'] = ['body' => 'x', 'modified' => now()->subHours(26)->getTimestamp()];
        $this->bucket->objects['parts/01FRESH/0.part'] = ['body' => 'x', 'modified' => now()->subHours(25)->getTimestamp()];
        $this->bucket->objects['parts/01FRESH/1.part'] = ['body' => 'x', 'modified' => now()->subHour()->getTimestamp()];
        $this->bucket->objects['request-parts/01OLD/0.part'] = ['body' => 'x', 'modified' => now()->subHours(2)->getTimestamp()];
        $this->bucket->objects['request-parts/01NEW/0.part'] = ['body' => 'x', 'modified' => now()->subMinutes(5)->getTimestamp()];

        app(TempFileSweeper::class)->sweep();

        expect($this->bucket->keys())->toBe(['parts/01FRESH/0.part', 'parts/01FRESH/1.part', 'request-parts/01NEW/0.part']);
    });

    it('keeps the parts of a recording still uploading', function () {
        $audio = Fixtures::audio(600_000);
        $created = $this->createSession($audio)->assertSuccessful();
        $id = (string) $created->json('recordings.0.id');
        $this->uploadAll($id, $audio, (int) $created->json('recordings.0.upload.chunk_size_bytes'), [0]);

        $this->travel(3)->days();
        app(TempFileSweeper::class)->sweep();

        expect($this->bucket->keys("parts/{$id}/"))->toHaveCount(1);
    });

    it('goes on past a folder the bucket will not delete, and tries it again on the next run', function () {
        $old = now()->subHours(25)->getTimestamp();
        $this->bucket->objects['parts/01GONE/0.part'] = ['body' => 'x', 'modified' => $old];
        $this->bucket->objects['parts/01ALSO/0.part'] = ['body' => 'x', 'modified' => $old];
        $this->bucket->objects['request-parts/01OLD/0.part'] = ['body' => 'x', 'modified' => $old];
        $this->bucket->objects['request-parts/01TOO/0.part'] = ['body' => 'x', 'modified' => $old];
        $this->bucket->objects['files/due.elr'] = ['body' => 'x', 'modified' => $old];
        StorageDeletion::query()->create(['path' => 'files/due.elr', 'next_attempt_at' => now()->subMinute()]);

        $this->bucket->failNext('DELETE', 403, 1, 'parts/01GONE/');
        $this->bucket->failNext('DELETE', 403, 1, 'request-parts/01OLD/');
        expect(app(TempFileSweeper::class)->sweep())->toBe(3);

        expect($this->bucket->keys())->toBe(['parts/01GONE/0.part', 'request-parts/01OLD/0.part'])
            ->and(StorageDeletion::query()->count())->toBe(0);

        expect(app(TempFileSweeper::class)->sweep())->toBe(2);
        expect($this->bucket->keys())->toBe([]);
    });

    it('still retries what is due when the bucket cannot list the folders', function () {
        $this->bucket->objects['files/due.elr'] = ['body' => 'x', 'modified' => now()->getTimestamp()];
        StorageDeletion::query()->create(['path' => 'files/due.elr', 'next_attempt_at' => now()->subMinute()]);
        $this->bucket->dropNext('GET', 6);

        expect(app(TempFileSweeper::class)->sweep())->toBe(1);

        expect($this->bucket->keys())->toBe([])->and(StorageDeletion::query()->count())->toBe(0);
    });

    it('removes copies a killed process left in the temp directory, also encrypted ones', function () {
        $copy = RecordingFiles::tempPath('fetch');
        file_put_contents($copy, 'x');
        touch($copy, now()->subHours(2)->getTimestamp());

        app(TempFileSweeper::class)->sweep();

        expect(file_exists($copy))->toBeFalse();
    });
});

describe('erasing', function () {
    beforeEach(function () {
        $this->audio = Fixtures::audio(3000);
        $this->session = $this->makeReadySession($this->device, $this->audio, ['status' => SessionStatus::Completed]);
        $this->screen = keepScreen($this->session, screenPicture('png', 1280, 720));
        $this->recording = $this->session->recordings()->sole();
    });

    it('keeps the files through the undo bin, then the bin\'s end erases them', function () {
        $this->session->delete();
        expect($this->bucket->keys())->toHaveCount(3);

        $this->travel(CaptureSession::BIN_HOURS + 1)->hours();
        app(RetentionPolicy::class)->emptyBin();

        expect($this->bucket->keys())->toBe([]);
    });

    it('takes a recording out of the bin with its files', function () {
        $this->session->delete();
        app(RetentionPolicy::class)->restore($this->session->refresh());

        expect(s3PlainOf($this->recording->refresh()))->toBe($this->audio);
    });

    it('remembers what the bucket would not delete and retries until it goes', function () {
        $keys = $this->bucket->keys();
        $this->session->delete();
        $this->travel(CaptureSession::BIN_HOURS + 1)->hours();

        // 3 tries per file: the bucket is down for the recording's file and both pictures.
        $this->bucket->failNext('DELETE', 503, 9);
        app(RetentionPolicy::class)->emptyBin();

        expect($this->session->refresh()->media_erased_at)->not->toBeNull()
            ->and(Recording::query()->whereKey($this->recording->id)->exists())->toBeFalse()
            ->and($this->bucket->keys())->toBe($keys)
            ->and(StorageDeletion::query()->pluck('path')->sort()->values()->all())->toBe($keys);
        foreach (StorageDeletion::query()->get() as $deletion) {
            expect($deletion->last_error)->toContain('503')->not->toContain(FakeS3Server::KEY);
        }

        // Not due yet: nothing is asked.
        $this->bucket->log = [];
        expect(app(RecordingFiles::class)->retryErasures())->toBe(0)
            ->and($this->bucket->requests('DELETE '))->toBe(0);

        // Still down at the next try: the wait doubles.
        $this->travel(RecordingFiles::FIRST_RETRY_MINUTES + 1)->minutes();
        $this->bucket->failNext('DELETE', 503, 9);
        expect(app(RecordingFiles::class)->retryErasures())->toBe(0);
        $next = StorageDeletion::query()->firstOrFail();
        expect($next->attempts)->toBe(1)
            ->and((int) round(now()->diffInMinutes($next->next_attempt_at)))->toBe(RecordingFiles::FIRST_RETRY_MINUTES * 2);

        // Back: stt:reconcile's sweep takes the rest.
        $this->travel(RecordingFiles::FIRST_RETRY_MINUTES * 2 + 1)->minutes();
        app(TempFileSweeper::class)->sweep();

        expect($this->bucket->keys())->toBe([])->and(StorageDeletion::query()->count())->toBe(0);
    });

    it('waits at most six hours between tries', function () {
        StorageDeletion::query()->create(['path' => 'files/a.elr', 'attempts' => 40, 'next_attempt_at' => now()->subMinute()]);
        $this->bucket->failNext('DELETE', 503, 3);

        app(RecordingFiles::class)->retryErasures();

        expect((int) round(now()->diffInMinutes(StorageDeletion::query()->sole()->next_attempt_at)))->toBe(RecordingFiles::MAX_RETRY_MINUTES);
    });

    it('remembers a file once when it fails twice', function () {
        $this->bucket->failNext('DELETE', 503, 6);
        app(RecordingFiles::class)->erase('files/a.elr');
        app(RecordingFiles::class)->erase('files/a.elr');

        expect(StorageDeletion::query()->where('path', 'files/a.elr')->count())->toBe(1);
    });

    it('erases an account with every file it had', function () {
        Queue::fake();
        Mail::fake();
        $user = User::query()->findOrFail($this->device->user_id);
        $project = Client::factory()->create(['user_id' => $user->id, 'name' => 'Acme']);
        $plain = app(ChunkedUploadService::class)->tempPath('file');
        file_put_contents($plain, 'Scope');
        app(ClientWorkspace::class)->storeFile($project, $plain, 'scope.txt', 'text/plain', 5, $user->id);
        @unlink($plain);
        $this->bucket->objects['exports/old.zip.elr'] = ['body' => 'x', 'modified' => now()->getTimestamp()];
        AccountExport::query()->create(['user_id' => $user->id, 'status' => 'ready', 'path' => 'exports/old.zip.elr', 'expires_at' => now()->addHour()]);
        expect($this->bucket->keys())->toHaveCount(5);

        app(AccountDeletion::class)->schedule($user->fresh());
        $this->travel(AccountDeletion::GRACE_DAYS)->days();
        $this->travel(1)->minutes();
        expect(Artisan::call('echoledger:erase-accounts'))->toBe(0);

        expect($this->bucket->keys())->toBe([])
            ->and(unscoped(fn () => User::query()->find($user->id)))->toBeNull()
            ->and(StorageDeletion::query()->count())->toBe(0);
    });

    it('erases a deleted project\'s files, and remembers one the bucket would not delete', function () {
        Queue::fake();
        $user = User::query()->findOrFail($this->device->user_id);
        $add = function (Client $project) use ($user): ClientFile {
            $plain = app(ChunkedUploadService::class)->tempPath('file');
            file_put_contents($plain, 'Scope');
            $file = app(ClientWorkspace::class)->storeFile($project, $plain, 'scope.txt', 'text/plain', 5, $user->id);
            @unlink($plain);

            return $file;
        };
        $gone = Client::factory()->create(['user_id' => $user->id, 'name' => 'Gone']);
        $add($gone);
        app(ProjectTree::class)->delete($gone);
        expect($this->bucket->keys('client-files/'))->toBe([]);

        $stuck = Client::factory()->create(['user_id' => $user->id, 'name' => 'Stuck']);
        $file = $add($stuck);
        $this->bucket->failNext('DELETE', 503, 3, 'client-files/');
        app(ProjectTree::class)->delete($stuck);

        expect(Client::query()->find($stuck->id))->toBeNull()
            ->and($this->bucket->keys('client-files/'))->toBe([$file->path])
            ->and(StorageDeletion::query()->sole()->path)->toBe($file->path);
    });

    it('never blocks when even the list of folders fails', function () {
        $this->session->delete();
        $this->travel(CaptureSession::BIN_HOURS + 1)->hours();
        $this->bucket->failNext('GET', 503, 3);

        app(RetentionPolicy::class)->emptyBin();

        expect($this->session->refresh()->media_erased_at)->not->toBeNull()
            ->and($this->bucket->objects)->toBe([]);
    });
});

describe('saving what a teammate sent', function () {
    beforeEach(function () {
        Mail::fake();
        Queue::fake();
        $this->audio = Fixtures::audio(3000, S3_MARKER);
        $this->original = $this->makeReadySession($this->device, $this->audio, ['status' => SessionStatus::Completed]);
        $this->postJson("/api/sessions/{$this->original->id}/send", ['to' => [($this->friend = Fx::user())->email]])->assertCreated();
        app('auth')->forgetGuards();
        [, $token] = $this->enroll($this->friend);
        $this->asToken($token);
        $this->sent = $this->getJson('/api/shared-with-me')->assertOk()->json('data.0.id');
    });

    it('makes a copy of its own in the bucket, encrypted again and independent', function () {
        $before = $this->bucket->keys();
        $copy = $this->postJson("/api/shared-with-me/{$this->sent}/save")->assertCreated()->json('session_id');

        expect($this->bucket->keys())->toHaveCount(count($before) + 1)
            ->and($this->bucket->holds(S3_MARKER))->toBeFalse();
        $recording = unscoped(fn () => Recording::query()->where('capture_session_id', $copy)->sole());
        expect(s3PlainOf($recording))->toBe($this->audio)->and($this->workFiles())->toBe([]);
    });

    it('leaves nothing behind when the bucket fails halfway', function () {
        $before = $this->bucket->keys();
        $this->bucket->failNext('PUT', 503, 3, 'files/');

        expect($this->postJson("/api/shared-with-me/{$this->sent}/save")->status())->toBeGreaterThanOrEqual(500);

        expect($this->bucket->keys())->toBe($before)->and($this->workFiles())->toBe([]);
    });
});

describe('recovering an interrupted assembly', function () {
    beforeEach(function () {
        $this->audio = Fixtures::audio(4000);
        $session = CaptureSession::factory()->create(['user_id' => $this->device->user_id, 'device_id' => $this->device->id, 'status' => SessionStatus::Uploading]);
        $uploads = app(ChunkedUploadService::class);
        $this->recording = $uploads->createRecording($session, Fixtures::artifact($this->audio), 1024 * 1024);
        $this->recording->update(['upload_status' => UploadStatus::Assembling]);
        $this->key = ChunkedUploadService::storedPathFor($this->recording);
        $plain = $uploads->tempPath('source');
        file_put_contents($plain, $this->audio);
        app(RecordingFiles::class)->encryptInto($plain, $this->key, $this->recording->id);
        @unlink($plain);
    });

    it('adopts a whole file', function () {
        expect(app(ChunkedUploadService::class)->adoptStoredFile($this->recording))->toBeTrue()
            ->and($this->recording->refresh()->upload_status)->toBe(UploadStatus::Ready)
            ->and($this->recording->stored_size_bytes)->toBe(strlen(s3ObjectBody($this, $this->key)))
            ->and($this->workFiles())->toBe([]);
    });

    it('deletes a file that really is damaged', function () {
        $this->bucket->objects[$this->key]['body'] = substr(s3ObjectBody($this, $this->key), 0, 100);

        expect(app(ChunkedUploadService::class)->adoptStoredFile($this->recording))->toBeFalse()
            ->and($this->bucket->objects)->not->toHaveKey($this->key);
    });

    it('answers null when there is no file', function () {
        unset($this->bucket->objects[$this->key]);

        expect(app(ChunkedUploadService::class)->adoptStoredFile($this->recording))->toBeNull();
    });

    describe('with the command', function () {
        beforeEach(function () {
            Queue::fake();
            $this->travel(16)->minutes();
        });

        it('assembles again when every part is there', function () {
            $this->recording->update(['received_chunks' => [0]]);
            app(RecordingFiles::class)->putPart($this->recording->partsDirectory().'/0.part', $this->audio);
            unset($this->bucket->objects[$this->key]);
            $this->travel(16)->minutes();

            expect(Artisan::call('recordings:recover'))->toBe(0);

            Queue::assertPushed(AssembleRecordingJob::class, fn (AssembleRecordingJob $job): bool => $job->recordingId === $this->recording->id);
            expect($this->recording->refresh()->upload_status)->toBe(UploadStatus::Assembling);
        });

        it('adopts the whole file when the parts are gone', function () {
            expect(Artisan::call('recordings:recover'))->toBe(0);
            expect(Artisan::output())->toContain('finished 1');

            expect($this->recording->refresh()->upload_status)->toBe(UploadStatus::Ready)
                ->and(s3PlainOf($this->recording))->toBe($this->audio)
                ->and($this->workFiles())->toBe([]);
        });

        it('asks for the file again when nothing is left', function () {
            unset($this->bucket->objects[$this->key]);

            expect(Artisan::call('recordings:recover'))->toBe(0);
            expect(Artisan::output())->toContain('failed 1');

            expect($this->recording->refresh()->upload_status)->toBe(UploadStatus::Failed);
        });

        it('changes nothing while the bucket does not answer, and goes on when it does', function () {
            $this->bucket->dropNext('GET', 3);
            expect(Artisan::call('recordings:recover'))->toBe(0);
            expect($this->recording->refresh()->upload_status)->toBe(UploadStatus::Assembling)
                ->and($this->bucket->objects)->toHaveKey($this->key);

            $this->bucket->failNext('GET', 503, 3, $this->key);
            expect(Artisan::call('recordings:recover'))->toBe(0);
            expect($this->recording->refresh()->upload_status)->toBe(UploadStatus::Assembling)
                ->and($this->bucket->objects)->toHaveKey($this->key)
                ->and($this->workFiles())->toBe([]);

            expect(Artisan::call('recordings:recover'))->toBe(0);
            expect($this->recording->refresh()->upload_status)->toBe(UploadStatus::Ready);
        });
    });

    it('never takes a network failure for a damaged file: the only copy stays', function () {
        $this->bucket->failNext('GET', 503, 3, $this->key);
        expect(fn () => app(ChunkedUploadService::class)->adoptStoredFile($this->recording))->toThrow(UnableToReadFile::class);
        expect($this->bucket->objects)->toHaveKey($this->key);

        $this->bucket->cutNextDownloads(100, 3);
        expect(fn () => app(ChunkedUploadService::class)->adoptStoredFile($this->recording))->toThrow(RuntimeException::class, 'cut short');
        expect($this->bucket->objects)->toHaveKey($this->key)
            ->and($this->workFiles())->toBe([]);
    });
});

it('gives the device the documented text when a final transcript fails on the bucket, never the address or the file', function () {
    $session = $this->makeReadySession($this->device, Fixtures::audio(3000), ['status' => SessionStatus::Processing]);
    $transcript = $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Pending, 'language' => 'en']);
    $recording = $session->recordings()->sole();

    $this->bucket->dropNext('HEAD', 3);
    try {
        app(ChunkedUploadService::class)->decryptToTemp($recording);
        $this->fail('It should not read.');
    } catch (FilesystemException $e) {
        (new TranscribeFinalJob($transcript->id))->failed($e);
    }

    $answer = $this->getJson("/api/transcripts/{$transcript->id}")->assertOk()
        ->assertJsonPath('status', 'failed')
        ->assertJsonPath('error', TranscriptErrorCode::Interrupted->message())
        ->assertJsonPath('error_code', 'interrupted');
    expect($answer->getContent())->not->toContain(FakeS3Server::HOST)->not->toContain(FakeS3Server::BUCKET)->not->toContain((string) $recording->stored_path);

    // What is no storage trouble keeps its own text, as before.
    $other = $session->transcripts()->create(['kind' => TranscriptKind::Alternate, 'provider' => 'groq', 'status' => TranscriptStatus::Pending, 'language' => 'en']);
    (new TranscribeFinalJob($other->id))->failed(new RuntimeException('Something else'));
    expect($other->refresh()->error)->toBe('Something else');
});

it('reports no disk numbers for a bucket, as the API allows', function () {
    $this->getJson('/api/storage')->assertOk()->assertJsonPath('disk_free_bytes', null)->assertJsonPath('disk_total_bytes', null);
});

it('makes no folder objects', function () {
    $this->makeReadySession($this->device, Fixtures::audio(3000));

    expect(array_filter($this->bucket->keys(), fn (string $key): bool => str_ends_with($key, '/')))->toBe([]);
});
