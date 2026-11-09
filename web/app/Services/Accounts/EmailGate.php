<?php

declare(strict_types=1);

namespace App\Services\Accounts;

use App\Services\SettingsRepository;
use Illuminate\Support\Str;

/**
 * Which addresses may make an account (since v1.6, P1.5): never a throwaway address of the disposable-email list kept
 * in the repository (resources/data/disposable-email-domains.txt, a subdomain of a listed domain too), and, when the
 * admin set allowed domains (Settings → Sign-up), only addresses of those domains or their subdomains. Accounts that
 * exist already keep signing in whatever their domain.
 */
final class EmailGate
{
    public const LIST_PATH = 'data/disposable-email-domains.txt';

    /** @var array<string, true>|null */
    private static ?array $disposable = null;

    public function __construct(private readonly SettingsRepository $settings) {}

    public function allows(string $email): bool
    {
        $domain = self::domainOf($email);
        if ($domain === '' || self::isDisposable($domain)) {
            return false;
        }
        $allowed = $this->allowedDomains();

        return $allowed === [] || self::matchesAny($domain, $allowed);
    }

    /** @return list<string> the allowed domains, lower case; empty = any domain */
    public function allowedDomains(): array
    {
        $value = $this->settings->get('signup.allowed_domains', []);

        return self::cleanDomains(is_array($value) ? $value : []);
    }

    /**
     * @param  array<mixed>  $domains
     * @return list<string>
     */
    public static function cleanDomains(array $domains): array
    {
        $clean = [];
        foreach ($domains as $domain) {
            $domain = Str::lower(trim((string) $domain));
            $domain = ltrim(Str::after($domain, '@') ?: $domain, '.');
            if ($domain !== '' && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $domain) === 1) {
                $clean[$domain] = $domain;
            }
        }

        return array_values($clean);
    }

    public static function isDisposable(string $domain): bool
    {
        return self::matchesAny(Str::lower($domain), array_keys(self::disposable()));
    }

    public static function domainOf(string $email): string
    {
        $email = Str::lower(trim($email));

        return str_contains($email, '@') ? Str::afterLast($email, '@') : '';
    }

    /** @param  list<string>  $domains */
    private static function matchesAny(string $domain, array $domains): bool
    {
        foreach ($domains as $listed) {
            if ($domain === $listed || str_ends_with($domain, '.'.$listed)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, true> */
    private static function disposable(): array
    {
        if (self::$disposable === null) {
            $lines = @file(resource_path(self::LIST_PATH), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $domains = [];
            foreach ($lines as $line) {
                $line = Str::lower(trim($line));
                if ($line !== '' && ! str_starts_with($line, '#')) {
                    $domains[$line] = true;
                }
            }
            self::$disposable = $domains;
        }

        return self::$disposable;
    }
}
