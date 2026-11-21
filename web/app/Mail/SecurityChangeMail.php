<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\Brand;

/**
 * A way to sign in changed from an app (since 2.0.0-l): two-step sign-in turned on or off, new recovery codes, a
 * passkey added or removed, the password changed or Google unlinked. The account holder hears of it, so a stolen device cannot change one quietly. Names the change and
 * the device, never a secret or a code.
 */
final class SecurityChangeMail extends AccountMail
{
    public const TWO_FACTOR_ON = 'two_factor_on';

    public const PASSKEY_ADDED = 'passkey_added';

    public const RECOVERY_CODES = 'recovery_codes';

    public const TWO_FACTOR_OFF = 'two_factor_off';

    public const PASSWORD_CHANGED = 'password_changed';

    public const GOOGLE_UNLINKED = 'google_unlinked';

    public const PASSKEY_REMOVED = 'passkey_removed';

    private const SUBJECTS = [
        self::TWO_FACTOR_ON => 'Two-step sign-in is on for your account',
        self::PASSKEY_ADDED => 'A passkey was added to your account',
        self::RECOVERY_CODES => 'New recovery codes were made for your account',
        self::TWO_FACTOR_OFF => 'Two-step sign-in was turned off for your account',
        self::PASSWORD_CHANGED => 'The password of your account was changed',
        self::GOOGLE_UNLINKED => 'Google was unlinked from your account',
        self::PASSKEY_REMOVED => 'A passkey was removed from your account',
    ];

    private const HEADINGS = [
        self::TWO_FACTOR_ON => 'Two-step sign-in is on',
        self::PASSKEY_ADDED => 'A passkey was added',
        self::RECOVERY_CODES => 'New recovery codes were made',
        self::TWO_FACTOR_OFF => 'Two-step sign-in is off',
        self::PASSWORD_CHANGED => 'Your password was changed',
        self::GOOGLE_UNLINKED => 'Google was unlinked',
        self::PASSKEY_REMOVED => 'A passkey was removed',
    ];

    public function __construct(
        public readonly string $change,
        public readonly string $device,
        public readonly string $securityUrl,
        public readonly ?string $passkey = null,
    ) {}

    protected function subjectLine(): string
    {
        return str_replace('your account', 'your '.Brand::name().' account', self::SUBJECTS[$this->change] ?? self::SUBJECTS[self::PASSKEY_ADDED]);
    }

    protected function template(): string
    {
        return 'mail.security-change';
    }

    protected function data(): array
    {
        return [
            'heading' => self::HEADINGS[$this->change] ?? self::HEADINGS[self::PASSKEY_ADDED],
            'change' => $this->change,
            // Names the person or an app chose: plain text, never Markdown or HTML (the template prints them with {!! !!}).
            // Other keys than the public properties, which the view would otherwise get as they are.
            'deviceText' => self::plain($this->device),
            'passkeyText' => $this->passkey !== null ? self::plain($this->passkey) : null,
            'securityUrl' => $this->securityUrl,
        ];
    }

    /**
     * [$value] as plain text inside a Markdown email: control and format characters (bidi overrides and isolates among
     * them) and line breaks go, it is cut to 80 characters, and every character but letters, marks, numbers and spaces
     * becomes a character reference, so HTML, Markdown and links cannot be built from it: a name like
     * "[click](https://evil.example)" shows as it is written and is never a link, an image, emphasis or a heading.
     */
    public static function plain(string $value): string
    {
        $value = (string) preg_replace('/\p{Cf}+/u', '', (string) preg_replace('/[\p{Cc}\p{Zl}\p{Zp}]+/u', ' ', $value));
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        // Letters, marks, numbers and spaces stay; anything else (HTML, Markdown, link and URL characters) is a reference.
        return (string) preg_replace_callback('/[^\p{L}\p{M}\p{N} ]/u', fn (array $m): string => '&#'.mb_ord($m[0]).';', mb_substr($value, 0, 80));
    }
}
