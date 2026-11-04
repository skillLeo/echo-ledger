<?php

declare(strict_types=1);

namespace App\Services\Sharing;

use App\Enums\RecordingRole;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\CaptureSession;
use App\Models\Recording;
use App\Models\ShareLink;
use App\Models\Transcript;
use App\Services\ChunkedUploadService;
use App\Services\TranscriptExporter;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * "Download" on a share page whose link allows it (since v1.5): one ZIP with the recording's audio (decrypted for
 * this download only) and its transcript as text, in the link's Show as view when that is ready.
 */
final class ShareDownloads
{
    /** File extensions of the stored audio types. */
    private const EXTENSIONS = [
        'audio/ogg' => 'ogg', 'audio/opus' => 'opus', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a',
        'audio/aac' => 'aac', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/wave' => 'wav', 'audio/flac' => 'flac',
        'audio/x-flac' => 'flac', 'audio/webm' => 'webm', 'audio/amr' => 'amr', 'application/ogg' => 'ogg',
    ];

    public function __construct(private readonly ChunkedUploadService $uploads, private readonly TranscriptExporter $exporter) {}

    /**
     * The ZIP's path and its download name, or null when the recording has neither audio nor a finished transcript.
     * The caller sends the file and deletes it afterwards.
     *
     * @return array{path: string, name: string}|null
     */
    public function zip(ShareLink $link, CaptureSession $session): ?array
    {
        $base = pathinfo(TranscriptExporter::filename($session, 'txt'), PATHINFO_FILENAME);
        $path = $this->uploads->tempPath('share-zip');
        $audio = null;
        $added = 0;
        try {
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create the zip file.');
            }
            $primary = $session->recordings()->where('role', RecordingRole::Primary->value)->first();
            if ($primary instanceof Recording && $primary->isReady()) {
                $audio = $this->uploads->decryptToTemp($primary);
                $entry = $base.'.'.(self::EXTENSIONS[strtolower($primary->mime)] ?? 'audio');
                $zip->addFile($audio, $entry);
                // Stored as it is: packing audio again gains little and would run inside the request on every download.
                $zip->setCompressionName($entry, ZipArchive::CM_STORE);
                $added++;
            }
            $transcript = $session->transcripts()->where('status', TranscriptStatus::Completed->value)->get()
                ->sortBy(fn (Transcript $t): int => $t->kind === TranscriptKind::Final ? 0 : 1)->first();
            if ($transcript instanceof Transcript) {
                $file = $this->exporter->export($transcript->setRelation('captureSession', $session), 'txt', ['view' => $link->view]);
                $zip->addFromString($file['filename'], $file['body']);
                $added++;
            }
            $zip->close();
        } catch (Throwable $e) {
            @unlink($path);
            throw $e;
        } finally {
            // The audio is read into the zip when it closes; the decrypted copy goes right after.
            if ($audio !== null) {
                @unlink($audio);
            }
        }
        if ($added === 0) {
            @unlink($path);

            return null;
        }

        return ['path' => $path, 'name' => $base.'.zip'];
    }
}
