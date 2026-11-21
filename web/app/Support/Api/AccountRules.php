<?php

declare(strict_types=1);

namespace App\Support\Api;

use Illuminate\Validation\Rules\Password;

/**
 * The rules of a name and a password from the apps (since 2.0.0-l, docs/API.md "Account and security from the apps"),
 * as stable codes for `error_codes`. The password's come from `Password::defaults()`, the same rule the console's
 * Settings and the reset page use, so the apps and the web never disagree.
 */
final class AccountRules
{
    public const NAME_MAX = 120;

    /** Longer passwords are refused before they are hashed. */
    public const PASSWORD_MAX = 4096;

    /**
     * `password_rules` of GET /api/auth/options: what the apps check before sending (the server still decides).
     *
     * @return array{min: int, letters: bool, numbers: bool}
     */
    public static function passwordRules(): array
    {
        $applied = Password::default()->appliedRules();

        return ['min' => (int) $applied['min'], 'letters' => (bool) $applied['letters'], 'numbers' => (bool) $applied['numbers']];
    }

    /**
     * What is wrong with a new password, as FieldErrors::fail() takes it ([] when nothing).
     *
     * @return list<array{0: string, 1: string, 2?: array<string, int>}>
     */
    public static function passwordProblems(#[\SensitiveParameter] string $password): array
    {
        if ($password === '') {
            return [['required', 'Enter a new password.']];
        }
        $rules = self::passwordRules();
        $problems = [];
        if (mb_strlen($password) < $rules['min']) {
            $problems[] = ['too_short', "Use at least {$rules['min']} characters.", ['min' => $rules['min']]];
        }
        if (strlen($password) > self::PASSWORD_MAX) {
            $problems[] = ['too_long', 'That password is too long.', ['max' => self::PASSWORD_MAX]];
        }
        if ($rules['letters'] && preg_match('/\pL/u', $password) !== 1) {
            $problems[] = ['needs_letters', 'Use at least one letter.'];
        }
        if ($rules['numbers'] && preg_match('/\pN/u', $password) !== 1) {
            $problems[] = ['needs_numbers', 'Use at least one number.'];
        }

        return $problems;
    }

    /**
     * A name trimmed at both ends (spaces, and the joiners and direction marks that only belong inside a name), or the
     * problems with it. Letters, marks and numbers of any script, spaces, . ' - ( ) , and inside a name ZWNJ/ZWJ
     * (Urdu, Persian) and LRM/RLM; every other control or format character, the bidi embeddings, overrides and
     * isolates among them, is refused rather than stripped.
     *
     * @return array{0: string, 1: list<array{0: string, 1: string, 2?: array<string, int>}>}
     */
    public static function name(mixed $value): array
    {
        if (! is_string($value) || preg_match('//u', $value) !== 1) {
            return ['', [is_string($value) ? ['invalid_characters', 'The name has characters that cannot be used.'] : ['required', 'Enter your name.']]];
        }
        $name = (string) preg_replace('/^[\s\x{200C}-\x{200F}]+|[\s\x{200C}-\x{200F}]+$/u', '', $value);
        if ($name === '') {
            return ['', [['required', 'Enter your name.']]];
        }
        $problems = [];
        if (mb_strlen($name) > self::NAME_MAX) {
            $problems[] = ['too_long', 'Use at most '.self::NAME_MAX.' characters.', ['max' => self::NAME_MAX]];
        }
        if (preg_match("/^[\\pL\\pM\\pN\\p{Zs}.'\\-(),\\x{200C}-\\x{200F}]+$/u", $name) !== 1) {
            $problems[] = ['invalid_characters', 'The name has characters that cannot be used.'];
        }

        return [$name, $problems];
    }

    /** "h…@example.com": how summaries and labels name an address, never in full. */
    public static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return '…';
        }

        return mb_substr($email, 0, 1).'…'.substr($email, $at);
    }
}
