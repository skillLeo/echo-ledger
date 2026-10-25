<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** An SPKI pin as the device apps use it: `sha256/` + base64 of a 32-byte SHA-256 digest (44 characters). */
final class CertificatePin implements ValidationRule
{
    public const MESSAGE = 'Each pin must look like sha256/<44 base64 characters>.';

    public static function isValid(mixed $pin): bool
    {
        if (! is_string($pin) || preg_match('#^sha256/[A-Za-z0-9+/]{43}=$#', $pin) !== 1) {
            return false;
        }
        $digest = base64_decode(substr($pin, 7), true);

        return $digest !== false && strlen($digest) === 32;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::isValid($value)) {
            $fail(self::MESSAGE);
        }
    }
}
