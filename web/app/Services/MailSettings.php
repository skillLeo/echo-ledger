<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Mail\MailManager;
use Throwable;

/**
 * Settings → Email (since v1.5): the SMTP server that every email goes through. The settings hold the host, port,
 * encryption, user and sender; the password is in the credential vault (`mail.password`), never in the settings.
 * Without a host the mailer of the server's .env file is used, as before.
 *
 * A mailer reads its configuration once, so the stored settings are applied when the mail manager is made, after a
 * save and before every queued email (a worker runs for days); mailers made with older settings are rebuilt.
 * One instance per application (a singleton), so it remembers the .env configuration to go back to.
 */
final class MailSettings
{
    public const ENCRYPTIONS = [
        'tls' => 'STARTTLS (usually port 587)',
        'ssl' => 'SSL/TLS (usually port 465)',
        'none' => 'None (only inside a private network)',
    ];

    /** The vault entry of the SMTP password. */
    public const VAULT = ['mail', 'password'];

    /** The mailer of "Send a test email": made from the form's values and forgotten right after. */
    public const TEST_MAILER = 'echoledger_test';

    /** @var array{default: mixed, smtp: mixed, from: mixed}|null */
    private ?array $original = null;

    private ?string $applied = null;

    /**
     * The SMTP mailer configuration for these values, or null without a host.
     *
     * @param  array<string, mixed>  $settings  the `mail.*` settings
     * @return array<string, mixed>|null
     */
    public static function mailerConfig(array $settings, ?string $password): ?array
    {
        $host = trim((string) ($settings['mail.host'] ?? ''));
        if ($host === '') {
            return null;
        }
        $encryption = isset(self::ENCRYPTIONS[$settings['mail.encryption'] ?? '']) ? $settings['mail.encryption'] : 'tls';
        $username = trim((string) ($settings['mail.username'] ?? ''));

        return [
            'transport' => 'smtp',
            // smtps = TLS from the first byte; smtp = STARTTLS, required for "tls" and never tried for "none".
            'scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
            'url' => null,
            'host' => $host,
            'port' => (int) ($settings['mail.port'] ?? 0) ?: 587,
            'username' => $username !== '' ? $username : null,
            'password' => $username !== '' ? $password : null,
            'require_tls' => $encryption === 'tls',
            'auto_tls' => $encryption !== 'none',
            'timeout' => 15,
            'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: null,
        ];
    }

    /**
     * The sender: the from address and name of the settings, each falling back to [$fallback] (the .env one).
     *
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $fallback
     * @return array{address: mixed, name: mixed}
     */
    public static function from(array $settings, array $fallback): array
    {
        $address = trim((string) ($settings['mail.from_address'] ?? ''));
        $name = trim((string) ($settings['mail.from_name'] ?? ''));

        return [
            'address' => $address !== '' ? $address : ($fallback['address'] ?? null),
            'name' => $name !== '' ? $name : ($fallback['name'] ?? null),
        ];
    }

    /**
     * The sender of the .env file (the fallback of empty from fields).
     *
     * @return array<string, mixed>
     */
    public function envFrom(): array
    {
        return (array) ($this->original['from'] ?? config('mail.from'));
    }

    /** Puts the stored settings into the mail configuration; mailers already made are rebuilt when they changed. */
    public function apply(?MailManager $manager = null): void
    {
        $this->original ??= ['default' => config('mail.default'), 'smtp' => config('mail.mailers.smtp'), 'from' => config('mail.from')];
        // Read afresh: the scoped repository of a long-running worker can be older than the last save.
        $settings = (new SettingsRepository)->all();
        $smtp = self::mailerConfig($settings, filled($settings['mail.host'] ?? null) ? $this->password() : null);
        $config = $smtp === null ? $this->original : [
            'default' => 'smtp',
            'smtp' => [...(array) $this->original['smtp'], ...$smtp],
            'from' => self::from($settings, (array) $this->original['from']),
        ];
        $fingerprint = hash('sha256', serialize($config));
        if ($fingerprint === $this->applied) {
            return;
        }
        config(['mail.default' => $config['default'], 'mail.mailers.smtp' => $config['smtp'], 'mail.from' => $config['from']]);
        $this->applied = $fingerprint;
        $manager ??= app()->resolved('mail.manager') ? app('mail.manager') : null;
        if ($manager instanceof MailManager) {
            $manager->forgetMailers();
        }
    }

    private function password(): ?string
    {
        try {
            return (new CredentialVault)->get(...self::VAULT);
        } catch (Throwable) {
            // During install the credentials table may not exist yet.
            return null;
        }
    }
}
