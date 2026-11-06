<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Storage\RecordingFiles;
use App\Services\Storage\S3Adapter;
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
        $this->warnAboutVersions($files);

        return self::SUCCESS;
    }

    /** A warning only: a rule at the provider can make it right, and a key need not be allowed to read this setting. */
    private function warnAboutVersions(RecordingFiles $files): void
    {
        $adapter = $files->disk()->getAdapter();
        $versioning = $adapter instanceof S3Adapter ? $adapter->versioning() : '';
        if ($versioning === '') {
            return;
        }
        $this->warn(($versioning === null ? 'The bucket would not say whether it keeps old versions.' : "The bucket keeps old versions (versioning: {$versioning}).")
            .' Where it does, a delete leaves the file in the bucket: it needs the rule in docs/DEPLOY_VPS.md that removes them.');
    }
}
