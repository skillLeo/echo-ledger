<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Support;

use App\Enums\RecordingRole;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\CaptureSession;
use App\Models\Recording;
use App\Models\Transcript;
use App\Models\User;
use App\Services\ChunkedUploadService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Shared fixtures for the admin console tests: users, a signed-in panel context and ready-made library rows.
 */
trait AdminTestHelpers
{
    /** @var list<string> */
    private array $isolatedRoots = [];

    public function makeAdmin(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->forceFill(['is_super_admin' => true])->save();

        return $user->fresh();
    }

    public function makeRegularUser(array $attributes = []): User
    {
        // Neither a super admin nor a member of any workspace (B20): no console rights at all.
        return withoutWorkspace(User::factory()->create($attributes));
    }

    /** Signs a fresh super admin in and selects the admin panel for Livewire component tests. */
    public function signInAsAdmin(array $attributes = []): User
    {
        $admin = $this->makeAdmin($attributes);
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');

        return $admin;
    }

    /** @param  array<string, mixed>  $params */
    public function panelComponent(string $class, array $params = []): Testable
    {
        return Livewire::test($class, $params);
    }

    /**
     * Points the `local` and `recordings` disks and the app's temp directory at a private temp folder for this test.
     * Storage::fake() uses one shared folder per disk, so parallel test processes would wipe each other's files.
     */
    public function isolatedStorage(): void
    {
        if ($this->isolatedRoots !== []) {
            return;
        }

        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'echoledger-admin-tests'.DIRECTORY_SEPARATOR.bin2hex(random_bytes(8));
        foreach (['local', 'recordings'] as $disk) {
            File::ensureDirectoryExists($base.DIRECTORY_SEPARATOR.$disk);
            config(["filesystems.disks.{$disk}.root" => $base.DIRECTORY_SEPARATOR.$disk]);
            Storage::forgetDisk($disk);
        }
        File::ensureDirectoryExists($base.DIRECTORY_SEPARATOR.'tmp');
        config(['echoledger.tmp_dir' => $base.DIRECTORY_SEPARATOR.'tmp']);

        $this->isolatedRoots[] = $base;
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($base));
    }

    /**
     * @param  list<array<string, mixed>>  $segments
     */
    public function sessionWithTranscript(User $owner, array $segments, array $session = [], string $kind = 'final', array $transcript = []): CaptureSession
    {
        $capture = CaptureSession::factory()->create(array_merge($session, ['user_id' => $owner->id]));

        Transcript::query()->create(array_merge([
            'capture_session_id' => $capture->id,
            'kind' => TranscriptKind::from($kind),
            'provider' => 'assemblyai',
            'status' => TranscriptStatus::Completed,
            'language' => 'en',
            'text' => implode(' ', array_map(fn (array $s): string => (string) ($s['text'] ?? ''), $segments)),
            'segments' => $segments,
        ], $transcript));

        return $capture->fresh();
    }

    /** Stores a small encrypted recording for the session and returns it, ready for playback. */
    public function readyRecording(CaptureSession $session, string $plain = 'RIFF-test-audio-bytes', string $mime = 'audio/wav'): Recording
    {
        $this->isolatedStorage();

        $uploads = app(ChunkedUploadService::class);
        $recording = $uploads->createRecording($session, [
            'role' => RecordingRole::Primary->value, 'mime' => $mime, 'channels' => 2,
            'size_bytes' => strlen($plain), 'sha256' => hash('sha256', $plain),
        ], 1024 * 1024);

        $source = $uploads->tempPath('admin-test');
        file_put_contents($source, $plain);
        $uploads->storeEncrypted($recording, $source);
        @unlink($source);

        return $recording->fresh();
    }

    /** Action modals are streamed as partials, so their markup is not part of the component's own html(). */
    public function modalHtml(Testable $component): string
    {
        return implode('', array_map('strval', (array) ($component->effects['partials'] ?? [])));
    }
}
