<?php

declare(strict_types=1);

namespace Tests\Feature\Security\Support;

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Device;
use App\Models\Recording;
use App\Models\Transcript;
use App\Models\User;
use App\Services\ChunkedUploadService;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Fixtures shared by the security feature tests: two-tenant data sets, real bearer tokens and encrypted recordings.
 */
final class SecurityFixtures
{
    /** A well-formed ULID that does not exist in any table. */
    public const MISSING_ID = '01JZZZZZZZZZZZZZZZZZZZZZZZ';

    /** @var list<string> */
    private static array $diskRoots = [];

    /**
     * Points the `recordings` disk at a private directory. Storage::fake() shares one folder between every test
     * process, so two suites running side by side would delete each other's files.
     */
    public static function fakeRecordingsDisk(): void
    {
        self::fakeDisk('recordings');
    }

    public static function fakeDisk(string $name): void
    {
        $root = storage_path('framework/testing/disks/security-'.$name.'-'.getmypid().'-'.bin2hex(random_bytes(4)));
        (new Filesystem)->ensureDirectoryExists($root);
        Storage::set($name, Storage::build(['driver' => 'local', 'root' => $root, 'throw' => true]));
        self::$diskRoots[] = $root;
    }

    public static function cleanupDisks(): void
    {
        foreach (self::$diskRoots as $root) {
            (new Filesystem)->deleteDirectory($root);
        }
        self::$diskRoots = [];
    }

    public static function user(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }

    public static function superAdmin(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->forceFill(['is_super_admin' => true])->save();

        return $user->fresh();
    }

    public static function device(?User $owner = null, array $attributes = []): Device
    {
        return Device::factory()->create(['user_id' => ($owner ?? self::user())->id] + $attributes);
    }

    /** @param  list<string>|null  $abilities */
    public static function token(Device $device, ?array $abilities = null, ?Carbon $expiresAt = null): string
    {
        return $device->createToken('device', $abilities ?? Device::ABILITIES, $expiresAt ?? now()->addDays(30))->plainTextToken;
    }

    public static function client(User $owner, string $name = 'Acme Holdings'): Client
    {
        return Client::query()->create(['user_id' => $owner->id, 'name' => $name, 'color' => '#8B5CF6']);
    }

    public static function session(User $owner, ?Device $device = null, array $attributes = []): CaptureSession
    {
        return CaptureSession::factory()->create([
            'user_id' => $owner->id,
            'device_id' => $device?->id,
        ] + $attributes);
    }

    /** A ready (assembled and encrypted) recording. Callers must have called Storage::fake('recordings'). */
    public static function readyRecording(CaptureSession $session, ?string $plain = null, string $role = 'primary', string $mime = 'audio/ogg'): Recording
    {
        $plain ??= random_bytes(4096);
        $uploads = app(ChunkedUploadService::class);
        $recording = $uploads->createRecording($session, [
            'role' => $role, 'mime' => $mime, 'channels' => 2,
            'size_bytes' => strlen($plain), 'sha256' => hash('sha256', $plain),
        ], 1024 * 1024);

        $source = $uploads->tempPath('sec-fixture');
        file_put_contents($source, $plain);
        $uploads->storeEncrypted($recording, $source);
        @unlink($source);

        return $recording->fresh();
    }

    /** An upload slot with nothing received yet. */
    public static function pendingRecording(CaptureSession $session, string $plain, int $chunkSize = 1024, string $role = 'primary'): Recording
    {
        return app(ChunkedUploadService::class)->createRecording($session, [
            'role' => $role, 'mime' => 'audio/ogg', 'channels' => 2,
            'size_bytes' => strlen($plain), 'sha256' => hash('sha256', $plain),
        ], $chunkSize);
    }

    public static function transcript(CaptureSession $session, string $text = 'Confidential sentence.', TranscriptKind $kind = TranscriptKind::Final): Transcript
    {
        return Transcript::query()->create([
            'capture_session_id' => $session->id,
            'kind' => $kind,
            'provider' => 'assemblyai',
            'status' => TranscriptStatus::Completed,
            'language' => 'en',
            'text' => $text,
            'segments' => [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 900, 'text' => $text, 'words' => []]],
            'completed_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    public static function artifact(array $overrides = []): array
    {
        return array_replace([
            'role' => 'primary', 'mime' => 'audio/ogg', 'codec' => 'opus', 'channels' => 2, 'sample_rate' => 48000,
            'size_bytes' => 2048, 'sha256' => str_repeat('a', 64), 'duration_ms' => 1000,
        ], $overrides);
    }

    /** @param  array<string, mixed>  $overrides */
    public static function sessionPayload(array $overrides = []): array
    {
        return array_replace([
            'client_ref' => (string) Str::uuid(),
            'source_app' => 'whatsapp',
            'started_at' => '2026-10-24T09:00:00Z',
            'ended_at' => '2026-10-24T09:10:00Z',
            'duration_ms' => 600000,
            'artifacts' => [self::artifact()],
        ], $overrides);
    }

    /** @return list<array<string, mixed>> */
    public static function segments(string $text = 'Hello there.'): array
    {
        return [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 900, 'text' => $text, 'words' => []]];
    }
}
