<?php

declare(strict_types=1);

namespace App\Services\Health;

use App\Exceptions\MissingPhpExtension;
use App\Services\RecordingCipher;
use App\Services\Storage\RecordingFiles;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Whether this PHP can run the server (W-INC-1124): every extension the code and its packages need, and a round trip
 * through the recordings cipher on a small random file. The CLI PHP checks itself every five minutes
 * (`echoledger:health --report`); the web PHP checks itself on the admin dashboard and at /health/php for the deploy.
 */
final class ServerHealth
{
    /**
     * composer.lock's ext-* and what app/ calls (web/DEPLOY.md, Requirements), less what PHP 8.4 cannot be built
     * without. The database's PDO driver is added from the config.
     */
    public const REQUIRED = ['ctype', 'curl', 'dom', 'fileinfo', 'filter', 'gd', 'intl', 'libxml', 'mbstring', 'openssl', 'session', 'sodium', 'tokenizer', 'xmlreader', 'zip'];

    /** The scheduled check's last failure, for the admin dashboard: kept until a check passes. */
    public const SCHEDULED = 'server-health:scheduled';

    /** Set for an hour by each email to the super admins, so a fault that lasts mails once an hour. */
    public const MAILED = 'server-health:mailed';

    public function __construct(private readonly PhpExtensions $extensions) {}

    /** @return list<string> */
    public function required(): array
    {
        $driver = config('database.connections.'.config('database.default').'.driver');
        $pdo = match ($driver) {
            'mysql', 'mariadb' => 'pdo_mysql',
            'sqlite' => 'pdo_sqlite',
            'pgsql' => 'pdo_pgsql',
            default => null,
        };

        return $pdo === null ? self::REQUIRED : [...self::REQUIRED, $pdo];
    }

    /**
     * Never throws: a check that cannot run (a full or unwritable disk, a broken config) is itself a failed check, so the
     * admin dashboard, /health/php, `about` and the scheduled run always answer.
     *
     * @return array{missing: list<string>, cipher: string|null} cipher: null when the round trip works, else why not
     */
    public function check(): array
    {
        try {
            $missing = array_values(array_filter($this->required(), fn (string $extension): bool => ! $this->extensions->loaded($extension)));
            if ($this->extensions->loaded('gd') && ! function_exists('imagewebp')) {
                $missing[] = 'gd with WebP';
            }

            return ['missing' => $missing, 'cipher' => $this->cipherFault()];
        } catch (Throwable $e) {
            return ['missing' => [], 'cipher' => 'the health check itself failed: '.Str::limit($e->getMessage(), 300)];
        }
    }

    /** @param array{missing: list<string>, cipher: string|null} $result */
    public static function healthy(array $result): bool
    {
        return $result['missing'] === [] && $result['cipher'] === null;
    }

    /** @param array{missing: list<string>, cipher: string|null} $result */
    public static function summary(array $result): string
    {
        $parts = [];
        if ($result['missing'] !== []) {
            $parts[] = 'missing PHP extensions: '.implode(', ', $result['missing']);
        }
        if ($result['cipher'] !== null) {
            $parts[] = 'the recordings cipher fails: '.$result['cipher'];
        }

        return $parts === [] ? 'all well' : implode('; ', $parts);
    }

    /** @return array{missing: list<string>, cipher: string|null, php: string, checked_at: string}|null */
    public static function lastScheduledFailure(): ?array
    {
        $kept = Cache::get(self::SCHEDULED);

        return is_array($kept) ? $kept : null;
    }

    /** Encrypts and decrypts a random file: fixed texts only come back, never a recording or its path. */
    private function cipherFault(): ?string
    {
        $plain = $sealed = null;
        try {
            // Inside the try: tempPath makes the temp folder, which fails on a full or unwritable disk.
            $plain = RecordingFiles::tempPath('health');
            $sealed = RecordingFiles::tempPath('health');
            $bytes = random_bytes(4096);
            file_put_contents($plain, $bytes);
            $cipher = app(RecordingCipher::class);
            $cipher->encryptFile($plain, $sealed, 'health-check');

            return $cipher->digest($sealed, 'health-check')['sha256'] === hash('sha256', $bytes) ? null : 'the test file did not come back as it was.';
        } catch (MissingPhpExtension $e) {
            return "the {$e->extension} extension is not loaded";
        } catch (Throwable $e) {
            return Str::limit($e->getMessage(), 300);
        } finally {
            foreach ([$plain, $sealed] as $path) {
                if ($path !== null && is_file($path)) {
                    @unlink($path);
                }
            }
        }
    }
}
