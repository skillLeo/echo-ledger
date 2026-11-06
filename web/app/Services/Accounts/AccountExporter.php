<?php

declare(strict_types=1);

namespace App\Services\Accounts;

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Mail\ExportReadyMail;
use App\Models\AccountExport;
use App\Models\CallNote;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\ClientBrief;
use App\Models\ClientFile;
use App\Models\ClientMemoryItem;
use App\Models\Recording;
use App\Models\Screen;
use App\Models\Transcript;
use App\Models\User;
use App\Services\AuditLog;
use App\Services\RecordingCipher;
use App\Services\Screens\ScreenStore;
use App\Services\SettingsRepository;
use App\Services\TranscriptExporter;
use App\Services\Views\TranscriptViews;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * "Export my data" (since v1.5, docs/API.md "Your account"): one ZIP with every recording of the account (its
 * decrypted audio, its transcripts as .txt and .json in the original and every view that was made, its screens and
 * call notes) and every project of it (notes, brief, memory and files), with a manifest.json listing it all. The ZIP
 * is built in a working folder of its own, stored encrypted on the recordings disk (exports/) and its link emailed.
 */
final class AccountExporter
{
    /**
     * The working folder is written out into the ZIP whenever this much waits in it (the disk holds it twice). Every
     * part goes in as a file of the working folder, never as a string: ZipArchive keeps an added string in memory
     * until the ZIP is written, and a library of screens and transcripts would not fit.
     */
    private const FLUSH_BYTES = 512 * 1024 * 1024;

    /** File extensions of the stored audio types. */
    private const AUDIO = [
        'audio/ogg' => 'ogg', 'audio/opus' => 'opus', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a',
        'audio/aac' => 'aac', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/wave' => 'wav', 'audio/flac' => 'flac',
        'audio/x-flac' => 'flac', 'audio/webm' => 'webm', 'audio/amr' => 'amr', 'application/ogg' => 'ogg',
    ];

    private const PICTURES = ['image/webp' => 'webp', 'image/png' => 'png', 'image/jpeg' => 'jpg'];

    private ZipArchive $zip;

    private string $zipPath = '';

    private string $work = '';

    /** Numbers the working folder's files of text and pictures. */
    private int $parts = 0;

    /** @var list<string> decrypted files waiting for the next write of the ZIP */
    private array $pending = [];

    private int $pendingBytes = 0;

    /** @var array<string, true> */
    private array $usedNames = [];

    public function __construct(
        private readonly RecordingCipher $cipher,
        private readonly TranscriptExporter $exporter,
        private readonly TranscriptViews $views,
        private readonly ScreenStore $screens,
        private readonly SettingsRepository $settings,
        private readonly AuditLog $audit,
    ) {}

    public static function workDirectory(string $exportId): string
    {
        return storage_path('app/private/export-work/'.$exportId);
    }

    public function build(AccountExport $export): void
    {
        $user = User::query()->findOrFail($export->user_id);
        $work = self::workDirectory($export->id);
        File::ensureDirectoryExists($work, 0700);
        $this->work = $work;
        $this->zipPath = $work.'/export.zip';
        $this->pending = [];
        $this->pendingBytes = 0;
        $this->parts = 0;
        $this->usedNames = [];
        try {
            $this->open(ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $manifest = [
                'format' => 'echoledger-account-export',
                'version' => 1,
                'made_at' => now()->toAtomString(),
                'account' => ['name' => $user->name, 'email' => $user->email, 'created_at' => $user->created_at?->toAtomString()],
                'recordings' => $this->recordings($user, $work),
                'projects' => $this->projects($user, $work),
            ];
            $this->addText('manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $this->close();

            $relative = 'exports/'.$export->id.'.zip.elr';
            $disk = Storage::disk('recordings');
            $disk->makeDirectory('exports');
            $bytes = (int) filesize($this->zipPath);
            $this->cipher->encryptFile($this->zipPath, $disk->path($relative), $export->id);
        } finally {
            File::deleteDirectory($work);
        }

        $export->forceFill(['status' => 'ready', 'path' => $relative, 'bytes' => $bytes, 'error' => null, 'completed_at' => now(), 'expires_at' => now()->addHours(AccountExport::HOURS)])->save();
        $expires = $export->expires_at?->copy()->setTimezone($this->settings->displayTimezone())->format('M j, Y \a\t g:i A T') ?? '';
        Mail::to($user->email)->queue(new ExportReadyMail((string) $export->downloadUrl(), $expires, Number::fileSize($bytes, 1)));
        $this->audit->record('account_exported', $user->id, null, 'Data exported: '.count($manifest['recordings']).' recordings, '.count($manifest['projects']).' projects');
    }

    /** @return list<array<string, mixed>> */
    private function recordings(User $user, string $work): array
    {
        $entries = [];
        CaptureSession::query()->withoutGlobalScopes()->whereNull('deleted_at')->where('user_id', $user->id)->with('client')
            ->chunkById(50, function (Collection $sessions) use (&$entries, $work): void {
                foreach ($sessions as $session) {
                    /** @var CaptureSession $session */
                    $entries[] = $this->recording($session, $work);
                }
            });

        return $entries;
    }

    /** @return array<string, mixed> */
    private function recording(CaptureSession $session, string $work): array
    {
        $folder = $this->uniqueName('recordings/'.$session->started_at->format('Y-m-d').' '.self::safe($session->displayTitle(), 60));
        $files = [];
        foreach ($session->recordings()->get() as $recording) {
            /** @var Recording $recording */
            if (! $recording->isReady() || $recording->stored_path === null || ! Storage::disk('recordings')->exists($recording->stored_path)) {
                continue;
            }
            $name = "{$folder}/audio-{$recording->role->value}.".(self::AUDIO[strtolower($recording->mime)] ?? 'audio');
            $plain = $work.'/'.$recording->id.'.audio';
            $bytes = $this->cipher->decryptToFile(Storage::disk('recordings')->path($recording->stored_path), $plain, $recording->id);
            // Audio is compressed already: stored as it is.
            $this->addPlain($plain, $name, $bytes, store: true);
            $files[] = $name;
        }

        $transcripts = $session->allTranscripts()->where('status', TranscriptStatus::Completed->value)->where('kind', '!=', TranscriptKind::Trial->value)->get();
        foreach ($transcripts as $transcript) {
            /** @var Transcript $transcript */
            $transcript->setRelation('captureSession', $session);
            $base = match ($transcript->kind) {
                TranscriptKind::Final => 'transcript',
                TranscriptKind::Live => 'live-captions',
                default => 'transcript-'.self::safe((string) $transcript->provider, 20).'-'.Str::lower(substr($transcript->id, -6)),
            };
            $views = [null];
            if ($transcript->kind === TranscriptKind::Final) {
                foreach (array_keys(TranscriptViews::VIEWS) as $view) {
                    if ($this->views->textsFor($transcript, $view) !== null) {
                        $views[] = $view;
                    }
                }
            }
            foreach ($views as $view) {
                foreach (['txt', 'json'] as $format) {
                    $name = "{$folder}/{$base}".($view === null ? '' : "-{$view}").".{$format}";
                    $this->addText($name, $this->exporter->export($transcript, $format, ['view' => $view])['body']);
                    $files[] = $name;
                }
            }
        }

        $shots = [];
        foreach (ScreenStore::ordered($session->id)->get() as $index => $screen) {
            /** @var Screen $screen */
            $plain = $this->part();
            $bytes = $this->screens->pictureToFile($screen, $plain);
            if ($bytes === null) {
                continue;
            }
            $name = sprintf('%s/screens/%03d-%s.%s', $folder, $index + 1, str_replace(':', '-', TranscriptExporter::clock($screen->at_ms)), self::PICTURES[$screen->mime] ?? 'img');
            $this->addPlain($plain, $name, $bytes, store: true);
            $shots[] = ['file' => $name, 'at_ms' => $screen->at_ms, 'trigger' => $screen->trigger, 'text' => $screen->ocr_text, 'description' => $screen->description];
            $files[] = $name;
        }
        if ($shots !== []) {
            $this->addText("{$folder}/screens.json", (string) json_encode($shots, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $files[] = "{$folder}/screens.json";
        }

        $notes = CallNote::query()->withoutGlobalScopes()->where('capture_session_id', $session->id)->where('status', 'ready')->first();
        if ($notes instanceof CallNote) {
            $this->addText("{$folder}/notes.json", (string) json_encode($notes->sections, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $files[] = "{$folder}/notes.json";
        }

        return [
            'id' => $session->id,
            'title' => $session->displayTitle(),
            'started_at' => $session->started_at->toAtomString(),
            'duration_ms' => $session->duration_ms,
            'source_app' => $session->source_app->value,
            'language' => $session->language,
            'project' => $session->client?->name,
            'folder' => $folder,
            'files' => $files,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function projects(User $user, string $work): array
    {
        $entries = [];
        foreach (Client::query()->withoutGlobalScopes()->where('user_id', $user->id)->orderBy('name')->get() as $project) {
            /** @var Client $project */
            $folder = $this->uniqueName('projects/'.self::safe($project->name, 60));
            $files = [];
            if (filled($project->notes)) {
                $this->addText("{$folder}/notes.txt", (string) $project->notes);
                $files[] = "{$folder}/notes.txt";
            }
            $brief = ClientBrief::query()->withoutGlobalScopes()->where('client_id', $project->id)->first();
            if ($brief instanceof ClientBrief) {
                $this->addText("{$folder}/brief.json", (string) json_encode($brief->sections, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                $files[] = "{$folder}/brief.json";
            }
            $memory = ClientMemoryItem::query()->withoutGlobalScopes()->ofProjectMaker($project)->orderBy('created_at')->get()
                ->map(fn (ClientMemoryItem $m): array => ['kind' => $m->kind, 'text' => $m->text, 'happened_on' => $m->happened_on?->toDateString(), 'pinned' => $m->pinned])->all();
            if ($memory !== []) {
                $this->addText("{$folder}/memory.json", (string) json_encode($memory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                $files[] = "{$folder}/memory.json";
            }
            foreach (ClientFile::query()->withoutGlobalScopes()->where('client_id', $project->id)->orderBy('created_at')->get() as $file) {
                /** @var ClientFile $file */
                if ($file->path === null) {
                    $name = $this->uniqueName("{$folder}/files/".self::safe($file->name, 80).'.txt', file: true);
                    $this->addText($name, (string) $file->text);
                } elseif (Storage::disk('recordings')->exists($file->path)) {
                    $name = $this->uniqueName("{$folder}/files/".self::safe($file->name, 80), file: true);
                    $plain = $work.'/'.$file->id.'.file';
                    $this->addPlain($plain, $name, $this->cipher->decryptToFile(Storage::disk('recordings')->path($file->path), $plain, $file->id));
                } else {
                    continue;
                }
                $files[] = $name;
            }
            $entries[] = ['id' => $project->id, 'name' => $project->name, 'parent_id' => $project->parent_id, 'folder' => $folder, 'files' => $files];
        }

        return $entries;
    }

    /** Adds text to the ZIP through a file of the working folder. */
    private function addText(string $name, string $text): void
    {
        $plain = $this->part();
        if (file_put_contents($plain, $text) === false) {
            throw new RuntimeException('Could not write the export file.');
        }
        $this->addPlain($plain, $name, strlen($text));
    }

    /** Adds a file of the working folder (decrypted or written there); it is removed once the ZIP is written. */
    private function addPlain(string $plain, string $name, int $bytes, bool $store = false): void
    {
        $this->pending[] = $plain;
        if (! $this->zip->addFile($plain, $name)) {
            throw new RuntimeException('Could not add to the export file.');
        }
        if ($store) {
            $this->zip->setCompressionName($name, ZipArchive::CM_STORE);
        }
        $this->pendingBytes += $bytes;
        if ($this->pendingBytes >= self::FLUSH_BYTES) {
            $this->flush();
        }
    }

    private function part(): string
    {
        return $this->work.'/'.(++$this->parts).'.part';
    }

    /** Writes what waits into the ZIP and removes the decrypted copies, then opens the ZIP again to go on. */
    private function flush(): void
    {
        $this->close();
        $this->open(ZipArchive::CREATE);
    }

    private function open(int $flags): void
    {
        $this->zip = new ZipArchive;
        if ($this->zip->open($this->zipPath, $flags) !== true) {
            throw new RuntimeException('Could not create the export file.');
        }
    }

    private function close(): void
    {
        try {
            if (! $this->zip->close()) {
                throw new RuntimeException('Could not write the export file.');
            }
        } finally {
            foreach ($this->pending as $plain) {
                @unlink($plain);
            }
            $this->pending = [];
            $this->pendingBytes = 0;
        }
    }

    /** A name not used yet in the ZIP: two recordings with the same title and day get " 2", " 3", a file before its extension. */
    private function uniqueName(string $name, bool $file = false): string
    {
        $extension = $file ? pathinfo($name, PATHINFO_EXTENSION) : '';
        $candidate = $name;
        for ($i = 2; isset($this->usedNames[mb_strtolower($candidate)]); $i++) {
            $candidate = $extension === '' ? "{$name} {$i}" : substr($name, 0, -strlen($extension) - 1)." {$i}.{$extension}";
        }
        $this->usedNames[mb_strtolower($candidate)] = true;

        return $candidate;
    }

    /** A file or folder name that every system accepts. */
    private static function safe(string $name, int $max): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', ' ', $name)));
        $name = trim($name, '. ');

        return mb_substr($name === '' ? 'Untitled' : $name, 0, $max);
    }

    /** Marks a failed export and removes what it left. */
    public function failed(AccountExport $export, Throwable $e): void
    {
        File::deleteDirectory(self::workDirectory($export->id));
        report($e);
        $export->forceFill(['status' => 'failed', 'error' => 'The export could not be made. Try again later.'])->save();
    }
}
