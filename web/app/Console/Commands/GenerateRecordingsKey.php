<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\RecordingCipher;
use Illuminate\Console\Command;

final class GenerateRecordingsKey extends Command
{
    protected $signature = 'echoledger:key {--show : Print the key instead of writing .env} {--force : Overwrite an existing key}';

    protected $description = 'Generate the dedicated recordings encryption key (ECHOLEDGER_RECORDINGS_KEY)';

    public function handle(): int
    {
        $key = RecordingCipher::generateKey();
        if ($this->option('show')) {
            $this->line($key);

            return self::SUCCESS;
        }

        $envPath = base_path('.env');
        $env = is_file($envPath) ? (string) file_get_contents($envPath) : '';
        if (preg_match('/^ECHOLEDGER_RECORDINGS_KEY=(.+)$/m', $env) === 1 && ! $this->option('force')) {
            $this->error('ECHOLEDGER_RECORDINGS_KEY already set. Rotating it makes existing recordings unreadable. Use --force only if you are sure.');

            return self::FAILURE;
        }

        $line = 'ECHOLEDGER_RECORDINGS_KEY='.$key;
        $env = preg_match('/^ECHOLEDGER_RECORDINGS_KEY=.*$/m', $env) === 1
            ? (string) preg_replace('/^ECHOLEDGER_RECORDINGS_KEY=.*$/m', $line, $env)
            : rtrim($env)."\n\n".$line."\n";
        file_put_contents($envPath, $env);

        $this->info('Recordings key written to .env. Back it up offline — without it stored audio cannot be decrypted.');

        return self::SUCCESS;
    }
}
