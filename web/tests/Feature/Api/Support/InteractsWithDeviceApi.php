<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Support;

use App\Enums\SessionStatus;
use App\Models\CaptureSession;
use App\Models\Device;
use App\Models\Recording;
use App\Models\User;
use App\Services\ChunkedUploadService;
use App\Services\CredentialVault;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/**
 * Shared plumbing for the device API integration tests: an isolated recordings disk and temp directory, a hard stop on
 * real outbound HTTP, real bearer tokens (so ability checks run through Sanctum, not a test shortcut) and helpers for
 * the raw-body chunk endpoint.
 */
trait InteractsWithDeviceApi
{
    protected string $tmpDirectory = '';

    protected function setUpInteractsWithDeviceApi(): void
    {
        Http::preventStrayRequests();
        Storage::forgetDisk('recordings');

        // Every test gets its own recordings root. Storage::fake() would share one directory per machine and wipe the
        // files of any other test process running at the same time.
        $this->tmpDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eld-api-'.bin2hex(random_bytes(6));
        mkdir($this->tmpDirectory.DIRECTORY_SEPARATOR.'recordings', 0700, true);
        config([
            'filesystems.disks.recordings.root' => $this->tmpDirectory.DIRECTORY_SEPARATOR.'recordings',
            'echoledger.tmp_dir' => $this->tmpDirectory.DIRECTORY_SEPARATOR.'work',
            'echoledger.device_token_days' => 365,
            'echoledger.max_recording_bytes' => 2 * 1024 * 1024 * 1024,
            'app.debug' => false,
        ]);
    }

    /** Plaintext temp files currently on disk (assembly and decryption files); empty when nothing leaked. */
    public function workFiles(): array
    {
        return glob(config('echoledger.tmp_dir').DIRECTORY_SEPARATOR.'*') ?: [];
    }

    protected function tearDownInteractsWithDeviceApi(): void
    {
        if ($this->tmpDirectory !== '' && is_dir($this->tmpDirectory)) {
            File::deleteDirectory($this->tmpDirectory);
        }
    }

    /**
     * A device of $user (a new user when omitted) with a real Sanctum token.
     *
     * @param  list<string>|null  $abilities  defaults to the standard device ability set
     * @return array{0: Device, 1: string} the device and its plain-text bearer token
     */
    public function enroll(?User $user = null, ?array $abilities = null, string $platform = 'android'): array
    {
        $device = Device::factory()->create([
            'user_id' => ($user ?? User::factory()->create())->id,
            'platform' => $platform,
        ]);

        return [$device, $device->createToken('device', $abilities ?? Device::ABILITIES, now()->addDays(365))->plainTextToken];
    }

    /** Authenticate the following requests with a bearer token, discarding any user cached by an earlier request. */
    public function asToken(string $token): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withToken($token);
    }

    /** POST a raw-body chunk. A null header value in $headers removes that header. */
    public function postChunk(string $recordingId, int|string|null $index, string $body, ?string $sha256 = null, array $headers = []): TestResponse
    {
        $merged = array_merge($this->defaultHeaders, [
            'Content-Type' => 'application/octet-stream',
            'Accept' => 'application/json',
            'Upload-Chunk-Index' => $index === null ? null : (string) $index,
            'Upload-Chunk-Sha256' => $sha256 ?? hash('sha256', $body),
        ], $headers);
        $server = $this->transformHeadersToServerVars(array_filter($merged, static fn ($value): bool => $value !== null));

        return $this->call('POST', "/api/recordings/{$recordingId}/chunks", [], [], [], $server, $body);
    }

    /**
     * Create a session with one primary artifact for $bytes.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function createSession(string $bytes, array $overrides = [], array $artifact = []): TestResponse
    {
        return $this->postJson('/api/sessions', Fixtures::sessionBody($bytes, $overrides, $artifact));
    }

    /**
     * Upload every chunk of $bytes for one recording slot (in the given order) and return the last chunk response.
     *
     * @param  list<int>|null  $order  chunk indexes to send; defaults to ascending
     */
    public function uploadAll(string $recordingId, string $bytes, int $chunkSize, ?array $order = null): TestResponse
    {
        $chunks = Fixtures::chunks($bytes, $chunkSize);
        $response = null;
        foreach ($order ?? array_keys($chunks) as $index) {
            $response = $this->postChunk($recordingId, $index, $chunks[$index]);
        }

        return $response;
    }

    /**
     * Whole happy path over HTTP: create, upload, complete.
     *
     * @param  array<string, mixed>  $overrides
     * @return array{session: string, recording: string, chunk_size: int, create: TestResponse, complete: TestResponse}
     */
    public function uploadRecording(string $bytes, array $overrides = [], array $artifact = []): array
    {
        $create = $this->createSession($bytes, $overrides, $artifact)->assertSuccessful();
        $recordingId = (string) $create->json('recordings.0.id');
        $chunkSize = (int) $create->json('recordings.0.upload.chunk_size_bytes');
        $this->uploadAll($recordingId, $bytes, $chunkSize);
        $complete = $this->postJson("/api/recordings/{$recordingId}/complete");

        return [
            'session' => (string) $create->json('session.id'),
            'recording' => $recordingId,
            'chunk_size' => $chunkSize,
            'create' => $create,
            'complete' => $complete,
        ];
    }

    /** Drop every stub registered so far (the first matching stub wins, so a later one cannot override an earlier one). */
    public function resetHttpFakes(): void
    {
        Http::swap(new HttpFactory);
        Http::preventStrayRequests();
    }

    /**
     * POST a raw body (webhooks sign the exact bytes, so they must not be re-encoded).
     *
     * @param  array<string, string>  $headers
     */
    public function postRaw(string $uri, string $body, array $headers = []): TestResponse
    {
        $server = $this->transformHeadersToServerVars(array_merge(['Accept' => 'application/json', 'Content-Type' => 'application/json'], $headers));

        return $this->call('POST', $uri, [], [], [], $server, $body);
    }

    /**
     * A recording that is already assembled and encrypted on the faked disk, for tests that start at the job layer.
     *
     * @param  array<string, mixed>  $artifact  overrides for the declared artifact
     */
    public function makeReadyRecording(CaptureSession $session, string $bytes, array $artifact = []): Recording
    {
        $uploads = app(ChunkedUploadService::class);
        $recording = $uploads->createRecording($session, Fixtures::artifact($bytes, $artifact), 1024 * 1024);
        $plain = $uploads->tempPath('fixture');
        file_put_contents($plain, $bytes);
        $uploads->storeEncrypted($recording, $plain);
        @unlink($plain);

        return $recording->refresh();
    }

    /** A session of the device's owner with a ready primary recording and no transcript yet. */
    public function makeReadySession(Device $device, string $bytes, array $session = [], array $artifact = []): CaptureSession
    {
        $model = CaptureSession::factory()->create(array_merge([
            'user_id' => $device->user_id, 'device_id' => $device->id, 'status' => SessionStatus::Processing, 'duration_ms' => 60000,
        ], $session));
        $this->makeReadyRecording($model, $bytes, $artifact);

        return $model;
    }

    public function configureAssemblyAi(?string $webhookSecret = 'whsec_test_secret'): void
    {
        $vault = app(CredentialVault::class);
        $vault->put('assemblyai', 'api_key', 'aai_test_key_1234567890');
        if ($webhookSecret !== null) {
            $vault->put('assemblyai', 'webhook_secret', $webhookSecret);
        }
    }

    /**
     * Fake the AssemblyAI REST surface. Requests are appended to $log as "METHOD /path" so tests can assert exactly
     * which provider calls were made.
     *
     * @param  array<string, mixed>|null  $result  body returned by GET /v2/transcript/{id}
     * @param  list<string>  $log
     */
    public function fakeAssemblyAi(string $jobId = 'job_1', ?array $result = null, array &$log = []): void
    {
        $result ??= Fixtures::assemblyAiResult($jobId);

        Http::fake(function (HttpRequest $request) use ($jobId, $result, &$log) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $log[] = $request->method().' '.$path;

            return match (true) {
                $request->method() === 'GET' && $path === '/v3/token' => Http::response(['token' => 'tmp_stream_token'], 200),
                $request->method() === 'POST' && $path === '/v2/upload' => Http::response(['upload_url' => 'https://cdn.assemblyai.com/upload/abc'], 200),
                $request->method() === 'POST' && $path === '/v2/transcript' => Http::response(['id' => $jobId, 'status' => 'queued'], 200),
                $request->method() === 'GET' && $path === "/v2/transcript/{$jobId}" => Http::response($result, 200),
                $request->method() === 'DELETE' && $path === "/v2/transcript/{$jobId}" => Http::response(['id' => $jobId, 'status' => 'deleted'], 200),
                default => Http::response(['error' => 'unexpected '.$request->method().' '.$path], 404),
            };
        });
    }
}
