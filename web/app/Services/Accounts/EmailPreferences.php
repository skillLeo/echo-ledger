<?php

declare(strict_types=1);

namespace App\Services\Accounts;

use App\Models\User;

/**
 * Profile → Emails (since v1.5, Phase 1C): which emails a person wants, per category, as the apps' notification
 * categories. Account & security emails (sign-in alerts, password resets, email confirmation, invitations, the data
 * export and account deletion) always come and cannot be turned off. "Transcription" is the earlier "Email me when a
 * transcript is ready" (off by default); the others are on until turned off.
 */
final class EmailPreferences
{
    public const TRANSCRIPTION = 'transcription';

    public const ENHANCEMENT = 'enhancement';

    public const SHARING = 'sharing';

    public const USAGE = 'usage';

    public const UPDATES = 'updates';

    public const OTHER = 'other';

    /** @var array<string, array{label: string, what: string, default: bool}> */
    public const CATEGORIES = [
        self::TRANSCRIPTION => ['label' => 'Transcription', 'what' => 'When a transcript of yours is ready.', 'default' => false],
        self::ENHANCEMENT => ['label' => 'Enhancement', 'what' => 'When the call notes or the Roman Urdu of a recording are ready.', 'default' => true],
        self::SHARING => ['label' => 'Sharing', 'what' => 'When someone sends you a recording or a project.', 'default' => true],
        self::USAGE => ['label' => 'Usage & limits', 'what' => 'When your workspace nears or reaches a limit of its plan.', 'default' => true],
        self::UPDATES => ['label' => 'App updates', 'what' => 'When a new version of the apps is out.', 'default' => true],
        self::OTHER => ['label' => 'Other', 'what' => 'Anything else about your recordings.', 'default' => true],
    ];

    public const ALWAYS = 'Account & security emails always come: sign-in alerts, password resets, confirming your email, invitations, your data export and account deletion.';

    /** Whether [$user] wants the emails of [$category]; an unknown category is treated as Account & security (sent). */
    public static function wants(User $user, string $category): bool
    {
        if (! isset(self::CATEGORIES[$category])) {
            return true;
        }
        if ($category === self::TRANSCRIPTION) {
            return (bool) $user->notify_transcript_ready;
        }
        $chosen = is_array($user->email_preferences) ? ($user->email_preferences[$category] ?? null) : null;

        return is_bool($chosen) ? $chosen : self::CATEGORIES[$category]['default'];
    }

    public static function set(User $user, string $category, bool $on): void
    {
        if (! isset(self::CATEGORIES[$category])) {
            return;
        }
        if ($category === self::TRANSCRIPTION) {
            $user->forceFill(['notify_transcript_ready' => $on])->save();

            return;
        }
        $user->forceFill(['email_preferences' => [...(is_array($user->email_preferences) ? $user->email_preferences : []), $category => $on]])->save();
    }

    /** @return list<array{key: string, label: string, what: string, on: bool}> */
    public static function rows(User $user): array
    {
        $rows = [];
        foreach (self::CATEGORIES as $key => $category) {
            $rows[] = ['key' => $key, 'label' => $category['label'], 'what' => $category['what'], 'on' => self::wants($user, $key)];
        }

        return $rows;
    }
}
