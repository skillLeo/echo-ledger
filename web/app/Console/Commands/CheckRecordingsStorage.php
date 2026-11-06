<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Storage\RecordingFiles;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Whether the recordings disk works, before anything depends on it (docs/DEPLOY_VPS.md, RECORDINGS_DRIVER=s3). */
final class CheckRecordingsStorage extends Command
{
    protected $signature = 'recordings:check-storage';

    protected $description = 'Writes, reads, lists and deletes a small test file on the recordings disk';

    public function handle(RecordingFiles $files): int
    {
        $this->line('Recordings are on '.($files->isLocal() ? "the server's own disk." : 'object storage.'));
        $disk = $files->disk();
        $path = 'storage-check/'.Str::lower((string) Str::ulid()).'.txt';
        $body = bin2hex(random_bytes(32));
        try {
            $disk->put($path, $body);
            if ($disk->get($path) !== $body || (int) $disk->size($path) !== strlen($body) || ! in_array($path, $disk->files('storage-check'), true)) {
                throw new RuntimeException('The test file did not come back as it was written.');
            }
            $disk->delete($path);
            if ($disk->exists($path)) {
                throw new RuntimeException('The test file is still there after its delete.');
            }
        } catch (Throwable $e) {
            $files->erase($path);
            $this->error('The recordings disk does not work: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->info('The recordings disk works.');

        return self::SUCCESS;
    }
}
